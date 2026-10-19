<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Settings access with the weekly-report defaults in one place.
 *
 * Why this model exists (plan task W0.7)
 * --------------------------------------
 * The weekly report used hard-coded constants for values the business owns:
 *
 *     $week_hours = 7 * 24;            // Reports::_build_workshop_weekly()
 *     'target'    => 0.90,             // ditto, twice
 *     strtotime('monday this week')    // week start, in four entry points
 *
 * Those values now live in `settings` (seeded in the database dump):
 *
 *     weekly_open_hours           168     open hours in a 7-day report week (W36!B2)
 *     daily_open_hours             24     open hours per day          (W36!C2)
 *     default_availability_target  0.88   last fallback of the target chain (BR-W13)
 *     report_week_start_day         1     1 = Monday … 7 = Sunday
 *     breakdown_count_mode  start_in_period   start_in_period | overlapping   (D1/OPEN-2)
 *     mtbf_zero_failure_mode        dash  dash | uptime                       (OPEN-3)
 *     pm_meter_stale_days           30    G4-stale: age limit for the reading
 *                                         behind a meter-only PM judgement
 *                                         (0 = the check is off)             (D21)
 *
 * Every getter falls back to the documented default when the row is missing or
 * holds an invalid value, so an installation without those settings rows
 * still behaves exactly as before — and nothing in the generator
 * needs to hard-code 168, 24 or 0.90 again.
 */

class Settings_model extends CI_Model {

    /** Defaults, mirrored from the seeded `settings` rows. Key => default. */
    const DEFAULTS = array(
        'weekly_open_hours'           => '168',
        'daily_open_hours'            => '24',
        'default_availability_target' => '0.88',
        'report_week_start_day'       => '1',
        'breakdown_count_mode'        => 'start_in_period',
        'mtbf_zero_failure_mode'      => 'dash',
        'pm_meter_stale_days'         => '30',

        /* ── sign-in security (finding F-19) ───────────── */
        // 1 = limit failures from one source across many accounts (credential
        // stuffing). Turn it off when every visitor appears to come from one
        // address (a proxy/CDN without `proxy_ips` configured), where the rule
        // would lock out the whole site instead of stopping an attacker.
        'login_ip_cap_enabled'        => '1',

        /* ── forecast planning (decisions D29–D31) ─────── */
        'standing_allowance_hours'         => '{}',
        'standing_allowance_default_hours' => '2',
        'inspection_default_hours'         => '1',
        'inspection_hours_by_type'         => '{}',
        'pm_utilisation_lookback_days'     => '28',
        'pm_default_hours_per_day'         => '{}',
        'unplanned_allowance_mode'         => 'flat',
        'unplanned_allowance_default_hours'=> '1',
        'unplanned_allowance_lookback_weeks' => '12',
        'forecast_show_excel_reference'    => '1',

        /* ── team / leave (previously hard-coded or absent) ── */
        // Seeded as 30 in the dump; kept here so a database created without
        // that row still has the value the certification screens expect.
        'cert_alert_days'                  => '30',
        // Days per leave type per year. A type missing from the map is not
        // tracked (shown as "—"), which is how Maternity/Paternity/Training
        // behave until the business sets a figure.
        'leave_entitlements'               => '{"Annual Leave":21,"Sick Leave":10,"Emergency":3}',
    );

