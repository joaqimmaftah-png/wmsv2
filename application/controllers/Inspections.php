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
       INDEX — list available inspection form templates
    ───────────────────────────────────────────────────── */
    public function index() {
        $lang = $this->input->get('lang', TRUE);
        if (!$lang) {
            $lang = $this->session->userdata('inspection_lang') ?: 'en';
        } else {
            $lang = ($lang === 'fr') ? 'fr' : 'en';
            $this->session->set_userdata('inspection_lang', $lang);
        }

        $data['templates']     = $this->Inspection_model->get_templates();
        $data['record_counts'] = $this->Inspection_model->get_record_counts();
        $data['lang']          = $lang;
        $data['can_manage']    = $this->is_supervisor();
        $data['page_title']    = ($lang === 'fr' ? 'Formulaires d’inspection' : 'Inspection Forms');
        $this->render('inspections/index', $data);
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

        $code = strtolower(trim(isset($post['code']) ? $post['code'] : ''));
        if ($code === '' || !preg_match('/^[a-z0-9_]{2,50}$/D', $code)) {
            $errors[] = 'The code must be 2–50 characters: lower-case letters, digits and underscores only.';
        } elseif ($this->Inspection_model->template_code_taken($code, $id ?: NULL)) {
            $errors[] = 'Another template already uses the code "' . $code . '".';
        }

        $name_en = trim(isset($post['name_en']) ? $post['name_en'] : '');
        $name_fr = trim(isset($post['name_fr']) ? $post['name_fr'] : '');
        if ($name_en === '' || $name_fr === '') {
            $errors[] = 'Both the English and the French template name are required.';
        }

        $layout = (isset($post['layout_type']) && $post['layout_type'] === 'matrix') ? 'matrix' : 'compliance';

        $columns_json = NULL;
        if ($layout === 'matrix') {
            $raw = trim(isset($post['columns_json']) ? (string)$post['columns_json'] : '');
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
            $text_en  = trim(isset($r['text_en']) ? (string)$r['text_en'] : '');
            $text_fr  = trim(isset($r['text_fr']) ? (string)$r['text_fr'] : '');
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
            );
        }
        if (empty($items)) {
            $errors[] = 'The checklist needs at least one row.';
        }

        $data = array(
            'code'          => $code,
            'name_en'       => $name_en,
            'name_fr'       => $name_fr,
            'category_en'   => trim(isset($post['category_en']) ? $post['category_en'] : '') ?: 'General',
            'category_fr'   => trim(isset($post['category_fr']) ? $post['category_fr'] : '') ?: 'Général',
            'layout_type'   => $layout,
            'columns_json'  => $columns_json,
            'meter_label_en'=> substr(trim(isset($post['meter_label_en']) ? $post['meter_label_en'] : 'SMR') ?: 'SMR', 0, 20),
            'meter_label_fr'=> substr(trim(isset($post['meter_label_fr']) ? $post['meter_label_fr'] : 'SMR') ?: 'SMR', 0, 20),
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
            if (trim($text_en) === '' && trim($text_fr) === '') continue;
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
            );
        }
        return $items;
    }

    /* ─────────────────────────────────────────────────────
       FILL — blank data-entry form for a template
       /inspections/fill/<code>/<en|fr>
    ───────────────────────────────────────────────────── */
    public function fill($code = NULL, $lang = 'en') {
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
        if ($this->input->method() !== 'post') redirect('inspections');

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
            $findings = $this->Inspection_model->process_backlog_findings($record_id);

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
        $findings = $this->Inspection_model->process_backlog_findings($new_id);

        $this->session->set_flashdata('success', ($record['language'] === 'fr'
            ? 'Inspection enregistrée avec succès.'
            : 'Inspection saved successfully.').$this->_findings_note($findings));
        redirect('inspections/view/' . $new_id);
    }

    /* ─────────────────────────────────────────────────────
       RECORDS — list submitted inspections for a template
    ───────────────────────────────────────────────────── */
    public function records($code = NULL) {
        $template = $this->Inspection_model->get_template($code);
        if (!$template) show_404();

        $lang = $this->input->get('lang', TRUE) ?: ($this->session->userdata('inspection_lang') ?: 'en');
        $lang = ($lang === 'fr') ? 'fr' : 'en';

        $data['template']   = $template;
        $data['records']    = $this->Inspection_model->get_records($template->id);
        $data['lang']       = $lang;
        $data['page_title'] = ($lang === 'fr' ? $template->name_fr : $template->name_en) . ' — ' . ($lang === 'fr' ? 'Enregistrements' : 'Records');
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
                    . '</strong><br><pre>' . htmlspecialchars($e->getMessage()) . '</pre>' : ''), $pdf_lang);
            return;
        }

        if (empty($pdf_bytes)) {
            $message = $pdf_lang === 'fr'
                ? 'Le générateur PDF a produit un fichier vide. Veuillez réessayer ou contacter le support.'
                : 'The PDF generator produced an empty file. Please try again or contact support.';
            $this->_pdf_fail($message, $pdf_lang);
            return;
        }

        $filename = strtoupper($template->code) . '_' . $id . '.pdf';
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdf_bytes));
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
           . '<h3 style="margin-top:0;">' . htmlspecialchars($title) . '</h3>'
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
            echo '<tr><td style="padding:8px;border-bottom:1px solid #eee;">' . htmlspecialchars($label) . '</td>'
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

        $name = trim($first . ' ' . $last);
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
        if (!empty($findings['closed']))    $parts[] = $findings['closed'].' defect(s) closed by this inspection';
        return empty($parts) ? '' : ' Defect list: '.implode(', ', $parts).'.';
    }
}
