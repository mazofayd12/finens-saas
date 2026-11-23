<?php
require_once __DIR__ . '/config/app.php';
requireLogin();

$currentUser = getCurrentUser();
$activeCompanyId = (int)($currentUser['active_company_id'] ?? 0);
$db = getDBConnection();

$voucherId = (int)($_GET['id'] ?? 0);
if ($voucherId <= 0) {
    header('Location: project_vouchers.php');
    exit;
}

$whereClause = isSuperAdmin() ? "WHERE e.id = :id" : "WHERE e.id = :id AND e.company_id = :cid";
$params = isSuperAdmin() ? [':id' => $voucherId] : [':id' => $voucherId, ':cid' => $activeCompanyId];

$stmt = $db->prepare("
    SELECT e.*, 
           c.name as company_name, c.logo as company_logo, c.phone as company_phone, c.signature as company_sig,
           u.name as issuer_name, u.signature as user_sig,
           p.name as project_name,
           s.name as safe_name
    FROM expenses e
    JOIN companies c ON e.company_id = c.id
    LEFT JOIN users u ON e.user_id = u.id
    LEFT JOIN projects p ON e.project_id = p.id
    LEFT JOIN safes s ON e.safe_id = s.id
    {$whereClause}
    LIMIT 1
");
$stmt->execute($params);
$voucher = $stmt->fetch();

if (!$voucher) {
    setFlash('error', 'سند الصرف المطلوب غير موجود.');
    header('Location: project_vouchers.php');
    exit;
}

// Generate token if somehow missing
if (empty($voucher['sign_token'])) {
    $newToken = bin2hex(random_bytes(16));
    $db->prepare("UPDATE expenses SET sign_token = :tok WHERE id = :id")->execute([':tok' => $newToken, ':id' => $voucherId]);
    $voucher['sign_token'] = $newToken;
}

$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http');
$host = $_SERVER['HTTP_HOST'];
$baseDir = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
$signUrl = "{$protocol}://{$host}{$baseDir}/voucher_sign.php?token=" . urlencode($voucher['sign_token']);

// Clean phone for WhatsApp
$rawPhone = $voucher['beneficiary_phone'] ?? '';
$cleanPhone = preg_replace('/[^0-9]/', '', $rawPhone);
if (strlen($cleanPhone) === 11 && strpos($cleanPhone, '01') === 0) {
    $cleanPhone = '2' . $cleanPhone;
} elseif (strlen($cleanPhone) === 10 && strpos($cleanPhone, '05') === 0) {
    $cleanPhone = '966' . substr($cleanPhone, 1);
}

$voucherCurrency = !empty($voucher['currency']) ? $voucher['currency'] : 'ج.م';
$voucherNo = $voucher['voucher_number'] ?: ('VCH-' . $voucher['id']);

$waMsg = "مرحباً {$voucher['beneficiary_name']}،\nتم إصدار سند صرف مالي رقم ({$voucherNo}) بمبلغ (" . formatMoney($voucher['amount'], $voucherCurrency) . ") لحسابكم طرف {$voucher['company_name']}.\n\nيرجى التكرم بفتح الرابط التالي وتأكيد الاستلام عبر التوقيع الإلكتروني المعتمد:\n{$signUrl}\n\nشكراً لتعاونكم.";
$waUrl = "https://api.whatsapp.com/send?" . (!empty($cleanPhone) ? "phone={$cleanPhone}&" : "") . "text=" . urlencode($waMsg);

$fromProject = !empty($_GET['from_project']) ? (int)$_GET['from_project'] : (!empty($voucher['project_id']) ? (int)$voucher['project_id'] : null);

$pageTitle = 'مشاركة ورابط توقيع سند صرف ' . htmlspecialchars($voucherNo);
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<div class="max-w-3xl mx-auto space-y-6 pb-14">

    <!-- Back Navigation -->
    <div class="flex items-center justify-between text-xs font-bold text-slate-500">
        <?php if ($fromProject): ?>
            <a href="project_view.php?id=<?= $fromProject ?>" class="hover:text-rose-600 transition flex items-center">
                <i class="fa-solid fa-arrow-right ml-1.5"></i>
                العودة لصفحة المشروع
            </a>
        <?php else: ?>
            <a href="project_vouchers.php" class="hover:text-rose-600 transition flex items-center">
                <i class="fa-solid fa-arrow-right ml-1.5"></i>
                العودة لسجل سندات الصرف
            </a>
        <?php endif; ?>

        <a href="voucher_create.php<?= $fromProject ? '?project_id=' . $fromProject : '' ?>" class="hover:text-rose-600 transition flex items-center text-rose-600 font-black">
            <i class="fa-solid fa-plus-circle ml-1.5"></i>
            إصدار سند صرف آخر
        </a>
    </div>

    <!-- Success Card -->
    <div class="bg-gradient-to-l from-slate-900 via-rose-950 to-slate-900 rounded-3xl p-6 sm:p-8 text-white shadow-xl relative overflow-hidden text-center sm:text-right">
        <div class="absolute -top-12 -left-12 w-48 h-48 bg-rose-500/10 rounded-full blur-3xl pointer-events-none"></div>

        <div class="relative z-10 flex flex-col sm:flex-row sm:items-center justify-between gap-5">
            <div class="flex items-center space-x-3.5 space-x-reverse">
                <div class="w-14 h-14 rounded-2xl bg-rose-500/20 text-rose-400 border border-rose-400/30 flex items-center justify-center text-2xl font-bold shrink-0">
                    <i class="fa-solid fa-file-invoice-dollar"></i>
                </div>
                <div>
                    <span class="text-xs font-bold text-rose-400 uppercase tracking-wider block mb-1">تم إصدار سند الصرف بنجاح</span>
                    <h1 class="text-xl sm:text-2xl font-black text-white">
                        سند صرف رقم: <span class="font-mono text-amber-300"><?= htmlspecialchars($voucherNo) ?></span>
                    </h1>
                    <p class="text-xs text-slate-300 mt-1">
                        بمبلغ: <strong class="text-white font-mono text-sm"><?= formatMoney($voucher['amount'], $voucherCurrency) ?></strong>
                        | للمستفيد: <strong class="text-white"><?= htmlspecialchars($voucher['beneficiary_name']) ?></strong>
                    </p>
                </div>
            </div>

            <!-- Dynamic Live Status Badge -->
            <div id="status-container" class="shrink-0 text-center">
                <?php if ($voucher['signature_status'] === 'signed'): ?>
                    <div class="inline-flex items-center px-4 py-2 rounded-2xl text-xs font-black bg-emerald-500/20 text-emerald-300 border border-emerald-400/30 shadow-xs">
                        <i class="fa-solid fa-circle-check ml-1.5 text-emerald-400 text-sm"></i>
                        <span>تم التوقيع والاستلام ✓</span>
                    </div>
                <?php else: ?>
                    <div class="inline-flex items-center px-4 py-2 rounded-2xl text-xs font-bold bg-amber-500/20 text-amber-300 border border-amber-400/30 shadow-xs">
                        <span class="w-2.5 h-2.5 rounded-full bg-amber-400 animate-ping ml-2"></span>
                        <span id="status-text">في انتظار توقيع المستلم...</span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- MAIN SIGNING LINK & WHATSAPP SHARE CARD -->
    <div class="bg-white rounded-3xl p-6 sm:p-7 border border-slate-200/90 shadow-sm space-y-5">
        <div class="flex items-center space-x-2.5 space-x-reverse pb-3 border-b border-slate-100">
            <span class="w-8 h-8 rounded-xl bg-rose-50 text-rose-600 flex items-center justify-center font-bold text-sm">
                <i class="fa-solid fa-link"></i>
            </span>
            <div>
                <h2 class="text-sm font-black text-slate-900">رابط التوقيع والاستلام الإلكتروني للمستلم</h2>
                <p class="text-[11px] text-slate-400">أرسل هذا الرابط للشخص أو الجهة المستلمة ليفتح شاشة التوقيع المباشرة ويؤكد استلام المبلغ</p>
            </div>
        </div>

        <!-- Copyable Link Bar -->
        <div class="space-y-2">
            <label class="block text-xs font-bold text-slate-700">رابط التوقيع المباشر لسند الصرف (جاهز للمشاركة):</label>
            <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2">
                <div class="relative flex-1">
                    <input type="text" id="signing_url_input" readonly value="<?= htmlspecialchars($signUrl) ?>" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3.5 py-2.5 text-xs font-mono text-slate-800 focus:outline-none focus:ring-2 focus:ring-rose-500 select-all font-bold">
                    <button type="button" onclick="copySignLink()" class="absolute left-1.5 top-1.5 bg-slate-200 hover:bg-slate-300 text-slate-700 text-xs font-bold px-3 py-1.5 rounded-lg transition flex items-center">
                        <i class="fa-solid fa-copy ml-1"></i>
                        <span id="copy_btn_text">نسخ الرابط</span>
                    </button>
                </div>

                <a href="<?= htmlspecialchars($waUrl) ?>" target="_blank" class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold px-5 py-2.5 rounded-xl text-xs transition shadow-md shadow-emerald-600/20 flex items-center justify-center shrink-0">
                    <i class="fa-brands fa-whatsapp text-base ml-1.5"></i>
                    إرسال واتساب للمستفيد
                </a>
            </div>
        </div>

        <!-- Action Shortcuts -->
        <div class="pt-2 flex flex-wrap items-center gap-2.5 text-xs">
            <!-- Preview Recipient Page -->
            <a href="<?= htmlspecialchars($signUrl) ?>" target="_blank" class="bg-slate-100 hover:bg-slate-200 text-slate-800 font-bold px-4 py-2.5 rounded-xl transition flex items-center">
                <i class="fa-solid fa-arrow-up-right-from-square ml-1.5 text-slate-500"></i>
                معاينة شاشة توقيع المستلم
            </a>

            <!-- Print A4 Receipt -->
            <a href="project_voucher_print.php?id=<?= $voucherId ?>" target="_blank" class="bg-slate-900 hover:bg-slate-800 text-white font-bold px-4 py-2.5 rounded-xl transition shadow-xs flex items-center">
                <i class="fa-solid fa-print ml-1.5"></i>
                معاينة سند الصرف A4 الرسمي
            </a>
        </div>
    </div>

    <!-- Signatures Live Status Box -->
    <div class="bg-white rounded-3xl p-6 border border-slate-200/90 shadow-sm space-y-4">
        <h3 class="font-black text-slate-900 text-sm flex items-center">
            <i class="fa-solid fa-file-signature ml-2 text-rose-600"></i>
            حالة التوقيعات المعتمدة على سند الصرف:
        </h3>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <!-- 1. Issuer Signature (توقيع المحاسب / مصدر الصرف) -->
            <div class="bg-slate-50 border border-slate-200 rounded-2xl p-4 text-center space-y-2">
                <div class="flex items-center justify-between text-xs">
                    <span class="font-bold text-slate-600">1. توقيع المحاسب / مُصدر السند:</span>
                    <span class="text-emerald-700 font-bold text-[11px] bg-emerald-50 px-2 py-0.5 rounded border border-emerald-200">
                        معتمد من لوحة التحكم
                    </span>
                </div>
                <div class="h-28 bg-white border border-slate-200 rounded-xl flex items-center justify-center p-2">
                    <?php 
                    $issuerSigImg = !empty($voucher['issuer_signature']) ? $voucher['issuer_signature'] : (!empty($voucher['user_sig']) ? $voucher['user_sig'] : (!empty($voucher['company_sig']) ? $voucher['company_sig'] : null));
                    ?>
                    <?php if (!empty($issuerSigImg) && file_exists(APP_PATH . '/' . $issuerSigImg)): ?>
                        <img src="<?= htmlspecialchars($issuerSigImg) ?>" alt="توقيع مصدر الصرف" class="max-h-full max-w-full object-contain">
                    <?php else: ?>
                        <div class="text-xs text-slate-400 font-bold">
                            <?= htmlspecialchars($voucher['issuer_name'] ?: 'المحاسب المسؤول') ?>
                        </div>
                    <?php endif; ?>
                </div>
                <span class="text-[11px] text-slate-500 font-bold block">
                    مصدر الصرف: <?= htmlspecialchars($voucher['issuer_name'] ?: 'المحاسب') ?>
                </span>
            </div>

            <!-- 2. Recipient Electronic Signature (توقيع المستلم / المستفيد) -->
            <div id="recipient-sig-card" class="bg-slate-50 border border-slate-200 rounded-2xl p-4 text-center space-y-2">
                <div class="flex items-center justify-between text-xs">
                    <span class="font-bold text-slate-600">2. توقيع مستلم المبلغ (المستفيد):</span>
                    <span id="recipient-badge" class="<?= $voucher['signature_status'] === 'signed' ? 'text-emerald-700 bg-emerald-50 border border-emerald-200' : 'text-amber-700 bg-amber-50 border border-amber-200' ?> font-bold text-[11px] px-2 py-0.5 rounded">
                        <?= $voucher['signature_status'] === 'signed' ? 'تم التوقيع والاستلام ✓' : 'في انتظار التوقيع...' ?>
                    </span>
                </div>
                <div id="recipient-sig-preview" class="h-28 bg-white border border-slate-200 rounded-xl flex items-center justify-center p-2">
                    <?php if ($voucher['signature_status'] === 'signed' && !empty($voucher['recipient_signature'])): ?>
                        <?php if (strpos($voucher['recipient_signature'], 'data:image/') === 0): ?>
                            <img src="<?= $voucher['recipient_signature'] ?>" alt="توقيع المستلم" class="max-h-full max-w-full object-contain">
                        <?php elseif (file_exists(APP_PATH . '/' . $voucher['recipient_signature'])): ?>
                            <img src="<?= htmlspecialchars($voucher['recipient_signature']) ?>" alt="توقيع المستلم" class="max-h-full max-w-full object-contain">
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="text-slate-400 text-center">
                            <i class="fa-solid fa-pen-clip text-2xl text-slate-300 mb-1 block"></i>
                            <span class="text-xs font-bold block">في انتظار فتح الرابط وتوقيع المستلم</span>
                            <span class="text-[10px] text-slate-400 font-mono">سيتحدث تلقائياً فور توقيعه</span>
                        </div>
                    <?php endif; ?>
                </div>
                <span id="signed-meta" class="text-[11px] text-slate-500 font-bold block">
                    <?php if ($voucher['signature_status'] === 'signed' && !empty($voucher['signed_at'])): ?>
                        تاريخ الاستلام: <?= date('Y-m-d H:i', strtotime($voucher['signed_at'])) ?>
                    <?php else: ?>
                        المستفيد: <?= htmlspecialchars($voucher['beneficiary_name']) ?>
                    <?php endif; ?>
                </span>
            </div>
        </div>
    </div>

    <!-- Voucher Details Card -->
    <div class="bg-white rounded-3xl p-6 border border-slate-200/90 shadow-sm space-y-3">
        <h4 class="font-black text-slate-900 text-xs uppercase tracking-wider text-slate-400 block mb-2">بيانات وتفاصيل سند الصرف:</h4>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-xs">
            <div class="bg-slate-50 p-3 rounded-xl border border-slate-100">
                <span class="text-slate-400 block text-[10px] font-bold">تاريخ الصرف:</span>
                <strong class="text-slate-800 font-mono"><?= htmlspecialchars($voucher['expense_date']) ?></strong>
            </div>
            <div class="bg-slate-50 p-3 rounded-xl border border-slate-100">
                <span class="text-slate-400 block text-[10px] font-bold">طريقة الصرف:</span>
                <strong class="text-slate-800"><?= htmlspecialchars($voucher['payment_method'] ?: 'نقداً كاش') ?></strong>
            </div>
            <div class="bg-slate-50 p-3 rounded-xl border border-slate-100">
                <span class="text-slate-400 block text-[10px] font-bold">هاتف المستفيد:</span>
                <strong class="text-slate-800 font-mono"><?= htmlspecialchars($voucher['beneficiary_phone'] ?: '-') ?></strong>
            </div>
            <div class="bg-slate-50 p-3 rounded-xl border border-slate-100">
                <span class="text-slate-400 block text-[10px] font-bold">الخزنة:</span>
                <strong class="text-slate-800"><?= htmlspecialchars($voucher['safe_name'] ?: 'الرئيسية') ?></strong>
            </div>
        </div>
        <div class="p-3 bg-slate-50 rounded-xl border border-slate-100 text-xs">
            <span class="text-slate-400 block text-[10px] font-bold mb-0.5">البيان وسبب الصرف:</span>
            <span class="text-slate-800 font-bold"><?= htmlspecialchars($voucher['title']) ?></span>
            <?php if (!empty($voucher['description']) && $voucher['description'] !== $voucher['title']): ?>
                <span class="text-slate-500 block text-[11px] mt-0.5"><?= htmlspecialchars($voucher['description']) ?></span>
            <?php endif; ?>
            <?php if (!empty($voucher['project_name'])): ?>
                <span class="text-indigo-600 font-bold block text-[11px] mt-1"><i class="fa-solid fa-city ml-1"></i> المشروع: <?= htmlspecialchars($voucher['project_name']) ?></span>
            <?php endif; ?>
        </div>
    </div>

</div>

<script>
function copySignLink() {
    const input = document.getElementById('signing_url_input');
    input.select();
    input.setSelectionRange(0, 99999);

    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(input.value).then(showCopiedFeedback);
    } else {
        try {
            document.execCommand('copy');
            showCopiedFeedback();
        } catch (err) {}
    }
}

