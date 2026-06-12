<?php
require_once '../core/security.php';
require_login(['resident']);

$pdo = db();

$residentId = (int)($_SESSION['uid'] ?? 0);
$residentEmail = $_SESSION['email'] ?? '';

$message = '';
$error = '';

function has_column_feedback(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare("\n            SELECT COUNT(*)\n            FROM INFORMATION_SCHEMA.COLUMNS\n            WHERE TABLE_SCHEMA = DATABASE()\n            AND TABLE_NAME = ?\n            AND COLUMN_NAME = ?\n        ");
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function table_exists_feedback(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare("\n            SELECT COUNT(*)\n            FROM INFORMATION_SCHEMA.TABLES\n            WHERE TABLE_SCHEMA = DATABASE()\n            AND TABLE_NAME = ?\n        ");
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function safe_count_feedback(PDO $pdo, string $sql, array $params = []): int {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function ensure_resident_feedback_table(PDO $pdo): void {
    $pdo->exec("\n        CREATE TABLE IF NOT EXISTS resident_feedback (\n            id INT AUTO_INCREMENT PRIMARY KEY,\n            resident_id INT NOT NULL,\n            rating TINYINT NOT NULL DEFAULT 5,\n            category VARCHAR(50) NOT NULL DEFAULT 'General',\n            subject VARCHAR(150) NOT NULL,\n            message TEXT NOT NULL,\n            status VARCHAR(30) NOT NULL DEFAULT 'open',\n            admin_reply TEXT NULL,\n            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n            updated_at DATETIME NULL DEFAULT NULL,\n            INDEX idx_resident_feedback_resident (resident_id),\n            INDEX idx_resident_feedback_status (status),\n            INDEX idx_resident_feedback_created (created_at)\n        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n    ");

    if (!has_column_feedback($pdo, 'resident_feedback', 'rating')) {
        $pdo->exec("\n            ALTER TABLE resident_feedback\n            ADD COLUMN rating TINYINT NOT NULL DEFAULT 5 AFTER resident_id\n        ");
    }

    if (!has_column_feedback($pdo, 'resident_feedback', 'category')) {
        $pdo->exec("\n            ALTER TABLE resident_feedback\n            ADD COLUMN category VARCHAR(50) NOT NULL DEFAULT 'General' AFTER rating\n        ");
    }

    if (!has_column_feedback($pdo, 'resident_feedback', 'subject')) {
        $pdo->exec("\n            ALTER TABLE resident_feedback\n            ADD COLUMN subject VARCHAR(150) NOT NULL DEFAULT 'Resident Feedback' AFTER category\n        ");
    }

    if (!has_column_feedback($pdo, 'resident_feedback', 'admin_reply')) {
        $pdo->exec("\n            ALTER TABLE resident_feedback\n            ADD COLUMN admin_reply TEXT NULL AFTER status\n        ");
    }

    if (!has_column_feedback($pdo, 'resident_feedback', 'updated_at')) {
        $pdo->exec("\n            ALTER TABLE resident_feedback\n            ADD COLUMN updated_at DATETIME NULL DEFAULT NULL AFTER created_at\n        ");
    }
}

function build_unit_text_feedback(array $resident): string {
    if (empty($resident['unit_no'])) {
        return 'No Unit Assigned';
    }

    $block = trim((string)($resident['block_no'] ?? ''));
    $floor = trim((string)($resident['floor_no'] ?? ''));
    $unit = trim((string)($resident['unit_no'] ?? ''));

    if ($block !== '' && $floor !== '') {
        return 'Block ' . $block . ' / Floor ' . $floor . ' / Unit ' . $unit;
    }

    return 'Unit ' . $unit;
}

ensure_resident_feedback_table($pdo);

$hasFullName = has_column_feedback($pdo, 'users', 'full_name');
$residentNameSql = $hasFullName ? "u.full_name AS resident_name" : "NULL AS resident_name";

$stmt = $pdo->prepare("\n    SELECT\n        u.id,\n        u.email,\n        {$residentNameSql},\n        ru.unit_id,\n        a.apartment_name,\n        a.address,\n        un.block_no,\n        un.floor_no,\n        un.unit_no\n    FROM users u\n    LEFT JOIN resident_units ru\n        ON ru.resident_id = u.id\n        AND ru.status = 'active'\n    LEFT JOIN units un ON un.id = ru.unit_id\n    LEFT JOIN apartments a ON a.id = un.apartment_id\n    WHERE u.id = ?\n    LIMIT 1\n");
$stmt->execute([$residentId]);
$resident = $stmt->fetch();

if (!$resident) {
    $resident = [
        'resident_name' => '',
        'apartment_name' => '',
        'address' => '',
        'block_no' => '',
        'floor_no' => '',
        'unit_no' => ''
    ];
}

$residentName = ($resident['resident_name'] ?? '') ?: explode('@', $residentEmail)[0];
$unitText = build_unit_text_feedback($resident);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';

    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
        $error = 'Invalid security token. Please refresh the page.';
    } else {
        $rating = (int)($_POST['rating'] ?? 0);
        $category = trim($_POST['category'] ?? 'General');
        $feedbackMessage = trim($_POST['message'] ?? '');

        $allowedCategories = [
            'General',
            'Security',
            'Parking',
            'Visitor Access',
            'Maintenance',
            'Complaint',
            'Suggestion'
        ];

        if ($rating < 1 || $rating > 5) {
            $error = 'Please select a rating from 1 to 5 stars.';
        } elseif (!in_array($category, $allowedCategories, true)) {
            $error = 'Invalid feedback category.';
        } elseif ($feedbackMessage === '') {
            $error = 'Please enter your feedback message.';
        } elseif (mb_strlen($feedbackMessage) > 1500) {
            $error = 'Feedback message is too long. Please keep it under 1500 characters.';
        } else {
            try {
                $subject = $category . ' Feedback - ' . $rating . ' Star';

                $stmt = $pdo->prepare("\n                    INSERT INTO resident_feedback\n                        (resident_id, rating, category, subject, message, status, created_at)\n                    VALUES\n                        (?, ?, ?, ?, ?, 'open', NOW())\n                ");
                $stmt->execute([
                    $residentId,
                    $rating,
                    $category,
                    $subject,
                    $feedbackMessage
                ]);

                if (function_exists('log_audit')) {
                    log_audit(
                        'RESIDENT_FEEDBACK_SUBMITTED',
                        'Resident submitted feedback. Resident ID: ' . $residentId . '. Rating: ' . $rating
                    );
                }

                $message = 'Feedback submitted successfully.';
            } catch (Throwable $e) {
                $error = 'Unable to submit feedback. Please try again.';
            }
        }
    }
}

$notificationCount = table_exists_feedback($pdo, 'notifications')
    ? safe_count_feedback($pdo, "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0", [$residentId])
    : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Feedback - <?= e(APP_NAME) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <style>
:root {
    --surface: rgba(255, 255, 255, 0.94);
    --line: #e2e8f0;
    --navy: #0f172a;
    --text: #334155;
    --muted: #64748b;
    --blue: #2563eb;
    --blue-dark: #1e40af;
    --blue-soft: #eff6ff;
    --red: #ef4444;
    --red-soft: #fff1f2;
    --yellow: #f59e0b;
    --yellow-soft: #fffbeb;
    --shadow-sm: 0 8px 20px rgba(15, 23, 42, 0.045);
    --shadow-md: 0 18px 45px rgba(15, 23, 42, 0.09);
}

* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
    font-family: 'Plus Jakarta Sans', sans-serif;
}

