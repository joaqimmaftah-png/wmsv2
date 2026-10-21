<?php
/**
 * Workshop Catalogue & Excel Export Recovery Tool for WMSv2
 *
 * Note: Your complete Equipment Register (116 machines + 338 specs + 44 meter readings)
 * is ALREADY restored automatically by running `scripts\migrate.bat` (migration 0008).
 *
 * This CLI utility adds two extra capabilities:
 *   1. Generate/refresh a multi-sheet Excel (.xlsx) Asset Register workbook directly
 *      from your MySQL database (100% compatible with Reports -> Asset Register Import):
 *        scripts\recover-data.bat --xlsx
 *
 *   2. Optionally recover additional non-credential operational catalogues from local
 *      Git commit `a4a2a6e` into MySQL WITHOUT re-importing any staff accounts,
 *      password hashes, or session rows (preserving SECURITY.md compliance):
 *        scripts\recover-data.bat --inventory     (1,697 spare parts + 12,721 stock movements)
 *        scripts\recover-data.bat --suppliers     (22 suppliers)
 *        scripts\recover-data.bat --backlogs      (44 backlog defect items)
 *        scripts\recover-data.bat --all           (all of the above + .xlsx export)
 */

if (isset($_SERVER['REMOTE_ADDR']) && $_SERVER['REMOTE_ADDR'] !== '') {
    http_response_code(403);
    exit("CLI access only.\n");
}

$rootDir = realpath(__DIR__ . '/..');
if (!defined('BASEPATH')) define('BASEPATH', $rootDir . '/system/');
if (!defined('APPPATH'))  define('APPPATH',  $rootDir . '/application/');
if (!defined('ENVIRONMENT')) define('ENVIRONMENT', getenv('CI_ENV') ?: 'development');

$opts = array(
    'xlsx'      => false,
    'xlsx_path' => $rootDir . '/database/Equipment_Register.xlsx',
    'inventory' => false,
    'suppliers' => false,
    'backlogs'  => false,
    'commit'    => 'a4a2a6e',
);

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--xlsx')           { $opts['xlsx'] = true; }
    elseif (strpos($arg, '--xlsx=') === 0) {
        $opts['xlsx'] = true;
        $p = substr($arg, 7);
        $opts['xlsx_path'] = (preg_match('#^(/|[A-Za-z]:[\\\\/])#', $p)) ? $p : ($rootDir . '/' . $p);
    }
    elseif ($arg === '--inventory')  { $opts['inventory'] = true; }
    elseif ($arg === '--suppliers')  { $opts['suppliers'] = true; }
    elseif ($arg === '--backlogs')   { $opts['backlogs']  = true; }
    elseif ($arg === '--all') {
        $opts['xlsx'] = $opts['inventory'] = $opts['suppliers'] = $opts['backlogs'] = true;
    }
    elseif (strpos($arg, '--commit=') === 0) {
        $opts['commit'] = preg_replace('/[^0-9a-fA-F]/', '', substr($arg, 9));
    }
    elseif ($arg === '--help' || $arg === '-h') {
        echo file_get_contents(__FILE__, false, null, 0, 1280);
        exit(0);
    }
}

if (!$opts['xlsx'] && !$opts['inventory'] && !$opts['suppliers'] && !$opts['backlogs']) {
    echo "Usage:\n";
    echo "  scripts\\recover-data.bat --xlsx         Export database/Equipment_Register.xlsx from DB\n";
    echo "  scripts\\recover-data.bat --inventory    Restore 1,697 spare parts & 12,721 stock movements\n";
    echo "  scripts\\recover-data.bat --suppliers    Restore 22 suppliers\n";
    echo "  scripts\\recover-data.bat --backlogs     Restore 44 defect backlog items\n";
    echo "  scripts\\recover-data.bat --all          Run all of the above\n";
    exit(0);
}

// Connect using identical config resolution as scripts/migrate.php
$envFile = $rootDir . '/.env';
if (is_file($envFile) && is_readable($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        list($k, $v) = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v, " \t\n\r\0\x0B\"'");
        if (getenv($k) === false) putenv("{$k}={$v}");
    }
}

