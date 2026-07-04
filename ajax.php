<?php
/**
 * پاسخ‌دهنده AJAX – نسخهٔ نهایی پایدار
 * مسیر: /ajax.php
 */
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/functions.php';

while (ob_get_level()) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {

        case 'search_student':
            $term = mb_trim($_GET['term'] ?? '');
            if (mb_strlen($term) < 2) { echo json_encode([]); exit; }
            $like = "%$term%";
            $stmt = $pdo->prepare("SELECT national_id, first_name, last_name, class_name, ledger_number, mobile FROM students WHERE first_name LIKE ? OR last_name LIKE ? OR national_id LIKE ? ORDER BY last_name LIMIT 50");
            $stmt->execute([$like, $like, $like]);
            echo json_encode($stmt->fetchAll());
            break;

        case 'delete_payment':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['error' => 'متد نامعتبر']); exit; }
            $payment_id = (int)($_POST['id'] ?? 0);
            $student_id = $_POST['student_id'] ?? '';
            if ($payment_id < 1 || empty($student_id)) { echo json_encode(['error' => 'پارامترها نامعتبر']); exit; }
            $stmt = $pdo->prepare("SELECT id FROM payments WHERE id = ? AND student_id = ?");
            $stmt->execute([$payment_id, $student_id]);
            if (!$stmt->fetch()) { echo json_encode(['error' => 'پرداخت یافت نشد.']); exit; }
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("INSERT INTO audit_log (table_name, record_id, action, old_data) VALUES (?, ?, ?, ?)");
                $stmt->execute(['payments', $payment_id, 'DELETE', json_encode(['deleted_payment_id' => $payment_id], JSON_UNESCAPED_UNICODE)]);
                // دریافت fiscal_year_id برای بی‌اعتبارسازی کش
               $fy_stmt = $pdo->prepare("SELECT fiscal_year_id FROM payments WHERE id = ?");
               $fy_stmt->execute([$payment_id]);
               $fy_row = $fy_stmt->fetch();
                $payment_fiscal_year = $fy_row ? (int)$fy_row['fiscal_year_id'] : 0;
                $stmt = $pdo->prepare("DELETE FROM payments WHERE id = ?");
                $stmt->execute([$payment_id]);
                $pdo->commit();
                if ($payment_fiscal_year > 0) {
    invalidate_student_balances_cache($payment_fiscal_year);
}
                echo json_encode(['success' => true, 'message' => 'پرداخت با موفقیت حذف شد.']);
            } catch (\Exception $e) {
                $pdo->rollBack();
                echo json_encode(['error' => 'خطا در حذف: ' . $e->getMessage()]);
            }
            break;

        case 'get_balance':
            $student_id = $_GET['student_id'] ?? '';
            $fiscal_year_id = (int)($_GET['fiscal_year_id'] ?? 0);
            if (empty($student_id) || $fiscal_year_id < 1) { echo json_encode(['error'=>'پارامترها نامعتبر']); exit; }
            $tuition = get_tuition($fiscal_year_id);
            $discount = get_total_discount($student_id, $fiscal_year_id);
            $payments = get_total_payments($student_id, $fiscal_year_id);
            $balance = $tuition - $discount - $payments;
            $unit = get_setting('currency_unit', 'rial');
            echo json_encode([
                'tuition'                => $tuition,
                'discount'               => $discount,
                'payments'               => $payments,
                'balance'                => $balance,
                'tuition_formatted'      => format_money($tuition, $unit),
                'discount_formatted'     => format_money($discount, $unit),
                'payments_formatted'     => format_money($payments, $unit),
                'balance_formatted'      => format_money(abs($balance), $unit) . ($balance >= 0 ? ' بدهکار' : ' بستانکار'),
                'tuition_after_formatted'=> format_money($tuition - $discount, $unit),
                'status'                 => $balance > 0 ? 'بدهکار' : ($balance < 0 ? 'بستانکار' : 'تسویه'),
                'unit'                   => $unit,
            ]);
            break;

        case 'get_previous_balance':
            $student_id = $_GET['student_id'] ?? '';
            if (empty($student_id)) { echo json_encode(['balance' => 0, 'balance_formatted' => '']); exit; }
            $prev_balance = get_previous_year_balance($student_id);
            $unit = get_setting('currency_unit', 'rial');
            echo json_encode([
                'balance'           => $prev_balance ?? 0,
                'balance_formatted' => $prev_balance !== null ? format_money(abs($prev_balance), $unit) . ($prev_balance >= 0 ? ' بدهکار' : ' بستانکار') : '',
                'has_previous'      => $prev_balance !== null && $prev_balance > 0
            ]);
            break;

        case 'convert_words':
            $amount = (int)($_GET['amount'] ?? 0);
            $unit = $_GET['unit'] ?? 'rial';
            if (!in_array($unit, ['rial', 'toman'])) $unit = 'rial';
            echo json_encode(['words' => number_to_words($amount, $unit)]);
            break;

        case 'get_tuition_info':
            $fiscal_year_id = (int)($_GET['fiscal_year_id'] ?? 0);
            if ($fiscal_year_id < 1) { echo json_encode(['error'=>'سال مالی نامعتبر']); exit; }
            $stmt = $pdo_master->prepare("SELECT * FROM fiscal_years WHERE id = ?");
            $stmt->execute([$fiscal_year_id]);
            $year = $stmt->fetch();
            if (!$year) { echo json_encode(['error'=>'سال مالی یافت نشد']); exit; }
            echo json_encode(['tuition'=>$year['tuition_amount'],'year_name'=>$year['name'],'is_closed'=>(bool)$year['is_closed']]);
            break;

        case 'update_ledger':
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['error'=>'متد نامعتبر']); exit; }
    $student_id = $_POST['student_id'] ?? '';
    $ledger = trim($_POST['ledger'] ?? '');
    if (empty($student_id) || strlen($student_id) !== 10) { echo json_encode(['error'=>'کد ملی نامعتبر']); exit; }

    // بررسی تکراری نبودن شماره دفترچه
    if ($ledger !== '') {
        $check = $pdo->prepare("SELECT COUNT(*) FROM students WHERE ledger_number = ? AND national_id != ?");
        $check->execute([$ledger, $student_id]);
        if ($check->fetchColumn() > 0) {
            echo json_encode(['error' => 'این شماره دفترچه قبلاً برای دانش‌آموز دیگری ثبت شده است.']);
            exit;
        }
    }

    $stmt = $pdo->prepare("UPDATE students SET ledger_number = ? WHERE national_id = ?");
    $stmt->execute([$ledger, $student_id]);
    echo json_encode(['success' => true]);
    break;

        case 'quick_settle':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['error' => 'متد نامعتبر']); exit; }
            $student_id = $_POST['student_id'] ?? '';
            $fiscal_year_id = (int)($_POST['fiscal_year_id'] ?? 0);
            if (empty($student_id) || strlen($student_id) !== 10 || $fiscal_year_id < 1) {
                echo json_encode(['error' => 'پارامترها نامعتبر']); exit;
            }
            $balance = calculate_balance($student_id, $fiscal_year_id);
            if ($balance <= 0) {
                echo json_encode(['error' => 'دانش‌آموز بدهی ندارد یا قبلاً تسویه شده است.']); exit;
            }
            $today_jalali = gregorian_to_jalali(date('Y-m-d'));
            $today_greg = jalali_to_gregorian($today_jalali);
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("INSERT INTO payments (student_id, fiscal_year_id, amount, payment_method, card_last4, payment_date, notes) VALUES (?, ?, ?, 'cash', NULL, ?, 'تسویه آنی')");
                $stmt->execute([$student_id, $fiscal_year_id, $balance, $today_greg]);
                $stmt = $pdo->prepare("INSERT INTO audit_log (table_name, record_id, action, new_data) VALUES (?, ?, ?, ?)");
                $stmt->execute(['payments', $pdo->lastInsertId(), 'INSERT', json_encode(['student_id' => $student_id, 'amount' => $balance, 'method' => 'cash', 'date' => $today_greg], JSON_UNESCAPED_UNICODE)]);
                $pdo->commit();
                invalidate_student_balances_cache($fiscal_year_id);
                echo json_encode(['success' => true, 'message' => 'تسویه آنی با موفقیت انجام شد.']);
            } catch (\Exception $e) {
                $pdo->rollBack();
                echo json_encode(['error' => 'خطا در ثبت: ' . $e->getMessage()]);
            }
            break;

        case 'get_students_list':
            $search       = trim($_GET['search'] ?? '');
            $class_filter = trim($_GET['class'] ?? '');
            $grade_filter = trim($_GET['grade'] ?? '');
            $hide_settled = ($_GET['hide_settled'] ?? '') === '1';
            $hide_small_debtors = ($_GET['hide_small_debtors'] ?? '') === '1';
            $hide_with_payments = ($_GET['hide_with_payments'] ?? '') === '1';
            $hide_small_debt_threshold = 4_000_000;

            $sort_by      = $_GET['sort'] ?? 'last_name';
            $sort_order   = strtolower($_GET['order'] ?? 'asc');
            $allowed_sorts = ['last_name', 'first_name', 'class_name', 'national_id', 'ledger_number', 'balance', 'grade'];
            if (!in_array($sort_by, $allowed_sorts)) $sort_by = 'last_name';
            $sort_order = $sort_order === 'desc' ? 'DESC' : 'ASC';

            $active_year_id = get_active_fiscal_year_id();
            $unit = get_setting('currency_unit', 'rial');
            $per_page_options = [10, 20, 50, 100];
            $per_page = (int)($_GET['per_page'] ?? 20);
            if (!in_array($per_page, $per_page_options)) $per_page = 20;

            $threshold_rials = ($unit === 'toman') ? $hide_small_debt_threshold * 10 : $hide_small_debt_threshold;
            $grade_map = json_decode(get_setting('class_grade_mapping', '{}'), true) ?: [];

            if ($active_year_id > 0) {
                $data = get_students_with_balances_cached($active_year_id);
                $students = $data['students'];
                $balances = $data['balances'];

                foreach ($students as &$s) $s['grade'] = $grade_map[$s['class_name']] ?? 'نامشخص';
                unset($s);

                if ($search !== '') {
                    $normalized_search = normalize_search_term($search);
                    $students = array_filter($students, function($s) use ($normalized_search, $search) {
                        $first = normalize_search_term($s['first_name']);
                        $last  = normalize_search_term($s['last_name']);
                        $nid   = $s['national_id'];
                        return mb_stripos($first, $normalized_search) !== false ||
                               mb_stripos($last, $normalized_search) !== false ||
                               mb_stripos($nid, $search) !== false;
                    });
                }
                if ($class_filter !== '') {
                    $students = array_filter($students, function($s) use ($class_filter) {
                        return $s['class_name'] === $class_filter;
                    });
                }
                if ($grade_filter !== '') {
                    $students = array_filter($students, function($s) use ($grade_map, $grade_filter) {
                        return ($grade_map[$s['class_name']] ?? '') === $grade_filter;
                    });
                }
                if ($hide_settled) {
                    $students = array_filter($students, function($s) use ($balances) {
                        return ($balances[$s['national_id']]['balance'] ?? 0) != 0;
                    });
                }
                if ($hide_small_debtors) {
                    $students = array_filter($students, function($s) use ($balances, $threshold_rials) {
                        $bal = $balances[$s['national_id']]['balance'] ?? 0;
                        return !($bal != 0 && abs($bal) < $threshold_rials);
                    });
                }
                if ($hide_with_payments) {
                    $students = array_filter($students, function($s) use ($active_year_id) {
                        return get_total_payments($s['national_id'], $active_year_id) == 0;
                    });
                }

                if (!function_exists('persian_alphabet_order')) {
                    function persian_alphabet_order($str) {
                        $alphabet = ['ا'=>1,'آ'=>1,'ب'=>2,'پ'=>3,'ت'=>4,'ث'=>5,'ج'=>6,'چ'=>7,'ح'=>8,'خ'=>9,'د'=>10,'ذ'=>11,'ر'=>12,'ز'=>13,'ژ'=>14,'س'=>15,'ش'=>16,'ص'=>17,'ض'=>18,'ط'=>19,'ظ'=>20,'ع'=>21,'غ'=>22,'ف'=>23,'ق'=>24,'ک'=>25,'گ'=>26,'ل'=>27,'م'=>28,'ن'=>29,'و'=>30,'ه'=>31,'ی'=>32];
                        $key = '';
                        $len = mb_strlen($str, 'UTF-8');
                        for ($i = 0; $i < $len; $i++) {
                            $char = mb_substr($str, $i, 1, 'UTF-8');
                            $key .= isset($alphabet[$char]) ? str_pad($alphabet[$char], 3, '0', STR_PAD_LEFT) : '999';
                        }
                        return $key;
                    }
                }

                if ($sort_by === 'balance') {
                    usort($students, function($a, $b) use ($sort_order, $balances) {
                        $balA = $balances[$a['national_id']]['balance'] ?? 0;
                        $balB = $balances[$b['national_id']]['balance'] ?? 0;
                        return $sort_order === 'DESC' ? $balB - $balA : $balA - $balB;
                    });
                } elseif ($sort_by === 'grade') {
                    usort($students, function($a, $b) use ($sort_order) {
                        $keyA = persian_alphabet_order($a['grade'] ?? '');
                        $keyB = persian_alphabet_order($b['grade'] ?? '');
                        return $sort_order === 'DESC' ? strcmp($keyB, $keyA) : strcmp($keyA, $keyB);
                    });
                } else {
                    usort($students, function($a, $b) use ($sort_by, $sort_order) {
                        $valA = $a[$sort_by] ?? '';
                        $valB = $b[$sort_by] ?? '';
                        $keyA = persian_alphabet_order($valA);
                        $keyB = persian_alphabet_order($valB);
                        return $sort_order === 'DESC' ? strcmp($keyB, $keyA) : strcmp($keyA, $keyB);
                    });
                }
                $students = array_values($students);

                $total_filtered = count($students);
                $total_pages = max(1, ceil($total_filtered / $per_page));
                $current_page = max(1, min((int)($_GET['page'] ?? 1), $total_pages));
                $students = array_slice($students, ($current_page - 1) * $per_page, $per_page);
                $students = array_values($students);
            } else {
                $students = []; $balances = []; $total_filtered = 0; $total_pages = 1; $current_page = 1;
            }

            // ساخت HTML جدول
            ob_start();
            if (empty($students)): ?>
                <tr><td colspan="13" class="text-center py-12 text-slate-400">هیچ دانش‌آموزی یافت نشد.</td></tr>
            <?php else: 
                $row_start = ($current_page - 1) * $per_page;
                foreach ($students as $index => $s):
                    $row_number = $row_start + $index + 1;
                    $bal = $balances[$s['national_id']] ?? ['discount'=>0,'paid'=>0,'balance'=>0]; ?>
                <tr class="border-b hover:bg-slate-50 table-row-hover">
                    <td class="px-2 py-3 text-center text-slate-400 text-xs"><?= $row_number ?></td>
                    <td class="px-4 py-3"><?= e($s['first_name']) ?></td>
                    <td class="px-4 py-3 font-medium"><?= e($s['last_name']) ?></td>
                    <td class="px-4 py-3"><?= e($s['class_name']) ?></td>
                    <td class="px-4 py-3"><?= e($s['grade']) ?></td>
                    <td class="px-4 py-3 font-mono" dir="ltr"><?= e($s['national_id']) ?></td>
                    <td class="px-4 py-3">
    <div class="ledger-cell flex items-center gap-1">
        <span class="ledger-display" id="ldisp-<?= $s['national_id'] ?>"><?= e($s['ledger_number'] ?: '-') ?></span>
        <input type="text" 
               class="ledger-input hidden w-20 p-1 border border-slate-300 rounded text-sm" 
               id="linput-<?= $s['national_id'] ?>" 
               value="<?= e($s['ledger_number']) ?>" 
               data-student-id="<?= $s['national_id'] ?>"
               onkeydown="if(event.key==='Enter') saveLedger('<?= $s['national_id'] ?>')">
        <button onclick="editLedger('<?= $s['national_id'] ?>')" 
                class="edit-btn text-indigo-600 hover:text-indigo-800 focus:outline-none" 
                title="ویرایش دفترچه">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
        </button>
        <button onclick="saveLedger('<?= $s['national_id'] ?>')" 
                class="save-btn hidden text-emerald-600 hover:text-emerald-800 focus:outline-none" 
                title="ذخیره">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
        </button>
        <button onclick="cancelEdit('<?= $s['national_id'] ?>')" 
                class="cancel-btn hidden text-slate-400 hover:text-slate-600 focus:outline-none" 
                title="لغو">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    </div>
