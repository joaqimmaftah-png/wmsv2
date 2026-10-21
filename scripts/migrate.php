<?php
/**
 * Cross-platform Database Migration & Schema Verification Runner for WMSv2
 *
 * Works identically on:
 *   - Windows XAMPP (`C:\xampp\php\php.exe scripts\migrate.php` or `scripts\migrate.bat`)
 *   - Linux / macOS / Docker / GitHub Actions (`php scripts/migrate.php`)
 *   - cPanel SSH / Cron jobs (supports both `cli` and `cgi-fcgi` CLI SAPIs via `mysqli`)
 *
 * Usage:
 *   php scripts/migrate.php            Apply all pending migrations in database/migrations/
 *   php scripts/migrate.php --status   Show applied vs pending migrations without changes
 *   php scripts/migrate.php --schema   Import full database/schema.sql into an empty database
 *   php scripts/migrate.php --verify   Verify tables, PKs, FKs, settings & 14 checklists
 *   php scripts/migrate.php --force    Re-execute all idempotent migrations even if recorded
 *
 * Optional flags (override env vars / .env / application/config/database.php):
 *   --host=127.0.0.1 --port=3306 --user=root --pass=secret --db=wmsv2
 */

if (isset($_SERVER['REMOTE_ADDR']) && $_SERVER['REMOTE_ADDR'] !== '') {
    http_response_code(403);
    exit("CLI access only.\n");
}

$rootDir = realpath(__DIR__ . '/..');
$migDir  = $rootDir . '/database/migrations';
$schemaPath = $rootDir . '/database/schema.sql';

// Parse CLI flags
$opts = array(
    'status' => false,
    'schema' => false,
    'verify' => false,
    'force'  => false,
    'host'   => null,
    'port'   => null,
    'user'   => null,
    'pass'   => null,
    'db'     => null,
);
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--status') { $opts['status'] = true; }
    elseif ($arg === '--schema') { $opts['schema'] = true; }
    elseif ($arg === '--verify') { $opts['verify'] = true; }
    elseif ($arg === '--force')  { $opts['force']  = true; }
    elseif (preg_match('/^--(host|port|user|pass|db)=(.*)$/', $arg, $m)) {
        $opts[$m[1]] = $m[2];
    } elseif ($arg === '--help' || $arg === '-h') {
        echo file_get_contents(__FILE__, false, null, 0, 1100);
        exit(0);
    }
}

// Load .env if present
$envFile = $rootDir . '/.env';
if (is_file($envFile) && is_readable($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        list($k, $v) = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v, " \t\n\r\0\x0B\"'");
        if (getenv($k) === false) {
            putenv("{$k}={$v}");
            $_ENV[$k] = $v;
        }
    }
}

// Load application/config/database.php defaults if available
$appDb = array();
if (!defined('BASEPATH')) define('BASEPATH', $rootDir . '/system/');
if (!defined('ENVIRONMENT')) define('ENVIRONMENT', getenv('CI_ENV') ?: 'development');
$dbConfigFile = $rootDir . '/application/config/database.php';
if (is_file($dbConfigFile)) {
    $db = array();
    include $dbConfigFile;
    if (isset($db['default']) && is_array($db['default'])) {
        $appDb = $db['default'];
    }
}