$db = array();
if (is_file($rootDir . '/application/config/database.php')) {
    include $rootDir . '/application/config/database.php';
}
$appDb  = isset($db['default']) ? $db['default'] : array();
$host   = getenv('WMS_DB_HOST') ?: (getenv('DB_HOST') ?: (!empty($appDb['hostname']) ? $appDb['hostname'] : '127.0.0.1'));
$port   = (int)(getenv('WMS_DB_PORT') ?: (getenv('DB_PORT') ?: 3306));
if (strpos($host, ':') !== false && substr_count($host, ':') === 1) {
    list($host, $hp) = explode(':', $host, 2);
    if (ctype_digit($hp)) $port = (int)$hp;
}
if ($host === 'localhost') $host = '127.0.0.1';
$user   = getenv('WMS_DB_USER') ?: (getenv('DB_USER') ?: (!empty($appDb['username']) ? $appDb['username'] : 'root'));
$pass   = getenv('WMS_DB_PASS') !== false ? getenv('WMS_DB_PASS') : (getenv('DB_PASS') !== false ? getenv('DB_PASS') : (isset($appDb['password']) ? $appDb['password'] : ''));
$dbName = getenv('WMS_DB_NAME') ?: (getenv('DB_NAME') ?: (!empty($appDb['database']) ? $appDb['database'] : 'wmsv2'));

mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = mysqli_init();
if (!@$mysqli->real_connect($host, $user, $pass, $dbName, $port)) {
    if ($host === '127.0.0.1' && @$mysqli->real_connect('localhost', $user, $pass, $dbName, $port)) {
        $host = 'localhost';
    } else {
        fwrite(STDERR, "ERROR: Cannot connect to MySQL `{$dbName}` at {$host}:{$port}: " . mysqli_connect_error() . "\n");
        exit(1);
    }
}
$mysqli->set_charset('utf8mb4');

/**
 * Parse tuple rows from an `INSERT INTO `table` (...) VALUES ...;` SQL dump block.
 */
function wms_parse_dump_table($rawSql, $table) {
    $rows = array();
    $cols = array();
    $pattern = '/INSERT INTO `' . preg_quote($table, '/') . '`\s*\(([^)]+)\)\s*VALUES\s*([\s\S]*?);(?=\s*(?:--|\/\*|INSERT|CREATE|ALTER|COMMIT|$))/';
    if (!preg_match_all($pattern, $rawSql, $matches, PREG_SET_ORDER)) {
        return array('cols' => array(), 'rows' => array());
    }
    foreach ($matches as $m) {
        $cols = array_map(function ($c) { return trim($c, " \t\r\n`"); }, explode(',', $m[1]));
        $bv = $m[2];
        $i = 0; $n = strlen($bv);
        while ($i < $n) {
            while ($i < $n && $bv[$i] !== '(') $i++;
            if ($i >= $n) break;
            $i++;
            $row = array();
            while ($i < $n) {
                while ($i < $n && ctype_space($bv[$i])) $i++;
                if ($i < $n && $bv[$i] === ')') { $i++; break; }
                if ($bv[$i] === "'") {
                    $i++; $buf = '';
                    while ($i < $n) {
                        $ch = $bv[$i];
                        if ($ch === '\\' && $i + 1 < $n) {
                            $nx = $bv[$i + 1];
                            $map = array('n' => "\n", 'r' => "\r", 't' => "\t", '0' => "\0", '\\' => '\\', "'" => "'", '"' => '"');
                            $buf .= isset($map[$nx]) ? $map[$nx] : $nx;
                            $i += 2; continue;
                        }
                        if ($ch === "'") {
                            if ($i + 1 < $n && $bv[$i + 1] === "'") { $buf .= "'"; $i += 2; continue; }
                            $i++; break;
                        }
                        $buf .= $ch; $i++;
                    }
                    $row[] = $buf;
                } else {
                    $s = $i;
                    while ($i < $n && $bv[$i] !== ',' && $bv[$i] !== ')') $i++;
                    $tok = trim(substr($bv, $s, $i - $s));
                    if (strcasecmp($tok, 'NULL') === 0) $row[] = null;
                    elseif (preg_match('/^-?\d+$/', $tok)) $row[] = (int)$tok;
                    else $row[] = $tok;
                }
                while ($i < $n && ctype_space($bv[$i])) $i++;
                if ($i < $n && $bv[$i] === ',') $i++;
            }
            $rows[] = $row;
        }
    }
    return array('cols' => $cols, 'rows' => $rows);
}

function wms_sql_val($mysqli, $v) {
    if ($v === null) return 'NULL';
    if (is_int($v)) return (string)$v;
    $s = (string)$v;
    if (strpos($s, '0000-00-00') !== false) $s = '2026-10-06 15:26:03';
    return "'" . $mysqli->real_escape_string($s) . "'";
}

