<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$currentUser = sams_authenticated_user();
if (!$currentUser || ($currentUser['role'] ?? null) !== 'admin') {
    header('Location: ../login.php');
    exit;
}

$pdo = sams_pdo();

$admin_name = $currentUser['name'] ?? 'SAMS Admin';
$admin_role = 'SDAO Head';
$department = 'NU Lipa - Student Development and Activities Office';

$flashMessage = '';
$flashError = '';

$days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
$dayShort = [
    'Monday' => 'Mon',
    'Tuesday' => 'Tue',
    'Wednesday' => 'Wed',
    'Thursday' => 'Thu',
    'Friday' => 'Fri',
    'Saturday' => 'Sat',
];

$officeOptions = sams_office_options();

$dutyScheduleHasOfficeColumn = false;
try {
    $dutyScheduleHasOfficeColumn = schedule_has_column($pdo, 'duty_schedules', 'office_name');
    if (!$dutyScheduleHasOfficeColumn) {
        $pdo->exec('ALTER TABLE duty_schedules ADD COLUMN office_name VARCHAR(100) NULL AFTER application_id');
        $dutyScheduleHasOfficeColumn = true;
    }

    if ($dutyScheduleHasOfficeColumn) {
        $pdo->exec(
            'UPDATE duty_schedules ds
             INNER JOIN applications a ON a.application_id = ds.application_id
             SET ds.office_name = a.preferred_office
             WHERE ds.office_name IS NULL OR ds.office_name = ""'
        );
    }
} catch (Throwable $columnException) {
    $dutyScheduleHasOfficeColumn = schedule_has_column($pdo, 'duty_schedules', 'office_name');
}

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function time_to_minutes(string $time): int
{
    $parts = explode(':', $time);
    return ((int) ($parts[0] ?? 0)) * 60 + ((int) ($parts[1] ?? 0));
}

function minutes_to_time(int $minutes): string
{
    $hours = intdiv($minutes, 60);
    $mins = $minutes % 60;
    return sprintf('%02d:%02d:00', $hours, $mins);
}

function display_time(string $time): string
{
    $timestamp = strtotime($time);
    return $timestamp ? date('g:i A', $timestamp) : $time;
}

function duration_hours(string $start, string $end): float
{
    $diff = time_to_minutes($end) - time_to_minutes($start);
    return max(0, round($diff / 60, 2));
}

function schedule_color(string $office): string
{
    $colors = [
        'ITSO' => 'blue',
        'SDAO' => 'green',
        'Registrar' => 'purple',
        'Guidance Office' => 'orange',
        'Library' => 'yellow',
        'Accounting Office' => 'blue',
        'Admissions Office' => 'green',
        'Clinic' => 'purple',
        'Cashier' => 'orange',
    ];

    return $colors[$office] ?? 'blue';
}

function schedule_badge_class(string $status): string
{
    return match ($status) {
        'accepted' => 'confirmed',
        'declined' => 'declined',
        default => 'pending',
    };
}

function schedule_badge_label(string $status): string
{
    return match ($status) {
        'accepted' => 'Accepted',
        'declined' => 'Declined',
        default => 'Pending',
    };
}

function schedule_day_label(string $day): string
{
    $normalized = trim($day);

    return match (strtolower($normalized)) {
        'monday', 'mon' => 'Monday',
        'tuesday', 'tue' => 'Tuesday',
        'wednesday', 'wed' => 'Wednesday',
        'thursday', 'thu' => 'Thursday',
        'friday', 'fri' => 'Friday',
        'saturday', 'sat' => 'Saturday',
        'sunday', 'sun' => 'Sunday',
        default => $normalized,
    };
}

function schedule_has_column(PDO $pdo, string $table, string $column): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name'
    );
    $statement->execute([
        'table_name' => $table,
        'column_name' => $column,
    ]);

    return (int) $statement->fetchColumn() > 0;
}

function calendar_block_style(string $start, string $end): string
{
    $calendarStart = 8 * 60;
    $slotHeight = 80;
    $startMinutes = max($calendarStart, time_to_minutes($start));
    $endMinutes = max($startMinutes + 30, time_to_minutes($end));

    $top = (($startMinutes - $calendarStart) / 60) * $slotHeight;
    $height = (($endMinutes - $startMinutes) / 60) * $slotHeight;

    return sprintf('top:%dpx;height:%dpx;', (int) round($top), (int) max(48, round($height)));
}

