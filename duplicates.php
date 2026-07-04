<?php
/**
 * تشخیص مشابهت دانش‌آموزان + مشاهده متولدین ماه
 * مسیر: /duplicates.php
 */
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/functions.php';

$page_title = 'تشخیص مشابهت';
$criteria = $_GET['criteria'] ?? [];
if (!is_array($criteria)) $criteria = [];

$sort = $_GET['sort'] ?? 'cnt';
$order = strtolower($_GET['order'] ?? 'desc');
$order = ($order === 'asc') ? 'ASC' : 'DESC';

// ======================== بخش متولدین ماه (با مرتب‌سازی بر اساس روز) ========================
$birth_month = $_GET['birth_month'] ?? '';
$birth_sort = $_GET['birth_sort'] ?? 'birth_date';
$birth_order = strtolower($_GET['birth_order'] ?? 'asc');
if (!in_array($birth_order, ['asc', 'desc'])) $birth_order = 'asc';
$birth_order_sql = ($birth_order === 'asc') ? 'ASC' : 'DESC';
$allowed_birth_sorts = ['birth_date', 'first_name', 'last_name', 'class_name'];
if (!in_array($birth_sort, $allowed_birth_sorts)) $birth_sort = 'birth_date';

$birth_students = [];
if (!empty($birth_month) && is_numeric($birth_month) && $birth_month >= 1 && $birth_month <= 12) {
    $month_str = str_pad($birth_month, 2, '0', STR_PAD_LEFT);
    // اگر مرتب‌سازی بر اساس birth_date باشد، بر اساس روز تولد (دو رقم آخر) مرتب کن
    if ($birth_sort === 'birth_date') {
        $order_clause = "CAST(substr(birth_date, 9, 2) AS INTEGER) $birth_order_sql, last_name, first_name";
    } else {
        $order_clause = "$birth_sort $birth_order_sql, last_name, first_name";
    }
    $stmt = $pdo->prepare("SELECT * FROM students WHERE substr(birth_date, 6, 2) = ? ORDER BY $order_clause");
    $stmt->execute([$month_str]);
    $birth_students = $stmt->fetchAll();
}

