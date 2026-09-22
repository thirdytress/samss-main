<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$currentUser = sams_authenticated_user();
if (!$currentUser || (($currentUser['role'] ?? null) !== 'admin')) {
    header('Location: ../login.php');
    exit;
}

$pdo = sams_pdo();
// Auto-clear "new" flag when an admin opens the reports page (acknowledge notifications)
try {
    $pdo->exec("UPDATE student_reports SET is_new = 0 WHERE is_new = 1");
} catch (Throwable $e) {
    // don't block page load on failure
}

$reportsFlash = '';
if (isset($_SESSION['reports_flash'])) {
    $reportsFlash = (string) $_SESSION['reports_flash'];
    unset($_SESSION['reports_flash']);
}

$applicationBadgeCount = (int) $pdo->query("SELECT COUNT(*) FROM applications WHERE status = 'pending'")->fetchColumn();

$admin_name = (string) ($currentUser['name'] ?? 'SAMS Admin');
$admin_role = 'SDAO Head';
$department = 'NU Lipa - Student Development and Activities Office';

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
                    $label = 'Current Semester';
                }
            } else {
                $start = $now->modify('-3 months')->setTime(0, 0, 0);
                $end = $now->setTime(23, 59, 59);
                $label = 'Current Semester';
            }
            break;
        case 'trimester1':
            // July 1 -> Oct 31 (same year)
            $year = (int) $now->format('Y');
            $start = $now->setDate($year, 7, 1)->setTime(0, 0, 0);
            $end = $now->setDate($year, 10, 31)->setTime(23, 59, 59);
            $label = 'Trimester 1 (' . $start->format('Y') . ')';
            break;
        case 'trimester2':
            // Nov 1 -> Feb 28/29 (spans year boundary)
            $year = (int) $now->format('Y');
            $t2_start = (new DateTimeImmutable())->setDate($year, 11, 1)->setTime(0, 0, 0);
            // end is end of Feb next year
            $t2_end = $t2_start->modify('+3 months')->modify('last day of this month')->setTime(23, 59, 59);
            // if now is earlier in the year (Jan-Feb), use previous year's Nov as start
            if ($now < $t2_start && (int) $now->format('n') <= 2) {
                $t2_start = $t2_start->modify('-1 year');
                $t2_end = $t2_start->modify('+3 months')->modify('last day of this month')->setTime(23, 59, 59);
            }
            $start = $t2_start;
            $end = $t2_end;
            $label = 'Trimester 2 (' . $start->format('Y') . '–' . $end->format('Y') . ')';
            break;
        case 'trimester3':
            // Mar 1 -> Jun 30 (same year)
            $year = (int) $now->format('Y');
            $start = $now->setDate($year, 3, 1)->setTime(0, 0, 0);
            $end = $now->setDate($year, 6, 30)->setTime(23, 59, 59);
            $label = 'Trimester 3 (' . $start->format('Y') . ')';
            break;
        case 'year':
            $year = (int) $now->format('Y');
            $start = $now->setDate($year, 1, 1)->setTime(0, 0, 0);
            $end = $now->setDate($year, 12, 31)->setTime(23, 59, 59);
            $label = 'This Year';
            break;
        case 'month':
        default:
            $start = $now->modify('first day of this month')->setTime(0, 0, 0);
            $end = $start->modify('last day of this month')->setTime(23, 59, 59);
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

$periodKey = (string) ($_GET['period'] ?? 'month');
$mode = (string) ($_GET['mode'] ?? 'scheduled'); // 'scheduled' (default) or 'logs'
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
$periodLabel = (string) $periodRange['label'];
$periodKey = (string) $periodRange['key'];

$officeOptionsStmt = $pdo->query(
    'SELECT DISTINCT COALESCE(NULLIF(TRIM(preferred_office), ""), "Unassigned") AS office_name
     FROM applications
     ORDER BY office_name ASC'
);
$officeOptions = array_values(array_unique(array_map('strval', $officeOptionsStmt->fetchAll(PDO::FETCH_COLUMN) ?: [])));
if ($officeKey !== 'all' && !in_array($officeKey, $officeOptions, true)) {
    $officeKey = 'all';
}

$officeFilter = sams_reports_office_filter($officeKey, 'a');
$officeLabel = (string) ($officeFilter['label'] ?? 'All Offices');

$attendance_data = [];
if ($mode === 'scheduled') {
    // Use duty_schedules as the expected duties and left-join latest attendance_logs per duty within period
    $termFilterSql = '';
    $termParams = [];
    if (!empty($activeTermRow['term_id'])) {
        $termFilterSql = 'AND ds.term_id = :term_id';
        $termParams['term_id'] = (int) $activeTermRow['term_id'];
    }

    $attendanceDataStmt = $pdo->prepare(
        'SELECT
            COALESCE(CONCAT(u.last_name, ", ", u.first_name), "Unassigned Student") AS name,
            COALESCE(a.preferred_office, "Unassigned") AS office_name,
            SUM(CASE WHEN (al.log_id IS NOT NULL OR COALESCE(ds.student_response_date, ds.updated_at, ds.created_at) <= NOW()) THEN 1 ELSE 0 END) AS total,
            SUM(CASE WHEN al.status = "present" THEN 1 ELSE 0 END) AS present,
            SUM(CASE WHEN al.status = "late" THEN 1 ELSE 0 END) AS late,
            SUM(CASE WHEN (al.log_id IS NULL OR al.status = "absent") AND COALESCE(ds.student_response_date, ds.updated_at, ds.created_at) <= NOW() THEN 1 ELSE 0 END) AS absent,
            COALESCE(SUM(CASE WHEN al.clock_in_time IS NOT NULL AND al.clock_out_time IS NOT NULL
                THEN TIMESTAMPDIFF(SECOND, al.clock_in_time, al.clock_out_time) / 3600
                ELSE 0 END), 0) AS hours
         FROM duty_schedules ds
         JOIN applications a ON a.application_id = ds.application_id
         JOIN students s ON s.student_id = a.student_id
         LEFT JOIN users u ON u.user_id = s.user_id
         LEFT JOIN attendance_logs al ON al.log_id = (
             SELECT MAX(al2.log_id) FROM attendance_logs al2
             WHERE al2.duty_id = ds.duty_id
               AND al2.application_id = a.application_id
               AND al2.created_at BETWEEN :period_start AND :period_end
         )
         WHERE ds.status = "deployed" ' . $termFilterSql . $officeFilter['sql'] . '
         GROUP BY s.student_id, u.user_id, a.preferred_office
         ORDER BY total DESC, name ASC
         LIMIT 200'
    );
    $execParams = array_merge(['period_start' => $periodStart, 'period_end' => $periodEnd], $termParams);
    $execParams = array_merge($execParams, $officeFilter['params']);
    $attendanceDataStmt->execute($execParams);
    foreach ($attendanceDataStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $total = (int) ($row['total'] ?? 0);
        $present = (int) ($row['present'] ?? 0);
        $late = (int) ($row['late'] ?? 0);
        $absent = (int) ($row['absent'] ?? 0);
        $attendance_data[] = [
            'name' => trim((string) ($row['name'] ?? '')) !== '' ? (string) $row['name'] : 'Unassigned Student',
            'office' => trim((string) ($row['office_name'] ?? '')) !== '' ? (string) $row['office_name'] : 'Unassigned',
            'total' => $total,
            'present' => $present,
            'late' => $late,
            'absent' => $absent,
            'hours' => (float) ($row['hours'] ?? 0),
            'pct' => $total > 0 ? number_format((($present + $late) / $total) * 100, 1) . '%' : '0.0%',
        ];
    }
} else {
    // 'logs' fallback — preserve previous behavior (log-only aggregation)
    $attendanceDataStmt = $pdo->prepare(
        'SELECT
            COALESCE(CONCAT(u.last_name, ", ", u.first_name), "Unassigned Student") AS name,
            COALESCE(a.preferred_office, "Unassigned") AS office_name,
            COUNT(*) AS total,
            SUM(CASE WHEN al.status = "present" THEN 1 ELSE 0 END) AS present,
            SUM(CASE WHEN al.status = "late" THEN 1 ELSE 0 END) AS late,
            SUM(CASE WHEN al.status = "absent" THEN 1 ELSE 0 END) AS absent,
            COALESCE(SUM(CASE WHEN al.clock_in_time IS NOT NULL AND al.clock_out_time IS NOT NULL
                THEN TIMESTAMPDIFF(SECOND, al.clock_in_time, al.clock_out_time) / 3600
                ELSE 0 END), 0) AS hours
         FROM attendance_logs al
         LEFT JOIN applications a ON a.application_id = al.application_id
         LEFT JOIN students s ON s.student_id = a.student_id
         LEFT JOIN users u ON u.user_id = s.user_id
         WHERE al.created_at BETWEEN :period_start AND :period_end' . $officeFilter['sql'] . '
         GROUP BY s.student_id, u.user_id, a.preferred_office
         ORDER BY total DESC, name ASC
         LIMIT 200'
    );
    $attendanceDataStmt->execute(array_merge([
        'period_start' => $periodStart,
        'period_end' => $periodEnd,
    ], $officeFilter['params']));
    foreach ($attendanceDataStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $total = (int) ($row['total'] ?? 0);
        $present = (int) ($row['present'] ?? 0);
        $late = (int) ($row['late'] ?? 0);
        $absent = (int) ($row['absent'] ?? 0);
        $attendance_data[] = [
            'name' => trim((string) ($row['name'] ?? '')) !== '' ? (string) $row['name'] : 'Unassigned Student',
            'office' => trim((string) ($row['office_name'] ?? '')) !== '' ? (string) $row['office_name'] : 'Unassigned',
            'total' => $total,
            'present' => $present,
            'late' => $late,
            'absent' => $absent,
            'hours' => (float) ($row['hours'] ?? 0),
            'pct' => $total > 0 ? number_format((($present + $late) / $total) * 100, 1) . '%' : '0.0%',
        ];
    }
}

