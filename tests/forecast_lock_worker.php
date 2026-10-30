<?php
/** Worker for forecast_lock_integration.py. Run only against a disposable DB. */
error_reporting(E_ALL & ~E_DEPRECATED);
define('BASEPATH', realpath(__DIR__ . '/../system') . '/');
define('APPPATH', realpath(__DIR__ . '/../application') . '/');
function log_message($level, $message) { if ($level === 'error') fwrite(STDERR, $message . "\n"); }
function is_php($version) { return version_compare(PHP_VERSION, $version, '>='); }
function config_item($key) { return null; }
function show_error($message) { throw new RuntimeException(is_array($message) ? implode(' ', $message) : $message); }
class CI_Model {
    public $db, $load;
    public function __construct() { $this->db = $GLOBALS['worker_db']; }
}
require BASEPATH . 'database/DB.php';
require APPPATH . 'helpers/kpi_helper.php';
require APPPATH . 'helpers/equip_helper.php';
require APPPATH . 'models/Forecast_model.php';
require APPPATH . 'models/Maintenance_plan_model.php';
require APPPATH . 'models/Backlog_model.php';

function signal_file($path) { if ($path) file_put_contents($path, '1'); }
function gate_after_operation($args) {
    if (empty($args['ready'])) return;
    signal_file($args['ready']);
    $until = microtime(true) + 60;
    while (!file_exists($args['release'])) {
        if (microtime(true) > $until) throw new RuntimeException('Timed out waiting for test release');
        usleep(25000);
    }
}
class InstrumentedPlanModel extends Maintenance_plan_model {
    public $args = array();
    public function plan_transaction(callable $operation) {
        return parent::plan_transaction(function () use ($operation) {
            signal_file(isset($this->args['entered']) ? $this->args['entered'] : null);
            $result = $operation();
            gate_after_operation($this->args);
            return $result;
        });
    }
}
class WorkerLoader {
    private $owner;
    public function __construct($owner) { $this->owner = $owner; }
    public function model($name) {
        if ($name === 'Backlog_model') $this->owner->Backlog_model = $GLOBALS['worker_backlog'];
        if ($name === 'Maintenance_plan_model') $this->owner->Maintenance_plan_model = $GLOBALS['worker_plan'];
    }
}
class MY_Controller {
    public $db, $load, $Backlog_model, $Maintenance_plan_model, $user_id = null;
    public function __construct() { $this->db = $GLOBALS['worker_db']; $this->load = new WorkerLoader($this); }
    protected function is_supervisor() { return true; }
}
require APPPATH . 'controllers/Backlog.php';

