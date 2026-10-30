<?php
/** Focused controller/model regressions using in-memory fakes; no MySQL required.
 * Run separately: php tests/backlog_workflow.php. Database transactions and
 * concurrent requests must also be exercised against real MySQL/MariaDB. */
error_reporting(E_ALL & ~E_DEPRECATED);
define('BASEPATH', __DIR__ . '/../system/');
class CI_Model { public $db; public function __construct() {} }
class StopRedirect extends Exception {}
function redirect($where) { throw new StopRedirect((string)$where); }
function show_404() { throw new RuntimeException('404'); }
function log_message($level, $message) {}

class TestResult {
    private $rows;
    public function __construct($rows) { $this->rows = array_values($rows); }
    public function result() { return array_map(function ($r) { return (object)$r; }, $this->rows); }
    public function row() { return $this->rows ? (object)$this->rows[0] : NULL; }
}
class TestDB {
    public $db_debug = TRUE, $blockedField = NULL, $blockedTable = NULL, $indexUnique = TRUE, $throwInsert = NULL, $indexEntries = NULL, $woLockStatus = NULL, $backlogLockEquipment = NULL;
    public $tables, $ready = TRUE, $where = array(), $whereIn = array(), $from = '', $affected = 0, $lastId = 0, $snap = NULL;
    public function __construct() {
        $this->tables = array(
            'equipment' => array(1 => array('id' => 1, 'registration_no' => 'EX 006', 'legacy_code' => 'C-HE-EX 006'),
                                 2 => array('id' => 2, 'registration_no' => 'DZ 003', 'legacy_code' => NULL)),
            'work_orders' => array(10 => array('id' => 10, 'wo_number' => 'WO-10', 'title' => 'Repair', 'equipment_id' => 2, 'status' => 'Completed')),
            'backlogs' => array(), 'backlog_events' => array(), 'maintenance_plan_jobs' => array(), 'wo_sessions' => array());
    }
    public function table_exists($table) { return $table !== $this->blockedTable && array_key_exists($table, $this->tables) && ($table !== 'backlog_events' || $this->ready); }
    public function field_exists($field, $table) { return $field !== $this->blockedField && ($this->ready || !in_array($field, array('requested_date', 'submit_token'), TRUE)); }
    public function dbprefix($name) { return $name; }
    public function select($s, $escape = NULL) { return $this; }
    public function from($s) { $this->from = $s; return $this; }
    public function order_by($a, $b = NULL) { return $this; }
    public function limit($n) { return $this; }
    public function where($key, $value = NULL, $escape = NULL) { $this->where[$key] = $value; return $this; }
    public function where_in($key, $value) { $this->whereIn[$key] = $value; return $this; }
    private function matches($row) {
        foreach ($this->where as $k => $v) {
            if (substr($k, -3) === ' !=') { $k = substr($k, 0, -3); if ((string)($row[$k] ?? '') === (string)$v) return FALSE; }
            elseif ((string)($row[$k] ?? '') !== (string)$v) return FALSE;
        }
        foreach ($this->whereIn as $k => $v) if (!in_array($row[$k] ?? NULL, $v, TRUE)) return FALSE;
        return TRUE;
    }
    private function reset() { $this->where = array(); $this->whereIn = array(); $this->from = ''; }
    public function get($table = NULL) {
        $table = $table ?: $this->from; $rows = array();
        foreach ($this->tables[$table] ?? array() as $row) if ($this->matches($row)) $rows[] = $row;
        $this->reset(); return new TestResult($rows);
    }
    public function update($table, $data) {
        $this->affected = 0;
        foreach ($this->tables[$table] as $id => $row) if ($this->matches($row)) {
            $this->tables[$table][$id] = array_merge($row, $data); $this->affected++;
        }
        $this->reset(); return TRUE;
    }
    public function insert($table, $data) {
        if ($table === $this->throwInsert) throw new RuntimeException('Simulated write failure');
        $id = $this->tables[$table] ? max(array_keys($this->tables[$table])) + 1 : 1;
        $data['id'] = $id; $this->tables[$table][$id] = $data; $this->lastId = $id; return TRUE;
    }
    public function insert_id() { return $this->lastId; }
    public function affected_rows() { return $this->affected; }
    public function query($sql, $params = array()) {
        if (strpos($sql, 'SHOW INDEX') === 0) {
            if ($this->indexEntries !== NULL) return new TestResult($this->indexEntries);
            return new TestResult($this->ready && $this->indexUnique ? array(array('Key_name'=>'uq_backlog_submit_token',
                'Non_unique'=>0, 'Column_name'=>'submit_token', 'Seq_in_index'=>1, 'Sub_part'=>NULL)) : array());
        }
        if (strpos($sql, 'maintenance_plan_jobs') !== FALSE) {
            $rows = array();
            foreach ($this->tables['maintenance_plan_jobs'] as $job) {
                if ((int)$job['backlog_id'] === (int)$params[0] && in_array($job['status'], array('Planned','Issued'), TRUE)) $rows[] = $job;
            }
            return new TestResult(array_reverse($rows));
        }
        if (strpos($sql, 'backlogs') !== FALSE) {
            $row = $this->tables['backlogs'][(int)$params[0]] ?? NULL;
            if ($row && $this->backlogLockEquipment !== NULL && strpos($sql, 'FOR UPDATE') !== FALSE) $row['equipment_id'] = $this->backlogLockEquipment;
            return new TestResult($row ? array($row) : array());
        }
        if (strpos($sql, 'wo_sessions') !== FALSE) return new TestResult(isset($this->tables['wo_sessions'][(int)$params[0]]) ? array($this->tables['wo_sessions'][(int)$params[0]]) : array());
        if (strpos($sql, 'work_orders') !== FALSE) {
            $row = $this->tables['work_orders'][(int)$params[0]] ?? NULL;
            if ($row && $this->woLockStatus !== NULL && strpos($sql, 'FOR UPDATE') !== FALSE) $row['status'] = $this->woLockStatus;
            return new TestResult($row ? array($row) : array());
        }
        return new TestResult(array());
    }
    public function trans_begin() { $this->snap = unserialize(serialize($this->tables)); return TRUE; }
    public function trans_status() { return TRUE; }
    public function trans_commit() { $this->snap = NULL; return TRUE; }
    public function trans_rollback() { if ($this->snap !== NULL) $this->tables = $this->snap; $this->snap = NULL; return TRUE; }
}
class TestInput {
    public $values = array(), $getValues = array();
    public function post($key = NULL, $clean = FALSE) { return $key === NULL ? $this->values : ($this->values[$key] ?? NULL); }
    public function get($key = NULL, $clean = FALSE) { return $key === NULL ? $this->getValues : ($this->getValues[$key] ?? NULL); }
}
class TestSession {
    public $messages = array();
    public function set_flashdata($key, $value) { $this->messages[$key] = $value; }
    public function flashdata($key) { return $this->messages[$key] ?? NULL; }
}
class TestBacklogModel {
    public $db, $overview = NULL, $lateJob = NULL, $failPlanCheck = FALSE;
    public function __construct($db) { $this->db = $db; }
    public function workflow_ready() { return $this->db->ready; }
    public function get_register_overview() { return $this->overview; }
    public function resolve_equipment($asset) { foreach ($this->db->tables['equipment'] as $e) if ($e['registration_no'] === $asset) return $e['id']; return NULL; }
    public function by_submit_token($token) { foreach ($this->db->tables['backlogs'] as $r) if (($r['submit_token'] ?? NULL) === $token) return (object)$r; return NULL; }
    public function get_by_id($id) { return isset($this->db->tables['backlogs'][$id]) ? (object)$this->db->tables['backlogs'][$id] : NULL; }
    public function insert($data) { $this->db->insert('backlogs', $data); return $this->db->insert_id(); }
    public function update($id, $data) { return $this->db->where('id', $id)->update('backlogs', $data); }
    public function record_event($id, $actor, $type, $details) { return $this->db->insert('backlog_events', array('backlog_id' => $id, 'actor_id' => $actor, 'event_type' => $type, 'details' => $details)); }
    public function active_plans($id) { $out = array(); foreach ($this->db->tables['maintenance_plan_jobs'] as $r) if ((int)$r['backlog_id'] === (int)$id && in_array($r['status'], array('Planned','Issued'), TRUE)) $out[] = (object)$r; return array_reverse($out); }
    public function active_plan($id) { $r = $this->active_plans($id); return $r ? $r[0] : NULL; }
    public function active_plans_locked($id) {
        if ($this->failPlanCheck) return FALSE;
        if ($this->lateJob) { $this->db->tables['maintenance_plan_jobs'][$this->lateJob['id']] = $this->lateJob; $this->lateJob = NULL; }
        return $this->active_plans($id);
    }
}
class TestPlanModel {
    public $db; public $fail = FALSE; public $manual = FALSE; public $throw = FALSE; public $lockFail = FALSE;
    public function __construct($db) { $this->db = $db; }
    public function plan_transaction(callable $op) {
        if (!$this->db->trans_begin()) return array('error' => 'The plan change could not be started.');
        try {
            if ($this->lockFail) $out = array('error' => 'The plan is busy or its lock is unavailable.');
            else $out = $op();
            if (!empty($out['error']) || !empty($out['errors'])) $this->db->trans_rollback();
            else $this->db->trans_commit();
            return $out;
        } catch (Throwable $e) {
            $this->db->trans_rollback();
            return array('error' => 'The plan change could not be confirmed.');
        }
    }
    public function save_job($data, $actor, $id = NULL) {
        if ($id) $this->db->tables['maintenance_plan_jobs'][$id] = array_merge($this->db->tables['maintenance_plan_jobs'][$id], $data);
        else { $this->db->insert('maintenance_plan_jobs', $data); $id = $this->db->insert_id(); }
        if ($this->throw) throw new RuntimeException('Simulated plan failure');
        if ($this->fail) return array('errors' => array('The week is published and frozen.'));
        return array('id' => $id, 'synced' => array(array('manual_kept' => $this->manual ? 1 : 0)));
    }
    public function delete_job($id, $actor) { unset($this->db->tables['maintenance_plan_jobs'][$id]); return $this->fail ? array('errors' => array('Frozen week.')) : array('deleted' => $id, 'synced' => array()); }
}
class TestLoader {
    private $ctrl;
    public function __construct($ctrl) { $this->ctrl = $ctrl; }
    public function model($name) {
        if ($name === 'Backlog_model') {
            if ($this->ctrl instanceof Workorders) { $this->ctrl->Backlog_model = new Backlog_model(); $this->ctrl->Backlog_model->db = $this->ctrl->db; }
            else $this->ctrl->Backlog_model = new TestBacklogModel($this->ctrl->db);
        }
        if ($name === 'Maintenance_plan_model') $this->ctrl->Maintenance_plan_model = $GLOBALS['testPlan'];
    }
}
class MY_Controller {
    public $db, $input, $session, $load, $Backlog_model, $Maintenance_plan_model, $user_id = 17, $supervisor = FALSE, $rendered = NULL;
    public function __construct() { $this->db = $GLOBALS['testDB']; $this->input = $GLOBALS['testInput']; $this->session = $GLOBALS['testSession']; $this->load = new TestLoader($this); }
    protected function can_write() { return TRUE; }
    protected function is_supervisor() { return $this->supervisor; }
    protected function require_post() { return TRUE; }
    protected function render($view, $data) { $this->rendered = $data; }
}
require __DIR__ . '/../application/models/Backlog_model.php';
require __DIR__ . '/../application/models/Inspection_model.php';
require __DIR__ . '/../application/controllers/Backlog.php';
require __DIR__ . '/../application/controllers/Workorders.php';

