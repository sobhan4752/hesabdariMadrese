<?php
/**
 * ثبت پرداخت – نسخه نهایی با نمایش زنده و محاسبات
 * مسیر: /payment.php
 */
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/functions.php';

csrf_verify();

$page_title = 'ثبت پرداخت';
$errors = [];
$success = [];
$form_data = [
    'student_id'        => '',
    'fiscal_year_id'    => '',
    'amount'            => '',
    'amount_unit'       => 'rial',
    'payment_method'    => 'cash',
    'card_last4'        => '',
    'payment_date'      => '',
    'discount_amount'   => '',
    'previous_allocation'=> '',
    'notes'             => '',
];

$fiscal_years = $pdo_master->query("SELECT * FROM fiscal_years ORDER BY sort_order ASC, id ASC")->fetchAll();
$active_year_id = get_active_fiscal_year_id();
if (!$active_year_id && !empty($fiscal_years)) $active_year_id = $fiscal_years[0]['id'];

$previous_fiscal_id = get_previous_fiscal_year_id();
$system_unit = get_setting('currency_unit', 'rial');
$form_data['amount_unit'] = $system_unit;

// پیش‌انتخاب از طریق لینک
if (isset($_GET['student_id']) && !empty($_GET['student_id'])) {
    $pre_id = trim($_GET['student_id']);
    $stmt = $pdo->prepare("SELECT national_id FROM students WHERE national_id = ?");
    $stmt->execute([$pre_id]);
    if ($stmt->fetch()) $form_data['student_id'] = $pre_id;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form_data = [
        'student_id'         => $_POST['student_id'] ?? '',
        'fiscal_year_id'     => $_POST['fiscal_year_id'] ?? '',
        'amount'             => $_POST['amount'] ?? '',
        'amount_unit'        => $_POST['amount_unit'] ?? $system_unit,
        'payment_method'     => $_POST['payment_method'] ?? 'cash',
        'card_last4'         => $_POST['card_last4'] ?? '',
        'payment_date'       => $_POST['payment_date'] ?? '',
        'discount_amount'    => $_POST['discount_amount'] ?? '',
        'previous_allocation'=> $_POST['previous_allocation'] ?? '',
        'notes'              => $_POST['notes'] ?? '',
    ];

    $student_id         = trim($form_data['student_id']);
    $fiscal_year_id     = (int)$form_data['fiscal_year_id'];
    $amount_raw         = (int)str_replace(',', '', $form_data['amount']);
    $amount_unit        = $form_data['amount_unit'];
    $payment_method     = $form_data['payment_method'];
    $card_last4         = trim($form_data['card_last4']);
    $payment_date_jalali = trim($form_data['payment_date']);
    $discount_raw       = (int)str_replace(',', '', $form_data['discount_amount']);
    $previous_allocation = (int)str_replace(',', '', $form_data['previous_allocation']);
    $notes              = trim($form_data['notes']);

    if ($amount_unit === 'toman') $amount = $amount_raw * 10;
    else $amount = $amount_raw;

    if (empty($student_id) || strlen($student_id) !== 10) $errors[] = 'کد ملی نامعتبر است.';
    if ($fiscal_year_id < 1) $errors[] = 'سال مالی انتخاب نشده.';
    else {
        $yr = $pdo_master->query("SELECT is_closed FROM fiscal_years WHERE id = $fiscal_year_id")->fetch();
        if ($yr && $yr['is_closed']) $errors[] = 'این سال مالی بسته شده است.';
    }
    if ($amount <= 0) $errors[] = 'مبلغ باید بزرگتر از صفر باشد.';
    if (!in_array($payment_method, ['cash','card','transfer'])) $errors[] = 'نوع پرداخت نامعتبر.';
    if ($payment_method !== 'cash' && empty($card_last4)) $errors[] = '۴ رقم آخر کارت الزامی است.';
    if ($payment_method !== 'cash' && !preg_match('/^\d{4}$/', $card_last4)) $errors[] = '۴ رقم آخر باید ۴ عدد باشد.';
    if (empty($payment_date_jalali)) $errors[] = 'تاریخ پرداخت الزامی است.';
    if ($discount_raw < 0) $errors[] = 'تخفیف نمی‌تواند منفی باشد.';
    if ($previous_allocation < 0) $errors[] = 'مبلغ تخصیص‌یافته به سال قبل نمی‌تواند منفی باشد.';
    if ($previous_allocation > 0 && $previous_fiscal_id < 1) $errors[] = 'سال مالی قبلی وجود ندارد.';
    if ($previous_allocation > $amount) $errors[] = 'مبلغ تخصیص‌یافته به سال قبل نمی‌تواند بیشتر از کل مبلغ پرداختی باشد.';

    $payment_date_greg = null;
    if (!empty($payment_date_jalali)) {
        $payment_date_greg = jalali_to_gregorian($payment_date_jalali);
        if (!$payment_date_greg) $errors[] = 'فرمت تاریخ نامعتبر.';
    }

    if (empty($errors)) {
        $pdo->beginTransaction();
        try {
            if ($discount_raw > 0) {
                $stmt = $pdo->prepare("INSERT INTO student_discounts (student_id, fiscal_year_id, amount, description) VALUES (?,?,?,?)");
                $stmt->execute([$student_id, $fiscal_year_id, $discount_raw, 'تخفیف هنگام ثبت پرداخت']);
            }

            // پرداخت سال قبل (در صورت تخصیص)
            if ($previous_allocation > 0 && $previous_fiscal_id > 0) {
                $prev_db = PRIVATE_PATH . '/year_' . $previous_fiscal_id . '.sqlite';
                if (file_exists($prev_db)) {
                    $pdo_prev = new PDO('sqlite:' . $prev_db);
                    $pdo_prev->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    $stmt_prev = $pdo_prev->prepare("INSERT INTO payments (student_id, fiscal_year_id, amount, payment_method, card_last4, payment_date, notes) VALUES (?,?,?,?,?,?,?)");
                    $stmt_prev->execute([$student_id, $previous_fiscal_id, $previous_allocation, $payment_method, $card_last4 ?: null, $payment_date_greg, $notes . ' (تخصیص به سال قبل)']);
                    $pdo_prev = null;
                }
            }

            $current_amount = $amount - $previous_allocation;
            if ($current_amount > 0) {
                $stmt = $pdo->prepare("INSERT INTO payments (student_id, fiscal_year_id, amount, payment_method, card_last4, payment_date, notes) VALUES (?,?,?,?,?,?,?)");
                $stmt->execute([$student_id, $fiscal_year_id, $current_amount, $payment_method, $card_last4 ?: null, $payment_date_greg, $notes]);
                $stmt = $pdo->prepare("INSERT INTO audit_log (table_name, record_id, action, new_data) VALUES (?,?,?,?)");
                $stmt->execute(['payments', $pdo->lastInsertId(), 'INSERT', json_encode(['student_id'=>$student_id,'amount'=>$current_amount,'method'=>$payment_method,'date'=>$payment_date_greg,'fiscal_year'=>$fiscal_year_id], JSON_UNESCAPED_UNICODE)]);
            }

                    $pdo->commit();
                    // بی‌اعتبارسازی کش ترازنامه‌ها
invalidate_student_balances_cache($fiscal_year_id);
if ($previous_allocation > 0 && $previous_fiscal_id > 0) {
    invalidate_student_balances_cache($previous_fiscal_id);
}
        $success[] = 'پرداخت با موفقیت ثبت شد.';
        // فقط فیلدهای مالی و توضیحات خالی شوند، دانش‌آموز و سال مالی حفظ شوند
        $form_data = array_merge($form_data, [
            'amount'              => '',
            'card_last4'          => '',
            'payment_date'        => '',
            'discount_amount'     => '',
            'previous_allocation' => '',
            'notes'               => '',
        ]);
    } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'خطا در ثبت: ' . $e->getMessage();
        }
    }
}

