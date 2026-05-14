<?php
declare(strict_types=1);

function sams_mail_config(): array
{
    return [
        'mode' => getenv('SAMS_MAIL_MODE') ?: 'smtp',
        'host' => getenv('SAMS_MAIL_HOST') ?: 'smtp.gmail.com',
        'port' => (int) (getenv('SAMS_MAIL_PORT') ?: 587),
        'username' => getenv('SAMS_MAIL_USERNAME') ?: '',
        'password' => getenv('SAMS_MAIL_PASSWORD') ?: '',
        'encryption' => getenv('SAMS_MAIL_ENCRYPTION') ?: 'tls',
        'from_email' => getenv('SAMS_MAIL_FROM_EMAIL') ?: '',
        'from_name' => getenv('SAMS_MAIL_FROM_NAME') ?: 'SAMS OTP',
        'test_to_email' => getenv('SAMS_MAIL_TEST_TO_EMAIL') ?: '',
    ];
}

function sams_configure_mailer(PHPMailer\PHPMailer\PHPMailer $mailer, array $config): void
{
    // If explicitly configured for SMTP, use it — but fall back to PHP mail
    // when credentials are clearly not configured to avoid hard failures on
    // local/dev environments. This preserves correct behavior in production
    // while keeping local setups working without env changes.
    if ($config['mode'] === 'smtp') {
        $username = trim((string)($config['username'] ?? ''));
        if ($username === '') {
            error_log('[sams] SMTP username missing or default — falling back to PHP mail()');
            $mailer->isMail();
            return;
        }

        $mailer->isSMTP();
        $mailer->Host = $config['host'];
        $mailer->Port = $config['port'];
        $mailer->SMTPAuth = $username !== '';
        if ($mailer->SMTPAuth) {
            $mailer->Username = $username;
            $mailer->Password = (string)($config['password'] ?? '');
        }
        if (!empty($config['encryption'])) {
            $mailer->SMTPSecure = $config['encryption'];
        }
        return;
    }

    $mailer->isMail();
}

function sams_build_branded_email(
        string $eyebrow,
        string $heading,
        string $message,
        string $accent = '#003087',
        ?string $ctaLabel = null,
        ?string $ctaUrl = null
): string {
        $safeEyebrow = htmlspecialchars($eyebrow, ENT_QUOTES, 'UTF-8');
        $safeHeading = htmlspecialchars($heading, ENT_QUOTES, 'UTF-8');
        $safeMessage = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));
        $safeCtaLabel = $ctaLabel !== null ? htmlspecialchars($ctaLabel, ENT_QUOTES, 'UTF-8') : '';
        $safeCtaUrl = $ctaUrl !== null ? htmlspecialchars($ctaUrl, ENT_QUOTES, 'UTF-8') : '';

        $ctaHtml = '';
        if ($ctaLabel !== null && $ctaUrl !== null) {
                $ctaHtml = '
                    <tr>
                        <td style="padding: 8px 0 0;">
                            <a href="' . $safeCtaUrl . '" style="display:inline-block;background:' . $accent . ';color:#fff;text-decoration:none;font-weight:700;padding:14px 22px;border-radius:12px;">' . $safeCtaLabel . '</a>
                        </td>
                    </tr>';
        }

        return '
            <div style="margin:0;padding:0;background:#f3f7ff;font-family:Arial,Helvetica,sans-serif;color:#101828;">
                <div style="max-width:640px;margin:0 auto;padding:32px 16px;">
                    <div style="background:#ffffff;border:1px solid #e5e7eb;border-radius:24px;overflow:hidden;box-shadow:0 16px 40px rgba(0,0,0,.08);">
                        <div style="background:' . $accent . ';padding:24px 28px;color:#ffffff;">
                            <div style="font-size:12px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;opacity:.85;">' . $safeEyebrow . '</div>
                            <div style="font-size:28px;line-height:1.15;font-weight:900;margin-top:8px;">' . $safeHeading . '</div>
                        </div>
                        <div style="padding:28px;">
                            <div style="font-size:16px;line-height:1.7;color:#364153;">' . $safeMessage . '</div>
                            <table role="presentation" style="width:100%;border-collapse:collapse;margin-top:24px;">
                                <tr>
                                    <td style="border-top:1px solid #e5e7eb;padding-top:20px;font-size:13px;color:#6b7280;line-height:1.6;">
                                        If you did not expect this email, you can safely ignore it.
                                    </td>
                                </tr>' . $ctaHtml . '
                            </table>
                        </div>
                    </div>
                </div>
            </div>';
}

