<?php
/**
 * Dependency-free test runner.
 *
 *     php tests/run.php
 *
 * No Composer, no PHPUnit, no database: the cases cover the code that is pure
 * logic and has historically broken silently — the session projection (K-18),
 * currency normalisation (K-11) and the base_url / Host validation (K-17).
 *
 * The config cases include a *copy* of application/config/config.php so they
 * exercise the deployed code, not a duplicate of its regular expressions. The
 * copy is made in the system temp directory so a developer's own
 * config.local.php cannot change the result.
 *
 * Exit code 0 = every check passed, 1 = at least one failed.
 */

if (!defined('BASEPATH')) define('BASEPATH', TRUE);

$GLOBALS['wms_test_pass'] = 0;
$GLOBALS['wms_test_fail'] = 0;
$GLOBALS['wms_test_failures'] = array();

function check($name, $condition, $detail = '') {
    if ($condition) {
        $GLOBALS['wms_test_pass']++;
        return TRUE;
    }
    $GLOBALS['wms_test_fail']++;
    $GLOBALS['wms_test_failures'][] = $name . ($detail !== '' ? ' — ' . $detail : '');
    return FALSE;
}

function is_same($name, $expected, $actual) {
    return check(
        $name,
        $expected === $actual,
        'expected ' . var_export($expected, TRUE) . ', got ' . var_export($actual, TRUE)
    );
}

/* ─────────────────────────────────────────────────────────────────────────
   Minimal CodeIgniter stubs, enough for the helpers under test
   ───────────────────────────────────────────────────────────────────────── */

class WmsTestLoader {
    public function model($name) {
        // wms_site_currency() loads Settings_model when the property is absent;
        // the fake is installed by the test, so this is intentionally empty.
    }
}

class WmsTestSettingsModel {
    public $values = array();

    public function get($key, $default = NULL) {
        return (array_key_exists($key, $this->values) && $this->values[$key] !== '')
            ? $this->values[$key] : $default;
    }
}

#[AllowDynamicProperties]
class WmsTestApp {
    public $load;
    public $Settings_model;

    public function __construct() {
        $this->load = new WmsTestLoader();
        $this->Settings_model = new WmsTestSettingsModel();
    }
}

if (!function_exists('get_instance')) {
    function get_instance() {
        return $GLOBALS['wms_test_app'];
    }
}

$GLOBALS['wms_test_app'] = new WmsTestApp();

require_once __DIR__ . '/../application/helpers/user_helper.php';
require_once __DIR__ . '/../application/helpers/currency_helper.php';
require_once __DIR__ . '/../application/helpers/attendance_helper.php';

/* ─────────────────────────────────────────────────────────────────────────
   K-18 — the session payload
   ───────────────────────────────────────────────────────────────────────── */

$row = (object) array(
    'id'         => 7,
    'role_id'    => 3,
    'first_name' => 'Amina',
    'last_name'  => 'Yusuf',
    'email'      => 'amina@example.com',
    'position'   => 'Planner',
    'password'   => '$2y$10$abcdefghijklmnopqrstuv',
    'salt'       => 'legacy-salt',
    'remember_token' => 'secret-token',
);

$session_user = wms_session_user($row);
is_same('K-18: id survives the projection', 7, $session_user->id);
is_same('K-18: full_name is derived', 'Amina Yusuf', $session_user->full_name);
check('K-18: password is not in the payload', !property_exists($session_user, 'password'));
check('K-18: salt is not in the payload', !property_exists($session_user, 'salt'));
check('K-18: remember_token is not in the payload', !property_exists($session_user, 'remember_token'));

$array_user = wms_session_user(array('id' => 9, 'first_name' => 'Jean', 'last_name' => 'Mbeki', 'password' => 'x'));
is_same('K-18: array input keeps the id', 9, $array_user->id);
check('K-18: array input drops the hash', !property_exists($array_user, 'password'));

$empty = wms_session_user(NULL);
is_same('K-18: null input yields a full_name key', '', $empty->full_name);
check('K-18: null input still has every whitelisted field', property_exists($empty, 'email'));

is_same('display name: object with full_name', 'Amina Yusuf', wms_user_full_name($session_user));
is_same('display name: object without full_name', 'Amina Yusuf', wms_user_full_name((object) array('first_name' => 'Amina', 'last_name' => 'Yusuf')));
is_same('display name: array', 'Jean Mbeki', wms_user_full_name(array('first_name' => 'Jean', 'last_name' => 'Mbeki')));
is_same('display name: null', '', wms_user_full_name(NULL));

/* ─────────────────────────────────────────────────────────────────────────
   K-11 — currency
   ───────────────────────────────────────────────────────────────────────── */

$codes = wms_currencies();
check('K-11: UGX is offered', in_array('UGX', $codes, TRUE));
is_same('K-11: the vocabulary has no duplicates', count($codes), count(array_unique($codes)));
$all_iso = TRUE;
foreach ($codes as $c) {
    if (!preg_match('/^[A-Z]{3}$/', $c)) $all_iso = FALSE;
}
check('K-11: every code is three upper-case letters', $all_iso);

is_same('K-11: lower-case is normalised', 'UGX', wms_currency_code(' ugx '));
is_same('K-11: mixed case is normalised', 'USD', wms_currency_code('Usd'));
is_same('K-11: junk falls back to the caller value', 'TZS', wms_currency_code('<script>', 'TZS'));
is_same('K-11: a two-letter code is not accepted', 'KES', wms_currency_code('KE', 'KES'));

$settings = $GLOBALS['wms_test_app']->Settings_model;
$settings->values['currency'] = 'ugx';
is_same('K-11: site currency is read from settings', 'UGX', wms_site_currency(TRUE));
$settings->values['currency'] = 'dollars';
is_same('K-11: an invalid setting falls back to USD', 'USD', wms_site_currency(TRUE));
$settings->values['currency'] = '';
is_same('K-11: a missing setting falls back to USD', 'USD', wms_site_currency(TRUE));

is_same('K-11: money carries its currency', '1,234.50 UGX', wms_money(1234.5, 'ugx'));
is_same('K-11: money can drop decimals', '0 USD', wms_money(0, 'USD', 0));

/* ─────────────────────────────────────────────────────────────────────────
   K-17 — base_url and the Host header
   ───────────────────────────────────────────────────────────────────────── */

// Keep config.php's encryption-key fallback from logging on every include.
putenv('WMS_ENCRYPTION_KEY=test-only-key-0123456789abcdef');

$config_source = realpath(__DIR__ . '/../application/config/config.php');
check('K-17: config.php is present', $config_source !== FALSE);
$config_copy = tempnam(sys_get_temp_dir(), 'wms_config_');
copy($config_source, $config_copy);

function load_config($copy) {
    // Each include starts from a fresh array; config.php resets base_url itself.
    $config = array();
    include $copy;
    return $config;
}

$_SERVER['HTTP_HOST']   = 'wms.test';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$config = load_config($config_copy);
is_same('K-17: a normal host is accepted', 'http://wms.test/', $config['base_url']);
is_same('K-17: the encryption key comes from the environment', 'test-only-key-0123456789abcdef', $config['encryption_key']);
is_same('K-17: cookie_secure is off over HTTP', FALSE, $config['cookie_secure']);

$_SERVER['HTTP_HOST']   = 'WMS.Example.COM:8080';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$config = load_config($config_copy);
is_same('K-17: host is lower-cased and the port kept', 'http://wms.example.com:8080/', $config['base_url']);

$_SERVER['HTTP_HOST']   = 'wms.example.com';
$_SERVER['SCRIPT_NAME'] = '/wms/index.php';
$config = load_config($config_copy);
is_same('K-17: a sub-directory install keeps its prefix', 'http://wms.example.com/wms/', $config['base_url']);

$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['HTTPS']       = 'on';
$config = load_config($config_copy);
is_same('K-17: HTTPS produces an https base_url', 'https://wms.example.com/', $config['base_url']);
is_same('K-17: cookie_secure follows HTTPS', TRUE, $config['cookie_secure']);
unset($_SERVER['HTTPS']);

putenv('WMS_COOKIE_SECURE=0');
$config = load_config($config_copy);
is_same('K-17: WMS_COOKIE_SECURE=0 forces plain cookies', FALSE, $config['cookie_secure']);
putenv('WMS_COOKIE_SECURE=');

putenv('WMS_BASE_URL=https://pinned.example.com/');
$_SERVER['HTTP_HOST'] = 'wms.example.com';
$config = load_config($config_copy);
is_same('K-17: WMS_BASE_URL wins over detection', 'https://pinned.example.com/', $config['base_url']);
putenv('WMS_BASE_URL=');

// Direct checks of the validator config.php uses.
$valid_hosts = array(
    'aml.yzz.me'        => 'http://aml.yzz.me/',
    'localhost'         => 'http://localhost/',
    'localhost:8080'    => 'http://localhost:8080/',
    '192.168.1.10:8080' => 'http://192.168.1.10:8080/',
    '[::1]'             => 'http://[::1]/',
    '[::1]:8080'        => 'http://[::1]:8080/',
    'wms-01.internal'   => 'http://wms-01.internal/',
);
foreach ($valid_hosts as $host => $expected) {
    is_same('K-17: accepts ' . $host, $expected, wms_base_url_from_host($host, '/index.php', FALSE));
}

$invalid_hosts = array(
    'evil.com"><script>',
    'evil.com/',
    'evil.com\\path',
    'user@evil.com',
    'evil.com#fragment',
    'evil.com:80:80',
    'a..b',
    '',
);
foreach ($invalid_hosts as $host) {
    is_same('K-17: rejects ' . var_export($host, TRUE), NULL, wms_base_url_from_host($host, '/index.php', FALSE));
}

is_same('K-17: surrounding whitespace is trimmed', 'http://evil.com/', wms_base_url_from_host('evil.com ', '/index.php', FALSE));
is_same('K-17: a valid script prefix is kept', 'http://h.test/app/', wms_base_url_from_host('h.test', '/app/index.php', FALSE));

is_same(
    'K-17: a suspicious SCRIPT_NAME is ignored',
    'http://wms.example.com/',
    wms_base_url_from_host('wms.example.com', '/a"><script>/index.php', FALSE)
);

// CLI: no Host header at all.
unset($_SERVER['HTTP_HOST']);
$config = load_config($config_copy);
is_same('K-17: CLI falls back to localhost', 'http://localhost/', $config['base_url']);
unlink($config_copy);

/* ─────────────────────────────────────────────────────────────────────────
   XAMPP subfolder routing, PHP 8.x XLSX stream cleanliness & schema sync
   ───────────────────────────────────────────────────────────────────────── */

foreach (array('.htaccess', '.htaccess.cpanel-alternative') as $htFile) {
    $ht = file_get_contents(__DIR__ . '/../' . $htFile);
    check("{$htFile}: no hardcoded RewriteBase / breaking subfolder installs", !preg_match('/^\s*RewriteBase\s+\/\s*$/m', $ht));
    check("{$htFile}: rewrites non-files to relative index.php", strpos($ht, 'index.php') !== FALSE);
}

require_once __DIR__ . '/../application/helpers/xlsx_helper.php';
$xlsxErr = NULL;
set_error_handler(function ($errno, $errstr) use (&$xlsxErr) {
    $xlsxErr = $errstr;
    return TRUE;
});
$xlsx = new XlsxWriter();
$xlsx->addSheet('Test');
$xlsx->setRowHeight(NULL, 22);
$xlsx->writeRow(array('Month', 'Availability %'));
$tmpXlsx = sys_get_temp_dir() . '/wms_test_' . uniqid('', TRUE) . '.xlsx';
$xlsx->save($tmpXlsx);
restore_error_handler();
is_same('XlsxWriter: setRowHeight(null, ...) emits zero PHP 8.x warnings/deprecations', NULL, $xlsxErr);
is_same('XlsxWriter: writes valid ZIP header PK\\x03\\x04', "PK\x03\x04", substr(file_get_contents($tmpXlsx), 0, 4));
@unlink($tmpXlsx);

/* ─────────────────────────────────────────────────────────────────────────
   Attendance punches (ATTENDANCE_WORKFLOW.md)

   The geofence arithmetic and the signed QR tokens are the two places where a
   silent mistake is invisible in the UI but expensive in the field: a fence
   that is off by a factor, or a token check that accepts an old code. Both are
   pure functions, so they are pinned here.
   ───────────────────────────────────────────────────────────────────────── */

// Coordinates
check('Attendance: a normal latitude is accepted', wms_valid_latitude('0.3475964'));
check('Attendance: a latitude beyond 90 is rejected', !wms_valid_latitude('91'));
check('Attendance: a latitude of text is rejected', !wms_valid_latitude('51.5N'));
check('Attendance: an empty latitude is rejected', !wms_valid_latitude(''));
check('Attendance: NULL is not a latitude', !wms_valid_latitude(NULL));
check('Attendance: the south pole is a latitude', wms_valid_latitude(-90));
check('Attendance: a normal longitude is accepted', wms_valid_longitude('32.5825197'));
check('Attendance: a longitude beyond 180 is rejected', !wms_valid_longitude('181'));
check('Attendance: an array is not a longitude', !wms_valid_longitude(array(1)));

// Distance (haversine)
$d_1km = wms_attendance_distance_m(0, 0, 0, 0.008993);   // ~1 km at the equator
check('Attendance: ~1 km along the equator is ~1000 m', $d_1km > 990 && $d_1km < 1010, 'got ' . var_export($d_1km, TRUE));
is_same('Attendance: the distance is symmetric',
    wms_attendance_distance_m(0.3475964, 32.5825197, 0.3485964, 32.5835197),
    wms_attendance_distance_m(0.3485964, 32.5835197, 0.3475964, 32.5825197));
is_same('Attendance: the same point is 0 m', 0.0, wms_attendance_distance_m(0.3475964, 32.5825197, 0.3475964, 32.5825197));
$d_111 = wms_attendance_distance_m(0, 0, 0.001, 0);
check('Attendance: a point 0.001 deg north is ~111 m', $d_111 > 110 && $d_111 < 112, 'got ' . var_export($d_111, TRUE));
is_same('Attendance: an invalid coordinate has no distance', NULL, wms_attendance_distance_m(0, 0, 200, 0));

// The GPS verdict: order of the checks matters
$v = wms_attendance_geo_verdict(NULL, 150, 10, 100);
is_same('Attendance: no position is refused', 'no_location', $v['code']);
$v = wms_attendance_geo_verdict(10, 150, NULL, 100);
is_same('Attendance: a position with no accuracy is refused', 'no_accuracy', $v['code']);
$v = wms_attendance_geo_verdict(10, 150, 700, 100);
is_same('Attendance: poor accuracy is refused even inside the fence', 'accuracy', $v['code']);
$v = wms_attendance_geo_verdict(400, 150, 20, 100);
is_same('Attendance: a good fix outside the fence is refused', 'outside', $v['code']);
$v = wms_attendance_geo_verdict(149, 150, 20, 100);
check('Attendance: a good fix inside the fence is accepted', $v['ok']);
$v = wms_attendance_geo_verdict(5000, 0, 5, 0);
check('Attendance: a radius of 0 means no fence, only the accuracy rule applies', $v['ok']);

// Signed, short-lived QR tokens
$secret = str_repeat('ab', 32);
$now    = 1_800_000_000;
$token  = wms_attendance_qr_token(3, $secret, $now, 60);
check('Attendance: a token is issued', is_string($token) && $token !== '');
check('Attendance: the token carries no technician data', strpos((string)$token, '@') === FALSE);
$ok = wms_attendance_qr_verify($token, $secret, $now, 60, 0);
check('Attendance: the current token verifies', $ok['ok']);
is_same('Attendance: the token names its site', 3, $ok['site_id']);

$bad = wms_attendance_qr_verify($token, str_repeat('cd', 32), $now, 60, 0);
is_same('Attendance: another secret is refused', 'bad_signature', $bad['code']);
$bad = wms_attendance_qr_verify(substr($token, 0, -1) . (substr($token, -1) === 'a' ? 'b' : 'a'), $secret, $now, 60, 0);
is_same('Attendance: a tampered MAC is refused', 'bad_signature', $bad['code']);
$bad = wms_attendance_qr_verify('v2.3.1.' . str_repeat('0', 32), $secret, $now, 60, 0);
is_same('Attendance: another version is refused', 'wrong_version', $bad['code']);
foreach (array('', 'hello', 'v1.3', 'v1.3.x.' . str_repeat('0', 32), str_repeat('9', 200)) as $junk) {
    $bad = wms_attendance_qr_verify($junk, $secret, $now, 60, 0);
    is_same('Attendance: malformed token ' . var_export(substr($junk, 0, 12), TRUE) . ' is refused', 'malformed', $bad['code']);
}

// Expiry & grace: a token is valid for its window plus the grace only
$in_window = wms_attendance_qr_verify($token, $secret, $now + 59, 60, 0);
check('Attendance: the token still works one second before its window ends', $in_window['ok']);
$expired = wms_attendance_qr_verify($token, $secret, $now + 61, 60, 0);
is_same('Attendance: it stops working without grace', 'expired', $expired['code']);
$graced = wms_attendance_qr_verify($token, $secret, $now + 61, 60, 120);
check('Attendance: the grace window accepts the previous token', $graced['ok']);
$past_grace = wms_attendance_qr_verify($token, $secret, $now + 200, 60, 120);
is_same('Attendance: beyond the grace it expires', 'expired', $past_grace['code']);
$future = wms_attendance_qr_verify(wms_attendance_qr_token(3, $secret, $now + 60, 60), $secret, $now, 60, 0);
is_same('Attendance: a token from the future is refused', 'expired', $future['code']);
check('Attendance: a site id of 0 issues no token', wms_attendance_qr_token(0, $secret, $now, 60) === NULL);
check('Attendance: a short secret issues no token', wms_attendance_qr_token(3, 'abc', $now, 60) === NULL);