    const DESCRIPTIONS = array(
        'login_ip_cap_enabled'        => 'F-19: 1 = limit failed sign-ins from one source across many accounts (credential stuffing); 0 = off',
        'weekly_open_hours'           => 'Open hours in a 7-day reporting week',
        'daily_open_hours'            => 'Open hours per day',
        'default_availability_target' => 'Fallback availability target when neither the equipment nor the group has one',
        'report_week_start_day'       => '1 = Monday … 7 = Sunday',
        'breakdown_count_mode'        => 'start_in_period = count breakdown events that start inside the week; overlapping = count every event overlapping the week',
        'mtbf_zero_failure_mode'      => 'dash = show "-" for MTBF/MTTR when a machine had no breakdown in the period; uptime = show uptime as MTBF',
        'pm_meter_stale_days'         => 'G4-stale: a PM schedule with no due date is judged by its meter alone, so it is NOT JUDGED when its newest reading is older than this many days before the reported week (0 = judge from the equipment record alone, as before)',

        'standing_allowance_hours'          => 'D31: planned standing downtime per DAY as JSON keyed by equipment-type name, e.g. {"Excavator":2,"Dozer":2}. Empty object = every type uses the default below',
        'standing_allowance_default_hours'  => 'D31: fallback standing downtime per day (hours) for any equipment type not listed in standing_allowance_hours',
        'inspection_default_hours'          => 'MP-3 (R-MP2, OPEN-MP4 = D37): hours one weekly internal inspection takes, for every machine type. 1 h by decision.',
        'inspection_hours_by_type'          => 'MP-3: optional per-type override as JSON, e.g. {"Bus":4}. Empty object = every type uses inspection_default_hours.',
        'pm_utilisation_lookback_days'      => 'MP-4 (R-MP3): how many days of meter readings give a machine its hours-per-day (the window runs back from its newest reading).',
        'pm_default_hours_per_day'          => 'MP-4 (R-MP3): fallback hours per day per equipment type, as JSON, e.g. {"Dozer":9}. Used only when a machine has fewer than two readings in the window. Empty object = no fallback: such a machine is reported, not guessed.',
        'unplanned_allowance_mode'          => 'flat = use unplanned_allowance_default_hours for every machine; derived = per-machine allowance from its own corrective history',
        'unplanned_allowance_default_hours' => 'flat mode: planned breakdown allowance per day (hours). The historical workbook typed 1 h/day on 85 of 103 machines',
        'unplanned_allowance_lookback_weeks'=> 'derived mode: how many weeks of history the per-machine allowance is computed from',
        'forecast_show_excel_reference'     => 'D29/D30: 1 = also show the historical Excel figures (24-hour calendar availability, unweighted group mean) as labelled reference columns; 0 = system figures only',

        'cert_alert_days'                   => 'Days before a certification expiry at which it is flagged "Expiring Soon" (the tracker also lists already-expired certificates)',
        'leave_entitlements'                => 'Leave days per type per year, as JSON keyed by the leave type, e.g. {"Annual Leave":21,"Sick Leave":10,"Emergency":3}. A type not listed is not tracked (shown as "—")',
    );

    const BREAKDOWN_COUNT_MODES = array('start_in_period', 'overlapping');
    const UNPLANNED_ALLOWANCE_MODES = array('flat', 'derived');
    const MTBF_ZERO_FAILURE_MODES = array('dash', 'uptime');

    /** @var array|null lazy key => raw value cache */
    protected $_cache = null;

    public function __construct() {
        parent::__construct();
    }

    /* ── reading ─────────────────────────────────────────────────────── */

    /**
     * Every settings row as key => value. A missing table yields an empty
     * array, never an exception.
     *
     * @param  bool $refresh bypass the per-request cache
     * @return array
     */
    public function all($refresh = false) {
        if ($this->_cache !== null && !$refresh) return $this->_cache;

        $out = array();
        if ($this->db) {
            try {
                $query = $this->db->select('setting_key, setting_value')->get('settings');

                // FALSE means the query itself failed (no table, no grant, no
                // server). Checked before ->result(), because calling a method
                // on FALSE is an Error that `catch (Exception)` does not catch.
                if ($query !== FALSE) {
                    foreach ((array)$query->result() as $r) {
                        if (isset($r->setting_key)) $out[$r->setting_key] = $r->setting_value;
                    }
                }
            } catch (Throwable $e) {
                $out = array();   // settings table not present → documented defaults
            }
        }
        $this->_cache = $out;
        return $this->_cache;
    }

    /**
     * Raw value for a key, falling back to $default and then to DEFAULTS.
     * Empty strings count as absent (the settings screen writes '' for blanks).
     */
    public function get($key, $default = null) {
        $all = $this->all();
        if (array_key_exists($key, $all) && $all[$key] !== null && $all[$key] !== '') {
            return $all[$key];
        }
        if ($default !== null) return $default;
        return array_key_exists($key, self::DEFAULTS) ? self::DEFAULTS[$key] : null;
    }

    public function int_val($key, $default = null) {
        return (int)$this->get($key, $default);
    }

    public function float_val($key, $default = null) {
        return (float)$this->get($key, $default);
    }

    /* ── the weekly-report values ────────────────────────────────────── */

