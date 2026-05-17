<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$currentUser = sams_authenticated_user();
if (!$currentUser || (($currentUser['role'] ?? null) !== 'admin')) {
    header('Location: ../login.php');
    exit;
}

$pdo = sams_pdo();
$reportId = isset($_GET['report_id']) ? (int) $_GET['report_id'] : 0;
if ($reportId <= 0) {
    header('Location: reports.php');
    exit;
}

// POST actions: close/reopen/delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // CSRF protection
    $postedToken = (string)($_POST['_csrf'] ?? '');
    if (!sams_verify_csrf($postedToken)) {
        http_response_code(400);
        echo 'Invalid CSRF token';
        exit;
    }
    $action = $_POST['action'];
    if ($action === 'close') {
        $upd = $pdo->prepare('UPDATE student_reports SET status = "closed", is_new = 0 WHERE report_id = :id');
        $upd->execute(['id' => $reportId]);
    } elseif ($action === 'reopen') {
        $upd = $pdo->prepare('UPDATE student_reports SET status = "open" WHERE report_id = :id');
        $upd->execute(['id' => $reportId]);
    } elseif ($action === 'delete') {
        $del = $pdo->prepare('DELETE FROM student_reports WHERE report_id = :id');
        $del->execute(['id' => $reportId]);
        $_SESSION['reports_flash'] = 'Report deleted successfully.';
        header('Location: reports.php');
        exit;
    }
    header('Location: report_detail.php?report_id=' . $reportId);
    exit;
}

// Mark as read for admin view
try {
    $mark = $pdo->prepare('UPDATE student_reports SET is_new = 0 WHERE report_id = :id');
    $mark->execute(['id' => $reportId]);
} catch (Throwable $e) {
    // ignore
}

$stmt = $pdo->prepare(
    "SELECT sr.*, u.email AS reporter_email, CONCAT(u.first_name, ' ', u.last_name) AS reporter_name, a.preferred_office,
            s.student_id_number AS student_id_number, CONCAT(su.first_name, ' ', su.last_name) AS student_name
     FROM student_reports sr
     LEFT JOIN users u ON u.user_id = sr.reporter_id
     LEFT JOIN applications a ON a.application_id = sr.application_id
     LEFT JOIN students s ON s.student_id = sr.student_code
     LEFT JOIN users su ON su.user_id = s.user_id
     WHERE sr.report_id = :id"
);
$stmt->execute(['id' => $reportId]);
$report = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$report) {
    header('Location: reports.php');
    exit;
}

// Render detail
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Report #<?php echo (int)$report['report_id']; ?> — Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>body{font-family:Inter,system-ui,Arial;background:#f9fafb;margin:0;padding:20px} .card{background:#fff;padding:18px;border-radius:10px;border:1px solid #eef2f7;max-width:900px;margin:18px auto}</style>
</head>
<body>
    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
            <div>
                <h2 style="margin:0">Report #<?php echo (int)$report['report_id']; ?></h2>
                <div style="color:#6b7280">Submitted: <?php echo htmlspecialchars((string)$report['created_at']); ?> — Reporter: <?php echo htmlspecialchars((string)$report['reporter_name'] ?? $report['reporter_email']); ?></div>
            </div>
            <div>
                <a href="reports.php" style="margin-right:8px">Back</a>
                <?php if (($report['status'] ?? '') === 'open'): ?>
                    <form method="post" style="display:inline">
                        <?php echo sams_csrf_input_field(); ?>
                        <input type="hidden" name="action" value="close">
                        <button type="submit" style="background:#ef4444;color:#fff;padding:8px 12px;border-radius:6px;border:0">Close</button>
                    </form>
                <?php else: ?>
                    <form method="post" style="display:inline">
                        <?php echo sams_csrf_input_field(); ?>
                        <input type="hidden" name="action" value="reopen">
                        <button type="submit" style="background:#10b981;color:#fff;padding:8px 12px;border-radius:6px;border:0">Reopen</button>
                    </form>
                <?php endif; ?>
                <form method="post" style="display:inline;margin-left:8px" onsubmit="return confirm('Delete this report permanently? This cannot be undone.')">
                    <?php echo sams_csrf_input_field(); ?>
                    <input type="hidden" name="action" value="delete">
                    <button type="submit" style="background:#111827;color:#fff;padding:8px 12px;border-radius:6px;border:0">Delete Report</button>
                </form>
            </div>
        </div>

        <div style="margin-bottom:12px">
            <?php
                $studentDisplay = '-';
                if (!empty($report['student_name']) || !empty($report['student_id_number'])) {
                    $name = trim((string)($report['student_name'] ?? ''));
                    $num = trim((string)($report['student_id_number'] ?? ''));
                    if ($name !== '' && $num !== '') {
                        $studentDisplay = $name . ' (' . $num . ')';
                    } elseif ($name !== '') {
                        $studentDisplay = $name;
                    } else {
                        $studentDisplay = $num !== '' ? $num : '-';
                    }
                } elseif (!empty($report['student_code'])) {
                    $studentDisplay = 'ID: ' . (int)$report['student_code'];
                }
            ?>
            <strong>Student</strong>: <?php echo htmlspecialchars($studentDisplay); ?><br>
            <strong>Application</strong>: <?php if (!empty($report['application_id'])): ?><a href="application_detail.php?application_id=<?php echo (int)$report['application_id']; ?>"><?php echo (int)$report['application_id']; ?></a><?php else: ?>-<?php endif; ?><br>
            <strong>Office</strong>: <?php echo htmlspecialchars((string)($report['preferred_office'] ?? '-')); ?><br>
            <strong>Status</strong>: <?php echo htmlspecialchars((string)($report['status'] ?? '-')); ?>
        </div>

        <div style="padding:12px;border:1px solid #eef2f7;border-radius:8px;background:#fff">
            <strong>Notes</strong>
            <div style="margin-top:8px;white-space:pre-wrap;color:#111"><?php echo nl2br(htmlspecialchars((string)($report['notes'] ?? ''))); ?></div>
        </div>
    </div>
</body>
</html>