body {
    min-height: 100vh;
    color: var(--text);
    background:
        radial-gradient(circle at 10% 18%, rgba(147, 197, 253, 0.13) 0 72px, transparent 74px),
        radial-gradient(circle at 90% 30%, rgba(191, 219, 254, 0.18) 0 95px, transparent 97px),
        radial-gradient(circle at 16% 82%, rgba(186, 230, 253, 0.14) 0 62px, transparent 64px),
        radial-gradient(circle at 86% 87%, rgba(219, 234, 254, 0.24) 0 86px, transparent 88px),
        linear-gradient(180deg, #ffffff 0%, #f3f6fb 100%);
    overflow-x: hidden;
}

a {
    color: inherit;
    text-decoration: none;
}

.navbar {
    height: 76px;
    padding: 0 5%;
    background: rgba(255, 255, 255, 0.92);
    backdrop-filter: blur(18px);
    -webkit-backdrop-filter: blur(18px);
    border-bottom: 1px solid var(--line);
    position: sticky;
    top: 0;
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.brand {
    font-size: 1.55rem;
    font-weight: 900;
    color: var(--navy);
    letter-spacing: -0.8px;
}

.brand span {
    color: var(--blue);
}

.nav-links {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 8px;
}

.nav-btn {
    padding: 10px 14px;
    border-radius: 999px;
    color: #344054;
    background: transparent;
    border: 0;
    text-decoration: none;
    font-size: 0.82rem;
    font-weight: 800;
    line-height: 1;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    transition: 0.22s ease;
    white-space: nowrap;
}

.nav-btn:hover,
.nav-btn.active {
    color: var(--blue);
    background: var(--blue-soft);
}

.nav-btn.active {
    border: 1px solid #bfdbfe;
}

.nav-btn.logout {
    color: #dc2626;
    background: var(--red-soft);
}

.nav-btn.logout:hover {
    color: #b91c1c;
    background: #ffe4e6;
}

.notification-nav-btn {
    position: relative;
}

.nav-notification-badge {
    position: absolute;
    top: -5px;
    right: -4px;
    min-width: 20px;
    height: 20px;
    padding: 0 6px;
    border-radius: 999px;
    background: #dc2626;
    color: #ffffff;
    font-size: 0.65rem;
    font-weight: 900;
    line-height: 20px;
    text-align: center;
    border: 2px solid #ffffff;
    box-shadow: 0 8px 18px rgba(220, 38, 38, 0.25);
}

.page {
    width: min(920px, calc(100% - 48px));
    margin: 0 auto;
    padding: 42px 0 68px;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    gap: 24px;
    margin-bottom: 24px;
    padding-bottom: 22px;
    border-bottom: 1px solid var(--line);
}

.header-kicker {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: var(--blue);
    background: rgba(239, 246, 255, 0.9);
    border: 1px solid #dbeafe;
    padding: 7px 12px;
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 900;
    letter-spacing: 0.35px;
    margin-bottom: 14px;
}

.header-info h1 {
    font-size: 2.5rem;
    font-weight: 900;
    color: var(--navy);
    letter-spacing: -1.5px;
    line-height: 1.08;
    margin-bottom: 10px;
}

.header-info p {
    color: var(--muted);
    font-size: 1rem;
    font-weight: 650;
    max-width: 620px;
}

.user-badge {
    min-width: 250px;
    padding: 14px 20px;
    border-radius: 18px;
    background: rgba(255, 255, 255, 0.82);
    border: 1px solid var(--line);
    box-shadow: var(--shadow-sm);
    display: flex;
    align-items: center;
    gap: 12px;
}

.user-icon {
    width: 38px;
    height: 38px;
    border-radius: 12px;
    background: var(--blue-soft);
    color: var(--blue);
    display: flex;
    align-items: center;
    justify-content: center;
}

.user-badge small {
    display: block;
    color: var(--muted);
    font-size: 0.68rem;
    font-weight: 900;
    letter-spacing: 0.7px;
    text-transform: uppercase;
    margin-bottom: 2px;
}

.user-badge strong {
    display: block;
    color: var(--navy);
    font-size: 0.92rem;
    font-weight: 900;
}

.user-badge .sub {
    color: var(--muted);
    font-size: 0.78rem;
    font-weight: 700;
    margin-top: 2px;
}

.feedback-card {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: 24px;
    box-shadow: var(--shadow-md);
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);
    padding: 34px;
}

.form-top {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 20px;
    margin-bottom: 24px;
}

.form-top h2 {
    color: var(--navy);
    font-size: 1.35rem;
    font-weight: 900;
    margin-bottom: 8px;
}

.form-top p {
    color: var(--muted);
    font-size: 0.92rem;
    font-weight: 650;
    line-height: 1.5;
}

.form-icon {
    width: 52px;
    height: 52px;
    border-radius: 16px;
    background: var(--blue-soft);
    color: var(--blue);
    border: 1px solid #dbeafe;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    flex-shrink: 0;
}

.alert {
    border-radius: 16px;
    padding: 14px 16px;
    margin-bottom: 18px;
    font-weight: 800;
    line-height: 1.5;
}

.alert.success {
    color: #166534;
    background: #dcfce7;
    border: 1px solid #bbf7d0;
}

.alert.error {
    color: #991b1b;
    background: #fee2e2;
    border: 1px solid #fecaca;
}

.form-label {
    display: block;
    color: var(--muted);
    font-size: 0.73rem;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: 0.55px;
    margin-bottom: 9px;
}

.rating-row {
    margin-bottom: 22px;
}

.stars {
    display: flex;
    gap: 10px;
}

.star-btn {
    width: 62px;
    height: 54px;
    border: 1px solid #fde68a;
    border-radius: 16px;
    background: #ffffff;
    color: #d1d5db;
    cursor: pointer;
    font-size: 1.35rem;
    transition: 0.2s ease;
}

.star-btn.active {
    background: var(--yellow-soft);
    color: var(--yellow);
    border-color: #fcd34d;
    box-shadow: 0 10px 20px rgba(245, 158, 11, 0.08);
}

.star-btn:hover {
    transform: translateY(-2px);
}

.field-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 18px;
    margin-bottom: 18px;
}

