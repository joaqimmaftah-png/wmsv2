<?php
$report   = isset($report) && is_array($report) ? $report : array();
$week     = isset($report['week']) ? $report['week'] : array();
$days     = isset($week['days']) ? $week['days'] : array();
$groups   = isset($report['groups']) ? $report['groups'] : array();
$machines = isset($report['machines']) ? $report['machines'] : array();
$order    = isset($report['machine_order']) ? $report['machine_order'] : array();
$fleet    = isset($report['fleet']) ? $report['fleet'] : array();
$per_type = isset($report['per_type']) ? $report['per_type'] : array();
$charts   = isset($report['charts']) ? $report['charts'] : array();
$pm       = isset($report['pm']) ? $report['pm'] : array();
$inspect  = isset($report['inspection']) ? $report['inspection'] : array();
$quality  = isset($report['data_quality']) ? $report['data_quality'] : array();
$meta     = isset($report['meta']) ? $report['meta'] : array();
$modes    = isset($report['settings']) ? $report['settings'] : array();
$notice   = isset($notice) ? $notice : null;
$snapshot = isset($snapshot) && is_array($snapshot) ? $snapshot : null;
$published = isset($published) && is_array($published) ? $published : $snapshot;
$is_live  = !empty($is_live);
$live_query = $is_live && $published !== null ? '&live=1' : '';
$may_publish = isset($role_id) && in_array((int)$role_id, array(1, 4), true);
$csrf_token = isset($csrf_token) ? (string)$csrf_token : '';
$csrf_name  = isset($csrf_name) && $csrf_name !== '' ? (string)$csrf_name : null;
$csrf_field = function () use ($csrf_token, $csrf_name) { return csrf_field($csrf_token, $csrf_name); };
$print_mode   = !empty($print_mode);
$auto_print   = !empty($auto_print);
$generated_at = isset($generated_at) ? $generated_at : date('Y-m-d H:i');
$build_ms = isset($build_ms) ? (int)$build_ms : null;
$prev_week = isset($prev_week) ? $prev_week : null;
$next_week = isset($next_week) ? $next_week : null;
$week_label = isset($week_label) ? $week_label : (isset($week['iso_label']) ? $week['iso_label'] : '');
$report_name = isset($report['report_name']) && trim((string)$report['report_name']) !== ''
    ? trim((string)$report['report_name'])
    : ('W' . (int)(isset($week['iso_week']) ? $week['iso_week'] : (isset($week_label) && preg_match('/-W([0-9]+)$/', $week_label, $wm) ? $wm[1] : date('W'))));

$esc = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };

$num = function ($v, $dec = 2) {
    if ($v === null || $v === '' || $v === false) return '—';
    return number_format((float)$v, $dec, '.', ' ');
};

$pct = function ($v) use ($num) {
    if ($v === null || $v === '') return '—';
    return $num(((float)$v) * 100, 2) . ' %';
};

$misses = function ($value, $target) {
    return $value !== null && $target !== null && (float)$value < (float)$target;
};
$availability_tone = function ($value, $target) {
    if ($value === null || $target === null) return 'neutral';
    if ((float)$value >= (float)$target) return 'good';
    if ((float)$value >= (float)$target - .15) return 'warn';
    return 'bad';
};

$day_name = function ($d) { return date('D d/m', strtotime($d)); };
$day_row = function ($daily, $day, $index) {
    if (!is_array($daily)) return null;
    if (isset($daily[$day]) && is_array($daily[$day])) return $daily[$day];
    return isset($daily[$index]) && is_array($daily[$index]) ? $daily[$index] : null;
};

$availability_bar = function ($value, $target) use ($esc, $pct, $availability_tone) {
    $tone = $availability_tone($value, $target);
    $width = $value === null ? 0 : max(0, min(100, (float)$value * 100));
    $label = $pct($value);
    return '<span class="w36-avbar" aria-label="Availability '.$esc($label).'">
              <span class="w36-av-track">
                <i class="w36-av-fill '.$esc($tone).'" style="width:'.$width.'%"></i>
              </span>
              <span class="w36-av-value '.$esc($tone).'">'.$esc($label).'</span>
            </span>';
};
$daily_strip = function ($daily) use ($days, $day_row, $pct, $availability_tone, $esc) {
    $html = '<span class="w36-strip" aria-label="Daily availability Monday to Sunday">';
    foreach ($days as $i => $day) {
        $cell = $day_row($daily, $day, $i);
        $value = $cell && isset($cell['availability']) ? $cell['availability'] : null;
        $target = $cell && array_key_exists('target', $cell) ? $cell['target'] : null;
        $tone = $availability_tone($value, $target);
        $preventive = $cell && isset($cell['preventive_hours']) ? (float)$cell['preventive_hours'] : 0;
        if ($value !== null && $preventive > 0 && (float)$value >= .99) $tone = 'prev';
        $title = $day_name = date('D d M', strtotime($day));
        $title .= ': '.$pct($value);
        if ($target !== null) $title .= ' vs target '.$pct($target);
        if ($cell && isset($cell['downtime_hours'])) $title .= ' · '. $cell['downtime_hours']. ' h down';
        if ($preventive > 0) $title .= ' · '. $preventive.' h preventive';
        if ($value === null) $title .= ' · no reading';
        $html .= '<i class="'.$esc($tone).'" title="'.$esc($title).'"></i>';
    }
    return $html.'</span>';
};

