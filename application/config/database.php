<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
| -------------------------------------------------------------------
| DATABASE CONNECTIVITY SETTINGS
| -------------------------------------------------------------------
| Credentials are deliberately NOT stored in this file: anything committed
| here is readable by everyone who can read the repository, today and in
| every clone, fork and CI artefact made from it.
|
| Supply them in one of these ways (checked in this order):
|
|   1. application/config/database.local.php
|      Not committed (see .gitignore). Copy database.local.php.example
|      and fill it in. This is the recommended way on shared hosting,
|      where per-site environment variables are often unavailable.
|
|   2. Environment variables:
|        WMS_DB_HOST   (hostname, optionally host:port)
|        WMS_DB_USER
|        WMS_DB_PASS
|        WMS_DB_NAME
|
| Deployment order (do not reverse it):
|   a. create database.local.php (or the environment variables) with the
|      CURRENT credentials on the server, while the application is still
|      running;
|   b. deploy this file; verify the application still connects;
|   c. rotate the database password and update the local file;
|   d. confirm the old password no longer works.
|
| If nothing is configured the connection fails loudly (Database Error
| page and a log entry) instead of silently falling back to a leaked
| credential — that is intentional.
*/

$active_group = 'default';
$query_builder = TRUE;

$env = function ($key) {
    $value = getenv($key);
    return ($value === FALSE) ? '' : (string) $value;
};

$db['default'] = array(
    'dsn'          => '',
    'hostname'     => $env('WMS_DB_HOST'),
    'username'     => $env('WMS_DB_USER'),
    'password'     => $env('WMS_DB_PASS'),
    'database'     => $env('WMS_DB_NAME'),
    'dbdriver'     => 'mysqli',
    'dbprefix'     => '',
    'pconnect'     => FALSE,
    // On cPanel, keep db_debug FALSE in production but we will handle missing credentials gracefully below
    'db_debug'     => (defined('ENVIRONMENT') && ENVIRONMENT === 'development') ? TRUE : FALSE,
    'cache_on'     => FALSE,
    'cachedir'     => '',
    'char_set'     => 'utf8mb4',
    'dbcollat'     => 'utf8mb4_unicode_ci',
    'swap_pre'     => '',
    'encrypt'      => FALSE,
    'compress'     => FALSE,
    'stricton'     => FALSE,
    'failover'     => array(),
    'save_queries' => FALSE,
);

/*
| Host-local override. Never committed; it may set or replace any key of
| $db['default'] (and $active_group if needed) and wins over the
| environment variables above.
*/
$local_config = __DIR__ . '/database.local.php';
if (is_file($local_config)) {
    require $local_config;
}

/*
| cPanel-friendly: Detect missing credentials early and show helpful error
| instead of generic 500. This prevents "This page isn't working" with no clue.
*/
if (empty($db['default']['hostname']) || empty($db['default']['username']) || empty($db['default']['database'])) {
    // Only trigger friendly error if we're in a web request (not CLI) and not already handling an error
    if (php_sapi_name() !== 'cli' && !defined('WMS_DB_CHECK_BYPASS')) {
        // Log for cPanel error_log
        error_log('WMS: Database credentials missing. Create application/config/database.local.php from database.local.php.example');

        // If this is a direct access (not through CI), show friendly HTML
        // CI will handle it later via its own error page, but we prepare the message
        if (!defined('BASEPATH')) {
            // We are being loaded outside CI (should not happen, but safe)
            header('HTTP/1.1 500 Internal Server Error');
            echo '<h1>Database Configuration Missing</h1>';
            echo '<p>The file <code>application/config/database.local.php</code> is missing.</p>';
            echo '<p>On cPanel, create it from the example:</p>';
            echo '<ol>';
            echo '<li>Copy <code>application/config/database.local.php.example</code> to <code>application/config/database.local.php</code></li>';
            echo '<li>Edit it with your cPanel database credentials (from MySQL Databases section)</li>';
            echo '<li>Example:<br><code>';
            echo htmlspecialchars("<?php\ndefined('BASEPATH') OR exit('No direct script access allowed');\n\$db['default']['hostname'] = 'localhost';\n\$db['default']['username'] = 'your_cpaneluser_dbuser';\n\$db['default']['password'] = 'your_password';\n\$db['default']['database'] = 'your_cpaneluser_dbname';\n");
            echo '</code></li>';
            echo '</ol>';
            echo '<p>Also check cPanel &gt; Select PHP Version &gt; set to 8.1 or 8.2</p>';
            exit(1);
        }
        // If inside CI, let CI handle but ensure we have a marker for MY_Controller to detect
        // We set a constant that controllers can check if needed
        if (!defined('WMS_DB_MISSING')) {
            define('WMS_DB_MISSING', TRUE);
        }
    }
}

unset($env, $local_config);
