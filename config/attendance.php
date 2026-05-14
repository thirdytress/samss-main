<?php
declare(strict_types=1);

function sams_attendance_clocking_enabled(): bool
{
    return false;
}

function sams_attendance_display_status(string $status): string
{
    if (!sams_attendance_clocking_enabled()) {
        return 'absent';
    }

    return sams_attendance_canonical_status($status);
}

function sams_attendance_canonical_status(string $status): string
{
    return match (strtolower(trim($status))) {
        'present', 'active', 'completed' => 'present',
        'late' => 'late',
        'absent', 'incomplete' => 'absent',
        default => strtolower(trim($status)),
    };
}

function sams_attendance_status_priority(string $status): int
{
    return match (sams_attendance_canonical_status($status)) {
        'late' => 3,
        'present' => 2,
        'absent' => 1,
        default => 0,
    };
}

function sams_attendance_normalize_student_logs(PDO $pdo, int $applicationId, ?int $termId = null): array
{
    if ($applicationId <= 0) {
        return [];
    }

    $logsStmt = $pdo->prepare(
        'SELECT al.log_id, al.duty_id, al.clock_in_time AS time_in, al.clock_out_time AS time_out, al.status, al.late_minutes, al.notes, al.created_at, ds.start_time AS scheduled_start
         FROM attendance_logs al
         LEFT JOIN duty_schedules ds ON ds.duty_id = al.duty_id
         WHERE al.application_id = :application_id
         ORDER BY al.created_at DESC, al.log_id DESC'
    );
    $logsStmt->execute(['application_id' => $applicationId]);
    $rawLogs = $logsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $records = [];
    foreach ($rawLogs as $log) {
        $dutyId = (int) ($log['duty_id'] ?? 0);
        $key = $dutyId > 0 ? 'duty:' . $dutyId : 'log:' . (int) ($log['log_id'] ?? 0);
        // derive lateness based on scheduled start when available
        $scheduledStart = isset($log['scheduled_start']) ? trim((string) $log['scheduled_start']) : '';
        $timeIn = isset($log['time_in']) ? trim((string) $log['time_in']) : '';

        if ($timeIn !== '' && $scheduledStart !== '') {
            $startTs = strtotime($scheduledStart);
            $inTs = strtotime($timeIn);
            if ($startTs && $inTs && $inTs > $startTs) {
                $computedLate = (int) floor(($inTs - $startTs) / 60);
                $log['late_minutes'] = $computedLate;
                // consider >=10 minutes as late
                $log['status'] = $computedLate >= 10 ? 'late' : 'present';
            } else {
                // default to whatever the log says or present
                $log['status'] = $log['status'] ?? 'present';
            }
        } else {
            $log['status'] = $log['status'] ?? 'present';
        }

        $log['status'] = sams_attendance_display_status((string) ($log['status'] ?? ''));
        $log['__priority'] = sams_attendance_status_priority((string) $log['status']);
        if (!sams_attendance_clocking_enabled()) {
            $log['time_in'] = null;
            $log['time_out'] = null;
        }

        if (!isset($records[$key])) {
            $records[$key] = $log;
            continue;
        }

        $currentPriority = (int) ($records[$key]['__priority'] ?? 0);
        $incomingPriority = (int) ($log['__priority'] ?? 0);
        $currentLogId = (int) ($records[$key]['log_id'] ?? 0);
        $incomingLogId = (int) ($log['log_id'] ?? 0);

        if ($incomingPriority > $currentPriority || ($incomingPriority === $currentPriority && $incomingLogId > $currentLogId)) {
            $records[$key] = $log;
        }
    }

    if ($termId !== null && $termId > 0) {
        $scheduleStmt = $pdo->prepare(
            'SELECT duty_id, day_of_week, start_time, end_time
             FROM duty_schedules
             WHERE application_id = :application_id
               AND term_id = :term_id
               AND status = :accepted_status
             ORDER BY FIELD(day_of_week, "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"), start_time ASC'
        );
        $scheduleStmt->execute([
            'application_id' => $applicationId,
            'term_id' => $termId,
            'accepted_status' => 'accepted',
        ]);
        $scheduleRows = $scheduleStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $recordsByDuty = [];
        foreach ($records as $record) {
            $dutyId = (int) ($record['duty_id'] ?? 0);
            if ($dutyId > 0) {
                $recordsByDuty[$dutyId] = true;
            }
        }

        $weekdayMap = [
            'Monday' => 1,
            'Tuesday' => 2,
            'Wednesday' => 3,
            'Thursday' => 4,
            'Friday' => 5,
            'Saturday' => 6,
        ];
        $today = new DateTimeImmutable('now');
        $weekStart = new DateTimeImmutable('monday this week');

        foreach ($scheduleRows as $schedule) {
            $dutyId = (int) ($schedule['duty_id'] ?? 0);
            if ($dutyId <= 0 || isset($recordsByDuty[$dutyId])) {
                continue;
            }

            $day = (string) ($schedule['day_of_week'] ?? '');
            if (!isset($weekdayMap[$day])) {
                continue;
            }

            $scheduledDate = $weekStart->modify('+' . ($weekdayMap[$day] - 1) . ' days');
            $endTime = trim((string) ($schedule['end_time'] ?? ''));
            if ($endTime === '') {
                continue;
            }

            $scheduledEnd = DateTimeImmutable::createFromFormat(
                'Y-m-d H:i:s',
                $scheduledDate->format('Y-m-d') . ' ' . $endTime
            );

            if ($scheduledEnd instanceof DateTimeImmutable && $today <= $scheduledEnd) {
                continue;
            }

            $records[] = [
                'log_id' => null,
                'duty_id' => $dutyId,
                'time_in' => null,
                'time_out' => null,
                'status' => 'absent',
                'late_minutes' => 0,
                'notes' => 'No clock-in by cutoff',
                'created_at' => $scheduledDate->format('Y-m-d') . ' 00:00:00',
                '__priority' => 1,
            ];
        }
    }

    usort($records, static function (array $left, array $right): int {
        $leftTime = strtotime((string) ($left['created_at'] ?? '')) ?: 0;
        $rightTime = strtotime((string) ($right['created_at'] ?? '')) ?: 0;
        if ($leftTime !== $rightTime) {
            return $rightTime <=> $leftTime;
        }

        return (int) ($right['log_id'] ?? 0) <=> (int) ($left['log_id'] ?? 0);
    });

    foreach ($records as &$record) {
        unset($record['__priority']);
    }
    unset($record);

    return array_values($records);
}