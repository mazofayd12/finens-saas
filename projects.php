<?php
require_once __DIR__ . '/config/app.php';
requireLogin();
requireModule('projects');
requirePermission('projects');

$currentUser = getCurrentUser();
$db = getDBConnection();
$activeCompanyId = (int)($currentUser['active_company_id'] ?? 0);
if ($activeCompanyId <= 0 && isSuperAdmin()) {
    $firstComp = $db->query("SELECT id FROM companies WHERE is_active = 1 AND module_projects = 1 LIMIT 1")->fetch();
    if (!$firstComp) {
        $firstComp = $db->query("SELECT id FROM companies WHERE is_active = 1 LIMIT 1")->fetch();
    }
    if ($firstComp) {
        $activeCompanyId = (int)$firstComp['id'];
    }
}
$currency = $currentUser['currency'] ?? 'ر.س';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireNotViewOnly();
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        die('خطأ في التحقق من صحة الطلب (CSRF)');
    }

    $action = $_POST['action'] ?? '';

    // 1. Create Real Estate Project
    if ($action === 'create_project') {
        $name = trim($_POST['name'] ?? '');
        $code = trim($_POST['code'] ?? '');
        $projectType = trim($_POST['project_type'] ?? 'with_capital');
        if (!in_array($projectType, ['with_capital', 'without_capital'])) {
            $projectType = 'with_capital';
        }
        $clientName = trim($_POST['client_name'] ?? '');
        $clientPhone = trim($_POST['client_phone'] ?? '');
        $clientFeePct = max(0, (float)($_POST['client_fee_percentage'] ?? 0));
        $landPrice = max(0, (float)($_POST['land_price'] ?? 0));
        $landBrokerage = max(0, (float)($_POST['land_brokerage'] ?? 0));
        $landTax = max(0, (float)($_POST['land_tax'] ?? 0));

        if ($projectType === 'without_capital') {
            $capital = 0.00;
            $depositInitial = false;
            if (empty($clientName)) {
                $clientName = 'عميل مشروع ' . $name;
            }
        } else {
            $capital = max(0, (float)($_POST['capital'] ?? 0));
            $depositInitial = isset($_POST['deposit_initial']) && $_POST['deposit_initial'] === '1';
        }

        $projCurrency = trim($_POST['currency'] ?? $currency);
        $location = trim($_POST['location'] ?? '');
        $startDate = !empty($_POST['start_date']) ? trim($_POST['start_date']) : date('Y-m-d');
        $endDate = !empty($_POST['end_date']) ? trim($_POST['end_date']) : null;
        $description = trim($_POST['description'] ?? '');

        if (empty($name)) {
            setFlash('error', 'يرجى إدخال اسم المشروع العقاري.');
        } else {
            if (empty($code)) {
                $code = 'PRJ-' . strtoupper(substr(md5(uniqid()), 0, 5));
            }

            $db->beginTransaction();
            try {
                $now = date('Y-m-d H:i:s');
                $shareToken = bin2hex(random_bytes(16));

                // A. Insert Project
                $insPrj = $db->prepare("
                    INSERT INTO projects (
                        company_id, name, code, capital, currency, location, status,
                        project_type, client_name, client_phone, client_fee_percentage,
                        land_price, land_brokerage, land_tax,
                        description, start_date, end_date, share_token, created_at
                    ) VALUES (
                        :cid, :name, :code, :cap, :curr, :loc, 'active',
                        :ptype, :cname, :cphone, :cfee,
                        :lprice, :lbrok, :ltax,
                        :desc, :sdate, :edate, :tok, :now
                    )
                ");
                $insPrj->execute([
                    ':cid' => $activeCompanyId,
                    ':name' => $name,
                    ':code' => $code,
                    ':cap' => $capital,
                    ':curr' => $projCurrency,
                    ':loc' => $location,
                    ':ptype' => $projectType,
                    ':cname' => $clientName,
                    ':cphone' => $clientPhone,
                    ':cfee' => $clientFeePct,
                    ':lprice' => $landPrice,
                    ':lbrok' => $landBrokerage,
                    ':ltax' => $landTax,
                    ':desc' => $description,
                    ':sdate' => $startDate,
                    ':edate' => $endDate,
                    ':tok' => $shareToken,
                    ':now' => $now
                ]);
                $projectId = (int)$db->lastInsertId();

                // B. Create Dedicated Safe for this Project
                $safeName = 'خزنة ' . $name;
                $insSafe = $db->prepare("
                    INSERT INTO safes (company_id, name, balance, project_id, type, created_at)
                    VALUES (:cid, :name, 0.00, :pid, 'project', :now)
                ");
                $insSafe->execute([
                    ':cid' => $activeCompanyId,
                    ':name' => $safeName,
                    ':pid' => $projectId,
                    ':now' => $now
                ]);
                $safeId = (int)$db->lastInsertId();

                // Link safe to project
                $db->prepare("UPDATE projects SET safe_id = :sid WHERE id = :pid")->execute([
                    ':sid' => $safeId,
                    ':pid' => $projectId
                ]);

                // C. Initial Capital Deposit if selected (Capital based projects only)
                if ($projectType === 'with_capital' && $depositInitial && $capital > 0) {
                    recordSafeTransaction(
                        $activeCompanyId,
                        $safeId,
                        $currentUser['id'],
                        'deposit',
                        'رأس مال معتمد',
                        $capital,
                        'إيداع رأس المال الافتتاحي المخصص لمشروع: ' . $name,
                        $startDate,
                        $projCurrency,
                        $projectId
                    );
                }

                // D. Automatically record Land & Acquisition expenses if provided
                $landItems = [];
                if ($landPrice > 0) {
                    $landItems[] = [
                        'cat' => 'قيمة الأرض',
                        'title' => 'قيمة الأرض - شراء أرض المشروع',
                        'desc' => 'قيمة شراء أرض المشروع',
                        'amt' => $landPrice
                    ];
                }
                if ($landBrokerage > 0) {
                    $landItems[] = [
                        'cat' => 'السعي',
                        'title' => 'السعي - سعي ودلالة شراء أرض المشروع',
                        'desc' => 'سعي ودلالة شراء أرض المشروع',
                        'amt' => $landBrokerage
                    ];
                }
                if ($landTax > 0) {
                    $landItems[] = [
                        'cat' => 'الضريبة',
                        'title' => 'الضريبة - ضريبة شراء أرض المشروع',
                        'desc' => 'ضريبة شراء أرض المشروع',
                        'amt' => $landTax
                    ];
                }

                if (!empty($landItems)) {
                    $catStmt = $db->prepare("SELECT id, name FROM expense_categories WHERE company_id = :cid");
                    $catStmt->execute([':cid' => $activeCompanyId]);
                    $cMap = [];
                    foreach ($catStmt->fetchAll(PDO::FETCH_ASSOC) as $cRow) {
                        $cMap[trim($cRow['name'])] = (int)$cRow['id'];
                    }
                    $insCat = $db->prepare("INSERT INTO expense_categories (company_id, name, created_at) VALUES (:cid, :name, :now)");

                    // Get current project safe balance
                    $currentSafeBal = (float)$db->query("SELECT balance FROM safes WHERE id = {$safeId}")->fetchColumn();
                    $voucherIndex = 0;

                    foreach ($landItems as $lItem) {
                        $voucherIndex++;
                        $cName = $lItem['cat'];
                        if (!isset($cMap[$cName])) {
                            $insCat->execute([':cid' => $activeCompanyId, ':name' => $cName, ':now' => $now]);
                            $cMap[$cName] = (int)$db->lastInsertId();
                        }
                        $cId = $cMap[$cName];
                        $lAmt = $lItem['amt'];
                        $vchNum = 'VCH-' . strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $code), 0, 6)) . '-' . str_pad($voucherIndex, 4, '0', STR_PAD_LEFT);
                        $sToken = bin2hex(random_bytes(16));

                        $bBefore = $currentSafeBal;
                        $bAfter = $currentSafeBal - $lAmt;
                        $currentSafeBal = $bAfter;

                        // 1. Safe Transaction
                        $txStmt = $db->prepare("
                            INSERT INTO safe_transactions (
                                company_id, safe_id, user_id, type, category, amount,
                                balance_before, balance_after, description, transaction_date,
                                created_at, currency, project_id
                            ) VALUES (
                                :cid, :sid, :uid, 'expense', :cat, :amt,
                                :bb, :ba, :desc, :dt, :created, :curr, :pid
                            )
                        ");
                        $txStmt->execute([
                            ':cid' => $activeCompanyId,
                            ':sid' => $safeId,
                            ':uid' => $currentUser['id'],
                            ':cat' => $cName,
                            ':amt' => $lAmt,
                            ':bb' => $bBefore,
                            ':ba' => $bAfter,
                            ':desc' => $lItem['title'],
                            ':dt' => $startDate,
                            ':created' => $now,
                            ':curr' => $projCurrency,
                            ':pid' => $projectId
                        ]);
                        $txId = (int)$db->lastInsertId();

                        // 2. Expense Record
                        $insExp = $db->prepare("
                            INSERT INTO expenses (
                                company_id, category_id, safe_id, safe_transaction_id, user_id,
                                amount, title, description, expense_date, created_at, currency,
                                project_id, voucher_number, status, approved_by, approved_at,
                                payment_method, sign_token, signature_status
                            ) VALUES (
                                :cid, :catid, :sid, :txid, :uid,
                                :amt, :title, :desc, :dt, :created, :curr,
                                :pid, :vnum, 'deducted', :appby, :appat,
                                'تحويل بنكي', :stok, 'pending_signature'
                            )
                        ");
                        $insExp->execute([
                            ':cid' => $activeCompanyId,
                            ':catid' => $cId,
                            ':sid' => $safeId,
                            ':txid' => $txId,
                            ':uid' => $currentUser['id'],
                            ':amt' => $lAmt,
                            ':title' => $lItem['title'],
                            ':desc' => $lItem['desc'],
                            ':dt' => $startDate,
                            ':created' => $now,
                            ':curr' => $projCurrency,
                            ':pid' => $projectId,
                            ':vnum' => $vchNum,
                            ':appby' => $currentUser['id'],
                            ':appat' => $now,
                            ':stok' => $sToken
                        ]);
                    }

                    // Update safe balance
                    $db->prepare("UPDATE safes SET balance = :bal WHERE id = :sid")->execute([
                        ':bal' => $currentSafeBal,
                        ':sid' => $safeId
                    ]);
                    $db->prepare("INSERT OR REPLACE INTO safe_balances (company_id, safe_id, currency, balance, updated_at) VALUES (:cid, :sid, :curr, :bal, :now)")->execute([
                        ':cid' => $activeCompanyId,
                        ':sid' => $safeId,
                        ':curr' => $projCurrency,
                        ':bal' => $currentSafeBal,
                        ':now' => $now
                    ]);
                }

                $db->commit();
                $typeMsg = $projectType === 'without_capital' ? 'مشروع بدون رأس مال (مصروفات لحساب عميل)' : 'مشروع برأس مال معتمد';
                setFlash('success', "تم إنشاء المشروع العقاري ({$name}) كـ {$typeMsg} بنجاح!");
                header('Location: project_view.php?id=' . $projectId);
                exit;
            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                setFlash('error', 'حدث خطأ أثناء إنشاء المشروع: ' . $e->getMessage());
            }
        }
    }

    // 2. Deposit into Project Safe
    elseif ($action === 'deposit_project') {
        $projectId = (int)($_POST['project_id'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);
        $depCurrency = trim($_POST['currency'] ?? $currency);
        $category = trim($_POST['category'] ?? 'تمويل مشروع');
        $description = trim($_POST['description'] ?? '');
        $txDate = trim($_POST['transaction_date'] ?? date('Y-m-d'));

        if ($projectId <= 0 || $amount <= 0) {
            setFlash('error', 'يرجى تحديد المشروع وإدخال مبلغ إيداع صحيح.');
        } else {
            try {
                $pStmt = $db->prepare("SELECT * FROM projects WHERE id = :pid AND company_id = :cid");
                $pStmt->execute([':pid' => $projectId, ':cid' => $activeCompanyId]);
                $prj = $pStmt->fetch();

                if (!$prj) {
                    throw new Exception('المشروع غير موجود.');
                }

                $safe = getProjectSafe($activeCompanyId, $projectId, $prj['name']);

                recordSafeTransaction(
                    $activeCompanyId,
                    $safe['id'],
                    $currentUser['id'],
                    'deposit',
                    $category,
                    $amount,
                    $description ?: ('إيداع سيولة في خزنة ' . $prj['name']),
                    $txDate,
                    $depCurrency,
                    $projectId
                );

                setFlash('success', "تم إيداع مبلغ " . formatMoney($amount, $depCurrency) . " في خزنة مشروع ({$prj['name']}) بنجاح!");
                header('Location: project_view.php?id=' . $projectId);
                exit;
            } catch (Exception $e) {
                setFlash('error', $e->getMessage());
            }
        }
    }

    // 3. Project Expense / Deduction with 15% VAT & Attachment
    elseif ($action === 'expense_project') {
        $projectId = (int)($_POST['project_id'] ?? 0);
        $baseAmount = (float)($_POST['amount'] ?? 0);
        $applyVat = isset($_POST['apply_vat']) && $_POST['apply_vat'] === '1';
        $expCurrency = trim($_POST['currency'] ?? $currency);
        $title = trim($_POST['title'] ?? ''); // e.g. حديد، أسمنت
        $categoryId = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
        $newCategoryName = trim($_POST['new_category_name'] ?? '');
        if (empty($categoryId) && !empty($newCategoryName)) {
            $createdCatId = getOrCreateExpenseCategory($activeCompanyId, $newCategoryName);
            if ($createdCatId) {
                $categoryId = $createdCatId;
            }
        }
        $description = trim($_POST['description'] ?? '');
        $beneficiaryName = trim($_POST['beneficiary_name'] ?? '');
        $beneficiaryId = trim($_POST['beneficiary_id_number'] ?? '');
        $beneficiaryPhone = trim($_POST['beneficiary_phone'] ?? '');
        $expenseDate = trim($_POST['expense_date'] ?? date('Y-m-d'));
        $printAfter = isset($_POST['print_after']) && $_POST['print_after'] === '1';

        if ($projectId <= 0 || $baseAmount <= 0 || empty($title)) {
            setFlash('error', 'يرجى إدخال المشروع، بند الصرف (السبب)، والمبلغ.');
        } else {
            $taxRate = $applyVat ? 15.00 : 0.00;
            $taxAmount = $applyVat ? round($baseAmount * 0.15, 2) : 0.00;
            $totalAmount = $baseAmount + $taxAmount;

            $db->beginTransaction();
            try {
                // Fetch project & safe
                $pStmt = $db->prepare("SELECT * FROM projects WHERE id = :pid AND company_id = :cid");
                $pStmt->execute([':pid' => $projectId, ':cid' => $activeCompanyId]);
                $prj = $pStmt->fetch();
                if (!$prj) throw new Exception('المشروع غير موجود.');

                $safe = getProjectSafe($activeCompanyId, $projectId, $prj['name']);

                // Handle invoice file upload
                $attachmentPath = null;
                if (isset($_FILES['invoice_file']) && $_FILES['invoice_file']['error'] === UPLOAD_ERR_OK) {
                    $fileTmp = $_FILES['invoice_file']['tmp_name'];
                    $fileName = $_FILES['invoice_file']['name'];
                    $fileSize = $_FILES['invoice_file']['size'];
                    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

                    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
                    if (!in_array($ext, $allowed)) {
                        throw new Exception('نوع الملف المرفق غير مدعوم. الصيغ المدعومة: JPG, PNG, WEBP, PDF');
                    }
                    if ($fileSize > 15 * 1024 * 1024) {
                        throw new Exception('حجم ملف الفاتورة كبير جداً، الحد الأقصى 15 ميجابايت.');
                    }

                    $uploadDir = APP_PATH . '/assets/uploads/invoices/';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0777, true);
                    }
                    $newFileName = 'inv_' . $projectId . '_' . time() . '_' . rand(100, 999) . '.' . $ext;
                    if (move_uploaded_file($fileTmp, $uploadDir . $newFileName)) {
                        $attachmentPath = 'assets/uploads/invoices/' . $newFileName;
                    }
                }

                // Category name
                $catName = $title;
                if ($categoryId) {
                    $cStmt = $db->prepare("SELECT name FROM expense_categories WHERE id = :id AND company_id = :cid");
                    $cStmt->execute([':id' => $categoryId, ':cid' => $activeCompanyId]);
                    $catRow = $cStmt->fetch();
                    if ($catRow) $catName = $catRow['name'];
                }

                $voucherNo = generateVoucherNumber($activeCompanyId);

                // Safe transaction deduction
                $txDesc = "صرف مشروع ({$prj['name']}) - {$title}" . ($beneficiaryName ? " | المستفيد: {$beneficiaryName}" : "");
                if ($applyVat) {
                    $txDesc .= " (شامل ضريبة 15%: " . formatMoney($taxAmount, $expCurrency) . ")";
                }

                $txResult = recordSafeTransaction(
                    $activeCompanyId,
                    $safe['id'],
                    $currentUser['id'],
                    'expense',
                    $catName,
                    $totalAmount,
                    $txDesc,
                    $expenseDate,
                    $expCurrency,
                    $projectId
                );

                $signToken = bin2hex(random_bytes(16));
                $issuerSig = !empty($currentUser['signature']) ? $currentUser['signature'] : ($company['signature'] ?? null);

                // Insert into expenses
                $insExp = $db->prepare("
                    INSERT INTO expenses (
                        company_id, category_id, safe_id, safe_transaction_id, user_id, project_id,
                        amount, currency, title, description, expense_date,
                        invoice_attachment, tax_rate, tax_amount, beneficiary_name, beneficiary_id_number, beneficiary_phone, voucher_number,
                        sign_token, issuer_signature, signature_status,
                        created_at
                    ) VALUES (
                        :cid, :catid, :sid, :txid, :uid, :pid,
                        :amt, :curr, :title, :desc, :edate,
                        :att, :trate, :tamt, :bname, :bid, :bphone, :vnum,
                        :stok, :isig, 'pending_signature',
                        :now
                    )
                ");
                $insExp->execute([
                    ':cid' => $activeCompanyId,
                    ':catid' => $categoryId,
                    ':sid' => $safe['id'],
                    ':txid' => $txResult['transaction_id'],
                    ':uid' => $currentUser['id'],
                    ':pid' => $projectId,
                    ':amt' => $totalAmount,
                    ':curr' => $expCurrency,
                    ':title' => $title,
                    ':desc' => $description,
                    ':edate' => $expenseDate,
                    ':att' => $attachmentPath,
                    ':trate' => $taxRate,
                    ':tamt' => $taxAmount,
                    ':bname' => $beneficiaryName,
                    ':bid' => $beneficiaryId,
                    ':bphone' => $beneficiaryPhone,
                    ':vnum' => $voucherNo,
                    ':stok' => $signToken,
                    ':isig' => $issuerSig,
                    ':now' => date('Y-m-d H:i:s')
                ]);
                $expenseId = (int)$db->lastInsertId();

                $db->commit();

                setFlash('success', "تم تسجيل الفاتورة وخصم مبلغ " . formatMoney($totalAmount, $expCurrency) . " من خزنة مشروع {$prj['name']} بنجاح! رقم السند: {$voucherNo}");

                if ($printAfter) {
                    header('Location: project_voucher_print.php?id=' . $expenseId);
                } else {
                    header('Location: project_view.php?id=' . $projectId);
                }
                exit;
            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                setFlash('error', $e->getMessage());
            }
        }
    }
}

