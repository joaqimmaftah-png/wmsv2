<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Inventory Dashboard (/materials).
 *
 * Layout follows the workflow, not the field order:
 *   context → KPIs → exceptions → analysis → records.
 *
 * Built on the SAME row set and derived columns as the Inventory list
 * (get_catalogue_overview()), so "Low / Out" here always agrees with the
 * list. No decorative charts, no invented data.
 */

$h = function ($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
};

$can_write = isset($can_write) ? (bool)$can_write : in_array((int)$role_id, array(1, 2, 3), TRUE);

$kpi = isset($kpi) && is_array($kpi) ? $kpi : array(
    'items' => 0, 'in_stock' => 0, 'low' => 0, 'out' => 0, 'units' => 0,
    'received' => 0, 'issued' => 0, 'reorder_units' => 0, 'reorder_items' => 0,
    'valued_items' => 0, 'stock_value' => 0.0, 'movers' => 0, 'dormant' => 0,
);
$by_category    = isset($by_category) && is_array($by_category) ? $by_category : array();
$low_items      = isset($low_items) && is_array($low_items) ? $low_items : array();
$out_items      = isset($out_items) && is_array($out_items) ? $out_items : array();
$reorder_items  = isset($reorder_items) && is_array($reorder_items) ? $reorder_items : array();
$movers         = isset($movers) && is_array($movers) ? $movers : array();
$recent         = isset($recent) && is_array($recent) ? $recent : array();
$catalogue_rows = isset($catalogue_rows) ? (int)$catalogue_rows : (int)$kpi['items'];
$available      = !isset($available) ? TRUE : (bool)$available;

// Most severe first: out of stock before low stock.
$attention = array_merge($out_items, $low_items);
$attention = array_slice($attention, 0, 8);

$inv_url = function ($params = array(), $endpoint = 'materials/inventory') {
    $qs = http_build_query($params);
    return base_url($endpoint) . ($qs !== '' ? '?' . $qs : '');
};
?>

