<?php
// admin_landingpage_aboutus.php - SmartVMS About Us page without Contact link
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>About Us | SmartVMS</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        :root{
            --red:#e11d2e;
            --red-dark:#9f1620;
            --red-soft:#fff1f2;
            --navy:#06163a;
            --text:#24304a;
            --muted:#6b758c;
            --line:#e8edf5;
            --bg:#f7f9fc;
            --white:#fff;
            --shadow:0 20px 60px rgba(15,23,42,.08);
            --radius:26px;
        }
        *{box-sizing:border-box}
        body{
            margin:0;
            font-family:Inter,Segoe UI,Arial,sans-serif;
            color:var(--navy);
            background:linear-gradient(180deg,#fff 0%,#f8fafc 42%,#fff 100%);
        }
        a{text-decoration:none;color:inherit}
        .topbar{
            position:sticky;
            top:0;
            z-index:50;
            height:92px;
            display:flex;
            align-items:center;
            justify-content:center;
            border-bottom:1px solid var(--line);
            background:rgba(255,255,255,.94);
            backdrop-filter:blur(14px);
        }
        .nav{
            width:min(1500px,92vw);
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:28px;
        }
        .brand{
            display:flex;
            align-items:center;
            gap:14px;
            font-weight:900;
            font-size:27px;
            letter-spacing:-1px;
        }
        .brand-icon{
            width:54px;
            height:54px;
            border-radius:17px;
            display:grid;
            place-items:center;
            background:linear-gradient(135deg,var(--red),var(--red-dark));
            color:#fff;
            box-shadow:0 18px 36px rgba(225,29,46,.24);
        }
        .brand span span{color:var(--red)}
        .nav-links{
            display:flex;
            align-items:center;
            gap:34px;
            font-weight:800;
            color:#586176;
        }
        .nav-links a{position:relative;padding:8px 0}
        .nav-links a:hover,.nav-links a.active{color:var(--red)}
        .nav-links a.active:after{
            content:"";
            position:absolute;
            left:0;right:0;bottom:-17px;
            height:3px;
            border-radius:99px;
            background:var(--red);
        }
        .nav-actions{
            display:flex;
            align-items:center;
            gap:14px;
        }
        .btn{
            border:0;
            border-radius:16px;
            padding:16px 25px;
            font-weight:900;
            font-size:16px;
            cursor:pointer;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap:9px;
        }
        .btn-red{
            color:#fff;
            background:linear-gradient(135deg,var(--red),var(--red-dark));
            box-shadow:0 20px 45px rgba(225,29,46,.22);
        }
        .btn-light{
            background:#fff;
            border:1px solid var(--line);
            color:var(--navy);
        }
        .hero{
            padding:86px 0 56px;
            background:
                radial-gradient(circle at 18% 0%,rgba(225,29,46,.09),transparent 32%),
                radial-gradient(circle at 82% 15%,rgba(225,29,46,.08),transparent 36%);
        }
        .container{width:min(1320px,92vw);margin:0 auto}
        .hero-grid{
            display:grid;
            grid-template-columns:1.05fr .95fr;
            gap:44px;
            align-items:center;
        }
        .eyebrow{
            display:inline-flex;
            align-items:center;
            gap:8px;
            padding:9px 13px;
            border-radius:999px;
            background:var(--red-soft);
            color:var(--red-dark);
            font-weight:900;
            font-size:13px;
            border:1px solid #fecdd3;
        }
        h1{
            margin:20px 0 18px;
            font-size:clamp(42px,5vw,76px);
            line-height:1.02;
            letter-spacing:-3px;
        }
        h1 span{color:var(--red)}
        .lead{
            max-width:720px;
            color:var(--muted);
            font-size:20px;
            line-height:1.7;
            font-weight:700;
        }
        .hero-card{
            position:relative;
            background:#fff;
            border:1px solid var(--line);
            border-radius:34px;
            padding:30px;
            box-shadow:var(--shadow);
            overflow:hidden;
        }
        .hero-card:before{
            content:"";
            position:absolute;
            inset:auto -55px -75px auto;
            width:260px;height:260px;
            border-radius:50%;
            background:rgba(225,29,46,.10);
        }
        .mock-top{
            height:48px;
            border-radius:20px;
            background:#f4f6fb;
            display:flex;
            align-items:center;
            gap:8px;
            padding:0 18px;
            margin-bottom:20px;
        }
        .dot{width:10px;height:10px;border-radius:50%}.d1{background:#ef4444}.d2{background:#f59e0b}.d3{background:#22c55e}
        .metric{
            border:1px solid var(--line);
            border-radius:22px;
            padding:22px;
            margin-bottom:16px;
            background:linear-gradient(135deg,#fff,#fff5f5);
        }
        .metric small{color:var(--muted);font-weight:900;text-transform:uppercase;letter-spacing:.08em}
        .metric strong{display:block;margin-top:9px;font-size:34px;color:var(--navy)}
        .mini-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
        .mini{
            border:1px solid var(--line);
            border-radius:20px;
            padding:18px;
            background:#fff;
            font-weight:900;
        }
        .mini i{color:var(--red);margin-right:8px}
        .section{padding:76px 0}
        .section-head{text-align:center;margin-bottom:38px}
        .section-head small{font-weight:900;color:var(--red);letter-spacing:.13em;text-transform:uppercase}
        .section-head h2{font-size:clamp(30px,3.2vw,50px);letter-spacing:-1.8px;margin:12px 0;color:var(--navy)}
        .section-head p{color:var(--muted);font-weight:700;line-height:1.65;max-width:780px;margin:0 auto}
        .cards{display:grid;grid-template-columns:repeat(3,1fr);gap:22px}
        .card{
            background:#fff;
            border:1px solid var(--line);
            border-radius:var(--radius);
            padding:30px;
            box-shadow:0 18px 48px rgba(15,23,42,.05);
        }
        .icon{
            width:56px;height:56px;border-radius:18px;
            display:grid;place-items:center;
            background:var(--red-soft);
            color:var(--red);
            font-size:22px;
            margin-bottom:20px;
        }
        .card h3{font-size:23px;margin:0 0 12px;letter-spacing:-.5px}
        .card p{margin:0;color:var(--muted);line-height:1.65;font-weight:700}
        .story{
            display:grid;
            grid-template-columns:1fr 1fr;
            gap:28px;
            align-items:stretch;
        }
        .panel{
            background:#fff;
            border:1px solid var(--line);
            border-radius:30px;
            padding:34px;
            box-shadow:var(--shadow);
        }
        .panel h3{font-size:28px;margin:0 0 14px;letter-spacing:-.8px}
        .panel p{color:var(--muted);font-weight:700;line-height:1.75;margin:0 0 18px}
        .check-list{display:grid;gap:14px;margin-top:22px}
        .check{display:flex;gap:12px;align-items:flex-start;font-weight:850;color:var(--text)}
        .check i{color:var(--red);margin-top:3px}
        .cta{
            margin:30px 0 0;
            border-radius:32px;
            padding:40px;
            background:linear-gradient(135deg,var(--red),var(--red-dark));
            color:#fff;
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:20px;
            box-shadow:0 30px 70px rgba(225,29,46,.22);
        }
        .cta h2{margin:0 0 8px;font-size:34px;letter-spacing:-1px}
        .cta p{margin:0;font-weight:750;opacity:.93;line-height:1.55}
        .cta .btn{background:#fff;color:var(--red-dark)}
        footer{
            padding:34px 0;
            background:#06163a;
            color:#fff;
        }
        .foot{display:flex;justify-content:space-between;align-items:center;gap:20px;font-weight:800}
        .foot span{opacity:.8}
        @media(max-width:980px){
            .hero-grid,.story{grid-template-columns:1fr}
            .cards{grid-template-columns:1fr 1fr}
            .nav-links{display:none}
            .cta{flex-direction:column;align-items:flex-start}
        }
        @media(max-width:620px){
            .cards{grid-template-columns:1fr}
            .topbar{height:auto;padding:14px 0}
            .nav{flex-wrap:wrap}
            .nav-actions{width:100%}.nav-actions .btn{flex:1}
            h1{letter-spacing:-1.5px}
        }
    </style>
</head>
<body>
<header class="topbar">
    <nav class="nav">
        <a class="brand" href="admin_landingpage.php">
            <div class="brand-icon"><i class="fa-solid fa-shield-halved"></i></div>
            <span>Smart<span>VMS</span></span>
        </a>

        <div class="nav-links">
            <a href="admin_landingpage.php#products">Products</a>
            <a href="admin_landingpage.php#how">How It Works</a>
            <a href="admin_landingpage.php#pricing">Pricing</a>
            <a href="admin_landingpage.php">Tutorial</a>
            <a href="admin_landingpage_aboutus.php" class="active">About Us</a>
        </div>

        <div class="nav-actions">
            <a class="btn btn-red" href="free_trial.php">First Month Free</a>
            <a class="btn btn-light" href="login.php">Login</a>
        </div>
    </nav>
</header>

<section class="hero">
    <div class="container hero-grid">
        <div>
            <div class="eyebrow"><i class="fa-solid fa-building-shield"></i> About SmartVMS</div>
            <h1>We simplify <span>apartment visitor access</span> with plate recognition.</h1>
            <p class="lead">
                SmartVMS is built for apartment management teams that need a simple way to manage visitors, residents, parking access and gate records. Our system focuses on automatic vehicle plate recognition, with QR verification as a backup method when needed.
            </p>
        </div>
        <div class="hero-card">
            <div class="mock-top"><span class="dot d1"></span><span class="dot d2"></span><span class="dot d3"></span></div>
            <div class="metric">
                <small>Core Focus</small>
                <strong>Plate Recognition Gate Access</strong>
            </div>
            <div class="mini-grid">
                <div class="mini"><i class="fa-solid fa-car"></i> Entry / Exit</div>
                <div class="mini"><i class="fa-solid fa-qrcode"></i> QR Backup</div>
                <div class="mini"><i class="fa-solid fa-square-parking"></i> Parking</div>
                <div class="mini"><i class="fa-solid fa-clipboard-list"></i> Logs</div>
            </div>
        </div>
    </div>
</section>

<section class="section">
    <div class="container">
        <div class="section-head">
            <small>Our Purpose</small>
            <h2>Designed for apartment management</h2>
            <p>SmartVMS helps apartment admins and guards reduce manual visitor checking, improve entry records and make parking control easier.</p>
        </div>
        <div class="cards">
            <div class="card">
                <div class="icon"><i class="fa-solid fa-car-side"></i></div>
                <h3>Automatic Plate Access</h3>
                <p>Vehicles can be checked through license plate recognition for faster apartment entry and exit.</p>
            </div>
            <div class="card">
                <div class="icon"><i class="fa-solid fa-calendar-check"></i></div>
                <h3>Visitor Booking</h3>
                <p>Residents can invite visitors, submit visit details and allow the admin or guard to track visit status.</p>
            </div>
            <div class="card">
                <div class="icon"><i class="fa-solid fa-square-parking"></i></div>
                <h3>Parking Control</h3>
                <p>Manage resident parking subscriptions, visitor parking slots and assigned vehicles in one system.</p>
            </div>
        </div>
    </div>
</section>

<section class="section" style="background:#f8fafc;border-top:1px solid var(--line);border-bottom:1px solid var(--line);">
    <div class="container story">
        <div class="panel">
            <h3>Why SmartVMS?</h3>
            <p>
                Many apartments still depend on manual guard checking, paper records or separate parking lists. SmartVMS combines visitor booking, vehicle plate checking, QR backup verification and access logs in one simple web-based platform.
            </p>
            <div class="check-list">
                <div class="check"><i class="fa-solid fa-check"></i> Faster gate checking using plate recognition.</div>
                <div class="check"><i class="fa-solid fa-check"></i> QR code available as backup verification.</div>
                <div class="check"><i class="fa-solid fa-check"></i> Clear records for visitor entry and exit.</div>
                <div class="check"><i class="fa-solid fa-check"></i> Parking data linked with resident and visitor vehicles.</div>
            </div>
        </div>
        <div class="panel">
            <h3>Who uses it?</h3>
            <p>
                SmartVMS is suitable for apartment management offices that want a cleaner visitor and vehicle access process.
            </p>
            <div class="check-list">
                <div class="check"><i class="fa-solid fa-user-shield"></i> Superadmin manages apartment applications and subscriptions.</div>
                <div class="check"><i class="fa-solid fa-user-tie"></i> Admin manages residents, visitors, parking and records.</div>
                <div class="check"><i class="fa-solid fa-person-military-pointing"></i> Guards verify vehicles, QR passes and gate logs.</div>
                <div class="check"><i class="fa-solid fa-house-user"></i> Residents invite visitors and manage visitor requests.</div>
            </div>
        </div>
    </div>
</section>

<section class="section">
    <div class="container">
        <div class="section-head">
            <small>System Value</small>
            <h2>One platform for safer apartment access</h2>
            <p>The goal is to make apartment visitor and vehicle management easier, more organized and more secure for daily operations.</p>
        </div>
        <div class="cta">
            <div>
                <h2>Ready to try SmartVMS?</h2>
                <p>Start the first month free. The apartment admin account will be activated after superadmin approval.</p>
            </div>
            <a class="btn" href="free_trial.php"><i class="fa-solid fa-rocket"></i> Start First Month Free</a>
        </div>
    </div>
</section>

<footer>
    <div class="container foot">
        <strong>SmartVMS — Apartment Visitor Management System</strong>
        <span>Plate Recognition • Visitor Booking • Parking Management</span>
    </div>
</footer>
</body>
</html>
