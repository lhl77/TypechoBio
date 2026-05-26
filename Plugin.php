<?php

/**
 * TypechoBio 多域名独立页面（个人主页）插件，复用Typecho的评论系统。
 *
 * @package TypechoBio
 * @author lhl
 * @version 1.0.0
 * @link https://github.com/lhl77/TypechoBio
 */
class TypechoBio_Plugin implements Typecho_Plugin_Interface
{
    const FIXED_ENTRY = 'templates/index.php';
    const THEME_CONFIG_FILE = 'theme.json';
    const VERSION = '1.0.0';
    const THEME_CATALOG_URL = 'https://github.com/lhl77/Typecho-Raw-Nontification/blob/main/TypechoBio/themes.json';
    const GH1_MIRROR_PREFIX = 'https://gh1.lhl.one/';
    const THEME_CATALOG_SCHEMA_VERSION = 1;

    public static function activate()
    {
        Typecho_Plugin::factory('index.php')->begin = array('TypechoBio_Plugin', 'begin');
        Typecho_Plugin::factory('admin/common.php')->begin = array('TypechoBio_Plugin', 'begin');
        Helper::addAction('typecho-bio', 'TypechoBio_Action');
        self::ensurePluginConfigExists();
        return _t('TypechoBio 已启用');
    }

    public static function deactivate()
    {
        Helper::removeAction('typecho-bio');
        return _t('TypechoBio 已禁用');
    }

    public static function config(Typecho_Widget_Helper_Form $form)
    {
        $settings = self::getSettings(Typecho_Widget::widget('Widget_Options'));
        $rawSettings = self::settingsToArray($settings);
        $themes = self::discoverThemes();
        $syncedThemeSettings = self::synchronizeThemeConfigSettings($rawSettings, $themes);
        if (!empty($syncedThemeSettings['changed'])) {
            Helper::configPlugin('TypechoBio', $syncedThemeSettings['settings']);
        }
        $settings = (object) array_merge($rawSettings, $syncedThemeSettings['settings']);

        $hosts = new Typecho_Widget_Helper_Form_Element_Textarea(
            'bio_hosts',
            null,
            "bio.example.com",
            _t('独立域名列表'),
            _t('填写要进入独立页面的域名。支持逗号或换行分隔；支持通配前缀，例如 *.example.com。')
        );
        $form->addInput($hosts->addRule('required', _t('请至少填写一个域名')));

        $interceptAdmin = new Typecho_Widget_Helper_Form_Element_Checkbox(
            'bio_intercept_admin',
            array('1' => _t('拦截后台管理路径')),
            array(),
            _t('后台路径拦截'),
            _t('默认不拦截后台路径。')
        );
        $form->addInput($interceptAdmin);

        $commentCid = new Typecho_Widget_Helper_Form_Element_Text(
            'bio_comment_cid',
            null,
            isset($settings->bio_comment_cid) ? (string) $settings->bio_comment_cid : '0',
            _t('评论文章 ID'),
            _t('用于欢迎页评论区归集的文章 CID。填 0 则不启用。')
        );
        $form->addInput($commentCid);

        self::appendThemeMarketplaceBlock($form, $settings, $themes);

        $themeOptions = array();
        foreach ($themes as $theme) {
            $themeOptions[$theme['dir']] = self::getThemeOptionLabel($theme);
        }
        if (empty($themeOptions)) {
            $themeOptions['default'] = 'default';
        }
        $themeKeys = array_keys($themeOptions);
        $defaultTheme = isset($themeKeys[0]) ? $themeKeys[0] : 'default';
        $selectedTheme = self::getSelectedTheme($settings);
        if (!isset($themeOptions[$selectedTheme])) {
            $selectedTheme = $defaultTheme;
        }

        $themeSelect = new Typecho_Widget_Helper_Form_Element_Select(
            'bio_theme',
            $themeOptions,
            $selectedTheme,
            _t('启用主题'),
            _t('从 templates 目录自动发现主题目录，并使用该主题的 home.php 进行渲染。')
        );
        $themeSelect->setAttribute('data-bio-theme-selector', '1');
        $form->addInput($themeSelect);

        $themeValues = self::getThemeConfigValues($settings);
        self::appendThemeConfigInputs($form, $themes, $themeValues);

        $jsonDefault = self::buildThemeConfigJsonDefault($themes);
        $jsonCurrent = json_encode($themeValues, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($jsonCurrent) || trim($jsonCurrent) === '') {
            $jsonCurrent = isset($settings->bio_theme_configs_json) ? trim((string) $settings->bio_theme_configs_json) : '';
        }
        if ($jsonCurrent === '') {
            $jsonCurrent = $jsonDefault;
        }
        $themeConfigJson = new Typecho_Widget_Helper_Form_Element_Textarea(
            'bio_theme_configs_json',
            null,
            $jsonCurrent,
            _t('主题配置 JSON'),
            _t('主题字段会在本页以常规配置项展示，提交时自动同步到本 JSON。') . self::renderThemeConfigSyncScript()
        );
        $form->addInput($themeConfigJson);

        $siteIcon = new Typecho_Widget_Helper_Form_Element_Text(
            'bio_site_icon',
            null,
            isset($settings->bio_site_icon) ? (string) $settings->bio_site_icon : '',
            _t('自定义网站 ICO'),
            _t('填写 ico/png/svg 图标链接，将作为主题页面 favicon。留空则使用各主题默认图标。')
        );
        $form->addInput($siteIcon);

        $headPreMeta = new Typecho_Widget_Helper_Form_Element_Textarea(
            'bio_head_pre_meta',
            null,
            isset($settings->bio_head_pre_meta) ? (string) $settings->bio_head_pre_meta : '',
            _t('Head 注入（Meta 前）'),
            _t('原样插入到页面 &lt;head&gt; 内，并位于默认 meta 标签之前。可填写自定义 meta/link/script 片段。')
        );
        $form->addInput($headPreMeta);

        $headCss = new Typecho_Widget_Helper_Form_Element_Textarea(
            'bio_head_css',
            null,
            isset($settings->bio_head_css) ? (string) $settings->bio_head_css : '',
            _t('自定义 CSS 注入'),
            _t('填写 CSS 代码即可，无需 &lt;style&gt; 标签。')
        );
        $form->addInput($headCss);

        $headJs = new Typecho_Widget_Helper_Form_Element_Textarea(
            'bio_head_js',
            null,
            isset($settings->bio_head_js) ? (string) $settings->bio_head_js : '',
            _t('自定义 JS 注入'),
            _t('填写 JavaScript 代码即可，无需 &lt;script&gt; 标签。')
        );
        $form->addInput($headJs);

        self::appendStaleThemeConfigPlaceholders($form, $rawSettings, $themes);
    }

    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {
    }

    public static function configHandle(array $settings, $isInit)
    {
        $themes = self::discoverThemes();

        $syncResult = self::synchronizeThemeConfigSettings($settings, $themes);
        Helper::configPlugin('TypechoBio', $syncResult['settings']);
        return true;
    }

