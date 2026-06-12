<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SmartVMS | User Portal</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            min-height: 100vh;
            background: #000;
            overflow-x: hidden;
        }

        .header-bg {
            width: 100%;
            height: 78px;
            background: #000000;
            padding: 0 8%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: fixed;
            top: 0;
            left: 0;
            z-index: 1000;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }

        .logo {
            color: #ffffff;
            font-size: 1.45rem;
            font-weight: 900;
            letter-spacing: -0.04em;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .logo i {
            font-size: 1rem;
        }

        .logo span {
            color: #1683ff;
        }

        nav {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .register-nav-btn,
        .login-nav-btn {
            text-decoration: none;
            font-weight: 800;
            font-size: 0.82rem;
            padding: 10px 20px;
            border-radius: 5px;
            transition: 0.25s ease;
        }

        .register-nav-btn {
            color: #ffffff;
            border: 1px solid rgba(255, 255, 255, 0.58);
            background: transparent;
        }

        .register-nav-btn:hover {
            background: rgba(255, 255, 255, 0.12);
        }

        .login-nav-btn {
            color: #ffffff;
            background: #087bff;
            border: 1px solid #087bff;
        }

        .login-nav-btn:hover {
            background: #006be0;
        }

        .hero-section {
            min-height: 100vh;
            padding-top: 78px;
            position: relative;
            display: flex;
            align-items: center;
            overflow: hidden;
            background: #000;
        }

        .video-bg {
            position: absolute;
            top: 78px;
            left: 0;
            width: 100%;
            height: calc(100vh - 78px);
            z-index: 0;
            overflow: hidden;
            background: #000;
        }

        .video-bg video {
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: center;
            display: block;
        }

        .hero-overlay {
            position: absolute;
            top: 78px;
            left: 0;
            width: 100%;
            height: calc(100vh - 78px);
            background:
                linear-gradient(
                    90deg,
                    rgba(0, 0, 0, 0.82) 0%,
                    rgba(0, 0, 0, 0.58) 38%,
                    rgba(0, 0, 0, 0.22) 100%
                );
            z-index: 1;
        }

        .hero-container {
            width: 100%;
            padding: 0 8%;
            position: relative;
            z-index: 2;
        }

        .hero-content {
            max-width: 650px;
            color: #ffffff;
            margin-top: 55px;
        }

        .hero-label {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            padding: 9px 16px;
            margin-bottom: 20px;
            border-radius: 999px;
            background: rgba(0, 0, 0, 0.35);
            border: 1px solid rgba(255, 255, 255, 0.22);
            color: rgba(255, 255, 255, 0.92);
            font-size: 0.78rem;
            font-weight: 800;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            backdrop-filter: blur(12px);
        }

        .hero-label i {
            color: #1683ff;
        }

        .hero-content h1 {
            font-size: 4.35rem;
            font-weight: 900;
            line-height: 1.05;
            letter-spacing: -0.07em;
            margin-bottom: 22px;
            color: #ffffff;
            text-shadow: 0 10px 35px rgba(0, 0, 0, 0.55);
        }

        .hero-tagline {
            font-size: 1.08rem;
            font-weight: 500;
            line-height: 1.75;
            margin-bottom: 32px;
            color: rgba(255, 255, 255, 0.88);
            max-width: 560px;
            text-shadow: 0 6px 22px rgba(0, 0, 0, 0.55);
        }

        .cta-group {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 15px;
        }

        .btn-primary {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            padding: 15px 34px;
            background: #087bff;
            color: #ffffff;
            text-decoration: none;
            font-weight: 900;
            border-radius: 7px;
            transition: 0.25s ease;
            box-shadow: 0 18px 38px rgba(8, 123, 255, 0.28);
        }

        .btn-primary:hover {
            background: #006be0;
            transform: translateY(-2px);
        }

        .cta-sub {
            font-size: 0.86rem;
            color: rgba(255, 255, 255, 0.76);
            font-weight: 500;
            text-shadow: 0 5px 18px rgba(0, 0, 0, 0.55);
        }

        @media (max-width: 768px) {
            .header-bg {
                height: auto;
                padding: 16px 5%;
                flex-direction: column;
                align-items: flex-start;
                gap: 14px;
            }

            nav {
                width: 100%;
                display: grid;
                grid-template-columns: 1fr 1fr;
            }

            .register-nav-btn,
            .login-nav-btn {
                text-align: center;
                padding: 11px 12px;
            }

            .hero-section {
                padding-top: 135px;
            }

            .video-bg,
            .hero-overlay {
                top: 135px;
                height: calc(100vh - 135px);
            }

            .hero-container {
                padding: 0 5%;
            }

            .hero-content {
                margin-top: 25px;
            }

            .hero-label {
                font-size: 0.66rem;
                padding: 8px 12px;
                margin-bottom: 16px;
            }

            .hero-content h1 {
                font-size: 2.7rem;
                line-height: 1.08;
            }

            .hero-tagline {
                font-size: 0.95rem;
                max-width: 100%;
            }

            .btn-primary {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>

<header class="header-bg">
    <div class="logo">
        <i class="fas fa-car"></i>
        Smart<span>VMS</span>
    </div>

    <nav>
        <a href="register.php" class="register-nav-btn">Visitor Register</a>
        <a href="user_login.php" class="login-nav-btn">User Login</a>
    </nav>
</header>

<section class="hero-section">

    <div class="video-bg">
        <video autoplay muted loop playsinline preload="auto">
            <source src="landing.mp4" type="video/mp4">
        </video>
    </div>

    <div class="hero-overlay"></div>

    <div class="hero-container">
        <div class="hero-content">

            <div class="hero-label">
                <i class="fas fa-shield-halved"></i>
                Smart Apartment Visitor Management
            </div>

            <h1>
                Safer Visits, <br>
                Smarter Access.
            </h1>

            <p class="hero-tagline">
                Manage visitor bookings, QR passes, parking access, and resident verification in one simple SmartVMS portal.
            </p>

            <div class="cta-group">
                <a href="user_login.php" class="btn-primary">
                    <i class="fas fa-right-to-bracket"></i>
                    Login to User Portal
                </a>

                <p class="cta-sub">
                    For verified residents and registered visitors only.
                </p>
            </div>

        </div>
    </div>

</section>

</body>
</html>