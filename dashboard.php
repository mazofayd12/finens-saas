<?php
$pageTitle = 'لوحة التحكم';
require_once __DIR__ . '/includes/header.php';

$db = getDBConnection();
$currentMonth = date('Y-m');
$firstDayOfMonth = date('Y-m-01');
$lastDayOfMonth = date('Y-m-t');

if ($isSuper && empty($currentUser['active_company_id'])):
    // -------------------------------------------------------------
    // SUPER ADMIN VIEW (Multi-tenant SaaS Overview)
    // -------------------------------------------------------------
    // Global stats
    $totalCompanies = (int)$db->query("SELECT COUNT(*) FROM companies")->fetchColumn();
    $activeCompanies = (int)$db->query("SELECT COUNT(*) FROM companies WHERE is_active = 1")->fetchColumn();
    $totalUsers = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $totalLiquidity = (float)$db->query("SELECT SUM(balance) FROM safes")->fetchColumn();

    // Companies list with their safes
    $companiesStmt = $db->query("
        SELECT c.*, 
               (SELECT balance FROM safes WHERE company_id = c.id ORDER BY id ASC LIMIT 1) as safe_balance,
               (SELECT COUNT(*) FROM users WHERE company_id = c.id) as user_count
        FROM companies c 
        ORDER BY c.id DESC
    ");
    $companies = $companiesStmt->fetchAll();
?>

<div class="space-y-6">
    <!-- Super Admin Header Banner -->
    <div class="bg-gradient-to-r from-slate-900 via-indigo-950 to-slate-900 rounded-3xl p-6 sm:p-8 text-white shadow-xl relative overflow-hidden">
        <div class="relative z-10 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-amber-500/20 text-amber-300 border border-amber-500/30 mb-2">
                    <i class="fa-solid fa-crown ml-1.5"></i> لوحة الإشراف العام (سوبر أدمن)
                </span>
                <h1 class="text-2xl sm:text-3xl font-black">مرحباً، <?= htmlspecialchars($currentUser['name']) ?> 👋</h1>
                <p class="text-sm text-slate-300 mt-1 max-w-xl">
                    يمكنك من هنا إدارة جميع الشركات، تفعيل وتعطيل الموديولات المالية وموديل الموارد البشرية لكل شركة، وإنشاء الحسابات للمديرين ومدخلي البيانات.
                </p>
            </div>
            <div>
                <a href="companies.php?action=new" class="inline-flex items-center bg-brand-600 hover:bg-brand-700 text-white font-bold px-4 py-2.5 rounded-xl shadow-lg shadow-indigo-600/30 transition text-sm">
                    <i class="fa-solid fa-plus-circle ml-2"></i>
                    إضافة شركة جديدة
                </a>
            </div>
        </div>
    </div>

    <!-- Stats Overview Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs flex items-center justify-between">
            <div>
                <p class="text-xs font-bold text-slate-500">إجمالي الشركات</p>
                <p class="text-2xl font-black text-slate-800 mt-1"><?= $totalCompanies ?></p>
                <span class="text-[11px] font-semibold text-emerald-600"><?= $activeCompanies ?> شركة نشطة</span>
            </div>
            <div class="w-12 h-12 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center text-xl">
                <i class="fa-solid fa-building"></i>
            </div>
        </div>

        <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs flex items-center justify-between">
            <div>
                <p class="text-xs font-bold text-slate-500">إجمالي السيولة النقدية</p>
                <p class="text-2xl font-black text-emerald-600 mt-1"><?= formatMoney($totalLiquidity, 'ج.م') ?></p>
                <span class="text-[11px] font-semibold text-slate-400">في جميع خزائن الشركات</span>
            </div>
            <div class="w-12 h-12 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center text-xl">
                <i class="fa-solid fa-vault"></i>
            </div>
        </div>

        <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs flex items-center justify-between">
            <div>
                <p class="text-xs font-bold text-slate-500">المستخدمين المسجلين</p>
                <p class="text-2xl font-black text-slate-800 mt-1"><?= $totalUsers ?></p>
                <span class="text-[11px] font-semibold text-slate-400">مديرين ومدخلي بيانات</span>
            </div>
            <div class="w-12 h-12 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center text-xl">
                <i class="fa-solid fa-users"></i>
            </div>
        </div>

        <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs flex items-center justify-between">
            <div>
                <p class="text-xs font-bold text-slate-500">حالة النظام والـ PWA</p>
                <p class="text-lg font-black text-slate-800 mt-1">تطبيق جاهز</p>
                <span class="text-[11px] font-semibold text-brand-600">متوافق 100% مع الموبايل</span>
            </div>
            <div class="w-12 h-12 rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center text-xl">
                <i class="fa-solid fa-mobile-screen-button"></i>
            </div>
        </div>
    </div>

    <!-- Registered Companies List -->
    <div class="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
        <div class="p-5 border-b border-slate-100 flex items-center justify-between">
            <div>
                <h3 class="font-black text-slate-800 text-lg">الشركات المشتركة في المنصة</h3>
                <p class="text-xs text-slate-500">يمكنك الدخول كمدير لأي شركة أو تعديل الصلاحيات والموديولات بنقرة واحدة</p>
            </div>
            <a href="companies.php" class="text-xs font-bold text-brand-600 hover:text-brand-700">عرض الكل &larr;</a>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 p-5">
            <?php foreach ($companies as $comp): ?>
                <div class="bg-slate-50 hover:bg-slate-50/80 border border-slate-200 rounded-2xl p-5 flex flex-col justify-between transition hover:shadow-md">
                    <div>
                        <div class="flex items-start justify-between">
                            <div class="flex items-center space-x-3 space-x-reverse">
                                <div class="w-10 h-10 rounded-xl bg-brand-600 text-white flex items-center justify-center font-bold text-base shadow-xs">
                                    <i class="fa-solid fa-building"></i>
                                </div>
                                <div>
                                    <h4 class="font-black text-slate-800 text-base"><?= htmlspecialchars($comp['name']) ?></h4>
                                    <span class="text-[11px] text-slate-400 font-mono">#<?= htmlspecialchars($comp['code']) ?></span>
                                </div>
                            </div>
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold <?= (int)$comp['is_active'] === 1 ? 'bg-emerald-100 text-emerald-800' : 'bg-red-100 text-red-800' ?>">
                                <?= (int)$comp['is_active'] === 1 ? 'نشط' : 'معطل' ?>
                            </span>
                        </div>

                        <!-- Modules Badges -->
                        <div class="mt-4 pt-3 border-t border-slate-200/60">
                            <p class="text-[10px] font-bold text-slate-500 mb-1.5">الموديولات المفعلة للشركة:</p>
                            <div class="flex flex-wrap gap-1">
                                <?php if ((int)$comp['module_treasury'] === 1): ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-100 text-emerald-800">
                                        <i class="fa-solid fa-vault ml-1 text-[8px]"></i> الخزنة
                                    </span>
                                <?php endif; ?>

                                <?php if ((int)$comp['module_expenses'] === 1): ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-rose-100 text-rose-800">
                                        <i class="fa-solid fa-receipt ml-1 text-[8px]"></i> المصروفات
                                    </span>
                                <?php endif; ?>

                                <?php if ((int)$comp['module_hr'] === 1): ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-purple-100 text-purple-800">
                                        <i class="fa-solid fa-user-tie ml-1 text-[8px]"></i> HR والمرتبات
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-slate-200 text-slate-600 line-through">
                                        HR معطل
                                    </span>
                                <?php endif; ?>

                                <?php if ((int)$comp['module_reports'] === 1): ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-blue-100 text-blue-800">
                                        <i class="fa-solid fa-chart-column ml-1 text-[8px]"></i> التقارير
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Safe balance -->
                        <div class="mt-4 bg-white rounded-xl p-3 border border-slate-200 flex items-center justify-between">
                            <span class="text-xs text-slate-500 font-bold">رصيد الخزنة الحالي:</span>
                            <span class="text-sm font-black text-emerald-600"><?= formatMoney($comp['safe_balance'] ?? 0, $comp['currency']) ?></span>
                        </div>
                    </div>

                    <!-- Company Actions -->
                    <div class="mt-4 pt-3 border-t border-slate-200/60 flex items-center space-x-2 space-x-reverse">
                        <a href="companies.php?impersonate=<?= $comp['id'] ?>" class="flex-1 bg-brand-600 hover:bg-brand-700 text-white text-xs font-bold py-2 px-3 rounded-xl text-center shadow-xs transition flex items-center justify-center">
                            <i class="fa-solid fa-arrow-right-to-bracket ml-1.5"></i>
                            دخول كمدير
                        </a>
                        <a href="companies.php?action=edit&id=<?= $comp['id'] ?>" title="تعديل الشركة والموديولات" class="bg-white hover:bg-slate-100 border border-slate-200 text-slate-700 p-2 rounded-xl text-xs transition">
                            <i class="fa-solid fa-sliders"></i>
                        </a>
                        <a href="users.php?company_id=<?= $comp['id'] ?>" title="مستخدمين الشركة" class="bg-white hover:bg-slate-100 border border-slate-200 text-slate-700 p-2 rounded-xl text-xs transition">
                            <i class="fa-solid fa-users"></i>
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php else:
    // -------------------------------------------------------------
    // COMPANY TENANT VIEW (e.g. كوكب الصين or any tenant)
    // -------------------------------------------------------------
    $cid = (int)$currentUser['active_company_id'];
    $currency = $currentUser['currency'] ?? 'ج.م';

    // 1. Safe Balance
    $safe = getCompanyMainSafe($cid);

    // 2. Monthly Stats
    // Total deposits this month
    $depStmt = $db->prepare("
        SELECT COALESCE(SUM(amount), 0) FROM safe_transactions 
        WHERE company_id = :cid AND type = 'deposit' AND strftime('%Y-%m', transaction_date) = :m
    ");
    $depStmt->execute([':cid' => $cid, ':m' => $currentMonth]);
    $monthDeposits = (float)$depStmt->fetchColumn();

    // Total expenses this month
    $expStmt = $db->prepare("
        SELECT COALESCE(SUM(amount), 0) FROM safe_transactions 
        WHERE company_id = :cid AND (type = 'expense' OR type = 'salary') AND strftime('%Y-%m', transaction_date) = :m
    ");
    $expStmt->execute([':cid' => $cid, ':m' => $currentMonth]);
    $monthExpenses = (float)$expStmt->fetchColumn();

    $netCashFlow = $monthDeposits - $monthExpenses;

    // 3. Recent 5 Safe Transactions
    $recentTxStmt = $db->prepare("
        SELECT st.*, u.name as user_name
        FROM safe_transactions st
        LEFT JOIN users u ON st.user_id = u.id
        WHERE st.company_id = :cid
        ORDER BY st.id DESC
        LIMIT 6
    ");
    $recentTxStmt->execute([':cid' => $cid]);
    $recentTransactions = $recentTxStmt->fetchAll();

    // 4. Top Expense Categories this month
    $topCatStmt = $db->prepare("
        SELECT ec.name, COALESCE(SUM(e.amount), 0) as total_amount
        FROM expenses e
        LEFT JOIN expense_categories ec ON e.category_id = ec.id
        WHERE e.company_id = :cid AND strftime('%Y-%m', e.expense_date) = :m
        GROUP BY e.category_id
        ORDER BY total_amount DESC
        LIMIT 4
    ");
    $topCatStmt->execute([':cid' => $cid, ':m' => $currentMonth]);
    $topCategories = $topCatStmt->fetchAll();
?>

<div class="space-y-6">
    <!-- Welcome Header & Quick Action Buttons -->
    <div class="bg-white rounded-3xl p-5 sm:p-7 border border-slate-200/80 shadow-xs flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
            <div class="flex items-center space-x-2 space-x-reverse mb-1">
                <span class="px-2.5 py-0.5 rounded-full text-xs font-bold bg-brand-50 text-brand-700 border border-brand-200">
                    <i class="fa-solid fa-building ml-1"></i> <?= htmlspecialchars($currentUser['company_name'] ?? 'الشركة') ?>
                </span>
                <span class="text-xs text-slate-400 font-medium">| شهر <?= date('m / Y') ?></span>
            </div>
            <h1 class="text-xl sm:text-2xl font-black text-slate-900">مرحباً بك، <?= htmlspecialchars($currentUser['name']) ?></h1>
            <p class="text-xs text-slate-500 mt-0.5">متابعة دقيقة وحية لحركة الخزنة والمصروفات اليومية</p>
        </div>

        <div class="flex items-center space-x-2 space-x-reverse">
            <?php if (hasModule('treasury')): ?>
                <a href="receipts.php" class="flex-1 sm:flex-none inline-flex items-center justify-center bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-4 py-2.5 rounded-xl shadow-md shadow-indigo-600/20 transition text-xs sm:text-sm">
                    <i class="fa-solid fa-receipt ml-2"></i>
                    وصل استلام نقدية
                </a>

                <button onclick="openModal('quick-deposit-modal')" class="flex-1 sm:flex-none inline-flex items-center justify-center bg-emerald-600 hover:bg-emerald-700 text-white font-bold px-4 py-2.5 rounded-xl shadow-md shadow-emerald-600/20 transition text-xs sm:text-sm">
                    <i class="fa-solid fa-circle-plus ml-2"></i>
                    إيداع في الخزنة
                </button>
            <?php endif; ?>

            <?php if (hasModule('expenses')): ?>
                <button onclick="openModal('quick-expense-modal')" class="flex-1 sm:flex-none inline-flex items-center justify-center bg-rose-600 hover:bg-rose-700 text-white font-bold px-4 py-2.5 rounded-xl shadow-md shadow-rose-600/20 transition text-xs sm:text-sm">
                    <i class="fa-solid fa-circle-minus ml-2"></i>
                    تسجيل مصروف
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Main Safe Hero Card + Monthly Cards -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
        <!-- Safe Hero Card (Prominent) -->
        <div class="lg:col-span-1 gradient-safe rounded-3xl p-6 text-white shadow-xl shadow-emerald-500/10 flex flex-col justify-between relative overflow-hidden">
            <div class="relative z-10">
                <div class="flex items-center justify-between mb-4">
                    <span class="text-xs font-black uppercase tracking-wider bg-white/20 backdrop-blur-md px-3 py-1 rounded-full">
                        <i class="fa-solid fa-vault ml-1"></i> الخزنة الرئيسية
                    </span>
                    <i class="fa-solid fa-shield-halved text-white/50 text-xl"></i>
                </div>
                <p class="text-xs text-emerald-100 font-bold">الرصيد الفعلي المتوفر حالياً</p>
                <h2 class="text-3xl sm:text-4xl font-black mt-2 tracking-tight">
                    <?= formatMoney($safe['balance'], $currency) ?>
                </h2>
            </div>

            <div class="relative z-10 pt-5 mt-4 border-t border-white/20 flex items-center justify-between text-xs">
                <span class="text-emerald-100">آخر تحديث: <?= date('Y-m-d') ?></span>
                <?php if (hasModule('treasury')): ?>
                    <a href="treasury.php" class="font-bold underline hover:text-white transition">كشف الحركة &larr;</a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Monthly Financial Metrics (2 Cards) -->
        <div class="lg:col-span-2 grid grid-cols-1 sm:grid-cols-3 gap-4">
            <!-- Deposits This Month -->
            <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs flex flex-col justify-between">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold text-slate-500">إيداعات الشهر</span>
                    <div class="w-9 h-9 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center text-sm">
                        <i class="fa-solid fa-arrow-down-long"></i>
                    </div>
                </div>
                <div class="mt-3">
                    <h3 class="text-2xl font-black text-slate-800"><?= formatMoney($monthDeposits, $currency) ?></h3>
                    <p class="text-[11px] text-emerald-600 font-bold mt-1">
                        <i class="fa-solid fa-check ml-1"></i> داخل الخزنة
                    </p>
                </div>
            </div>

            <!-- Expenses This Month -->
            <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs flex flex-col justify-between">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold text-slate-500">مصروفات الشهر</span>
                    <div class="w-9 h-9 rounded-xl bg-rose-50 text-rose-600 flex items-center justify-center text-sm">
                        <i class="fa-solid fa-arrow-up-long"></i>
                    </div>
                </div>
                <div class="mt-3">
                    <h3 class="text-2xl font-black text-rose-600"><?= formatMoney($monthExpenses, $currency) ?></h3>
                    <p class="text-[11px] text-rose-500 font-bold mt-1">
                        <i class="fa-solid fa-minus ml-1"></i> تم سحبها وصرفها
                    </p>
                </div>
            </div>

            <!-- Net Cashflow -->
            <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs flex flex-col justify-between">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold text-slate-500">صافي السيولة</span>
                    <div class="w-9 h-9 rounded-xl <?= $netCashFlow >= 0 ? 'bg-indigo-50 text-brand-600' : 'bg-amber-50 text-amber-600' ?> flex items-center justify-center text-sm">
                        <i class="fa-solid fa-scale-balanced"></i>
                    </div>
                </div>
                <div class="mt-3">
                    <h3 class="text-2xl font-black <?= $netCashFlow >= 0 ? 'text-indigo-600' : 'text-amber-600' ?>">
                        <?= formatMoney($netCashFlow, $currency) ?>
                    </h3>
                    <p class="text-[11px] font-bold mt-1 <?= $netCashFlow >= 0 ? 'text-indigo-600' : 'text-amber-600' ?>">
                        <?= $netCashFlow >= 0 ? 'فائض سيولة' : 'عجز سيولة' ?>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Active Modules Banner for this Company -->
    <div class="bg-slate-100/80 rounded-2xl p-4 border border-slate-200/60 flex flex-wrap items-center justify-between gap-2">
        <div class="flex items-center space-x-2 space-x-reverse text-xs font-bold text-slate-600">
            <i class="fa-solid fa-circle-info text-brand-600"></i>
            <span>الموديولات المتاحة لشركتك:</span>
            <span class="bg-white px-2 py-0.5 rounded border text-slate-700">الخزنة</span>
            <span class="bg-white px-2 py-0.5 rounded border text-slate-700">المصروفات</span>
            <span class="bg-white px-2 py-0.5 rounded border text-slate-700">التقارير</span>
            <?php if (hasModule('hr')): ?>
                <span class="bg-purple-100 text-purple-800 px-2 py-0.5 rounded border border-purple-200">شؤون الموظفين والمرتبات مفعلة</span>
            <?php else: ?>
                <span class="bg-slate-200 text-slate-500 px-2 py-0.5 rounded line-through">موديول الموظفين والـ HR (غير مفعل للشركة)</span>
            <?php endif; ?>
        </div>
        <div class="text-[11px] text-slate-400">
            كود الشركة: <span class="font-mono font-bold text-slate-600"><?= htmlspecialchars($currentUser['company_code'] ?? '') ?></span>
        </div>
    </div>

    <!-- Recent Transactions & Top Spending Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Recent Transactions Table (2 cols) -->
        <div class="lg:col-span-2 bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
            <div class="p-4 sm:p-5 border-b border-slate-100 flex items-center justify-between">
                <div>
                    <h3 class="font-black text-slate-800 text-base">آخر حركات الخزنة والمصروفات</h3>
                    <p class="text-[11px] text-slate-400">سجل الإيداعات والمسحوبات الأخيرة</p>
                </div>
                <?php if (hasModule('treasury')): ?>
                    <a href="treasury.php" class="text-xs font-bold text-brand-600 hover:text-brand-700">عرض كامل السجل &larr;</a>
                <?php endif; ?>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-right text-xs">
                    <thead class="bg-slate-50 text-slate-500 font-bold border-b border-slate-100">
                        <tr>
                            <th class="p-3.5">النوع</th>
                            <th class="p-3.5">البند / السبب</th>
                            <th class="p-3.5">المبلغ</th>
                            <th class="p-3.5">الرصيد بعد العملية</th>
                            <th class="p-3.5">التاريخ</th>
                            <th class="p-3.5">بواسطة</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 text-slate-700">
                        <?php if (empty($recentTransactions)): ?>
                            <tr>
                                <td colspan="6" class="p-8 text-center text-slate-400 font-medium">
                                    لا توجد حركات مسجلة حتى الآن. ابدأ بإيداع مبلغ أو تسجيل مصروف!
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($recentTransactions as $tx): ?>
                                <tr class="hover:bg-slate-50/70 transition">
                                    <td class="p-3.5 whitespace-nowrap">
                                        <?php if ($tx['type'] === 'deposit'): ?>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full font-bold text-[10px] bg-emerald-100 text-emerald-800">
                                                <i class="fa-solid fa-arrow-down ml-1"></i> إيداع
                                            </span>
                                        <?php elseif ($tx['type'] === 'salary'): ?>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full font-bold text-[10px] bg-purple-100 text-purple-800">
                                                <i class="fa-solid fa-money-check-dollar ml-1"></i> راتب
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full font-bold text-[10px] bg-rose-100 text-rose-800">
                                                <i class="fa-solid fa-arrow-up ml-1"></i> مصروف
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="p-3.5 font-bold text-slate-800">
                                        <?= htmlspecialchars($tx['category']) ?>
                                        <?php if (!empty($tx['description'])): ?>
                                            <p class="text-[11px] font-normal text-slate-400 mt-0.5 truncate max-w-xs"><?= htmlspecialchars($tx['description']) ?></p>
                                        <?php endif; ?>
                                    </td>
                                    <td class="p-3.5 whitespace-nowrap font-black <?= $tx['type'] === 'deposit' ? 'text-emerald-600' : 'text-rose-600' ?>">
                                        <?= $tx['type'] === 'deposit' ? '+' : '-' ?><?= formatMoney($tx['amount'], $currency) ?>
                                    </td>
                                    <td class="p-3.5 whitespace-nowrap font-bold text-slate-600">
                                        <?= formatMoney($tx['balance_after'], $currency) ?>
                                    </td>
                                    <td class="p-3.5 whitespace-nowrap text-slate-500">
                                        <?= htmlspecialchars($tx['transaction_date']) ?>
                                    </td>
                                    <td class="p-3.5 whitespace-nowrap text-slate-500">
                                        <?= htmlspecialchars($tx['user_name'] ?? 'مستخدم') ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Top Spending Categories (1 col) -->
        <div class="bg-white rounded-2xl border border-slate-200/80 shadow-xs p-5 flex flex-col justify-between">
            <div>
                <h3 class="font-black text-slate-800 text-base mb-1">أعلى بنود الصرف هذا الشهر</h3>
                <p class="text-[11px] text-slate-400 mb-4">توزيع النفقات بحسب البنود</p>

                <?php if (empty($topCategories)): ?>
                    <div class="p-8 text-center text-slate-400 text-xs font-medium">
                        <i class="fa-solid fa-receipt text-2xl mb-2 text-slate-300"></i>
                        <p>لا توجد مصروفات مسجلة لهذا الشهر حتى الآن.</p>
                    </div>
                <?php else: ?>
                    <div class="space-y-3">
                        <?php foreach ($topCategories as $tc): 
                            $percentage = $monthExpenses > 0 ? round(($tc['total_amount'] / $monthExpenses) * 100, 1) : 0;
                        ?>
                            <div>
                                <div class="flex items-center justify-between text-xs mb-1">
                                    <span class="font-bold text-slate-700"><?= htmlspecialchars($tc['name'] ?? 'بدون بند') ?></span>
                                    <span class="font-black text-slate-900"><?= formatMoney($tc['total_amount'], $currency) ?></span>
                                </div>
                                <div class="w-full bg-slate-100 rounded-full h-2 overflow-hidden">
                                    <div class="bg-rose-500 h-2 rounded-full" style="width: <?= min(100, $percentage) ?>%"></div>
                                </div>
                                <span class="text-[10px] text-slate-400 font-medium"><?= $percentage ?>% من إجمالي مصروفات الشهر</span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="mt-5 pt-4 border-t border-slate-100">
                <a href="reports.php" class="block w-full py-2.5 px-3 bg-slate-50 hover:bg-slate-100 text-slate-700 font-bold rounded-xl text-center text-xs transition border border-slate-200">
                    <i class="fa-solid fa-chart-pie ml-1 text-brand-600"></i>
                    عرض التقارير والرسوم البيانية الكاملة
                </a>
            </div>
        </div>
    </div>
</div>

<?php endif; ?>

<?php include_once __DIR__ . '/includes/footer.php'; ?>
