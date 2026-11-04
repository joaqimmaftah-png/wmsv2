// Dependency-free structural checks for the Phase 0 Reports safety batches.
// These complement PHP/MySQL integration, generated-workbook and browser tests.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.join(__dirname, '..');
const source = file => fs.readFileSync(path.join(root, file), 'utf8');
const store = source('application/models/Store_model.php');
const dashboard = source('application/models/Dashboard_model.php');
const reports = source('application/controllers/Reports.php');
const issue = source('application/views/reports/issue_register.php');
const receipt = source('application/views/reports/receipt_register.php');
const reliability = source('application/views/reports/mttr_mtbf.php');
const annual = source('application/views/reports/annual_report.php');

function methodBody(src, name, nextName) {
  const start = src.indexOf(`function ${name}(`);
  assert.ok(start >= 0, `${name} exists`);
  const end = nextName ? src.indexOf(`function ${nextName}(`, start + 1) : src.length;
  assert.ok(end > start, `${name} has a bounded body`);
  return src.slice(start, end);
}

/* H-04: the visible Issue action reaches item selection and remains role-safe. */
assert.ok(issue.includes("base_url('materials/inventory')"),
  'Issue report links to the existing inventory item-selection workflow');
assert.ok(!issue.includes("base_url('materials/issue')"),
  'Issue report never calls the item-required route without an ID');
assert.ok(issue.includes("!empty($can_issue_stock)"),
  'unauthorised read-only users do not see the Issue action');
assert.ok(issue.includes('Select item to issue'),
  'the action explains that an item must be selected');
assert.ok(reports.includes("'can_issue_stock' => $this->can_write()"),
  'controller derives action visibility from existing write permissions');

/* H-06: rows, KPI totals and allocation analytics share every filter. */
const applyFilter = methodBody(store, '_apply_filter', '_base');
for (const key of ['date_from', 'date_to', 'category', 'item_code', 'equipment_id',
                   'allocation', 'supplier', 'reference', 'person', 'search']) {
  assert.ok(applyFilter.includes(`$filter['${key}']`), `shared filter handles ${key}`);
}
assert.ok(applyFilter.includes('m.part_number_size'),
  'shared free-text search includes part numbers');
assert.ok(methodBody(store, '_base', 'issue_register').includes('$this->_apply_filter($filter, $d)'),
  'register rows use the shared filter');
const totals = methodBody(store, 'register_totals', 'consumption_by_allocation');
assert.ok(totals.includes('$this->_apply_filter($filter, $d2)'),
  'headline totals use the shared filter');
assert.ok(!totals.includes("OR m.description LIKE '%{$s}%')"),
  'totals no longer maintain a narrower duplicate search');
const allocations = methodBody(store, 'consumption_by_allocation', 'allocations');
assert.ok(allocations.includes("join('materials m'"),
  'allocation analytics join the fields needed by the shared filter');
assert.ok(allocations.includes('$this->_apply_filter($filter, $d)'),
  'allocation analytics use the shared filter');

