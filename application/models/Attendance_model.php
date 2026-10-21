<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Attendance punches, geofenced sites and work-order eligibility.
 *
 * Design (ATTENDANCE_WORKFLOW.md)
 * -------------------------------
 * The `attendance` table keeps one row per worker per day — it is what the
 * monthly reports, the dashboard and the leave logic already read, and it is
 * *not* enough to audit a day of repeated punches. So punches are stored as
 * their own immutable events in `attendance_punches`, and the day row is
 * derived from them (`sync_attendance_row()`). Nothing here deletes a punch:
 * a supervisor correction is another event, with the reason and the author
 * attached, on top of the original history.
 *
 * A punch is only written when the controller has already accepted both the
 * QR token and the GPS position; this model never decides that.
 */
class Attendance_model extends CI_Model {

    /** Work-order statuses that still hold a technician (SYSTEM.md §7). */
    const UNFINISHED_STATUSES = array('Open', 'In Progress', 'Waiting Parts', 'Waiting Tools');

    /** Roles that may be assigned to work orders: 1 = Supervisor, 2 = Technician. */
    const TECHNICIAN_ROLES = array(1, 2);

    /** Active staff roles permitted to record site attendance punches: Supervisor, Technician, Store Keeper. */
    const PUNCH_ROLES = array(1, 2, 3);

    /** Per-request memo: the picker asks for every technician's verdict. */
    protected $_cache = array();

    /** Drop the memo after anything that changes the day's punches. */
    protected function _flush() {
        $this->_cache = array();
    }

    /* ═════════════════════════════════════════════════════════════════
       Sites & their geofences
       ═════════════════════════════════════════════════════════════════ */

    /** Are the attendance tables present? Everything degrades when not. */
    public function ready() {
        try {
            return $this->db->table_exists('attendance_sites')
                && $this->db->table_exists('attendance_punches');
        } catch (Throwable $e) {
            return FALSE;
        }
    }

    /** @return array site rows, ordered by name */
    public function get_sites($active_only = FALSE) {
        if (!$this->db->table_exists('attendance_sites')) return array();
        if ($active_only) $this->db->where('is_active', 1);
        return $this->db->order_by('name', 'ASC')->get('attendance_sites')->result();
    }

    /** @return object|null */
    public function get_site($id) {
        if (!$this->db->table_exists('attendance_sites')) return NULL;
        return $this->db->where('id', (int)$id)->get('attendance_sites')->row();
    }

    /** The site the site-screen should default to (first active one). */
    public function default_site() {
        $sites = $this->get_sites(TRUE);
        return $sites ? $sites[0] : NULL;
    }

    /**
     * Create a site with a fresh random QR secret.
     *
     * @param  array $data name, latitude, longitude, radius_m, max_accuracy_m, is_active
     * @return int inserted id
     */
    public function create_site(array $data) {
        $data['qr_secret']  = bin2hex(random_bytes(32));
        $data['created_by'] = isset($data['created_by']) ? (int)$data['created_by'] : NULL;
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');
        $this->db->insert('attendance_sites', $data);
        return (int)$this->db->insert_id();
    }

    /**
     * Update a site. The QR secret is never taken from the caller: it only
     * changes through regenerate_secret().
     *
     * @param  int   $id
     * @param  array $data
     * @return bool
     */
    public function update_site($id, array $data) {
        unset($data['qr_secret'], $data['id']);
        $data['updated_at'] = date('Y-m-d H:i:s');
        return (bool)$this->db->where('id', (int)$id)->update('attendance_sites', $data);
    }

    /**
     * Rotate a site's secret. Every token already displayed stops verifying,
     * including screenshots taken off site.
     *
     * @return string|false the new secret
     */
    public function regenerate_secret($id) {
        $secret = bin2hex(random_bytes(32));
        $ok = $this->db->where('id', (int)$id)->update('attendance_sites', array(
            'qr_secret'  => $secret,
            'updated_at' => date('Y-m-d H:i:s'),
        ));
        return $ok ? $secret : FALSE;
    }

    /* ═════════════════════════════════════════════════════════════════
       Punches
       ═════════════════════════════════════════════════════════════════ */

