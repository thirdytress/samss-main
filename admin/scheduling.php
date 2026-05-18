<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/mail.php';

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

function schedule_badge_html(string $status): string
{
    $status = strtolower(trim($status));
    $colorClass = match ($status) {
        'deployed' => 'sched-item__badge--blue',
        'accepted' => 'sched-item__badge--confirmed', // Green
        'assigned', 'pending' => 'sched-item__badge--declined', // Red
        'declined' => 'sched-item__badge--declined',
        default => 'sched-item__badge--pending',
    };
    
    $label = match ($status) {
        'deployed' => 'Deployed',
        'accepted' => 'Accepted',
        'assigned', 'pending' => 'Not Accepted',
        'declined' => 'Declined',
        default => ucfirst($status),
    };
    
    return '<span class="sched-item__badge ' . $colorClass . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
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
                     AND ds.status <> 'declined'
                     AND (
                         (ds.start_time < :end_time AND ds.end_time > :start_time)
                     )"
        );

    if ($hasOfficeColumn) {
        $insertStmt = $pdo->prepare(
            "INSERT INTO duty_schedules
                (application_id, office_name, term_id, day_of_week, start_time, end_time, status)
             VALUES
                (:application_id_insert, :office_name_insert, :term_id_insert, :day_of_week_insert, :start_time_insert, :end_time_insert, 'assigned')"
        );
    } else {
        $insertStmt = $pdo->prepare(
            "INSERT INTO duty_schedules
                (application_id, term_id, day_of_week, start_time, end_time, status)
             VALUES
                (:application_id_insert, :term_id_insert, :day_of_week_insert, :start_time_insert, :end_time_insert, 'assigned')"
        );
    }

    foreach ($approved as $student) {
        $office = trim((string) $student['preferred_office']);

        if ($office === '') {
            $skipped++;
            $errors[] = 'Skipped student ID ' . $student['student_id'] . ': no preferred office.';
            continue;
        }

        // Check if student already has any existing schedules for this term
        $checkExistingStmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM duty_schedules ds
             JOIN applications a ON a.application_id = ds.application_id
             WHERE a.student_id = :student_id
                 AND ds.term_id = :term_id
                 AND ds.status <> 'declined'"
        );
        $checkExistingStmt->execute([
            'student_id' => (int) $student['student_id'],
            'term_id' => (int) $student['term_id'],
        ]);

        if ((int) $checkExistingStmt->fetchColumn() > 0) {
            $skipped++;
            $errors[] = 'Skipped student ID ' . $student['student_id'] . ': already has existing schedules.';
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

            $availableStart = time_to_minutes((string) $availability['time_start']);
            $availableEnd = time_to_minutes((string) $availability['time_end']);

            if ($availableEnd <= $availableStart) {
                $skipped++;
                continue;
            }

            // Enforce 2-hour minimum and maximum 4 hours per day
            $scheduleStart = $availableStart;
            $scheduleEnd = min($availableEnd, $scheduleStart + (4 * 60));

            $durationMinutes = $scheduleEnd - $scheduleStart;

            // Skip if less than 2 hours (120 minutes)
            if ($durationMinutes < 120) {
                $skipped++;
                $errors[] = sprintf('Skipped student %s on %s: available window too small (needs 2+ hours).', $student['student_id'], $day);
                continue;
            }

            $startTime = minutes_to_time($scheduleStart);
            $endTime = minutes_to_time($scheduleEnd);

            // Check for time conflicts with existing schedules (allow multiple schedules per day if no overlap)
            $existingStmt->execute([
                'student_id' => (int) $student['student_id'],
                'term_id' => (int) $student['term_id'],
                'day_of_week' => $day,
                'start_time' => $startTime,
                'end_time' => $endTime,
            ]);

            if ((int) $existingStmt->fetchColumn() > 0) {
                $skipped++;
                $errors[] = sprintf('Skipped student %s on %s: time conflict with existing schedule.', $student['student_id'], $day);
                continue;
            }

            $insertStmt->execute([
                'application_id_insert' => (int) $student['application_id'],
                'office_name_insert' => $office,
                'term_id_insert' => (int) $student['term_id'],
                'day_of_week_insert' => $day,
                'start_time_insert' => $startTime,
                'end_time_insert' => $endTime,
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

// Show status filter: all | applied | approved | deployed
$showStatus = trim((string) ($_GET['show_status'] ?? 'all'));
$allowedStatus = ['all','applied','approved','deployed'];
if (!in_array($showStatus, $allowedStatus, true)) { $showStatus = 'all'; }

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

        if ($action === 'approve_application') {
            $applicationId = (int) ($_POST['application_id'] ?? 0);
            if ($applicationId <= 0) {
                throw new RuntimeException('Invalid application selected.');
            }

            // Load application and ensure it's pending
            $appCheck = $pdo->prepare('SELECT application_id, student_id, term_id, preferred_office FROM applications WHERE application_id = :aid AND status = "pending" LIMIT 1');
            $appCheck->execute(['aid' => $applicationId]);
            $appRow = $appCheck->fetch(PDO::FETCH_ASSOC);
            if (!$appRow) {
                throw new RuntimeException('Application not found or already processed.');
            }

            $availStmt = $pdo->prepare(
                "SELECT day_of_week, start_time AS time_start, end_time AS time_end
                 FROM availability
                 WHERE application_id = :application_id
                   AND term_id = :term_id
                 ORDER BY FIELD(day_of_week, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'), start_time ASC"
            );
            $availStmt->execute(['application_id' => $applicationId, 'term_id' => (int)$appRow['term_id']]);
            $availRows = $availStmt->fetchAll(PDO::FETCH_ASSOC);

            if (!$availRows) {
                throw new RuntimeException('No availability saved for this applicant.');
            }

            // Insert duty_schedules for each availability
            if ($dutyScheduleHasOfficeColumn) {
                $insertAppStmt = $pdo->prepare(
                    'INSERT INTO duty_schedules (application_id, office_name, term_id, day_of_week, start_time, end_time, status) VALUES (:application_id, :office_name, :term_id, :day_of_week, :time_start, :time_end, "assigned")'
                );
            } else {
                $insertAppStmt = $pdo->prepare(
                    'INSERT INTO duty_schedules (application_id, term_id, day_of_week, start_time, end_time, status) VALUES (:application_id, :term_id, :day_of_week, :time_start, :time_end, "assigned")'
                );
            }

            foreach ($availRows as $arow) {
                $day = schedule_day_label((string)$arow['day_of_week']);
                if ($dutyScheduleHasOfficeColumn) {
                    $insertAppStmt->execute([
                        'application_id' => $applicationId,
                        'office_name' => $appRow['preferred_office'],
                        'term_id' => (int)$appRow['term_id'],
                        'day_of_week' => $day,
                        'time_start' => (string)$arow['time_start'],
                        'time_end' => (string)$arow['time_end'],
                    ]);
                } else {
                    $insertAppStmt->execute([
                        'application_id' => $applicationId,
                        'term_id' => (int)$appRow['term_id'],
                        'day_of_week' => $day,
                        'time_start' => (string)$arow['time_start'],
                        'time_end' => (string)$arow['time_end'],
                    ]);
                }
            }

            // Mark application approved
            $updateApp = $pdo->prepare('UPDATE applications SET status = "approved" WHERE application_id = :aid');
            $updateApp->execute(['aid' => $applicationId]);

            $flashMessage = 'Application approved and schedules created.';
        }

        if ($action === 'deploy_application') {
            $applicationId = (int) ($_POST['application_id'] ?? 0);
            if ($applicationId <= 0) {
                throw new RuntimeException('Invalid application selected.');
            }

            $appCheck = $pdo->prepare('SELECT application_id, student_id, term_id, preferred_office FROM applications WHERE application_id = :aid AND status = "approved" LIMIT 1');
            $appCheck->execute(['aid' => $applicationId]);
            $appRow = $appCheck->fetch(PDO::FETCH_ASSOC);
            if (!$appRow) {
                throw new RuntimeException('Application not approved or not found.');
            }

            $availStmt = $pdo->prepare(
                "SELECT day_of_week, start_time AS time_start, end_time AS time_end
                 FROM availability
                 WHERE application_id = :application_id
                   AND term_id = :term_id
                 ORDER BY FIELD(day_of_week, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'), start_time ASC"
            );
            $availStmt->execute(['application_id' => $applicationId, 'term_id' => (int)$appRow['term_id']]);
            $availRows = $availStmt->fetchAll(PDO::FETCH_ASSOC);

            if (!$availRows) {
                throw new RuntimeException('No availability saved for this applicant.');
            }

            if ($dutyScheduleHasOfficeColumn) {
                $insertAppStmt = $pdo->prepare(
                    'INSERT INTO duty_schedules (application_id, office_name, term_id, day_of_week, start_time, end_time, status) VALUES (:application_id, :office_name, :term_id, :day_of_week, :time_start, :time_end, "assigned")'
                );
            } else {
                $insertAppStmt = $pdo->prepare(
                    'INSERT INTO duty_schedules (application_id, term_id, day_of_week, start_time, end_time, status) VALUES (:application_id, :term_id, :day_of_week, :time_start, :time_end, "assigned")'
                );
            }

            foreach ($availRows as $arow) {
                $day = schedule_day_label((string)$arow['day_of_week']);
                if ($dutyScheduleHasOfficeColumn) {
                    $insertAppStmt->execute([
                        'application_id' => $applicationId,
                        'office_name' => $appRow['preferred_office'],
                        'term_id' => (int)$appRow['term_id'],
                        'day_of_week' => $day,
                        'time_start' => (string)$arow['time_start'],
                        'time_end' => (string)$arow['time_end'],
                    ]);
                } else {
                    $insertAppStmt->execute([
                        'application_id' => $applicationId,
                        'term_id' => (int)$appRow['term_id'],
                        'day_of_week' => $day,
                        'time_start' => (string)$arow['time_start'],
                        'time_end' => (string)$arow['time_end'],
                    ]);
                }
            }

            $flashMessage = 'Schedules created for approved application.';
        }

        if ($action === 'edit_schedule') {
            $studentId = (int)($_POST['student_id'] ?? 0);
            $officeName = trim((string) ($_POST['office_name'] ?? ''));
            $scheduleJson = trim((string)($_POST['schedule_json'] ?? ''));
            
            if ($studentId <= 0) {
                throw new RuntimeException('Invalid student selected.');
            }
            if ($officeName !== '' && !in_array($officeName, $officeOptions, true)) {
                throw new RuntimeException('Please choose a valid office.');
            }
            
            $entries = json_decode($scheduleJson, true);
            if (!is_array($entries) || empty($entries)) {
                throw new RuntimeException('No schedule blocks provided.');
            }
            
            // Get the application ID
            $appStmt = $pdo->prepare('
                SELECT a.application_id, a.term_id, a.preferred_office, u.email, u.first_name, u.last_name
                FROM applications a
                INNER JOIN students s ON s.student_id = a.student_id
                INNER JOIN users u ON u.user_id = s.user_id
                WHERE a.student_id = :sid 
                ORDER BY a.application_id DESC LIMIT 1
            ');
            $appStmt->execute(['sid' => $studentId]);
            $appRow = $appStmt->fetch(PDO::FETCH_ASSOC);
            if (!$appRow) {
                throw new RuntimeException('Application not found.');
            }
            $applicationId = (int)$appRow['application_id'];
            $termId = (int)$appRow['term_id'];
            $currentOffice = $officeName !== '' ? $officeName : trim((string) ($appRow['preferred_office'] ?? ''));

            // Delete existing schedules for this application
            $delStmt = $pdo->prepare('DELETE FROM duty_schedules WHERE application_id = :aid');
            $delStmt->execute(['aid' => $applicationId]);

            // Insert new schedules (as 'accepted' so they appear in real-time on supervisor dashboard)
            if ($dutyScheduleHasOfficeColumn) {
                $insertAppStmt = $pdo->prepare(
                    'INSERT INTO duty_schedules (application_id, office_name, term_id, day_of_week, start_time, end_time, status) VALUES (:application_id, :office_name, :term_id, :day_of_week, :time_start, :time_end, "accepted")'
                );
            } else {
                $insertAppStmt = $pdo->prepare(
                    'INSERT INTO duty_schedules (application_id, term_id, day_of_week, start_time, end_time, status) VALUES (:application_id, :term_id, :day_of_week, :time_start, :time_end, "accepted")'
                );
            }

            foreach ($entries as $entry) {
                $day = schedule_day_label((string)$entry['day_of_week']);
                if ($dutyScheduleHasOfficeColumn) {
                    $insertAppStmt->execute([
                        'application_id' => $applicationId,
                        'office_name' => $currentOffice,
                        'term_id' => $termId,
                        'day_of_week' => $day,
                        'time_start' => (string)$entry['time_start'],
                        'time_end' => (string)$entry['time_end'],
                    ]);
                } else {
                    $insertAppStmt->execute([
                        'application_id' => $applicationId,
                        'term_id' => $termId,
                        'day_of_week' => $day,
                        'time_start' => (string)$entry['time_start'],
                        'time_end' => (string)$entry['time_end'],
                    ]);
                }
            }

            if ($officeName !== '') {
                $redirectOffice = $officeName;
            }

            // Send email notification to student
            try {
                $emailDetails = ['Office' => $currentOffice];
                $daysList = [];
                foreach ($entries as $entry) {
                    $day = (string)($entry['day_of_week'] ?? '');
                    if ($day === '') continue;
                    $start = date('g:i A', strtotime((string)($entry['time_start'] ?? '')));
                    $end = date('g:i A', strtotime((string)($entry['time_end'] ?? '')));
                    if (!isset($daysList[$day])) {
                        $daysList[$day] = [];
                    }
                    $daysList[$day][] = "$start - $end";
                }
                foreach ($daysList as $day => $times) {
                    $emailDetails[$day] = implode(', ', $times);
                }

                sams_send_schedule_email(
                    (string)($appRow['email'] ?? ''),
                    trim((string)($appRow['first_name'] ?? '') . ' ' . (string)($appRow['last_name'] ?? '')),
                    'edit',
                    $emailDetails
                );
            } catch (Throwable $e) {
                $flashError = 'Schedule updated, but email could not be sent: ' . $e->getMessage();
            }
            $flashMessage = 'Student schedule updated successfully.';
            $redirectStudentId = $studentId;
        }

        if ($action === 'accept_schedule') {
            $studentId = (int)($_POST['student_id'] ?? 0);
            if ($studentId <= 0) {
                throw new RuntimeException('Invalid student selected.');
            }
            $statement = $pdo->prepare("UPDATE duty_schedules ds
                INNER JOIN applications a ON a.application_id = ds.application_id
                SET ds.status = 'accepted'
                WHERE a.student_id = :sid AND ds.status = 'assigned'");
            $statement->execute(['sid' => $studentId]);
            $flashMessage = 'All assigned schedules for this student have been accepted.';
            $redirectStudentId = $studentId;
        }

        if ($action === 'deploy_student') {
            $studentId = (int)($_POST['student_id'] ?? 0);
            if ($studentId <= 0) {
                throw new RuntimeException('Invalid student selected.');
            }
            $statement = $pdo->prepare("UPDATE duty_schedules ds
                INNER JOIN applications a ON a.application_id = ds.application_id
                SET ds.status = 'deployed'
                WHERE a.student_id = :sid AND (ds.status = 'assigned' OR ds.status = 'accepted')");
            $statement->execute(['sid' => $studentId]);
            $flashMessage = 'Student deployed. Schedules are now visible on the supervisor dashboard.';
            $redirectStudentId = $studentId;
        }

        if ($action === 'undeploy_student') {
            $studentId = (int)($_POST['student_id'] ?? 0);
            if ($studentId <= 0) {
                throw new RuntimeException('Invalid student selected.');
            }
            $statement = $pdo->prepare("UPDATE duty_schedules ds
                INNER JOIN applications a ON a.application_id = ds.application_id
                SET ds.status = 'accepted'
                WHERE a.student_id = :sid AND ds.status = 'deployed'");
            $statement->execute(['sid' => $studentId]);
            $flashMessage = 'Student undeployed. You can now edit their schedule.';
            $redirectStudentId = $studentId;
        }

        if ($action === 'update_status') {
            $scheduleId = (int) ($_POST['schedule_id'] ?? 0);
            $status = (string) ($_POST['status'] ?? '');

            if ($scheduleId <= 0 || !in_array($status, ['pending', 'accepted', 'declined'], true)) {
                throw new RuntimeException('Invalid schedule status update.');
            }

            // Fetch schedule and user info
            $scheduleStmt = $pdo->prepare(
                'SELECT ds.application_id, ds.office_name, ds.status, a.preferred_office, a.student_id, u.email, u.first_name, u.last_name
                 FROM duty_schedules ds
                 INNER JOIN applications a ON a.application_id = ds.application_id
                 INNER JOIN students s ON s.student_id = a.student_id
                 INNER JOIN users u ON u.user_id = s.user_id
                 WHERE ds.duty_id = :duty_id
                 LIMIT 1'
            );
            $scheduleStmt->execute(['duty_id' => $scheduleId]);
            $scheduleRow = $scheduleStmt->fetch(PDO::FETCH_ASSOC);
            if (!$scheduleRow) {
                throw new RuntimeException('Schedule not found.');
            }

            $statement = $pdo->prepare(
                'UPDATE duty_schedules SET status = :status, student_response_date = NOW() WHERE duty_id = :duty_id'
            );
            $statement->execute(['status' => $status, 'duty_id' => $scheduleId]);

            // Send email if accepted
            if ($status === 'accepted') {
                try {
                    sams_send_schedule_email(
                        (string)($scheduleRow['email'] ?? ''),
                        trim((string)($scheduleRow['first_name'] ?? '') . ' ' . (string)($scheduleRow['last_name'] ?? '')),
                        'accept',
                        [
                            'office' => $scheduleRow['office_name'] ?? $scheduleRow['preferred_office'] ?? '',
                        ]
                    );
                } catch (Throwable $e) {
                    $flashError = 'Schedule accepted, but email could not be sent: ' . $e->getMessage();
                }
            }
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


// Only fetch schedules if a specific student is selected
if ($selectedStudentId > 0) {
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
     WHERE a.student_id = :student_id";
    $scheduleParams = ['student_id' => $selectedStudentId];
    if ($selectedDay !== '') {
        $scheduleSql .= ' AND ds.day_of_week = :day_of_week';
        $scheduleParams['day_of_week'] = $selectedDay;
    }
    $scheduleSql .= " ORDER BY FIELD(ds.day_of_week, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'), ds.start_time ASC, u.last_name ASC";
    $scheduleStmt = $pdo->prepare($scheduleSql);
    $scheduleStmt->execute($scheduleParams);
    $schedules = $scheduleStmt->fetchAll();
} else {
    // If 'All Students' is selected, show no schedules
    $schedules = [];
}

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

// Pending applications for admin review
$pendingApplicationsStmt = $pdo->prepare(
    "SELECT a.application_id, a.student_id, a.term_id, a.preferred_office, a.created_at, s.student_id_number AS student_code, u.first_name, u.last_name
     FROM applications a
     INNER JOIN students s ON s.student_id = a.student_id
     INNER JOIN users u ON u.user_id = s.user_id
     WHERE a.status = 'pending'
     ORDER BY a.created_at DESC"
);
$pendingApplicationsStmt->execute();
$pendingApplications = $pendingApplicationsStmt->fetchAll(PDO::FETCH_ASSOC);

// All applicants (for student dropdown)
$studentsListStmt = $pdo->query(
    "SELECT DISTINCT a.student_id, st.student_id_number AS student_code, u.first_name, u.last_name
     FROM applications a
     INNER JOIN students st ON st.student_id = a.student_id
     INNER JOIN users u ON u.user_id = st.user_id
     ORDER BY u.last_name ASC, u.first_name ASC"
);
$studentsList = $studentsListStmt->fetchAll(PDO::FETCH_ASSOC);

// If a student is selected, load their preferred application and availability
$selectedPreferred = null;
if ($selectedStudentId > 0) {
    $appStmt = $pdo->prepare('SELECT application_id, term_id, preferred_office, status FROM applications WHERE student_id = :sid ORDER BY application_id DESC LIMIT 1');
    $appStmt->execute(['sid' => $selectedStudentId]);
    $selectedPreferred = $appStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($selectedPreferred) {
        $availStmt = $pdo->prepare("SELECT day_of_week, start_time AS time_start, end_time AS time_end FROM availability WHERE application_id = :aid AND term_id = :term_id ORDER BY FIELD(day_of_week, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'), start_time ASC");
        $availStmt->execute(['aid' => (int)$selectedPreferred['application_id'], 'term_id' => (int)$selectedPreferred['term_id']]);
        $selectedPreferred['availability'] = $availStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Approved applicants
$approvedApplicantsStmt = $pdo->prepare(
    "SELECT a.application_id, a.student_id, a.term_id, a.preferred_office, s.student_id_number AS student_code, u.first_name, u.last_name, a.created_at
     FROM applications a
     INNER JOIN students s ON s.student_id = a.student_id
     INNER JOIN users u ON u.user_id = s.user_id
     WHERE a.status = 'approved'
     ORDER BY a.created_at DESC"
);
$approvedApplicantsStmt->execute();
$approvedApplicants = $approvedApplicantsStmt->fetchAll(PDO::FETCH_ASSOC);

// Deployed (students with schedules)
$deployedStmt = $pdo->prepare(
    "SELECT DISTINCT a.application_id, a.student_id, st.student_id_number AS student_code, u.first_name, u.last_name, COALESCE(ds.office_name, a.preferred_office) AS office_name
     FROM duty_schedules ds
     INNER JOIN applications a ON a.application_id = ds.application_id
     INNER JOIN students st ON st.student_id = a.student_id
     INNER JOIN users u ON u.user_id = st.user_id
     WHERE ds.status <> 'declined'
     ORDER BY u.last_name ASC"
);
$deployedStmt->execute();
$deployedStudents = $deployedStmt->fetchAll(PDO::FETCH_ASSOC);

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
$calendarEndHour = 17; // default 5pm

// If selected preferred office or any scheduled office is Clinic, set end hour to 20 (8pm)
$isClinic = false;
if (!empty($selectedPreferred['preferred_office']) && strtolower(trim($selectedPreferred['preferred_office'])) === 'clinic') {
    $isClinic = true;
}
if (!$isClinic && !empty($schedules)) {
    foreach ($schedules as $sch) {
        if (isset($sch['office_name']) && strtolower(trim($sch['office_name'])) === 'clinic') {
            $isClinic = true;
            break;
        }
    }
}
if ($isClinic) {
    $calendarEndHour = 20; // 8pm
}
$hours = range($calendarStartHour, $calendarEndHour); // include last hour (e.g., 17 for 5pm)
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
        .sched-grid{display:grid;grid-template-columns:900px 360px;gap:24px;align-items:start;justify-content:center}.calendar-card,.card{background:#fff;border:1px solid var(--color-border);border-radius:var(--radius-card);box-shadow:var(--shadow-card);overflow:hidden}.cal-days{display:grid;grid-template-columns:64px repeat(6,1fr);border-bottom:1px solid var(--color-border)}.cal-days__day{padding:16px 10px;text-align:center;border-left:1px solid var(--color-border)}.cal-days__day-name{font-size:var(--font-xs);font-weight:700;color:var(--color-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px}.cal-days__day-num{font-size:var(--font-md);font-weight:700}.cal-body{display:grid;grid-template-columns:64px repeat(6,1fr)}.cal-time-col{display:flex;flex-direction:column}.cal-time-slot{height:80px;padding:8px 8px 0 0;text-align:right;font-size:var(--font-xs);color:var(--color-muted);border-bottom:1px solid var(--color-border);flex-shrink:0}.cal-day-col{border-left:1px solid var(--color-border);display:flex;flex-direction:column;min-height:<?= count($hours) * 80 ?>px;position:relative}.cal-day-col__slot{height:80px;flex-shrink:0;border-bottom:1px solid var(--color-border)}.sched-block{position:absolute;left:6px;right:6px;border-radius:var(--radius-block);padding:8px;overflow:hidden;transition:opacity .15s}.sched-block--blue{background:#dbeafe;border-left:3px solid var(--sched-blue)}.sched-block--green{background:#dcfce7;border-left:3px solid var(--sched-green)}.sched-block--purple{background:#f3e8ff;border-left:3px solid var(--sched-purple)}.sched-block--orange{background:#ffedd4;border-left:3px solid var(--sched-orange)}.sched-block--yellow{background:#fef9c2;border-left:3px solid var(--sched-yellow)}.sched-block--red{background:#fee2e2;border-left:3px solid #dc2626}.sched-block__name{font-size:var(--font-xs);font-weight:700;margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.sched-block__time,.sched-block__loc{font-size:11px;color:var(--color-body)}
        .sched-right{display:flex;flex-direction:column;gap:24px}.card{padding:24px}.card__title{font-size:var(--font-md);font-weight:700;margin-bottom:16px}.sched-list{display:flex;flex-direction:column;gap:12px;max-height:560px;overflow-y:auto}.sched-item{background:var(--color-bg-app);border-radius:var(--radius-nav);padding:16px;display:flex;flex-direction:column;gap:8px;border-left:4px solid transparent}.sched-item--blue{border-left-color:var(--sched-blue)}.sched-item--green{border-left-color:var(--sched-green)}.sched-item--purple{border-left-color:var(--sched-purple)}.sched-item--orange{border-left-color:var(--sched-orange)}.sched-item--yellow{border-left-color:var(--sched-yellow)}.sched-item__name{font-size:var(--font-base);font-weight:700}.sched-item__time,.sched-item__loc{font-size:var(--font-sm);color:var(--color-body)}.sched-item__actions{display:flex;gap:6px;flex-wrap:wrap;margin-top:4px}.sched-item__badge{display:inline-block;padding:4px 10px;border-radius:var(--radius-badge);font-size:var(--font-xs);font-weight:700}.sched-item__badge--confirmed{background:var(--color-green-bg);color:var(--color-green-text)}.sched-item__badge--pending{background:var(--color-yellow-bg);color:var(--color-yellow-text)}.sched-item__badge--declined{background:var(--color-red-bg);color:var(--color-red-text)}.sched-item__badge--blue{background:var(--color-blue-bg);color:var(--color-blue-text)}
        .table-card{background:#fff;border:1px solid var(--color-border);border-radius:var(--radius-card);box-shadow:var(--shadow-card);overflow:hidden}.schedule-table{width:100%;border-collapse:collapse}.schedule-table th,.schedule-table td{padding:12px 14px;border-bottom:1px solid var(--color-border);text-align:left;font-size:14px}.schedule-table th{background:#f8fafc;color:var(--color-muted);text-transform:uppercase;font-size:12px;letter-spacing:.04em}.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:90}.sidebar-overlay--visible{display:block}
        @media(max-width:1200px){.sched-grid{grid-template-columns:1fr}.stats-row{grid-template-columns:repeat(2,1fr)}}@media(max-width:1024px){.sidebar{position:fixed;left:0;top:0;height:100%;z-index:100;transform:translateX(-100%);transition:transform .3s ease}.sidebar--open{transform:translateX(0)}.topbar__hamburger{display:flex}.topbar{padding:0 24px}.scheduling{padding:24px}}@media(max-width:768px){.topbar{padding:0 16px}.topbar__user-info{display:none}.scheduling{padding:16px;gap:16px}.stats-row{grid-template-columns:1fr}.calendar-card{overflow-x:auto}.cal-days,.cal-body{min-width:980px}}
        /* Override: center the scheduling grid and set fixed column widths */
        .sched-grid{max-width:1260px;margin:0 auto !important;display:flex !important;justify-content:center;gap:24px;align-items:flex-start}
        .sched-grid > .calendar-card{flex:0 0 900px;max-width:900px}
        .sched-grid > .sched-right{flex:0 0 360px;max-width:360px}
    </style>
</head>
<body>
<div class="sidebar-overlay" id="sidebar-overlay" aria-hidden="true"></div>
<div class="shell">
    <?php
        $activeAdminNav = 'scheduling';
        // Preserve any existing $pendingApplications (array of rows) for this page
        $__pendingApplications_backup = $pendingApplications ?? null;
        $pendingApplications = (int) $applicationBadgeCount;
        include __DIR__ . '/_sidebar.php';
        // Restore the original variable (if it existed) so later code can iterate it
        if ($__pendingApplications_backup !== null) {
            $pendingApplications = $__pendingApplications_backup;
        } else {
            unset($pendingApplications);
        }
    ?>
    </aside>
    <div class="main">
        <header class="topbar" role="banner">
            <div class="topbar__left-wrap"><button class="topbar__hamburger" id="hamburger-btn" aria-expanded="false" aria-controls="sidebar" aria-label="Toggle navigation"><span class="topbar__hamburger-bar"></span><span class="topbar__hamburger-bar"></span><span class="topbar__hamburger-bar"></span></button><div><div class="topbar__title">Scheduling</div><div class="topbar__sub"><?= h($department) ?></div></div></div>
            <div class="topbar__right"><div class="topbar__notif-btn" role="button" aria-label="Notifications" tabindex="0"><svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M10 2a6 6 0 00-6 6v3.586l-.707.707A1 1 0 004 14h12a1 1 0 00.707-1.707L16 11.586V8a6 6 0 00-6-6zM10 18a3 3 0 01-3-3h6a3 3 0 01-3 3z" fill="#4A5565"/></svg><span class="topbar__notif-dot" aria-hidden="true"></span></div><div class="topbar__user-info" aria-label="Logged in user"><div class="topbar__user-name"><?= h($admin_name) ?></div><div class="topbar__user-role"><?= h($admin_role) ?></div></div><div class="topbar__avatar" aria-hidden="true"><svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="10" cy="7" r="4" fill="white" opacity=".9"/><path d="M2 17c0-3.314 3.582-6 8-6s8 2.686 8 6" fill="white" opacity=".9"/></svg></div></div>
        </header>
        <main class="scheduling" id="main-content">
            <div class="sched-header">
                                <div class="sched-header__left"></div>
                <div class="sched-header__right">
                    <div style="display:flex;align-items:center;gap:12px;">
                        <div style="font-weight:700;color:var(--color-body);">Pending applications appear below for review.</div>
                        <form method="GET" style="margin-left:12px;display:flex;gap:8px;align-items:center;">
                            <label for="student_id" style="margin-right:6px;color:var(--color-body);font-weight:600;">Student</label>
                            <select id="student_id" name="student_id" class="sched-select" onchange="this.form.submit()">
                                <option value="">All students</option>
                                <?php foreach ($studentsList as $st): $sid = (int)$st['student_id']; $slabel = trim((string)$st['last_name'] . ', ' . (string)$st['first_name']) . ' (' . (string)$st['student_code'] . ')'; ?>
                                    <option value="<?= $sid ?>" <?= $selectedStudentId === $sid ? 'selected' : '' ?>><?= h($slabel) ?></option>
                                <?php endforeach; ?>
                            </select>

                            <label for="show_status" style="margin-right:6px;color:var(--color-body);font-weight:600;">Show</label>
                            <select id="show_status" name="show_status" onchange="this.form.submit()" class="sched-select">
                                <option value="all" <?php echo $showStatus === 'all' ? 'selected' : ''; ?>>All</option>
                                <option value="applied" <?php echo $showStatus === 'applied' ? 'selected' : ''; ?>>Applied</option>
                                <option value="approved" <?php echo $showStatus === 'approved' ? 'selected' : ''; ?>>Approved</option>
                                <option value="deployed" <?php echo $showStatus === 'deployed' ? 'selected' : ''; ?>>Deployed</option>
                                <option value="undeployed" <?php echo $showStatus === 'undeployed' ? 'selected' : ''; ?>>Undeployed</option>
                            </select>
                        </form>
                    </div>
                </div>
            </div>
            <?php if ($flashMessage !== ''): ?><div class="alert alert-success"><?= h($flashMessage) ?></div><?php endif; ?>
            <?php if ($flashError !== ''): ?><div class="alert alert-error"><?= h($flashError) ?></div><?php endif; ?>
                        <!-- stats cards removed as requested -->

                        <div class="sched-flex" style="display:flex;max-width:1260px;margin:0 auto;gap:24px;align-items:flex-start;">
                            <div style="flex:0 0 900px;max-width:900px;display:flex;flex-direction:column;gap:24px;">
                                <div style="display:flex;flex-direction:column;align-items:center;gap:4px;margin-bottom:8px;">
                                    <?php if ($selectedPreferred): ?>
                                        <span style="font-size:22px;font-weight:900;letter-spacing:0.5px;">Preferred Schedule</span>
                                        <span style="font-size:16px;color:#364153;">Preferred Office: <strong><?= h((string)$selectedPreferred['preferred_office']) ?></strong></span>
                                    <?php endif; ?>
                                </div>
                                <section class="calendar-card" aria-label="Weekly schedule calendar" style="margin-left:auto;margin-right:auto;">
                                    <div class="cal-days" role="row"><div class="cal-days__time-gutter" aria-hidden="true"></div><?php foreach ($days as $day): ?><div class="cal-days__day"><div class="cal-days__day-name"><?= h($dayShort[$day]) ?></div><div class="cal-days__day-num"><?= h($day) ?></div></div><?php endforeach; ?></div><div class="cal-body" role="grid" aria-label="Calendar time grid"><div class="cal-time-col" aria-hidden="true"><?php foreach ($hours as $hour): ?><div class="cal-time-slot"><?= date('g A', strtotime(sprintf('%02d:00:00', $hour))) ?></div><?php endforeach; ?></div><?php foreach ($days as $day): ?><div class="cal-day-col" role="gridcell" aria-label="<?= h($day) ?>"><?php foreach ($hours as $_): ?><div class="cal-day-col__slot"></div><?php endforeach; ?><?php foreach ($schedules as $schedule): ?><?php $scheduleDay = schedule_day_label((string) $schedule['day_of_week']); if ($scheduleDay !== $day || $schedule['status'] === 'declined') continue; ?><?php $studentName = trim((string) $schedule['first_name'] . ' ' . (string) $schedule['last_name']); $office = (string) $schedule['office_name']; $statusStr = strtolower(trim($schedule['status'])); $color = match($statusStr) { 'deployed' => 'blue', 'accepted' => 'green', 'assigned', 'pending' => 'red', default => 'yellow' }; ?><div class="sched-block sched-block--<?= h($color) ?>" style="<?= h(calendar_block_style((string) $schedule['time_start'], (string) $schedule['time_end'])) ?>" title="<?= h($studentName . ' - ' . $office) ?>"><div class="sched-block__name"><?= h($studentName) ?></div><div class="sched-block__time"><?= h(display_time((string) $schedule['time_start']) . ' – ' . display_time((string) $schedule['time_end'])) ?></div><div class="sched-block__loc"><?= h($office) ?></div></div><?php endforeach; ?></div><?php endforeach; ?></div>
                                </section>
                                <?php
                                $isStudentDeployed = false;
                                if (!empty($schedules)) {
                                    foreach ($schedules as $s) {
                                        if ($s['status'] === 'deployed') {
                                            $isStudentDeployed = true;
                                            break;
                                        }
                                    }
                                }
                                ?>
                                <div style="display:flex;gap:18px;justify-content:flex-start;align-items:center;margin:18px 0 0 0;">
                                    <?php if ($isStudentDeployed): ?>
                                        <form method="POST" style="margin:0;">
                                            <input type="hidden" name="action" value="undeploy_student">
                                            <input type="hidden" name="student_id" value="<?= (int)$selectedStudentId ?>">
                                            <button type="submit" class="btn-create" style="background:#f59e42;min-width:150px;font-size:15px;box-shadow:0 2px 8px rgba(245,158,66,0.08);">Undeploy Student</button>
                                        </form>
                                        <?php if (!empty($schedules)): $firstSchedule = $schedules[0]; ?>
                                            <button type="button" class="btn-small" style="background:#e5e7eb;color:#9ca3af;min-width:150px;font-size:15px;cursor:not-allowed;" disabled>Edit Schedule</button>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <form method="POST" style="margin:0;">
                                            <input type="hidden" name="action" value="accept_schedule">
                                            <input type="hidden" name="student_id" value="<?= (int)$selectedStudentId ?>">
                                            <button type="submit" class="btn-create" style="min-width:150px;font-size:15px;box-shadow:0 2px 8px rgba(21,93,252,0.08);">Accept Schedule</button>
                                        </form>
                                        <form method="POST" style="margin:0;">
                                            <input type="hidden" name="action" value="deploy_student">
                                            <input type="hidden" name="student_id" value="<?= (int)$selectedStudentId ?>">
                                            <button type="submit" class="btn-create" style="background:#00a63e;min-width:150px;font-size:15px;box-shadow:0 2px 8px rgba(0,166,62,0.08);">Deploy Student</button>
                                        </form>
                                        <?php if (!empty($schedules)): $firstSchedule = $schedules[0]; ?>
                                            <button type="button" class="btn-small" style="background:#eef2ff;color:#3730a3;min-width:150px;font-size:15px;box-shadow:0 2px 8px rgba(55,48,163,0.08);" onclick="openEditModal(<?= (int)$firstSchedule['student_id'] ?>)">Edit Schedule</button>
                                        <?php else: ?>
                                            <button type="button" class="btn-small" style="background:#eef2ff;color:#3730a3;min-width:150px;font-size:15px;box-shadow:0 2px 8px rgba(55,48,163,0.08);" onclick="alert('No schedule to edit.')">Edit Schedule</button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                                <section class="table-card" style="margin-top:24px;"><table class="schedule-table"><thead><tr><th>Student</th><th>Office</th><th>Day</th><th>Time</th><th>Hours</th><th>Status</th><th>Action</th></tr></thead><tbody><?php if (!$schedules): ?><tr><td colspan="7">No schedules found. Click Auto Generate Schedule.</td></tr><?php endif; ?><?php foreach ($schedules as $schedule): ?><?php $studentName = trim((string) $schedule['first_name'] . ' ' . (string) $schedule['last_name']); ?><tr><td><?= h($studentName) ?></td><td><?= h((string) $schedule['office_name']) ?></td><td><?= h(schedule_day_label((string) $schedule['day_of_week'])) ?></td><td><?= h(display_time((string) $schedule['time_start']) . ' – ' . display_time((string) $schedule['time_end'])) ?></td><td><?= h(number_format((float) $schedule['required_hours'], 2)) ?>h</td><td><?= schedule_badge_html((string) $schedule['status']) ?></td><td><?php if ($schedule['status'] === 'deployed'): ?><button type="button" class="btn-small" style="background:#e5e7eb;color:#9ca3af;cursor:not-allowed;" disabled>Edit</button><?php else: ?><button type="button" class="btn-small" onclick="openEditModal(<?= (int)$schedule['student_id'] ?>)">Edit</button><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></section>
                            </div>
                            <div class="sched-right" style="flex:0 0 360px;max-width:360px;display:flex;flex-direction:column;gap:24px;">
                    <section class="card" aria-labelledby="pending-heading">
                        <h2 class="card__title" id="pending-heading">Pending Applications</h2>
                        <!-- Preferred schedule shown above calendar -->
                        <div class="sched-list">
                            <?php if (($showStatus === 'all' || $showStatus === 'applied')): ?>
                                <?php if (empty($pendingApplications)): ?>
                                    <div class="sched-item">No pending applications.</div>
                                <?php endif; ?>

                                <?php foreach ($pendingApplications as $app): ?>
                                <?php
                                    $appName = trim((string)$app['first_name'] . ' ' . (string)$app['last_name']);
                                    $appOffice = (string)$app['preferred_office'];
                                    $availStmtRender = $pdo->prepare(
                                        "SELECT day_of_week, start_time AS time_start, end_time AS time_end
                                         FROM availability
                                         WHERE application_id = :application_id
                                           AND term_id = :term_id
                                         ORDER BY FIELD(day_of_week, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'), start_time ASC"
                                    );
                                    $availStmtRender->execute(['application_id' => $app['application_id'], 'term_id' => $app['term_id']]);
                                    $availRows = $availStmtRender->fetchAll(PDO::FETCH_ASSOC);
                                ?>
                                <div class="sched-item sched-item--<?= h(schedule_color($appOffice)) ?>">
                                    <div class="sched-item__name"><?= h($appName) ?></div>
                                    <div class="sched-item__loc"><strong>Preferred Office:</strong> <?= h($appOffice) ?> · <span style="color:#6b7280"><?= h((string)$app['student_code']) ?></span></div>
                                    <div class="sched-item__time">
                                        <?php if (empty($availRows)): ?>
                                            <em>No availability provided.</em>
                                        <?php else: ?>
                                            <?php foreach ($availRows as $ar): ?>
                                                <div style="font-size:13px;color:#111827;"><strong><?= h(schedule_day_label((string)$ar['day_of_week'])) ?></strong> — <span style="color:#374151"><?= h(display_time((string)$ar['time_start'])) ?> – <?= h(display_time((string)$ar['time_end'])) ?></span></div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>

                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>

                            <?php if ($showStatus === 'all' || $showStatus === 'approved'): ?>
                                <?php if (empty($approvedApplicants)): ?>
                                    <div class="sched-item">No approved applicants.</div>
                                <?php else: ?>
                                    <?php foreach ($approvedApplicants as $app): ?>
                                        <?php $appName = trim((string)$app['first_name'] . ' ' . (string)$app['last_name']); $appOffice = (string)$app['preferred_office']; ?>
                                        <div class="sched-item sched-item--<?= h(schedule_color($appOffice)) ?>">
                                            <div class="sched-item__name"><?= h($appName) ?></div>
                                            <div class="sched-item__loc">Preferred: <?= h($appOffice) ?> · <?= h((string)$app['student_code']) ?></div>

                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php if ($showStatus === 'all' || $showStatus === 'deployed'): ?>
                                <?php if (empty($deployedStudents)): ?>
                                    <div class="sched-item">No deployed students.</div>
                                <?php else: ?>
                                    <?php foreach ($deployedStudents as $ds): ?>
                                        <?php $dName = trim((string)$ds['first_name'] . ' ' . (string)$ds['last_name']); $dOffice = (string)$ds['office_name']; ?>
                                        <div class="sched-item sched-item--<?= h(schedule_color($dOffice)) ?>">
                                            <div class="sched-item__name"><?= h($dName) ?></div>
                                            <div class="sched-item__loc"><?= h($dOffice) ?> · <?= h((string)$ds['student_code']) ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </section>
                    <!-- Generated Schedules section removed per user request -->
                    <section class="card">
                        <h2 class="card__title">Summary</h2>
                        <p style="font-size:14px;color:#4a5565;line-height:1.7;">Pending: <strong><?= (int) $pendingCount ?></strong><br>Accepted: <strong><?= (int) $acceptedCount ?></strong><br>Declined: <strong><?= (int) $declinedCount ?></strong><br>Students Scheduled: <strong><?= count($studentIds) ?></strong></p>
                    </section>
                </div>
            </div>

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

var studentAvailability = <?= json_encode($selectedPreferred['availability'] ?? []) ?>;
var studentSchedules = <?= json_encode($schedules ?? []) ?>;

function openEditModal(studentId) {
    if (studentSchedules.length === 0) {
        alert('No schedules available to edit for this student.');
        return;
    }

    document.getElementById('edit_student_id').value = studentId;

    // Use the office from the first schedule
    document.getElementById('edit_office').value = studentSchedules[0].office_name;

    // Reset all checkboxes and inputs to default
    var days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    for (var i = 0; i < days.length; i++) {
        document.querySelector('.edit-enabled-morning[data-index="' + i + '"]').checked = false;
        document.querySelector('.edit-start-morning[data-index="' + i + '"]').value = '08:00';
        document.querySelector('.edit-end-morning[data-index="' + i + '"]').value = '12:00';
        
        document.querySelector('.edit-enabled-afternoon[data-index="' + i + '"]').checked = false;
        document.querySelector('.edit-start-afternoon[data-index="' + i + '"]').value = '13:00';
        document.querySelector('.edit-end-afternoon[data-index="' + i + '"]').value = '17:00';
    }

    // Populate existing schedules
    for (var i = 0; i < studentSchedules.length; i++) {
        var s = studentSchedules[i];
        var dayIdx = days.indexOf(s.day_of_week);
        if (dayIdx !== -1) {
            if (s.time_start < '12:30:00') {
                document.querySelector('.edit-enabled-morning[data-index="' + dayIdx + '"]').checked = true;
                document.querySelector('.edit-start-morning[data-index="' + dayIdx + '"]').value = s.time_start.substring(0, 5);
                document.querySelector('.edit-end-morning[data-index="' + dayIdx + '"]').value = s.time_end.substring(0, 5);
            } else {
                document.querySelector('.edit-enabled-afternoon[data-index="' + dayIdx + '"]').checked = true;
                document.querySelector('.edit-start-afternoon[data-index="' + dayIdx + '"]').value = s.time_start.substring(0, 5);
                document.querySelector('.edit-end-afternoon[data-index="' + dayIdx + '"]').value = s.time_end.substring(0, 5);
            }
        }
    }

    // Build overall availability hint
    var hint = document.getElementById('availability_hint');
    if (studentAvailability.length > 0) {
        var hintLines = [];
        for (var d = 0; d < days.length; d++) {
            var dayAvail = [];
            for (var a = 0; a < studentAvailability.length; a++) {
                if (studentAvailability[a].day_of_week === days[d]) {
                    dayAvail.push(studentAvailability[a].time_start.substring(0,5) + '-' + studentAvailability[a].time_end.substring(0,5));
                }
            }
            if (dayAvail.length > 0) {
                hintLines.push('<strong>' + days[d].substring(0,3) + ':</strong> ' + dayAvail.join(', '));
            }
        }
        hint.innerHTML = 'Availability: ' + hintLines.join(' | ');
        hint.style.color = '#047857';
    } else {
        hint.innerHTML = 'No availability indicated.';
        hint.style.color = '#b91c1c';
    }

    document.getElementById('editModal').style.display = 'flex';
}

function closeEditModal() {
    document.getElementById('editModal').style.display = 'none';
}

document.addEventListener('DOMContentLoaded', function() {
    var editForm = document.getElementById('edit-schedule-form');
    if (editForm) {
        editForm.addEventListener('submit', function(e) {
            var entries = [];
            var days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            
            for (var i = 0; i < days.length; i++) {
                var day = days[i];
                
                var enabledMorning = document.querySelector('.edit-enabled-morning[data-index="' + i + '"]');
                var startMorning = document.querySelector('.edit-start-morning[data-index="' + i + '"]');
                var endMorning = document.querySelector('.edit-end-morning[data-index="' + i + '"]');
                if (enabledMorning && enabledMorning.checked) {
                    entries.push({
                        day_of_week: day,
                        time_start: startMorning.value,
                        time_end: endMorning.value
                    });
                }
                
                var enabledAfternoon = document.querySelector('.edit-enabled-afternoon[data-index="' + i + '"]');
                var startAfternoon = document.querySelector('.edit-start-afternoon[data-index="' + i + '"]');
                var endAfternoon = document.querySelector('.edit-end-afternoon[data-index="' + i + '"]');
                if (enabledAfternoon && enabledAfternoon.checked) {
                    entries.push({
                        day_of_week: day,
                        time_start: startAfternoon.value,
                        time_end: endAfternoon.value
                    });
                }
            }
            
            if (entries.length === 0) {
                e.preventDefault();
                alert('Please select at least one schedule block.');
                return false;
            }
            
            document.getElementById('edit_schedule_json').value = JSON.stringify(entries);
        });
    }
});

function openAppModal(id, name, office, avail) {
    var modal = document.getElementById('appModal');
    var body = document.getElementById('appModalBody');
    var hidden = document.getElementById('appModalId');

    hidden.value = String(id);
    var html = '<div style="font-weight:700;margin-bottom:6px;">' + (name || 'Applicant') + '</div>';
    html += '<div style="margin-bottom:6px;">Preferred Office: <strong>' + (office || '-') + '</strong></div>';
    if (!avail || avail.length === 0) {
        html += '<div><em>No availability provided</em></div>';
    } else {
        html += '<div style="margin-top:8px;">';
        for (var i = 0; i < avail.length; i++) {
            var a = avail[i];
            html += '<div>' + (a.day_of_week || '') + ' — ' + (a.time_start || '') + ' – ' + (a.time_end || '') + '</div>';
        }
        html += '</div>';
    }

    body.innerHTML = html;
    modal.style.display = 'flex';
}

function closeAppModal() {
    document.getElementById('appModal').style.display = 'none';
}

</script>

<div
    id="editModal"
    style="
        display:none;
        position:fixed;
        inset:0;
        background:rgba(16, 24, 40, 0.6);
        backdrop-filter: blur(4px);
        z-index:9999;
        align-items:center;
        justify-content:center;
        padding: 20px;
    "
>

    <div
        style="
            background: #ffffff;
            padding: 32px;
            border-radius: 20px;
            width: 100%;
            max-width: 800px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            font-family: 'Inter', sans-serif;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
        "
    >

        <h2 style="margin: 0 0 24px; font-size: 24px; font-weight: 800; color: #111827; display:flex; align-items:center; gap:8px; flex-shrink: 0;">
            <svg style="width:24px;height:24px;color:#003087;" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
            Edit Student Schedule
        </h2>

        <form method="POST" id="edit-schedule-form" style="display:flex; flex-direction:column; min-height:0; flex:1;">
            <input type="hidden" name="action" value="edit_schedule">
            <!-- Now storing student_id instead of a single schedule_id -->
            <input type="hidden" name="student_id" id="edit_student_id">
            <input type="hidden" name="return_office" value="<?= h($selectedOffice) ?>">
            <input type="hidden" name="return_student_id" value="<?= (int) $selectedStudentId ?>">
            <input type="hidden" name="return_day" value="<?= h($selectedDay) ?>">
            
            <!-- We will serialize the checked schedule rows into this hidden input -->
            <input type="hidden" name="schedule_json" id="edit_schedule_json">

            <div style="margin-bottom: 16px; flex-shrink: 0;">
                <label style="display: block; font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: #6b7280; margin-bottom: 8px;">Assign Office</label>
                <select
                    name="office_name"
                    id="edit_office"
                    style="width: 100%; padding: 12px 14px; border: 1px solid #d1d5dc; border-radius: 12px; font-size: 15px; color: #111827; outline: none; background: #fff;"
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
            </div>

            <div id="availability_hint" style="font-size: 13.5px; margin-bottom: 16px; padding: 14px 16px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 12px; color: #047857; flex-shrink: 0;"></div>
            
            <div style="overflow-y: auto; flex: 1; border: 1px solid var(--color-border); border-radius: 12px; margin-bottom: 24px;">
                <table class="availability-table" id="edit_availability_table" style="margin-top:0;">
                    <thead style="position: sticky; top: 0; background: #f8fafc; z-index: 10;">
                        <tr>
                            <th>Day</th>
                            <th>Morning</th>
                            <th>Start</th>
                            <th>End</th>
                            <th>Afternoon</th>
                            <th>Start</th>
                            <th>End</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'] as $dayIndex => $day): ?>
                            <tr data-index="<?= (int) $dayIndex ?>" data-day="<?= htmlspecialchars($day) ?>">
                                <td><?= htmlspecialchars($day) ?></td>
                                <!-- Morning Slot -->
                                <td>
                                    <input type="checkbox" class="edit-enabled-morning" data-index="<?= (int) $dayIndex ?>" />
                                </td>
                                <td>
                                    <div class="time-wrapper">
                                        <input type="time" class="edit-start-morning" data-index="<?= (int) $dayIndex ?>" value="08:00" />
                                    </div>
                                </td>
                                <td>
                                    <div class="time-wrapper">
                                        <input type="time" class="edit-end-morning" data-index="<?= (int) $dayIndex ?>" value="12:00" />
                                    </div>
                                </td>
                                <!-- Afternoon Slot -->
                                <td>
                                    <input type="checkbox" class="edit-enabled-afternoon" data-index="<?= (int) $dayIndex ?>" />
                                </td>
                                <td>
                                    <div class="time-wrapper">
                                        <input type="time" class="edit-start-afternoon" data-index="<?= (int) $dayIndex ?>" value="13:00" />
                                    </div>
                                </td>
                                <td>
                                    <div class="time-wrapper">
                                        <input type="time" class="edit-end-afternoon" data-index="<?= (int) $dayIndex ?>" value="17:00" />
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div style="display:flex; gap:12px; justify-content: flex-end; flex-shrink: 0;">
                <button
                    type="button"
                    style="background: #ffffff; color: #003087; border: 2px solid #003087; padding: 12px 24px; border-radius: 14px; font-weight: 700; font-size: 15px; cursor: pointer; transition: all 0.2s;"
                    onclick="closeEditModal()"
                >
                    Cancel
                </button>
                <button 
                    type="submit" 
                    style="background: linear-gradient(90deg, #ffb81c 0%, #ffa500 100%); color: #003087; padding: 12px 24px; border-radius: 14px; border: none; font-weight: 700; font-size: 15px; cursor: pointer; transition: all 0.2s; display: flex; align-items: center; gap: 8px;"
                >
                    <svg style="width:18px;height:18px;color:#003087;" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                    Save Changes
                </button>
            </div>
        </form>
    </div>

</div>

<!-- Application detail modal -->
<div
    id="appModal"
    style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;"
>
    <div style="background:white;padding:24px;border-radius:16px;width:420px;max-width:95%;">
        <h2 style="margin-bottom:8px;">Application Details</h2>
        <div id="appModalBody" style="margin-bottom:12px;color:var(--color-body);"></div>

        <form method="POST" id="appApproveForm">
            <input type="hidden" name="action" value="approve_application">
            <input type="hidden" name="application_id" id="appModalId">
            <div style="display:flex;gap:10px;justify-content:flex-end;">
                <button type="submit" class="btn-create">Approve</button>
                <button type="button" class="btn-danger" onclick="closeAppModal()">Close</button>
            </div>
        </form>
    </div>

</div>

<script src="../assets/js/admin-notifications.js"></script>

</body>
</html>
