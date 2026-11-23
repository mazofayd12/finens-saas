<?php
require_once __DIR__ . '/config/app.php';

$invoiceId = (int)($_GET['id'] ?? 0);
if ($invoiceId <= 0) {
    die('<div style="text-align:center;padding:50px;font-family:sans-serif;direction:rtl;"><h2>رقم الفاتورة غير محدد.</h2></div>');
}

$db = getDBConnection();

// Fetch Invoice details with Company
$stmt = $db->prepare("
    SELECT i.*, 
           c.name as company_name, c.logo as company_logo, c.phone as company_phone,
           c.tax_number as company_tax_number, c.cr_number as company_cr_number,
           c.address as company_address,
           c.email as company_email, c.location_url as company_location, c.primary_color as company_primary_color,
           c.building_no, c.street_name, c.district, c.city, c.postal_code, c.additional_no, c.country,
           u.name as user_name
    FROM invoices i
    JOIN companies c ON i.company_id = c.id
    LEFT JOIN users u ON i.user_id = u.id
    WHERE i.id = :id
    LIMIT 1
");
$stmt->execute([':id' => $invoiceId]);
$invoice = $stmt->fetch();

if (!$invoice) {
    die('<div style="text-align:center;padding:50px;font-family:sans-serif;direction:rtl;"><h2>الفاتورة غير موجودة أو ليس لديك صلاحية لعرضها.</h2></div>');
}

// Fetch Invoice Items
$itemStmt = $db->prepare("SELECT * FROM invoice_items WHERE invoice_id = :id ORDER BY id ASC");
$itemStmt->execute([':id' => $invoiceId]);
$items = $itemStmt->fetchAll();

$autoPrint = isset($_GET['autoprint']) && $_GET['autoprint'] === '1';
$invCurrency = !empty($invoice['currency']) ? $invoice['currency'] : 'ج.م';

// Brand Navy Color from user reference image
$navyPrimary = '#03254c';

// Address & contact fallbacks matching the exact design reference
$taxNumber = !empty($invoice['company_tax_number']) ? $invoice['company_tax_number'] : '314899868200003';
$customerTaxNo = !empty($invoice['customer_tax_number']) ? $invoice['customer_tax_number'] : '310123456700003';

$arabicAddress = !empty($invoice['company_address']) ? $invoice['company_address'] : 'القصيم - بريدة - طريق ابو بكر الصديق - حي النهضة';
if (empty($invoice['company_address']) && !empty($invoice['city'])) {
    $arabicAddress = $invoice['city'] . ' - ' . ($invoice['district'] ?: '') . ' - ' . ($invoice['street_name'] ?: '');
}

$phone1 = !empty($invoice['company_phone']) ? $invoice['company_phone'] : '0532125366';
$email = !empty($invoice['company_email']) ? $invoice['company_email'] : 'dobalyoffice@gmail.com';
$englishAddress = "Al-Qassim - Buraidah - Abu Bakr Al-Siddiq Road - Al-Nahda District - {$phone1} - 0547709948 - Email: {$email}";

// Generate or fetch ZATCA QR
$qrData = $invoice['qr_data'];
if (empty($qrData)) {
    $qrData = generateZatcaTlvBase64(
        $invoice['company_name'],
        $taxNumber,
        $invoice['invoice_date'] . 'T' . date('H:i:s', strtotime($invoice['created_at'] ?? 'now')),
        (float)$invoice['total_amount'],
        (float)$invoice['tax_amount']
    );
}

$grandTotal = (float)$invoice['total_amount'];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>فاتورة إلكترونية ضريبية <?= htmlspecialchars($invoice['invoice_number']) ?> - <?= htmlspecialchars($invoice['company_name']) ?></title>
    
    <!-- Fonts & Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- QR Code Generator -->
    <script src="assets/js/qrcode.min.js"></script>

    <style>
        body {
            font-family: 'Cairo', sans-serif;
            background: #e2e8f0;
            color: #0f172a;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }

        @page {
            size: A4 portrait;
            margin: 0;
        }

        .a4-sheet {
            width: 210mm;
            min-height: 297mm;
            margin: 20px auto;
            background: #ffffff;
            box-sizing: border-box;
            box-shadow: 0 15px 35px -5px rgba(0, 0, 0, 0.15), 0 0 0 1px rgba(0, 0, 0, 0.05);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
            overflow: hidden;
        }

        .sheet-body {
            padding: 12mm 14mm 6mm 14mm;
            flex: 1;
            display: flex;
            flex-direction: column;
            position: relative;
            z-index: 10;
        }

        /* Items Table Styling */
        .inv-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
        }

        .inv-table thead tr {
            background-color: <?= $navyPrimary ?> !important;
            color: #ffffff !important;
        }

        .inv-table th {
            border: 1px solid #1e3a5f;
            padding: 6px 4px;
            font-weight: 800;
            line-height: 1.25;
            text-align: center;
        }

        .inv-table th .en-sub {
            display: block;
            font-size: 9.5px;
            font-weight: 500;
            opacity: 0.9;
            font-family: ui-sans-serif, system-ui, sans-serif;
        }

        .inv-table td {
            border: 1px solid #cbd5e1;
            padding: 6px 5px;
            text-align: center;
            color: #0f172a;
            font-size: 11px;
            background: #ffffff;
        }

        .inv-table tbody tr:nth-child(even) td {
            background-color: #f8fafc;
        }

        /* Watermark */
        .watermark-container {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            opacity: 0.04;
            pointer-events: none;
            z-index: 1;
            max-width: 420px;
            max-height: 420px;
        }

        #qrcode canvas,
        #qrcode img {
            width: 92px !important;
            height: 92px !important;
            display: block;
            margin: 0 auto;
            image-rendering: pixelated;
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
            .a4-sheet {
                width: 100% !important;
                min-height: 297mm !important;
                height: 297mm !important;
                margin: 0 !important;
                box-shadow: none !important;
                border: none !important;
                page-break-after: avoid;
                page-break-inside: avoid;
            }
            .sheet-body {
                padding: 10mm 12mm 4mm 12mm !important;
            }
        }
    </style>
