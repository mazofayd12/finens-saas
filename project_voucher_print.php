<?php
require_once __DIR__ . '/config/app.php';

$token = trim($_GET['token'] ?? '');
$expenseId = (int)($_GET['id'] ?? 0);
$db = getDBConnection();
$voucher = null;

if (!empty($token)) {
    $stmt = $db->prepare("
        SELECT e.*, 
               c.name as company_name, c.phone as company_phone, c.logo as company_logo,
               c.tax_number as company_tax_number, c.cr_number as company_cr_number, c.address as company_address,
               c.email as company_email, c.location_url as company_location, c.primary_color as company_primary_color,
               c.city, c.district, c.street_name,
               c.signature as company_signature, c.stamp as company_stamp,
               p.name as project_name, p.code as project_code,
               s.name as safe_name,
               ec.name as category_name,
               u.name as user_name, u.signature as issuer_user_sig,
               u_app.name as approver_name
        FROM expenses e
        JOIN companies c ON e.company_id = c.id
        LEFT JOIN projects p ON e.project_id = p.id
        LEFT JOIN safes s ON e.safe_id = s.id
        LEFT JOIN expense_categories ec ON e.category_id = ec.id
        LEFT JOIN users u ON e.user_id = u.id
        LEFT JOIN users u_app ON e.approved_by = u_app.id
        WHERE e.sign_token = :tok
        LIMIT 1
    ");
    $stmt->execute([':tok' => $token]);
    $voucher = $stmt->fetch();
} elseif ($expenseId > 0) {
    requireLogin();
    $currentUser = getCurrentUser();
    $activeCompanyId = (int)$currentUser['active_company_id'];
    $whereClause = isSuperAdmin() ? "WHERE e.id = :id" : "WHERE e.id = :id AND e.company_id = :cid";
    $params = isSuperAdmin() ? [':id' => $expenseId] : [':id' => $expenseId, ':cid' => $activeCompanyId];

    $stmt = $db->prepare("
        SELECT e.*, 
               c.name as company_name, c.phone as company_phone, c.logo as company_logo,
               c.tax_number as company_tax_number, c.cr_number as company_cr_number, c.address as company_address,
               c.email as company_email, c.location_url as company_location, c.primary_color as company_primary_color,
               c.city, c.district, c.street_name,
               c.signature as company_signature, c.stamp as company_stamp,
               p.name as project_name, p.code as project_code,
               s.name as safe_name,
               ec.name as category_name,
               u.name as user_name, u.signature as issuer_user_sig,
               u_app.name as approver_name
        FROM expenses e
        JOIN companies c ON e.company_id = c.id
        LEFT JOIN projects p ON e.project_id = p.id
        LEFT JOIN safes s ON e.safe_id = s.id
        LEFT JOIN expense_categories ec ON e.category_id = ec.id
        LEFT JOIN users u ON e.user_id = u.id
        LEFT JOIN users u_app ON e.approved_by = u_app.id
        {$whereClause}
        LIMIT 1
    ");
    $stmt->execute($params);
    $voucher = $stmt->fetch();
}

if (!$voucher) {
    die('<div style="text-align:center;padding:50px;font-family:sans-serif;direction:rtl;"><h2>سند الصرف غير موجود أو ليس لديك صلاحية لعرضه.</h2></div>');
}

// Never auto print popup
$autoPrint = false;

$navyPrimary = '#03254c';
$voucherCurrency = !empty($voucher['currency']) ? $voucher['currency'] : 'ر.س';
$voucherNumber = $voucher['voucher_number'] ?: ('VCH-' . $voucher['id']);
$taxNumber = !empty($voucher['company_tax_number']) ? $voucher['company_tax_number'] : '314899868200003';
$crNumber = !empty($voucher['company_cr_number']) ? $voucher['company_cr_number'] : '';

$arabicAddress = !empty($voucher['company_address']) ? $voucher['company_address'] : 'القصيم - بريدة - طريق ابو بكر الصديق - حي النهضة';
if (empty($voucher['company_address']) && !empty($voucher['city'])) {
    $arabicAddress = $voucher['city'] . ' - ' . ($voucher['district'] ?: '') . ' - ' . ($voucher['street_name'] ?: '');
}

$phone1 = !empty($voucher['company_phone']) ? $voucher['company_phone'] : '0532125366';
$email = !empty($voucher['company_email']) ? $voucher['company_email'] : 'dobalyoffice@gmail.com';
$englishAddress = "Al-Qassim - Buraidah - Abu Bakr Al-Siddiq Road - Al-Nahda District - {$phone1} - 0547709948 - Email: {$email}";

// Amount in words
$amountWords = (function_exists('tafqeetNumber') ? tafqeetNumber((float)$voucher['amount'], $voucherCurrency) : ((float)$voucher['amount'] . ' ' . $voucherCurrency));

// Dates
$gregorianDate = $voucher['expense_date'];
$hijriDate = function_exists('getHijriDate') ? getHijriDate($gregorianDate) : '';

// Number and Fraction split
$numAmount = (float)$voucher['amount'];
$wholePart = (int)floor($numAmount);
$fractionPart = (int)round(($numAmount - $wholePart) * 100);
$fractionStr = ($fractionPart > 0) ? sprintf('%02d', $fractionPart) : '—';
$wholeStr = number_format($wholePart);

// Currency labels
$currLower = mb_strtolower($voucherCurrency);
$isEgp = (str_contains($currLower, 'ج.م') || str_contains($currLower, 'egp') || str_contains($currLower, 'جنيه'));
$minorLabel = $isEgp ? 'ق . P' : 'هـ . H';
$majorLabel = $isEgp ? 'ج.م . EGP' : 'ريال . S.R';

// Payment Method
$pm = trim((string)$voucher['payment_method']);
$pmLower = mb_strtolower($pm);
$isNaqdi = false;
$isCash = false;
$isTransfer = false;
$isPos = false;
$isCheque = false;

if (str_contains($pmLower, 'كاش') || str_contains($pmLower, 'cash')) {
    $isCash = true;
} elseif (str_contains($pmLower, 'نقد')) {
    $isNaqdi = true;
} elseif (str_contains($pmLower, 'تحويل') || str_contains($pmLower, 'حوالة') || str_contains($pmLower, 'bank') || str_contains($pmLower, 'transfer')) {
    $isTransfer = true;
} elseif (str_contains($pmLower, 'شبك') || str_contains($pmLower, 'مدى') || str_contains($pmLower, 'pos') || str_contains($pmLower, 'بطاق')) {
    $isPos = true;
} elseif (str_contains($pmLower, 'شيك') || str_contains($pmLower, 'cheque') || str_contains($pmLower, 'check')) {
    $isCheque = true;
} else {
    $isNaqdi = true;
}

$bankName = $voucher['bank_name'] ?? '';
$chequeNumber = $voucher['cheque_number'] ?? '';
$chequeDate = $voucher['cheque_date'] ?? '';

// Purpose & Project Description
$purposeParts = [];
if (!empty($voucher['title'])) {
    $purposeParts[] = $voucher['title'];
}
if (!empty($voucher['project_name'])) {
    $purposeParts[] = 'مشروع: ' . $voucher['project_name'];
}
if (!empty($voucher['category_name'])) {
    $purposeParts[] = '(بند: ' . $voucher['category_name'] . ')';
}
if (!empty($voucher['description']) && $voucher['description'] !== $voucher['title']) {
    $purposeParts[] = $voucher['description'];
}
$purposeText = !empty($purposeParts) ? implode(' - ', $purposeParts) : 'صرف مبالغ مستحقة';

// QR Code url
$qrUrl = function_exists('getVoucherSignUrl') ? getVoucherSignUrl($voucher) : (getAppBaseUrl() . '/voucher_sign.php?token=' . ($voucher['sign_token'] ?? ''));

// Helper for Base64 Images (ensures print & PDF reliability)
function getSafeBase64Image(?string $path): ?string {
    if (empty($path)) return null;
    if (strpos($path, 'data:image/') === 0) return $path;
    $full = APP_PATH . '/' . ltrim($path, '/');
    if (file_exists($full) && is_file($full)) {
        $mime = mime_content_type($full) ?: 'image/png';
        return "data:{$mime};base64," . base64_encode(file_get_contents($full));
    }
    return null;
}

// Signatures & Logos
$logoBase64 = getSafeBase64Image($voucher['company_logo'] ?? '');
$recipientSigBase64 = getSafeBase64Image($voucher['recipient_signature'] ?? '');
$signedByName = $voucher['signed_by_name'] ?? ($voucher['beneficiary_name'] ?? '');
$signedAt = $voucher['signed_at'] ?? '';

$rawIssuerSig = $voucher['issuer_signature'] ?: ($voucher['issuer_user_sig'] ?: ($voucher['company_signature'] ?: ''));
$issuerSigBase64 = getSafeBase64Image($rawIssuerSig);
$accountantName = $voucher['user_name'] ?? 'إدارة الحسابات';
$approverName = $voucher['approver_name'] ?? 'المدير العام';

// Single Official Voucher configuration for 1 A4 sheet
$copy = [
    'type' => 'original',
    'badge' => 'سند أصلي معتمد',
    'badge_en' => 'Official Original Voucher',
    'badge_class' => 'copy-badge-original',
    'qr_id' => 'qrcode-single'
];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>سند صرف <?= htmlspecialchars($voucherNumber) ?> - <?= htmlspecialchars($voucher['company_name']) ?></title>
    
    <!-- Fonts & Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- QR Code Generator -->
    <script src="assets/js/qrcode.min.js"></script>

    <style>
        * {
            box-sizing: border-box;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }

        body {
            font-family: 'Cairo', sans-serif;
            background: #e2e8f0;
            color: #0f172a;
            margin: 0;
            padding: 0;
        }

        @page {
            size: A4 portrait;
            margin: 0;
        }

        /* The single A4 Page container containing exactly 1 official voucher slip */
        .a4-page {
            width: 210mm;
            height: 296mm;
            max-height: 296mm;
            margin: 15px auto;
            background: #ffffff;
            box-shadow: 0 15px 35px -5px rgba(0, 0, 0, 0.15), 0 0 0 1px rgba(0, 0, 0, 0.05);
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
            padding: 7mm 9mm;
            position: relative;
            overflow: hidden;
        }

        /* Single full A4 voucher slip */
        .voucher-slip {
            width: 100%;
            height: 100%;
            min-height: 280mm;
            max-height: 282mm;
            border: 2px solid #03254c;
            border-radius: 12px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            padding: 7mm 9mm 0 9mm;
            position: relative;
            background: #ffffff;
            overflow: hidden;
        }

        /* Watermark */
        .slip-watermark {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            opacity: 0.038;
            pointer-events: none;
            z-index: 0;
            max-width: 320px;
            max-height: 320px;
        }

        .slip-content {
            position: relative;
            z-index: 2;
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        /* 1. Slip Header (Right: Logo & Amount | Center: Title & Red Serial | Left: Company & Dates) */
        .slip-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-bottom: 3.5mm;
            border-bottom: 1.5px solid #03254c;
        }

        /* Right column in RTL: Logo & Amount Card (Exact Reference Image Match) */
        .header-right {
            width: 33%;
            display: flex;
            align-items: center;
            justify-content: flex-start;
            gap: 10px;
        }

        .logo-wrapper {
            max-width: 75px;
            max-height: 65px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .logo-wrapper img {
            max-width: 75px;
            max-height: 65px;
            object-fit: contain;
        }

        /* Amount Card (Matching the photo: هـ. H | ريال S.R) */
        .amount-card {
            display: inline-flex;
            align-items: stretch;
            border: 2px solid #03254c;
            border-radius: 8px;
            background: #ffffff;
            overflow: hidden;
            direction: rtl;
        }

        .amt-unit {
            padding: 3px 10px;
            text-align: center;
        }

        .amt-unit.fraction {
            border-left: 1px solid #94a3b8;
            background: #f8fafc;
            min-width: 42px;
        }

        .amt-unit.whole {
            background: #f0f7ff;
            min-width: 95px;
        }

        .amt-header {
            font-size: 10px;
            font-weight: 800;
            color: #475569;
            border-bottom: 1px solid #cbd5e1;
            padding-bottom: 1px;
            line-height: 1.1;
        }

        .amt-digits {
            font-family: 'Courier New', Courier, monospace;
            font-size: 18px;
            font-weight: 900;
            color: #03254c;
            padding-top: 1px;
            line-height: 1.1;
        }

        /* Header Center: Title & Red Serial & Copy Badge */
        .header-center {
            width: 34%;
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        .voucher-title-main {
            font-size: 26px;
            font-weight: 900;
            color: #03254c;
            line-height: 1.1;
            letter-spacing: -0.5px;
        }

        .voucher-title-sub {
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 2.5px;
            color: #0b3b60;
            font-family: ui-sans-serif, system-ui, sans-serif;
            margin-top: 2px;
        }

        .serial-no-box {
            margin-top: 3px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .serial-digits {
            font-family: 'Courier New', Courier, monospace;
            font-size: 19px;
            font-weight: 900;
            color: #dc2626;
            letter-spacing: 0.5px;
        }

        .copy-pill {
            font-size: 9.5px;
            font-weight: 800;
            padding: 2.5px 10px;
            border-radius: 9999px;
            margin-top: 3px;
            display: inline-block;
        }

        .copy-badge-original {
            background-color: #ecfdf5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .copy-badge-archive {
            background-color: #fffbeb;
            color: #92400e;
            border: 1px solid #fde68a;
        }

        /* Header Left in RTL: Company & Dates (Matching photo) */
        .header-left {
            width: 33%;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            text-align: left;
        }

        .company-name-ar {
            font-size: 15px;
            font-weight: 900;
            color: #03254c;
            line-height: 1.25;
            text-align: left;
            width: 100%;
        }

        .company-name-en {
            font-size: 9.5px;
            font-weight: 700;
            letter-spacing: 0.8px;
            color: #475569;
            font-family: ui-sans-serif, system-ui, sans-serif;
            text-transform: uppercase;
            margin-top: 2px;
            text-align: left;
            width: 100%;
        }

        .dates-box {
            margin-top: 4px;
            font-size: 11px;
            color: #334155;
            line-height: 1.45;
            text-align: left;
            width: 100%;
        }

        .dates-box strong {
            color: #03254c;
        }

        /* 2. Classical Voucher Body Rows */
        .voucher-body {
            padding: 8mm 0 6mm 0;
            display: flex;
            flex-direction: column;
            gap: 5.5mm;
        }

        .vch-line {
            display: flex;
            align-items: baseline;
            font-size: 13px;
            line-height: 1.35;
            position: relative;
        }

        .vch-lbl-ar {
            font-weight: 800;
            color: #03254c;
            white-space: nowrap;
            margin-left: 8px;
            font-size: 13px;
        }

        .vch-fill {
            flex: 1;
            border-bottom: 1.5px dotted #64748b;
            padding: 0 8px 2px 8px;
            min-height: 24px;
            display: flex;
            align-items: center;
        }

        .vch-fill-text {
            color: #0f172a;
            font-weight: 800;
            font-size: 13.5px;
        }

        .vch-lbl-en {
            font-size: 10.5px;
            font-family: ui-sans-serif, system-ui, sans-serif;
            font-weight: 700;
            color: #64748b;
            white-space: nowrap;
            margin-right: 8px;
            direction: ltr;
        }

        /* Payment methods row with checkboxes */
        .pm-options-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 7px;
            padding: 4px 10px;
            font-size: 11px;
            margin: 2mm 0;
        }

        .pm-checks {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .pm-item {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .pm-box {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 14px;
            height: 14px;
            border: 1.5px solid #03254c;
            border-radius: 3px;
            background: #ffffff;
            font-size: 10px;
            font-weight: 900;
            color: #03254c;
            line-height: 1;
        }

        .pm-box.active {
            background: #03254c;
            color: #ffffff;
        }

        .pm-label {
            font-weight: 700;
            color: #1e293b;
        }

        .pm-details {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 10.5px;
            color: #475569;
        }

        .pm-details strong {
            color: #03254c;
            border-bottom: 1px dotted #94a3b8;
            padding: 0 3px;
        }

        /* 3. Signatures Section */
        .slip-signatures {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            padding: 4mm 0 2mm 0;
            border-top: 1.5px solid #e2e8f0;
            margin-top: 3mm;
        }

        .sig-column {
            width: 30%;
            text-align: center;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            min-height: 30mm;
        }

        .sig-head {
            font-size: 12px;
            font-weight: 800;
            color: #03254c;
            line-height: 1.1;
        }

        .sig-head .sig-en {
            display: block;
            font-size: 9px;
            font-weight: 600;
            color: #64748b;
            font-family: ui-sans-serif, system-ui, sans-serif;
        }

        .sig-slot {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 15mm;
            position: relative;
        }

        .sig-img {
            max-height: 14mm;
            max-width: 80%;
            object-fit: contain;
        }

        .sig-dots {
            width: 75%;
            margin: 0 auto;
            border-bottom: 1.5px dotted #94a3b8;
            height: 1px;
        }

        .sig-person {
            font-size: 10.5px;
            font-weight: 700;
            color: #334155;
            line-height: 1.25;
        }

        .sig-verified {
            font-size: 9px;
            font-weight: 800;
            color: #059669;
            display: block;
        }

        /* Mini QR in signature */
        .mini-qr-box {
            width: 10%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        .mini-qr-box canvas,
        .mini-qr-box img {
            width: 44px !important;
            height: 44px !important;
            display: block;
        }

        .mini-qr-caption {
            font-size: 7.5px;
            font-weight: 700;
            color: #64748b;
            margin-top: 2px;
            text-align: center;
        }

        /* 4. Solid Deep Navy Footer Bar */
        .slip-footer-bar {
            background-color: #03254c !important;
            color: #ffffff !important;
            text-align: center;
            padding: 5px 8px;
            margin: 0 -9mm 0 -9mm;
            border-radius: 0 0 10px 10px;
        }

        .footer-ar {
            font-size: 11px;
            font-weight: 800;
            line-height: 1.25;
            letter-spacing: 0.2px;
        }

        .footer-en {
            font-size: 8.5px;
            font-family: ui-sans-serif, system-ui, sans-serif;
            opacity: 0.95;
            line-height: 1.2;
            direction: ltr;
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
                width: 210mm !important;
                height: 297mm !important;
                max-height: 297mm !important;
                margin: 0 !important;
                padding: 6mm 8mm !important;
                box-shadow: none !important;
                border: none !important;
                page-break-after: avoid !important;
                page-break-inside: avoid !important;
            }
            .voucher-slip {
                width: 100% !important;
                height: 100% !important;
                min-height: 282mm !important;
                max-height: 284mm !important;
                page-break-inside: avoid !important;
            }
        }
    </style>
</head>
<body class="py-6">

    <!-- Top Action Bar (Hidden on print) -->
    <div class="no-print max-w-4xl mx-auto mb-5 px-4">
        <div class="bg-white rounded-2xl p-4 shadow-lg border border-slate-200 flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center space-x-3 space-x-reverse">
                <?php if (isLoggedIn()): ?>
                    <a href="project_vouchers.php" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold px-3 py-2 rounded-xl text-xs transition flex items-center">
                        <i class="fa-solid fa-arrow-right ml-1.5"></i>
                        العودة لقائمة السندات
                    </a>
                <?php endif; ?>
                <div>
                    <h2 class="text-sm font-black text-slate-900 flex items-center gap-2">
                        سند صرف رسمي مقسوم A4 (نسختين للقص)
                        <?php if (!empty($voucher['is_edited'])): ?>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-black bg-amber-100 text-amber-900 border border-amber-300">
                                <i class="fa-solid fa-pen-to-square ml-1 text-amber-600"></i> سند مُعدّل
                            </span>
                        <?php endif; ?>
                    </h2>
                    <span class="text-[11px] text-slate-500">رقم السند: <strong class="text-indigo-900 font-mono"><?= htmlspecialchars($voucherNumber) ?></strong></span>
                </div>
            </div>

            <div class="flex items-center space-x-2 space-x-reverse">
                <?php if (isLoggedIn()): ?>
                    <a href="voucher_share.php?id=<?= (int)$voucher['id'] ?>" class="bg-indigo-50 hover:bg-indigo-100 text-indigo-700 font-bold px-4 py-2.5 rounded-xl text-xs transition flex items-center">
                        <i class="fa-solid fa-share-nodes ml-1.5"></i>
                        رابط السند والتوقيع
                    </a>
                <?php endif; ?>
                <button onclick="window.print()" class="text-white font-black px-6 py-2.5 rounded-xl shadow-md transition text-xs flex items-center" style="background-color: <?= $navyPrimary ?>;">
                    <i class="fa-solid fa-print ml-2 text-sm"></i>
                    طباعة سند الصرف A4
                </button>
            </div>
        </div>
    </div>

    <!-- The Single A4 Sheet containing exactly ONE voucher -->
    <div class="a4-page">

        <!-- Single Official Voucher Slip -->
        <div class="voucher-slip">

            <!-- Watermark -->
            <?php if (!empty($logoBase64)): ?>
                <img src="<?= $logoBase64 ?>" class="slip-watermark" alt="">
            <?php endif; ?>

            <div class="slip-content">

                <!-- 1. Header (Right: Logo & Amount | Center: Title & Serial | Left: Company & Dates) -->
                <div class="slip-header">
                        
                        <!-- Right: Logo & Amount Card -->
                        <div class="header-right">
                            <div class="logo-wrapper">
                                <?php if (!empty($logoBase64)): ?>
                                    <img src="<?= $logoBase64 ?>" alt="Logo">
                                <?php else: ?>
                                    <!-- Architectural building outline emblem (Matching Reference Image) -->
                                    <svg class="w-10 h-10" viewBox="0 0 100 100" fill="none" stroke="#059669" stroke-width="3.5">
                                        <rect x="25" y="25" width="50" height="55" rx="3" />
                                        <path d="M15 45 L50 20 L85 45" />
                                        <line x1="38" y1="50" x2="62" y2="50" stroke="#03254c" />
                                        <line x1="38" y1="65" x2="62" y2="65" stroke="#03254c" />
                                    </svg>
                                <?php endif; ?>
                            </div>
                            <div class="amount-card">
                                <div class="amt-unit fraction">
                                    <div class="amt-header"><?= $minorLabel ?></div>
                                    <div class="amt-digits"><?= $fractionStr ?></div>
                                </div>
                                <div class="amt-unit whole">
                                    <div class="amt-header"><?= $majorLabel ?></div>
                                    <div class="amt-digits"><?= $wholeStr ?></div>
                                </div>
                            </div>
                        </div>

                        <!-- Center: Title, Red Serial & Copy Badge -->
                        <div class="header-center">
                            <div class="voucher-title-main">سند صرف</div>
                            <div class="voucher-title-sub">PAYMENT VOUCHER</div>
                            <div class="serial-no-box">
                                <span class="serial-digits"><?= htmlspecialchars($voucherNumber) ?></span>
                            </div>
                            <div>
                                <span class="copy-pill <?= $copy['badge_class'] ?>"><?= $copy['badge'] ?> | <?= $copy['badge_en'] ?></span>
                            </div>
                            <?php if (!empty($voucher['is_edited'])): ?>
                                <div style="margin-top: 2px;">
                                    <span style="display: inline-block; padding: 1px 6px; background: #fffbeb; color: #92400e; border: 1px solid #f59e0b; border-radius: 4px; font-size: 8px; font-weight: 800;">
                                        سند مُعدّل (<?= !empty($voucher['last_edited_at']) ? date('Y-m-d', strtotime($voucher['last_edited_at'])) : 'مُحدّث' ?>)
                                    </span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Left: Company Name & Dates -->
                        <div class="header-left">
                            <div class="company-name-ar"><?= htmlspecialchars($voucher['company_name'] ?: 'مكتب النظم الهندسية للاستشارات') ?></div>
                            <div class="company-name-en">ENGINEERING & CONSULTING OFFICE</div>
                            <div class="dates-box">
                                <div><strong>التاريخ:</strong> <?= htmlspecialchars($hijriDate) ?> هـ</div>
                                <div><strong>الموافق:</strong> <?= htmlspecialchars($gregorianDate) ?> م</div>
                            </div>
                        </div>

                    </div>

                    <!-- 2. Classical Fill-in Lines (Checkbook Style) -->
                    <div class="voucher-body">

                        <!-- Line 1: Paid to -->
                        <div class="vch-line">
                            <div class="vch-lbl-ar">صرفنا للمكرم :</div>
                            <div class="vch-fill">
                                <span class="vch-fill-text"><?= htmlspecialchars($voucher['beneficiary_name'] ?: '................................................') ?></span>
                            </div>
                            <div class="vch-lbl-en">Paid to:</div>
                        </div>

                        <!-- Line 2: The Sum of -->
                        <div class="vch-line">
                            <div class="vch-lbl-ar">مبلغ وقدره :</div>
                            <div class="vch-fill">
                                <span class="vch-fill-text font-serif text-slate-800"><?= htmlspecialchars($amountWords) ?></span>
                            </div>
                            <div class="vch-lbl-en">The Sum of:</div>
                        </div>

                        <!-- Line 3: Payment method checkboxes & Bank info -->
                        <div class="pm-options-row">
                            <div class="pm-checks">
                                <span class="pm-item">
                                    <span class="pm-box <?= $isNaqdi ? 'active' : '' ?>"><?= $isNaqdi ? '✓' : '' ?></span>
                                    <span class="pm-label">نقدًا Cash</span>
                                </span>
                                <span class="pm-item">
                                    <span class="pm-box <?= $isCash ? 'active' : '' ?>"><?= $isCash ? '✓' : '' ?></span>
                                    <span class="pm-label">كاش</span>
                                </span>
                                <span class="pm-item">
                                    <span class="pm-box <?= $isTransfer ? 'active' : '' ?>"><?= $isTransfer ? '✓' : '' ?></span>
                                    <span class="pm-label">تحويل Transfer</span>
                                </span>
                                <span class="pm-item">
                                    <span class="pm-box <?= $isPos ? 'active' : '' ?>"><?= $isPos ? '✓' : '' ?></span>
                                    <span class="pm-label">شبكة Network</span>
                                </span>
                                <span class="pm-item">
                                    <span class="pm-box <?= $isCheque ? 'active' : '' ?>"><?= $isCheque ? '✓' : '' ?></span>
                                    <span class="pm-label">شيك Check</span>
                                </span>
                            </div>
                            <div class="pm-details">
                                <span>على بنك To bank: <strong><?= htmlspecialchars($bankName ?: '—') ?></strong></span>
                                <span>بتاريخ On Date: <strong><?= htmlspecialchars($chequeDate ?: '—') ?></strong></span>
                                <?php if (!empty($chequeNumber)): ?>
                                    <span>رقم Ref: <strong><?= htmlspecialchars($chequeNumber) ?></strong></span>
                                <?php elseif (!empty($voucher['safe_name'])): ?>
                                    <span>الخزينة: <strong><?= htmlspecialchars($voucher['safe_name']) ?></strong></span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Line 4: And that for -->
                        <div class="vch-line">
                            <div class="vch-lbl-ar">وذلك مقابل :</div>
                            <div class="vch-fill">
                                <span class="vch-fill-text"><?= htmlspecialchars($purposeText) ?></span>
                            </div>
                            <div class="vch-lbl-en">And that for:</div>
                        </div>

                    </div>

                    <!-- 3. Signatures Section -->
                    <div class="slip-signatures">

                        <!-- المستلم Receiver -->
                        <div class="sig-column">
                            <div class="sig-head">
                                <span>المستلم</span>
                                <span class="sig-en">Receiver</span>
                            </div>
                            <div class="sig-slot">
                                <?php if (!empty($recipientSigBase64)): ?>
                                    <img src="<?= $recipientSigBase64 ?>" class="sig-img" alt="Receiver Signature">
                                <?php else: ?>
                                    <div class="sig-dots"></div>
                                <?php endif; ?>
                            </div>
                            <div class="sig-person">
                                <span><?= htmlspecialchars($signedByName ?: ($voucher['beneficiary_name'] ?: 'المستلم')) ?></span>
                                <?php if (!empty($signedAt)): ?>
                                    <span class="sig-verified">✓ موقّع إلكترونياً (<?= date('Y/m/d', strtotime($signedAt)) ?>)</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- المحاسب Accountant -->
                        <div class="sig-column">
                            <div class="sig-head">
                                <span>المحاسب</span>
                                <span class="sig-en">Accountant</span>
                            </div>
                            <div class="sig-slot">
                                <div class="sig-dots"></div>
                            </div>
                            <div class="sig-person">
                                <?= htmlspecialchars($accountantName) ?>
                            </div>
                        </div>

                        <!-- المدير Manager -->
                        <div class="sig-column">
                            <div class="sig-head">
                                <span>المدير</span>
                                <span class="sig-en">Manager</span>
                            </div>
                            <div class="sig-slot">
                                <?php if (!empty($issuerSigBase64)): ?>
                                    <img src="<?= $issuerSigBase64 ?>" class="sig-img" alt="Manager Signature">
                                <?php else: ?>
                                    <div class="sig-dots"></div>
                                <?php endif; ?>
                            </div>
                            <div class="sig-person">
                                <span><?= htmlspecialchars($approverName) ?></span>
                                <?php if (!empty($issuerSigBase64) || !empty($voucher['approved_at'])): ?>
                                    <span class="sig-verified">✓ معتمد رسمياً</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Mini QR Code Verification -->
                        <div class="mini-qr-box">
                            <div id="<?= $copy['qr_id'] ?>"></div>
                            <div class="mini-qr-caption">تحقق إلكتروني</div>
                        </div>

                    </div>

                </div>

                <!-- 4. Solid Deep Navy Footer Bar -->
                <div class="slip-footer-bar">
                    <div class="footer-ar"><?= htmlspecialchars($arabicAddress) ?></div>
                    <div class="footer-en"><?= htmlspecialchars($englishAddress) ?></div>
                </div>

            </div>

        </div>

    </div>

    <!-- Generate QR Code via QRCode.js -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var qrText = <?= json_encode($qrUrl) ?>;
            var container = document.getElementById('qrcode-single');
            if (container && typeof QRCode !== 'undefined') {
                new QRCode(container, {
                    text: qrText,
                    width: 44,
                    height: 44,
                    colorDark: "#03254c",
                    colorLight: "#ffffff",
                    correctLevel: QRCode.CorrectLevel.M
                });
            }
        });
    </script>

</body>
</html>
