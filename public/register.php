<?php
require_once '../core/security.php';

$pdo = db();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
$error = '';

function register_has_column(PDO $pdo, string $table, string $column): bool {
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

function register_table_exists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
        ");
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function register_clean_phone(string $phone): string {
    return trim(preg_replace('/[^0-9+\-\s]/', '', $phone));
}

function register_password_error(string $password): string {
    if (strlen($password) < 8) {
        return 'Password must be at least 8 characters long.';
    }

    if (!preg_match('/[A-Z]/', $password)) {
        return 'Password must contain at least one uppercase letter.';
    }

    if (!preg_match('/[a-z]/', $password)) {
        return 'Password must contain at least one lowercase letter.';
    }

    if (!preg_match('/[0-9]/', $password)) {
        return 'Password must contain at least one number.';
    }

    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        return 'Password must contain at least one special symbol.';
    }

    return '';
}

$hasFullName = register_has_column($pdo, 'users', 'full_name');
$hasFirstName = register_has_column($pdo, 'users', 'first_name');
$hasLastName = register_has_column($pdo, 'users', 'last_name');
$hasContactNumber = register_has_column($pdo, 'users', 'contact_number');
$hasPhone = register_has_column($pdo, 'users', 'phone');
$hasStatus = register_has_column($pdo, 'users', 'status');
$hasMustChange = register_has_column($pdo, 'users', 'must_change_password');
$hasCreatedAt = register_has_column($pdo, 'users', 'created_at');

$passwordColumn = null;

