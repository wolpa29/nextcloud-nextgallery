<?php
$admin=!empty($_['admin']);
$areas = [
 ['mai-pipeline-status', 'Übersicht', '<path d="M4 19V9m6 10V5m6 14v-7"/>'],
 ['mai-library', 'Dateien', '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>'],
 ['mai-pipeline-settings', 'Verarbeitung', '<path d="M3 6a2 2 0 0 1 2-2h4l2 3h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z"/>'],
 ['mai-pipeline-logs', 'Aktivitäten', '<path d="M8 6h12M8 12h12M8 18h12"/><path d="M4 6h.01M4 12h.01M4 18h.01"/>'],
 ['mai-settings', 'Einstellungen', '<path d="M4 7h6m4 0h6M4 17h10m4 0h2"/><circle cx="12" cy="7" r="2"/><circle cx="16" cy="17" r="2"/>'],
 ['mai-pipeline-maintenance', 'Wartung', '<path d="M14 6a5 5 0 0 0-6 6L3 17a2.8 2.8 0 0 0 4 4l5-5a5 5 0 0 0 6-6l-3 3-4-4Z"/>'],
];
if($admin){$areas=[ $areas[4],['mai-accounts','Benutzer','<path d="M4 20v-3a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v3"/><circle cx="10" cy="6" r="3"/>'], ];}
if(!$admin)$areas=array_values(array_filter($areas,fn($area)=>!in_array($area[0],['mai-settings','mai-pipeline-settings','mai-pipeline-maintenance'],true)));
?>
<div class="mai-shell" data-appearance="<?php p($_['appearance'] ?? 'system'); ?>" data-language="<?php p($_['language'] ?? 'de'); ?>">
 <nav id="app-navigation" class="mai-navigation" aria-label="NextGallery KI-Verarbeitung" data-collapsed="true">
  <div class="mai-nav-header"><a href="#mai-pipeline-status" class="mai-nav-brand"><span class="mai-brand-mark" style="width:30px;height:30px" aria-hidden="true"></span><span class="mai-brand-copy">NextGallery<small><?php p($admin?'Administration':'Meine KI-Daten'); ?></small></span></a><button type="button" id="mai-nav-toggle" aria-expanded="false" aria-controls="mai-navigation-list">Bereiche</button></div>
  <a class="mai-gallery-link" href="<?php p($_['galleryUrl']); ?>">← Galerie</a>
  <?php if($admin): ?><a class="mai-gallery-link" href="<?php p($_['accountUrl']); ?>">Meine Verarbeitung</a><?php endif; ?>
  <?php if(!empty($_['adminUrl'])): ?><a class="mai-gallery-link" href="<?php p($_['adminUrl']); ?>">Administration</a><?php endif; ?>
  <ul id="mai-navigation-list" class="mai-nav-links">
<?php foreach ($areas as [$id, $label, $icon]): ?>
   <li><a href="#<?php p($id); ?>" data-nav-target="<?php p($id); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?php echo $icon; ?></svg><span><?php p($label); ?></span></a></li>
<?php endforeach; ?>
  </ul>
  <div class="mai-nav-footer"><span id="mai-nav-status" class="mai-status-dot">Verbinde …</span><small>NextGallery · <?php p($admin?'Administration':'KI-Verarbeitung'); ?></small></div>
 </nav>
 <div id="app-content" class="mai-app-content">
<?php if($admin){require __DIR__.'/ai-admin.php';}else{require __DIR__.'/ai-user.php';} ?>
 </div>
</div>
