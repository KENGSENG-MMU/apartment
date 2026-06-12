<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * Generate SmartVMS visitor receipt PDF and QR image.
 *
 * Return:
 * [
 *   'pdf_path' => full PDF path,
 *   'qr_path'  => full QR SVG path
 * ]
 */
function svms_generate_receipt_pdf(array $data): array
{
    $publicDir = realpath(__DIR__ . '/../public');

    if (!$publicDir) {
        $publicDir = __DIR__ . '/../public';
    }

    $receiptDir = $publicDir . '/receipts';
    $qrDir = $receiptDir . '/qr';

    if (!is_dir($receiptDir)) {
        mkdir($receiptDir, 0777, true);
    }

    if (!is_dir($qrDir)) {
        mkdir($qrDir, 0777, true);
    }

    $bookingId = (int)($data['booking_id'] ?? 0);

    if ($bookingId <= 0) {
        throw new Exception('Invalid booking ID for receipt.');
    }

    $visitorName = (string)($data['visitor_name'] ?? '-');
    $visitorEmail = (string)($data['visitor_email'] ?? '-');
    $visitorPhone = (string)($data['visitor_phone'] ?? '-');
    $visitorIc = (string)($data['visitor_ic'] ?? '-');
    $plateNo = (string)($data['plate_no'] ?? '-');
    $purpose = (string)($data['purpose'] ?? '-');
    $visitType = (string)($data['visit_type'] ?? '-');
    $arrival = (string)($data['arrival'] ?? '-');
    $validUntil = (string)($data['valid_until'] ?? '-');
    $residentUnit = (string)($data['resident_unit'] ?? '-');
    $parkingSlot = (string)($data['parking_slot'] ?? 'Not assigned');
    $approvedAt = (string)($data['approved_at'] ?? date('Y-m-d H:i:s'));

    $qrToken = trim((string)($data['qr_token'] ?? ''));

    if ($qrToken !== '') {
        $qrText = $qrToken;
    } else {
        $qrText = 'SMARTVMS_BOOKING_' . $bookingId;
    }

    /*
     * Use Bacon QR SVG instead of PNG.
     * This avoids common XAMPP GD image extension issues.
     */
    $qrPath = $qrDir . '/booking_' . $bookingId . '.svg';

    $renderer = new ImageRenderer(
        new RendererStyle(320),
        new SvgImageBackEnd()
    );

    $writer = new Writer($renderer);
    $qrSvg = $writer->writeString($qrText);

    file_put_contents($qrPath, $qrSvg);

    $qrBase64 = base64_encode($qrSvg);

    $html = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <style>
            @page {
                margin: 22px;
            }

            body {
                font-family: DejaVu Sans, Arial, sans-serif;
                color: #111827;
                background: #ffffff;
                font-size: 13px;
            }

            .receipt {
                border: 1px solid #d9e2f2;
                border-radius: 18px;
                overflow: hidden;
            }

            .header {
                background: #14213d;
                color: #ffffff;
                padding: 24px;
            }

            .brand {
                font-size: 15px;
                font-weight: bold;
                letter-spacing: 1px;
                color: #bfdbfe;
                margin-bottom: 8px;
            }

            .title {
                font-size: 28px;
                font-weight: bold;
                margin: 0 0 8px 0;
            }

            .subtitle {
                font-size: 13px;
                color: #dbeafe;
                margin: 0;
                line-height: 1.5;
            }

            .body {
                padding: 24px;
            }

            .status-box {
                background: #ecfdf3;
                border: 1px solid #abefc6;
                color: #027a48;
                border-radius: 12px;
                padding: 12px 14px;
                font-weight: bold;
                margin-bottom: 18px;
            }

            .grid {
                width: 100%;
                border-collapse: collapse;
                margin-top: 10px;
            }

            .grid td {
                border: 1px solid #e5e7eb;
                padding: 10px 12px;
                vertical-align: top;
            }

            .label {
                width: 34%;
                background: #f8fafc;
                color: #475569;
                font-weight: bold;
            }

            .value {
                color: #111827;
                font-weight: bold;
            }

            .plate {
                display: inline-block;
                background: #020617;
                color: white;
                border-radius: 8px;
                padding: 5px 10px;
                letter-spacing: 1.5px;
                font-family: DejaVu Sans Mono, monospace;
            }

            .qr-section {
                text-align: center;
                margin-top: 22px;
                padding-top: 20px;
                border-top: 1px dashed #cbd5e1;
            }

            .qr-section img {
                width: 190px;
                height: 190px;
            }

            .qr-caption {
                margin-top: 9px;
                color: #475569;
                font-size: 12px;
                font-weight: bold;
            }

            .note {
                margin-top: 18px;
                padding: 12px 14px;
                background: #eff6ff;
                border: 1px solid #bfdbfe;
                border-radius: 12px;
                color: #1e3a8a;
                font-size: 12px;
                line-height: 1.5;
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
                <div class="brand">SmartVMS IXORA</div>
                <h1 class="title">Visitor Receipt</h1>
                <p class="subtitle">
                    Your visit request has been approved. Please show this QR pass at the guard gate.
                </p>
            </div>

            <div class="body">
                <div class="status-box">
                    APPROVED VISITOR PASS
                </div>

                <table class="grid">
                    <tr>
                        <td class="label">Booking ID</td>
                        <td class="value">#' . htmlspecialchars((string)$bookingId) . '</td>
                    </tr>
                    <tr>
                        <td class="label">Visitor Name</td>
                        <td class="value">' . htmlspecialchars($visitorName) . '</td>
                    </tr>
                    <tr>
                        <td class="label">Visitor Email</td>
                        <td class="value">' . htmlspecialchars($visitorEmail) . '</td>
                    </tr>
                    <tr>
                        <td class="label">Phone Number</td>
                        <td class="value">' . htmlspecialchars($visitorPhone) . '</td>
                    </tr>
                    <tr>
                        <td class="label">IC / Passport</td>
                        <td class="value">' . htmlspecialchars($visitorIc) . '</td>
                    </tr>
                    <tr>
                        <td class="label">Vehicle Plate</td>
                        <td class="value"><span class="plate">' . htmlspecialchars($plateNo) . '</span></td>
                    </tr>
                    <tr>
                        <td class="label">Purpose</td>
                        <td class="value">' . htmlspecialchars($purpose) . '</td>
                    </tr>
                    <tr>
                        <td class="label">Visit Type</td>
                        <td class="value">' . htmlspecialchars($visitType) . '</td>
                    </tr>
                    <tr>
                        <td class="label">Arrival</td>
                        <td class="value">' . htmlspecialchars($arrival) . '</td>
                    </tr>
                    <tr>
                        <td class="label">Valid Until</td>
                        <td class="value">' . htmlspecialchars($validUntil) . '</td>
                    </tr>
                    <tr>
                        <td class="label">Resident Unit</td>
                        <td class="value">' . htmlspecialchars($residentUnit) . '</td>
                    </tr>
                    <tr>
                        <td class="label">Parking Slot</td>
                        <td class="value">' . htmlspecialchars($parkingSlot) . '</td>
                    </tr>
                    <tr>
                        <td class="label">Approved At</td>
                        <td class="value">' . htmlspecialchars($approvedAt) . '</td>
                    </tr>
                </table>

                <div class="qr-section">
                    <img src="data:image/svg+xml;base64,' . $qrBase64 . '" alt="QR Code">
                    <div class="qr-caption">Scan this QR code at the guard gate.</div>
                </div>

                <div class="note">
                    Please follow the Valid Until time shown on this receipt.<br>
                    One Time Visit QR can only be used once. Multiple In-Out Visit QR can be used multiple times within the valid period.
                </div>

                <div class="footer">
                    This is an auto-generated SmartVMS receipt.
                </div>
            </div>
        </div>
    </body>
    </html>';

    $options = new Options();
    $options->set('isRemoteEnabled', true);
    $options->set('isHtml5ParserEnabled', true);

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $pdfPath = $receiptDir . '/booking_' . $bookingId . '.pdf';
    file_put_contents($pdfPath, $dompdf->output());

    if (!file_exists($pdfPath) || filesize($pdfPath) <= 0) {
        throw new Exception('PDF file was not created.');
    }

    return [
        'pdf_path' => $pdfPath,
        'qr_path' => $qrPath
    ];
}