/* H-06: both register screens page the complete filtered set; XLSX remains capped. */
const storeIssue = methodBody(store, 'issue_register', 'receipt_register');
const storeReceipt = methodBody(store, 'receipt_register', 'register_totals');
for (const [name, body] of [['Issue', storeIssue], ['Receipt', storeReceipt]]) {
  assert.ok(body.includes('$limit = self::MAX_ROWS, $offset = 0'),
    `${name} model query defaults to the existing export cap`);
  assert.ok(body.includes("order_by($d, 'DESC', FALSE)->order_by('mt.id', 'DESC')"),
    `${name} pages retain stable descending date/ID order`);
  assert.ok(body.includes('$this->db->limit((int)$limit, max(0, (int)$offset))'),
    `${name} query applies a bounded page limit and offset`);
}
const registerPagination = methodBody(reports, '_store_register_pagination', '_valid_date');
for (const marker of ["$this->input->get('page')", "$this->input->get('per_page')",
                      'ctype_digit((string)$pageRaw)', 'array(10, 25, 50, 100)',
                      'in_array($perPage, $allowed, TRUE)', 'ceil($total / $perPage)',
                      'min(max(1, $page), $totalPages)', '$offset = ($page - 1) * $perPage',
                      "'showing_from'", "'showing_to'"]) {
  assert.ok(registerPagination.includes(marker), `register pagination validates and calculates ${marker}`);
}
for (const [name, screen, exportBody, totalsType, query] of [
  ['Issue', methodBody(reports, 'issue_register', 'export_issue_register_xlsx'),
    methodBody(reports, 'export_issue_register_xlsx', 'receipt_register'), 'ISSUE', 'issue_register'],
  ['Receipt', methodBody(reports, 'receipt_register', 'export_receipt_register_xlsx'),
    methodBody(reports, 'export_receipt_register_xlsx', '_export_register_xlsx'), 'RECEIVE', 'receipt_register'],
]) {
  assert.ok(screen.includes(`register_totals('${totalsType}', $filter)`) &&
            screen.includes("_store_register_pagination($totals['lines'])"),
    `${name} screen derives page count from the full filtered line total`);
  assert.ok(screen.includes(`$this->Store_model->${query}($filter, $pagination['per_page'], $pagination['offset'])`),
    `${name} screen requests only the selected page`);
  assert.ok(exportBody.includes(`$this->Store_model->${query}($filter)`) &&
            !exportBody.includes('_store_register_pagination'),
    `${name} XLSX export ignores page parameters and keeps the default model cap`);
}

/* Mixed stock units and currencies are never presented as scalar totals. */
for (const key of ['qty_by_unit', 'qty_approved_by_unit', 'value_by_currency']) {
  assert.ok(totals.includes(`'${key}'`), `register totals expose ${key}`);
}
assert.ok(totals.includes("group_by('m.unit')") && totals.includes("group_by('mt.currency')"),
  'database aggregates are grouped by their measurement/currency dimension');
assert.ok(totals.includes("count($qtyByUnit) === 1 ? reset($qtyByUnit) : null") &&
          totals.includes("count($valueByCurrency) === 1 ? reset($valueByCurrency) : null"),
          'legacy scalar totals are null unless exactly one dimension exists');
assert.ok(totals.includes('array_key_exists($unit, $qtyByUnit)') &&
          totals.includes('(float)$qtyByUnit[$unit] + (float)$unitRow->qty'),
          'NULL and empty raw units merge after normalisation without overwriting quantities');
assert.ok(allocations.includes('mt.allocation_type, m.unit') &&
          allocations.includes("group_by('allocation, mt.allocation_type, m.unit')") &&
          allocations.includes("order_by('lines', 'DESC')"),
          'allocation rows retain units and rank by movement lines');
assert.ok(!allocations.includes('SUM(mt.qty * COALESCE(mt.unit_price,0))'),
  'allocation analytics do not expose an unqualified mixed-currency value');
assert.ok(issue.includes('$qtyByUnit') && issue.includes('grouped by unit') &&
          issue.includes('<th>Unit</th>'),
          'Issue KPI and allocation table display unit-qualified quantities');
assert.ok(!issue.includes("$t['qty']") && !receipt.includes("$t['qty']"),
  'register screens do not render a scalar quantity across mixed units');
assert.ok(receipt.includes('$valueByCurrency') && receipt.includes('grouped by currency') &&
          receipt.includes('wms_money($value, $currency, 2)') &&
          receipt.includes('wms_money($x->unit_price, $x->currency, 2)'),
          'Receipt totals and unit prices retain their currency codes');
assert.ok(!receipt.includes("$t['value']"),
  'Receipt screen does not render a scalar value across mixed currencies');

