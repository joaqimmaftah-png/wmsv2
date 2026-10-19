<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Backlog — the defect / backlog register.
 *
 * Replaces the manual "Backlog on going" / "Backlog done" sheets. Items are
 * raised, prioritised, assigned and closed in the system; the Excel export
 * reproduces the two sheets for anyone who still wants the file.
 */
class Backlog extends MY_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->model('Backlog_model');
        $this->load->model('Equipment_model');
    }

    /* ── LIST ──────────────────────────────────────────── */
    public function index() {
        $filter = $this->_filter();
        $data = array(
            'items'      => $this->Backlog_model->get_all($filter),
            'kpi'        => $this->Backlog_model->kpi(),
            'by_asset'   => $this->Backlog_model->by_equipment(10),
            'equipment'  => $this->_equipment_list(),
            'filter'     => $filter,
            'available'  => $this->Backlog_model->available(),
            'can_write'  => $this->can_write(),
            'page_title' => 'Backlog Register',
        );
        $this->render('backlog/index', $data);
        $data['is_supervisor'] = $this->is_supervisor();
    }

    /* ── CREATE ────────────────────────────────────────── */
    public function create() {
        if (!$this->can_write()) redirect('backlog');
        if ($this->input->post()) return $this->_save(null);
        $this->_form(null, array());
    }

    /* ── EDIT ──────────────────────────────────────────── */
    public function edit($id) {
        if (!$this->can_write()) redirect('backlog');
        $item = $this->Backlog_model->get_by_id($id);
        if (!$item) show_404();
        if ($this->input->post()) return $this->_save($item);
        $this->_form($item, array());
    }

    /* ── CLOSE (quick action from the list) ────────────── */
    /**
     * Close a defect (Wave 3, Q5 / F-08).
     *
     * Closing a defect is the statement "the repair is done and somebody
     * checked it". It used to be available to anyone who could edit the board,
     * which made it useless as evidence; it is now the supervisor's call, the
     * closure records who verified it and when, and — when the defect has a
     * work order — the work order must be finished first, because that is
     * where the repair is written down. Closing the work order closes the
     * defect automatically, so there is exactly one closure path.
     */
    public function close($id) {
        if (!$this->require_post()) return;
        if (!$this->can_write()) redirect('backlog');
        $item = $this->Backlog_model->get_by_id($id);
        if (!$item) show_404();

        if (!$this->is_supervisor()) {
            $this->session->set_flashdata('error',
                'Closing a defect is the supervisor\'s verification. Record what was done and let a supervisor close it.');
            redirect('backlog');
            return;
        }

        // A defect with a work order is closed through that work order.
        if (!empty($item->work_order_id) && $this->db->table_exists('work_orders')) {
            $wo = $this->db->select('id, wo_number, status')->where('id', (int)$item->work_order_id)
                ->get('work_orders')->row();
            if ($wo && !in_array($wo->status, array('Completed', 'Closed'), TRUE)) {
                $this->session->set_flashdata('error',
                    'Work order '.$wo->wo_number.' is still "'.$wo->status.'" — finish it there, then close it as the supervisor: '
                    .'that closes this defect with the repair record attached.');
                redirect('backlog');
                return;
            }
        }

        $data = array(
            'status'          => 'Closed',
            'end_date'        => $this->input->post('end_date') ?: date('Y-m-d'),
            'end_odometer'    => $this->input->post('end_odometer'),
            'closing_remarks' => $this->input->post('closing_remarks'),
            'mechanic'        => $this->input->post('mechanic') ?: $item->in_charge,
            'supervisor'      => $this->input->post('supervisor') ?: $item->supervisor,
        );

        // The Wave 3 evidence columns arrive with migration 014.
        if ($this->db->field_exists('verified_by', 'backlogs')) {
            $data['verified_by']         = $this->user_id;
            $data['verified_at']         = date('Y-m-d H:i:s');
            $data['verification_notes']  = $this->input->post('verification_notes', TRUE)
                ?: 'Verified on site by the supervisor.';
        }
        if ($this->db->field_exists('closed_by', 'backlogs')) {
            $data['closed_by'] = $this->user_id;
        }

        $ok = $this->Backlog_model->update($id, $data);
        $this->session->set_flashdata($ok ? 'success' : 'error',
            $ok ? 'Defect closed and verified by you.' : 'The item could not be closed.');
        redirect('backlog');
    }

    public function delete($id) {
        if (!$this->require_post()) return;
        if (!$this->is_supervisor()) redirect('backlog');
        $this->Backlog_model->delete($id);
        $this->session->set_flashdata('success', 'Backlog item deleted.');
        redirect('backlog');
    }

    /* ── EXCEL EXPORT (mirrors the two manual sheets) ──── */
    public function export_xlsx() {
        $this->load->helper('xlsx');
        $filter = $this->_filter();
        $rows   = $this->Backlog_model->get_all($filter);

        $open = array(); $done = array();
        foreach ($rows as $r) {
            if ($r->status === 'Closed' || $r->status === 'Cancelled') $done[] = $r;
            else $open[] = $r;
        }

        $xlsx = new XlsxWriter();
        $sHdr  = 'bold|fontSize:10|fontName:Arial|align:center|valign:middle|bg:2E75B6|fg:FFFFFF|border:thin|wrap';
        $sCell = 'fontSize:10|fontName:Arial|valign:middle|border:thin|wrap';
        $sC    = 'fontSize:10|fontName:Arial|align:center|valign:middle|border:thin';
        $sP1   = 'bold|fontSize:10|fontName:Arial|align:center|valign:middle|border:thin|bg:FFE0E0|fg:C00000';
        foreach (array($sHdr, $sCell, $sC, $sP1) as $s) $xlsx->registerStyle($s);

        // ── Sheet 1: open items ──
        $xlsx->addSheet('Backlog on going');
        foreach (array(1=>12, 2=>18, 3=>20, 4=>14, 5=>12, 6=>45, 7=>12, 8=>16,
                       9=>16, 10=>8, 11=>10, 12=>12, 13=>35, 14=>14) as $c => $w) {
            $xlsx->setColWidth($c, $w);
        }
        $xlsx->writeRow(array('ASSET','MODEL','S/N','ODOMETER','STARTING DATE','DESCRIPTION',
                              'SOURCE','REPORTED BY','IN CHARGE','PRIORITY','DAYS DOWN',
                              'DEADLINE','OBSERVATION','STATUS'), $sHdr);
        $xlsx->freezePane('A2');
        $xlsx->setAutoFilter('A1:N1');
        foreach ($open as $r) {
            $xlsx->writeRowStyled(array(
                array('v' => $r->equipment_code ?: $r->asset_label, 's' => $sC),
                array('v' => (string)$r->model,          's' => $sCell),
                array('v' => (string)$r->serial_no,      's' => $sCell),
                array('v' => (string)$r->start_odometer, 's' => $sC),
                array('v' => (string)$r->start_date,     's' => $sC),
                array('v' => (string)$r->description,    's' => $sCell),
                array('v' => (string)$r->source,         's' => $sC),
                array('v' => (string)$r->reported_by,    's' => $sCell),
                array('v' => (string)$r->in_charge,      's' => $sCell),
                array('v' => (string)$r->priority,       's' => ($r->priority === 'P1' ? $sP1 : $sC)),
                array('v' => (int)$r->days_down,         's' => $sC),
                array('v' => (string)$r->deadline,       's' => $sC),
                array('v' => (string)$r->observation,    's' => $sCell),
                array('v' => (string)$r->machine_status, 's' => $sC),
            ));
        }

        // ── Sheet 2: closed items ──
        $xlsx->addSheet('Backlog done');
        foreach (array(1=>12, 2=>18, 3=>20, 4=>14, 5=>12, 6=>45, 7=>18, 8=>14,
                       9=>14, 10=>12, 11=>40) as $c => $w) {
            $xlsx->setColWidth($c, $w);
        }
        $xlsx->writeRow(array('ASSET','MODEL','S/N','START ODO','STARTING DATE','DESCRIPTION',
                              'MECHANIC IN CHARGE','SUPERVISOR','END ODO','END DATE',
                              'OBSERVATION'), $sHdr);
        $xlsx->freezePane('A2');
        foreach ($done as $r) {
            $xlsx->writeRowStyled(array(
                array('v' => $r->equipment_code ?: $r->asset_label, 's' => $sC),
                array('v' => (string)$r->model,           's' => $sCell),
                array('v' => (string)$r->serial_no,       's' => $sCell),
                array('v' => (string)$r->start_odometer,  's' => $sC),
                array('v' => (string)$r->start_date,      's' => $sC),
                array('v' => (string)$r->description,     's' => $sCell),
                array('v' => (string)($r->mechanic ?: $r->in_charge), 's' => $sCell),
                array('v' => (string)$r->supervisor,      's' => $sCell),
                array('v' => (string)$r->end_odometer,    's' => $sC),
                array('v' => (string)$r->end_date,        's' => $sC),
                array('v' => (string)$r->closing_remarks, 's' => $sCell),
            ));
        }

        $name = 'Backlog_'.date('Ymd_His').'.xlsx';
        $tmp  = sys_get_temp_dir().'/'.$name;
        $xlsx->save($tmp);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="'.$name.'"');
        header('Content-Length: '.filesize($tmp));
        readfile($tmp);
        unlink($tmp);
        exit;
    }

    /* ── internals ─────────────────────────────────────── */

    private function _filter() {
        return array(
            'status'         => $this->input->get('status'),
            'priority'       => $this->input->get('priority'),
            'source'         => $this->input->get('source'),
            'machine_status' => $this->input->get('machine_status'),
            'equipment_id'   => $this->input->get('equipment_id'),
            'date_from'      => $this->input->get('date_from'),
            'date_to'        => $this->input->get('date_to'),
            'overdue'        => $this->input->get('overdue'),
            'search'         => $this->input->get('search'),
        );
    }

    private function _equipment_list() {
        if (!$this->db->table_exists('equipment')) return array();
        return $this->db->select('id, registration_no')->from('equipment')
                        ->order_by('registration_no')->get()->result();
    }

    private function _form($item, $errors, $post = null) {
        $this->render('backlog/form', array(
            'item'       => $item,
            'post'       => $post,
            'errors'     => $errors,
            'equipment'  => $this->_equipment_list(),
            'sources'    => Backlog_model::SOURCES,
            'priorities' => Backlog_model::PRIORITIES,
            'statuses'   => Backlog_model::STATUSES,
            'machine_statuses' => Backlog_model::MACHINE_STATUSES,
            'page_title' => $item ? 'Edit Backlog Item' : 'New Backlog Item',
        ));
    }

    private function _save($item) {
        $in = $this->input->post(NULL, TRUE);
        $errors = array();

        $asset = trim((string)$this->_v($in, 'asset_label'));
        $equip = (int)$this->_v($in, 'equipment_id');
        if ($equip > 0) {
            $row = $this->db->select('registration_no')->from('equipment')
                            ->where('id', $equip)->get()->row();
            if ($row && $asset === '') $asset = $row->registration_no;
        } elseif ($asset !== '') {
            // free-text asset: try to attach it to a real machine
            $equip = (int)$this->Backlog_model->resolve_equipment($asset);
        }

        if ($asset === '')  $errors['asset_label'] = 'Choose the machine, or type the asset reference.';
        $start = $this->_v($in, 'start_date');
        if (!$this->_is_date($start)) $errors['start_date'] = 'A valid starting date is required.';
        $desc = trim((string)$this->_v($in, 'description'));
        if ($desc === '') $errors['description'] = 'Describe the defect.';
        $end = $this->_v($in, 'end_date');
        if ($end !== '' && $end !== null && !$this->_is_date($end)) {
            $errors['end_date'] = 'The end date is not a valid date.';
        }
        if (!$errors && $end && $start && $end < $start) {
            $errors['end_date'] = 'The end date cannot be before the starting date.';
        }
        $status = in_array($this->_v($in, 'status'), Backlog_model::STATUSES, true)
            ? $this->_v($in, 'status') : 'Open';
        if ($status === 'Closed' && !$end) {
            $end = date('Y-m-d');
        }

        if ($errors) {
            $this->session->set_flashdata('error', 'Please correct the highlighted fields.');
            return $this->_form($item, $errors, (object)$in);
        }

        $planned_date = $this->_v($in, 'planned_date');
        $estimated_duration = $this->_v($in, 'estimated_duration');

        $data = array(
            'equipment_id'    => $equip > 0 ? $equip : NULL,
            'asset_label'     => $asset,
            'model'           => $this->_v($in, 'model'),
            'serial_no'       => $this->_v($in, 'serial_no'),
            'start_odometer'  => $this->_v($in, 'start_odometer'),
            'start_date'      => $start,
            'description'     => $desc,
            'source'          => in_array($this->_v($in, 'source'), Backlog_model::SOURCES, true)
                                    ? $this->_v($in, 'source') : 'Other',
            'reported_by'     => $this->_v($in, 'reported_by'),
            'in_charge'       => $this->_v($in, 'in_charge'),
            'priority'        => in_array($this->_v($in, 'priority'), Backlog_model::PRIORITIES, true)
                                    ? $this->_v($in, 'priority') : NULL,
            'deadline'        => $this->_is_date($this->_v($in, 'deadline')) ? $this->_v($in, 'deadline') : NULL,
            'observation'     => $this->_v($in, 'observation'),
            'machine_status'  => in_array($this->_v($in, 'machine_status'), Backlog_model::MACHINE_STATUSES, true)
                                    ? $this->_v($in, 'machine_status') : NULL,
            'status'          => $status,
            'end_odometer'    => $this->_v($in, 'end_odometer'),
            'end_date'        => $end ?: NULL,
            'closing_remarks' => $this->_v($in, 'closing_remarks'),
            'mechanic'        => $this->_v($in, 'mechanic'),
            'supervisor'      => $this->_v($in, 'supervisor'),
            'planned_date'    => $this->_is_date($planned_date) ? $planned_date : NULL,
            'estimated_duration' => is_numeric($estimated_duration) ? (int)$estimated_duration : NULL,
        );

        if ($item) {
            $ok = $this->Backlog_model->update($item->id, $data);
            $msg = $ok ? 'Backlog item updated.' : 'The item could not be updated.';
            $saved_id = $item->id;
        } else {
            $saved_id = $this->Backlog_model->insert($data);
            $ok = $saved_id !== false;
            $msg = $ok ? 'Backlog item created.' : 'The item could not be created.';
        }
        
        if ($ok && $equip > 0 && $this->_is_date($planned_date) && is_numeric($estimated_duration)) {
            // Push to Maintenance Plan (AC-07, AC-08)
            $this->load->model('Maintenance_plan_model');
            $hours_per_day = round((int)$estimated_duration / 60, 2);
            $job_data = [
                'equipment_id'  => $equip,
                'start_date'    => $planned_date,
                'end_date'      => $planned_date,
                'hours_per_day' => $hours_per_day,
                'job_type'      => 'Repair',
                'category'      => 'Corrective',
                'title'         => 'Backlog: ' . mb_substr($desc, 0, 100),
                'status'        => 'Planned',
                'source'        => 'FromBacklog',
                'backlog_id'    => $saved_id
            ];
            
            // Check if job exists
            $existing_job = $this->db->where('backlog_id', $saved_id)->get('maintenance_plan_jobs')->row();
            if ($existing_job) {
                $this->Maintenance_plan_model->save_job($job_data, $this->user_id, $existing_job->id);
            } else {
                $this->Maintenance_plan_model->save_job($job_data, $this->user_id);
            }
        } elseif ($ok && $item) {
            // If it was removed or unset
            $existing_job = $this->db->where('backlog_id', $saved_id)->get('maintenance_plan_jobs')->row();
            if ($existing_job && (!$this->_is_date($planned_date) || !is_numeric($estimated_duration))) {
                $this->load->model('Maintenance_plan_model');
                $this->Maintenance_plan_model->delete_job($existing_job->id, $this->user_id);
            }
        }

        if (!$ok && $this->Backlog_model->last_error()) {
            $msg .= ' ' . $this->Backlog_model->last_error();
        }
        $this->session->set_flashdata($ok ? 'success' : 'error', $msg);
        if (!$ok) return $this->_form($item, array(), (object)$in);
        redirect('backlog');
    }

    private function _v($in, $k) {
        return isset($in[$k]) ? $in[$k] : '';
    }

    private function _is_date($s) {
        if (!$s) return false;
        $d = DateTime::createFromFormat('Y-m-d', $s);
        return $d && $d->format('Y-m-d') === $s;
    }
}