// Fetch all projects with stats
$query = "
    SELECT p.*,
           s.balance as safe_balance,
           (SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE project_id = p.id AND company_id = p.company_id AND (status IN ('deducted', 'approved') OR status IS NULL)) as total_expenses,
           (SELECT COUNT(*) FROM expenses WHERE project_id = p.id AND company_id = p.company_id AND (status IN ('deducted', 'approved') OR status IS NULL)) as expenses_count,
           (SELECT COALESCE(SUM(amount), 0) FROM safe_transactions WHERE safe_id = p.safe_id AND type = 'deposit') as total_deposited,
           (SELECT COALESCE(SUM(amount), 0) FROM receipts WHERE project_id = p.id AND company_id = p.company_id) as total_receipts
    FROM projects p
    LEFT JOIN safes s ON p.safe_id = s.id
    WHERE p.company_id = :cid
    ORDER BY p.id DESC
";
$stmt = $db->prepare($query);
$stmt->execute([':cid' => $activeCompanyId]);
$projects = $stmt->fetchAll();

// Fetch expense categories
$catStmt = $db->prepare("SELECT * FROM expense_categories WHERE company_id = :cid AND is_active = 1 ORDER BY name ASC");
$catStmt->execute([':cid' => $activeCompanyId]);
$categories = $catStmt->fetchAll();

