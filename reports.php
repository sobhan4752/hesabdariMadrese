<?php
/**
 * گزارش‌های حسابداری – نسخه ۲.۲ با پایگاه داده سال جاری
 * مسیر: /reports.php
 */
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/report_functions.php';
require_once ROOT_PATH . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use Mpdf\Mpdf;

$page_title = 'گزارش‌ها';
$errors = [];

$fiscal_years = $pdo_master->query("SELECT * FROM fiscal_years ORDER BY id DESC")->fetchAll();
$active_year_id = get_active_fiscal_year_id();
if (!$active_year_id && !empty($fiscal_years)) $active_year_id = $fiscal_years[0]['id'];

$selected_fiscal = (int)($_GET['fiscal'] ?? $active_year_id);
$report = $_GET['report'] ?? 'ledger';
$export = $_GET['export'] ?? null;

$date_from = $_GET['date_from'] ?? '';
$date_to   = $_GET['date_to'] ?? '';
$method    = $_GET['method'] ?? '';
$class     = $_GET['class'] ?? '';
$search    = $_GET['search'] ?? '';

$greg_from = $date_from ? jalali_to_gregorian($date_from) : null;
$greg_to   = $date_to   ? jalali_to_gregorian($date_to)   : null;

$unit = get_setting('currency_unit', 'rial');
$school_name = get_setting('school_name', 'دبیرستان نمونه');
$method_fa = ['cash' => 'نقدی', 'card' => 'کارت‌خوان', 'transfer' => 'انتقال وجه'];

$report_labels = [
    'ledger'          => 'دفتر روزنامه',
    'income'          => 'خلاصه درآمد',
    'debtors'         => 'بدهکاران',
    'settled'         => 'تسویه‌شده',
    'discounts_report'=> 'تخفیف‌ها',
    'discrepancy'     => 'مغایرت',
    'comparison'      => 'مقایسه دوسال',
    'ledger_book'     => 'دفتر شهریه',
    'overpayments'    => 'اضافه واریزی‌ها'
];