$passed = 0; $failed = array();
function expect($name, $ok) { global $passed, $failed; if ($ok) $passed++; else $failed[] = $name; }
function reset_fixture($sup = FALSE) {
    $GLOBALS['testDB'] = new TestDB(); $GLOBALS['testInput'] = new TestInput(); $GLOBALS['testSession'] = new TestSession();
    $GLOBALS['testPlan'] = new TestPlanModel($GLOBALS['testDB']);
    $c = new Backlog(); $c->supervisor = $sup; return $c;
}
function submit($c, $data, $id = NULL) {
    $c->input->values = $data;
    try { if ($id === NULL) $c->create(); else $c->edit($id); } catch (StopRedirect $e) { return $e->getMessage(); }
    return NULL;
}
function defect($more = array()) {
    return array_merge(array('equipment_id' => '1', 'asset_label' => 'EX 006', 'start_date' => '2020-01-01',
        'description' => 'Hose damaged', 'source' => 'Other', 'priority' => 'P2', 'status' => 'Open',
        'submit_token' => str_repeat('a', 32)), $more);
}
function row1() { return $GLOBALS['testDB']->tables['backlogs'][1] ?? array(); }

$c = reset_fixture(); submit($c, defect(array('requested_date' => '2030-02-03', 'requested_duration' => '60', 'plan_action' => 'schedule')));
expect('technician proposal saves defect', count($c->db->tables['backlogs']) === 1);
expect('technician cannot directly schedule', count($c->db->tables['maintenance_plan_jobs']) === 0 && (row1()['schedule_state'] ?? '') === 'pending_approval');
expect('manual reporter is authenticated actor', (row1()['created_by'] ?? 0) === 17);
$c = reset_fixture(TRUE); submit($c, defect());
$version = Backlog_model::edit_version((object)row1());
submit($c, defect(array('description'=>'Current edit', 'record_version'=>$version)), 1);
expect('current edit version saves successfully', (row1()['description'] ?? '') === 'Current edit');
$c = reset_fixture(TRUE); submit($c, defect());
$version = Backlog_model::edit_version((object)row1());
$c->db->tables['backlogs'][1]['description'] = 'Updated by another user';
submit($c, defect(array('description'=>'Old form', 'record_version'=>$version)), 1);
expect('stale edit form cannot overwrite another users update', (row1()['description'] ?? '') === 'Updated by another user' && isset($c->rendered['errors']['record_version']));
expect('stale version stays on the rejected form until refresh', ($c->rendered['record_version'] ?? '') === $version);

