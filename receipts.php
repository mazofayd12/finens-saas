<?php
require_once __DIR__ . '/config/app.php';
requireLogin();

$currentUser = getCurrentUser();
$activeCompanyId = (int)$currentUser['active_company_id'];
$currency = $currentUser['currency'] ?? 'ج.م';
$db = getDBConnection();

$safe = getCompanyMainSafe($activeCompanyId);

// Fetch company details including logo
$compStmt = $db->prepare("SELECT * FROM companies WHERE id = :cid LIMIT 1");
$compStmt->execute([':cid' => $activeCompanyId]);
$company = $compStmt->fetch();

// Handle Logo Upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_logo') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        die('خطأ في التحقق من صحة الطلب');
    }

    if (isset($_FILES['company_logo']) && $_FILES['company_logo']['error'] === UPLOAD_ERR_OK) {
        $fileTmp = $_FILES['company_logo']['tmp_name'];
        $fileName = $_FILES['company_logo']['name'];
        $fileSize = $_FILES['company_logo']['size'];
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        $allowedExts = ['png', 'jpg', 'jpeg', 'webp', 'svg'];
        if (!in_array($ext, $allowedExts)) {
            setFlash('error', 'صيغة الصورة غير مدعومة. يرجى رفع ملف صورة (PNG, JPG, WEBP, SVG).');
        } elseif ($fileSize > 5 * 1024 * 1024) {
            setFlash('error', 'حجم الصورة كبير جداً، الحد الأقصى 5 ميجابايت.');
        } else {
            $newFileName = 'logo_' . $activeCompanyId . '_' . time() . '.' . $ext;
            $uploadDir = APP_PATH . '/assets/uploads/logos/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            $targetPath = $uploadDir . $newFileName;

            if (move_uploaded_file($fileTmp, $targetPath)) {
                $upd = $db->prepare("UPDATE companies SET logo = :logo WHERE id = :id");
                $upd->execute([':logo' => 'assets/uploads/logos/' . $newFileName, ':id' => $activeCompanyId]);
                setFlash('success', 'تم تحديث شعار المقر والشركة بنجاح!');
                header('Location: receipts.php');
                exit;
            } else {
                setFlash('error', 'فشل حفظ ملف الشعار على الخادم.');
            }
        }
    } else {
        setFlash('error', 'يرجى اختيار ملف الشعار أولاً.');
    }
}

