<?php
$currentPage = basename($_SERVER['PHP_SELF']);
$isSuperMaster = isSuperAdmin() && empty($currentUser['active_company_id']);
?>

<!-- Desktop Sidebar -->
<aside id="app-sidebar" class="no-print hidden md:flex flex-col w-64 bg-slate-900 text-slate-200 border-l border-slate-800 min-h-screen shrink-0 transition-all duration-300">
    <!-- Brand Header -->
    <div class="p-5 border-b border-slate-800 flex items-center justify-between">
        <a href="dashboard.php" class="flex items-center space-x-3 space-x-reverse">
            <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-brand-600 to-indigo-500 text-white flex items-center justify-center text-xl shadow-lg">
                <i class="fa-solid fa-coins"></i>
            </div>
            <div>
                <span class="text-base font-black tracking-wide text-white block">فينانس ساس</span>
                <span class="text-[11px] text-slate-400 font-medium block">لوحة تحكم الشركات</span>
            </div>
        </a>
    </div>

    <!-- Navigation Links -->
    <nav class="flex-1 px-3 py-4 space-y-1.5 overflow-y-auto">
        <p class="px-3 text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-2">القائمة الرئيسية</p>

        <!-- Dashboard -->
        <a href="dashboard.php" class="flex items-center px-3.5 py-2.5 text-sm font-semibold rounded-xl transition <?= $currentPage === 'dashboard.php' ? 'bg-brand-600 text-white shadow-md' : 'text-slate-300 hover:bg-slate-800/80 hover:text-white' ?>">
            <i class="fa-solid fa-chart-pie w-6 text-center ml-2.5 <?= $currentPage === 'dashboard.php' ? 'text-white' : 'text-slate-400' ?>"></i>
            <span>لوحة التحكم</span>
        </a>

        <?php if ($isSuperMaster): ?>
            <!-- SUPER ADMIN EXCLUSIVE LINKS -->
            <p class="px-3 text-[11px] font-bold uppercase tracking-wider text-slate-500 mt-5 mb-2">إدارة المنصة (ساس)</p>
            
            <a href="companies.php" class="flex items-center px-3.5 py-2.5 text-sm font-semibold rounded-xl transition <?= $currentPage === 'companies.php' ? 'bg-brand-600 text-white shadow-md' : 'text-slate-300 hover:bg-slate-800/80 hover:text-white' ?>">
                <i class="fa-solid fa-building-flag w-6 text-center ml-2.5 <?= $currentPage === 'companies.php' ? 'text-white' : 'text-slate-400' ?>"></i>
                <span>الشركات والموديولات</span>
            </a>

            <a href="users.php" class="flex items-center px-3.5 py-2.5 text-sm font-semibold rounded-xl transition <?= $currentPage === 'users.php' ? 'bg-brand-600 text-white shadow-md' : 'text-slate-300 hover:bg-slate-800/80 hover:text-white' ?>">
                <i class="fa-solid fa-users-gear w-6 text-center ml-2.5 <?= $currentPage === 'users.php' ? 'text-white' : 'text-slate-400' ?>"></i>
                <span>المستخدمين والصلاحيات</span>
            </a>

        <?php else: ?>
            <!-- COMPANY SPECIFIC MODULES (Filtered by active company settings) -->
            <p class="px-3 text-[11px] font-bold uppercase tracking-wider text-slate-500 mt-5 mb-2">المالية والحسابات</p>

            <?php if (hasModule('treasury')): ?>
                <a href="treasury.php" class="flex items-center px-3.5 py-2.5 text-sm font-semibold rounded-xl transition <?= $currentPage === 'treasury.php' ? 'bg-brand-600 text-white shadow-md' : 'text-slate-300 hover:bg-slate-800/80 hover:text-white' ?>">
                    <i class="fa-solid fa-vault w-6 text-center ml-2.5 <?= $currentPage === 'treasury.php' ? 'text-white' : 'text-emerald-400' ?>"></i>
                    <span>الخزنة والإيداعات</span>
                </a>

                <a href="receipts.php" class="flex items-center px-3.5 py-2.5 text-sm font-semibold rounded-xl transition <?= $currentPage === 'receipts.php' || $currentPage === 'receipt_print.php' ? 'bg-brand-600 text-white shadow-md' : 'text-slate-300 hover:bg-slate-800/80 hover:text-white' ?>">
                    <i class="fa-solid fa-hand-holding-dollar w-6 text-center ml-2.5 <?= $currentPage === 'receipts.php' ? 'text-white' : 'text-emerald-300' ?>"></i>
                    <span>إيصالات الاستلام (سند قبض)</span>
                </a>
            <?php endif; ?>

            <?php if (hasModule('expenses')): ?>
                <a href="expenses.php" class="flex items-center px-3.5 py-2.5 text-sm font-semibold rounded-xl transition <?= $currentPage === 'expenses.php' ? 'bg-brand-600 text-white shadow-md' : 'text-slate-300 hover:bg-slate-800/80 hover:text-white' ?>">
                    <i class="fa-solid fa-receipt w-6 text-center ml-2.5 <?= $currentPage === 'expenses.php' ? 'text-white' : 'text-rose-400' ?>"></i>
                    <span>سجل المصروفات</span>
                </a>

                <?php if (isCompanyAdmin()): ?>
                    <a href="expense_categories.php" class="flex items-center px-3.5 py-2.5 text-sm font-semibold rounded-xl transition <?= $currentPage === 'expense_categories.php' ? 'bg-brand-600 text-white shadow-md' : 'text-slate-300 hover:bg-slate-800/80 hover:text-white' ?>">
                        <i class="fa-solid fa-tags w-6 text-center ml-2.5 <?= $currentPage === 'expense_categories.php' ? 'text-white' : 'text-amber-400' ?>"></i>
                        <span>بنود المصروفات</span>
                    </a>
                <?php endif; ?>
            <?php endif; ?>

            <?php if (hasModule('reports')): ?>
                <a href="reports.php" class="flex items-center px-3.5 py-2.5 text-sm font-semibold rounded-xl transition <?= $currentPage === 'reports.php' ? 'bg-brand-600 text-white shadow-md' : 'text-slate-300 hover:bg-slate-800/80 hover:text-white' ?>">
                    <i class="fa-solid fa-file-invoice-dollar w-6 text-center ml-2.5 <?= $currentPage === 'reports.php' ? 'text-white' : 'text-blue-400' ?>"></i>
                    <span>التقارير المالية</span>
                </a>
            <?php endif; ?>

            <!-- HR MODULE (Only if toggled ON for this company) -->
            <?php if (hasModule('hr')): ?>
                <p class="px-3 text-[11px] font-bold uppercase tracking-wider text-slate-500 mt-5 mb-2">الموارد البشرية والرواتب</p>

                <a href="employees.php" class="flex items-center px-3.5 py-2.5 text-sm font-semibold rounded-xl transition <?= $currentPage === 'employees.php' ? 'bg-brand-600 text-white shadow-md' : 'text-slate-300 hover:bg-slate-800/80 hover:text-white' ?>">
                    <i class="fa-solid fa-user-tie w-6 text-center ml-2.5 <?= $currentPage === 'employees.php' ? 'text-white' : 'text-purple-400' ?>"></i>
                    <span>دليل الموظفين</span>
                </a>

                <a href="payroll.php" class="flex items-center px-3.5 py-2.5 text-sm font-semibold rounded-xl transition <?= $currentPage === 'payroll.php' ? 'bg-brand-600 text-white shadow-md' : 'text-slate-300 hover:bg-slate-800/80 hover:text-white' ?>">
                    <i class="fa-solid fa-money-check-dollar w-6 text-center ml-2.5 <?= $currentPage === 'payroll.php' ? 'text-white' : 'text-teal-400' ?>"></i>
                    <span>مسيرات الرواتب</span>
                </a>
            <?php endif; ?>

            <?php if (isCompanyAdmin()): ?>
                <p class="px-3 text-[11px] font-bold uppercase tracking-wider text-slate-500 mt-5 mb-2">إدارة الشركة</p>
                <a href="settings.php" class="flex items-center px-3.5 py-2.5 text-sm font-semibold rounded-xl transition <?= $currentPage === 'settings.php' ? 'bg-brand-600 text-white shadow-md' : 'text-slate-300 hover:bg-slate-800/80 hover:text-white' ?>">
                    <i class="fa-solid fa-building-gear w-6 text-center ml-2.5 <?= $currentPage === 'settings.php' ? 'text-white' : 'text-indigo-400' ?>"></i>
                    <span>إعدادات الشركة والمقر</span>
                </a>
                <a href="users.php" class="flex items-center px-3.5 py-2.5 text-sm font-semibold rounded-xl transition <?= $currentPage === 'users.php' ? 'bg-brand-600 text-white shadow-md' : 'text-slate-300 hover:bg-slate-800/80 hover:text-white' ?>">
                    <i class="fa-solid fa-users w-6 text-center ml-2.5 <?= $currentPage === 'users.php' ? 'text-white' : 'text-slate-400' ?>"></i>
                    <span>مستخدمي الشركة</span>
                </a>
            <?php endif; ?>

        <?php endif; ?>
    </nav>

    <!-- Sidebar Footer -->
    <div class="p-4 border-t border-slate-800">
        <div class="flex items-center justify-between">
            <div class="flex items-center space-x-2 space-x-reverse truncate">
                <div class="w-8 h-8 rounded-full bg-slate-800 border border-slate-700 flex items-center justify-center text-slate-300 font-bold text-xs">
                    <?= mb_substr($currentUser['name'] ?? 'م', 0, 1) ?>
                </div>
                <div class="truncate">
                    <p class="text-xs font-bold text-white truncate"><?= htmlspecialchars($currentUser['name']) ?></p>
                    <p class="text-[10px] text-slate-400 truncate">@<?= htmlspecialchars($currentUser['username']) ?></p>
                </div>
            </div>
            <a href="logout.php" title="تسجيل الخروج" class="text-slate-400 hover:text-rose-400 text-sm">
                <i class="fa-solid fa-arrow-right-from-bracket"></i>
            </a>
        </div>
    </div>