.field-box,
.message-wrap {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

select,
input,
textarea {
    width: 100%;
    border-radius: 16px;
    border: 1px solid var(--line);
    background: #ffffff;
    color: var(--navy);
    padding: 0 16px;
    font-size: 0.92rem;
    font-weight: 800;
    outline: none;
    box-shadow: none;
}

select,
input {
    min-height: 56px;
}

textarea {
    min-height: 210px;
    padding: 16px;
    resize: vertical;
    line-height: 1.5;
}

select:focus,
input:focus,
textarea:focus {
    border-color: #bfdbfe;
    box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.08);
}

input[readonly] {
    background: #f8fafc;
    color: var(--navy);
}

textarea::placeholder {
    color: #94a3b8;
}

.bottom-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 18px;
    margin-top: 22px;
}

.small-note {
    color: var(--muted);
    font-size: 0.82rem;
    font-weight: 650;
    line-height: 1.45;
}

.submit-btn {
    border: 0;
    cursor: pointer;
    min-height: 48px;
    padding: 0 24px;
    border-radius: 999px;
    background: var(--blue);
    color: #ffffff;
    font-size: 0.88rem;
    font-weight: 900;
    display: inline-flex;
    align-items: center;
    gap: 10px;
    box-shadow: 0 12px 22px rgba(37, 99, 235, 0.18);
    transition: 0.22s ease;
    white-space: nowrap;
}

