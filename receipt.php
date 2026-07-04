<?php
/**
 * رسید پرداخت – نسخه نهایی با اصلاح کامل ریسپانسیو موبایل
 * مسیر: /receipt.php
 */
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/functions.php';
require_once ROOT_PATH . '/vendor/autoload.php';

use Mpdf\Mpdf;

$payment_id = (int)($_GET['id'] ?? 0);
$student_id = $_GET['student_id'] ?? '';
$fiscal = (int)($_GET['fiscal'] ?? 0);

// ========== داده‌ها ==========
if (!empty($student_id)) {
    $stmt = $pdo->prepare("SELECT * FROM students WHERE national_id = ?");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch();
    if (!$student) die('دانش‌آموز یافت نشد');

    $fiscal_cond = $fiscal ? "AND fiscal_year_id = $fiscal" : "";
    $payments = $pdo->query("SELECT * FROM payments WHERE student_id = '$student_id' $fiscal_cond ORDER BY payment_date DESC")->fetchAll();
    $total_paid = array_sum(array_column($payments, 'amount'));
    $is_single = false;
} elseif ($payment_id > 0) {
    $stmt = $pdo->prepare("SELECT p.*, s.first_name, s.last_name, s.class_name, s.ledger_number, s.national_id, s.mobile, s.father_name
                           FROM payments p
                           JOIN students s ON p.student_id = s.national_id
                           WHERE p.id = ?");
    $stmt->execute([$payment_id]);
    $payment = $stmt->fetch();
    if (!$payment) die('پرداخت یافت نشد');
    $is_single = true;
} else {
    die('پارامتر نامعتبر');
}

$template = $_GET['template'] ?? 'modern';
$download = isset($_GET['pdf']);
$school_name = get_setting('school_name', 'دبیرستان نمونه');
$unit = get_setting('currency_unit', 'rial');
$method_fa = ['cash' => 'نقدی', 'card' => 'کارت‌خوان', 'transfer' => 'انتقال وجه'];

// ========== ساخت HTML ==========
ob_start();
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<style>
body { font-family: 'Vazirmatn', sans-serif; direction: rtl; }
@media print {
    .no-print, #sidebar, header, .btn-pulse, button, a { display: none !important; }
    body { background: white; }
    #receipt-printable { display: block; }
}
</style>
</head>
<body>
<div id="receipt-printable">
<?php if ($template === 'minimal'): ?>
    <h3 class="text-center"><?= e($school_name) ?></h3>
    <p class="text-center">رسید پرداخت شهریه</p>
    <?php if ($is_single): ?>
    <div class="overflow-x-auto">
        <table class="w-full border-collapse">
        <tr><td>شماره رسید:</td><td><?= $payment_id ?></td></tr>
        <tr><td>تاریخ:</td><td><?= gregorian_to_jalali($payment['payment_date']) ?></td></tr>
        <tr><td>کد ملی:</td><td><?= $payment['national_id'] ?></td></tr>
        <tr><td>دانش‌آموز:</td><td><?= e($payment['first_name'].' '.$payment['last_name']) ?></td></tr>
        <tr><td>نام پدر:</td><td><?= e($payment['father_name'] ?: '-') ?></td></tr>
        <tr><td>کلاس:</td><td><?= e($payment['class_name']) ?></td></tr>
        <tr><td>مبلغ پرداختی:</td><td><?= format_money($payment['amount'], $unit) ?></td></tr>
        <tr><td>نوع پرداخت:</td><td><?= $method_fa[$payment['payment_method']] ?? $payment['payment_method'] ?></td></tr>
        <?php if ($payment['card_last4']): ?><tr><td>۴ رقم آخر کارت:</td><td><?= $payment['card_last4'] ?></td></tr><?php endif; ?>
        </table>
    </div>
    <?php else: ?>
    <p class="text-sm">کد ملی: <?= $student['national_id'] ?> | دانش‌آموز: <?= e($student['first_name'].' '.$student['last_name']) ?> | نام پدر: <?= e($student['father_name'] ?: '-') ?> | کلاس: <?= e($student['class_name']) ?></p>
    <div class="overflow-x-auto">
        <table class="w-full border-collapse">
        <?php foreach ($payments as $p): ?>
        <tr><td><?= gregorian_to_jalali($p['payment_date']) ?></td><td><?= format_money($p['amount'], $unit) ?></td><td><?= $method_fa[$p['payment_method']] ?? $p['payment_method'] ?></td></tr>
        <?php endforeach; ?>
        <tr><td><b>جمع کل</b></td><td><b><?= format_money($total_paid, $unit) ?></b></td></tr>
        </table>
    </div>
    <?php endif; ?>
<?php elseif ($template === 'classic'): ?>
    <div class="text-center mb-5"><h2><?= e($school_name) ?></h2><p>رسید رسمی پرداخت شهریه</p></div>
    <?php if ($is_single): ?>
    <div class="overflow-x-auto">
        <table class="w-full border-collapse">
        <tr><td class="bg-gray-100 font-bold">شماره رسید:</td><td><?= $payment_id ?></td></tr>
        <tr><td class="bg-gray-100 font-bold">تاریخ:</td><td><?= gregorian_to_jalali($payment['payment_date']) ?></td></tr>
        <tr><td class="bg-gray-100 font-bold">کد ملی:</td><td><?= $payment['national_id'] ?></td></tr>
        <tr><td class="bg-gray-100 font-bold">دانش‌آموز:</td><td><?= e($payment['first_name'].' '.$payment['last_name']) ?></td></tr>
        <tr><td class="bg-gray-100 font-bold">نام پدر:</td><td><?= e($payment['father_name'] ?: '-') ?></td></tr>
        <tr><td class="bg-gray-100 font-bold">کلاس:</td><td><?= e($payment['class_name']) ?></td></tr>
        <tr><td class="bg-gray-100 font-bold">مبلغ:</td><td><?= format_money($payment['amount'], $unit) ?></td></tr>
        <tr><td class="bg-gray-100 font-bold">نوع پرداخت:</td><td><?= $method_fa[$payment['payment_method']] ?? $payment['payment_method'] ?></td></tr>
        <?php if ($payment['card_last4']): ?><tr><td class="bg-gray-100 font-bold">۴ رقم آخر کارت:</td><td><?= $payment['card_last4'] ?></td></tr><?php endif; ?>
        </table>
    </div>
    <?php else: ?>
    <p class="text-sm">کد ملی: <?= $student['national_id'] ?> | دانش‌آموز: <?= e($student['first_name'].' '.$student['last_name']) ?> | نام پدر: <?= e($student['father_name'] ?: '-') ?> | کلاس: <?= e($student['class_name']) ?></p>
    <div class="overflow-x-auto">
        <table class="w-full border-collapse">
        <tr class="bg-gray-100"><td>تاریخ</td><td>مبلغ</td><td>نوع</td></tr>
        <?php foreach ($payments as $p): ?>
        <tr><td><?= gregorian_to_jalali($p['payment_date']) ?></td><td><?= format_money($p['amount'], $unit) ?></td><td><?= $method_fa[$p['payment_method']] ?? $p['payment_method'] ?></td></tr>
        <?php endforeach; ?>
        <tr><td><b>جمع کل</b></td><td><b><?= format_money($total_paid, $unit) ?></b></td></tr>
        </table>
    </div>
    <?php endif; ?>
<?php elseif ($template === 'official'): ?>
    <div class="border-2 border-indigo-500 p-4 sm:p-6 max-w-full sm:max-w-2xl mx-auto">
        <div class="text-center border-b-2 border-indigo-500 pb-2 mb-4">
            <h2 class="text-indigo-600"><?= e($school_name) ?></h2>
            <p>فاکتور رسمی پرداخت شهریه</p>
        </div>
        <?php if ($is_single): ?>
        <div class="overflow-x-auto">
            <table class="w-full border-collapse">
                <tr><td class="font-bold w-1/3">شماره فاکتور:</td><td><?= $payment_id ?></td></tr>
                <tr><td class="font-bold">تاریخ:</td><td><?= gregorian_to_jalali($payment['payment_date']) ?></td></tr>
                <tr><td class="font-bold">کد ملی:</td><td><?= $payment['national_id'] ?></td></tr>
                <tr><td class="font-bold">نام دانش‌آموز:</td><td><?= e($payment['first_name'].' '.$payment['last_name']) ?></td></tr>
                <tr><td class="font-bold">نام پدر:</td><td><?= e($payment['father_name'] ?: '-') ?></td></tr>
                <tr><td class="font-bold">کلاس:</td><td><?= e($payment['class_name']) ?></td></tr>
            </table>
        </div>
        <br>
        <div class="overflow-x-auto">
            <table class="w-full border-collapse border-t-2 border-indigo-500">
                <tr><td class="font-bold w-1/3">مبلغ پرداختی:</td><td><?= format_money($payment['amount'], $unit) ?></td></tr>
                <tr><td class="font-bold">نوع پرداخت:</td><td><?= $method_fa[$payment['payment_method']] ?? $payment['payment_method'] ?></td></tr>
                <?php if ($payment['card_last4']): ?><tr><td class="font-bold">۴ رقم آخر کارت:</td><td><?= $payment['card_last4'] ?></td></tr><?php endif; ?>
            </table>
        </div>
        <?php else: ?>
        <p class="text-sm">کد ملی: <?= $student['national_id'] ?> | دانش‌آموز: <?= e($student['first_name'].' '.$student['last_name']) ?> | نام پدر: <?= e($student['father_name'] ?: '-') ?> | کلاس: <?= e($student['class_name']) ?></p>
        <div class="overflow-x-auto">
            <table class="w-full border-collapse mt-3">
                <tr class="bg-gray-100"><th>تاریخ</th><th>مبلغ</th><th>نوع</th></tr>
                <?php foreach ($payments as $p): ?>
                <tr><td><?= gregorian_to_jalali($p['payment_date']) ?></td><td><?= format_money($p['amount'], $unit) ?></td><td><?= $method_fa[$p['payment_method']] ?? $p['payment_method'] ?></td></tr>
                <?php endforeach; ?>
                <tr><td><b>جمع کل</b></td><td><b><?= format_money($total_paid, $unit) ?></b></td></tr>
            </table>
        </div>
        <?php endif; ?>
    </div>
<?php else: // modern ?>
    <div class="w-full max-w-full sm:max-w-md mx-auto bg-white rounded-2xl shadow-lg overflow-hidden">
        <div class="bg-gradient-to-r from-indigo-600 to-indigo-700 text-white p-4 sm:p-5 text-center">
            <h2 class="text-xl sm:text-2xl font-bold">رسید پرداخت</h2>
            <p class="text-sm sm:text-base mt-1"><?= e($school_name) ?></p>
        </div>
        <div class="p-4 sm:p-5">
        <?php if ($is_single): ?>
            <div class="flex justify-between border-b border-dashed py-1"><span>شماره رسید:</span><span><?= $payment_id ?></span></div>
            <div class="flex justify-between border-b border-dashed py-1"><span>تاریخ:</span><span><?= gregorian_to_jalali($payment['payment_date']) ?></span></div>
            <div class="flex justify-between border-b border-dashed py-1"><span>کد ملی:</span><span><?= $payment['national_id'] ?></span></div>
            <div class="flex justify-between border-b border-dashed py-1"><span>دانش‌آموز:</span><span><?= e($payment['first_name'].' '.$payment['last_name']) ?></span></div>
            <div class="flex justify-between border-b border-dashed py-1"><span>نام پدر:</span><span><?= e($payment['father_name'] ?: '-') ?></span></div>
            <div class="flex justify-between border-b border-dashed py-1"><span>کلاس:</span><span><?= e($payment['class_name']) ?></span></div>
            <div class="flex justify-between border-b border-dashed py-1"><span>مبلغ:</span><span><?= format_money($payment['amount'], $unit) ?></span></div>
            <div class="flex justify-between border-b border-dashed py-1"><span>نوع پرداخت:</span><span><?= $method_fa[$payment['payment_method']] ?? $payment['payment_method'] ?></span></div>
            <?php if ($payment['card_last4']): ?><div class="flex justify-between border-b border-dashed py-1"><span>۴ رقم آخر کارت:</span><span><?= $payment['card_last4'] ?></span></div><?php endif; ?>
        <?php else: ?>
            <div class="flex justify-between border-b border-dashed py-1"><span>کد ملی:</span><span><?= $student['national_id'] ?></span></div>
            <div class="flex justify-between border-b border-dashed py-1"><span>دانش‌آموز:</span><span><?= e($student['first_name'].' '.$student['last_name']) ?></span></div>
            <div class="flex justify-between border-b border-dashed py-1"><span>نام پدر:</span><span><?= e($student['father_name'] ?: '-') ?></span></div>
            <div class="flex justify-between border-b border-dashed py-1"><span>کلاس:</span><span><?= e($student['class_name']) ?></span></div>
            <?php foreach ($payments as $p): ?>
            <div class="flex justify-between border-b border-dashed py-1"><span><?= gregorian_to_jalali($p['payment_date']) ?> - <?= $method_fa[$p['payment_method']] ?? $p['payment_method'] ?></span><span><?= format_money($p['amount'], $unit) ?></span></div>
            <?php endforeach; ?>
            <div class="flex justify-between py-1 font-bold"><span>جمع کل</span><span><?= format_money($total_paid, $unit) ?></span></div>
        <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
</div>
</body>
</html>
<?php
$html = ob_get_clean();

// ========== خروجی PDF ==========
if ($download) {
    $mpdf_ttfonts = ROOT_PATH . '/vendor/mpdf/mpdf/ttfonts/';
    $font_regular = $mpdf_ttfonts . 'Vazirmatn-Regular.ttf';
    $font_bold    = $mpdf_ttfonts . 'Vazirmatn-Bold.ttf';

    if (!file_exists($font_regular)) {
        copy(ROOT_PATH . '/assets/fonts/Vazirmatn-Regular.ttf', $font_regular);
    }
    if (!file_exists($font_bold)) {
        copy(ROOT_PATH . '/assets/fonts/Vazirmatn-Bold.ttf', $font_bold);
    }

    $mpdf = new Mpdf([
        'mode' => 'utf-8',
        'format' => 'A5',
        'default_font' => 'Vazirmatn',
    ]);
    $mpdf->SetDirectionality('rtl');
    $mpdf->autoScriptToLang = true;
    $mpdf->autoLangToFont = true;

    $mpdf->fontdata['Vazirmatn'] = [
        'R' => $font_regular,
        'B' => $font_bold,
    ];
    $mpdf->available_unifonts[] = 'Vazirmatn';

    $mpdf->WriteHTML($html);
    $filename = $is_single ? "receipt_$payment_id.pdf" : "receipt_" . $student_id . ".pdf";
    $mpdf->Output($filename, 'D');
    exit;
}

// ========== نمایش در مرورگر ==========
$page_title = $is_single ? "رسید پرداخت #$payment_id" : "رسید تجمعی " . e($student['first_name'] . ' ' . $student['last_name']);
include INCLUDES_PATH . '/header.php';
?>
<div class="max-w-3xl mx-auto">
    <div class="flex flex-col sm:flex-row justify-between mb-6 gap-4">
        <h1 class="text-2xl font-extrabold">رسید پرداخت</h1>
        <div class="flex gap-2 no-print flex-wrap">
            <a href="?<?= $is_single ? "id=$payment_id" : "student_id=$student_id&fiscal=$fiscal" ?>&template=<?= $template ?>&pdf=1" class="px-4 py-2 bg-indigo-600 text-white rounded-xl text-sm">دانلود PDF</a>
            <button onclick="window.print()" class="px-4 py-2 bg-slate-100 rounded-xl text-sm">چاپ</button>
        </div>
    </div>
    <div class="flex flex-wrap gap-2 mb-4 no-print">
        <a href="?<?= $is_single ? "id=$payment_id" : "student_id=$student_id&fiscal=$fiscal" ?>&template=modern" class="px-4 py-2 rounded-xl text-sm <?= $template=='modern' ? 'bg-indigo-600 text-white' : 'bg-slate-100' ?>">مدرن</a>
        <a href="?<?= $is_single ? "id=$payment_id" : "student_id=$student_id&fiscal=$fiscal" ?>&template=classic" class="px-4 py-2 rounded-xl text-sm <?= $template=='classic' ? 'bg-indigo-600 text-white' : 'bg-slate-100' ?>">کلاسیک</a>
        <a href="?<?= $is_single ? "id=$payment_id" : "student_id=$student_id&fiscal=$fiscal" ?>&template=official" class="px-4 py-2 rounded-xl text-sm <?= $template=='official' ? 'bg-indigo-600 text-white' : 'bg-slate-100' ?>">رسمی</a>
        <a href="?<?= $is_single ? "id=$payment_id" : "student_id=$student_id&fiscal=$fiscal" ?>&template=minimal" class="px-4 py-2 rounded-xl text-sm <?= $template=='minimal' ? 'bg-indigo-600 text-white' : 'bg-slate-100' ?>">ساده</a>
    </div>
    <div class="bg-white rounded-2xl p-4 sm:p-6 shadow">
        <?= $html ?>
    </div>
</div>
<?php include INCLUDES_PATH . '/footer.php'; ?>