</aside>

<!-- Mobile Slide-out Drawer (Hidden by default) -->
<div id="mobile-sidebar-drawer" class="fixed inset-0 z-50 bg-slate-900/80 backdrop-blur-xs hidden md:hidden">
    <div class="w-72 max-w-full bg-slate-900 text-white h-full flex flex-col p-4 shadow-2xl">
        <div class="flex items-center justify-between pb-4 border-b border-slate-800">
            <div class="flex items-center space-x-2 space-x-reverse">
                <div class="w-9 h-9 rounded-xl bg-brand-600 text-white flex items-center justify-center font-bold">
                    <i class="fa-solid fa-coins"></i>
                </div>
                <span class="font-extrabold text-sm"><?= htmlspecialchars(APP_SHORT_NAME) ?></span>
            </div>
            <button id="mobile-sidebar-close" class="text-slate-400 hover:text-white text-xl p-1">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <nav class="flex-1 py-4 space-y-1 overflow-y-auto">
            <a href="dashboard.php" class="flex items-center px-3 py-2.5 text-sm rounded-lg <?= $currentPage === 'dashboard.php' ? 'bg-brand-600 text-white' : 'text-slate-300' ?>">
                <i class="fa-solid fa-chart-pie ml-3 w-5 text-center"></i> لوحة التحكم
            </a>

            <?php if ($isSuperMaster): ?>
                <a href="companies.php" class="flex items-center px-3 py-2.5 text-sm rounded-lg <?= $currentPage === 'companies.php' ? 'bg-brand-600 text-white' : 'text-slate-300' ?>">
                    <i class="fa-solid fa-building-flag ml-3 w-5 text-center"></i> إدارة الشركات
                </a>
                <a href="users.php" class="flex items-center px-3 py-2.5 text-sm rounded-lg <?= $currentPage === 'users.php' ? 'bg-brand-600 text-white' : 'text-slate-300' ?>">
                    <i class="fa-solid fa-users-gear ml-3 w-5 text-center"></i> المستخدمين
                </a>
            <?php else: ?>
                <?php if (hasModule('treasury')): ?>
                    <a href="treasury.php" class="flex items-center px-3 py-2.5 text-sm rounded-lg <?= $currentPage === 'treasury.php' ? 'bg-brand-600 text-white' : 'text-slate-300' ?>">
                        <i class="fa-solid fa-vault ml-3 w-5 text-center text-emerald-400"></i> الخزنة
                    </a>
                    <a href="receipts.php" class="flex items-center px-3 py-2.5 text-sm rounded-lg <?= $currentPage === 'receipts.php' ? 'bg-brand-600 text-white' : 'text-slate-300' ?>">
                        <i class="fa-solid fa-hand-holding-dollar ml-3 w-5 text-center text-emerald-300"></i> إيصالات الاستلام (وصل قبض)
                    </a>
                <?php endif; ?>
                <?php if (hasModule('expenses')): ?>
                    <a href="expenses.php" class="flex items-center px-3 py-2.5 text-sm rounded-lg <?= $currentPage === 'expenses.php' ? 'bg-brand-600 text-white' : 'text-slate-300' ?>">
                        <i class="fa-solid fa-receipt ml-3 w-5 text-center text-rose-400"></i> المصروفات
                    </a>
                    <a href="expense_categories.php" class="flex items-center px-3 py-2.5 text-sm rounded-lg <?= $currentPage === 'expense_categories.php' ? 'bg-brand-600 text-white' : 'text-slate-300' ?>">
                        <i class="fa-solid fa-tags ml-3 w-5 text-center text-amber-400"></i> بنود المصروفات
                    </a>
                <?php endif; ?>
                <?php if (hasModule('reports')): ?>
                    <a href="reports.php" class="flex items-center px-3 py-2.5 text-sm rounded-lg <?= $currentPage === 'reports.php' ? 'bg-brand-600 text-white' : 'text-slate-300' ?>">
                        <i class="fa-solid fa-file-invoice-dollar ml-3 w-5 text-center text-blue-400"></i> التقارير
                    </a>
                <?php endif; ?>
                <?php if (hasModule('hr')): ?>
                    <a href="employees.php" class="flex items-center px-3 py-2.5 text-sm rounded-lg <?= $currentPage === 'employees.php' ? 'bg-brand-600 text-white' : 'text-slate-300' ?>">
                        <i class="fa-solid fa-user-tie ml-3 w-5 text-center text-purple-400"></i> الموظفين
                    </a>
                    <a href="payroll.php" class="flex items-center px-3 py-2.5 text-sm rounded-lg <?= $currentPage === 'payroll.php' ? 'bg-brand-600 text-white' : 'text-slate-300' ?>">
                        <i class="fa-solid fa-money-check-dollar ml-3 w-5 text-center text-teal-400"></i> الرواتب
                    </a>
                <?php endif; ?>
                <?php if (isCompanyAdmin()): ?>
                    <a href="settings.php" class="flex items-center px-3 py-2.5 text-sm rounded-lg <?= $currentPage === 'settings.php' ? 'bg-brand-600 text-white' : 'text-slate-300' ?>">
                        <i class="fa-solid fa-building-gear ml-3 w-5 text-center text-indigo-400"></i> إعدادات الشركة والمقر
                    </a>
                    <a href="users.php" class="flex items-center px-3 py-2.5 text-sm rounded-lg <?= $currentPage === 'users.php' ? 'bg-brand-600 text-white' : 'text-slate-300' ?>">
                        <i class="fa-solid fa-users ml-3 w-5 text-center"></i> مستخدمي الشركة
                    </a>
                <?php endif; ?>
            <?php endif; ?>
            
            <a href="logout.php" class="flex items-center px-3 py-2.5 text-sm rounded-lg text-rose-400 mt-4">
                <i class="fa-solid fa-power-off ml-3 w-5 text-center"></i> تسجيل خروج
            </a>
        </nav>
    </div>