.submit-btn:hover {
    background: var(--blue-dark);
    transform: translateY(-2px);
}

@media (max-width: 1180px) {
    .navbar {
        height: auto;
        padding: 16px 24px;
        align-items: flex-start;
        flex-direction: column;
        gap: 12px;
    }

    .nav-links {
        width: 100%;
        flex-wrap: wrap;
        justify-content: flex-start;
    }
}

@media (max-width: 820px) {
    .page-header {
        flex-direction: column;
        align-items: flex-start;
    }

    .user-badge {
        width: 100%;
    }

    .field-row {
        grid-template-columns: 1fr;
    }

    .bottom-row {
        flex-direction: column;
        align-items: stretch;
    }

    .submit-btn {
        width: 100%;
        justify-content: center;
    }
}

@media (max-width: 620px) {
    .page {
        width: min(100% - 28px, 920px);
        padding-top: 26px;
    }

    .header-info h1 {
        font-size: 2rem;
    }

    .feedback-card {
        padding: 24px;
    }

    .form-top {
        flex-direction: column;
    }

    .stars {
        display: grid;
        grid-template-columns: repeat(5, 1fr);
    }

    .star-btn {
        width: 100%;
    }

    .nav-btn {
        padding: 9px 11px;
        font-size: 0.76rem;
    }
}
    </style>
</head>
<body>

<nav class="navbar">
    <a href="resident.php" class="brand">Smart<span>VMS</span></a>

    <div class="nav-links">
        <a href="resident.php" class="nav-btn">
            <i class="fas fa-th-large"></i>
            Dashboard
        </a>

        <a href="notifications.php" class="nav-btn notification-nav-btn">
            <i class="fas fa-bell"></i>
            Notifications
            <?php if ($notificationCount > 0): ?>
                <span class="nav-notification-badge">
                    <?= $notificationCount > 99 ? '99+' : (int)$notificationCount ?>
                </span>
            <?php endif; ?>
        </a>

        <a href="resident_invite.php" class="nav-btn">
            <i class="fas fa-user-plus"></i>
            Invite Guest
        </a>

        <a href="resident_requests.php" class="nav-btn">
            <i class="fas fa-clipboard-check"></i>
            Requests
        </a>

        <a href="resident_vehicles.php" class="nav-btn">
            <i class="fas fa-square-parking"></i>
            My Parking
        </a>

        <a href="resident_feedback.php" class="nav-btn active">
            <i class="fas fa-comment-dots"></i>
            Feedback
        </a>

        <a href="../core/logout.php" class="nav-btn logout">
            <i class="fas fa-power-off"></i>
            Logout
        </a>
    </div>
