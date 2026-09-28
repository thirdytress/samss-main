<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$currentUser = sams_authenticated_user();
if (!$currentUser || (($currentUser['role'] ?? null) !== 'admin')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

function sams_reports_period_bounds(string $period, ?array $activeTermRow): array
{
    $period = strtolower(trim($period));
    $now = new DateTimeImmutable('now');

    switch ($period) {
        case 'last_month':
            $start = $now->modify('first day of previous month')->setTime(0, 0, 0);
            $end = $start->modify('last day of this month')->setTime(23, 59, 59);
            $label = 'Last Month';
            break;
        case 'semester':
            if (!empty($activeTermRow['start_date']) && !empty($activeTermRow['end_date'])) {
                $start = (new DateTimeImmutable((string) $activeTermRow['start_date']))->setTime(0, 0, 0);
                $end = (new DateTimeImmutable((string) $activeTermRow['end_date']))->setTime(23, 59, 59);
                $termName = trim((string) ($activeTermRow['term_name'] ?? ''));
                $termYear = trim((string) ($activeTermRow['term_year'] ?? ''));
                $label = trim($termName . ' ' . $termYear);
                if ($label === '') {
                    $label = 'Semester';
                }
                break;
            }
            // fall through to default
        case 'year':
            $start = $now->modify('first day of January')->setTime(0, 0, 0);
            $end = $now->modify('last day of December')->setTime(23, 59, 59);
            $label = 'This Year';
            $period = 'year';
            break;
        default:
            // 'month' (default)
            $start = $now->modify('first day of this month')->setTime(0, 0, 0);
            $end = $now->setTime(23, 59, 59);
            $label = 'This Month';
            $period = 'month';
            break;
    }

    return [
        'key' => $period,
        'label' => $label,
        'start' => $start,
        'end' => $end,
    ];
}

function sams_reports_office_filter(string $officeKey, string $alias = 'a'): array
{
    $officeKey = trim($officeKey);
    if ($officeKey === '' || strtolower($officeKey) === 'all') {
        return ['sql' => '', 'params' => [], 'label' => 'All Offices'];
    }

    $alias = preg_replace('/[^a-zA-Z0-9_]/', '', $alias) ?: 'a';

    return [
        'sql' => ' AND COALESCE(NULLIF(TRIM(' . $alias . '.preferred_office), ""), "Unassigned") = :office_filter',
        'params' => ['office_filter' => $officeKey],
        'label' => $officeKey,
    ];
}

try {
    $pdo = sams_pdo();

    $periodKey = (string) ($_GET['period'] ?? 'month');
    $mode = (string) ($_GET['mode'] ?? 'scheduled');
    $officeKey = (string) ($_GET['office'] ?? 'all');

    $activeTermRow = $pdo->query(
        'SELECT term_id, term_name, term_year, start_date, end_date
         FROM terms
         WHERE is_active = 1
         ORDER BY term_id DESC
         LIMIT 1'
    )->fetch(PDO::FETCH_ASSOC) ?: null;

    $periodRange = sams_reports_period_bounds($periodKey, $activeTermRow);
    $periodStart = $periodRange['start']->format('Y-m-d H:i:s');
    $periodEnd = $periodRange['end']->format('Y-m-d H:i:s');

    $officeFilter = sams_reports_office_filter($officeKey, 'a');

    // Fetch monthly hours (all 12 months of current year, only accepted duties)
    $months = [];
    for ($month = 1; $month <= 12; $month++) {
        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(TIMESTAMPDIFF(SECOND, al.clock_in_time, al.clock_out_time) / 3600), 0)
             FROM attendance_logs al
             LEFT JOIN applications a ON a.application_id = al.application_id
             LEFT JOIN duty_schedules ds ON ds.duty_id = al.duty_id
             WHERE YEAR(al.created_at) = YEAR(CURDATE())
               AND MONTH(al.created_at) = :month
               AND al.clock_in_time IS NOT NULL
               AND al.clock_out_time IS NOT NULL
               AND (ds.status = "deployed" OR ds.duty_id IS NULL)' . $officeFilter['sql']
        );
        $stmt->execute(array_merge(['month' => $month], $officeFilter['params']));
        $hours = (float) $stmt->fetchColumn();
        $months[] = ['label' => date('M', mktime(0, 0, 0, $month, 1)), 'h' => (int) round($hours)];
    }
    $max_h = max(array_column($months, 'h')) ?: 1;

    // Fetch office distribution (only from accepted duty schedules)
    $officesStmt = $pdo->prepare(
        'SELECT COALESCE(a.preferred_office, "Unassigned") AS name,
                COUNT(*) AS count
         FROM attendance_logs al
         LEFT JOIN applications a ON a.application_id = al.application_id
         LEFT JOIN duty_schedules ds ON ds.duty_id = al.duty_id
         WHERE al.created_at BETWEEN :period_start AND :period_end
           AND (ds.status = "deployed" OR ds.duty_id IS NULL)' . $officeFilter['sql'] . '
         GROUP BY COALESCE(a.preferred_office, "Unassigned")
         ORDER BY count DESC'
    );
    $officesStmt->execute(array_merge([
        'period_start' => $periodStart,
        'period_end' => $periodEnd,
    ], $officeFilter['params']));
    $officeRows = $officesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $officeTotal = array_sum(array_map(static fn($row) => (int) ($row['count'] ?? 0), $officeRows));
    $offices = [];
    foreach ($officeRows as $officeRow) {
        $count = (int) ($officeRow['count'] ?? 0);
        $name = (string) ($officeRow['name'] ?? 'Unassigned');
        $fill = $officeTotal > 0 ? (int) round(($count / $officeTotal) * 100) : 0;
        $offices[] = [
            'name' => $name,
            'count' => $count,
            'pct' => $officeTotal > 0 ? number_format(($count / $officeTotal) * 100, 1) . '%' : '0.0%',
            'fill_pct' => $fill,
            'color' => str_contains(strtolower($name), 'sdao') ? 'blue' : (str_contains(strtolower($name), 'library') ? 'green' : (str_contains(strtolower($name), 'computer') ? 'purple' : (str_contains(strtolower($name), 'registrar') ? 'orange' : 'grey'))),
        ];
    }

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'data' => [
            'months' => $months,
            'max_h' => $max_h,
            'offices' => $offices,
        ],
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
    exit;
}
