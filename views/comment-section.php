<?php

if (!isset($typechoBioContext) || !is_array($typechoBioContext)) {
    return;
}

$bioCommentOptions = isset($bioCommentOptions) && is_array($bioCommentOptions)
    ? $bioCommentOptions
    : array();

if (isset($bioCommentOptions['enabled']) && !$bioCommentOptions['enabled']) {
    return;
}

$allowedTags = array('section', 'article', 'div');
$containerTag = isset($bioCommentOptions['container_tag']) ? strtolower((string) $bioCommentOptions['container_tag']) : 'section';
if (!in_array($containerTag, $allowedTags, true)) {
    $containerTag = 'section';
}

$containerClass = isset($bioCommentOptions['container_class'])
    ? trim((string) $bioCommentOptions['container_class'])
    : '';

$sectionId = isset($bioCommentOptions['id']) && trim((string) $bioCommentOptions['id']) !== ''
    ? preg_replace('/[^A-Za-z0-9_-]/', '-', (string) $bioCommentOptions['id'])
    : 'bio-comments';

$title = isset($bioCommentOptions['title']) && trim((string) $bioCommentOptions['title']) !== ''
    ? (string) $bioCommentOptions['title']
    : '评论区';

$titleTag = isset($bioCommentOptions['title_tag']) ? strtolower((string) $bioCommentOptions['title_tag']) : 'h3';
if (!in_array($titleTag, array('h2', 'h3', 'h4'), true)) {
    $titleTag = 'h3';
}

$reloadDelay = isset($bioCommentOptions['reload_delay']) ? (int) $bioCommentOptions['reload_delay'] : 500;
$autoReload = !isset($bioCommentOptions['reload_on_success']) || (bool) $bioCommentOptions['reload_on_success'];
$missingTargetText = isset($bioCommentOptions['target_missing_text']) && trim((string) $bioCommentOptions['target_missing_text']) !== ''
    ? (string) $bioCommentOptions['target_missing_text']
    : '尚未配置评论文章 ID。请在插件设置中填写“评论文章 ID”。';
$emptyListText = isset($bioCommentOptions['empty_list_text']) && trim((string) $bioCommentOptions['empty_list_text']) !== ''
    ? (string) $bioCommentOptions['empty_list_text']
    : '暂无评论';

$commentTarget = isset($typechoBioContext['commentTarget']) && is_array($typechoBioContext['commentTarget'])
    ? $typechoBioContext['commentTarget']
    : null;

$commentList = isset($typechoBioContext['commentList']) && is_array($typechoBioContext['commentList'])
    ? $typechoBioContext['commentList']
    : array();