function sams_send_otp_email(string $toEmail, string $toName, string $otpCode): void
{
    require_once __DIR__ . '/../vendor/autoload.php';

    $config = sams_mail_config();

    $mailer = new PHPMailer\PHPMailer\PHPMailer(true);
    $mailer->CharSet = 'UTF-8';
    $mailer->setFrom($config['from_email'], $config['from_name']);
    // In normal use send to the actual recipient. Only override when running in
    // explicit "test" mode and a test address is configured. This prevents OTPs
    // being sent to a developer/test address during production or SMTP mode.
    if ($config['mode'] === 'test' && $config['test_to_email'] !== '') {
        $recipientEmail = $config['test_to_email'];
    } else {
        $recipientEmail = $toEmail;
    }
    $recipientName = $toName;
    $mailer->addAddress($recipientEmail, $recipientName);

        sams_configure_mailer($mailer, $config);

    $mailer->isHTML(true);
    $mailer->Subject = 'Your SAMS login OTP';
        $mailer->Body = sams_build_branded_email(
                'SAMS Login OTP',
                'Verify your login',
                'Hello ' . $toName . ",\n\nYour SAMS login OTP is " . $otpCode . ".\n\nThis code expires in 10 minutes.",
                '#003087'
        );
        $mailer->AltBody = 'Hello ' . $toName . ",\n\nYour SAMS login OTP is " . $otpCode . ".\n\nThis code expires in 10 minutes.";

    $mailer->send();
}

function sams_send_application_review_email(string $toEmail, string $toName, string $status): void
{
    require_once __DIR__ . '/../vendor/autoload.php';

    $config = sams_mail_config();
    $isApproved = $status === 'approved';
    $subject = $isApproved
        ? 'Your SAMS application has been approved'
        : 'Your SAMS application has been declined';
    $message = $isApproved
        ? 'Congratulations! Your application has been approved. Please log in to SAMS to continue your student assistant onboarding.'
        : 'Your application has been declined. You may contact the admin office if you need more information.';

    $mailer = new PHPMailer\PHPMailer\PHPMailer(true);
    $mailer->CharSet = 'UTF-8';
    $mailer->setFrom($config['from_email'], $config['from_name']);
    $mailer->addAddress($toEmail, $toName);

    sams_configure_mailer($mailer, $config);

    $mailer->isHTML(true);
    $mailer->Subject = $subject;
    $mailer->Body = sams_build_branded_email(
        'SAMS Application Update',
        $isApproved ? 'Application Approved' : 'Application Declined',
        'Hello ' . $toName . ",\n\n" . $message,
        $isApproved ? '#008236' : '#b91c1c',
        $isApproved ? 'Log in to SAMS' : null,
        $isApproved ? 'http://localhost/samss-main/login.php' : null
    );
    $mailer->AltBody = 'Hello ' . $toName . ",\n\n" . $message;

    try {
        $mailer->send();
    } catch (Throwable $e) {
        // Attempt a safe fallback with PHP mail() in case SMTP fails. Log both
        // failures and rethrow so callers can surface an admin-visible error.
        error_log('[sams] Primary mailer failed: ' . $e->getMessage());
        try {
            $mailer->clearAllRecipients();
            $mailer->isMail();
            $mailer->addAddress($toEmail, $toName);
            $mailer->send();
            error_log('[sams] Fallback PHP mail() succeeded for ' . $toEmail);
            return;
        } catch (Throwable $e2) {
            error_log('[sams] Fallback mail failed: ' . $e2->getMessage());
            throw $e2;
        }
    }
}

function sams_send_password_reset_email(string $toEmail, string $toName, string $resetLink): void
{
    require_once __DIR__ . '/../vendor/autoload.php';

    $config = sams_mail_config();

    $mailer = new PHPMailer\PHPMailer\PHPMailer(true);
    $mailer->CharSet = 'UTF-8';
    $mailer->setFrom($config['from_email'], $config['from_name']);
    $mailer->addAddress($toEmail, $toName);

    sams_configure_mailer($mailer, $config);

    $mailer->isHTML(true);
    $mailer->Subject = 'Reset your SAMS password';
    $mailer->Body = sams_build_branded_email(
        'SAMS Password Reset',
        'Reset your password',
        'Hello ' . $toName . ",\n\nWe received a request to reset your SAMS password. Click the button below to create a new password. This link will expire in 1 hour.",
        '#003087',
        'Reset Password',
        $resetLink
    );
    $mailer->AltBody = 'Hello ' . $toName . ",\n\nWe received a request to reset your SAMS password. Open this link to create a new password: " . $resetLink . "\n\nThis link will expire in 1 hour.";

    $mailer->send();
}
