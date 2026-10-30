<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Inspections extends MY_Controller {

    public $Inspection_model;
    public $Equipment_model;

    public function __construct() {
        parent::__construct();
        $this->load->model(array('Inspection_model', 'Equipment_model'));
    }

    /* ─────────────────────────────────────────────────────
       SHARED REGISTER DATASET — powers index(), records(), & export_csv()
    ───────────────────────────────────────────────────── */
    private function _resolve_lang() {
        $lang = $this->input->get('lang', TRUE);
        if (!$lang) {
            $lang = $this->session->userdata('inspection_lang') ?: 'en';
        } else {
            $lang = ($lang === 'fr') ? 'fr' : 'en';
            $this->session->set_userdata('inspection_lang', $lang);
        }
        return ($lang === 'fr') ? 'fr' : 'en';
    }

    private function _build_register_dataset($forced_code = NULL, $paginate = TRUE) {
        $lang     = $this->_resolve_lang();
        $overview = $this->Inspection_model->get_register_overview();
        $allRows  = $overview['rows'];
        $todayStr = $overview['today'];

        $tab = strtolower((string)trim((string)$this->input->get('tab', TRUE)));
        $validTabs = array('all', 'p1_nogo', 'failed', 'passed', 'unsigned', 'draft', 'forms');
        if (!in_array($tab, $validTabs, TRUE)) {
            $tab = 'all';
        }

        $q       = trim((string)$this->input->get('q', TRUE));
        if ($q === '') {
            $q = trim((string)$this->input->get('search', TRUE));
        }
        $fTpl    = $forced_code !== NULL && $forced_code !== ''
            ? strtolower((string)trim((string)$forced_code))
            : strtolower((string)trim((string)$this->input->get('template', TRUE)));
        if (isset(Inspection_model::CODE_ALIASES[$fTpl])) {
            $fTpl = Inspection_model::CODE_ALIASES[$fTpl];
        }
        $fEquip  = (int)$this->input->get('equipment_id', TRUE);
        $fLang   = strtolower((string)trim((string)$this->input->get('rec_lang', TRUE)));
        if (!in_array($fLang, array('en', 'fr'), TRUE)) {
            $fLang = '';
        }
        $fFrom   = trim((string)$this->input->get('date_from', TRUE));
        $fTo     = trim((string)$this->input->get('date_to', TRUE));

        $sort = strtolower((string)trim((string)$this->input->get('sort', TRUE)));
        $allowedSort = array('inspection_date', 'id', 'machine', 'template', 'smr', 'verdict', 'inspector');
        if (!in_array($sort, $allowedSort, TRUE)) {
            $sort = 'inspection_date';
        }
        $dir = strtolower((string)trim((string)$this->input->get('dir', TRUE)));
        if (!in_array($dir, array('asc', 'desc'), TRUE)) {
            $dir = ($sort === 'machine' || $sort === 'template' || $sort === 'inspector' || $sort === 'verdict') ? 'asc' : 'desc';
        }

        $viewMode = strtolower((string)trim((string)$this->input->get('view', TRUE)));
        if (!in_array($viewMode, array('table', 'cards'), TRUE)) {
            $viewMode = 'table';
        }

        // Resolve optional focused template object (e.g. /inspections/records/{code})
        $focusedTemplate = NULL;
        if ($fTpl !== '') {
            foreach ($overview['templates'] as $tObj) {
                if (strtolower((string)$tObj->code) === $fTpl) {
                    $focusedTemplate = $tObj;
                    break;
                }
            }
            if ($forced_code !== NULL && !$focusedTemplate) {
                return NULL;
            }
        }

        // Global KPI telemetry computed across full register before slicing tabs
        $kpi = array(
            'total_records'      => count($allRows),
            'today_records'      => 0,
            'p1_nogo'            => 0,
            'failed_records'     => 0,
            'total_failed_items' => 0,
            'clean_pass'         => 0,
            'pass_rate'          => 100,
            'unsigned'           => 0,
            'drafts'             => 0,
            'active_templates'   => count($overview['active_templates']),
            'total_items'        => (int)$overview['total_items'],
        );

        foreach ($allRows as $r) {
            if ((string)$r->inspection_date === $todayStr) {
                $kpi['today_records']++;
            }
            $kpi['total_failed_items'] += (int)$r->fail_count;
            if ($r->verdict_state === 'draft') {
                $kpi['drafts']++;
            } elseif ($r->verdict_state === 'p1_nogo') {
                $kpi['p1_nogo']++;
                $kpi['failed_records']++;
            } elseif ($r->verdict_state === 'failed') {
                $kpi['failed_records']++;
            } else {
                $kpi['clean_pass']++;
            }
            if (!empty($r->is_unsigned)) {
                $kpi['unsigned']++;
            }
        }
        $submittedTotal = max(0, $kpi['total_records'] - $kpi['drafts']);
        if ($submittedTotal > 0) {
            $kpi['pass_rate'] = (int)round(($kpi['clean_pass'] / $submittedTotal) * 100);
        }

        // Stage 1: Apply facet filters (search, template, equipment, record language, date range)
        $faceted = array();
        $qLower  = strtolower($q);
        foreach ($allRows as $r) {
            if ($fTpl !== '' && strtolower((string)$r->template_code) !== $fTpl) {
                continue;
            }
            if ($fEquip > 0 && (int)$r->equipment_id !== $fEquip) {
                continue;
            }
            if ($fLang !== '' && strtolower((string)$r->language) !== $fLang) {
                continue;
            }
            if ($fFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fFrom) && (string)$r->inspection_date < $fFrom) {
                continue;
            }
            if ($fTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fTo) && (string)$r->inspection_date > $fTo) {
                continue;
            }
            if ($qLower !== '') {
                $hay = strtolower(implode(' ', array(
                    'insp-' . (int)$r->id,
                    '#' . (int)$r->id,
                    (string)$r->machine_label,
                    (string)$r->fleet_number,
                    (string)$r->equipment_type,
                    (string)$r->model,
                    (string)$r->serial_number,
                    (string)$r->template_code,
                    (string)$r->template_name_en,
                    (string)$r->template_name_fr,
                    (string)$r->mechanic_name,
                    (string)$r->operator_name,
                    (string)$r->supervisor_name,
                    (string)$r->general_comments,
                )));
                if (strpos($hay, $qLower) === FALSE) {
                    continue;
                }
            }
            $faceted[] = $r;
        }

        // Stage 2: Tab badge counts across active facet filter context
        $tabCounts = array(
            'all'      => count($faceted),
            'p1_nogo'  => 0,
            'failed'   => 0,
            'passed'   => 0,
            'unsigned' => 0,
            'draft'    => 0,
            'forms'    => count($overview['active_templates']),
        );
        foreach ($faceted as $r) {
            if ($r->verdict_state === 'p1_nogo') {
                $tabCounts['p1_nogo']++;
                $tabCounts['failed']++;
            } elseif ($r->verdict_state === 'failed') {
                $tabCounts['failed']++;
            } elseif ($r->verdict_state === 'passed') {
                $tabCounts['passed']++;
            } elseif ($r->verdict_state === 'draft') {
                $tabCounts['draft']++;
            }
            if (!empty($r->is_unsigned)) {
                $tabCounts['unsigned']++;
            }
        }

        // Stage 3: Slice active tab
        $filtered = array();
        foreach ($faceted as $r) {
            if ($tab === 'p1_nogo' && $r->verdict_state !== 'p1_nogo') continue;
            if ($tab === 'failed' && !in_array($r->verdict_state, array('p1_nogo', 'failed'), TRUE)) continue;
            if ($tab === 'passed' && $r->verdict_state !== 'passed') continue;
            if ($tab === 'unsigned' && empty($r->is_unsigned)) continue;
            if ($tab === 'draft' && $r->verdict_state !== 'draft') continue;
            $filtered[] = $r;
        }

        // Stage 4: Optional ?ids=1,2,3 subset for multi-select batch CSV export
        $idsRaw = trim((string)$this->input->get('ids', TRUE));
        if (!$paginate && $idsRaw !== '') {
            $idMap = array();
            foreach (explode(',', $idsRaw) as $tok) {
                $v = (int)trim($tok);
                if ($v > 0) $idMap[$v] = TRUE;
            }
            if (!empty($idMap)) {
                $sub = array();
                foreach ($filtered as $r) {
                    if (isset($idMap[(int)$r->id])) $sub[] = $r;
                }
                $filtered = $sub;
            }
        }

        // Stage 5: Deterministic multi-column sorting
        $mult = ($dir === 'asc') ? 1 : -1;
        usort($filtered, function ($a, $b) use ($sort, $mult, $lang) {
            if ($sort === 'id') {
                return (((int)$a->id) <=> ((int)$b->id)) * $mult;
            }
            if ($sort === 'machine') {
                $cmp = strcasecmp((string)$a->machine_label, (string)$b->machine_label);
                return $cmp !== 0 ? ($cmp * $mult) : (((int)$b->id) <=> ((int)$a->id));
            }
            if ($sort === 'template') {
                $ta = $lang === 'fr' ? (string)$a->template_name_fr : (string)$a->template_name_en;
                $tb = $lang === 'fr' ? (string)$b->template_name_fr : (string)$b->template_name_en;
                $cmp = strcasecmp($ta, $tb);
                return $cmp !== 0 ? ($cmp * $mult) : (((int)$b->id) <=> ((int)$a->id));
            }
            if ($sort === 'smr') {
                $sa = is_numeric($a->smr) ? (float)$a->smr : -1.0;
                $sb = is_numeric($b->smr) ? (float)$b->smr : -1.0;
                $cmp = $sa <=> $sb;
                return $cmp !== 0 ? ($cmp * $mult) : (((int)$b->id) <=> ((int)$a->id));
            }
            if ($sort === 'verdict') {
                $cmp = ((int)$a->severity_rank) <=> ((int)$b->severity_rank);
                if ($cmp !== 0) return $cmp * $mult;
                $cmpFail = ((int)$b->fail_count) <=> ((int)$a->fail_count);
                return $cmpFail !== 0 ? ($cmpFail * $mult) : (((int)$b->id) <=> ((int)$a->id));
            }
            if ($sort === 'inspector') {
                $cmp = strcasecmp((string)$a->inspector_label, (string)$b->inspector_label);
                return $cmp !== 0 ? ($cmp * $mult) : (((int)$b->id) <=> ((int)$a->id));
            }
            // Default: inspection_date then id
            $cmp = strcmp((string)$a->inspection_date, (string)$b->inspection_date);
            if ($cmp !== 0) return $cmp * $mult;
            return (((int)$a->id) <=> ((int)$b->id)) * $mult;
        });

        // Stage 6: Server-side pagination
        $allowedPp     = array(10, 25, 50, 100, 150);
        $perPage       = (int)$this->input->get('per_page', TRUE);
        if (!in_array($perPage, $allowedPp, TRUE)) $perPage = 25;
        $totalFiltered = count($filtered);
        $totalPages    = max(1, (int)ceil($totalFiltered / $perPage));
        $currentPage   = max(1, min($totalPages, (int)$this->input->get('page', TRUE) ?: 1));
        $offset        = ($currentPage - 1) * $perPage;
        $pagedRows     = $paginate ? array_slice($filtered, $offset, $perPage) : $filtered;

        return array(
            'records'          => $pagedRows,
            'templates'        => $overview['active_templates'],
            'all_templates'    => $overview['templates'],
            'record_counts'    => $overview['record_counts'],
            'equipment_list'   => $this->Equipment_model->get_all_active(),
            'focused_template' => $focusedTemplate,
            'forced_code'      => $forced_code,
            'kpi'              => $kpi,
            'tab_counts'       => $tabCounts,
            'tab'              => $tab,
            'q'                => $q,
            'f_template'       => $fTpl,
            'f_equip'          => $fEquip,
            'f_rec_lang'       => $fLang,
            'f_from'           => $fFrom,
            'f_to'             => $fTo,
            'sort'             => $sort,
            'dir'              => $dir,
            'view_mode'        => $viewMode,
            'per_page'         => $perPage,
            'allowed_pp'       => $allowedPp,
            'current_page'     => $currentPage,
            'total_pages'      => $totalPages,
            'total_filtered'   => $totalFiltered,
            'offset'           => $offset,
            'lang'             => $lang,
            'can_manage'       => $this->is_supervisor(),
            'can_submit'       => $this->can_write(),
        );
    }

    private function _csv_guard($val) {
        $s = (string)$val;
        if ($s !== '' && in_array($s[0], array('=', '+', '-', '@', "\t", "\r"), TRUE)) {
            return "'" . $s;
        }
        return $s;
    }

    /* ─────────────────────────────────────────────────────
       INDEX — Unified Fleet Inspection Register & Bilingual Form Hub
    ───────────────────────────────────────────────────── */
    public function index() {
        $data = $this->_build_register_dataset(NULL, TRUE);
        $data['page_title'] = ($data['lang'] === 'fr' ? 'Inspections & Formulaires' : 'Inspections & Safety Checklists');
        $this->render('inspections/index', $data);
    }

    /* ─────────────────────────────────────────────────────
       EXPORT CSV — Filtered / Multi-Select UTF-8 BOM CSV
    ───────────────────────────────────────────────────── */
    public function export_csv() {
        $data = $this->_build_register_dataset(NULL, FALSE);
        $rows = $data['records'];
        $lang = $data['lang'];

        $filename = 'inspections-register-' . date('Y-m-d') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");

        fputcsv($out, array(
            'Record ID',
            'Inspection Date',
            'Status',
            'Verdict',
            'P1 No-Go Count',
            'Failed Items',
            'Checked Items',
            'Machine / Fleet ID',
            'Equipment Type',
            'Model',
            'Serial No',
            'Meter (SMR)',
            'Checklist Code',
            'Checklist Name',
            'Language',
            'Inspector / Mechanic',
            'Operator',
            'Supervisor Sign-Off',
            'General Comments',
        ));

        foreach ($rows as $r) {
            if ($r->verdict_state === 'p1_nogo') {
                $verdictTxt = 'No-Go (P1 Breakdown)';
            } elseif ($r->verdict_state === 'failed') {
                $verdictTxt = 'Defects Found';
            } elseif ($r->verdict_state === 'draft') {
                $verdictTxt = 'Draft';
            } else {
                $verdictTxt = 'Clean Pass';
            }
            $tplName = ($lang === 'fr') ? $r->template_name_fr : $r->template_name_en;
            $supTxt  = !empty($r->is_signed)
                ? ('Signed' . (trim((string)$r->supervisor_name) !== '' ? ' (' . trim((string)$r->supervisor_name) . ')' : ''))
                : 'Unsigned';

            fputcsv($out, array(
                'INSP-' . (int)$r->id,
                $this->_csv_guard($r->inspection_date),
                ucfirst((string)$r->status),
                $verdictTxt,
                (int)$r->p1_count,
                (int)$r->fail_count,
                (int)$r->checked_count,
                $this->_csv_guard($r->machine_label),
                $this->_csv_guard($r->equipment_type),
                $this->_csv_guard($r->model),
                $this->_csv_guard($r->serial_number),
                $this->_csv_guard($r->smr),
                strtoupper((string)$r->template_code),
                $this->_csv_guard($tplName),
                strtoupper((string)$r->language),
                $this->_csv_guard($r->mechanic_name),
                $this->_csv_guard($r->operator_name),
                $this->_csv_guard($supTxt),
                $this->_csv_guard($r->general_comments),
            ));
        }

        fclose($out);
        exit;
    }

    /* ─────────────────────────────────────────────────────
       SUMMARY JSON — Slide-Over Checklist & Defect Drawer
    ───────────────────────────────────────────────────── */
    public function summary_json($id = NULL) {
        $rid = (int)$id;
        if ($rid <= 0) {
            return $this->json(array('ok' => FALSE, 'error' => 'Invalid inspection record ID.'), 404);
        }
        $record = $this->Inspection_model->get_record($rid);
        if (!$record) {
            return $this->json(array('ok' => FALSE, 'error' => 'Inspection record not found.'), 404);
        }

        $template = $this->Inspection_model->get_template($record->template_id);
        $rawItems = $this->Inspection_model->get_record_items($rid);
        $backlogs = $this->Inspection_model->get_record_backlogs($rid);
        $equip    = !empty($record->equipment_id) ? $this->Equipment_model->get_by_id((int)$record->equipment_id) : NULL;

        $failedItems = array();
        $passedCount = 0;
        $checkedCount = 0;
        $p1Count = 0;
        foreach ($rawItems as $ri) {
            if (isset($ri->row_type) && $ri->row_type === 'section') continue;
            if (!empty($ri->value_json) && $ri->value_json !== '[]' && $ri->value_json !== '{}') {
                $checkedCount++;
            }
            $verdict = $this->Inspection_model->evaluate_item_verdict($ri);
            if (!empty($verdict['failed'])) {
                if ($verdict['priority'] === 'P1') $p1Count++;
                $failedItems[] = array(
                    'item_no'  => $ri->item_no !== NULL ? (string)$ri->item_no : '—',
                    'priority' => (string)$verdict['priority'],
                    'label'    => (string)$verdict['label'],
                    'text_en'  => (string)$ri->text_en,
                    'text_fr'  => (string)$ri->text_fr,
                    'comment'  => (string)$ri->comment,
                );
            } else {
                $passedCount++;
            }
        }

        // Sort failed items P1 -> P2 -> P3 so critical safety items always appear first
        usort($failedItems, function ($a, $b) {
            $rk = array('P1' => 1, 'P2' => 2, 'P3' => 3);
            return ($rk[$a['priority']] ?? 4) <=> ($rk[$b['priority']] ?? 4);
        });

        $signatures = array();
        $roleMeta = array(
            'mechanic'        => 'Mechanic / Inspector',
            'operator'        => 'Machine Operator',
            'supervisor'      => 'Workshop Supervisor',
            'prod_supervisor' => 'Production Supervisor',
        );
        foreach ($roleMeta as $rk => $rLabel) {
            $nm  = trim((string)$record->{$rk . '_name'});
            $dt  = trim((string)$record->{$rk . '_date'});
            $sig = !empty($record->{$rk . '_signature'});
            $signatures[] = array(
                'role'   => $rLabel,
                'name'   => $nm !== '' ? $nm : '—',
                'date'   => $dt !== '' ? $dt : '—',
                'signed' => ($sig || $nm !== ''),
            );
        }

        $backlogRows = array();
        foreach ($backlogs as $bl) {
            $backlogRows[] = array(
                'id'             => (int)$bl->id,
                'priority'       => (string)$bl->priority,
                'status'         => (string)$bl->status,
                'machine_status' => (string)$bl->machine_status,
                'description'    => (string)$bl->description,
            );
        }

        $verdictState = ($record->status === 'draft')
            ? 'draft'
            : ($p1Count > 0 ? 'p1_nogo' : (count($failedItems) > 0 ? 'failed' : 'passed'));

        return $this->json(array(
            'ok' => TRUE,
            'record' => array(
                'id'               => (int)$record->id,
                'inspection_date'  => (string)$record->inspection_date,
                'status'           => (string)$record->status,
                'verdict_state'    => $verdictState,
                'language'         => strtoupper((string)$record->language),
                'machine_label'    => $equip ? (string)$equip->registration_no : ((string)$record->fleet_number ?: '—'),
                'equipment_id'     => $equip ? (int)$equip->id : 0,
                'equipment_type'   => (string)$record->equipment_type ?: '—',
                'model'            => (string)$record->model ?: '—',
                'serial_number'    => (string)$record->serial_number ?: '—',
                'smr'              => (string)$record->smr ?: '—',
                'meter_label'      => $template ? (string)$template->meter_label_en : 'SMR',
                'template_code'    => $template ? strtoupper((string)$template->code) : 'GENERAL',
                'template_name_en' => $template ? (string)$template->name_en : '',
                'template_name_fr' => $template ? (string)$template->name_fr : '',
                'general_comments' => (string)$record->general_comments,
                'checked_count'    => $checkedCount,
                'passed_count'     => $passedCount,
                'fail_count'       => count($failedItems),
                'p1_count'         => $p1Count,
                'can_edit'         => (bool)$this->can_write(),
            ),
            'failed_items' => $failedItems,
            'signatures'   => $signatures,
            'backlogs'     => $backlogRows,
        ));
    }

    /* ─────────────────────────────────────────────────────
       TEMPLATE MANAGEMENT (supervisor)

       The seeded checklist templates could only be changed in the database.
       These screens make the template and its bilingual rows editable, while
       protecting submitted records: a row or template that a record already
       uses can never be deleted.
    ───────────────────────────────────────────────────── */

    public function templates() {
        if (!$this->is_supervisor()) {
            $this->session->set_flashdata('error', 'Only a supervisor can manage inspection templates.');
            redirect('inspections');
        }

        $data['templates']     = $this->Inspection_model->get_all_templates();
        $data['record_counts'] = $this->Inspection_model->get_record_counts();
        $data['page_title']    = 'Inspection Templates';
        $this->render('inspections/templates', $data);
    }

    /** Create (no id) or edit (id) a template and its checklist rows. */
    public function template_edit($id = NULL) {
        if (!$this->is_supervisor()) {
            $this->session->set_flashdata('error', 'Only a supervisor can manage inspection templates.');
            redirect('inspections');
        }

        $template = $id ? $this->Inspection_model->get_template($id) : NULL;
        if ($id && !$template) show_404();

        if ($this->input->post()) {
            $post   = $this->input->post();
            $parsed = $this->_parse_template_post($post, $template);

            if (empty($parsed['errors'])) {
                // The template row and its checklist rows are one unit: a
                // failure in the rows must not leave a half-saved template
                // behind (the row writer runs its own nested transaction).
                $this->db->trans_begin();
                $new_id = $this->Inspection_model->save_template(
                    $parsed['data'], $template ? (int)$template->id : NULL
                );
                $result = $new_id ? $this->Inspection_model->save_template_items($new_id, $parsed['items'])
                                  : array('failed' => TRUE, 'saved' => 0, 'kept' => 0);

                if (!empty($result['failed']) || $this->db->trans_status() === FALSE) {
                    $this->db->trans_rollback();
                    // Fall through to the re-render below so the posted rows
                    // are not lost.
                    $parsed['errors'][] = 'The template could not be saved. No changes were written.';
                } else {
                    $this->db->trans_commit();

                    $msg = 'Template saved — ' . $result['saved'] . ' checklist row(s).';
                    if ($result['kept'] > 0) {
                        $msg .= ' ' . $result['kept'] . ' row(s) could not be removed because a submitted '
                              . 'inspection record uses them; deactivate the template instead.';
                    }
                    $this->session->set_flashdata('success', $msg);
                    redirect('inspections/template_edit/' . (int)$new_id);
                }
            }

            // Re-render what was posted so a validation error never throws away
            // a supervisor's edits.
            $data['template'] = $this->_template_from_post($post, $template);
            $data['items']    = $this->_items_from_post($post);
            $data['errors']   = $parsed['errors'];
        } else {
            $data['template'] = $template;
            $data['items']    = $template ? $this->Inspection_model->get_items($template->id) : array();
            $data['errors']   = array();
        }

        $data['record_count'] = $template ? $this->Inspection_model->template_record_count($template->id) : 0;
        $data['page_title']   = $template
            ? 'Edit Template — ' . $template->name_en
            : 'New Inspection Template';
        $this->render('inspections/template_form', $data);
    }

    /** Delete a template that has never been used. */
    public function template_delete($id = NULL) {
        if (!$this->require_post()) return;
        if (!$this->is_supervisor()) {
            $this->session->set_flashdata('error', 'Only a supervisor can manage inspection templates.');
            redirect('inspections');
        }

        $template = $id ? $this->Inspection_model->get_template($id) : NULL;
        if (!$template) {
            $this->session->set_flashdata('error', 'Template not found.');
            redirect('inspections/templates');
        }

        if ($this->Inspection_model->template_record_count($template->id) > 0) {
            $this->session->set_flashdata('error',
                'The template has submitted records and cannot be deleted. Set it inactive instead.');
            redirect('inspections/templates');
        }

        // No records exist, so no inspection_record_items can reference its rows.
        $this->db->where('template_id', (int)$template->id)->delete('inspection_items');
        $this->Inspection_model->delete_template($template->id);

        $this->session->set_flashdata('success', 'Template deleted.');
        redirect('inspections/templates');
    }

    /**
     * Validate a posted template and normalise its checklist rows.
     *
     * @return array{data:array,items:array,errors:string[]}
     */
    private function _parse_template_post($post, $template = NULL) {
        $id = $template ? (int)$template->id : 0;
        $errors = array();

        $code = strtolower((string)(trim((string)(isset($post['code']) ? $post['code'] : ''))));
        if ($code === '' || !preg_match('/^[a-z0-9_]{2,50}$/D', $code)) {
            $errors[] = 'The code must be 2–50 characters: lower-case letters, digits and underscores only.';
        } elseif ($this->Inspection_model->template_code_taken($code, $id ?: NULL)) {
            $errors[] = 'Another template already uses the code "' . $code . '".';
        }

        $name_en = trim((string)(isset($post['name_en']) ? $post['name_en'] : ''));
        $name_fr = trim((string)(isset($post['name_fr']) ? $post['name_fr'] : ''));
        if ($name_en === '' || $name_fr === '') {
            $errors[] = 'Both the English and the French template name are required.';
        }

        $layout = (isset($post['layout_type']) && $post['layout_type'] === 'matrix') ? 'matrix' : 'compliance';

        $columns_json = NULL;
        if ($layout === 'matrix') {
            $raw = trim((string)(isset($post['columns_json']) ? (string)$post['columns_json'] : ''));
            $cols = json_decode($raw, TRUE);
            if (!is_array($cols) || empty($cols)) {
                $errors[] = 'A matrix template needs a non-empty columns JSON array '
                          . '(each entry: {"key":"H","label_en":"Excavator","label_fr":"Excavatrice"}).';
            } else {
                $clean = array();
                foreach ($cols as $c) {
                    if (!is_array($c)) continue;
                    $key = isset($c['key']) ? trim((string)$c['key']) : '';
                    if ($key === '') continue;
                    $clean[] = array(
                        'key'      => substr($key, 0, 10),
                        'label_en' => isset($c['label_en']) ? trim((string)$c['label_en']) : $key,
                        'label_fr' => isset($c['label_fr']) ? trim((string)$c['label_fr']) : $key,
                    );
                }
                if (empty($clean)) {
                    $errors[] = 'Every column needs a non-empty "key".';
                } else {
                    $columns_json = json_encode($clean);
                }
            }
        }

        $rows  = isset($post['items']) && is_array($post['items']) ? $post['items'] : array();
        $items = array();
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $row_type = (isset($r['row_type']) && $r['row_type'] === 'section') ? 'section' : 'item';
            $text_en  = trim((string)(isset($r['text_en']) ? (string)$r['text_en'] : ''));
            $text_fr  = trim((string)(isset($r['text_fr']) ? (string)$r['text_fr'] : ''));
            if ($text_en === '' && $text_fr === '') continue;   // blank spare row

            $items[] = array(
                'id'          => isset($r['id']) ? (int)$r['id'] : 0,
                'row_type'    => $row_type,
                'item_no'     => isset($r['item_no']) ? $r['item_no'] : '',
                'category_en' => isset($r['category_en']) ? $r['category_en'] : '',
                'category_fr' => isset($r['category_fr']) ? $r['category_fr'] : '',
                'text_en'     => $text_en,
                'text_fr'     => $text_fr,
                'na_columns'  => isset($r['na_columns']) ? $r['na_columns'] : '',
                'priority'    => isset($r['priority']) ? trim((string)$r['priority']) : '',
            );
        }
        if (empty($items)) {
            $errors[] = 'The checklist needs at least one row.';
        }

        $data = array(
            'code'          => $code,
            'name_en'       => $name_en,
            'name_fr'       => $name_fr,
            'category_en'   => trim((string)(isset($post['category_en']) ? $post['category_en'] : '')) ?: 'General',
            'category_fr'   => trim((string)(isset($post['category_fr']) ? $post['category_fr'] : '')) ?: 'Général',
            'layout_type'   => $layout,
            'columns_json'  => $columns_json,
            'meter_label_en'=> substr(trim((string)(isset($post['meter_label_en']) ? $post['meter_label_en'] : 'SMR')) ?: 'SMR', 0, 20),
            'meter_label_fr'=> substr(trim((string)(isset($post['meter_label_fr']) ? $post['meter_label_fr'] : 'SMR')) ?: 'SMR', 0, 20),
            'active'        => !empty($post['active']) ? 1 : 0,
        );

        return array('data' => $data, 'items' => $items, 'errors' => $errors);
    }

    /** Rebuild a template-shaped object from a rejected POST. */
    private function _template_from_post($post, $template = NULL) {
        return (object) array(
            'id'             => $template ? (int)$template->id : 0,
            'code'           => isset($post['code']) ? (string)$post['code'] : '',
            'name_en'        => isset($post['name_en']) ? (string)$post['name_en'] : '',
            'name_fr'        => isset($post['name_fr']) ? (string)$post['name_fr'] : '',
            'category_en'    => isset($post['category_en']) ? (string)$post['category_en'] : 'General',
            'category_fr'    => isset($post['category_fr']) ? (string)$post['category_fr'] : 'Général',
            'layout_type'    => (isset($post['layout_type']) && $post['layout_type'] === 'matrix') ? 'matrix' : 'compliance',
            'columns_json'   => isset($post['columns_json']) ? (string)$post['columns_json'] : '',
            'meter_label_en' => isset($post['meter_label_en']) ? (string)$post['meter_label_en'] : 'SMR',
            'meter_label_fr' => isset($post['meter_label_fr']) ? (string)$post['meter_label_fr'] : 'SMR',
            'active'         => !empty($post['active']) ? 1 : 0,
            'columns'        => array(),
        );
    }

    /** Rebuild item objects from a rejected POST (so nothing is lost). */
    private function _items_from_post($post) {
        $rows  = isset($post['items']) && is_array($post['items']) ? $post['items'] : array();
        $items = array();
        $i = 0;
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $text_en = isset($r['text_en']) ? (string)$r['text_en'] : '';
            $text_fr = isset($r['text_fr']) ? (string)$r['text_fr'] : '';
            if (trim((string)($text_en)) === '' && trim((string)($text_fr)) === '') continue;
            $i++;
            $items[] = (object) array(
                'id'          => isset($r['id']) ? (int)$r['id'] : 0,
                'sort_order'  => $i,
                'row_type'    => (isset($r['row_type']) && $r['row_type'] === 'section') ? 'section' : 'item',
                'item_no'     => isset($r['item_no']) ? $r['item_no'] : NULL,
                'category_en' => isset($r['category_en']) ? (string)$r['category_en'] : '',
                'category_fr' => isset($r['category_fr']) ? (string)$r['category_fr'] : '',
                'text_en'     => $text_en,
                'text_fr'     => $text_fr,
                'na_columns'  => isset($r['na_columns']) ? (string)$r['na_columns'] : '',
                'priority'    => isset($r['priority']) && in_array($r['priority'], array('P1', 'P2', 'P3'), TRUE) ? $r['priority'] : NULL,
            );
        }
        return $items;
    }

    /* ─────────────────────────────────────────────────────
       FILL — blank data-entry form for a template
       /inspections/fill/<code>/<en|fr>
    ───────────────────────────────────────────────────── */
    public function fill($code = NULL, $lang = 'en') {
        if (!$this->can_write()) {
            $this->session->set_flashdata('error', 'Your role has view-only access — creating inspections is not permitted.');
            redirect('inspections');
            return;
        }
        $template = $this->Inspection_model->get_template($code);
        if (!$template) show_404();

        $lang = ($lang === 'fr') ? 'fr' : 'en';

        $data['template']     = $template;
        $data['items']        = $this->Inspection_model->get_items($template->id);
        $data['lang']         = $lang;
        $data['equipment_list']= $this->Equipment_model->get_all_active();
        $data['readonly']     = FALSE;
        $data['record']       = NULL;
        $data['record_items'] = array();
        $data['inspector_name'] = $this->_logged_in_user_full_name();
        $data['page_title']   = ($lang === 'fr' ? $template->name_fr : $template->name_en);
        $this->render('inspections/fill', $data);
    }

    /* ─────────────────────────────────────────────────────
       EDIT — pull up an existing record in editable form
    ───────────────────────────────────────────────────── */
    public function edit($id = NULL) {
        if (!$this->can_write()) {
            $this->session->set_flashdata('error', 'Your role has view-only access — editing inspections is not permitted.');
            redirect('inspections');
            return;
        }
        $record = $this->Inspection_model->get_record($id);
        if (!$record) show_404();

        $template = $this->Inspection_model->get_template($record->template_id);
        $items    = $this->Inspection_model->get_items($template->id);
        $record_items_raw = $this->Inspection_model->get_record_items($id);

        $record_items = array();
        foreach ($record_items_raw as $ri) {
            $record_items[$ri->item_id] = array(
                'value'   => $ri->value_json ? json_decode($ri->value_json, TRUE) : array(),
                'comment' => $ri->comment,
            );
        }

        $data['template']      = $template;
        $data['items']         = $items;
        $data['lang']          = $record->language;
        $data['equipment_list']= $this->Equipment_model->get_all_active();
        $data['readonly']      = FALSE;
        $data['editing']       = TRUE;
        $data['record']        = $record;
        $data['record_items']  = $record_items;
        $data['page_title']    = ($record->language === 'fr' ? $template->name_fr : $template->name_en)
            . ' — ' . ($record->language === 'fr' ? 'Modifier' : 'Edit');
        $this->render('inspections/fill', $data);
    }

    /* ─────────────────────────────────────────────────────
       SAVE — store a new inspection, or update an existing one
       (a hidden record_id field on the form decides which)
    ───────────────────────────────────────────────────── */
    public function save() {
        if (!$this->require_post()) return;
        if (!$this->can_write()) {
            $this->session->set_flashdata('error', 'Your role has view-only access — saving inspections is not permitted.');
            redirect('inspections');
            return;
        }

        $template_id = (int) $this->input->post('template_id', TRUE);
        $template    = $this->Inspection_model->get_template($template_id);
        if (!$template) show_404();

        $record_id = (int) $this->input->post('record_id', TRUE);
        $is_edit = $record_id > 0;
        $existing = $is_edit ? $this->Inspection_model->get_record($record_id) : NULL;
        if ($is_edit && !$existing) show_404();

        // New inspections belong to the authenticated inspector. On edits,
        // preserve the original inspector rather than replacing them with the editor.
        $mechanic_name = $is_edit
            ? (isset($existing->mechanic_name) ? (string) $existing->mechanic_name : '')
            : $this->_logged_in_user_full_name();

        $record = array(
            'template_id'      => $template_id,
            'language'         => $this->input->post('language', TRUE) === 'fr' ? 'fr' : 'en',
            'inspection_date'  => $this->input->post('inspection_date', TRUE) ?: date('Y-m-d'),
            'fleet_number'     => $this->input->post('fleet_number', TRUE),
            'equipment_type'   => $this->input->post('equipment_type', TRUE),
            'smr'              => $this->input->post('smr', TRUE),
            'serial_number'    => $this->input->post('serial_number', TRUE),
            'model'            => $this->input->post('model', TRUE),
            'equipment_id'     => $this->input->post('equipment_id', TRUE) ?: NULL,
            'general_comments' => $this->input->post('general_comments', TRUE),

            'mechanic_name'    => $mechanic_name,
            'mechanic_date'    => $this->input->post('mechanic_date', TRUE) ?: NULL,
            'supervisor_name'  => $this->input->post('supervisor_name', TRUE),
            'supervisor_date'  => $this->input->post('supervisor_date', TRUE) ?: NULL,
            'operator_name'    => $this->input->post('operator_name', TRUE),
            'operator_date'    => $this->input->post('operator_date', TRUE) ?: NULL,
            'prod_supervisor_name' => $this->input->post('prod_supervisor_name', TRUE),
            'prod_supervisor_date' => $this->input->post('prod_supervisor_date', TRUE) ?: NULL,

            'status' => 'submitted',
        );

        if (!$is_edit) {
            $record['created_by'] = $this->user_id;
        }

        // Signatures — 4 possible draw-to-sign canvases. If the mechanic/supervisor/etc.
        // didn't re-sign, keep whatever signature was already on file (don't blank it out).
        $roles = array('mechanic' => 'mech', 'supervisor' => 'sup', 'operator' => 'op', 'prod_supervisor' => 'psup');
        foreach ($roles as $role => $prefix) {
            $new_sig = $this->_save_signature($role . '_signature_data', $prefix);
            if ($new_sig) {
                $record[$role . '_signature'] = $new_sig;
            } elseif ($is_edit) {
                $record[$role . '_signature'] = $existing->{$role . '_signature'};
            } else {
                $record[$role . '_signature'] = NULL;
            }
        }

        // Per-item results
        $items = $this->Inspection_model->get_items($template_id);
        $item_rows = array();
        foreach ($items as $it) {
            if ($it->row_type !== 'item') continue;

            if ($template->layout_type === 'matrix') {
                $value = array();
                $cols = is_array($template->columns) ? $template->columns : array();
                foreach ($cols as $c) {
                    $key = $c['key'];
                    $v = $this->input->post("item_{$it->id}_{$key}", TRUE);
                    if ($v !== NULL && $v !== '') $value[$key] = $v; // 'ok' | 'fail' | 'na'
                }
                $comment = $this->input->post("item_{$it->id}_comment", TRUE);
            } else {
                $result  = $this->input->post("item_{$it->id}_result", TRUE); // Yes|No|NA
                $value   = $result ? array('result' => $result) : array();
                $comment = $this->input->post("item_{$it->id}_comment", TRUE);
            }

            $item_rows[$it->id] = array(
                'value_json' => !empty($value) ? json_encode($value) : NULL,
                'comment'    => $comment,
            );
        }

        if ($is_edit) {
            $ok = $this->Inspection_model->update_record($record_id, $record, $item_rows);
            if (!$ok) {
                $this->session->set_flashdata('error', $record['language'] === 'fr'
                    ? 'Impossible d’enregistrer vos modifications. Veuillez réessayer.'
                    : 'Could not save your changes. Please try again.');
                redirect('inspections/edit/' . $record_id);
            }
            // AC-01, AC-04: Store meter reading
            if (!empty($record['equipment_id']) && !empty($record['smr'])) {
                $this->Equipment_model->record_meter_reading($record['equipment_id'], $record['inspection_date'], $record['smr'], 'Manual', null, $this->user_id);
            }
            // Trigger backlog parsing
            $findings = $this->Inspection_model->process_backlog_findings($record_id, $this->user_id);

            $this->session->set_flashdata('success', ($record['language'] === 'fr'
                ? 'Inspection mise à jour avec succès.'
                : 'Inspection updated successfully.').$this->_findings_note($findings));
            redirect('inspections/view/' . $record_id);
        }

        $new_id = $this->Inspection_model->create_record($record, $item_rows);
        if (!$new_id) {
            $this->session->set_flashdata('error', $record['language'] === 'fr'
                ? 'Impossible d’enregistrer l’inspection. Veuillez réessayer.'
                : 'Could not save the inspection. Please try again.');
            redirect('inspections/fill/' . $template->code . '/' . $record['language']);
        }
        
        // AC-01, AC-04: Store meter reading
        if (!empty($record['equipment_id']) && !empty($record['smr'])) {
            $this->Equipment_model->record_meter_reading($record['equipment_id'], $record['inspection_date'], $record['smr'], 'Manual', null, $this->user_id);
        }
        // Trigger backlog parsing
        $findings = $this->Inspection_model->process_backlog_findings($new_id, $this->user_id);

        $this->session->set_flashdata('success', ($record['language'] === 'fr'
            ? 'Inspection enregistrée avec succès.'
            : 'Inspection saved successfully.').$this->_findings_note($findings));
        redirect('inspections/view/' . $new_id);
    }

    /* ─────────────────────────────────────────────────────
       RECORDS — list submitted inspections (all or per template)
    ───────────────────────────────────────────────────── */
    public function records($code = NULL) {
        $forced = ($code !== NULL && trim((string)$code) !== '') ? trim((string)$code) : NULL;
        $data   = $this->_build_register_dataset($forced, TRUE);
        if ($forced !== NULL && $data === NULL) {
            show_404();
            return;
        }
        $lang = $data['lang'];
        if (!empty($data['focused_template'])) {
            $tpl = $data['focused_template'];
            $data['page_title'] = ($lang === 'fr' ? $tpl->name_fr : $tpl->name_en) . ' — ' . ($lang === 'fr' ? 'Enregistrements' : 'Records');
        } else {
            $data['page_title'] = ($lang === 'fr' ? 'Registre des inspections' : 'Inspection Register');
        }
        $this->render('inspections/records', $data);
    }

    /* ─────────────────────────────────────────────────────
       VIEW — read-only view of a submitted record
    ───────────────────────────────────────────────────── */
    public function view($id = NULL) {
        $record = $this->Inspection_model->get_record($id);
        if (!$record) show_404();

        $template = $this->Inspection_model->get_template($record->template_id);
        $items    = $this->Inspection_model->get_items($template->id);
        $record_items_raw = $this->Inspection_model->get_record_items($id);

        $record_items = array();
        foreach ($record_items_raw as $ri) {
            $record_items[$ri->item_id] = array(
                'value'   => $ri->value_json ? json_decode($ri->value_json, TRUE) : array(),
                'comment' => $ri->comment,
            );
        }

        $data['template']      = $template;
        $data['items']         = $items;
        $data['lang']          = $record->language;
        $data['equipment_list']= array();
        $data['readonly']      = TRUE;
        $data['record']        = $record;
        $data['record_items']  = $record_items;
        $data['page_title']    = ($record->language === 'fr' ? $template->name_fr : $template->name_en);
        $this->render('inspections/fill', $data);
    }

    /* ─────────────────────────────────────────────────────
       PDF — generate the pixel-perfect print PDF
    ───────────────────────────────────────────────────── */
    public function pdf($id = NULL) {
        $record = $this->Inspection_model->get_record($id);
        if (!$record) show_404();

        $template = $this->Inspection_model->get_template($record->template_id);
        $items    = $this->Inspection_model->get_items($template->id);
        $record_items_raw = $this->Inspection_model->get_record_items($id);

        $record_items = array();
        foreach ($record_items_raw as $ri) {
            $record_items[$ri->item_id] = array(
                'value'   => $ri->value_json ? json_decode($ri->value_json, TRUE) : array(),
                'comment' => $ri->comment,
            );
        }

        $items_payload = array();
        foreach ($items as $it) {
            $items_payload[] = array(
                'id'          => $it->id,
                'row_type'    => $it->row_type,
                'item_no'     => $it->item_no,
                'category_en' => $it->category_en,
                'category_fr' => $it->category_fr,
                'text_en'     => $it->text_en,
                'text_fr'     => $it->text_fr,
                'na_columns'  => $it->na_columns ? explode(',', $it->na_columns) : array(),
                'result'      => isset($record_items[$it->id]) ? $record_items[$it->id]['value'] : array(),
                'comment'     => isset($record_items[$it->id]) ? $record_items[$it->id]['comment'] : '',
            );
        }

        $payload = array(
            'template' => array(
                'code'           => $template->code,
                'name_en'        => $template->name_en,
                'name_fr'        => $template->name_fr,
                'category_en'    => isset($template->category_en) ? $template->category_en : 'Equipment Inspection',
                'category_fr'    => isset($template->category_fr) ? $template->category_fr : 'Inspection d’équipement',
                'layout_type'    => $template->layout_type,
                'columns'        => is_array($template->columns) ? $template->columns : array(),
                'meter_label_en' => $template->meter_label_en,
                'meter_label_fr' => $template->meter_label_fr,
            ),
            'record' => array(
                'language'         => $record->language,
                'inspection_date'  => $record->inspection_date,
                'fleet_number'     => $record->fleet_number,
                'equipment_type'   => $record->equipment_type,
                'smr'              => $record->smr,
                'serial_number'    => $record->serial_number,
                'model'            => $record->model,
                'general_comments' => $record->general_comments,
                'mechanic_name'        => $record->mechanic_name,
                'mechanic_signature'   => $record->mechanic_signature ? FCPATH . $record->mechanic_signature : '',
                'mechanic_date'        => $record->mechanic_date,
                'supervisor_name'      => $record->supervisor_name,
                'supervisor_signature' => $record->supervisor_signature ? FCPATH . $record->supervisor_signature : '',
                'supervisor_date'      => $record->supervisor_date,
                'operator_name'        => $record->operator_name,
                'operator_signature'   => $record->operator_signature ? FCPATH . $record->operator_signature : '',
                'operator_date'        => $record->operator_date,
                'prod_supervisor_name'      => $record->prod_supervisor_name,
                'prod_supervisor_signature' => $record->prod_supervisor_signature ? FCPATH . $record->prod_supervisor_signature : '',
                'prod_supervisor_date'      => $record->prod_supervisor_date,
            ),
            'items' => $items_payload,
        );

        $pdf_lang = $record->language === 'fr' ? 'fr' : 'en';
        try {
            require_once APPPATH . 'libraries/Inspection_pdf.php';
            $builder = new Inspection_pdf($payload);
            $pdf_bytes = $builder->render();
        } catch (Throwable $e) {
            log_message('error', 'Inspection PDF generation failed: ' . $e->getMessage());
            $message = $pdf_lang === 'fr'
                ? 'Le générateur PDF a rencontré une erreur inattendue.'
                : 'The PDF generator hit an unexpected error.';
            $this->_pdf_fail($message
                . (ENVIRONMENT !== 'production' ? '<br><br><strong>'
                    . ($pdf_lang === 'fr' ? 'Détails :' : 'Details:')
                    . '</strong><br><pre>' . htmlspecialchars((string)($e->getMessage())) . '</pre>' : ''), $pdf_lang);
            return;
        }

        if (empty($pdf_bytes)) {
            $message = $pdf_lang === 'fr'
                ? 'Le générateur PDF a produit un fichier vide. Veuillez réessayer ou contacter le support.'
                : 'The PDF generator produced an empty file. Please try again or contact support.';
            $this->_pdf_fail($message, $pdf_lang);
            return;
        }

        $filename = strtoupper((string)($template->code)) . '_' . $id . '.pdf';
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('Content-Length: ' . strlen((string)($pdf_bytes)));
        echo $pdf_bytes;
        exit;
    }

    /* ─────────────────────────────────────────────────────
       Render a clear, non-fatal error page instead of a 500
    ───────────────────────────────────────────────────── */
    private function _pdf_fail($message, $lang = 'en') {
        log_message('error', 'Inspection PDF: ' . strip_tags($message));
        if (!headers_sent()) header('HTTP/1.1 500 Internal Server Error');
        $title = $lang === 'fr' ? 'Impossible de générer le PDF' : 'Could not generate the PDF';
        echo '<div style="font-family:sans-serif;max-width:700px;margin:60px auto;padding:24px;border:1px solid #e2b8b8;background:#fff5f5;border-radius:10px;color:#7a1f1f;">'
           . '<h3 style="margin-top:0;">' . htmlspecialchars((string)($title)) . '</h3>'
           . '<p>' . $message . '</p>'
           . '</div>';
    }

    /* ─────────────────────────────────────────────────────
       Browser-visitable environment check — visit
       /inspections/pdf_diagnostics to see what's missing.
       PDF generation is pure PHP (no exec/python needed); this
       checks the handful of PHP extensions it optionally uses.
    ───────────────────────────────────────────────────── */
    public function pdf_diagnostics() {
        $rows = array();
        $rows['PHP version >= 7.0 (for Throwable)'] = version_compare(PHP_VERSION, '7.0.0', '>=');
        $rows['mbstring extension (mb_strlen etc.)'] = function_exists('mb_strlen');
        $rows['iconv extension (accented text)'] = function_exists('iconv');
        $rows['zlib extension (smaller PDFs; optional)'] = function_exists('gzcompress');
        $rows['GD extension (signature images; optional)'] = function_exists('imagecreatefromstring');
        $rows['Inspection_pdf.php library found'] = file_exists(APPPATH . 'libraries/Inspection_pdf.php');
        $rows['Pdf_writer.php library found'] = file_exists(APPPATH . 'libraries/Pdf_writer.php');

        header('Content-Type: text/html; charset=utf-8');
        echo '<div style="font-family:sans-serif;max-width:640px;margin:50px auto;">';
        echo '<h2>Inspection PDF — environment check</h2>';
        echo '<p style="color:#666;font-size:13px;">PDF generation runs natively in PHP — no exec(), no python3. '
           . 'The two "optional" rows only affect signature images and file size; everything else must be &#10004; for PDFs to work.</p>';
        echo '<table style="width:100%;border-collapse:collapse;">';
        foreach ($rows as $label => $ok) {
            $color = $ok ? '#1a7a34' : '#c0392b';
            $icon  = $ok ? '&#10004;' : '&#10008;';
            echo '<tr><td style="padding:8px;border-bottom:1px solid #eee;">' . htmlspecialchars((string)($label)) . '</td>'
               . '<td style="padding:8px;border-bottom:1px solid #eee;color:' . $color . ';font-weight:bold;">' . $icon . '</td></tr>';
        }
        echo '</table></div>';
    }

    /** Resolve the authenticated user's display name for inspector attribution. */
    private function _logged_in_user_full_name() {
        $user = $this->user_data;
        if (is_object($user)) {
            $first = isset($user->first_name) ? trim((string) $user->first_name) : '';
            $last  = isset($user->last_name) ? trim((string) $user->last_name) : '';
        } elseif (is_array($user)) {
            $first = isset($user['first_name']) ? trim((string) $user['first_name']) : '';
            $last  = isset($user['last_name']) ? trim((string) $user['last_name']) : '';
        } else {
            $first = $last = '';
        }

        $name = trim((string)($first . ' ' . $last));
        if ($name !== '') return $name;

        // Older sessions may not carry user_data; fall back to the authenticated ID.
        if (!$this->db || (int) $this->user_id <= 0) return '';
        try {
            $user = $this->db->select('first_name, last_name')
                ->get_where('users', array('id' => (int) $this->user_id))
                ->row();
            if ($user) return trim((string) $user->first_name . ' ' . (string) $user->last_name);
        } catch (Throwable $e) {
            log_message('error', 'Could not resolve inspection inspector name: ' . $e->getMessage());
        }
        return '';
    }

    /* ─────────────────────────────────────────────────────
       Save a drawn signature (base64 PNG) to file, return path
    ───────────────────────────────────────────────────── */
    private function _save_signature($post_key, $prefix) {
        $data = $this->input->post($post_key, FALSE);
        if (empty($data) || strpos($data, 'data:image/png;base64,') !== 0) return NULL;

        $data = str_replace('data:image/png;base64,', '', $data);
        $data = str_replace(' ', '+', $data);
        $bin  = base64_decode($data);
        if ($bin === FALSE) return NULL;

        if (!is_dir(FCPATH . 'uploads/signatures/')) mkdir(FCPATH . 'uploads/signatures/', 0755, true);
        $fname = 'insp_' . $prefix . '_' . $this->user_id . '_' . time() . '.png';
        $path  = FCPATH . 'uploads/signatures/' . $fname;
        file_put_contents($path, $bin);
        return 'uploads/signatures/' . $fname;
    }
    /**
     * What this inspection did to the defect list (Wave 3, Q2): defects it
     * raised, defects it annotated as repeat sightings, and defects it has
     * just cleared.  Saying so on the confirmation is the difference between
     * "saved" and "saved, and here is what changed".
     */
    private function _findings_note($findings) {
        if (!is_array($findings)) return '';
        $parts = array();
        if (!empty($findings['created']))   $parts[] = $findings['created'].' defect(s) raised';
        if (!empty($findings['annotated'])) $parts[] = $findings['annotated'].' repeat sighting(s) added to open defects';
        if (!empty($findings['review']))    $parts[] = $findings['review'].' positive result(s) sent for supervisor review; defects remain open';
        return empty($parts) ? '' : ' Defect list: '.implode(', ', $parts).'.';
    }
}
