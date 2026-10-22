<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Session-safe view of the signed-in user (K-18).
 *
 * `Auth::login()` used to push the whole `users` row into the session:
 *
 *     'user_data' => $user        // includes ->password
 *
 * Sessions are stored in the `ci_sessions` table, so every signed-in user's
 * bcrypt/MD5 hash was written to the database on every login and again on
 * every session regeneration (the session row is rewritten periodically).
 * A read-only dump of `ci_sessions` was therefore as sensitive as `users`.
 *
 * The payload below is the subset the application actually reads. Keep it in
 * sync if a screen starts using another column — anything not listed here
 * simply will not exist in `$current_user`.
 */

if (!function_exists('wms_session_user')) {
    /**
     * Project a `users` row (object or array) onto the session whitelist.
     *
     * @param  object|array|null $row
     * @return stdClass
     */
    function wms_session_user($row)
    {
        static $fields = array(
            'id', 'role_id', 'employee_id',
            'first_name', 'last_name', 'email',
            'position', 'site', 'photo', 'signature', 'status',
        );

        $user = new stdClass();
        foreach ($fields as $field) {
            if (is_object($row) && isset($row->$field)) {
                $user->$field = $row->$field;
            } elseif (is_array($row) && isset($row[$field])) {
                $user->$field = $row[$field];
            } else {
                $user->$field = NULL;
            }
        }

        $user->full_name = trim((string)(trim((string) $user->first_name) . ' ' . trim((string) $user->last_name)));

        return $user;
    }
}

if (!function_exists('wms_user_full_name')) {
    /**
     * Display name from a session payload, a user object or a user array.
     * Export headers use this; it tolerates every shape the session has had.
     *
     * @param  mixed $user
     * @return string
     */
    function wms_user_full_name($user)
    {
        if (is_object($user)) {
            if (isset($user->full_name) && $user->full_name !== '') {
                return (string) $user->full_name;
            }
            $first = isset($user->first_name) ? (string) $user->first_name : '';
            $last  = isset($user->last_name)  ? (string) $user->last_name  : '';
            return trim((string)($first . ' ' . $last));
        }

        if (is_array($user)) {
            if (isset($user['full_name']) && $user['full_name'] !== '') {
                return (string) $user['full_name'];
            }
            $first = isset($user['first_name']) ? (string) $user['first_name'] : '';
            $last  = isset($user['last_name'])  ? (string) $user['last_name']  : '';
            return trim((string)($first . ' ' . $last));
        }

        return '';
    }
}