function generate_schedules(PDO $pdo, int $adminId, bool $hasOfficeColumn, ?string $officeFilter = null, ?int $studentFilter = null): array
{
    $created = 0;
    $skipped = 0;
    $errors = [];

    $sql = "SELECT
            a.application_id AS application_id,
            a.student_id,
            a.term_id,
            a.preferred_office,
            s.student_id_number AS student_code,
            u.first_name,
            u.last_name
         FROM applications a
         INNER JOIN students s ON s.student_id = a.student_id
         INNER JOIN users u ON u.user_id = s.user_id
         WHERE a.status = 'approved'";

    $params = [];
    if ($officeFilter !== null && $officeFilter !== '') {
        $sql .= ' AND a.preferred_office = :office_filter';
        $params['office_filter'] = $officeFilter;
    }

    if ($studentFilter !== null && $studentFilter > 0) {
        $sql .= ' AND a.student_id = :student_filter';
        $params['student_filter'] = $studentFilter;
    }

    $sql .= ' ORDER BY a.application_id ASC';

    $approvedStmt = $pdo->prepare($sql);
    $approvedStmt->execute($params);
    $approved = $approvedStmt->fetchAll();

    $availabilityStmt = $pdo->prepare(
        "SELECT day_of_week, start_time AS time_start, end_time AS time_end
                 FROM availability
                 WHERE application_id = :application_id
                     AND term_id = :term_id
                 ORDER BY FIELD(day_of_week, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'), start_time ASC"
    );

        $existingStmt = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM duty_schedules ds
                 JOIN applications a ON a.application_id = ds.application_id
                 WHERE a.student_id = :student_id
                     AND ds.term_id = :term_id
                     AND ds.day_of_week = :day_of_week
                     AND ds.status <> 'declined'"
        );

    if ($hasOfficeColumn) {
        $insertStmt = $pdo->prepare(
            "INSERT INTO duty_schedules
                (application_id, office_name, term_id, day_of_week, start_time, end_time, status)
             SELECT :application_id_insert, :office_name_insert, :term_id_insert, :day_of_week_insert, :start_time_insert, :end_time_insert, 'assigned'
             WHERE NOT EXISTS (
                 SELECT 1 FROM duty_schedules WHERE application_id = :application_id_exists AND day_of_week = :day_of_week_exists
             )"
        );
    } else {
        $insertStmt = $pdo->prepare(
            "INSERT INTO duty_schedules
                (application_id, term_id, day_of_week, start_time, end_time, status)
             SELECT :application_id_insert, :term_id_insert, :day_of_week_insert, :start_time_insert, :end_time_insert, 'assigned'
             WHERE NOT EXISTS (
                 SELECT 1 FROM duty_schedules WHERE application_id = :application_id_exists AND day_of_week = :day_of_week_exists
             )"
        );
    }

    foreach ($approved as $student) {
        $office = trim((string) $student['preferred_office']);

        if ($office === '') {
            $skipped++;
            $errors[] = 'Skipped student ID ' . $student['student_id'] . ': no preferred office.';
            continue;
        }

        $availabilityStmt->execute([
            'application_id' => (int) $student['application_id'],
            'term_id' => (int) $student['term_id'],
        ]);

        $availabilityRows = $availabilityStmt->fetchAll();

        if (!$availabilityRows) {
            $skipped++;
            $errors[] = 'Skipped student ID ' . $student['student_id'] . ': no availability saved.';
            continue;
        }

        foreach ($availabilityRows as $availability) {
            $day = schedule_day_label((string) $availability['day_of_week']);

            if ($day === '') {
                $skipped++;
                $errors[] = 'Skipped student ID ' . $student['student_id'] . ': invalid availability day.';
                continue;
            }

            $existingStmt->execute([
                'student_id' => (int) $student['student_id'],
                'term_id' => (int) $student['term_id'],
                'day_of_week' => $day,
            ]);

            if ((int) $existingStmt->fetchColumn() > 0) {
                $skipped++;
                continue;
            }

            $availableStart = time_to_minutes((string) $availability['time_start']);
            $availableEnd = time_to_minutes((string) $availability['time_end']);

            if ($availableEnd <= $availableStart) {
                $skipped++;
                continue;
            }

            // Maximum 4 hours per day, starting at the student's available start time.
            $scheduleStart = $availableStart;
            $scheduleEnd = min($availableEnd, $scheduleStart + (4 * 60));

            if (($scheduleEnd - $scheduleStart) < 60) {
                $skipped++;
                continue;
            }

            $startTime = minutes_to_time($scheduleStart);
            $endTime = minutes_to_time($scheduleEnd);

            $insertStmt->execute([
                'application_id_insert' => (int) $student['application_id'],
                'office_name_insert' => $office,
                'term_id_insert' => (int) $student['term_id'],
                'day_of_week_insert' => $day,
                'start_time_insert' => $startTime,
                'end_time_insert' => $endTime,
                'application_id_exists' => (int) $student['application_id'],
                'day_of_week_exists' => $day,
            ]);

            $created++;
        }
    }

    return [
        'created' => $created,
        'skipped' => $skipped,
        'errors' => $errors,
    ];
}

$selectedOffice = trim((string) ($_GET['office'] ?? ''));
if (!in_array($selectedOffice, $officeOptions, true)) {
    $selectedOffice = '';
}

$selectedStudentId = (int) ($_GET['student_id'] ?? 0);
if ($selectedStudentId <= 0) {
    $selectedStudentId = 0;
}

$selectedDay = trim((string) ($_GET['day'] ?? ''));
if (!in_array($selectedDay, $days, true)) {
    $selectedDay = '';
}

$approvedStudentOptions = [];
if ($selectedOffice !== '') {
    $approvedStudentsStmt = $pdo->prepare(
        "SELECT DISTINCT
            a.student_id,
            s.student_id_number AS student_code,
            u.first_name,
            u.last_name
         FROM applications a
         INNER JOIN students s ON s.student_id = a.student_id
         INNER JOIN users u ON u.user_id = s.user_id
         WHERE a.status = 'approved'
           AND a.preferred_office = :office
         ORDER BY u.last_name ASC, u.first_name ASC"
    );
    $approvedStudentsStmt->execute(['office' => $selectedOffice]);
    $approvedStudentOptions = $approvedStudentsStmt->fetchAll();

    $isValidSelectedStudent = false;
    foreach ($approvedStudentOptions as $approvedStudentOption) {
        if ((int) $approvedStudentOption['student_id'] === $selectedStudentId) {
            $isValidSelectedStudent = true;
            break;
        }
    }

    if (!$isValidSelectedStudent) {
        $selectedStudentId = 0;
    }
}

$studentsByOffice = [];
$studentsByOfficeStmt = $pdo->query(
    "SELECT DISTINCT
        a.preferred_office,
        a.student_id,
        s.student_id_number AS student_code,
        u.first_name,
        u.last_name
     FROM applications a
     INNER JOIN students s ON s.student_id = a.student_id
     INNER JOIN users u ON u.user_id = s.user_id
     WHERE a.status = 'approved'
       AND a.preferred_office IN ('ITSO','SDAO','Registrar','Guidance Office','Library','Accounting Office','Admissions Office','Clinic','Cashier')
     ORDER BY a.preferred_office ASC, u.last_name ASC, u.first_name ASC"
);

