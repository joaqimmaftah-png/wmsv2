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
is_same('Migrations: all 12 ordered SQL migrations present (0001..0012)', 12, count($migFiles));
foreach ($migFiles as $mf) {
    $bn  = basename($mf);
    $sum = hash_file('sha256', $mf);
    check("Migrations: schema.sql ledger checksum matches {$bn}", strpos($schemaSql, "'{$bn}', '{$sum}'") !== FALSE);
    $mSql = file_get_contents($mf);
    check("Security (K-02): {$bn} never embeds staff accounts or session dumps",
        strpos($mSql, 'INSERT INTO `users`') === FALSE && strpos($mSql, 'INSERT INTO `ci_sessions`') === FALSE);
}
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

/* ─────────────────────────────────────────────────────────────────────────
   Result
   ───────────────────────────────────────────────────────────────────────── */

printf("\n%d passed, %d failed\n", $GLOBALS['wms_test_pass'], $GLOBALS['wms_test_fail']);
if ($GLOBALS['wms_test_fail'] > 0) {
    echo "\nFailures:\n";
    foreach ($GLOBALS['wms_test_failures'] as $failure) {
        echo '  - ' . $failure . "\n";
    }
    exit(1);
}
exit(0);
