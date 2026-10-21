<?php
/**
 * Inspection form — data entry (readonly = FALSE) and read-only view (readonly = TRUE).
 * Renders either a 'matrix' layout (one column per equipment type, e.g. Heavy Equipment
 * Permitting Checklist) or a 'compliance' layout (single Yes/No/N/A column + comment,
 * e.g. LV & Bus Permitting Checklist), driven entirely by inspection_templates / inspection_items.
 *
 * Vars: $template, $items, $lang ('en'|'fr'), $equipment_list, $readonly, $record, $record_items
 */
$L = function($en, $fr) use ($lang) { return $lang === 'fr' ? $fr : $en; };
$editing = isset($editing) ? $editing : FALSE;
$field = function($key) use ($record) {
    return $record && isset($record->$key) ? $record->$key : '';
};
$rname = $lang === 'fr' ? $template->name_fr : $template->name_en;
$is_matrix = $template->layout_type === 'matrix';
$cols = $is_matrix && is_array($template->columns) ? $template->columns : array();
?>
<style>
.insp-wrap{max-width:1180px;margin:0 auto;padding:22px 24px 60px;}
.insp-title{font-size:20px;font-weight:600;color:var(--t1);margin-bottom:2px;}
.insp-sub{font-size:12.5px;color:var(--t2);margin-bottom:18px;}
.insp-header-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:12px;background:var(--surface);border:1px solid var(--sep);border-radius:10px;padding:16px;margin-bottom:20px;}
.insp-header-grid .fld{display:flex;flex-direction:column;}
.insp-table{width:100%;border-collapse:collapse;font-size:12.5px;margin-bottom:4px;}
.insp-table th,.insp-table td{border:1px solid var(--sep-strong);padding:6px 7px;vertical-align:middle;}
.insp-table thead th{background:#ebebeb;color:var(--t1);font-weight:600;font-size:11px;text-align:center;}
.insp-section-row td{background:#d4e3cc;font-weight:700;font-size:12px;color:#1c3a10;}
.insp-item-no{width:28px;text-align:center;color:var(--t2);}
.insp-cat{width:90px;font-size:11px;color:var(--t2);text-align:center;}
.insp-req{min-width:220px;}
.insp-cell{width:56px;text-align:center;}
.insp-cell.na{background:#f2f2f2;color:var(--t4);font-size:10.5px;}
.insp-comment{min-width:140px;}
.tri{display:inline-flex;gap:3px;justify-content:center;}
.tri label{cursor:pointer;font-size:10px;padding:2px 5px;border-radius:5px;border:1px solid var(--sep-strong);color:var(--t2);}
.tri input{display:none;}
.tri input:checked + span{display:inline-block;}
.tri .opt-ok input:checked ~ label,.tri label.sel-ok{background:var(--blue-fill);color:var(--blue);border-color:var(--blue);}
.tri label.sel-fail{background:var(--red-fill);color:var(--red);border-color:var(--red);}
.tri label.sel-na{background:var(--g2);color:var(--t4);}
.cell-input{width:100%;font-size:11.5px;border:1px solid var(--sep);border-radius:5px;padding:3px 5px;}
.sign-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-top:8px;}
.sign-block{background:var(--surface);border:1px solid var(--sep);border-radius:10px;padding:14px;}
.sign-block h4{font-size:12.5px;font-weight:600;margin:0 0 10px;color:var(--t1);}
.sig-wrap{position:relative;background:#fff;border:1px solid var(--sep-strong);border-radius:8px;overflow:hidden;cursor:crosshair;}
.sig-wrap canvas{display:block;width:100%;height:100px;touch-action:none;}
.sig-existing{position:absolute;inset:0;width:100%;height:100%;object-fit:contain;pointer-events:none;}
.sig-ctrls{display:flex;justify-content:flex-end;gap:6px;margin-top:6px;}
.sig-ctrls button{font-size:10.5px;padding:3px 10px;border-radius:14px;border:1px solid var(--sep);background:var(--g2);color:var(--t2);cursor:pointer;}
.gc-box{margin-top:22px;margin-bottom:22px;}
.gc-box textarea{width:100%;min-height:90px;}
.readonly-badge{display:inline-block;font-size:11px;padding:3px 10px;border-radius:12px;background:var(--blue-fill);color:var(--blue);margin-left:10px;vertical-align:middle;}
@media print{ .no-print{display:none !important;} .insp-wrap{padding:0;max-width:none;} }
</style>

<div class="insp-wrap">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
    <div>
      <div class="insp-title"><?= htmlspecialchars((string)($rname)); ?>
        <?php if ($readonly): ?><span class="readonly-badge"><?= $L('Submitted','Soumis'); ?></span><?php endif; ?>
        <?php if ($editing): ?><span class="readonly-badge" style="background:#fdf2d9;color:#8a6100;"><?= $L('Editing','Modification'); ?></span><?php endif; ?>
      </div>
      <?php
        $sub_text = in_array($template->code, array('heavy_equipment', 'lv_bus', 'heavy_vehicle'))
            ? $L('Quarterly Permit Coding', 'Codification trimestrielle des permis')
            : ($lang === 'fr' ? (!empty($template->category_fr) ? $template->category_fr : 'Inspection d’équipement') : (!empty($template->category_en) ? $template->category_en : 'Equipment Inspection'));
      ?>
      <div class="insp-sub"><?= htmlspecialchars((string)($sub_text)); ?></div>
    </div>
    <div class="no-print" style="display:flex;gap:8px;">
      <?php if (!$readonly): ?>
        <?php if (!$editing): ?>
        <a href="<?= base_url('inspections/fill/'.$template->code.'/'.($lang==='fr'?'en':'fr')); ?>" class="btn-ghost" style="padding:8px 14px;border-radius:8px;font-size:12.5px;text-decoration:none;">
          <?= $L('Switch to French', 'Passer à l’anglais'); ?>
        </a>
        <?php endif; ?>
      <?php else: ?>
        <a href="<?= base_url('inspections/edit/'.$record->id); ?>" class="btn-ghost" style="padding:8px 14px;border-radius:8px;font-size:12.5px;text-decoration:none;">✎ <?= $L('Edit','Modifier'); ?></a>
        <a href="<?= base_url('inspections/pdf/'.$record->id); ?>" target="_blank" class="btn-primary" style="padding:8px 16px;border-radius:8px;font-size:12.5px;text-decoration:none;color:#fff;">⬇ <?= $L('Download PDF','Télécharger le PDF'); ?></a>
        <a href="<?= base_url('inspections/records/'.$template->code); ?>" class="btn-ghost" style="padding:8px 14px;border-radius:8px;font-size:12.5px;text-decoration:none;"><?= $L('Back to Records','Retour aux enregistrements'); ?></a>
      <?php endif; ?>
    </div>
  </div>

  <form id="insp-form" method="post" action="<?= base_url('inspections/save'); ?>" <?= $readonly ? 'onsubmit="return false;"' : ''; ?>>
  <?= csrf_field() ?>
    <input type="hidden" name="template_id" value="<?= $template->id; ?>">
    <input type="hidden" name="language" value="<?= $lang; ?>">
    <input type="hidden" name="record_id" value="<?= $editing && $record ? $record->id : ''; ?>">
    <input type="hidden" name="equipment_id" id="fld-equipment-id" value="<?= htmlspecialchars((string)($field('equipment_id'))); ?>">

    <!-- ══ HEADER FIELDS ══ -->
    <div class="insp-header-grid">
      <div class="fld"><label class="form-label"><?= $L('Date','Date'); ?></label>
        <input type="date" name="inspection_date" class="form-control" <?= $readonly?'disabled':''; ?>
          value="<?= htmlspecialchars((string)($record ? $record->inspection_date : date('Y-m-d'))); ?>"></div>

      <div class="fld"><label class="form-label"><?= $L('Fleet Number','Numéro de flotte'); ?></label>
        <input type="text" name="fleet_number" class="form-control" <?= $readonly?'disabled':''; ?>
          value="<?= htmlspecialchars((string)($field('fleet_number'))); ?>"></div>

      <div class="fld"><label class="form-label"><?= $L('Mobile Equipment Type','Type d’équipement mobile'); ?></label>
        <?php if (!$readonly && !empty($equipment_list)): ?>
        <select name="equipment_type" class="form-select" id="equip-type-select">
          <option value=""><?= $L('— Select —','— Sélectionner —'); ?></option>
          <?php foreach ($equipment_list as $eq): ?>
            <option value="<?= htmlspecialchars((string)($eq->registration_no)); ?>"
              data-id="<?= htmlspecialchars((string)($eq->id)); ?>"
              data-smr="<?= htmlspecialchars((string)($eq->current_hours)); ?>"
              data-km="<?= htmlspecialchars((string)(isset($eq->current_km) ? $eq->current_km : '')); ?>"
              data-sn="<?= htmlspecialchars((string)($eq->serial_number)); ?>"
              data-model="<?= htmlspecialchars((string)($eq->model)); ?>"
              data-fleet="<?= htmlspecialchars((string)($eq->registration_no)); ?>"
              <?= ($field('equipment_type') === $eq->registration_no) ? 'selected' : ''; ?>>
              <?= htmlspecialchars((string)($eq->registration_no . ' — ' . $eq->brand . ' ' . $eq->model)); ?>
            </option>
          <?php endforeach; ?>
        </select>
        <?php else: ?>
        <input type="text" name="equipment_type" class="form-control" <?= $readonly?'disabled':''; ?>
          value="<?= htmlspecialchars((string)($field('equipment_type'))); ?>">
        <?php endif; ?>
      </div>

      <div class="fld"><label class="form-label"><?= htmlspecialchars((string)($L($template->meter_label_en, $template->meter_label_fr))); ?></label>
        <input type="text" name="smr" id="fld-smr" class="form-control" <?= $readonly?'disabled':''; ?>
          value="<?= htmlspecialchars((string)($field('smr'))); ?>"></div>

      <div class="fld"><label class="form-label">S/N</label>
        <input type="text" name="serial_number" id="fld-sn" class="form-control" <?= $readonly?'disabled':''; ?>
          value="<?= htmlspecialchars((string)($field('serial_number'))); ?>"></div>

      <div class="fld"><label class="form-label"><?= $L('Model','Modèle'); ?></label>
        <input type="text" name="model" id="fld-model" class="form-control" <?= $readonly?'disabled':''; ?>
          value="<?= htmlspecialchars((string)($field('model'))); ?>"></div>
    </div>

    <!-- ══ CHECKLIST ══ -->
    <table class="insp-table">
      <thead>
        <tr>
          <th style="width:28px;">#</th>
          <th style="width:90px;"><?= $L('Category','Catégorie'); ?></th>
          <th style="min-width:220px;text-align:left;"><?= $L('Check Requirement','Exigence de contrôle'); ?></th>
          <?php if ($is_matrix): ?>
            <?php foreach ($cols as $c): ?>
              <th style="width:56px;"><?= htmlspecialchars((string)($lang==='fr' ? $c['label_fr'] : $c['label_en'])); ?></th>
            <?php endforeach; ?>
            <th style="min-width:130px;"><?= $L('Comment','Commentaire'); ?></th>
          <?php else: ?>
            <th style="width:150px;"><?= $L('Compliance','Conformité'); ?></th>
            <th style="min-width:150px;"><?= $L('Comments by Mechanic','Commentaires du mécanicien'); ?></th>
          <?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($items as $it):
          if ($it->row_type === 'section'): ?>
          <tr class="insp-section-row">
            <td colspan="<?= $is_matrix ? (4 + count($cols)) : 5; ?>">
              <?= htmlspecialchars((string)($lang==='fr' ? $it->text_fr : $it->text_en)); ?>
            </td>
          </tr>
        <?php else:
            $na_cols = $it->na_columns ? explode(',', $it->na_columns) : array();
            $existing = isset($record_items[$it->id]) ? $record_items[$it->id] : array('value'=>array(),'comment'=>'');
        ?>
          <tr>
            <td class="insp-item-no"><?= (int)$it->item_no; ?></td>
            <td class="insp-cat"><?= htmlspecialchars((string)($lang==='fr' ? $it->category_fr : $it->category_en)); ?></td>
            <td class="insp-req"><?= htmlspecialchars((string)($lang==='fr' ? $it->text_fr : $it->text_en)); ?></td>

            <?php if ($is_matrix): ?>
              <?php foreach ($cols as $c):
                $key = $c['key'];
                $is_na = in_array($key, $na_cols);
                $val = isset($existing['value'][$key]) ? $existing['value'][$key] : ($is_na ? 'na' : '');
              ?>
                <td class="insp-cell <?= $is_na?'na':''; ?>">
                  <?php if ($is_na): ?>
                    <?= $L('N/A', 'S/O'); ?>
                  <?php elseif ($readonly): ?>
                    <?= $val === 'ok' ? '✔' : ($val === 'fail' ? '✘' : '—'); ?>
                  <?php else: ?>
                    <div class="tri">
                      <label class="<?= $val==='ok'?'sel-ok':''; ?>" onclick="pickTri(this,'ok')">✔
                        <input type="radio" name="item_<?= $it->id; ?>_<?= $key; ?>" value="ok" <?= $val==='ok'?'checked':''; ?>></label>
                      <label class="<?= $val==='fail'?'sel-fail':''; ?>" onclick="pickTri(this,'fail')">✘
                        <input type="radio" name="item_<?= $it->id; ?>_<?= $key; ?>" value="fail" <?= $val==='fail'?'checked':''; ?>></label>
                    </div>
                  <?php endif; ?>
                </td>
              <?php endforeach; ?>
              <td class="insp-comment">
                <?php if ($readonly): ?>
                  <?= htmlspecialchars((string)($existing['comment'])); ?>
                <?php else: ?>
                  <input type="text" class="cell-input" name="item_<?= $it->id; ?>_comment" value="<?= htmlspecialchars((string)($existing['comment'])); ?>">
                <?php endif; ?>
              </td>

            <?php else:
              $result = isset($existing['value']['result']) ? $existing['value']['result'] : '';
            ?>
              <td class="insp-cell">
                <?php if ($readonly): ?>
                  <?php
                    $res_disp = $result;
                    if ($result === 'Yes') $res_disp = $L('Yes', 'Oui');
                    elseif ($result === 'No') $res_disp = $L('No', 'Non');
                    elseif ($result === 'NA') $res_disp = $L('N/A', 'S/O');
                  ?>
                  <?= $result ? htmlspecialchars((string)($res_disp)) : '—'; ?>
                <?php else: ?>
                  <div class="tri">
                    <label class="<?= $result==='Yes'?'sel-ok':''; ?>" onclick="pickTri(this,'Yes')"><?= $L('Yes','Oui'); ?>
                      <input type="radio" name="item_<?= $it->id; ?>_result" value="Yes" <?= $result==='Yes'?'checked':''; ?>></label>
                    <label class="<?= $result==='No'?'sel-fail':''; ?>" onclick="pickTri(this,'No')"><?= $L('No','Non'); ?>
                      <input type="radio" name="item_<?= $it->id; ?>_result" value="No" <?= $result==='No'?'checked':''; ?>></label>
                    <label class="<?= $result==='NA'?'sel-na':''; ?>" onclick="pickTri(this,'NA')"><?= $L('N/A', 'S/O'); ?>
                      <input type="radio" name="item_<?= $it->id; ?>_result" value="NA" <?= $result==='NA'?'checked':''; ?>></label>
                  </div>
                <?php endif; ?>
              </td>
              <td class="insp-comment">
                <?php if ($readonly): ?>
                  <?= htmlspecialchars((string)($existing['comment'])); ?>
                <?php else: ?>
                  <input type="text" class="cell-input" name="item_<?= $it->id; ?>_comment" value="<?= htmlspecialchars((string)($existing['comment'])); ?>">
                <?php endif; ?>
              </td>
            <?php endif; ?>
          </tr>
        <?php endif; endforeach; ?>
      </tbody>
    </table>

    <!-- ══ GENERAL COMMENTS ══ -->
    <div class="gc-box">
      <label class="form-label"><?= $L('General Comments (missing items, other issues not listed above)','Commentaires généraux (éléments manquants, autres problèmes non listés ci-dessus)'); ?></label>
      <?php if ($readonly): ?>
        <div style="white-space:pre-wrap;font-size:12.5px;color:var(--t1);background:var(--surface);border:1px solid var(--sep);border-radius:8px;padding:10px;">
          <?= htmlspecialchars((string)($record->general_comments ?: '—')); ?>
        </div>
      <?php else: ?>
        <textarea name="general_comments" class="form-control" placeholder="<?= $L('Any missing items or issues not covered above…','Tout élément manquant ou problème non couvert ci-dessus…'); ?>"><?= htmlspecialchars((string)($field('general_comments'))); ?></textarea>
      <?php endif; ?>
    </div>

    <!-- ══ SIGN-OFF ══ -->
    <div class="sign-grid">
      <?php
      $roles = array(
        array('key'=>'mechanic',        'label_en'=>'Mechanic Conducting Inspection', 'label_fr'=>'Mécanicien effectuant l’inspection'),
        array('key'=>'supervisor',      'label_en'=>'Mechanic Supervisor',            'label_fr'=>'Superviseur mécanicien'),
        array('key'=>'operator',        'label_en'=>'Operator / Driver',              'label_fr'=>'Opérateur / Conducteur'),
        array('key'=>'prod_supervisor', 'label_en'=>'Production Supervisor',          'label_fr'=>'Superviseur de production'),
      );
      foreach ($roles as $r):
        $k = $r['key'];
        $is_inspector_name = $k === 'mechanic';
        $name_val = $field($k.'_name');
        if ($is_inspector_name && !$record && isset($inspector_name)) $name_val = $inspector_name;
        $date_val = $field($k.'_date');
        $sig_val  = $record && isset($record->{$k.'_signature'}) ? $record->{$k.'_signature'} : '';
      ?>
      <div class="sign-block">
        <h4><?= htmlspecialchars((string)($L($r['label_en'], $r['label_fr']))); ?></h4>
        <div style="display:grid;grid-template-columns:1fr 120px;gap:10px;margin-bottom:8px;">
          <div>
            <label class="form-label"><?= $is_inspector_name ? $L('Inspector Name', "Nom de l'inspecteur") : $L('Name','Nom'); ?></label>
            <input type="text" name="<?= $k; ?>_name" class="form-control" <?= $readonly?'disabled':''; ?> <?= $is_inspector_name ? 'readonly title="'.htmlspecialchars((string)($L('Taken from your signed-in account','Nom repris de votre compte connecté')), ENT_QUOTES, 'UTF-8').'"' : ''; ?> value="<?= htmlspecialchars((string)($name_val)); ?>">
            <?php if ($is_inspector_name && !$readonly && !$editing): ?>
              <div style="font-size:10.5px;color:var(--t3);margin-top:3px;">
                <?= $L('Taken from your signed-in account.', 'Renseigné depuis votre compte connecté.'); ?>
              </div>
            <?php endif; ?>
          </div>
          <div>
            <label class="form-label"><?= $L('Date','Date'); ?></label>
            <input type="date" name="<?= $k; ?>_date" class="form-control" <?= $readonly?'disabled':''; ?> value="<?= htmlspecialchars((string)($date_val)); ?>">
          </div>
        </div>
        <?php if ($readonly): ?>
          <div class="sig-wrap" style="height:100px;">
            <?php if ($sig_val): ?>
              <img class="sig-existing" src="<?= base_url(htmlspecialchars((string)($sig_val))); ?>">
            <?php endif; ?>
          </div>
        <?php else: ?>
          <label class="form-label"><?= $L('Signature','Signature'); ?></label>
          <?php if ($editing && $sig_val): ?>
            <div style="font-size:11px;color:var(--t2);margin-bottom:4px;">
              <?= $L('Signature already on file — draw to replace it, or leave blank to keep it.','Signature déjà enregistrée — dessinez pour la remplacer, ou laissez vide pour la conserver.'); ?>
            </div>
            <div class="sig-wrap" style="height:60px;margin-bottom:6px;opacity:0.7;">
              <img class="sig-existing" src="<?= base_url(htmlspecialchars((string)($sig_val))); ?>">
            </div>
          <?php endif; ?>
          <div class="sig-wrap" id="wrap-<?= $k; ?>">
            <canvas id="canvas-<?= $k; ?>"></canvas>
          </div>
          <input type="hidden" name="<?= $k; ?>_signature_data" id="data-<?= $k; ?>">
          <div class="sig-ctrls">
            <button type="button" onclick="sigPads['<?= $k; ?>'].undo()"><?= $L('Undo','Annuler'); ?></button>
            <button type="button" onclick="sigPads['<?= $k; ?>'].clear()"><?= $L('Clear','Effacer'); ?></button>
          </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>

    <?php if (!$readonly): ?>
    <div class="no-print" style="margin-top:26px;display:flex;justify-content:flex-end;gap:10px;">
      <a href="<?= base_url($editing ? ('inspections/view/'.$record->id) : 'inspections'); ?>" class="btn-ghost" style="padding:10px 18px;border-radius:8px;text-decoration:none;font-size:13px;"><?= $L('Cancel','Annuler'); ?></a>
      <button type="submit" class="btn-primary" style="padding:10px 22px;border-radius:8px;border:none;font-size:13px;cursor:pointer;"><?= $editing ? $L('Save Changes','Enregistrer les modifications') : $L('Submit Inspection','Soumettre l’inspection'); ?></button>
    </div>
    <?php endif; ?>
  </form>
</div>

<?php if (!$readonly): ?>
<script>
function pickTri(el, val) {
  var row = el.closest('.tri');
  row.querySelectorAll('label').forEach(function(l){ l.classList.remove('sel-ok','sel-fail','sel-na'); });
  if (val === 'ok' || val === 'Yes') el.classList.add('sel-ok');
  else if (val === 'fail' || val === 'No') el.classList.add('sel-fail');
  else el.classList.add('sel-na');
}

/* Equipment auto-fill */
(function(){
  var sel = document.getElementById('equip-type-select');
  if (!sel) return;
  sel.addEventListener('change', function(){
    var opt = sel.options[sel.selectedIndex];
    if (!opt || !opt.value) return;
    var isKm = <?= ($template->meter_label_en === 'Km' || $template->meter_label_fr === 'Km') ? 'true' : 'false'; ?>;
    var meterVal = isKm ? (opt.getAttribute('data-km') || opt.getAttribute('data-smr') || '') : (opt.getAttribute('data-smr') || '');
    var smrInput = document.getElementById('fld-smr');
    if (smrInput) smrInput.value = meterVal;
    var snInput = document.getElementById('fld-sn');
    if (snInput) snInput.value = opt.getAttribute('data-sn') || '';
    var modelInput = document.getElementById('fld-model');
    if (modelInput) modelInput.value = opt.getAttribute('data-model') || '';
    var eqIdInput = document.getElementById('fld-equipment-id');
    if (eqIdInput) eqIdInput.value = opt.getAttribute('data-id') || '';
    var fleetInput = document.querySelector('input[name="fleet_number"]');
    if (fleetInput && !fleetInput.value) fleetInput.value = opt.getAttribute('data-fleet') || '';
  });
})();

/* ══ Draw-to-sign canvases (one per sign-off role) ══ */
window.sigPads = {};
(function(){
  var roles = ['mechanic','supervisor','operator','prod_supervisor'];
  roles.forEach(function(key){
    var canvas = document.getElementById('canvas-' + key);
    if (!canvas) return;
    var ctx = canvas.getContext('2d');
    var drawing = false, strokes = [], current = [];
    var dataInp = document.getElementById('data-' + key);

    function setup() {
      var rect = canvas.getBoundingClientRect();
      var dpr = window.devicePixelRatio || 1;
      canvas.style.width = '100%'; canvas.style.height = '100px';
      canvas.width = rect.width * dpr; canvas.height = 100 * dpr;
      ctx.setTransform(dpr,0,0,dpr,0,0);
      ctx.strokeStyle = '#0a1a5c'; ctx.lineWidth = 1.8; ctx.lineCap='round'; ctx.lineJoin='round';
      ctx.fillStyle = '#ffffff'; ctx.fillRect(0,0,rect.width,100);
      redraw();
    }
    function pos(e){ var r=canvas.getBoundingClientRect(); var s=e.touches?e.touches[0]:e; return {x:s.clientX-r.left,y:s.clientY-r.top}; }
    function redraw(){
      var rect = canvas.getBoundingClientRect();
      ctx.fillStyle='#ffffff'; ctx.fillRect(0,0,rect.width,100);
      strokes.forEach(function(st){
        if (st.length<2) return;
        ctx.beginPath(); ctx.moveTo(st[0].x, st[0].y);
        for (var i=1;i<st.length;i++) ctx.lineTo(st[i].x, st[i].y);
        ctx.stroke();
      });
    }
    function onStart(e){ e.preventDefault(); drawing=true; current=[pos(e)]; }
    function onMove(e){ e.preventDefault(); if(!drawing) return; var p=pos(e); current.push(p);
      if (current.length>=2){ ctx.beginPath(); ctx.moveTo(current[current.length-2].x,current[current.length-2].y); ctx.lineTo(p.x,p.y); ctx.stroke(); } }
    function onEnd(){ if(!drawing) return; drawing=false; if(current.length) strokes.push(current.slice()); current=[]; sync(); }
    function sync(){ dataInp.value = strokes.length ? canvas.toDataURL('image/png') : ''; }

    sigPads[key] = {
      clear: function(){ strokes=[]; current=[]; redraw(); dataInp.value=''; },
      undo:  function(){ if(strokes.length) strokes.pop(); redraw(); sync(); }
    };

    canvas.addEventListener('mousedown', onStart);
    canvas.addEventListener('mousemove', onMove);
    canvas.addEventListener('mouseup', onEnd);
    canvas.addEventListener('mouseleave', onEnd);
    canvas.addEventListener('touchstart', onStart, {passive:false});
    canvas.addEventListener('touchmove', onMove, {passive:false});
    canvas.addEventListener('touchend', onEnd);

    setup();
    window.addEventListener('resize', setup);
  });
})();
</script>
<?php endif; ?>
