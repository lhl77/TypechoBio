<?php

if (!isset($typechoBioContext) || !is_array($typechoBioContext)) {
    return;
}

$pluginName = isset($typechoBioContext['pluginName']) ? (string) $typechoBioContext['pluginName'] : 'TypechoBio';
$pluginVersion = isset($typechoBioContext['pluginVersion']) ? (string) $typechoBioContext['pluginVersion'] : '';
$pluginLink = isset($typechoBioContext['pluginLink']) ? (string) $typechoBioContext['pluginLink'] : '';
$loadStart = isset($typechoBioContext['loadStart']) ? (float) $typechoBioContext['loadStart'] : 0.0;
$serverCost = $loadStart > 0 ? max(0, (microtime(true) - $loadStart) * 1000) : 0;
?>
<script>
(function () {
    var info = {
        name: <?php echo json_encode($pluginName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        version: <?php echo json_encode($pluginVersion, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        github: <?php echo json_encode($pluginLink, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        serverCost: <?php echo json_encode(round($serverCost, 2), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
    };

    var clientCost = Math.max(0, (typeof performance !== 'undefined' && performance.now) ? performance.now() : 0);
    var total = (Number(info.serverCost) || 0) + clientCost;

    var line = [
        (info.name + (info.version ? (' v' + info.version) : '')),
        'GitHub: ' + (info.github || '-'),
        '页面加载: ' + total.toFixed(2) + ' ms (server ' + (Number(info.serverCost) || 0).toFixed(2) + ' + client ' + clientCost.toFixed(2) + ')'
    ].join(' | ');

    var lineStyle = 'display:inline-block;padding:6px 10px;border-radius:8px;background:#0f172a;color:#e2e8f0;font-weight:600;line-height:1.6;';
    console.log('%c' + line, lineStyle);
})();
</script>
