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
<div id="mobile-sidebar-drawer" class="fixed inset-0 z-50 hidden md:hidden">
    <!-- Backdrop -->
    <div class="fixed inset-0 bg-slate-900/80 backdrop-blur-xs transition-opacity" onclick="closeMobileSidebar()"></div>
    
    <!-- Drawer Panel -->
    <div class="relative w-72 max-w-[85vw] bg-slate-900 text-white h-full flex flex-col p-4 shadow-2xl z-10 transition-transform">
        <div class="flex items-center justify-between pb-4 border-b border-slate-800">
            <div class="flex items-center space-x-2 space-x-reverse">
                <div class="w-9 h-9 rounded-xl bg-brand-600 text-white flex items-center justify-center font-bold">
                    <i class="fa-solid fa-coins"></i>
                </div>
                <div>
                    <span class="font-extrabold text-sm block"><?= htmlspecialchars(APP_SHORT_NAME) ?></span>
                    <span class="text-[10px] text-slate-400 block"><?= htmlspecialchars($currentUser['company_name'] ?? 'لوحة التحكم') ?></span>
                </div>
            </div>
            <button id="mobile-sidebar-close" onclick="closeMobileSidebar()" class="text-slate-400 hover:text-white text-xl p-1.5 rounded-lg hover:bg-slate-800">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <nav class="flex-1 py-4 space-y-1 overflow-y-auto">
            <a href="dashboard.php" onclick="closeMobileSidebar()" class="flex items-center px-3 py-2.5 text-sm rounded-lg <?= $currentPage === 'dashboard.php' ? 'bg-brand-600 text-white font-bold' : 'text-slate-300 hover:bg-slate-800' ?>">
                <i class="fa-solid fa-chart-pie ml-3 w-5 text-center"></i> لوحة التحكم
            </a>

            <?php if ($isSuperMaster): ?>
                <p class="px-3 text-[10px] font-bold uppercase tracking-wider text-slate-500 mt-4 mb-1">إدارة المنصة</p>
                <a href="companies.php" onclick="closeMobileSidebar()" class="flex items-center px-3 py-2.5 text-sm rounded-lg <?= $currentPage === 'companies.php' ? 'bg-brand-600 text-white font-bold' : 'text-slate-300 hover:bg-slate-800' ?>">
                    <i class="fa-solid fa-building-flag ml-3 w-5 text-center"></i> إدارة الشركات
                </a>
                <a href="users.php" onclick="closeMobileSidebar()" class="flex items-center px-3 py-2.5 text-sm rounded-lg <?= $currentPage === 'users.php' ? 'bg-brand-600 text-white font-bold' : 'text-slate-300 hover:bg-slate-800' ?>">
                    <i class="fa-solid fa-users-gear ml-3 w-5 text-center"></i> المستخدمين
                </a>
            <?php else: ?>
                <p class="px-3 text-[10px] font-bold uppercase tracking-wider text-slate-500 mt-4 mb-1">المالية والحسابات</p>
                <?php if (hasModule('treasury')): ?>
                    <a href="treasury.php" onclick="closeMobileSidebar()" class="flex items-center px-3 py-2.5 text-sm rounded-lg <?= $currentPage === 'treasury.php' ? 'bg-brand-600 text-white font-bold' : 'text-slate-300 hover:bg-slate-800' ?>">
                        <i class="fa-solid fa-vault ml-3 w-5 text-center text-emerald-400"></i> الخزنة والإيداعات
                    </a>
                    <a href="receipts.php" onclick="closeMobileSidebar()" class="flex items-center px-3 py-2.5 text-sm rounded-lg <?= $currentPage === 'receipts.php' ? 'bg-brand-600 text-white font-bold' : 'text-slate-300 hover:bg-slate-800' ?>">
                        <i class="fa-solid fa-hand-holding-dollar ml-3 w-5 text-center text-emerald-300"></i> إيصالات الاستلام (سند قبض)
                    </a>
                <?php endif; ?>
                <?php if (hasModule('expenses')): ?>
                    <a href="expenses.php" onclick="closeMobileSidebar()" class="flex items-center px-3 py-2.5 text-sm rounded-lg <?= $currentPage === 'expenses.php' ? 'bg-brand-600 text-white font-bold' : 'text-slate-300 hover:bg-slate-800' ?>">
                        <i class="fa-solid fa-receipt ml-3 w-5 text-center text-rose-400"></i> المصروفات
                    </a>
                    <a href="expense_categories.php" onclick="closeMobileSidebar()" class="flex items-center px-3 py-2.5 text-sm rounded-lg <?= $currentPage === 'expense_categories.php' ? 'bg-brand-600 text-white font-bold' : 'text-slate-300 hover:bg-slate-800' ?>">
                        <i class="fa-solid fa-tags ml-3 w-5 text-center text-amber-400"></i> بنود المصروفات
                    </a>
                <?php endif; ?>
                <?php if (hasModule('reports')): ?>
                    <a href="reports.php" onclick="closeMobileSidebar()" class="flex items-center px-3 py-2.5 text-sm rounded-lg <?= $currentPage === 'reports.php' ? 'bg-brand-600 text-white font-bold' : 'text-slate-300 hover:bg-slate-800' ?>">
                        <i class="fa-solid fa-file-invoice-dollar ml-3 w-5 text-center text-blue-400"></i> التقارير المالية
                    </a>
                <?php endif; ?>
                <?php if (hasModule('hr')): ?>
                    <p class="px-3 text-[10px] font-bold uppercase tracking-wider text-slate-500 mt-4 mb-1">الموارد البشرية</p>
                    <a href="employees.php" onclick="closeMobileSidebar()" class="flex items-center px-3 py-2.5 text-sm rounded-lg <?= $currentPage === 'employees.php' ? 'bg-brand-600 text-white font-bold' : 'text-slate-300 hover:bg-slate-800' ?>">
                        <i class="fa-solid fa-user-tie ml-3 w-5 text-center text-purple-400"></i> دليل الموظفين
                    </a>
                    <a href="payroll.php" onclick="closeMobileSidebar()" class="flex items-center px-3 py-2.5 text-sm rounded-lg <?= $currentPage === 'payroll.php' ? 'bg-brand-600 text-white font-bold' : 'text-slate-300 hover:bg-slate-800' ?>">
                        <i class="fa-solid fa-money-check-dollar ml-3 w-5 text-center text-teal-400"></i> مسيرات الرواتب
                    </a>
                <?php endif; ?>
                <?php if (isCompanyAdmin()): ?>
                    <p class="px-3 text-[10px] font-bold uppercase tracking-wider text-slate-500 mt-4 mb-1">إدارة الشركة</p>
                    <a href="settings.php" onclick="closeMobileSidebar()" class="flex items-center px-3 py-2.5 text-sm rounded-lg <?= $currentPage === 'settings.php' ? 'bg-brand-600 text-white font-bold' : 'text-slate-300 hover:bg-slate-800' ?>">
                        <i class="fa-solid fa-building-gear ml-3 w-5 text-center text-indigo-400"></i> إعدادات الشركة والمقر
                    </a>
                    <a href="users.php" onclick="closeMobileSidebar()" class="flex items-center px-3 py-2.5 text-sm rounded-lg <?= $currentPage === 'users.php' ? 'bg-brand-600 text-white font-bold' : 'text-slate-300 hover:bg-slate-800' ?>">
                        <i class="fa-solid fa-users ml-3 w-5 text-center"></i> مستخدمي الشركة
                    </a>
                <?php endif; ?>
            <?php endif; ?>
            
            <div class="pt-4 mt-4 border-t border-slate-800">
                <a href="logout.php" class="flex items-center px-3 py-2.5 text-sm rounded-lg text-rose-400 hover:bg-rose-500/10 font-bold">
                    <i class="fa-solid fa-power-off ml-3 w-5 text-center"></i> تسجيل خروج
                </a>
            </div>
        </nav>
    </div>