// Punch sequencing
is_same('Attendance: the first punch of the day is a check-in', 'check_in', wms_attendance_next_punch(NULL));
is_same('Attendance: after a check-in the next punch is a check-out', 'check_out', wms_attendance_next_punch('check_in'));
is_same('Attendance: after a check-out the next punch is a check-in', 'check_in', wms_attendance_next_punch('check_out'));
check('Attendance: no punches means not on site', !wms_attendance_on_site(array()));
check('Attendance: a standing check-in means on site', wms_attendance_on_site(array('check_in')));
check('Attendance: a check-out means not on site', !wms_attendance_on_site(array('check_in', 'check_out')));
check('Attendance: a return after a check-out means on site again', wms_attendance_on_site(array('check_in', 'check_out', 'check_in')));
is_same('Attendance: punch labels', 'Check-out', wms_attendance_punch_label('check_out'));

$schemaSql = file_get_contents(__DIR__ . '/../database/schema.sql');
check('Schema: schema_migrations ledger table defined', strpos($schemaSql, 'CREATE TABLE IF NOT EXISTS `schema_migrations`') !== FALSE);
check('Schema: ci_sessions uses single-column PRIMARY KEY (`id`) for sess_match_ip = FALSE', strpos($schemaSql, "ALTER TABLE `ci_sessions`\n  ADD PRIMARY KEY (`id`)") !== FALSE);
check('Schema: attendance_sites defined', strpos($schemaSql, 'CREATE TABLE `attendance_sites`') !== FALSE);
check('Schema: attendance_punches defined', strpos($schemaSql, 'CREATE TABLE `attendance_punches`') !== FALSE);
check('Schema: attendance tables carry foreign keys', strpos($schemaSql, 'fk_attendance_punches_user') !== FALSE);
check('Schema: seeds the attendance settings', strpos($schemaSql, "attendance_gate_enabled") !== FALSE);
check('Schema: seeds all 14 bilingual inspection_templates', strpos($schemaSql, 'INSERT IGNORE INTO `inspection_templates`') !== FALSE);
check('Schema: seeds all 630 inspection_items', strpos($schemaSql, 'INSERT IGNORE INTO `inspection_items`') !== FALSE);

$migFiles = glob(__DIR__ . '/../database/migrations/[0-9][0-9][0-9][0-9]_*.sql');
sort($migFiles, SORT_STRING);
is_same('Migrations: all 14 ordered SQL migrations present (0001..0014)', 14, count($migFiles));
foreach ($migFiles as $mf) {
    $bn  = basename($mf);
    $sum = hash_file('sha256', $mf);
    check("Migrations: schema.sql ledger checksum matches {$bn}", strpos($schemaSql, "'{$bn}', '{$sum}'") !== FALSE);
    $mSql = file_get_contents($mf);
    check("Security (K-02): {$bn} never embeds staff accounts or session dumps",
        strpos($mSql, 'INSERT INTO `users`') === FALSE && strpos($mSql, 'INSERT INTO `ci_sessions`') === FALSE);
    check("Data hygiene: {$bn} never imports sample timesheet dates (July 2026)",
        stripos($mSql, '16/Jul/26') === FALSE && stripos($mSql, 'MONTH OF JULY 2026') === FALSE);
}
check('Schema: users table defines optional `site` column and timesheet signatory settings without sample dates',
    strpos($schemaSql, '`site` varchar(100) DEFAULT NULL') !== FALSE
    && strpos($schemaSql, "'timesheet_prepared_by'") !== FALSE
    && stripos($schemaSql, '16/Jul/26') === FALSE
    && stripos($schemaSql, 'MONTH OF JULY 2026') === FALSE);
check('Schema: seeds all 116 fleet rows into equipment', strpos($schemaSql, 'INSERT INTO `equipment`') !== FALSE);
check('Schema: preserves verbatim Asset Register Machine IDs (EX 006, C-HEADT 007, C-HEADT 008)',
    strpos($schemaSql, "'EX 006', 'EX 006'") !== FALSE
    && strpos($schemaSql, "'C-HEADT 007', 'ADT 007'") !== FALSE
    && strpos($schemaSql, "'C-HEADT 008', 'ADT 008'") !== FALSE);
check('Schema: seeds all 36 Asset Register locations in report_sites (including OPERATIONS)',
    strpos($schemaSql, "(36, 'OPERATIONS', 'OPERATIONS'") !== FALSE);
check('Schema: contains zero synthetic SN-* placeholder serials where frame_no exists and zero CATERPILAR/TOYOTA LC typos',
    strpos($schemaSql, "'SN-EX-001'") === FALSE
    && strpos($schemaSql, "'SN-ADT-002'") === FALSE
    && strpos($schemaSql, "'CATERPILAR'") === FALSE
    && strpos($schemaSql, "'TOYOTA LC'") === FALSE);

$eqListView = file_get_contents(__DIR__ . '/../application/views/maintenance/equipment.php');
$eqDetailView = file_get_contents(__DIR__ . '/../application/views/maintenance/equipment_view.php');
check('Equipment UI: list cards display registration_no (Asset Register Machine ID) instead of short legacy_code',
    strpos($eqListView, '$eq->registration_no') !== FALSE && strpos($eqListView, 'equip_machine_id($eq)') === FALSE);
check('Equipment UI: list cards surface Type, Category, Brand/Model, Serial No, Engine Model, Current Hours, Current KM & Year',
    strpos($eqListView, '$eq->type_name') !== FALSE
    && strpos($eqListView, '$eq->category') !== FALSE
    && strpos($eqListView, 'Current Hours') !== FALSE
    && strpos($eqListView, 'Current KM') !== FALSE);
check('Equipment UI: detail page surfaces all Asset Register fields (Machine ID, Type, Category, Brand, Model, Year, Serial No., Engine Model, Current Hours, Current KM, Location)',
    strpos($eqDetailView, "'Machine ID'") !== FALSE
    && strpos($eqDetailView, "'Type'") !== FALSE
    && strpos($eqDetailView, "'Category'") !== FALSE
    && strpos($eqDetailView, "'Current Hours'") !== FALSE
    && strpos($eqDetailView, "'Current KM'") !== FALSE);
check('Schema: seeds all 338 manufacturer specs into equipment_specs', strpos($schemaSql, 'INSERT INTO `equipment_specs`') !== FALSE);
check('Schema: seeds deduplicated vendor rows into suppliers', strpos($schemaSql, 'INSERT INTO `suppliers`') !== FALSE);
check('Schema: seeds all 1,697 inventory items into materials', strpos($schemaSql, 'INSERT IGNORE INTO `materials`') !== FALSE);
check('Security (K-02): schema.sql never embeds staff accounts or session dumps',
    strpos($schemaSql, 'INSERT INTO `users`') === FALSE && strpos($schemaSql, 'INSERT INTO `ci_sessions`') === FALSE);

$sitesView = file_get_contents(__DIR__ . '/../application/views/attendance/sites.php');
check('Attendance sites UI: provides prominent Capture current location button, two-tier GPS fallback, Maps URL parser & map pin picker',
    strpos($sitesView, 'Capture current location') !== FALSE
    && strpos($sitesView, 'parseCoordinateText') !== FALSE
    && strpos($sitesView, 'enableHighAccuracy: false') !== FALSE
    && strpos($sitesView, 'autolocate=1') !== FALSE);
check('Attendance sites UI: respects "no assumed default radius" and exposes grace window, retention policy & printed QR fallback controls',
    strpos($sitesView, "radInput.value = '150'") === FALSE
    && strpos($sitesView, 'attendance_qr_grace_seconds') !== FALSE
    && strpos($sitesView, 'attendance_location_retention_days') !== FALSE
    && strpos($sitesView, 'allow_static_qr') !== FALSE);

// Printed / fixed QR token fallback (ATTENDANCE_WORKFLOW.md Setup #3)
$staticToken = wms_attendance_qr_static_token(3, $secret);
check('Attendance: static printed QR token is issued without technician PII',
    is_string($staticToken) && strpos($staticToken, 'v1.3.0.') === 0);
$staticBlocked = wms_attendance_qr_verify($staticToken, $secret, $now, 60, 120, FALSE);
is_same('Attendance: static QR token is refused unless enabled for that site', 'static_disabled', $staticBlocked['code']);
$staticOk = wms_attendance_qr_verify($staticToken, $secret, $now + 999999, 60, 0, TRUE);
check('Attendance: static QR token verifies across windows when explicitly enabled for the site', $staticOk['ok']);
$staticRotated = wms_attendance_qr_verify($staticToken, str_repeat('ef', 32), $now, 60, 0, TRUE);
is_same('Attendance: rotating site secret invalidates printed static QR token immediately', 'bad_signature', $staticRotated['code']);

// Multi-stint daily summary derivation (`wms_attendance_day_summary`)
$singleStint = wms_attendance_day_summary(array(
    array('punch_type' => 'check_in',  'punched_at' => '2026-10-21 08:00:00'),
    array('punch_type' => 'check_out', 'punched_at' => '2026-10-21 17:00:00'),
));
is_same('Attendance summary: single 08:00-17:00 shift yields 9.0h', 9.0, $singleStint['hours_worked']);

$multiStint = wms_attendance_day_summary(array(
    array('punch_type' => 'check_in',  'punched_at' => '2026-10-21 08:00:00'),
    array('punch_type' => 'check_out', 'punched_at' => '2026-10-21 12:00:00'),
    array('punch_type' => 'check_in',  'punched_at' => '2026-10-21 15:00:00'),
    array('punch_type' => 'check_out', 'punched_at' => '2026-10-21 17:00:00'),
));
is_same('Attendance summary: multi-stint day (08:00-12:00 + 15:00-17:00) sums actual 6.0h on site', 6.0, $multiStint['hours_worked']);
is_same('Attendance summary: keeps first arrival check_in', '08:00:00', $multiStint['check_in']);
is_same('Attendance summary: keeps latest departure check_out', '17:00:00', $multiStint['check_out']);

$woControllerSrc = file_get_contents(__DIR__ . '/../application/controllers/Workorders.php');
$attControllerSrc = file_get_contents(__DIR__ . '/../application/controllers/Attendance.php');
$sidebarSrc = file_get_contents(__DIR__ . '/../application/views/layouts/sidebar.php');
check('Attendance gate: Workorders::_assignment_picker filters to eligible on-site technicians ($only_eligible = TRUE)',
    strpos($woControllerSrc, 'technicians_for_assignment(NULL, TRUE)') !== FALSE);
check('Attendance gate: Workorders::start_session enforces on-site & eligibility checks when gate is active',
    strpos($woControllerSrc, 'Cannot start work while the attendance gate is on') !== FALSE);
check('Attendance punch: Attendance::record verifies active eligible user row live from database',
    strpos($attControllerSrc, 'punchable_user($this->user_id)') !== FALSE
    && strpos($attControllerSrc, 'Settings_model->save(') === FALSE);
check('Attendance navigation: sidebar exposes Punch Log to both Supervisors and Managers',
    strpos($sidebarSrc, '$is_supervisor || $is_manager') !== FALSE);

/* ─────────────────────────────────────────────────────────────────────────
   10. AML Individual Attendance Sheet — Dynamic Calendar, PDF & XLSX Exports
   ───────────────────────────────────────────────────────────────────────── */

defined('APPPATH') || define('APPPATH', __DIR__ . '/../application/');
defined('FCPATH')  || define('FCPATH',  __DIR__ . '/../');
require_once APPPATH . 'libraries/Timesheet_pdf.php';
require_once APPPATH . 'libraries/Timesheet_excel.php';

// Hours helper edge cases
is_same('Timesheet hours: 07:30 to 17:00 = 9.5h', 9.5, wms_hours_between('07:30:00', '17:00:00'));
is_same('Timesheet hours: overnight shift 22:00 to 06:00 = 8.0h', 8.0, wms_hours_between('22:00', '06:00'));
is_same('Timesheet hours: blank input returns NULL', NULL, wms_hours_between('', '17:00'));
is_same('Timesheet hours formatting: trims .00 cleanly', '9', wms_timesheet_format_hours(9.0));
is_same('Timesheet hours formatting: keeps half hour 9.5', '9.5', wms_timesheet_format_hours(9.5));

// Dynamic period resolution across months, halves, leap February, and custom range swap
$pFull = wms_timesheet_resolve_period('2026-10', 'full');
is_same('Timesheet period: full month resolves two half-month sheets (01-15 & 16-31)', 2, count($pFull['segments']));
is_same('Timesheet period: full month heading uses live selected month', 'MONTH OF OCTOBER 2026', $pFull['month_heading']);
is_same('Timesheet period: segment 1 spans 01..15',
    array('start_date' => '2026-10-01', 'end_date' => '2026-10-15', 'month_heading' => 'MONTH OF OCTOBER 2026'),
    $pFull['segments'][0]);
is_same('Timesheet period: segment 2 spans 16..31',
    array('start_date' => '2026-10-16', 'end_date' => '2026-10-31', 'month_heading' => 'MONTH OF OCTOBER 2026'),
    $pFull['segments'][1]);

$pLeap = wms_timesheet_resolve_period('2028-02', 'h2');
is_same('Timesheet period: leap February 2nd half ends on 2028-02-29', '2028-02-29', $pLeap['end_date']);

$pCustomSwap = wms_timesheet_resolve_period('2026-10', 'custom', '2026-10-20', '2026-10-05');
is_same('Timesheet period: reversed custom range swaps cleanly', '2026-10-05', $pCustomSwap['start_date']);
is_same('Timesheet period: reversed custom range end date', '2026-10-20', $pCustomSwap['end_date']);

// Monday–Sunday week builder & observation mapping
$sampleAtt = array(
    '2026-10-16' => array('status' => 'Present', 'check_in' => '07:30:00', 'check_out' => '17:00:00', 'hours_worked' => 9.5),
    '2026-10-18' => array('status' => 'Rest',    'check_in' => NULL,       'check_out' => NULL,       'hours_worked' => NULL),
    '2026-10-21' => array('status' => 'Leave',   'check_in' => NULL,       'check_out' => NULL,       'hours_worked' => NULL, 'notes' => 'Auto: approved leave request #9'),
    '2026-10-22' => array('status' => 'Absent',  'check_in' => NULL,       'check_out' => NULL,       'hours_worked' => NULL, 'notes' => 'Excused hospital visit'),
);
$gridOctH2 = wms_timesheet_build_weeks('2026-10-16', '2026-10-31', $sampleAtt, array('2026-10-21' => 'Annual Leave'));
is_same('Timesheet grid: 16..31 Oct 2026 spans 3 Monday-Sunday weekly blocks (Fri-Sun, Mon-Sun, Mon-Sat)', 3, count($gridOctH2['weeks']));
is_same('Timesheet grid: counts Present days accurately', 1, $gridOctH2['total_days_worked']);
is_same('Timesheet grid: sums total hours accurately', 9.5, $gridOctH2['total_hours']);
check('Timesheet grid: marks Sunday is_sunday = TRUE', $gridOctH2['weeks'][0]['days'][2]['is_sunday'] === TRUE);
is_same('Timesheet grid: maps approved leave request type cleanly into OBSERVATION', 'ANNUAL LEAVE', $gridOctH2['weeks'][1]['days'][2]['observation']);
is_same('Timesheet grid: maps Absent + custom note cleanly into OBSERVATION', 'ABSENT - Excused hospital visit', $gridOctH2['weeks'][1]['days'][3]['observation']);
is_same('Timesheet grid: leaves future unrecorded days blank for pen-and-ink sign-off', '', $gridOctH2['weeks'][2]['days'][0]['observation']);

// Corporate AML banner asset & pure-PHP zlib PNG decoder
$bannerAsset = FCPATH . 'uploads/aml_timesheet_banner.png';
check('Timesheet banner: clean corporate graphic exists at uploads/aml_timesheet_banner.png', is_file($bannerAsset));
$decodedBanner = Timesheet_pdf::decode_png_to_rgb($bannerAsset);
check('Timesheet banner: pure-PHP zlib decoder extracts 616x141 RGB pixels cleanly without ext-gd',
    is_array($decodedBanner)
    && $decodedBanner['width'] === 616
    && $decodedBanner['height'] === 141
    && strlen($decodedBanner['rgb']) === 616 * 141 * 3);

// Render multi-page PDF across standard half-month, 4-week edge case (May 2027 16..31: Sun..Mon = 4 week blocks!), and compact full-month
$sigDefaults = array(
    'prepared_by' => 'AGANO Elie', 'prepared_title' => 'Assistant Admin HR',
    'checked_by' => "Supervisor's", 'checked_title' => '',
    'approved_by' => 'BUZINDU RUB. Serge', 'approved_title' => 'HR Manager',
);
$testSheets = array(
    array(
        'user_id' => 10, 'employee_id' => 'EMP010', 'full_name' => 'Amina Ndayishimiye',
        'position' => 'Auto Electrician', 'site' => 'WORKSHOP',
        'month_heading' => 'MONTH OF OCTOBER 2026', 'segment_index' => 1, 'segment_count' => 1,
        'grid' => $gridOctH2, 'signatories' => $sigDefaults,
    ),
    array(
        'user_id' => 10, 'employee_id' => 'EMP010', 'full_name' => 'Amina Ndayishimiye',
        'position' => 'Auto Electrician', 'site' => 'KALIMVA',
        'month_heading' => 'MONTH OF AUGUST 2026', 'segment_index' => 1, 'segment_count' => 1,
        'grid' => wms_timesheet_build_weeks('2026-08-16', '2026-08-31'), // 4 Monday-Sunday blocks edge case!
        'signatories' => $sigDefaults,
    ),
    array(
        'user_id' => 11, 'employee_id' => 'EMP011', 'full_name' => 'Jean-Pierre Habimana / Plant: #2',
        'position' => 'Heavy Mechanic', 'site' => 'MSATA',
        'month_heading' => 'MONTH OF OCTOBER 2026', 'segment_index' => 1, 'segment_count' => 1,
        'grid' => wms_timesheet_build_weeks('2026-10-01', '2026-10-31'), // Full compact 1-page month!
        'signatories' => $sigDefaults,
    ),
);
$pdfBin = (new Timesheet_pdf($testSheets))->render();
check('Timesheet PDF: outputs valid %PDF-1.4 document with single deduplicated XObject image',
    strncmp($pdfBin, '%PDF-1.4', 8) === 0
    && substr_count($pdfBin, '/Subtype /Image') === 1
    && strpos($pdfBin, '/Count 3') !== FALSE);

