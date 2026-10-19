<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Spareparts — Pending Spare Parts Tracking & Availability Alerts.
 *
 * The board that answers:
 *   "Which pending maintenance jobs can now proceed because the required
 *    spare parts have arrived?"
 *
 * Read actions (index / alerts) are available to supervisor, technician,
 * store and manager.  Reserving or issuing stock is limited to roles that can
 * write (supervisor / technician / store) — the Manager role stays view-only.
 */
class Spareparts extends MY_Controller {

    public $Spareparts_model;

    public function __construct() {
        parent::__construct();
        $this->load->model(array('Spareparts_model', 'Materials_model'));
        $this->Spareparts_model->materials = $this->Materials_model;
    }

    /* ── PENDING SPARE PARTS BOARD ─────────────────────── */
    public function index() {
        $filter = array(
            'status' => $this->input->get('status'),
            'search' => $this->input->get('search'),
        );

        $lines = $this->Spareparts_model->get_pending_lines($filter);
        if (!empty($filter['status']) && $filter['status'] !== 'all') {
            $wanted = $filter['status'];
            $lines = array_values(array_filter($lines, function ($l) use ($wanted) {
                return $l->avail_status === $wanted;
            }));
        }

        // Group the lines by inventory item — this is the view the store uses
        // when one part is required by several work orders.
        $groups = array();
        $unlinked = array();
        foreach ($lines as $l) {
            if (!$l->material) { $unlinked[] = $l; continue; }
            $key = (int)$l->material->id;
            if (!isset($groups[$key])) {
                $groups[$key] = array(
                    'material' => $l->material,
                    'stock'    => $l->stock_qty,
                    'free'     => $l->free_qty,
                    'lines'    => array(),
                    'demand'   => 0.0,
                );
            }
            $groups[$key]['lines'][] = $l;
            $groups[$key]['demand'] += max(0, $l->qty_required - $l->qty_allocated - $l->qty_issued);
        }
        // Recompute the free qty once per group (all lines of a group share it)
        foreach ($groups as &$g) {
            if (!empty($g['lines'])) $g['free'] = $g['lines'][0]->free_qty;
        }
        unset($g);
        $on_order = $this->Spareparts_model->get_on_order_by_material();

        $data = array(
            'groups'      => $groups,
            'unlinked'    => $unlinked,
            'line_allocs' => $this->Spareparts_model->get_line_allocations(),
            'on_order'    => $on_order,
            'summary'     => $this->Spareparts_model->get_board_summary(),
            'ready_wos'   => $this->Spareparts_model->get_ready_work_orders(),
            'new_alerts'  => $this->Spareparts_model->get_recent_alerts($this->user_id, $this->role_id, 5),
            'filter'      => $filter,
            'can_write'   => $this->can_write(),
            'statuses'    => array('Pending', 'Partial', 'Available', 'Allocated', 'Unlinked'),
            'page_title'  => 'Pending Spare Parts',
        );
        $this->render('spareparts/index', $data);
    }

    /* ── ALERTS (availability notifications) ───────────── */
    public function alerts() {
        $filter = array('status' => $this->input->get('status'));
        $data = array(
            'alerts'     => $this->Spareparts_model->get_alerts($filter, $this->user_id, $this->role_id),
            'filter'     => $filter,
            'new_count'  => $this->Spareparts_model->count_new_alerts($this->user_id, $this->role_id),
            'page_title' => 'Spare Part Availability Alerts',
        );
        $this->render('spareparts/alerts', $data);
    }

    public function mark_read($id) {
        if (!$this->require_post()) return;
        $this->Spareparts_model->mark_alert_read($id, $this->user_id);
        redirect('spareparts/alerts');
    }

    public function mark_all_read() {
        if (!$this->require_post()) return;
        $n = $this->Spareparts_model->mark_all_alerts_read($this->user_id, $this->role_id);
        $this->session->set_flashdata('success', $n > 0 ? $n.' alert(s) marked as read.' : 'No new alerts.');
        redirect('spareparts/alerts');
    }

    /* ── ALLOCATE stock against a requested part ───────── */
    public function allocate($line_id) {
        if (!$this->require_post()) return;
        if (!$this->can_write()) {
            $this->session->set_flashdata('error', 'Only supervisor, technician or store users can allocate stock.');
            redirect('spareparts');
        }
        $res = $this->Spareparts_model->allocate($line_id, $this->input->post('qty'), $this->user_id);
        if (!empty($res['ok'])) {
            $this->session->set_flashdata('success', 'Stock allocated — the quantity is now reserved for this work order.');
        } else {
            $this->session->set_flashdata('error', isset($res['error']) ? $res['error'] : 'Allocation failed.');
        }
        redirect('spareparts');
    }

    /* ── RELEASE a reservation ─────────────────────────── */
    public function release($allocation_id) {
        if (!$this->require_post()) return;
        if (!$this->can_write()) {
            $this->session->set_flashdata('error', 'Only supervisor, technician or store users can release allocations.');
            redirect('spareparts');
        }
        if ($this->Spareparts_model->release_allocation($allocation_id, $this->user_id)) {
            $this->session->set_flashdata('success', 'Allocation released — the stock is available for other work orders again.');
        } else {
            $this->session->set_flashdata('error', 'The allocation could not be released (it may already have been issued).');
        }
        redirect('spareparts');
    }

    /* ── ISSUE allocated stock (physical hand-out) ─────── */
    public function issue($allocation_id) {
        if (!$this->require_post()) return;
        if (!$this->can_write()) {
            $this->session->set_flashdata('error', 'Only supervisor, technician or store users can issue stock.');
            redirect('spareparts');
        }
        $res = $this->Spareparts_model->issue_allocation($allocation_id, $this->user_id);
        if (!empty($res['ok'])) {
            $this->session->set_flashdata('success', 'Stock issued and inventory updated.');
        } else {
            $this->session->set_flashdata('error', isset($res['error']) ? $res['error'] : 'The issue could not be booked.');
        }
        redirect('spareparts');
    }
}
