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
/* Scoped to this register; follows the attendance timesheet's table shell. */
.it-shell { color:var(--t1); font-family:inherit; max-width:100%; }
.it-head { display:flex; align-items:flex-start; justify-content:space-between; flex-wrap:wrap; gap:16px; margin-bottom:20px; }
.it-head h1 { margin:0; color:var(--t1); font-size:24px; font-weight:700; letter-spacing:-.025em; line-height:1.2; }
.it-head p { margin:3px 0 0; color:var(--t2); font-size:13px; line-height:1.5; }
.it-actions { display:flex; gap:8px; flex-wrap:wrap; }
.it-btn { display:inline-flex; align-items:center; justify-content:center; min-height:36px; padding:7px 12px; border:1px solid var(--sep-opaque); border-radius:var(--r-sm); background:var(--surface); color:var(--t1); font:inherit; font-size:12.5px; font-weight:600; text-decoration:none; cursor:pointer; }
.it-btn:hover { background:var(--fill); border-color:#b8b8bf; color:var(--t1); text-decoration:none; }
.it-btn-primary { background:var(--blue); border-color:var(--blue); color:#fff; }
.it-btn-primary:hover { background:#005bb8; border-color:#005bb8; color:#fff; }
.it-btn-danger { color:#B91C1C; border-color:#FECACA; }
.it-btn-danger:hover { color:#991B1B; background:#FEF2F2; border-color:#FCA5A5; }
.it-shell :focus-visible { outline:3px solid var(--blue); outline-offset:2px; }
.it-notice { padding:10px 14px; border-radius:8px; margin-bottom:16px; font-size:13px; }
.it-notice.ok { background:#ECFDF5; color:#065F46; border:1px solid #A7F3D0; }
.it-notice.error { background:#FEF2F2; color:#991B1B; border:1px solid #FECACA; }
.it-surface { background:var(--surface); border:1px solid var(--sep); border-radius:var(--r-lg); box-shadow:var(--shadow-card); overflow:visible; }
.it-tools { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; padding:10px 16px; border-bottom:1px solid var(--sep); }
.it-tools[hidden], .it-table [hidden], .it-empty[hidden] { display:none!important; }
.it-tabs { display:inline-flex; align-items:center; gap:4px; flex-wrap:wrap; }
.it-tab { display:inline-flex; align-items:center; gap:6px; min-height:36px; padding:6px 11px; border:1px solid transparent; border-radius:var(--r-sm); background:transparent; color:var(--t2); font:inherit; font-size:12.5px; font-weight:500; cursor:pointer; }
.it-tab:hover { background:var(--fill); color:var(--t1); }
.it-tab.is-active { background:var(--blue-lt); color:var(--blue); font-weight:600; border-color:rgba(0,113,227,.22); }
.it-tab-count { display:inline-flex; align-items:center; justify-content:center; min-width:20px; height:18px; padding:0 6px; border-radius:var(--r-pill); background:var(--fill); color:var(--t2); font-size:11px; font-weight:600; font-variant-numeric:tabular-nums; }
.it-tab.is-active .it-tab-count { background:var(--blue); color:#fff; }
.it-tools-right { display:flex; align-items:center; flex-wrap:wrap; gap:8px; margin-left:auto; }
.it-field { position:relative; display:inline-flex; align-items:center; }
.it-search-icon { position:absolute; left:9px; width:14px; height:14px; color:var(--t3); pointer-events:none; }
.it-field input { width:235px; max-width:100%; height:36px; padding:0 10px 0 29px; border:1px solid var(--sep-opaque); border-radius:var(--r-sm); background:var(--surface-2); color:var(--t1); font:inherit; font-size:12.5px; }
.it-field input:focus { background:var(--surface); border-color:var(--blue); box-shadow:0 0 0 3px var(--blue-lt); }
.it-sr-only { position:absolute; width:1px; height:1px; padding:0; margin:-1px; overflow:hidden; clip:rect(0,0,0,0); white-space:nowrap; border:0; }
.it-results { color:var(--t2); font-size:12px; }
.it-scroll { width:100%; overflow-x:auto; max-width:100%; }
.it-table { width:100%; min-width:830px; border-collapse:collapse; font-size:13px; }
.it-table th { position:sticky; top:0; z-index:2; padding:9px 11px; text-align:left; white-space:nowrap; background:var(--surface-2); color:var(--t3); border-bottom:1px solid var(--sep-opaque); font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.035em; }
.it-table td { padding:10px 11px; border-bottom:1px solid var(--sep); vertical-align:middle; color:var(--t1); }
.it-table tbody tr:hover { background:#f8f9fb; }
.it-table tbody tr:last-child td { border-bottom:0; }
.it-table .it-number { text-align:right; font-variant-numeric:tabular-nums; }
.it-name { font-weight:600; font-size:13.5px; color:var(--t1); } .it-secondary { display:block; margin-top:2px; color:var(--t3); font-size:11.5px; }
.it-code { color:var(--t2); font-family:var(--mono); font-size:11px; overflow-wrap:anywhere; } .it-badge { display:inline-block; padding:3px 8px; border-radius:999px; font-size:11px; font-weight:700; white-space:nowrap; }
.it-badge.active { background:#DCFCE7; color:#166534; } .it-badge.inactive { background:#F1F5F9; color:#475569; }
.it-row-actions { display:flex; justify-content:flex-end; align-items:center; gap:4px; flex-wrap:wrap; }
.it-row-actions form { display:inline-flex; margin:0; } .it-row-actions .it-btn { min-height:34px; padding:5px 9px; border-radius:6px; font-size:11.5px; font-weight:500; }
.it-row-actions .it-edit { background:var(--blue-lt); border-color:rgba(0,113,227,.25); color:var(--blue); font-weight:600; }
.it-empty { padding:48px 24px; text-align:center; color:var(--t2); font-size:13px; }
.it-foot { margin:12px 0; color:var(--t3); font-size:12px; line-height:1.5; }
@media(max-width:700px) { .it-head { flex-direction:column; } .it-tools-right { width:100%; margin-left:0; } .it-field { flex:1 1 180px; } .it-field input { width:100%; } }
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
        <div class="it-tabs" role="group" aria-label="Filter templates by status">
          <button type="button" class="it-tab is-active" data-status-filter="" aria-pressed="true">All <span class="it-tab-count"><?= $total_count ?></span></button>
          <button type="button" class="it-tab" data-status-filter="active" aria-pressed="false">Active <span class="it-tab-count"><?= $active_count ?></span></button>
          <button type="button" class="it-tab" data-status-filter="inactive" aria-pressed="false">Inactive <span class="it-tab-count"><?= $total_count - $active_count ?></span></button>
        </div>
        <div class="it-tools-right">
          <label class="it-field" for="it-search"><span class="it-sr-only">Search templates</span><svg class="it-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m16 16 5 5"/></svg><input type="search" id="it-search" placeholder="Name, code or category" autocomplete="off"></label>
          <button class="it-btn" type="button" id="it-reset">Clear filters</button>
          <span class="it-results" id="it-results" role="status" aria-live="polite"></span>
        </div>
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
                <a class="it-btn it-edit" href="<?= $h(base_url('inspections/template_edit/' . (int)$t->id)) ?>" aria-label="Edit <?= $h($t->name_en) ?>">Edit</a>
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
  var tabs = Array.prototype.slice.call(tools.querySelectorAll('[data-status-filter]'));
  var choice = '';
  var results = document.getElementById('it-results');
  var empty = document.getElementById('it-empty');
  var reset = document.getElementById('it-reset');
  var rows = Array.prototype.slice.call(table.querySelectorAll('[data-template-row]'));
  function update() {
    var term = search.value.trim().toLocaleLowerCase();
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
  function selectStatus(value) {
    choice = value;
    tabs.forEach(function (tab) {
      var active = tab.getAttribute('data-status-filter') === choice;
      tab.classList.toggle('is-active', active);
      tab.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
    update();
  }
  tabs.forEach(function (tab) { tab.addEventListener('click', function () { selectStatus(tab.getAttribute('data-status-filter')); }); });
  reset.addEventListener('click', function () { search.value = ''; selectStatus(''); search.focus(); });
  update();
  tools.hidden = false;
})();
</script>
