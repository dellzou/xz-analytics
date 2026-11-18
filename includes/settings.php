<?php
/**
 * AI 总结的后台设置页。
 *
 * 两条硬约束：
 *
 * 1. 服务器出于安全考虑禁用了 PHP 执行外部命令的能力（exec / shell_exec /
 *    proc_open），这是防止恶意代码执行的常规加固手段，不是本插件的缺陷。
 *    因此本页不会去触发任何脚本，AI 总结统一由系统定时任务在每天数据刷新后生成。
 *
 * 2. api key 存放在 web 根之外的 ai-config.json（权限 600），页面上只显示掩码，
 *    不回显明文，也不写进 HTML。
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 内置预设：只保证 base_url 正确，模型名会随厂商更新，界面上可自由改写。
 * 模型名填错会直接 404，所以这里给的是常见值而非"当前最优值"。
 */
function xz_ai_presets(): array
{
    return [
        ['k' => 'deepseek', 'n' => 'DeepSeek', 'b' => 'https://api.deepseek.com/v1', 'm' => 'deepseek-chat'],
        ['k' => 'qwen', 'n' => '通义千问（阿里云百炼）', 'b' => 'https://dashscope.aliyuncs.com/compatible-mode/v1', 'm' => 'qwen-plus'],
        ['k' => 'zhipu', 'n' => '智谱 GLM', 'b' => 'https://open.bigmodel.cn/api/paas/v4', 'm' => 'glm-4-plus'],
        ['k' => 'kimi', 'n' => 'Kimi（月之暗面）', 'b' => 'https://api.moonshot.cn/v1', 'm' => 'moonshot-v1-8k'],
        ['k' => 'doubao', 'n' => '豆包（火山方舟）', 'b' => 'https://ark.cn-beijing.volces.com/api/v3', 'm' => ''],
        ['k' => 'ernie', 'n' => '文心一言（百度千帆）', 'b' => 'https://qianfan.baidubce.com/v2', 'm' => 'ernie-4.0-8k'],
        ['k' => 'hunyuan', 'n' => '腾讯混元', 'b' => 'https://api.hunyuan.cloud.tencent.com/v1', 'm' => 'hunyuan-turbos-latest'],
        ['k' => 'minimax', 'n' => 'MiniMax', 'b' => 'https://api.minimax.cn/v1', 'm' => 'MiniMax-Text-01'],
        ['k' => 'siliconflow', 'n' => '硅基流动', 'b' => 'https://api.siliconflow.cn/v1', 'm' => 'deepseek-ai/DeepSeek-V3'],
        ['k' => 'openai', 'n' => 'OpenAI', 'b' => 'https://api.openai.com/v1', 'm' => 'gpt-4o-mini'],
        ['k' => 'ollama', 'n' => '本地 Ollama / vLLM / LM Studio', 'b' => 'http://127.0.0.1:11434/v1', 'm' => 'qwen2.5:7b'],
        ['k' => 'custom', 'n' => '其他（任意 OpenAI 兼容服务）', 'b' => '', 'm' => ''],
    ];
}

function xz_ai_config_file(): string
{
    return xz_analytics_data_dir() . '/ai-config.json';
}

function xz_ai_config_get(): array
{
    $file = xz_ai_config_file();
    if (!is_readable($file)) {
        return [];
    }
    $raw = file_get_contents($file);
    $cfg = json_decode((string) $raw, true);
    return is_array($cfg) ? $cfg : [];
}

function xz_ai_config_put(array $cfg): bool
{
    $file = xz_ai_config_file();
    $dir  = dirname($file);
    if (!is_dir($dir) && !wp_mkdir_p($dir)) {
        return false;
    }

    $json = wp_json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        return false;
    }

    // 先写临时文件再改名，避免写一半被定时任务读到
    $tmp = $file . '.tmp';
    if (file_put_contents($tmp, $json) === false) {
        return false;
    }
    @chmod($tmp, 0600);

    return @rename($tmp, $file);
}