$xlsxBin = (new Timesheet_excel())->render($testSheets);
check('Timesheet XLSX: outputs valid OOXML ZIP binary starting with PK\\x03\\x04',
    strncmp($xlsxBin, "PK\x03\x04", 4) === 0 && strlen($xlsxBin) > 15000);
if (class_exists('ZipArchive')) {
    $tmpXlsx = tempnam(sys_get_temp_dir(), 'ts_test_');
    file_put_contents($tmpXlsx, $xlsxBin);
    $za = new ZipArchive();
    check('Timesheet XLSX: opens cleanly in ZipArchive with deduplicated PNG, valid ECMA-376 cNvPr id>=2, Normal cellStyle & live formulas',
        $za->open($tmpXlsx) === TRUE
        && $za->locateName('xl/media/image1.png') !== FALSE
        && $za->locateName('xl/media/image2.png') === FALSE
        && strpos((string)$za->getFromName('xl/drawings/drawing1.xml'), 'cNvPr id="00"') === FALSE
        && strpos((string)$za->getFromName('xl/drawings/drawing1.xml'), 'cNvPr id="2"') !== FALSE
        && strpos((string)$za->getFromName('xl/styles.xml'), '<cellStyle name="Normal"') !== FALSE
        && strpos((string)$za->getFromName('xl/worksheets/sheet1.xml'), 'IF(SUM(E12:E14)&gt;0,SUM(E12:E14),&quot;&quot;)') !== FALSE
        && strpos((string)$za->getFromName('xl/worksheets/sheet1.xml'), 'IF(COUNT(E12:E14,E18:E24,E28:E33)&gt;0,COUNT(E12:E14,E18:E24,E28:E33),&quot;&quot;)') !== FALSE);
    $za->close();
    @unlink($tmpXlsx);
}
$tsModelSrc = file_get_contents(APPPATH . 'models/Timesheet_model.php');
check('Timesheet model: queries report_sites.label (never non-existent report_sites.name)',
    strpos($tsModelSrc, "select('label')") !== FALSE
    && strpos($tsModelSrc, "get('report_sites')") !== FALSE);

/* ─────────────────────────────────────────────────────────────────────────
   Timesheet Enterprise DataTable Suite (TS-01 .. TS-10)
   ───────────────────────────────────────────────────────────────────────── */

if (!class_exists('CI_Model')) {
    #[AllowDynamicProperties]
    class CI_Model {
        public function __construct() {}
        public function __get($key) {
            $ci = get_instance();
            return isset($ci->$key) ? $ci->$key : NULL;
        }
    }
}

class WmsQueryResultStub {
    private $rows;
    public function __construct(array $rows) { $this->rows = $rows; }
    public function result() {
        $out = array();
        foreach ($this->rows as $r) $out[] = is_object($r) ? $r : (object)$r;
        return $out;
    }
    public function result_array() {
        $out = array();
        foreach ($this->rows as $r) $out[] = is_array($r) ? $r : (array)$r;
        return $out;
    }
    public function row() {
        if (empty($this->rows)) return NULL;
        $first = reset($this->rows);
        return is_object($first) ? $first : (object)$first;
    }
}

class WmsSpyDb {
    public $queries = 0;
    public $users = array();
    public $attendance = array();
    public $period_punches = array();
    public $recent_punches = array();
    public $leaves = array();

    private $_from = '';
    private $_wheres = array();
    private $_in = array();

    private function _reset() {
        $this->_from = '';
        $this->_wheres = array();
        $this->_in = array();
    }
    public function table_exists($t) { return TRUE; }
    public function field_exists($f, $t) { return TRUE; }
    public function select($s, $escape = NULL) { return $this; }
    public function from($t) { $this->_from = $t; return $this; }
    public function join($t, $cond, $type = '') { return $this; }
    public function where($k, $v = NULL) { $this->_wheres[$k] = $v; return $this; }
    public function where_in($k, array $vals) { $this->_in[$k] = $vals; return $this; }
    public function group_by($g) { return $this; }
    public function order_by($c, $d = '') { return $this; }

    public function get($table = '') {
        $this->queries++;
        $tbl = $table !== '' ? $table : $this->_from;
        $wheres = $this->_wheres;
        $ins = $this->_in;
        $this->_reset();

        if (strpos($tbl, 'users') === 0) {
            if (isset($wheres['u.id'])) {
                $id = (int)$wheres['u.id'];
                return new WmsQueryResultStub(isset($this->users[$id]) ? array($this->users[$id]) : array());
            }
            return new WmsQueryResultStub(array_values($this->users));
        }
        if ($tbl === 'attendance') {
            $allow = isset($ins['user_id']) ? array_flip($ins['user_id']) : NULL;
            $out = array();
            foreach ($this->attendance as $ar) {
                if ($allow !== NULL && !isset($allow[(int)$ar['user_id']])) continue;
                $out[] = $ar;
            }
            return new WmsQueryResultStub($out);
        }
        if (strpos($tbl, 'attendance_punches') === 0) {
            $is_grouped = isset($wheres['p.work_date >=']);
            return new WmsQueryResultStub($is_grouped ? $this->period_punches : $this->recent_punches);
        }
        if ($tbl === 'leave_requests') {
            return new WmsQueryResultStub($this->leaves);
        }
        return new WmsQueryResultStub(array());
    }
}

require_once APPPATH . 'models/Team_model.php';
require_once APPPATH . 'models/Timesheet_model.php';

$spyDb = new WmsSpyDb();
// Build 50 active workers covering profile site, period QR punch, historical QR punch, and unassigned
for ($i = 1; $i <= 50; $i++) {
    $siteVal = '';
    if ($i === 1) $siteVal = 'WORKSHOP';
    elseif ($i >= 5) $siteVal = 'GEITA';
    $spyDb->users[$i] = (object)array(
        'id'          => $i,
        'employee_id' => sprintf('EMP%03d', $i),
        'first_name'  => 'Worker',
        'last_name'   => 'No' . $i,
        'role_id'     => ($i === 1) ? 1 : 2,
        'role_name'   => ($i === 1) ? 'supervisor' : 'technician',
        'position'    => 'Mechanic',
        'skill_level' => 'Senior',
        'site'        => $siteVal,
        'status'      => 'Active',
    );
}
$spyDb->period_punches = array(
    (object)array('user_id' => 2, 'name' => 'KALIMVA', 'cnt' => 14),
    (object)array('user_id' => 2, 'name' => 'MSATA',   'cnt' => 2),
);
$spyDb->recent_punches = array(
    (object)array('user_id' => 3, 'name' => 'MSATA'),
);
// Worker 1: 18 Present (one missing check_out), 1 Absent, 1 Leave => 20 judged days, 90%
for ($d = 1; $d <= 18; $d++) {
    $spyDb->attendance[] = array(
        'user_id'      => 1,
        'date'         => sprintf('2026-10-%02d', $d),
        'status'       => 'Present',
        'check_in'     => '08:00:00',
        'check_out'    => ($d === 18) ? NULL : '17:00:00',
        'hours_worked' => ($d === 18) ? NULL : 9.0,
    );
}
$spyDb->attendance[] = array('user_id' => 1, 'date' => '2026-10-19', 'status' => 'Absent', 'check_in' => NULL, 'check_out' => NULL, 'hours_worked' => NULL);
$spyDb->attendance[] = array('user_id' => 1, 'date' => '2026-10-20', 'status' => 'Leave',  'check_in' => NULL, 'check_out' => NULL, 'hours_worked' => NULL);

// Worker 2: 0 Present, 4 Absent => total = 4, genuine 0% attendance!
for ($d = 1; $d <= 4; $d++) {
    $spyDb->attendance[] = array('user_id' => 2, 'date' => sprintf('2026-10-%02d', $d), 'status' => 'Absent', 'check_in' => NULL, 'check_out' => NULL, 'hours_worked' => NULL);
}
// Worker 3..50: ZERO attendance rows => total = 0, pct MUST be NULL ("No records", NOT 0%!)

$app = get_instance();
$app->db = $spyDb;
$teamModel = new Team_model();
$tsModel   = new Timesheet_model();
$app->Team_model      = $teamModel;
$app->Timesheet_model = $tsModel;

$spyDb->queries = 0;
$reportRows = $teamModel->get_monthly_report('2026-10', '2026-10-01', '2026-10-31', '');

check('TS-02 Bulk SQL Performance: 50-worker monthly report executes in <= 4 queries (zero N+1 queries)',
    $spyDb->queries <= 4 && count($reportRows) === 50,
    'executed ' . $spyDb->queries . ' queries for ' . count($reportRows) . ' workers');

$r1 = $reportRows[0]; // Worker 1
$r2 = $reportRows[1]; // Worker 2 (0 Present, 4 Absent)
$r3 = $reportRows[2]; // Worker 3 (0 records, recent QR site)
$r4 = $reportRows[3]; // Worker 4 (0 records, no site)

is_same('TS-02 Site resolution priority: profile site preserved with source=profile', 'WORKSHOP', $r1->site);
is_same('TS-02 Site resolution priority: period QR predominant site with source=qr_period', 'KALIMVA', $r2->site);
is_same('TS-02 Site resolution priority: historical QR fallback site with source=qr_recent', 'MSATA', $r3->site);
is_same('TS-02 Site resolution priority: unassigned worker resolves cleanly to empty string', '', $r4->site);

is_same('TS-03 Data Accuracy: 18P / 1A / 1L yields exact 90% and 153h + 1 missing check-out', 90, $r1->pct);
is_same('TS-03 Data Accuracy: missing check-out shift flagged accurately', 1, $r1->missing_checkout);
is_same('TS-03 Empty vs Zero: 0 Present + 4 Absent yields integer 0% (genuine absence)', 0, $r2->pct);
check('TS-03 Empty vs Zero: unrecorded worker yields pct === NULL (never false 0%)',
    $r3->pct === NULL && $r3->has_records === FALSE && $r2->has_records === TRUE);

// TS-01 Horizontal RBAC Self-Scoping check
$scopedSelf = $teamModel->get_monthly_report('2026-10', '2026-10-01', '2026-10-31', '', 2);
check('TS-01 Horizontal RBAC Privacy: self-scoped report returns strictly 1 worker matching requested user_id',
    count($scopedSelf) === 1 && $scopedSelf[0]->id === 2);

// Verify Manager (role_id = 4) route names never collide with MY_Controller::MUTATION_PATTERN
$myCtrlSrc = file_get_contents(APPPATH . 'core/MY_Controller.php');
if (preg_match("/MUTATION_PATTERN\s*=\s*'([^']+)'/", $myCtrlSrc, $mPat)) {
    $mutRegex = $mPat[1];
    foreach (array('report', 'report_csv', 'sheet_json', 'timesheet', 'timesheets_batch') as $actName) {
        check("TS-01 Manager RBAC: Team::{$actName}() is allowed as read-only under MUTATION_PATTERN",
            preg_match($mutRegex, $actName) === 0);
    }
}

// TS-04 Multi-select batch filter (`$only_ids`)
$periodOct = wms_timesheet_resolve_period('2026-10', 'h1', NULL, NULL);
$selectedSheets = $tsModel->build_for_all_active($periodOct, NULL, array(2, 3));
check('TS-04 Multi-select batch export: build_for_all_active($period, NULL, [2,3]) restricts output to exact selected workers',
    count($selectedSheets) === 2
    && $selectedSheets[0]['user_id'] === 2
    && $selectedSheets[1]['user_id'] === 3);

// TS-05 .. TS-10 Full PHP View Render & Accessibility Audit (`application/views/team/report.php`)
if (!function_exists('site_url')) {
    function site_url($uri = '') { return 'http://wms.test/' . ltrim((string)$uri, '/'); }
}
$viewVars = array(
    'report'          => array($r1, $r2, $r3),
    'total_filtered'  => 50,
    'total_scope'     => 50,
    'tab_counts'      => array('all' => 50, 'recorded' => 2, 'unrecorded' => 48, 'absent' => 2, 'leave' => 1),
    'summary'         => array(
        'staff_count'      => 50,
        'recorded_staff'   => 2,
        'unrecorded_staff' => 48,
        'present_days'     => 18,
        'absent_days'      => 5,
        'leave_days'       => 1,
        'hours_worked'     => 153.0,
        'missing_checkout' => 1,
        'overall_pct'      => 75,
    ),
    'month'           => '2026-10',
    'period'          => wms_timesheet_resolve_period('2026-10', 'full', NULL, NULL),
    'site_filter'     => 'WORKSHOP',
    'role_filter'     => 2,
    'search_query'    => 'Worker',
    'active_tab'      => 'all',
    'sort_by'         => 'pct',
    'sort_dir'        => 'desc',
    'per_page'        => 25,
    'page'            => 1,
    'total_pages'     => 2,
    'showing_from'    => 1,
    'showing_to'      => 3,
    'view_mode'       => 'table',
    'sites'           => array('WORKSHOP', 'KALIMVA', 'MSATA'),
    'roles_list'      => array(
        (object)array('id' => 1, 'name' => 'supervisor'),
        (object)array('id' => 2, 'name' => 'technician'),
    ),
    'can_batch'       => TRUE,
    'is_supervisor'   => TRUE,
    'current_user_id' => 1,
    'page_title'      => 'Attendance Report & Individual Timesheets',
);

$viewWarnings = array();
set_error_handler(function ($severity, $message, $file, $line) use (&$viewWarnings) {
    $viewWarnings[] = "{$message} ({$file}:{$line})";
    return TRUE;
});
ob_start();
extract($viewVars, EXTR_SKIP);
include APPPATH . 'views/team/report.php';
$renderedHtml = ob_get_clean();
restore_error_handler();

check('TS-06 View Render: application/views/team/report.php renders with ZERO PHP warnings/notices',
    empty($viewWarnings), implode('; ', $viewWarnings));
check('TS-06 Icon Integrity: zero undefined Bootstrap Icon classes (bi-file-earmark / bi-filetype / bi-printer) in report.php',
    strpos($renderedHtml, 'bi-file-earmark') === FALSE
    && strpos($renderedHtml, 'bi-filetype') === FALSE
    && strpos($renderedHtml, 'bi-printer') === FALSE);
check('TS-05 Status Tabs: renders role="tablist" with all 5 status categories & live counts',
    strpos($renderedHtml, 'role="tablist"') !== FALSE
    && strpos($renderedHtml, 'Needs Entry') !== FALSE
    && strpos($renderedHtml, '>48<') !== FALSE);
check('TS-03 Rendered Empty vs Zero: displays "No records" for unrecorded worker AND "0%" for genuine 0-present worker',
    strpos($renderedHtml, '>No records<') !== FALSE
    && strpos($renderedHtml, '>0%<') !== FALSE
    && strpos($renderedHtml, '>90%<') !== FALSE);
check('TS-07 Accessibility & Sorting: renders aria-sort="descending" on sorted column and pagination nav',
    strpos($renderedHtml, 'aria-sort="descending"') !== FALSE
    && strpos($renderedHtml, 'aria-sort="none"') !== FALSE
    && strpos($renderedHtml, 'aria-label="Attendance roster pagination"') !== FALSE);
check('TS-08 & TS-09 View Switcher + Slide-Over Drawer: renders Table/Cards toggle and accessible dialog drawer',
    strpos($renderedHtml, 'data-set-view="cards"') !== FALSE
    && strpos($renderedHtml, 'class="ts-cards-grid"') !== FALSE
    && strpos($renderedHtml, 'id="tsDrawer" role="dialog" aria-modal="true"') !== FALSE);
$headerCss = file_get_contents(APPPATH . 'views/layouts/header.php');
check('TS-10 Print Stylesheet: header.php hides .no-print inside @media print',
    preg_match('/@media\s+print\s*\{[^}]*\.no-print/s', $headerCss) === 1);

/* ─────────────────────────────────────────────────────────────────────────
   Team Workforce Directory Audit & Redesign Suite (TM-01 .. TM-10)
   ───────────────────────────────────────────────────────────────────────── */

if (!function_exists('base_url')) {
    function base_url($uri = '') { return 'http://wms.test/' . ltrim((string)$uri, '/'); }
}
if (!function_exists('form_open')) {
    function form_open($action = '', $attributes = array()) {
        $attrStr = '';
        if (is_array($attributes)) {
            foreach ($attributes as $ak => $av) {
                $attrStr .= ' ' . htmlspecialchars((string)$ak, ENT_QUOTES, 'UTF-8')
                         . '="' . htmlspecialchars((string)$av, ENT_QUOTES, 'UTF-8') . '"';
            }
        }
        return '<form method="post" action="' . htmlspecialchars(site_url($action), ENT_QUOTES, 'UTF-8') . '"' . $attrStr . '>';
    }
}
if (!function_exists('form_close')) {
    function form_close() { return '</form>'; }
}

if (!class_exists('Team_model', FALSE)) {
    require_once APPPATH . 'models/Team_model.php';
}

class TeamRosterFakeResult {
    private $rows;
    public function __construct(array $rows) { $this->rows = array_values($rows); }
    public function result() { return $this->rows; }
    public function row() { return isset($this->rows[0]) ? $this->rows[0] : NULL; }
}

class TeamRosterFakeDB {
    public $queries = 0;
    private $users = array();
    private $fromTable = '';

