<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Login throttling / lockout (audit finding F-19).
 *
 * Policy — both limits are checked, whichever trips first:
 *
 *   · per account + source IP : MAX_ATTEMPTS  failures inside WINDOW_SECONDS
 *   · per account (any IP)    : MAX_ATTEMPTS_ACCOUNT failures inside WINDOW_SECONDS
 *
 * The account-wide limit exists because an attacker can rotate source IPs but
 * targets one mailbox; the per-IP limit keeps one noisy client from locking an
 * account for everybody else. Counters are time-based, so a locked account
 * unlocks by itself once the window has passed — no manual intervention.
 *
 * There is a third limit, which is about a different attack:
 *
 *   · per source IP, across ALL accounts : MAX_ATTEMPTS_IP failures inside the
 *     window, and only when they touched at least MIN_SPRAY_ACCOUNTS different
 *     accounts. The account limits above stop someone hammering ONE account;
 *     they do nothing about credential stuffing, where an attacker feeds a
 *     leaked password list through the whole user list with one guess each.
 *     That pattern leaves no account over its own threshold, but it does leave
 *     a footprint: many accounts tried from one source.
 *
 * Why the distinct-account condition matters
 * ──────────────────────────────────────────
 * A workshop often reaches the internet through one address, so "too many
 * failures from this IP" alone would let one forgetful colleague lock out the
 * whole office. Requiring several DIFFERENT accounts makes an ordinary fumble
 * (one account, repeatedly) invisible to this rule — it is caught by the
 * per-account limit instead — while a spray across the user list trips it.
 * If the site sits behind a proxy or CDN and every request appears to come
 * from the proxy's address, the limit is counter-productive and can be turned
 * off with the `login_ip_cap_enabled` setting (0 = off); the account limits
 * keep working either way.
 *
 * Visibility (the second half of F-19)
 * ────────────────────────────────────
 * Every lockout and spray detection is written to `security_events` and
 * surfaced at Settings → Sign-in security for the supervisor — with the count
 * on the sidebar — because a throttling rule that fires silently is a rule
 * nobody acts on. Recording degrades to nothing while the security-events
 * table is missing.
 *
 * Both tables (`login_attempts`, `security_events`) are part of the shipped
 * database schema. While they are missing the model reports
 * "not locked" and records no events, instead of raising an error page, so
 * deploying this file first cannot take the sign-in screen down.
 *
 * Nothing in this class is a substitute for the account-level controls in
 * MY_Controller: it only slows down online password guessing.
 */
class Auth_throttle_model extends CI_Model {

    /** Failed attempts from one IP against one account inside the window. */
    const MAX_ATTEMPTS = 5;

    /** Failed attempts against one account from any source inside the window. */
    const MAX_ATTEMPTS_ACCOUNT = 20;

    /** Sliding window, and therefore also the lockout duration (seconds). */
    const WINDOW_SECONDS = 900; // 15 minutes

    /**
     * Failed attempts from ONE SOURCE across all accounts inside the window.
     * Only counts when MIN_SPRAY_ACCOUNTS different accounts were touched —
     * see the note at the top of the class.
     */
    const MAX_ATTEMPTS_IP = 30;

    /** Different accounts one source must touch before the IP limit applies. */
    const MIN_SPRAY_ACCOUNTS = 3;

    /** Retained rows are pruned opportunistically on insert. */
    const RETENTION_SECONDS = 86400; // 24 hours

    /** Security events are kept longer than raw attempts: 90 days. */
    const EVENT_RETENTION_SECONDS = 7776000;

    /** Cached table_exists() results for this request. */
    private $table_ready = NULL;
    private $events_ready = NULL;

    private function ready() {
        if ($this->table_ready === NULL) {
            $this->table_ready = $this->table_exists('login_attempts');
        }
        return $this->table_ready;
    }

    /** Is the `security_events` visibility table present? */
    private function events_table_ready() {
        if ($this->events_ready === NULL) {
            $this->events_ready = $this->table_exists('security_events');
        }
        return $this->events_ready;
    }

    /**
     * table_exists(), but never fatal.
     *
     * `table_exists()` lists every table and therefore runs a query; when that
     * query fails — database unreachable, user without the grant, schema not
     * imported — the driver hands back FALSE and the listing calls a method on
     * it. That is an Error, not an Exception, so an unguarded call here used to
     * take the whole sign-in screen down with a blank HTTP 500 before the
     * password was ever checked. "Cannot tell" is treated as "not available",
     * which is exactly how the model already behaves towards a missing table:
     * throttling steps aside rather than breaking the door.
     */
    private function table_exists($table) {
        try {
            return (bool) $this->db->table_exists($table);
        } catch (Throwable $e) {
            log_message('error', 'WMS: could not check for the `'.$table.'` table — the database is '
                .'not answering. Sign-in throttling is disabled for this request.');
            return FALSE;
        }
    }

