<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * `/inspections/records[/{code}]` delegates directly to the unified Enterprise
 * Fleet Inspection Register DataTable (`application/views/inspections/index.php`)
 * with full backward compatibility when included directly with `$template` & `$records`.
 */
if (!isset($focused_template) && isset($template)) {
    $focused_template = $template;
}
if (!isset($forced_code) && isset($template) && !empty($template->code)) {
    $forced_code = $template->code;
}
include __DIR__ . '/index.php';
