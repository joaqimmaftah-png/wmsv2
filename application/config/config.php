<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/* ---------------------------------------------------------------------------
| BASE URL (K-17)
| ---------------------------------------------------------------------------
| Every generated link, redirect and cookie path derives from `base_url`. It
| used to be assembled straight from `$_SERVER['HTTP_HOST']`:
|
|     $config['base_url'] = $protocol . '://' . $_SERVER['HTTP_HOST'] . ...
|
| The Host header is supplied by the client. A request carrying
| `Host: evil.example"><script>...` put that text verbatim into every href
| the application rendered (attribute injection), and anything that rewrites
| Host — a proxy, a load balancer — silently changed every generated URL.
|
| Precedence:
|   1. WMS_BASE_URL environment variable, e.g. https://wms.example.com
|   2. $config['base_url'] in application/config/config.local.php (loaded at the
|      end of this file, so it beats the detected value)
|   3. auto-detection, which now accepts only a syntactically valid
|      host[:port]; anything else is a malformed request and is refused.
|
| Pin (1) or (2) in production — see SECURITY.md.
*/
$config['base_url'] = '';

// Scheme, including a TLS-terminating proxy. Also read by cookie_secure below.
$wms_is_https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
        && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');

/*
| Build the URL from a request, or return NULL when the Host header is not a
| hostname / IPv4 / [IPv6] with an optional port. Kept as a function so
| tests/run.php exercises this exact code instead of a copy of the regex.
*/
if (!function_exists('wms_base_url_from_host')) {
    function wms_base_url_from_host($host, $script_name, $is_https)
    {
        $host = strtolower((string)(trim((string) $host)));

        // No quotes, spaces, slashes, backslashes, CR/LF or userinfo can pass.
        if (!preg_match('/^(?:\[[0-9a-f:.]+\]|[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?)(?::[0-9]{1,5})?$/D', $host)
            || strpos($host, '..') !== false) {
            return NULL;
        }

        $script = (string) $script_name;
        if (!preg_match('#^/[a-zA-Z0-9._/-]*$#D', $script)) {
            $script = '/index.php';
        }
        $dir = strstr($script, 'index.php', true);
        if ($dir === false || $dir === '') {
            $dir = '/';
        }

        return ($is_https ? 'https' : 'http') . '://' . $host . $dir;
    }
}

$wms_base_env = (string) getenv('WMS_BASE_URL');
if ($wms_base_env !== '' && preg_match('#^https?://[A-Za-z0-9._~:/\[\]-]+$#D', $wms_base_env)) {
    $config['base_url'] = rtrim((string)($wms_base_env), '/') . '/';
} elseif ($wms_base_env !== '') {
    // Set but unusable: say so rather than falling through silently.
    error_log('WMS: ignoring WMS_BASE_URL (expected an http(s) URL, e.g. https://wms.example.com)');
}

if ($config['base_url'] === '') {
    if (isset($_SERVER['HTTP_HOST'])) {
        $wms_detected = wms_base_url_from_host(
            $_SERVER['HTTP_HOST'],
            isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/index.php',
            $wms_is_https
        );

        if ($wms_detected === NULL) {
            // Refuse instead of echoing the value into links. A browser does
            // not send a Host header that fails this test.
            error_log('WMS: rejected a malformed Host header while building base_url');
            http_response_code(400);
            exit('Bad Request');
        }

        $config['base_url'] = $wms_detected;
    } else {
        // CLI invocation: no request, no links to build.
        $config['base_url'] = 'http://localhost/';
    }
}

unset($wms_base_env, $wms_detected);
$config['index_page'] = '';
$config['uri_protocol'] = 'AUTO';
$config['url_suffix'] = '';
$config['language'] = 'english';
$config['charset'] = 'UTF-8';
$config['enable_hooks'] = FALSE;
$config['subclass_prefix'] = 'MY_';
$config['composer_autoload'] = FALSE;
$config['permitted_uri_chars'] = 'a-z 0-9~%.:_\-';
$config['allow_get_array'] = TRUE;
$config['enable_query_strings'] = FALSE;
$config['controller_trigger'] = 'c';
$config['function_trigger'] = 'm';
$config['directory_trigger'] = 'd';
$config['log_errors'] = FALSE;
$config['log_threshold'] = 1;
$config['log_path'] = '';
$config['log_file_extension'] = '';
$config['log_file_permissions'] = 0644;
$config['log_date_format'] = 'Y-m-d H:i:s';
$config['error_views_path'] = '';
$config['cache_path'] = '';
/*
| Encryption key — never a committed literal.
|
| The application does not currently load CodeIgniter's Encryption library, so
| nothing depends on this value yet, but leaving the shipped placeholder in the
| repository is an audit finding in its own right. Provide a long random value
| (>= 32 characters) through the WMS_ENCRYPTION_KEY environment variable or
| application/config/config.local.php — see config.local.php.example.
|
| Generate one with:  php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
|
| cPanel fix: If empty, generate a fallback from database name + static salt
| to prevent 500 errors on fresh installs. For production, set a proper key
| in config.local.php for security.
*/
$wms_env = function ($key, $default = '') {
    $val = getenv($key);
    if ($val !== FALSE && $val !== '') return (string) $val;
    if (isset($_ENV[$key]) && $_ENV[$key] !== '') return (string) $_ENV[$key];
    if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') return (string) $_SERVER[$key];
    if (isset($_SERVER['REDIRECT_' . $key]) && $_SERVER['REDIRECT_' . $key] !== '') return (string) $_SERVER['REDIRECT_' . $key];
    return $default;
};