include INCLUDES_PATH . '/header.php';
?>

<div class="max-w-4xl mx-auto">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between mb-8 gap-4">
    <div>
        <h1 class="text-3xl font-extrabold text-slate-800">ثبت پرداخت</h1>
        <p class="text-slate-500 mt-2">پرداخت دانش‌آموز همراه با تخفیف احتمالی و امکان تخصیص به سال قبل. محاسبات زنده نمایش داده می‌شود.</p>
    </div>
    <?php if (!empty($form_data['student_id'])): ?>
        <a href="student_card.php?id=<?= urlencode($form_data['student_id']) ?>" 
           class="px-5 py-3 bg-slate-100 hover:bg-slate-200 text-slate-700 font-medium rounded-xl transition-colors text-sm">
            بازگشت به کارت حساب
        </a>
    <?php endif; ?>
</div>
    <?php if ($errors): ?><div class="bg-red-50 border border-red-200 rounded-xl p-4 mb-6"><?php foreach ($errors as $e): ?><p class="text-red-800 text-sm"><?= e($e) ?></p><?php endforeach; ?></div><?php endif; ?>
    <?php if ($success): ?><div class="bg-emerald-50 border border-emerald-200 rounded-xl p-4 mb-6"><?php foreach ($success as $s): ?><p class="text-emerald-800 text-sm"><?= e($s) ?></p><?php endforeach; ?></div><?php endif; ?>

    <form method="post" id="payment-form" class="bg-white rounded-2xl border border-slate-200 p-6 sm:p-8 space-y-6">
        <?= csrf_field() ?>
        <!-- سال مالی -->
        <div>
            <label class="block text-sm font-semibold text-slate-700 mb-2">سال مالی</label>
            <select name="fiscal_year_id" id="fiscal_year_id" class="w-full p-3 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-200 focus:border-indigo-400 outline-none">
                <?php foreach ($fiscal_years as $fy): ?>
                    <option value="<?= $fy['id'] ?>" <?= ($fy['id'] == $active_year_id) ? 'selected' : '' ?>><?= e($fy['name']) ?> - شهریه: <?= number_format($fy['tuition_amount']) ?> ریال <?= $fy['is_closed'] ? '(بسته)' : '' ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- جستجوی دانش‌آموز -->
        <div class="relative">
            <label class="block text-sm font-semibold text-slate-700 mb-2">جستجوی دانش‌آموز (نام یا کد ملی)</label>
            <input type="text" id="student_search" placeholder="حداقل ۲ حرف تایپ کنید..." autocomplete="off" class="w-full p-3 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-200 focus:border-indigo-400 outline-none">
            <input type="hidden" name="student_id" id="student_id" value="<?= e($form_data['student_id']) ?>">
            <div id="search_results" class="absolute z-20 w-full bg-white border border-slate-200 rounded-xl shadow-lg mt-1 hidden max-h-60 overflow-y-auto"></div>
        </div>

        <!-- اطلاعات دانش‌آموز -->
        <div id="student_info" class="hidden bg-slate-50 rounded-xl p-4 grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div><span class="text-xs text-slate-500">نام:</span> <span id="s_name" class="font-medium"></span></div>
            <div><span class="text-xs text-slate-500">کد ملی:</span> <span id="s_national_id" class="font-medium"></span></div>
            <div><span class="text-xs text-slate-500">کلاس:</span> <span id="s_class" class="font-medium"></span></div>
            <div><span class="text-xs text-slate-500">دفترچه:</span> <span id="s_ledger" class="font-medium"></span></div>
            <div><span class="text-xs text-slate-500">شهریه پس از تخفیف:</span> <span id="s_tuition_after" class="font-bold text-slate-800"></span></div>
            <div><span class="text-xs text-slate-500">پرداختی تاکنون:</span> <span id="s_paid" class="font-bold text-indigo-600"></span></div>
            <div><span class="text-xs text-slate-500">مانده:</span> <span id="s_balance" class="font-bold text-red-600"></span></div>
        </div>

        <!-- مبلغ پرداختی -->
        <div>
            <label class="block text-sm font-semibold text-slate-700 mb-2">مبلغ پرداختی (<?= $system_unit === 'toman' ? 'تومان' : 'ریال' ?>)</label>
            <div class="flex flex-col sm:flex-row gap-2 items-start sm:items-center">
                <input type="text" name="amount" id="amount" value="<?= e($form_data['amount']) ?>" class="money-input w-full sm:flex-1 p-3 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-200 focus:border-indigo-400 outline-none" placeholder="مبلغ">
                <input type="hidden" name="amount_unit" value="<?= $system_unit ?>">
            </div>
            <div id="amount_words" class="text-xs text-slate-500 mt-1 min-h-[1.25rem]"></div>
        </div>
        <!-- نوع پرداخت -->
        <div>
            <label class="block text-sm font-semibold text-slate-700 mb-2">نوع پرداخت</label>
            <div class="flex flex-col sm:flex-row flex-wrap gap-3">
                <?php foreach (['cash'=>'نقدی','card'=>'کارت‌خوان','transfer'=>'انتقال وجه'] as $val=>$label): ?>
                <label class="flex items-center gap-2 cursor-pointer px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl hover:border-indigo-300 transition-colors has-[:checked]:border-indigo-500 has-[:checked]:bg-indigo-50 has-[:checked]:text-indigo-700 w-full sm:w-auto">
                    <input type="radio" name="payment_method" value="<?= $val ?>" <?= $form_data['payment_method']===$val?'checked':'' ?> class="accent-indigo-600">
                    <span class="text-sm font-medium"><?= $label ?></span>
                </label>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ۴ رقم آخر کارت -->
        <div id="card_field" class="<?= $form_data['payment_method']==='cash'?'hidden':'' ?>">
            <label class="block text-sm font-semibold text-slate-700 mb-2">۴ رقم آخر کارت</label>
            <input type="text" name="card_last4" value="<?= e($form_data['card_last4']) ?>" maxlength="4" class="w-full sm:w-32 p-3 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-200 focus:border-indigo-400 outline-none text-center font-mono tracking-widest" placeholder="۱۲۳۴">
        </div>

        <!-- تاریخ پرداخت -->
        <div>
            <label class="block text-sm font-semibold text-slate-700 mb-2">تاریخ پرداخت</label>
            <input type="text" name="payment_date" id="payment_date" value="<?= e($form_data['payment_date']) ?>" class="datepicker-jalali w-full p-3 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-200 focus:border-indigo-400 outline-none" placeholder="انتخاب تاریخ" readonly>
        </div>

        <!-- تخفیف -->
        <div class="bg-amber-50 border border-amber-200 rounded-xl p-4">
            <label class="block text-sm font-semibold text-amber-800 mb-2">تخفیف (اختیاری - ریال)</label>
            <input type="text" name="discount_amount" id="discount_amount" value="<?= e($form_data['discount_amount']) ?>" class="money-input w-full p-3 bg-white border border-amber-200 rounded-xl focus:ring-2 focus:ring-amber-200 focus:border-amber-400 outline-none" placeholder="در صورت نیاز تخفیف را وارد کنید">
            <div id="discount_words" class="text-xs text-amber-600 mt-1 min-h-[1.25rem]"></div>
            <p class="text-xs text-amber-600 mt-1">در صورت وارد کردن، یک رکورد تخفیف ثبت خواهد شد.</p>
        </div>

        <!-- تخصیص به سال قبل -->
        <?php if ($previous_fiscal_id > 0): ?>
        <div class="bg-blue-50 border border-blue-200 rounded-xl p-4" id="previous_allocation_section" style="display:none;">
            <label class="block text-sm font-semibold text-blue-800 mb-2">تخصیص به سال قبل (اختیاری)</label>
            <p class="text-xs text-blue-600 mb-2">مبلغی که می‌خواهید برای تسویه بدهی سال قبل (<?= e($fiscal_years[array_search($previous_fiscal_id, array_column($fiscal_years, 'id'))]['name'] ?? '') ?>) اختصاص دهید.</p>
            <input type="text" name="previous_allocation" id="previous_allocation" value="<?= e($form_data['previous_allocation']) ?>" class="money-input w-full p-3 bg-white border border-blue-200 rounded-xl focus:ring-2 focus:ring-blue-200 focus:border-blue-400 outline-none" placeholder="مبلغ به ریال">
            <div id="previous_balance_info" class="text-xs text-blue-600 mt-2"></div>
        </div>
        <?php endif; ?>

        <!-- مانده جدید -->
        <div id="new_balance_section" class="hidden bg-slate-50 rounded-xl p-4">
            <p class="text-sm text-slate-600">مانده پس از این پرداخت و تخفیف: <span id="new_balance" class="font-extrabold text-lg"></span></p>
        </div>

           <?php if (get_setting('payment_notes_enabled', 'enabled') === 'enabled'): ?>
        <div>
            <label class="block text-sm font-semibold text-slate-700 mb-2">توضیحات (اختیاری)</label>
            <textarea name="notes" rows="2" class="w-full p-3 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-200 focus:border-indigo-400 outline-none"><?= e($form_data['notes']) ?></textarea>
        </div>
        <?php endif; ?>

        <div class="flex justify-end">
    <button type="submit" class="px-6 sm:px-8 py-3.5 bg-emerald-600 hover:bg-emerald-700 text-white font-bold rounded-xl shadow-lg shadow-emerald-200 hover:shadow-emerald-300 transition-all btn-pulse w-full sm:w-auto">ثبت پرداخت</button>
