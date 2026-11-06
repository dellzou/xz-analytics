<?php
/**
 * Plugin Name: XZ 内容数据面板
 * Plugin URI:  https://github.com/dellzou/xz-analytics
 * Description: 从 Nginx 访问日志统计文章真实阅读量，在仪表盘展示哪些文章、哪些类型真正有人在读。
 * Version:     1.0.1
 * Author:      ZouNing
 * Author URI:  https://xiaozou123.cn
 * License:     MIT
 * Text Domain: xz-analytics
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

define('XZ_ANALYTICS_VERSION', '1.0.1');
define('XZ_ANALYTICS_FILE', __FILE__);
define('XZ_ANALYTICS_DIR', plugin_dir_path(__FILE__));
define('XZ_ANALYTICS_URL', plugin_dir_url(__FILE__));
define('XZ_ANALYTICS_SLUG', 'xz-analytics');
define('XZ_ANALYTICS_CAP', 'edit_posts');

/**
 * 数据文件放在 uploads 下，避免插件升级时被覆盖，也方便运维直接替换。
 */
function xz_analytics_data_file(): string
{
    $uploads = wp_upload_dir();
    return trailingslashit($uploads['basedir']) . 'xz-analytics/stats.json';
}

/**
 * 读取统计数据，按文件修改时间做进程内缓存。
 * 返回 null 表示还没有生成过数据。
 */
function xz_analytics_get_data(): ?array
{
    static $cache = null;
    static $loaded = false;

    if ($loaded) {
        return $cache;
    }
    $loaded = true;

    $file = xz_analytics_data_file();
    if (!is_readable($file)) {
        return null;
    }
    $raw = file_get_contents($file);
    if ($raw === false) {
        return null;
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['meta'])) {
        return null;
    }
    $cache = $data;
    return $cache;
}

/**
 * 数据新鲜度：返回 ['text' => '3 小时前', 'stale' => bool]
 */
function xz_analytics_freshness(): array
{
    $file = xz_analytics_data_file();
    if (!is_readable($file)) {
        return ['text' => '尚未生成', 'stale' => true];
    }
    $diff = time() - filemtime($file);
    if ($diff < 3600) {
        $text = max(1, round($diff / 60)) . ' 分钟前更新';
    } elseif ($diff < 86400) {
        $text = round($diff / 3600) . ' 小时前更新';
    } else {
        $text = round($diff / 86400) . ' 天前更新';
    }
    return ['text' => $text, 'stale' => $diff > 86400 * 2];
}

/**
 * 仪表盘小工具：核心指标 + 12 个月迷你走势。
 */
