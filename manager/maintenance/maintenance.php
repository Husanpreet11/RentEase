<?php
session_start();
require_once __DIR__.'/../../config/db.php';

// Manager access only
if(!isset($_SESSION['user_id'])){
    header("Location: ../../index.php");
    exit();
}
if(($_SESSION['role']??'')!=='manager'){
    header("Location: ../../tenant/dashboard.php");
    exit();
}

$managerId=(int)$_SESSION['user_id'];

// Manager information
$stmt=$conn->prepare("
SELECT u.first_name,u.last_name,mp.job_title
FROM users u
LEFT JOIN manager_profiles mp ON mp.manager_id=u.user_id
WHERE u.user_id=? AND u.role='manager'
LIMIT 1
");
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

// Search values
$search=trim($_GET['search']??'');
$statusFilter=trim($_GET['status']??'');
$priorityFilter=trim($_GET['priority']??'');

$allowedStatuses=['submitted','in_review','scheduled','in_progress','completed','cancelled'];
$allowedPriorities=['low','medium','high','urgent'];

if($statusFilter!==''&&!in_array($statusFilter,$allowedStatuses,true)){
    $statusFilter='';
}
if($priorityFilter!==''&&!in_array($priorityFilter,$allowedPriorities,true)){
    $priorityFilter='';
}

// Summary cards
$totalRequests=0;
$activeRequests=0;
$urgentRequests=0;
$completedRequests=0;

$result=$conn->query("SELECT COUNT(*) AS total FROM maintenance_requests");
if($result) $totalRequests=(int)$result->fetch_assoc()['total'];

$result=$conn->query("SELECT COUNT(*) AS total FROM maintenance_requests WHERE status NOT IN('completed','cancelled')");
if($result) $activeRequests=(int)$result->fetch_assoc()['total'];

$result=$conn->query("SELECT COUNT(*) AS total FROM maintenance_requests WHERE priority='urgent' AND status NOT IN('completed','cancelled')");
if($result) $urgentRequests=(int)$result->fetch_assoc()['total'];

$result=$conn->query("SELECT COUNT(*) AS total FROM maintenance_requests WHERE status='completed'");
if($result) $completedRequests=(int)$result->fetch_assoc()['total'];

// Load requests
$sql="
SELECT
m.maintenance_id,
m.title,
m.description,
m.category,
m.priority,
m.status,
m.submitted_at,
m.scheduled_date,
m.assigned_to,
u.first_name,
u.last_name,
p.property_code,
p.address_line1,
p.suburb
FROM maintenance_requests m
INNER JOIN users u ON u.user_id=m.tenant_id
INNER JOIN properties p ON p.property_id=m.property_id
WHERE 1=1
";

$params=[];
$types='';

if($search!==''){
    $sql.=" AND(
    CAST(m.maintenance_id AS CHAR) LIKE ?
    OR m.title LIKE ?
    OR m.description LIKE ?
    OR m.category LIKE ?
    OR u.first_name LIKE ?
    OR u.last_name LIKE ?
    OR CONCAT(u.first_name,' ',u.last_name) LIKE ?
    OR p.property_code LIKE ?
    OR p.address_line1 LIKE ?
    OR p.suburb LIKE ?
    )";

    $term='%'.$search.'%';
    for($i=0;$i<10;$i++){
        $params[]=$term;
        $types.='s';
    }
}

if($statusFilter!==''){
    $sql.=" AND m.status=?";
    $params[]=$statusFilter;
    $types.='s';
}

if($priorityFilter!==''){
    $sql.=" AND m.priority=?";
    $params[]=$priorityFilter;
    $types.='s';
}

$sql.="
ORDER BY
CASE
WHEN m.status='completed' THEN 2
WHEN m.status='cancelled' THEN 3
ELSE 1
END,
CASE m.priority
WHEN 'urgent' THEN 1
WHEN 'high' THEN 2
WHEN 'medium' THEN 3
WHEN 'low' THEN 4
ELSE 5
END,
m.submitted_at DESC
";

$stmt=$conn->prepare($sql);
if(!$stmt) die("Database error: ".$conn->error);

if(!empty($params)){
    $stmt->bind_param($types,...$params);
}

$stmt->execute();
$maintenanceResult=$stmt->get_result();
$resultCount=$maintenanceResult->num_rows;

function niceText($text){
    return ucwords(str_replace('_',' ',$text??''));
}

