<?php
/**
 * کارت حساب دانش‌آموز – نسخه ۲.۲ با بدهی سال قبل از دیتابیس قدیمی
 * مسیر: /student_card.php
 */
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/functions.php';

$student_id = $_GET['id'] ?? '';
if (empty($student_id)) redirect('students.php');

$stmt = $pdo->prepare("SELECT * FROM students WHERE national_id = ?");
$stmt->execute([$student_id]);
$student = $stmt->fetch();

if (!$student) {
    $page_title = 'خطا';
    include INCLUDES_PATH . '/header.php';
    echo '<div class="max-w-4xl mx-auto"><div class="bg-red-50 border border-red-200 rounded-xl p-6 text-center"><p class="text-red-800">دانش‌آموز یافت نشد.</p><a href="students.php" class="inline-block mt-4 text-indigo-600 hover:underline">بازگشت به لیست</a></div></div>';
    include INCLUDES_PATH . '/footer.php';
    exit;
}

$page_title = 'کارت حساب - ' . $student['first_name'] . ' ' . $student['last_name'];

$fiscal_years = $pdo_master->query("SELECT * FROM fiscal_years ORDER BY id DESC")->fetchAll();
$selected_fiscal = (int)($_GET['fiscal'] ?? 0);
if ($selected_fiscal < 1 && !empty($fiscal_years)) {
    $selected_fiscal = get_active_fiscal_year_id();
    if (!$selected_fiscal) $selected_fiscal = $fiscal_years[0]['id'];
}

$tuition        = get_tuition($selected_fiscal);
$discount       = get_total_discount($student_id, $selected_fiscal);
$payments_total = get_total_payments($student_id, $selected_fiscal);
$balance        = $tuition - $discount - $payments_total;

// بدهی سال قبل (از دیتابیس قدیمی)
// بررسی بدهی سال قبل (در صورت فعال بودن در تنظیمات)
$check_prev = get_setting('check_previous_balance', 'enabled');
if ($check_prev === 'enabled') {
    $previous_balance = get_previous_year_balance($student_id);
} else {
    $previous_balance = null;
}

$discounts_list = $pdo->prepare("SELECT * FROM student_discounts WHERE student_id = ? AND fiscal_year_id = ? ORDER BY id DESC");
$discounts_list->execute([$student_id, $selected_fiscal]);
$discounts = $discounts_list->fetchAll();

$payments_list = $pdo->prepare("SELECT * FROM payments WHERE student_id = ? AND fiscal_year_id = ? ORDER BY payment_date DESC, id DESC");
$payments_list->execute([$student_id, $selected_fiscal]);
$payments = $payments_list->fetchAll();

$status       = $balance > 0 ? 'بدهکار' : ($balance < 0 ? 'بستانکار' : 'تسویه');
$status_color = $balance > 0 ? 'red' : ($balance < 0 ? 'blue' : 'emerald');
$unit         = get_setting('currency_unit', 'rial');
$method_fa    = ['cash' => 'نقدی', 'card' => 'کارت‌خوان', 'transfer' => 'انتقال وجه'];

include INCLUDES_PATH . '/header.php';
?>

