<?php
/**
 * Inspection Records Listing for a Template
 * Vars: $template, $records, $lang
 */
$lang = isset($lang) && $lang === 'fr' ? 'fr' : 'en';
$L = function($en, $fr) use ($lang) { return $lang === 'fr' ? $fr : $en; };

$main_title = ($lang === 'fr') ? $template->name_fr : $template->name_en;
$sub_title  = ($lang === 'fr') ? $template->name_en : $template->name_fr;
?>

<div style="max-width:1100px;margin:0 auto;padding:24px 24px 60px;">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px;margin-bottom:20px;">
    <div>
      <div style="font-size:22px;font-weight:700;color:var(--t1);letter-spacing:-0.2px;">
        <?= htmlspecialchars((string)($main_title)); ?> — <?= $L('Records', 'Enregistrements'); ?>
      </div>
      <div style="font-size:13px;color:var(--t2);margin-top:2px;">
        <?= htmlspecialchars((string)($sub_title)); ?>
      </div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <a href="<?= base_url('inspections/fill/'.$template->code.'/en'); ?>" class="btn-primary" style="padding:8px 14px;border-radius:8px;font-size:12px;text-decoration:none;color:#fff;background:var(--blue);">+ <?= $L('New (English)', 'Nouveau (en anglais)'); ?></a>
      <a href="<?= base_url('inspections/fill/'.$template->code.'/fr'); ?>" class="btn-primary" style="padding:8px 14px;border-radius:8px;font-size:12px;text-decoration:none;color:#fff;background:var(--blue);">+ <?= $L('New (French)', 'Nouveau (en français)'); ?></a>
      <a href="<?= base_url('inspections?lang='.$lang); ?>" class="btn-ghost" style="padding:8px 14px;border-radius:8px;font-size:12px;text-decoration:none;background:var(--surface);border:1px solid var(--sep);color:var(--t2);">
        <?= $L('← All Forms', '← Tous les formulaires'); ?>
      </a>
    </div>
  </div>

  <table style="width:100%;border-collapse:collapse;font-size:12.5px;background:var(--surface);border:1px solid var(--sep);border-radius:10px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.04);">
    <thead>
      <tr style="text-align:left;border-bottom:1px solid var(--sep);background:var(--g2);">
        <th style="padding:12px 14px;color:var(--t2);font-weight:600;"><?= $L('Date', 'Date'); ?></th>
        <th style="padding:12px 14px;color:var(--t2);font-weight:600;"><?= $L('Fleet #', 'N° flotte'); ?></th>
        <th style="padding:12px 14px;color:var(--t2);font-weight:600;"><?= $L('Equipment Type', 'Type d’équipement'); ?></th>
        <th style="padding:12px 14px;color:var(--t2);font-weight:600;"><?= $L('Mechanic', 'Mécanicien'); ?></th>
        <th style="padding:12px 14px;color:var(--t2);font-weight:600;"><?= $L('Language', 'Langue'); ?></th>
        <th style="padding:12px 14px;color:var(--t2);font-weight:600;text-align:right;"><?= $L('Actions', 'Actions'); ?></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($records as $r): ?>
      <tr style="border-bottom:1px solid var(--sep);">
        <td style="padding:12px 14px;font-weight:500;"><?= htmlspecialchars((string)($r->inspection_date)); ?></td>
        <td style="padding:12px 14px;font-weight:600;color:var(--t1);"><?= htmlspecialchars((string)($r->fleet_number)); ?></td>
        <td style="padding:12px 14px;color:var(--t2);"><?= htmlspecialchars((string)($r->equipment_type)); ?></td>
        <td style="padding:12px 14px;"><?= htmlspecialchars((string)($r->mechanic_name)); ?></td>
        <td style="padding:12px 14px;">
          <span style="display:inline-block;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:600;background:var(--g2);color:var(--t2);text-transform:uppercase;">
            <?= htmlspecialchars((string)($r->language)); ?>
          </span>
        </td>
        <td style="padding:12px 14px;text-align:right;">
          <a href="<?= base_url('inspections/view/'.$r->id); ?>" style="color:var(--blue);text-decoration:none;font-size:12px;margin-right:12px;font-weight:500;">
            <?= $L('View', 'Voir'); ?>
          </a>
          <a href="<?= base_url('inspections/edit/'.$r->id); ?>" style="color:var(--blue);text-decoration:none;font-size:12px;margin-right:12px;font-weight:500;">
            <?= $L('Edit', 'Modifier'); ?>
          </a>
          <a href="<?= base_url('inspections/pdf/'.$r->id); ?>" target="_blank" style="color:var(--blue);text-decoration:none;font-size:12px;font-weight:600;">
            PDF
          </a>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($records)): ?>
      <tr>
        <td colspan="6" style="padding:36px 14px;color:var(--t3);text-align:center;font-size:13px;">
          <?= $L('No inspections submitted yet.', 'Aucune inspection soumise pour le moment.'); ?>
        </td>
      </tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>
