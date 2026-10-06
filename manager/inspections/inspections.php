<?php
error_reporting(E_ALL);
ini_set('display_errors',1);
session_start();
require_once __DIR__.'/../../config/db.php';

// Only managers can open this page
if(!isset($_SESSION['user_id'])){
    header("Location: ../../index.php");
    exit();
}
if(($_SESSION['role']??'')!=='manager'){
    header("Location: ../../tenant/dashboard.php");
    exit();
}

$managerId=(int)$_SESSION['user_id'];

// Makes database values easier to read
function niceText($text){
    return ucwords(str_replace('_',' ',$text??''));
}

// Load manager information
$stmt=$conn->prepare("
SELECT u.first_name,u.last_name,mp.job_title
FROM users u
LEFT JOIN manager_profiles mp ON mp.manager_id=u.user_id
WHERE u.user_id=? AND u.role='manager'
LIMIT 1
");
if(!$stmt) die("Manager query error: ".$conn->error);
$stmt->bind_param("i",$managerId);
$stmt->execute();
$manager=$stmt->get_result()->fetch_assoc();

if(!$manager){
    session_destroy();
    header("Location: ../../index.php");
    exit();
}

$managerName=trim(($manager['first_name']??'Manager').' '.($manager['last_name']??''));
$managerLetter=strtoupper(substr($manager['first_name']??'M',0,1));
$jobTitle=!empty($manager['job_title'])?$manager['job_title']:'Property Manager';

// Search values only exist when the user submits the search form
$search=trim($_GET['search']??'');
$statusFilter=trim($_GET['status']??'');
$typeFilter=trim($_GET['type']??'');

// Summary card numbers
$totalInspections=0;
$upcomingInspections=0;
$completedInspections=0;
$cancelledInspections=0;

// Total inspections
$result=$conn->query("SELECT COUNT(*) AS total FROM inspections");
if($result){
    $totalInspections=(int)$result->fetch_assoc()['total'];
}

// Upcoming inspections
$result=$conn->query("
SELECT COUNT(*) AS total
FROM inspections
WHERE status IN ('scheduled','rescheduled')
AND scheduled_at>=NOW()
");
if($result){
    $upcomingInspections=(int)$result->fetch_assoc()['total'];
}

// Completed inspections
$result=$conn->query("
SELECT COUNT(*) AS total
FROM inspections
WHERE status='completed'
");
if($result){
    $completedInspections=(int)$result->fetch_assoc()['total'];
}

// Cancelled inspections
$result=$conn->query("
SELECT COUNT(*) AS total
FROM inspections
WHERE status='cancelled'
");
if($result){
    $cancelledInspections=(int)$result->fetch_assoc()['total'];
}

// Load inspection records
$sql="
SELECT
i.inspection_id,
i.inspection_type,
i.scheduled_at,
i.status,
i.notes,
i.result_summary,
i.completed_at,
p.property_code,
p.address_line1,
p.suburb,
p.state,
p.postcode,
u.first_name AS tenant_first_name,
u.last_name AS tenant_last_name
FROM inspections i
INNER JOIN properties p ON p.property_id=i.property_id
LEFT JOIN users u ON u.user_id=i.tenant_id
WHERE 1=1
";

$params=[];
$types="";

// Search tenant, property or inspection notes
if($search!==''){
    $sql.="
    AND(
        p.property_code LIKE ?
        OR p.address_line1 LIKE ?
        OR p.suburb LIKE ?
        OR u.first_name LIKE ?
        OR u.last_name LIKE ?
        OR i.notes LIKE ?
    )";
    $searchTerm="%".$search."%";
    for($x=0;$x<6;$x++){
        $params[]=$searchTerm;
        $types.="s";
    }
}

// Search by status
if($statusFilter!==''){
    $sql.=" AND i.status=?";
    $params[]=$statusFilter;
    $types.="s";
}

// Search by inspection type
if($typeFilter!==''){
    $sql.=" AND i.inspection_type=?";
    $params[]=$typeFilter;
    $types.="s";
}

// Upcoming inspections appear first
$sql.="
ORDER BY
CASE
    WHEN i.status IN ('scheduled','rescheduled')
    AND i.scheduled_at>=NOW()
    THEN 0
    ELSE 1
END,
CASE
    WHEN i.status IN ('scheduled','rescheduled')
    AND i.scheduled_at>=NOW()
    THEN i.scheduled_at
END ASC,
i.scheduled_at DESC
";

$stmt=$conn->prepare($sql);
if(!$stmt) die("Database error: ".$conn->error);

if(!empty($params)){
    $stmt->bind_param($types,...$params);
}

$stmt->execute();
$inspectionResult=$stmt->get_result();
$showingSearch=($search!==''||$statusFilter!==''||$typeFilter!=='');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Inspection Management | RentEase</title>

<style>
/* Basic page setup */
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:"Segoe UI",Arial,sans-serif;background:#f5f7f6;color:#243331;font-size:16px}

/* Manager sidebar */
.sidebar{position:fixed;top:0;left:0;width:255px;height:100vh;padding:28px 18px;background:#183b3a;overflow-y:auto}
.brand{padding:0 12px 24px;border-bottom:1px solid rgba(255,255,255,.12)}
.brand-name{color:#fff;font-size:28px;font-weight:800}
.brand-name span{color:#80c7ba}
.brand-tagline{margin-top:4px;color:#b8d0cc;font-size:13px}
.menu-title{margin:23px 12px 8px;color:#8fb2ac;font-size:11px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase}
.sidebar a{display:flex;align-items:center;gap:12px;margin-bottom:4px;padding:12px 13px;border-radius:9px;color:#d8e5e2;text-decoration:none;font-size:15px;font-weight:500}
.sidebar a:hover{background:rgba(255,255,255,.08);color:#fff}
.sidebar a.active{background:#2f8178;color:#fff;font-weight:700}
.menu-icon{width:21px;text-align:center}
.logout-area{margin-top:25px;padding-top:15px;border-top:1px solid rgba(255,255,255,.12)}
.sidebar .logout{color:#f0c4c4}

/* Main page */
.main{margin-left:255px;min-height:100vh;padding:35px 42px 25px}
.topbar{display:flex;justify-content:space-between;align-items:center;gap:20px;margin-bottom:22px}
.page-title h1{color:#243331;font-size:32px;font-weight:700;letter-spacing:-.5px}
.page-title p{margin-top:7px;color:#687976;font-size:16px}

/* Manager profile shown at the top */
.top-profile{display:flex;align-items:center;gap:11px;min-width:190px;padding:9px 14px;background:#fff;border:1px solid #d9e3e0;border-radius:12px;color:#243331;text-decoration:none}
.profile-icon{display:flex;align-items:center;justify-content:center;width:42px;height:42px;border-radius:50%;background:#2f8178;color:#fff;font-size:17px;font-weight:800}
.profile-info strong{display:block;color:#243331;font-size:15px}
.profile-info span{display:block;margin-top:3px;color:#71817e;font-size:13px}

/* Page heading and schedule button */
.header-row{display:flex;justify-content:space-between;align-items:center;gap:20px;margin-bottom:20px}
.header-row h2{color:#304440;font-size:21px;font-weight:650}
.header-row p{margin-top:5px;color:#71817e;font-size:14px}
.button{display:inline-flex;align-items:center;justify-content:center;padding:11px 17px;background:#2f8178;border:1px solid #2f8178;border-radius:8px;color:#fff;font-family:inherit;font-size:14px;font-weight:600;text-decoration:none;cursor:pointer;white-space:nowrap}
.button:hover{background:#286f68;border-color:#286f68}

/* Inspection summary */
.summary-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin-bottom:20px}
.summary-card{padding:19px 20px;background:#fff;border:1px solid #dce5e2;border-radius:13px;box-shadow:0 4px 15px rgba(37,64,60,.04)}
.summary-label{display:block;margin-bottom:9px;color:#71817e;font-size:13px;font-weight:600}
.summary-value{color:#40514e;font-size:25px;font-weight:600}
.summary-value.teal{color:#2f8178}
.summary-value.green{color:#39785d}
.summary-value.red{color:#a75a5a}

/* Search area */
.search-card{margin-bottom:20px;padding:17px 18px;background:#fff;border:1px solid #dce5e2;border-radius:13px;box-shadow:0 4px 15px rgba(37,64,60,.04)}
.search-heading{margin-bottom:12px}
.search-heading h3{color:#304440;font-size:17px;font-weight:650}
.search-heading p{margin-top:3px;color:#71817e;font-size:13px}
.search-form{display:grid;grid-template-columns:2fr 1fr 1fr auto;gap:11px;align-items:center}
.search-form input,.search-form select{width:100%;height:43px;padding:9px 11px;background:#fff;border:1px solid #ccd8d5;border-radius:8px;color:#40514e;font-family:inherit;font-size:14px;outline:none}
.search-form input::placeholder{color:#93a19e}
.search-form input:focus,.search-form select:focus{border-color:#2f8178;box-shadow:0 0 0 3px rgba(47,129,120,.08)}
.search-result-note{margin-top:11px;color:#687976;font-size:13px}
.search-result-note strong{color:#2f8178}

/* Inspection records */
.section-card{margin-bottom:20px;background:#fff;border:1px solid #dce5e2;border-radius:14px;overflow:hidden;box-shadow:0 5px 18px rgba(37,64,60,.05)}
.section-header{display:flex;justify-content:space-between;align-items:center;padding:18px 20px;background:#eef6f4;border-bottom:1px solid #dce5e2}
.section-header h2{color:#304440;font-size:20px;font-weight:650}
.section-header p{margin-top:4px;color:#687976;font-size:13px}
.record-count{padding:6px 10px;background:#fff;border:1px solid #d4e4e0;border-radius:20px;color:#2f8178;font-size:12px;font-weight:700}

/* Inspection table */
.table-wrap{width:100%;overflow-x:auto}
table{width:100%;min-width:1000px;border-collapse:collapse}
th{padding:12px 13px;background:#f7faf9;border-bottom:1px solid #dce5e2;color:#60716e;font-size:11px;font-weight:700;letter-spacing:.3px;text-align:left;text-transform:uppercase;white-space:nowrap}
td{padding:14px 13px;border-bottom:1px solid #e8eeec;color:#40514e;font-size:13px;line-height:1.45;vertical-align:middle}
tbody tr:hover{background:#f8fbfa}
tbody tr:last-child td{border-bottom:none}
.primary-text{display:block;margin-bottom:3px;color:#304440;font-size:14px;font-weight:600}
.sub-text{display:block;margin-top:3px;color:#83908e;font-size:12px}

/* Status and type badges */
.badge{display:inline-block;padding:5px 9px;border-radius:20px;font-size:11px;font-weight:700;white-space:nowrap}
.status-scheduled{background:#e2f2ef;color:#286f68}
.status-rescheduled{background:#fff2d9;color:#95671d}
.status-completed{background:#e5f3e9;color:#39785d}
.status-cancelled{background:#f8e5e5;color:#a14e4e}
.type-badge{background:#edf2f1;color:#526562}

/* View inspection */
.view-button{display:inline-block;padding:7px 11px;background:#2f8178;border-radius:7px;color:#fff;font-size:12px;font-weight:600;text-decoration:none}
.view-button:hover{background:#286f68}
.empty{padding:38px 20px;color:#71817e;font-size:14px;text-align:center}

/* RentEase footer */
.footer{margin-top:30px;margin-left:-42px;margin-right:-42px;margin-bottom:-25px;padding:20px 25px;background:#2f8178;color:#fff;text-align:center;font-size:15px}
.footer strong{color:#fff;font-weight:700}

/* Smaller screens */
@media(max-width:1100px){
    .summary-grid{grid-template-columns:repeat(2,1fr)}
    .search-form{grid-template-columns:1fr 1fr}
    .search-form input{grid-column:1/-1}
}
@media(max-width:760px){
    .sidebar{position:relative;width:100%;height:auto}
    .main{margin-left:0;padding:25px 18px}
    .top-profile{display:none}
    .topbar,.header-row{align-items:flex-start;flex-direction:column}
    .summary-grid{grid-template-columns:1fr}
    .search-form{grid-template-columns:1fr}
    .search-form input{grid-column:auto}
    .search-form .button{width:100%}
    .footer{margin-left:-18px;margin-right:-18px}
}
</style>
</head>

<body>

<!-- Main manager sidebar -->
<div class="sidebar">
    <div class="brand">
        <div class="brand-name">Rent<span>Ease</span></div>
        <div class="brand-tagline">Renting Made Easy.</div>
    </div>

    <div class="menu-title">Main</div>
    <a href="../dashboard.php">
        <span class="menu-icon">⌂</span>
        Dashboard
    </a>
    <a href="../profile.php">
        <span class="menu-icon">●</span>
        Profile
    </a>

    <div class="menu-title">Management</div>
    <a href="../properties/properties.php">
        <span class="menu-icon">⌂</span>
        Properties
    </a>
    <a href="../tenants/tenants.php">
        <span class="menu-icon">♙</span>
        Tenants
    </a>
    <a href="../lease/leases.php">
        <span class="menu-icon">▣</span>
        Leases
    </a>
    <a href="../rent/payments.php">
        <span class="menu-icon">$</span>
        Rent & Utilities
    </a>

    <div class="menu-title">Operations</div>
    <a href="../maintenance/maintenance.php">
        <span class="menu-icon">⚙</span>
        Maintenance
    </a>
    <a href="inspections.php" class="active">
        <span class="menu-icon">◫</span>
        Inspections
    </a>
    <a href="../messages.php">
        <span class="menu-icon">✉</span>
        Communication
    </a>

    <div class="menu-title">System</div>
    <a href="../activity_log.php">
        <span class="menu-icon">☷</span>
        Activity Log
    </a>

    <div class="logout-area">
        <a href="../../auth/logout.php" class="logout">
            <span class="menu-icon">↪</span>
            Logout
        </a>
    </div>
</div>

<!-- Inspection management content -->
<div class="main">

    <!-- Page title and manager -->
    <div class="topbar">
        <div class="page-title">
            <h1>Inspection Management</h1>
            <p>Schedule and manage property inspections.</p>
        </div>

        <a href="../profile.php" class="top-profile">
            <div class="profile-icon">
                <?php echo htmlspecialchars($managerLetter); ?>
            </div>
            <div class="profile-info">
                <strong><?php echo htmlspecialchars($managerName); ?></strong>
                <span><?php echo htmlspecialchars($jobTitle); ?></span>
            </div>
        </a>
    </div>

    <!-- Property inspection heading -->
    <div class="header-row">
        <div>
            <h2>Property Inspections</h2>
            <p>View previous inspections and manage upcoming visits.</p>
        </div>
        <a href="add_inspection.php" class="button">
            + Schedule Inspection
        </a>
    </div>

    <!-- Inspection summary -->
    <div class="summary-grid">

        <div class="summary-card">
            <span class="summary-label">Total Inspections</span>
            <div class="summary-value">
                <?php echo $totalInspections; ?>
            </div>
        </div>

        <div class="summary-card">
            <span class="summary-label">Upcoming</span>
            <div class="summary-value teal">
                <?php echo $upcomingInspections; ?>
            </div>
        </div>

        <div class="summary-card">
            <span class="summary-label">Completed</span>
            <div class="summary-value green">
                <?php echo $completedInspections; ?>
            </div>
        </div>

        <div class="summary-card">
            <span class="summary-label">Cancelled</span>
            <div class="summary-value red">
                <?php echo $cancelledInspections; ?>
            </div>
        </div>

    </div>

    <!-- Search inspections -->
    <div class="search-card">

        <div class="search-heading">
            <h3>Search Inspections</h3>
            <p>Search by tenant or property, or narrow the results by status and type.</p>
        </div>

        <form method="GET" action="inspections.php" class="search-form">

            <input
                type="text"
                name="search"
                placeholder="Search tenant, property or notes..."
                value="<?php echo htmlspecialchars($search); ?>"
            >

            <select name="status">
                <option value="">All Statuses</option>
                <option value="scheduled" <?php echo $statusFilter==='scheduled'?'selected':''; ?>>
                    Scheduled
                </option>
                <option value="rescheduled" <?php echo $statusFilter==='rescheduled'?'selected':''; ?>>
                    Rescheduled
                </option>
                <option value="completed" <?php echo $statusFilter==='completed'?'selected':''; ?>>
                    Completed
                </option>
                <option value="cancelled" <?php echo $statusFilter==='cancelled'?'selected':''; ?>>
                    Cancelled
                </option>
            </select>

            <select name="type">
                <option value="">All Types</option>
                <option value="routine" <?php echo $typeFilter==='routine'?'selected':''; ?>>
                    Routine
                </option>
                <option value="entry" <?php echo $typeFilter==='entry'?'selected':''; ?>>
                    Entry
                </option>
                <option value="exit" <?php echo $typeFilter==='exit'?'selected':''; ?>>
                    Exit
                </option>
                <option value="follow_up" <?php echo $typeFilter==='follow_up'?'selected':''; ?>>
                    Follow Up
                </option>
                <option value="other" <?php echo $typeFilter==='other'?'selected':''; ?>>
                    Other
                </option>
            </select>

            <button type="submit" class="button">
                Search
            </button>

        </form>

        <?php if($showingSearch): ?>
            <div class="search-result-note">
                Showing <strong><?php echo $inspectionResult->num_rows; ?></strong>
                matching inspection<?php echo $inspectionResult->num_rows===1?'':'s'; ?>.
                Open <strong>Inspections</strong> from the sidebar to return to all records.
            </div>
        <?php endif; ?>

    </div>

    <!-- Inspection records -->
    <div class="section-card">

        <div class="section-header">
            <div>
                <h2>Inspection Records</h2>
                <p>
                    <?php if($showingSearch): ?>
                        Inspections matching your search.
                    <?php else: ?>
                        All scheduled and previous property inspections.
                    <?php endif; ?>
                </p>
            </div>

            <span class="record-count">
                <?php echo $inspectionResult->num_rows; ?>
                Record<?php echo $inspectionResult->num_rows===1?'':'s'; ?>
            </span>
        </div>

        <div class="table-wrap">

            <table>
                <thead>
                    <tr>
                        <th>Inspection</th>
                        <th>Property</th>
                        <th>Tenant</th>
                        <th>Date & Time</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>

                <tbody>

                <?php if($inspectionResult->num_rows>0): ?>

                    <?php while($inspection=$inspectionResult->fetch_assoc()): ?>

                        <?php
                        $statusClass='status-'.$inspection['status'];
                        $tenantName=trim(
                            ($inspection['tenant_first_name']??'').' '.
                            ($inspection['tenant_last_name']??'')
                        );
                        ?>

                        <tr>

                            <td>
                                <span class="primary-text">
                                    Inspection #<?php echo (int)$inspection['inspection_id']; ?>
                                </span>

                                <?php if(!empty($inspection['notes'])): ?>
                                    <span class="sub-text">
                                        <?php
                                        $notes=$inspection['notes'];
                                        echo htmlspecialchars(
                                            strlen($notes)>45
                                            ?substr($notes,0,45).'...'
                                            :$notes
                                        );
                                        ?>
                                    </span>
                                <?php else: ?>
                                    <span class="sub-text">
                                        No notes added
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <span class="primary-text">
                                    <?php echo htmlspecialchars($inspection['property_code']); ?>
                                </span>
                                <span class="sub-text">
                                    <?php
                                    echo htmlspecialchars(
                                        $inspection['address_line1'].', '.
                                        $inspection['suburb']
                                    );
                                    ?>
                                </span>
                            </td>

                            <td>
                                <span class="primary-text">
                                    <?php echo htmlspecialchars($tenantName!==''?$tenantName:'No tenant'); ?>
                                </span>
                            </td>

                            <td>
                                <span class="primary-text">
                                    <?php echo date('d M Y',strtotime($inspection['scheduled_at'])); ?>
                                </span>
                                <span class="sub-text">
                                    <?php echo date('g:i A',strtotime($inspection['scheduled_at'])); ?>
                                </span>
                            </td>

                            <td>
                                <span class="badge type-badge">
                                    <?php echo htmlspecialchars(niceText($inspection['inspection_type'])); ?>
                                </span>
                            </td>

                            <td>
                                <span class="badge <?php echo htmlspecialchars($statusClass); ?>">
                                    <?php echo htmlspecialchars(niceText($inspection['status'])); ?>
                                </span>
                            </td>

                            <td>
                                <a
                                    href="view_inspection.php?id=<?php echo (int)$inspection['inspection_id']; ?>"
                                    class="view-button"
                                >
                                    View
                                </a>
                            </td>

                        </tr>

                    <?php endwhile; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="7" class="empty">
                            No inspections matched your search.
                        </td>
                    </tr>

                <?php endif; ?>

                </tbody>
            </table>

        </div>
    </div>

    <!-- RentEase footer -->
    <footer class="footer">
        <strong>© 2026 RentEase Property Management System</strong>
        &nbsp; • &nbsp;
        Renting Made Easy.
    </footer>

</div>

</body>
</html>