<?php
require_once __DIR__ . '/config/app.php';
requireLogin();
requireModule('projects');
requirePermission('projects');

$currentUser = getCurrentUser();
$activeCompanyId = (int)$currentUser['active_company_id'];
$currency = $currentUser['currency'] ?? 'ر.س';
$db = getDBConnection();

$projectId = (int)($_GET['id'] ?? 0);
if ($projectId <= 0) {
    setFlash('error', 'معرّف المشروع غير صحيح.');
    header('Location: projects.php');
    exit;
}

// Fetch project
if (isSuperAdmin()) {
    $pStmt = $db->prepare("SELECT * FROM projects WHERE id = :id LIMIT 1");
    $pStmt->execute([':id' => $projectId]);
    $project = $pStmt->fetch();
    if ($project) {
        $activeCompanyId = (int)$project['company_id'];
    }
} else {
    $pStmt = $db->prepare("SELECT * FROM projects WHERE id = :id AND company_id = :cid LIMIT 1");
    $pStmt->execute([':id' => $projectId, ':cid' => $activeCompanyId]);
    $project = $pStmt->fetch();
}

if (!$project) {
    setFlash('error', 'المشروع العقاري غير موجود أو ليس لديك صلاحية للوصول إليه.');
    header('Location: projects.php');
    exit;
}

$projCurrency = !empty($project['currency']) ? $project['currency'] : $currency;
$safe = getProjectSafe($activeCompanyId, $projectId, $project['name']);

