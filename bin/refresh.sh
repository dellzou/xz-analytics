#!/usr/bin/env bash
#
# 生成内容统计数据，供 WordPress 插件「XZ 内容数据面板」读取。
#
# 用法：
#   ./refresh.sh            # 手动跑，打印过程
#   ./refresh.sh --quiet    # 给 cron 用，只在出错时说话
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(dirname "$SCRIPT_DIR")"
WP_ROOT="$(cd "$PLUGIN_DIR/../../.." && pwd)"
DATA_DIR="$WP_ROOT/wp-content/uploads/xz-analytics"
STATS_FILE="$DATA_DIR/stats.json"
QUIET=0
[ "${1:-}" = "--quiet" ] && QUIET=1

log()  { [ "$QUIET" -eq 1 ] || echo "$@"; }
fail() { echo "[xz-analytics] 失败：$*" >&2; exit 1; }

[ -d "$WP_ROOT/wp-content" ] || fail "找不到 WordPress 根目录：$WP_ROOT"
command -v wp      >/dev/null 2>&1 || fail "找不到 wp-cli"
command -v python3 >/dev/null 2>&1 || fail "找不到 python3"

mkdir -p "$DATA_DIR"

# ---------- 1. 导出文章与页面元数据 ----------
log "→ 导出文章元数据"
EXPORTER="$DATA_DIR/.export.php"
cat > "$EXPORTER" <<'PHP'
<?php
$cats = get_categories(array('hide_empty' => false));
$map  = array();
foreach ($cats as $c) { $map[$c->term_id] = $c->name; }

$out = array();
foreach (get_posts(array('post_type' => 'post', 'post_status' => 'publish', 'numberposts' => -1)) as $p) {
    $ids  = wp_get_post_categories($p->ID);
    $name = (!empty($ids) && isset($map[$ids[0]])) ? $map[$ids[0]] : '未分类';
    $out[] = array(
        'id'    => $p->ID,
        'slug'  => $p->post_name,
        'title' => html_entity_decode(get_the_title($p->ID), ENT_QUOTES, 'UTF-8'),
        'cat'   => $name,
        'date'  => substr($p->post_date, 0, 10),
        'words' => str_word_count(strip_tags($p->post_content)),
    );
}
file_put_contents(__DIR__ . '/posts.json', json_encode($out, JSON_UNESCAPED_UNICODE));

$out = array();
foreach (get_posts(array('post_type' => 'page', 'post_status' => 'publish', 'numberposts' => -1)) as $p) {
    $out[] = array('id' => $p->ID, 'slug' => $p->post_name);
}
file_put_contents(__DIR__ . '/pages.json', json_encode($out));

echo "posts=" . count($out) . "\n";
PHP

wp eval-file "$EXPORTER" --allow-root --path="$WP_ROOT" >/dev/null 2>&1 \
  || { rm -f "$EXPORTER"; fail "导出文章数据失败"; }
rm -f "$EXPORTER"

[ -s "$DATA_DIR/posts.json" ] || fail "posts.json 为空"

# ---------- 2. 分析访问日志 ----------
log "→ 分析访问日志（约 1 分钟）"
XZ_POSTS_JSON="$DATA_DIR/posts.json" \
XZ_PAGES_JSON="$DATA_DIR/pages.json" \
XZ_STATS_OUT="$STATS_FILE" \
python3 "$SCRIPT_DIR/xz_stats.py" \
    > "$DATA_DIR/last-run.json" 2> "$DATA_DIR/last-run.err" \
  || fail "分析脚本出错，详见 $DATA_DIR/last-run.err"

[ -s "$STATS_FILE" ] || fail "没有生成 $STATS_FILE"

# 让 web 进程读得到
chmod 644 "$STATS_FILE" 2>/dev/null || true
chown www:www "$STATS_FILE" 2>/dev/null || true

log "✓ 完成：$STATS_FILE（$(du -h "$STATS_FILE" | cut -f1)）"