    /** Open hours in the reporting week; when the stored value is unusable, derive it from the daily value. */
    public function weekly_open_hours() {
        $v = (int)$this->get('weekly_open_hours');
        return $v > 0 ? $v : 7 * $this->daily_open_hours();
    }

    /** Open hours per day (1–24; the W36 template uses 24). */
    public function daily_open_hours() {
        $v = (int)$this->get('daily_open_hours');
        return ($v >= 1 && $v <= 24) ? $v : 24;
    }

    /** Last fallback of the target chain (BR-W13): equipment → group → this value. */
    public function default_availability_target() {
        $v = (float)$this->get('default_availability_target');
        return ($v > 0 && $v <= 1) ? $v : 0.88;
    }

    /** ISO weekday the reporting week starts on: 1 = Monday … 7 = Sunday. */
    public function report_week_start_day() {
        $v = (int)$this->get('report_week_start_day');
        return ($v >= 1 && $v <= 7) ? $v : 1;
    }

    /** How a breakdown is counted in a period (D1 / OPEN-2). */
    public function breakdown_count_mode() {
        $v = (string)$this->get('breakdown_count_mode');
        return in_array($v, self::BREAKDOWN_COUNT_MODES, true) ? $v : 'start_in_period';
    }

    /** What MTBF/MTTR show when a machine had no breakdown in the period (OPEN-3). */
    public function mtbf_zero_failure_mode() {
        $v = (string)$this->get('mtbf_zero_failure_mode');
        return in_array($v, self::MTBF_ZERO_FAILURE_MODES, true) ? $v : 'dash';
    }

    /**
     * How old the reading behind a meter-only PM judgement may be, in days
     * (G4-stale, D21).  A schedule whose due date is empty is
     * judged by its meter alone, so the judgement is only as good as the
     * reading behind it: when the newest reading is older than this many days
     * before the reported week — or there is none on file — the schedule is
     * NOT JUDGED, never guessed.  `0` switches the check off (the judgement
     * then rests on `equipment.current_hours` / `current_km` alone, as before).
     */
    public function pm_meter_stale_days() {
        $v = $this->get('pm_meter_stale_days');
        if ($v === null || $v === '') return 30;
        $n = (int)$v;
        return $n >= 0 ? $n : 30;
    }

    /* ── period arithmetic ───────────────────────────────────────────── */

    /**
     * Open hours for a period of $days days.
     */
    public function period_open_hours($days) {
        $days = (int)$days;
        if ($days <= 0) return 0;
        return $days === 7 ? $this->weekly_open_hours() : $days * $this->daily_open_hours();
    }

    /**
     * First day of the reporting week containing $date (default: today),
     * honouring `report_week_start_day`.
     *
     * @param  string|null $date 'Y-m-d' (or any strtotime-able date)
     * @return string 'Y-m-d'
     */
    public function week_start($date = null) {
        $date  = $date ? date('Y-m-d', strtotime($date)) : date('Y-m-d');
        $dow   = (int)date('N', strtotime($date));            // 1 = Monday … 7 = Sunday
        $start = $this->report_week_start_day();
        $offset = ($dow - $start + 7) % 7;                    // days elapsed since the week start
        return $offset === 0 ? $date : date('Y-m-d', strtotime($date . ' -' . $offset . ' days'));
    }

    /**
     * The reporting week containing $date, as an inclusive day range.
     *
     * @param  string|null $date
     * @return array{start:string,end:string,days:int}
     */
    public function week_bounds($date = null) {
        $start = $this->week_start($date);
        $end   = date('Y-m-d', strtotime($start . ' +6 days'));
        return array('start' => $start, 'end' => $end, 'days' => 7);
    }

    /* ── ISO week helpers (the report picker and the download file name) ── */

    /**
     * The ISO-8601 week label of a date: '2026-W36'.
     *
     * @param  string|null $date any strtotime-able date (default: today)
     * @return string|null
     */
    public function iso_week_label($date = null) {
        $ts = $date ? strtotime($date) : time();
        if ($ts === false) return null;
        return date('o-\WW', $ts);
    }

