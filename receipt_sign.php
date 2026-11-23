<?php
require_once __DIR__ . '/config/app.php';

$token = trim($_GET['token'] ?? '');
if (empty($token)) {
    die('<div style="text-align:center;padding:50px;font-family:sans-serif;direction:rtl;"><h2>عذراً، رابط التوقيع غير صحيح أو مفقود.</h2><p>يرجى التأكد من الرابط المرسل إليك من الشركة.</p></div>');
}

$db = getDBConnection();

// Fetch receipt by sign_token
$stmt = $db->prepare("
    SELECT r.*, 
           c.name as company_name, c.logo as company_logo, c.phone as company_phone, 
           c.tax_number as company_tax_number, c.cr_number as company_cr_number, c.address as company_address,
           c.email as company_email, c.location_url as company_location, c.primary_color as company_primary_color,
           c.signature as company_signature,
           u.name as issuer_name, u.signature as issuer_user_sig
    FROM receipts r
    JOIN companies c ON r.company_id = c.id
    LEFT JOIN users u ON r.user_id = u.id
    WHERE r.sign_token = :tok
    LIMIT 1
");
$stmt->execute([':tok' => $token]);
$receipt = $stmt->fetch();

if (!$receipt) {
    die('<div style="text-align:center;padding:50px;font-family:sans-serif;direction:rtl;"><h2>عذراً، لم يتم العثور على هذا السند أو أن الرابط منتهي الصلاحية.</h2></div>');
}

$receiptId = (int)$receipt['id'];
$recCurrency = !empty($receipt['currency']) ? $receipt['currency'] : 'ج.م';
$themeColor = !empty($receipt['company_primary_color']) ? $receipt['company_primary_color'] : '#0f172a';

// Quick AJAX status checker for real-time polling
if (isset($_GET['check_status']) && $_GET['check_status'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    $sigImg = '';
    if (!empty($receipt['recipient_signature'])) {
        $sigImg = (strpos($receipt['recipient_signature'], 'data:image/') === 0) ? $receipt['recipient_signature'] : ($receipt['recipient_signature']);
    }
    echo json_encode([
        'status' => $receipt['signature_status'],
        'signed_at' => !empty($receipt['signed_at']) ? date('Y-m-d H:i', strtotime($receipt['signed_at'])) : null,
        'signed_by' => $receipt['signed_by_name'] ?? $receipt['customer_name'],
        'signature_img' => $sigImg
    ]);
    exit;
}

$error = '';
$justSigned = false;

// Handle POST: Submit Recipient Signature
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sigData = trim($_POST['signature_data'] ?? '');
    $signerName = trim($_POST['signer_name'] ?? ($receipt['customer_name'] ?: 'المستلم'));
    $ack = isset($_POST['acknowledge_receipt']) && $_POST['acknowledge_receipt'] === '1';

    if (!$ack) {
        $error = 'يرجى تأكيد الإقرار بالاستلام عبر وضع علامة الصح في المربع المخصص.';
    } elseif (empty($sigData) || strpos($sigData, 'data:image/png;base64,') !== 0) {
        $error = 'يرجى رسم توقيعك في المربع المخصص قبل التأكيد.';
    } else {
        $base64 = str_replace('data:image/png;base64,', '', $sigData);
        $base64 = str_replace(' ', '+', $base64);
        $imgBinary = base64_decode($base64);

        if ($imgBinary === false || strlen($imgBinary) < 30) {
            $error = 'التوقيع المدخل غير صالح، يرجى إعادة المحاولة.';
        } else {
            $uploadDir = APP_PATH . '/uploads/signatures/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            $newFileName = 'rec_sig_' . $receiptId . '_' . time() . '.png';
            $targetPath = $uploadDir . $newFileName;
            $savedRelative = 'uploads/signatures/' . $newFileName;

            if (file_put_contents($targetPath, $imgBinary)) {
                $now = date('Y-m-d H:i:s');
                $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

                $upd = $db->prepare("
                    UPDATE receipts 
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
                    ':id' => $receiptId
                ]);

                // Update local array for current view
                $receipt['recipient_signature'] = $savedRelative;
                $receipt['signature_status'] = 'signed';
                $receipt['signed_at'] = $now;
                $receipt['signed_by_name'] = $signerName;
                $justSigned = true;
            } else {
                $error = 'فشل حفظ ملف التوقيع على الخادم، يرجى المحاولة ثانية.';
            }
        }
    }
}