$periodHoursStmt = $pdo->prepare(
    'SELECT COALESCE(SUM(TIMESTAMPDIFF(SECOND, clock_in_time, clock_out_time) / 3600), 0)
     FROM attendance_logs al
     LEFT JOIN applications a ON a.application_id = al.application_id
     WHERE al.clock_in_time IS NOT NULL
       AND al.clock_out_time IS NOT NULL
       AND al.created_at BETWEEN :period_start AND :period_end' . $officeFilter['sql']
);
$periodHoursStmt->execute(array_merge([
    'period_start' => $periodStart,
    'period_end' => $periodEnd,
], $officeFilter['params']));
$periodHoursTotal = (float) $periodHoursStmt->fetchColumn();
$attendanceUnitLabel = $mode === 'scheduled' ? 'Expected Duties' : 'Total Logs';
$attendanceRateLabel = $mode === 'scheduled' ? 'Duty Attendance Rate' : 'Attendance Rate';
$absentLabel = $mode === 'scheduled' ? 'Absent / Unmarked' : 'Absent';

if ($mode === 'scheduled') {
    $termFilterSql = '';
    $termParams = [];
    if (!empty($activeTermRow['term_id'])) {
        $termFilterSql = 'AND ds.term_id = :term_id';
        $termParams['term_id'] = (int) $activeTermRow['term_id'];
    }
    $attendanceSummaryStmt = $pdo->prepare(
        'SELECT
            SUM(CASE WHEN (al.log_id IS NOT NULL OR COALESCE(ds.student_response_date, ds.updated_at, ds.created_at) <= NOW()) THEN 1 ELSE 0 END) AS total,
            SUM(CASE WHEN al.status = "present" THEN 1 ELSE 0 END) AS present,
            SUM(CASE WHEN al.status = "late" THEN 1 ELSE 0 END) AS late,
            SUM(CASE WHEN (al.log_id IS NULL OR al.status = "absent") AND COALESCE(ds.student_response_date, ds.updated_at, ds.created_at) <= NOW() THEN 1 ELSE 0 END) AS absent
         FROM duty_schedules ds
         JOIN applications a ON a.application_id = ds.application_id
         LEFT JOIN attendance_logs al ON al.log_id = (
             SELECT MAX(al2.log_id) FROM attendance_logs al2
             WHERE al2.duty_id = ds.duty_id
               AND al2.application_id = a.application_id
               AND al2.created_at BETWEEN :period_start AND :period_end
         )
         WHERE ds.status = "deployed" ' . $termFilterSql . $officeFilter['sql']
    );
    $attendanceSummaryStmt->execute(array_merge(['period_start' => $periodStart, 'period_end' => $periodEnd], $termParams, $officeFilter['params']));
    $attendanceSummary = $attendanceSummaryStmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'present' => 0, 'late' => 0, 'absent' => 0];
} else {
    $attendanceSummaryStmt = $pdo->prepare(
        'SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN al.status = "present" THEN 1 ELSE 0 END) AS present,
            SUM(CASE WHEN al.status = "late" THEN 1 ELSE 0 END) AS late,
            SUM(CASE WHEN al.status = "absent" THEN 1 ELSE 0 END) AS absent
         FROM attendance_logs al
         LEFT JOIN applications a ON a.application_id = al.application_id
         WHERE al.created_at BETWEEN :period_start AND :period_end' . $officeFilter['sql']
    );
    $attendanceSummaryStmt->execute(array_merge([
        'period_start' => $periodStart,
        'period_end' => $periodEnd,
    ], $officeFilter['params']));
    $attendanceSummary = $attendanceSummaryStmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'present' => 0, 'late' => 0, 'absent' => 0];
}

$months = [];
for ($month = 1; $month <= 12; $month++) {
    $stmt = $pdo->prepare(
        'SELECT COALESCE(SUM(TIMESTAMPDIFF(SECOND, al.clock_in_time, al.clock_out_time) / 3600), 0)
         FROM attendance_logs al
         LEFT JOIN applications a ON a.application_id = al.application_id
         WHERE YEAR(al.created_at) = YEAR(CURDATE())
           AND MONTH(al.created_at) = :month
           AND al.clock_in_time IS NOT NULL
           AND al.clock_out_time IS NOT NULL' . $officeFilter['sql']
    );
    $stmt->execute(array_merge(['month' => $month], $officeFilter['params']));
    $hours = (float) $stmt->fetchColumn();
    $months[] = ['label' => date('M', mktime(0, 0, 0, $month, 1)), 'h' => (int) round($hours)];
}
$max_h = max(array_column($months, 'h')) ?: 1;