// Global stats for real estate
$totalCapitalAll = 0;
$totalExpensesAll = 0;
$totalSafeBalanceAll = 0;
$totalClientDueAll = 0;
foreach ($projects as &$pr) {
    $pCap = (float)$pr['capital'];
    $pExp = (float)$pr['total_expenses'];
    $pRec = (float)$pr['total_receipts'];
    $pFeePct = (float)($pr['client_fee_percentage'] ?? 0);
    $pFeeAmt = round($pExp * ($pFeePct / 100), 2);
    $pBillable = $pExp + $pFeeAmt;
    $pDue = round($pBillable - $pRec, 2);

    $pr['client_fee_amount'] = $pFeeAmt;
    $pr['total_billable'] = $pBillable;
    $pr['client_paid'] = $pRec;
    $pr['client_due'] = $pDue;

    if (($pr['project_type'] ?? '') !== 'without_capital') {
        $totalCapitalAll += $pCap;
    } else {
        if ($pDue > 0) {
            $totalClientDueAll += $pDue;
        }
    }
    $totalExpensesAll += $pExp;
    $totalSafeBalanceAll += (float)$pr['safe_balance'];
}
unset($pr);

$pageTitle = 'مشاريع التطوير العقاري';
require_once __DIR__ . '/includes/header.php';
?>