<div class="max-w-5xl mx-auto">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between mb-6 gap-4">
        <div>
            <a href="students.php" class="text-sm text-indigo-600 hover:text-indigo-800 mb-2 inline-flex items-center gap-1">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
                بازگشت به لیست
            </a>
            <h1 class="text-2xl font-extrabold text-slate-800"><?= e($student['first_name'] . ' ' . $student['last_name']) ?></h1>
            <p class="text-slate-500 text-sm mt-1">کد ملی: <?= e($student['national_id']) ?> | کلاس: <?= e($student['class_name']) ?></p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="payment.php?student_id=<?= urlencode($student['national_id']) ?>" class="px-4 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white font-medium rounded-xl transition-colors btn-pulse text-sm">ثبت پرداخت جدید</a>
            <?php if ($balance > 0): ?>
                <button onclick="quickSettle()" class="px-4 py-2.5 bg-amber-500 hover:bg-amber-600 text-white font-medium rounded-xl transition-colors btn-pulse text-sm">تسویه آنی</button>
            <?php endif; ?>
            <a href="receipt.php?student_id=<?= urlencode($student['national_id']) ?>&fiscal=<?= $selected_fiscal ?>" class="px-4 py-2.5 bg-amber-500 hover:bg-amber-600 text-white font-medium rounded-xl transition-colors btn-pulse text-sm">رسید کل</a>
            <button onclick="window.print()" class="px-4 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 font-medium rounded-xl transition-colors btn-pulse text-sm">چاپ</button>
        </div>
    </div>

    <!-- انتخاب سال مالی -->
    <div class="bg-white rounded-2xl border border-slate-200 p-4 mb-6">
        <form method="get" class="flex items-center gap-3">
            <input type="hidden" name="id" value="<?= e($student_id) ?>">
            <label class="text-sm font-semibold text-slate-700">سال مالی:</label>
            <select name="fiscal" onchange="this.form.submit()" class="py-2 px-4 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:ring-2 focus:ring-indigo-200 focus:border-indigo-400 outline-none transition-all">
                <?php foreach ($fiscal_years as $fy): ?>
                    <option value="<?= $fy['id'] ?>" <?= $fy['id'] == $selected_fiscal ? 'selected' : '' ?>><?= e($fy['name']) ?> <?= $fy['is_closed'] ? '(بسته)' : '(فعال)' ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <!-- هشدار بدهی سال قبل -->
    <?php if ($previous_balance !== null && $previous_balance > 0): ?>
        <div class="bg-red-50 border border-red-200 rounded-xl p-4 mb-6">
            <p class="text-sm text-red-800">
                ⚠️ این دانش‌آموز از سال مالی قبل مبلغ
                <span class="font-bold"><?= format_money($previous_balance, $unit) ?></span> بدهی دارد.
                لطفاً ابتدا بدهی سال قبل تسویه شود یا در ثبت پرداخت جدید، بخشی از مبلغ را به سال قبل اختصاص دهید.
            </p>
        </div>
    <?php endif; ?>

    <!-- کارت‌های خلاصه -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-xl border border-slate-200 p-5"><p class="text-xs text-slate-500 mb-1">شهریه پایه</p><p class="text-xl font-extrabold text-slate-800"><?= format_money($tuition, $unit) ?></p></div>
        <div class="bg-white rounded-xl border border-slate-200 p-5"><p class="text-xs text-slate-500 mb-1">مجموع تخفیف‌ها</p><p class="text-xl font-extrabold text-violet-600"><?= format_money($discount, $unit) ?></p></div>
        <div class="bg-white rounded-xl border border-slate-200 p-5"><p class="text-xs text-slate-500 mb-1">مجموع پرداختی</p><p class="text-xl font-extrabold text-emerald-600"><?= format_money($payments_total, $unit) ?></p></div>
        <div class="bg-<?=$status_color?>-50 rounded-xl border border-<?=$status_color?>-200 p-5">
            <p class="text-xs text-<?=$status_color?>-600 mb-1">مانده</p><p class="text-xl font-extrabold text-<?=$status_color?>-700"><?= format_money(abs($balance), $unit) ?></p>
            <span class="inline-block mt-1 px-2 py-0.5 bg-<?=$status_color?>-100 text-<?=$status_color?>-700 rounded-full text-xs font-medium"><?= $status ?></span>
        </div>
    </div>

    <!-- اضافه پرداخت -->
    <?php if ($balance < 0): ?>
        <div class="bg-blue-50 border border-blue-200 rounded-xl p-4 mb-6">
            <p class="text-sm text-blue-800">
                💎 این دانش‌آموز <span class="font-bold"><?= format_money(abs($balance), $unit) ?></span> اضافه پرداخت دارد.
            </p>
        </div>
    <?php endif; ?>

    <!-- اطلاعات تکمیلی -->
    <div class="bg-white rounded-2xl border border-slate-200 p-5 mb-6">
        <h3 class="font-bold text-slate-800 mb-4">اطلاعات دانش‌آموز</h3>
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4 text-sm">
            <div><span class="text-slate-500">نام پدر:</span> <span class="font-medium"><?= e($student['father_name'] ?: '-') ?></span></div>
            <div><span class="text-slate-500">تاریخ تولد:</span> <span class="font-medium"><?= e($student['birth_date'] ? str_replace('-', '/', $student['birth_date']) : '-') ?></span></div>
            <div>
                <span class="text-slate-500">شماره دفتر:</span>
                <span id="ledger-display" class="font-medium"><?= e($student['ledger_number'] ?: '-') ?></span>
                <input type="text" id="ledger-input" value="<?= e($student['ledger_number']) ?>" class="hidden w-24 p-1 border border-slate-300 rounded text-sm inline-block" onblur="saveLedger()" onkeydown="if(event.key==='Enter') saveLedger()">
                <button onclick="editLedger()" class="ml-2 text-indigo-600 hover:text-indigo-800 text-xs focus:outline-none"><svg class="w-4 h-4 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg></button>
            </div>
            <div><span class="text-slate-500">موبایل:</span> <span class="font-medium" dir="ltr"><?= e($student['mobile'] ?: '-') ?></span></div>
            <div><span class="text-slate-500">تلفن ثابت:</span> <span class="font-medium" dir="ltr"><?= e($student['phone'] ?: '-') ?></span></div>
            <div><span class="text-slate-500">محل صدور:</span> <span class="font-medium"><?= e($student['issuing_place'] ?: '-') ?></span></div>
        </div>
    </div>

    <!-- ریز پرداخت‌ها -->
    <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden mb-6">
        <div class="px-6 py-4 border-b bg-slate-50"><h3 class="font-bold text-slate-800">ریز پرداخت‌ها (<?= count($payments) ?> مورد)</h3></div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead><tr class="border-b bg-slate-50">
    <th class="text-right px-4 py-3">#</th>
    <th class="text-right px-4 py-3">تاریخ پرداخت</th>
    <th class="text-right px-4 py-3">مبلغ</th>
    <th class="text-right px-4 py-3">نوع</th>
    <th class="text-right px-4 py-3">کارت</th>
    <th class="text-right px-4 py-3">توضیحات</th>
    <th class="text-right px-4 py-3">تاریخ ثبت</th>
    <th class="text-center px-4 py-3">رسید</th>
    <th class="text-center px-4 py-3">حذف</th>