</td>
                    <td class="px-4 py-3"><?= format_money($bal['discount'], $unit) ?></td>
                    <td class="px-4 py-3"><?= format_money($bal['paid'], $unit) ?></td>
                    <td class="px-4 py-3 font-bold <?= $bal['balance'] > 0 ? 'text-red-600' : ($bal['balance'] < 0 ? 'text-blue-600' : 'text-emerald-600') ?>"><?= format_money(abs($bal['balance']), $unit) ?></td>
                    <td class="px-4 py-3">
                        <?= $bal['balance'] > 0 ? '<span class="px-2.5 py-1 bg-red-50 text-red-700 rounded-full text-xs font-medium">بدهکار</span>' : ($bal['balance'] < 0 ? '<span class="px-2.5 py-1 bg-blue-50 text-blue-700 rounded-full text-xs font-medium">بستانکار</span>' : '<span class="px-2.5 py-1 bg-emerald-50 text-emerald-700 rounded-full text-xs font-medium">تسویه</span>') ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <div class="flex items-center justify-center gap-1">
                            <a href="student_card.php?id=<?= urlencode($s['national_id']) ?>" class="inline-flex items-center gap-1 px-3 py-1.5 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 rounded-lg text-xs font-medium transition-colors">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/></svg>
                                کارت حساب
                            </a>
                            <?php if ($bal['balance'] > 0): ?>
                                <button onclick="quickSettle('<?= e($s['national_id']) ?>')" class="inline-flex items-center gap-1 px-3 py-1.5 bg-amber-50 hover:bg-amber-100 text-amber-700 rounded-lg text-xs font-medium transition-colors" title="تسویه آنی">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z"/></svg>
                                </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; endif;
            $table_html = ob_get_clean();

            // صفحه‌بندی
            ob_start();
            if ($total_pages > 1 || count($per_page_options) > 0):
                $from = ($current_page - 1) * $per_page + 1;
                $to   = min($current_page * $per_page, $total_filtered);
            ?>
            <div class="mt-6 bg-white rounded-2xl border border-slate-200 p-4">
                <div class="flex flex-col sm:flex-row items-center justify-between gap-4">
                    <div class="text-sm text-slate-500">نمایش <?= $from ?> تا <?= $to ?> از <?= $total_filtered ?> دانش‌آموز</div>
                    <div class="flex items-center gap-1 flex-wrap">
                        <?php if ($current_page > 1): ?>
                            <a href="#" data-page="<?= $current_page - 1 ?>" class="page-link px-3 py-1.5 bg-slate-100 hover:bg-slate-200 rounded-lg text-sm">قبلی</a>
                        <?php endif; ?>
                        <?php for ($p = max(1, $current_page - 3); $p <= min($total_pages, $current_page + 3); $p++): ?>
                            <a href="#" data-page="<?= $p ?>" class="page-link px-3 py-1.5 rounded-lg text-sm <?= $p == $current_page ? 'bg-indigo-600 text-white' : 'bg-slate-100 hover:bg-slate-200' ?>"><?= $p ?></a>
                        <?php endfor; ?>
                        <?php if ($current_page < $total_pages): ?>
                            <a href="#" data-page="<?= $current_page + 1 ?>" class="page-link px-3 py-1.5 bg-slate-100 hover:bg-slate-200 rounded-lg text-sm">بعدی</a>
                        <?php endif; ?>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="text-sm text-slate-500">نمایش در هر صفحه:</span>
                        <select id="per_page_select" class="py-1.5 px-2 bg-slate-50 border border-slate-200 rounded-lg text-sm">
                            <?php foreach ($per_page_options as $opt): ?>
                                <option value="<?= $opt ?>" <?= $opt == $per_page ? 'selected' : '' ?>><?= $opt ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            <?php endif;
            $pagination_html = ob_get_clean();

            echo json_encode([
                'table'      => $table_html,
                'pagination' => $pagination_html,
                'total'      => $total_filtered,
                'page'       => $current_page,
                'pages'      => $total_pages
            ]);
            break;

        case 'export_card_csv':
            $student_id = $_GET['student_id'] ?? '';
            $fiscal_year_id = (int)($_GET['fiscal'] ?? 0);
            if (empty($student_id) || $fiscal_year_id < 1) { http_response_code(400); echo json_encode(['error'=>'پارامتر نامعتبر']); exit; }
            ob_start();
            try {
                $stmt = $pdo->prepare("SELECT * FROM students WHERE national_id = ?");
                $stmt->execute([$student_id]);
                $student = $stmt->fetch();
                if (!$student) { ob_end_clean(); http_response_code(404); exit('دانش‌آموز یافت نشد'); }

                $fp = fopen('php://temp', 'r+');
                fprintf($fp, chr(0xEF).chr(0xBB).chr(0xBF));
                fputcsv($fp, ['نام', $student['first_name'].' '.$student['last_name']]);
                fputcsv($fp, ['کد ملی', $student['national_id']]);
                fputcsv($fp, ['کلاس', $student['class_name']]);
                fputcsv($fp, ['شماره دفتر', $student['ledger_number'] ?: '-']);
                fputcsv($fp, []);

                $tuition = get_tuition($fiscal_year_id);
                $discount = get_total_discount($student_id, $fiscal_year_id);
                $payments = get_total_payments($student_id, $fiscal_year_id);
                $balance = $tuition - $discount - $payments;

                fputcsv($fp, ['شهریه پایه', number_format($tuition).' ریال']);
                fputcsv($fp, ['مجموع تخفیف', number_format($discount).' ریال']);
                fputcsv($fp, ['پرداختی', number_format($payments).' ریال']);
                fputcsv($fp, ['مانده', number_format(abs($balance)).' ریال '.($balance>=0?'(بدهکار)':'(بستانکار)')]);
                fputcsv($fp, []);

                fputcsv($fp, ['ریز پرداخت‌ها']);
                fputcsv($fp, ['تاریخ','مبلغ','نوع','کارت','توضیحات']);
                $pStmt = $pdo->prepare("SELECT * FROM payments WHERE student_id=? AND fiscal_year_id=? ORDER BY payment_date DESC");
                $pStmt->execute([$student_id, $fiscal_year_id]);
                while ($p = $pStmt->fetch()) {
                    fputcsv($fp, [gregorian_to_jalali($p['payment_date']), number_format($p['amount']), $p['payment_method'], $p['card_last4']?:'-', $p['notes']?:'-']);
                }
                fputcsv($fp, []);
                fputcsv($fp, ['ریز تخفیف‌ها']);
                fputcsv($fp, ['تاریخ','مبلغ','توضیحات']);
                $dStmt = $pdo->prepare("SELECT * FROM student_discounts WHERE student_id=? AND fiscal_year_id=? ORDER BY id DESC");
                $dStmt->execute([$student_id, $fiscal_year_id]);
                while ($d = $dStmt->fetch()) {
                    fputcsv($fp, [gregorian_to_jalali(substr($d['created_at'],0,10)), number_format($d['amount']), $d['description']?:'-']);
                }
                rewind($fp);
                $csv = stream_get_contents($fp);
                fclose($fp);
                ob_end_clean();
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="card_'.$student_id.'.csv"');
                echo $csv;
                exit;
            } catch (\Exception $e) { ob_end_clean(); http_response_code(500); exit('خطا'); }

        case 'export_card_excel':
            $student_id = $_GET['student_id'] ?? '';
            $fiscal_year_id = (int)($_GET['fiscal'] ?? 0);
            if (empty($student_id) || $fiscal_year_id < 1) { http_response_code(400); echo json_encode(['error'=>'پارامتر نامعتبر']); exit; }
            require_once ROOT_PATH . '/vendor/autoload.php';
            $method_fa = ['cash' => 'نقدی', 'card' => 'کارت‌خوان', 'transfer' => 'انتقال وجه'];
            $unit = get_setting('currency_unit', 'rial');

            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setRightToLeft(true);

            $stmt = $pdo->prepare("SELECT * FROM students WHERE national_id = ?");
            $stmt->execute([$student_id]);
            $student = $stmt->fetch();
            if (!$student) { http_response_code(404); exit; }

            $tuition = get_tuition($fiscal_year_id);
            $discount = get_total_discount($student_id, $fiscal_year_id);
            $payments = get_total_payments($student_id, $fiscal_year_id);
            $balance = $tuition - $discount - $payments;

            $sheet->setCellValue('A1', 'نام'); $sheet->setCellValue('B1', $student['first_name'].' '.$student['last_name']);
            $sheet->setCellValue('A2', 'کد ملی'); $sheet->setCellValue('B2', $student['national_id']);
            $sheet->setCellValue('A3', 'کلاس'); $sheet->setCellValue('B3', $student['class_name']);
            $sheet->setCellValue('A4', 'شماره دفتر'); $sheet->setCellValue('B4', $student['ledger_number'] ?: '-');
            $sheet->setCellValue('A5', 'شهریه پایه'); $sheet->setCellValue('B5', number_format($tuition).' ریال');
            $sheet->setCellValue('A6', 'مجموع تخفیف'); $sheet->setCellValue('B6', number_format($discount).' ریال');
            $sheet->setCellValue('A7', 'پرداختی'); $sheet->setCellValue('B7', number_format($payments).' ریال');
            $sheet->setCellValue('A8', 'مانده'); $sheet->setCellValue('B8', number_format(abs($balance)).' ریال '.($balance>=0?'(بدهکار)':'(بستانکار)'));

            $styleArray = [
                'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER],
                'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN]]
            ];
            $sheet->getStyle('A1:B8')->applyFromArray($styleArray);

            $sheet->setCellValue('A10', 'ریز پرداخت‌ها');
            $headers = ['تاریخ','مبلغ','نوع','کارت','توضیحات'];
            $col = 1;
            foreach ($headers as $h) { $sheet->setCellValueByColumnAndRow($col, 11, $h); $col++; }
            $pStmt = $pdo->prepare("SELECT * FROM payments WHERE student_id=? AND fiscal_year_id=? ORDER BY payment_date DESC");
            $pStmt->execute([$student_id, $fiscal_year_id]);
            $rowNum = 12;
            while ($p = $pStmt->fetch()) {
                $sheet->setCellValueByColumnAndRow(1, $rowNum, gregorian_to_jalali($p['payment_date']));
                $sheet->setCellValueByColumnAndRow(2, $rowNum, number_format($p['amount']).' ریال');
                $sheet->setCellValueByColumnAndRow(3, $rowNum, $method_fa[$p['payment_method']] ?? $p['payment_method']);
                $sheet->setCellValueByColumnAndRow(4, $rowNum, $p['card_last4'] ?: '-');
                $sheet->setCellValueByColumnAndRow(5, $rowNum, $p['notes'] ?: '-');
                $rowNum++;
            }
            $sheet->getStyle('A10:E'.($rowNum-1))->applyFromArray($styleArray);

            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="card_'.$student_id.'.xlsx"');
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save('php://output');
            exit;

        default:
            echo json_encode(['error' => 'action نامشخص']);
    }
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'خطای سرور: ' . $e->getMessage()]);
}