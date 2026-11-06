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
    └── xz_stats.py         # 日志分析与四层清洗
```

面板本身是零依赖的单文件 HTML（纯 SVG 画图，不加载任何 CDN），所以离线也能开，也不受后台样式干扰。

## 已知局限

- **UV 按 IP 去重**。同一出口 NAT 下的多人会被算成一个，UV 偏低。
- **没有停留时长和会话深度**。日志里有这些信息，但目前没解析。
- **刷量判定是启发式的**。真实爆款也可能触发"人均 1 次"——所以面板把剔除明细列出来了，有疑问可以自己核。
- 每次全量扫描全部日志。日志涨到千万行级别后需要改成增量解析。

## License

MIT
