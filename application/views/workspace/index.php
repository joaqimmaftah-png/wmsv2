<?php
$esc = function ($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); };
$modules = isset($workspace_modules) && is_array($workspace_modules) ? $workspace_modules : array();
$links = isset($workspace_links) && is_array($workspace_links) ? $workspace_links : array();
?>
<style>
.workspace-switcher { display:flex; flex-wrap:wrap; gap:8px; margin:0 0 20px; }
.workspace-switcher a {
  display:inline-flex; align-items:center; min-height:36px; padding:7px 12px;
  border:1px solid var(--sep); border-radius:9px; color:var(--t2);
  background:var(--surface); text-decoration:none; font-size:12px; font-weight:600;
}
.workspace-switcher a:hover { color:var(--t1); border-color:var(--blue); }
.workspace-switcher a[aria-current="page"] { color:var(--blue); background:var(--blue-d, rgba(0,113,227,.08)); border-color:var(--blue); }
.workspace-switcher a:focus-visible, .workspace-card:focus-visible { outline:3px solid var(--blue); outline-offset:3px; }
.workspace-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(230px,1fr)); gap:14px; }
.workspace-card {
  display:flex; flex-direction:column; align-items:flex-start; gap:10px; min-height:165px;
  padding:18px; border:1px solid var(--sep); border-radius:12px; background:var(--surface);
  color:var(--t1); text-decoration:none; transition:border-color .15s, transform .15s, box-shadow .15s;
}
.workspace-card:hover { border-color:var(--blue); transform:translateY(-1px); box-shadow:0 8px 24px rgba(0,0,0,.08); }
.workspace-card h2 { margin:0; font-size:15px; font-weight:650; color:var(--t1); }
.workspace-card p { margin:0; color:var(--t3); font-size:12px; line-height:1.55; }
.workspace-card-action { display:flex; align-items:center; gap:5px; margin-top:auto; color:var(--blue); font-size:12px; font-weight:650; }
@media (prefers-reduced-motion:reduce) { .workspace-card { transition:none; } .workspace-card:hover { transform:none; } }
@media print { .workspace-switcher { display:none; } .workspace-card { break-inside:avoid; box-shadow:none; } }
</style>

<div class="mb-4">
  <h1 class="page-heading" style="font-size:18px;"><?= $esc(isset($workspace_title) ? $workspace_title : 'Workspace') ?></h1>
  <div class="cs"><?= $esc(isset($workspace_description) ? $workspace_description : '') ?></div>
</div>

<?php if ($modules): ?>
<nav class="workspace-switcher" aria-label="Workspace sections">
  <?php foreach ($modules as $module):
    $current = isset($workspace_key) && $module['key'] === $workspace_key;
  ?>
  <a href="<?= $esc(base_url($module['route'])) ?>" <?= $current ? 'aria-current="page"' : '' ?>><?= $esc($module['title']) ?></a>
  <?php endforeach; ?>
</nav>
<?php endif; ?>

<section aria-labelledby="workspace-links-title">
  <h2 id="workspace-links-title" class="ct mb-3">Choose a destination</h2>
  <?php if ($links): ?>
  <div class="workspace-grid">
    <?php foreach ($links as $link): ?>
    <a class="workspace-card" href="<?= $esc(base_url($link['route'])) ?>">
      <h2><?= $esc($link['title']) ?></h2>
      <p><?= $esc($link['description']) ?></p>
      <span class="workspace-card-action">Open <?= $esc($link['title']) ?> <span aria-hidden="true">→</span></span>
    </a>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div class="card" role="status" style="padding:16px;">No destinations are available for this role.</div>
  <?php endif; ?>
</section>
