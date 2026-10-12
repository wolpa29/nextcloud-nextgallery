<meta name="nextgallery-requesttoken" content="<?php p($_['requesttoken']); ?>">
<meta name="nextgallery-route-root" content="<?php p(rtrim($_['routeRoot'] ?? $_['context']['base'],'/')); ?>">
<script nonce="<?php p($_['cspNonce']); ?>" defer src="<?php p($_['assets'] ?? $_['context']['assets']); ?>js/session.js?v=<?php p($_['version'] ?? $_['context']['version']); ?>"></script>