$c = reset_fixture();
submit($c, defect(array('end_date'=>'2040-01-01', 'closing_remarks'=>'Claimed complete', 'end_odometer'=>'120', 'mechanic'=>'Someone', 'supervisor'=>'Someone else')));
expect('hidden completion inputs do not create evidence on an open defect', count($c->db->tables['backlogs']) === 1 && empty(row1()['end_date']) && empty(row1()['closing_remarks']) && empty(row1()['end_odometer']) && empty(row1()['mechanic']) && empty(row1()['supervisor']));
$c = reset_fixture(TRUE); submit($c, defect());
$c->db->tables['backlogs'][1]['end_date'] = '2019-01-01'; $c->db->tables['backlogs'][1]['closing_remarks'] = 'Legacy note';
submit($c, defect(array('end_date'=>'2040-01-01', 'closing_remarks'=>'Replacement')), 1);
expect('editing an open legacy row preserves existing completion data without accepting hidden replacements', row1()['end_date'] === '2019-01-01' && row1()['closing_remarks'] === 'Legacy note');


$c = reset_fixture(); submit($c, defect(array('requested_date' => '2030-02-03', 'requested_duration' => '0')));
expect('invalid plan does not reject defect', count($c->db->tables['backlogs']) === 1 && (row1()['schedule_state'] ?? '') === 'needs_info');
expect('invalid duration is not persisted as schedule', (row1()['requested_duration'] ?? NULL) === NULL && !$c->db->tables['maintenance_plan_jobs']);

$c = reset_fixture(TRUE); $form = defect(array('requested_date' => '2030-02-03', 'requested_duration' => '60', 'plan_action' => 'schedule'));
submit($c, $form); submit($c, $form);
expect('supervisor plan creates one job', count($c->db->tables['maintenance_plan_jobs']) === 1 && (row1()['schedule_state'] ?? '') === 'planned');
expect('idempotent token prevents duplicate defect', count($c->db->tables['backlogs']) === 1);

$c = reset_fixture(TRUE); $c->Maintenance_plan_model = $GLOBALS['testPlan']; $GLOBALS['testPlan']->fail = TRUE;
submit($c, defect(array('requested_date' => '2030-02-03', 'requested_duration' => '60', 'plan_action' => 'schedule')));
expect('failed plan preserves defect', count($c->db->tables['backlogs']) === 1 && (row1()['schedule_state'] ?? '') === 'failed');
expect('failed job write rolls back', !$c->db->tables['maintenance_plan_jobs'] && strpos($c->session->messages['error'] ?? '', 'plan was not changed') !== FALSE);
$c = reset_fixture(TRUE); $GLOBALS['testPlan']->lockFail = TRUE;
submit($c, defect(array('requested_date'=>'2030-02-03', 'requested_duration'=>'60', 'plan_action'=>'schedule')));
expect('an unavailable plan lock leaves the saved defect unscheduled', count($c->db->tables['backlogs']) === 1 && !$c->db->tables['maintenance_plan_jobs'] && (row1()['schedule_state'] ?? '') === 'failed');
expect('an unavailable plan lock is reported for retry', strpos($c->session->messages['error'] ?? '', 'lock is unavailable') !== FALSE);

$c = reset_fixture(TRUE); $GLOBALS['testPlan']->throw = TRUE;
submit($c, defect(array('requested_date'=>'2030-02-03', 'requested_duration'=>'60', 'plan_action'=>'schedule')));
expect('unexpected planning error preserves the saved defect and rolls back job', count($c->db->tables['backlogs']) === 1 && !$c->db->tables['maintenance_plan_jobs'] && strpos($c->session->messages['error'] ?? '', 'could not be confirmed') !== FALSE);
expect('database debug mode is restored after plan failure', $c->db->db_debug === TRUE);
$c = reset_fixture(TRUE); $c->db->throwInsert = 'backlogs'; submit($c, defect());
expect('unexpected defect write error retains the form and restores debug mode', !$c->db->tables['backlogs'] && $c->rendered !== NULL && $c->db->db_debug === TRUE);