// ======================== خروجی‌ها ========================
if (in_array($export, ['csv','xlsx','pdf'])) {
    $rows = [];
    $headers = [];
    switch ($report) {
        case 'ledger':
            $headers = ['کد ملی','نام','کلاس','مبلغ (ریال)','نوع','تاریخ','توضیحات'];
            $where = []; $params = [];
            apply_filters($where, $params, $selected_fiscal, $greg_from, $greg_to, $method, $class, $search);
            $sql = "SELECT p.*, s.first_name, s.last_name, s.class_name FROM payments p JOIN students s ON p.student_id = s.national_id WHERE " . implode(' AND ', $where) . " ORDER BY p.payment_date DESC, p.id DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            while ($r = $stmt->fetch()) {
                $rows[] = [$r['student_id'], $r['first_name'].' '.$r['last_name'], $r['class_name'], $r['amount'], $method_fa[$r['payment_method']] ?? $r['payment_method'], gregorian_to_jalali($r['payment_date']), $r['notes']];
            }
            break;
        case 'income':
            $headers = ['روش/کلاس','مبلغ'];
            $stmt = $pdo->prepare("SELECT payment_method, SUM(amount) as total FROM payments WHERE fiscal_year_id = ? GROUP BY payment_method");
            $stmt->execute([$selected_fiscal]);
            while ($r = $stmt->fetch()) $rows[] = [$method_fa[$r['payment_method']] ?? $r['payment_method'], $r['total']];
            $rows[] = ['', ''];
            $stmt = $pdo->prepare("SELECT s.class_name, SUM(p.amount) as total FROM payments p JOIN students s ON p.student_id = s.national_id WHERE p.fiscal_year_id = ? GROUP BY s.class_name ORDER BY s.class_name");
            $stmt->execute([$selected_fiscal]);
            while ($r = $stmt->fetch()) $rows[] = [$r['class_name'], $r['total']];
            break;
        case 'debtors':
            $headers = ['نام','کلاس','کد ملی','بدهی'];
            $data = get_students_with_balances($selected_fiscal);
            foreach ($data['students'] as $s) {
                $bal = $data['balances'][$s['national_id']] ?? ['balance'=>0];
                if ($bal['balance'] > 0) $rows[] = [$s['first_name'].' '.$s['last_name'], $s['class_name'], $s['national_id'], $bal['balance']];
            }
            break;
        case 'settled':
            $headers = ['نام','کلاس','کد ملی','مانده'];
            $data = get_students_with_balances($selected_fiscal);
            foreach ($data['students'] as $s) {
                $bal = $data['balances'][$s['national_id']] ?? ['balance'=>0];
                if ($bal['balance'] <= 0) $rows[] = [$s['first_name'].' '.$s['last_name'], $s['class_name'], $s['national_id'], abs($bal['balance']) . ($bal['balance']<0?' (بستانکار)':'')];
            }
            break;
        case 'discounts_report':
            $headers = ['دانش‌آموز','کلاس','مبلغ','تاریخ','توضیحات'];
            $stmt = $pdo->prepare("SELECT d.*, s.first_name, s.last_name, s.class_name FROM student_discounts d JOIN students s ON d.student_id = s.national_id WHERE d.fiscal_year_id = ? ORDER BY d.id DESC");
            $stmt->execute([$selected_fiscal]);
            while ($d = $stmt->fetch()) {
                $rows[] = [$d['first_name'].' '.$d['last_name'], $d['class_name'], $d['amount'], gregorian_to_jalali(substr($d['created_at'],0,10)), $d['description']];
            }
            break;
        case 'discrepancy':
            $headers = ['شاخص','مقدار'];
            $total_tuition = get_tuition($selected_fiscal);
            $student_count = $pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();
            $expected = $total_tuition * $student_count;
            $discounts_total = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM student_discounts WHERE fiscal_year_id = $selected_fiscal")->fetchColumn();
            $payments_total = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE fiscal_year_id = $selected_fiscal")->fetchColumn();
            $gap = $expected - $discounts_total - $payments_total;
            $rows = [
                ['تعداد دانش‌آموزان', $student_count],
                ['شهریه هر نفر', $total_tuition],
                ['کل شهریه مصوب', $expected],
                ['مجموع تخفیف‌ها', $discounts_total],
                ['پرداختی‌ها', $payments_total],
                ['مغایرت', $gap]
            ];
            break;
        case 'comparison':
            $headers = ['سال','مبلغ'];
            $years = $pdo_master->query("SELECT id, name FROM fiscal_years ORDER BY id DESC LIMIT 2")->fetchAll();
            foreach ($years as $y) {
                $stmt = $pdo->prepare("SELECT SUM(amount) FROM payments WHERE fiscal_year_id = ?");
                $stmt->execute([$y['id']]);
                $rows[] = [$y['name'], $stmt->fetchColumn() ?: 0];
            }
            break;
        case 'ledger_book':
            $headers = ['شماره دفتر','نام','کلاس','پرداختی'];
            $stmt = $pdo->query("SELECT s.*, COALESCE(SUM(p.amount),0) as total_paid FROM students s LEFT JOIN payments p ON s.national_id = p.student_id AND p.fiscal_year_id = $selected_fiscal GROUP BY s.national_id ORDER BY s.last_name");
            while ($s = $stmt->fetch()) {
                $rows[] = [$s['ledger_number'] ?: '-', $s['first_name'].' '.$s['last_name'], $s['class_name'], $s['total_paid']];
            }
            break;
        case 'overpayments':
            $headers = ['نام','کلاس','کد ملی','اضافه پرداخت'];
            $data = get_students_with_balances($selected_fiscal);
            foreach ($data['students'] as $s) {
                $bal = $data['balances'][$s['national_id']] ?? ['balance'=>0];
                if ($bal['balance'] < 0) {
                    $rows[] = [$s['first_name'].' '.$s['last_name'], $s['class_name'], $s['national_id'], abs($bal['balance'])];
                }
            }
            break;
    }

    // CSV
    if ($export === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="report_' . $report . '.csv"');
        $fp = fopen('php://output', 'w');
        fprintf($fp, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($fp, $headers);
        foreach ($rows as $row) fputcsv($fp, $row);
        fclose($fp);
        exit;
    }

    // Excel
    if ($export === 'xlsx') {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setRightToLeft(true);
        $col = 1;
        foreach ($headers as $h) {
            $cell = $sheet->getCellByColumnAndRow($col, 1);
            $cell->setValue($h);
            $cell->getStyle()->getFont()->setBold(true);
            $cell->getStyle()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $cell->getStyle()->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            $col++;
        }
        $rowNum = 2;
        foreach ($rows as $row) {
            $col = 1;
            foreach ($row as $value) {
                $cell = $sheet->getCellByColumnAndRow($col, $rowNum);
                if (in_array($report, ['ledger','income','debtors','settled','discounts_report','ledger_book','overpayments']) && $col === count($headers) && is_numeric($value)) {
                    $cell->setValueExplicit((int)$value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
                    $cell->getStyle()->getNumberFormat()->setFormatCode('#,##0 "ریال"');
                } else {
                    $cell->setValue($value);
                }
                $cell->getStyle()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $cell->getStyle()->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
                $col++;
            }
            $rowNum++;
        }
        foreach (range(1, count($headers)) as $col) {
            $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
        }
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="report_' . $report . '.xlsx"');
        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }

    // PDF (mPDF)
    if ($export === 'pdf') {
        $mpdf_ttfonts = ROOT_PATH . '/vendor/mpdf/mpdf/ttfonts/';
        $font_regular = $mpdf_ttfonts . 'Vazirmatn-Regular.ttf';
        $font_bold    = $mpdf_ttfonts . 'Vazirmatn-Bold.ttf';
        if (!file_exists($font_regular)) copy(ROOT_PATH . '/assets/fonts/Vazirmatn-Regular.ttf', $font_regular);
        if (!file_exists($font_bold))    copy(ROOT_PATH . '/assets/fonts/Vazirmatn-Bold.ttf', $font_bold);

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4-L',
            'default_font' => 'Vazirmatn',
        ]);
        $mpdf->SetDirectionality('rtl');
        $mpdf->autoScriptToLang = true;
        $mpdf->autoLangToFont = true;
        $mpdf->fontdata['Vazirmatn'] = ['R' => $font_regular, 'B' => $font_bold];
        $mpdf->available_unifonts[] = 'Vazirmatn';

        $html = '<h2 style="text-align:center;">' . e($school_name) . ' – گزارش ' . $report_labels[$report] . '</h2>';
        $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width:100%; border-collapse:collapse;">';
        $html .= '<thead><tr style="background-color:#4f46e5; color:white;">';
        foreach ($headers as $h) $html .= '<th>' . e($h) . '</th>';
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) $html .= '<td>' . e($cell) . '</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';

        $mpdf->WriteHTML($html);
        $mpdf->Output("report_$report.pdf", 'D');
        exit;
    }
}

