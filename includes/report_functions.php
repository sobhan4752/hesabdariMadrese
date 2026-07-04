<?php
/**
 * توابع کمکی گزارش‌ها
 * مسیر: /includes/report_functions.php
 */
function apply_filters(&$where, &$params, $selected_fiscal, $greg_from, $greg_to, $method, $class, $search) {
    $where[] = "p.fiscal_year_id = ?"; $params[] = $selected_fiscal;
    if ($greg_from) { $where[] = "p.payment_date >= ?"; $params[] = $greg_from; }
    if ($greg_to)   { $where[] = "p.payment_date <= ?"; $params[] = $greg_to; }
    if ($method)    { $where[] = "p.payment_method = ?"; $params[] = $method; }
    if ($class)     { $where[] = "s.class_name = ?"; $params[] = $class; }
    if ($search)    {
        $where[] = "(s.first_name LIKE ? OR s.last_name LIKE ? OR s.national_id LIKE ?)";
        $like = "%$search%";
        $params[] = $like; $params[] = $like; $params[] = $like;
    }
}