<?php
/**
 * Standalone Shared Hosting Cron Script for Social Media Publishing
 * Can be executed via: php cron_social_publish.php
 * Typical Cron Job (every minute):
 * * * * * * php /path-to-project/cron_social_publish.php > /dev/null 2>&1
 */

if (php_sapi_name() !== 'cli' && (!isset($_GET['key']) || $_GET['key'] !== 'finens_cron_trigger')) {
    // If triggered via browser without secret key, reject
    http_response_code(403);
    die("Access denied. CLI or secret web cron key required.\n");
}

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/social/SocialScheduler.php';

$scheduler = new SocialScheduler();
$result = $scheduler->run();

if (php_sapi_name() === 'cli') {
    echo "[" . date('Y-m-d H:i:s') . "] Social Publishing Cron Execution\n";
    echo "Status: " . $result['status'] . "\n";
    echo "Posts Found: " . ($result['posts_found'] ?? 0) . "\n";
    echo "Processed: " . ($result['posts_processed'] ?? 0) . " (Succeeded: " . ($result['posts_succeeded'] ?? 0) . ", Failed: " . ($result['posts_failed'] ?? 0) . ")\n";
    echo "Duration: " . ($result['duration'] ?? 0) . "s\n";
} else {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
