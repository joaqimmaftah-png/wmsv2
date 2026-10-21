<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Pm extends MY_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->model('Pm_model');
        $this->load->model('Equipment_model');
    }

    public function index() {
        $filter = [
            'equipment_id' => $this->input->get('equipment_id'),
            'status'       => $this->input->get('status') ?: 'all',
        ];

        $data['pm_schedules'] = $this->Pm_model->get_all($filter);
        $data['equipment']  = $this->Equipment_model->get_all_active();
        $data['filter']     = $filter;
        $data['due_today']  = $this->Pm_model->get_due_today();
        $data['overdue']    = $this->Pm_model->get_overdue();
        // Open work orders, so a completion can name the job that did it.
        $data['open_wos']   = $this->db->select('w.id, w.wo_number, w.title, w.equipment_id, e.registration_no')
            ->from('work_orders w')
            ->join('equipment e', 'e.id = w.equipment_id', 'left')
            ->where_in('w.status', array('Open', 'In Progress', 'Waiting Parts', 'Waiting Tools'))
            ->order_by('w.created_at', 'DESC')->limit(300)->get()->result();
        $data['page_title'] = 'PM Planner';
        $this->render('maintenance/pm_planner', $data);
    }

    public function create() {
        if (!$this->is_supervisor()) {
            $this->session->set_flashdata('error', 'Access denied.');
            redirect('pm');
        }

        if ($this->input->post()) {
            $last_date  = $this->input->post('last_done_date', TRUE) ?: NULL;
            $interval_t = $this->input->post('interval_type', TRUE);
            $interval_v = (int)$this->input->post('interval_value', TRUE);
            $last_hours = $this->input->post('last_done_hours', TRUE) ?: NULL;

            $next_date  = $this->_calc_next_date($last_date, $interval_t, $interval_v);
            $next_hours = $last_hours
                ? $last_hours + (in_array($interval_t, array('Hours', 'Km'), TRUE) ? $interval_v : 0)
                : NULL;

            $data = [
                'equipment_id'    => $this->input->post('equipment_id', TRUE),
                'schedule_name'   => $this->input->post('schedule_name', TRUE),
                'description'     => $this->input->post('description', TRUE),
                'interval_type'   => $interval_t,
                'interval_value'  => $interval_v,
                'last_done_date'  => $last_date,
                'last_done_hours' => $last_hours,
                'next_due_date'   => $next_date,
                'next_due_hours'  => $next_hours,
                'tasks_checklist' => $this->input->post('tasks_checklist', TRUE),
                'status'          => 'Active',
            ];

            /*
             * Standard hours — how long the service holds the machine. The
             * Availability Forecast (F4) places exactly this much planned
             * downtime on the next due date; without it the service can be
             * scheduled but never forecast, so the form asks for it whenever
             * the column exists.
             *
             * Guarded by field_exists because older databases may not have the
             * column — where it is missing the value is simply not written
             * rather than failing on an unknown column.
             */
            if ($this->db->field_exists('standard_hours', 'pm_schedules')) {
                $std = $this->input->post('standard_hours', TRUE);
                $data['standard_hours'] = ($std === '' || $std === null || !is_numeric($std))
                    ? null
                    : max(0, min(999.99, (float)$std));
            }

            $this->Pm_model->create($data);
            $this->session->set_flashdata('success', 'PM Schedule created.');
            redirect('pm');
        }

        $data['pm']         = null;
        $data['equipment']  = $this->Equipment_model->get_all_active();
        // the field is only offered when the standard_hours column exists
        $data['has_standard_hours'] = $this->db->field_exists('standard_hours', 'pm_schedules');
        $data['page_title'] = 'Create PM Schedule';
        $this->render('maintenance/pm_form', $data);
    }

    /**
     * Edit an existing PM schedule.
     *
     * The PM planner has linked here since the planner screen was written, but
     * the action never existed, so the Edit button 404d.  It also matters for
     * the forecast: `standard_hours` is how F4 knows how long a service holds
     * the machine, and without an edit action a schedule created before the
     * value was known could never be given one.
     */
    public function edit($id) {
        if (!$this->is_supervisor()) {
            $this->session->set_flashdata('error', 'Access denied.');
            redirect('pm');
        }

        $pm = $this->Pm_model->get_by_id($id);
        if (!$pm) {
            $this->session->set_flashdata('error', 'PM schedule not found.');
            redirect('pm');
        }

        if ($this->input->post()) {
            $last_date  = $this->input->post('last_done_date', TRUE) ?: NULL;
            $interval_t = $this->input->post('interval_type', TRUE);
            $interval_v = (int)$this->input->post('interval_value', TRUE);
            $last_hours = $this->input->post('last_done_hours', TRUE) ?: NULL;

            $data = [
                'equipment_id'    => $this->input->post('equipment_id', TRUE),
                'schedule_name'   => $this->input->post('schedule_name', TRUE),
                'description'     => $this->input->post('description', TRUE),
                'interval_type'   => $interval_t,
                'interval_value'  => $interval_v,
                'last_done_date'  => $last_date,
                'last_done_hours' => $last_hours,
                'tasks_checklist' => $this->input->post('tasks_checklist', TRUE),
                'status'          => $this->input->post('status', TRUE) ?: $pm->status,
            ];

            /*
             * Recalculate the next due date only when something it derives from
             * actually changed.  Recomputing it unconditionally would set it to
             * NULL for any schedule that has never been done — and F4 places
             * that service's planned downtime on next_due_date, so a harmless
             * edit of the description would silently drop the service out of
             * the availability forecast.
             */
            $interval_changed = ($pm->interval_type !== $interval_t)
                             || ((int)$pm->interval_value !== $interval_v);
            $last_done_changed = ((string)$pm->last_done_date !== (string)$last_date);

            if ($interval_changed || $last_done_changed) {
                $data['next_due_date']  = $this->_calc_next_date($last_date, $interval_t, $interval_v);
                $data['next_due_hours'] = $last_hours
                    ? $last_hours + (in_array($interval_t, array('Hours', 'Km'), TRUE) ? $interval_v : 0)
                    : NULL;
            }

            /*
             * Standard hours, exactly as on create: clamped, and a blank stored
             * as NULL rather than 0 — "not entered yet" is not "takes no time",
             * and F4 reports the first as a warning.
             */
            if ($this->db->field_exists('standard_hours', 'pm_schedules')) {
                $std = $this->input->post('standard_hours', TRUE);
                $data['standard_hours'] = ($std === '' || $std === null || !is_numeric($std))
                    ? null
                    : max(0, min(999.99, (float)$std));
            }

            $this->Pm_model->update($id, $data);
            $this->session->set_flashdata('success', 'PM Schedule updated.');
            redirect('pm');
        }

        $data['pm']         = $pm;
        $data['equipment']  = $this->Equipment_model->get_all_active();
        $data['has_standard_hours'] = $this->db->field_exists('standard_hours', 'pm_schedules');
        $data['page_title'] = 'Edit PM Schedule';
        $this->render('maintenance/pm_form', $data);
    }

    /**
     * Mark a PM schedule as done.
     *
     * The planner's Mark Done modal posts to `pm/complete` with the schedule id
     * in a hidden `pm_id` field, so the id arrives in the body rather than in
     * the URI.  It was only ever read from the URI, which made $id null on every
     * submit: the completion row was written with a null schedule id and
     * `$pm->interval_type` then read a property off a missing row.
     *
     * Either source is accepted now, because the URI form is the natural one for
     * anything linking straight at a schedule.
     */
    public function complete($id = null) {
        if ($this->input->post()) {
            $id = $id ?: $this->input->post('pm_id', TRUE);

            $pm = $id ? $this->Pm_model->get_by_id($id) : null;
            if (!$pm) {
                // without this the next line reads a property off null and the
                // user gets an error page instead of a message
                $this->session->set_flashdata('error', 'PM schedule not found.');
                redirect('pm');
            }

            /*
             * Field names: the planner's Mark Done modal posts
             * `completion_date` / `current_hours`, while this handler read
             * `completed_date` / `completed_hours` — so the modal's date was
             * ignored (always "today") and its meter reading was always lost.
             * Both spellings are accepted now; the view uses the canonical
             * ones.
             */
            $completed_date  = $this->input->post('completed_date', TRUE)
                ?: ($this->input->post('completion_date', TRUE) ?: date('Y-m-d'));
            if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', (string)$completed_date)
                || !checkdate((int)substr($completed_date, 5, 2), (int)substr($completed_date, 8, 2), (int)substr($completed_date, 0, 4))) {
                $completed_date = date('Y-m-d');
            }
            $completed_hours = $this->input->post('completed_hours', TRUE);
            if ($completed_hours === NULL) {
                $completed_hours = $this->input->post('current_hours', TRUE);
            }
            $completed_hours = ($completed_hours === NULL || $completed_hours === '') ? NULL : $completed_hours;

            /*
             * Optional link to the work order that did the service. Without it
             * a PM completion and its work order are two unrelated facts, so
             * the job card cannot show the services it covered and the PM
             * history cannot show the job that performed it.
             */
            $work_order_id = (int)$this->input->post('work_order_id', TRUE);
            if ($work_order_id > 0) {
                $wo = $this->db->select('id, equipment_id')->where('id', $work_order_id)->get('work_orders')->row();
                if (!$wo || (int)$wo->equipment_id !== (int)$pm->equipment_id) {
                    $work_order_id = 0;
                    $this->session->set_flashdata('error',
                        'The PM was not completed: the selected work order belongs to a different machine.');
                    redirect('pm');
                    return;
                }
            }

            $next_date  = $this->_calc_next_date($completed_date, $pm->interval_type, $pm->interval_value);
            // Km schedules advance the odometer target the same way Hours does
            // (the enum value is 'Km', not 'Kilometres' — see K-05).
            $meter_interval = in_array($pm->interval_type, array('Hours', 'Km'), TRUE);
            // NULL here means "the target is unchanged", not "no target": a
            // date-based schedule must not lose the odometer target it had.
            $next_hours = ($completed_hours !== NULL && $meter_interval)
                ? (float)$completed_hours + (float)$pm->interval_value
                : NULL;

            $completion = [
                'pm_schedule_id'  => $id,
                'completed_date'  => $completed_date,
                'completed_hours' => $completed_hours,
                'completed_by'    => $this->user_id,
                'notes'           => $this->input->post('notes', TRUE),
            ];
            // Older databases may predate the link column (§13 tolerance).
            if ($this->db->field_exists('work_order_id', 'pm_completions')) {
                $completion['work_order_id'] = $work_order_id > 0 ? $work_order_id : NULL;
            }
            $this->db->insert('pm_completions', $completion);

            /*
             * The hours typed at completion are a real meter reading — until
             * Wave 2 they were stored on the completion row and nowhere else,
             * so the machine's meter and its history drifted away from the PM
             * record. They now go through the single meter writer (F-06),
             * which validates them and refuses an impossible jump. A refusal
             * never blocks the completion: it is reported afterwards.
             */
            $meter_note = '';
            if ($completed_hours !== NULL && $meter_interval) {
                // `record_meter_reading()` speaks "Hours"/"Kilometres"; the
                // schedule enum says "Km".
                $unit = ($pm->interval_type === 'Km') ? 'Kilometres' : $pm->interval_type;
                $reading = $this->Equipment_model->record_meter_reading(
                    $pm->equipment_id, $completed_date, (float)$completed_hours,
                    'WorkOrder', $work_order_id > 0 ? $work_order_id : null, $this->user_id, ['unit' => $unit]
                );
                if (empty($reading['ok'])) {
                    $meter_note = ' ⚠ The meter reading was not recorded: '.$reading['message'];
                }
            }

            $schedule_update = array(
                'last_done_date' => $completed_date,
                'next_due_date'  => $next_date,
            );
            // Only a reading that was actually entered moves the meter fields;
            // a blank box on a date-based schedule must not erase them.
            if ($completed_hours !== NULL) {
                $schedule_update['last_done_hours'] = $completed_hours;
            }
            if ($next_hours !== NULL) {
                $schedule_update['next_due_hours'] = $next_hours;
            }
            $this->Pm_model->update($id, $schedule_update);

            $this->session->set_flashdata('success', 'PM marked as completed.'.$meter_note);
        }
        redirect('pm');
    }

    public function calendar() {
        $months = 12;
        $data['forecast']   = $this->Pm_model->get_12month_forecast($months);
        $data['page_title'] = '12-Month PM Forecast';
        $this->render('maintenance/pm_calendar', $data);
    }

    private function _calc_next_date($from_date, $interval_type, $interval_value) {
        if (!$from_date) return NULL;
        switch ($interval_type) {
            case 'Days':   return date('Y-m-d', strtotime("{$from_date} +{$interval_value} days"));
            case 'Months': return date('Y-m-d', strtotime("{$from_date} +{$interval_value} months"));
            default:       return date('Y-m-d', strtotime("{$from_date} +30 days")); // hours-based, estimate
        }
    }
}