    public static function begin()
    {
        self::ensurePluginConfigExists();

        $settings = self::getSettings();

        if (isset($_GET['bio_debug']) && (string) $_GET['bio_debug'] === '1') {
            $host = self::getHostFromServer();
            $hostRules = self::parseHostList(isset($settings->bio_hosts) ? $settings->bio_hosts : '');
            $entryFile = self::resolveEntryFile($settings);

            $opcacheResetRequested = isset($_GET['bio_opcache_reset']) && (string) $_GET['bio_opcache_reset'] === '1';
            $opcacheResetResult = null;
            if ($opcacheResetRequested && function_exists('opcache_reset')) {
                $opcacheResetResult = opcache_reset();
            }

            $apiTheme = '';
            $apiThemeJsonRaw = '';
            $apiError = '';
            try {
                $options = Typecho_Widget::widget('Widget_Options');
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
            $themeConfigMap = self::getThemeConfigValues($settings);
            $selectedThemeConfig = isset($themeConfigMap[$mergedTheme]) && is_array($themeConfigMap[$mergedTheme])
                ? $themeConfigMap[$mergedTheme]
                : array();

            $payload = array(
                'host' => $host,
                'request_uri' => isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '',
                'is_typecho_bio_action' => self::isTypechoBioActionRequest(),
                'is_admin_or_install' => self::isAdminOrInstallPath(),
                'host_rules' => $hostRules,
                'host_matches' => self::hostMatches($host, $hostRules),
                'should_intercept' => self::shouldIntercept($settings),
                'resolved_entry_file' => $entryFile,
                'resolved_entry_file_mtime' => is_file($entryFile) ? @date('c', (int) @filemtime($entryFile)) : '',
                'zyyo_home_mtime' => is_file(__DIR__ . '/templates/zyyo/home.php') ? @date('c', (int) @filemtime(__DIR__ . '/templates/zyyo/home.php')) : '',
                'opcache_reset_requested' => $opcacheResetRequested,
                'opcache_reset_result' => $opcacheResetResult,
                'merged_theme' => $mergedTheme,
                'merged_theme_json_raw' => $mergedThemeJsonRaw,
                'selected_theme_config' => $selectedThemeConfig,
                'api_theme' => $apiTheme,
                'api_theme_json_raw' => $apiThemeJsonRaw,
                'api_error' => $apiError,
            );

            if (!headers_sent()) {
                header('Content-Type: application/json; charset=UTF-8');
            }
            echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        if (!self::shouldIntercept($settings)) {
            return;
        }

        self::serveInterceptedThemeApi($settings);
        self::serveInterceptedThemeAsset($settings);

        $entryFile = self::resolveEntryFile($settings);

        if (!is_file($entryFile)) {
            if (!headers_sent()) {
                header('HTTP/1.1 500 Internal Server Error');
                header('Content-Type: text/plain; charset=UTF-8');
            }
            echo 'TypechoBio entry file not found: ' . htmlspecialchars($entryFile, ENT_QUOTES, 'UTF-8');
            exit;
        }

        if (!defined('TYPECHO_BIO_REQUEST')) {
            define('TYPECHO_BIO_REQUEST', true);
        }

        if (!headers_sent()) {
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Expires: 0');
        }

        require $entryFile;
        exit;
    }

    public static function serveInterceptedThemeAsset($settings = null)
    {
        if ($settings === null) {
            $settings = self::getSettings();
        }

        $requestUri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        $requestPath = parse_url($requestUri, PHP_URL_PATH);
        $requestPath = self::normalizeRequestPath($requestPath, false);

        if ($requestPath === '' || $requestPath === '/') {
            return;
        }

        $theme = self::getSelectedTheme($settings);
        $themeRoot = realpath(__DIR__ . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . $theme);
        if ($themeRoot === false || !is_dir($themeRoot)) {
            return;
        }

        $candidates = array();
        $candidates[] = $themeRoot . $requestPath;

        // dmego and similar themes may request root-level aliases such as /assets/json/*.js
        if (strpos($requestPath, '/assets/') === 0 || in_array($requestPath, array('/favicon.ico', '/apple-touch-icon.png', '/robots.txt'), true)) {
            $candidates[] = $themeRoot . $requestPath;
        }

        foreach ($candidates as $candidate) {
            $real = realpath($candidate);
            if ($real === false || !is_file($real)) {
                continue;
            }

            if (strpos($real, $themeRoot) !== 0) {
                continue;
            }

            self::outputStaticFile($real);
            exit;
        }
    }

    public static function serveInterceptedThemeApi($settings = null)
    {
        if ($settings === null) {
            $settings = self::getSettings();
        }

        $requestUri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        $requestPath = parse_url($requestUri, PHP_URL_PATH);
        $requestPath = self::normalizeRequestPath($requestPath);

        if ($requestPath === '' || $requestPath === '/') {
            return;
        }

        if (self::getSelectedTheme($settings) !== 'imsyy') {
            return;
        }

        if ($requestPath === '/__typecho_bio__/imsyy/song') {
            self::serveImsyySongProxy($settings);
        }

        if ($requestPath === '/__typecho_bio__/imsyy/hitokoto') {
            self::serveImsyyHitokotoProxy($settings);
        }
    }

    public static function serveImsyySongProxy($settings)
    {
        $config = self::getThemeConfigForDir($settings, 'imsyy');
        $upstream = self::sanitizeImsyyProxyUpstream(
            self::getThemeConfigStringValue($config, 'song_api', 'https://api-meting.imsyy.top/api'),
            'https://api-meting.imsyy.top/api',
            '/__typecho_bio__/imsyy/song'
        );

        $server = trim(isset($_GET['server']) ? (string) $_GET['server'] : '');
        $type = trim(isset($_GET['type']) ? (string) $_GET['type'] : '');
        $id = trim(isset($_GET['id']) ? (string) $_GET['id'] : '');

        if ($server === '' || $type === '' || $id === '') {
            self::sendJsonResponse(array(
                'ok' => false,
                'message' => 'missing music query parameters',
            ), 400);
        }

        $url = self::appendUrlQuery($upstream, array(
            'server' => $server,
            'type' => $type,
            'id' => $id,
        ));

        $payload = self::fetchRemoteJson($url);
        if (!is_array($payload) || empty($payload) || !isset($payload[0]) || !is_array($payload[0]) || !isset($payload[0]['url'])) {
            self::sendJsonResponse(self::buildImsyySongFallbackList());
        }

        self::sendJsonResponse($payload);
    }

    public static function serveImsyyHitokotoProxy($settings)
    {
        $config = self::getThemeConfigForDir($settings, 'imsyy');
        $upstream = self::sanitizeImsyyProxyUpstream(
            self::getThemeConfigStringValue($config, 'hitokoto_api', 'https://v1.hitokoto.cn'),
            'https://v1.hitokoto.cn',
            '/__typecho_bio__/imsyy/hitokoto'
        );

        $payload = self::fetchRemoteJson($upstream);
        if (!is_array($payload) || trim((string) (isset($payload['hitokoto']) ? $payload['hitokoto'] : '')) === '') {
            self::sendJsonResponse(self::buildImsyyHitokotoFallbackPayload($config));
        }

        self::sendJsonResponse($payload);
    }

    public static function buildImsyySongFallbackList()
    {
        return array(
            array(
                'name' => '音乐服务暂不可用',
                'artist' => 'TypechoBio',
                'url' => '',
                'cover' => '',
                'lrc' => '当前音乐接口不可用，请稍后再试或更换 song_api。',
            ),
        );
    }

    public static function buildImsyyHitokotoFallbackPayload(array $config)
    {
        return array(
            'hitokoto' => self::getThemeConfigStringValue($config, 'hitokoto_fallback_text', '这里应该显示一句话'),
            'from' => self::getThemeConfigStringValue($config, 'hitokoto_fallback_from', '無名'),
        );
    }

    public static function getThemeConfigForDir($settings, $dir)
    {
        $themeConfigMap = self::getThemeConfigValues($settings);
        if (!isset($themeConfigMap[$dir]) || !is_array($themeConfigMap[$dir])) {
            return array();
        }

        return $themeConfigMap[$dir];
    }

    public static function getThemeConfigStringValue(array $config, $key, $default = '')
    {
        if (!array_key_exists($key, $config)) {
            return $default;
        }

        $value = $config[$key];
        if (is_array($value)) {
            $value = reset($value);
        }

        $value = trim((string) $value);
        return $value !== '' ? $value : $default;
    }

    public static function sanitizeImsyyProxyUpstream($upstream, $fallback, $localPath)
    {
        $upstream = trim((string) $upstream);
        if ($upstream === '') {
            return $fallback;
        }

        $upstreamPath = parse_url($upstream, PHP_URL_PATH);
        $upstreamPath = self::normalizeRequestPath($upstreamPath);
        if ($upstreamPath === $localPath) {
            return $fallback;
        }

        return $upstream;
    }

    public static function appendUrlQuery($url, array $params)
    {
        $params = array_filter($params, function ($value) {
            return $value !== null && $value !== '';
        });

        if (empty($params)) {
            return $url;
        }

        $separator = strpos($url, '?') === false ? '?' : '&';
        return $url . $separator . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    public static function proxyJsonUrl($url)
    {
        $response = self::fetchRemoteUrl($url);
        $status = isset($response['status']) ? (int) $response['status'] : 0;
        $body = isset($response['body']) ? (string) $response['body'] : '';

        if ($status < 200 || $status >= 300 || $body === '') {
            self::sendJsonResponse(array(
                'ok' => false,
                'message' => 'upstream request failed',
                'status' => $status,
            ), 502);
        }

        if (!headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Expires: 0');
        }

        echo $body;
        exit;
    }

    public static function fetchRemoteJson($url)
    {
        $response = self::fetchRemoteUrl($url);
        $status = isset($response['status']) ? (int) $response['status'] : 0;
        $body = isset($response['body']) ? (string) $response['body'] : '';

        if ($status < 200 || $status >= 300 || $body === '') {
            return null;
        }

        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : null;
    }

    public static function fetchRemoteUrl($url)
    {
        $result = array(
            'status' => 0,
            'contentType' => '',
            'body' => '',
        );

        $headers = array(
            'Accept: application/json, text/plain, */*',
            'User-Agent: TypechoBio/' . self::VERSION,
        );

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch !== false) {
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
                curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($ch, CURLOPT_ENCODING, '');

                $body = curl_exec($ch);
                if (is_string($body)) {
                    $result['body'] = $body;
                }

                $result['status'] = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
                if (is_string($contentType)) {
                    $result['contentType'] = $contentType;
                }
                curl_close($ch);

                return $result;
            }
        }

        $context = stream_context_create(array(
            'http' => array(
                'method' => 'GET',
                'timeout' => 10,
                'ignore_errors' => true,
                'header' => implode("\r\n", $headers),
            ),
        ));

        $body = @file_get_contents($url, false, $context);
        if (is_string($body)) {
            $result['body'] = $body;
        }

        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $headerLine) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $headerLine, $matches)) {
                    $result['status'] = (int) $matches[1];
                    continue;
                }

                if (stripos($headerLine, 'Content-Type:') === 0) {
                    $result['contentType'] = trim(substr($headerLine, strlen('Content-Type:')));
                }
            }
        }

        return $result;
    }

    public static function sendJsonResponse(array $payload, $status = 200)
    {
        if (!headers_sent()) {
            if ((int) $status >= 400) {
                header('HTTP/1.1 ' . (int) $status . ' Error');
            }
            header('Content-Type: application/json; charset=UTF-8');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Expires: 0');
        }

        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function outputStaticFile($file)
    {
        $mime = self::getStaticFileMimeType($file);

        if ($mime === '') {
            $mime = 'application/octet-stream';
            if (function_exists('mime_content_type')) {
                $detected = @mime_content_type($file);
                if (is_string($detected) && trim($detected) !== '') {
                    $mime = $detected;
                }
            } elseif (function_exists('finfo_open')) {
                $finfo = @finfo_open(FILEINFO_MIME_TYPE);
                if ($finfo) {
                    $detected = @finfo_file($finfo, $file);
                    @finfo_close($finfo);
                    if (is_string($detected) && trim($detected) !== '') {
                        $mime = $detected;
                    }
                }
            }
        }

        if (!headers_sent()) {
            header('Content-Type: ' . $mime);
            header('Content-Length: ' . (string) filesize($file));
            header('Cache-Control: public, max-age=300');
        }

        readfile($file);
    }

    public static function getStaticFileMimeType($file)
    {
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $mimeMap = array(
            'css' => 'text/css; charset=UTF-8',
            'js' => 'text/javascript; charset=UTF-8',
            'mjs' => 'text/javascript; charset=UTF-8',
            'json' => 'application/json; charset=UTF-8',
            'map' => 'application/json; charset=UTF-8',
            'html' => 'text/html; charset=UTF-8',
            'txt' => 'text/plain; charset=UTF-8',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'otf' => 'font/otf',
            'eot' => 'application/vnd.ms-fontobject',
            'xml' => 'application/xml; charset=UTF-8',
        );

        return isset($mimeMap[$extension]) ? $mimeMap[$extension] : '';
    }

    public static function getSettings($options = null)
    {
        if ($options === null) {
            $options = Typecho_Widget::widget('Widget_Options');
        }

        $defaults = array(
            'bio_hosts' => '',
            'bio_theme' => 'default',
            'bio_theme_configs_json' => '{}',
            'bio_site_icon' => '',
            'bio_head_pre_meta' => '',
            'bio_head_css' => '',
            'bio_head_js' => '',
            'bio_comment_cid' => '0',
            'bio_intercept_admin' => array(),
        );

        $merged = $defaults;

        try {
            $settings = $options->plugin('TypechoBio');
            if (is_object($settings)) {
                if (method_exists($settings, 'toArray')) {
                    $fromOptions = $settings->toArray();
                    if (is_array($fromOptions)) {
                        $merged = array_merge($merged, $fromOptions);
                    }
                } else {
                    $fromOptions = get_object_vars($settings);
                    if (is_array($fromOptions)) {
                        $merged = array_merge($merged, $fromOptions);
                    }
                }
            }
        } catch (Exception $e) {
        } catch (Throwable $e) {
        }

        $fromDb = self::getPluginSettingsFromDb();
        if (!empty($fromDb)) {
            $merged = array_merge($merged, $fromDb);
        }

        return (object) $merged;
    }

    public static function settingsToArray($settings)
    {
        if (is_array($settings)) {
            return $settings;
        }

        if (is_object($settings)) {
            if (method_exists($settings, 'toArray')) {
                $data = $settings->toArray();
                if (is_array($data)) {
                    return $data;
                }
            }

            $data = get_object_vars($settings);
            if (is_array($data)) {
                return $data;
            }
        }

        return array();
    }

    public static function getPluginSettingsFromDb()
    {
        try {
            $db = self::getDb();
            $row = $db->fetchRow(
                $db->select('value')
                    ->from('table.options')
                    ->where('name = ?', 'plugin:TypechoBio')
                    ->where('user = ?', 0)
                    ->limit(1)
            );

            if (!is_array($row) || !array_key_exists('value', $row)) {
                return array();
            }

            $decoded = self::decodePluginSettingsValue((string) $row['value']);
            return is_array($decoded) ? $decoded : array();
        } catch (Throwable $e) {
            return array();
        }
    }

    public static function decodePluginSettingsValue($value)
    {
        $value = (string) $value;
        if ($value === '') {
            return array();
        }

        $json = json_decode($value, true);
        if (is_array($json)) {
            return $json;
        }

        $isSerialized = strpos($value, 'a:') === 0 || $value === 'b:0;';
        if ($isSerialized) {
            $data = @unserialize($value);
            if (is_array($data)) {
                return $data;
            }
        }

        return array();
    }

    public static function ensurePluginConfigExists($options = null)
    {
        if ($options === null) {
            $options = Typecho_Widget::widget('Widget_Options');
        }

        try {
            $settings = $options->plugin('TypechoBio');
            if (is_object($settings)) {
                return;
            }
        } catch (Exception $e) {
        } catch (Throwable $e) {
        }

        Helper::configPlugin('TypechoBio', self::buildDefaultPluginSettings());
    }

    public static function buildDefaultPluginSettings()
    {
        $themes = self::discoverThemes();
        $themeOptions = array();
        foreach ($themes as $theme) {
            $themeOptions[] = $theme['dir'];
        }

        $selectedTheme = !empty($themeOptions) ? $themeOptions[0] : 'default';
        $settings = array(
            'bio_hosts' => '',
            'bio_intercept_admin' => array(),
            'bio_comment_cid' => '0',
            'bio_theme' => $selectedTheme,
            'bio_site_icon' => '',
            'bio_head_pre_meta' => '',
            'bio_head_css' => '',
            'bio_head_js' => '',
            'bio_theme_configs_json' => self::buildThemeConfigJsonDefault($themes),
        );

        foreach ($themes as $theme) {
            foreach ($theme['fields'] as $field) {
                $settings[self::buildThemeFieldName($theme['dir'], $field['key'])] = array_key_exists('default', $field)
                    ? $field['default']
                    : '';
            }
        }

        return $settings;
    }

    public static function getCurrentUser()
    {
        return Typecho_Widget::widget('Widget_User');
    }

    public static function getDb()
    {
        return Typecho_Db::get();
    }

    public static function getHostFromServer()
    {
        $host = '';

        if ($host === '' && !empty($_SERVER['HTTP_HOST'])) {
            $host = (string) $_SERVER['HTTP_HOST'];
        }

        if ($host === '' && !empty($_SERVER['SERVER_NAME'])) {
            $host = (string) $_SERVER['SERVER_NAME'];
        }

        return self::normalizeHost($host);
    }

    public static function normalizeHost($host)
    {
        $host = strtolower(trim((string) $host));
        if ($host === '') {
            return '';
        }

        $host = preg_replace('/:\d+$/', '', $host);
        $host = trim((string) $host, '.');

        if ($host === '') {
            return '';
        }

        if (!preg_match('/^[a-z0-9.-]+$/', $host)) {
            return '';
        }

        return $host;
    }

    public static function parseHostList($raw)
    {
        $items = preg_split('/[\s,]+/', (string) $raw);
        $hosts = array();

        foreach ($items as $item) {
            $item = strtolower(trim((string) $item));
            if ($item === '') {
                continue;
            }

            if (strpos($item, '*.') === 0) {
                $suffix = self::normalizeHost(substr($item, 2));
                if ($suffix !== '') {
                    $hosts[] = '*.' . $suffix;
                }
                continue;
            }

            $normalized = self::normalizeHost($item);
            if ($normalized !== '') {
                $hosts[] = $normalized;
            }
        }

        return array_values(array_unique($hosts));
    }

    public static function hostMatches($host, array $ruleHosts)
    {
        if ($host === '') {
            return false;
        }

        foreach ($ruleHosts as $rule) {
            if ($rule === $host) {
                return true;
            }

            if (strpos($rule, '*.') === 0) {
                $suffix = substr($rule, 2);
                if ($suffix !== '' && preg_match('/(^|\.)' . preg_quote($suffix, '/') . '$/', $host)) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function shouldIntercept($settings = null)
    {
        if ($settings === null) {
            $settings = self::getSettings();
        }

        // Allow Typecho action router to handle plugin actions.
        if (self::isTypechoBioActionRequest()) {
            return false;
        }

        $interceptAdmin = in_array('1', self::normalizeOptionArray(isset($settings->bio_intercept_admin) ? $settings->bio_intercept_admin : array()), true);

        if (!$interceptAdmin && self::isAdminOrInstallPath()) {
            return false;
        }

        $hostRules = self::parseHostList(isset($settings->bio_hosts) ? $settings->bio_hosts : '');
        if (empty($hostRules)) {
            return false;
        }

        $host = self::getHostFromServer();
        if ($host === '') {
            return false;
        }

        return self::hostMatches($host, $hostRules);
    }

    public static function isTypechoBioActionRequest()
    {
        $requestUri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        $path = parse_url($requestUri, PHP_URL_PATH);
        $path = strtolower((string) $path);

        if ($path !== '') {
            if (strpos($path, '/action/typecho-bio') !== false || strpos($path, '/index.php/action/typecho-bio') !== false) {
                return true;
            }
        }

        if (isset($_GET['do']) && in_array((string) $_GET['do'], array('profile', 'ping', 'comment'), true)) {
            if ($path !== '' && strpos($path, 'typecho-bio') !== false) {
                return true;
            }
        }

        return false;
    }

    public static function resolveEntryFile($settings = null)
    {
        $base = realpath(__DIR__);
        if ($base === false) {
            return __DIR__ . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'index.php';
        }

        $target = realpath($base . DIRECTORY_SEPARATOR . self::FIXED_ENTRY);
        if ($target === false) {
            return $base . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'index.php';
        }

        return $target;
    }

    public static function getThemeConfigValues($settings = null)
    {
        if ($settings === null) {
            $settings = self::getSettings();
        }

        $themes = self::discoverThemes();
        $raw = isset($settings->bio_theme_configs_json) ? (string) $settings->bio_theme_configs_json : '{}';
        $data = self::normalizeThemeConfigPayload(
            json_decode($raw, true),
            $themes,
            self::getSelectedTheme($settings)
        );
        $result = array();

        foreach ($themes as $theme) {
            $dir = $theme['dir'];
            $result[$dir] = array();
            foreach ($theme['fields'] as $field) {
                $key = $field['key'];
                $fieldName = self::buildThemeFieldName($dir, $key);
                $default = array_key_exists('default', $field) ? $field['default'] : '';
                $value = $default;
                $hasJsonValue = false;

                if (isset($data[$dir]) && is_array($data[$dir]) && array_key_exists($key, $data[$dir])) {
                    $value = $data[$dir][$key];
                    $hasJsonValue = true;
                }

                // Direct field values only act as a fallback when the unified JSON does not have the key.
                if (!$hasJsonValue && is_object($settings) && isset($settings->{$fieldName})) {
                    $directValue = $settings->{$fieldName};
                    if (is_array($directValue)) {
                        $value = !empty($directValue) ? (string) reset($directValue) : '';
                    } else {
                        $value = (string) $directValue;
                    }
                }

                if ($field['type'] === 'checkbox') {
                    $value = in_array((string) $value, array('1', 'true', 'on', 'yes'), true) ? '1' : '0';
                }

                $result[$dir][$key] = $value;
            }
        }

        return $result;
    }

    public static function normalizeThemeConfigPayload($data, array $themes, $selectedTheme = '')
    {
        if (!is_array($data)) {
            $data = array();
        }

        if (isset($data['themes']) && is_array($data['themes'])) {
            $data = $data['themes'];
        }

        $themeDirs = array();
        $fieldKeysByTheme = array();
        foreach ($themes as $theme) {
            $dir = $theme['dir'];
            $themeDirs[] = $dir;
            $fieldKeysByTheme[$dir] = array();
            foreach ($theme['fields'] as $field) {
                $fieldKeysByTheme[$dir][] = $field['key'];
            }
        }

        $hasThemeRoot = false;
        foreach ($themeDirs as $dir) {
            if (array_key_exists($dir, $data) && is_array($data[$dir])) {
                $hasThemeRoot = true;
                break;
            }
        }

        if (!$hasThemeRoot && $selectedTheme !== '' && isset($fieldKeysByTheme[$selectedTheme])) {
            $flatKeys = array_keys($data);
            if (!empty(array_intersect($flatKeys, $fieldKeysByTheme[$selectedTheme]))) {
                $data = array($selectedTheme => $data);
            }
        }

        $normalized = array();
        foreach ($themes as $theme) {
            $dir = $theme['dir'];
            $normalized[$dir] = isset($data[$dir]) && is_array($data[$dir]) ? $data[$dir] : array();
        }

        return $normalized;
    }

    public static function getCommentTargetCid($settings = null)
    {
        if ($settings === null) {
            $settings = self::getSettings();
        }

        $cid = isset($settings->bio_comment_cid) ? (int) $settings->bio_comment_cid : 0;
        return $cid > 0 ? $cid : 0;
    }

    public static function getCommentTargetPost($settings = null, $options = null)
    {
        $cid = self::getCommentTargetCid($settings);
        if ($cid <= 0) {
            return null;
        }

        if ($options === null) {
            $options = Typecho_Widget::widget('Widget_Options');
        }

        $db = self::getDb();
        $prefix = $db->getPrefix();
        $row = $db->fetchRow(
            $db->select('cid', 'title', 'slug', 'created', 'type', 'status', 'commentsNum')
                ->from($prefix . 'contents')
                ->where('cid = ?', $cid)
                ->where('(type = ? OR type = ?)', 'post', 'page')
                ->limit(1)
        );

        if (!is_array($row) || empty($row)) {
            return null;
        }

        $archive = Typecho_Widget::widget('Widget_Archive@typecho_bio_comment_target', 'type=single', array('cid' => $cid), false);
        if (!is_object($archive) || !method_exists($archive, 'have') || !$archive->have()) {
            return null;
        }

        // path = 相对路径（如 /archives/5/），是 Router::match() 所需要的格式
        $row['path'] = isset($archive->path) ? (string) $archive->path : '';
        // permalink = 完整 URL，用于展示
        $row['permalink'] = isset($archive->permalink) ? (string) $archive->permalink : '';
        if ($row['path'] === '' && $row['permalink'] === '') {
            return null;
        }

        return $row;
    }

    public static function getSelectedTheme($settings = null)
    {
        if ($settings === null) {
            $settings = self::getSettings();
        }

        $themes = self::discoverThemes();
        $dirs = array();
        foreach ($themes as $theme) {
            $dirs[] = $theme['dir'];
        }

        $selected = isset($settings->bio_theme) ? trim((string) $settings->bio_theme) : 'default';
        if ($selected !== '' && in_array($selected, $dirs, true)) {
            return $selected;
        }

        return empty($dirs) ? 'default' : $dirs[0];
    }

    public static function normalizeOptionArray($value)
    {
        if (is_array($value)) {
            $result = array();
            foreach ($value as $item) {
                $item = trim((string) $item);
                if ($item !== '') {
                    $result[] = $item;
                }
            }
            return array_values(array_unique($result));
        }

        $value = trim((string) $value);
        return $value === '' ? array() : array($value);
    }

    public static function isAdminOrInstallPath()
    {
        $options = Typecho_Widget::widget('Widget_Options');
        $adminUrl = isset($options->adminUrl) ? (string) $options->adminUrl : '';

        $adminPath = parse_url($adminUrl, PHP_URL_PATH);
        $adminPath = self::normalizeRequestPath($adminPath);
        if ($adminPath === '') {
            $adminPath = '/admin';
        }

        $requestUri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        $requestPath = parse_url($requestUri, PHP_URL_PATH);
        $requestPath = self::normalizeRequestPath($requestPath);

        $scriptPath = isset($_SERVER['SCRIPT_NAME']) ? self::normalizeRequestPath((string) $_SERVER['SCRIPT_NAME']) : '';

        if (self::pathIsUnderBase($requestPath, $adminPath) || self::pathIsUnderBase($scriptPath, $adminPath)) {
            return true;
        }

        return false;
    }

    public static function normalizeRequestPath($path, $lowercase = true)
    {
        $path = str_replace('\\', '/', (string) $path);
        $path = trim($path);
        if ($path === '') {
            return '';
        }

        if ($path[0] !== '/') {
            $path = '/' . $path;
        }

        $path = preg_replace('#/+#', '/', $path);
        $path = rtrim($path, '/');

        if ($lowercase) {
            $path = strtolower($path);
        }

        return $path === '' ? '/' : $path;
    }

    public static function pathIsUnderBase($path, $base)
    {
        if ($path === '' || $base === '') {
            return false;
        }

        if ($path === $base) {
            return true;
        }

        return strpos($path, $base . '/') === 0;
    }

    public static function discoverThemes()
    {
        $templatesRoot = __DIR__ . DIRECTORY_SEPARATOR . 'templates';
        if (!is_dir($templatesRoot)) {
            return array();
        }

        $items = scandir($templatesRoot);
        if (!is_array($items)) {
            return array();
        }

        $themes = array();

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $dir = $templatesRoot . DIRECTORY_SEPARATOR . $item;
            if (!is_dir($dir)) {
                continue;
            }

            if (!preg_match('/^[A-Za-z0-9_-]+$/', $item)) {
                continue;
            }

            $homeFile = $dir . DIRECTORY_SEPARATOR . 'home.php';
            $configFile = $dir . DIRECTORY_SEPARATOR . self::THEME_CONFIG_FILE;
            if (!is_file($homeFile)) {
                continue;
            }
            if (!is_file($configFile)) {
                continue;
            }

            $themeMeta = self::loadThemeMeta($item, $dir);
            $themes[] = $themeMeta;
        }

        usort($themes, function ($a, $b) {
            return strcmp($a['dir'], $b['dir']);
        });

        return $themes;
    }

    public static function isHiddenThemeForUi($dir)
    {
        return trim((string) $dir) === 'default';
    }

    public static function getSelectableThemesForUi(array $themes)
    {
        $visible = array();

        foreach ($themes as $theme) {
            $dir = isset($theme['dir']) ? (string) $theme['dir'] : '';
            if (self::isHiddenThemeForUi($dir)) {
                continue;
            }

            $visible[] = $theme;
        }

        return !empty($visible) ? array_values($visible) : array_values($themes);
    }

    public static function loadThemeMeta($dirName, $absoluteDir)
    {
        $meta = array(
            'dir' => $dirName,
            'name' => $dirName,
            'version' => '0.0.0',
            'description' => '',
            'fields' => array(),
        );

        $configFile = $absoluteDir . DIRECTORY_SEPARATOR . self::THEME_CONFIG_FILE;
        if (!is_file($configFile)) {
            return $meta;
        }

        $raw = file_get_contents($configFile);
        if (!is_string($raw) || $raw === '') {
            return $meta;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $meta;
        }

        $meta['name'] = isset($decoded['name']) && trim((string) $decoded['name']) !== ''
            ? trim((string) $decoded['name'])
            : $dirName;

        $meta['version'] = isset($decoded['version']) && trim((string) $decoded['version']) !== ''
            ? ltrim(trim((string) $decoded['version']), 'vV')
            : '0.0.0';

        $meta['description'] = isset($decoded['description']) ? trim((string) $decoded['description']) : '';

        if (isset($decoded['fields']) && is_array($decoded['fields'])) {
            $meta['fields'] = self::normalizeThemeFields($decoded['fields']);
        }

        return $meta;
    }

    public static function normalizeThemeFields(array $fields)
    {
        $normalized = array();

        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }

            $key = isset($field['key']) ? trim((string) $field['key']) : '';
            if ($key === '' || !preg_match('/^[A-Za-z0-9_]+$/', $key)) {
                continue;
            }

            $type = isset($field['type']) ? strtolower(trim((string) $field['type'])) : 'text';
            if (!in_array($type, array('text', 'textarea', 'number', 'select', 'checkbox'), true)) {
                $type = 'text';
            }

            $item = array(
                'key' => $key,
                'label' => isset($field['label']) && trim((string) $field['label']) !== '' ? trim((string) $field['label']) : $key,
                'type' => $type,
                'default' => array_key_exists('default', $field) ? $field['default'] : '',
                'description' => isset($field['description']) ? trim((string) $field['description']) : '',
                'options' => array(),
            );

            if ($type === 'select' && isset($field['options']) && is_array($field['options'])) {
                foreach ($field['options'] as $optionValue => $optionLabel) {
                    $ov = (string) $optionValue;
                    if ($ov === '') {
                        continue;
                    }
                    $item['options'][$ov] = (string) $optionLabel;
                }
            }

            $normalized[] = $item;
        }

        return $normalized;
    }

    public static function buildThemeConfigJsonDefault(array $themes)
    {
        $initial = array();
        foreach ($themes as $theme) {
            $dir = $theme['dir'];
            $initial[$dir] = array();
            foreach ($theme['fields'] as $field) {
                $initial[$dir][$field['key']] = array_key_exists('default', $field) ? $field['default'] : '';
            }
        }

        return json_encode($initial, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function buildThemeConfigJsonFromSettings(array $settings, array $themes)
    {
        $raw = isset($settings['bio_theme_configs_json']) ? (string) $settings['bio_theme_configs_json'] : '{}';
        $selectedTheme = isset($settings['bio_theme']) ? trim((string) $settings['bio_theme']) : '';
        $existing = self::normalizeThemeConfigPayload(json_decode($raw, true), $themes, $selectedTheme);

        $result = array();
        foreach ($themes as $theme) {
            $dir = $theme['dir'];
            $result[$dir] = array();

            foreach ($theme['fields'] as $field) {
                $key = $field['key'];
                $fieldName = self::buildThemeFieldName($dir, $key);
                $default = array_key_exists('default', $field) ? $field['default'] : '';
                $value = $default;
                $hasExisting = false;

                if (isset($existing[$dir]) && is_array($existing[$dir]) && array_key_exists($key, $existing[$dir])) {
                    $value = $existing[$dir][$key];
                    $hasExisting = true;
                }

                if (array_key_exists($fieldName, $settings)) {
                    $rawValue = $settings[$fieldName];
                    if ($field['type'] === 'checkbox') {
                        // Typecho checkbox request values may degrade to empty arrays in some environments.
                        // Keep JSON value when available; only override when we truly received a checked value.
                        if (is_array($rawValue)) {
                            if (!empty($rawValue)) {
                                $value = (string) reset($rawValue);
                            } elseif (!$hasExisting) {
                                $value = '';
                            }
                        } else {
                            $rawValue = trim((string) $rawValue);
                            if ($rawValue !== '') {
                                $value = $rawValue;
                            } elseif (!$hasExisting) {
                                $value = '';
                            }
                        }
                    } elseif (is_array($rawValue)) {
                        $value = !empty($rawValue) ? (string) reset($rawValue) : '';
                    } else {
                        $value = (string) $rawValue;
                    }
                }

                if ($field['type'] === 'checkbox') {
                    $value = in_array((string) $value, array('1', 'true', 'on', 'yes'), true) ? '1' : '0';
                }

                $result[$dir][$key] = $value;
            }
        }

        return json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function getThemeOptionLabel(array $theme)
    {
        $label = (string) $theme['name'] . ' (' . (string) $theme['dir'] . ')';
        $version = isset($theme['version']) ? ltrim(trim((string) $theme['version']), 'vV') : '';
        if ($version !== '' && $version !== '0.0.0') {
            $label .= ' v' . $version;
        }

        return $label;
    }

    public static function appendThemeMarketplaceBlock(Typecho_Widget_Helper_Form $form, $settings, array $themes)
    {
        $wrap = new Typecho_Widget_Helper_Layout('li', array(
            'class' => 'typecho-option typecho-bio-theme-market-option',
        ));
        $wrap->html(self::renderThemeMarketplacePanelHtml($settings, $themes));
        $form->addItem($wrap);
    }

    public static function renderThemeMarketplacePanelHtml($settings, array $themes)
    {
        $config = array(
            'listUrl' => self::getSecureActionUrl('action/typecho-bio?do=themes-list'),
            'installUrl' => self::getSecureActionUrl('action/typecho-bio?do=themes-install'),
            'updateUrl' => self::getSecureActionUrl('action/typecho-bio?do=themes-update'),
            'catalogUrl' => self::getThemeCatalogSourceUrl(),
            'csrfToken' => self::getCurrentRequestSecurityToken(),
            'csrfRef' => self::getCurrentRequestUrl(),
            'selectedTheme' => self::getSelectedTheme($settings),
        );
        $configJson = htmlspecialchars(json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_NOQUOTES, 'UTF-8');
        $catalogLink = htmlspecialchars(self::getThemeCatalogSourceUrl(), ENT_QUOTES, 'UTF-8');

        return <<<HTML
<style>
.typecho-bio-theme-market {
    padding: 16px 18px;
    border: 1px solid #d9dee3;
    border-radius: 12px;
    background: #fff;
}

.typecho-bio-theme-market__head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 12px;
}

.typecho-bio-theme-market__title {
    margin: 0;
    font-size: 16px;
    font-weight: 700;
    color: #232a31;
}

.typecho-bio-theme-market__desc {
    margin: 4px 0 0;
    color: #6b7280;
    line-height: 1.6;
}

.typecho-bio-theme-market__toolbar {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.typecho-bio-theme-market__search {
    min-width: 220px;
    padding: 7px 10px;
    border: 1px solid #d9dee3;
    border-radius: 8px;
    background: #fff;
}

.typecho-bio-theme-market__body {
    display: block;
}

.typecho-bio-theme-market.is-collapsed .typecho-bio-theme-market__body {
    display: none;
}

.typecho-bio-theme-market__status {
    margin-bottom: 12px;
    padding: 10px 12px;
    border-radius: 10px;
    background: #f5f7fa;
    color: #52616f;
    line-height: 1.6;
}

.typecho-bio-theme-market__status.is-error {
    background: #fff1f1;
    color: #c0392b;
}

.typecho-bio-theme-market__status.is-success {
    background: #eefaf2;
    color: #1e824c;
}

.typecho-bio-theme-market__grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
    gap: 14px;
}

.typecho-bio-theme-card {
    display: flex;
    flex-direction: column;
    border: 1px solid #e5e9ef;
    border-radius: 12px;
    overflow: hidden;
    background: #fff;
}

.typecho-bio-theme-card__cover {
    position: relative;
    display: block;
    width: 100%;
    aspect-ratio: 16 / 10;
    overflow: hidden;
    border: 0;
    padding: 0;
    font: inherit;
    background: linear-gradient(135deg, #edf2f7, #dce5ee);
}

.typecho-bio-theme-card__cover.is-zoomable {
    cursor: zoom-in;
}

.typecho-bio-theme-card__cover.is-zoomable::after {
    content: '放大';
    position: absolute;
    right: 10px;
    bottom: 10px;
    padding: 4px 8px;
    border-radius: 999px;
    background: rgba(15, 23, 42, 0.7);
    color: #fff;
    font-size: 12px;
    line-height: 1;
    opacity: 0;
    transform: translateY(4px);
    transition: opacity 0.18s ease, transform 0.18s ease;
}

.typecho-bio-theme-card__cover.is-zoomable:hover::after,
.typecho-bio-theme-card__cover.is-zoomable:focus-visible::after {
    opacity: 1;
    transform: translateY(0);
}

.typecho-bio-theme-card__cover.is-zoomable:focus-visible {
    outline: 2px solid #4c9ffe;
    outline-offset: -2px;
}

.typecho-bio-theme-card__cover img {
    display: block;
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.typecho-bio-theme-card__cover-fallback {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    height: 100%;
    font-size: 28px;
    font-weight: 700;
    letter-spacing: 0.08em;
    color: #6b7280;
}

.typecho-bio-theme-card__body {
    display: flex;
    flex: 1 1 auto;
    flex-direction: column;
    gap: 10px;
    padding: 14px;
}

.typecho-bio-theme-card__meta {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
}

.typecho-bio-theme-card__name {
    margin: 0;
    font-size: 15px;
    font-weight: 700;
    color: #1f2933;
}

.typecho-bio-theme-card__dir {
    color: #8a94a6;
    font-size: 12px;
}

.typecho-bio-theme-card__desc {
    margin: 0;
    color: #52616f;
    line-height: 1.6;
    min-height: 3.2em;
}

.typecho-bio-theme-card__badges {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.typecho-bio-theme-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 8px;
    border-radius: 999px;
    background: #f4f6f8;
    color: #52616f;
    font-size: 12px;
}

.typecho-bio-theme-badge.is-update {
    background: #fff4e5;
    color: #c96a00;
}

.typecho-bio-theme-badge.is-installed {
    background: #eefaf2;
    color: #1e824c;
}

.typecho-bio-theme-card__links {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    font-size: 12px;
}

.typecho-bio-theme-card__links a {
    color: #467b96;
}

.typecho-bio-theme-card__actions {
    margin-top: auto;
    display: flex;
    gap: 10px;
    align-items: center;
    justify-content: space-between;
}

.typecho-bio-theme-card__state {
    color: #6b7280;
    font-size: 12px;
}

.typecho-bio-theme-preview {
    position: fixed !important;
    inset: 0 !important;
    z-index: 9999 !important;
    display: none !important;
    --typecho-bio-preview-radius: 24px;
    --typecho-bio-preview-width: 0px;
    --typecho-bio-preview-height: 0px;
    --typecho-bio-preview-translate-x: 0px;
    --typecho-bio-preview-translate-y: 0px;
    --typecho-bio-preview-scale-x: 1;
    --typecho-bio-preview-scale-y: 1;
    opacity: 0;
    pointer-events: none;
    transition: opacity 300ms ease;
    background: rgba(15, 23, 42, 0.78) !important;
}

.typecho-bio-theme-preview.is-open {
    display: block !important;
}

.typecho-bio-theme-preview.is-active {
    opacity: 1 !important;
    pointer-events: auto !important;
}

.typecho-bio-theme-preview__dialog {
    position: fixed !important;
    left: 50% !important;
    top: 50% !important;
    width: var(--typecho-bio-preview-width) !important;
    height: var(--typecho-bio-preview-height) !important;
    opacity: 1 !important;
    transform: translate3d(calc(-50% + var(--typecho-bio-preview-translate-x)), calc(-50% + var(--typecho-bio-preview-translate-y)), 0) scale(var(--typecho-bio-preview-scale-x), var(--typecho-bio-preview-scale-y)) !important;
    transform-origin: 50% 50% !important;
    transition: transform 300ms cubic-bezier(0.2, 0.8, 0.2, 1) !important;
    will-change: transform !important;
}

.typecho-bio-theme-preview__frame {
    width: 100% !important;
    height: 100% !important;
    overflow: hidden !important;
    border-radius: var(--typecho-bio-preview-radius) !important;
    background: #0f172a !important;
    box-shadow: 0 24px 60px rgba(15, 23, 42, 0.35) !important;
}

.typecho-bio-theme-preview__image {
    display: block !important;
    width: 100% !important;
    height: 100% !important;
    object-fit: contain !important;
    background: #0f172a !important;
}

.typecho-bio-theme-preview__caption {
    position: fixed !important;
    left: 50% !important;
    bottom: max(18px, env(safe-area-inset-bottom));
    max-width: min(calc(100vw - 32px), 720px);
    margin: 0;
    padding: 8px 14px;
    border-radius: 999px;
    background: rgba(15, 23, 42, 0.72);
    text-align: center;
    color: #f8fafc;
    line-height: 1.6;
    opacity: 0;
    transform: translateX(-50%) !important;
    transition: opacity 180ms ease;
    backdrop-filter: blur(8px);
    pointer-events: none;
}

.typecho-bio-theme-preview.is-active .typecho-bio-theme-preview__caption {
    opacity: 1;
}

.typecho-bio-theme-preview__close {
    position: absolute !important;
    top: 12px;
    right: 12px;
    z-index: 1;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 38px;
    height: 38px;
    border: 0;
    border-radius: 999px;
    background: rgba(15, 23, 42, 0.72);
    color: #fff;
    font-size: 22px;
    line-height: 1;
    cursor: pointer;
}

body.typecho-bio-preview-open {
    overflow: hidden;
}

@media (max-width: 720px) {
    .typecho-bio-theme-market__head {
        flex-direction: column;
    }

    .typecho-bio-theme-market__toolbar {
        width: 100%;
    }

    .typecho-bio-theme-market__search {
        width: 100%;
        min-width: 0;
    }

    .typecho-bio-theme-preview {
        --typecho-bio-preview-radius: 18px;
    }

    .typecho-bio-theme-preview__frame {
        border-radius: 18px;
    }

    .typecho-bio-theme-preview__caption {
        bottom: max(12px, env(safe-area-inset-bottom));
        max-width: calc(100vw - 24px);
        padding: 7px 12px;
        font-size: 13px;
    }

    .typecho-bio-theme-preview__close {
        top: 8px;
        right: 8px;
        width: 34px;
        height: 34px;
        font-size: 20px;
    }
}
</style>
<div id="typecho-bio-theme-market" class="typecho-bio-theme-market">
    <div class="typecho-bio-theme-market__head">
        <div>
            <p class="typecho-bio-theme-market__title">获取主题</p>
            <p class="typecho-bio-theme-market__desc">读取可安装主题。主题开发文档和投稿详见：<a href="https://blog.lhl.one/artical/1263.html" target="_blank" rel="noreferrer">LHL's Blog</a></p>
        </div>
        <div class="typecho-bio-theme-market__toolbar">
            <input type="search" class="typecho-bio-theme-market__search" data-theme-market-search placeholder="搜索主题名称 / dir / 描述">
            <button type="button" class="btn" data-theme-market-refresh>刷新主题列表</button>
            <button type="button" class="btn" data-theme-market-toggle aria-expanded="true">收起</button>
        </div>
    </div>
    <div class="typecho-bio-theme-market__body" data-theme-market-body>
        <div class="typecho-bio-theme-market__status" data-theme-market-status>正在加载远程主题目录...</div>
        <div class="typecho-bio-theme-market__grid" data-theme-market-grid></div>
    </div>
    <div class="typecho-bio-theme-preview" data-theme-market-preview hidden>
        <div class="typecho-bio-theme-preview__dialog" data-theme-market-preview-dialog role="dialog" aria-modal="true" aria-label="主题封面预览">
            <button type="button" class="typecho-bio-theme-preview__close" data-theme-market-preview-close aria-label="关闭预览">×</button>
            <div class="typecho-bio-theme-preview__frame" data-theme-market-preview-frame>
                <img class="typecho-bio-theme-preview__image" data-theme-market-preview-image alt="">
            </div>
            <p class="typecho-bio-theme-preview__caption" data-theme-market-preview-caption></p>
        </div>
    </div>
</div>
<script type="application/json" id="typecho-bio-theme-market-config">{$configJson}</script>
<script>
(function () {
    const script = document.getElementById('typecho-bio-theme-market-config');
    const root = document.getElementById('typecho-bio-theme-market');
    if (!script || !root || root.getAttribute('data-market-init') === '1') {
        return;
    }

    root.setAttribute('data-market-init', '1');

    let config = {};
    try {
        config = JSON.parse(script.textContent || '{}');
    } catch (error) {
        config = {};
    }

    const status = root.querySelector('[data-theme-market-status]');
    const grid = root.querySelector('[data-theme-market-grid]');
    const marketBody = root.querySelector('[data-theme-market-body]');
    const searchInput = root.querySelector('[data-theme-market-search]');
    const toggleButton = root.querySelector('[data-theme-market-toggle]');
    const preview = root.querySelector('[data-theme-market-preview]');
    const previewDialog = root.querySelector('[data-theme-market-preview-dialog]');
    const previewFrame = root.querySelector('[data-theme-market-preview-frame]');
    const previewImage = root.querySelector('[data-theme-market-preview-image]');
    const previewCaption = root.querySelector('[data-theme-market-preview-caption]');

    if (preview && preview.parentNode !== document.body) {
        document.body.appendChild(preview);
    }

    let busyDir = '';
    let currentPayload = { themes: [] };
    let searchKeyword = '';
    let lastPreviewTrigger = null;
    let currentPreviewTrigger = null;
    let currentPreviewLayout = null;
    let previewLayoutFrame = 0;
    let previewCloseTimer = 0;

    const escapeHtml = (value) => String(value == null ? '' : value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');

    const setStatus = (text, kind) => {
        if (!status) {
            return;
        }

        status.className = 'typecho-bio-theme-market__status' + (kind ? ' is-' + kind : '');
        status.textContent = text || '';
    };

    const setCollapsed = (collapsed) => {
        root.classList.toggle('is-collapsed', Boolean(collapsed));
        if (marketBody) {
            marketBody.style.display = collapsed ? 'none' : '';
        }
        if (toggleButton) {
            toggleButton.textContent = collapsed ? '展开' : '收起';
            toggleButton.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        }
    };

    const shuffleItems = (items) => {
        const randomized = Array.isArray(items) ? items.slice() : [];

        for (let index = randomized.length - 1; index > 0; index -= 1) {
            const swapIndex = Math.floor(Math.random() * (index + 1));
            const current = randomized[index];
            randomized[index] = randomized[swapIndex];
            randomized[swapIndex] = current;
        }

        return randomized;
    };

    const filterMarketplaceThemes = (items) => Array.isArray(items)
        ? items.filter((item) => String(item && item.dir ? item.dir : '').trim().toLowerCase() !== 'default')
        : [];

    const getPreviewViewportSize = () => {
        const visualViewport = window.visualViewport;
        const viewportWidth = visualViewport && visualViewport.width ? visualViewport.width : (window.innerWidth || document.documentElement.clientWidth || window.screen.width || 0);
        const viewportHeight = visualViewport && visualViewport.height ? visualViewport.height : (window.innerHeight || document.documentElement.clientHeight || window.screen.height || 0);

        return {
            width: Math.max(240, Math.round(viewportWidth)),
            height: Math.max(240, Math.round(viewportHeight)),
            offsetLeft: visualViewport && typeof visualViewport.offsetLeft === 'number' ? visualViewport.offsetLeft : 0,
            offsetTop: visualViewport && typeof visualViewport.offsetTop === 'number' ? visualViewport.offsetTop : 0
        };
    };

    const getPreviewTransform = (layout) => {
        return {
            translateX: Math.round(layout.translateX) + 'px',
            translateY: Math.round(layout.translateY) + 'px',
            scaleX: String(layout.scaleX),
            scaleY: String(layout.scaleY)
        };
    };

    const calculatePreviewLayout = () => {
        if (!currentPreviewTrigger || !previewImage || typeof currentPreviewTrigger.getBoundingClientRect !== 'function') {
            return null;
        }

        const rect = currentPreviewTrigger.getBoundingClientRect();
        if (!rect.width || !rect.height) {
            return null;
        }

        const viewport = getPreviewViewportSize();
        const edgeGap = viewport.width <= 720 ? 12 : 24;
        const maxWidth = Math.max(180, viewport.width - edgeGap * 2);
        const maxHeight = Math.max(180, viewport.height - edgeGap * 2);
        const naturalWidth = previewImage.naturalWidth || rect.width;
        const naturalHeight = previewImage.naturalHeight || rect.height;
        const fitScale = Math.min(maxWidth / naturalWidth, maxHeight / naturalHeight, 1);
        const targetWidth = Math.max(180, Math.round(naturalWidth * fitScale));
        const targetHeight = Math.max(180, Math.round(naturalHeight * fitScale));
        const startCenterX = rect.left + rect.width / 2;
        const startCenterY = rect.top + rect.height / 2;
        const endCenterX = viewport.offsetLeft + viewport.width / 2;
        const endCenterY = viewport.offsetTop + viewport.height / 2;

        return {
            targetWidth: targetWidth,
            targetHeight: targetHeight,
            translateX: Math.round(startCenterX - endCenterX),
            translateY: Math.round(startCenterY - endCenterY),
            scaleX: rect.width / targetWidth,
            scaleY: rect.height / targetHeight,
            radius: viewport.width <= 720 ? 18 : 24
        };
    };

    const applyPreviewLayout = (layout, useStartTransform) => {
        if (!layout || !preview || !previewDialog || !previewFrame) {
            return;
        }

        currentPreviewLayout = layout;
        preview.style.setProperty('--typecho-bio-preview-radius', layout.radius + 'px');
        preview.style.setProperty('--typecho-bio-preview-width', layout.targetWidth + 'px');
        preview.style.setProperty('--typecho-bio-preview-height', layout.targetHeight + 'px');

        if (useStartTransform) {
            const transformState = getPreviewTransform(layout);
            preview.style.setProperty('--typecho-bio-preview-translate-x', transformState.translateX);
            preview.style.setProperty('--typecho-bio-preview-translate-y', transformState.translateY);
            preview.style.setProperty('--typecho-bio-preview-scale-x', transformState.scaleX);
            preview.style.setProperty('--typecho-bio-preview-scale-y', transformState.scaleY);
            return;
        }

        preview.style.setProperty('--typecho-bio-preview-translate-x', '0px');
        preview.style.setProperty('--typecho-bio-preview-translate-y', '0px');
        preview.style.setProperty('--typecho-bio-preview-scale-x', '1');
        preview.style.setProperty('--typecho-bio-preview-scale-y', '1');
    };

    const syncPreviewLayout = (animateFromThumb) => {
        if (!preview || !previewDialog || !previewFrame || !previewImage || !preview.classList.contains('is-open')) {
            return;
        }

        const layout = calculatePreviewLayout();
        if (!layout) {
            return;
        }

        applyPreviewLayout(layout, Boolean(animateFromThumb));

        if (animateFromThumb) {
            preview.classList.remove('is-active');
            previewDialog.offsetWidth;
        }

        preview.classList.add('is-active');
        preview.style.setProperty('--typecho-bio-preview-translate-x', '0px');
        preview.style.setProperty('--typecho-bio-preview-translate-y', '0px');
        preview.style.setProperty('--typecho-bio-preview-scale-x', '1');
        preview.style.setProperty('--typecho-bio-preview-scale-y', '1');
    };

    const schedulePreviewLayout = (animateFromThumb) => {
        if (previewLayoutFrame) {
            cancelAnimationFrame(previewLayoutFrame);
        }

        previewLayoutFrame = requestAnimationFrame(() => {
            previewLayoutFrame = 0;
            syncPreviewLayout(animateFromThumb);
        });
    };

    const finalizePreviewClose = () => {
        if (!preview || !previewDialog || !previewImage) {
            return;
        }

        preview.classList.remove('is-open');
        preview.classList.remove('is-active');
        preview.hidden = true;
        preview.style.removeProperty('--typecho-bio-preview-radius');
        preview.style.removeProperty('--typecho-bio-preview-width');
        preview.style.removeProperty('--typecho-bio-preview-height');
        preview.style.removeProperty('--typecho-bio-preview-translate-x');
        preview.style.removeProperty('--typecho-bio-preview-translate-y');
        preview.style.removeProperty('--typecho-bio-preview-scale-x');
        preview.style.removeProperty('--typecho-bio-preview-scale-y');
        previewImage.removeAttribute('src');
        previewImage.alt = '';
        if (previewCaption) {
            previewCaption.textContent = '';
            previewCaption.hidden = false;
        }
        document.body.classList.remove('typecho-bio-preview-open');
        currentPreviewTrigger = null;
        currentPreviewLayout = null;

        if (lastPreviewTrigger && typeof lastPreviewTrigger.focus === 'function') {
            try {
                lastPreviewTrigger.focus();
            } catch (error) {
            }
        }

        lastPreviewTrigger = null;
    };

    const openPreview = (url, name, trigger) => {
        if (!preview || !previewDialog || !previewImage || !url || !trigger) {
            return;
        }

        if (previewCloseTimer) {
            clearTimeout(previewCloseTimer);
            previewCloseTimer = 0;
        }

        if (preview.classList.contains('is-open')) {
            finalizePreviewClose();
        }

        lastPreviewTrigger = trigger || null;
        currentPreviewTrigger = trigger;
        currentPreviewLayout = null;
        previewImage.src = url;
        previewImage.alt = (name || '主题封面') + ' 大图';
        if (previewCaption) {
            previewCaption.textContent = name || '';
            previewCaption.hidden = !name;
        }
        preview.hidden = false;
        preview.classList.add('is-open');
        preview.classList.remove('is-active');
        preview.style.setProperty('--typecho-bio-preview-width', '0px');
        preview.style.setProperty('--typecho-bio-preview-height', '0px');
        preview.style.setProperty('--typecho-bio-preview-translate-x', '0px');
        preview.style.setProperty('--typecho-bio-preview-translate-y', '0px');
        preview.style.setProperty('--typecho-bio-preview-scale-x', '1');
        preview.style.setProperty('--typecho-bio-preview-scale-y', '1');
        document.body.classList.add('typecho-bio-preview-open');

        if (previewImage.complete && previewImage.naturalWidth > 0) {
            schedulePreviewLayout(true);
        }
    };

    const closePreview = () => {
        if (!preview) {
            return;
        }

        if (previewLayoutFrame) {
            cancelAnimationFrame(previewLayoutFrame);
            previewLayoutFrame = 0;
        }

        if (previewCloseTimer) {
            clearTimeout(previewCloseTimer);
            previewCloseTimer = 0;
        }

        if (!preview.classList.contains('is-open') || !currentPreviewLayout || !previewDialog) {
            finalizePreviewClose();
            return;
        }

        preview.classList.remove('is-active');
        applyPreviewLayout(currentPreviewLayout, true);
        previewCloseTimer = window.setTimeout(() => {
            previewCloseTimer = 0;
            finalizePreviewClose();
        }, 320);
    };

    const setThemeOptions = (payload) => {
        const select = document.querySelector('select[name="bio_theme"]');
        if (!select || !payload || !Array.isArray(payload.theme_options)) {
            return;
        }

        const currentValue = String(select.value || '');
        select.innerHTML = '';

        payload.theme_options.forEach((item) => {
            if (!item || !item.dir) {
                return;
            }

            const option = document.createElement('option');
            option.value = String(item.dir);
            option.textContent = String(item.label || item.dir);
            select.appendChild(option);
        });

        const nextValue = payload.selected_theme && Array.from(select.options).some((option) => option.value === payload.selected_theme)
            ? payload.selected_theme
            : (Array.from(select.options).some((option) => option.value === currentValue) ? currentValue : (select.options[0] ? select.options[0].value : ''));

        select.value = nextValue;
        select.dispatchEvent(new Event('change', { bubbles: true }));
    };

    const parseThemeConfigInputName = (name) => {
        const normalizedName = String(name || '').replace(/\[\]$/, '');
        const parts = normalizedName.split('__');
        if (parts.length !== 3 || parts[0] !== 'bio_theme_cfg') {
            return null;
        }

        const themeDir = String(parts[1] || '');
        const fieldKey = String(parts[2] || '');
        if (themeDir === '' || fieldKey === '') {
            return null;
        }

        return {
            theme: themeDir,
            key: fieldKey,
        };
    };

    const parseThemeConfigJson = (raw) => {
        if (typeof raw !== 'string' || raw.trim() === '') {
            return {};
        }

        try {
            const parsed = JSON.parse(raw);
            return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
        } catch (error) {
            return {};
        }
    };

    const applyThemeConfigInputValue = (input, value) => {
        if (!input) {
            return;
        }

        const normalizedValue = value == null ? '' : String(value);
        if (input.type === 'checkbox') {
            input.checked = ['1', 'true', 'on', 'yes'].indexOf(normalizedValue.toLowerCase()) !== -1;
            return;
        }

        input.value = normalizedValue;
    };

    const buildThemeConfigJsonFromForm = (form) => {
        if (!form) {
            return '';
        }

        const result = {};
        Array.from(form.querySelectorAll('[name^="bio_theme_cfg__"]')).forEach((input) => {
            if (!input || !input.name) {
                return;
            }

            const info = parseThemeConfigInputName(input.name);
            if (!info) {
                return;
            }

            if (!Object.prototype.hasOwnProperty.call(result, info.theme)) {
                result[info.theme] = {};
            }

            result[info.theme][info.key] = input.type === 'checkbox'
                ? (input.checked ? '1' : '0')
                : String(input.value || '');
        });

        return JSON.stringify(result, null, 2);
    };

    const setThemeJson = (payload) => {
        const textarea = document.querySelector('textarea[name="bio_theme_configs_json"]');
        if (!textarea) {
            return;
        }

        const anchor = textarea.closest('.typecho-option');
        const form = anchor ? anchor.closest('form') : null;
        const nextJson = buildThemeConfigJsonFromForm(form);

        if (nextJson !== '') {
            textarea.value = nextJson;
            return;
        }

        if (payload && typeof payload.theme_configs_json === 'string' && payload.theme_configs_json !== '') {
            textarea.value = payload.theme_configs_json;
        }
    };

    const syncThemeConfigInputs = (payload) => {
        const textarea = document.querySelector('textarea[name="bio_theme_configs_json"]');
        const html = payload && typeof payload.theme_config_inputs_html === 'string'
            ? payload.theme_config_inputs_html
            : '';
        const defaults = parseThemeConfigJson(payload && typeof payload.theme_config_defaults_json === 'string'
            ? payload.theme_config_defaults_json
            : '');

        if (!textarea || html === '') {
            return;
        }

        const anchor = textarea.closest('.typecho-option');
        const form = anchor ? anchor.closest('form') : null;
        if (!anchor || !anchor.parentNode || !form) {
            return;
        }

        const currentValues = {};
        Array.from(form.querySelectorAll('[name^="bio_theme_cfg__"]')).forEach((input) => {
            if (!input || !input.name) {
                return;
            }

            if (input.type === 'checkbox') {
                currentValues[input.name] = input.checked ? '1' : '0';
                return;
            }

            currentValues[input.name] = String(input.value || '');
        });

        Array.from(form.querySelectorAll('[data-bio-theme-config-row="1"]')).forEach((row) => {
            if (row && row.parentNode) {
                row.parentNode.removeChild(row);
            }
        });

        const template = document.createElement('template');
        template.innerHTML = html;

        Array.from(template.content.children).forEach((node) => {
            anchor.parentNode.insertBefore(node, anchor);
        });

        Array.from(form.querySelectorAll('[name^="bio_theme_cfg__"]')).forEach((input) => {
            if (!input || !input.name) {
                return;
            }

            if (Object.prototype.hasOwnProperty.call(currentValues, input.name)) {
                applyThemeConfigInputValue(input, currentValues[input.name]);
                return;
            }

            const info = parseThemeConfigInputName(input.name);
            if (!info || !defaults[info.theme] || !Object.prototype.hasOwnProperty.call(defaults[info.theme], info.key)) {
                return;
            }

            applyThemeConfigInputValue(input, defaults[info.theme][info.key]);
        });
    };

    const renderThemes = () => {
        if (!grid) {
            return;
        }

        const sourceItems = filterMarketplaceThemes(currentPayload && Array.isArray(currentPayload.themes) ? currentPayload.themes : []);
        const items = searchKeyword === ''
            ? sourceItems
            : sourceItems.filter((item) => {
                const haystack = [item.name, item.dir, item.description, item.status_text]
                    .map((value) => String(value || '').toLowerCase())
                    .join(' ');
                return haystack.indexOf(searchKeyword) !== -1;
            });

        if (!sourceItems.length) {
            grid.innerHTML = '<div class="typecho-bio-theme-card"><div class="typecho-bio-theme-card__body"><p class="typecho-bio-theme-card__name">暂无可显示主题</p><p class="typecho-bio-theme-card__desc">当前没有可显示的主题条目。</p></div></div>';
            return;
        }

        if (!items.length) {
            grid.innerHTML = '<div class="typecho-bio-theme-card"><div class="typecho-bio-theme-card__body"><p class="typecho-bio-theme-card__name">没有匹配主题</p><p class="typecho-bio-theme-card__desc">换个关键词再试一次。</p></div></div>';
            return;
        }

        grid.innerHTML = items.map((item) => {
            const rawDir = String(item.dir || '');
            const rawName = String(item.name || rawDir);
            const dir = escapeHtml(rawDir);
            const name = escapeHtml(rawName);
            const description = escapeHtml(item.description || '');
            const remoteVersion = escapeHtml(item.remote_version || item.version || '');
            const localVersion = escapeHtml(item.local_version || '');
            const stateText = escapeHtml(item.status_text || '');
            const coverUrl = String(item.cover || '');
            const coverFallbackText = escapeHtml((rawName || rawDir).slice(0, 2));
            const cover = coverUrl
                ? '<button type="button" class="typecho-bio-theme-card__cover is-zoomable" data-theme-market-cover-url="' + escapeHtml(coverUrl) + '" data-theme-market-cover-name="' + name + '" aria-label="查看 ' + name + ' 封面大图"><img src="' + escapeHtml(coverUrl) + '" alt="' + name + '"></button>'
                : '<div class="typecho-bio-theme-card__cover"><div class="typecho-bio-theme-card__cover-fallback">' + coverFallbackText + '</div></div>';
            const badges = [];

            if (remoteVersion) {
                badges.push('<span class="typecho-bio-theme-badge">远程 v' + remoteVersion + '</span>');
            }

            if (item.installed) {
                badges.push('<span class="typecho-bio-theme-badge is-installed">已安装' + (localVersion ? ' · v' + localVersion : '') + '</span>');
            }

            if (item.needs_update) {
                badges.push('<span class="typecho-bio-theme-badge is-update">可更新</span>');
            }

            if (item.local_only) {
                badges.push('<span class="typecho-bio-theme-badge">仅本地</span>');
            }

            const links = [];
            if (item.demo_url) {
                links.push('<a href="' + escapeHtml(item.demo_url) + '" target="_blank" rel="noreferrer">Demo</a>');
            }
            if (item.github_url) {
                links.push('<a href="' + escapeHtml(item.github_url) + '" target="_blank" rel="noreferrer">GitHub</a>');
            }
            if (item.zip_url) {
                links.push('<a href="' + escapeHtml(item.zip_url) + '" target="_blank" rel="noreferrer">Zip</a>');
            }

            let actionsHtml = '<span class="typecho-bio-theme-card__state">' + stateText + '</span>';
            if (item.can_install) {
                actionsHtml = '<button type="button" class="btn primary" data-theme-market-action="install" data-theme-dir="' + dir + '"' + (busyDir === rawDir ? ' disabled' : '') + '>安装</button><span class="typecho-bio-theme-card__state">' + stateText + '</span>';
            } else if (item.can_update) {
                actionsHtml = '<button type="button" class="btn primary" data-theme-market-action="update" data-theme-dir="' + dir + '"' + (busyDir === rawDir ? ' disabled' : '') + '>更新</button><span class="typecho-bio-theme-card__state">' + stateText + '</span>';
            }

            return '' +
                '<div class="typecho-bio-theme-card">' +
                    cover +
                    '<div class="typecho-bio-theme-card__body">' +
                        '<div class="typecho-bio-theme-card__meta">' +
                            '<div><p class="typecho-bio-theme-card__name">' + name + '</p><span class="typecho-bio-theme-card__dir">' + dir + '</span></div>' +
                        '</div>' +
                        '<p class="typecho-bio-theme-card__desc">' + description + '</p>' +
                        '<div class="typecho-bio-theme-card__badges">' + badges.join('') + '</div>' +
                        '<div class="typecho-bio-theme-card__links">' + links.join('') + '</div>' +
                        '<div class="typecho-bio-theme-card__actions">' + actionsHtml + '</div>' +
                    '</div>' +
                '</div>';
        }).join('');
    };

    const applyPayload = (payload, successMessage) => {
        if (!payload || typeof payload !== 'object') {
            setStatus('主题目录返回无效数据。', 'error');
            return;
        }

        currentPayload = Object.assign({}, payload, {
            themes: shuffleItems(filterMarketplaceThemes(payload && Array.isArray(payload.themes) ? payload.themes : []))
        });
        renderThemes();
        syncThemeConfigInputs(payload);
        setThemeJson(payload);
        setThemeOptions(payload);

        if (successMessage) {
            setStatus(successMessage, 'success');
            return;
        }

        if (payload.catalog_ok === false) {
            setStatus(payload.message || '远程主题目录获取失败，仅显示本地主题。', 'error');
            return;
        }

        setStatus(payload.message || '主题列表已更新。', '');
    };

    const fetchJson = (url, options) => fetch(url, Object.assign({
        credentials: 'same-origin',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    }, options || {})).then((response) => response.json().catch(() => ({ ok: false, message: '服务端返回格式错误' })));

    const getSecurityTokenFromUrl = (url) => {
        try {
            const parsedUrl = new URL(String(url || ''), window.location.href);
            return parsedUrl.searchParams.get('_') || '';
        } catch (error) {
            return '';
        }
    };

    const stripSecurityTokenFromUrl = (url) => {
        try {
            const parsedUrl = new URL(String(url || ''), window.location.href);
            parsedUrl.searchParams.delete('_');
            return parsedUrl.toString();
        } catch (error) {
            return String(url || '');
        }
    };

    const loadCatalog = () => {
        setStatus('正在拉取远程主题目录...', '');
        return fetchJson(config.listUrl, { method: 'GET' }).then((payload) => {
            applyPayload(payload);
        }).catch((error) => {
            setStatus('获取主题目录失败：' + (error && error.message ? error.message : '网络异常'), 'error');
        });
    };

    const runAction = (action, dir) => {
        if (!dir) {
            return;
        }

        busyDir = dir;
        setStatus((action === 'update' ? '正在更新主题：' : '正在安装主题：') + dir + '...', '');
        renderThemes();

        const body = new URLSearchParams();
        const actionUrl = action === 'update' ? config.updateUrl : config.installUrl;
        const requestUrl = stripSecurityTokenFromUrl(actionUrl);
        const csrfToken = String(config.csrfToken || getSecurityTokenFromUrl(actionUrl) || '');
        const csrfRef = String(config.csrfRef || window.location.href.split('#')[0] || '');
        body.set('dir', dir);
        if (csrfToken !== '') {
            body.set('_', csrfToken);
        }
        if (csrfRef !== '') {
            body.set('page_url', csrfRef);
        }

        fetchJson(requestUrl, {
            method: 'POST',
            body: body
        }).then((payload) => {
            busyDir = '';
            if (!payload || payload.ok === false) {
                applyPayload(payload || { themes: [] });
                setStatus((payload && payload.message) ? payload.message : '操作失败', 'error');
                return;
            }

            applyPayload(payload, payload.message || (action === 'update' ? '主题已更新。' : '主题已安装。'));
        }).catch((error) => {
            busyDir = '';
            setStatus('操作失败：' + (error && error.message ? error.message : '网络异常'), 'error');
            loadCatalog();
        });
    };

    root.addEventListener('click', (event) => {
        const refresh = event.target.closest('[data-theme-market-refresh]');
        if (refresh) {
            loadCatalog();
            return;
        }

        const toggle = event.target.closest('[data-theme-market-toggle]');
        if (toggle) {
            setCollapsed(!root.classList.contains('is-collapsed'));
            return;
        }

        const coverTrigger = event.target.closest('[data-theme-market-cover-url]');
        if (coverTrigger) {
            event.preventDefault();
            openPreview(coverTrigger.getAttribute('data-theme-market-cover-url'), coverTrigger.getAttribute('data-theme-market-cover-name'), coverTrigger);
            return;
        }

        const actionButton = event.target.closest('[data-theme-market-action]');
        if (!actionButton) {
            return;
        }

        event.preventDefault();
        runAction(actionButton.getAttribute('data-theme-market-action'), actionButton.getAttribute('data-theme-dir'));
    });

    if (searchInput) {
        searchInput.addEventListener('input', () => {
            searchKeyword = String(searchInput.value || '').trim().toLowerCase();
            renderThemes();
        });
    }

    if (preview) {
        preview.addEventListener('click', (event) => {
            const previewClose = event.target.closest('[data-theme-market-preview-close]');
            if (previewClose || event.target === preview) {
                event.preventDefault();
                closePreview();
            }
        });
    }

    if (previewImage) {
        previewImage.addEventListener('load', () => {
            schedulePreviewLayout(currentPreviewLayout === null);
        });
    }

    window.addEventListener('resize', () => {
        schedulePreviewLayout(false);
    });

    if (window.visualViewport && typeof window.visualViewport.addEventListener === 'function') {
        window.visualViewport.addEventListener('resize', () => {
            schedulePreviewLayout(false);
        });
    }

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && preview && preview.classList.contains('is-open')) {
            closePreview();
        }
    });

    setCollapsed(false);

    loadCatalog();
})();
</script>
HTML;
    }

    public static function getSecureActionUrl($path)
    {
        $options = Typecho_Widget::widget('Widget_Options');
        $fallback = Typecho_Common::url($path, isset($options->index) ? $options->index : '/');
        $securityClass = class_exists('Widget_Security') ? 'Widget_Security' : '\\Widget\\Security';

        if (!class_exists($securityClass)) {
            return $fallback;
        }

        try {
            $security = Typecho_Widget::widget($securityClass);
            if (is_object($security) && method_exists($security, 'getIndex')) {
                return (string) $security->getIndex($path);
            }
        } catch (Throwable $e) {
        }

        return $fallback;
    }

    public static function getCurrentRequestUrl()
    {
        $requestClass = class_exists('Typecho_Request') ? 'Typecho_Request' : '\\Typecho\\Request';
        if (class_exists($requestClass) && method_exists($requestClass, 'getInstance')) {
            try {
                $request = $requestClass::getInstance();
                if (is_object($request) && method_exists($request, 'getRequestUrl')) {
                    $requestUrl = (string) $request->getRequestUrl();
                    if ($requestUrl !== '') {
                        return $requestUrl;
                    }
                }
            } catch (Throwable $e) {
            }
        }

        $scheme = 'http';
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
            $scheme = 'https';
        } elseif (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            $scheme = 'https';
        } elseif (!empty($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443') {
            $scheme = 'https';
        }

        $host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : (isset($_SERVER['SERVER_NAME']) ? (string) $_SERVER['SERVER_NAME'] : '');
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';

        return $host !== '' ? ($scheme . '://' . $host . $uri) : $uri;
    }

    public static function getCurrentRequestSecurityToken()
    {
        $securityClass = class_exists('Widget_Security') ? 'Widget_Security' : '\\Widget\\Security';
        if (!class_exists($securityClass)) {
            return '';
        }

        try {
            $security = Typecho_Widget::widget($securityClass);
            if (is_object($security) && method_exists($security, 'getToken')) {
                return (string) $security->getToken(self::getCurrentRequestUrl());
            }
        } catch (Throwable $e) {
        }

        return '';
    }

    public static function getThemeCatalogSourceUrl()
    {
        return self::applyGh1MirrorUrl(self::normalizeGitHubRawUrl(self::THEME_CATALOG_URL));
    }

    public static function applyGh1MirrorUrl($url)
    {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }

        if (stripos($url, self::GH1_MIRROR_PREFIX) === 0) {
            return $url;
        }

        return rtrim(self::GH1_MIRROR_PREFIX, '/') . '/' . ltrim($url, '/');
    }

    public static function normalizeGitHubRawUrl($url)
    {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }

        if (preg_match('#^https://github\.com/([^/]+)/([^/]+)/blob/([^/]+)/(.+)$#i', $url, $matches)) {
            return 'https://raw.githubusercontent.com/' . $matches[1] . '/' . $matches[2] . '/' . $matches[3] . '/' . $matches[4];
        }

        if (preg_match('#^https://github\.com/([^/]+)/([^/]+)/raw/refs/heads/([^/]+)/(.+)$#i', $url, $matches)) {
            return 'https://raw.githubusercontent.com/' . $matches[1] . '/' . $matches[2] . '/' . $matches[3] . '/' . $matches[4];
        }

        return $url;
    }

    public static function normalizeThemeCatalogItem($item)
    {
        if (!is_array($item)) {
            return null;
        }

        $dir = isset($item['dir']) ? trim((string) $item['dir']) : '';
        if ($dir === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $dir)) {
            return null;
        }

        $version = isset($item['version']) ? ltrim(trim((string) $item['version']), 'vV') : '';

        return array(
            'dir' => $dir,
            'name' => isset($item['name']) && trim((string) $item['name']) !== '' ? trim((string) $item['name']) : $dir,
            'description' => isset($item['description']) ? trim((string) $item['description']) : '',
            'version' => $version,
            'cover' => self::normalizeGitHubRawUrl(isset($item['cover']) ? (string) $item['cover'] : ''),
            'zip_url' => self::normalizeGitHubRawUrl(isset($item['zip_url']) ? (string) $item['zip_url'] : ''),
            'github_url' => isset($item['github_url']) ? trim((string) $item['github_url']) : '',
            'demo_url' => isset($item['demo_url']) ? trim((string) $item['demo_url']) : '',
        );
    }

    public static function fetchThemeCatalog()
    {
        $requestedUrl = self::THEME_CATALOG_URL;
        $resolvedUrl = self::getThemeCatalogSourceUrl();
        $response = self::fetchRemoteUrl($resolvedUrl);
        $status = isset($response['status']) ? (int) $response['status'] : 0;
        $body = isset($response['body']) ? trim((string) $response['body']) : '';

        if ($status < 200 || $status >= 300) {
            return array(
                'ok' => false,
                'message' => '远程主题目录请求失败（HTTP ' . $status . '）',
                'requested_url' => $requestedUrl,
                'resolved_url' => $resolvedUrl,
                'themes' => array(),
                'empty' => false,
            );
        }

        if ($body === '') {
            return array(
                'ok' => true,
                'message' => '远程主题目录暂无内容。',
                'requested_url' => $requestedUrl,
                'resolved_url' => $resolvedUrl,
                'themes' => array(),
                'empty' => true,
            );
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return array(
                'ok' => false,
                'message' => '远程 themes.json 不是合法 JSON。',
                'requested_url' => $requestedUrl,
                'resolved_url' => $resolvedUrl,
                'themes' => array(),
                'empty' => false,
            );
        }

        $items = isset($decoded['themes']) && is_array($decoded['themes']) ? $decoded['themes'] : $decoded;
        $themes = array();
        foreach ($items as $item) {
            $normalized = self::normalizeThemeCatalogItem($item);
            if ($normalized === null) {
                continue;
            }

            $themes[$normalized['dir']] = $normalized;
        }

        return array(
            'ok' => true,
            'message' => empty($themes) ? '远程主题目录暂无主题条目。' : '主题目录已更新。',
            'requested_url' => $requestedUrl,
            'resolved_url' => $resolvedUrl,
            'themes' => array_values($themes),
            'empty' => empty($themes),
        );
    }

    public static function buildInstalledThemeOptions(array $themes)
    {
        $options = array();
        foreach ($themes as $theme) {
            $options[] = array(
                'dir' => isset($theme['dir']) ? (string) $theme['dir'] : '',
                'label' => self::getThemeOptionLabel($theme),
            );
        }

        return $options;
    }

    public static function buildThemeMarketPayload($settings = null)
    {
        if ($settings === null) {
            $settings = self::getSettings();
        }

        $allThemes = self::discoverThemes();
        $localThemes = self::getSelectableThemesForUi($allThemes);
        $localMap = array();
        foreach ($localThemes as $theme) {
            $localMap[$theme['dir']] = $theme;
        }

        $catalog = self::fetchThemeCatalog();
        $items = array();
        $seen = array();

        foreach ($catalog['themes'] as $remoteTheme) {
            $dir = $remoteTheme['dir'];
            if (self::isHiddenThemeForUi($dir)) {
                continue;
            }

            $seen[$dir] = true;
            $localTheme = isset($localMap[$dir]) ? $localMap[$dir] : null;
            $localVersion = $localTheme ? ltrim(trim((string) $localTheme['version']), 'vV') : '';
            $remoteVersion = ltrim(trim((string) $remoteTheme['version']), 'vV');
            $installed = $localTheme !== null;
            $needsUpdate = $installed
                && $localVersion !== ''
                && $remoteVersion !== ''
                && version_compare($remoteVersion, $localVersion, '>');

            $statusText = '未安装';
            if ($needsUpdate) {
                $statusText = '本地 v' . $localVersion . '，可更新到 v' . $remoteVersion;
            } elseif ($installed) {
                $statusText = '已安装';
            }

            $items[] = array(
                'dir' => $dir,
                'name' => $remoteTheme['name'],
                'description' => $remoteTheme['description'],
                'cover' => $remoteTheme['cover'],
                'zip_url' => $remoteTheme['zip_url'],
                'github_url' => $remoteTheme['github_url'],
                'demo_url' => $remoteTheme['demo_url'],
                'remote_version' => $remoteVersion,
                'local_version' => $localVersion,
                'installed' => $installed,
                'needs_update' => $needsUpdate,
                'can_install' => !$installed && $remoteTheme['zip_url'] !== '',
                'can_update' => $needsUpdate && $remoteTheme['zip_url'] !== '',
                'local_only' => false,
                'status_text' => $statusText,
            );
        }

        foreach ($localThemes as $theme) {
            if (isset($seen[$theme['dir']])) {
                continue;
            }

            $items[] = array(
                'dir' => $theme['dir'],
                'name' => $theme['name'],
                'description' => $theme['description'],
                'cover' => '',
                'zip_url' => '',
                'github_url' => '',
                'demo_url' => '',
                'remote_version' => '',
                'local_version' => isset($theme['version']) ? (string) $theme['version'] : '',
                'installed' => true,
                'needs_update' => false,
                'can_install' => false,
                'can_update' => false,
                'local_only' => true,
                'status_text' => '仅本地已安装，远程目录未提供该主题',
            );
        }

        $currentSettings = self::synchronizeStoredThemeSettings();

        return array(
            'ok' => true,
            'catalog_ok' => (bool) $catalog['ok'],
            'message' => isset($catalog['message']) ? (string) $catalog['message'] : '主题列表已更新。',
            'catalog_url' => isset($catalog['resolved_url']) ? (string) $catalog['resolved_url'] : self::getThemeCatalogSourceUrl(),
            'themes' => $items,
            'theme_options' => self::buildInstalledThemeOptions($allThemes),
            'selected_theme' => self::getSelectedTheme($currentSettings),
            'theme_configs_json' => isset($currentSettings->bio_theme_configs_json) ? (string) $currentSettings->bio_theme_configs_json : '{}',
            'theme_config_defaults_json' => self::buildThemeConfigJsonDefault($allThemes),
            'theme_config_inputs_html' => self::renderThemeConfigInputsHtml($allThemes, self::getThemeConfigValues($currentSettings)),
        );
    }

    public static function synchronizeStoredThemeSettings($options = null)
    {
        $settings = self::getSettings($options);
        $themes = self::discoverThemes();
        $syncResult = self::synchronizeThemeConfigSettings(self::settingsToArray($settings), $themes);
        if (!empty($syncResult['changed'])) {
            Helper::configPlugin('TypechoBio', $syncResult['settings']);
        }

        return (object) $syncResult['settings'];
    }

    public static function installThemeFromCatalog($dir, $mode = 'install')
    {
        $dir = trim((string) $dir);
        if ($dir === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $dir)) {
            throw new Exception('无效的主题目录标识');
        }

        $catalog = self::fetchThemeCatalog();
        if (empty($catalog['ok'])) {
            throw new Exception(isset($catalog['message']) ? (string) $catalog['message'] : '远程主题目录不可用');
        }

        $remoteTheme = null;
        foreach ($catalog['themes'] as $item) {
            if (isset($item['dir']) && (string) $item['dir'] === $dir) {
                $remoteTheme = $item;
                break;
            }
        }

        if (!$remoteTheme) {
            throw new Exception('远程主题目录中不存在该主题');
        }

        if (trim((string) $remoteTheme['zip_url']) === '') {
            throw new Exception('该主题未提供 zip 下载地址');
        }

        $templatesRoot = __DIR__ . DIRECTORY_SEPARATOR . 'templates';
        $targetDir = $templatesRoot . DIRECTORY_SEPARATOR . $dir;
        $alreadyInstalled = is_dir($targetDir);
        if ($mode === 'install' && $alreadyInstalled) {
            throw new Exception('该主题已安装');
        }
        if ($mode === 'update' && !$alreadyInstalled) {
            throw new Exception('该主题尚未安装，不能更新');
        }

        $response = self::fetchRemoteUrl($remoteTheme['zip_url']);
        $status = isset($response['status']) ? (int) $response['status'] : 0;
        $body = isset($response['body']) ? (string) $response['body'] : '';
        if ($status < 200 || $status >= 300 || $body === '') {
            throw new Exception('主题压缩包下载失败（HTTP ' . $status . '）');
        }

        $tempDir = self::createTemporaryDirectory('typechobio_theme_');
        $zipFile = $tempDir . DIRECTORY_SEPARATOR . 'theme.zip';
        $extractDir = $tempDir . DIRECTORY_SEPARATOR . 'extract';
        self::ensureDirectory($extractDir);

        try {
            if (@file_put_contents($zipFile, $body) === false) {
                throw new Exception('无法写入临时主题压缩包');
            }

            self::extractZipArchiveToDirectory($zipFile, $extractDir);
            $sourceDir = self::locateThemeDirectoryFromExtracted($extractDir, $dir);
            if ($sourceDir === '') {
                throw new Exception('压缩包中未找到合法主题目录（需同时包含 home.php 与 theme.json）');
            }

            if (is_dir($targetDir)) {
                self::removeDirectory($targetDir);
            }

            self::copyDirectory($sourceDir, $targetDir);
        } catch (Throwable $e) {
            self::removeDirectory($tempDir);
            throw $e;
        }

        self::removeDirectory($tempDir);
        self::synchronizeStoredThemeSettings();
    }

    public static function createTemporaryDirectory($prefix)
    {
        $base = rtrim((string) sys_get_temp_dir(), DIRECTORY_SEPARATOR);
        $temp = tempnam($base, (string) $prefix);
        if ($temp === false) {
            throw new Exception('无法创建临时目录');
        }

        if (is_file($temp)) {
            @unlink($temp);
        }

        if (!@mkdir($temp, 0775, true) && !is_dir($temp)) {
            throw new Exception('无法创建临时目录');
        }

        return $temp;
    }

    public static function ensureDirectory($path)
    {
        if (is_dir($path)) {
            return;
        }

        if (!@mkdir($path, 0775, true) && !is_dir($path)) {
            throw new Exception('无法创建目录：' . $path);
        }
    }

    public static function extractZipArchiveToDirectory($zipFile, $destination)
    {
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($zipFile) === true) {
                $ok = $zip->extractTo($destination);
                $zip->close();
                if ($ok) {
                    return;
                }
            }
        }

        if (self::extractZipWithCommand('unzip', array('-qq', '-o', $zipFile, '-d', $destination))) {
            return;
        }

        if (self::extractZipWithCommand('bsdtar', array('-xf', $zipFile, '-C', $destination))) {
            return;
        }

        throw new Exception('当前服务器无法解压 zip，请安装 ZipArchive、unzip 或 bsdtar');
    }

    public static function extractZipWithCommand($binary, array $arguments)
    {
        $commandPath = self::findShellCommand($binary);
        if ($commandPath === '') {
            return false;
        }

        $parts = array($commandPath);
        foreach ($arguments as $argument) {
            $parts[] = escapeshellarg((string) $argument);
        }

        $command = implode(' ', $parts) . ' 2>&1';
        $output = array();
        $code = 1;
        @exec($command, $output, $code);

        return $code === 0;
    }

    public static function findShellCommand($binary)
    {
        if (!function_exists('exec')) {
            return '';
        }

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (in_array('exec', $disabled, true)) {
            return '';
        }

        $output = array();
        $code = 1;
        @exec('command -v ' . escapeshellarg((string) $binary) . ' 2>/dev/null', $output, $code);
        if ($code !== 0 || empty($output[0])) {
            return '';
        }

        return trim((string) $output[0]);
    }

    public static function locateThemeDirectoryFromExtracted($root, $expectedDir)
    {
        $root = rtrim((string) $root, DIRECTORY_SEPARATOR);
        $expectedDir = trim((string) $expectedDir);
        $candidates = array();

        $checkDir = function ($dir) use ($expectedDir, &$candidates) {
            $dir = rtrim((string) $dir, DIRECTORY_SEPARATOR);
            if (!is_file($dir . DIRECTORY_SEPARATOR . 'home.php') || !is_file($dir . DIRECTORY_SEPARATOR . self::THEME_CONFIG_FILE)) {
                return '';
            }

            $meta = self::loadThemeMeta(basename($dir), $dir);
            if ((isset($meta['dir']) && (string) $meta['dir'] === $expectedDir) || basename($dir) === $expectedDir) {
                return $dir;
            }

            $candidates[] = $dir;
            return '';
        };

        $found = $checkDir($root);
        if ($found !== '') {
            return $found;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isDir()) {
                continue;
            }

            $found = $checkDir($fileInfo->getPathname());
            if ($found !== '') {
                return $found;
            }
        }

        return count($candidates) === 1 ? $candidates[0] : '';
    }

    public static function removeDirectory($path)
    {
        if (!is_dir($path)) {
            if (is_file($path)) {
                @unlink($path);
            }
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isDir()) {
                @rmdir($fileInfo->getPathname());
            } else {
                @unlink($fileInfo->getPathname());
            }
        }

        @rmdir($path);
    }

    public static function copyDirectory($source, $destination)
    {
        self::ensureDirectory($destination);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $fileInfo) {
            $relative = substr($fileInfo->getPathname(), strlen(rtrim($source, DIRECTORY_SEPARATOR)) + 1);
            $target = $destination . DIRECTORY_SEPARATOR . $relative;

            if ($fileInfo->isDir()) {
                self::ensureDirectory($target);
                continue;
            }

            self::ensureDirectory(dirname($target));
            if (!@copy($fileInfo->getPathname(), $target)) {
                throw new Exception('复制主题文件失败：' . $relative);
            }
        }
    }

    public static function synchronizeThemeConfigSettings(array $settings, array $themes)
    {
        $normalized = $settings;
        $validThemeDirs = array();
        $validFieldNames = array();

        foreach ($themes as $theme) {
            $dir = $theme['dir'];
            $validThemeDirs[] = $dir;

            foreach ($theme['fields'] as $field) {
                $validFieldNames[self::buildThemeFieldName($dir, $field['key'])] = true;
            }
        }

        $defaultTheme = !empty($validThemeDirs) ? $validThemeDirs[0] : 'default';
        $selectedTheme = isset($normalized['bio_theme']) ? trim((string) $normalized['bio_theme']) : '';
        if ($selectedTheme === '' || !in_array($selectedTheme, $validThemeDirs, true)) {
            $selectedTheme = $defaultTheme;
        }
        $normalized['bio_theme'] = $selectedTheme;

        $changed = !isset($settings['bio_theme']) || (string) $settings['bio_theme'] !== $selectedTheme;

        foreach (array_keys($normalized) as $key) {
            if (strpos((string) $key, 'bio_theme_cfg__') !== 0) {
                continue;
            }

            if (isset($validFieldNames[$key])) {
                continue;
            }

            unset($normalized[$key]);
            $changed = true;
        }

        $normalizedJson = self::buildThemeConfigJsonFromSettings($normalized, $themes);
        $originalJson = isset($settings['bio_theme_configs_json']) ? trim((string) $settings['bio_theme_configs_json']) : '';
        if ($originalJson !== trim((string) $normalizedJson)) {
            $changed = true;
        }
        $normalized['bio_theme_configs_json'] = $normalizedJson;

        $jsonPayload = self::normalizeThemeConfigPayload(json_decode($normalizedJson, true), $themes, $selectedTheme);

        foreach ($themes as $theme) {
            $dir = $theme['dir'];
            foreach ($theme['fields'] as $field) {
                $key = $field['key'];
                $fieldName = self::buildThemeFieldName($dir, $key);
                $default = array_key_exists('default', $field) ? $field['default'] : '';
                $value = $default;

                if (isset($jsonPayload[$dir]) && is_array($jsonPayload[$dir]) && array_key_exists($key, $jsonPayload[$dir])) {
                    $value = $jsonPayload[$dir][$key];
                }

                if ($field['type'] === 'checkbox') {
                    $value = in_array((string) $value, array('1', 'true', 'on', 'yes'), true) ? '1' : '0';
                }

                $value = (string) $value;
                if (!array_key_exists($fieldName, $normalized) || (string) $normalized[$fieldName] !== $value) {
                    $changed = true;
                }

                $normalized[$fieldName] = $value;
            }
        }

        return array(
            'settings' => $normalized,
            'changed' => $changed,
        );
    }

    public static function appendThemeConfigInputs(Typecho_Widget_Helper_Form $form, array $themes, array $themeValues)
    {
        foreach ($themes as $theme) {
            $sectionWrap = new Typecho_Widget_Helper_Layout('li', array(
                'class' => 'typecho-option',
                'data-bio-theme' => (string) $theme['dir'],
                'data-bio-theme-config-row' => '1',
            ));
            $section = new Typecho_Widget_Helper_Layout('p', array('class' => 'description'));
            $sectionHtml = '<strong>' . htmlspecialchars((string) $theme['name'], ENT_QUOTES, 'UTF-8') . '</strong>';
            if (trim((string) $theme['description']) !== '') {
                $sectionHtml .= '<br>' . htmlspecialchars((string) $theme['description'], ENT_QUOTES, 'UTF-8');
            }
            $section->html($sectionHtml);
            $sectionWrap->addItem($section);
            $form->addItem($sectionWrap);

            foreach ($theme['fields'] as $field) {
                $name = self::buildThemeFieldName($theme['dir'], $field['key']);
                $value = isset($themeValues[$theme['dir']][$field['key']]) ? $themeValues[$theme['dir']][$field['key']] : '';

                $label = htmlspecialchars(sprintf('%s / %s', (string) $theme['name'], (string) $field['label']), ENT_QUOTES, 'UTF-8');
                $description = htmlspecialchars((string) $field['description'], ENT_QUOTES, 'UTF-8');

                if ($field['type'] === 'textarea') {
                    $element = new Typecho_Widget_Helper_Form_Element_Textarea($name, null, (string) $value, $label, $description);
                } elseif ($field['type'] === 'select') {
                    $options = is_array($field['options']) ? $field['options'] : array();
                    $element = new Typecho_Widget_Helper_Form_Element_Select($name, $options, (string) $value, $label, $description);
                } elseif ($field['type'] === 'checkbox') {
                    $checked = in_array((string) $value, array('1', 'true', 'on', 'yes'), true) ? array('1') : array();
                    $element = new Typecho_Widget_Helper_Form_Element_Checkbox($name, array('1' => _t('启用')), $checked, $label, $description);
                } else {
                    $element = new Typecho_Widget_Helper_Form_Element_Text($name, null, (string) $value, $label, $description);
                }

                $element->setAttribute('data-bio-theme', (string) $theme['dir']);
                $element->setAttribute('data-bio-theme-config-row', '1');

                $form->addInput($element);
            }
        }
    }

    public static function renderThemeConfigInputsHtml(array $themes, array $themeValues)
    {
        $form = new \Typecho\Widget\Helper\Form(null, \Typecho\Widget\Helper\Form::POST_METHOD);
        self::appendThemeConfigInputs($form, $themes, $themeValues);

        ob_start();
        foreach ($form->getItems() as $item) {
            if ($item instanceof \Typecho\Widget\Helper\Layout) {
                $item->render();
            }
        }
        $html = ob_get_clean();

        return is_string($html) ? $html : '';
    }

    public static function appendStaleThemeConfigPlaceholders(Typecho_Widget_Helper_Form $form, array $settings, array $themes)
    {
        $validFieldNames = array();
        foreach ($themes as $theme) {
            foreach ($theme['fields'] as $field) {
                $validFieldNames[self::buildThemeFieldName($theme['dir'], $field['key'])] = true;
            }
        }

        foreach ($settings as $key => $value) {
            $key = (string) $key;
            if (strpos($key, 'bio_theme_cfg__') !== 0) {
                continue;
            }

            if (isset($validFieldNames[$key])) {
                continue;
            }

            $placeholderValue = '';
            if (is_array($value)) {
                $placeholderValue = !empty($value) ? (string) reset($value) : '';
            } else {
                $placeholderValue = (string) $value;
            }

            $form->addInput(new \Typecho\Widget\Helper\Form\Element\Hidden($key, null, $placeholderValue));
        }
    }

    public static function buildThemeFieldName($themeDir, $fieldKey)
    {
        return 'bio_theme_cfg__' . $themeDir . '__' . $fieldKey;
    }

    public static function renderThemeConfigSyncScript()
    {
        $html = '<script>(function(){';
        $html .= 'function splitName(name){name=String(name||"").replace(/\[\]$/,"" );var p=name.split("__");if(p.length!==3){return null;}if(p[0]!=="bio_theme_cfg"){return null;}return {theme:p[1],key:p[2]};}';
        $html .= 'function readValue(el){if(!el){return "";}if(el.type==="checkbox"){return el.checked?"1":"0";}return el.value;}';
        $html .= 'function build(form){var out={};var all=form.querySelectorAll("[name^=bio_theme_cfg__]");for(var i=0;i<all.length;i++){var el=all[i];var info=splitName(el.name);if(!info){continue;}if(!out[info.theme]){out[info.theme]={};}out[info.theme][info.key]=readValue(el);}return out;}';
        $html .= 'function refresh(form){var select=form.querySelector("select[name=\"bio_theme\"]");if(!select){return;}var selected=String(select.value||"");var rows=form.querySelectorAll("[data-bio-theme]");for(var i=0;i<rows.length;i++){var row=rows[i];var theme=row.getAttribute("data-bio-theme")||"";row.style.display=(theme===selected)?"":"none";}}';
        $html .= 'function bind(form){if(!form||form.getAttribute("data-bio-theme-init")==="1"){return;}form.setAttribute("data-bio-theme-init","1");var select=form.querySelector("select[name=\"bio_theme\"]");if(select){select.addEventListener("change",function(){refresh(form);});}form.addEventListener("submit",function(){var ta=form.querySelector("textarea[name=\"bio_theme_configs_json\"]");if(!ta){return;}ta.value=JSON.stringify(build(form),null,2);});refresh(form);}';
        $html .= 'function findFormBySelect(select){if(!select){return null;}if(select.form){return select.form;}var cur=select;while(cur&&cur!==document.body){if((cur.tagName||"").toLowerCase()==="form"){return cur;}cur=cur.parentNode;}return null;}';
        $html .= 'function init(){var selects=document.querySelectorAll("select[name=\"bio_theme\"]");for(var i=0;i<selects.length;i++){var form=findFormBySelect(selects[i]);if(form){bind(form);}}}';
        $html .= 'if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",init);}else{init();}';
        $html .= 'document.addEventListener("ab:pageload",init);';
        $html .= '})();</script>';
        return $html;
    }
}

if (!class_exists('\\TypechoPlugin\\TypechoBio\\Plugin', false)) {
    class_alias('TypechoBio_Plugin', '\\TypechoPlugin\\TypechoBio\\Plugin');
}
