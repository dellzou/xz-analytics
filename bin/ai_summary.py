#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
用大模型把统计摘要写成中文结论，结果写回 stats.json 的 ai_summary 字段。

设计要点：
  - 只吃 stats.json 里的 digest（约 1.5K token），不喂原始明细
  - 走 OpenAI 兼容协议，千问/DeepSeek/Kimi/智谱/OpenAI 都能用
  - 失败不影响主数据：写 ai_summary.error，面板自动回落到规则文案
  - 输出做数字校验，模型编出来的数字会被标记出来

用法：
  python3 ai_summary.py              # 正常调用
  python3 ai_summary.py --dry-run    # 只打印提示词
  python3 ai_summary.py --mock       # 写一份假结果，用于调前端
"""
import argparse
import json
import os
import re
import sys
import urllib.error
import urllib.request
from pathlib import Path

DATA_DIR = Path(os.environ.get(
    "XZ_DATA_DIR",
    "/usr/local/lighthouse/softwares/wordpress/wp-content/uploads/xz-analytics"))
STATS = Path(os.environ.get("XZ_STATS_OUT", str(DATA_DIR / "stats.json")))
CONFIG = Path(os.environ.get("XZ_AI_CONFIG", str(DATA_DIR / "ai-config.json")))

# 数据里没出现、但结论里合法可用的阈值类数字
EXTRA_NUMBERS = {7, 8, 10, 14, 30, 60, 90, 100, 2, 3, 5}

SYSTEM_PROMPT = """你是网站内容数据分析助手。用户会给一份统计摘要，你要写出简洁、可执行的中文结论。

硬性规则：
1. 只能引用摘要里出现过的数字，禁止推算、估算、四舍五入后当成新数字。
2. 每条结论必须落到具体数字上，没有数字支撑的判断不要写。
3. 优先讲：异常波动 > 结构变化（来源/类型）> 头部集中度 > 内容质量。
4. 不要写"数据表明""值得我们关注""建议持续优化"这类空话。
5. 不用"显著""大幅""明显"这类无法量化的词，除非摘要里有对比数字。
6. 不复述摘要原文，要给判断。例如不要写"技术实践 71 篇 13459 次阅读"，
   而要写"技术实践篇数占 36%，但篇均 190 低于数码拆解的 387，产出效率被篇数稀释"。
7. 严格输出 JSON，不要加解释性文字，不要用 markdown 代码块包裹。"""

USER_TEMPLATE = """以下是站点近 {days} 天的内容数据摘要（{start} 至 {end}）：

{payload}

请输出 JSON，结构如下：
{{
  "headline": "一句话结论，不超过 30 字，点出最值得注意的事",
  "findings": [
    {{"level": "risk|warn|info", "text": "结论，40-80 字", "evidence": "支撑这条结论的关键数字"}}
  ],
  "actions": ["具体动作，20-40 字，动词开头"]
}}

