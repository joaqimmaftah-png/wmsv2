<?php
$t         = isset($template) ? $template : (object) array();
$tid       = isset($t->id) ? (int)$t->id : 0;
$is_edit   = $tid > 0;
$errors    = isset($errors) ? $errors : array();
$items     = isset($items) ? $items : array();
$layout    = isset($t->layout_type) ? $t->layout_type : 'compliance';
$columns_j = isset($t->columns_json) ? (string)$t->columns_json : '';
$active    = !isset($t->active) ? 1 : (int)$t->active;
$records   = isset($record_count) ? (int)$record_count : 0;

$val = function ($key, $default = '') use ($t) {
    return isset($t->$key) ? (string)$t->$key : $default;
};
?>
<div style="max-width:1150px;margin:0 auto;padding:24px 24px 60px;">

  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:18px;">
    <div>
      <div style="font-size:22px;font-weight:700;color:var(--t1);">
        <?= $is_edit ? 'Edit Inspection Template' : 'New Inspection Template' ?>
      </div>
      <div style="font-size:12.5px;color:var(--t2);margin-top:2px;">
        <?= $is_edit
            ? htmlspecialchars((string)($val('code'))) . ' — ' . $records . ' submitted record(s)'
            : 'Create a bilingual checklist; it appears on the Inspection Forms screen immediately.' ?>
      </div>
    </div>
    <a href="<?= base_url('inspections/templates') ?>"
       style="padding:9px 14px;border-radius:8px;font-size:12.5px;text-decoration:none;color:var(--t2);background:var(--surface);border:1px solid var(--sep);">← Templates</a>
  </div>

  <?php if (!empty($errors)): ?>
  <div style="padding:12px 16px;border-radius:10px;background:var(--red-fill);border:1px solid rgba(192,57,43,0.2);margin-bottom:16px;">
    <div style="font-size:12.5px;font-weight:700;color:var(--red);margin-bottom:6px;">Nothing was saved:</div>
    <ul style="margin:0;padding-left:18px;font-size:12.5px;color:var(--red);">
      <?php foreach ($errors as $e): ?><li><?= htmlspecialchars((string)($e)) ?></li><?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>

  <form method="post" action="<?= base_url('inspections/template_edit' . ($is_edit ? '/' . $tid : '')) ?>">
    <?= form_hidden('csrf_token_wms', $this->security->get_csrf_hash()) ?>

    <div class="card" style="padding:24px;margin-bottom:18px;">
      <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--t3);margin-bottom:14px;">Template</div>
      <div class="form-grid cols-2" style="gap:14px;">
        <div>
          <label class="form-label">Code *</label>
          <input type="text" name="code" class="form-control" required maxlength="50"
                 value="<?= htmlspecialchars((string)($val('code'))) ?>" placeholder="e.g. excavator">
          <div style="font-size:10.5px;color:var(--t3);margin-top:4px;">Lower-case letters, digits and underscores. Used in URLs and must be unique.</div>
        </div>
        <div>
          <label class="form-label">Category (English / French)</label>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
            <input type="text" name="category_en" class="form-control" maxlength="100"
                   value="<?= htmlspecialchars((string)($val('category_en', 'General'))) ?>" placeholder="Heavy Equipment">
            <input type="text" name="category_fr" class="form-control" maxlength="100"
                   value="<?= htmlspecialchars((string)($val('category_fr', 'Général'))) ?>" placeholder="Engins lourds">
          </div>
        </div>
        <div>
          <label class="form-label">Name (English) *</label>
          <input type="text" name="name_en" class="form-control" required maxlength="255"
                 value="<?= htmlspecialchars((string)($val('name_en'))) ?>" placeholder="Excavator Inspection Form">
        </div>
        <div>
          <label class="form-label">Name (French) *</label>
          <input type="text" name="name_fr" class="form-control" required maxlength="255"
                 value="<?= htmlspecialchars((string)($val('name_fr'))) ?>" placeholder="Formulaire d’inspection Excavatrice">
        </div>
        <div>
          <label class="form-label">Layout</label>
          <select name="layout_type" id="layout-type" class="form-select" onchange="toggleColumns()">
            <option value="compliance" <?= $layout === 'compliance' ? 'selected' : '' ?>>Compliance — one Yes / No / N/A column</option>
            <option value="matrix" <?= $layout === 'matrix' ? 'selected' : '' ?>>Matrix — one column per equipment type</option>
          </select>
        </div>
        <div>
          <label class="form-label">Meter label (EN / FR)</label>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
            <input type="text" name="meter_label_en" class="form-control" maxlength="20" value="<?= htmlspecialchars((string)($val('meter_label_en', 'SMR'))) ?>">
            <input type="text" name="meter_label_fr" class="form-control" maxlength="20" value="<?= htmlspecialchars((string)($val('meter_label_fr', 'SMR'))) ?>">
          </div>
        </div>
      </div>

      <div id="columns-box" style="margin-top:14px;<?= $layout === 'matrix' ? '' : 'display:none;' ?>">
        <label class="form-label">Matrix columns (JSON) *</label>
        <textarea name="columns_json" class="form-control" rows="4" spellcheck="false"
                  style="font-family:ui-monospace,monospace;font-size:11.5px;"><?= htmlspecialchars((string)($columns_j)) ?></textarea>
        <div style="font-size:10.5px;color:var(--t3);margin-top:4px;">
          Array of <code>{"key","label_en","label_fr"}</code> objects, e.g.
          <code>[{"key":"H","label_en":"Excavator","label_fr":"Excavatrice"}]</code>.
          The key is what the fill form writes for that column.
        </div>
      </div>

      <div style="margin-top:16px;display:flex;align-items:center;gap:10px;">
        <input type="checkbox" name="active" value="1" id="tpl-active" style="width:16px;height:16px;" <?= $active ? 'checked' : '' ?>>
        <label for="tpl-active" style="font-size:12.5px;color:var(--t2);">Active — show this template on the Inspection Forms screen</label>
      </div>
    </div>

    <div class="card" style="padding:24px;margin-bottom:18px;">
      <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:12px;">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--t3);">Checklist rows</div>
        <div style="display:flex;gap:8px;">
          <button type="button" class="btn btn-ghost btn-sm" onclick="addRow('section')">+ Section</button>
          <button type="button" class="btn btn-ghost btn-sm" onclick="addRow('item')">+ Item</button>
        </div>
      </div>

      <?php if (isset($this->Inspection_model) && !$this->Inspection_model->supports_item_priority()): ?>
      <div style="background:var(--amber-fill,#fff6e5);border:1px solid var(--amber,#e0a800);border-radius:var(--r2);padding:10px 12px;margin-bottom:12px;font-size:11.5px;color:var(--t2);">
        <strong>Severity is not stored yet.</strong> This database predates the <code>inspection_items.priority</code>
        column, so the dropdown below falls back to the category rule. Run the <code>ALTER TABLE</code> in
        <code>README.md</code> §3 to enable it.
      </div>
      <?php endif; ?>

      <div style="overflow-x:auto;">
        <table class="tbl" id="items-table">
          <thead>
            <tr>
              <th style="width:90px;">Type</th>
              <th style="width:60px;">No.</th>
              <th style="width:110px;">Category EN</th>
              <th style="width:110px;">Category FR</th>
              <th style="width:104px;">Severity</th>
              <th>Text — English</th>
              <th>Text — French</th>
              <th style="width:90px;">N/A cols</th>
              <th style="width:44px;"></th>
            </tr>
          </thead>
          <tbody id="items-body">
            <?php
            $idx = 0;
            if (empty($items)) {
                // one blank item to start a new template
                $items = array((object) array('id' => 0, 'row_type' => 'item', 'item_no' => 1,
                    'category_en' => '', 'category_fr' => '', 'text_en' => '', 'text_fr' => '', 'na_columns' => ''));
            }
            foreach ($items as $it):
              $row_type = isset($it->row_type) ? $it->row_type : 'item';
              $is_section = ($row_type === 'section');
              $iid = isset($it->id) ? (int)$it->id : 0;
            ?>
            <tr data-row-type="<?= $is_section ? 'section' : 'item' ?>" class="<?= $is_section ? 'tpl-section-row' : '' ?>">
              <td>
                <input type="hidden" name="items[<?= $idx ?>][id]" value="<?= $iid ?>">
                <select name="items[<?= $idx ?>][row_type]" class="form-select" style="font-size:11.5px;padding:4px 6px;">
                  <option value="item" <?= $is_section ? '' : 'selected' ?>>Item</option>
                  <option value="section" <?= $is_section ? 'selected' : '' ?>>Section</option>
                </select>
              </td>
              <td><input type="number" name="items[<?= $idx ?>][item_no]" class="form-control" style="font-size:11.5px;padding:4px 6px;" value="<?= $is_section ? '' : htmlspecialchars((string)(isset($it->item_no) ? $it->item_no : '')) ?>" <?= $is_section ? 'disabled' : '' ?>></td>
              <td><input type="text" name="items[<?= $idx ?>][category_en]" class="form-control" style="font-size:11.5px;padding:4px 6px;" value="<?= $is_section ? '' : htmlspecialchars((string)(isset($it->category_en) ? $it->category_en : '')) ?>" placeholder="No-Go" <?= $is_section ? 'disabled' : '' ?>></td>
              <td><input type="text" name="items[<?= $idx ?>][category_fr]" class="form-control" style="font-size:11.5px;padding:4px 6px;" value="<?= $is_section ? '' : htmlspecialchars((string)(isset($it->category_fr) ? $it->category_fr : '')) ?>" placeholder="Arrêt" <?= $is_section ? 'disabled' : '' ?>></td>
              <td>
                <?php $prio = (!$is_section && isset($it->priority)) ? strtoupper((string)$it->priority) : ''; ?>
                <select name="items[<?= $idx ?>][priority]" class="form-select" style="font-size:11.5px;padding:4px 6px;" title="Severity a failed item is escalated to" <?= $is_section ? 'disabled' : '' ?>>
                  <option value="" <?= $prio === '' ? 'selected' : '' ?>>Auto</option>
                  <option value="P1" <?= $prio === 'P1' ? 'selected' : '' ?>>P1 · No-Go</option>
                  <option value="P2" <?= $prio === 'P2' ? 'selected' : '' ?>>P2 · Go &amp; Fix</option>
                  <option value="P3" <?= $prio === 'P3' ? 'selected' : '' ?>>P3 · Defect</option>
                </select>
              </td>
              <td><input type="text" name="items[<?= $idx ?>][text_en]" class="form-control" style="font-size:11.5px;padding:4px 6px;" value="<?= htmlspecialchars((string)(isset($it->text_en) ? $it->text_en : '')) ?>" <?= $is_section ? 'placeholder="Section heading (English)"' : 'placeholder="Checklist item (English)"' ?>></td>
              <td><input type="text" name="items[<?= $idx ?>][text_fr]" class="form-control" style="font-size:11.5px;padding:4px 6px;" value="<?= htmlspecialchars((string)(isset($it->text_fr) ? $it->text_fr : '')) ?>" <?= $is_section ? 'placeholder="Titre de section (français)"' : 'placeholder="Point de contrôle (français)"' ?>></td>
              <td><input type="text" name="items[<?= $idx ?>][na_columns]" class="form-control" style="font-size:11.5px;padding:4px 6px;" value="<?= $is_section ? '' : htmlspecialchars((string)(isset($it->na_columns) ? $it->na_columns : '')) ?>" placeholder="H,J" <?= $is_section ? 'disabled' : '' ?>></td>
              <td style="text-align:center;">
                <button type="button" class="btn btn-ghost btn-sm" title="Remove this row"
                        style="padding:2px 8px;color:var(--red);" onclick="removeRow(this)">×</button>
              </td>
            </tr>
            <?php $idx++; endforeach; ?>
          </tbody>
        </table>
      </div>
      <div style="font-size:10.5px;color:var(--t3);margin-top:8px;">
        Rows are saved in the order shown. A row used by an already-submitted inspection cannot be
        removed — the save keeps it and tells you so.<br>
        <strong>Severity</strong>: a failed item opens a defect at this priority. <em>Auto</em> keeps the
        old rule (P1 when the category says No-Go, P2 for Go &amp; Fix, P3 otherwise); pick a code to make
        the escalation explicit and independent of the category wording.
      </div>
    </div>

    <div style="display:flex;justify-content:flex-end;gap:10px;">
      <a href="<?= base_url('inspections/templates') ?>" class="btn btn-ghost">Cancel</a>
      <button type="submit" class="btn btn-primary">Save Template</button>
    </div>
  </form>