/**
 * 只露出头尾，够确认是哪把 key，又不至于泄露。
 */
function xz_ai_mask_key(string $key): string
{
    $len = strlen($key);
    if ($len === 0) {
        return '';
    }
    if ($len <= 8) {
        return str_repeat('•', $len);
    }
    return substr($key, 0, 4) . str_repeat('•', 6) . substr($key, -4);
}

function xz_ai_safe_redirect(bool $ok, string $msg): void
{
    wp_safe_redirect(add_query_arg(
        [
            'page'   => 'xz-ai-settings',
            'xz_ok'  => $ok ? 1 : 0,
            'xz_msg' => $msg,
        ],
        admin_url('options-general.php')
    ));
    exit;
}

/**
 * 用一次最小请求确认三件事：地址通、key 对、模型存在。
 * 走 wp_remote_post，不需要 PHP 执行外部命令。
 */
function xz_ai_test_connection(array $cfg): array
{
    $base  = rtrim((string) ($cfg['base_url'] ?? ''), '/');
    $model = (string) ($cfg['model'] ?? '');
    $key   = (string) ($cfg['api_key'] ?? '');

    if ($base === '' || $model === '' || $key === '') {
        return [false, 'base_url、模型名、API Key 三项都填了才能测试。'];
    }

    $scheme = strtolower((string) parse_url($base, PHP_URL_SCHEME));
    if (!in_array($scheme, ['http', 'https'], true)) {
        return [false, 'base_url 必须以 http:// 或 https:// 开头。'];
    }

    $resp = wp_remote_post($base . '/chat/completions', [
        'timeout' => 25,
        'headers' => [
            'Authorization' => 'Bearer ' . $key,
            'Content-Type'  => 'application/json',
        ],
        'body'    => wp_json_encode([
            'model'       => $model,
            'messages'    => [['role' => 'user', 'content' => 'ping']],
            'max_tokens'  => 1,
            'temperature' => 0,
        ]),
    ]);

    if (is_wp_error($resp)) {
        return [false, '连不上：' . $resp->get_error_message()];
    }

    $code = (int) wp_remote_retrieve_response_code($resp);
    $body = (string) wp_remote_retrieve_body($resp);
    $json = json_decode($body, true);

    if (200 === $code && is_array($json) && isset($json['choices'])) {
        return [true, '连接成功，' . $model . ' 正常返回。'];
    }

    $detail = '';
    if (is_array($json)) {
        if (isset($json['error']['message'])) {
            $detail = (string) $json['error']['message'];
        } elseif (isset($json['message'])) {
            $detail = (string) $json['message'];
        }
    }
    if ($detail === '') {
        $detail = wp_strip_all_tags(function_exists('mb_substr') ? mb_substr($body, 0, 160) : substr($body, 0, 160));
    }

    if (401 === $code || 403 === $code) {
        $hint = '鉴权失败：API Key 不对，或这把 Key 没有该模型的权限。';
    } elseif (404 === $code) {
        $hint = '404：base_url 多半少写或多写了结尾（要写到 /v1、/compatible-mode/v1、/api/paas/v4 这一级），也可能是模型名不存在。';
    } elseif (429 === $code) {
        $hint = '被限流或额度用尽。';
    } elseif ($code >= 500) {
        $hint = '对方服务端错误（' . $code . '），稍后再试。';
    } else {
        $hint = '返回 HTTP ' . $code . '。';
    }

    return [false, $hint . ($detail !== '' ? ' 详情：' . $detail : '')];
}

/**
 * 统一处理保存与测试：同一个表单，两个提交按钮。
 */
