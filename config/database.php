<?php
/**
 * Database Configuration & Connection
 * Supports both SQLite (default zero-configuration) and MySQL.
 */

if (!defined('APP_PATH')) {
    define('APP_PATH', dirname(__DIR__));
}

// Database Driver: 'sqlite' or 'mysql'
define('DB_DRIVER', 'sqlite');

// MySQL Settings (if you switch DB_DRIVER to 'mysql')
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'egypt_office_finens');
define('DB_USER', 'root');
define('DB_PASS', '');

// SQLite Settings
define('SQLITE_FILE', APP_PATH . '/database/database.sqlite');

function getDBConnection(): PDO {
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    try {
        if (DB_DRIVER === 'sqlite') {
            $dbDir = dirname(SQLITE_FILE);
            if (!is_dir($dbDir)) {
                mkdir($dbDir, 0777, true);
            }
            $pdo = new PDO('sqlite:' . SQLITE_FILE);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            // Enable Foreign Keys for SQLite
            $pdo->exec('PRAGMA foreign_keys = ON;');
        } else {
            $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
            ]);
        }
    } catch (PDOException $e) {
        die('<div style="font-family:sans-serif; direction:rtl; text-align:center; padding:50px;">' .
            '<h2>خطأ في الاتصال بقاعدة البيانات</h2>' .
            '<p>' . htmlspecialchars($e->getMessage()) . '</p>' .
            '</div>');
    }

    return $pdo;
}