$isSigned = ($receipt['signature_status'] === 'signed');
$amountWords = !empty($receipt['amount_words']) ? $receipt['amount_words'] : tafqeetNumber((float)$receipt['amount'], $recCurrency);
$issuerSigImg = !empty($receipt['issuer_signature']) ? $receipt['issuer_signature'] : (!empty($receipt['issuer_user_sig']) ? $receipt['issuer_user_sig'] : (!empty($receipt['company_signature']) ? $receipt['company_signature'] : null));
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>توقيع واستلام سند <?= htmlspecialchars($receipt['receipt_number']) ?> - <?= htmlspecialchars($receipt['company_name']) ?></title>
    
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
<body class="p-3 sm:p-6 lg:p-8">

<div class="max-w-xl mx-auto space-y-5">

    <!-- Top Security & Trust Header -->
    <div class="flex items-center justify-between text-xs text-slate-500 font-bold px-1">
        <div class="flex items-center space-x-1.5 space-x-reverse text-emerald-600">
            <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 animate-pulse"></span>
            <span>بوابة التوقيع الإلكتروني المعتمد والمشفر</span>
        </div>
        <span class="font-mono text-slate-400">ID: #<?= $receipt['id'] ?></span>
    </div>

    <!-- Official Company Header -->
    <div class="bg-white rounded-3xl p-5 sm:p-6 border border-slate-200/90 shadow-xs flex items-center justify-between gap-4">
        <div class="flex items-center space-x-3.5 space-x-reverse">
            <div class="w-14 h-14 rounded-2xl bg-slate-50 border border-slate-200 flex items-center justify-center p-1.5 shrink-0">
                <?php if (!empty($receipt['company_logo']) && file_exists(APP_PATH . '/' . $receipt['company_logo'])): ?>
                    <img src="<?= htmlspecialchars($receipt['company_logo']) ?>" alt="شعار الشركة" class="max-h-full max-w-full object-contain">
                <?php else: ?>
                    <i class="fa-solid fa-building text-2xl text-slate-400"></i>
                <?php endif; ?>
            </div>
            <div>
                <span class="text-[10px] font-bold text-indigo-600 uppercase tracking-widest block">سند استلام وقبض نقدية</span>
                <h1 class="text-base sm:text-lg font-black text-slate-900"><?= htmlspecialchars($receipt['company_name']) ?></h1>
                <?php if (!empty($receipt['company_phone'])): ?>
                    <span class="text-xs text-slate-400 font-mono block">هاتف: <?= htmlspecialchars($receipt['company_phone']) ?></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="text-left shrink-0">
            <span class="text-[10px] font-bold text-slate-400 block">رقم السند</span>
            <span class="text-sm font-black font-mono text-indigo-700 bg-indigo-50 border border-indigo-200 px-2 py-0.5 rounded-lg block"><?= htmlspecialchars($receipt['receipt_number']) ?></span>
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
                    تم توثيق استلامك للمبلغ المالي إلكترونياً واعتمد السند رسميًا في سجلات الشركة.
                </p>
                <div class="text-xs text-emerald-200 font-mono mt-2 bg-white/10 py-1.5 px-3 rounded-xl inline-block border border-white/10">
                    <i class="fa-solid fa-clock ml-1"></i>
                    تاريخ ووقت التوقيع: <?= date('Y-m-d H:i', strtotime($receipt['signed_at'])) ?>
                </div>
            </div>

            <!-- Captured Recipient Signature Image -->
            <div class="bg-white rounded-2xl p-4 text-slate-900 max-w-sm mx-auto shadow-inner border border-slate-200">
                <span class="text-[11px] font-bold text-slate-500 block mb-1">توقيعك الإلكتروني المسجل:</span>
                <div class="h-24 flex items-center justify-center">
                    <?php if (strpos($receipt['recipient_signature'], 'data:image/') === 0): ?>
                        <img src="<?= $receipt['recipient_signature'] ?>" alt="توقيع المستلم" class="max-h-full max-w-full object-contain">
                    <?php elseif (file_exists(APP_PATH . '/' . $receipt['recipient_signature'])): ?>
                        <img src="<?= htmlspecialchars($receipt['recipient_signature']) ?>" alt="توقيع المستلم" class="max-h-full max-w-full object-contain">
                    <?php endif; ?>
                </div>
                <div class="border-t border-slate-100 pt-1 mt-1 text-[11px] text-slate-600 flex justify-between items-center">
                    <span>المستلم: <strong><?= htmlspecialchars($receipt['signed_by_name'] ?: $receipt['customer_name']) ?></strong></span>
                    <span class="text-emerald-600 font-bold"><i class="fa-solid fa-shield-halved ml-1"></i> موثق إلكترونياً</span>
                </div>
            </div>

            <!-- Action: View/Print Official A4 Receipt -->
            <div class="pt-2 flex flex-wrap items-center justify-center gap-2.5">
                <span class="inline-flex items-center text-xs font-bold text-emerald-300 bg-white/10 px-3.5 py-2 rounded-xl border border-white/10">
                    <i class="fa-solid fa-circle-check ml-1.5 text-emerald-400"></i>
                    السند معتمد وموثق رقمياً
                </span>
                <a href="receipt_print.php?token=<?= urlencode($token) ?>" target="_blank" class="inline-flex items-center bg-white text-slate-900 hover:bg-slate-100 font-bold px-4 py-2 rounded-xl text-xs transition shadow-sm">
                    <i class="fa-solid fa-file-lines ml-1.5 text-indigo-600"></i>
                    معاينة السند الرسمي (A4)
                </a>
            </div>
        </div>
    <?php endif; ?>

    <!-- VOUCHER FINANCIAL DETAILS CARD -->
    <div class="bg-white rounded-3xl p-5 sm:p-6 border border-slate-200/90 shadow-sm space-y-4">
        
        <!-- Big Amount Display Box -->
        <div class="bg-slate-50 border-2 border-indigo-100 rounded-2xl p-4 text-center space-y-1">
            <span class="text-xs font-bold text-slate-500 block">المبلغ المحدد في السند:</span>
            <div class="text-3xl sm:text-4xl font-black font-mono text-indigo-700">
                <?= formatMoney($receipt['amount'], $recCurrency) ?>
            </div>
            <p class="text-xs font-bold text-slate-700 pt-1">
                فقط وقدره: <span class="text-indigo-900"><?= htmlspecialchars($amountWords) ?></span>
            </p>
        </div>

        <!-- Meta Details Table -->
        <div class="divide-y divide-slate-100 text-xs">
            <div class="py-2.5 flex items-center justify-between">
                <span class="text-slate-500">اسم المستلم منه / العميل:</span>
                <strong class="text-slate-900 font-bold"><?= htmlspecialchars($receipt['customer_name']) ?></strong>
            </div>
            <?php if (!empty($receipt['customer_phone'])): ?>
                <div class="py-2.5 flex items-center justify-between">
                    <span class="text-slate-500">رقم الهاتف:</span>
                    <strong class="text-slate-800 font-mono"><?= htmlspecialchars($receipt['customer_phone']) ?></strong>
                </div>
            <?php endif; ?>
            <div class="py-2.5 flex items-center justify-between">
                <span class="text-slate-500">تاريخ السند:</span>
                <strong class="text-slate-800 font-mono"><?= htmlspecialchars($receipt['receipt_date']) ?></strong>
            </div>
            <div class="py-2.5 flex items-center justify-between">
                <span class="text-slate-500">طريقة الدفع:</span>
                <span class="bg-slate-100 text-slate-800 px-2 py-0.5 rounded font-bold"><?= htmlspecialchars($receipt['payment_method'] ?: 'نقداً كاش') ?></span>
            </div>
            <div class="py-2.5 flex items-start justify-between gap-4">
                <span class="text-slate-500 shrink-0">وذلك مقابل (البيان):</span>
                <span class="text-slate-900 font-bold text-left"><?= htmlspecialchars($receipt['purpose']) ?><?= !empty($receipt['notes']) ? ' (' . htmlspecialchars($receipt['notes']) . ')' : '' ?></span>
            </div>
        </div>

        <!-- Issuer Authorized Signature Display (توقيع مصدر السند) -->
        <div class="p-3.5 bg-slate-50 border border-slate-200 rounded-2xl flex items-center justify-between text-xs">
            <div>
                <span class="text-[11px] font-bold text-slate-500 block">مصدر السند المعتمد:</span>
                <strong class="text-slate-900"><?= htmlspecialchars($receipt['issuer_name'] ?: 'المحاسب المسؤول') ?></strong>
                <span class="text-[10px] text-emerald-600 block mt-0.5 font-bold"><i class="fa-solid fa-circle-check ml-0.5"></i> معتمد رسمياً من الإدارة</span>
            </div>
            <div class="h-16 w-32 bg-white border border-slate-200 rounded-xl flex items-center justify-center p-1 shadow-2xs">
                <?php if (!empty($issuerSigImg) && file_exists(APP_PATH . '/' . $issuerSigImg)): ?>
                    <img src="<?= htmlspecialchars($issuerSigImg) ?>" alt="توقيع مصدر السند" class="max-h-full max-w-full object-contain">
                <?php else: ?>
                    <span class="text-[11px] font-bold text-slate-400">توقيع معتمد</span>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <!-- CASE 2: PENDING SIGNATURE - INTERACTIVE SIGNATURE PAD -->
    <?php if (!$isSigned): ?>
        <form id="sign-form" action="receipt_sign.php?token=<?= urlencode($token) ?>" method="POST" class="bg-white rounded-3xl p-5 sm:p-6 border border-slate-200/90 shadow-md space-y-4">
            <input type="hidden" name="signature_data" id="sig_data_input" value="">
            <input type="hidden" name="signer_name" value="<?= htmlspecialchars($receipt['customer_name']) ?>">

            <div class="flex items-center justify-between pb-2 border-b border-slate-100">
                <div class="flex items-center space-x-2 space-x-reverse">
                    <span class="w-8 h-8 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center font-bold text-sm">
                        <i class="fa-solid fa-pen-nib"></i>
                    </span>
                    <div>
                        <h3 class="font-black text-slate-900 text-sm">لوحة التوقيع الإلكتروني المعتمد</h3>
                        <p class="text-[11px] text-slate-400">وقع بإصبعك على شاشة الموبايل أو بقلم اللمس أو بالماوس</p>
                    </div>
                </div>
                <button type="button" onclick="clearSignPad()" class="text-xs text-rose-600 hover:text-rose-700 font-bold flex items-center transition bg-rose-50 px-2.5 py-1 rounded-lg border border-rose-100">
                    <i class="fa-solid fa-rotate-right ml-1"></i> مسح وبدء من جديد
                </button>
            </div>

            <!-- Signature Pad Canvas -->
            <div class="relative bg-slate-50 border-2 border-dashed border-indigo-200 hover:border-indigo-400 rounded-2xl overflow-hidden transition">
                <canvas id="sign-pad" class="w-full h-44 cursor-crosshair touch-none block"></canvas>
                <div class="absolute bottom-2 left-3 text-[10px] text-slate-300 font-mono pointer-events-none select-none">
                    لوحة التوقيع والاستلام المعتمد
                </div>
            </div>

            <!-- Legal Acknowledgement Checkbox (Exact User Requirement) -->
            <div class="p-3.5 bg-indigo-50/70 border border-indigo-100 rounded-2xl">
                <label class="flex items-start space-x-2.5 space-x-reverse cursor-pointer">
                    <input type="checkbox" name="acknowledge_receipt" id="ack_checkbox" value="1" required class="rounded text-indigo-600 focus:ring-indigo-500 w-5 h-5 mt-0.5 shrink-0">
                    <span class="text-xs font-bold text-slate-800 leading-relaxed select-none">
                        أقر وأعترف أنا <strong>(<?= htmlspecialchars($receipt['customer_name']) ?>)</strong> بأنني استلمت المبلغ الموضح أعلاه وقدره <strong>(<?= formatMoney($receipt['amount'], $recCurrency) ?>)</strong> كاملاً، وهذا إقرار وتوقيع إلكتروني رسمي مني بصحة الاستلام.
                    </span>
                </label>
            </div>

            <!-- Submit Button -->
            <div class="pt-2">
                <button type="button" onclick="submitRecipientSignature()" id="btn-submit-sig" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-black py-3.5 px-6 rounded-2xl shadow-lg shadow-emerald-600/25 transition text-sm flex items-center justify-center">
                    <i class="fa-solid fa-check-circle ml-2 text-base"></i>
                    <span>تأكيد استلام المبلغ واعتماد التوقيع الإلكتروني</span>
                </button>
            </div>
        </form>
    <?php endif; ?>

    <!-- Footer Note -->
    <div class="text-center text-[11px] text-slate-400 space-y-1">
        <p>نظام التوقيع والاستلام الإلكتروني المعتمد - <?= htmlspecialchars($receipt['company_name']) ?></p>
        <p class="font-mono text-[10px] text-slate-300">Protected & Verified by Secure Token Authentication</p>
    </div>