function xz_ai_handle_form(): void
{
    if (!current_user_can('manage_options')) {
        wp_die('权限不足');
    }
    check_admin_referer('xz_ai_form');

    $old  = xz_ai_config_get();
    $mode = isset($_POST['xz_do']) ? sanitize_key(wp_unslash($_POST['xz_do'])) : 'save';

    $cfg = [
        'enabled'     => !empty($_POST['enabled']),
        'base_url'    => esc_url_raw(trim((string) wp_unslash($_POST['base_url'] ?? ''))),
        'model'       => sanitize_text_field(wp_unslash($_POST['model'] ?? '')),
        'temperature' => min(2, max(0, (float) ($_POST['temperature'] ?? 0.3))),
        'max_tokens'  => min(4000, max(128, (int) ($_POST['max_tokens'] ?? 900))),
        'timeout'     => min(120, max(5, (int) ($_POST['timeout'] ?? 40))),
    ];

    // key：留空表示沿用已保存的；勾了"清空"才真的删掉
    $submitted = trim((string) wp_unslash($_POST['api_key'] ?? ''));
    if (!empty($_POST['clear_key'])) {
        $cfg['api_key'] = '';
    } elseif ($submitted !== '') {
        // key 里不该有空白和控制字符，其余字符原样保留
        $cfg['api_key'] = preg_replace('/[\x00-\x20\x7f]/', '', $submitted);
    } else {
        $cfg['api_key'] = (string) ($old['api_key'] ?? '');
    }

    if ('test' === $mode) {
        list($ok, $msg) = xz_ai_test_connection($cfg);
        xz_ai_safe_redirect($ok, $msg);
    }

    if ($cfg['base_url'] === '' || $cfg['model'] === '') {
        xz_ai_safe_redirect(false, 'base_url 和模型名不能为空。');
    }

    if (!xz_ai_config_put($cfg)) {
        xz_ai_safe_redirect(false, '写入失败：' . xz_ai_config_file() . ' 不可写，检查目录权限。');
    }

    if ($cfg['enabled'] && $cfg['api_key'] === '') {
        xz_ai_safe_redirect(true, '已保存。但还没填 API Key，AI 总结暂时不会生效。');
    }

    xz_ai_safe_redirect(true, $cfg['enabled'] ? '已保存，下次数据刷新时生效。' : '已保存，AI 总结处于关闭状态。');
}

/**
 * 菜单：设置 → XZ AI 总结
 */
function xz_ai_admin_menu(): void
{
    $hook = add_options_page(
        'XZ AI 总结',
        'XZ AI 总结',
        'manage_options',
        'xz-ai-settings',
        'xz_ai_render_page'
    );
    if ($hook) {
        add_action('admin_print_styles-' . $hook, 'xz_analytics_admin_assets');
    }
}

/**
 * 设置页。
 */