</div>

<style>
.tpl-section-row td { background:var(--g2); }
</style>

<script>
var rowIndex = <?= (int)$idx ?>;

function toggleColumns() {
  var layout = document.getElementById('layout-type').value;
  document.getElementById('columns-box').style.display = (layout === 'matrix') ? '' : 'none';
}

function rowHtml(type, index) {
  var isSection = (type === 'section');
  var dis = isSection ? ' disabled' : '';
  return '' +
    '<td>' +
      '<input type="hidden" name="items[' + index + '][id]" value="0">' +
      '<select name="items[' + index + '][row_type]" class="form-select" style="font-size:11.5px;padding:4px 6px;">' +
        '<option value="item"' + (isSection ? '' : ' selected') + '>Item</option>' +
        '<option value="section"' + (isSection ? ' selected' : '') + '>Section</option>' +
      '</select>' +
    '</td>' +
    '<td><input type="number" name="items[' + index + '][item_no]" class="form-control" style="font-size:11.5px;padding:4px 6px;" value=""' + dis + '></td>' +
    '<td><input type="text" name="items[' + index + '][category_en]" class="form-control" style="font-size:11.5px;padding:4px 6px;" value="" placeholder="No-Go"' + dis + '></td>' +
    '<td><input type="text" name="items[' + index + '][category_fr]" class="form-control" style="font-size:11.5px;padding:4px 6px;" value="" placeholder="Arrêt"' + dis + '></td>' +
    '<td><select name="items[' + index + '][priority]" class="form-select" style="font-size:11.5px;padding:4px 6px;"' + dis + '>' +
      '<option value="">Auto</option>' +
      '<option value="P1">P1 &middot; No-Go</option>' +
      '<option value="P2">P2 &middot; Go &amp; Fix</option>' +
      '<option value="P3">P3 &middot; Defect</option>' +
    '</select></td>' +
    '<td><input type="text" name="items[' + index + '][text_en]" class="form-control" style="font-size:11.5px;padding:4px 6px;" value="" placeholder="' + (isSection ? 'Section heading (English)' : 'Checklist item (English)') + '"></td>' +
    '<td><input type="text" name="items[' + index + '][text_fr]" class="form-control" style="font-size:11.5px;padding:4px 6px;" value="" placeholder="' + (isSection ? 'Titre de section (français)' : 'Point de contrôle (français)') + '"></td>' +
    '<td><input type="text" name="items[' + index + '][na_columns]" class="form-control" style="font-size:11.5px;padding:4px 6px;" value="" placeholder="H,J"' + dis + '></td>' +
    '<td style="text-align:center;">' +
      '<button type="button" class="btn btn-ghost btn-sm" title="Remove this row" style="padding:2px 8px;color:var(--red);" onclick="removeRow(this)">&times;</button>' +
    '</td>';
}

function addRow(type) {
  var tbody = document.getElementById('items-body');
  var tr = document.createElement('tr');
  tr.setAttribute('data-row-type', type);
  if (type === 'section') tr.className = 'tpl-section-row';
  tr.innerHTML = rowHtml(type, rowIndex++);
  tbody.appendChild(tr);
  var input = tr.querySelector('input[name$="[text_en]"]');
  if (input) input.focus();
}

function removeRow(btn) {
  var tr = btn.closest('tr');
  if (tr) tr.parentNode.removeChild(tr);
}
</script>
