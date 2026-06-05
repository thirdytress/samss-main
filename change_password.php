<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/config/bootstrap.php';

$user = sams_authenticated_user();

if (!$user) {
    header('Location: login.php');
    exit;
}

$error = '';
$isFirstLogin = (int) ($user['must_change_password'] ?? 0) === 1;
$cancelUrl = '';
if (!$isFirstLogin) {
    $cancelUrl = $user['role'] === 'student' ? 'students/profile.php' : sams_dashboard_for_role((string)$user['role']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentPassword = trim($_POST['current_password'] ?? '');
    $newPassword = trim($_POST['new_password'] ?? '');
    $confirmPassword = trim($_POST['confirm_password'] ?? '');

    if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
        $error = 'Please fill in all fields.';
    } elseif (strlen($newPassword) < 6) {
        $error = 'New password must be at least 6 characters.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'New password and confirm password do not match.';
    } else {
        $pdo = sams_pdo();
        $passwordColumn = sams_first_existing_column($pdo, 'users', ['password', 'password_hash']);
        $mustChangePasswordColumn = sams_first_existing_column($pdo, 'users', ['must_change_password']);

        if ($passwordColumn === null) {
            $error = 'Users table missing password column.';
        } else {
            $stmt = $pdo->prepare('SELECT ' . $passwordColumn . ' FROM users WHERE user_id = :user_id LIMIT 1');
            $stmt->execute(['user_id' => (int) $user['user_id']]);
            $hash = $stmt->fetchColumn();

            if (!$hash || !password_verify($currentPassword, (string) $hash)) {
                $error = 'Current password is incorrect.';
            } else {
                $updateSql = 'UPDATE users SET ' . $passwordColumn . ' = :password';
                $params = [
                    'password' => password_hash($newPassword, PASSWORD_DEFAULT),
                    'user_id' => (int) $user['user_id'],
                ];

                if ($mustChangePasswordColumn !== null) {
                    $updateSql .= ', ' . $mustChangePasswordColumn . ' = 0';
                }

                $updateSql .= ' WHERE user_id = :user_id';

                $update = $pdo->prepare($updateSql);
                $update->execute($params);

                $_SESSION['sams_user']['must_change_password'] = 0;

                $redirectUrl = $isFirstLogin 
                    ? sams_dashboard_for_role((string) ($user['role'] ?? ''))
                    : ($user['role'] === 'student' ? 'students/profile.php' : sams_dashboard_for_role((string) ($user['role'] ?? '')));
                header('Location: ' . $redirectUrl);
                exit;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Change Password – SAMS</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <style>
        * {
            box-sizing: border-box;
            font-family: Arial, sans-serif;
        }

        body {
            margin: 0;
            min-height: 100vh;
            background: linear-gradient(135deg, #eff6ff, #ffffff, #fffbeb);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .card {
            width: 100%;
            max-width: 460px;
            background: white;
            border-radius: 18px;
            padding: 32px;
            box-shadow: 0 20px 45px rgba(0,0,0,0.15);
            border: 1px solid #e5e7eb;
        }

        h1 {
            margin: 0 0 8px;
            font-size: 28px;
            color: #101828;
        }

        p {
            margin: 0 0 24px;
            color: #4b5563;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 700;
            color: #374151;
        }

        input {
            width: 100%;
            height: 48px;
            padding: 10px 14px;
            border: 1px solid #d1d5db;
            border-radius: 12px;
            margin-bottom: 18px;
            font-size: 15px;
        }

        button {
            width: 100%;
            height: 52px;
            border: none;
            border-radius: 12px;
            background: #003087;
            color: white;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
        }

        .error {
            background: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fecaca;
            padding: 12px;
            border-radius: 10px;
            margin-bottom: 18px;
            font-weight: 600;
        }

        .btn-cancel {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            height: 52px;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            background: white;
            color: #4b5563;
            font-size: 16px;
            font-weight: 700;
            text-decoration: none;
            margin-top: 12px;
            transition: background 0.15s, border-color 0.15s;
        }
        .btn-cancel:hover {
            background: #f9fafb;
            border-color: #d1d5db;
        }
    </style>
</head>
<body>

<div class="card">
    <h1>Change Password</h1>
    <?php if ($isFirstLogin): ?>
        <p>For security, please change your default password before accessing your dashboard.</p>
    <?php else: ?>
        <p>Update your account password to keep your account secure.</p>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST">
        <label>Current Password</label>
        <input type="password" name="current_password" placeholder="<?= $isFirstLogin ? 'Enter your Student ID' : 'Enter current password' ?>" required>

        <label>New Password</label>
        <input type="password" name="new_password" placeholder="Enter new password" required>

        <label>Confirm New Password</label>
        <input type="password" name="confirm_password" placeholder="Confirm new password" required>

        <button type="submit">Update Password</button>
        <?php if (!$isFirstLogin): ?>
            <a href="<?= htmlspecialchars($cancelUrl) ?>" class="btn-cancel">Cancel</a>
        <?php endif; ?>
    </form>
</div>

</body>
</html>