function xz_ai_render_page(): void
{
    if (!current_user_can('manage_options')) {
        wp_die('权限不足');
    }

    $cfg   = xz_ai_config_get();
    $key   = (string) ($cfg['api_key'] ?? '');
    $data  = xz_analytics_get_data();
    $ai    = is_array($data) && isset($data['ai_summary']) ? $data['ai_summary'] : null;
    $file  = xz_ai_config_file();
    $is_ok = isset($_GET['xz_ok']) ? (bool) (int) $_GET['xz_ok'] : null;
    $msg   = isset($_GET['xz_msg']) ? sanitize_text_field(wp_unslash($_GET['xz_msg'])) : '';

    $enabled = !empty($cfg['enabled']) && $key !== '';
    ?>
    <div class="wrap xz-ai-wrap">
        <h1 class="wp-heading-inline">XZ AI 总结</h1>
        <a href="<?php echo esc_url(admin_url('index.php?page=xz-analytics')); ?>" class="page-title-action">返回内容数据</a>
        <hr class="wp-header-end">

        <?php if ($msg !== '') : ?>
            <div class="notice <?php echo $is_ok ? 'notice-success' : 'notice-error'; ?> is-dismissible">
                <p><?php echo esc_html($msg); ?></p>
            </div>
        <?php endif; ?>

        <div class="xz-ai-status <?php echo $enabled ? 'is-on' : 'is-off'; ?>">
            <div class="xz-ai-status__dot"></div>
            <div>
                <strong><?php echo $enabled ? '已启用' : '未启用'; ?></strong>
                <span>
                    <?php
                    if ($enabled) {
                        echo '面板底部「这份数据说明了什么」由大模型生成';
                    } elseif ($key === '' && !empty($cfg['enabled'])) {
                        echo '已打开开关，但还没填 API Key，仍走规则模板';
                    } else {
                        echo '面板底部走内置规则模板，不调用任何模型';
                    }
                    ?>
                </span>
            </div>
            <div class="xz-ai-status__time">
                <?php
                if (is_array($ai) && !empty($ai['generated_at'])) {
                    echo '上次生成：' . esc_html($ai['generated_at']);
                    if (!empty($ai['model'])) {
                        echo ' · ' . esc_html($ai['model']);
                    }
                } else {
                    echo '还没有 AI 生成记录';
                }
                ?>
            </div>
        </div>

        <?php if (is_array($ai) && !empty($ai['error'])) : ?>
            <div class="notice notice-warning">
                <p>上次调用失败，已自动回落到规则模板。失败原因：
                    <code><?php echo esc_html($ai['error']); ?></code>
                </p>
            </div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="xz_ai_save">
            <?php wp_nonce_field('xz_ai_form'); ?>

            <h2>接口</h2>
            <p class="xz-ai-lead">
                支持任何<strong>兼容 OpenAI 接口格式</strong>的 API。目前可直接接入的有：
                OpenAI、DeepSeek、通义千问（阿里云百炼）、智谱 GLM、Kimi、豆包（火山方舟）、
                文心一言（百度千帆）、腾讯混元、MiniMax、硅基流动，以及本地部署的
                Ollama / vLLM / LM Studio。换一家只需改下面三项。
            </p>
            <p class="xz-ai-lead" style="margin-top:0">
                常见模型名如 <code>deepseek-chat</code>、<code>qwen-plus</code>、
                <code>glm-4-plus</code>、<code>moonshot-v1-8k</code>、<code>gpt-4o-mini</code>——
                选完服务商会自动填好，也可以手填任意兼容模型。
            </p>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">服务商</th>
                    <td>
                        <select id="xz-ai-preset">
                            <?php foreach (xz_ai_presets() as $p) : ?>
                                <option value="<?php echo esc_attr($p['k']); ?>"
                                        data-base="<?php echo esc_attr($p['b']); ?>"
                                        data-model="<?php echo esc_attr($p['m']); ?>">
                                    <?php echo esc_html($p['n']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">只是帮你把地址和模型名填好，选完仍可手动改。</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="xz-ai-base">base_url</label></th>
                    <td>
                        <input id="xz-ai-base" name="base_url" type="text" class="regular-text code"
                               value="<?php echo esc_attr((string) ($cfg['base_url'] ?? '')); ?>"
                               placeholder="https://api.deepseek.com/v1">
                        <p class="description">
                            写到版本层为止：OpenAI 是 <code>/v1</code>，千问是
                            <code>/compatible-mode/v1</code>，智谱是 <code>/api/paas/v4</code>。
                            多一层或少一层都会 404。
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="xz-ai-model">模型名</label></th>
                    <td>
                        <input id="xz-ai-model" name="model" type="text" class="regular-text code"
                               value="<?php echo esc_attr((string) ($cfg['model'] ?? '')); ?>"
                               placeholder="deepseek-chat">
                        <p class="description">以厂商控制台的模型列表为准，可直接填写任意兼容模型。</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="xz-ai-key">API Key</label></th>
                    <td>
                        <input id="xz-ai-key" name="api_key" type="password" class="regular-text code"
                               autocomplete="new-password" spellcheck="false"
                               value=""
                               placeholder="<?php echo $key !== '' ? esc_attr(xz_ai_mask_key($key)) : 'sk-...'; ?>">
                        <p class="description">
                            <?php if ($key !== '') : ?>
                                已保存：<code><?php echo esc_html(xz_ai_mask_key($key)); ?></code>。
                                留空表示不修改；要换就整条覆盖。
                            <?php else : ?>
                                只保存在服务器上，页面上不回显明文。
                            <?php endif; ?>
                        </p>
                        <?php if ($key !== '') : ?>
                            <label class="xz-ai-clear">
                                <input type="checkbox" name="clear_key" value="1"> 清空已保存的 Key
                            </label>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">启用</th>
                    <td>
                        <label>
                            <input type="checkbox" name="enabled" value="1"
                                <?php checked(!empty($cfg['enabled'])); ?>>
                            每天数据刷新后，让模型重写「这份数据说明了什么」
                        </label>
                        <p class="description">
                            关掉不影响面板，会一直用内置规则模板。
                        </p>
                    </td>
                </tr>
            </table>

            <details class="xz-ai-adv">
                <summary>高级参数</summary>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">temperature</th>
                        <td>
                            <input name="temperature" type="number" step="0.1" min="0" max="2" class="small-text"
                                   value="<?php echo esc_attr((string) ($cfg['temperature'] ?? 0.3)); ?>">
                            <p class="description">越低越稳定。这里做数据分析，建议 0.2–0.4。</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">max_tokens</th>
                        <td>
                            <input name="max_tokens" type="number" min="128" max="4000" class="small-text"
                                   value="<?php echo esc_attr((string) ($cfg['max_tokens'] ?? 900)); ?>">
                            <p class="description">结论输出上限，900 够写 4–6 条。</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">超时（秒）</th>
                        <td>
                            <input name="timeout" type="number" min="5" max="120" class="small-text"
                                   value="<?php echo esc_attr((string) ($cfg['timeout'] ?? 40)); ?>">
                        </td>
                    </tr>
                </table>
            </details>

            <p class="submit">
                <button type="submit" name="xz_do" value="save" class="button button-primary">保存设置</button>
                <button type="submit" name="xz_do" value="test" class="button">测试连接</button>
                <span class="xz-ai-hint">测试会真的发一次极短请求（约 1 个 token），会产生可忽略的费用。</span>
            </p>
        </form>

        <h2>什么时候生成</h2>
        <p>
            总结跟着<strong>数据刷新</strong>走：每天凌晨 3:30 重新分析日志后自动生成一次，
            结果存进 <code>stats.json</code>，面板打开时直接读取。
            这样同一份数据只会算一次，措辞稳定，也不会因为浏览器关掉而中断。
        </p>
        <p class="xz-ai-note">
            服务器出于安全考虑禁用了 PHP 执行外部命令的能力（<code>exec</code>、
            <code>shell_exec</code>、<code>proc_open</code>），这是防止恶意代码执行的常规加固手段。
            所以本插件不在后台触发脚本——要立刻重算，请在服务器上执行：
        </p>
        <pre class="xz-ai-code"><?php echo esc_html('cd ' . ABSPATH . 'wp-content/plugins/xz-analytics/bin && ./refresh.sh'); ?></pre>

        <h2>配置文件</h2>
        <p>
            存在 <code><?php echo esc_html($file); ?></code>，
            权限 0600，位于 web 根目录之外，公网无法下载。
            <?php if (!is_readable($file)) : ?>
                <span style="color:#b32d2e">当前文件还不存在，保存后会自动创建。</span>
            <?php endif; ?>
        </p>
    </div>

    <script>
    (function () {
        var sel = document.getElementById('xz-ai-preset');
        var base = document.getElementById('xz-ai-base');
        var model = document.getElementById('xz-ai-model');
        if (!sel || !base || !model) return;
        sel.addEventListener('change', function () {
            var o = sel.options[sel.selectedIndex];
            var b = o.getAttribute('data-base') || '';
            var m = o.getAttribute('data-model') || '';
            if (b) base.value = b;
            if (m) model.value = m;
        });
        // 首次进来还没配置时，先按默认预设填好，省得点保存报"不能为空"
        if (!base.value && !model.value) {
            sel.dispatchEvent(new Event('change'));
        }
    })();
    </script>
    <?php
}

add_action('admin_menu', 'xz_ai_admin_menu');
add_action('admin_post_xz_ai_save', 'xz_ai_handle_form');
