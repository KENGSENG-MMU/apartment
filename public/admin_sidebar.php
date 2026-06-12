<?php
/* admin_sidebar.php
   Shared sidebar for all SmartVMS admin pages.
   Use inside admin page layout:
   <?php require_once __DIR__ . '/admin_sidebar.php'; ?>
*/

if (!function_exists('e')) {
    function e($value): string {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

$sidebarCurrentFile = basename($_SERVER['PHP_SELF'] ?? '');
$sidebarApartmentName = $currentApartmentName ?? 'Ixoro Apartment';
$sidebarApartmentLabel = $currentApartmentLabel ?? 'Apartment';

function smvms_sidebar_active(string $currentFile, array $files): bool {
    return in_array($currentFile, $files, true);
}

$residentAddFiles = ['admin_resident_accounts.php'];
$residentManageFiles = ['admin_residents_manage.php'];
$residentUnitFiles = ['admin_resident_apartment.php'];
$residentVehicleFiles = ['admin_resident_vehicles.php'];

$visitorAccountFiles = ['admin_visitor_passes.php'];
$visitorBookingFiles = ['admin_visitor_bookings.php', 'admin_visitor_records.php'];
$visitorRecordFiles = [];

$parkingVisitorFiles = ['admin_parking_(V)manage.php'];
$parkingResidentFiles = ['admin_parking_(R)manage.php'];
$parkingRequestFiles = ['admin_parking_requests.php'];
$parkingPaymentFiles = ['admin_parking_payment.php'];

$gateLogFiles = ['guard_logs.php'];
$gateOverstayFiles = ['admin_gate_overstay.php'];

$reportFiles = ['admin_system_reports.php'];
$auditLogFiles = ['admin_system_audit.php'];
$platformPaymentFiles = ['admin_platform_payment.php'];

$isResidentOpen =
    smvms_sidebar_active($sidebarCurrentFile, $residentAddFiles) ||
    smvms_sidebar_active($sidebarCurrentFile, $residentManageFiles) ||
    smvms_sidebar_active($sidebarCurrentFile, $residentUnitFiles) ||
    smvms_sidebar_active($sidebarCurrentFile, $residentVehicleFiles);

$isVisitorOpen =
    smvms_sidebar_active($sidebarCurrentFile, $visitorAccountFiles) ||
    smvms_sidebar_active($sidebarCurrentFile, $visitorBookingFiles) ||
    smvms_sidebar_active($sidebarCurrentFile, $visitorRecordFiles);

$isParkingOpen =
    smvms_sidebar_active($sidebarCurrentFile, $parkingVisitorFiles) ||
    smvms_sidebar_active($sidebarCurrentFile, $parkingResidentFiles) ||
    smvms_sidebar_active($sidebarCurrentFile, $parkingRequestFiles) ||
    smvms_sidebar_active($sidebarCurrentFile, $parkingPaymentFiles);

$isGateOpen =
    smvms_sidebar_active($sidebarCurrentFile, $gateLogFiles) ||
    smvms_sidebar_active($sidebarCurrentFile, $gateOverstayFiles);

$isReportOpen =
    smvms_sidebar_active($sidebarCurrentFile, $reportFiles) ||
    smvms_sidebar_active($sidebarCurrentFile, $auditLogFiles);
?>


<style id="smartvms-shared-sidebar-style">
    .dashboard-shell {
        display: grid;
        grid-template-columns: 260px minmax(0, 1fr);
        min-height: 100vh;
    }

    .sidebar {
        background: rgba(255, 255, 255, 0.94);
        backdrop-filter: blur(20px);
        border-right: 1px solid rgba(229, 231, 235, 0.9);
        padding: 22px 18px;
        position: sticky;
        top: 0;
        height: 100vh;
        overflow-y: auto;
        z-index: 20;
        font-family: 'Plus Jakarta Sans', system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        font-size: 14px;
        line-height: 1.25;
    }

    .brand {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 22px;
        padding: 6px 8px;
    }

    .brand-icon {
        width: 44px;
        height: 44px;
        border-radius: 16px;
        background: linear-gradient(135deg, #dc2626, #991b1b);
        display: grid;
        place-items: center;
        color: white;
        box-shadow: 0 14px 30px rgba(220, 38, 38, 0.28);
        flex: 0 0 auto;
    }

    .brand-title {
        font-weight: 900;
        letter-spacing: -0.04em;
        font-size: 1.08rem;
        line-height: 1.1;
        color: #111827;
    }

    .brand-title span {
        color: #dc2626;
    }

    .brand-sub {
        font-size: .7rem;
        color: #64748b;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: .08em;
        margin-top: 3px;
    }

    .tenant-card {
        background: #fff7f7;
        border: 1px solid #fecaca;
        border-radius: 20px;
        padding: 13px 14px;
        margin-bottom: 20px;
        display: flex;
        gap: 11px;
        align-items: center;
    }

    .tenant-icon {
        width: 38px;
        height: 38px;
        border-radius: 14px;
        background: #fee2e2;
        color: #dc2626;
        display: grid;
        place-items: center;
        flex: 0 0 auto;
    }

    .tenant-label {
        color: #64748b;
        font-size: .64rem;
        font-weight: 950;
        text-transform: uppercase;
        letter-spacing: .07em;
        margin-bottom: 3px;
    }

    .tenant-name {
        font-size: .8rem;
        font-weight: 950;
        line-height: 1.28;
        color: #111827;
        word-break: break-word;
    }

    .side-section {
        margin: 20px 0 10px;
        color: #9ca3af;
        font-size: .68rem;
        text-transform: uppercase;
        letter-spacing: .1em;
        font-weight: 900;
        padding: 0 10px;
    }

    .side-nav {
        display: grid;
        gap: 6px;
    }

    .side-link {
        width: 100%;
        border: 0;
        display: flex;
        align-items: center;
        gap: 11px;
        padding: 11px 12px;
        border-radius: 15px;
        text-decoration: none;
        color: #475569;
        font-size: .82rem;
        font-weight: 850;
        transition: .2s ease;
        background: transparent;
        cursor: pointer;
        text-align: left;
        font-family: inherit;
        line-height: 1.25;
    }

    .side-link i {
        width: 18px;
        text-align: center;
        color: #94a3b8;
        transition: .2s ease;
    }

    .side-link:hover,
    .side-link.current {
        background: #fff1f2;
        color: #dc2626;
    }

    .side-link:hover i,
    .side-link.current i,
    .side-parent.open .side-link.parent i {
        color: #dc2626;
    }

    .side-parent {
        margin-top: 4px;
    }

    .side-link.parent {
        justify-content: space-between;
    }

    .side-link.parent .left {
        display: flex;
        align-items: center;
        gap: 11px;
    }

    .side-link.parent .chevron,
    .side-link.parent .fa-chevron-down {
        font-size: .65rem;
        color: inherit;
        opacity: .72;
        transition: transform .2s ease;
    }

    .side-parent.open .side-link.parent {
        background: #fff1f2;
        color: #dc2626;
    }

    .side-parent.open .side-link.parent .chevron,
    .side-parent.open .side-link.parent .fa-chevron-down {
        transform: rotate(180deg);
    }

    .submenu {
        margin: 0 0 0 30px;
        padding-left: 12px;
        border-left: 2px solid #fee2e2;
        display: grid;
        gap: 4px;
        max-height: 0;
        overflow: hidden;
        opacity: 0;
        transform: translateY(-4px);
        transition: max-height .25s ease, opacity .2s ease, transform .2s ease, margin .2s ease;
    }

    .side-parent.open .submenu {
        max-height: 320px;
        opacity: 1;
        transform: translateY(0);
        margin: 5px 0 8px 30px;
    }

    .submenu a {
        text-decoration: none;
        color: #64748b;
        font-size: .76rem;
        font-weight: 850;
        padding: 7px 8px;
        border-radius: 11px;
        transition: .2s ease;
    }

    .submenu a:hover,
    .submenu a.sub-active {
        background: #fff1f2;
        color: #dc2626;
    }


    /* Reports & Logs should look plain, not like the red active pill.
       Only Logout keeps the red background. */
    .side-parent.reports-parent.open .side-link.parent,
    .side-parent.reports-parent .side-link.parent.current {
        background: transparent !important;
        color: #475569 !important;
    }

    .side-parent.reports-parent.open .side-link.parent i,
    .side-parent.reports-parent .side-link.parent.current i {
        color: #94a3b8 !important;
    }

    .side-parent.reports-parent .submenu a.sub-active {
        background: transparent !important;
        color: #dc2626 !important;
    }

    .side-parent.reports-parent .submenu a:hover {
        background: #fff7f7 !important;
        color: #dc2626 !important;
    }


    /* Keep Reports & Logs plain like normal menu. Only Logout keeps red background. */
    .side-parent.reports-parent.open .side-link.parent,
    .side-parent.reports-parent .side-link.parent.current {
        background: transparent !important;
        color: #475569 !important;
    }

    .side-parent.reports-parent.open .side-link.parent i,
    .side-parent.reports-parent .side-link.parent.current i {
        color: #94a3b8 !important;
    }

    .side-parent.reports-parent .submenu a.sub-active {
        background: transparent !important;
        color: #dc2626 !important;
    }

    .side-parent.reports-parent .submenu a:hover {
        background: #fff7f7 !important;
        color: #dc2626 !important;
    }

    .side-link.logout,
    .side-link.logout-link {
        color: #991b1b;
        background: #fff1f2;
    }

    @media (max-width: 1220px) {
        .dashboard-shell {
            grid-template-columns: 1fr;
        }

        .sidebar {
            position: relative;
            height: auto;
            border-right: 0;
            border-bottom: 1px solid #e5e7eb;
        }
    }
</style>

<aside class="sidebar">
    <div class="brand">
        <div class="brand-icon">
            <i class="fas fa-shield-halved"></i>
        </div>
        <div>
            <div class="brand-title">Smart <span>VMS</span></div>
            <div class="brand-sub">Admin Panel</div>
        </div>
    </div>

    <div class="tenant-card">
        <div class="tenant-icon">
            <i class="fas fa-building"></i>
        </div>
        <div>
            <div class="tenant-label"><?= e($sidebarApartmentLabel) ?></div>
            <div class="tenant-name"><?= e($sidebarApartmentName) ?></div>
        </div>
    </div>

    <div class="side-section">Main</div>
    <nav class="side-nav">
        <a href="admin_dashboard.php" class="side-link <?= $sidebarCurrentFile === 'admin_dashboard.php' ? 'current' : '' ?>">
            <i class="fas fa-table-columns"></i>
            Dashboard
        </a>
    </nav>

    <div class="side-section">Management</div>
    <nav class="side-nav">
        <div class="side-parent <?= $isResidentOpen ? 'open' : '' ?>">
            <button type="button" class="side-link parent <?= $isResidentOpen ? 'current' : '' ?>">
                <span class="left">
                    <i class="fas fa-users-gear"></i>
                    Resident Management
                </span>
                <i class="fas fa-chevron-down chevron"></i>
            </button>
            <div class="submenu">
                <a href="admin_resident_accounts.php" class="<?= smvms_sidebar_active($sidebarCurrentFile, $residentAddFiles) ? 'sub-active' : '' ?>">Add Resident</a>
                <a href="admin_residents_manage.php" class="<?= smvms_sidebar_active($sidebarCurrentFile, $residentManageFiles) ? 'sub-active' : '' ?>">Manage Residents</a>
                <a href="admin_resident_apartment.php" class="<?= smvms_sidebar_active($sidebarCurrentFile, $residentUnitFiles) ? 'sub-active' : '' ?>">Unit / Household</a>
                <a href="admin_resident_vehicles.php" class="<?= smvms_sidebar_active($sidebarCurrentFile, $residentVehicleFiles) ? 'sub-active' : '' ?>">Resident Vehicles</a>
            </div>
        </div>

        <div class="side-parent <?= $isVisitorOpen ? 'open' : '' ?>">
            <button type="button" class="side-link parent <?= $isVisitorOpen ? 'current' : '' ?>">
                <span class="left">
                    <i class="fas fa-id-card-clip"></i>
                    Visitor Management
                </span>
                <i class="fas fa-chevron-down chevron"></i>
            </button>
            <div class="submenu">
                <a href="admin_visitor_passes.php" class="<?= smvms_sidebar_active($sidebarCurrentFile, $visitorAccountFiles) ? 'sub-active' : '' ?>">Visitor Accounts</a>
                <a href="admin_visitor_bookings.php" class="<?= smvms_sidebar_active($sidebarCurrentFile, $visitorBookingFiles) ? 'sub-active' : '' ?>">Visitor Visits</a>
            </div>
        </div>

        <div class="side-parent <?= $isParkingOpen ? 'open' : '' ?>">
            <button type="button" class="side-link parent <?= $isParkingOpen ? 'current' : '' ?>">
                <span class="left">
                    <i class="fas fa-square-parking"></i>
                    Parking Management
                </span>
                <i class="fas fa-chevron-down chevron"></i>
            </button>
            <div class="submenu">
                <a href="admin_parking_(V)manage.php" class="<?= smvms_sidebar_active($sidebarCurrentFile, $parkingVisitorFiles) ? 'sub-active' : '' ?>">Visitor Parking Slots</a>
                <a href="admin_parking_(R)manage.php" class="<?= smvms_sidebar_active($sidebarCurrentFile, $parkingResidentFiles) ? 'sub-active' : '' ?>">Resident Parking</a>
                <a href="admin_parking_requests.php" class="<?= smvms_sidebar_active($sidebarCurrentFile, $parkingRequestFiles) ? 'sub-active' : '' ?>">Parking Requests</a>
                <a href="admin_parking_payment.php" class="<?= smvms_sidebar_active($sidebarCurrentFile, $parkingPaymentFiles) ? 'sub-active' : '' ?>">Payment Verification</a>
            </div>
        </div>

        <div class="side-parent <?= $isGateOpen ? 'open' : '' ?>">
            <button type="button" class="side-link parent <?= $isGateOpen ? 'current' : '' ?>">
                <span class="left">
                    <i class="fas fa-shield-halved"></i>
                    Gate Management
                </span>
                <i class="fas fa-chevron-down chevron"></i>
            </button>
            <div class="submenu">
                <a href="guard_logs.php" class="<?= smvms_sidebar_active($sidebarCurrentFile, $gateLogFiles) ? 'sub-active' : '' ?>">Gate Logs</a>
                <a href="admin_gate_overstay.php" class="<?= smvms_sidebar_active($sidebarCurrentFile, $gateOverstayFiles) ? 'sub-active' : '' ?>">Overstay Visitors</a>
            </div>
        </div>
    </nav>

    <div class="side-section">System</div>
    <nav class="side-nav">
        <div class="side-parent reports-parent <?= $isReportOpen ? 'open' : '' ?>">
            <button type="button" class="side-link parent <?= $isReportOpen ? 'current' : '' ?>">
                <span class="left">
                    <i class="fas fa-chart-line"></i>
                    Reports & Logs
                </span>
                <i class="fas fa-chevron-down chevron"></i>
            </button>
            <div class="submenu">
                <a href="admin_system_reports.php" class="<?= smvms_sidebar_active($sidebarCurrentFile, $reportFiles) ? 'sub-active' : '' ?>">Reports</a>
                <a href="admin_system_audit.php" class="<?= smvms_sidebar_active($sidebarCurrentFile, $auditLogFiles) ? 'sub-active' : '' ?>">Audit Logs</a>
            </div>
        </div>

        <a href="admin_platform_payment.php" class="side-link <?= smvms_sidebar_active($sidebarCurrentFile, $platformPaymentFiles) ? 'current' : '' ?>">
            <i class="fas fa-credit-card"></i>
            Platform Payment
        </a>

        <a href="../core/logout.php" class="side-link logout logout-link">
            <i class="fas fa-right-from-bracket"></i>
            Logout
        </a>
    </nav>
</aside>

<?php require_once __DIR__ . '/admin_platform_payment_guard.php'; ?>

<script id="smartvms-sidebar-toggle-script">
(function () {
    if (window.__smartvmsSidebarSafeToggleReady) {
        return;
    }

    window.__smartvmsSidebarSafeToggleReady = true;

    document.addEventListener('click', function (event) {
        const button = event.target.closest('.side-link.parent');

        if (!button || !button.closest('.sidebar')) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
        event.stopImmediatePropagation();

        const parent = button.closest('.side-parent');
        if (!parent) {
            return;
        }

        const isOpen = parent.classList.contains('open');

        document.querySelectorAll('.sidebar .side-parent.open').forEach(function (item) {
            if (item !== parent) {
                item.classList.remove('open');
            }
        });

        if (isOpen) {
            parent.classList.remove('open');
        } else {
            parent.classList.add('open');
        }
    }, true);
})();
</script>