try {
    if (getenv('WMS_TEST_DESTRUCTIVE') !== '1') throw new RuntimeException('Set WMS_TEST_DESTRUCTIVE=1 only for a disposable test database.');
    $args = json_decode(isset($argv[1]) ? $argv[1] : '', true);
    if (!is_array($args) || empty($args['action'])) throw new RuntimeException('Expected a JSON action argument.');
    $GLOBALS['worker_db'] = DB(array(
        'dbdriver' => 'mysqli', 'hostname' => getenv('WMS_DB_HOST') ?: '127.0.0.1',
        'port' => (int)(getenv('WMS_DB_PORT') ?: 3306), 'username' => getenv('WMS_DB_USER') ?: 'root',
        'password' => getenv('WMS_DB_PASS') ?: '', 'database' => getenv('WMS_DB_NAME') ?: '',
        'char_set' => 'utf8mb4', 'dbcollat' => 'utf8mb4_unicode_ci', 'db_debug' => true, 'save_queries' => false,
    ), true);
    $db = $GLOBALS['worker_db'];
    $forecast = new Forecast_model();
    $plan = new InstrumentedPlanModel(); $plan->forecast = $forecast; $plan->args = $args;
    $GLOBALS['worker_plan'] = $plan;
    $GLOBALS['worker_backlog'] = new Backlog_model();
    if (!empty($args['lock_timeout'])) $db->query('SET SESSION innodb_lock_wait_timeout = ' . (int)$args['lock_timeout']);
    $action = $args['action'];
    $week = isset($args['week']) ? $args['week'] : null;
    $end = isset($args['end']) ? $args['end'] : null;
    $eid = isset($args['equipment_id']) ? (int)$args['equipment_id'] : 0;
    $result = null;

    if ($action === 'prepare') {
        $overlap = $db->query('SELECT COUNT(*) AS n FROM maintenance_plan_jobs WHERE start_date <= ? AND end_date >= ?', array($end, $week))->row()->n;
        $ledger = $db->query('SELECT COUNT(*) AS n FROM equipment_planned_downtime WHERE plan_date BETWEEN ? AND ?', array($week, $end))->row()->n;
        $snaps = $db->query('SELECT COUNT(*) AS n FROM forecast_snapshots WHERE week_start BETWEEN ? AND ?', array($week, $end))->row()->n;
        if ($overlap || $ledger || $snaps) throw new RuntimeException('Test date range is not empty; refusing to modify it.');
        $eq = $db->query("SELECT id, registration_no FROM equipment WHERE status NOT IN ('Retired','Standby') AND (include_in_weekly_report IS NULL OR include_in_weekly_report = 1) ORDER BY id LIMIT 1")->row();
        if (!$eq) throw new RuntimeException('No reportable equipment found.');
        $alternate = $db->query("SELECT id, registration_no FROM equipment WHERE id <> ? AND status NOT IN ('Retired','Standby') AND (include_in_weekly_report IS NULL OR include_in_weekly_report = 1) ORDER BY id LIMIT 1", array((int)$eq->id))->row();
        if (!$alternate) throw new RuntimeException('Two reportable equipment records are required for the linked-defect tests.');
        $result = array('equipment_id' => (int)$eq->id, 'asset' => $eq->registration_no,
            'alternate_id' => (int)$alternate->id, 'alternate_asset' => $alternate->registration_no);
    } elseif ($action === 'create_defect') {
        $ok = $db->insert('backlogs', array('equipment_id' => $eid, 'asset_label' => $args['asset'], 'start_date' => $week,
            'description' => $args['title'], 'source' => 'Other', 'status' => 'Open',
            'requested_date' => $week, 'requested_duration' => 60, 'schedule_state' => 'requested'));
        if (!$ok) throw new RuntimeException('Cannot create test defect.');
        $result = array('id' => (int)$db->insert_id());
    } elseif ($action === 'update_defect') {
        $ok = $db->where('id', (int)$args['defect_id'])->update('backlogs', array(
            'requested_date' => $week, 'requested_duration' => isset($args['minutes']) ? (int)$args['minutes'] : 120, 'schedule_state' => 'pending_approval'));
        if (!$ok) throw new RuntimeException('Cannot update test planning request.');
        $result = array('updated' => true);
    } elseif ($action === 'change_equipment') {
        // Mirror the controller's advisory read, then use its real current-read
        // helper after locking the defect. Barriers exercise both race orders.
        $before = $GLOBALS['worker_backlog']->active_plan((int)$args['defect_id']);
        signal_file(isset($args['attempt']) ? $args['attempt'] : null);
        if (!$db->trans_begin()) throw new RuntimeException('Could not start defect transaction.');
        $q = $db->query('SELECT * FROM backlogs WHERE id = ? FOR UPDATE', array((int)$args['defect_id']));
        if (!$q || !$q->row()) throw new RuntimeException('Test defect is missing.');
        signal_file(isset($args['entered']) ? $args['entered'] : null);
        $jobs = $GLOBALS['worker_backlog']->active_plans_locked((int)$args['defect_id']);
        if ($jobs === false) throw new RuntimeException('Could not check current jobs.');
        if ($jobs) {
            $db->trans_rollback();
            $result = array('error' => 'A plan now exists.', 'early_plan' => (bool)$before);
        } else {
            if (!$db->where('id', (int)$args['defect_id'])->update('backlogs', array('equipment_id' => $eid, 'asset_label' => $args['asset']))) throw new RuntimeException('Could not change test machine.');
            gate_after_operation($args);
            if (!$db->trans_commit()) throw new RuntimeException('Could not commit test machine change.');
            $result = array('updated' => true, 'early_plan' => (bool)$before);
        }
    } elseif ($action === 'change_defect_status') {
        $ok = $db->where('id', (int)$args['defect_id'])->update('backlogs', array('status' => $args['status']));
        if (!$ok) throw new RuntimeException('Cannot change test defect status.');
        $result = array('updated' => true);
    } elseif ($action === 'cleanup') {
        // Only the previously verified empty date range and explicitly created defect are removed.
        $db->query('DELETE FROM forecast_snapshots WHERE week_start BETWEEN ? AND ?', array($week, $end));
        $db->query('DELETE FROM maintenance_plan_jobs WHERE start_date <= ? AND end_date >= ?', array($end, $week));
        $db->query('DELETE FROM equipment_planned_downtime WHERE plan_date BETWEEN ? AND ?', array($week, $end));
        if (!empty($args['defect_id'])) {
            $db->query('DELETE FROM backlog_events WHERE backlog_id = ?', array((int)$args['defect_id']));
            $db->query('DELETE FROM backlogs WHERE id = ?', array((int)$args['defect_id']));
        }
        $result = array('cleaned' => true);
    } elseif ($action === 'lock_schema') {
        // Negative checks only: restore the disposable DB immediately afterwards.
        $mode = isset($args['mode']) ? $args['mode'] : '';
        if ($mode === 'remove') $ok = $db->query('DELETE FROM forecast_write_lock WHERE lock_id = 1');
        elseif ($mode === 'restore') $ok = $db->query('INSERT IGNORE INTO forecast_write_lock (lock_id) VALUES (1)');
        elseif ($mode === 'myisam') $ok = $db->query('ALTER TABLE forecast_write_lock ENGINE=MyISAM');
        elseif ($mode === 'innodb') $ok = $db->query('ALTER TABLE forecast_write_lock ENGINE=InnoDB');
        elseif ($mode === 'events_myisam') $ok = $db->query('ALTER TABLE backlog_events ENGINE=MyISAM');
        elseif ($mode === 'events_innodb') $ok = $db->query('ALTER TABLE backlog_events ENGINE=InnoDB');
        else throw new RuntimeException('Unknown lock-schema mode.');
        if (!$ok) throw new RuntimeException('Could not alter test lock schema.');
        $result = array('mode' => $mode);
    } elseif ($action === 'inspect') {
        $snapshots = $db->query('SELECT id, revision, is_baseline, payload, payload_checksum FROM forecast_snapshots WHERE week_start = ? ORDER BY revision', array($week))->result_array();
        foreach ($snapshots as &$snapshot) {
            $snapshot['checksum_valid'] = hash('sha256', $snapshot['payload']) === $snapshot['payload_checksum'];
            $payload = json_decode($snapshot['payload'], true);
            $snapshot['jobs'] = isset($payload['jobs']) ? $payload['jobs'] : array();
            $snapshot['machine'] = null;
            foreach ((array)($payload['rows'] ?? array()) as $r) if ((int)$r['equipment_id'] === $eid) $snapshot['machine'] = $r;
            unset($snapshot['payload']);
        }
        unset($snapshot);
        $jobs = $db->query('SELECT id, equipment_id, start_date, end_date, title, source, backlog_id, status FROM maintenance_plan_jobs WHERE start_date <= ? AND end_date >= ? ORDER BY id', array($forecast->week_end($week), $week))->result_array();
        $ledger = $db->query('SELECT equipment_id, plan_date, category, hours, source FROM equipment_planned_downtime WHERE plan_date BETWEEN ? AND ? ORDER BY equipment_id, plan_date, category', array($week, $forecast->week_end($week)))->result_array();
        $defect = !empty($args['defect_id']) ? $db->where('id', (int)$args['defect_id'])->get('backlogs')->row_array() : null;
        $result = compact('snapshots', 'jobs', 'ledger', 'defect');
    } elseif ($action === 'unguarded') {
        $input = array('equipment_id' => $eid, 'start_date' => $week, 'end_date' => $week,
            'hours_per_day' => 2, 'job_type' => 'Repair', 'category' => 'Corrective', 'title' => 'unguarded');
        $result = array('job' => $plan->save_job($input), 'publish' => $forecast->publish($week),
            'cell' => $forecast->set_planned($eid, $week, 'Corrective', 2),
            'pm' => $forecast->generate_from_pm($week), 'standing' => $forecast->generate_standing_allowance($week),
            'unplanned' => $forecast->generate_unplanned_allowance($week), 'sync' => $plan->sync_week($week),
            'inspections' => $plan->generate_inspections($week), 'status' => $plan->sync_inspection_status($week));
    } elseif ($action === 'nested') {
        $db->trans_begin();
        $inner = $forecast->plan_transaction(function () { return array('unexpected' => true); });
        $stillActive = $db->trans_active();
        $db->trans_rollback();
        $result = array('inner' => $inner, 'outer_still_active' => $stillActive);
    } elseif ($action === 'backlog') {
        signal_file(isset($args['attempt']) ? $args['attempt'] : null);
        $controller = new Backlog();
        $method = new ReflectionMethod('Backlog', '_apply_plan');
        $method->setAccessible(true);
        $message = $method->invoke($controller, (int)$args['defect_id'], $eid, $args['title'], $week, 60, false);
        $result = array('message' => $message);
    } else {
        signal_file(isset($args['attempt']) ? $args['attempt'] : null);
        $result = $forecast->plan_transaction(function () use ($action, $args, $week, $eid, $forecast, $plan) {
            signal_file(isset($args['entered']) ? $args['entered'] : null);
            if ($action === 'publish') {
                $out = $forecast->publish($week, null, 'Test acknowledgement');
                if (isset($out['payload'])) unset($out['payload']);
            } elseif ($action === 'save') {
                $input = array('equipment_id' => $eid, 'start_date' => $week,
                    'end_date' => isset($args['job_end']) ? $args['job_end'] : $week,
                    'hours_per_day' => 2, 'job_type' => 'Repair', 'category' => 'Corrective',
                    'title' => $args['title'], 'status' => $args['status'] ?? 'Planned');
                if (isset($args['source'])) $input['source'] = $args['source'];
                if (isset($args['defect_id'])) $input['backlog_id'] = (int)$args['defect_id'];
                $out = $plan->save_job($input, null, isset($args['job_id']) ? (int)$args['job_id'] : null);
            } elseif ($action === 'delete') {
                $out = $plan->delete_job((int)$args['job_id']);
            } elseif ($action === 'cell') {
                if ($forecast->baseline_snapshot($week)) $out = array('error' => 'The week is frozen.');
                else {
                    $id = $forecast->set_planned($eid, $week, 'Corrective', 3, Forecast_model::SRC_MANUAL, 'test override');
                    $out = $id ? array('id' => $id) : array('error' => 'Cell was not saved.');
                }
            } elseif ($action === 'standing') {
                $out = $forecast->generate_standing_allowance($week);
            } elseif ($action === 'inspections') {
                $out = $plan->generate_inspections($week);
            } elseif ($action === 'status') {
                $out = $plan->sync_inspection_status($week);
            } else throw new RuntimeException('Unknown action: ' . $action);
            gate_after_operation($args);
            return !empty($args['rollback']) ? array('error' => 'Simulated rollback') : $out;
        });
    }
    echo json_encode(array('result' => $result, 'transaction_active' => $db->trans_active(), 'debug_restored' => $db->db_debug), JSON_UNESCAPED_SLASHES) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
}
