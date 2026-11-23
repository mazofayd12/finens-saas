<?php
require_once __DIR__ . '/../config/app.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');

$amount = (float)($_GET['amount'] ?? 0);
$currency = trim($_GET['currency'] ?? 'ج.م');

if ($amount <= 0) {
    echo json_encode(['words' => '']);
    exit;
}

$words = tafqeetNumber($amount, $currency);
echo json_encode(['words' => $words], JSON_UNESCAPED_UNICODE);
