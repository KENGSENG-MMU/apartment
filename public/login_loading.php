<?php
require_once '../core/security.php';

require_login(['superadmin', 'admin', 'guard']);

$pdo = db();

$currentRole = $_SESSION['role'] ?? '';
$currentEmail = $_SESSION['email'] ?? '';
$currentApartmentId = $_SESSION['apartment_id'] ?? null;

$apartmentName = 'SmartVMS';
$apartmentSubtitle = 'Preparing your workspace...';

if ($currentRole === 'superadmin') {
    $apartmentName = 'SmartVMS Superadmin';
    $apartmentSubtitle = 'Loading platform control center...';
} elseif (!empty($currentApartmentId)) {
    try {
        $stmt = $pdo->prepare("
            SELECT apartment_name
            FROM apartments
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([(int)$currentApartmentId]);
        $apartment = $stmt->fetch();

        if ($apartment && !empty($apartment['apartment_name'])) {
            $apartmentName = $apartment['apartment_name'];
            $apartmentSubtitle = $currentRole === 'guard'
                ? 'Loading gate security console...'
                : 'Loading apartment admin dashboard...';
        }
    } catch (Throwable $e) {
        $apartmentName = 'Apartment ID ' . (int)$currentApartmentId;
        $apartmentSubtitle = $currentRole === 'guard'
            ? 'Loading gate security console...'
            : 'Loading apartment admin dashboard...';
    }
} else {
    $apartmentName = 'No Apartment Assigned';
    $apartmentSubtitle = 'Please contact superadmin if this is unexpected.';
}

$roleLabel = match ($currentRole) {
    'superadmin' => 'Superadmin',
    'admin' => 'Admin',
    'guard' => 'Guard',
    default => 'Staff'
};

$welcomeText = match ($currentRole) {
    'superadmin' => 'Welcome, Superadmin',
    'admin' => 'Welcome, Admin',
    'guard' => 'Welcome, Guard',
    default => 'Welcome'
};

$loadingText = match ($currentRole) {
    'superadmin' => 'Loading platform control center',
    'admin' => 'Loading apartment admin dashboard',
    'guard' => 'Loading gate security console',
    default => 'Loading staff workspace'
};

$theme = match ($currentRole) {
    'guard' => [
        'primary' => '#16a34a',
        'primaryDark' => '#166534',
        'primarySoft' => '#dcfce7',
        'primaryLight' => '#22c55e',
        'bg1' => 'rgba(22, 163, 74, .30)',
        'bg2' => 'rgba(20, 83, 45, .22)',
        'cardBorder' => '#bbf7d0',
        'textDark' => '#14532d',
        'icon' => 'fa-shield-halved'
    ],
    default => [
        'primary' => '#dc2626',
        'primaryDark' => '#991b1b',
        'primarySoft' => '#fee2e2',
        'primaryLight' => '#ef4444',
        'bg1' => 'rgba(220, 38, 38, .32)',
        'bg2' => 'rgba(153, 27, 27, .22)',
        'cardBorder' => '#fecaca',
        'textDark' => '#991b1b',
        'icon' => 'fa-building-shield'
    ]
};

$nextUrl = $_SESSION['login_next_url'] ?? null;

if (!$nextUrl) {
    $nextUrl = match ($currentRole) {
        'superadmin' => 'superadmin_dash.php',
        'admin' => 'admin_dashboard.php',
        'guard' => 'guard_scan.php',
        default => 'staff_login.php'
    };
}

if (preg_match('/^(https?:)?\/\//i', $nextUrl) || str_contains($nextUrl, '..')) {
    $nextUrl = 'staff_login.php';
}

unset($_SESSION['login_next_url']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Loading - <?= e(APP_NAME) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <style>
        :root {
            --primary: <?= e($theme['primary']) ?>;
            --primary-dark: <?= e($theme['primaryDark']) ?>;
            --primary-soft: <?= e($theme['primarySoft']) ?>;
            --primary-light: <?= e($theme['primaryLight']) ?>;
            --card-border: <?= e($theme['cardBorder']) ?>;
            --text-dark: <?= e($theme['textDark']) ?>;
            --bg1: <?= e($theme['bg1']) ?>;
            --bg2: <?= e($theme['bg2']) ?>;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Plus Jakarta Sans', sans-serif;
        }

        body {
            min-height: 100vh;
            overflow: hidden;
            background:
                radial-gradient(circle at 15% 20%, var(--bg1), transparent 28%),
                radial-gradient(circle at 85% 15%, var(--bg2), transparent 30%),
                linear-gradient(135deg, #fff7f7 0%, #f8fafc 45%, #eef2f7 100%);
            display: grid;
            place-items: center;
            color: #111827;
        }

        .boot-screen {
            width: min(560px, calc(100vw - 34px));
            text-align: center;
            animation: fadeIn .55s ease both;
        }

        .logo-wrap {
            width: 96px;
            height: 96px;
            margin: 0 auto 24px;
            border-radius: 32px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            display: grid;
            place-items: center;
            color: #fff;
            font-size: 2.2rem;
            box-shadow: 0 28px 70px rgba(15, 23, 42, .16);
            position: relative;
        }

        .logo-wrap::before {
            content: "";
            position: absolute;
            inset: -12px;
            border-radius: 42px;
            border: 1px solid var(--card-border);
            animation: pulse 1.8s ease-in-out infinite;
        }

        .boot-card {
            background: rgba(255, 255, 255, .78);
            border: 1px solid rgba(255, 255, 255, .88);
            border-top: 5px solid var(--primary);
            border-radius: 30px;
            padding: 28px 26px;
            box-shadow: 0 28px 80px rgba(15, 23, 42, .12);
            backdrop-filter: blur(18px);
        }

        .welcome {
            font-size: .86rem;
            font-weight: 950;
            letter-spacing: .13em;
            text-transform: uppercase;
            color: var(--primary);
            margin-bottom: 10px;
        }

        .apartment-name {
            font-size: clamp(1.75rem, 5vw, 2.65rem);
            line-height: 1.08;
            font-weight: 950;
            letter-spacing: -.07em;
            margin-bottom: 10px;
        }

        .subtitle {
            color: #64748b;
            font-size: .94rem;
            line-height: 1.55;
            font-weight: 750;
            margin-bottom: 20px;
        }

        .role-pill {
            width: fit-content;
            margin: 0 auto 28px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 14px;
            border-radius: 999px;
            background: var(--primary-soft);
            border: 1px solid var(--card-border);
            color: var(--text-dark);
            font-size: .78rem;
            font-weight: 950;
        }

        .loading-row {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            color: #475569;
            font-size: .82rem;
            font-weight: 900;
            margin-bottom: 16px;
        }

        .dots {
            display: inline-flex;
            gap: 5px;
        }

        .dots span {
            width: 6px;
            height: 6px;
            border-radius: 999px;
            background: var(--primary);
            animation: dot 1s infinite ease-in-out;
        }

        .dots span:nth-child(2) { animation-delay: .16s; }
        .dots span:nth-child(3) { animation-delay: .32s; }

        .progress {
            height: 10px;
            width: 100%;
            background: #f1f5f9;
            border-radius: 999px;
            overflow: hidden;
            border: 1px solid #e5e7eb;
        }

        .progress span {
            display: block;
            height: 100%;
            width: 0%;
            background: linear-gradient(135deg, var(--primary-light), var(--primary-dark));
            border-radius: 999px;
            animation: loadbar 2.6s ease forwards;
        }

        .login-info {
            margin-top: 18px;
            color: #94a3b8;
            font-size: .75rem;
            font-weight: 800;
        }

        .fallback {
            margin-top: 18px;
            font-size: .78rem;
            font-weight: 850;
            color: #64748b;
        }

        .fallback a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 950;
        }

        @keyframes loadbar {
            0% { width: 0%; }
            30% { width: 38%; }
            68% { width: 72%; }
            100% { width: 100%; }
        }

        @keyframes pulse {
            0%, 100% { transform: scale(.98); opacity: .45; }
            50% { transform: scale(1.06); opacity: .85; }
        }

        @keyframes dot {
            0%, 100% { transform: translateY(0); opacity: .35; }
            50% { transform: translateY(-5px); opacity: 1; }
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(12px) scale(.98); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
    </style>
</head>
<body>

<div class="boot-screen">
    <div class="logo-wrap">
        <i class="fas <?= e($theme['icon']) ?>"></i>
    </div>

    <div class="boot-card">
        <div class="welcome"><?= e($welcomeText) ?></div>

        <h1 class="apartment-name"><?= e($apartmentName) ?></h1>

        <p class="subtitle">
            <?= e($apartmentSubtitle) ?>
        </p>

        <div class="role-pill">
            <i class="fas <?= e($theme['icon']) ?>"></i>
            <?= e($roleLabel) ?> Portal
        </div>

        <div class="loading-row">
            <?= e($loadingText) ?>
            <span class="dots">
                <span></span>
                <span></span>
                <span></span>
            </span>
        </div>

        <div class="progress">
            <span></span>
        </div>

        <div class="login-info">
            Signed in as <?= e($currentEmail) ?>
        </div>

        <div class="fallback">
            Not redirected? <a href="<?= e($nextUrl) ?>">Continue</a>
        </div>
    </div>
</div>

<script>
setTimeout(function () {
    window.location.href = <?= json_encode($nextUrl) ?>;
}, 2800);
</script>

</body>
</html>