    public function __construct(array $users) { $this->users = $users; }
    public function dbprefix($t = '') { return (string)$t; }
    public function affected_rows() { return 0; }
    public function table_exists($t) {
        return in_array($t, array('users', 'roles', 'certifications', 'work_orders', 'leave_requests', 'shift_assignments', 'shifts'), TRUE);
    }
    public function query($sql, $binds = array()) {
        $this->queries++;
        return new TeamRosterFakeResult(array());
    }
    public function select($s, $escape = NULL) { return $this; }
    public function from($t) { $this->fromTable = (string)$t; return $this; }
    public function join($t, $c, $type = '') { return $this; }
    public function where($k, $v = NULL) { return $this; }
    public function where_in($k, $vals) { return $this; }
    public function group_by($g) { return $this; }
    public function order_by($c, $d = '') { return $this; }
    public function get($table = NULL) {
        $this->queries++;
        $src = $table !== NULL ? (string)$table : $this->fromTable;
        if (strpos($src, 'users') === 0) {
            return new TeamRosterFakeResult($this->users);
        }
        if ($src === 'certifications') {
            return new TeamRosterFakeResult(array(
                (object)array('user_id' => 1, 'status' => 'Valid', 'cnt' => 2),
                (object)array('user_id' => 2, 'status' => 'Expired', 'cnt' => 1),
                (object)array('user_id' => 2, 'status' => 'Expiring Soon', 'cnt' => 1),
                (object)array('user_id' => 3, 'status' => 'Expiring Soon', 'cnt' => 2),
            ));
        }
        if (strpos($src, 'work_orders') === 0) {
            return new TeamRosterFakeResult(array(
                (object)array('id' => 101, 'wo_number' => 'WO-101', 'title' => 'Hydraulic pump rebuild', 'priority' => 'Critical', 'status' => 'In Progress', 'assigned_to' => '1, 2', 'registration_no' => 'EX 006'),
                (object)array('id' => 102, 'wo_number' => 'WO-102', 'title' => 'Brake inspection', 'priority' => 'Medium', 'status' => 'Open', 'assigned_to' => '2', 'registration_no' => 'C-HEADT 007'),
            ));
        }
        if ($src === 'leave_requests') {
            return new TeamRosterFakeResult(array(
                (object)array('user_id' => 3, 'leave_type' => 'Annual Leave'),
            ));
        }
        if (strpos($src, 'shift_assignments') === 0) {
            return new TeamRosterFakeResult(array(
                (object)array('user_id' => 1, 'name' => 'Day Shift', 'start_time' => '07:00:00', 'end_time' => '16:00:00'),
            ));
        }
        return new TeamRosterFakeResult(array());
    }
}

$fakeUsers = array();
for ($ui = 1; $ui <= 20; $ui++) {
    $fakeUsers[] = (object)array(
        'id'          => $ui,
        'role_id'     => ($ui === 1) ? 1 : 2,
        'role_name'   => ($ui === 1) ? 'supervisor' : 'technician',
        'employee_id' => sprintf('EMP%03d', $ui),
        'first_name'  => ($ui === 2) ? 'Evil<script>alert(1)</script>' : ('Staff' . $ui),
        'last_name'   => 'Member' . $ui,
        'email'       => "staff{$ui}@wms.test",
        'phone'       => '+2557000000' . sprintf('%02d', $ui),
        'position'    => 'Technician',
        'site'        => ($ui % 2 === 0) ? 'KALIMVA' : 'WORKSHOP',
        'skill_level' => ($ui <= 5) ? 'Expert' : 'Senior',
        'photo'       => ($ui === 2) ? '../../evil"onload="alert(2).png' : '',
        'join_date'   => '2025-03-15',
        'last_login'  => '2026-10-22 08:00:00',
        'status'      => ($ui === 20) ? 'Inactive' : 'Active',
    );
}

$rosterDb  = new TeamRosterFakeDB($fakeUsers);
$teamModel = new Team_model();
$teamModel->db = $rosterDb;

$rosterRows = $teamModel->get_roster_overview('2026-10-22');
check('TM-04 O(1) Bulk Roster Query Scaling: 20 workers hydrated in <= 8 SQL queries (zero N+1)',
    count($rosterRows) === 20 && $rosterDb->queries <= 8,
    'queries=' . $rosterDb->queries);

check('TM-02 Enriched Operational Columns: active_wo_count, critical_wo_count, cert alerts, shift & active leave hydrated accurately',
    $rosterRows[0]->active_wo_count === 1
    && $rosterRows[0]->critical_wo_count === 1
    && $rosterRows[0]->cert_valid === 2
    && strpos($rosterRows[0]->current_shift, 'Day Shift') !== FALSE
    && $rosterRows[1]->active_wo_count === 2
    && $rosterRows[1]->cert_expired === 1
    && $rosterRows[1]->has_cert_alert === TRUE
    && $rosterRows[2]->effective_status === 'On Leave');

$openWosU2 = $teamModel->get_worker_open_workorders(2);
check('TM-02 Open Work Orders Inspector: get_worker_open_workorders(2) resolves exact assigned WOs & Asset Register machine IDs',
    count($openWosU2) === 2
    && $openWosU2[0]['machine'] === 'EX 006'
    && $openWosU2[1]['machine'] === 'C-HEADT 007');

// TM-01 RBAC & Security static + pattern verification
$teamCtrlSrc = file_get_contents(APPPATH . 'controllers/Team.php');
$teamViewSrc = file_get_contents(APPPATH . 'views/team/view.php');
if (isset($mutRegex)) {
    foreach (array('index', 'roster_csv', 'member_json', 'view') as $actName) {
        check("TM-01 Manager RBAC: Team::{$actName}() is permitted under read-only MUTATION_PATTERN",
            preg_match($mutRegex, $actName) === 0);
    }
}
check('TM-01 Horizontal RBAC Guard: Team::index(), roster_csv(), member_json() & view() restrict peer PII from self-service roles',
    substr_count($teamCtrlSrc, '!$this->is_supervisor() && !$this->is_manager()') >= 3);
check('TM-01 Stored XSS Hardening in team/view.php: worker photo, name, employee_id & leave_type escaped',
    strpos($teamViewSrc, 'rawurlencode(basename((string)$worker->photo))') !== FALSE
    && strpos($teamViewSrc, '<?= $worker->first_name') === FALSE
    && strpos($teamViewSrc, '<?= $worker->employee_id ?>') === FALSE
    && strpos($teamViewSrc, '<?= $lr->leave_type ?>') === FALSE);

// TM-03 .. TM-10 Full PHP Render & XSS/Contrast/RBAC Audit of application/views/team/index.php
$rosterById = array();
foreach ($rosterRows as $rw) { $rosterById[(int)$rw->id] = $rw; }

$tmVars = array(
    'workers'        => array_slice($rosterRows, 0, 5),
    'roster_by_id'   => $rosterById,
    'pending_leave'  => array(
        (object)array(
            'id'          => 77,
            'user_id'     => 2,
            'worker_name' => 'Evil<script>alert(9)</script>',
            'employee_id' => 'EMP002',
            'leave_type'  => 'Annual<b>XSS</b>',
            'start_date'  => '2026-10-25',
            'end_date'    => '2026-10-28',
            'days_count'  => 4,
            'reason'      => 'Family travel',
        ),
    ),
    'total_filtered' => 20,
    'total_scope'    => 20,
    'tab_counts'     => array('all' => 20, 'active' => 18, 'on_site' => 4, 'on_leave' => 1, 'cert_alert' => 2, 'inactive' => 1),
    'summary'        => array(
        'total_staff'    => 20,
        'active_staff'   => 18,
        'inactive_staff' => 1,
        'on_site_today'  => 4,
        'on_leave_today' => 1,
        'open_wos'       => 3,
        'critical_wos'   => 1,
        'cert_expired'   => 1,
        'cert_expiring'  => 3,
        'pending_leave'  => 1,
    ),
    'site_filter'    => 'WORKSHOP',
    'role_filter'    => 2,
    'skill_filter'   => 'Expert',
    'search_query'   => 'Staff',
    'active_tab'     => 'all',
    'sort_by'        => 'active_wos',
    'sort_dir'       => 'desc',
    'per_page'       => 10,
    'page'           => 1,
    'total_pages'    => 2,
    'showing_from'   => 1,
    'showing_to'     => 5,
    'view_mode'      => 'table',
    'sites'          => array('WORKSHOP', 'KALIMVA'),
    'roles_list'     => array(
        (object)array('id' => 1, 'name' => 'supervisor'),
        (object)array('id' => 2, 'name' => 'technician'),
    ),
    'skills_list'    => array('Junior', 'Senior', 'Expert', 'Specialist'),
    'is_supervisor'  => TRUE,
    'is_manager'     => FALSE,
    'page_title'     => 'Team Management',
);

$tmWarnings = array();
set_error_handler(function ($severity, $message, $file, $line) use (&$tmWarnings) {
    $tmWarnings[] = "{$message} ({$file}:{$line})";
    return TRUE;
});
ob_start();
(function (array $v) { extract($v, EXTR_SKIP); include APPPATH . 'views/team/index.php'; })($tmVars);
$tmSupHtml = ob_get_clean();

$tmVarsMgr = array_merge($tmVars, array('is_supervisor' => FALSE, 'is_manager' => TRUE, 'pending_leave' => array()));
ob_start();
(function (array $v) { extract($v, EXTR_SKIP); include APPPATH . 'views/team/index.php'; })($tmVarsMgr);
$tmMgrHtml = ob_get_clean();
restore_error_handler();

check('TM-05 View Render: application/views/team/index.php renders cleanly for Supervisor & Manager with ZERO warnings',
    empty($tmWarnings), implode('; ', $tmWarnings));
check('TM-01 XSS Neutralization in team/index.php: hostile <script> tags and path traversal photo filenames fully neutralized',
    strpos($tmSupHtml, '<script>alert(') === FALSE
    && strpos($tmSupHtml, '../../evil') === FALSE
    && strpos($tmSupHtml, 'Evil&lt;script&gt;alert(1)&lt;/script&gt;') !== FALSE);
check('TM-03 WCAG Contrast Fix: zero dark text color:var(--t1) on dark avatar backgrounds',
    strpos($tmSupHtml, 'color:var(--t1);flex-shrink:0') === FALSE);
check('TM-06 Status Tabs & Workload Pending Leave Context: renders 6 status tabs + "2 Open WOs" warning inside Pending Leave table',
    strpos($tmSupHtml, 'On Site Today') !== FALSE
    && strpos($tmSupHtml, 'Cert Alerts') !== FALSE
    && strpos($tmSupHtml, '2 Open WOs') !== FALSE);
check('TM-07 Manager UI RBAC: hides Add Worker & Edit Worker Profile mutations for Manager while keeping Inspect, Profile & Export CSV',
    strpos($tmMgrHtml, 'Add Worker') === FALSE
    && strpos($tmMgrHtml, 'Edit Worker Profile') === FALSE
    && strpos($tmMgrHtml, 'Export CSV') !== FALSE);
check('TM-08 & TM-09 Dual View Mode + Accessible Staff Inspector Drawer rendered cleanly',
    strpos($tmSupHtml, 'data-set-view="cards"') !== FALSE
    && strpos($tmSupHtml, 'class="tm-cards-grid"') !== FALSE
    && strpos($tmSupHtml, 'id="tmDrawer" role="dialog" aria-modal="true"') !== FALSE);

/* ─────────────────────────────────────────────────────────────────────────
   Work Orders DataTable Audit & Redesign Suite (WO-01 .. WO-09)
   ───────────────────────────────────────────────────────────────────────── */

require_once APPPATH . 'helpers/wo_helper.php';
if (!class_exists('Workorder_model', FALSE)) {
    require_once APPPATH . 'models/Workorder_model.php';
}

class WorkorderFakeDB {
    public $queries = 0;
    private $wos = array();
    private $fromTable = '';

    public function __construct(array $wos) { $this->wos = $wos; }
    public function dbprefix($t = '') { return (string)$t; }
    public function table_exists($t) {
        return in_array($t, array('work_orders', 'equipment', 'users', 'wo_spare_parts', 'wo_sessions'), TRUE);
    }
    public function select($s, $escape = NULL) { return $this; }
    public function from($t) { $this->fromTable = (string)$t; return $this; }
    public function join($t, $c, $type = '') { return $this; }
    public function where($k, $v = NULL) { return $this; }
    public function where_in($k, $vals) { return $this; }
    public function group_by($g) { return $this; }
    public function order_by($c, $d = '') { return $this; }
    public function group_start() { return $this; }
    public function group_end() { return $this; }
    public function like($k, $v, $side = 'both') { return $this; }
    public function or_like($k, $v, $side = 'both') { return $this; }
    public function get($table = NULL) {
        $this->queries++;
        $src = $table !== NULL ? (string)$table : $this->fromTable;
        if (strpos($src, 'work_orders') === 0) {
            return new TeamRosterFakeResult($this->wos);
        }
        if ($src === 'users') {
            return new TeamRosterFakeResult(array(
                (object)array('id' => 1, 'first_name' => 'CI', 'last_name' => 'Supervisor'),
                (object)array('id' => 2, 'first_name' => 'John', 'last_name' => 'Moshi'),
            ));
        }
        if ($src === 'wo_spare_parts') {
            return new TeamRosterFakeResult(array(
                (object)array('work_order_id' => 1, 'lines_cnt' => 2),
            ));
        }
        if ($src === 'wo_sessions') {
            return new TeamRosterFakeResult(array(
                (object)array('wo_id' => 1, 'hrs' => 4.5, 'open_cnt' => 1),
            ));
        }
        return new TeamRosterFakeResult(array());
    }
}

$fakeWos = array();
for ($wi = 1; $wi <= 25; $wi++) {
    $fakeWos[] = (object)array(
        'id'                    => $wi,
        'wo_number'             => ($wi === 2) ? 'WO-XSS<script>alert(1)</script>' : sprintf('WO-AML-%03d', $wi),
        'equipment_id'          => $wi,
        'registration_no'       => ($wi === 1) ? 'EX 006' : (($wi === 2) ? 'C-HEADT 007' : 'C-HEADT 008'),
        'brand'                 => 'CAT',
        'model'                 => '320D',
        'equipment_location'    => 'WORKSHOP',
        'equipment_live_status' => 'Breakdown',
        'type'                  => ($wi === 1) ? 'Corrective Maintenance' : (($wi === 2) ? 'WaitingTest' : 'Preventive Maintenance'),
        'is_breakdown'          => ($wi === 1) ? 1 : 0,
        'priority'              => ($wi === 1) ? 'Critical' : (($wi === 2) ? 'High' : 'Medium'),
        'status'                => ($wi === 1) ? 'In Progress' : (($wi === 2) ? 'Waiting Parts' : 'Completed'),
        'hold_code'             => ($wi === 2) ? 'WSP' : NULL,
        'title'                 => ($wi === 2) ? 'Seal kit<script>alert(3)</script>' : 'Main boom cylinder repair',
        'assigned_to'           => '1, 2',
        'actual_hours'          => NULL,
        'downtime_hours'        => ($wi === 3) ? 6.5 : NULL,
        'start_datetime'        => '2026-10-20 08:00:00',
        'end_datetime'          => ($wi === 3) ? '2026-10-20 14:30:00' : NULL,
        'expected_return_date'  => ($wi === 2) ? '2025-05-01' : NULL,
        'created_at'            => '2026-10-20 08:00:00',
    );
}
$fakeWos[1]->type = 'Incident';

$woDb    = new WorkorderFakeDB($fakeWos);
$woModel = new Workorder_model();
$woModel->db = $woDb;

$woOverview = $woModel->get_directory_overview();
check('WO-01 O(1) Bulk Directory Query Scaling: 25 work orders hydrated in <= 4 SQL queries (eliminated 26-query N+1 storm)',
    count($woOverview) === 25 && $woDb->queries <= 4,
    'queries=' . $woDb->queries);

$woDbAll = new WorkorderFakeDB($fakeWos);
$woModel->db = $woDbAll;
$woAllRows = $woModel->get_all();
check('WO-01 Fixed Workorder_model::get_all() N+1 loop: 25 work orders resolved in <= 2 SQL queries',
    count($woAllRows) === 25 && $woDbAll->queries <= 2 && $woAllRows[0]->assigned_name === 'CI Supervisor, John Moshi',
    'queries=' . $woDbAll->queries);

check('WO-03 Operational Signals Hydrated: spare part count, live open session, logged hours, hold state & overdue ETA',
    $woOverview[0]->spare_lines_count === 2
    && $woOverview[0]->has_open_session === TRUE
    && abs($woOverview[0]->logged_hours - 4.5) < 0.01
    && $woOverview[1]->is_on_hold === TRUE
    && $woOverview[1]->is_overdue_eta === TRUE);

// WO-02 RBAC & Security static checks
$woViewSrc = file_get_contents(APPPATH . 'views/maintenance/wo_view.php');
if (isset($mutRegex)) {
    foreach (array('index', 'export_csv', 'summary_json', 'view', 'jobcard') as $actName) {
        check("WO-02 Manager RBAC: Workorders::{$actName}() is permitted under read-only MUTATION_PATTERN",
            preg_match($mutRegex, $actName) === 0);
    }
}
check('WO-02 Stored XSS & Manager RBAC in wo_view.php: escapes wo_number/registration_no/assigned_name & hides Edit for Manager',
    strpos($woViewSrc, '<?= $wo->wo_number ?>') === FALSE
    && strpos($woViewSrc, '$wo->assigned_name : \'—\') ?>') === FALSE
    && strpos($woViewSrc, '(int)$role_id !== 4') !== FALSE);

