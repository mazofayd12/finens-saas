<?php
require_once __DIR__ . '/config/app.php';
requireLogin();
requirePermission('invoices');

$currentUser = getCurrentUser();
$db = getDBConnection();
$activeCompanyId = (int)($currentUser['active_company_id'] ?? 0);
if ($activeCompanyId <= 0 && isSuperAdmin()) {
    $firstComp = $db->query("SELECT id FROM companies WHERE is_active = 1 LIMIT 1")->fetch();
    if ($firstComp) {
        $activeCompanyId = (int)$firstComp['id'];
    }
}
$currency = $currentUser['currency'] ?? 'ج.م';

$pageTitle = 'الفواتير الضريبية المبسطة';

// Handle Delete Invoice
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_invoice') {
    requireNotViewOnly();
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        die('خطأ في التحقق من صحة الطلب (CSRF)');
    }

    $delId = (int)($_POST['invoice_id'] ?? 0);
    if ($delId > 0) {
        $delStmt = $db->prepare("DELETE FROM invoices WHERE id = :id AND company_id = :cid");
        $delStmt->execute([':id' => $delId, ':cid' => $activeCompanyId]);
        setFlash('success', 'تم حذف الفاتورة بنجاح.');
    }
    header('Location: invoices.php');
    exit;
}

// Search & Filter Parameters
$search = trim($_GET['search'] ?? '');
$filterFrom = trim($_GET['from'] ?? '');
$filterTo = trim($_GET['to'] ?? '');

// Build Query
$where = ["i.company_id = :cid"];
$params = [':cid' => $activeCompanyId];

if (!empty($search)) {
    $where[] = "(i.invoice_number LIKE :s OR i.customer_name LIKE :s OR i.customer_phone LIKE :s)";
    $params[':s'] = "%{$search}%";
}

if (!empty($filterFrom)) {
    $where[] = "i.invoice_date >= :from";
    $params[':from'] = $filterFrom;
}

if (!empty($filterTo)) {
    $where[] = "i.invoice_date <= :to";
    $params[':to'] = $filterTo;
}

$whereClause = implode(' AND ', $where);

