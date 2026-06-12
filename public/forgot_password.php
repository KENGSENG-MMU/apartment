<?php
require_once '../core/security.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../core/mail_config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

$pdo = db();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function has_column_forgot_password(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND COLUMN_NAME = ?
        ");
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function send_reset_otp_email(string $toEmail, string $otp): void
{
    $mail = new PHPMailer(true);

    $mail->isSMTP();
    $mail->Host       = SVMS_SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = SVMS_SMTP_USERNAME;
    $mail->Password   = SVMS_SMTP_PASSWORD;
    $mail->SMTPSecure = SVMS_SMTP_SECURE;
    $mail->Port       = SVMS_SMTP_PORT;

    $mail->setFrom(SVMS_SMTP_FROM_EMAIL, SVMS_SMTP_FROM_NAME);
    $mail->addAddress($toEmail);

    $mail->isHTML(true);
    $mail->Subject = 'SmartVMS Password Reset Code';

    $mail->Body = '
        <div style="font-family:Arial,sans-serif;background:#f6f9ff;padding:30px;">
            <div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:18px;padding:30px;border:1px solid #dbe7f7;">
                <h2 style="margin-top:0;color:#111827;">SmartVMS Password Reset</h2>

                <p style="color:#475569;font-size:15px;line-height:1.7;">
                    We received a request to reset your SmartVMS password.
                </p>

                <p style="color:#475569;font-size:15px;line-height:1.7;">
                    Use this verification code to continue:
                </p>

                <div style="margin:25px 0;text-align:center;">
                    <div style="display:inline-block;padding:16px 28px;font-size:30px;font-weight:800;letter-spacing:8px;color:#2563eb;background:#eff6ff;border:1px solid #bfdbfe;border-radius:14px;">
                        ' . htmlspecialchars($otp) . '
                    </div>
                </div>

                <p style="color:#475569;font-size:14px;line-height:1.7;">
                    This code will expire in <strong>15 minutes</strong>.
                </p>

                <p style="color:#94a3b8;font-size:13px;line-height:1.7;margin-top:28px;">
                    If you did not request this, you can ignore this email.
                </p>

                <p style="color:#111827;font-size:14px;font-weight:700;margin-top:24px;">
                    SmartVMS IXORA
                </p>
            </div>
        </div>
    ';

    $mail->AltBody = "Your SmartVMS password reset code is: {$otp}. This code expires in 15 minutes.";

    $mail->send();
}

$error = '';
$email = strtolower(trim($_POST['email'] ?? ''));

$emailColumn = has_column_forgot_password($pdo, 'users', 'email') ? 'email' : 'username';
$hasStatus = has_column_forgot_password($pdo, 'users', 'status');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $csrf)) {
        $error = 'Invalid security token. Please refresh the page.';
    } elseif ($email === '') {
        $error = 'Please enter your email address.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        try {
            $sql = "SELECT id, {$emailColumn} AS email, role";

            if ($hasStatus) {
                $sql .= ", status";
            }

            $sql .= " FROM users WHERE {$emailColumn} = ? LIMIT 1";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if (!$user) {
                throw new Exception('No visitor or resident account found with this email.');
            }

            if (!in_array($user['role'] ?? '', ['visitor', 'resident'], true)) {
                throw new Exception('This reset page is only for visitor and resident accounts.');
            }

            if ($hasStatus && isset($user['status']) && $user['status'] !== 'active') {
                throw new Exception('This account is not active. Please contact admin.');
            }

            $otp = (string)random_int(100000, 999999);
            $otpHash = password_hash($otp, PASSWORD_DEFAULT);

            $pdo->prepare("
                DELETE FROM password_resets
                WHERE email = ?
                OR expires_at < NOW()
                OR used_at IS NOT NULL
            ")->execute([$email]);

            $stmt = $pdo->prepare("
                INSERT INTO password_resets
                (user_id, email, otp_hash, expires_at, created_at)
                VALUES
                (?, ?, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE), NOW())
            ");

            $stmt->execute([
                (int)$user['id'],
                $email,
                $otpHash
            ]);

            send_reset_otp_email($email, $otp);

            $_SESSION['reset_email'] = $email;
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            header('Location: reset_password.php');
            exit;

        } catch (Throwable $e) {
            $error = 'System error: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Forgot Password - SmartVMS</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Plus Jakarta Sans', sans-serif;
        }

        body {
            min-height: 100vh;
            background: linear-gradient(90deg, #eef1f5 0%, #edf2f8 32%, #e9f3ff 100%);
            position: relative;
            overflow-x: hidden;
            color: #0f172a;
        }

        .bg-blur-left {
            position: absolute;
            top: 0;
            left: 0;
            width: 380px;
            height: 100%;
            background: radial-gradient(circle at left center, rgba(255,255,255,0.55), transparent 70%);
            pointer-events: none;
        }

        .bg-blur-right {
            position: absolute;
            top: 0;
            right: 0;
            width: 320px;
            height: 100%;
            background: radial-gradient(circle at right center, rgba(255,255,255,0.35), transparent 70%);
            pointer-events: none;
        }

        .cloud {
            position: absolute;
            width: 105px;
            height: 42px;
            background: rgba(255,255,255,0.35);
            border: 2px solid #d9e5f5;
            border-radius: 999px;
            z-index: 1;
        }

        .cloud::before,
        .cloud::after {
            content: "";
            position: absolute;
            background: rgba(255,255,255,0.35);
            border: 2px solid #d9e5f5;
            border-radius: 50%;
        }

        .cloud::before {
            width: 42px;
            height: 42px;
            left: 12px;
            top: -18px;
        }

        .cloud::after {
            width: 50px;
            height: 50px;
            left: 42px;
            top: -24px;
        }

        .cloud-left {
            top: 115px;
            left: 90px;
        }

        .cloud-right {
            top: 95px;
            right: 120px;
        }

        .star {
            position: absolute;
            color: #f5c560;
            font-size: 20px;
            z-index: 1;
        }

        .star.s1 {
            top: 180px;
            left: 340px;
        }

        .star.s2 {
            top: 305px;
            right: 250px;
        }

        .star.s3 {
            bottom: 165px;
            left: 225px;
            color: #9bc8ff;
        }

        .star.s4 {
            bottom: 120px;
            right: 155px;
            color: #b6cff4;
        }

        .forget-bubble {
            position: absolute;
            right: 250px;
            top: 365px;
            width: 54px;
            height: 40px;
            border: 2px solid #9bc8f0;
            border-radius: 50px;
            z-index: 1;
        }

        .forget-bubble::before {
            content: "";
            position: absolute;
            width: 18px;
            height: 18px;
            left: 6px;
            top: 9px;
            border-radius: 50%;
            border: 2px solid #9bc8f0;
        }

        .forget-line {
            position: absolute;
            right: 170px;
            top: 408px;
            width: 70px;
            height: 18px;
            border-bottom: 2px dashed #d7e4f4;
            border-radius: 50%;
            z-index: 1;
        }

        .peach-wrap {
            position: absolute;
            left: 150px;
            bottom: 52px;
            z-index: 1;
        }

        .peach-leaf-1,
        .peach-leaf-2 {
            position: absolute;
            background: #b9ddb0;
            border: 2px solid #89c184;
            border-radius: 40px 40px 10px 40px;
        }

        .peach-leaf-1 {
            width: 38px;
            height: 58px;
            left: 20px;
            top: -56px;
            transform: rotate(12deg);
        }

        .peach-leaf-2 {
            width: 30px;
            height: 48px;
            left: 45px;
            top: -40px;
            transform: rotate(-62deg);
        }

        .peach {
            width: 62px;
            height: 62px;
            background: #ffe7c8;
            border: 2px solid #e7b98d;
            border-radius: 50%;
            position: relative;
        }

        .peach::before,
        .peach::after {
            content: "";
            position: absolute;
            width: 6px;
            height: 6px;
            background: #5f6774;
            border-radius: 50%;
            top: 28px;
        }

        .peach::before {
            left: 22px;
        }

        .peach::after {
            right: 18px;
        }

        .peach-smile {
            position: absolute;
            width: 15px;
            height: 8px;
            border-bottom: 2px solid #5f6774;
            border-radius: 0 0 12px 12px;
            left: 24px;
            top: 36px;
        }

        .bush-wrap {
            position: absolute;
            right: 120px;
            bottom: 18px;
            display: flex;
            align-items: flex-end;
            gap: 0;
            z-index: 1;
        }

        .bush1, .bush2, .bush3 {
            background: #c8e3bf;
            border: 2px solid #a9cf9d;
        }

        .bush1 {
            width: 52px;
            height: 52px;
            border-radius: 52px 52px 0 0;
        }

        .bush2 {
            width: 78px;
            height: 74px;
            border-radius: 74px 74px 0 0;
            margin-left: -10px;
        }

        .bush3 {
            width: 58px;
            height: 46px;
            border-radius: 46px 46px 0 0;
            margin-left: -10px;
        }

        .page-wrap {
            position: relative;
            z-index: 5;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 50px 20px;
        }

        .content-box {
            width: 100%;
            max-width: 830px;
        }

        .page-title-wrap {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 16px;
            justify-content: center;
        }

        .page-title-icon {
            width: 58px;
            height: 58px;
            border-radius: 20px;
            border: 2px solid #efc08d;
            background: #fff5ec;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #6b7280;
            font-size: 1.35rem;
            position: relative;
            transform: rotate(-6deg);
            flex-shrink: 0;
        }

        .page-title-icon .heart-badge {
            position: absolute;
            right: -8px;
            bottom: -6px;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: #ffd9e6;
            border: 2px solid #f5a4bf;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ef739f;
            font-size: 12px;
        }

        .title-text h1 {
            font-size: 3.1rem;
            line-height: 1;
            font-weight: 900;
            color: #0f172a;
            letter-spacing: -1.5px;
        }

        .title-text p {
            margin-top: 8px;
            font-size: 0.95rem;
            color: #667085;
            font-weight: 700;
        }

        .title-text p .pink-heart {
            color: #f69bb8;
        }

        .card {
            max-width: 820px;
            margin: 0 auto;
            background: rgba(255,255,255,0.9);
            border: 1px solid #d8e0ec;
            border-radius: 30px;
            overflow: hidden;
            box-shadow: 0 20px 55px rgba(15, 23, 42, 0.06);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 22px 22px;
            border-bottom: 1px solid #e3e8f0;
        }

        .card-header-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .form-icon {
            width: 30px;
            height: 30px;
            border-radius: 10px;
            border: 1.8px solid #efc08d;
            background: #fff5ec;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #d89d59;
            font-size: 0.95rem;
        }

        .card-header h2 {
            font-size: 1.05rem;
            font-weight: 800;
            color: #0f172a;
        }

        .tiny-face {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            border: 2px solid #ecb64b;
            background: #fff6dd;
            position: relative;
            flex-shrink: 0;
        }

        .tiny-face::before,
        .tiny-face::after {
            content: "";
            position: absolute;
            width: 4px;
            height: 4px;
            background: #7a6948;
            border-radius: 50%;
            top: 14px;
        }

        .tiny-face::before {
            left: 10px;
        }

        .tiny-face::after {
            right: 10px;
        }

        .tiny-face span {
            position: absolute;
            left: 10px;
            top: 19px;
            width: 16px;
            height: 8px;
            border-bottom: 2px solid #7a6948;
            border-radius: 0 0 12px 12px;
        }

        .card-body {
            padding: 20px;
        }

        .info-box {
            background: #eef5ff;
            border: 1px solid #bdd1f5;
            border-radius: 20px;
            padding: 16px 18px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 20px;
        }

        .info-icon {
            width: 28px;
            height: 28px;
            min-width: 28px;
            border-radius: 50%;
            background: #dcecff;
            color: #4b80ea;
            border: 1px solid #9fc0f7;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .info-box p {
            color: #5f6f86;
            font-size: 0.95rem;
            font-weight: 700;
            line-height: 1.65;
        }

        .message {
            border-radius: 16px;
            padding: 14px 16px;
            font-size: 0.92rem;
            font-weight: 700;
            margin-bottom: 18px;
        }

        .message.error {
            background: #fff1f2;
            border: 1px solid #f7b1bd;
            color: #b42318;
        }

        .field {
            margin-bottom: 18px;
        }

        .field label {
            display: block;
            margin-bottom: 9px;
            font-size: 0.78rem;
            font-weight: 800;
            color: #667085;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .input-wrap {
            position: relative;
        }

        .input-wrap input {
            width: 100%;
            height: 56px;
            border: 1px solid #d5dde8;
            border-radius: 16px;
            background: #ffffff;
            outline: none;
            padding: 0 48px 0 16px;
            font-size: 0.97rem;
            font-weight: 700;
            color: #1f2937;
            transition: 0.2s ease;
        }

        .input-wrap input::placeholder {
            color: #9aa4b2;
            font-weight: 600;
        }

        .input-wrap input:focus {
            border-color: #9fc0f7;
            box-shadow: 0 0 0 4px rgba(159, 192, 247, 0.18);
        }

        .input-icon {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #a8b4c5;
            font-size: 1rem;
        }

        .btn {
            width: 100%;
            height: 56px;
            border: none;
            border-radius: 16px;
            background: linear-gradient(90deg, #2d7ef7 0%, #5a46ea 100%);
            color: white;
            font-size: 1rem;
            font-weight: 800;
            cursor: pointer;
            box-shadow: 0 12px 30px rgba(45, 126, 247, 0.20);
            transition: 0.2s ease;
        }

        .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 16px 35px rgba(45, 126, 247, 0.26);
        }

        .bottom-link {
            text-align: center;
            margin-top: 18px;
            color: #6b7280;
            font-weight: 700;
            font-size: 0.95rem;
        }

        .bottom-link a {
            color: #2563eb;
            text-decoration: none;
            font-weight: 800;
        }

        .bottom-link a:hover {
            text-decoration: underline;
        }

        @media (max-width: 900px) {
            .cloud,
            .star,
            .forget-bubble,
            .forget-line,
            .peach-wrap,
            .bush-wrap {
                display: none;
            }

            .title-text h1 {
                font-size: 2.35rem;
            }

            .page-title-wrap {
                justify-content: flex-start;
            }

            .content-box {
                max-width: 100%;
            }
        }

        @media (max-width: 600px) {
            .page-wrap {
                padding: 24px 14px;
            }

            .title-text h1 {
                font-size: 1.95rem;
            }

            .title-text p {
                font-size: 0.88rem;
            }

            .page-title-icon {
                width: 50px;
                height: 50px;
                border-radius: 16px;
                font-size: 1.1rem;
            }

            .card {
                border-radius: 22px;
            }

            .card-header,
            .card-body {
                padding: 16px;
            }

            .info-box p {
                font-size: 0.88rem;
            }
        }
    </style>
</head>
<body>

<div class="bg-blur-left"></div>
<div class="bg-blur-right"></div>

<div class="cloud cloud-left"></div>
<div class="cloud cloud-right"></div>

<div class="star s1"><i class="fas fa-star"></i></div>
<div class="star s2"><i class="fas fa-star"></i></div>
<div class="star s3"><i class="far fa-star"></i></div>
<div class="star s4"><i class="far fa-star"></i></div>

<div class="forget-bubble"></div>
<div class="forget-line"></div>

<div class="peach-wrap">
    <div class="peach-leaf-1"></div>
    <div class="peach-leaf-2"></div>
    <div class="peach">
        <div class="peach-smile"></div>
    </div>
</div>

<div class="bush-wrap">
    <div class="bush1"></div>
    <div class="bush2"></div>
    <div class="bush3"></div>
</div>

<div class="page-wrap">
    <div class="content-box">

        <div class="page-title-wrap">
            <div class="page-title-icon">
                <i class="fas fa-key"></i>
                <div class="heart-badge"><i class="fas fa-heart"></i></div>
            </div>

            <div class="title-text">
                <h1>Forgot Password</h1>
                <p>Enter your Gmail and receive a verification code to reset your password. <span class="pink-heart">♥</span></p>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <div class="card-header-left">
                    <div class="form-icon">
                        <i class="fas fa-envelope-open-text"></i>
                    </div>
                    <h2>Password Recovery Form</h2>
                </div>

                <div class="tiny-face">
                    <span></span>
                </div>
            </div>

            <div class="card-body">

                <div class="info-box">
                    <div class="info-icon">
                        <i class="fas fa-info"></i>
                    </div>
                    <p>
                        Don’t worry. Enter your Gmail address below and SmartVMS will send a verification code so you can reset your password safely.
                    </p>
                </div>

                <?php if ($error): ?>
                    <div class="message error">
                        <i class="fas fa-circle-exclamation"></i>
                        <?= e($error) ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">

                    <div class="field">
                        <label>Email Address</label>
                        <div class="input-wrap">
                            <input
                                type="email"
                                name="email"
                                placeholder="visitor@gmail.com"
                                value="<?= e($_POST['email'] ?? '') ?>"
                                required
                            >
                            <i class="fas fa-envelope input-icon"></i>
                        </div>
                    </div>

                    <button type="submit" class="btn">
                        Send Verification Code
                    </button>
                </form>

                <div class="bottom-link">
                    Remember your password?
                    <a href="user_login.php">Back to login</a>
                </div>

            </div>
        </div>

    </div>
</div>

</body>
</html>