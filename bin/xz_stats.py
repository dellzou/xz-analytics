#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
xiaozou123.cn traffic analyzer v3
Nginx access log -> per-post PV/UV + aggregates -> JSON

四层清洗（每层都可单独关闭）：
  1. 机器人 UA 过滤
  2. 脏 IP 剔除：整个日志期内访问过 wp-login/xmlrpc/wp-config/wp-admin 的 IP，
     判定为扫描器/采集器，其文章请求不计
  3. 刷量日剔除：某文某日 PV 超该文日 PV 中位数 8 倍，且满足
     独立IP>=80 / 人均<=1.15 / 无referer>=85% —— 整日不计
  4. 单 IP 单日限流：同一 IP 同日阅读超过 DAILY_CAP 次的部分不计
"""
import json
import re
import sys
from collections import defaultdict, Counter
from datetime import datetime, timedelta

import os

# 路径可用环境变量覆盖，方便作为 WordPress 插件的数据生成脚本使用
LOG = os.environ.get("XZ_ACCESS_LOG", "/www/wwwlogs/wordpress.local.log")
POSTS = os.environ.get("XZ_POSTS_JSON", "/root/xz_posts.json")
PAGES = os.environ.get("XZ_PAGES_JSON", "/root/xz_pages.json")
OUT = os.environ.get("XZ_STATS_OUT", "/root/xz_stats.json")

DAILY_CAP = 10
USE_DIRTY_IP = True
USE_BURST = True

LINE_RE = re.compile(
    r'^(?P<ip>\S+) \S+ \S+ \[(?P<ts>[^\]]+)\] '
    r'"(?P<method>[A-Z]+) (?P<path>[^ ]*)(?: [^"]*)?" '
    r'(?P<status>\d{3}) (?P<bytes>\d+|-) '
    r'"(?P<ref>[^"]*)" "(?P<ua>[^"]*)"'
)

BOT_WORDS = [
    "bot", "spider", "crawl", "slurp", "exporter", "wordpress", "python",
    "curl", "wget", "headless", "phantom", "scrapy", "okhttp", "java/",
    "go-http", "ahrefs", "semrush", "mj12", "dotbot", "dataforseo",
    "petal", "bytespider", "yandex", "baidu", "sogou", "qihoo",
    "monitor", "uptime", "preview", "facebookexternalhit", "telegram",
    "whatsapp", "feed", "rss", "lighthouse", "pingdom", "gtmetrix",
    "zgrab", "masscan", "nmap", "sqlmap", "nikto", "nuclei", "censys",
    "shodan", "expanse", "internetmeasurement", "trendmicro", "nessus",
    "netcraft", "sucuri", "axios", "node-fetch", "guzzle", "libwww",
    "perl", "ruby", "httpx", "aiohttp", "cgnat",
]
BOT_RE = re.compile("|".join(re.escape(w) for w in BOT_WORDS), re.I)
HUMAN_OS = re.compile(r"Windows NT|Macintosh|iPhone|iPad|Android|X11|Linux", re.I)
REAL_BROWSER = re.compile(r"Chrome/|Firefox/|Safari/|Edg/|OPR/|MicroMessenger|UCBrowser|Quark", re.I)

SKIP_PATH = re.compile(
    r"^/(wp-login|wp-admin|wp-json|wp-content|wp-includes|xmlrpc|feed|comments/|"
    r"wp-cron|wp-config|readme\.html|install\.php|setup-config|admin\.php|license|"
    r"\.well-known|robots|sitemap|favicon)", re.I)
STATIC_EXT = re.compile(r"\.(css|js|png|jpe?g|gif|webp|svg|ico|woff2?|ttf|map|xml|txt|json|mp4|webm)(\?|$)", re.I)

DANGER = ("wp-login.php", "xmlrpc.php", "wp-config.php", "wp-admin/")
SAFE_ADMIN = ("admin-ajax.php", "admin-post.php")


def is_human(ua: str) -> bool:
    if not ua or ua == "-":
        return False
    if BOT_RE.search(ua):
        return False
    return bool(HUMAN_OS.search(ua) and REAL_BROWSER.search(ua))


def device_of(ua: str) -> str:
    u = ua.lower()
    if "micromessenger" in u:
        return "微信内置"
    if "ipad" in u or "tablet" in u:
        return "平板"
    if "iphone" in u or "android" in u or "mobile" in u:
        return "手机"
    if "windows" in u:
        return "Windows"
    if "macintosh" in u or "mac os" in u:
        return "macOS"
    if "linux" in u or "x11" in u:
        return "Linux"
    return "其他"


def ref_domain(ref: str) -> str:
    if not ref or ref == "-":
        return "直接访问"
    m = re.match(r"https?://([^/]+)", ref)
    if not m:
        return "直接访问"
    host = m.group(1).lower()
    if "xiaozou123" in host:
        return "站内跳转"
    table = {"baidu": "百度", "google": "Google", "bing": "Bing", "sogou": "搜狗",
             "so.com": "360搜索", "sm.cn": "神马", "toutiao": "今日头条",
             "zhihu": "知乎", "weibo": "微博", "csdn": "CSDN", "juejin": "掘金",
             "bilibili": "B站", "douyin": "抖音", "weixin": "微信",
             "qq.com": "腾讯系", "chatgpt": "ChatGPT", "perplexity": "Perplexity"}
    for k, v in table.items():
        if k in host:
            return v
    return host


def norm_path(m):
    p = m.group("path").split("?")[0]
    return re.sub(r"/{2,}", "/", p)


def main():
    posts = json.load(open(POSTS, encoding="utf-8"))
    try:
        pages = json.load(open(PAGES, encoding="utf-8"))
    except Exception:
        pages = []

    slug2post = {p["slug"]: p for p in posts if p.get("slug")}
    path2slug = {}
    for s in slug2post:
        path2slug["/" + s + "/"] = s
        path2slug["/" + s] = s
    page_set = {pg["slug"] for pg in pages if pg.get("slug")}
    cat_slugs = {"personal-updates", "technical-practice", "digital-disassembly",
                 "digital-recommendations", "digital-news", "technical-learning"}

    # ---------- Pass 1: 脏 IP ----------
    dirty = set()
    if USE_DIRTY_IP:
        with open(LOG, encoding="utf-8", errors="replace") as f:
            for line in f:
                m = LINE_RE.match(line)
                if not m:
                    continue
                low = norm_path(m).lower()
                if any(d in low for d in DANGER) and not any(s in low for s in SAFE_ADMIN):
                    dirty.add(m.group("ip"))
    print(f"[1/3] 脏 IP: {len(dirty)}", file=sys.stderr)

    # ---------- Pass 2: (slug, day) 维度 ----------
    day_stats = defaultdict(lambda: {"pv": 0, "noref": 0, "ips": set()})
    human_article_pv = 0
    total = 0
    skipped = Counter()
    with open(LOG, encoding="utf-8", errors="replace") as f:
        for line in f:
            total += 1
            m = LINE_RE.match(line)
            if not m:
                skipped["parse_fail"] += 1
                continue
            if m.group("status") != "200":
                skipped["non200"] += 1
                continue
            path = norm_path(m)
            if len(path) > 120 or STATIC_EXT.search(path) or SKIP_PATH.match(path):
                skipped["non_page"] += 1
                continue
            if not is_human(m.group("ua")):
                skipped["bot"] += 1
                continue
            ip = m.group("ip")
            slug = path2slug.get(path)
            if slug is None:
                skipped["not_post"] += 1
                continue
            human_article_pv += 1
            if USE_DIRTY_IP and ip in dirty:
                skipped["dirty_ip"] += 1
                continue
            try:
                dt = datetime.strptime(m.group("ts"), "%d/%b/%Y:%H:%M:%S %z")
            except ValueError:
                skipped["badtime"] += 1
                continue
            st = day_stats[(slug, dt.strftime("%Y-%m-%d"))]
            st["pv"] += 1
            st["ips"].add(ip)
            if m.group("ref") == "-":
                st["noref"] += 1

    # ---------- 刷量日判定 ----------
    per_slug_days = defaultdict(list)
    for (slug, day), st in day_stats.items():
        per_slug_days[slug].append(st["pv"])
    median_pv = {}
    for slug, arr in per_slug_days.items():
        arr.sort()
        median_pv[slug] = arr[len(arr) // 2] or 1

    burst_days, burst_detail = set(), []
    if USE_BURST:
        for (slug, day), st in day_stats.items():
            pv, uniq = st["pv"], len(st["ips"])
            if uniq < 80 or pv < 80:
                continue
            per_ip = pv / uniq
            noref_ratio = st["noref"] / pv
            if per_ip <= 1.15 and noref_ratio >= 0.85 and pv > median_pv[slug] * 8:
                burst_days.add((slug, day))
                burst_detail.append({
                    "slug": slug, "day": day, "pv": pv, "uniq": uniq,
                    "per_ip": round(per_ip, 2),
                    "noref": round(noref_ratio, 2),
                    "median": median_pv[slug],
                    "multiple": round(pv / median_pv[slug], 1),
                    "title": slug2post[slug]["title"],
                    "cat": slug2post[slug]["cat"],
                })
    burst_detail.sort(key=lambda x: -x["pv"])
    removed_burst = sum(b["pv"] for b in burst_detail)
    print(f"[2/3] 刷量日: {len(burst_days)} 组，合计 {removed_burst} PV", file=sys.stderr)

    # ---------- Pass 3: 聚合 ----------
    pv = Counter()
    post_ips = defaultdict(set)
    monthly_pv, monthly_uv, site_month_uv = Counter(), defaultdict(set), defaultdict(set)
    post_month_pv = defaultdict(Counter)
    daily_all = Counter()
    daily_post = defaultdict(Counter)
    cat_pv, cat_month = Counter(), defaultdict(Counter)
    ref_c, dev_c = Counter(), Counter()
    first_seen, last_seen = {}, {}
    kind_pv = Counter()
    ipday = Counter()
    capped = raw_pv = 0
    site_ips = set()

    with open(LOG, encoding="utf-8", errors="replace") as f:
        for line in f:
            m = LINE_RE.match(line)
            if not m or m.group("status") != "200":
                continue
            path = norm_path(m)
            if len(path) > 120 or STATIC_EXT.search(path) or SKIP_PATH.match(path):
                continue
            if not is_human(m.group("ua")):
                continue
            ip = m.group("ip")
            if USE_DIRTY_IP and ip in dirty:
                continue
            try:
                dt = datetime.strptime(m.group("ts"), "%d/%b/%Y:%H:%M:%S %z")
            except ValueError:
                continue
            month = dt.strftime("%Y-%m")
            day = dt.strftime("%Y-%m-%d")

            site_ips.add(ip)
            site_month_uv[month].add(ip)

            slug = path2slug.get(path)
            if slug is None:
                if path in ("/", "/index.php"):
                    kind_pv["home"] += 1
                elif path.strip("/") in page_set:
                    kind_pv["page"] += 1
                elif path.strip("/") in cat_slugs or any(("/" + c + "/") in path for c in cat_slugs):
                    kind_pv["archive"] += 1
                else:
                    kind_pv["other"] += 1
                    continue
                daily_all[day] += 1
                continue

            if (slug, day) in burst_days:
                continue

            raw_pv += 1
            key = (ip, day)
            if ipday[key] >= DAILY_CAP:
                capped += 1
                continue
            ipday[key] += 1

            pv[slug] += 1
            post_ips[slug].add(ip)
            monthly_pv[month] += 1
            monthly_uv[month].add(ip)
            post_month_pv[slug][month] += 1
            daily_all[day] += 1
            daily_post[slug][day] += 1
            kind_pv["post"] += 1
            cat = slug2post[slug]["cat"]
            cat_pv[cat] += 1
            cat_month[cat][month] += 1
            ref_c[ref_domain(m.group("ref"))] += 1
            dev_c[device_of(m.group("ua"))] += 1
            first_seen.setdefault(slug, day)
            last_seen[slug] = day

    # ---------- 组装 ----------
    today = max(daily_all) if daily_all else "2026-01-01"
    dt_today = datetime.strptime(today, "%Y-%m-%d")
    cutoff30 = (dt_today - timedelta(days=30)).strftime("%Y-%m-%d")

    rows = []
    for p in posts:
        s = p.get("slug")
        if not s:
            continue
        dstr = p["date"]
        try:
            pub = datetime.strptime(dstr, "%Y-%m-%d")
        except Exception:
            pub = dt_today
        online = max((dt_today - pub).days + 1, 1)
        end30 = (pub + timedelta(days=30)).strftime("%Y-%m-%d")
        dd = daily_post.get(s, {})
        first30 = sum(n for d, n in dd.items() if dstr <= d <= end30)
        recent30 = sum(n for d, n in dd.items() if d >= cutoff30)
        rows.append({
            "id": p["id"], "slug": s, "title": p["title"], "cat": p["cat"],
            "date": dstr, "words": p.get("words", 0),
            "pv": pv.get(s, 0), "uv": len(post_ips.get(s, ())),
            "monthly": dict(sorted(post_month_pv[s].items())),
            "first": first_seen.get(s, ""), "last": last_seen.get(s, ""),
            "online": online,
            "velocity": round(pv.get(s, 0) / online, 2),
            "first30": first30, "recent30": recent30,
            "longtail": round(recent30 / pv[s], 3) if pv.get(s) else 0,
        })
    rows.sort(key=lambda r: -r["pv"])

    months = sorted(monthly_pv.keys())
    cat_rows = []
    for c in sorted(cat_pv, key=lambda k: -cat_pv[k]):
        sub = [r for r in rows if r["cat"] == c]
        n = len(sub)
        tot = cat_pv[c]
        cat_rows.append({
            "cat": c, "pv": tot, "n": n,
            "avg_pv": round(tot / n, 1) if n else 0,
            "avg_uv": round(sum(r["uv"] for r in sub) / n, 1) if n else 0,
            "median_pv": sorted(r["pv"] for r in sub)[n // 2] if n else 0,
            "monthly": dict(sorted(cat_month[c].items())),
            "first30": sum(r["first30"] for r in sub),
            "recent30": sum(r["recent30"] for r in sub),
        })

    result = {
        "meta": {
            "generated_at": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
            "source": "Nginx access log (full history)",
            "log_lines": total,
            "post_pv": sum(pv.values()),
            "post_pv_raw": raw_pv,
            "human_article_pv": human_article_pv,
            "removed_burst": removed_burst,
            "removed_dirty": skipped.get("dirty_ip", 0),
            "removed_bot": skipped.get("bot", 0),
            "rate_capped": capped,
            "daily_cap": DAILY_CAP,
            "dirty_ips": len(dirty),
            "total_pv": sum(kind_pv.values()),
            "kinds": dict(kind_pv),
            "skipped": dict(skipped),
            "post_total": len(rows),
            "active_posts": len([r for r in rows if r["pv"] > 0]),
            "site_uv": len(site_ips),
            "days": len(daily_all),
            "range": [months[0] if months else "", months[-1] if months else ""],
        },
        "burst": burst_detail,
        "posts": rows,
        "months": [{"m": m, "pv": monthly_pv[m], "uv": len(monthly_uv[m]),
                    "site_uv": len(site_month_uv[m])} for m in months],
        "daily": [{"d": d, "pv": daily_all[d]} for d in sorted(daily_all)],
        "categories": cat_rows,
        "refs": ref_c.most_common(12),
        "devices": dev_c.most_common(8),
    }
    json.dump(result, open(OUT, "w", encoding="utf-8"), ensure_ascii=False)
    print(json.dumps({k: result["meta"][k] for k in
                      ("post_pv", "post_pv_raw", "removed_dirty", "removed_burst",
                       "rate_capped", "total_pv", "site_uv")},
                     ensure_ascii=False, indent=1))


if __name__ == "__main__":
    main()
