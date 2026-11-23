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


/**
 * Ensures audit_logs table exists and expenses table has edit tracking columns.
 */
function ensureAuditLogsTable(): void {
    static $ensured = false;
    if ($ensured) return;
    $db = getDBConnection();

    $db->exec("
        CREATE TABLE IF NOT EXISTS audit_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            company_id INTEGER NOT NULL,
            user_id INTEGER,
            user_name TEXT,
            user_role TEXT,
            action TEXT NOT NULL,
            module TEXT NOT NULL,
            record_id INTEGER,
            record_ref TEXT,
            summary TEXT NOT NULL,
            old_values TEXT,
            new_values TEXT,
            ip_address TEXT,
            user_agent TEXT,
            device_info TEXT,
            created_at DATETIME NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_audit_logs_company ON audit_logs(company_id);
        CREATE INDEX IF NOT EXISTS idx_audit_logs_module_record ON audit_logs(module, record_id);
        CREATE INDEX IF NOT EXISTS idx_audit_logs_created_at ON audit_logs(created_at);
    ");

    // Check expenses table columns
    try {
        $cols = $db->query("PRAGMA table_info(expenses)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('is_edited', $cols)) {
            $db->exec("ALTER TABLE expenses ADD COLUMN is_edited INTEGER DEFAULT 0");
        }
        if (!in_array('edit_count', $cols)) {
            $db->exec("ALTER TABLE expenses ADD COLUMN edit_count INTEGER DEFAULT 0");
        }
        if (!in_array('last_edited_by', $cols)) {
            $db->exec("ALTER TABLE expenses ADD COLUMN last_edited_by INTEGER DEFAULT NULL");
        }
        if (!in_array('last_edited_at', $cols)) {
            $db->exec("ALTER TABLE expenses ADD COLUMN last_edited_at DATETIME DEFAULT NULL");
        }
        if (!in_array('edit_reason', $cols)) {
            $db->exec("ALTER TABLE expenses ADD COLUMN edit_reason TEXT DEFAULT NULL");
        }
    } catch (Exception $e) {
        // Silently continue if PRAGMA fails or columns already exist
    }

    $ensured = true;
}

/**
 * Returns the client's real IP address.
 */
function getClientIP(): string {
    $keys = [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_CLIENT_IP',
        'REMOTE_ADDR'
    ];
    foreach ($keys as $key) {
        if (!empty($_SERVER[$key])) {
            $ips = explode(',', $_SERVER[$key]);
            $ip = trim($ips[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}

/**
 * Parses user agent string to return computer/device and browser in clear Arabic.
 */
function parseUserAgentString(?string $ua = null): string {
    if ($ua === null) {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    }
    if (empty($ua)) {
        return 'جهاز غير معروف';
    }

    // Platform / Device
    $platform = 'كمبيوتر شخصي';
    if (stripos($ua, 'Macintosh') !== false || stripos($ua, 'Mac OS X') !== false) {
        $platform = 'كمبيوتر Mac (Apple)';
    } elseif (stripos($ua, 'Windows NT 10.0') !== false) {
        $platform = 'كمبيوتر Windows 10/11';
    } elseif (stripos($ua, 'Windows') !== false) {
        $platform = 'كمبيوتر Windows';
    } elseif (stripos($ua, 'iPhone') !== false) {
        $platform = 'هاتف iPhone';
    } elseif (stripos($ua, 'iPad') !== false) {
        $platform = 'جهاز iPad';
    } elseif (stripos($ua, 'Android') !== false) {
        $platform = 'هاتف Android';
    } elseif (stripos($ua, 'Linux') !== false) {
        $platform = 'كمبيوتر Linux';
    }

    // Browser
    $browser = 'متصفح ويب';
    if (stripos($ua, 'Edg/') !== false || stripos($ua, 'Edge/') !== false) {
        $browser = 'Microsoft Edge';
    } elseif (stripos($ua, 'Chrome/') !== false && stripos($ua, 'Edg/') === false) {
        $browser = 'Google Chrome';
    } elseif (stripos($ua, 'Firefox/') !== false) {
        $browser = 'Mozilla Firefox';
    } elseif (stripos($ua, 'Safari/') !== false && stripos($ua, 'Chrome/') === false) {
        $browser = 'Apple Safari';
    } elseif (stripos($ua, 'Opera') !== false || stripos($ua, 'OPR/') !== false) {
        $browser = 'Opera';
    }

    return "{$platform} - {$browser}";
}

/**
 * Records an entry into the audit_logs (Equals) system.
 */
function recordAuditLog(
    int $companyId,
    ?int $userId,
    ?string $userName,
    ?string $userRole,
    string $action,
    string $module,
    ?int $recordId,
    ?string $recordRef,
    string $summary,
    ?array $oldValues = null,
    ?array $newValues = null,
    ?string $ipAddress = null,
    ?string $userAgent = null,
    ?string $deviceInfo = null
): int {
    ensureAuditLogsTable();
    $db = getDBConnection();

    if ($ipAddress === null) {
        $ipAddress = getClientIP();
    }
    if ($userAgent === null) {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    }
    if ($deviceInfo === null) {
        $deviceInfo = parseUserAgentString($userAgent);
    }
    $oldJson = $oldValues !== null ? json_encode($oldValues, JSON_UNESCAPED_UNICODE) : null;
    $newJson = $newValues !== null ? json_encode($newValues, JSON_UNESCAPED_UNICODE) : null;
    $now = date('Y-m-d H:i:s');

    try {
        $stmt = $db->prepare("
            INSERT INTO audit_logs (
                company_id, user_id, user_name, user_role, action, module,
                record_id, record_ref, summary, old_values, new_values,
                ip_address, user_agent, device_info, created_at
            ) VALUES (
                :cid, :uid, :uname, :urole, :act, :mod,
                :rec_id, :rec_ref, :sum, :old_v, :new_v,
                :ip, :ua, :dev, :created_at
            )
        ");
        $stmt->execute([
            ':cid' => $companyId,
            ':uid' => $userId,
            ':uname' => $userName,
            ':urole' => $userRole,
            ':act' => $action,
            ':mod' => $module,
            ':rec_id' => $recordId,
            ':rec_ref' => $recordRef,
            ':sum' => $summary,
            ':old_v' => $oldJson,
            ':new_v' => $newJson,
            ':ip' => $ipAddress,
            ':ua' => $userAgent,
            ':dev' => $deviceInfo,
            ':created_at' => $now
        ]);
        return (int)$db->lastInsertId();
    } catch (Exception $e) {
        error_log("Failed to insert audit log: " . $e->getMessage());
        return 0;
    }
}

/**
 * Fetches audit logs for a specific record.
 */
function getAuditLogsForRecord(string $module, int $recordId, int $companyId): array {
    ensureAuditLogsTable();
    $db = getDBConnection();
    $stmt = $db->prepare("
        SELECT * FROM audit_logs
        WHERE module = :mod AND record_id = :rec_id AND company_id = :cid
        ORDER BY id DESC
    ");
    $stmt->execute([
        ':mod' => $module,
        ':rec_id' => $recordId,
        ':cid' => $companyId
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Edits an expense record with strict financial integrity and audit logging.
 */
function editExpenseRecord(int $expenseId, array $data, array $currentUser): array {
    ensureAuditLogsTable();
    $db = getDBConnection();
    $companyId = (int)($currentUser['active_company_id'] ?? 0);

    // 1. Fetch current expense
    $stmt = $db->prepare("
        SELECT e.*, c.name as category_name, p.name as project_name, u.name as creator_name
        FROM expenses e
        LEFT JOIN expense_categories c ON e.category_id = c.id
        LEFT JOIN projects p ON e.project_id = p.id
        LEFT JOIN users u ON e.user_id = u.id
        WHERE e.id = :id AND e.company_id = :cid
    ");
    $stmt->execute([':id' => $expenseId, ':cid' => $companyId]);
    $oldExp = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$oldExp) {
        throw new Exception('سند الصرف أو المصروف المحدد غير موجود.');
    }

    // 2. Parse new values
    $baseAmount = (float)($data['amount'] ?? 0);
    if ($baseAmount <= 0) {
        throw new Exception('يرجى إدخال مبلغ صحيح للصرف (أكبر من صفر).');
    }

    $title = trim($data['title'] ?? '');
    if (empty($title)) {
        throw new Exception('يرجى تحديد بيان وسبب الصرف.');
    }

    $expenseDate = trim($data['expense_date'] ?? date('Y-m-d'));
    $expCurrency = trim($data['currency'] ?? $oldExp['currency']);
    $description = trim($data['description'] ?? '');
    $beneficiaryName = trim($data['beneficiary_name'] ?? '');
    $beneficiaryId = trim($data['beneficiary_id_number'] ?? '');
    $beneficiaryPhone = trim($data['beneficiary_phone'] ?? '');
    $paymentMethod = trim($data['payment_method'] ?? ($oldExp['payment_method'] ?: 'نقدي'));
    $chequeNumber = trim($data['cheque_number'] ?? '');
    $bankName = trim($data['bank_name'] ?? '');
    $chequeDate = trim($data['cheque_date'] ?? '');
    $editReason = trim($data['edit_reason'] ?? '');

    // VAT
    $applyVat = !empty($data['apply_vat']);
    $vatPercent = ($expCurrency === 'ج.م') ? 14.00 : 15.00;
    $taxRate = $applyVat ? $vatPercent : 0.00;
    $taxAmount = $applyVat ? round($baseAmount * ($vatPercent / 100), 2) : 0.00;
    $totalAmount = round($baseAmount + $taxAmount, 2);

    // Category
    $categoryId = !empty($data['category_id']) ? (int)$data['category_id'] : null;
    $newCategoryName = trim($data['new_category_name'] ?? '');
    if (empty($categoryId) && !empty($newCategoryName)) {
        $createdCatId = getOrCreateExpenseCategory($companyId, $newCategoryName);
        if ($createdCatId) $categoryId = $createdCatId;
    }
    $catDisplayName = 'مصروف عام';
    if ($categoryId) {
        $catStmt = $db->prepare("SELECT name FROM expense_categories WHERE id = :id AND company_id = :cid");
        $catStmt->execute([':id' => $categoryId, ':cid' => $companyId]);
        $catDisplayName = $catStmt->fetchColumn() ?: 'مصروف عام';
    }

    // Attachment handling: supports deleting individual attachments AND adding new ones without replacing
    ensureExpenseAttachmentsTable();
    $attachmentPath = $oldExp['invoice_attachment'];
    $newAttachmentUploaded = false;

    // 1. Delete marked attachments if any
    $deleteAttIds = $data['delete_attachment_ids'] ?? [];
    if (!empty($deleteAttIds) && is_array($deleteAttIds)) {
        foreach ($deleteAttIds as $delId) {
            $delId = (int)$delId;
            if ($delId > 0) {
                $selStmt = $db->prepare("SELECT file_path FROM expense_attachments WHERE id = :id AND expense_id = :eid AND company_id = :cid");
                $selStmt->execute([':id' => $delId, ':eid' => $expenseId, ':cid' => $companyId]);
                $targetPath = $selStmt->fetchColumn();
                if ($targetPath) {
                    $fullPath = APP_PATH . '/' . ltrim($targetPath, '/');
                    if (file_exists($fullPath) && is_file($fullPath)) {
                        @unlink($fullPath);
                    }
                    $db->prepare("DELETE FROM expense_attachments WHERE id = :id AND expense_id = :eid AND company_id = :cid")->execute([
                        ':id' => $delId,
                        ':eid' => $expenseId,
                        ':cid' => $companyId
                    ]);
                }
            } elseif ($delId === 0 && !empty($oldExp['invoice_attachment'])) {
                $fullPath = APP_PATH . '/' . ltrim($oldExp['invoice_attachment'], '/');
                if (file_exists($fullPath) && is_file($fullPath)) {
                    @unlink($fullPath);
                }
                $db->prepare("DELETE FROM expense_attachments WHERE expense_id = :eid AND company_id = :cid")->execute([
                    ':eid' => $expenseId,
                    ':cid' => $companyId
                ]);
            }
        }
    }

    // 2. Append newly uploaded invoices (does NOT overwrite or delete existing ones)
    $uploadFilesPayload = !empty($_FILES['invoice_files']['name']) ? $_FILES['invoice_files'] : (!empty($_FILES['invoice_file']['name']) ? $_FILES['invoice_file'] : null);
    if (!empty($uploadFilesPayload)) {
        $savedAtts = saveExpenseUploadedFiles($expenseId, $companyId, $uploadFilesPayload, !empty($oldExp['project_id']) ? (int)$oldExp['project_id'] : null);
        if (!empty($savedAtts)) {
            $newAttachmentUploaded = true;
        }
    }

    // 3. Keep expenses.invoice_attachment synchronized to the first available attachment (or null if none)
    $firstAttStmt = $db->prepare("SELECT file_path FROM expense_attachments WHERE expense_id = :eid ORDER BY id ASC LIMIT 1");
    $firstAttStmt->execute([':eid' => $expenseId]);
    $firstRemainingPath = $firstAttStmt->fetchColumn();
    $attachmentPath = $firstRemainingPath ?: null;

    // 3. Financial update: If already deducted from safe, update transaction and sync balances!
    $oldTotal = (float)$oldExp['amount'];
    $safeTxId = (int)($oldExp['safe_transaction_id'] ?? 0);
    $safeId = (int)($oldExp['safe_id'] ?? 0);

    $db->beginTransaction();
    try {
        if ($oldExp['status'] === 'deducted' && $safeTxId > 0 && $safeId > 0) {
            $finalDesc = "سند صرف رقم " . ($oldExp['voucher_number'] ?: ('VCH-' . $expenseId)) . " إلى: " . ($beneficiaryName ?: 'غير محدد') . " - {$title}";
            if (!empty($oldExp['project_name'])) {
                $finalDesc .= " | مشروع: " . $oldExp['project_name'];
            }
            if ($applyVat) {
                $finalDesc .= " (شامل ضريبة {$vatPercent}%: " . formatMoney($taxAmount, $expCurrency) . ")";
            }

            // Update the existing safe transaction amount and info
            $updTx = $db->prepare("
                UPDATE safe_transactions
                SET amount = :amt,
                    category = :cat,
                    description = :desc,
                    transaction_date = :tdate
                WHERE id = :txid AND company_id = :cid
            ");
            $updTx->execute([
                ':amt' => $totalAmount,
                ':cat' => $catDisplayName,
                ':desc' => $finalDesc,
                ':tdate' => $expenseDate,
                ':txid' => $safeTxId,
                ':cid' => $companyId
            ]);

            // Re-sync safe balances accurately
            syncSafeBalances($safeId);
        }

        // 4. Update the expense record
        $now = date('Y-m-d H:i:s');
        $updExp = $db->prepare("
            UPDATE expenses
            SET amount = :amt,
                currency = :curr,
                tax_rate = :trate,
                tax_amount = :tamt,
                title = :title,
                description = :desc,
                category_id = :cat_id,
                expense_date = :edate,
                beneficiary_name = :bname,
                beneficiary_id_number = :bid,
                beneficiary_phone = :bphone,
                payment_method = :pmethod,
                cheque_number = :cheque_no,
                bank_name = :bank_name,
                cheque_date = :cheque_date,
                invoice_attachment = :attach,
                is_edited = 1,
                edit_count = COALESCE(edit_count, 0) + 1,
                last_edited_by = :editor_id,
                last_edited_at = :edited_at,
                edit_reason = :ereason
            WHERE id = :id AND company_id = :cid
        ");
        $updExp->execute([
            ':amt' => $totalAmount,
            ':curr' => $expCurrency,
            ':trate' => $taxRate,
            ':tamt' => $taxAmount,
            ':title' => $title,
            ':desc' => $description,
            ':cat_id' => $categoryId,
            ':edate' => $expenseDate,
            ':bname' => $beneficiaryName,
            ':bid' => $beneficiaryId,
            ':bphone' => $beneficiaryPhone,
            ':pmethod' => $paymentMethod,
            ':cheque_no' => $chequeNumber,
            ':bank_name' => $bankName,
            ':cheque_date' => $chequeDate,
            ':attach' => $attachmentPath,
            ':editor_id' => $currentUser['id'],
            ':edited_at' => $now,
            ':ereason' => $editReason,
            ':id' => $expenseId,
            ':cid' => $companyId
        ]);

        // 5. Track diffs for audit log
        $diffs = [];
        if (abs($oldTotal - $totalAmount) > 0.001) {
            $diffs[] = "المبلغ من (" . formatMoney($oldTotal, $oldExp['currency']) . ") إلى (" . formatMoney($totalAmount, $expCurrency) . ")";
        }
        if (trim((string)$oldExp['title']) !== $title) {
            $diffs[] = "البيان من '{$oldExp['title']}' إلى '{$title}'";
        }
        if ($oldExp['expense_date'] !== $expenseDate) {
            $diffs[] = "التاريخ من {$oldExp['expense_date']} إلى {$expenseDate}";
        }
        if ((int)($oldExp['category_id'] ?? 0) !== (int)$categoryId) {
            $diffs[] = "التصنيف من '" . ($oldExp['category_name'] ?: 'مصروف عام') . "' إلى '{$catDisplayName}'";
        }
        if (trim((string)$oldExp['beneficiary_name']) !== $beneficiaryName) {
            $diffs[] = "المستفيد من '" . ($oldExp['beneficiary_name'] ?: 'لا يوجد') . "' إلى '" . ($beneficiaryName ?: 'لا يوجد') . "'";
        }
        if (trim((string)$oldExp['payment_method']) !== $paymentMethod) {
            $diffs[] = "طريقة الخصم من '" . ($oldExp['payment_method'] ?: 'غير محدد') . "' إلى '{$paymentMethod}'";
        }
        if ($newAttachmentUploaded) {
            $diffs[] = "تم استبدال/إرفاق ملف فاتورة جديد";
        }

        $summaryText = "تعديل سند صرف رقم " . ($oldExp['voucher_number'] ?: ('VCH-' . $expenseId)) . ": " . (empty($diffs) ? "تحديث بيانات السند" : implode(' | ', $diffs));
        if (!empty($editReason)) {
            $summaryText .= " (سبب التعديل: {$editReason})";
        }

        $oldValues = [
            'amount' => $oldTotal,
            'currency' => $oldExp['currency'],
            'tax_amount' => (float)$oldExp['tax_amount'],
            'title' => $oldExp['title'],
            'category_name' => $oldExp['category_name'] ?: 'مصروف عام',
            'expense_date' => $oldExp['expense_date'],
            'beneficiary_name' => $oldExp['beneficiary_name'],
            'beneficiary_id_number' => $oldExp['beneficiary_id_number'],
            'beneficiary_phone' => $oldExp['beneficiary_phone'],
            'payment_method' => $oldExp['payment_method'],
            'invoice_attachment' => $oldExp['invoice_attachment']
        ];

        $newValues = [
            'amount' => $totalAmount,
            'currency' => $expCurrency,
            'tax_amount' => $taxAmount,
            'title' => $title,
            'category_name' => $catDisplayName,
            'expense_date' => $expenseDate,
            'beneficiary_name' => $beneficiaryName,
            'beneficiary_id_number' => $beneficiaryId,
            'beneficiary_phone' => $beneficiaryPhone,
            'payment_method' => $paymentMethod,
            'invoice_attachment' => $attachmentPath,
            'edit_reason' => $editReason
        ];

        recordAuditLog(
            $companyId,
            (int)$currentUser['id'],
            $currentUser['name'] ?? 'مستخدم',
            $currentUser['role'] ?? 'user',
            'edit',
            'expenses',
            $expenseId,
            $oldExp['voucher_number'] ?: ('VCH-' . $expenseId),
            $summaryText,
            $oldValues,
            $newValues
        );

        $db->commit();

        return [
            'success' => true,
            'message' => 'تم تعديل السند وحفظ التغييرات في سجل الرقابة والتدقيق (Equals) وتحديث أرصدة الخزنة بنجاح!',
            'voucher_number' => $oldExp['voucher_number'] ?: ('VCH-' . $expenseId)
        ];
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

/**
 * Ensures project_blueprints table exists.
 */
function ensureProjectBlueprintsTable(): void {
    static $ensured = false;
    if ($ensured) return;
    $db = getDBConnection();

    $db->exec("
        CREATE TABLE IF NOT EXISTS project_blueprints (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            company_id INTEGER NOT NULL,
            project_id INTEGER NOT NULL,
            user_id INTEGER,
            title VARCHAR(255) NOT NULL,
            blueprint_type VARCHAR(100) DEFAULT 'عام',
            file_path TEXT NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            file_size INTEGER DEFAULT 0,
            file_ext VARCHAR(20) DEFAULT '',
            notes TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
        );
        CREATE INDEX IF NOT EXISTS idx_project_blueprints_project ON project_blueprints(project_id);
        CREATE INDEX IF NOT EXISTS idx_project_blueprints_company ON project_blueprints(company_id);
    ");
    $ensured = true;
}

/**
 * Format bytes into human-readable string.
 */
function formatBytes($bytes, int $precision = 1): string {
    $bytes = max((float)$bytes, 0);
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min((int)$pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));
    return round($bytes, $precision) . ' ' . $units[$pow];
}

/**
 * Retrieve blueprints for a specific project.
 */
function getProjectBlueprints(int $companyId, int $projectId): array {
    ensureProjectBlueprintsTable();
    $db = getDBConnection();
    $stmt = $db->prepare("
        SELECT b.*, u.name as uploader_name 
        FROM project_blueprints b
        LEFT JOIN users u ON b.user_id = u.id
        WHERE b.company_id = :cid AND b.project_id = :pid
        ORDER BY b.id DESC
    ");
    $stmt->execute([':cid' => $companyId, ':pid' => $projectId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Save a single blueprint uploaded file to project_blueprints.
 */
function saveProjectBlueprintFile(
    int $companyId,
    int $projectId,
    ?int $userId,
    array $fileItem,
    string $title = '',
    string $type = 'عام',
    string $notes = ''
): ?int {
    if (empty($fileItem['name']) || ($fileItem['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }

    ensureProjectBlueprintsTable();
    $db = getDBConnection();

    $originalName = basename($fileItem['name']);
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    $allowedExts = [
        'pdf', 'dwg', 'dxf', 'cad', 'rvt', 'ifc',
        'png', 'jpg', 'jpeg', 'webp', 'svg', 'bmp',
        'zip', 'rar', '7z', 'tar', 'gz',
        'doc', 'docx', 'xls', 'xlsx'
    ];

    if (!in_array($ext, $allowedExts)) {
        throw new InvalidArgumentException("صيغة الملف ({$ext}) غير مدعومة للمخططات الهندسية.");
    }

    $uploadDir = APP_PATH . '/uploads/projects/blueprints/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    // Clean title
    $rawBaseName = pathinfo($originalName, PATHINFO_FILENAME);
    $finalTitle = trim($title) !== '' ? trim($title) : $rawBaseName;
    $finalType = trim($type) !== '' ? trim($type) : 'عام';

    // Unique filename
    $safeName = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $rawBaseName);
    if (empty($safeName)) {
        $safeName = 'blueprint';
    }
    $newFileName = 'bp_' . $projectId . '_' . time() . '_' . rand(100, 999) . '_' . substr($safeName, 0, 40) . '.' . $ext;
    $targetPath = $uploadDir . $newFileName;

    if (!move_uploaded_file($fileItem['tmp_name'], $targetPath)) {
        throw new RuntimeException("فشل في حفظ الملف على السيرفر.");
    }

    $relPath = 'uploads/projects/blueprints/' . $newFileName;
    $fileSize = (int)($fileItem['size'] ?? (file_exists($targetPath) ? filesize($targetPath) : 0));

    $stmt = $db->prepare("
        INSERT INTO project_blueprints (
            company_id, project_id, user_id, title, blueprint_type,
            file_path, file_name, file_size, file_ext, notes, created_at
        ) VALUES (
            :cid, :pid, :uid, :title, :btype,
            :fpath, :fname, :fsize, :fext, :notes, :created
        )
    ");
    $stmt->execute([
        ':cid' => $companyId,
        ':pid' => $projectId,
        ':uid' => $userId,
        ':title' => $finalTitle,
        ':btype' => $finalType,
        ':fpath' => $relPath,
        ':fname' => $originalName,
        ':fsize' => $fileSize,
        ':fext' => $ext,
        ':notes' => $notes ?: null,
        ':created' => date('Y-m-d H:i:s')
    ]);

    return (int)$db->lastInsertId();
}

/**
 * Save multiple blueprint files uploaded via standard PHP array format.
 */
function saveMultipleProjectBlueprints(
    int $companyId,
    int $projectId,
    ?int $userId,
    array $filesArray,
    string $defaultType = 'عام',
    string $notes = ''
): int {
    if (empty($filesArray['name']) || !is_array($filesArray['name'])) {
        return 0;
    }

    $count = 0;
    $totalFiles = count($filesArray['name']);
    for ($i = 0; $i < $totalFiles; $i++) {
        if (($filesArray['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $singleFile = [
                'name' => $filesArray['name'][$i],
                'type' => $filesArray['type'][$i] ?? '',
                'tmp_name' => $filesArray['tmp_name'][$i],
                'error' => $filesArray['error'][$i],
                'size' => $filesArray['size'][$i] ?? 0
            ];
            try {
                $savedId = saveProjectBlueprintFile($companyId, $projectId, $userId, $singleFile, '', $defaultType, $notes);
                if ($savedId) $count++;
            } catch (Exception $e) {
                // Ignore single file error, continue with other files
            }
        }
    }
    return $count;
}

/**
 * Delete a blueprint record and its physical file.
 */
function deleteProjectBlueprint(int $companyId, int $blueprintId): bool {
    ensureProjectBlueprintsTable();
    $db = getDBConnection();
    $stmt = $db->prepare("SELECT * FROM project_blueprints WHERE id = :id AND company_id = :cid");
    $stmt->execute([':id' => $blueprintId, ':cid' => $companyId]);
    $bp = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$bp) {
        return false;
    }

    // Delete physical file
    if (!empty($bp['file_path'])) {
        $fullPath = APP_PATH . '/' . ltrim($bp['file_path'], '/');
        if (file_exists($fullPath) && is_file($fullPath)) {
            @unlink($fullPath);
        }
    }

    $del = $db->prepare("DELETE FROM project_blueprints WHERE id = :id AND company_id = :cid");
    return $del->execute([':id' => $blueprintId, ':cid' => $companyId]);
}

/**
 * Ensures expense_attachments table exists.
 */
function ensureExpenseAttachmentsTable(): void {
    static $ensured = false;
    if ($ensured) return;
    $db = getDBConnection();

    $db->exec("
        CREATE TABLE IF NOT EXISTS expense_attachments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            expense_id INTEGER NOT NULL,
            company_id INTEGER NOT NULL,
            project_id INTEGER NULL,
            file_path TEXT NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            file_size INTEGER DEFAULT 0,
            file_ext VARCHAR(20) DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (expense_id) REFERENCES expenses(id) ON DELETE CASCADE
        );
        CREATE INDEX IF NOT EXISTS idx_expense_attachments_expense ON expense_attachments(expense_id);
        CREATE INDEX IF NOT EXISTS idx_expense_attachments_company ON expense_attachments(company_id);
        CREATE INDEX IF NOT EXISTS idx_expense_attachments_project ON expense_attachments(project_id);
    ");

    // Auto-backfill existing invoice_attachment values from expenses table into expense_attachments
    try {
        $legacy = $db->query("
            SELECT e.id, e.company_id, e.project_id, e.invoice_attachment, e.created_at
            FROM expenses e
            WHERE e.invoice_attachment IS NOT NULL 
              AND e.invoice_attachment != ''
              AND NOT EXISTS (
                  SELECT 1 FROM expense_attachments ea WHERE ea.expense_id = e.id
              )
        ")->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($legacy)) {
            $insStmt = $db->prepare("
                INSERT INTO expense_attachments (
                    expense_id, company_id, project_id, file_path, file_name, file_size, file_ext, created_at
                ) VALUES (
                    :eid, :cid, :pid, :fpath, :fname, :fsize, :fext, :created_at
                )
            ");
            foreach ($legacy as $leg) {
                $cPath = ltrim($leg['invoice_attachment'], '/');
                $full = APP_PATH . '/' . $cPath;
                $ext = strtolower(pathinfo($cPath, PATHINFO_EXTENSION));
                $size = (file_exists($full) && is_file($full)) ? filesize($full) : 0;
                $insStmt->execute([
                    ':eid' => $leg['id'],
                    ':cid' => $leg['company_id'],
                    ':pid' => $leg['project_id'],
                    ':fpath' => $cPath,
                    ':fname' => basename($cPath),
                    ':fsize' => $size,
                    ':fext' => $ext,
                    ':created_at' => $leg['created_at'] ?: date('Y-m-d H:i:s')
                ]);
            }
        }
    } catch (Exception $e) {
        // Silently continue if query fails
    }

    $ensured = true;
}

/**
 * Save multiple or single uploaded invoice files for an expense.
 *
 * @param int $expenseId
 * @param int $companyId
 * @param array $filesInput (Either $_FILES['invoice_files'] or $_FILES['invoice_file'])
 * @param int|null $projectId
 * @return array Array of saved attachment records
 */
function saveExpenseUploadedFiles(int $expenseId, int $companyId, array $filesInput, ?int $projectId = null): array {
    ensureExpenseAttachmentsTable();
    $db = getDBConnection();
    $savedRecords = [];

    if (empty($filesInput) || empty($filesInput['name'])) {
        return $savedRecords;
    }

    $uploadDir = APP_PATH . '/assets/uploads/invoices';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];

    // Normalize $_FILES structure: handle both single and multiple file uploads
    $isMultiple = is_array($filesInput['name']);
    $fileCount = $isMultiple ? count($filesInput['name']) : 1;

    for ($i = 0; $i < $fileCount; $i++) {
        $error = $isMultiple ? $filesInput['error'][$i] : $filesInput['error'];
        if ($error !== UPLOAD_ERR_OK) {
            continue;
        }

        $tmpName = $isMultiple ? $filesInput['tmp_name'][$i] : $filesInput['tmp_name'];
        $origName = $isMultiple ? $filesInput['name'][$i] : $filesInput['name'];
        $size = $isMultiple ? (int)$filesInput['size'][$i] : (int)$filesInput['size'];
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

        if (!in_array($ext, $allowedExts)) {
            continue;
        }

        $prefix = $projectId ? "inv_prj_{$projectId}_" : "inv_exp_{$expenseId}_";
        $newFileName = $prefix . time() . '_' . rand(1000, 9999) . '.' . $ext;
        $destPath = $uploadDir . '/' . $newFileName;

        if (move_uploaded_file($tmpName, $destPath)) {
            $relPath = 'assets/uploads/invoices/' . $newFileName;

            $ins = $db->prepare("
                INSERT INTO expense_attachments (
                    expense_id, company_id, project_id, file_path, file_name, file_size, file_ext, created_at
                ) VALUES (
                    :eid, :cid, :pid, :fpath, :fname, :fsize, :fext, :now
                )
            ");
            $now = date('Y-m-d H:i:s');
            $ins->execute([
                ':eid' => $expenseId,
                ':cid' => $companyId,
                ':pid' => $projectId,
                ':fpath' => $relPath,
                ':fname' => $origName,
                ':fsize' => $size,
                ':fext' => $ext,
                ':now' => $now
            ]);

            $savedRecords[] = [
                'id' => (int)$db->lastInsertId(),
                'expense_id' => $expenseId,
                'file_path' => $relPath,
                'file_name' => $origName,
                'file_size' => $size,
                'file_ext' => $ext
            ];
        }
    }

    // Keep expenses.invoice_attachment in sync with the first attachment for full backward compatibility
    if (!empty($savedRecords) && $expenseId > 0) {
        $firstPath = $savedRecords[0]['file_path'];
        $db->prepare("
            UPDATE expenses 
            SET invoice_attachment = COALESCE(NULLIF(invoice_attachment, ''), :att) 
            WHERE id = :id AND company_id = :cid
        ")->execute([
            ':att' => $firstPath,
            ':id' => $expenseId,
            ':cid' => $companyId
        ]);
    }

    return $savedRecords;
}

/**
 * Retrieve all invoice attachments for an expense.
 */
function getExpenseAttachments(int $expenseId, ?string $fallbackPath = null): array {
    ensureExpenseAttachmentsTable();
    $db = getDBConnection();

    $stmt = $db->prepare("
        SELECT * FROM expense_attachments 
        WHERE expense_id = :eid 
        ORDER BY id ASC
    ");
    $stmt->execute([':eid' => $expenseId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($items) && !empty($fallbackPath)) {
        $cleanPath = ltrim($fallbackPath, '/');
        $fullPath = APP_PATH . '/' . $cleanPath;
        $ext = strtolower(pathinfo($cleanPath, PATHINFO_EXTENSION));
        $items[] = [
            'id' => 0,
            'expense_id' => $expenseId,
            'file_path' => $cleanPath,
            'file_name' => basename($cleanPath),
            'file_size' => (file_exists($fullPath) && is_file($fullPath)) ? filesize($fullPath) : 0,
            'file_ext' => $ext,
            'created_at' => null
        ];
    }

    return $items;
}

/**
 * Batch fetch attachments for multiple expense IDs.
 */
function getBatchExpenseAttachments(array $expenseIds): array {
    if (empty($expenseIds)) return [];
    ensureExpenseAttachmentsTable();
    $db = getDBConnection();

    $placeholders = implode(',', array_fill(0, count($expenseIds), '?'));
    $stmt = $db->prepare("
        SELECT * FROM expense_attachments 
        WHERE expense_id IN ($placeholders) 
        ORDER BY id ASC
    ");
    $stmt->execute(array_values($expenseIds));
    $all = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $grouped = [];
    foreach ($all as $item) {
        $grouped[$item['expense_id']][] = $item;
    }
    return $grouped;
}
