<?php

if (!function_exists('parking_auto_table_exists')) {
    function parking_auto_table_exists(PDO $pdo, string $table): bool {
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

if (!function_exists('parking_auto_has_column')) {
    function parking_auto_has_column(PDO $pdo, string $table, string $column): bool {
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

if (!function_exists('parking_auto_safe_count')) {
    function parking_auto_safe_count(PDO $pdo, string $sql, array $params = []): int {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('parking_auto_generate_qr_token')) {
    function parking_auto_generate_qr_token(): string {
        return bin2hex(random_bytes(24));
    }
}

if (!function_exists('parking_auto_notify')) {
    function parking_auto_notify(PDO $pdo, ?int $userId, string $title, string $message, string $type = 'booking'): void {
        if (!$userId || !function_exists('create_notification')) {
            return;
        }

        try {
            create_notification($pdo, $userId, $title, $message, $type);
        } catch (Throwable $e) {
            // Ignore notification error
        }
    }
}

if (!function_exists('parking_auto_audit')) {
    function parking_auto_audit(string $action, string $description): void {
        if (!function_exists('log_audit')) {
            return;
        }

        try {
            log_audit($action, $description);
        } catch (Throwable $e) {
            // Ignore audit error
        }
    }
}

if (!function_exists('parking_auto_slot_text')) {
    function parking_auto_slot_text(array $slot): string {
        $block = $slot['block_name'] ?? '';
        $slotNo = $slot['slot_no'] ?? '';

        if ($block === '' && $slotNo === '') {
            return 'visitor parking slot';
        }

        return trim($block . ' ' . $slotNo);
    }
}

if (!function_exists('parking_auto_release_slot_if_free')) {
    function parking_auto_release_slot_if_free(PDO $pdo, ?int $slotId): bool {
        if (!$slotId) {
            return false;
        }

        if (!parking_auto_table_exists($pdo, 'parking_slots')) {
            return false;
        }

        if (!parking_auto_has_column($pdo, 'bookings', 'slot_id')) {
            return false;
        }

        try {
            $activeCount = parking_auto_safe_count($pdo, "
                SELECT COUNT(*)
                FROM bookings
                WHERE slot_id = ?
                AND status IN ('approved', 'allocated', 'checked_in')
            ", [$slotId]);

            if ($activeCount > 0) {
                return false;
            }

            $stmt = $pdo->prepare("
                UPDATE parking_slots
                SET status = 'available'
                WHERE id = ?
                AND slot_type = 'Visitor'
                AND status <> 'maintenance'
            ");
            $stmt->execute([$slotId]);

            return $stmt->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('parking_auto_expire_old_bookings')) {
    function parking_auto_expire_old_bookings(PDO $pdo): int {
        if (!parking_auto_table_exists($pdo, 'bookings')) {
            return 0;
        }

        $hasSlotId = parking_auto_has_column($pdo, 'bookings', 'slot_id');
        $hasUpdatedAt = parking_auto_has_column($pdo, 'bookings', 'updated_at');

        try {
            $slotSql = $hasSlotId ? "slot_id" : "NULL AS slot_id";

            $stmt = $pdo->prepare("
                SELECT
                    id,
                    visitor_user_id,
                    visitor_name,
                    plate_no,
                    end_time,
                    {$slotSql}
                FROM bookings
                WHERE status IN ('pending', 'approved', 'allocated', 'waiting')
                AND end_time < NOW()
                ORDER BY end_time ASC
                LIMIT 200
            ");
            $stmt->execute();
            $expiredBookings = $stmt->fetchAll();

            $expiredCount = 0;

            foreach ($expiredBookings as $booking) {
                $sets = [
                    "status = 'expired'"
                ];

                if ($hasSlotId) {
                    $sets[] = "slot_id = NULL";
                }

                if ($hasUpdatedAt) {
                    $sets[] = "updated_at = NOW()";
                }

                $update = $pdo->prepare("
                    UPDATE bookings
                    SET " . implode(', ', $sets) . "
                    WHERE id = ?
                    AND status IN ('pending', 'approved', 'allocated', 'waiting')
                ");
                $update->execute([(int)$booking['id']]);

                if ($update->rowCount() <= 0) {
                    continue;
                }

                $expiredCount++;

                if ($hasSlotId && !empty($booking['slot_id'])) {
                    parking_auto_release_slot_if_free($pdo, (int)$booking['slot_id']);
                }

                parking_auto_notify(
                    $pdo,
                    (int)($booking['visitor_user_id'] ?? 0),
                    'Visit Booking Expired',
                    'Your visit booking has expired because the visit time has passed. Plate: ' . ($booking['plate_no'] ?? '-'),
                    'booking'
                );

                parking_auto_audit(
                    'AUTO_BOOKING_EXPIRED',
                    'System automatically expired booking #' . $booking['id'] . ', plate ' . ($booking['plate_no'] ?? '-')
                );
            }

            return $expiredCount;
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('parking_auto_get_available_slot')) {
    function parking_auto_get_available_slot(PDO $pdo): ?array {
        if (!parking_auto_table_exists($pdo, 'parking_slots')) {
            return null;
        }

        try {
            $stmt = $pdo->query("
                SELECT *
                FROM parking_slots
                WHERE slot_type = 'Visitor'
                AND status = 'available'
                ORDER BY id ASC
                LIMIT 1
            ");

            $slot = $stmt->fetch();

            if (!$slot) {
                return null;
            }

            $update = $pdo->prepare("
                UPDATE parking_slots
                SET status = 'reserved'
                WHERE id = ?
                AND status = 'available'
                AND slot_type = 'Visitor'
            ");
            $update->execute([(int)$slot['id']]);

            if ($update->rowCount() <= 0) {
                return null;
            }

            return $slot;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('parking_auto_assign_waiting_bookings')) {
    function parking_auto_assign_waiting_bookings(PDO $pdo): int {
        if (!parking_auto_table_exists($pdo, 'bookings')) {
            return 0;
        }

        if (!parking_auto_table_exists($pdo, 'parking_slots')) {
            return 0;
        }

        if (!parking_auto_has_column($pdo, 'bookings', 'slot_id')) {
            return 0;
        }

        $hasQrToken = parking_auto_has_column($pdo, 'bookings', 'qr_token');
        $hasUpdatedAt = parking_auto_has_column($pdo, 'bookings', 'updated_at');

        try {
            $qrSql = $hasQrToken ? "qr_token" : "NULL AS qr_token";

            $stmt = $pdo->prepare("
                SELECT
                    id,
                    visitor_user_id,
                    visitor_name,
                    plate_no,
                    start_time,
                    end_time,
                    {$qrSql}
                FROM bookings
                WHERE status = 'waiting'
                AND end_time >= NOW()
                ORDER BY created_at ASC
                LIMIT 100
            ");
            $stmt->execute();
            $waitingBookings = $stmt->fetchAll();

            $assignedCount = 0;

            foreach ($waitingBookings as $booking) {
                $slot = parking_auto_get_available_slot($pdo);

                if (!$slot) {
                    break;
                }

                $sets = [
                    "status = 'allocated'",
                    "slot_id = ?"
                ];

                $params = [
                    (int)$slot['id']
                ];

                if ($hasQrToken && empty($booking['qr_token'])) {
                    $sets[] = "qr_token = ?";
                    $params[] = parking_auto_generate_qr_token();
                }

                if ($hasUpdatedAt) {
                    $sets[] = "updated_at = NOW()";
                }

                $params[] = (int)$booking['id'];

                $update = $pdo->prepare("
                    UPDATE bookings
                    SET " . implode(', ', $sets) . "
                    WHERE id = ?
                    AND status = 'waiting'
                ");
                $update->execute($params);

                if ($update->rowCount() <= 0) {
                    parking_auto_release_slot_if_free($pdo, (int)$slot['id']);
                    continue;
                }

                $assignedCount++;

                $slotText = parking_auto_slot_text($slot);

                parking_auto_notify(
                    $pdo,
                    (int)($booking['visitor_user_id'] ?? 0),
                    'Visitor Parking Slot Assigned',
                    'A visitor parking slot has been assigned to your booking. Slot: ' . $slotText . '. Plate: ' . ($booking['plate_no'] ?? '-'),
                    'parking'
                );

                parking_auto_audit(
                    'AUTO_WAITING_BOOKING_ALLOCATED',
                    'System automatically allocated waiting booking #' . $booking['id'] . ' to slot ' . $slotText
                );
            }

            return $assignedCount;
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('parking_auto_sync_slot_status')) {
    function parking_auto_sync_slot_status(PDO $pdo): int {
        if (!parking_auto_table_exists($pdo, 'parking_slots')) {
            return 0;
        }

        if (!parking_auto_table_exists($pdo, 'bookings')) {
            return 0;
        }

        if (!parking_auto_has_column($pdo, 'bookings', 'slot_id')) {
            return 0;
        }

        $changed = 0;

        try {
            /*
             * checked_in booking = occupied slot
             */
            $stmt = $pdo->prepare("
                UPDATE parking_slots ps
                JOIN bookings b ON b.slot_id = ps.id
                SET ps.status = 'occupied'
                WHERE ps.slot_type = 'Visitor'
                AND ps.status <> 'maintenance'
                AND b.status = 'checked_in'
            ");
            $stmt->execute();
            $changed += $stmt->rowCount();

            /*
             * approved / allocated booking = reserved slot
             */
            $stmt = $pdo->prepare("
                UPDATE parking_slots ps
                JOIN bookings b ON b.slot_id = ps.id
                SET ps.status = 'reserved'
                WHERE ps.slot_type = 'Visitor'
                AND ps.status NOT IN ('maintenance', 'occupied')
                AND b.status IN ('approved', 'allocated')
            ");
            $stmt->execute();
            $changed += $stmt->rowCount();

            /*
             * No active booking uses this slot = available
             */
            $stmt = $pdo->prepare("
                UPDATE parking_slots ps
                SET ps.status = 'available'
                WHERE ps.slot_type = 'Visitor'
                AND ps.status IN ('reserved', 'occupied')
                AND NOT EXISTS (
                    SELECT 1
                    FROM bookings b
                    WHERE b.slot_id = ps.id
                    AND b.status IN ('approved', 'allocated', 'checked_in')
                )
            ");
            $stmt->execute();
            $changed += $stmt->rowCount();

            return $changed;
        } catch (Throwable $e) {
            return $changed;
        }
    }
}



if (!function_exists('parking_auto_current_month')) {
    function parking_auto_current_month(): string {
        return date('Y-m');
    }
}

if (!function_exists('parking_auto_first_day_of_month')) {
    function parking_auto_first_day_of_month(string $month): string {
        return $month . '-01';
    }
}

if (!function_exists('parking_auto_last_day_of_month')) {
    function parking_auto_last_day_of_month(string $month): string {
        return date('Y-m-t', strtotime($month . '-01'));
    }
}

if (!function_exists('parking_auto_generate_resident_monthly_invoices')) {
    function parking_auto_generate_resident_monthly_invoices(PDO $pdo, ?string $billingMonth = null): int {
        if (!parking_auto_table_exists($pdo, 'resident_parking_assignments')) {
            return 0;
        }

        if (!parking_auto_table_exists($pdo, 'parking_payments')) {
            return 0;
        }

        $billingMonth = $billingMonth ?: parking_auto_current_month();
        $monthStart = parking_auto_first_day_of_month($billingMonth);
        $monthEnd = parking_auto_last_day_of_month($billingMonth);

        try {
            $stmt = $pdo->prepare("\n                SELECT\n                    id,\n                    resident_id,\n                    monthly_fee,\n                    start_date,\n                    end_date\n                FROM resident_parking_assignments\n                WHERE status = 'active'\n                AND start_date <= ?\n                AND (end_date IS NULL OR end_date >= ?)\n                ORDER BY id ASC\n                LIMIT 1000\n            ");
            $stmt->execute([$monthEnd, $monthStart]);
            $assignments = $stmt->fetchAll();

            $created = 0;

            foreach ($assignments as $assignment) {
                $insert = $pdo->prepare("\n                    INSERT INTO parking_payments\n                    (assignment_id, resident_id, billing_month, amount, payment_status, created_at)\n                    VALUES\n                    (?, ?, ?, ?, 'unpaid', NOW())\n                    ON DUPLICATE KEY UPDATE\n                        amount = amount\n                ");
                $insert->execute([
                    (int)$assignment['id'],
                    (int)$assignment['resident_id'],
                    $billingMonth,
                    (float)$assignment['monthly_fee']
                ]);

                if ($insert->rowCount() === 1) {
                    $created++;
                }
            }

            if ($created > 0) {
                parking_auto_audit(
                    'AUTO_RESIDENT_PARKING_INVOICE_CREATED',
                    'System created ' . $created . ' resident parking invoice(s) for ' . $billingMonth
                );
            }

            return $created;
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('parking_auto_mark_resident_payments_overdue')) {
    function parking_auto_mark_resident_payments_overdue(PDO $pdo, ?string $billingMonth = null): int {
        if (!parking_auto_table_exists($pdo, 'parking_payments')) {
            return 0;
        }

        $billingMonth = $billingMonth ?: parking_auto_current_month();
        $hasUpdatedAt = parking_auto_has_column($pdo, 'parking_payments', 'updated_at');

        try {
            $sets = ["payment_status = 'overdue'"];

            if ($hasUpdatedAt) {
                $sets[] = "updated_at = NOW()";
            }

            $stmt = $pdo->prepare("\n                UPDATE parking_payments\n                SET " . implode(', ', $sets) . "\n                WHERE billing_month < ?\n                AND payment_status IN ('unpaid', 'pending_verification')\n            ");
            $stmt->execute([$billingMonth]);
            $changed = $stmt->rowCount();

            if ($changed > 0) {
                parking_auto_audit(
                    'AUTO_RESIDENT_PARKING_PAYMENT_OVERDUE',
                    'System marked ' . $changed . ' resident parking payment(s) as overdue before ' . $billingMonth
                );
            }

            return $changed;
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('parking_auto_sync_resident_slot_status')) {
    function parking_auto_sync_resident_slot_status(PDO $pdo): int {
        if (!parking_auto_table_exists($pdo, 'parking_slots')) {
            return 0;
        }

        if (!parking_auto_table_exists($pdo, 'resident_parking_assignments')) {
            return 0;
        }

        $changed = 0;

        try {
            /* Active resident assignment = reserved resident slot */
            $stmt = $pdo->prepare("\n                UPDATE parking_slots ps\n                JOIN resident_parking_assignments rpa ON rpa.slot_id = ps.id\n                SET ps.status = 'reserved', ps.updated_at = NOW()\n                WHERE ps.slot_type = 'Resident'\n                AND ps.status <> 'maintenance'\n                AND rpa.status = 'active'\n            ");
            $stmt->execute();
            $changed += $stmt->rowCount();

            /* No active resident assignment = available resident slot */
            $stmt = $pdo->prepare("\n                UPDATE parking_slots ps\n                SET ps.status = 'available', ps.updated_at = NOW()\n                WHERE ps.slot_type = 'Resident'\n                AND ps.status IN ('reserved', 'occupied')\n                AND NOT EXISTS (\n                    SELECT 1\n                    FROM resident_parking_assignments rpa\n                    WHERE rpa.slot_id = ps.id\n                    AND rpa.status = 'active'\n                )\n            ");
            $stmt->execute();
            $changed += $stmt->rowCount();

            return $changed;
        } catch (Throwable $e) {
            return $changed;
        }
    }
}

if (!function_exists('run_parking_automation')) {
    function run_parking_automation(PDO $pdo): array {
        $result = [
            'expired_bookings' => 0,
            'waiting_allocated' => 0,
            'visitor_slot_status_synced' => 0,
            'resident_invoices_created' => 0,
            'resident_payments_overdue' => 0,
            'resident_slot_status_synced' => 0,
            'success' => true
        ];

        try {
            /* Visitor parking automation */
            $result['expired_bookings'] = parking_auto_expire_old_bookings($pdo);
            $result['visitor_slot_status_synced'] += parking_auto_sync_slot_status($pdo);
            $result['waiting_allocated'] = parking_auto_assign_waiting_bookings($pdo);
            $result['visitor_slot_status_synced'] += parking_auto_sync_slot_status($pdo);

            /* Resident parking subscription automation */
            $result['resident_invoices_created'] = parking_auto_generate_resident_monthly_invoices($pdo);
            $result['resident_payments_overdue'] = parking_auto_mark_resident_payments_overdue($pdo);
            $result['resident_slot_status_synced'] = parking_auto_sync_resident_slot_status($pdo);

            /* Backward compatible key for pages that still read old result name */
            $result['slot_status_synced'] = $result['visitor_slot_status_synced'] + $result['resident_slot_status_synced'];

            return $result;
        } catch (Throwable $e) {
            $result['success'] = false;
            $result['error'] = $e->getMessage();
            return $result;
        }
    }
}