function xz_analytics_dashboard_widget()
{
    $data = xz_analytics_get_data();
    $fresh = xz_analytics_freshness();

    if (!$data) {
        echo '<div class="xz-empty">';
        echo '<p>还没有统计数据。请在服务器上运行一次分析脚本生成：</p>';
        echo '<pre>cd ' . esc_html(ABSPATH) . 'wp-content/plugins/xz-analytics/bin &amp;&amp; python3 xz_stats.py</pre>';
        echo '</div>';
        return;
    }

    $meta = $data['meta'];
    $months = isset($data['months']) ? $data['months'] : [];
    $months = array_slice($months, -12);

    $pv      = isset($meta['post_pv']) ? (int) $meta['post_pv'] : 0;
    $uv      = isset($meta['site_uv']) ? (int) $meta['site_uv'] : 0;
    $total   = isset($meta['total_pv']) ? (int) $meta['total_pv'] : 0;
    $active  = isset($meta['active_posts']) ? (int) $meta['active_posts'] : 0;
    $raw     = isset($meta['human_article_pv']) ? (int) $meta['human_article_pv'] : 0;
    $days    = isset($meta['days']) && (int) $meta['days'] > 0 ? (int) $meta['days'] : 1;
    $removed = max(0, $raw - $pv);

    // 最能打的类型：按篇均 PV 排第一的分类
    $best_cat_name = '—';
    $best_cat_sub  = '暂无数据';
    if (!empty($data['categories'])) {
        $sorted = $data['categories'];
        usort($sorted, function ($a, $b) {
            return ($b['avg_pv'] <=> $a['avg_pv']);
        });
        $best_cat_name = $sorted[0]['cat'];
        $best_cat_sub  = '篇均 ' . round($sorted[0]['avg_pv']) . ' · ' . (int) $sorted[0]['n'] . ' 篇';
    }

    $kpis = [
        ['有效文章阅读', number_format($pv), '日均 ' . number_format((int) round($pv / $days)) . ' 次', false],
        ['独立访客', number_format($uv), '人均 ' . ($uv > 0 ? round($total / $uv, 2) : 0) . ' 次访问', false],
        ['最能打的类型', $best_cat_name, $best_cat_sub, true],
        ['已剔除异常', number_format($removed), '占原始 ' . ($raw > 0 ? round($removed / $raw * 100) : 0) . '%', false],
    ];
    ?>
    <div class="xz-widget">
        <div class="xz-widget__head">
            <span class="xz-muted"><?php echo esc_html($meta['range'][0] . ' → ' . $meta['range'][1] . '，共 ' . $days . ' 天'); ?></span>
            <span class="xz-badge <?php echo $fresh['stale'] ? 'is-stale' : ''; ?>"><?php echo esc_html($fresh['text']); ?></span>
        </div>

        <div class="xz-kpis">
            <?php foreach ($kpis as $k) : ?>
                <div class="xz-kpi">
                    <div class="xz-kpi__label"><?php echo esc_html($k[0]); ?></div>
                    <div class="xz-kpi__value<?php echo $k[3] ? ' is-text' : ''; ?>"><?php echo esc_html($k[1]); ?></div>
                    <div class="xz-kpi__sub"><?php echo esc_html($k[2]); ?></div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($months) : ?>
            <?php
            $vals = array_map(function ($m) {
                return isset($m['pv']) ? (int) $m['pv'] : 0;
            }, $months);
            $peak = max(1, max($vals));
            ?>
            <div class="xz-spark" role="img" aria-label="近 12 个月文章阅读量走势">
                <?php foreach ($months as $i => $m) : ?>
                    <?php $h = max(3, (int) round($vals[$i] / $peak * 100)); ?>
                    <div class="xz-spark__col" title="<?php echo esc_attr($m['m'] . '：' . number_format($vals[$i]) . ' 次'); ?>">
                        <div class="xz-spark__bar" style="height:<?php echo $h; ?>%"></div>
                        <?php
                        $n = count($months);
                        $show_label = ($i === 0 || $i === $n - 1 || $i % 3 === 0);
                        ?>
                        <span class="xz-spark__x"><?php echo $show_label ? esc_html($m['m']) : '&nbsp;'; ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="xz-widget__foot">
            <a class="button button-primary" href="<?php echo esc_url(admin_url('index.php?page=' . XZ_ANALYTICS_SLUG)); ?>">
                查看完整面板
            </a>
            <span class="xz-muted">
                榜单第一：<?php
                if (!empty($data['posts'][0])) {
                    echo esc_html(mb_substr($data['posts'][0]['title'], 0, 22)) . '（' . number_format($data['posts'][0]['pv']) . ' PV）';
                }
                ?>
            </span>
        </div>
    </div>
    <?php
}

function xz_analytics_register_widget()
{
    if (!current_user_can(XZ_ANALYTICS_CAP)) {
        return;
    }
    wp_add_dashboard_widget(
        'xz_analytics_overview',
        'XZ 内容数据 · 文章阅读排行',
        'xz_analytics_dashboard_widget',
        null,
        null,
        'normal',
        'high'   // 排在仪表盘最前面，否则会被挤到页面底部
    );
}
add_action('wp_dashboard_setup', 'xz_analytics_register_widget');

/**
 * 后台菜单：仪表盘 → 内容数据
 */
function xz_analytics_admin_menu()
{
    $hook = add_submenu_page(
        'index.php',
        '内容数据面板',
        '内容数据',
        XZ_ANALYTICS_CAP,
        XZ_ANALYTICS_SLUG,
        'xz_analytics_render_page'
    );
    if ($hook) {
        add_action('admin_print_styles-' . $hook, 'xz_analytics_admin_assets');
    }
}
add_action('admin_menu', 'xz_analytics_admin_menu');