// Recover optional non-credential catalogues from local Git history if requested
if ($opts['suppliers'] || $opts['inventory'] || $opts['backlogs']) {
    $cmd = 'git -C ' . escapeshellarg($rootDir) . ' show ' . escapeshellarg($opts['commit'] . ':database/yzzme_43038742_aml.sql') . ' 2>&1';
    $rawSql = shell_exec($cmd);
    if (!$rawSql || strpos($rawSql, 'INSERT INTO `equipment`') === false) {
        fwrite(STDERR, "Fetching full Git history from origin to locate commit {$opts['commit']}...\n");
        shell_exec('git -C ' . escapeshellarg($rootDir) . ' fetch --unshallow --all 2>&1');
        $rawSql = shell_exec($cmd);
    }
    if (!$rawSql || strpos($rawSql, 'INSERT INTO `equipment`') === false) {
        fwrite(STDERR, "ERROR: Could not read {$opts['commit']}:database/yzzme_43038742_aml.sql from Git history.\n");
        exit(1);
    }

    if ($opts['suppliers']) {
        $sup = wms_parse_dump_table($rawSql, 'suppliers');
        $colSql = implode(', ', array_map(function ($c) { return "`{$c}`"; }, $sup['cols']));
        $vals = array();
        foreach ($sup['rows'] as $r) {
            $vals[] = '(' . implode(', ', array_map(function ($v) use ($mysqli) { return wms_sql_val($mysqli, $v); }, $r)) . ')';
        }
        if ($vals) {
            if (!$mysqli->query("INSERT IGNORE INTO `suppliers` ({$colSql}) VALUES " . implode(",\n", $vals))) {
                fwrite(STDERR, "ERROR importing suppliers: {$mysqli->error}\n");
                exit(1);
            }
            echo "Suppliers restored: " . count($vals) . " rows checked/imported into `suppliers`.\n";
        }
    }

    if ($opts['inventory']) {
        $mat = wms_parse_dump_table($rawSql, 'materials');
        $colSql = implode(', ', array_map(function ($c) { return "`{$c}`"; }, $mat['cols']));
        foreach (array_chunk($mat['rows'], 200) as $chunk) {
            $vals = array();
            foreach ($chunk as $r) {
                $vals[] = '(' . implode(', ', array_map(function ($v) use ($mysqli) { return wms_sql_val($mysqli, $v); }, $r)) . ')';
            }
            if (!$mysqli->query("INSERT IGNORE INTO `materials` ({$colSql}) VALUES " . implode(",\n", $vals))) {
                fwrite(STDERR, "ERROR importing materials: {$mysqli->error}\n");
                exit(1);
            }
        }
        echo "Materials restored: " . count($mat['rows']) . " spare parts checked/imported into `materials`.\n";

        $tx = wms_parse_dump_table($rawSql, 'material_transactions');
        $createdByIdx = array_search('created_by', $tx['cols'], true);
        $personIdx    = array_search('person', $tx['cols'], true);
        $woIdx        = array_search('work_order_id', $tx['cols'], true);
        $colSql = implode(', ', array_map(function ($c) { return "`{$c}`"; }, $tx['cols']));
        foreach (array_chunk($tx['rows'], 400) as $chunk) {
            $vals = array();
            foreach ($chunk as $r) {
                if ($createdByIdx !== false) $r[$createdByIdx] = null;
                if ($personIdx !== false)    $r[$personIdx]    = null;
                if ($woIdx !== false)        $r[$woIdx]        = null;
                $vals[] = '(' . implode(', ', array_map(function ($v) use ($mysqli) { return wms_sql_val($mysqli, $v); }, $r)) . ')';
            }
            if (!$mysqli->query("INSERT IGNORE INTO `material_transactions` ({$colSql}) VALUES " . implode(",\n", $vals))) {
                fwrite(STDERR, "ERROR importing material_transactions: {$mysqli->error}\n");
                exit(1);
            }
        }
        echo "Stock history restored: " . count($tx['rows']) . " movements checked/imported into `material_transactions`.\n";
    }

    if ($opts['backlogs']) {
        $oldEq = wms_parse_dump_table($rawSql, 'equipment');
        $regByOldId = array();
        foreach ($oldEq['rows'] as $er) $regByOldId[(int)$er[0]] = $er[2];

        $liveIdByReg = array();
        $resEq = $mysqli->query("SELECT `id`, UPPER(TRIM(`registration_no`)) AS reg FROM `equipment`");
        while ($row = $resEq->fetch_assoc()) $liveIdByReg[$row['reg']] = (int)$row['id'];
        $resEq->free();

        $bl = wms_parse_dump_table($rawSql, 'backlogs');
        $eqIdx  = array_search('equipment_id', $bl['cols'], true);
        $nullFk = array('work_order_id', 'created_by', 'verified_by', 'closed_by', 'reported_by', 'in_charge', 'mechanic', 'supervisor');
        $colSql = implode(', ', array_map(function ($c) { return "`{$c}`"; }, $bl['cols']));
        $vals   = array();
        foreach ($bl['rows'] as $r) {
            if ($eqIdx !== false && $r[$eqIdx] !== null) {
                $oldReg = isset($regByOldId[(int)$r[$eqIdx]]) ? strtoupper(trim($regByOldId[(int)$r[$eqIdx]])) : '';
                $r[$eqIdx] = isset($liveIdByReg[$oldReg]) ? $liveIdByReg[$oldReg] : null;
            }
            foreach ($nullFk as $colName) {
                $idx = array_search($colName, $bl['cols'], true);
                if ($idx !== false) $r[$idx] = null;
            }
            $vals[] = '(' . implode(', ', array_map(function ($v) use ($mysqli) { return wms_sql_val($mysqli, $v); }, $r)) . ')';
        }
        if ($vals) {
            if (!$mysqli->query("INSERT IGNORE INTO `backlogs` ({$colSql}) VALUES " . implode(",\n", $vals))) {
                fwrite(STDERR, "ERROR importing backlogs: {$mysqli->error}\n");
                exit(1);
            }
            echo "Backlogs restored: " . count($vals) . " defect items checked/imported into `backlogs`.\n";
        }
    }
}

