<?php
session_start();
require_once __DIR__ . '/../config/db.php';

/*
|--------------------------------------------------------------------------
| Security
|--------------------------------------------------------------------------
| Only logged-in tenants should be able to open this page.
*/
if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit();
}

if (($_SESSION['role'] ?? '') !== 'tenant') {
    header("Location: ../manager/dashboard.php");
    exit();
}

$tenantId = (int)$_SESSION['user_id'];

/*
|--------------------------------------------------------------------------
| Get Tenant Information
|--------------------------------------------------------------------------
| This information is used in the profile box at the top of the page.
*/
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

/*
|--------------------------------------------------------------------------
| Get Current Property
|--------------------------------------------------------------------------
| This gives the tenant a clear reminder of which rental property the
| inspection information belongs to.
*/
$stmt = $conn->prepare("
    SELECT
        l.property_id,
        p.property_code,
        p.address_line1,
        p.suburb,
        p.state,
        p.postcode
    FROM leases l
    INNER JOIN properties p ON p.property_id=l.property_id
    WHERE l.tenant_id=?
    AND l.lease_status IN ('active','renewal_due')
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
$property = $stmt->get_result()->fetch_assoc();

/*
|--------------------------------------------------------------------------
| Get Inspections
|--------------------------------------------------------------------------
| Inspections are created by the manager. The tenant can only view their
| own inspection information from this page.
*/
$inspections = [];

$stmt = $conn->prepare("
    SELECT
        i.inspection_id,
        i.property_id,
        i.inspection_type,
        i.scheduled_at,
        i.notes,
        i.result_summary,
        i.status,
        i.completed_at,
        i.created_at,
        p.property_code,
        p.address_line1,
        p.suburb,
        p.state,
        p.postcode,
        u.first_name AS manager_first_name,
        u.last_name AS manager_last_name
    FROM inspections i
    INNER JOIN properties p
        ON p.property_id=i.property_id
    INNER JOIN users u
        ON u.user_id=i.scheduled_by
    WHERE i.tenant_id=?
    ORDER BY i.scheduled_at DESC
");
$stmt->bind_param("i",$tenantId);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $inspections[] = $row;
}

/*
|--------------------------------------------------------------------------
| Inspection Summary
|--------------------------------------------------------------------------
*/
$totalInspections = count($inspections);
$upcomingInspections = 0;
$completedInspections = 0;

foreach ($inspections as $inspection) {
    if (
        in_array($inspection['status'],['scheduled','rescheduled'],true)
        && strtotime($inspection['scheduled_at']) >= time()
    ) {
        $upcomingInspections++;
    }

    if ($inspection['status'] === 'completed') {
        $completedInspections++;
    }
}

/*
|--------------------------------------------------------------------------
| Find Next Inspection
|--------------------------------------------------------------------------
| We find the closest future inspection so it can be highlighted separately.
*/
$nextInspection = null;

foreach ($inspections as $inspection) {
    if (
        in_array($inspection['status'],['scheduled','rescheduled'],true)
        && strtotime($inspection['scheduled_at']) >= time()
    ) {
        if (
            $nextInspection === null ||
            strtotime($inspection['scheduled_at']) <
            strtotime($nextInspection['scheduled_at'])
        ) {
            $nextInspection = $inspection;
        }
    }
}

/*
|--------------------------------------------------------------------------
| Unread Communication Count
|--------------------------------------------------------------------------
*/
$stmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM notifications
    WHERE recipient_id=? AND is_read=0
");
$stmt->bind_param("i",$tenantId);
$stmt->execute();
$unreadCommunication = (int)$stmt->get_result()->fetch_assoc()['total'];

/*
|--------------------------------------------------------------------------
| Helper Functions
|--------------------------------------------------------------------------
*/
function niceText($text) {
    return ucwords(str_replace("_"," ",$text ?? ""));
}

function showDateTime($date) {
    if (!$date) {
        return "—";
    }

    return date("d M Y, h:i A",strtotime($date));
}

function showDate($date) {
    if (!$date) {
        return "—";
    }

    return date("d M Y",strtotime($date));
}

function statusClass($status) {
    switch ($status) {
        case 'completed':
            return 'green';

        case 'cancelled':
            return 'red';

        case 'rescheduled':
            return 'orange';

        case 'scheduled':
            return 'teal';

        default:
            return 'grey';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Inspections | RentEase</title>

<style>
/* Basic page setup */
*{
    box-sizing:border-box;
}

body{
    margin:0;
    background:#f5f7f6;
    color:#243331;
    font-family:"Segoe UI",Arial,sans-serif;
    font-size:16px;
}

/* Tenant sidebar */
.sidebar{
    position:fixed;
    top:0;
    left:0;
    width:255px;
    height:100vh;
    padding:28px 18px;
    background:#183b3a;
    overflow-y:auto;
}

.brand{
    padding:0 12px 24px;
    border-bottom:1px solid rgba(255,255,255,.12);
}

.brand-name{
    color:#fff;
    font-size:28px;
    font-weight:800;
}

.brand-name span{
    color:#80c7ba;
}

.brand-tagline{
    margin-top:4px;
    color:#b8d0cc;
    font-size:13px;
}

.menu-title{
    margin:23px 12px 8px;
    color:#8fb2ac;
    font-size:11px;
    font-weight:700;
    letter-spacing:1.2px;
    text-transform:uppercase;
}

.sidebar a{
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
    transition:.2s;
}

.sidebar a:hover{
    background:rgba(255,255,255,.08);
    color:#fff;
}

.sidebar a.active{
    background:#2f8178;
    color:#fff;
    font-weight:700;
}

.menu-icon{
    width:21px;
    text-align:center;
}

.notification-count{
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

.logout-area{
    margin-top:25px;
    padding-top:15px;
    border-top:1px solid rgba(255,255,255,.12);
}

.sidebar .logout{
    color:#f0c4c4;
}

/* Main content */
.main{
    margin-left:255px;
    min-height:100vh;
    padding:35px 42px 25px;
}

/* Page heading */
.topbar{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:20px;
    margin-bottom:25px;
}

.page-title h1{
    margin:0;
    color:#243331;
    font-size:32px;
    font-weight:700;
    letter-spacing:-.5px;
}

.page-title p{
    margin:7px 0 0;
    color:#687976;
    font-size:16px;
}

/* Tenant profile shortcut */
.top-profile{
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

.profile-icon{
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

.profile-info strong{
    display:block;
    font-size:15px;
    font-weight:600;
}

.profile-info span{
    display:block;
    margin-top:2px;
    color:#71817e;
    font-size:13px;
}

/* Current property banner */
.property-box{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:20px;
    margin-bottom:22px;
    padding:24px 27px;
    background:#2f8178;
    border-radius:15px;
    color:#fff;
}

.property-label{
    margin-bottom:5px;
    color:#d9efeb;
    font-size:12px;
    font-weight:600;
    letter-spacing:.8px;
    text-transform:uppercase;
}

.property-box h2{
    margin:0;
    font-size:22px;
    font-weight:600;
}

.property-box p{
    margin:6px 0 0;
    color:#e3f1ee;
    font-size:14px;
}

.property-code{
    padding:8px 14px;
    background:rgba(255,255,255,.16);
    border:1px solid rgba(255,255,255,.28);
    border-radius:20px;
    font-size:13px;
    font-weight:600;
}

/* Summary cards */
.summary-grid{
    display:grid;
    grid-template-columns:repeat(3,1fr);
    gap:18px;
    margin-bottom:22px;
}

.summary-card{
    padding:20px;
    background:#fff;
    border:1px solid #dce5e2;
    border-radius:14px;
    box-shadow:0 5px 18px rgba(37,64,60,.04);
}

.summary-label{
    color:#71817e;
    font-size:12px;
    font-weight:600;
    letter-spacing:.4px;
    text-transform:uppercase;
}

.summary-value{
    margin-top:9px;
    color:#40514e;
    font-size:21px;
    font-weight:600;
}

.summary-note{
    margin-top:6px;
    color:#83908e;
    font-size:12px;
}

/* Next inspection highlight */
.next-inspection{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:25px;
    margin-bottom:22px;
    padding:24px 27px;
    background:#eef6f4;
    border:1px solid #cfe3df;
    border-radius:15px;
}

.next-label{
    margin-bottom:6px;
    color:#2f8178;
    font-size:11px;
    font-weight:700;
    letter-spacing:.7px;
    text-transform:uppercase;
}

.next-inspection h2{
    margin:0;
    color:#304440;
    font-size:21px;
    font-weight:600;
}

.next-inspection p{
    margin:7px 0 0;
    color:#687976;
    font-size:14px;
}

.next-date{
    min-width:190px;
    padding:16px 18px;
    background:#fff;
    border:1px solid #d4e4e1;
    border-radius:11px;
    text-align:center;
}

.next-date strong{
    display:block;
    color:#40514e;
    font-size:16px;
    font-weight:600;
}

.next-date span{
    display:block;
    margin-top:5px;
    color:#71817e;
    font-size:13px;
}

/* Main inspection section */
.section{
    margin-bottom:22px;
    background:#fff;
    border:1px solid #dce5e2;
    border-radius:15px;
    overflow:hidden;
    box-shadow:0 6px 22px rgba(37,64,60,.05);
}

.section-header{
    padding:21px 25px 17px;
    border-bottom:1px solid #e4e9e7;
}

.section-header h2{
    margin:0;
    color:#263936;
    font-size:21px;
    font-weight:600;
}

.section-header p{
    margin:5px 0 0;
    color:#71817e;
    font-size:14px;
}

/* Inspection cards */
.inspection-list{
    padding:22px;
}

.inspection-card{
    margin-bottom:17px;
    padding:20px;
    background:#fff;
    border:1px solid #dce5e2;
    border-radius:12px;
}

.inspection-card:last-child{
    margin-bottom:0;
}

.inspection-top{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:20px;
    margin-bottom:16px;
}

.inspection-title{
    margin-bottom:5px;
    color:#304440;
    font-size:17px;
    font-weight:600;
}

.inspection-id{
    color:#83908e;
    font-size:12px;
}

/* Status badges */
.badge{
    display:inline-block;
    padding:6px 11px;
    border-radius:20px;
    font-size:11px;
    font-weight:600;
}

.badge.green{
    background:#e8f5ee;
    color:#287a55;
}

.badge.red{
    background:#fae8e8;
    color:#a84545;
}

.badge.orange{
    background:#fff2d9;
    color:#96671e;
}

.badge.teal{
    background:#e6f3f1;
    color:#286f68;
}

.badge.grey{
    background:#edf2f1;
    color:#60716e;
}

/* Inspection information */
.details-grid{
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:11px;
}

.detail{
    padding:13px;
    background:#f5f8f7;
    border-radius:8px;
}

.detail-label{
    margin-bottom:6px;
    color:#83908e;
    font-size:10px;
    font-weight:600;
    letter-spacing:.3px;
    text-transform:uppercase;
}

.detail-value{
    color:#40514e;
    font-size:12px;
    font-weight:600;
    line-height:1.5;
}

/* Information supplied by the manager */
.note-box{
    margin-top:15px;
    padding:14px 16px;
    background:#f5f8f7;
    border-left:4px solid #8fa7a2;
    border-radius:0 8px 8px 0;
}

.note-box strong{
    display:block;
    margin-bottom:5px;
    color:#40514e;
    font-size:12px;
}

.note-box p{
    margin:0;
    color:#52635f;
    font-size:13px;
    line-height:1.6;
}

/* Completed inspection result */
.result-box{
    margin-top:15px;
    padding:14px 16px;
    background:#eef6f4;
    border-left:4px solid #2f8178;
    border-radius:0 8px 8px 0;
}

.result-box strong{
    display:block;
    margin-bottom:5px;
    color:#286f68;
    font-size:12px;
}

.result-box p{
    margin:0;
    color:#52635f;
    font-size:13px;
    line-height:1.6;
}

/* Empty information box */
.empty{
    padding:40px 25px;
    color:#71817e;
    font-size:14px;
    text-align:center;
}

/* Footer */
.footer{
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

.footer strong{
    color:#fff;
    font-weight:700;
}

/* Responsive layout */
@media(max-width:1050px){
    .summary-grid{
        grid-template-columns:1fr;
    }

    .details-grid{
        grid-template-columns:1fr 1fr;
    }
}

@media(max-width:760px){
    .sidebar{
        position:relative;
        width:100%;
        height:auto;
    }

    .main{
        margin-left:0;
        padding:25px 18px;
    }

    .top-profile{
        display:none;
    }

    .property-box{
        flex-direction:column;
        align-items:flex-start;
    }

    .next-inspection{
        flex-direction:column;
        align-items:flex-start;
    }

    .next-date{
        width:100%;
    }

    .details-grid{
        grid-template-columns:1fr;
    }

    .inspection-top{
        flex-direction:column;
    }

    .footer{
        margin-left:-18px;
        margin-right:-18px;
    }
}
</style>
</head>

<body>

<!-- Tenant sidebar -->
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

<a href="lease.php">
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

<a href="inspections.php" class="active">
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

<!-- Main page -->
<div class="main">

<!-- Page heading -->
<div class="topbar">

<div class="page-title">
<h1>Inspections</h1>
<p>View upcoming property inspections and previous inspection results.</p>
</div>

<a href="profile.php" class="top-profile">

<div class="profile-icon">
<?php echo htmlspecialchars($tenantLetter); ?>
</div>

<div class="profile-info">
<strong><?php echo htmlspecialchars($tenantName); ?></strong>
<span>Tenant</span>
</div>

</a>

</div>

<?php if ($property): ?>

<!-- Current rental -->
<div class="property-box">

<div>

<div class="property-label">
Inspections For
</div>

<h2>
<?php echo htmlspecialchars($property['address_line1']); ?>
</h2>

<p>
<?php
echo htmlspecialchars(
    $property['suburb'].', '.
    $property['state'].' '.
    $property['postcode']
);
?>
</p>

</div>

<div class="property-code">
<?php echo htmlspecialchars($property['property_code']); ?>
</div>

</div>

<?php endif; ?>

<!-- Inspection summary -->
<div class="summary-grid">

<div class="summary-card">

<div class="summary-label">
Total Inspections
</div>

<div class="summary-value">
<?php echo $totalInspections; ?>
</div>

<div class="summary-note">
All inspection records
</div>

</div>

<div class="summary-card">

<div class="summary-label">
Upcoming
</div>

<div class="summary-value">
<?php echo $upcomingInspections; ?>
</div>

<div class="summary-note">
Future scheduled inspections
</div>

</div>

<div class="summary-card">

<div class="summary-label">
Completed
</div>

<div class="summary-value">
<?php echo $completedInspections; ?>
</div>

<div class="summary-note">
Finished property inspections
</div>

</div>

</div>

<!-- Highlight the closest upcoming inspection -->
<?php if ($nextInspection): ?>

<div class="next-inspection">

<div>

<div class="next-label">
Next Inspection
</div>

<h2>
<?php
echo htmlspecialchars(
    niceText($nextInspection['inspection_type'])
);
?> Inspection
</h2>

<p>
<?php
echo htmlspecialchars(
    $nextInspection['address_line1'].', '.
    $nextInspection['suburb']
);
?>
</p>

</div>

<div class="next-date">

<strong>
<?php
echo date(
    "d M Y",
    strtotime($nextInspection['scheduled_at'])
);
?>
</strong>

<span>
<?php
echo date(
    "h:i A",
    strtotime($nextInspection['scheduled_at'])
);
?>
</span>

</div>

</div>

<?php endif; ?>

<!-- Inspection history -->
<div class="section">

<div class="section-header">

<h2>Inspection History</h2>

<p>
Inspections scheduled by your property manager.
</p>

</div>

<?php if ($inspections): ?>

<div class="inspection-list">

<?php foreach ($inspections as $inspection): ?>

<div class="inspection-card">

<!-- Inspection title and current status -->
<div class="inspection-top">

<div>

<div class="inspection-title">
<?php
echo htmlspecialchars(
    niceText($inspection['inspection_type'])
);
?> Inspection
</div>

<div class="inspection-id">
Inspection #<?php echo (int)$inspection['inspection_id']; ?>
&nbsp; • &nbsp;
<?php echo htmlspecialchars($inspection['property_code']); ?>
</div>

</div>

<span class="badge <?php echo statusClass($inspection['status']); ?>">
<?php echo htmlspecialchars(niceText($inspection['status'])); ?>
</span>

</div>

<!-- Main inspection information -->
<div class="details-grid">

<div class="detail">

<div class="detail-label">
Scheduled Date
</div>

<div class="detail-value">
<?php echo showDateTime($inspection['scheduled_at']); ?>
</div>

</div>

<div class="detail">

<div class="detail-label">
Inspection Type
</div>

<div class="detail-value">
<?php
echo htmlspecialchars(
    niceText($inspection['inspection_type'])
);
?>
</div>

</div>

<div class="detail">

<div class="detail-label">
Scheduled By
</div>

<div class="detail-value">
<?php
echo htmlspecialchars(
    trim(
        $inspection['manager_first_name'].' '.
        $inspection['manager_last_name']
    )
);
?>
</div>

</div>

<div class="detail">

<div class="detail-label">
Completed
</div>

<div class="detail-value">
<?php echo showDate($inspection['completed_at']); ?>
</div>

</div>

</div>

<!-- Inspection instructions/notes -->
<?php if (!empty($inspection['notes'])): ?>

<div class="note-box">

<strong>
Inspection Notes
</strong>

<p>
<?php
echo nl2br(
    htmlspecialchars($inspection['notes'])
);
?>
</p>

</div>

<?php endif; ?>

<!-- Final inspection result -->
<?php if (!empty($inspection['result_summary'])): ?>

<div class="result-box">

<strong>
Inspection Result
</strong>

<p>
<?php
echo nl2br(
    htmlspecialchars($inspection['result_summary'])
);
?>
</p>

</div>

<?php endif; ?>

</div>

<?php endforeach; ?>

</div>

<?php else: ?>

<div class="empty">
No inspections have been scheduled for you yet.
</div>

<?php endif; ?>

</div>

<footer class="footer">
<strong>© 2026 RentEase Property Management System</strong>
&nbsp; • &nbsp;
Renting Made Easy.
</footer>

</div>

</body>
</html>