$c = reset_fixture(TRUE); submit($c, defect());
$c->db->tables['maintenance_plan_jobs'][7] = array('id' => 7, 'backlog_id' => 1, 'equipment_id' => 1, 'start_date' => '2030-01-01', 'end_date' => '2030-01-01', 'hours_per_day' => 1, 'status' => 'Planned');
$GLOBALS['testPlan']->fail = TRUE;
submit($c, defect(array('requested_date' => '2030-02-03', 'requested_duration' => '90', 'plan_action' => 'schedule')), 1);
expect('failed reschedule preserves old plan', $c->db->tables['maintenance_plan_jobs'][7]['start_date'] === '2030-01-01');
expect('failed reschedule is visible', (row1()['schedule_state'] ?? '') === 'failed');

$c = reset_fixture(TRUE); submit($c, defect());
$c->db->tables['maintenance_plan_jobs'][7] = array('id'=>7, 'backlog_id'=>1, 'equipment_id'=>1, 'start_date'=>'2030-01-01', 'end_date'=>'2030-01-01', 'hours_per_day'=>1, 'status'=>'Issued', 'source'=>'FromBacklog');
submit($c, defect(array('requested_date'=>'2030-02-03', 'requested_duration'=>'60', 'plan_action'=>'schedule')), 1);
expect('issued job cannot be silently downgraded', $c->db->tables['maintenance_plan_jobs'][7]['status'] === 'Issued' && (row1()['schedule_state'] ?? '') === 'failed');
$c = reset_fixture(TRUE); submit($c, defect());
$c->db->tables['maintenance_plan_jobs'][7] = array('id'=>7, 'backlog_id'=>1, 'equipment_id'=>1, 'start_date'=>'2030-01-01', 'end_date'=>'2030-01-01', 'hours_per_day'=>1, 'status'=>'Planned', 'source'=>'Manual');
submit($c, defect(array('requested_date'=>'2030-02-03', 'requested_duration'=>'60', 'plan_action'=>'schedule')), 1);
expect('externally created job is not overwritten', $c->db->tables['maintenance_plan_jobs'][7]['start_date'] === '2030-01-01' && $c->db->tables['maintenance_plan_jobs'][7]['source'] === 'Manual' && (row1()['schedule_state'] ?? '') === 'failed');
$c = reset_fixture(TRUE); submit($c, defect());
$c->db->tables['maintenance_plan_jobs'][7] = array('id'=>7, 'backlog_id'=>1, 'equipment_id'=>1, 'start_date'=>'2030-01-01', 'end_date'=>'2030-01-01', 'hours_per_day'=>1, 'status'=>'Planned', 'source'=>'FromBacklog');
submit($c, defect(array('equipment_id'=>'2', 'asset_label'=>'DZ 003')), 1);
expect('machine cannot change underneath an active plan', (int)row1()['equipment_id'] === 1 && isset($c->rendered['errors']['equipment_id']));

$c = reset_fixture(TRUE); submit($c, defect());
$c->Backlog_model->lateJob = array('id'=>8, 'backlog_id'=>1, 'equipment_id'=>1, 'start_date'=>'2090-01-01', 'end_date'=>'2090-01-01', 'hours_per_day'=>1, 'status'=>'Planned', 'source'=>'FromBacklog');
submit($c, defect(array('equipment_id'=>'2', 'asset_label'=>'DZ 003')), 1);
expect('locked recheck refuses a newly activated plan before a machine change', (int)row1()['equipment_id'] === 1 && strpos($c->rendered['errors']['equipment_id'] ?? '', 'now exists') !== FALSE);
$c = reset_fixture(TRUE); submit($c, defect());
$c->Backlog_model->lateJob = array('id'=>8, 'backlog_id'=>1, 'equipment_id'=>1, 'start_date'=>'2090-01-01', 'end_date'=>'2090-01-01', 'hours_per_day'=>1, 'status'=>'Planned', 'source'=>'FromBacklog');
submit($c, defect(array('status'=>'Closed', 'end_date'=>'2020-02-01', 'closing_remarks'=>'Repaired', 'verified_now'=>'1', 'verification_notes'=>'Checked')), 1);
expect('locked recheck marks a concurrently activated future plan for review on closure', (row1()['status'] ?? '') === 'Closed' && (row1()['schedule_state'] ?? '') === 'review_required');
$c = reset_fixture(TRUE); submit($c, defect()); $c->Backlog_model->failPlanCheck = TRUE;
submit($c, defect(array('equipment_id'=>'2', 'asset_label'=>'DZ 003')), 1);
expect('failed plan check retains a machine-change form without saving', (int)row1()['equipment_id'] === 1 && isset($c->rendered['errors']['equipment_id']));

$c = reset_fixture(TRUE); submit($c, defect(array('asset_label' => 'DZ 003')));
expect('mismatched selected and typed asset rejected', !$c->db->tables['backlogs'] && isset($c->rendered['errors']['asset_label']));
$c = reset_fixture(TRUE); submit($c, defect(array('work_order_id' => '10')));
expect('work order for another machine rejected', !$c->db->tables['backlogs'] && isset($c->rendered['errors']['work_order_id']));
$c = reset_fixture(); submit($c, defect(array('status' => 'Closed')));
expect('technician self-closure rejected', !$c->db->tables['backlogs'] && strpos($c->rendered['errors']['status'] ?? '', 'supervisor') !== FALSE);
$c = reset_fixture(); submit($c, defect(array('status' => 'Cancelled', 'cancellation_reason' => 'duplicate')));
expect('technician cancellation rejected', !$c->db->tables['backlogs'] && strpos($c->rendered['errors']['status'] ?? '', 'supervisor') !== FALSE);

$c = reset_fixture(TRUE); submit($c, defect());
try { $c->delete(1); } catch (StopRedirect $e) {}
expect('hard delete cannot bypass audited cancellation', count($c->db->tables['backlogs']) === 1 && strpos($c->session->messages['error'] ?? '', 'cannot be deleted') !== FALSE);

