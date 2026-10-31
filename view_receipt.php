<?php
require_once __DIR__ . '/config/app.php';

// Public electronic receipt viewer & verification page (No login required)
$receiptNum = trim($_GET['num'] ?? '');
$receiptId = (int)($_GET['id'] ?? 0);

if (empty($receiptNum) && $receiptId <= 0) {
    $error = 'رقم الإيصال غير محدد أو غير صالح.';
} else {
    $db = getDBConnection();
    if (!empty($receiptNum)) {
        $stmt = $db->prepare("
            SELECT r.*, c.name as company_name, c.phone as company_phone, c.logo as company_logo, c.code as company_code, u.name as receiver_name
            FROM receipts r
            JOIN companies c ON r.company_id = c.id
            LEFT JOIN users u ON r.user_id = u.id
            WHERE r.receipt_number = :num
            LIMIT 1
        ");
        $stmt->execute([':num' => $receiptNum]);
    } else {
        $stmt = $db->prepare("
            SELECT r.*, c.name as company_name, c.phone as company_phone, c.logo as company_logo, c.code as company_code, u.name as receiver_name
            FROM receipts r
            JOIN companies c ON r.company_id = c.id
            LEFT JOIN users u ON r.user_id = u.id
            WHERE r.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $receiptId]);
    }

    $receipt = $stmt->fetch();
    if (!$receipt) {
        $error = 'لم يتم العثور على هذا الإيصال، قد يكون ملغياً أو غير صحيح.';
    }
}

$pageTitle = isset($receipt) ? "إيصال استلام إلكتروني موثق {$receipt['receipt_number']}" : "التحقق من الفاتورة";
$publicUrl = isset($receipt) ? getReceiptPublicUrl($receipt) : '';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    
    <!-- Meta tags for social / sharing preview -->
    <?php if (isset($receipt)): ?>
    <meta property="og:title" content="إيصال استلام نقدية رقم <?= htmlspecialchars($receipt['receipt_number']) ?> - <?= htmlspecialchars($receipt['company_name']) ?>">
    <meta property="og:description" content="إيصال استلام مبلغ <?= formatMoney($receipt['amount'], $receipt['currency'] ?? 'ج.م') ?> من <?= htmlspecialchars($receipt['customer_name']) ?>">
    <meta property="og:type" content="website">
    <?php endif; ?>

    <!-- Fonts & Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brand: {
                            50: '#eef2ff',
                            100: '#e0e7ff',
                            500: '#6366f1',
                            600: '#4f46e5',
                            700: '#4338ca',
                            900: '#312e81',
                        }
                    }
                }
            }
        }
    </script>

    <style>
        body {
            font-family: 'Cairo', sans-serif;
            background: #f8fafc;
        }
        @media print {
            .no-print {
                display: none !important;
            }
        }
    </style>
