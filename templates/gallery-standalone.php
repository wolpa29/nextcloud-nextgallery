<!doctype html>
<html lang="<?php p($_['context']['language']); ?>">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><title>NextGallery</title>
<link rel="stylesheet" href="<?php p($_['context']['assets']); ?>css/main.css?v=<?php p($_['context']['version']); ?>">
<?php include __DIR__.'/session.php'; ?>
</head><body class="nextgallery-standalone">
<?php include __DIR__.'/shell.php'; ?>
<script nonce="<?php p($_['cspNonce']); ?>" defer src="<?php p($_['context']['assets']); ?>js/main.js?v=<?php p($_['context']['version']); ?>"></script>
</body></html>