$c = reset_fixture(TRUE); submit($c, defect());
submit($c, defect(array('status'=>'Cancelled', 'cancellation_reason'=>'Duplicate record')), 1);
expect('supervisor cancellation is reasoned and audited', (row1()['status'] ?? '') === 'Cancelled' && (row1()['cancellation_reason'] ?? '') === 'Duplicate record' && count($c->db->tables['backlog_events']) === 2);
expect('cancellation identifies its current reviewer', (row1()['reviewed_by'] ?? 0) === 17 && !empty(row1()['reviewed_at']));
submit($c, defect(array('status'=>'Cancelled', 'change_reason'=>'Correction', 'cancellation_reason'=>'')), 1);
expect('cancellation reason cannot be erased by a correction', (row1()['cancellation_reason'] ?? '') === 'Duplicate record' && isset($c->rendered['errors']['cancellation_reason']));
submit($c, defect(array('status'=>'Cancelled', 'change_reason'=>'Description correction')), 1);
expect('omitted cancellation reason is preserved on a correction', (row1()['cancellation_reason'] ?? '') === 'Duplicate record');
submit($c, defect(array('status'=>'Open', 'change_reason'=>'Reopened for review')), 1);
expect('reopening clears current cancellation reviewer but keeps the audit events', empty(row1()['reviewed_by']) && empty(row1()['cancellation_reason']) && count($c->db->tables['backlog_events']) >= 3);
submit($c, defect(array('status'=>'Cancelled', 'cancellation_reason'=>'Duplicate record')), 1);


$c->supervisor = FALSE; submit($c, defect(array('status'=>'Open')), 1);
expect('technician cannot reopen cancelled record', (row1()['status'] ?? '') === 'Cancelled');

$c = reset_fixture(TRUE); $c->db->tables['work_orders'][10]['equipment_id'] = 1;
$c->db->tables['work_orders'][11] = array('id'=>11, 'equipment_id'=>1, 'status'=>'Open', 'wo_number'=>'WO-11', 'title'=>'Other');
submit($c, defect(array('work_order_id'=>'10')));
submit($c, defect(array('work_order_id'=>'11')), 1);
expect('changing existing WO needs reason', (int)row1()['work_order_id'] === 10 && isset($c->rendered['errors']['link_change_reason']));
submit($c, defect(array('work_order_id'=>'11', 'link_change_reason'=>'Corrected order')), 1);
$lastEvent = end($c->db->tables['backlog_events']);
expect('reasoned WO link correction is audited', (int)row1()['work_order_id'] === 11 && ($lastEvent['details']['link_change_reason'] ?? '') === 'Corrected order');

$c = reset_fixture(TRUE); $c->db->tables['work_orders'][10]['equipment_id'] = 1; submit($c, defect(array('work_order_id'=>'10')));
submit($c, defect(array('work_order_id'=>'', 'status'=>'Closed', 'end_date'=>'2020-02-01', 'closing_remarks'=>'Repaired', 'verified_now'=>'1', 'verification_notes'=>'Checked', 'link_change_reason'=>'Wrong order')), 1);
expect('WO unlink and closure cannot bypass order review', (row1()['status'] ?? '') === 'Open' && isset($c->rendered['errors']['work_order_id']));

$c = reset_fixture(TRUE); submit($c, defect(array('status' => 'Closed', 'historical' => '1', 'completion_source' => 'Job card 123', 'closing_remarks' => 'Hose replaced')));
expect('historical defect accepted with source and work', count($c->db->tables['backlogs']) === 1 && (row1()['closure_kind'] ?? '') === 'historical');
expect('historical completion and verification remain unknown', (row1()['end_date'] ?? NULL) === NULL && (row1()['verified_by'] ?? NULL) === NULL && !empty(row1()['reviewed_at']));
$c = reset_fixture(TRUE); submit($c, defect(array('status'=>'Closed', 'historical'=>'1', 'completion_source'=>'Job card',
    'closing_remarks'=>'Repaired', 'verified_now'=>'1', 'verification_notes'=>'Checked in person')));
expect('historical record verified now retains both provenance and present checks', (row1()['closure_kind'] ?? '') === 'historical_verified' && (row1()['verified_by'] ?? 0) === 17 && (row1()['completion_source'] ?? '') === 'Job card');

$c = reset_fixture(TRUE); submit($c, defect(array('status' => 'Closed', 'historical' => '1', 'closing_remarks' => 'Repaired')));
expect('historical closure requires source', !$c->db->tables['backlogs'] && isset($c->rendered['errors']['completion_source']));
$c = reset_fixture(TRUE); submit($c, defect(array('status' => 'Closed', 'historical' => '1', 'completion_source' => 'Job card', 'closing_remarks' => 'Repaired', 'start_date' => '2099-01-01')));
expect('closed record cannot start in future', !$c->db->tables['backlogs'] && isset($c->rendered['errors']['start_date']));
$c = reset_fixture(TRUE); submit($c, defect(array('status' => 'Closed', 'end_date' => '2020-01-02', 'closing_remarks' => 'Repaired')));
expect('current closure requires personal verification', !$c->db->tables['backlogs'] && isset($c->rendered['errors']['verified_now']));

$c = reset_fixture(); $c->db->ready = FALSE; submit($c, defect(array('requested_date' => '2030-02-03', 'requested_duration' => '60')));
expect('unmigrated database still accepts open reports', count($c->db->tables['backlogs']) === 1 && strpos(row1()['observation'], 'not scheduled') !== FALSE);
$c = reset_fixture(TRUE); $c->db->ready = FALSE; submit($c, defect(array('status' => 'Closed', 'historical' => '1', 'completion_source' => 'Job card', 'closing_remarks' => 'Repaired')));
expect('unmigrated database refuses unaudited closure', !$c->db->tables['backlogs'] && isset($c->rendered['errors']['status']));
$db = new TestDB(); $model = new Backlog_model(); $model->db = $db;
expect('complete migration is ready', $model->workflow_ready());
$db->blockedField = 'verification_notes'; expect('missing evidence field is not ready', !$model->workflow_ready());
$db->blockedField = NULL; $db->blockedTable = 'backlog_events'; expect('missing event table is not ready', !$model->workflow_ready());
$db->blockedTable = NULL; $db->indexUnique = FALSE; expect('missing unique token index is not ready', !$model->workflow_ready());
$db->indexEntries = array(array('Non_unique'=>0, 'Column_name'=>'other', 'Seq_in_index'=>1, 'Sub_part'=>NULL));
expect('index name on wrong column is not ready', !$model->workflow_ready());
$db->indexEntries = array(array('Non_unique'=>0, 'Column_name'=>'submit_token', 'Seq_in_index'=>1, 'Sub_part'=>NULL),
    array('Non_unique'=>0, 'Column_name'=>'id', 'Seq_in_index'=>2, 'Sub_part'=>NULL));