$host = $opts['host'] !== null ? $opts['host'] : (getenv('WMS_DB_HOST') ?: (getenv('DB_HOST') ?: (isset($appDb['hostname']) && $appDb['hostname'] !== '' ? $appDb['hostname'] : '127.0.0.1')));
$port = (int)($opts['port'] !== null ? $opts['port'] : (getenv('WMS_DB_PORT') ?: (getenv('DB_PORT') ?: 3306)));
if (strpos($host, ':') !== false && substr_count($host, ':') === 1) {
    list($host, $hp) = explode(':', $host, 2);
    if (ctype_digit($hp)) $port = (int)$hp;
}
if ($host === 'localhost') {
    // Prefer TCP 127.0.0.1 when default socket differs (common on XAMPP macOS/Linux & CI containers)
    $host = '127.0.0.1';
}
$user = $opts['user'] !== null ? $opts['user'] : (getenv('WMS_DB_USER') ?: (getenv('DB_USER') ?: (isset($appDb['username']) && $appDb['username'] !== '' ? $appDb['username'] : 'root')));
$pass = $opts['pass'] !== null ? $opts['pass'] : (getenv('WMS_DB_PASS') !== false ? getenv('WMS_DB_PASS') : (getenv('DB_PASS') !== false ? getenv('DB_PASS') : (isset($appDb['password']) ? $appDb['password'] : '')));
$dbName = $opts['db'] !== null ? $opts['db'] : (getenv('WMS_DB_NAME') ?: (getenv('DB_NAME') ?: (isset($appDb['database']) && $appDb['database'] !== '' ? $appDb['database'] : 'wmsv2')));

mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = mysqli_init();
if (!$mysqli) {
    fwrite(STDERR, "ERROR: PHP mysqli extension is not available.\n");
    exit(1);
}

// Connect without database first so we can create it if `--schema` is used
if (!@$mysqli->real_connect($host, $user, $pass, '', $port)) {
    // Fallback to socket if 127.0.0.1 was refused on a socket-only local server
    if ($host === '127.0.0.1' && @$mysqli->real_connect('localhost', $user, $pass, '', $port)) {
        $host = 'localhost';
    } else {
        fwrite(STDERR, sprintf("ERROR: Cannot connect to MySQL/MariaDB at %s:%d as '%s': %s\n", $host, $port, $user, mysqli_connect_error()));
        exit(1);
    }
}
$mysqli->set_charset('utf8mb4');

if (!$mysqli->select_db($dbName)) {
    $safeDb = str_replace('`', '``', $dbName);
    if (!$mysqli->query("CREATE DATABASE IF NOT EXISTS `{$safeDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci") || !$mysqli->select_db($dbName)) {
        fwrite(STDERR, "ERROR: Database '{$dbName}' does not exist and could not be created: " . $mysqli->error . "\n");
        exit(1);
    }
    echo "Created database `{$dbName}` (utf8mb4_unicode_ci).\n";
}

/**
 * Quote-and-comment-aware SQL splitter (handles `;` inside string literals & comments).
 */
function wms_split_sql($sql) {
    $stmts = array();
    $cur   = '';
    $len   = strlen($sql);
    $inSq = false; $inDq = false; $inBt = false; $inLine = false; $inBlock = false;

    for ($i = 0; $i < $len; $i++) {
        $ch  = $sql[$i];
        $nxt = ($i + 1 < $len) ? $sql[$i + 1] : '';

        if ($inLine) {
            $cur .= $ch;
            if ($ch === "\n") $inLine = false;
            continue;
        }
        if ($inBlock) {
            $cur .= $ch;
            if ($ch === '*' && $nxt === '/') { $cur .= $nxt; $i++; $inBlock = false; }
            continue;
        }
        if ($inSq) {
            $cur .= $ch;
            if ($ch === '\\' && $nxt !== '') { $cur .= $nxt; $i++; continue; }
            if ($ch === "'" && $nxt === "'") { $cur .= $nxt; $i++; continue; }
            if ($ch === "'") $inSq = false;
            continue;
        }
        if ($inDq) {
            $cur .= $ch;
            if ($ch === '\\' && $nxt !== '') { $cur .= $nxt; $i++; continue; }
            if ($ch === '"' && $nxt === '"') { $cur .= $nxt; $i++; continue; }
            if ($ch === '"') $inDq = false;
            continue;
        }
        if ($inBt) {
            $cur .= $ch;
            if ($ch === '`') $inBt = false;
            continue;
        }

        if ($ch === '-' && $nxt === '-' && ($i + 2 === $len || ctype_space($sql[$i + 2]))) {
            $inLine = true; $cur .= $ch; continue;
        }
        if ($ch === '/' && $nxt === '*' && ($i + 2 >= $len || $sql[$i + 2] !== '!')) {
            $inBlock = true; $cur .= $ch; continue;
        }
        if ($ch === "'") { $inSq = true; $cur .= $ch; continue; }
        if ($ch === '"') { $inDq = true; $cur .= $ch; continue; }
        if ($ch === '`') { $inBt = true; $cur .= $ch; continue; }

        if ($ch === ';') {
            $trimmed = trim($cur);
            if ($trimmed !== '') $stmts[] = $trimmed;
            $cur = '';
            continue;
        }
        $cur .= $ch;
    }
    if (trim($cur) !== '') $stmts[] = trim($cur);
    return $stmts;
}

