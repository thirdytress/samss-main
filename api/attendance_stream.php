<?php
declare(strict_types=1);
// Server-Sent Events endpoint for attendance updates
require_once __DIR__ . '/../config/bootstrap.php';

// Long-running script
set_time_limit(0);
ignore_user_abort(true);
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

$user = sams_authenticated_user();
if (!$user) {
    http_response_code(403);
    echo "event: error\n";
    echo "data: {\"success\":false,\"message\":\"Forbidden\"}\n\n";
    flush();
    exit;
}

$pdo = sams_pdo();
session_write_close();

$isAdmin = (($user['role'] ?? '') === 'admin');

$prevSig = null;

// helper: send SSE event
function sse_send(string $event, array $payload): void
{
    echo "event: {$event}\n";
    echo 'data: ' . json_encode($payload, JSON_UNESCAPED_SLASHES) . "\n\n";
    if (ob_get_level()) ob_flush();
    flush();
}

while (!connection_aborted()) {
    try {
        if ($isAdmin) {
            $sig = (int) $pdo->query('SELECT COALESCE(MAX(log_id),0) FROM attendance_logs')->fetchColumn();
            if ($sig !== $prevSig) {
                // build admin payload (reuse admin/attendance_data shape)
                $metrics = [
                    'active_now' => 0,
                    'completed_today' => 0,
                    'total_schedules' => (int) $pdo->query("SELECT COUNT(DISTINCT ds.duty_id) FROM duty_schedules ds WHERE ds.status = 'assigned' AND ds.term_id = (SELECT MAX(term_id) FROM terms WHERE start_date <= CURDATE() AND end_date >= CURDATE() LIMIT 1)")->fetchColumn(),
                ];

                // today rows (simplified)
                $stmt = $pdo->query(
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
                     WHERE ds.day_of_week = '" . date('l') . "'
                         AND ds.status = 'accepted'
                         AND ds.term_id = " . ((int) ($pdo->query("SELECT COALESCE(MAX(term_id),0) FROM terms WHERE start_date <= CURDATE() AND end_date >= CURDATE()")->fetchColumn())) . "
                     ORDER BY ds.start_time ASC, al.log_id DESC"
                );
                $today_rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

                $offices = $pdo->query(
                    "SELECT COALESCE(a.preferred_office, 'Unassigned') AS office_name,
                            COUNT(DISTINCT al.log_id) AS total,
                            SUM(CASE WHEN al.clock_out_time IS NULL THEN 1 ELSE 0 END) AS active
                     FROM attendance_logs al
                     LEFT JOIN applications a ON a.application_id = al.application_id
                     WHERE DATE(al.created_at) = CURDATE()
                     GROUP BY COALESCE(a.preferred_office, 'Unassigned')
                     ORDER BY total DESC"
                )->fetchAll(PDO::FETCH_ASSOC) ?: [];

                sse_send('attendance', ['success' => true, 'metrics' => $metrics, 'today_rows' => $today_rows, 'offices' => $offices]);
                $prevSig = $sig;
            }
        } else {
            // student stream: get their application
            $userId = (int) ($user['user_id'] ?? $user['id'] ?? 0);
            $st = $pdo->prepare('SELECT s.student_id AS student_db_id, a.application_id, a.term_id FROM students s LEFT JOIN applications a ON a.student_id = s.student_id WHERE s.user_id = :user_id ORDER BY a.application_id DESC LIMIT 1');
            $st->execute(['user_id' => $userId]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $appId = (int) ($row['application_id'] ?? 0);

            $sig = 0;
            if ($appId > 0) {
                $ps = $pdo->prepare('SELECT COALESCE(MAX(log_id),0) FROM attendance_logs WHERE application_id = :app');
                $ps->execute(['app' => $appId]);
                $sig = (int) $ps->fetchColumn();
            }

            if ($sig !== $prevSig) {
                // create same payload as attendance_snapshot
                $logs = [];
                if ($appId > 0) {
                    $logs = sams_attendance_normalize_student_logs($pdo, $appId, (int) ($row['term_id'] ?? 0) ?: null);
                }
                $summary = ['present' => 0, 'late' => 0, 'absent' => 0, 'total' => 0, 'incomplete' => 0];
                foreach ($logs as $l) {
                    $status = (string) ($l['status'] ?? 'absent');
                    if (in_array($status, ['present','active','completed'], true)) $summary['present']++;
                    elseif ($status === 'late') $summary['late']++;
                    elseif ($status === 'incomplete') $summary['incomplete']++;
                    else $summary['absent']++;
                    $summary['total']++;
                }
                sse_send('attendance', ['success' => true, 'attendance' => array_slice($logs,0,10), 'summary' => $summary]);
                $prevSig = $sig;
            }
        }
    } catch (Throwable $e) {
        // send error and break
        sse_send('error', ['success' => false, 'message' => $e->getMessage()]);
        break;
    }

    // heartbeat comment to keep connection alive
    echo ": heartbeat\n\n";
    if (ob_get_level()) ob_flush();
    flush();
    sleep(2);
}

exit;