<div class="space-y-6">
    <!-- Header with Action Buttons -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <div class="flex items-center space-x-2 space-x-reverse">
                <span class="w-10 h-10 rounded-xl bg-amber-500 text-white flex items-center justify-center font-bold text-lg shadow-md">
                    <i class="fa-solid fa-city"></i>
                </span>
                <div>
                    <h1 class="text-2xl font-black text-slate-900">مشاريع التطوير العقاري والمقاولات</h1>
                    <p class="text-xs text-slate-500">إدارة خزن المشاريع، تسجيل فواتير الشراء والصرف، وإصدار سندات الدفع A4</p>
                </div>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <button onclick="openModal('quick-project-expense-modal')" class="inline-flex items-center bg-rose-600 hover:bg-rose-700 text-white font-bold px-4 py-2.5 rounded-xl shadow-md transition text-xs sm:text-sm">
                <i class="fa-solid fa-file-invoice-dollar ml-1.5"></i>
                تسجيل فاتورة / خصم لمشروع
            </button>
            <button onclick="openModal('quick-project-deposit-modal')" class="inline-flex items-center bg-emerald-600 hover:bg-emerald-700 text-white font-bold px-4 py-2.5 rounded-xl shadow-md transition text-xs sm:text-sm">
                <i class="fa-solid fa-circle-plus ml-1.5"></i>
                إيداع سيولة لمشروع
            </button>
            <button onclick="openModal('add-project-modal')" class="inline-flex items-center bg-amber-600 hover:bg-amber-700 text-white font-bold px-4 py-2.5 rounded-xl shadow-md transition text-xs sm:text-sm">
                <i class="fa-solid fa-plus-circle ml-1.5"></i>
                مشروع عقاري جديد
            </button>
        </div>
    </div>

    <!-- KPI Summary Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <!-- 1: Total Projects -->
        <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs flex items-center justify-between">
            <div>
                <p class="text-xs font-bold text-slate-400 mb-1">عدد المشاريع العقارية</p>
                <h3 class="text-2xl font-black text-slate-800"><?= count($projects) ?> <span class="text-xs font-normal text-slate-400">مشروع</span></h3>
            </div>
            <div class="w-12 h-12 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center text-xl font-bold">
                <i class="fa-solid fa-building-columns"></i>
            </div>
        </div>

        <!-- 2: Total Capital -->
        <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs flex items-center justify-between">
            <div>
                <p class="text-xs font-bold text-slate-400 mb-1">إجمالي رؤوس أموال المشاريع</p>
                <h3 class="text-xl font-black text-blue-600"><?= formatMoney($totalCapitalAll, $currency) ?></h3>
            </div>
            <div class="w-12 h-12 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center text-xl font-bold">
                <i class="fa-solid fa-coins"></i>
            </div>
        </div>

        <!-- 3: Total Safe Balances -->
        <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs flex items-center justify-between">
            <div>
                <p class="text-xs font-bold text-slate-400 mb-1">السيولة المتاحة في خزن المشاريع</p>
                <h3 class="text-xl font-black text-emerald-600"><?= formatMoney($totalSafeBalanceAll, $currency) ?></h3>
            </div>
            <div class="w-12 h-12 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center text-xl font-bold">
                <i class="fa-solid fa-vault"></i>
            </div>
        </div>

        <!-- 4: Total Spent -->
        <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs flex items-center justify-between">
            <div>
                <p class="text-xs font-bold text-slate-400 mb-1">إجمالي المصروفات المنفذة</p>
                <h3 class="text-xl font-black text-rose-600"><?= formatMoney($totalExpensesAll, $currency) ?></h3>
            </div>
            <div class="w-12 h-12 rounded-xl bg-rose-50 text-rose-600 flex items-center justify-center text-xl font-bold">
                <i class="fa-solid fa-hand-holding-dollar"></i>
            </div>
        </div>
    </div>

    <!-- Projects Grid / Cards -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <?php if (empty($projects)): ?>
            <div class="col-span-full bg-white rounded-3xl p-12 text-center border border-slate-200">
                <div class="w-20 h-20 rounded-2xl bg-amber-50 text-amber-500 mx-auto flex items-center justify-center text-3xl mb-4">
                    <i class="fa-solid fa-city"></i>
                </div>
                <h3 class="text-lg font-black text-slate-800">لا توجد مشاريع عقارية مسجلة حتى الآن</h3>
                <p class="text-xs text-slate-500 mt-1 max-w-md mx-auto">ابدأ بإضافة أول مشروع عقاري للشركة (مثل: مشروع Bulad Cairo)، وحدد رأس المال وتفاصيل المشروع لمتابعة خزنته ومصاريفه بدقة.</p>
                <button onclick="openModal('add-project-modal')" class="mt-5 inline-flex items-center bg-amber-600 hover:bg-amber-700 text-white font-bold px-5 py-2.5 rounded-xl shadow-md transition text-sm">
                    <i class="fa-solid fa-plus-circle ml-2"></i>
                    إضافة أول مشروع عقاري الآن
                </button>
            </div>
        <?php else: ?>
            <?php foreach ($projects as $proj): ?>
                <?php
                $isWithoutCap = ($proj['project_type'] ?? '') === 'without_capital';
                $pCap = (float)$proj['capital'];
                $pExp = (float)$proj['total_expenses'];
                $pBal = (float)$proj['safe_balance'];
                $pCurr = !empty($proj['currency']) ? $proj['currency'] : $currency;
                $pPaid = (float)($proj['client_paid'] ?? 0);
                $pDue = (float)($proj['client_due'] ?? 0);
                $pct = $pCap > 0 ? min(100, round(($pExp / $pCap) * 100, 1)) : 0;
                ?>
                <div class="bg-white rounded-3xl border border-slate-200/90 shadow-xs hover:shadow-md transition flex flex-col justify-between overflow-hidden group">
                    <div class="p-5 space-y-4">
                        <!-- Top Row: Code & Status & Type Badge -->
                        <div class="flex items-center justify-between gap-1">
                            <div class="flex items-center space-x-1.5 space-x-reverse">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-lg text-[10px] font-mono font-bold bg-slate-100 text-slate-700 border border-slate-200">
                                    #<?= htmlspecialchars($proj['code'] ?: ('PRJ-' . $proj['id'])) ?>
                                </span>
                                <?php if ($isWithoutCap): ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-black bg-indigo-50 text-indigo-700 border border-indigo-200">
                                        <i class="fa-solid fa-file-invoice-dollar ml-1"></i>بدون رأس مال (عميل)
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-black bg-blue-50 text-blue-700 border border-blue-200">
                                        <i class="fa-solid fa-vault ml-1"></i>برأس مال محدد
                                    </span>
                                <?php endif; ?>
                            </div>
                            <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold <?= $proj['status'] === 'active' ? 'bg-emerald-100 text-emerald-800' : ($proj['status'] === 'completed' ? 'bg-blue-100 text-blue-800' : 'bg-amber-100 text-amber-800') ?>">
                                <?= $proj['status'] === 'active' ? 'قيد التنفيذ' : ($proj['status'] === 'completed' ? 'مكتمل' : 'معلق') ?>
                            </span>
                        </div>

                        <!-- Project Title & Location -->
                        <div>
                            <h3 class="font-black text-slate-900 text-lg leading-tight group-hover:text-amber-600 transition">
                                <a href="project_view.php?id=<?= $proj['id'] ?>">
                                    <?= htmlspecialchars($proj['name']) ?>
                                </a>
                            </h3>
                            <div class="flex flex-wrap items-center gap-2 mt-1 text-xs text-slate-500 font-medium">
                                <?php if ($isWithoutCap && !empty($proj['client_name'])): ?>
                                    <span class="text-indigo-700 font-bold flex items-center">
                                        <i class="fa-solid fa-user-tie ml-1 text-indigo-500"></i>
                                        العميل: <?= htmlspecialchars($proj['client_name']) ?>
                                    </span>
                                    <span>•</span>
                                <?php endif; ?>
                                <?php if (!empty($proj['location'])): ?>
                                    <span class="flex items-center">
                                        <i class="fa-solid fa-location-dot ml-1 text-slate-400"></i>
                                        <?= htmlspecialchars($proj['location']) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Prominent Total Project Expenses Box -->
                        <div class="bg-rose-50/90 border border-rose-200/90 rounded-2xl p-3 flex items-center justify-between shadow-2xs">
                            <div class="flex items-center space-x-2.5 space-x-reverse">
                                <div class="w-9 h-9 rounded-xl bg-rose-600 text-white flex items-center justify-center text-sm shadow-xs">
                                    <i class="fa-solid fa-receipt"></i>
                                </div>
                                <div>
                                    <span class="text-xs font-black text-rose-950 block">إجمالي مصروفات المشروع</span>
                                    <span class="text-[10px] text-slate-500 font-medium"><?= (int)($proj['expenses_count'] ?? 0) ?> فاتورة وسند صرف</span>
                                </div>
                            </div>
                            <div class="text-left">
                                <span class="text-lg sm:text-xl font-black text-rose-600 dir-ltr block"><?= formatMoney($pExp, $pCurr) ?></span>
                            </div>
                        </div>

                        <!-- Financial Indicators Box -->
                        <?php if ($isWithoutCap): ?>
                            <!-- WITHOUT CAPITAL: Client Billing Breakdown -->
                            <div class="bg-indigo-50/40 rounded-2xl p-3.5 border border-indigo-100 space-y-2">
                                <div class="flex items-center justify-between text-xs">
                                    <span class="text-slate-600 font-bold">إجمالي المصروفات المنفذة:</span>
                                    <span class="font-black text-rose-600"><?= formatMoney($pExp, $pCurr) ?></span>
                                </div>
                                <div class="flex items-center justify-between text-xs">
                                    <span class="text-slate-600 font-bold">المسدد من العميل:</span>
                                    <span class="font-black text-emerald-600"><?= formatMoney($pPaid, $pCurr) ?></span>
                                </div>
                                <div class="pt-2 border-t border-indigo-200/60 flex items-center justify-between">
                                    <div>
                                        <span class="text-[11px] font-black text-slate-700 block">المفروض العميل يدفع:</span>
                                        <span class="text-[10px] text-slate-500 font-medium">المتبقي المطلوب سداده</span>
                                    </div>
                                    <div class="text-left">
                                        <span class="text-sm font-black <?= $pDue > 0 ? 'text-rose-600' : ($pDue < 0 ? 'text-blue-600' : 'text-emerald-600') ?>">
                                            <?= $pDue == 0 ? 'خالص (0.00)' : formatMoney(abs($pDue), $pCurr) ?>
                                        </span>
                                        <?php if ($pDue > 0): ?>
                                            <span class="block text-[9px] font-bold text-rose-500">مطلوب من العميل</span>
                                        <?php elseif ($pDue < 0): ?>
                                            <span class="block text-[9px] font-bold text-blue-500">فائض دائن للعميل</span>
                                        <?php else: ?>
                                            <span class="block text-[9px] font-bold text-emerald-600">مسدد بالكامل</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <!-- WITH CAPITAL: Budget & Burn Rate Breakdown -->
                            <div class="bg-slate-50 rounded-2xl p-3.5 border border-slate-100 space-y-2">
                                <div class="flex items-center justify-between text-xs">
                                    <span class="text-slate-500 font-bold">رأس المال المخصص:</span>
                                    <span class="font-black text-slate-800"><?= formatMoney($pCap, $pCurr) ?></span>
                                </div>
                                <div class="flex items-center justify-between text-xs">
                                    <span class="text-slate-500 font-bold">رصيد خزنة المشروع:</span>
                                    <span class="font-black <?= $pBal > 0 ? 'text-emerald-600' : 'text-slate-600' ?>"><?= formatMoney($pBal, $pCurr) ?></span>
                                </div>
                                <div class="flex items-center justify-between text-xs">
                                    <span class="text-slate-500 font-bold">إجمالي المصروفات (خصومات):</span>
                                    <span class="font-black text-rose-600"><?= formatMoney($pExp, $pCurr) ?></span>
                                </div>

                                <!-- Progress Bar -->
                                <div class="pt-1.5">
                                    <div class="flex items-center justify-between text-[10px] text-slate-400 mb-1">
                                        <span>المستهلك من الميزانية</span>
                                        <span class="font-black <?= $pct > 85 ? 'text-rose-600' : 'text-amber-600' ?>"><?= $pct ?>%</span>
                                    </div>
                                    <div class="w-full bg-slate-200 h-2 rounded-full overflow-hidden">
                                        <div class="h-full <?= $pct > 85 ? 'bg-rose-500' : 'bg-amber-500' ?> transition-all duration-500" style="width: <?= $pct ?>%"></div>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($proj['description'])): ?>
                            <p class="text-xs text-slate-500 line-clamp-2 leading-relaxed">
                                <?= htmlspecialchars($proj['description']) ?>
                            </p>
                        <?php endif; ?>
                    </div>

                    <!-- Card Footer Actions -->
                    <div class="p-4 bg-slate-50 border-t border-slate-100 flex items-center justify-between gap-2">
                        <a href="project_view.php?id=<?= $proj['id'] ?>" class="flex-1 text-center bg-amber-600 hover:bg-amber-700 text-white font-bold py-2 px-3 rounded-xl text-xs transition shadow-xs flex items-center justify-center">
                            <i class="fa-solid fa-eye ml-1.5"></i>
                            <?= $isWithoutCap ? 'كشف حساب ومطالبة العميل' : 'تفاصيل وكشف الحساب' ?>
                        </a>
                        <button onclick="openProjectExpenseModal(<?= $proj['id'] ?>, '<?= htmlspecialchars(addslashes($proj['name'])) ?>', '<?= $pCurr ?>')" class="bg-rose-50 hover:bg-rose-100 text-rose-700 p-2 rounded-xl text-xs transition font-bold" title="تسجيل صرف / شراء حديد ومواد">
                            <i class="fa-solid fa-receipt"></i>
                        </button>
                        <button onclick="openProjectDepositModal(<?= $proj['id'] ?>, '<?= htmlspecialchars(addslashes($proj['name'])) ?>', '<?= $pCurr ?>')" class="bg-emerald-50 hover:bg-emerald-100 text-emerald-700 p-2 rounded-xl text-xs transition font-bold" title="إيداع في الخزنة">
                            <i class="fa-solid fa-plus"></i>
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- 1. ADD PROJECT MODAL -->
<div id="add-project-modal" class="modal-container fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/60 backdrop-blur-xs p-4 overflow-y-auto">
    <div class="modal-backdrop fixed inset-0" onclick="closeModal('add-project-modal')"></div>
    <div class="relative bg-white rounded-3xl shadow-2xl max-w-xl w-full p-6 sm:p-8 modal-animate-enter z-10 border border-slate-100">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-5">
            <div class="flex items-center space-x-2 space-x-reverse">
                <div class="w-10 h-10 rounded-xl bg-amber-100 text-amber-600 flex items-center justify-center font-bold text-lg">
                    <i class="fa-solid fa-city"></i>
                </div>
                <div>
                    <h3 class="font-black text-slate-800 text-lg">إضافة مشروع عقاري جديد</h3>
                    <p class="text-xs text-slate-400">تأسيس المشروع، تحديد رأس المال، وإنشاء خزنة مستقلة له</p>
                </div>
            </div>
            <button onclick="closeModal('add-project-modal')" class="text-slate-400 hover:text-slate-600 text-lg">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form action="projects.php" method="POST" class="space-y-4">
            <?= getCSRFInput() ?>
            <input type="hidden" name="action" value="create_project">
            <input type="hidden" id="modal_project_type" name="project_type" value="with_capital">

            <!-- 1. Selection: Project Type (With Capital vs Without Capital) -->
            <div>
                <label class="block text-xs font-black text-slate-800 mb-2">نوع تمويل وطبيعة المشروع <span class="text-red-500">*</span></label>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <!-- Option A: With Capital -->
                    <div id="btn-type-with-cap" onclick="switchAddProjectType('with_capital')" class="cursor-pointer p-3.5 rounded-2xl border-2 border-amber-500 bg-amber-50/60 transition hover:shadow-xs flex flex-col justify-between">
                        <div class="flex items-center space-x-2 space-x-reverse mb-1">
                            <div class="w-8 h-8 rounded-xl bg-amber-500 text-white flex items-center justify-center text-sm font-bold shadow-xs shrink-0">
                                <i class="fa-solid fa-vault"></i>
                            </div>
                            <span class="text-xs font-black text-slate-900">مشروع برأس مال محدد</span>
                        </div>
                        <p class="text-[11px] text-slate-600 leading-tight pr-1">تحديد ميزانية ورأس مال معتمد، مع متابعة نسبة الاستهلاك والرصيد المتبقي بالخزنة.</p>
                    </div>

                    <!-- Option B: Without Capital (Client Billable Expenses) -->
                    <div id="btn-type-without-cap" onclick="switchAddProjectType('without_capital')" class="cursor-pointer p-3.5 rounded-2xl border-2 border-slate-200 bg-slate-50 transition hover:border-indigo-400 hover:shadow-xs flex flex-col justify-between">
                        <div class="flex items-center space-x-2 space-x-reverse mb-1">
                            <div class="w-8 h-8 rounded-xl bg-indigo-600 text-white flex items-center justify-center text-sm font-bold shadow-xs shrink-0">
                                <i class="fa-solid fa-file-invoice-dollar"></i>
                            </div>
                            <span class="text-xs font-black text-slate-900">مشروع بدون رأس مال</span>
                        </div>
                        <p class="text-[11px] text-slate-600 leading-tight pr-1">مشروع مصروفات لحساب عميل؛ كل مصروف يسجل يظهر تلقائياً العميل المفروض يدفع كام.</p>
                    </div>
                </div>
            </div>

            <!-- SECTION C: LAND & ACQUISITION COSTS (OPTIONAL) -->
            <div class="p-4 rounded-2xl bg-amber-50/60 border border-amber-200/80 space-y-3">
                <div class="flex items-center justify-between">
                    <div class="flex items-center space-x-2 space-x-reverse text-amber-900 font-black text-xs">
                        <i class="fa-solid fa-map-location-dot text-amber-600 text-sm"></i>
                        <span>تكاليف شراء وتوثيق الأرض (اختياري)</span>
                    </div>
                    <span class="text-[10px] font-bold text-amber-700 bg-amber-100 px-2 py-0.5 rounded-full">خاص بالمشاريع المتضمنة شراء أرض</span>
                </div>
                <p class="text-[11px] text-slate-600 leading-tight">
                    إذا كان المشروع يتضمن شراء أرض، يمكنك إدخال قيمتها وتوابعها هنا لتسجيلها تلقائياً كسندات صرف أولية للمشروع، أو اتركها فارغة إذا لم تكن مشتراة.
                </p>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                        <label class="block text-[11px] font-bold text-slate-700 mb-1">قيمة شراء الأرض (ر.س)</label>
                        <input type="number" step="0.01" min="0" name="land_price" placeholder="0.00" class="w-full bg-white border border-slate-300 rounded-xl px-3 py-2 text-xs font-bold text-slate-800 focus:ring-2 focus:ring-amber-500 focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-slate-700 mb-1">قيمة السعي / الدلالة (ر.س)</label>
                        <input type="number" step="0.01" min="0" name="land_brokerage" placeholder="0.00" class="w-full bg-white border border-slate-300 rounded-xl px-3 py-2 text-xs font-bold text-slate-800 focus:ring-2 focus:ring-amber-500 focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-slate-700 mb-1">ضريبة التصرفات / الشراء (ر.س)</label>
                        <input type="number" step="0.01" min="0" name="land_tax" placeholder="0.00" class="w-full bg-white border border-slate-300 rounded-xl px-3 py-2 text-xs font-bold text-slate-800 focus:ring-2 focus:ring-amber-500 focus:outline-none">
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">اسم المشروع العقاري <span class="text-red-500">*</span></label>
                    <input type="text" name="name" required placeholder="مثال: فيلا الياسمين، تشطيب شقة التجمع" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-sm focus:bg-white focus:ring-2 focus:ring-amber-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">كود المشروع (معرّف اختياري)</label>
                    <input type="text" name="code" placeholder="مثال: PRJ-SLM" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-sm font-mono focus:bg-white focus:ring-2 focus:ring-amber-500 focus:outline-none">
                </div>
            </div>

            <!-- SECTION A: WITH CAPITAL FIELDS -->
            <div id="section-with-capital" class="space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-bold text-slate-700 mb-1">رأس المال المعتمد للمشروع <span class="text-red-500">*</span></label>
                        <input type="number" step="0.01" min="0" id="input_capital" name="capital" required placeholder="0.00" class="w-full text-lg font-bold bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-blue-600 focus:bg-white focus:ring-2 focus:ring-amber-500 focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">العملة</label>
                        <select name="currency" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2.5 text-xs font-bold focus:bg-white focus:ring-2 focus:ring-amber-500 focus:outline-none">
                            <option value="ر.س" <?= $currency === 'ر.س' ? 'selected' : '' ?>>ر.س (ريال سعودي)</option>
                            <option value="ج.م" <?= $currency === 'ج.م' ? 'selected' : '' ?>>ج.م (جنيه مصري)</option>
                            <option value="$" <?= $currency === '$' ? 'selected' : '' ?>>$ (دولار أمريكي)</option>
                            <option value="د.إ" <?= $currency === 'د.إ' ? 'selected' : '' ?>>د.إ (درهم إماراتي)</option>
                            <option value="د.ك" <?= $currency === 'د.ك' ? 'selected' : '' ?>>د.ك (دينار كويتي)</option>
                        </select>
                    </div>
                </div>

                <!-- Initial deposit toggle -->
                <div class="p-3 bg-emerald-50 rounded-2xl border border-emerald-200">
                    <label class="flex items-center space-x-2 space-x-reverse cursor-pointer">
                        <input type="checkbox" name="deposit_initial" value="1" checked class="rounded text-emerald-600 focus:ring-emerald-500 w-4 h-4">
                        <span class="text-xs font-bold text-emerald-900">إيداع رأس المال فوراً في خزنة المشروع كرصيد افتتاحي لبدء الصرف منه</span>
                    </label>
                </div>
            </div>

            <!-- SECTION B: WITHOUT CAPITAL (CLIENT EXPENSES / BILLING) FIELDS -->
            <div id="section-without-capital" class="hidden space-y-4">
                <div class="p-3 bg-indigo-50/80 rounded-2xl border border-indigo-200 text-xs text-indigo-900 leading-relaxed flex items-start space-x-2 space-x-reverse">
                    <i class="fa-solid fa-lightbulb text-indigo-600 text-sm mt-0.5 ml-1.5 shrink-0"></i>
                    <div>
                        <strong class="font-black block">مشروع بدون رأس مال (مصروفات لحساب عميل):</strong>
                        <span>كل مصروف تسجله على هذا المشروع (شراء مواد، مقاولات، فواتير...) يضاف تلقائياً لحساب العميل ليظهر لك صافي المبلغ المطلوب منه دفعه، مع إمكانية تحصيل دفعات منه وإصدار سندات قبض رسمية وكشف حساب A4.</span>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">اسم العميل (صاحب المشروع) <span class="text-red-500">*</span></label>
                        <input type="text" id="input_client_name" name="client_name" placeholder="مثال: أحمد عبد الله، م/ محمود كمال" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-sm focus:bg-white focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">رقم هاتف العميل (اختياري)</label>
                        <input type="text" name="client_phone" placeholder="01xxxxxxxxx / 05xxxxxxxx" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-sm dir-ltr text-right focus:bg-white focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">نسبة أتعاب الإشراف % (اختياري)</label>
                        <input type="number" step="0.5" min="0" max="100" name="client_fee_percentage" placeholder="0%" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-sm focus:bg-white focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                        <span class="text-[10px] text-slate-400 mt-0.5 block">تضاف تلقائياً فوق قيمة المصروفات عند المطالبة (اتركها 0 إن لم توجد عمولة)</span>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">عملة الحساب مع العميل</label>
                        <select name="currency_alt" onchange="document.querySelector('[name=currency]').value = this.value" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2.5 text-xs font-bold focus:bg-white focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                            <option value="ر.س" <?= $currency === 'ر.س' ? 'selected' : '' ?>>ر.س (ريال سعودي)</option>
                            <option value="ج.م" <?= $currency === 'ج.م' ? 'selected' : '' ?>>ج.م (جنيه مصري)</option>
                            <option value="$" <?= $currency === '$' ? 'selected' : '' ?>>$ (دولار أمريكي)</option>
                            <option value="د.إ" <?= $currency === 'د.إ' ? 'selected' : '' ?>>د.إ (درهم إماراتي)</option>
                            <option value="د.ك" <?= $currency === 'د.ك' ? 'selected' : '' ?>>د.ك (دينار كويتي)</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- SECTION C: LAND & ACQUISITION COSTS (OPTIONAL) -->
            <div class="p-4 rounded-2xl bg-amber-50/60 border border-amber-200/80 space-y-3">
                <div class="flex items-center justify-between">
                    <div class="flex items-center space-x-2 space-x-reverse text-amber-900 font-black text-xs">
                        <i class="fa-solid fa-map-location-dot text-amber-600 text-sm"></i>
                        <span>تكاليف شراء وتوثيق الأرض (اختياري)</span>
                    </div>
                    <span class="text-[10px] font-bold text-amber-700 bg-amber-100 px-2 py-0.5 rounded-full">خاص بالمشاريع المتضمنة شراء أرض</span>
                </div>
                <p class="text-[11px] text-slate-600 leading-tight">
                    إذا كان المشروع يتضمن شراء أرض، يمكنك إدخال قيمتها وتوابعها هنا لتسجيلها تلقائياً كسندات صرف أولية للمشروع، أو اتركها فارغة إذا لم تكن مشتراة.
                </p>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                        <label class="block text-[11px] font-bold text-slate-700 mb-1">قيمة شراء الأرض (ر.س)</label>
                        <input type="number" step="0.01" min="0" name="land_price" placeholder="0.00" class="w-full bg-white border border-slate-300 rounded-xl px-3 py-2 text-xs font-bold text-slate-800 focus:ring-2 focus:ring-amber-500 focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-slate-700 mb-1">قيمة السعي / الدلالة (ر.س)</label>
                        <input type="number" step="0.01" min="0" name="land_brokerage" placeholder="0.00" class="w-full bg-white border border-slate-300 rounded-xl px-3 py-2 text-xs font-bold text-slate-800 focus:ring-2 focus:ring-amber-500 focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-slate-700 mb-1">ضريبة التصرفات / الشراء (ر.س)</label>
                        <input type="number" step="0.01" min="0" name="land_tax" placeholder="0.00" class="w-full bg-white border border-slate-300 rounded-xl px-3 py-2 text-xs font-bold text-slate-800 focus:ring-2 focus:ring-amber-500 focus:outline-none">
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">موقع المشروع</label>
                    <input type="text" name="location" placeholder="مثال: الرياض - حي النرجس" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-sm focus:bg-white focus:ring-2 focus:ring-amber-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">تاريخ بدء المشروع</label>
                    <input type="date" name="start_date" value="<?= date('Y-m-d') ?>" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-sm focus:bg-white focus:ring-2 focus:ring-amber-500 focus:outline-none">
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">تفاصيل وملاحظات المشروع</label>
                <textarea name="description" rows="2" placeholder="مواصفات المشروع، العقد، أو أي شروط خاصة..." class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-sm focus:bg-white focus:ring-2 focus:ring-amber-500 focus:outline-none"></textarea>
            </div>

            <div class="flex items-center space-x-2 space-x-reverse pt-2">
                <button type="submit" class="flex-1 bg-amber-600 hover:bg-amber-700 text-white font-bold py-2.5 px-4 rounded-xl shadow-md transition text-sm">
                    إنشاء المشروع والخزنة الآن
                </button>
                <button type="button" onclick="closeModal('add-project-modal')" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold py-2.5 px-4 rounded-xl transition text-sm">
                    إلغاء
                </button>
            </div>
        </form>

        <script>
            function switchAddProjectType(type) {
                const hiddenInput = document.getElementById('modal_project_type');
                const btnWith = document.getElementById('btn-type-with-cap');
                const btnWithout = document.getElementById('btn-type-without-cap');
                const secWith = document.getElementById('section-with-capital');
                const secWithout = document.getElementById('section-without-capital');
                const inputCap = document.getElementById('input_capital');
                const inputClient = document.getElementById('input_client_name');

                hiddenInput.value = type;

                if (type === 'without_capital') {
                    btnWithout.classList.remove('border-slate-200', 'bg-slate-50');
                    btnWithout.classList.add('border-indigo-600', 'bg-indigo-50/60');
                    btnWith.classList.remove('border-amber-500', 'bg-amber-50/60');
                    btnWith.classList.add('border-slate-200', 'bg-slate-50');

                    secWith.classList.add('hidden');
                    secWithout.classList.remove('hidden');

                    if (inputCap) inputCap.removeAttribute('required');
                    if (inputClient) inputClient.setAttribute('required', 'required');
                } else {
                    btnWith.classList.remove('border-slate-200', 'bg-slate-50');
                    btnWith.classList.add('border-amber-500', 'bg-amber-50/60');
                    btnWithout.classList.remove('border-indigo-600', 'bg-indigo-50/60');
                    btnWithout.classList.add('border-slate-200', 'bg-slate-50');

                    secWith.classList.remove('hidden');
                    secWithout.classList.add('hidden');

                    if (inputCap) inputCap.setAttribute('required', 'required');
                    if (inputClient) inputClient.removeAttribute('required');
                }
            }
        </script>
    </div>
