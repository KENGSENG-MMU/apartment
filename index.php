<?php
// 文件路径: index.php (根目录)
// 如果用户已经登录了，直接智能跳转到他们的专属控制台
session_start();
if (isset($_SESSION['uid']) && isset($_SESSION['role'])) {
    $role = $_SESSION['role'];
    if ($role === 'visitor') header('Location: public/visitor_book.php');
    elseif ($role === 'resident') header('Location: public/resident.php');
    elseif ($role === 'guard') header('Location: public/guard_scan.php');
    else header('Location: public/admin_dash.php');
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SmartVMS - Next-Gen Visitor Management</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root { --primary: #4f46e5; --bg: #0f172a; --text-light: #f8fafc; --text-muted: #94a3b8; }
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Plus Jakarta Sans', sans-serif; }
        
        /* 酷炫暗黑风 SaaS 首页 */
        body { background: var(--bg); color: var(--text-light); overflow-x: hidden; }
        
        /* 导航栏 */
        .navbar { display: flex; justify-content: space-between; align-items: center; padding: 20px 5%; background: rgba(15, 23, 42, 0.8); backdrop-filter: blur(10px); position: fixed; width: 100%; top: 0; z-index: 1000; border-bottom: 1px solid rgba(255,255,255,0.05); }
        .logo { font-size: 1.5rem; font-weight: 800; color: white; letter-spacing: -0.5px; }
        .logo span { color: var(--primary); }
        .nav-actions a { text-decoration: none; font-weight: 600; font-size: 0.95rem; transition: 0.2s; }
        .btn-login { color: white; margin-right: 20px; }
        .btn-login:hover { color: var(--primary); }
        .btn-register { background: var(--primary); color: white; padding: 10px 20px; border-radius: 50px; }
        .btn-register:hover { background: #4338ca; box-shadow: 0 4px 15px rgba(79, 70, 229, 0.4); }

        /* 英雄区域 (Hero Section) */
        .hero { min-height: 100vh; display: flex; flex-direction: column; justify-content: center; align-items: center; text-align: center; padding: 0 20px; position: relative; overflow: hidden; }
        
        /* 酷炫的背景光晕 */
        .glow-1 { position: absolute; width: 600px; height: 600px; background: radial-gradient(circle, rgba(79,70,229,0.15) 0%, rgba(15,23,42,0) 70%); top: -100px; left: -100px; z-index: -1; }
        .glow-2 { position: absolute; width: 500px; height: 500px; background: radial-gradient(circle, rgba(16,185,129,0.1) 0%, rgba(15,23,42,0) 70%); bottom: -100px; right: -100px; z-index: -1; }

        .tagline { display: inline-block; background: rgba(79, 70, 229, 0.1); color: #818cf8; padding: 6px 15px; border-radius: 20px; font-size: 0.85rem; font-weight: 700; border: 1px solid rgba(79, 70, 229, 0.2); margin-bottom: 25px; letter-spacing: 1px; text-transform: uppercase; }
        .hero h1 { font-size: 4rem; font-weight: 800; line-height: 1.1; margin-bottom: 20px; background: linear-gradient(to right, #ffffff, #94a3b8); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .hero p { font-size: 1.2rem; color: var(--text-muted); max-width: 600px; margin-bottom: 40px; line-height: 1.6; }
        
        .hero-btns { display: flex; gap: 20px; }
        .btn-primary { background: var(--primary); color: white; padding: 16px 35px; border-radius: 50px; text-decoration: none; font-weight: 700; font-size: 1.1rem; display: flex; align-items: center; gap: 10px; transition: 0.2s; box-shadow: 0 10px 25px rgba(79, 70, 229, 0.3); }
        .btn-primary:hover { transform: translateY(-3px); box-shadow: 0 15px 35px rgba(79, 70, 229, 0.4); }
        .btn-secondary { background: rgba(255,255,255,0.05); color: white; padding: 16px 35px; border-radius: 50px; text-decoration: none; font-weight: 700; font-size: 1.1rem; display: flex; align-items: center; gap: 10px; transition: 0.2s; border: 1px solid rgba(255,255,255,0.1); }
        .btn-secondary:hover { background: rgba(255,255,255,0.1); }

        /* 特性网格 */
        .features { padding: 80px 5%; max-width: 1200px; margin: 0 auto; display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 30px; }
        .feature-card { background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.05); padding: 30px; border-radius: 20px; transition: 0.3s; }
        .feature-card:hover { background: rgba(255,255,255,0.04); border-color: rgba(79, 70, 229, 0.3); transform: translateY(-5px); }
        .f-icon { width: 50px; height: 50px; background: rgba(79, 70, 229, 0.1); color: #818cf8; border-radius: 12px; display: flex; justify-content: center; align-items: center; font-size: 1.5rem; margin-bottom: 20px; }
        .feature-card h3 { font-size: 1.2rem; margin-bottom: 10px; font-weight: 700; }
        .feature-card p { color: var(--text-muted); font-size: 0.95rem; line-height: 1.5; }

        @media (max-width: 768px) {
            .hero h1 { font-size: 2.5rem; }
            .hero-btns { flex-direction: column; width: 100%; max-width: 300px; }
            .navbar { padding: 15px 20px; }
            .btn-login { display: none; }
        }
    </style>
</head>
<body>

    <nav class="navbar">
        <div class="logo">Smart<span>VMS</span></div>
        <div class="nav-actions">
            <a href="public/login.php" class="btn-login">Sign In</a>
            <a href="public/register.php" class="btn-register">Visitor Registration</a>
        </div>
    </nav>

    <div class="hero">
        <div class="glow-1"></div>
        <div class="glow-2"></div>
        
        <div class="tagline">Final Year Project 2026</div>
        <h1>Next-Generation<br>Visitor Management</h1>
        <p>A completely automated, highly secure, and seamless way to manage apartment visitors. Powered by AI License Plate Recognition and Smart Parking Allocation.</p>
        
        <div class="hero-btns">
            <a href="public/login.php" class="btn-primary">
                Enter System <i class="fas fa-arrow-right"></i>
            </a>
            <a href="public/register.php" class="btn-secondary">
                Book a Visit <i class="fas fa-ticket-alt"></i>
            </a>
        </div>
    </div>

    <div class="features">
        <div class="feature-card">
            <div class="f-icon"><i class="fas fa-camera"></i></div>
            <h3>AI Plate Recognition</h3>
            <p>Guards can seamlessly scan vehicle plates using mobile cameras with integrated OCR and confidence fallbacks.</p>
        </div>
        <div class="feature-card">
            <div class="f-icon"><i class="fas fa-parking"></i></div>
            <h3>Auto Resource Allocation</h3>
            <p>Automatically assigns optimal visitor parking slots and reclaims them immediately upon vehicle exit.</p>
        </div>
        <div class="feature-card">
            <div class="f-icon"><i class="fas fa-shield-alt"></i></div>
            <h3>Strict Risk Control</h3>
            <p>Enterprise-grade anti-passback logic, dynamic blacklists, and grace-period validations protect the premises.</p>
        </div>
        <div class="feature-card">
            <div class="f-icon"><i class="fas fa-file-shield"></i></div>
            <h3>PDPA Compliant</h3>
            <p>Comprehensive audit logs, sensitive data masking, and role-based access control (RBAC) built natively into the core.</p>
        </div>
    </div>

</body>
</html>