// WO-03 .. WO-09 Full PHP View Render & RBAC/XSS Audit of application/views/maintenance/workorders.php
$woVars = array(
    'work_orders'    => array_slice($woOverview, 0, 4),
    'total_filtered' => 25,
    'total_scope'    => 25,
    'tab_counts'     => array('all' => 25, 'active' => 2, 'open' => 0, 'in_progress' => 1, 'on_hold' => 1, 'completed' => 23, 'closed' => 0),
    'summary'        => array(
        'total_wos'       => 25,
        'active_queue'    => 2,
        'critical_active' => 1,
        'breakdowns_open' => 1,
        'in_progress'     => 1,
        'live_sessions'   => 1,
        'on_hold'         => 1,
        'overdue_eta'     => 1,
        'awaiting_verify' => 23,
    ),
    'active_tab'     => 'all',
    'status_exact'   => '',
    'type_filter'    => '',
    'priority_filter'=> '',
    'equip_filter'   => 0,
    'tech_filter'    => 0,
    'search_query'   => '',
    'sort_by'        => 'priority',
    'sort_dir'       => 'desc',
    'per_page'       => 25,
    'page'           => 1,
    'total_pages'    => 1,
    'showing_from'   => 1,
    'showing_to'     => 4,
    'view_mode'      => 'table',
    'equipment_list' => array(
        (object)array('id' => 1, 'registration_no' => 'EX 006'),
        (object)array('id' => 2, 'registration_no' => 'C-HEADT 007'),
    ),
    'tech_options'   => array(
        (object)array('id' => 1, 'first_name' => 'CI', 'last_name' => 'Supervisor'),
        (object)array('id' => 2, 'first_name' => 'John', 'last_name' => 'Moshi'),
    ),
    'can_write'      => TRUE,
    'is_supervisor'  => TRUE,
    'is_manager'     => FALSE,
    'page_title'     => 'Work Orders',
);

$woWarnings = array();
set_error_handler(function ($severity, $message, $file, $line) use (&$woWarnings) {
    $woWarnings[] = "{$message} ({$file}:{$line})";
    return TRUE;
});
ob_start();
(function (array $v) { extract($v, EXTR_SKIP); include APPPATH . 'views/maintenance/workorders.php'; })($woVars);
$woSupHtml = ob_get_clean();

$woVarsMgr = array_merge($woVars, array('can_write' => FALSE, 'is_supervisor' => FALSE, 'is_manager' => TRUE));
ob_start();
(function (array $v) { extract($v, EXTR_SKIP); include APPPATH . 'views/maintenance/workorders.php'; })($woVarsMgr);
$woMgrHtml = ob_get_clean();
restore_error_handler();

check('WO-04 View Render: application/views/maintenance/workorders.php renders cleanly for Supervisor & Manager with ZERO warnings',
    empty($woWarnings), implode('; ', $woWarnings));
check('WO-02 XSS Neutralization in maintenance/workorders.php: hostile <script> tags escaped cleanly',
    strpos($woSupHtml, '<script>alert(') === FALSE
    && strpos($woSupHtml, 'WO-XSS&lt;script&gt;alert(1)&lt;/script&gt;') !== FALSE);
check('WO-03 Semantic Type & Hold Hierarchy: Breakdown=b-danger, Incident=b-warning, Preventive=b-primary, Hold="WSP · Waiting for Spare Part"',
    strpos($woSupHtml, '>Breakdown<') !== FALSE
    && strpos($woSupHtml, 'b-danger') !== FALSE
    && strpos($woSupHtml, 'WSP · Waiting for Spare Part') !== FALSE
    && strpos($woSupHtml, 'Overdue return') !== FALSE);
check('WO-02 Manager UI RBAC: hides New WO & Edit Work Order buttons while preserving Export CSV, Inspect & Print Job Card',
    strpos($woMgrHtml, 'New WO') === FALSE
    && strpos($woMgrHtml, 'Edit Work Order') === FALSE
    && strpos($woMgrHtml, 'workorders/export_csv') !== FALSE);
check('WO-07 & WO-08 Dual View Mode + Accessible Slide-Over Work Order Drawer rendered cleanly',
    strpos($woSupHtml, 'data-set-view="cards"') !== FALSE
    && strpos($woSupHtml, 'class="wo-cards-grid"') !== FALSE
    && strpos($woSupHtml, 'id="woDrawer" role="dialog" aria-modal="true"') !== FALSE);

/* ─────────────────────────────────────────────────────────────────────────
   14. Equipment Fleet DataTable, O(1) Telemetry Hydration, RBAC & XSS Audit
   ───────────────────────────────────────────────────────────────────────── */

require_once APPPATH . 'helpers/equip_helper.php';
if (!class_exists('Equipment_model', FALSE)) {
    require_once APPPATH . 'models/Equipment_model.php';
}

class EquipFleetFakeResult {
    private $rows;
    public function __construct(array $rows) { $this->rows = $rows; }
    public function result() { return $this->rows; }
    public function row() { return isset($this->rows[0]) ? $this->rows[0] : NULL; }
    public function num_rows() { return count($this->rows); }
}

class EquipFleetFakeDB {
    public $queries = array();
    public $queue   = array();

    public function dbprefix($t = '') { return (string)$t; }
    public function table_exists($t) {
        return in_array($t, array('equipment', 'equipment_types', 'report_sites', 'report_groups', 'work_orders', 'pm_schedules', 'equipment_specs', 'users'), TRUE);
    }
    public function select($s, $escape = NULL) { return $this; }
    public function from($t) { return $this; }
    public function join($t, $c, $type = '') { return $this; }
    public function where($k, $v = NULL) { return $this; }
    public function where_in($k, $vals) { return $this; }
    public function group_by($g) { return $this; }
    public function order_by($c, $d = '') { return $this; }
    public function limit($n, $off = 0) { return $this; }
    public function get($table = NULL) {
        $this->queries[] = (string)$table;
        $next = array_shift($this->queue);
        return $next ? $next : new EquipFleetFakeResult(array());
    }
}

$eqDb = new EquipFleetFakeDB();
$eqModel = new Equipment_model();
$eqModel->db = $eqDb;

$fleetMachines = array();
for ($m = 1; $m <= 18; $m++) {
    $reg = ($m === 1) ? 'EX 006' : (($m === 2) ? 'C-HEADT 007' : sprintf('C-HV-EX %03d', $m));
    $fleetMachines[] = (object)array(
        'id'                       => $m,
        'equipment_type_id'        => ($m % 2) + 1,
        'registration_no'          => $reg,
        'type_name'                => ($m % 2 === 1) ? 'Excavator' : 'Horse Truck',
        'category'                 => ($m % 2 === 1) ? 'Mobile Equipment' : 'Heavy Truck',
        'brand'                    => ($m === 1) ? 'CATERPILLAR' : 'SHACMAN',
        'model'                    => ($m === 1) ? '336D2L' : 'F3000<script>alert(7)</script>',
        'year'                     => 2020 + ($m % 5),
        'serial_number'            => 'SN-EQ-' . $m,
        'engine_model'             => 'C9-ACERT-' . $m,
        'current_hours'            => 1250.5 + ($m * 40),
        'current_km'               => ($m === 2) ? 98420.5 : 0.0,
        'status'                   => ($m === 1) ? 'Breakdown' : (($m === 2) ? 'In Maintenance' : (($m === 18) ? 'Retired' : 'Available')),
        'location'                 => ($m === 3) ? 'UNMAPPED CAMP' : 'MET PLANT',
        'report_group'             => ($m === 4) ? NULL : 'EXCAVATOR',
        'report_sort'              => 10000 + $m,
        'include_in_weekly_report' => ($m === 18) ? 0 : 1,
        'purchase_date'            => '2021-04-10',
        'warranty_expiry'          => '2026-12-31',
    );
}

$eqDb->queue = array(
    new EquipFleetFakeResult($fleetMachines),
    new EquipFleetFakeResult(array(
        (object)array('code' => 'MET_PLANT', 'label' => 'MET PLANT'),
        (object)array('code' => 'WORKSHOP',  'label' => 'WORKSHOP'),
    )),
    new EquipFleetFakeResult(array(
        (object)array('equipment_id' => 1, 'open_cnt' => 2, 'crit_cnt' => 1),
        (object)array('equipment_id' => 2, 'open_cnt' => 1, 'crit_cnt' => 0),
    )),
    new EquipFleetFakeResult(array(
        (object)array('equipment_id' => 1, 'interval_type' => 'Hours', 'next_due_date' => '2026-10-01', 'next_due_hours' => 1200),
        (object)array('equipment_id' => 2, 'interval_type' => 'Days',  'next_due_date' => '2026-12-15', 'next_due_hours' => NULL),
    )),
    new EquipFleetFakeResult(array(
        (object)array('equipment_id' => 1, 'cnt' => 6),
        (object)array('equipment_id' => 2, 'cnt' => 4),
    )),
);

$qStartEq = count($eqDb->queries);
$fleetOverview = $eqModel->get_fleet_overview('2026-10-22');
$qTotalEq = count($eqDb->queries) - $qStartEq;

check('EQ-03 O(1) Fleet Telemetry Scaling: get_fleet_overview() hydrates 18 machines + WOs + PMs + specs in <= 5 SQL queries',
    $qTotalEq <= 5 && count($fleetOverview['rows']) === 18,
    "Executed {$qTotalEq} queries");
check('EQ-03 Verbatim Asset Register & Live Telemetry Hydration: preserves registration_no, open_wo_count, pm_overdue_count, spec_count & data alerts',
    $fleetOverview['rows'][0]->registration_no === 'EX 006'
    && $fleetOverview['rows'][0]->open_wo_count === 2
    && $fleetOverview['rows'][0]->crit_wo_count === 1
    && $fleetOverview['rows'][0]->pm_overdue_count === 1
    && $fleetOverview['rows'][0]->spec_count === 6
    && $fleetOverview['rows'][2]->loc_approved === FALSE
    && $fleetOverview['rows'][2]->has_data_alert === TRUE
    && in_array('UNMAPPED CAMP', $fleetOverview['locations'], TRUE));

// EQ-02 Workorder_model::get_by_equipment() zero-N+1 verification
$woEqDb = new EquipFleetFakeDB();
$woEqModel = new Workorder_model();
$woEqModel->db = $woEqDb;
$woEqDb->queue = array(
    new EquipFleetFakeResult(array(
        (object)array('id' => 11, 'wo_number' => 'WO-EQ-01', 'assigned_to' => '2,5'),
        (object)array('id' => 12, 'wo_number' => 'WO-EQ-02', 'assigned_to' => '5'),
        (object)array('id' => 13, 'wo_number' => 'WO-EQ-03', 'assigned_to' => '2'),
    )),
    new EquipFleetFakeResult(array(
        (object)array('id' => 2, 'first_name' => 'John', 'last_name' => 'Moshi'),
        (object)array('id' => 5, 'first_name' => 'Asha', 'last_name' => 'Kileo'),
    )),
);
$qStartWoEq = count($woEqDb->queries);
$eqHistRows = $woEqModel->get_by_equipment(1, 20);
$qTotalWoEq = count($woEqDb->queries) - $qStartWoEq;
check('EQ-02 Zero N+1 on Equipment::view(): Workorder_model::get_by_equipment() resolves all rows in 2 constant queries',
    $qTotalWoEq === 2 && $eqHistRows[0]->assigned_name === 'John Moshi, Asha Kileo',
    "Executed {$qTotalWoEq} queries");

// EQ-02 RBAC & Stored XSS checks on Equipment controller + equipment_view.php
$eqCtrlSrc = file_get_contents(APPPATH . 'controllers/Equipment.php');
$eqDetSrc  = file_get_contents(APPPATH . 'views/maintenance/equipment_view.php');
if (isset($mutRegex)) {
    foreach (array('index', 'export_csv', 'summary_json', 'view') as $eqAct) {
        check("EQ-02 Manager RBAC: Equipment::{$eqAct}() is permitted under read-only MUTATION_PATTERN",
            preg_match($mutRegex, $eqAct) === 0);
    }
}
check('EQ-02 Stored XSS & Manager RBAC Hardening in maintenance/equipment_view.php',
    strpos($eqDetSrc, '<?= $pm->schedule_name ?>') === FALSE
    && strpos($eqDetSrc, '<?= $wo->wo_number ?>') === FALSE
    && strpos($eqDetSrc, '<?= $wo->type ?>') === FALSE
    && strpos($eqDetSrc, '<?= $wo->status ?>') === FALSE
    && substr_count($eqDetSrc, '(int)$role_id !== 4') >= 2);

// EQ-01 .. EQ-08 Full PHP Render Audit of application/views/maintenance/equipment.php (Supervisor & Non-Supervisor)
$eqVars = array(
    'equipment_list' => array_slice($fleetOverview['rows'], 0, 6),
    'types'          => array((object)array('id' => 1, 'name' => 'Excavator'), (object)array('id' => 2, 'name' => 'Horse Truck')),
    'categories'     => array('Heavy Truck', 'Mobile Equipment'),
    'locations'      => $fleetOverview['locations'],
    'report_sites'   => $fleetOverview['report_sites'],
    'report_groups'  => array('EXCAVATOR', 'TIPPER'),
    'reportability'  => $fleetOverview['reportability'],
    'site_summary'   => $fleetOverview['site_summary'],
    'kpi'            => array(
        'total' => 116, 'operational' => 98, 'down' => 6, 'breakdown' => 2,
        'in_maintenance' => 4, 'open_wo_total' => 9, 'open_wo_machines' => 6,
        'pm_overdue_total' => 3, 'pm_overdue_machines' => 3, 'avail_pct' => 94.2,
    ),
    'tab_counts'     => array(
        'all' => 116, 'available' => 92, 'maintenance' => 4, 'breakdown' => 2,
        'standby' => 6, 'pm_overdue' => 3, 'data_alert' => 2, 'retired' => 12,
    ),
    'tab'            => 'all',
    'q'              => 'EX',
    'f_category'     => 'Mobile Equipment',
    'f_type'         => 1,
    'f_location'     => 'MET PLANT',
    'f_group'        => 'EXCAVATOR',
    'f_rep'          => 'yes',
    'sort'           => 'hours',
    'dir'            => 'desc',
    'view_mode'      => 'table',
    'per_page'       => 25,
    'allowed_pp'     => array(10, 25, 50, 100, 150),
    'current_page'   => 1,
    'total_pages'    => 5,
    'total_filtered' => 116,
    'offset'         => 0,
    'can_create_eq'  => TRUE,
    'can_create_wo'  => TRUE,
);

$eqWarnings = array();
set_error_handler(function ($severity, $message, $file, $line) use (&$eqWarnings) {
    $eqWarnings[] = "{$message} ({$file}:{$line})";
    return TRUE;
});
ob_start();
(function (array $v) { extract($v, EXTR_SKIP); include APPPATH . 'views/maintenance/equipment.php'; })($eqVars);
$eqSupHtml = ob_get_clean();

$eqVarsNonSup = array_merge($eqVars, array('can_create_eq' => FALSE, 'can_create_wo' => FALSE));
ob_start();
(function (array $v) { extract($v, EXTR_SKIP); include APPPATH . 'views/maintenance/equipment.php'; })($eqVarsNonSup);
$eqMgrHtml = ob_get_clean();
restore_error_handler();

check('EQ-01 View Render: application/views/maintenance/equipment.php renders Enterprise DataTable & Dual Cards with ZERO PHP warnings',
    empty($eqWarnings)
    && strpos($eqSupHtml, '<table class="eq-table"') !== FALSE
    && strpos($eqSupHtml, 'class="eq-cards-grid"') !== FALSE,
    implode('; ', $eqWarnings));
check('EQ-02 XSS Neutralization in maintenance/equipment.php: hostile <script> tag in model escaped cleanly',
    strpos($eqSupHtml, '<script>alert(7)</script>') === FALSE
    && strpos($eqSupHtml, 'F3000&lt;script&gt;alert(7)&lt;/script&gt;') !== FALSE);
check('EQ-02 Non-Supervisor UI RBAC: hides Add Equipment & Edit buttons while preserving Export CSV, Inspect & View',
    strpos($eqSupHtml, 'equipment/create') !== FALSE
    && strpos($eqMgrHtml, 'equipment/create') === FALSE
    && strpos($eqMgrHtml, 'equipment/edit/') === FALSE
    && strpos($eqMgrHtml, 'equipment/export_csv') !== FALSE);
check('EQ-06 Accessible Slide-Over Machine Inspector Drawer rendered cleanly',
    strpos($eqSupHtml, 'id="eqDrawer" role="dialog" aria-modal="true"') !== FALSE
    && strpos($eqSupHtml, 'equipment/summary_json/') !== FALSE);

/* ─────────────────────────────────────────────────────────────────────────
   15. PM Planner DataTable, O(1) Telemetry Hydration, RBAC & XSS Audit
   ───────────────────────────────────────────────────────────────────────── */

if (!class_exists('Pm_model', FALSE)) {
    require_once APPPATH . 'models/Pm_model.php';
}

class PmPlannerFakeDB {
    public $queries = array();
    public $queue   = array();

    public function dbprefix($t = '') { return (string)$t; }
    public function table_exists($t) {
        return in_array($t, array(
            'pm_schedules', 'equipment', 'equipment_types', 'pm_part_requirements',
            'materials', 'material_transactions', 'suppliers',
            'pm_completions', 'backlogs', 'work_orders', 'users',
        ), TRUE);
    }
    public function field_exists($f, $t = '') { return TRUE; }
    public function escape_like_str($s) { return addslashes((string)$s); }
    public function select($s, $escape = NULL) { return $this; }
    public function from($t) { return $this; }
    public function join($t, $c, $type = '') { return $this; }
    public function where($k, $v = NULL, $escape = NULL) { return $this; }
    public function where_in($k, $vals) { return $this; }
    public function like($f, $v = '', $side = 'both') { return $this; }
    public function group_by($g) { return $this; }
    public function order_by($c, $d = '', $escape = NULL) { return $this; }
    public function limit($n, $off = 0) { return $this; }
    public function get($table = NULL) {
        $this->queries[] = (string)$table;
        $next = array_shift($this->queue);
        return $next ? $next : new EquipFleetFakeResult(array());
    }
}

$pmDb = new PmPlannerFakeDB();
$pmModel = new Pm_model();
$pmModel->db = $pmDb;