expect('composite index cannot guarantee submission idempotency', !$model->workflow_ready());

// Exercise the REAL model's selected-work-order closure, including an
// unselected defect and an unknown historical completion date.
$db = new TestDB(); $db->tables['backlogs'] = array(
  1 => array('id' => 1, 'work_order_id' => 10, 'equipment_id' => 1, 'status' => 'Open', 'start_date' => '2020-01-01'),
  2 => array('id' => 2, 'work_order_id' => 10, 'equipment_id' => 1, 'status' => 'Open', 'start_date' => '2020-01-01'));
$model = new Backlog_model(); $model->db = $db;
$wo = (object)array('id' => 10, 'wo_number' => 'WO-10', 'equipment_id' => 1, 'end_datetime' => NULL, 'action_taken' => 'Hose replaced');
$result = $model->close_selected_for_work_order($wo, array(1), 17, 'Pressure tested');
expect('WO verifies selected defect only', ($result['closed'] ?? 0) === 1 && $db->tables['backlogs'][1]['status'] === 'Closed' && $db->tables['backlogs'][2]['status'] === 'Open');
expect('WO unknown completion is not fabricated', $db->tables['backlogs'][1]['end_date'] === NULL && $db->tables['backlogs'][1]['verified_by'] === 17);
$result = $model->close_selected_for_work_order($wo, array(2), 17, '');
expect('WO verification needs checks', !empty($result['error']) && $db->tables['backlogs'][2]['status'] === 'Open');
$db->tables['backlogs'][3] = array('id'=>3, 'work_order_id'=>10, 'equipment_id'=>NULL, 'status'=>'Open', 'start_date'=>'2020-01-01');
$wo->equipment_id = NULL;
$result = $model->close_selected_for_work_order($wo, array(3), 17, 'Checked');
expect('two missing machine IDs do not authorize verification', !empty($result['error']) && $db->tables['backlogs'][3]['status'] === 'Open');
$wo->equipment_id = 1; $db->backlogLockEquipment = 2;
$result = $model->close_selected_for_work_order($wo, array(2), 17, 'Checked');
expect('selected defect is rechecked after its row is locked', !empty($result['error']) && $db->tables['backlogs'][2]['status'] === 'Open');
$db->backlogLockEquipment = NULL;

$wo->equipment_id = 1; $wo->end_datetime = '0000-00-00 00:00:00';
$result = $model->close_selected_for_work_order($wo, array(2), 17, 'Checked');
expect('invalid legacy WO completion date remains unknown', ($result['closed'] ?? 0) === 1 && $db->tables['backlogs'][2]['end_date'] === NULL);

// Exercise real register hydration while stubbing only SQL result delivery.
class RegisterDB extends TestDB {
    public $registerRows = array(), $planRows = array();
    public function join($table, $condition, $type = '') { return $this; }
    public function get($table = NULL) {
        $from = $table ?: $this->from;
        $this->where = array(); $this->whereIn = array(); $this->from = '';
        if ($from === 'backlogs b') return new TestResult($this->registerRows);
        if ($from === 'maintenance_plan_jobs') return new TestResult($this->planRows);
        return new TestResult(array_values($this->tables['equipment']));
    }
}
$db = new RegisterDB(); $model = new Backlog_model(); $model->db = $db;
$db->registerRows = array(array('id'=>1, 'equipment_id'=>1, 'equipment_code'=>'EX 006', 'status'=>'Open', 'priority'=>'P2',
    'start_date'=>'2020-01-01', 'asset_label'=>'EX 006', 'in_charge'=>'', 'mechanic'=>'', 'reported_by'=>'', 'schedule_state'=>'planned'));
$db->planRows = array(array('id'=>7, 'backlog_id'=>1, 'equipment_id'=>2, 'start_date'=>'2030-01-01', 'end_date'=>'2030-01-01', 'hours_per_day'=>1, 'status'=>'Planned'));
$register = $model->get_register_overview('2029-01-01'); $r = $register['rows'][0];
expect('register flags mismatched plan machine', !$r->has_plan && strpos($r->schedule_label, 'another machine') !== FALSE);
$db->registerRows[0]['equipment_id'] = NULL; $db->planRows[0]['equipment_id'] = NULL;
$register = $model->get_register_overview('2029-01-01'); $r = $register['rows'][0];
expect('register does not treat two missing machine IDs as a match', !$r->has_plan && strpos($r->schedule_label, 'another machine') !== FALSE);
$db->planRows = array(); $db->registerRows[0]['status'] = 'Closed';
$db->registerRows[0]['closure_kind'] = 'historical_verified'; $db->registerRows[0]['verified_by'] = 17;
$db->registerRows[0]['verified_at'] = '2020-01-02 09:00:00';
$register = $model->get_register_overview('2029-01-01');
expect('register distinguishes historical verification performed now', $register['rows'][0]->verification_label === 'Historical completion; supervisor verified now');
$c = reset_fixture(TRUE); $c->input->getValues['q'] = 'BL-42';
$searchRow = array('id'=>42, 'is_open'=>TRUE, 'days_down'=>NULL, 'priority'=>'P2', 'is_overdue'=>FALSE,
    'machine_status'=>'OPERATIONAL', 'has_plan'=>FALSE, 'status'=>'Open', 'source'=>'Other', 'equipment_id'=>1,
    'start_date'=>'2020-01-01', 'machine_display'=>'EX 006', 'asset_label'=>'EX 006', 'model'=>'', 'serial_no'=>'',
    'description'=>'Hose', 'observation'=>'', 'closing_remarks'=>'', 'reported_by'=>'', 'in_charge'=>'', 'mechanic'=>'',
    'status_rank'=>1, 'priority_rank'=>2, 'owner_display'=>'');