<style>
  /* Same token set as the Inventory list, so the whole module speaks one
     visual language. Scoped to #invShell — nothing leaks to other pages. */
  #invShell {
    --inv-bg: #F8FAFC;
    --inv-surface: #FFFFFF;
    --inv-border: #E2E8F0;
    --inv-border-strong: #CBD5E1;
    --inv-text: #0F172A;
    --inv-muted: #475569;
    --inv-subtle: #64748B;
    --inv-accent: #1D4ED8;
    --inv-accent-soft: #EFF6FF;
    --inv-ok-bg: #ECFDF5;
    --inv-ok-fg: #047857;
    --inv-warn-bg: #FFFBEB;
    --inv-warn-fg: #B45309;
    --inv-crit-bg: #FEF2F2;
    --inv-crit-fg: #B91C1C;
    color: var(--inv-text);
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    max-width: 100%;
  }
  #invShell * { box-sizing: border-box; }

  .inv-head {
    display: flex; align-items: flex-start; justify-content: space-between;
    gap: 14px; flex-wrap: wrap; margin-bottom: 16px;
  }
  .inv-title { font-size: 20px; font-weight: 700; letter-spacing: -0.02em; margin: 0; }
  .inv-subtitle { font-size: 12.5px; color: var(--inv-subtle); margin-top: 3px; }
  .inv-actions { display: flex; align-items: center; gap: 7px; flex-wrap: wrap; }

  .inv-btn {
    display: inline-flex; align-items: center; justify-content: center; gap: 6px;
    height: 34px; padding: 0 12px; border-radius: 8px;
    border: 1px solid var(--inv-border-strong); background: var(--inv-surface);
    color: var(--inv-text); font-size: 12.5px; font-weight: 600;
    text-decoration: none; cursor: pointer; white-space: nowrap;
    transition: background .14s ease, border-color .14s ease, color .14s ease;
  }
  .inv-btn:hover { background: #F1F5F9; border-color: #94A3B8; }
  .inv-btn-primary { background: var(--inv-accent); border-color: var(--inv-accent); color: #FFFFFF; }
  .inv-btn-primary:hover { background: #1E40AF; border-color: #1E40AF; color: #FFFFFF; }
  .inv-btn-in { background: var(--inv-ok-bg); border-color: #A7F3D0; color: var(--inv-ok-fg); }
  .inv-btn-in:hover { background: #D1FAE5; color: #065F46; }
  .inv-btn-sm { height: 27px; padding: 0 8px; font-size: 11.5px; border-radius: 7px; }
  .inv-btn svg { width: 14px; height: 14px; stroke: currentColor; fill: none; stroke-width: 1.7; stroke-linecap: round; stroke-linejoin: round; }

  /* KPI strip — 5 ranked, clickable cards */
  .inv-kpi-grid {
    display: grid; grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 12px; margin-bottom: 16px;
  }
  .inv-kpi-card {
    background: var(--inv-surface); border: 1px solid var(--inv-border);
    border-radius: 12px; padding: 14px 16px;
    display: flex; flex-direction: column; gap: 4px; text-decoration: none;
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.03);
    transition: border-color .14s ease, box-shadow .14s ease, transform .14s ease;
  }
  .inv-kpi-card:hover { border-color: var(--inv-border-strong); box-shadow: 0 4px 12px rgba(15, 23, 42, 0.06); transform: translateY(-1px); }
  .inv-kpi-lbl { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: var(--inv-subtle); }
  .inv-kpi-val { font-size: 25px; font-weight: 700; letter-spacing: -0.02em; font-variant-numeric: tabular-nums; line-height: 1.15; }
  .inv-kpi-meta { font-size: 11.5px; color: var(--inv-muted); font-variant-numeric: tabular-nums; }
  .inv-kpi-val.is-crit { color: var(--inv-crit-fg); }
  .inv-kpi-val.is-warn { color: var(--inv-warn-fg); }
  .inv-kpi-val.is-accent { color: var(--inv-accent); }
  .inv-kpi-val.is-sm { font-size: 17px; letter-spacing: 0; }

  /* Visible keyboard focus for every interactive control */
  .inv-kpi-card:focus-visible,
  .inv-btn:focus-visible,
  .inv-card-link:focus-visible { outline: 2px solid var(--inv-accent); outline-offset: 2px; }

  /* Surfaces */
  .inv-surface {
    background: var(--inv-surface); border: 1px solid var(--inv-border);
    border-radius: 12px; overflow: hidden;
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);
  }
  .inv-surface + .inv-surface { margin-top: 14px; }
  .inv-card-head {
    display: flex; align-items: flex-start; justify-content: space-between;
    gap: 12px; padding: 14px 16px 0;
  }
  .inv-card-title { font-size: 13px; font-weight: 700; color: var(--inv-text); }
  .inv-card-sub { font-size: 11.5px; color: var(--inv-subtle); margin-top: 2px; }
  .inv-card-link { font-size: 11.5px; font-weight: 600; color: var(--inv-accent); text-decoration: none; white-space: nowrap; }
  .inv-card-link:hover { text-decoration: underline; }

  .inv-list-row {
    display: flex; align-items: center; gap: 12px;
    padding: 11px 16px; border-bottom: 1px solid var(--inv-border);
  }
  .inv-list-row:last-child { border-bottom: 0; }
  .inv-dot { width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0; }
  .inv-dot.crit { background: var(--inv-crit-fg); }
  .inv-dot.warn { background: var(--inv-warn-fg); }
  .inv-dot.ok   { background: var(--inv-ok-fg); }
  .inv-cell-main { font-weight: 600; color: var(--inv-text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .inv-cell-sub { font-size: 11.5px; color: var(--inv-subtle); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 1px; }
  .inv-mono { font-variant-numeric: tabular-nums; letter-spacing: -0.3px; }

  .inv-badge {
    display: inline-flex; align-items: center; gap: 4px; padding: 2px 7px;
    border-radius: 999px; font-size: 11px; font-weight: 700; white-space: nowrap;
    border: 1px solid transparent; font-variant-numeric: tabular-nums;
  }
  .inv-badge-crit    { background: var(--inv-crit-bg); color: var(--inv-crit-fg); border-color: #FECACA; }
  .inv-badge-warn    { background: var(--inv-warn-bg); color: var(--inv-warn-fg); border-color: #FDE68A; }
  .inv-badge-ok      { background: var(--inv-ok-bg);   color: var(--inv-ok-fg);   border-color: #A7F3D0; }
  .inv-badge-neutral { background: #F1F5F9; color: #334155; border-color: #CBD5E1; }

  .inv-bar { width: 100%; height: 4px; background: #EEF2F7; border-radius: 3px; overflow: hidden; margin-top: 6px; }
  .inv-bar-fill { height: 100%; background: var(--inv-accent); border-radius: 3px; }

  .inv-table { width: 100%; border-collapse: collapse; }
  .inv-table thead th {
    background: #F8FAFC; border-bottom: 1px solid var(--inv-border);
    padding: 9px 16px; text-align: left; font-size: 11px; font-weight: 700;
    text-transform: uppercase; letter-spacing: .04em; color: var(--inv-subtle); white-space: nowrap;
  }
  .inv-table tbody td { padding: 11px 16px; vertical-align: middle; font-size: 13px; color: var(--inv-text); border-bottom: 1px solid var(--inv-border); }
  .inv-table tbody tr:last-child td { border-bottom: 0; }
  .inv-table tbody tr:hover td { background: #F8FAFC; }
  .inv-table td.num { text-align: right; font-variant-numeric: tabular-nums; }

  .inv-empty {
    padding: 34px 18px; text-align: center; color: var(--inv-muted);
    border-bottom: 0 !important;
  }
  .inv-empty-title { font-size: 13.5px; font-weight: 700; color: var(--inv-text); margin-bottom: 4px; }
  .inv-empty-sub { font-size: 12px; margin-bottom: 12px; }

  .inv-section-label {
    font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em;
    color: var(--inv-subtle); margin: 0 0 10px;
  }
  .inv-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; align-items: start; }
  .inv-grid-2 .inv-col-main { min-width: 0; }

  @media (max-width: 1080px) {
    .inv-kpi-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
  }
  @media (max-width: 820px) {
    .inv-grid-2 { grid-template-columns: 1fr; }
  }
  @media (max-width: 640px) {
    .inv-kpi-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  }
  @media print {
    .inv-actions, .inv-card-link { display: none !important; }
    #invShell { color: #000; }
  }
</style>

<div id="invShell">

  <!-- ── Page identity ───────────────────────────────────── -->
  <div class="inv-head">
    <div>
      <h1 class="inv-title">Inventory Dashboard</h1>
      <div class="inv-subtitle">All-time cumulative · <?= number_format($catalogue_rows) ?> catalogue items · warehouse-wide</div>
    </div>
    <div class="inv-actions">
      <a href="<?= $h($inv_url(array(), 'materials/inventory')) ?>" class="inv-btn">View Inventory</a>
      <a href="<?= $h($inv_url(array(), 'materials/transactions')) ?>" class="inv-btn">Transactions</a>
      <a href="<?= $h($inv_url(array(), 'materials/export_xlsx')) ?>" class="inv-btn">Export Excel</a>
      <a href="<?= $h($inv_url(array(), 'materials/template_xlsx')) ?>" class="inv-btn">Template</a>
      <?php if ($can_write): ?>
      <a href="<?= $h($inv_url(array(), 'materials/inventory#import')) ?>" class="inv-btn">Import</a>
      <a href="<?= $h($inv_url(array(), 'materials/create')) ?>" class="inv-btn inv-btn-primary">+ Add Item</a>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── KPI strip ──────────────────────────────────────── -->
  <div class="inv-kpi-grid">
    <a class="inv-kpi-card" href="<?= $h($inv_url(array())) ?>">
      <span class="inv-kpi-lbl">Catalogue Items</span>
      <span class="inv-kpi-val"><?= number_format((int)$kpi['items']) ?></span>
      <span class="inv-kpi-meta"><?= number_format((int)$kpi['in_stock']) ?> healthy in stock</span>
    </a>
    <a class="inv-kpi-card" href="<?= $h($inv_url(array())) ?>">
      <span class="inv-kpi-lbl">Units On Hand</span>
      <span class="inv-kpi-val is-accent"><?= number_format((int)$kpi['units']) ?></span>
      <span class="inv-kpi-meta">+<?= number_format((int)$kpi['received']) ?> in · −<?= number_format((int)$kpi['issued']) ?> out</span>
    </a>
    <a class="inv-kpi-card" href="<?= $h($inv_url(array('stock' => 'low'))) ?>">
      <span class="inv-kpi-lbl">Low / Reorder</span>
      <span class="inv-kpi-val <?= (int)$kpi['low'] > 0 ? 'is-warn' : '' ?>"><?= number_format((int)$kpi['low']) ?></span>
      <span class="inv-kpi-meta">At or below min · <?= number_format((int)$kpi['reorder_units']) ?> to order</span>
    </a>
    <a class="inv-kpi-card" href="<?= $h($inv_url(array('stock' => 'out'))) ?>">
      <span class="inv-kpi-lbl">Out of Stock</span>
      <span class="inv-kpi-val <?= (int)$kpi['out'] > 0 ? 'is-crit' : '' ?>"><?= number_format((int)$kpi['out']) ?></span>
      <span class="inv-kpi-meta">Balance at zero</span>
    </a>
    <a class="inv-kpi-card" href="<?= $h($inv_url(array('tab' => 'valued'))) ?>">
      <span class="inv-kpi-lbl">Stock Value</span>
      <span class="inv-kpi-val<?= $kpi['valued_items'] > 0 ? ' is-sm' : '' ?>"><?= $kpi['valued_items'] > 0 ? $h(wms_money($kpi['stock_value'])) : '—' ?></span>
      <span class="inv-kpi-meta"><?= number_format((int)$kpi['valued_items']) ?> priced items</span>
    </a>
  </div>

  <?php if (!$available || $catalogue_rows === 0): ?>
    <div class="inv-surface">
      <div class="inv-empty">
        <?php if (!$available): ?>
          <div class="inv-empty-title">Inventory data unavailable</div>
          <div class="inv-empty-sub">The inventory table could not be read. Check the database connection.</div>
        <?php else: ?>
          <div class="inv-empty-title">No inventory items yet</div>
          <div class="inv-empty-sub">The catalogue is empty — add items manually or import a workbook.</div>
          <?php if ($can_write): ?>
          <a class="inv-btn inv-btn-primary" href="<?= $h($inv_url(array(), 'materials/create')) ?>">+ Add Item</a>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
    <?php return; ?>
  <?php endif; ?>

  <!-- ── Exceptions + reorder load ───────────────────────── -->
  <div class="inv-grid-2">

    <div class="inv-surface inv-col-main">
      <div class="inv-card-head">
        <div>
          <div class="inv-card-title">Items Needing Attention</div>
          <div class="inv-card-sub">
            <?= number_format((int)$kpi['out']) ?> out · <?= number_format((int)$kpi['low']) ?> low · lowest balance first
          </div>
        </div>
        <a class="inv-card-link" href="<?= $h($inv_url(array('stock' => 'low'))) ?>">View all →</a>
      </div>

      <?php if (empty($attention)): ?>
        <div class="inv-empty">
          <div class="inv-empty-title">Every item is above its minimum</div>
          <div class="inv-empty-sub">No out-of-stock or low-stock items right now.</div>
        </div>
      <?php else: ?>
        <?php foreach ($attention as $m):
          $bal    = (int)$m->balance;
          $isOut  = $bal <= 0;
          $state  = $isOut ? 'crit' : 'warn';
          $stLbl  = $isOut ? 'Out' : 'Low';
          $min    = isset($m->effective_min) ? (int)$m->effective_min : 5;
          $toOrd  = isset($m->qty_to_order) ? (int)$m->qty_to_order : 0;
        ?>
        <div class="inv-list-row">
          <div class="inv-dot <?= $state ?>" aria-hidden="true"></div>
          <div style="flex:1;min-width:0;">
            <div class="inv-cell-main"><?= $h($m->item_code) ?> <span class="inv-badge inv-badge-<?= $state === 'crit' ? 'crit' : 'warn' ?>"><?= $stLbl ?></span></div>
            <div class="inv-cell-sub"><?= $h($m->description) ?> · min <?= $min ?><?= $toOrd > 0 ? ' · order ' . $toOrd : '' ?></div>
          </div>
          <div class="inv-mono" style="font-weight:700;color:var(--inv-text);white-space:nowrap;">
            <?= $bal ?> <span style="font-weight:500;font-size:11px;color:var(--inv-subtle);"><?= $h($m->unit ?: 'pcs') ?></span>
          </div>
          <?php if ($can_write): ?>
          <a class="inv-btn inv-btn-in inv-btn-sm" href="<?= $h($inv_url(array(), 'materials/receive/' . (int)$m->id)) ?>">Receive</a>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <div class="inv-surface">
      <div class="inv-card-head">
        <div>
          <div class="inv-card-title">Reorder Load</div>
          <div class="inv-card-sub">
            <?= number_format((int)$kpi['reorder_items']) ?> item(s) · <?= number_format((int)$kpi['reorder_units']) ?> units to order
          </div>
        </div>
        <a class="inv-card-link" href="<?= $h($inv_url(array('tab' => 'low'))) ?>">Reorder →</a>
      </div>

      <?php if (empty($reorder_items)): ?>
        <div class="inv-empty">
          <div class="inv-empty-title">Nothing to reorder</div>
          <div class="inv-empty-sub">All items are at or above their target.</div>
        </div>
      <?php else: ?>
        <?php foreach ($reorder_items as $m):
          $toOrd = isset($m->qty_to_order) ? (int)$m->qty_to_order : 0;
        ?>
        <div class="inv-list-row">
          <div style="flex:1;min-width:0;">
            <div class="inv-cell-main"><?= $h($m->item_code) ?></div>
            <div class="inv-cell-sub"><?= $h($m->description) ?></div>
          </div>
          <div class="inv-mono" style="font-weight:700;color:var(--inv-warn-fg);white-space:nowrap;"><?= $toOrd ?> <span style="font-weight:500;font-size:11px;color:var(--inv-subtle);"><?= $h($m->unit ?: 'pcs') ?></span></div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── Analysis: category + movement activity ─────────── -->
  <div class="inv-grid-2">

    <div class="inv-surface">
      <div class="inv-card-head">
        <div>
          <div class="inv-card-title">Stock by Category</div>
          <div class="inv-card-sub"><?= count($by_category) ?> categories · ranked by item count</div>
        </div>
        <a class="inv-card-link" href="<?= $h($inv_url(array())) ?>">Browse →</a>
      </div>

      <?php if (empty($by_category)): ?>
        <div class="inv-empty">
          <div class="inv-empty-title">No categories</div>
        </div>
      <?php else:
        $maxItems = 0;
        foreach ($by_category as $c) { if ($c['items'] > $maxItems) $maxItems = $c['items']; }
        foreach ($by_category as $cat => $d):
          $pct = $maxItems > 0 ? round($d['items'] / $maxItems * 100) : 0;
      ?>
        <div class="inv-list-row" style="align-items:flex-start;">
          <div style="flex:1;min-width:0;">
            <div style="display:flex;align-items:center;gap:8px;">
              <div class="inv-cell-main"><?= $h($cat) ?></div>
              <?php if ($d['low'] + $d['out'] > 0): ?>
                <span class="inv-badge inv-badge-warn"><?= $d['out'] ?> out · <?= $d['low'] ?> low</span>
              <?php endif; ?>
            </div>
            <div class="inv-cell-sub">
              <?= number_format((int)$d['items']) ?> items · <?= number_format((int)$d['units']) ?> units
              <?php if ($d['value'] > 0): ?> · <?= $h(wms_money($d['value'])) ?><?php endif; ?>
            </div>
            <div class="inv-bar"><div class="inv-bar-fill" style="width:<?= $pct ?>%;"></div></div>
          </div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <div class="inv-surface">
      <div class="inv-card-head">
        <div>
          <div class="inv-card-title">Movement Activity</div>
          <div class="inv-card-sub">
            <?= number_format((int)$kpi['movers']) ?> active · <?= number_format((int)$kpi['dormant']) ?> with no movements
          </div>
        </div>
        <a class="inv-card-link" href="<?= $h($inv_url(array('tab' => 'movers'))) ?>">Active →</a>
      </div>

      <?php if (empty($movers)): ?>
        <div class="inv-empty">
          <div class="inv-empty-title">No movement activity</div>
          <div class="inv-empty-sub">No item has recorded stock movements yet.</div>
        </div>
      <?php else: ?>
        <?php foreach ($movers as $m):
          $txn = isset($m->txn_count) ? (int)$m->txn_count : 0;
          $last = isset($m->last_movement_date) ? (string)$m->last_movement_date : '';
        ?>
        <div class="inv-list-row">
          <div style="flex:1;min-width:0;">
            <div class="inv-cell-main"><?= $h($m->item_code) ?></div>
            <div class="inv-cell-sub">
              <?= $h($m->description) ?>
              · <?= $txn ?> movement(s)<?= $last !== '' ? ' · last ' . $h($last) : '' ?>
            </div>
          </div>
          <div class="inv-mono" style="font-weight:700;color:var(--inv-accent);white-space:nowrap;"><?= $txn ?></div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── Records: recent movements ───────────────────────── -->
  <div class="inv-surface" style="margin-top:14px;">
    <div class="inv-card-head">
      <div>
        <div class="inv-card-title">Recent Movements</div>
        <div class="inv-card-sub">Latest stock transactions</div>
      </div>
      <a class="inv-card-link" href="<?= $h($inv_url(array(), 'materials/transactions')) ?>">Open ledger →</a>
    </div>

    <?php if (empty($recent)): ?>
      <div class="inv-empty">
        <div class="inv-empty-title">No transactions recorded yet</div>
        <div class="inv-empty-sub">Receive stock or issue an item to start the ledger.</div>
      </div>
    <?php else: ?>
      <div style="overflow-x:auto;">
        <table class="inv-table">
          <thead>
            <tr>
              <th>Date</th><th>Item</th><th>Description</th>
              <th>Type</th><th style="text-align:right;">Qty</th>
              <th style="text-align:right;">After</th><th>By</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recent as $t):
              $ts = isset($t->created_at) && $t->created_at ? strtotime($t->created_at) : false;
              $isRec = isset($t->type) && $t->type === 'RECEIVE';
            ?>
              <tr>
                <td class="inv-mono" style="font-size:11px;color:var(--inv-subtle);white-space:nowrap;"><?= $ts ? date('d M Y H:i', $ts) : '—' ?></td>
                <td class="inv-cell-main"><?= $h($t->item_code ?? '') ?></td>
                <td style="font-size:12.5px;max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= $h($t->description ?? '') ?></td>
                <td><span class="inv-badge <?= $isRec ? 'inv-badge-ok' : 'inv-badge-warn' ?>"><?= $isRec ? 'Receive' : 'Issue' ?></span></td>
                <td class="num" style="font-weight:600;color:<?= $isRec ? 'var(--inv-ok-fg)' : 'var(--inv-warn-fg)' ?>;"><?= $isRec ? '+' : '−' ?><?= number_format(abs((float)($t->qty ?? 0))) ?></td>
                <td class="num"><?= $t->stock_after !== null ? number_format((float)$t->stock_after) : '—' ?></td>
                <td style="font-size:12px;color:var(--inv-muted);white-space:nowrap;"><?= $h($t->user_name ?? '') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

</div>