    /**
     * The first day of the reporting week named by an ISO label, honouring
     * `report_week_start_day`. Accepts '2026-W36', '2026W36' and lower case;
     * returns null for anything that is not a real ISO week (so a crafted URL
     * can never move the report to a made-up date, e.g. 2026-W53).
     *
     * @param  string $label
     * @return string|null 'Y-m-d'
     */
    public function iso_week_start($label) {
        if (!is_string($label) && !is_numeric($label)) return null;
        $label = trim((string)$label);
        if (!preg_match('/^(\d{4})-?[Ww](\d{1,2})$/', $label, $m)) return null;

        $year = (int)$m[1];
        $week = (int)$m[2];
        if ($week < 1 || $week > 53 || $year < 2000 || $year > 2100) return null;

        try {
            $d = new DateTime();
        } catch (Exception $e) {
            return null;
        }
        $d->setISODate($year, $week, 1);                       // the Monday of that ISO week
        // setISODate rolls a non-existent week (2026-W53) into the next year —
        // refuse it instead of silently reporting the wrong period
        if ($d->format('o-\WW') !== sprintf('%04d-W%02d', $year, $week)) return null;

        return $this->week_start($d->format('Y-m-d'));
    }

    /* ── writing ─────────────────────────────────────────────────────── */

    /**
     * A value may be written when it is a known report key or already exists in
     * the table — unknown keys from a crafted POST are ignored.
     */
    public function is_writable($key) {
        if (array_key_exists($key, self::DEFAULTS)) return true;
        $all = $this->all();
        return array_key_exists($key, $all);
    }

    /**
     * Coerce a posted value into what the report expects.
     * Percentages are accepted for the target ('88' → 0.88).
     */
    public function sanitize($key, $value) {
        switch ($key) {
            case 'weekly_open_hours':
                return (string)max(1, (int)$value);

            case 'daily_open_hours':
                $v = (int)$value;
                return (string)(($v >= 1 && $v <= 24) ? $v : 24);

            case 'default_availability_target':
                $v = (float)str_replace(',', '.', (string)$value);
                if ($v > 1) $v = $v / 100;                    // '88' / '88%' → 0.88
                $v = max(0.0, min(1.0, $v));
                return number_format($v, 4, '.', '');

            case 'report_week_start_day':
                $v = (int)$value;
                return (string)(($v >= 1 && $v <= 7) ? $v : 1);

            case 'breakdown_count_mode':
                return in_array($value, self::BREAKDOWN_COUNT_MODES, true) ? $value : 'start_in_period';

            case 'mtbf_zero_failure_mode':
                return in_array($value, self::MTBF_ZERO_FAILURE_MODES, true) ? $value : 'dash';

            case 'login_ip_cap_enabled':
                return ((int)$value === 0) ? '0' : '1';

            case 'pm_meter_stale_days':
                $v = (int)$value;
                return (string)($v >= 0 ? $v : 30);

            /* ── forecast planning (D31) ─────────────────────────────── */

            case 'inspection_default_hours':
                $h = (float)$value;
                return (string)(($h > 0 && $h <= 24) ? $h : 1);

            case 'pm_utilisation_lookback_days':
                $v = (int)$value;
                return (string)(($v >= 1 && $v <= 365) ? $v : 28);

            case 'pm_default_hours_per_day':
                $j = json_decode((string)$value, true);
                if (!is_array($j)) return '{}';
                $clean = array();
                foreach ($j as $type => $hours) {
                    if (!is_string($type) || trim($type) === '') continue;
                    $h = (float)$hours;
                    if ($h <= 0 || $h > 24) continue;
                    $clean[trim($type)] = $h;
                }
                return $clean ? json_encode($clean) : '{}';

            case 'inspection_hours_by_type':
                // same guard as standing_allowance_hours: a malformed map would
                // silently change every inspection's duration
                $j = json_decode((string)$value, true);
                if (!is_array($j)) return '{}';
                $clean = array();
                foreach ($j as $type => $hours) {
                    if (!is_string($type) || trim($type) === '') continue;
                    $h = (float)$hours;
                    if ($h <= 0 || $h > 24) continue;
                    $clean[trim($type)] = $h;
                }
                return $clean ? json_encode($clean) : '{}';

            case 'standing_allowance_hours':
                // must stay valid JSON mapping type name => hours per day, or an
                // empty map; a malformed value would silently zero every
                // machine's standing allowance.
                $j = json_decode((string)$value, true);
                if (!is_array($j)) return '{}';
                $clean = array();
                foreach ($j as $type => $hours) {
                    if (!is_string($type) || trim($type) === '') continue;
                    $h = (float)$hours;
                    if ($h < 0) $h = 0;
                    if ($h > 24) $h = 24;                       // a day is 24 h
                    $clean[trim($type)] = $h;
                }
                return $clean ? json_encode($clean) : '{}';

            case 'standing_allowance_default_hours':
            case 'unplanned_allowance_default_hours':
                $v = (float)str_replace(',', '.', (string)$value);
                return (string)(($v >= 0 && $v <= 24) ? round($v, 2) : 0);

            case 'unplanned_allowance_mode':
                return in_array($value, self::UNPLANNED_ALLOWANCE_MODES, true) ? $value : 'flat';

            case 'unplanned_allowance_lookback_weeks':
                $v = (int)$value;
                return (string)(($v >= 1 && $v <= 104) ? $v : 12);   // 1 wk … 2 yr

            case 'forecast_show_excel_reference':
                return ((string)$value === '1' || $value === true || $value === 1) ? '1' : '0';

            /* ── team / leave ─────────────────────────────────────────── */

            case 'cert_alert_days':
                $v = (int)$value;
                return (string)(($v > 0 && $v <= 365) ? $v : 30);

            case 'leave_entitlements':
                // must stay a JSON object of type => whole/half days; a
                // malformed value would silently zero every balance.
                $j = json_decode((string)$value, true);
                if (!is_array($j)) return '{}';
                $clean = array();
                foreach ($j as $type => $days) {
                    if (!is_string($type) || trim($type) === '') continue;
                    if (!is_numeric($days)) continue;
                    $d = (float)$days;
                    if ($d < 0)   $d = 0;
                    if ($d > 365) $d = 365;
                    $clean[trim($type)] = round($d, 1);
                }
                return $clean ? json_encode($clean) : '{}';
        }
        return $value;
    }