function showCopiedFeedback() {
    const btnText = document.getElementById('copy_btn_text');
    if (btnText) {
        const old = btnText.innerText;
        btnText.innerText = 'تم النسخ بنجاح!';
        setTimeout(() => {
            btnText.innerText = old;
        }, 2500);
    }
}

// Live Polling: Check if beneficiary has signed without page reload
let currentStatus = '<?= $voucher['signature_status'] ?>';
const signToken = '<?= $voucher['sign_token'] ?>';

if (currentStatus !== 'signed') {
    const pollInterval = setInterval(() => {
        fetch('voucher_sign.php?token=' + encodeURIComponent(signToken) + '&check_status=1')
            .then(res => res.json())
            .then(data => {
                if (data && data.status === 'signed') {
                    clearInterval(pollInterval);
                    currentStatus = 'signed';
                    // Update UI dynamically
                    const statusContainer = document.getElementById('status-container');
                    if (statusContainer) {
                        statusContainer.innerHTML = `
                            <div class="inline-flex items-center px-4 py-2 rounded-2xl text-xs font-black bg-emerald-500/20 text-emerald-300 border border-emerald-400/30 animate-bounce">
                                <i class="fa-solid fa-circle-check ml-1.5 text-emerald-400 text-sm"></i>
                                <span>تم التوقيع والاستلام الآن ✓</span>
                            </div>
                        `;
                    }
                    const badge = document.getElementById('recipient-badge');
                    if (badge) {
                        badge.className = 'text-emerald-700 bg-emerald-50 border border-emerald-200 font-bold text-[11px] px-2 py-0.5 rounded';
                        badge.innerText = 'تم التوقيع والاستلام ✓';
                    }
                    const preview = document.getElementById('recipient-sig-preview');
                    if (preview && data.signature_img) {
                        preview.innerHTML = `<img src="${data.signature_img}" alt="توقيع المستلم" class="max-h-full max-w-full object-contain">`;
                    }
                    const meta = document.getElementById('signed-meta');
                    if (meta && data.signed_at) {
                        meta.innerText = 'تاريخ الاستلام: ' + data.signed_at;
                    }
                }
            })
            .catch(() => {});
    }, 3000);
}
</script>

<?php include_once __DIR__ . '/includes/footer.php'; ?>
