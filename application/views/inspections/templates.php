<?php
$counts = isset($record_counts) && is_array($record_counts) ? $record_counts : array();
$templates = isset($templates) && is_array($templates) ? $templates : array();
$labels = array('matrix' => 'Matrix', 'compliance' => 'Compliance');
$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
$active_count = 0;
foreach ($templates as $template) if ((int)$template->active === 1) $active_count++;
$total_count = count($templates);
?>
<style>
.it-shell { color:#0F172A; font-family:inherit; max-width:100%; }
.it-head { display:flex; align-items:flex-start; justify-content:space-between; flex-wrap:wrap; gap:14px; margin-bottom:18px; }
.it-head h1 { margin:0; font-size:20px; font-weight:700; letter-spacing:-.02em; }
.it-head p { margin:5px 0 0; color:#64748B; font-size:12.5px; line-height:1.5; }
.it-actions { display:flex; gap:8px; flex-wrap:wrap; }
.it-btn { display:inline-flex; align-items:center; justify-content:center; min-height:36px; padding:7px 12px; border:1px solid #CBD5E1; border-radius:8px; background:#fff; color:#1E293B; font:inherit; font-size:12.5px; font-weight:600; text-decoration:none; cursor:pointer; }
.it-btn:hover { background:#F8FAFC; border-color:#94A3B8; color:#0F172A; text-decoration:none; }
.it-btn-primary { background:#1D4ED8; border-color:#1D4ED8; color:#fff; }
.it-btn-primary:hover { background:#1E40AF; border-color:#1E40AF; color:#fff; }
.it-btn-danger { color:#B91C1C; border-color:#FECACA; }
.it-btn-danger:hover { color:#991B1B; background:#FEF2F2; border-color:#FCA5A5; }
.it-shell :focus-visible { outline:3px solid #1D4ED8; outline-offset:2px; }
.it-notice { padding:10px 14px; border-radius:8px; margin-bottom:16px; font-size:13px; }
.it-notice.ok { background:#ECFDF5; color:#065F46; border:1px solid #A7F3D0; }
.it-notice.error { background:#FEF2F2; color:#991B1B; border:1px solid #FECACA; }
.it-surface { background:#fff; border:1px solid #E2E8F0; border-radius:14px; overflow:hidden; }
.it-tools { display:flex; align-items:end; flex-wrap:wrap; gap:10px; padding:14px; border-bottom:1px solid #E2E8F0; }
.it-tools[hidden], .it-table [hidden], .it-empty[hidden] { display:none!important; }
.it-field { display:flex; flex-direction:column; gap:5px; color:#475569; font-size:11.5px; font-weight:600; }
.it-field.search { flex:1 1 240px; }
.it-field input, .it-field select { width:100%; height:36px; padding:0 10px; border:1px solid #CBD5E1; border-radius:8px; background:#fff; color:#0F172A; font:inherit; font-size:12.5px; }
.it-results { color:#64748B; font-size:12px; padding:8px 0; margin-left:auto; }
.it-scroll { overflow-x:auto; max-width:100%; }
.it-table { width:100%; min-width:830px; border-collapse:collapse; font-size:12.5px; }
.it-table th { padding:10px 12px; text-align:left; background:#F8FAFC; color:#475569; border-bottom:1px solid #E2E8F0; font-size:11px; text-transform:uppercase; letter-spacing:.04em; }
.it-table td { padding:10px 12px; border-bottom:1px solid #F1F5F9; vertical-align:middle; color:#1E293B; }
.it-table tbody tr:hover { background:#F8FAFC; }
.it-table tbody tr:last-child td { border-bottom:0; }
.it-table .it-number { text-align:right; font-variant-numeric:tabular-nums; }
.it-name { font-weight:700; color:#0F172A; } .it-secondary { display:block; margin-top:3px; color:#64748B; font-size:11.5px; }
.it-code { color:#475569; overflow-wrap:anywhere; } .it-badge { display:inline-block; padding:3px 8px; border-radius:999px; font-size:11px; font-weight:700; white-space:nowrap; }
.it-badge.active { background:#DCFCE7; color:#166534; } .it-badge.inactive { background:#F1F5F9; color:#475569; }
.it-row-actions { display:flex; justify-content:flex-end; align-items:center; gap:7px; flex-wrap:wrap; }
.it-row-actions form { display:inline-flex; margin:0; } .it-row-actions .it-btn { min-height:34px; padding:5px 9px; font-size:12px; }
.it-empty { padding:28px 16px; text-align:center; color:#64748B; font-size:13px; }
.it-foot { margin:12px 0; color:#64748B; font-size:12px; line-height:1.5; }
@media(max-width:700px) { .it-field.search { flex-basis:100%; } .it-results { margin-left:0; } .it-head { flex-direction:column; } }
</style>
<div class="it-shell">
  <header class="it-head">
    <div><h1>Inspection Templates</h1><p>Manage bilingual inspection forms. Inactive templates are hidden from the form picker; their existing records remain available.</p></div>
    <div class="it-actions"><a class="it-btn it-btn-primary" href="<?= $h(base_url('inspections/template_edit')) ?>">New template</a><a class="it-btn" href="<?= $h(base_url('inspections')) ?>">All inspection forms</a></div>
  </header>
  <?php if ($this->session->flashdata('success')): ?>
    <div class="it-notice ok" role="status"><?= $h($this->session->flashdata('success')) ?></div>
  <?php endif; ?>
  <?php if ($this->session->flashdata('error')): ?>
    <div class="it-notice error" role="alert"><?= $h($this->session->flashdata('error')) ?></div>
  <?php endif; ?>
  <section class="it-surface" aria-label="Inspection template register">
    <?php if ($templates): ?>
      <div class="it-tools" id="it-tools" hidden>
        <label class="it-field search" for="it-search">Search templates<input type="search" id="it-search" placeholder="Name, code or category" autocomplete="off"></label>
        <label class="it-field" for="it-status">Status<select id="it-status"><option value="">All (<?= $total_count ?>)</option><option value="active">Active (<?= $active_count ?>)</option><option value="inactive">Inactive (<?= $total_count - $active_count ?>)</option></select></label>
        <button class="it-btn" type="button" id="it-reset">Clear filters</button>
        <span class="it-results" id="it-results" role="status" aria-live="polite"></span>
      </div>
      <div class="it-scroll" tabindex="0" role="region" aria-label="Inspection templates; scroll horizontally for actions">
        <table class="it-table" id="it-table">
          <caption class="it-foot" style="text-align:left;padding:0 12px;">All templates, ordered by ID. Record counts include drafts and submitted inspections.</caption>
          <thead><tr><th scope="col">Template</th><th scope="col">Code</th><th scope="col">Category</th><th scope="col">Layout</th><th class="it-number" scope="col">Records</th><th scope="col">Status</th><th scope="col" style="text-align:right">Actions</th></tr></thead>
          <tbody>
          <?php foreach ($templates as $t):
            $records = isset($counts[$t->id]) ? (int)$counts[$t->id] : 0;
            $status = (int)$t->active === 1 ? 'active' : 'inactive';
            $search = implode(' ', array($t->name_en, $t->name_fr, $t->code, $t->category_en, isset($t->category_fr) ? $t->category_fr : ''));
            $url_code = rawurlencode((string)$t->code);
          ?>
            <tr data-template-row data-status="<?= $h($status) ?>" data-search="<?= $h($search) ?>">
              <td><span class="it-name"><?= $h($t->name_en) ?></span><span class="it-secondary" lang="fr"><?= $h($t->name_fr) ?></span></td>
              <td class="it-code"><?= $h($t->code) ?></td>
              <td><?= $h($t->category_en) ?></td>
              <td><?= $h(isset($labels[$t->layout_type]) ? $labels[$t->layout_type] : $t->layout_type) ?></td>
              <td class="it-number"><?= $records ?></td>
              <td><span class="it-badge <?= $h($status) ?>"><?= $status === 'active' ? 'Active' : 'Inactive' ?></span></td>
              <td><div class="it-row-actions">
                <a class="it-btn" href="<?= $h(base_url('inspections/fill/' . $url_code . '/en')) ?>" aria-label="Fill <?= $h($t->name_en) ?>">Fill</a>
                <a class="it-btn" href="<?= $h(base_url('inspections/records/' . $url_code)) ?>" aria-label="Records for <?= $h($t->name_en) ?>">Records</a>
                <a class="it-btn" href="<?= $h(base_url('inspections/template_edit/' . (int)$t->id)) ?>" aria-label="Edit <?= $h($t->name_en) ?>">Edit</a>
                <?php if ($records === 0): ?>
                  <?= form_open('inspections/template_delete/' . (int)$t->id, array('class' => 'it-delete-form')) ?>
                    <button class="it-btn it-btn-danger" type="submit" aria-label="Delete <?= $h($t->name_en) ?>" onclick="return confirm('Delete this template and its checklist rows? This cannot be undone.');">Delete</button>
                  <?= form_close() ?>
                <?php endif; ?>
              </div></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="it-empty" id="it-empty" hidden>No templates match these filters. Clear the filters to see all templates.</div>
    <?php else: ?>
      <div class="it-empty">No templates yet. Create a template to begin.</div>
    <?php endif; ?>
  </section>
  <p class="it-foot">Templates with inspection records cannot be deleted. Set them inactive in the editor to keep their history available. Scroll horizontally to see all columns and actions.</p>
</div>
<script>
(function () {
  var tools = document.getElementById('it-tools');
  var table = document.getElementById('it-table');
  if (!tools || !table) return;
  var search = document.getElementById('it-search');
  var status = document.getElementById('it-status');
  var results = document.getElementById('it-results');
  var empty = document.getElementById('it-empty');
  var reset = document.getElementById('it-reset');
  var rows = Array.prototype.slice.call(table.querySelectorAll('[data-template-row]'));
  function update() {
    var term = search.value.trim().toLocaleLowerCase();
    var choice = status.value;
    var matches = 0;
    rows.forEach(function (row) {
      var found = (!term || row.getAttribute('data-search').toLocaleLowerCase().indexOf(term) !== -1) && (!choice || row.getAttribute('data-status') === choice);
      row.hidden = !found;
      if (found) matches++;
    });
    results.textContent = matches + ' of ' + rows.length + ' templates shown';
    empty.hidden = matches !== 0;
  }
  search.addEventListener('input', update);
  status.addEventListener('change', update);
  reset.addEventListener('click', function () { search.value = ''; status.value = ''; update(); search.focus(); });
  update();
  tools.hidden = false;
})();
</script>