/* Capped results are identified and never given misleading footer totals. */
assert.ok(reports.includes("register_totals('ISSUE', $filter)") &&
          reports.includes("register_totals('RECEIVE', $filter)"),
          'both exports obtain full filtered count and grouped summaries');
assert.ok(reports.includes("_export_register_xlsx('ISSUE', $rows, $filter, $totals)") &&
          reports.includes("_export_register_xlsx('RECEIVE', $rows, $filter, $totals)"),
          'both export callers pass the complete summary contract');
const registerExport = methodBody(reports, '_export_register_xlsx', '_col_letter');
assert.ok(registerExport.includes('function _export_register_xlsx($type, $rows, $filter, $summary = null)'),
  'export writer receives full-scope summary metadata');
for (const marker of ['$truncated', 'RESULT LIMITED:', "'equipment_id' => 'Equipment ID'",
                      'RESULT LIMITED — NO OVERALL TOTALS', 'TOTAL BY UNIT', 'VALUE BY CURRENCY']) {
  assert.ok(registerExport.includes(marker), `export safety metadata includes ${marker}`);
}
assert.ok(!registerExport.includes('TOTAL (DOWNLOADED ROWS)'),
  'capped workbooks no longer label a partial or full-scope total as a downloaded-row total');
const cappedMarker = registerExport.indexOf('// A capped workbook');
const cappedEnd = registerExport.indexOf('} elseif ($isIssue)', cappedMarker);
assert.ok(cappedMarker >= 0 && cappedEnd > cappedMarker, 'capped footer branch is bounded');
const cappedFooter = registerExport.slice(cappedMarker, cappedEnd);
assert.ok(!cappedFooter.includes('=SUM(') && !cappedFooter.includes('TOTAL BY UNIT') &&
          !cappedFooter.includes('VALUE BY CURRENCY'),
          'capped footer suppresses all overall totals');
const completeFooter = registerExport.slice(cappedEnd);
assert.ok(!completeFooter.includes('=SUM('),
  'unit/currency footer summaries use grouped values, not cross-dimension SUM formulas');
assert.ok(registerExport.includes("array('Unit Price', 14), array('Currency', 10), array('Line Value', 14), array('Supplier', 28)"),
  'Receipt workbook has Currency before Line Value and Supplier');
assert.ok(registerExport.includes("wms_currency_code($x->currency)") &&
          registerExport.includes("'=I'.$r.'*J'.$r"),
  'Receipt rows preserve currency and calculate delivered quantity times unit price');
assert.ok(registerExport.includes("$tot[10] = array('v' => (string)$currency") &&
          registerExport.includes("$tot[11] = array('v' => (float)$value"),
          'Receipt currency/value footer uses K/L after the Currency column was inserted');
assert.ok(!registerExport.includes('=SUM(K'),
  'obsolete pre-Currency line-value footer formula is gone');
for (const [name, view, exportRoute, pageSizeId] of [
  ['issue', issue, 'export_issue_register_xlsx', 'issuePerPage'],
  ['receipt', receipt, 'export_receipt_register_xlsx', 'receiptPerPage'],
]) {
  assert.ok(view.includes("$exportTruncated = (int)$t['lines'] > $limit"),
    `${name} separately detects when the XLSX cap truncates the full filter`);
  assert.ok(view.includes('Excel includes only the latest') && view.includes('Narrow the filters'),
    `${name} discloses the capped XLSX scope`);
  assert.ok(view.includes('isset($row_limit)'), `${name} consumes the model-owned export cap`);
  assert.ok(view.includes('Showing <?= number_format($showingFrom) ?>–<?= number_format($showingTo) ?> of <?= number_format((int)$t[\'lines\']) ?> matching lines'),
    `${name} reports the current page range against the full filtered count`);
  assert.ok(view.includes("$pageUrl = function ($targetPage) use ($qs, $perPage)") &&
            view.includes("$params['per_page'] = $perPage") &&
            view.includes("$params['page'] = (int)$targetPage"),
    `${name} page links preserve filters and page size`);
  assert.ok(view.includes(`id=\"${pageSizeId}\" name=\"per_page\"`) &&
            view.includes('Rows per page') && view.includes('array(10, 25, 50, 100)'),
    `${name} exposes the allowed page sizes`);
  assert.ok(view.includes('name=\"per_page\" value=\"<?= $perPage ?>\"') &&
            view.includes('foreach ($qs as $key => $value)') &&
            view.includes('htmlspecialchars((string)$key, ENT_QUOTES, \'UTF-8\')'),
    `${name} filter and page-size forms preserve their non-pagination GET state`);
  assert.ok(view.includes('aria-label=') && view.includes('aria-current=\"page\"'),
    `${name} pagination controls have accessible labels and current-page state`);
  assert.ok(view.includes(`reports/${exportRoute}?'.http_build_query($qs)`),
    `${name} export URL uses filters only and excludes pagination state`);
}

