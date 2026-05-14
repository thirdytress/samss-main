<?php
declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/config/mail.php';

$error = '';
$success = '';
$email = trim((string) ($_POST['email'] ?? ''));

function sams_ensure_password_reset_table(): void
{
    $pdo = sams_pdo();
    $pdo->exec(
      'CREATE TABLE IF NOT EXISTS password_reset_tokens (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
        token_hash CHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL,
        used_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user_id (user_id),
        INDEX idx_token_hash (token_hash),
        INDEX idx_expires_at (expires_at),
        CONSTRAINT fk_prt_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function sams_app_base_url(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');

    return $scheme . '://' . $host . ($path !== '' ? $path : '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        try {
            sams_ensure_password_reset_table();

            $pdo = sams_pdo();
            $userStatement = $pdo->prepare(
              'SELECT user_id, email, first_name, last_name
               FROM users
               WHERE email = :email
               LIMIT 1'
            );
            $userStatement->execute(['email' => $email]);
            $user = $userStatement->fetch();

            if ($user) {
              $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = :user_id')
                ->execute(['user_id' => (int) $user['user_id']]);

                $token = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $token);
                $expiresAt = (new DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s');

                $pdo->prepare(
                  'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at)
                   VALUES (:user_id, :token_hash, :expires_at)'
                )->execute([
                  'user_id' => (int) $user['user_id'],
                  'token_hash' => $tokenHash,
                  'expires_at' => $expiresAt,
                ]);

                $fullName = trim((string) ($user['first_name'] ?? '') . ' ' . (string) ($user['last_name'] ?? ''));
                $resetLink = sams_app_base_url() . '/reset_password.php?token=' . urlencode($token);

                sams_send_password_reset_email(
                    (string) $user['email'],
                    $fullName !== '' ? $fullName : (string) $user['email'],
                    $resetLink
                );
            }

            $success = 'If that email exists in our system, a reset link has been sent.';
        } catch (Throwable $exception) {
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
  <title>Forgot Password – SAMS</title>
  <style>
    body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center; font-family:Arial,sans-serif; background:linear-gradient(135deg,#eff6ff,#fff,#fffbeb); }
    .card { width:min(100%, 460px); background:#fff; border:1px solid #e5e7eb; border-radius:18px; padding:32px; box-shadow:0 20px 45px rgba(0,0,0,.15); }
    h1 { margin:0 0 8px; font-size:28px; color:#101828; }
    p { margin:0 0 24px; color:#4b5563; line-height:1.6; }
    label { display:block; margin-bottom:8px; font-weight:700; color:#374151; }
    input { width:100%; height:48px; padding:10px 14px; border:1px solid #d1d5db; border-radius:12px; margin-bottom:18px; font-size:15px; }
    button { width:100%; height:52px; border:none; border-radius:12px; background:#003087; color:#fff; font-size:16px; font-weight:700; cursor:pointer; }
    .error { background:#fef2f2; color:#b91c1c; border:1px solid #fecaca; padding:12px; border-radius:10px; margin-bottom:18px; font-weight:600; }
    .success { background:#ecfdf5; color:#047857; border:1px solid #a7f3d0; padding:12px; border-radius:10px; margin-bottom:18px; font-weight:600; }
    .back { display:block; margin-top:16px; text-align:center; color:#003087; font-weight:700; text-decoration:none; }
  </style>
</head>
<body>
  <div class="card">
    <h1>Forgot Password</h1>
    <p>Enter your email address and we will send you a secure link to reset your password.</p>

    <?php if ($error !== ''): ?>
      <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php if ($success !== ''): ?>
      <div class="success"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <form method="POST">
      <label for="email">Email Address</label>
      <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>" placeholder="you@example.com" required>
      <button type="submit">Send Reset Link</button>
    </form>

    <a class="back" href="login.php">Back to Login</a>
  </div>
</body>
</html>