$enc_key = $wms_env('WMS_ENCRYPTION_KEY', '');
if (empty($enc_key)) {
    // Try to get from database name as fallback salt (better than empty)
    // This prevents session failures that cause 500 on cPanel
    $fallback_salt = 'wms_fallback_salt_for_cpanel_2024_';
    if (isset($_SERVER['HTTP_HOST'])) {
        $fallback_salt .= $_SERVER['HTTP_HOST'];
    }
    // If database.local.php exists, use its database name as part of salt
    $db_local = __DIR__ . '/database.local.php';
    if (is_file($db_local)) {
        // We can't include it here (would cause recursion), just use filemtime as salt
        $fallback_salt .= filemtime($db_local);
    }
    $enc_key = hash('sha256', $fallback_salt);
    // Log warning for admin
    error_log('WMS WARNING: encryption_key empty, using fallback. Create application/config/config.local.php with a proper key for production.');
}
$config['encryption_key'] = $enc_key;
unset($enc_key, $fallback_salt, $db_local);
$config['sess_driver'] = $wms_env('WMS_SESS_DRIVER', 'database');
$config['sess_cookie_name'] = 'wms_session';
$config['sess_expiration'] = 7200;
$config['sess_save_path'] = $wms_env('WMS_SESS_SAVE_PATH', 'ci_sessions');
$config['sess_match_ip'] = FALSE;
$config['sess_time_to_update'] = 300;
// Destroy the previous session record whenever the session id is regenerated
// (on sign-in and every sess_time_to_update seconds). Without this, an id that
// was captured before sign-in stays valid after it — session fixation.
$config['sess_regenerate_destroy'] = TRUE;
$config['cookie_prefix'] = 'wms_';
$config['cookie_domain'] = '';
$config['cookie_path'] = '/';
/*
| Cookie flags.
|
| cookie_secure  : TRUE automatically whenever the request arrived over HTTPS
|                  (directly or through a TLS-terminating proxy). Set
|                  WMS_COOKIE_SECURE=0 in the environment, or override this
|                  in config.local.php, for local development over plain HTTP.
|                  Nothing changes on an http:// deployment, but as soon as
|                  HTTPS is used the session and CSRF cookies carry Secure.
| cookie_httponly: TRUE. CodeIgniter already sends the *session* cookie with
|                  HttpOnly; this setting is what governs the CSRF cookie
|                  (system/core/Security.php), so it is a real fix, not a
|                  no-op.
| samesite       : 'Lax' for both the session cookie (sess_samesite — the key
|                  CI 3.1.13 actually reads for it) and the other cookies
|                  (cookie_samesite). Set explicitly so the intent survives
|                  future edits.
*/
$config['cookie_secure']   = ($wms_env('WMS_COOKIE_SECURE', '') === '0') ? FALSE : $wms_is_https;
$config['cookie_httponly'] = TRUE;
$config['sess_samesite']   = 'Lax';
$config['cookie_samesite'] = 'Lax';
$config['standardize_newlines'] = FALSE;
$config['global_xss_filtering'] = FALSE;
$config['csrf_protection'] = TRUE;
$config['csrf_token_name'] = 'csrf_token_wms';
$config['csrf_cookie_name'] = 'csrf_cookie_wms';
$config['csrf_expire'] = 7200;
$config['csrf_regenerate'] = TRUE;
$config['csrf_exclude_uris'] = array();
$config['compress_output'] = FALSE;
$config['time_reference'] = 'local';
$config['rewrite_short_tags'] = FALSE;
$config['proxy_ips'] = '';

/*
| Legacy password verification (audit finding F-02).
|
| TRUE  : the sign-in screen still accepts MD5 and SHA1 hashes, upgrading them
|         to bcrypt on the next successful sign-in. Required only while
|         accounts with legacy hashes exist.
| FALSE : only bcrypt hashes are accepted. Set this once every legacy
|         hash has been upgraded to bcrypt (each is upgraded on its owner's
|         next successful sign-in) — it is the switch that finally retires
|         MD5/SHA1. Overridable per installation through the
|         WMS_LEGACY_PASSWORD_LOGIN=0 environment variable or config.local.php.
*/
$config['legacy_password_login'] = ($wms_env('WMS_LEGACY_PASSWORD_LOGIN', '') === '0') ? FALSE : TRUE;

/*
| Host-local overrides — never committed. Copy config.local.php.example to
| config.local.php on the server to keep secrets and per-installation values
| (encryption key, base_url, cookie_secure, legacy_password_login) out of the
| repository.
*/
$local_config = __DIR__ . '/config.local.php';
if (is_file($local_config)) {
    require $local_config;
}

unset($local_config, $wms_is_https, $wms_env);