    /**
     * The source-IP limit is on by default. A site behind a proxy or CDN that
     * is not configured with `proxy_ips` sees every visitor from one address,
     * where this rule does more harm than good — the setting turns it off.
     */
    public function ip_cap_enabled() {
        try {
            if (!isset($this->Settings_model)) $this->load->model('Settings_model');
            return (int)$this->Settings_model->get('login_ip_cap_enabled', 1) !== 0;
        } catch (Exception $e) {
            return TRUE;   // no settings model, no reason to weaken the rule
        } catch (Throwable $e) {
            // Same answer, different cause: an unreadable settings table makes
            // the read fail with an Error rather than an Exception. The rule
            // must not silently switch itself off because the database is
            // unwell — it is checkable again as soon as the database is.
            return TRUE;
        }
    }

    private function ip() {
        $ip = (string) $this->input->ip_address();
        return substr($ip, 0, 45);
    }

    private function normalize_email($email) {
        return substr(strtolower((string)(trim((string) $email))), 0, 150);
    }

    private function window_start() {
        return date('Y-m-d H:i:s', time() - self::WINDOW_SECONDS);
    }

    /**
     * TRUE when this (account, IP) pair, or the account as a whole, has failed
     * too often inside the window.
     */
    public function is_locked($email) {
        if ( ! $this->ready()) return FALSE;

        $email = $this->normalize_email($email);
        if ($email === '') return FALSE;

        $since = $this->window_start();

        $pair = $this->db->where('email', $email)
                         ->where('ip_address', $this->ip())
                         ->where('attempted_at >=', $since)
                         ->count_all_results('login_attempts');
        if ($pair >= self::MAX_ATTEMPTS) return TRUE;

        $account = $this->db->where('email', $email)
                            ->where('attempted_at >=', $since)
                            ->count_all_results('login_attempts');
        if ($account >= self::MAX_ATTEMPTS_ACCOUNT) return TRUE;

        // Credential stuffing: one source working through many accounts, each
        // one under its own threshold (see the note at the top of the class).
        $spray = $this->source_attempts($since);
        if ($spray['attempts'] >= self::MAX_ATTEMPTS_IP
            && $spray['accounts'] >= self::MIN_SPRAY_ACCOUNTS) {
            $this->record_event('ip_spray', $this->ip(), $spray['attempts'], $spray['accounts']);
            return TRUE;
        }

        return FALSE;
    }

    /**
     * What this source has been doing: how many failures, how many accounts.
     * One query, because both figures come from the same window.
     */
    public function source_attempts($since = null) {
        $since = $since ?: $this->window_start();
        $rows  = $this->db->select('COUNT(*) AS attempts, COUNT(DISTINCT email) AS accounts')
                          ->where('ip_address', $this->ip())
                          ->where('attempted_at >=', $since)
                          ->get('login_attempts')->row();

        return array(
            'attempts' => $rows ? (int)$rows->attempts : 0,
            'accounts' => $rows ? (int)$rows->accounts : 0,
        );
    }

    /** Record one failed attempt. Never throws on a missing table. */
    public function record_failure($email) {
        if ( ! $this->ready()) return;

        $email = $this->normalize_email($email);
        if ($email === '') return;

        $this->db->insert('login_attempts', array(
            'email'        => $email,
            'ip_address'   => $this->ip(),
            'attempted_at' => date('Y-m-d H:i:s'),
        ));

        // Opportunistic housekeeping: keeps the table small without a cron job.
        $this->db->where('attempted_at <', date('Y-m-d H:i:s', time() - self::RETENTION_SECONDS))
                 ->delete('login_attempts');

        // Tell somebody. This is where an account reaches its limit, so this is
        // the moment the event is worth recording — not when the next attempt
        // happens to bounce off it.
        $since = $this->window_start();

        $pair = $this->db->where('email', $email)
                         ->where('ip_address', $this->ip())
                         ->where('attempted_at >=', $since)
                         ->count_all_results('login_attempts');
        $account = $this->db->where('email', $email)
                            ->where('attempted_at >=', $since)
                            ->count_all_results('login_attempts');

        if ($pair >= self::MAX_ATTEMPTS || $account >= self::MAX_ATTEMPTS_ACCOUNT) {
            $reason = $pair >= self::MAX_ATTEMPTS
                ? $pair . ' failures for this account from one source'
                : $account . ' failures for this account from any source';
            $this->record_event('account_lockout', $email, $account, NULL, $reason);
        }

        if ($this->ip_cap_enabled()) {
            $source = $this->source_attempts($since);
            if ($source['attempts'] >= self::MAX_ATTEMPTS_IP
                && $source['accounts'] >= self::MIN_SPRAY_ACCOUNTS) {
                $this->record_event('ip_spray', $this->ip(), $source['attempts'], $source['accounts']);
            }
        }
    }

    /* ── visibility: what happened, for a person to read ───────────────── */

