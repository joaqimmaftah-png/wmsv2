<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Supplier_model extends CI_Model {

    public static $STATUSES = array('Active', 'Inactive', 'Blacklisted');

    /** Normalize supplier status strictly to valid ENUM values. */
    public static function clean_status($val) {
        $s = trim((string)$val);
        return in_array($s, self::$STATUSES, TRUE) ? $s : 'Active';
    }

    /**
     * Single-pass O(1)-query Directory Overview (<= 4 flat SQL queries total,
     * zero correlated per-row subqueries) hydrating every supplier row with:
     *   - Primary contact details & contact count (`supplier_contacts`)
     *   - Catalog item count, preferred quote count & search haystack (`supplier_materials`)
     *   - Purchase order counts, open PO exposure, cumulative spend, on-time
     *     delivery rate & verbatim linked fleet machine codes (`spare_orders`
     *     -> `work_orders` -> `equipment.registration_no`)
     */
    public function get_directory_overview() {
        $suppliers = $this->db->select('s.*')
            ->from('suppliers s')
            ->order_by('s.company_name', 'ASC')
            ->get()
            ->result();

        $contactsBySup = array();
        if (!empty($suppliers) && $this->db->table_exists('supplier_contacts')) {
            $cRows = $this->db->select('sc.*')
                ->from('supplier_contacts sc')
                ->order_by('sc.id', 'ASC')
                ->get()
                ->result();
            foreach ($cRows as $c) {
                $sid = (int)$c->supplier_id;
                if (!isset($contactsBySup[$sid])) $contactsBySup[$sid] = array();
                $contactsBySup[$sid][] = $c;
            }
        }

        $catalogBySup   = array();
        $totalCatQuotes = 0;
        if (!empty($suppliers) && $this->db->table_exists('supplier_materials')) {
            $mRows = $this->db->select('sm.id, sm.supplier_id, sm.material_id, sm.part_number, sm.description, sm.unit_price, sm.currency, sm.lead_time_days, sm.is_preferred')
                ->from('supplier_materials sm')
                ->get()
                ->result();
            foreach ($mRows as $m) {
                $sid = (int)$m->supplier_id;
                if (!isset($catalogBySup[$sid])) $catalogBySup[$sid] = array();
                $catalogBySup[$sid][] = $m;
                $totalCatQuotes++;
            }
        }

        $ordersBySup = array();
        if (!empty($suppliers) && $this->db->table_exists('spare_orders')) {
            $this->db->select('o.id, o.order_no, o.supplier_id, o.status, o.currency, o.total_cost, o.order_date, o.expected_delivery_date, o.actual_delivery_date, wo.wo_number, eq.registration_no')
                ->from('spare_orders o')
                ->join('work_orders wo', 'wo.id = o.linked_wo_id', 'left')
                ->join('equipment eq', 'eq.id = wo.equipment_id', 'left')
                ->order_by('o.order_date', 'DESC');
            $oRows = $this->db->get()->result();
            foreach ($oRows as $o) {
                $sid = (int)$o->supplier_id;
                if (!isset($ordersBySup[$sid])) $ordersBySup[$sid] = array();
                $ordersBySup[$sid][] = $o;
            }
        }

        $countriesMap  = array();
        $currenciesMap = array();
        $openCostByCcy = array();
        $kpi = array(
            'total'         => count($suppliers),
            'active'        => 0,
            'inactive'      => 0,
            'blacklisted'   => 0,
            'countries'     => 0,
            'with_catalog'  => 0,
            'catalog_items' => $totalCatQuotes,
            'with_orders'   => 0,
            'open_orders'   => 0,
        );

        foreach ($suppliers as $s) {
            $sid = (int)$s->id;
            $s->status   = self::clean_status($s->status ?? 'Active');
            $s->currency = strtoupper(trim((string)($s->currency ?: 'TZS')));
            $countryKey  = trim((string)($s->country ?? ''));
            if ($countryKey !== '') $countriesMap[$countryKey] = TRUE;
            if ($s->currency !== '') $currenciesMap[$s->currency] = TRUE;

            if ($s->status === 'Active') $kpi['active']++;
            elseif ($s->status === 'Blacklisted') $kpi['blacklisted']++;
            else $kpi['inactive']++;

            // Primary contact & contact search blob
            $cList = isset($contactsBySup[$sid]) ? $contactsBySup[$sid] : array();
            $s->contact_count = count($cList);
            $primary = !empty($cList) ? $cList[0] : NULL;
            $s->primary_contact_name  = $primary ? (string)($primary->name ?? '') : '';
            $s->primary_contact_role  = $primary ? (string)($primary->role ?? '') : '';
            $s->primary_contact_phone = $primary ? (string)($primary->phone ?? '') : '';
            $s->primary_contact_email = $primary ? (string)($primary->email ?? '') : '';
            $cBlob = array();
            foreach ($cList as $c) {
                $cBlob[] = trim((string)($c->name ?? '') . ' ' . (string)($c->email ?? '') . ' ' . (string)($c->phone ?? ''));
            }
            $s->contacts_search_blob = implode(' ', $cBlob);

            // Catalog counts & search blob
            $catList = isset($catalogBySup[$sid]) ? $catalogBySup[$sid] : array();
            $s->item_count = count($catList);
            if ($s->item_count > 0) $kpi['with_catalog']++;
            $prefCnt = 0;
            $mBlob   = array();
            foreach ($catList as $cm) {
                if (!empty($cm->is_preferred)) $prefCnt++;
                $mBlob[] = trim((string)($cm->part_number ?? '') . ' ' . (string)($cm->description ?? ''));
            }
            $s->preferred_count     = $prefCnt;
            $s->catalog_search_blob = implode(' ', $mBlob);

            // Purchase Orders rollup, open exposure, on-time delivery & verbatim fleet machine IDs
            $oList      = isset($ordersBySup[$sid]) ? $ordersBySup[$sid] : array();
            $orderCnt   = count($oList);
            $openCnt    = 0;
            $openCost   = 0.0;
            $totalSpend = 0.0;
            $otSample   = 0;
            $otHits     = 0;
            $fleetSet   = array();
            $woBlob     = array();

            foreach ($oList as $o) {
                $st   = (string)($o->status ?? '');
                $cost = (float)($o->total_cost ?? 0);
                $ccy  = strtoupper(trim((string)($o->currency ?: $s->currency)));
                if ($st !== 'Cancelled') {
                    $totalSpend += $cost;
                }
                if (!in_array($st, array('Verified & Closed', 'Cancelled'), TRUE)) {
                    $openCnt++;
                    $openCost += $cost;
                    $kpi['open_orders']++;
                    if (!isset($openCostByCcy[$ccy])) $openCostByCcy[$ccy] = 0.0;
                    $openCostByCcy[$ccy] += $cost;
                }
                if (in_array($st, array('Received at Store', 'Verified & Closed'), TRUE)
                    && !empty($o->expected_delivery_date)
                    && !empty($o->actual_delivery_date)) {
                    $otSample++;
                    if (strcmp((string)$o->actual_delivery_date, (string)$o->expected_delivery_date) <= 0) {
                        $otHits++;
                    }
                }
                if (!empty($o->registration_no)) {
                    $reg = (string)$o->registration_no;
                    $fleetSet[$reg] = $reg;
                }
                if (!empty($o->order_no)) $woBlob[] = (string)$o->order_no;
                if (!empty($o->wo_number)) $woBlob[] = (string)$o->wo_number;
            }

            if ($orderCnt > 0) $kpi['with_orders']++;
            $s->order_count      = $orderCnt;
            $s->open_order_count = $openCnt;
            $s->open_order_cost  = $openCost;
            $s->total_spend      = $totalSpend;
            $s->on_time_sample   = $otSample;
            $s->on_time_rate     = $otSample > 0 ? (int)round(($otHits / $otSample) * 100) : NULL;
            $s->fleet_assets     = array_values($fleetSet);
            $s->orders_search_blob = implode(' ', array_merge($s->fleet_assets, $woBlob));
        }

        $countries = array_keys($countriesMap);
        natcasesort($countries);
        $countries = array_values($countries);

        $currencies = array_keys($currenciesMap);
        sort($currencies);

        $kpi['countries']         = count($countries);
        $kpi['open_cost_by_ccy']  = $openCostByCcy;

        return array(
            'rows'       => $suppliers,
            'kpi'        => $kpi,
            'countries'  => $countries,
            'currencies' => $currencies,
        );
    }

    public function get_all($filter = array()) {
        $ov  = $this->get_directory_overview();
        $out = array();
        foreach ($ov['rows'] as $s) {
            if (!empty($filter['country']) && strcasecmp((string)$s->country, (string)$filter['country']) !== 0) continue;
            if (!empty($filter['status']) && (string)$s->status !== (string)$filter['status']) continue;
            if (!empty($filter['search'])) {
                $q = strtolower(trim((string)$filter['search']));
                $hay = strtolower((string)$s->company_name . ' ' . (string)($s->tax_id ?? ''));
                if ($q !== '' && strpos($hay, $q) === FALSE) continue;
            }
            $out[] = $s;
        }
        return $out;
    }

    public function get_by_id($id) {
        $row = $this->db->where('id', (int)$id)->get('suppliers')->row();
        if ($row) {
            $row->status = self::clean_status($row->status ?? 'Active');
        }
        return $row;
    }

    public function get_countries() {
        $rows = $this->db->select('country')->distinct()->order_by('country', 'ASC')->get('suppliers')->result();
        return array_map(function ($r) { return $r->country; }, $rows);
    }

    public function insert($data) {
        if (isset($data['status'])) {
            $data['status'] = self::clean_status($data['status']);
        }
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');
        $this->db->insert('suppliers', $data);
        return $this->db->insert_id();
    }

    public function update($id, $data) {
        if (isset($data['status'])) {
            $data['status'] = self::clean_status($data['status']);
        }
        $data['updated_at'] = date('Y-m-d H:i:s');
        return $this->db->where('id', (int)$id)->update('suppliers', $data);
    }

    /** Count linked purchase orders in `spare_orders` (RESTRICT FK `fk_so_supplier`). */
    public function count_linked_orders($supplier_id) {
        if (!$this->db->table_exists('spare_orders')) return 0;
        return (int)$this->db->where('supplier_id', (int)$supplier_id)->count_all_results('spare_orders');
    }

    public function can_delete($supplier_id) {
        return $this->count_linked_orders($supplier_id) === 0;
    }

    public function delete($id) {
        if (!$this->can_delete($id)) {
            return FALSE;
        }
        return $this->db->where('id', (int)$id)->delete('suppliers');
    }

    /* ── Contacts ──────────────────────────────────────── */
    public function get_contacts($supplier_id) {
        return $this->db->where('supplier_id', (int)$supplier_id)->order_by('id', 'ASC')->get('supplier_contacts')->result();
    }

    public function add_contact($data) {
        $data['created_at'] = date('Y-m-d H:i:s');
        $this->db->insert('supplier_contacts', $data);
        return $this->db->insert_id();
    }

    public function delete_contact($id, $supplier_id) {
        $deleted = $this->db->where('id', (int)$id)
            ->where('supplier_id', (int)$supplier_id)
            ->delete('supplier_contacts');
        return $deleted && $this->db->affected_rows() > 0;
    }

    /* ── Catalog (supplier ↔ material mapping) ───────────── */
    public function get_catalog($supplier_id) {
        $this->db->select('sm.*, m.item_code, m.description as material_desc, m.final_qty as in_stock')
            ->from('supplier_materials sm')
            ->join('materials m', 'm.id = sm.material_id', 'left')
            ->where('sm.supplier_id', (int)$supplier_id)
            ->order_by('sm.is_preferred', 'DESC')
            ->order_by('sm.description', 'ASC');
        return $this->db->get()->result();
    }

    public function add_catalog_item($data) {
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');
        $this->db->insert('supplier_materials', $data);
        return $this->db->insert_id();
    }

    public function update_catalog_item($id, $data) {
        $data['updated_at'] = date('Y-m-d H:i:s');
        return $this->db->where('id', (int)$id)->update('supplier_materials', $data);
    }

    public function delete_catalog_item($id, $supplier_id) {
        $deleted = $this->db->where('id', (int)$id)
            ->where('supplier_id', (int)$supplier_id)
            ->delete('supplier_materials');
        return $deleted && $this->db->affected_rows() > 0;
    }

    /** Recent orders for a supplier enriched with linked Work Order & verbatim machine registration_no. */
    public function get_recent_orders_with_fleet($supplier_id, $limit = 12) {
        if (!$this->db->table_exists('spare_orders')) return array();
        return $this->db->select('o.*, wo.wo_number, eq.registration_no, eq.id as equipment_db_id')
            ->from('spare_orders o')
            ->join('work_orders wo', 'wo.id = o.linked_wo_id', 'left')
            ->join('equipment eq', 'eq.id = wo.equipment_id', 'left')
            ->where('o.supplier_id', (int)$supplier_id)
            ->order_by('o.order_date', 'DESC')
            ->order_by('o.id', 'DESC')
            ->limit((int)$limit)
            ->get()
            ->result();
    }

    /* "Who supplies this part?" — search across all suppliers' catalogs */
    public function find_suppliers_for_part($search) {
        $this->db->select('sm.*, s.company_name, s.country, s.status as supplier_status, s.lead_time_guarantee_days, m.item_code, m.final_qty as in_stock')
            ->from('supplier_materials sm')
            ->join('suppliers s', 's.id = sm.supplier_id')
            ->join('materials m', 'm.id = sm.material_id', 'left')
            ->group_start()
                ->like('sm.description', $search)
                ->or_like('sm.part_number', $search)
                ->or_like('m.item_code', $search)
            ->group_end()
            ->order_by('sm.is_preferred', 'DESC')
            ->order_by('s.company_name', 'ASC');
        $rows = $this->db->get()->result();
        foreach ($rows as $r) {
            $r->supplier_status = self::clean_status($r->supplier_status ?? 'Active');
        }
        return $rows;
    }

    public function get_kpi() {
        $rows = $this->db->select('status, country')->from('suppliers')->get()->result();
        $active      = 0;
        $blacklisted = 0;
        $countries   = array();
        foreach ($rows as $r) {
            $st = self::clean_status($r->status ?? 'Active');
            if ($st === 'Active') $active++;
            elseif ($st === 'Blacklisted') $blacklisted++;
            $c = trim((string)($r->country ?? ''));
            if ($c !== '') $countries[$c] = TRUE;
        }
        return array(
            'total'       => count($rows),
            'active'      => $active,
            'blacklisted' => $blacklisted,
            'countries'   => count($countries),
        );
    }
}