if (register_has_column($pdo, 'users', 'password_hash')) {
    $passwordColumn = 'password_hash';
} elseif (register_has_column($pdo, 'users', 'password')) {
    $passwordColumn = 'password';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';

    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
        $error = 'Invalid security token. Please refresh the page.';
    } else {
        try {
            if (!$passwordColumn) {
                throw new Exception('Users table does not have password_hash or password column.');
            }

            $fullName = trim($_POST['full_name'] ?? '');
            $email = strtolower(trim($_POST['email'] ?? ''));
            $phone = register_clean_phone($_POST['phone'] ?? '');
            $password = $_POST['password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';

            if ($fullName === '') {
                throw new Exception('Please enter your full name.');
            }

            if ($email === '') {
                throw new Exception('Please enter your email address.');
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Please enter a valid email address.');
            }

            if ($phone === '') {
                throw new Exception('Please enter your phone number.');
            }

            if (strlen(preg_replace('/\D/', '', $phone)) < 9) {
                throw new Exception('Please enter a valid phone number.');
            }

            $passwordError = register_password_error($password);

            if ($passwordError !== '') {
                throw new Exception($passwordError);
            }

            if ($password !== $confirmPassword) {
                throw new Exception('Confirm password does not match.');
            }

            $stmt = $pdo->prepare("
                SELECT id, role
                FROM users
                WHERE email = ?
                LIMIT 1
            ");
            $stmt->execute([$email]);
            $existing = $stmt->fetch();

            if ($existing) {
                throw new Exception('This email is already registered. Please login instead.');
            }

            $columns = ['email', $passwordColumn, 'role'];
            $marks = ['?', '?', '?'];
            $values = [
                $email,
                password_hash($password, PASSWORD_DEFAULT),
                'visitor'
            ];

            if ($hasFullName) {
                $columns[] = 'full_name';
                $marks[] = '?';
                $values[] = $fullName;
            }

            if ($hasFirstName || $hasLastName) {
                $nameParts = preg_split('/\s+/', $fullName, 2);
                $firstName = $nameParts[0] ?? $fullName;
                $lastName = $nameParts[1] ?? '';

                if ($hasFirstName) {
                    $columns[] = 'first_name';
                    $marks[] = '?';
                    $values[] = $firstName;
                }

                if ($hasLastName) {
                    $columns[] = 'last_name';
                    $marks[] = '?';
                    $values[] = $lastName;
                }
            }

            if ($hasContactNumber) {
                $columns[] = 'contact_number';
                $marks[] = '?';
                $values[] = $phone;
            }

            if ($hasPhone) {
                $columns[] = 'phone';
                $marks[] = '?';
                $values[] = $phone;
            }

            if ($hasStatus) {
                $columns[] = 'status';
                $marks[] = '?';
                $values[] = 'active';
            }

            if ($hasMustChange) {
                $columns[] = 'must_change_password';
                $marks[] = '?';
                $values[] = 0;
            }

            if ($hasCreatedAt) {
                $columns[] = 'created_at';
                $marks[] = 'NOW()';
            }

            $stmt = $pdo->prepare("
                INSERT INTO users
                (" . implode(', ', $columns) . ")
                VALUES
                (" . implode(', ', $marks) . ")
            ");
            $stmt->execute($values);

            $message = 'Visitor account created successfully. You can login now.';

        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Visitor Register - SmartVMS</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

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
            --success: #16a34a;
            --successBg: #ecfdf3;
            --successLine: #bbf7d0;
            --card: rgba(255,255,255,.88);
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
                    rgba(248,252,255,.60) 42%,
                    rgba(225,240,255,.42) 100%
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
                radial-gradient(circle at 9% 28%, rgba(37, 99, 235, .08), transparent 22%),
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
            padding: 76px 0;
            display: grid;
            grid-template-columns: minmax(0, 1fr) 460px;
            align-items: center;
            gap: 56px;
        }

        .hero {
            position: relative;
            min-height: 700px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 48px 0 48px 12px;
        }

        .brand {
            display: inline-flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 46px;
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
            text-shadow: 0 1px 18px rgba(255,255,255,.75);
        }

        .brand-name span {
            color: var(--blue);
        }

        .hero h1 {
            max-width: 710px;
            font-size: clamp(3.8rem, 5.7vw, 6.15rem);
            line-height: .96;
            letter-spacing: -.064em;
            font-weight: 900;
            color: #08111f;
            margin-bottom: 18px;
            text-wrap: balance;
            text-shadow: 0 1px 18px rgba(255,255,255,.75);
        }

        .hero .subtitle {
            color: #5d6b83;
            font-size: clamp(1.28rem, 1.65vw, 1.78rem);
            line-height: 1.3;
            font-weight: 650;
            margin-bottom: 28px;
            letter-spacing: -.033em;
            text-shadow: 0 1px 18px rgba(255,255,255,.75);
        }

        .hero .description {
            max-width: 530px;
            color: #5b6880;
            font-size: 1.12rem;
            line-height: 1.68;
            font-weight: 580;
            margin-bottom: 40px;
            text-shadow: 0 1px 18px rgba(255,255,255,.75);
        }

        .feature-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(138px, 176px));
            gap: 18px;
            margin-bottom: 44px;
        }

        .feature-card {
            min-height: 164px;
            padding: 22px 18px;
            border-radius: 22px;
            background: rgba(255,255,255,.78);
            border: 1px solid rgba(219,229,240,.86);
            box-shadow: var(--shadowSoft);
            backdrop-filter: blur(18px) saturate(1.12);
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
            font-weight: 580;
        }

        .entry-strip {
            width: min(100%, 510px);
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 16px 18px;
            border-radius: 20px;
            background: rgba(255,255,255,.78);
            border: 1px solid rgba(219,229,240,.86);
            box-shadow: var(--shadowSoft);
            backdrop-filter: blur(18px) saturate(1.12);
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
            font-weight: 580;
        }

        .form-card {
            position: relative;
            overflow: hidden;
            padding: 42px 34px 34px;
            border-radius: 28px;
            background: rgba(255,255,255,.90);
            border: 1px solid rgba(255,255,255,.92);
            box-shadow: var(--shadow);
            backdrop-filter: blur(22px) saturate(1.1);
        }

        .form-card::before {
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

        .form-card::after {
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
        .alert,
        form,
        .bottom-text {
            position: relative;
            z-index: 2;
        }

        .form-head {
            display: grid;
            grid-template-columns: 68px 1fr;
            gap: 18px;
            align-items: center;
            margin-bottom: 30px;
        }

        .avatar {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            background: #eaf3ff;
            color: var(--blue);
            font-size: 1.55rem;
            box-shadow: 0 14px 30px rgba(37,99,235,.13);
        }

        .form-head h2 {
            font-size: 1.9rem;
            line-height: 1.08;
            letter-spacing: -.05em;
            font-weight: 900;
            margin-bottom: 6px;
        }

        .form-head p {
            color: #64748b;
            font-size: .98rem;
            font-weight: 600;
            line-height: 1.45;
        }

        .alert {
            padding: 13px 14px;
            border-radius: 16px;
            margin-bottom: 18px;
            font-weight: 780;
            font-size: .86rem;
            line-height: 1.45;
        }

        .alert.success {
            background: var(--successBg);
            color: #166534;
            border: 1px solid var(--successLine);
        }

        .alert.error {
            background: var(--dangerBg);
            color: #991b1b;
            border: 1px solid var(--dangerLine);
        }

        .field {
            margin-bottom: 17px;
        }

        label {
            display: block;
            color: #64748b;
            font-size: .72rem;
            font-weight: 900;
            letter-spacing: .09em;
            text-transform: uppercase;
            margin-bottom: 8px;
        }

        .input-wrap {
            position: relative;
        }

        input {
            width: 100%;
            height: 54px;
            padding: 0 48px 0 50px;
            border: 1px solid #d7e3f1;
            border-radius: 15px;
            background: rgba(255,255,255,.86);
            color: var(--ink);
            outline: none;
            font-size: .94rem;
            font-weight: 700;
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
            font-size: 1rem;
        }

        .input-icon {
            left: 18px;
        }

        .toggle-password {
            right: 16px;
            border: none;
            background: transparent;
            cursor: pointer;
        }

        .password-hint {
            margin-top: 8px;
            color: #64748b;
            font-size: .75rem;
            line-height: 1.45;
            font-weight: 660;
        }

        .field-error {
            display: none;
            margin-top: 8px;
            color: var(--danger);
            font-size: .78rem;
            font-weight: 780;
            line-height: 1.4;
        }

        .input-wrap.invalid input {
            border-color: #ef4444;
            background: #fffafa;
            box-shadow: 0 0 0 5px rgba(239, 68, 68, .08);
        }

        .input-wrap.valid input {
            border-color: #22c55e;
            background: #ffffff;
            box-shadow: 0 0 0 5px rgba(34, 197, 94, .08);
        }

        .input-wrap.valid .input-icon,
        .input-wrap.valid .toggle-password {
            color: var(--success);
        }

        .input-wrap.invalid .input-icon,
        .input-wrap.invalid .toggle-password {
            color: var(--danger);
        }

        .btn-submit {
            width: 100%;
            height: 58px;
            border: none;
            border-radius: 15px;
            color: #fff;
            background: linear-gradient(135deg, var(--blue2), var(--blue) 62%, #1d4ed8);
            box-shadow: 0 20px 40px rgba(37,99,235,.25);
            font-size: .97rem;
            font-weight: 900;
            cursor: pointer;
            transition: .24s ease;
            margin-top: 4px;
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 26px 52px rgba(37,99,235,.30);
        }

        .bottom-text {
            text-align: center;
            margin-top: 22px;
            color: #64748b;
            font-size: .88rem;
            font-weight: 650;
        }

        .bottom-text a {
            color: var(--blue);
            text-decoration: none;
            font-weight: 900;
        }

        .bottom-text a:hover {
            text-decoration: underline;
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

            .form-card {
                width: min(100%, 480px);
                margin: 0 auto;
            }
        }

        @media (max-width: 720px) {
            .page {
                width: calc(100% - 30px);
                padding: 34px 0;
            }

            .hero h1 {
                font-size: 3.2rem;
                letter-spacing: -.055em;
            }

            .feature-grid {
                grid-template-columns: 1fr;
            }

            .form-card {
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
</head>
<body>

<a href="user_login.php" class="back-link">
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

        <h1>Create visitor account.</h1>

        <div class="subtitle">
            Start your SmartVMS access here.
        </div>

        <p class="description">
            Register your visitor account to book visits, receive resident approval updates and access your QR visitor pass.
        </p>

        <div class="feature-grid">
            <div class="feature-card">
                <div class="feature-icon">
                    <i class="fas fa-qrcode"></i>
                </div>
                <h3>QR Pass</h3>
                <p>Receive your digital visitor pass after approval.</p>
            </div>

            <div class="feature-card">
                <div class="feature-icon">
                    <i class="fas fa-bell"></i>
                </div>
                <h3>Approval Update</h3>
                <p>Track your request status from the system.</p>
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
                <i class="fas fa-user-check"></i>
            </div>
            <div>
                <strong>Visitor Access Setup</strong>
                <span>Account Registration · Booking Request · QR Access</span>
            </div>
        </div>
    </section>

    <section class="form-card">
        <div class="form-head">
            <div class="avatar">
                <i class="fas fa-user-plus"></i>
            </div>

            <div>
                <h2>Visitor Register</h2>
                <p>Create an account before booking your visit.</p>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert success"><?= e($message) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert error"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="POST" autocomplete="off" id="registerForm" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">

            <div class="field">
                <label>Full Name</label>
                <div class="input-wrap">
                    <i class="fas fa-user input-icon"></i>
                    <input
                        type="text"
                        name="full_name"
                        id="fullName"
                        placeholder="Example: Tan Wei Ming"
                        value="<?= e($_POST['full_name'] ?? '') ?>"
                    >
                </div>
                <div class="field-error" id="fullNameError"></div>
            </div>

            <div class="field">
                <label>Email Address</label>
                <div class="input-wrap">
                    <i class="fas fa-envelope input-icon"></i>
                    <input
                        type="email"
                        name="email"
                        id="email"
                        placeholder="visitor@email.com"
                        value="<?= e($_POST['email'] ?? '') ?>"
                    >
                </div>
                <div class="field-error" id="emailError"></div>
            </div>

            <div class="field">
                <label>Phone Number</label>
                <div class="input-wrap">
                    <i class="fas fa-phone input-icon"></i>
                    <input
                        type="text"
                        name="phone"
                        id="phone"
                        placeholder="Example: 0123456789"
                        value="<?= e($_POST['phone'] ?? '') ?>"
                    >
                </div>
                <div class="field-error" id="phoneError"></div>
            </div>

            <div class="field">
                <label>Password</label>
                <div class="input-wrap">
                    <i class="fas fa-lock input-icon"></i>
                    <input
                        type="password"
                        name="password"
                        id="password"
                        placeholder="Example: Visitor@123"
                    >
                    <button type="button" class="toggle-password" onclick="togglePassword('password', 'passwordEye')">
                        <i class="fas fa-eye" id="passwordEye"></i>
                    </button>
                </div>

                <div class="password-hint">
                    Minimum 8 characters with uppercase, lowercase, number and symbol.
                </div>

                <div class="field-error" id="passwordError"></div>
            </div>

            <div class="field">
                <label>Confirm Password</label>
                <div class="input-wrap">
                    <i class="fas fa-shield-halved input-icon"></i>
                    <input
                        type="password"
                        name="confirm_password"
                        id="confirmPassword"
                        placeholder="Repeat your password"
                    >
                    <button type="button" class="toggle-password" onclick="togglePassword('confirmPassword', 'confirmEye')">
                        <i class="fas fa-eye" id="confirmEye"></i>
                    </button>
                </div>
                <div class="field-error" id="confirmPasswordError"></div>
            </div>

            <button type="submit" class="btn-submit">
                Create Visitor Account
            </button>
        </form>

        <div class="bottom-text">
            Already have an account?
            <a href="user_login.php">Login here</a>
        </div>
    </section>
</main>

<script>
    const form = document.getElementById('registerForm');

    const fullName = document.getElementById('fullName');
    const email = document.getElementById('email');
    const phone = document.getElementById('phone');
    const password = document.getElementById('password');
    const confirmPassword = document.getElementById('confirmPassword');

    function togglePassword(inputId, iconId) {
        const input = document.getElementById(inputId);
        const icon = document.getElementById(iconId);

        if (input.type === 'password') {
            input.type = 'text';
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        } else {
            input.type = 'password';
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        }
    }

    function showError(input, errorId, message) {
        const wrap = input.closest('.input-wrap');
        const errorBox = document.getElementById(errorId);

        wrap.classList.remove('valid');
        wrap.classList.add('invalid');

        errorBox.textContent = message;
        errorBox.style.display = 'block';

        return false;
    }

    function showValid(input, errorId) {
        const wrap = input.closest('.input-wrap');
        const errorBox = document.getElementById(errorId);

        wrap.classList.remove('invalid');
        wrap.classList.add('valid');

        errorBox.textContent = '';
        errorBox.style.display = 'none';

        return true;
    }

    function validateFullName() {
        const value = fullName.value.trim();

        if (value === '') {
            return showError(fullName, 'fullNameError', 'Full name is required.');
        }

        if (value.length < 3) {
            return showError(fullName, 'fullNameError', 'Full name must be at least 3 characters.');
        }

        if (!/^[A-Za-z\s.'-]+$/.test(value)) {
            return showError(fullName, 'fullNameError', 'Full name can only contain letters, spaces, dot, dash and apostrophe.');
        }

        return showValid(fullName, 'fullNameError');
    }

    function validateEmail() {
        const value = email.value.trim();

        if (value === '') {
            return showError(email, 'emailError', 'Email address is required.');
        }

        const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;

        if (!emailPattern.test(value)) {
            return showError(email, 'emailError', 'Please enter a valid email format. Example: visitor@email.com');
        }

        return showValid(email, 'emailError');
    }

    function validatePhone() {
        const value = phone.value.trim();
        const digitsOnly = value.replace(/\D/g, '');

        if (value === '') {
            return showError(phone, 'phoneError', 'Phone number is required.');
        }

        if (!/^[0-9+\-\s]+$/.test(value)) {
            return showError(phone, 'phoneError', 'Phone number can only contain numbers, +, - and spaces.');
        }

        if (digitsOnly.length < 9 || digitsOnly.length > 15) {
            return showError(phone, 'phoneError', 'Phone number must be between 9 to 15 digits.');
        }

        return showValid(phone, 'phoneError');
    }

    function validatePassword() {
        const value = password.value;

        if (value === '') {
            return showError(password, 'passwordError', 'Password is required.');
        }

        if (value.length < 8) {
            return showError(password, 'passwordError', 'Password must be at least 8 characters.');
        }

        if (!/[A-Z]/.test(value)) {
            return showError(password, 'passwordError', 'Password must contain at least one uppercase letter.');
        }

        if (!/[a-z]/.test(value)) {
            return showError(password, 'passwordError', 'Password must contain at least one lowercase letter.');
        }

        if (!/[0-9]/.test(value)) {
            return showError(password, 'passwordError', 'Password must contain at least one number.');
        }

        if (!/[^A-Za-z0-9]/.test(value)) {
            return showError(password, 'passwordError', 'Password must contain at least one special symbol.');
        }

        return showValid(password, 'passwordError');
    }

    function validateConfirmPassword() {
        const value = confirmPassword.value;

        if (value === '') {
            return showError(confirmPassword, 'confirmPasswordError', 'Please confirm your password.');
        }

        if (value !== password.value) {
            return showError(confirmPassword, 'confirmPasswordError', 'Confirm password does not match.');
        }

        return showValid(confirmPassword, 'confirmPasswordError');
    }

    fullName.addEventListener('input', validateFullName);
    email.addEventListener('input', validateEmail);
    phone.addEventListener('input', validatePhone);

    password.addEventListener('input', function () {
        validatePassword();

        if (confirmPassword.value !== '') {
            validateConfirmPassword();
        }
    });

    confirmPassword.addEventListener('input', validateConfirmPassword);

    form.addEventListener('submit', function (e) {
        const isFullNameValid = validateFullName();
        const isEmailValid = validateEmail();
        const isPhoneValid = validatePhone();
        const isPasswordValid = validatePassword();
        const isConfirmPasswordValid = validateConfirmPassword();

        if (
            !isFullNameValid ||
            !isEmailValid ||
            !isPhoneValid ||
            !isPasswordValid ||
            !isConfirmPasswordValid
        ) {
            e.preventDefault();

            Swal.fire({
                icon: 'error',
                title: 'Invalid Input',
                text: 'Please check your registration details before submitting.',
                confirmButtonColor: '#2563eb'
            });
        }
    });
</script>

<?php if ($message): ?>
<script>
Swal.fire({
    icon: 'success',
    title: 'Account Created',
    text: <?= json_encode($message) ?>,
    confirmButtonColor: '#2563eb'
}).then(() => {
    window.location.href = 'user_login.php';
});
</script>
<?php endif; ?>

<?php if ($error): ?>
<script>
Swal.fire({
    icon: 'error',
    title: 'Registration Failed',
    text: <?= json_encode($error) ?>,
    confirmButtonColor: '#2563eb'
});
</script>
<?php endif; ?>

</body>
</html>