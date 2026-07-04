<?php
/**
 * لیست دانش‌آموزان – نسخهٔ نهایی ساده و ضدخطا
 * مسیر: /students.php
 */
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/functions.php';

$page_title = 'دانش‌آموزان';

$search       = trim($_GET['search'] ?? '');
$class_filter = trim($_GET['class'] ?? '');
$grade_filter = trim($_GET['grade'] ?? '');

$hide_settled           = isset($_COOKIE['hide_settled']) && in_array($_COOKIE['hide_settled'], ['1','true','on'], true);
$hide_small_debtors     = isset($_COOKIE['hide_small_debtors']) && in_array($_COOKIE['hide_small_debtors'], ['1','true','on'], true);
$hide_with_payments     = isset($_COOKIE['hide_with_payments']) && in_array($_COOKIE['hide_with_payments'], ['1','true','on'], true);
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

// تابع مرتب‌سازی فارسی
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

// تابع normalize_search_term
if (!function_exists('normalize_search_term')) {
    function normalize_search_term($str) {
        return str_replace(['ی', 'ک'], ['ي', 'ك'], $str);
    }
}

// دریافت داده‌ها
$students_all = [];
$balances_all = [];
$total_all_students = 0;
$settled_count = 0;
$unsettled_count = 0;

if ($active_year_id > 0) {
    $data = get_students_with_balances_cached($active_year_id);
    $students_all = $data['students'];
    $balances_all = $data['balances'];
    $total_all_students = count($students_all);
    foreach ($balances_all as $bal) {
        if ($bal['balance'] <= 0) $settled_count++;
          else $unsettled_count++;
    }
    foreach ($students_all as &$s) {
        $s['grade'] = $grade_map[$s['class_name']] ?? 'نامشخص';
    }
    unset($s);
}

// فیلترها (با حلقهٔ ساده)
$filtered = [];
foreach ($students_all as $s) {
    $bid = $s['national_id'];
    $bal = $balances_all[$bid] ?? ['discount'=>0,'paid'=>0,'balance'=>0];

    // جستجو
    if ($search !== '') {
        $ns = normalize_search_term($search);
        $fn = normalize_search_term($s['first_name']);
        $ln = normalize_search_term($s['last_name']);
        if (mb_stripos($fn, $ns) === false && mb_stripos($ln, $ns) === false && mb_stripos($bid, $search) === false) {
            continue;
        }
    }
    // کلاس
    if ($class_filter !== '' && $s['class_name'] !== $class_filter) continue;
    // پایه
    if ($grade_filter !== '' && ($grade_map[$s['class_name']] ?? '') !== $grade_filter) continue;
    // تسویه‌شده
    if ($hide_settled && $bal['balance'] == 0) continue;
    // بدهکاران کوچک
    if ($hide_small_debtors) {
        if ($bal['balance'] != 0 && abs($bal['balance']) < $threshold_rials) continue;
    }
    // دارای پرداخت
    if ($hide_with_payments) {
        $total_payments = get_total_payments($bid, $active_year_id);
        if ($total_payments > 0) continue;
    }

    // محاسبهٔ پایه برای مرتب‌سازی
    $item = $s;
    $item['balance'] = $bal['balance'];
    $item['discount'] = $bal['discount'];
    $item['paid'] = $bal['paid'];
    $filtered[] = $item;
}

// مرتب‌سازی
if ($sort_by === 'balance') {
    usort($filtered, function($a, $b) use ($sort_order) {
        return $sort_order === 'DESC' ? $b['balance'] - $a['balance'] : $a['balance'] - $b['balance'];
    });
} elseif ($sort_by === 'grade') {
    usort($filtered, function($a, $b) use ($sort_order) {
        $keyA = persian_alphabet_order($a['grade'] ?? '');
        $keyB = persian_alphabet_order($b['grade'] ?? '');
        return $sort_order === 'DESC' ? strcmp($keyB, $keyA) : strcmp($keyA, $keyB);
    });
} else {
    usort($filtered, function($a, $b) use ($sort_by, $sort_order) {
        $valA = $a[$sort_by] ?? '';
        $valB = $b[$sort_by] ?? '';
        $keyA = persian_alphabet_order($valA);
        $keyB = persian_alphabet_order($valB);
        return $sort_order === 'DESC' ? strcmp($keyB, $keyA) : strcmp($keyA, $keyB);
    });
}

