<?php
// 文件路径: public/register.php
require_once '../core/security.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 【安全】CSRF 验证
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("❌ CSRF Token Validation Failed.");
    }

    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    // 基本验证
    if ($password !== $confirm_password) {
        $error = "❌ Passwords do not match.";
    } elseif (strlen($password) < 6) {
        $error = "❌ Password must be at least 6 characters.";
    } else {
        $pdo = db();
        // 检查邮箱是否已存在
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = "❌ Email is already registered. Please log in.";
        } else {
            // 【安全】密码哈希加密
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            
            // 插入新用户，角色固定为 'visitor'
            $insert = $pdo->prepare("INSERT INTO users (email, password_hash, role, phone, status) VALUES (?, ?, 'visitor', ?, 'active')");
            if ($insert->execute([$email, $hashed_password, $phone])) {
                $success = "✅ Account created successfully! You can now log in.";
                log_audit('USER_REGISTERED', "New visitor registered: $email");
            } else {
                $error = "❌ Registration failed. Please try again.";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Visitor Registration - <?= APP_NAME ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { --primary: #4f46e5; --bg: #f3f4f6; --surface: #ffffff; --text-main: #111827; }
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Plus Jakarta Sans', sans-serif; }
        body { background: var(--bg); display: flex; justify-content: center; align-items: center; min-height: 100vh; padding: 20px; }
        .reg-card { background: var(--surface); padding: 40px 30px; border-radius: 16px; box-shadow: 0 10px 25px rgba(0,0,0,0.05); width: 100%; max-width: 450px; border: 1px solid #e5e7eb; }
        .logo-text { text-align: center; font-size: 1.5rem; font-weight: 800; color: var(--text-main); margin-bottom: 5px; }
        .logo-text span { color: var(--primary); }
        .sub-text { text-align: center; color: #6b7280; font-size: 0.9rem; margin-bottom: 30px; }
        .form-group { margin-bottom: 18px; }
        label { display: block; font-size: 0.85rem; font-weight: 600; color: #374151; margin-bottom: 8px; }
        input { width: 100%; padding: 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 1rem; transition: 0.2s; background: #f9fafb; }
        input:focus { border-color: var(--primary); outline: none; background: #ffffff; box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1); }
        .btn { width: 100%; padding: 14px; background: var(--primary); color: white; border: none; border-radius: 8px; font-size: 1rem; font-weight: 700; cursor: pointer; transition: 0.2s; margin-top: 10px; }
        .btn:hover { background: #4338ca; transform: translateY(-2px); }
        .alert { padding: 12px; border-radius: 8px; font-size: 0.85rem; margin-bottom: 20px; text-align: center; font-weight: 600; }
        .alert-error { background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; }
        .alert-success { background: #dcfce7; color: #15803d; border: 1px solid #86efac; }
        .login-link { text-align: center; margin-top: 20px; font-size: 0.9rem; color: #6b7280; }
        .login-link a { color: var(--primary); font-weight: 600; text-decoration: none; }
        .login-link a:hover { text-decoration: underline; }
    </style>
</head>
<body>

<div class="reg-card">
    <div class="logo-text">Smart<span>VMS</span></div>
    <div class="sub-text">Create your Visitor Account</div>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= $error ?></div>
    <?php endif; ?>
    
    <?php if ($success): ?>
        <div class="alert alert-success"><?= $success ?></div>
    <?php endif; ?>

    <form method="POST" action="">
        <?= csrf_field() ?>
        <div class="form-group">
            <label>Email Address</label>
            <input type="email" name="email" placeholder="e.g. john@example.com" required>
        </div>
        <div class="form-group">
            <label>Phone Number</label>
            <input type="text" name="phone" placeholder="e.g. 0123456789" required>
        </div>
        <div style="display: flex; gap: 15px;">
            <div class="form-group" style="flex: 1;">
                <label>Password</label>
                <input type="password" name="password" placeholder="••••••••" required>
            </div>
            <div class="form-group" style="flex: 1;">
                <label>Confirm</label>
                <input type="password" name="confirm_password" placeholder="••••••••" required>
            </div>
        </div>
        <button type="submit" class="btn">Sign Up</button>
    </form>

    <div class="login-link">
        Already have an account? <a href="login.php">Log in here</a>
    </div>
</div>

</body>
</html>