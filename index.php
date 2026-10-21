<?php
/**
 * Workshop Management System - Front Controller
 * cPanel compatible version
 */

// Prevent double output buffering issues on some cPanel PHP handlers
if (ob_get_level() === 0) {
    ob_start();
}

define('ENVIRONMENT', isset($_SERVER['CI_ENV']) ? $_SERVER['CI_ENV'] : 'production');
switch (ENVIRONMENT) {
    case 'development':
        error_reporting(-1);
        ini_set('display_errors', 1);
        ini_set('log_errors', 1);
    break;
    case 'testing':
    case 'production':
        // On cPanel, we hide display but always log - helps debug 500 errors
        ini_set('display_errors', 0);
        ini_set('log_errors', 1);
        // Log to application/logs if writable, otherwise to PHP default
        $log_path = __DIR__ . '/application/logs/php_errors.log';
        if (is_dir(dirname($log_path)) && is_writable(dirname($log_path))) {
            ini_set('error_log', $log_path);
        }
        error_reporting(PHP_VERSION_ID >= 80400
            ? (E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_USER_NOTICE & ~E_USER_DEPRECATED)
            : (E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_STRICT & ~E_USER_NOTICE & ~E_USER_DEPRECATED));
    break;
    default:
        header('HTTP/1.1 503 Service Unavailable.', TRUE, 503);
        echo 'The application environment is not set correctly.';
        exit(1);
}

$system_path = 'system';
$application_folder = 'application';
$view_folder = '';

if (defined('STDIN')) {
    chdir(dirname(__FILE__));
}

if (($_temp = realpath($system_path)) !== FALSE) {
    $system_path = $_temp.DIRECTORY_SEPARATOR;
} else {
    $system_path = rtrim($system_path, '/\\').DIRECTORY_SEPARATOR;
}

if (! is_dir($system_path)) {
    header('HTTP/1.1 503 Service Unavailable.', TRUE, 503);
    echo 'Your system folder path does not appear to be set correctly. Please open the following file and correct this: '.pathinfo(__FILE__, PATHINFO_BASENAME);
    exit(3);
}

define('SELF',        pathinfo(__FILE__, PATHINFO_BASENAME));
define('BASEPATH',    $system_path);
define('FCPATH',      dirname(__FILE__).DIRECTORY_SEPARATOR);
define('SYSDIR',      basename(BASEPATH));

if (is_dir($application_folder)) {
    if (($_temp = realpath($application_folder)) !== FALSE) {
        $application_folder = $_temp;
    }
    define('APPPATH', $application_folder.DIRECTORY_SEPARATOR);
} else {
    if (! is_dir(BASEPATH.$application_folder.DIRECTORY_SEPARATOR)) {
        header('HTTP/1.1 503 Service Unavailable.', TRUE, 503);
        echo 'Your application folder path does not appear to be set correctly. Please open the following file and correct this: '.SELF;
        exit(3);
    }
    define('APPPATH', BASEPATH.$application_folder.DIRECTORY_SEPARATOR);
}

if ($view_folder !== '') {
    if (! is_dir($view_folder)) {
        if (! is_dir(APPPATH.'views'.DIRECTORY_SEPARATOR.$view_folder.DIRECTORY_SEPARATOR)) {
            header('HTTP/1.1 503 Service Unavailable.', TRUE, 503);
            echo 'Your view folder path does not appear to be set correctly. Please open the following file and correct this: '.SELF;
            exit(3);
        }
        define('VIEWPATH', APPPATH.'views'.DIRECTORY_SEPARATOR.$view_folder.DIRECTORY_SEPARATOR);
    } else {
        define('VIEWPATH', rtrim($view_folder, '/\\').DIRECTORY_SEPARATOR);
    }
} else {
    define('VIEWPATH', APPPATH.'views'.DIRECTORY_SEPARATOR);
}

require_once BASEPATH.'core/CodeIgniter.php';
