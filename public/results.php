<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_login();

$year = (int)($_GET['year'] ?? 0);
$month = (int)($_GET['month'] ?? 0);
$m = (string)($_GET['m'] ?? '');
if ($m !== '' && preg_match('/^(?<year>\d{4})-(?<month>\d{2})$/', $m, $matches)) {
    $year = (int)$matches['year'];
    $month = (int)$matches['month'];
}

if ($year <= 0 || $month <= 0) {
    $latest = repo_get_latest_month($db, current_user_id());
    $year = $year > 0 ? $year : (int)($latest['y'] ?? date('Y'));
    $month = $month > 0 ? $month : (int)($latest['m'] ?? date('n'));
}

redirect('/summary.php?year=' . $year . '&month=' . $month);