function shortDate($date){
    if(empty($date)) return 'Not set';
    return date('d M Y',strtotime($date));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Maintenance Management | RentEase</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:"Segoe UI",Arial,sans-serif;background:#f5f7f6;color:#243331}
.sidebar{position:fixed;top:0;left:0;width:255px;height:100vh;background:#183b3a;padding:25px 18px;overflow-y:auto}
.brand{padding:0 10px 24px}
.brand-name{font-size:27px;font-weight:750;color:#fff}
.brand-name span{color:#8bc7c0}
.brand-tagline{margin-top:4px;font-size:12px;color:#b7d1cd}
.menu-title{margin:20px 10px 8px;color:#8fb0ac;font-size:10px;font-weight:700;letter-spacing:1px;text-transform:uppercase}
.sidebar a{display:flex;align-items:center;gap:11px;padding:11px 12px;margin-bottom:4px;border-radius:8px;color:#d6e4e2;font-size:15px;text-decoration:none;transition:.2s}
.sidebar a:hover{background:#24514e;color:#fff}
.sidebar a.active{background:#2f8178;color:#fff;font-weight:600}
.menu-icon{width:20px;text-align:center}
.logout-area{margin-top:25px;padding-top:15px;border-top:1px solid #315553}
.sidebar .logout{color:#f1c1c1}
.sidebar .logout:hover{background:#68403e;color:#fff}
.main{margin-left:255px;min-height:100vh;padding:30px 36px 0}
.topbar{display:flex;justify-content:space-between;align-items:center;gap:20px;margin-bottom:25px}
.page-title h1{font-size:32px;font-weight:700;margin-bottom:5px}
.page-title p{font-size:16px;color:#687976}
.top-profile{display:flex;align-items:center;gap:11px;min-width:205px;padding:9px 13px;background:#fff;border:1px solid #dce5e2;border-radius:12px;color:#243331;text-decoration:none}
.profile-icon{display:flex;align-items:center;justify-content:center;width:42px;height:42px;background:#2f8178;border-radius:50%;color:#fff;font-weight:700}
.profile-info{display:flex;flex-direction:column}
.profile-info strong{font-size:14px}
.profile-info span{margin-top:2px;color:#71817e;font-size:11px}
.summary-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:17px;margin-bottom:22px}
.summary-card{background:#fff;border:1px solid #dce5e2;border-radius:15px;padding:20px;box-shadow:0 3px 12px rgba(35,70,66,.05)}
.summary-label{display:block;margin-bottom:9px;color:#71817e;font-size:13px;font-weight:600}
.summary-value{font-size:27px;font-weight:600;color:#40514e}
.summary-value.teal{color:#2f8178}
.summary-value.red{color:#a84d4d}
.summary-value.green{color:#477b65}
.search-card{background:#fff;border:1px solid #dce5e2;border-radius:15px;padding:19px 21px;margin-bottom:22px;box-shadow:0 3px 12px rgba(35,70,66,.04)}
.search-heading{margin-bottom:14px}
.search-heading h2{font-size:18px;margin-bottom:3px}
.search-heading p{font-size:13px;color:#71817e}
.search-form{display:grid;grid-template-columns:minmax(280px,2fr) minmax(150px,1fr) minmax(150px,1fr) auto;gap:12px}
.search-form input,.search-form select{width:100%;padding:11px 12px;border:1px solid #ccd9d6;border-radius:8px;background:#fff;color:#40514e;font-family:inherit;font-size:14px;outline:none}
.search-form input:focus,.search-form select:focus{border-color:#2f8178;box-shadow:0 0 0 3px rgba(47,129,120,.08)}
.search-button{padding:11px 22px;border:0;border-radius:8px;background:#2f8178;color:#fff;font-family:inherit;font-size:14px;font-weight:600;cursor:pointer}
.search-button:hover{background:#286f68}
.section-card{background:#fff;border:1px solid #dce5e2;border-radius:15px;margin-bottom:25px;overflow:hidden;box-shadow:0 3px 12px rgba(35,70,66,.04)}
.section-header{display:flex;justify-content:space-between;align-items:center;padding:19px 21px;border-bottom:1px solid #e3ebe9}
.section-header h2{font-size:20px;margin-bottom:4px}
.section-header p{font-size:13px;color:#71817e}
.result-count{font-size:13px;color:#687976;background:#eef6f4;padding:7px 11px;border-radius:20px}
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse}
th{padding:12px 14px;background:#f3f7f6;border-bottom:1px solid #dce5e2;color:#687976;font-size:11px;font-weight:700;text-align:left;text-transform:uppercase;letter-spacing:.3px}
td{padding:15px 14px;border-bottom:1px solid #edf1f0;color:#40514e;font-size:13px;vertical-align:middle}
tr:last-child td{border-bottom:0}
tbody tr:hover{background:#fbfdfc}
.request-title{display:block;color:#243331;font-size:14px;font-weight:600;margin-bottom:4px}
.request-id{display:block;color:#82918e;font-size:11px}
.primary-text{display:block;color:#40514e;font-weight:600;margin-bottom:3px}
.sub-text{display:block;color:#82918e;font-size:11px;line-height:1.4}
.badge{display:inline-block;padding:5px 9px;border-radius:20px;font-size:11px;font-weight:700}
.priority-low{background:#eef1f0;color:#60706d}
.priority-medium{background:#e8f3f1;color:#2f7069}
.priority-high{background:#fff1d9;color:#91601f}
.priority-urgent{background:#fde8e8;color:#a13e3e}
.status-submitted{background:#e8f3f1;color:#2f7069}
.status-in_review{background:#eeeafa;color:#66559a}
.status-scheduled{background:#fff1d9;color:#91601f}
.status-in_progress{background:#e5eef6;color:#426a88}
.status-completed{background:#e5f3ea;color:#477b65}
.status-cancelled{background:#edf0ef;color:#687976}
.manage-button{display:inline-block;padding:8px 13px;background:#2f8178;border-radius:7px;color:#fff;font-size:12px;font-weight:600;text-decoration:none}
.manage-button:hover{background:#286f68}
.empty{padding:42px 20px;color:#71817e;text-align:center;font-size:14px}
.footer{margin:32px -36px 0;padding:21px 30px;background:#183b3a;color:#c7d9d6;font-size:13px;text-align:center}
.footer strong{color:#fff}
@media(max-width:1100px){.summary-grid{grid-template-columns:repeat(2,1fr)}.search-form{grid-template-columns:1fr 1fr}.search-button{width:100%}}
@media(max-width:760px){.sidebar{position:relative;width:100%;height:auto}.main{margin-left:0;padding:22px}.topbar{align-items:flex-start;flex-direction:column}.summary-grid,.search-form{grid-template-columns:1fr}.footer{margin-left:-22px;margin-right:-22px}}
</style>
</head>
<body>
<div class="sidebar">
<div class="brand">
<div class="brand-name">Rent<span>Ease</span></div>
<div class="brand-tagline">Renting Made Easy.</div>
</div>
<div class="menu-title">Main</div>
<a href="../dashboard.php"><span class="menu-icon">⌂</span>Dashboard</a>
<a href="../profile.php"><span class="menu-icon">●</span>Profile</a>
<div class="menu-title">Management</div>
<a href="../properties/properties.php"><span class="menu-icon">▣</span>Properties</a>
<a href="../tenants/tenants.php"><span class="menu-icon">♙</span>Tenants</a>
<a href="../lease/leases.php"><span class="menu-icon">▤</span>Leases</a>
<a href="../rent/payments.php"><span class="menu-icon">$</span>Rent & Utilities</a>
<div class="menu-title">Operations</div>
<a href="maintenance.php" class="active"><span class="menu-icon">⚙</span>Maintenance</a>
<a href="../inspections/inspections.php"><span class="menu-icon">◫</span>Inspections</a>
<a href="../messages.php"><span class="menu-icon">✉</span>Communication</a>
<div class="menu-title">System</div>
<a href="../activity_log.php"><span class="menu-icon">☷</span>Activity Log</a>
<div class="logout-area">
<a href="../../auth/logout.php" class="logout"><span class="menu-icon">↪</span>Logout</a>
</div>
</div>

<div class="main">
<div class="topbar">
<div class="page-title">
<h1>Maintenance Management</h1>
<p>Review, prioritise and manage maintenance requests across your properties.</p>
</div>
<a href="../profile.php" class="top-profile">
<div class="profile-icon"><?php echo htmlspecialchars($managerLetter); ?></div>
<div class="profile-info">
<strong><?php echo htmlspecialchars($managerName); ?></strong>
<span><?php echo htmlspecialchars($jobTitle); ?></span>
</div>
</a>
</div>

<div class="summary-grid">
<div class="summary-card">
<span class="summary-label">Total Requests</span>
<div class="summary-value"><?php echo $totalRequests; ?></div>
</div>
<div class="summary-card">
<span class="summary-label">Active Requests</span>
<div class="summary-value teal"><?php echo $activeRequests; ?></div>
</div>
<div class="summary-card">
<span class="summary-label">Urgent Requests</span>
<div class="summary-value red"><?php echo $urgentRequests; ?></div>
</div>
<div class="summary-card">
<span class="summary-label">Completed</span>
<div class="summary-value green"><?php echo $completedRequests; ?></div>
</div>
</div>

<div class="search-card">
<div class="search-heading">
<h2>Search Requests</h2>
<p>Find a request by issue, request number, tenant or property.</p>
</div>
<form method="GET" action="maintenance.php" class="search-form">
<input type="text" name="search" placeholder="Search request, tenant or property..." value="<?php echo htmlspecialchars($search); ?>">
<select name="status">
<option value="">All Statuses</option>
<option value="submitted" <?php echo $statusFilter==='submitted'?'selected':''; ?>>Submitted</option>
<option value="in_review" <?php echo $statusFilter==='in_review'?'selected':''; ?>>In Review</option>
<option value="scheduled" <?php echo $statusFilter==='scheduled'?'selected':''; ?>>Scheduled</option>
<option value="in_progress" <?php echo $statusFilter==='in_progress'?'selected':''; ?>>In Progress</option>
<option value="completed" <?php echo $statusFilter==='completed'?'selected':''; ?>>Completed</option>
<option value="cancelled" <?php echo $statusFilter==='cancelled'?'selected':''; ?>>Cancelled</option>
</select>
<select name="priority">
<option value="">All Priorities</option>
<option value="urgent" <?php echo $priorityFilter==='urgent'?'selected':''; ?>>Urgent</option>
<option value="high" <?php echo $priorityFilter==='high'?'selected':''; ?>>High</option>
<option value="medium" <?php echo $priorityFilter==='medium'?'selected':''; ?>>Medium</option>
<option value="low" <?php echo $priorityFilter==='low'?'selected':''; ?>>Low</option>
</select>
<button type="submit" class="search-button">Search</button>
</form>
</div>

<div class="section-card">
<div class="section-header">
<div>
<h2>Maintenance Requests</h2>
<p>Open a request to review the issue and manage its progress.</p>
</div>
<span class="result-count"><?php echo $resultCount; ?> result<?php echo $resultCount===1?'':'s'; ?></span>
</div>

<div class="table-wrap">
<table>
<thead>
<tr>
<th>Request</th>
<th>Tenant</th>
<th>Property</th>
<th>Category</th>
<th>Priority</th>
<th>Status</th>
<th>Submitted</th>
<th>Action</th>
</tr>
</thead>
<tbody>
<?php if($maintenanceResult->num_rows>0): ?>
<?php while($request=$maintenanceResult->fetch_assoc()): ?>
<tr>
<td>
<span class="request-title"><?php echo htmlspecialchars($request['title']); ?></span>
<span class="request-id">Request #<?php echo (int)$request['maintenance_id']; ?></span>
</td>
<td>
<span class="primary-text"><?php echo htmlspecialchars($request['first_name'].' '.$request['last_name']); ?></span>
</td>
<td>
<span class="primary-text"><?php echo htmlspecialchars($request['property_code']); ?></span>
<span class="sub-text"><?php echo htmlspecialchars($request['address_line1'].', '.$request['suburb']); ?></span>
</td>
<td><?php echo htmlspecialchars(niceText($request['category'])); ?></td>
<td>
<span class="badge priority-<?php echo htmlspecialchars($request['priority']); ?>">
<?php echo htmlspecialchars(niceText($request['priority'])); ?>
</span>
</td>
<td>
<span class="badge status-<?php echo htmlspecialchars($request['status']); ?>">
<?php echo htmlspecialchars(niceText($request['status'])); ?>
</span>
</td>
<td><?php echo shortDate($request['submitted_at']); ?></td>
<td>
<a href="view_maintenance.php?id=<?php echo (int)$request['maintenance_id']; ?>" class="manage-button">Manage</a>
</td>
</tr>
<?php endwhile; ?>
<?php else: ?>
<tr>
<td colspan="8" class="empty">
<?php echo ($search!==''||$statusFilter!==''||$priorityFilter!=='')?'No requests match your search. Open Maintenance from the sidebar to show all requests.':'No maintenance requests have been submitted yet.'; ?>
</td>
</tr>
<?php endif; ?>
</tbody>
</table>
</div>
</div>

<footer class="footer">
<strong>© 2026 RentEase Property Management System</strong>
&nbsp; • &nbsp;
Renting Made Easy.
</footer>
</div>
</body>
</html>