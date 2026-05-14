<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$user = sams_authenticated_user();
if (!$user || (($user['role'] ?? null) !== 'supervisor')) {
    header('Location: ../login.php');
    exit;
}

$pdo = sams_pdo();
$applicationId = (int) ($_GET['application_id'] ?? 0);
if ($applicationId <= 0) {
    header('Location: students.php');
    exit;
}

$supervisorStatement = $pdo->prepare(
    'SELECT s.office_name
     FROM supervisors s
     WHERE s.user_id = :user_id
     LIMIT 1'
);
$supervisorStatement->execute(['user_id' => (int) ($user['user_id'] ?? 0)]);
$supervisorRow = $supervisorStatement->fetch(PDO::FETCH_ASSOC) ?: [];
$supervisorOffice = trim((string) ($supervisorRow['office_name'] ?? ($user['office_name'] ?? '')));

$termStatement = $pdo->query(
    'SELECT term_id
     FROM terms
     WHERE start_date <= CURDATE() AND end_date >= CURDATE()
     ORDER BY term_id DESC
     LIMIT 1'
);
$activeTermId = (int) ($termStatement->fetchColumn() ?: 0);

$studentStmt = $pdo->prepare(
    'SELECT
        a.application_id,
        a.preferred_office,
        a.term_id,
        s.student_id,
        s.student_id_number,
        s.program,
        s.year_level,
        u.first_name,
        u.last_name,
        u.email
     FROM applications a
     INNER JOIN students s ON s.student_id = a.student_id
     INNER JOIN users u ON u.user_id = s.user_id
     WHERE a.application_id = :application_id
       AND EXISTS (
            SELECT 1
            FROM duty_schedules ds
            WHERE ds.application_id = a.application_id
              AND ds.status = "accepted"
                            AND (ds.office_name = :office_ds OR a.preferred_office = :office_app)
                            AND (:term_id_guard = 0 OR ds.term_id = :term_id_ds)
       )
     LIMIT 1'
);
$studentStmt->execute([
    'application_id' => $applicationId,
        'office_ds' => $supervisorOffice,
        'office_app' => $supervisorOffice,
        'term_id_guard' => $activeTermId,
        'term_id_ds' => $activeTermId,
]);
$student = $studentStmt->fetch(PDO::FETCH_ASSOC) ?: null;

if (!$student) {
    header('Location: students.php');
    exit;
}

