<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Inspection_model extends CI_Model {

    const CATEGORY_MAP = array(
        'excavator'        => array('Heavy Equipment', 'Engins lourds'),
        'adt'              => array('Heavy Equipment', 'Engins lourds'),
        'grader'           => array('Heavy Equipment', 'Engins lourds'),
        'dozer'            => array('Heavy Equipment', 'Engins lourds'),
        'drill_rigg'       => array('Heavy Equipment', 'Engins lourds'),
        'bus'              => array('Transport & Light Vehicles', 'Transport & Véhicules légers'),
        'light_vehicle'    => array('Transport & Light Vehicles', 'Transport & Véhicules légers'),
        'lv'               => array('Transport & Light Vehicles', 'Transport & Véhicules légers'),
        'hiab'             => array('Service & Utility Trucks', 'Camions de service & utilitaires'),
        'bowser'           => array('Service & Utility Trucks', 'Camions de service & utilitaires'),
        'service_van'      => array('Service & Utility Trucks', 'Camions de service & utilitaires'),
        'forklift'         => array('Heavy Equipment', 'Engins lourds'),
        'heavy_equipment'  => array('General Checklists', 'Listes de contrôle générales'),
        'lv_bus'           => array('General Checklists', 'Listes de contrôle générales'),
        'heavy_vehicle'    => array('General Checklists', 'Listes de contrôle générales'),
    );

    const CODE_ALIASES = array(
        'lv'                => 'light_vehicle',
        'fuel_water_bowser' => 'bowser',
        'bulldozer'         => 'dozer',
        'drill_rig'         => 'drill_rigg',
        'fork_lift'         => 'forklift',
        'servicevan'        => 'service_van',
    );

    public function __construct() {
        parent::__construct();
    }

    /* ---------- Templates ---------- */

    public function get_templates($active_only = TRUE) {
        $this->db->order_by('id', 'ASC');
        if ($active_only) $this->db->where('active', 1);
        $templates = $this->db->get('inspection_templates')->result();
        foreach ($templates as $t) {
            $this->_enrich_template($t);
        }
        return $templates;
    }

    public function get_template($id_or_code) {
        if (is_string($id_or_code)) {
            $normalized = strtolower((string)(trim((string)($id_or_code))));
            if (isset(self::CODE_ALIASES[$normalized])) {
                $id_or_code = self::CODE_ALIASES[$normalized];
            }
        }

        if (is_numeric($id_or_code)) {
            $this->db->where('id', (int)$id_or_code);
        } else {
            $this->db->where('code', $id_or_code);
        }
        $t = $this->db->get('inspection_templates')->row();
        if ($t) {
            $this->_enrich_template($t);
        }
        return $t;
    }

    private function _enrich_template($t) {
        if (!$t) return;
        if (!empty($t->columns_json)) {
            $t->columns = json_decode($t->columns_json, TRUE);
        } else {
            $t->columns = array();
        }

        if (empty($t->category_en)) {
            $code = strtolower((string)($t->code));
            if (isset(self::CATEGORY_MAP[$code])) {
                $t->category_en = self::CATEGORY_MAP[$code][0];
                $t->category_fr = self::CATEGORY_MAP[$code][1];
            } else {
                $t->category_en = 'General';
                $t->category_fr = 'Général';
            }
        }
    }

    public function get_templates_grouped($active_only = TRUE, $lang = 'en') {
        $templates = $this->get_templates($active_only);
        $groups = array();
        foreach ($templates as $t) {
            $cat = ($lang === 'fr') ? $t->category_fr : $t->category_en;
            if (!isset($groups[$cat])) {
                $groups[$cat] = array();
            }
            $groups[$cat][] = $t;
        }
        return $groups;
    }

    public function get_record_counts() {
        $rows = $this->db->select('template_id, COUNT(*) as cnt')
                         ->group_by('template_id')
                         ->get('inspection_records')->result();
        $counts = array();
        foreach ($rows as $r) {
            $counts[$r->template_id] = (int) $r->cnt;
        }
        return $counts;
    }

    /** @var bool|null cached field_exists() probe for K-16's priority column */
    protected $_has_item_priority = null;

    /**
     * Is `inspection_items.priority` present? Fresh installs get it from
     * database/schema.sql; a database created before K-16 does not, and the
     * severity then falls back to the category text exactly as before.
     */
    protected function _items_have_priority() {
        if ($this->_has_item_priority === null) {
            $this->_has_item_priority = (bool)$this->db->field_exists('priority', 'inspection_items');
        }
        return $this->_has_item_priority;
    }

    /** Public form of the probe: lets the template editor warn that the column is missing. */
    public function supports_item_priority() {
        return $this->_items_have_priority();
    }

    public function get_items($template_id) {
        return $this->db->where('template_id', $template_id)
                         ->order_by('sort_order', 'ASC')
                         ->get('inspection_items')->result();
    }

    /* ---------- Template management ----------
       The 14 seeded templates were fully usable but could only be changed in
       the database. These methods let a supervisor create/edit a template and
       its bilingual checklist rows from the UI.

       Deleting a row that a submitted record already references would break
       the FK from inspection_record_items.item_id, so such a row is kept and
       reported instead of failing the whole save. */

    /** All templates, active and inactive (the picker only shows active ones). */
    public function get_all_templates() {
        return $this->get_templates(FALSE);
    }

    public function template_record_count($template_id) {
        return (int)$this->db->where('template_id', (int)$template_id)
            ->count_all_results('inspection_records');
    }

    public function item_usage_count($item_id) {
        return (int)$this->db->where('item_id', (int)$item_id)
            ->count_all_results('inspection_record_items');
    }

    /** Is this code free? (case-insensitive; $exclude_id for an edit) */
    public function template_code_taken($code, $exclude_id = NULL) {
        $this->db->where('code', $code);
        if ($exclude_id) $this->db->where('id !=', (int)$exclude_id);
        return $this->db->count_all_results('inspection_templates') > 0;
    }

    public function save_template($data, $id = NULL) {
        if ($id) {
            $this->db->where('id', (int)$id)->update('inspection_templates', $data);
            return (int)$id;
        }
        $this->db->insert('inspection_templates', $data);
        return (int)$this->db->insert_id();
    }

    public function delete_template($id) {
        $this->db->where('id', (int)$id)->delete('inspection_templates');
        return $this->db->affected_rows() > 0;
    }

    /**
     * Replace a template's rows with what the editor posted.
     *
     * Each row: id (existing) | row_type | item_no | category_en/fr |
     *           text_en/fr | na_columns
     *
     * @param  int   $template_id
     * @param  array $rows
     * @return array{saved:int,kept:int}  kept = rows that could not be deleted
     *                                    because a submitted record uses them
     */
    public function save_template_items($template_id, $rows) {
        $template_id = (int)$template_id;
        $existing = array();
        foreach ($this->get_items($template_id) as $it) {
            $existing[(int)$it->id] = $it;
        }

        $seen = array();
        $keep = array();
        $saved = 0;
        $order = 0;

        $this->db->trans_begin();

        foreach ((array)$rows as $row) {
            if (!is_array($row)) continue;

            $row_type = (isset($row['row_type']) && $row['row_type'] === 'section') ? 'section' : 'item';
            $text_en  = isset($row['text_en']) ? trim((string)$row['text_en']) : '';
            $text_fr  = isset($row['text_fr']) ? trim((string)$row['text_fr']) : '';
            $cat_en   = isset($row['category_en']) ? trim((string)$row['category_en']) : '';
            $cat_fr   = isset($row['category_fr']) ? trim((string)$row['category_fr']) : '';
            $na       = isset($row['na_columns']) ? trim((string)$row['na_columns']) : '';
            $item_no  = (isset($row['item_no']) && $row['item_no'] !== '' && is_numeric($row['item_no']))
                ? (int)$row['item_no'] : NULL;

            // a completely blank row is ignored (the editor leaves one spare)
            if ($text_en === '' && $text_fr === '' && $cat_en === '' && $cat_fr === '') continue;

            $order++;
            $id = (isset($row['id']) && (int)$row['id'] > 0) ? (int)$row['id'] : 0;

            if ($row_type === 'section') {
                $item_no = NULL;
                $cat_en  = '';
                $cat_fr  = '';
                $na      = '';
            }

            $data = array(
                'template_id'   => $template_id,
                'sort_order'    => $order,
                'row_type'      => $row_type,
                'item_no'       => $item_no,
                'category_en'   => $cat_en !== '' ? $cat_en : NULL,
                'category_fr'   => $cat_fr !== '' ? $cat_fr : NULL,
                'text_en'       => $text_en !== '' ? $text_en : NULL,
                'text_fr'       => $text_fr !== '' ? $text_fr : NULL,
                'na_columns'    => $na !== '' ? $na : NULL,
            );

            // K-16: the editor now posts an explicit severity. Only a known
            // code is stored; an empty value means "derive it from the
            // category", which is how every row behaved before the column.
            if ($this->_items_have_priority()) {
                $priority = isset($row['priority']) ? strtoupper((string)(trim((string)$row['priority']))) : '';
                $data['priority'] = in_array($priority, array('P1', 'P2', 'P3'), true) ? $priority : NULL;
                if ($row_type === 'section') $data['priority'] = NULL;
            }

            if ($id > 0 && isset($existing[$id])) {
                $this->db->where('id', $id)->update('inspection_items', $data);
                $seen[$id] = TRUE;
            } else {
                $this->db->insert('inspection_items', $data);
                $new_id = (int)$this->db->insert_id();
                if ($new_id) $seen[$new_id] = TRUE;
            }
            $saved++;
        }

        // Rows removed from the editor: delete only those no record uses.
        foreach ($existing as $id => $it) {
            if (isset($seen[$id])) continue;
            if ($this->item_usage_count($id) > 0) {
                $keep[] = $it;
                continue;
            }
            $this->db->where('id', $id)->delete('inspection_items');
        }

        if ($this->db->trans_status() === FALSE) {
            $this->db->trans_rollback();
            return array('saved' => 0, 'kept' => 0, 'failed' => TRUE);
        }
        $this->db->trans_commit();

        return array('saved' => $saved, 'kept' => count($keep), 'failed' => FALSE);
    }

    /* ---------- Records ---------- */

    public function create_record($record, $items) {
        $this->db->trans_start();
        $this->db->insert('inspection_records', $record);
        $record_id = $this->db->insert_id();

        foreach ($items as $item_id => $row) {
            $this->db->insert('inspection_record_items', array(
                'record_id'  => $record_id,
                'item_id'    => $item_id,
                'value_json' => isset($row['value_json']) ? $row['value_json'] : null,
                'comment'    => isset($row['comment']) ? $row['comment'] : null,
            ));
        }

        $this->db->trans_complete();
        return $this->db->trans_status() ? $record_id : FALSE;
    }

    public function update_record($record_id, $record, $items) {
        $this->db->trans_start();

        $this->db->where('id', $record_id)->update('inspection_records', $record);

        // Simplest correct approach: replace all item rows for this record
        $this->db->where('record_id', $record_id)->delete('inspection_record_items');
        foreach ($items as $item_id => $row) {
            $this->db->insert('inspection_record_items', array(
                'record_id'  => $record_id,
                'item_id'    => $item_id,
                'value_json' => isset($row['value_json']) ? $row['value_json'] : null,
                'comment'    => isset($row['comment']) ? $row['comment'] : null,
            ));
        }

        $this->db->trans_complete();
        return $this->db->trans_status();
    }

    public function get_records($template_id = NULL, $limit = 200) {
        $this->db->select('inspection_records.*, inspection_templates.name_en AS template_name_en, inspection_templates.name_fr AS template_name_fr, inspection_templates.layout_type')
                  ->join('inspection_templates', 'inspection_templates.id = inspection_records.template_id')
                  ->order_by('inspection_records.id', 'DESC')
                  ->limit($limit);
        if ($template_id) $this->db->where('inspection_records.template_id', $template_id);
        return $this->db->get('inspection_records')->result();
    }

    public function get_record($id) {
        return $this->db->where('id', $id)->get('inspection_records')->row();
    }

    public function get_record_items($record_id) {
        $columns = 'inspection_record_items.*, inspection_items.item_no, inspection_items.row_type, inspection_items.category_en, inspection_items.category_fr, inspection_items.text_en, inspection_items.text_fr, inspection_items.na_columns';
        if ($this->_items_have_priority()) $columns .= ', inspection_items.priority';
        $this->db->select($columns . ', inspection_items.sort_order')
                  ->join('inspection_items', 'inspection_items.id = inspection_record_items.item_id')
                  ->where('inspection_record_items.record_id', $record_id)
                  ->order_by('inspection_items.sort_order', 'ASC');
        return $this->db->get('inspection_record_items')->result();
    }

    /** Public wrapper for checklist item verdict classification (`P1`/`P2`/`P3` + `failed`). */
    public function evaluate_item_verdict($item) {
        return $this->_inspection_item_verdict($item);
    }

    /** Fetch defects in `backlogs` raised directly by a submitted inspection record. */
    public function get_record_backlogs($record_id) {
        $record_id = (int)$record_id;
        if ($record_id <= 0 || !$this->db->table_exists('backlogs')) {
            return array();
        }
        return $this->db
            ->select('id, priority, status, machine_status, description, start_date, end_date')
            ->from('backlogs')
            ->like('source_ref', 'insp_' . $record_id . '_item_', 'after')
            ->order_by('id', 'ASC')
            ->get()->result();
    }

    /**
     * Hydrate the entire Fleet Inspection Register + Bilingual Form Library in
     * constant O(1) SQL queries (`<= 4` queries regardless of record count):
     *   1. `inspection_templates` (active + inactive)
     *   2. `inspection_items` grouped counts per template
     *   3. `inspection_records LEFT JOIN equipment` (verbatim Asset Register `registration_no`)
     *   4. `inspection_record_items JOIN inspection_items` batch verdict roll-up
     *
     * @param string|null $today Optional YYYY-MM-DD anchor for deterministic unit tests.
     * @return array{rows:object[],templates:object[],active_templates:object[],record_counts:array,total_items:int,today:string}
     */
    public function get_register_overview($today = NULL) {
        $todayStr = ($today && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$today))
            ? (string)$today
            : date('Y-m-d');

        // Query 1: All templates (active + inactive so historical records always resolve)
        $allTemplates = $this->db->order_by('id', 'ASC')->get('inspection_templates')->result();
        $tplById      = array();
        $activeTpls   = array();
        foreach ($allTemplates as $t) {
            $this->_enrich_template($t);
            $t->item_count    = 0;
            $t->section_count = 0;
            $tplById[(int)$t->id] = $t;
            if ((int)$t->active === 1) {
                $activeTpls[] = $t;
            }
        }

        // Query 2: Checklist item counts grouped by template_id (strict ONLY_FULL_GROUP_BY safe)
        $totalItems = 0;
        $itemCounts = $this->db
            ->select("template_id, SUM(CASE WHEN row_type = 'item' THEN 1 ELSE 0 END) AS item_cnt, SUM(CASE WHEN row_type = 'section' THEN 1 ELSE 0 END) AS section_cnt", FALSE)
            ->from('inspection_items')
            ->group_by('template_id')
            ->get()->result();
        foreach ($itemCounts as $ic) {
            $tid = (int)$ic->template_id;
            $cnt = (int)$ic->item_cnt;
            if (isset($tplById[$tid])) {
                $tplById[$tid]->item_count    = $cnt;
                $tplById[$tid]->section_count = (int)$ic->section_cnt;
                if ((int)$tplById[$tid]->active === 1) {
                    $totalItems += $cnt;
                }
            }
        }

        // Query 3: All inspection records joined cleanly to Asset Register `equipment`
        $records = $this->db
            ->select('ir.*, e.registration_no AS equip_reg_no, e.location AS equip_location, e.brand AS equip_brand, e.model AS equip_model')
            ->from('inspection_records ir')
            ->join('equipment e', 'e.id = ir.equipment_id', 'left')
            ->order_by('ir.inspection_date', 'DESC')
            ->order_by('ir.id', 'DESC')
            ->get()->result();

        // Query 4: Batch per-item verdict hydration across all records in ONE query
        $aggByRecord = array();
        if (!empty($records)) {
            $riRows = $this->db
                ->select('iri.record_id, iri.item_id, iri.value_json, iri.comment, ii.*')
                ->from('inspection_record_items iri')
                ->join('inspection_items ii', 'ii.id = iri.item_id')
                ->where('ii.row_type', 'item')
                ->get()->result();

            foreach ($riRows as $ri) {
                $rid = (int)$ri->record_id;
                if (!isset($aggByRecord[$rid])) {
                    $aggByRecord[$rid] = array(
                        'checked' => 0,
                        'failed'  => 0,
                        'p1'      => 0,
                        'p2'      => 0,
                        'p3'      => 0,
                        'notes'   => 0,
                    );
                }
                if (!empty($ri->value_json) && $ri->value_json !== '[]' && $ri->value_json !== '{}') {
                    $aggByRecord[$rid]['checked']++;
                }
                if (trim((string)$ri->comment) !== '') {
                    $aggByRecord[$rid]['notes']++;
                }
                $verdict = $this->_inspection_item_verdict($ri);
                if (!empty($verdict['failed'])) {
                    $aggByRecord[$rid]['failed']++;
                    if ($verdict['priority'] === 'P1') {
                        $aggByRecord[$rid]['p1']++;
                    } elseif ($verdict['priority'] === 'P2') {
                        $aggByRecord[$rid]['p2']++;
                    } else {
                        $aggByRecord[$rid]['p3']++;
                    }
                }
            }
        }

        $recordCounts = array();
        foreach ($records as $r) {
            $rid = (int)$r->id;
            $tid = (int)$r->template_id;
            $recordCounts[$tid] = isset($recordCounts[$tid]) ? ($recordCounts[$tid] + 1) : 1;

            $tpl = isset($tplById[$tid]) ? $tplById[$tid] : NULL;
            $r->template_code     = $tpl ? (string)$tpl->code : 'general';
            $r->template_name_en  = $tpl ? (string)$tpl->name_en : ('Template #' . $tid);
            $r->template_name_fr  = $tpl ? (string)$tpl->name_fr : ('Modèle #' . $tid);
            $r->category_en       = $tpl ? (string)$tpl->category_en : 'General';
            $r->category_fr       = $tpl ? (string)$tpl->category_fr : 'Général';
            $r->layout_type       = $tpl ? (string)$tpl->layout_type : 'compliance';
            $r->meter_label_en    = $tpl ? (string)$tpl->meter_label_en : 'SMR';
            $r->meter_label_fr    = $tpl ? (string)$tpl->meter_label_fr : 'SMR';
            $r->total_items       = $tpl ? (int)$tpl->item_count : 0;

            // Preserve exact verbatim Asset Register registration_no when linked
            $regNo = trim((string)(isset($r->equip_reg_no) ? $r->equip_reg_no : ''));
            $fleet = trim((string)(isset($r->fleet_number) ? $r->fleet_number : ''));
            $r->machine_label = $regNo !== '' ? $regNo : ($fleet !== '' ? $fleet : '—');
            $r->inspector_label = trim((string)$r->mechanic_name) !== ''
                ? trim((string)$r->mechanic_name)
                : (trim((string)$r->operator_name) !== '' ? trim((string)$r->operator_name) : '—');

            $agg = isset($aggByRecord[$rid]) ? $aggByRecord[$rid] : array(
                'checked' => 0, 'failed' => 0, 'p1' => 0, 'p2' => 0, 'p3' => 0, 'notes' => 0,
            );
            $r->checked_count = (int)$agg['checked'];
            $r->fail_count    = (int)$agg['failed'];
            $r->p1_count      = (int)$agg['p1'];
            $r->p2_count      = (int)$agg['p2'];
            $r->p3_count      = (int)$agg['p3'];
            $r->comment_count = (int)$agg['notes'];

            $hasSupSign = !empty($r->supervisor_signature) || trim((string)$r->supervisor_name) !== '';
            $sigCount = 0;
            foreach (array('mechanic', 'supervisor', 'operator', 'prod_supervisor') as $sRole) {
                $sigCol  = $sRole . '_signature';
                $nameCol = $sRole . '_name';
                if (!empty($r->{$sigCol}) || trim((string)$r->{$nameCol}) !== '') {
                    $sigCount++;
                }
            }
            $r->sig_count   = $sigCount;
            $r->is_signed   = $hasSupSign;
            $r->is_unsigned = ($r->status === 'submitted' && !$hasSupSign);

            if ($r->status === 'draft') {
                $r->verdict_state = 'draft';
                $r->severity_rank = 4;
            } elseif ($r->p1_count > 0) {
                $r->verdict_state = 'p1_nogo';
                $r->severity_rank = 1;
            } elseif ($r->fail_count > 0) {
                $r->verdict_state = 'failed';
                $r->severity_rank = 2;
            } else {
                $r->verdict_state = 'passed';
                $r->severity_rank = 3;
            }
        }

        return array(
            'rows'             => $records,
            'templates'        => $allTemplates,
            'active_templates' => $activeTpls,
            'record_counts'    => $recordCounts,
            'total_items'      => $totalItems,
            'today'            => $todayStr,
        );
    }

    /**
     * Turn a submitted inspection into defects — and, just as important, close
     * the ones the inspection has just cleared (Wave 3, Q2).
     *
     * Until now this method only ever created rows: a repeat failure added a
     * second row beside the first, a later pass closed nothing, and every
     * defect arrived as P3 regardless of what the checklist said. The rules
     * now:
     *
     *   • severity is the item's explicit priority (No-Go → P1, Go & Fix →
     *     P2, Defect → P3), set in the template editor; a row saved before
     *     that setting existed still derives it from its category wording.
     *     A No-Go item stops the machine, so it can never sit at the bottom
     *     of the list behind a routine P3.
     *   • an item that now conforms closes the defect it opened — the closure
     *     records the inspection that cleared it (verified_by / verified_at),
     *     so "who said this is fixed, and on what evidence" has an answer.
     *   • a repeat failure does NOT add a row beside an open one: the existing
     *     defect is annotated with the new sighting and escalated if the new
     *     verdict is more severe. When the earlier defect was already closed,
     *     the new row records `recurrence_of` — a repeat is visible as a
     *     repeat, not as a brand-new problem.
     *
     * @return array ['created'=>int,'annotated'=>int,'closed'=>int]
     */
    public function process_backlog_findings($record_id) {
        $stats = array('created' => 0, 'annotated' => 0, 'closed' => 0);
        if (!$this->db->table_exists('backlogs')) return $stats;

        $record = $this->get_record($record_id);
        if (!$record) return $stats;

        // The closure-evidence/link columns are probed with field_exists(); on
        // a database without them the method still works, it just cannot link
        // or verify.
        $has_item_link  = $this->db->field_exists('inspection_item_id', 'backlogs');
        $has_recurrence = $this->db->field_exists('recurrence_of', 'backlogs');
        $has_verified   = $this->db->field_exists('verified_by', 'backlogs');

        $items = $this->get_record_items($record_id);
        $who   = (int)$record->created_by;   // the person who signed the inspection

        foreach ($items as $item) {
            if (isset($item->row_type) && $item->row_type === 'section') continue;

            $verdict = $this->_inspection_item_verdict($item);
            $source_ref = 'insp_'.$record_id.'_item_'.$item->item_id;

            // ── the item conforms now: close the defect it opened ─────────
            if (!$verdict['failed']) {
                if ($has_item_link && $record->equipment_id) {
                    $this->db->where('equipment_id', (int)$record->equipment_id)
                        ->where('inspection_item_id', (int)$item->item_id)
                        ->where_in('status', array('Open', 'In Progress'));
                    $open = $this->db->get('backlogs')->result();

                    foreach ($open as $row) {
                        $close = array(
                            'status'          => 'Closed',
                            'end_date'        => $record->inspection_date ?: date('Y-m-d'),
                            'closing_remarks' => 'Closed by inspection '.($record->inspection_date ?: date('Y-m-d'))
                                .' (record #'.$record_id.'): the item conforms. '
                                .'Previously: '.mb_substr((string)$row->description, 0, 120),
                        );
                        if ($has_verified) {
                            $close['verified_by']         = $who ?: null;
                            $close['verified_at']         = date('Y-m-d H:i:s');
                            $close['verification_notes'] = 'Cleared on inspection record #'.$record_id
                                .' ('.($item->item_no !== null ? 'item '.$item->item_no : 'checklist item').').';
                        }
                        $this->db->where('id', (int)$row->id)->update('backlogs', $close);
                        $stats['closed']++;
                    }
                }
                continue;
            }

            // ── the item failed ──────────────────────────────────────────
            $priority = $verdict['priority'];

            // Re-processing the same record must not duplicate its own defects.
            $same = $this->db->where('source_ref', $source_ref)->get('backlogs')->row();
            if ($same) continue;

            $desc = ($record->language === 'fr' && !empty($item->text_fr)) ? $item->text_fr : $item->text_en;
            if (!empty($item->comment)) {
                $desc .= ' - '.$item->comment;
            }

            // An identical defect from an earlier inspection that is still open:
            // annotate it rather than stacking a second row on the same fault.
            $open_same = null;
            if ($has_item_link && $record->equipment_id) {
                $open_same = $this->db->where('equipment_id', (int)$record->equipment_id)
                    ->where('inspection_item_id', (int)$item->item_id)
                    ->where_in('status', array('Open', 'In Progress'))
                    ->order_by('id', 'ASC')->limit(1)
                    ->get('backlogs')->row();
            }

            if ($open_same) {
                $observation = trim((string)$open_same->observation);
                $note = 'Re-seen on inspection '.($record->inspection_date ?: date('Y-m-d'))
                    .' (record #'.$record_id.'): '.$verdict['label'].'.';
                $update = array(
                    'observation' => $observation === '' ? $note : $observation."\n".$note,
                    'updated_at'  => date('Y-m-d H:i:s'),
                );
                if ($has_item_link) $update['priority'] = $this->_escalate($open_same->priority, $priority);
                $this->db->where('id', (int)$open_same->id)->update('backlogs', $update);
                $stats['annotated']++;
                continue;
            }

            // A repeat of a defect that was closed before: link it back.
            $previous = null;
            if ($has_item_link && $record->equipment_id) {
                $previous = $this->db->where('equipment_id', (int)$record->equipment_id)
                    ->where('inspection_item_id', (int)$item->item_id)
                    ->where('status', 'Closed')
                    ->order_by('id', 'DESC')->limit(1)
                    ->get('backlogs')->row();
            }

            $backlog_data = array(
                'equipment_id'   => $record->equipment_id,
                'asset_label'    => $record->fleet_number,
                'model'          => $record->model,
                'serial_no'      => $record->serial_number,
                'start_odometer' => $record->smr,
                'start_date'     => $record->inspection_date,
                'description'    => $desc,
                'source'         => 'Inspection',
                'reported_by'    => $record->mechanic_name ?: $record->operator_name,
                'priority'       => $priority,
                'status'         => 'Open',
                'machine_status' => $verdict['machine_status'],
                'source_ref'     => $source_ref,
                'created_at'     => date('Y-m-d H:i:s'),
            );
            if ($has_item_link)  $backlog_data['inspection_item_id'] = (int)$item->item_id;
            if ($has_recurrence && $previous) {
                $backlog_data['recurrence_of'] = (int)$previous->id;
                $backlog_data['observation'] = 'Recurrence of backlog #'.$previous->id
                    .' (first reported '.($previous->start_date ?: 'unknown').', closed '.($previous->end_date ?: 'unknown').').';
            }

            $this->db->insert('backlogs', $backlog_data);
            $stats['created']++;
        }

        return $stats;
    }

    /**
     * What a checklist item says. `No-Go` stops the machine; `Go & Fix` allows
     * operation but needs the repair — so the first is P1 and the second P2,
     * whatever the free-text comment says.
     */
    private function _inspection_item_verdict($item) {
        $failed = false;
        $values = array();

        if (!empty($item->value_json)) {
            $val = json_decode($item->value_json, true);
            if (is_array($val)) {
                foreach ($val as $k => $v) {
                    if (is_string($v)) $values[] = $v;
                }
                if (isset($val['result']) && $val['result'] === 'No') $failed = true;
                if (in_array('fail', $values, true)) $failed = true;
            }
        }

        // K-16: an explicit severity set on the checklist row wins. It is a
        // fixed code picked in the template editor, so renaming the category
        // (or writing it in another language) no longer changes what a
        // failure is escalated to.
        $explicit = isset($item->priority) ? strtoupper((string)(trim((string)$item->priority))) : '';
        if ($explicit === 'P1') {
            return array('failed' => $failed, 'priority' => 'P1', 'label' => 'No-Go', 'machine_status' => 'BREAKDOWN');
        }
        if ($explicit === 'P2') {
            return array('failed' => $failed, 'priority' => 'P2', 'label' => 'Go & Fix', 'machine_status' => 'OPERATIONAL');
        }
        if ($explicit === 'P3') {
            return array('failed' => $failed, 'priority' => 'P3', 'label' => 'Defect', 'machine_status' => NULL);
        }

        // No explicit severity (a template saved before K-16, or "Auto"):
        // fall back to the category wording, exactly as before. The substring
        // aliases below are what the seeded checklists use ("No-Go",
        // "Go & Fix", "Arrêt", "Non conforme"…).
        $category = strtolower((string)(trim((string)(isset($item->category_en) ? $item->category_en : $item->category_fr))));
        $no_go    = (strpos($category, 'no-go') !== false || strpos($category, 'arrêt') !== false
                     || strpos($category, 'interdit') !== false || strpos($category, 'non conforme') !== false);
        $go_fix   = (strpos($category, 'go & fix') !== false || strpos($category, 'corriger') !== false
                     || strpos($category, 'conforme') !== false);

        if ($no_go) {
            return array('failed' => $failed, 'priority' => 'P1', 'label' => 'No-Go', 'machine_status' => 'BREAKDOWN');
        }
        if ($go_fix) {
            return array('failed' => $failed, 'priority' => 'P2', 'label' => 'Go & Fix', 'machine_status' => 'OPERATIONAL');
        }
        return array('failed' => $failed, 'priority' => 'P3', 'label' => 'Defect', 'machine_status' => NULL);
    }

    /** P1 is the most severe: an escalation moves up, never down. */
    private function _escalate($current, $candidate) {
        $rank = array('P1' => 1, 'P2' => 2, 'P3' => 3);
        $a = isset($rank[$current])   ? $rank[$current]   : 4;
        $b = isset($rank[$candidate]) ? $rank[$candidate] : 4;
        if ($b < $a) return $candidate;
        return $current;   // untouched when the current priority is still the worse one
    }
}
