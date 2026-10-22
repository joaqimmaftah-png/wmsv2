<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Attendance punch helper — the pure logic of the check-in / check-out
 * workflow described in ATTENDANCE_WORKFLOW.md.
 *
 * Everything here is deterministic, dependency-free and side-effect free so it
 * can be unit-tested without a database (tests/run.php) and reused by the
 * controller, the model and the site screens. Nothing in this file talks to
 * the database, the session or the network: the caller supplies the inputs
 * (including the time) and gets a verdict back.
 *
 * Two ideas live here:
 *
 *   1. Geofence arithmetic — how far a phone is from the configured site
 *      point, and whether that distance and the reported GPS accuracy are good
 *      enough to accept a punch.
 *
 *   2. Short-lived signed QR tokens — the site screen shows a token that
 *      changes every `attendance_qr_ttl_seconds`; the server recomputes the
 *      signature from the site's own secret and its own clock, so a token can
 *      neither be forged nor kept for later. The token carries no technician
 *      information, only the site, the time window it belongs to and a MAC.
 */

/** Earth mean radius, metres (IUGG). */
if (!defined('WMS_ATTENDANCE_EARTH_RADIUS_M')) {
    define('WMS_ATTENDANCE_EARTH_RADIUS_M', 6371008.8);
}

/** QR token format version. A token from another version is rejected. */
if (!defined('WMS_ATTENDANCE_QR_VERSION')) {
    define('WMS_ATTENDANCE_QR_VERSION', 'v1');
}

/* ─────────────────────────────────────────────────────────────────────────
   Coordinates
   ───────────────────────────────────────────────────────────────────────── */

/**
 * Is this a usable latitude? Numeric, finite, inside ±90.
 *
 * Deliberately strict: an empty string, NULL, an array, "51.5N" or a value
 * outside the range is not a latitude. A geofence built on a wrong number is
 * worse than a geofence that refuses to build.
 *
 * @param  mixed $value
 * @return bool
 */
function wms_valid_latitude($value) {
    if (!is_scalar($value) || is_bool($value)) return FALSE;
    $s = trim((string)$value);
    if ($s === '' || !is_numeric($s)) return FALSE;
    $f = (float)$s;
    if (!is_finite($f)) return FALSE;
    return $f >= -90.0 && $f <= 90.0;
}

/**
 * Is this a usable longitude? Numeric, finite, inside ±180.
 *
 * @param  mixed $value
 * @return bool
 */
function wms_valid_longitude($value) {
    if (!is_scalar($value) || is_bool($value)) return FALSE;
    $s = trim((string)$value);
    if ($s === '' || !is_numeric($s)) return FALSE;
    $f = (float)$s;
    if (!is_finite($f)) return FALSE;
    return $f >= -180.0 && $f <= 180.0;
}

/**
 * Great-circle distance between two points, in metres (haversine).
 *
 * Haversine is the right shape for a geofence: accurate to well under a metre
 * at the distances involved, no dependency on a projection library, and
 * symmetric (the order of the points does not matter).
 *
 * @return float|null metres, or NULL when either point is not a valid coordinate
 */
