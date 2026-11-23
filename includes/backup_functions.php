<?php
/**
 * Tenant Backup & Restore Utility Functions
 * Supports per-company export, import, and restore with full data and assets (logo)
 */

require_once __DIR__ . '/../config/app.php';

/**
 * Exports all data and assets for a specific company into a structured array
 */
function exportCompanyBackup(int $companyId): array {
    $db = getDBConnection();

    // 1. Company profile
    $stmt = $db->prepare("SELECT * FROM companies WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $companyId]);
    $company = $stmt->fetch();

    if (!$company) {
        throw new Exception("الشركة برقم {$companyId} غير موجودة.");
    }

    // Embed Logo file as Base64 if exists
    $logoBase64 = null;
    $logoFilename = null;
    if (!empty($company['logo']) && file_exists(APP_PATH . '/' . $company['logo'])) {
        $logoFullPath = APP_PATH . '/' . $company['logo'];
        $logoData = file_get_contents($logoFullPath);
        if ($logoData !== false) {
            $logoBase64 = base64_encode($logoData);
            $logoFilename = basename($company['logo']);
        }
    }
    $company['logo_base64'] = $logoBase64;
    $company['logo_filename'] = $logoFilename;

    // 2. Fetch Safes
    $stmt = $db->prepare("SELECT * FROM safes WHERE company_id = :cid ORDER BY id ASC");
    $stmt->execute([':cid' => $companyId]);
    $safes = $stmt->fetchAll();

    // 3. Safe Balances
    $stmt = $db->prepare("SELECT * FROM safe_balances WHERE company_id = :cid ORDER BY id ASC");
    $stmt->execute([':cid' => $companyId]);
    $safeBalances = $stmt->fetchAll();

    // 4. Safe Transactions
    $stmt = $db->prepare("SELECT * FROM safe_transactions WHERE company_id = :cid ORDER BY id ASC");
    $stmt->execute([':cid' => $companyId]);
    $safeTransactions = $stmt->fetchAll();

    // 5. Expense Categories
    $stmt = $db->prepare("SELECT * FROM expense_categories WHERE company_id = :cid ORDER BY id ASC");
    $stmt->execute([':cid' => $companyId]);
    $categories = $stmt->fetchAll();

    // 6. Projects
    $stmt = $db->prepare("SELECT * FROM projects WHERE company_id = :cid ORDER BY id ASC");
    $stmt->execute([':cid' => $companyId]);
    $projects = $stmt->fetchAll();

    // 7. Expenses
    $stmt = $db->prepare("SELECT * FROM expenses WHERE company_id = :cid ORDER BY id ASC");
    $stmt->execute([':cid' => $companyId]);
    $expenses = $stmt->fetchAll();

    // 8. Receipts
    $stmt = $db->prepare("SELECT * FROM receipts WHERE company_id = :cid ORDER BY id ASC");
    $stmt->execute([':cid' => $companyId]);
    $receipts = $stmt->fetchAll();

    // 9. Invoices & Items
    $stmt = $db->prepare("SELECT * FROM invoices WHERE company_id = :cid ORDER BY id ASC");
    $stmt->execute([':cid' => $companyId]);
    $invoices = $stmt->fetchAll();

    $invoiceIds = array_column($invoices, 'id');
    $invoiceItems = [];
    if (!empty($invoiceIds)) {
        $inClause = implode(',', array_map('intval', $invoiceIds));
        $itemStmt = $db->query("SELECT * FROM invoice_items WHERE invoice_id IN ({$inClause}) ORDER BY id ASC");
        $invoiceItems = $itemStmt->fetchAll();
    }

    // 10. Employees & Payrolls
    $stmt = $db->prepare("SELECT * FROM employees WHERE company_id = :cid ORDER BY id ASC");
    $stmt->execute([':cid' => $companyId]);
    $employees = $stmt->fetchAll();

    $stmt = $db->prepare("SELECT * FROM payrolls WHERE company_id = :cid ORDER BY id ASC");
    $stmt->execute([':cid' => $companyId]);
    $payrolls = $stmt->fetchAll();

    // 11. Company Users
    $stmt = $db->prepare("SELECT id, company_id, name, username, password, role, phone, is_active, created_at FROM users WHERE company_id = :cid ORDER BY id ASC");
    $stmt->execute([':cid' => $companyId]);
    $users = $stmt->fetchAll();

    return [
        'format' => 'FINANCE_SAAS_COMPANY_BACKUP',
        'version' => '2.0',
        'exported_at' => date('Y-m-d H:i:s'),
        'company_id' => $companyId,
        'company' => $company,
        'counts' => [
            'safes' => count($safes),
            'safe_transactions' => count($safeTransactions),
            'expenses' => count($expenses),
            'receipts' => count($receipts),
            'invoices' => count($invoices),
            'invoice_items' => count($invoiceItems),
            'projects' => count($projects),
            'categories' => count($categories),
            'employees' => count($employees),
            'payrolls' => count($payrolls),
            'users' => count($users)
        ],
        'data' => [
            'safes' => $safes,
            'safe_balances' => $safeBalances,
            'expense_categories' => $categories,
            'projects' => $projects,
            'safe_transactions' => $safeTransactions,
            'expenses' => $expenses,
            'receipts' => $receipts,
            'invoices' => $invoices,
            'invoice_items' => $invoiceItems,
            'employees' => $employees,
            'payrolls' => $payrolls,
            'users' => $users
        ]
    ];
}

/**
 * Outputs backup as JSON download to the browser
 */
function downloadCompanyBackup(int $companyId): void {
    $backup = exportCompanyBackup($companyId);
    $compCode = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $backup['company']['code'] ?? ('company_' . $companyId));
    $dateStr = date('Y-m-d_His');
    $filename = "backup_{$compCode}_{$dateStr}.json";

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Restores a company's data from backup array safely inside a transaction
 */
function restoreCompanyBackup(int $targetCompanyId, array $backup, bool $restoreProfile = true): array {
    $db = getDBConnection();

    if (($backup['format'] ?? '') !== 'FINANCE_SAAS_COMPANY_BACKUP') {
        throw new Exception("الملف المرفوع ليس ملف نسخة احتياطية صالح للمنصة (تنسيق غير معتمد).");
    }

    $cData = $backup['company'] ?? null;
    $d = $backup['data'] ?? [];

    if (!$cData) {
        throw new Exception("بيانات الشركة مفقودة داخل ملف النسخة الاحتياطية.");
    }

    $db->beginTransaction();

    try {
        // 1. Update Company Profile & Logo if requested
        if ($restoreProfile) {
            $logoPath = null;
            if (!empty($cData['logo_base64'])) {
                $rawLogo = base64_decode($cData['logo_base64']);
                if ($rawLogo !== false) {
                    $ext = pathinfo($cData['logo_filename'] ?? 'logo.png', PATHINFO_EXTENSION) ?: 'png';
                    $uploadDir = APP_PATH . '/uploads/logos';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0777, true);
                    }
                    $newLogoFile = 'uploads/logos/company_' . $targetCompanyId . '_' . time() . '.' . $ext;
                    file_put_contents(APP_PATH . '/' . $newLogoFile, $rawLogo);
                    $logoPath = $newLogoFile;
                }
            }

            $updComp = $db->prepare("
                UPDATE companies SET
                    name = :name,
                    phone = :phone,
                    currency = :curr,
                    tax_number = :tax,
                    cr_number = :cr,
                    address = :addr,
                    building_no = :bno,
                    street_name = :sname,
                    district = :dist,
                    city = :city,
                    postal_code = :pcode,
                    additional_no = :addno,
                    country = :country,
                    email = :email,
                    location_url = :loc_url,
                    primary_color = :pcolor,
                    dashboard_color = :dcolor,
                    module_treasury = :mod_tre,
                    module_expenses = :mod_exp,
                    module_hr = :mod_hr,
                    module_reports = :mod_rep,
                    module_projects = :mod_proj
                    " . ($logoPath ? ", logo = :logo" : "") . "
                WHERE id = :cid
            ");

            $compParams = [
                ':cid' => $targetCompanyId,
                ':name' => $cData['name'] ?? 'شركة مستعادة',
                ':phone' => $cData['phone'] ?? null,
                ':curr' => $cData['currency'] ?? 'ج.م',
                ':tax' => $cData['tax_number'] ?? null,
                ':cr' => $cData['cr_number'] ?? null,
                ':addr' => $cData['address'] ?? null,
                ':bno' => $cData['building_no'] ?? null,
                ':sname' => $cData['street_name'] ?? null,
                ':dist' => $cData['district'] ?? null,
                ':city' => $cData['city'] ?? null,
                ':pcode' => $cData['postal_code'] ?? null,
                ':addno' => $cData['additional_no'] ?? null,
                ':country' => $cData['country'] ?? 'SA',
                ':email' => $cData['email'] ?? null,
                ':loc_url' => $cData['location_url'] ?? null,
                ':pcolor' => $cData['primary_color'] ?? '#b91c1c',
                ':dcolor' => $cData['dashboard_color'] ?? '#4f46e5',
                ':mod_tre' => (int)($cData['module_treasury'] ?? 1),
                ':mod_exp' => (int)($cData['module_expenses'] ?? 1),
                ':mod_hr' => (int)($cData['module_hr'] ?? 0),
                ':mod_rep' => (int)($cData['module_reports'] ?? 1),
                ':mod_proj' => (int)($cData['module_projects'] ?? 0),
            ];
            if ($logoPath) {
                $compParams[':logo'] = $logoPath;
            }
            $updComp->execute($compParams);
        }

        // 2. Clean existing records of target company only (strict tenant isolation)
        $invIdsStmt = $db->prepare("SELECT id FROM invoices WHERE company_id = :cid");
        $invIdsStmt->execute([':cid' => $targetCompanyId]);
        $existingInvIds = $invIdsStmt->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($existingInvIds)) {
            $inClause = implode(',', array_map('intval', $existingInvIds));
            $db->exec("DELETE FROM invoice_items WHERE invoice_id IN ({$inClause})");
        }

        $tablesToClean = [
            'invoices', 'receipts', 'expenses', 'safe_transactions',
            'safe_balances', 'payrolls', 'employees', 'projects',
            'expense_categories', 'safes'
        ];
        foreach ($tablesToClean as $tbl) {
            $delStmt = $db->prepare("DELETE FROM {$tbl} WHERE company_id = :cid");
            $delStmt->execute([':cid' => $targetCompanyId]);
        }

        // ID Mapping dictionaries: old_id => new_id
        $safeMap = [];
        $catMap = [];
        $projMap = [];
        $txMap = [];
        $invMap = [];
        $empMap = [];
        $userMap = [];

        // 3. Users: match by username or insert
        $backupUsers = $d['users'] ?? [];
        foreach ($backupUsers as $u) {
            $checkU = $db->prepare("SELECT id FROM users WHERE username = :un LIMIT 1");
            $checkU->execute([':un' => $u['username']]);
            $existingUserId = $checkU->fetchColumn();

            if ($existingUserId) {
                // Link user to this company if needed
                $updU = $db->prepare("UPDATE users SET company_id = :cid, name = :name, role = :role WHERE id = :id");
                $updU->execute([
                    ':cid' => $targetCompanyId,
                    ':name' => $u['name'],
                    ':role' => $u['role'],
                    ':id' => $existingUserId
                ]);
                $userMap[$u['id']] = (int)$existingUserId;
            } else {
                $insU = $db->prepare("
                    INSERT INTO users (company_id, name, username, password, role, phone, is_active, created_at)
                    VALUES (:cid, :name, :un, :pass, :role, :phone, :active, :created)
                ");
                $insU->execute([
                    ':cid' => $targetCompanyId,
                    ':name' => $u['name'],
                    ':un' => $u['username'],
                    ':pass' => $u['password'],
                    ':role' => $u['role'],
                    ':phone' => $u['phone'] ?? null,
                    ':active' => $u['is_active'] ?? 1,
                    ':created' => $u['created_at'] ?? date('Y-m-d H:i:s')
                ]);
                $userMap[$u['id']] = (int)$db->lastInsertId();
            }
        }

        // Fallback user if needed
        $fallbackUserId = !empty($userMap) ? reset($userMap) : 1;

        // 4. Safes
        $backupSafes = $d['safes'] ?? [];
        $insSafe = $db->prepare("
            INSERT INTO safes (company_id, name, balance, project_id, type, created_at)
            VALUES (:cid, :name, :bal, :pid, :type, :created)
        ");
        foreach ($backupSafes as $s) {
            $insSafe->execute([
                ':cid' => $targetCompanyId,
                ':name' => $s['name'],
                ':bal' => (float)$s['balance'],
                ':pid' => null, // will link project later if needed
                ':type' => $s['type'] ?? 'main',
                ':created' => $s['created_at'] ?? date('Y-m-d H:i:s')
            ]);
            $newSafeId = (int)$db->lastInsertId();
            $safeMap[$s['id']] = $newSafeId;
        }

        // 5. Safe Balances
        $backupBalances = $d['safe_balances'] ?? [];
        $insBal = $db->prepare("
            INSERT INTO safe_balances (company_id, safe_id, currency, balance, updated_at)
            VALUES (:cid, :sid, :curr, :bal, :upd)
        ");
        foreach ($backupBalances as $sb) {
            $newSid = $safeMap[$sb['safe_id']] ?? null;
            if ($newSid) {
                $insBal->execute([
                    ':cid' => $targetCompanyId,
                    ':sid' => $newSid,
                    ':curr' => $sb['currency'],
                    ':bal' => (float)$sb['balance'],
                    ':upd' => $sb['updated_at'] ?? date('Y-m-d H:i:s')
                ]);
            }
        }

        // 6. Expense Categories
        $backupCats = $d['expense_categories'] ?? [];
        $insCat = $db->prepare("
            INSERT INTO expense_categories (company_id, name, icon, is_active, created_at)
            VALUES (:cid, :name, :icon, :active, :created)
        ");
        foreach ($backupCats as $cat) {
            $insCat->execute([
                ':cid' => $targetCompanyId,
                ':name' => $cat['name'],
                ':icon' => $cat['icon'] ?? 'fa-folder',
                ':active' => $cat['is_active'] ?? 1,
                ':created' => $cat['created_at'] ?? date('Y-m-d H:i:s')
            ]);
            $catMap[$cat['id']] = (int)$db->lastInsertId();
        }

        // 7. Projects
        $backupProjects = $d['projects'] ?? [];
        $insProj = $db->prepare("
            INSERT INTO projects (company_id, name, code, capital, currency, safe_id, location, status, description, start_date, end_date, share_token, created_at)
            VALUES (:cid, :name, :code, :cap, :curr, :sid, :loc, :status, :desc, :sdate, :edate, :token, :created)
        ");
        foreach ($backupProjects as $p) {
            $pSid = !empty($p['safe_id']) && isset($safeMap[$p['safe_id']]) ? $safeMap[$p['safe_id']] : null;
            $insProj->execute([
                ':cid' => $targetCompanyId,
                ':name' => $p['name'],
                ':code' => $p['code'] ?? null,
                ':cap' => (float)($p['capital'] ?? 0),
                ':curr' => $p['currency'] ?? 'ج.م',
                ':sid' => $pSid,
                ':loc' => $p['location'] ?? null,
                ':status' => $p['status'] ?? 'active',
                ':desc' => $p['description'] ?? null,
                ':sdate' => $p['start_date'] ?? null,
                ':edate' => $p['end_date'] ?? null,
                ':token' => $p['share_token'] ?? null,
                ':created' => $p['created_at'] ?? date('Y-m-d H:i:s')
            ]);
            $projMap[$p['id']] = (int)$db->lastInsertId();
        }

        // 8. Safe Transactions
        $backupTxs = $d['safe_transactions'] ?? [];
        $insTx = $db->prepare("
            INSERT INTO safe_transactions (company_id, safe_id, user_id, type, category, amount, balance_before, balance_after, description, transaction_date, currency, project_id, created_at)
            VALUES (:cid, :sid, :uid, :type, :cat, :amt, :bbef, :baft, :desc, :tdate, :curr, :pid, :created)
        ");
        foreach ($backupTxs as $tx) {
            $tSid = $safeMap[$tx['safe_id']] ?? null;
            if (!$tSid && !empty($safeMap)) {
                $tSid = reset($safeMap);
            }
            if ($tSid) {
                $tUid = $userMap[$tx['user_id']] ?? $fallbackUserId;
                $tPid = !empty($tx['project_id']) && isset($projMap[$tx['project_id']]) ? $projMap[$tx['project_id']] : null;
                $insTx->execute([
                    ':cid' => $targetCompanyId,
                    ':sid' => $tSid,
                    ':uid' => $tUid,
                    ':type' => $tx['type'],
                    ':cat' => $tx['category'],
                    ':amt' => (float)$tx['amount'],
                    ':bbef' => (float)$tx['balance_before'],
                    ':baft' => (float)$tx['balance_after'],
                    ':desc' => $tx['description'] ?? null,
                    ':tdate' => $tx['transaction_date'],
                    ':curr' => $tx['currency'] ?? 'ج.م',
                    ':pid' => $tPid,
                    ':created' => $tx['created_at'] ?? date('Y-m-d H:i:s')
                ]);
                $txMap[$tx['id']] = (int)$db->lastInsertId();
            }
        }

        // 9. Expenses
        $backupExpenses = $d['expenses'] ?? [];
        $insExp = $db->prepare("
            INSERT INTO expenses (company_id, category_id, safe_id, safe_transaction_id, user_id, amount, title, description, expense_date, currency, project_id, invoice_attachment, tax_rate, tax_amount, beneficiary_name, beneficiary_id_number, beneficiary_phone, voucher_number, status, cheque_number, bank_name, cheque_date, created_at)
            VALUES (:cid, :catid, :sid, :txid, :uid, :amt, :title, :desc, :edate, :curr, :pid, :attach, :trate, :tamt, :bname, :bid, :bphone, :vno, :status, :cno, :bname_bank, :cdate, :created)
        ");
        foreach ($backupExpenses as $exp) {
            $eSid = $safeMap[$exp['safe_id']] ?? (!empty($safeMap) ? reset($safeMap) : 1);
            $eCatId = !empty($exp['category_id']) && isset($catMap[$exp['category_id']]) ? $catMap[$exp['category_id']] : null;
            $eTxId = !empty($exp['safe_transaction_id']) && isset($txMap[$exp['safe_transaction_id']]) ? $txMap[$exp['safe_transaction_id']] : null;
            $ePid = !empty($exp['project_id']) && isset($projMap[$exp['project_id']]) ? $projMap[$exp['project_id']] : null;
            $eUid = $userMap[$exp['user_id']] ?? $fallbackUserId;

            $insExp->execute([
                ':cid' => $targetCompanyId,
                ':catid' => $eCatId,
                ':sid' => $eSid,
                ':txid' => $eTxId,
                ':uid' => $eUid,
                ':amt' => (float)$exp['amount'],
                ':title' => $exp['title'],
                ':desc' => $exp['description'] ?? null,
                ':edate' => $exp['expense_date'],
                ':curr' => $exp['currency'] ?? 'ج.م',
                ':pid' => $ePid,
                ':attach' => $exp['invoice_attachment'] ?? null,
                ':trate' => (float)($exp['tax_rate'] ?? 0),
                ':tamt' => (float)($exp['tax_amount'] ?? 0),
                ':bname' => $exp['beneficiary_name'] ?? null,
                ':bid' => $exp['beneficiary_id_number'] ?? null,
                ':bphone' => $exp['beneficiary_phone'] ?? null,
                ':vno' => $exp['voucher_number'] ?? null,
                ':status' => $exp['status'] ?? 'deducted',
                ':cno' => $exp['cheque_number'] ?? null,
                ':bname_bank' => $exp['bank_name'] ?? null,
                ':cdate' => $exp['cheque_date'] ?? null,
                ':created' => $exp['created_at'] ?? date('Y-m-d H:i:s')
            ]);
        }

        // 10. Receipts
        $backupReceipts = $d['receipts'] ?? [];
        $insRec = $db->prepare("
            INSERT INTO receipts (receipt_number, company_id, user_id, safe_transaction_id, customer_name, customer_phone, amount, amount_words, payment_method, purpose, receipt_date, notes, currency, cheque_number, bank_name, cheque_date, created_at)
            VALUES (:rno, :cid, :uid, :txid, :cname, :cphone, :amt, :awords, :pmethod, :purp, :rdate, :notes, :curr, :cno, :bname, :cdate, :created)
        ");
        foreach ($backupReceipts as $rec) {
            $rTxId = !empty($rec['safe_transaction_id']) && isset($txMap[$rec['safe_transaction_id']]) ? $txMap[$rec['safe_transaction_id']] : null;
            $rUid = $userMap[$rec['user_id']] ?? $fallbackUserId;

            $insRec->execute([
                ':rno' => $rec['receipt_number'],
                ':cid' => $targetCompanyId,
                ':uid' => $rUid,
                ':txid' => $rTxId,
                ':cname' => $rec['customer_name'],
                ':cphone' => $rec['customer_phone'] ?? null,
                ':amt' => (float)$rec['amount'],
                ':awords' => $rec['amount_words'] ?? null,
                ':pmethod' => $rec['payment_method'] ?? 'نقداً',
                ':purp' => $rec['purpose'],
                ':rdate' => $rec['receipt_date'],
                ':notes' => $rec['notes'] ?? null,
                ':curr' => $rec['currency'] ?? 'ج.م',
                ':cno' => $rec['cheque_number'] ?? null,
                ':bname' => $rec['bank_name'] ?? null,
                ':cdate' => $rec['cheque_date'] ?? null,
                ':created' => $rec['created_at'] ?? date('Y-m-d H:i:s')
            ]);
        }

        // 11. Invoices & Items
        $backupInvoices = $d['invoices'] ?? [];
        $backupItems = $d['invoice_items'] ?? [];

        $insInv = $db->prepare("
            INSERT INTO invoices (company_id, user_id, invoice_number, invoice_date, customer_name, customer_phone, customer_tax_number, payment_method, discount_days, subtotal, tax_rate, tax_amount, discount_amount, total_amount, currency, warehouse, seller_name, notes, qr_data, status, created_at)
            VALUES (:cid, :uid, :ino, :idate, :cname, :cphone, :ctax, :pmethod, :ddays, :subtotal, :trate, :tamt, :damt, :total, :curr, :wh, :seller, :notes, :qr, :status, :created)
        ");
        foreach ($backupInvoices as $inv) {
            $iUid = $userMap[$inv['user_id']] ?? $fallbackUserId;
            $insInv->execute([
                ':cid' => $targetCompanyId,
                ':uid' => $iUid,
                ':ino' => $inv['invoice_number'],
                ':idate' => $inv['invoice_date'],
                ':cname' => $inv['customer_name'],
                ':cphone' => $inv['customer_phone'] ?? null,
                ':ctax' => $inv['customer_tax_number'] ?? null,
                ':pmethod' => $inv['payment_method'] ?? 'نقداً',
                ':ddays' => (int)($inv['discount_days'] ?? 0),
                ':subtotal' => (float)($inv['subtotal'] ?? 0),
                ':trate' => (float)($inv['tax_rate'] ?? 15.00),
                ':tamt' => (float)($inv['tax_amount'] ?? 0),
                ':damt' => (float)($inv['discount_amount'] ?? 0),
                ':total' => (float)($inv['total_amount'] ?? 0),
                ':curr' => $inv['currency'] ?? 'ج.م',
                ':wh' => $inv['warehouse'] ?? 'المستودع الرئيسي',
                ':seller' => $inv['seller_name'] ?? 'المبيعات',
                ':notes' => $inv['notes'] ?? null,
                ':qr' => $inv['qr_data'] ?? null,
                ':status' => $inv['status'] ?? 'issued',
                ':created' => $inv['created_at'] ?? date('Y-m-d H:i:s')
            ]);
            $invMap[$inv['id']] = (int)$db->lastInsertId();
        }

        // Invoice items
        $insItem = $db->prepare("
            INSERT INTO invoice_items (invoice_id, item_code, item_name, quantity, unit, unit_price, discount_amount, tax_rate, tax_amount, total_amount)
            VALUES (:invid, :code, :name, :qty, :unit, :price, :disc, :trate, :tamt, :total)
        ");
        foreach ($backupItems as $it) {
            $newInvId = $invMap[$it['invoice_id']] ?? null;
            if ($newInvId) {
                $insItem->execute([
                    ':invid' => $newInvId,
                    ':code' => $it['item_code'] ?? null,
                    ':name' => $it['item_name'],
                    ':qty' => (float)($it['quantity'] ?? 1),
                    ':unit' => $it['unit'] ?? 'طن',
                    ':price' => (float)($it['unit_price'] ?? 0),
                    ':disc' => (float)($it['discount_amount'] ?? 0),
                    ':trate' => (float)($it['tax_rate'] ?? 15.00),
                    ':tamt' => (float)($it['tax_amount'] ?? 0),
                    ':total' => (float)($it['total_amount'] ?? 0)
                ]);
            }
        }

        // 12. Employees & Payrolls
        $backupEmployees = $d['employees'] ?? [];
        $insEmp = $db->prepare("
            INSERT INTO employees (company_id, name, job_title, phone, base_salary, hire_date, status, notes, created_at)
            VALUES (:cid, :name, :job, :phone, :salary, :hdate, :status, :notes, :created)
        ");
        foreach ($backupEmployees as $emp) {
            $insEmp->execute([
                ':cid' => $targetCompanyId,
                ':name' => $emp['name'],
                ':job' => $emp['job_title'] ?? null,
                ':phone' => $emp['phone'] ?? null,
                ':salary' => (float)($emp['base_salary'] ?? 0),
                ':hdate' => $emp['hire_date'] ?? null,
                ':status' => $emp['status'] ?? 'active',
                ':notes' => $emp['notes'] ?? null,
                ':created' => $emp['created_at'] ?? date('Y-m-d H:i:s')
            ]);
            $empMap[$emp['id']] = (int)$db->lastInsertId();
        }

        $backupPayrolls = $d['payrolls'] ?? [];
        $insPay = $db->prepare("
            INSERT INTO payrolls (company_id, employee_id, user_id, month_year, base_salary, bonus, deductions, net_salary, status, paid_date, safe_transaction_id, notes, currency, created_at)
            VALUES (:cid, :empid, :uid, :myear, :bsalary, :bonus, :ded, :netsalary, :status, :pdate, :txid, :notes, :curr, :created)
        ");
        foreach ($backupPayrolls as $pr) {
            $newEmpId = $empMap[$pr['employee_id']] ?? null;
            if ($newEmpId) {
                $pTxId = !empty($pr['safe_transaction_id']) && isset($txMap[$pr['safe_transaction_id']]) ? $txMap[$pr['safe_transaction_id']] : null;
                $pUid = $userMap[$pr['user_id']] ?? $fallbackUserId;
                $insPay->execute([
                    ':cid' => $targetCompanyId,
                    ':empid' => $newEmpId,
                    ':uid' => $pUid,
                    ':myear' => $pr['month_year'],
                    ':bsalary' => (float)$pr['base_salary'],
                    ':bonus' => (float)($pr['bonus'] ?? 0),
                    ':ded' => (float)($pr['deductions'] ?? 0),
                    ':netsalary' => (float)$pr['net_salary'],
                    ':status' => $pr['status'] ?? 'paid',
                    ':pdate' => $pr['paid_date'] ?? null,
                    ':txid' => $pTxId,
                    ':notes' => $pr['notes'] ?? null,
                    ':curr' => $pr['currency'] ?? 'ج.م',
                    ':created' => $pr['created_at'] ?? date('Y-m-d H:i:s')
                ]);
            }
        }

        $db->commit();

        return [
            'success' => true,
            'message' => 'تم استعادة النسخة الاحتياطية بنجاح تام وتحديث كافة البيانات والشعار!',
            'restored_company' => $cData['name'] ?? '',
            'counts' => [
                'safes' => count($backupSafes),
                'safe_transactions' => count($backupTxs),
                'expenses' => count($backupExpenses),
                'receipts' => count($backupReceipts),
                'invoices' => count($backupInvoices),
                'projects' => count($backupProjects)
            ]
        ];

    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}
