<?php
/**
 * Application Core Settings & Helpers
 */

if (session_status() === PHP_SESSION_NONE) {
    // 30 days session persistence
    ini_set('session.gc_maxlifetime', 2592000);
    session_set_cookie_params([
        'lifetime' => 2592000,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

if (!defined('APP_PATH')) {
    define('APP_PATH', dirname(__DIR__));
}

// App metadata
define('APP_NAME', 'فينانس ساس - لوحة التحكم المالية');
define('APP_SHORT_NAME', 'فينانس');
define('APP_VERSION', '1.0.0');

require_once APP_PATH . '/config/database.php';
require_once APP_PATH . '/includes/auth.php';
require_once APP_PATH . '/includes/functions.php';

// Auto-initialize DB tables if not present
require_once APP_PATH . '/config/init_db.php';
ensureDatabaseTablesExist();
