<?php

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

Typecho_Widget::widget('Widget_Options')->to($options);
Typecho_Widget::widget('Widget_User')->to($user);

if (!$user->pass('administrator', true)) {
    die('Permission denied');
}

$settings = TypechoBio_Plugin::getSettings($options);
$hosts = isset($settings->bio_hosts) ? (string) $settings->bio_hosts : '';
$theme = isset($settings->bio_theme) ? (string) $settings->bio_theme : 'default';
$themeJson = isset($settings->bio_theme_configs_json) ? (string) $settings->bio_theme_configs_json : '{}';
$commentCid = isset($settings->bio_comment_cid) ? (string) $settings->bio_comment_cid : '0';
$configUrl = $options->adminUrl('options-plugin.php?config=TypechoBio');

include 'header.php';
include 'menu.php';
?>
<div class="main">
    <div class="body container">
        <?php include 'page-title.php'; ?>
        <div class="typecho-page-main">
            <div class="col-mb-12 typecho-list">
                <div class="typecho-list-operate clearfix">
                    <h2>TypechoBio</h2>
                </div>
                <div class="typecho-table-wrap" style="padding:16px;">
                    <p><strong>Bio Host Rules:</strong> <?php echo nl2br(htmlspecialchars($hosts, ENT_QUOTES, 'UTF-8')); ?></p>
                    <p><strong>Bio Entry:</strong> templates/index.php (固定)</p>
                    <p><strong>Active Theme:</strong> <?php echo htmlspecialchars($theme, ENT_QUOTES, 'UTF-8'); ?></p>
                    <p><strong>Comment CID:</strong> <?php echo htmlspecialchars($commentCid, ENT_QUOTES, 'UTF-8'); ?></p>
                    <p><strong>Theme JSON:</strong></p>
                    <pre style="max-height:220px;overflow:auto;background:#f6f8fa;padding:8px;border:1px solid #eee;white-space:pre-wrap;"><?php echo htmlspecialchars($themeJson, ENT_QUOTES, 'UTF-8'); ?></pre>
                    <p><strong>注意：</strong>该页面是只读诊断面板，不提供保存功能。</p>
                    <p>请到插件配置页修改并保存：<a href="<?php echo htmlspecialchars($configUrl, ENT_QUOTES, 'UTF-8'); ?>">TypechoBio 插件设置</a></p>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include 'copyright.php'; ?>
<?php include 'common-js.php'; ?>
<?php include 'footer.php'; ?>