$total_filtered = count($filtered);
$total_pages    = max(1, ceil($total_filtered / $per_page));
$current_page   = max(1, min((int)($_GET['page'] ?? 1), $total_pages));
$offset         = ($current_page - 1) * $per_page;
$students_page  = array_slice($filtered, $offset, $per_page);

$classes_raw = $pdo->query("SELECT DISTINCT class_name FROM students ORDER BY class_name ASC")->fetchAll(PDO::FETCH_COLUMN);
$grades_raw  = array_unique(array_values($grade_map));
sort($grades_raw);

function sort_icon($col, $current, $order) {
    if ($col !== $current) return '';
    return $order === 'ASC'
        ? '<svg class="w-4 h-4 inline-block mr-1 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/></svg>'
        : '<svg class="w-4 h-4 inline-block mr-1 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>';
}

function sort_link($col, $current, $order, $search, $class_filter, $grade_filter, $per_page) {
    $new = ($col === $current && $order === 'ASC') ? 'desc' : 'asc';
    $params = ['sort'=>$col,'order'=>$new,'search'=>$search,'class'=>$class_filter,'grade'=>$grade_filter,'per_page'=>$per_page];
    return '?' . http_build_query($params);
}

$paginationHtml = '';
if ($total_pages > 1 || count($per_page_options) > 0) {
    $from = $offset + 1;
    $to   = min($offset + $per_page, $total_filtered);
    $paginationHtml .= '<div id="paginationContainer" class="mt-6 bg-white rounded-2xl border border-slate-200 p-4"><div class="flex flex-col sm:flex-row items-center justify-between gap-4">';
    $paginationHtml .= '<div class="text-sm text-slate-500">نمایش ' . $from . ' تا ' . $to . ' از ' . $total_filtered . ' دانش‌آموز</div>';
    $paginationHtml .= '<div class="flex items-center gap-1 flex-wrap">';
    if ($current_page > 1) $paginationHtml .= '<a href="#" data-page="' . ($current_page - 1) . '" class="page-link px-3 py-1.5 bg-slate-100 hover:bg-slate-200 rounded-lg text-sm">قبلی</a>';
    for ($p = max(1, $current_page - 3); $p <= min($total_pages, $current_page + 3); $p++) {
        $active = ($p == $current_page) ? 'bg-indigo-600 text-white' : 'bg-slate-100 hover:bg-slate-200';
        $paginationHtml .= '<a href="#" data-page="' . $p . '" class="page-link px-3 py-1.5 rounded-lg text-sm ' . $active . '">' . $p . '</a>';
    }
    if ($current_page < $total_pages) $paginationHtml .= '<a href="#" data-page="' . ($current_page + 1) . '" class="page-link px-3 py-1.5 bg-slate-100 hover:bg-slate-200 rounded-lg text-sm">بعدی</a>';
    $paginationHtml .= '</div><div class="flex items-center gap-2"><span class="text-sm text-slate-500">نمایش در هر صفحه:</span><select id="per_page_select" class="py-1.5 px-2 bg-slate-50 border border-slate-200 rounded-lg text-sm">';
    foreach ($per_page_options as $opt) $paginationHtml .= '<option value="' . $opt . '" ' . ($opt == $per_page ? 'selected' : '') . '>' . $opt . '</option>';
    $paginationHtml .= '</select></div></div></div>';
}

include INCLUDES_PATH . '/header.php';
?>

