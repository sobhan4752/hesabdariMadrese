<?php
/**
 * مدیریت تخفیف‌ها – نسخه ۲.۲ مقاوم
 * مسیر: /discounts.php
 */
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/functions.php';

csrf_verify();

$page_title = 'مدیریت تخفیف‌ها';
$errors = [];
$success = [];

// سال‌های مالی از دیتابیس اصلی
$fiscal_years = $pdo_master->query("SELECT * FROM fiscal_years ORDER BY sort_order ASC, id ASC")->fetchAll();
$active_year_id = get_active_fiscal_year_id();
$selected_fiscal = (int)($_GET['fiscal'] ?? $active_year_id);

// ثبت تخفیف
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_discount'])) {
    $student_id = trim($_POST['student_id'] ?? '');
    $fiscal_year_id = (int)($_POST['fiscal_year_id'] ?? 0);
    $amount = (int)str_replace(',', '', $_POST['amount'] ?? '0');
    $description = trim($_POST['description'] ?? '');

    if (empty($student_id) || strlen($student_id) !== 10) $errors[] = 'کد ملی معتبر نیست.';
    if ($fiscal_year_id < 1) $errors[] = 'سال مالی انتخاب نشده.';
    if ($amount <= 0) $errors[] = 'مبلغ تخفیف باید بزرگتر از صفر باشد.';

    if (empty($errors)) {
        $chk = $pdo->prepare("SELECT national_id FROM students WHERE national_id = ?");
        $chk->execute([$student_id]);
        if (!$chk->fetch()) {
            $errors[] = 'دانش‌آموز با این کد ملی یافت نشد.';
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO student_discounts (student_id, fiscal_year_id, amount, description) VALUES (?,?,?,?)");
                $stmt->execute([$student_id, $fiscal_year_id, $amount, $description]);
                $stmt = $pdo->prepare("INSERT INTO audit_log (table_name, record_id, action, new_data) VALUES (?,?,?,?)");
                $stmt->execute(['student_discounts', $pdo->lastInsertId(), 'INSERT', json_encode([
                    'student_id' => $student_id, 'fiscal_year_id' => $fiscal_year_id, 'amount' => $amount
                ], JSON_UNESCAPED_UNICODE)]);
                $success[] = 'تخفیف با موفقیت ثبت شد.';
            } catch (\Exception $e) {
                $errors[] = 'خطا در ثبت: ' . $e->getMessage();
            }
        }
    }
}

// حذف تخفیف
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $discount_id = (int)$_GET['delete'];
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT * FROM student_discounts WHERE id = ?");
        $stmt->execute([$discount_id]);
        $old = $stmt->fetch();
        if ($old) {
            $stmt = $pdo->prepare("INSERT INTO audit_log (table_name, record_id, action, old_data) VALUES (?,?,?,?)");
            $stmt->execute(['student_discounts', $discount_id, 'DELETE', json_encode($old, JSON_UNESCAPED_UNICODE)]);
        }
        $stmt = $pdo->prepare("DELETE FROM student_discounts WHERE id = ?");
        $stmt->execute([$discount_id]);
        $pdo->commit();
        $success[] = 'تخفیف حذف شد.';
    } catch (\Exception $e) {
        $pdo->rollBack();
        $errors[] = 'خطا در حذف: ' . $e->getMessage();
    }
}

$discounts = $pdo->prepare("SELECT d.*, s.first_name, s.last_name, s.class_name FROM student_discounts d JOIN students s ON d.student_id = s.national_id WHERE d.fiscal_year_id = ? ORDER BY d.id DESC");
$discounts->execute([$selected_fiscal]);
$discounts = $discounts->fetchAll();
$total_amount = array_sum(array_column($discounts, 'amount'));

include INCLUDES_PATH . '/header.php';
?>

