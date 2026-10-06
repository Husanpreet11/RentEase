<?php
session_start();
require_once __DIR__ . '/../config/db.php';

// Only tenants can access this page
if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit();
}

if (($_SESSION['role'] ?? '') !== 'tenant') {
    header("Location: ../manager/dashboard.php");
    exit();
}

$tenantId = (int)$_SESSION['user_id'];

// Get tenant details
$stmt = $conn->prepare("
    SELECT first_name,last_name,email
    FROM users
    WHERE user_id=? AND role='tenant'
    LIMIT 1
");
$stmt->bind_param("i",$tenantId);
$stmt->execute();
$tenant = $stmt->get_result()->fetch_assoc();

if (!$tenant) {
    session_destroy();
    header("Location: ../index.php");
    exit();
}

$tenantName = trim($tenant['first_name'].' '.$tenant['last_name']);
$tenantLetter = strtoupper(substr($tenant['first_name'],0,1));

// Get current or most relevant lease
$stmt = $conn->prepare("
    SELECT
        l.lease_id,
        l.property_id,
        l.start_date,
        l.end_date,
        l.monthly_rent,
        l.bond_amount,
        l.lease_status,
        l.lease_document,
        l.notes,
        p.property_code,
        p.address_line1,
        p.suburb,
        p.state,
        p.postcode,
        p.bedrooms,
        p.bathrooms,
        p.parking_spaces,
        p.property_status,
        p.description
    FROM leases l
    INNER JOIN properties p ON p.property_id=l.property_id
    WHERE l.tenant_id=?
    ORDER BY
        CASE
            WHEN l.lease_status='active' THEN 1
            WHEN l.lease_status='renewal_due' THEN 2
            ELSE 3
        END,
        l.end_date DESC
    LIMIT 1
");
$stmt->bind_param("i",$tenantId);
$stmt->execute();
$lease = $stmt->get_result()->fetch_assoc();

// Count unread notifications
$stmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM notifications
    WHERE recipient_id=? AND is_read=0
");
$stmt->bind_param("i",$tenantId);
$stmt->execute();
$unreadCommunication = (int)$stmt->get_result()->fetch_assoc()['total'];

function showDate($date) {
    if (!$date) return "—";
    return date("d M Y",strtotime($date));
}

function niceText($text) {
    return ucwords(str_replace("_"," ",$text ?? ""));
}

// Calculate remaining lease days
$daysRemaining = null;

if ($lease && !empty($lease['end_date'])) {
    $today = new DateTime('today');
    $endDate = new DateTime($lease['end_date']);
    $daysRemaining = $endDate >= $today ? $today->diff($endDate)->days : 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Lease Information | RentEase</title>

<style>
* {
    box-sizing:border-box;
}

body {
    margin:0;
    background:#f5f7f6;
    color:#243331;
    font-family:"Segoe UI",Arial,sans-serif;
    font-size:16px;
}

/* Sidebar */
.sidebar {
    position:fixed;
    top:0;
    left:0;
    width:255px;
    height:100vh;
    padding:28px 18px;
    background:#183b3a;
    overflow-y:auto;
}

.brand {
    padding:0 12px 24px;
    border-bottom:1px solid rgba(255,255,255,.12);
}

.brand-name {
    color:#fff;
    font-size:28px;
    font-weight:800;
}

.brand-name span {
    color:#80c7ba;
}

.brand-tagline {
    margin-top:4px;
    color:#b8d0cc;
    font-size:13px;
}

.menu-title {
    margin:23px 12px 8px;
    color:#8fb2ac;
    font-size:11px;
    font-weight:700;
    letter-spacing:1.2px;
    text-transform:uppercase;
}

.sidebar a {
    display:flex;
    align-items:center;
    gap:12px;
    margin-bottom:4px;
    padding:12px 13px;
    border-radius:9px;
    color:#d8e5e2;
    text-decoration:none;
    font-size:15px;
    font-weight:500;
}

.sidebar a:hover {
    background:rgba(255,255,255,.08);
    color:#fff;
}

.sidebar a.active {
    background:#2f8178;
    color:#fff;
    font-weight:700;
}

.menu-icon {
    width:21px;
    text-align:center;
}

.notification-count {
    display:flex;
    align-items:center;
    justify-content:center;
    min-width:21px;
    height:21px;
    margin-left:auto;
    padding:0 6px;
    background:#80c7ba;
    color:#183b3a;
    border-radius:20px;
    font-size:11px;
    font-weight:700;
}

.logout-area {
    margin-top:25px;
    padding-top:15px;
    border-top:1px solid rgba(255,255,255,.12);
}

.sidebar .logout {
    color:#f0c4c4;
}

/* Main content */
.main-content {
    margin-left:255px;
    min-height:100vh;
    padding:35px 42px 25px;
}

.topbar {
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:20px;
    margin-bottom:25px;
}

.page-title h1 {
    margin:0;
    color:#243331;
    font-size:32px;
    font-weight:700;
    letter-spacing:-.5px;
}

.page-title p {
    margin:7px 0 0;
    color:#687976;
    font-size:16px;
}

/* Top profile */
.user-box {
    display:flex;
    align-items:center;
    gap:11px;
    padding:9px 14px;
    background:#fff;
    border:1px solid #d9e3e0;
    border-radius:12px;
    color:#243331;
    text-decoration:none;
}

.small-avatar {
    display:flex;
    align-items:center;
    justify-content:center;
    width:42px;
    height:42px;
    border-radius:50%;
    background:#2f8178;
    color:#fff;
    font-size:17px;
    font-weight:700;
}

.user-box strong {
    display:block;
    font-size:15px;
    font-weight:600;
}

.user-box span {
    display:block;
    margin-top:2px;
    color:#71817e;
    font-size:13px;
}

/* Current rental banner */
.lease-banner {
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:25px;
    margin-bottom:22px;
    padding:25px 28px;
    background:#2f8178;
    border-radius:15px;
    color:#fff;
}

.lease-banner-label {
    margin-bottom:5px;
    color:#d9efeb;
    font-size:12px;
    font-weight:600;
    letter-spacing:.8px;
    text-transform:uppercase;
}

.lease-banner h2 {
    margin:0;
    font-size:23px;
    font-weight:600;
}

.lease-banner p {
    margin:6px 0 0;
    color:#e3f1ee;
    font-size:14px;
}

.banner-status {
    flex-shrink:0;
    padding:8px 15px;
    background:rgba(255,255,255,.17);
    border:1px solid rgba(255,255,255,.28);
    border-radius:20px;
    color:#fff;
    font-size:13px;
    font-weight:600;
}

/* Summary cards */
.summary-grid {
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:18px;
    margin-bottom:22px;
}

.summary-card {
    padding:20px;
    background:#fff;
    border:1px solid #dce5e2;
    border-radius:14px;
    box-shadow:0 5px 18px rgba(37,64,60,.04);
}

.summary-label {
    margin-bottom:9px;
    color:#71817e;
    font-size:12px;
    font-weight:600;
    letter-spacing:.4px;
    text-transform:uppercase;
}

.summary-value {
    color:#465754;
    font-size:18px;
    font-weight:500;
}

.summary-note {
    margin-top:6px;
    color:#83908e;
    font-size:12px;
}

/* Status */
.status {
    display:inline-block;
    padding:6px 11px;
    background:#e8f5ee;
    border-radius:20px;
    color:#287a55;
    font-size:12px;
    font-weight:600;
}

.status-renewal {
    background:#fff4d8;
    color:#9a6817;
}

.status-expired {
    background:#fae8e8;
    color:#a84545;
}

/* Main cards */
.content-grid {
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:22px;
}

.card {
    background:#fff;
    border:1px solid #dce5e2;
    border-radius:15px;
    overflow:hidden;
    box-shadow:0 6px 22px rgba(37,64,60,.05);
}

.card-header {
    padding:22px 25px 18px;
    border-bottom:1px solid #e4e9e7;
}

.card-header h2 {
    margin:0;
    color:#263936;
    font-size:21px;
    font-weight:600;
}

.card-header p {
    margin:5px 0 0;
    color:#71817e;
    font-size:14px;
}

.card-body {
    padding:20px 25px 24px;
}

/* Detail rows */
.info-row {
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:20px;
    padding:14px 0;
    border-bottom:1px solid #edf1ef;
}

.info-row:last-child {
    border-bottom:none;
}

.info-label {
    color:#71817e;
    font-size:14px;
}

.info-value {
    color:#465754;
    font-size:14px;
    font-weight:500;
    text-align:right;
}

/* Property */
.property-box {
    margin-bottom:16px;
    padding:18px;
    background:#eef6f4;
    border:1px solid #d3e7e2;
    border-radius:11px;
}

.property-code {
    margin-bottom:6px;
    color:#2f8178;
    font-size:12px;
    font-weight:600;
}

.property-address {
    margin-bottom:5px;
    color:#344744;
    font-size:18px;
    font-weight:600;
}

.property-location {
    color:#687976;
    font-size:14px;
}

.description {
    margin-top:18px;
    padding:15px;
    background:#f5f8f7;
    border-radius:9px;
    color:#566763;
    font-size:14px;
    line-height:1.6;
}

.description strong {
    color:#344744;
    font-weight:600;
}

/* Full-width agreement card */
.agreement-card {
    margin-top:22px;
}

.agreement-header {
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:20px;
    padding:22px 25px 18px;
    border-bottom:1px solid #e4e9e7;
}

.agreement-header h2 {
    margin:0;
    color:#263936;
    font-size:21px;
    font-weight:600;
}

.agreement-header p {
    margin:5px 0 0;
    color:#71817e;
    font-size:14px;
}

.agreement-label {
    padding:7px 12px;
    background:#e8f3f1;
    border-radius:20px;
    color:#286f68;
    font-size:12px;
    font-weight:600;
}

.agreement-content {
    display:grid;
    grid-template-columns:auto 1fr auto;
    align-items:center;
    gap:18px;
    padding:22px 25px;
}

.agreement-icon {
    display:flex;
    align-items:center;
    justify-content:center;
    width:52px;
    height:52px;
    background:#eef6f4;
    border-radius:11px;
    color:#2f8178;
    font-size:24px;
}

.agreement-info strong {
    display:block;
    margin-bottom:5px;
    color:#344744;
    font-size:15px;
    font-weight:600;
}

.agreement-info p {
    margin:0;
    color:#71817e;
    font-size:14px;
    line-height:1.5;
}

.btn {
    display:inline-block;
    padding:11px 18px;
    background:#2f8178;
    border:none;
    border-radius:9px;
    color:#fff;
    font-family:inherit;
    font-size:14px;
    font-weight:600;
    text-decoration:none;
    cursor:pointer;
}

.btn:hover {
    background:#286f68;
}

.agreement-button {
    white-space:nowrap;
}

/* Empty state */
.empty-card {
    padding:60px 25px;
    background:#fff;
    border:1px solid #dce5e2;
    border-radius:15px;
    text-align:center;
}

.empty-icon {
    display:flex;
    align-items:center;
    justify-content:center;
    width:65px;
    height:65px;
    margin:0 auto 17px;
    background:#eef6f4;
    border-radius:50%;
    color:#2f8178;
    font-size:28px;
}

.empty-card h2 {
    margin:0 0 8px;
    color:#263936;
    font-size:21px;
    font-weight:600;
}

.empty-card p {
    margin:0;
    color:#71817e;
    font-size:14px;
    line-height:1.6;
}

/* Footer */
.footer {
    margin-top:32px;
    margin-left:-42px;
    margin-right:-42px;
    margin-bottom:-25px;
    padding:20px 25px;
    background:#2f8178;
    color:#fff;
    text-align:center;
    font-size:14px;
}

.footer strong {
    color:#fff;
    font-weight:700;
}

/* Responsive */
@media (max-width:1100px) {
    .summary-grid {
        grid-template-columns:repeat(2,1fr);
    }
}

@media (max-width:900px) {
    .content-grid {
        grid-template-columns:1fr;
    }
}

@media (max-width:760px) {
    .sidebar {
        position:relative;
        width:100%;
        height:auto;
    }

    .main-content {
        margin-left:0;
        padding:25px 18px;
    }

    .user-box {
        display:none;
    }

    .summary-grid {
        grid-template-columns:1fr;
    }

    .lease-banner {
        flex-direction:column;
        align-items:flex-start;
    }

    .agreement-content {
        grid-template-columns:1fr;
    }

    .agreement-icon {
        display:none;
    }

    .agreement-button {
        width:100%;
        text-align:center;
    }

    .footer {
        margin-left:-18px;
        margin-right:-18px;
    }
}
</style>
</head>

<body>

<!-- Sidebar -->
<div class="sidebar">

    <div class="brand">
        <div class="brand-name">Rent<span>Ease</span></div>
        <div class="brand-tagline">Renting Made Easy.</div>
    </div>

    <div class="menu-title">Main</div>

    <a href="dashboard.php">
        <span class="menu-icon">⌂</span>
        Dashboard
    </a>

    <a href="profile.php">
        <span class="menu-icon">●</span>
        Profile
    </a>

    <div class="menu-title">My Rental</div>

    <a href="lease.php" class="active">
        <span class="menu-icon">▣</span>
        Lease Information
    </a>

    <a href="payments.php">
        <span class="menu-icon">$</span>
        Rent & Utilities
    </a>

    <a href="maintenance.php">
        <span class="menu-icon">⚙</span>
        Maintenance
    </a>

    <a href="inspections.php">
        <span class="menu-icon">◫</span>
        Inspections
    </a>

    <a href="communication.php">
        <span class="menu-icon">✉</span>
        Communication

        <a href="privacy.php">
<span class="menu-icon">◆</span>
Privacy
</a>

        <?php if ($unreadCommunication > 0): ?>
            <span class="notification-count">
                <?php echo $unreadCommunication; ?>
            </span>
        <?php endif; ?>
    </a>

    <div class="logout-area">
        <a href="../auth/logout.php" class="logout">
            <span class="menu-icon">↪</span>
            Logout
        </a>
    </div>

</div>

<!-- Main content -->
<div class="main-content">

    <div class="topbar">

        <div class="page-title">
            <h1>Lease Information</h1>
            <p>View your current lease and rental property details.</p>
        </div>

        <a href="profile.php" class="user-box">

            <div class="small-avatar">
                <?php echo htmlspecialchars($tenantLetter); ?>
            </div>

            <div>
                <strong><?php echo htmlspecialchars($tenantName); ?></strong>
                <span>Tenant</span>
            </div>

        </a>

    </div>

    <?php if ($lease): ?>

        <!-- Current rental -->
        <div class="lease-banner">

            <div>
                <div class="lease-banner-label">Current Rental</div>

                <h2>
                    <?php echo htmlspecialchars($lease['address_line1']); ?>
                </h2>

                <p>
                    <?php
                    echo htmlspecialchars(
                        $lease['suburb'].', '.
                        $lease['state'].' '.
                        $lease['postcode']
                    );
                    ?>
                </p>
            </div>

            <div class="banner-status">
                <?php echo htmlspecialchars(niceText($lease['lease_status'])); ?>
            </div>

        </div>

        <!-- Summary cards -->
        <div class="summary-grid">

            <div class="summary-card">

                <div class="summary-label">Lease Status</div>

                <div class="summary-value">

                    <?php
                    $statusClass = '';

                    if ($lease['lease_status'] === 'renewal_due') {
                        $statusClass = 'status-renewal';
                    }

                    if ($lease['lease_status'] === 'expired') {
                        $statusClass = 'status-expired';
                    }
                    ?>

                    <span class="status <?php echo $statusClass; ?>">
                        <?php echo htmlspecialchars(niceText($lease['lease_status'])); ?>
                    </span>

                </div>

            </div>

            <div class="summary-card">

                <div class="summary-label">Monthly Rent</div>

                <div class="summary-value">
                    $<?php echo number_format((float)$lease['monthly_rent'],2); ?>
                </div>

                <div class="summary-note">
                    As recorded in your lease
                </div>

            </div>

            <div class="summary-card">

                <div class="summary-label">Lease End Date</div>

                <div class="summary-value">
                    <?php echo showDate($lease['end_date']); ?>
                </div>

                <div class="summary-note">
                    Current agreement end date
                </div>

            </div>

            <div class="summary-card">

                <div class="summary-label">Days Remaining</div>

                <div class="summary-value">
                    <?php echo $daysRemaining !== null ? $daysRemaining : '—'; ?>
                </div>

                <div class="summary-note">
                    Until current lease ends
                </div>

            </div>

        </div>

        <!-- Lease and property cards -->
        <div class="content-grid">

            <!-- Lease details -->
            <div class="card">

                <div class="card-header">
                    <h2>Lease Details</h2>
                    <p>Information recorded for your current tenancy.</p>
                </div>

                <div class="card-body">

                    <div class="info-row">
                        <span class="info-label">Lease ID</span>
                        <span class="info-value">
                            #<?php echo (int)$lease['lease_id']; ?>
                        </span>
                    </div>

                    <div class="info-row">
                        <span class="info-label">Start Date</span>
                        <span class="info-value">
                            <?php echo showDate($lease['start_date']); ?>
                        </span>
                    </div>

                    <div class="info-row">
                        <span class="info-label">End Date</span>
                        <span class="info-value">
                            <?php echo showDate($lease['end_date']); ?>
                        </span>
                    </div>

                    <div class="info-row">
                        <span class="info-label">Monthly Rent</span>
                        <span class="info-value">
                            $<?php echo number_format((float)$lease['monthly_rent'],2); ?>
                        </span>
                    </div>

                    <div class="info-row">
                        <span class="info-label">Bond Amount</span>
                        <span class="info-value">
                            $<?php echo number_format((float)$lease['bond_amount'],2); ?>
                        </span>
                    </div>

                    <div class="info-row">
                        <span class="info-label">Status</span>
                        <span class="info-value">
                            <?php echo htmlspecialchars(niceText($lease['lease_status'])); ?>
                        </span>
                    </div>

                    <?php if (!empty($lease['notes'])): ?>

                        <div class="description">
                            <strong>Lease Notes</strong><br><br>

                            <?php
                            echo nl2br(
                                htmlspecialchars($lease['notes'])
                            );
                            ?>
                        </div>

                    <?php endif; ?>

                </div>

            </div>

            <!-- Property details -->
            <div class="card">

                <div class="card-header">
                    <h2>Property Details</h2>
                    <p>Your currently assigned rental property.</p>
                </div>

                <div class="card-body">

                    <div class="property-box">

                        <div class="property-code">
                            <?php echo htmlspecialchars($lease['property_code']); ?>
                        </div>

                        <div class="property-address">
                            <?php echo htmlspecialchars($lease['address_line1']); ?>
                        </div>

                        <div class="property-location">
                            <?php
                            echo htmlspecialchars(
                                $lease['suburb'].', '.
                                $lease['state'].' '.
                                $lease['postcode']
                            );
                            ?>
                        </div>

                    </div>

                    <div class="info-row">
                        <span class="info-label">Bedrooms</span>
                        <span class="info-value">
                            <?php echo (int)$lease['bedrooms']; ?>
                        </span>
                    </div>

                    <div class="info-row">
                        <span class="info-label">Bathrooms</span>
                        <span class="info-value">
                            <?php echo htmlspecialchars($lease['bathrooms']); ?>
                        </span>
                    </div>

                    <div class="info-row">
                        <span class="info-label">Parking Spaces</span>
                        <span class="info-value">
                            <?php echo (int)$lease['parking_spaces']; ?>
                        </span>
                    </div>

                    <div class="info-row">
                        <span class="info-label">Property Status</span>
                        <span class="info-value">
                            <?php echo htmlspecialchars(niceText($lease['property_status'])); ?>
                        </span>
                    </div>

                    <?php if (!empty($lease['description'])): ?>

                        <div class="description">
                            <?php
                            echo nl2br(
                                htmlspecialchars($lease['description'])
                            );
                            ?>
                        </div>

                    <?php endif; ?>

                </div>

            </div>

        </div>

        <!-- Separate full-width lease agreement -->
        <div class="card agreement-card">

            <div class="agreement-header">

                <div>
                    <h2>Lease Agreement</h2>
                    <p>View the signed agreement connected to your current tenancy.</p>
                </div>

                <span class="agreement-label">
                    PDF Document
                </span>

            </div>

            <div class="agreement-content">

                <div class="agreement-icon">
                    ▤
                </div>

                <div class="agreement-info">

                    <?php if (!empty($lease['lease_document'])): ?>

                        <strong>Your Lease Agreement</strong>

                        <p>
                            Your signed lease document is available to view securely.
                        </p>

                    <?php else: ?>

                        <strong>Document Not Available</strong>

                        <p>
                            A lease agreement has not been uploaded for this tenancy yet.
                        </p>

                    <?php endif; ?>

                </div>

                <?php if (!empty($lease['lease_document'])): ?>

                    <a
                        href="../view_lease_document.php"
                        target="_blank"
                        class="btn agreement-button"
                    >
                        View Agreement
                    </a>

                <?php endif; ?>

            </div>

        </div>

    <?php else: ?>

        <!-- No lease -->
        <div class="empty-card">

            <div class="empty-icon">▣</div>

            <h2>No Lease Found</h2>

            <p>
                There is currently no lease assigned to your RentEase account.<br>
                Please contact your property manager.
            </p>

        </div>

    <?php endif; ?>

    <footer class="footer">
        <strong>© 2026 RentEase Property Management System</strong>
        &nbsp; • &nbsp;
        Renting Made Easy.
    </footer>

</div>

</body>
</html>