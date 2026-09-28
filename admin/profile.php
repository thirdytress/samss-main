<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$currentUser = sams_authenticated_user();
if (!$currentUser || ($currentUser['role'] ?? null) !== 'admin') {
    header('Location: ../login.php');
    exit;
}

$pdo = sams_pdo();
$userId = (int) ($currentUser['user_id'] ?? 0);
$message = '';
$error = '';

$profileStatement = $pdo->prepare(
    'SELECT user_id, email, first_name, last_name, phone_number, role, created_at
     FROM users WHERE user_id = :user_id LIMIT 1'
);
$profileStatement->execute(['user_id' => $userId]);
$profile = $profileStatement->fetch(PDO::FETCH_ASSOC) ?: [];

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
                $duplicate = $pdo->prepare(
                    'SELECT COUNT(*) FROM users WHERE email = :email AND user_id <> :user_id'
                );
                $duplicate->execute(['email' => $email, 'user_id' => $userId]);
                if ((int) $duplicate->fetchColumn() > 0) {
                    throw new RuntimeException('That email is already in use.');
                }

                if ($phone !== '') {
                    $phoneDuplicate = $pdo->prepare(
                        'SELECT COUNT(*) FROM users WHERE phone_number = :phone AND user_id <> :user_id'
                    );
                    $phoneDuplicate->execute(['phone' => $phone, 'user_id' => $userId]);
                    if ((int) $phoneDuplicate->fetchColumn() > 0) {
                        throw new RuntimeException('That phone number is already in use.');
                    }
                }

                $update = $pdo->prepare(
                    'UPDATE users SET first_name = :first_name, last_name = :last_name,
                     email = :email, phone_number = :phone_number
                     WHERE user_id = :user_id'
                );
                $update->execute([
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $email,
                    'phone_number' => $phone !== '' ? $phone : null,
                    'user_id' => $userId,
                ]);

                $_SESSION['sams_user'] = array_merge($_SESSION['sams_user'], [
                    'email' => $email,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'name' => sams_normalize_name($firstName, $lastName),
                ]);
                $message = 'Profile updated successfully.';
                $profile['first_name'] = $firstName;
                $profile['last_name'] = $lastName;
                $profile['email'] = $email;
                $profile['phone_number'] = $phone;
            } catch (Throwable $exception) {
                $error = $exception->getMessage();
            }
        }
    }
}

$activeAdminNav = 'profile';
$displayName = sams_normalize_name($profile['first_name'] ?? null, $profile['last_name'] ?? null);
$h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Profile | SAMS</title>
    <link rel="stylesheet" href="../assets/css/admin-shell.css?v=20260925">
    <style>
        .profile-wrap { max-width: 760px; margin: 0 auto; }
        .profile-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 28px; box-shadow: 0 1px 3px rgba(16,24,40,.06); }
        .profile-heading { display:flex; align-items:center; gap:16px; margin-bottom:24px; }
        .profile-avatar { width:64px; height:64px; border-radius:50%; display:grid; place-items:center; color:#fff; background:#003087; font-size:22px; font-weight:800; }
        .profile-heading h1 { margin:0; font-size:24px; color:#101828; }
        .profile-heading p { margin:4px 0 0; color:#667085; }
        .profile-grid { display:grid; grid-template-columns:1fr 1fr; gap:18px; }
        .profile-field { display:flex; flex-direction:column; gap:7px; }
        .profile-field--full { grid-column:1 / -1; }
        .profile-field label { font-size:13px; font-weight:700; color:#364153; }
        .profile-field input { min-height:42px; padding:0 12px; border:1px solid #d1d5db; border-radius:8px; font:inherit; }
        .profile-field input:focus { outline:none; border-color:#155dfc; box-shadow:0 0 0 3px rgba(21,93,252,.12); }
        .profile-actions { margin-top:24px; display:flex; justify-content:flex-end; gap:10px; }
        .profile-btn { min-height:42px; padding:0 16px; border:0; border-radius:8px; font-weight:700; cursor:pointer; }
        .profile-btn--primary { color:#fff; background:#003087; }
        .profile-btn--secondary { color:#364153; background:#f3f4f6; text-decoration:none; display:inline-flex; align-items:center; }
        .profile-alert { padding:12px 14px; border-radius:8px; margin-bottom:18px; font-weight:600; }
        .profile-alert--success { background:#ecfdf3; color:#027a48; }
        .profile-alert--error { background:#fef3f2; color:#b42318; }
        @media (max-width: 640px) { .profile-grid { grid-template-columns:1fr; } .profile-field--full { grid-column:auto; } .profile-card { padding:20px; } }
    </style>
</head>
<body>
<div class="shell">
    <?php require __DIR__ . '/_sidebar.php'; ?>
    <div class="main">
        <header class="topbar">
            <div><div class="topbar__title">Admin Profile</div><div class="topbar__sub">Manage your account information</div></div>
        </header>
        <main class="page">
            <div class="profile-wrap">
                <section class="profile-card">
                    <div class="profile-heading">
                        <div class="profile-avatar"><?= $h(strtoupper(substr($displayName, 0, 1))) ?></div>
                        <div><h1><?= $h($displayName) ?></h1><p><?= $h(ucfirst((string) ($profile['role'] ?? 'admin'))) ?></p></div>
                    </div>
                    <?php if ($message !== ''): ?><div class="profile-alert profile-alert--success"><?= $h($message) ?></div><?php endif; ?>
                    <?php if ($error !== ''): ?><div class="profile-alert profile-alert--error"><?= $h($error) ?></div><?php endif; ?>
                    <form method="post">
                        <?= sams_csrf_input_field() ?>
                        <div class="profile-grid">
                            <div class="profile-field"><label for="first_name">First name</label><input id="first_name" name="first_name" required value="<?= $h($profile['first_name'] ?? '') ?>"></div>
                            <div class="profile-field"><label for="last_name">Last name</label><input id="last_name" name="last_name" required value="<?= $h($profile['last_name'] ?? '') ?>"></div>
                            <div class="profile-field profile-field--full"><label for="email">Email address</label><input id="email" name="email" type="email" required value="<?= $h($profile['email'] ?? '') ?>"></div>
                            <div class="profile-field profile-field--full"><label for="phone_number">Phone number</label><input id="phone_number" name="phone_number" inputmode="numeric" value="<?= $h($profile['phone_number'] ?? '') ?>"></div>
                        </div>
                        <div class="profile-actions"><a class="profile-btn profile-btn--secondary" href="dashboard.php">Cancel</a><button class="profile-btn profile-btn--primary" type="submit">Save changes</button></div>
                    </form>
                </section>
            </div>
        </main>
    </div>
</div>
</body>
</html>