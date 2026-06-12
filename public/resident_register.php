<?php
require_once '../core/security.php';

$pdo = db();

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
$error = '';

function clean_text($value) {
    return trim((string)$value);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $csrf)) {
        $error = 'Invalid security token. Please refresh the page.';
    } else {
        $fullName = clean_text($_POST['full_name'] ?? '');
        $email = strtolower(clean_text($_POST['email'] ?? ''));
        $contactNumber = clean_text($_POST['contact_number'] ?? '');
        $unitId = (int)($_POST['unit_id'] ?? 0);
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if ($fullName === '' || $email === '' || $unitId <= 0 || $password === '' || $confirmPassword === '') {
            $error = 'Please fill in all required fields.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif (strlen($password) < 6) {
            $error = 'Password must be at least 6 characters.';
        } elseif ($password !== $confirmPassword) {
            $error = 'Password confirmation does not match.';
        } else {
            try {
                $checkEmail = $pdo->prepare("
                    SELECT id 
                    FROM users 
                    WHERE email = ? 
                    LIMIT 1
                ");
                $checkEmail->execute([$email]);

                if ($checkEmail->fetch()) {
                    $error = 'This email is already registered.';
                } else {
                    $checkUnit = $pdo->prepare("
                        SELECT 
                            u.id,
                            u.unit_no,
                            u.block_no,
                            u.floor_no,
                            a.apartment_name
                        FROM units u
                        JOIN apartments a ON a.id = u.apartment_id
                        WHERE u.id = ?
                        AND u.status = 'active'
                        LIMIT 1
                    ");
                    $checkUnit->execute([$unitId]);
                    $unit = $checkUnit->fetch();

                    if (!$unit) {
                        $error = 'Selected unit is invalid.';
                    } else {
                        $occupiedCheck = $pdo->prepare("
                            SELECT ru.id
                            FROM resident_units ru
                            JOIN users us ON us.id = ru.resident_id
                            WHERE ru.unit_id = ?
                            AND ru.status = 'active'
                            AND us.status = 'active'
                            LIMIT 1
                        ");
                        $occupiedCheck->execute([$unitId]);

                        if ($occupiedCheck->fetch()) {
                            $error = 'This unit already has an active resident account.';
                        } else {
                            $pendingCheck = $pdo->prepare("
                                SELECT ru.id
                                FROM resident_units ru
                                JOIN users us ON us.id = ru.resident_id
                                WHERE ru.unit_id = ?
                                AND us.status = 'pending'
                                LIMIT 1
                            ");
                            $pendingCheck->execute([$unitId]);

                            if ($pendingCheck->fetch()) {
                                $error = 'This unit already has a pending registration request.';
                            } else {
                                $pdo->beginTransaction();

                                $stmt = $pdo->prepare("
                                    INSERT INTO users
                                    (full_name, email, contact_number, password_hash, role, status)
                                    VALUES (?, ?, ?, ?, 'resident', 'pending')
                                ");

                                $stmt->execute([
                                    $fullName,
                                    $email,
                                    $contactNumber ?: null,
                                    password_hash($password, PASSWORD_DEFAULT)
                                ]);

                                $residentId = (int)$pdo->lastInsertId();

                                $assign = $pdo->prepare("
                                    INSERT INTO resident_units
                                    (resident_id, unit_id, status)
                                    VALUES (?, ?, 'inactive')
                                ");
                                $assign->execute([$residentId, $unitId]);

                                if (function_exists('log_audit')) {
                                    log_audit(
                                        'RESIDENT_REGISTER_PENDING',
                                        'Resident registration pending: ' . $email . ' / Unit: ' . $unit['unit_no']
                                    );
                                }

                                $pdo->commit();

                                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                                $message = 'Registration submitted successfully. Please wait for admin approval before login.';
                            }
                        }
                    }
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $error = 'Registration error: ' . $e->getMessage();
            }
        }
    }
}

$apartments = $pdo->query("
    SELECT id, apartment_name
    FROM apartments
    WHERE status = 'active'
    ORDER BY apartment_name ASC
")->fetchAll();

$units = $pdo->query("
    SELECT 
        u.id,
        u.apartment_id,
        u.block_no,
        u.floor_no,
        u.unit_no,
        a.apartment_name
    FROM units u
    JOIN apartments a ON a.id = u.apartment_id
    WHERE u.status = 'active'
    ORDER BY a.apartment_name ASC, u.block_no ASC, u.floor_no ASC, u.unit_no ASC
")->fetchAll();

$unitJson = json_encode($units);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Resident Register - <?= e(APP_NAME) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700;800&display=swap" rel="stylesheet">

    <style>
        * {
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
            margin: 0;
            padding: 0;
        }

        body {
            min-height: 100vh;
            background:
                linear-gradient(rgba(0,0,0,.42), rgba(0,0,0,.55)),
                url('dash1.jpg') center center / cover no-repeat;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 26px;
        }

        .card {
            width: 100%;
            max-width: 760px;
            background: rgba(255,255,255,.97);
            border-radius: 26px;
            box-shadow: 0 24px 60px rgba(0,0,0,.25);
            overflow: hidden;
        }

        .header {
            background: linear-gradient(135deg, #0f172a, #2563eb);
            color: white;
            padding: 28px 32px;
        }

        .header h1 {
            font-size: 1.7rem;
            font-weight: 800;
            margin-bottom: 6px;
        }

        .header p {
            opacity: .84;
            font-size: .92rem;
            font-weight: 500;
        }

        .body {
            padding: 30px 32px;
        }

        .grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
        }

        .field {
            margin-bottom: 18px;
        }

        .full {
            grid-column: 1 / -1;
        }

        label {
            display: block;
            font-size: .82rem;
            font-weight: 700;
            color: #374151;
            margin-bottom: 8px;
        }

        input, select {
            width: 100%;
            padding: 13px 14px;
            border: 1px solid #d1d5db;
            border-radius: 14px;
            font-size: .92rem;
            font-weight: 600;
            outline: none;
            background: #f9fafb;
        }

        input:focus, select:focus {
            border-color: #2563eb;
            background: white;
            box-shadow: 0 0 0 4px rgba(37,99,235,.12);
        }

        .btn {
            width: 100%;
            padding: 15px;
            border: none;
            border-radius: 15px;
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            color: white;
            font-size: .98rem;
            font-weight: 800;
            cursor: pointer;
            box-shadow: 0 16px 28px rgba(37,99,235,.24);
        }

        .alert {
            padding: 14px 15px;
            border-radius: 14px;
            margin-bottom: 20px;
            font-size: .88rem;
            font-weight: 700;
            line-height: 1.45;
        }

        .error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }

        .success {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #86efac;
        }

        .note {
            background: #eff6ff;
            color: #1e40af;
            border: 1px solid #bfdbfe;
            padding: 13px 14px;
            border-radius: 14px;
            font-size: .84rem;
            font-weight: 700;
            line-height: 1.5;
            margin-bottom: 20px;
        }

        .links {
            text-align: center;
            margin-top: 22px;
            color: #6b7280;
            font-size: .88rem;
            font-weight: 600;
        }

        .links a {
            color: #2563eb;
            text-decoration: none;
            font-weight: 800;
        }

        @media (max-width: 720px) {
            .grid {
                grid-template-columns: 1fr;
            }

            .full {
                grid-column: auto;
            }
        }
    </style>
</head>
<body>

<div class="card">
    <div class="header">
        <h1>Resident Account Registration</h1>
        <p>Apply for a resident account by selecting your apartment block, floor, and unit.</p>
    </div>

    <div class="body">
        <?php if ($error): ?>
            <div class="alert error"><?= e($error) ?></div>
        <?php endif; ?>

        <?php if ($message): ?>
            <div class="alert success">
                <?= e($message) ?>
                <br>
                <a href="user_login.php" style="color:#166534;font-weight:800;">Go to User Login</a>
            </div>
        <?php endif; ?>

        <div class="note">
            Your account will be reviewed by admin. You can login only after admin approval.
        </div>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">

            <div class="grid">
                <div class="field">
                    <label>Full Name</label>
                    <input type="text" name="full_name" value="<?= e($_POST['full_name'] ?? '') ?>" placeholder="Example: Tan Wei Ming" required>
                </div>

                <div class="field">
                    <label>Contact Number</label>
                    <input type="text" name="contact_number" value="<?= e($_POST['contact_number'] ?? '') ?>" placeholder="Example: 0123456789">
                </div>

                <div class="field full">
                    <label>Email Address</label>
                    <input type="email" name="email" value="<?= e($_POST['email'] ?? '') ?>" placeholder="resident@email.com" required>
                </div>

                <div class="field">
                    <label>Apartment</label>
                    <select id="apartmentSelect" required>
                        <option value="">-- Select Apartment --</option>
                        <?php foreach ($apartments as $apt): ?>
                            <option value="<?= (int)$apt['id'] ?>">
                                <?= e($apt['apartment_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label>Block</label>
                    <select id="blockSelect" required>
                        <option value="">-- Select Block --</option>
                    </select>
                </div>

                <div class="field">
                    <label>Floor</label>
                    <select id="floorSelect" required>
                        <option value="">-- Select Floor --</option>
                    </select>
                </div>

                <div class="field">
                    <label>Unit</label>
                    <select name="unit_id" id="unitSelect" required>
                        <option value="">-- Select Unit --</option>
                    </select>
                </div>

                <div class="field">
                    <label>Password</label>
                    <input type="password" name="password" placeholder="Minimum 6 characters" required>
                </div>

                <div class="field">
                    <label>Confirm Password</label>
                    <input type="password" name="confirm_password" placeholder="Repeat password" required>
                </div>
            </div>

            <button type="submit" class="btn">Submit Resident Registration</button>
        </form>

        <div class="links">
            Already approved? <a href="user_login.php">Login here</a>
            <br>
            Back to <a href="r_landingpage.php">User Portal</a>
        </div>
    </div>
</div>

<script>
const units = <?= $unitJson ?: '[]' ?>;

const apartmentSelect = document.getElementById('apartmentSelect');
const blockSelect = document.getElementById('blockSelect');
const floorSelect = document.getElementById('floorSelect');
const unitSelect = document.getElementById('unitSelect');

function clearSelect(select, placeholder) {
    select.innerHTML = `<option value="">${placeholder}</option>`;
}

function uniqueValues(arr) {
    return [...new Set(arr)].sort((a, b) => {
        if (!isNaN(a) && !isNaN(b)) {
            return Number(a) - Number(b);
        }
        return String(a).localeCompare(String(b));
    });
}

apartmentSelect.addEventListener('change', function () {
    clearSelect(blockSelect, '-- Select Block --');
    clearSelect(floorSelect, '-- Select Floor --');
    clearSelect(unitSelect, '-- Select Unit --');

    const apartmentId = this.value;

    const filtered = units.filter(u => String(u.apartment_id) === String(apartmentId));
    const blocks = uniqueValues(filtered.map(u => u.block_no));

    blocks.forEach(block => {
        blockSelect.innerHTML += `<option value="${block}">Block ${block}</option>`;
    });
});

blockSelect.addEventListener('change', function () {
    clearSelect(floorSelect, '-- Select Floor --');
    clearSelect(unitSelect, '-- Select Unit --');

    const apartmentId = apartmentSelect.value;
    const block = this.value;

    const filtered = units.filter(u =>
        String(u.apartment_id) === String(apartmentId) &&
        String(u.block_no) === String(block)
    );

    const floors = uniqueValues(filtered.map(u => u.floor_no));

    floors.forEach(floor => {
        floorSelect.innerHTML += `<option value="${floor}">Floor ${floor}</option>`;
    });
});

floorSelect.addEventListener('change', function () {
    clearSelect(unitSelect, '-- Select Unit --');

    const apartmentId = apartmentSelect.value;
    const block = blockSelect.value;
    const floor = this.value;

    const filtered = units.filter(u =>
        String(u.apartment_id) === String(apartmentId) &&
        String(u.block_no) === String(block) &&
        String(u.floor_no) === String(floor)
    );

    filtered.forEach(unit => {
        unitSelect.innerHTML += `<option value="${unit.id}">${unit.unit_no}</option>`;
    });
});
</script>

</body>
</html>