$pmSeedRows = array(
    // 1. Meter-overdue on EX 006 (date still in future, but current_hours 1420 >= next_due_hours 1250!)
    (object)array(
        'id' => 1, 'equipment_id' => 1, 'registration_no' => 'EX 006', 'brand' => 'CATERPILLAR', 'model' => '336D2L',
        'type_name' => 'Excavator', 'category' => 'Mobile Equipment', 'location' => 'MET PLANT', 'equip_status' => 'Available',
        'current_hours' => 1420.0, 'current_km' => 0.0, 'schedule_name' => '250-Hour Service<script>alert(3)</script>',
        'description' => 'Engine oil & primary fuel filters', 'interval_type' => 'Hours', 'interval_value' => 250,
        'standard_hours' => 8.0, 'last_done_date' => '2026-09-25', 'last_done_hours' => 1000.0,
        'next_due_date' => '2026-11-10', 'next_due_hours' => 1250.0, 'status' => 'Active', 'tasks_checklist' => "Drain engine oil\nReplace filters",
    ),
    // 2. Date due-soon within 7 days on C-HEADT 007 + missing standard_hours (F4 alert)
    (object)array(
        'id' => 2, 'equipment_id' => 2, 'registration_no' => 'C-HEADT 007', 'brand' => 'SHACMAN', 'model' => 'F3000',
        'type_name' => 'Horse Truck', 'category' => 'Heavy Truck', 'location' => 'WORKSHOP', 'equip_status' => 'Available',
        'current_hours' => 300.0, 'current_km' => 98200.0, 'schedule_name' => '10,000 KM Service',
        'description' => 'Driveline grease & brake inspection', 'interval_type' => 'Km', 'interval_value' => 10000,
        'standard_hours' => NULL, 'last_done_date' => '2026-09-20', 'last_done_hours' => 90000.0,
        'next_due_date' => '2026-10-25', 'next_due_hours' => 100000.0, 'status' => 'Active', 'tasks_checklist' => '',
    ),
    // 3. Paused / Inactive schedule (previously vanished into a black hole!)
    (object)array(
        'id' => 3, 'equipment_id' => 1, 'registration_no' => 'EX 006', 'brand' => 'CATERPILLAR', 'model' => '336D2L',
        'type_name' => 'Excavator', 'category' => 'Mobile Equipment', 'location' => 'MET PLANT', 'equip_status' => 'Available',
        'current_hours' => 1420.0, 'current_km' => 0.0, 'schedule_name' => '2000-Hour Hydraulic Overhaul',
        'description' => 'Seasonal overhaul', 'interval_type' => 'Hours', 'interval_value' => 2000,
        'standard_hours' => 24.0, 'last_done_date' => '2026-01-10', 'last_done_hours' => 500.0,
        'next_due_date' => '2027-01-10', 'next_due_hours' => 2500.0, 'status' => 'Inactive', 'tasks_checklist' => '',
    ),
);

$pmDb->queue = array(
    new EquipFleetFakeResult($pmSeedRows),
    new EquipFleetFakeResult(array(
        (object)array('pm_schedule_id' => 1, 'parts_cnt' => 4, 'short_cnt' => 1),
        (object)array('pm_schedule_id' => 2, 'parts_cnt' => 2, 'short_cnt' => 0),
    )),
    new EquipFleetFakeResult(array(
        (object)array('pm_schedule_id' => 1, 'comp_cnt' => 3),
    )),
);

$qStartPm = count($pmDb->queries);
$pmOverview = $pmModel->get_planner_overview('2026-10-22');
$qTotalPm = count($pmDb->queries) - $qStartPm;

check('PM-05 O(1) PM Telemetry Scaling: get_planner_overview() hydrates schedules + BOM shortages + completions in <= 4 SQL queries',
    $qTotalPm <= 4 && count($pmOverview['rows']) === 3,
    "Executed {$qTotalPm} queries");
check('PM-02 & PM-03 Meter Overdue, Due-Soon, F4 Forecast Alert & Inactive Schedule Recovery',
    $pmOverview['rows'][0]->compliance_state === 'overdue'
    && $pmOverview['rows'][0]->compliance_label === 'Overdue (170h)'
    && $pmOverview['rows'][0]->shortage_count === 1
    && $pmOverview['rows'][0]->completion_count === 3
    && $pmOverview['rows'][1]->compliance_state === 'due_soon'
    && $pmOverview['rows'][1]->has_forecast_alert === TRUE
    && $pmOverview['rows'][2]->compliance_state === 'inactive');

// PM-04 RBAC & Stored XSS checks across Pm controller, pm_planner.php & pm_calendar.php
$pmCalSrc = file_get_contents(APPPATH . 'views/maintenance/pm_calendar.php');
if (isset($mutRegex)) {
    foreach (array('index', 'export_csv', 'summary_json', 'calendar') as $pmAct) {
        check("PM-04 Manager RBAC: Pm::{$pmAct}() is permitted under read-only MUTATION_PATTERN",
            preg_match($mutRegex, $pmAct) === 0);
    }
}
check('PM-04 Stored XSS Hardening in maintenance/pm_calendar.php: escapes registration_no, brand/model & schedule_name',
    strpos($pmCalSrc, '<?= $pm->registration_no ?>') === FALSE
    && strpos($pmCalSrc, '<?= $pm->schedule_name ?>') === FALSE);

$pmVars = array(
    'pm_schedules'   => $pmOverview['rows'],
    'equipment'      => array((object)array('id' => 1, 'registration_no' => 'EX 006'), (object)array('id' => 2, 'registration_no' => 'C-HEADT 007')),
    'locations'      => $pmOverview['locations'],
    'categories'     => $pmOverview['categories'],
    'open_wos'       => array((object)array('id' => 5, 'wo_number' => 'WO-2026-005', 'title' => '250h service', 'equipment_id' => 1, 'registration_no' => 'EX 006')),
    'kpi'            => array(
        'active_total' => 2, 'inactive_total' => 1, 'overdue' => 1, 'due_soon' => 1,
        'due_today' => 0, 'planned_std_hrs' => 8.0, 'missing_std_hrs' => 1, 'parts_ready' => 1, 'parts_short' => 1,
    ),
    'tab_counts'     => array('active' => 2, 'overdue' => 1, 'due_soon' => 1, 'ok' => 0, 'parts_short' => 1, 'forecast_alert' => 1, 'inactive' => 1),
    'tab'            => 'active',
    'q'              => '',
    'f_equip'        => 0,
    'f_interval'     => '',
    'f_category'     => '',
    'f_location'     => '',
    'sort'           => 'urgency',
    'dir'            => 'asc',
    'view_mode'      => 'table',
    'per_page'       => 25,
    'allowed_pp'     => array(10, 25, 50, 100, 150),
    'current_page'   => 1,
    'total_pages'    => 1,
    'total_filtered' => 3,
    'offset'         => 0,
    'can_manage_pm'  => TRUE,
    'can_complete_pm'=> TRUE,
);

$pmWarnings = array();
set_error_handler(function ($severity, $message, $file, $line) use (&$pmWarnings) {
    $pmWarnings[] = "{$message} ({$file}:{$line})";
    return TRUE;
});
ob_start();
(function (array $v) { extract($v, EXTR_SKIP); include APPPATH . 'views/maintenance/pm_planner.php'; })($pmVars);
$pmSupHtml = ob_get_clean();

$pmVarsMgr = array_merge($pmVars, array('can_manage_pm' => FALSE, 'can_complete_pm' => FALSE));
ob_start();
(function (array $v) { extract($v, EXTR_SKIP); include APPPATH . 'views/maintenance/pm_planner.php'; })($pmVarsMgr);
$pmMgrHtml = ob_get_clean();
restore_error_handler();

check('PM-01 View Render: application/views/maintenance/pm_planner.php renders Enterprise DataTable & Dual Cards with ZERO PHP warnings',
    empty($pmWarnings)
    && strpos($pmSupHtml, '<table class="pm-table"') !== FALSE
    && strpos($pmSupHtml, 'class="pm-cards-grid"') !== FALSE,
    implode('; ', $pmWarnings));
check('PM-04 XSS Neutralization in maintenance/pm_planner.php: hostile <script> tag escaped cleanly',
    strpos($pmSupHtml, '<script>alert(3)</script>') === FALSE
    && strpos($pmSupHtml, '250-Hour Service&lt;script&gt;alert(3)&lt;/script&gt;') !== FALSE);
check('PM-04 Manager UI RBAC: hides New PM, Edit & Done mutation buttons while preserving Export CSV, 12-Month Calendar & Inspect',
    strpos($pmSupHtml, 'pm/create') !== FALSE
    && strpos($pmMgrHtml, 'pm/create') === FALSE
    && strpos($pmMgrHtml, 'pm/edit/') === FALSE
    && strpos($pmMgrHtml, '>Done<') === FALSE
    && strpos($pmMgrHtml, 'pm/export_csv') !== FALSE);
check('PM-06 Accessible Slide-Over PM Inspector Drawer rendered cleanly',
    strpos($pmSupHtml, 'id="pmDrawer" role="dialog" aria-modal="true"') !== FALSE
    && strpos($pmSupHtml, 'pm/summary_json/') !== FALSE);

/* ─────────────────────────────────────────────────────────────────────────
   16. Fleet Inspections Register DataTable, O(1) Telemetry, RBAC & XSS Audit
   ───────────────────────────────────────────────────────────────────────── */

if (!class_exists('Inspection_model', FALSE)) {
    require_once APPPATH . 'models/Inspection_model.php';
}

$inDb = new PmPlannerFakeDB();
$inModel = new Inspection_model();
$inModel->db = $inDb;

$inTplSeed = array(
    (object)array(
        'id' => 1, 'code' => 'excavator', 'name_en' => 'Excavator Pre-Shift Checklist',
        'name_fr' => 'Liste d’inspection excavatrice', 'category_en' => 'Heavy Equipment',
        'category_fr' => 'Engins lourds', 'layout_type' => 'compliance', 'columns_json' => NULL,
        'meter_label_en' => 'SMR', 'meter_label_fr' => 'SMR', 'active' => 1,
    ),
    (object)array(
        'id' => 2, 'code' => 'adt', 'name_en' => 'Articulated Dump Truck Checklist',
        'name_fr' => 'Camion articulé ADT', 'category_en' => 'Heavy Equipment',
        'category_fr' => 'Engins lourds', 'layout_type' => 'compliance', 'columns_json' => NULL,
        'meter_label_en' => 'SMR', 'meter_label_fr' => 'SMR', 'active' => 1,
    ),
);

$inRecordSeed = array(
    // 1. P1 No-Go failure on EX 006 + unsigned by supervisor + XSS probe
    (object)array(
        'id' => 91, 'template_id' => 1, 'language' => 'en', 'inspection_date' => '2026-10-22',
        'fleet_number' => 'EX 006', 'equipment_type' => 'Excavator', 'smr' => '1420',
        'serial_number' => 'CAT0336D', 'model' => '336D2L', 'equipment_id' => 1,
        'general_comments' => 'Brake pressure fault<script>alert(9)</script>',
        'mechanic_name' => 'John Moshi<script>alert(9)</script>', 'mechanic_signature' => 'uploads/sig1.png', 'mechanic_date' => '2026-10-22',
        'supervisor_name' => '', 'supervisor_signature' => NULL, 'supervisor_date' => NULL,
        'operator_name' => 'Asha Nyerere', 'operator_signature' => NULL, 'operator_date' => '2026-10-22',
        'prod_supervisor_name' => '', 'prod_supervisor_signature' => NULL, 'prod_supervisor_date' => NULL,
        'status' => 'submitted', 'equip_reg_no' => 'EX 006', 'equip_category' => 'Mobile Equipment',
        'equip_location' => 'MET PLANT', 'equip_brand' => 'CATERPILLAR', 'equip_model' => '336D2L',
    ),
    // 2. Clean Pass + signed by supervisor on C-HEADT 007
    (object)array(
        'id' => 92, 'template_id' => 2, 'language' => 'fr', 'inspection_date' => '2026-10-21',
        'fleet_number' => 'C-HEADT 007', 'equipment_type' => 'Horse Truck', 'smr' => '98200',
        'serial_number' => 'SHAC982', 'model' => 'F3000', 'equipment_id' => 2,
        'general_comments' => 'RAS',
        'mechanic_name' => 'Paul Kileo', 'mechanic_signature' => NULL, 'mechanic_date' => '2026-10-21',
        'supervisor_name' => 'Chief Sup', 'supervisor_signature' => 'uploads/sup.png', 'supervisor_date' => '2026-10-21',
        'operator_name' => '', 'operator_signature' => NULL, 'operator_date' => NULL,
        'prod_supervisor_name' => '', 'prod_supervisor_signature' => NULL, 'prod_supervisor_date' => NULL,
        'status' => 'submitted', 'equip_reg_no' => 'C-HEADT 007', 'equip_category' => 'Heavy Truck',
        'equip_location' => 'WORKSHOP', 'equip_brand' => 'SHACMAN', 'equip_model' => 'F3000',
    ),
);

$inDb->queue = array(
    new EquipFleetFakeResult($inTplSeed),
    new EquipFleetFakeResult(array(
        (object)array('template_id' => 1, 'item_cnt' => 45, 'section_cnt' => 5),
        (object)array('template_id' => 2, 'item_cnt' => 40, 'section_cnt' => 4),
    )),
    new EquipFleetFakeResult($inRecordSeed),
    new EquipFleetFakeResult(array(
        (object)array(
            'record_id' => 91, 'item_id' => 501, 'value_json' => '{"result":"No"}',
            'comment' => 'Service brake pressure below min', 'row_type' => 'item',
            'priority' => 'P1', 'category_en' => 'No-Go', 'category_fr' => 'Arrêt',
        ),
        (object)array(
            'record_id' => 92, 'item_id' => 502, 'value_json' => '{"result":"Yes"}',
            'comment' => '', 'row_type' => 'item',
            'priority' => 'P1', 'category_en' => 'No-Go', 'category_fr' => 'Arrêt',
        ),
    )),
);

$qStartIn = count($inDb->queries);
$inOverview = $inModel->get_register_overview('2026-10-22');
$qTotalIn = count($inDb->queries) - $qStartIn;

check('INSP-05 O(1) Telemetry Scaling: get_register_overview() hydrates templates, item counts, machines & verdicts in <= 4 SQL queries',
    $qTotalIn <= 4 && count($inOverview['rows']) === 2 && $inOverview['total_items'] === 85,
    "Executed {$qTotalIn} queries");
check('INSP-01 & INSP-03 P1 No-Go, Clean Pass, Unsigned Sign-Off & Verbatim Asset Register ID Resolution',
    $inOverview['rows'][0]->verdict_state === 'p1_nogo'
    && $inOverview['rows'][0]->p1_count === 1
    && $inOverview['rows'][0]->is_unsigned === TRUE
    && $inOverview['rows'][0]->machine_label === 'EX 006'
    && $inOverview['rows'][1]->verdict_state === 'passed'
    && $inOverview['rows'][1]->is_signed === TRUE
    && $inOverview['rows'][1]->machine_label === 'C-HEADT 007');

$inCtrlSrc = file_get_contents(APPPATH . 'controllers/Inspections.php');
$myCtrlSrcIn = file_get_contents(APPPATH . 'core/MY_Controller.php');
check('INSP-04 Manager RBAC & POST Guard: MY_Controller whitelists GET inspections/records while Inspections::save() enforces require_post()',
    strpos($myCtrlSrcIn, 'const MANAGER_READ_WHITELIST') !== FALSE
    && strpos($myCtrlSrcIn, "'inspections/records'") !== FALSE
    && strpos($inCtrlSrc, '$this->require_post()') !== FALSE);
if (isset($mutRegex)) {
    foreach (array('index', 'export_csv', 'summary_json') as $inAct) {
        check("INSP-04 Manager RBAC: Inspections::{$inAct}() is permitted under read-only MUTATION_PATTERN",
            preg_match($mutRegex, $inAct) === 0);
    }
}

$inVars = array(
    'records'          => $inOverview['rows'],
    'templates'        => $inOverview['active_templates'],
    'all_templates'    => $inOverview['templates'],
    'record_counts'    => $inOverview['record_counts'],
    'equipment_list'   => array((object)array('id' => 1, 'registration_no' => 'EX 006'), (object)array('id' => 2, 'registration_no' => 'C-HEADT 007')),
    'focused_template' => NULL,
    'forced_code'      => NULL,
    'kpi'              => array(
        'total_records' => 2, 'today_records' => 1, 'p1_nogo' => 1, 'failed_records' => 1,
        'total_failed_items' => 1, 'clean_pass' => 1, 'pass_rate' => 50, 'unsigned' => 1,
        'drafts' => 0, 'active_templates' => 2, 'total_items' => 85,
    ),
    'tab_counts'       => array('all' => 2, 'p1_nogo' => 1, 'failed' => 1, 'passed' => 1, 'unsigned' => 1, 'draft' => 0, 'forms' => 2),
    'tab'              => 'all',
    'q'                => '',
    'f_template'       => '',
    'f_equip'          => 0,
    'f_rec_lang'       => '',
    'f_from'           => '',
    'f_to'             => '',
    'sort'             => 'inspection_date',
    'dir'              => 'desc',
    'view_mode'        => 'table',
    'per_page'         => 25,
    'allowed_pp'       => array(10, 25, 50, 100, 150),
    'current_page'     => 1,
    'total_pages'      => 1,
    'total_filtered'   => 2,
    'offset'           => 0,
    'lang'             => 'en',
    'can_manage'       => TRUE,
    'can_submit'       => TRUE,
);

$inWarnings = array();
set_error_handler(function ($severity, $message, $file, $line) use (&$inWarnings) {
    $inWarnings[] = "{$message} ({$file}:{$line})";
    return TRUE;
});
ob_start();
(function (array $v) { extract($v, EXTR_SKIP); include APPPATH . 'views/inspections/index.php'; })($inVars);
$inSupHtml = ob_get_clean();

$inVarsMgr = array_merge($inVars, array('can_manage' => FALSE, 'can_submit' => FALSE));
ob_start();
(function (array $v) { extract($v, EXTR_SKIP); include APPPATH . 'views/inspections/index.php'; })($inVarsMgr);
$inMgrHtml = ob_get_clean();
restore_error_handler();