$otherRow = $searchRow; $otherRow['id'] = 421;
$c->Backlog_model->overview = array('rows'=>array((object)$searchRow,(object)$otherRow), 'by_asset'=>array(), 'equipment'=>array(), 'available'=>TRUE);
$c->index();
expect('BL-id search locates the exact record, not an ID prefix', count($c->rendered['items']) === 1 && $c->rendered['items'][0]->id === 42);
expect('unknown ages are not shown as zero days', $c->rendered['kpi']['avg_days'] === NULL && $c->rendered['kpi']['oldest'] === NULL);
$closedRow = $searchRow; $closedRow['id'] = 5; $closedRow['status'] = 'Closed'; $closedRow['is_open'] = FALSE;
$cancelledRow = $closedRow; $cancelledRow['id'] = 6; $cancelledRow['status'] = 'Cancelled';
$c->Backlog_model->overview['rows'] = array((object)$closedRow,(object)$cancelledRow);
$c->input->getValues = array('tab'=>'closed'); $c->index();
expect('closed tab does not count or show cancelled defects as closures', $c->rendered['kpi']['closed'] === 1 && $c->rendered['tab_counts']['closed'] === 1 && count($c->rendered['items']) === 1 && $c->rendered['items'][0]->status === 'Closed');

// Real inspection model: blank, N/A and a pass never close. The current
// actor, not the original creator, is recorded on an evidence event.
class TestInspection extends Inspection_model {
    public $record, $items;
    public function get_record($id) { return $this->record; }
    public function get_record_items($id) { return $this->items; }
}
foreach (array('{}', '{"result":"N/A"}', '{"result":"Yes"}', '{"1":"ok"}') as $json) {
    $db = new TestDB();
    $db->tables['backlogs'][1] = array('id' => 1, 'equipment_id' => 1, 'inspection_item_id' => 8, 'status' => 'Open', 'start_date' => '2020-01-01', 'observation' => '');
    $inspection = new TestInspection(); $inspection->db = $db;
    $inspection->record = (object)array('equipment_id' => 1, 'inspection_date' => '2020-02-01', 'created_by' => 99);
    $inspection->items = array((object)array('item_id' => 8, 'value_json' => $json, 'priority' => 'P3'));
    $stats = $inspection->process_backlog_findings(25, 17);
    expect('inspection never auto-closes '.$json, $db->tables['backlogs'][1]['status'] === 'Open' && $stats['closed'] === 0);
    $positive = $json === '{"result":"Yes"}' || $json === '{"1":"ok"}';
    expect('only positive inspection prompts review '.$json, $stats['review'] === ($positive ? 1 : 0));
    if ($positive) expect('inspection evidence uses current actor '.$json, ($db->tables['backlog_events'][1]['actor_id'] ?? 0) === 17);
}

$db = new TestDB();
$db->tables['backlogs'][1] = array('id'=>1, 'equipment_id'=>1, 'inspection_item_id'=>8, 'status'=>'Open',
    'source_ref'=>'insp_9_item_8', 'start_date'=>'2020-01-01', 'observation'=>'', 'priority'=>'P3');
$inspection = new TestInspection(); $inspection->db = $db;
$inspection->record = (object)array('equipment_id'=>1, 'inspection_date'=>'2020-02-01', 'created_by'=>99, 'language'=>'en');
$inspection->items = array((object)array('item_id'=>8, 'value_json'=>'{"result":"No"}', 'priority'=>'P1', 'text_en'=>'Hose'));
$first = $inspection->process_backlog_findings(25, 17); $again = $inspection->process_backlog_findings(25, 17);
expect('repeat inspection is annotated once', $first['annotated'] === 1 && $again['annotated'] === 0 && $db->tables['backlogs'][1]['priority'] === 'P1');
$inspection->items[0]->value_json = '{"result":"Yes"}';
$pass = $inspection->process_backlog_findings(25, 17);
expect('edited positive result remains separate evidence', $pass['review'] === 1 && $db->tables['backlogs'][1]['status'] === 'Open');

