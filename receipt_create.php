<?php
require_once __DIR__ . '/config/app.php';
requireLogin();
requirePermission('receipts');
requireNotViewOnly();

$currentUser = getCurrentUser();
$activeCompanyId = (int)($currentUser['active_company_id'] ?? 0);
$db = getDBConnection();

if (empty($activeCompanyId)) {
    $firstCo = $db->query("SELECT id FROM companies WHERE is_active = 1 ORDER BY id ASC LIMIT 1")->fetch();
    if ($firstCo) {
        $activeCompanyId = (int)$firstCo['id'];
        $_SESSION['impersonate_company_id'] = $activeCompanyId;
        $currentUser = getCurrentUser(true);
    } else {
        setFlash('error', 'يرجى إضافة أو تفعيل شركة أولاً.');
        header('Location: dashboard.php');
        exit;
    }
}
$currency = !empty($currentUser['currency']) ? $currentUser['currency'] : 'ج.م';
$safe = getCompanyMainSafe($activeCompanyId);

$compStmt = $db->prepare("SELECT * FROM companies WHERE id = :id LIMIT 1");
$compStmt->execute([':id' => $activeCompanyId]);
$company = $compStmt->fetch();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'خطأ في التحقق من صحة الطلب (انتهت الجلسة). يرجى المحاولة ثانية.';
    } else {
        $customerName = trim($_POST['customer_name'] ?? '');
        $customerPhone = trim($_POST['customer_phone'] ?? '');
        $projectId = !empty($_POST['project_id']) ? (int)$_POST['project_id'] : null;
        $amount = (float)($_POST['amount'] ?? 0);
        $recCurrency = trim($_POST['currency'] ?? $currency);
        $paymentMethod = trim($_POST['payment_method'] ?? 'نقداً كاش');
        $chequeNumber = trim($_POST['cheque_number'] ?? '');
        $bankName = trim($_POST['bank_name'] ?? '');
        $chequeDate = !empty($_POST['cheque_date']) ? trim($_POST['cheque_date']) : null;
        $purpose = trim($_POST['purpose'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $receiptDate = trim($_POST['receipt_date'] ?? date('Y-m-d'));
        $depositToSafe = isset($_POST['deposit_to_safe']) && $_POST['deposit_to_safe'] === '1';

        if (empty($customerName) || $amount <= 0 || empty($purpose)) {
            $error = 'يرجى إدخال اسم العميل، المبلغ، وبيان وسبب الاستلام.';
        } else {
            $amountWords = tafqeetNumber($amount, $recCurrency);
            $receiptNumber = generateNextReceiptNumber($activeCompanyId);
            $signToken = bin2hex(random_bytes(16));
            $issuerSig = !empty($currentUser['signature']) ? $currentUser['signature'] : ($company['signature'] ?? null);

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
                        $recCurrency,
                        $projectId
                    );
                    $safeTxId = $txResult['transaction_id'];
                }

                // Insert receipt record with signing token & issuer signature
                $insRec = $db->prepare("
                    INSERT INTO receipts (
                        receipt_number, company_id, user_id, safe_transaction_id, project_id,
                        customer_name, customer_phone, amount, currency, amount_words, payment_method,
                        cheque_number, bank_name, cheque_date,
                        purpose, receipt_date, notes,
                        sign_token, issuer_signature, signature_status,
                        created_at
                    ) VALUES (
                        :rnum, :cid, :uid, :txid, :pid,
                        :cname, :cphone, :amt, :curr, :awords, :pmethod,
                        :cheque_no, :bank_name, :cheque_date,
                        :purp, :rdate, :notes,
                        :stok, :isig, 'pending_signature',
                        :now
                    )
                ");
                $insRec->execute([
                    ':rnum' => $receiptNumber,
                    ':cid' => $activeCompanyId,
                    ':uid' => $currentUser['id'],
                    ':txid' => $safeTxId,
                    ':pid' => $projectId,
                    ':cname' => $customerName,
                    ':cphone' => $customerPhone,
                    ':amt' => $amount,
                    ':curr' => $recCurrency,
                    ':awords' => $amountWords,
                    ':pmethod' => $paymentMethod,
                    ':cheque_no' => $chequeNumber ?: null,
                    ':bank_name' => $bankName ?: null,
                    ':cheque_date' => $chequeDate,
                    ':purp' => $purpose,
                    ':rdate' => $receiptDate,
                    ':notes' => $notes,
                    ':stok' => $signToken,
                    ':isig' => $issuerSig,
                    ':now' => date('Y-m-d H:i:s')
                ]);
                $newReceiptId = (int)$db->lastInsertId();

                $db->commit();
                setFlash('success', "تم إصدار إيصال الاستلام رقم {$receiptNumber} بنجاح! رابط التوقيع الإلكتروني جاهز للإرسال للعميل.");
                header('Location: receipt_share.php?id=' . $newReceiptId);
                exit;
            } catch (Exception $e) {
                $db->rollBack();
                $error = 'حدث خطأ أثناء حفظ الإيصال: ' . $e->getMessage();
            }
        }
    }
}

