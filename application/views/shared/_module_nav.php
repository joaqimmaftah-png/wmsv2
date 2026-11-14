<?php
defined('BASEPATH') OR exit('No direct script access allowed');
/** @var array $module_nav_items; @var string $module_nav_active; @var string $module_nav_label */
$module_nav_role = isset($role_id) ? (int)$role_id : 0;
?>
<nav class="module-nav no-print" aria-label="<?= htmlspecialchars((string)$module_nav_label, ENT_QUOTES, 'UTF-8') ?>">
  <?php foreach ($module_nav_items as $module_nav_item):
    if (!wms_nav_link_allowed($module_nav_item['path'], $module_nav_role)) continue;
    $module_nav_current = ($module_nav_active === $module_nav_item['key']); ?>
  <a href="<?= htmlspecialchars((string)base_url($module_nav_item['path']), ENT_QUOTES, 'UTF-8') ?>"<?= $module_nav_current ? ' aria-current="page"' : '' ?>><?= htmlspecialchars((string)$module_nav_item['label'], ENT_QUOTES, 'UTF-8') ?></a>
  <?php endforeach; ?>
</nav>
