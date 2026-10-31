<?php
require_once __DIR__ . '/config/app.php';
requireLogin();

$currentUser = getCurrentUser();
$activeCompanyId = (int)$currentUser['active_company_id'];
$currency = $currentUser['currency'] ?? 'ج.م';
$db = getDBConnection();

$receiptId = (int)($_GET['id'] ?? 0);
if ($receiptId <= 0) {
    die('رقم الإيصال غير محدد.');
}

// Fetch receipt with company and receiver details
$stmt = $db->prepare("
    SELECT r.*, c.name as company_name, c.phone as company_phone, c.logo as company_logo, u.name as receiver_name
    FROM receipts r
    JOIN companies c ON r.company_id = c.id
    LEFT JOIN users u ON r.user_id = u.id
    WHERE r.id = :id AND r.company_id = :cid
    LIMIT 1
");
$stmt->execute([':id' => $receiptId, ':cid' => $activeCompanyId]);
$receipt = $stmt->fetch();

if (!$receipt) {
    die('الإيصال غير موجود أو ليس لديك صلاحية لعرضه.');
}

$autoPrint = isset($_GET['autoprint']) && $_GET['autoprint'] === '1';

$receiptCurrency = !empty($receipt['currency']) ? $receipt['currency'] : ($currentUser['currency'] ?? 'ج.م');
$receiptPublicUrl = getReceiptPublicUrl($receipt);

// 3 Voucher copy titles
$copies = [
    ['title' => 'أصل - نسخة العميل', 'color' => 'bg-emerald-50 text-emerald-800 border-emerald-300'],
    ['title' => 'صورة - نسخة الحسابات والخزنة', 'color' => 'bg-indigo-50 text-indigo-800 border-indigo-300'],
    ['title' => 'صورة - نسخة الأرشيف والسجلات', 'color' => 'bg-slate-100 text-slate-800 border-slate-300']
];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>طباعة إيصال استلام نقدية <?= htmlspecialchars($receipt['receipt_number']) ?> - 3 وصولات A4</title>
    
    <!-- Fonts & Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>

    <style>
        body {
            font-family: 'Cairo', sans-serif;
            background: #f1f5f9;
            color: #0f172a;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }

        @page {
            size: A4 portrait;
            margin: 6mm 8mm;
        }

        .a4-page {
            width: 194mm;
            margin: 0 auto;
            background: #ffffff;
            box-sizing: border-box;
        }

        .receipt-slip {
            height: 87mm;
            max-height: 87mm;
            box-sizing: border-box;
            border: 2px solid #1e293b;
            border-radius: 10px;
            padding: 8px 14px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            page-break-inside: avoid;
            background: #ffffff;
            position: relative;
            overflow: hidden;
        }

        .cut-line {
            height: 7mm;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }

        .cut-line::before {
            content: '';
            position: absolute;
            left: 0;
            right: 0;
            top: 50%;
            border-top: 1.5px dashed #94a3b8;
        }

        .cut-badge {
            background: #ffffff;
            padding: 0 10px;
            position: relative;
            z-index: 1;
            font-size: 10px;
            color: #64748b;
            font-weight: bold;
        }

        @media print {
            .no-print {
                display: none !important;
            }
            body {
                background: #ffffff !important;
                padding: 0 !important;
                margin: 0 !important;
            }
            .a4-page {
                box-shadow: none !important;
                border: none !important;
                width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
            }
            .receipt-slip {
                border-color: #000000 !important;
            }
        }
    </style>
</head>
<body class="py-6">

    <!-- Top Action Bar (Hidden on print) -->
    <div class="no-print max-w-4xl mx-auto mb-6 px-4">
        <div class="bg-white rounded-2xl p-4 shadow-lg border border-slate-200 flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center space-x-3 space-x-reverse">
                <a href="receipts.php" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold px-3 py-2 rounded-xl text-xs transition flex items-center">
                    <i class="fa-solid fa-arrow-right ml-1.5"></i>
                    العودة لسجل الإيصالات
                </a>
                <div>
                    <h2 class="text-sm font-black text-slate-900">معاينة طباعة ورقة A4 (3 وصولات استلام فلوس)</h2>
                    <span class="text-[11px] text-slate-500">رقم الإيصال الثابت: <strong><?= htmlspecialchars($receipt['receipt_number']) ?></strong></span>
                </div>
            </div>

            <div class="flex items-center space-x-2 space-x-reverse">
                <a href="<?= htmlspecialchars($receiptPublicUrl) ?>" target="_blank" class="bg-indigo-50 hover:bg-indigo-100 text-indigo-700 border border-indigo-200 font-bold px-3 py-2 rounded-xl text-xs transition flex items-center">
                    <i class="fa-solid fa-arrow-up-right-from-square ml-1.5 text-xs"></i>
                    فتح الفاتورة أونلاين
                </a>
                <button onclick="window.print()" class="bg-emerald-600 hover:bg-emerald-700 text-white font-black px-5 py-2.5 rounded-xl shadow-md transition text-xs flex items-center">
                    <i class="fa-solid fa-print ml-2 text-sm"></i>
                    طباعة الورقة الآن (Print A4)
                </button>
            </div>
        </div>
    </div>

    <!-- The Single A4 Printable Page Container -->
    <div class="a4-page shadow-2xl p-0">
        <?php foreach ($copies as $idx => $copy): ?>
            <!-- RECEIPT SLIP -->
            <div class="receipt-slip">
                <!-- Watermark Background -->
                <div class="absolute inset-0 flex items-center justify-center opacity-[0.03] pointer-events-none select-none">
                    <span class="text-7xl font-black text-slate-900 rotate-[-15deg]">إيصال استلام نقدية</span>
                </div>

                <!-- Top Header: Logo + Title + Receipt ID & Date -->
                <div class="flex items-center justify-between pb-2 border-b border-slate-300 relative z-10">
                    <!-- Right: Company Info & Logo -->
                    <div class="flex items-center space-x-3 space-x-reverse">
                        <div class="w-14 h-14 max-h-14 rounded-lg bg-slate-50 border border-slate-200 flex items-center justify-center p-1 overflow-hidden shrink-0">
                            <?php if (!empty($receipt['company_logo']) && file_exists(APP_PATH . '/' . $receipt['company_logo'])): ?>
                                <img src="<?= htmlspecialchars($receipt['company_logo']) ?>" alt="شعار" class="max-w-full max-h-full object-contain">
                            <?php else: ?>
                                <i class="fa-solid fa-building text-2xl text-slate-400"></i>
                            <?php endif; ?>
                        </div>
                        <div>
                            <h1 class="font-black text-slate-900 text-base leading-tight"><?= htmlspecialchars($receipt['company_name']) ?></h1>
                            <p class="text-[10px] text-slate-500 font-bold mt-0.5">
                                <?= !empty($receipt['company_phone']) ? 'هاتف: ' . htmlspecialchars($receipt['company_phone']) : 'إيصال معتمد' ?>
                            </p>
                        </div>
                    </div>

                    <!-- Center: Title Badge -->
                    <div class="text-center">
                        <div class="inline-block px-3 py-1 bg-slate-900 text-white font-black text-xs rounded-lg tracking-wider shadow-xs">
                            إيصال استلام نقدية
                        </div>
                        <p class="text-[9px] font-bold text-slate-400 mt-0.5 tracking-widest uppercase">CASH RECEIPT VOUCHER</p>
                    </div>

                    <!-- Left: Receipt Permanent ID & Date & Copy Type -->
                    <div class="text-left flex flex-col items-end">
                        <span class="inline-block px-2 py-0.5 rounded text-[9px] font-black border <?= $copy['color'] ?> mb-1">
                            <?= $copy['title'] ?>
                        </span>
                        <div class="text-xs font-mono font-black text-slate-900">
                            رقم: <span class="text-rose-600 font-black text-sm"><?= htmlspecialchars($receipt['receipt_number']) ?></span>
                        </div>
                        <div class="text-[10px] font-bold text-slate-600 mt-0.5">
                            التاريخ: <span class="font-mono"><?= htmlspecialchars($receipt['receipt_date']) ?></span>
                        </div>
                    </div>
                </div>

                <!-- Middle Content Table / Form -->
                <div class="py-2 space-y-1.5 text-xs text-slate-800 relative z-10 flex-1">
                    <!-- Amount Highlight Banner -->
                    <div class="flex items-center justify-between bg-slate-50 border border-slate-300 rounded-lg px-3 py-1.5">
                        <div class="flex items-center space-x-2 space-x-reverse">
                            <span class="font-extrabold text-slate-600 text-xs">المبلغ المستلم:</span>
                            <span class="font-black text-base text-emerald-700 font-mono tracking-tight">
                                <?= formatMoney($receipt['amount'], $receiptCurrency) ?>
                            </span>
                        </div>
                        <div class="text-[11px] font-bold text-slate-700">
                            طريقة الدفع: <span class="font-black text-slate-900"><?= htmlspecialchars($receipt['payment_method']) ?></span>
                        </div>
                    </div>

                    <!-- Payer Line -->
                    <div class="flex items-center text-xs">
                        <span class="font-bold text-slate-600 w-36 shrink-0">استلمنا من السيد / السادة:</span>
                        <span class="font-black text-slate-900 text-xs flex-1 border-b border-dotted border-slate-400 pb-0.5">
                            <?= htmlspecialchars($receipt['customer_name']) ?>
                            <?php if (!empty($receipt['customer_phone'])): ?>
                                <span class="text-[10px] font-mono text-slate-600 mr-2">(هاتف: <?= htmlspecialchars($receipt['customer_phone']) ?>)</span>
                            <?php endif; ?>
                        </span>
                    </div>

                    <!-- Amount in words (Tafqeet) -->
                    <div class="flex items-center text-xs">
                        <span class="font-bold text-slate-600 w-36 shrink-0">مبلـغ وقـدره (كتابةً):</span>
                        <span class="font-black text-indigo-900 text-xs flex-1 border-b border-dotted border-slate-400 pb-0.5">
                            <?= htmlspecialchars($receipt['amount_words']) ?>
                        </span>
                    </div>

                    <!-- Purpose / Reason -->
                    <div class="flex items-start text-xs">
                        <span class="font-bold text-slate-600 w-36 shrink-0">وذلـك عـن (البيان):</span>
                        <span class="font-bold text-slate-900 text-xs flex-1 border-b border-dotted border-slate-400 pb-0.5 leading-snug">
                            <?= htmlspecialchars($receipt['purpose']) ?>
                            <?php if (!empty($receipt['notes'])): ?>
                                <span class="text-slate-500 font-normal mr-1">- <?= htmlspecialchars($receipt['notes']) ?></span>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>

                <!-- Footer Signatures & Stamp -->
                <div class="pt-2 border-t border-slate-300 relative z-10 grid grid-cols-4 gap-2 text-center text-[11px]">
                    <!-- Receiver Name & Signature -->
                    <div class="border-l border-slate-200 pl-1">
                        <span class="text-slate-500 font-bold block text-[10px]">المستلم المسؤول</span>
                        <span class="font-black text-slate-900 block truncate"><?= htmlspecialchars($receipt['receiver_name'] ?? 'معاذ') ?></span>
                        <span class="text-slate-400 block text-[9px] mt-2">التوقيع: ....................</span>
                    </div>

                    <!-- Payer Signature -->
                    <div class="border-l border-slate-200 pl-1">
                        <span class="text-slate-500 font-bold block text-[10px]">توقيع الدافع (العميل)</span>
                        <div class="h-4"></div>
                        <span class="text-slate-400 block text-[9px] mt-2">التوقيع: ....................</span>
                    </div>

                    <!-- Accountant / Auditor / Stamp -->
                    <div class="border-l border-slate-200 pl-1">
                        <span class="text-slate-500 font-bold block text-[10px]">اعتماد الحسابات / الخاتم</span>
                        <div class="h-3.5"></div>
                        <span class="text-slate-400 block text-[9px] mt-1.5">الاعتماد: ....................</span>
                    </div>

                    <!-- QR Code Electronic Verification -->
                    <div class="flex flex-col items-center justify-center">
                        <div class="qr-code-box w-12 h-12 flex items-center justify-center bg-white border border-slate-300 rounded p-0.5" id="qr-code-slip-<?= $idx ?>">
                            <img src="https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=<?= urlencode($receiptPublicUrl) ?>" alt="QR" class="w-11 h-11 object-contain qr-img">
                        </div>
                        <span class="text-slate-600 font-black block text-[8px] mt-0.5 leading-tight tracking-tighter">
                            <i class="fa-solid fa-qrcode text-indigo-600 ml-0.5"></i> امسح للفاتورة أونلاين
                        </span>
                    </div>
                </div>
            </div>

            <!-- Scissors Cut Line between slips (only after slip 1 and 2) -->
            <?php if ($idx < 2): ?>
                <div class="cut-line">
                    <span class="cut-badge">
                        <i class="fa-solid fa-scissors ml-1 text-slate-400"></i> قص هنا (خط فصل الإيصالات)
                    </span>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <!-- QR Code Script -->
    <script src="assets/js/qrcode.min.js"></script>
    <script>
        const targetUrl = "<?= $receiptPublicUrl ?>";
        try {
            document.querySelectorAll('.qr-code-box').forEach((box) => {
                box.innerHTML = '';
                new QRCode(box, {
                    text: targetUrl,
                    width: 44,
                    height: 44,
                    colorDark: "#000000",
                    colorLight: "#ffffff",
                    correctLevel: QRCode.CorrectLevel.M
                });
            });
        } catch (e) {
            console.log('Using fallback QR image');
        }
    </script>

    <?php if ($autoPrint): ?>
    <script>
        window.addEventListener('load', () => {
            setTimeout(() => {
                window.print();
            }, 600);
        });
    </script>
    <?php endif; ?>

</body>
</html>
