<?php
/**
 * پیشخوان – نسخه ۲.۲ با دو پایگاه داده و ویجت اضافه پرداخت‌ها
 * مسیر: /index.php
 */
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/functions.php';

$page_title = 'پیشخوان';

try {
    $active_year_id = get_active_fiscal_year_id();
    $active_year = null;
    if ($active_year_id > 0) {
        $stmt = $pdo_master->prepare("SELECT * FROM fiscal_years WHERE id = ?");
        $stmt->execute([$active_year_id]);
        $active_year = $stmt->fetch();
    }
    $active_year_name = $active_year ? $active_year['name'] : 'تعریف نشده';
    $tuition_amount = $active_year ? (int)$active_year['tuition_amount'] : 0;
    $unit = get_setting('currency_unit', 'rial');

    if ($active_year_id > 0) {
        $data = get_students_with_balances($active_year_id);
        $students = $data['students'];
        $balances = $data['balances'];
        $total_students = count($students);
        $settled_count = 0;
        $total_payments = 0;
        $total_discounts = 0;
        $total_overpay = 0;
        foreach ($balances as $bal) {
            $total_payments += $bal['paid'];
            $total_discounts += $bal['discount'];
            if ($bal['balance'] <= 0) $settled_count++;
            if ($bal['balance'] < 0) $total_overpay += abs($bal['balance']);
        }
        $total_tuition = $total_students * $tuition_amount;
        $balance_receivable = $total_tuition - $total_discounts - $total_payments;
        $collection_percent = ($total_tuition - $total_discounts) > 0
            ? round($total_payments / ($total_tuition - $total_discounts) * 100, 1)
            : 0;

        $latest_payments = $pdo->query("
            SELECT p.id, p.amount, p.payment_method, p.payment_date, p.created_at,
                   s.first_name, s.last_name, s.class_name, s.national_id
            FROM payments p
            JOIN students s ON p.student_id = s.national_id
            ORDER BY p.id DESC LIMIT 10
        ")->fetchAll();

        $today_greg = date('Y-m-d');
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE payment_date = ?");
        $stmt->execute([$today_greg]);
        $today_payments = (int)$stmt->fetchColumn();

        $latest_students = array_slice($students, 0, 5);
    } else {
        $total_students = $pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();
        $total_payments = $total_discounts = $balance_receivable = $settled_count = $total_overpay = 0;
        $collection_percent = 0;
        $today_payments = 0;
        $latest_payments = [];
        $latest_students = [];
    }
} catch (\PDOException $e) {
    $error_msg = 'خطا در بارگذاری آمار: ' . $e->getMessage();
}

include INCLUDES_PATH . '/header.php';
?>

<div class="max-w-7xl mx-auto">
    <div class="mb-8">
        <h1 class="text-3xl font-extrabold text-slate-800">پیشخوان</h1>
        <p class="text-slate-500 mt-2">
            سال مالی فعال: <span class="font-bold text-indigo-600"><?= e($active_year_name) ?></span>
        </p>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 mb-8">
        <div class="bg-white rounded-2xl border border-slate-200 p-6 card-hover">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-slate-500">کل دانش‌آموزان</p>
                    <p class="text-3xl font-extrabold text-slate-800 mt-1"><?= number_format($total_students) ?></p>
                </div>
                <div class="w-12 h-12 bg-blue-50 rounded-xl flex items-center justify-center">
                    <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z"/></svg>
                </div>
            </div>
            <p class="text-xs text-slate-400 mt-3"><?= $settled_count ?> نفر تسویه‌شده</p>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200 p-6 card-hover">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-slate-500">پرداختی‌ها (سال جاری)</p>
                    <p class="text-3xl font-extrabold text-slate-800 mt-1"><?= format_money($total_payments, $unit) ?></p>
                </div>
                <div class="w-12 h-12 bg-emerald-50 rounded-xl flex items-center justify-center">
                    <svg class="w-6 h-6 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5Z"/></svg>
                </div>
            </div>
            <div class="w-full bg-slate-200 rounded-full h-1.5 mt-4">
                <div class="bg-emerald-600 h-1.5 rounded-full" style="width: <?= min($collection_percent, 100) ?>%;"></div>
            </div>
            <p class="text-xs text-slate-400 mt-2"><?= $collection_percent ?>% وصول</p>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200 p-6 card-hover">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-slate-500">مانده قابل وصول</p>
                    <p class="text-3xl font-extrabold text-slate-800 mt-1"><?= format_money($balance_receivable, $unit) ?></p>
                </div>
                <div class="w-12 h-12 bg-amber-50 rounded-xl flex items-center justify-center">
                    <svg class="w-6 h-6 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                </div>
            </div>
            <p class="text-xs text-slate-400 mt-3"><?= number_format($total_students - $settled_count) ?> نفر بدهکار</p>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200 p-6 card-hover">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-slate-500">مجموع تخفیف‌ها</p>
                    <p class="text-3xl font-extrabold text-slate-800 mt-1"><?= format_money($total_discounts, $unit) ?></p>
                </div>
                <div class="w-12 h-12 bg-violet-50 rounded-xl flex items-center justify-center">
                    <svg class="w-6 h-6 text-violet-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="m9 14.25 6-6m4.5-3.493V21.75l-3.75-1.5-3.75 1.5-3.75-1.5-3.75 1.5V4.757c0-1.108.806-2.057 1.907-2.185a48.507 48.507 0 0 1 11.186 0c1.1.128 1.907 1.077 1.907 2.185ZM9.75 9h.008v.008H9.75V9Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm4.125 4.5h.008v.008h-.008V13.5Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z"/></svg>
                </div>
            </div>
            <p class="text-xs text-slate-400 mt-3"><?= $today_payments ?> پرداخت امروز</p>
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5 mb-8">
        <div class="bg-white rounded-2xl border border-slate-200 p-6 card-hover">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-slate-500">اضافه پرداخت‌ها</p>
                    <p class="text-3xl font-extrabold text-slate-800 mt-1"><?= format_money($total_overpay, $unit) ?></p>
                    <p class="text-xs text-slate-400">مازاد پرداختی</p>
                </div>
                <div class="w-12 h-12 bg-blue-50 rounded-xl flex items-center justify-center">
                    <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                </div>
            </div>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200 p-5 card-hover">
            <h2 class="text-lg font-bold text-slate-800 mb-4">ثبت پرداخت سریع</h2>
            <form method="get" action="payment.php" class="space-y-3">
                <div>
                    <label class="text-xs text-slate-500">کد ملی یا نام</label>
                    <input type="text" name="student_id" placeholder="کد ملی دانش‌آموز" class="w-full mt-1 p-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm">
                </div>
                <button type="submit" class="w-full py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white font-medium rounded-xl transition-colors btn-pulse">رفتن به صفحه پرداخت</button>
            </form>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200 p-5 card-hover">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-bold text-slate-800">آخرین دانش‌آموزان</h2>
                <a href="students.php" class="text-xs text-indigo-600 hover:underline">مشاهده همه</a>
            </div>
            <?php if (!empty($latest_students)): ?>
            <ul class="space-y-2">
                <?php foreach ($latest_students as $s): ?>
                <li class="flex justify-between items-center text-sm">
                    <span><?= e($s['first_name'] . ' ' . $s['last_name']) ?></span>
                    <span class="text-slate-400"><?= e($s['class_name']) ?></span>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php else: ?>
            <p class="text-sm text-slate-400">دانش‌آموزی ثبت نشده است.</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 p-6">
        <h2 class="text-lg font-bold text-slate-800 mb-4">آخرین پرداخت‌ها</h2>
        <?php if (!empty($latest_payments)): ?>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr class="border-b bg-slate-50"><th class="text-right px-4 py-3 font-medium text-slate-600">دانش‌آموز</th><th class="text-right px-4 py-3 font-medium text-slate-600">کلاس</th><th class="text-right px-4 py-3 font-medium text-slate-600">مبلغ</th><th class="text-right px-4 py-3 font-medium text-slate-600">نوع</th><th class="text-right px-4 py-3 font-medium text-slate-600">تاریخ</th><th class="text-center px-4 py-3 font-medium text-slate-600">رسید</th></tr></thead>
                    <tbody>
                        <?php foreach ($latest_payments as $lp): ?>
                            <tr class="border-b hover:bg-slate-50 table-row-hover">
                                <td class="px-4 py-3 font-medium"><?= e($lp['first_name'] . ' ' . $lp['last_name']) ?></td>
                                <td class="px-4 py-3 text-slate-600"><?= e($lp['class_name']) ?></td>
                                <td class="px-4 py-3 text-slate-800"><?= format_money($lp['amount'], $unit) ?></td>
                                <td class="px-4 py-3">
                                    <?php
                                    $labels = ['cash'=>'نقدی','card'=>'کارت‌خوان','transfer'=>'انتقال وجه'];
                                    $colors = ['cash'=>'bg-blue-100 text-blue-700','card'=>'bg-purple-100 text-purple-700','transfer'=>'bg-teal-100 text-teal-700'];
                                    ?>
                                    <span class="px-2 py-1 rounded-full text-xs font-medium <?= $colors[$lp['payment_method']] ?? '' ?>"><?= $labels[$lp['payment_method']] ?? $lp['payment_method'] ?></span>
                                </td>
                                <td class="px-4 py-3 text-slate-500"><?= e(gregorian_to_jalali($lp['payment_date'])) ?></td>
                                <td class="px-4 py-3 text-center">
                                    <a href="receipt.php?id=<?= $lp['id'] ?>" class="inline-flex items-center gap-1 px-3 py-1.5 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 rounded-lg text-xs font-medium transition-colors">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/></svg>
                                        رسید
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="text-sm text-slate-400 text-center py-8">هنوز پرداختی ثبت نشده است.</p>
        <?php endif; ?>
    </div>
</div>
   
<?php include INCLUDES_PATH . '/footer.php'; ?>