$officesStmt = $pdo->prepare(
    'SELECT COALESCE(a.preferred_office, "Unassigned") AS name,
            COUNT(*) AS count
     FROM attendance_logs al
     LEFT JOIN applications a ON a.application_id = al.application_id
     WHERE al.created_at BETWEEN :period_start AND :period_end' . $officeFilter['sql'] . '
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

// Recent student reports for admin history
$reportsStmt = $pdo->prepare(
    'SELECT sr.*, CONCAT(COALESCE(u.first_name, ""), CASE WHEN u.first_name IS NOT NULL AND u.last_name IS NOT NULL THEN " " ELSE "" END, COALESCE(u.last_name, "")) AS reporter_name, COALESCE(sp.office_name, a.preferred_office, "Unassigned") AS reporter_office
     FROM student_reports sr
     LEFT JOIN users u ON u.user_id = sr.reporter_id
     LEFT JOIN supervisors sp ON sp.user_id = sr.reporter_id
     LEFT JOIN applications a ON a.application_id = sr.application_id
     ORDER BY sr.created_at DESC
     LIMIT 200'
);
$reportsStmt->execute();
$student_reports = $reportsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$activeStudents = (int) $pdo->query('SELECT COUNT(*) FROM students WHERE is_enrolled = 1')->fetchColumn();
$monthlyHoursTotal = (int) ($months[(int) date('n') - 1]['h'] ?? 0);
$avgRatingStmt = $pdo->query('SELECT COALESCE(AVG((performance_rating + reliability_rating + professionalism_rating) / 3), 0) FROM evaluations WHERE performance_rating IS NOT NULL');
$avgRating = (float) $avgRatingStmt->fetchColumn();
$attendanceRate = 0;
if (!empty($attendance_data)) {
    $allTotal = array_sum(array_map(static fn($row) => (int) $row['total'], $attendance_data));
    $allGood = array_sum(array_map(static fn($row) => (int) $row['present'] + (int) $row['late'], $attendance_data));
    $attendanceRate = $allTotal > 0 ? (int) round(($allGood / $allTotal) * 100) : 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports &amp; Analytics | NU SA System</title>
    <style>
        /* ============================================================
           CSS VARIABLES — Design System
        ============================================================ */
        :root {
            --clr-white:         #FFFFFF;
            --clr-bg:            #F9FAFB;
            --clr-border:        #E5E7EB;
            --clr-border-input:  #D1D5DC;
            --clr-text-primary:  #101828;
            --clr-text-body:     #364153;
            --clr-text-muted:    #4A5565;
            --clr-text-dark:     #0A0A0A;

            --clr-blue:          #155DFC;
            --clr-blue-dark:     #1447E6;
            --clr-blue-bg:       #DBEAFE;
            --clr-blue-light:    #DBEAFE;

            --clr-green:         #00A63E;
            --clr-green-bg:      #DCFCE7;

            --clr-purple:        #9810FA;
            --clr-purple-bg:     #F3E8FF;

            --clr-orange:        #F54900;
            --clr-orange-bg:     #FFEDD4;

            --clr-amber:         #D08700;
            --clr-red:           #E7000B;
            --clr-alert:         #FB2C36;

            --clr-grey-bar:      #4A5565;

            --grad-brand:        linear-gradient(135deg, #155DFC 0%, #9810FA 100%);
            --grad-blue-panel:   linear-gradient(169.04deg, #155DFC 0%, #1447E6 100%);

            --shadow-sm: 0 1px 3px rgba(0,0,0,.10), 0 1px 2px rgba(0,0,0,.10);

            --sidebar-width: 256px;

            --fs-xs:   12px;
            --fs-sm:   14px;
            --fs-base: 16px;
            --fs-md:   18px;
            --fs-lg:   24px;
            --fs-xl:   30px;

            --sp-4:    4px;
            --sp-8:    8px;
            --sp-12:   12px;
            --sp-16:   16px;
            --sp-24:   24px;
            --sp-32:   32px;

            --radius-xs:   4px;
            --radius-sm:   10px;
            --radius-md:   16.4px;
            --radius-pill: 9999px;
        }

        /* ============================================================
           RESET & BASE
        ============================================================ */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html { font-size: 16px; }
        body {
            font-family: Arial, sans-serif;
            background: var(--clr-bg);
            color: var(--clr-text-primary);
            min-height: 100vh;
            display: flex;
        }
        a { text-decoration: none; color: inherit; }
        button { cursor: pointer; font-family: inherit; border: none; background: none; }
        img { display: block; max-width: 100%; }
        ul { list-style: none; }

        /* ============================================================
           APP LAYOUT
        ============================================================ */
        .app { display: flex; width: 100%; min-height: 100vh; }

        /* ============================================================
           SIDEBAR
        ============================================================ */
        .sidebar {
            width: var(--sidebar-width);
            min-height: 100vh;
            background: var(--clr-white);
            border-right: 1px solid var(--clr-border);
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
            position: sticky;
            top: 0;
            height: 100vh;
            overflow-y: auto;
            z-index: 100;
        }

        .sidebar__header {
            height: 89px;
            border-bottom: none;
            padding: 0;
            flex-shrink: 0;
        }

        .sidebar__brand {
            display: flex;
            align-items: center;
            gap: var(--sp-12);
            padding: var(--sp-24) var(--sp-24) 20px;
            border-bottom: 1px solid var(--clr-border);
        }

        .sidebar__logo {
            width: 40px;
            height: 40px;
            border-radius: var(--radius-sm);
            background: var(--grad-brand);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .sidebar__logo-text {
            font-size: var(--fs-md);
            font-weight: bold;
            color: var(--clr-white);
            line-height: 28px;
        }

        .sidebar__brand-info { display: flex; flex-direction: column; }

        .sidebar__app-name {
            font-size: var(--fs-base);
            font-weight: bold;
            color: var(--clr-text-primary);
            line-height: 24px;
        }

        .sidebar__app-sub {
            font-size: var(--fs-xs);
            color: var(--clr-text-muted);
            line-height: 16px;
        }

        .sidebar__nav {
            flex: 1;
            padding: var(--sp-16) var(--sp-16) 0;
        }

        .nav__list { display: flex; flex-direction: column; gap: var(--sp-4); }
        .nav__item { display: block; }

        .nav__link {
            display: flex;
            align-items: center;
            gap: var(--sp-12);
            height: 48px;
            padding: 0 var(--sp-16);
            border-radius: var(--radius-sm);
            transition: background 0.15s;
        }
        .nav__link:hover { background: var(--clr-bg); }
        .nav__link--active { background: var(--clr-blue); }
        .nav__link--active .nav__label { color: var(--clr-white); }

        .nav__icon { width: 20px; height: 20px; flex-shrink: 0; display: flex; align-items: center; }
        .nav__icon svg { width: 100%; height: 100%; }

        .nav__label {
            font-size: var(--fs-base);
            color: var(--clr-text-body);
            line-height: 24px;
            flex: 1;
            white-space: nowrap;
        }

        .nav__badge {
            background: var(--clr-blue-bg);
            color: var(--clr-blue);
            font-size: var(--fs-xs);
            font-weight: bold;
            padding: 2px 8px;
            border-radius: var(--radius-pill);
            height: 20px;
            display: flex;
            align-items: center;
        }

        .sidebar__footer {
            border-top: 1px solid var(--clr-border);
            padding: 17px var(--sp-16) var(--sp-16);
            flex-shrink: 0;
        }

        /* Hamburger */
        .hamburger {
            display: none;
            flex-direction: column;
            gap: 5px;
            width: 36px;
            height: 36px;
            align-items: center;
            justify-content: center;
            border-radius: var(--radius-sm);
            transition: background 0.15s;
            flex-shrink: 0;
        }
        .hamburger:hover { background: var(--clr-bg); }
        .hamburger__bar {
            width: 20px;
            height: 2px;
            background: var(--clr-text-muted);
            border-radius: 2px;
            transition: transform 0.25s, opacity 0.25s;
        }
        .hamburger[aria-expanded="true"] .hamburger__bar:nth-child(1) { transform: translateY(7px) rotate(45deg); }
        .hamburger[aria-expanded="true"] .hamburger__bar:nth-child(2) { opacity: 0; }
        .hamburger[aria-expanded="true"] .hamburger__bar:nth-child(3) { transform: translateY(-7px) rotate(-45deg); }

        .sidebar-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.4);
            z-index: 99;
        }
        .sidebar-overlay--hidden  { display: none; }
        .sidebar-overlay--visible { display: block; }

        /* ============================================================
           MAIN
        ============================================================ */
          .main { flex: 1; min-width: 0; display: flex; flex-direction: column; }
          /* ============================================================
              TOP BAR
          ============================================================ */
          .topbar { box-shadow: var(--shadow-sm); height: 89px; padding: 0 var(--sp-32); display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; position: sticky; top: 0; z-index: 50; }

        .topbar__left { display: flex; align-items: center; gap: var(--sp-12); }

        .topbar__heading { display: flex; flex-direction: column; gap: var(--sp-4); }

        .topbar__title {
            font-size: var(--fs-lg);
            font-weight: bold;
            color: var(--clr-text-primary);
            line-height: 32px;
        }

        .topbar__subtitle {
            font-size: var(--fs-sm);
            color: var(--clr-text-muted);
            line-height: 20px;
        }

        .topbar__right { display: flex; align-items: center; gap: var(--sp-12); }

        .topbar__notif {
            position: relative;
            width: 36px;
            height: 36px;
            border-radius: var(--radius-pill);
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.15s;
        }
        .topbar__notif:hover { background: var(--clr-bg); }
        .topbar__notif svg { width: 20px; height: 20px; }
        .topbar__notif-dot {
            position: absolute;
            top: 4px;
            right: 4px;
            width: 8px;
            height: 8px;
            background: var(--clr-alert);
            border-radius: 50%;
        }

        .topbar__user { display: flex; align-items: center; gap: var(--sp-12); }
        .topbar__user-info { text-align: right; display: flex; flex-direction: column; }
        .topbar__user-name { font-size: var(--fs-sm); color: var(--clr-text-primary); line-height: 20px; }
        .topbar__user-role { font-size: var(--fs-xs); color: var(--clr-text-muted); line-height: 16px; }

        .topbar__avatar {
            width: 40px;
            height: 40px;
            border-radius: var(--radius-pill);
            background: var(--grad-brand);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .topbar__avatar svg { width: 20px; height: 20px; }

        /* ============================================================
           PAGE CONTENT
        ============================================================ */
        .page-content {
            flex: 1;
            padding: var(--sp-32);
            display: flex;
            flex-direction: column;
            gap: var(--sp-24);
        }

        /* ============================================================
           PAGE HEADER ROW
        ============================================================ */
        .page-header-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: var(--sp-16);
            flex-wrap: wrap;
            min-height: 52px;
        }

        .page-header-row__left { display: flex; flex-direction: column; gap: var(--sp-4); }

        .page-header-row__title {
            font-size: var(--fs-md);
            font-weight: bold;
            color: var(--clr-text-primary);
            line-height: 28px;
        }

        .page-header-row__subtitle {
            font-size: var(--fs-sm);
            color: var(--clr-text-muted);
            line-height: 20px;
            white-space: nowrap;
        }

        .page-header-row__actions {
            display: flex;
            align-items: center;
            gap: var(--sp-12);
        }

        /* Period dropdown */
        .period-select {
            height: 41px;
            width: 152px;
            border: 1px solid var(--clr-border-input);
            border-radius: var(--radius-sm);
            padding: 0 var(--sp-12);
            font-family: Arial, sans-serif;
            font-size: var(--fs-base);
            color: var(--clr-text-dark);
            background: var(--clr-white);
            outline: none;
            cursor: pointer;
            transition: border-color 0.15s;
        }
        .period-select:focus { border-color: var(--clr-blue); }

        /* Export button */
        .btn-export-all {
            height: 41px;
            padding: 0 var(--sp-16);
            background: var(--clr-blue);
            color: var(--clr-white);
            border-radius: var(--radius-sm);
            font-size: var(--fs-base);
            line-height: 24px;
            display: flex;
            align-items: center;
            gap: var(--sp-8);
            white-space: nowrap;
            transition: opacity 0.15s;
        }
        .btn-export-all:hover { opacity: .88; }
        .btn-export-all svg { width: 16px; height: 16px; flex-shrink: 0; }

        /* ============================================================
           OVERVIEW STAT CARDS (4 column grid)
        ============================================================ */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: var(--sp-16);
        }

        .stat-card {
            background: var(--clr-white);
            border: 1px solid var(--clr-border);
            border-radius: var(--radius-md);
            padding: var(--sp-24);
            display: flex;
            flex-direction: column;
            gap: 0;
            min-height: 198px;
            position: relative;
        }

        .stat-card__icon-wrap {
            width: 48px;
            height: 48px;
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            margin-bottom: 16px;
        }
        .stat-card__icon-wrap svg { width: 24px; height: 24px; }
        .stat-card__icon-wrap--blue   { background: var(--clr-blue-bg); }
        .stat-card__icon-wrap--green  { background: var(--clr-green-bg); }
        .stat-card__icon-wrap--purple { background: var(--clr-purple-bg); }
        .stat-card__icon-wrap--orange { background: var(--clr-orange-bg); }

        .stat-card__value {
            font-size: var(--fs-xl);
            font-weight: bold;
            color: var(--clr-text-primary);
            line-height: 36px;
            margin-bottom: 4px;
        }

        .stat-card__label {
            font-size: var(--fs-sm);
            color: var(--clr-text-muted);
            line-height: 20px;
            margin-bottom: 4px;
        }

        .stat-card__delta {
            font-size: var(--fs-xs);
            color: var(--clr-green);
            line-height: 16px;
        }

        /* ============================================================
           REPORT TYPE TABS
        ============================================================ */
        .report-tabs {
            display: flex;
            gap: var(--sp-12);
            flex-wrap: wrap;
        }

        .report-tab {
            height: 48px;
            padding: 0 var(--sp-16);
            border-radius: var(--radius-sm);
            font-size: var(--fs-base);
            line-height: 24px;
            display: flex;
            align-items: center;
            white-space: nowrap;
            transition: background 0.15s, color 0.15s;
        }
        .report-tab--active   { background: var(--clr-blue);  color: var(--clr-white); }
        .report-tab--inactive { background: #F3F4F6; color: var(--clr-text-body); }
        .report-tab--inactive:hover { background: #E5E7EB; }

        /* ============================================================
           ATTENDANCE REPORT TABLE CARD
        ============================================================ */
        .table-card {
            background: var(--clr-white);
            border: 1px solid var(--clr-border);
            border-radius: var(--radius-md);
            overflow: hidden;
        }

        .table-card__header {
            border-bottom: 1px solid var(--clr-border);
            padding: var(--sp-24);
            min-height: 77px;
            display: flex;
            align-items: center;
        }

        .table-card__title {
            font-size: var(--fs-md);
            font-weight: bold;
            color: var(--clr-text-primary);
            line-height: 28px;
        }

        .att-table-wrap { overflow-x: auto; }

        .att-table {
            width: 100%;
            min-width: 800px;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .att-table col.col-name    { width: 24%; }
        .att-table col.col-days    { width: 17%; }
        .att-table col.col-present { width: 15%; }
        .att-table col.col-late    { width: 12%; }
        .att-table col.col-absent  { width: 14%; }
        .att-table col.col-pct     { width: 18%; }

        .att-table thead th {
            background: var(--clr-bg);
            border-bottom: 1px solid var(--clr-border);
            padding: var(--sp-16) var(--sp-24);
            text-align: left;
            font-size: var(--fs-sm);
            font-weight: bold;
            color: var(--clr-text-primary);
            height: 52.5px;
        }

        .att-table tbody tr { border-bottom: 1px solid var(--clr-border); }
        .att-table tbody tr:last-child { border-bottom: none; }

        .att-table tbody td {
            padding: 0 var(--sp-24);
            height: 57px;
            vertical-align: middle;
            font-size: var(--fs-base);
            color: var(--clr-text-primary);
            line-height: 24px;
        }

        .report-actions {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }

        .report-actions form {
            display: inline-flex;
            margin: 0;
        }

        .report-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            height: 30px;
            padding: 0 10px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 700;
            line-height: 1;
            white-space: nowrap;
        }

        .report-action--light {
            border: 1px solid var(--clr-border-input);
            background: #fff;
            color: var(--clr-text-primary);
        }

        .report-action--primary {
            background: var(--clr-blue);
            color: #fff;
        }

        .report-action--danger {
            border: 0;
            background: #111827;
            color: #fff;
        }

        .report-modal {
            position: fixed;
            inset: 0;
            z-index: 300;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: var(--sp-24);
        }

        .report-modal[hidden] { display: none; }

        .report-modal__backdrop {
            position: absolute;
            inset: 0;
            background: rgba(15, 23, 42, 0.55);
        }

        .report-modal__dialog {
            position: relative;
            width: min(760px, 100%);
            max-height: min(84vh, 900px);
            overflow: auto;
            background: var(--clr-white);
            border-radius: 18px;
            box-shadow: 0 24px 80px rgba(15, 23, 42, 0.28);
            border: 1px solid var(--clr-border);
        }

        .report-modal__header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: var(--sp-16);
            padding: var(--sp-24);
            border-bottom: 1px solid var(--clr-border);
        }

        .report-modal__eyebrow {
            font-size: var(--fs-xs);
            text-transform: uppercase;
            letter-spacing: .08em;
            color: var(--clr-text-muted);
            margin-bottom: 6px;
        }

        .report-modal__title {
            font-size: var(--fs-lg);
            font-weight: 800;
            color: var(--clr-text-primary);
            line-height: 1.2;
        }

        .report-modal__close {
            width: 36px;
            height: 36px;
            border-radius: var(--radius-pill);
            background: #F3F4F6;
            color: var(--clr-text-body);
            font-size: 22px;
            line-height: 1;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .report-modal__body {
            padding: var(--sp-24);
            display: flex;
            flex-direction: column;
            gap: var(--sp-24);
        }

        .report-modal__grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: var(--sp-16);
        }

        .report-modal__grid strong,
        .report-modal__notes strong {
            display: block;
            font-size: var(--fs-sm);
            color: var(--clr-text-muted);
            margin-bottom: 6px;
        }

        .report-modal__grid div div,
        .report-modal__notes-text {
            font-size: var(--fs-base);
            color: var(--clr-text-primary);
            line-height: 1.6;
        }

        .report-modal__notes {
            padding: var(--sp-16);
            border: 1px solid var(--clr-border);
            border-radius: 14px;
            background: #FAFAFB;
        }

        .report-modal__notes-text {
            white-space: pre-wrap;
        }

        .report-modal__footer {
            padding: 0 var(--sp-24) var(--sp-24);
            display: flex;
            justify-content: flex-end;
        }

        .report-modal__open {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            height: 40px;
            padding: 0 14px;
            border-radius: 10px;
            background: var(--clr-blue);
            color: var(--clr-white);
            font-weight: 700;
            font-size: var(--fs-sm);
        }

        .att-present { font-weight: bold; color: var(--clr-green); }
        .att-late    { font-weight: bold; color: var(--clr-amber); }
        .att-absent  { font-weight: bold; color: var(--clr-red); }
        .att-pct     { font-weight: bold; color: var(--clr-text-primary); }

        /* ============================================================
           CHARTS ROW (Monthly Trend + Office Distribution)
        ============================================================ */
        .charts-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: var(--sp-24);
        }

        .chart-card {
            background: var(--clr-white);
            border: 1px solid var(--clr-border);
            border-radius: var(--radius-md);
            padding: 25px;
            display: flex;
            flex-direction: column;
            gap: var(--sp-16);
        }

        .chart-card__title {
            font-size: var(--fs-md);
            font-weight: bold;
            color: var(--clr-text-primary);
            line-height: 28px;
        }

        /* BAR CHART */
        .bar-chart {
            display: flex;
            align-items: flex-end;
            gap: var(--sp-12);
            height: 256px;
        }

        .bar-chart__bar {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: var(--sp-8);
            height: 100%;
            justify-content: flex-end;
        }

        .bar-chart__fill {
            width: 100%;
            max-width: 64px;
            background: var(--clr-blue);
            border-radius: 10px 10px 0 0;
            flex-shrink: 0;
        }

        .bar-chart__label {
            font-size: var(--fs-xs);
            color: var(--clr-text-muted);
            line-height: 16px;
            white-space: nowrap;
        }

        /* DISTRIBUTION BARS */
        .dist-bars { display: flex; flex-direction: column; gap: var(--sp-16); }

        .dist-bar { display: flex; flex-direction: column; gap: var(--sp-8); }

        .dist-bar__header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            height: 20px;
        }

        .dist-bar__name { font-size: var(--fs-sm); color: var(--clr-text-primary); line-height: 20px; }
        .dist-bar__count { font-size: var(--fs-sm); color: var(--clr-text-muted); line-height: 20px; white-space: nowrap; }

        .dist-bar__track {
            height: 12px;
            background: var(--clr-border);
            border-radius: var(--radius-pill);
            overflow: hidden;
        }

        .dist-bar__fill {
            height: 100%;
            border-radius: var(--radius-pill);
        }
        .dist-bar__fill--blue   { background: var(--clr-blue); }
        .dist-bar__fill--green  { background: var(--clr-green); }
        .dist-bar__fill--purple { background: var(--clr-purple); }
        .dist-bar__fill--orange { background: var(--clr-orange); }
        .dist-bar__fill--grey   { background: var(--clr-grey-bar); }

        /* ============================================================
           PRINTABLE REPORTS PANEL (Blue gradient)
        ============================================================ */
        .print-panel {
            background: var(--grad-blue-panel);
            border-radius: var(--radius-md);
            padding: var(--sp-24);
            display: flex;
            flex-direction: column;
            gap: var(--sp-16);
        }

        .print-panel__title {
            font-size: var(--fs-md);
            font-weight: bold;
            color: var(--clr-white);
            line-height: 28px;
        }

        .print-panel__desc {
            font-size: var(--fs-sm);
            color: var(--clr-blue-light);
            line-height: 20px;
        }

        .print-cards {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: var(--sp-16);
        }

        .print-card {
            background: rgba(255,255,255,.10);
            border-radius: var(--radius-sm);
            padding: var(--sp-16);
            display: flex;
            flex-direction: column;
            gap: var(--sp-8);
        }

        .print-card__title {
            font-size: var(--fs-base);
            font-weight: bold;
            color: var(--clr-white);
            line-height: 24px;
        }

        .print-card__desc {
            font-size: var(--fs-sm);
            color: var(--clr-blue-light);
            line-height: 20px;
        }

        .print-card__btn {
            height: 36px;
            background: var(--clr-white);
            border-radius: var(--radius-sm);
            font-size: var(--fs-sm);
            color: var(--clr-blue);
            line-height: 20px;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: var(--sp-8);
            transition: opacity 0.15s;
            width: 100%;
        }
        .print-card__btn:hover { opacity: .85; }
        .print-card__btn svg { width: 16px; height: 16px; flex-shrink: 0; }

        /* ============================================================
           RESPONSIVE — TABLET (≤1024px)
        ============================================================ */
        @media (max-width: 1024px) {
            .sidebar {
                position: fixed;
                left: -100%;
                top: 0;
                height: 100vh;
                transition: left 0.28s ease;
                z-index: 200;
            }
            .sidebar--open { left: 0; }
            .hamburger { display: flex; }
            .topbar { padding: 0 var(--sp-16); }
            .page-content { padding: var(--sp-16); }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .charts-row { grid-template-columns: 1fr; }
            .print-cards { grid-template-columns: 1fr; }
            .topbar__user-info { display: none; }
        }

        /* ============================================================
           RESPONSIVE — MOBILE (≤768px)
        ============================================================ */
        @media (max-width: 768px) {
            .topbar__title { font-size: 18px; }
            .topbar__subtitle { font-size: var(--fs-xs); }
            .page-content { padding: var(--sp-12); gap: var(--sp-16); }
            .stats-grid { grid-template-columns: 1fr 1fr; gap: var(--sp-12); }
            .stat-card { min-height: auto; padding: var(--sp-16); }
            .page-header-row { flex-direction: column; align-items: flex-start; }
            .report-tabs { gap: var(--sp-8); }
            .report-tab { font-size: var(--fs-sm); padding: 0 var(--sp-12); }
            .print-cards { grid-template-columns: 1fr; }
            .print-panel { padding: var(--sp-16); }
        }
    </style>
    <link rel="stylesheet" href="../assets/css/sams-shell.css" />
</head>
<body>

<div class="sidebar-overlay sidebar-overlay--hidden" id="sidebarOverlay"></div>

<div class="app">
    <?php $activeAdminNav = 'reports'; $pendingApplications = (int) $applicationBadgeCount; include __DIR__ . '/_sidebar.php'; ?>

    <!-- ================================================================
         MAIN
    ================================================================ -->
    <main class="main">

        <!-- Top Bar -->
        <header class="topbar">
            <div class="topbar__left">
                <button class="hamburger" id="hamburgerBtn" type="button" aria-expanded="false" aria-controls="sidebar" aria-label="Toggle navigation">
                    <span class="hamburger__bar"></span>
                    <span class="hamburger__bar"></span>
                    <span class="hamburger__bar"></span>
                </button>
                <div class="topbar__heading">
                    <h1 class="topbar__title">Reports &amp; Analytics</h1>
                    <p class="topbar__subtitle">NU Lipa - Student Development and Activities Office</p>
                </div>
            </div>
            <div class="topbar__right">
                <a href="#" class="topbar__notif" aria-label="Notifications">
                    <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M10 2a6 6 0 00-6 6v3.586l-.707.707A1 1 0 004 14h12a1 1 0 00.707-1.707L16 11.586V8a6 6 0 00-6-6zM10 18a3 3 0 01-3-3h6a3 3 0 01-3 3z" fill="#4A5565"/>
                    </svg>
                    <span class="topbar__notif-dot" aria-label="New notifications"></span>
                </a>
                <div class="topbar__user">
                    <div class="topbar__user-info">
                        <span class="topbar__user-name">Zaira Joy S. Enayo</span>
                        <span class="topbar__user-role">SDAO Head</span>
                    </div>
                    <div class="topbar__avatar" aria-hidden="true">
                        <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <circle cx="10" cy="7" r="4" fill="white" opacity=".9"/>
                            <path d="M2 17c0-3.314 3.582-6 8-6s8 2.686 8 6" fill="white" opacity=".9"/>
                        </svg>
                    </div>
                </div>
            </div>
        </header>

        <!-- Page Content -->
        <section class="page-content" aria-label="Reports and Analytics content">

            <?php if ($reportsFlash !== ''): ?>
                <div style="margin:0 0 16px;padding:14px 16px;border-radius:12px;border:1px solid #bbf7d0;background:#ecfdf5;color:#047857;font-weight:700;">
                    <?= htmlspecialchars($reportsFlash) ?>
                </div>
            <?php endif; ?>

            <!-- Page Header Row -->
            <div class="page-header-row">
                <div class="page-header-row__left">
                    <h2 class="page-header-row__title">Reports &amp; Analytics</h2>
                    <p class="page-header-row__subtitle">Comprehensive insights and downloadable reports for <?= htmlspecialchars($periodLabel) ?> · <?= htmlspecialchars($officeLabel) ?></p>
                </div>
                <div class="page-header-row__actions">
                    <form method="get" style="display:inline">
                        <select class="period-select" name="period" aria-label="Select report period" onchange="this.form.submit()">
                            <option value="month"<?= $periodKey === 'month' ? ' selected' : '' ?>>This Month</option>
                            <option value="last_month"<?= $periodKey === 'last_month' ? ' selected' : '' ?>>Last Month</option>
                            <option value="semester"<?= $periodKey === 'semester' ? ' selected' : '' ?>>This Semester</option>
                            <option value="trimester1"<?= $periodKey === 'trimester1' ? ' selected' : '' ?>>Trimester 1</option>
                            <option value="trimester2"<?= $periodKey === 'trimester2' ? ' selected' : '' ?>>Trimester 2</option>
                            <option value="trimester3"<?= $periodKey === 'trimester3' ? ' selected' : '' ?>>Trimester 3</option>
                            <option value="year"<?= $periodKey === 'year' ? ' selected' : '' ?>>This Year</option>
                        </select>
                        <select class="period-select" name="mode" aria-label="Report mode" onchange="this.form.submit()" style="margin-left:8px">
                            <option value="scheduled"<?= $mode === 'scheduled' ? ' selected' : '' ?>>Scheduled duties</option>
                            <option value="logs"<?= $mode === 'logs' ? ' selected' : '' ?>>Log-only</option>
                        </select>
                        <select class="period-select" name="office" aria-label="Office filter" onchange="this.form.submit()" style="margin-left:8px;min-width:170px">
                            <option value="all"<?= $officeKey === 'all' ? ' selected' : '' ?>>All Offices</option>
                            <?php foreach ($officeOptions as $officeName): ?>
                                <?php $officeName = (string) $officeName; ?>
                                <option value="<?= htmlspecialchars($officeName) ?>"<?= $officeKey === $officeName ? ' selected' : '' ?>><?= htmlspecialchars($officeName) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                    <button class="btn-export-all" type="button">
                        <svg viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M8 2v8M8 10L5 7M8 10l3-3" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M2 12v2a1 1 0 001 1h10a1 1 0 001-1v-2" stroke="white" stroke-width="1.5" stroke-linecap="round"/>
                        </svg>
                        Export All Reports
                    </button>
                    <button class="btn-export-all" type="button" onclick="location.reload()" style="margin-left:8px;background:#6B7280">Refresh</button>
                    <label style="display:inline-flex;align-items:center;margin-left:8px;font-size:12px;color:var(--clr-text-muted)">
                        <input type="checkbox" id="auto-refresh" style="margin-right:6px"> Auto-refresh (60s)
                    </label>
                </div>
            </div>

            <!-- Overview Stat Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-card__icon-wrap stat-card__icon-wrap--blue">
                        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <circle cx="12" cy="8" r="5" stroke="#155DFC" stroke-width="1.5"/>
                            <path d="M3 20c0-4 4-7 9-7s9 3 9 7" stroke="#155DFC" stroke-width="1.5" stroke-linecap="round"/>
                        </svg>
                    </div>
                    <div class="stat-card__value"><?= (int) $activeStudents ?></div>
                    <div class="stat-card__label">Total Student Assistants</div>
                    <div class="stat-card__delta">+3 from last month</div>
                </div>
                <div class="stat-card">
                    <div class="stat-card__icon-wrap stat-card__icon-wrap--green">
                        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <circle cx="12" cy="12" r="9" stroke="#00A63E" stroke-width="1.5"/>
                            <path d="M12 7v5l3 3" stroke="#00A63E" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>
                    <div class="stat-card__value"><?= number_format($monthlyHoursTotal) ?></div>
                    <div class="stat-card__label">Total Hours (<?= htmlspecialchars($periodLabel) ?>)</div>
                    <div class="stat-card__delta">+12% increase</div>
                </div>
                <div class="stat-card">
                    <div class="stat-card__icon-wrap stat-card__icon-wrap--purple">
                        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M3 17l4-7 4 5 3-3 4 3" stroke="#9810FA" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>
                    <div class="stat-card__value"><?= number_format($avgRating, 1) ?>/5</div>
                    <div class="stat-card__label">Avg. Performance Rating</div>
                    <div class="stat-card__delta">+0.2 improvement</div>
                </div>
                <div class="stat-card">
                    <div class="stat-card__icon-wrap stat-card__icon-wrap--orange">
                        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <rect x="3" y="4" width="18" height="17" rx="2" stroke="#F54900" stroke-width="1.5"/>
                            <path d="M8 2v4M16 2v4" stroke="#F54900" stroke-width="1.5" stroke-linecap="round"/>
                            <path d="M3 10h18" stroke="#F54900" stroke-width="1.3"/>
                        </svg>
                    </div>
                    <div class="stat-card__value"><?= (int) $attendanceRate ?>%</div>
                    <div class="stat-card__label"><?= htmlspecialchars($attendanceRateLabel) ?> (<?= htmlspecialchars($periodLabel) ?>)</div>
                    <div class="stat-card__delta">+1.5% improvement</div>
                </div>
            </div>

            <!-- Report Type Tabs -->
            <div class="report-tabs" role="tablist" aria-label="Report types">
                <button class="report-tab report-tab--active" id="tabAtt" type="button" role="tab" aria-selected="true" aria-controls="panelAtt">
                    Attendance Report
                </button>
                <button class="report-tab report-tab--inactive" id="tabPerf" type="button" role="tab" aria-selected="false" aria-controls="panelPerf">
                    Performance Report
                </button>
                <button class="report-tab report-tab--inactive" id="tabHours" type="button" role="tab" aria-selected="false" aria-controls="panelHours">
                    Duty Hours Report
                </button>
                <button class="report-tab report-tab--inactive" id="tabReports" type="button" role="tab" aria-selected="false" aria-controls="panelReports">
                    Student Reports
                </button>
            </div>

            <!-- Attendance Report Table -->
            <div id="panelAtt" role="tabpanel" aria-labelledby="tabAtt">
                <div class="table-card">
                    <div class="table-card__header">
                        <h2 class="table-card__title">Detailed Attendance Report - <?= htmlspecialchars($periodLabel) ?> Live Data</h2>
                        <p style="margin-top:8px;color:var(--clr-text-muted);font-size:12px">This view uses <?= htmlspecialchars($attendanceUnitLabel) ?>, so absent means <?= $mode === 'scheduled' ? 'a duty was expected but no matching log was found' : 'no attendance log exists' ?>.</p>
                    </div>
                    <div class="att-table-wrap">
                        <table class="att-table" aria-label="Detailed attendance report for <?= htmlspecialchars($periodLabel) ?>">
                            <colgroup>
                                <col class="col-name">
                                <col style="width:18%">
                                <col class="col-present">
                                <col class="col-late">
                                <col class="col-absent">
                                <col style="width:12%">
                                <col class="col-pct">
                            </colgroup>
                            <thead>
                                <tr>
                                    <th scope="col">Student Name</th>
                                    <th scope="col">Office</th>
                                    <th scope="col"><?= htmlspecialchars($attendanceUnitLabel) ?></th>
                                    <th scope="col">Present</th>
                                    <th scope="col">Late</th>
                                    <th scope="col"><?= htmlspecialchars($absentLabel) ?></th>
                                    <th scope="col">Hours</th>
                                    <th scope="col">Attendance %</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($attendance_data as $row): ?>
                                <tr>
                                    <td><?= htmlspecialchars($row['name']) ?></td>
                                    <td><?= htmlspecialchars($row['office']) ?></td>
                                    <td><?= $row['total'] ?></td>
                                    <td class="att-present"><?= $row['present'] ?></td>
                                    <td class="att-late"><?= $row['late'] ?></td>
                                    <td class="att-absent"><?= $row['absent'] ?></td>
                                    <td><?= number_format((float) $row['hours'], 1) ?>h</td>
                                    <td class="att-pct"><?= $row['pct'] ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($attendance_data)): ?>
                                <tr>
                                    <td colspan="8" style="padding:24px;text-align:center;color:var(--clr-text-muted)">No attendance records found for <?= htmlspecialchars($periodLabel) ?>.</td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Performance Panel (hidden) -->
            <div id="panelPerf" role="tabpanel" aria-labelledby="tabPerf" hidden>
                <div class="table-card">
                    <div class="table-card__header">
                        <h2 class="table-card__title">Performance Report - Live Data</h2>
                    </div>
                    <div style="padding: 32px; text-align: center; color: var(--clr-text-muted); font-size: var(--fs-sm);">
                        Performance report data will appear here.
                    </div>
                </div>
            </div>

            <!-- Duty Hours Panel (hidden) -->
            <div id="panelHours" role="tabpanel" aria-labelledby="tabHours" hidden>
                <div class="table-card">
                    <div class="table-card__header">
                        <h2 class="table-card__title">Duty Hours Report - <?= htmlspecialchars($periodLabel) ?> Live Data</h2>
                    </div>
                    <div style="padding: 32px; text-align: center; color: var(--clr-text-muted); font-size: var(--fs-sm);">
                        Total duty hours for <?= htmlspecialchars($periodLabel) ?>: <strong><?= number_format($periodHoursTotal, 1) ?></strong>
                    </div>
                </div>
            </div>

            <!-- Student Reports Panel (admin history) -->
            <div id="panelReports" role="tabpanel" aria-labelledby="tabReports" hidden>
                <div class="table-card">
                    <div class="table-card__header">
                        <h2 class="table-card__title">Student Reports History</h2>
                    </div>
                    <div style="padding:16px;overflow:auto">
                        <table class="att-table" style="min-width:1100px">
                            <colgroup>
                                <col style="width:6%">
                                <col style="width:10%">
                                <col style="width:8%">
                                <col style="width:22%">
                                <col style="width:14%">
                                <col style="width:10%">
                                <col style="width:8%">
                                <col style="width:12%">
                                <col style="width:14%">
                            </colgroup>
                            <thead>
                                <tr>
                                    <th>Report</th>
                                    <th>Student</th>
                                    <th>Application</th>
                                    <th>Title / Notes</th>
                                    <th>Reporter</th>
                                    <th>Office</th>
                                    <th>Status</th>
                                    <th>Created</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($student_reports as $r): ?>
                                <tr>
                                    <td><?php echo (int)($r['report_id'] ?? 0); ?></td>
                                    <td><?php echo htmlspecialchars((string)($r['student_code'] ?? '-')); ?></td>
                                    <td><?php echo (int)($r['application_id'] ?? 0); ?></td>
                                    <td><?php echo '<strong>' . htmlspecialchars((string)($r['title'] ?? '-')) . '</strong><div style="color:var(--clr-text-muted);font-size:13px;margin-top:6px;white-space:pre-wrap">' . htmlspecialchars((string)($r['notes'] ?? '')) . '</div>'; ?></td>
                                    <td><?php echo htmlspecialchars((string)($r['reporter_name'] ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars((string)($r['reporter_office'] ?? 'Unassigned')); ?></td>
                                    <td><?php echo htmlspecialchars((string)($r['status'] ?? 'open')); ?></td>
                                    <td><?php echo htmlspecialchars((string)($r['created_at'] ?? '')); ?></td>
                                    <td>
                                        <div class="report-actions">
                                        <button
                                            type="button"
                                            class="report-preview-btn report-action report-action--light"
                                            data-report-id="<?php echo (int)($r['report_id'] ?? 0); ?>"
                                            data-student="<?php echo htmlspecialchars((string)($r['student_code'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?>"
                                            data-application="<?php echo (int)($r['application_id'] ?? 0); ?>"
                                            data-title="<?php echo htmlspecialchars((string)($r['title'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?>"
                                            data-notes="<?php echo htmlspecialchars((string)($r['notes'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                            data-reporter="<?php echo htmlspecialchars((string)($r['reporter_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                            data-office="<?php echo htmlspecialchars((string)($r['reporter_office'] ?? 'Unassigned'), ENT_QUOTES, 'UTF-8'); ?>"
                                            data-status="<?php echo htmlspecialchars((string)($r['status'] ?? 'open'), ENT_QUOTES, 'UTF-8'); ?>"
                                            data-created="<?php echo htmlspecialchars((string)($r['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                        >Preview</button>
                                        <a class="report-action report-action--primary" href="report_detail.php?report_id=<?php echo (int)($r['report_id'] ?? 0); ?>">Open</a>
                                        <!-- Delete moved to preview modal to keep list clean -->
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($student_reports)): ?><tr><td colspan="9" style="padding:24px">No reports yet.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div id="reportPreviewModal" class="report-modal" hidden aria-hidden="true">
                <div class="report-modal__backdrop" data-close-report-modal></div>
                <div class="report-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="reportPreviewTitle">
                    <div class="report-modal__header">
                        <div>
                            <div class="report-modal__eyebrow">Report Preview</div>
                            <h3 id="reportPreviewTitle" class="report-modal__title">Report #</h3>
                        </div>
                        <button type="button" class="report-modal__close" data-close-report-modal aria-label="Close preview">×</button>
                    </div>
                    <div class="report-modal__body">
                        <div class="report-modal__grid">
                            <div><strong>Student</strong><div id="reportPreviewStudent"></div></div>
                            <div><strong>Application</strong><div id="reportPreviewApplication"></div></div>
                            <div><strong>Reporter</strong><div id="reportPreviewReporter"></div></div>
                            <div><strong>Office</strong><div id="reportPreviewOffice"></div></div>
                            <div><strong>Status</strong><div id="reportPreviewStatus"></div></div>
                            <div><strong>Created</strong><div id="reportPreviewCreated"></div></div>
                        </div>
                        <div class="report-modal__notes">
                            <strong>Title / Notes</strong>
                            <div id="reportPreviewNotes" class="report-modal__notes-text"></div>
                        </div>
                    </div>
                    <div class="report-modal__footer">
                        <a id="reportPreviewOpenLink" class="report-modal__open" href="#">Open Full Report</a>
                        <form id="reportPreviewDeleteForm" method="post" action="#" style="margin-left:12px" onsubmit="return confirm('Delete this report permanently? This cannot be undone.')">
                            <?php echo sams_csrf_input_field(); ?>
                            <input type="hidden" name="action" value="delete">
                            <button type="submit" class="report-action report-action--danger">Delete</button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Charts Row: Monthly Trend + Office Distribution -->
            <div class="charts-row">

                <!-- Monthly Hours Trend (Bar Chart) -->
                <div class="chart-card">
                    <h2 class="chart-card__title">Monthly Hours Trend</h2>
                    <div class="bar-chart" role="img" aria-label="Bar chart of monthly duty hours from August to January">
                        <?php foreach ($months as $m): ?>
                        <?php $bar_h = (int)(($m['h'] / $max_h) * 224); ?>
                        <div class="bar-chart__bar">
                            <div class="bar-chart__fill" style="height:<?= $bar_h ?>px;" aria-label="<?= $m['label'] ?>: <?= $m['h'] ?> hours"></div>
                            <span class="bar-chart__label"><?= $m['label'] ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Office Distribution -->
                <div class="chart-card">
                    <h2 class="chart-card__title">Office Distribution</h2>
                    <div class="dist-bars">
                        <?php foreach ($offices as $office): ?>
                        <div class="dist-bar">
                            <div class="dist-bar__header">
                                <span class="dist-bar__name"><?= htmlspecialchars($office['name']) ?></span>
                                <span class="dist-bar__count"><?= $office['count'] ?> (<?= $office['pct'] ?>)</span>
                            </div>
                            <div class="dist-bar__track" role="progressbar" aria-valuenow="<?= $office['fill_pct'] ?>" aria-valuemin="0" aria-valuemax="100" aria-label="<?= htmlspecialchars($office['name']) ?>">
                                <div class="dist-bar__fill dist-bar__fill--<?= $office['color'] ?>" style="width:<?= $office['fill_pct'] ?>%"></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

            </div>

            <!-- Printable Duty Hours Reports Panel -->
            <div class="print-panel" role="region" aria-label="Printable Duty Hours Reports">
                <h2 class="print-panel__title">📄 Printable Duty Hours Reports</h2>
                <p class="print-panel__desc">Generate detailed reports for documentation and evaluation purposes</p>
                <div class="print-cards">
                    <div class="print-card">
                        <h3 class="print-card__title">Individual Report</h3>
                        <p class="print-card__desc">Detailed hours per student</p>
                        <button class="print-card__btn" type="button">
                            <svg viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M8 2v8M8 10L5 7M8 10l3-3" stroke="#155DFC" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/>
                                <path d="M2 12v2a1 1 0 001 1h10a1 1 0 001-1v-2" stroke="#155DFC" stroke-width="1.3" stroke-linecap="round"/>
                            </svg>
                            Generate PDF
                        </button>
                    </div>
                    <div class="print-card">
                        <h3 class="print-card__title">Office Summary</h3>
                        <p class="print-card__desc">Hours breakdown by office</p>
                        <button class="print-card__btn" type="button">
                            <svg viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M8 2v8M8 10L5 7M8 10l3-3" stroke="#155DFC" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/>
                                <path d="M2 12v2a1 1 0 001 1h10a1 1 0 001-1v-2" stroke="#155DFC" stroke-width="1.3" stroke-linecap="round"/>
                            </svg>
                            Generate PDF
                        </button>
                    </div>
                    <div class="print-card">
                        <h3 class="print-card__title">Period Report</h3>
                        <p class="print-card__desc">Custom date range report</p>
                        <button class="print-card__btn" type="button">
                            <svg viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M8 2v8M8 10L5 7M8 10l3-3" stroke="#155DFC" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/>
                                <path d="M2 12v2a1 1 0 001 1h10a1 1 0 001-1v-2" stroke="#155DFC" stroke-width="1.3" stroke-linecap="round"/>
                            </svg>
                            Generate PDF
                        </button>
                    </div>
                </div>
            </div>

        </section>
    </main>
</div><!-- /app -->

<script>
(function () {
    'use strict';

    /* ---- Hamburger / Sidebar ---- */
    var hamburger = document.getElementById('hamburgerBtn');
    var sidebar   = document.getElementById('sidebar');
    var overlay   = document.getElementById('sidebarOverlay');

    function openSidebar() {
        sidebar.classList.add('sidebar--open');
        overlay.classList.remove('sidebar-overlay--hidden');
        overlay.classList.add('sidebar-overlay--visible');
        hamburger.setAttribute('aria-expanded', 'true');
        document.body.style.overflow = 'hidden';
    }

    function closeSidebar() {
        sidebar.classList.remove('sidebar--open');
        overlay.classList.add('sidebar-overlay--hidden');
        overlay.classList.remove('sidebar-overlay--visible');
        hamburger.setAttribute('aria-expanded', 'false');
        document.body.style.overflow = '';
    }

    if (hamburger) hamburger.addEventListener('click', function () {
        sidebar.classList.contains('sidebar--open') ? closeSidebar() : openSidebar();
    });
    if (overlay) overlay.addEventListener('click', closeSidebar);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && sidebar.classList.contains('sidebar--open')) {
            closeSidebar();
            hamburger && hamburger.focus();
        }
    });

    /* ---- Report Type Tabs ---- */
    var tabs = [
        { btn: document.getElementById('tabAtt'),   panel: document.getElementById('panelAtt') },
        { btn: document.getElementById('tabPerf'),  panel: document.getElementById('panelPerf') },
        { btn: document.getElementById('tabHours'), panel: document.getElementById('panelHours') },
        { btn: document.getElementById('tabReports'), panel: document.getElementById('panelReports') }
    ];

    tabs.forEach(function (t) {
        if (!t.btn) return;
        t.btn.addEventListener('click', function () {
            tabs.forEach(function (item) {
                item.btn.classList.remove('report-tab--active');
                item.btn.classList.add('report-tab--inactive');
                item.btn.setAttribute('aria-selected', 'false');
                if (item.panel) item.panel.hidden = true;
            });
            t.btn.classList.remove('report-tab--inactive');
            t.btn.classList.add('report-tab--active');
            t.btn.setAttribute('aria-selected', 'true');
            if (t.panel) t.panel.hidden = false;
        });
    });

    /* ---- Report Preview Modal ---- */
    var reportModal = document.getElementById('reportPreviewModal');
    var reportModalTitle = document.getElementById('reportPreviewTitle');
    var reportModalStudent = document.getElementById('reportPreviewStudent');
    var reportModalApplication = document.getElementById('reportPreviewApplication');
    var reportModalReporter = document.getElementById('reportPreviewReporter');
    var reportModalOffice = document.getElementById('reportPreviewOffice');
    var reportModalStatus = document.getElementById('reportPreviewStatus');
    var reportModalCreated = document.getElementById('reportPreviewCreated');
    var reportModalNotes = document.getElementById('reportPreviewNotes');
    var reportModalOpenLink = document.getElementById('reportPreviewOpenLink');
    var reportModalDeleteForm = document.getElementById('reportPreviewDeleteForm');
    var reportPreviewButtons = document.querySelectorAll('.report-preview-btn');
    var lastFocusedElement = null;

    function openReportModal(button) {
        if (!reportModal || !button) return;
        lastFocusedElement = document.activeElement;
        var reportId = button.getAttribute('data-report-id') || '';
        var student = button.getAttribute('data-student') || '-';
        var application = button.getAttribute('data-application') || '0';
        var title = button.getAttribute('data-title') || '-';
        var notes = button.getAttribute('data-notes') || '';
        var reporter = button.getAttribute('data-reporter') || '-';
        var office = button.getAttribute('data-office') || 'Unassigned';
        var status = button.getAttribute('data-status') || 'open';
        var created = button.getAttribute('data-created') || '-';

        reportModalTitle.textContent = 'Report #' + reportId;
        reportModalStudent.textContent = student;
        reportModalApplication.textContent = application && application !== '0' ? application : '-';
        reportModalReporter.textContent = reporter || '-';
        reportModalOffice.textContent = office || 'Unassigned';
        reportModalStatus.textContent = status || '-';
        reportModalCreated.textContent = created || '-';
        reportModalNotes.textContent = (title ? title + '\n\n' : '') + notes;
        reportModalOpenLink.setAttribute('href', 'report_detail.php?report_id=' + encodeURIComponent(reportId));
        if (reportModalDeleteForm) {
            reportModalDeleteForm.setAttribute('action', 'report_detail.php?report_id=' + encodeURIComponent(reportId));
        }

        reportModal.hidden = false;
        reportModal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        reportModal.querySelector('.report-modal__close').focus();
    }

    function closeReportModal() {
        if (!reportModal) return;
        reportModal.hidden = true;
        reportModal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
        if (lastFocusedElement && typeof lastFocusedElement.focus === 'function') {
            lastFocusedElement.focus();
        }
    }

    reportPreviewButtons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            openReportModal(btn);
        });
    });

    if (reportModal) {
        reportModal.addEventListener('click', function (e) {
            if (e.target && e.target.hasAttribute('data-close-report-modal')) {
                closeReportModal();
            }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && reportModal && !reportModal.hidden) {
                closeReportModal();
            }
        });
    }

    /* ---- Live Chart Updates ---- */
    var chartsTimer = null;
    var chartsLastSuccess = null;
    var chartsFailureCount = 0;
    var chartsMaxRetries = 5;

    function getChartQueryParams() {
        var params = new URLSearchParams(window.location.search);
        return {
            period: params.get('period') || 'month',
            office: params.get('office') || 'all',
            mode: params.get('mode') || 'scheduled'
        };
    }

    function updateChartsFromAPI() {
        var params = getChartQueryParams();
        var url = 'reports_data.php?period=' + encodeURIComponent(params.period) +
                  '&office=' + encodeURIComponent(params.office) +
                  '&mode=' + encodeURIComponent(params.mode);

        fetch(url, { method: 'GET', cache: 'no-cache' })
            .then(function (response) {
                if (!response.ok) throw new Error('API responded with ' + response.status);
                return response.json();
            })
            .then(function (data) {
                if (!data.success || !data.data) throw new Error('Invalid API response');
                chartsFailureCount = 0;
                chartsLastSuccess = Date.now();
                updateMonthlyChart(data.data);
                updateOfficeChart(data.data);
            })
            .catch(function (err) {
                console.warn('Chart update failed:', err);
                chartsFailureCount++;
                if (chartsFailureCount >= chartsMaxRetries && chartsTimer) {
                    console.error('Chart polling stopped after ' + chartsMaxRetries + ' failures');
                    clearInterval(chartsTimer);
                    chartsTimer = null;
                }
            });
    }

    function updateMonthlyChart(data) {
        var maxH = data.max_h || 1;
        var months = data.months || [];
        var barChart = document.querySelector('.bar-chart');
        if (!barChart) return;

        var bars = barChart.querySelectorAll('.bar-chart__bar');
        bars.forEach(function (bar, idx) {
            if (!months[idx]) return;
            var fill = bar.querySelector('.bar-chart__fill');
            if (!fill) return;
            var barH = Math.round((months[idx].h / maxH) * 224);
            fill.style.height = barH + 'px';
            fill.setAttribute('aria-label', months[idx].label + ': ' + months[idx].h + ' hours');
        });
    }

    function updateOfficeChart(data) {
        var offices = data.offices || [];
        var distBars = document.querySelector('.dist-bars');
        if (!distBars) return;

        var bars = distBars.querySelectorAll('.dist-bar');
        bars.forEach(function (bar, idx) {
            if (!offices[idx]) return;
            var office = offices[idx];

            var nameSpan = bar.querySelector('.dist-bar__name');
            if (nameSpan) nameSpan.textContent = office.name;

            var countSpan = bar.querySelector('.dist-bar__count');
            if (countSpan) countSpan.textContent = office.count + ' (' + office.pct + ')';

            var fill = bar.querySelector('.dist-bar__fill');
            if (fill) {
                fill.style.width = office.fill_pct + '%';
                fill.setAttribute('aria-valuenow', office.fill_pct);
            }

            var track = bar.querySelector('.dist-bar__track');
            if (track) track.setAttribute('aria-valuenow', office.fill_pct);
        });
    }

    function startChartPolling() {
        if (chartsTimer) clearInterval(chartsTimer);
        updateChartsFromAPI();
        chartsTimer = setInterval(updateChartsFromAPI, 30000);
    }

    function stopChartPolling() {
        if (chartsTimer) {
            clearInterval(chartsTimer);
            chartsTimer = null;
        }
    }

    var auto = document.getElementById('auto-refresh');
    if (auto) {
        var key = 'admin_reports_auto_refresh';
        try { auto.checked = localStorage.getItem(key) === '1'; } catch (e) {}

        auto.addEventListener('change', function () {
            try { localStorage.setItem(key, auto.checked ? '1' : '0'); } catch (e) {}
            if (auto.checked) startChartPolling(); else stopChartPolling();
        });

        if (auto.checked) startChartPolling();
    }

})();
</script>
<script src="../assets/js/admin-notifications.js?v=20260922"></script>

</body>
</html>