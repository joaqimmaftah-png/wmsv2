<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Suppliers extends MY_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->model('Supplier_model');
        $this->load->model('Materials_model');
    }

    /** Neutralize spreadsheet formula prefixes in both CSV and OpenXML XLSX cells. */
    protected function _csv_guard($val) {
        $s = (string)$val;
        if ($s !== '' && in_array($s[0], array('=', '+', '-', '@', "\t", "\r"), TRUE)) {
            return "'" . $s;
        }
        return $s;
    }

    /** Validate and normalize supplier POST payload. */
    protected function _clean_supplier_payload($defaultCountry = 'Tanzania') {
        $leadRaw = trim((string)$this->input->post('lead_time_guarantee_days', TRUE));
        $leadDays = ($leadRaw !== '' && is_numeric($leadRaw)) ? max(0, (int)$leadRaw) : NULL;

        $country = trim((string)$this->input->post('country', TRUE));
        if ($country === '' && $defaultCountry !== '') {
            $country = $defaultCountry;
        }

        return array(
            'company_name'             => trim((string)$this->input->post('company_name', TRUE)),
            'tax_id'                   => trim((string)$this->input->post('tax_id', TRUE)) ?: NULL,
            'country'                  => $country,
            'city'                     => trim((string)$this->input->post('city', TRUE)) ?: NULL,
            'address'                  => trim((string)$this->input->post('address', TRUE)) ?: NULL,
            'currency'                 => wms_currency_code($this->input->post('currency', TRUE)),
            'payment_terms'            => trim((string)$this->input->post('payment_terms', TRUE)) ?: NULL,
            'lead_time_guarantee_days' => $leadDays,
            'status'                   => Supplier_model::clean_status($this->input->post('status', TRUE)),
            'notes'                    => trim((string)$this->input->post('notes', TRUE)) ?: NULL,
        );
    }

    /**
     * Build the filtered, sorted, country-grouped, and optionally paginated
     * Supplier Directory dataset shared across index(), export_csv(), and export_xlsx().
     */
    protected function _build_suppliers_dataset($paginate = TRUE) {
        $ov  = $this->Supplier_model->get_directory_overview();
        $all = $ov['rows'];

        $allowedTabs = array('all', 'active', 'with_catalog', 'with_orders', 'flagged');
        $tab = strtolower(trim((string)$this->input->get('tab', TRUE)));
        if (!in_array($tab, $allowedTabs, TRUE)) $tab = 'all';

        $qRaw = trim((string)$this->input->get('q', TRUE));
        if ($qRaw === '') {
            $qRaw = trim((string)$this->input->get('search', TRUE));
        }

        $countryF  = trim((string)$this->input->get('country', TRUE));
        $currencyF = strtoupper(trim((string)$this->input->get('currency', TRUE)));
        $statusF   = trim((string)$this->input->get('status', TRUE));
        if ($statusF === 'all') $statusF = '';

        $idsRaw = trim((string)$this->input->get('ids', TRUE));
        $idSet  = array();
        if ($idsRaw !== '') {
            foreach (explode(',', $idsRaw) as $tok) {
                $iv = (int)trim($tok);
                if ($iv > 0) $idSet[$iv] = TRUE;
            }
        }

        $tabCounts = array(
            'all'          => count($all),
            'active'       => 0,
            'with_catalog' => 0,
            'with_orders'  => 0,
            'flagged'      => 0,
        );
        foreach ($all as $row) {
            if ($row->status === 'Active') $tabCounts['active']++;
            if ((int)$row->item_count > 0) $tabCounts['with_catalog']++;
            if ((int)$row->order_count > 0) $tabCounts['with_orders']++;
            if ($row->status !== 'Active') $tabCounts['flagged']++;
        }

        $qLower = strtolower($qRaw);
        $filtered = array();
        foreach ($all as $s) {
            if (!empty($idSet) && !isset($idSet[(int)$s->id])) continue;

            if ($tab === 'active' && $s->status !== 'Active') continue;
            if ($tab === 'with_catalog' && (int)$s->item_count <= 0) continue;
            if ($tab === 'with_orders' && (int)$s->order_count <= 0) continue;
            if ($tab === 'flagged' && $s->status === 'Active') continue;

            if ($countryF !== '' && strcasecmp((string)$s->country, $countryF) !== 0) continue;
            if ($currencyF !== '' && strcasecmp((string)$s->currency, $currencyF) !== 0) continue;
            if ($statusF !== '' && strcasecmp((string)$s->status, $statusF) !== 0) continue;

            if ($qLower !== '') {
                $hay = strtolower(
                    (string)$s->company_name . ' '
                    . (string)($s->tax_id ?? '') . ' '
                    . (string)($s->country ?? '') . ' '
                    . (string)($s->city ?? '') . ' '
                    . (string)($s->currency ?? '') . ' '
                    . (string)($s->payment_terms ?? '') . ' '
                    . (string)($s->notes ?? '') . ' '
                    . (string)($s->contacts_search_blob ?? '') . ' '
                    . (string)($s->catalog_search_blob ?? '') . ' '
                    . (string)($s->orders_search_blob ?? '')
                );
                if (strpos($hay, $qLower) === FALSE) continue;
            }
            $filtered[] = $s;
        }

        $statusRank = array('Active' => 1, 'Inactive' => 2, 'Blacklisted' => 3);
        $allowedSort = array('company_name', 'country', 'lead_time', 'item_count', 'order_count', 'on_time', 'status');
        $sort = strtolower(trim((string)$this->input->get('sort', TRUE)));
        if (!in_array($sort, $allowedSort, TRUE)) $sort = 'company_name';
        $defaultDir = in_array($sort, array('item_count', 'order_count', 'on_time'), TRUE) ? 'desc' : 'asc';
        $dirParam   = strtolower(trim((string)$this->input->get('dir', TRUE)));
        $dir        = in_array($dirParam, array('asc', 'desc'), TRUE) ? $dirParam : $defaultDir;
        $mul        = ($dir === 'asc') ? 1 : -1;

        usort($filtered, function ($a, $b) use ($sort, $mul, $statusRank) {
            switch ($sort) {
                case 'country':
                    $cmp = strcasecmp((string)$a->country, (string)$b->country);
                    break;
                case 'lead_time':
                    $la = $a->lead_time_guarantee_days !== NULL ? (int)$a->lead_time_guarantee_days : 999999;
                    $lb = $b->lead_time_guarantee_days !== NULL ? (int)$b->lead_time_guarantee_days : 999999;
                    $cmp = ($la <=> $lb);
                    break;
                case 'item_count':
                    $cmp = ((int)$a->item_count <=> (int)$b->item_count);
                    break;
                case 'order_count':
                    $cmp = ((int)$a->order_count <=> (int)$b->order_count);
                    break;
                case 'on_time':
                    $oa = $a->on_time_rate !== NULL ? (int)$a->on_time_rate : -1;
                    $ob = $b->on_time_rate !== NULL ? (int)$b->on_time_rate : -1;
                    $cmp = ($oa <=> $ob);
                    break;
                case 'status':
                    $ra = isset($statusRank[$a->status]) ? $statusRank[$a->status] : 9;
                    $rb = isset($statusRank[$b->status]) ? $statusRank[$b->status] : 9;
                    $cmp = ($ra <=> $rb);
                    break;
                case 'company_name':
                default:
                    $cmp = strcasecmp((string)$a->company_name, (string)$b->company_name);
                    break;
            }
            if ($cmp === 0) {
                $cmp = strcasecmp((string)$a->company_name, (string)$b->company_name);
            }
            return $cmp * $mul;
        });

        // Group filtered suppliers by country corridor for the "By Country" view
        $groupsMap = array();
        foreach ($filtered as $s) {
            $ck = trim((string)($s->country ?: 'Unspecified'));
            if (!isset($groupsMap[$ck])) {
                $groupsMap[$ck] = (object)array(
                    'country'      => $ck,
                    'suppliers'    => array(),
                    'total_count'  => 0,
                    'active_count' => 0,
                    'item_count'   => 0,
                    'open_orders'  => 0,
                );
            }
            $g = $groupsMap[$ck];
            $g->suppliers[] = $s;
            $g->total_count++;
            if ($s->status === 'Active') $g->active_count++;
            $g->item_count  += (int)$s->item_count;
            $g->open_orders += (int)$s->open_order_count;
        }
        ksort($groupsMap, SORT_NATURAL | SORT_FLAG_CASE);

        $viewMode = strtolower(trim((string)$this->input->get('view', TRUE)));
        if (!in_array($viewMode, array('table', 'groups', 'cards'), TRUE)) $viewMode = 'table';

        $allowedPP     = array(10, 25, 50, 100, 150);
        $perPage       = (int)$this->input->get('per_page');
        if (!in_array($perPage, $allowedPP, TRUE)) $perPage = 25;
        $totalFiltered = count($filtered);
        $totalPages    = max(1, (int)ceil($totalFiltered / $perPage));
        $page          = max(1, min((int)$this->input->get('page') ?: 1, $totalPages));
        $offset        = ($page - 1) * $perPage;
        $rows          = $paginate ? array_slice($filtered, $offset, $perPage) : $filtered;

        return array(
            'suppliers'      => $rows,
            'groups'         => array_values($groupsMap),
            'countries'      => $ov['countries'],
            'currencies'     => $ov['currencies'],
            'kpi'            => $ov['kpi'],
            'tab_counts'     => $tabCounts,
            'tab'            => $tab,
            'q'              => $qRaw,
            'filter'         => array(
                'country'  => $countryF,
                'currency' => $currencyF,
                'status'   => $statusF,
                'search'   => $qRaw,
            ),
            'sort'           => $sort,
            'dir'            => $dir,
            'view_mode'      => $viewMode,
            'per_page'       => $perPage,
            'allowed_pp'     => $allowedPP,
            'current_page'   => $page,
            'total_pages'    => $totalPages,
            'total_filtered' => $totalFiltered,
            'offset'         => $offset,
            'can_write'      => $this->can_write(),
            'page_title'     => 'Suppliers',
        );
    }

    /* ── LIST ───────────────────────────────────────────── */
    public function index() {
        $data = $this->_build_suppliers_dataset(TRUE);
        $this->render('suppliers/index', $data);
    }

    /* ── SLIDE-OVER VENDOR INSPECTOR JSON ───────────────── */
    public function summary_json($id) {
        $supplier = $this->Supplier_model->get_by_id((int)$id);
        if (!$supplier) {
            $this->json(array('ok' => FALSE, 'error' => 'Supplier not found.'), 404);
            return;
        }

        $this->load->model('Order_model');
        $onTime  = $this->Order_model->get_supplier_on_time_rate((int)$supplier->id);
        $cRows   = $this->Supplier_model->get_contacts((int)$supplier->id);
        $mRows   = $this->Supplier_model->get_catalog((int)$supplier->id);
        $oRows   = $this->Supplier_model->get_recent_orders_with_fleet((int)$supplier->id, 8);

        $contacts = array();
        foreach ($cRows as $c) {
            $contacts[] = array(
                'id'    => (int)$c->id,
                'name'  => (string)($c->name ?? ''),
                'role'  => (string)($c->role ?: 'Contact'),
                'phone' => (string)($c->phone ?: ''),
                'email' => (string)($c->email ?: ''),
            );
        }

        $catalog = array();
        foreach ($mRows as $m) {
            $catalog[] = array(
                'id'             => (int)$m->id,
                'material_id'    => $m->material_id ? (int)$m->material_id : NULL,
                'item_code'      => (string)($m->item_code ?? ''),
                'part_number'    => (string)($m->part_number ?: '—'),
                'description'    => (string)($m->description ?? ''),
                'unit_price'     => $m->unit_price !== NULL ? (float)$m->unit_price : NULL,
                'currency'       => (string)($m->currency ?: $supplier->currency),
                'moq'            => $m->moq !== NULL ? (float)$m->moq : NULL,
                'lead_time_days' => $m->lead_time_days !== NULL ? (int)$m->lead_time_days : NULL,
                'in_stock'       => isset($m->in_stock) && $m->in_stock !== NULL ? (float)$m->in_stock : NULL,
                'is_preferred'   => (int)$m->is_preferred === 1,
            );
        }

        $orders = array();
        foreach ($oRows as $o) {
            $orders[] = array(
                'id'              => (int)$o->id,
                'order_no'        => (string)$o->order_no,
                'order_date'      => (string)$o->order_date,
                'status'          => (string)$o->status,
                'currency'        => (string)$o->currency,
                'total_cost'      => (float)$o->total_cost,
                'wo_number'       => (string)($o->wo_number ?? ''),
                'registration_no' => (string)($o->registration_no ?? ''),
            );
        }

        $this->json(array(
            'ok'       => TRUE,
            'supplier' => array(
                'id'                       => (int)$supplier->id,
                'company_name'             => (string)$supplier->company_name,
                'tax_id'                   => (string)($supplier->tax_id ?: '—'),
                'country'                  => (string)$supplier->country,
                'city'                     => (string)($supplier->city ?: ''),
                'address'                  => (string)($supplier->address ?: '—'),
                'currency'                 => (string)$supplier->currency,
                'payment_terms'            => (string)($supplier->payment_terms ?: 'Standard terms'),
                'lead_time_guarantee_days' => $supplier->lead_time_guarantee_days !== NULL ? (int)$supplier->lead_time_guarantee_days : NULL,
                'status'                   => (string)$supplier->status,
                'notes'                    => (string)($supplier->notes ?: ''),
                'on_time_rate'             => $onTime ? (int)$onTime['rate'] : NULL,
                'on_time_sample'           => $onTime ? (int)$onTime['sample'] : 0,
                'can_write'                => $this->can_write(),
            ),
            'contacts' => $contacts,
            'catalog'  => $catalog,
            'orders'   => $orders,
        ));
    }

    /* ── UTF-8 BOM CSV EXPORT ───────────────────────────── */
    public function export_csv() {
        $data = $this->_build_suppliers_dataset(FALSE);
        $rows = $data['suppliers'];

        $filename = 'suppliers-' . date('Y-m-d') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");

        fputcsv($out, array(
            'Supplier ID',
            'Company Name',
            'Status',
            'Country',
            'City',
            'Tax ID',
            'Currency',
            'Payment Terms',
            'Quoted Lead Time (Days)',
            'Primary Contact',
            'Contact Phone',
            'Contact Email',
            'Catalog Items',
            'Preferred Items',
            'Total Orders',
            'Open Orders',
            'Open PO Value',
            'On-Time %',
            'Fleet Assets Served',
            'Notes',
        ));

        foreach ($rows as $s) {
            fputcsv($out, array(
                (int)$s->id,
                $this->_csv_guard($s->company_name ?? ''),
                $this->_csv_guard($s->status ?? 'Active'),
                $this->_csv_guard($s->country ?? ''),
                $this->_csv_guard($s->city ?? ''),
                $this->_csv_guard($s->tax_id ?? ''),
                $this->_csv_guard($s->currency ?? 'TZS'),
                $this->_csv_guard($s->payment_terms ?? ''),
                $s->lead_time_guarantee_days !== NULL ? (int)$s->lead_time_guarantee_days : '',
                $this->_csv_guard($s->primary_contact_name ?? ''),
                $this->_csv_guard($s->primary_contact_phone ?? ''),
                $this->_csv_guard($s->primary_contact_email ?? ''),
                (int)$s->item_count,
                (int)$s->preferred_count,
                (int)$s->order_count,
                (int)$s->open_order_count,
                number_format((float)$s->open_order_cost, 2, '.', ''),
                $s->on_time_rate !== NULL ? ((int)$s->on_time_rate . '%') : '',
                $this->_csv_guard(implode(', ', $s->fleet_assets ?? array())),
                $this->_csv_guard($s->notes ?? ''),
            ));
        }
        fclose($out);
        exit;
    }

    /* ── STYLED OPENXML XLSX EXPORT (FORMULA-SAFE) ──────── */
    public function export_xlsx() {
        $this->load->helper('xlsx');
        $data = $this->_build_suppliers_dataset(FALSE);
        $rows = $data['suppliers'];

        $xlsx  = new XlsxWriter();
        $sHdr  = 'bold|fontSize:10|fontName:Arial|align:center|valign:middle|bg:1E3A8A|fg:FFFFFF|border:thin|wrap';
        $sCell = 'fontSize:10|fontName:Arial|valign:middle|border:thin|wrap';
        $sC    = 'fontSize:10|fontName:Arial|align:center|valign:middle|border:thin';
        $sOk   = 'bold|fontSize:10|fontName:Arial|align:center|valign:middle|border:thin|bg:DCFCE7|fg:166534';
        $sBad  = 'bold|fontSize:10|fontName:Arial|align:center|valign:middle|border:thin|bg:FEE2E2|fg:991B1B';
        foreach (array($sHdr, $sCell, $sC, $sOk, $sBad) as $st) {
            $xlsx->registerStyle($st);
        }

        $xlsx->addSheet('Suppliers');
        $widths = array(
            1 => 10, 2 => 30, 3 => 13, 4 => 16, 5 => 15, 6 => 16, 7 => 9,
            8 => 22, 9 => 12, 10 => 22, 11 => 18, 12 => 11, 13 => 11, 14 => 14, 15 => 22, 16 => 28,
        );
        foreach ($widths as $col => $w) {
            $xlsx->setColWidth($col, $w);
        }
        $xlsx->writeRow(array(
            'ID', 'COMPANY NAME', 'STATUS', 'COUNTRY', 'CITY', 'TAX ID', 'CCY',
            'PAYMENT TERMS', 'LEAD (DAYS)', 'PRIMARY CONTACT', 'PHONE / EMAIL',
            'CATALOG', 'ORDERS', 'OPEN PO VAL', 'FLEET ASSETS', 'NOTES',
        ), $sHdr);
        $xlsx->freezePane('A2');
        $xlsx->setAutoFilter('A1:P1');

        foreach ($rows as $s) {
            $stStyle = $s->status === 'Active' ? $sOk : ($s->status === 'Blacklisted' ? $sBad : $sC);
            $contactMeta = trim((string)($s->primary_contact_phone ?? '') . ($s->primary_contact_phone && $s->primary_contact_email ? ' / ' : '') . (string)($s->primary_contact_email ?? ''));
            $xlsx->writeRowStyled(array(
                array('v' => (int)$s->id, 's' => $sC),
                array('v' => $this->_csv_guard($s->company_name ?? ''), 's' => $sCell),
                array('v' => $this->_csv_guard($s->status ?? 'Active'), 's' => $stStyle),
                array('v' => $this->_csv_guard($s->country ?? ''), 's' => $sC),
                array('v' => $this->_csv_guard($s->city ?? ''), 's' => $sC),
                array('v' => $this->_csv_guard($s->tax_id ?? ''), 's' => $sC),
                array('v' => $this->_csv_guard($s->currency ?? 'TZS'), 's' => $sC),
                array('v' => $this->_csv_guard($s->payment_terms ?? ''), 's' => $sCell),
                array('v' => $s->lead_time_guarantee_days !== NULL ? (int)$s->lead_time_guarantee_days : '', 's' => $sC),
                array('v' => $this->_csv_guard($s->primary_contact_name ?? ''), 's' => $sCell),
                array('v' => $this->_csv_guard($contactMeta), 's' => $sCell),
                array('v' => (int)$s->item_count, 's' => $sC),
                array('v' => (int)$s->order_count, 's' => $sC),
                array('v' => round((float)$s->open_order_cost, 2), 's' => $sC),
                array('v' => $this->_csv_guard(implode(', ', $s->fleet_assets ?? array())), 's' => $sCell),
                array('v' => $this->_csv_guard($s->notes ?? ''), 's' => $sCell),
            ));
        }

        $fname = 'Suppliers_' . date('Ymd_His') . '.xlsx';
        $tmp   = wms_temp_path($fname);
        $xlsx->save($tmp);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $fname . '"');
        header('Content-Length: ' . filesize($tmp));
        header('Cache-Control: max-age=0');
        readfile($tmp);
        @unlink($tmp);
        exit;
    }

    /* ── CREATE ─────────────────────────────────────────── */
    public function create() {
        if (!$this->can_write()) {
            show_error('Forbidden.', 403);
            return;
        }
        if ($this->input->method() === 'post') {
            $payload = $this->_clean_supplier_payload('Tanzania');
            if ($payload['company_name'] === '') {
                $this->session->set_flashdata('error', 'Company name is required.');
                redirect('suppliers/create');
                return;
            }
            $id = $this->Supplier_model->insert($payload);
            $this->session->set_flashdata('success', 'Supplier added successfully.');
            redirect('suppliers/view/' . $id);
            return;
        }
        $data = array(
            'can_write'  => $this->can_write(),
            'page_title' => 'Add Supplier',
        );
        $this->render('suppliers/form', $data);
    }

    /* ── EDIT ───────────────────────────────────────────── */
    public function edit($id) {
        if (!$this->can_write()) {
            show_error('Forbidden.', 403);
            return;
        }
        $supplier = $this->Supplier_model->get_by_id((int)$id);
        if (!$supplier) show_404();

        if ($this->input->method() === 'post') {
            $payload = $this->_clean_supplier_payload((string)($supplier->country ?: 'Tanzania'));
            if ($payload['company_name'] === '') {
                $this->session->set_flashdata('error', 'Company name is required.');
                redirect('suppliers/edit/' . (int)$supplier->id);
                return;
            }
            $this->Supplier_model->update((int)$supplier->id, $payload);
            $this->session->set_flashdata('success', 'Supplier updated successfully.');
            redirect('suppliers/view/' . (int)$supplier->id);
            return;
        }
        $data = array(
            'supplier'   => $supplier,
            'can_write'  => $this->can_write(),
            'page_title' => 'Edit Supplier',
        );
        $this->render('suppliers/form', $data);
    }

    /* ── VIEW (profile + contacts + catalog + orders) ───── */
    public function view($id) {
        $supplier = $this->Supplier_model->get_by_id((int)$id);
        if (!$supplier) show_404();

        $this->load->model('Order_model');
        $data = array(
            'supplier'          => $supplier,
            'contacts'          => $this->Supplier_model->get_contacts((int)$supplier->id),
            'catalog'           => $this->Supplier_model->get_catalog((int)$supplier->id),
            'materials'         => $this->can_write() ? $this->Materials_model->get_all() : array(),
            'on_time'           => $this->Order_model->get_supplier_on_time_rate((int)$supplier->id),
            'recent_orders'     => $this->Supplier_model->get_recent_orders_with_fleet((int)$supplier->id, 12),
            'linked_order_cnt'  => $this->Supplier_model->count_linked_orders((int)$supplier->id),
            'can_write'         => $this->can_write(),
            'page_title'        => $supplier->company_name,
        );
        $this->render('suppliers/view', $data);
    }

    /* ── CONTACTS ───────────────────────────────────────── */
    public function add_contact($supplier_id) {
        if (!$this->require_post()) return;
        if (!$this->can_write()) {
            show_error('Forbidden.', 403);
            return;
        }
        $supplier = $this->Supplier_model->get_by_id((int)$supplier_id);
        if (!$supplier) show_404();

        $name = trim((string)$this->input->post('name', TRUE));
        if ($name === '') {
            $this->session->set_flashdata('error', 'Contact name is required.');
            redirect('suppliers/view/' . (int)$supplier->id);
            return;
        }
        $this->Supplier_model->add_contact(array(
            'supplier_id' => (int)$supplier->id,
            'name'        => $name,
            'role'        => trim((string)$this->input->post('role', TRUE)),
            'phone'       => trim((string)$this->input->post('phone', TRUE)),
            'email'       => trim((string)$this->input->post('email', TRUE)),
        ));
        $this->session->set_flashdata('success', 'Contact added.');
        redirect('suppliers/view/' . (int)$supplier->id);
    }

    public function delete_contact($id, $supplier_id = NULL) {
        if (!$this->require_post()) return;
        if (!$this->can_write()) {
            show_error('Forbidden.', 403);
            return;
        }
        if ($supplier_id === NULL) show_404();
        if (!$this->Supplier_model->delete_contact((int)$id, (int)$supplier_id)) show_404();
        $this->session->set_flashdata('success', 'Contact removed.');
        redirect('suppliers/view/' . (int)$supplier_id);
    }

    /* ── CATALOG (supplier ↔ material mapping) ──────────── */
    public function add_catalog_item($supplier_id) {
        if (!$this->require_post()) return;
        if (!$this->can_write()) {
            show_error('Forbidden.', 403);
            return;
        }
        $supplier = $this->Supplier_model->get_by_id((int)$supplier_id);
        if (!$supplier) show_404();

        $part_number = trim((string)$this->input->post('part_number', TRUE));
        $description = trim((string)$this->input->post('description', TRUE));
        if ($part_number === '' && $description === '') {
            $this->session->set_flashdata('error', 'Part number or description is required.');
            redirect('suppliers/view/' . (int)$supplier->id);
            return;
        }
        $matIdRaw = trim((string)$this->input->post('material_id', TRUE));
        $priceRaw = trim((string)$this->input->post('unit_price', TRUE));
        $moqRaw   = trim((string)$this->input->post('moq', TRUE));
        $leadRaw  = trim((string)$this->input->post('lead_time_days', TRUE));

        $this->Supplier_model->add_catalog_item(array(
            'supplier_id'    => (int)$supplier->id,
            'material_id'    => ($matIdRaw !== '' && (int)$matIdRaw > 0) ? (int)$matIdRaw : NULL,
            'part_number'    => $part_number,
            'description'    => $description !== '' ? $description : $part_number,
            'unit_price'     => ($priceRaw !== '' && is_numeric($priceRaw)) ? max(0.0, (float)$priceRaw) : NULL,
            'currency'       => wms_currency_code($this->input->post('currency', TRUE)),
            'moq'            => ($moqRaw !== '' && is_numeric($moqRaw)) ? max(0.0, (float)$moqRaw) : NULL,
            'lead_time_days' => ($leadRaw !== '' && is_numeric($leadRaw)) ? max(0, (int)$leadRaw) : NULL,
            'is_preferred'   => $this->input->post('is_preferred', TRUE) ? 1 : 0,
        ));
        $this->session->set_flashdata('success', 'Catalog item added.');
        redirect('suppliers/view/' . (int)$supplier->id);
    }

    public function delete_catalog_item($id, $supplier_id = NULL) {
        if (!$this->require_post()) return;
        if (!$this->can_write()) {
            show_error('Forbidden.', 403);
            return;
        }
        if ($supplier_id === NULL) show_404();
        if (!$this->Supplier_model->delete_catalog_item((int)$id, (int)$supplier_id)) show_404();
        $this->session->set_flashdata('success', 'Catalog item removed.');
        redirect('suppliers/view/' . (int)$supplier_id);
    }

    /* ── "Who supplies this part?" lookup ───────────────── */
    public function find_part() {
        $q = trim((string)$this->input->get('q', TRUE));
        $data = array(
            'q'          => $q,
            'results'    => $q !== '' ? $this->Supplier_model->find_suppliers_for_part($q) : array(),
            'can_write'  => $this->can_write(),
            'page_title' => 'Find Supplier for Part',
        );
        $this->render('suppliers/find_part', $data);
    }

    public function delete($id) {
        if (!$this->require_post()) return;
        if (!$this->can_write()) {
            show_error('Forbidden.', 403);
            return;
        }
        $supplier = $this->Supplier_model->get_by_id((int)$id);
        if (!$supplier) show_404();

        $orderCnt = $this->Supplier_model->count_linked_orders((int)$supplier->id);
        if ($orderCnt > 0) {
            $this->session->set_flashdata(
                'error',
                'Cannot delete supplier with ' . $orderCnt . ' linked purchase order(s). Set status to Inactive or Blacklisted instead.'
            );
            redirect('suppliers/view/' . (int)$supplier->id);
            return;
        }

        $this->Supplier_model->delete((int)$supplier->id);
        $this->session->set_flashdata('success', 'Supplier deleted.');
        redirect('suppliers');
    }
}
