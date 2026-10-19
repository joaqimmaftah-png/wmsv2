<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Job Card — <?= isset($wo->wo_number) ? htmlspecialchars($wo->wo_number) : 'JC'; ?></title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:Arial,Helvetica,sans-serif;font-size:8pt;background:#d1d5db;color:#000;
  -webkit-print-color-adjust:exact;print-color-adjust:exact;}

/* ── Toolbar (hidden on print) ── */
#tb{background:#080810;padding:11px 26px;display:flex;align-items:center;
  justify-content:space-between;position:sticky;top:0;z-index:200;gap:12px;}
.tb-l{display:flex;align-items:center;gap:14px;}
.tb-brand{font-size:13px;font-weight:700;color:#fff;}
.tb-sub{font-size:10px;color:rgba(255,255,255,0.35);}
.tb-wo{font-size:11px;font-weight:700;color:#0a84ff;background:rgba(10,132,255,0.12);
  border:1px solid rgba(10,132,255,0.25);padding:4px 13px;border-radius:100px;font-family:'Courier New',monospace;}
.tb-r{display:flex;align-items:center;gap:10px;}
.tb-hint{font-size:10px;color:rgba(255,255,255,0.28);font-style:italic;}
.btn-bk{display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,0.08);
  color:rgba(255,255,255,0.65);border:1px solid rgba(255,255,255,0.12);
  padding:8px 16px;border-radius:100px;font-size:12px;cursor:pointer;text-decoration:none;}