foreach ($studentsByOfficeStmt->fetchAll() as $studentRow) {
    $officeKey = (string) $studentRow['preferred_office'];
    if (!isset($studentsByOffice[$officeKey])) {
        $studentsByOffice[$officeKey] = [];
    }

    $studentsByOffice[$officeKey][] = [
        'student_id' => (int) $studentRow['student_id'],
        'label' => trim((string) $studentRow['last_name'] . ', ' . (string) $studentRow['first_name'])
            . ' (' . (string) $studentRow['student_code'] . ')',
    ];
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string) ($_POST['action'] ?? '');
        $adminId = (int) ($currentUser['user_id'] ?? 0);

        $redirectOffice = trim((string) ($_POST['return_office'] ?? ''));
        if (!in_array($redirectOffice, $officeOptions, true)) {
            $redirectOffice = '';
        }

        $redirectStudentId = (int) ($_POST['return_student_id'] ?? 0);
        if ($redirectStudentId <= 0) {
            $redirectStudentId = 0;
        }

        $redirectDay = trim((string) ($_POST['return_day'] ?? ''));
        if (!in_array($redirectDay, $days, true)) {
            $redirectDay = '';
        }

        if ($action === 'auto_generate') {
            $officeFilter = trim((string) ($_POST['office_filter'] ?? ''));
            if (!in_array($officeFilter, $officeOptions, true)) {
                throw new RuntimeException('Please choose a valid office before generating schedules.');
            }

            $studentFilter = (int) ($_POST['student_filter'] ?? 0);
            if ($studentFilter < 0) {
                $studentFilter = 0;
            }

            if ($studentFilter > 0) {
                $approvedStudentCheckStmt = $pdo->prepare(
                    "SELECT COUNT(*)
                     FROM applications
                     WHERE status = 'approved'
                       AND preferred_office = :office
                       AND student_id = :student_id"
                );
                $approvedStudentCheckStmt->execute([
                    'office' => $officeFilter,
                    'student_id' => $studentFilter,
                ]);

                if ((int) $approvedStudentCheckStmt->fetchColumn() <= 0) {
                    throw new RuntimeException('Selected student is not an approved applicant for the chosen office.');
                }
            }

            $result = generate_schedules(
                $pdo,
                $adminId,
                $dutyScheduleHasOfficeColumn,
                $officeFilter,
                $studentFilter > 0 ? $studentFilter : null
            );
            $flashMessage = 'Auto scheduling complete. Created ' . $result['created'] . ' schedule(s). Skipped ' . $result['skipped'] . '.';
            if (!empty($result['errors'])) {
                $flashError = implode(' ', array_slice($result['errors'], 0, 3));
            }

            $redirectOffice = $officeFilter;
            $redirectStudentId = $studentFilter;
        }

        if ($action === 'edit_schedule') {

            $scheduleId = (int)($_POST['schedule_id'] ?? 0);
            $officeName = trim((string) ($_POST['office_name'] ?? ''));
            $dayOfWeek = schedule_day_label(trim((string)($_POST['day_of_week'] ?? '')));
            $timeStart = trim((string)($_POST['time_start'] ?? ''));
            $timeEnd = trim((string)($_POST['time_end'] ?? ''));

            if ($dayOfWeek === '') {
                throw new RuntimeException('Please choose a valid day of the week.');
            }

            if ($officeName !== '' && !in_array($officeName, $officeOptions, true)) {
                throw new RuntimeException('Please choose a valid office.');
            }

            $scheduleStmt = $pdo->prepare(
                'SELECT ds.application_id, ds.office_name, a.preferred_office
                 FROM duty_schedules ds
                 INNER JOIN applications a ON a.application_id = ds.application_id
                 WHERE ds.duty_id = :duty_id
                 LIMIT 1'
            );
            $scheduleStmt->execute(['duty_id' => $scheduleId]);
            $scheduleRow = $scheduleStmt->fetch(PDO::FETCH_ASSOC);

            if (!$scheduleRow) {
                throw new RuntimeException('Schedule not found.');
            }

            $applicationId = (int) ($scheduleRow['application_id'] ?? 0);
            $currentOffice = trim((string) ($scheduleRow['office_name'] ?? $scheduleRow['preferred_office'] ?? ''));

            if ($dutyScheduleHasOfficeColumn) {
                $updateStmt = $pdo->prepare("
                    UPDATE duty_schedules
                    SET
                        office_name = :office_name,
                        day_of_week = :day_of_week,
                        start_time = :time_start,
                        end_time = :time_end
                    WHERE duty_id = :duty_id
                ");

                $updateStmt->execute([
                    'office_name' => $officeName !== '' ? $officeName : $currentOffice,
                    'day_of_week' => $dayOfWeek,
                    'time_start' => $timeStart,
                    'time_end' => $timeEnd,
                    'duty_id' => $scheduleId
                ]);
            } else {
                $updateStmt = $pdo->prepare("
                    UPDATE duty_schedules
                    SET
                        day_of_week = :day_of_week,
                        start_time = :time_start,
                        end_time = :time_end
                    WHERE duty_id = :duty_id
                ");

                $updateStmt->execute([
                    'day_of_week' => $dayOfWeek,
                    'time_start' => $timeStart,
                    'time_end' => $timeEnd,
                    'duty_id' => $scheduleId
                ]);
            }

            if ($officeName !== '') {
                $redirectOffice = $officeName;
            }

            $flashMessage = 'Schedule updated successfully.';

        }

        if ($action === 'update_status') {
            $scheduleId = (int) ($_POST['schedule_id'] ?? 0);
            $status = (string) ($_POST['status'] ?? '');

            if ($scheduleId <= 0 || !in_array($status, ['pending', 'accepted', 'declined'], true)) {
                throw new RuntimeException('Invalid schedule status update.');
            }

            $statement = $pdo->prepare(
                'UPDATE duty_schedules SET status = :status, student_response_date = NOW() WHERE duty_id = :duty_id'
            );
            $statement->execute(['status' => $status, 'duty_id' => $scheduleId]);
            $flashMessage = 'Schedule status updated successfully.';
        }

        if ($action === 'delete_schedule') {
            $scheduleId = (int) ($_POST['schedule_id'] ?? 0);
            if ($scheduleId <= 0) {
                throw new RuntimeException('Invalid schedule selected.');
            }
            $statement = $pdo->prepare('DELETE FROM duty_schedules WHERE duty_id = :duty_id');
            $statement->execute(['duty_id' => $scheduleId]);
            $flashMessage = 'Schedule deleted successfully.';
        }

        $_SESSION['scheduling_flash'] = $flashMessage;
        $_SESSION['scheduling_error'] = $flashError;

        $redirectParams = [];
        if ($redirectOffice !== '') {
            $redirectParams['office'] = $redirectOffice;
        }
        if ($redirectStudentId > 0) {
            $redirectParams['student_id'] = (string) $redirectStudentId;
        }
        if ($redirectDay !== '') {
            $redirectParams['day'] = $redirectDay;
        }

        $redirectUrl = 'scheduling.php';
        if (!empty($redirectParams)) {
            $redirectUrl .= '?' . http_build_query($redirectParams);
        }

        header('Location: ' . $redirectUrl);
        exit;
    }
} catch (Throwable $exception) {
    $_SESSION['scheduling_error'] = $exception->getMessage();
    header('Location: scheduling.php');
    exit;
}

if (isset($_SESSION['scheduling_flash'])) {
    $flashMessage = (string) $_SESSION['scheduling_flash'];
    unset($_SESSION['scheduling_flash']);
}

if (isset($_SESSION['scheduling_error'])) {
    $flashError = (string) $_SESSION['scheduling_error'];
    unset($_SESSION['scheduling_error']);
}

$scheduleSql = "SELECT
        ds.duty_id as id,
        a.student_id,
    COALESCE(ds.office_name, a.preferred_office) AS office_name,
        ds.term_id,
        ds.day_of_week,
    ds.start_time AS time_start,
    ds.end_time AS time_end,
        ds.status,
        ds.created_at as assigned_at,
        ds.student_response_date as responded_at,
    ROUND(TIMESTAMPDIFF(MINUTE, ds.start_time, ds.end_time) / 60, 2) AS required_hours,
        st.student_id_number AS student_code,
        st.program,
        st.year_level,
        u.first_name,
        u.last_name
     FROM duty_schedules ds
     INNER JOIN applications a ON a.application_id = ds.application_id
     INNER JOIN students st ON st.student_id = a.student_id
     INNER JOIN users u ON u.user_id = st.user_id
     WHERE 1 = 1";