// Handle POST: Update Project Details
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireNotViewOnly();
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        die('خطأ في التحقق من صحة الطلب');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'update_project') {
        $name = trim($_POST['name'] ?? '');
        $code = trim($_POST['code'] ?? '');
        $projectType = trim($_POST['project_type'] ?? ($project['project_type'] ?? 'with_capital'));
        if (!in_array($projectType, ['with_capital', 'without_capital'])) {
            $projectType = 'with_capital';
        }
        $clientName = trim($_POST['client_name'] ?? '');
        $clientPhone = trim($_POST['client_phone'] ?? '');
        $clientFeePct = max(0, (float)($_POST['client_fee_percentage'] ?? 0));
        $landPrice = max(0, (float)($_POST['land_price'] ?? 0));
        $landBrokerage = max(0, (float)($_POST['land_brokerage'] ?? 0));
        $landTax = max(0, (float)($_POST['land_tax'] ?? 0));
        $capital = $projectType === 'without_capital' ? 0.00 : max(0, (float)($_POST['capital'] ?? 0));
        $status = trim($_POST['status'] ?? 'active');
        $location = trim($_POST['location'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $startDate = !empty($_POST['start_date']) ? trim($_POST['start_date']) : null;
        $endDate = !empty($_POST['end_date']) ? trim($_POST['end_date']) : null;

        if (empty($name)) {
            setFlash('error', 'اسم المشروع مطلوب.');
        } else {
            $upd = $db->prepare("
                UPDATE projects 
                SET name = :name, code = :code, capital = :cap, status = :status, location = :loc,
                    project_type = :ptype, client_name = :cname, client_phone = :cphone, client_fee_percentage = :cfee,
                    land_price = :lprice, land_brokerage = :lbrok, land_tax = :ltax,
                    description = :desc, start_date = :sdate, end_date = :edate
                WHERE id = :id AND company_id = :cid
            ");
            $upd->execute([
                ':name' => $name,
                ':code' => $code,
                ':cap' => $capital,
                ':status' => $status,
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
                ':id' => $projectId,
                ':cid' => $activeCompanyId
            ]);

            // Update safe name if changed
            $db->prepare("UPDATE safes SET name = :sname WHERE id = :sid")->execute([
                ':sname' => 'خزنة ' . $name,
                ':sid' => $safe['id']
            ]);

            setFlash('success', 'تم تحديث بيانات المشروع بنجاح!');
            header('Location: project_view.php?id=' . $projectId);
            exit;
        }
    }
    elseif ($action === 'record_client_payment') {
        $amount = (float)($_POST['amount'] ?? 0);
        $payCurrency = trim($_POST['currency'] ?? $projCurrency);
        $payMethod = trim($_POST['payment_method'] ?? 'نقداً كاش');
        $payDate = !empty($_POST['payment_date']) ? trim($_POST['payment_date']) : date('Y-m-d');
        $payerName = trim($_POST['payer_name'] ?? ($project['client_name'] ?: 'العميل'));
        $purpose = trim($_POST['purpose'] ?? ("دفعة سداد لحساب مشروع: " . $project['name']));
        $notes = trim($_POST['notes'] ?? '');
        $printReceipt = isset($_POST['print_receipt']) && $_POST['print_receipt'] === '1';

        if ($amount <= 0) {
            setFlash('error', 'يرجى إدخال مبلغ صحيح للدفعة.');
        } else {
            $db->beginTransaction();
            try {
                $recNumber = generateNextReceiptNumber($activeCompanyId);
                $amountWords = tafqeetNumber($amount, $payCurrency);
                $signToken = bin2hex(random_bytes(16));
                $issuerSig = !empty($currentUser['signature']) ? $currentUser['signature'] : ($company['signature'] ?? null);

                // Record safe transaction deposit in project safe
                $txDesc = "إيصال قبض دفعة عميل رقم {$recNumber} لمشروع: {$project['name']} (العميل: {$payerName})";
                $txRes = recordSafeTransaction(
                    $activeCompanyId,
                    $safe['id'],
                    $currentUser['id'],
                    'deposit',
                    'سداد دفعة عميل لمشروع',
                    $amount,
                    $txDesc,
                    $payDate,
                    $payCurrency,
                    $projectId
                );

                // Insert receipt
                $insRec = $db->prepare("
                    INSERT INTO receipts (
                        receipt_number, company_id, user_id, safe_transaction_id, project_id,
                        customer_name, customer_phone, amount, currency, amount_words, payment_method,
                        purpose, receipt_date, notes,
                        sign_token, issuer_signature, signature_status,
                        created_at
                    ) VALUES (
                        :rnum, :cid, :uid, :txid, :pid,
                        :cname, :cphone, :amt, :curr, :awords, :pmethod,
                        :purp, :rdate, :notes,
                        :stok, :isig, 'pending_signature',
                        :now
                    )
                ");
                $insRec->execute([
                    ':rnum' => $recNumber,
                    ':cid' => $activeCompanyId,
                    ':uid' => $currentUser['id'],
                    ':txid' => $txRes['transaction_id'],
                    ':pid' => $projectId,
                    ':cname' => $payerName,
                    ':cphone' => $project['client_phone'] ?: null,
                    ':amt' => $amount,
                    ':curr' => $payCurrency,
                    ':awords' => $amountWords,
                    ':pmethod' => $payMethod,
                    ':purp' => $purpose,
                    ':rdate' => $payDate,
                    ':notes' => $notes,
                    ':stok' => $signToken,
                    ':isig' => $issuerSig,
                    ':now' => date('Y-m-d H:i:s')
                ]);
                $receiptId = (int)$db->lastInsertId();

                $db->commit();
                setFlash('success', "تم تسجيل دفعة العميل بنجاح بمبلغ " . formatMoney($amount, $payCurrency) . "! رقم السند: {$recNumber}");

                if ($printReceipt) {
                    header("Location: receipt_print.php?id={$receiptId}");
                } else {
                    header("Location: receipt_share.php?id={$receiptId}&from_project={$projectId}");
                }
                exit;
            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                setFlash('error', 'حدث خطأ أثناء حفظ الدفعة: ' . $e->getMessage());
            }
        }
    }
    elseif ($action === 'confirm_pending_expense') {
        $expenseId = (int)($_POST['expense_id'] ?? 0);

        $stmt = $db->prepare("SELECT * FROM expenses WHERE id = :id AND company_id = :cid AND project_id = :pid AND status = 'pending_receipt'");
        $stmt->execute([':id' => $expenseId, ':cid' => $activeCompanyId, ':pid' => $projectId]);
        $exp = $stmt->fetch();

        if (!$exp) {
            setFlash('error', 'المصروف غير موجود أو تم تأكيد خصمه مسبقاً.');
            header('Location: project_view.php?id=' . $projectId);
            exit;
        }

        $attachmentPath = $exp['invoice_attachment'];
        if (isset($_FILES['invoice_file']) && $_FILES['invoice_file']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['invoice_file']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'pdf'])) {
                setFlash('error', 'نوع الملف غير مدعوم. الصيغ المدعومة: JPG, PNG, WEBP, PDF');
                header('Location: project_view.php?id=' . $projectId);
                exit;
            }
            $uploadDir = APP_PATH . '/assets/uploads/invoices/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
            $newFileName = 'inv_prj_' . $projectId . '_' . time() . '_' . rand(100, 999) . '.' . $ext;
            if (move_uploaded_file($_FILES['invoice_file']['tmp_name'], $uploadDir . $newFileName)) {
                $attachmentPath = 'assets/uploads/invoices/' . $newFileName;
            }
        }

        if (empty($attachmentPath)) {
            setFlash('error', 'يرجى رفع صورة الإيصال أو الفاتورة لتأكيد الخصم من الخزنة.');
            header('Location: project_view.php?id=' . $projectId);
            exit;
        }

        try {
            $expCurrency = $exp['currency'];
            $totalAmount = (float)$exp['amount'];
            $finalTitle = "صرف مشروع ({$project['name']}) - " . $exp['title'] . ($exp['beneficiary_name'] ? " | المستفيد: " . $exp['beneficiary_name'] : "");

            // Deduct from project safe now
            $txResult = recordSafeTransaction(
                $activeCompanyId,
                $safe['id'],
                $currentUser['id'],
                'expense',
                $exp['title'],
                $totalAmount,
                $finalTitle,
                date('Y-m-d'),
                $expCurrency,
                $projectId
            );

            // Update expense
            $upd = $db->prepare("
                UPDATE expenses
                SET invoice_attachment = :att,
                    safe_transaction_id = :txid,
                    status = 'deducted'
                WHERE id = :id AND company_id = :cid
            ");
            $upd->execute([
                ':att' => $attachmentPath,
                ':txid' => $txResult['transaction_id'],
                ':id' => $expenseId,
                ':cid' => $activeCompanyId
            ]);

            setFlash('success', 'تم إرفاق صورة الإيصال بنجاح وتأكيد خصم مبلغ ' . formatMoney($totalAmount, $expCurrency) . ' من خزنة المشروع!');
            header('Location: project_view.php?id=' . $projectId);
            exit;
        } catch (Exception $e) {
            setFlash('error', $e->getMessage());
            header('Location: project_view.php?id=' . $projectId);
            exit;
        }
    }
    elseif ($action === 'add_daily_report') {
        $title = trim($_POST['title'] ?? '');
        $reportDate = !empty($_POST['report_date']) ? trim($_POST['report_date']) : date('Y-m-d');
        $periodRange = trim($_POST['period_range'] ?? '');
        $stage = trim($_POST['stage'] ?? '');
        $completionPct = max(0, min(100, (int)($_POST['completion_percentage'] ?? 0)));
        $description = trim($_POST['description'] ?? '');
        $videoUrl = trim($_POST['video_url'] ?? '');

        if (empty($title)) {
            setFlash('error', 'عنوان التقرير مطلوب.');
            header('Location: project_view.php?id=' . $projectId . '&tab=tab-reports');
            exit;
        }

        $uploadDir = APP_PATH . '/uploads/projects/reports/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        // Handle Video File Upload if provided
        $savedVideoFile = null;
        if (isset($_FILES['video_file']) && $_FILES['video_file']['error'] === UPLOAD_ERR_OK) {
            $vTmp = $_FILES['video_file']['tmp_name'];
            $vName = $_FILES['video_file']['name'];
            $vExt = strtolower(pathinfo($vName, PATHINFO_EXTENSION));
            $allowedVidExts = ['mp4', 'webm', 'mov', 'm4v', 'mkv', 'avi'];
            if (in_array($vExt, $allowedVidExts)) {
                $newVidName = 'vid_rep_' . $projectId . '_' . time() . '_' . rand(100, 999) . '.' . $vExt;
                if (move_uploaded_file($vTmp, $uploadDir . $newVidName)) {
                    $savedVideoFile = 'uploads/projects/reports/' . $newVidName;
                }
            } else {
                setFlash('error', 'صيغة ملف الفيديو غير مدعومة. الصيغ المسموحة: MP4, WebM, MOV');
                header('Location: project_view.php?id=' . $projectId . '&tab=tab-reports');
                exit;
            }
        }

        try {
            $insRep = $db->prepare("
                INSERT INTO project_daily_reports (
                    company_id, project_id, user_id, report_date, period_range,
                    title, stage, completion_percentage, description, video_url, video_file,
                    created_at
                ) VALUES (
                    :cid, :pid, :uid, :rdate, :prange,
                    :title, :stage, :pct, :desc, :vurl, :vfile,
                    :now
                )
            ");
            $insRep->execute([
                ':cid' => $activeCompanyId,
                ':pid' => $projectId,
                ':uid' => $currentUser['id'],
                ':rdate' => $reportDate,
                ':prange' => $periodRange,
                ':title' => $title,
                ':stage' => $stage,
                ':pct' => $completionPct,
                ':desc' => $description,
                ':vurl' => $videoUrl ?: null,
                ':vfile' => $savedVideoFile,
                ':now' => date('Y-m-d H:i:s')
            ]);
            $reportId = (int)$db->lastInsertId();

            // Insert video into media table if uploaded
            if ($savedVideoFile) {
                $insMedia = $db->prepare("
                    INSERT INTO project_report_media (
                        report_id, project_id, company_id, file_path, file_type, caption, created_at
                    ) VALUES (
                        :rid, :pid, :cid, :fpath, 'video', :cap, :now
                    )
                ");
                $insMedia->execute([
                    ':rid' => $reportId,
                    ':pid' => $projectId,
                    ':cid' => $activeCompanyId,
                    ':fpath' => $savedVideoFile,
                    ':cap' => 'فيديو: ' . $title,
                    ':now' => date('Y-m-d H:i:s')
                ]);
            }

            // Handle Multiple Image Uploads
            $uploadedImgCount = 0;
            if (isset($_FILES['report_images']) && is_array($_FILES['report_images']['name'])) {
                $allowedImgExts = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
                $countImgs = count($_FILES['report_images']['name']);
                for ($i = 0; $i < $countImgs; $i++) {
                    if ($_FILES['report_images']['error'][$i] === UPLOAD_ERR_OK) {
                        $imgTmp = $_FILES['report_images']['tmp_name'][$i];
                        $imgName = $_FILES['report_images']['name'][$i];
                        $imgExt = strtolower(pathinfo($imgName, PATHINFO_EXTENSION));
                        if (in_array($imgExt, $allowedImgExts)) {
                            $newImgName = 'img_rep_' . $reportId . '_' . time() . '_' . rand(1000, 9999) . '.' . $imgExt;
                            if (move_uploaded_file($imgTmp, $uploadDir . $newImgName)) {
                                $relPath = 'uploads/projects/reports/' . $newImgName;
                                $insMedia = $db->prepare("
                                    INSERT INTO project_report_media (
                                        report_id, project_id, company_id, file_path, file_type, caption, created_at
                                    ) VALUES (
                                        :rid, :pid, :cid, :fpath, 'image', :cap, :now
                                    )
                                ");
                                $insMedia->execute([
                                    ':rid' => $reportId,
                                    ':pid' => $projectId,
                                    ':cid' => $activeCompanyId,
                                    ':fpath' => $relPath,
                                    ':cap' => $title,
                                    ':now' => date('Y-m-d H:i:s')
                                ]);
                                $uploadedImgCount++;
                            }
                        }
                    }
                }
            }

            setFlash('success', "تم حفظ التقرير الميداني بنجاح! تم إرفاق ({$uploadedImgCount}) صور " . ($savedVideoFile ? 'وفيديو الموقع.' : '.'));
            header('Location: project_view.php?id=' . $projectId . '&tab=tab-reports');
            exit;
        } catch (Exception $e) {
            setFlash('error', 'حدث خطأ أثناء حفظ التقرير: ' . $e->getMessage());
            header('Location: project_view.php?id=' . $projectId . '&tab=tab-reports');
            exit;
        }
    }
    elseif ($action === 'delete_daily_report') {
        $reportId = (int)($_POST['report_id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM project_daily_reports WHERE id = :id AND project_id = :pid AND company_id = :cid");
        $stmt->execute([':id' => $reportId, ':pid' => $projectId, ':cid' => $activeCompanyId]);
        $report = $stmt->fetch();

        if ($report) {
            // Delete media files from disk
            $mStmt = $db->prepare("SELECT file_path FROM project_report_media WHERE report_id = :rid");
            $mStmt->execute([':rid' => $reportId]);
            $mediaFiles = $mStmt->fetchAll(PDO::FETCH_COLUMN);
            foreach ($mediaFiles as $mf) {
                $fullPath = APP_PATH . '/' . $mf;
                if (file_exists($fullPath)) {
                    @unlink($fullPath);
                }
            }
            if (!empty($report['video_file'])) {
                $vfPath = APP_PATH . '/' . $report['video_file'];
                if (file_exists($vfPath)) {
                    @unlink($vfPath);
                }
            }
            $db->prepare("DELETE FROM project_report_media WHERE report_id = :rid")->execute([':rid' => $reportId]);
            $db->prepare("DELETE FROM project_daily_reports WHERE id = :id")->execute([':id' => $reportId]);
            setFlash('success', 'تم حذف التقرير الميداني بنجاح.');
        } else {
            setFlash('error', 'التقرير غير موجود أو لا تملك صلاحية حذفه.');
        }
        header('Location: project_view.php?id=' . $projectId . '&tab=tab-reports');
        exit;
    }
}

// Filters: Month (e.g. Month 12: 2026-12), Custom Date Range, Category, Search, Exclude Land
$filterMonth = trim($_GET['month'] ?? '');
$filterCat = !empty($_GET['category_id']) ? (int)$_GET['category_id'] : null;
$search = trim($_GET['search'] ?? '');
$excludeLand = isset($_GET['exclude_land']) && $_GET['exclude_land'] == '1';

if (!empty($filterMonth)) {
    $filterFrom = date('Y-m-01', strtotime($filterMonth . '-01'));
    $filterTo = date('Y-m-t', strtotime($filterMonth . '-01'));
} else {
    $filterFrom = trim($_GET['from'] ?? '');
    $filterTo = trim($_GET['to'] ?? '');
}

// Retrieve Project Stats with current filters
$stats = getProjectStats($activeCompanyId, $projectId, $filterMonth ?: null, $filterFrom ?: null, $filterTo ?: null);

// Fetch Expenses with filters
$expQuery = "
    SELECT e.*, 
           ec.name as category_name, 
           u.name as user_name,
           st.balance_before,
           st.balance_after,
           st.created_at as transaction_exact_time
    FROM expenses e
    LEFT JOIN expense_categories ec ON e.category_id = ec.id
    LEFT JOIN users u ON e.user_id = u.id
    LEFT JOIN safe_transactions st ON e.safe_transaction_id = st.id
    WHERE e.company_id = :cid AND e.project_id = :pid
";
$expParams = [':cid' => $activeCompanyId, ':pid' => $projectId];

if (!empty($filterFrom)) {
    $expQuery .= " AND e.expense_date >= :from";
    $expParams[':from'] = $filterFrom;
}
if (!empty($filterTo)) {
    $expQuery .= " AND e.expense_date <= :to";
    $expParams[':to'] = $filterTo;
}
if ($filterCat) {
    $expQuery .= " AND e.category_id = :cat";
    $expParams[':cat'] = $filterCat;
}
if (!empty($search)) {
    $expQuery .= " AND (
        e.title LIKE :s 
        OR e.beneficiary_name LIKE :s 
        OR e.voucher_number LIKE :s 
        OR e.description LIKE :s 
        OR ec.name LIKE :s 
        OR u.name LIKE :s
    )";
    $expParams[':s'] = "%{$search}%";
}
if ($excludeLand) {
    $expQuery .= " AND NOT " . getLandExpensesSqlCondition('e', 'ec');
}
$expQuery .= " ORDER BY e.expense_date DESC, e.id DESC";

$expStmt = $db->prepare($expQuery);
$expStmt->execute($expParams);
$expensesList = $expStmt->fetchAll();

// Distinct categories used in this project for the filter dropdown
$prjCatStmt = $db->prepare("
    SELECT DISTINCT ec.id, ec.name, COUNT(e.id) as exp_cnt
    FROM expenses e
    JOIN expense_categories ec ON e.category_id = ec.id
    WHERE e.company_id = :cid AND e.project_id = :pid
    GROUP BY ec.id, ec.name
    ORDER BY ec.name ASC
");
$prjCatStmt->execute([':cid' => $activeCompanyId, ':pid' => $projectId]);
$projectCategories = $prjCatStmt->fetchAll();

// Period Totals (Exclude pending approval and rejected expenses from project cost)
$periodTotalSpent = 0;
$periodVatTotal = 0;
$periodPendingApprovalTotal = 0;
$periodPendingApprovalCount = 0;
foreach ($expensesList as $ex) {
    $st = $ex['status'] ?? 'deducted';
    if (in_array($st, ['deducted', 'approved']) || empty($st)) {
        $periodTotalSpent += (float)$ex['amount'];
        $periodVatTotal += (float)$ex['tax_amount'];
    } elseif ($st === 'pending_approval') {
        $periodPendingApprovalTotal += (float)$ex['amount'];
        $periodPendingApprovalCount++;
    }
}

// Fetch Deposits into Project Safe
$depStmt = $db->prepare("
    SELECT st.*, u.name as user_name
    FROM safe_transactions st
    LEFT JOIN users u ON st.user_id = u.id
    WHERE st.company_id = :cid AND st.safe_id = :sid AND st.type = 'deposit'
    ORDER BY st.transaction_date DESC, st.id DESC
");
$depStmt->execute([':cid' => $activeCompanyId, ':sid' => $safe['id']]);
$depositsList = $depStmt->fetchAll();

// Fetch Full Safe Ledger (Deposits + Deductions)
$ledgStmt = $db->prepare("
    SELECT st.*, u.name as user_name
    FROM safe_transactions st
    LEFT JOIN users u ON st.user_id = u.id
    WHERE st.company_id = :cid AND st.safe_id = :sid
    ORDER BY st.id DESC
");
$ledgStmt->execute([':cid' => $activeCompanyId, ':sid' => $safe['id']]);
$ledgerList = $ledgStmt->fetchAll();

// Fetch Categories for Filter
$catStmt = $db->prepare("SELECT * FROM expense_categories WHERE company_id = :cid AND is_active = 1 ORDER BY name ASC");
$catStmt->execute([':cid' => $activeCompanyId]);
$categories = $catStmt->fetchAll();

// Fetch Client Payments (Receipts linked to this project)
$recStmt = $db->prepare("
    SELECT r.*, u.name as receiver_name 
    FROM receipts r 
    LEFT JOIN users u ON r.user_id = u.id 
    WHERE r.company_id = :cid AND r.project_id = :pid 
    ORDER BY r.receipt_date DESC, r.id DESC
");
$recStmt->execute([':cid' => $activeCompanyId, ':pid' => $projectId]);
$clientReceipts = $recStmt->fetchAll();

// Fetch Project Daily Reports (تقارير الإنجاز اليومية والميدانية)
$repStmt = $db->prepare("
    SELECT r.*, u.name as engineer_name 
    FROM project_daily_reports r 
    LEFT JOIN users u ON r.user_id = u.id 
    WHERE r.company_id = :cid AND r.project_id = :pid 
    ORDER BY r.report_date DESC, r.id DESC
");
$repStmt->execute([':cid' => $activeCompanyId, ':pid' => $projectId]);
$dailyReports = $repStmt->fetchAll();

$repIds = array_column($dailyReports, 'id');
$reportMediaGrouped = [];
if (!empty($repIds)) {
    $inPlaceholders = implode(',', array_fill(0, count($repIds), '?'));
    $mStmt = $db->prepare("
        SELECT * FROM project_report_media 
        WHERE report_id IN ($inPlaceholders) 
        ORDER BY id ASC
    ");
    $mStmt->execute($repIds);
    $allMedia = $mStmt->fetchAll();
    foreach ($allMedia as $m) {
        $reportMediaGrouped[$m['report_id']][] = $m;
    }
}

$pageTitle = 'مشروع: ' . htmlspecialchars($project['name']);
require_once __DIR__ . '/includes/header.php';
?>

<div class="space-y-6">
    <!-- Breadcrumb & Top Bar -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <div class="flex items-center space-x-2 space-x-reverse text-xs text-slate-500 mb-1">
                <a href="projects.php" class="hover:text-amber-600 transition flex items-center">
                    <i class="fa-solid fa-city ml-1"></i> المشاريع العقارية
                </a>
                <span>/</span>
                <span class="text-slate-800 font-bold"><?= htmlspecialchars($project['name']) ?></span>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="text-2xl font-black text-slate-900"><?= htmlspecialchars($project['name']) ?></h1>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-lg text-xs font-mono font-bold bg-amber-50 text-amber-800 border border-amber-200">
                    <?= htmlspecialchars($project['code'] ?: ('PRJ-' . $project['id'])) ?>
                </span>
                <?php if ($stats['project_type'] === 'without_capital'): ?>
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-black bg-indigo-100 text-indigo-800 border border-indigo-200">
                        <i class="fa-solid fa-file-invoice-dollar ml-1"></i>بدون رأس مال (مصروفات عميل)
                    </span>
                <?php else: ?>
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-black bg-blue-100 text-blue-800 border border-blue-200">
                        <i class="fa-solid fa-vault ml-1"></i>برأس مال محدد
                    </span>
                <?php endif; ?>
                <span class="px-2.5 py-0.5 rounded-full text-xs font-bold <?= $project['status'] === 'active' ? 'bg-emerald-100 text-emerald-800' : ($project['status'] === 'completed' ? 'bg-blue-100 text-blue-800' : 'bg-amber-100 text-amber-800') ?>">
                    <?= $project['status'] === 'active' ? 'قيد التنفيذ' : ($project['status'] === 'completed' ? 'مكتمل' : 'معلق') ?>
                </span>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="flex flex-wrap items-center gap-2">
            <button onclick="openModal('add-daily-report-modal')" class="inline-flex items-center bg-blue-600 hover:bg-blue-700 text-white font-bold px-3.5 py-2.5 rounded-xl shadow-md shadow-blue-600/20 transition text-xs sm:text-sm">
                <i class="fa-solid fa-camera-retro ml-1.5"></i>
                إضافة تقرير موقع / إنجاز
            </button>
            <?php if ($stats['project_type'] === 'without_capital'): ?>
                <button onclick="openModal('record-client-payment-modal')" class="inline-flex items-center bg-emerald-600 hover:bg-emerald-700 text-white font-bold px-4 py-2.5 rounded-xl shadow-md shadow-emerald-600/20 transition text-xs sm:text-sm">
                    <i class="fa-solid fa-hand-holding-dollar ml-1.5"></i>
                    قبض دفعة من العميل (سند قبض)
                </button>
                <a href="project_client_statement.php?id=<?= $projectId ?>" target="_blank" class="inline-flex items-center bg-slate-900 hover:bg-slate-800 text-white font-bold px-4 py-2.5 rounded-xl shadow-sm transition text-xs sm:text-sm">
                    <i class="fa-solid fa-print ml-1.5"></i>
                    كشف حساب ومطالبة العميل A4
                </a>
            <?php endif; ?>
            <a href="expense_create.php?source=project&project_id=<?= $projectId ?>&return_url=<?= urlencode("project_view.php?id={$projectId}") ?>" class="inline-flex items-center bg-rose-600 hover:bg-rose-700 text-white font-bold px-4 py-2.5 rounded-xl shadow-md transition text-xs sm:text-sm">
                <i class="fa-solid fa-receipt ml-1.5"></i>
                تسجيل فاتورة خصم (شراء حديد/مواد)
            </a>
            <button onclick="openModal('quick-project-deposit-modal')" class="inline-flex items-center bg-emerald-50 hover:bg-emerald-100 text-emerald-800 font-bold px-3.5 py-2.5 rounded-xl border border-emerald-200 transition text-xs sm:text-sm">
                <i class="fa-solid fa-circle-plus ml-1.5"></i>
                إيداع سيولة بالخزنة
            </button>
            <button onclick="openModal('edit-project-modal')" class="inline-flex items-center bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold px-3 py-2.5 rounded-xl transition text-xs sm:text-sm" title="تعديل المشروع">
                <i class="fa-solid fa-pen-to-square ml-1"></i>
                تعديل
            </button>
        </div>
    </div>

    <!-- KPI Summary Cards -->
    <?php if ($stats['project_type'] === 'without_capital'): ?>
        <!-- WITHOUT CAPITAL: CLIENT BILLING SUMMARY HERO (Exact user requirement) -->
        <div class="bg-white rounded-3xl p-6 sm:p-7 border border-slate-200/90 shadow-xs space-y-6">
            <!-- Client Info Strip -->
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 pb-4 border-b border-slate-100">
                <div class="flex items-center space-x-3 space-x-reverse">
                    <div class="w-12 h-12 rounded-2xl bg-indigo-100 text-indigo-700 flex items-center justify-center text-xl font-bold">
                        <i class="fa-solid fa-user-tie"></i>
                    </div>
                    <div>
                        <div class="flex items-center space-x-2 space-x-reverse">
                            <span class="text-xs font-bold text-slate-400">العميل صاحب المشروع:</span>
                            <strong class="text-slate-900 text-sm sm:text-base"><?= htmlspecialchars($project['client_name'] ?: 'غير محدد') ?></strong>
                            <?php if (!empty($project['client_phone'])): ?>
                                <span class="text-xs text-indigo-600 font-mono">(<?= htmlspecialchars($project['client_phone']) ?>)</span>
                            <?php endif; ?>
                        </div>
                        <p class="text-xs text-slate-500 mt-0.5">مشروع بدون ميزانية مسبقة - يتم قيد كافة المصروفات لحساب العميل لاحتساب المبلغ المطلوب منه سداده.</p>
                    </div>
                </div>

                <?php if ($stats['client_fee_percentage'] > 0): ?>
                    <span class="inline-flex items-center px-3 py-1.5 rounded-xl text-xs font-bold bg-amber-50 text-amber-800 border border-amber-200">
                        <i class="fa-solid fa-percent ml-1.5 text-amber-600"></i>
                        أتعاب إشراف مضافة: <strong><?= (float)$stats['client_fee_percentage'] ?>%</strong>
                    </span>
                <?php endif; ?>
            </div>

            <!-- Math Breakdown Grid -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <!-- 1: Total Project Expenses Incurred -->
                <div class="bg-slate-50 rounded-2xl p-4 border border-slate-200/80">
                    <div class="flex items-center justify-between mb-1">
                        <span class="text-xs font-bold text-slate-500">1. إجمالي المصروفات المنفذة</span>
                        <div class="w-7 h-7 rounded-lg bg-rose-100 text-rose-600 flex items-center justify-center text-xs">
                            <i class="fa-solid fa-receipt"></i>
                        </div>
                    </div>
                    <h3 class="text-xl font-black text-rose-600"><?= formatMoney($stats['total_expenses'], $projCurrency) ?></h3>
                    <span class="text-[11px] text-slate-400 mt-1 block">كل فواتير الشراء المنفذة</span>
                </div>

                <!-- 2: Fees or Filtered -->
                <div class="bg-slate-50 rounded-2xl p-4 border border-slate-200/80">
                    <div class="flex items-center justify-between mb-1">
                        <span class="text-xs font-bold text-slate-500">
                            <?= $stats['client_fee_percentage'] > 0 ? "2. أتعاب الإشراف ({$stats['client_fee_percentage']}%)" : '2. رصيد خزنة المشروع' ?>
                        </span>
                        <div class="w-7 h-7 rounded-lg bg-amber-100 text-amber-600 flex items-center justify-center text-xs">
                            <i class="fa-solid fa-coins"></i>
                        </div>
                    </div>
                    <h3 class="text-xl font-black text-amber-700">
                        <?= $stats['client_fee_percentage'] > 0 ? formatMoney($stats['client_fee_amount'], $projCurrency) : formatMoney($stats['safe_balance'], $projCurrency) ?>
                    </h3>
                    <span class="text-[11px] text-slate-400 mt-1 block">
                        <?= $stats['client_fee_percentage'] > 0 ? 'تضاف على إجمالي المطالبة' : 'السيولة المتاحة حالياً' ?>
                    </span>
                </div>

                <!-- 3: Total Client Payments -->
                <div class="bg-emerald-50/60 rounded-2xl p-4 border border-emerald-200">
                    <div class="flex items-center justify-between mb-1">
                        <span class="text-xs font-bold text-emerald-800">3. إجمالي ما سدده العميل</span>
                        <div class="w-7 h-7 rounded-lg bg-emerald-100 text-emerald-700 flex items-center justify-center text-xs">
                            <i class="fa-solid fa-hand-holding-dollar"></i>
                        </div>
                    </div>
                    <h3 class="text-xl font-black text-emerald-700"><?= formatMoney($stats['client_payments'], $projCurrency) ?></h3>
                    <span class="text-[11px] text-emerald-600 mt-1 block"><?= count($clientReceipts) ?> دفعات مقبوضة</span>
                </div>

                <!-- 4: WHAT CLIENT OWES (المفروض العميل يدفع كام) -->
                <div class="rounded-2xl p-4 border <?= $stats['client_due'] > 0 ? 'bg-gradient-to-br from-rose-600 to-rose-700 text-white shadow-md shadow-rose-600/20' : ($stats['client_due'] < 0 ? 'bg-gradient-to-br from-blue-600 to-blue-700 text-white shadow-md' : 'bg-gradient-to-br from-emerald-600 to-emerald-700 text-white shadow-md') ?>">
                    <div class="flex items-center justify-between mb-1">
                        <span class="text-xs font-bold <?= $stats['client_due'] > 0 ? 'text-rose-100' : 'text-emerald-100' ?>">
                            <?= $stats['client_due'] > 0 ? '4. المفروض العميل يدفع (المتبقي)' : ($stats['client_due'] < 0 ? '4. رصيد فائض للعميل (دائن)' : '4. حالة الحساب') ?>
                        </span>
                        <div class="w-7 h-7 rounded-lg bg-white/20 text-white flex items-center justify-center text-xs">
                            <i class="fa-solid fa-scale-balanced"></i>
                        </div>
                    </div>
                    <h3 class="text-2xl font-black text-white">
                        <?= $stats['client_due'] == 0 ? 'خالص بالكامل (0.00)' : formatMoney(abs($stats['client_due']), $projCurrency) ?>
                    </h3>
                    <span class="text-[11px] <?= $stats['client_due'] > 0 ? 'text-rose-100' : 'text-emerald-100' ?> mt-1 block font-bold">
                        <?= $stats['client_due'] > 0 ? 'مستحق السداد على العميل الآن' : ($stats['client_due'] < 0 ? 'العميل دفع مبالغ تفيض عن المصروفات' : 'تمت تسوية ومطابقة الحساب') ?>
                    </span>
                </div>
            </div>
        </div>
    <?php else: ?>
        <!-- WITH CAPITAL: BUDGET & SAFE KPI CARDS -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
            <!-- 1: Approved Capital -->
            <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-xs">
                <div class="flex items-center justify-between mb-1">
                    <span class="text-xs font-bold text-slate-400">رأس المال المعتمد</span>
                    <div class="w-8 h-8 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center text-sm">
                        <i class="fa-solid fa-coins"></i>
                    </div>
                </div>
                <h3 class="text-xl font-black text-slate-800"><?= formatMoney($stats['capital'], $projCurrency) ?></h3>
                <span class="text-[11px] text-slate-400 mt-1 block">الميزانية التقديرية</span>
            </div>

            <!-- 2: Current Safe Balance -->
            <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-xs">
                <div class="flex items-center justify-between mb-1">
                    <span class="text-xs font-bold text-slate-400">رصيد خزنة المشروع</span>
                    <div class="w-8 h-8 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center text-sm">
                        <i class="fa-solid fa-vault"></i>
                    </div>
                </div>
                <h3 class="text-xl font-black text-emerald-600"><?= formatMoney($stats['safe_balance'], $projCurrency) ?></h3>
                <span class="text-[11px] text-slate-400 mt-1 block"><?= htmlspecialchars($safe['name']) ?></span>
            </div>

            <!-- 3: Total All-time Expenses -->
            <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-xs">
                <div class="flex items-center justify-between mb-1">
                    <span class="text-xs font-bold text-slate-400">إجمالي المصروفات المنفذة</span>
                    <div class="w-8 h-8 rounded-lg bg-rose-50 text-rose-600 flex items-center justify-center text-sm">
                        <i class="fa-solid fa-hand-holding-dollar"></i>
                    </div>
                </div>
                <h3 class="text-xl font-black text-rose-600"><?= formatMoney($stats['total_expenses'], $projCurrency) ?></h3>
                <span class="text-[11px] text-slate-400 mt-1 block">مجموع الخصومات التراكمي</span>
            </div>

            <!-- 4: Filtered Period Expenses -->
            <div class="bg-gradient-to-br from-amber-500 to-amber-600 text-white rounded-2xl p-4 shadow-md">
                <div class="flex items-center justify-between mb-1">
                    <span class="text-xs font-bold text-amber-100">مصروفات الفترة بالفلتر</span>
                    <div class="w-8 h-8 rounded-lg bg-white/20 text-white flex items-center justify-center text-sm">
                        <i class="fa-solid fa-calendar-check"></i>
                    </div>
                </div>
                <h3 class="text-xl font-black text-white"><?= formatMoney($periodTotalSpent, $projCurrency) ?></h3>
                <span class="text-[11px] text-amber-100 mt-1 block">
                    <?= count($expensesList) ?> فاتورة مسجلة
                </span>
            </div>

            <!-- 5: Progress Bar / % Used -->
            <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-xs flex flex-col justify-between">
                <div>
                    <div class="flex items-center justify-between mb-1">
                        <span class="text-xs font-bold text-slate-400">نسبة الاستهلاك</span>
                        <span class="text-xs font-black <?= $stats['burn_rate'] > 85 ? 'text-rose-600' : 'text-amber-600' ?>"><?= $stats['burn_rate'] ?>%</span>
                    </div>
                    <div class="w-full bg-slate-100 h-2 rounded-full overflow-hidden mt-2">
                        <div class="h-full <?= $stats['burn_rate'] > 85 ? 'bg-rose-500' : 'bg-amber-500' ?> transition-all duration-500" style="width: <?= min(100, $stats['burn_rate']) ?>%"></div>
                    </div>
                </div>
                <span class="text-[10px] text-slate-400 mt-2">
                    المتبقي من رأس المال: <strong class="text-slate-700"><?= formatMoney(max(0, $stats['capital'] - $stats['total_expenses']), $projCurrency) ?></strong>
                </span>
            </div>
        </div>
    <?php endif; ?>

    <?php if (($stats['total_land_cost'] ?? 0) > 0): ?>
        <!-- REAL ESTATE / LAND & CONSTRUCTION COST BREAKDOWN -->
        <div class="bg-gradient-to-r from-slate-900 via-slate-850 to-slate-800 text-white rounded-2xl p-5 shadow-lg border border-slate-700/60 relative overflow-hidden">
            <div class="absolute -left-10 -bottom-10 w-48 h-48 bg-amber-500/10 rounded-full blur-2xl pointer-events-none"></div>
            <div class="relative z-10 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-5">
                <div>
                    <div class="flex items-center space-x-2 space-x-reverse mb-1.5">
                        <span class="w-2.5 h-2.5 rounded-full bg-amber-400 animate-pulse"></span>
                        <span class="text-xs font-bold text-amber-300 uppercase tracking-wider">التحليل العقاري والتكاليف الإنشائية للمشروع</span>
                        <?php if ($excludeLand): ?>
                            <span class="px-2 py-0.5 rounded-md text-[10px] font-black bg-emerald-500/20 text-emerald-300 border border-emerald-500/30">
                                <i class="fa-solid fa-filter"></i> وضع تكلفة البناء فقط نشط
                            </span>
                        <?php else: ?>
                            <span class="px-2 py-0.5 rounded-md text-[10px] font-black bg-blue-500/20 text-blue-300 border border-blue-500/30">
                                <i class="fa-solid fa-layer-group"></i> عرض شامل (مع الأرض)
                            </span>
                        <?php endif; ?>
                    </div>
                    <p class="text-xs text-slate-300 max-w-xl">
                        يمكنك التبديل بنقرة واحدة بين العرض الشامل لكامل تكاليف المشروع وبين عرض تكاليف البناء والتنفيذ الفعلي فقط باستبعاد (شراء الأرض، السعي، والضريبة).
                    </p>
                </div>

                <!-- Quick Toggle Buttons -->
                <div class="flex flex-wrap items-center gap-2">
                    <a href="project_view.php?id=<?= $projectId ?>&exclude_land=0<?= !empty($filterMonth) ? '&month=' . urlencode($filterMonth) : '' ?><?= $filterCat ? '&category_id=' . $filterCat : '' ?><?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
                       class="px-3.5 py-2 rounded-xl text-xs font-bold transition flex items-center <?= !$excludeLand ? 'bg-amber-500 text-slate-950 shadow-md shadow-amber-500/30 font-black' : 'bg-white/10 hover:bg-white/20 text-white border border-white/15' ?>">
                        <i class="fa-solid fa-list-check ml-1.5"></i>
                        <span>شامل تكلفة الأرض (<?= formatMoney($stats['total_expenses'], $projCurrency) ?>)</span>
                    </a>

                    <a href="project_view.php?id=<?= $projectId ?>&exclude_land=1<?= !empty($filterMonth) ? '&month=' . urlencode($filterMonth) : '' ?><?= $filterCat ? '&category_id=' . $filterCat : '' ?><?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
                       class="px-3.5 py-2 rounded-xl text-xs font-bold transition flex items-center <?= $excludeLand ? 'bg-emerald-500 text-slate-950 shadow-md shadow-emerald-500/30 font-black' : 'bg-white/10 hover:bg-white/20 text-white border border-white/15' ?>">
                        <i class="fa-solid fa-trowel-bricks ml-1.5"></i>
                        <span>تكلفة البناء فقط (<?= formatMoney($stats['construction_expenses'], $projCurrency) ?>)</span>
                    </a>
                </div>
            </div>

            <!-- Breakdown Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 mt-4 pt-4 border-t border-white/10 text-xs">
                <div class="bg-white/5 rounded-xl p-3 border border-white/10">
                    <span class="text-slate-400 block text-[11px] mb-1">إجمالي تكلفة المشروع بالكامل:</span>
                    <strong class="text-base font-black text-amber-300 font-mono"><?= formatMoney($stats['total_expenses'], $projCurrency) ?></strong>
                </div>
                <div class="bg-white/5 rounded-xl p-3 border border-white/10">
                    <span class="text-slate-400 block text-[11px] mb-1">تكلفة البناء والتنفيذ الفعلي:</span>
                    <strong class="text-base font-black text-emerald-400 font-mono"><?= formatMoney($stats['construction_expenses'], $projCurrency) ?></strong>
                </div>
                <div class="bg-white/5 rounded-xl p-3 border border-white/10">
                    <span class="text-slate-400 block text-[11px] mb-1">إجمالي تكاليف الأرض وتوابعها:</span>
                    <strong class="text-base font-black text-rose-300 font-mono"><?= formatMoney($stats['total_land_cost'], $projCurrency) ?></strong>
                </div>
                <div class="bg-white/5 rounded-xl p-3 border border-white/10 flex flex-col justify-center text-[10px] text-slate-300 space-y-0.5">
                    <div>شراء الأرض: <span class="font-mono font-bold text-white"><?= formatMoney($stats['land_price'], $projCurrency) ?></span></div>
                    <div>السعي والدلالة: <span class="font-mono font-bold text-white"><?= formatMoney($stats['land_brokerage'], $projCurrency) ?></span></div>
                    <div>ضريبة التصرفات: <span class="font-mono font-bold text-white"><?= formatMoney($stats['land_tax'], $projCurrency) ?></span></div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- MONTHLY FILTER BAR & SEARCH (Requested by user: فلتر بالبند/البراند/اسم المسجل/رقم السند/استبعاد الأرض) -->
    <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs space-y-4">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 pb-3 border-b border-slate-100">
            <div class="flex items-center space-x-2 space-x-reverse">
                <div class="w-7 h-7 rounded-lg bg-amber-100 text-amber-700 flex items-center justify-center text-xs">
                    <i class="fa-solid fa-filter"></i>
                </div>
                <h3 class="font-bold text-slate-800 text-sm">فلتر المصروفات والبحث المتقدم</h3>
                <?php if (!empty($filterMonth)): ?>
                    <span class="px-2.5 py-0.5 rounded-full text-xs font-black bg-amber-100 text-amber-800">
                        شهر: <?= htmlspecialchars($filterMonth) ?>
                    </span>
                <?php endif; ?>
                <?php if ($excludeLand): ?>
                    <span class="px-2.5 py-0.5 rounded-full text-xs font-black bg-emerald-100 text-emerald-800">
                        مستبعد الأرض (تكلفة البناء فقط)
                    </span>
                <?php endif; ?>
            </div>

            <!-- Quick Month Buttons -->
            <div class="flex flex-wrap items-center gap-1.5 text-xs">
                <span class="text-slate-400 ml-1 text-[11px] font-bold">وصول سريع:</span>
                <a href="project_view.php?id=<?= $projectId ?><?= $excludeLand ? '&exclude_land=1' : '' ?>" class="px-2.5 py-1 rounded-lg font-bold <?= empty($filterMonth) && empty($filterFrom) ? 'bg-slate-800 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">
                    التقرير الكامل
                </a>
                <a href="project_view.php?id=<?= $projectId ?>&month=<?= date('Y-04') ?><?= $excludeLand ? '&exclude_land=1' : '' ?>" class="px-2.5 py-1 rounded-lg font-bold <?= $filterMonth === date('Y-04') ? 'bg-amber-600 text-white shadow-xs' : 'bg-amber-50 text-amber-800 hover:bg-amber-100' ?>">
                    شهر 4 (أبريل)
                </a>
                <a href="project_view.php?id=<?= $projectId ?>&month=<?= date('Y-05') ?><?= $excludeLand ? '&exclude_land=1' : '' ?>" class="px-2.5 py-1 rounded-lg font-bold <?= $filterMonth === date('Y-05') ? 'bg-amber-600 text-white shadow-xs' : 'bg-amber-50 text-amber-800 hover:bg-amber-100' ?>">
                    شهر 5 (مايو)
                </a>
                <a href="project_view.php?id=<?= $projectId ?>&month=<?= date('Y-12') ?><?= $excludeLand ? '&exclude_land=1' : '' ?>" class="px-2.5 py-1 rounded-lg font-bold <?= $filterMonth === date('Y-12') ? 'bg-amber-600 text-white shadow-xs' : 'bg-amber-50 text-amber-800 hover:bg-amber-100' ?>">
                    شهر 12 (ديسمبر)
                </a>
                <a href="project_view.php?id=<?= $projectId ?>&month=<?= date('Y-m') ?><?= $excludeLand ? '&exclude_land=1' : '' ?>" class="px-2.5 py-1 rounded-lg font-bold <?= $filterMonth === date('Y-m') ? 'bg-amber-600 text-white shadow-xs' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' ?>">
                    الشهر الحالي
                </a>
            </div>
        </div>

        <!-- Custom Filter Form -->
        <form action="project_view.php" method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3 items-end">
            <input type="hidden" name="id" value="<?= $projectId ?>">

            <div>
                <label class="block text-[11px] font-bold text-slate-600 mb-1">الشهر مباشرة</label>
                <input type="month" name="month" value="<?= htmlspecialchars($filterMonth) ?>" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs font-bold text-slate-800 focus:bg-white focus:ring-2 focus:ring-amber-500 focus:outline-none">
            </div>

            <div>
                <label class="block text-[11px] font-bold text-slate-600 mb-1">البند / التصنيف</label>
                <select name="category_id" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-2.5 py-2 text-xs font-bold text-slate-800 focus:bg-white focus:ring-2 focus:ring-amber-500 focus:outline-none">
                    <option value="">-- كل البنود والتصنيفات --</option>
                    <?php 
                    $catsToShow = !empty($projectCategories) ? $projectCategories : $categories;
                    foreach ($catsToShow as $c): 
                    ?>
                        <option value="<?= $c['id'] ?>" <?= $filterCat === (int)$c['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['name']) ?> <?= isset($c['exp_cnt']) ? "({$c['exp_cnt']})" : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-[11px] font-bold text-slate-600 mb-1">من تاريخ</label>
                <input type="date" name="from" value="<?= htmlspecialchars($filterFrom) ?>" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs focus:bg-white focus:ring-2 focus:ring-amber-500 focus:outline-none">
            </div>

            <div>
                <label class="block text-[11px] font-bold text-slate-600 mb-1">إلى تاريخ</label>
                <input type="date" name="to" value="<?= htmlspecialchars($filterTo) ?>" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs focus:bg-white focus:ring-2 focus:ring-amber-500 focus:outline-none">
            </div>

            <div>
                <label class="block text-[11px] font-bold text-slate-600 mb-1">بحث بالبند، البراند، السند، المسجل</label>
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="مثال: حديد، سابك، مقاول، VCH..." class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs focus:bg-white focus:ring-2 focus:ring-amber-500 focus:outline-none">
            </div>

            <div class="flex gap-2">
                <button type="submit" class="flex-1 bg-amber-600 hover:bg-amber-700 text-white font-bold py-2 px-3 rounded-xl text-xs transition shadow-xs flex items-center justify-center">
                    <i class="fa-solid fa-magnifying-glass ml-1.5"></i> تصفية
                </button>
                <a href="project_view.php?id=<?= $projectId ?>" class="bg-slate-100 hover:bg-slate-200 text-slate-600 font-bold py-2 px-3 rounded-xl text-xs transition flex items-center justify-center" title="إعادة ضبط">
                    <i class="fa-solid fa-rotate-right"></i>
                </a>
            </div>

            <!-- Exclude Land Checkbox Switch -->
            <div class="sm:col-span-2 lg:col-span-6 pt-2 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 border-t border-slate-100 mt-1">
                <label class="inline-flex items-center space-x-2 space-x-reverse cursor-pointer text-xs font-bold text-slate-700 hover:text-amber-800 transition">
                    <input type="checkbox" name="exclude_land" value="1" <?= $excludeLand ? 'checked' : '' ?> onchange="this.form.submit()" class="w-4 h-4 text-amber-600 rounded border-slate-300 focus:ring-amber-500">
                    <span class="text-amber-950 font-bold">
                        <i class="fa-solid fa-filter-circle-xmark ml-1 text-amber-600"></i>
                        استبعاد تكاليف شراء الأرض والسعي والضريبة (عرض تكلفة البناء والتنفيذ الفعلي فقط)
                    </span>
                </label>
                <?php if ($excludeLand): ?>
                    <span class="text-[11px] text-emerald-800 font-bold bg-emerald-50 px-2.5 py-1 rounded-lg border border-emerald-200 self-start sm:self-auto">
                        <i class="fa-solid fa-circle-check ml-1 text-emerald-600"></i> فلتر استبعاد الأرض نشط (يتم عرض المصاريف بدون قيمة الأرض والسعي والضريبة)
                    </span>
                <?php endif; ?>
            </div>
        </form>

        <!-- Filter Result Alert Badge -->
        <?php if (!empty($filterMonth) || !empty($filterFrom) || !empty($search) || !empty($filterCat) || $excludeLand): ?>
            <div class="p-3 bg-amber-50/90 border border-amber-200 rounded-xl flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 text-xs">
                <div class="flex items-center space-x-2 space-x-reverse text-amber-900">
                    <i class="fa-solid fa-circle-info text-amber-600 text-sm"></i>
                    <span>
                        نتيجة التصفية الحالية: 
                        إجمالي المصروفات المنفذة: <strong class="text-rose-600 font-black text-sm"><?= formatMoney($periodTotalSpent, $projCurrency) ?></strong>
                        <?php if ($periodVatTotal > 0): ?>
                            (منها ضريبة: <?= formatMoney($periodVatTotal, $projCurrency) ?>)
                        <?php endif; ?>
                        عبر <strong class="text-slate-800 font-bold"><?= count($expensesList) ?> عملية صرف</strong>.
                        <?php if ($excludeLand): ?>
                            <span class="text-emerald-700 font-bold mr-1">(تم استبعاد تكاليف الأرض: <?= formatMoney($stats['total_land_cost'] ?? 0, $projCurrency) ?>)</span>
                        <?php endif; ?>
                    </span>
                </div>
                <a href="project_view.php?id=<?= $projectId ?>" class="text-[11px] text-amber-700 hover:underline font-bold self-end sm:self-auto">
                    إلغاء جميع الفلاتر
                </a>
            </div>
        <?php endif; ?>
    </div>

    <!-- EXECUTIVE SHARE & PDF REPORT CARD (Option 1: Link to client, Option 2: PDF export) -->
    <div class="bg-gradient-to-r from-slate-900 via-slate-850 to-indigo-950 text-white rounded-3xl p-5 sm:p-6 shadow-xl flex flex-col md:flex-row md:items-center md:justify-between gap-4 border border-slate-800">
        <div class="space-y-1">
            <div class="flex items-center space-x-2 space-x-reverse text-amber-400 text-xs font-black uppercase">
                <i class="fa-solid fa-share-nodes text-sm"></i>
                <span>مشاركة تقارير التدقيق والمصروفات مع العميل</span>
            </div>
            <h3 class="text-lg font-black text-white">تقرير المصروفات والتدقيق المالي (رابط العميل المباشر & ملف PDF)</h3>
            <p class="text-xs text-slate-300">
                <?= !empty($filterMonth) ? 'الفترة المحددة: شهر ' . htmlspecialchars($filterMonth) : 'تقرير المصروفات الكاملة لكافة فترات المشروع' ?> | يحتوي على البند، التاريخ والوقت، المبلغ، حركة الخزنة (قبل وبعد)، وصورة الإيصال.
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2.5">
            <?php 
            $excludeLandParam = $excludeLand ? '&exclude_land=1' : '';
            $shareUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['PHP_SELF']), '/\\') . '/project_report_share.php?token=' . urlencode($project['share_token'] ?? '') . (!empty($filterMonth) ? '&month=' . urlencode($filterMonth) : '') . $excludeLandParam;
            ?>
            <!-- 1: Copy Link for Client -->
            <button type="button" onclick="copyClientReportLink('<?= htmlspecialchars($shareUrl) ?>')" class="inline-flex items-center bg-indigo-600 hover:bg-indigo-500 text-white font-bold px-4 py-2.5 rounded-xl shadow-md transition text-xs">
                <i class="fa-solid fa-link ml-1.5"></i>
                <span id="btn_copy_link_text">نسخ رابط العميل</span>
            </button>

            <!-- Preview Client View -->
            <a href="project_report_share.php?token=<?= htmlspecialchars($project['share_token'] ?? '') ?><?= !empty($filterMonth) ? '&month=' . urlencode($filterMonth) : '' ?><?= $excludeLandParam ?>" target="_blank" class="inline-flex items-center bg-white/10 hover:bg-white/20 text-white font-bold px-3 py-2.5 rounded-xl border border-white/20 transition text-xs" title="فتح شاشة العميل في علامة تبويب جديدة">
                <i class="fa-solid fa-arrow-up-right-from-square ml-1.5"></i>
                معاينة شاشة العميل
            </a>

            <!-- Client Reports & Photos Direct -->
            <a href="project_report_share.php?token=<?= htmlspecialchars($project['share_token'] ?? '') ?>&tab=reports" target="_blank" class="inline-flex items-center bg-blue-600 hover:bg-blue-500 text-white font-bold px-3 py-2.5 rounded-xl shadow-md transition text-xs" title="فتح شاشة تقارير وصور الموقع للعميل">
                <i class="fa-solid fa-camera-retro ml-1.5"></i>
                تقارير وصور الموقع
            </a>

            <!-- 2: Printable PDF -->
            <a href="project_report_pdf.php?id=<?= $projectId ?>&token=<?= urlencode($project['share_token'] ?? '') ?><?= !empty($filterMonth) ? '&month=' . urlencode($filterMonth) : '' ?><?= $excludeLandParam ?>&autoprint=1" target="_blank" class="inline-flex items-center bg-rose-600 hover:bg-rose-500 text-white font-bold px-4 py-2.5 rounded-xl shadow-md transition text-xs">
                <i class="fa-solid fa-file-pdf ml-1.5"></i>
                تقرير التدقيق PDF
            </a>

            <!-- 3: Client Account Statement A4 -->
            <a href="project_client_statement.php?id=<?= $projectId ?><?= $excludeLandParam ?>" target="_blank" class="inline-flex items-center bg-emerald-600 hover:bg-emerald-500 text-white font-bold px-4 py-2.5 rounded-xl shadow-md transition text-xs" title="طباعة كشف حساب ومطالبة العميل المالية A4">
                <i class="fa-solid fa-file-invoice-dollar ml-1.5"></i>
                كشف حساب العميل A4
            </a>
        </div>
    </div>

    <!-- TABS NAVIGATION -->
    <div class="border-b border-slate-200 flex flex-wrap items-center gap-4 text-xs font-bold" id="project-tabs">
        <button onclick="switchTab('tab-expenses')" id="btn-tab-expenses" class="pb-3 border-b-2 border-amber-600 text-amber-600 flex items-center transition">
            <i class="fa-solid fa-receipt ml-1.5"></i>
            فواتير ومصروفات المشروع (<?= count($expensesList) ?>)
        </button>
        <button onclick="switchTab('tab-reports')" id="btn-tab-reports" class="pb-3 border-b-2 border-transparent text-slate-500 hover:text-slate-800 flex items-center transition">
            <i class="fa-solid fa-camera-retro ml-1.5 text-blue-600"></i>
            تقارير الموقع والإنجاز (<?= count($dailyReports) ?>)
        </button>
        <button onclick="switchTab('tab-client-payments')" id="btn-tab-client-payments" class="pb-3 border-b-2 border-transparent text-slate-500 hover:text-slate-800 flex items-center transition">
            <i class="fa-solid fa-hand-holding-dollar ml-1.5 text-emerald-600"></i>
            دفعات وسدادات العميل (<?= count($clientReceipts) ?>)
        </button>
        <button onclick="switchTab('tab-deposits')" id="btn-tab-deposits" class="pb-3 border-b-2 border-transparent text-slate-500 hover:text-slate-800 flex items-center transition">
            <i class="fa-solid fa-circle-arrow-down ml-1.5 text-emerald-500"></i>
            إيداعات وتمويل الخزنة (<?= count($depositsList) ?>)
        </button>
        <button onclick="switchTab('tab-ledger')" id="btn-tab-ledger" class="pb-3 border-b-2 border-transparent text-slate-500 hover:text-slate-800 flex items-center transition">
            <i class="fa-solid fa-vault ml-1.5 text-indigo-500"></i>
            كشف حساب حركة الخزنة
        </button>
        <button onclick="switchTab('tab-analytics')" id="btn-tab-analytics" class="pb-3 border-b-2 border-transparent text-slate-500 hover:text-slate-800 flex items-center transition">
            <i class="fa-solid fa-chart-pie ml-1.5 text-blue-500"></i>
            تحليل بنود الصرف (حديد، أسمنت...)
        </button>
    </div>

    <!-- TAB 1: EXPENSES & PURCHASE BILLS TABLE -->
    <div id="tab-expenses" class="tab-pane space-y-4">

        <?php 
        $canApprove = canApproveExpenses();
        $pendingApprovalProjList = array_filter($expensesList, fn($x) => ($x['status'] ?? '') === 'pending_approval');
        $pendingApprovalProjCount = count($pendingApprovalProjList);
        $pendingApprovalProjTotal = array_sum(array_column($pendingApprovalProjList, 'amount'));

        $pendingProjList = array_filter($expensesList, fn($x) => ($x['status'] ?? '') === 'pending_receipt');
        $pendingProjCount = count($pendingProjList);
        $pendingProjTotal = array_sum(array_column($pendingProjList, 'amount'));
        ?>

        <?php if ($pendingApprovalProjCount > 0): ?>
            <div class="bg-amber-50 border-2 border-amber-400 rounded-2xl p-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 shadow-xs">
                <div class="flex items-center space-x-3 space-x-reverse">
                    <div class="w-10 h-10 rounded-xl bg-amber-500 text-white flex items-center justify-center text-lg font-bold">
                        <i class="fa-solid fa-shield-halved"></i>
                    </div>
                    <div>
                        <strong class="text-sm font-black text-amber-950 block">يوجد عدد (<?= $pendingApprovalProjCount ?>) مصروفات بانتظار موافقة واعتماد الإدارة لهذا المشروع</strong>
                        <p class="text-xs text-amber-800">إجمالي المبالغ المعلقة: <strong class="font-bold font-mono text-amber-950"><?= formatMoney($pendingApprovalProjTotal, $projCurrency) ?></strong> — لم تُخصم من خزنة المشروع ولم تُحتسب على حساب وتكاليف العميل حتى يتم اعتمادها.</p>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($pendingProjCount > 0): ?>
            <div class="bg-amber-50/70 border border-amber-300 rounded-2xl p-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 shadow-xs">
                <div class="flex items-center space-x-3 space-x-reverse">
                    <div class="w-10 h-10 rounded-xl bg-amber-500 text-white flex items-center justify-center text-lg font-bold">
                        <i class="fa-solid fa-clock"></i>
                    </div>
                    <div>
                        <strong class="text-sm font-black text-amber-950 block">يوجد عدد (<?= $pendingProjCount ?>) مصروفات معلقة بانتظار إرفاق صورة الإيصال</strong>
                        <p class="text-xs text-amber-800">إجمالي المبالغ المعلقة: <span class="font-bold font-mono"><?= formatMoney($pendingProjTotal, $projCurrency) ?></span> (لم تُخصم من خزنة المشروع بعد حتى يتم رفع الفاتورة لتأكيد الخصم).</p>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-right text-xs">
                    <thead class="bg-slate-50 text-slate-500 font-bold border-b border-slate-200">
                        <tr>
                            <th class="p-3.5">رقم السند</th>
                            <th class="p-3.5">التاريخ والوقت</th>
                            <th class="p-3.5">بند / سبب الصرف</th>
                            <th class="p-3.5">المستفيد / المورد</th>
                            <th class="p-3.5">المبلغ والضريبة</th>
                            <th class="p-3.5 bg-slate-100 text-center">حركة الخزنة (قبل / بعد)</th>
                            <th class="p-3.5 text-center">مرفق الإيصال</th>
                            <th class="p-3.5 text-center">حالة الاعتماد</th>
                            <th class="p-3.5 text-center">إجراءات</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 text-slate-700">
                        <?php if (empty($expensesList)): ?>
                            <tr>
                                <td colspan="9" class="p-8 text-center text-slate-400">
                                    <i class="fa-solid fa-receipt text-3xl mb-2 text-slate-300 block"></i>
                                    لا توجد فواتير أو مصروفات مسجلة لهذا المشروع في الفترة المحددة.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($expensesList as $row): ?>
                                <?php
                                $baseAmt = (float)$row['amount'] - (float)$row['tax_amount'];
                                $hasVat = (float)$row['tax_rate'] > 0;
                                $isPending = ($row['status'] ?? '') === 'pending_receipt';
                                $exactTime = !empty($row['transaction_exact_time']) ? date('H:i', strtotime($row['transaction_exact_time'])) : date('H:i', strtotime($row['created_at']));
                                ?>
                                <tr class="hover:bg-slate-50/70 transition <?= $isPending ? 'bg-amber-50/30' : '' ?>">
                                    <td class="p-3.5 whitespace-nowrap font-mono font-bold text-slate-800">
                                        <?= htmlspecialchars($row['voucher_number'] ?: ('VCH-' . $row['id'])) ?>
                                        <?php if (!empty($row['user_name'])): ?>
                                            <span class="text-[10px] text-slate-400 font-sans font-normal block mt-0.5" title="المستخدم الذي سجّل المصروف">
                                                <i class="fa-solid fa-user-pen ml-1 text-slate-400"></i><?= htmlspecialchars($row['user_name']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="p-3.5 whitespace-nowrap">
                                        <span class="font-bold text-slate-800 block"><?= date('Y-m-d', strtotime($row['expense_date'])) ?></span>
                                        <span class="text-[10px] text-slate-400 font-mono"><?= $exactTime ?></span>
                                    </td>
                                    <td class="p-3.5 font-bold text-slate-900">
                                        <div class="flex items-center space-x-1.5 space-x-reverse">
                                            <span class="w-2 h-2 rounded-full <?= $isPending ? 'bg-amber-500' : 'bg-rose-500' ?>"></span>
                                            <span><?= htmlspecialchars($row['title']) ?></span>
                                        </div>
                                        <?php if (!empty($row['description'])): ?>
                                            <span class="text-[11px] text-slate-400 font-normal block mt-0.5"><?= htmlspecialchars($row['description']) ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($row['category_name'])): ?>
                                            <span class="inline-block mt-1 px-2 py-0.5 rounded-md text-[10px] font-bold bg-slate-100 text-slate-600">
                                                <i class="fa-solid fa-tag ml-1 text-amber-500"></i><?= htmlspecialchars($row['category_name']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="p-3.5 text-slate-700">
                                        <?php if (!empty($row['beneficiary_name'])): ?>
                                            <span class="font-bold block"><?= htmlspecialchars($row['beneficiary_name']) ?></span>
                                            <?php if (!empty($row['beneficiary_id_number'])): ?>
                                                <span class="text-[10px] text-slate-400 font-mono block">هوية: <?= htmlspecialchars($row['beneficiary_id_number']) ?></span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-slate-400">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="p-3.5 whitespace-nowrap font-bold text-slate-700">
                                        <strong class="font-black text-rose-600 block text-sm font-mono">
                                            <?= formatMoney($row['amount'], $row['currency']) ?>
                                        </strong>
                                        <div class="text-[10px] text-slate-400 font-normal">
                                            <span>صافي: <?= formatMoney($baseAmt, $row['currency']) ?></span>
                                            <?php if ($hasVat): ?>
                                                <span>| ضريبة 15%: <?= formatMoney($row['tax_amount'], $row['currency']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <!-- Safe Before/After Audit (كانت إيه وبقت إيه) -->
                                    <td class="p-3.5 whitespace-nowrap text-center bg-slate-50/60 font-mono text-xs border-x border-slate-100">
                                        <?php 
                                        $rowSt = $row['status'] ?? 'deducted';
                                        ?>
                                        <?php if ($rowSt === 'pending_approval'): ?>
                                            <span class="text-[10px] font-bold text-amber-800 bg-amber-100 px-2 py-0.5 rounded">
                                                معلق (بانتظار الاعتماد)
                                            </span>
                                        <?php elseif ($rowSt === 'rejected'): ?>
                                            <span class="text-[10px] font-bold text-rose-700 bg-rose-50 px-2 py-0.5 rounded border border-rose-200">
                                                لم يُخصم (مرفوض)
                                            </span>
                                        <?php elseif ($rowSt === 'pending_receipt'): ?>
                                            <span class="text-[10px] font-bold text-amber-700 bg-amber-100 px-2 py-0.5 rounded">
                                                معلق (لم يُخصم)
                                            </span>
                                        <?php elseif (isset($row['balance_before']) && $row['balance_before'] !== null): ?>
                                            <div class="text-right inline-block text-[10px]">
                                                <span class="text-slate-500 block">قبل: <?= formatMoney($row['balance_before'], $row['currency']) ?></span>
                                                <span class="text-emerald-700 font-bold block">بعد: <?= formatMoney($row['balance_after'], $row['currency']) ?></span>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-slate-400 text-[11px]">مخصوم</span>
                                        <?php endif; ?>
                                    </td>
                                    <!-- Attachment -->
                                    <td class="p-3.5 whitespace-nowrap text-center">
                                        <?php if (!empty($row['invoice_attachment']) && file_exists(APP_PATH . '/' . $row['invoice_attachment'])): ?>
                                            <?php $ext = strtolower(pathinfo($row['invoice_attachment'], PATHINFO_EXTENSION)); ?>
                                            <?php if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])): ?>
                                                <a href="<?= htmlspecialchars($row['invoice_attachment']) ?>" target="_blank" class="inline-block group" title="معاينة صورة الفاتورة">
                                                    <img src="<?= htmlspecialchars($row['invoice_attachment']) ?>" alt="فاتورة" class="w-10 h-10 object-cover rounded-lg border border-slate-300 group-hover:scale-105 transition shadow-xs mx-auto">
                                                </a>
                                            <?php else: ?>
                                                <a href="<?= htmlspecialchars($row['invoice_attachment']) ?>" target="_blank" class="inline-flex items-center px-2 py-1 rounded-lg text-xs font-bold bg-indigo-50 text-indigo-700 hover:bg-indigo-100 transition shadow-2xs" title="عرض فاتورة الشراء المرفقة">
                                                    <i class="fa-solid fa-file-pdf text-red-600 ml-1"></i>
                                                    PDF
                                                </a>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-slate-300 text-[11px]">بدون مرفق</span>
                                        <?php endif; ?>
                                    </td>
                                    <!-- Status Badge & Action -->
                                    <td class="p-3.5 whitespace-nowrap text-center">
                                        <?php if ($rowSt === 'pending_approval'): ?>
                                            <span class="px-2 py-0.5 rounded-full text-[10px] font-black bg-amber-100 text-amber-900 border border-amber-300 block mb-1">
                                                <i class="fa-solid fa-hourglass-half ml-1 text-amber-600"></i> بانتظار الاعتماد
                                            </span>
                                            <?php if ($canApprove): ?>
                                                <div class="flex items-center justify-center gap-1">
                                                    <button type="button" onclick="openApproveExpenseModal(<?= $row['id'] ?>, '<?= htmlspecialchars(addslashes($row['title'])) ?>', '<?= formatMoney($row['amount'], $row['currency']) ?>', '<?= htmlspecialchars(addslashes($row['beneficiary_name'] ?? '')) ?>', '<?= htmlspecialchars(addslashes($project['name'])) ?>')" class="bg-emerald-600 hover:bg-emerald-700 text-white px-2 py-0.5 rounded text-[10px] font-bold inline-flex items-center shadow-xs">
                                                        <i class="fa-solid fa-check ml-1 text-[9px]"></i> اعتماد
                                                    </button>
                                                    <button type="button" onclick="openRejectExpenseModal(<?= $row['id'] ?>, '<?= htmlspecialchars(addslashes($row['title'])) ?>', '<?= formatMoney($row['amount'], $row['currency']) ?>')" class="bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-300 px-2 py-0.5 rounded text-[10px] font-bold inline-flex items-center">
                                                        <i class="fa-solid fa-ban ml-1 text-[9px]"></i> رفض
                                                    </button>
                                                </div>
                                            <?php endif; ?>
                                        <?php elseif ($rowSt === 'rejected'): ?>
                                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-100 text-rose-800 border border-rose-300 block">
                                                <i class="fa-solid fa-ban ml-0.5 text-rose-600"></i> مرفوض
                                            </span>
                                            <?php if (!empty($row['rejection_reason'])): ?>
                                                <span class="text-[9px] text-rose-600 font-bold block mt-0.5" title="<?= htmlspecialchars($row['rejection_reason']) ?>">
                                                    <?= htmlspecialchars(mb_strimwidth($row['rejection_reason'], 0, 20, '...')) ?>
                                                </span>
                                            <?php endif; ?>
                                        <?php elseif ($rowSt === 'pending_receipt'): ?>
                                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 text-amber-900 border border-amber-300 block mb-1">
                                                معلق بانتظار الإيصال
                                            </span>
                                            <button type="button" onclick="openConfirmReceiptModal(<?= $row['id'] ?>, '<?= htmlspecialchars(addslashes($row['title'])) ?>', '<?= formatMoney($row['amount'], $row['currency']) ?>', '<?= htmlspecialchars(addslashes($row['beneficiary_name'] ?? '')) ?>')" class="bg-amber-600 hover:bg-amber-700 text-white px-2 py-1 rounded text-[10px] font-bold inline-flex items-center shadow-xs">
                                                <i class="fa-solid fa-cloud-arrow-up ml-1 text-[9px]"></i> إرفاق الإيصال وخصم
                                            </button>
                                        <?php else: ?>
                                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800 border border-emerald-200">
                                                مؤكد ومخصوم بالخزنة
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="p-3.5 whitespace-nowrap text-center">
                                        <a href="project_voucher_print.php?id=<?= $row['id'] ?>" target="_blank" class="inline-flex items-center bg-slate-100 hover:bg-slate-200 text-slate-800 font-bold px-2.5 py-1.5 rounded-lg text-xs transition" title="طباعة سند الصرف A4">
                                            <i class="fa-solid fa-print ml-1 text-slate-600"></i>
                                            سند A4
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- TAB: CLIENT PAYMENTS & SETTLEMENTS TABLE -->
    <div id="tab-client-payments" class="tab-pane hidden space-y-4">
        <!-- Client Balance Mini Strip -->
        <div class="bg-gradient-to-l from-slate-900 to-indigo-950 text-white rounded-2xl p-4 sm:p-5 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
                <span class="text-[11px] font-bold text-indigo-300 block mb-0.5">سجل مدفوعات وسدادات العميل</span>
                <h4 class="text-base sm:text-lg font-black flex items-center">
                    <i class="fa-solid fa-hand-holding-dollar ml-2 text-emerald-400"></i>
                    <?= htmlspecialchars($project['client_name'] ?: 'العميل') ?>
                    <?php if (!empty($project['client_phone'])): ?>
                        <span class="text-xs font-mono font-normal text-slate-300 mr-2">(<?= htmlspecialchars($project['client_phone']) ?>)</span>
                    <?php endif; ?>
                </h4>
            </div>

            <div class="flex flex-wrap items-center gap-3 text-xs">
                <div class="bg-white/10 rounded-xl px-3 py-2 border border-white/10">
                    <span class="text-[10px] text-slate-300 block">إجمالي المصروفات والمطالبة</span>
                    <strong class="font-mono font-black text-white text-sm"><?= formatMoney($stats['total_billable'] ?? $stats['total_expenses'], $projCurrency) ?></strong>
                </div>
                <div class="bg-emerald-500/20 rounded-xl px-3 py-2 border border-emerald-400/30">
                    <span class="text-[10px] text-emerald-200 block">إجمالي المدفوع</span>
                    <strong class="font-mono font-black text-emerald-400 text-sm"><?= formatMoney($stats['client_payments'] ?? 0, $projCurrency) ?></strong>
                </div>
                <div class="bg-white/10 rounded-xl px-3 py-2 border border-white/10">
                    <span class="text-[10px] text-slate-300 block">المتبقي المطلوب</span>
                    <strong class="font-mono font-black <?= ($stats['client_due'] ?? 0) > 0 ? 'text-amber-400' : 'text-emerald-400' ?> text-sm">
                        <?= formatMoney($stats['client_due'] ?? 0, $projCurrency) ?>
                    </strong>
                </div>
                <button onclick="openModal('record-client-payment-modal')" class="bg-emerald-600 hover:bg-emerald-500 text-white font-bold px-3.5 py-2 rounded-xl transition shadow-md flex items-center">
                    <i class="fa-solid fa-plus-circle ml-1.5"></i>
                    قبض دفعة جديدة
                </button>
            </div>
        </div>

        <div class="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-right text-xs">
                    <thead class="bg-slate-50 text-slate-500 font-bold border-b border-slate-200">
                        <tr>
                            <th class="p-3.5">رقم السند</th>
                            <th class="p-3.5">التاريخ</th>
                            <th class="p-3.5">المستلم منه (العميل)</th>
                            <th class="p-3.5">طريقة الدفع</th>
                            <th class="p-3.5">المبلغ المقبوض</th>
                            <th class="p-3.5">البيان والغرض</th>
                            <th class="p-3.5">الموظف المستلم</th>
                            <th class="p-3.5 text-center">التوقيع الإلكتروني</th>
                            <th class="p-3.5 text-center">إجراءات السند</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 text-slate-700">
                        <?php if (empty($clientReceipts)): ?>
                            <tr>
                                <td colspan="9" class="p-10 text-center text-slate-400">
                                    <i class="fa-solid fa-hand-holding-dollar text-4xl mb-3 text-slate-300 block"></i>
                                    <p class="font-bold text-slate-600 mb-1">لا توجد سندات قبض مسجلة لهذا المشروع بعد</p>
                                    <p class="text-xs text-slate-400 mb-4">عند استلام أي مبالغ أو سدادات من العميل، قم بتسجيل سند قبض لتسوية المصروفات المطلوبة منه.</p>
                                    <button onclick="openModal('record-client-payment-modal')" class="inline-flex items-center bg-emerald-600 hover:bg-emerald-700 text-white font-bold px-4 py-2 rounded-xl text-xs transition shadow-sm">
                                        <i class="fa-solid fa-plus-circle ml-1.5"></i>
                                        تسجيل أول دفعة من العميل الآن
                                    </button>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($clientReceipts as $rec): ?>
                                <tr class="hover:bg-slate-50/70 transition">
                                    <td class="p-3.5 whitespace-nowrap font-mono font-bold text-slate-900">
                                        <div class="flex items-center space-x-1.5 space-x-reverse">
                                            <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                                            <span><?= htmlspecialchars($rec['receipt_number']) ?></span>
                                        </div>
                                    </td>
                                    <td class="p-3.5 whitespace-nowrap text-slate-500">
                                        <?= date('Y-m-d', strtotime($rec['receipt_date'])) ?>
                                    </td>
                                    <td class="p-3.5">
                                        <span class="font-bold text-slate-800 block"><?= htmlspecialchars($rec['customer_name']) ?></span>
                                        <?php if (!empty($rec['customer_phone'])): ?>
                                            <span class="text-[10px] text-slate-400 font-mono block"><?= htmlspecialchars($rec['customer_phone']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="p-3.5 whitespace-nowrap">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-bold bg-slate-100 text-slate-700">
                                            <?= htmlspecialchars($rec['payment_method'] ?: 'نقداً') ?>
                                        </span>
                                    </td>
                                    <td class="p-3.5 whitespace-nowrap">
                                        <span class="font-black text-emerald-600 font-mono text-sm">
                                            +<?= formatMoney($rec['amount'], $rec['currency'] ?? $projCurrency) ?>
                                        </span>
                                    </td>
                                    <td class="p-3.5 text-slate-600 max-w-xs truncate">
                                        <span class="font-bold block text-slate-800"><?= htmlspecialchars($rec['purpose'] ?: '-') ?></span>
                                        <?php if (!empty($rec['notes'])): ?>
                                            <span class="text-[10px] text-slate-400 block"><?= htmlspecialchars($rec['notes']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="p-3.5 whitespace-nowrap text-slate-500 text-[11px]">
                                        <?= htmlspecialchars($rec['receiver_name'] ?? '-') ?>
                                    </td>
                                    <td class="p-3.5 whitespace-nowrap text-center">
                                        <?php if (($rec['signature_status'] ?? '') === 'signed'): ?>
                                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[10px] font-black bg-emerald-100 text-emerald-800 border border-emerald-200">
                                                <i class="fa-solid fa-signature ml-1 text-emerald-600"></i> موقّع ومستلم ✓
                                            </span>
                                        <?php else: ?>
                                            <a href="receipt_share.php?id=<?= $rec['id'] ?>&from_project=<?= $projectId ?>" class="inline-flex items-center px-2.5 py-1 rounded-full text-[10px] font-bold bg-amber-50 text-amber-800 border border-amber-200 hover:bg-amber-100 transition">
                                                <span class="w-1.5 h-1.5 rounded-full bg-amber-500 animate-pulse ml-1.5"></span>
                                                بانتظار التوقيع
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                    <td class="p-3.5 whitespace-nowrap text-center">
                                        <div class="flex items-center justify-center space-x-1.5 space-x-reverse">
                                            <a href="receipt_share.php?id=<?= $rec['id'] ?>&from_project=<?= $projectId ?>" class="inline-flex items-center bg-indigo-50 hover:bg-indigo-100 text-indigo-700 font-bold px-2 py-1.5 rounded-lg text-xs transition border border-indigo-200" title="رابط التوقيع والواتساب">
                                                <i class="fa-solid fa-share-nodes ml-1"></i> توقيع
                                            </a>
                                            <a href="receipt_print.php?id=<?= $rec['id'] ?>" target="_blank" class="inline-flex items-center bg-slate-100 hover:bg-emerald-50 text-slate-700 hover:text-emerald-700 font-bold px-2.5 py-1.5 rounded-lg text-xs transition border border-slate-200" title="طباعة سند القبض A4">
                                                <i class="fa-solid fa-print ml-1 text-emerald-600"></i> سند A4
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- TAB 2: DEPOSITS & FUNDING TABLE -->
    <div id="tab-deposits" class="tab-pane hidden space-y-4">
        <div class="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-right text-xs">
                    <thead class="bg-slate-50 text-slate-500 font-bold border-b border-slate-200">
                        <tr>
                            <th class="p-3.5">رقم الحركة</th>
                            <th class="p-3.5">التاريخ</th>
                            <th class="p-3.5">مصدر التمويل / البند</th>
                            <th class="p-3.5">البيان والتفاصيل</th>
                            <th class="p-3.5">المبلغ المودع</th>
                            <th class="p-3.5">رصيد الخزنة بعد العملية</th>
                            <th class="p-3.5">المستخدم</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 text-slate-700">
                        <?php if (empty($depositsList)): ?>
                            <tr>
                                <td colspan="7" class="p-8 text-center text-slate-400">
                                    <i class="fa-solid fa-circle-arrow-down text-3xl mb-2 text-slate-300 block"></i>
                                    لا توجد إيداعات مسجلة في خزنة هذا المشروع حتى الآن.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($depositsList as $dep): ?>
                                <tr class="hover:bg-slate-50/70 transition">
                                    <td class="p-3.5 whitespace-nowrap font-mono text-slate-500">#<?= $dep['id'] ?></td>
                                    <td class="p-3.5 whitespace-nowrap text-slate-500"><?= date('Y-m-d', strtotime($dep['transaction_date'])) ?></td>
                                    <td class="p-3.5 font-bold text-emerald-800">
                                        <div class="flex items-center space-x-1.5 space-x-reverse">
                                            <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                                            <span><?= htmlspecialchars($dep['category']) ?></span>
                                        </div>
                                    </td>
                                    <td class="p-3.5 text-slate-600"><?= htmlspecialchars($dep['description'] ?: '-') ?></td>
                                    <td class="p-3.5 whitespace-nowrap font-black text-emerald-600 text-sm">
                                        +<?= formatMoney($dep['amount'], $dep['currency']) ?>
                                    </td>
                                    <td class="p-3.5 whitespace-nowrap font-bold text-slate-700">
                                        <?= formatMoney($dep['balance_after'], $dep['currency']) ?>
                                    </td>
                                    <td class="p-3.5 whitespace-nowrap text-slate-500 text-[11px]"><?= htmlspecialchars($dep['user_name'] ?? '-') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- TAB 3: PROJECT SAFE LEDGER -->
    <div id="tab-ledger" class="tab-pane hidden space-y-4">
        <div class="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-right text-xs">
                    <thead class="bg-slate-50 text-slate-500 font-bold border-b border-slate-200">
                        <tr>
                            <th class="p-3.5">الرقم</th>
                            <th class="p-3.5">التاريخ</th>
                            <th class="p-3.5">نوع الحركة</th>
                            <th class="p-3.5">البند والبيان</th>
                            <th class="p-3.5">المبلغ</th>
                            <th class="p-3.5">الرصيد قبل</th>
                            <th class="p-3.5">الرصيد بعد</th>
                            <th class="p-3.5">المسؤول</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 text-slate-700">
                        <?php if (empty($ledgerList)): ?>
                            <tr>
                                <td colspan="8" class="p-8 text-center text-slate-400">لا توجد حركات نقدية مسجلة في خزنة المشروع.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($ledgerList as $tx): ?>
                                <tr class="hover:bg-slate-50/70 transition">
                                    <td class="p-3.5 font-mono text-slate-400">#<?= $tx['id'] ?></td>
                                    <td class="p-3.5 whitespace-nowrap text-slate-500"><?= date('Y-m-d', strtotime($tx['transaction_date'])) ?></td>
                                    <td class="p-3.5 whitespace-nowrap">
                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold <?= $tx['type'] === 'deposit' ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800' ?>">
                                            <?= $tx['type'] === 'deposit' ? 'إيداع وارد' : 'صرف وخصم' ?>
                                        </span>
                                    </td>
                                    <td class="p-3.5">
                                        <span class="font-bold text-slate-800 block"><?= htmlspecialchars($tx['category']) ?></span>
                                        <span class="text-[11px] text-slate-400 block"><?= htmlspecialchars($tx['description'] ?: '-') ?></span>
                                    </td>
                                    <td class="p-3.5 whitespace-nowrap font-black <?= $tx['type'] === 'deposit' ? 'text-emerald-600' : 'text-rose-600' ?>">
                                        <?= ($tx['type'] === 'deposit' ? '+' : '-') . formatMoney($tx['amount'], $tx['currency']) ?>
                                    </td>
                                    <td class="p-3.5 whitespace-nowrap text-slate-500"><?= formatMoney($tx['balance_before'], $tx['currency']) ?></td>
                                    <td class="p-3.5 whitespace-nowrap font-bold text-slate-800"><?= formatMoney($tx['balance_after'], $tx['currency']) ?></td>
                                    <td class="p-3.5 whitespace-nowrap text-slate-500 text-[11px]"><?= htmlspecialchars($tx['user_name'] ?? '-') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- TAB 4: CATEGORY BREAKDOWN -->
    <div id="tab-analytics" class="tab-pane hidden space-y-4">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs">
                <h3 class="font-black text-slate-800 text-sm mb-4 flex items-center">
                    <i class="fa-solid fa-chart-pie ml-2 text-amber-500"></i>
                    توزيع بنود الصرف لمشروع (<?= htmlspecialchars($project['name']) ?>)
                </h3>
                <div class="space-y-3">
                    <?php if (empty($stats['category_breakdown'])): ?>
                        <p class="text-xs text-slate-400 text-center py-6">لا توجد مصروفات لتحليلها في الفترة المحددة.</p>
                    <?php else: ?>
                        <?php foreach ($stats['category_breakdown'] as $cb): ?>
                            <?php
                            $cbAmt = (float)$cb['cat_total'];
                            $cbPct = $periodTotalSpent > 0 ? round(($cbAmt / $periodTotalSpent) * 100, 1) : 0;
                            ?>
                            <div class="space-y-1">
                                <div class="flex items-center justify-between text-xs">
                                    <span class="font-bold text-slate-800"><?= htmlspecialchars($cb['cat_name']) ?> (<?= $cb['cat_count'] ?> عملية)</span>
                                    <span class="font-black text-rose-600"><?= formatMoney($cbAmt, $projCurrency) ?> (<?= $cbPct ?>%)</span>
                                </div>
                                <div class="w-full bg-slate-100 h-2 rounded-full overflow-hidden">
                                    <div class="bg-amber-500 h-full rounded-full" style="width: <?= $cbPct ?>%"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Project Meta & Notes -->
            <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs space-y-3">
                <h3 class="font-black text-slate-800 text-sm flex items-center">
                    <i class="fa-solid fa-circle-info ml-2 text-indigo-500"></i>
                    بيانات ومعلومات المشروع
                </h3>
                <div class="divide-y divide-slate-100 text-xs">
                    <div class="py-2 flex items-center justify-between">
                        <span class="text-slate-500">الموقع الجغرافي:</span>
                        <span class="font-bold text-slate-800"><?= htmlspecialchars($project['location'] ?: 'غير محدد') ?></span>
                    </div>
                    <div class="py-2 flex items-center justify-between">
                        <span class="text-slate-500">تاريخ البدء:</span>
                        <span class="font-bold text-slate-800"><?= htmlspecialchars($project['start_date'] ?: 'غير محدد') ?></span>
                    </div>
                    <div class="py-2 flex items-center justify-between">
                        <span class="text-slate-500">تاريخ الانتهاء المتوقع:</span>
                        <span class="font-bold text-slate-800"><?= htmlspecialchars($project['end_date'] ?: 'مفتوح') ?></span>
                    </div>
                    <div class="py-2 flex items-center justify-between">
                        <span class="text-slate-500">خزنة المشروع المرتبطة:</span>
                        <span class="font-bold text-emerald-600"><?= htmlspecialchars($safe['name']) ?> (ID: <?= $safe['id'] ?>)</span>
                    </div>
                </div>
                <?php if (!empty($project['description'])): ?>
                    <div class="pt-2">
                        <span class="text-xs font-bold text-slate-500 block mb-1">وصف ومواصفات المشروع:</span>
                        <p class="text-xs text-slate-700 bg-slate-50 p-3 rounded-xl leading-relaxed"><?= nl2br(htmlspecialchars($project['description'])) ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- TAB: DAILY SITE REPORTS & PROGRESS -->
    <div id="tab-reports" class="tab-pane hidden space-y-6">
        <!-- Top bar inside tab -->
        <div class="bg-white rounded-3xl p-5 sm:p-6 border border-slate-200/90 shadow-xs flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
                <div class="flex items-center space-x-2 space-x-reverse text-blue-600 font-bold text-xs uppercase mb-1">
                    <i class="fa-solid fa-tower-observation"></i>
                    <span>التوثيق الميداني وسجل مراحل التنفيذ</span>
                </div>
                <h3 class="text-lg font-black text-slate-900">سجل التقارير اليومية وإنجاز المشروع الميداني</h3>
                <p class="text-xs text-slate-500 mt-0.5">
                    توثيق أعمال الموقع بالفترات الزمنية (من السبت للسبت...)، الصور الحية، الفيديوهات ونسب الإنجاز لمشاركتها مع العميل.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <button onclick="openModal('add-daily-report-modal')" class="inline-flex items-center bg-blue-600 hover:bg-blue-700 text-white font-bold px-4 py-2.5 rounded-xl shadow-md shadow-blue-600/20 transition text-xs sm:text-sm">
                    <i class="fa-solid fa-plus ml-1.5"></i>
                    إضافة تقرير موقع جديد
                </button>
                <a href="project_report_share.php?token=<?= htmlspecialchars($project['share_token'] ?? '') ?>&tab=reports" target="_blank" class="inline-flex items-center bg-indigo-50 hover:bg-indigo-100 text-indigo-800 font-bold px-3.5 py-2.5 rounded-xl border border-indigo-200 transition text-xs" title="معاينة شاشة تقارير العميل">
                    <i class="fa-solid fa-share-nodes ml-1.5"></i>
                    شاشة العميل للتقارير
                </a>
            </div>
        </div>

        <?php if (empty($dailyReports)): ?>
            <!-- Empty State -->
            <div class="bg-white rounded-3xl p-12 text-center border border-slate-200/80 shadow-xs space-y-4">
                <div class="w-20 h-20 rounded-full bg-blue-50 text-blue-600 mx-auto flex items-center justify-center text-3xl">
                    <i class="fa-solid fa-camera-retro"></i>
                </div>
                <div class="space-y-1">
                    <h4 class="text-base font-black text-slate-900">لا توجد تقارير موقع مسجلة بعد</h4>
                    <p class="text-xs text-slate-500 max-w-md mx-auto">
                        ابدأ بتوثيق أول تقرير إنجاز للموقع (مثال: من السبت للسبت تم إنجاز أعمال السباكة) مع صور وفيديوهات الأعمال ليراها العميل فوراً.
                    </p>
                </div>
                <button onclick="openModal('add-daily-report-modal')" class="inline-flex items-center bg-blue-600 hover:bg-blue-700 text-white font-bold px-5 py-2.5 rounded-xl shadow-md text-xs transition">
                    <i class="fa-solid fa-plus ml-1.5"></i>
                    إضافة أول تقرير للمشروع
                </button>
            </div>
        <?php else: ?>
            <!-- Timeline Feed -->
            <div class="space-y-6">
                <?php foreach ($dailyReports as $rep): ?>
                    <?php 
                    $repMedia = $reportMediaGrouped[$rep['id']] ?? [];
                    $repPhotos = array_filter($repMedia, fn($m) => $m['file_type'] === 'image');
                    $repVideos = array_filter($repMedia, fn($m) => $m['file_type'] === 'video');
                    $pct = max(0, min(100, (int)$rep['completion_percentage']));
                    ?>
                    <div class="bg-white rounded-3xl border border-slate-200/90 shadow-sm overflow-hidden hover:border-slate-300 transition">
                        <!-- Card Header -->
                        <div class="p-5 sm:p-6 pb-4 border-b border-slate-100 flex flex-col md:flex-row md:items-center md:justify-between gap-4 bg-slate-50/50">
                            <div class="space-y-1.5">
                                <div class="flex flex-wrap items-center gap-2">
                                    <!-- Period Range Badge (Requested specifically) -->
                                    <?php if (!empty($rep['period_range'])): ?>
                                        <span class="inline-flex items-center px-3 py-1 rounded-xl text-xs font-black bg-indigo-100 text-indigo-900 border border-indigo-200 shadow-2xs">
                                            <i class="fa-solid fa-calendar-days ml-1.5 text-indigo-600"></i>
                                            <?= htmlspecialchars($rep['period_range']) ?>
                                        </span>
                                    <?php endif; ?>

                                    <!-- Date Badge -->
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-xl text-xs font-bold bg-slate-100 text-slate-700 border border-slate-200">
                                        <i class="fa-regular fa-clock ml-1 text-slate-400"></i>
                                        <?= date('Y-m-d', strtotime($rep['report_date'])) ?>
                                    </span>

                                    <!-- Stage Badge -->
                                    <?php if (!empty($rep['stage'])): ?>
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-xl text-xs font-black bg-amber-100 text-amber-900 border border-amber-200">
                                            <i class="fa-solid fa-trowel-bricks ml-1 text-amber-600"></i>
                                            <?= htmlspecialchars($rep['stage']) ?>
                                        </span>
                                    <?php endif; ?>

                                    <!-- Engineer Badge -->
                                    <?php if (!empty($rep['engineer_name'])): ?>
                                        <span class="text-xs text-slate-400">
                                            بواسطة: <strong class="text-slate-600"><?= htmlspecialchars($rep['engineer_name']) ?></strong>
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <h4 class="text-lg font-black text-slate-900"><?= htmlspecialchars($rep['title']) ?></h4>
                            </div>

                            <!-- Right: Completion Progress & Delete Action -->
                            <div class="flex items-center space-x-4 space-x-reverse self-start md:self-center">
                                <!-- Progress Bar Mini -->
                                <div class="w-36 sm:w-44 space-y-1 text-right">
                                    <div class="flex items-center justify-between text-[11px] font-bold">
                                        <span class="text-slate-400">نسبة الإنجاز:</span>
                                        <span class="font-mono <?= $pct >= 100 ? 'text-emerald-600' : ($pct >= 50 ? 'text-blue-600' : 'text-amber-600') ?>"><?= $pct ?>%</span>
                                    </div>
                                    <div class="w-full bg-slate-200 h-2 rounded-full overflow-hidden">
                                        <div class="h-full rounded-full transition-all duration-500 <?= $pct >= 100 ? 'bg-emerald-500' : ($pct >= 50 ? 'bg-blue-600' : 'bg-amber-500') ?>" style="width: <?= $pct ?>%"></div>
                                    </div>
                                </div>

                                <!-- Delete Action -->
                                <form action="project_view.php?id=<?= $projectId ?>" method="POST" onsubmit="return confirm('هل أنت متأكد من حذف هذا التقرير وكافة الصور والفيديوهات المرتبطة به نهائياً؟');">
                                    <?= getCSRFInput() ?>
                                    <input type="hidden" name="action" value="delete_daily_report">
                                    <input type="hidden" name="report_id" value="<?= $rep['id'] ?>">
                                    <button type="submit" class="w-8 h-8 rounded-xl bg-slate-100 text-slate-400 hover:bg-rose-50 hover:text-rose-600 flex items-center justify-center transition text-xs" title="حذف التقرير">
                                        <i class="fa-solid fa-trash-can"></i>
                                    </button>
                                </form>
                            </div>
                        </div>

                        <!-- Card Body -->
                        <div class="p-5 sm:p-6 space-y-4">
                            <!-- Description / Notes -->
                            <?php if (!empty($rep['description'])): ?>
                                <p class="text-slate-700 text-sm leading-relaxed whitespace-pre-line bg-slate-50/80 p-4 rounded-2xl border border-slate-100">
                                    <?= nl2br(htmlspecialchars($rep['description'])) ?>
                                </p>
                            <?php endif; ?>

                            <!-- Photos Grid -->
                            <?php if (!empty($repPhotos)): ?>
                                <div>
                                    <div class="flex items-center space-x-2 space-x-reverse text-xs font-bold text-slate-700 mb-2">
                                        <i class="fa-solid fa-images text-blue-600"></i>
                                        <span>صور الموقع الميدانية (<?= count($repPhotos) ?> صورة)</span>
                                    </div>
                                    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3">
                                        <?php foreach ($repPhotos as $img): ?>
                                            <div class="group relative aspect-4/3 rounded-2xl overflow-hidden bg-slate-100 border border-slate-200 cursor-pointer shadow-2xs hover:shadow-md transition" onclick="openReportLightbox('<?= htmlspecialchars($img['file_path']) ?>', '<?= htmlspecialchars(addslashes($rep['title'])) ?> (<?= htmlspecialchars($rep['period_range']) ?>)')">
                                                <img src="<?= htmlspecialchars($img['file_path']) ?>" alt="صورة إنجاز" class="w-full h-full object-cover group-hover:scale-105 transition duration-300">
                                                <div class="absolute inset-0 bg-slate-900/30 opacity-0 group-hover:opacity-100 transition flex items-center justify-center text-white text-base">
                                                    <i class="fa-solid fa-magnifying-glass-plus"></i>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Video Section -->
                            <?php 
                            $videoHtml = renderProjectVideoPlayer($rep['video_file'], $rep['video_url']);
                            if (!empty($videoHtml)): 
                            ?>
                                <div>
                                    <div class="flex items-center space-x-2 space-x-reverse text-xs font-bold text-slate-700 mb-1">
                                        <i class="fa-solid fa-video text-rose-600"></i>
                                        <span>فيديو توثيق إنجاز المرحلة</span>
                                    </div>
                                    <?= $videoHtml ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- EDIT PROJECT MODAL -->
<div id="edit-project-modal" class="modal-container fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/60 backdrop-blur-xs p-4 overflow-y-auto">
    <div class="modal-backdrop fixed inset-0" onclick="closeModal('edit-project-modal')"></div>
    <div class="relative bg-white rounded-3xl shadow-2xl max-w-lg w-full p-6 sm:p-8 modal-animate-enter z-10 border border-slate-100 max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-5">
            <h3 class="font-black text-slate-800 text-lg">تعديل بيانات المشروع العقاري</h3>
            <button onclick="closeModal('edit-project-modal')" class="text-slate-400 hover:text-slate-600 text-lg">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form action="project_view.php?id=<?= $projectId ?>" method="POST" class="space-y-4">
            <?= getCSRFInput() ?>
            <input type="hidden" name="action" value="update_project">

            <!-- Type Switcher -->
            <div>
                <label class="block text-xs font-bold text-slate-700 mb-2">نوع المشروع المالي</label>
                <div class="grid grid-cols-2 gap-2 p-1.5 bg-slate-100 rounded-2xl">
                    <label class="flex items-center justify-center p-2.5 rounded-xl text-xs font-bold cursor-pointer transition border <?= ($project['project_type'] ?? 'with_capital') === 'without_capital' ? 'bg-white shadow-xs text-indigo-700 border-indigo-200' : 'text-slate-600 border-transparent hover:bg-white/60' ?>">
                        <input type="radio" name="project_type" value="without_capital" <?= ($project['project_type'] ?? '') === 'without_capital' ? 'checked' : '' ?> onchange="toggleEditProjectType('without_capital')" class="sr-only">
                        <i class="fa-solid fa-file-invoice-dollar ml-1.5 text-indigo-500"></i>
                        <span>بدون رأس مال (مصروفات عميل)</span>
                    </label>
                    <label class="flex items-center justify-center p-2.5 rounded-xl text-xs font-bold cursor-pointer transition border <?= ($project['project_type'] ?? 'with_capital') === 'with_capital' ? 'bg-white shadow-xs text-blue-700 border-blue-200' : 'text-slate-600 border-transparent hover:bg-white/60' ?>">
                        <input type="radio" name="project_type" value="with_capital" <?= ($project['project_type'] ?? 'with_capital') === 'with_capital' ? 'checked' : '' ?> onchange="toggleEditProjectType('with_capital')" class="sr-only">
                        <i class="fa-solid fa-vault ml-1.5 text-blue-500"></i>
                        <span>برأس مال مخصص</span>
                    </label>
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">اسم المشروع</label>
                <input type="text" name="name" value="<?= htmlspecialchars($project['name']) ?>" required class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-sm focus:bg-white focus:ring-2 focus:ring-amber-500 focus:outline-none">
            </div>

            <!-- Client Details Section (Shown when without_capital) -->
            <div id="edit-client-fields" class="p-3.5 bg-indigo-50/70 border border-indigo-100 rounded-2xl space-y-3 <?= ($project['project_type'] ?? '') === 'without_capital' ? '' : 'hidden' ?>">
                <span class="text-xs font-black text-indigo-900 block">بيانات العميل / المالك:</span>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[11px] font-bold text-slate-700 mb-1">اسم العميل</label>
                        <input type="text" name="client_name" value="<?= htmlspecialchars($project['client_name'] ?? '') ?>" placeholder="مثال: فهد عبد الله" class="w-full bg-white border border-slate-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-slate-700 mb-1">هاتف العميل</label>
                        <input type="text" name="client_phone" value="<?= htmlspecialchars($project['client_phone'] ?? '') ?>" placeholder="05xxxxxxxx" class="w-full bg-white border border-slate-300 rounded-lg px-2.5 py-1.5 text-xs font-mono focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                    </div>
                </div>
                <div>
                    <label class="block text-[11px] font-bold text-slate-700 mb-1">نسبة أتعاب الإشراف والإدارة المضافة على المصروفات (%)</label>
                    <div class="relative">
                        <input type="number" step="0.01" min="0" max="100" name="client_fee_percentage" value="<?= (float)($project['client_fee_percentage'] ?? 0) ?>" placeholder="0" class="w-full bg-white border border-slate-300 rounded-lg px-2.5 py-1.5 text-xs font-bold text-indigo-700 focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                        <span class="absolute left-3 top-1.5 text-xs font-bold text-slate-400">%</span>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">كود المشروع</label>
                    <input type="text" name="code" value="<?= htmlspecialchars($project['code']) ?>" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-sm font-mono focus:bg-white focus:ring-2 focus:ring-amber-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">الحالة</label>
                    <select name="status" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-sm focus:bg-white focus:ring-2 focus:ring-amber-500 focus:outline-none">
                        <option value="active" <?= $project['status'] === 'active' ? 'selected' : '' ?>>قيد التنفيذ / نشط</option>
                        <option value="completed" <?= $project['status'] === 'completed' ? 'selected' : '' ?>>مكتمل ومنتهي</option>
                        <option value="paused" <?= $project['status'] === 'paused' ? 'selected' : '' ?>>معلق ومؤقت</option>
                    </select>
                </div>
            </div>

            <div id="edit-capital-field" class="<?= ($project['project_type'] ?? '') === 'without_capital' ? 'hidden' : '' ?>">
                <label class="block text-xs font-bold text-slate-700 mb-1">رأس المال المعتمد (<?= $projCurrency ?>)</label>
                <input type="number" step="0.01" min="0" name="capital" id="edit-capital-input" value="<?= (float)$project['capital'] ?>" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-sm font-bold text-blue-600 focus:bg-white focus:ring-2 focus:ring-amber-500 focus:outline-none">
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">تاريخ البدء</label>
                    <input type="date" name="start_date" value="<?= htmlspecialchars($project['start_date'] ?? '') ?>" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-sm focus:bg-white focus:ring-2 focus:ring-amber-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">تاريخ الانتهاء</label>
                    <input type="date" name="end_date" value="<?= htmlspecialchars($project['end_date'] ?? '') ?>" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-sm focus:bg-white focus:ring-2 focus:ring-amber-500 focus:outline-none">
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">الموقع</label>
                <input type="text" name="location" value="<?= htmlspecialchars($project['location'] ?? '') ?>" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-sm focus:bg-white focus:ring-2 focus:ring-amber-500 focus:outline-none">
            </div>

            <!-- Land Costs Section (Optional) -->
            <div class="rounded-2xl border border-amber-200/90 bg-gradient-to-br from-amber-50/40 to-slate-50/60 p-4 space-y-3">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 pb-2.5 border-b border-amber-200/60">
                    <div class="flex items-center space-x-2.5 space-x-reverse">
                        <div class="w-8 h-8 rounded-xl bg-amber-500 text-white flex items-center justify-center text-sm font-bold shadow-xs">
                            <i class="fa-solid fa-map-location-dot"></i>
                        </div>
                        <div>
                            <div class="flex items-center space-x-2 space-x-reverse">
                                <span class="text-xs font-black text-slate-900">تكاليف شراء وتوثيق الأرض</span>
                                <span class="text-[10px] font-bold text-amber-800 bg-amber-100/80 px-2 py-0.5 rounded-md">اختياري</span>
                            </div>
                            <span class="text-[11px] text-slate-500 block">خاص بالمشاريع المتضمنة شراء أرض — اتركها 0 إذا كان المشروع إشراف وبناء فقط</span>
                        </div>
                    </div>
                    <?php 
                    $editLandTotal = (float)($project['land_price'] ?? 0) + (float)($project['land_brokerage'] ?? 0) + (float)($project['land_tax'] ?? 0);
                    ?>
                    <span id="edit_land_live_total" class="text-xs font-black font-mono text-amber-700 bg-white px-3 py-1 rounded-xl border border-amber-200 shadow-2xs self-start sm:self-auto">
                        الإجمالي: <?= formatMoney($editLandTotal, $projCurrency) ?>
                    </span>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 pt-1">
                    <div class="bg-white p-3 rounded-xl border border-slate-200/90 focus-within:border-amber-500 focus-within:ring-2 focus-within:ring-amber-500/20 transition">
                        <label class="block text-[11px] font-bold text-slate-700 mb-1">قيمة شراء الأرض (ر.س)</label>
                        <input type="number" step="0.01" min="0" id="edit_input_land_price" name="land_price" value="<?= (float)($project['land_price'] ?? 0) ?>" placeholder="0.00" oninput="updateEditLandTotalPreview()" class="w-full text-xs font-bold font-mono text-slate-900 bg-transparent focus:outline-none">
                    </div>
                    <div class="bg-white p-3 rounded-xl border border-slate-200/90 focus-within:border-amber-500 focus-within:ring-2 focus-within:ring-amber-500/20 transition">
                        <label class="block text-[11px] font-bold text-slate-700 mb-1">قيمة السعي / الدلالة (ر.س)</label>
                        <input type="number" step="0.01" min="0" id="edit_input_land_brokerage" name="land_brokerage" value="<?= (float)($project['land_brokerage'] ?? 0) ?>" placeholder="0.00" oninput="updateEditLandTotalPreview()" class="w-full text-xs font-bold font-mono text-slate-900 bg-transparent focus:outline-none">
                    </div>
                    <div class="bg-white p-3 rounded-xl border border-slate-200/90 focus-within:border-amber-500 focus-within:ring-2 focus-within:ring-amber-500/20 transition">
                        <label class="block text-[11px] font-bold text-slate-700 mb-1">ضريبة التصرفات العقارية (ر.س)</label>
                        <input type="number" step="0.01" min="0" id="edit_input_land_tax" name="land_tax" value="<?= (float)($project['land_tax'] ?? 0) ?>" placeholder="0.00" oninput="updateEditLandTotalPreview()" class="w-full text-xs font-bold font-mono text-slate-900 bg-transparent focus:outline-none">
                    </div>
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">الوصف والتفاصيل</label>
                <textarea name="description" rows="2" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-sm focus:bg-white focus:ring-2 focus:ring-amber-500 focus:outline-none"><?= htmlspecialchars($project['description'] ?? '') ?></textarea>
            </div>

            <div class="flex items-center space-x-2 space-x-reverse pt-2">
                <button type="submit" class="flex-1 bg-amber-600 hover:bg-amber-700 text-white font-bold py-2.5 px-4 rounded-xl shadow-md transition text-sm">
                    حفظ التعديلات
                </button>
                <button type="button" onclick="closeModal('edit-project-modal')" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold py-2.5 px-4 rounded-xl transition text-sm">
                    إلغاء
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Record Client Payment (سند قبض دفعة عميل) -->
<div id="record-client-payment-modal" class="modal-container fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/60 backdrop-blur-xs p-4 overflow-y-auto">
    <div class="modal-backdrop fixed inset-0" onclick="closeModal('record-client-payment-modal')"></div>
    <div class="relative bg-white rounded-3xl shadow-2xl max-w-lg w-full p-6 sm:p-8 modal-animate-enter z-10 border border-slate-100">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-5">
            <div class="flex items-center space-x-2.5 space-x-reverse">
                <div class="w-10 h-10 rounded-xl bg-emerald-100 text-emerald-600 flex items-center justify-center font-bold text-lg">
                    <i class="fa-solid fa-hand-holding-dollar"></i>
                </div>
                <div>
                    <h3 class="font-black text-slate-800 text-lg">قبض دفعة نقدية من العميل</h3>
                    <p class="text-xs text-slate-400">سند قبض وتوريد سيولة لخزنة المشروع وتخفيض المطلوب من العميل</p>
                </div>
            </div>
            <button onclick="closeModal('record-client-payment-modal')" class="text-slate-400 hover:text-slate-600 text-lg">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <!-- Remaining Due Info Box -->
        <?php if ($stats['project_type'] === 'without_capital'): ?>
            <div class="p-3.5 bg-indigo-50/70 border border-indigo-100 rounded-2xl flex items-center justify-between mb-4 text-xs">
                <div>
                    <span class="text-indigo-600 font-bold block">رصيد العميل المطلوب سداده حالياً:</span>
                    <span class="text-[11px] text-slate-500">حسب إجمالي المصروفات المسجلة حتى الآن</span>
                </div>
                <div class="text-left font-mono">
                    <span class="text-base font-black <?= ($stats['client_due'] ?? 0) > 0 ? 'text-amber-600' : 'text-emerald-600' ?>">
                        <?= formatMoney($stats['client_due'] ?? 0, $projCurrency) ?>
                    </span>
                </div>
            </div>
        <?php endif; ?>

        <form action="project_view.php?id=<?= $projectId ?>" method="POST" class="space-y-4">
            <?= getCSRFInput() ?>
            <input type="hidden" name="action" value="record_client_payment">
            <input type="hidden" name="currency" value="<?= htmlspecialchars($projCurrency) ?>">

            <!-- Amount Input -->
            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">المبلغ المقبوض <span class="text-red-500">*</span></label>
                <div class="relative">
                    <input type="number" step="0.01" min="0.01" name="amount" required placeholder="0.00" value="<?= ($stats['client_due'] ?? 0) > 0 ? (float)$stats['client_due'] : '' ?>" class="w-full text-xl font-bold bg-white border border-slate-300 rounded-xl px-4 py-2.5 text-emerald-600 focus:ring-2 focus:ring-emerald-500 focus:outline-none">
                    <span class="absolute left-4 top-3 font-bold text-slate-400 text-xs"><?= $projCurrency ?></span>
                </div>
            </div>

            <!-- Customer Name & Phone -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">اسم العميل / المستلم منه <span class="text-red-500">*</span></label>
                    <input type="text" name="payer_name" required value="<?= htmlspecialchars($project['client_name'] ?: 'العميل') ?>" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs focus:bg-white focus:ring-2 focus:ring-emerald-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">طريقة الدفع</label>
                    <select name="payment_method" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs focus:bg-white focus:ring-2 focus:ring-emerald-500 focus:outline-none">
                        <option value="نقداً كاش">نقداً كاش</option>
                        <option value="تحويل بنكي">تحويل بنكي</option>
                        <option value="شيك بنكي">شيك بنكي</option>
                        <option value="شبكة / مدى">شبكة / مدى</option>
                    </select>
                </div>
            </div>

            <!-- Date & Purpose -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">تاريخ القبض</label>
                    <input type="date" name="payment_date" value="<?= date('Y-m-d') ?>" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs focus:bg-white focus:ring-2 focus:ring-emerald-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">بيان السداد / الغرض</label>
                    <input type="text" name="purpose" value="دفعة سداد لحساب مشروع: <?= htmlspecialchars($project['name']) ?>" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs focus:bg-white focus:ring-2 focus:ring-emerald-500 focus:outline-none">
                </div>
            </div>

            <!-- Notes -->
            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">ملاحظات إضافية</label>
                <textarea name="notes" rows="2" placeholder="أي تفاصيل أو رقم مرجع التحويل..." class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs focus:bg-white focus:ring-2 focus:ring-emerald-500 focus:outline-none"></textarea>
            </div>

            <!-- Print Receipt Checkbox -->
            <div class="flex items-center justify-between p-3 bg-emerald-50/50 rounded-xl border border-emerald-100">
                <label class="flex items-center space-x-2 space-x-reverse cursor-pointer">
                    <input type="checkbox" name="print_receipt" value="1" checked class="rounded text-emerald-600 focus:ring-emerald-500 w-4 h-4">
                    <span class="text-xs font-bold text-slate-800">طباعة سند القبض الرسمي A4 فور الحفظ</span>
                </label>
                <i class="fa-solid fa-print text-emerald-600"></i>
            </div>

            <div class="flex items-center space-x-2 space-x-reverse pt-2">
                <button type="submit" class="flex-1 bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-2.5 px-4 rounded-xl shadow-md transition text-sm">
                    حفظ سند القبض وتوريد المبلغ
                </button>
                <button type="button" onclick="closeModal('record-client-payment-modal')" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold py-2.5 px-4 rounded-xl transition text-sm">
                    إلغاء
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal 2: Quick Project Expense (Dedicated for this project) -->
<div id="quick-project-expense-modal" class="modal-container fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/60 backdrop-blur-xs p-4 overflow-y-auto">
    <div class="modal-backdrop fixed inset-0" onclick="closeModal('quick-project-expense-modal')"></div>
    <div class="relative bg-white rounded-3xl shadow-2xl max-w-xl w-full p-6 sm:p-8 modal-animate-enter z-10 border border-slate-100">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-4">
            <div class="flex items-center space-x-2 space-x-reverse">
                <div class="w-10 h-10 rounded-xl bg-rose-100 text-rose-600 flex items-center justify-center font-bold text-lg">
                    <i class="fa-solid fa-receipt"></i>
                </div>
                <div>
                    <h3 class="font-black text-slate-800 text-lg">تسجيل فاتورة شراء لمشروع <?= htmlspecialchars($project['name']) ?></h3>
                    <p class="text-xs text-slate-400">تخصم تلقائياً من <?= htmlspecialchars($safe['name']) ?> (الرصيد: <?= formatMoney($safe['balance'], $projCurrency) ?>)</p>
                </div>
            </div>
            <button onclick="closeModal('quick-project-expense-modal')" class="text-slate-400 hover:text-slate-600 text-lg">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form action="projects.php" method="POST" enctype="multipart/form-data" class="space-y-4">
            <?= getCSRFInput() ?>
            <input type="hidden" name="action" value="expense_project">
            <input type="hidden" name="project_id" value="<?= $projectId ?>">
            <input type="hidden" name="currency" value="<?= htmlspecialchars($projCurrency) ?>">

            <!-- Title & Category -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">سبب الصرف / البند <span class="text-red-500">*</span></label>
                    <input type="text" name="title" required placeholder="مثال: حديد تسليح، أسمنت، خرسانة، عمالة..." class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-sm focus:bg-white focus:ring-2 focus:ring-rose-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1 flex items-center justify-between">
                        <span>تصنيف وبند المصروف:</span>
                        <span id="proj_exp_cat_feedback" class="text-[10px] font-bold text-emerald-600 hidden">
                            <i class="fa-solid fa-check ml-0.5"></i> تم الاعتماد
                        </span>
                    </label>
                    <div class="relative" id="proj-cat-combobox-wrapper">
                        <div class="relative">
                            <input type="text" id="proj_cat_search_input" placeholder="ابحث أو اكتب بنداً واضغط Enter..." autocomplete="off" class="w-full bg-slate-50 border border-slate-300 rounded-xl pr-8 pl-7 py-2 text-xs font-bold focus:bg-white focus:ring-2 focus:ring-rose-500 focus:outline-none">
                            <span class="absolute right-2.5 top-2 text-slate-400 text-xs">
                                <i class="fa-solid fa-tag"></i>
                            </span>
                            <button type="button" id="proj_cat_clear_btn" onclick="clearProjCategorySelection()" class="absolute left-2.5 top-2 text-slate-400 hover:text-slate-600 text-xs hidden">
                                <i class="fa-solid fa-xmark"></i>
                            </button>
                        </div>
                        <input type="hidden" name="category_id" id="proj_selected_cat_id" value="">
                        <input type="hidden" name="new_category_name" id="proj_selected_cat_new_name" value="">
                        <div id="proj_cat_dropdown_menu" class="absolute z-30 left-0 right-0 mt-1 bg-white border border-slate-200 rounded-2xl shadow-xl max-h-48 overflow-y-auto hidden divide-y divide-slate-100">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Amount & 15% VAT calculation -->
            <div class="p-4 bg-slate-50 rounded-2xl border border-slate-200 space-y-3">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">المبلغ الأساسي (قبل الضريبة) <span class="text-red-500">*</span></label>
                    <div class="relative">
                        <input type="number" step="0.01" min="0.01" id="exp2_base_amount" name="amount" required placeholder="مثلاً: 100" oninput="calcVatTotal2()" class="w-full text-xl font-bold bg-white border border-slate-300 rounded-xl px-3 py-2 text-rose-600 focus:ring-2 focus:ring-rose-500 focus:outline-none">
                        <span class="absolute left-3 top-2.5 font-bold text-slate-400 text-xs"><?= $projCurrency ?></span>
                    </div>
                </div>

                <!-- 15% VAT Checkbox -->
                <div class="flex items-center justify-between p-2.5 bg-white rounded-xl border border-slate-200">
                    <label class="flex items-center space-x-2 space-x-reverse cursor-pointer">
                        <input type="checkbox" id="exp2_apply_vat" name="apply_vat" value="1" onchange="calcVatTotal2()" class="rounded text-rose-600 focus:ring-rose-500 w-4 h-4">
                        <span class="text-xs font-bold text-slate-800">تطبيق ضريبة القيمة المضافة (15% VAT)</span>
                    </label>
                    <span id="exp2_vat_preview" class="text-xs font-bold text-slate-400">بدون ضريبة (0.00)</span>
                </div>

                <!-- Final Total Preview -->
                <div class="flex items-center justify-between pt-1 border-t border-slate-200">
                    <span class="text-xs font-bold text-slate-600">الإجمالي النهائي المخصوم من الخزنة:</span>
                    <span id="exp2_total_preview" class="text-base font-black text-rose-600">0.00 <?= $projCurrency ?></span>
                </div>
            </div>

            <!-- Beneficiary Name, ID & Phone -->
            <div class="p-4 bg-indigo-50/50 rounded-2xl border border-indigo-100 space-y-3">
                <p class="text-xs font-black text-indigo-900">بيانات المستفيد / المورد (لسند الصرف والفاتورة A4):</p>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                        <label class="block text-[11px] font-bold text-slate-700 mb-1">اسم المستفيد / المقاول</label>
                        <input type="text" name="beneficiary_name" placeholder="مثال: شركة الراجحي" class="w-full bg-white border border-slate-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-rose-500 focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-slate-700 mb-1">رقم الهوية / السجل</label>
                        <input type="text" name="beneficiary_id_number" placeholder="رقم الهوية" class="w-full bg-white border border-slate-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-rose-500 focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-slate-700 mb-1">رقم الهاتف</label>
                        <input type="text" name="beneficiary_phone" placeholder="05xxxxxxxx" class="w-full bg-white border border-slate-300 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-rose-500 focus:outline-none">
                    </div>
                </div>
            </div>

            <!-- Invoice Attachment File -->
            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">
                    <i class="fa-solid fa-paperclip ml-1 text-slate-400"></i>
                    إرفاق فاتورة الشراء أو السند (صورة أو PDF)
                </label>
                <input type="file" name="invoice_file" accept=".jpg,.jpeg,.png,.webp,.pdf" class="w-full text-xs text-slate-600 bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 file:mr-2 file:py-1 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-bold file:bg-rose-50 file:text-rose-700 hover:file:bg-rose-100">
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">تاريخ الفاتورة</label>
                    <input type="date" name="expense_date" value="<?= date('Y-m-d') ?>" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-sm focus:bg-white focus:ring-2 focus:ring-rose-500 focus:outline-none">
                </div>
                <div class="flex items-center pt-5">
                    <label class="flex items-center space-x-2 space-x-reverse cursor-pointer">
                        <input type="checkbox" name="print_after" value="1" checked class="rounded text-rose-600 focus:ring-rose-500 w-4 h-4">
                        <span class="text-xs font-bold text-slate-800">طباعة سند الصرف A4 فور الحفظ</span>
                    </label>
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">ملاحظات إضافية</label>
                <textarea name="description" rows="2" placeholder="ملاحظات أو مواصفات الكمية..." class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-sm focus:bg-white focus:ring-2 focus:ring-rose-500 focus:outline-none"></textarea>
            </div>

            <div class="flex items-center space-x-2 space-x-reverse pt-2">
                <button type="submit" class="flex-1 bg-rose-600 hover:bg-rose-700 text-white font-bold py-2.5 px-4 rounded-xl shadow-md transition text-sm">
                    تأكيد الخصم من الخزنة
                </button>
                <button type="button" onclick="closeModal('quick-project-expense-modal')" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold py-2.5 px-4 rounded-xl transition text-sm">
                    إلغاء
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal 3: Quick Deposit Modal (Dedicated for this project) -->
<div id="quick-project-deposit-modal" class="modal-container fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/60 backdrop-blur-xs p-4 overflow-y-auto">
    <div class="modal-backdrop fixed inset-0" onclick="closeModal('quick-project-deposit-modal')"></div>
    <div class="relative bg-white rounded-3xl shadow-2xl max-w-md w-full p-6 modal-animate-enter z-10 border border-slate-100">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-4">
            <div class="flex items-center space-x-2 space-x-reverse">
                <div class="w-9 h-9 rounded-xl bg-emerald-100 text-emerald-600 flex items-center justify-center font-bold">
                    <i class="fa-solid fa-circle-plus"></i>
                </div>
                <h3 class="font-bold text-slate-800 text-lg">إيداع سيولة في <?= htmlspecialchars($safe['name']) ?></h3>
            </div>
            <button onclick="closeModal('quick-project-deposit-modal')" class="text-slate-400 hover:text-slate-600 text-lg">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form action="projects.php" method="POST" class="space-y-4">
            <?= getCSRFInput() ?>
            <input type="hidden" name="action" value="deposit_project">
            <input type="hidden" name="project_id" value="<?= $projectId ?>">
            <input type="hidden" name="currency" value="<?= htmlspecialchars($projCurrency) ?>">

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">المبلغ المودع (<?= $projCurrency ?>) <span class="text-red-500">*</span></label>
                <input type="number" step="0.01" min="0.01" name="amount" required placeholder="0.00" class="w-full text-xl font-bold bg-slate-50 border border-slate-300 rounded-xl px-4 py-2 text-emerald-600 focus:bg-white focus:ring-2 focus:ring-emerald-500 focus:outline-none">
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">مصدر الإيداع / البند <span class="text-red-500">*</span></label>
                <input type="text" name="category" required placeholder="مثال: تمويل، دفعة مستثمر، تغذية خزنة" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-sm focus:bg-white focus:ring-2 focus:ring-emerald-500 focus:outline-none">
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">تاريخ الإيداع</label>
                <input type="date" name="transaction_date" value="<?= date('Y-m-d') ?>" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-sm focus:bg-white focus:ring-2 focus:ring-emerald-500 focus:outline-none">
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">ملاحظات</label>
                <textarea name="description" rows="2" placeholder="أي تفاصيل أو رقم تحويل..." class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-sm focus:bg-white focus:ring-2 focus:ring-emerald-500 focus:outline-none"></textarea>
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

<!-- Modal: Add Daily Progress Report -->
<div id="add-daily-report-modal" class="modal-container fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/60 backdrop-blur-xs p-4 overflow-y-auto">
    <div class="modal-backdrop fixed inset-0" onclick="closeModal('add-daily-report-modal')"></div>
    <div class="relative bg-white rounded-3xl shadow-2xl max-w-2xl w-full p-6 sm:p-7 modal-animate-enter z-10 border border-slate-100 max-h-[92vh] overflow-y-auto">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-5">
            <div class="flex items-center space-x-2.5 space-x-reverse">
                <div class="w-10 h-10 rounded-2xl bg-blue-100 text-blue-600 flex items-center justify-center text-lg font-bold">
                    <i class="fa-solid fa-clipboard-check"></i>
                </div>
                <div>
                    <h3 class="font-black text-slate-800 text-lg">إضافة تقرير موقع وإنجاز ميداني جديد</h3>
                    <p class="text-xs text-slate-500">مشروع: <?= htmlspecialchars($project['name']) ?></p>
                </div>
            </div>
            <button onclick="closeModal('add-daily-report-modal')" class="text-slate-400 hover:text-slate-600 text-lg">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form action="project_view.php?id=<?= $projectId ?>" method="POST" enctype="multipart/form-data" class="space-y-4">
            <?= getCSRFInput() ?>
            <input type="hidden" name="action" value="add_daily_report">

            <!-- 1: Report Title -->
            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">
                    عنوان التقرير / ملخص الإنجاز <span class="text-red-500">*</span>
                </label>
                <input type="text" name="title" required placeholder="مثال: إنجاز أعمال السباكة وشبكة الصرف والتغذية" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-4 py-2.5 text-sm font-bold text-slate-800 focus:bg-white focus:ring-2 focus:ring-blue-500 focus:outline-none">
            </div>

            <!-- 2: Period Range (من السبت للسبت) & Date -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">
                        نطاق الفترة / مدة الإنجاز <span class="text-blue-600 font-normal">(مثال: من السبت للسبت)</span>
                    </label>
                    <input type="text" id="rep_period_input" name="period_range" placeholder="مثال: من السبت للسبت" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs font-bold text-slate-800 focus:bg-white focus:ring-2 focus:ring-blue-500 focus:outline-none">
                    
                    <!-- Quick Pill buttons for period -->
                    <div class="flex flex-wrap items-center gap-1.5 mt-2">
                        <span class="text-[10px] text-slate-400">خيارات سريعة:</span>
                        <button type="button" onclick="setPeriodQuick('من السبت للسبت')" class="px-2 py-0.5 rounded-lg text-[10px] font-bold bg-indigo-50 text-indigo-700 border border-indigo-200 hover:bg-indigo-100 transition">
                            من السبت للسبت
                        </button>
                        <button type="button" onclick="setPeriodQuick('من الخميس للخميس')" class="px-2 py-0.5 rounded-lg text-[10px] font-bold bg-indigo-50 text-indigo-700 border border-indigo-200 hover:bg-indigo-100 transition">
                            من الخميس للخميس
                        </button>
                        <button type="button" onclick="setPeriodQuick('من الأحد للخميس')" class="px-2 py-0.5 rounded-lg text-[10px] font-bold bg-indigo-50 text-indigo-700 border border-indigo-200 hover:bg-indigo-100 transition">
                            من الأحد للخميس
                        </button>
                        <button type="button" onclick="setPeriodQuick('تقرير يومي')" class="px-2 py-0.5 rounded-lg text-[10px] font-bold bg-slate-100 text-slate-700 hover:bg-slate-200 transition">
                            تقرير يومي
                        </button>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">
                        تاريخ التقرير <span class="text-red-500">*</span>
                    </label>
                    <input type="date" name="report_date" value="<?= date('Y-m-d') ?>" required class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs font-bold text-slate-800 focus:bg-white focus:ring-2 focus:ring-blue-500 focus:outline-none">
                </div>
            </div>

            <!-- 3: Stage & Completion % -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">المرحلة الإنشائية</label>
                    <input type="text" id="rep_stage_input" name="stage" list="stages_datalist" placeholder="مثال: أعمال السباكة، العظم، اللياسة..." class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs font-bold text-slate-800 focus:bg-white focus:ring-2 focus:ring-blue-500 focus:outline-none">
                    <datalist id="stages_datalist">
                        <option value="أعمال السباكة والتمديدات">
                        <option value="أعمال الكهرباء والتأسيس">
                        <option value="مرحلة العظم والخرسانات">
                        <option value="أعمال المباني والبلوك">
                        <option value="أعمال اللياسة والمحارة">
                        <option value="أعمال العزل المائي والحراري">
                        <option value="أعمال البلاط والسيراميك">
                        <option value="أعمال الدهانات والتشطيب">
                        <option value="أعمال الجبس والأسقف">
                        <option value="أعمال الألمنيوم والواجهات">
                        <option value="تسليم نهائي وتشطيبات">
                    </datalist>
                </div>

                <div>
                    <div class="flex items-center justify-between mb-1">
                        <label class="block text-xs font-bold text-slate-700">نسبة إنجاز المرحلة</label>
                        <span id="rep_pct_val" class="text-xs font-black font-mono text-blue-600">50%</span>
                    </div>
                    <div class="flex items-center space-x-2 space-x-reverse">
                        <input type="range" id="rep_pct_range" min="0" max="100" step="5" value="50" oninput="syncPct(this.value)" class="w-full accent-blue-600">
                        <input type="number" id="rep_pct_num" name="completion_percentage" min="0" max="100" value="50" oninput="syncPct(this.value)" class="w-16 bg-slate-50 border border-slate-300 rounded-xl px-2 py-1 text-xs text-center font-bold focus:bg-white focus:ring-2 focus:ring-blue-500 focus:outline-none">
                    </div>
                </div>
            </div>

            <!-- 4: Description -->
            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">تفاصيل وملاحظات الإنجاز الميداني</label>
                <textarea name="description" rows="3" placeholder="اكتب وصفاً لما تم إنجازه في الموقع خلال هذه الفترة (مثال: تم الانتهاء من تمديد مواسير الصرف والتغذية للدور الأول واختبار الضغط...)" class="w-full bg-slate-50 border border-slate-300 rounded-xl p-3 text-xs leading-relaxed focus:bg-white focus:ring-2 focus:ring-blue-500 focus:outline-none"></textarea>
            </div>

            <!-- 5: Multiple Photos Upload -->
            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">
                    <i class="fa-solid fa-images ml-1 text-blue-600"></i>
                    صور الموقع الميدانية (يمكن اختيار عدة صور معاً)
                </label>
                <div class="border-2 border-dashed border-slate-300 rounded-2xl p-4 text-center bg-slate-50/70 hover:bg-blue-50/30 transition">
                    <input type="file" name="report_images[]" id="rep_photos_input" multiple accept="image/jpeg,image/png,image/webp" onchange="previewReportPhotos(this)" class="hidden">
                    <label for="rep_photos_input" class="cursor-pointer flex flex-col items-center">
                        <i class="fa-solid fa-cloud-arrow-up text-2xl text-blue-500 mb-1"></i>
                        <span class="text-xs font-bold text-slate-700">اضغط هنا لاختيار صور الإنجاز من جهازك أو الجوال</span>
                        <span class="text-[10px] text-slate-400 mt-0.5">صيغ مدعومة: JPG, PNG, WEBP (يمكن تحديد أكثر من صورة معاً)</span>
                    </label>
                    <div id="photos_preview_grid" class="grid grid-cols-4 sm:grid-cols-6 gap-2 mt-3 hidden"></div>
                </div>
            </div>

            <!-- 6: Video Upload & Video URL -->
            <div class="border border-slate-200 rounded-2xl p-3 bg-slate-50/50 space-y-3">
                <span class="text-xs font-bold text-slate-700 flex items-center">
                    <i class="fa-solid fa-video ml-1.5 text-rose-600"></i>
                    فيديو توثيقي من الموقع (اختياري)
                </span>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                    <div>
                        <label class="block text-[11px] font-bold text-slate-600 mb-1">رفع ملف فيديو مباشر</label>
                        <input type="file" name="video_file" accept="video/mp4,video/webm,video/quicktime,video/mkv" class="w-full text-xs text-slate-600 file:mr-2 file:py-1.5 file:px-3 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-rose-50 file:text-rose-700 hover:file:bg-rose-100">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-slate-600 mb-1">أو رابط فيديو (يوتيوب / Google Drive)</label>
                        <input type="url" name="video_url" placeholder="https://..." class="w-full bg-white border border-slate-300 rounded-xl px-3 py-1.5 text-xs focus:ring-2 focus:ring-rose-500 focus:outline-none">
                    </div>
                </div>
            </div>

            <!-- Submit Buttons -->
            <div class="flex items-center space-x-2 space-x-reverse pt-2">
                <button type="submit" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-bold py-2.5 px-4 rounded-xl shadow-md transition text-sm flex items-center justify-center">
                    <i class="fa-solid fa-cloud-arrow-up ml-1.5"></i>
                    حفظ التقرير وإرفاق الصور
                </button>
                <button type="button" onclick="closeModal('add-daily-report-modal')" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold py-2.5 px-4 rounded-xl transition text-sm">
                    إلغاء
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Lightbox for Report Images -->
<div id="report-image-lightbox-modal" class="fixed inset-0 z-50 bg-slate-900/85 backdrop-blur-sm hidden items-center justify-center p-4" onclick="closeReportLightbox()">
    <div class="relative bg-white rounded-3xl max-w-3xl w-full p-4 shadow-2xl overflow-hidden" onclick="event.stopPropagation()">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-3">
            <h4 class="font-bold text-slate-800 text-sm" id="rep_lightbox_title">معاينة صورة إنجاز الموقع</h4>
            <button onclick="closeReportLightbox()" class="w-8 h-8 rounded-full bg-slate-100 text-slate-500 hover:bg-slate-200 flex items-center justify-center">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div class="max-h-[75vh] overflow-auto flex items-center justify-center bg-slate-950 rounded-2xl p-2">
            <img id="rep_lightbox_img" src="" alt="صورة الموقع" class="max-h-[70vh] max-w-full object-contain rounded-xl shadow-md">
        </div>
        <div class="pt-3 text-center">
            <a id="rep_lightbox_download" href="" target="_blank" download class="inline-flex items-center bg-blue-600 hover:bg-blue-700 text-white font-bold px-4 py-2 rounded-xl text-xs transition">
                <i class="fa-solid fa-download ml-1.5"></i> فتح الصورة بدقتها الكاملة أو تحميلها
            </a>
        </div>
    </div>
</div>

<script>
function switchTab(tabId) {
    document.querySelectorAll('.tab-pane').forEach(el => el.classList.add('hidden'));
    const target = document.getElementById(tabId);
    if (target) {
        target.classList.remove('hidden');
    }

    document.querySelectorAll('#project-tabs button').forEach(btn => {
        btn.classList.remove('border-amber-600', 'text-amber-600');
        btn.classList.add('border-transparent', 'text-slate-500');
    });

    const activeBtn = document.getElementById('btn-' + tabId);
    if (activeBtn) {
        activeBtn.classList.remove('border-transparent', 'text-slate-500');
        activeBtn.classList.add('border-amber-600', 'text-amber-600');
    }
}

function setPeriodQuick(text) {
    const input = document.getElementById('rep_period_input');
    if (input) {
        input.value = text;
        input.focus();
    }
}

function syncPct(val) {
    val = Math.max(0, Math.min(100, parseInt(val) || 0));
    const range = document.getElementById('rep_pct_range');
    const num = document.getElementById('rep_pct_num');
    const display = document.getElementById('rep_pct_val');
    if (range) range.value = val;
    if (num) num.value = val;
    if (display) display.innerText = val + '%';
}

function previewReportPhotos(input) {
    const grid = document.getElementById('photos_preview_grid');
    if (!grid) return;
    grid.innerHTML = '';
    if (input.files && input.files.length > 0) {
        grid.classList.remove('hidden');
        Array.from(input.files).forEach(file => {
            if (file.type.startsWith('image/')) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const div = document.createElement('div');
                    div.className = 'aspect-square rounded-xl overflow-hidden bg-slate-100 border border-slate-200';
                    div.innerHTML = `<img src="${e.target.result}" class="w-full h-full object-cover">`;
                    grid.appendChild(div);
                };
                reader.readAsDataURL(file);
            }
        });
    } else {
        grid.classList.add('hidden');
    }
}

function openReportLightbox(src, title) {
    const modal = document.getElementById('report-image-lightbox-modal');
    const img = document.getElementById('rep_lightbox_img');
    const link = document.getElementById('rep_lightbox_download');
    const titleEl = document.getElementById('rep_lightbox_title');
    if (!modal || !img) return;

    img.src = src;
    if (link) link.href = src;
    if (titleEl) titleEl.innerText = title || 'معاينة صورة الموقع';
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function closeReportLightbox() {
    const modal = document.getElementById('report-image-lightbox-modal');
    if (modal) {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const params = new URLSearchParams(window.location.search);
    const tabParam = params.get('tab');
    if (tabParam) {
        const targetTabId = tabParam.startsWith('tab-') ? tabParam : 'tab-' + tabParam;
        if (document.getElementById(targetTabId)) {
            switchTab(targetTabId);
        }
    }
});

function calcVatTotal2() {
    const baseInput = document.getElementById('exp2_base_amount');
    const vatCheck = document.getElementById('exp2_apply_vat');
    const vatPreview = document.getElementById('exp2_vat_preview');
    const totalPreview = document.getElementById('exp2_total_preview');

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
    totalPreview.innerText = total.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' <?= $projCurrency ?>';
}

function copyClientReportLink(url) {
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(url).then(showCopiedToast);
    } else {
        var textArea = document.createElement("textarea");
        textArea.value = url;
        textArea.style.position = "fixed";
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();
        try {
            document.execCommand('copy');
            showCopiedToast();
        } catch (err) {}
        document.body.removeChild(textArea);
    }
}

function toggleEditProjectType(type) {
    const clientFields = document.getElementById('edit-client-fields');
    const capitalField = document.getElementById('edit-capital-field');
    const capitalInput = document.getElementById('edit-capital-input');
    if (type === 'without_capital') {
        if (clientFields) clientFields.classList.remove('hidden');
        if (capitalField) capitalField.classList.add('hidden');
        if (capitalInput) capitalInput.value = '0';
    } else {
        if (clientFields) clientFields.classList.add('hidden');
        if (capitalField) capitalField.classList.remove('hidden');
    }
}

function updateEditLandTotalPreview() {
    const p = parseFloat(document.getElementById('edit_input_land_price')?.value || 0) || 0;
    const b = parseFloat(document.getElementById('edit_input_land_brokerage')?.value || 0) || 0;
    const t = parseFloat(document.getElementById('edit_input_land_tax')?.value || 0) || 0;
    const total = p + b + t;
    const badge = document.getElementById('edit_land_live_total');
    if (badge) {
        badge.textContent = 'الإجمالي: ' + total.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' <?= $projCurrency ?>';
    }
}

function showCopiedToast() {
    var txt = document.getElementById('btn_copy_link_text');
    if (txt) {
        var old = txt.innerText;
        txt.innerText = 'تم نسخ الرابط بنجاح!';
        setTimeout(function() {
            txt.innerText = old;
        }, 2500);
    }
}

// Project Expense Combobox with Instant Add on Enter
let projCategories = <?= json_encode($categories, JSON_UNESCAPED_UNICODE) ?> || [];
const pCatInput = document.getElementById('proj_cat_search_input');
const pCatDropdown = document.getElementById('proj_cat_dropdown_menu');
const pCatIdInput = document.getElementById('proj_selected_cat_id');
const pCatNewNameInput = document.getElementById('proj_selected_cat_new_name');
const pCatClearBtn = document.getElementById('proj_cat_clear_btn');
const pCatFeedback = document.getElementById('proj_exp_cat_feedback');

function renderProjCatDropdown(query = '') {
    if (!pCatDropdown) return;
    const q = query.trim().toLowerCase();
    let matches = projCategories.filter(c => c.name.toLowerCase().includes(q));
    let html = '';

    const exactMatch = projCategories.find(c => c.name.toLowerCase() === q);
    if (q.length > 0 && !exactMatch) {
        html += `
            <div onclick="createProjCategoryFromInput('${escapeHtml(query.trim())}')" class="p-2.5 bg-rose-50 hover:bg-rose-100 text-rose-900 cursor-pointer flex items-center justify-between transition font-bold text-xs">
                <div class="flex items-center space-x-1.5 space-x-reverse">
                    <span class="w-5 h-5 rounded bg-rose-600 text-white flex items-center justify-center text-[10px]">
                        <i class="fa-solid fa-plus"></i>
                    </span>
                    <span>إضافة <strong>"${escapeHtml(query.trim())}"</strong> كبند جديد</span>
                </div>
                <span class="text-[9px] text-rose-600 bg-white px-1.5 py-0.5 rounded border border-rose-200">
                    اضغط Enter ↵
                </span>
            </div>
        `;
    }

    if (matches.length > 0) {
        matches.forEach(c => {
            html += `
                <div onclick="selectProjCategory(${c.id}, '${escapeHtml(c.name)}')" class="p-2.5 hover:bg-slate-50 cursor-pointer flex items-center justify-between text-xs text-slate-800 transition">
                    <div class="flex items-center space-x-2 space-x-reverse">
                        <i class="fa-solid fa-tag text-slate-400 text-[10px]"></i>
                        <span>${escapeHtml(c.name)}</span>
                    </div>
                </div>
            `;
        });
    } else if (q.length === 0) {
        html += `<div class="p-3 text-xs text-slate-400 text-center">اكتب للبحث أو لإضافة بند جديد...</div>`;
    }

    pCatDropdown.innerHTML = html;
    pCatDropdown.classList.remove('hidden');
}

function selectProjCategory(id, name) {
    if (pCatIdInput) pCatIdInput.value = id;
    if (pCatNewNameInput) pCatNewNameInput.value = '';
    if (pCatInput) pCatInput.value = name;
    if (pCatDropdown) pCatDropdown.classList.add('hidden');
    if (pCatClearBtn) pCatClearBtn.classList.remove('hidden');
    if (pCatFeedback) {
        pCatFeedback.innerHTML = `<i class="fa-solid fa-check ml-0.5 text-emerald-500"></i> تم اختيار البند`;
        pCatFeedback.classList.remove('hidden');
        setTimeout(() => pCatFeedback.classList.add('hidden'), 3000);
    }
}

function clearProjCategorySelection() {
    if (pCatIdInput) pCatIdInput.value = '';
    if (pCatNewNameInput) pCatNewNameInput.value = '';
    if (pCatInput) {
        pCatInput.value = '';
        pCatInput.focus();
        renderProjCatDropdown('');
    }
    if (pCatClearBtn) pCatClearBtn.classList.add('hidden');
    if (pCatFeedback) pCatFeedback.classList.add('hidden');
}

function createProjCategoryFromInput(catName) {
    if (!catName || !catName.trim()) return;
    const name = catName.trim();
    const csrfEl = document.querySelector('input[name="csrf_token"]');
    const csrf = csrfEl ? csrfEl.value : '';

    if (pCatFeedback) {
        pCatFeedback.innerHTML = `<i class="fa-solid fa-spinner fa-spin ml-0.5 text-rose-500"></i> جاري إضافة البند...`;
        pCatFeedback.classList.remove('hidden');
    }

    fetch('ajax_category_create.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            'csrf_token': csrf,
            'name': name
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            if (!projCategories.some(c => c.id === data.id)) {
                projCategories.push({ id: data.id, name: data.name });
            }
            selectProjCategory(data.id, data.name);
            if (pCatFeedback) {
                pCatFeedback.innerHTML = `<i class="fa-solid fa-check ml-0.5 text-emerald-500"></i> تمت إضافة البند بنجاح`;
                pCatFeedback.classList.remove('hidden');
                setTimeout(() => pCatFeedback.classList.add('hidden'), 3000);
            }
        } else {
            alert(data.error || 'فشل إضافة البند');
            if (pCatFeedback) pCatFeedback.classList.add('hidden');
        }
    })
    .catch(() => {
        if (pCatIdInput) pCatIdInput.value = '';
        if (pCatNewNameInput) pCatNewNameInput.value = name;
        if (pCatInput) pCatInput.value = name;
        if (pCatDropdown) pCatDropdown.classList.add('hidden');
        if (pCatClearBtn) pCatClearBtn.classList.remove('hidden');
    });
}

function escapeHtml(str) {
    return (str + '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

if (pCatInput) {
    pCatInput.addEventListener('focus', () => renderProjCatDropdown(pCatInput.value));
    pCatInput.addEventListener('input', () => {
        if (pCatIdInput) pCatIdInput.value = '';
        if (pCatNewNameInput) pCatNewNameInput.value = '';
        if (pCatInput.value.trim().length > 0) {
            if (pCatClearBtn) pCatClearBtn.classList.remove('hidden');
        } else {
            if (pCatClearBtn) pCatClearBtn.classList.add('hidden');
        }
        renderProjCatDropdown(pCatInput.value);
    });
    pCatInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            const val = pCatInput.value.trim();
            if (!val) return;
            const match = projCategories.find(c => c.name.toLowerCase() === val.toLowerCase());
            if (match) {
                selectProjCategory(match.id, match.name);
            } else {
                createProjCategoryFromInput(val);
            }
        }
    });

    document.addEventListener('click', (e) => {
        const wrapper = document.getElementById('proj-cat-combobox-wrapper');
        if (wrapper && !wrapper.contains(e.target) && pCatDropdown) {
            pCatDropdown.classList.add('hidden');
        }
    });
}
</script>

<?php include_once __DIR__ . '/includes/footer.php'; ?>
