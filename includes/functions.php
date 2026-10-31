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
    string $transactionDate
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
                company_id, safe_id, user_id, type, category, amount, balance_before, balance_after, description, transaction_date, created_at
            ) VALUES (
                :cid, :sid, :uid, :type, :cat, :amt, :bb, :ba, :desc, :tdate, :now
            )
        ");
        $ins->execute([
            ':cid' => $companyId,
            ':sid' => $safeId,
            ':uid' => $userId,
            ':type' => $type,
            ':cat' => $category,
            ':amt' => $amount,
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
 * Converts a number to Arabic words (Tafqeet) for receipts
 */
function tafqeetNumber(float $num, string $currency = 'جنيه مصري'): string {
    $num = round($num, 2);
    $parts = explode('.', (string)$num);
    $intPart = (int)$parts[0];
    $fraction = isset($parts[1]) ? (int)str_pad($parts[1], 2, '0', STR_PAD_RIGHT) : 0;

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

    $out = "فقط " . $text . " " . $currency;
    if ($fraction > 0) {
        $out .= " و" . $convert3Digits($fraction) . " قرشاً";
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