</div>

<!-- 2. QUICK PROJECT EXPENSE / INVOICE MODAL -->
<div id="quick-project-expense-modal" class="modal-container fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/60 backdrop-blur-xs p-4 overflow-y-auto">
    <div class="modal-backdrop fixed inset-0" onclick="closeModal('quick-project-expense-modal')"></div>
    <div class="relative bg-white rounded-3xl shadow-2xl max-w-xl w-full p-6 sm:p-8 modal-animate-enter z-10 border border-slate-100">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-4">
            <div class="flex items-center space-x-2 space-x-reverse">
                <div class="w-10 h-10 rounded-xl bg-rose-100 text-rose-600 flex items-center justify-center font-bold text-lg">
                    <i class="fa-solid fa-receipt"></i>
                </div>
                <div>
                    <h3 class="font-black text-slate-800 text-lg">تسجيل فاتورة شراء / خصم من مشروع</h3>
                    <p class="text-xs text-slate-400">خصم تلقائي من خزنة المشروع مع خيار الضريبة 15% وإرفاق الفاتورة</p>
                </div>
            </div>
            <button onclick="closeModal('quick-project-expense-modal')" class="text-slate-400 hover:text-slate-600 text-lg">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form action="projects.php" method="POST" enctype="multipart/form-data" class="space-y-4">
            <?= getCSRFInput() ?>
            <input type="hidden" name="action" value="expense_project">

            <!-- Project Selection -->
            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">اختر المشروع العقاري <span class="text-red-500">*</span></label>
                <select id="modal_expense_project_id" name="project_id" required class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-sm font-bold text-slate-800 focus:bg-white focus:ring-2 focus:ring-rose-500 focus:outline-none">
                    <option value="">-- حدد المشروع --</option>
                    <?php foreach ($projects as $pr): ?>
                        <option value="<?= $pr['id'] ?>">
                            <?= htmlspecialchars($pr['name']) ?> (الرصيد: <?= formatMoney($pr['safe_balance'], $pr['currency'] ?? $currency) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Title (Reason/Item e.g. حديد) & Category -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">سبب الصرف / البند <span class="text-red-500">*</span></label>
                    <input type="text" name="title" required placeholder="مثال: حديد، أسمنت، خرسانة، عمالة..." class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-sm focus:bg-white focus:ring-2 focus:ring-rose-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">تصنيف المصروف</label>
                    <select name="category_id" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-sm focus:bg-white focus:ring-2 focus:ring-rose-500 focus:outline-none">
                        <option value="">عام / مواد بناء</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Amount & 15% VAT calculation -->
            <div class="p-4 bg-slate-50 rounded-2xl border border-slate-200 space-y-3">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-bold text-slate-700 mb-1">المبلغ الأساسي (قبل الضريبة) <span class="text-red-500">*</span></label>
                        <input type="number" step="0.01" min="0.01" id="exp_base_amount" name="amount" required placeholder="مثلاً: 100" oninput="calcVatTotal()" class="w-full text-xl font-bold bg-white border border-slate-300 rounded-xl px-3 py-2 text-rose-600 focus:ring-2 focus:ring-rose-500 focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">العملة</label>
                        <select name="currency" id="exp_modal_currency" class="w-full h-[42px] bg-white border border-slate-300 rounded-xl px-3 text-xs font-bold focus:ring-2 focus:ring-rose-500 focus:outline-none">
                            <option value="ر.س">ر.س (ريال)</option>
                            <option value="ج.م">ج.م (جنيه)</option>
                            <option value="$">$ (دولار)</option>
                            <option value="د.إ">د.إ (درهم)</option>
                            <option value="د.ك">د.ك (دينار)</option>
                        </select>
                    </div>
                </div>

                <!-- 15% VAT Checkbox -->
                <div class="flex items-center justify-between p-2.5 bg-white rounded-xl border border-slate-200">
                    <label class="flex items-center space-x-2 space-x-reverse cursor-pointer">
                        <input type="checkbox" id="exp_apply_vat" name="apply_vat" value="1" onchange="calcVatTotal()" class="rounded text-rose-600 focus:ring-rose-500 w-4 h-4">
                        <span class="text-xs font-bold text-slate-800">تطبيق ضريبة القيمة المضافة (15% VAT)</span>
                    </label>
                    <span id="exp_vat_preview" class="text-xs font-bold text-slate-400">بدون ضريبة (0.00)</span>
                </div>

                <!-- Final Total Preview -->
                <div class="flex items-center justify-between pt-1 border-t border-slate-200">
                    <span class="text-xs font-bold text-slate-600">الإجمالي النهائي المخصوم من الخزنة:</span>
                    <span id="exp_total_preview" class="text-base font-black text-rose-600">0.00</span>
                </div>
            </div>

            <!-- Beneficiary Name, ID & Phone -->
            <div class="p-4 bg-indigo-50/50 rounded-2xl border border-indigo-100 space-y-3">
                <p class="text-xs font-black text-indigo-900">بيانات المستفيد / المورد (لسند الصرف A4):</p>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                        <label class="block text-[11px] font-bold text-slate-700 mb-1">اسم المستفيد / الشركة</label>
                        <input type="text" name="beneficiary_name" placeholder="مثال: شركة الراجحي للحديد" class="w-full bg-white border border-slate-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-rose-500 focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-slate-700 mb-1">رقم الهوية / السجل / الضريبي</label>
                        <input type="text" name="beneficiary_id_number" placeholder="رقم الهوية أو السجل" class="w-full bg-white border border-slate-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-rose-500 focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-slate-700 mb-1">رقم الهاتف</label>
                        <input type="text" name="beneficiary_phone" placeholder="05xxxxxxxx" class="w-full bg-white border border-slate-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-rose-500 focus:outline-none">
                    </div>
                </div>
            </div>

            <!-- Attachment File -->
            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">
                    <i class="fa-solid fa-paperclip ml-1 text-slate-400"></i>
                    إرفاق فاتورة الشراء أو الإيصال (صورة أو PDF)
                </label>
                <input type="file" name="invoice_file" accept=".jpg,.jpeg,.png,.webp,.pdf" class="w-full text-xs text-slate-600 bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 file:mr-2 file:py-1 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-bold file:bg-rose-50 file:text-rose-700 hover:file:bg-rose-100">
                <span class="text-[10px] text-slate-400">الملف سيتم حفظه تلقائياً وإتاحته للمعاينة مع السند</span>
            </div>

            <!-- SECTION C: LAND & ACQUISITION COSTS (OPTIONAL) -->
            <div class="p-4 rounded-2xl bg-amber-50/60 border border-amber-200/80 space-y-3">
                <div class="flex items-center justify-between">
                    <div class="flex items-center space-x-2 space-x-reverse text-amber-900 font-black text-xs">
                        <i class="fa-solid fa-map-location-dot text-amber-600 text-sm"></i>
                        <span>تكاليف شراء وتوثيق الأرض (اختياري)</span>
                    </div>
                    <span class="text-[10px] font-bold text-amber-700 bg-amber-100 px-2 py-0.5 rounded-full">خاص بالمشاريع المتضمنة شراء أرض</span>
                </div>
                <p class="text-[11px] text-slate-600 leading-tight">
                    إذا كان المشروع يتضمن شراء أرض، يمكنك إدخال قيمتها وتوابعها هنا لتسجيلها تلقائياً كسندات صرف أولية للمشروع، أو اتركها فارغة إذا لم تكن مشتراة.
                </p>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                        <label class="block text-[11px] font-bold text-slate-700 mb-1">قيمة شراء الأرض (ر.س)</label>
                        <input type="number" step="0.01" min="0" name="land_price" placeholder="0.00" class="w-full bg-white border border-slate-300 rounded-xl px-3 py-2 text-xs font-bold text-slate-800 focus:ring-2 focus:ring-amber-500 focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-slate-700 mb-1">قيمة السعي / الدلالة (ر.س)</label>
                        <input type="number" step="0.01" min="0" name="land_brokerage" placeholder="0.00" class="w-full bg-white border border-slate-300 rounded-xl px-3 py-2 text-xs font-bold text-slate-800 focus:ring-2 focus:ring-amber-500 focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-slate-700 mb-1">ضريبة التصرفات / الشراء (ر.س)</label>
                        <input type="number" step="0.01" min="0" name="land_tax" placeholder="0.00" class="w-full bg-white border border-slate-300 rounded-xl px-3 py-2 text-xs font-bold text-slate-800 focus:ring-2 focus:ring-amber-500 focus:outline-none">
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">تاريخ العملية</label>
                    <input type="date" name="expense_date" value="<?= date('Y-m-d') ?>" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-sm focus:bg-white focus:ring-2 focus:ring-rose-500 focus:outline-none">
                </div>
                <div class="flex items-center pt-5">
                    <label class="flex items-center space-x-2 space-x-reverse cursor-pointer">
                        <input type="checkbox" name="print_after" value="1" checked class="rounded text-rose-600 focus:ring-rose-500 w-4 h-4">
                        <span class="text-xs font-bold text-slate-800">فتح وطباعة سند الصرف A4 فور الحفظ</span>
                    </label>
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">ملاحظات إضافية</label>
                <textarea name="description" rows="2" placeholder="أي تفاصيل أو رقم فاتورة المورد..." class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-sm focus:bg-white focus:ring-2 focus:ring-rose-500 focus:outline-none"></textarea>
            </div>

            <div class="flex items-center space-x-2 space-x-reverse pt-2">
                <button type="submit" class="flex-1 bg-rose-600 hover:bg-rose-700 text-white font-bold py-2.5 px-4 rounded-xl shadow-md transition text-sm">
                    تأكيد الخصم وحفظ السند
                </button>
                <button type="button" onclick="closeModal('quick-project-expense-modal')" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold py-2.5 px-4 rounded-xl transition text-sm">
                    إلغاء
                </button>
            </div>
        </form>
    </div>
</div>

<!-- 3. QUICK PROJECT DEPOSIT MODAL -->
<div id="quick-project-deposit-modal" class="modal-container fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/60 backdrop-blur-xs p-4 overflow-y-auto">
    <div class="modal-backdrop fixed inset-0" onclick="closeModal('quick-project-deposit-modal')"></div>
    <div class="relative bg-white rounded-3xl shadow-2xl max-w-md w-full p-6 modal-animate-enter z-10 border border-slate-100">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-4">
            <div class="flex items-center space-x-2 space-x-reverse">
                <div class="w-9 h-9 rounded-xl bg-emerald-100 text-emerald-600 flex items-center justify-center font-bold">
                    <i class="fa-solid fa-circle-plus"></i>
                </div>
                <h3 class="font-bold text-slate-800 text-lg">إيداع سيولة في خزنة مشروع</h3>
            </div>
            <button onclick="closeModal('quick-project-deposit-modal')" class="text-slate-400 hover:text-slate-600 text-lg">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form action="projects.php" method="POST" class="space-y-4">
            <?= getCSRFInput() ?>
            <input type="hidden" name="action" value="deposit_project">

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">المشروع المستفيد <span class="text-red-500">*</span></label>
                <select id="modal_deposit_project_id" name="project_id" required class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-sm font-bold text-slate-800 focus:bg-white focus:ring-2 focus:ring-emerald-500 focus:outline-none">
                    <option value="">-- اختر المشروع --</option>
                    <?php foreach ($projects as $pr): ?>
                        <option value="<?= $pr['id'] ?>"><?= htmlspecialchars($pr['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">المبلغ المودع والعملة <span class="text-red-500">*</span></label>
                <div class="flex gap-2">
                    <div class="relative flex-1">
                        <input type="number" step="0.01" min="0.01" name="amount" required placeholder="0.00" class="w-full text-xl font-bold bg-slate-50 border border-slate-300 rounded-xl px-4 py-2 text-emerald-600 focus:bg-white focus:ring-2 focus:ring-emerald-500 focus:outline-none">
                    </div>
                    <div class="w-32 shrink-0">
                        <select name="currency" class="w-full h-full text-xs font-bold bg-slate-50 border border-slate-300 rounded-xl px-2.5 py-2 text-slate-800 focus:bg-white focus:ring-2 focus:ring-emerald-500 focus:outline-none">
                            <option value="ر.س">ر.س (ريال)</option>
                            <option value="ج.م">ج.م (جنيه)</option>
                            <option value="$">$ (دولار)</option>
                            <option value="د.إ">د.إ (درهم)</option>
                            <option value="د.ك">د.ك (دينار)</option>
                        </select>
                    </div>
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">مصدر التمويل / البيان <span class="text-red-500">*</span></label>
                <input type="text" name="category" required placeholder="مثال: تمويل مستثمر، دفعة عميل، تغذية من الخزنة العامة" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-sm focus:bg-white focus:ring-2 focus:ring-emerald-500 focus:outline-none">
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">تاريخ الإيداع</label>
                <input type="date" name="transaction_date" value="<?= date('Y-m-d') ?>" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-sm focus:bg-white focus:ring-2 focus:ring-emerald-500 focus:outline-none">
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">ملاحظات</label>
                <textarea name="description" rows="2" placeholder="أي تفاصيل أو رقم تحويل بنكي..." class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-sm focus:bg-white focus:ring-2 focus:ring-emerald-500 focus:outline-none"></textarea>
            </div>

            <div class="flex items-center space-x-2 space-x-reverse pt-2">
                <button type="submit" class="flex-1 bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-2.5 px-4 rounded-xl shadow-md transition text-sm">
                    تأكيد الإيداع الآن
                </button>
                <button type="button" onclick="closeModal('quick-project-deposit-modal')" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold py-2.5 px-4 rounded-xl transition text-sm">
                    إلغاء
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openProjectExpenseModal(projectId, projectName, curr) {
    const sel = document.getElementById('modal_expense_project_id');
    if (sel) sel.value = projectId;
    const currSel = document.getElementById('exp_modal_currency');
    if (currSel && curr) currSel.value = curr;
    openModal('quick-project-expense-modal');
}

function openProjectDepositModal(projectId, projectName, curr) {
    const sel = document.getElementById('modal_deposit_project_id');
    if (sel) sel.value = projectId;
    openModal('quick-project-deposit-modal');
}

function calcVatTotal() {
    const baseInput = document.getElementById('exp_base_amount');
    const vatCheck = document.getElementById('exp_apply_vat');
    const vatPreview = document.getElementById('exp_vat_preview');
    const totalPreview = document.getElementById('exp_total_preview');

    const base = parseFloat(baseInput.value) || 0;
    const isVat = vatCheck.checked;

    let vat = 0;
    if (isVat) {
        vat = Math.round(base * 0.15 * 100) / 100;
        vatPreview.innerHTML = `<span class="text-rose-600 font-bold">+15% ضريبة: ${vat.toFixed(2)}</span>`;
    } else {
        vatPreview.innerHTML = `<span class="text-slate-400">بدون ضريبة (0.00)</span>`;
    }

    const total = base + vat;
    totalPreview.innerText = total.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
</script>

<?php include_once __DIR__ . '/includes/footer.php'; ?>