    /**
     * Upsert a batch of settings. Rows that do not exist yet are inserted with
     * their documented description (the settings table has no unique index on
     * `setting_key`, so existence is checked first).
     *
     * @param  array $values key => value (as posted)
     * @return int number of keys written
     */
    public function save_many(array $values) {
        $written = 0;
        foreach ($values as $key => $value) {
            if (!$this->is_writable($key)) continue;
            $value = $this->sanitize($key, $value);
            $row   = $this->db->select('id')->where('setting_key', $key)->get('settings')->row();

            if ($row) {
                $this->db->update('settings', array('setting_value' => $value), array('id' => $row->id));
            } else {
                $this->db->insert('settings', array(
                    'setting_key'   => $key,
                    'setting_value' => $value,
                    'description'   => isset(self::DESCRIPTIONS[$key]) ? self::DESCRIPTIONS[$key] : null,
                ));
            }
            $written++;
        }
        $this->_cache = null;                                  // next read re-queries
        return $written;
    }

    /* ── metadata for the settings screen ───────────────────────────── */

    /** @return array key => default */
    public static function defaults() {
        return self::DEFAULTS;
    }

    /** @return array key => human description */
    public static function descriptions() {
        return self::DESCRIPTIONS;
    }

    /** The report keys, in the order the settings screen shows them. */
    public static function report_keys() {
        return array(
            'weekly_open_hours', 'daily_open_hours', 'default_availability_target',
            'report_week_start_day', 'breakdown_count_mode', 'mtbf_zero_failure_mode',
            'pm_meter_stale_days',
        );
    }

    /**
     * The forecast-planning settings, grouped for the settings screen
     * (D29–D31).
     *
     * These are a planner's decision, not a developer's constant: the standing
     * allowance alone drove 966 of the historical 1 894 planned downtime hours.
     *
     * @return array section heading => list of keys
     */
    public static function forecast_keys() {
        return array(
            'Planned Allowances' => array(
                'standing_allowance_default_hours',
                'standing_allowance_hours',
                'unplanned_allowance_mode',
                'unplanned_allowance_default_hours',
                'unplanned_allowance_lookback_weeks',
            ),
            'Weekly Inspections' => array(
                'inspection_default_hours',
                'inspection_hours_by_type',
            ),
            'Meter Projection' => array(
                'pm_utilisation_lookback_days',
                'pm_default_hours_per_day',
            ),
            'Forecast Presentation' => array(
                'forecast_show_excel_reference',
            ),
        );
    }
}
