<?php
// 文件路径: public/login.php
require_once '../core/security.php';

$error = '';
$msg = $_GET['msg'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 【安全防御】验证 CSRF Token
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("❌ CSRF Token Validation Failed.");
    }

    $email = trim($_POST['email']);
    $password = $_POST['password'];

    $pdo = db();
    // 使用 PDO 防 SQL 注入
    $stmt = $pdo->prepare("SELECT id, email, password_hash, role, status FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    // 验证密码 Hash
    if ($user && password_verify($password, $user['password_hash'])) {
        // 【异常风控】检查账号状态
        if ($user['status'] !== 'active') {
            $error = "❌ 账号状态为 " . strtoupper($user['status']) . "。请联系管理处。";
            log_audit('LOGIN_BLOCKED', "Status block for email: $email");
        } else {
            // 登录成功！配置 Session
            $_SESSION['uid'] = $user['id'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['last_activity'] = time();
            session_regenerate_id(true); // 防御 Session Fixation 攻击

            log_audit('LOGIN_SUCCESS', "User logged in successfully");

            // 【智能路由】不同角色跳不同页面
            switch ($user['role']) {
                case 'admin':
                case 'superadmin':
                    header("Location: admin_dash.php"); break;
                case 'guard':
                    header("Location: guard_scan.php"); break;
                case 'resident':
                    header("Location: resident.php"); break;
                case 'visitor':
                    header("Location: visitor_book.php"); break;
            }
            exit();
        }
    } else {
        $error = "❌ 邮箱或密码错误。";
        log_audit('LOGIN_FAILED', "Invalid credentials for email: $email");
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Enterprise Login - <?= APP_NAME ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { --primary: #4f46e5; --bg: #f3f4f6; --surface: #ffffff; --text-main: #111827; }
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Plus Jakarta Sans', sans-serif; }
        body { background: var(--bg); display: flex; justify-content: center; align-items: center; min-height: 100vh; }
        .login-card { background: var(--surface); padding: 40px 30px; border-radius: 16px; box-shadow: 0 10px 25px rgba(0,0,0,0.05); width: 100%; max-width: 400px; border: 1px solid #e5e7eb; }
        .logo-text { text-align: center; font-size: 1.5rem; font-weight: 800; color: var(--text-main); margin-bottom: 5px; }
        .logo-text span { color: var(--primary); }
        .sub-text { text-align: center; color: #6b7280; font-size: 0.9rem; margin-bottom: 30px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; font-size: 0.85rem; font-weight: 600; color: #374151; margin-bottom: 8px; }
        input { width: 100%; padding: 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 1rem; transition: 0.2s; background: #f9fafb; }
        input:focus { border-color: var(--primary); outline: none; background: #ffffff; box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1); }
        .btn { width: 100%; padding: 14px; background: var(--primary); color: white; border: none; border-radius: 8px; font-size: 1rem; font-weight: 700; cursor: pointer; transition: 0.2s; margin-top: 10px; }
        .btn:hover { background: #4338ca; transform: translateY(-2px); }
        .alert { padding: 12px; border-radius: 8px; font-size: 0.85rem; margin-bottom: 20px; text-align: center; font-weight: 600; }
        .alert-error { background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; }
        .alert-info { background: #e0f2fe; color: #0369a1; border: 1px solid #7dd3fc; }
    </style>
</head>
<body>

<div class="login-card">
    <div class="logo-text">Smart<span>VMS</span></div>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= $error ?></div>
    <?php endif; ?>
    
    <?php if ($msg === 'timeout'): ?>
        <div class="alert alert-info">⚠️ Session expired. Please log in again.</div>
    <?php endif; ?>

    <form method="POST" action="">
        <?= csrf_field() ?>
        <div class="form-group">
            <label>Email Address</label>
            <input type="email" name="email" placeholder="e.g. visitor@apt.com" required autocomplete="email">
        </div>
        <div class="form-group">
            <label>Password</label>
            <input type="password" name="password" placeholder="••••••••" required>
        </div>
        <button type="submit" class="btn">Secure Login</button>
    </form>

    <div style="text-align: center; margin-top: 20px; font-size: 0.9rem; color: #6b7280;">
        New visitor? <a href="register.php" style="color: #4f46e5; font-weight: 600; text-decoration: none;">Create an account</a>
    </div>
    
</div>

</body>
</html>