<?php
require_once '../core/security.php';
require_login(['resident']);

$pdo = db();
$residentId = (int)($_SESSION['uid'] ?? 0);
$paymentId = (int)($_GET['payment_id'] ?? 0);
$bank = strtolower(trim((string)($_GET['bank'] ?? 'maybank')));

if (!function_exists('e')) {
    function e($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

function sbp_has_col(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function sbp_money($amount): string {
    return 'RM' . number_format((float)$amount, 2);
}

function sbp_payment_channel(string $bank): array {
    if ($bank === 'cimb') {
        return [
            'key' => 'cimb',
            'type' => 'bank',
            'brand' => 'CIMB Clicks',
            'short' => 'CIMB',
            'title' => 'CIMB Clicks',
            'subtitle' => 'Secure online banking payment',
            'accent' => '#d71920',
            'accent_dark' => '#991b1b',
            'accent_soft' => '#fff1f2',
            'header_bg' => 'linear-gradient(135deg, #d71920 0%, #ef4444 100%)',
            'page_tint' => 'linear-gradient(135deg, rgba(215,25,32,.10), rgba(255,255,255,.60))',
            'logo_text' => 'C',
            'button_text' => '#ffffff',
            'welcome' => 'Welcome to CIMB Clicks. Please log in to approve your parking fee payment.',
            'demo_note' => 'Demo only: enter any User ID and password to continue the simulation.',
            'otp_note' => 'A secure TAC / OTP has been sent to your registered device. Demo OTP: 123456.',
            'login_label' => 'User ID',
            'password_label' => 'Password',
            'login_placeholder' => 'Enter your CIMB Clicks User ID',
            'password_placeholder' => 'Enter your password',
        ];
    }

    if ($bank === 'card') {
        return [
            'key' => 'card',
            'type' => 'card',
            'brand' => 'Card Checkout',
            'short' => 'CARD',
            'title' => 'Card Payment Gateway',
            'subtitle' => 'Secure Visa / Mastercard payment',
            'accent' => '#2563eb',
            'accent_dark' => '#1d4ed8',
            'accent_soft' => '#eff6ff',
            'header_bg' => 'linear-gradient(135deg, #1d4ed8 0%, #38bdf8 100%)',
            'page_tint' => 'linear-gradient(135deg, rgba(37,99,235,.10), rgba(255,255,255,.60))',
            'logo_text' => 'CARD',
            'button_text' => '#ffffff',
            'welcome' => 'Enter your card details to complete the resident parking fee payment.',
            'demo_note' => 'Demo only: use any sample card details for presentation. No real card is charged.',
            'otp_note' => 'The payment gateway will simulate authorisation and generate a payment reference.',
            'login_label' => 'Cardholder Name',
            'password_label' => 'Card Number',
            'login_placeholder' => 'Example: ONG KENG SENG',
            'password_placeholder' => '4111 1111 1111 1111',
        ];
    }

    return [
        'key' => 'maybank',
        'type' => 'bank',
        'brand' => 'Maybank2u',
        'short' => 'MAYBANK',
        'title' => 'Maybank2u',
        'subtitle' => 'Secure online banking payment',
        'accent' => '#f5b301',
        'accent_dark' => '#111827',
        'accent_soft' => '#fff8db',
        'header_bg' => 'linear-gradient(135deg, #f5b301 0%, #ffd44d 100%)',
        'page_tint' => 'linear-gradient(135deg, rgba(245,179,1,.12), rgba(255,255,255,.60))',
        'logo_text' => 'M2U',
        'button_text' => '#111827',
        'welcome' => 'Welcome to Maybank2u. Log in to approve your resident parking monthly payment.',
        'demo_note' => 'Demo only: enter any username and password to continue this simulation.',
        'otp_note' => 'An SMS TAC / OTP has been sent to your registered mobile number. Demo OTP: 123456.',
        'login_label' => 'Username',
        'password_label' => 'Password',
        'login_placeholder' => 'Enter your Maybank2u username',
        'password_placeholder' => 'Enter your password',
    ];
}

$channel = sbp_payment_channel($bank);
$error = '';
$payment = null;

if ($paymentId <= 0) {
    $error = 'Invalid payment record.';
} else {
    try {
        $vehicleModelSql = sbp_has_col($pdo, 'resident_vehicles', 'vehicle_model') ? 'rv.vehicle_model' : "NULL AS vehicle_model";
        $stmt = $pdo->prepare("\n            SELECT\n                pp.id AS payment_id,\n                pp.billing_month,\n                pp.amount,\n                pp.payment_status,\n                rpa.id AS assignment_id,\n                rpa.status AS assignment_status,\n                rv.plate_no,\n                {$vehicleModelSql},\n                ps.block_name,\n                ps.slot_no,\n                u.email\n            FROM parking_payments pp\n            JOIN resident_parking_assignments rpa ON rpa.id = pp.assignment_id\n            JOIN resident_vehicles rv ON rv.id = rpa.vehicle_id\n            JOIN parking_slots ps ON ps.id = rpa.slot_id\n            JOIN users u ON u.id = pp.resident_id\n            WHERE pp.id = ?\n            AND pp.resident_id = ?\n            LIMIT 1\n        ");
        $stmt->execute([$paymentId, $residentId]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$payment) {
            $error = 'Payment record not found.';
        }
    } catch (Throwable $e) {
        $error = 'Unable to load payment details.';
    }
}

$amountText = $payment ? sbp_money($payment['amount'] ?? 0) : 'RM0.00';
$billingMonth = $payment && !empty($payment['billing_month'])
    ? date('F Y', strtotime($payment['billing_month'] . '-01'))
    : date('F Y');
$slotText = $payment ? trim(($payment['block_name'] ?? '-') . ' / ' . ($payment['slot_no'] ?? '-')) : '-';
$plateText = $payment['plate_no'] ?? '-';
$merchantRef = $payment ? 'SVMS-' . str_pad((string)$paymentId, 6, '0', STR_PAD_LEFT) : 'SVMS-000000';
$isCard = $channel['type'] === 'card';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($channel['title']) ?> - SmartVMS Payment</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap');

        :root {
            --accent: <?= e($channel['accent']) ?>;
            --accent-dark: <?= e($channel['accent_dark']) ?>;
            --accent-soft: <?= e($channel['accent_soft']) ?>;
            --header-bg: <?= e($channel['header_bg']) ?>;
            --page-tint: <?= e($channel['page_tint']) ?>;
            --button-text: <?= e($channel['button_text']) ?>;
            --line: #dbe5f0;
            --text: #0f172a;
            --muted: #64748b;
            --white: rgba(255,255,255,.94);
        }

        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body {
            min-height: 100vh;
            font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            color: var(--text);
            background:
                var(--page-tint),
                url('lou.jpg') center/cover no-repeat fixed;
        }

        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background: rgba(255,255,255,.55);
            backdrop-filter: blur(4px);
            pointer-events: none;
        }

        .page {
            position: relative;
            z-index: 1;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 28px;
        }

        .shell {
            width: min(1160px, 100%);
            display: grid;
            grid-template-columns: 1.05fr .95fr;
            gap: 24px;
            align-items: stretch;
        }

        .card {
            background: var(--white);
            border: 1px solid rgba(219,229,240,.96);
            border-radius: 30px;
            box-shadow: 0 26px 70px rgba(15,23,42,.14);
            overflow: hidden;
        }

        .summary {
            padding: 28px;
            position: relative;
            display: flex;
            flex-direction: column;
        }

        .summary::after {
            content: '';
            position: absolute;
            right: -90px;
            bottom: -80px;
            width: 230px;
            height: 230px;
            border-radius: 50%;
            background: color-mix(in srgb, var(--accent) 16%, transparent);
        }

        .summary-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            margin-bottom: 26px;
            position: relative;
            z-index: 1;
        }

        .merchant {
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 900;
            letter-spacing: -.04em;
            font-size: 1.2rem;
        }

        .merchant-icon {
            width: 48px;
            height: 48px;
            border-radius: 16px;
            background: linear-gradient(135deg, #38bdf8, #2563eb);
            color: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 16px 30px rgba(37,99,235,.26);
        }

        .secure-chip {
            padding: 10px 14px;
            border-radius: 999px;
            background: var(--accent-soft);
            color: var(--accent-dark);
            border: 1px solid color-mix(in srgb, var(--accent) 55%, white);
            font-size: .78rem;
            font-weight: 800;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .hero-label {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            align-self: flex-start;
            padding: 8px 14px;
            border-radius: 999px;
            background: var(--accent-soft);
            color: var(--accent-dark);
            border: 1px solid color-mix(in srgb, var(--accent) 60%, white);
            font-size: .78rem;
            font-weight: 800;
            margin-bottom: 16px;
            position: relative;
            z-index: 1;
        }

        .hero-title {
            position: relative;
            z-index: 1;
            margin: 0;
            font-size: clamp(2.65rem, 5vw, 4.4rem);
            line-height: .94;
            letter-spacing: -.08em;
            font-weight: 900;
        }

        .hero-title .accent {
            color: var(--accent-dark);
        }

        .hero-text {
            position: relative;
            z-index: 1;
            margin: 16px 0 0;
            color: #475569;
            font-size: 1rem;
            line-height: 1.55;
            max-width: 520px;
            font-weight: 650;
        }

        .amount-box {
            position: relative;
            z-index: 1;
            margin-top: 24px;
            padding: 22px 22px 20px;
            border-radius: 24px;
            background: linear-gradient(135deg, color-mix(in srgb, var(--accent) 10%, white), white 65%);
            border: 1px solid color-mix(in srgb, var(--accent) 35%, #dbe5f0);
        }

        .amount-box span {
            color: var(--muted);
            font-size: .74rem;
            text-transform: uppercase;
            letter-spacing: .08em;
            font-weight: 800;
        }

        .amount-box strong {
            display: block;
            margin-top: 8px;
            font-size: 3rem;
            line-height: .94;
            font-weight: 900;
            letter-spacing: -.06em;
        }

        .info-grid {
            position: relative;
            z-index: 1;
            margin-top: 18px;
            display: grid;
            gap: 10px;
        }

        .info-row {
            min-height: 52px;
            border-radius: 18px;
            background: rgba(255,255,255,.78);
            border: 1px solid var(--line);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            padding: 0 16px;
        }

        .info-row span {
            font-size: .72rem;
            font-weight: 800;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: .07em;
        }

        .info-row strong {
            font-size: .92rem;
            font-weight: 850;
            text-align: right;
        }

        .gateway {
            display: flex;
            flex-direction: column;
        }

        .gateway-head {
            padding: 22px 24px;
            background: var(--header-bg);
            color: #fff;
        }

        .gateway-head.light-text {
            color: #0f172a;
        }

        .gateway-brand {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
        }

        .brand-left {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .brand-badge {
            width: 58px;
            height: 58px;
            border-radius: 18px;
            background: rgba(255,255,255,.92);
            color: var(--accent-dark);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            font-weight: 900;
            box-shadow: 0 12px 24px rgba(15,23,42,.12);
        }

        .brand-copy h1 {
            margin: 0;
            font-size: 1.5rem;
            letter-spacing: -.04em;
            font-weight: 900;
        }

        .brand-copy p {
            margin: 6px 0 0;
            font-size: .85rem;
            line-height: 1.45;
            opacity: .95;
            font-weight: 650;
        }

        .status-pill {
            padding: 10px 14px;
            border-radius: 999px;
            background: rgba(255,255,255,.85);
            color: var(--accent-dark);
            font-size: .76rem;
            font-weight: 800;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
        }

        .steps {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            padding: 18px 24px 0;
            background: linear-gradient(180deg, rgba(248,250,252,.98), rgba(255,255,255,.95));
        }

        .step {
            height: 44px;
            border-radius: 999px;
            border: 1px solid var(--line);
            background: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            color: var(--muted);
            font-size: .76rem;
            font-weight: 800;
        }

        .step.active {
            background: var(--accent-soft);
            border-color: color-mix(in srgb, var(--accent) 45%, white);
            color: var(--accent-dark);
        }

        .gateway-body {
            padding: 22px 24px 24px;
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .screen { display: none; }
        .screen.active { display: block; }

        .section-title {
            margin: 0 0 8px;
            font-size: 1.28rem;
            font-weight: 900;
            letter-spacing: -.04em;
        }

        .section-subtitle {
            margin: 0 0 18px;
            color: var(--muted);
            font-size: .9rem;
            line-height: 1.48;
            font-weight: 650;
        }

        .login-banner {
            margin-bottom: 18px;
            padding: 15px 16px;
            border-radius: 18px;
            background: linear-gradient(135deg, color-mix(in srgb, var(--accent) 10%, white), #fff);
            border: 1px solid color-mix(in srgb, var(--accent) 30%, #dbe5f0);
            display: flex;
            gap: 12px;
            align-items: flex-start;
        }

        .login-banner i {
            margin-top: 2px;
            color: var(--accent-dark);
        }

        .login-banner strong {
            display: block;
            font-size: .9rem;
            margin-bottom: 3px;
        }

        .login-banner span {
            display: block;
            color: #475569;
            font-size: .82rem;
            line-height: 1.45;
            font-weight: 650;
        }

        .form-group {
            margin-bottom: 14px;
        }

        .form-group label {
            display: block;
            margin-bottom: 7px;
            color: var(--muted);
            font-size: .74rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .07em;
        }

        .field-wrap {
            position: relative;
        }

        .field-wrap i {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
        }

        .input,
        .otp-box input {
            width: 100%;
            height: 52px;
            border-radius: 16px;
            border: 1px solid var(--line);
            background: #fff;
            padding: 0 16px;
            font: inherit;
            font-size: .92rem;
            font-weight: 750;
            color: var(--text);
            outline: none;
            transition: .16s ease;
        }

        .input:focus,
        .otp-box input:focus {
            border-color: color-mix(in srgb, var(--accent) 60%, #93c5fd);
            box-shadow: 0 0 0 4px color-mix(in srgb, var(--accent) 18%, transparent);
        }

        .note-box {
            margin: 16px 0 18px;
            padding: 13px 14px;
            border-radius: 18px;
            background: var(--accent-soft);
            border: 1px solid color-mix(in srgb, var(--accent) 32%, #dbe5f0);
            color: var(--accent-dark);
            font-size: .79rem;
            line-height: 1.45;
            font-weight: 760;
            display: flex;
            gap: 10px;
            align-items: flex-start;
        }

        .otp-box {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 9px;
            margin-bottom: 18px;
        }

        .otp-box input {
            text-align: center;
            font-size: 1.12rem;
            font-weight: 900;
            padding: 0;
        }

        .card-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }

        .btn-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-top: 2px;
        }

        .btn {
            height: 52px;
            border: 0;
            border-radius: 17px;
            cursor: pointer;
            font: inherit;
            font-size: .92rem;
            font-weight: 850;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            transition: transform .16s ease, box-shadow .16s ease, opacity .16s ease;
        }

        .btn:hover { transform: translateY(-1px); }

        .btn-primary {
            background: var(--header-bg);
            color: var(--button-text);
            box-shadow: 0 18px 34px color-mix(in srgb, var(--accent) 28%, transparent);
        }

        .btn-secondary {
            background: #fff;
            color: #334155;
            border: 1px solid var(--line);
        }

        .payment-review {
            margin-top: 14px;
            display: grid;
            gap: 10px;
        }

        .review-item {
            min-height: 48px;
            padding: 0 14px;
            border-radius: 15px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }

        .review-item span {
            color: var(--muted);
            font-size: .72rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .07em;
        }

        .review-item strong {
            font-size: .88rem;
            font-weight: 850;
            text-align: right;
        }

        .overlay {
            position: fixed;
            inset: 0;
            z-index: 99;
            display: none;
            align-items: center;
            justify-content: center;
            background: rgba(15,23,42,.58);
            backdrop-filter: blur(12px);
            padding: 24px;
        }

        .overlay.show { display: flex; }

        .overlay-card {
            width: min(430px, 100%);
            padding: 32px;
            border-radius: 28px;
            background: rgba(255,255,255,.98);
            box-shadow: 0 30px 90px rgba(15,23,42,.28);
            text-align: center;
        }

        .spinner {
            width: 66px;
            height: 66px;
            margin: 0 auto 18px;
            border-radius: 50%;
            border: 5px solid #e2e8f0;
            border-top-color: var(--accent);
            animation: spin .9s linear infinite;
        }

        @keyframes spin { to { transform: rotate(360deg); } }

        .success-icon {
            display: none;
            width: 66px;
            height: 66px;
            margin: 0 auto 18px;
            border-radius: 50%;
            background: #dcfce7;
            color: #16a34a;
            font-size: 1.75rem;
            align-items: center;
            justify-content: center;
        }

        .overlay-card.success .spinner { display: none; }
        .overlay-card.success .success-icon { display: inline-flex; }

        .overlay-card h2 {
            margin: 0 0 9px;
            font-size: 1.48rem;
            letter-spacing: -.04em;
            font-weight: 900;
        }

        .overlay-card p {
            margin: 0;
            color: var(--muted);
            font-size: .92rem;
            line-height: 1.5;
            font-weight: 650;
        }

        .ref-box {
            display: none;
            margin-top: 18px;
            padding: 16px;
            border-radius: 18px;
            background: #f8fafc;
            border: 1px solid var(--line);
            text-align: left;
        }

        .overlay-card.success .ref-box,
        .overlay-card.success .overlay-actions { display: block; }

        .ref-box span {
            color: var(--muted);
            font-size: .72rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .07em;
        }

        .ref-box strong {
            display: block;
            margin-top: 6px;
            font-size: 1rem;
            font-weight: 900;
            word-break: break-all;
        }

        .overlay-actions {
            display: none;
            margin-top: 18px;
        }

        .overlay-actions .btn + .btn { margin-top: 10px; }


        .receipt-download-hint {
            display: none;
            margin-top: 12px;
            padding: 11px 13px;
            border-radius: 16px;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            color: #1d4ed8;
            font-size: .78rem;
            line-height: 1.4;
            font-weight: 750;
        }

        .overlay-card.success .receipt-download-hint {
            display: block;
        }

        .error-box {
            width: min(760px, 100%);
            padding: 38px;
            border-radius: 30px;
            background: rgba(255,255,255,.96);
            border: 1px solid #fecaca;
            box-shadow: 0 26px 70px rgba(15,23,42,.14);
            text-align: center;
        }

        .error-box i {
            font-size: 2.2rem;
            color: #ef4444;
            margin-bottom: 16px;
        }

        .error-box h1 {
            margin: 0 0 8px;
            font-size: 1.75rem;
            font-weight: 900;
        }

        .error-box p {
            margin: 0 0 20px;
            color: var(--muted);
            font-weight: 650;
        }


        .message-modal {
            position: fixed;
            inset: 0;
            z-index: 120;
            display: none;
            align-items: center;
            justify-content: center;
            background: rgba(15,23,42,.45);
            backdrop-filter: blur(8px);
            padding: 24px;
        }

        .message-modal.show {
            display: flex;
        }

        .message-card {
            width: min(430px, 100%);
            background: rgba(255,255,255,.98);
            border: 1px solid #dbe5f0;
            border-radius: 28px;
            box-shadow: 0 28px 80px rgba(15,23,42,.26);
            padding: 28px 28px 24px;
            text-align: center;
        }

        .message-icon {
            width: 72px;
            height: 72px;
            margin: 0 auto 16px;
            border-radius: 50%;
            background: #fff7ed;
            color: #f97316;
            border: 1px solid #fdba74;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.9rem;
            box-shadow: 0 14px 30px rgba(249,115,22,.15);
        }

        .message-card h3 {
            margin: 0 0 10px;
            font-size: 1.45rem;
            font-weight: 900;
            letter-spacing: -.04em;
        }

        .message-card p {
            margin: 0;
            color: var(--muted);
            font-size: .95rem;
            line-height: 1.55;
            font-weight: 650;
        }

        .message-card .btn {
            margin-top: 20px;
            min-width: 140px;
        }


        .input.field-error,
        .otp-box input.field-error {
            border-color: #ef4444 !important;
            box-shadow: 0 0 0 4px rgba(239,68,68,.14) !important;
            background: #fff5f5 !important;
        }

        .form-group .field-error-note {
            display: none;
            margin-top: 6px;
            color: #dc2626;
            font-size: .74rem;
            font-weight: 800;
        }

        .form-group.has-error .field-error-note {
            display: block;
        }

        .input.card-masked {
            letter-spacing: .04em;
            color: #0f172a;
            font-weight: 900;
        }

        .privacy-note {
            margin: -4px 0 14px;
            color: #64748b;
            font-size: .74rem;
            line-height: 1.4;
            font-weight: 700;
            display: flex;
            align-items: flex-start;
            gap: 8px;
        }

        .privacy-note i {
            color: #16a34a;
            margin-top: 2px;
        }

        @media (max-width: 980px) {
            .shell {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 640px) {
            .page { padding: 16px; }
            .summary, .gateway-body { padding: 20px; }
            .gateway-head { padding: 18px 20px; }
            .steps { padding: 16px 20px 0; }
            .brand-left { align-items: flex-start; }
            .gateway-brand { flex-direction: column; align-items: flex-start; }
            .btn-row, .card-grid { grid-template-columns: 1fr; }
            .otp-box { gap: 6px; }
            .otp-box input { height: 48px; }
        }
    </style>
</head>
<body>
<div class="page">
    <?php if ($error): ?>
        <div class="error-box">
            <i class="fas fa-circle-xmark"></i>
            <h1>Unable to continue payment</h1>
            <p><?= e($error) ?></p>
            <a class="btn btn-secondary" href="resident_vehicles.php">
                <i class="fas fa-arrow-left"></i>
                Back to My Parking
            </a>
        </div>
    <?php else: ?>
        <div class="shell">
            <section class="card summary">
                <div class="summary-top">
                    <div class="merchant">
                        <span class="merchant-icon"><i class="fas fa-building-shield"></i></span>
                        <span>Smart<span style="color:#2563eb;">VMS</span></span>
                    </div>
                    <span class="secure-chip"><i class="fas fa-lock"></i> Secure Checkout</span>
                </div>

                <span class="hero-label"><i class="fas fa-circle-check"></i> Resident Parking Payment</span>
                <h1 class="hero-title">
                    <?= $isCard ? 'Card <span class="accent">payment</span> demo.' : 'Online <span class="accent">banking</span> payment.' ?>
                </h1>
                <p class="hero-text">
                    Complete your <?= e($billingMonth) ?> parking fee payment for <?= e($plateText) ?>.
                    This demo will simulate a realistic <?= $isCard ? 'card checkout' : 'bank login, OTP and processing' ?> flow before returning you to SmartVMS.
                </p>

                <div class="amount-box">
                    <span>Total Amount</span>
                    <strong><?= e($amountText) ?></strong>
                </div>

                <div class="info-grid">
                    <div class="info-row"><span>Merchant</span><strong>Ixora Apartment Management</strong></div>
                    <div class="info-row"><span>Vehicle</span><strong><?= e($plateText) ?></strong></div>
                    <div class="info-row"><span>Parking Slot</span><strong><?= e($slotText) ?></strong></div>
                    <div class="info-row"><span>Billing Month</span><strong><?= e($billingMonth) ?></strong></div>
                    <div class="info-row"><span>Reference</span><strong><?= e($merchantRef) ?></strong></div>
                </div>
            </section>

            <section class="card gateway">
                <div class="gateway-head<?= ($channel['key'] === 'maybank') ? ' light-text' : '' ?>">
                    <div class="gateway-brand">
                        <div class="brand-left">
                            <span class="brand-badge"><?= e($channel['logo_text']) ?></span>
                            <div class="brand-copy">
                                <h1><?= e($channel['title']) ?></h1>
                                <p><?= e($channel['subtitle']) ?></p>
                            </div>
                        </div>
                        <span class="status-pill"><i class="fas fa-shield-halved"></i> SSL Protected</span>
                    </div>
                </div>

                <div class="steps">
                    <?php if ($isCard): ?>
                        <div class="step active" id="stepLogin"><i class="fas fa-credit-card"></i> Card Details</div>
                        <div class="step" id="stepOtp"><i class="fas fa-lock"></i> Authorise</div>
                        <div class="step" id="stepDone"><i class="fas fa-check"></i> Success</div>
                    <?php else: ?>
                        <div class="step active" id="stepLogin"><i class="fas fa-user-lock"></i> Login</div>
                        <div class="step" id="stepOtp"><i class="fas fa-key"></i> OTP / TAC</div>
                        <div class="step" id="stepDone"><i class="fas fa-check"></i> Success</div>
                    <?php endif; ?>
                </div>

                <div class="gateway-body">
                    <?php if ($isCard): ?>
                        <div class="screen active" id="cardScreen">
                            <h2 class="section-title">Pay with card</h2>
                            <p class="section-subtitle"><?= e($channel['welcome']) ?></p>

                            <div class="login-banner">
                                <i class="fas fa-credit-card"></i>
                                <div>
                                    <strong>Cardholder verification</strong>
                                    <span>Use demo details only. The card payment will be simulated for presentation purposes.</span>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Cardholder Name</label>
                                <input class="input card-required" id="cardName" type="text" placeholder="Example: ONG KENG SENG">
                                <div class="field-error-note">Cardholder name is required.</div>
                            </div>

                            <div class="form-group">
                                <label>Email Address</label>
                                <input class="input card-required" id="cardEmail" type="email" placeholder="Example: resident@email.com">
                                <div class="field-error-note">Valid email address is required.</div>
                            </div>

                            <div class="form-group">
                                <label>Billing Address</label>
                                <input class="input card-required" id="billingAddress" type="text" placeholder="Example: Block A, Unit A-01-02, Ixora Apartment">
                                <div class="field-error-note">Billing address is required.</div>
                            </div>

                            <div class="card-grid">
                                <div class="form-group">
                                    <label>City / State</label>
                                    <input class="input card-required" id="billingCity" type="text" placeholder="Example: Melaka">
                                    <div class="field-error-note">City or state is required.</div>
                                </div>
                                <div class="form-group">
                                    <label>Postcode</label>
                                    <input class="input card-required" id="billingPostcode" type="text" maxlength="5" placeholder="Example: 75450">
                                    <div class="field-error-note">Postcode is required.</div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Card Number</label>
                                <input class="input card-required" id="cardNumber" type="text" maxlength="19" placeholder="4111 1111 1111 1111" autocomplete="off">
                                <div class="field-error-note">Valid card number is required.</div>
                            </div>

                            <div class="privacy-note">
                                <i class="fas fa-shield-halved"></i>
                                <span>For privacy, the card number will be masked automatically after entering it.</span>
                            </div>

                            <div class="card-grid">
                                <div class="form-group">
                                    <label>Expiry Date</label>
                                    <input class="input card-required" id="cardExpiry" type="text" maxlength="5" placeholder="MM/YY">
                                    <div class="field-error-note">Expiry date is required.</div>
                                </div>
                                <div class="form-group">
                                    <label>CVV</label>
                                    <input class="input card-required" id="cardCvv" type="password" maxlength="4" placeholder="123">
                                    <div class="field-error-note">CVV is required.</div>
                                </div>
                            </div>

                            <div class="note-box">
                                <i class="fas fa-circle-info"></i>
                                <span><?= e($channel['demo_note']) ?></span>
                            </div>

                            <div class="btn-row">
                                <button type="button" class="btn btn-primary" id="cardPayBtn"><i class="fas fa-lock"></i> Pay <?= e($amountText) ?></button>
                                <a class="btn btn-secondary" href="resident_vehicles.php"><i class="fas fa-arrow-left"></i> Cancel</a>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="screen active" id="loginScreen">
                            <h2 class="section-title"><?= e($channel['title']) ?> secure login</h2>
                            <p class="section-subtitle"><?= e($channel['welcome']) ?></p>

                            <div class="login-banner">
                                <i class="fas fa-lock"></i>
                                <div>
                                    <strong>Protected banking session</strong>
                                    <span>Please sign in to continue your resident parking payment.</span>
                                </div>
                            </div>

                            <div class="form-group">
                                <label><?= e($channel['login_label']) ?></label>
                                <input class="input" id="bankUsername" type="text" placeholder="<?= e($channel['login_placeholder']) ?>">
                            </div>

                            <div class="form-group">
                                <label><?= e($channel['password_label']) ?></label>
                                <div class="field-wrap">
                                    <input class="input" id="bankPassword" type="password" placeholder="<?= e($channel['password_placeholder']) ?>">
                                    <i class="fas fa-eye"></i>
                                </div>
                            </div>

                            <div class="note-box">
                                <i class="fas fa-circle-info"></i>
                                <span><?= e($channel['demo_note']) ?></span>
                            </div>

                            <div class="btn-row">
                                <button type="button" class="btn btn-primary" id="loginBtn"><i class="fas fa-right-to-bracket"></i> Login & Continue</button>
                                <a class="btn btn-secondary" href="resident_vehicles.php"><i class="fas fa-arrow-left"></i> Cancel</a>
                            </div>
                        </div>

                        <div class="screen" id="otpScreen">
                            <h2 class="section-title">Secure OTP / TAC Verification</h2>
                            <p class="section-subtitle">Enter the one-time password to authorise this payment.</p>

                            <div class="login-banner">
                                <i class="fas fa-mobile-screen-button"></i>
                                <div>
                                    <strong>Verification required</strong>
                                    <span><?= e($channel['otp_note']) ?></span>
                                </div>
                            </div>

                            <div class="otp-box">
                                <input maxlength="1" class="otp-digit" inputmode="numeric">
                                <input maxlength="1" class="otp-digit" inputmode="numeric">
                                <input maxlength="1" class="otp-digit" inputmode="numeric">
                                <input maxlength="1" class="otp-digit" inputmode="numeric">
                                <input maxlength="1" class="otp-digit" inputmode="numeric">
                                <input maxlength="1" class="otp-digit" inputmode="numeric">
                            </div>

                            <div class="payment-review">
                                <div class="review-item"><span>Pay To</span><strong>Ixora Apartment Management</strong></div>
                                <div class="review-item"><span>Amount</span><strong><?= e($amountText) ?></strong></div>
                                <div class="review-item"><span>Reference</span><strong><?= e($merchantRef) ?></strong></div>
                            </div>

                            <div class="btn-row" style="margin-top:18px;">
                                <button type="button" class="btn btn-primary" id="authoriseBtn"><i class="fas fa-shield-halved"></i> Authorise Payment</button>
                                <button type="button" class="btn btn-secondary" id="backLoginBtn"><i class="fas fa-arrow-left"></i> Back</button>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    <?php endif; ?>
</div>

<div class="overlay" id="processingLayer">
    <div class="overlay-card" id="processingCard">
        <div class="spinner"></div>
        <div class="success-icon"><i class="fas fa-check"></i></div>
        <h2 id="processingTitle">Processing payment...</h2>
        <p id="processingText">Please wait while the secure gateway responds.</p>
        <div class="ref-box">
            <span>Transaction Reference</span>
            <strong id="generatedRef">-</strong>
        </div>
        <div class="receipt-download-hint">
            <i class="fas fa-circle-info"></i>
            Download the receipt image and keep it as your payment proof.
        </div>
        <div class="overlay-actions">
            <button type="button" class="btn btn-secondary" id="downloadReceiptBtn"><i class="fas fa-download"></i> Download Receipt Image</button>
            <a class="btn btn-primary" id="returnWithRefBtn" href="resident_vehicles.php"><i class="fas fa-arrow-right"></i> Return to SmartVMS</a>
        </div>
    </div>
</div>


<div class="message-modal" id="messageModal">
    <div class="message-card">
        <div class="message-icon"><i class="fas fa-circle-exclamation"></i></div>
        <h3 id="messageModalTitle">Please check</h3>
        <p id="messageModalText">Something needs your attention.</p>
        <button type="button" class="btn btn-primary" id="messageModalOkBtn">OK</button>
    </div>
</div>

<script>
const isCard = <?= $isCard ? 'true' : 'false' ?>;
const loginBtn = document.getElementById('loginBtn');
const authoriseBtn = document.getElementById('authoriseBtn');
const backLoginBtn = document.getElementById('backLoginBtn');
const cardPayBtn = document.getElementById('cardPayBtn');
const loginScreen = document.getElementById('loginScreen');
const otpScreen = document.getElementById('otpScreen');
const stepLogin = document.getElementById('stepLogin');
const stepOtp = document.getElementById('stepOtp');
const stepDone = document.getElementById('stepDone');
const processingLayer = document.getElementById('processingLayer');
const processingCard = document.getElementById('processingCard');
const processingTitle = document.getElementById('processingTitle');
const processingText = document.getElementById('processingText');
const generatedRef = document.getElementById('generatedRef');
const downloadReceiptBtn = document.getElementById('downloadReceiptBtn');
const returnWithRefBtn = document.getElementById('returnWithRefBtn');

let finalReference = '';


const receiptData = {
    merchant: <?= json_encode('Ixora Apartment Management') ?>,
    channel: <?= json_encode($channel['title']) ?>,
    channelKey: <?= json_encode($channel['key']) ?>,
    amount: <?= json_encode($amountText) ?>,
    vehicle: <?= json_encode($plateText) ?>,
    parkingSlot: <?= json_encode($slotText) ?>,
    billingMonth: <?= json_encode($billingMonth) ?>,
    merchantRef: <?= json_encode($merchantRef) ?>,
    paymentId: <?= json_encode((string)$paymentId) ?>,
    generatedAt: ''
};

function drawRoundedRect(ctx, x, y, width, height, radius) {
    const r = Math.min(radius, width / 2, height / 2);
    ctx.beginPath();
    ctx.moveTo(x + r, y);
    ctx.arcTo(x + width, y, x + width, y + height, r);
    ctx.arcTo(x + width, y + height, x, y + height, r);
    ctx.arcTo(x, y + height, x, y, r);
    ctx.arcTo(x, y, x + width, y, r);
    ctx.closePath();
}

function drawReceiptRow(ctx, label, value, y) {
    ctx.fillStyle = '#64748b';
    ctx.font = '700 20px Arial';
    ctx.fillText(label.toUpperCase(), 90, y);

    ctx.fillStyle = '#0f172a';
    ctx.font = '800 23px Arial';
    ctx.textAlign = 'right';
    ctx.fillText(value || '-', 990, y);
    ctx.textAlign = 'left';

    ctx.strokeStyle = '#e2e8f0';
    ctx.lineWidth = 2;
    ctx.beginPath();
    ctx.moveTo(90, y + 24);
    ctx.lineTo(990, y + 24);
    ctx.stroke();
}

function downloadPaymentReceiptImage() {
    if (!finalReference) {
        showMessageDialog('Please complete the payment first before downloading the receipt.', 'Receipt not ready');
        return;
    }

    const canvas = document.createElement('canvas');
    canvas.width = 1080;
    canvas.height = 1450;
    const ctx = canvas.getContext('2d');

    const gradient = ctx.createLinearGradient(0, 0, 1080, 1450);
    gradient.addColorStop(0, '#eef6ff');
    gradient.addColorStop(1, '#ffffff');
    ctx.fillStyle = gradient;
    ctx.fillRect(0, 0, canvas.width, canvas.height);

    drawRoundedRect(ctx, 54, 54, 972, 1342, 44);
    ctx.fillStyle = '#ffffff';
    ctx.fill();
    ctx.strokeStyle = '#dbeafe';
    ctx.lineWidth = 3;
    ctx.stroke();

    const headerGradient = ctx.createLinearGradient(54, 54, 1026, 260);
    headerGradient.addColorStop(0, '#38bdf8');
    headerGradient.addColorStop(1, '#2563eb');
    drawRoundedRect(ctx, 54, 54, 972, 206, 44);
    ctx.fillStyle = headerGradient;
    ctx.fill();

    ctx.fillStyle = '#ffffff';
    ctx.font = '900 54px Arial';
    ctx.fillText('SmartVMS', 90, 136);

    ctx.font = '700 24px Arial';
    ctx.fillText('Resident Parking Payment Receipt', 90, 188);

    ctx.textAlign = 'right';
    ctx.font = '800 24px Arial';
    ctx.fillText('PAID / SUBMITTED', 990, 126);
    ctx.font = '600 20px Arial';
    ctx.fillText(receiptData.generatedAt, 990, 170);
    ctx.textAlign = 'left';

    ctx.fillStyle = '#0f172a';
    ctx.font = '900 38px Arial';
    ctx.fillText('Payment Receipt', 90, 340);

    ctx.fillStyle = '#64748b';
    ctx.font = '700 22px Arial';
    ctx.fillText('This receipt is generated by SmartVMS payment demo.', 90, 380);

    drawRoundedRect(ctx, 90, 430, 900, 150, 28);
    ctx.fillStyle = '#eff6ff';
    ctx.fill();
    ctx.strokeStyle = '#bfdbfe';
    ctx.lineWidth = 2;
    ctx.stroke();

    ctx.fillStyle = '#64748b';
    ctx.font = '800 22px Arial';
    ctx.fillText('AMOUNT PAID', 126, 488);

    ctx.fillStyle = '#0f172a';
    ctx.font = '900 62px Arial';
    ctx.fillText(receiptData.amount, 126, 555);

    ctx.textAlign = 'right';
    ctx.fillStyle = '#2563eb';
    ctx.font = '900 30px Arial';
    ctx.fillText(receiptData.channel, 950, 520);
    ctx.textAlign = 'left';

    let y = 665;
    drawReceiptRow(ctx, 'Transaction Reference', finalReference, y);
    y += 84;
    drawReceiptRow(ctx, 'Merchant', receiptData.merchant, y);
    y += 84;
    drawReceiptRow(ctx, 'Vehicle', receiptData.vehicle, y);
    y += 84;
    drawReceiptRow(ctx, 'Parking Slot', receiptData.parkingSlot, y);
    y += 84;
    drawReceiptRow(ctx, 'Billing Month', receiptData.billingMonth, y);
    y += 84;
    drawReceiptRow(ctx, 'Merchant Reference', receiptData.merchantRef, y);
    y += 84;
    drawReceiptRow(ctx, 'Payment ID', '#' + receiptData.paymentId, y);

    drawRoundedRect(ctx, 90, 1220, 900, 90, 22);
    ctx.fillStyle = '#f8fafc';
    ctx.fill();
    ctx.strokeStyle = '#e2e8f0';
    ctx.stroke();

    ctx.fillStyle = '#64748b';
    ctx.font = '700 20px Arial';
    ctx.fillText('IMPORTANT NOTE', 126, 1264);

    ctx.fillStyle = '#0f172a';
    ctx.font = '700 20px Arial';
    ctx.fillText('Admin verification may still be required before parking access is activated.', 126, 1294);

    ctx.fillStyle = '#94a3b8';
    ctx.font = '600 18px Arial';
    ctx.fillText('Generated by SmartVMS · This is a demo receipt for FYP presentation.', 90, 1360);

    const link = document.createElement('a');
    const safeRef = finalReference.replace(/[^a-zA-Z0-9_-]/g, '');
    link.download = 'SmartVMS_Parking_Receipt_' + safeRef + '.png';
    link.href = canvas.toDataURL('image/png');
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}



function markFieldError(field, hasError = true) {
    if (!field) return;

    field.classList.toggle('field-error', hasError);

    const group = field.closest('.form-group');
    if (group) {
        group.classList.toggle('has-error', hasError);
    }
}

function clearFieldErrorOnInput(field) {
    if (!field) return;
    field.addEventListener('input', () => markFieldError(field, false));
}

function validateEmail(value) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
}

function getDigits(value) {
    return (value || '').replace(/\D/g, '');
}



const messageModal = document.getElementById('messageModal');
const messageModalTitle = document.getElementById('messageModalTitle');
const messageModalText = document.getElementById('messageModalText');
const messageModalOkBtn = document.getElementById('messageModalOkBtn');

function showMessageDialog(message, title = 'Please check') {
    if (!messageModal) return;
    messageModalTitle.textContent = title;
    messageModalText.textContent = message;
    messageModal.classList.add('show');
}

function hideMessageDialog() {
    if (messageModal) {
        messageModal.classList.remove('show');
    }
}

if (messageModalOkBtn) {
    messageModalOkBtn.addEventListener('click', hideMessageDialog);
}

if (messageModal) {
    messageModal.addEventListener('click', (event) => {
        if (event.target === messageModal) {
            hideMessageDialog();
        }
    });
}


function showLayer(title, text) {
    processingLayer.classList.add('show');
    processingCard.classList.remove('success');
    processingTitle.textContent = title;
    processingText.textContent = text;
}

function hideLayer() {
    processingLayer.classList.remove('show');
}

function makeReference() {
    const now = new Date();
    const date = now.getFullYear().toString()
        + String(now.getMonth() + 1).padStart(2, '0')
        + String(now.getDate()).padStart(2, '0');
    const random = Math.floor(100000 + Math.random() * 900000);
    const prefix = <?= json_encode(strtoupper($channel['short'])) ?>;
    return prefix + 'PAY' + date + random;
}

function completePayment() {
    finalReference = makeReference();
    showLayer('Connecting to payment network...', 'Please wait while your transaction is being securely processed.');

    setTimeout(() => {
        processingTitle.textContent = isCard ? 'Authorising card payment...' : 'Verifying banking session...';
        processingText.textContent = isCard
            ? 'Checking card details and requesting issuer authorisation.'
            : 'Verifying your bank login and OTP approval.';
    }, 1300);

    setTimeout(() => {
        processingTitle.textContent = 'Finalising transaction...';
        processingText.textContent = 'Generating your payment reference and returning response to SmartVMS.';
    }, 2700);

    setTimeout(() => {
        receiptData.generatedAt = new Date().toLocaleString();
        processingCard.classList.add('success');
        processingTitle.textContent = 'Payment Successful';
        processingText.textContent = 'Your receipt image is ready. Download it and return to SmartVMS to continue.';
        generatedRef.textContent = finalReference;
        if (stepOtp) stepOtp.classList.remove('active');
        if (stepDone) stepDone.classList.add('active');

        const returnUrl = 'resident_vehicles.php?payment_id=<?= (int)$paymentId ?>&payment_ref=' + encodeURIComponent(finalReference) + '&bank=<?= e($channel['key']) ?>';
        returnWithRefBtn.href = returnUrl;
    }, 4200);
}


['bankUsername', 'bankPassword'].forEach((id) => {
    clearFieldErrorOnInput(document.getElementById(id));
});

if (loginBtn) {
    loginBtn.addEventListener('click', () => {
        const username = document.getElementById('bankUsername').value.trim();
        const password = document.getElementById('bankPassword').value.trim();
        if (!username || !password) {
            markFieldError(document.getElementById('bankUsername'), !username);
            markFieldError(document.getElementById('bankPassword'), !password);
            showMessageDialog('Please enter your login details.', 'Login required');
            return;
        }

        markFieldError(document.getElementById('bankUsername'), false);
        markFieldError(document.getElementById('bankPassword'), false);

        showLayer('Signing in...', 'Establishing a protected session with <?= e($channel['title']) ?>.');

        setTimeout(() => {
            hideLayer();
            loginScreen.classList.remove('active');
            otpScreen.classList.add('active');
            stepLogin.classList.remove('active');
            stepOtp.classList.add('active');
            const first = document.querySelector('.otp-digit');
            if (first) first.focus();
        }, 1500);
    });
}

if (backLoginBtn) {
    backLoginBtn.addEventListener('click', () => {
        otpScreen.classList.remove('active');
        loginScreen.classList.add('active');
        stepOtp.classList.remove('active');
        stepLogin.classList.add('active');
    });
}

if (authoriseBtn) {
    authoriseBtn.addEventListener('click', () => {
        const otp = Array.from(document.querySelectorAll('.otp-digit')).map(input => input.value).join('');
        if (otp.length < 6) {
            showMessageDialog('Please enter the 6-digit OTP / TAC. Demo code is 123456.', 'OTP required');
            return;
        }
        completePayment();
    });
}

if (cardPayBtn) {
    cardPayBtn.addEventListener('click', () => {
        const fields = Array.from(document.querySelectorAll('.card-required'));
        let firstErrorField = null;

        fields.forEach((field) => {
            let invalid = !field.value.trim();

            if (field.id === 'cardEmail') {
                invalid = !validateEmail(field.value.trim());
            }

            if (field.id === 'cardNumber') {
                invalid = cardRawNumber.length < 12;
            }

            if (field.id === 'cardExpiry') {
                invalid = !/^\d{2}\/\d{2}$/.test(field.value.trim());
            }

            if (field.id === 'cardCvv') {
                invalid = !/^\d{3,4}$/.test(field.value.trim());
            }

            markFieldError(field, invalid);

            if (invalid && !firstErrorField) {
                firstErrorField = field;
            }
        });

        if (firstErrorField) {
            showMessageDialog('Please complete all required card, email and billing address details before payment.', 'Card details required');
            firstErrorField.focus();
            return;
        }

        if (cardNumber) {
            maskCardNumber();
        }

        stepLogin.classList.remove('active');
        stepOtp.classList.add('active');
        completePayment();
    });
}

document.querySelectorAll('.otp-digit').forEach((input, index, list) => {
    input.addEventListener('input', () => {
        input.value = input.value.replace(/\D/g, '').slice(0, 1);
        if (input.value && list[index + 1]) list[index + 1].focus();
    });
    input.addEventListener('keydown', (event) => {
        if (event.key === 'Backspace' && !input.value && list[index - 1]) {
            list[index - 1].focus();
        }
    });
});

const cardNumber = document.getElementById('cardNumber');
let cardRawNumber = '';

function formatCardNumber(value) {
    return value.replace(/(.{4})/g, '$1 ').trim();
}

function maskCardNumber() {
    if (!cardNumber || cardRawNumber.length < 4) return;
    const lastFour = cardRawNumber.slice(-4);
    cardNumber.value = '**** **** **** ' + lastFour;
    cardNumber.classList.add('card-masked');
}

if (cardNumber) {
    cardNumber.addEventListener('focus', () => {
        if (cardNumber.classList.contains('card-masked')) {
            cardNumber.value = formatCardNumber(cardRawNumber);
            cardNumber.classList.remove('card-masked');
        }
    });

    cardNumber.addEventListener('input', () => {
        let value = getDigits(cardNumber.value).slice(0, 16);
        cardRawNumber = value;
        cardNumber.value = formatCardNumber(value);
        markFieldError(cardNumber, false);
    });

    cardNumber.addEventListener('blur', () => {
        if (cardRawNumber.length >= 12) {
            maskCardNumber();
        }
    });
}

document.querySelectorAll('.card-required').forEach((field) => {
    if (field.id !== 'cardNumber') {
        clearFieldErrorOnInput(field);
    }
});

const cardExpiry = document.getElementById('cardExpiry');
if (cardExpiry) {
    cardExpiry.addEventListener('input', () => {
        let value = cardExpiry.value.replace(/\D/g, '').slice(0, 4);
        if (value.length >= 3) value = value.slice(0, 2) + '/' + value.slice(2);
        cardExpiry.value = value;
    });
}

if (downloadReceiptBtn) {
    downloadReceiptBtn.addEventListener('click', downloadPaymentReceiptImage);
}
</script>
</body>
</html>