</tr></thead>
                <tbody>
                    <?php if (empty($payments)): ?><tr><td colspan="9" class="text-center py-8 text-slate-400">هیچ پرداختی ثبت نشده است.</td></tr>
                    <?php else: foreach ($payments as $i => $p): ?>
                        <tr class="border-b hover:bg-slate-50 table-row-hover">
                            <td class="px-4 py-3 text-slate-400"><?= count($payments) - $i ?></td>
                            <td class="px-4 py-3 font-medium"><?= e(gregorian_to_jalali($p['payment_date'])) ?></td>
                            <td class="px-4 py-3 font-medium text-slate-800"><?= format_money($p['amount'], $unit) ?></td>
                            <td class="px-4 py-3"><span class="px-2 py-0.5 rounded-full text-xs font-medium <?= $p['payment_method']==='cash'?'bg-blue-100 text-blue-700':($p['payment_method']==='card'?'bg-purple-100 text-purple-700':'bg-teal-100 text-teal-700') ?>"><?= $method_fa[$p['payment_method']] ?? $p['payment_method'] ?></span></td>
                            <td class="px-4 py-3 font-mono text-slate-500" dir="ltr"><?= e($p['card_last4'] ?: '-') ?></td>
                            <td class="px-4 py-3 text-slate-500 max-w-[200px] truncate"><?= e($p['notes'] ?: '-') ?></td>
                            <td class="px-4 py-3 text-slate-500 text-xs"><?= e(gregorian_to_jalali(substr($p['created_at'], 0, 10))) ?></td>
                            <td class="px-4 py-3 text-center">
    <a href="receipt.php?id=<?= $p['id'] ?>" class="inline-flex items-center gap-1 px-3 py-1.5 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 rounded-lg text-xs font-medium transition-colors">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/></svg>
        رسید
    </a>