<div class="max-w-7xl mx-auto">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between mb-8 gap-4">
        <div>
            <h1 class="text-3xl font-extrabold text-slate-800">دانش‌آموزان</h1>
            <div class="flex flex-wrap gap-x-6 gap-y-1 text-sm text-slate-500 mt-1">
                <span>📋 تعداد کل: <span class="font-bold text-slate-700"><?= $total_all_students ?></span> نفر</span>
                <span>✅ تسویه شده: <span class="font-bold text-emerald-600"><?= $settled_count ?></span> نفر</span>
                <span>⚠️ تسویه نشده: <span class="font-bold text-red-600"><?= $unsettled_count ?></span> نفر</span>
            </div>
        </div>
        <a href="upload.php" class="inline-flex items-center gap-2 px-5 py-3 bg-indigo-600 hover:bg-indigo-700 text-white font-bold rounded-xl shadow-lg transition-all btn-pulse">
            بارگذاری اکسل
        </a>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 p-5 mb-6">
        <form id="filterForm" class="flex flex-col sm:flex-row gap-3 items-center">
            <input type="text" id="searchInput" value="<?= e($search) ?>" placeholder="جستجو..." class="w-full pl-4 pr-10 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm">
            <select id="classSelect" class="py-2.5 px-4 bg-slate-50 border border-slate-200 rounded-xl text-sm">
                <option value="">همه کلاس‌ها</option>
                <?php foreach ($classes_raw as $cls): ?>
                    <option value="<?= e($cls) ?>" <?= $class_filter === $cls ? 'selected' : '' ?>><?= e($cls) ?></option>
                <?php endforeach; ?>
            </select>
            <select id="gradeSelect" class="py-2.5 px-4 bg-slate-50 border border-slate-200 rounded-xl text-sm">
                <option value="">همه پایه‌ها</option>
                <?php foreach ($grades_raw as $gr): ?>
                    <option value="<?= e($gr) ?>" <?= $grade_filter === $gr ? 'selected' : '' ?>><?= e($gr) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="button" id="applyFilters" class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white font-medium rounded-xl btn-pulse">اعمال</button>
            <button type="button" id="clearFilters" class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 font-medium rounded-xl btn-pulse">پاک کردن</button>
        </form>
        <div class="flex flex-wrap items-center gap-4 mt-4">
            <label class="inline-flex items-center gap-2 px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm cursor-pointer">
                <input type="checkbox" id="hide_settled" <?= $hide_settled ? 'checked' : '' ?>>
                <span>مخفی‌سازی تسویه‌شده‌ها</span>
            </label>
            <label class="inline-flex items-center gap-2 px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm cursor-pointer">
                <input type="checkbox" id="hide_small_debtors" <?= $hide_small_debtors ? 'checked' : '' ?>>
                <span>مخفی‌سازی مانده‌های کمتر از <?= number_format($hide_small_debt_threshold) ?> <?= e($unit) ?></span>
            </label>
            <label class="inline-flex items-center gap-2 px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm cursor-pointer">
                <input type="checkbox" id="hide_with_payments" <?= $hide_with_payments ? 'checked' : '' ?>>
                <span>مخفی‌سازی دانش‌آموزان دارای پرداخت</span>
            </label>
        </div>
    </div>

    <div id="studentsContainer">
        <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden">
            <table class="w-full text-sm">
                <thead><tr class="bg-slate-50 border-b">
                    <th class="text-center px-2 py-3">#</th>
                    <th class="text-right px-4 py-3"><a href="<?= sort_link('first_name', $sort_by, $sort_order, $search, $class_filter, $grade_filter, $per_page) ?>" class="sort-link" data-sort="first_name">نام <?= sort_icon('first_name', $sort_by, $sort_order) ?></a></th>
                    <th class="text-right px-4 py-3"><a href="<?= sort_link('last_name', $sort_by, $sort_order, $search, $class_filter, $grade_filter, $per_page) ?>" class="sort-link" data-sort="last_name">نام خانوادگی <?= sort_icon('last_name', $sort_by, $sort_order) ?></a></th>
                    <th class="text-right px-4 py-3"><a href="<?= sort_link('class_name', $sort_by, $sort_order, $search, $class_filter, $grade_filter, $per_page) ?>" class="sort-link" data-sort="class_name">کلاس <?= sort_icon('class_name', $sort_by, $sort_order) ?></a></th>
                    <th class="text-right px-4 py-3"><a href="<?= sort_link('grade', $sort_by, $sort_order, $search, $class_filter, $grade_filter, $per_page) ?>" class="sort-link" data-sort="grade">پایه <?= sort_icon('grade', $sort_by, $sort_order) ?></a></th>
                    <th class="text-right px-4 py-3"><a href="<?= sort_link('national_id', $sort_by, $sort_order, $search, $class_filter, $grade_filter, $per_page) ?>" class="sort-link" data-sort="national_id">کد ملی <?= sort_icon('national_id', $sort_by, $sort_order) ?></a></th>
                    <th class="text-right px-4 py-3"><a href="<?= sort_link('ledger_number', $sort_by, $sort_order, $search, $class_filter, $grade_filter, $per_page) ?>" class="sort-link" data-sort="ledger_number">دفترچه <?= sort_icon('ledger_number', $sort_by, $sort_order) ?></a></th>
                    <th class="text-right px-4 py-3">تخفیف</th>
                    <th class="text-right px-4 py-3">پرداختی</th>
                    <th class="text-right px-4 py-3"><a href="<?= sort_link('balance', $sort_by, $sort_order, $search, $class_filter, $grade_filter, $per_page) ?>" class="sort-link" data-sort="balance">مانده <?= sort_icon('balance', $sort_by, $sort_order) ?></a></th>
                    <th class="text-right px-4 py-3">وضعیت</th>
                    <th class="text-center px-4 py-3">عملیات</th>
                </tr></thead>
                <tbody id="studentsTableBody">
                    <?php if (empty($students_page)): ?>
                        <tr><td colspan="13" class="text-center py-12 text-slate-400">هیچ دانش‌آموزی یافت نشد.</td></tr>
                    <?php else:
                        $row_start = $offset;
                        foreach ($students_page as $index => $s):
                            $row_number = $row_start + $index + 1;
                            $bal_balance = $s['balance'];
                            $bal_discount = $s['discount'];
                            $bal_paid = $s['paid'];
                    ?>
                        <tr class="border-b hover:bg-slate-50">
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
                            <td class="px-4 py-3"><?= format_money($bal_discount, $unit) ?></td>
                            <td class="px-4 py-3"><?= format_money($bal_paid, $unit) ?></td>
                            <td class="px-4 py-3 font-bold <?= $bal_balance > 0 ? 'text-red-600' : ($bal_balance < 0 ? 'text-blue-600' : 'text-emerald-600') ?>"><?= format_money(abs($bal_balance), $unit) ?></td>
                            <td class="px-4 py-3">
                                <?php if ($bal_balance > 0): ?>
                                    <span class="px-2.5 py-1 bg-red-50 text-red-700 rounded-full text-xs font-medium">بدهکار</span>
                                <?php elseif ($bal_balance < 0): ?>
                                    <span class="px-2.5 py-1 bg-blue-50 text-blue-700 rounded-full text-xs font-medium">بستانکار</span>
                                <?php else: ?>
                                    <span class="px-2.5 py-1 bg-emerald-50 text-emerald-700 rounded-full text-xs font-medium">تسویه</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <a href="student_card.php?id=<?= urlencode($s['national_id']) ?>" class="inline-flex items-center gap-1 px-3 py-1.5 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 rounded-lg text-xs font-medium">کارت حساب</a>
                                <?php if ($bal_balance > 0): ?>
                                    <button onclick="quickSettle('<?= e($s['national_id']) ?>')" class="inline-flex items-center gap-1 px-3 py-1.5 bg-amber-50 hover:bg-amber-100 text-amber-700 rounded-lg text-xs font-medium">تسویه</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?= $paginationHtml ?>
    </div>
