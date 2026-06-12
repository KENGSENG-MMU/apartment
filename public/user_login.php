<?php
require_once '../core/security.php';

$pdo = db();

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function has_column_user_login(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
        AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function redirect_user_role(string $role): void {
    if ($role === 'visitor') {
        header("Location: visitor_book.php");
        exit;
    }

    if ($role === 'resident') {
        header("Location: resident.php");
        exit;
    }

    if ($role === 'admin' || $role === 'superadmin') {
        header("Location: admin_dash.php");
        exit;
    }

    if ($role === 'guard') {
        header("Location: guard_scan.php");
        exit;
    }

    header("Location: user_login.php");
    exit;
}

if (isset($_SESSION['uid'], $_SESSION['role'])) {
    redirect_user_role($_SESSION['role']);
}

$error = '';

$emailColumn = has_column_user_login($pdo, 'users', 'email') ? 'email' : 'username';
$passwordColumn = has_column_user_login($pdo, 'users', 'password_hash') ? 'password_hash' : 'password';
$hasStatus = has_column_user_login($pdo, 'users', 'status');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $csrf)) {
        $error = 'Invalid security token. Please refresh the page.';
    } else {
        $email = strtolower(trim($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';

        if ($email === '' || $password === '') {
            $error = 'Please enter email and password.';
        } else {
            try {
                $stmt = $pdo->prepare("
                    SELECT *
                    FROM users
                    WHERE {$emailColumn} = ?
                    LIMIT 1
                ");
                $stmt->execute([$email]);
                $user = $stmt->fetch();

                if (!$user) {
                    if (function_exists('log_audit')) {
                        log_audit('USER_LOGIN_FAILED', 'Failed user portal login attempt: ' . $email);
                    }

                    $error = 'Invalid email or password.';
                } else {
                    $role = $user['role'] ?? '';

                    if (!in_array($role, ['visitor', 'resident'], true)) {
                        if (function_exists('log_audit')) {
                            log_audit('WRONG_PORTAL_LOGIN', 'Staff tried to login from user portal: ' . $email);
                        }

                        $error = 'This portal is only for visitors and residents. Please use Staff Portal.';
                    } elseif ($hasStatus && isset($user['status']) && $user['status'] !== 'active') {
                        if (function_exists('log_audit')) {
                            log_audit('USER_LOGIN_BLOCKED', 'Inactive user account login attempt: ' . $email);
                        }

                        $error = 'Your account is not active yet. Please contact admin.';
                    } else {
                        $storedPassword = $user[$passwordColumn] ?? '';
                        $passwordOk = false;

                        if ($storedPassword !== '') {
                            $info = password_get_info($storedPassword);

                            if (!empty($info['algo'])) {
                                $passwordOk = password_verify($password, $storedPassword);
                            } else {
                                $passwordOk = hash_equals($storedPassword, $password);
                            }
                        }

                        if (!$passwordOk) {
                            if (function_exists('log_audit')) {
                                log_audit('USER_LOGIN_FAILED', 'Failed user portal login attempt: ' . $email);
                            }

                            $error = 'Invalid email or password.';
                        } else {
                            session_regenerate_id(true);

                            $_SESSION['uid'] = (int)$user['id'];
                            $_SESSION['role'] = $role;
                            $_SESSION['email'] = $user[$emailColumn];

                            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                            if (function_exists('log_audit')) {
                                log_audit('USER_LOGIN_SUCCESS', 'User logged in from user portal');
                            }

                            redirect_user_role($role);
                        }
                    }
                }
            } catch (Throwable $e) {
                $error = 'Login error: ' . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>User Login - <?= e(APP_NAME) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <style>
        :root {
            --ink: #0b1220;
            --text: #53627a;
            --muted: #7a8aa4;
            --line: #d9e4f2;
            --blue: #2563eb;
            --blue2: #31b9f4;
            --blueSoft: #eef6ff;
            --danger: #dc2626;
            --dangerBg: #fff1f2;
            --dangerLine: #fecaca;
            --card: rgba(255,255,255,.86);
            --shadow: 0 28px 85px rgba(15, 23, 42, .14);
            --shadowSoft: 0 16px 45px rgba(15, 23, 42, .07);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: "Inter", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        body {
            min-height: 100vh;
            color: var(--ink);
            background: #eff6ff;
            overflow-x: hidden;
        }

        body::before {
            content: "";
            position: fixed;
            inset: 0;
            z-index: -5;
            background:
                linear-gradient(105deg,
                    rgba(255,255,255,.78) 0%,
                    rgba(248,252,255,.58) 42%,
                    rgba(225,240,255,.40) 100%
                ),
                url("r_png1.jpg") center/cover no-repeat;
        }

        body::after {
            content: "";
            position: fixed;
            inset: 0;
            z-index: -4;
            pointer-events: none;
            backdrop-filter: blur(2px);
            background:
                radial-gradient(circle at 10% 20%, rgba(37, 99, 235, .08), transparent 24%),
                radial-gradient(circle at 88% 18%, rgba(56, 189, 248, .14), transparent 25%),
                radial-gradient(circle at 94% 84%, rgba(37, 99, 235, .06), transparent 24%),
                linear-gradient(180deg, rgba(255,255,255,.00), rgba(255,255,255,.18));
        }

        .back-link {
            position: fixed;
            top: 28px;
            left: 28px;
            z-index: 50;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            height: 46px;
            padding: 0 19px;
            border-radius: 999px;
            background: rgba(255,255,255,.88);
            color: var(--ink);
            border: 1px solid rgba(219,229,240,.95);
            text-decoration: none;
            font-size: .92rem;
            font-weight: 850;
            box-shadow: 0 12px 34px rgba(15,23,42,.08);
            backdrop-filter: blur(16px);
            transition: .22s ease;
        }

        .back-link:hover {
            transform: translateY(-1px);
            color: var(--blue);
            background: #fff;
        }

        .page {
            min-height: 100vh;
            width: min(1260px, calc(100% - 64px));
            margin: 0 auto;
            padding: 78px 0;
            display: grid;
            grid-template-columns: minmax(0, 1fr) 440px;
            align-items: center;
            gap: 56px;
        }

        .hero {
            position: relative;
            min-height: 690px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 48px 0 48px 12px;
        }

        .brand {
            display: inline-flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 52px;
        }

        .brand-icon {
            width: 56px;
            height: 56px;
            border-radius: 18px;
            display: grid;
            place-items: center;
            color: #fff;
            background: linear-gradient(135deg, var(--blue2), var(--blue));
            font-size: 1.35rem;
            box-shadow: 0 18px 40px rgba(37,99,235,.24);
        }

        .brand-name {
            font-size: 1.78rem;
            font-weight: 900;
            letter-spacing: -.055em;
        }

        .brand-name span {
            color: var(--blue);
        }

        .hero h1 {
            max-width: 700px;
            font-size: clamp(4.1rem, 6vw, 6.35rem);
            line-height: .97;
            letter-spacing: -.066em;
            font-weight: 900;
            color: #08111f;
            margin-bottom: 18px;
            text-wrap: balance;
        }

        .hero .subtitle {
            color: #5d6b83;
            font-size: clamp(1.35rem, 1.75vw, 1.9rem);
            line-height: 1.28;
            font-weight: 650;
            margin-bottom: 30px;
            letter-spacing: -.035em;
        }

        .hero .description {
            max-width: 520px;
            color: #5b6880;
            font-size: 1.17rem;
            line-height: 1.65;
            font-weight: 560;
            margin-bottom: 42px;
        }

        .feature-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(140px, 180px));
            gap: 18px;
            margin-bottom: 44px;
        }

        .feature-card {
            min-height: 166px;
            padding: 22px 18px;
            border-radius: 22px;
            background: rgba(255,255,255,.80);
            border: 1px solid rgba(219,229,240,.86);
            box-shadow: var(--shadowSoft);
            backdrop-filter: blur(18px);
            text-align: center;
            transition: .22s ease;
        }

        .feature-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 22px 55px rgba(15,23,42,.10);
        }

        .feature-icon {
            width: 62px;
            height: 62px;
            margin: 0 auto 16px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            background: #eaf3ff;
            color: var(--blue);
            font-size: 1.45rem;
        }

        .feature-card h3 {
            font-size: 1rem;
            font-weight: 900;
            letter-spacing: -.02em;
            margin-bottom: 10px;
        }

        .feature-card p {
            color: #64748b;
            font-size: .88rem;
            line-height: 1.55;
            font-weight: 560;
        }

        .entry-strip {
            width: min(100%, 470px);
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 16px 18px;
            border-radius: 20px;
            background: rgba(255,255,255,.80);
            border: 1px solid rgba(219,229,240,.86);
            box-shadow: var(--shadowSoft);
            backdrop-filter: blur(18px);
        }

        .entry-strip .strip-icon {
            width: 52px;
            height: 52px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            background: #eaf3ff;
            color: var(--blue);
            flex-shrink: 0;
        }

        .entry-strip strong {
            display: block;
            font-size: .96rem;
            font-weight: 900;
            margin-bottom: 6px;
        }

        .entry-strip span {
            color: #64748b;
            font-size: .86rem;
            font-weight: 560;
        }

        .login-card {
            position: relative;
            overflow: hidden;
            min-height: 630px;
            padding: 52px 38px 38px;
            border-radius: 28px;
            background: rgba(255,255,255,.88);
            border: 1px solid rgba(255,255,255,.92);
            box-shadow: var(--shadow);
            backdrop-filter: blur(24px) saturate(1.1);
        }

        .login-card::before {
            content: "";
            position: absolute;
            width: 156px;
            height: 156px;
            right: -60px;
            top: -62px;
            border-radius: 50%;
            background: rgba(37,99,235,.10);
            pointer-events: none;
        }

        .login-card::after {
            content: "";
            position: absolute;
            width: 90px;
            height: 90px;
            left: -46px;
            bottom: -46px;
            border-radius: 50%;
            background: rgba(56,189,248,.10);
            pointer-events: none;
        }

        .form-head,
        .portal-badge,
        .error,
        form,
        .links,
        .divider,
        .staff-link {
            position: relative;
            z-index: 2;
        }

        .form-head {
            display: grid;
            grid-template-columns: 70px 1fr;
            gap: 18px;
            align-items: center;
            margin-bottom: 38px;
        }

        .avatar {
            width: 66px;
            height: 66px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            background: #eaf3ff;
            color: var(--blue);
            font-size: 1.7rem;
            box-shadow: 0 14px 30px rgba(37,99,235,.13);
        }

        .form-head h2 {
            font-size: 2rem;
            line-height: 1.08;
            letter-spacing: -.05em;
            font-weight: 900;
            margin-bottom: 6px;
        }

        .form-head p {
            color: #64748b;
            font-size: 1.02rem;
            font-weight: 560;
        }

        .portal-badge {
            min-height: 42px;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            width: 100%;
            padding: 0 15px;
            border-radius: 14px;
            background: #eff6ff;
            color: var(--blue);
            border: 1px solid #bfdbfe;
            font-size: .85rem;
            font-weight: 850;
            margin-bottom: 34px;
        }

        .error {
            padding: 13px 14px;
            border-radius: 16px;
            background: #fff1f2;
            color: #b91c1c;
            border: 1px solid #fecaca;
            font-size: .86rem;
            font-weight: 760;
            line-height: 1.45;
            margin-bottom: 18px;
        }

        .field {
            margin-bottom: 24px;
        }

        label {
            display: block;
            color: #64748b;
            font-size: .78rem;
            font-weight: 900;
            letter-spacing: .08em;
            text-transform: uppercase;
            margin-bottom: 10px;
        }

        .input-wrap {
            position: relative;
        }

        input {
            width: 100%;
            height: 60px;
            padding: 0 52px 0 54px;
            border: 1px solid #d7e3f1;
            border-radius: 14px;
            background: rgba(255,255,255,.86);
            color: var(--ink);
            outline: none;
            font-size: 1rem;
            font-weight: 650;
            transition: .22s ease;
        }

        input::placeholder {
            color: #8da0ba;
            font-weight: 560;
        }

        input:focus {
            border-color: rgba(37,99,235,.60);
            background: #fff;
            box-shadow: 0 0 0 5px rgba(37,99,235,.10), 0 15px 28px rgba(37,99,235,.08);
        }

        .input-icon,
        .toggle-password {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            color: #73849d;
            font-size: 1.08rem;
        }

        .input-icon {
            left: 20px;
        }

        .toggle-password {
            right: 18px;
            border: none;
            background: transparent;
            cursor: pointer;
        }

        .field.is-invalid input {
            border-color: #ef4444;
            background: #fffafa;
            box-shadow: 0 0 0 5px rgba(239,68,68,.10);
        }

        .field-error {
            display: none;
            align-items: center;
            gap: 7px;
            margin-top: 9px;
            color: var(--danger);
            font-size: .8rem;
            font-weight: 780;
            line-height: 1.35;
        }

        .field.is-invalid .field-error {
            display: flex;
        }

        .forgot-link {
            text-align: right;
            margin: -4px 0 26px;
        }

        .forgot-link a,
        .links a {
            color: var(--blue);
            font-size: .92rem;
            font-weight: 850;
            text-decoration: none;
        }

        .forgot-link a:hover,
        .links a:hover {
            text-decoration: underline;
        }

        .btn-submit {
            width: 100%;
            height: 62px;
            border: none;
            border-radius: 14px;
            color: #fff;
            background: linear-gradient(135deg, var(--blue2), var(--blue) 62%, #1d4ed8);
            box-shadow: 0 20px 40px rgba(37,99,235,.25);
            font-size: 1rem;
            font-weight: 900;
            cursor: pointer;
            transition: .24s ease;
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 26px 52px rgba(37,99,235,.30);
        }

        .links {
            text-align: center;
            color: #64748b;
            font-size: .94rem;
            font-weight: 560;
            margin-top: 22px;
        }

        .divider {
            display: flex;
            align-items: center;
            gap: 14px;
            color: #94a3b8;
            font-size: .78rem;
            font-weight: 900;
            letter-spacing: .10em;
            text-transform: uppercase;
            margin: 42px 0 22px;
        }

        .divider::before,
        .divider::after {
            content: "";
            flex: 1;
            height: 1px;
            background: linear-gradient(90deg, transparent, #dbe5f0, transparent);
        }

        .staff-link {
            width: 100%;
            min-height: 58px;
            border-radius: 14px;
            border: 1px solid #d7e3f1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            color: var(--ink);
            background: rgba(255,255,255,.80);
            text-decoration: none;
            font-size: .96rem;
            font-weight: 850;
            transition: .22s ease;
        }

        .staff-link:hover {
            background: #eff6ff;
            color: var(--blue);
            border-color: #bfdbfe;
        }

        @media (max-width: 1080px) {
            .page {
                grid-template-columns: 1fr;
                max-width: 720px;
                gap: 34px;
            }

            .hero {
                min-height: auto;
                padding: 88px 0 10px;
            }

            .login-card {
                width: min(100%, 460px);
                margin: 0 auto;
            }
        }

        @media (max-width: 720px) {
            .page {
                width: calc(100% - 30px);
                padding: 34px 0;
            }

            .hero h1 {
                font-size: 3.35rem;
                letter-spacing: -.055em;
            }

            .feature-grid {
                grid-template-columns: 1fr;
            }

            .login-card {
                min-height: auto;
                border-radius: 24px;
                padding: 28px 20px;
            }

            .form-head {
                grid-template-columns: 1fr;
            }

            .back-link {
                position: static;
                margin: 16px 0 0 16px;
            }
        }
    </style>

<style id="login-clear-background-polish">
    .hero h1,
    .subtitle,
    .description,
    .brand-name {
        text-shadow: 0 1px 18px rgba(255,255,255,.75);
    }

    .feature-card,
    .entry-strip,
    .login-card {
        backdrop-filter: blur(18px) saturate(1.12) !important;
    }

    .login-card {
        background: rgba(255,255,255,.90) !important;
    }

    .feature-card,
    .entry-strip {
        background: rgba(255,255,255,.78) !important;
    }
</style>


<style id="back-link-click-fix">
    .back-link {
        position: fixed !important;
        z-index: 9999 !important;
        pointer-events: auto !important;
    }

    body::before,
    body::after,
    .hero::before,
    .hero::after,
    .showcase::before,
    .showcase::after,
    .login-card::before,
    .login-card::after {
        pointer-events: none !important;
    }
</style>

</head>
<body>

<a href="r_landingpage.php" class="back-link">
    <i class="fas fa-arrow-left"></i>
    Back
</a>

<main class="page">
    <section class="hero">
        <div class="brand">
            <div class="brand-icon">
                <i class="fas fa-building-shield"></i>
            </div>
            <div class="brand-name">Smart<span>VMS</span></div>
        </div>

        <h1>Welcome back.</h1>

        <div class="subtitle">
            Your SmartVMS access starts here.
        </div>

        <p class="description">
            Manage visitor bookings, QR pass access, resident approvals and apartment entry control in one secure portal.
        </p>

        <div class="feature-grid">
            <div class="feature-card">
                <div class="feature-icon">
                    <i class="fas fa-qrcode"></i>
                </div>
                <h3>QR Pass</h3>
                <p>Fast and secure visitor entry.</p>
            </div>

            <div class="feature-card">
                <div class="feature-icon">
                    <i class="fas fa-users"></i>
                </div>
                <h3>Resident Approval</h3>
                <p>Real-time approval updates.</p>
            </div>

            <div class="feature-card">
                <div class="feature-icon">
                    <i class="fas fa-shield-halved"></i>
                </div>
                <h3>Secure Entry</h3>
                <p>Verified access at the guard point.</p>
            </div>
        </div>

        <div class="entry-strip">
            <div class="strip-icon">
                <i class="fas fa-shield-halved"></i>
            </div>
            <div>
                <strong>Smart Entry System</strong>
                <span>QR Access · Resident Approval · Parking Access</span>
            </div>
        </div>
    </section>

    <section class="login-card">
        <div class="form-head">
            <div class="avatar">
                <i class="fas fa-user"></i>
            </div>

            <div>
                <h2>User Login</h2>
                <p>Visitor & Resident Access Portal</p>
            </div>
        </div>

        <div class="portal-badge">
            <i class="fas fa-user-shield"></i>
            Visitor & Resident Access Portal
        </div>

        <?php if ($error): ?>
            <div class="error"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="" autocomplete="off" id="loginForm" novalidate>
            <?= csrf_field() ?>

            <div class="field">
                <label>Email Address</label>
                <div class="input-wrap">
                    <i class="fas fa-envelope input-icon"></i>
                    <input
                        type="email"
                        name="email"
                        id="emailInput"
                        value="<?= e($_POST['email'] ?? '') ?>"
                        placeholder="visitor@email.com"
                    >
                </div>
                <div class="field-error" id="emailError">
                    <i class="fas fa-circle-exclamation"></i>
                    Please enter your email address.
                </div>
            </div>

            <div class="field">
                <label>Password</label>
                <div class="input-wrap">
                    <i class="fas fa-lock input-icon"></i>
                    <input
                        type="password"
                        name="password"
                        id="passwordInput"
                        placeholder="Enter password"
                    >
                    <button type="button" class="toggle-password" onclick="togglePassword()">
                        <i class="fas fa-eye" id="eyeIcon"></i>
                    </button>
                </div>
                <div class="field-error" id="passwordError">
                    <i class="fas fa-circle-exclamation"></i>
                    Please enter your password.
                </div>
            </div>

            <div class="forgot-link">
                <a href="forgot_password.php">Forgot password?</a>
            </div>

            <button type="submit" class="btn-submit">
                Login to User Portal
            </button>
        </form>

        <div class="links">
            New visitor?
            <a href="register.php">Create Visitor Account</a>
        </div>

        <div class="divider">
            <span>Staff Access</span>
        </div>

        <a href="login.php" class="staff-link">
            <i class="fas fa-users-gear"></i>
            Go to Staff Portal
        </a>
    </section>
</main>

<script>
    function setFieldError(input, errorElement, message) {
        const field = input.closest('.field');

        if (!field) {
            return;
        }

        if (message) {
            field.classList.add('is-invalid');
            errorElement.innerHTML = '<i class="fas fa-circle-exclamation"></i>' + message;
        } else {
            field.classList.remove('is-invalid');
        }
    }

    function validateLoginForm() {
        const emailInput = document.getElementById('emailInput');
        const passwordInput = document.getElementById('passwordInput');
        const emailError = document.getElementById('emailError');
        const passwordError = document.getElementById('passwordError');

        let isValid = true;
        const emailValue = emailInput.value.trim();
        const passwordValue = passwordInput.value.trim();

        if (emailValue === '') {
            setFieldError(emailInput, emailError, 'Please enter your email address.');
            isValid = false;
        } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailValue)) {
            setFieldError(emailInput, emailError, 'Please enter a valid email address.');
            isValid = false;
        } else {
            setFieldError(emailInput, emailError, '');
        }

        if (passwordValue === '') {
            setFieldError(passwordInput, passwordError, 'Please enter your password.');
            isValid = false;
        } else {
            setFieldError(passwordInput, passwordError, '');
        }

        return isValid;
    }

    function togglePassword() {
        const passwordInput = document.getElementById('passwordInput');
        const eyeIcon = document.getElementById('eyeIcon');

        if (passwordInput.type === 'password') {
            passwordInput.type = 'text';
            eyeIcon.classList.remove('fa-eye');
            eyeIcon.classList.add('fa-eye-slash');
        } else {
            passwordInput.type = 'password';
            eyeIcon.classList.remove('fa-eye-slash');
            eyeIcon.classList.add('fa-eye');
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        const loginForm = document.getElementById('loginForm');
        const emailInput = document.getElementById('emailInput');
        const passwordInput = document.getElementById('passwordInput');

        loginForm.addEventListener('submit', function (event) {
            if (!validateLoginForm()) {
                event.preventDefault();
            }
        });

        emailInput.addEventListener('input', validateLoginForm);
        passwordInput.addEventListener('input', validateLoginForm);
    });
</script>

</body>
</html>