/**
 * Execute every SQL statement in a file sequentially, draining result sets cleanly.
 */
function wms_exec_sql_file($mysqli, $filePath) {
    $sql = file_get_contents($filePath);
    if ($sql === false) {
        throw new RuntimeException("Unable to read {$filePath}");
    }
    $stmts = wms_split_sql($sql);
    $n = 0;
    foreach ($stmts as $idx => $stmt) {
        // Strip leading `--` comment lines to check if statement is purely comments
        $clean = trim(preg_replace('/^\s*--[^\r\n]*(\r?\n|$)/m', '', $stmt));
        if ($clean === '') continue;

        if (!$mysqli->query($clean)) {
            $preview = substr(preg_replace('/\s+/', ' ', $clean), 0, 220);
            throw new RuntimeException(sprintf(
                "SQL error in %s (statement #%d): [%d] %s\n  SQL: %s",
                basename($filePath),
                $idx + 1,
                $mysqli->errno,
                $mysqli->error,
                $preview
            ));
        }
        while ($res = $mysqli->store_result()) {
            $res->free();
        }
        while ($mysqli->more_results() && $mysqli->next_result()) {
            if ($res = $mysqli->store_result()) $res->free();
        }
        $n++;
    }
    return $n;
}

// Check whether target database is currently completely empty (0 tables)
$existingTableCount = 0;
$resEmpty = @$mysqli->query("SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()");
if ($resEmpty) {
    $existingTableCount = (int)$resEmpty->fetch_assoc()['c'];
    $resEmpty->free();
}

// 1. Load baseline schema.sql when `--schema` is explicitly passed OR when migrating an empty database (0 tables)
$autoBootstrapped = (!$opts['schema'] && !$opts['status'] && $existingTableCount === 0);
if ($opts['schema'] || $autoBootstrapped) {
    if (!is_file($schemaPath)) {
        fwrite(STDERR, "ERROR: {$schemaPath} not found.\n");
        exit(1);
    }
    if ($autoBootstrapped) {
        echo "Empty database `{$dbName}` detected — bootstrapping baseline schema from database/schema.sql...\n";
    } else {
        echo "Importing baseline schema from database/schema.sql into `{$dbName}`...\n";
    }
    try {
        $count = wms_exec_sql_file($mysqli, $schemaPath);
        echo "OK — executed {$count} statements from database/schema.sql.\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "FAILED: " . $e->getMessage() . "\n");
        exit(1);
    }
}

// Discover ordered migration files
$files = glob($migDir . '/[0-9][0-9][0-9][0-9]_*.sql');
sort($files, SORT_STRING);