    /**
     * Record one security event, at most once per subject per window.
     *
     * The dedupe matters: an attacker keeps trying after the lock trips, and
     * forty identical rows would bury the one that mattered. The first row in
     * a window is the event; the rest are noise the counters already hold.
     */
    public function record_event($type, $subject, $attempts = 0, $distinct = NULL, $details = NULL) {
        if (!$this->events_table_ready()) return FALSE;

        $subject = substr((string)$subject, 0, 150);
        $since   = $this->window_start();

        $seen = $this->db->where('event_type', $type)
                         ->where('subject', $subject)
                         ->where('created_at >=', $since)
                         ->count_all_results('security_events');
        if ($seen > 0) return FALSE;

        $this->db->insert('security_events', array(
            'event_type'        => $type,
            'subject'           => $subject,
            'ip_address'        => $this->ip(),
            'attempts'          => (int)$attempts,
            'distinct_accounts' => $distinct === NULL ? NULL : (int)$distinct,
            'window_seconds'    => self::WINDOW_SECONDS,
            'details'           => $details === NULL ? NULL : substr((string)$details, 0, 255),
            'created_at'        => date('Y-m-d H:i:s'),
        ));

        // A line in application/logs as well, so an operator watching the log
        // during an incident sees it live. The address is masked: the log file
        // sits under the web root on this host, and a readable list of staff
        // e-mail addresses there would be its own finding.
        log_message('error', 'sign-in security: ' . $type . ' — ' . $this->mask($subject)
            . ' from ' . $this->ip() . ' (' . (int)$attempts . ' failures'
            . ($distinct === NULL ? '' : ', ' . (int)$distinct . ' accounts') . ')');

        // Opportunistic housekeeping, same idea as the attempts table.
        $this->db->where('created_at <', date('Y-m-d H:i:s', time() - self::EVENT_RETENTION_SECONDS))
                 ->delete('security_events');

        return TRUE;
    }

    /** a.someone@example.com — enough to recognise, not enough to harvest. */
    private function mask($subject) {
        if (strpos($subject, '@') === FALSE) return $subject;      // an IP address
        list($local, $domain) = explode('@', $subject, 2);
        return substr($local, 0, 1) . '***@' . $domain;
    }

    /** Unacknowledged events, newest first. Empty when 015 is not applied. */
    public function recent_events($limit = 50, $only_open = FALSE) {
        if (!$this->events_table_ready()) return array();
        if ($only_open) $this->db->where('acknowledged_at IS NULL', NULL, FALSE);
        return $this->db->order_by('created_at', 'DESC')
                        ->limit((int)$limit)
                        ->get('security_events')->result();
    }

    /** How many events are waiting for somebody to look at them. */
    public function unacknowledged_count() {
        if (!$this->events_table_ready()) return 0;
        return (int)$this->db->where('acknowledged_at IS NULL', NULL, FALSE)
                             ->count_all_results('security_events');
    }

    /** Counts for the last N hours, by type. */
    public function event_counts($hours = 24) {
        if (!$this->events_table_ready()) return array();
        $rows = $this->db->select('event_type, COUNT(*) AS n')
                         ->where('created_at >=', date('Y-m-d H:i:s', time() - ((int)$hours * 3600)))
                         ->group_by('event_type')
                         ->get('security_events')->result();
        $out = array('account_lockout' => 0, 'ip_spray' => 0);
        foreach ($rows as $r) $out[$r->event_type] = (int)$r->n;
        return $out;
    }

    /** Mark one event as seen, with a note about what was done. */
    public function acknowledge($id, $user_id, $note = NULL) {
        if (!$this->events_table_ready()) return FALSE;
        $id = (int)$id;
        if ($id <= 0) return FALSE;

        $row = $this->db->select('id, acknowledged_at')->where('id', $id)->get('security_events')->row();
        if (!$row) return FALSE;
        if ($row->acknowledged_at !== NULL) return TRUE;   // already done; not an error

        $this->db->update('security_events', array(
            'acknowledged_by'   => (int)$user_id,
            'acknowledged_at'   => date('Y-m-d H:i:s'),
            'acknowledged_note' => $note === NULL ? NULL : substr((string)$note, 0, 255),
        ), array('id' => $id));

        return $this->db->affected_rows() > 0;
    }

    /** The policy this installation is running, for the screen to print. */
    public function policy() {
        return array(
            'pair_attempts'    => self::MAX_ATTEMPTS,
            'account_attempts' => self::MAX_ATTEMPTS_ACCOUNT,
            'source_attempts'  => self::MAX_ATTEMPTS_IP,
            'spray_accounts'   => self::MIN_SPRAY_ACCOUNTS,
            'window_minutes'   => (int)(self::WINDOW_SECONDS / 60),
            'ip_cap_enabled'   => $this->ip_cap_enabled(),
        );
    }

    /** Clear the counter for an account after a successful sign-in. */
    public function clear($email) {
        if ( ! $this->ready()) return;

        $email = $this->normalize_email($email);
        if ($email === '') return;

        $this->db->where('email', $email)->delete('login_attempts');
    }
}