/* H-09: undefined detailed reliability measures stay null all the way to the view. */
const trend = methodBody(dashboard, 'get_mttr_mtbf_trend', 'get_mttr_mtbf_report');
assert.ok(trend.includes("$m['mttr'] === null ? null") &&
          trend.includes("$m['mtbf'] === null ? null"),
          'monthly reliability trend preserves null');
const detail = methodBody(dashboard, 'get_mttr_mtbf_report', 'get_availability_forecast');
for (const marker of ["$k['mttr_hours'] === null ? null", "$k['mtbf_hours'] === null ? null",
                      "$fleet['mttr_hours'] === null ? null", "$fleet['mtbf_hours'] === null ? null"]) {
  assert.ok(detail.includes(marker), `detail reliability preserves ${marker}`);
}
assert.ok(reliability.includes("return $value === null ? '—'"),
  'view formats undefined hours as a dash');
assert.ok(reliability.includes('No qualifying breakdowns in this window'),
  'summary explains why the value is unavailable');
assert.ok(reliability.includes("$eq['mttr'] !== null && $summary['avg_mttr'] !== null"),
  'per-equipment comparison never coerces null to zero');
assert.ok(reliability.includes('spanGaps:false'),
  'charts leave undefined periods as gaps');
assert.ok(reliability.includes("typeof Chart === 'undefined'"),
  'missing chart dependency does not break the report script');
assert.ok(!reliability.includes('var avgMttr'),
  'unused JavaScript no longer emits an unguarded nullable PHP value');

/* Annual outputs use the same null, unit and configured-currency contracts. */
const annualModel = methodBody(dashboard, 'get_annual_report', 'get_annual_plan');
for (const marker of ["$kpi['fleet']['mttr_hours'] === null ? null",
                      "$kpi['fleet']['mtbf_hours'] === null ? null",
                      "$k['mttr_hours'] === null ? null",
                      "$k['mtbf_hours'] === null ? null",
                      '$total_bd>0 ? round($total_dt/$total_bd,1) : null']) {
  assert.ok(annualModel.includes(marker), `Annual reliability preserves ${marker}`);
}
assert.ok(annualModel.includes("array_column($monthly, 'availability')") &&
          annualModel.includes('$avg_avail = count($defined_availability) > 0') &&
          annualModel.includes('array_sum($defined_availability) / count($defined_availability)'),
          'Annual availability retains its unweighted mean over months with measured values');
assert.ok(annualModel.includes("join('lube_types lt'") &&
          annualModel.includes('SUM(lc.quantity_used) AS total') &&
          annualModel.includes("group_by('lt.unit')") &&
          annualModel.includes("'lube_by_unit' => $lubeByUnit"),
          'Annual monthly lubricant quantities are grouped by their master-data unit');
assert.ok(annualModel.includes("count($lubeByUnit) === 1 ? reset($lubeByUnit) : null"),
  'legacy scalar monthly lubricant value is null for zero or mixed units');
assert.ok(!annualModel.includes("select_sum('quantity_used'"),
  'Annual no longer adds all lubricant quantities in one unitless query');
