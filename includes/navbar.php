<?php
$isSuper = isSuperAdmin();
$isImpersonating = !empty($currentUser['is_impersonating']);
?>
<header id="app-header" class="no-print bg-white/95 backdrop-blur-md border-b border-slate-200 fixed md:sticky top-0 inset-x-0 z-30 shadow-xs safe-area-top">
    <div class="px-4 sm:px-6 py-2.5 sm:py-3 flex items-center justify-between min-h-[3.75rem]">
        <!-- Right side: Brand & Mobile toggle & Context Badge -->
        <div class="flex items-center space-x-3 space-x-reverse">
            <!-- Mobile Menu Toggle Button -->
            <button id="mobile-sidebar-toggle" onclick="openMobileSidebar()" class="md:hidden p-2 rounded-lg text-slate-600 hover:text-slate-900 hover:bg-slate-100 focus:outline-none" aria-label="فتح القائمة">
                <i class="fa-solid fa-bars text-lg"></i>
            </button>

            <!-- Company or Platform Indicator -->
            <div class="flex items-center space-x-2 space-x-reverse">
                <?php if ($isSuper && empty($currentUser['active_company_id'])): ?>
                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold bg-indigo-100 text-indigo-800 border border-indigo-200">
                        <i class="fa-solid fa-crown ml-1 text-indigo-600"></i>
                        منصة السوبر أدمن
                    </span>
                <?php else: ?>
                    <div class="flex items-center">
                        <div class="w-8 h-8 rounded-lg bg-brand-600 text-white flex items-center justify-center font-black text-sm ml-2 shadow-xs">
                            <i class="fa-solid fa-building"></i>
                        </div>
                        <div>
                            <span class="font-extrabold text-slate-800 text-sm sm:text-base leading-tight block">
                                <?= htmlspecialchars($currentUser['company_name'] ?? 'شركتي') ?>
                            </span>
                            <span class="text-[11px] text-slate-500 font-medium block">
                                <?= htmlspecialchars($currentUser['name']) ?> (<?= $currentUser['role'] === 'company_admin' ? 'مدير الشركة' : ($currentUser['role'] === 'data_entry' ? 'مدخل بيانات' : 'سوبر أدمن') ?>)
                            </span>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($isImpersonating): ?>
                    <a href="companies.php?exit_impersonate=1" class="text-xs bg-amber-500 hover:bg-amber-600 text-white font-bold px-2 py-0.5 rounded-full transition shadow-xs flex items-center">
                        <i class="fa-solid fa-arrow-right-from-bracket ml-1 text-[10px]"></i>
                        خروج للسوبر أدمن
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Left side: Actions & Safe Balance -->
        <div class="flex items-center space-x-3 space-x-reverse">
            <!-- Safe Balance Pill (if in company and has treasury) -->
            <?php if (!empty($mainSafe) && hasModule('treasury')): ?>
                <?php 
                $navBals = $mainSafe['balances'] ?? [$currentUser['currency'] ?? 'ج.م' => $mainSafe['balance']];
                $activeNavBals = array_filter($navBals, fn($b) => abs($b) > 0.001);
                if (empty($activeNavBals)) {
                    $activeNavBals = [$currentUser['currency'] ?? 'ج.م' => 0.00];
                }
                ?>
                <a href="treasury.php" class="hidden sm:flex items-center gap-1.5 bg-emerald-50 hover:bg-emerald-100/80 border border-emerald-200 text-emerald-800 px-3 py-1.5 rounded-xl text-xs font-bold shadow-xs transition" title="عرض تفاصيل الخزنة والأرصدة">
                    <i class="fa-solid fa-vault ml-1 text-emerald-600 text-sm"></i>
                    <span class="text-slate-600 ml-1">الخزنة:</span>
                    <?php foreach ($activeNavBals as $nCurr => $nBal): ?>
                        <span class="inline-flex items-center px-2 py-0.5 rounded-lg bg-white border border-emerald-300 font-black text-emerald-700 dir-ltr text-xs shadow-2xs">
                            <?= formatMoney($nBal, $nCurr) ?>
                        </span>
                    <?php endforeach; ?>
                </a>

                <!-- Quick Action Buttons -->
                <a href="receipts.php" class="hidden lg:inline-flex items-center bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold px-3 py-1.5 rounded-lg shadow-sm transition">
                    <i class="fa-solid fa-receipt ml-1.5"></i>
                    وصل استلام
                </a>

                <button onclick="openModal('quick-deposit-modal')" class="hidden md:inline-flex items-center bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold px-3 py-1.5 rounded-lg shadow-sm transition">
                    <i class="fa-solid fa-circle-plus ml-1.5"></i>
                    إيداع سريع
                </button>

                <button onclick="openModal('quick-expense-modal')" class="hidden md:inline-flex items-center bg-rose-600 hover:bg-rose-700 text-white text-xs font-bold px-3 py-1.5 rounded-lg shadow-sm transition">
                    <i class="fa-solid fa-circle-minus ml-1.5"></i>
                    مصروف جديد
                </button>
            <?php endif; ?>

            <!-- User Menu & Logout -->
            <div class="flex items-center space-x-2 space-x-reverse">
                <a href="logout.php" title="تسجيل الخروج" class="p-2 text-slate-500 hover:text-rose-600 hover:bg-rose-50 rounded-lg transition" aria-label="تسجيل الخروج">
                    <i class="fa-solid fa-power-off text-base"></i>
                </a>
            </div>
        </div>
    </div>
</header>