    /**
     * Write one accepted punch.
     *
     * @param  array $p user_id, site_id, work_date, punch_type, punched_at,
     *                  origin (self|supervisor), latitude, longitude,
     *                  accuracy_m, distance_m, radius_m, ip_address,
     *                  user_agent, reason, created_by
     * @return int inserted id
     */
    public function record_punch(array $p) {
        $insert = array(
            'user_id'    => (int)$p['user_id'],
            'site_id'    => !empty($p['site_id']) ? (int)$p['site_id'] : NULL,
            'work_date'  => $p['work_date'],
            'punch_type' => ($p['punch_type'] === 'check_out') ? 'check_out' : 'check_in',
            'punched_at' => $p['punched_at'],
            'origin'     => (isset($p['origin']) && $p['origin'] === 'supervisor') ? 'supervisor' : 'self',
            'latitude'   => isset($p['latitude'])   ? $p['latitude']   : NULL,
            'longitude'  => isset($p['longitude'])  ? $p['longitude']  : NULL,
            'accuracy_m' => isset($p['accuracy_m']) ? $p['accuracy_m'] : NULL,
            'distance_m' => isset($p['distance_m']) ? $p['distance_m'] : NULL,
            'radius_m'   => isset($p['radius_m'])   ? (int)$p['radius_m'] : NULL,
            'ip_address' => isset($p['ip_address']) ? substr((string)$p['ip_address'], 0, 45) : NULL,
            'user_agent' => isset($p['user_agent']) ? substr((string)$p['user_agent'], 0, 255) : NULL,
            'reason'     => isset($p['reason']) && $p['reason'] !== '' ? substr((string)$p['reason'], 0, 300) : NULL,
            'created_by' => isset($p['created_by']) ? (int)$p['created_by'] : NULL,
            'created_at' => isset($p['created_at']) ? $p['created_at'] : date('Y-m-d H:i:s'),
        );

        $this->db->insert('attendance_punches', $insert);
        $this->_flush();
        return (int)$this->db->insert_id();
    }

    /**
     * Every accepted punch of one day, oldest first.
     *
     * @param  string   $date    'Y-m-d'
     * @param  int|null $user_id restrict to one worker
     * @return array
     */
    public function get_punches($date, $user_id = NULL) {
        if (!$this->db->table_exists('attendance_punches')) return array();
        $this->db->select('p.*, s.name AS site_name, s.radius_m AS site_radius_m, '
                        . 'u.first_name, u.last_name, u.employee_id, '
                        . 'cb.first_name AS by_first_name, cb.last_name AS by_last_name')
            ->from('attendance_punches p')
            ->join('attendance_sites s', 's.id = p.site_id', 'left')
            ->join('users u', 'u.id = p.user_id', 'left')
            ->join('users cb', 'cb.id = p.created_by', 'left')
            ->where('p.work_date', $date)
            ->order_by('p.user_id', 'ASC')->order_by('p.punched_at', 'ASC')->order_by('p.id', 'ASC');

        if ($user_id !== NULL) $this->db->where('p.user_id', (int)$user_id);
        return $this->db->get()->result();
    }

    /**
     * The latest accepted punch of a worker on a day, or NULL.
     *
     * @return object|null
     */
    public function latest_punch($user_id, $date = NULL) {
        if (!$this->db->table_exists('attendance_punches')) return NULL;
        $date = $date ?: date('Y-m-d');
        return $this->db->where('user_id', (int)$user_id)->where('work_date', $date)
            ->order_by('punched_at', 'DESC')->order_by('id', 'DESC')
            ->limit(1)->get('attendance_punches')->row();
    }

    /**
     * Punch types of one worker on one day, in order. Feeds
     * wms_attendance_on_site() / wms_attendance_next_punch().
     *
     * @return array
     */
    public function punch_types($user_id, $date = NULL) {
        if (!$this->db->table_exists('attendance_punches')) return array();
        $date = $date ?: date('Y-m-d');
        $rows = $this->db->select('punch_type')->where('user_id', (int)$user_id)
            ->where('work_date', $date)
            ->order_by('punched_at', 'ASC')->order_by('id', 'ASC')
            ->get('attendance_punches')->result();
        $types = array();
        foreach ($rows as $r) $types[] = $r->punch_type;
        return $types;
    }