// Handle POST: Create Receipt
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_receipt') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        die('خطأ في التحقق من صحة الطلب');
    }

    $customerName = trim($_POST['customer_name'] ?? '');
    $customerPhone = trim($_POST['customer_phone'] ?? '');
    $amount = (float)($_POST['amount'] ?? 0);
    $recCurrency = trim($_POST['currency'] ?? $currency);
    $paymentMethod = trim($_POST['payment_method'] ?? 'نقداً كاش');
    $purpose = trim($_POST['purpose'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $receiptDate = trim($_POST['receipt_date'] ?? date('Y-m-d'));
    $depositToSafe = isset($_POST['deposit_to_safe']) ? 1 : 0;

    if (empty($customerName) || $amount <= 0 || empty($purpose)) {
        setFlash('error', 'يرجى إدخال اسم العميل، المبلغ، وبيان وسبب الاستلام.');
    } else {
        $amountWords = tafqeetNumber($amount, $recCurrency);
        $receiptNumber = generateNextReceiptNumber($activeCompanyId);

        $db->beginTransaction();
        try {
            $safeTxId = null;

            // Automatically deposit into treasury if checked
            if ($depositToSafe && hasModule('treasury')) {
                $txDesc = "إيصال استلام رقم {$receiptNumber} من العميل: {$customerName}";
                if ($recCurrency !== $currency) {
                    $txDesc .= " (المبلغ: " . formatMoney($amount, $recCurrency) . ")";
                }
                $txDesc .= " - بيان: {$purpose}";

                $txResult = recordSafeTransaction(
                    $activeCompanyId,
                    $safe['id'],
                    $currentUser['id'],
                    'deposit',
                    'تحصيل نقدية وإيصال قبض',
                    $amount,
                    $txDesc,
                    $receiptDate,
                    $recCurrency
                );
                $safeTxId = $txResult['transaction_id'];
            }

            // Insert receipt record
            $insRec = $db->prepare("
                INSERT INTO receipts (
                    receipt_number, company_id, user_id, safe_transaction_id,
                    customer_name, customer_phone, amount, currency, amount_words, payment_method,
                    purpose, receipt_date, notes, created_at
                ) VALUES (
                    :rnum, :cid, :uid, :txid,
                    :cname, :cphone, :amt, :curr, :awords, :pmethod,
                    :purp, :rdate, :notes, :now
                )
            ");
            $insRec->execute([
                ':rnum' => $receiptNumber,
                ':cid' => $activeCompanyId,
                ':uid' => $currentUser['id'],
                ':txid' => $safeTxId,
                ':cname' => $customerName,
                ':cphone' => $customerPhone,
                ':amt' => $amount,
                ':curr' => $recCurrency,
                ':awords' => $amountWords,
                ':pmethod' => $paymentMethod,
                ':purp' => $purpose,
                ':rdate' => $receiptDate,
                ':notes' => $notes,
                ':now' => date('Y-m-d H:i:s')
            ]);
            $newReceiptId = (int)$db->lastInsertId();

            $db->commit();
            setFlash('success', "تم إصدار إيصال الاستلام رقم {$receiptNumber} بنجاح!");
            // Redirect to receipts list or directly to print preview with auto-print
            header('Location: receipt_print.php?id=' . $newReceiptId . '&autoprint=1');
            exit;
        } catch (Exception $e) {
            $db->rollBack();
            setFlash('error', 'حدث خطأ: ' . $e->getMessage());
        }
    }
}

// Search and Filters
$search = trim($_GET['search'] ?? '');
$filterFrom = $_GET['from'] ?? date('Y-m-01');
$filterTo = $_GET['to'] ?? date('Y-m-d');

$query = "
    SELECT r.*, u.name as receiver_name
    FROM receipts r
    LEFT JOIN users u ON r.user_id = u.id
    WHERE r.company_id = :cid
";
$params = [':cid' => $activeCompanyId];

if (!empty($search)) {
    $query .= " AND (r.receipt_number LIKE :s OR r.customer_name LIKE :s OR r.customer_phone LIKE :s OR r.purpose LIKE :s)";
    $params[':s'] = "%{$search}%";
}
if (!empty($filterFrom)) {
    $query .= " AND r.receipt_date >= :from";
    $params[':from'] = $filterFrom;
}
if (!empty($filterTo)) {
    $query .= " AND r.receipt_date <= :to";
    $params[':to'] = $filterTo;
}
$query .= " ORDER BY r.id DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$receiptsList = $stmt->fetchAll();

$totalCollectedAmount = array_sum(array_column($receiptsList, 'amount'));

$pageTitle = 'إيصالات استلام النقدية (سندات القبض)';
require_once __DIR__ . '/includes/header.php';
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-black text-slate-900">إيصالات استلام النقدية (سندات القبض)</h1>
            <p class="text-xs text-slate-500 mt-1">
                إصدار وصولات استلام فلوس فورية، تسجيل بيانات العميل والمستلم، وطباعة 3 وصولات في صفحة A4 واحدة
            </p>
        </div>
        <div class="flex items-center space-x-2 space-x-reverse">
            <button onclick="openModal('create-receipt-modal')" class="inline-flex items-center bg-emerald-600 hover:bg-emerald-700 text-white font-bold px-4 py-2.5 rounded-xl shadow-md transition text-xs sm:text-sm">
                <i class="fa-solid fa-file-circle-plus ml-2"></i>
                إصدار وصل استلام جديد
            </button>
            <button onclick="openModal('company-logo-modal')" class="inline-flex items-center bg-white hover:bg-slate-100 border border-slate-300 text-slate-700 font-bold px-3 py-2 rounded-xl text-xs transition">
                <i class="fa-solid fa-image ml-1.5 text-indigo-600"></i>
                <?= !empty($company['logo']) ? 'تعديل شعار المقر' : 'إضافة شعار المقر' ?>
            </button>
        </div>
    </div>

    <!-- Company Branding Banner (Logo Status) -->
    <div class="bg-gradient-to-r from-slate-900 via-indigo-950 to-slate-900 rounded-3xl p-5 sm:p-6 text-white shadow-xl flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div class="flex items-center space-x-4 space-x-reverse">
            <div class="w-16 h-16 rounded-2xl bg-white/10 border border-white/20 flex items-center justify-center p-1 overflow-hidden shrink-0">
                <?php if (!empty($company['logo']) && file_exists(APP_PATH . '/' . $company['logo'])): ?>
                    <img src="<?= htmlspecialchars($company['logo']) ?>" alt="شعار الشركة" class="w-full h-full object-contain">
                <?php else: ?>
                    <i class="fa-solid fa-building text-2xl text-white/60"></i>
                <?php endif; ?>
            </div>
            <div>
                <span class="text-xs font-bold text-amber-300">شعار وبيانات إيصالات القبض الرسمية</span>
                <h3 class="text-lg font-black mt-0.5"><?= htmlspecialchars($company['name'] ?? 'شركتي') ?></h3>
                <p class="text-xs text-slate-300 mt-0.5">
                    المستلم المسجل حالياً: <span class="font-bold text-emerald-400"><?= htmlspecialchars($currentUser['name']) ?></span> (يتم تثبيته تلقائياً على كل وصل يتم إصداره)
                </p>
            </div>
        </div>
        <div class="flex items-center space-x-2 space-x-reverse">
            <button onclick="openModal('company-logo-modal')" class="bg-white/15 hover:bg-white/25 text-white border border-white/20 font-bold text-xs px-3.5 py-2 rounded-xl transition">
                <i class="fa-solid fa-upload ml-1"></i> رفع الشعار للطباعة
            </button>
        </div>
    </div>

    <!-- Summary Stats -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs flex items-center justify-between">
            <div>
                <p class="text-xs font-bold text-slate-500">إجمالي المبالغ المستلمة</p>
                <p class="text-2xl font-black text-emerald-600 mt-1"><?= formatMoney($totalCollectedAmount, $currency) ?></p>
                <span class="text-[11px] text-slate-400">بحسب فترة البحث</span>
            </div>
            <div class="w-12 h-12 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center text-xl">
                <i class="fa-solid fa-hand-holding-dollar"></i>
            </div>
        </div>

        <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs flex items-center justify-between">
            <div>
                <p class="text-xs font-bold text-slate-500">عدد الإيصالات المصدرة</p>
                <p class="text-2xl font-black text-slate-800 mt-1"><?= count($receiptsList) ?> إيصال</p>
                <span class="text-[11px] text-slate-400">بأرقام تسلسلية فريدة لا تتغير</span>
            </div>
            <div class="w-12 h-12 rounded-xl bg-indigo-50 text-brand-600 flex items-center justify-center text-xl">
                <i class="fa-solid fa-receipt"></i>
            </div>
        </div>

        <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs flex items-center justify-between">
            <div>
                <p class="text-xs font-bold text-slate-500">تنسيق الطباعة A4</p>
                <p class="text-base font-black text-slate-800 mt-1">3 وصولات بالورقة</p>
                <span class="text-[11px] text-emerald-600 font-semibold">عميل + حسابات + أرشيف</span>
            </div>
            <div class="w-12 h-12 rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center text-xl">
                <i class="fa-solid fa-print"></i>
            </div>
        </div>
    </div>

    <!-- Search & Filter Bar -->
    <div class="no-print bg-white rounded-2xl p-4 border border-slate-200/80 shadow-xs">
        <form method="GET" action="receipts.php" class="grid grid-cols-1 sm:grid-cols-4 gap-3 items-end text-xs">
            <div>
                <label class="block font-bold text-slate-700 mb-1">بحث برقم الإيصال / اسم العميل / الهاتف</label>
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="مثال: REC-0001 أو اسم العميل..." class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 focus:bg-white focus:outline-none">
            </div>
            <div>
                <label class="block font-bold text-slate-700 mb-1">من تاريخ</label>
                <input type="date" name="from" value="<?= htmlspecialchars($filterFrom) ?>" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 focus:bg-white focus:outline-none">
            </div>
            <div>
                <label class="block font-bold text-slate-700 mb-1">إلى تاريخ</label>
                <input type="date" name="to" value="<?= htmlspecialchars($filterTo) ?>" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 focus:bg-white focus:outline-none">
            </div>
            <div class="flex items-center space-x-2 space-x-reverse">
                <button type="submit" class="flex-1 bg-brand-600 hover:bg-brand-700 text-white font-bold py-2 px-3 rounded-xl transition text-center">
                    <i class="fa-solid fa-search ml-1"></i> بحث وتصفية
                </button>
                <a href="receipts.php" class="bg-slate-100 hover:bg-slate-200 text-slate-600 font-bold py-2 px-3 rounded-xl transition text-center" title="إعادة تعيين">
                    <i class="fa-solid fa-rotate-right"></i>
                </a>
            </div>
        </form>
    </div>

    <!-- Receipts Table -->
    <div class="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
        <div class="p-4 sm:p-5 border-b border-slate-100 flex items-center justify-between">
            <h3 class="font-black text-slate-800 text-base">سجل إيصالات استلام النقدية</h3>
            <span class="text-xs text-slate-400"><?= count($receiptsList) ?> إيصال مسجل</span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-right text-xs">
                <thead class="bg-slate-50 text-slate-500 font-bold border-b border-slate-200">
                    <tr>
                        <th class="p-3.5">رقم الإيصال (ID)</th>
                        <th class="p-3.5">تاريخ الاستلام</th>
                        <th class="p-3.5">اسم العميل (المستلم منه)</th>
                        <th class="p-3.5">المبلغ</th>
                        <th class="p-3.5">طريقة الدفع</th>
                        <th class="p-3.5">بيان وسبب الاستلام</th>
                        <th class="p-3.5">المستلم</th>
                        <th class="p-3.5">حالة الخزنة</th>
                        <th class="p-3.5 text-center">طباعة الوصل A4</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-slate-700">
                    <?php if (empty($receiptsList)): ?>
                        <tr>
                            <td colspan="9" class="p-8 text-center text-slate-400">
                                لا توجد إيصالات استلام مسجلة حتى الآن. اضغط على "إصدار وصل استلام جديد" لإنشاء أول إيصال.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($receiptsList as $rec): ?>
                            <tr class="hover:bg-slate-50/70 transition">
                                <td class="p-3.5 font-mono font-bold text-brand-600">
                                    <?= htmlspecialchars($rec['receipt_number']) ?>
                                </td>
                                <td class="p-3.5 whitespace-nowrap text-slate-600 font-medium">
                                    <?= htmlspecialchars($rec['receipt_date']) ?>
                                </td>
                                <td class="p-3.5 whitespace-nowrap">
                                    <span class="font-extrabold text-slate-900 block"><?= htmlspecialchars($rec['customer_name']) ?></span>
                                    <?php if (!empty($rec['customer_phone'])): ?>
                                        <span class="text-[11px] text-slate-400 font-mono"><i class="fa-solid fa-phone text-[9px] ml-1"></i><?= htmlspecialchars($rec['customer_phone']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-3.5 whitespace-nowrap font-black text-emerald-600 text-sm">
                                    <?= formatMoney($rec['amount'], $rec['currency'] ?? $currency) ?>
                                </td>
                                <td class="p-3.5 whitespace-nowrap">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-slate-100 text-slate-700">
                                        <?= htmlspecialchars($rec['payment_method']) ?>
                                    </span>
                                </td>
                                <td class="p-3.5">
                                    <span class="font-bold text-slate-800 block"><?= htmlspecialchars($rec['purpose']) ?></span>
                                    <?php if (!empty($rec['notes'])): ?>
                                        <span class="text-[11px] text-slate-400 block mt-0.5"><?= htmlspecialchars($rec['notes']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-3.5 whitespace-nowrap">
                                    <span class="font-bold text-indigo-700 bg-indigo-50 px-2 py-0.5 rounded-lg text-[11px]">
                                        <i class="fa-solid fa-user-check ml-1"></i><?= htmlspecialchars($rec['receiver_name'] ?? 'المستلم') ?>
                                    </span>
                                </td>
                                <td class="p-3.5 whitespace-nowrap">
                                    <?php if (!empty($rec['safe_transaction_id'])): ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800">
                                            <i class="fa-solid fa-check ml-1"></i> تم الإيداع بالخزنة
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-100 text-slate-600">
                                            خارجي
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-3.5 whitespace-nowrap text-center">
                                    <div class="flex items-center justify-center space-x-1.5 space-x-reverse">
                                        <a href="receipt_print.php?id=<?= $rec['id'] ?>" target="_blank" class="inline-flex items-center bg-brand-600 hover:bg-brand-700 text-white font-bold px-3 py-1.5 rounded-xl text-xs transition shadow-xs" title="طباعة 3 وصولات في ورقة A4">
                                            <i class="fa-solid fa-print ml-1.5"></i> طباعة A4
                                        </a>
                                        <a href="<?= htmlspecialchars(getReceiptPublicUrl($rec)) ?>" target="_blank" class="inline-flex items-center bg-indigo-50 hover:bg-indigo-100 text-indigo-700 border border-indigo-200 font-bold px-2.5 py-1.5 rounded-xl text-xs transition" title="عرض الفاتورة الإلكترونية عبر QR والرابط المباشر">
                                            <i class="fa-solid fa-qrcode ml-1 text-xs"></i> أونلاين
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

<!-- CREATE RECEIPT MODAL -->
<div id="create-receipt-modal" class="modal-container fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/60 backdrop-blur-xs p-4 overflow-y-auto">
    <div class="modal-backdrop fixed inset-0"></div>
    <div class="relative bg-white rounded-3xl shadow-2xl max-w-lg w-full p-6 sm:p-8 modal-animate-enter z-10 border border-slate-100">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-5">
            <div class="flex items-center space-x-2 space-x-reverse">
                <div class="w-9 h-9 rounded-xl bg-emerald-100 text-emerald-600 flex items-center justify-center font-bold">
                    <i class="fa-solid fa-hand-holding-dollar"></i>
                </div>
                <div>
                    <h3 class="font-black text-slate-800 text-lg">إصدار وصل استلام نقدية</h3>
                    <p class="text-xs text-slate-400">سند قبض فوري برقم ID غير قابل للتغيير</p>
                </div>
            </div>
            <button onclick="closeModal('create-receipt-modal')" class="text-slate-400 hover:text-slate-600 text-lg">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form action="receipts.php" method="POST" class="space-y-4">
            <?= getCSRFInput() ?>
            <input type="hidden" name="action" value="create_receipt">

            <!-- Logged in Receiver info alert -->
            <div class="p-3 bg-indigo-50/80 rounded-2xl border border-indigo-100 flex items-center justify-between text-xs">
                <div>
                    <span class="text-slate-500 font-bold">المستلم المسؤول:</span>
                    <span class="font-black text-indigo-700 mr-1"><?= htmlspecialchars($currentUser['name']) ?></span>
                </div>
                <span class="text-[11px] text-slate-400 font-mono">الرقم المتوقع: <?= generateNextReceiptNumber($activeCompanyId) ?></span>
            </div>

            <!-- Amount & Currency Input -->
            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">المبلغ المستلم والعملة <span class="text-red-500">*</span></label>
                <div class="flex gap-2">
                    <div class="relative flex-1">
                        <input type="number" step="0.01" min="0.01" id="rec_amount" name="amount" required placeholder="500.00" oninput="previewAmountWords()" class="w-full text-2xl font-black bg-slate-50 border border-slate-300 rounded-xl px-4 py-2 text-emerald-600 focus:bg-white focus:ring-2 focus:ring-emerald-500 focus:outline-none">
                    </div>
                    <div class="w-40 shrink-0">
                        <select id="rec_currency" name="currency" onchange="previewAmountWords()" class="w-full h-full text-xs font-bold bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-slate-800 focus:bg-white focus:ring-2 focus:ring-emerald-500 focus:outline-none">
                            <option value="ج.م" <?= $currency === 'ج.م' ? 'selected' : '' ?>>ج.م (جنيه مصري)</option>
                            <option value="$" <?= $currency === '$' ? 'selected' : '' ?>>$ (دولار أمريكي)</option>
                            <option value="ر.س" <?= $currency === 'ر.س' ? 'selected' : '' ?>>ر.س (ريال سعودي)</option>
                            <option value="€" <?= $currency === '€' ? 'selected' : '' ?>>€ (يورو)</option>
                            <option value="د.إ" <?= $currency === 'د.إ' ? 'selected' : '' ?>>د.إ (درهم إماراتي)</option>
                            <option value="د.ك" <?= $currency === 'د.ك' ? 'selected' : '' ?>>د.ك (دينار كويتي)</option>
                        </select>
                    </div>
                </div>
                <div class="mt-2 p-2.5 bg-emerald-50/70 border border-emerald-200/60 rounded-xl flex items-center space-x-2 space-x-reverse text-xs">
                    <i class="fa-solid fa-spell-check text-emerald-600"></i>
                    <span class="text-slate-500 font-bold">التفقيط:</span>
                    <span id="rec_words_preview" class="text-emerald-800 font-black">أدخل المبلغ لعرض التفقيط</span>
                </div>
            </div>

            <!-- Customer Name & Phone -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">اسم العميل / المستلم منه <span class="text-red-500">*</span></label>
                    <input type="text" name="customer_name" required placeholder="مثال: محمد السيد، أو شركة الأمل..." class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">رقم هاتف العميل</label>
                    <input type="text" name="customer_phone" placeholder="010xxxxxxxx" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs font-mono focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none">
                </div>
            </div>

            <!-- Purpose & Payment Method -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">طريقة الدفع</label>
                    <select name="payment_method" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none">
                        <option value="نقداً كاش">نقداً كاش</option>
                        <option value="فودافون كاش / إنستاباي">فودافون كاش / إنستاباي</option>
                        <option value="تحويل بنكي">تحويل بنكي</option>
                        <option value="شيك">شيك مصرفي</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">تاريخ الاستلام</label>
                    <input type="date" name="receipt_date" value="<?= date('Y-m-d') ?>" required class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none">
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">بيان وسبب الاستلام (وذلك عن) <span class="text-red-500">*</span></label>
                <input type="text" name="purpose" required placeholder="مثال: دفعة تحت الحساب، حجز بضاعة، قسط شهر 10..." class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none">
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">تفاصيل أو ملاحظات إضافية</label>
                <textarea name="notes" rows="2" placeholder="اختياري..." class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none"></textarea>
            </div>

            <!-- Safe deposit toggle -->
            <?php if (hasModule('treasury')): ?>
                <div class="p-3 bg-emerald-50 rounded-xl border border-emerald-200 flex items-center space-x-2 space-x-reverse">
                    <input type="checkbox" id="deposit_to_safe" name="deposit_to_safe" value="1" checked class="rounded text-emerald-600 focus:ring-emerald-500 w-4 h-4">
                    <label for="deposit_to_safe" class="text-xs font-bold text-emerald-900 cursor-pointer">
                        إيداع المبلغ في الخزنة تلقائياً وتحديث الرصيد
                    </label>
                </div>
            <?php endif; ?>

            <div class="flex items-center space-x-2 space-x-reverse pt-2">
                <button type="submit" class="flex-1 bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-2.5 px-4 rounded-xl shadow-md transition text-xs">
                    تأكيد وإصدار الوصل والطباعة فوراً
                </button>
                <button type="button" onclick="closeModal('create-receipt-modal')" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold py-2.5 px-4 rounded-xl transition text-xs">
                    إلغاء
                </button>
            </div>
        </form>
    </div>
</div>

<!-- COMPANY LOGO UPLOAD MODAL -->
<div id="company-logo-modal" class="modal-container fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/60 backdrop-blur-xs p-4 overflow-y-auto">
    <div class="modal-backdrop fixed inset-0"></div>
    <div class="relative bg-white rounded-3xl shadow-2xl max-w-md w-full p-6 modal-animate-enter z-10 border border-slate-100">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-4">
            <h3 class="font-black text-slate-800 text-lg">شعار المقر والشركة للطباعة</h3>
            <button onclick="closeModal('company-logo-modal')" class="text-slate-400 hover:text-slate-600 text-lg">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form action="receipts.php" method="POST" enctype="multipart/form-data" class="space-y-4">
            <?= getCSRFInput() ?>
            <input type="hidden" name="action" value="upload_logo">

            <div class="text-center p-4 border-2 border-dashed border-slate-300 rounded-2xl bg-slate-50">
                <?php if (!empty($company['logo']) && file_exists(APP_PATH . '/' . $company['logo'])): ?>
                    <img src="<?= htmlspecialchars($company['logo']) ?>" alt="الشعار الحالي" class="h-20 max-w-full mx-auto object-contain mb-3">
                    <p class="text-xs text-slate-500 font-bold mb-2">الشعار الحالي محفوظ</p>
                <?php else: ?>
                    <i class="fa-solid fa-cloud-arrow-up text-3xl text-slate-400 mb-2"></i>
                    <p class="text-xs text-slate-500 font-bold mb-1">اختر صورة الشعار من جهازك</p>
                <?php endif; ?>
                <input type="file" name="company_logo" accept="image/*" required class="text-xs text-slate-500 mx-auto">
                <p class="text-[11px] text-slate-400 mt-2">الصيغ المدعومة: PNG, JPG, WEBP, SVG بحجم حتى 5MB</p>
            </div>

            <div class="flex items-center space-x-2 space-x-reverse pt-2">
                <button type="submit" class="flex-1 bg-brand-600 hover:bg-brand-700 text-white font-bold py-2.5 px-4 rounded-xl shadow-md transition text-xs">
                    حفظ وتحديث الشعار
                </button>
                <button type="button" onclick="closeModal('company-logo-modal')" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold py-2.5 px-4 rounded-xl transition text-xs">
                    إلغاء
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function tafqeetJS(num, currency) {
    num = parseFloat(num);
    if (isNaN(num) || num <= 0) return '';

    const parts = num.toFixed(2).split('.');
    const intPart = parseInt(parts[0], 10);
    const fraction = parseInt(parts[1], 10);

    let mainUnit = 'جنيه مصري';
    let subUnit = 'قرشاً';

    if (currency === '$' || currency === 'USD') {
        mainUnit = 'دولار أمريكي';
        subUnit = 'سنتاً';
    } else if (currency === 'ر.س' || currency === 'SAR') {
        mainUnit = 'ريال سعودي';
        subUnit = 'هللة';
    } else if (currency === '€' || currency === 'EUR') {
        mainUnit = 'يورو';
        subUnit = 'سنتاً';
    } else if (currency === 'د.إ' || currency === 'AED') {
        mainUnit = 'درهم إماراتي';
        subUnit = 'فلساً';
    } else if (currency === 'د.ك' || currency === 'KWD') {
        mainUnit = 'دينار كويتي';
        subUnit = 'فلساً';
    }

    const ones = ["", "واحد", "اثنان", "ثلاثة", "أربعة", "خمسة", "ستة", "سبعة", "ثمانية", "تسعة", "عشرة",
        "أحد عشر", "اثنا عشر", "ثلاثة عشر", "أربعة عشر", "خمسة عشر", "ستة عشر", "سبعة عشر", "ثمانية عشر", "تسعة عشر"];
    const tens = ["", "", "عشرون", "ثلاثون", "أربعون", "خمسون", "ستون", "سبعون", "ثمانون", "تسعون"];
    const hundreds = ["", "مائة", "مائتان", "ثلاثمائة", "أربعمائة", "خمسمائة", "ستمائة", "سبعمائة", "ثمانمائة", "تسعمائة"];

    function convert3Digits(n) {
        let res = [];
        let h = Math.floor(n / 100);
        let rem = n % 100;
        if (h > 0) res.push(hundreds[h]);
        if (rem > 0) {
            if (rem < 20) {
                res.push(ones[rem]);
            } else {
                let unit = rem % 10;
                let ten = Math.floor(rem / 10);
                if (unit > 0) {
                    res.push(ones[unit] + " و" + tens[ten]);
                } else {
                    res.push(tens[ten]);
                }
            }
        }
        return res.join(" و");
    }

    let text = "";
    if (intPart === 0) {
        text = "صفر";
    } else {
        let res = [];
        let mil = Math.floor(intPart / 1000000);
        let remMil = intPart % 1000000;
        if (mil > 0) {
            if (mil === 1) res.push("مليون");
            else if (mil === 2) res.push("مليونان");
            else if (mil >= 3 && mil <= 10) res.push(convert3Digits(mil) + " ملايين");
            else res.push(convert3Digits(mil) + " مليون");
        }
        let th = Math.floor(remMil / 1000);
        let remTh = remMil % 1000;
        if (th > 0) {
            if (th === 1) res.push("ألف");
            else if (th === 2) res.push("ألفان");
            else if (th >= 3 && th <= 10) res.push(convert3Digits(th) + " آلاف");
            else res.push(convert3Digits(th) + " ألف");
        }
        if (remTh > 0) {
            res.push(convert3Digits(remTh));
        }
        text = res.join(" و");
    }

    let out = "فقط " + text + " " + mainUnit;
    if (fraction > 0) {
        out += " و" + convert3Digits(fraction) + " " + subUnit;
    }
    out += " لا غير";
    return out;
}

function previewAmountWords() {
    const amtInput = document.getElementById('rec_amount');
    const currInput = document.getElementById('rec_currency');
    const p = document.getElementById('rec_words_preview');
    if (!amtInput || !p) return;

    const val = parseFloat(amtInput.value);
    const curr = currInput ? currInput.value : 'ج.م';

    if (isNaN(val) || val <= 0) {
        p.innerText = 'أدخل المبلغ لعرض التفقيط';
        return;
    }

    p.innerText = tafqeetJS(val, curr);
}
</script>

<?php include_once __DIR__ . '/includes/footer.php'; ?>
