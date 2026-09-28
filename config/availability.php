<?php
declare(strict_types=1);

function sams_availability_first_existing_column(PDO $pdo, string $table, array $columns): ?string
{
    foreach ($columns as $column) {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name'
        );
        $statement->execute([
            'table_name' => $table,
            'column_name' => $column,
        ]);

        if ((int) $statement->fetchColumn() > 0) {
            return $column;
        }
    }

    return null;
}

function sams_save_availability(int $applicationId, int $termId, array $entries, ?array $user = null): array
{
    if ($applicationId <= 0 || $termId <= 0) {
        throw new InvalidArgumentException('Missing required fields: application_id, term_id');
    }

    if (empty($entries)) {
        throw new InvalidArgumentException('Missing availability array');
    }

    $allowedDays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    $pdo = sams_pdo();
    $applicationIdColumn = sams_availability_first_existing_column($pdo, 'applications', ['id', 'application_id']);

    if ($applicationIdColumn === null) {
        throw new RuntimeException('Applications table is missing a supported identifier column.');
    }

    $normalizeDay = static function (string $day): string {
        $normalized = trim($day);

        return match (strtolower($normalized)) {
            'monday', 'mon' => 'Monday',
            'tuesday', 'tue' => 'Tuesday',
            'wednesday', 'wed' => 'Wednesday',
            'thursday', 'thu' => 'Thursday',
            'friday', 'fri' => 'Friday',
            'saturday', 'sat' => 'Saturday',
            default => $normalized,
        };
    };

    $pdo->beginTransaction();

    try {
        $applicationLookupSql =
            'SELECT student_id, term_id
             FROM applications
             WHERE ' . $applicationIdColumn . ' = :application_id
               AND term_id = :term_id
             LIMIT 1';

        $applicationStatement = $pdo->prepare(
            $applicationLookupSql
        );
        $applicationStatement->execute([
            'application_id' => $applicationId,
            'term_id' => $termId,
        ]);
        $applicationRow = $applicationStatement->fetch();

        if (!$applicationRow) {
            throw new RuntimeException('Unable to locate the application for this availability submission.');
        }

        $studentId = (int) $applicationRow['student_id'];

        if ($user && ($user['role'] ?? null) === 'student' && isset($user['user_id'])) {
            $studentIdStatement = $pdo->prepare('SELECT student_id FROM students WHERE user_id = :user_id LIMIT 1');
            $studentIdStatement->execute(['user_id' => (int) $user['user_id']]);
            $dbStudentId = (int) ($studentIdStatement->fetchColumn() ?: 0);

            if ($dbStudentId > 0 && $dbStudentId !== $studentId) {
                throw new RuntimeException('This availability does not belong to the signed-in student.');
            }
        }

        $delete = $pdo->prepare('DELETE FROM availability WHERE application_id = :application_id AND term_id = :term_id');
        $delete->execute([
            'application_id' => $applicationId,
            'term_id' => $termId,
        ]);

        $insert = $pdo->prepare(
            'INSERT INTO availability (application_id, term_id, day_of_week, start_time, end_time)
             VALUES (:application_id, :term_id, :day_of_week, :start_time, :end_time)'
        );

        $inserted = [];

        foreach ($entries as $index => $entry) {
            $day = $normalizeDay((string) ($entry['day_of_week'] ?? ''));
            $startTime = trim((string) ($entry['start_time'] ?? ''));
            $endTime = trim((string) ($entry['end_time'] ?? ''));
            $enabled = array_key_exists('enabled', $entry) ? (bool) $entry['enabled'] : true;

            if (!$enabled) {
                continue;
            }

            if (!in_array($day, $allowedDays, true)) {
                throw new InvalidArgumentException('Invalid day_of_week at row ' . ($index + 1));
            }

            if (!preg_match('/^\d{2}:\d{2}$/', $startTime) || !preg_match('/^\d{2}:\d{2}$/', $endTime)) {
                throw new InvalidArgumentException('Invalid time format at row ' . ($index + 1));
            }

            if ($startTime >= $endTime) {
                throw new InvalidArgumentException('Start time must be earlier than end time at row ' . ($index + 1));
            }

            $insert->execute([
                'application_id' => $applicationId,
                'term_id' => $termId,
                'day_of_week' => $day,
                'start_time' => $startTime,
                'end_time' => $endTime,
            ]);

            $inserted[] = (int) $pdo->lastInsertId();
        }

        if (empty($inserted)) {
            throw new RuntimeException('Please add at least one availability slot before submitting.');
        }

        $pdo->commit();

        return [
            'success' => true,
            'inserted_ids' => $inserted,
            'application_id' => $applicationId,
            'term_id' => $termId,
            'student_id' => $studentId,
        ];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }
}