    /**
     * Who is on site at the end of $date, derived from the punches.
     *
     * @param  string $date
     * @return array user_id => last punch row (only workers still checked in)
     */
    public function on_site_map($date = NULL) {
        if (!$this->db->table_exists('attendance_punches')) return array();
        $date = $date ?: date('Y-m-d');
        if (isset($this->_cache['on_site'][$date])) return $this->_cache['on_site'][$date];
        $rows = $this->db->select('p.*, s.name AS site_name, u.first_name, u.last_name, u.employee_id')
            ->from('attendance_punches p')
            ->join('attendance_sites s', 's.id = p.site_id', 'left')
            ->join('users u', 'u.id = p.user_id', 'left')
            ->where('p.work_date', $date)
            ->order_by('p.user_id', 'ASC')->order_by('p.punched_at', 'ASC')->order_by('p.id', 'ASC')
            ->get()->result();

        $last = array();
        foreach ($rows as $r) $last[(int)$r->user_id] = $r;

        $on_site = array();
        foreach ($last as $uid => $row) {
            if ($row->punch_type === 'check_in') $on_site[$uid] = $row;
        }
        $this->_cache['on_site'][$date] = $on_site;
        return $on_site;
    }

    /**
     * Is this worker on site at the end of $date?
     *
     * @return bool
     */
    public function is_on_site($user_id, $date = NULL) {
        $map = $this->on_site_map($date);
        return isset($map[(int)$user_id]);
    }

    /* ═════════════════════════════════════════════════════════════════
       The derived day row (keeps the rest of the system consistent)
       ═════════════════════════════════════════════════════════════════ */

    /**
     * Rebuild the `attendance` day row for one worker from their punches.
     *
     * The day row is what the monthly report, the dashboard and the leave
     * logic read, so a successful punch must be visible there immediately:
     * first check-in becomes `check_in`, the last check-out after it becomes
     * `check_out`, and the status becomes Present.
     *
     * Two things are deliberately left alone:
     *   • an approved-leave row — leave wins over an odd punch, and the
     *     marker in `notes` belongs to the leave request, not to us;
     *   • `notes` generally — it is a human note, not a punch field.
     *
     * @return bool TRUE when a row was written
     */
    public function sync_attendance_row($user_id, $date = NULL) {
        $user_id = (int)$user_id;
        $date    = $date ?: date('Y-m-d');
        if ($user_id <= 0 || !$this->db->table_exists('attendance_punches')) return FALSE;

        $punches = $this->get_punches($date, $user_id);
        if (empty($punches)) return FALSE;

        $row = $this->db->select('id, status, notes, created_by')
            ->where('user_id', $user_id)->where('date', $date)
            ->get('attendance')->row();

        if ($row && $row->status === 'Leave') return FALSE;

        $summary = wms_attendance_day_summary($punches);

        $payload = array(
            'status'      => 'Present',
            'check_in'    => $summary['check_in'],
            'check_out'   => $summary['check_out'],
            'hours_worked'=> $summary['hours_worked'],
        );

        if ($row) {
            $this->db->where('id', $row->id)->update('attendance', $payload);
        } else {
            $payload['user_id']    = $user_id;
            $payload['date']       = $date;
            $payload['created_by'] = $user_id;
            $this->db->insert('attendance', $payload);
        }
        return TRUE;
    }

    /**
     * Scrub raw personal location & client evidence on punch events older than
     * the configured retention window while preserving the immutable punch
     * event audit rows and daily `attendance` summaries (ATTENDANCE_WORKFLOW.md).
     *
     * @param  int $retention_days 0 = retain indefinitely; >= 30 scrubs older rows
     * @return int affected rows
     */
    public function prune_old_location_evidence($retention_days) {
        $days = (int)$retention_days;
        if ($days < 30 || !$this->db->table_exists('attendance_punches')) return 0;

        $cutoff = date('Y-m-d', strtotime('-' . $days . ' days'));
        $this->db->where('work_date <', $cutoff)
            ->group_start()
                ->where('latitude IS NOT NULL', NULL, FALSE)
                ->or_where('longitude IS NOT NULL', NULL, FALSE)
                ->or_where('ip_address IS NOT NULL', NULL, FALSE)
                ->or_where('user_agent IS NOT NULL', NULL, FALSE)
            ->group_end()
            ->update('attendance_punches', array(
                'latitude'   => NULL,
                'longitude'  => NULL,
                'accuracy_m' => NULL,
                'ip_address' => NULL,
                'user_agent' => NULL,
            ));
        return (int)$this->db->affected_rows();
    }