</td>
<td class="px-4 py-3 text-center">
    <button onclick="deletePayment(<?= $p['id'] ?>)" class="inline-flex items-center gap-1 px-3 py-1.5 bg-red-50 hover:bg-red-100 text-red-700 rounded-lg text-xs font-medium transition-colors" title="حذف پرداخت">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
    </button>
</td>
</tr>

                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ریز تخفیف‌ها -->
    <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden mb-6">
        <div class="px-6 py-4 border-b bg-slate-50"><h3 class="font-bold text-slate-800">ریز تخفیف‌ها (<?= count($discounts) ?> مورد)</h3></div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead><tr class="border-b bg-slate-50"><th class="text-right px-4 py-3">تاریخ ثبت</th><th class="text-right px-4 py-3">مبلغ</th><th class="text-right px-4 py-3">توضیحات</th></tr></thead>
                <tbody>
                    <?php if (empty($discounts)): ?><tr><td colspan="3" class="text-center py-8 text-slate-400">تخفیفی ثبت نشده است.</td></tr>
                    <?php else: foreach ($discounts as $d): ?>
                        <tr class="border-b hover:bg-slate-50 table-row-hover">
                            <td class="px-4 py-3 text-slate-500"><?= e(gregorian_to_jalali(substr($d['created_at'], 0, 10))) ?></td>
                            <td class="px-4 py-3 font-medium text-violet-600"><?= format_money($d['amount'], $unit) ?></td>
                            <td class="px-4 py-3 text-slate-500"><?= e($d['description'] ?: '-') ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="flex gap-3">
        <a href="ajax.php?action=export_card_csv&student_id=<?= urlencode($student_id) ?>&fiscal=<?= $selected_fiscal ?>" class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 font-medium rounded-xl transition-colors btn-pulse text-sm">CSV</a>
        <a href="ajax.php?action=export_card_excel&student_id=<?= urlencode($student_id) ?>&fiscal=<?= $selected_fiscal ?>" class="px-5 py-2.5 bg-green-100 hover:bg-green-200 text-green-700 font-medium rounded-xl transition-colors btn-pulse text-sm">Excel</a>
    </div>
</div>

<script>
function editLedger() {
    $('#ledger-display').hide();
    $('#ledger-input').show().focus();
}
function saveLedger() {
    const val = $('#ledger-input').val().trim();
    $.post('ajax.php', { action: 'update_ledger', student_id: '<?= e($student_id) ?>', ledger: val }, function(response){
        if (response === 'ok') {
            $('#ledger-display').text(val || '-').show();
            $('#ledger-input').hide();
        } else {
            alert('خطا در ذخیره');
        }
    });
}
function quickSettle() {
    if (!confirm('آیا از تسویه آنی این دانش‌آموز اطمینان دارید؟')) return;
    $.post('ajax.php', {
        action: 'quick_settle',
        student_id: '<?= e($student_id) ?>',
        fiscal_year_id: <?= $selected_fiscal ?>
    }, function(response) {
        if (response.success) {
            showToast(response.message, 'success');
            setTimeout(() => location.reload(), 800);
        } else {
            showToast(response.error || 'خطا در تسویه', 'error');
        }
    }, 'json');
}
function deletePayment(paymentId) {
    if (!confirm('آیا از حذف این پرداخت اطمینان دارید؟ این عملیات قابل بازگشت نیست.')) return;
    $.post('ajax.php', {
        action: 'delete_payment',
        id: paymentId,
        student_id: '<?= e($student_id) ?>'
    }, function(response) {
        if (response.success) {
            showToast(response.message, 'success');
            setTimeout(() => location.reload(), 800);
        } else {
            showToast(response.error || 'خطا در حذف', 'error');
        }
    }, 'json');
}
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>