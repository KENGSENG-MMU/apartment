<?php
require_once '../core/security.php';

$pdo = db();

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function has_column_staff_login(PDO $pdo, string $table, string $column): bool {
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

function staff_next_page(string $role): string {
    return match ($role) {
        'admin', 'superadmin' => 'admin_dash.php',
        'guard' => 'guard_scan.php',
        default => 'staff_login.php'
    };
}

function redirect_staff_role(string $role): void {
    if (!in_array($role, ['admin', 'superadmin', 'guard'], true)) {
        header("Location: r_landingpage.php");
        exit;
    }

    $_SESSION['login_next_url'] = staff_next_page($role);
    header("Location: login_loading.php");
    exit;
}

if (isset($_SESSION['uid'], $_SESSION['role'])) {
    redirect_staff_role($_SESSION['role']);
}

$error = '';

$emailColumn = has_column_staff_login($pdo, 'users', 'email') ? 'email' : 'username';
$passwordColumn = has_column_staff_login($pdo, 'users', 'password_hash') ? 'password_hash' : 'password';
$hasStatus = has_column_staff_login($pdo, 'users', 'status');
$hasApartmentId = has_column_staff_login($pdo, 'users', 'apartment_id');

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
                        log_audit('STAFF_LOGIN_FAILED', 'Failed staff portal login attempt: ' . $email);
                    }

                    $error = 'Invalid email or password.';
                } else {
                    $role = $user['role'] ?? '';

                    if (!in_array($role, ['admin', 'superadmin', 'guard'], true)) {
                        if (function_exists('log_audit')) {
                            log_audit('WRONG_PORTAL_LOGIN', 'User tried to login from staff portal: ' . $email);
                        }

                        $error = 'This portal is only for admin, superadmin, and guard. Please use User Portal.';
                    } elseif ($hasStatus && isset($user['status']) && $user['status'] !== 'active') {
                        if (function_exists('log_audit')) {
                            log_audit('STAFF_LOGIN_BLOCKED', 'Inactive staff account login attempt: ' . $email);
                        }

                        $error = 'Your staff account is inactive. Please contact admin.';
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
                                log_audit('STAFF_LOGIN_FAILED', 'Failed staff portal login attempt: ' . $email);
                            }

                            $error = 'Invalid email or password.';
                        } else {
                            session_regenerate_id(true);

                            $_SESSION['uid'] = (int)$user['id'];
                            $_SESSION['role'] = $role;
                            $_SESSION['email'] = $user[$emailColumn];
                            $_SESSION['apartment_id'] = ($hasApartmentId && isset($user['apartment_id']))
                                ? $user['apartment_id']
                                : null;

                            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                            if (function_exists('log_audit')) {
                                log_audit('STAFF_LOGIN_SUCCESS', 'Staff logged in from staff portal');
                            }

                            redirect_staff_role($role);
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
    <title>Staff Login - <?= e(APP_NAME) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

    <style>
        * {
            box-sizing: border-box;
            font-family: 'Plus Jakarta Sans', sans-serif;
            margin: 0;
            padding: 0;
        }

        body {
            min-height: 100vh;
            background:
                linear-gradient(rgba(0,0,0,.68), rgba(0,0,0,.75)),
                url('a.jpg') center center / cover no-repeat;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }

        .login-card {
            width: 100%;
            max-width: 420px;
            background: rgba(255,255,255,.96);
            border-radius: 24px;
            padding: 34px;
            box-shadow: 0 24px 60px rgba(0,0,0,.35);
            border-top: 6px solid #d32f2f;
        }

        .brand {
            text-align: center;
            font-size: 1.8rem;
            font-weight: 900;
            color: #111827;
            margin-bottom: 8px;
        }

        .brand span {
            color: #d32f2f;
        }

        .subtitle {
            text-align: center;
            color: #6b7280;
            font-size: .9rem;
            font-weight: 700;
            margin-bottom: 24px;
        }

        .portal-badge {
            background: #fee2e2;
            color: #991b1b;
            padding: 10px 14px;
            border-radius: 14px;
            text-align: center;
            font-size: .85rem;
            font-weight: 900;
            margin-bottom: 20px;
        }

        .field {
            margin-bottom: 17px;
        }

        label {
            display: block;
            font-size: .84rem;
            font-weight: 800;
            color: #374151;
            margin-bottom: 8px;
        }

        input {
            width: 100%;
            padding: 14px 15px;
            border: 1px solid #d1d5db;
            border-radius: 14px;
            font-size: .95rem;
            outline: none;
            font-weight: 700;
            background: #f9fafb;
        }

        input:focus {
            border-color: #d32f2f;
            background: white;
            box-shadow: 0 0 0 4px rgba(211,47,47,.12);
        }

        .btn {
            width: 100%;
            margin-top: 10px;
            padding: 15px;
            border: none;
            border-radius: 14px;
            background: linear-gradient(135deg, #d32f2f, #9f1239);
            color: white;
            font-size: .98rem;
            font-weight: 900;
            cursor: pointer;
            box-shadow: 0 16px 28px rgba(211,47,47,.25);
        }

        .error {
            background: #fee2e2;
            border: 1px solid #fca5a5;
            color: #991b1b;
            padding: 13px 14px;
            border-radius: 14px;
            font-size: .86rem;
            font-weight: 800;
            margin-bottom: 18px;
        }

        .links {
            text-align: center;
            margin-top: 22px;
            color: #6b7280;
            font-size: .88rem;
            font-weight: 700;
            line-height: 1.8;
        }

        .links a {
            color: #d32f2f;
            text-decoration: none;
            font-weight: 900;
        }
    </style>
</head>
<body>

<div class="login-card">
    <div class="brand">Smart<span>VMS</span></div>
    <div class="subtitle">Admin & Guard Staff Portal</div>

    <div class="portal-badge">
        Restricted access for Admin, Superadmin and Guard only
    </div>

    <?php if ($error): ?>
        <div class="error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="">
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">

        <div class="field">
            <label>Staff Email</label>
            <input type="email" name="email" value="<?= e($_POST['email'] ?? '') ?>" placeholder="admin@apt.com" required>
        </div>

        <div class="field">
            <label>Password</label>
            <input type="password" name="password" placeholder="Enter password" required>
        </div>

        <button type="submit" class="btn">Login to Staff Portal</button>
    </form>

    <div class="links">
        Visitor or Resident? <a href="r_landingpage.php">Go to User Portal</a>
    </div>
</div>

</body>
</html>