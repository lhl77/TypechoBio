<?php

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

$typechoBioLoadStart = microtime(true);

/* 读取 Typecho 基础对象 */
$options = Typecho_Widget::widget('Widget_Options');
$user = TypechoBio_Plugin::getCurrentUser();
$db = TypechoBio_Plugin::getDb();
$prefix = $db->getPrefix();

/* 读取请求域名与插件配置 */
$settings = TypechoBio_Plugin::getSettings($options);
$currentHost = TypechoBio_Plugin::getHostFromServer();

$selectedTheme = TypechoBio_Plugin::getSelectedTheme($settings);
$themeConfigMap = TypechoBio_Plugin::getThemeConfigValues($settings);
$selectedThemeConfig = isset($themeConfigMap[$selectedTheme]) && is_array($themeConfigMap[$selectedTheme])
    ? $themeConfigMap[$selectedTheme]
    : array();

$bioDebug = isset($_GET['bio_debug']) && (string) $_GET['bio_debug'] === '1';
$bioDebugPayload = array();
if ($bioDebug) {
    $apiTheme = '';
    $apiThemeJsonRaw = '';
    $apiError = '';

    try {
        $apiSettings = $options->plugin('TypechoBio');
        if (is_object($apiSettings) && method_exists($apiSettings, 'offsetGet')) {
            $apiTheme = (string) $apiSettings->offsetGet('bio_theme');
            $apiThemeJsonRaw = (string) $apiSettings->offsetGet('bio_theme_configs_json');
        } elseif (is_object($apiSettings)) {
            $apiTheme = isset($apiSettings->bio_theme) ? (string) $apiSettings->bio_theme : '';
            $apiThemeJsonRaw = isset($apiSettings->bio_theme_configs_json) ? (string) $apiSettings->bio_theme_configs_json : '';
        }
    } catch (Throwable $e) {
        $apiError = $e->getMessage();
    }

    $mergedTheme = isset($settings->bio_theme) ? (string) $settings->bio_theme : '';
    $mergedThemeJsonRaw = isset($settings->bio_theme_configs_json) ? (string) $settings->bio_theme_configs_json : '';

    $bioDebugPayload = array(
        'request_host' => $currentHost,
        'api_theme' => $apiTheme,
        'api_theme_json_raw' => $apiThemeJsonRaw,
        'api_error' => $apiError,
        'merged_theme' => $mergedTheme,
        'merged_theme_json_raw' => $mergedThemeJsonRaw,
        'selected_theme' => $selectedTheme,
        'theme_config_map_keys' => array_keys($themeConfigMap),
        'selected_theme_config' => $selectedThemeConfig,
    );

    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($bioDebugPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$requestScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$requestHost = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
$requestOrigin = ($requestHost !== '') ? ($requestScheme . '://' . $requestHost) : '';
$profileApi = ($requestOrigin !== '')
    ? $requestOrigin . Typecho_Common::url('action/typecho-bio?do=profile', '/')
    : Typecho_Common::url('action/typecho-bio?do=profile', $options->index);
$commentApi = ($requestOrigin !== '')
    ? $requestOrigin . Typecho_Common::url('action/typecho-bio?do=comment', '/')
    : Typecho_Common::url('action/typecho-bio?do=comment', $options->index);
$assetBasePrefix = ($requestOrigin !== '') ? ($requestOrigin . '/') : $options->rootUrl;
$pluginAssetBase = Typecho_Common::url('usr/plugins/TypechoBio/', $assetBasePrefix);
$themeAssetBase = Typecho_Common::url('usr/plugins/TypechoBio/templates/' . $selectedTheme . '/', $assetBasePrefix);
$siteIcon = isset($settings->bio_site_icon) ? trim((string) $settings->bio_site_icon) : '';
$headPreMeta = isset($settings->bio_head_pre_meta) ? (string) $settings->bio_head_pre_meta : '';
$headCustomCss = isset($settings->bio_head_css) ? (string) $settings->bio_head_css : '';
$headCustomJs = isset($settings->bio_head_js) ? (string) $settings->bio_head_js : '';

/* 主题文件选择逻辑 */
$themeFile = __DIR__ . DIRECTORY_SEPARATOR . $selectedTheme . DIRECTORY_SEPARATOR . 'home.php';

$commentTarget = TypechoBio_Plugin::getCommentTargetPost($settings, $options);
$commentList = array();
if (is_array($commentTarget) && !empty($commentTarget['cid'])) {
    $commentList = $db->fetchAll(
        $db->select('coid', 'parent', 'author', 'text', 'created')
            ->from($prefix . 'comments')
            ->where('cid = ?', (int) $commentTarget['cid'])
            ->where('type = ?', 'comment')
            ->where('status = ?', 'approved')
            ->order($prefix . 'comments.created', Typecho_Db::SORT_ASC)
            ->limit(30)
    );
}

/* 获取最近文章（仅示例，避免加载 Widget_Archive） */
$posts = $db->fetchAll(
    $db->select('cid', 'title', 'created')
        ->from($prefix . 'contents')
        ->where('type = ?', 'post')
        ->where('status = ?', 'publish')
        ->order($prefix . 'contents.created', Typecho_Db::SORT_DESC)
        ->limit(10)
);

/* 共享模板变量 */
$typechoBioContext = array(
    'host' => $currentHost,
    'siteTitle' => (string) $options->title,
    'siteUrl' => (string) $options->siteUrl,
    'adminUrl' => (string) $options->adminUrl,
    'userLogged' => $user->hasLogin(),
    'userName' => $user->hasLogin() ? (string) $user->name : '',
    'userGroup' => $user->hasLogin() ? (string) $user->group : '',
    'profileApi' => $profileApi,
    'commentApi' => $commentApi,
    'posts' => $posts,
    'themeKey' => $selectedTheme,
    'themeConfig' => $selectedThemeConfig,
    'pluginAssetBase' => $pluginAssetBase,
    'themeAssetBase' => $themeAssetBase,
    'siteIcon' => $siteIcon,
    'headPreMeta' => $headPreMeta,
    'headCustomCss' => $headCustomCss,
    'headCustomJs' => $headCustomJs,
    'pluginName' => 'TypechoBio',
    'pluginVersion' => TypechoBio_Plugin::VERSION,
    'pluginLink' => 'https://github.com/lhl77/TypechoBio',
    'loadStart' => $typechoBioLoadStart,
    'commentTarget' => $commentTarget,
    'commentList' => $commentList,
    'commentResult' => isset($_GET['bio_comment']) ? (string) $_GET['bio_comment'] : '',
    'commentMessage' => isset($_GET['bio_comment_msg']) ? (string) $_GET['bio_comment_msg'] : '',
);

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('X-TypechoBio-Trace: ' . sprintf('%.6f', microtime(true)));
}

if (is_file($themeFile)) {
    require $themeFile;
    return;
}

header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?php echo htmlspecialchars($typechoBioContext['siteTitle'], ENT_QUOTES, 'UTF-8'); ?> - TypechoBio</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; margin: 24px; color: #111827; }
        .card { border: 1px solid #e5e7eb; border-radius: 14px; padding: 16px; margin-bottom: 16px; }
        .muted { color: #6b7280; }
        .post-list li { margin: 8px 0; }
    </style>
</head>
<body>
    <div class="card">
        <h2>TypechoBio 独立入口</h2>
        <p class="muted">当前域名：<?php echo htmlspecialchars($typechoBioContext['host'], ENT_QUOTES, 'UTF-8'); ?></p>
        <p class="muted">此页面未走 Typecho 前台主题渲染，仅复用数据库/用户系统。</p>
    </div>

    <div class="card">
        <h3>数据库访问示例（最近 10 篇文章）</h3>
        <ul class="post-list">
            <?php foreach ($typechoBioContext['posts'] as $item): ?>
                <li>
                    #<?php echo (int) $item['cid']; ?>
                    <?php echo htmlspecialchars((string) $item['title'], ENT_QUOTES, 'UTF-8'); ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php require dirname(__DIR__) . '/views/console-brand.php'; ?>
</body>
</html>
