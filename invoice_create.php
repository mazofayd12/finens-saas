<?php
require_once __DIR__ . '/config/app.php';
requireLogin();
requirePermission('invoices');
requireNotViewOnly();

$currentUser = getCurrentUser();
$activeCompanyId = (int)($currentUser['active_company_id'] ?? 0);
if ($activeCompanyId <= 0 && isSuperAdmin()) {
    $firstComp = $db->query("SELECT id FROM companies WHERE is_active = 1 LIMIT 1")->fetch();
    if ($firstComp) {
        $activeCompanyId = (int)$firstComp['id'];
    }
}
$currency = $currentUser['currency'] ?? 'ج.م';
$db = getDBConnection();

$pageTitle = 'إصدار فاتورة ضريبية مبسطة جديدة';

// Fetch company details for default VAT rate and national address check
$compStmt = $db->prepare("SELECT * FROM companies WHERE id = :id LIMIT 1");
$compStmt->execute([':id' => $activeCompanyId]);
$company = $compStmt->fetch();

$defaultTaxRate = ($currency === 'ر.س' || $currency === 'SAR') ? 15.00 : 14.00;

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        die('خطأ في التحقق من صحة الطلب (CSRF)');
    }

    $customerName = trim($_POST['customer_name'] ?? '');
    $customerPhone = trim($_POST['customer_phone'] ?? '');
    $customerTaxNo = trim($_POST['customer_tax_number'] ?? '');
    $invoiceDate = !empty($_POST['invoice_date']) ? trim($_POST['invoice_date']) : date('Y-m-d');
    $paymentMethod = trim($_POST['payment_method'] ?? 'نقداً');
    $discountDays = (int)($_POST['discount_days'] ?? 0);
    $warehouse = trim($_POST['warehouse'] ?? 'المستودع الرئيسي');
    $sellerName = trim($_POST['seller_name'] ?? $currentUser['name']);
    $invCurrency = trim($_POST['currency'] ?? $currency);
    $notes = trim($_POST['notes'] ?? 'البضاعة المباعة لا ترد ولا تستبدل إلا وفق الشروط المعتمدة.');
    $defaultRowVatRate = (float)($_POST['tax_rate'] ?? $defaultTaxRate);

    // Items array from POST
    $items = $_POST['items'] ?? [];

    if (empty($customerName)) {
        setFlash('error', 'يرجى إدخال اسم العميل.');
    } elseif (empty($items) || !is_array($items)) {
        setFlash('error', 'يرجى إضافة بند واحد على الأقل للفاتورة.');
    } else {
        // Calculate totals across rows
        $calculatedItems = [];
        $subtotal = 0.0;
        $totalVat = 0.0;
        $totalDiscount = 0.0;
        $totalAmount = 0.0;

        foreach ($items as $item) {
            $name = trim($item['name'] ?? '');
            if (empty($name)) continue;

            $code = trim($item['code'] ?? '');
            $qty = max(0.001, (float)($item['quantity'] ?? 1));
            $unit = trim($item['unit'] ?? 'طن');
            $price = max(0, (float)($item['price'] ?? 0));
            $discount = max(0, (float)($item['discount'] ?? 0));
            $vatRate = isset($item['tax_rate']) ? (float)$item['tax_rate'] : $defaultRowVatRate;

            $lineBase = $qty * $price;
            $lineAfterDiscount = max(0, $lineBase - $discount);
            $lineVat = round($lineAfterDiscount * ($vatRate / 100), 2);
            $lineTotal = round($lineAfterDiscount + $lineVat, 2);

            $subtotal += $lineAfterDiscount;
            $totalVat += $lineVat;
            $totalDiscount += $discount;
            $totalAmount += $lineTotal;

            $calculatedItems[] = [
                'code' => $code,
                'name' => $name,
                'qty' => $qty,
                'unit' => $unit,
                'price' => $price,
                'discount' => $discount,
                'tax_rate' => $vatRate,
                'tax_amount' => $lineVat,
                'total_amount' => $lineTotal
            ];
        }

        if (empty($calculatedItems)) {
            setFlash('error', 'يرجى كتابة اسم صنف صحيح في بنود الفاتورة.');
        } else {
            try {
                $db->beginTransaction();

                $invoiceNumber = generateInvoiceNumber($activeCompanyId);
                $sellerTaxNo = $company['tax_number'] ?? '';
                $dateTimeIso = $invoiceDate . 'T' . date('H:i:s');
                
                // Generate ZATCA TLV Base64 QR Code string
                $qrData = generateZatcaTlvBase64(
                    $company['name'],
                    $sellerTaxNo,
                    $dateTimeIso,
                    $totalAmount,
                    $totalVat
                );

                // Insert into invoices
                $insInv = $db->prepare("
                    INSERT INTO invoices (
                        company_id, user_id, invoice_number, invoice_date,
                        customer_name, customer_phone, customer_tax_number,
                        payment_method, discount_days,
                        subtotal, tax_rate, tax_amount, discount_amount, total_amount,
                        currency, warehouse, seller_name, notes, qr_data, status, created_at
                    ) VALUES (
                        :cid, :uid, :inv_no, :inv_date,
                        :cname, :cphone, :ctax,
                        :pmethod, :ddays,
                        :subtotal, :trate, :tamount, :damount, :total,
                        :curr, :wh, :seller, :notes, :qr, 'issued', :now
                    )
                ");

                $insInv->execute([
                    ':cid' => $activeCompanyId,
                    ':uid' => $currentUser['id'],
                    ':inv_no' => $invoiceNumber,
                    ':inv_date' => $invoiceDate,
                    ':cname' => $customerName,
                    ':cphone' => $customerPhone ?: null,
                    ':ctax' => $customerTaxNo ?: null,
                    ':pmethod' => $paymentMethod,
                    ':ddays' => $discountDays,
                    ':subtotal' => $subtotal,
                    ':trate' => $defaultRowVatRate,
                    ':tamount' => $totalVat,
                    ':damount' => $totalDiscount,
                    ':total' => $totalAmount,
                    ':curr' => $invCurrency,
                    ':wh' => $warehouse,
                    ':seller' => $sellerName,
                    ':notes' => $notes,
                    ':qr' => $qrData,
                    ':now' => date('Y-m-d H:i:s')
                ]);

                $newInvoiceId = (int)$db->lastInsertId();

                // Insert invoice items
                $insItem = $db->prepare("
                    INSERT INTO invoice_items (
                        invoice_id, item_code, item_name, quantity, unit,
                        unit_price, discount_amount, tax_rate, tax_amount, total_amount
                    ) VALUES (
                        :inv_id, :code, :name, :qty, :unit,
                        :price, :disc, :trate, :tamt, :tot
                    )
                ");

                foreach ($calculatedItems as $ci) {
                    $insItem->execute([
                        ':inv_id' => $newInvoiceId,
                        ':code' => $ci['code'],
                        ':name' => $ci['name'],
                        ':qty' => $ci['qty'],
                        ':unit' => $ci['unit'],
                        ':price' => $ci['price'],
                        ':disc' => $ci['discount'],
                        ':trate' => $ci['tax_rate'],
                        ':tamt' => $ci['tax_amount'],
                        ':tot' => $ci['total_amount']
                    ]);
                }

                $db->commit();
                setFlash('success', "تم إصدار الفاتورة الضريبية المبسطة رقم {$invoiceNumber} بنجاح!");
                header("Location: invoice_print.php?id={$newInvoiceId}&autoprint=1");
                exit;

            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                setFlash('error', 'حدث خطأ أثناء حفظ الفاتورة: ' . $e->getMessage());
            }
        }
    }
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<div class="max-w-7xl mx-auto space-y-6 pb-24 lg:pb-12">

    <!-- Top Action Breadcrumb -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 bg-white rounded-2xl p-4 sm:p-5 border border-slate-200/80 shadow-xs">
        <div class="flex items-center space-x-3 space-x-reverse">
            <a href="invoices.php" class="w-10 h-10 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 flex items-center justify-center transition active:scale-95">
                <i class="fa-solid fa-arrow-right text-sm"></i>
            </a>
            <div>
                <h1 class="text-lg sm:text-xl font-black text-slate-900">إصدار فاتورة ضريبية مبسطة</h1>
                <div class="flex items-center space-x-2 space-x-reverse text-xs text-slate-500 mt-0.5">
                    <span>الشركة: <strong class="text-slate-800"><?= htmlspecialchars($company['name'] ?? '') ?></strong></span>
                    <span>•</span>
                    <span>البائع: <strong class="text-indigo-600"><?= htmlspecialchars($currentUser['name']) ?></strong></span>
                </div>
            </div>
        </div>

        <div class="flex items-center space-x-2 space-x-reverse">
            <a href="invoices.php" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold px-4 py-2 rounded-xl text-xs transition">
                العودة لقائمة الفواتير
            </a>
        </div>
    </div>

    <!-- Main Creation Form -->
    <form method="POST" action="invoice_create.php" id="invoiceForm" class="space-y-6">
        <?= getCSRFInput() ?>

        <!-- 1. Header Information & Customer Data -->
        <div class="bg-white rounded-2xl p-5 sm:p-6 border border-slate-200/80 shadow-xs space-y-4">
            <h2 class="text-sm font-black text-slate-900 flex items-center border-b border-slate-100 pb-3">
                <span class="w-7 h-7 rounded-lg bg-rose-50 text-rose-600 flex items-center justify-center text-xs ml-2">
                    <i class="fa-solid fa-user-tag"></i>
                </span>
                بيانات الفاتورة والعميل
            </h2>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">اسم العميل <span class="text-red-500">*</span></label>
                    <input type="text" name="customer_name" required placeholder="مثال: شركة البركة للتجارة / محمد أحمد" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3.5 py-2 text-xs font-bold focus:bg-white focus:ring-2 focus:ring-rose-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">هاتف العميل</label>
                    <input type="text" name="customer_phone" placeholder="05xxxxxxxx" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3.5 py-2 text-xs font-mono focus:bg-white focus:ring-2 focus:ring-rose-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">الرقم الضريبي للعميل (اختياري)</label>
                    <input type="text" name="customer_tax_number" placeholder="300xxxxxxxxx" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3.5 py-2 text-xs font-mono focus:bg-white focus:ring-2 focus:ring-rose-500 focus:outline-none">
                </div>
            </div>

            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 pt-2">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">تاريخ الفاتورة</label>
                    <input type="date" name="invoice_date" value="<?= date('Y-m-d') ?>" required class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs font-mono font-bold focus:bg-white focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">طريقة الدفع</label>
                    <select name="payment_method" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs font-bold focus:bg-white focus:outline-none">
                        <option value="نقداً">نقداً</option>
                        <option value="شبكة / مدى">شبكة / مدى</option>
                        <option value="تحويل بنكي">تحويل بنكي</option>
                        <option value="شيك">شيك مصرفي</option>
                        <option value="آجل">آجل</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">الأيام المخصومة (شروط الخصم)</label>
                    <input type="number" name="discount_days" value="0" min="0" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs font-mono focus:bg-white focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">العملة ونسبة الضريبة</label>
                    <div class="grid grid-cols-2 gap-1.5">
                        <select name="currency" id="inv_curr" onchange="recalculateAll()" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-2 py-2 text-xs font-bold focus:bg-white focus:outline-none">
                            <option value="ر.س" <?= $currency === 'ر.س' ? 'selected' : '' ?>>ر.س (SAR)</option>
                            <option value="ج.م" <?= $currency === 'ج.م' ? 'selected' : '' ?>>ج.م (EGP)</option>
                            <option value="USD" <?= $currency === 'USD' ? 'selected' : '' ?>>USD ($)</option>
                        </select>
                        <input type="number" step="0.1" name="tax_rate" id="global_tax_rate" value="<?= $defaultTaxRate ?>" onchange="recalculateAll()" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-2 py-2 text-xs font-mono font-bold text-center focus:bg-white focus:outline-none" title="نسبة ضريبة القيمة المضافة %">
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-2">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">المخزن</label>
                    <input type="text" name="warehouse" value="المستودع الرئيسي" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs focus:bg-white focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">البائع</label>
                    <input type="text" name="seller_name" value="<?= htmlspecialchars($currentUser['name']) ?>" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-2 text-xs focus:bg-white focus:outline-none">
                </div>
            </div>
        </div>

        <!-- 2. Dynamic Items Repeater -->
        <div class="bg-white rounded-2xl p-5 sm:p-6 border border-slate-200/80 shadow-xs space-y-4">
            <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                <h2 class="text-sm font-black text-slate-900 flex items-center">
                    <span class="w-7 h-7 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center text-xs ml-2">
                        <i class="fa-solid fa-boxes-stacked"></i>
                    </span>
                    بنود الأصناف والخدمات المباعة
                </h2>
                <button type="button" onclick="addItemRow()" class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold px-3 py-1.5 rounded-xl text-xs transition shadow-xs flex items-center">
                    <i class="fa-solid fa-plus ml-1.5"></i> إضافة صنف جديد
                </button>
            </div>

            <!-- Items Table -->
            <div class="overflow-x-auto">
                <table class="w-full text-right text-xs" id="itemsTable">
                    <thead class="bg-slate-50 text-slate-700 font-extrabold border-b border-slate-200">
                        <tr>
                            <th class="p-2 w-10 text-center">م</th>
                            <th class="p-2 w-28">رمز الصنف</th>
                            <th class="p-2">اسم الصنف والوصف <span class="text-red-500">*</span></th>
                            <th class="p-2 w-20 text-center">الكمية</th>
                            <th class="p-2 w-24">الوحدة</th>
                            <th class="p-2 w-24 text-center">السعر</th>
                            <th class="p-2 w-20 text-center">الخصم</th>
                            <th class="p-2 w-24 text-center">الضريبة</th>
                            <th class="p-2 w-28 text-center">الإجمالي</th>
                            <th class="p-2 w-10 text-center">حذف</th>
                        </tr>
                    </thead>
                    <tbody id="itemsBody" class="divide-y divide-slate-100">
                        <!-- Initial Row 1 -->
                        <tr class="item-row" data-row="0">
                            <td class="p-2 text-center font-mono font-bold row-index">1</td>
                            <td class="p-2">
                                <input type="text" name="items[0][code]" placeholder="رمز الصنف" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-2 py-1.5 text-xs font-mono focus:bg-white focus:outline-none">
                            </td>
                            <td class="p-2">
                                <input type="text" name="items[0][name]" required placeholder="اسم الصنف أو الخدمة" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-2 py-1.5 text-xs font-bold focus:bg-white focus:outline-none">
                            </td>
                            <td class="p-2">
                                <input type="number" step="0.01" min="0.01" name="items[0][quantity]" value="1" oninput="calcRow(this)" required class="w-full bg-slate-50 border border-slate-200 rounded-lg px-2 py-1.5 text-xs font-mono font-bold text-center focus:bg-white focus:outline-none item-qty">
                            </td>
                            <td class="p-2">
                                <select name="items[0][unit]" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-1.5 py-1.5 text-xs focus:bg-white focus:outline-none">
                                    <option value="طن">طن</option>
                                    <option value="متر">متر</option>
                                    <option value="متر مربع">م²</option>
                                    <option value="كجم">كجم</option>
                                    <option value="قطعة">قطعة</option>
                                    <option value="كرتون">كرتون</option>
                                    <option value="عدد">عدد</option>
                                    <option value="خدمة">خدمة</option>
                                </select>
                            </td>
                            <td class="p-2">
                                <input type="number" step="0.01" min="0" name="items[0][price]" value="0" oninput="calcRow(this)" required class="w-full bg-slate-50 border border-slate-200 rounded-lg px-2 py-1.5 text-xs font-mono font-bold text-center focus:bg-white focus:outline-none item-price">
                            </td>
                            <td class="p-2">
                                <input type="number" step="0.01" min="0" name="items[0][discount]" value="0" oninput="calcRow(this)" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-2 py-1.5 text-xs font-mono text-center focus:bg-white focus:outline-none item-discount">
                            </td>
                            <td class="p-2 text-center font-mono font-bold text-amber-700 item-vat">0.00</td>
                            <td class="p-2 text-center font-mono font-black text-slate-900 item-total">0.00</td>
                            <td class="p-2 text-center">
                                <button type="button" onclick="removeRow(this)" class="text-slate-400 hover:text-red-500 transition p-1">
                                    <i class="fa-solid fa-trash-can"></i>
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="pt-2 flex justify-start">
                <button type="button" onclick="addItemRow()" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold px-3 py-1.5 rounded-xl text-xs transition flex items-center">
                    <i class="fa-solid fa-plus-circle ml-1.5 text-emerald-600"></i> إضافة سطر بند آخر
                </button>
            </div>
        </div>

        <!-- 3. Totals Breakdown & Notes -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            
            <!-- Notes / Terms -->
            <div class="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs space-y-2">
                <label class="block text-xs font-bold text-slate-700">ملاحظات التعليمات والشروط (تظهر أسفل الفاتورة)</label>
                <textarea name="notes" rows="4" class="w-full bg-slate-50 border border-slate-200 rounded-xl p-3 text-xs focus:bg-white focus:outline-none leading-relaxed">البضاعة المباعة لا ترد ولا تستبدل إلا وفق الشروط المعتمدة.</textarea>
            </div>

            <!-- Grand Totals Box (Matching Image 2 exactly) -->
            <div class="bg-white rounded-2xl p-5 border-2 border-slate-900 shadow-sm space-y-3">
                <span class="text-xs font-black text-slate-900 block pb-2 border-b border-slate-100 flex items-center justify-between">
                    <span>ملخص المبالغ والضريبة:</span>
                    <span class="text-[11px] font-mono text-slate-500" id="lbl_curr_display"><?= htmlspecialchars($currency) ?></span>
                </span>

                <div class="space-y-2 text-xs">
                    <div class="flex items-center justify-between text-slate-600">
                        <span>إجمالي المبلغ غير شامل الضريبة:</span>
                        <strong class="font-mono text-slate-900 text-sm" id="lbl_subtotal">0.00</strong>
                    </div>

                    <div class="flex items-center justify-between text-slate-600">
                        <span>إجمالي ضريبة القيمة المضافة:</span>
                        <strong class="font-mono text-amber-600 text-sm" id="lbl_vat">0.00</strong>
                    </div>

                    <div class="flex items-center justify-between text-slate-600">
                        <span>مبلغ الخصم:</span>
                        <strong class="font-mono text-rose-600 text-sm" id="lbl_discount">0.00</strong>
                    </div>

                    <div class="flex items-center justify-between pt-3 border-t-2 border-slate-900 text-slate-900">
                        <span class="text-sm font-black">إجمالي المبلغ المستحق:</span>
                        <strong class="font-mono text-xl font-black text-emerald-700" id="lbl_grand_total">0.00</strong>
                    </div>
                </div>
            </div>

        </div>

        <!-- 4. Submit Buttons -->
        <div class="bg-white rounded-2xl p-4 border border-slate-200/80 shadow-xs flex items-center justify-between">
            <a href="invoices.php" class="text-slate-500 hover:text-slate-800 text-xs font-bold transition">
                إلغاء والعودة
            </a>

            <button type="submit" class="bg-rose-700 hover:bg-rose-800 text-white font-black px-6 py-3 rounded-xl shadow-md transition text-xs flex items-center">
                <i class="fa-solid fa-receipt ml-2 text-sm"></i>
                حفظ وإصدار الفاتورة الضريبية المبسطة الآن
            </button>
        </div>

    </form>

    <!-- Mobile Floating Sticky Dock (Visible only on mobile screens < lg) -->
    <div class="lg:hidden fixed bottom-0 inset-x-0 bg-white/95 backdrop-blur-md border-t border-slate-200 shadow-2xl z-50 p-3.5 safe-area-bottom">
        <div class="flex items-center justify-between gap-3 max-w-lg mx-auto">
            <div>
                <span class="text-[10px] font-bold text-slate-400 block">إجمالي المستحق:</span>
                <strong id="dock_grand_total" class="text-xl font-black text-slate-900 font-mono">0.00</strong>
            </div>
            <button type="submit" form="invoiceForm" class="flex-1 bg-rose-700 hover:bg-rose-800 text-white font-black py-3 px-4 rounded-xl shadow-md transition text-xs flex items-center justify-center space-x-1.5 space-x-reverse active:scale-95">
                <i class="fa-solid fa-receipt ml-1"></i>
                <span>حفظ وإصدار الفاتورة</span>
            </button>
        </div>
    </div>

</div>

<script>
let rowCount = 1;

function getTaxRate() {
    const rateInput = document.getElementById('global_tax_rate');
    return parseFloat(rateInput ? rateInput.value : 15.00) || 0;
}

function calcRow(el) {
    const row = el.closest('tr');
    const qty = parseFloat(row.querySelector('.item-qty').value) || 0;
    const price = parseFloat(row.querySelector('.item-price').value) || 0;
    const discount = parseFloat(row.querySelector('.item-discount').value) || 0;
    const rate = getTaxRate();

    const base = qty * price;
    const afterDiscount = Math.max(0, base - discount);
    const vat = afterDiscount * (rate / 100);
    const total = afterDiscount + vat;

    row.querySelector('.item-vat').textContent = vat.toFixed(2);
    row.querySelector('.item-total').textContent = total.toFixed(2);

    recalculateAll();
}

function recalculateAll() {
    const rows = document.querySelectorAll('.item-row');
    const rate = getTaxRate();
    let subtotal = 0;
    let totalVat = 0;
    let totalDiscount = 0;
    let grandTotal = 0;

    rows.forEach((row, idx) => {
        row.querySelector('.row-index').textContent = (idx + 1);
        const qty = parseFloat(row.querySelector('.item-qty').value) || 0;
        const price = parseFloat(row.querySelector('.item-price').value) || 0;
        const discount = parseFloat(row.querySelector('.item-discount').value) || 0;

        const base = qty * price;
        const afterDiscount = Math.max(0, base - discount);
        const vat = afterDiscount * (rate / 100);
        const total = afterDiscount + vat;

        row.querySelector('.item-vat').textContent = vat.toFixed(2);
        row.querySelector('.item-total').textContent = total.toFixed(2);

        subtotal += afterDiscount;
        totalVat += vat;
        totalDiscount += discount;
        grandTotal += total;
    });

    document.getElementById('lbl_subtotal').textContent = subtotal.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    document.getElementById('lbl_vat').textContent = totalVat.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    document.getElementById('lbl_discount').textContent = totalDiscount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    document.getElementById('lbl_grand_total').textContent = grandTotal.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    const curr = document.getElementById('inv_curr').value;
    document.getElementById('lbl_curr_display').textContent = curr;
    const dockEl = document.getElementById('dock_grand_total');
    if (dockEl) dockEl.textContent = grandTotal.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ' + curr;
}

function addItemRow() {
    const tbody = document.getElementById('itemsBody');
    const idx = rowCount++;
    const tr = document.createElement('tr');
    tr.className = 'item-row';
    tr.dataset.row = idx;
    tr.innerHTML = `
        <td class="p-2 text-center font-mono font-bold row-index"></td>
        <td class="p-2">
            <input type="text" name="items[${idx}][code]" placeholder="رمز الصنف" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-2 py-1.5 text-xs font-mono focus:bg-white focus:outline-none">
        </td>
        <td class="p-2">
            <input type="text" name="items[${idx}][name]" required placeholder="اسم الصنف أو الخدمة" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-2 py-1.5 text-xs font-bold focus:bg-white focus:outline-none">
        </td>
        <td class="p-2">
            <input type="number" step="0.01" min="0.01" name="items[${idx}][quantity]" value="1" oninput="calcRow(this)" required class="w-full bg-slate-50 border border-slate-200 rounded-lg px-2 py-1.5 text-xs font-mono font-bold text-center focus:bg-white focus:outline-none item-qty">
        </td>
        <td class="p-2">
            <select name="items[${idx}][unit]" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-1.5 py-1.5 text-xs focus:bg-white focus:outline-none">
                <option value="طن">طن</option>
                <option value="متر">متر</option>
                <option value="متر مربع">م²</option>
                <option value="كجم">كجم</option>
                <option value="قطعة">قطعة</option>
                <option value="كرتون">كرتون</option>
                <option value="عدد">عدد</option>
                <option value="خدمة">خدمة</option>
            </select>
        </td>
        <td class="p-2">
            <input type="number" step="0.01" min="0" name="items[${idx}][price]" value="0" oninput="calcRow(this)" required class="w-full bg-slate-50 border border-slate-200 rounded-lg px-2 py-1.5 text-xs font-mono font-bold text-center focus:bg-white focus:outline-none item-price">
        </td>
        <td class="p-2">
            <input type="number" step="0.01" min="0" name="items[${idx}][discount]" value="0" oninput="calcRow(this)" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-2 py-1.5 text-xs font-mono text-center focus:bg-white focus:outline-none item-discount">
        </td>
        <td class="p-2 text-center font-mono font-bold text-amber-700 item-vat">0.00</td>
        <td class="p-2 text-center font-mono font-black text-slate-900 item-total">0.00</td>
        <td class="p-2 text-center">
            <button type="button" onclick="removeRow(this)" class="text-slate-400 hover:text-red-500 transition p-1">
                <i class="fa-solid fa-trash-can"></i>
            </button>
        </td>
    `;
    tbody.appendChild(tr);
    recalculateAll();
}

function removeRow(btn) {
    const rows = document.querySelectorAll('.item-row');
    if (rows.length <= 1) {
        alert('يجب أن تحتوي الفاتورة على بند واحد على الأقل.');
        return;
    }
    btn.closest('tr').remove();
    recalculateAll();
}

document.addEventListener('DOMContentLoaded', function() {
    recalculateAll();
});
</script>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