$presetProjectId = !empty($_GET['project_id']) ? (int)$_GET['project_id'] : null;
$projectsList = [];
if (hasModule('projects')) {
    $pStmt = $db->prepare("SELECT id, name, project_type, client_name, client_phone, currency FROM projects WHERE company_id = :cid ORDER BY name ASC");
    $pStmt->execute([':cid' => $activeCompanyId]);
    $projectsList = $pStmt->fetchAll();
}

$pageTitle = 'إصدار سند قبض وإيصال استلام نقدية';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
require_once __DIR__ . '/includes/sidebar.php';

$expectedReceiptNo = generateNextReceiptNumber($activeCompanyId);
?>

<div class="max-w-7xl mx-auto space-y-6 pb-24 lg:pb-12">

    <!-- Top Action Breadcrumb -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 bg-white rounded-2xl p-4 sm:p-5 border border-slate-200/80 shadow-xs">
        <div class="flex items-center space-x-3 space-x-reverse">
            <a href="receipts.php" class="w-10 h-10 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 flex items-center justify-center transition active:scale-95">
                <i class="fa-solid fa-arrow-right text-sm"></i>
            </a>
            <div>
                <h1 class="text-lg sm:text-xl font-black text-slate-900">إصدار سند قبض وإيصال استلام نقدية</h1>
                <div class="flex items-center space-x-2 space-x-reverse text-xs text-slate-500 mt-0.5">
                    <span>الشركة: <strong class="text-slate-800"><?= htmlspecialchars($company['name'] ?? '') ?></strong></span>
                    <span>•</span>
                    <span>المسؤول المستلم: <strong class="text-indigo-600"><?= htmlspecialchars($currentUser['name']) ?></strong></span>
                </div>
            </div>
        </div>

        <div class="flex items-center space-x-2 space-x-reverse">
            <a href="receipts.php" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold px-4 py-2 rounded-xl text-xs transition">
                العودة لقائمة السندات
            </a>
        </div>
    </div>

    <?php if (!empty($error)): ?>
        <div class="p-4 rounded-2xl bg-rose-50 border border-rose-200 text-rose-800 text-xs font-bold flex items-start space-x-3 space-x-reverse shadow-xs">
            <i class="fa-solid fa-circle-exclamation text-base mt-0.5 text-rose-600 shrink-0"></i>
            <div class="flex-1"><?= htmlspecialchars($error) ?></div>
        </div>
    <?php endif; ?>

    <!-- Unified Cash Voucher Type Switcher (استلام نقدية / تسليم نقدية) -->
    <div class="flex items-center justify-center">
        <div class="inline-flex p-1.5 bg-slate-200/70 rounded-2xl border border-slate-300/80 shadow-2xs">
            <span class="px-5 py-2.5 rounded-xl text-xs sm:text-sm font-black bg-indigo-600 text-white shadow-sm flex items-center">
                <i class="fa-solid fa-arrow-down-left ml-2 text-indigo-200"></i>
                استلام نقدية (سند قبض وارد)
            </span>
            <a href="voucher_create.php<?= $presetProjectId ? '?project_id=' . $presetProjectId : '' ?>" class="px-5 py-2.5 rounded-xl text-xs sm:text-sm font-bold text-slate-600 hover:text-slate-900 transition flex items-center">
                <i class="fa-solid fa-arrow-up-right ml-2 text-rose-500"></i>
                تسليم نقدية (سند صرف صادر)
            </a>
        </div>
    </div>

    <!-- Main Form Grid (Responsive 2 Columns on Desktop) -->
    <form method="POST" action="receipt_create.php" id="receiptForm">
        <?= getCSRFInput() ?>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">

            <!-- RIGHT COLUMN: 8 Columns -->
            <div class="lg:col-span-8 space-y-6">

                <!-- 1. Amount & Currency -->
                <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs space-y-4">
                    <h2 class="text-sm font-black text-slate-900 flex items-center border-b border-slate-100 pb-3">
                        <span class="w-7 h-7 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center text-xs ml-2">
                            <i class="fa-solid fa-hand-holding-dollar"></i>
                        </span>
                        المبلغ المستلم والعملة
                    </h2>

                    <div class="grid grid-cols-1 sm:grid-cols-12 gap-4">
                        <div class="sm:col-span-8">
                            <label class="block text-xs font-bold text-slate-700 mb-1.5">
                                المبلغ المستلم نقداً أو بالشيك <span class="text-red-500">*</span>
                            </label>
                            <input type="number" step="0.01" min="0.01" id="rec_amount" name="amount" required placeholder="0.00" oninput="updateReceiptWords()" class="w-full text-2xl sm:text-3xl font-black bg-slate-50 border border-slate-300 rounded-xl px-4 py-2.5 text-emerald-600 focus:bg-white focus:ring-2 focus:ring-emerald-500 focus:outline-none">
                        </div>

                        <div class="sm:col-span-4">
                            <label class="block text-xs font-bold text-slate-700 mb-1.5">العملة</label>
                            <select id="rec_currency" name="currency" onchange="updateReceiptWords()" class="w-full h-12 bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs font-black text-slate-800 focus:bg-white focus:ring-2 focus:ring-emerald-500 focus:outline-none">
                                <option value="ج.م" <?= $currency === 'ج.م' ? 'selected' : '' ?>>ج.م (جنيه مصري)</option>
                                <option value="ر.س" <?= $currency === 'ر.س' ? 'selected' : '' ?>>ر.س (ريال سعودي)</option>
                                <option value="$" <?= $currency === '$' ? 'selected' : '' ?>>$ (دولار أمريكي)</option>
                                <option value="د.إ" <?= $currency === 'د.إ' ? 'selected' : '' ?>>د.إ (درهم إماراتي)</option>
                                <option value="د.ك" <?= $currency === 'د.ك' ? 'selected' : '' ?>>د.ك (دينار كويتي)</option>
                            </select>
                        </div>
                    </div>

                    <!-- Live Tafqeet Box -->
                    <div class="p-3.5 bg-emerald-50/70 border border-emerald-200/80 rounded-xl flex items-center space-x-3 space-x-reverse text-xs">
                        <span class="w-7 h-7 rounded-lg bg-emerald-100 text-emerald-700 flex items-center justify-center text-xs shrink-0 font-bold">
                            <i class="fa-solid fa-spell-check"></i>
                        </span>
                        <div>
                            <span class="text-slate-500 font-bold block text-[11px]">المبلغ كتابة بالحروف (التفقيط المعتمد بالسند):</span>
                            <span id="rec_words_display" class="text-emerald-900 font-black text-xs sm:text-sm">أدخل المبلغ لعرض التفقيط</span>
                        </div>
                    </div>

                    <!-- Deposit to Safe Option -->
                    <div class="p-3.5 bg-slate-50 hover:bg-slate-100/80 rounded-xl border border-slate-200 transition">
                        <label class="flex items-center space-x-2.5 space-x-reverse cursor-pointer">
                            <input type="checkbox" name="deposit_to_safe" value="1" checked class="w-4 h-4 rounded text-emerald-600 focus:ring-emerald-500">
                            <div>
                                <span class="text-xs font-bold text-slate-900 block">إيداع المبلغ فوراً في خزنة الشركة العامة</span>
                                <span class="text-[11px] text-slate-500">يقيد كإيداع رسمي في حركة الخزينة وتزيد السيولة الحالية.</span>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- 2. Customer & Project Information -->
                <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs space-y-4">
                    <h2 class="text-sm font-black text-slate-900 flex items-center border-b border-slate-100 pb-3">
                        <span class="w-7 h-7 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center text-xs ml-2">
                            <i class="fa-solid fa-user-tag"></i>
                        </span>
                        بيانات العميل والمشروع
                    </h2>

                    <?php if (!empty($projectsList)): ?>
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1.5">
                                ربط بمشروع عقاري (اختياري - سداد دفعة عميل لمشروع)
                            </label>
                            <select name="project_id" id="rec_project_id" onchange="handleReceiptProjectChange(this)" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3.5 py-2.5 text-xs font-bold text-slate-800 focus:bg-white focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                                <option value="">-- بدون ربط بمشروع (سند قبض عام) --</option>
                                <?php foreach ($projectsList as $prj): ?>
                                    <option value="<?= $prj['id'] ?>" data-client="<?= htmlspecialchars($prj['client_name'] ?? '') ?>" data-phone="<?= htmlspecialchars($prj['client_phone'] ?? '') ?>" data-name="<?= htmlspecialchars($prj['name']) ?>" <?= $presetProjectId === (int)$prj['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($prj['name']) ?> <?= $prj['project_type'] === 'without_capital' ? '(بدون رأس مال - مصروفات عميل)' : '(مشروع برأس مال)' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1.5">
                                استلمنا من السيد / السادة <span class="text-red-500">*</span>
                            </label>
                            <input type="text" name="customer_name" required placeholder="مثال: شركة النور للمقاولات / محمد علي" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3.5 py-2.5 text-xs font-bold focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none">
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1.5">رقم هاتف العميل</label>
                            <input type="text" name="customer_phone" placeholder="05xxxxxxxx / 010xxxxxxxx" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3.5 py-2.5 text-xs font-mono focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none">
                        </div>
                    </div>
                </div>

                <!-- 3. Purpose & Payment Method -->
                <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs space-y-4">
                    <h2 class="text-sm font-black text-slate-900 flex items-center border-b border-slate-100 pb-3">
                        <span class="w-7 h-7 rounded-lg bg-purple-50 text-purple-600 flex items-center justify-center text-xs ml-2">
                            <i class="fa-solid fa-receipt"></i>
                        </span>
                        طريقة الاستلام وبيان المقابل
                    </h2>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1.5">طريقة الاستلام</label>
                            <select name="payment_method" id="rec_payment_method" onchange="toggleChequeFields()" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3.5 py-2.5 text-xs font-bold focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none">
                                <option value="نقداً كاش">نقداً كاش</option>
                                <option value="تحويل بنكي">تحويل بنكي</option>
                                <option value="شيك مصرفي">شيك مصرفي</option>
                                <option value="شبكة / مدى">شبكة / مدى</option>
                                <option value="إنستاباي / فودافون كاش">إنستاباي / فودافون كاش</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1.5">تاريخ السند</label>
                            <input type="date" name="receipt_date" value="<?= date('Y-m-d') ?>" required class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs font-mono font-bold focus:bg-white focus:outline-none">
                        </div>
                    </div>

                    <!-- Cheque Details (Collapsible or Adaptive) -->
                    <div id="cheque_details_box" class="p-4 bg-slate-50 rounded-xl border border-slate-200 space-y-3">
                        <span class="text-xs font-bold text-slate-700 block flex items-center">
                            <i class="fa-solid fa-money-check-dollar ml-1.5 text-rose-600"></i> بيانات الشيك أو الحوالة البنكية (تظهر في السند الرسمي):
                        </span>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <div>
                                <label class="block text-[11px] text-slate-500 mb-1">رقم الشيك / الحوالة</label>
                                <input type="text" name="cheque_number" placeholder="مثال: 948211" class="w-full bg-white border border-slate-200 rounded-lg px-2.5 py-1.5 text-xs font-mono focus:outline-none">
                            </div>
                            <div>
                                <label class="block text-[11px] text-slate-500 mb-1">على بنك</label>
                                <input type="text" name="bank_name" placeholder="مثال: بنك الراجحي / بنك مصر" class="w-full bg-white border border-slate-200 rounded-lg px-2.5 py-1.5 text-xs focus:outline-none">
                            </div>
                            <div>
                                <label class="block text-[11px] text-slate-500 mb-1">بتاريخ</label>
                                <input type="date" name="cheque_date" class="w-full bg-white border border-slate-200 rounded-lg px-2.5 py-1.5 text-xs font-mono focus:outline-none">
                            </div>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1.5">
                            وذلك مقابل (بيان المقابل والسبب) <span class="text-red-500">*</span>
                        </label>
                        <input type="text" name="purpose" required placeholder="مثال: دفعة أولى من عقد توريد / سداد فاتورة مبيعات رقم 104..." class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3.5 py-2.5 text-xs font-bold focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none">
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1.5">ملاحظات إضافية</label>
                        <textarea name="notes" rows="2" placeholder="أي شروط أو تفاصيل إضافية تظهر أسفل السند..." class="w-full bg-slate-50 border border-slate-300 rounded-xl p-3 text-xs focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none"></textarea>
                    </div>
                </div>

            </div>

            <!-- LEFT COLUMN: Summary & Actions (4 Columns Sticky) -->
            <div class="lg:col-span-4 lg:sticky lg:top-24 space-y-6">

                <!-- Summary Card -->
                <div class="bg-white rounded-2xl p-5 border-2 border-slate-900 shadow-md space-y-4">
                    <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                        <strong class="text-xs font-black text-slate-900 flex items-center">
                            <i class="fa-solid fa-file-invoice-dollar ml-1.5 text-emerald-600"></i>
                            معاينة سند القبض الرسمي
                        </strong>
                        <span class="text-[11px] font-mono text-emerald-700 bg-emerald-50 px-2.5 py-0.5 rounded-lg border border-emerald-200 font-black">
                            <?= htmlspecialchars($expectedReceiptNo) ?>
                        </span>
                    </div>

                    <div class="space-y-3 text-xs">
                        <div class="flex items-center justify-between text-slate-600">
                            <span>الشركة المصدرة:</span>
                            <strong class="text-slate-900"><?= htmlspecialchars($company['name'] ?? '') ?></strong>
                        </div>

                        <div class="flex items-center justify-between text-slate-600">
                            <span>المستلم المسؤول:</span>
                            <strong class="text-slate-900"><?= htmlspecialchars($currentUser['name']) ?></strong>
                        </div>

                        <div class="flex items-center justify-between pt-3 border-t-2 border-slate-900 text-slate-900">
                            <span class="text-sm font-black">المبلغ المقبوض:</span>
                            <div class="flex items-baseline space-x-1 space-x-reverse">
                                <strong class="font-mono text-2xl font-black text-emerald-700" id="card_amount_display">0.00</strong>
                                <span class="font-bold text-xs" id="card_currency_display"><?= htmlspecialchars($currency) ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Submit Button -->
                    <div class="pt-2 space-y-2">
                        <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-black py-3.5 px-4 rounded-xl shadow-md transition text-xs flex items-center justify-center space-x-2 space-x-reverse active:scale-98">
                            <i class="fa-solid fa-print ml-1.5 text-sm"></i>
                            <span>حفظ وإصدار السند فوراً (Print A4)</span>
                        </button>
                        
                        <a href="receipts.php" class="block text-center text-xs font-bold text-slate-500 hover:text-slate-800 py-1 transition">
                            إلغاء والعودة
                        </a>
                    </div>
                </div>

                <!-- Info Box -->
                <div class="bg-slate-50 rounded-2xl p-4 border border-slate-200 text-xs text-slate-700 space-y-2">
                    <div class="flex items-center space-x-2 space-x-reverse font-bold text-slate-900">
                        <i class="fa-solid fa-stamp text-emerald-600"></i>
                        <span>طباعة فورية معتمدة (A4 أصل وصورة)</span>
                    </div>
                    <p class="text-[11px] leading-relaxed text-slate-500">
                        عند الضغط على حفظ، سيتم توجيهك فورياً إلى نافذة الطباعة الرسمية للسند المعتمد بهوية وألوان شركتك وشعارها.
                    </p>
                </div>

            </div>

        </div>

        <!-- Mobile Floating Sticky Dock (Visible only on mobile screens < lg) -->
        <div class="lg:hidden fixed bottom-0 inset-x-0 bg-white/95 backdrop-blur-md border-t border-slate-200 shadow-2xl z-50 p-3.5 safe-area-bottom">
            <div class="flex items-center justify-between gap-3 max-w-lg mx-auto">
                <div>
                    <span class="text-[10px] font-bold text-slate-400 block">المبلغ المقبوض:</span>
                    <div class="flex items-baseline space-x-1 space-x-reverse">
                        <span id="dock_rec_amount" class="text-xl font-black text-emerald-700 font-mono">0.00</span>
                        <span id="dock_rec_curr" class="text-xs font-black text-slate-700"><?= htmlspecialchars($currency) ?></span>
                    </div>
                </div>
                <button type="submit" class="flex-1 bg-emerald-600 hover:bg-emerald-700 text-white font-black py-3 px-4 rounded-xl shadow-md transition text-xs flex items-center justify-center space-x-1.5 space-x-reverse active:scale-95">
                    <i class="fa-solid fa-print ml-1"></i>
                    <span>حفظ وطباعة السند</span>
                </button>
            </div>
        </div>

    </form>

</div>

<script>
    function updateReceiptWords() {
        const amtInput = document.getElementById('rec_amount');
        const curSelect = document.getElementById('rec_currency');
        const amt = parseFloat(amtInput ? amtInput.value : 0) || 0;
        const cur = curSelect ? curSelect.value : 'ج.م';

        const dispAmt = document.getElementById('card_amount_display');
        const dispCur = document.getElementById('card_currency_display');
        const dockAmt = document.getElementById('dock_rec_amount');
        const dockCur = document.getElementById('dock_rec_curr');

        if (dispAmt) dispAmt.innerText = amt.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        if (dispCur) dispCur.innerText = cur;
        if (dockAmt) dockAmt.innerText = amt.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        if (dockCur) dockCur.innerText = cur;

        const wordsLabel = document.getElementById('rec_words_display');
        if (wordsLabel) {
            if (amt > 0) {
                wordsLabel.innerText = 'جاري احتساب التفقيط...';
                fetch('api/tafqeet.php?amount=' + encodeURIComponent(amt) + '&currency=' + encodeURIComponent(cur))
                    .then(res => res.json())
                    .then(data => {
                        if (data && data.words) {
                            wordsLabel.innerText = data.words;
                        } else {
                            wordsLabel.innerText = amt.toLocaleString() + ' ' + cur;
                        }
                    })
                    .catch(() => {
                        wordsLabel.innerText = amt.toLocaleString() + ' ' + cur;
                    });
            } else {
                wordsLabel.innerText = 'أدخل المبلغ لعرض التفقيط';
            }
        }
    }

    function toggleChequeFields() {
        const method = document.getElementById('rec_payment_method').value;
        const box = document.getElementById('cheque_details_box');
        if (box) {
            if (method.includes('شيك') || method.includes('تحويل')) {
                box.classList.remove('opacity-60');
            } else {
                box.classList.add('opacity-60');
            }
        }
    }

    function handleReceiptProjectChange(select) {
        const opt = select.options[select.selectedIndex];
        if (!opt || !opt.value) return;
        const client = opt.getAttribute('data-client');
        const phone = opt.getAttribute('data-phone');
        const prjName = opt.getAttribute('data-name');
        const nameInput = document.querySelector('input[name="customer_name"]');
        const phoneInput = document.querySelector('input[name="customer_phone"]');
        const purposeInput = document.querySelector('input[name="purpose"]');
        if (client && nameInput && !nameInput.value) {
            nameInput.value = client;
        }
        if (phone && phoneInput && !phoneInput.value) {
            phoneInput.value = phone;
        }
        if (prjName && purposeInput && !purposeInput.value) {
            purposeInput.value = 'دفعة سداد لحساب مشروع: ' + prjName;
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        updateReceiptWords();
        toggleChequeFields();
    });
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