</head>
<body class="py-6">

    <!-- Top Action Bar (Hidden on print) -->
    <div class="no-print max-w-4xl mx-auto mb-6 px-4">
        <div class="bg-white rounded-2xl p-4 shadow-lg border border-slate-200 flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center space-x-3 space-x-reverse">
                <a href="invoices.php" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold px-3 py-2 rounded-xl text-xs transition flex items-center">
                    <i class="fa-solid fa-arrow-right ml-1.5"></i>
                    العودة للفواتير
                </a>
                <div>
                    <h2 class="text-sm font-black text-slate-900">معاينة الفاتورة الإلكترونية الرسمية (A4)</h2>
                    <span class="text-[11px] text-slate-500">رقم الفاتورة: <strong class="text-indigo-900 font-mono"><?= htmlspecialchars($invoice['invoice_number']) ?></strong></span>
                </div>
            </div>

            <div class="flex items-center space-x-2 space-x-reverse">
                <button onclick="window.print()" class="text-white font-black px-6 py-2.5 rounded-xl shadow-md transition text-xs flex items-center" style="background-color: <?= $navyPrimary ?>;">
                    <i class="fa-solid fa-print ml-2 text-sm"></i>
                    طباعة الفاتورة الآن (Print A4)
                </button>
            </div>
        </div>
    </div>

    <!-- Official A4 Sheet (Exact Match of User Design Image) -->
    <div class="a4-sheet">

        <!-- Background Watermark -->
        <?php if (!empty($invoice['company_logo']) && file_exists(APP_PATH . '/' . $invoice['company_logo'])): ?>
            <img src="<?= htmlspecialchars($invoice['company_logo']) ?>" alt="Watermark" class="watermark-container">
        <?php else: ?>
            <div class="watermark-container flex items-center justify-center">
                <i class="fa-solid fa-building text-[320px] text-slate-900"></i>
            </div>
        <?php endif; ?>

        <!-- Sheet Body -->
        <div class="sheet-body">

            <!-- 1. Top Header Grid: Customer Info (Left) | Invoice No. & VAT (Center) | Company Logo (Right) -->
            <div class="flex items-start justify-between pb-6 border-b border-slate-200/80">
                
                <!-- Right: Company Logo -->
                <div class="w-1/3 flex justify-start items-center">
                    <?php if (!empty($invoice['company_logo']) && file_exists(APP_PATH . '/' . $invoice['company_logo'])): ?>
                        <img src="<?= htmlspecialchars($invoice['company_logo']) ?>" alt="<?= htmlspecialchars($invoice['company_name']) ?>" class="max-h-24 max-w-[200px] object-contain">
                    <?php else: ?>
                        <div class="flex items-center space-x-3 space-x-reverse">
                            <div class="w-16 h-16 rounded-2xl flex items-center justify-center text-white text-2xl shadow-sm" style="background: linear-gradient(135deg, <?= $navyPrimary ?> 0%, #0d9488 100%);">
                                <i class="fa-solid fa-cube"></i>
                            </div>
                            <div>
                                <h1 class="text-base font-black text-slate-900 leading-tight"><?= htmlspecialchars($invoice['company_name']) ?></h1>
                                <p class="text-[11px] font-bold text-slate-500">للمقاولات والاستثمار العقاري</p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Center: Invoice Number & Company VAT Number -->
                <div class="w-1/3 text-center pt-1">
                    <div class="text-2xl font-black tracking-wide" style="color: <?= $navyPrimary ?>; font-family: 'Cairo', sans-serif;">
                        <?= htmlspecialchars($invoice['invoice_number']) ?>
                    </div>
                    <div class="text-sm font-black tracking-wider mt-0.5" style="color: <?= $navyPrimary ?>; font-family: 'Courier New', monospace;">
                        VAT-<?= htmlspecialchars($taxNumber) ?>
                    </div>
                </div>

                <!-- Left: Customer Information with Circular Profile Icon -->
                <div class="w-1/3 flex justify-end">
                    <div class="flex items-start space-x-3 space-x-reverse text-right">
                        <div>
                            <div class="text-sm font-black text-slate-900 leading-tight">بيانات العميل</div>
                            <div class="text-[10.5px] font-semibold text-slate-500 font-sans leading-tight mb-1">Customer Information</div>
                            <div class="text-sm font-black text-slate-900 mt-0.5"><?= htmlspecialchars($invoice['customer_name']) ?></div>
                            <div class="text-xs font-mono font-bold text-slate-700 mt-0.5">
                                VAT No. : <?= htmlspecialchars($customerTaxNo) ?>
                            </div>
                            <?php if (!empty($invoice['customer_phone'])): ?>
                                <div class="text-[11px] font-mono text-slate-500 mt-0.5">
                                    هاتف: <?= htmlspecialchars($invoice['customer_phone']) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="w-10 h-10 rounded-full flex items-center justify-center shrink-0 mt-0.5" style="background-color: <?= $navyPrimary ?>; color: #ffffff;">
                            <i class="fa-solid fa-user text-lg"></i>
                        </div>
                    </div>
                </div>

            </div>

            <!-- 2. Items Table (10 Columns Exact Match of Reference Image) -->
            <div class="pt-6 flex-1">
                <table class="inv-table">
                    <thead>
                        <tr>
                            <th style="width: 32px;">
                                <div>م</div>
                                <span class="en-sub">No.</span>
                            </th>
                            <th style="text-align: right; padding-right: 10px;">
                                <div>وصف الصنف أو الخدمة</div>
                                <span class="en-sub" style="text-align: right;">Description</span>
                            </th>
                            <th style="width: 55px;">
                                <div>الكمية</div>
                                <span class="en-sub">Unit</span>
                            </th>
                            <th style="width: 55px;">
                                <div>الوحدة</div>
                                <span class="en-sub">Qty</span>
                            </th>
                            <th style="width: 75px;">
                                <div>سعر الوحدة</div>
                                <span class="en-sub">User Price</span>
                            </th>
                            <th style="width: 65px;">
                                <div>الالخصم</div>
                                <span class="en-sub">Discount</span>
                            </th>
                            <th style="width: 95px;">
                                <div>المبلغ الخاضع للضريبة</div>
                                <span class="en-sub">Taxable Amount</span>
                            </th>
                            <th style="width: 55px;">
                                <div>نسبة الضريبة</div>
                                <span class="en-sub">VAT %</span>
                            </th>
                            <th style="width: 85px;">
                                <div>قيمة الضريبة</div>
                                <span class="en-sub">VAT Amount</span>
                            </th>
                            <th style="width: 100px;">
                                <div>الإجمالي شامل الضريبة</div>
                                <span class="en-sub">Total Incl. VAT</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($items)): ?>
                            <!-- Fallback Demo Rows matching user reference if empty -->
                            <tr>
                                <td class="font-mono font-bold">1</td>
                                <td class="text-right font-bold pr-2.5">أعمال مقاولات عامة وتجهيزات إنشائية</td>
                                <td class="font-mono">100</td>
                                <td>متر</td>
                                <td class="font-mono">500.00</td>
                                <td class="font-mono">0.00</td>
                                <td class="font-mono">50,000.00</td>
                                <td class="font-mono">15%</td>
                                <td class="font-mono">7,500.00</td>
                                <td class="font-mono font-black">57,500.00</td>
                            </tr>
                            <tr>
                                <td class="font-mono font-bold">2</td>
                                <td class="text-right font-bold pr-2.5">توريد مواد بناء وإسمنت وحديد</td>
                                <td class="font-mono">50</td>
                                <td>طن</td>
                                <td class="font-mono">800.00</td>
                                <td class="font-mono">0.00</td>
                                <td class="font-mono">40,000.00</td>
                                <td class="font-mono">15%</td>
                                <td class="font-mono">6,000.00</td>
                                <td class="font-mono font-black">46,000.00</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($items as $idx => $it): 
                                $qty = (float)$it['quantity'];
                                $price = (float)$it['unit_price'];
                                $discount = (float)$it['discount_amount'];
                                $taxable = ($qty * $price) - $discount;
                                $vatRate = (float)$it['tax_rate'];
                                $vatAmount = (float)$it['tax_amount'];
                                $total = (float)$it['total_amount'];
                            ?>
                                <tr>
                                    <td class="font-mono font-bold"><?= $idx + 1 ?></td>
                                    <td class="text-right font-bold pr-2.5"><?= htmlspecialchars($it['item_name']) ?></td>
                                    <td class="font-mono"><?= $qty == (int)$qty ? (int)$qty : number_format($qty, 2) ?></td>
                                    <td><?= htmlspecialchars($it['unit'] ?: 'متر') ?></td>
                                    <td class="font-mono"><?= number_format($price, 2) ?></td>
                                    <td class="font-mono"><?= number_format($discount, 2) ?></td>
                                    <td class="font-mono"><?= number_format($taxable, 2) ?></td>
                                    <td class="font-mono"><?= (int)$vatRate ?>%</td>
                                    <td class="font-mono"><?= number_format($vatAmount, 2) ?></td>
                                    <td class="font-mono font-black"><?= number_format($total, 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <!-- 3. Grand Total Bar (Under Table, Exact Match of Reference Image) -->
                <div class="flex justify-end mt-2">
                    <div class="flex items-stretch overflow-hidden rounded-xs border border-slate-400" style="background-color: <?= $navyPrimary ?>; color: #ffffff;">
                        <div class="py-2.5 px-6 text-center border-l border-white/20">
                            <div class="text-xs font-black leading-tight">الإجمالي شامل الضريبة</div>
                            <div class="text-[9.5px] font-sans font-semibold opacity-90 leading-tight">Grand Total (Incl. VAT)</div>
                        </div>
                        <div class="py-2.5 px-8 text-center flex items-center justify-center font-mono font-black text-lg tracking-wider" style="min-width: 170px;">
                            <?= number_format($grandTotal, 2) ?>
                        </div>
                    </div>
                </div>

            </div>

            <!-- 4. Bottom Area: ZATCA QR Code & Verification (Left Side) -->
            <div class="pt-8 pb-4 flex items-end justify-between">
                <!-- QR Code & Scan text on the Left -->
                <div class="flex flex-col items-center">
                    <div id="qrcode" class="p-1 bg-white border border-slate-300 rounded shadow-xs"></div>
                    <div class="text-[10px] text-center mt-1.5 text-slate-800 leading-tight">
                        <div class="font-bold">امسح الرمز للتحقق من الفاتورة</div>
                        <div class="font-sans text-[8.5px] text-slate-500">Scan QR Code to Verify Invoice</div>
                    </div>
                </div>

                <!-- Date & Seller stamp/meta -->
                <div class="text-left text-[11px] text-slate-500 space-y-0.5">
                    <div><strong>تاريخ الإصدار:</strong> <span class="font-mono"><?= htmlspecialchars($invoice['invoice_date']) ?></span></div>
                    <div><strong>طريقة السداد:</strong> <span class="font-bold text-slate-700"><?= htmlspecialchars($invoice['payment_method']) ?></span></div>
                </div>
            </div>

        </div>

        <!-- 5. Solid Deep Navy Footer Bar (Full Width, Matching Image Exactly) -->
        <div class="w-full text-center py-3 px-4" style="background-color: <?= $navyPrimary ?>; color: #ffffff;">
            <div class="text-base font-extrabold tracking-wide mb-0.5">
                <?= htmlspecialchars($arabicAddress) ?>
            </div>
            <div class="text-[10px] font-sans font-semibold tracking-normal opacity-95">
                <?= htmlspecialchars($englishAddress) ?>
            </div>
        </div>

    </div>

    <!-- Generate ZATCA QR Code via QRCode.js -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var qrText = <?= json_encode($qrData) ?>;
            var container = document.getElementById("qrcode");
            if (container && typeof QRCode !== 'undefined') {
                new QRCode(container, {
                    text: qrText,
                    width: 92,
                    height: 92,
                    colorDark: "#000000",
                    colorLight: "#ffffff",
                    correctLevel: QRCode.CorrectLevel.M
                });
            }
        });
    </script>

    <?php if ($autoPrint): ?>
        <script>
            window.addEventListener('load', function() {
                setTimeout(function() {
                    window.print();
                }, 600);
            });
        </script>
    <?php endif; ?>

</body>
</html>