</nav>

<main class="page">
    <section class="page-header">
        <div class="header-info">
            <div class="header-kicker">
                <i class="fas fa-comment-dots"></i>
                Resident Feedback
            </div>

            <h1>Write Feedback</h1>
            <p>Send your rating and message to management. Keep it simple and clear.</p>
        </div>

        <div class="user-badge">
            <div class="user-icon">
                <i class="fas fa-user"></i>
            </div>
            <div>
                <small>Resident</small>
                <strong><?= e($residentName) ?></strong>
                <div class="sub"><?= e($unitText) ?></div>
            </div>
        </div>
    </section>

    <section class="feedback-card">
        <div class="form-top">
            <div>
                <h2>Feedback Form</h2>
                <p>Select a rating, choose a category, then write your message below.</p>
            </div>

            <div class="form-icon">
                <i class="fas fa-paper-plane"></i>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert success"><?= e($message) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert error"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="POST" id="feedbackForm">
            <?= csrf_field() ?>

            <input type="hidden" name="rating" id="ratingInput" value="5">

            <div class="rating-row">
                <label class="form-label">Rating</label>

                <div class="stars" aria-label="Rating">
                    <button type="button" class="star-btn active" data-rating="1">
                        <i class="fas fa-star"></i>
                    </button>

                    <button type="button" class="star-btn active" data-rating="2">
                        <i class="fas fa-star"></i>
                    </button>

                    <button type="button" class="star-btn active" data-rating="3">
                        <i class="fas fa-star"></i>
                    </button>

                    <button type="button" class="star-btn active" data-rating="4">
                        <i class="fas fa-star"></i>
                    </button>

                    <button type="button" class="star-btn active" data-rating="5">
                        <i class="fas fa-star"></i>
                    </button>
                </div>
            </div>

            <div class="field-row">
                <div class="field-box">
                    <label class="form-label">Category</label>
                    <select name="category" required>
                        <option value="General">General</option>
                        <option value="Security">Security</option>
                        <option value="Parking">Parking</option>
                        <option value="Visitor Access">Visitor Access</option>
                        <option value="Maintenance">Maintenance</option>
                        <option value="Complaint">Complaint</option>
                        <option value="Suggestion">Suggestion</option>
                    </select>
                </div>

                <div class="field-box">
                    <label class="form-label">Resident</label>
                    <input type="text" value="<?= e($residentName) ?>" readonly>
                </div>
            </div>

            <div class="message-wrap">
                <label class="form-label">Message</label>

                <textarea
                    name="message"
                    maxlength="1500"
                    placeholder="Write your feedback here..."
                    required
                ></textarea>
            </div>

            <div class="bottom-row">
                <div class="small-note">
                    Your feedback will be reviewed by apartment management.
                </div>

                <button type="submit" class="submit-btn">
                    <i class="fas fa-paper-plane"></i>
                    Submit Feedback
                </button>
            </div>
        </form>
    </section>
</main>

<?php if ($message): ?>
<script>
Swal.fire({
    icon: 'success',
    title: 'Success',
    text: <?= json_encode($message) ?>,
    confirmButtonColor: '#2563eb',
    background: '#ffffff',
    color: '#0f172a'
});
</script>
<?php endif; ?>

<?php if ($error): ?>
<script>
Swal.fire({
    icon: 'error',
    title: 'Error',
    text: <?= json_encode($error) ?>,
    confirmButtonColor: '#2563eb',
    background: '#ffffff',
    color: '#0f172a'
});
</script>
<?php endif; ?>

<script>
const ratingInput = document.getElementById('ratingInput');
const stars = document.querySelectorAll('.star-btn');

function setRating(value) {
    ratingInput.value = value;

    stars.forEach(function(star) {
        const starValue = Number(star.dataset.rating);
        star.classList.toggle('active', starValue <= value);
    });
}

stars.forEach(function(star) {
    star.addEventListener('click', function() {
        setRating(Number(star.dataset.rating));
    });
});
</script>

<?php require_once __DIR__ . '/resident_notification_popup.php'; ?>
</body>
</html>