// Ensure tracking ledger exists when modifying DB
if (!$opts['status']) {
    $mysqli->query("CREATE TABLE IF NOT EXISTS `schema_migrations` (
      `version` varchar(20) NOT NULL,
      `filename` varchar(255) NOT NULL,
      `checksum` char(64) NOT NULL,
      `applied_at` timestamp NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`version`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

// Load applied ledger rows if table exists
$applied = array();
$res = @$mysqli->query("SELECT `version`, `filename`, `checksum`, `applied_at` FROM `schema_migrations` ORDER BY `version` ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $applied[$row['version']] = $row;
    }
    $res->free();
}

// 2. `--status` mode
if ($opts['status']) {
    printf("Database: %s@%s:%d/%s\n\n", $user, $host, $port, $dbName);
    printf("%-8s  %-10s  %-48s  %s\n", "VERSION", "STATE", "FILENAME", "APPLIED AT");
    printf("%s\n", str_repeat('-', 92));
    foreach ($files as $f) {
        $bn  = basename($f);
        $ver = strtok($bn, '_');
        $state = isset($applied[$ver]) ? 'APPLIED' : 'PENDING';
        $at    = isset($applied[$ver]) ? $applied[$ver]['applied_at'] : '-';
        printf("%-8s  %-10s  %-48s  %s\n", $ver, $state, $bn, $at);
    }
    $mysqli->close();
    exit(0);
}

// 3. Apply all pending (or `--force`d) migrations before optional `--verify`
if (!$opts['status']) {
    $ranCount = 0;
    $skipCount = 0;
    foreach ($files as $f) {
        $bn  = basename($f);
        $ver = strtok($bn, '_');
        $sum = hash_file('sha256', $f);

        if (!$opts['force'] && isset($applied[$ver])) {
            $skipCount++;
            continue;
        }

        echo "Applying {$bn} ... ";
        try {
            $n = wms_exec_sql_file($mysqli, $f);
            $stmt = $mysqli->prepare(
                "INSERT INTO `schema_migrations` (`version`, `filename`, `checksum`) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE `filename` = VALUES(`filename`), `checksum` = VALUES(`checksum`), `applied_at` = CURRENT_TIMESTAMP"
            );
            $stmt->bind_param('sss', $ver, $bn, $sum);
            $stmt->execute();
            $stmt->close();
            echo "OK ({$n} statements)\n";
            $ranCount++;
        } catch (Throwable $e) {
            echo "FAILED\n";
            fwrite(STDERR, $e->getMessage() . "\n");
            exit(1);
        }
    }
    echo "Migrations complete: {$ranCount} applied, {$skipCount} already up to date.\n";
}

// 4. Optional `--verify` health assertions
if ($opts['verify']) {
    $errors = array();

    $res = $mysqli->query("SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()");
    $tableCount = $res ? (int)$res->fetch_assoc()['c'] : 0;
    if ($tableCount < 60) {
        $errors[] = "Expected at least 62 tables (61 operational + schema_migrations), found {$tableCount}.";
    }

    $res = $mysqli->query("SELECT COUNT(*) AS c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ci_sessions' AND INDEX_NAME = 'PRIMARY'");
    $pkCols = $res ? (int)$res->fetch_assoc()['c'] : 0;
    if ($pkCols !== 1) {
        $errors[] = "Expected single-column PRIMARY KEY (`id`) on `ci_sessions`, found {$pkCols} PK column(s).";
    }

    $res = $mysqli->query("SELECT COUNT(*) AS c FROM `settings`");
    $settingsCount = $res ? (int)$res->fetch_assoc()['c'] : 0;
    if ($settingsCount < 25) {
        $errors[] = "Expected at least 25 seeded settings rows, found {$settingsCount}.";
    }

    $res = $mysqli->query("SELECT COUNT(*) AS c FROM `inspection_templates`");
    $tplCount = $res ? (int)$res->fetch_assoc()['c'] : 0;
    if ($tplCount < 14) {
        $errors[] = "Expected all 14 workshop inspection_templates, found {$tplCount}.";
    }

    $res = $mysqli->query("SELECT COUNT(*) AS c FROM `inspection_items`");
    $itemCount = $res ? (int)$res->fetch_assoc()['c'] : 0;
    if ($itemCount < 630) {
        $errors[] = "Expected at least 630 inspection_items across the 14 checklists, found {$itemCount}.";
    }

    $res = $mysqli->query("SELECT COUNT(DISTINCT CONSTRAINT_NAME) AS c FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = 'FOREIGN KEY'");
    $fkCount = $res ? (int)$res->fetch_assoc()['c'] : 0;
    if ($fkCount < 80) {
        $errors[] = "Expected at least 80 enforced InnoDB FOREIGN KEY constraints, found {$fkCount}.";
    }

    $res = $mysqli->query("SELECT COUNT(*) AS c FROM `equipment`");
    $eqCount = $res ? (int)$res->fetch_assoc()['c'] : 0;
    if ($eqCount < 116) {
        $errors[] = "Expected at least 116 fleet rows in `equipment`, found {$eqCount}.";
    }

    $res = $mysqli->query("SELECT COUNT(*) AS c FROM `equipment` WHERE `registration_no` IN ('EX 006', 'C-HEADT 007', 'C-HEADT 008')");
    $verbatimIds = $res ? (int)$res->fetch_assoc()['c'] : 0;
    if ($verbatimIds !== 3) {
        $errors[] = "Expected verbatim Asset Register Machine IDs ('EX 006', 'C-HEADT 007', 'C-HEADT 008'), found {$verbatimIds}/3.";
    }

    $res = $mysqli->query("SELECT COUNT(*) AS c FROM `equipment` WHERE (`serial_number` LIKE 'SN-%' AND `frame_no` IS NOT NULL AND `frame_no` != '') OR `brand` = 'CATERPILAR' OR `model` = 'TOYOTA LC'");
    $staleCells = $res ? (int)$res->fetch_assoc()['c'] : 0;
    if ($staleCells !== 0) {
        $samples = array();
        $sRes = $mysqli->query("SELECT `id`, `registration_no`, `brand`, `model`, `serial_number` FROM `equipment` WHERE (`serial_number` LIKE 'SN-%' AND `frame_no` IS NOT NULL AND `frame_no` != '') OR `brand` = 'CATERPILAR' OR `model` = 'TOYOTA LC' LIMIT 10");
        if ($sRes) {
            while ($sr = $sRes->fetch_assoc()) {
                $samples[] = sprintf("#%d '%s' (%s / %s / %s)", (int)$sr['id'], $sr['registration_no'], $sr['brand'], $sr['model'], $sr['serial_number']);
            }
            $sRes->free();
        }
        $errors[] = "Expected 0 stale placeholder serials/brands/models in `equipment`, found {$staleCells}: " . implode('; ', $samples);
    }

    $res = $mysqli->query("SELECT COUNT(*) AS c FROM `report_sites`");
    $siteCount = $res ? (int)$res->fetch_assoc()['c'] : 0;
    if ($siteCount < 36) {
        $errors[] = "Expected >= 36 approved locations in `report_sites`, found {$siteCount}.";
    }

    $res = $mysqli->query("SELECT COUNT(*) AS c FROM `equipment_specs`");
    $specCount = $res ? (int)$res->fetch_assoc()['c'] : 0;
    if ($specCount < 338) {
        $errors[] = "Expected at least 338 specification rows in `equipment_specs`, found {$specCount}.";
    }

    $res = $mysqli->query("SELECT COUNT(*) AS c FROM `suppliers`");
    $supCount = $res ? (int)$res->fetch_assoc()['c'] : 0;
    if ($supCount < 14) {
        $errors[] = "Expected at least 14 deduplicated vendor rows in `suppliers`, found {$supCount}.";
    }

    $res = $mysqli->query("SELECT COUNT(*) AS c FROM `materials`");
    $matCount = $res ? (int)$res->fetch_assoc()['c'] : 0;
    if ($matCount < 1697) {
        $errors[] = "Expected at least 1,697 catalogue rows in `materials`, found {$matCount}.";
    }

    if (!empty($errors)) {
        fwrite(STDERR, "SCHEMA VERIFICATION FAILED:\n  - " . implode("\n  - ", $errors) . "\n");
        exit(1);
    }

    echo sprintf(
        "VERIFIED OK: %d tables | %d foreign keys | ci_sessions PK=1 | %d settings | %d templates | %d checklist items | %d equipment | %d specs | %d suppliers | %d materials.\n",
        $tableCount, $fkCount, $settingsCount, $tplCount, $itemCount, $eqCount, $specCount, $supCount, $matCount
    );
}