</div>
    </form>
</div>

<script>
// ======== تنظیمات اولیه ========
const SYSTEM_UNIT = '<?= $system_unit ?>';
const AMOUNT_UNIT = SYSTEM_UNIT; // واحد پولی همواره از تنظیمات سیستم
console.log('SYSTEM_UNIT:', SYSTEM_UNIT);

// ======== توابع کمکی ========
function getAmountUnit() {
    return AMOUNT_UNIT;
}

function getAmountInRial() {
    const raw = $('#amount').val().replace(/,/g, '');
    const amount = parseInt(raw) || 0;
    return getAmountUnit() === 'toman' ? amount * 10 : amount;
}

$(function(){
    const $searchInput = $('#student_search');
    const $resultsDiv = $('#search_results');
    const $studentId = $('#student_id');
    const $studentInfo = $('#student_info');
    const $fiscalYear = $('#fiscal_year_id');
    const $amountInput = $('#amount');
    const $discountInput = $('#discount_amount');
    const $previousAllocation = $('#previous_allocation');
    const $previousSection = $('#previous_allocation_section');
    const $previousBalanceInfo = $('#previous_balance_info');
    const $wordsDiv = $('#amount_words');
    const $discountWordsDiv = $('#discount_words');
    const $newBalanceSpan = $('#new_balance');
    const $newBalanceSection = $('#new_balance_section');
    const $cardField = $('#card_field');
    let searchTimeout, selectedStudent;

    // جستجوی زنده
    $searchInput.on('input', function(){
        clearTimeout(searchTimeout);
        const term = this.value.trim();
        if (term.length < 2) { $resultsDiv.hide().empty(); return; }
        $resultsDiv.show().html('<div class="p-3 text-sm text-slate-500">در حال جستجو...</div>');
        searchTimeout = setTimeout(() => {
            $.getJSON('ajax.php', { action: 'search_student', term: term }, function(data){
                $resultsDiv.empty();
                if (!data.length) $resultsDiv.append('<div class="p-3 text-sm text-slate-500">نتیجه‌ای یافت نشد</div>');
                else data.forEach(s => {
                    const div = $(`<div class="p-3 hover:bg-indigo-50 cursor-pointer border-b last:border-b-0 transition-colors">
                        <span class="font-medium text-slate-800">${s.first_name} ${s.last_name}</span>
                        <span class="text-xs text-slate-500 mr-2">(${s.class_name})</span>
                        <span class="text-xs text-slate-400 block" dir="ltr">${s.national_id}</span>
                    </div>`);
                    div.on('click', function(){ selectStudent(s); });
                    $resultsDiv.append(div);
                });
            });
        }, 300);
    });

    $(document).on('click', function(e){ if (!$(e.target).closest('#search_results, #student_search').length) $resultsDiv.hide(); });

    function selectStudent(student){
        selectedStudent = student;
        $studentId.val(student.national_id);
        $searchInput.val(`${student.first_name} ${student.last_name} (${student.class_name})`);
        $resultsDiv.hide();
        updateStudentInfo();
        updateCalculations();
    }

    function updateStudentInfo(){
        if (!selectedStudent || !$fiscalYear.val()) return;
        $.getJSON('ajax.php', { action: 'get_balance', student_id: selectedStudent.national_id, fiscal_year_id: $fiscalYear.val() }, function(data){
            if (data.error) { showToast(data.error, 'error'); return; }
            $('#s_name').text(selectedStudent.first_name+' '+selectedStudent.last_name);
            $('#s_national_id').text(selectedStudent.national_id);
            $('#s_class').text(selectedStudent.class_name);
            $('#s_ledger').text(selectedStudent.ledger_number || '-');
            $('#s_tuition_after').text(data.tuition_after_formatted);
            $('#s_paid').text(data.payments_formatted);
            $('#s_balance').text(data.balance_formatted);
            $studentInfo.show();
        });

        <?php if ($previous_fiscal_id > 0): ?>
        $.getJSON('ajax.php', { action: 'get_previous_balance', student_id: selectedStudent ? selectedStudent.national_id : '' }, function(data){
            if (data && data.balance > 0) {
                $previousSection.show();
                $previousBalanceInfo.text('بدهی سال قبل: ' + data.balance_formatted + ' | حداکثر قابل تخصیص: ' + data.balance.toLocaleString() + ' ریال');
            } else {
                $previousSection.hide();
            }
        });
        <?php endif; ?>
    }

    function updateCalculations(){
        console.log('updateCalculations called');
        if (!selectedStudent) return;
        const fid = $fiscalYear.val();
        const amount = getAmountInRial();
        const discountRaw = $discountInput.val().replace(/,/g, '');
        const discount = parseInt(discountRaw) || 0;
        const previousRaw = ($previousAllocation.length > 0 ? $previousAllocation.val() : '0').replace(/,/g, '');
        const previous = parseInt(previousRaw) || 0;
        const unit = getAmountUnit();

        // نمایش زندهٔ مبلغ اصلی
        if (amount > 0) {
            const displayAmount = unit === 'toman' ? amount / 10 : amount;
            $.getJSON('ajax.php', { action: 'convert_words', amount: displayAmount, unit: unit }, function(data){
                $wordsDiv.text(data.words);
            });
        } else $wordsDiv.text('');

        // نمایش زندهٔ تخفیف (با واحد سیستم)
        if (discount > 0) {
            $.getJSON('ajax.php', { action: 'convert_words', amount: discount, unit: unit }, function(data){
                $discountWordsDiv.text(data.words);
            });
        } else $discountWordsDiv.text('');

        // محاسبهٔ ماندهٔ جدید
        $.getJSON('ajax.php', { action: 'get_balance', student_id: selectedStudent.national_id, fiscal_year_id: fid }, function(data){
            if (data.error) return;
            const newBal = data.balance - (amount - previous) - discount;
            const displayUnit = SYSTEM_UNIT;
            const displayAmount = displayUnit === 'toman' ? newBal / 10 : newBal;
            const formatted = Math.abs(displayAmount).toLocaleString('en-US') + (displayUnit === 'toman' ? ' تومان' : ' ریال');
            $newBalanceSpan.text(formatted + ' ' + (newBal > 0 ? '(بدهکار)' : newBal < 0 ? '(بستانکار)' : '(تسویه)'));
            $newBalanceSpan.removeClass('text-red-600 text-emerald-600 text-blue-600').addClass(newBal > 0 ? 'text-red-600' : newBal < 0 ? 'text-blue-600' : 'text-emerald-600');
            $newBalanceSection.show();
        });
    }

    // اتصال رویدادها
    $amountInput.on('input', updateCalculations);
    $discountInput.on('input', updateCalculations);
    $previousAllocation.on('input', updateCalculations);
    $fiscalYear.on('change', function(){ if (selectedStudent) { updateStudentInfo(); updateCalculations(); } });
    $('input[name="payment_method"]').on('change', function(){
        if (this.value === 'cash') $cardField.slideUp(200); else $cardField.slideDown(200);
    });

    // در صورت وجود انتخاب اولیه
    <?php if (!empty($form_data['student_id'])): ?>
        $.getJSON('ajax.php', { action: 'search_student', term: '<?= e($form_data['student_id']) ?>' }, function(data) {
            const found = data.find(s => s.national_id === '<?= e($form_data['student_id']) ?>');
            if (found) selectStudent(found); else showToast('کد ملی نامعتبر', 'error');
        });
    <?php endif; ?>
});
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>