function wms_attendance_distance_m($lat1, $lon1, $lat2, $lon2) {
    if (!wms_valid_latitude($lat1) || !wms_valid_longitude($lon1)
        || !wms_valid_latitude($lat2) || !wms_valid_longitude($lon2)) {
        return NULL;
    }

    $lat1 = deg2rad((float)$lat1);
    $lat2 = deg2rad((float)$lat2);
    $dLat = $lat2 - $lat1;
    $dLon = deg2rad((float)$lon2 - (float)$lon1);

    $a = sin($dLat / 2) * sin($dLat / 2)
       + cos($lat1) * cos($lat2) * sin($dLon / 2) * sin($dLon / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return round(WMS_ATTENDANCE_EARTH_RADIUS_M * $c, 1);
}

/**
 * The GPS side of a punch, as a verdict.
 *
 * Order matters: a phone with no position cannot be judged, an accuracy that
 * is worse than the policy allows is rejected *before* the distance (a 2 km
 * accuracy circle that happens to contain the site proves nothing), and only
 * then is the distance compared with the geofence radius.
 *
 * @param  float|null $distance_m       distance from the site point, metres
 * @param  int        $radius_m         geofence radius, metres (0/empty = no fence)
 * @param  float|null $accuracy_m       accuracy the phone reported, metres
 * @param  int        $max_accuracy_m   worst accuracy accepted, metres (0 = no limit)
 * @return array{ok:bool,code:string,message:string}
 */
function wms_attendance_geo_verdict($distance_m, $radius_m, $accuracy_m, $max_accuracy_m) {
    if ($distance_m === NULL) {
        return array(
            'ok'      => FALSE,
            'code'    => 'no_location',
            'message' => 'Your location could not be read. Turn on location for this browser and try again.',
        );
    }

    $accuracy = ($accuracy_m === NULL || !is_numeric($accuracy_m)) ? NULL : (float)$accuracy_m;
    $max_acc  = (int)$max_accuracy_m;

    if ($accuracy === NULL || $accuracy <= 0) {
        return array(
            'ok'      => FALSE,
            'code'    => 'no_accuracy',
            'message' => 'Your phone did not report how accurate its position is, so the punch cannot be verified. Try again in the open.',
        );
    }

    if ($max_acc > 0 && $accuracy > $max_acc) {
        return array(
            'ok'      => FALSE,
            'code'    => 'accuracy',
            'message' => 'Your position is only accurate to about ' . (int)round($accuracy)
                       . ' m; this site accepts up to ' . $max_acc . ' m. Step outside or wait a moment and try again.',
        );
    }

    $radius = (int)$radius_m;
    if ($radius > 0 && $distance_m > $radius) {
        return array(
            'ok'      => FALSE,
            'code'    => 'outside',
            'message' => 'You are about ' . (int)round($distance_m) . ' m from this site; '
                       . 'the allowed radius is ' . $radius . ' m.',
        );
    }

    return array('ok' => TRUE, 'code' => 'ok', 'message' => 'Location verified.');
}

/* ─────────────────────────────────────────────────────────────────────────
   Signed, short-lived QR tokens
   ───────────────────────────────────────────────────────────────────────── */

/**
 * The MAC for one site and one time window.
 *
 * The secret is per site (32 random bytes, generated when the site is
 * created), so a token from another site — or another installation — never
 * verifies here, and rotating one site's secret does not disturb the others.
 *
 * @return string 32 hex characters (128 bits)
 */
function wms_attendance_qr_mac($site_id, $window, $secret) {
    $payload = 'attendance-qr|' . (int)$site_id . '|' . (int)$window;
    return substr(hash_hmac('sha256', $payload, (string)$secret), 0, 32);
}

/**
 * Domain-separated MAC for a fixed/printed pilot site QR token.
 *
 * Uses a distinct HMAC domain prefix from rotating window tokens so window 0
 * can never collide with a time-windowed token, while still invalidating
 * immediately whenever the site's `qr_secret` is rotated.
 *
 * @return string 32 hex characters (128 bits)
 */
function wms_attendance_qr_static_mac($site_id, $secret) {
    $payload = 'attendance-qr-static|' . (int)$site_id;
    return substr(hash_hmac('sha256', $payload, (string)$secret), 0, 32);
}

/**
 * Build a fixed/printable token for sites without a permanent gate screen
 * (ATTENDANCE_WORKFLOW.md → "One-time site setup", step 3).
 *
 * Format: `v1.<site>.0.<mac>` — carries no technician data, requires the site
 * explicitly allow printed QR codes, and is revoked immediately when the site
 * secret is rotated. Live GPS geofence + accuracy checks still apply.
 *
 * @param  int    $site_id
 * @param  string $secret
 * @return string|null
 */
function wms_attendance_qr_static_token($site_id, $secret) {
    $site_id = (int)$site_id;
    $secret  = is_string($secret) ? trim($secret) : '';
    if ($site_id <= 0 || strlen($secret) < 16) return NULL;

    return WMS_ATTENDANCE_QR_VERSION . '.' . $site_id . '.0.'
         . wms_attendance_qr_static_mac($site_id, $secret);
}

/**
 * Build the token for a site at a given moment.
 *
 * Format: `v1.<site>.<window>.<mac>` — short enough to encode in a low-density
 * QR code that a phone camera reads instantly, and containing no personal data.
 *
 * @param  int         $site_id
 * @param  string      $secret   site's QR secret
 * @param  int|null    $time     unix time (default: now)
 * @param  int         $ttl      seconds one token is valid for (clamped 10–3600)
 * @return string|null NULL when the site id or secret is unusable
 */
function wms_attendance_qr_token($site_id, $secret, $time = NULL, $ttl = 60) {
    $site_id = (int)$site_id;
    $secret  = is_string($secret) ? trim($secret) : '';
    if ($site_id <= 0 || strlen($secret) < 16) return NULL;

    $ttl    = max(10, min(3600, (int)$ttl));
    $time   = ($time === NULL) ? time() : (int)$time;
    $window = (int)floor($time / $ttl);

    return WMS_ATTENDANCE_QR_VERSION . '.' . $site_id . '.' . $window . '.'
         . wms_attendance_qr_mac($site_id, $window, $secret);
}

/**
 * Verify a scanned token.
 *
 * Accepted: the token for the current window, and tokens from windows that
 * ended less than $grace seconds ago (so a technician standing at the screen
 * when it flips is not punished for the refresh). When $allow_static is TRUE
 * for this site, a site-signed printed token (`window = 0`) is also accepted.
 * Rejected: anything malformed, signed with another secret, from another site,
 * or older than ttl + grace. Windows in the future are rejected too — the
 * server's clock issued the token, so a future window means the token was not
 * issued by this server.
 *
 * @param  string   $token
 * @param  string   $secret       the secret of the site the token claims to be for
 * @param  int|null $time         unix time (default: now)
 * @param  int      $ttl          seconds per window (clamped 10–3600)
 * @param  int      $grace        seconds a just-expired window is still accepted
 * @param  bool     $allow_static whether this site permits printed/fixed QR codes
 * @return array{ok:bool,code:string,site_id:int,window:int,expires_in:int,message:string}
 *         codes: ok | malformed | wrong_version | bad_signature | expired | static_disabled
 */
function wms_attendance_qr_verify($token, $secret, $time = NULL, $ttl = 60, $grace = 0, $allow_static = FALSE) {
    $fail = function ($code, $message) {
        return array(
            'ok'         => FALSE,
            'code'       => $code,
            'site_id'    => 0,
            'window'     => 0,
            'expires_in' => 0,
            'message'    => $message,
        );
    };

    $token  = is_scalar($token) ? trim((string)$token) : '';
    $secret = is_string($secret) ? trim($secret) : '';
    $ttl    = max(10, min(3600, (int)$ttl));
    $grace  = max(0, min(3600, (int)$grace));
    $time   = ($time === NULL) ? time() : (int)$time;

    if ($token === '' || strlen($token) > 120
        || !preg_match('/^([A-Za-z0-9]{2,4})\.([0-9]{1,10})\.([0-9]{1,12})\.([0-9a-f]{16,64})$/D', $token, $m)) {
        return $fail('malformed', 'That is not a valid site code. Scan the QR code on the site screen again.');
    }

    if ($m[1] !== WMS_ATTENDANCE_QR_VERSION) {
        return $fail('wrong_version', 'That site code is from an older version. Scan the QR code on the site screen again.');
    }

    $site_id = (int)$m[2];
    $window  = (int)$m[3];
    $mac_hex = strtolower($m[4]);

    if ($site_id <= 0 || $secret === '') {
        return $fail('bad_signature', 'That site code is not valid here. Scan the QR code on the site screen again.');
    }

    // Printed / fixed QR path (`v1.<site>.0.<static_mac>`)
    if ($window === 0) {
        $expected_static = wms_attendance_qr_static_mac($site_id, $secret);
        if (!hash_equals($expected_static, $mac_hex)) {
            return $fail('bad_signature', 'That site code is not valid here. Scan the QR code on the site screen again.');
        }
        if (!$allow_static) {
            return $fail('static_disabled', 'Printed QR codes are disabled for this site. Scan the rotating code on the site screen instead.');
        }
        return array(
            'ok'         => TRUE,
            'code'       => 'ok',
            'site_id'    => $site_id,
            'window'     => 0,
            'expires_in' => 0,
            'message'    => 'Printed site code verified.',
        );
    }

    $expected = wms_attendance_qr_mac($site_id, $window, $secret);
    if (!hash_equals($expected, $mac_hex)) {
        return $fail('bad_signature', 'That site code is not valid here. Scan the QR code on the site screen again.');
    }

    $current = (int)floor($time / $ttl);
    $oldest  = $current - (int)floor($grace / $ttl);

    if ($window > $current) {
        return $fail('expired', 'That site code is dated in the future. Check that the phone and the site screen show the same time.');
    }
    if ($window < $oldest) {
        return $fail('expired', 'That site code has expired. Scan the QR code on the site screen again.');
    }

    $expires_in = (($window + 1) * $ttl + $grace) - $time;

    return array(
        'ok'         => TRUE,
        'code'       => 'ok',
        'site_id'    => $site_id,
        'window'     => $window,
        'expires_in' => max(0, $expires_in),
        'message'    => 'Site code verified.',
    );
}

/* ─────────────────────────────────────────────────────────────────────────
   Punch sequencing
   ───────────────────────────────────────────────────────────────────────── */

/**
 * What does this punch mean, given today's latest accepted punch?
 *
 * A technician alternates: no punch yet (or the last one was a check-out)
 * means this is a check-in; a check-in still standing means this is the
 * check-out. The type is decided on the server, never sent by the phone.
 *
 * @param  string|null $last_type 'check_in' | 'check_out' | NULL
 * @return string 'check_in' | 'check_out'
 */
function wms_attendance_next_punch($last_type) {
    if ($last_type === 'check_in') return 'check_out';
    if ($last_type === 'check_out') return 'check_in';
    return 'check_in';
}

/**
 * Human label for a punch type.
 *
 * @param  string $type
 * @return string
 */
function wms_attendance_punch_label($type) {
    return $type === 'check_out' ? 'Check-out' : 'Check-in';
}

/**
 * Is this user on site at the end of $date's punches?
 *
 * "On site" means the latest accepted punch of the day is a check-in: a
 * check-out (or no punch at all) means they are not on site.
 *
 * @param  array $types punch types for one user and one day, in punch order
 * @return bool
 */
function wms_attendance_on_site(array $types) {
    if (empty($types)) return FALSE;
    $last = end($types);
    return $last === 'check_in';
}

/**
 * Derive the daily `attendance` summary fields (`check_in`, `check_out`,
 * `hours_worked`) from an ordered list of accepted punch rows.
 *
 * Sums paired (`check_in` → `check_out`) intervals so a technician who leaves
 * midday (`08:00–12:00`, `15:00–17:00`) is credited with actual on-site time
 * (`6.00 h`) rather than full span (`9.00 h`), while keeping `check_in` as the
 * day's first arrival and `check_out` as the latest departure.
 *
 * @param  array $punches objects or associative arrays with `punch_type` & `punched_at`
 * @return array{check_in:string|null,check_out:string|null,hours_worked:float|null}
 */
function wms_attendance_day_summary(array $punches) {
    $first_in      = NULL;
    $last_out      = NULL;
    $open_in_ts    = NULL;
    $total_seconds = 0;
    $has_completed = FALSE;

    foreach ($punches as $p) {
        $type = is_array($p) ? (isset($p['punch_type']) ? $p['punch_type'] : '') : (isset($p->punch_type) ? $p->punch_type : '');
        $raw  = is_array($p) ? (isset($p['punched_at']) ? $p['punched_at'] : '') : (isset($p->punched_at) ? $p->punched_at : '');
        $ts   = strtotime((string)$raw);
        if ($ts === FALSE) continue;
        $hms  = date('H:i:s', $ts);

        if ($type === 'check_in') {
            if ($first_in === NULL) $first_in = $hms;
            if ($open_in_ts === NULL) $open_in_ts = $ts;
        } elseif ($type === 'check_out') {
            $last_out = $hms;
            if ($open_in_ts !== NULL && $ts >= $open_in_ts) {
                $total_seconds += ($ts - $open_in_ts);
                $has_completed  = TRUE;
                $open_in_ts     = NULL;
            }
        }
    }

    return array(
        'check_in'     => $first_in,
        'check_out'    => $last_out,
        'hours_worked' => ($has_completed && $total_seconds > 0) ? round($total_seconds / 3600, 2) : NULL,
    );
}

/* ─────────────────────────────────────────────────────────────────────────
   AML Individual Attendance Sheet — pure calculation & period helpers
   ───────────────────────────────────────────────────────────────────────── */

/**
 * Elapsed hours between two HH:MM(:SS) clock times, rounded to 2 decimals.
 * Handles overnight shifts (e.g. 22:00 -> 06:00 = 8.0h). Returns NULL when
 * either time is absent/invalid or equal.
 *
 * @param  mixed $check_in
 * @param  mixed $check_out
 * @return float|null
 */
function wms_hours_between($check_in, $check_out) {
    if (!is_scalar($check_in) || !is_scalar($check_out)) return NULL;
    $in_s  = trim((string)$check_in);
    $out_s = trim((string)$check_out);
    if (!preg_match('/^(?:([01][0-9]|2[0-3])):([0-5][0-9])(?::([0-5][0-9]))?$/D', $in_s, $m1)) return NULL;
    if (!preg_match('/^(?:([01][0-9]|2[0-3])):([0-5][0-9])(?::([0-5][0-9]))?$/D', $out_s, $m2)) return NULL;

    $in_sec  = ((int)$m1[1]) * 3600 + ((int)$m1[2]) * 60 + (isset($m1[3]) && $m1[3] !== '' ? (int)$m1[3] : 0);
    $out_sec = ((int)$m2[1]) * 3600 + ((int)$m2[2]) * 60 + (isset($m2[3]) && $m2[3] !== '' ? (int)$m2[3] : 0);

    if ($in_sec === $out_sec) return NULL;
    $diff = ($out_sec > $in_sec) ? ($out_sec - $in_sec) : ((86400 - $in_sec) + $out_sec);
    return round($diff / 3600, 2);
}

/**
 * Format numeric hours cleanly without trailing zeros (e.g. 8, 8.5, 8.25).
 *
 * @param  float|int|string|null $hours
 * @return string
 */
function wms_timesheet_format_hours($hours) {
    if ($hours === NULL || $hours === '' || !is_numeric($hours)) return '';
    $f = round((float)$hours, 2);
    if ($f <= 0) return '';
    return rtrim(rtrim(number_format($f, 2, '.', ''), '0'), '.');
}

/**
 * Resolve dynamic calendar dates & printable A4 sheet segments for a month /
 * half-month / custom payroll period. Defaults strictly to live current month.
 *
 * @param  string|null $month        'YYYY-MM'
 * @param  string|null $period_mode  'full' | 'full_compact' | 'h1' | 'h2' | 'custom'
 * @param  string|null $from         'YYYY-MM-DD' when $period_mode === 'custom'
 * @param  string|null $to           'YYYY-MM-DD' when $period_mode === 'custom'
 * @return array{month:string,period_mode:string,start_date:string,end_date:string,period_label:string,month_heading:string,file_slug:string,segments:array<int,array{start_date:string,end_date:string,month_heading:string}>}
 */
function wms_timesheet_resolve_period($month = NULL, $period_mode = 'full', $from = NULL, $to = NULL) {
    $valid_ymd = function ($s) {
        if (!is_string($s) || !preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $s, $m)) return NULL;
        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $s : NULL;
    };

    if (!is_string($month) || !preg_match('/^([0-9]{4})-(0[1-9]|1[0-2])$/D', $month)) {
        $month = date('Y-m');
    }

    $mode = is_string($period_mode) ? strtolower(trim($period_mode)) : 'full';
    if (!in_array($mode, array('full', 'full_compact', 'h1', 'h2', 'custom'), TRUE)) {
        $mode = 'full';
    }

    $first_of_month = $month . '-01';
    $last_of_month  = date('Y-m-t', strtotime($first_of_month));
    $mid_of_month   = $month . '-15';
    $h2_of_month    = $month . '-16';
    $month_heading  = 'MONTH OF ' . strtoupper(date('F Y', strtotime($first_of_month)));

    if ($mode === 'custom') {
        $s = $valid_ymd($from);
        $e = $valid_ymd($to);
        if (!$s || !$e) {
            $mode = 'full';
        } else {
            if ($e < $s) {
                $tmp = $s; $s = $e; $e = $tmp;
            }
            // Cap custom range at 62 days so a mistyped year cannot generate hundreds of pages
            $max_end = date('Y-m-d', strtotime($s . ' +61 days'));
            if ($e > $max_end) $e = $max_end;

            $segments = array();
            $cur = strtotime($s);
            $end_ts = strtotime($e);
            $total_days = (int)round(($end_ts - $cur) / 86400) + 1;
            if ($total_days <= 18) {
                $segments[] = array(
                    'start_date'    => $s,
                    'end_date'      => $e,
                    'month_heading' => 'MONTH OF ' . strtoupper(date('F Y', $end_ts)),
                );
            } else {
                while ($cur <= $end_ts) {
                    $chunk_end = min($end_ts, strtotime('+15 days', $cur));
                    $segments[] = array(
                        'start_date'    => date('Y-m-d', $cur),
                        'end_date'      => date('Y-m-d', $chunk_end),
                        'month_heading' => 'MONTH OF ' . strtoupper(date('F Y', $chunk_end)),
                    );
                    $cur = strtotime('+1 day', $chunk_end);
                }
            }
            return array(
                'month'         => substr($e, 0, 7),
                'period_mode'   => 'custom',
                'start_date'    => $s,
                'end_date'      => $e,
                'period_label'  => date('d M Y', strtotime($s)) . ' – ' . date('d M Y', strtotime($e)),
                'month_heading' => 'MONTH OF ' . strtoupper(date('F Y', $end_ts)),
                'file_slug'     => $s . '_to_' . $e,
                'segments'      => $segments,
            );
        }
    }

    if ($mode === 'h1') {
        return array(
            'month'         => $month,
            'period_mode'   => 'h1',
            'start_date'    => $first_of_month,
            'end_date'      => $mid_of_month,
            'period_label'  => date('01 – 15 F Y', strtotime($first_of_month)),
            'month_heading' => $month_heading,
            'file_slug'     => $month . '_01-15',
            'segments'      => array(
                array('start_date' => $first_of_month, 'end_date' => $mid_of_month, 'month_heading' => $month_heading),
            ),
        );
    }

    if ($mode === 'h2') {
        return array(
            'month'         => $month,
            'period_mode'   => 'h2',
            'start_date'    => $h2_of_month,
            'end_date'      => $last_of_month,
            'period_label'  => date('16 – t F Y', strtotime($first_of_month)),
            'month_heading' => $month_heading,
            'file_slug'     => $month . '_16-' . substr($last_of_month, 8, 2),
            'segments'      => array(
                array('start_date' => $h2_of_month, 'end_date' => $last_of_month, 'month_heading' => $month_heading),
            ),
        );
    }

    if ($mode === 'full_compact') {
        return array(
            'month'         => $month,
            'period_mode'   => 'full_compact',
            'start_date'    => $first_of_month,
            'end_date'      => $last_of_month,
            'period_label'  => date('F Y', strtotime($first_of_month)) . ' (1 Page)',
            'month_heading' => $month_heading,
            'file_slug'     => $month,
            'segments'      => array(
                array('start_date' => $first_of_month, 'end_date' => $last_of_month, 'month_heading' => $month_heading),
            ),
        );
    }

    // Default 'full': full calendar month rendered across AML's two spacious
    // half-month sheets (01–15 and 16–End) so every row keeps full wet-ink signing height.
    return array(
        'month'         => $month,
        'period_mode'   => 'full',
        'start_date'    => $first_of_month,
        'end_date'      => $last_of_month,
        'period_label'  => date('F Y', strtotime($first_of_month)),
        'month_heading' => $month_heading,
        'file_slug'     => $month,
        'segments'      => array(
            array('start_date' => $first_of_month, 'end_date' => $mid_of_month,  'month_heading' => $month_heading),
            array('start_date' => $h2_of_month,    'end_date' => $last_of_month, 'month_heading' => $month_heading),
        ),
    );
}