$by_level = array('error' => array(), 'warn' => array(), 'info' => array());
foreach ($quality as $q) {
    $lvl = isset($q['level']) ? $q['level'] : 'info';
    if (!isset($by_level[$lvl])) $by_level[$lvl] = array();
    $by_level[$lvl][] = $q;
}
$issue_count = count($by_level['error']) + count($by_level['warn']);
$warning_counts = array();
foreach ($by_level['warn'] as $warning) {
    $code = isset($warning['code']) ? (string)$warning['code'] : 'other';
    $warning_counts[$code] = isset($warning_counts[$code]) ? $warning_counts[$code] + 1 : 1;
}
$status_without_wo_count = isset($warning_counts['status_without_wo']) ? $warning_counts['status_without_wo'] : 0;
$can_create_wo = isset($role_id) && in_array((int)$role_id, array(1, 2, 3), true);
$can_edit_equipment = isset($role_id) && (int)$role_id === 1;
$with_data   = 0;
$down_machines = 0;
foreach ($order as $id) {
    if (empty($machines[$id])) continue;
    if (!empty($machines[$id]['weekly']['has_data'])) $with_data++;
    if ((float)$machines[$id]['weekly']['hours_down'] > 0) $down_machines++;
}
$machine_total = count($machines);
$fleet_available = max(0, $machine_total - $down_machines);
$locations = array();
$unclassified_ids = array();
foreach ($order as $id) {
    if (empty($machines[$id])) continue;
    $site_label = trim((string)(isset($machines[$id]['site']['label']) ? $machines[$id]['site']['label'] : ''));
    if ($site_label !== '') $locations[$site_label] = $site_label;
    if (empty($machines[$id]['group_code'])) $unclassified_ids[] = $id;
}
ksort($locations, SORT_NATURAL | SORT_FLAG_CASE);

$weekly_cols = array(
    'days'      => 'NUMBER OF DAYS DOWN',
    'open'      => 'WEEKLY OPEN TIME',
    'pt'        => 'PREVENTIVE TIME',
    'rt'        => 'REQUIRED TIME',
    'dt'        => 'TOTAL DOWN TIME',
    'f'         => 'NUMBER OF FAILURES',
    'mtbf'      => 'MTBF',
    'mttr'      => 'MTTR',
    'av'        => 'OPERATIONAL AVAILABILITY',
    'target'    => 'DAILY TARGET KPI',
);

$detail_rows = array(
    'Preventive h' => 'preventive_hours', 'Down h' => 'downtime_hours', 'Failures' => 'failures',
    'MTBF h' => 'mtbf_hours', 'MTTR h' => 'mttr_hours', 'Availability' => 'availability', 'Target' => 'target',
);
$detail_week_key = array(
    'preventive_hours' => 'preventive_hours', 'downtime_hours' => 'downtime_hours',
    'failures' => 'breakdowns', 'mtbf_hours' => 'mtbf_hours', 'mttr_hours' => 'mttr_hours',
    'availability' => 'availability', 'target' => 'weekly_target',
);

$weekly_cells = function (array $r, $open_hours = null) use ($num, $pct) {
    $w = isset($r['rollup']) ? $r['rollup'] : $r;
    return array(
        'days'   => $num($w['days_down'], 0),
        'open'   => $num($open_hours, 0),
        'pt'     => $num($w['preventive_hours'], 2),
        'rt'     => $num($w['required_time_hours'], 2),
        'dt'     => $num($w['downtime_hours'], 2),
        'f'      => $num($w['breakdowns'], 0),
        'mtbf'   => $num($w['mtbf_hours'], 2),
        'mttr'   => $num($w['mttr_hours'], 2),
        'av'     => $pct($w['availability']),
        'target' => $pct(isset($w['weekly_target']) ? $w['weekly_target'] : null),
    );
};
?>