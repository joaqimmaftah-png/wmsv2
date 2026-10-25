<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Reports extends MY_Controller {

    public $Materials_model;
    public $Asset_model;
    public $Store_model;

    /** Import results are parked here while the user reads the summary */
    const ASSET_IMPORT_TMP = 'uploads/tmp/';

    /** How many *completed* reporting weeks /reports checks for an unpublished W36. */
    const PUBLICATION_LOOKBACK_WEEKS = 4;

    public function __construct() {
        parent::__construct();
        $this->load->model(['Dashboard_model','Workorder_model','Team_model','Lubricant_model','Equipment_model','Order_model','Supplier_model','Asset_model','Materials_model','Store_model','Settings_model','Kpi_target_model','Kpi_engine_model','W36_report_model']);
    }

    /**
     * When a W36 report's publication becomes due.
     *
     * The application has NO publication-deadline setting, so the only honest
     * boundary is the report's own configured reporting week: its last day is
     * the "due soon" warning window and its last instant is the deadline.
     * If the business later defines a formal cut-off (e.g. Monday 09:00 or a
     * settings key), it belongs in this ONE function — no view or report may
     * compute its own.  Nothing here changes when publishing is allowed.
     *
     * @param  string $week_end 'Y-m-d'
     * @return array{warning_from:string, deadline:string} 'Y-m-d H:i:s'
     */
    private function _publication_window($week_end) {
        return array(
            'warning_from' => date('Y-m-d 00:00:00', strtotime($week_end)),
            'deadline'     => date('Y-m-d 23:59:59', strtotime($week_end)),
        );
    }

    /**
     * The pure state classification of one report week.  Kept separate from
     * the data reads so the five-state ladder can be reasoned about (and
     * regression-tested) on its own.  Timestamps are 'Y-m-d H:i:s' strings,
     * which compare lexicographically, so no timezone math is repeated here.
     *
     * @return string published | draft_in_progress | draft_due_soon |
     *                unpublished_action_required
     */
    private function _publication_state($is_published, $now, $warning_from, $deadline) {
        if ($is_published) return 'published';
        if (strcmp((string)$now, (string)$deadline) >= 0) return 'unpublished_action_required';
        if (strcmp((string)$now, (string)$warning_from) >= 0) return 'draft_due_soon';
        return 'draft_in_progress';
    }

    public function index() {
        $data['page_title'] = 'Reports';
        // default week shown by the weekly-report launcher (W0.7)
        $week_start = $this->Settings_model->week_start();
        $bounds     = $this->Settings_model->week_bounds($week_start);
        $week_end   = isset($bounds['end']) ? $bounds['end'] : date('Y-m-d', strtotime($week_start . ' +6 days'));
        $iso_week   = $this->Settings_model->iso_week_label($week_start);

        $data['report_week_start']  = $week_start;
        $data['report_week_end']    = $week_end;
        $data['report_iso_week']    = $iso_week;
        $data['weekly_report_name'] = $this->W36_report_model->report_name($week_start);

        // Operational reporting telemetry — wrapped defensively so a missing
        // optional table never prevents the Reports hub from rendering.
        $telemetry = array(
            'available'           => false,
            'availability_pct'    => 100.0,
            'avail_target_pct'    => 88.0,
            'avail_machines_with' => 0,
            'avail_from'          => null,
            'avail_to'            => null,
            'mttr_30d'            => 0.0,
            'mtbf_30d'            => 0.0,
            'breakdowns_30d'      => 0,
            'downtime_30d'        => 0.0,
            'availability_trend'  => array(),
            'zero_failure_mode'   => 'dash',
            'overdue_pm'          => 0,
            'pm_due_30d'          => 0,
            'fleet_total'         => 0,
            'machines_down'       => 0,
            'equipment_list'      => array(),
            'reorder_count'       => 0,
            'out_of_stock_count'  => 0,
            'reorder_units'       => 0,
            'store_available'     => false,
            'targets_ready'       => false,
            'w36_snapshot'        => null,
            // Five-state W36 publication ladder.  Every key has an honest
            // default so a failed telemetry read never looks like a known
            // "Draft" or a known published week.
            'w36_pub'             => array(
                'checkable'     => false,
                'state'         => 'unknown',
                'published_at'  => null,
                'warning_from'  => null,
                'deadline'      => null,
                'previous'      => null,
                'older_count'   => 0,
                'older_latest'  => null,
                'window'        => self::PUBLICATION_LOOKBACK_WEEKS,
            ),
            'weekly_open_hours'   => 168.0,
            'daily_open_hours'    => 24.0,
        );

        try {
            $telemetry['weekly_open_hours'] = (float)$this->Settings_model->period_open_hours(7);
            $telemetry['daily_open_hours']  = (float)$this->Settings_model->daily_open_hours();
            $telemetry['zero_failure_mode'] = $this->Kpi_engine_model->zero_failure_mode();
            $telemetry['avail_target_pct']  = (float)$this->Dashboard_model->availability_target_pct();

            $avail_win = $this->Dashboard_model->get_availability_window();
            $telemetry['availability_pct']    = isset($avail_win['pct']) ? (float)$avail_win['pct'] : 100.0;
            $telemetry['avail_machines_with'] = isset($avail_win['machines_with']) ? (int)$avail_win['machines_with'] : 0;
            $telemetry['avail_from']          = isset($avail_win['from']) ? $avail_win['from'] : null;
            $telemetry['avail_to']            = isset($avail_win['to']) ? $avail_win['to'] : null;

            $telemetry['mttr_30d']       = (float)$this->Dashboard_model->get_mttr_30d();
            $telemetry['mtbf_30d']       = (float)$this->Dashboard_model->get_mtbf_30d();
            $telemetry['breakdowns_30d'] = (int)$this->Dashboard_model->count_breakdowns_30d();
            $telemetry['downtime_30d']   = (float)$this->Dashboard_model->get_total_downtime_30d();

            // One 30-day series for the reporting-context trend.  It is the
            // engine's own daily_fleet() output, never a fabricated fallback.
            $trend = $this->Dashboard_model->get_availability_trend(30);
            $telemetry['availability_trend'] = is_array($trend) ? $trend : array();

            $telemetry['overdue_pm'] = (int)$this->Dashboard_model->count_overdue_pm();

            if ($this->db && $this->db->table_exists('pm_schedules')) {
                $telemetry['pm_due_30d'] = (int)$this->db
                    ->where('status', 'Active')
                    ->where('next_due_date <=', date('Y-m-d', strtotime('+30 days')))
                    ->count_all_results('pm_schedules');
            }

            // Roll the fleet up from the same per-type status definition the
            // dashboard uses; no second status taxonomy is invented here.
            $by_type = $this->Dashboard_model->get_fleet_kpi_by_type();
            if (is_array($by_type)) {
                foreach ($by_type as $g) {
                    $telemetry['fleet_total']   += isset($g['total']) ? (int)$g['total'] : 0;
                    $telemetry['machines_down'] += isset($g['down']) ? (int)$g['down'] : 0;
                }
            }

            if ($this->db && $this->db->table_exists('equipment')) {
                $telemetry['equipment_list'] = $this->db
                    ->select('id, registration_no, brand, model')
                    ->from('equipment')
                    ->where('status !=', 'Retired')
                    ->order_by('registration_no', 'ASC')
                    ->get()->result();
            }

            $telemetry['store_available'] = (bool)$this->Store_model->available();
            $reorder_rows = $this->Materials_model->get_reorder_items();
            if (is_array($reorder_rows)) {
                $telemetry['reorder_count'] = count($reorder_rows);
                foreach ($reorder_rows as $rr) {
                    if ((int)$rr->final_qty <= 0) $telemetry['out_of_stock_count']++;
                    $telemetry['reorder_units'] += isset($rr->qty_to_order) ? (int)$rr->qty_to_order : 0;
                }
            }

            $telemetry['targets_ready']     = (bool)$this->Kpi_target_model->table_ready();
            $telemetry['w36_snapshot']      = $this->W36_report_model->snapshot_of($week_start);

            /* ── W36 publication ladder ──────────────────────────────────
               Current week:  Draft — Week in progress (Informational)
                              Draft — Publication due soon (Warning)
                              Unpublished — Action required (High)
               Previous week: Published (No action) / overdue (High)
               Only COMPLETED weeks are checked for a missing snapshot, over a
               bounded window, so a report missed three weeks ago cannot vanish
               when a newer week is published.  The deadline itself comes from
               the configured week end — no SLA or setting is invented.      */
            $lookback = self::PUBLICATION_LOOKBACK_WEEKS;
            $window   = $this->_publication_window($week_end);
            $now      = date('Y-m-d H:i:s');
            $from     = date('Y-m-d', strtotime($week_start . ' -' . ($lookback * 7) . ' days'));
            $to       = date('Y-m-d', strtotime($week_start . ' -1 day'));
            $published_starts = $this->W36_report_model->published_week_starts($from, $to);
            $checkable        = ($published_starts !== null);

            $pub = $telemetry['w36_pub'];
            $pub['checkable']    = $checkable;
            $pub['window']       = $lookback;
            $pub['warning_from'] = $window['warning_from'];
            $pub['deadline']     = $window['deadline'];
            $pub['state']        = $this->_publication_state(
                !empty($telemetry['w36_snapshot']),
                $now, $window['warning_from'], $window['deadline']
            );
            if (!empty($telemetry['w36_snapshot']['published_at'])) {
                $pub['published_at'] = $telemetry['w36_snapshot']['published_at'];
            }

            if ($checkable) {
                $completed = array();
                for ($i = 1; $i <= $lookback; $i++) {
                    $s = date('Y-m-d', strtotime($week_start . ' -' . ($i * 7) . ' days'));
                    $e = date('Y-m-d', strtotime($s . ' +6 days'));
                    $completed[] = array(
                        'start'     => $s,
                        'end'       => $e,
                        'iso_label' => $this->Settings_model->iso_week_label($s),
                        'published' => isset($published_starts[$s]),
                    );
                }
                // The just-closed week: deadline passed → "Action required".
                $prev       = $completed[0];
                $prev_win   = $this->_publication_window($prev['end']);
                $prev['state'] = $this->_publication_state(
                    $prev['published'], $now, $prev_win['warning_from'], $prev_win['deadline']
                );
                $pub['previous'] = $prev;

                // Older completed weeks with no snapshot: "Overdue".
                $older = array();
                for ($i = 1; $i < count($completed); $i++) {
                    if (!$completed[$i]['published']) $older[] = $completed[$i];
                }
                $pub['older_count']  = count($older);
                $pub['older_latest'] = $older ? $older[0] : null;
            }
            $telemetry['w36_pub'] = $pub;

            $telemetry['available']         = true;
        } catch (Throwable $e) {
            log_message('error', 'Reports::index telemetry fallback: ' . $e->getMessage());
        }

        $data['telemetry'] = $telemetry;
        $this->render('reports/index', $data);
    }

    public function daily() {
        $date = $this->input->get('date') ?: date('Y-m-d');
        $data['date']        = $date;
        $data['work_orders'] = $this->Workorder_model->get_by_date($date);
        $data['attendance']  = $this->Team_model->get_attendance_by_date($date);
        $data['pm_due']      = $this->db->where('next_due_date', $date)->get('pm_schedules')->result();
        $data['page_title']  = 'Daily Report - '.date('d M Y', strtotime($date));
        $this->render('reports/daily', $data);
    }

    public function weekly() {
        $week_raw  = $this->input->get('week') ?: '';
        if ($week_raw && preg_match('/^\d{4}-W\d{2}$/', $week_raw)) {
            $week_start = date('Y-m-d', strtotime($week_raw));
        } else {
            $week_start = $week_raw ?: $this->Settings_model->week_start();
        }
        $week_end = date('Y-m-d', strtotime($week_start.' +6 days'));
        $data['week_start']  = $week_start;
        $data['week_end']    = $week_end;
        $data['work_orders'] = $this->Workorder_model->get_by_date_range($week_start, $week_end);
        $data['attendance']  = $this->Team_model->get_attendance_range($week_start, $week_end);
        $data['pm_done']     = $this->db->where('next_due_date >=', $week_start)->where('next_due_date <=', $week_end)->get('pm_schedules')->result();
        $data['kpi']         = $this->Dashboard_model->get_weekly_kpi($week_start, $week_end);
        $data['page_title']  = 'Weekly Report';
        $this->render('reports/weekly', $data);
    }

    public function monthly() {
        // Validate the month before it reaches strtotime(): a malformed value
        // returned false there and silently produced a January-1970 report.
        $month = $this->_valid_month($this->input->get('month'));
        $date_from = "{$month}-01";
        $date_to   = date('Y-m-t', strtotime($date_from));
        $data['month']        = $month;
        $data['date_from']    = $date_from;
        $data['date_to']      = $date_to;
        $data['work_orders']  = $this->Workorder_model->get_by_date_range($date_from, $date_to);
        $data['kpi']          = $this->Dashboard_model->get_monthly_kpi($date_from, $date_to);
        $data['attendance']   = $this->Team_model->get_monthly_report($month);
        $data['lube']         = $this->Lubricant_model->get_monthly_summary($month);
        $data['availability'] = $this->Dashboard_model->get_availability_trend(30);
        $data['page_title']   = 'Monthly Report - '.date('F Y', strtotime($date_from));
        $this->render('reports/monthly', $data);
    }

    /* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
       RELIABILITY & PERFORMANCE REPORTS
    ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */

    public function mttr_mtbf() {
        $months = (int)($this->input->get('months') ?: 12);
        $months = max(3, min(24, $months));
        $data   = $this->Dashboard_model->get_mttr_mtbf_report($months);
        $data['page_title'] = 'MTTR / MTBF Report';
        $this->render('reports/mttr_mtbf', $data);
    }

    public function availability_forecast() {
        $horizon = (int)($this->input->get('horizon') ?: 90);
        $horizon = max(7, min(180, $horizon));
        $data    = $this->Dashboard_model->get_availability_forecast($horizon);
        $data['page_title'] = 'PM Due Forecast';
        $this->render('reports/availability_forecast', $data);
    }

    public function monthly_plan() {
        $month = $this->input->get('month') ?: date('Y-m');
        $data  = $this->Dashboard_model->get_monthly_maintenance_plan($month);
        $data['page_title'] = 'Monthly Maintenance Plan — '.date('F Y', strtotime($month.'-01'));
        $this->render('reports/monthly_plan', $data);
    }

    /* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
       LUBRICANT COMPLIANCE — PM-driven requirement vs actual usage
    ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */

    public function lube_compliance() {
        $month = $this->input->get('month') ?: date('Y-m');
        $from  = $month.'-01';
        $to    = date('Y-m-t', strtotime($from));
        $data['month']       = $month;
        $data['from']        = $from;
        $data['to']          = $to;
        $data['rows']        = $this->Lubricant_model->get_compliance_report($from, $to);
        $data['page_title']  = 'Lubricant Compliance — '.date('F Y', strtotime($from));
        $this->render('reports/lube_compliance', $data);
    }

    public function lube_compliance_yearly() {
        $year = (int)($this->input->get('year') ?: date('Y'));
        $data = $this->Lubricant_model->get_yearly_compliance($year);
        $data['page_title'] = 'Lubricant Compliance — '.$year;
        $this->render('reports/lube_compliance_yearly', $data);
    }

    /* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
       STORE ANALYTICS — supplier spend & route lead-times
    ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */

    public function store_analytics() {
        $year = (int)($this->input->get('year') ?: date('Y'));
        $from = $year.'-01-01';
        $to   = $year.'-12-31';

        $spend = $this->Order_model->get_spend_by_supplier($from, $to);
        // K-11: one total per order currency — never a cross-currency sum.
        $spend_by_currency = array();
        foreach ($spend as $s) {
            $cur = wms_currency_code($s->currency);
            $spend_by_currency[$cur] = (isset($spend_by_currency[$cur]) ? $spend_by_currency[$cur] : 0)
                + (float)$s->total_spend;
        }
        arsort($spend_by_currency);

        $order_kpi = $this->Order_model->get_kpi();

        // Discrepancy rate this year
        $this->db->where('order_date >=', $from)->where('order_date <=', $to);
        $orders_this_year = $this->db->count_all_results('spare_orders');
        $this->db->where('order_date >=', $from)->where('order_date <=', $to)
                  ->where('discrepancy_flag !=', 'None');
        $discrepancy_count = $this->db->count_all_results('spare_orders');

        $data['year']               = $year;
        $data['spend']              = $spend;
        $data['spend_by_currency']  = $spend_by_currency;
        $data['lead_times']         = $this->Order_model->get_lead_time_by_route();
        $data['order_kpi']          = $order_kpi;
        $data['orders_this_year']   = $orders_this_year;
        $data['discrepancy_count']  = $discrepancy_count;
        $data['page_title']         = 'Store Analytics — '.$year;
        $this->render('reports/store_analytics', $data);
    }

    public function export_monthly_plan_xlsx() {
        $month = $this->input->get('month') ?: date('Y-m');
        $plan  = $this->Dashboard_model->get_monthly_maintenance_plan($month);
        $this->_export_monthly_plan_xlsx($plan);
    }

    /* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
       ASSET REGISTER — view, export, import, template
    ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */

    public function asset_register() {
        $data = $this->Asset_model->get_register();
        $data['page_title']     = 'Asset Register';
        $data['eq_types']       = $this->Asset_model->get_types();
        $data['import_result']  = $this->_load_import_summary();
        $data['import_history'] = $this->Asset_model->get_import_history(3);
        $this->render('reports/asset_register', $data);
    }

    /** Full register export — every stored field + lifetime KPIs. */
    public function export_asset_register_xlsx() {
        $data = $this->Asset_model->get_register();
        $this->load->library('asset_excel');
        $fname = 'Asset_Register_'.date('Ymd_His').'.xlsx';
        $tmp   = $this->_tmp_path($fname);
        $user  = $this->user_data;
        $this->asset_excel->export_register($data, $tmp, array(
            'title'        => 'ASSET REGISTER',
            'generated_by' => wms_user_full_name($user),
        ));
        $this->_send_file($tmp, $fname);
    }

    /** Empty import template with the exact column structure. */
    public function asset_template_xlsx() {
        $this->load->library('asset_excel');
        $types    = array();
        foreach ($this->Asset_model->get_types() as $t) $types[] = $t->name;
        $fname = 'Asset_Import_Template.xlsx';
        $tmp   = $this->_tmp_path($fname);
        $this->asset_excel->build_template($tmp, $types, Asset_model::STATUSES);
        $this->_send_file($tmp, $fname);
    }

    /**
     * Handle an uploaded workbook: validate every row, import it and show a
     * per-row summary.  Bad rows never stop the good ones.
     */
    public function import_asset_register() {
        if (!$this->require_post()) return;
        if (!$this->is_supervisor()) {
            $this->session->set_flashdata('error', 'Only a supervisor can import asset data.');
            redirect('reports/asset_register');
        }

        $opts = array(
            'duplicate_action' => in_array($this->input->post('duplicate_action'), array('skip','update','fail'), true)
                                  ? $this->input->post('duplicate_action') : 'skip',
            'create_types'     => $this->input->post('create_types') ? 1 : 0,
            'dry_run'          => $this->input->post('dry_run') ? 1 : 0,
        );

        // ── 1. Upload ──────────────────────────────────────────────
        $upload_path = FCPATH.self::ASSET_IMPORT_TMP;
        if (!is_dir($upload_path)) @mkdir($upload_path, 0755, true);

        $config = array(
            'upload_path'   => $upload_path,
            'allowed_types' => 'xlsx',
            'max_size'      => 8192,
            'file_name'     => 'asset_import_'.date('YmdHis').'_'.mt_rand(1000, 9999),
            'overwrite'     => false,
        );
        $this->load->library('upload', $config);

        if (!$this->upload->do_upload('asset_file')) {
            $err = $this->upload->display_errors('', '');
            $this->session->set_flashdata('error', 'Import failed — '.$this->_clean_upload_error($err).' '
                .'Download the template to make sure the file is in the right format.');
            redirect('reports/asset_register');
        }
        $file = $this->upload->data();
        $path = $file['full_path'];

        try {
            // ── 2. Parse ───────────────────────────────────────────
            $this->load->library('xlsx_reader');
            $this->load->library('asset_importer', array('model' => $this->Asset_model));

            $sheet = $this->xlsx_reader->read($path, array('Assets', 'Asset Register', 'Sheet1'), 0, array(
                'header_hints' => array_keys(Asset_importer::expected_columns()),
            ));
            if ($sheet === false) {
                throw new RuntimeException($this->xlsx_reader->last_error());
            }

            $result = $this->asset_importer->import($sheet, $opts + array('file_name' => $file['client_name']));

            // ── 3. Store the full report for download, keep the summary in the session ──
            //     The token is created first so the parked report knows its own
            //     download link (and exactly one temp file is written per import).
            $token = $this->_new_import_token();
            $result['report_token'] = $token;
            $this->_park_import_summary($result, $token);
            $this->Asset_model->log_import(array(
                'user_id' => $this->user_id, 'file_name' => $file['client_name'],
                'mode' => $result['mode'], 'duplicate_action' => $result['duplicate_action'],
                'total_rows' => $result['summary']['total_rows'],
                'imported' => $result['summary']['inserted'],
                'updated' => $result['summary']['updated'],
                'skipped' => $result['summary']['skipped'],
                'failed' => $result['summary']['failed'],
            ));

            $s = $result['summary'];
            if (!empty($result['errors'])) {
                $this->session->set_flashdata('error', 'Import not started — '.implode(' ', $result['errors']));
            } elseif ($result['mode'] === 'validate') {
                $this->session->set_flashdata('success', sprintf(
                    'Validation finished: %d row(s) checked, %d ready to import, %d skipped, %d with errors. Nothing was saved.',
                    $s['total_rows'], $s['inserted'] + $s['updated'], $s['skipped'], $s['failed']));
            } else {
                $this->session->set_flashdata('success', sprintf(
                    'Import finished: %d created, %d updated, %d skipped, %d failed out of %d row(s).',
                    $s['inserted'], $s['updated'], $s['skipped'], $s['failed'], $s['total_rows']));
            }
        } catch (Exception $e) {
            $this->session->set_flashdata('error', 'Import failed — '.$e->getMessage());
        }

        @unlink($path);
        redirect('reports/asset_register');
    }

    /** Excel download with the per-row import result (successes + failures). */
    public function asset_import_report($token = '') {
        $result = $this->_read_import_report($token);
        if ($result === null) {
            $this->session->set_flashdata('error', 'That import report is no longer available — run the import again.');
            redirect('reports/asset_register');
        }
        $this->load->library('asset_excel');
        $fname = 'Asset_Import_Report_'.date('Ymd_His').'.xlsx';
        $tmp   = $this->_tmp_path($fname);
        $this->asset_excel->import_report($result, $tmp);
        $this->_send_file($tmp, $fname);
    }

    /* ── Asset register helpers ───────────────────────────── */

    private function _clean_upload_error($msg) {
        $msg = trim((string)(strip_tags((string)$msg)));
        $msg = str_replace(array('The filetype you are attempting to upload is not allowed.',
                                 'The uploaded file exceeds the maximum allowed size.'), '', $msg);
        return trim((string)($msg)) !== '' ? trim((string)($msg)) : 'the file could not be uploaded. Save the workbook as .xlsx and try again.';
    }

    private function _tmp_path($fname) {
        $dir = FCPATH.self::ASSET_IMPORT_TMP;
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        return $dir.$fname;
    }

    /** Send a generated file to the browser and remove it from the server. */
    private function _send_file($path, $downloadName) {
        if (!file_exists($path)) {
            show_error('Could not generate the Excel file. Check that uploads/tmp/ is writable.', 500);
            return;
        }
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="'.$downloadName.'"');
        header('Content-Length: '.filesize($path));
        header('Cache-Control: must-revalidate, max-age=0');
        header('Pragma: public');
        readfile($path);
        @unlink($path);
        exit;
    }

    /**
     * Park the full import result in a temp file: the session only carries a
     * token + the summary, so large imports cannot blow up the session store.
     * Returns the token.
     */
    private function _new_import_token() {
        return function_exists('random_bytes')
             ? bin2hex(random_bytes(8))
             : substr(md5(uniqid('wms_asset_import', true)), 0, 16);
    }

    private function _park_import_summary($result, $token = null) {
        if ($token === null) $token = $this->_new_import_token();
        $this->_prune_import_reports();
        $payload = array(
            'user_id' => (int)$this->user_id,
            'created' => time(),
            'result'  => $result,
        );
        file_put_contents($this->_tmp_path('asset_import_'.$token.'.json'), json_encode($payload));
        // the register page reads this on the next request only
        $this->session->set_flashdata('asset_import_token', $token);
        return $token;
    }

    private function _read_import_report($token) {
        if (!preg_match('/^[a-f0-9]{16}$/', (string)$token)) return null;
        $file = $this->_tmp_path('asset_import_'.$token.'.json');
        if (!is_file($file)) return null;
        $payload = json_decode(file_get_contents($file), true);
        if (!is_array($payload) || !isset($payload['result'])) return null;
        if ((int)$payload['user_id'] !== (int)$this->user_id) return null;
        if ($payload['created'] < time() - 86400) { @unlink($file); return null; }
        return $payload['result'];
    }

    /** Summary of the last import (token + counters) for the register page. */
    private function _load_import_summary() {
        $token = $this->session->flashdata('asset_import_token');
        if (!$token) return null;
        $result = $this->_read_import_report($token);
        if ($result === null) return null;
        $result['report_token'] = $token;
        return $result;
    }

    /** Remove report files older than 24h (best effort housekeeping). */
    private function _prune_import_reports() {
        foreach (glob($this->_tmp_path('asset_import_*.json')) as $f) {
            if (is_file($f) && filemtime($f) < time() - 86400) @unlink($f);
        }
    }

    private function _export_monthly_plan_xlsx($plan) {
        $this->load->helper('xlsx');
        $xlsx = new XlsxWriter();
        $xlsx->addSheet('Monthly Plan');

        $colW = [1=>14,2=>10,3=>22,4=>18,5=>22,6=>14,7=>14,8=>12,9=>18,10=>14];
        foreach ($colW as $c=>$w) $xlsx->setColWidth($c, $w);

        $sTitle = 'bold|fontSize:13|align:center|valign:middle|bg:1F4E79|fg:FFFFFF';
        $sHdr   = 'bold|fontSize:9|align:center|valign:middle|bg:2E75B6|fg:FFFFFF|border:thin|wrap';
        $sWk    = 'bold|fontSize:10|align:left|valign:middle|bg:BDD7EE|fg:1F4E79|border:thin';
        $sCell  = 'fontSize:9|valign:middle|border:thin|wrap';
        $sCellC = 'fontSize:9|align:center|valign:middle|border:thin';
        $sPm    = 'fontSize:9|valign:middle|border:thin|bg:E2EFDA';
        $sCor   = 'fontSize:9|valign:middle|border:thin|bg:FFF2CC';
        $sPrio  = ['Critical'=>'bold|fontSize:9|align:center|valign:middle|border:thin|bg:FFD7D7|fg:C00000',
                   'High'    =>'bold|fontSize:9|align:center|valign:middle|border:thin|bg:FFF2CC|fg:7F6000',
                   'Medium'  =>'fontSize:9|align:center|valign:middle|border:thin',
                   'Low'     =>'fontSize:9|align:center|valign:middle|border:thin|fg:888888'];

        foreach (array_merge([$sTitle,$sHdr,$sWk,$sCell,$sCellC,$sPm,$sCor], array_values($sPrio)) as $s) {
            $xlsx->registerStyle($s);
        }

        // Title
        $xlsx->setRowHeight(1, 28);
        $tc = [['v'=>'MONTHLY MAINTENANCE PLAN — '.strtoupper((string)(date('F Y',strtotime($plan['month'].'-01')))),'s'=>$sTitle]];
        for ($i=1;$i<10;$i++) $tc[] = ['v'=>'','s'=>$sTitle];
        $xlsx->writeRowStyled($tc);
        $xlsx->mergeCells('A1','J1');

        // Header
        $xlsx->setRowHeight(2,36);
        $xlsx->writeRow(['TYPE','MACHINE ID','MODEL','LOCATION','PM / TASK','DUE DATE','DUE HOURS','PRIORITY','LAST DONE','STATUS'],$sHdr);

        $row = 3;

        // PM by week
        foreach ($plan['weeks'] as $wk) {
            if (empty($wk['pm'])) continue;
            $xlsx->setRowHeight($row, 22);
            $wkCells = [['v'=>'📅 '.$wk['label'],'s'=>$sWk]];
            for ($i=1;$i<10;$i++) $wkCells[]=(['v'=>'','s'=>$sWk]);
            $xlsx->writeRowStyled($wkCells);
            $xlsx->mergeCells("A{$row}","J{$row}");
            $row++;

            foreach ($wk['pm'] as $pm) {
                $xlsx->setRowHeight($row, 24);
                $xlsx->writeRowStyled([
                    ['v'=>strtoupper((string)($pm->equip_type??'')),'s'=>$sCellC],
                    ['v'=>$pm->registration_no,'s'=>$sCellC],
                    ['v'=>($pm->brand??'').' '.($pm->model??''),'s'=>$sPm],
                    ['v'=>$pm->location,'s'=>$sCellC],
                    ['v'=>$pm->schedule_name,'s'=>$sPm],
                    ['v'=>$pm->next_due_date ? date('d/m/Y',strtotime($pm->next_due_date)) : '','s'=>$sCellC],
                    ['v'=>$pm->next_due_hours ? number_format((float)($pm->next_due_hours),0).' h' : '','s'=>$sCellC],
                    ['v'=>'PM','s'=>$sCellC],
                    ['v'=>$pm->last_done_date ? date('d/m/Y',strtotime($pm->last_done_date)) : '','s'=>$sCellC],
                    ['v'=>'Scheduled','s'=>$sCellC],
                ]);
                $row++;
            }
        }

        // Corrective WOs
        if (!empty($plan['corrective'])) {
            $xlsx->setRowHeight($row, 22);
            $hc = [['v'=>'🔧 Corrective / Open Work Orders','s'=>$sWk]];
            for ($i=1;$i<10;$i++) $hc[]=['v'=>'','s'=>$sWk];
            $xlsx->writeRowStyled($hc);
            $xlsx->mergeCells("A{$row}","J{$row}");
            $row++;
            foreach ($plan['corrective'] as $wo) {
                $ps = $sPrio[$wo->priority] ?? $sCellC;
                $xlsx->setRowHeight($row, 24);
                $xlsx->writeRowStyled([
                    ['v'=>strtoupper((string)($wo->equip_type??'')),'s'=>$sCellC],
                    ['v'=>$wo->registration_no,'s'=>$sCellC],
                    ['v'=>($wo->brand??'').' '.($wo->model??''),'s'=>$sCor],
                    ['v'=>$wo->location,'s'=>$sCellC],
                    ['v'=>$wo->title,'s'=>$sCor],
                    ['v'=>$wo->end_datetime ? date('d/m/Y',strtotime($wo->end_datetime)) : 'TBD','s'=>$sCellC],
                    ['v'=>'','s'=>$sCellC],
                    ['v'=>$wo->priority,'s'=>$ps],
                    ['v'=>$wo->start_datetime ? date('d/m/Y',strtotime($wo->start_datetime)) : '','s'=>$sCellC],
                    ['v'=>$wo->status,'s'=>$sCellC],
                ]);
                $row++;
            }
        }

        $fname = 'Monthly_Plan_'.str_replace('-','_',$plan['month']).'.xlsx';
        $tmp   = wms_temp_path($fname);
        $xlsx->save($tmp);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="'.$fname.'"');
        header('Content-Length: '.filesize($tmp));
        readfile($tmp); unlink($tmp); exit;
    }

    /* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
       ANNUAL REPORT
    ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */

    public function annual_report() {
        $year = (int)($this->input->get('year') ?: date('Y'));
        $year = max(2020, min((int)date('Y'), $year));
        $data = $this->Dashboard_model->get_annual_report($year);
        $data['page_title'] = 'Annual Report '.$year;
        $data['years'] = range((int)date('Y'), 2020);
        $this->render('reports/annual_report', $data);
    }

    public function export_annual_report_xlsx() {
        $year = (int)($this->input->get('year') ?: date('Y'));
        $data = $this->Dashboard_model->get_annual_report($year);
        $this->_export_annual_report_xlsx($data);
    }

    /* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
       ANNUAL MAINTENANCE PLAN
    ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */

    public function annual_plan() {
        $year = (int)($this->input->get('year') ?: date('Y'));
        $data = $this->Dashboard_model->get_annual_plan($year);
        $data['page_title'] = 'Annual Maintenance Plan '.$year;
        $data['years'] = range((int)date('Y') + 1, 2020);
        $this->render('reports/annual_plan', $data);
    }

    public function export_annual_plan_xlsx() {
        $year = (int)($this->input->get('year') ?: date('Y'));
        $data = $this->Dashboard_model->get_annual_plan($year);
        $this->_export_annual_plan_xlsx($data);
    }

    /* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
       SERVICE KIT RECOMMENDATIONS
    ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */

    public function service_kits() {
        $data = $this->Dashboard_model->get_service_kit_recommendations();
        $data['page_title'] = 'Service Kit Recommendations';
        $this->render('reports/service_kits', $data);
    }

    public function export_service_kits_xlsx() {
        $data = $this->Dashboard_model->get_service_kit_recommendations();
        $this->_export_service_kits_xlsx($data);
    }

    /* ─── XLSX: Annual Report ─────────────────────────── */

    private function _export_annual_report_xlsx($data) {
        $this->load->helper('xlsx');
        $xlsx = new XlsxWriter();
        $year = $data['year'];

        // Sheet 1 — Monthly KPI Trend
        $xlsx->addSheet('Monthly KPI Trend');
        foreach([1=>14,2=>10,3=>10,4=>10,5=>10,6=>12,7=>12,8=>12,9=>12] as $c=>$w) $xlsx->setColWidth($c,$w);

        $sTitle='bold|fontSize:13|align:center|valign:middle|bg:1F4E79|fg:FFFFFF';
        $sHdr  ='bold|fontSize:9|align:center|valign:middle|bg:2E75B6|fg:FFFFFF|border:thin';
        $sCell ='fontSize:9|align:center|valign:middle|border:thin';
        $sPctG ='bold|fontSize:9|align:center|valign:middle|border:thin|numFmt:0%|bg:E2EFDA|fg:375623';
        $sPctW ='bold|fontSize:9|align:center|valign:middle|border:thin|numFmt:0%|bg:FFF2CC|fg:7F6000';
        $sPctR ='bold|fontSize:9|align:center|valign:middle|border:thin|numFmt:0%|bg:FFD7D7|fg:C00000';
        foreach([$sTitle,$sHdr,$sCell,$sPctG,$sPctW,$sPctR] as $s) $xlsx->registerStyle($s);

        $xlsx->setRowHeight(1,28);
        $tc=[['v'=>'ANNUAL REPORT '.$year.' — Monthly KPI Trend','s'=>$sTitle]];
        for($i=1;$i<9;$i++) $tc[]=['v'=>'','s'=>$sTitle];
        $xlsx->writeRowStyled($tc); $xlsx->mergeCells('A1','I1');

        $xlsx->setRowHeight(2,32);
        $xlsx->writeRow(['MONTH','WOs','BREAKDOWNS','PM DONE','COMPLETED','DOWNTIME (h)','AVAILABILITY%','MTTR (h)','MTBF (h)'],$sHdr);

        foreach ($data['monthly'] as $row) {
            $av = $row['availability']/100;
            $sp = $av>=0.9?$sPctG:($av>=0.7?$sPctW:$sPctR);
            $xlsx->setRowHeight(null,22);
            $xlsx->writeRowStyled([
                ['v'=>date('F Y',strtotime($row['month'].'-01')),'s'=>$sCell],
                ['v'=>$row['wo_total'],'s'=>$sCell],
                ['v'=>$row['breakdowns'],'s'=>$sCell],
                ['v'=>$row['wo_total']-$row['breakdowns'],'s'=>$sCell],
                ['v'=>$row['completed'],'s'=>$sCell],
                ['v'=>$row['downtime'],'s'=>$sCell],
                ['v'=>$av,'s'=>$sp],
                ['v'=>$row['mttr'],'s'=>$sCell],
                ['v'=>$row['mtbf'],'s'=>$sCell],
            ]);
        }

        // Sheet 2 — Equipment Reliability
        $xlsx->addSheet('Equipment Reliability');
        foreach([1=>12,2=>12,3=>20,4=>12,5=>10,6=>14,7=>10,8=>10,9=>12] as $c=>$w) $xlsx->setColWidth($c,$w);
        $xlsx->setRowHeight(1,28);
        $tc2=[['v'=>'ANNUAL REPORT '.$year.' — Equipment Reliability','s'=>$sTitle]];
        for($i=1;$i<9;$i++) $tc2[]=['v'=>'','s'=>$sTitle];
        $xlsx->writeRowStyled($tc2); $xlsx->mergeCells('A1','I1');
        $xlsx->setRowHeight(2,32);
        $xlsx->writeRow(['TYPE','MACHINE ID','MODEL','LOCATION','BREAKDOWNS','DOWNTIME (h)','MTTR (h)','MTBF (h)','AVAILABILITY%'],$sHdr);
        foreach ($data['eq_reliability'] as $eq) {
            $av=$eq['availability']/100;
            $sp=$av>=0.9?$sPctG:($av>=0.7?$sPctW:$sPctR);
            $xlsx->setRowHeight(null,22);
            $xlsx->writeRowStyled([
                ['v'=>$eq['type'],'s'=>$sCell],['v'=>$eq['machine_id'],'s'=>$sCell],
                ['v'=>trim((string)($eq['model'])),'s'=>$sCell],['v'=>$eq['location'],'s'=>$sCell],
                ['v'=>$eq['breakdowns'],'s'=>$sCell],['v'=>$eq['downtime'],'s'=>$sCell],
                ['v'=>$eq['mttr'],'s'=>$sCell],['v'=>$eq['mtbf'],'s'=>$sCell],
                ['v'=>$av,'s'=>$sp],
            ]);
        }

        $fname='Annual_Report_'.$year.'.xlsx';
        $tmp=wms_temp_path($fname);
        $xlsx->save($tmp);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="'.$fname.'"');
        header('Content-Length: '.filesize($tmp));
        readfile($tmp); unlink($tmp); exit;
    }

    /* ─── XLSX: Annual Plan ───────────────────────────── */

    private function _export_annual_plan_xlsx($data) {
        $this->load->helper('xlsx');
        $xlsx = new XlsxWriter();
        $year = $data['year'];

        $xlsx->addSheet('Annual Plan '.$year);
        foreach([1=>8,2=>14,3=>22,4=>14,5=>26,6=>12,7=>14,8=>14] as $c=>$w) $xlsx->setColWidth($c,$w);

        $sTitle='bold|fontSize:13|align:center|valign:middle|bg:1F4E79|fg:FFFFFF';
        $sHdr  ='bold|fontSize:9|align:center|valign:middle|bg:2E75B6|fg:FFFFFF|border:thin|wrap';
        $sMhdr ='bold|fontSize:11|align:left|valign:middle|bg:BDD7EE|fg:1F4E79|border:thin';
        $sCell ='fontSize:9|valign:middle|border:thin';
        $sCellC='fontSize:9|align:center|valign:middle|border:thin';
        $sPm   ='fontSize:9|valign:middle|border:thin|bg:E2EFDA';
        foreach([$sTitle,$sHdr,$sMhdr,$sCell,$sCellC,$sPm] as $s) $xlsx->registerStyle($s);

        $xlsx->setRowHeight(1,28);
        $tc=[['v'=>'ANNUAL MAINTENANCE PLAN — '.$year,'s'=>$sTitle]];
        for($i=1;$i<8;$i++) $tc[]=['v'=>'','s'=>$sTitle];
        $xlsx->writeRowStyled($tc); $xlsx->mergeCells('A1','H1');

        $xlsx->setRowHeight(2,32);
        $xlsx->writeRow(['MONTH','MACHINE','MODEL','LOCATION','PM TASK','INTERVAL','DUE DATE','DUE HOURS'],$sHdr);

        $row=3;
        foreach ($data['months'] as $mo) {
            if (empty($mo['pm'])) continue;
            $xlsx->setRowHeight($row,22);
            $mhCells=[['v'=>'📅 '.$mo['label'].' '.$year.' — '.$mo['count'].' PM job'.($mo['count']>1?'s':''),'s'=>$sMhdr]];
            for($i=1;$i<8;$i++) $mhCells[]=['v'=>'','s'=>$sMhdr];
            $xlsx->writeRowStyled($mhCells);
            $xlsx->mergeCells("A{$row}","H{$row}");
            $row++;
            foreach ($mo['pm'] as $pm) {
                $xlsx->setRowHeight($row,22);
                $xlsx->writeRowStyled([
                    ['v'=>$mo['label'],'s'=>$sCellC],
                    ['v'=>$pm->registration_no,'s'=>$sCellC],
                    ['v'=>($pm->brand??'').' '.($pm->model??''),'s'=>$sPm],
                    ['v'=>$pm->location,'s'=>$sCellC],
                    ['v'=>$pm->schedule_name,'s'=>$sPm],
                    ['v'=>$pm->interval_value.' '.$pm->interval_type,'s'=>$sCellC],
                    ['v'=>$pm->next_due_date ? date('d/m/Y',strtotime($pm->next_due_date)) : '','s'=>$sCellC],
                    ['v'=>$pm->next_due_hours ? number_format((float)($pm->next_due_hours),0).' h' : '','s'=>$sCellC],
                ]);
                $row++;
            }
        }

        $fname='Annual_Maintenance_Plan_'.$year.'.xlsx';
        $tmp=wms_temp_path($fname);
        $xlsx->save($tmp);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="'.$fname.'"');
        header('Content-Length: '.filesize($tmp));
        readfile($tmp); unlink($tmp); exit;
    }

    /* ─── XLSX: Service Kits ──────────────────────────── */

    private function _export_service_kits_xlsx($data) {
        $this->load->helper('xlsx');
        $xlsx = new XlsxWriter();

        $sTitle='bold|fontSize:12|align:center|valign:middle|bg:1F4E79|fg:FFFFFF';
        $sHdr  ='bold|fontSize:8|align:center|valign:middle|bg:2E75B6|fg:FFFFFF|border:thin|wrap';
        $sMach ='bold|fontSize:10|align:left|valign:middle|bg:BDD7EE|fg:1F4E79|border:thin';
        $sSec  ='bold|fontSize:9|align:left|valign:middle|bg:DEEAF1|border:thin';
        $sCell ='fontSize:9|valign:middle|border:thin';
        $sCellC='fontSize:9|align:center|valign:middle|border:thin';
        $sNum  ='fontSize:9|align:center|valign:middle|border:thin|numFmt:#,##0.00';
        foreach([$sTitle,$sHdr,$sMach,$sSec,$sCell,$sCellC,$sNum] as $s) $xlsx->registerStyle($s);

        foreach ($data['grouped'] as $type => $kits) {
            $xlsx->addSheet($type.'s');
            foreach([1=>14,2=>28,3=>18,4=>12,5=>12,6=>10,7=>12] as $c=>$w) $xlsx->setColWidth($c,$w);

            $xlsx->setRowHeight(1,26);
            $tc=[['v'=>'SERVICE KIT RECOMMENDATIONS — '.$type.'s — '.date('d F Y'),'s'=>$sTitle]];
            for($i=1;$i<7;$i++) $tc[]=['v'=>'','s'=>$sTitle];
            $xlsx->writeRowStyled($tc); $xlsx->mergeCells('A1','G1');

            $row=2;
            foreach ($kits as $kit) {
                // Machine header
                $xlsx->setRowHeight($row,24);
                $mc=[['v'=>'🔧 '.$kit['machine_id'].' — '.$kit['model'].' | '.$kit['location'].' | '.number_format((float)($kit['hours']),0).' h','s'=>$sMach]];
                for($i=1;$i<7;$i++) $mc[]=['v'=>'','s'=>$sMach];
                $xlsx->writeRowStyled($mc); $xlsx->mergeCells("A{$row}","G{$row}"); $row++;

                // Parts section
                if (!empty($kit['parts'])) {
                    $xlsx->setRowHeight($row,20);
                    $sh=[['v'=>'SPARE PARTS (Historical usage)','s'=>$sSec]];
                    for($i=1;$i<7;$i++) $sh[]=['v'=>'','s'=>$sSec];
                    $xlsx->writeRowStyled($sh); $xlsx->mergeCells("A{$row}","G{$row}"); $row++;
                    $xlsx->setRowHeight($row,28);
                    $xlsx->writeRow(['PART NAME','PART NUMBER','UNIT','TIMES USED','TOTAL QTY','AVG COST','NOTES'],$sHdr); $row++;
                    foreach ($kit['parts'] as $p) {
                        $xlsx->setRowHeight($row,20);
                        $xlsx->writeRowStyled([
                            ['v'=>$p->part_name,'s'=>$sCell],
                            ['v'=>$p->part_number??'','s'=>$sCellC],
                            ['v'=>$p->unit,'s'=>$sCellC],
                            ['v'=>(int)$p->times_used,'s'=>$sCellC],
                            ['v'=>(float)$p->total_qty,'s'=>$sCellC],
                            ['v'=>(float)($p->avg_cost??0),'s'=>$sNum],
                            ['v'=>'','s'=>$sCell],
                        ]); $row++;
                    }
                }

                // Lubricants section
                if (!empty($kit['lubes'])) {
                    $xlsx->setRowHeight($row,20);
                    $sl=[['v'=>'LUBRICANTS & OILS','s'=>$sSec]];
                    for($i=1;$i<7;$i++) $sl[]=['v'=>'','s'=>$sSec];
                    $xlsx->writeRowStyled($sl); $xlsx->mergeCells("A{$row}","G{$row}"); $row++;
                    $xlsx->setRowHeight($row,28);
                    $xlsx->writeRow(['LUBRICANT NAME','CATEGORY','GRADE','UNIT','TOTAL USED','TIMES',''],$sHdr); $row++;
                    foreach ($kit['lubes'] as $l) {
                        $xlsx->setRowHeight($row,20);
                        $xlsx->writeRowStyled([
                            ['v'=>$l->name,'s'=>$sCell],
                            ['v'=>$l->category,'s'=>$sCellC],
                            ['v'=>$l->grade??'','s'=>$sCellC],
                            ['v'=>$l->unit,'s'=>$sCellC],
                            ['v'=>(float)$l->total_used,'s'=>$sCellC],
                            ['v'=>(int)$l->times,'s'=>$sCellC],
                            ['v'=>'','s'=>$sCell],
                        ]); $row++;
                    }
                }
                $row++; // blank row between machines
            }
        }

        $fname='Service_Kit_Recommendations_'.date('Ymd').'.xlsx';
        $tmp=wms_temp_path($fname);
        $xlsx->save($tmp);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="'.$fname.'"');
        header('Content-Length: '.filesize($tmp));
        readfile($tmp); unlink($tmp); exit;
    }

    public function equipment_history($id = null) {
        // The Reports hub offers a machine picker (a GET form).  The canonical
        // route still passes the id as a path segment; accepting the same id
        // from the query string keeps that picker usable without JavaScript.
        if ($id === null || $id === '') $id = $this->input->get('id', TRUE);
        $id = is_scalar($id) ? (int)$id : 0;
        if ($id <= 0) show_404();
        $data['equipment']   = $this->Equipment_model->get_by_id($id);
        if (!$data['equipment']) show_404();
        $data['work_orders'] = $this->Workorder_model->get_by_equipment($id, 100);
        $data['pm_history']  = $this->db->where('equipment_id', $id)->get('pm_completions')->result();
        $data['lube_history']= $this->Lubricant_model->get_by_equipment($id);
        $data['kpi']         = $this->Equipment_model->get_equipment_kpi($id);
        $data['page_title']  = 'Equipment History: '.$data['equipment']->registration_no;
        $this->render('reports/equipment_history', $data);
    }

    /* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
       MATERIALS MOVEMENT REPORT
    ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */

    public function materials_movement() {
        $filter = [
            'category' => $this->input->get('category'),
            'location' => $this->input->get('location'),
            'search'   => $this->input->get('search'),
        ];
        $data['materials']   = $this->Materials_model->get_all($filter);
        $data['categories']  = $this->Materials_model->get_categories();
        $data['locations']   = $this->Materials_model->get_locations();
        $data['filter']      = $filter;
        $data['page_title']  = 'Materials Movement';
        $this->render('reports/materials_movement', $data);
    }

    public function export_materials_xlsx() {
        $this->load->helper('xlsx');
        $filter = [
            'category' => $this->input->get('category'),
            'location' => $this->input->get('location'),
            'search'   => $this->input->get('search'),
        ];
        $rows = $this->Materials_model->get_all($filter);
        $this->_export_materials_xlsx($rows);
    }

    private function _export_materials_xlsx($rows) {
        $xlsx = new XlsxWriter();
        $xlsx->addSheet('Materials Movement');

        // ── Column widths (matching template) ──
        $xlsx->setColWidth(1, 14);  // Item Code
        $xlsx->setColWidth(2, 15);  // Category
        $xlsx->setColWidth(3, 30);  // Description
        $xlsx->setColWidth(4, 18);  // Part No/Size
        $xlsx->setColWidth(5,  7);  // Unit
        $xlsx->setColWidth(6, 14);  // Initial Stock
        $xlsx->setColWidth(7, 14);  // Qty Received
        $xlsx->setColWidth(8, 12);  // Qty Sortie
        $xlsx->setColWidth(9, 18);  // Final Qty
        $xlsx->setColWidth(10, 18); // Application
        $xlsx->setColWidth(11, 14); // Location

        // ── Register styles ──
        $sTitle  = 'bold|fontSize:14|fontName:Arial|align:center|valign:middle|bg:1F4E79|fg:FFFFFF|border:thin';
        $sHdr    = 'bold|fontSize:10|fontName:Arial|align:center|valign:middle|bg:2E75B6|fg:FFFFFF|border:thin|wrap';
        $sCell   = 'fontSize:10|fontName:Arial|valign:middle|border:thin';
        $sCellC  = 'fontSize:10|fontName:Arial|align:center|valign:middle|border:thin';
        $sNum    = 'fontSize:10|fontName:Arial|align:center|valign:middle|border:thin|numFmt:#,##0';
        $sFrmla  = 'bold|fontSize:10|fontName:Arial|align:center|valign:middle|border:thin|numFmt:#,##0|bg:EBF3FB';
        $sLowStk = 'bold|fontSize:10|fontName:Arial|align:center|valign:middle|border:thin|numFmt:#,##0|bg:FFE0E0|fg:C00000';
        $sTotal  = 'bold|fontSize:10|fontName:Arial|align:right|valign:middle|border:thin|bg:D6E4F0';
        $sTotalN = 'bold|fontSize:10|fontName:Arial|align:center|valign:middle|border:thin|numFmt:#,##0|bg:D6E4F0';

        foreach ([$sTitle,$sHdr,$sCell,$sCellC,$sNum,$sFrmla,$sLowStk,$sTotal,$sTotalN] as $s) {
            $xlsx->registerStyle($s);
        }

        // ── Row 1: Title (merged A1:K1) ──
        $xlsx->setRowHeight(1, 30);
        $xlsx->writeRowStyled([
            ['v'=>'MATERIALS MOVEMENT','s'=>$sTitle],
            ['v'=>'','s'=>$sTitle],['v'=>'','s'=>$sTitle],['v'=>'','s'=>$sTitle],
            ['v'=>'','s'=>$sTitle],['v'=>'','s'=>$sTitle],['v'=>'','s'=>$sTitle],
            ['v'=>'','s'=>$sTitle],['v'=>'','s'=>$sTitle],['v'=>'','s'=>$sTitle],
            ['v'=>'','s'=>$sTitle],
        ]);
        $xlsx->mergeCells('A1','K1');

        // ── Row 2: Headers ──
        $xlsx->setRowHeight(2, 38);
        $headers = ['Item Code','Category','Description','Part Number / Size','Unit',
                    'Initial STOCK','Qty Received','Qty Sortie','Final Qty in Stock','Application','Location'];
        $xlsx->writeRow($headers, $sHdr);

        // ── Data rows ──
        $dataRowStart = 3;
        if (empty($rows)) {
            $xlsx->writeRowStyled(array_fill(0, 11, ['v'=>'','s'=>$sCell]));
        } else {
            foreach ($rows as $i => $r) {
                $rowNum = $dataRowStart + $i;
                // Final Qty as formula: =F{n}+G{n}-H{n}
                $fQty = "=F{$rowNum}+G{$rowNum}-H{$rowNum}";
                // Highlight low stock (final_qty <= 5 AND not zero initial)
                $finalQtyStyle = ($r->final_qty <= 5 && $r->initial_stock + $r->qty_received > 0) ? $sLowStk : $sFrmla;

                $xlsx->setRowHeight($rowNum, 18);
                $xlsx->writeRowStyled([
                    ['v'=>$r->item_code,        's'=>$sCellC],
                    ['v'=>$r->category,         's'=>$sCell],
                    ['v'=>$r->description,      's'=>$sCell],
                    ['v'=>$r->part_number_size ?? '', 's'=>$sCellC],
                    ['v'=>$r->unit,             's'=>$sCellC],
                    ['v'=>(int)$r->initial_stock,  's'=>$sNum],
                    ['v'=>(int)$r->qty_received,   's'=>$sNum],
                    ['v'=>(int)$r->qty_sortie,     's'=>$sNum],
                    ['v'=>$fQty,                's'=>$finalQtyStyle],
                    ['v'=>$r->application ?? '','s'=>$sCell],
                    ['v'=>$r->location ?? '',   's'=>$sCell],
                ]);
            }

            // ── Totals row ──
            $n   = count($rows);
            $end = $dataRowStart + $n - 1;
            $xlsx->setRowHeight($end+1, 20);
            $xlsx->writeRowStyled([
                ['v'=>'','s'=>$sTotal],
                ['v'=>'','s'=>$sTotal],
                ['v'=>'','s'=>$sTotal],
                ['v'=>'','s'=>$sTotal],
                ['v'=>'TOTAL','s'=>$sTotal],
                ['v'=>"=SUM(F{$dataRowStart}:F{$end})",'s'=>$sTotalN],
                ['v'=>"=SUM(G{$dataRowStart}:G{$end})",'s'=>$sTotalN],
                ['v'=>"=SUM(H{$dataRowStart}:H{$end})",'s'=>$sTotalN],
                ['v'=>"=SUM(I{$dataRowStart}:I{$end})",'s'=>$sTotalN],
                ['v'=>'','s'=>$sTotal],
                ['v'=>'','s'=>$sTotal],
            ]);
        }

        $filename = 'Materials_Movement_'.date('Ymd').'.xlsx';
        $tmp      = wms_temp_path($filename);
        $xlsx->save($tmp);

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        header('Content-Length: '.filesize($tmp));
        readfile($tmp);
        unlink($tmp);
        exit;
    }

    /* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
       WORKSHOP WEEKLY REPORT
    ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */

    public function workshop_weekly() {
        $week_start = $this->_resolve_report_date(
            $this->input->get('week_start', TRUE),
            $this->Settings_model->week_start()
        );
        $week_end   = date('Y-m-d', strtotime($week_start.' +6 days'));
        $data['week_start'] = $week_start;
        $data['week_end']   = $week_end;
        $data['report']     = $this->_build_workshop_weekly($week_start, $week_end);
        $data['page_title'] = 'Workshop Weekly Report — '.date('d M', strtotime($week_start)).' to '.date('d M Y', strtotime($week_end));
        $this->render('reports/workshop_weekly', $data);
    }

    public function export_workshop_weekly_xlsx() {
        $week_start = $this->_resolve_report_date(
            $this->input->get('week_start', TRUE),
            $this->Settings_model->week_start()
        );
        $week_end   = date('Y-m-d', strtotime($week_start.' +6 days'));
        $report     = $this->_build_workshop_weekly($week_start, $week_end);
        $this->_export_workshop_weekly_xlsx($report, $week_start, $week_end);
    }

    public function export_workshop_weekly_pdf() {
        $week_start = $this->_resolve_report_date(
            $this->input->get('week_start', TRUE),
            $this->Settings_model->week_start()
        );
        $week_end   = date('Y-m-d', strtotime($week_start.' +6 days'));
        $report     = $this->_build_workshop_weekly($week_start, $week_end);
        $this->_export_workshop_weekly_pdf($report, $week_start, $week_end);
    }

    private function _build_workshop_weekly($week_start, $week_end) {
        // W0.7: the open hours, the week start and the fallback target are settings,
        // never constants (seeded in the `settings` table).
        $week_hours = $this->Settings_model->period_open_hours(7);
        $default_target = $this->Settings_model->default_availability_target();

        // Q1: the week's availability per machine comes from the engine, not from
        // this method's own arithmetic — the printed weekly report and the PDF are
        // the same document, so they must not be the two numbers that disagree.
        $kpi_window = $this->Kpi_engine_model->fleet($week_start, $week_end);

        $equipment = $this->db
            ->select('e.id, e.registration_no, et.name AS equip_type, e.brand, e.model, e.location, e.status AS equip_status')
            ->from('equipment e')
            ->join('equipment_types et', 'et.id = e.equipment_type_id', 'left')
            ->where('e.status !=', 'Retired')
            ->order_by('et.name, e.registration_no')
            ->get()->result();

        // Get ALL WOs that overlap with this week
        $wos = $this->db
            ->select('wo.*, e.registration_no')
            ->from('work_orders wo')
            ->join('equipment e', 'e.id = wo.equipment_id')
            ->group_start()
                ->group_start()
                    ->where('DATE(wo.start_datetime) >=', $week_start)
                    ->where('DATE(wo.start_datetime) <=', $week_end)
                ->group_end()
                ->or_group_start()
                    ->where('DATE(wo.created_at) >=', $week_start)
                    ->where('DATE(wo.created_at) <=', $week_end)
                ->group_end()
                ->or_group_start()
                    ->where_not_in('wo.status', array('Completed', 'Closed'))
                    ->where('DATE(wo.start_datetime) <=', $week_end)
                ->group_end()
            ->group_end()
            ->order_by('wo.start_datetime', 'ASC')
            ->get()->result();

        // Group ALL WOs per equipment (multiple WOs per machine during the week)
        $wo_map = [];
        foreach ($wos as $wo) {
            $wo_map[$wo->equipment_id][] = $wo;
        }

        $groups = [];
        foreach ($equipment as $eq) {
            $type = strtoupper((string)($eq->equip_type ?: 'OTHER'));
            if (!isset($groups[$type])) {
                $groups[$type] = ['type' => $type, 'machines' => []];
            }

            $eq_wos   = isset($wo_map[$eq->id]) ? $wo_map[$eq->id] : [];
            $latest   = !empty($eq_wos) ? end($eq_wos) : null;

            // Observations and actions — numbered list from all WOs
            $observations = [];
            $actions      = [];
            $wo_dates     = [];
            foreach ($eq_wos as $idx => $wo) {
                $obs = trim((string)($wo->symptoms ?? ($wo->description ?? ($wo->title ?? ''))));
                $act = trim((string)($wo->action_taken ?? ''));
                $dt  = !empty($wo->start_datetime) ? date('d/m/Y', strtotime($wo->start_datetime)) : 'N/A';
                if ($obs) $observations[] = ($idx+1).'_ '.$obs;
                if ($act) $actions[]      = ($idx+1).'_ '.$act;
                $wo_dates[] = $dt;
            }

            // Determine current status from latest WO
            $isDown   = false;
            $statusCode = 'O';
            if ($latest) {
                $s = $latest->status;
                if (in_array($s, ['Open','In Progress'])) {
                    $isDown = true; $statusCode = 'BD';
                } elseif ($s === 'Waiting Parts') {
                    $isDown = true; $statusCode = 'WSP';
                } elseif ($s === 'Waiting Tools') {
                    $isDown = true; $statusCode = 'UM';
                }
            }
            if (!$isDown && equip_is_down($eq)) {   // W0.2
                $isDown = true; $statusCode = 'BD';
            }

            // Start date = earliest WO this week
            $startDate = '';
            $daysDown  = 0;
            if (!empty($eq_wos)) {
                $first = reset($eq_wos);
                if (!empty($first->start_datetime)) {
                    $startDate = date('d/m/Y', strtotime($first->start_datetime));
                    $daysDown  = (int)ceil((strtotime($week_end) - strtotime($first->start_datetime)) / 86400);
                    $daysDown  = max(0, min($daysDown, 7));
                }
            }

            // Deadline = week end date
            $deadline = date('d/m/Y', strtotime($week_end));

            // The engine's figures for this machine and this week: downtime is the
            // UNION of the breakdown intervals (one failure repaired by three work
            // orders counts once) and planned service is excluded from the basis.
            $k = isset($kpi_window['machines'][(int)$eq->id]) ? $kpi_window['machines'][(int)$eq->id] : null;
            $availability   = ($k !== null && $k['availability'] !== null) ? $k['availability'] : 1.0;
            $machine_target = $this->Kpi_engine_model->target_for($eq->id, $week_end);
            $totalDt        = $k === null ? 0.0 : round((float)$k['downtime_hours'], 2);

            $groups[$type]['machines'][] = [
                'equipment_id' => (int)$eq->id,
                'machine_id'   => $eq->registration_no,
                'model'        => ($eq->brand ? $eq->brand.' ' : '').$eq->model,
                'location'     => $eq->location,
                'start_date'   => $startDate,
                'deadline'     => $deadline,
                'days_down'    => $daysDown,
                'availability' => $availability,
                'target'       => $machine_target,
                'status_code'  => $statusCode,
                'status_label' => $isDown ? 'Not Operational' : 'Operational',
                'observations' => $observations,
                'actions'      => $actions,
                'wo_dates'     => $wo_dates,
                'total_dt'     => $totalDt,
                'wo_count'     => count($eq_wos),
            ];
        }

        // Group summaries — the group's availability is a required-weighted rollup
        // of its machines (engine->rollup), not the average of their percentages.
        foreach ($groups as &$g) {
            $total = count($g['machines']);
            $down  = 0; $kpi_members = [];
            foreach ($g['machines'] as $m) {
                if (!in_array($m['status_code'], ['O'])) $down++;
                $eid = isset($m['equipment_id']) ? (int)$m['equipment_id'] : null;
                if ($eid !== null && isset($kpi_window['machines'][$eid])) {
                    $kpi_members[] = $kpi_window['machines'][$eid];
                }
            }
            $rollup = $this->Kpi_engine_model->rollup($kpi_members);
            $g['total']     = $total;
            $g['available'] = $total - $down;
            $g['down']      = $down;
            $g['weekly_kpi']= $rollup['availability'] === null ? 0 : round($rollup['availability'], 2);
            $g['kpi_rollup']= $rollup;                       // downtime / breakdowns / MTTR / MTBF for the group
            $g['target']    = $rollup['weekly_target'] !== null ? $rollup['weekly_target'] : $default_target;
        }
        unset($g);

        return $groups;
    }

    private function _export_workshop_weekly_pdf($report, $week_start, $week_end) {
        $site    = isset($this->site_settings['company_name']) ? $this->site_settings['company_name'] : 'Workshop WMS';
        $wkLabel = date('d M', strtotime($week_start)).' – '.date('d M Y', strtotime($week_end));

        $totalEq = $totalAvail = $totalDown = 0;
        foreach ($report as $g) {
            $totalEq    += $g['total'];
            $totalAvail += $g['available'];
            $totalDown  += $g['down'];
        }
        // Two different figures, deliberately not conflated (Q1):
        //   $overallKpi  — the engine's time-based fleet availability for the week
        //   $readiness   — how many machines were up (a count)
        $kpi_fleet   = $this->Kpi_engine_model->fleet($week_start, $week_end);
        $overallKpi  = $kpi_fleet['fleet']['availability_pct'] === null
            ? 100 : round($kpi_fleet['fleet']['availability_pct']);
        $readiness   = $totalEq > 0 ? round($totalAvail / $totalEq * 100) : 0;
        $fleetTarget = round($this->Kpi_engine_model->target_for(null, $week_end) * 100);

        ob_start(); ?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Workshop Weekly Report — <?= $wkLabel ?></title>
<style>
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:Arial,sans-serif;font-size:7.5pt;color:#222;background:#fff;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
.page{padding:8mm 7mm;}
.hdr{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:5px;gap:10px;}
.hdr-title{font-size:15pt;font-weight:900;color:#1F4E79;letter-spacing:1px;}
.hdr-sub{font-size:8pt;color:#555;margin-top:2px;}
.legend{border:1px solid #2E75B6;border-radius:3px;padding:4px 8px;font-size:6.5pt;}
.legend-title{font-weight:700;color:#1F4E79;margin-bottom:2px;font-size:7pt;}
.legend div{margin:1px 0;}
/* Summary */
.summary{display:flex;flex-wrap:wrap;gap:5px;margin-bottom:7px;}
.sum-box{flex:1;min-width:80px;border:1.5px solid #2E75B6;border-radius:3px;padding:4px 6px;text-align:center;}
.sum-type{font-size:6.5pt;font-weight:700;color:#1F4E79;text-transform:uppercase;}
.sum-nums{font-size:6pt;color:#555;margin:1px 0;}
.sum-kpi{font-size:12pt;font-weight:900;}
/* Table */
table{width:100%;border-collapse:collapse;margin-bottom:8px;}
th{background:#2E75B6;color:#fff;font-size:6pt;font-weight:700;text-align:center;padding:2.5px 2px;border:1px solid #1a5891;}
td{font-size:6.5pt;padding:2px 3px;border:1px solid #ccc;vertical-align:top;}
.td-type{background:#BDD7EE;font-weight:700;color:#1F4E79;text-align:center;font-size:7pt;vertical-align:middle;}
.td-sum{background:#DEEAF1;font-weight:700;text-align:center;vertical-align:middle;}
.td-c{text-align:center;vertical-align:middle;}
.td-id{font-weight:700;color:#1F4E79;vertical-align:middle;}
.td-op{background:#E2EFDA;color:#375623;font-weight:700;text-align:center;vertical-align:middle;}
.td-bd{background:#FFD7D7;color:#C00000;font-weight:700;text-align:center;vertical-align:middle;}
.td-wsp{background:#FFF2CC;color:#7F6000;font-weight:700;text-align:center;vertical-align:middle;}
.td-good{color:#375623;font-weight:700;text-align:center;vertical-align:middle;}
.td-warn{color:#7F6000;font-weight:700;text-align:center;vertical-align:middle;}
.td-bad{color:#C00000;font-weight:700;text-align:center;vertical-align:middle;}
.td-kpi{background:#EBF3FB;font-weight:900;font-size:7pt;text-align:center;color:#1F4E79;vertical-align:middle;}
.ol-item{margin-bottom:1px;line-height:1.3;}
/* Charts */
.charts-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;margin-top:6px;page-break-inside:avoid;}
.chart-box{border:1px solid #ccc;border-radius:3px;padding:5px;}
.chart-title{font-size:7pt;font-weight:700;color:#1F4E79;text-align:center;margin-bottom:3px;}
.chart-kpi{font-size:10pt;font-weight:900;text-align:center;margin-bottom:3px;}
.bars{display:flex;align-items:flex-end;gap:2px;height:45px;}
.bar-wrap{flex:1;display:flex;flex-direction:column;align-items:center;}
.bar{width:100%;border-radius:2px 2px 0 0;}
.bar-lbl{font-size:4.5pt;color:#666;margin-top:1px;text-align:center;overflow:hidden;white-space:nowrap;width:100%;}
.bar-val{font-size:5pt;font-weight:700;margin-bottom:1px;}
@media print{@page{size:A4 landscape;margin:0;}body{background:#fff;}.page{padding:7mm 6mm;}}
</style>
</head>
<body>
<div class="page">
  <div class="hdr">
    <div>
      <div class="hdr-title">WORKSHOP WEEKLY REPORT</div>
      <div class="hdr-sub"><?= htmlspecialchars((string)($site)) ?> &nbsp;|&nbsp; Week: <?= $wkLabel ?> &nbsp;|&nbsp; <?= $totalAvail ?> of <?= $totalEq ?> machines up &nbsp;|&nbsp; target <?= $fleetTarget ?>%</div>
    </div>
    <div style="display:flex;gap:10px;align-items:flex-start;">
      <div style="text-align:right;">
        <div style="font-size:7pt;color:#555;">Overall Fleet Availability</div>
        <div style="font-size:18pt;font-weight:900;color:<?= $overallKpi>=$fleetTarget?'#375623':($overallKpi>=$fleetTarget-10?'#7F6000':'#C00000') ?>;"><?= $overallKpi ?>%</div>
      </div>
      <div class="legend">
        <div class="legend-title">LEGENDS</div>
        <div><strong style="color:#375623;">O</strong> – Operational</div>
        <div><strong style="color:#C00000;">BD</strong> – Breakdown</div>
        <div><strong style="color:#7F6000;">WSP</strong> – Waiting for Sparepart</div>
        <div><strong style="color:#555;">UM</strong> – Under Maintenance</div>
      </div>
    </div>
  </div>

  <!-- Summary by type -->
  <div class="summary">
    <?php foreach ($report as $type => $g):
      $kpi = round($g['weekly_kpi'] * 100);
      $tgt = round($g['target'] * 100);
      $kpiColor = $kpi >= $tgt ? '#375623' : ($kpi >= $tgt - 10 ? '#7F6000' : '#C00000');
    ?>
    <div class="sum-box">
      <div class="sum-type"><?= htmlspecialchars((string)($g['type'])) ?>s</div>
      <div class="sum-nums"><?= $g['available'] ?> avail / <?= $g['down'] ?> down / <?= $g['total'] ?> total</div>
      <div class="sum-kpi" style="color:<?= $kpiColor ?>"><?= $kpi ?>%</div>
      <div style="font-size:5.5pt;color:#555;">Target: <?= $tgt ?>%</div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Main table -->
  <table>
    <thead>
      <tr>
        <th rowspan="2">TYPE</th>
        <th rowspan="2"># EQ</th>
        <th rowspan="2">AVAIL</th>
        <th rowspan="2">DOWN</th>
        <th rowspan="2">MACHINE ID</th>
        <th rowspan="2">MODEL</th>
        <th rowspan="2">LOCATION</th>
        <th rowspan="2">START DATE</th>
        <th rowspan="2">DAYS DOWN</th>
        <th rowspan="2">AVAIL%</th>
        <th rowspan="2">WEEKLY KPI</th>
        <th rowspan="2">TARGET</th>
        <th rowspan="2">ANALYSIS</th>
        <th colspan="2" style="text-align:center;">INTERVENTION</th>
        <th rowspan="2">DEADLINE</th>
        <th rowspan="2">STATUS</th>
        <th rowspan="2">OBSERVATION</th>
      </tr>
      <tr>
        <th>OBSERVATION</th>
        <th>ACTION</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($report as $type => $g):
      $first = true; $count = count($g['machines']);
      $tgt   = round($g['target'] * 100);
    ?>
    <?php foreach ($g['machines'] as $m):
      $avail    = round($m['availability'] * 100);
      $target   = round($m['target'] * 100);
      $analysis = $avail >= $target ? '+'.($avail-$target).'%' : '-'.($target-$avail).'%';
      $analysisColor = $avail >= $target ? '#375623' : '#C00000';
      $avClass  = $avail >= $target ? 'td-good' : ($avail >= $target - 10 ? 'td-warn' : 'td-bad');
      $stClass  = $m['status_code']==='O' ? 'td-op' : ($m['status_code']==='WSP' ? 'td-wsp' : 'td-bd');
    ?>
    <tr>
      <td class="td-type"><?= $first ? htmlspecialchars((string)($g['type'])) : '' ?></td>
      <td class="td-sum"><?= $first ? $g['total'] : '' ?></td>
      <td class="td-sum" style="color:#375623;"><?= $first ? $g['available'] : '' ?></td>
      <td class="td-sum" style="color:<?= $g['down']>0?'#C00000':'#555' ?>;"><?= $first ? $g['down'] : '' ?></td>
      <td class="td-id"><?= htmlspecialchars((string)($m['machine_id'])) ?></td>
      <td><?= htmlspecialchars((string)($m['model'])) ?></td>
      <td class="td-c"><?= htmlspecialchars((string)($m['location'])) ?></td>
      <td class="td-c" style="font-size:6pt;"><?= $m['start_date'] ?></td>
      <td class="td-c" style="<?= $m['days_down']>7?'color:#C00000;font-weight:700':'' ?>"><?= $m['days_down']>0 ? $m['days_down'] : '' ?></td>
      <td class="<?= $avClass ?>"><?= $avail ?>%</td>
      <td class="td-kpi"><?= $first ? round($g['weekly_kpi']*100).'%' : '' ?></td>
      <td class="td-c" style="color:#1F4E79;font-weight:700;"><?= $target ?>%</td>
      <td class="td-c" style="color:<?= $analysisColor ?>;font-weight:700;"><?= $analysis ?></td>
      <td style="font-size:6pt;">
        <?php foreach ($m['observations'] as $oi => $obs): ?>
        <div class="ol-item"><?= htmlspecialchars((string)($obs)) ?></div>
        <?php endforeach; ?>
      </td>
      <td style="font-size:6pt;">
        <?php foreach ($m['actions'] as $ai => $act): ?>
        <div class="ol-item"><?= htmlspecialchars((string)($act)) ?></div>
        <?php if (isset($m['wo_dates'][$ai]) && $m['wo_dates'][$ai]): ?>
        <div style="color:#888;font-size:5.5pt;"><?= $m['wo_dates'][$ai] ?></div>
        <?php endif; ?>
        <?php endforeach; ?>
      </td>
      <td class="td-c" style="font-size:6pt;"><?= $m['deadline'] ?></td>
      <td class="<?= $stClass ?>"><?= $m['status_code'] ?><br><span style="font-size:5pt;"><?= $m['status_label'] ?></span></td>
      <td style="font-size:6pt;"><?= $m['status_label'] ?></td>
    </tr>
    <?php $first = false; endforeach; ?>
    <?php endforeach; ?>
    </tbody>
  </table>

  <!-- Per-type charts -->
  <div class="charts-grid">
    <?php foreach ($report as $type => $g):
      $kpi = round($g['weekly_kpi'] * 100);
      $ctgt = round($g['target'] * 100);
      $kpiColor = $kpi >= $ctgt ? '#375623' : ($kpi >= $ctgt - 10 ? '#7F6000' : '#C00000');
    ?>
    <div class="chart-box">
      <div class="chart-title">Weekly <?= htmlspecialchars((string)($g['type'])) ?>s KPI</div>
      <div class="chart-kpi" style="color:<?= $kpiColor ?>"><?= $kpi ?>%</div>
      <div class="bars">
        <?php foreach ($g['machines'] as $m):
          $avail    = round($m['availability'] * 100);
          $mtgt     = round($m['target'] * 100);
          $barColor = $avail >= $mtgt ? '#375623' : ($avail >= $mtgt - 10 ? '#FF9F0A' : '#C00000');
          $barH     = max(2, round($avail * 0.43));
        ?>
        <div class="bar-wrap">
          <div class="bar-val" style="color:<?= $barColor ?>"><?= $avail ?>%</div>
          <div class="bar" style="height:<?= $barH ?>px;background:<?= $barColor ?>;opacity:.85;"></div>
          <div class="bar-lbl"><?= htmlspecialchars((string)($m['machine_id'])) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

</div>
<script>window.onload=function(){window.print();}</script>
</body>
</html>
        <?php
        $html = ob_get_clean();
        header('Content-Type: text/html; charset=UTF-8');
        header('Content-Disposition: inline; filename="Workshop_Weekly_'.date('Ymd',strtotime($week_start)).'.html"');
        echo $html;
        exit;
    }

    private function _export_workshop_weekly_xlsx($report, $week_start, $week_end) {
        $this->load->helper('xlsx');
        $xlsx = new XlsxWriter();
        $xlsx->addSheet('Workshop Weekly Report');

        // 18 columns matching PDF layout
        $colW = [1=>12,2=>7,3=>7,4=>7,5=>11,6=>16,7=>11,8=>10,9=>8,10=>12,11=>10,12=>9,13=>9,14=>32,15=>28,16=>11,17=>12,18=>14];
        foreach ($colW as $c=>$w) $xlsx->setColWidth($c, $w);

        // Styles
        $sTitle  = 'bold|fontSize:13|align:center|valign:middle|bg:1F4E79|fg:FFFFFF';
        $sHdr    = 'bold|fontSize:8|align:center|valign:middle|bg:2E75B6|fg:FFFFFF|border:thin|wrap';
        $sType   = 'bold|fontSize:10|align:center|valign:middle|bg:BDD7EE|border:thin';
        $sSumN   = 'bold|fontSize:10|align:center|valign:middle|bg:DEEAF1|border:thin';
        $sCell   = 'fontSize:9|valign:top|border:thin|wrap';
        $sCellC  = 'fontSize:9|align:center|valign:middle|border:thin';
        $sOp     = 'bold|fontSize:9|align:center|valign:middle|border:thin|bg:E2EFDA|fg:375623';
        $sBd     = 'bold|fontSize:9|align:center|valign:middle|border:thin|bg:FFD7D7|fg:C00000';
        $sWsp    = 'bold|fontSize:9|align:center|valign:middle|border:thin|bg:FFF2CC|fg:7F6000';
        $sPctG   = 'bold|fontSize:9|align:center|valign:middle|border:thin|numFmt:0%|bg:E2EFDA|fg:375623';
        $sPctW   = 'bold|fontSize:9|align:center|valign:middle|border:thin|numFmt:0%|bg:FFF2CC|fg:7F6000';
        $sPctR   = 'bold|fontSize:9|align:center|valign:middle|border:thin|numFmt:0%|bg:FFD7D7|fg:C00000';
        $sTgt    = 'bold|fontSize:9|align:center|valign:middle|border:thin|numFmt:0%|bg:EBF3FB|fg:1F4E79';
        $sAnaG   = 'bold|fontSize:9|align:center|valign:middle|border:thin|bg:E2EFDA|fg:375623';
        $sAnaR   = 'bold|fontSize:9|align:center|valign:middle|border:thin|bg:FFD7D7|fg:C00000';
        $sTotL   = 'bold|fontSize:10|align:right|valign:middle|bg:1F4E79|fg:FFFFFF|border:thin';
        $sTotP   = 'bold|fontSize:10|align:center|valign:middle|bg:1F4E79|fg:FFFFFF|border:thin|numFmt:0%';

        foreach ([$sTitle,$sHdr,$sType,$sSumN,$sCell,$sCellC,$sOp,$sBd,$sWsp,$sPctG,$sPctW,$sPctR,$sTgt,$sAnaG,$sAnaR,$sTotL,$sTotP] as $s) {
            $xlsx->registerStyle($s);
        }

        $wkLabel = date('d/m/Y', strtotime($week_start)).' – '.date('d/m/Y', strtotime($week_end));

        // Row 1: Title
        $xlsx->setRowHeight(1, 30);
        $titleCells = [['v'=>'WORKSHOP WEEKLY REPORT   |   Week: '.$wkLabel,'s'=>$sTitle]];
        for ($i = 1; $i < 18; $i++) $titleCells[] = ['v'=>'','s'=>$sTitle];
        $xlsx->writeRowStyled($titleCells);
        $xlsx->mergeCells('A1','R1');

        // Row 2: Headers
        $xlsx->setRowHeight(2, 44);
        $headers = ['TYPE','# EQUIP','AVAILABLE','DOWN','MACHINE ID','MODEL','LOCATION',
                    'START DATE','DAYS DOWN','AVAILABILITY','WEEKLY KPI','TARGET','ANALYSIS',
                    'OBSERVATION','ACTION / DATES','DEADLINE','STATUS','FINAL STATUS'];
        $xlsx->writeRow($headers, $sHdr);

        $rowNum = 3;
        foreach ($report as $type => $g) {
            $startRow = $rowNum;
            $machines = $g['machines'];
            $count    = count($machines);

            foreach ($machines as $mi => $m) {
                $isFirst = ($mi === 0);
                $avPct   = $m['availability'];
                $tgtPct  = $m['target'];
                $sPctStyle = $avPct >= 0.9 ? $sPctG : ($avPct >= 0.7 ? $sPctW : $sPctR);
                $sStatus   = $m['status_code']==='O' ? $sOp : ($m['status_code']==='WSP' ? $sWsp : $sBd);
                $diff      = round(($avPct - $tgtPct) * 100);
                $sAna      = $diff >= 0 ? $sAnaG : $sAnaR;
                $anaText   = ($diff >= 0 ? '+' : '').$diff.'%';

                $obsText = implode("\n", $m['observations']);
                $actText = '';
                foreach ($m['actions'] as $ai => $act) {
                    $actText .= $act;
                    if (isset($m['wo_dates'][$ai]) && $m['wo_dates'][$ai]) {
                        $actText .= ' ('.$m['wo_dates'][$ai].')';
                    }
                    $actText .= "\n";
                }

                $xlsx->setRowHeight($rowNum, max(30, count($m['observations']) * 14));
                $xlsx->writeRowStyled([
                    ['v' => $isFirst ? $g['type']      : '', 's' => $sType],
                    ['v' => $isFirst ? $g['total']     : '', 's' => $sSumN],
                    ['v' => $isFirst ? $g['available'] : '', 's' => $sSumN],
                    ['v' => $isFirst ? $g['down']      : '', 's' => $sSumN],
                    ['v' => $m['machine_id'],          's' => $sCellC],
                    ['v' => $m['model'],               's' => $sCell],
                    ['v' => $m['location'],            's' => $sCellC],
                    ['v' => $m['start_date'],          's' => $sCellC],
                    ['v' => $m['days_down']>0 ? (int)$m['days_down'] : '', 's' => $sCellC],
                    ['v' => $avPct,                    's' => $sPctStyle],
                    ['v' => $isFirst ? (float)$g['weekly_kpi'] : '', 's' => $sPctG],
                    ['v' => $tgtPct,                   's' => $sTgt],
                    ['v' => $anaText,                  's' => $sAna],
                    ['v' => $obsText,                  's' => $sCell],
                    ['v' => trim((string)($actText)),            's' => $sCell],
                    ['v' => $m['deadline'],            's' => $sCellC],
                    ['v' => $m['status_code'],         's' => $sStatus],
                    ['v' => $m['status_label'],        's' => $sStatus],
                ]);
                $rowNum++;
            }

            if ($count > 1) {
                $endRow = $rowNum - 1;
                $xlsx->mergeCells("A{$startRow}", "A{$endRow}");
                $xlsx->mergeCells("B{$startRow}", "B{$endRow}");
                $xlsx->mergeCells("C{$startRow}", "C{$endRow}");
                $xlsx->mergeCells("D{$startRow}", "D{$endRow}");
                $xlsx->mergeCells("K{$startRow}", "K{$endRow}");
            }
        }

        // Footer
        $totalEq = $totalAvail = $totalDown = 0;
        $kpiSum = 0; $kpiCount = 0;
        foreach ($report as $g) {
            $totalEq    += $g['total'];
            $totalAvail += $g['available'];
            $totalDown  += $g['down'];
            $kpiSum     += $g['weekly_kpi'];
            $kpiCount++;
        }
        $overallAvail = $totalEq > 0 ? round($totalAvail/$totalEq, 2) : 0;
        $overallKpi   = $kpiCount > 0 ? round($kpiSum/$kpiCount, 2)   : 0;

        $footer = [];
        for ($i = 0; $i < 16; $i++) {
            $footer[] = ['v' => $i===3 ? 'TOTAL FLEET' : '', 's' => $sTotL];
        }
        $footer[] = ['v' => $overallAvail, 's' => $sTotP];
        $footer[] = ['v' => $overallKpi,   's' => $sTotP];
        $xlsx->writeRowStyled($footer);
        $xlsx->mergeCells("A{$rowNum}", "P{$rowNum}");

        $filename = 'Workshop_Weekly_Report_'.date('Ymd',strtotime($week_start)).'_'.date('Ymd',strtotime($week_end)).'.xlsx';
        $tmp      = wms_temp_path($filename);
        $xlsx->save($tmp);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        header('Content-Length: '.filesize($tmp));
        readfile($tmp);
        unlink($tmp);
        exit;
    }

    /** Validate report date query parameters before they reach report logic. */
    private function _resolve_report_date($raw, $default = null) {
        if ($raw === null || $raw === '') {
            $raw = $default ?: date('Y-m-d');
        }

        if (!is_scalar($raw)) {
            show_error('Invalid date. Use YYYY-MM-DD.', 400);
            return null;
        }

        $date = trim((string)$raw);
        if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $date)
            || !checkdate((int)substr($date, 5, 2), (int)substr($date, 8, 2), (int)substr($date, 0, 4))) {
            show_error('Invalid date. Use YYYY-MM-DD.', 400);
            return null;
        }

        return $date;
    }

    /* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
       WORKSHOP DAILY REPORT
    ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */

    public function workshop_daily() {
        $date = $this->_resolve_report_date($this->input->get('date', TRUE));
        $data['date']       = $date;
        $data['report']     = $this->_build_workshop_daily($date);
        $data['page_title'] = 'Workshop Daily Report — '.date('d M Y', strtotime($date));
        $this->render('reports/workshop_daily', $data);
    }

    public function export_workshop_daily_xlsx() {
        $this->load->helper('xlsx');
        $date   = $this->_resolve_report_date($this->input->get('date', TRUE));
        $report = $this->_build_workshop_daily($date);
        $this->_export_workshop_xlsx($report, $date);
    }

    /** Build structured report data grouped by equipment type */
    private function _build_workshop_daily($date) {
        // Q1: a day's availability is the engine's — open hours from settings
        // (10 h here), downtime as the union of that day's breakdown intervals.
        // This used to be "(24 − downtime) / 24", a 24-hour day that exists
        // nowhere else in the system.
        $kpi_day = $this->Kpi_engine_model->fleet($date, $date);
        // Get all equipment joined with type name
        $equipment = $this->db
            ->select('e.id, e.registration_no, et.name AS equip_type, e.brand, e.model, e.location, e.status AS equip_status')
            ->from('equipment e')
            ->join('equipment_types et', 'et.id = e.equipment_type_id', 'left')
            ->where('e.status !=', 'Retired')
            ->order_by('et.name, e.registration_no')
            ->get()->result();

        // Get WOs for this date OR currently open/active ones
        $this->db->select('wo.*, e.registration_no');
        $this->db->from('work_orders wo');
        $this->db->join('equipment e', 'e.id = wo.equipment_id');
        $this->db->group_start()
            ->where('DATE(wo.created_at) =', $date)
            ->or_group_start()
                ->where_not_in('wo.status', array('Completed', 'Closed'))
                ->where('DATE(wo.start_datetime) <=', $date)
            ->group_end()
        ->group_end();
        $this->db->order_by('wo.created_at', 'DESC');
        $wos = $this->db->get()->result();

        // Index WOs by equipment_id (latest per equipment)
        $wo_map = [];
        foreach ($wos as $wo) {
            if (!isset($wo_map[$wo->equipment_id])) {
                $wo_map[$wo->equipment_id] = $wo;
            }
        }

        // Group by type
        $groups = [];
        foreach ($equipment as $eq) {
            $type = strtoupper((string)($eq->equip_type ?: 'OTHER'));
            if (!isset($groups[$type])) {
                $groups[$type] = ['type'=>$type, 'machines'=>[]];
            }
            $wo = isset($wo_map[$eq->id]) ? $wo_map[$eq->id] : null;

            // Determine status
            $isDown = false;
            if ($wo && in_array($wo->status, ['Open','In Progress','Waiting Parts','Waiting Tools'])) {
                $isDown = true;
            } elseif (equip_is_down($eq)) {          // W0.2
                $isDown = true;
            }

            // Downtime hours
            $downtime = 0;
            $upTime   = '';
            $downTime = '';
            $startDate = '';
            $daysDown  = 0;
            $estTime   = '';
            $observation  = '';
            $actionTaken  = '';
            $nbrBreakdown = 0;

            if ($wo) {
                $observation = $wo->symptoms ?? ($wo->description ?? '');
                $actionTaken = $wo->action_taken ?? ($wo->root_cause ?? '');
                // W0.1: the breakdown flag, not the (non-existent) 'Breakdown' type.
                // W1.6 replaces this per-work-order flag with per-period event counting.
                $nbrBreakdown= wo_is_breakdown($wo) ? 1 : 0;
                $estTime     = isset($wo->estimated_hours) && $wo->estimated_hours ? $wo->estimated_hours.' Hrs' : '';

                if ($wo->start_datetime) {
                    $downTime  = date('H:i', strtotime($wo->start_datetime));
                    $startDate = date('Y-m-d', strtotime($wo->start_datetime));
                    $daysDown  = (int)floor((strtotime($date) - strtotime($startDate)) / 86400) + 1;
                }
                if ($wo->end_datetime) {
                    $upTime = date('H:i', strtotime($wo->end_datetime));
                }
                $downtime = isset($wo->downtime_hours) ? (float)$wo->downtime_hours : 0;
            }

            // Availability for the day, from the engine (singleton on this date).
            $k = isset($kpi_day['machines'][(int)$eq->id]) ? $kpi_day['machines'][(int)$eq->id] : null;
            $availability   = ($k !== null && $k['availability'] !== null) ? round($k['availability'], 2) : 1;
            $machine_target = $this->Kpi_engine_model->target_for($eq->id, $date);
            if ($k !== null) $downtime = round((float)$k['downtime_hours'], 2);   // the union, not the sum

            $groups[$type]['machines'][] = [
                'equipment_id' => (int)$eq->id,
                'machine_id'   => $eq->registration_no,
                'model'        => ($eq->brand ? $eq->brand.' ' : '').$eq->model,
                'location'     => $eq->location,
                'observation'  => $observation,
                'action_taken' => $actionTaken,
                'est_time'     => $estTime,
                'status'       => $isDown ? 'Down' : 'Available',
                'down_time'    => $downTime,
                'up_time'      => $upTime,
                'total_dt'     => $downtime,
                'start_date'   => $startDate,
                'days_down'    => $daysDown,
                'availability' => $availability,
                'target'       => $machine_target,
            ];
        }

        // Compute group summaries — required-weighted, never an average of
        // percentages (the same rule as the weekly sheet).
        foreach ($groups as &$g) {
            $total = count($g['machines']);
            $down  = 0; $kpi_members = [];
            foreach ($g['machines'] as $m) {
                if ($m['status']==='Down') $down++;
                $eid = isset($m['equipment_id']) ? (int)$m['equipment_id'] : null;
                if ($eid !== null && isset($kpi_day['machines'][$eid])) {
                    $kpi_members[] = $kpi_day['machines'][$eid];
                }
            }
            $rollup = $this->Kpi_engine_model->rollup($kpi_members);
            $g['total']      = $total;
            $g['available']  = $total - $down;
            $g['down']       = $down;
            $g['daily_kpi']  = $rollup['availability'] === null ? 0 : round($rollup['availability'], 2);
            $g['kpi_rollup'] = $rollup;
            $g['target']     = $rollup['weekly_target'] !== null
                ? $rollup['weekly_target']
                : $this->Kpi_engine_model->target_for(null, $date);
        }
        unset($g);

        return $groups;
    }

    public function export_workshop_daily_pdf() {
        $date = $this->_resolve_report_date($this->input->get('date', TRUE));
        $report = $this->_build_workshop_daily($date);
        $this->_export_workshop_pdf($report, $date);
    }

    private function _export_workshop_pdf($report, $date) {
        // Build HTML for browser print-to-PDF (wkhtmltopdf not required)
        $site = isset($this->site_settings['company_name']) ? $this->site_settings['company_name'] : 'Workshop WMS';

        $totalEq = $totalAvail = $totalDown = 0;
        foreach ($report as $g) {
            $totalEq    += $g['total'];
            $totalAvail += $g['available'];
            $totalDown  += $g['down'];
        }
        // time-based availability from the engine, the count kept separately (Q1)
        $kpi_fleet   = $this->Kpi_engine_model->fleet($date, $date);
        $overallKpi  = $kpi_fleet['fleet']['availability_pct'] === null
            ? 100 : round($kpi_fleet['fleet']['availability_pct']);
        $readiness   = $totalEq > 0 ? round($totalAvail / $totalEq * 100) : 0;
        $default_target = $this->Kpi_engine_model->target_for(null, $date);
        $fleetTarget = round($default_target * 100);

        ob_start();
        ?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Workshop Daily Report — <?= date('d/m/Y', strtotime($date)) ?></title>
<style>
  *{margin:0;padding:0;box-sizing:border-box;}
  body{font-family:Arial,sans-serif;font-size:8pt;color:#222;background:#fff;}
  .page{padding:10mm 8mm;}
  /* Header */
  .hdr{display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;}
  .hdr-title{font-size:16pt;font-weight:900;color:#1F4E79;letter-spacing:1px;}
  .hdr-date{font-size:9pt;color:#555;}
  /* Summary boxes */
  .summary{display:flex;gap:6px;margin-bottom:8px;}
  .sum-box{flex:1;border:1.5px solid #2E75B6;border-radius:4px;padding:5px 8px;text-align:center;}
  .sum-type{font-size:7pt;font-weight:700;color:#1F4E79;text-transform:uppercase;margin-bottom:2px;}
  .sum-nums{font-size:7pt;color:#444;}
  .sum-kpi{font-size:13pt;font-weight:900;}
  /* Main table */
  table{width:100%;border-collapse:collapse;margin-bottom:10px;}
  th{background:#2E75B6;color:#fff;font-size:6.5pt;font-weight:700;text-align:center;padding:3px 2px;border:1px solid #1a5891;}
  td{font-size:6.5pt;padding:2px 3px;border:1px solid #ccc;vertical-align:middle;}
  .td-type{background:#BDD7EE;font-weight:700;color:#1F4E79;text-align:center;font-size:7pt;}
  .td-sum{background:#DEEAF1;font-weight:700;text-align:center;}
  .td-c{text-align:center;}
  .td-down{background:#FFD7D7;color:#C00000;font-weight:700;text-align:center;}
  .td-avail{background:#E2EFDA;color:#375623;text-align:center;}
  .td-pct-good{color:#375623;font-weight:700;text-align:center;}
  .td-pct-warn{color:#7F6000;font-weight:700;text-align:center;}
  .td-pct-bad{color:#C00000;font-weight:700;text-align:center;}
  .td-kpi{background:#EBF3FB;font-weight:900;font-size:7.5pt;text-align:center;color:#1F4E79;}
  /* Charts section */
  .charts-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;margin-top:8px;}
  .chart-box{border:1px solid #ccc;border-radius:4px;padding:5px;}
  .chart-title{font-size:7pt;font-weight:700;color:#1F4E79;margin-bottom:3px;text-align:center;}
  .chart-kpi{font-size:11pt;font-weight:900;text-align:center;margin-bottom:4px;}
  .bars{display:flex;align-items:flex-end;gap:3px;height:50px;}
  .bar-wrap{flex:1;display:flex;flex-direction:column;align-items:center;}
  .bar{width:100%;border-radius:2px 2px 0 0;}
  .bar-lbl{font-size:5pt;color:#666;margin-top:2px;text-align:center;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;width:100%;}
  .bar-val{font-size:5.5pt;font-weight:700;margin-bottom:1px;}
</style>
</head>
<body>
<div class="page">

  <!-- Header -->
  <div class="hdr">
    <div>
      <div class="hdr-title">WORKSHOP DAILY REPORT</div>
      <div class="hdr-date"><?= htmlspecialchars((string)($site)) ?> &nbsp;|&nbsp; <?= date('l, d F Y', strtotime($date)) ?> &nbsp;|&nbsp; <?= $totalAvail ?> of <?= $totalEq ?> machines up &nbsp;|&nbsp; target <?= $fleetTarget ?>%</div>
    </div>
    <div style="text-align:right;font-size:8pt;color:#555;">
      Overall Availability<br>
      <span style="font-size:18pt;font-weight:900;color:<?= $overallKpi>=$fleetTarget?'#375623':($overallKpi>=$fleetTarget-10?'#7F6000':'#C00000') ?>;"><?= $overallKpi ?>%</span>
    </div>
  </div>

  <!-- Summary by type -->
  <div class="summary">
    <?php foreach ($report as $type => $g):
      $kpi = round($g['daily_kpi'] * 100);
      $dtgt = round((isset($g['target']) ? $g['target'] : $default_target) * 100);
      $kpiColor = $kpi >= $dtgt ? '#375623' : ($kpi >= $dtgt - 10 ? '#7F6000' : '#C00000');
    ?>
    <div class="sum-box">
      <div class="sum-type"><?= htmlspecialchars((string)($g['type'])) ?>s</div>
      <div class="sum-nums"><?= $g['available'] ?> avail / <?= $g['down'] ?> down / <?= $g['total'] ?> total</div>
      <div class="sum-kpi" style="color:<?= $kpiColor ?>"><?= $kpi ?>%</div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Main table -->
  <table>
    <thead>
      <tr>
        <th>TYPE</th><th># EQ</th><th>AVAIL</th><th>DOWN</th>
        <th>MACHINE ID</th><th>MODEL</th><th>LOCATION</th>
        <th style="width:18%;">OBSERVATION</th>
        <th style="width:14%;">ONGOING ACTION</th>
        <th>E/T</th><th>STATUS</th>
        <th>DOWN TIME</th><th>UP TIME</th>
        <th>TOTAL DT</th><th>START DATE</th><th>DAYS DOWN</th>
        <th>AVAIL%</th><th>DAILY KPI</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($report as $type => $g):
      $first = true;
      $count = count($g['machines']);
    ?>
    <?php foreach ($g['machines'] as $m):
      $avail = round($m['availability'] * 100);
      $mtgt  = round((isset($m['target']) ? $m['target'] : $default_target) * 100);
      $pctClass = $avail >= $mtgt ? 'td-pct-good' : ($avail >= $mtgt - 10 ? 'td-pct-warn' : 'td-pct-bad');
    ?>
    <tr>
      <td class="td-type" rowspan="<?= $first && $count>1 ? '' : '' ?>"><?= $first ? htmlspecialchars((string)($g['type'])) : '' ?></td>
      <td class="td-sum"><?= $first ? $g['total'] : '' ?></td>
      <td class="td-sum" style="color:#375623;"><?= $first ? $g['available'] : '' ?></td>
      <td class="td-sum" style="color:<?= $g['down']>0?'#C00000':'#555' ?>;"><?= $first ? $g['down'] : '' ?></td>
      <td class="td-c" style="font-weight:700;color:#1F4E79;"><?= htmlspecialchars((string)($m['machine_id'])) ?></td>
      <td><?= htmlspecialchars((string)($m['model'])) ?></td>
      <td class="td-c"><?= htmlspecialchars((string)($m['location'])) ?></td>
      <td><?= htmlspecialchars((string)($m['observation'])) ?></td>
      <td><?= htmlspecialchars((string)($m['action_taken'])) ?></td>
      <td class="td-c"><?= htmlspecialchars((string)($m['est_time'])) ?></td>
      <td class="<?= $m['status']==='Down'?'td-down':'td-avail' ?>"><?= $m['status'] ?></td>
      <td class="td-c"><?= htmlspecialchars((string)($m['down_time'])) ?></td>
      <td class="td-c"><?= htmlspecialchars((string)($m['up_time'])) ?></td>
      <td class="td-c" style="<?= $m['total_dt']>0?'color:#7F6000;font-weight:700':'' ?>">
        <?= $m['total_dt'] > 0 ? number_format((float)($m['total_dt']),1) : '' ?>
      </td>
      <td class="td-c"><?= $m['start_date'] ? date('d/m/Y',strtotime($m['start_date'])) : '' ?></td>
      <td class="td-c" style="<?= $m['days_down']>7?'color:#C00000;font-weight:700':($m['days_down']>0?'color:#7F6000;font-weight:700':'') ?>">
        <?= $m['days_down'] > 0 ? $m['days_down'] : '' ?>
      </td>
      <td class="<?= $pctClass ?>"><?= $avail ?>%</td>
      <td class="td-kpi"><?= $first ? round($g['daily_kpi']*100).'%' : '' ?></td>
    </tr>
    <?php $first = false; endforeach; ?>
    <?php endforeach; ?>
    </tbody>
  </table>

  <!-- Per-type bar charts -->
  <div class="charts-grid">
    <?php foreach ($report as $type => $g):
      $kpi = round($g['daily_kpi'] * 100);
      $dtgt = round((isset($g['target']) ? $g['target'] : $default_target) * 100);
      $kpiColor = $kpi >= $dtgt ? '#375623' : ($kpi >= $dtgt - 10 ? '#7F6000' : '#C00000');
    ?>
    <div class="chart-box">
      <div class="chart-title">Daily <?= htmlspecialchars((string)($g['type'])) ?>s KPI</div>
      <div class="chart-kpi" style="color:<?= $kpiColor ?>"><?= $kpi ?>%</div>
      <div class="bars">
        <?php foreach ($g['machines'] as $m):
          $avail = round($m['availability'] * 100);
          $mtgt  = round((isset($m['target']) ? $m['target'] : $default_target) * 100);
          $barColor = $avail >= $mtgt ? '#375623' : ($avail >= $mtgt - 10 ? '#FF9F0A' : '#C00000');
          $barH = max(2, round($avail * 0.45)); // max ~45px
        ?>
        <div class="bar-wrap">
          <div class="bar-val" style="color:<?= $barColor ?>"><?= $avail ?>%</div>
          <div class="bar" style="height:<?= $barH ?>px;background:<?= $barColor ?>;opacity:.85;"></div>
          <div class="bar-lbl"><?= htmlspecialchars((string)($m['machine_id'])) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

</div>
<script>window.onload=function(){window.print();}</script>
</body>
</html>
        <?php
        $html = ob_get_clean();
        header('Content-Type: text/html; charset=UTF-8');
        header('Content-Disposition: inline; filename="Workshop_Daily_'.date('Ymd',strtotime($date)).'.html"');
        echo $html;
        exit;
    }

    private function _export_workshop_xlsx($report, $date) {
        $default_target = $this->Kpi_engine_model->target_for(null, $date);
        $xlsx = new XlsxWriter();
        $xlsx->addSheet('Workshop Daily Report');

        // 18 columns A-R — match PDF layout exactly
        $colW = [1=>12,2=>7,3=>7,4=>7,5=>11,6=>16,7=>11,8=>32,9=>28,10=>8,11=>11,12=>10,13=>10,14=>9,15=>11,16=>7,17=>12,18=>10];
        foreach ($colW as $c=>$w) $xlsx->setColWidth($c, $w);

        // Styles
        $sTitle  = 'bold|fontSize:13|align:center|valign:middle|bg:1F4E79|fg:FFFFFF';
        $sHdr    = 'bold|fontSize:9|align:center|valign:middle|bg:2E75B6|fg:FFFFFF|border:thin|wrap';
        $sType   = 'bold|fontSize:10|align:center|valign:middle|bg:BDD7EE|border:thin';
        $sSumN   = 'bold|fontSize:10|align:center|valign:middle|bg:DEEAF1|border:thin';
        $sCell   = 'fontSize:9|valign:middle|border:thin|wrap';
        $sCellC  = 'fontSize:9|align:center|valign:middle|border:thin';
        $sDown   = 'bold|fontSize:9|align:center|valign:middle|border:thin|bg:FFD7D7|fg:C00000';
        $sAvail  = 'fontSize:9|align:center|valign:middle|border:thin|bg:E2EFDA|fg:375623';
        $sPctG   = 'bold|fontSize:9|align:center|valign:middle|border:thin|numFmt:0%|bg:E2EFDA|fg:375623';
        $sPctW   = 'bold|fontSize:9|align:center|valign:middle|border:thin|numFmt:0%|bg:FFF2CC|fg:7F6000';
        $sPctR   = 'bold|fontSize:9|align:center|valign:middle|border:thin|numFmt:0%|bg:FFD7D7|fg:C00000';
        $sTotL   = 'bold|fontSize:10|align:right|valign:middle|bg:1F4E79|fg:FFFFFF|border:thin';
        $sTotN   = 'bold|fontSize:10|align:center|valign:middle|bg:1F4E79|fg:FFFFFF|border:thin';
        $sTotP   = 'bold|fontSize:10|align:center|valign:middle|bg:1F4E79|fg:FFFFFF|border:thin|numFmt:0%';

        foreach ([$sTitle,$sHdr,$sType,$sSumN,$sCell,$sCellC,$sDown,$sAvail,$sPctG,$sPctW,$sPctR,$sTotL,$sTotN,$sTotP] as $s) {
            $xlsx->registerStyle($s);
        }

        // Row 1: Title — exactly 18 cells, all merged
        $xlsx->setRowHeight(1, 30);
        $titleCells = [['v'=>'WORKSHOP DAILY REPORT   |   '.date('d/m/Y', strtotime($date)),'s'=>$sTitle]];
        for ($i = 1; $i < 18; $i++) $titleCells[] = ['v'=>'','s'=>$sTitle];
        $xlsx->writeRowStyled($titleCells);
        $xlsx->mergeCells('A1','R1');

        // Row 2: Column headers — exactly 18
        $xlsx->setRowHeight(2, 44);
        $headers = ['TYPE','# EQUIP','AVAILABLE','DOWN','MACHINE ID','MODEL','LOCATION',
                    'OBSERVATION','ONGOING ACTION','E/T','STATUS',
                    'DOWN TIME','UP TIME','TOTAL DT (h)','START DATE','DAYS DOWN','AVAILABILITY','DAILY KPI'];
        $xlsx->writeRow($headers, $sHdr);

        // Data rows
        $rowNum = 3;
        foreach ($report as $type => $g) {
            $startRow = $rowNum;
            $machines = $g['machines'];
            $count    = count($machines);

            foreach ($machines as $mi => $m) {
                $isFirst = ($mi === 0);
                $sStatus = ($m['status']==='Down') ? $sDown : $sAvail;

                // Pick availability colour style
                $avPct = $m['availability']; // decimal 0.0–1.0
                $sPctStyle = $avPct >= 0.9 ? $sPctG : ($avPct >= 0.7 ? $sPctW : $sPctR);

                // Clean down/up time — strip trailing :00 duplication, blank for available machines
                $downTimeVal = '';
                $upTimeVal   = '';
                if (!empty($m['down_time'])) {
                    // Stored as HH:MM:00 or HH:MM — keep HH:MM only
                    $downTimeVal = substr($m['down_time'], 0, 5);
                }
                if (!empty($m['up_time'])) {
                    $upTimeVal = substr($m['up_time'], 0, 5);
                }
                // Available machines with no real WO should have blank times
                if ($m['status'] === 'Available' && $m['total_dt'] == 0) {
                    $downTimeVal = '';
                    $upTimeVal   = '';
                }

                // Format start date as d/m/Y for display
                $startDateVal = !empty($m['start_date']) ? date('d/m/Y', strtotime($m['start_date'])) : '';

                $xlsx->setRowHeight($rowNum, 30);
                $xlsx->writeRowStyled([
                    ['v' => $isFirst ? $g['type']      : '', 's' => $sType],
                    ['v' => $isFirst ? $g['total']     : '', 's' => $sSumN],
                    ['v' => $isFirst ? $g['available'] : '', 's' => $sSumN],
                    ['v' => $isFirst ? $g['down']      : '', 's' => $sSumN],
                    ['v' => $m['machine_id'],                    's' => $sCellC],
                    ['v' => $m['model'],                         's' => $sCell],
                    ['v' => $m['location'],                      's' => $sCellC],
                    ['v' => $m['observation'],                   's' => $sCell],
                    ['v' => $m['action_taken'],                  's' => $sCell],
                    ['v' => $m['est_time'],                      's' => $sCellC],
                    ['v' => $m['status'],                        's' => $sStatus],
                    ['v' => $downTimeVal,                        's' => $sCellC],
                    ['v' => $upTimeVal,                          's' => $sCellC],
                    ['v' => $m['total_dt'] > 0 ? (float)$m['total_dt'] : '', 's' => $sCellC],
                    ['v' => $startDateVal,                       's' => $sCellC],
                    ['v' => $m['days_down'] > 0 ? (int)$m['days_down'] : '', 's' => $sCellC],
                    ['v' => $avPct,                              's' => $sPctStyle],
                    ['v' => $isFirst ? (float)$g['daily_kpi'] : '', 's' => $sPctG],
                ]);
                $rowNum++;
            }

            // Merge TYPE + summary cols + KPI col across group rows
            if ($count > 1) {
                $endRow = $rowNum - 1;
                $xlsx->mergeCells("A{$startRow}", "A{$endRow}");
                $xlsx->mergeCells("B{$startRow}", "B{$endRow}");
                $xlsx->mergeCells("C{$startRow}", "C{$endRow}");
                $xlsx->mergeCells("D{$startRow}", "D{$endRow}");
                $xlsx->mergeCells("R{$startRow}", "R{$endRow}");
            }
        }

        // Summary footer row
        $xlsx->setRowHeight($rowNum, 22);
        $totalEq = $totalAvail = $totalDown = 0;
        $kpiSum = 0; $kpiCount = 0;
        foreach ($report as $g) {
            $totalEq    += $g['total'];
            $totalAvail += $g['available'];
            $totalDown  += $g['down'];
            $kpiSum     += $g['daily_kpi'];
            $kpiCount++;
        }
        $overallAvail = $totalEq > 0 ? round($totalAvail / $totalEq, 2) : 0;
        $overallKpi   = $kpiCount > 0 ? round($kpiSum / $kpiCount, 2)   : 0;

        $footer = [];
        for ($i = 0; $i < 16; $i++) {
            $footer[] = ['v' => $i === 3 ? 'TOTAL' : '', 's' => $sTotL];
        }
        $footer[] = ['v' => $overallAvail, 's' => $sTotP];
        $footer[] = ['v' => $overallKpi,   's' => $sTotP];
        $xlsx->writeRowStyled($footer);
        $xlsx->mergeCells("A{$rowNum}", "P{$rowNum}");

        $filename = 'Workshop_Daily_Report_'.date('Ymd', strtotime($date)).'.xlsx';
        $tmp      = wms_temp_path($filename);
        $xlsx->save($tmp);

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        header('Content-Length: '.filesize($tmp));
        readfile($tmp);
        unlink($tmp);
        exit;
    }

        /* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
       EXISTING CSV EXPORTS
    ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */

    /** 'YYYY-MM' from a query string, or the current month when invalid. */
    private function _valid_month($value) {
        if (is_string($value) && preg_match('/^([0-9]{4})-(0[1-9]|1[0-2])$/D', $value)) {
            return $value;
        }
        return date('Y-m');
    }

    public function export_excel($type) {
        $month = $this->_valid_month($this->input->get('month'));
        switch ($type) {
            case 'workorders':
                $rows = $this->Workorder_model->get_by_date_range($month.'-01', date('Y-m-t', strtotime($month.'-01')));
                $filename = "WorkOrders_{$month}.csv";
                header('Content-Type: text/csv');
                header("Content-Disposition: attachment; filename={$filename}");
                $out = fopen('php://output', 'w');
                fputcsv($out, ['WO Number','Equipment','Type','Priority','Status','Title','Start','End','Downtime Hrs','Assigned To'], ',', '"', '\\');
                foreach ($rows as $r) fputcsv($out, [$r->wo_number,$r->registration_no,$r->type,$r->priority,$r->status,$r->title,$r->start_datetime,$r->end_datetime,$r->downtime_hours,$r->assigned_name], ',', '"', '\\');
                fclose($out);
                break;
            case 'attendance':
                $this->load->helper('attendance');
                $period = wms_timesheet_resolve_period(
                    $month,
                    $this->input->get('period', TRUE),
                    $this->input->get('from', TRUE),
                    $this->input->get('to', TRUE)
                );
                $site = trim((string)$this->input->get('site', TRUE));
                $rows = $this->Team_model->get_monthly_report($period['month'], $period['start_date'], $period['end_date'], $site);
                $filename = 'Attendance_' . $period['file_slug'] . '.csv';
                header('Content-Type: text/csv; charset=utf-8');
                header("Content-Disposition: attachment; filename=\"{$filename}\"");
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, ['Employee ID','Name','Role','Position','Site','Present','Absent','Leave','Rest','Holiday','Hours Worked','Working Days','Attendance %'], ',', '"', '\\');
                foreach ($rows as $r) {
                    $pct_str = ($r->pct === NULL) ? 'No records' : ($r->pct . '%');
                    fputcsv($out, [$r->employee_id,$r->full_name,$r->role_name,$r->position,$r->site,$r->present,$r->absent,$r->leave,$r->rest,$r->holiday,$r->hours_worked,$r->total,$pct_str], ',', '"', '\\');
                }
                fclose($out);
                break;
            case 'lubricants':
                $rows = $this->Lubricant_model->get_consumption(['month'=>$month]);
                $filename = "Lubricants_{$month}.csv";
                header('Content-Type: text/csv');
                header("Content-Disposition: attachment; filename={$filename}");
                $out = fopen('php://output', 'w');
                fputcsv($out, ['Date','Equipment','Lube Type','Qty Used','Recommended','Variance','WO Number','Recorded By'], ',', '"', '\\');
                foreach ($rows as $r) fputcsv($out, [$r->consumption_date,$r->registration_no,$r->lube_name,$r->quantity_used,$r->recommended_qty,$r->variance,$r->wo_number,$r->recorded_by_name], ',', '"', '\\');
                fclose($out);
                break;
            default:
                show_404();
                return;
        }
        exit;
    }

    public function pdf_daily() {
        $date = $this->input->get('date') ?: date('Y-m-d');
        $data['date']        = $date;
        $data['work_orders'] = $this->Workorder_model->get_by_date($date);
        $data['attendance']  = $this->Team_model->get_attendance_by_date($date);
        $data['print_mode']  = true;
        $this->load->view('reports/pdf_daily', $data);
    }

    /* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
       STORE REGISTERS — the reports that replace the manual
       Inventory.xlsx sheets.

         Excel "Sortie"    →  issue_register()
         Excel "Receipts"  →  receipt_register()
         Excel Alerte /
         Qty to order      →  reorder()

       All three share one filter contract (date range, category,
       allocation / supplier, free-text search) and all three export to
       Excel with exactly the columns of the sheet they replace.
    ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */

    /** Filters shared by the two registers. */
    private function _store_filter() {
        $from = $this->input->get('date_from');
        $to   = $this->input->get('date_to');
        return array(
            'date_from'    => $this->_valid_date($from) ? $from : null,
            'date_to'      => $this->_valid_date($to)   ? $to   : null,
            'category'     => $this->input->get('category'),
            'allocation'   => $this->input->get('allocation'),
            'equipment_id' => $this->input->get('equipment_id'),
            'supplier'     => $this->input->get('supplier'),
            'reference'    => $this->input->get('reference'),
            'person'       => $this->input->get('person'),
            'search'       => $this->input->get('search'),
        );
    }

    private function _valid_date($s) {
        if (!$s) return false;
        $d = DateTime::createFromFormat('Y-m-d', $s);
        return $d && $d->format('Y-m-d') === $s;
    }

    /* ── ISSUE REGISTER (Sortie) ───────────────────────── */
    public function issue_register() {
        $filter = $this->_store_filter();
        $data = array(
            'rows'        => $this->Store_model->issue_register($filter),
            'totals'      => $this->Store_model->register_totals('ISSUE', $filter),
            'by_alloc'    => $this->Store_model->consumption_by_allocation($filter, 15),
            'categories'  => $this->Materials_model->get_categories(),
            'equipment'   => $this->Store_model->equipment_options(),
            'available'   => $this->Store_model->available(),
            'filter'      => $filter,
            'page_title'  => 'Issue Register (Sortie)',
        );
        $this->render('reports/issue_register', $data);
    }

    public function export_issue_register_xlsx() {
        $this->load->helper('xlsx');
        $filter = $this->_store_filter();
        $rows   = $this->Store_model->issue_register($filter);
        $this->_export_register_xlsx('ISSUE', $rows, $filter);
    }

    /* ── RECEIPT REGISTER (Receipts / MRN) ─────────────── */
    public function receipt_register() {
        $filter = $this->_store_filter();
        $data = array(
            'rows'        => $this->Store_model->receipt_register($filter),
            'totals'      => $this->Store_model->register_totals('RECEIVE', $filter),
            'suppliers'   => $this->Store_model->receipt_suppliers(),
            'categories'  => $this->Materials_model->get_categories(),
            'available'   => $this->Store_model->available(),
            'filter'      => $filter,
            'page_title'  => 'Receipt Register',
        );
        $this->render('reports/receipt_register', $data);
    }

    public function export_receipt_register_xlsx() {
        $this->load->helper('xlsx');
        $filter = $this->_store_filter();
        $rows   = $this->Store_model->receipt_register($filter);
        $this->_export_register_xlsx('RECEIVE', $rows, $filter);
    }

    /**
     * One writer for both registers — the column set differs, the formatting,
     * the totals row and the filter banner do not.
     */
    private function _export_register_xlsx($type, $rows, $filter) {
        $isIssue = ($type === 'ISSUE');
        $title   = $isIssue ? 'ISSUE REGISTER (SORTIE)' : 'RECEIPT REGISTER';

        $cols = $isIssue
            ? array(
                array('Date', 12), array('Code', 13), array('Category', 14),
                array('Description', 34), array('Part No', 18), array('Unit', 7),
                array('Qty', 9), array('Allocation', 16), array('Charged To', 14),
                array('Receiver', 16), array('Requisition No', 14),
              )
            : array(
                array('Delivery Date', 13), array('MRN / Ref', 13), array('Code', 13),
                array('Category', 14), array('Description', 34), array('Part No', 18),
                array('Unit', 7), array('Qty Approved', 12), array('Qty Delivered', 12),
                array('Unit Price', 14), array('Line Value', 14), array('Supplier', 28),
              );

        $xlsx = new XlsxWriter();
        $xlsx->addSheet($isIssue ? 'Sortie' : 'Receipts');

        $sTitle = 'bold|fontSize:14|fontName:Arial|align:center|valign:middle|bg:1F4E79|fg:FFFFFF|border:thin';
        $sNote  = 'italic|fontSize:9|fontName:Arial|valign:middle';
        $sHdr   = 'bold|fontSize:10|fontName:Arial|align:center|valign:middle|bg:2E75B6|fg:FFFFFF|border:thin|wrap';
        $sCell  = 'fontSize:10|fontName:Arial|valign:middle|border:thin';
        $sC     = 'fontSize:10|fontName:Arial|align:center|valign:middle|border:thin';
        $sNum   = 'fontSize:10|fontName:Arial|align:right|valign:middle|border:thin|numFmt:#,##0';
        $sMoney = 'fontSize:10|fontName:Arial|align:right|valign:middle|border:thin|numFmt:#,##0.00';
        $sTot   = 'bold|fontSize:10|fontName:Arial|align:right|valign:middle|border:thin|bg:D6E4F0';
        $sTotN  = 'bold|fontSize:10|fontName:Arial|align:right|valign:middle|border:thin|bg:D6E4F0|numFmt:#,##0';
        $sTotM  = 'bold|fontSize:10|fontName:Arial|align:right|valign:middle|border:thin|bg:D6E4F0|numFmt:#,##0.00';
        foreach (array($sTitle,$sNote,$sHdr,$sCell,$sC,$sNum,$sMoney,$sTot,$sTotN,$sTotM) as $s) {
            $xlsx->registerStyle($s);
        }

        $n = count($cols);
        foreach ($cols as $i => $c) $xlsx->setColWidth($i + 1, $c[1]);

        // Row 1 — title
        $xlsx->setRowHeight(1, 28);
        $row = array();
        for ($i = 0; $i < $n; $i++) $row[] = array('v' => $i === 0 ? $title : '', 's' => $sTitle);
        $xlsx->writeRowStyled($row);
        $xlsx->mergeCells('A1', $this->_col_letter($n).'1');

        // Row 2 — the filter that produced the file (so a printout is unambiguous)
        $bits = array();
        if (!empty($filter['date_from']) || !empty($filter['date_to'])) {
            $bits[] = 'Period: '.($filter['date_from'] ?: 'start').' to '.($filter['date_to'] ?: 'today');
        }
        foreach (array('category' => 'Category', 'allocation' => 'Allocation',
                       'supplier' => 'Supplier', 'reference' => 'Reference',
                       'person' => 'Person', 'search' => 'Search') as $k => $lbl) {
            if (!empty($filter[$k])) $bits[] = $lbl.': '.$filter[$k];
        }
        $xlsx->writeRowStyled(array(array(
            'v' => ($bits ? implode('  |  ', $bits) : 'Filter: none (all records)')
                 . '   —   generated '.date('d/m/Y H:i'),
            's' => $sNote)));

        // Row 3 — headers
        $xlsx->setRowHeight(3, 30);
        $hdr = array();
        foreach ($cols as $c) $hdr[] = $c[0];
        $xlsx->writeRow($hdr, $sHdr);
        $xlsx->freezePane('A4');
        $xlsx->setAutoFilter('A3:'.$this->_col_letter($n).'3');

        $start = 4;
        $r = $start;
        foreach ($rows as $x) {
            if ($isIssue) {
                $xlsx->writeRowStyled(array(
                    array('v' => (string)$x->txn_date,          's' => $sC),
                    array('v' => (string)$x->item_code,         's' => $sC),
                    array('v' => (string)$x->category,          's' => $sCell),
                    array('v' => (string)$x->description,       's' => $sCell),
                    array('v' => (string)$x->part_number_size,  's' => $sCell),
                    array('v' => (string)$x->unit,              's' => $sC),
                    array('v' => (float)$x->qty,                's' => $sNum),
                    array('v' => (string)($x->equipment_code ?: $x->allocation_label), 's' => $sC),
                    array('v' => (string)$x->allocation_type,   's' => $sC),
                    array('v' => (string)$x->person,            's' => $sCell),
                    array('v' => (string)$x->reference_no,      's' => $sC),
                ));
            } else {
                $xlsx->writeRowStyled(array(
                    array('v' => (string)$x->txn_date,         's' => $sC),
                    array('v' => (string)$x->reference_no,     's' => $sC),
                    array('v' => (string)$x->item_code,        's' => $sC),
                    array('v' => (string)$x->category,         's' => $sCell),
                    array('v' => (string)$x->description,      's' => $sCell),
                    array('v' => (string)$x->part_number_size, 's' => $sCell),
                    array('v' => (string)$x->unit,             's' => $sC),
                    array('v' => $x->qty_approved === null ? '' : (float)$x->qty_approved, 's' => $sNum),
                    array('v' => (float)$x->qty,               's' => $sNum),
                    array('v' => $x->unit_price === null ? '' : (float)$x->unit_price, 's' => $sMoney),
                    array('v' => '=I'.$r.'*J'.$r,              's' => $sMoney),
                    array('v' => (string)$x->supplier_name,    's' => $sCell),
                ));
            }
            $r++;
        }

        if ($rows) {
            $end  = $r - 1;
            $tot  = array();
            for ($i = 0; $i < $n; $i++) $tot[] = array('v' => '', 's' => $sTot);
            if ($isIssue) {
                $tot[5] = array('v' => 'TOTAL', 's' => $sTot);
                $tot[6] = array('v' => "=SUM(G{$start}:G{$end})", 's' => $sTotN);
            } else {
                $tot[6] = array('v' => 'TOTAL', 's' => $sTot);
                $tot[7] = array('v' => "=SUM(H{$start}:H{$end})", 's' => $sTotN);
                $tot[8] = array('v' => "=SUM(I{$start}:I{$end})", 's' => $sTotN);
                $tot[10] = array('v' => "=SUM(K{$start}:K{$end})", 's' => $sTotM);
            }
            $xlsx->writeRowStyled($tot);
        } else {
            $empty = array();
            for ($i = 0; $i < $n; $i++) $empty[] = array('v' => $i === 0 ? 'No movements match this filter' : '', 's' => $sCell);
            $xlsx->writeRowStyled($empty);
        }

        $name = ($isIssue ? 'Issue_Register_' : 'Receipt_Register_').date('Ymd_His').'.xlsx';
        $tmp  = wms_temp_path($name);
        $xlsx->save($tmp);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="'.$name.'"');
        header('Content-Length: '.filesize($tmp));
        readfile($tmp);
        unlink($tmp);
        exit;
    }

    private function _col_letter($n) {
        $s = '';
        while ($n > 0) { $m = ($n - 1) % 26; $s = chr(65 + $m).$s; $n = (int)(($n - $m) / 26); }
        return $s;
    }

    /* ── RE-ORDER REPORT ───────────────────────────────── */
    public function reorder() {
        $filter = array(
            'category' => $this->input->get('category'),
            'location' => $this->input->get('location'),
            'search'   => $this->input->get('search'),
        );
        $rows = $this->Materials_model->get_reorder_items($filter);
        $data = array(
            'rows'       => $rows,
            'categories' => $this->Materials_model->get_categories(),
            'locations'  => $this->Materials_model->get_locations(),
            'extended'   => $this->Materials_model->has_extended_fields(),
            'filter'     => $filter,
            'page_title' => 'Re-order Report',
        );
        $this->render('reports/reorder', $data);
    }

    public function export_reorder_xlsx() {
        $this->load->helper('xlsx');
        $filter = array(
            'category' => $this->input->get('category'),
            'location' => $this->input->get('location'),
            'search'   => $this->input->get('search'),
        );
        $rows = $this->Materials_model->get_reorder_items($filter);

        $xlsx = new XlsxWriter();
        $xlsx->addSheet('Re-order');
        $sTitle = 'bold|fontSize:14|fontName:Arial|align:center|valign:middle|bg:1F4E79|fg:FFFFFF|border:thin';
        $sHdr   = 'bold|fontSize:10|fontName:Arial|align:center|valign:middle|bg:2E75B6|fg:FFFFFF|border:thin|wrap';
        $sCell  = 'fontSize:10|fontName:Arial|valign:middle|border:thin';
        $sC     = 'fontSize:10|fontName:Arial|align:center|valign:middle|border:thin';
        $sNum   = 'fontSize:10|fontName:Arial|align:right|valign:middle|border:thin|numFmt:#,##0';
        $sAlert = 'bold|fontSize:10|fontName:Arial|align:center|valign:middle|border:thin|bg:FFE0E0|fg:C00000';
        $sMoney = 'fontSize:10|fontName:Arial|align:right|valign:middle|border:thin|numFmt:#,##0.00';
        $sTot   = 'bold|fontSize:10|fontName:Arial|align:right|valign:middle|border:thin|bg:D6E4F0';
        $sTotN  = 'bold|fontSize:10|fontName:Arial|align:right|valign:middle|border:thin|bg:D6E4F0|numFmt:#,##0';
        foreach (array($sTitle,$sHdr,$sCell,$sC,$sNum,$sAlert,$sMoney,$sTot,$sTotN) as $s) $xlsx->registerStyle($s);

        $widths = array(13, 14, 34, 18, 7, 11, 11, 11, 11, 13, 14, 16, 26);
        foreach ($widths as $i => $w) $xlsx->setColWidth($i + 1, $w);

        $xlsx->setRowHeight(1, 28);
        $t = array();
        for ($i = 0; $i < 13; $i++) $t[] = array('v' => $i === 0 ? 'RE-ORDER REPORT — ITEMS AT OR BELOW MINIMUM STOCK' : '', 's' => $sTitle);
        $xlsx->writeRowStyled($t);
        $xlsx->mergeCells('A1', 'M1');

        $xlsx->setRowHeight(2, 30);
        $xlsx->writeRow(array('Item Code','Category','Description','Part No / Size','Unit',
                              'In Stock','Min Stock','Max Stock','Qty to Order','Alert',
                              'Price UGX','Location','Supplier'), $sHdr);
        $xlsx->freezePane('A3');
        $xlsx->setAutoFilter('A2:M2');

        $start = 3; $r = $start;
        foreach ($rows as $m) {
            $xlsx->writeRowStyled(array(
                array('v' => (string)$m->item_code,   's' => $sC),
                array('v' => (string)$m->category,    's' => $sCell),
                array('v' => (string)$m->description, 's' => $sCell),
                array('v' => (string)$m->part_number_size, 's' => $sCell),
                array('v' => (string)$m->unit,        's' => $sC),
                array('v' => (int)$m->final_qty,      's' => $sNum),
                array('v' => isset($m->min_stock) && $m->min_stock !== null ? (int)$m->min_stock : '', 's' => $sNum),
                array('v' => isset($m->max_stock) && $m->max_stock !== null ? (int)$m->max_stock : '', 's' => $sNum),
                array('v' => (int)$m->qty_to_order,   's' => $sNum),
                array('v' => ((int)$m->final_qty <= 0 ? 'OUT OF STOCK' : 'ORDER'), 's' => $sAlert),
                array('v' => isset($m->unit_price_ugx) && $m->unit_price_ugx !== null ? (float)$m->unit_price_ugx : '', 's' => $sMoney),
                array('v' => (string)$m->location,    's' => $sCell),
                array('v' => isset($m->supplier_name) ? (string)$m->supplier_name : '', 's' => $sCell),
            ));
            $r++;
        }
        if ($rows) {
            $end = $r - 1;
            $tot = array();
            for ($i = 0; $i < 13; $i++) $tot[] = array('v' => '', 's' => $sTot);
            $tot[4] = array('v' => 'TOTAL', 's' => $sTot);
            $tot[8] = array('v' => "=SUM(I{$start}:I{$end})", 's' => $sTotN);
            $xlsx->writeRowStyled($tot);
        }

        $name = 'Reorder_Report_'.date('Ymd_His').'.xlsx';
        $tmp  = wms_temp_path($name);
        $xlsx->save($tmp);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="'.$name.'"');
        header('Content-Length: '.filesize($tmp));
        readfile($tmp);
        unlink($tmp);
        exit;
    }

    /* ─────────────────────────────────────────────────────────────────────
     |  W36 weekly-report TARGETS  (plan tasks W0.4 + W1.5)
     |
     |  Availability targets live in `equipment_kpi_targets`: a standing
     |  default per machine or per group, plus an optional override for one
     |  single day (business decision OPEN-1: Mon 95 %, Tue 90 %, …).
     |
     |  Reading is open to the roles that may open Reports; every write is
     |  restricted to the Supervisor (MY_Controller already treats a Manager
     |  POST as view-only — this is the explicit second line of defence).
     * ───────────────────────────────────────────────────────────────────── */

    public function weekly_targets() {
        $requested = $this->input->get('week_start');
        $week_start = $this->Settings_model->week_start($requested ? $requested : null);
        $bounds     = $this->Settings_model->week_bounds($week_start);
        $days       = $this->Kpi_target_model->week_days($bounds['start']);

        $machines = $this->Equipment_model->get_all(array('reportable' => 1));

        $data['page_title']      = 'Availability Targets';
        $data['week_start']      = $bounds['start'];
        $data['week_end']        = $bounds['end'];
        $data['report_name']     = $this->W36_report_model->report_name($bounds['start']);
        $data['days']            = $days;
        $data['machines']        = $machines;
        $data['groups']          = $this->Equipment_model->report_groups();
        $data['has_table']       = $this->Kpi_target_model->table_ready();
        $data['fallback_target'] = $this->Settings_model->default_availability_target();
        $data['can_edit']        = $this->is_supervisor();
        // every targets form is a POST: they must carry the token (csrf_protection = TRUE)
        $data['csrf_token']      = $this->security->get_csrf_hash();
        $data['csrf_name']       = $this->security->get_csrf_token_name();

        // labels for the target table
        $labels = array();
        foreach ($machines as $m) $labels[(int)$m->id] = $m->registration_no;
        $data['machine_labels'] = $labels;

        $targets = $data['has_table']
            ? $this->Kpi_target_model->for_week($bounds['start'], $bounds['end'])
            : array();
        $data['targets']      = $targets;
        $data['target_rows']  = $this->Kpi_target_model->describe($targets, $labels, $data['groups']);
        $data['coverage']     = $this->Kpi_target_model->coverage($machines, $targets, $days);

        // the week matrix: what the report would print per machine-day, with the
        // provenance of every value (same code path the generator will use)
        $data['resolved']     = $this->Kpi_target_model->resolve_map($machines, $days, $targets, $data['fallback_target']);

        // the row being edited (inline edit form), if any
        $edit_id = (int)$this->input->get('edit');
        $data['edit_row'] = ($edit_id && $data['has_table']) ? $this->Kpi_target_model->get($edit_id) : null;

        // what happens when the same bulk action is applied (preview count)
        $data['fleet_size'] = count($machines);

        $this->render('reports/weekly_targets', $data);
    }

    /** Save one target (new or edited). */
    public function weekly_targets_save() {
        if (!$this->is_supervisor()) {
            $this->session->set_flashdata('error', 'Only a supervisor can change the availability targets.');
            redirect('reports/weekly_targets');
        }
        if ($this->input->method() !== 'post') redirect('reports/weekly_targets');

        $post      = $this->input->post();
        $week      = $this->Settings_model->week_start($this->input->post('week_start') ? $this->input->post('week_start') : null);
        $edit_id   = (int)$this->input->post('id');

        if ($edit_id > 0) {
            $ok = $this->Kpi_target_model->update_row($edit_id, $post, $this->user_id);
        } else {
            $ok = $this->Kpi_target_model->save($post, $this->user_id);
        }

        if ($ok) {
            $this->session->set_flashdata('success', $edit_id > 0 ? 'Target updated.' : 'Target saved.');
        } else {
            $this->session->set_flashdata('error', $this->Kpi_target_model->last_error() ?: 'The target could not be saved.');
        }
        redirect('reports/weekly_targets?week_start=' . $week);
    }

    /**
     * Apply one percentage to many machines at once — the whole fleet, one
     * group, or a hand-picked list; as a standing default or for a single day.
     */
    public function weekly_targets_bulk() {
        if (!$this->is_supervisor()) {
            $this->session->set_flashdata('error', 'Only a supervisor can change the availability targets.');
            redirect('reports/weekly_targets');
        }
        if ($this->input->method() !== 'post') redirect('reports/weekly_targets');

        $week = $this->Settings_model->week_start($this->input->post('week_start') ? $this->input->post('week_start') : null);

        $opts = array(
            'group_code'  => $this->input->post('group_code'),
            'target_date' => $this->input->post('target_date'),
        );

        $written = $this->Kpi_target_model->bulk_apply($this->input->post('target_pct'), $opts, $this->user_id);

        if ($written > 0) {
            $this->session->set_flashdata('success', $written . ' machine target' . ($written === 1 ? '' : 's') . ' saved.');
        } else {
            $this->session->set_flashdata('error', $this->Kpi_target_model->last_error() ?: 'No target was written.');
        }
        redirect('reports/weekly_targets?week_start=' . $week);
    }

    /** Copy the previous week's per-day overrides onto the selected week. */
    public function weekly_targets_copy() {
        if (!$this->is_supervisor()) {
            $this->session->set_flashdata('error', 'Only a supervisor can change the availability targets.');
            redirect('reports/weekly_targets');
        }
        if ($this->input->method() !== 'post') redirect('reports/weekly_targets');

        $week    = $this->Settings_model->week_start($this->input->post('week_start') ? $this->input->post('week_start') : null);
        $from    = date('Y-m-d', strtotime($week . ' -7 days'));
        $copied  = $this->Kpi_target_model->copy_week($from, $week, $this->user_id);

        $this->session->set_flashdata($copied > 0 ? 'success' : 'error',
            $copied > 0
                ? $copied . ' day-specific target' . ($copied === 1 ? '' : 's') . ' copied from the week of ' . $from . '.'
                : 'The previous week has no day-specific targets to copy.');
        redirect('reports/weekly_targets?week_start=' . $week);
    }

    public function weekly_targets_delete($id = 0) {
        if (!$this->require_post()) return;
        if (!$this->is_supervisor()) {
            $this->session->set_flashdata('error', 'Only a supervisor can change the availability targets.');
            redirect('reports/weekly_targets');
        }

        $week = $this->Settings_model->week_start($this->input->post('week_start', TRUE) ?: null);
        if ($this->Kpi_target_model->delete($id)) {
            $this->session->set_flashdata('success', 'Target deleted.');
        } else {
            $this->session->set_flashdata('error', 'That target could not be deleted.');
        }
        redirect('reports/weekly_targets?week_start=' . $week);
    }

    /* ─────────────────────────────────────────────────────────────────
     |  W36 WEEKLY REPORT — preview   (plan tasks W2.1 + W2.2)
     |
     |  One request answers the whole workflow: pick a week (an ISO label or
     |  any date in it), read the report the Excel file will contain, and see
     |  what the data cannot yet say. Every figure comes from
     |  W36_report_model::build(), the same call the Excel writer will use, so
     |  the preview can never disagree with the download (decision D-W1).
     * ───────────────────────────────────────────────────────────────── */

    public function weekly_w36() {
        $requested = trim((string)$this->input->get('week'));
        $notice    = null;

        // 1. the ISO-week picker — the report's own label, e.g. 2026-W36
        $week_start = $this->Settings_model->iso_week_start($requested);
        if ($week_start === null && $requested !== '') {
            $notice = '“' . $requested . '” is not an ISO week (use e.g. ' . $this->Settings_model->iso_week_label() . ').';
        }

        // 2. a custom range, given as any date inside the week
        if ($week_start === null) {
            $date = trim((string)$this->input->get('week_start'));
            if ($date !== '') {
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && strtotime($date) !== false) {
                    $week_start = $this->Settings_model->week_start($date);
                } else {
                    $notice = '“' . $date . '” is not a date (use yyyy-mm-dd).';
                }
            }
        }

        // 3. nothing usable → the current week
        if ($week_start === null) $week_start = $this->Settings_model->week_start();

        // a published week is served FROZEN (AC7); an unpublished one is built
        // live, where a machine still down keeps counting to today (D4)
        $t0     = microtime(true);
        $report = $this->W36_report_model->report_for_week($week_start, array(
            'live' => $this->input->get('live') !== null,
        ));
        $build_ms = (int)round((microtime(true) - $t0) * 1000);

        $data['page_title']  = $report['report_name'] . ' Weekly Report';
        $data['report']      = $report;
        $data['report_name'] = $report['report_name'];
        $data['snapshot']    = isset($report['snapshot']) ? $report['snapshot'] : null;
        $data['published']   = isset($report['published']) ? $report['published'] : null;
        $data['is_live']     = empty($report['snapshot']);
        // the publish form is a POST: it must carry the token (csrf_protection = TRUE)
        $data['csrf_token']  = $this->security->get_csrf_hash();
        $data['csrf_name']   = $this->security->get_csrf_token_name();
        $data['week_start']  = $report['week']['start'];
        $data['week_end']    = $report['week']['end'];
        $data['week_label']  = $report['week']['iso_label'];   // ISO picker value; report_name is the display/file label
        $data['notice']      = $notice;
        $data['build_ms']    = $build_ms;
        $data['prev_week']   = $this->Settings_model->iso_week_label(date('Y-m-d', strtotime($report['week']['start'] . ' -7 days')));
        $data['next_week']   = $this->Settings_model->iso_week_label(date('Y-m-d', strtotime($report['week']['start'] . ' +7 days')));
        $this->render('reports/weekly_w36', $data);
    }

    /** Save the selected week's display / workbook name from the report dashboard. */
    public function weekly_report_name() {
        if (!$this->is_supervisor() && !$this->is_manager()) {
            $this->session->set_flashdata('error', 'Only a supervisor or a manager can change a weekly report name.');
            redirect('reports/weekly_w36');
        }
        if ($this->input->method() !== 'post') redirect('reports/weekly_w36');

        $requested = trim((string)$this->input->post('week'));
        $week_start = $this->Settings_model->iso_week_start($requested);
        if ($week_start === null) {
            $date = trim((string)$this->input->post('week_start'));
            $week_start = ($date !== '' && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $date) && strtotime($date) !== false)
                ? $this->Settings_model->week_start($date)
                : $this->Settings_model->week_start();
        }

        if ($this->W36_report_model->is_published($week_start)) {
            $this->session->set_flashdata('error', 'A published weekly report is frozen; re-publish it to change its name.');
            redirect('reports/weekly_w36?week=' . $this->Settings_model->iso_week_label($week_start));
        }

        $name = trim((string)$this->input->post('report_name'));
        if ($name !== '' && $this->W36_report_model->save_report_name($week_start, $name, $this->user_id)) {
            $this->session->set_flashdata('success', 'Weekly report name saved for this week.');
        } else {
            $this->session->set_flashdata('error',
                'The weekly report name could not be saved. Enter a valid name and make sure the weekly_report_names table is installed.');
        }

        $label = $this->Settings_model->iso_week_label($week_start);
        redirect('reports/weekly_w36?week=' . $label);
    }

    /**
     * Download the generated W36 workbook   (plan tasks W3.2 + W3.3)
     *
     * The preview and the file come from the same W36_report_model::build()
     * call, so the download can never disagree with what was read on screen
     * (decision D-W1). The week is the same picker input the preview takes.
     */
    public function export_weekly_w36_xlsx() {
        $requested  = trim((string)$this->input->get('week'));
        $week_start = $this->Settings_model->iso_week_start($requested);

        if ($week_start === null) {
            $date = trim((string)$this->input->get('week_start'));
            $week_start = ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && strtotime($date) !== false)
                ? $this->Settings_model->week_start($date)
                : $this->Settings_model->week_start();
        }

        // AC7: a published week re-downloads as published. `report_for_week()`
        // hands back the frozen array for it and a fresh build otherwise, so the
        // file and the screen can never disagree (D-W1).
        $report = $this->W36_report_model->report_for_week($week_start);

        $this->load->library('w36_excel');
        $filename = $this->w36_excel->filename($report);
        $tmp      = $this->_tmp_path($filename);
        $this->w36_excel->save($report, $tmp);
        $this->_send_file($tmp, $filename);
    }

    /**
     * The print / PDF view of a week   (plan task 4.3)
     *
     * This is the house pattern for a PDF in this application: a standalone HTML
     * page that the browser prints — or saves as PDF — so no PDF library is
     * needed and the paper copy is the screen the reviewer approved
     * (`_export_workshop_weekly_pdf()` does the same for `workshop_weekly`).
     * The page is the preview view rendered with `print_mode`: it expands the day
     * blocks, drops the screen chrome and prints the sample's own page size
     * (A4 landscape) with the three breakdown KPIs and a sign-off line. Because
     * it renders the same view, the paper, the screen and the Excel file can
     * never disagree. A published week prints frozen, like every other reading.
     */
    public function export_weekly_w36_pdf() {
        $week_start = $this->Settings_model->iso_week_start(trim((string)$this->input->get('week')));
        if ($week_start === null) {
            $date = trim((string)$this->input->get('week_start'));
            $week_start = ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && strtotime($date) !== false)
                ? $this->Settings_model->week_start($date)
                : $this->Settings_model->week_start();
        }

        $report = $this->W36_report_model->report_for_week($week_start, array(
            'live' => $this->input->get('live') !== null,
        ));

        $data = array(
            'report'       => $report,
            'snapshot'     => isset($report['snapshot']) ? $report['snapshot'] : null,
            'published'    => isset($report['published']) ? $report['published'] : null,
            'is_live'      => empty($report['snapshot']),
            'week_start'   => $report['week']['start'],
            'week_end'     => $report['week']['end'],
            'week_label'   => $report['week']['iso_label'],
            'notice'       => null,
            'build_ms'     => null,
            'prev_week'    => null,
            'next_week'    => null,
            'print_mode'   => true,
            // printing is what the page is for; `?noauto=1` opens it quietly
            'auto_print'   => $this->input->get('noauto') === null,
            'generated_at' => date('Y-m-d H:i'),
            'logo_url'     => base_url('uploads/logoam.png'),
        );

        $body = $this->load->view('reports/weekly_w36', $data, true);

        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
           . '<meta name="viewport" content="width=device-width, initial-scale=1">'
           . '<title>' . html_escape($report['report_name'] . ' ' . $data['week_label'] . ' — print') . '</title>'
           . '</head><body class="w36-print-doc">'
           . $body
           . ($data['auto_print'] ? '<script>window.onload=function(){window.print();};</script>' : '')
           . '</body></html>';
    }

    /**
     * Publish (freeze) a week   (gap G6 / plan task 1.4)
     *
     * From this moment the week is read as published: the preview and the
     * download serve the frozen array, and later work orders, targets or
     * statuses cannot change the numbers the business signed off (D4, AC7).
     * Publishing an already published week is refused unless `force` is sent —
     * the freeze is the audit trail, not a cache.
     */
    public function publish_weekly_w36() {
        if (!$this->require_post()) return;
        if (!$this->is_supervisor() && !$this->is_manager()) {
            $this->session->set_flashdata('error', 'Only a supervisor or a manager can publish a weekly report.');
            redirect('reports/weekly_w36');
        }

        $week_start = $this->Settings_model->iso_week_start(trim((string)$this->input->get('week')));
        if ($week_start === null) {
            $date = trim((string)$this->input->get('week_start'));
            $week_start = ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && strtotime($date) !== false)
                ? $this->Settings_model->week_start($date)
                : $this->Settings_model->week_start();
        }

        $result = $this->W36_report_model->publish($week_start, $this->user_id, (bool)$this->input->post('force'));
        $label  = isset($result['snapshot']['iso_label']) && $result['snapshot']['iso_label']
                ? $result['snapshot']['iso_label']
                : $this->Settings_model->iso_week_label($week_start);

        if (!empty($result['published'])) {
            $this->session->set_flashdata('success',
                $label . ' published — the week is now frozen: re-opening or re-downloading it returns these figures.');
        } elseif (isset($result['reason']) && $result['reason'] === 'already_published') {
            $this->session->set_flashdata('error',
                $label . ' was already published on ' . $result['snapshot']['published_at']
                . ' — a published week is not overwritten.');
        } else {
            $this->session->set_flashdata('error',
                'The week could not be published (' . (isset($result['reason']) ? $result['reason'] : 'unknown') . ').');
        }

        redirect('reports/weekly_w36?week=' . $label);
    }
}