    /**
     * Re-verify against the live `users` table that a signed-in session belongs
     * to an active, eligible worker allowed to punch (ATTENDANCE_WORKFLOW.md
     * → "Technician check-in", step 4).
     *
     * @param  int $user_id
     * @return array{ok:bool,user:object|null,message:string}
     */
    public function punchable_user($user_id) {
        $user_id = (int)$user_id;
        if ($user_id <= 0 || !$this->db->table_exists('users')) {
            return array('ok' => FALSE, 'user' => NULL, 'message' => 'Your user account could not be verified. Sign in again.');
        }

        $user = $this->db->select('id, first_name, last_name, role_id, status, employee_id')
            ->where('id', $user_id)->get('users')->row();

        if (!$user) {
            return array('ok' => FALSE, 'user' => NULL, 'message' => 'Your user account no longer exists. Nothing was recorded.');
        }
        if ($user->status !== 'Active') {
            return array('ok' => FALSE, 'user' => $user, 'message' => 'Your user account is inactive and cannot record attendance punches.');
        }
        if (!in_array((int)$user->role_id, self::PUNCH_ROLES, TRUE)) {
            return array('ok' => FALSE, 'user' => $user, 'message' => 'Your role is view-only and does not record site punches.');
        }

        return array('ok' => TRUE, 'user' => $user, 'message' => 'OK');
    }

    /**
     * Active punch-capable staff (including Store Keepers alongside Technicians)
     * for the Supervisor correction form on `attendance/log`, so any worker who
     * punched in can always have a missed check-out/check-in corrected.
     *
     * @return array
     */
    public function workers_for_correction() {
        return $this->db->select('u.id, u.first_name, u.last_name, u.position, u.employee_id, u.role_id')
            ->from('users u')
            ->where_in('u.role_id', self::PUNCH_ROLES)
            ->where('u.status', 'Active')
            ->order_by('u.first_name', 'ASC')->order_by('u.last_name', 'ASC')
            ->get()->result();
    }

    /* ═════════════════════════════════════════════════════════════════
       Work-order eligibility
       ═════════════════════════════════════════════════════════════════ */

    /**
     * Unfinished work orders each technician is currently named on, as
     * technician id => list of WO numbers.
     *
     * `work_orders.assigned_to` is a comma-separated CSV of user ids (with the
     * spaces the form writes: "3, 5, 7"), which FIND_IN_SET cannot read, so
     * the comparison is done in PHP over the unfinished rows only.
     *
     * @return array<int, array<string>>
     */
    public function unfinished_assignment_map() {
        if (isset($this->_cache['unfinished'])) return $this->_cache['unfinished'];

        $map = array();
        if (!$this->db->table_exists('work_orders')) {
            $this->_cache['unfinished'] = $map;
            return $map;
        }

        $rows = $this->db->select('id, wo_number, assigned_to, status')
            ->where_in('status', self::UNFINISHED_STATUSES)
            ->where('assigned_to IS NOT NULL', NULL, FALSE)
            ->where('assigned_to !=', '')
            ->get('work_orders')->result();

        foreach ($rows as $wo) {
            $ids = preg_split('/[^0-9]+/', (string)$wo->assigned_to, -1, PREG_SPLIT_NO_EMPTY);
            foreach (array_unique($ids) as $id) {
                $id = (int)$id;
                if ($id <= 0) continue;
                if (!isset($map[$id])) $map[$id] = array();
                $map[$id][] = (string)$wo->wo_number;
            }
        }
        $this->_cache['unfinished'] = $map;
        return $map;
    }

    /**
     * The work order a technician is currently clocked on, if any.
     *
     * @return object|null
     */
    public function open_session($user_id) {
        if (!$this->db->table_exists('wo_sessions')) return NULL;
        return $this->db->select('s.id, s.wo_id, s.start_datetime, w.wo_number')
            ->from('wo_sessions s')
            ->join('work_orders w', 'w.id = s.wo_id', 'left')
            ->where('s.technician_id', (int)$user_id)
            ->where('s.status', 'Open')
            ->order_by('s.start_datetime', 'DESC')
            ->limit(1)->get()->row();
    }

