<?php
/**
 * لیست آخرین پرداخت‌ها برای رسید – نسخه ۲.۲ مقاوم
 * مسیر: /receipt_list.php
 */
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/functions.php';

$page_title = 'رسید پرداخت‌ها';

$search = $_GET['search'] ?? '';
$fiscal = (int)($_GET['fiscal'] ?? 0);

$fiscal_years = $pdo_master->query("SELECT * FROM fiscal_years ORDER BY sort_order ASC, id ASC")->fetchAll();
$active_year_id = get_active_fiscal_year_id();
$selected_fiscal = $fiscal ?: $active_year_id;

$where = ["p.fiscal_year_id = ?"]; $params = [$selected_fiscal];
if ($search) {
    $where[] = "(s.first_name LIKE ? OR s.last_name LIKE ? OR s.national_id LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}
$sql = "SELECT p.*, s.first_name, s.last_name, s.class_name, s.national_id
        FROM payments p JOIN students s ON p.student_id = s.national_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY p.payment_date DESC, p.id DESC LIMIT 100";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$payments = $stmt->fetchAll();

$unit = get_setting('currency_unit', 'rial');
$method_fa = ['cash' => 'نقدی', 'card' => 'کارت‌خوان', 'transfer' => 'انتقال وجه'];

include INCLUDES_PATH . '/header.php';
?>
<div class="max-w-6xl mx-auto">
    <h1 class="text-3xl font-extrabold text-slate-800 mb-6">رسید پرداخت‌ها</h1>
    <div class="bg-white rounded-2xl border border-slate-200 p-4 mb-6 flex flex-col sm:flex-row gap-3">
        <form method="get" class="flex-1 flex gap-3">
            <input type="text" name="search" value="<?= e($search) ?>" placeholder="نام یا کد ملی..." class="flex-1 p-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm">
            <select name="fiscal" class="p-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm">
                <?php foreach ($fiscal_years as $fy): ?>
                    <option value="<?= $fy['id'] ?>" <?= $fy['id'] == $selected_fiscal ? 'selected' : '' ?>><?= e($fy['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-xl text-sm">جستجو</button>
        </form>
    </div>
    <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead><tr class="bg-slate-50"><th class="px-4 py-3">شماره</th><th class="px-4 py-3">تاریخ</th><th class="px-4 py-3">دانش‌آموز</th><th class="px-4 py-3">کلاس</th><th class="px-4 py-3">مبلغ</th><th class="px-4 py-3">نوع</th><th class="text-center px-4 py-3">رسید</th></tr></thead>
                <tbody>
                    <?php if (empty($payments)): ?><tr><td colspan="7" class="text-center py-10 text-slate-400">پرداختی یافت نشد.</td></tr>
                    <?php else: foreach ($payments as $p): ?>
                        <tr class="border-b hover:bg-slate-50">
                            <td class="px-4 py-3"><?= $p['id'] ?></td>
                            <td class="px-4 py-3"><?= gregorian_to_jalali($p['payment_date']) ?></td>
                            <td class="px-4 py-3"><?= e($p['first_name'].' '.$p['last_name']) ?></td>
                            <td class="px-4 py-3"><?= e($p['class_name']) ?></td>
                            <td class="px-4 py-3"><?= format_money($p['amount'], $unit) ?></td>
                            <td class="px-4 py-3"><?= $method_fa[$p['payment_method']] ?? $p['payment_method'] ?></td>
                            <td class="px-4 py-3 text-center"><a href="receipt.php?id=<?= $p['id'] ?>" class="px-3 py-1.5 bg-indigo-50 text-indigo-700 rounded-lg text-xs">رسید</a></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php include INCLUDES_PATH . '/footer.php'; ?>