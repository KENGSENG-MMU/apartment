<?php
require_once '../core/security.php';
require_login(['admin', 'superadmin']);

$pdo = db();

if (!function_exists('e')) {
    function e($value): string {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

function pp_table_exists(PDO $pdo, string $table): bool {
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

function pp_column_exists(PDO $pdo, string $table, string $column): bool {
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

function pp_ensure_table(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS platform_payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            apartment_id INT NOT NULL,
            billing_month DATE NOT NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 300.00,
            status ENUM('unpaid','submitted','paid','rejected') NOT NULL DEFAULT 'unpaid',
            payment_method VARCHAR(60) DEFAULT NULL,
            transaction_ref VARCHAR(120) DEFAULT NULL,
            proof_file VARCHAR(255) DEFAULT NULL,
            note TEXT DEFAULT NULL,
            submitted_by INT DEFAULT NULL,
            verified_by INT DEFAULT NULL,
            submitted_at DATETIME DEFAULT NULL,
            verified_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_platform_payment_month (apartment_id, billing_month),
            KEY idx_platform_payment_status (status),
            KEY idx_platform_payment_apartment (apartment_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function pp_current_apartment_id(PDO $pdo): int {
    if (!empty($_SESSION['apartment_id'])) {
        return (int)$_SESSION['apartment_id'];
    }

    $uid = (int)($_SESSION['uid'] ?? 0);

    if ($uid > 0 && pp_table_exists($pdo, 'users') && pp_column_exists($pdo, 'users', 'apartment_id')) {
        try {
            $stmt = $pdo->prepare("SELECT apartment_id FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$uid]);
            $id = $stmt->fetchColumn();

            if ($id) {
                $_SESSION['apartment_id'] = (int)$id;
                return (int)$id;
            }
        } catch (Throwable $e) {
            // continue fallback
        }
    }

    if (pp_table_exists($pdo, 'apartments')) {
        try {
            $id = $pdo->query("SELECT id FROM apartments ORDER BY id ASC LIMIT 1")->fetchColumn();
            if ($id) {
                $_SESSION['apartment_id'] = (int)$id;
                return (int)$id;
            }
        } catch (Throwable $e) {
            // continue fallback
        }
    }

    return 1;
}

function pp_current_apartment_name(PDO $pdo, int $apartmentId): string {
    if (pp_table_exists($pdo, 'apartments')) {
        try {
            $stmt = $pdo->prepare("SELECT apartment_name FROM apartments WHERE id = ? LIMIT 1");
            $stmt->execute([$apartmentId]);
            $name = $stmt->fetchColumn();

            if ($name) {
                $_SESSION['apartment_name'] = $name;
                return (string)$name;
            }
        } catch (Throwable $e) {
            // keep fallback
        }
    }

    return $_SESSION['apartment_name'] ?? 'Ixoro Apartment';
}

function pp_safe_month($month): string {
    $month = trim((string)$month);

    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        return date('Y-m');
    }

    return $month;
}

function pp_month_date(string $month): string {
    return $month . '-01';
}

function pp_month_label(?string $date): string {
    if (!$date) {
        return '-';
    }

    $time = strtotime($date);
    return $time ? date('M Y', $time) : $date;
}

function pp_dt(?string $value): string {
    if (!$value) {
        return '-';
    }

    $time = strtotime($value);
    return $time ? date('d M Y, h:i A', $time) : $value;
}

function pp_due_date(string $billingDate): string {
    $dt = new DateTime($billingDate);
    $dt->modify('+6 days');
    return $dt->format('Y-m-d');
}

function pp_visual_status(array $payment): string {
    $status = strtolower((string)($payment['status'] ?? 'unpaid'));

    if ($status === 'unpaid' || $status === 'rejected') {
        $dueDate = pp_due_date((string)$payment['billing_month']);
        if (date('Y-m-d') > $dueDate) {
            return 'overdue';
        }
    }

    return $status;
}

function pp_status_label(string $status): string {
    return match ($status) {
        'paid' => 'Paid',
        'submitted' => 'Submitted',
        'rejected' => 'Rejected',
        'overdue' => 'Overdue',
        default => 'Unpaid',
    };
}

function pp_ensure_payment(PDO $pdo, int $apartmentId, string $billingDate, float $amount = 300.00): void {
    $stmt = $pdo->prepare("
        INSERT INTO platform_payments (apartment_id, billing_month, amount, status)
        VALUES (?, ?, ?, 'unpaid')
        ON DUPLICATE KEY UPDATE
            amount = IF(status = 'paid', amount, VALUES(amount)),
            apartment_id = apartment_id
    ");
    $stmt->execute([$apartmentId, $billingDate, $amount]);
}

function pp_fetch_payment(PDO $pdo, int $apartmentId, string $billingDate): array {
    $stmt = $pdo->prepare("
        SELECT *
        FROM platform_payments
        WHERE apartment_id = ?
        AND billing_month = ?
        LIMIT 1
    ");
    $stmt->execute([$apartmentId, $billingDate]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

function pp_upload_proof(): ?string {
    if (empty($_FILES['proof_file']) || !is_array($_FILES['proof_file'])) {
        return null;
    }

    $file = $_FILES['proof_file'];

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new Exception('Payment proof upload failed.');
    }

    if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new Exception('Payment proof must be below 5MB.');
    }

    $originalName = (string)($file['name'] ?? '');
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'pdf'];

    if (!in_array($extension, $allowed, true)) {
        throw new Exception('Only JPG, PNG or PDF proof files are allowed.');
    }

    $uploadDir = __DIR__ . '/uploads/platform_payments';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0775, true);
    }

    $newName = 'platform_payment_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
    $target = $uploadDir . '/' . $newName;

    if (!move_uploaded_file($file['tmp_name'], $target)) {
        throw new Exception('Unable to save payment proof.');
    }

    return 'uploads/platform_payments/' . $newName;
}

pp_ensure_table($pdo);


function pp_payment_by_id(PDO $pdo, int $paymentId, int $apartmentId): ?array {
    $stmt = $pdo->prepare("
        SELECT pp.*, a.apartment_name
        FROM platform_payments pp
        LEFT JOIN apartments a ON a.id = pp.apartment_id
        WHERE pp.id = ?
        AND pp.apartment_id = ?
        LIMIT 1
    ");
    $stmt->execute([$paymentId, $apartmentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function pp_download_receipt(PDO $pdo, int $paymentId, int $apartmentId): void {
    $payment = pp_payment_by_id($pdo, $paymentId, $apartmentId);

    if (!$payment) {
        http_response_code(404);
        echo 'Receipt not found.';
        exit;
    }

    $receiptNo = 'SVMS-RCPT-' . str_pad((string)$payment['id'], 6, '0', STR_PAD_LEFT);
    $apartment = $payment['apartment_name'] ?: 'Apartment';
    $billingMonth = pp_month_label($payment['billing_month'] ?? '');
    $amount = number_format((float)($payment['amount'] ?? 0), 2);
    $method = $payment['payment_method'] ?: '-';
    $transactionRef = $payment['transaction_ref'] ?: '-';
    $submittedAt = pp_dt($payment['submitted_at'] ?? null);
    $status = strtoupper((string)($payment['status'] ?? 'submitted'));

    $content = "SMARTVMS PLATFORM PAYMENT RECEIPT\n";
    $content .= "=================================\n\n";
    $content .= "Receipt No       : {$receiptNo}\n";
    $content .= "Apartment        : {$apartment}\n";
    $content .= "Billing Month    : {$billingMonth}\n";
    $content .= "Amount           : RM {$amount}\n";
    $content .= "Payment Method   : {$method}\n";
    $content .= "Transaction Ref  : {$transactionRef}\n";
    $content .= "Submitted At     : {$submittedAt}\n";
    $content .= "Status           : {$status}\n\n";
    $content .= "Note: This receipt is generated by SmartVMS for FYP demonstration.\n";
    $content .= "The payment is submitted to Superadmin for review.\n";

    $fileName = 'SmartVMS_Receipt_' . str_pad((string)$payment['id'], 6, '0', STR_PAD_LEFT) . '.txt';

    header('Content-Type: text/plain; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Content-Length: ' . strlen($content));
    echo $content;
    exit;
}

function pp_render_gateway_page(string $view, array $payment, string $bankName, string $month): void {
    $isSuccess = $view === 'success';
    $paymentId = (int)($payment['id'] ?? 0);
    $amount = number_format((float)($payment['amount'] ?? 300), 2);
    $transactionRef = $payment['transaction_ref'] ?? '-';
    $billingLabel = pp_month_label($payment['billing_month'] ?? '');
    $bankLabel = $bankName !== '' ? $bankName : 'Selected Bank';
    $bankLower = strtolower($bankLabel);

    if (str_contains($bankLower, 'public')) {
        $bankLogoType = 'public';
    } elseif (str_contains($bankLower, 'maybank')) {
        $bankLogoType = 'maybank';
    } elseif (str_contains($bankLower, 'cimb')) {
        $bankLogoType = 'cimb';
    } elseif (str_contains($bankLower, 'hong leong')) {
        $bankLogoType = 'hongleong';
    } elseif (str_contains($bankLower, 'rhb')) {
        $bankLogoType = 'rhb';
    } elseif (str_contains($bankLower, 'touch') || str_contains($bankLower, 'tng') || str_contains($bankLower, 'e-wallet') || str_contains($bankLower, 'ewallet')) {
        $bankLogoType = 'tng';
    } else {
        $bankLogoType = 'generic';
    }

    $successUrl = 'admin_platform_payment.php?gateway=success&payment_id=' . urlencode((string)$paymentId) . '&month=' . urlencode($month) . '&bank=' . urlencode($bankLabel);
    $backUrl = 'admin_platform_payment.php?month=' . urlencode($month);
    $receiptUrl = 'admin_platform_payment.php?download_receipt=1&id=' . urlencode((string)$paymentId);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title><?= $isSuccess ? 'Payment Successful' : 'Processing Payment' ?> | SmartVMS</title>
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <?php if (!$isSuccess): ?>
            <meta http-equiv="refresh" content="2;url=<?= htmlspecialchars($successUrl, ENT_QUOTES, 'UTF-8') ?>">
        <?php endif; ?>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
        <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
        <style>
            * { box-sizing: border-box; }

            body {
                margin: 0;
                min-height: 100vh;
                display: grid;
                place-items: center;
                font-family: 'Plus Jakarta Sans', system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
                color: #0f172a;
                background:
                    radial-gradient(circle at top right, rgba(220, 38, 38, .16), transparent 34%),
                    linear-gradient(180deg, #ffffff, #f4f6fa);
                padding: 24px;
            }

            .gateway-card {
                width: min(620px, 94vw);
                border: 1px solid #e5e7eb;
                border-radius: 28px;
                background: rgba(255, 255, 255, .98);
                box-shadow: 0 26px 60px rgba(15, 23, 42, .14);
                overflow: hidden;
            }

            .gateway-head {
                padding: 28px 30px;
                background:
                    radial-gradient(circle at 12% 0%, rgba(248, 113, 113, .20), transparent 26%),
                    linear-gradient(135deg, #fff7f7, #ffffff);
                border-bottom: 1px solid #e5e7eb;
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 18px;
            }

            .brand {
                display: flex;
                align-items: center;
                gap: 12px;
                font-weight: 950;
                font-size: 1.16rem;
            }

            .brand-icon {
                width: 46px;
                height: 46px;
                border-radius: 16px;
                display: grid;
                place-items: center;
                color: #ffffff;
                background: linear-gradient(135deg, #dc2626, #b91c1c);
                box-shadow: 0 14px 26px rgba(220, 38, 38, .22);
            }

            .gateway-body {
                padding: 34px 32px 32px;
                text-align: center;
            }

            .bank-logo {
                margin: 0 auto 24px;
                width: 210px;
                height: 138px;
                border-radius: 18px;
                background: #ffffff;
                border: 1px solid #e5e7eb;
                display: grid;
                place-items: center;
                box-shadow: 0 18px 36px rgba(15, 23, 42, .08);
            }

            .bank-logo-inner {
                width: 100%;
                text-align: center;
            }

            .public-logo-mark {
                width: 72px;
                height: 72px;
                border-radius: 16px;
                background: #ef4444;
                transform: rotate(45deg);
                position: relative;
                margin: 0 auto 15px;
            }

            .public-logo-mark::before {
                content: "";
                position: absolute;
                inset: 17px;
                border: 9px solid #ffffff;
                border-radius: 10px;
            }

            .public-logo-mark::after {
                content: "";
                position: absolute;
                width: 17px;
                height: 17px;
                background: #ffffff;
                border-radius: 50%;
                left: 28px;
                top: 28px;
            }

            .public-logo-text,
            .bank-logo-text {
                font-size: 1.18rem;
                font-weight: 950;
                letter-spacing: .04em;
                text-transform: uppercase;
            }

            .public-logo-text {
                color: #ef4444;
            }

            .maybank-logo {
                width: 126px;
                height: 62px;
                border-radius: 999px;
                background: #facc15;
                border: 5px solid #111827;
                margin: 0 auto 14px;
                position: relative;
                overflow: hidden;
            }

            .maybank-logo::before {
                content: "";
                position: absolute;
                left: 22px;
                top: 16px;
                width: 82px;
                height: 28px;
                border-radius: 50%;
                background:
                    linear-gradient(90deg, transparent 0 18%, #111827 18% 24%, transparent 24% 35%, #111827 35% 41%, transparent 41% 52%, #111827 52% 58%, transparent 58% 100%);
                transform: skewX(-18deg);
                opacity: .95;
            }

            .maybank-text {
                color: #111827;
            }

            .cimb-logo {
                width: 86px;
                height: 86px;
                border-radius: 20px;
                background: #b91c1c;
                margin: 0 auto 14px;
                position: relative;
                transform: rotate(45deg);
                box-shadow: inset 0 0 0 10px rgba(255,255,255,.18);
            }

            .cimb-logo::before,
            .cimb-logo::after {
                content: "";
                position: absolute;
                background: #ffffff;
            }

            .cimb-logo::before {
                width: 42px;
                height: 18px;
                left: 22px;
                top: 34px;
                border-radius: 4px;
            }

            .cimb-logo::after {
                width: 18px;
                height: 42px;
                left: 34px;
                top: 22px;
                border-radius: 4px;
            }

            .cimb-text {
                color: #b91c1c;
            }

            .rhb-logo {
                width: 92px;
                height: 92px;
                border-radius: 24px;
                background: linear-gradient(135deg, #1d4ed8, #1e3a8a);
                margin: 0 auto 14px;
                display: grid;
                place-items: center;
                color: #ffffff;
                font-size: 2rem;
                font-weight: 950;
                box-shadow: 0 14px 28px rgba(29,78,216,.22);
            }

            .rhb-text {
                color: #1d4ed8;
            }

            .hongleong-logo {
                width: 96px;
                height: 96px;
                border-radius: 28px;
                background: #ffffff;
                border: 6px solid #1d4ed8;
                margin: 0 auto 14px;
                position: relative;
            }

            .hongleong-logo::before {
                content: "";
                position: absolute;
                inset: 15px;
                border-radius: 18px;
                background: #dc2626;
                transform: rotate(45deg);
            }

            .hongleong-logo::after {
                content: "HL";
                position: absolute;
                inset: 0;
                display: grid;
                place-items: center;
                color: #ffffff;
                font-size: 1.55rem;
                font-weight: 950;
            }

            .hongleong-text {
                color: #1d4ed8;
            }


            .bank-logo.tng-bank-logo-clean {
                width: auto;
                height: auto;
                border: 0;
                background: transparent;
                box-shadow: none;
                border-radius: 0;
                margin-bottom: 24px;
            }

            .bank-logo.tng-bank-logo-clean .tng-logo {
                margin-bottom: 10px;
            }

            .bank-logo.tng-bank-logo-clean .tng-text {
                font-size: 1.18rem;
                line-height: 1.12;
            }


            .tng-logo {
                width: 92px;
                height: 92px;
                border-radius: 28px;
                background: linear-gradient(135deg, #0077c8, #005baa);
                margin: 0 auto 14px;
                display: grid;
                place-items: center;
                color: #ffffff;
                font-size: 1.1rem;
                font-weight: 950;
                box-shadow: 0 14px 28px rgba(0, 91, 170, .22);
                position: relative;
                overflow: hidden;
            }

            .tng-logo::before {
                content: "";
                position: absolute;
                width: 92px;
                height: 92px;
                border: 8px solid rgba(255,255,255,.32);
                border-radius: 50%;
                left: -35px;
                bottom: -35px;
            }

            .tng-logo span {
                position: relative;
                z-index: 2;
            }

            .tng-text {
                color: #005baa;
            }

            .generic-bank-logo {
                width: 78px;
                height: 78px;
                border-radius: 22px;
                display: grid;
                place-items: center;
                margin: 0 auto 14px;
                color: #ffffff;
                background: linear-gradient(135deg, #0284c7, #0369a1);
                font-size: 2rem;
            }

            .generic-text {
                color: #0369a1;
            }

            .status-icon {
                width: 72px;
                height: 72px;
                border-radius: 50%;
                display: grid;
                place-items: center;
                margin: 0 auto 18px;
                color: #ffffff;
                font-size: 2rem;
                background: #22c55e;
                box-shadow: 0 16px 34px rgba(34, 197, 94, .26);
            }

            .spinner {
                width: 70px;
                height: 70px;
                border-radius: 50%;
                border: 7px solid #fee2e2;
                border-top-color: #dc2626;
                margin: 0 auto 18px;
                animation: spin 1s linear infinite;
            }

            @keyframes spin {
                to { transform: rotate(360deg); }
            }

            h1 {
                margin: 0;
                font-size: 1.72rem;
                line-height: 1.1;
                letter-spacing: -.05em;
                font-weight: 950;
            }

            .sub {
                margin: 10px auto 0;
                max-width: 430px;
                color: #64748b;
                font-weight: 800;
                line-height: 1.55;
                font-size: .9rem;
            }

            .summary {
                margin: 24px auto 0;
                max-width: 440px;
                border: 1px solid #e5e7eb;
                border-radius: 18px;
                background: #f8fafc;
                text-align: left;
                padding: 16px 18px;
            }

            .row {
                display: flex;
                justify-content: space-between;
                gap: 18px;
                padding: 8px 0;
                border-bottom: 1px solid #e5e7eb;
                font-size: .84rem;
                font-weight: 850;
            }

            .row:last-child { border-bottom: 0; }

            .row span:first-child { color: #64748b; }

            .row span:last-child {
                color: #0f172a;
                font-weight: 950;
                text-align: right;
            }

            .actions {
                display: flex;
                align-items: center;
                justify-content: center;
                flex-wrap: wrap;
                gap: 10px;
                margin-top: 24px;
            }

            .btn {
                height: 44px;
                border-radius: 14px;
                border: 0;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
                padding: 0 18px;
                font-family: inherit;
                font-weight: 950;
                text-decoration: none;
                cursor: pointer;
            }

            .btn-primary {
                color: #ffffff;
                background: linear-gradient(135deg, #dc2626, #b91c1c);
                box-shadow: 0 14px 28px rgba(220, 38, 38, .20);
            }

            .btn-light {
                color: #0f172a;
                background: #ffffff;
                border: 1px solid #e5e7eb;
            }

            .small-note {
                margin-top: 18px;
                color: #64748b;
                font-size: .75rem;
                font-weight: 800;
            }
        </style>
    </head>
    <body>
        <section class="gateway-card">
            <div class="gateway-head">
                <div class="brand">
                    <div class="brand-icon">
                        <i class="fas fa-shield-halved"></i>
                    </div>
                    Smart<span style="color:#dc2626;">VMS</span>
                </div>

                <strong><?= htmlspecialchars($bankLabel, ENT_QUOTES, 'UTF-8') ?></strong>
            </div>

            <div class="gateway-body">
                <div class="bank-logo <?= $bankLogoType === 'tng' ? 'tng-bank-logo-clean' : '' ?>">
                    <div class="bank-logo-inner">
                        <?php if ($bankLogoType === 'public'): ?>
                            <div class="public-logo-mark"></div>
                            <div class="public-logo-text">Public Bank</div>
                        <?php elseif ($bankLogoType === 'maybank'): ?>
                            <div class="maybank-logo"></div>
                            <div class="bank-logo-text maybank-text">Maybank</div>
                        <?php elseif ($bankLogoType === 'cimb'): ?>
                            <div class="cimb-logo"></div>
                            <div class="bank-logo-text cimb-text">CIMB Bank</div>
                        <?php elseif ($bankLogoType === 'rhb'): ?>
                            <div class="rhb-logo">RHB</div>
                            <div class="bank-logo-text rhb-text">RHB Bank</div>
                        <?php elseif ($bankLogoType === 'hongleong'): ?>
                            <div class="hongleong-logo"></div>
                            <div class="bank-logo-text hongleong-text">Hong Leong Bank</div>
                        <?php elseif ($bankLogoType === 'tng'): ?>
                            <div class="tng-logo"><span>TNG</span></div>
                            <div class="bank-logo-text tng-text">Touch 'n Go eWallet</div>
                        <?php else: ?>
                            <div class="generic-bank-logo">
                                <i class="fas fa-building-columns"></i>
                            </div>
                            <div class="bank-logo-text generic-text"><?= htmlspecialchars($bankLabel, ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($isSuccess): ?>
                    <div class="status-icon">
                        <i class="fas fa-check"></i>
                    </div>

                    <h1>Payment Successful</h1>
                    <p class="sub">
                        Your platform payment has been submitted successfully. You can download the receipt below.
                    </p>
                <?php else: ?>
                    <div class="spinner"></div>

                    <h1>Processing Payment</h1>
                    <p class="sub">
                        Please wait while <?= htmlspecialchars($bankLabel, ENT_QUOTES, 'UTF-8') ?> verifies your payment. This will take about 2 seconds.
                    </p>
                <?php endif; ?>

                <div class="summary">
                    <div class="row">
                        <span>Billing Month</span>
                        <span><?= htmlspecialchars($billingLabel, ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                    <div class="row">
                        <span>Amount</span>
                        <span>RM <?= htmlspecialchars($amount, ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                    <div class="row">
                        <span>Reference No</span>
                        <span><?= htmlspecialchars($transactionRef, ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                </div>

                <?php if ($isSuccess): ?>
                    <div class="actions">
                        <a class="btn btn-primary" href="<?= htmlspecialchars($receiptUrl, ENT_QUOTES, 'UTF-8') ?>">
                            <i class="fas fa-download"></i>
                            Download Receipt
                        </a>
                        <a class="btn btn-light" href="<?= htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') ?>">
                            Back to Payment
                        </a>
                    </div>
                <?php else: ?>
                    <div class="small-note">
                        Do not close this window while the payment is loading.
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </body>
    </html>
    <?php
    exit;
}

$apartmentId = pp_current_apartment_id($pdo);
$currentApartmentName = pp_current_apartment_name($pdo, $apartmentId);
$currentApartmentLabel = 'Apartment';

if (isset($_GET['download_receipt'])) {
    pp_download_receipt($pdo, (int)($_GET['id'] ?? 0), $apartmentId);
}

if (isset($_GET['gateway'])) {
    $gatewayView = (string)$_GET['gateway'];
    $gatewayPaymentId = (int)($_GET['payment_id'] ?? 0);
    $gatewayPayment = pp_payment_by_id($pdo, $gatewayPaymentId, $apartmentId);

    if ($gatewayPayment && in_array($gatewayView, ['processing', 'success'], true)) {
        pp_render_gateway_page(
            $gatewayView,
            $gatewayPayment,
            trim((string)($_GET['bank'] ?? '')),
            pp_safe_month($_GET['month'] ?? date('Y-m'))
        );
    }
}

$selectedMonth = pp_safe_month($_GET['month'] ?? date('Y-m'));
$billingDate = pp_month_date($selectedMonth);

pp_ensure_payment($pdo, $apartmentId, $billingDate, 300.00);

$successMessage = '';
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $postMonth = pp_safe_month($_POST['billing_month'] ?? date('Y-m'));
        $postBillingDate = pp_month_date($postMonth);

        pp_ensure_payment($pdo, $apartmentId, $postBillingDate, 300.00);

        $method = trim((string)($_POST['payment_method'] ?? ''));
        $transactionRef = trim((string)($_POST['transaction_ref'] ?? ''));
        $note = trim((string)($_POST['note'] ?? ''));
        $cardBank = trim((string)($_POST['card_bank_demo'] ?? ''));
        $onlineBank = trim((string)($_POST['online_bank_demo'] ?? ''));
        $ewalletPhone = trim((string)($_POST['ewallet_phone_demo'] ?? ''));

        if ($method === '') {
            throw new Exception('Please select payment method.');
        }

        if (empty($_POST['authorize_payment'])) {
            throw new Exception('Please tick the authorization checkbox before proceeding.');
        }

        if ($transactionRef === '') {
            $transactionRef = 'SVMS' . date('Ym', strtotime($postBillingDate)) . str_pad((string)$apartmentId, 4, '0', STR_PAD_LEFT) . date('His');
        }

        $payment = pp_fetch_payment($pdo, $apartmentId, $postBillingDate);
        $status = strtolower((string)($payment['status'] ?? 'unpaid'));

        if ($status === 'paid') {
            throw new Exception('This month is already marked as paid.');
        }

        $proofFile = pp_upload_proof();
        $proofSql = $proofFile ? ', proof_file = ?' : '';

        $bankForGateway = '';

        if ($method === 'Online Banking') {
            $bankForGateway = $onlineBank;
        } elseif ($method === 'Credit / Debit Card') {
            $bankForGateway = $cardBank;
        } elseif ($method === 'E-Wallet') {
            $bankForGateway = "Touch 'n Go eWallet";
        }

        $methodForDb = $method;
        if ($bankForGateway !== '') {
            $methodForDb .= ' - ' . $bankForGateway;
        }

        $params = [
            $methodForDb,
            $transactionRef,
            $note,
            (int)($_SESSION['uid'] ?? 0),
        ];

        if ($proofFile) {
            $params[] = $proofFile;
        }

        $params[] = $apartmentId;
        $params[] = $postBillingDate;

        $stmt = $pdo->prepare("
            UPDATE platform_payments
            SET
                status = 'submitted',
                payment_method = ?,
                transaction_ref = ?,
                note = ?,
                submitted_by = ?,
                submitted_at = NOW()
                {$proofSql}
            WHERE apartment_id = ?
            AND billing_month = ?
        ");
        $stmt->execute($params);

        $stmt = $pdo->prepare("
            SELECT id, transaction_ref, payment_method
            FROM platform_payments
            WHERE apartment_id = ?
            AND billing_month = ?
            LIMIT 1
        ");
        $stmt->execute([$apartmentId, $postBillingDate]);
        $submittedPayment = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $paymentId = (int)($submittedPayment['id'] ?? 0);
        $bankForGateway = $bankForGateway !== '' ? $bankForGateway : 'Selected Bank';

        header(
            'Location: admin_platform_payment.php?gateway=processing'
            . '&payment_id=' . urlencode((string)$paymentId)
            . '&month=' . urlencode($postMonth)
            . '&bank=' . urlencode($bankForGateway)
        );
        exit;
    } catch (Throwable $e) {
        $errorMessage = $e->getMessage();
    }
}

if (isset($_GET['success'])) {
    $successMessage = 'Payment submitted to Superadmin successfully.';
}

$currentPayment = pp_fetch_payment($pdo, $apartmentId, $billingDate);
$currentVisualStatus = pp_visual_status($currentPayment);
$dueDate = pp_due_date($billingDate);

$stmt = $pdo->prepare("
    SELECT *
    FROM platform_payments
    WHERE apartment_id = ?
    ORDER BY billing_month DESC
    LIMIT 24
");
$stmt->execute([$apartmentId]);
$history = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$totalPaid = 0.0;
$unpaidCount = 0;
$submittedCount = 0;

foreach ($history as $item) {
    $status = strtolower((string)$item['status']);

    if ($status === 'paid') {
        $totalPaid += (float)$item['amount'];
    }

    if (pp_visual_status($item) === 'overdue' || $status === 'unpaid' || $status === 'rejected') {
        $unpaidCount++;
    }

    if ($status === 'submitted') {
        $submittedCount++;
    }
}

$adminInitial = strtoupper(substr(trim((string)($_SESSION['email'] ?? 'A')), 0, 1)) ?: 'A';
$canSubmit = !in_array(strtolower((string)($currentPayment['status'] ?? 'unpaid')), ['paid', 'submitted'], true);
$paymentRef = 'SVMS' . date('Ym', strtotime($billingDate)) . str_pad((string)$apartmentId, 4, '0', STR_PAD_LEFT) . str_pad((string)($currentPayment['id'] ?? 0), 5, '0', STR_PAD_LEFT);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Platform Payment | SmartVMS</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary: #dc2626;
            --primary-dark: #b91c1c;
            --soft: #fff1f2;
            --line: #e5e7eb;
            --text: #0f172a;
            --muted: #64748b;
            --shadow: 0 20px 45px rgba(15, 23, 42, .08);
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            height: 100vh;
            overflow: hidden;
            font-family: 'Plus Jakarta Sans', system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            color: var(--text);
            background:
                radial-gradient(circle at top right, rgba(220, 38, 38, .12), transparent 34%),
                linear-gradient(180deg, #ffffff, #f4f6fa);
            font-size: .88rem;
        }

        .dashboard-shell {
            display: grid;
            grid-template-columns: 260px minmax(0, 1fr);
            height: 100vh;
            overflow: hidden;
        }

        .main-content {
            padding: 24px 28px 26px;
            min-width: 0;
            height: 100vh;
            overflow: auto;
        }

        .topbar {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 18px;
            margin-bottom: 18px;
        }

        .eyebrow {
            color: var(--primary);
            text-transform: uppercase;
            font-size: .72rem;
            font-weight: 950;
            letter-spacing: .12em;
            margin-bottom: 5px;
        }

        h1 {
            margin: 0;
            font-size: 1.85rem;
            line-height: 1.05;
            letter-spacing: -.07em;
            font-weight: 950;
        }

        .page-sub {
            margin: 8px 0 0;
            color: #475569;
            font-size: .88rem;
            font-weight: 800;
            line-height: 1.45;
        }

        .top-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .top-btn,
        .profile-dot {
            height: 42px;
            border-radius: 999px;
            border: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            font-weight: 950;
            font-family: inherit;
        }

        .top-btn {
            padding: 0 17px;
            gap: 8px;
            color: #ffffff;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            box-shadow: 0 14px 28px rgba(220, 38, 38, .22);
        }

        .profile-dot {
            width: 42px;
            color: #ffffff;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            box-shadow: 0 14px 28px rgba(220, 38, 38, .22);
        }

        .hero {
            min-height: 160px;
            border: 1px solid var(--line);
            border-radius: 24px;
            background:
                radial-gradient(circle at 9% 4%, rgba(248, 113, 113, .22), transparent 20%),
                linear-gradient(115deg, #fff7f7 0%, #ffffff 45%, #fff1f2 100%);
            box-shadow: var(--shadow);
            padding: 22px 28px;
            display: grid;
            grid-template-columns: minmax(360px, 1fr) 360px;
            gap: 20px;
            align-items: center;
            margin-bottom: 16px;
            overflow: hidden;
        }

        .hero-title {
            color: var(--primary);
            text-transform: uppercase;
            font-size: .75rem;
            font-weight: 950;
            letter-spacing: .13em;
            margin-bottom: 8px;
        }

        .hero-amount {
            font-size: 3.35rem;
            line-height: .95;
            letter-spacing: -.08em;
            font-weight: 950;
            margin-bottom: 8px;
        }

        .hero-note {
            color: #475569;
            font-weight: 850;
            line-height: 1.45;
            max-width: 520px;
        }

        .hero-visual {
            height: 135px;
            position: relative;
        }

        .receipt {
            position: absolute;
            right: 80px;
            top: 6px;
            width: 118px;
            height: 122px;
            border: 6px solid #0f172a;
            border-radius: 22px;
            background: #ffffff;
            box-shadow: 0 18px 30px rgba(15,23,42,.14);
        }

        .receipt::before,
        .receipt::after {
            content: "";
            position: absolute;
            left: 24px;
            right: 24px;
            height: 8px;
            border-radius: 99px;
            background: #cbd5e1;
        }

        .receipt::before { top: 40px; }
        .receipt::after { top: 62px; }

        .rm-badge {
            position: absolute;
            right: 170px;
            top: 38px;
            width: 52px;
            height: 52px;
            border-radius: 16px;
            display: grid;
            place-items: center;
            color: #ffffff;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            font-weight: 950;
            box-shadow: 0 14px 28px rgba(220,38,38,.24);
        }

        .check-badge {
            position: absolute;
            right: 46px;
            bottom: 14px;
            width: 56px;
            height: 56px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            color: #ffffff;
            background: #22c55e;
            border: 6px solid #ffffff;
            box-shadow: 0 14px 28px rgba(34,197,94,.25);
            font-size: 1.35rem;
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 8px 12px;
            border-radius: 999px;
            font-size: .72rem;
            font-weight: 950;
            text-transform: uppercase;
            letter-spacing: .02em;
            white-space: nowrap;
        }

        .status-pill.paid {
            color: #047857;
            background: #d1fae5;
        }

        .status-pill.submitted {
            color: #1d4ed8;
            background: #dbeafe;
        }

        .status-pill.unpaid {
            color: #b45309;
            background: #ffedd5;
        }

        .status-pill.overdue,
        .status-pill.rejected {
            color: #b91c1c;
            background: #fee2e2;
        }

        .grid {
            display: grid;
            grid-template-columns: minmax(0, .9fr) minmax(0, 1.1fr);
            gap: 16px;
            align-items: start;
        }

        .panel {
            border: 1px solid var(--line);
            border-radius: 24px;
            background: rgba(255,255,255,.98);
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .panel-head {
            padding: 16px 18px;
            border-bottom: 1px solid var(--line);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
        }

        .panel-title {
            font-weight: 950;
            font-size: 1rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .panel-title i {
            color: var(--primary);
        }

        .panel-body {
            padding: 18px;
        }

        .month-form {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 10px;
            margin-bottom: 14px;
        }

        label {
            display: block;
            color: #64748b;
            text-transform: uppercase;
            font-size: .66rem;
            font-weight: 950;
            letter-spacing: .07em;
            margin-bottom: 7px;
        }

        .input,
        .select,
        .textarea {
            width: 100%;
            border-radius: 15px;
            border: 1px solid var(--line);
            background: #ffffff;
            padding: 0 14px;
            font-family: inherit;
            font-weight: 850;
            outline: none;
            color: #0f172a;
        }

        .input,
        .select {
            height: 46px;
        }

        .textarea {
            min-height: 82px;
            padding-top: 12px;
            resize: vertical;
        }

        .input:focus,
        .select:focus,
        .textarea:focus {
            border-color: #fca5a5;
            box-shadow: 0 0 0 4px #fee2e2;
        }

        .btn {
            height: 46px;
            border-radius: 15px;
            border: 1px solid var(--line);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-decoration: none;
            font-family: inherit;
            font-weight: 950;
            cursor: pointer;
            padding: 0 16px;
            background: #ffffff;
            color: #64748b;
        }

        .btn-primary {
            border: 0;
            color: #ffffff;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            box-shadow: 0 14px 28px rgba(220, 38, 38, .18);
        }

        .btn-primary:disabled {
            opacity: .55;
            cursor: not-allowed;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            gap: 18px;
            padding: 13px 0;
            border-bottom: 1px solid #edf2f7;
        }

        .detail-row:last-child {
            border-bottom: 0;
        }

        .detail-label {
            color: #64748b;
            font-weight: 850;
        }

        .detail-value {
            text-align: right;
            font-weight: 950;
        }

        .form-grid {
            display: grid;
            gap: 13px;
        }

        .message {
            margin-bottom: 14px;
            border-radius: 16px;
            padding: 13px 14px;
            font-weight: 850;
        }

        .message.success {
            color: #047857;
            background: #d1fae5;
            border: 1px solid #a7f3d0;
        }

        .message.error {
            color: #b91c1c;
            background: #fee2e2;
            border: 1px solid #fecaca;
        }

        .help-text {
            margin-top: 8px;
            color: #64748b;
            font-size: .74rem;
            font-weight: 750;
            line-height: 1.4;
        }

        .table-wrap {
            overflow: auto;
            max-height: 520px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 760px;
        }

        th {
            text-align: left;
            padding: 12px 14px;
            background: #f8fafc;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: .06em;
            font-size: .66rem;
            font-weight: 950;
            border-bottom: 1px solid var(--line);
        }

        td {
            padding: 13px 14px;
            border-bottom: 1px solid #edf2f7;
            font-size: .8rem;
            font-weight: 800;
            vertical-align: middle;
        }

        .proof-link {
            color: var(--primary);
            font-weight: 950;
            text-decoration: none;
        }

        .empty {
            padding: 42px 18px;
            text-align: center;
            color: #64748b;
            font-weight: 850;
        }



        /* Current bill is now the main page content. Gateway opens only as popup. */
        .grid {
            display: block;
        }

        .grid > .panel:first-child {
            min-height: calc(100vh - 330px);
        }

        .grid > .panel:first-child .panel-body {
            padding: 22px 26px 28px;
        }

        .grid > .panel:first-child .detail-row {
            max-width: 920px;
            margin: 0 auto;
            padding: 16px 0;
        }

        .bill-actions {
            max-width: 920px;
            margin: 24px auto 0;
            padding-top: 18px;
            border-top: 1px solid #edf2f7;
        }

        .pay-now-btn {
            width: 100%;
            height: 54px;
            border: 0;
            border-radius: 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            color: #ffffff;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            box-shadow: 0 16px 30px rgba(220, 38, 38, .22);
            font-family: inherit;
            font-weight: 950;
            cursor: pointer;
        }

        .pay-now-btn.disabled {
            cursor: not-allowed;
            opacity: .72;
            background: #94a3b8;
            box-shadow: none;
        }

        .payment-modal {
            position: fixed;
            inset: 0;
            z-index: 2000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 28px;
            background: rgba(15, 23, 42, .56);
            backdrop-filter: blur(8px);
        }

        .payment-modal.open {
            display: flex;
        }

        .payment-modal-card {
            width: min(1120px, 96vw);
            max-height: 92vh;
            animation: modalIn .18s ease both;
        }

        @keyframes modalIn {
            from {
                opacity: 0;
                transform: translateY(12px) scale(.985);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .gateway-panel {
            max-height: 92vh;
            display: flex;
            flex-direction: column;
            border-radius: 24px;
            overflow: hidden;
        }

        .gateway-panel .panel-body {
            overflow: auto;
            padding: 18px;
        }

        .modal-close-btn {
            width: 42px;
            height: 42px;
            border: 0;
            border-radius: 14px;
            display: grid;
            place-items: center;
            color: #ffffff;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            box-shadow: 0 14px 28px rgba(220, 38, 38, .22);
            cursor: pointer;
        }





        .card-success-toast {
            border: 1px solid #bbf7d0;
            background: #dcfce7;
            color: #047857;
            border-radius: 12px;
            padding: 11px 14px;
            margin: 10px 0 12px;
            font-size: .82rem;
            font-weight: 950;
            display: flex;
            align-items: center;
            gap: 9px;
            box-shadow: 0 12px 24px rgba(34, 197, 94, .12);
        }


        .add-card-error {
            border: 1px solid #fecaca;
            background: #fff1f2;
            color: #b91c1c;
            border-radius: 10px;
            padding: 10px 12px;
            margin-bottom: 12px;
            font-size: .78rem;
            font-weight: 850;
            line-height: 1.45;
        }

        .gateway-input.input-error {
            border-color: #dc2626 !important;
            box-shadow: 0 0 0 3px #fee2e2 !important;
            background: #fff7f7 !important;
        }

        .gateway-input.input-ok {
            border-color: #22c55e !important;
        }



        .gpay-clickable {
            border: 0;
            cursor: pointer;
            font-family: inherit;
            transition: transform .16s ease, box-shadow .16s ease;
        }

        .gpay-clickable:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 22px rgba(15, 23, 42, .24);
        }

        .gpay-clickable.is-saved {
            outline: 3px solid #bbf7d0;
            outline-offset: 2px;
        }


        .saved-card-toolbar {
            display: flex;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
            margin: 4px 0 12px;
        }

        .saved-card-toolbar .gpay-box {
            margin: 0;
        }

        .add-card-btn {
            height: 42px;
            border: 1px solid #fecaca;
            border-radius: 12px;
            padding: 0 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            color: #b91c1c;
            background: #fff1f2;
            font-family: inherit;
            font-weight: 950;
            cursor: pointer;
        }

        .add-card-btn:hover {
            background: #fee2e2;
        }

        .saved-card-box {
            border: 1px dashed #cbd5e1;
            background: #f8fafc;
            border-radius: 12px;
            padding: 12px 14px;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .saved-card-box strong {
            display: block;
            color: #0f172a;
            font-size: .82rem;
            font-weight: 950;
            margin-bottom: 3px;
        }

        .saved-card-box span {
            display: block;
            color: #64748b;
            font-size: .76rem;
            font-weight: 800;
        }

        .saved-card-actions {
            display: flex;
            align-items: center;
            gap: 7px;
            flex-shrink: 0;
        }

        .saved-card-actions button {
            height: 32px;
            border: 0;
            border-radius: 999px;
            padding: 0 12px;
            color: #b91c1c;
            background: #fee2e2;
            font-family: inherit;
            font-weight: 950;
            cursor: pointer;
        }

        .saved-card-actions button:first-child {
            color: #047857;
            background: #d1fae5;
        }

        .add-card-box {
            border: 1px solid #bae6fd;
            background: #ffffff;
            box-shadow: 0 16px 34px rgba(15, 23, 42, .12);
            border-radius: 14px;
            padding: 14px;
            margin: 12px 0 14px;
        }

        .add-card-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
            color: #0f172a;
            font-weight: 950;
        }

        .add-card-head button {
            width: 34px;
            height: 34px;
            border: 0;
            border-radius: 10px;
            color: #ffffff;
            background: #dc2626;
            cursor: pointer;
        }

        .add-card-grid {
            display: grid;
            gap: 10px;
            max-width: 420px;
        }

        .add-card-two {
            display: grid;
            grid-template-columns: 1fr 120px;
            gap: 10px;
        }

        .save-new-card-btn {
            height: 40px;
            border: 0;
            border-radius: 999px;
            color: #ffffff;
            background: linear-gradient(135deg, #0284c7, #0369a1);
            font-family: inherit;
            font-weight: 950;
            cursor: pointer;
        }

        .gateway-note-card {
            margin-top: 10px;
            color: #64748b;
            background: #f8fafc;
            border: 1px dashed #cbd5e1;
            border-radius: 10px;
            padding: 9px 11px;
            font-size: .75rem;
            font-weight: 800;
            line-height: 1.4;
        }

        @media (max-width: 720px) {
            .saved-card-box {
                align-items: flex-start;
                flex-direction: column;
            }

            .add-card-two {
                grid-template-columns: 1fr;
            }
        }


        .gateway-box {
            border: 1px solid #bae6fd;
            border-radius: 4px;
            background: #ffffff;
            box-shadow: 0 6px 14px rgba(15, 23, 42, .08);
            padding: 18px 20px 20px;
        }

        .gateway-title {
            color: #0284c7;
            font-size: .95rem;
            font-weight: 950;
            margin-bottom: 12px;
            border-bottom: 1px solid #0284c7;
            padding-bottom: 5px;
        }

        .method-tabs {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
            margin: 8px 0 18px;
        }

        .method-card {
            min-height: 54px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            background: linear-gradient(180deg, #ffffff, #f8fafc);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            font-family: inherit;
            font-size: .92rem;
            font-weight: 950;
            color: #020617;
            cursor: pointer;
            box-shadow: inset 0 -2px 0 rgba(15,23,42,.06), 0 2px 4px rgba(15,23,42,.08);
            transition: .18s ease;
        }

        .method-card i {
            font-size: 1.28rem;
            color: #334155;
        }

        .method-card.active {
            border-color: #dc2626;
            background: #fff1f2;
            color: #b91c1c;
            box-shadow: 0 8px 18px rgba(220,38,38,.14);
        }

        .gateway-section-title {
            color: #0284c7;
            font-weight: 950;
            margin: 16px 0 10px;
            border-bottom: 1px solid #0284c7;
            padding-bottom: 5px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .transaction-summary {
            border: 1px solid #cbd5e1;
            border-top: 0;
            background: #f8fafc;
            box-shadow: 3px 4px 5px rgba(15,23,42,.14);
            padding: 12px 18px;
            margin-bottom: 14px;
        }

        .summary-row {
            display: grid;
            grid-template-columns: 190px 1fr;
            gap: 14px;
            padding: 5px 0;
            font-size: .82rem;
            font-weight: 850;
        }

        .summary-row .summary-label {
            color: #111827;
            font-weight: 950;
        }

        .summary-row .summary-value {
            color: #020617;
        }

        .summary-row .amount {
            color: #1e3a8a;
            font-style: italic;
            font-weight: 950;
            font-size: 1rem;
        }

        .gpay-box {
            width: 300px;
            height: 52px;
            border-radius: 6px;
            background: #050505;
            color: white;
            display: inline-flex;
            align-items: center;
            gap: 12px;
            padding: 0 18px;
            font-weight: 900;
            margin: 4px 0 22px 22px;
            box-shadow: 0 4px 8px rgba(15,23,42,.16);
        }

        .gpay-g {
            font-size: 1.35rem;
            font-weight: 950;
            color: #4285f4;
        }

        .gateway-form {
            display: grid;
            gap: 12px;
            max-width: 760px;
            margin: 0 auto;
        }

        .gateway-row {
            display: grid;
            grid-template-columns: 210px minmax(220px, 1fr) 180px;
            align-items: center;
            gap: 12px;
        }

        .gateway-label {
            color: #0f172a;
            font-weight: 950;
            font-size: .84rem;
        }

        .gateway-input,
        .gateway-select {
            height: 30px;
            border: 1px solid #9ca3af;
            border-radius: 2px;
            padding: 0 8px;
            font-family: Arial, sans-serif;
            font-size: .84rem;
            background: white;
        }


        .gateway-small-row .gateway-select[style*="width:100%"] {
            width: 100% !important;
        }

        .gateway-small-row {
            display: flex;
            gap: 7px;
        }

        .gateway-small-row select {
            width: 70px;
        }

        .gateway-example {
            color: #2563eb;
            font-size: .8rem;
            font-weight: 850;
            display: flex;
            align-items: center;
            gap: 5px;
        }



        .tng-wallet-box {
            background: #eff6ff;
            border-color: #bfdbfe;
        }

        .tng-head {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 14px;
        }

        .tng-mini-logo {
            width: 48px;
            height: 48px;
            border-radius: 16px;
            display: grid;
            place-items: center;
            color: #ffffff;
            background: linear-gradient(135deg, #0077c8, #005baa);
            font-weight: 950;
            box-shadow: 0 12px 24px rgba(0, 91, 170, .20);
            flex-shrink: 0;
        }

        .tng-head strong {
            display: block;
            color: #0f172a;
            font-size: .95rem;
            font-weight: 950;
            margin-bottom: 4px;
        }

        .tng-head span {
            display: block;
            color: #64748b;
            font-size: .76rem;
            font-weight: 800;
        }

        .tng-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }

        .tng-grid label {
            display: block;
            color: #334155;
            font-size: .7rem;
            font-weight: 950;
            text-transform: uppercase;
            letter-spacing: .04em;
            margin-bottom: 6px;
        }

        @media (max-width: 720px) {
            .tng-grid {
                grid-template-columns: 1fr;
            }
        }


        .online-banking-box {
            background: #f0f9ff;
        }

        .online-bank-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            margin-bottom: 14px;
        }

        .online-bank-head strong {
            display: block;
            color: #0f172a;
            font-size: .95rem;
            font-weight: 950;
            margin-bottom: 4px;
        }

        .online-bank-head span {
            display: block;
            color: #64748b;
            font-size: .76rem;
            font-weight: 800;
        }

        .online-bank-head i {
            width: 42px;
            height: 42px;
            border-radius: 14px;
            display: grid;
            place-items: center;
            color: #ffffff;
            background: linear-gradient(135deg, #0284c7, #0369a1);
            flex-shrink: 0;
        }

        .online-bank-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }

        .online-bank-grid label {
            display: block;
            color: #334155;
            font-size: .7rem;
            font-weight: 950;
            text-transform: uppercase;
            letter-spacing: .04em;
            margin-bottom: 6px;
        }

        .tac-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 120px;
            gap: 8px;
        }

        .request-tac-btn {
            height: 30px;
            border: 0;
            border-radius: 7px;
            color: #ffffff;
            background: #0284c7;
            font-family: inherit;
            font-weight: 900;
            cursor: pointer;
            font-size: .74rem;
        }

        .online-bank-note {
            margin-top: 12px;
            color: #0369a1;
            background: #ffffff;
            border: 1px dashed #7dd3fc;
            border-radius: 10px;
            padding: 9px 11px;
            font-size: .75rem;
            font-weight: 800;
            line-height: 1.4;
            display: flex;
            align-items: flex-start;
            gap: 8px;
        }

        .gateway-input.input-error,
        .gateway-select.input-error {
            border-color: #dc2626 !important;
            box-shadow: 0 0 0 3px #fee2e2 !important;
            background: #fff7f7 !important;
        }

        @media (max-width: 720px) {
            .online-bank-grid,
            .tac-row {
                grid-template-columns: 1fr;
            }
        }


        .method-extra {
            display: none;
            border: 1px solid #bae6fd;
            border-radius: 8px;
            background: #f0f9ff;
            padding: 14px;
            margin-top: 6px;
            color: #0f172a;
            font-weight: 850;
        }

        .method-extra.show {
            display: block;
        }

        .gateway-check {
            margin: 14px auto 0;
            max-width: 760px;
            display: flex;
            align-items: flex-start;
            gap: 9px;
            color: #0f172a;
            font-size: .8rem;
            line-height: 1.45;
        }

        .gateway-check input {
            margin-top: 3px;
            width: 16px;
            height: 16px;
        }

        .gateway-note {
            max-width: 760px;
            margin: 10px auto 0;
            color: red;
            font-style: italic;
            font-size: .78rem;
        }

        .gateway-actions {
            margin-top: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .proceed-btn,
        .cancel-btn {
            height: 34px;
            border-radius: 5px;
            border: 0;
            padding: 0 34px;
            font-family: inherit;
            font-weight: 950;
            cursor: pointer;
        }

        .proceed-btn {
            color: #ffffff;
            background: linear-gradient(180deg, #0284c7, #0369a1);
            box-shadow: inset 0 -2px 0 rgba(0,0,0,.12);
        }

        .cancel-btn {
            color: #ffffff;
            background: #334155;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
        }

        .timeout {
            text-align: center;
            font-weight: 950;
            color: #111827;
            margin: 14px 0 28px;
        }

        .secure-line {
            color: #64748b;
            font-size: .75rem;
            font-weight: 800;
            text-align: center;
            margin-top: 10px;
        }


        @media (max-width: 1180px) {
            body {
                height: auto;
                overflow: auto;
            }

            .dashboard-shell {
                grid-template-columns: 1fr;
                height: auto;
                overflow: visible;
            }

            .sidebar {
                display: none;
            }

            .main-content {
                height: auto;
                overflow: visible;
            }

            .hero {
                grid-template-columns: 1fr;
            }

            .payment-modal {
                padding: 14px;
                align-items: flex-start;
            }

            .payment-modal-card {
                max-height: 96vh;
                width: 100%;
            }

            .method-tabs {
                grid-template-columns: 1fr;
            }

            .gateway-row {
                grid-template-columns: 1fr;
                align-items: stretch;
            }

            .gpay-box {
                width: 100%;
                margin-left: 0;
            }


            .hero-visual {
                display: none;
            }
        }



        .month-form {
            display: block !important;
            max-width: 920px;
            width: 100%;
            margin: 0 auto 12px !important;
        }

        .month-form label {
            margin-bottom: 7px !important;
        }

        .month-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 96px;
            gap: 10px;
            align-items: center;
            width: 100%;
        }

        .month-row .input,
        .month-row .btn {
            height: 44px !important;
        }

        .month-row .btn {
            width: 96px;
            padding: 0;
            white-space: nowrap;
        }


        /* One screen layout: no page vertical scroll */
        body {
            height: 100vh !important;
            overflow: hidden !important;
        }

        .dashboard-shell {
            height: 100vh !important;
            overflow: hidden !important;
        }

        .main-content {
            height: 100vh !important;
            overflow: hidden !important;
            padding: 18px 28px 18px !important;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .topbar {
            margin-bottom: 0 !important;
            flex: 0 0 auto;
        }

        h1 {
            font-size: 1.62rem !important;
        }

        .page-sub {
            margin-top: 5px !important;
            font-size: .82rem !important;
        }

        .top-btn,
        .profile-dot {
            height: 38px !important;
        }

        .profile-dot {
            width: 38px !important;
        }

        .hero {
            min-height: 128px !important;
            margin-bottom: 0 !important;
            padding: 18px 24px !important;
            flex: 0 0 auto;
            grid-template-columns: minmax(340px, 1fr) 240px !important;
        }

        .hero-amount {
            font-size: 2.75rem !important;
            margin-bottom: 6px !important;
        }

        .hero-note {
            font-size: .82rem !important;
        }

        .hero-visual {
            height: 96px !important;
        }

        .receipt {
            width: 88px !important;
            height: 90px !important;
            right: 80px !important;
            top: 1px !important;
            border-width: 5px !important;
            border-radius: 18px !important;
        }

        .rm-badge {
            right: 146px !important;
            top: 30px !important;
            width: 44px !important;
            height: 44px !important;
            border-radius: 14px !important;
        }

        .check-badge {
            right: 48px !important;
            bottom: 4px !important;
            width: 48px !important;
            height: 48px !important;
            border-width: 5px !important;
        }

        .grid {
            flex: 1 1 auto;
            min-height: 0;
            margin: 0 !important;
        }

        .grid > .panel:first-child {
            min-height: 0 !important;
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        .panel-head {
            padding: 12px 16px !important;
            flex: 0 0 auto;
        }

        .grid > .panel:first-child .panel-body {
            flex: 1 1 auto;
            min-height: 0;
            padding: 14px 22px 16px !important;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .month-form {
            display: block !important;
            max-width: 920px;
            width: 100%;
            margin: 0 auto 12px !important;
        }

        .input,
        .select {
            height: 40px !important;
        }

        .btn {
            height: 40px !important;
        }

        .grid > .panel:first-child .detail-row {
            max-width: 920px !important;
            width: 100%;
            padding: 9px 0 !important;
        }

        .bill-actions {
            max-width: 920px !important;
            width: 100%;
            margin: 14px auto 0 !important;
            padding-top: 12px !important;
        }

        .pay-now-btn {
            height: 46px !important;
        }

        .bill-actions .help-text {
            margin-top: 6px !important;
            font-size: .72rem !important;
        }


        /* Bigger Current Bill content - keep all existing payment functions */
        .grid > .panel:first-child .panel-body {
            padding: 30px 46px 34px !important;
            justify-content: flex-start !important;
        }

        .month-form {
            max-width: 1180px !important;
            margin: 18px auto 20px !important;
        }

        .month-form label {
            font-size: .74rem !important;
            margin-bottom: 9px !important;
        }

        .month-row {
            grid-template-columns: minmax(0, 1fr) 118px !important;
            gap: 14px !important;
        }

        .month-row .input,
        .month-row .btn {
            height: 52px !important;
            border-radius: 17px !important;
            font-size: .92rem !important;
        }

        .month-row .btn {
            width: 118px !important;
        }

        .grid > .panel:first-child .detail-row {
            max-width: 1180px !important;
            padding: 15px 0 !important;
            font-size: .94rem !important;
        }

        .grid > .panel:first-child .detail-label {
            font-size: .94rem !important;
            font-weight: 900 !important;
        }

        .grid > .panel:first-child .detail-value {
            font-size: .96rem !important;
            font-weight: 950 !important;
        }

        .bill-actions {
            max-width: 1180px !important;
            margin: 22px auto 0 !important;
            padding-top: 18px !important;
        }

        .pay-now-btn {
            height: 56px !important;
            border-radius: 17px !important;
            font-size: .92rem !important;
        }

        .bill-actions .help-text {
            font-size: .78rem !important;
            margin-top: 9px !important;
        }


        @media (max-width: 1180px) {
            body {
                height: auto !important;
                overflow: auto !important;
            }

            .dashboard-shell,
            .main-content {
                height: auto !important;
                overflow: visible !important;
                display: block !important;
            }
        }


        @media (max-width: 720px) {
            .main-content {
                padding: 20px;
            }

            .topbar {
                flex-direction: column;
            }

            .month-form {
                grid-template-columns: 1fr;
            }
        }
    

        /* 100% zoom fix: keep Current Bill readable but make the bottom action button visible */
        .fee-card {
            min-height: 128px !important;
            padding: 24px 34px !important;
            margin-bottom: 14px !important;
        }

        .fee-card .hero-amount {
            font-size: 2.8rem !important;
            line-height: 1 !important;
        }

        .grid > .panel:first-child .panel-body {
            padding: 24px 40px 24px !important;
            display: grid !important;
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            gap: 12px 18px !important;
            align-content: start !important;
        }

        .grid > .panel:first-child .message,
        .grid > .panel:first-child .month-form,
        .grid > .panel:first-child .bill-actions {
            grid-column: 1 / -1 !important;
        }

        .grid > .panel:first-child .month-form {
            max-width: 100% !important;
            margin: 4px 0 4px !important;
        }

        .grid > .panel:first-child .month-form label {
            font-size: .72rem !important;
            margin-bottom: 7px !important;
        }

        .grid > .panel:first-child .month-row {
            grid-template-columns: minmax(0, 1fr) 118px !important;
            gap: 12px !important;
        }

        .grid > .panel:first-child .month-row .input,
        .grid > .panel:first-child .month-row .btn {
            height: 48px !important;
            border-radius: 16px !important;
            font-size: .88rem !important;
        }

        .grid > .panel:first-child .detail-row {
            max-width: none !important;
            width: 100% !important;
            min-height: 58px !important;
            padding: 10px 13px !important;
            border: 1px solid #edf2f7 !important;
            border-radius: 16px !important;
            background: #ffffff !important;
            display: flex !important;
            align-items: center !important;
            justify-content: space-between !important;
            gap: 12px !important;
        }

        .grid > .panel:first-child .detail-label {
            font-size: .82rem !important;
            font-weight: 900 !important;
            color: #64748b !important;
        }

        .grid > .panel:first-child .detail-value {
            font-size: .86rem !important;
            font-weight: 950 !important;
            text-align: right !important;
            overflow-wrap: anywhere !important;
        }

        .grid > .panel:first-child .bill-actions {
            max-width: 100% !important;
            width: 100% !important;
            margin: 4px 0 0 !important;
            padding-top: 10px !important;
            border-top: 1px solid #edf2f7 !important;
        }

        .grid > .panel:first-child .pay-now-btn {
            height: 50px !important;
            border-radius: 16px !important;
            font-size: .88rem !important;
        }

        .grid > .panel:first-child .bill-actions .help-text {
            margin-top: 6px !important;
            font-size: .72rem !important;
        }

        @media (max-width: 900px) {
            .grid > .panel:first-child .panel-body {
                grid-template-columns: 1fr !important;
            }
        }


        /* Compact 100% zoom fix so the bottom action button is fully visible */
        .fee-card {
            min-height: 118px !important;
            padding: 20px 28px !important;
            margin-bottom: 12px !important;
        }
        .fee-card .hero-amount {
            font-size: 2.55rem !important;
            line-height: 1 !important;
            margin-bottom: 6px !important;
        }
        .grid > .panel:first-child .panel-body {
            padding: 18px 34px 18px !important;
            gap: 10px 16px !important;
        }
        .grid > .panel:first-child .month-form { margin: 2px 0 2px !important; }
        .grid > .panel:first-child .month-row .input,
        .grid > .panel:first-child .month-row .btn {
            height: 44px !important;
            font-size: .85rem !important;
            border-radius: 14px !important;
        }
        .grid > .panel:first-child .detail-row {
            min-height: 50px !important;
            padding: 8px 13px !important;
            border-radius: 14px !important;
        }
        .grid > .panel:first-child .detail-label {
            font-size: .79rem !important;
        }
        .grid > .panel:first-child .detail-value {
            font-size: .84rem !important;
        }
        .grid > .panel:first-child .bill-actions {
            margin: 0 !important;
            padding-top: 8px !important;
        }
        .grid > .panel:first-child .pay-now-btn {
            height: 46px !important;
            border-radius: 14px !important;
            font-size: .84rem !important;
        }
        .grid > .panel:first-child .bill-actions .help-text {
            margin-top: 5px !important;
            font-size: .7rem !important;
        }
</style>
</head>
<body>
<div class="dashboard-shell">
    <?php require_once __DIR__ . '/admin_sidebar.php'; ?>

    <main class="main-content">
        <div class="topbar">
            <div>
                <div class="eyebrow">System</div>
                <h1>Platform Payment</h1>
                <p class="page-sub">
                    Submit monthly platform fee payment to Superadmin for this apartment.
                </p>
            </div>

            <div class="top-actions">
                <a href="admin_dashboard.php" class="top-btn">
                    <i class="fas fa-arrow-left"></i>
                    Dashboard
                </a>
                <div class="profile-dot"><?= e($adminInitial) ?></div>
            </div>
        </div>

        <section class="hero">
            <div>
                <div class="hero-title">Monthly Platform Fee</div>
                <div class="hero-amount">RM <?= number_format((float)($currentPayment['amount'] ?? 300), 2) ?></div>
                <div class="hero-note">
                    Billing month: <strong><?= e(pp_month_label($billingDate)) ?></strong>.
                    Due date: <strong><?= e(date('d M Y', strtotime($dueDate))) ?></strong>.
                    Current status:
                    <span class="status-pill <?= e($currentVisualStatus) ?>">
                        <i class="fas fa-circle-dot"></i>
                        <?= e(pp_status_label($currentVisualStatus)) ?>
                    </span>
                </div>

            </div>

            <div class="hero-visual" aria-hidden="true">
                <div class="receipt"></div>
                <div class="rm-badge">RM</div>
                <div class="check-badge">
                    <i class="fas fa-check"></i>
                </div>
            </div>
        </section>

        <div class="grid">
            <section class="panel">
                <div class="panel-head">
                    <div class="panel-title">
                        <i class="fas fa-wallet"></i>
                        Current Bill
                    </div>
                    <span class="status-pill <?= e($currentVisualStatus) ?>">
                        <?= e(pp_status_label($currentVisualStatus)) ?>
                    </span>
                </div>

                <div class="panel-body">
                    <?php if ($successMessage): ?>
                        <div class="message success">
                            <i class="fas fa-check-circle"></i>
                            <?= e($successMessage) ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($errorMessage): ?>
                        <div class="message error">
                            <i class="fas fa-circle-exclamation"></i>
                            <?= e($errorMessage) ?>
                        </div>
                    <?php endif; ?>

                    <form method="GET" class="month-form">
                        <label for="month">Billing Month</label>

                        <div class="month-row">
                            <input class="input" type="month" name="month" id="month" value="<?= e($selectedMonth) ?>">

                            <button class="btn" type="submit">
                                <i class="fas fa-search"></i>
                                View
                            </button>
                        </div>
                    </form>

                    <div class="detail-row">
                        <div class="detail-label">Apartment</div>
                        <div class="detail-value"><?= e($currentApartmentName) ?></div>
                    </div>

                    <div class="detail-row">
                        <div class="detail-label">Billing Month</div>
                        <div class="detail-value"><?= e(pp_month_label($billingDate)) ?></div>
                    </div>

                    <div class="detail-row">
                        <div class="detail-label">Due Date</div>
                        <div class="detail-value"><?= e(date('d M Y', strtotime($dueDate))) ?></div>
                    </div>

                    <div class="detail-row">
                        <div class="detail-label">Amount</div>
                        <div class="detail-value">RM <?= number_format((float)($currentPayment['amount'] ?? 300), 2) ?></div>
                    </div>

                    <div class="detail-row">
                        <div class="detail-label">Payment Method</div>
                        <div class="detail-value"><?= e($currentPayment['payment_method'] ?: '-') ?></div>
                    </div>

                    <div class="detail-row">
                        <div class="detail-label">Transaction Ref</div>
                        <div class="detail-value"><?= e($currentPayment['transaction_ref'] ?: '-') ?></div>
                    </div>

                    <div class="detail-row">
                        <div class="detail-label">Submitted At</div>
                        <div class="detail-value"><?= e(pp_dt($currentPayment['submitted_at'] ?? null)) ?></div>
                    </div>

                    <?php if (!empty($currentPayment['proof_file'])): ?>
                        <div class="detail-row">
                            <div class="detail-label">Payment Proof</div>
                            <div class="detail-value">
                                <a class="proof-link" href="<?= e($currentPayment['proof_file']) ?>" target="_blank">
                                    View Proof
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="bill-actions">
                        <?php if ($canSubmit): ?>
                            <button type="button" class="pay-now-btn" id="openGatewayModal">
                                <i class="fas fa-credit-card"></i>
                                Prepare Payment
                            </button>
                            <div class="help-text">
                                Click Prepare Payment to open the secure payment gateway.
                            </div>
                        <?php else: ?>
                            <button type="button" class="pay-now-btn disabled" disabled>
                                <i class="fas fa-clock"></i>
                                <?= strtolower((string)($currentPayment['status'] ?? '')) === 'paid' ? 'Payment Completed' : 'Waiting Superadmin Review' ?>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <div class="payment-modal" id="gatewayModal" aria-hidden="true">
                <div class="payment-modal-card">
                    <section class="panel gateway-panel">
                        <div class="panel-head">
                            <div class="panel-title">
                                <i class="fas fa-credit-card"></i>
                                Secure Payment Gateway
                            </div>

                            <button type="button" class="modal-close-btn" id="closeGatewayModal" aria-label="Close payment gateway">
                                <i class="fas fa-xmark"></i>
                            </button>
                        </div>

                <div class="panel-body">
                    <?php if ($canSubmit): ?>
                        <form method="POST" class="gateway-box" id="gatewayForm">
                            <input type="hidden" name="billing_month" value="<?= e($selectedMonth) ?>">
                            <input type="hidden" name="payment_method" id="payment_method" value="Credit / Debit Card">
                            <input type="hidden" name="transaction_ref" value="<?= e($paymentRef) ?>">
                            <input type="hidden" name="note" value="Submitted via SmartVMS payment gateway demo.">

                            <div class="gateway-title">Available Payment Method:</div>

                            <div class="method-tabs">
                                <button class="method-card active" type="button" data-method="Credit / Debit Card">
                                    <i class="fas fa-credit-card"></i>
                                    <span>Credit / Debit<br>Card</span>
                                </button>

                                <button class="method-card" type="button" data-method="Online Banking">
                                    <i class="fas fa-lock"></i>
                                    <span>Online Banking</span>
                                </button>

                                <button class="method-card" type="button" data-method="E-Wallet">
                                    <i class="fas fa-wallet"></i>
                                    <span>Touch 'n Go<br>eWallet</span>
                                </button>
                            </div>

                            <div class="gateway-section-title">
                                <i class="fas fa-cart-shopping"></i>
                                Summary Of Transaction
                            </div>

                            <div class="transaction-summary">
                                <div class="summary-row">
                                    <div class="summary-label">Net Charges</div>
                                    <div class="summary-value amount">MYR <?= number_format((float)($currentPayment['amount'] ?? 300), 2) ?></div>
                                </div>

                                <div class="summary-row">
                                    <div class="summary-label">Pay To</div>
                                    <div class="summary-value">SmartVMS Superadmin</div>
                                </div>

                                <div class="summary-row">
                                    <div class="summary-label">Payment of</div>
                                    <div class="summary-value">Platform Subscription Fee - <?= e(pp_month_label($billingDate)) ?></div>
                                </div>

                                <div class="summary-row">
                                    <div class="summary-label">Reference No / Payment ID</div>
                                    <div class="summary-value"><?= e($paymentRef) ?></div>
                                </div>
                            </div>

                            <div id="cardSection">
                                <div class="gateway-section-title">Fast Checkout with Google Pay:</div>

                                <div class="saved-card-toolbar">
                                    <button type="button" class="gpay-box gpay-clickable" id="fastCheckoutCard" style="display:none;">
                                        <span class="gpay-g">G</span>
                                        <span id="fastCheckoutType">Pay</span>
                                        <span style="opacity:.7;">|</span>
                                        <span id="gpayLast4">•••• ----</span>
                                    </button>

                                    <button type="button" class="add-card-btn" id="openAddCardBox">
                                        <i class="fas fa-gear"></i>
                                        Add Card
                                    </button>
                                </div>

                                <div class="card-success-toast" id="cardSuccessToast" style="display:none;">
                                    <i class="fas fa-check-circle"></i>
                                    Card added successfully.
                                </div>

                                <div class="add-card-box" id="addCardBox" style="display:none;">
                                    <div class="add-card-head">
                                        <strong>Link a card</strong>
                                        <button type="button" id="closeAddCardBox">
                                            <i class="fas fa-xmark"></i>
                                        </button>
                                    </div>

                                    <div class="add-card-error" id="addCardError" style="display:none;"></div>

                                    <div class="add-card-grid">
                                        <select class="gateway-input" id="newCardType">
                                            <option value="">Select card type</option>
                                            <option value="Credit Card">Credit Card</option>
                                            <option value="Debit Card">Debit Card</option>
                                        </select>

                                        <input class="gateway-input" type="text" id="newCardNumber" maxlength="19" placeholder="Card number, example 1111 1111 1111 1111">

                                        <div class="add-card-two">
                                            <input class="gateway-input" type="text" id="newCardExpiry" maxlength="7" placeholder="Expiry MM/YYYY">
                                            <input class="gateway-input" type="password" id="newCardCvv" maxlength="4" placeholder="CVV">
                                        </div>

                                        <input class="gateway-input" type="text" id="newCardName" placeholder="Cardholder's name, example TAN KAI MING">
                                        <button type="button" class="save-new-card-btn" id="saveNewCardBtn">Save Card</button>
                                    </div>

                                    <div class="gateway-note-card">
                                        Demo only: the form checks 16 digits, expiry, CVV and name before saving. Card number is masked on screen and no real payment is processed.
                                    </div>
                                </div>

                                <div class="gateway-section-title">Credit / Debit Card Details</div>

                                <div class="timeout">Timeout: <span id="gatewayTimer">04:00</span></div>

                                <div class="gateway-form">
                                    <div class="gateway-row">
                                        <div class="gateway-label">Cardholder Name</div>
                                        <input class="gateway-input" type="text" name="cardholder_name" id="payCardName" autocomplete="off">
                                        <div class="gateway-example">Example <i class="fas fa-circle-question"></i></div>
                                    </div>

                                    <div class="gateway-row">
                                        <div class="gateway-label">Credit / Debit Card No.</div>
                                        <input class="gateway-input" type="text" name="card_number_demo" id="payCardNumber" maxlength="19" autocomplete="off" placeholder="1111 1111 1111 1111">
                                        <div class="gateway-example"><i class="fas fa-credit-card"></i></div>
                                    </div>

                                    <div class="gateway-row">
                                        <div class="gateway-label">CVC/CVV2</div>
                                        <input class="gateway-input" type="password" name="cvv_demo" id="payCardCvv" maxlength="4" autocomplete="off">
                                        <div class="gateway-example">CVC/CVV2 <i class="fas fa-circle-question"></i></div>
                                    </div>

                                    <div class="gateway-row">
                                        <div class="gateway-label">Expiry Date</div>
                                        <div class="gateway-small-row">
                                            <select class="gateway-select" name="exp_month_demo" id="payExpMonth">
                                                <option value="">--</option>
                                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                                    <option value="<?= sprintf('%02d', $m) ?>"><?= sprintf('%02d', $m) ?></option>
                                                <?php endfor; ?>
                                            </select>

                                            <select class="gateway-select" name="exp_year_demo" id="payExpYear">
                                                <option value="">----</option>
                                                <?php for ($y = (int)date('Y'); $y <= (int)date('Y') + 10; $y++): ?>
                                                    <option value="<?= $y ?>"><?= $y ?></option>
                                                <?php endfor; ?>
                                            </select>
                                        </div>
                                        <div></div>
                                    </div>

                                    <div class="gateway-row">
                                        <div class="gateway-label">Card Issuing Country</div>
                                        <select class="gateway-select" name="card_country_demo">
                                            <option value="Malaysia">Malaysia</option>
                                            <option value="Singapore">Singapore</option>
                                            <option value="Indonesia">Indonesia</option>
                                            <option value="Thailand">Thailand</option>
                                        </select>
                                        <div></div>
                                    </div>

                                    <div class="gateway-row">
                                        <div class="gateway-label">Card Issuing Bank</div>
                                        <div class="gateway-small-row">
                                            <select class="gateway-select" name="card_bank_demo" style="width:100%;">
                                                <option value="">Please Select</option>
                                                <option>Maybank</option>
                                                <option>CIMB Bank</option>
                                                <option>Public Bank</option>
                                                <option>Hong Leong Bank</option>
                                                <option>RHB Bank</option>
                                            </select>
                                        </div>
                                        <div></div>
                                    </div>
                                </div>
                            </div>

                            <div class="method-extra online-banking-box" id="onlineBankingSection">
                                <div class="online-bank-head">
                                    <div>
                                        <strong>Online Banking Login</strong>
                                        <span>Demo bank authentication before payment processing.</span>
                                    </div>
                                    <i class="fas fa-building-columns"></i>
                                </div>

                                <div class="online-bank-grid">
                                    <div>
                                        <label for="onlineBankDemo">Bank</label>
                                        <select class="gateway-input" name="online_bank_demo" id="onlineBankDemo">
                                            <option value="">Select Bank</option>
                                            <option>Maybank</option>
                                            <option>CIMB Bank</option>
                                            <option>Public Bank</option>
                                            <option>Hong Leong Bank</option>
                                            <option>RHB Bank</option>
                                        </select>
                                    </div>

                                    <div>
                                        <label for="onlineUserDemo">Username / User ID</label>
                                        <input class="gateway-input" type="text" id="onlineUserDemo" placeholder="Enter demo username" autocomplete="off">
                                    </div>

                                    <div>
                                        <label for="onlinePasswordDemo">Password</label>
                                        <input class="gateway-input" type="password" id="onlinePasswordDemo" placeholder="Enter demo password" autocomplete="new-password">
                                    </div>

                                    <div>
                                        <label for="onlineTacDemo">TAC / OTP</label>
                                        <div class="tac-row">
                                            <input class="gateway-input" type="text" id="onlineTacDemo" maxlength="6" placeholder="6 digits">
                                            <button type="button" class="request-tac-btn" id="requestTacBtn">Request TAC</button>
                                        </div>
                                    </div>
                                </div>

                                <div class="online-bank-note">
                                    <i class="fas fa-circle-info"></i>
                                    Demo only: username, password and TAC are checked on screen only and will not be stored.
                                </div>

                                <div class="help-text">Reference No: <?= e($paymentRef) ?></div>
                            </div>

                            <div class="method-extra tng-wallet-box" id="ewalletSection">
                                <div class="tng-head">
                                    <div class="tng-mini-logo">TNG</div>
                                    <div>
                                        <strong>Touch 'n Go eWallet</strong>
                                        <span>Demo eWallet authentication before payment processing.</span>
                                    </div>
                                </div>

                                <div class="tng-grid">
                                    <div>
                                        <label for="ewalletPhoneDemo">Mobile Number</label>
                                        <input class="gateway-input" type="text" name="ewallet_phone_demo" id="ewalletPhoneDemo" placeholder="Example: 0123456789" maxlength="12" autocomplete="off">
                                    </div>

                                    <div>
                                        <label for="ewalletPinDemo">6-Digit PIN</label>
                                        <input class="gateway-input" type="password" id="ewalletPinDemo" placeholder="Enter 6-digit PIN" maxlength="6" autocomplete="new-password">
                                    </div>

                                    <div>
                                        <label for="ewalletOtpDemo">OTP</label>
                                        <div class="tac-row">
                                            <input class="gateway-input" type="text" id="ewalletOtpDemo" maxlength="6" placeholder="6 digits">
                                            <button type="button" class="request-tac-btn" id="requestEwalletOtpBtn">Generate OTP</button>
                                        </div>
                                    </div>
                                </div>

                                <div class="online-bank-note">
                                    <i class="fas fa-circle-info"></i>
                                    Demo only: TNG phone, PIN and OTP are checked on screen only and will not be stored.
                                </div>

                                <div class="help-text">Reference No: <?= e($paymentRef) ?></div>
                            </div>

                            <label class="gateway-check">
                                <input type="checkbox" name="authorize_payment" value="1" required>
                                <span>
                                    I authorize SmartVMS to submit the above monthly platform charges and I have read and agreed to the payment terms.
                                </span>
                            </label>

                            <div class="gateway-note">
                                Note: This is a SmartVMS FYP payment gateway simulation. After Proceed, a loading page and receipt will be generated.
                            </div>

                            <div class="gateway-actions">
                                <button class="proceed-btn" type="submit">» Proceed</button>
                                <a class="cancel-btn" href="admin_dashboard.php">Cancel</a>
                            </div>

                            <div class="secure-line">
                                <i class="fas fa-lock"></i>
                                Secure payment simulation for demonstration purpose.
                            </div>
                        </form>
                    <?php else: ?>
                        <div class="empty">
                            <?php if (strtolower((string)$currentPayment['status']) === 'paid'): ?>
                                <i class="fas fa-check-circle"></i>
                                This month has already been approved as paid.
                            <?php else: ?>
                                <i class="fas fa-clock"></i>
                                Payment has been submitted. Waiting for Superadmin review.
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
                    </section>
                </div>
            </div>
        </div>

    </main>
</div>

<script>
(function () {
    const modal = document.getElementById('gatewayModal');
    const openBtn = document.getElementById('openGatewayModal');
    const closeBtn = document.getElementById('closeGatewayModal');

    function openModal() {
        if (!modal) return;
        modal.classList.add('open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }

    function closeModal() {
        if (!modal) return;
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }

    if (openBtn) {
        openBtn.addEventListener('click', openModal);
    }

    if (closeBtn) {
        closeBtn.addEventListener('click', closeModal);
    }

    if (modal) {
        modal.addEventListener('click', function (event) {
            if (event.target === modal) {
                closeModal();
            }
        });
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeModal();
        }
    });

    const methodInput = document.getElementById('payment_method');
    const cards = document.querySelectorAll('.method-card');
    const cardSection = document.getElementById('cardSection');
    const onlineSection = document.getElementById('onlineBankingSection');
    const ewalletSection = document.getElementById('ewalletSection');

    const gatewayForm = document.getElementById('gatewayForm');
    const onlineBankDemo = document.getElementById('onlineBankDemo');
    const onlineUserDemo = document.getElementById('onlineUserDemo');
    const onlinePasswordDemo = document.getElementById('onlinePasswordDemo');
    const onlineTacDemo = document.getElementById('onlineTacDemo');
    const requestTacBtn = document.getElementById('requestTacBtn');

    const ewalletPhoneDemo = document.getElementById('ewalletPhoneDemo');
    const ewalletPinDemo = document.getElementById('ewalletPinDemo');
    const ewalletOtpDemo = document.getElementById('ewalletOtpDemo');
    const requestEwalletOtpBtn = document.getElementById('requestEwalletOtpBtn');

    cards.forEach(function (card) {
        card.addEventListener('click', function () {
            const method = card.dataset.method || 'Credit / Debit Card';
            methodInput.value = method;

            cards.forEach(function (item) {
                item.classList.remove('active');
            });
            card.classList.add('active');

            if (cardSection) cardSection.style.display = method === 'Credit / Debit Card' ? 'block' : 'none';
            if (onlineSection) onlineSection.classList.toggle('show', method === 'Online Banking');
            if (ewalletSection) ewalletSection.classList.toggle('show', method === 'E-Wallet');
        });
    });


    function markField(field, valid) {
        if (!field) return;
        field.classList.remove('input-error');
        if (!valid) field.classList.add('input-error');
    }

    function validateOnlineBanking() {
        if (!methodInput || methodInput.value !== 'Online Banking') {
            return true;
        }

        const bankOk = onlineBankDemo && onlineBankDemo.value.trim() !== '';
        const userOk = onlineUserDemo && onlineUserDemo.value.trim().length >= 4;
        const passOk = onlinePasswordDemo && onlinePasswordDemo.value.trim().length >= 4;
        const tacOk = onlineTacDemo && /^\d{6}$/.test(onlineTacDemo.value.trim());

        markField(onlineBankDemo, bankOk);
        markField(onlineUserDemo, userOk);
        markField(onlinePasswordDemo, passOk);
        markField(onlineTacDemo, tacOk);

        if (!bankOk || !userOk || !passOk || !tacOk) {
            alert('Please complete online banking demo login: bank, username, password and 6-digit TAC/OTP.');
            return false;
        }

        return true;
    }

    function validateEwallet() {
        if (!methodInput || methodInput.value !== 'E-Wallet') {
            return true;
        }

        const phoneOk = ewalletPhoneDemo && /^01\d{8,9}$/.test(ewalletPhoneDemo.value.trim());
        const pinOk = ewalletPinDemo && /^\d{6}$/.test(ewalletPinDemo.value.trim());
        const otpOk = ewalletOtpDemo && /^\d{6}$/.test(ewalletOtpDemo.value.trim());

        markField(ewalletPhoneDemo, phoneOk);
        markField(ewalletPinDemo, pinOk);
        markField(ewalletOtpDemo, otpOk);

        if (!phoneOk || !pinOk || !otpOk) {
            alert("Please complete Touch 'n Go eWallet demo login: Malaysian mobile number, 6-digit PIN and 6-digit OTP.");
            return false;
        }

        return true;
    }

    if (gatewayForm) {
        gatewayForm.addEventListener('submit', function (event) {
            if (!validateOnlineBanking() || !validateEwallet()) {
                event.preventDefault();
                return false;
            }
        });
    }

    [onlineBankDemo, onlineUserDemo, onlinePasswordDemo, onlineTacDemo].forEach(function (field) {
        if (!field) return;
        field.addEventListener('input', function () {
            field.classList.remove('input-error');
        });
        field.addEventListener('change', function () {
            field.classList.remove('input-error');
        });
    });

    if (requestTacBtn) {
        requestTacBtn.addEventListener('click', function () {
            if (onlineTacDemo) {
                onlineTacDemo.value = '123456';
                onlineTacDemo.classList.remove('input-error');
            }
            alert('Demo TAC generated: 123456');
        });
    }

    [ewalletPhoneDemo, ewalletPinDemo, ewalletOtpDemo].forEach(function (field) {
        if (!field) return;
        field.addEventListener('input', function () {
            field.classList.remove('input-error');
        });
    });

    if (ewalletPhoneDemo) {
        ewalletPhoneDemo.addEventListener('input', function () {
            ewalletPhoneDemo.value = ewalletPhoneDemo.value.replace(/\D/g, '').slice(0, 11);
        });
    }

    if (ewalletPinDemo) {
        ewalletPinDemo.addEventListener('input', function () {
            ewalletPinDemo.value = ewalletPinDemo.value.replace(/\D/g, '').slice(0, 6);
        });
    }

    if (ewalletOtpDemo) {
        ewalletOtpDemo.addEventListener('input', function () {
            ewalletOtpDemo.value = ewalletOtpDemo.value.replace(/\D/g, '').slice(0, 6);
        });
    }

    if (requestEwalletOtpBtn) {
        requestEwalletOtpBtn.addEventListener('click', function () {
            if (ewalletOtpDemo) {
                ewalletOtpDemo.value = '654321';
                ewalletOtpDemo.classList.remove('input-error');
            }
            alert('Demo eWallet OTP generated: 654321');
        });
    }

    let seconds = 240;
    const timer = document.getElementById('gatewayTimer');

    if (timer) {
        setInterval(function () {
            seconds = Math.max(0, seconds - 1);
            const min = String(Math.floor(seconds / 60)).padStart(2, '0');
            const sec = String(seconds % 60).padStart(2, '0');
            timer.textContent = min + ':' + sec;
        }, 1000);
    }

    const openAddCardBox = document.getElementById('openAddCardBox');
    const closeAddCardBox = document.getElementById('closeAddCardBox');
    const addCardBox = document.getElementById('addCardBox');
    const saveNewCardBtn = document.getElementById('saveNewCardBtn');
    const cardSuccessToast = document.getElementById('cardSuccessToast');
    const gpayLast4 = document.getElementById('gpayLast4');
    const fastCheckoutCard = document.getElementById('fastCheckoutCard');
    const fastCheckoutType = document.getElementById('fastCheckoutType');

    const payCardName = document.getElementById('payCardName');
    const payCardNumber = document.getElementById('payCardNumber');
    const payCardCvv = document.getElementById('payCardCvv');
    const payExpMonth = document.getElementById('payExpMonth');
    const payExpYear = document.getElementById('payExpYear');

    let savedDemoCard = null;
    const savedDemoCardStorageKey = 'smartvms_demo_saved_payment_card';


    const newCardType = document.getElementById('newCardType');
    const newCardNumber = document.getElementById('newCardNumber');
    const newCardExpiry = document.getElementById('newCardExpiry');
    const newCardCvv = document.getElementById('newCardCvv');
    const newCardName = document.getElementById('newCardName');

    const addCardError = document.getElementById('addCardError');

    function digitsOnly(value) {
        return (value || '').replace(/\D/g, '').slice(0, 16);
    }

    function maskCard(value) {
        const digits = digitsOnly(value);
        if (digits.length < 4) {
            return '•••• ----';
        }

        return '•••• ' + digits.slice(-4);
    }

    function setFieldState(field, isValid) {
        if (!field) return;
        field.classList.remove('input-error', 'input-ok');
        field.classList.add(isValid ? 'input-ok' : 'input-error');
    }

    function clearFieldState(field) {
        if (!field) return;
        field.classList.remove('input-error', 'input-ok');
    }

    function showCardError(messages) {
        if (!addCardError) return;

        if (!messages.length) {
            addCardError.style.display = 'none';
            addCardError.innerHTML = '';
            return;
        }

        addCardError.style.display = 'block';
        addCardError.innerHTML = messages.map(function (msg) {
            return '<div>• ' + msg + '</div>';
        }).join('');
    }

    function isValidCardNumber(cardNumber) {
        return digitsOnly(cardNumber).length === 16;
    }

    function validateExpiry(value) {
        const match = (value || '').trim().match(/^(0[1-9]|1[0-2])\/(20\d{2})$/);

        if (!match) {
            return false;
        }

        const month = parseInt(match[1], 10);
        const year = parseInt(match[2], 10);
        const now = new Date();
        const expiry = new Date(year, month, 0, 23, 59, 59);

        return expiry >= now;
    }

    function formatExpiry(value) {
        let raw = (value || '').replace(/\D/g, '').slice(0, 6);

        if (raw.length >= 3) {
            raw = raw.slice(0, 2) + '/' + raw.slice(2);
        }

        return raw;
    }

    function validateAddCardForm() {
        const errors = [];

        const type = (newCardType && newCardType.value.trim()) || '';
        const number = (newCardNumber && newCardNumber.value) || '';
        const expiry = (newCardExpiry && newCardExpiry.value.trim()) || '';
        const cvv = (newCardCvv && newCardCvv.value.trim()) || '';
        const name = (newCardName && newCardName.value.trim()) || '';

        const validType = type !== '';
        const validNumber = isValidCardNumber(number);
        const validExpiry = validateExpiry(expiry);
        const validCvv = /^\d{3,4}$/.test(cvv);
        const validName = /^[A-Za-z][A-Za-z\s'.-]{1,48}$/.test(name);

        setFieldState(newCardType, validType);
        setFieldState(newCardNumber, validNumber);
        setFieldState(newCardExpiry, validExpiry);
        setFieldState(newCardCvv, validCvv);
        setFieldState(newCardName, validName);

        if (!validType) errors.push('Please select a card type.');
        if (!validNumber) errors.push('Card number must contain exactly 16 digits. Example: 1111 1111 1111 1111.');
        if (!validExpiry) errors.push('Expiry date must be MM/YYYY and cannot be expired.');
        if (!validCvv) errors.push('CVV must be 3 or 4 digits.');
        if (!validName) errors.push('Cardholder name must contain letters and at least 2 characters.');

        showCardError(errors);

        return errors.length === 0;
    }

    function formatCardNumber(value) {
        const digits = digitsOnly(value);
        return digits.replace(/(.{4})/g, '$1 ').trim();
    }

    function splitExpiry(expiry) {
        const match = (expiry || '').trim().match(/^(0[1-9]|1[0-2])\/(20\d{2})$/);
        if (!match) {
            return { month: '', year: '' };
        }

        return { month: match[1], year: match[2] };
    }


    function saveDemoCardToBrowser(card) {
        try {
            localStorage.setItem(savedDemoCardStorageKey, JSON.stringify(card));
        } catch (error) {
            // Browser storage may be disabled. The card will still work until page refresh.
        }
    }

    function loadDemoCardFromBrowser() {
        try {
            const raw = localStorage.getItem(savedDemoCardStorageKey);
            if (!raw) {
                return null;
            }

            const card = JSON.parse(raw);

            if (!card || !card.type || !card.number || !card.name || !card.expiry) {
                return null;
            }

            if (!isValidCardNumber(card.number) || !validateExpiry(card.expiry)) {
                localStorage.removeItem(savedDemoCardStorageKey);
                return null;
            }

            return card;
        } catch (error) {
            return null;
        }
    }

    function updateFastCheckoutCard() {
        if (!savedDemoCard) {
            if (fastCheckoutCard) {
                fastCheckoutCard.style.display = 'none';
                fastCheckoutCard.classList.remove('is-saved');
            }
            return;
        }

        const masked = maskCard(savedDemoCard.number);

        if (fastCheckoutCard) {
            fastCheckoutCard.style.display = 'inline-flex';
            fastCheckoutCard.classList.add('is-saved');
            fastCheckoutCard.title = 'Click to fill card details below';
        }

        if (gpayLast4) {
            gpayLast4.textContent = masked;
        }

        if (fastCheckoutType) {
            fastCheckoutType.textContent = savedDemoCard.type === 'Debit Card' ? 'Debit' : 'Credit';
        }
    }

    function applySavedCardToPaymentForm() {
        if (!savedDemoCard) {
            alert('Please add a card first.');
            return;
        }

        if (payCardName) {
            payCardName.value = savedDemoCard.name;
        }

        if (payCardNumber) {
            payCardNumber.value = formatCardNumber(savedDemoCard.number);
        }

        const parts = splitExpiry(savedDemoCard.expiry);

        if (payExpMonth) {
            payExpMonth.value = parts.month;
        }

        if (payExpYear) {
            payExpYear.value = parts.year;
        }

        if (payCardCvv) {
            payCardCvv.value = '';
            payCardCvv.focus();
        }

        alert('Saved card details have been filled in. Please enter CVV to continue.');
    }

    savedDemoCard = loadDemoCardFromBrowser();
    updateFastCheckoutCard();

    if (newCardNumber) {
        newCardNumber.addEventListener('input', function () {
            const digits = digitsOnly(newCardNumber.value);
            newCardNumber.value = formatCardNumber(digits);
            clearFieldState(newCardNumber);
            showCardError([]);
        });
    }

    if (newCardExpiry) {
        newCardExpiry.addEventListener('input', function () {
            newCardExpiry.value = formatExpiry(newCardExpiry.value);
            clearFieldState(newCardExpiry);
            showCardError([]);
        });
    }

    [newCardType, newCardCvv, newCardName].forEach(function (field) {
        if (!field) return;
        field.addEventListener('input', function () {
            clearFieldState(field);
            showCardError([]);
        });
        field.addEventListener('change', function () {
            clearFieldState(field);
            showCardError([]);
        });
    });

    if (openAddCardBox) {
        openAddCardBox.addEventListener('click', function () {
            if (addCardBox) {
                addCardBox.style.display = 'block';
            }
            showCardError([]);
        });
    }

    if (closeAddCardBox) {
        closeAddCardBox.addEventListener('click', function () {
            if (addCardBox) {
                addCardBox.style.display = 'none';
            }
            showCardError([]);
        });
    }

    if (saveNewCardBtn) {
        saveNewCardBtn.addEventListener('click', function () {
            if (!validateAddCardForm()) {
                return;
            }

            const type = newCardType.value.trim();
            const number = digitsOnly(newCardNumber.value);
            const name = newCardName.value.trim().toUpperCase();
            const expiry = newCardExpiry.value.trim();
            const masked = maskCard(number);

            savedDemoCard = {
                type: type,
                number: number,
                name: name,
                expiry: expiry
            };

            saveDemoCardToBrowser(savedDemoCard);
            updateFastCheckoutCard();

            if (cardSuccessToast) {
                cardSuccessToast.style.display = 'flex';
                setTimeout(function () {
                    cardSuccessToast.style.display = 'none';
                }, 2500);
            }

            if (addCardBox) {
                addCardBox.style.display = 'none';
            }

            if (newCardCvv) {
                newCardCvv.value = '';
                clearFieldState(newCardCvv);
            }

            applySavedCardToPaymentForm();
            showCardError([]);
        });
    }

    if (fastCheckoutCard) {
        fastCheckoutCard.addEventListener('click', applySavedCardToPaymentForm);
    }
})();
</script>

</body>
</html>
