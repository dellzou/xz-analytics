# XZ 内容数据面板

给 WordPress 站点做的内容数据面板：从 Nginx 访问日志里算出**文章真实阅读量**，在后台仪表盘直接看哪些文章、哪类内容真正有人在读。

为 [xiaozou123.cn](https://xiaozou123.cn) 而写，但任何 Nginx + WordPress 的站点都能用。

## 它解决什么问题

站点没装统计插件，想知道"哪些文章受欢迎"却无从下手。其实 Web 服务器日志里一直记着每一次访问，只是没人去读。

这个插件把日志读成一张能被决策的排行榜，并且**把脏数据挡在外面**——爬虫、扫描器、刷量会让排行榜彻底失真。

## 界面

**WordPress 后台 → 仪表盘**，顶部第一块就是：

- 有效文章阅读 / 独立访客 / 最能打的类型 / 已剔除异常流量
- 近 12 个月迷你走势
- 「查看完整面板」进入全量排行

**后台 → 仪表盘 → 内容数据** 是完整面板：类型表现、文章排行（可筛选、可排序、点开看单篇月度曲线）、流量来源、设备构成、以及这份数据是怎么算出来的。

## 为什么数字比你想的少

日志里 127 万行请求，真实文章阅读可能只有 2.6 万次。差的那些不是被漏掉，是本来就不该算。清洗分四层，顺序不能乱：

| 层 | 判据 | 典型占比 |
|---|---|---|
| 机器人 UA | UA 含 bot/spider/crawl/exporter 等，或伪装成浏览器的采集器 | 大部分 |
| 扫描器 IP | 日志期内访问过 `wp-login.php` / `xmlrpc.php` / `wp-config.php` / `wp-admin` | ~23% |
| 刷量日 | 单日 PV 超该文日中位数 8 倍，且人均 1 次、几乎无 referer | 视情况 |
| 高频重复 | 同一 IP 单日超 10 次的部分 | 少量 |

面板底部把每一层的剔除量都列出来，数字可核对，不用盲信。

## 安装

```bash
cd wp-content/plugins
git clone https://github.com/dellzou/xz-analytics.git
cd xz-analytics && chmod +x bin/refresh.sh
wp plugin activate xz-analytics
```

然后生成第一份数据（约 1 分钟，取决于日志大小）：

```bash
./bin/refresh.sh
```

数据写到 `wp-content/uploads/xz-analytics/stats.json`，仪表盘立刻就能看到。

### 每日自动刷新

```bash
crontab -e
```

```
30 3 * * * /path/to/wp-content/plugins/xz-analytics/bin/refresh.sh --quiet >> /var/log/xz-analytics.log 2>&1
```

## AI 总结（可选）

面板底部「这份数据说明了什么」默认由规则模板生成。配置大模型后，可以改由模型根据统计摘要写结论，**规则模板保留为降级兜底**——没配 key、调用失败、返回不是合法 JSON，都会自动回落。

### 开启

把 `bin/ai-config.example.json` 复制到 `wp-content/uploads/xz-analytics/ai-config.json`，填上 key 并把 `enabled` 改成 `true`：

```json
{
  "enabled": true,
  "base_url": "https://api.deepseek.com/v1",
  "model": "deepseek-chat",
  "api_key": "sk-...",
  "temperature": 0.3,
  "max_tokens": 900,
  "timeout": 40
}
```

只要服务商兼容 OpenAI 的 `/chat/completions` 协议就能用，常见配置：

| 服务商 | base_url | 模型示例 |
|---|---|---|
| DeepSeek | `https://api.deepseek.com/v1` | `deepseek-chat` |
| 通义千问 | `https://dashscope.aliyuncs.com/compatible-mode/v1` | `qwen-plus` |
| 智谱 | `https://open.bigmodel.cn/api/paas/v4` | `glm-4-flash` |
| Kimi | `https://api.moonshot.cn/v1` | `moonshot-v1-8k` |
| OpenAI | `https://api.openai.com/v1` | `gpt-4o-mini` |

填完手动跑一次生成：

```bash
python3 bin/ai_summary.py
```

之后每天数据刷新时会自动重算。想先看提示词长什么样，加 `--dry-run`；想调前端样式不想花钱，用 `--mock` 写一份假数据。

### 它拿到的是什么

不是原始日志，也不是完整面板数据，而是 `stats.json` 里的 `digest` 字段——约 1.5K token：总览、六类内容表现、TOP15 文章、近 30 天日序列的异常点、来源结构、设备构成、新文与零流量文清单。

**异常点（暴涨日/断崖日/新文强势）在 Python 里算好**，模型只负责解读，不负责计算。这是刻意的：让模型做算术，它一定会编。

### 输出与校验

模型返回结构化 JSON（`headline` / `findings[]` / `actions[]`），前端按风险等级上色渲染，每条结论都带 `evidence` 数字。

生成后会做一次数字校验：把结论里出现的数字与摘要里的数值比对，对不上的列进 `unverified_numbers`，面板底部会提示「有 N 个数字在原始数据里找不到出处」。不直接丢弃，是怕误杀四舍五入和阈值（比如「60 天」）造成的正常差异。

### 成本

一次约 2K 输入 + 600 输出。DeepSeek 大概 0.002 元/次，每天一次，一个月不到 1 毛。

## 配置

`bin/refresh.sh` 会自动从插件位置推断 WordPress 根目录，无需配置。分析脚本的路径可以用环境变量覆盖：

| 变量 | 默认值 | 说明 |
|---|---|---|
| `XZ_ACCESS_LOG` | `/www/wwwlogs/wordpress.local.log` | Nginx access log，**唯一必须对上的一项** |
| `XZ_POSTS_JSON` | 由 refresh.sh 指定 | 文章元数据 |
| `XZ_PAGES_JSON` | 由 refresh.sh 指定 | 页面 slug 列表 |
| `XZ_STATS_OUT` | 由 refresh.sh 指定 | 输出 JSON |

> 日志路径因面板/系统而异，宝塔是 `/www/wwwlogs/`，LNMP 是 `/home/wwwlogs/`，Debian 系默认 `/var/log/nginx/`。对不上就改 `refresh.sh` 里的默认值或设环境变量。

## 目录结构

```
xz-analytics/
├── xz-analytics.php        # 插件主体：仪表盘小工具、面板页、ajax 输出
├── assets/
│   ├── panel.html          # 面板模板，__DATA__ 由 PHP 注入 JSON
│   ├── admin.css
│   └── admin.js            # iframe 高度自适应
└── bin/
    ├── refresh.sh          # 导出文章元数据 + 跑分析，cron 入口
    ├── xz_stats.py         # 日志分析与四层清洗，产出 digest
    ├── ai_summary.py       # 可选：把 digest 交给大模型写成结论
    └── ai-config.example.json
```

面板本身是零依赖的单文件 HTML（纯 SVG 画图，不加载任何 CDN），所以离线也能开，也不受后台样式干扰。

## 已知局限

- **UV 按 IP 去重**。同一出口 NAT 下的多人会被算成一个，UV 偏低。
- **没有停留时长和会话深度**。日志里有这些信息，但目前没解析。
- **刷量判定是启发式的**。真实爆款也可能触发"人均 1 次"——所以面板把剔除明细列出来了，有疑问可以自己核。
- 每次全量扫描全部日志。日志涨到千万行级别后需要改成增量解析。

## License

MIT