// Execute the real work-order routes with fakes; no alternative route may
// bypass the supervisor verification and selected-defect policy.
class TestWorkOrderModel {
    public $db;
    public function __construct($db) { $this->db = $db; }
    public function get_by_id($id) { return isset($this->db->tables['work_orders'][$id]) ? (object)$this->db->tables['work_orders'][$id] : NULL; }
    public function update($id, $data) { return $this->db->where('id', $id)->update('work_orders', $data); }
    public function save_spare_parts($id, $rows) { return TRUE; }
    public function save_oil_records($id, $rows) { return TRUE; }
    public function sync_lube_consumption($id, $actor) { return array(); }
    public function add_status_history($id, $old, $new, $actor, $notes) { return TRUE; }
    public function close_open_session_for_wo($id) { return TRUE; }
    public function get_total_session_hours($id) { return 0; }
}
class TestEquipment { public $status; public function update_status($id, $status) { $this->status = $status; } }
class TestSpares { public function auto_issue_for_wo($id, $actor) { return 0; } }
function wo_fixture($status, $sup) {
    reset_fixture($sup);
    $c = (new ReflectionClass('Workorders'))->newInstanceWithoutConstructor();
    $c->db = $GLOBALS['testDB']; $c->input = $GLOBALS['testInput']; $c->session = $GLOBALS['testSession'];
    $c->supervisor = $sup; $c->load = new TestLoader($c); $c->Workorder_model = new TestWorkOrderModel($c->db);
    $c->Equipment_model = new TestEquipment(); $c->Spareparts_model = new TestSpares();
    $c->db->tables['work_orders'][10] = array('id'=>10, 'wo_number'=>'WO-10', 'equipment_id'=>1, 'type'=>'Corrective Maintenance',
        'status'=>$status, 'start_datetime'=>'2020-01-01 08:00:00', 'end_datetime'=>'2020-02-01 09:00:00',
        'completed_by'=>20, 'downtime_hours'=>5, 'action_taken'=>'Hose replaced', 'assigned_to'=>'');
    return $c;
}
function wo_submit($c, $method, $input, $id = 10) {
    $c->input->values = $input;
    try { if ($method === 'create') $c->create(); elseif ($method === 'edit') $c->edit($id); else $c->update_status($id); }
    catch (StopRedirect $e) { return $e->getMessage(); }
    return NULL;
}
$c = wo_fixture('Open', FALSE); wo_submit($c, 'create', array('status'=>'Closed'));
expect('WO create cannot bypass supervisor verification', count($c->db->tables['work_orders']) === 1 && strpos($c->session->messages['error'] ?? '', 'supervisor') !== FALSE);
$c = wo_fixture('Completed', FALSE); wo_submit($c, 'edit', array('status'=>'Closed'));
expect('WO edit cannot close directly', $c->db->tables['work_orders'][10]['status'] === 'Completed' && strpos($c->session->messages['error'] ?? '', 'Verify') !== FALSE);
$c = wo_fixture('Completed', FALSE); wo_submit($c, 'status', array('status'=>'Closed', 'verification_notes'=>'Checked'));
expect('WO quick closure rejects technician', $c->db->tables['work_orders'][10]['status'] === 'Completed' && strpos($c->session->messages['error'] ?? '', 'supervisor') !== FALSE);
$c = wo_fixture('Open', TRUE); wo_submit($c, 'status', array('status'=>'Closed', 'verification_notes'=>'Checked'));
expect('WO cannot skip completed state', $c->db->tables['work_orders'][10]['status'] === 'Open' && strpos($c->session->messages['error'] ?? '', 'Completed') !== FALSE);
$c = wo_fixture('Completed', TRUE); wo_submit($c, 'status', array('status'=>'Closed'));
expect('WO closure requires verification notes', $c->db->tables['work_orders'][10]['status'] === 'Completed' && strpos($c->session->messages['error'] ?? '', 'checked') !== FALSE);
$c = wo_fixture('Closed', TRUE); wo_submit($c, 'edit', array('status'=>'Open'));
expect('closed WO evidence cannot be rewritten through edit', $c->db->tables['work_orders'][10]['status'] === 'Closed');
$c = wo_fixture('Completed', TRUE); $c->db->woLockStatus = 'Closed';
wo_submit($c, 'edit', array('status'=>'Completed', 'type'=>'Corrective Maintenance', 'priority'=>'Medium', 'title'=>'Repair'));
expect('work order edit rechecks status under lock', strpos($c->session->messages['error'] ?? '', 'status changed') !== FALSE && $c->db->tables['work_orders'][10]['status'] === 'Completed');
$c = wo_fixture('Open', TRUE);
wo_submit($c, 'edit', array('status'=>'Open', 'type'=>'Corrective Maintenance', 'priority'=>'Medium', 'title'=>'Repair updated'));
expect('ordinary work order edit commits while still open', $c->db->tables['work_orders'][10]['title'] === 'Repair updated' && $c->db->snap === NULL && isset($c->session->messages['success']));

foreach (array('Completed', 'Closed') as $finished) {
    $c = wo_fixture($finished, FALSE);
    try { $c->start_session(10); } catch (StopRedirect $e) {}
    expect('cannot start session on '.$finished.' work order', strpos($c->session->messages['error'] ?? '', 'cannot start') !== FALSE && $c->db->tables['work_orders'][10]['status'] === $finished);
}
$c = wo_fixture('Closed', TRUE);
try { $c->delete_attachment(50, 10); } catch (StopRedirect $e) {}
expect('closed WO evidence cannot be deleted', strpos($c->session->messages['error'] ?? '', 'cannot be deleted') !== FALSE);
$c = wo_fixture('Closed', TRUE); $c->db->tables['wo_sessions'][50] = array('id'=>50, 'wo_id'=>10, 'status'=>'Open');
try { $c->close_session(50, 10); } catch (StopRedirect $e) {}
expect('historical open session cannot rewrite a closed WO', strpos($c->session->messages['error'] ?? '', 'locked') !== FALSE && $c->db->tables['wo_sessions'][50]['status'] === 'Open');

$c = wo_fixture('Completed', TRUE);
$c->db->tables['backlogs'] = array(
    1=>array('id'=>1, 'work_order_id'=>10, 'equipment_id'=>1, 'status'=>'Open', 'start_date'=>'2020-01-01', 'machine_status'=>'BREAKDOWN'),
    2=>array('id'=>2, 'work_order_id'=>10, 'equipment_id'=>1, 'status'=>'Open', 'start_date'=>'2020-01-01', 'machine_status'=>'BREAKDOWN'));
wo_submit($c, 'status', array('status'=>'Closed', 'verification_notes'=>'Pressure tested', 'resolved_defect_ids'=>array('1')));
expect('WO status route verifies only selected defects', $c->db->tables['work_orders'][10]['status'] === 'Closed' && $c->db->tables['backlogs'][1]['status'] === 'Closed' && $c->db->tables['backlogs'][2]['status'] === 'Open');
expect('open breakdown defect prevents equipment release', $c->Equipment_model->status === 'Breakdown');
$c = wo_fixture('Completed', TRUE);
$c->db->tables['backlogs'] = array(
    1=>array('id'=>1, 'work_order_id'=>10, 'equipment_id'=>1, 'status'=>'Open', 'start_date'=>'2020-01-01', 'machine_status'=>'BREAKDOWN'));
wo_submit($c, 'status', array('status'=>'Closed', 'verification_notes'=>'Pressure tested', 'resolved_defect_ids'=>array('1')));
expect('equipment status recalculates after the last selected defect closes', $c->db->tables['backlogs'][1]['status'] === 'Closed' && $c->Equipment_model->status === 'Available');


$c = wo_fixture('Completed', TRUE);
$c->db->tables['backlogs'] = array(
    1=>array('id'=>1, 'work_order_id'=>10, 'equipment_id'=>1, 'status'=>'Open', 'start_date'=>'2020-01-01'),
    2=>array('id'=>2, 'work_order_id'=>10, 'equipment_id'=>2, 'status'=>'Open', 'start_date'=>'2020-01-01'));
wo_submit($c, 'status', array('status'=>'Closed', 'verification_notes'=>'Pressure tested', 'resolved_defect_ids'=>array('1','2')));
expect('invalid selected defect rolls back all verification', $c->db->tables['work_orders'][10]['status'] === 'Completed' && $c->db->tables['backlogs'][1]['status'] === 'Open');

echo $passed.' passed, '.count($failed)." failed\n";
foreach ($failed as $name) fwrite(STDERR, "FAIL: $name\n");
exit($failed ? 1 : 0);
