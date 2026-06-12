<?php
require_once '../core/security.php';
require_login(['resident', 'admin', 'superadmin']);

$pdo = db();
$paymentId = (int)($_GET['id'] ?? $_GET['payment_id'] ?? 0);
$currentUserId = (int)($_SESSION['uid'] ?? 0);
$currentRole = $_SESSION['role'] ?? '';

function pr_text($value): string {
    return ($value !== null && $value !== '') ? (string)$value : '-';
}

function pr_money($amount): string {
    return 'RM ' . number_format((float)$amount, 2);
}

function pr_has_column(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare("\n            SELECT COUNT(*)\n            FROM INFORMATION_SCHEMA.COLUMNS\n            WHERE TABLE_SCHEMA = DATABASE()\n            AND TABLE_NAME = ?\n            AND COLUMN_NAME = ?\n        ");
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function pr_stop(string $message): void {
    http_response_code(400);
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Parking Receipt</title>';
    echo '<style>body{font-family:Arial,sans-serif;background:#f1f5f9;color:#0f172a;padding:40px}.box{max-width:680px;margin:auto;background:#fff;border-radius:20px;padding:28px;box-shadow:0 20px 50px rgba(15,23,42,.12)}h1{margin:0 0 10px}.btn{display:inline-block;margin-top:18px;background:#2563eb;color:#fff;text-decoration:none;border-radius:12px;padding:12px 16px;font-weight:700}</style>';
    echo '</head><body><div class="box"><h1>Receipt Not Available</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p><a class="btn" href="javascript:history.back()">Back</a></div></body></html>';
    exit;
}

if ($paymentId <= 0) {
    pr_stop('Invalid parking payment ID.');
}

$hasFullName = pr_has_column($pdo, 'users', 'full_name');
$hasVehicleModel = pr_has_column($pdo, 'resident_vehicles', 'vehicle_model');
$hasVehicleColor = pr_has_column($pdo, 'resident_vehicles', 'vehicle_color');

$residentNameSql = $hasFullName ? 'u.full_name AS resident_name' : 'NULL AS resident_name';
$vehicleModelSql = $hasVehicleModel ? 'rv.vehicle_model' : 'NULL AS vehicle_model';
$vehicleColorSql = $hasVehicleColor ? 'rv.vehicle_color' : 'NULL AS vehicle_color';

$sql = "\n    SELECT\n        pp.*,\n        rpa.monthly_fee,\n        rpa.start_date,\n        rpa.end_date,\n        rpa.status AS assignment_status,\n        rv.plate_no,\n        {$vehicleModelSql},\n        {$vehicleColorSql},\n        ps.block_name,\n        ps.slot_no,\n        u.email AS resident_email,\n        {$residentNameSql},\n        un.block_no,\n        un.floor_no,\n        un.unit_no,\n        a.apartment_name,\n        a.address,\n        verifier.email AS verified_by_email\n    FROM parking_payments pp\n    JOIN resident_parking_assignments rpa ON rpa.id = pp.assignment_id\n    JOIN resident_vehicles rv ON rv.id = rpa.vehicle_id\n    JOIN parking_slots ps ON ps.id = rpa.slot_id\n    JOIN users u ON u.id = pp.resident_id\n    LEFT JOIN resident_units ru ON ru.resident_id = u.id AND ru.status = 'active'\n    LEFT JOIN units un ON un.id = ru.unit_id\n    LEFT JOIN apartments a ON a.id = un.apartment_id\n    LEFT JOIN users verifier ON verifier.id = pp.verified_by\n    WHERE pp.id = ?\n";

$params = [$paymentId];

if ($currentRole === 'resident') {
    $sql .= " AND pp.resident_id = ?";
    $params[] = $currentUserId;
}

$sql .= " LIMIT 1";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$payment = $stmt->fetch();

if (!$payment) {
    pr_stop('This receipt cannot be found or you do not have permission to view it.');
}

if ($payment['payment_status'] !== 'paid') {
    pr_stop('Official receipt is only available after admin verifies the payment as paid.');
}

$receiptNo = 'SVMS-PARK-' . str_pad((string)$paymentId, 6, '0', STR_PAD_LEFT);
$residentName = pr_text($payment['resident_name'] ?: $payment['resident_email']);
$unitText = !empty($payment['unit_no'])
    ? 'Block ' . pr_text($payment['block_no']) . ' / Floor ' . pr_text($payment['floor_no']) . ' / Unit ' . pr_text($payment['unit_no'])
    : 'No active unit assigned';
$verifiedAt = pr_text($payment['verified_at'] ?: $payment['paid_at']);
$generatedAt = date('Y-m-d H:i:s');

$html = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        @page { margin: 26px; }
        body {
            font-family: DejaVu Sans, Arial, sans-serif;
            color: #111827;
            background: #ffffff;
            font-size: 13px;
        }
        .receipt {
            border: 1px solid #dbe4f0;
            border-radius: 18px;
            overflow: hidden;
        }
        .header {
            background: #111c36;
            color: #ffffff;
            padding: 26px;
        }
        .brand {
            font-size: 14px;
            font-weight: bold;
            letter-spacing: 1px;
            color: #93c5fd;
            margin-bottom: 8px;
        }
        .title {
            font-size: 27px;
            font-weight: bold;
            margin: 0 0 8px 0;
        }
        .subtitle {
            color: #dbeafe;
            line-height: 1.5;
            margin: 0;
        }
        .body { padding: 24px; }
        .status {
            background: #ecfdf3;
            border: 1px solid #abefc6;
            color: #027a48;
            border-radius: 12px;
            padding: 12px 14px;
            font-weight: bold;
            margin-bottom: 18px;
        }
        .summary {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 18px;
        }
        .summary td {
            border: 1px solid #e5e7eb;
            padding: 10px 12px;
            vertical-align: top;
        }
        .label {
            width: 35%;
            background: #f8fafc;
            color: #475569;
            font-weight: bold;
        }
        .value {
            color: #111827;
            font-weight: bold;
        }
        .amount-box {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            color: #1e3a8a;
            border-radius: 14px;
            padding: 16px;
            margin: 18px 0;
        }
        .amount-label { font-size: 12px; font-weight: bold; color: #475569; }
        .amount { font-size: 28px; font-weight: bold; margin-top: 5px; }
        .plate {
            display: inline-block;
            background: #020617;
            color: #ffffff;
            border-radius: 8px;
            padding: 5px 10px;
            letter-spacing: 1.5px;
            font-family: DejaVu Sans Mono, monospace;
        }
        .note {
            margin-top: 18px;
            padding: 12px 14px;
            background: #f8fafc;
            border: 1px dashed #cbd5e1;
            border-radius: 12px;
            color: #475569;
            line-height: 1.5;
            font-size: 12px;
        }
        .footer {
            margin-top: 18px;
            color: #64748b;
            font-size: 11px;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="receipt">
        <div class="header">
            <div class="brand">SmartVMS Parking Subscription</div>
            <h1 class="title">Official Parking Payment Receipt</h1>
            <p class="subtitle">This receipt confirms that the resident parking fee has been verified by management.</p>
        </div>

        <div class="body">
            <div class="status">PAID AND VERIFIED - GATE ACCESS ALLOWED FOR THIS BILLING MONTH</div>

            <table class="summary">
                <tr><td class="label">Receipt No.</td><td class="value">' . htmlspecialchars($receiptNo, ENT_QUOTES, 'UTF-8') . '</td></tr>
                <tr><td class="label">Payment ID</td><td class="value">#' . htmlspecialchars((string)$paymentId, ENT_QUOTES, 'UTF-8') . '</td></tr>
                <tr><td class="label">Billing Month</td><td class="value">' . htmlspecialchars(pr_text($payment['billing_month']), ENT_QUOTES, 'UTF-8') . '</td></tr>
                <tr><td class="label">Resident Name</td><td class="value">' . htmlspecialchars($residentName, ENT_QUOTES, 'UTF-8') . '</td></tr>
                <tr><td class="label">Resident Email</td><td class="value">' . htmlspecialchars(pr_text($payment['resident_email']), ENT_QUOTES, 'UTF-8') . '</td></tr>
                <tr><td class="label">Apartment</td><td class="value">' . htmlspecialchars(pr_text($payment['apartment_name']), ENT_QUOTES, 'UTF-8') . '</td></tr>
                <tr><td class="label">Unit</td><td class="value">' . htmlspecialchars($unitText, ENT_QUOTES, 'UTF-8') . '</td></tr>
                <tr><td class="label">Parking Slot</td><td class="value">' . htmlspecialchars(pr_text($payment['slot_no']) . ' - ' . pr_text($payment['block_name']), ENT_QUOTES, 'UTF-8') . '</td></tr>
                <tr><td class="label">Vehicle Plate</td><td class="value"><span class="plate">' . htmlspecialchars(pr_text($payment['plate_no']), ENT_QUOTES, 'UTF-8') . '</span></td></tr>
                <tr><td class="label">Vehicle Model</td><td class="value">' . htmlspecialchars(pr_text($payment['vehicle_model']), ENT_QUOTES, 'UTF-8') . '</td></tr>
                <tr><td class="label">Payment Method</td><td class="value">' . htmlspecialchars(pr_text($payment['payment_method']), ENT_QUOTES, 'UTF-8') . '</td></tr>
                <tr><td class="label">Verified At</td><td class="value">' . htmlspecialchars($verifiedAt, ENT_QUOTES, 'UTF-8') . '</td></tr>
                <tr><td class="label">Verified By</td><td class="value">' . htmlspecialchars(pr_text($payment['verified_by_email']), ENT_QUOTES, 'UTF-8') . '</td></tr>
            </table>

            <div class="amount-box">
                <div class="amount-label">Amount Paid</div>
                <div class="amount">' . htmlspecialchars(pr_money($payment['amount']), ENT_QUOTES, 'UTF-8') . '</div>
            </div>

            <div class="note">
                This receipt is generated by SmartVMS. Resident vehicle access is allowed only when the current billing month payment status is marked as paid by admin.
            </div>

            <div class="footer">
                Generated at ' . htmlspecialchars($generatedAt, ENT_QUOTES, 'UTF-8') . ' · SmartVMS Enterprise
            </div>
        </div>
    </div>
</body>
</html>';

try {
    require_once __DIR__ . '/../vendor/autoload.php';

    if (!class_exists('Dompdf\\Dompdf')) {
        throw new Exception('Dompdf library not found.');
    }

    $options = new Dompdf\Options();
    $options->set('isRemoteEnabled', true);
    $options->set('isHtml5ParserEnabled', true);

    $dompdf = new Dompdf\Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $fileName = $receiptNo . '.pdf';
    $dompdf->stream($fileName, ['Attachment' => false]);
    exit;
} catch (Throwable $e) {
    echo $html;
    exit;
}
