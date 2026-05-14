<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

$pdo = sams_pdo();

// Allow either session-authenticated supervisors or short-lived token auth
$currentUser = sams_authenticated_user();
$office = '';
if ($currentUser && (($currentUser['role'] ?? null) === 'supervisor')) {
    $office = (string) ($currentUser['office_name'] ?? '');
    $officeStmt = $pdo->prepare(
        'SELECT s.office_name
         FROM supervisors s
         WHERE s.user_id = :user_id
         LIMIT 1'
    );
    $officeStmt->execute(['user_id' => (int) ($currentUser['user_id'] ?? 0)]);
    $officeRow = $officeStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $resolvedOffice = trim((string) ($officeRow['office_name'] ?? ''));
    if ($resolvedOffice !== '') {
        $office = $resolvedOffice;
    }
} else {
    // token-based auth: ?token=...
    $token = trim((string) ($_GET['token'] ?? ''));
    if ($token === '') {
        http_response_code(403);
        echo "event: error\ndata: {\"message\": \"Forbidden\"}\n\n";
        exit;
    }
    // validate token
    $tstmt = $pdo->prepare('SELECT user_id, office_name, expires_at FROM sse_tokens WHERE token = :token LIMIT 1');
    $tstmt->execute(['token' => $token]);
    $t = $tstmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$t) {
        http_response_code(403);
        echo "event: error\ndata: {\"message\": \"Invalid token\"}\n\n";
        exit;
    }
    if (new DateTime() > new DateTime($t['expires_at'])) {
        http_response_code(403);
        echo "event: error\ndata: {\"message\": \"Token expired\"}\n\n";
        exit;
    }
    $office = (string) ($t['office_name'] ?? '');
    // optionally delete token to make it single-use
    $del = $pdo->prepare('DELETE FROM sse_tokens WHERE token = :token');
    $del->execute(['token' => $token]);
}
$currentDay = date('l');
$activeTermId = (int) ($pdo->query("SELECT COALESCE(MAX(term_id), 0) FROM terms WHERE start_date <= CURDATE() AND end_date >= CURDATE()")->fetchColumn() ?: 0);

set_time_limit(0);
ignore_user_abort(true);

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

$lastSignature = null;
$checkStmt = $pdo->prepare(
        'SELECT COALESCE(MAX(al.log_id), 0) AS sig
         FROM duty_schedules ds
         INNER JOIN applications a ON a.application_id = ds.application_id
         LEFT JOIN attendance_logs al ON al.application_id = a.application_id AND al.duty_id = ds.duty_id
         WHERE ds.day_of_week = :day
             AND ds.status = "assigned"
             AND ds.term_id = :term
             AND a.preferred_office = :office'
);

$fetchStmt = $pdo->prepare(
    "SELECT u.first_name, u.last_name, s.student_id AS student_code, a.preferred_office AS office_name,
            al.clock_in_time AS time_in, al.clock_out_time AS time_out, al.status, al.late_minutes, ds.start_time
     FROM duty_schedules ds
     INNER JOIN applications a ON a.application_id = ds.application_id
     LEFT JOIN students s ON s.student_id = a.student_id
     LEFT JOIN users u ON u.user_id = s.user_id
     LEFT JOIN attendance_logs al ON al.log_id = (
             SELECT al2.log_id
             FROM attendance_logs al2
             WHERE al2.application_id = ds.application_id AND al2.duty_id = ds.duty_id
             ORDER BY al2.log_id DESC
             LIMIT 1
     )
     WHERE ds.day_of_week = :day
         AND ds.status = 'assigned'
         AND ds.term_id = :term
         AND a.preferred_office = :office
     ORDER BY ds.start_time ASC, al.log_id DESC"
);

while (true) {
    try {
        $checkStmt->execute([':day' => $currentDay, ':term' => $activeTermId, ':office' => $office]);
        $sig = (string) $checkStmt->fetchColumn();

        if ($sig !== $lastSignature) {
            $lastSignature = $sig;
            // fetch fresh rows
            $fetchStmt->execute([':day' => $currentDay, ':term' => $activeTermId, ':office' => $office]);
            $rows = $fetchStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // normalize display (mirror attendance_data.php)
            $today_rows = [];
            foreach ($rows as $row) {
                $status = sams_attendance_display_status((string) ($row['status'] ?? ''));
                if ($status === '') { $status = 'absent'; }
                $timeIn = sams_attendance_clocking_enabled() ? ($row['time_in'] ?? null) : null;
                $timeOut = sams_attendance_clocking_enabled() ? ($row['time_out'] ?? null) : null;

                $today_rows[] = [
                    'first_name' => (string) ($row['first_name'] ?? ''),
                    'last_name' => (string) ($row['last_name'] ?? ''),
                    'office_name' => (string) ($row['office_name'] ?? '-'),
                    'time_in' => $timeIn ? date('g:i A', strtotime((string) $timeIn)) : '-',
                    'time_out' => $timeOut ? date('g:i A', strtotime((string) $timeOut)) : ($timeIn ? 'In Progress' : '-'),
                    'status' => match ($status) {
                        'present', 'completed' => 'Present',
                        'late' => 'Late',
                        default => 'Absent',
                    },
                ];
            }

            $payload = json_encode(['success' => true, 'today_rows' => $today_rows]);
            if ($payload === false) { $payload = json_encode(['success' => false]); }

            echo "event: attendance\n";
            // data must be sent line-by-line
            foreach (explode("\n", $payload) as $line) {
                echo "data: {$line}\n";
            }
            echo "\n";
            @ob_flush();
            @flush();
        }

        // heartbeat comment to keep connection alive
        echo ": heartbeat\n\n";
        @ob_flush();
        @flush();

        // sleep a short while
        sleep(2);

        if (connection_aborted()) {
            break;
        }
    } catch (Throwable $e) {
        echo "event: error\n";
        echo "data: {\"message\": \"Server error\"}\n\n";
        @ob_flush();
        @flush();
        break;
    }
}

exit;