</div>

<script>
(function() {
    var state = {
        search: <?= json_encode($search, JSON_UNESCAPED_UNICODE) ?>,
        class: <?= json_encode($class_filter, JSON_UNESCAPED_UNICODE) ?>,
        grade: <?= json_encode($grade_filter, JSON_UNESCAPED_UNICODE) ?>,
        sort: <?= json_encode($sort_by, JSON_UNESCAPED_UNICODE) ?>,
        order: <?= json_encode($sort_order, JSON_UNESCAPED_UNICODE) ?>,
        page: <?= (int)$current_page ?>,
        per_page: <?= (int)$per_page ?>,
        hide_settled: <?= $hide_settled ? '1' : '0' ?>,
        hide_small_debtors: <?= $hide_small_debtors ? '1' : '0' ?>,
        hide_with_payments: <?= $hide_with_payments ? '1' : '0' ?>,
        active_year_id: <?= (int)$active_year_id ?>
    };

    function getEl(id) { return document.getElementById(id); }

    function ajaxGet(url, callback) {
        var xhr = new XMLHttpRequest();
        xhr.open('GET', url, true);
        xhr.onload = function() {
            if (xhr.status === 200) {
                try { callback(JSON.parse(xhr.responseText)); } catch(e) {}
            }
        };
        xhr.send();
    }

    function fetchStudents() {
        var qs = 'action=get_students_list' +
            '&search=' + encodeURIComponent(state.search) +
            '&class=' + encodeURIComponent(state.class) +
            '&grade=' + encodeURIComponent(state.grade) +
            '&sort=' + state.sort +
            '&order=' + state.order +
            '&page=' + state.page +
            '&per_page=' + state.per_page +
            '&hide_settled=' + state.hide_settled +
            '&hide_small_debtors=' + state.hide_small_debtors +
            '&hide_with_payments=' + state.hide_with_payments;

        ajaxGet('ajax.php?' + qs, function(data) {
            getEl('studentsTableBody').innerHTML = data.table;
            var pc = getEl('paginationContainer');
            if (pc && data.pagination) pc.innerHTML = data.pagination;
            else if (data.pagination) {
                var np = document.createElement('div');
                np.id = 'paginationContainer';
                np.className = 'mt-6 bg-white rounded-2xl border border-slate-200 p-4';
                np.innerHTML = data.pagination;
                getEl('studentsContainer').appendChild(np);
            } else { if (pc) pc.remove(); }
            attachPaginationEvents();
            attachSortEvents();
        });
    }

    function attachSortEvents() {
        var links = document.querySelectorAll('.sort-link');
        for (var i=0; i<links.length; i++) {
            links[i].onclick = function(e) {
                e.preventDefault();
                state.sort = this.getAttribute('data-sort');
                state.order = (state.order === 'ASC') ? 'DESC' : 'ASC';
                state.page = 1;
                fetchStudents();
            };
        }
    }

    function attachPaginationEvents() {
        var pageLinks = document.querySelectorAll('.page-link');
        for (var j=0; j<pageLinks.length; j++) {
            pageLinks[j].onclick = function(e) {
                e.preventDefault();
                var p = parseInt(this.getAttribute('data-page'));
                if (!isNaN(p)) { state.page = p; fetchStudents(); }
            };
        }
        var pps = getEl('per_page_select');
        if (pps) pps.onchange = function() { state.per_page = parseInt(this.value); state.page = 1; fetchStudents(); };
    }

    var searchTimeout;
    getEl('searchInput').addEventListener('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(function() { state.search = getEl('searchInput').value.trim(); state.page = 1; fetchStudents(); }, 300);
    });
    getEl('classSelect').addEventListener('change', function() { state.class = this.value; state.page = 1; fetchStudents(); });
    getEl('gradeSelect').addEventListener('change', function() { state.grade = this.value; state.page = 1; fetchStudents(); });
    getEl('applyFilters').onclick = function() { state.search = getEl('searchInput').value.trim(); state.class = getEl('classSelect').value; state.grade = getEl('gradeSelect').value; state.page = 1; fetchStudents(); };
    getEl('clearFilters').onclick = function() { getEl('searchInput').value = ''; getEl('classSelect').value = ''; getEl('gradeSelect').value = ''; state.search=''; state.class=''; state.grade=''; state.page=1; fetchStudents(); };
    getEl('hide_settled').onchange = function() { state.hide_settled = this.checked ? '1' : '0'; document.cookie = 'hide_settled='+(this.checked?'1':'0')+';path=/;SameSite=Lax;max-age=31536000'; state.page=1; fetchStudents(); };
    getEl('hide_small_debtors').onchange = function() { state.hide_small_debtors = this.checked ? '1' : '0'; document.cookie = 'hide_small_debtors='+(this.checked?'1':'0')+';path=/;SameSite=Lax;max-age=31536000'; state.page=1; fetchStudents(); };
    getEl('hide_with_payments').onchange = function() { state.hide_with_payments = this.checked ? '1' : '0'; document.cookie = 'hide_with_payments='+(this.checked?'1':'0')+';path=/;SameSite=Lax;max-age=31536000'; state.page=1; fetchStudents(); };

    window.quickSettle = function(studentId) {
        if (!confirm('تسویه آنی؟')) return;
        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'ajax.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onload = function() {
            if (xhr.status === 200) {
                try {
                    var r = JSON.parse(xhr.responseText);
                    if (r.success) { showToast(r.message, 'success'); fetchStudents(); }
                    else showToast(r.error || 'خطا', 'error');
                } catch(e) {}
            }
        };
        xhr.send('action=quick_settle&student_id='+studentId+'&fiscal_year_id='+state.active_year_id);
    };

    attachSortEvents();
    attachPaginationEvents();
})();

