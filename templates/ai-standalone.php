<!doctype html>
<html lang="<?php p($_['language']); ?>">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><title>NextGallery · KI</title>
<?php foreach(['ai-admin','ai-branding','ai-embedded'] as $asset): ?>
<link rel="stylesheet" href="<?php p($_['assets']); ?>css/<?php p($asset); ?>.css?v=<?php p($_['version'].'-'.@filemtime(__DIR__.'/../css/'.$asset.'.css')); ?>">
<?php endforeach; include __DIR__.'/session.php'; ?>
</head><body class="nextgallery-ai-standalone">
<?php include __DIR__.'/ai-index.php'; ?>
<?php foreach(array_merge(['ai-embedded','ai-i18n'],!empty($_['admin'])?['ai-workspace','ai-admin']:['ai-user']) as $asset): ?>
<script nonce="<?php p($_['cspNonce']); ?>" defer src="<?php p($_['assets']); ?>js/<?php p($asset); ?>.js?v=<?php p($_['version'].'-'.@filemtime(__DIR__.'/../js/'.$asset.'.js')); ?>"></script>
<?php endforeach; ?>
</body></html>