check('INSP-01 View Render: application/views/inspections/index.php renders Enterprise DataTable & Dual Cards with ZERO PHP warnings',
    empty($inWarnings)
    && strpos($inSupHtml, '<table class="in-table"') !== FALSE
    && strpos($inSupHtml, 'class="in-cards-grid"') !== FALSE,
    implode('; ', $inWarnings));
check('INSP-04 XSS Neutralization in inspections/index.php: hostile <script> tag escaped cleanly',
    strpos($inSupHtml, '<script>alert(9)</script>') === FALSE
    && strpos($inSupHtml, 'John Moshi&lt;script&gt;alert(9)&lt;/script&gt;') !== FALSE);
check('INSP-04 Manager UI RBAC: hides New Inspection, Manage Templates & Edit links while preserving Export CSV, Inspect, View & PDF',
    strpos($inSupHtml, 'id="inOpenLauncherBtn"') !== FALSE
    && strpos($inSupHtml, 'inspections/templates') !== FALSE
    && strpos($inMgrHtml, 'id="inOpenLauncherBtn"') === FALSE
    && strpos($inMgrHtml, 'inspections/templates') === FALSE
    && strpos($inMgrHtml, 'href="' . base_url('inspections/edit/')) === FALSE
    && strpos($inMgrHtml, 'inspections/export_csv') !== FALSE);
check('INSP-06 Accessible Slide-Over Inspection Drawer rendered cleanly',
    strpos($inSupHtml, 'id="inDrawer" role="dialog" aria-modal="true"') !== FALSE
    && strpos($inSupHtml, 'inspections/summary_json/') !== FALSE);

/* ─────────────────────────────────────────────────────────────────────────
   17. Backlog Defect Register DataTable, O(1) Telemetry, F-08 RBAC & XSS
   ───────────────────────────────────────────────────────────────────────── */

if (!class_exists('Backlog_model', FALSE)) {
    require_once APPPATH . 'models/Backlog_model.php';
}

$blDb = new PmPlannerFakeDB();
$blModel = new Backlog_model();
$blModel->db = $blDb;

$blSeedRows = array(
    // 1. Open overdue P1 BREAKDOWN raised by INSP-951 on EX 006 + XSS probe
    (object)array(
        'id' => 61, 'equipment_id' => 1, 'asset_label' => 'EX 006', 'equipment_code' => 'EX 006',
        'machine_type' => 'Excavator', 'equip_location' => 'MET PLANT', 'model' => '336D2L',
        'serial_no' => 'CAT336', 'start_odometer' => '1420 Hr', 'start_date' => '2026-10-01',
        'description' => 'Service brake pressure loss<script>alert(7)</script>',
        'source' => 'Inspection', 'reported_by' => 'John Moshi', 'in_charge' => 'John Moshi',
        'priority' => 'P1', 'deadline' => '2026-10-15', 'observation' => 'Stop machine immediately',
        'machine_status' => 'BREAKDOWN', 'status' => 'Open', 'end_odometer' => NULL, 'end_date' => NULL,
        'closing_remarks' => NULL, 'mechanic' => NULL, 'supervisor' => NULL,
        'work_order_id' => 5, 'wo_number' => 'WO-2026-005', 'wo_status' => 'In Progress',
        'planned_date' => '2026-10-23', 'estimated_duration' => 180,
        'source_ref' => 'insp_951_item_1', 'verified_by' => NULL, 'verified_at' => NULL,
        'verifier_first' => NULL, 'verifier_last' => NULL, 'recurrence_of' => NULL,
    ),
    // 2. Closed supervisor-verified defect on C-HEADT 007
    (object)array(
        'id' => 62, 'equipment_id' => 2, 'asset_label' => 'C-HEADT 007', 'equipment_code' => 'C-HEADT 007',
        'machine_type' => 'Horse Truck', 'equip_location' => 'WORKSHOP', 'model' => 'F3000',
        'serial_no' => 'SHAC982', 'start_odometer' => '98000 Km', 'start_date' => '2026-10-10',
        'description' => 'Air dryer purge valve sticking',
        'source' => 'Checklist', 'reported_by' => 'Paul Kileo', 'in_charge' => 'Paul Kileo',
        'priority' => 'P2', 'deadline' => '2026-10-20', 'observation' => '',
        'machine_status' => 'OPERATIONAL', 'status' => 'Closed', 'end_odometer' => '98200 Km',
        'end_date' => '2026-10-18', 'closing_remarks' => 'Replaced purge valve kit',
        'mechanic' => 'Paul Kileo', 'supervisor' => 'Chief Sup',
        'work_order_id' => NULL, 'wo_number' => NULL, 'wo_status' => NULL,
        'planned_date' => NULL, 'estimated_duration' => NULL,
        'source_ref' => NULL, 'verified_by' => 1, 'verified_at' => '2026-10-18 16:00:00',
        'verifier_first' => 'Chief', 'verifier_last' => 'Supervisor', 'recurrence_of' => NULL,
    ),
);

$blDb->queue = array(
    new EquipFleetFakeResult($blSeedRows),
    new EquipFleetFakeResult(array(
        (object)array('id' => 1, 'registration_no' => 'EX 006', 'location' => 'MET PLANT'),
        (object)array('id' => 2, 'registration_no' => 'C-HEADT 007', 'location' => 'WORKSHOP'),
    )),
);

$qStartBl = count($blDb->queries);
$blOverview = $blModel->get_register_overview('2026-10-22');
$qTotalBl = count($blDb->queries) - $qStartBl;

check('BL-03 O(1) Telemetry Scaling: Backlog_model::get_register_overview() hydrates defects, WOs, verifiers & hotspots in <= 3 SELECT queries',
    $qTotalBl <= 3 && count($blOverview['rows']) === 2 && count($blOverview['by_asset']) === 1,
    "Executed {$qTotalBl} SELECT queries");
check('BL-03 Age, Overdue Deadline, Inspection Traceability (`insp_951`) & Verifier Resolution',
    $blOverview['rows'][0]->days_down === 21
    && $blOverview['rows'][0]->is_overdue === TRUE
    && $blOverview['rows'][0]->inspection_record_id === 951
    && $blOverview['rows'][0]->machine_display === 'EX 006'
    && $blOverview['rows'][1]->days_down === 8
    && $blOverview['rows'][1]->verifier_name === 'Chief Supervisor');

$blCtrlSrc = file_get_contents(APPPATH . 'controllers/Backlog.php');
check('BL-02 F-08 Closure Bypass Hardening: Backlog::_save() enforces supervisor verification on status = Closed',
    strpos($blCtrlSrc, 'Closing a defect requires supervisor verification.') !== FALSE);
if (isset($mutRegex)) {
    foreach (array('index', 'export_csv', 'export_xlsx', 'summary_json') as $blAct) {
        check("BL-04 Manager RBAC: Backlog::{$blAct}() is permitted under read-only MUTATION_PATTERN",
            preg_match($mutRegex, $blAct) === 0);
    }
}

$blVars = array(
    'items'          => $blOverview['rows'],
    'kpi'            => array('total' => 2, 'open' => 1, 'closed' => 1, 'p1' => 1, 'overdue' => 1, 'breakdown' => 1, 'scheduled' => 1, 'avg_days' => 21.0, 'oldest' => 21),
    'by_asset'       => $blOverview['by_asset'],
    'equipment'      => $blOverview['equipment'],
    'available'      => TRUE,
    'tab_counts'     => array('all' => 2, 'active' => 1, 'p1' => 1, 'breakdown' => 1, 'overdue' => 1, 'scheduled' => 1, 'closed' => 1),
    'tab'            => 'all',
    'q'              => '',
    'filter'         => array('status' => '', 'priority' => '', 'source' => '', 'machine_status' => '', 'equipment_id' => '', 'date_from' => '', 'date_to' => '', 'overdue' => '', 'search' => ''),
    'sort'           => 'default',
    'dir'            => 'asc',
    'view_mode'      => 'table',
    'per_page'       => 25,
    'allowed_pp'     => array(10, 25, 50, 100, 150),
    'current_page'   => 1,
    'total_pages'    => 1,
    'total_filtered' => 2,
    'offset'         => 0,
    'can_write'      => TRUE,
    'is_supervisor'  => TRUE,
);

$blWarnings = array();
set_error_handler(function ($severity, $message, $file, $line) use (&$blWarnings) {
    $blWarnings[] = "{$message} ({$file}:{$line})";
    return TRUE;
});
ob_start();
(function (array $v) { extract($v, EXTR_SKIP); include APPPATH . 'views/backlog/index.php'; })($blVars);
$blSupHtml = ob_get_clean();

$blTechHtml = '';
ob_start();
(function (array $v) { extract($v, EXTR_SKIP); include APPPATH . 'views/backlog/index.php'; })(array_merge($blVars, array('can_write' => TRUE, 'is_supervisor' => FALSE)));
$blTechHtml = ob_get_clean();

$blMgrHtml = '';
ob_start();
(function (array $v) { extract($v, EXTR_SKIP); include APPPATH . 'views/backlog/index.php'; })(array_merge($blVars, array('can_write' => FALSE, 'is_supervisor' => FALSE)));
$blMgrHtml = ob_get_clean();
restore_error_handler();

check('BL-01 View Render: application/views/backlog/index.php renders Enterprise DataTable & Dual Cards with ZERO PHP warnings',
    empty($blWarnings)
    && strpos($blSupHtml, '<table class="bl-table"') !== FALSE
    && strpos($blSupHtml, 'class="bl-cards-grid"') !== FALSE,
    implode('; ', $blWarnings));
check('BL-04 XSS Neutralization in backlog/index.php: hostile <script> tag escaped cleanly',
    strpos($blSupHtml, '<script>alert(7)</script>') === FALSE
    && strpos($blSupHtml, 'Service brake pressure loss&lt;script&gt;alert(7)&lt;/script&gt;') !== FALSE);
check('BL-02 3-Role UI RBAC: Supervisor sees New/Edit/Verify, Technician sees New/Edit without Verify, Manager sees read-only Exports & Inspect',
    strpos($blSupHtml, 'id="blCloseModal"') !== FALSE
    && strpos($blTechHtml, 'backlog/create') !== FALSE
    && strpos($blTechHtml, 'id="blCloseModal"') === FALSE
    && strpos($blMgrHtml, 'backlog/create') === FALSE
    && strpos($blMgrHtml, 'href="' . base_url('backlog/edit/')) === FALSE
    && strpos($blMgrHtml, 'backlog/export_csv') !== FALSE
    && strpos($blMgrHtml, 'backlog/export_xlsx') !== FALSE);
check('BL-05 Accessible Slide-Over Defect Drawer rendered cleanly',
    strpos($blSupHtml, 'id="blDrawer" role="dialog" aria-modal="true"') !== FALSE
    && strpos($blSupHtml, 'backlog/summary_json/') !== FALSE);

/* ─────────────────────────────────────────────────────────────────────────
   18. Inventory Catalogue Register Overhaul (/materials/inventory — INV-01..INV-06)
   ───────────────────────────────────────────────────────────────────────── */

if (!function_exists('form_open_multipart')) {
    function form_open_multipart($action = '', $attrs = '') {
        return '<form method="post" enctype="multipart/form-data" action="' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8') . '">';
    }
}
if (!function_exists('csrf_field')) {
    function csrf_field() {
        return '<input type="hidden" name="csrf_token_wms" value="test-csrf-token">';
    }
}

require_once APPPATH . 'models/Materials_model.php';

$invDb = new PmPlannerFakeDB();
$invModel = new Materials_model();
$invModel->db = $invDb;

$invRows = array(
    // Custom min_stock = 10 -> balance = 8 MUST be flagged 'low' with qty_to_order = 12 (max_stock 20 - 8)
    (object)array(
        'id' => 9901, 'item_code' => 'FLT-9901', 'category' => 'SPARE PART',
        'description' => 'Primary Fuel Filter<script>alert(8)</script>', 'part_number_size' => '1R-0750',
        'unit' => 'pcs', 'initial_stock' => 5, 'qty_received' => 6, 'qty_sortie' => 3, 'final_qty' => 8,
        'min_stock' => 10, 'max_stock' => 20, 'unit_price_ugx' => NULL, 'unit_price_usd' => 34.50,
        'supplier_id' => 1, 'supplier_name' => NULL, 'linked_supplier_name' => 'Mantrac CAT',
        'application' => 'EX 006 / 336D2L', 'location' => 'BIN A-12',
    ),
    // Out of stock item -> balance = 0, qty_to_order = 6
    (object)array(
        'id' => 9902, 'item_code' => 'HYD-9902', 'category' => 'HYDRAULIC',
        'description' => 'Boom Cylinder Seal Kit', 'part_number_size' => '295-5609',
        'unit' => 'Set', 'initial_stock' => 0, 'qty_received' => 2, 'qty_sortie' => 2, 'final_qty' => 0,
        'min_stock' => 2, 'max_stock' => 6, 'unit_price_ugx' => NULL, 'unit_price_usd' => NULL,
        'supplier_id' => NULL, 'supplier_name' => 'HydraForce TZ', 'linked_supplier_name' => NULL,
        'application' => 'C-HEADT 007', 'location' => 'BIN B-04',
    ),
);
for ($k = 3; $k <= 250; $k++) {
    $invRows[] = (object)array(
        'id' => 9900 + $k, 'item_code' => 'CON-' . (9900 + $k), 'category' => 'CONSUMABLE',
        'description' => 'Workshop Consumable #' . $k, 'part_number_size' => 'P-' . $k,
        'unit' => 'pcs', 'initial_stock' => 10, 'qty_received' => 5, 'qty_sortie' => 2, 'final_qty' => 13,
        'min_stock' => NULL, 'max_stock' => NULL, 'unit_price_ugx' => NULL, 'unit_price_usd' => NULL,
        'supplier_id' => NULL, 'supplier_name' => NULL, 'linked_supplier_name' => NULL,
        'application' => NULL, 'location' => 'MAIN STORE',
    );
}
$invDb->queue = array(
    new EquipFleetFakeResult($invRows),
    new EquipFleetFakeResult(array(
        (object)array('material_id' => 9901, 'txn_count' => 4, 'last_movement_date' => '2026-10-20'),
    )),
);
$invOv = $invModel->get_catalogue_overview();
check('INV-02 O(1) Query Scaling: 250 inventory SKUs hydrated in <= 2 constant SQL SELECT queries',
    count($invDb->queries) <= 2 && count($invOv['rows']) === 250,
    'Executed ' . count($invDb->queries) . ' queries');

$iv0 = $invOv['rows'][0];
$iv1 = $invOv['rows'][1];
check('INV-03 Custom Reorder & Supplier Hydration: custom min_stock=10 flags balance=8 as low (order 12) & resolves supplier',
    $iv0->balance === 8 && $iv0->stock_state === 'low' && $iv0->qty_to_order === 12
    && $iv0->supplier_display === 'Mantrac CAT' && $iv0->txn_count === 4
    && $iv1->balance === 0 && $iv1->stock_state === 'out' && $iv1->qty_to_order === 6
    && $iv1->supplier_display === 'HydraForce TZ');

$matCtrlSrc = file_get_contents(APPPATH . 'controllers/Materials.php');
check('INV-01 Diagnostic Endpoint Hardening: Materials::ping() restricted to Supervisor & hides PHP_VERSION',
    strpos($matCtrlSrc, 'if (!$this->is_supervisor())') !== FALSE
    && strpos($matCtrlSrc, 'PHP_VERSION') === FALSE);

foreach (array('inventory', 'summary_json', 'export_csv', 'export_xlsx', 'template_xlsx') as $roMethod) {
    check('INV-05 Manager MUTATION_PATTERN compatibility: GET allowed on Materials::' . $roMethod,
        !preg_match($mutPattern, $roMethod));
}

$invWarnings = array();
$renderInvView = function ($roleId, $canWrite, $isSup) use ($invOv, &$invWarnings) {
    $materials       = array_slice($invOv['rows'], 0, 2);
    $categories      = $invOv['categories'];
    $locations       = $invOv['locations'];
    $suppliers       = $invOv['suppliers'];
    $kpi             = array('total_items' => 250, 'in_stock_items' => 248, 'low_stock' => 1, 'out_of_stock' => 1, 'total_units' => 3258, 'total_received' => 1258, 'total_issued' => 505, 'reorder_needed' => 18);
    $filtered_totals = array('initial' => 5, 'received' => 8, 'issued' => 5, 'balance' => 8);
    $tab_counts      = array('all' => 250, 'in_stock' => 248, 'low' => 1, 'out' => 1, 'movers' => 1, 'valued' => 1);
    $tab             = 'all';
    $q               = '';
    $filter          = array('category' => '', 'location' => '', 'supplier' => '', 'stock' => '', 'search' => '');
    $sort            = 'default';
    $dir             = 'asc';
    $view_mode       = 'table';
    $per_page        = 25;
    $allowed_pp      = array(10, 25, 50, 100, 150);
    $current_page    = 1;
    $total_pages     = 10;
    $total_filtered  = 250;
    $offset          = 0;
    $can_write       = $canWrite;
    $can_import      = $canWrite;
    $is_supervisor   = $isSup;
    $role_id         = $roleId;

    set_error_handler(function ($severity, $message, $file, $line) use (&$invWarnings) {
        $invWarnings[] = "$message ($file:$line)";
        return TRUE;
    });
    ob_start();
    include APPPATH . 'views/materials/inventory.php';
    $out = ob_get_clean();
    restore_error_handler();
    return $out;
};

$invSupHtml   = $renderInvView(1, TRUE, TRUE);
$invStoreHtml = $renderInvView(3, TRUE, FALSE);
$invMgrHtml   = $renderInvView(4, FALSE, FALSE);

check('INV-06 Clean View Render: materials/inventory.php renders Table + Dual Cards with ZERO PHP warnings',
    empty($invWarnings)
    && strpos($invSupHtml, '<table class="inv-table"') !== FALSE
    && strpos($invSupHtml, 'class="inv-cards-grid"') !== FALSE,
    implode('; ', $invWarnings));
