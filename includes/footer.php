<?php
$quickCategories = [];
if (!empty($activeCompanyId)) {
    $db = getDBConnection();
    $catStmt = $db->prepare("SELECT * FROM expense_categories WHERE company_id = :cid AND is_active = 1 ORDER BY name ASC");
    $catStmt->execute([':cid' => $activeCompanyId]);
    $quickCategories = $catStmt->fetchAll();
}
?>
            </main>
        </div>
    </div>

    <!-- QUICK DEPOSIT MODAL -->
    <?php if (!empty($activeCompanyId) && hasModule('treasury')): ?>
    <div id="quick-deposit-modal" class="modal-container fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/60 backdrop-blur-xs p-4 overflow-y-auto">
        <div class="modal-backdrop fixed inset-0"></div>
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
                    <label class="block text-xs font-bold text-slate-700 mb-1">المبلغ المودع (<?= htmlspecialchars($currentUser['currency'] ?? 'ج.م') ?>) <span class="text-red-500">*</span></label>
                    <div class="relative">
                        <input type="number" step="0.01" min="0.01" name="amount" required placeholder="0.00" class="w-full text-xl font-bold bg-slate-50 border border-slate-300 rounded-xl px-4 py-2.5 text-emerald-600 focus:bg-white focus:ring-2 focus:ring-emerald-500 focus:outline-none">
                        <span class="absolute left-3 top-3 text-xs text-slate-400 font-bold"><?= htmlspecialchars($currentUser['currency'] ?? 'ج.م') ?></span>
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

    <!-- QUICK EXPENSE MODAL -->
    <?php if (!empty($activeCompanyId) && hasModule('expenses')): ?>
    <div id="quick-expense-modal" class="modal-container fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/60 backdrop-blur-xs p-4 overflow-y-auto">
        <div class="modal-backdrop fixed inset-0"></div>
        <div class="relative bg-white rounded-2xl shadow-2xl max-w-md w-full p-6 modal-animate-enter z-10 border border-slate-100">
            <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-4">
                <div class="flex items-center space-x-2 space-x-reverse">
                    <div class="w-8 h-8 rounded-lg bg-rose-100 text-rose-600 flex items-center justify-center font-bold">
                        <i class="fa-solid fa-receipt"></i>
                    </div>
                    <h3 class="font-bold text-slate-800 text-lg">تسجيل مصروف جديد</h3>
                </div>
                <button onclick="closeModal('quick-expense-modal')" class="text-slate-400 hover:text-slate-600 text-lg">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <form action="expenses.php" method="POST" class="space-y-4">
                <?= getCSRFInput() ?>
                <input type="hidden" name="action" value="create_expense">

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">المبلغ المطلوب سحبه (<?= htmlspecialchars($currentUser['currency'] ?? 'ج.م') ?>) <span class="text-red-500">*</span></label>
                    <div class="relative">
                        <input type="number" step="0.01" min="0.01" name="amount" required placeholder="0.00" class="w-full text-xl font-bold bg-slate-50 border border-slate-300 rounded-xl px-4 py-2.5 text-rose-600 focus:bg-white focus:ring-2 focus:ring-rose-500 focus:outline-none">
                        <span class="absolute left-3 top-3 text-xs text-slate-400 font-bold"><?= htmlspecialchars($currentUser['currency'] ?? 'ج.م') ?></span>
                    </div>
                    <?php if (!empty($mainSafe)): ?>
                        <p class="text-[11px] text-slate-500 mt-1">الرصيد المتاح بالخزنة: <span class="font-bold text-slate-700"><?= formatMoney($mainSafe['balance'], $currentUser['currency'] ?? 'ج.م') ?></span></p>
                    <?php endif; ?>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">بند المصروف <span class="text-red-500">*</span></label>
                    <select name="category_id" required class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-sm focus:bg-white focus:ring-2 focus:ring-rose-500 focus:outline-none font-medium">
                        <option value="">-- اختر بند المصروف --</option>
                        <?php foreach ($quickCategories as $qCat): ?>
                            <option value="<?= $qCat['id'] ?>"><?= htmlspecialchars($qCat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">عنوان / سبب المصروف <span class="text-red-500">*</span></label>
                    <input type="text" name="title" required placeholder="مثال: شراء شاي وسكر، فاتورة كهرباء شهر 10..." class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-sm focus:bg-white focus:ring-2 focus:ring-rose-500 focus:outline-none">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">تاريخ الصرف</label>
                    <input type="date" name="expense_date" value="<?= date('Y-m-d') ?>" required class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-sm focus:bg-white focus:ring-2 focus:ring-rose-500 focus:outline-none">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">تفاصيل إضافية / المورد / رقم الفاتورة</label>
                    <textarea name="description" rows="2" placeholder="اختياري..." class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-sm focus:bg-white focus:ring-2 focus:ring-rose-500 focus:outline-none"></textarea>
                </div>

                <div class="flex items-center space-x-2 space-x-reverse pt-2">
                    <button type="submit" class="flex-1 bg-rose-600 hover:bg-rose-700 text-white font-bold py-2.5 px-4 rounded-xl shadow-md transition text-sm">
                        صرف المبلغ وخصمه من الخزنة
                    </button>
                    <button type="button" onclick="closeModal('quick-expense-modal')" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold py-2.5 px-4 rounded-xl transition text-sm">
                        إلغاء
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="assets/js/app.js"></script>
</body>
</html>