$schedulesStmt = $pdo->prepare(
    'SELECT ds.duty_id, ds.day_of_week, ds.start_time, ds.end_time, ds.status, COALESCE(NULLIF(TRIM(ds.office_name), ""), NULLIF(TRIM(a.preferred_office), ""), "Unassigned") AS office_name
     FROM duty_schedules ds
     INNER JOIN applications a ON a.application_id = ds.application_id
     WHERE ds.application_id = :application_id
       AND ds.status = "accepted"
             AND (ds.office_name = :office_ds OR a.preferred_office = :office_app)
             AND (:term_id_guard = 0 OR ds.term_id = :term_id_ds)
     ORDER BY FIELD(ds.day_of_week, "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"), ds.start_time ASC'
);
$schedulesStmt->execute([
    'application_id' => $applicationId,
        'office_ds' => $supervisorOffice,
        'office_app' => $supervisorOffice,
        'term_id_guard' => $activeTermId,
        'term_id_ds' => $activeTermId,
]);
$schedules = $schedulesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// compute absent schedules (accepted schedules with no attendance log)
$absentStmt = $pdo->prepare(
    'SELECT ds.duty_id, ds.day_of_week, ds.start_time, ds.end_time, COALESCE(NULLIF(TRIM(ds.office_name), ""), a.preferred_office, "Unassigned") AS office_name
     FROM duty_schedules ds
     INNER JOIN applications a ON a.application_id = ds.application_id
    LEFT JOIN (
        SELECT application_id AS app_sub, duty_id, MAX(log_id) AS max_log_id
        FROM attendance_logs
        WHERE application_id = :application_id_sub
        GROUP BY application_id, duty_id
    ) lm ON lm.duty_id = ds.duty_id AND lm.app_sub = ds.application_id
    WHERE ds.application_id = :application_id_outer
       AND ds.status = "accepted"
       AND lm.max_log_id IS NULL'
);
$absentStmt->execute(['application_id_sub' => $applicationId, 'application_id_outer' => $applicationId]);
$absentSchedules = $absentStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$normalizedLogs = sams_attendance_normalize_student_logs($pdo, $applicationId, $activeTermId > 0 ? $activeTermId : null);
$attendanceRows = [];
$summary = ['present' => 0, 'late' => 0, 'absent' => 0, 'total' => 0, 'hours' => 0.0];
foreach (array_slice($normalizedLogs, 0, 50) as $log) {
    $status = sams_attendance_display_status((string) ($log['status'] ?? 'absent'));
    if ($status === 'present' || $status === 'completed') {
        $summary['present']++;
    } elseif ($status === 'late') {
        $summary['late']++;
    } else {
        $summary['absent']++;
    }
    $summary['total']++;

    $timeIn = (string) ($log['time_in'] ?? '-');
    $timeOut = (string) ($log['time_out'] ?? '-');
    if (trim($timeIn) === '') {
        $timeIn = '-';
    }
    if (trim($timeOut) === '') {
        $timeOut = '-';
    }

    $inTs = $timeIn !== '-' ? strtotime($timeIn) : false;
    $outTs = $timeOut !== '-' ? strtotime($timeOut) : false;
    if ($inTs && $outTs && $outTs > $inTs) {
        $summary['hours'] += ($outTs - $inTs) / 3600;
    }

    $attendanceRows[] = [
        'created_at' => (string) ($log['created_at'] ?? ''),
        'clock_in_time' => $timeIn,
        'clock_out_time' => $timeOut,
        'status' => $status,
        'late_minutes' => (int) ($log['late_minutes'] ?? 0),
    ];
}

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$studentName = trim((string) ($student['first_name'] ?? '') . ' ' . (string) ($student['last_name'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Student Profile | Supervisor Portal</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;700;900&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="../assets/css/sams-shell.css" />
    <link rel="stylesheet" href="../assets/css/sams-theme-admin.css" />
    <style>
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
        body{font-family:Inter,Arial,sans-serif;background:var(--color-bg-app);color:var(--color-heading)}
        .wrap{max-width:1200px;margin:24px auto;padding:0 16px;display:flex;flex-direction:column;gap:16px}
        .card{background:#fff;border:1px solid var(--color-border);border-radius:var(--radius);padding:18px}
        .title{font-size:28px;font-weight:800}
        .sub{margin-top:6px;color:var(--color-body)}
        .grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
        .tile{background:#fff;border:1px solid var(--color-border);border-radius:12px;padding:14px}
        .tile strong{display:block;font-size:26px;line-height:1.1}
        .tile span{display:block;font-size:13px;color:var(--color-body);margin-top:4px}
        table{width:100%;border-collapse:collapse}
        th,td{padding:10px 12px;border-bottom:1px solid var(--color-border);font-size:14px;text-align:left}
        th{background:#f9fafb;font-size:13px}
        .btn{display:inline-flex;align-items:center;justify-content:center;height:38px;padding:0 12px;border-radius:10px;background:#155dfc;color:#fff;text-decoration:none;font-weight:700}
        .muted{color:var(--color-body)}
        .badge{display:inline-flex;align-items:center;height:22px;padding:0 10px;border-radius:9999px;font-size:12px;font-weight:700}
        .badge--present{background:#dcfce7;color:#008236}
        .badge--late{background:#dbeafe;color:#1447e6}
        .badge--absent{background:#f3f4f6;color:#4a5565}
        .live-pill{display:inline-flex;align-items:center;height:24px;padding:0 10px;border-radius:9999px;background:#dcfce7;color:#166534;font-size:12px;font-weight:700}
        @media (max-width:900px){.grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
        @media (max-width:560px){.grid{grid-template-columns:1fr}}
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <div class="flex-between flex-row">
            <div>
                <div class="title"><?php echo h($studentName); ?></div>
                <div class="sub">Student ID: <?php echo h((string) ($student['student_id_number'] ?? '')); ?> · Program: <?php echo h((string) ($student['program'] ?? '-')); ?> · Office: <?php echo h((string) ($student['preferred_office'] ?? $supervisorOffice)); ?></div>
            </div>
            <div class="flex-row">
                <span class="live-pill">Live</span>
                <a class="btn" href="students.php">Back to Students</a>
                <a class="btn" href="reports.php">Open Reports</a>
            </div>
        </div>
    </div>

    <div class="grid">
        <div class="tile"><strong id="sum-hours"><?php echo number_format((float) $summary['hours'], 1); ?>h</strong><span>Rendered Hours</span></div>
        <div class="tile"><strong id="sum-present"><?php echo (int) $summary['present']; ?></strong><span>Present</span></div>
        <div class="tile"><strong id="sum-late"><?php echo (int) $summary['late']; ?></strong><span>Late</span></div>
        <div class="tile"><strong id="sum-absent"><?php echo (int) $summary['absent']; ?></strong><span>Absent</span></div>
    </div>

    <div class="card">
        <h2 class="heading-sm">Accepted Schedules</h2>
        <table>
            <thead>
                <tr>
                    <th>Day</th>
                    <th>Start</th>
                    <th>End</th>
                    <th>Office</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($schedules)): ?>
                    <?php foreach ($schedules as $schedule): ?>
                        <tr>
                            <td><?php echo h((string) ($schedule['day_of_week'] ?? '')); ?></td>
                            <td><?php echo h((string) ($schedule['start_time'] ?? '-')); ?></td>
                            <td><?php echo h((string) ($schedule['end_time'] ?? '-')); ?></td>
                            <td><?php echo h((string) ($schedule['office_name'] ?? 'Unassigned')); ?></td>
                            <td><span class="badge badge--present"><?php echo h((string) ($schedule['status'] ?? 'accepted')); ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="5" class="muted">No accepted schedules found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2 class="heading-sm">Absent Schedules (No Log)</h2>
        <table>
            <thead>
                <tr>
                    <th>Day</th>
                    <th>Start</th>
                    <th>End</th>
                    <th>Office</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody id="absent-body">
                <?php if (!empty($absentSchedules)): ?>
                    <?php foreach ($absentSchedules as $as): ?>
                        <tr>
                            <td><?php echo h((string) ($as['day_of_week'] ?? '')); ?></td>
                            <td><?php echo h((string) ($as['start_time'] ?? '-')); ?></td>
                            <td><?php echo h((string) ($as['end_time'] ?? '-')); ?></td>
                            <td><?php echo h((string) ($as['office_name'] ?? 'Unassigned')); ?></td>
                            <td><button class="btn" type="button" data-duty="<?php echo (int) ($as['duty_id'] ?? 0); ?>">Mark Excused</button></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="5" class="muted">No absent schedules detected.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2 class="heading-sm">Recent Attendance Logs</h2>
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Time In</th>
                    <th>Time Out</th>
                    <th>Status</th>
                    <th>Late (min)</th>
                </tr>
            </thead>
            <tbody id="attendance-body">
                <?php if (!empty($attendanceRows)): ?>
                    <?php foreach ($attendanceRows as $log): ?>
                        <?php
                            $status = sams_attendance_display_status((string) ($log['status'] ?? ''));
                            $badgeClass = $status === 'late' ? 'badge--late' : (($status === 'present' || $status === 'completed') ? 'badge--present' : 'badge--absent');
                            $statusLabel = $status === 'late' ? 'Late' : (($status === 'present' || $status === 'completed') ? 'Present' : 'Absent');
                        ?>
                        <tr>
                            <td><?php echo h((string) ($log['created_at'] ?? '')); ?></td>
                            <td><?php echo h((string) ($log['clock_in_time'] ?? '-')); ?></td>
                            <td><?php echo h((string) ($log['clock_out_time'] ?? '-')); ?></td>
                            <td><span class="badge <?php echo h($badgeClass); ?>"><?php echo h($statusLabel); ?></span></td>
                            <td><?php echo (int) ($log['late_minutes'] ?? 0); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="5" class="muted">No attendance logs found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<script>
(function () {
    'use strict';

    var appId = <?php echo (int) $applicationId; ?>;
    var sumHours = document.getElementById('sum-hours');
    var sumPresent = document.getElementById('sum-present');
    var sumLate = document.getElementById('sum-late');
    var sumAbsent = document.getElementById('sum-absent');
    var attendanceBody = document.getElementById('attendance-body');

    function esc(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function renderRows(rows) {
        if (!rows || rows.length === 0) {
            attendanceBody.innerHTML = '<tr><td colspan="5" class="muted">No attendance logs found.</td></tr>';
            return;
        }

        var html = rows.map(function (row) {
            return '<tr>' +
                '<td>' + esc(row.created_at || '') + '</td>' +
                '<td>' + esc(row.clock_in_time || '-') + '</td>' +
                '<td>' + esc(row.clock_out_time || '-') + '</td>' +
                '<td><span class="badge ' + esc(row.badge_class || 'badge--absent') + '">' + esc(row.status_label || 'Absent') + '</span></td>' +
                '<td>' + esc(row.late_minutes || 0) + '</td>' +
                '</tr>';
        }).join('');

        attendanceBody.innerHTML = html;
    }

    function applyPayload(data) {
        if (!data || !data.summary) return;
        sumHours.textContent = Number(data.summary.hours || 0).toFixed(1) + 'h';
        sumPresent.textContent = data.summary.present || 0;
        sumLate.textContent = data.summary.late || 0;
        sumAbsent.textContent = data.summary.absent || 0;
        renderRows(data.attendance_rows || []);
        renderAbsent(data.absent_schedules || []);


    function renderAbsent(rows) {
        var body = document.getElementById('absent-body');
        if (!rows || rows.length === 0) {
            body.innerHTML = '<tr><td colspan="5" class="muted">No absent schedules detected.</td></tr>';
            return;
        }

        var html = rows.map(function (r) {
            return '<tr>' +
                '<td>' + esc(r.day_of_week || '') + '</td>' +
                '<td>' + esc(r.start_time || '-') + '</td>' +
                '<td>' + esc(r.end_time || '-') + '</td>' +
                '<td>' + esc(r.office_name || 'Unassigned') + '</td>' +
                '<td><button class="btn" type="button" data-duty="' + (r.duty_id || 0) + '">Mark Excused</button></td>' +
                '</tr>';
        }).join('');

        body.innerHTML = html;
    }
    function poll() {
        fetch('student_profile_data.php?application_id=' + encodeURIComponent(appId), { credentials: 'same-origin' })
            .then(function (res) {
                if (!res.ok) throw new Error('Network');
                return res.json();
            })
            .then(function (data) {
                if (!data || !data.success) return;
                applyPayload(data);
            })
            .catch(function () {
                // keep UI as-is on transient failures
            });
    }

    setInterval(poll, 5000);
})();
</script>
</body>
</html>
