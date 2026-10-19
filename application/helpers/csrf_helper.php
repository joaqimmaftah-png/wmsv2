<?php
/**
 * CSRF fields for hand-written POST forms.
 *
 * `config/config.php` runs with `csrf_protection = TRUE`, and CodeIgniter
 * verifies the token on **every** POST *before* the controller action runs — a
 * form that posts without one is refused with a 403 and the user just sees
 * nothing happen. `form_open()` / `form_open_multipart()` add the field
 * themselves; a view that writes its own `<form>` tag needs this helper.
 *
 * The token name comes from the security class, never from a literal, and the
 * helper answers with an empty string when there is no application at hand (a
 * bare view render outside the framework) rather than sending a broken value.
 */

if (!function_exists('csrf_field')) {
    /**
     * @param  string|null $token a token the caller already resolved (CLI, cached pages)
     * @param  string|null $name  its field name — defaults to the configured one
     * @return string             the hidden input, or '' when there is nothing to send
     */
    function csrf_field($token = null, $name = null) {
        $ci  = function_exists('get_instance') ? get_instance() : null;
        $sec = ($ci && isset($ci->security)) ? $ci->security : null;

        if ($token === null && $sec) {
            $token = $sec->get_csrf_hash();
        }
        if ($name === null) {
            $name = $sec ? $sec->get_csrf_token_name() : 'csrf_token_wms';
        }

        if ($token === null || $token === '') return '';

        return '<input type="hidden" name="' . htmlspecialchars((string)$name, ENT_QUOTES, 'UTF-8')
             . '" value="' . htmlspecialchars((string)$token, ENT_QUOTES, 'UTF-8') . '">';
    }
}