</div>

<!-- Mobile Quick Action Sheet (Modal Bottom Sheet) -->
<div id="mobile-quick-actions-sheet" class="modal-container fixed inset-0 z-50 hidden items-end justify-center bg-slate-900/60 backdrop-blur-xs p-0 transition-opacity">
    <div class="modal-backdrop fixed inset-0" onclick="closeMobileFabMenu()"></div>
    <div class="relative bg-white rounded-t-3xl shadow-2xl w-full max-w-lg p-5 z-10 border-t border-slate-200 modal-animate-enter safe-area-bottom">
        <div class="w-12 h-1.5 bg-slate-300 rounded-full mx-auto mb-4"></div>
        <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-4">
            <h3 class="font-black text-slate-800 text-base flex items-center">
                <i class="fa-solid fa-bolt ml-2 text-amber-500"></i>
                إجراءات سريعة
            </h3>
            <button onclick="closeMobileFabMenu()" class="text-slate-400 hover:text-slate-600 text-lg p-1">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <div class="grid grid-cols-2 gap-3 mb-2">
            <?php if (!$isSuperMaster && hasModule('expenses')): ?>
                <button type="button" onclick="closeMobileFabMenu(); openModal('quick-expense-modal')" class="flex flex-col items-center justify-center p-4 rounded-2xl bg-rose-50 border border-rose-100 hover:bg-rose-100 transition active:scale-95 text-center">
                    <div class="w-12 h-12 rounded-xl bg-rose-600 text-white flex items-center justify-center text-xl shadow-md mb-2">
                        <i class="fa-solid fa-receipt"></i>
                    </div>
                    <span class="text-xs font-bold text-slate-800">تسجيل مصروف</span>
                    <span class="text-[10px] text-slate-400 mt-0.5">خصم من الخزنة</span>
                </button>
            <?php endif; ?>

            <?php if (!$isSuperMaster && hasModule('treasury')): ?>
                <button type="button" onclick="closeMobileFabMenu(); openModal('quick-deposit-modal')" class="flex flex-col items-center justify-center p-4 rounded-2xl bg-emerald-50 border border-emerald-100 hover:bg-emerald-100 transition active:scale-95 text-center">
                    <div class="w-12 h-12 rounded-xl bg-emerald-600 text-white flex items-center justify-center text-xl shadow-md mb-2">
                        <i class="fa-solid fa-circle-plus"></i>
                    </div>
                    <span class="text-xs font-bold text-slate-800">إيداع نقدية</span>
                    <span class="text-[10px] text-slate-400 mt-0.5">إضافة لرصيد الخزنة</span>
                </button>

                <a href="receipts.php" class="flex flex-col items-center justify-center p-3.5 rounded-2xl bg-indigo-50 border border-indigo-100 hover:bg-indigo-100 transition active:scale-95 text-center col-span-2">
                    <div class="flex items-center space-x-3 space-x-reverse">
                        <div class="w-10 h-10 rounded-xl bg-indigo-600 text-white flex items-center justify-center text-lg shadow-md shrink-0">
                            <i class="fa-solid fa-file-invoice-dollar"></i>
                        </div>
                        <div class="text-right">
                            <span class="text-xs font-bold text-slate-800 block">إصدار إيصال استلام جديد (سند قبض)</span>
                            <span class="text-[10px] text-slate-500 block">مع رمز QR وطباعة ومشاركة إلكترونية</span>
                        </div>
                    </div>
                </a>
            <?php endif; ?>

            <?php if ($isSuperMaster): ?>
                <a href="companies.php" class="flex flex-col items-center justify-center p-4 rounded-2xl bg-indigo-50 border border-indigo-100 text-center">
                    <div class="w-12 h-12 rounded-xl bg-brand-600 text-white flex items-center justify-center text-xl shadow-md mb-2">
                        <i class="fa-solid fa-building-circle-check"></i>
                    </div>
                    <span class="text-xs font-bold text-slate-800">إدارة الشركات</span>
                </a>
                <a href="users.php" class="flex flex-col items-center justify-center p-4 rounded-2xl bg-purple-50 border border-purple-100 text-center">
                    <div class="w-12 h-12 rounded-xl bg-purple-600 text-white flex items-center justify-center text-xl shadow-md mb-2">
                        <i class="fa-solid fa-user-plus"></i>
                    </div>
                    <span class="text-xs font-bold text-slate-800">إدارة المستخدمين</span>
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Mobile App Bottom Navigation Bar (Symmetrical 5-Column Native Layout) -->
<nav id="app-bottom-nav" class="no-print md:hidden fixed bottom-0 inset-x-0 bg-white/95 backdrop-blur-md border-t border-slate-200/80 z-40 px-2 py-1 shadow-[0_-4px_25px_rgba(0,0,0,0.06)] safe-area-bottom">
    <div class="grid grid-cols-5 items-center text-center h-14">
        <!-- 1: Dashboard -->
        <a href="dashboard.php" class="flex flex-col items-center justify-center py-1 transition <?= $currentPage === 'dashboard.php' ? 'text-brand-600 font-bold' : 'text-slate-500 hover:text-slate-800' ?>">
            <i class="fa-solid fa-chart-pie text-lg mb-0.5 <?= $currentPage === 'dashboard.php' ? 'text-brand-600 scale-110' : '' ?>"></i>
            <span class="text-[10px] leading-tight">الرئيسية</span>
        </a>

        <?php if ($isSuperMaster): ?>
            <!-- 2: Companies -->
            <a href="companies.php" class="flex flex-col items-center justify-center py-1 transition <?= $currentPage === 'companies.php' ? 'text-brand-600 font-bold' : 'text-slate-500 hover:text-slate-800' ?>">
                <i class="fa-solid fa-building-flag text-lg mb-0.5 <?= $currentPage === 'companies.php' ? 'text-brand-600 scale-110' : '' ?>"></i>
                <span class="text-[10px] leading-tight">الشركات</span>
            </a>

            <!-- 3: Elevated Center FAB Button (+) -->
            <div class="flex items-center justify-center -mt-6">
                <button type="button" onclick="toggleMobileFabMenu()" class="w-12 h-12 bg-gradient-to-tr from-brand-600 to-indigo-500 text-white rounded-full flex items-center justify-center shadow-lg hover:shadow-xl hover:scale-105 active:scale-95 transition transform border-4 border-slate-50 ring-2 ring-brand-100" aria-label="إجراء سريع">
                    <i id="fab-icon" class="fa-solid fa-plus text-lg transition-transform duration-200"></i>
                </button>
            </div>

            <!-- 4: Users -->
            <a href="users.php" class="flex flex-col items-center justify-center py-1 transition <?= $currentPage === 'users.php' ? 'text-brand-600 font-bold' : 'text-slate-500 hover:text-slate-800' ?>">
                <i class="fa-solid fa-users-gear text-lg mb-0.5 <?= $currentPage === 'users.php' ? 'text-brand-600 scale-110' : '' ?>"></i>
                <span class="text-[10px] leading-tight">المستخدمين</span>
            </a>

            <!-- 5: Full Menu -->
            <button type="button" onclick="openMobileSidebar()" class="flex flex-col items-center justify-center py-1 text-slate-500 hover:text-slate-800 transition">
                <i class="fa-solid fa-bars-staggered text-lg mb-0.5"></i>
                <span class="text-[10px] leading-tight">القائمة</span>
            </button>

        <?php else: ?>
            <!-- 2: Treasury -->
            <a href="treasury.php" class="flex flex-col items-center justify-center py-1 transition <?= $currentPage === 'treasury.php' ? 'text-emerald-600 font-bold' : 'text-slate-500 hover:text-slate-800' ?>">
                <i class="fa-solid fa-vault text-lg mb-0.5 <?= $currentPage === 'treasury.php' ? 'text-emerald-600 scale-110' : '' ?>"></i>
                <span class="text-[10px] leading-tight">الخزنة</span>
            </a>

            <!-- 3: Elevated Center FAB Button (+) -->
            <div class="flex items-center justify-center -mt-6">
                <button type="button" onclick="toggleMobileFabMenu()" class="w-12 h-12 bg-gradient-to-tr from-rose-600 to-rose-500 text-white rounded-full flex items-center justify-center shadow-lg hover:shadow-xl hover:scale-105 active:scale-95 transition transform border-4 border-slate-50 ring-2 ring-rose-100" aria-label="إجراء سريع">
                    <i id="fab-icon" class="fa-solid fa-plus text-lg transition-transform duration-200"></i>
                </button>
            </div>

            <!-- 4: Expenses -->
            <a href="expenses.php" class="flex flex-col items-center justify-center py-1 transition <?= $currentPage === 'expenses.php' ? 'text-rose-600 font-bold' : 'text-slate-500 hover:text-slate-800' ?>">
                <i class="fa-solid fa-receipt text-lg mb-0.5 <?= $currentPage === 'expenses.php' ? 'text-rose-600 scale-110' : '' ?>"></i>
                <span class="text-[10px] leading-tight">المصروفات</span>
            </a>

            <!-- 5: Reports -->
            <a href="reports.php" class="flex flex-col items-center justify-center py-1 transition <?= $currentPage === 'reports.php' ? 'text-blue-600 font-bold' : 'text-slate-500 hover:text-slate-800' ?>">
                <i class="fa-solid fa-file-invoice-dollar text-lg mb-0.5 <?= $currentPage === 'reports.php' ? 'text-blue-600 scale-110' : '' ?>"></i>
                <span class="text-[10px] leading-tight">التقارير</span>
            </a>
        <?php endif; ?>
    </div>