function editLedger(studentId) {
    // پنهان کردن نمایش و دکمه ویرایش، نمایش input و دکمه‌های تایید/لغو
    document.getElementById('ldisp-' + studentId).classList.add('hidden');
    document.querySelector('#linput-' + studentId + ' ~ .edit-btn').classList.add('hidden');
    var input = document.getElementById('linput-' + studentId);
    input.classList.remove('hidden');
    input.focus();
    input.select();
    document.querySelector('#linput-' + studentId + ' ~ .save-btn').classList.remove('hidden');
    document.querySelector('#linput-' + studentId + ' ~ .cancel-btn').classList.remove('hidden');
}

function saveLedger(studentId) {
    var input = document.getElementById('linput-' + studentId);
    var newValue = input.value.trim();
    var oldValue = document.getElementById('ldisp-' + studentId).innerText;
    // اگر تغییری نکرده بود، فقط به حالت نمایش برگرد
    if (newValue === oldValue || (oldValue === '-' && newValue === '')) {
        cancelEdit(studentId);
        return;
    }

    $.post('ajax.php', {
        action: 'update_ledger',
        student_id: studentId,
        ledger: newValue
    }, function(response) {
        if (response.success) {
            document.getElementById('ldisp-' + studentId).innerText = newValue || '-';
            cancelEdit(studentId);
            showToast('شماره دفترچه ذخیره شد', 'success');
        } else {
            showToast(response.error || 'خطا در ذخیره', 'error');
            // در صورت خطا، input را نگه داریم تا کاربر ویرایش کند
            input.focus();
        }
    }, 'json');
}

function cancelEdit(studentId) {
    document.getElementById('ldisp-' + studentId).classList.remove('hidden');
    var input = document.getElementById('linput-' + studentId);
    input.classList.add('hidden');
    document.querySelector('#linput-' + studentId + ' ~ .edit-btn').classList.remove('hidden');
    document.querySelector('#linput-' + studentId + ' ~ .save-btn').classList.add('hidden');
    document.querySelector('#linput-' + studentId + ' ~ .cancel-btn').classList.add('hidden');
}


</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>