.btn-bk:hover{background:rgba(255,255,255,0.14);color:#fff;}
.btn-pr{display:inline-flex;align-items:center;gap:7px;background:#0a84ff;color:#fff;
  border:none;padding:9px 20px;border-radius:100px;font-size:12px;font-weight:700;cursor:pointer;}
.btn-pr:hover{background:#2b94ff;}

/* ── Paper container ── */
.wrap{padding:20px;display:flex;flex-direction:column;align-items:center;}
.jc{width:210mm;background:#fff;box-shadow:0 4px 40px rgba(0,0,0,0.2);
  padding:7mm 7mm 5mm 7mm;font-family:Arial,Helvetica,sans-serif;font-size:7.5pt;color:#000;}

/* ── Table base ── */
table{border-collapse:collapse;width:100%;}
td,th{border:1px solid #444;padding:2px 4px;vertical-align:middle;line-height:1.35;}

/* ── Cell helpers ── */
.sh{background:#c8dfc0!important;text-align:center;font-weight:bold;font-size:7pt;
  letter-spacing:.4px;text-transform:uppercase;padding:3px 4px!important;}
.nb{border:none!important;}
.bb{border:none!important;border-bottom:1px solid #555!important;}
.lbl{font-size:6.5pt;font-weight:bold;color:#222;white-space:nowrap;}

/* ── Underline-value span ── */
.uv{display:inline-block;width:100%;border-bottom:1px solid #888;font-size:7.5pt;
  font-weight:500;min-height:10px;padding-bottom:1px;}

/* ── Checkbox ── */
.box{display:inline-block;width:11px;height:11px;border:1.5px solid #333;
  vertical-align:middle;margin-right:3px;text-align:center;line-height:9px;font-size:8pt;}
.box-on::after{content:'X';}

/* ── System sub-boxes ── */
.sys{font-size:6.5pt;padding:2px 3px!important;}
.sn{font-weight:bold;font-size:6pt;display:inline-block;width:14px;text-align:right;margin-right:1px;}
.sb{display:inline-block;width:9px;height:9px;border:1px solid #333;vertical-align:middle;
  margin-right:2px;text-align:center;line-height:8px;font-size:7pt;}
.sb-on::after{content:'X';}

/* ── Text line rows ── */
.lr td{height:15px;border:none!important;
  border-left:1px solid #444!important;border-right:1px solid #444!important;
  border-bottom:1px solid #d0d0d0!important;padding:1px 4px;font-size:7.5pt;}
.lr td.last-line{border-bottom:1px solid #444!important;}

/* ── Status check boxes ── */
.stchk{display:inline-flex;align-items:center;gap:4px;font-size:7pt;font-weight:bold;}
.stbox{width:13px;height:13px;border:1.5px solid #333;display:inline-flex;
  align-items:center;justify-content:center;font-size:8pt;flex-shrink:0;}
.stbox.on::after{content:'X';}

/* ── Spare type badge ── */
.spare-new{font-size:6pt;font-weight:bold;color:#155724;background:#d4edda;
  padding:1px 4px;border-radius:2px;}
.spare-used{font-size:6pt;font-weight:bold;color:#856404;background:#fff3cd;
  padding:1px 4px;border-radius:2px;}

@media print{
  @page{size:A4 portrait;margin:0;}
  body{background:#fff;}
  #tb{display:none!important;}
  .wrap{padding:0;background:none;}
  .jc{box-shadow:none;width:100%;padding:8mm 8mm 6mm 8mm;margin:0;}
}
</style>
</head>
<body>

<?php
/* ── Data binding ── */
$wo         = isset($wo)          ? $wo          : (object)array();
$eq         = isset($equipment)   ? $equipment   : (object)array();
$spare_rows = (isset($spare_parts) && is_array($spare_parts)) ? $spare_parts : array();
$oil_rows   = (isset($oil_records) && is_array($oil_records)) ? $oil_records  : array();
$site       = isset($site_settings) ? $site_settings : array();
$company    = isset($site['company_name']) ? $site['company_name'] : 'WORKSHOP';

$t   = isset($wo->type) ? $wo->type : '';
$iC  = in_array($t, array('Corrective Maintenance','Breakdown','Corrective'));
$iP  = in_array($t, array('Preventive Maintenance','Preventive'));
$iI  = ($t === 'Incident');

$ms   = isset($wo->machine_status) ? $wo->machine_status : ($iC ? 'Breakdown' : 'Operational');
$sta  = isset($wo->status)         ? $wo->status         : 'Open';
$isOp = ($ms === 'Operational');
$isBk = ($ms === 'Breakdown');
$isO  = ($sta === 'Open');
$isWP = ($sta === 'Waiting Parts');
$isWT = ($sta === 'Waiting Tools');
$isC  = in_array($sta, array('Completed','Closed'));

$selSys = array();
if (!empty($wo->equipment_systems)) {
    $selSys = array_map('trim', explode(',', $wo->equipment_systems));
}

$wdate = (!empty($wo->created_at))     ? date('d/m/Y',       strtotime($wo->created_at))     : '';
$dtS   = (!empty($wo->start_datetime)) ? date('d/m/Y  H:i',  strtotime($wo->start_datetime)) : '';
$dtE   = (!empty($wo->end_datetime))   ? date('d/m/Y  H:i',  strtotime($wo->end_datetime))   : '';

/* ── Session-scoped override: when printing one stint of a multi-session WO,
      show that session's own dates/hours instead of the whole WO's span ── */
$isSessionCard = isset($session) && $session;
if ($isSessionCard) {
    $dtS = !empty($session->start_datetime) ? date('d/m/Y  H:i', strtotime($session->start_datetime)) : '';
    $dtE = !empty($session->end_datetime)   ? date('d/m/Y  H:i', strtotime($session->end_datetime))   : '';
}

/* ── Helper: split text into fixed line array ── */
function jcLines($text, $maxLines, $lineLen = 105) {
    if (!$text) return array_fill(0, $maxLines, '');
    $text  = trim(str_replace("\r\n", "\n", $text));
    $lines = array();
    foreach (explode("\n", $text) as $para) {
        $wrapped = wordwrap($para, $lineLen, "\n", true);
        foreach (explode("\n", $wrapped) as $l) {
            $lines[] = $l;
            if (count($lines) >= $maxLines) break 2;
        }
    }
    while (count($lines) < $maxLines) $lines[] = '';
    return array_slice($lines, 0, $maxLines);
}

/* ── Build text sections (now separate) ── */
$descLines = jcLines(
    (!empty($wo->description) ? $wo->description : (!empty($wo->symptoms) ? $wo->symptoms : '')),
    6
);
$rootLines = jcLines(!empty($wo->root_cause) ? $wo->root_cause : '', 4);
$doneLines = jcLines(!empty($wo->action_taken) ? $wo->action_taken : '', 7);
$pendLines = jcLines(isset($wo->pending_work) ? $wo->pending_work : '', 3);
$remLines  = jcLines(isset($wo->remarks)      ? $wo->remarks      : '', 7);

/* ── Ensure minimum rows for printing ── */
while (count($spare_rows) < 6) $spare_rows[] = null;
while (count($oil_rows)   < 2) $oil_rows[]   = null;

/* ── Render helpers ── */
function uvf($v) {
    return '<span class="uv">'.htmlspecialchars(isset($v) ? $v : '').'</span>';
}
function boxf($on) {
    return '<span class="box'.($on?' box-on':'').'"></span>';
}
function sbxf($n, $sel) {
    $on = in_array((string)$n, $sel);
    return '<span class="sb'.($on?' sb-on':'').'"></span>';
}
function stbf($on) {
    return '<span class="stbox'.($on?' on':'').'"></span>';
}
function spareBadge($type) {
    if ($type === 'From Machine') return '<span class="spare-used">FROM MACHINE</span>';
    return '<span class="spare-new">NEW</span>';
}

$sysNames = array(
    1=>'CHASSIS/CARROSSERIE', 2=>'ENGINE',           3=>'TRANSMISSION, GEAR BOX',
    4=>'HYDRAULIC CIRCUIT',   5=>'AIR COND./ANSUL',  6=>'STEERING',
    7=>'SUSPENSION',          8=>'BRAKES',            9=>'PNEUMATIC CIRCUIT',
    10=>'FINAL DRIVES/DIFF',  11=>'ELECTRIC',         12=>'LUBRICANT & GREASING',
    13=>'TYRE, WHEEL',        14=>'LIGHTING & SIGNING', 15=>'CALIBRATION',
);
$sysRows = array(
    array(1,4,7,10,13),
    array(2,5,8,11,14),
    array(3,6,9,12,15),
);
?>

<!-- ═══ TOOLBAR ═══ -->
<div id="tb">
  <div class="tb-l">
    <div>
      <div class="tb-brand">Workshop Intelligence</div>
      <div class="tb-sub">Maintenance Job Card</div>
    </div>
    <div class="tb-wo"><?= htmlspecialchars(isset($wo->wo_number) ? $wo->wo_number : 'JC'); ?></div>
  </div>
  <div class="tb-r">
    <span class="tb-hint">Ctrl+P &rarr; Save as PDF &middot; uncheck Headers &amp; Footers for clean output</span>
    <a href="<?= base_url('workorders/view/'.$wo->id) ?>" class="btn-bk">&larr; Back to WO</a>
    <button class="btn-pr" onclick="window.print()">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
        stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
        <polyline points="6 9 6 2 18 2 18 9"/>
        <path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/>
        <rect x="6" y="14" width="12" height="8"/>
      </svg>
      Print / Save as PDF
    </button>
  </div>
</div>

<!-- ═══ PAPER ═══ -->
<div class="wrap"><div class="jc">
<table>

  <!-- ═══ HEADER ROW ═══ -->
  <tr>
    <td colspan="2" rowspan="4" style="border:2px solid #222;text-align:center;vertical-align:middle;width:22%;">
      <div style="font-size:14pt;font-weight:900;letter-spacing:1px;">JOB CARD</div>
      <div style="font-size:6pt;color:#888;margin-top:2px;">FICHE DE TRAVAIL</div>
      <?php if ($isSessionCard): ?>
      <div style="font-size:6.5pt;font-weight:800;color:#0a50cc;margin-top:3px;">SESSION <?= (int)$session->session_no; ?> of <?= (int)$session_count; ?></div>
      <?php endif; ?>
    </td>
    <td rowspan="4" style="border:2px solid #222;text-align:center;vertical-align:middle;width:13%;padding:4px;">
      <img src="<?= base_url('uploads/logoam.png') ?>" alt="A&M Logo"
           style="max-width:100%;width:90px;height:auto;display:block;margin:0 auto;"
           onerror="this.style.display='none';this.nextElementSibling.style.display='block';">
      <div style="display:none;font-size:9pt;font-weight:900;color:#1a5ccf;">A&amp;M</div>
    </td>
    <td colspan="3" class="sh bb" style="border:2px solid #222;border-bottom:none!important;width:32%;">WORK REQUEST</td>
    <td colspan="2" class="sh bb" style="border:2px solid #222;border-bottom:none!important;width:33%;">WORK PLANNING</td>
  </tr>
  <tr>
    <td class="lbl nb bb" style="width:12%;padding:2px 5px!important;">Date</td>
    <td colspan="2" class="nb bb" style="padding:2px 5px!important;"><?= uvf($wdate); ?></td>
    <td class="lbl nb bb" style="width:14%;padding:2px 5px!important;">Work Order [OT]</td>
    <td class="nb bb" style="padding:2px 5px!important;">
      <strong style="font-size:7pt;color:#0a50cc;"><?= htmlspecialchars($wo->wo_number??''); ?></strong>
    </td>
  </tr>
  <tr>
    <td class="lbl nb bb" style="padding:2px 5px!important;">Requester</td>
    <td colspan="2" class="nb bb" style="padding:2px 5px!important;"><?= uvf($created_by??''); ?></td>
    <td class="lbl nb bb" style="padding:2px 5px!important;">Planner</td>
    <td class="nb bb" style="padding:2px 5px!important;"><?= uvf($wo->planner??''); ?></td>
  </tr>
  <tr>
    <td class="lbl nb" style="padding:2px 5px!important;">Purchase Order / DA</td>
    <td colspan="2" class="nb" style="padding:2px 5px!important;"><?= uvf($wo->purchase_order??''); ?></td>
    <td class="lbl nb" style="padding:2px 5px!important;">Workplace / Location</td>
    <td class="nb" style="padding:2px 5px!important;"><?= uvf($wo->workplace??''); ?></td>
  </tr>

  <!-- ═══ MACHINE INFO ═══ -->
  <tr>
    <td class="lbl" style="width:9%;">MACHINE ID</td>
    <td colspan="4"><?= uvf(isset($wo->registration_no)?$wo->registration_no:$eq->registration_no??''); ?></td>
    <td class="lbl" rowspan="5" style="text-align:center;width:8%;word-break:break-word;font-size:6.5pt;">WORK<br>EXECUTED<br>BY</td>
    <td colspan="2" rowspan="5" style="padding:4px 6px;vertical-align:top;">
      <?php
      $techs = isset($assigned_techs) && is_array($assigned_techs) ? $assigned_techs : array();
      if (empty($techs) && !empty($wo->assigned_name)) { $techs = array($wo->assigned_name); }
      foreach ($techs as $techName):
      ?><div style="font-size:7pt;font-weight:600;border-bottom:1px solid #ccc;padding:2px 0;margin-bottom:2px;">
          <span class="uv"><?= htmlspecialchars($techName); ?></span>
        </div><?php endforeach; ?>
    </td>
  </tr>
  <tr>
    <td class="lbl">SMR / HOURS</td>
    <td colspan="4"><?= uvf((isset($wo->hours_reading)&&$wo->hours_reading)?number_format($wo->hours_reading,1).' h':''); ?></td>
  </tr>
  <tr>
    <td class="lbl">DATE</td>
    <td colspan="4"><?= uvf($wdate); ?></td>
  </tr>
  <tr>
    <td class="lbl">MACHINE STATUS</td>
    <td colspan="4"><?= uvf($ms); ?></td>
  </tr>
  <tr>
    <td class="lbl">START</td>
    <td><?= uvf($dtS); ?></td>
    <td class="lbl" style="width:5%;">END</td>
    <td colspan="2"><?= uvf($dtE); ?></td>
  </tr>
  <?php if ($isSessionCard): ?>
  <tr>
    <td class="lbl">HOURS (THIS SESSION)</td>
    <td><strong><?= uvf(($session->hours_worked !== null) ? number_format($session->hours_worked,2).' h' : ''); ?></strong></td>
    <td class="lbl" style="width:5%;">REASON FOR STOP</td>
    <td colspan="2"><?= uvf(!empty($session->switched_to_wo_number) ? 'Switched to WO '.$session->switched_to_wo_number : ($session->interruption_reason ?: '')); ?></td>
  </tr>
  <?php endif; ?>

  <!-- ═══ TYPE OF INTERVENTION ═══ -->
  <tr><td colspan="8" class="sh">TYPE OF INTERVENTION</td></tr>
  <tr>
    <td colspan="3" style="text-align:center;padding:5px;">
      <?= boxf($iC); ?><strong style="font-size:7pt;"> CORRECTIVE MAINTENANCE</strong>
    </td>
    <td colspan="2" style="text-align:center;padding:5px;">
      <?= boxf($iP); ?><strong style="font-size:7pt;"> PREVENTIVE MAINTENANCE</strong>
    </td>
    <td colspan="3" style="text-align:center;padding:5px;">
      <?= boxf($iI); ?><strong style="font-size:7pt;"> INCIDENT</strong>
    </td>
  </tr>

  <!-- ═══ EQUIPMENT SYSTEMS ═══ -->
  <tr><td colspan="8" class="sh">EQUIPMENT SYSTEM</td></tr>
  <?php foreach ($sysRows as $row): ?>
  <tr>
    <td class="sys" colspan="2"><?= sbxf($row[0],$selSys); ?><span class="sn"><?= $row[0]; ?></span> <?= $sysNames[$row[0]]; ?></td>
    <td class="sys" colspan="2"><?= sbxf($row[1],$selSys); ?><span class="sn"><?= $row[1]; ?></span> <?= $sysNames[$row[1]]; ?></td>
    <td class="sys"><?= sbxf($row[2],$selSys); ?><span class="sn"><?= $row[2]; ?></span> <?= $sysNames[$row[2]]; ?></td>
    <td class="sys" colspan="2"><?= sbxf($row[3],$selSys); ?><span class="sn"><?= $row[3]; ?></span> <?= $sysNames[$row[3]]; ?></td>
    <td class="sys"><?= sbxf($row[4],$selSys); ?><span class="sn"><?= $row[4]; ?></span> <?= $sysNames[$row[4]]; ?></td>
  </tr>
  <?php endforeach; ?>

  <!-- ═══ DESCRIPTION OF INTERVENTION ═══ -->
  <tr><td colspan="8" class="sh">DESCRIPTION OF INTERVENTION / WORK REQUESTED</td></tr>
  <?php foreach ($descLines as $k => $line): ?>
  <tr class="lr">
    <td colspan="8" class="<?= ($k===count($descLines)-1)?'last-line':''; ?>"><?= htmlspecialchars($line); ?>&nbsp;</td>
  </tr>
  <?php endforeach; ?>

  <!-- ═══ ROOT CAUSE ═══ -->
  <tr><td colspan="8" class="sh">ROOT CAUSE / CAUSE RACINE</td></tr>
  <?php foreach ($rootLines as $k => $line): ?>
  <tr class="lr">
    <td colspan="8" class="<?= ($k===count($rootLines)-1)?'last-line':''; ?>"><?= htmlspecialchars($line); ?>&nbsp;</td>
  </tr>
  <?php endforeach; ?>

  <!-- ═══ WORK COMPLETED ═══ -->
  <tr><td colspan="8" class="sh">WORK COMPLETED / TRAVAUX EFFECTUÉS</td></tr>
  <?php foreach ($doneLines as $k => $line): ?>
  <tr class="lr">
    <td colspan="8" class="<?= ($k===count($doneLines)-1)?'last-line':''; ?>"><?= htmlspecialchars($line); ?>&nbsp;</td>
  </tr>
  <?php endforeach; ?>

  <!-- ═══ SPARE USED ═══ -->
  <tr><td colspan="8" class="sh">SPARE USED</td></tr>
  <tr style="background:#f4f4f4;">
    <td colspan="2" style="font-size:6.5pt;font-weight:bold;text-align:center;">ITEM CODE</td>
    <td colspan="2" style="font-size:6.5pt;font-weight:bold;text-align:center;">DESIGNATION / DESCRIPTION</td>
    <td style="font-size:6.5pt;font-weight:bold;text-align:center;">REFERENCE</td>
    <td style="font-size:6.5pt;font-weight:bold;text-align:center;">TYPE</td>
    <td style="font-size:6.5pt;font-weight:bold;text-align:center;">SOURCE MACHINE</td>
    <td style="font-size:6.5pt;font-weight:bold;text-align:center;">QTY</td>
  </tr>
  <?php foreach ($spare_rows as $sp): ?>
  <tr style="height:14px;">
    <td colspan="2" style="font-size:7pt;border:1px solid #aaa;padding:1px 4px;">
      <?= htmlspecialchars($sp->item_code??''); ?>
    </td>
    <td colspan="2" style="border:1px solid #aaa;font-size:7pt;padding:1px 4px;">
      <?= htmlspecialchars($sp->part_name??''); ?>
    </td>
    <td style="border:1px solid #aaa;font-size:7pt;padding:1px 4px;">
      <?= htmlspecialchars($sp->part_number??''); ?>
    </td>
    <td style="border:1px solid #aaa;font-size:6.5pt;text-align:center;padding:1px 3px;">
      <?php if ($sp && isset($sp->spare_type)): echo spareBadge($sp->spare_type); endif; ?>
    </td>
    <td style="border:1px solid #aaa;font-size:7pt;padding:1px 4px;">
      <?= htmlspecialchars($sp->source_machine??''); ?>
    </td>
    <td style="border:1px solid #aaa;font-size:7pt;text-align:center;padding:1px 4px;">
      <?= ($sp && isset($sp->quantity) && $sp->quantity > 0) ? htmlspecialchars($sp->quantity.(isset($sp->unit)&&$sp->unit?' '.$sp->unit:'')) : ''; ?>
    </td>
  </tr>
  <?php endforeach; ?>

  <!-- ═══ OIL / COOLANT TOP UP ═══ -->
  <tr><td colspan="8" class="sh">OIL / COOLANT TOP UP</td></tr>
  <tr style="background:#f4f4f4;">
    <td colspan="2" style="font-size:6.5pt;font-weight:bold;text-align:center;">SYSTEM / LOCATION</td>
    <td colspan="3" style="font-size:6.5pt;font-weight:bold;text-align:center;">OIL / FLUID TYPE</td>
    <td colspan="2" style="font-size:6.5pt;font-weight:bold;text-align:center;">GRADE / SPECIFICATION</td>
    <td style="font-size:6.5pt;font-weight:bold;text-align:center;">QTY (L)</td>
  </tr>
  <?php foreach ($oil_rows as $oil): ?>
  <tr style="height:13px;">
    <td colspan="2" style="border:1px solid #aaa;font-size:7pt;padding:1px 4px;">
      <?= htmlspecialchars($oil->system_location??''); ?>
    </td>
    <td colspan="3" style="border:1px solid #aaa;font-size:7pt;padding:1px 4px;">
      <?= htmlspecialchars($oil->oil_type??''); ?>
    </td>
    <td colspan="2" style="border:1px solid #aaa;font-size:7pt;padding:1px 4px;">
      <?= htmlspecialchars($oil->grade??''); ?>
    </td>
    <td style="border:1px solid #aaa;font-size:7pt;text-align:center;padding:1px 4px;">
      <?= ($oil && isset($oil->quantity) && $oil->quantity > 0) ? number_format($oil->quantity,1) : ''; ?>
    </td>
  </tr>
  <?php endforeach; ?>

  <!-- ═══ INCIDENT REPORT (only for Incident-type WOs) ═══ -->
  <?php if (isset($wo->type) && $wo->type === 'Incident' && !empty($incident)): ?>
  <tr><td colspan="8" class="sh" style="text-align:left;padding-left:6px!important;background:#fde8e8;">INCIDENT REPORT / RAPPORT D'INCIDENT — NEAR MISS</td></tr>
  <tr class="lr">
    <td colspan="8" style="font-size:7.5pt;padding:4px 6px;">
      <strong>Date/Time:</strong> <?= $incident->incident_datetime ? date('d M Y H:i', strtotime($incident->incident_datetime)) : '—'; ?>
      &nbsp;&nbsp;<strong>Site:</strong> <?= htmlspecialchars($incident->site ?: '—'); ?>
      &nbsp;&nbsp;<strong>Department:</strong> <?= htmlspecialchars($incident->department ?: '—'); ?>
      &nbsp;&nbsp;<strong>Location:</strong> <?= htmlspecialchars($incident->location ?: '—'); ?>
    </td>
  </tr>
  <tr class="lr">
    <td colspan="8" style="font-size:7.5pt;padding:4px 6px;">
      <strong>Involved:</strong> <?= htmlspecialchars($incident->involved_party_type ?: '—'); ?>
      <?= $incident->staff_id ? '&nbsp;&nbsp;<strong>Staff ID:</strong> '.htmlspecialchars($incident->staff_id) : ''; ?>
      <?= $incident->person_name ? '&nbsp;&nbsp;<strong>Name:</strong> '.htmlspecialchars($incident->person_name) : ''; ?>
      <?= $incident->person_function ? '&nbsp;&nbsp;<strong>Function:</strong> '.htmlspecialchars($incident->person_function) : ''; ?>
      <?= !empty($incident->injured) ? '&nbsp;&nbsp;<strong style="color:#b00;">⚠ Injured</strong>' : '&nbsp;&nbsp;<strong>Injured:</strong> No'; ?>
    </td>
  </tr>
  <tr class="lr">
    <td colspan="8" style="font-size:7.5pt;padding:4px 6px;">
      <?= $incident->fleet_no ? '<strong>Fleet No:</strong> '.htmlspecialchars($incident->fleet_no) : ''; ?>
      <?= $incident->part_affected ? '&nbsp;&nbsp;<strong>Part:</strong> '.htmlspecialchars($incident->part_affected) : ''; ?>
      &nbsp;&nbsp;<strong>Damaged:</strong> <?= !empty($incident->equipment_damaged) ? 'YES' : 'No'; ?>
      &nbsp;&nbsp;<strong>Activity Status:</strong> <?= htmlspecialchars($incident->activity_status ?: '—'); ?>
    </td>
  </tr>
  <?php if (!empty($incident->description)): ?>
  <tr class="lr"><td colspan="8" style="font-size:7.5pt;padding:4px 6px;"><strong>Description:</strong> <?= htmlspecialchars($incident->description); ?></td></tr>
  <?php endif; ?>
  <?php if (!empty($incident->injury_damage_details)): ?>
  <tr class="lr"><td colspan="8" style="font-size:7.5pt;padding:4px 6px;"><strong>Injury/Damage/Impact:</strong> <?= htmlspecialchars($incident->injury_damage_details); ?></td></tr>
  <?php endif; ?>
  <?php if (!empty($incident->actions_taken)): ?>
  <tr class="lr"><td colspan="8" style="font-size:7.5pt;padding:4px 6px;"><strong>Actions Taken:</strong> <?= htmlspecialchars($incident->actions_taken); ?></td></tr>
  <?php endif; ?>
  <?php if (!empty($attachments)): ?>
  <tr class="lr">
    <td colspan="8" style="font-size:7.5pt;padding:4px 6px;">
      <strong>Evidence on file (<?= count($attachments); ?>):</strong>
      <?= htmlspecialchars(implode(', ', array_map(function($a){ return $a->original_name; }, $attachments))); ?>
      — full files available in the system record for this Work Order.
    </td>
  </tr>
  <?php endif; ?>
  <?php endif; ?>
  <tr><td colspan="8" class="sh" style="text-align:left;padding-left:6px!important;">PENDING WORK / TRAVAUX EN ATTENTE</td></tr>
  <?php foreach ($pendLines as $k => $line): ?>
  <tr class="lr">
    <td colspan="8" class="<?= ($k===count($pendLines)-1)?'last-line':''; ?>"><?= htmlspecialchars($line); ?>&nbsp;</td>
  </tr>
  <?php endforeach; ?>

  <!-- ═══ REMARKS ═══ -->
  <tr><td colspan="8" class="sh" style="text-align:left;padding-left:6px!important;">REMARKS / REMARQUES</td></tr>
  <?php foreach ($remLines as $k => $line): ?>
  <tr class="lr">
    <td colspan="8" class="<?= ($k===count($remLines)-1)?'last-line':''; ?>"><?= htmlspecialchars($line); ?>&nbsp;</td>
  </tr>
  <?php endforeach; ?>

  <!-- ═══ MACHINE STATUS + OPERATION STATUS ═══ -->
  <tr>
    <td colspan="3" style="padding:0;">
      <table>
        <tr><td colspan="3" class="sh nb bb">MACHINE STATUS</td></tr>
        <tr>
          <td style="border:none;padding:5px 10px;text-align:center;">
            <div class="stchk"><?= stbf($isOp); ?>&nbsp;OPERATIONAL</div>
          </td>
          <td style="border:none;width:1px;background:#444;"></td>
          <td style="border:none;padding:5px 10px;text-align:center;">
            <div class="stchk"><?= stbf($isBk); ?>&nbsp;BREAKDOWN</div>
          </td>
        </tr>
      </table>
    </td>
    <td colspan="5" style="padding:0;">
      <table>
        <tr><td colspan="9" class="sh nb bb">OPERATION STATUS</td></tr>
        <tr>
          <td style="border:none;padding:4px 7px;text-align:center;"><div class="stchk"><?= stbf($isO); ?>&nbsp;OPEN</div></td>
          <td style="border:none;width:1px;background:#444;"></td>
          <td style="border:none;padding:4px 7px;text-align:center;"><div class="stchk"><?= stbf($isWP); ?>&nbsp;<span style="font-size:6.5pt;">WAITING<br>SPARE PART</span></div></td>
          <td style="border:none;width:1px;background:#444;"></td>
          <td style="border:none;padding:4px 7px;text-align:center;"><div class="stchk"><?= stbf($isWT); ?>&nbsp;<span style="font-size:6.5pt;">WAITING<br>TOOLS</span></div></td>
          <td style="border:none;width:1px;background:#444;"></td>
          <td style="border:none;padding:4px 7px;text-align:center;"><div class="stchk"><?= stbf($isC); ?>&nbsp;CLOSED</div></td>
        </tr>
      </table>
    </td>
  </tr>

</table><!-- /main table -->

<!-- ═══ SIGNATURE BLOCK ═══ -->
<?php
function renderSigBox($user, $role, $borderRight = true) {
    if ($user) {
        $ln   = !empty($user->last_name) ? ' ' . $user->last_name : '';
        $name = htmlspecialchars($user->first_name . $ln);
    } else {
        $name = '';
    }
    $pos  = ($user && !empty($user->position)) ? htmlspecialchars($user->position) : '';
    $sig  = ($user && !empty($user->signature)) ? $user->signature : '';
    $border = 'border:1px solid #444;' . ($borderRight ? '' : 'border-right:none;');
    echo '<div style="flex:1;'.$border.'padding:5px 8px;">';
    echo '<div style="font-size:6.5pt;font-weight:bold;text-align:center;text-transform:uppercase;letter-spacing:.4px;margin-bottom:2px;">';
    echo htmlspecialchars($role).'</div>';
    if ($sig) {
        echo '<div style="text-align:center;height:46px;display:flex;align-items:center;justify-content:center;">';
        echo '<img src="'.base_url(htmlspecialchars($sig)).'" alt="Signature"';
        echo ' style="max-height:44px;max-width:100%;object-fit:contain;">';
        echo '</div>';
    } else {
        echo '<div style="height:46px;"></div>';
    }
    echo '<div style="border-top:1px solid #444;padding-top:3px;text-align:center;">';
    if ($name) echo '<div style="font-size:6.5pt;font-weight:700;">'.$name.'</div>';
    if ($pos)  echo '<div style="font-size:5.5pt;color:#777;">'.$pos.'</div>';
    echo '<div style="font-size:5pt;color:#aaa;margin-top:1px;">'.htmlspecialchars($role).'</div>';
    echo '</div>';
    echo '</div>';
}
?>
<div style="display:flex;margin-top:9mm;">
  <?php
  renderSigBox(isset($intervention_resp) ? $intervention_resp : NULL, 'Intervention Responsible', false);
  renderSigBox(isset($supervisor)         ? $supervisor         : NULL, 'Supervisor',               false);
  renderSigBox(isset($operator)           ? $operator           : NULL, 'Operator / Driver',        true);
  ?>
</div>

<div style="margin-top:3px;text-align:right;font-size:5.5pt;color:#bbb;">
  <?= htmlspecialchars($company); ?> WMS &middot; Generated <?= date('d/m/Y H:i'); ?>
  &middot; <?= htmlspecialchars($wo->wo_number??''); ?>
</div>

</div></div><!-- /jc /wrap -->

<script>
var p = new URLSearchParams(location.search);
if (p.get('autoprint') === '1') {
  window.addEventListener('load', function(){ setTimeout(window.print, 600); });
}
</script>
</body>
</html>
