<?php

if (!isset($typechoBioContext) || !is_array($typechoBioContext)) {
    exit;
}

$themeConfig = isset($typechoBioContext['themeConfig']) && is_array($typechoBioContext['themeConfig'])
    ? $typechoBioContext['themeConfig']
    : array();

$heroTitle = isset($themeConfig['hero_title']) && trim((string) $themeConfig['hero_title']) !== ''
    ? (string) $themeConfig['hero_title']
    : 'TypechoBio';

$heroSubtitle = isset($themeConfig['hero_subtitle']) && trim((string) $themeConfig['hero_subtitle']) !== ''
    ? (string) $themeConfig['hero_subtitle']
    : '欢迎使用 TypechoBio';

$showPosts = isset($themeConfig['show_posts']) && in_array((string) $themeConfig['show_posts'], array('1', 'true', 'on', 'yes'), true);

$postsTitle = isset($themeConfig['posts_title']) && trim((string) $themeConfig['posts_title']) !== ''
    ? (string) $themeConfig['posts_title']
    : '最近文章';

$showComments = !isset($themeConfig['show_comments']) || in_array((string) $themeConfig['show_comments'], array('1', 'true', 'on', 'yes'), true);
$commentTitle = isset($themeConfig['comment_title']) && trim((string) $themeConfig['comment_title']) !== ''
    ? (string) $themeConfig['comment_title']
    : '评论区';

$pluginRoot = dirname(dirname(__DIR__));
$siteIcon = isset($typechoBioContext['siteIcon']) && trim((string) $typechoBioContext['siteIcon']) !== ''
    ? (string) $typechoBioContext['siteIcon']
    : '';
$headPreMeta = isset($typechoBioContext['headPreMeta']) ? (string) $typechoBioContext['headPreMeta'] : '';
$headCustomCss = isset($typechoBioContext['headCustomCss']) ? (string) $typechoBioContext['headCustomCss'] : '';
$headCustomJs = isset($typechoBioContext['headCustomJs']) ? (string) $typechoBioContext['headCustomJs'] : '';

$accentColor = '#0f766e';
if (isset($themeConfig['accent_color'])) {
    $accent = (string) $themeConfig['accent_color'];
    if ($accent === 'blue') {
        $accentColor = '#2563eb';
    } elseif ($accent === 'orange') {
        $accentColor = '#ea580c';
    }
}

$commentTarget = isset($typechoBioContext['commentTarget']) && is_array($typechoBioContext['commentTarget'])
    ? $typechoBioContext['commentTarget']
    : null;

$commentList = isset($typechoBioContext['commentList']) && is_array($typechoBioContext['commentList'])
    ? $typechoBioContext['commentList']
    : array();

$commentResult = isset($typechoBioContext['commentResult']) ? (string) $typechoBioContext['commentResult'] : '';
$commentMessage = isset($typechoBioContext['commentMessage']) ? (string) $typechoBioContext['commentMessage'] : '';
$commentStatus = isset($_GET['bio_comment_status']) ? (string) $_GET['bio_comment_status'] : '';