    /**
     * The full eligibility verdict for one technician on one day.
     *
     * Rules (ATTENDANCE_WORKFLOW.md → "Work-order assignment eligibility"):
     *   • active account (checked by the caller / active_technicians())
     *   • checked in today with no later check-out
     *   • not named on an unfinished work order
     *   • not clocked on an open work session
     *
     * @return array{eligible:bool,on_site:bool,reasons:string[],work_orders:string[],session:object|null}
     */
    public function eligibility($user_id, $date = NULL) {
        $user_id = (int)$user_id;
        $date    = $date ?: date('Y-m-d');
        $reasons = array();

        $on_site = $this->is_on_site($user_id, $date);
        if (!$on_site) {
            $latest = $this->latest_punch($user_id, $date);
            $reasons[] = $latest
                ? 'Checked out at ' . date('H:i', strtotime((string)$latest->punched_at)) . ' — not on site'
                : 'Not checked in today';
        }

        $map = $this->unfinished_assignment_map();
        $wos = isset($map[$user_id]) ? $map[$user_id] : array();
        if (!empty($wos)) {
            $reasons[] = 'Assigned to unfinished work order' . (count($wos) > 1 ? 's ' : ' ')
                       . implode(', ', array_slice($wos, 0, 3))
                       . (count($wos) > 3 ? ' …' : '');
        }

        $session = $this->open_session($user_id);
        if ($session) {
            $reasons[] = 'Work session still open'
                       . (!empty($session->wo_number) ? ' on ' . $session->wo_number : '')
                       . ' — close it first';
        }

        return array(
            'eligible'   => empty($reasons),
            'on_site'    => $on_site,
            'reasons'    => $reasons,
            'work_orders'=> $wos,
            'session'    => $session,
        );
    }

    /**
     * Active technicians with their eligibility attached.
     *
     * @param  string $date
     * @param  bool   $only_eligible
     * @return array list of user rows with ->on_site, ->eligible, ->block_reasons
     */
    public function technicians_for_assignment($date = NULL, $only_eligible = FALSE) {
        $date = $date ?: date('Y-m-d');
        $rows = $this->db->select('u.id, u.first_name, u.last_name, u.position, u.employee_id, u.role_id')
            ->from('users u')
            ->where_in('u.role_id', self::TECHNICIAN_ROLES)
            ->where('u.status', 'Active')
            ->order_by('u.first_name', 'ASC')->order_by('u.last_name', 'ASC')
            ->get()->result();

        $out = array();
        foreach ($rows as $r) {
            $e = $this->eligibility($r->id, $date);
            $r->on_site       = $e['on_site'];
            $r->eligible      = $e['eligible'];
            $r->block_reasons = $e['reasons'];
            if ($only_eligible && !$e['eligible']) continue;
            $out[] = $r;
        }
        return $out;
    }

    /**
     * Which of the posted technician ids are not eligible?
     *
     * Used by the work-order save handlers: hiding a name in the picker is not
     * enough, so the same rule is applied to what actually arrives.
     *
     * @param  string   $csv  comma-separated user ids from the form
     * @param  string   $date
     * @return array list of human-readable problems (empty = all good)
     */
    public function assignment_problems($csv, $date = NULL) {
        $date = $date ?: date('Y-m-d');
        $problems = array();
        $ids = preg_split('/[^0-9]+/', (string)$csv, -1, PREG_SPLIT_NO_EMPTY);

        foreach (array_unique($ids) as $id) {
            $id = (int)$id;
            if ($id <= 0) continue;

            $user = $this->db->select('id, first_name, last_name, role_id, status')
                ->where('id', $id)->get('users')->row();

            if (!$user) {
                $problems[] = 'Technician #' . $id . ' does not exist.';
                continue;
            }
            $name = trim((string)($user->first_name . ' ' . $user->last_name)) ?: ('#' . $id);

            if (!in_array((int)$user->role_id, self::TECHNICIAN_ROLES, TRUE) || $user->status !== 'Active') {
                $problems[] = $name . ' is not an active technician.';
                continue;
            }

            $e = $this->eligibility($id, $date);
            if (!$e['eligible']) {
                $problems[] = $name . ': ' . implode('; ', $e['reasons']) . '.';
            }
        }
        return $problems;
    }
}
