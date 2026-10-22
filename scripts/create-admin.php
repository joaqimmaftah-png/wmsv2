<?php
/**
 * Create or update a user account from CLI without embedding PII in SQL dumps.
 *
 * Usage:
 *   php scripts/create-admin.php <email> <password> [first_name] [last_name] [role_id]
 *
 * Example (XAMPP / production bootstrap):
 *   php scripts/create-admin.php admin@workshop.local "ChangeMeNow!2026" "Workshop" "Supervisor" 1
 */

if (isset($_SERVER['REMOTE_ADDR']) && $_SERVER['REMOTE_ADDR'] !== '') {
    http_response_code(403);
    exit("CLI access only.\n");
}

if ($argc < 3) {
    fwrite(STDERR, "Usage: php scripts/create-admin.php <email> <password> [first_name] [last_name] [role_id]\n");
    exit(1);
}

$email     = strtolower(trim($argv[1]));
$password  = (string)$argv[2];
$firstName = isset($argv[3]) ? trim($argv[3]) : 'Workshop';
$lastName  = isset($argv[4]) ? trim($argv[4]) : 'Supervisor';

$roleMap = array(
    'supervisor'  => 1,
    'admin'       => 1,
    'technician'  => 2,
    'tech'        => 2,
    'store'       => 3,
    'storekeeper' => 3,
    'manager'     => 4,
    'ceo'         => 5,
    'director'    => 5,
    'executive'   => 5,
);
$rawRole = isset($argv[5]) ? trim($argv[5]) : '1';
if (ctype_digit($rawRole)) {
    $roleId = max(1, min(5, (int)$rawRole));
} else {
    $roleKey = strtolower($rawRole);
    $roleId  = isset($roleMap[$roleKey]) ? $roleMap[$roleKey] : 1;
}
$customEmpId = (isset($argv[6]) && trim($argv[6]) !== '') ? trim($argv[6]) : null;

if (strlen($password) < 8) {
    fwrite(STDERR, "ERROR: Password must be at least 8 characters.\n");
    exit(1);
}

$rootDir = realpath(__DIR__ . '/..');
if (!defined('BASEPATH')) define('BASEPATH', $rootDir . '/system/');
if (!defined('ENVIRONMENT')) define('ENVIRONMENT', getenv('CI_ENV') ?: 'development');

$appDb = array();
$dbConfigFile = $rootDir . '/application/config/database.php';
if (is_file($dbConfigFile)) {
    $db = array();
    include $dbConfigFile;
    if (isset($db['default'])) $appDb = $db['default'];
}

$host = getenv('WMS_DB_HOST') ?: (getenv('DB_HOST') ?: (!empty($appDb['hostname']) ? $appDb['hostname'] : '127.0.0.1'));
$port = (int)(getenv('WMS_DB_PORT') ?: (getenv('DB_PORT') ?: 3306));
if ($host === 'localhost') $host = '127.0.0.1';
$user = getenv('WMS_DB_USER') ?: (getenv('DB_USER') ?: (!empty($appDb['username']) ? $appDb['username'] : 'root'));
$pass = getenv('WMS_DB_PASS') !== false ? getenv('WMS_DB_PASS') : (getenv('DB_PASS') !== false ? getenv('DB_PASS') : (isset($appDb['password']) ? $appDb['password'] : ''));
$dbName = getenv('WMS_DB_NAME') ?: (getenv('DB_NAME') ?: (!empty($appDb['database']) ? $appDb['database'] : 'wmsv2'));

mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli($host, $user, $pass, $dbName, $port);
if ($mysqli->connect_errno) {
    fwrite(STDERR, "ERROR: DB connection failed: {$mysqli->connect_error}\n");
    exit(1);
}
$mysqli->set_charset('utf8mb4');

$hash = password_hash($password, PASSWORD_DEFAULT);
if ($customEmpId !== null) {
    $empId = $customEmpId;
} else {
    $chk = $mysqli->prepare("SELECT 1 FROM `users` WHERE `employee_id` = ? LIMIT 1");
    do {
        $empId = sprintf('EMP%04d', random_int(100, 9999));
        $chk->bind_param('s', $empId);
        $chk->execute();
        $taken = $chk->get_result()->fetch_assoc();
    } while ($taken);
    $chk->close();
}

$posMap = array(
    1 => 'Workshop Supervisor',
    2 => 'Maintenance Technician',
    3 => 'Storekeeper',
    4 => 'Workshop Manager',
    5 => 'Executive Director',
);
$pos = isset($posMap[$roleId]) ? $posMap[$roleId] : 'Staff';

$stmt = $mysqli->prepare("SELECT `id` FROM `users` WHERE `email` = ? LIMIT 1");
$stmt->bind_param('s', $email);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($existing) {
    $id = (int)$existing['id'];
    $up = $mysqli->prepare("UPDATE `users` SET `password` = ?, `role_id` = ?, `first_name` = ?, `last_name` = ?, `position` = ?, `status` = 'Active' WHERE `id` = ?");
    $up->bind_param('sisssi', $hash, $roleId, $firstName, $lastName, $pos, $id);
    $up->execute();
    $up->close();
    echo "Updated user #{$id} ({$email}, role={$roleId}).\n";
} else {
    $ins = $mysqli->prepare("INSERT INTO `users` (`role_id`, `employee_id`, `first_name`, `last_name`, `email`, `password`, `position`, `skill_level`, `status`) VALUES (?, ?, ?, ?, ?, ?, ?, 'Expert', 'Active')");
    $ins->bind_param('issssss', $roleId, $empId, $firstName, $lastName, $email, $hash, $pos);
    if (!$ins->execute()) {
        fwrite(STDERR, "ERROR: Insert failed: {$ins->error}\n");
        $mysqli->close();
        exit(1);
    }
    echo "Created user #{$ins->insert_id} ({$email}, role={$roleId}).\n";
    $ins->close();
}

$mysqli->close();