$scheduleParams = [];
if ($selectedStudentId > 0) {
    $scheduleSql .= ' AND a.student_id = :student_id';
    $scheduleParams['student_id'] = $selectedStudentId;
}

if ($selectedDay !== '') {
    $scheduleSql .= ' AND ds.day_of_week = :day_of_week';
    $scheduleParams['day_of_week'] = $selectedDay;
}

$scheduleSql .= " ORDER BY FIELD(ds.day_of_week, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'), ds.start_time ASC, u.last_name ASC";

$scheduleStmt = $pdo->prepare($scheduleSql);
$scheduleStmt->execute($scheduleParams);
$schedules = $scheduleStmt->fetchAll();

$approvedStudentsSql = "SELECT COUNT(*) FROM applications
     WHERE status = 'approved'
       AND preferred_office IN ('ITSO','SDAO','Registrar','Guidance Office','Library','Accounting Office','Admissions Office','Clinic','Cashier')";

$approvedStudentsParams = [];
if ($selectedOffice !== '') {
    $approvedStudentsSql .= ' AND preferred_office = :preferred_office';
    $approvedStudentsParams['preferred_office'] = $selectedOffice;
}

$approvedStudentsStmt = $pdo->prepare($approvedStudentsSql);
$approvedStudentsStmt->execute($approvedStudentsParams);
$approvedStudents = (int) $approvedStudentsStmt->fetchColumn();

$totalSchedules = count($schedules);
$acceptedCount = 0;
$pendingCount = 0;
$declinedCount = 0;
$totalHours = 0.0;
$studentIds = [];

foreach ($schedules as $schedule) {
    if ($schedule['status'] === 'accepted') $acceptedCount++;
    elseif ($schedule['status'] === 'declined') $declinedCount++;
    else $pendingCount++;

    $totalHours += duration_hours((string) $schedule['time_start'], (string) $schedule['time_end']);
    $studentIds[(int) $schedule['student_id']] = true;
}