// Export multi-sheet Asset Register .xlsx workbook compatible with Reports -> Asset Register Import
if ($opts['xlsx']) {
    require_once APPPATH . 'helpers/xlsx_helper.php';
    require_once APPPATH . 'libraries/Asset_excel.php';

    $assets = array();
    $sqlAssets = "SELECT e.*, COALESCE(et.`name`, e.`machine_type`, 'Equipment') AS `type_name`, et.`category`
                  FROM `equipment` e
                  LEFT JOIN `equipment_types` et ON et.`id` = e.`equipment_type_id`
                  ORDER BY e.`id` ASC";
    $res = $mysqli->query($sqlAssets);
    if (!$res) {
        fwrite(STDERR, "ERROR querying equipment: {$mysqli->error}\n");
        exit(1);
    }
    while ($row = $res->fetch_assoc()) {
        $warrantyStatus = 'N/A';
        $warrantyDays   = null;
        if (!empty($row['warranty_expiry'])) {
            $days = (int)ceil((strtotime($row['warranty_expiry']) - time()) / 86400);
            $warrantyDays   = $days;
            $warrantyStatus = $days > 0 ? 'Valid' : 'Expired';
        }
        $engineModel = (!empty($row['engine_model'])) ? $row['engine_model'] : (!empty($row['engine_description']) ? $row['engine_description'] : '');
        $assets[] = array(
            'id'               => (int)$row['id'],
            'equipment_type_id'=> (int)$row['equipment_type_id'],
            'type'             => strtoupper((string)($row['type_name'] ? $row['type_name'] : 'OTHER')),
            'type_name'        => $row['type_name'],
            'category'         => $row['category'],
            'registration_no'  => $row['registration_no'],
            'brand'            => $row['brand'],
            'model'            => $row['model'],
            'year'             => $row['year'],
            'serial_number'    => $row['serial_number'],
            'engine_model'     => $engineModel,
            'current_hours'    => (float)$row['current_hours'],
            'current_km'       => (float)$row['current_km'],
            'location'         => $row['location'],
            'status'           => $row['status'],
            'purchase_date'    => $row['purchase_date'],
            'warranty_expiry'  => $row['warranty_expiry'],
            'warranty_status'  => $warrantyStatus,
            'warranty_days'    => $warrantyDays,
            'notes'            => $row['notes'],
            'created_at'       => $row['created_at'],
            'updated_at'       => $row['updated_at'],
            'total_wo'         => 0,
            'breakdowns'       => 0,
            'corrective_wo'    => 0,
            'completed_wo'     => 0,
            'open_wo'          => 0,
            'total_down'       => 0.0,
            'availability'     => 100.0,
            'next_pm_name'     => null,
            'next_pm_date'     => null,
            'next_pm_hours'    => null,
            'last_pm_date'     => null,
            'pm_interval'      => null,
        );
    }
    $res->free();

    $outPath = $opts['xlsx_path'];
    if (!is_dir(dirname($outPath))) @mkdir(dirname($outPath), 0755, true);

    $excel = new Asset_excel();
    $excel->export_register(
        array('assets' => $assets),
        $outPath,
        array('title' => 'WORKSHOP EQUIPMENT REGISTER', 'generated_by' => 'WMSv2 Recovery')
    );

    echo sprintf("Exported Excel workbook (%d assets): %s (%s bytes)\n", count($assets), $outPath, number_format(filesize($outPath)));
}

$mysqli->close();
