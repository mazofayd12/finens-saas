<?php
require_once __DIR__ . '/config/app.php';

$token = trim($_GET['token'] ?? '');
if (empty($token)) {
    die('<div style="text-align:center;padding:50px;font-family:sans-serif;direction:rtl;"><h2>رابط التوقيع غير صالح أو غير مكتمل.</h2></div>');
}

$db = getDBConnection();
$stmt = $db->prepare("
    SELECT e.*, 
           c.name as company_name, c.phone as company_phone, c.logo as company_logo,
           c.tax_number as company_tax_number, c.cr_number as company_cr_number, c.address as company_address,
           c.email as company_email, c.location_url as company_location, c.primary_color as company_primary_color,
           c.signature as company_signature,
           u.name as issuer_name, u.signature as issuer_user_sig,
           p.name as project_name
    FROM expenses e
    JOIN companies c ON e.company_id = c.id
    LEFT JOIN users u ON e.user_id = u.id
    LEFT JOIN projects p ON e.project_id = p.id
    WHERE e.sign_token = :tok
    LIMIT 1
");
$stmt->execute([':tok' => $token]);
$voucher = $stmt->fetch();

if (!$voucher) {
    die('<div style="text-align:center;padding:50px;font-family:sans-serif;direction:rtl;"><h2>عذراً، لم يتم العثور على سند الصرف المطلوب أو أن الرابط منتهي الصلاحية.</h2></div>');
}

$voucherId = (int)$voucher['id'];
$vchCurrency = !empty($voucher['currency']) ? $voucher['currency'] : 'ج.م';
$themeColor = !empty($voucher['company_primary_color']) ? $voucher['company_primary_color'] : '#991b1b';

// Quick AJAX status checker for real-time polling
if (isset($_GET['check_status']) && $_GET['check_status'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    $sigImg = '';
    if (!empty($voucher['recipient_signature'])) {
        $sigImg = (strpos($voucher['recipient_signature'], 'data:image/') === 0) ? $voucher['recipient_signature'] : ($voucher['recipient_signature']);
    }
    echo json_encode([
        'status' => $voucher['signature_status'],
        'signed_at' => !empty($voucher['signed_at']) ? date('Y-m-d H:i', strtotime($voucher['signed_at'])) : null,
        'signed_by' => $voucher['signed_by_name'] ?? $voucher['beneficiary_name'],
        'signature_img' => $sigImg
    ]);
    exit;
}

$error = '';
$justSigned = false;

// Handle Signature Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($voucher['signature_status'] ?? '') !== 'signed') {
    $sigData = trim($_POST['signature_data'] ?? '');
    $signerName = trim($_POST['signer_name'] ?? ($voucher['beneficiary_name'] ?: 'المستلم'));

    if (empty($sigData)) {
        $error = 'يرجى رسم التوقيع أولاً في لوحة التوقيع.';
    } elseif (strpos($sigData, 'data:image/') !== 0) {
        $error = 'صيغة بيانات التوقيع غير صحيحة.';
    } else {
        // Decode base64 PNG
        $parts = explode(',', $sigData, 2);
        $imgBinary = base64_decode($parts[1] ?? '');
        if (!$imgBinary || strlen($imgBinary) < 100) {
            $error = 'التوقيع المدخل فارغ أو غير واضح، يرجى التوقيع مرة ثانية.';
        } else {
            $uploadDir = APP_PATH . '/uploads/signatures/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            $newFileName = 'vch_sig_' . $voucherId . '_' . time() . '_' . rand(100, 999) . '.png';
            $targetPath = $uploadDir . $newFileName;
            $savedRelative = 'uploads/signatures/' . $newFileName;

            if (file_put_contents($targetPath, $imgBinary)) {
                $now = date('Y-m-d H:i:s');
                $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

                $upd = $db->prepare("
                    UPDATE expenses 
                    SET recipient_signature = :sig,
                        signature_status = 'signed',
                        signed_at = :now,
                        signed_ip = :ip,
                        signed_by_name = :sname
                    WHERE id = :id
                ");
                $upd->execute([
                    ':sig' => $savedRelative,
                    ':now' => $now,
                    ':ip' => $ip,
                    ':sname' => $signerName,
                    ':id' => $voucherId
                ]);

                // Update local array for current view
                $voucher['recipient_signature'] = $savedRelative;
                $voucher['signature_status'] = 'signed';
                $voucher['signed_at'] = $now;
                $voucher['signed_by_name'] = $signerName;
                $justSigned = true;
            } else {
                $error = 'فشل حفظ ملف التوقيع على الخادم، يرجى المحاولة ثانية.';
            }
        }
    }
}

$isSigned = ($voucher['signature_status'] === 'signed');
$amountWords = tafqeetNumber((float)$voucher['amount'], $vchCurrency);
$issuerSigImg = !empty($voucher['issuer_signature']) ? $voucher['issuer_signature'] : (!empty($voucher['issuer_user_sig']) ? $voucher['issuer_user_sig'] : (!empty($voucher['company_signature']) ? $voucher['company_signature'] : null));
$voucherNo = $voucher['voucher_number'] ?: ('VCH-' . $voucher['id']);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>توقيع واستلام سند صرف <?= htmlspecialchars($voucherNo) ?> - <?= htmlspecialchars($voucher['company_name']) ?></title>
    
    <!-- Tailwind CSS & Fonts & Icons -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

    <style>
        body {
            font-family: 'Cairo', sans-serif;
            background-color: #f8fafc;
            color: #0f172a;
            -webkit-tap-highlight-color: transparent;
        }
        .touch-none {
            touch-action: none;
        }
    </style>
</head>
<body class="min-h-screen p-3 sm:p-6 flex flex-col justify-between">

<div class="max-w-xl mx-auto w-full space-y-5">

    <!-- Top Official Header Card -->
    <div class="bg-white rounded-3xl p-5 border border-slate-200/90 shadow-xs flex items-center justify-between">
        <div class="flex items-center space-x-3 space-x-reverse">
            <div class="w-14 h-14 rounded-2xl bg-slate-50 border border-slate-200 flex items-center justify-center p-1.5 shrink-0">
                <?php if (!empty($voucher['company_logo']) && file_exists(APP_PATH . '/' . $voucher['company_logo'])): ?>
                    <img src="<?= htmlspecialchars($voucher['company_logo']) ?>" alt="شعار الشركة" class="max-h-full max-w-full object-contain">
                <?php else: ?>
                    <i class="fa-solid fa-building text-2xl text-slate-400"></i>
                <?php endif; ?>
            </div>
            <div>
                <span class="text-[10px] font-bold text-rose-600 uppercase tracking-widest block">سند صرف مالي إلكتروني</span>
                <h1 class="text-base sm:text-lg font-black text-slate-900"><?= htmlspecialchars($voucher['company_name']) ?></h1>
                <?php if (!empty($voucher['company_phone'])): ?>
                    <span class="text-xs text-slate-400 font-mono block">هاتف: <?= htmlspecialchars($voucher['company_phone']) ?></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="text-left shrink-0">
            <span class="text-[10px] font-bold text-slate-400 block">رقم السند</span>
            <span class="text-sm font-black font-mono text-rose-700 bg-rose-50 border border-rose-200 px-2 py-0.5 rounded-lg block"><?= htmlspecialchars($voucherNo) ?></span>
        </div>
    </div>

    <!-- Error Alert if any -->
    <?php if (!empty($error)): ?>
        <div class="bg-rose-50 border-2 border-rose-200 text-rose-800 p-4 rounded-2xl text-xs font-bold flex items-center space-x-2 space-x-reverse shadow-xs">
            <i class="fa-solid fa-triangle-exclamation text-base text-rose-600 shrink-0"></i>
            <span><?= htmlspecialchars($error) ?></span>
        </div>
    <?php endif; ?>

    <!-- CASE 1: ALREADY SIGNED SUCCESS VIEW -->
    <?php if ($isSigned): ?>
        <div class="bg-gradient-to-br from-emerald-950 via-slate-900 to-emerald-900 rounded-3xl p-6 sm:p-7 border border-emerald-800 text-white shadow-xl text-center space-y-4">
            <div class="w-16 h-16 rounded-full bg-emerald-500/20 text-emerald-400 border border-emerald-400/30 mx-auto flex items-center justify-center text-3xl animate-bounce">
                <i class="fa-solid fa-check-double"></i>
            </div>
            <div>
                <h2 class="text-xl font-black text-emerald-300">تم استلام المبلغ وتأكيد التوقيع الإلكتروني بنجاح!</h2>
                <p class="text-xs text-slate-300 mt-1">
                    تم توثيق استلامك للمبلغ المالي إلكترونياً واعتمد سند الصرف رسميًا في سجلات الشركة.
                </p>
                <div class="text-xs text-emerald-200 font-mono mt-2 bg-white/10 py-1.5 px-3 rounded-xl inline-block border border-white/10">
                    <i class="fa-solid fa-clock ml-1"></i>
                    تاريخ ووقت التوقيع: <?= date('Y-m-d H:i', strtotime($voucher['signed_at'])) ?>
                </div>
            </div>

            <!-- Captured Recipient Signature Image -->
            <div class="bg-white rounded-2xl p-4 text-slate-900 max-w-sm mx-auto shadow-inner border border-slate-200">
                <span class="text-[11px] font-bold text-slate-500 block mb-1">توقيعك الإلكتروني المسجل:</span>
                <div class="h-24 flex items-center justify-center">
                    <?php if (strpos($voucher['recipient_signature'], 'data:image/') === 0): ?>
                        <img src="<?= $voucher['recipient_signature'] ?>" alt="توقيع المستلم" class="max-h-full max-w-full object-contain">
                    <?php elseif (file_exists(APP_PATH . '/' . $voucher['recipient_signature'])): ?>
                        <img src="<?= htmlspecialchars($voucher['recipient_signature']) ?>" alt="توقيع المستلم" class="max-h-full max-w-full object-contain">
                    <?php endif; ?>
                </div>
                <div class="border-t border-slate-100 pt-1 mt-1 text-[11px] text-slate-600 flex justify-between items-center">
                    <span>المستلم: <strong><?= htmlspecialchars($voucher['signed_by_name'] ?: $voucher['beneficiary_name']) ?></strong></span>
                    <span class="text-emerald-600 font-bold"><i class="fa-solid fa-shield-halved ml-1"></i> موثق إلكترونياً</span>
                </div>
            </div>

            <!-- Action: View/Print Official A4 Receipt (Without auto-print!) -->
            <div class="pt-2 flex flex-wrap items-center justify-center gap-2.5">
                <span class="inline-flex items-center text-xs font-bold text-emerald-300 bg-white/10 px-3.5 py-2 rounded-xl border border-white/10">
                    <i class="fa-solid fa-circle-check ml-1.5 text-emerald-400"></i>
                    السند معتمد وموثق رقمياً
                </span>
                <a href="project_voucher_print.php?token=<?= urlencode($token) ?>" target="_blank" class="inline-flex items-center bg-white text-slate-900 hover:bg-slate-100 font-bold px-4 py-2 rounded-xl text-xs transition shadow-sm">
                    <i class="fa-solid fa-file-lines ml-1.5 text-rose-600"></i>
                    معاينة نسخة السند الرسمية (A4)
                </a>
            </div>
        </div>
    <?php endif; ?>

    <!-- VOUCHER FINANCIAL DETAILS CARD -->
    <div class="bg-white rounded-3xl p-5 sm:p-6 border border-slate-200/90 shadow-sm space-y-4">
        
        <!-- Big Amount Display Box -->
        <div class="bg-slate-50 border-2 border-rose-100 rounded-2xl p-4 text-center space-y-1">
            <span class="text-xs font-bold text-slate-500 block">المبلغ المالي المصروف في السند:</span>
            <div class="text-3xl sm:text-4xl font-black font-mono text-rose-700">
                <?= formatMoney($voucher['amount'], $vchCurrency) ?>
            </div>
            <p class="text-xs font-bold text-slate-700 pt-1">
                فقط وقدره: <span class="text-rose-950"><?= htmlspecialchars($amountWords) ?></span>
            </p>
        </div>

        <!-- Meta Details Table -->
        <div class="divide-y divide-slate-100 text-xs">
            <div class="py-2.5 flex items-center justify-between">
                <span class="text-slate-500">اسم الجهة أو الشخص المستلم:</span>
                <strong class="text-slate-900 font-bold"><?= htmlspecialchars($voucher['beneficiary_name']) ?></strong>
            </div>
            <?php if (!empty($voucher['beneficiary_phone'])): ?>
                <div class="py-2.5 flex items-center justify-between">
                    <span class="text-slate-500">رقم الهاتف:</span>
                    <strong class="text-slate-800 font-mono"><?= htmlspecialchars($voucher['beneficiary_phone']) ?></strong>
                </div>
            <?php endif; ?>
            <?php if (!empty($voucher['beneficiary_id_number'])): ?>
                <div class="py-2.5 flex items-center justify-between">
                    <span class="text-slate-500">رقم الهوية / السجل التجاري:</span>
                    <strong class="text-slate-800 font-mono"><?= htmlspecialchars($voucher['beneficiary_id_number']) ?></strong>
                </div>
            <?php endif; ?>
            <div class="py-2.5 flex items-center justify-between">
                <span class="text-slate-500">تاريخ سند الصرف:</span>
                <strong class="text-slate-800 font-mono"><?= htmlspecialchars($voucher['expense_date']) ?></strong>
            </div>
            <div class="py-2.5 flex items-center justify-between">
                <span class="text-slate-500">طريقة الصرف:</span>
                <span class="bg-slate-100 text-slate-800 px-2 py-0.5 rounded font-bold"><?= htmlspecialchars($voucher['payment_method'] ?: 'نقداً كاش') ?></span>
            </div>
            <?php if (!empty($voucher['cheque_number'])): ?>
                <div class="py-2.5 flex items-center justify-between">
                    <span class="text-slate-500">رقم الشيك / الحوالة:</span>
                    <strong class="text-slate-800 font-mono"><?= htmlspecialchars($voucher['cheque_number']) ?> (<?= htmlspecialchars($voucher['bank_name'] ?: 'بنكي') ?>)</strong>
                </div>
            <?php endif; ?>
            <div class="py-2.5 flex items-start justify-between gap-4">
                <span class="text-slate-500 shrink-0">وذلك مقابل (البيان):</span>
                <span class="text-slate-900 font-bold text-left"><?= htmlspecialchars($voucher['title']) ?><?= !empty($voucher['description']) ? ' (' . htmlspecialchars($voucher['description']) . ')' : '' ?></span>
            </div>
            <?php if (!empty($voucher['project_name'])): ?>
                <div class="py-2.5 flex items-center justify-between">
                    <span class="text-slate-500">المشروع المرتبط:</span>
                    <strong class="text-indigo-700 font-bold"><?= htmlspecialchars($voucher['project_name']) ?></strong>
                </div>
            <?php endif; ?>
        </div>

        <!-- Issuer Authorized Signature Display (توقيع مصدر الصرف) -->
        <div class="p-3.5 bg-slate-50 border border-slate-200 rounded-2xl flex items-center justify-between text-xs">
            <div>
                <span class="text-[11px] font-bold text-slate-500 block">جهة الصرف المعتمدة:</span>
                <strong class="text-slate-900"><?= htmlspecialchars($voucher['issuer_name'] ?: 'المحاسب المسؤول') ?></strong>
                <span class="text-[10px] text-emerald-600 block mt-0.5 font-bold"><i class="fa-solid fa-circle-check ml-0.5"></i> معتمد رسمياً من الإدارة</span>
            </div>
            <div class="h-16 w-32 bg-white border border-slate-200 rounded-xl flex items-center justify-center p-1 shadow-2xs">
                <?php if (!empty($issuerSigImg) && file_exists(APP_PATH . '/' . $issuerSigImg)): ?>
                    <img src="<?= htmlspecialchars($issuerSigImg) ?>" alt="توقيع مصدر الصرف" class="max-h-full max-w-full object-contain">
                <?php else: ?>
                    <span class="text-[11px] font-bold text-slate-400">توقيع معتمد</span>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <!-- CASE 2: PENDING SIGNATURE - INTERACTIVE SIGNATURE PAD -->
    <?php if (!$isSigned): ?>
        <form id="sign-form" action="voucher_sign.php?token=<?= urlencode($token) ?>" method="POST" class="bg-white rounded-3xl p-5 sm:p-6 border border-slate-200/90 shadow-md space-y-4">
            <input type="hidden" name="signature_data" id="sig_data_input" value="">
            <input type="hidden" name="signer_name" value="<?= htmlspecialchars($voucher['beneficiary_name']) ?>">

            <div class="flex items-center justify-between pb-2 border-b border-slate-100">
                <div class="flex items-center space-x-2 space-x-reverse">
                    <span class="w-8 h-8 rounded-xl bg-rose-50 text-rose-600 flex items-center justify-center font-bold text-sm">
                        <i class="fa-solid fa-pen-nib"></i>
                    </span>
                    <div>
                        <h3 class="font-black text-slate-900 text-sm">لوحة التوقيع الإلكتروني للمستلم</h3>
                        <p class="text-[11px] text-slate-400">وقع بإصبعك على شاشة الموبايل أو بقلم اللمس أو بالماوس</p>
                    </div>
                </div>
                <button type="button" onclick="clearSignPad()" class="text-xs text-rose-600 hover:text-rose-700 font-bold flex items-center transition bg-rose-50 px-2.5 py-1 rounded-lg border border-rose-100">
                    <i class="fa-solid fa-rotate-right ml-1"></i> مسح وبدء من جديد
                </button>
            </div>

            <!-- Signature Pad Canvas -->
            <div class="relative bg-slate-50 border-2 border-dashed border-rose-200 hover:border-rose-400 rounded-2xl overflow-hidden transition">
                <canvas id="sign-pad" class="w-full h-44 cursor-crosshair touch-none block"></canvas>
                <div class="absolute bottom-2 left-3 text-[10px] text-slate-300 font-mono pointer-events-none select-none">
                    لوحة التوقيع والاستلام المعتمد
                </div>
            </div>

            <!-- Legal Acknowledgement Checkbox -->
            <div class="p-3.5 bg-rose-50/70 border border-rose-100 rounded-2xl">
                <label class="flex items-start space-x-2.5 space-x-reverse cursor-pointer">
                    <input type="checkbox" id="ack_checkbox" required class="mt-1 w-4 h-4 text-rose-600 rounded focus:ring-rose-500 border-slate-300">
                    <span class="text-xs text-slate-800 leading-relaxed font-semibold">
                        أقر أنا (<strong class="text-rose-950 font-bold"><?= htmlspecialchars($voucher['beneficiary_name']) ?></strong>) بأنني استلمت المبلغ المالي الموضح أعلاه وقدره (<strong class="font-mono text-rose-900 font-bold"><?= formatMoney($voucher['amount'], $vchCurrency) ?></strong>) كاملاً من شركة (<strong class="text-slate-900 font-bold"><?= htmlspecialchars($voucher['company_name']) ?></strong>) وذلك عن: <strong><?= htmlspecialchars($voucher['title']) ?></strong>، وهذا إقرار رسمي وتوقيع إلكتروني ملزم بالاستلام.
                    </span>
                </label>
            </div>

            <!-- Submit Button -->
            <button type="button" onclick="submitRecipientSignature()" id="btn-submit-sig" class="w-full bg-rose-600 hover:bg-rose-700 text-white font-black py-4 px-6 rounded-2xl text-sm transition shadow-lg shadow-rose-600/30 flex items-center justify-center">
                <i class="fa-solid fa-signature ml-2 text-base"></i>
                تأكيد واستلام المبلغ والتوقيع إلكترونياً
            </button>
        </form>
    <?php endif; ?>

</div>

<!-- Footer Info -->
<footer class="mt-8 text-center text-xs text-slate-400 space-y-1">
    <p>نظام التوقيع الإلكتروني المعتمد © <?= date('Y') ?> <?= htmlspecialchars($voucher['company_name']) ?></p>
    <p class="text-[10px] text-slate-300 font-mono">سند صرف مالي رقم: <?= htmlspecialchars($voucherNo) ?> | معرف الأمان: <?= substr($token, 0, 8) ?>****</p>
</footer>

<script>
let signCanvas = null;
let signCtx = null;
let isDrawing = false;
let hasDrawn = false;

function initSignPad() {
    signCanvas = document.getElementById('sign-pad');
    if (!signCanvas) return;

    signCtx = signCanvas.getContext('2d');
    resizeSignCanvas();

    window.addEventListener('resize', resizeSignCanvas);

    // Mouse Events
    signCanvas.addEventListener('mousedown', (e) => {
        isDrawing = true;
        hasDrawn = true;
        const rect = signCanvas.getBoundingClientRect();
        signCtx.beginPath();
        signCtx.moveTo(e.clientX - rect.left, e.clientY - rect.top);
    });

    signCanvas.addEventListener('mousemove', (e) => {
        if (!isDrawing) return;
        const rect = signCanvas.getBoundingClientRect();
        signCtx.lineTo(e.clientX - rect.left, e.clientY - rect.top);
        signCtx.stroke();
    });

    window.addEventListener('mouseup', () => {
        if (isDrawing) {
            isDrawing = false;
        }
    });

    // Touch Events (Mobile & Stylus)
    signCanvas.addEventListener('touchstart', (e) => {
        e.preventDefault();
        if (e.touches.length === 1) {
            isDrawing = true;
            hasDrawn = true;
            const rect = signCanvas.getBoundingClientRect();
            const touch = e.touches[0];
            signCtx.beginPath();
            signCtx.moveTo(touch.clientX - rect.left, touch.clientY - rect.top);
        }
    }, { passive: false });

    signCanvas.addEventListener('touchmove', (e) => {
        e.preventDefault();
        if (!isDrawing || e.touches.length !== 1) return;
        const rect = signCanvas.getBoundingClientRect();
        const touch = e.touches[0];
        signCtx.lineTo(touch.clientX - rect.left, touch.clientY - rect.top);
        signCtx.stroke();
    }, { passive: false });

    signCanvas.addEventListener('touchend', (e) => {
        e.preventDefault();
        isDrawing = false;
    }, { passive: false });
}

function resizeSignCanvas() {
    if (!signCanvas || !signCtx) return;
    const ratio = Math.max(window.devicePixelRatio || 1, 1);
    const w = signCanvas.offsetWidth;
    const h = signCanvas.offsetHeight;

    let prev = null;
    if (hasDrawn) {
        prev = signCanvas.toDataURL();
    }

    signCanvas.width = w * ratio;
    signCanvas.height = h * ratio;
    signCtx.scale(ratio, ratio);
    signCtx.strokeStyle = '#0f172a';
    signCtx.lineWidth = 2.8;
    signCtx.lineCap = 'round';
    signCtx.lineJoin = 'round';

    if (prev) {
        let img = new Image();
        img.onload = () => {
            signCtx.drawImage(img, 0, 0, w, h);
        };
        img.src = prev;
    }
}

function clearSignPad() {
    if (!signCanvas || !signCtx) return;
    const w = signCanvas.offsetWidth;
    const h = signCanvas.offsetHeight;
    signCtx.clearRect(0, 0, w, h);
    hasDrawn = false;
}

function submitRecipientSignature() {
    const ack = document.getElementById('ack_checkbox');
    if (!ack || !ack.checked) {
        alert('يرجى تحديد مربع الإقرار باستلام المبلغ أولاً.');
        if (ack) ack.focus();
        return;
    }

    if (!hasDrawn) {
        alert('يرجى رسم توقيعك في المربع المخصص باللمس أو القلم أو الماوس.');
        return;
    }

    const dataUrl = signCanvas.toDataURL('image/png');
    document.getElementById('sig_data_input').value = dataUrl;

    const btn = document.getElementById('btn-submit-sig');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin ml-2"></i> جاري اعتماد التوقيع...';
    }

    document.getElementById('sign-form').submit();
}

window.addEventListener('DOMContentLoaded', () => {
    initSignPad();
});
</script>

</body>
</html>