</div>

<script>
let signCanvas = document.getElementById('sign-pad');
let signCtx = null;
let isDrawing = false;
let hasDrawn = false;

function initSignPad() {
    if (!signCanvas) return;
    signCtx = signCanvas.getContext('2d');
    resizeSignCanvas();
    window.addEventListener('resize', resizeSignCanvas);

    const getPos = (e) => {
        const rect = signCanvas.getBoundingClientRect();
        const clientX = e.clientX || (e.touches && e.touches[0] ? e.touches[0].clientX : 0);
        const clientY = e.clientY || (e.touches && e.touches[0] ? e.touches[0].clientY : 0);
        return {
            x: clientX - rect.left,
            y: clientY - rect.top
        };
    };

    const startDraw = (e) => {
        e.preventDefault();
        isDrawing = true;
        const pos = getPos(e);
        signCtx.beginPath();
        signCtx.moveTo(pos.x, pos.y);
    };

    const draw = (e) => {
        if (!isDrawing) return;
        e.preventDefault();
        const pos = getPos(e);
        signCtx.lineTo(pos.x, pos.y);
        signCtx.stroke();
        hasDrawn = true;
    };

    const stopDraw = (e) => {
        if (!isDrawing) return;
        isDrawing = false;
        signCtx.closePath();
    };

    signCanvas.addEventListener('mousedown', startDraw);
    signCanvas.addEventListener('mousemove', draw);
    signCanvas.addEventListener('mouseup', stopDraw);
    signCanvas.addEventListener('mouseleave', stopDraw);

    signCanvas.addEventListener('touchstart', startDraw, { passive: false });
    signCanvas.addEventListener('touchmove', draw, { passive: false });
    signCanvas.addEventListener('touchend', stopDraw, { passive: false });
}

function resizeSignCanvas() {
    if (!signCanvas || !signCtx) return;
    const ratio = Math.max(window.devicePixelRatio || 1, 1);
    const w = signCanvas.offsetWidth;
    const h = signCanvas.offsetHeight;
    if (w === 0 || h === 0) return;

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
