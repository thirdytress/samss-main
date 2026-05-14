<?php
declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/config/mail.php';

$pending = $_SESSION['sams_pending_student_login'] ?? null;
if (!is_array($pending) || empty($pending['user']) || empty($pending['otp_hash']) || empty($pending['expires_at'])) {
    header('Location: login.php');
    exit;
}

$error = '';
$success = '';
$pendingUser = $pending['user'];
$email = (string) ($pendingUser['email'] ?? '');
$name = trim((string) ($pendingUser['first_name'] ?? '') . ' ' . (string) ($pendingUser['last_name'] ?? ''));

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? 'verify');

    if ($action === 'resend') {
        try {
            $otpCode = (string) random_int(100000, 999999);
            $_SESSION['sams_pending_student_login']['otp_hash'] = password_hash($otpCode, PASSWORD_DEFAULT);
            $_SESSION['sams_pending_student_login']['expires_at'] = time() + 600;
            $_SESSION['sams_pending_student_login']['created_at'] = time();

            sams_send_otp_email($email, $name !== '' ? $name : 'Student', $otpCode);
            $success = 'A new OTP has been sent to your email.';
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }
    } else {
        $otp = preg_replace('/\D+/', '', (string) ($_POST['otp'] ?? ''));

        if ($otp === '') {
            $error = 'Please enter the OTP code.';
        } elseif ((int) $pending['expires_at'] < time()) {
            $error = 'OTP expired. Please resend a new code.';
        } elseif (!password_verify($otp, (string) $pending['otp_hash'])) {
            $error = 'Invalid OTP. Please try again.';
        } else {
            $user = [
                'id' => (int) ($pendingUser['id'] ?? 0),
                'role' => 'student',
                'email' => $email,
                'first_name' => (string) ($pendingUser['first_name'] ?? ''),
                'last_name' => (string) ($pendingUser['last_name'] ?? ''),
                'student_id' => (string) ($pendingUser['student_id'] ?? ''),
                'must_change_password' => (int) ($pendingUser['must_change_password'] ?? 0),
            ];

            unset($_SESSION['sams_pending_student_login']);
            sams_login($user);

            if ((int) ($user['must_change_password'] ?? 0) === 1) {
                header('Location: change_password.php');
                exit;
            }

            header('Location: ' . sams_dashboard_for_role('student'));
            exit;
        }
    }
}

$expiresIn = max(0, (int) $pending['expires_at'] - time());
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Verify OTP – SAMS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;700;900&display=swap" rel="stylesheet" />
    <style>
        :root{--bg:linear-gradient(135deg,#eff6ff 0%,#ffffff 50%,#fffbeb 100%);--card:#fff;--border:#e5e7eb;--text:#101828;--muted:#4a5565;--primary:#003087;--primary-end:#0047ab;--danger:#b91c1c;--danger-bg:#fef2f2;--success:#047857;--success-bg:#ecfdf5;--shadow:0 25px 50px rgba(0,0,0,.18);--radius:18px}
        *{box-sizing:border-box}body{margin:0;font-family:'Inter',sans-serif;background:var(--bg);min-height:100vh;display:flex;align-items:center;justify-content:center;color:var(--text)}a{text-decoration:none;color:inherit}.wrap{width:min(100%,560px);padding:24px}.card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);padding:32px}.eyebrow{display:inline-flex;align-items:center;gap:8px;padding:6px 10px;border-radius:9999px;background:#dcfce7;color:#166534;font-size:12px;font-weight:900;margin-bottom:16px}.title{font-size:32px;line-height:1.1;margin:0 0 8px;font-weight:900}.sub{margin:0 0 20px;color:var(--muted);line-height:1.6}.alert{padding:12px 14px;border-radius:12px;margin-bottom:14px;font-weight:700}.alert--error{background:var(--danger-bg);color:var(--danger);border:1px solid #fecaca}.alert--success{background:var(--success-bg);color:var(--success);border:1px solid #a7f3d0}.meta{padding:14px;border-radius:14px;background:#f8fafc;border:1px solid var(--border);margin-bottom:18px}.meta__row{display:flex;justify-content:space-between;gap:12px;font-size:14px;color:var(--muted);padding:6px 0}.otp{display:flex;gap:10px;justify-content:center;margin:16px 0 20px}.otp input{width:min(100%,56px);height:64px;text-align:center;font-size:28px;font-weight:900;border:2px solid #d1d5dc;border-radius:14px;outline:none}.otp input:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(0,48,135,.12)}.actions{display:flex;flex-direction:column;gap:12px}.btn{height:52px;border:0;border-radius:14px;font-size:16px;font-weight:900;cursor:pointer}.btn--primary{background:linear-gradient(90deg,var(--primary) 0%,var(--primary-end) 100%);color:#fff}.btn--secondary{background:#f3f4f6;color:#374151}.footer{margin-top:16px;text-align:center;color:var(--muted);font-size:14px}.footer a{color:var(--primary);font-weight:900}@media (max-width:480px){.wrap{padding:14px}.card{padding:22px}.title{font-size:26px}.otp{gap:8px}.otp input{width:46px;height:56px;font-size:24px}}
    </style>
</head>
<body>
    <div class="wrap">
        <div class="card">
            <div class="eyebrow">Student OTP Verification</div>
            <h1 class="title">Check your email</h1>
            <p class="sub">We sent a one-time password to your student email. Enter the code below to finish logging in.</p>

            <?php if ($error !== ''): ?>
                <div class="alert alert--error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <?php if ($success !== ''): ?>
                <div class="alert alert--success"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <div class="meta">
                <div class="meta__row"><span>Email</span><strong><?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?></strong></div>
                <div class="meta__row"><span>Expires in</span><strong><?php echo (int) ceil($expiresIn / 60); ?> min</strong></div>
            </div>

            <form method="POST" autocomplete="off">
                <input type="hidden" name="action" value="verify" />
                <div class="otp">
                    <input type="text" name="otp" maxlength="6" inputmode="numeric" pattern="[0-9]*" aria-label="One time password" autofocus />
                </div>
                <div class="actions">
                    <button class="btn btn--primary" type="submit">Verify OTP</button>
                </div>
            </form>

            <form method="POST" style="margin-top:12px;">
                <input type="hidden" name="action" value="resend" />
                <button class="btn btn--secondary" type="submit">Resend OTP</button>
            </form>

            <div class="footer">
                <a href="login.php">Back to login</a>
            </div>
        </div>
    </div>
</body>
</html>