assert.ok(annualModel.includes("if ($a['availability'] === null) return 1") &&
          annualModel.includes("if ($b['availability'] === null) return -1"),
  'unknown equipment availability does not sort as a measured zero');
for (const marker of ["$m['mttr'] === null ? '—'", "$m['mtbf'] === null ? '—'",
                      "$t['avg_mttr'] === null ? '—'", "$m['lube_by_unit']",
                      'Mean Monthly Avail.', 'Unweighted mean of measured months; not a full-year required-time rollup',
                      'Mean of measured months',
                      'wms_site_currency()', 'wms_money($t[\'parts_spend\'],$siteCurrency,0)',
                      'Configured site currency · existing WO cost basis']) {
  assert.ok(annual.includes(marker), `Annual view includes ${marker}`);
}
assert.ok(!annual.includes('LUBE (L)') && !annual.includes("['Parts Spend',    '$'"),
  'Annual view removes the assumed litre unit and hard-coded dollar label');
assert.ok(annual.includes('return $eq[\'availability\'] !== null;') &&
          annual.includes('No measured availability for'),
          'unknown availability is excluded from the measured worst-performer ranking');
const annualExport = methodBody(reports, '_export_annual_report_xlsx', '_export_annual_plan_xlsx');
assert.ok(annualExport.includes("'MTBF (h)','LUBRICANT BY UNIT'") &&
          annualExport.includes("$xlsx->mergeCells('A1','J1')") &&
          annualExport.includes("$row['lube_by_unit']"),
          'Annual workbook adds a unit-qualified monthly lubricant column');
for (const marker of ["$row['mttr'] === null ? null", "$row['mtbf'] === null ? null",
                      "$eq['mttr'] === null ? null", "$eq['mtbf'] === null ? null",
                      "$summaryRow[1] === null ? null"]) {
  assert.ok(annualExport.includes(marker), `Annual workbook preserves blank ${marker}`);
}
assert.ok(annualExport.includes("$xlsx->addSheet('Annual Summary')") &&
          annualExport.includes("array('Average MTTR (h)', $totals['avg_mttr']") &&
          annualExport.includes("array('Unweighted mean monthly availability (%)', $totals['avg_avail']") &&
          annualExport.includes("array('Availability target (%)', $targetPct") &&
          annualExport.includes('not a full-year required-time rollup'),
          'Annual workbook summary labels its monthly mean, discloses the target and preserves nullable reliability outputs');
assert.ok(annualExport.includes('$av >= $targetFraction') &&
          annualExport.includes('$av >= $watchFraction') &&
          annualExport.includes('$targetPct = isset($data[\'target\'])') &&
          annualExport.includes('$watchPct = 70.0') &&
          annualExport.includes('>= 0.9') === false && annualExport.includes('>=0.9') === false &&
          annualExport.includes('>= 0.7') === false && annualExport.includes('>=0.7') === false,
          'Annual XLSX availability colours use the screen target/watch policy, not fixed 90/70 performance cutoffs');
assert.ok(annualExport.includes("'Parts spend ('.$siteCurrency.')'") &&
          annualExport.includes('Configured site currency; existing WO spare-part cost basis; no conversion'),
          'Annual workbook labels the configured site currency without converting values');

/* Parse the actual inline reliability JavaScript after substituting PHP data. */
const script = reliability.match(/<script>([\s\S]*?)<\/script>/)[1]
  .replace(/<\?=json_encode\(\$trendLabels\)\?>/g, '[]')
  .replace(/<\?=json_encode\(\$trendMttr\)\?>/g, '[]')
  .replace(/<\?=json_encode\(\$trendMtbf\)\?>/g, '[]');
assert.ok(!script.includes('<?'), 'all PHP literals in reliability script are covered by the parse check');
new Function(script); // eslint-disable-line no-new-func

console.log('Reports safety structural checks passed.');