header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <?php if (trim($headPreMeta) !== ''): ?>
        <?php echo $headPreMeta; ?>
    <?php endif; ?>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <?php if ($siteIcon !== ''): ?>
        <link rel="icon" href="<?php echo htmlspecialchars($siteIcon, ENT_QUOTES, 'UTF-8'); ?>">
    <?php endif; ?>
    <title><?php echo htmlspecialchars($typechoBioContext['siteTitle'], ENT_QUOTES, 'UTF-8'); ?> - TypechoBio</title>
    <style>
        :root {
            --bg: #f8f9ff;
            --surface: #ffffff;
            --surface-2: #f2f4ff;
            --line: #d7dcf7;
            --title: #1a1c2b;
            --muted: #60647b;
            --accent: <?php echo htmlspecialchars($accentColor, ENT_QUOTES, 'UTF-8'); ?>;
            --on-accent: #ffffff;
            --shadow: 0 10px 30px rgba(30, 45, 90, 0.08);
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: radial-gradient(circle at 80% -10%, #e4f3ff 0%, transparent 45%), var(--bg); color: var(--title); font-family: "Noto Sans SC", "Segoe UI", -apple-system, BlinkMacSystemFont, sans-serif; }
        .wrap { max-width: 1080px; margin: 0 auto; padding: 28px 16px 42px; }
        .hero {
            background: linear-gradient(145deg, #ecf3ff 0%, #ffffff 62%);
            border: 1px solid var(--line);
            border-radius: 28px;
            padding: 26px;
            box-shadow: var(--shadow);
        }
        .hero h1 { margin: 0; font-size: clamp(24px, 3vw, 34px); line-height: 1.2; letter-spacing: .2px; }
        .hero p { margin: 10px 0 0; color: #375172; font-size: 15px; }
        .welcome { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 16px; }
        .welcome span {
            background: var(--surface-2);
            border: 1px solid var(--line);
            border-radius: 999px;
            padding: 8px 12px;
            font-size: 13px;
            color: #364058;
        }
        .welcome b { color: #15253f; font-weight: 700; }
        .grid { margin-top: 18px; display: grid; gap: 14px; grid-template-columns: repeat(12, minmax(0, 1fr)); }
        .card {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 20px;
            padding: 16px;
            box-shadow: 0 2px 8px rgba(30, 45, 90, 0.03);
        }
        .card h3 { margin: 0 0 8px; font-size: 18px; letter-spacing: .2px; }
        .card--half { grid-column: span 6; }
        .card--full { grid-column: 1/-1; }
        .muted { color: var(--muted); font-size: 13px; }
        ul { margin: 0; padding-left: 18px; }
        li { margin: 6px 0; }
        a { color: var(--accent); text-decoration: none; }
        a:hover { text-decoration: underline; }
        .bio-comment-status.is-success,
        .bio-comment-tip.is-success { color: #166534; }
        .bio-comment-status.is-error,
        .bio-comment-tip.is-error { color: #b91c1c; }
        .bio-comment-status.is-waiting,
        .bio-comment-tip.is-waiting { color: #92400e; }
        .bio-comment-tip.is-pending { color: var(--muted); }
        .bio-comment-empty { color: var(--muted); }
        .bio-comment-item { border-top: 1px dashed var(--line); padding: 12px 0; }
        .bio-comment-item:first-child { border-top: none; padding-top: 0; }
        .bio-comment-children { display: flex; flex-direction: column; gap: 10px; margin-left: 18px; border-left: 2px solid var(--line); padding-left: 14px; }
        .bio-comment-meta { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; font-size: 12px; color: var(--muted); margin-bottom: 6px; }
        .bio-comment-author { color: var(--title); font-weight: 700; }
        .bio-comment-time { color: var(--muted); opacity: .8; font-size: 11px; }
        .bio-comment-reply-label { color: var(--muted); font-size: 11px; }
        .bio-comment-replyto { color: var(--accent); font-weight: 600; }
        .bio-comment-text { font-size: 14px; line-height: 1.8; color: var(--title); font-weight: 500; }
        .bio-comment-actions { margin: 8px 0 0; }
        .bio-comment-reply {
            border: 1px solid var(--line);
            background: var(--surface-2);
            color: #334155;
            border-radius: 999px;
            font-size: 12px;
            padding: 4px 10px;
            cursor: pointer;
        }
        .bio-comment-reply-target {
            margin: 0 0 10px;
            padding: 8px 10px;
            border-radius: 10px;
            background: var(--surface-2);
            color: #334155;
            font-size: 13px;
        }
        .bio-comment-reply-cancel {
            margin-left: 8px;
            border: 0;
            background: transparent;
            color: var(--accent);
            cursor: pointer;
            font-size: 12px;
        }
        .bio-comment-form-row { display: grid; gap: 10px; grid-template-columns: repeat(3, minmax(0, 1fr)); }
        .bio-comment-form-row > * { min-width: 0; }
        .bio-comment-field { margin: 10px 0; min-width: 0; }
        .bio-comment-field label { display: block; font-size: 12px; color: #42506c; margin-bottom: 6px; }
        .bio-comment-form input,
        .bio-comment-form textarea {
            width: 100%;
            display: block;
            box-sizing: border-box;
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 10px 12px;
            font-size: 14px;
            background: #fff;
            transition: border-color .2s ease, box-shadow .2s ease;
        }
        .bio-comment-form input:focus,
        .bio-comment-form textarea:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.15);
        }
        .bio-comment-submit {
            border: none;
            border-radius: 999px;
            padding: 10px 18px;
            background: var(--accent);
            color: var(--on-accent);
            cursor: pointer;
            font-weight: 600;
        }
        .bio-comment-list { max-height: 360px; overflow: auto; padding-right: 6px; }
        .bio-comment-tip { margin-top: 8px; font-size: 13px; }
        @media (max-width: 860px) {
            .card--half { grid-column: 1/-1; }
            .bio-comment-form-row { grid-template-columns: 1fr; }
            .bio-comment-children { margin-left: 10px; padding-left: 10px; }
        }
        .footer {
            margin-top: 18px;
            color: #64748b;
            font-size: 13px;
            text-align: center;
        }
    </style>
    <?php if (trim($headCustomCss) !== ''): ?>
        <style id="typecho-bio-custom-css">
<?php echo $headCustomCss; ?>
        </style>
    <?php endif; ?>
    <?php if (trim($headCustomJs) !== ''): ?>
        <script id="typecho-bio-custom-js">
<?php echo $headCustomJs; ?>
        </script>
    <?php endif; ?>
</head>
<body>
<div class="wrap">
    <section class="hero">
        <h1><?php echo htmlspecialchars($heroTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
        <p>您的域名: <?php echo htmlspecialchars($typechoBioContext['host'], ENT_QUOTES, 'UTF-8'); ?> | <?php echo htmlspecialchars($heroSubtitle, ENT_QUOTES, 'UTF-8'); ?></p>
        <div class="welcome">
            <span>插件：<b><?php echo htmlspecialchars($typechoBioContext['pluginName'], ENT_QUOTES, 'UTF-8'); ?></b></span>
            <span>版本：<b><?php echo htmlspecialchars($typechoBioContext['pluginVersion'], ENT_QUOTES, 'UTF-8'); ?></b></span>
            <span>当前主题：<b><?php echo htmlspecialchars($typechoBioContext['themeKey'], ENT_QUOTES, 'UTF-8'); ?></b></span>
            <span>Github：<a href="<?php echo htmlspecialchars($typechoBioContext['pluginLink'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($typechoBioContext['pluginLink'], ENT_QUOTES, 'UTF-8'); ?></a></span>
        </div>
    </section>

    <section class="grid">
        <?php
        $bioCommentOptions = array(
            'enabled' => $showComments,
            'container_tag' => 'article',
            'container_class' => 'card card--full',
            'id' => 'bio-comments',
            'title' => $commentTitle,
        );
        require $pluginRoot . '/views/comment-section.php';
        ?>

        <?php if ($showPosts): ?>
            <article class="card card--full">
                <h3><?php echo htmlspecialchars($postsTitle, ENT_QUOTES, 'UTF-8'); ?></h3>
                <ul>
                    <?php foreach ($typechoBioContext['posts'] as $item): ?>
                        <li>#<?php echo (int) $item['cid']; ?> <?php echo htmlspecialchars((string) $item['title'], ENT_QUOTES, 'UTF-8'); ?></li>
                    <?php endforeach; ?>
                </ul>
            </article>
        <?php endif; ?>
    </section>
    <footer class="footer">
        <?php echo htmlspecialchars((string) $typechoBioContext['siteTitle'], ENT_QUOTES, 'UTF-8'); ?>
        · Powered by <a href="<?php echo htmlspecialchars((string) $typechoBioContext['pluginLink'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">TypechoBio</a>
    </footer>
</div>
<?php require $pluginRoot . '/views/comment-script.php'; ?>
<?php require $pluginRoot . '/views/console-brand.php'; ?>
</body>
</html>