</div>

<!-- Mobile App Bottom Navigation Bar (Native App Style) -->
<nav class="no-print md:hidden fixed bottom-0 inset-x-0 bg-white/95 backdrop-blur-md border-t border-slate-200 z-40 py-2 px-3 shadow-lg flex items-center justify-around">
    <a href="dashboard.php" class="flex flex-col items-center justify-center flex-1 py-1 <?= $currentPage === 'dashboard.php' ? 'text-brand-600 font-bold' : 'text-slate-500' ?>">
        <i class="fa-solid fa-chart-pie text-lg mb-0.5"></i>
        <span class="text-[10px]">الرئيسية</span>
    </a>

    <?php if ($isSuperMaster): ?>
        <a href="companies.php" class="flex flex-col items-center justify-center flex-1 py-1 <?= $currentPage === 'companies.php' ? 'text-brand-600 font-bold' : 'text-slate-500' ?>">
            <i class="fa-solid fa-building-flag text-lg mb-0.5"></i>
            <span class="text-[10px]">الشركات</span>
        </a>
        <a href="users.php" class="flex flex-col items-center justify-center flex-1 py-1 <?= $currentPage === 'users.php' ? 'text-brand-600 font-bold' : 'text-slate-500' ?>">
            <i class="fa-solid fa-users-gear text-lg mb-0.5"></i>
            <span class="text-[10px]">المستخدمين</span>
        </a>
    <?php else: ?>
        <?php if (hasModule('treasury')): ?>
            <a href="treasury.php" class="flex flex-col items-center justify-center flex-1 py-1 <?= $currentPage === 'treasury.php' ? 'text-emerald-600 font-bold' : 'text-slate-500' ?>">
                <i class="fa-solid fa-vault text-lg mb-0.5"></i>
                <span class="text-[10px]">الخزنة</span>
            </a>
        <?php endif; ?>

        <!-- Central Action Button (Quick Expense or Deposit Modal) -->
        <?php if (hasModule('expenses')): ?>
            <button onclick="openModal('quick-expense-modal')" class="-mt-5 bg-gradient-to-tr from-rose-600 to-rose-500 text-white rounded-full w-12 h-12 flex items-center justify-center shadow-lg hover:shadow-xl hover:scale-105 transition transform active:scale-95" aria-label="إضافة مصروف">
                <i class="fa-solid fa-plus text-xl"></i>
            </button>

            <a href="expenses.php" class="flex flex-col items-center justify-center flex-1 py-1 <?= $currentPage === 'expenses.php' ? 'text-rose-600 font-bold' : 'text-slate-500' ?>">
                <i class="fa-solid fa-receipt text-lg mb-0.5"></i>
                <span class="text-[10px]">المصروفات</span>
            </a>
        <?php endif; ?>

        <?php if (hasModule('reports')): ?>
            <a href="reports.php" class="flex flex-col items-center justify-center flex-1 py-1 <?= $currentPage === 'reports.php' ? 'text-blue-600 font-bold' : 'text-slate-500' ?>">
                <i class="fa-solid fa-file-invoice-dollar text-lg mb-0.5"></i>
                <span class="text-[10px]">التقارير</span>
            </a>
        <?php endif; ?>
    <?php endif; ?>

    <button id="mobile-more-btn" class="flex flex-col items-center justify-center flex-1 py-1 text-slate-500">
        <i class="fa-solid fa-ellipsis text-lg mb-0.5"></i>
        <span class="text-[10px]">المزيد</span>
    </button>
</nav>

<script>
    // Mobile Drawer open/close logic
    const drawer = document.getElementById('mobile-sidebar-drawer');
    const openBtn = document.getElementById('mobile-sidebar-toggle');
    const closeBtn = document.getElementById('mobile-sidebar-close');
    const moreBtn = document.getElementById('mobile-more-btn');

    function toggleDrawer(open) {
        if (open) {
            drawer.classList.remove('hidden');
        } else {
            drawer.classList.add('hidden');
        }
    }

    if (openBtn) openBtn.addEventListener('click', () => toggleDrawer(true));
    if (moreBtn) moreBtn.addEventListener('click', () => toggleDrawer(true));
    if (closeBtn) closeBtn.addEventListener('click', () => toggleDrawer(false));
    if (drawer) {
        drawer.addEventListener('click', (e) => {
            if (e.target === drawer) toggleDrawer(false);
        });
    }
</script>
