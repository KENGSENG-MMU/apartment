<?php
// admin_landingpage.php
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SmartVMS | Apartment Access System</title>

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <style>
        :root{
            --red:#e21f2d;
            --red-dark:#c51622;
            --navy:#06183a;
            --text:#475569;
            --muted:#64748b;
            --line:#e8edf5;
            --white:#ffffff;
            --soft-red:#fde8ea;
        }

        *{
            margin:0;
            padding:0;
            box-sizing:border-box;
        }

        html{
            scroll-behavior:smooth;
        }

        body{
            font-family:'Inter', sans-serif;
            background:#ffffff;
            color:var(--navy);
            overflow-x:hidden;
        }

        a{
            text-decoration:none;
            color:inherit;
        }

        .navbar{
            width:100%;
            height:98px;
            padding:0 6.8%;
            display:flex;
            align-items:center;
            justify-content:space-between;
            background:#ffffff;
            border-bottom:1px solid var(--line);
            position:sticky;
            top:0;
            z-index:100;
        }

        .logo{
            display:flex;
            align-items:center;
            gap:14px;
            font-size:30px;
            font-weight:900;
            letter-spacing:-1.5px;
        }

        .logo-icon{
            width:48px;
            height:48px;
            border-radius:15px;
            background:linear-gradient(135deg,var(--red),var(--red-dark));
            color:#ffffff;
            display:flex;
            align-items:center;
            justify-content:center;
            font-size:22px;
            box-shadow:0 12px 26px rgba(226,31,45,.20);
        }

        .logo .smart{
            color:var(--navy);
        }

        .logo .vms{
            color:var(--red);
        }

        .nav-menu{
            display:flex;
            align-items:center;
            gap:46px;
            font-size:16px;
            font-weight:800;
            color:#07142f;
        }

        .nav-menu a{
            transition:.2s ease;
        }

        .nav-menu a:hover{
            color:var(--red);
        }

        .nav-actions{
            display:flex;
            align-items:center;
            gap:18px;
        }

        .btn{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap:10px;
            padding:17px 30px;
            border-radius:10px;
            font-size:16px;
            font-weight:900;
            border:none;
            cursor:pointer;
            transition:.2s ease;
            white-space:nowrap;
        }

        .btn-red{
            background:linear-gradient(135deg,var(--red),var(--red-dark));
            color:#ffffff;
            box-shadow:0 14px 32px rgba(226,31,45,.20);
        }

        .btn-red:hover{
            transform:translateY(-2px);
            box-shadow:0 18px 40px rgba(226,31,45,.28);
        }

        .btn-outline{
            background:#ffffff;
            color:var(--navy);
            border:1px solid #cfd8e3;
        }

        .btn-outline:hover{
            color:var(--red);
            border-color:var(--red);
        }

        .btn-watch{
            background:#ffffff;
            color:var(--red);
            border:1.5px solid var(--red);
        }

        .btn-watch:hover{
            background:#fff5f6;
            transform:translateY(-2px);
        }

        .hero{
            width:100%;
            min-height:690px;
            display:grid;
            grid-template-columns:46% 54%;
            align-items:center;
            background:#ffffff;
            border-bottom:1px solid var(--line);
            overflow:hidden;
        }

        .hero-left{
            padding-left:6.8%;
            padding-right:42px;
            padding-top:20px;
            z-index:5;
        }

        .badge{
            display:inline-flex;
            align-items:center;
            gap:10px;
            background:var(--soft-red);
            color:#d21824;
            padding:13px 21px;
            border-radius:999px;
            font-size:15px;
            font-weight:800;
            margin-bottom:38px;
        }

        .hero h1{
            font-size:clamp(48px,4vw,68px);
            line-height:1.08;
            letter-spacing:-3px;
            font-weight:900;
            color:var(--navy);
            margin-bottom:30px;
        }

        .hero h1 .red{
            color:var(--red);
            white-space:nowrap;
        }

        .hero p{
            max-width:650px;
            color:#4f5f74;
            font-size:18px;
            line-height:1.75;
            font-weight:650;
            margin-bottom:38px;
        }

        .hero-buttons{
            display:flex;
            align-items:center;
            gap:20px;
            flex-wrap:wrap;
            margin-bottom:34px;
        }

        .hero-points{
            display:flex;
            align-items:center;
            gap:30px;
            flex-wrap:wrap;
            color:#64748b;
            font-size:15px;
            font-weight:700;
        }

        .hero-points span{
            display:flex;
            align-items:center;
            gap:8px;
        }

        .hero-points i{
            color:var(--red);
            font-size:14px;
        }

        .hero-right{
            width:100%;
            height:100%;
            min-height:690px;
            display:flex;
            align-items:center;
            justify-content:flex-end;
            overflow:hidden;
            background:#ffffff;
            padding-left:55px;
        }

        .hero-image{
            width:92%;
            max-width:950px;
            height:auto;
            min-height:0;
            object-fit:contain;
            object-position:right center;
            display:block;
            transform:translateX(12px);
        }

        .section{
            padding:80px 6.8%;
            background:#ffffff;
            border-bottom:1px solid var(--line);
        }

        .section-title{
            max-width:760px;
            margin:0 auto 45px;
            text-align:center;
        }

        .section-title small{
            display:block;
            color:var(--red);
            font-size:13px;
            font-weight:900;
            letter-spacing:2px;
            text-transform:uppercase;
            margin-bottom:12px;
        }

        .section-title h2{
            font-size:42px;
            line-height:1.2;
            font-weight:900;
            letter-spacing:-1.5px;
            margin-bottom:15px;
        }

        .section-title p{
            color:#64748b;
            font-size:17px;
            line-height:1.7;
            font-weight:600;
        }

        /* ===== Products Section ===== */
        .product-grid{
            display:grid;
            grid-template-columns:repeat(3,1fr);
            gap:22px;
            max-width:1160px;
            margin:0 auto;
        }

        .product-card{
            position:relative;
            background:#ffffff;
            border:1px solid #f0d9dc;
            border-radius:18px;
            padding:22px 22px 24px;
            box-shadow:0 12px 28px rgba(15,23,42,.04);
            overflow:hidden;
            min-height:178px;
            transition:.2s ease;
        }

        .product-card::after{
            content:"";
            position:absolute;
            right:-34px;
            bottom:-48px;
            width:150px;
            height:150px;
            background:radial-gradient(circle, rgba(226,31,45,.14), rgba(226,31,45,0) 70%);
        }

        .product-card:hover{
            transform:translateY(-5px);
            border-color:#ffb8bf;
            box-shadow:0 18px 40px rgba(15,23,42,.08);
        }

        .product-top{
            display:flex;
            justify-content:space-between;
            align-items:flex-start;
            gap:16px;
            margin-bottom:18px;
        }

        .product-icon{
            width:38px;
            height:38px;
            border-radius:12px;
            background:#fde5e7;
            color:var(--red);
            display:flex;
            align-items:center;
            justify-content:center;
            font-size:16px;
            flex:0 0 auto;
        }

        .mini-window{
            width:88px;
            height:52px;
            border:1px solid #f4cfd4;
            border-radius:12px;
            background:#fffafa;
            position:relative;
            padding:10px;
            flex:0 0 auto;
        }

        .mini-window::before{
            content:"";
            position:absolute;
            top:8px;
            left:10px;
            width:8px;
            height:8px;
            border-radius:50%;
            background:#fecaca;
            box-shadow:12px 0 0 #fee2e2, 24px 0 0 #fee2e2;
        }

        .mini-window span{
            display:block;
            height:6px;
            border-radius:999px;
            background:#f6cbd0;
            margin-top:16px;
            width:58%;
        }

        .mini-badge{
            position:absolute;
            right:-9px;
            bottom:8px;
            width:30px;
            height:30px;
            border-radius:50%;
            background:linear-gradient(135deg,var(--red),var(--red-dark));
            color:#ffffff;
            display:flex;
            align-items:center;
            justify-content:center;
            font-size:12px;
            box-shadow:0 8px 18px rgba(226,31,45,.22);
        }

        .product-card h3{
            font-size:17px;
            font-weight:900;
            color:var(--navy);
            margin-bottom:8px;
        }

        .product-card p{
            color:#64748b;
            font-size:14px;
            line-height:1.65;
            font-weight:600;
            position:relative;
            z-index:2;
        }

        /* ===== How It Works Section ===== */
        .flow-grid{
            display:grid;
            grid-template-columns:repeat(4,1fr);
            gap:22px;
            max-width:1160px;
            margin:0 auto;
        }

        .flow-card{
            background:#ffffff;
            border:1px solid #f0d9dc;
            border-radius:18px;
            padding:16px;
            box-shadow:0 10px 24px rgba(15,23,42,.04);
            transition:.2s ease;
        }

        .flow-card:hover{
            transform:translateY(-4px);
            border-color:#ffb8bf;
            box-shadow:0 18px 36px rgba(15,23,42,.08);
        }

        .flow-number{
            width:28px;
            height:28px;
            border-radius:50%;
            background:linear-gradient(135deg,var(--red),var(--red-dark));
            color:#ffffff;
            display:flex;
            align-items:center;
            justify-content:center;
            font-size:13px;
            font-weight:900;
            margin-bottom:14px;
        }

        .flow-preview{
            width:100%;
            height:84px;
            border:1px solid #f3d7db;
            border-radius:14px;
            background:linear-gradient(180deg,#fff8f8 0%, #ffffff 100%);
            padding:14px;
            position:relative;
            overflow:hidden;
            margin-bottom:16px;
        }

        .flow-preview::before{
            content:"";
            position:absolute;
            top:10px;
            left:12px;
            width:10px;
            height:10px;
            border-radius:50%;
            background:#fca5a5;
            box-shadow:16px 0 0 #fecaca, 32px 0 0 #fee2e2;
        }

        .preview-line{
            height:8px;
            border-radius:999px;
            background:#f5c9cf;
            margin-top:10px;
        }

        .preview-line.short{
            width:48%;
            margin-top:16px;
        }

        .preview-line.medium{
            width:72%;
        }

        .preview-line.small{
            width:38%;
        }

        .preview-icon{
            position:absolute;
            right:12px;
            bottom:12px;
            width:28px;
            height:28px;
            border-radius:50%;
            background:linear-gradient(135deg,var(--red),var(--red-dark));
            color:#ffffff;
            display:flex;
            align-items:center;
            justify-content:center;
            font-size:12px;
            box-shadow:0 8px 18px rgba(226,31,45,.20);
        }

        .flow-card h3{
            font-size:18px;
            font-weight:900;
            color:var(--navy);
            margin-bottom:8px;
        }

        .flow-card p{
            font-size:14px;
            line-height:1.6;
            color:#64748b;
            font-weight:600;
        }

        /* ===== Pricing Section ===== */
        .pricing-wrap{
            max-width:760px;
            margin:0 auto;
        }

        .pricing-card-main{
            position:relative;
            background:#ffffff;
            border:2px solid var(--red);
            border-radius:22px;
            padding:30px 30px 28px;
            box-shadow:0 18px 40px rgba(226,31,45,.10);
        }

        .pricing-badge{
            position:absolute;
            top:18px;
            right:20px;
            background:#fff3f4;
            color:var(--red);
            border:1px solid #ffd0d6;
            border-radius:999px;
            padding:6px 12px;
            font-size:11px;
            font-weight:900;
        }

        .pricing-card-main h3{
            font-size:24px;
            font-weight:900;
            color:var(--navy);
            margin-bottom:10px;
        }

        .pricing-desc{
            color:#64748b;
            font-size:15px;
            line-height:1.7;
            font-weight:600;
            margin-bottom:22px;
            max-width:590px;
        }

        .price-row{
            display:flex;
            align-items:flex-end;
            gap:8px;
            margin-bottom:10px;
            flex-wrap:wrap;
        }

        .price-row .rm{
            font-size:30px;
            line-height:1;
            font-weight:900;
            color:var(--navy);
        }

        .price-row .amount{
            font-size:58px;
            line-height:0.95;
            font-weight:900;
            letter-spacing:-2px;
            color:var(--navy);
        }

        .price-row .per{
            font-size:16px;
            font-weight:800;
            color:#64748b;
            margin-bottom:8px;
        }

        .price-note{
            display:flex;
            align-items:center;
            gap:8px;
            color:var(--red);
            font-size:13px;
            font-weight:800;
            margin-bottom:22px;
        }

        .pricing-list{
            list-style:none;
            display:grid;
            grid-template-columns:repeat(2,1fr);
            gap:12px 24px;
            margin:0 0 26px;
            padding:0;
        }

        .pricing-list li{
            position:relative;
            padding-left:22px;
            color:#334155;
            font-size:14px;
            line-height:1.6;
            font-weight:700;
        }

        .pricing-list li::before{
            content:"✓";
            position:absolute;
            left:0;
            top:0;
            color:var(--red);
            font-weight:900;
        }

        .pricing-actions{
            display:flex;
            align-items:center;
            gap:14px;
            flex-wrap:wrap;
        }

        .tutorial-anchor{
            position:relative;
            top:-120px;
            display:block;
            visibility:hidden;
        }

        footer{
            background:#06183a;
            color:#ffffff;
            padding:34px 6.8%;
            display:flex;
            justify-content:space-between;
            gap:20px;
            flex-wrap:wrap;
            font-weight:700;
        }

        @media(max-width:1300px){
            .hero{
                grid-template-columns:1fr;
                min-height:auto;
                padding-top:60px;
            }

            .hero-left{
                padding:0 7%;
            }

            .hero-right{
                min-height:auto;
                padding:30px 7% 0;
            }

            .hero-image{
                width:100%;
                max-width:980px;
                height:auto;
                min-height:0;
                object-fit:contain;
                transform:none;
                margin:0 auto;
            }

            .product-grid{
                grid-template-columns:repeat(2,1fr);
            }

            .flow-grid{
                grid-template-columns:repeat(2,1fr);
            }

            .pricing-list{
                grid-template-columns:repeat(2,1fr);
            }
        }

        @media(max-width:760px){
            .navbar{
                height:auto;
                padding:18px 22px;
                flex-direction:column;
                gap:18px;
            }

            .logo{
                font-size:26px;
            }

            .nav-menu{
                gap:18px;
                flex-wrap:wrap;
                justify-content:center;
                font-size:14px;
            }

            .nav-actions{
                width:100%;
                justify-content:center;
                flex-wrap:wrap;
            }

            .hero{
                padding-top:45px;
            }

            .hero-left{
                padding:0 24px;
            }

            .badge{
                font-size:13px;
                margin-bottom:24px;
            }

            .hero h1{
                font-size:42px;
                letter-spacing:-2px;
            }

            .hero h1 .red{
                white-space:normal;
            }

            .hero p{
                font-size:16px;
            }

            .hero-buttons{
                gap:14px;
            }

            .btn{
                width:100%;
                padding:15px 22px;
            }

            .hero-points{
                gap:14px;
                font-size:14px;
            }

            .hero-right{
                padding:20px 0 0;
            }

            .hero-image{
                width:112%;
                max-width:none;
                margin-left:-6%;
            }

            .section{
                padding:60px 22px;
            }

            .section-title h2{
                font-size:32px;
            }

            .product-grid{
                grid-template-columns:1fr;
            }

            .flow-grid{
                grid-template-columns:1fr;
            }

            .pricing-card-main{
                padding:24px 20px;
            }

            .pricing-badge{
                position:static;
                display:inline-flex;
                margin-bottom:18px;
            }

            .price-row .amount{
                font-size:46px;
            }

            .pricing-list{
                grid-template-columns:1fr;
            }

            .pricing-actions{
                flex-direction:column;
                align-items:stretch;
            }

            .pricing-actions .btn{
                width:100%;
            }

            footer{
                padding:28px 22px;
            }
        }
    </style>
</head>

<body>

<header class="navbar">
    <a href="admin_landingpage.php" class="logo">
        <div class="logo-icon">
            <i class="fa-solid fa-shield-halved"></i>
        </div>
        <div>
            <span class="smart">Smart</span><span class="vms">VMS</span>
        </div>
    </a>

    <nav class="nav-menu">
        <a href="#products">Products</a>
        <a href="#how">How It Works</a>
        <a href="#pricing">Pricing</a>
        <a href="#tutorial">Tutorial</a>
        <a href="admin_landingpage_aboutus.php">About Us</a>
    </nav>

    <div class="nav-actions">
        <a href="free_trial.php" class="btn btn-red">First Month Free</a>
        <a href="staff_login.php" class="btn btn-outline">Login</a>
    </div>
</header>

<main>

    <section class="hero">
        <div class="hero-left">
            <div class="badge">
                <i class="fa-solid fa-building-lock"></i>
                Smart apartment security platform
            </div>

            <h1>
                Automatic Plate<br>
                Recognition for<br>
                <span class="red">Apartment Access</span>
            </h1>

            <p>
                SmartVMS helps apartment management handle visitor bookings and
                control vehicle entry and exit using automatic plate recognition.
                QR verification is included only as a backup method when needed.
            </p>

            <div class="hero-buttons">
                <a href="free_trial.php" class="btn btn-red">
                    <i class="fa-solid fa-rocket"></i>
                    Start First Month Free
                </a>

                <a href="admin_landingpage.php" class="btn btn-watch">
                    <i class="fa-solid fa-circle-play"></i>
                    Watch Product Tutorial
                </a>
            </div>

            <div class="hero-points">
                <span><i class="fa-solid fa-circle-check"></i>No credit card required</span>
                <span><i class="fa-solid fa-circle-check"></i>Built for apartments</span>
                <span><i class="fa-solid fa-circle-check"></i>Visitor booking control</span>
            </div>
        </div>

        <div class="hero-right">
            <img
                src="admin_landingpage.png"
                alt="SmartVMS apartment access illustration"
                class="hero-image"
            >
        </div>
    </section>

    <section class="section" id="products">
        <div class="section-title">
            <small>Products</small>
            <h2>Core modules built for apartment vehicle access</h2>
            <p>
                SmartVMS focuses on visitor booking, automatic plate scanning,
                resident vehicle records, parking control and access logs.
                QR is only a backup verification method.
            </p>
        </div>

        <div class="product-grid">
            <div class="product-card">
                <div class="product-top">
                    <div class="product-icon">
                        <i class="fa-solid fa-car-side"></i>
                    </div>
                    <div class="mini-window">
                        <span></span>
                        <div class="mini-badge">
                            <i class="fa-solid fa-camera"></i>
                        </div>
                    </div>
                </div>
                <h3>Plate Recognition Gate Access</h3>
                <p>
                    Automatically scans and verifies vehicle plate numbers at apartment
                    entry and exit. QR verification is kept as a backup when plate
                    scanning cannot be used.
                </p>
            </div>

            <div class="product-card">
                <div class="product-top">
                    <div class="product-icon">
                        <i class="fa-solid fa-calendar-check"></i>
                    </div>
                    <div class="mini-window">
                        <span></span>
                        <div class="mini-badge">
                            <i class="fa-solid fa-user-plus"></i>
                        </div>
                    </div>
                </div>
                <h3>Visitor Booking</h3>
                <p>
                    Residents can invite visitors or approve visit requests with purpose,
                    date, time slot, visitor details and vehicle plate number.
                </p>
            </div>

            <div class="product-card">
                <div class="product-top">
                    <div class="product-icon">
                        <i class="fa-solid fa-id-card"></i>
                    </div>
                    <div class="mini-window">
                        <span></span>
                        <div class="mini-badge">
                            <i class="fa-solid fa-users"></i>
                        </div>
                    </div>
                </div>
                <h3>Visitor Management</h3>
                <p>
                    Admins can view active bookings, completed records, visitor account
                    information and booking history in one place.
                </p>
            </div>

            <div class="product-card">
                <div class="product-top">
                    <div class="product-icon">
                        <i class="fa-solid fa-square-parking"></i>
                    </div>
                    <div class="mini-window">
                        <span></span>
                        <div class="mini-badge">
                            <i class="fa-solid fa-car"></i>
                        </div>
                    </div>
                </div>
                <h3>Parking Management</h3>
                <p>
                    Control resident parking subscriptions, visitor parking slots and
                    assigned vehicles linked to plate verification.
                </p>
            </div>

            <div class="product-card">
                <div class="product-top">
                    <div class="product-icon">
                        <i class="fa-solid fa-building-user"></i>
                    </div>
                    <div class="mini-window">
                        <span></span>
                        <div class="mini-badge">
                            <i class="fa-solid fa-folder-open"></i>
                        </div>
                    </div>
                </div>
                <h3>Resident & Vehicle Records</h3>
                <p>
                    Register residents, assign units and manage resident vehicle plates
                    for accurate access and parking control.
                </p>
            </div>

            <div class="product-card">
                <div class="product-top">
                    <div class="product-icon">
                        <i class="fa-solid fa-chart-line"></i>
                    </div>
                    <div class="mini-window">
                        <span></span>
                        <div class="mini-badge">
                            <i class="fa-solid fa-clock-rotate-left"></i>
                        </div>
                    </div>
                </div>
                <h3>Access Logs & Records</h3>
                <p>
                    Track entry and exit records, plate scan history, backup verification
                    usage, blacklist records and admin activity.
                </p>
            </div>
        </div>
    </section>

    <section class="section" id="how">
        <div class="section-title">
            <small>How It Works</small>
            <h2>Simple flow from application to smart gate access</h2>
            <p>
                Apartment management applies online, then SmartVMS creates the admin account,
                units, parking slots and gate access setup after approval.
            </p>
        </div>

        <div class="flow-grid">
            <div class="flow-card">
                <div class="flow-number">1</div>
                <div class="flow-preview">
                    <div class="preview-line short"></div>
                    <div class="preview-line medium"></div>
                    <div class="preview-line small"></div>
                    <div class="preview-icon">
                        <i class="fa-solid fa-file-pen"></i>
                    </div>
                </div>
                <h3>Apply</h3>
                <p>
                    Apartment management fills the first month free form with apartment details,
                    admin login email and parking information.
                </p>
            </div>

            <div class="flow-card">
                <div class="flow-number">2</div>
                <div class="flow-preview">
                    <div class="preview-line short"></div>
                    <div class="preview-line medium"></div>
                    <div class="preview-line small"></div>
                    <div class="preview-icon">
                        <i class="fa-solid fa-list-check"></i>
                    </div>
                </div>
                <h3>Review</h3>
                <p>
                    SmartVMS superadmin checks the application before activating
                    the apartment account.
                </p>
            </div>

            <div class="flow-card">
                <div class="flow-number">3</div>
                <div class="flow-preview">
                    <div class="preview-line short"></div>
                    <div class="preview-line medium"></div>
                    <div class="preview-line small"></div>
                    <div class="preview-icon">
                        <i class="fa-solid fa-building-circle-check"></i>
                    </div>
                </div>
                <h3>Generate</h3>
                <p>
                    The system creates apartment units, resident and visitor handling setup,
                    parking slots and admin access.
                </p>
            </div>

            <div class="flow-card">
                <div class="flow-number">4</div>
                <div class="flow-preview">
                    <div class="preview-line short"></div>
                    <div class="preview-line medium"></div>
                    <div class="preview-line small"></div>
                    <div class="preview-icon">
                        <i class="fa-solid fa-sliders"></i>
                    </div>
                </div>
                <h3>Manage</h3>
                <p>
                    The apartment admin logs in and starts managing resident vehicles,
                    visitor booking, parking and plate recognition gate access.
                </p>
            </div>
        </div>
    </section>

    <section class="section" id="pricing">
        <div class="section-title">
            <small>Pricing</small>
            <h2>One simple plan for every apartment</h2>
            <p>
                All core SmartVMS features are included in one monthly package.
                First month is free after superadmin approval.
            </p>
        </div>

        <div class="pricing-wrap">
            <span id="tutorial" class="tutorial-anchor"></span>

            <div class="pricing-card-main">
                <span class="pricing-badge">Standard Plan</span>

                <h3>SmartVMS Standard</h3>
                <p class="pricing-desc">
                    For apartment and condominium management teams that need visitor booking,
                    automatic plate recognition, resident vehicle records and parking control
                    in one system.
                </p>

                <div class="price-row">
                    <span class="rm">RM</span>
                    <span class="amount">300</span>
                    <span class="per">/ month</span>
                </div>

                <div class="price-note">
                    <i class="fa-solid fa-circle-info"></i>
                    First month free. No credit card required.
                </div>

                <ul class="pricing-list">
                    <li>Apartment application approval</li>
                    <li>Auto-generate units and parking</li>
                    <li>Admin dashboard</li>
                    <li>Resident management</li>
                    <li>Automatic plate recognition access</li>
                    <li>Visitor booking management</li>
                    <li>QR backup verification when needed</li>
                    <li>Parking management</li>
                    <li>Access logs and records</li>
                    <li>Blacklist and gate activity tracking</li>
                </ul>

                <div class="pricing-actions">
                    <a href="free_trial.php" class="btn btn-red">
                        <i class="fa-solid fa-gift"></i>
                        Start First Month Free
                    </a>

                    <a href="admin_landingpage.php" class="btn btn-watch">
                        <i class="fa-solid fa-circle-play"></i>
                        Watch Tutorial
                    </a>
                </div>
            </div>
        </div>
    </section>

</main>

<footer>
    <span>SmartVMS — Apartment Visitor Management System</span>
    <span>Visitor Booking • Plate Recognition • Parking Management</span>
</footer>

</body>
</html>