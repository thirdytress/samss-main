<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
$pdo = sams_pdo();
$office = 'ITSO';
$sql = 'SELECT DISTINCT a.application_id, s.student_id, s.student_id_number AS student_code, CONCAT(COALESCE(u.last_name, ""), ", ", COALESCE(u.first_name, "")) AS name
         FROM applications a
         JOIN students s ON s.student_id = a.student_id
         LEFT JOIN users u ON u.user_id = s.user_id
         WHERE (
                    a.preferred_office = :office1
                OR EXISTS (
                        SELECT 1
                        FROM duty_schedules ds
                        WHERE ds.application_id = a.application_id
                            AND ds.office_name = :office2
                )
         )
         AND EXISTS (
                    SELECT 1
                    FROM duty_schedules ds2
                    WHERE ds2.application_id = a.application_id
                        AND (ds2.office_name = :office3 OR a.preferred_office = :office4)
         )
         ORDER BY s.student_id_number ASC';
$stmt = $pdo->prepare($sql);
$stmt->execute([
    'office1' => $office,
    'office2' => $office,
    'office3' => $office,
    'office4' => $office,
]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "count=" . count($rows) . PHP_EOL;
foreach ($rows as $row) {
    echo json_encode($row) . PHP_EOL;
}
