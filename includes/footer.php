<?php
$quickCategories = [];
$quickProjects = [];
$mainSafe = null;
if (!empty($activeCompanyId)) {
    $db = getDBConnection();
    $mainSafe = getCompanyMainSafe($activeCompanyId);

    $catStmt = $db->prepare("SELECT * FROM expense_categories WHERE company_id = :cid AND is_active = 1 ORDER BY name ASC");
    $catStmt->execute([':cid' => $activeCompanyId]);
    $quickCategories = $catStmt->fetchAll();

    if (hasModule('projects')) {
        $qpStmt = $db->prepare("SELECT id, name, currency FROM projects WHERE company_id = :cid ORDER BY name ASC");
        $qpStmt->execute([':cid' => $activeCompanyId]);
        $quickProjects = $qpStmt->fetchAll();
        foreach ($quickProjects as &$qp) {
            $pSafe = getProjectSafe($activeCompanyId, (int)$qp['id'], $qp['name']);
            $qp['safe_id'] = $pSafe['id'];
            $qp['safe_balance'] = $pSafe['balance'];
            $qp['safe_name'] = $pSafe['name'];
        }
        unset($qp);
    }
}
?>
            </main>
        </div>
    </div>

    <!-- ========================================== -->
    <!-- GLOBAL MODAL: CASH VOUCHER TYPE SELECTOR   -->
    <!-- وصل استلام نقدي وسند مالي (قبض وارد / صرف صادر) -->
    <!-- ========================================== -->
    <div id="cash-voucher-modal" class="modal-container fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/60 backdrop-blur-xs p-4 overflow-y-auto">
        <div class="modal-backdrop fixed inset-0" onclick="closeModal('cash-voucher-modal')"></div>
        <div class="relative bg-white rounded-3xl shadow-2xl max-w-lg w-full p-6 sm:p-7 modal-animate-enter z-10 border border-slate-100">
            
            <!-- Header -->
            <div class="flex items-center justify-between pb-4 border-b border-slate-100 mb-5">
                <div class="flex items-center space-x-3 space-x-reverse">
                    <div class="w-11 h-11 rounded-2xl bg-indigo-50 text-indigo-600 flex items-center justify-center text-xl font-bold shadow-xs">
                        <i class="fa-solid fa-receipt"></i>
                    </div>
                    <div>
                        <h3 class="font-black text-slate-900 text-lg">وصل استلام نقدي وسند مالي</h3>
                        <p class="text-xs text-slate-500">اختر نوع العملية: هل تريد استلام نقدية أم تسليم وصرف نقدية؟</p>
                    </div>
                </div>
                <button type="button" onclick="closeModal('cash-voucher-modal')" class="w-8 h-8 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-500 flex items-center justify-center transition">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <!-- 2 Direction Cards -->
            <div class="space-y-4">
                
                <!-- Type 1: استلام نقدية (سند قبض وارد) -->
                <a href="receipt_create.php" onclick="closeModal('cash-voucher-modal')" class="group block p-4.5 rounded-2xl border-2 border-indigo-100 bg-gradient-to-r from-indigo-50/70 to-white hover:border-indigo-600 hover:shadow-lg transition-all active:scale-[0.99]">
                    <div class="flex items-start space-x-3.5 space-x-reverse">
                        <div class="w-12 h-12 rounded-2xl bg-indigo-600 text-white flex items-center justify-center text-xl shrink-0 shadow-md shadow-indigo-600/30 group-hover:scale-105 transition">
                            <i class="fa-solid fa-arrow-down-left"></i>
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center justify-between mb-1">
                                <h4 class="font-black text-slate-900 text-base group-hover:text-indigo-600 transition flex items-center">
                                    استلام نقدية (سند قبض)
                                    <i class="fa-solid fa-arrow-left mr-2 text-xs opacity-0 group-hover:opacity-100 transition-opacity"></i>
                                </h4>
                                <span class="text-[10px] font-black text-emerald-700 bg-emerald-100 px-2.5 py-0.5 rounded-full">
                                    وارد للخزنة +
                                </span>
                            </div>
                            <p class="text-xs text-slate-600 leading-relaxed font-medium">
                                <strong>أنا استلمت فلوس من العميل:</strong> مقابل تقديم خدمة أو دفعة بمشروع عقاري، واستخراج إيصال قبض رسمي برابط توقيع إلكتروني وتوثيق بالخزنة.
                            </p>
                        </div>
                    </div>
                </a>

                <!-- Type 2: تسليم نقدية (سند صرف صادر) -->
                <a href="voucher_create.php" onclick="closeModal('cash-voucher-modal')" class="group block p-4.5 rounded-2xl border-2 border-rose-100 bg-gradient-to-r from-rose-50/70 to-white hover:border-rose-600 hover:shadow-lg transition-all active:scale-[0.99]">
                    <div class="flex items-start space-x-3.5 space-x-reverse">
                        <div class="w-12 h-12 rounded-2xl bg-rose-600 text-white flex items-center justify-center text-xl shrink-0 shadow-md shadow-rose-600/30 group-hover:scale-105 transition">
                            <i class="fa-solid fa-arrow-up-right"></i>
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center justify-between mb-1">
                                <h4 class="font-black text-slate-900 text-base group-hover:text-rose-600 transition flex items-center">
                                    تسليم نقدية (سند صرف)
                                    <i class="fa-solid fa-arrow-left mr-2 text-xs opacity-0 group-hover:opacity-100 transition-opacity"></i>
                                </h4>
                                <span class="text-[10px] font-black text-rose-700 bg-rose-100 px-2.5 py-0.5 rounded-full">
                                    صادر من الخزنة -
                                </span>
                            </div>
                            <p class="text-xs text-slate-600 leading-relaxed font-medium">
                                <strong>أنا طلعت أو سلمت فلوس للعميل أو مورد:</strong> مقابل مستحقات أو مصروفات عقار أو مقاولات، واستخراج سند صرف رسمي معتمد برابط توقيع إلكتروني للمستلم.
                            </p>
                        </div>
                    </div>
                </a>

            </div>

            <!-- Footer close -->
            <div class="pt-4 mt-3 text-center border-t border-slate-100">
                <button type="button" onclick="closeModal('cash-voucher-modal')" class="text-xs font-bold text-slate-400 hover:text-slate-700 transition">
                    إلغاء التراجع
                </button>
            </div>

        </div>
    </div>

    <!-- QUICK DEPOSIT MODAL -->
    <?php if (!empty($activeCompanyId) && hasModule('treasury') && canUser('treasury') && canViewBalances() && !isViewOnly()): ?>
    <div id="quick-deposit-modal" class="modal-container fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/60 backdrop-blur-xs p-4 overflow-y-auto">
        <div class="modal-backdrop fixed inset-0" onclick="closeModal('quick-deposit-modal')"></div>
        <div class="relative bg-white rounded-2xl shadow-2xl max-w-md w-full p-6 modal-animate-enter z-10 border border-slate-100">
            <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-4">
                <div class="flex items-center space-x-2 space-x-reverse">
                    <div class="w-8 h-8 rounded-lg bg-emerald-100 text-emerald-600 flex items-center justify-center font-bold">
                        <i class="fa-solid fa-circle-plus"></i>
                    </div>
                    <h3 class="font-bold text-slate-800 text-lg">إيداع نقدية في الخزنة</h3>
                </div>
                <button onclick="closeModal('quick-deposit-modal')" class="text-slate-400 hover:text-slate-600 text-lg">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <form action="treasury.php" method="POST" class="space-y-4">
                <?= getCSRFInput() ?>
                <input type="hidden" name="action" value="deposit">

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">المبلغ المودع والعملة <span class="text-red-500">*</span></label>
                    <div class="flex gap-2">
                        <div class="relative flex-1">
                            <input type="number" step="0.01" min="0.01" name="amount" required placeholder="0.00" class="w-full text-xl font-bold bg-slate-50 border border-slate-300 rounded-xl px-4 py-2.5 text-emerald-600 focus:bg-white focus:ring-2 focus:ring-emerald-500 focus:outline-none">
                        </div>
                        <div class="w-36 shrink-0">
                            <select name="currency" class="w-full h-full text-xs font-bold bg-slate-50 border border-slate-300 rounded-xl px-2.5 py-2 text-slate-800 focus:bg-white focus:ring-2 focus:ring-emerald-500 focus:outline-none">
                                <option value="ر.س" <?= ($currentUser['currency'] ?? 'ر.س') === 'ر.س' ? 'selected' : '' ?>>ر.س (ريال)</option>
                                <option value="ج.م" <?= ($currentUser['currency'] ?? 'ر.س') === 'ج.م' ? 'selected' : '' ?>>ج.م (جنيه)</option>
                                <option value="$" <?= ($currentUser['currency'] ?? 'ر.س') === '$' ? 'selected' : '' ?>>$ (دولار)</option>
                                <option value="€" <?= ($currentUser['currency'] ?? 'ر.س') === '€' ? 'selected' : '' ?>>€ (يورو)</option>
                                <option value="د.إ" <?= ($currentUser['currency'] ?? 'ر.س') === 'د.إ' ? 'selected' : '' ?>>د.إ (درهم)</option>
                                <option value="د.ك" <?= ($currentUser['currency'] ?? 'ر.س') === 'د.ك' ? 'selected' : '' ?>>د.ك (دينار)</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">مصدر الإيداع / البند <span class="text-red-500">*</span></label>
                    <input type="text" name="category" required placeholder="مثال: توريد مبيعات، تمويل، رصيد إضافي" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-sm focus:bg-white focus:ring-2 focus:ring-emerald-500 focus:outline-none">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">تاريخ الإيداع</label>
                    <input type="date" name="transaction_date" value="<?= date('Y-m-d') ?>" required class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-sm focus:bg-white focus:ring-2 focus:ring-emerald-500 focus:outline-none">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">ملاحظات أو تفاصيل إضافية</label>
                    <textarea name="description" rows="2" placeholder="أي تفاصيل أو رقم إيصال..." class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-sm focus:bg-white focus:ring-2 focus:ring-emerald-500 focus:outline-none"></textarea>
                </div>

                <div class="flex items-center space-x-2 space-x-reverse pt-2">
                    <button type="submit" class="flex-1 bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-2.5 px-4 rounded-xl shadow-md transition text-sm">
                        تأكيد الإيداع الآن
                    </button>
                    <button type="button" onclick="closeModal('quick-deposit-modal')" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold py-2.5 px-4 rounded-xl transition text-sm">
                        إلغاء
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- QUICK EXPENSE MODAL (DISABLED IN FAVOR OF FULL MOBILE PAGE expense_create.php) -->
    <?php if (false && !empty($activeCompanyId) && hasModule('expenses')): ?>
    <div id="quick-expense-modal" class="modal-container fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/60 backdrop-blur-xs p-4 overflow-y-auto">
        <div class="modal-backdrop fixed inset-0" onclick="closeModal('quick-expense-modal')"></div>
        <div class="relative bg-white rounded-3xl shadow-2xl max-w-lg w-full p-6 modal-animate-enter z-10 border border-slate-100 my-6">
            <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-4">
                <div class="flex items-center space-x-2 space-x-reverse">
                    <div class="w-9 h-9 rounded-xl bg-rose-100 text-rose-600 flex items-center justify-center font-bold text-lg">
                        <i class="fa-solid fa-receipt"></i>
                    </div>
                    <div>
                        <h3 class="font-black text-slate-900 text-base">تسجيل مصروف مالي جديد</h3>
                        <p class="text-[11px] text-slate-500">اختر مصدر الصرف وحالة الخصم من الخزنة</p>
                    </div>
                </div>
                <button onclick="closeModal('quick-expense-modal')" class="text-slate-400 hover:text-slate-600 text-lg">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <form action="expenses.php" method="POST" enctype="multipart/form-data" class="space-y-4">
                <?= getCSRFInput() ?>
                <input type="hidden" name="action" value="create_expense">
                <input type="hidden" name="expense_source_type" id="quick_expense_source_type" value="project">

                <!-- 1. Source Selection: Company Safe vs Project Safe -->
                <div class="space-y-1.5">
                    <label class="block text-xs font-bold text-slate-700">جهة ومصدر الصرف المطلوب:</label>
                    <div class="grid grid-cols-2 gap-2.5">
                        <button type="button" onclick="setQuickExpenseSource('project')" id="btn-src-project" class="p-3 rounded-2xl border-2 text-right transition flex flex-col justify-between border-rose-600 bg-rose-50/70 text-rose-900 shadow-xs">
                            <div class="flex items-center justify-between w-full mb-1">
                                <i class="fa-solid fa-helmet-safety text-rose-600 text-base"></i>
                                <span class="w-3 h-3 rounded-full bg-rose-600 inline-block indicator-dot"></span>
                            </div>
                            <strong class="text-xs font-black block">مشروع عقاري معين</strong>
                            <span class="text-[10px] text-rose-700 font-medium">خصم من خزنة مشروع كـ Bulad Cairo</span>
                        </button>

                        <button type="button" onclick="setQuickExpenseSource('company')" id="btn-src-company" class="p-3 rounded-2xl border-2 text-right transition flex flex-col justify-between border-slate-200 bg-slate-50 text-slate-700 hover:border-slate-300">
                            <div class="flex items-center justify-between w-full mb-1">
                                <i class="fa-solid fa-vault text-slate-500 text-base"></i>
                                <span class="w-3 h-3 rounded-full bg-slate-300 inline-block indicator-dot"></span>
                            </div>
                            <strong class="text-xs font-black block">خزنة الشركة العامة</strong>
                            <span class="text-[10px] text-slate-500 font-medium">مصروف عام / تشغيلي للمقر</span>
                        </button>
                    </div>
                </div>

                <!-- 2. Project Selector (visible when project mode selected) -->
                <div id="quick_project_selector_wrap" class="bg-amber-50/70 border border-amber-200 rounded-2xl p-3.5 space-y-2">
                    <label class="block text-xs font-bold text-amber-950 flex items-center justify-between">
                        <span><i class="fa-solid fa-city ml-1 text-amber-600"></i> حدد المشروع العقاري المستهدف <span class="text-red-500">*</span></span>
                        <span id="quick_project_safe_balance_badge" class="text-[11px] font-mono font-bold text-emerald-800 bg-emerald-100 px-2 py-0.5 rounded-lg border border-emerald-200 dir-ltr">
                            الرصيد: --
                        </span>
                    </label>
                    <select name="project_id" id="quick_project_id" onchange="onQuickProjectChange()" class="w-full bg-white border border-amber-300 rounded-xl px-3 py-2 text-xs font-bold focus:ring-2 focus:ring-amber-500 focus:outline-none">
                        <?php if (empty($quickProjects)): ?>
                            <option value="">لا توجد مشاريع عقارية مسجلة (سيتم الصرف من خزنة الشركة)</option>
                        <?php else: ?>
                            <?php foreach ($quickProjects as $qp): ?>
                                <option value="<?= $qp['id'] ?>" data-balance="<?= (float)$qp['safe_balance'] ?>" data-currency="<?= htmlspecialchars($qp['currency'] ?? $currency) ?>">
                                    <?= htmlspecialchars($qp['name']) ?> (خزنة: <?= formatMoney($qp['safe_balance'], $qp['currency'] ?? $currency) ?>)
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>

                <!-- Company Safe Info (visible when company mode selected) -->
                <div id="quick_company_safe_info" class="hidden bg-slate-50 border border-slate-200 rounded-2xl p-3 flex items-center justify-between text-xs">
                    <span class="font-bold text-slate-700 flex items-center">
                        <i class="fa-solid fa-building ml-1.5 text-indigo-600"></i> الخزنة الرئيسية للشركة:
                    </span>
                    <span class="font-mono font-black text-emerald-700 bg-emerald-50 border border-emerald-200 px-2.5 py-1 rounded-lg dir-ltr">
                        <?= formatMoney($mainSafe['balance'] ?? 0, $currency) ?>
                    </span>
                </div>

                <!-- 3. Amount & Currency -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">المبلغ المطلوب صرفه <span class="text-red-500">*</span></label>
                    <div class="flex gap-2">
                        <div class="relative flex-1">
                            <input type="number" step="0.01" min="0.01" name="amount" id="quick_exp_amount" required placeholder="0.00" class="w-full text-xl font-bold bg-slate-50 border border-slate-300 rounded-xl px-4 py-2.5 text-rose-600 focus:bg-white focus:ring-2 focus:ring-rose-500 focus:outline-none font-mono">
                        </div>
                        <div class="w-36 shrink-0">
                            <select name="currency" class="w-full h-full text-xs font-bold bg-slate-50 border border-slate-300 rounded-xl px-2.5 py-2 text-slate-800 focus:bg-white focus:ring-2 focus:ring-rose-500 focus:outline-none">
                                <option value="ر.س" <?= ($currentUser['currency'] ?? 'ر.س') === 'ر.س' ? 'selected' : '' ?>>ر.س (ريال)</option>
                                <option value="ج.م" <?= ($currentUser['currency'] ?? 'ر.س') === 'ج.م' ? 'selected' : '' ?>>ج.م (جنيه)</option>
                                <option value="$" <?= ($currentUser['currency'] ?? 'ر.س') === '$' ? 'selected' : '' ?>>$ (دولار)</option>
                                <option value="€" <?= ($currentUser['currency'] ?? 'ر.س') === '€' ? 'selected' : '' ?>>€ (يورو)</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- 15% VAT Checkbox -->
                <div class="flex items-center space-x-2 space-x-reverse bg-slate-50 p-2.5 rounded-xl border border-slate-200">
                    <input type="checkbox" id="quick_apply_vat" name="apply_vat" value="1" class="rounded text-rose-600 focus:ring-rose-500 w-4 h-4">
                    <label for="quick_apply_vat" class="text-xs font-bold text-slate-700 cursor-pointer">تطبيق ضريبة القيمة المضافة (15% VAT)</label>
                </div>

                <!-- Category & Title -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">بند المصروف <span class="text-red-500">*</span></label>
                        <select name="category_id" required class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs font-bold focus:bg-white focus:ring-2 focus:ring-rose-500 focus:outline-none">
                            <option value="">-- اختر بند المصروف --</option>
                            <?php foreach ($quickCategories as $qCat): ?>
                                <option value="<?= $qCat['id'] ?>"><?= htmlspecialchars($qCat['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">بيان وسبب المصروف <span class="text-red-500">*</span></label>
                        <input type="text" name="title" required placeholder="مثال: شراء حديد، أسمنت..." class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs font-bold focus:bg-white focus:ring-2 focus:ring-rose-500 focus:outline-none">
                    </div>
                </div>

                <!-- Beneficiary Details -->
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[11px] font-bold text-slate-700 mb-1">اسم المستفيد / المورد (اختياري)</label>
                        <input type="text" name="beneficiary_name" placeholder="اسم الشركة أو الشخص..." class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs focus:bg-white focus:outline-none font-bold">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-slate-700 mb-1">رقم الهوية / السجل (اختياري)</label>
                        <input type="text" name="beneficiary_id_number" placeholder="رقم السجل أو الهوية..." class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs focus:bg-white focus:outline-none font-mono">
                    </div>
                </div>

                <!-- Date & Invoice File -->
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">تاريخ الصرف</label>
                        <input type="date" name="expense_date" value="<?= date('Y-m-d') ?>" required class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs font-bold focus:bg-white focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">إرفاق صورة الإيصال / الفاتورة</label>
                        <input type="file" name="invoice_file" accept=".jpg,.jpeg,.png,.webp,.pdf" class="w-full text-xs text-slate-500 file:mr-2 file:py-1 file:px-2.5 file:rounded-lg file:border-0 file:text-xs file:font-bold file:bg-slate-200">
                    </div>
                </div>

                <!-- 4. Deduction Status Options (Immediate vs Pending Approval vs Pending Receipt) -->
                <?php $canApproveModal = canApproveExpenses(); ?>
                <?php if (!$canApproveModal): ?>
                    <div class="p-3.5 bg-amber-50 border border-amber-300 rounded-2xl space-y-1.5">
                        <div class="flex items-center space-x-1.5 space-x-reverse text-amber-900 font-bold text-xs">
                            <i class="fa-solid fa-hourglass-half text-amber-600"></i>
                            <span>اعتماد وموافقة الإدارة مطلوب</span>
                        </div>
                        <p class="text-[11px] text-amber-800 leading-relaxed">
                            سيتم تقديم هذا المصروف كطلب معلق بانتظار موافقة الإدارة. لن يتم خصمه من الخزنة أو إدراجه بالمشروع حتى يتم اعتماده.
                        </p>
                        <input type="hidden" name="expense_status" value="pending_approval">
                    </div>
                <?php else: ?>
                    <div class="p-3.5 bg-slate-50 border border-slate-200/90 rounded-2xl space-y-2">
                        <span class="block text-xs font-black text-slate-800 flex items-center">
                            <i class="fa-solid fa-shield-halved ml-1.5 text-rose-600"></i> خيار الخصم والاعتماد:
                        </span>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                            <label class="flex items-center p-2.5 rounded-xl border border-emerald-300 bg-emerald-50/70 cursor-pointer text-xs select-none hover:bg-emerald-100/50 transition">
                                <input type="radio" name="expense_status" value="deducted" checked onchange="onDeductionStatusChange('deducted')" class="w-4 h-4 text-emerald-600 focus:ring-emerald-500">
                                <div class="mr-2.5">
                                    <strong class="text-emerald-950 font-bold block">خصم فوري الآن</strong>
                                    <span class="text-[10px] text-emerald-700 block">يخصم من الخزنة فوراً</span>
                                </div>
                            </label>

                            <label class="flex items-center p-2.5 rounded-xl border border-amber-300 bg-amber-50/70 cursor-pointer text-xs select-none hover:bg-amber-100/50 transition">
                                <input type="radio" name="expense_status" value="pending_receipt" onchange="onDeductionStatusChange('pending')" class="w-4 h-4 text-amber-600 focus:ring-amber-500">
                                <div class="mr-2.5">
                                    <strong class="text-amber-950 font-bold block">معلق بانتظار الإيصال</strong>
                                    <span class="text-[10px] text-amber-700 block">لا يخصم حتى رفع الفاتورة</span>
                                </div>
                            </label>
                        </div>
                        <p id="quick_pending_alert" class="hidden text-[11px] text-amber-800 bg-amber-100/80 p-2 rounded-lg border border-amber-300 font-medium">
                            <i class="fa-solid fa-clock ml-1 text-amber-600"></i> لن يتم خصم أي رصيد من الخزنة الآن، وسيظهر المصروف بحالة "معلق" في المشروع حتى ترفق صورة الإيصال لاحقاً لتأكيد الخصم.
                        </p>
                    </div>
                <?php endif; ?>

                <!-- Submit / Cancel -->
                <div class="flex items-center space-x-2 space-x-reverse pt-2">
                    <button type="submit" id="btn_quick_expense_submit" class="flex-1 <?= $canApproveModal ? 'bg-rose-600 hover:bg-rose-700' : 'bg-amber-600 hover:bg-amber-700' ?> text-white font-bold py-2.5 px-4 rounded-xl shadow-md transition text-xs sm:text-sm flex items-center justify-center">
                        <i class="fa-solid <?= $canApproveModal ? 'fa-check' : 'fa-paper-plane' ?> ml-1.5"></i>
                        <span><?= $canApproveModal ? 'صرف المبلغ وخصمه من الخزنة فوراً' : 'تقديم طلب المصروف للاعتماد' ?></span>
                    </button>
                    <button type="button" onclick="closeModal('quick-expense-modal')" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold py-2.5 px-4 rounded-xl transition text-xs">
                        إلغاء
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- MODAL: CONFIRM PENDING RECEIPT & DEDUCT FROM SAFE -->
    <div id="confirm-receipt-modal" class="modal-container fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/60 backdrop-blur-xs p-4 overflow-y-auto">
        <div class="modal-backdrop fixed inset-0" onclick="closeModal('confirm-receipt-modal')"></div>
        <div class="relative bg-white rounded-3xl shadow-2xl max-w-md w-full p-6 modal-animate-enter z-10 border border-slate-100">
            <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-4">
                <div class="flex items-center space-x-2 space-x-reverse">
                    <div class="w-9 h-9 rounded-xl bg-amber-100 text-amber-600 flex items-center justify-center font-bold text-lg">
                        <i class="fa-solid fa-receipt"></i>
                    </div>
                    <div>
                        <h3 class="font-black text-slate-900 text-base">إرفاق صورة الإيصال وتأكيد الخصم</h3>
                        <p class="text-[11px] text-slate-500">رفع الفاتورة لخصم المصروف المعلق من الخزنة</p>
                    </div>
                </div>
                <button onclick="closeModal('confirm-receipt-modal')" class="text-slate-400 hover:text-slate-600 text-lg">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <form action="expenses.php" method="POST" enctype="multipart/form-data" class="space-y-4">
                <?= getCSRFInput() ?>
                <input type="hidden" name="action" value="confirm_pending_expense">
                <input type="hidden" name="expense_id" id="conf_expense_id" value="">
                <input type="hidden" name="redirect_url" id="conf_redirect_url" value="">

                <!-- Details Info Card -->
                <div class="bg-slate-50 border border-slate-200 rounded-2xl p-3.5 space-y-1.5 text-xs">
                    <div class="flex justify-between">
                        <span class="text-slate-500 font-bold">بند المصروف:</span>
                        <strong class="text-slate-800" id="conf_expense_title">--</strong>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-slate-500 font-bold">المستفيد:</span>
                        <strong class="text-slate-800" id="conf_expense_beneficiary">--</strong>
                    </div>
                    <div class="flex justify-between pt-1 border-t border-slate-200">
                        <span class="text-slate-700 font-bold">المبلغ المطلوب خصمه:</span>
                        <strong class="text-rose-600 font-black text-sm" id="conf_expense_amount">--</strong>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">
                        صورة الفاتورة أو إيصال الشراء <span class="text-red-500">*</span>
                    </label>
                    <input type="file" name="invoice_file" accept=".jpg,.jpeg,.png,.webp,.pdf" required class="w-full text-xs text-slate-600 file:mr-0 file:ml-2 file:py-2 file:px-3 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-amber-100 file:text-amber-800 hover:file:bg-amber-200 border border-slate-300 rounded-xl p-2 bg-slate-50">
                    <span class="text-[10px] text-slate-400 mt-1 block">يرجى رفع ملف صورة أو PDF لإتمام الخصم من الخزنة.</span>
                </div>

                <div class="flex items-center space-x-2 space-x-reverse pt-2">
                    <button type="submit" class="flex-1 bg-amber-600 hover:bg-amber-700 text-white font-bold py-2.5 px-4 rounded-xl shadow-md transition text-xs sm:text-sm flex items-center justify-center">
                        <i class="fa-solid fa-cloud-arrow-up ml-1.5"></i>
                        تأكيد الخصم من الخزنة وحفظ الإيصال
                    </button>
                    <button type="button" onclick="closeModal('confirm-receipt-modal')" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold py-2.5 px-4 rounded-xl transition text-xs">
                        إلغاء
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL: APPROVE EXPENSE & DEDUCT FROM SAFE -->
    <div id="approve-expense-modal" class="modal-container fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/60 backdrop-blur-xs p-4 overflow-y-auto">
        <div class="modal-backdrop fixed inset-0" onclick="closeModal('approve-expense-modal')"></div>
        <div class="relative bg-white rounded-3xl shadow-2xl max-w-md w-full p-6 modal-animate-enter z-10 border border-slate-100">
            <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-4">
                <div class="flex items-center space-x-2 space-x-reverse">
                    <div class="w-10 h-10 rounded-2xl bg-emerald-100 text-emerald-600 flex items-center justify-center font-bold text-lg">
                        <i class="fa-solid fa-check-double"></i>
                    </div>
                    <div>
                        <h3 class="font-black text-slate-900 text-base">اعتماد وصرف المصروف</h3>
                        <p class="text-[11px] text-slate-500">الموافقة الإدارية وخصم المبلغ من الخزنة</p>
                    </div>
                </div>
                <button onclick="closeModal('approve-expense-modal')" class="text-slate-400 hover:text-slate-600 text-lg">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <form action="expenses.php" method="POST" class="space-y-4">
                <?= getCSRFInput() ?>
                <input type="hidden" name="action" value="approve_expense">
                <input type="hidden" name="expense_id" id="approve_expense_id" value="">
                <input type="hidden" name="redirect_url" id="approve_redirect_url" value="">

                <div class="bg-emerald-50/60 border border-emerald-200 rounded-2xl p-4 space-y-2 text-xs">
                    <div class="flex justify-between items-center">
                        <span class="text-slate-500 font-bold">بند / سبب المصروف:</span>
                        <strong class="text-slate-900 font-black text-sm" id="approve_expense_title">--</strong>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-slate-500 font-bold">المستفيد / المورد:</span>
                        <strong class="text-slate-800" id="approve_expense_beneficiary">--</strong>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-slate-500 font-bold">المشروع / الخزنة:</span>
                        <strong class="text-slate-800" id="approve_expense_project">--</strong>
                    </div>
                    <div class="flex justify-between items-center pt-2 border-t border-emerald-200">
                        <span class="text-slate-800 font-black">المبلغ المطلوب اعتماده وخصمه:</span>
                        <strong class="text-rose-600 font-black text-base font-mono" id="approve_expense_amount">--</strong>
                    </div>
                </div>

                <div class="p-3 bg-slate-50 rounded-xl border border-slate-200 text-[11px] text-slate-600 space-y-1">
                    <div class="flex items-center space-x-1.5 space-x-reverse text-slate-800 font-bold mb-1">
                        <i class="fa-solid fa-circle-info text-emerald-600"></i>
                        <span>نتائج الاعتماد الإداري:</span>
                    </div>
                    <p>• سيتم خصم المبلغ فوراً من الخزنة المحددة وتحديث رصيدها.</p>
                    <p>• سيتم إدراج المصروف رسمياً في حسابات وتكاليف المشروع العقاري.</p>
                    <p>• توثيق اعتمادك وتاريخ الموافقة في سند الصرف وسجلات التدقيق.</p>
                </div>

                <div class="flex items-center space-x-2 space-x-reverse pt-2">
                    <button type="submit" class="flex-1 bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-2.5 px-4 rounded-xl shadow-md transition text-xs sm:text-sm flex items-center justify-center space-x-1.5 space-x-reverse">
                        <i class="fa-solid fa-check ml-1.5"></i>
                        <span>موافقة واعتماد الصرف الآن</span>
                    </button>
                    <button type="button" onclick="closeModal('approve-expense-modal')" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold py-2.5 px-4 rounded-xl transition text-xs">
                        إلغاء
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL: REJECT EXPENSE -->
    <div id="reject-expense-modal" class="modal-container fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/60 backdrop-blur-xs p-4 overflow-y-auto">
        <div class="modal-backdrop fixed inset-0" onclick="closeModal('reject-expense-modal')"></div>
        <div class="relative bg-white rounded-3xl shadow-2xl max-w-md w-full p-6 modal-animate-enter z-10 border border-slate-100">
            <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-4">
                <div class="flex items-center space-x-2 space-x-reverse">
                    <div class="w-10 h-10 rounded-2xl bg-rose-100 text-rose-600 flex items-center justify-center font-bold text-lg">
                        <i class="fa-solid fa-ban"></i>
                    </div>
                    <div>
                        <h3 class="font-black text-slate-900 text-base">رفض طلب المصروف</h3>
                        <p class="text-[11px] text-slate-500">عدم الموافقة على طلب الصرف المالي</p>
                    </div>
                </div>
                <button onclick="closeModal('reject-expense-modal')" class="text-slate-400 hover:text-slate-600 text-lg">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <form action="expenses.php" method="POST" class="space-y-4">
                <?= getCSRFInput() ?>
                <input type="hidden" name="action" value="reject_expense">
                <input type="hidden" name="expense_id" id="reject_expense_id" value="">
                <input type="hidden" name="redirect_url" id="reject_redirect_url" value="">

                <div class="bg-rose-50/60 border border-rose-200 rounded-2xl p-4 space-y-1.5 text-xs">
                    <div class="flex justify-between items-center">
                        <span class="text-slate-500 font-bold">بند / سبب المصروف:</span>
                        <strong class="text-slate-900 font-black" id="reject_expense_title">--</strong>
                    </div>
                    <div class="flex justify-between items-center pt-1 border-t border-rose-200">
                        <span class="text-slate-700 font-bold">المبلغ:</span>
                        <strong class="text-rose-600 font-black text-sm font-mono" id="reject_expense_amount">--</strong>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1.5">
                        سبب الرفض <span class="text-rose-500">*</span>
                    </label>
                    <textarea name="rejection_reason" required rows="3" placeholder="اكتب سبب رفض المصروف (مثال: المبلغ غير مبرر، الفاتورة غير صحيحة، غير مدرج بالمقايسة...)" class="w-full bg-slate-50 border border-slate-300 rounded-xl p-3 text-xs focus:bg-white focus:ring-2 focus:ring-rose-500 focus:outline-none leading-relaxed"></textarea>
                    <span class="text-[10px] text-slate-400 mt-1 block">سيتم تسجيل سبب الرفض ولن يتم خصم أي مبالغ من الخزنة أو إضافتها للمشروع.</span>
                </div>

                <div class="flex items-center space-x-2 space-x-reverse pt-2">
                    <button type="submit" class="flex-1 bg-rose-600 hover:bg-rose-700 text-white font-bold py-2.5 px-4 rounded-xl shadow-md transition text-xs sm:text-sm flex items-center justify-center space-x-1.5 space-x-reverse">
                        <i class="fa-solid fa-ban ml-1.5"></i>
                        <span>تأكيد رفض المصروف</span>
                    </button>
                    <button type="button" onclick="closeModal('reject-expense-modal')" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold py-2.5 px-4 rounded-xl transition text-xs">
                        إلغاء
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="assets/js/app.js"></script>

    <script>
    function setQuickExpenseSource(type) {
        document.getElementById('quick_expense_source_type').value = type;
        var btnProj = document.getElementById('btn-src-project');
        var btnComp = document.getElementById('btn-src-company');
        var wrapProj = document.getElementById('quick_project_selector_wrap');
        var wrapComp = document.getElementById('quick_company_safe_info');

        if (type === 'project') {
            btnProj.className = 'p-3 rounded-2xl border-2 text-right transition flex flex-col justify-between border-rose-600 bg-rose-50/70 text-rose-900 shadow-xs';
            btnProj.querySelector('.indicator-dot').className = 'w-3 h-3 rounded-full bg-rose-600 inline-block indicator-dot';

            btnComp.className = 'p-3 rounded-2xl border-2 text-right transition flex flex-col justify-between border-slate-200 bg-slate-50 text-slate-700 hover:border-slate-300';
            btnComp.querySelector('.indicator-dot').className = 'w-3 h-3 rounded-full bg-slate-300 inline-block indicator-dot';

            wrapProj.classList.remove('hidden');
            wrapComp.classList.add('hidden');
            onQuickProjectChange();
        } else {
            btnComp.className = 'p-3 rounded-2xl border-2 text-right transition flex flex-col justify-between border-brand-600 bg-brand-50/70 text-brand-900 shadow-xs';
            btnComp.querySelector('.indicator-dot').className = 'w-3 h-3 rounded-full bg-brand-600 inline-block indicator-dot';

            btnProj.className = 'p-3 rounded-2xl border-2 text-right transition flex flex-col justify-between border-slate-200 bg-slate-50 text-slate-700 hover:border-slate-300';
            btnProj.querySelector('.indicator-dot').className = 'w-3 h-3 rounded-full bg-slate-300 inline-block indicator-dot';

            wrapProj.classList.add('hidden');
            wrapComp.classList.remove('hidden');
        }
    }

    function onQuickProjectChange() {
        var sel = document.getElementById('quick_project_id');
        if (!sel || !sel.options[sel.selectedIndex]) return;
        var opt = sel.options[sel.selectedIndex];
        var bal = parseFloat(opt.getAttribute('data-balance')) || 0;
        var curr = opt.getAttribute('data-currency') || 'ر.س';
        var badge = document.getElementById('quick_project_safe_balance_badge');
        if (badge) {
            badge.innerText = 'رصيد الخزنة: ' + bal.toLocaleString('en-US', {minimumFractionDigits: 2}) + ' ' + curr;
        }
    }

    function onDeductionStatusChange(status) {
        var alertBox = document.getElementById('quick_pending_alert');
        var submitBtn = document.getElementById('btn_quick_expense_submit');
        if (status === 'pending') {
            alertBox.classList.remove('hidden');
            submitBtn.className = 'flex-1 bg-amber-600 hover:bg-amber-700 text-white font-bold py-2.5 px-4 rounded-xl shadow-md transition text-xs sm:text-sm flex items-center justify-center';
            submitBtn.querySelector('span').innerText = 'حفظ المصروف كمعلق (بانتظار الإيصال)';
        } else {
            alertBox.classList.add('hidden');
            submitBtn.className = 'flex-1 bg-rose-600 hover:bg-rose-700 text-white font-bold py-2.5 px-4 rounded-xl shadow-md transition text-xs sm:text-sm flex items-center justify-center';
            submitBtn.querySelector('span').innerText = 'صرف المبلغ وخصمه من الخزنة فوراً';
        }
    }

    function openConfirmReceiptModal(expenseId, title, amountStr, beneficiary) {
        document.getElementById('conf_expense_id').value = expenseId;
        document.getElementById('conf_redirect_url').value = window.location.href;
        document.getElementById('conf_expense_title').innerText = title || '-';
        document.getElementById('conf_expense_beneficiary').innerText = beneficiary || 'غير محدد';
        document.getElementById('conf_expense_amount').innerText = amountStr || '0.00';
        openModal('confirm-receipt-modal');
    }

    function openApproveExpenseModal(expenseId, title, amountStr, beneficiary, projectName) {
        document.getElementById('approve_expense_id').value = expenseId;
        document.getElementById('approve_redirect_url').value = window.location.href;
        document.getElementById('approve_expense_title').innerText = title || '-';
        document.getElementById('approve_expense_amount').innerText = amountStr || '0.00';
        document.getElementById('approve_expense_beneficiary').innerText = beneficiary || 'غير محدد';
        document.getElementById('approve_expense_project').innerText = projectName || 'الخزنة العامة للشركة';
        openModal('approve-expense-modal');
    }

    function openRejectExpenseModal(expenseId, title, amountStr) {
        document.getElementById('reject_expense_id').value = expenseId;
        document.getElementById('reject_redirect_url').value = window.location.href;
        document.getElementById('reject_expense_title').innerText = title || '-';
        document.getElementById('reject_expense_amount').innerText = amountStr || '0.00';
        openModal('reject-expense-modal');
    }

    document.addEventListener('DOMContentLoaded', function() {
        onQuickProjectChange();
    });
    </script>
</body>
</html>
