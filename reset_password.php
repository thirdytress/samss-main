<?php
declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';

$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$error = '';

function sams_find_reset_token(string $token): ?array
{
    if ($token === '') {
        return null;
    }

    $statement = sams_pdo()->prepare(
        'SELECT id, user_id, expires_at, used_at
         FROM password_reset_tokens
         WHERE token_hash = :token_hash
         LIMIT 1'
    );
    $statement->execute(['token_hash' => hash('sha256', $token)]);
    $row = $statement->fetch();

    return $row ?: null;
}

$resetRow = sams_find_reset_token($token);

if (!$resetRow) {
    $error = 'Invalid or expired reset link.';
} elseif (!empty($resetRow['used_at'])) {
    $error = 'This reset link has already been used.';
} elseif (strtotime((string) $resetRow['expires_at']) < time()) {
    $error = 'This reset link has expired. Please request a new one.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
    $newPassword = trim((string) ($_POST['new_password'] ?? ''));
    $confirmPassword = trim((string) ($_POST['confirm_password'] ?? ''));

    if ($newPassword === '' || $confirmPassword === '') {
        $error = 'Please fill in all fields.';
    } elseif (strlen($newPassword) < 6) {
        $error = 'New password must be at least 6 characters.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } else {
        $pdo = sams_pdo();
        $passwordColumn = sams_first_existing_column($pdo, 'users', ['password', 'password_hash']);
        $mustChangePasswordColumn = sams_first_existing_column($pdo, 'users', ['must_change_password']);
        $pdo->beginTransaction();

        try {
            if ($passwordColumn === null) {
                throw new RuntimeException('Users table missing password column.');
            }

            $updateSql = 'UPDATE users SET ' . $passwordColumn . ' = :password';
            if ($mustChangePasswordColumn !== null) {
                $updateSql .= ', ' . $mustChangePasswordColumn . ' = 0';
            }
            $updateSql .= ' WHERE user_id = :user_id';

            $pdo->prepare(
                $updateSql
            )->execute([
                'password' => password_hash($newPassword, PASSWORD_DEFAULT),
                'user_id' => (int) $resetRow['user_id'],
            ]);

            $pdo->prepare(
                'UPDATE password_reset_tokens
                 SET used_at = NOW()
                 WHERE id = :id'
            )->execute(['id' => (int) $resetRow['id']]);

            $pdo->commit();

            header('Location: login.php?password_reset=1');
            exit;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $error = $exception->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Reset Password – SAMS</title>
  <style>
    body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center; font-family:Arial,sans-serif; background:linear-gradient(135deg,#eff6ff,#fff,#fffbeb); }
    .card { width:min(100%, 460px); background:#fff; border:1px solid #e5e7eb; border-radius:18px; padding:32px; box-shadow:0 20px 45px rgba(0,0,0,.15); }
    h1 { margin:0 0 8px; font-size:28px; color:#101828; }
    p { margin:0 0 24px; color:#4b5563; line-height:1.6; }
    label { display:block; margin-bottom:8px; font-weight:700; color:#374151; }
    input { width:100%; height:48px; padding:10px 14px; border:1px solid #d1d5db; border-radius:12px; margin-bottom:18px; font-size:15px; }
    button { width:100%; height:52px; border:none; border-radius:12px; background:#003087; color:#fff; font-size:16px; font-weight:700; cursor:pointer; }
    .error { background:#fef2f2; color:#b91c1c; border:1px solid #fecaca; padding:12px; border-radius:10px; margin-bottom:18px; font-weight:600; }
    .back { display:block; margin-top:16px; text-align:center; color:#003087; font-weight:700; text-decoration:none; }
  </style>
</head>
<body>
  <div class="card">
    <h1>Reset Password</h1>
    <p>Choose a new password for your SAMS account.</p>

    <?php if ($error !== ''): ?>
      <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
      <a class="back" href="forgot_password.php">Request a new reset link</a>
    <?php else: ?>
      <form method="POST">
        <input type="hidden" name="token" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">

        <label for="new_password">New Password</label>
        <input type="password" id="new_password" name="new_password" placeholder="Enter new password" required>

        <label for="confirm_password">Confirm Password</label>
        <input type="password" id="confirm_password" name="confirm_password" placeholder="Confirm new password" required>

        <button type="submit">Update Password</button>
      </form>
    <?php endif; ?>
  </div>
</body>
</html>