$applicationBadgeCount = (int) $pdo->query("SELECT COUNT(*) FROM applications WHERE status = 'pending'")->fetchColumn();
$calendarStartHour = 8;
$calendarEndHour = 21;
$hours = range($calendarStartHour, $calendarEndHour - 1);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>NU SA System – Scheduling</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;700;900&display=swap" rel="stylesheet" />
    <style>
        :root{--color-primary:#155dfc;--gradient-brand:linear-gradient(135deg,#155dfc 0%,#9810fa 100%);--color-heading:#101828;--color-body:#4a5565;--color-label:#364153;--color-muted:#6a7282;--color-border:#e5e7eb;--color-bg-app:#f9fafb;--color-white:#fff;--color-blue-bg:#dbeafe;--color-blue-text:#155dfc;--color-green-bg:#dcfce7;--color-green-text:#00a63e;--color-yellow-bg:#fef9c2;--color-yellow-text:#a65f00;--color-red-bg:#fee2e2;--color-red-text:#dc2626;--color-red-dot:#fb2c36;--sched-blue:#155dfc;--sched-green:#00a63e;--sched-purple:#9810fa;--sched-orange:#f54900;--sched-yellow:#d08700;--shadow-card:0 1px 3px rgba(0,0,0,.07),0 1px 2px rgba(0,0,0,.05);--sidebar-width:256px;--topbar-height:89px;--radius-card:16px;--radius-nav:10px;--radius-badge:9999px;--radius-btn:10px;--radius-block:8px;--font-xs:12px;--font-sm:14px;--font-base:16px;--font-md:18px;--font-lg:24px}
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}html,body{height:100%}body{font-family:'Inter',sans-serif;background:var(--color-bg-app);color:var(--color-heading);min-height:100vh;display:flex}a{text-decoration:none;color:inherit}button{font-family:inherit;cursor:pointer;border:none;background:none}.shell{display:flex;width:100%;min-height:100vh}
        .sidebar{width:var(--sidebar-width);min-height:100vh;background:var(--color-white);border-right:1px solid var(--color-border);display:flex;flex-direction:column;flex-shrink:0;position:sticky;top:0;height:100vh;overflow-y:auto}.sidebar__brand{display:flex;align-items:center;gap:12px;padding:24px 24px 20px;border-bottom:1px solid var(--color-border);flex-shrink:0}.sidebar__logo{width:40px;height:40px;background:var(--gradient-brand);border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0}.sidebar__logo-text{font-size:18px;font-weight:700;color:#fff;line-height:1}.sidebar__brand-name{font-size:var(--font-base);font-weight:700}.sidebar__brand-sub{font-size:var(--font-xs);color:var(--color-body)}.sidebar__nav{flex:1;padding:16px;display:flex;flex-direction:column;gap:4px;overflow-y:auto}.sidebar__nav-link{display:flex;align-items:center;gap:12px;height:48px;padding:0 16px;border-radius:var(--radius-nav);font-size:var(--font-base);color:var(--color-label);transition:background .15s;white-space:nowrap}.sidebar__nav-link:hover{background:var(--color-bg-app)}.sidebar__nav-link--active{background:var(--color-primary);color:#fff}.sidebar__nav-icon{width:20px;height:20px;display:flex;align-items:center;justify-content:center;flex-shrink:0}.sidebar__nav-icon svg{width:100%;height:100%}.sidebar__nav-badge{background:var(--color-blue-bg);color:var(--color-primary);font-size:var(--font-xs);font-weight:700;padding:2px 8px;border-radius:var(--radius-badge);margin-left:auto}.sidebar__footer{border-top:1px solid var(--color-border);padding:16px;display:flex;flex-direction:column;gap:4px;flex-shrink:0}
        .main{flex:1;min-width:0;display:flex;flex-direction:column}.topbar{background:#fff;border-bottom:1px solid var(--color-border);height:var(--topbar-height);padding:0 32px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;position:sticky;top:0;z-index:50}.topbar__left-wrap{display:flex;align-items:center}.topbar__title{font-size:var(--font-lg);font-weight:700}.topbar__sub{font-size:var(--font-sm);color:var(--color-body)}.topbar__right{display:flex;align-items:center;gap:12px}.topbar__notif-btn{width:36px;height:36px;border-radius:var(--radius-badge);display:flex;align-items:center;justify-content:center;position:relative}.topbar__notif-btn svg{width:20px;height:20px}.topbar__notif-dot{position:absolute;top:4px;right:4px;width:8px;height:8px;background:var(--color-red-dot);border-radius:var(--radius-badge)}.topbar__user-info{text-align:right}.topbar__user-name{font-size:var(--font-sm)}.topbar__user-role{font-size:var(--font-xs);color:var(--color-body)}.topbar__avatar{width:40px;height:40px;background:var(--gradient-brand);border-radius:var(--radius-badge);display:flex;align-items:center;justify-content:center;flex-shrink:0}.topbar__avatar svg{width:20px;height:20px}.topbar__hamburger{display:none;flex-direction:column;gap:5px;width:32px;height:32px;justify-content:center;align-items:center;padding:0;margin-right:16px}.topbar__hamburger-bar{display:block;width:22px;height:2px;background:var(--color-heading);border-radius:2px}
        .scheduling{padding:32px;display:flex;flex-direction:column;gap:24px;flex:1}.sched-header{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px}.sched-header__title{font-size:var(--font-md);font-weight:700;margin-bottom:4px}.sched-header__sub{font-size:var(--font-sm);color:var(--color-body)}.sched-header__right{display:flex;align-items:center;gap:12px;flex-shrink:0;flex-wrap:wrap}.sched-toolbar-form{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.sched-select{height:38px;min-width:190px;padding:0 10px;border:1px solid var(--color-border);border-radius:8px;background:#fff;color:var(--color-heading);font-size:13px}.sched-select:disabled{opacity:.6;cursor:not-allowed}.btn-create,.btn-danger,.btn-small{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:41px;padding:0 16px;border-radius:var(--radius-btn);font-size:var(--font-sm);font-weight:700;cursor:pointer;transition:opacity .15s;white-space:nowrap}.btn-create{background:var(--color-primary);color:#fff}.btn-danger{background:var(--color-red-bg);color:var(--color-red-text)}.btn-small{min-height:32px;padding:0 10px;font-size:12px;background:#eef2ff;color:#3730a3}.btn-create:hover,.btn-danger:hover,.btn-small:hover{opacity:.85}
        .alert{padding:14px 16px;border-radius:12px;font-size:14px;font-weight:700;border:1px solid}.alert-success{background:#ecfdf5;color:#047857;border-color:#a7f3d0}.alert-error{background:#fef2f2;color:#b91c1c;border-color:#fecaca}.stats-row{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px}.stat-card{background:#fff;border:1px solid var(--color-border);border-radius:var(--radius-card);padding:18px;box-shadow:var(--shadow-card)}.stat-card__label{font-size:13px;color:var(--color-muted);margin-bottom:8px}.stat-card__value{font-size:26px;font-weight:900}
        .sched-grid{display:grid;grid-template-columns:1fr 360px;gap:24px;align-items:start}.calendar-card,.card{background:#fff;border:1px solid var(--color-border);border-radius:var(--radius-card);box-shadow:var(--shadow-card);overflow:hidden}.cal-days{display:grid;grid-template-columns:64px repeat(6,1fr);border-bottom:1px solid var(--color-border)}.cal-days__day{padding:16px 10px;text-align:center;border-left:1px solid var(--color-border)}.cal-days__day-name{font-size:var(--font-xs);font-weight:700;color:var(--color-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px}.cal-days__day-num{font-size:var(--font-md);font-weight:700}.cal-body{display:grid;grid-template-columns:64px repeat(6,1fr)}.cal-time-col{display:flex;flex-direction:column}.cal-time-slot{height:80px;padding:8px 8px 0 0;text-align:right;font-size:var(--font-xs);color:var(--color-muted);border-bottom:1px solid var(--color-border);flex-shrink:0}.cal-day-col{border-left:1px solid var(--color-border);display:flex;flex-direction:column;min-height:<?= count($hours) * 80 ?>px;position:relative}.cal-day-col__slot{height:80px;flex-shrink:0;border-bottom:1px solid var(--color-border)}.sched-block{position:absolute;left:6px;right:6px;border-radius:var(--radius-block);padding:8px;overflow:hidden;transition:opacity .15s}.sched-block--blue{background:#dbeafe;border-left:3px solid var(--sched-blue)}.sched-block--green{background:#dcfce7;border-left:3px solid var(--sched-green)}.sched-block--purple{background:#f3e8ff;border-left:3px solid var(--sched-purple)}.sched-block--orange{background:#ffedd4;border-left:3px solid var(--sched-orange)}.sched-block--yellow{background:#fef9c2;border-left:3px solid var(--sched-yellow)}.sched-block__name{font-size:var(--font-xs);font-weight:700;margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.sched-block__time,.sched-block__loc{font-size:11px;color:var(--color-body)}
        .sched-right{display:flex;flex-direction:column;gap:24px}.card{padding:24px}.card__title{font-size:var(--font-md);font-weight:700;margin-bottom:16px}.sched-list{display:flex;flex-direction:column;gap:12px;max-height:560px;overflow-y:auto}.sched-item{background:var(--color-bg-app);border-radius:var(--radius-nav);padding:16px;display:flex;flex-direction:column;gap:8px;border-left:4px solid transparent}.sched-item--blue{border-left-color:var(--sched-blue)}.sched-item--green{border-left-color:var(--sched-green)}.sched-item--purple{border-left-color:var(--sched-purple)}.sched-item--orange{border-left-color:var(--sched-orange)}.sched-item--yellow{border-left-color:var(--sched-yellow)}.sched-item__name{font-size:var(--font-base);font-weight:700}.sched-item__time,.sched-item__loc{font-size:var(--font-sm);color:var(--color-body)}.sched-item__actions{display:flex;gap:6px;flex-wrap:wrap;margin-top:4px}.sched-item__badge{display:inline-block;padding:4px 10px;border-radius:var(--radius-badge);font-size:var(--font-xs);font-weight:700}.sched-item__badge--confirmed{background:var(--color-green-bg);color:var(--color-green-text)}.sched-item__badge--pending{background:var(--color-yellow-bg);color:var(--color-yellow-text)}.sched-item__badge--declined{background:var(--color-red-bg);color:var(--color-red-text)}
        .table-card{background:#fff;border:1px solid var(--color-border);border-radius:var(--radius-card);box-shadow:var(--shadow-card);overflow:hidden}.schedule-table{width:100%;border-collapse:collapse}.schedule-table th,.schedule-table td{padding:12px 14px;border-bottom:1px solid var(--color-border);text-align:left;font-size:14px}.schedule-table th{background:#f8fafc;color:var(--color-muted);text-transform:uppercase;font-size:12px;letter-spacing:.04em}.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:90}.sidebar-overlay--visible{display:block}
        @media(max-width:1200px){.sched-grid{grid-template-columns:1fr}.stats-row{grid-template-columns:repeat(2,1fr)}}@media(max-width:1024px){.sidebar{position:fixed;left:0;top:0;height:100%;z-index:100;transform:translateX(-100%);transition:transform .3s ease}.sidebar--open{transform:translateX(0)}.topbar__hamburger{display:flex}.topbar{padding:0 24px}.scheduling{padding:24px}}@media(max-width:768px){.topbar{padding:0 16px}.topbar__user-info{display:none}.scheduling{padding:16px;gap:16px}.stats-row{grid-template-columns:1fr}.calendar-card{overflow-x:auto}.cal-days,.cal-body{min-width:980px}}
    </style>
</head>
<body>
<div class="sidebar-overlay" id="sidebar-overlay" aria-hidden="true"></div>
<div class="shell">
    <?php $activeAdminNav = 'scheduling'; $pendingApplications = (int) $applicationBadgeCount; include __DIR__ . '/_sidebar.php'; ?>
    </aside>
    <div class="main">
        <header class="topbar" role="banner">
            <div class="topbar__left-wrap"><button class="topbar__hamburger" id="hamburger-btn" aria-expanded="false" aria-controls="sidebar" aria-label="Toggle navigation"><span class="topbar__hamburger-bar"></span><span class="topbar__hamburger-bar"></span><span class="topbar__hamburger-bar"></span></button><div><div class="topbar__title">Scheduling</div><div class="topbar__sub"><?= h($department) ?></div></div></div>
            <div class="topbar__right"><div class="topbar__notif-btn" role="button" aria-label="Notifications" tabindex="0"><svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M10 2a6 6 0 00-6 6v3.586l-.707.707A1 1 0 004 14h12a1 1 0 00.707-1.707L16 11.586V8a6 6 0 00-6-6zM10 18a3 3 0 01-3-3h6a3 3 0 01-3 3z" fill="#4A5565"/></svg><span class="topbar__notif-dot" aria-hidden="true"></span></div><div class="topbar__user-info" aria-label="Logged in user"><div class="topbar__user-name"><?= h($admin_name) ?></div><div class="topbar__user-role"><?= h($admin_role) ?></div></div><div class="topbar__avatar" aria-hidden="true"><svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="10" cy="7" r="4" fill="white" opacity=".9"/><path d="M2 17c0-3.314 3.582-6 8-6s8 2.686 8 6" fill="white" opacity=".9"/></svg></div></div>
        </header>
        <main class="scheduling" id="main-content">
            <div class="sched-header">
                <div class="sched-header__left">
                    <div class="sched-header__title">Scheduling</div>
                    <div class="sched-header__sub">Auto-generate and review student assistant duty schedules</div>
                </div>
                <div class="sched-header__right">
                    <form method="GET" class="sched-toolbar-form" aria-label="Filter schedules">
                        <select class="sched-select" name="office" id="filter-office-select">
                            <option value="">All Offices</option>
                            <?php foreach ($officeOptions as $officeOption): ?>
                                <option value="<?= h($officeOption) ?>" <?= $selectedOffice === $officeOption ? 'selected' : '' ?>><?= h($officeOption) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select class="sched-select" name="student_id" id="filter-student-select" <?= $selectedOffice === '' ? 'disabled' : '' ?>>
                            <option value="">All Approved Students</option>
                            <?php foreach ($approvedStudentOptions as $studentOption): ?>
                                <?php $studentOptionId = (int) $studentOption['student_id']; ?>
                                <?php $studentLabel = trim((string) $studentOption['last_name'] . ', ' . (string) $studentOption['first_name']) . ' (' . (string) $studentOption['student_code'] . ')'; ?>
                                <option value="<?= $studentOptionId ?>" <?= $selectedStudentId === $studentOptionId ? 'selected' : '' ?>><?= h($studentLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select class="sched-select" name="day" id="filter-day-select">
                            <option value="">All Days</option>
                            <?php foreach ($days as $day): ?>
                                <option value="<?= h($day) ?>" <?= $selectedDay === $day ? 'selected' : '' ?>><?= h($day) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn-small" type="submit">Apply Filter</button>
                        <?php if ($selectedOffice !== '' || $selectedStudentId > 0 || $selectedDay !== ''): ?>
                            <a class="btn-small" href="scheduling.php">Clear</a>
                        <?php endif; ?>
                    </form>

                    <form method="POST" class="sched-toolbar-form" onsubmit="return confirm('Generate schedules from approved applications and availability?');" aria-label="Generate schedules">
                        <input type="hidden" name="action" value="auto_generate">
                        <select class="sched-select" name="office_filter" id="generate-office-select" required>
                            <option value="">Select Office</option>
                            <?php foreach ($officeOptions as $officeOption): ?>
                                <option value="<?= h($officeOption) ?>" <?= $selectedOffice === $officeOption ? 'selected' : '' ?>><?= h($officeOption) ?></option>
                            <?php endforeach; ?>
                        </select>

                        <select class="sched-select" name="student_filter" id="generate-student-select" <?= $selectedOffice === '' ? 'disabled' : '' ?>>
                            <option value="">All Approved in Office</option>
                            <?php foreach ($approvedStudentOptions as $studentOption): ?>
                                <?php $studentOptionId = (int) $studentOption['student_id']; ?>
                                <?php $studentLabel = trim((string) $studentOption['last_name'] . ', ' . (string) $studentOption['first_name']) . ' (' . (string) $studentOption['student_code'] . ')'; ?>
                                <option value="<?= $studentOptionId ?>" <?= $selectedStudentId === $studentOptionId ? 'selected' : '' ?>><?= h($studentLabel) ?></option>
                            <?php endforeach; ?>
                        </select>

                        <input type="hidden" name="return_office" value="<?= h($selectedOffice) ?>">
                        <input type="hidden" name="return_student_id" value="<?= (int) $selectedStudentId ?>">
                        <input type="hidden" name="return_day" value="<?= h($selectedDay) ?>">
                        <button class="btn-create" type="submit">Auto Generate Schedule</button>
                    </form>
                </div>
            </div>
            <?php if ($flashMessage !== ''): ?><div class="alert alert-success"><?= h($flashMessage) ?></div><?php endif; ?>
            <?php if ($flashError !== ''): ?><div class="alert alert-error"><?= h($flashError) ?></div><?php endif; ?>
            <div class="stats-row"><div class="stat-card"><div class="stat-card__label">Approved Students Ready</div><div class="stat-card__value"><?= (int) $approvedStudents ?></div></div><div class="stat-card"><div class="stat-card__label">Total Schedules</div><div class="stat-card__value"><?= (int) $totalSchedules ?></div></div><div class="stat-card"><div class="stat-card__label">Accepted</div><div class="stat-card__value"><?= (int) $acceptedCount ?></div></div><div class="stat-card"><div class="stat-card__label">Total Duty Hours</div><div class="stat-card__value"><?= number_format($totalHours, 2) ?>h</div></div></div>
            <div class="sched-grid">
                <section class="calendar-card" aria-label="Weekly schedule calendar"><div class="cal-days" role="row"><div class="cal-days__time-gutter" aria-hidden="true"></div><?php foreach ($days as $day): ?><div class="cal-days__day"><div class="cal-days__day-name"><?= h($dayShort[$day]) ?></div><div class="cal-days__day-num"><?= h($day) ?></div></div><?php endforeach; ?></div><div class="cal-body" role="grid" aria-label="Calendar time grid"><div class="cal-time-col" aria-hidden="true"><?php foreach ($hours as $hour): ?><div class="cal-time-slot"><?= date('g A', strtotime(sprintf('%02d:00:00', $hour))) ?></div><?php endforeach; ?></div><?php foreach ($days as $day): ?><div class="cal-day-col" role="gridcell" aria-label="<?= h($day) ?>"><?php foreach ($hours as $_): ?><div class="cal-day-col__slot"></div><?php endforeach; ?><?php foreach ($schedules as $schedule): ?><?php $scheduleDay = schedule_day_label((string) $schedule['day_of_week']); if ($scheduleDay !== $day || $schedule['status'] === 'declined') continue; ?><?php $studentName = trim((string) $schedule['first_name'] . ' ' . (string) $schedule['last_name']); $office = (string) $schedule['office_name']; $color = schedule_color($office); ?><div class="sched-block sched-block--<?= h($color) ?>" style="<?= h(calendar_block_style((string) $schedule['time_start'], (string) $schedule['time_end'])) ?>" title="<?= h($studentName . ' - ' . $office) ?>"><div class="sched-block__name"><?= h($studentName) ?></div><div class="sched-block__time"><?= h(display_time((string) $schedule['time_start']) . ' – ' . display_time((string) $schedule['time_end'])) ?></div><div class="sched-block__loc"><?= h($office) ?></div></div><?php endforeach; ?></div><?php endforeach; ?></div></section>
                <div class="sched-right">
                    <section class="card" aria-labelledby="upcoming-heading">
                        <h2 class="card__title" id="upcoming-heading">Generated Schedules</h2>
                        <div class="sched-list">
                            <?php if (!$schedules): ?>
                                <div class="sched-item">No schedules generated yet.</div>
                            <?php endif; ?>

                            <?php foreach ($schedules as $schedule): ?>
                                <?php
                                    $studentName = trim((string) $schedule['first_name'] . ' ' . (string) $schedule['last_name']);
                                    $office = (string) $schedule['office_name'];
                                    $color = schedule_color($office);
                                    $badge = schedule_badge_class((string) $schedule['status']);
                                ?>

                                <div class="sched-item sched-item--<?= h($color) ?>">
                                    <div class="sched-item__name"><?= h($studentName) ?></div>
                                    <div class="sched-item__time"><?= h(schedule_day_label((string) $schedule['day_of_week'])) ?> · <?= h(display_time((string) $schedule['time_start'])) ?> – <?= h(display_time((string) $schedule['time_end'])) ?></div>
                                    <div class="sched-item__loc"><?= h($office) ?> · <?= h(number_format((float) $schedule['required_hours'], 2)) ?>h</div>
                                    <span class="sched-item__badge sched-item__badge--<?= h($badge) ?>"><?= h(schedule_badge_label((string) $schedule['status'])) ?></span>

                                    <div class="sched-item__actions">
                                        <?php
                                        $checkScheduleStmt = $pdo->prepare("
                                            SELECT COUNT(*)
                                            FROM duty_schedules
                                            INNER JOIN applications a ON a.application_id = duty_schedules.application_id
                                            WHERE a.student_id = :student_id
                                            AND duty_schedules.status = 'accepted'
                                        ");

                                        $checkScheduleStmt->execute([
                                            'student_id' => $schedule['student_id']
                                        ]);

                                        $alreadyScheduled =
                                            (int)$checkScheduleStmt->fetchColumn() > 0;
                                        ?>

                                        <form method="POST"><input type="hidden" name="action" value="update_status"><input type="hidden" name="schedule_id" value="<?= (int) $schedule['id'] ?>"><input type="hidden" name="status" value="accepted"><input type="hidden" name="return_office" value="<?= h($selectedOffice) ?>"><input type="hidden" name="return_student_id" value="<?= (int) $selectedStudentId ?>"><input type="hidden" name="return_day" value="<?= h($selectedDay) ?>"><button class="btn-small" type="submit">Accept</button></form>
                                        <form method="POST"><input type="hidden" name="action" value="update_status"><input type="hidden" name="schedule_id" value="<?= (int) $schedule['id'] ?>"><input type="hidden" name="status" value="pending"><input type="hidden" name="return_office" value="<?= h($selectedOffice) ?>"><input type="hidden" name="return_student_id" value="<?= (int) $selectedStudentId ?>"><input type="hidden" name="return_day" value="<?= h($selectedDay) ?>"><button class="btn-small" type="submit">Pending</button></form>
                                        <form method="POST"><input type="hidden" name="action" value="update_status"><input type="hidden" name="schedule_id" value="<?= (int) $schedule['id'] ?>"><input type="hidden" name="status" value="declined"><input type="hidden" name="return_office" value="<?= h($selectedOffice) ?>"><input type="hidden" name="return_student_id" value="<?= (int) $selectedStudentId ?>"><input type="hidden" name="return_day" value="<?= h($selectedDay) ?>"><button class="btn-small" type="submit">Decline</button></form>

                                        <button
                                            class="btn-small"
                                            onclick="openEditModal(
                                                <?= (int)$schedule['id'] ?>,
                                                '<?= h($schedule['office_name']) ?>',
                                                '<?= h(schedule_day_label((string) $schedule['day_of_week'])) ?>',
                                                '<?= h($schedule['time_start']) ?>',
                                                '<?= h($schedule['time_end']) ?>'
                                            )"
                                            type="button"
                                        >
                                            Edit
                                        </button>

                                        <form method="POST" onsubmit="return confirm('Delete this schedule?');"><input type="hidden" name="action" value="delete_schedule"><input type="hidden" name="schedule_id" value="<?= (int) $schedule['id'] ?>"><input type="hidden" name="return_office" value="<?= h($selectedOffice) ?>"><input type="hidden" name="return_student_id" value="<?= (int) $selectedStudentId ?>"><input type="hidden" name="return_day" value="<?= h($selectedDay) ?>"><button class="btn-danger" type="submit">Delete</button></form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                    <section class="card">
                        <h2 class="card__title">Summary</h2>
                        <p style="font-size:14px;color:#4a5565;line-height:1.7;">Pending: <strong><?= (int) $pendingCount ?></strong><br>Accepted: <strong><?= (int) $acceptedCount ?></strong><br>Declined: <strong><?= (int) $declinedCount ?></strong><br>Students Scheduled: <strong><?= count($studentIds) ?></strong></p>
                    </section>
                </div>
            </div>
            <section class="table-card"><table class="schedule-table"><thead><tr><th>Student</th><th>Office</th><th>Day</th><th>Time</th><th>Hours</th><th>Status</th></tr></thead><tbody><?php if (!$schedules): ?><tr><td colspan="6">No schedules found. Click Auto Generate Schedule.</td></tr><?php endif; ?><?php foreach ($schedules as $schedule): ?><?php $studentName = trim((string) $schedule['first_name'] . ' ' . (string) $schedule['last_name']); ?><tr><td><?= h($studentName) ?></td><td><?= h((string) $schedule['office_name']) ?></td><td><?= h(schedule_day_label((string) $schedule['day_of_week'])) ?></td><td><?= h(display_time((string) $schedule['time_start']) . ' – ' . display_time((string) $schedule['time_end'])) ?></td><td><?= h(number_format((float) $schedule['required_hours'], 2)) ?>h</td><td><?= h(schedule_badge_label((string) $schedule['status'])) ?></td></tr><?php endforeach; ?></tbody></table></section>
        </main>
    </div>
</div>
<script>
(function(){'use strict';var sidebar=document.getElementById('sidebar');var overlay=document.getElementById('sidebar-overlay');var hamburger=document.getElementById('hamburger-btn');function openSidebar(){sidebar.classList.add('sidebar--open');overlay.classList.add('sidebar-overlay--visible');hamburger.setAttribute('aria-expanded','true');overlay.setAttribute('aria-hidden','false')}function closeSidebar(){sidebar.classList.remove('sidebar--open');overlay.classList.remove('sidebar-overlay--visible');hamburger.setAttribute('aria-expanded','false');overlay.setAttribute('aria-hidden','true')}if(hamburger){hamburger.addEventListener('click',function(){sidebar.classList.contains('sidebar--open')?closeSidebar():openSidebar()})}if(overlay){overlay.addEventListener('click',closeSidebar)}document.addEventListener('keydown',function(e){if(e.key==='Escape')closeSidebar()});window.addEventListener('resize',function(){if(window.innerWidth>1024)closeSidebar()})})();

(function(){
    'use strict';

    var studentsByOffice = <?= json_encode($studentsByOffice, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var selectedOffice = <?= json_encode($selectedOffice, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var selectedStudentId = <?= (int) $selectedStudentId ?>;

    var filterOffice = document.getElementById('filter-office-select');
    var filterStudent = document.getElementById('filter-student-select');
    var generateOffice = document.getElementById('generate-office-select');
    var generateStudent = document.getElementById('generate-student-select');

    function refillStudents(selectEl, office, includeAllLabel, selectedId) {
        if (!selectEl) return;

        var options = studentsByOffice[office] || [];
        selectEl.innerHTML = '';

        var baseOption = document.createElement('option');
        baseOption.value = '';
        baseOption.textContent = includeAllLabel;
        selectEl.appendChild(baseOption);

        for (var i = 0; i < options.length; i++) {
            var item = options[i];
            var opt = document.createElement('option');
            opt.value = String(item.student_id);
            opt.textContent = item.label;
            if (selectedId > 0 && Number(item.student_id) === Number(selectedId)) {
                opt.selected = true;
            }
            selectEl.appendChild(opt);
        }

        selectEl.disabled = office === '';
    }

    if (filterOffice && filterStudent) {
        refillStudents(filterStudent, selectedOffice, 'All Approved Students', selectedStudentId);
        filterOffice.addEventListener('change', function () {
            refillStudents(filterStudent, filterOffice.value, 'All Approved Students', 0);
        });
    }

    if (generateOffice && generateStudent) {
        refillStudents(generateStudent, selectedOffice, 'All Approved in Office', selectedStudentId);
        generateOffice.addEventListener('change', function () {
            refillStudents(generateStudent, generateOffice.value, 'All Approved in Office', 0);
        });
    }
})();

function openEditModal(
    id,
    office,
    day,
    start,
    end
) {

    document.getElementById('editModal').style.display = 'flex';

    document.getElementById('edit_schedule_id').value = id;
    document.getElementById('edit_office').value = office;
    document.getElementById('edit_day').value = day;
    document.getElementById('edit_start').value = start;
    document.getElementById('edit_end').value = end;
}

function closeEditModal() {
    document.getElementById('editModal').style.display = 'none';
}

</script>

<div
    id="editModal"
    style="
        display:none;
        position:fixed;
        inset:0;
        background:rgba(0,0,0,.5);
        z-index:9999;
        align-items:center;
        justify-content:center;
    "
>

    <div
        style="
            background:white;
            padding:24px;
            border-radius:16px;
            width:400px;
        "
    >

        <h2 style="margin-bottom:16px;">
            Edit Schedule
        </h2>

        <form method="POST">

            <input type="hidden" name="action" value="edit_schedule">

            <input type="hidden" name="schedule_id" id="edit_schedule_id">
            <input type="hidden" name="return_office" value="<?= h($selectedOffice) ?>">
            <input type="hidden" name="return_student_id" value="<?= (int) $selectedStudentId ?>">
            <input type="hidden" name="return_day" value="<?= h($selectedDay) ?>">

            <label>Office</label>

            <select
                name="office_name"
                id="edit_office"
                style="width:100%;margin-bottom:12px;"
            >
                <option value="ITSO">ITSO</option>
                <option value="SDAO">SDAO</option>
                <option value="Registrar">Registrar</option>
                <option value="Guidance Office">Guidance Office</option>
                <option value="Library">Library</option>
                <option value="Accounting Office">Accounting Office</option>
                <option value="Admissions Office">Admissions Office</option>
                <option value="Clinic">Clinic</option>
                <option value="Cashier">Cashier</option>
            </select>

            <label>Day</label>

            <select
                name="day_of_week"
                id="edit_day"
                style="width:100%;margin-bottom:12px;"
            >
                <option value="Monday">Monday</option>
                <option value="Tuesday">Tuesday</option>
                <option value="Wednesday">Wednesday</option>
                <option value="Thursday">Thursday</option>
                <option value="Friday">Friday</option>
                <option value="Saturday">Saturday</option>
                
            </select>

            <label>Start Time</label>

            <input
                type="time"
                name="time_start"
                id="edit_start"
                style="width:100%;margin-bottom:12px;"
            >

            <label>End Time</label>

            <input
                type="time"
                name="time_end"
                id="edit_end"
                style="width:100%;margin-bottom:12px;"
            >

            <div style="display:flex;gap:10px;">

                <button class="btn-create" type="submit">
                    Save Changes
                </button>

                <button
                    type="button"
                    class="btn-danger"
                    onclick="closeEditModal()"
                >
                    Cancel
                </button>

            </div>

        </form>

    </div>

</div>

<script src="../assets/js/admin-notifications.js"></script>

</body>
</html>
