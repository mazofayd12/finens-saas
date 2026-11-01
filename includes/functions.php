<?php
/**
 * Global Utility Functions
 */

function sanitize(string $data): string {
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

function formatMoney(float|int|string $amount, string $currency = 'ج.م'): string {
    $num = (float)$amount;
    return number_format($num, 2, '.', ',') . ' ' . $currency;
}

function setFlash(string $type, string $message): void {
    if (!isset($_SESSION['flash'])) {
        $_SESSION['flash'] = [];
    }
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function getFlashes(): array {
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

function generateCSRFToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCSRFToken(?string $token): bool {
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

function getCSRFInput(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(generateCSRFToken()) . '">';
}

/**
 * Gets or creates the primary safe for a given company.
 */
function getCompanyMainSafe(int $companyId): array {
    $db = getDBConnection();
    $stmt = $db->prepare("SELECT * FROM safes WHERE company_id = :cid ORDER BY id ASC LIMIT 1");
    $stmt->execute([':cid' => $companyId]);
    $safe = $stmt->fetch();

    if (!$safe) {
        $now = date('Y-m-d H:i:s');
        $create = $db->prepare("INSERT INTO safes (company_id, name, balance, created_at) VALUES (:cid, 'الخزنة الرئيسية', 0.00, :now)");
        $create->execute([':cid' => $companyId, ':now' => $now]);
        $newId = (int)$db->lastInsertId();
        return [
            'id' => $newId,
            'company_id' => $companyId,
            'name' => 'الخزنة الرئيسية',
            'balance' => 0.00,
            'created_at' => $now
        ];
    }

    return $safe;
}

/**
 * Executes a safe transaction (deposit, expense, salary) atomically with transaction lock.
 */
function recordSafeTransaction(
    int $companyId,
    int $safeId,
    int $userId,
    string $type, // 'deposit' or 'expense' or 'salary'
    string $category,
    float $amount,
    string $description,
    string $transactionDate,
    string $currency = 'ج.م'
): array {
    if ($amount <= 0) {
        throw new InvalidArgumentException('يجب أن يكون المبلغ أكبر من صفر.');
    }

    $db = getDBConnection();
    $db->beginTransaction();

    try {
        // Fetch current safe balance
        $stmt = $db->prepare("SELECT balance FROM safes WHERE id = :sid AND company_id = :cid");
        $stmt->execute([':sid' => $safeId, ':cid' => $companyId]);
        $safe = $stmt->fetch();

        if (!$safe) {
            throw new Exception('الخزنة المحددة غير موجودة.');
        }

        $balanceBefore = (float)$safe['balance'];

        if ($type === 'deposit') {
            $balanceAfter = $balanceBefore + $amount;
        } else {
            // expense / salary / withdrawal
            if ($amount > $balanceBefore) {
                throw new Exception('رصيد الخزنة لا يكفي لإتمام هذه العملية! الرصيد الحالي: ' . formatMoney($balanceBefore));
            }
            $balanceAfter = $balanceBefore - $amount;
        }

        // Insert transaction
        $ins = $db->prepare("
            INSERT INTO safe_transactions (
                company_id, safe_id, user_id, type, category, amount, currency, balance_before, balance_after, description, transaction_date, created_at
            ) VALUES (
                :cid, :sid, :uid, :type, :cat, :amt, :curr, :bb, :ba, :desc, :tdate, :now
            )
        ");
        $ins->execute([
            ':cid' => $companyId,
            ':sid' => $safeId,
            ':uid' => $userId,
            ':type' => $type,
            ':cat' => $category,
            ':amt' => $amount,
            ':curr' => $currency,
            ':bb' => $balanceBefore,
            ':ba' => $balanceAfter,
            ':desc' => $description,
            ':tdate' => $transactionDate,
            ':now' => date('Y-m-d H:i:s')
        ]);
        $transactionId = (int)$db->lastInsertId();

        // Update safe balance
        $upd = $db->prepare("UPDATE safes SET balance = :ba WHERE id = :sid");
        $upd->execute([':ba' => $balanceAfter, ':sid' => $safeId]);

        $db->commit();

        return [
            'transaction_id' => $transactionId,
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter
        ];
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * Returns supported currencies list
 */
function getSupportedCurrencies(): array {
    return [
        ['code' => 'ج.م', 'name' => 'جنيه مصري (EGP)', 'symbol' => 'ج.م'],
        ['code' => '$', 'name' => 'دولار أمريكي (USD)', 'symbol' => '$'],
        ['code' => 'ر.س', 'name' => 'ريال سعودي (SAR)', 'symbol' => 'ر.س'],
        ['code' => '€', 'name' => 'يورو (EUR)', 'symbol' => '€'],
        ['code' => 'د.إ', 'name' => 'درهم إماراتي (AED)', 'symbol' => 'د.إ'],
        ['code' => 'د.ك', 'name' => 'دينار كويتي (KWD)', 'symbol' => 'د.ك'],
    ];
}

/**
 * Converts a number to Arabic words (Tafqeet) for receipts
 */
function tafqeetNumber(float $num, string $currency = 'ج.م'): string {
    $num = round($num, 2);
    $parts = explode('.', sprintf('%.2f', $num));
    $intPart = (int)$parts[0];
    $fraction = (int)($parts[1] ?? 0);

    // Map currency code or symbol to Arabic singular/subunit
    $curr = trim($currency);
    $mainUnit = 'جنيه مصري';
    $subUnit = 'قرشاً';

    if (in_array($curr, ['$', 'USD', 'usd', 'دولار', 'دولار أمريكي'])) {
        $mainUnit = 'دولار أمريكي';
        $subUnit = 'سنتاً';
    } elseif (in_array($curr, ['ج.م', 'EGP', 'egp', 'جنيه', 'جنيه مصري'])) {
        $mainUnit = 'جنيه مصري';
        $subUnit = 'قرشاً';
    } elseif (in_array($curr, ['ر.س', 'SAR', 'sar', 'ريال', 'ريال سعودي'])) {
        $mainUnit = 'ريال سعودي';
        $subUnit = 'هللة';
    } elseif (in_array($curr, ['€', 'EUR', 'eur', 'يورو'])) {
        $mainUnit = 'يورو';
        $subUnit = 'سنتاً';
    } elseif (in_array($curr, ['د.إ', 'AED', 'aed', 'درهم', 'درهم إماراتي'])) {
        $mainUnit = 'درهم إماراتي';
        $subUnit = 'فلساً';
    } elseif (in_array($curr, ['د.ك', 'KWD', 'kwd', 'دينار', 'دينار كويتي'])) {
        $mainUnit = 'دينار كويتي';
        $subUnit = 'فلساً';
    } elseif (!empty($curr)) {
        $mainUnit = $curr;
        $subUnit = 'أجزاء';
    }

    $ones = [
        "", "واحد", "اثنان", "ثلاثة", "أربعة", "خمسة", "ستة", "سبعة", "ثمانية", "تسعة", "عشرة",
        "أحد عشر", "اثنا عشر", "ثلاثة عشر", "أربعة عشر", "خمسة عشر", "ستة عشر", "سبعة عشر", "ثمانية عشر", "تسعة عشر"
    ];
    $tens = ["", "", "عشرون", "ثلاثون", "أربعون", "خمسون", "ستون", "سبعون", "ثمانون", "تسعون"];
    $hundreds = ["", "مائة", "مائتان", "ثلاثمائة", "أربعمائة", "خمسمائة", "ستمائة", "سبعمائة", "ثمانمائة", "تسعمائة"];

    $convert3Digits = function($n) use ($ones, $tens, $hundreds) {
        $res = [];
        $h = (int)($n / 100);
        $rem = $n % 100;
        if ($h > 0) $res[] = $hundreds[$h];
        if ($rem > 0) {
            if ($rem < 20) {
                $res[] = $ones[$rem];
            } else {
                $unit = $rem % 10;
                $ten = (int)($rem / 10);
                if ($unit > 0) {
                    $res[] = $ones[$unit] . " و" . $tens[$ten];
                } else {
                    $res[] = $tens[$ten];
                }
            }
        }
        return implode(" و", $res);
    };

    if ($intPart === 0) {
        $text = "صفر";
    } else {
        $res = [];
        // Millions
        $mil = (int)($intPart / 1000000);
        $remMil = $intPart % 1000000;
        if ($mil > 0) {
            if ($mil === 1) $res[] = "مليون";
            elseif ($mil === 2) $res[] = "مليونان";
            elseif ($mil >= 3 && $mil <= 10) $res[] = $convert3Digits($mil) . " ملايين";
            else $res[] = $convert3Digits($mil) . " مليون";
        }
        // Thousands
        $th = (int)($remMil / 1000);
        $remTh = $remMil % 1000;
        if ($th > 0) {
            if ($th === 1) $res[] = "ألف";
            elseif ($th === 2) $res[] = "ألفان";
            elseif ($th >= 3 && $th <= 10) $res[] = $convert3Digits($th) . " آلاف";
            else $res[] = $convert3Digits($th) . " ألف";
        }
        // Hundreds & Units
        if ($remTh > 0) {
            $res[] = $convert3Digits($remTh);
        }
        $text = implode(" و", $res);
    }

    $out = "فقط " . $text . " " . $mainUnit;
    if ($fraction > 0) {
        $out .= " و" . $convert3Digits($fraction) . " " . $subUnit;
    }
    $out .= " لا غير";
    return $out;
}

/**
 * Generates next sequential receipt number for a company (Starting from 1001)
 */
function generateNextReceiptNumber(int $companyId): string {
    $db = getDBConnection();
    $stmt = $db->prepare("SELECT MAX(id) as max_id FROM receipts WHERE company_id = :cid");
    $stmt->execute([':cid' => $companyId]);
    $row = $stmt->fetch();
    $nextId = ((int)($row['max_id'] ?? 0)) + 1;
    $recNum = 1000 + $nextId;
    return 'REC-' . $recNum;
}

/**
 * Generates verification token for a receipt
 */
function getReceiptVerificationToken(array|int|string $receiptOrId): string {
    $val = is_array($receiptOrId) ? ($receiptOrId['receipt_number'] ?? $receiptOrId['id']) : $receiptOrId;
    return substr(hash('sha256', "rec_{$val}_finens_secure_token"), 0, 12);
}

/**
 * Returns public URL for viewing / verifying a receipt online
 */
function getReceiptPublicUrl(array|int|string $receipt): string {
    $baseUrl = getAppBaseUrl();
    if (is_array($receipt)) {
        $num = $receipt['receipt_number'] ?? ('REC-' . $receipt['id']);
    } else {
        $num = is_numeric($receipt) ? ('REC-' . $receipt) : $receipt;
    }
    return $baseUrl . '/view_receipt.php?r=' . urlencode($num);
}

/**
 * Returns base URL of current app instance
 */
function getAppBaseUrl(): string {
    if (!empty($_SERVER['HTTP_HOST'])) {
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') 
                   || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)
                   || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        $scheme = $isHttps ? 'https' : 'http';
        return $scheme . '://' . $_SERVER['HTTP_HOST'];
    }
    // Fallback production domain
    return 'https://darkorange-reindeer-228322.hostingersite.com';
}