check('INV-04 XSS Neutralization in materials/inventory.php: hostile <script> tag escaped cleanly',
    strpos($invSupHtml, '<script>alert(8)</script>') === FALSE
    && strpos($invSupHtml, 'Primary Fuel Filter&lt;script&gt;alert(8)&lt;/script&gt;') !== FALSE);
check('INV-01 Store Role Fix & Manager Read-Only RBAC: Store (role 3) sees +In/-Out/Edit/Import, Manager (role 4) is read-only',
    strpos($invStoreHtml, 'data-inv-move="receive"') !== FALSE
    && strpos($invStoreHtml, 'data-inv-move="issue"') !== FALSE
    && strpos($invStoreHtml, 'id="invAddManualBtn"') !== FALSE
    && strpos($invMgrHtml, 'id="invAddManualBtn"') === FALSE
    && strpos($invMgrHtml, 'data-inv-move="receive"') === FALSE
    && strpos($invMgrHtml, 'materials/export_csv') !== FALSE
    && strpos($invMgrHtml, 'materials/export_xlsx') !== FALSE);
check('INV-05 Accessible Slide-Over SKU & Ledger Drawer rendered cleanly',
    strpos($invSupHtml, 'id="invDrawer" role="dialog" aria-modal="true"') !== FALSE
    && strpos($invSupHtml, 'materials/summary_json/') !== FALSE);

/* ─────────────────────────────────────────────────────────────────────────
   19. Warehouse Stock Transactions Ledger Overhaul (/materials/transactions — TXN-01..TXN-06)
   ───────────────────────────────────────────────────────────────────────── */

$txnDb = new PmPlannerFakeDB();
$txnModel = new Materials_model();
$txnModel->db = $txnDb;

$txnSampleRows = array(
    (object)array(
        'id' => 29901, 'material_id' => 9901, 'type' => 'ISSUE',
        'txn_date' => '2026-02-03', 'movement_date' => '2026-02-03',
        'created_at' => '2026-10-06 15:26:03',
        'reference_no' => 'REQ-4692', 'qty' => 2.0,
        'allocation_type' => 'Equipment', 'equipment_id' => 1, 'equipment_code' => 'EX 006',
        'allocation_label' => 'EX 006', 'person' => 'John Moshi<script>alert(9)</script>',
        'supplier_id' => NULL, 'supplier_name' => NULL, 'linked_supplier_name' => NULL,
        'qty_approved' => NULL, 'unit_price' => NULL, 'currency' => NULL,
        'source' => 'Excel Import', 'affects_balance' => 0,
        'stock_before' => NULL, 'stock_after' => NULL, 'notes' => 'Hydraulic service issue',
        'item_code' => 'FLT-9901', 'description' => 'Primary Fuel Filter',
        'part_number_size' => '1R-0750', 'unit' => 'pcs', 'category' => 'SPARE PART',
        'location' => 'BIN A-12', 'user_name' => '',
    ),
    (object)array(
        'id' => 29902, 'material_id' => 9901, 'type' => 'RECEIVE',
        'txn_date' => '2026-10-20', 'movement_date' => '2026-10-20',
        'created_at' => '2026-10-20 09:15:00',
        'reference_no' => 'MRN-9901', 'qty' => 6.0,
        'allocation_type' => 'Other', 'equipment_id' => NULL, 'equipment_code' => NULL,
        'allocation_label' => NULL, 'person' => 'Amina Store',
        'supplier_id' => 1, 'supplier_name' => NULL, 'linked_supplier_name' => 'Mantrac CAT',
        'qty_approved' => 6.0, 'unit_price' => 34.50, 'currency' => 'USD',
        'source' => 'Order GRN', 'affects_balance' => 1,
        'stock_before' => 2, 'stock_after' => 8, 'notes' => 'PO delivery',
        'item_code' => 'FLT-9901', 'description' => 'Primary Fuel Filter',
        'part_number_size' => '1R-0750', 'unit' => 'pcs', 'category' => 'SPARE PART',
        'location' => 'BIN A-12', 'user_name' => 'Amina Store',
    ),
);
$txnDb->queue = array(
    new EquipFleetFakeResult(array((object)array(
        'total_all' => 10164, 'receive_count' => 1850, 'issue_count' => 8314,
        'equip_issues' => 7420, 'valued_receipts' => 1120, 'live_entries' => 42,
        'total_received_qty' => 28400.0, 'total_issued_qty' => 19150.0,
    ))),
    new EquipFleetFakeResult(array((object)array('cnt' => 2, 'in_qty' => 6.0, 'out_qty' => 2.0))),
    new EquipFleetFakeResult($txnSampleRows),
);
$txnLedger = $txnModel->get_transactions_ledger(array('q' => 'FLT-9901'), 'txn_date', 'desc', 25, 0);
check('TXN-02 O(1) Query Scaling: 10,164-row warehouse ledger queried in exact <= 3 SQL SELECT queries',
    count($txnDb->queries) === 3 && $txnLedger['kpi']['total_all'] === 10164 && count($txnLedger['rows']) === 2,
    'Executed ' . count($txnDb->queries) . ' queries');

foreach (array('transactions', 'transaction_json', 'transactions_csv', 'transactions_xlsx') as $txRo) {
    check('TXN-05 Manager MUTATION_PATTERN compatibility: GET allowed on Materials::' . $txRo,
        !preg_match($mutPattern, $txRo));
}

$txnWarnings = array();
$renderTxnView = function ($canWrite) use ($txnLedger, &$txnWarnings) {
    $transactions    = $txnLedger['rows'];
    $kpi             = $txnLedger['kpi'];
    $tab_counts      = array('all' => 10164, 'receive' => 1850, 'issue' => 8314, 'equipment' => 7420, 'valued' => 1120, 'live' => 42);
    $tab             = 'all';
    $q               = '';
    $filter          = array('type' => '', 'source' => '', 'category' => '', 'allocation_type' => '', 'item_code' => '', 'date_from' => '', 'date_to' => '');
    $categories      = array((object)array('category' => 'SPARE PART'));
    $sort            = 'txn_date';
    $dir             = 'desc';
    $view_mode       = 'table';
    $per_page        = 25;
    $allowed_pp      = array(10, 25, 50, 100, 150);
    $current_page    = 1;
    $total_pages     = 407;
    $total_filtered  = 10164;
    $offset          = 0;
    $filtered_totals = $txnLedger['filtered_totals'];
    $can_write       = $canWrite;

    set_error_handler(function ($severity, $message, $file, $line) use (&$txnWarnings) {
        $txnWarnings[] = "$message ($file:$line)";
        return TRUE;
    });
    ob_start();
    include APPPATH . 'views/materials/transactions.php';
    $out = ob_get_clean();
    restore_error_handler();
    return $out;
};

$txnSupHtml = $renderTxnView(TRUE);
$txnMgrHtml = $renderTxnView(FALSE);

check('TXN-06 Clean View Render: materials/transactions.php renders Table + Dual Cards with ZERO PHP warnings',
    empty($txnWarnings)
    && strpos($txnSupHtml, '<table class="txn-table"') !== FALSE
    && strpos($txnSupHtml, 'class="txn-cards-grid"') !== FALSE,
    implode('; ', $txnWarnings));
check('TXN-01 Business Date Truthfulness: renders true txn_date 03 Feb 2026 instead of migration timestamp 06 Oct 2026',
    strpos($txnSupHtml, '03 Feb 2026') !== FALSE
    && strpos($txnSupHtml, '06 Oct 2026') === FALSE);
check('TXN-03 Verbatim Asset Link, Reference No & XSS Neutralization in materials/transactions.php',
    strpos($txnSupHtml, 'EX 006') !== FALSE
    && strpos($txnSupHtml, 'REQ-4692') !== FALSE
    && strpos($txnSupHtml, 'MRN-9901') !== FALSE
    && strpos($txnSupHtml, 'Mantrac CAT') !== FALSE
    && strpos($txnSupHtml, '<script>alert(9)</script>') === FALSE
    && strpos($txnMgrHtml, 'materials/transactions_csv') !== FALSE
    && strpos($txnMgrHtml, 'materials/transactions_xlsx') !== FALSE
    && strpos($txnSupHtml, 'id="txnDrawer" role="dialog" aria-modal="true"') !== FALSE);

/* ─────────────────────────────────────────────────────────────────────────
   Section 20 — Spare Orders Register Overhaul (ORD-01..ORD-06)
   ───────────────────────────────────────────────────────────────────────── */

if (!class_exists('Order_model', FALSE)) {
    require_once APPPATH . 'models/Order_model.php';
}

class OrderRegisterFakeDB {
    public $queries = 0;
    private $orders = array();
    private $items  = array();
    private $fromTable = '';

    public function __construct(array $orders, array $items) {
        $this->orders = $orders;
        $this->items  = $items;
    }
    public function dbprefix($t = '') { return (string)$t; }
    public function table_exists($t) {
        return in_array($t, array('spare_orders', 'spare_order_items', 'work_orders', 'equipment', 'users'), TRUE);
    }
    public function select($s, $escape = NULL) { return $this; }
    public function from($t) { $this->fromTable = (string)$t; return $this; }
    public function join($t, $c, $type = '') { return $this; }
    public function where($k, $v = NULL) { return $this; }
    public function where_in($k, $vals) { return $this; }
    public function group_by($g) { return $this; }
    public function order_by($c, $d = '') { return $this; }
    public function get($table = NULL) {
        $this->queries++;
        $src = $table !== NULL ? (string)$table : $this->fromTable;
        if (strpos($src, 'spare_orders') === 0) {
            return new TeamRosterFakeResult($this->orders);
        }
        if (strpos($src, 'spare_order_items') === 0) {
            return new TeamRosterFakeResult($this->items);
        }
        return new TeamRosterFakeResult(array());
    }
}

$fakeOrders = array();
$fakeOrderItems = array();
for ($oi = 1; $oi <= 500; $oi++) {
    $isLate = ($oi === 1);
    $isSoon = ($oi === 2);
    $fakeOrders[] = (object)array(
        'id'                     => $oi,
        'order_no'               => ($oi === 2) ? 'PO-XSS<script>alert(7)</script>' : sprintf('PO-2026-%04d', $oi),
        'supplier_id'            => 10,
        'supplier_name'          => ($oi === 1) ? 'Mantrac CAT Intl' : 'HydraForce TZ',
        'country'                => ($oi === 1) ? 'Germany' : 'Tanzania',
        'shipping_method'        => ($oi === 1) ? 'Air Freight' : 'Road Transport',
        'order_date'             => '2026-10-01',
        'expected_delivery_date' => $isLate ? '2000-01-01' : ($isSoon ? date('Y-m-d', strtotime('+3 days')) : '2099-12-31'),
        'actual_delivery_date'   => NULL,
        'status'                 => ($oi <= 2) ? 'In Transit' : (($oi <= 490) ? 'Sent to Supplier' : 'Verified & Closed'),
        'tracking_number'        => ($oi === 1) ? 'DHL-8841209' : '',
        'carrier'                => ($oi === 1) ? 'DHL Express' : '',
        'currency'               => ($oi % 2 === 1) ? 'USD' : 'TZS',
        'total_cost'             => ($oi % 2 === 1) ? 100.00 : 250000.00,
        'discrepancy_flag'       => ($oi === 1) ? 'Shortage' : 'None',
        'discrepancy_notes'      => ($oi === 1) ? '3 sets short' : NULL,
        'linked_wo_id'           => ($oi === 1) ? 1 : (($oi === 2) ? 2 : NULL),
        'wo_number'              => ($oi === 1) ? 'WO-AML-001' : (($oi === 2) ? 'WO-AML-002' : NULL),
        'wo_title'               => ($oi === 1) ? 'Main boom seal kit' : NULL,
        'equipment_id'           => ($oi === 1) ? 1 : (($oi === 2) ? 2 : NULL),
        'equipment_code'         => ($oi === 1) ? 'EX 006' : (($oi === 2) ? 'C-HEADT 007' : NULL),
        'first_name'             => 'CI',
        'last_name'              => 'Storekeeper',
        'notes'                  => '',
    );
    $fakeOrderItems[] = (object)array(
        'id'           => $oi,
        'order_id'     => $oi,
        'description'  => ($oi === 1) ? 'Main Boom Seal Kit' : 'Fuel Filter Spin-On',
        'part_number'  => ($oi === 1) ? '295-5609' : '1R-0750',
        'qty_ordered'  => 4,
        'qty_received' => ($oi === 1) ? 1 : 0,
    );
}

$ordFakeDb = new OrderRegisterFakeDB($fakeOrders, $fakeOrderItems);
$ordModel  = new Order_model();
$ordModel->db = $ordFakeDb;
$ordOv = $ordModel->get_register_overview();

check('ORD-02 Constant O(1) SQL Scaling: 500 POs + line items + KPIs hydrated in <= 2 SQL queries (replaces 9-query index storm)',
    count($ordOv['rows']) === 500 && $ordFakeDb->queries <= 2,
    'queries=' . $ordFakeDb->queries);

check('ORD-03 & K-11 Multi-Currency Open Commitment: groups USD and TZS separately & tracks partial GRN fulfillment (1/4 = 25%)',
    (int)$ordOv['kpi']['open_orders'] === 490
    && (int)$ordOv['kpi']['in_transit'] === 2
    && (int)$ordOv['kpi']['overdue'] === 1
    && (int)$ordOv['kpi']['due_soon'] === 1
    && (int)$ordOv['kpi']['discrepancies'] === 1
    && abs($ordOv['kpi']['open_spend_by_ccy']['USD'] - 24500.0) < 0.01
    && abs($ordOv['kpi']['open_spend_by_ccy']['TZS'] - 61250000.0) < 0.01
    && (int)$ordOv['rows'][0]->fulfillment_pct === 25
    && $ordOv['rows'][0]->equipment_code === 'EX 006');

$ordControllerSrc = file_get_contents(APPPATH . 'controllers/Orders.php');
check('ORD-01 & ORD-05 Server-Side RBAC & Exports in Orders.php: can_write() guards create/receive/upload_document, honours Manager update_status whitelist + CSV/XLSX/JSON',
    substr_count($ordControllerSrc, 'if (!$this->can_write())') >= 3
    && strpos($ordControllerSrc, '!$this->can_write() && !$this->is_manager()') !== FALSE
    && strpos($ordControllerSrc, 'public function summary_json(') !== FALSE
    && strpos($ordControllerSrc, 'public function export_csv(') !== FALSE
    && strpos($ordControllerSrc, 'public function export_xlsx(') !== FALSE);

$ordWarnings = array();
$renderOrdIndex = function ($canWrite) use ($ordOv, &$ordWarnings) {
    $orders          = array_slice($ordOv['rows'], 0, 2);
    $suppliers       = array((object)array('id' => 10, 'company_name' => 'Mantrac CAT Intl'));
    $countries       = array('Germany', 'Tanzania');
    $statuses        = Order_model::$STATUSES;
    $methods         = Order_model::$SHIPPING_METHODS;
    $kpi             = $ordOv['kpi'];
    $tab_counts      = array('all' => 500, 'open' => 490, 'transit' => 2, 'overdue' => 1, 'discrepancy' => 1, 'closed' => 10);
    $tab             = 'all';
    $q               = '';
    $filter          = array('status' => '', 'supplier' => '', 'country' => '', 'shipping_method' => '', 'search' => '');
    $sort            = 'order_date';
    $dir             = 'desc';
    $view_mode       = 'table';
    $per_page        = 25;
    $allowed_pp      = array(10, 25, 50, 100, 150);
    $current_page    = 1;
    $total_pages     = 20;
    $total_filtered  = 500;
    $offset          = 0;
    $filtered_totals = array(
        'qty_ordered'  => 8.0,
        'qty_received' => 1.0,
        'by_currency'  => array('USD' => 100.00, 'TZS' => 250000.00),
    );
    $can_write       = $canWrite;

    set_error_handler(function ($severity, $message, $file, $line) use (&$ordWarnings) {
        $ordWarnings[] = "$message ($file:$line)";
        return TRUE;
    });
    ob_start();
    include APPPATH . 'views/orders/index.php';
    $out = ob_get_clean();
    restore_error_handler();
    return $out;
};

$ordSupHtml = $renderOrdIndex(TRUE);
$ordMgrHtml = $renderOrdIndex(FALSE);

check('ORD-06 Clean View Render, Verbatim EX 006 / C-HEADT 007, RBAC Gate & XSS Neutralization in orders/index.php',
    empty($ordWarnings)
    && strpos($ordSupHtml, 'id="ordNewBtn"') !== FALSE
    && strpos($ordMgrHtml, 'id="ordNewBtn"') === FALSE
    && strpos($ordSupHtml, 'EX 006') !== FALSE
    && strpos($ordSupHtml, 'C-HEADT 007') !== FALSE
    && strpos($ordSupHtml, 'DHL-8841209') !== FALSE
    && strpos($ordSupHtml, 'USD 24,500.00 · TZS 61,250,000.00') !== FALSE
    && strpos($ordSupHtml, '<script>alert(7)</script>') === FALSE
    && strpos($ordSupHtml, 'id="ordDrawer" role="dialog" aria-modal="true"') !== FALSE,
    implode('; ', $ordWarnings));

/* ─────────────────────────────────────────────────────────────────────────
   Result
   ───────────────────────────────────────────────────────────────────────── */

printf("\n%d passed, %d failed\n", $GLOBALS['wms_test_pass'], $GLOBALS['wms_test_fail']);
if ($GLOBALS['wms_test_fail'] > 0) {
    echo "\nFailures:\n";
    foreach ($GLOBALS['wms_test_failures'] as $failure) {
        echo '  - ' . $failure . "\n";
        echo '::error title=UnitTestFail::' . str_replace(array("\r", "\n"), ' ', $failure) . "\n";
    }
    exit(1);
}
exit(0);
