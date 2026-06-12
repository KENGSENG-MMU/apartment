<?php
/**
 * SmartVMS Admin Platform Payment Lock
 *
 * Put this file in:
 * C:\xampp\htdocs\apartment\public\admin_platform_payment_guard.php
 *
 * Then include it once in admin_sidebar.php near the bottom:
 * require_once __DIR__ . '/admin_platform_payment_guard.php';
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Asia/Kuala_Lumpur');

if (!function_exists('smvms_pg_e')) {
    function smvms_pg_e($value): string {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('smvms_pg_table_exists')) {
    function smvms_pg_table_exists(PDO $pdo, string $table): bool {
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
}

if (!function_exists('smvms_pg_column_exists')) {
    function smvms_pg_column_exists(PDO $pdo, string $table, string $column): bool {
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
}

if (!function_exists('smvms_pg_ensure_table')) {
    function smvms_pg_ensure_table(PDO $pdo): void {
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
}

if (!function_exists('smvms_pg_current_apartment_id')) {
    function smvms_pg_current_apartment_id(PDO $pdo): int {
        if (!empty($_SESSION['apartment_id'])) {
            return (int)$_SESSION['apartment_id'];
        }

        $uid = (int)($_SESSION['uid'] ?? 0);

        if ($uid > 0 && smvms_pg_table_exists($pdo, 'users') && smvms_pg_column_exists($pdo, 'users', 'apartment_id')) {
            try {
                $stmt = $pdo->prepare("SELECT apartment_id FROM users WHERE id = ? LIMIT 1");
                $stmt->execute([$uid]);
                $id = $stmt->fetchColumn();

                if ($id) {
                    $_SESSION['apartment_id'] = (int)$id;
                    return (int)$id;
                }
            } catch (Throwable $e) {
                // fallback below
            }
        }

        if (smvms_pg_table_exists($pdo, 'apartments')) {
            try {
                $id = $pdo->query("SELECT id FROM apartments ORDER BY id ASC LIMIT 1")->fetchColumn();
                if ($id) {
                    $_SESSION['apartment_id'] = (int)$id;
                    return (int)$id;
                }
            } catch (Throwable $e) {
                // fallback below
            }
        }

        return 1;
    }
}

if (!function_exists('smvms_pg_current_apartment_name')) {
    function smvms_pg_current_apartment_name(PDO $pdo, int $apartmentId): string {
        if (smvms_pg_table_exists($pdo, 'apartments')) {
            try {
                $stmt = $pdo->prepare("SELECT apartment_name FROM apartments WHERE id = ? LIMIT 1");
                $stmt->execute([$apartmentId]);
                $name = $stmt->fetchColumn();

                if ($name) {
                    return (string)$name;
                }
            } catch (Throwable $e) {
                // fallback below
            }
        }

        return $_SESSION['apartment_name'] ?? 'Ixoro Apartment';
    }
}

if (!function_exists('smvms_pg_payment_status')) {
    function smvms_pg_payment_status(PDO $pdo, int $apartmentId, string $billingMonth): array {
        $stmt = $pdo->prepare("
            INSERT INTO platform_payments (apartment_id, billing_month, amount, status)
            VALUES (?, ?, 300.00, 'unpaid')
            ON DUPLICATE KEY UPDATE
                amount = IF(status = 'paid', amount, VALUES(amount)),
                apartment_id = apartment_id
        ");
        $stmt->execute([$apartmentId, $billingMonth]);

        $stmt = $pdo->prepare("
            SELECT *
            FROM platform_payments
            WHERE apartment_id = ?
            AND billing_month = ?
            LIMIT 1
        ");
        $stmt->execute([$apartmentId, $billingMonth]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
}

try {
    /*
     * Only admin should be blocked.
     * Superadmin can still use the system.
     */
    $role = strtolower((string)($_SESSION['role'] ?? ''));

    if ($role !== 'admin') {
        return;
    }

    /*
     * Do not block the payment page itself, logout, or login pages.
     */
    $currentFile = basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');

    $allowedFiles = [
        'admin_platform_payment.php',
        'logout.php',
        'admin_login.php',
        'staff_login.php',
    ];

    if (in_array($currentFile, $allowedFiles, true)) {
        return;
    }

    if (!function_exists('db')) {
        require_once __DIR__ . '/../core/security.php';
    }

    $pdo = db();

    smvms_pg_ensure_table($pdo);

    $apartmentId = smvms_pg_current_apartment_id($pdo);
    $apartmentName = smvms_pg_current_apartment_name($pdo, $apartmentId);
    $billingMonth = date('Y-m-01');
    $billingMonthInput = date('Y-m');
    $billingMonthLabel = date('M Y', strtotime($billingMonth));

    $payment = smvms_pg_payment_status($pdo, $apartmentId, $billingMonth);
    $status = strtolower((string)($payment['status'] ?? 'unpaid'));

    /*
     * submitted = admin has already paid/submitted payment.
     * paid = superadmin approved.
     * unpaid/rejected = lock system.
     *
     * If you want to unlock ONLY after superadmin approval,
     * change this to: $allowedStatuses = ['paid'];
     */
    $allowedStatuses = ['submitted', 'paid'];

    if (in_array($status, $allowedStatuses, true)) {
        return;
    }

    $statusText = match ($status) {
        'rejected' => 'Payment rejected',
        'unpaid' => 'Payment required',
        default => 'Payment required',
    };

    $paymentUrl = 'admin_platform_payment.php?month=' . urlencode($billingMonthInput);
    ?>
    <style>
        .smartvms-payment-lock-overlay {
            position: fixed;
            inset: 0;
            z-index: 999999;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 26px;
            background:
                linear-gradient(135deg, rgba(15, 23, 42, .56), rgba(15, 23, 42, .38)),
                rgba(255, 255, 255, .18);
            backdrop-filter: blur(9px) grayscale(.25);
            -webkit-backdrop-filter: blur(9px) grayscale(.25);
            font-family: 'Plus Jakarta Sans', system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        .smartvms-payment-lock-card {
            width: min(560px, 92vw);
            border: 1px solid rgba(255, 255, 255, .70);
            border-radius: 28px;
            background:
                radial-gradient(circle at top left, rgba(248, 113, 113, .20), transparent 34%),
                rgba(255, 255, 255, .96);
            box-shadow: 0 28px 70px rgba(15, 23, 42, .30);
            padding: 34px 34px 30px;
            text-align: center;
            color: #0f172a;
        }

        .smartvms-payment-lock-icon {
            width: 76px;
            height: 76px;
            border-radius: 24px;
            display: grid;
            place-items: center;
            margin: 0 auto 18px;
            color: #ffffff;
            background: linear-gradient(135deg, #dc2626, #b91c1c);
            box-shadow: 0 20px 34px rgba(220, 38, 38, .28);
            font-size: 1.9rem;
        }

        .smartvms-payment-lock-tag {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 8px 12px;
            border-radius: 999px;
            color: #b91c1c;
            background: #fee2e2;
            font-size: .72rem;
            font-weight: 950;
            text-transform: uppercase;
            letter-spacing: .06em;
            margin-bottom: 13px;
        }

        .smartvms-payment-lock-card h2 {
            margin: 0;
            font-size: 1.65rem;
            line-height: 1.15;
            letter-spacing: -.05em;
            font-weight: 950;
        }

        .smartvms-payment-lock-card p {
            margin: 12px auto 0;
            max-width: 440px;
            color: #475569;
            font-size: .92rem;
            font-weight: 800;
            line-height: 1.55;
        }

        .smartvms-payment-lock-info {
            margin: 20px auto 0;
            border: 1px solid #fee2e2;
            border-radius: 18px;
            background: #fff7f7;
            padding: 14px 16px;
            text-align: left;
        }

        .smartvms-payment-lock-row {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            padding: 8px 0;
            border-bottom: 1px solid #fee2e2;
            color: #0f172a;
            font-size: .86rem;
            font-weight: 850;
        }

        .smartvms-payment-lock-row:last-child {
            border-bottom: 0;
        }

        .smartvms-payment-lock-row span:first-child {
            color: #64748b;
        }

        .smartvms-payment-lock-row span:last-child {
            text-align: right;
            font-weight: 950;
        }

        .smartvms-payment-lock-actions {
            display: flex;
            justify-content: center;
            margin-top: 24px;
        }

        .smartvms-payment-lock-btn {
            height: 48px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            padding: 0 24px;
            color: #ffffff;
            background: linear-gradient(135deg, #dc2626, #b91c1c);
            box-shadow: 0 18px 34px rgba(220, 38, 38, .25);
            text-decoration: none;
            font-size: .9rem;
            font-weight: 950;
        }

        .smartvms-payment-lock-small {
            margin-top: 14px !important;
            color: #64748b !important;
            font-size: .76rem !important;
            font-weight: 800 !important;
        }

        @media (max-width: 640px) {
            .smartvms-payment-lock-card {
                padding: 28px 22px 24px;
            }

            .smartvms-payment-lock-card h2 {
                font-size: 1.35rem;
            }

            .smartvms-payment-lock-row {
                flex-direction: column;
                gap: 3px;
            }

            .smartvms-payment-lock-row span:last-child {
                text-align: left;
            }
        }
    </style>

    <div class="smartvms-payment-lock-overlay" role="dialog" aria-modal="true">
        <div class="smartvms-payment-lock-card">
            <div class="smartvms-payment-lock-icon">
                <i class="fas fa-lock"></i>
            </div>

            <div class="smartvms-payment-lock-tag">
                <i class="fas fa-circle-exclamation"></i>
                <?= smvms_pg_e($statusText) ?>
            </div>

            <h2>Platform payment is required</h2>

            <p>
                Your admin functions are temporarily locked because the current monthly platform payment has not been completed.
                Please complete payment to continue using SmartVMS.
            </p>

            <div class="smartvms-payment-lock-info">
                <div class="smartvms-payment-lock-row">
                    <span>Apartment</span>
                    <span><?= smvms_pg_e($apartmentName) ?></span>
                </div>

                <div class="smartvms-payment-lock-row">
                    <span>Billing Month</span>
                    <span><?= smvms_pg_e($billingMonthLabel) ?></span>
                </div>

                <div class="smartvms-payment-lock-row">
                    <span>Amount</span>
                    <span>RM 300.00</span>
                </div>

                <div class="smartvms-payment-lock-row">
                    <span>Status</span>
                    <span><?= smvms_pg_e(strtoupper($status)) ?></span>
                </div>
            </div>

            <div class="smartvms-payment-lock-actions">
                <a href="<?= smvms_pg_e($paymentUrl) ?>" class="smartvms-payment-lock-btn">
                    <i class="fas fa-credit-card"></i>
                    Go to Payment Page
                </a>
            </div>

            <p class="smartvms-payment-lock-small">
                After payment is submitted, the admin panel will be unlocked automatically.
            </p>
        </div>
    </div>

    <script>
        document.documentElement.style.overflow = 'hidden';
        document.body.style.overflow = 'hidden';
    </script>
    <?php
} catch (Throwable $e) {
    /*
     * Fail open, not fail closed:
     * If database/table checking has an error, do not break every admin page.
     * You can temporarily uncomment the line below for debugging.
     */
    // echo '<pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
}