/**
 * Group a date window into Monday–Sunday weekly blocks matching the AML
 * Individual Attendance Sheet layout, merging live `attendance` & `leave_requests`.
 *
 * @param  string               $start_date          'YYYY-MM-DD'
 * @param  string               $end_date            'YYYY-MM-DD'
 * @param  array<string,array>  $attendance_by_date  keyed by 'YYYY-MM-DD'
 * @param  array<string,string> $leave_by_date       keyed by 'YYYY-MM-DD' => leave_type
 * @param  string               $auto_leave_prefix   internal marker prefix stripped from notes
 * @return array{start_date:string,end_date:string,weeks:array,days_worked:int,total_hours:float,total_hours_label:string,day_count:int}
 */
function wms_timesheet_build_weeks(
    $start_date,
    $end_date,
    array $attendance_by_date = array(),
    array $leave_by_date = array(),
    $auto_leave_prefix = 'Auto: approved leave request #'
) {
    $weeks       = array();
    $cur_days    = array();
    $cur_hours   = 0.0;
    $days_worked = 0;
    $total_hours = 0.0;
    $day_count   = 0;

    $ts     = strtotime($start_date);
    $end_ts = strtotime($end_date);

    while ($ts !== FALSE && $end_ts !== FALSE && $ts <= $end_ts) {
        $iso = date('Y-m-d', $ts);
        $dow = (int)date('N', $ts); // 1 = Monday .. 7 = Sunday

        if ($dow === 1 && !empty($cur_days)) {
            $weeks[] = array(
                'days'        => $cur_days,
                'hours'       => round($cur_hours, 2),
                'hours_label' => wms_timesheet_format_hours($cur_hours),
            );
            $cur_days  = array();
            $cur_hours = 0.0;
        }

        $att    = isset($attendance_by_date[$iso]) ? (array)$attendance_by_date[$iso] : NULL;
        $status = ($att && isset($att['status'])) ? (string)$att['status'] : '';

        $raw_in   = ($att && !empty($att['check_in']))  ? (string)$att['check_in']  : '';
        $raw_out  = ($att && !empty($att['check_out'])) ? (string)$att['check_out'] : '';
        $time_in  = $raw_in !== ''  ? substr($raw_in, 0, 5)  : '';
        $time_out = $raw_out !== '' ? substr($raw_out, 0, 5) : '';

        $hours = NULL;
        if ($att && isset($att['hours_worked']) && $att['hours_worked'] !== NULL && $att['hours_worked'] !== '' && is_numeric($att['hours_worked'])) {
            $hours = round((float)$att['hours_worked'], 2);
        } elseif ($raw_in !== '' && $raw_out !== '') {
            $hours = wms_hours_between($raw_in, $raw_out);
        }

        $note = ($att && isset($att['notes']) && $att['notes'] !== NULL) ? trim((string)$att['notes']) : '';
        if ($auto_leave_prefix !== '' && strpos($note, $auto_leave_prefix) === 0) {
            $note = '';
        }

        $leave_label = isset($leave_by_date[$iso]) ? strtoupper(trim((string)$leave_by_date[$iso])) : '';
        $observation = '';
        if ($status === 'Leave') {
            $base = $leave_label !== '' ? $leave_label : 'LEAVE';
            $observation = ($note !== '' && stripos($note, $base) === FALSE) ? ($base . ' — ' . $note) : ($note !== '' ? $note : $base);
        } elseif ($status === 'Rest') {
            $observation = $note !== '' ? $note : 'REST';
        } elseif ($status === 'Holiday') {
            $observation = $note !== '' ? $note : 'HOLIDAY';
        } elseif ($status === 'Absent') {
            $observation = $note !== '' ? ('ABSENT - ' . $note) : 'ABSENT';
        } else {
            $observation = $note;
        }

        $worked = ($status === 'Present') || ($time_in !== '') || ($hours !== NULL && $hours > 0);
        if ($worked) $days_worked++;

        if ($hours !== NULL && $hours > 0) {
            $cur_hours   += $hours;
            $total_hours += $hours;
        }

        $cur_days[] = array(
            'date_iso'    => $iso,
            'dow'         => $dow,
            'day_name'    => strtoupper(date('l', $ts)),
            'date_label'  => date('d/M/y', $ts),
            'is_sunday'   => ($dow === 7),
            'status'      => $status,
            'time_in'     => $time_in,
            'time_out'    => $time_out,
            'hours'       => $hours,
            'hours_label' => wms_timesheet_format_hours($hours),
            'worked'      => $worked,
            'observation' => $observation,
        );

        $day_count++;
        $ts = strtotime('+1 day', $ts);
    }

    if (!empty($cur_days)) {
        $weeks[] = array(
            'days'        => $cur_days,
            'hours'       => round($cur_hours, 2),
            'hours_label' => wms_timesheet_format_hours($cur_hours),
        );
    }

    return array(
        'start_date'        => $start_date,
        'end_date'          => $end_date,
        'weeks'             => $weeks,
        'days_worked'       => $days_worked,
        'total_days_worked' => $days_worked,
        'total_hours'       => round($total_hours, 2),
        'total_hours_label' => wms_timesheet_format_hours($total_hours),
        'day_count'         => $day_count,
    );
}