</nav>

<script>
    // Global Navigation & Drawer Handlers
    window.openMobileSidebar = function() {
        const d = document.getElementById('mobile-sidebar-drawer');
        if (d) {
            d.classList.remove('hidden');
            d.classList.add('flex');
            document.body.style.overflow = 'hidden';
        }
    };

    window.closeMobileSidebar = function() {
        const d = document.getElementById('mobile-sidebar-drawer');
        if (d) {
            d.classList.add('hidden');
            d.classList.remove('flex');
            document.body.style.overflow = 'auto';
        }
    };

    window.toggleMobileFabMenu = function() {
        const sheet = document.getElementById('mobile-quick-actions-sheet');
        const icon = document.getElementById('fab-icon');
        if (!sheet) return;
        if (sheet.classList.contains('hidden')) {
            sheet.classList.remove('hidden');
            sheet.classList.add('flex');
            if (icon) icon.classList.add('rotate-45');
            document.body.style.overflow = 'hidden';
        } else {
            window.closeMobileFabMenu();
        }
    };

    window.closeMobileFabMenu = function() {
        const sheet = document.getElementById('mobile-quick-actions-sheet');
        const icon = document.getElementById('fab-icon');
        if (!sheet) return;
        sheet.classList.add('hidden');
        sheet.classList.remove('flex');
        if (icon) icon.classList.remove('rotate-45');
        document.body.style.overflow = 'auto';
    };

    // Attach event listeners as backup
    document.addEventListener('DOMContentLoaded', () => {
        const openBtn = document.getElementById('mobile-sidebar-toggle');
        const closeBtn = document.getElementById('mobile-sidebar-close');
        if (openBtn) openBtn.onclick = window.openMobileSidebar;
        if (closeBtn) closeBtn.onclick = window.closeMobileSidebar;
    });
</script>