if (!function_exists('typechoBioRenderCommentTree')) {
    function typechoBioRenderCommentTree(array $nodes, array $authorMap, $depth = 0)
    {
        foreach ($nodes as $node) {
            $coid = isset($node['coid']) ? (int) $node['coid'] : 0;
            $author = isset($node['author']) ? (string) $node['author'] : '';
            $created = isset($node['created']) ? (int) $node['created'] : 0;
            $text = isset($node['text']) ? (string) $node['text'] : '';
            $parent = isset($node['parent']) ? (int) $node['parent'] : 0;
            $parentAuthor = ($parent > 0 && isset($authorMap[$parent])) ? (string) $authorMap[$parent] : '';
            $children = isset($node['children']) && is_array($node['children']) ? $node['children'] : array();
            ?>
            <div class="bio-comment-item" data-comment-coid="<?php echo (int) $coid; ?>" data-comment-author="<?php echo htmlspecialchars($author, ENT_QUOTES, 'UTF-8'); ?>" data-comment-depth="<?php echo (int) $depth; ?>">
                <div class="bio-comment-meta">
                    <span class="bio-comment-author"><?php echo htmlspecialchars($author, ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="bio-comment-time"><?php echo date('Y-m-d H:i', $created); ?></span>
                    <?php if ($parentAuthor !== ''): ?>
                        <span class="bio-comment-reply-label">回复</span>
                        <span class="bio-comment-replyto"><?php echo htmlspecialchars($parentAuthor, ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php endif; ?>
                </div>
                <div class="bio-comment-text"><?php echo nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8')); ?></div>
                <?php if (!empty($children)): ?>
                    <div class="bio-comment-children">
                        <?php typechoBioRenderCommentTree($children, $authorMap, $depth + 1); ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php
        }
    }
}

$commentById = array();
$authorById = array();
foreach ($commentList as $item) {
    $coid = isset($item['coid']) ? (int) $item['coid'] : 0;
    if ($coid <= 0) {
        continue;
    }

    $item['parent'] = isset($item['parent']) ? (int) $item['parent'] : 0;
    $item['children'] = array();
    $commentById[$coid] = $item;
    $authorById[$coid] = isset($item['author']) ? (string) $item['author'] : '';
}

$commentTree = array();
foreach ($commentById as $coid => &$node) {
    $parent = isset($node['parent']) ? (int) $node['parent'] : 0;
    if ($parent > 0 && isset($commentById[$parent])) {
        $commentById[$parent]['children'][] = &$node;
    } else {
        $commentTree[] = &$node;
    }
}
unset($node);

$commentResult = isset($typechoBioContext['commentResult']) ? (string) $typechoBioContext['commentResult'] : '';
$commentMessage = isset($typechoBioContext['commentMessage']) ? (string) $typechoBioContext['commentMessage'] : '';
$commentStatus = isset($_GET['bio_comment_status']) ? (string) $_GET['bio_comment_status'] : '';
$currentRequestUri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
$formId = $sectionId . '-form';
$tipId = $sectionId . '-tip';
?>
<<?php echo $containerTag; ?><?php echo $containerClass !== '' ? ' class="' . htmlspecialchars($containerClass, ENT_QUOTES, 'UTF-8') . '"' : ''; ?> id="<?php echo htmlspecialchars($sectionId, ENT_QUOTES, 'UTF-8'); ?>">
    <<?php echo $titleTag; ?> class="bio-comment-heading"><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></<?php echo $titleTag; ?>>
    <?php if ($commentTarget): ?>
        <?php if ($commentResult === 'ok'): ?>
            <?php if ($commentStatus === 'approved'): ?>
                <p class="bio-comment-status is-success">评论已提交并通过审核</p>
            <?php else: ?>
                <p class="bio-comment-status is-waiting">评论已提交，等待审核</p>
            <?php endif; ?>
        <?php elseif ($commentResult === 'fail'): ?>
            <p class="bio-comment-status is-error">评论提交失败：<?php echo htmlspecialchars($commentMessage, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>

        <form class="bio-comment-form" id="<?php echo htmlspecialchars($formId, ENT_QUOTES, 'UTF-8'); ?>" method="post" action="<?php echo htmlspecialchars((string) $typechoBioContext['commentApi'], ENT_QUOTES, 'UTF-8'); ?>" data-bio-comment-form data-reload-on-success="<?php echo $autoReload ? '1' : '0'; ?>" data-reload-delay="<?php echo (int) $reloadDelay; ?>">
            <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($currentRequestUri, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="ajax" value="1">
            <?php if (empty($typechoBioContext['userLogged'])): ?>
                <div class="bio-comment-form-row">
                    <div class="bio-comment-field">
                        <label>称呼</label>
                        <input type="text" name="author" required>
                    </div>
                    <div class="bio-comment-field">
                        <label>Email</label>
                        <input type="email" name="mail" required>
                    </div>
                    <div class="bio-comment-field">
                        <label>网站</label>
                        <input type="url" name="url" placeholder="http://">
                    </div>
                </div>
            <?php endif; ?>
            <div class="bio-comment-field">
                <label>评论内容</label>
                <textarea name="text" rows="4" required></textarea>
            </div>
            <div class="bio-comment-actions"><button class="bio-comment-submit" type="submit">提交评论</button></div>
            <div id="<?php echo htmlspecialchars($tipId, ENT_QUOTES, 'UTF-8'); ?>" class="bio-comment-tip" data-bio-comment-tip></div>
        </form>

        <?php if (!empty($commentTree)): ?>
            <div class="bio-comment-list">
                <?php typechoBioRenderCommentTree($commentTree, $authorById, 0); ?>
            </div>
        <?php else: ?>
            <p class="bio-comment-empty"><?php echo htmlspecialchars($emptyListText, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>
    <?php else: ?>
        <p class="bio-comment-empty"><?php echo htmlspecialchars($missingTargetText, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>
</<?php echo $containerTag; ?>>