// Fetch Stats
$statsStmt = $db->prepare("
    SELECT 
        COUNT(i.id) as total_count,
        COALESCE(SUM(i.subtotal), 0) as total_subtotal,
        COALESCE(SUM(i.tax_amount), 0) as total_vat,
        COALESCE(SUM(i.total_amount), 0) as total_net
    FROM invoices i
    WHERE {$whereClause}
");
$statsStmt->execute($params);
$stats = $statsStmt->fetch();

// Fetch Invoices List
$listStmt = $db->prepare("
    SELECT i.*, u.name as creator_name
    FROM invoices i
    LEFT JOIN users u ON i.user_id = u.id
    WHERE {$whereClause}
    ORDER BY i.id DESC
");
$listStmt->execute($params);
$invoices = $listStmt->fetchAll();

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<div class="space-y-6">

    <!-- Top Hero Banner / Quick Action -->
    <div class="bg-gradient-to-l from-slate-900 via-indigo-950 to-slate-900 rounded-2xl p-5 sm:p-6 text-white shadow-xl relative overflow-hidden">
        <div class="absolute -top-10 -left-10 w-44 h-44 bg-indigo-500/10 rounded-full blur-2xl pointer-events-none"></div>
        <div class="absolute -bottom-10 -right-10 w-44 h-44 bg-rose-500/10 rounded-full blur-2xl pointer-events-none"></div>

        <div class="relative z-10 flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div>
                <div class="flex items-center space-x-2 space-x-reverse mb-1.5">
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[11px] font-black bg-rose-500/20 text-rose-300 border border-rose-500/30">
                        <i class="fa-solid fa-file-invoice ml-1"></i> الفواتير الضريبية المبسطة
                    </span>
                    <span class="text-xs text-slate-400">Simplified Tax Invoices</span>
                </div>
                <h1 class="text-xl sm:text-2xl font-black tracking-tight text-white">إدارة وإصدار الفواتير الضريبية المبسطة</h1>
                <p class="text-xs text-slate-300 mt-1 max-w-2xl leading-relaxed">
                    إصدار فواتير ضريبية مبسطة معتمدة بهوية الشركة، باركود ZATCA QR Code الذكي، وبنود المنتجات والخدمات مع احتساب الضريبة بدقة.
                </p>
            </div>

            <div class="flex items-center space-x-2 space-x-reverse shrink-0">
                <?php if (!isViewOnly()): ?>
                    <a href="invoice_create.php" class="inline-flex items-center bg-rose-600 hover:bg-rose-700 text-white font-extrabold px-4 py-2.5 rounded-xl shadow-lg transition text-xs">
                        <i class="fa-solid fa-plus-circle ml-2 text-sm"></i>
                        إصدار فاتورة ضريبية مبسطة جديدة
                    </a>
                <?php else: ?>
                    <span class="inline-flex items-center px-3 py-1.5 rounded-xl text-xs font-black bg-white/20 text-white border border-white/30">
                        <i class="fa-solid fa-eye ml-1.5"></i> وضع المشاهدة فقط
                    </span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Stats Summary Cards -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
        <!-- 1. Total Invoices Count -->
        <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-xs">
            <div class="flex items-center justify-between mb-2">
                <span class="text-xs font-bold text-slate-500">عدد الفواتير الصادرة</span>
                <span class="w-8 h-8 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center text-sm">
                    <i class="fa-solid fa-receipt"></i>
                </span>
            </div>
            <div class="text-xl sm:text-2xl font-black text-slate-900 font-mono">
                <?= number_format((int)($stats['total_count'] ?? 0)) ?>
            </div>
            <span class="text-[11px] text-slate-400 block mt-1">فاتورة معتمدة</span>
        </div>

        <!-- 2. Subtotal Before VAT -->
        <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-xs">
            <div class="flex items-center justify-between mb-2">
                <span class="text-xs font-bold text-slate-500">المبيعات (قبل الضريبة)</span>
                <span class="w-8 h-8 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center text-sm">
                    <i class="fa-solid fa-coins"></i>
                </span>
            </div>
            <div class="text-lg sm:text-xl font-black text-slate-900 font-mono">
                <?= formatMoney($stats['total_subtotal'] ?? 0, $currency) ?>
            </div>
            <span class="text-[11px] text-slate-400 block mt-1">القيمة الخاضعة للضريبة</span>
        </div>

        <!-- 3. Total VAT -->
        <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-xs">
            <div class="flex items-center justify-between mb-2">
                <span class="text-xs font-bold text-slate-500">إجمالي ضريبة القيمة المضافة</span>
                <span class="w-8 h-8 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center text-sm">
                    <i class="fa-solid fa-percent"></i>
                </span>
            </div>
            <div class="text-lg sm:text-xl font-black text-amber-600 font-mono">
                <?= formatMoney($stats['total_vat'] ?? 0, $currency) ?>
            </div>
            <span class="text-[11px] text-slate-400 block mt-1">قيمة الـ VAT المحصلة</span>
        </div>

        <!-- 4. Grand Net Total -->
        <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-xs">
            <div class="flex items-center justify-between mb-2">
                <span class="text-xs font-bold text-slate-500">إجمالي المبلغ المستحق</span>
                <span class="w-8 h-8 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center text-sm">
                    <i class="fa-solid fa-circle-check"></i>
                </span>
            </div>
            <div class="text-lg sm:text-xl font-black text-emerald-600 font-mono">
                <?= formatMoney($stats['total_net'] ?? 0, $currency) ?>
            </div>
            <span class="text-[11px] text-slate-400 block mt-1">شامل الضريبة والخصومات</span>
        </div>
    </div>

    <!-- Filter & Search Bar -->
    <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-xs">
        <form method="GET" action="invoices.php" class="grid grid-cols-1 sm:grid-cols-12 gap-3 items-end">
            <div class="sm:col-span-5">
                <label class="block text-xs font-bold text-slate-600 mb-1">بحث برقم الفاتورة أو العميل أو الهاتف</label>
                <div class="relative">
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="مثال: INV-2026-0001 أو اسم العميل..." class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2 text-xs focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none">
                    <i class="fa-solid fa-magnifying-glass absolute left-3 top-2.5 text-slate-400 text-xs"></i>
                </div>
            </div>

            <div class="sm:col-span-3">
                <label class="block text-xs font-bold text-slate-600 mb-1">من تاريخ</label>
                <input type="date" name="from" value="<?= htmlspecialchars($filterFrom) ?>" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none font-mono">
            </div>

            <div class="sm:col-span-3">
                <label class="block text-xs font-bold text-slate-600 mb-1">إلى تاريخ</label>
                <input type="date" name="to" value="<?= htmlspecialchars($filterTo) ?>" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none font-mono">
            </div>

            <div class="sm:col-span-1 flex items-center space-x-1.5 space-x-reverse">
                <button type="submit" class="w-full bg-slate-900 hover:bg-slate-800 text-white font-bold py-2 rounded-xl text-xs transition shadow-xs flex items-center justify-center" title="تطبيق الفلتر">
                    <i class="fa-solid fa-filter"></i>
                </button>
                <?php if (!empty($search) || !empty($filterFrom) || !empty($filterTo)): ?>
                    <a href="invoices.php" class="bg-slate-100 hover:bg-slate-200 text-slate-600 font-bold p-2 rounded-xl text-xs transition flex items-center justify-center" title="إلغاء الفلتر">
                        <i class="fa-solid fa-rotate-left"></i>
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Desktop Invoices Table (Hidden on small screens) -->
    <div class="hidden md:block bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-right text-xs">
                <thead class="bg-slate-50/80 text-slate-600 border-b border-slate-200 font-bold uppercase tracking-wider">
                    <tr>
                        <th class="p-3.5">رقم الفاتورة</th>
                        <th class="p-3.5">التاريخ</th>
                        <th class="p-3.5">اسم العميل وبياناته</th>
                        <th class="p-3.5">طريقة الدفع</th>
                        <th class="p-3.5">قبل الضريبة</th>
                        <th class="p-3.5">الضريبة</th>
                        <th class="p-3.5">المبلغ المستحق</th>
                        <th class="p-3.5 text-center">الإجراءات</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-slate-700">
                    <?php if (empty($invoices)): ?>
                        <tr>
                            <td colspan="8" class="p-8 text-center text-slate-400">
                                <i class="fa-solid fa-file-invoice text-4xl block mb-2 text-slate-300"></i>
                                لا توجد فواتير ضريبية صادرة حتى الآن.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($invoices as $inv): 
                            $invCurr = $inv['currency'] ?? $currency;
                        ?>
                            <tr class="hover:bg-slate-50/70 transition">
                                <td class="p-3.5 whitespace-nowrap">
                                    <a href="invoice_print.php?id=<?= $inv['id'] ?>" target="_blank" class="font-mono font-black text-rose-700 hover:underline flex items-center">
                                        <i class="fa-solid fa-qrcode ml-1 text-slate-400"></i>
                                        <?= htmlspecialchars($inv['invoice_number']) ?>
                                    </a>
                                </td>
                                <td class="p-3.5 whitespace-nowrap text-slate-600 font-mono">
                                    <?= htmlspecialchars($inv['invoice_date']) ?>
                                </td>
                                <td class="p-3.5">
                                    <strong class="text-slate-900 block"><?= htmlspecialchars($inv['customer_name']) ?></strong>
                                    <?php if (!empty($inv['customer_phone'])): ?>
                                        <span class="text-[10px] text-slate-400 font-mono"><i class="fa-solid fa-phone text-[9px] ml-1"></i><?= htmlspecialchars($inv['customer_phone']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-3.5 whitespace-nowrap">
                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-slate-100 text-slate-700">
                                        <?= htmlspecialchars($inv['payment_method']) ?>
                                    </span>
                                </td>
                                <td class="p-3.5 whitespace-nowrap font-mono text-slate-700">
                                    <?= formatMoney($inv['subtotal'], $invCurr) ?>
                                </td>
                                <td class="p-3.5 whitespace-nowrap font-mono text-amber-600 font-bold">
                                    <?= formatMoney($inv['tax_amount'], $invCurr) ?>
                                </td>
                                <td class="p-3.5 whitespace-nowrap font-mono font-black text-emerald-700 text-sm">
                                    <?= formatMoney($inv['total_amount'], $invCurr) ?>
                                </td>
                                <td class="p-3.5 whitespace-nowrap text-center">
                                    <div class="flex items-center justify-center space-x-1.5 space-x-reverse">
                                        <a href="invoice_print.php?id=<?= $inv['id'] ?>" target="_blank" class="inline-flex items-center bg-rose-600 hover:bg-rose-700 text-white font-bold px-3 py-1.5 rounded-xl text-xs transition shadow-xs">
                                            <i class="fa-solid fa-print ml-1.5"></i> طباعة A4
                                        </a>
                                        <?php if (!isViewOnly()): ?>
                                            <form method="POST" action="invoices.php" onsubmit="return confirm('هل أنت متأكد من رغبتك في حذف هذه الفاتورة نهائياً؟');" class="inline">
                                                <?= getCSRFInput() ?>
                                                <input type="hidden" name="action" value="delete_invoice">
                                                <input type="hidden" name="invoice_id" value="<?= $inv['id'] ?>">
                                                <button type="submit" class="inline-flex items-center bg-slate-100 hover:bg-red-50 text-slate-500 hover:text-red-600 p-1.5 rounded-xl text-xs transition" title="حذف الفاتورة">
                                                    <i class="fa-solid fa-trash-can"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Mobile Invoices Feed (Visible on small screens) -->
    <div class="block md:hidden space-y-3">
        <?php if (empty($invoices)): ?>
            <div class="bg-white rounded-2xl p-8 border border-slate-200/80 text-center shadow-xs">
                <i class="fa-solid fa-file-invoice text-3xl text-slate-300 block mb-2"></i>
                <p class="text-xs text-slate-500">لا توجد فواتير صادرة حتى الآن.</p>
            </div>
        <?php else: ?>
            <?php foreach ($invoices as $inv): 
                $invCurr = $inv['currency'] ?? $currency;
            ?>
                <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-xs space-y-3">
                    <div class="flex items-center justify-between border-b border-slate-100 pb-2.5">
                        <div>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-lg text-xs font-black font-mono bg-rose-50 text-rose-700 border border-rose-200">
                                <i class="fa-solid fa-qrcode ml-1 text-rose-500"></i>
                                <?= htmlspecialchars($inv['invoice_number']) ?>
                            </span>
                            <span class="text-[11px] text-slate-400 mr-2 font-mono"><?= htmlspecialchars($inv['invoice_date']) ?></span>
                        </div>
                        <div class="text-left">
                            <span class="text-base font-black text-emerald-700 font-mono block">
                                <?= formatMoney($inv['total_amount'], $invCurr) ?>
                            </span>
                            <span class="text-[10px] text-slate-400 block"><?= htmlspecialchars($inv['payment_method']) ?></span>
                        </div>
                    </div>

                    <div>
                        <div class="flex items-center space-x-1.5 space-x-reverse mb-1">
                            <i class="fa-solid fa-user-check text-xs text-slate-400"></i>
                            <strong class="text-slate-900 text-xs font-extrabold"><?= htmlspecialchars($inv['customer_name']) ?></strong>
                            <?php if (!empty($inv['customer_phone'])): ?>
                                <span class="text-[10px] text-slate-400 font-mono">(<?= htmlspecialchars($inv['customer_phone']) ?>)</span>
                            <?php endif; ?>
                        </div>
                        <div class="flex items-center justify-between text-[11px] text-slate-500 pt-1">
                            <span>الضريبة: <strong class="text-amber-600 font-mono"><?= formatMoney($inv['tax_amount'], $invCurr) ?></strong></span>
                            <span>الصافي قبل الضريبة: <strong class="font-mono text-slate-700"><?= formatMoney($inv['subtotal'], $invCurr) ?></strong></span>
                        </div>
                    </div>

                    <div class="pt-2 border-t border-slate-100 flex items-center justify-between">
                        <span class="text-[10px] text-slate-400">بواسطة: <?= htmlspecialchars($inv['creator_name'] ?? 'المستخدم') ?></span>
                        <div class="flex items-center space-x-2 space-x-reverse">
                            <a href="invoice_print.php?id=<?= $inv['id'] ?>" target="_blank" class="inline-flex items-center bg-rose-600 hover:bg-rose-700 text-white font-bold px-3 py-1.5 rounded-xl text-xs transition shadow-xs">
                                <i class="fa-solid fa-print ml-1"></i> طباعة A4
                            </a>
                            <form method="POST" action="invoices.php" onsubmit="return confirm('هل تريد حذف هذه الفاتورة؟');" class="inline">
                                <?= getCSRFInput() ?>
                                <input type="hidden" name="action" value="delete_invoice">
                                <input type="hidden" name="invoice_id" value="<?= $inv['id'] ?>">
                                <button type="submit" class="bg-slate-100 text-slate-500 hover:text-red-600 p-1.5 rounded-xl text-xs transition">
                                    <i class="fa-solid fa-trash-can"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
