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

/**
 * Splits an amount into integer and fraction (piastres/halalas) parts
 * e.g. 1500.50 => ['int' => '1,500', 'dec' => '50']
 */
function splitAmountParts(float|int|string $amount): array {
    $num = (float)$amount;
    $formatted = sprintf('%.2f', $num);
    $parts = explode('.', $formatted);
    $intPart = number_format((int)$parts[0], 0, '.', ',');
    $decPart = $parts[1] ?? '00';
    return ['int' => $intPart, 'dec' => $decPart];
}

/**
 * Returns Hijri date for given timestamp or date string (e.g. 1448/05/25)
 */
function getHijriDate(string|int|null $timestamp = null): string {
    $time = $timestamp ? (is_numeric($timestamp) ? (int)$timestamp : strtotime($timestamp)) : time();
    if ($time === false) $time = time();
    
    if (class_exists('IntlDateFormatter')) {
        try {
            $fmt = new IntlDateFormatter(
                'ar_SA@calendar=islamic-umalqura',
                IntlDateFormatter::FULL,
                IntlDateFormatter::NONE,
                'Asia/Riyadh',
                IntlDateFormatter::TRADITIONAL,
                'yyyy/MM/dd'
            );
            if ($fmt) {
                $val = $fmt->format($time);
                $arabic = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
                $latin = ['0','1','2','3','4','5','6','7','8','9'];
                return str_replace($arabic, $latin, $val);
            }
        } catch (Exception $e) {}
    }
    
    // Algorithmic fallback
    $m = (int)date('m', $time);
    $d = (int)date('d', $time);
    $y = (int)date('Y', $time);
    $jd = gregoriantojd($m, $d, $y);
    $l = $jd - 1948440 + 10632;
    $n = (int)(($l - 1) / 10631);
    $l = $l - 10631 * $n + 354;
    $j = (int)((10985 - $l) / 5316) * (int)((50 * $l) / 17719) + (int)($l / 5670) * (int)((43 * $l) / 15238);
    $l = $l - (int)((30 - $j) / 15) * (int)((17719 * $j) / 50) - (int)($j / 16) * (int)((15238 * $j) / 43) + 29;
    $m = (int)((24 * $l) / 709);
    $d = $l - (int)((709 * $m) / 24);
    $y = 30 * $n + $j - 30;
    return sprintf('%04d/%02d/%02d', $y, $m, $d);
}

/**
 * Generates ZATCA-compliant TLV Base64 string for Tax Invoices
 */