// ======================== بخش تشخیص مشابهت ========================
$errors = [];
$results = [];
if (!empty($criteria)) {
    $allowed = ['first_name', 'last_name', 'father_name', 'birth_date'];
    $selectFields = array_values(array_intersect($criteria, $allowed));
    if (empty($selectFields)) {
        $errors[] = 'حداقل یک معیار انتخاب کنید.';
    } else {
        $fields = implode(', ', $selectFields);
        $valid_sort_columns = array_merge(['cnt'], $selectFields);
        if (!in_array($sort, $valid_sort_columns)) {
            $sort = 'cnt';
            $order = 'DESC';
        }

        $sql = "SELECT $fields, COUNT(*) as cnt
                FROM students
                GROUP BY $fields
                HAVING cnt > 1
                ORDER BY $sort $order";
        try {
            $stmt = $pdo->query($sql);
            $groups = $stmt->fetchAll();

            foreach ($groups as $group) {
                $where = [];
                $params = [];
                foreach ($selectFields as $field) {
                    $where[] = "$field = ?";
                    $params[] = $group[$field];
                }
                $whereClause = implode(' AND ', $where);

                $s2 = $pdo->prepare("SELECT national_id, first_name, last_name, class_name
                                     FROM students
                                     WHERE $whereClause
                                     ORDER BY national_id");
                $s2->execute($params);
                $students = $s2->fetchAll();

                $ids = [];
                $classes = [];
                $names = [];
                foreach ($students as $s) {
                    $ids[] = $s['national_id'];
                    $classes[] = $s['class_name'];
                    $names[] = $s['first_name'] . ' ' . $s['last_name'];
                }

                $group['ids'] = implode(', ', $ids);
                $group['classes'] = implode(', ', $classes);
                $group['names'] = implode('، ', $names);
                $results[] = $group;
            }
        } catch (\Exception $e) {
            $errors[] = 'خطای پایگاه داده: ' . $e->getMessage();
        }
    }
}

function sort_link_dup($col, $current_sort, $current_order, $criteria) {
    $new_order = ($col === $current_sort && $current_order === 'ASC') ? 'desc' : 'asc';
    $params = '';
    foreach ($criteria as $c) $params .= '&criteria[]=' . urlencode($c);
    return "?sort=$col&order=$new_order$params";
}

function birth_sort_link($col, $current_sort, $current_order, $birth_month) {
    $new_order = ($col === $current_sort && $current_order === 'asc') ? 'desc' : 'asc';
    return "?birth_month=$birth_month&birth_sort=$col&birth_order=$new_order";
}

include INCLUDES_PATH . '/header.php';
?>

<div class="max-w-7xl mx-auto">
    <h1 class="text-3xl font-extrabold text-slate-800 mb-2">تشخیص مشابهت دانش‌آموزان</h1>
    <p class="text-slate-500 mb-6">انتخاب کنید بر اساس کدام مشخصات، دانش‌آموزان تکراری یا مشابه را بیابید.</p>

    <!-- بخش متولدین ماه -->
    <div class="bg-white rounded-2xl border border-slate-200 p-5 mb-6">
        <h3 class="font-bold text-slate-800 mb-3">متولدین ماه</h3>
        <form method="get" class="flex flex-wrap items-center gap-3">
            <select name="birth_month" class="py-2 px-4 bg-slate-50 border border-slate-200 rounded-xl text-sm">
                <option value="">انتخاب ماه...</option>
                <?php
                $months = [
                    1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد',
                    4 => 'تیر', 5 => 'مرداد', 6 => 'شهریور',
                    7 => 'مهر', 8 => 'آبان', 9 => 'آذر',
                    10 => 'دی', 11 => 'بهمن', 12 => 'اسفند'
                ];
                foreach ($months as $k => $name):
                ?>
                    <option value="<?= $k ?>" <?= ($birth_month == $k) ? 'selected' : '' ?>><?= $name ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-xl text-sm">نمایش</button>
            <?php if ($birth_month !== ''): ?>
                <a href="duplicates.php" class="px-4 py-2 bg-slate-100 rounded-xl text-sm">پاک کردن</a>
            <?php endif; ?>
        </form>

        <?php if ($birth_month !== ''): ?>
            <div class="mt-4">
                <?php if (empty($birth_students)): ?>
                    <p class="text-slate-400 text-sm">هیچ دانش‌آموزی در این ماه متولد نشده است.</p>
                <?php else: ?>
                    <p class="text-sm text-slate-600 mb-2"><?= count($birth_students) ?> دانش‌آموز متولد <?= $months[(int)$birth_month] ?></p>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-50">
                                <tr>
                                    <th class="px-4 py-3 text-right">نام</th>
                                    <th class="px-4 py-3 text-right">نام خانوادگی</th>
                                    <th class="px-4 py-3 text-right">کلاس</th>
                                    <th class="px-4 py-3 text-right">کد ملی</th>
                                    <th class="px-4 py-3 text-right">
                                        <a href="<?= birth_sort_link('birth_date', $birth_sort, $birth_order, $birth_month) ?>" class="hover:text-indigo-600">
                                            تاریخ تولد
                                            <?= ($birth_sort === 'birth_date') ? ($birth_order === 'asc' ? '▲' : '▼') : '' ?>
                                        </a>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($birth_students as $st): ?>
                                    <tr class="border-b hover:bg-slate-50">
                                        <td class="px-4 py-3"><?= e($st['first_name']) ?></td>
                                        <td class="px-4 py-3"><?= e($st['last_name']) ?></td>
                                        <td class="px-4 py-3"><?= e($st['class_name']) ?></td>
                                        <td class="px-4 py-3 font-mono" dir="ltr"><?= e($st['national_id']) ?></td>
                                        <td class="px-4 py-3"><?= e($st['birth_date']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- بخش تشخیص مشابهت -->
    <?php if ($errors): ?><div class="bg-red-50 border border-red-200 rounded-xl p-4 mb-6"><?php foreach ($errors as $e): ?><p class="text-red-800 text-sm"><?= e($e) ?></p><?php endforeach; ?></div><?php endif; ?>

    <div class="bg-white rounded-2xl border border-slate-200 p-5 mb-6">
        <form method="get" class="flex flex-wrap items-center gap-4">
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="criteria[]" value="first_name" <?= in_array('first_name', $criteria) ? 'checked' : '' ?>> نام</label>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="criteria[]" value="last_name" <?= in_array('last_name', $criteria) ? 'checked' : '' ?>> نام خانوادگی</label>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="criteria[]" value="father_name" <?= in_array('father_name', $criteria) ? 'checked' : '' ?>> نام پدر</label>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="criteria[]" value="birth_date" <?= in_array('birth_date', $criteria) ? 'checked' : '' ?>> تاریخ تولد</label>
            <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-xl text-sm">جستجو</button>
        </form>
        <p class="text-xs text-slate-400 mt-2">می‌توانید چند مورد را با هم انتخاب کنید. نتیجه دانش‌آموزانی را نشان می‌دهد که در موارد انتخاب‌شده کاملاً یکسان هستند.</p>
    </div>

    <?php if (!empty($results)): ?>
    <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50">
                    <tr>
                        <?php foreach ($criteria as $c): ?>
                            <th class="px-4 py-3 text-center">
                                <a href="<?= sort_link_dup($c, $sort, $order, $criteria) ?>" class="hover:text-indigo-600">
                                    <?= $c === 'first_name' ? 'نام' : ($c === 'last_name' ? 'نام خانوادگی' : ($c === 'father_name' ? 'نام پدر' : 'تاریخ تولد')) ?>
                                    <?= $sort === $c ? ($order === 'ASC' ? '▲' : '▼') : '' ?>
                                </a>
                            </th>
                        <?php endforeach; ?>
                        <th class="px-4 py-3 text-right">
                            <a href="<?= sort_link_dup('cnt', $sort, $order, $criteria) ?>" class="hover:text-indigo-600">
                                تعداد
                                <?= $sort === 'cnt' ? ($order === 'ASC' ? '▲' : '▼') : '' ?>
                            </a>
                        </th>
                        <th class="px-4 py-3 text-center">کدهای ملی (کلاس)</th>
                        <th class="px-4 py-3 text-center">نام دانش‌آموزان</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($results as $row): ?>
                        <tr class="border-b hover:bg-slate-50">
                            <?php foreach ($criteria as $c): ?>
                                <td class="px-4 py-3"><?= e($row[$c] ?? '-') ?></td>
                            <?php endforeach; ?>
                            <td class="px-4 py-3 font-bold text-red-600"><?= $row['cnt'] ?></td>
                            <td class="px-4 py-3 text-xs text-slate-600 max-w-[250px] whitespace-normal break-words"><?= e($row['ids'] . ' (' . $row['classes'] . ')') ?></td>
                            <td class="px-4 py-3 text-xs text-slate-600 max-w-[250px] whitespace-normal break-words"><?= e($row['names']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($criteria)): ?>
        <div class="text-center py-10 text-slate-400">هیچ دانش‌آموز مشابهی یافت نشد.</div>
    <?php endif; ?>
</div>

<?php include INCLUDES_PATH . '/footer.php'; ?>