/**
 * 用文件修改时间做资源版本号：改样式或脚本后自动失效，不用手动升版本。
 */
function xz_analytics_asset_ver(string $relative): string
{
    $path = XZ_ANALYTICS_DIR . $relative;
    return file_exists($path) ? (string) filemtime($path) : XZ_ANALYTICS_VERSION;
}

function xz_analytics_admin_assets()
{
    wp_enqueue_style(
        'xz-analytics-admin',
        XZ_ANALYTICS_URL . 'assets/admin.css',
        [],
        xz_analytics_asset_ver('assets/admin.css')
    );
    wp_enqueue_script(
        'xz-analytics-admin',
        XZ_ANALYTICS_URL . 'assets/admin.js',
        [],
        xz_analytics_asset_ver('assets/admin.js'),
        true
    );
}

/**
 * 仪表盘小工具的样式也要在 dashboard 页加载。
 */
function xz_analytics_dashboard_assets($hook)
{
    if ('index.php' === $hook) {
        wp_enqueue_style(
            'xz-analytics-admin',
            XZ_ANALYTICS_URL . 'assets/admin.css',
            [],
            xz_analytics_asset_ver('assets/admin.css')
        );
    }
}
add_action('admin_enqueue_scripts', 'xz_analytics_dashboard_assets');

function xz_analytics_render_page()
{
    if (!current_user_can(XZ_ANALYTICS_CAP)) {
        wp_die('权限不足');
    }
    $frame = wp_nonce_url(
        admin_url('admin-ajax.php?action=xz_analytics_panel'),
        'xz_analytics_panel'
    );
    $fresh = xz_analytics_freshness();
    ?>
    <div class="wrap xz-panel-wrap">
        <h1 class="wp-heading-inline">内容数据面板</h1>
        <span class="xz-badge <?php echo $fresh['stale'] ? 'is-stale' : ''; ?>"><?php echo esc_html($fresh['text']); ?></span>
        <hr class="wp-header-end">
        <iframe id="xz-analytics-frame" class="xz-frame" src="<?php echo esc_url($frame); ?>"
                title="内容数据面板" scrolling="no"></iframe>
        <noscript><p>需要启用 JavaScript 才能显示面板。</p></noscript>
    </div>
    <?php
}

/**
 * 面板本体：把数据注入模板后整页输出，供 iframe 加载。
 * 走 admin-ajax 而不是静态文件，是为了强制登录态 —— 流量数据不该公开可读。
 */
function xz_analytics_ajax_panel()
{
    check_ajax_referer('xz_analytics_panel');

    if (!current_user_can(XZ_ANALYTICS_CAP)) {
        status_header(403);
        wp_die('权限不足');
    }

    $tpl_file = XZ_ANALYTICS_DIR . 'assets/panel.html';
    if (!is_readable($tpl_file)) {
        status_header(500);
        wp_die('面板模板缺失：assets/panel.html');
    }

    $data = xz_analytics_get_data();
    $payload = $data ? wp_json_encode($data, JSON_UNESCAPED_UNICODE) : '{"meta":{},"posts":[],"months":[],"categories":[],"refs":[],"devices":[],"burst":[]}';
    // 防止数据里出现 </script> 提前闭合
    $payload = str_replace('</', '<\/', $payload);

    $html = str_replace('__DATA__', $payload, file_get_contents($tpl_file));

    nocache_headers();
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    wp_die();
}
add_action('wp_ajax_xz_analytics_panel', 'xz_analytics_ajax_panel');

/**
 * 激活时准备数据目录。
 */
function xz_analytics_activate()
{
    $uploads = wp_upload_dir();
    $dir = trailingslashit($uploads['basedir']) . 'xz-analytics';
    if (!file_exists($dir)) {
        wp_mkdir_p($dir);
    }
    if (!file_exists($dir . '/index.php')) {
        file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n");
    }
}
register_activation_hook(__FILE__, 'xz_analytics_activate');

/**
 * 插件列表里加一行「上次数据生成时间」，方便判断是不是该刷新了。
 */
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    $fresh = xz_analytics_freshness();
    $links[] = '<span style="color:#646970">' . esc_html($fresh['text']) . '</span>';
    return $links;
});