function generateZatcaTlvBase64(string $seller, string $taxNumber, string $datetime, float $total, float $vat): string {
    $tlv = '';
    $tags = [
        1 => $seller,
        2 => $taxNumber,
        3 => $datetime,
        4 => number_format((float)$total, 2, '.', ''),
        5 => number_format((float)$vat, 2, '.', '')
    ];
    foreach ($tags as $tag => $val) {
        $valStr = (string)$val;
        $len = strlen($valStr);
        $tlv .= chr($tag) . chr($len) . $valStr;
    }
    return base64_encode($tlv);
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
 * Ensures the multi-currency safe balances table exists.
 */
function ensureSafeBalancesTable(): void {
    static $ensured = false;
    if ($ensured) return;
    $db = getDBConnection();
    $db->exec("
        CREATE TABLE IF NOT EXISTS safe_balances (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            company_id INTEGER NOT NULL,
            safe_id INTEGER NOT NULL,
            currency VARCHAR(20) NOT NULL,
            balance DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(safe_id, currency)
        )
    ");
    $ensured = true;
}

/**
 * Ensures payrolls table has currency column.
 */
function ensurePayrollCurrencyColumn(): void {
    static $ensured = false;
    if ($ensured) return;
    try {
        $db = getDBConnection();
        $cols = $db->query("PRAGMA table_info(payrolls)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!empty($cols) && !in_array('currency', $cols)) {
            $db->exec("ALTER TABLE payrolls ADD COLUMN currency VARCHAR(20) DEFAULT 'ج.م'");
        }
    } catch (Exception $e) {}
    $ensured = true;
}

/**
 * Returns associative array of currency => balance for a safe (e.g. ['ج.م' => 1000.00, '$' => 500.00])
 */
function getSafeBalances(int $safeId): array {
    ensureSafeBalancesTable();
    $db = getDBConnection();

    $stmt = $db->prepare("SELECT currency, balance FROM safe_balances WHERE safe_id = :sid ORDER BY id ASC");
    $stmt->execute([':sid' => $safeId]);
    $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    if (empty($rows)) {
        // Automatically sync from safe_transactions if table is empty
        $rows = syncSafeBalances($safeId);
    }

    return $rows;
}

/**
 * Returns the balance of a specific currency in a safe
 */
function getSafeBalance(int $safeId, string $currency): float {
    ensureSafeBalancesTable();
    $db = getDBConnection();
    $stmt = $db->prepare("SELECT balance FROM safe_balances WHERE safe_id = :sid AND currency = :curr LIMIT 1");
    $stmt->execute([':sid' => $safeId, ':curr' => $currency]);
    $val = $stmt->fetchColumn();
    if ($val !== false && $val !== null) {
        return (float)$val;
    }

    $all = syncSafeBalances($safeId);
    return (float)($all[$currency] ?? 0.00);
}

/**
 * Recalculates safe balances per currency from transaction history
 */
function syncSafeBalances(int $safeId): array {
    ensureSafeBalancesTable();
    $db = getDBConnection();

    // Get company_id and default balance of safe
    $safeStmt = $db->prepare("SELECT s.*, c.currency as company_currency FROM safes s LEFT JOIN companies c ON s.company_id = c.id WHERE s.id = :sid");
    $safeStmt->execute([':sid' => $safeId]);
    $safe = $safeStmt->fetch();
    if (!$safe) return [];

    $companyId = (int)$safe['company_id'];
    $defaultCurrency = !empty($safe['company_currency']) ? $safe['company_currency'] : 'ج.م';

    // Calculate net per currency from safe_transactions
    $txStmt = $db->prepare("
        SELECT currency,
               COALESCE(SUM(CASE WHEN type = 'deposit' THEN amount ELSE -amount END), 0) as net_bal
        FROM safe_transactions
        WHERE safe_id = :sid
        GROUP BY currency
    ");
    $txStmt->execute([':sid' => $safeId]);
    $results = $txStmt->fetchAll(PDO::FETCH_ASSOC);

    $balances = [];
    $now = date('Y-m-d H:i:s');

    if (empty($results)) {
        // No transactions: use the balance in safes table with default currency
        $balances[$defaultCurrency] = (float)$safe['balance'];
        $ins = $db->prepare("
            INSERT INTO safe_balances (company_id, safe_id, currency, balance, updated_at)
            VALUES (:cid, :sid, :curr, :bal, :now)
            ON CONFLICT(safe_id, currency) DO UPDATE SET balance = :bal, updated_at = :now
        ");
        $ins->execute([
            ':cid' => $companyId,
            ':sid' => $safeId,
            ':curr' => $defaultCurrency,
            ':bal' => (float)$safe['balance'],
            ':now' => $now
        ]);
    } else {
        foreach ($results as $r) {
            $curr = trim($r['currency']);
            if (empty($curr)) $curr = $defaultCurrency;
            $net = (float)$r['net_bal'];
            $balances[$curr] = $net;

            $ins = $db->prepare("
                INSERT INTO safe_balances (company_id, safe_id, currency, balance, updated_at)
                VALUES (:cid, :sid, :curr, :bal, :now)
                ON CONFLICT(safe_id, currency) DO UPDATE SET balance = :bal, updated_at = :now
            ");
            $ins->execute([
                ':cid' => $companyId,
                ':sid' => $safeId,
                ':curr' => $curr,
                ':bal' => $net,
                ':now' => $now
            ]);
        }
    }

    // Ensure default currency is represented
    if (!isset($balances[$defaultCurrency])) {
        $balances[$defaultCurrency] = 0.00;
        $ins = $db->prepare("
            INSERT INTO safe_balances (company_id, safe_id, currency, balance, updated_at)
            VALUES (:cid, :sid, :curr, 0.00, :now)
            ON CONFLICT(safe_id, currency) DO UPDATE SET balance = 0.00, updated_at = :now
        ");
        $ins->execute([
            ':cid' => $companyId,
            ':sid' => $safeId,
            ':curr' => $defaultCurrency,
            ':now' => $now
        ]);
    }

    // Keep safes.balance updated to the default/primary currency balance
    $updSafe = $db->prepare("UPDATE safes SET balance = :bal WHERE id = :sid");
    $updSafe->execute([':bal' => (float)($balances[$defaultCurrency] ?? 0.00), ':sid' => $safeId]);

    return $balances;
}

/**
 * Gets or creates the primary safe for a given company with multi-currency balances attached.
 */
function getCompanyMainSafe(int $companyId): array {
    $db = getDBConnection();
    ensureSafeBalancesTable();
    $stmt = $db->prepare("SELECT * FROM safes WHERE company_id = :cid ORDER BY id ASC LIMIT 1");
    $stmt->execute([':cid' => $companyId]);
    $safe = $stmt->fetch();

    if (!$safe) {
        $now = date('Y-m-d H:i:s');
        $create = $db->prepare("INSERT INTO safes (company_id, name, balance, created_at) VALUES (:cid, 'الخزنة الرئيسية', 0.00, :now)");
        $create->execute([':cid' => $companyId, ':now' => $now]);
        $newId = (int)$db->lastInsertId();
        $safe = [
            'id' => $newId,
            'company_id' => $companyId,
            'name' => 'الخزنة الرئيسية',
            'balance' => 0.00,
            'created_at' => $now
        ];
    }

    $safe['balances'] = getSafeBalances((int)$safe['id']);
    return $safe;
}

/**
 * Executes a safe transaction (deposit, expense, salary) atomically per currency with lock.
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
    string $currency = 'ج.م',
    ?int $projectId = null
): array {
    if ($amount <= 0) {
        throw new InvalidArgumentException('يجب أن يكون المبلغ أكبر من صفر.');
    }

    $db = getDBConnection();
    ensureSafeBalancesTable();

    $startedTransaction = false;
    if (!$db->inTransaction()) {
        $db->beginTransaction();
        $startedTransaction = true;
    }

    try {
        // Fetch current safe & company details
        $stmt = $db->prepare("SELECT s.*, c.currency as company_currency FROM safes s LEFT JOIN companies c ON s.company_id = c.id WHERE s.id = :sid AND s.company_id = :cid");
        $stmt->execute([':sid' => $safeId, ':cid' => $companyId]);
        $safe = $stmt->fetch();

        if (!$safe) {
            throw new Exception('الخزنة المحددة غير موجودة.');
        }

        $defaultCurrency = !empty($safe['company_currency']) ? $safe['company_currency'] : 'ج.م';
        $currency = trim($currency ?: $defaultCurrency);

        // Fetch current balance FOR THIS SPECIFIC CURRENCY
        $currStmt = $db->prepare("SELECT balance FROM safe_balances WHERE safe_id = :sid AND currency = :curr LIMIT 1");
        $currStmt->execute([':sid' => $safeId, ':curr' => $currency]);
        $currRow = $currStmt->fetchColumn();

        if ($currRow === false) {
            // Check if there are transactions for this currency or calculate
            $syncStmt = $db->prepare("
                SELECT COALESCE(SUM(CASE WHEN type = 'deposit' THEN amount ELSE -amount END), 0)
                FROM safe_transactions
                WHERE safe_id = :sid AND currency = :curr
            ");
            $syncStmt->execute([':sid' => $safeId, ':curr' => $currency]);
            $syncVal = $syncStmt->fetchColumn();
            $balanceBefore = ($syncVal !== false && $syncVal !== null) ? (float)$syncVal : 0.00;
        } else {
            $balanceBefore = (float)$currRow;
        }

        if ($type === 'deposit') {
            $balanceAfter = $balanceBefore + $amount;
        } else {
            // expense / salary / withdrawal
            // Project safes allow expenses to be logged even before client deposits funds (reflects negative/accrued client balance)
            $isProjectSafe = ($safe['type'] ?? '') === 'project' || !empty($projectId);
            if (!$isProjectSafe && $amount > $balanceBefore) {
                throw new Exception("رصيد الخزنة بالعملة ({$currency}) لا يكفي لإتمام هذه العملية! الرصيد المتاح: " . formatMoney($balanceBefore, $currency));
            }
            $balanceAfter = $balanceBefore - $amount;
        }

        $now = date('Y-m-d H:i:s');

        // Insert transaction
        $ins = $db->prepare("
            INSERT INTO safe_transactions (
                company_id, safe_id, user_id, project_id, type, category, amount, currency, balance_before, balance_after, description, transaction_date, created_at
            ) VALUES (
                :cid, :sid, :uid, :pid, :type, :cat, :amt, :curr, :bb, :ba, :desc, :tdate, :now
            )
        ");
        $ins->execute([
            ':cid' => $companyId,
            ':sid' => $safeId,
            ':uid' => $userId,
            ':pid' => $projectId,
            ':type' => $type,
            ':cat' => $category,
            ':amt' => $amount,
            ':curr' => $currency,
            ':bb' => $balanceBefore,
            ':ba' => $balanceAfter,
            ':desc' => $description,
            ':tdate' => $transactionDate,
            ':now' => $now
        ]);
        $transactionId = (int)$db->lastInsertId();

        // Update safe_balances table for this currency
        $updBal = $db->prepare("
            INSERT INTO safe_balances (company_id, safe_id, currency, balance, updated_at)
            VALUES (:cid, :sid, :curr, :ba, :now)
            ON CONFLICT(safe_id, currency) DO UPDATE SET balance = :ba, updated_at = :now
        ");
        $updBal->execute([
            ':cid' => $companyId,
            ':sid' => $safeId,
            ':curr' => $currency,
            ':ba' => $balanceAfter,
            ':now' => $now
        ]);

        // If this currency is the company primary currency, also update safes.balance
        if ($currency === $defaultCurrency) {
            $updSafe = $db->prepare("UPDATE safes SET balance = :ba WHERE id = :sid");
            $updSafe->execute([':ba' => $balanceAfter, ':sid' => $safeId]);
        }

        if ($startedTransaction && $db->inTransaction()) {
            $db->commit();
        }

        return [
            'transaction_id' => $transactionId,
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
            'currency' => $currency
        ];
    } catch (Exception $e) {
        if ($startedTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

/**
 * Returns supported currencies list
 */
function getSupportedCurrencies(): array {
    return [
        'ج.م' => 'جنيه مصري (EGP)',
        '$' => 'دولار أمريكي (USD)',
        'ر.س' => 'ريال سعودي (SAR)',
        '€' => 'يورو (EUR)',
        'د.إ' => 'درهم إماراتي (AED)',
        'د.ك' => 'دينار كويتي (KWD)',
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
 * Returns direct signing URL for a payment voucher (سند صرف)
 */
function getVoucherSignUrl(array $expense): string {
    $baseUrl = getAppBaseUrl();
    $token = $expense['sign_token'] ?? '';
    return $baseUrl . '/voucher_sign.php?token=' . urlencode($token);
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
        $scriptDir = trim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        $subDir = ($scriptDir !== '' && $scriptDir !== '.') ? '/' . $scriptDir : '';
        return $scheme . '://' . $_SERVER['HTTP_HOST'] . $subDir;
    }
    // Fallback production domain
    return 'https://buildesa.com/erp';
}

/**
 * Returns all safes of a company (Main safe + project safes) with multi-currency balances.
 */
function getCompanySafes(int $companyId): array {
    $db = getDBConnection();
    ensureSafeBalancesTable();

    $stmt = $db->prepare("
        SELECT s.*, p.name as project_name, p.code as project_code, p.status as project_status
        FROM safes s
        LEFT JOIN projects p ON s.project_id = p.id
        WHERE s.company_id = :cid
        ORDER BY CASE WHEN s.type = 'main' THEN 0 ELSE 1 END, s.id ASC
    ");
    $stmt->execute([':cid' => $companyId]);
    $safes = $stmt->fetchAll();

    foreach ($safes as &$safe) {
        $safe['balances'] = getSafeBalances((int)$safe['id']);
    }
    unset($safe);

    return $safes;
}

/**
 * Returns or creates the dedicated safe for a given real estate project.
 */
function getProjectSafe(int $companyId, int $projectId, ?string $projectName = null): array {
    $db = getDBConnection();
    ensureSafeBalancesTable();

    $stmt = $db->prepare("SELECT * FROM safes WHERE company_id = :cid AND project_id = :pid LIMIT 1");
    $stmt->execute([':cid' => $companyId, ':pid' => $projectId]);
    $safe = $stmt->fetch();

    if (!$safe) {
        if (empty($projectName)) {
            $pStmt = $db->prepare("SELECT name FROM projects WHERE id = :pid LIMIT 1");
            $pStmt->execute([':pid' => $projectId]);
            $projectName = $pStmt->fetchColumn() ?: 'المشروع';
        }

        $now = date('Y-m-d H:i:s');
        $safeName = 'خزنة ' . $projectName;
        $ins = $db->prepare("
            INSERT INTO safes (company_id, name, balance, project_id, type, created_at)
            VALUES (:cid, :name, 0.00, :pid, 'project', :now)
        ");
        $ins->execute([
            ':cid' => $companyId,
            ':name' => $safeName,
            ':pid' => $projectId,
            ':now' => $now
        ]);
        $safeId = (int)$db->lastInsertId();

        // Update project with safe_id
        $updPrj = $db->prepare("UPDATE projects SET safe_id = :sid WHERE id = :pid");
        $updPrj->execute([':sid' => $safeId, ':pid' => $projectId]);

        $safe = [
            'id' => $safeId,
            'company_id' => $companyId,
            'name' => $safeName,
            'balance' => 0.00,
            'project_id' => $projectId,
            'type' => 'project',
            'created_at' => $now
        ];
    }

    $safe['balances'] = getSafeBalances((int)$safe['id']);
    return $safe;
}

/**
 * Transfers funds between two safes atomically (e.g. from Main Safe to Project Safe).
 */
function recordInterSafeTransfer(
    int $companyId,
    int $fromSafeId,
    int $toSafeId,
    int $userId,
    float $amount,
    string $description,
    string $date,
    string $currency = 'ج.م'
): bool {
    if ($fromSafeId === $toSafeId) {
        throw new InvalidArgumentException('لا يمكن التحويل من وإلى نفس الخزنة.');
    }
    if ($amount <= 0) {
        throw new InvalidArgumentException('المبلغ يجب أن يكون أكبر من صفر.');
    }

    $db = getDBConnection();
    $db->beginTransaction();

    try {
        // Fetch safe names
        $s1Stmt = $db->prepare("SELECT name FROM safes WHERE id = :id AND company_id = :cid");
        $s1Stmt->execute([':id' => $fromSafeId, ':cid' => $companyId]);
        $fromName = $s1Stmt->fetchColumn() ?: 'الخزنة المصدر';

        $s2Stmt = $db->prepare("SELECT name FROM safes WHERE id = :id AND company_id = :cid");
        $s2Stmt->execute([':id' => $toSafeId, ':cid' => $companyId]);
        $toName = $s2Stmt->fetchColumn() ?: 'الخزنة الوجهة';

        $descOut = "تحويل صادر إلى [{$toName}]" . ($description ? " - {$description}" : "");
        $descIn = "تحويل وارد من [{$fromName}]" . ($description ? " - {$description}" : "");

        // 1. Withdraw from source
        recordSafeTransaction(
            $companyId,
            $fromSafeId,
            $userId,
            'expense',
            'تحويل بين الخزن',
            $amount,
            $descOut,
            $date,
            $currency
        );

        // 2. Deposit to destination
        recordSafeTransaction(
            $companyId,
            $toSafeId,
            $userId,
            'deposit',
            'تحويل بين الخزن',
            $amount,
            $descIn,
            $date,
            $currency
        );

        $db->commit();
        return true;
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

/**
 * Calculates project statistics, balance, and filter breakdown (e.g. Month 12).
 */
function getProjectStats(int $companyId, int $projectId, ?string $month = null, ?string $from = null, ?string $to = null): array {
    $db = getDBConnection();

    // 1. Fetch project info
    $pStmt = $db->prepare("SELECT * FROM projects WHERE id = :pid AND company_id = :cid LIMIT 1");
    $pStmt->execute([':pid' => $projectId, ':cid' => $companyId]);
    $project = $pStmt->fetch();

    if (!$project) {
        return [];
    }

    $currency = !empty($project['currency']) ? $project['currency'] : 'ر.س';

    // 2. Ensure project safe exists and get its balance
    $safe = getProjectSafe($companyId, $projectId, $project['name']);

    // 3. All-time deposits into project safe
    $depStmt = $db->prepare("
        SELECT COALESCE(SUM(amount), 0) FROM safe_transactions
        WHERE company_id = :cid AND safe_id = :sid AND type = 'deposit'
    ");
    $depStmt->execute([':cid' => $companyId, ':sid' => $safe['id']]);
    $totalDeposits = (float)$depStmt->fetchColumn();

    // 4. All-time expenses for project (Only approved / deducted expenses)
    $allExpStmt = $db->prepare("
        SELECT COALESCE(SUM(amount), 0) FROM expenses
        WHERE company_id = :cid AND project_id = :pid AND (status IN ('deducted', 'approved') OR status IS NULL)
    ");
    $allExpStmt->execute([':cid' => $companyId, ':pid' => $projectId]);
    $totalExpenses = (float)$allExpStmt->fetchColumn();

    // Pending Approval Expenses for this project (Waiting for Admin approval)
    $pndStmt = $db->prepare("
        SELECT COALESCE(SUM(amount), 0) as total, COUNT(*) as cnt
        FROM expenses
        WHERE company_id = :cid AND project_id = :pid AND status = 'pending_approval'
    ");
    $pndStmt->execute([':cid' => $companyId, ':pid' => $projectId]);
    $pndRow = $pndStmt->fetch();
    $pendingApprovalAmount = (float)($pndRow['total'] ?? 0);
    $pendingApprovalCount = (int)($pndRow['cnt'] ?? 0);

    // 5. Date filter calculation (Only approved / deducted expenses)
    if (!empty($month)) {
        $filterFrom = date('Y-m-01', strtotime($month . '-01'));
        $filterTo = date('Y-m-t', strtotime($month . '-01'));
    } else {
        $filterFrom = $from ?: date('Y-01-01');
        $filterTo = $to ?: date('Y-m-d');
    }

    $filterStmt = $db->prepare("
        SELECT COALESCE(SUM(amount), 0) as total_amount, COUNT(*) as cnt
        FROM expenses
        WHERE company_id = :cid AND project_id = :pid AND expense_date BETWEEN :from AND :to
          AND (status IN ('deducted', 'approved') OR status IS NULL)
    ");
    $filterStmt->execute([':cid' => $companyId, ':pid' => $projectId, ':from' => $filterFrom, ':to' => $filterTo]);
    $filterRow = $filterStmt->fetch();
    $filteredExpenses = (float)($filterRow['total_amount'] ?? 0);
    $filteredCount = (int)($filterRow['cnt'] ?? 0);

    // 6. Category breakdown for filtered period (Only approved / deducted expenses)
    $catStmt = $db->prepare("
        SELECT COALESCE(ec.name, 'مصروف مشروع') as cat_name, 
               COALESCE(SUM(e.amount), 0) as cat_total,
               COUNT(e.id) as cat_count
        FROM expenses e
        LEFT JOIN expense_categories ec ON e.category_id = ec.id
        WHERE e.company_id = :cid AND e.project_id = :pid AND e.expense_date BETWEEN :from AND :to
          AND (e.status IN ('deducted', 'approved') OR e.status IS NULL)
        GROUP BY ec.id, ec.name
        ORDER BY cat_total DESC
    ");
    $catStmt->execute([':cid' => $companyId, ':pid' => $projectId, ':from' => $filterFrom, ':to' => $filterTo]);
    $categories = $catStmt->fetchAll();

    $capital = (float)$project['capital'];
    $burnRate = $capital > 0 ? round(($totalExpenses / $capital) * 100, 1) : 0;

    // Land & Acquisition breakdown
    $landPrice = (float)($project['land_price'] ?? 0);
    $landBrokerage = (float)($project['land_brokerage'] ?? 0);
    $landTax = (float)($project['land_tax'] ?? 0);
    $totalLandCost = $landPrice + $landBrokerage + $landTax;

    // If project table land fields are 0, check if expenses contain land items
    if ($totalLandCost <= 0) {
        $landExpStmt = $db->prepare("
            SELECT 
                COALESCE(SUM(CASE WHEN e.title LIKE 'قيمة الأرض%' OR e.title LIKE 'قيمة الارض%' OR ec.name = 'قيمة الأرض' THEN e.amount ELSE 0 END), 0) as l_price,
                COALESCE(SUM(CASE WHEN e.title LIKE '%سعي%' OR ec.name = 'السعي' OR ec.name = 'سعي' OR e.description = 'سعي' THEN e.amount ELSE 0 END), 0) as l_brok,
                COALESCE(SUM(CASE WHEN e.title LIKE '%ضريب%' OR ec.name = 'الضريبة' OR ec.name = 'ضريبة' OR e.description = 'ضريبة' THEN e.amount ELSE 0 END), 0) as l_tax
            FROM expenses e
            LEFT JOIN expense_categories ec ON e.category_id = ec.id
            WHERE e.company_id = :cid AND e.project_id = :pid
              AND (e.title NOT LIKE '%الدور الارض%' AND e.title NOT LIKE '%الدور الأرض%')
              AND (
                ec.name IN ('قيمة الأرض', 'السعي', 'سعي', 'الضريبة', 'ضريبة')
                OR e.title LIKE 'قيمة الأرض%' OR e.title LIKE 'قيمة الارض%'
                OR e.title LIKE 'السعي%' OR e.title LIKE 'سعي%'
                OR e.title LIKE 'الضريبة%' OR e.title LIKE 'ضريبة%'
                OR e.description IN ('قيمة الارض', 'قيمة الأرض', 'سعي', 'ضريبة')
              )
        ");
        $landExpStmt->execute([':cid' => $companyId, ':pid' => $projectId]);
        $landExpRow = $landExpStmt->fetch();
        if ($landExpRow) {
            $landPrice = (float)$landExpRow['l_price'];
            $landBrokerage = (float)$landExpRow['l_brok'];
            $landTax = (float)$landExpRow['l_tax'];
            $totalLandCost = $landPrice + $landBrokerage + $landTax;
        }
    }

    $constructionExpenses = max(0, $totalExpenses - $totalLandCost);

    // Client payments calculation (Receipts linked to project + direct client deposits)
    $recStmt = $db->prepare("
        SELECT COALESCE(SUM(amount), 0) FROM receipts
        WHERE company_id = :cid AND project_id = :pid
    ");
    $recStmt->execute([':cid' => $companyId, ':pid' => $projectId]);
    $receiptsAmount = (float)$recStmt->fetchColumn();

    // Additional deposits in project safe not already originating from receipts
    $directDepStmt = $db->prepare("
        SELECT COALESCE(SUM(amount), 0) FROM safe_transactions
        WHERE company_id = :cid AND safe_id = :sid AND type = 'deposit'
        AND category != 'رأس مال معتمد'
        AND id NOT IN (SELECT safe_transaction_id FROM receipts WHERE safe_transaction_id IS NOT NULL)
    ");
    $directDepStmt->execute([':cid' => $companyId, ':sid' => $safe['id']]);
    $directDepAmount = (float)$directDepStmt->fetchColumn();

    $clientPayments = $receiptsAmount + $directDepAmount;

    $clientFeePct = (float)($project['client_fee_percentage'] ?? 0);
    $clientFeeAmount = round($totalExpenses * ($clientFeePct / 100), 2);
    $totalBillable = $totalExpenses + $clientFeeAmount;
    $clientDue = round($totalBillable - $clientPayments, 2);

    $clientStatus = 'due';
    if (abs($clientDue) < 0.01) {
        $clientStatus = 'paid';
    } elseif ($clientDue < -0.01) {
        $clientStatus = 'overpaid';
    }

    return [
        'project' => $project,
        'safe' => $safe,
        'capital' => $capital,
        'currency' => $currency,
        'safe_balance' => (float)$safe['balance'],
        'total_deposits' => $totalDeposits,
        'total_expenses' => $totalExpenses,
        'pending_approval_amount' => $pendingApprovalAmount,
        'pending_approval_count' => $pendingApprovalCount,
        'filtered_expenses' => $filteredExpenses,
        'filtered_count' => $filteredCount,
        'filter_from' => $filterFrom,
        'filter_to' => $filterTo,
        'burn_rate' => $burnRate,
        'category_breakdown' => $categories,
        'project_type' => $project['project_type'] ?? 'with_capital',
        'client_name' => $project['client_name'] ?? '',
        'client_phone' => $project['client_phone'] ?? '',
        'client_fee_percentage' => $clientFeePct,
        'client_fee_amount' => $clientFeeAmount,
        'total_billable' => $totalBillable,
        'client_payments' => $clientPayments,
        'client_due' => $clientDue,
        'client_status' => $clientStatus,
        'land_price' => $landPrice,
        'land_brokerage' => $landBrokerage,
        'land_tax' => $landTax,
        'total_land_cost' => $totalLandCost,
        'construction_expenses' => $constructionExpenses
    ];
}

/**
 * SQL condition to identify Land, Brokerage, and Land Tax expenses.
 * Excludes normal construction items like "سقف الدور الارضي".
 */
function getLandExpensesSqlCondition(string $expAlias = 'e', string $catAlias = 'ec'): string {
    return "(
        (
            {$catAlias}.name IN ('قيمة الأرض', 'السعي', 'سعي', 'الضريبة', 'ضريبة')
            OR {$expAlias}.title LIKE 'قيمة الأرض%'
            OR {$expAlias}.title LIKE 'قيمة الارض%'
            OR {$expAlias}.title LIKE 'السعي%'
            OR {$expAlias}.title LIKE 'سعي%'
            OR {$expAlias}.title LIKE 'الضريبة%'
            OR {$expAlias}.title LIKE 'ضريبة%'
            OR {$expAlias}.description IN ('قيمة الارض', 'قيمة الأرض', 'سعي', 'ضريبة', 'قيمة شراء أرض المشروع', 'سعي ودلالة شراء أرض المشروع', 'ضريبة التصرفات العقارية للأرض')
        )
        AND ({$expAlias}.title NOT LIKE '%الدور الارض%' AND {$expAlias}.title NOT LIKE '%الدور الأرض%')
    )";
}

/**
 * Generates sequential official voucher number (e.g. VCH-2026-0001).
 */
function generateVoucherNumber(int $companyId): string {
    $db = getDBConnection();
    $year = date('Y');
    $prefix = "VCH-{$year}-";

    $stmt = $db->prepare("
        SELECT voucher_number FROM expenses 
        WHERE company_id = :cid AND voucher_number LIKE :pref 
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([':cid' => $companyId, ':pref' => "{$prefix}%"]);
    $lastVoucher = $stmt->fetchColumn();

    if ($lastVoucher && preg_match('/-(\d+)$/', $lastVoucher, $m)) {
        $next = (int)$m[1] + 1;
    } else {
        $next = 1;
    }

    return sprintf('%s%04d', $prefix, $next);
}

/**
 * Generates sequential official simplified tax invoice number (e.g. INV-2026-0001).
 */
function generateInvoiceNumber(int $companyId): string {
    $db = getDBConnection();
    $year = date('Y');
    $prefix = "INV-{$year}-";

    $stmt = $db->prepare("
        SELECT invoice_number FROM invoices 
        WHERE company_id = :cid AND invoice_number LIKE :pref 
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([':cid' => $companyId, ':pref' => "{$prefix}%"]);
    $lastInv = $stmt->fetchColumn();

    if ($lastInv && preg_match('/-(\d+)$/', $lastInv, $m)) {
        $next = (int)$m[1] + 1;
    } else {
        $next = 1;
    }

    return sprintf('%s%04d', $prefix, $next);
}

/**
 * Renders an embedded HTML video player or external video link for daily project reports.
 */
function renderProjectVideoPlayer(?string $videoFile, ?string $videoUrl): string {
    $html = '';
    if (!empty($videoFile) && file_exists(APP_PATH . '/' . $videoFile)) {
        $html .= '<div class="mt-3 rounded-2xl overflow-hidden bg-black/95 shadow-md border border-slate-700/60 max-w-2xl">
            <video controls playsinline class="w-full max-h-96 object-contain" preload="metadata">
                <source src="' . htmlspecialchars($videoFile) . '">
                متصفحك لا يدعم مشغل الفيديو المباشر.
            </video>
        </div>';
    } elseif (!empty($videoUrl)) {
        // YouTube check (watch, embed, or youtu.be shortlinks)
        if (preg_match('/(?:youtube\.com\/(?:watch\?v=|embed\/)|youtu\.be\/)([a-zA-Z0-9_-]{11})/i', $videoUrl, $matches)) {
            $ytId = $matches[1];
            $html .= '<div class="mt-3 aspect-video max-w-2xl rounded-2xl overflow-hidden bg-black shadow-md border border-slate-700/60">
                <iframe class="w-full h-full" src="https://www.youtube-nocookie.com/embed/' . htmlspecialchars($ytId) . '" title="فيديو توثيق إنجاز المرحلة" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
            </div>';
        } elseif (preg_match('/\.(mp4|webm|mov|m4v)(\?.*)?$/i', $videoUrl)) {
            // Direct MP4 / WebM URL
            $html .= '<div class="mt-3 rounded-2xl overflow-hidden bg-black/95 shadow-md border border-slate-700/60 max-w-2xl">
                <video controls playsinline class="w-full max-h-96 object-contain" preload="metadata">
                    <source src="' . htmlspecialchars($videoUrl) . '">
                    متصفحك لا يدعم تشغيل هذا الفيديو.
                </video>
            </div>';
        } else {
            // Other link (e.g. Google Drive, OneDrive, DropBox)
            $html .= '<div class="mt-3 p-3.5 bg-gradient-to-r from-blue-50 to-indigo-50 border border-blue-200 rounded-2xl flex items-center justify-between max-w-2xl">
                <div class="flex items-center space-x-2.5 space-x-reverse text-xs text-blue-900 font-bold">
                    <div class="w-8 h-8 rounded-xl bg-blue-600 text-white flex items-center justify-center">
                        <i class="fa-solid fa-video"></i>
                    </div>
                    <div>
                        <span class="block">فيديو توثيقي ميداني للموقع</span>
                        <span class="text-[10px] text-blue-600 font-normal truncate max-w-xs block">' . htmlspecialchars($videoUrl) . '</span>
                    </div>
                </div>
                <a href="' . htmlspecialchars($videoUrl) . '" target="_blank" class="inline-flex items-center bg-blue-600 hover:bg-blue-700 text-white font-bold px-3.5 py-2 rounded-xl text-xs transition shadow-sm shrink-0">
                    <i class="fa-solid fa-arrow-up-right-from-square ml-1.5"></i> فتح الفيديو
                </a>
            </div>';
        }
    }
    return $html;
}

/**
 * Gets or creates an expense category by name for a company.
 */
function getOrCreateExpenseCategory(int $companyId, string $categoryName): ?int {
    $trimmed = trim($categoryName);
    if (empty($trimmed) || $companyId <= 0) return null;
    $db = getDBConnection();
    $chk = $db->prepare("SELECT id FROM expense_categories WHERE company_id = :cid AND TRIM(LOWER(name)) = LOWER(:name) LIMIT 1");
    $chk->execute([':cid' => $companyId, ':name' => $trimmed]);
    $cat = $chk->fetch();
    if ($cat) {
        return (int)$cat['id'];
    }
    try {
        $ins = $db->prepare("INSERT INTO expense_categories (company_id, name, icon, is_active, created_at) VALUES (:cid, :name, 'fa-tags', 1, :now)");
        $ins->execute([
            ':cid' => $companyId,
            ':name' => $trimmed,
            ':now' => date('Y-m-d H:i:s')
        ]);
        return (int)$db->lastInsertId();
    } catch (Exception $e) {
        return null;
    }
}