要求：
- findings 写 4-6 条，按重要性从高到低。level 里 risk 表示需要马上处理，warn 表示需要关注，info 表示正常观察。
- actions 写 2-3 条，必须是能直接做的动作，不要写"加强运营"这种。
- 如果摘要里的 anomalies 为空，不要编造波动。
- 全文用中文。"""


def load_config() -> dict:
    if not CONFIG.is_file():
        return {}
    try:
        return json.loads(CONFIG.read_text(encoding="utf-8"))
    except Exception as e:
        print(f"[ai] 配置解析失败：{e}", file=sys.stderr)
        return {}


def build_prompt(digest: dict) -> list:
    period = digest.get("period", {})
    payload = json.dumps(digest, ensure_ascii=False, indent=1)
    user = USER_TEMPLATE.format(
        days=period.get("days", "?"),
        start=period.get("from", "?"),
        end=period.get("to", "?"),
        payload=payload,
    )
    return [
        {"role": "system", "content": SYSTEM_PROMPT},
        {"role": "user", "content": user},
    ]


def call_api(cfg: dict, messages: list) -> str:
    base = (cfg.get("base_url") or "").rstrip("/")
    if not base:
        raise ValueError("ai-config.json 缺少 base_url")
    if not cfg.get("api_key"):
        raise ValueError("ai-config.json 缺少 api_key")
    model = cfg.get("model") or ""

    url = base + "/chat/completions"
    body = {
        "model": model,
        "messages": messages,
        "temperature": float(cfg.get("temperature", 0.3)),
        "max_tokens": int(cfg.get("max_tokens", 900)),
    }
    req = urllib.request.Request(url, data=json.dumps(body).encode("utf-8"), method="POST")
    req.add_header("Authorization", "Bearer " + str(cfg["api_key"]))
    req.add_header("Content-Type", "application/json")
    req.add_header("User-Agent", "xz-analytics/1.0")

    timeout = float(cfg.get("timeout", 40))
    try:
        with urllib.request.urlopen(req, timeout=timeout) as r:
            data = json.loads(r.read().decode("utf-8"))
    except urllib.error.HTTPError as e:
        detail = e.read().decode("utf-8", "replace")[:300]
        raise RuntimeError(f"HTTP {e.code}: {detail}")

    try:
        return data["choices"][0]["message"]["content"]
    except (KeyError, IndexError):
        raise RuntimeError(f"返回结构异常：{json.dumps(data, ensure_ascii=False)[:300]}")


def parse_output(text: str) -> dict:
    raw = text.strip()
    # 兼容被 ```json 包裹、或前后带解释文字的情况
    m = re.search(r"\{[\s\S]*\}", raw)
    if not m:
        raise ValueError("返回内容里找不到 JSON")
    obj = json.loads(m.group(0))
    if not isinstance(obj, dict) or "findings" not in obj:
        raise ValueError("JSON 缺少 findings 字段")
    return obj


def collect_numbers(obj, acc=None) -> set:
    """把摘要里出现的所有数值收集成集合，用于校验模型有没有编数字。"""
    if acc is None:
        acc = set()
    if isinstance(obj, dict):
        for v in obj.values():
            collect_numbers(v, acc)
    elif isinstance(obj, list):
        for v in obj:
            collect_numbers(v, acc)
    elif isinstance(obj, bool):
        pass
    elif isinstance(obj, (int, float)):
        acc.add(round(float(obj), 1))
    elif isinstance(obj, str):
        for n in re.findall(r"\d+(?:\.\d+)?", obj):
            acc.add(round(float(n), 1))
    return acc


def verify_numbers(summary: dict, digest: dict) -> list:
    """返回模型用到、但摘要里找不到出处的数字。"""
    allowed = collect_numbers(digest) | {float(x) for x in EXTRA_NUMBERS}
    text = json.dumps(summary, ensure_ascii=False)
    unknown = []
    for token in re.findall(r"\d+(?:\.\d+)?", text):
        val = round(float(token), 1)
        if val in allowed:
            continue
        # 允许 ±1 的取整误差
        if any(abs(val - a) <= 1 for a in allowed if a >= 10):
            continue
        unknown.append(token)
    return sorted(set(unknown))


def write_summary(payload: dict) -> None:
    stats = json.loads(STATS.read_text(encoding="utf-8"))
    stats["ai_summary"] = payload
    STATS.write_text(json.dumps(stats, ensure_ascii=False), encoding="utf-8")


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--dry-run", action="store_true", help="只打印提示词")
    ap.add_argument("--mock", action="store_true", help="写一份假结果")
    args = ap.parse_args()

    if not STATS.is_file():
        print(f"[ai] 找不到 {STATS}", file=sys.stderr)
        return 1

    stats = json.loads(STATS.read_text(encoding="utf-8"))
    digest = stats.get("digest")
    if not digest:
        print("[ai] stats.json 里没有 digest，先跑 xz_stats.py", file=sys.stderr)
        return 1

    if args.mock:
        payload = {
            "headline": "（示例）数码拆解效率最高，百度几乎零流量",
            "findings": [
                {"level": "risk", "text": "外部来源里 Bing 占 76%，百度只有 23 次，"
                                          "国内读者搜不到站点，这是当前最大的流量缺口。",
                 "evidence": "Bing 4919 次 vs 百度 23 次"},
                {"level": "warn", "text": "前 3 篇文章吃掉全部阅读的 32%，"
                                          "头部一旦掉排名整站数据会立刻塌，第二梯队还没长起来。",
                 "evidence": "top3 占比 32%"},
                {"level": "info", "text": "数码拆解 11 篇篇均 387，是理论学习 79 的 4.9 倍，"
                                          "单篇产出效率明显更高。",
                 "evidence": "387 vs 79"},
            ],
            "actions": ["把百度收录当成独立任务推进：主动提交 sitemap、检查 robots 与备案状态",
                        "按拆解的结构改造 2 篇理论学习文章，做一次对照"],
            "model": "mock",
            "generated_at": "",
            "unverified_numbers": [],
        }
        write_summary(payload)
        print("[ai] 已写入 mock 结果")
        return 0

    cfg = load_config()
    if not cfg.get("enabled"):
        print("[ai] 未启用（ai-config.json 里 enabled=false 或文件不存在），跳过", file=sys.stderr)
        return 0

    messages = build_prompt(digest)
    if args.dry_run:
        print(json.dumps(messages, ensure_ascii=False, indent=1))
        print(f"\n[ai] 提示词约 {sum(len(m['content']) for m in messages)} 字符", file=sys.stderr)
        return 0

    try:
        text = call_api(cfg, messages)
        summary = parse_output(text)
    except Exception as e:
        print(f"[ai] 生成失败：{e}", file=sys.stderr)
        write_summary({"error": str(e)[:200], "generated_at": ""})
        return 0   # 不让 refresh.sh 因此失败

    unknown = verify_numbers(summary, digest)
    if unknown:
        print(f"[ai] 警告：以下数字在摘要里找不到出处 -> {unknown}", file=sys.stderr)

    summary["model"] = cfg.get("model", "")
    summary["generated_at"] = stats["meta"].get("generated_at", "")
    summary["unverified_numbers"] = unknown
    write_summary(summary)
    print(f"[ai] 已生成 {len(summary.get('findings', []))} 条结论，"
          f"{len(summary.get('actions', []))} 条动作"
          + (f"，{len(unknown)} 个数字待核" if unknown else ""))
    return 0


if __name__ == "__main__":
    sys.exit(main())
