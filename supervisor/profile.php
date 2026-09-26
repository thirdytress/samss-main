<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$user = sams_authenticated_user();
if (!$user || ($user['role'] ?? null) !== 'supervisor') {
    header('Location: ../login.php');
    exit;
}

$pdo = sams_pdo();
$userId = (int) ($user['user_id'] ?? 0);
$message = '';
$error = '';

$profileStmt = $pdo->prepare(
    'SELECT u.user_id, u.email, u.first_name, u.last_name, u.phone_number, u.role,
            s.supervisor_id, s.office_name, s.max_students, s.phone
     FROM users u INNER JOIN supervisors s ON s.user_id = u.user_id
     WHERE u.user_id = :user_id LIMIT 1'
);
$profileStmt->execute(['user_id' => $userId]);
$profile = $profileStmt->fetch(PDO::FETCH_ASSOC) ?: [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!sams_verify_csrf((string) ($_POST['_csrf'] ?? ''))) {
        $error = 'Your session expired. Please refresh and try again.';
    } else {
        $firstName = trim((string) ($_POST['first_name'] ?? ''));
        $lastName = trim((string) ($_POST['last_name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $phone = preg_replace('/\D+/', '', (string) ($_POST['phone_number'] ?? ''));

        if ($firstName === '' || $lastName === '' || $email === '') {
            $error = 'First name, last name, and email are required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            try {
                $duplicate = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = :email AND user_id <> :user_id');
                $duplicate->execute(['email' => $email, 'user_id' => $userId]);
                if ((int) $duplicate->fetchColumn() > 0) {
                    throw new RuntimeException('That email is already in use.');
                }

                if ($phone !== '') {
                    $phoneDuplicate = $pdo->prepare('SELECT COUNT(*) FROM users WHERE phone_number = :phone AND user_id <> :user_id');
                    $phoneDuplicate->execute(['phone' => $phone, 'user_id' => $userId]);
                    if ((int) $phoneDuplicate->fetchColumn() > 0) {
                        throw new RuntimeException('That phone number is already in use.');
                    }
                }

                $update = $pdo->prepare(
                    'UPDATE users SET first_name = :first_name, last_name = :last_name,
                     email = :email, phone_number = :phone_number WHERE user_id = :user_id'
                );
                $update->execute(['first_name' => $firstName, 'last_name' => $lastName, 'email' => $email, 'phone_number' => $phone !== '' ? $phone : null, 'user_id' => $userId]);
                $_SESSION['sams_user'] = array_merge($_SESSION['sams_user'], ['email' => $email, 'first_name' => $firstName, 'last_name' => $lastName, 'name' => sams_normalize_name($firstName, $lastName)]);
                $profile['first_name'] = $firstName;
                $profile['last_name'] = $lastName;
                $profile['email'] = $email;
                $profile['phone_number'] = $phone;
                $message = 'Profile updated successfully.';
            } catch (Throwable $exception) {
                $error = $exception->getMessage();
            }
        }
    }
}

$displayName = sams_normalize_name($profile['first_name'] ?? null, $profile['last_name'] ?? null);
$officeName = (string) ($profile['office_name'] ?? 'Assigned Office');
$h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Supervisor Profile | SAMS</title>
<link rel="stylesheet" href="../assets/css/sams-shell.css">
<link rel="stylesheet" href="../assets/css/sams-theme-admin.css">
<link rel="stylesheet" href="../assets/css/supervisor-notifications.css">
<link rel="stylesheet" href="../assets/css/notifications-shell.css?v=20260926">
<link rel="stylesheet" href="../assets/css/sams-dark-mode.css?v=20260926">
<style>
body{font-family:Inter,Arial,sans-serif;background:#f7f9fc;color:#101828}.profile-shell{max-width:760px;margin:0 auto}.profile-card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:28px;box-shadow:0 1px 3px rgba(16,24,40,.06)}.profile-heading{display:flex;align-items:center;gap:16px;margin-bottom:24px}.profile-avatar{width:64px;height:64px;border-radius:50%;display:grid;place-items:center;background:#003087;color:#fff;font-size:22px;font-weight:800}.profile-heading h1{margin:0;font-size:24px}.profile-heading p{margin:4px 0 0;color:#667085}.profile-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}.profile-field{display:flex;flex-direction:column;gap:7px}.profile-field--full{grid-column:1/-1}.profile-field label{font-size:13px;font-weight:700;color:#364153}.profile-field input{min-height:42px;padding:0 12px;border:1px solid #d1d5db;border-radius:8px;font:inherit}.profile-field input:focus{outline:none;border-color:#155dfc;box-shadow:0 0 0 3px rgba(21,93,252,.12)}.profile-actions{margin-top:24px;display:flex;justify-content:flex-end;gap:10px}.profile-btn{min-height:42px;padding:0 16px;border:0;border-radius:8px;font-weight:700;cursor:pointer}.profile-btn--primary{color:#fff;background:#003087}.profile-btn--secondary{color:#364153;background:#f3f4f6;text-decoration:none;display:inline-flex;align-items:center}.alert{padding:12px 14px;border-radius:8px;margin-bottom:18px;font-weight:600}.alert--success{background:#ecfdf3;color:#027a48}.alert--error{background:#fef3f2;color:#b42318}.profile-topbar-user{display:inline-flex;align-items:center;gap:10px;font-weight:700;color:#364153}.profile-topbar-user a{color:#991b1b;background:#fee2e2;padding:8px 12px;border-radius:8px}@media(max-width:640px){.profile-grid{grid-template-columns:1fr}.profile-field--full{grid-column:auto}.profile-card{padding:20px}}
</style></head><body>
<div class="shell"><aside class="sidebar"><div class="sidebar__brand"><div class="sidebar__logo"><span class="sidebar__logo-text">NU</span></div><div><div class="sidebar__brand-name">SA System</div><div class="sidebar__brand-sub">Supervisor</div></div></div><nav class="sidebar__nav"><a href="dashboard.php" class="sidebar__nav-link">Dashboard</a><a href="attendance.php" class="sidebar__nav-link">Attendance</a><a href="evaluation.php" class="sidebar__nav-link">Evaluation</a><a href="reports.php" class="sidebar__nav-link">Reports</a><a href="students.php" class="sidebar__nav-link">Students</a><a href="announcements.php" class="sidebar__nav-link">Announcements</a></nav><div class="sidebar__footer"><a href="profile.php" class="sidebar__nav-link sidebar__nav-link--active">Profile</a><a href="logout.php" class="sidebar__nav-link">Sign Out</a></div></aside>
<main class="main"><header class="topbar"><div><div class="topbar__title">Supervisor Profile</div><div class="topbar__sub"><?= $h($officeName) ?></div></div><div class="topbar__right profile-topbar-user"><strong><?= $h($displayName) ?></strong><a href="logout.php">Logout</a></div></header><section class="page"><div class="profile-shell"><section class="profile-card"><div class="profile-heading"><div class="profile-avatar"><?= $h(strtoupper(substr($displayName,0,1))) ?></div><div><h1><?= $h($displayName) ?></h1><p><?= $h($officeName) ?> · Supervisor</p></div></div><?php if($message!==''): ?><div class="alert alert--success"><?= $h($message) ?></div><?php endif; ?><?php if($error!==''): ?><div class="alert alert--error"><?= $h($error) ?></div><?php endif; ?><form method="post"><?= sams_csrf_input_field() ?><div class="profile-grid"><div class="profile-field"><label for="first_name">First name</label><input id="first_name" name="first_name" required value="<?= $h($profile['first_name'] ?? '') ?>"></div><div class="profile-field"><label for="last_name">Last name</label><input id="last_name" name="last_name" required value="<?= $h($profile['last_name'] ?? '') ?>"></div><div class="profile-field profile-field--full"><label for="email">Email address</label><input id="email" name="email" type="email" required value="<?= $h($profile['email'] ?? '') ?>"></div><div class="profile-field profile-field--full"><label for="phone_number">Phone number</label><input id="phone_number" name="phone_number" inputmode="numeric" value="<?= $h($profile['phone_number'] ?? ($profile['phone'] ?? '')) ?>"></div></div><div class="profile-actions"><a class="profile-btn profile-btn--secondary" href="dashboard.php">Cancel</a><button class="profile-btn profile-btn--primary" type="submit">Save changes</button></div></form></section></div></section></main><script src="../assets/js/sams-theme.js?v=20260926"></script></div></body></html>