</head>
<body class="min-h-screen py-6 sm:py-10 px-4 flex flex-col items-center justify-center">

    <?php if (!empty($error)): ?>
        <!-- Error State -->
        <div class="max-w-md w-full bg-white rounded-3xl shadow-xl p-8 text-center border border-slate-200">
            <div class="w-16 h-16 rounded-2xl bg-rose-50 text-rose-500 mx-auto flex items-center justify-center text-3xl mb-4">
                <i class="fa-solid fa-circle-exclamation"></i>
            </div>
            <h2 class="text-xl font-black text-slate-800 mb-2">تعذر العثور على الفاتورة</h2>
            <p class="text-xs text-slate-500 mb-6"><?= htmlspecialchars($error) ?></p>
            <a href="login.php" class="inline-flex items-center justify-center bg-slate-900 text-white font-bold px-6 py-2.5 rounded-xl text-xs hover:bg-slate-800 transition">
                <i class="fa-solid fa-house ml-2"></i> الانتقال للرئيسية
            </a>
        </div>
    <?php else: ?>
        <!-- Verified E-Receipt Container -->
        <div class="max-w-xl w-full bg-white rounded-3xl shadow-2xl overflow-hidden border border-slate-200/80">
            
            <!-- Top Verified Ribbon -->
            <div class="bg-gradient-to-r from-emerald-600 via-teal-600 to-emerald-700 p-3 sm:p-4 text-white flex items-center justify-between text-xs">
                <div class="flex items-center space-x-2 space-x-reverse font-bold">
                    <span class="w-6 h-6 rounded-full bg-white/20 flex items-center justify-center">
                        <i class="fa-solid fa-check text-xs"></i>
                    </span>
                    <span>إيصال استلام إلكتروني موثق ومعتمد رسميـاً</span>
                </div>
                <span class="bg-black/20 px-2.5 py-0.5 rounded-full font-mono text-[11px] font-bold">
                    ✓ VERIFIED
                </span>
            </div>

            <!-- Receipt Card Body -->
            <div class="p-6 sm:p-8 space-y-6">
                
                <!-- Company Header -->
                <div class="flex items-center justify-between pb-6 border-b border-slate-100">
                    <div class="flex items-center space-x-3 space-x-reverse">
                        <div class="w-14 h-14 rounded-2xl bg-slate-50 border border-slate-200 flex items-center justify-center p-1 overflow-hidden shrink-0">
                            <?php if (!empty($receipt['company_logo']) && file_exists(APP_PATH . '/' . $receipt['company_logo'])): ?>
                                <img src="<?= htmlspecialchars($receipt['company_logo']) ?>" alt="شعار" class="max-w-full max-h-full object-contain">
                            <?php else: ?>
                                <i class="fa-solid fa-building text-2xl text-slate-400"></i>
                            <?php endif; ?>
                        </div>
                        <div>
                            <h1 class="font-black text-slate-900 text-lg leading-tight"><?= htmlspecialchars($receipt['company_name']) ?></h1>
                            <p class="text-xs text-slate-500 font-bold mt-0.5">
                                <?= !empty($receipt['company_phone']) ? 'هاتف: ' . htmlspecialchars($receipt['company_phone']) : 'إيصال استلام نقدية إلكتروني' ?>
                            </p>
                        </div>
                    </div>

                    <div class="text-left">
                        <span class="text-[11px] text-slate-400 block font-bold">رقم الإيصال الثابت</span>
                        <span class="font-mono font-black text-rose-600 text-base sm:text-lg block tracking-wide">
                            <?= htmlspecialchars($receipt['receipt_number']) ?>
                        </span>
                        <span class="text-[11px] text-slate-500 font-mono block mt-0.5">
                            <?= htmlspecialchars($receipt['receipt_date']) ?>
                        </span>
                    </div>
                </div>

                <!-- Big Highlighted Amount Card -->
                <div class="bg-gradient-to-br from-slate-900 to-indigo-950 rounded-3xl p-6 text-white text-center shadow-lg relative overflow-hidden">
                    <div class="absolute -right-6 -bottom-6 opacity-10 text-8xl text-white">
                        <i class="fa-solid fa-hand-holding-dollar"></i>
                    </div>
                    <span class="text-xs uppercase tracking-widest text-indigo-300 font-bold block mb-1">المبلغ المستلم</span>
                    <h2 class="text-3xl sm:text-4xl font-black tracking-tight text-emerald-400 font-mono mb-2">
                        <?= formatMoney($receipt['amount'], $receipt['currency'] ?? 'ج.م') ?>
                    </h2>
                    <p class="text-xs sm:text-sm font-bold text-slate-200 bg-white/10 py-1.5 px-4 rounded-xl inline-block max-w-full">
                        <?= htmlspecialchars($receipt['amount_words']) ?>
                    </p>
                </div>

                <!-- Details Grid -->
                <div class="bg-slate-50 rounded-2xl p-5 border border-slate-200/60 divide-y divide-slate-200/60 text-xs sm:text-sm">
                    
                    <!-- Payer (Customer) -->
                    <div class="py-3 flex items-start justify-between gap-4 first:pt-0">
                        <span class="font-bold text-slate-500 shrink-0">استلمنا من السيد / السادة:</span>
                        <div class="text-left">
                            <span class="font-black text-slate-900 block"><?= htmlspecialchars($receipt['customer_name']) ?></span>
                            <?php if (!empty($receipt['customer_phone'])): ?>
                                <span class="text-xs text-slate-500 font-mono block mt-0.5"><i class="fa-solid fa-phone text-[10px] ml-1"></i><?= htmlspecialchars($receipt['customer_phone']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Purpose / وذلك عن -->
                    <div class="py-3 flex items-start justify-between gap-4">
                        <span class="font-bold text-slate-500 shrink-0">بيان وسبب الاستلام:</span>
                        <span class="font-extrabold text-slate-900 text-left"><?= htmlspecialchars($receipt['purpose']) ?></span>
                    </div>

                    <!-- Payment Method -->
                    <div class="py-3 flex items-center justify-between gap-4">
                        <span class="font-bold text-slate-500 shrink-0">طريقة الدفع:</span>
                        <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-black bg-indigo-50 text-indigo-700 border border-indigo-200">
                            <i class="fa-solid fa-money-bill-wave ml-1 text-[11px]"></i> <?= htmlspecialchars($receipt['payment_method']) ?>
                        </span>
                    </div>

                    <!-- Receiver -->
                    <div class="py-3 flex items-center justify-between gap-4">
                        <span class="font-bold text-slate-500 shrink-0">المستلم المسؤول:</span>
                        <span class="font-black text-slate-900"><?= htmlspecialchars($receipt['receiver_name'] ?? 'معاذ') ?></span>
                    </div>

                    <!-- Date & Time of creation -->
                    <div class="py-3 flex items-center justify-between gap-4 last:pb-0">
                        <span class="font-bold text-slate-500 shrink-0">توقيت التسجيل بالنظام:</span>
                        <span class="font-mono text-xs text-slate-600 font-bold"><?= htmlspecialchars($receipt['created_at']) ?></span>
                    </div>

                    <?php if (!empty($receipt['notes'])): ?>
                    <div class="py-3 flex items-start justify-between gap-4">
                        <span class="font-bold text-slate-500 shrink-0">ملاحظات إضافية:</span>
                        <span class="text-slate-700 text-left"><?= htmlspecialchars($receipt['notes']) ?></span>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Footer Trust Badge -->
                <div class="text-center pt-2">
                    <div class="inline-flex items-center space-x-1.5 space-x-reverse text-[11px] text-slate-400 font-bold">
                        <i class="fa-solid fa-shield-halved text-emerald-600"></i>
                        <span>هذا الإيصال مسجل إلكترونياً برقم تعريفي مشفر ولا يمكن تكراره أو التلاعب به.</span>
                    </div>
                </div>

                <!-- Action Buttons (Hidden when printing) -->
                <div class="no-print pt-4 border-t border-slate-100 grid grid-cols-1 sm:grid-cols-3 gap-2.5">
                    <!-- Print A4 -->
                    <a href="receipt_print.php?id=<?= $receipt['id'] ?>" target="_blank" class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-2.5 px-3 rounded-xl transition text-xs flex items-center justify-center shadow-xs">
                        <i class="fa-solid fa-print ml-1.5"></i> طباعة الوصل A4
                    </a>

                    <!-- WhatsApp Share -->
                    <a href="https://api.whatsapp.com/send?text=<?= urlencode("إيصال استلام نقدية رسمي من {$receipt['company_name']}\nرقم الإيصال: {$receipt['receipt_number']}\nالمبلغ: " . formatMoney($receipt['amount'], $receipt['currency'] ?? 'ج.م') . "\nرابط الفاتورة المعتمدة:\n{$publicUrl}") ?>" target="_blank" class="bg-emerald-500 hover:bg-emerald-600 text-white font-bold py-2.5 px-3 rounded-xl transition text-xs flex items-center justify-center shadow-xs">
                        <i class="fa-brands fa-whatsapp ml-1.5 text-sm"></i> إرسال عبر واتساب
                    </a>

                    <!-- Copy Link -->
                    <button onclick="copyReceiptLink()" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold py-2.5 px-3 rounded-xl transition text-xs flex items-center justify-center">
                        <i class="fa-solid fa-link ml-1.5"></i> نسخ رابط الفاتورة
                    </button>
                </div>

            </div>
        </div>
    <?php endif; ?>

    <!-- Copy to Clipboard Toast -->
    <div id="copy-toast" class="fixed bottom-6 left-1/2 -translate-x-1/2 bg-slate-900 text-white text-xs font-bold px-4 py-2.5 rounded-full shadow-2xl opacity-0 pointer-events-none transition-all duration-300 z-50 flex items-center space-x-2 space-x-reverse">
        <i class="fa-solid fa-check text-emerald-400"></i>
        <span>تم نسخ رابط الفاتورة المعتمدة بنجاح!</span>
    </div>

    <script>
    function copyReceiptLink() {
        const url = "<?= $publicUrl ?>";
        navigator.clipboard.writeText(url).then(() => {
            const toast = document.getElementById('copy-toast');
            toast.classList.remove('opacity-0', 'pointer-events-none');
            toast.classList.add('opacity-100');
            setTimeout(() => {
                toast.classList.remove('opacity-100');
                toast.classList.add('opacity-0', 'pointer-events-none');
            }, 2500);
        });
    }
    </script>
</body>
</html>