<div class="max-w-6xl mx-auto">
    <div class="mb-8">
        <h1 class="text-3xl font-extrabold text-slate-800">مدیریت تخفیف‌ها</h1>
        <p class="text-slate-500 mt-2">ثبت و مشاهده تخفیف‌های دانش‌آموزان در سال مالی.</p>
    </div>

    <?php if ($errors): ?><div class="bg-red-50 border border-red-200 rounded-xl p-4 mb-6"><?php foreach ($errors as $e): ?><p class="text-red-800 text-sm"><?= e($e) ?></p><?php endforeach; ?></div><?php endif; ?>
    <?php if ($success): ?><div class="bg-emerald-50 border border-emerald-200 rounded-xl p-4 mb-6"><?php foreach ($success as $s): ?><p class="text-emerald-800 text-sm"><?= e($s) ?></p><?php endforeach; ?></div><?php endif; ?>

    <!-- انتخاب سال مالی -->
    <div class="bg-white rounded-2xl border border-slate-200 p-4 mb-6">
        <form method="get" class="flex items-center gap-3">
            <label class="text-sm font-semibold text-slate-700">سال مالی:</label>
            <select name="fiscal" onchange="this.form.submit()" class="py-2 px-4 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:ring-2 focus:ring-indigo-200 focus:border-indigo-400 outline-none">
                <?php foreach ($fiscal_years as $fy): ?>
                    <option value="<?= $fy['id'] ?>" <?= $fy['id'] == $selected_fiscal ? 'selected' : '' ?>><?= e($fy['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <!-- کارت آمار -->
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
        <div class="bg-white rounded-xl border border-slate-200 p-5 card-hover"><p class="text-xs text-slate-500">تعداد تخفیف‌ها</p><p class="text-2xl font-bold text-slate-800"><?= count($discounts) ?></p></div>
        <div class="bg-white rounded-xl border border-slate-200 p-5 card-hover"><p class="text-xs text-slate-500">مجموع مبالغ</p><p class="text-2xl font-bold text-violet-600"><?= number_format($total_amount) ?> ریال</p></div>
    </div>

    <!-- فرم ثبت جدید -->
    <div class="bg-white rounded-2xl border border-slate-200 p-6 mb-6">
        <h3 class="text-lg font-bold text-slate-800 mb-4">ثبت تخفیف جدید</h3>
        <form method="post" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <?= csrf_field() ?>
            <input type="hidden" name="fiscal_year_id" value="<?= $selected_fiscal ?>">
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-2">کد ملی دانش‌آموز</label>
                <input type="text" name="student_id" placeholder="۱۰ رقم" maxlength="10" required pattern="\d{10}" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-200 focus:border-indigo-400 outline-none">
            </div>
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-2">مبلغ تخفیف (ریال)</label>
                <input type="text" name="amount" class="money-input w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-200 focus:border-indigo-400 outline-none" required placeholder="مبلغ">
            </div>
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-2">توضیحات</label>
                <input type="text" name="description" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-200 focus:border-indigo-400 outline-none" placeholder="اختیاری">
            </div>
            <div class="flex items-end">
                <button type="submit" name="add_discount" class="w-full py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white font-medium rounded-xl transition-colors btn-pulse">ثبت تخفیف</button>
            </div>
        </form>
    </div>

    <!-- لیست تخفیف‌ها -->
    <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden">
        <div class="px-6 py-4 border-b bg-slate-50"><h3 class="font-bold text-slate-800">لیست تخفیف‌ها</h3></div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead><tr class="bg-slate-50"><th class="px-4 py-3">دانش‌آموز</th><th class="px-4 py-3">کلاس</th><th class="px-4 py-3">کد ملی</th><th class="px-4 py-3">مبلغ (ریال)</th><th class="px-4 py-3">تاریخ ثبت</th><th class="px-4 py-3">توضیحات</th><th class="text-center px-4 py-3">عملیات</th></tr></thead>
                <tbody>
                    <?php if (empty($discounts)): ?>
                        <tr><td colspan="7" class="text-center py-10 text-slate-400">هیچ تخفیفی ثبت نشده است.</td></tr>
                    <?php else: foreach ($discounts as $d): ?>
                        <tr class="border-b hover:bg-slate-50 table-row-hover">
                            <td class="px-4 py-3 font-medium"><?= e($d['first_name'] . ' ' . $d['last_name']) ?></td>
                            <td class="px-4 py-3"><?= e($d['class_name']) ?></td>
                            <td class="px-4 py-3 font-mono" dir="ltr"><?= e($d['student_id']) ?></td>
                            <td class="px-4 py-3 text-violet-600 font-medium"><?= number_format($d['amount']) ?></td>
                            <td class="px-4 py-3 text-slate-500"><?= e(gregorian_to_jalali(substr($d['created_at'], 0, 10))) ?></td>
                            <td class="px-4 py-3 text-xs text-slate-500"><?= e($d['description'] ?: '-') ?></td>
                            <td class="px-4 py-3 text-center">
                                <a href="?fiscal=<?= $selected_fiscal ?>&delete=<?= $d['id'] ?>" onclick="return confirm('مطمئن هستید؟')" class="px-3 py-1.5 bg-red-50 hover:bg-red-100 text-red-700 rounded-lg text-xs font-medium transition-colors">حذف</a>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include INCLUDES_PATH . '/footer.php'; ?>