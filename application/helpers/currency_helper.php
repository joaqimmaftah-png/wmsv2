<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Currency vocabulary and the installation's own currency (K-11).
 *
 * The register entry was "currency handling is inconsistent". Four places
 * disagreed:
 *
 *   settings.currency   seeded ('USD') but never read by any screen
 *   suppliers           defaulted to 'TZS' in three controllers
 *   manual receipts     hard-coded 'UGX' on the posted transaction
 *   Settings screen     offered a list that did not even include UGX
 *
 * The list below is the single vocabulary those screens now share; the site
 * currency is whatever Settings holds, so the operator — not a string literal
 * in a controller — decides it. Prices that belong to a specific column
 * (`materials.unit_price_ugx`, `materials.unit_price_usd`) keep their explicit
 * label; everything that records a *transaction* currency reads this helper.
 */

/**
 * Currency codes the pickers offer. Add a code here and it appears in the
 * Settings, supplier, catalog and order forms — there is no second list.
 *
 * @return array<int,string>
 */
function wms_currencies() {
    return array('UGX', 'TZS', 'USD', 'EUR', 'GBP', 'CNY', 'CDF', 'KES', 'AUD', 'ZAR', 'IDR', 'MYR', 'NGN');
}

/**
 * The installation's reporting currency: `settings.currency`, upper-cased and
 * sanity-checked. Falls back to the seeded default ('USD') when the row or the
 * table is missing, so a half-installed database still renders.
 *
 * The model is loaded on first use, so a view can call this without the
 * controller having loaded Settings_model. `$refresh` bypasses the per-request
 * cache (used by tests, and by a caller that has just saved the setting).
 *
 * @param  bool $refresh
 * @return string 3-letter ISO code
 */
function wms_site_currency($refresh = false) {
    static $code = null;
    if (!$refresh && $code !== null) return $code;

    $ci = function_exists('get_instance') ? get_instance() : null;
    if (!$ci) return $code = 'USD';

    if (!isset($ci->Settings_model)) {
        $ci->load->model('Settings_model');
    }
    $stored = isset($ci->Settings_model) ? $ci->Settings_model->get('currency', 'USD') : 'USD';

    $code = strtoupper((string)(trim((string) $stored)));
    if (!preg_match('/^[A-Z]{3}$/', $code)) {
        $code = 'USD';   // a corrupt setting must not leak into every total
    }
    return $code;
}

/**
 * Normalise a posted currency code. Anything that is not three letters is
 * replaced by $fallback (by default the site currency), so a crafted POST can
 * never write an empty or 20-character "currency" into a transaction.
 *
 * @param  mixed       $value
 * @param  string|null $fallback
 * @return string
 */
function wms_currency_code($value, $fallback = null) {
    $code = strtoupper((string)(trim((string) $value)));
    if (preg_match('/^[A-Z]{3}$/', $code)) return $code;

    if ($fallback !== null) {
        $fallback = strtoupper((string)(trim((string) $fallback)));
        if (preg_match('/^[A-Z]{3}$/', $fallback)) return $fallback;
    }
    return wms_site_currency();
}

/**
 * "1,234.56 UGX" — a number and its code, for places that used to print a bare
 * number and leave the reader guessing which currency it was in.
 *
 * @param  mixed       $amount
 * @param  string|null $currency null = the site currency
 * @param  int         $decimals
 * @return string
 */
function wms_money($amount, $currency = null, $decimals = 2) {
    return number_format((float) $amount, (int) $decimals) . ' '
        . ($currency !== null ? wms_currency_code($currency) : wms_site_currency());
}
