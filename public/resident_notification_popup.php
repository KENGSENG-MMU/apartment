<?php
/*
 * Resident Notification Popup
 * This file only adds a popup preview for Notifications.
 */

if (!function_exists('resident_notif_popup_e')) {
    function resident_notif_popup_e($value): string {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('resident_notif_popup_table_exists')) {
    function resident_notif_popup_table_exists(PDO $pdo, string $table): bool {
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

if (!function_exists('resident_notif_popup_short')) {
    function resident_notif_popup_short($text, int $limit = 105): string {
        $text = trim(preg_replace('/\s+/', ' ', (string)($text ?? '')));
        if ($text === '') return '-';

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit) . '...' : $text;
        }

        return strlen($text) > $limit ? substr($text, 0, $limit) . '...' : $text;
    }
}

if (!function_exists('resident_notif_popup_icon')) {
    function resident_notif_popup_icon(string $type): string {
        $type = strtolower($type);

        if (str_contains($type, 'payment')) return 'fa-credit-card';
        if (str_contains($type, 'parking')) return 'fa-square-parking';
        if (str_contains($type, 'security')) return 'fa-shield-halved';
        if (str_contains($type, 'booking')) return 'fa-calendar-check';
        if (str_contains($type, 'visitor')) return 'fa-user-check';
        if (str_contains($type, 'warning')) return 'fa-triangle-exclamation';

        return 'fa-bell';
    }
}

if (!function_exists('resident_notif_popup_link')) {
    function resident_notif_popup_link(array $notification): string {
        $title = strtolower((string)($notification['title'] ?? ''));
        $message = strtolower((string)($notification['message'] ?? ''));
        $type = strtolower((string)($notification['type'] ?? ''));

        $combined = $title . ' ' . $message . ' ' . $type;

        if (
            str_contains($combined, 'payment') ||
            str_contains($combined, 'parking payment') ||
            str_contains($combined, 'unpaid') ||
            str_contains($combined, 'paid') ||
            str_contains($combined, 'receipt')
        ) {
            return 'resident_vehicles.php';
        }

        if (
            str_contains($combined, 'visitor request') ||
            str_contains($combined, 'visit request') ||
            str_contains($combined, 'submitted a visit') ||
            str_contains($combined, 'approved') ||
            str_contains($combined, 'rejected')
        ) {
            return 'resident_requests.php';
        }

        if (
            str_contains($combined, 'pass') ||
            str_contains($combined, 'qr') ||
            str_contains($combined, 'booking')
        ) {
            return 'resident_requests.php';
        }

        return 'notifications.php';
    }
}

$residentNotifRows = [];
$residentNotifUnread = isset($notificationCount) ? (int)$notificationCount : 0;

try {
    if (!isset($pdo) || !$pdo instanceof PDO) {
        if (function_exists('db')) {
            $pdo = db();
        }
    }

    $popupResidentId = isset($residentId) ? (int)$residentId : (int)($_SESSION['uid'] ?? 0);

    if (isset($pdo) && $pdo instanceof PDO && $popupResidentId > 0 && resident_notif_popup_table_exists($pdo, 'notifications')) {
        if ($residentNotifUnread <= 0) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
            $stmt->execute([$popupResidentId]);
            $residentNotifUnread = (int)$stmt->fetchColumn();
        }

        $stmt = $pdo->prepare("
            SELECT id, title, message, type, is_read, created_at
            FROM notifications
            WHERE user_id = ?
            ORDER BY is_read ASC, created_at DESC, id DESC
            LIMIT 4
        ");
        $stmt->execute([$popupResidentId]);
        $residentNotifRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Throwable $e) {
    $residentNotifRows = [];
}
?>

<style id="resident-notification-popup-style">
    .resident-notification-popup {
        position: fixed;
        top: 66px;
        right: 132px;
        width: min(390px, calc(100vw - 28px));
        z-index: 9998;
        border: 1px solid rgba(191, 219, 254, .95);
        border-radius: 24px;
        background: rgba(255, 255, 255, .96);
        backdrop-filter: blur(18px);
        -webkit-backdrop-filter: blur(18px);
        box-shadow: 0 24px 60px rgba(15, 23, 42, .20);
        overflow: hidden;
        opacity: 0;
        pointer-events: none;
        transform: translateY(-8px) scale(.98);
        transition: opacity .18s ease, transform .18s ease;
        font-family: 'Plus Jakarta Sans', system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    }

    .resident-notification-popup.show {
        opacity: 1;
        pointer-events: auto;
        transform: translateY(0) scale(1);
    }

    .resident-notification-popup::before {
        content: "";
        position: absolute;
        top: -8px;
        right: 54px;
        width: 16px;
        height: 16px;
        background: rgba(255, 255, 255, .96);
        border-left: 1px solid rgba(191, 219, 254, .95);
        border-top: 1px solid rgba(191, 219, 254, .95);
        transform: rotate(45deg);
    }

    .resident-notification-popup-head {
        position: relative;
        padding: 18px 18px 14px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        border-bottom: 1px solid #e5e7eb;
        background:
            radial-gradient(circle at top left, rgba(59, 130, 246, .10), transparent 38%),
            linear-gradient(135deg, #ffffff, #f8fbff);
    }

    .resident-notification-popup-title {
        display: flex;
        align-items: center;
        gap: 10px;
        color: #0f172a;
        font-weight: 950;
        font-size: .98rem;
        letter-spacing: -.03em;
    }

    .resident-notification-popup-title i {
        width: 34px;
        height: 34px;
        display: grid;
        place-items: center;
        border-radius: 13px;
        color: #2563eb;
        background: #dbeafe;
    }


    .resident-notification-popup-head-actions {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .resident-notification-read-all {
        height: 30px;
        border: 0;
        border-radius: 999px;
        padding: 0 12px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        color: #2563eb;
        background: #dbeafe;
        font-family: inherit;
        font-size: .72rem;
        font-weight: 950;
        cursor: pointer;
        transition: .18s ease;
    }

    .resident-notification-read-all:hover {
        background: #bfdbfe;
    }

    .resident-notification-read-all:disabled {
        opacity: .55;
        cursor: not-allowed;
    }

    .resident-notification-popup-close {
        width: 30px;
        height: 30px;
        border: 0;
        border-radius: 999px;
        display: inline-grid;
        place-items: center;
        color: #64748b;
        background: #f1f5f9;
        cursor: pointer;
        transition: .18s ease;
    }

    .resident-notification-popup-close:hover {
        color: #dc2626;
        background: #fee2e2;
    }


    .resident-notification-popup-count {
        min-width: 28px;
        height: 28px;
        border-radius: 999px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        color: #ffffff;
        background: #2563eb;
        font-size: .74rem;
        font-weight: 950;
        padding: 0 9px;
    }

    .resident-notification-popup-list {
        max-height: 356px;
        overflow: auto;
        padding: 12px;
        display: grid;
        gap: 10px;
    }

    .resident-notification-popup-item {
        display: grid;
        grid-template-columns: 42px minmax(0, 1fr);
        gap: 11px;
        padding: 13px;
        border: 1px solid #dbeafe;
        border-radius: 17px;
        background: #ffffff;
        text-decoration: none;
        color: #0f172a;
        transition: .18s ease;
    }

    .resident-notification-popup-item:hover {
        transform: translateY(-1px);
        box-shadow: 0 12px 24px rgba(37, 99, 235, .12);
    }

    .resident-notification-popup-item.unread {
        border-color: #93c5fd;
        background: #f8fbff;
    }

    .resident-notification-popup-icon {
        width: 42px;
        height: 42px;
        border-radius: 15px;
        display: grid;
        place-items: center;
        color: #2563eb;
        background: #dbeafe;
    }

    .resident-notification-popup-item.unread .resident-notification-popup-icon {
        color: #ffffff;
        background: linear-gradient(135deg, #38bdf8, #2563eb);
    }

    .resident-notification-popup-message-title {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        color: #0f172a;
        font-size: .84rem;
        font-weight: 950;
        line-height: 1.25;
    }

    .resident-notification-popup-dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: #ef4444;
        flex: 0 0 auto;
        box-shadow: 0 0 0 4px #fee2e2;
    }

    .resident-notification-popup-date {
        margin-top: 3px;
        color: #64748b;
        font-size: .68rem;
        font-weight: 850;
    }

    .resident-notification-popup-text {
        margin-top: 6px;
        color: #475569;
        font-size: .74rem;
        font-weight: 760;
        line-height: 1.45;
    }

    .resident-notification-popup-empty {
        padding: 26px 18px;
        text-align: center;
        color: #64748b;
        font-weight: 850;
    }

    .resident-notification-popup-empty i {
        width: 48px;
        height: 48px;
        margin: 0 auto 12px;
        border-radius: 18px;
        display: grid;
        place-items: center;
        color: #2563eb;
        background: #dbeafe;
        font-size: 1.1rem;
    }

    .resident-notification-popup-foot {
        padding: 12px 14px 14px;
        display: flex;
        justify-content: flex-end;
        border-top: 1px solid #e5e7eb;
        background: #ffffff;
    }

    .resident-notification-popup-more {
        height: 38px;
        border-radius: 999px;
        padding: 0 17px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        color: #ffffff;
        background: linear-gradient(135deg, #38bdf8, #2563eb);
        text-decoration: none;
        font-size: .78rem;
        font-weight: 950;
        box-shadow: 0 12px 24px rgba(37, 99, 235, .18);
    }

    @media (max-width: 720px) {
        .resident-notification-popup {
            top: 78px !important;
            right: 14px !important;
            left: 14px !important;
            width: auto;
        }

        .resident-notification-popup::before {
            right: 96px;
        }
    }
</style>

<div class="resident-notification-popup" id="residentNotificationPopup" aria-hidden="true">
    <div class="resident-notification-popup-head">
        <div class="resident-notification-popup-title">
            <i class="fas fa-bell"></i>
            Latest Notifications
        </div>

        <div class="resident-notification-popup-head-actions">
            <button type="button" class="resident-notification-read-all" id="residentNotificationReadAll" <?= $residentNotifUnread <= 0 ? 'disabled' : '' ?>>
                <i class="fas fa-check-double"></i>
                Read All
            </button>

            <span class="resident-notification-popup-count" id="residentNotificationPopupCount">
                <?= $residentNotifUnread > 99 ? '99+' : (int)$residentNotifUnread ?>
            </span>

            <button type="button" class="resident-notification-popup-close" id="residentNotificationPopupClose" aria-label="Close notifications">
                <i class="fas fa-xmark"></i>
            </button>
        </div>
    </div>

    <div class="resident-notification-popup-list">
        <?php if ($residentNotifRows): ?>
            <?php foreach ($residentNotifRows as $residentPopupNotification): ?>
                <?php
                    $popupType = (string)($residentPopupNotification['type'] ?? 'general');
                    $popupUnread = (int)($residentPopupNotification['is_read'] ?? 0) === 0;
                    $popupDate = !empty($residentPopupNotification['created_at'])
                        ? date('d M Y, h:i A', strtotime((string)$residentPopupNotification['created_at']))
                        : '-';
                ?>
                <?php $popupTargetUrl = resident_notif_popup_link($residentPopupNotification); ?>
                <a href="<?= resident_notif_popup_e($popupTargetUrl) ?>" class="resident-notification-popup-item <?= $popupUnread ? 'unread' : '' ?>">
                    <div class="resident-notification-popup-icon">
                        <i class="fas <?= resident_notif_popup_e(resident_notif_popup_icon($popupType)) ?>"></i>
                    </div>

                    <div>
                        <div class="resident-notification-popup-message-title">
                            <span><?= resident_notif_popup_e($residentPopupNotification['title'] ?? 'Notification') ?></span>
                            <?php if ($popupUnread): ?>
                                <span class="resident-notification-popup-dot"></span>
                            <?php endif; ?>
                        </div>

                        <div class="resident-notification-popup-date">
                            <?= resident_notif_popup_e($popupDate) ?>
                        </div>

                        <div class="resident-notification-popup-text">
                            <?= resident_notif_popup_e(resident_notif_popup_short($residentPopupNotification['message'] ?? '', 118)) ?>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="resident-notification-popup-empty">
                <i class="fas fa-bell-slash"></i>
                No notifications yet.
            </div>
        <?php endif; ?>
    </div>

    <div class="resident-notification-popup-foot">
        <a href="notifications.php" class="resident-notification-popup-more">
            More
            <i class="fas fa-arrow-right"></i>
        </a>
    </div>
</div>

<script id="resident-notification-popup-script">
(function () {
    if (window.__residentNotificationPopupReady) {
        return;
    }

    window.__residentNotificationPopupReady = true;

    const popup = document.getElementById('residentNotificationPopup');
    const closeButton = document.getElementById('residentNotificationPopupClose');
    const readAllButton = document.getElementById('residentNotificationReadAll');
    const popupCount = document.getElementById('residentNotificationPopupCount');

    if (!popup) return;

    function openPopup(button) {
        const rect = button.getBoundingClientRect();
        const popupWidth = Math.min(390, window.innerWidth - 28);
        let right = window.innerWidth - rect.right;
        let top = rect.bottom + 12;

        if (right + popupWidth > window.innerWidth - 14) right = 14;

        popup.style.right = right + 'px';
        popup.style.top = top + 'px';
        popup.classList.add('show');
        popup.setAttribute('aria-hidden', 'false');
    }

    function closePopup() {
        popup.classList.remove('show');
        popup.setAttribute('aria-hidden', 'true');
    }

    function togglePopup(button) {
        popup.classList.contains('show') ? closePopup() : openPopup(button);
    }

    if (closeButton) {
        closeButton.addEventListener('click', function (event) {
            event.preventDefault();
            closePopup();
        });
    }

    if (readAllButton) {
        readAllButton.addEventListener('click', function (event) {
            event.preventDefault();

            if (readAllButton.disabled) {
                return;
            }

            readAllButton.disabled = true;
            readAllButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Reading';

            fetch('resident_notification_action.php', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                if (!data || !data.ok) {
                    throw new Error('Unable to mark all as read.');
                }

                document.querySelectorAll('#residentNotificationPopup .resident-notification-popup-item.unread').forEach(function (item) {
                    item.classList.remove('unread');
                });

                document.querySelectorAll('#residentNotificationPopup .resident-notification-popup-dot').forEach(function (dot) {
                    dot.remove();
                });

                if (popupCount) {
                    popupCount.textContent = '0';
                }

                document.querySelectorAll('.notification-nav-btn .badge, .nav-btn .badge, .notification-badge').forEach(function (badge) {
                    badge.textContent = '0';
                    badge.style.display = 'none';
                });

                readAllButton.innerHTML = '<i class="fas fa-check-double"></i> Read All';
            })
            .catch(function () {
                readAllButton.disabled = false;
                readAllButton.innerHTML = '<i class="fas fa-check-double"></i> Read All';
                alert('Unable to mark all notifications as read. Please try again.');
            });
        });
    }

    document.addEventListener('click', function (event) {
        const notificationLink = event.target.closest('a[href="notifications.php"].notification-nav-btn, a[href="notifications.php"].nav-btn');

        if (notificationLink && !notificationLink.closest('#residentNotificationPopup')) {
            if (event.ctrlKey || event.metaKey || event.shiftKey || event.button === 1) return;

            event.preventDefault();
            togglePopup(notificationLink);
            return;
        }

        if (!event.target.closest('#residentNotificationPopup')) closePopup();
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') closePopup();
    });

    window.addEventListener('resize', closePopup);
})();
</script>
