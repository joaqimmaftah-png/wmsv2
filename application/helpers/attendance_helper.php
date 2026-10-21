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