// ======================== HTML ========================
include INCLUDES_PATH . '/header.php';
?>
<div class="max-w-7xl mx-auto">
    <div class="mb-8">
        <h1 class="text-3xl font-extrabold text-slate-800">گزارش‌ها</h1>
        <p class="text-slate-500 mt-2">گزارش‌های حسابداری و حسابرسی با خروجی CSV، Excel و PDF</p>
    </div>
    <?php if ($errors): ?><div class="bg-red-50 border border-red-200 rounded-xl p-4 mb-6"><?php foreach ($errors as $e): ?><p class="text-red-800 text-sm"><?= e($e) ?></p><?php endforeach; ?></div><?php endif; ?>

    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <div class="flex gap-1 bg-slate-100 rounded-xl p-1 overflow-x-auto flex-nowrap">
            <?php foreach ($report_labels as $key => $label): ?>
                <a href="?report=<?= $key ?>&fiscal=<?= $selected_fiscal ?>" class="px-4 py-2 rounded-lg text-sm font-medium transition-all whitespace-nowrap <?= $report === $key ? 'bg-white shadow text-indigo-700' : 'text-slate-600 hover:text-slate-800' ?>"><?= $label ?></a>
            <?php endforeach; ?>
        </div>
        <form class="flex items-center gap-2" method="get">
            <input type="hidden" name="report" value="<?= $report ?>">
            <select name="fiscal" onchange="this.form.submit()" class="py-2 px-3 bg-white border border-slate-200 rounded-lg text-sm focus:ring-2 focus:ring-indigo-200 focus:border-indigo-400 outline-none">
                <?php foreach ($fiscal_years as $fy): ?>
                    <option value="<?= $fy['id'] ?>" <?= $fy['id'] == $selected_fiscal ? 'selected' : '' ?>><?= e($fy['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <?php if ($report === 'ledger'):
        $where = []; $params = [];
        apply_filters($where, $params, $selected_fiscal, $greg_from, $greg_to, $method, $class, $search);
        $sql = "SELECT p.*, s.first_name, s.last_name, s.class_name FROM payments p JOIN students s ON p.student_id = s.national_id WHERE " . implode(' AND ', $where) . " ORDER BY p.payment_date DESC, p.id DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
    ?>
        <div class="bg-white rounded-2xl border border-slate-200 p-5 mb-6">
            <form method="get" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                <input type="hidden" name="report" value="ledger"><input type="hidden" name="fiscal" value="<?= $selected_fiscal ?>">
                <input type="text" name="date_from" class="datepicker-jalali p-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm" placeholder="از تاریخ" value="<?= e($date_from) ?>" readonly>
                <input type="text" name="date_to" class="datepicker-jalali p-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm" placeholder="تا تاریخ" value="<?= e($date_to) ?>" readonly>
                <select name="method" class="p-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm"><option value="">همه روش‌ها</option><option value="cash" <?= $method==='cash'?'selected':'' ?>>نقدی</option><option value="card" <?= $method==='card'?'selected':'' ?>>کارت‌خوان</option><option value="transfer" <?= $method==='transfer'?'selected':'' ?>>انتقال</option></select>
                <input type="text" name="search" value="<?= e($search) ?>" placeholder="جستجو..." class="p-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm">
                <div class="flex gap-2">
                    <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-xl btn-pulse">اعمال</button>
                    <a href="?report=ledger&fiscal=<?= $selected_fiscal ?>&export=csv&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>&method=<?= $method ?>&search=<?= urlencode($search) ?>" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-sm font-medium rounded-xl btn-pulse">CSV</a>
                    <a href="?report=ledger&fiscal=<?= $selected_fiscal ?>&export=xlsx&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>&method=<?= $method ?>&search=<?= urlencode($search) ?>" class="px-4 py-2 bg-green-100 hover:bg-green-200 text-green-700 text-sm font-medium rounded-xl btn-pulse">Excel</a>
                    <a href="?report=ledger&fiscal=<?= $selected_fiscal ?>&export=pdf&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>&method=<?= $method ?>&search=<?= urlencode($search) ?>" class="px-4 py-2 bg-red-100 hover:bg-red-200 text-red-700 text-sm font-medium rounded-xl btn-pulse">PDF</a>
                </div>
            </form>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50"><tr><th class="px-4 py-3">کد ملی</th><th class="px-4 py-3">نام</th><th class="px-4 py-3">کلاس</th><th class="px-4 py-3">مبلغ</th><th class="px-4 py-3">نوع</th><th class="px-4 py-3">تاریخ</th><th class="px-4 py-3">توضیحات</th></tr></thead>
                    <tbody>
                        <?php if (empty($rows)): ?><tr><td colspan="7" class="text-center py-10 text-slate-400">هیچ پرداختی یافت نشد.</td></tr>
                        <?php else: foreach ($rows as $r): ?>
                        <tr class="border-b hover:bg-slate-50"><td class="px-4 py-3 font-mono"><?= e($r['student_id']) ?></td><td class="px-4 py-3"><?= e($r['first_name'].' '.$r['last_name']) ?></td><td class="px-4 py-3"><?= e($r['class_name']) ?></td><td class="px-4 py-3"><?= format_money($r['amount'], $unit) ?></td><td class="px-4 py-3"><?= $method_fa[$r['payment_method']] ?? $r['payment_method'] ?></td><td class="px-4 py-3"><?= e(gregorian_to_jalali($r['payment_date'])) ?></td><td class="px-4 py-3 text-xs"><?= e($r['notes'] ?: '-') ?></td></tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php elseif ($report === 'income'):
        $stmt = $pdo->prepare("SELECT payment_method, SUM(amount) as total FROM payments WHERE fiscal_year_id = ? GROUP BY payment_method"); $stmt->execute([$selected_fiscal]); $income_by_method = $stmt->fetchAll();
        $stmt = $pdo->prepare("SELECT s.class_name, SUM(p.amount) as total FROM payments p JOIN students s ON p.student_id = s.national_id WHERE p.fiscal_year_id = ? GROUP BY s.class_name ORDER BY s.class_name"); $stmt->execute([$selected_fiscal]); $income_by_class = $stmt->fetchAll();
    ?>
        <div class="flex gap-2 mb-4"><a href="?report=income&fiscal=<?= $selected_fiscal ?>&export=csv" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-sm font-medium rounded-xl btn-pulse">CSV</a><a href="?report=income&fiscal=<?= $selected_fiscal ?>&export=xlsx" class="px-4 py-2 bg-green-100 hover:bg-green-200 text-green-700 text-sm font-medium rounded-xl btn-pulse">Excel</a><a href="?report=income&fiscal=<?= $selected_fiscal ?>&export=pdf" class="px-4 py-2 bg-red-100 hover:bg-red-200 text-red-700 text-sm font-medium rounded-xl btn-pulse">PDF</a></div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div class="bg-white rounded-2xl border border-slate-200 p-5"><h3 class="font-bold mb-4">درآمد به تفکیک روش</h3><table class="w-full text-sm"><?php foreach ($income_by_method as $im): ?><tr class="border-b"><td class="px-4 py-2"><?= $method_fa[$im['payment_method']] ?? $im['payment_method'] ?></td><td class="px-4 py-2"><?= format_money($im['total'], $unit) ?></td></tr><?php endforeach; ?></table></div>
            <div class="bg-white rounded-2xl border border-slate-200 p-5"><h3 class="font-bold mb-4">درآمد به تفکیک کلاس</h3><table class="w-full text-sm"><?php foreach ($income_by_class as $ic): ?><tr class="border-b"><td class="px-4 py-2"><?= $ic['class_name'] ?></td><td class="px-4 py-2"><?= format_money($ic['total'], $unit) ?></td></tr><?php endforeach; ?></table></div>
        </div>
    <?php elseif ($report === 'debtors'):
        $data = get_students_with_balances($selected_fiscal); $debtors = [];
        foreach ($data['students'] as $s) { $bal = $data['balances'][$s['national_id']] ?? ['balance'=>0]; if ($bal['balance'] > 0) { $s['balance'] = $bal['balance']; $debtors[] = $s; } }
        usort($debtors, fn($a,$b) => $b['balance'] - $a['balance']);
    ?>
        <div class="flex gap-2 mb-4"><a href="?report=debtors&fiscal=<?= $selected_fiscal ?>&export=csv" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-sm font-medium rounded-xl btn-pulse">CSV</a><a href="?report=debtors&fiscal=<?= $selected_fiscal ?>&export=xlsx" class="px-4 py-2 bg-green-100 hover:bg-green-200 text-green-700 text-sm font-medium rounded-xl btn-pulse">Excel</a><a href="?report=debtors&fiscal=<?= $selected_fiscal ?>&export=pdf" class="px-4 py-2 bg-red-100 hover:bg-red-200 text-red-700 text-sm font-medium rounded-xl btn-pulse">PDF</a></div>
        <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden"><div class="overflow-x-auto"><table class="w-full text-sm"><thead class="bg-slate-50"><tr><th class="px-4 py-3">نام</th><th class="px-4 py-3">کلاس</th><th class="px-4 py-3">کد ملی</th><th class="px-4 py-3">بدهی</th></tr></thead><tbody><?php foreach ($debtors as $d): ?><tr class="border-b hover:bg-slate-50"><td class="px-4 py-3"><?= e($d['first_name'].' '.$d['last_name']) ?></td><td class="px-4 py-3"><?= e($d['class_name']) ?></td><td class="px-4 py-3 font-mono"><?= e($d['national_id']) ?></td><td class="px-4 py-3 font-bold text-red-600"><?= format_money($d['balance'], $unit) ?></td></tr><?php endforeach; ?></tbody></table></div></div>
    <?php elseif ($report === 'settled'):
        $data = get_students_with_balances($selected_fiscal); $settled = [];
        foreach ($data['students'] as $s) { $bal = $data['balances'][$s['national_id']] ?? ['balance'=>0]; if ($bal['balance'] <= 0) { $s['balance'] = $bal['balance']; $settled[] = $s; } }
    ?>
        <div class="flex gap-2 mb-4"><a href="?report=settled&fiscal=<?= $selected_fiscal ?>&export=csv" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-sm font-medium rounded-xl btn-pulse">CSV</a><a href="?report=settled&fiscal=<?= $selected_fiscal ?>&export=xlsx" class="px-4 py-2 bg-green-100 hover:bg-green-200 text-green-700 text-sm font-medium rounded-xl btn-pulse">Excel</a><a href="?report=settled&fiscal=<?= $selected_fiscal ?>&export=pdf" class="px-4 py-2 bg-red-100 hover:bg-red-200 text-red-700 text-sm font-medium rounded-xl btn-pulse">PDF</a></div>
        <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden"><div class="overflow-x-auto"><table class="w-full text-sm"><thead class="bg-slate-50"><tr><th class="px-4 py-3">نام</th><th class="px-4 py-3">کلاس</th><th class="px-4 py-3">کد ملی</th><th class="px-4 py-3">مانده</th></tr></thead><tbody><?php foreach ($settled as $s): ?><tr class="border-b hover:bg-slate-50"><td class="px-4 py-3"><?= e($s['first_name'].' '.$s['last_name']) ?></td><td class="px-4 py-3"><?= e($s['class_name']) ?></td><td class="px-4 py-3 font-mono"><?= e($s['national_id']) ?></td><td class="px-4 py-3 font-bold text-emerald-600"><?= format_money(abs($s['balance']), $unit) ?> <?= $s['balance'] < 0 ? '(بستانکار)' : '' ?></td></tr><?php endforeach; ?></tbody></table></div></div>
    <?php elseif ($report === 'discounts_report'):
        $discounts = $pdo->prepare("SELECT d.*, s.first_name, s.last_name, s.class_name FROM student_discounts d JOIN students s ON d.student_id = s.national_id WHERE d.fiscal_year_id = ? ORDER BY d.id DESC"); $discounts->execute([$selected_fiscal]); $discounts = $discounts->fetchAll();
    ?>
        <div class="flex gap-2 mb-4"><a href="?report=discounts_report&fiscal=<?= $selected_fiscal ?>&export=csv" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-sm font-medium rounded-xl btn-pulse">CSV</a><a href="?report=discounts_report&fiscal=<?= $selected_fiscal ?>&export=xlsx" class="px-4 py-2 bg-green-100 hover:bg-green-200 text-green-700 text-sm font-medium rounded-xl btn-pulse">Excel</a><a href="?report=discounts_report&fiscal=<?= $selected_fiscal ?>&export=pdf" class="px-4 py-2 bg-red-100 hover:bg-red-200 text-red-700 text-sm font-medium rounded-xl btn-pulse">PDF</a></div>
        <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden"><div class="overflow-x-auto"><table class="w-full text-sm"><thead class="bg-slate-50"><tr><th class="px-4 py-3">دانش‌آموز</th><th class="px-4 py-3">کلاس</th><th class="px-4 py-3">مبلغ</th><th class="px-4 py-3">تاریخ</th><th class="px-4 py-3">توضیحات</th></tr></thead><tbody><?php foreach ($discounts as $d): ?><tr class="border-b hover:bg-slate-50"><td class="px-4 py-3"><?= e($d['first_name'].' '.$d['last_name']) ?></td><td class="px-4 py-3"><?= e($d['class_name']) ?></td><td class="px-4 py-3"><?= format_money($d['amount'], $unit) ?></td><td class="px-4 py-3"><?= e(gregorian_to_jalali(substr($d['created_at'],0,10))) ?></td><td class="px-4 py-3 text-xs"><?= e($d['description'] ?: '-') ?></td></tr><?php endforeach; ?></tbody></table></div></div>
    <?php elseif ($report === 'discrepancy'):
        $total_tuition = get_tuition($selected_fiscal);
        $student_count = $pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();
        $expected = $total_tuition * $student_count;
        $discounts_total = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM student_discounts WHERE fiscal_year_id = $selected_fiscal")->fetchColumn();
        $payments_total = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE fiscal_year_id = $selected_fiscal")->fetchColumn();
        $expected_after = $expected - $discounts_total;
        $gap = $expected_after - $payments_total;
    ?>
        <div class="flex gap-2 mb-4"><a href="?report=discrepancy&fiscal=<?= $selected_fiscal ?>&export=csv" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-sm font-medium rounded-xl btn-pulse">CSV</a><a href="?report=discrepancy&fiscal=<?= $selected_fiscal ?>&export=xlsx" class="px-4 py-2 bg-green-100 hover:bg-green-200 text-green-700 text-sm font-medium rounded-xl btn-pulse">Excel</a><a href="?report=discrepancy&fiscal=<?= $selected_fiscal ?>&export=pdf" class="px-4 py-2 bg-red-100 hover:bg-red-200 text-red-700 text-sm font-medium rounded-xl btn-pulse">PDF</a></div>
        <div class="bg-white rounded-2xl border border-slate-200 p-6 space-y-3">
            <div class="flex justify-between"><span>تعداد دانش‌آموزان:</span><span class="font-bold"><?= number_format($student_count) ?></span></div>
            <div class="flex justify-between"><span>شهریه هر نفر:</span><span><?= format_money($total_tuition, $unit) ?></span></div>
            <div class="flex justify-between"><span>کل شهریه مصوب:</span><span><?= format_money($expected, $unit) ?></span></div>
            <div class="flex justify-between"><span>مجموع تخفیف‌ها:</span><span class="text-violet-600"><?= format_money($discounts_total, $unit) ?></span></div>
            <div class="flex justify-between"><span>مبلغ قابل وصول:</span><span><?= format_money($expected_after, $unit) ?></span></div>
            <div class="flex justify-between"><span>پرداختی‌ها:</span><span class="text-emerald-600"><?= format_money($payments_total, $unit) ?></span></div>
            <div class="flex justify-between text-lg font-bold"><span>مغایرت:</span><span class="<?= $gap > 0 ? 'text-red-600' : 'text-emerald-600' ?>"><?= format_money(abs($gap), $unit) ?></span></div>
        </div>
    <?php elseif ($report === 'comparison'):
        $years = $pdo_master->query("SELECT id, name FROM fiscal_years ORDER BY id DESC LIMIT 2")->fetchAll();
        if (count($years) < 2) echo '<p class="text-slate-500">حداقل دو سال مالی نیاز است.</p>';
        else { $y1 = $years[0]; $y2 = $years[1]; $stmt = $pdo->prepare("SELECT SUM(amount) FROM payments WHERE fiscal_year_id = ?"); $stmt->execute([$y1['id']]); $sum1 = $stmt->fetchColumn() ?: 0; $stmt->execute([$y2['id']]); $sum2 = $stmt->fetchColumn() ?: 0; ?>
        <div class="flex gap-2 mb-4"><a href="?report=comparison&fiscal=<?= $selected_fiscal ?>&export=csv" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-sm font-medium rounded-xl btn-pulse">CSV</a><a href="?report=comparison&fiscal=<?= $selected_fiscal ?>&export=xlsx" class="px-4 py-2 bg-green-100 hover:bg-green-200 text-green-700 text-sm font-medium rounded-xl btn-pulse">Excel</a><a href="?report=comparison&fiscal=<?= $selected_fiscal ?>&export=pdf" class="px-4 py-2 bg-red-100 hover:bg-red-200 text-red-700 text-sm font-medium rounded-xl btn-pulse">PDF</a></div>
        <div class="bg-white rounded-2xl border border-slate-200 p-6"><div class="flex justify-between"><span><?= e($y1['name']) ?>:</span><span><?= format_money($sum1, $unit) ?></span></div><div class="flex justify-between"><span><?= e($y2['name']) ?>:</span><span><?= format_money($sum2, $unit) ?></span></div><div class="flex justify-between font-bold mt-2"><span>تفاوت:</span><span><?= format_money($sum1 - $sum2, $unit) ?></span></div></div>
        <?php } ?>
    <?php elseif ($report === 'ledger_book'):
        $students = $pdo->query("SELECT s.*, COALESCE(SUM(p.amount),0) as total_paid FROM students s LEFT JOIN payments p ON s.national_id = p.student_id AND p.fiscal_year_id = $selected_fiscal GROUP BY s.national_id ORDER BY s.last_name")->fetchAll();
    ?>
        <div class="flex gap-2 mb-4"><a href="?report=ledger_book&fiscal=<?= $selected_fiscal ?>&export=csv" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-sm font-medium rounded-xl btn-pulse">CSV</a><a href="?report=ledger_book&fiscal=<?= $selected_fiscal ?>&export=xlsx" class="px-4 py-2 bg-green-100 hover:bg-green-200 text-green-700 text-sm font-medium rounded-xl btn-pulse">Excel</a><a href="?report=ledger_book&fiscal=<?= $selected_fiscal ?>&export=pdf" class="px-4 py-2 bg-red-100 hover:bg-red-200 text-red-700 text-sm font-medium rounded-xl btn-pulse">PDF</a></div>
        <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden"><div class="overflow-x-auto"><table class="w-full text-sm"><thead class="bg-slate-50"><tr><th class="px-4 py-3">شماره دفتر</th><th class="px-4 py-3">نام</th><th class="px-4 py-3">کلاس</th><th class="px-4 py-3">پرداختی</th></tr></thead><tbody><?php foreach ($students as $s): ?><tr class="border-b"><td class="px-4 py-3"><?= e($s['ledger_number'] ?: '-') ?></td><td class="px-4 py-3"><?= e($s['first_name'].' '.$s['last_name']) ?></td><td class="px-4 py-3"><?= e($s['class_name']) ?></td><td class="px-4 py-3"><?= format_money($s['total_paid'], $unit) ?></td></tr><?php endforeach; ?></tbody></table></div></div>
    <?php elseif ($report === 'overpayments'):
        $data = get_students_with_balances($selected_fiscal);
        $overpayers = [];
        foreach ($data['students'] as $s) {
            $bal = $data['balances'][$s['national_id']] ?? ['balance'=>0];
            if ($bal['balance'] < 0) {
                $s['overpay'] = abs($bal['balance']);
                $overpayers[] = $s;
            }
        }
        usort($overpayers, fn($a,$b) => $b['overpay'] - $a['overpay']);
    ?>
        <div class="flex gap-2 mb-4">
            <a href="?report=overpayments&fiscal=<?= $selected_fiscal ?>&export=csv" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-sm font-medium rounded-xl btn-pulse">CSV</a>
            <a href="?report=overpayments&fiscal=<?= $selected_fiscal ?>&export=xlsx" class="px-4 py-2 bg-green-100 hover:bg-green-200 text-green-700 text-sm font-medium rounded-xl btn-pulse">Excel</a>
            <a href="?report=overpayments&fiscal=<?= $selected_fiscal ?>&export=pdf" class="px-4 py-2 bg-red-100 hover:bg-red-200 text-red-700 text-sm font-medium rounded-xl btn-pulse">PDF</a>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50"><tr><th class="px-4 py-3">نام</th><th class="px-4 py-3">کلاس</th><th class="px-4 py-3">کد ملی</th><th class="px-4 py-3">اضافه پرداخت</th></tr></thead>
                    <tbody>
                        <?php foreach ($overpayers as $op): ?>
                            <tr class="border-b hover:bg-slate-50">
                                <td class="px-4 py-3"><?= e($op['first_name'].' '.$op['last_name']) ?></td>
                                <td class="px-4 py-3"><?= e($op['class_name']) ?></td>
                                <td class="px-4 py-3 font-mono"><?= e($op['national_id']) ?></td>
                                <td class="px-4 py-3 font-bold text-blue-600"><?= format_money($op['overpay'], $unit) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php include INCLUDES_PATH . '/footer.php'; ?>