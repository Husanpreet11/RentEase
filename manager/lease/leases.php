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

$userId=(int)$_SESSION['user_id'];

// Manager details
$stmt=$conn->prepare("
SELECT u.first_name,u.last_name,mp.job_title
FROM users u
LEFT JOIN manager_profiles mp ON u.user_id=mp.manager_id
WHERE u.user_id=?
LIMIT 1
");
$stmt->bind_param("i",$userId);
$stmt->execute();
$manager=$stmt->get_result()->fetch_assoc();

$firstName=$manager['first_name']??'Manager';
$lastName=$manager['last_name']??'';
$fullName=trim($firstName.' '.$lastName);
$profileLetter=strtoupper(substr($firstName,0,1));
$jobTitle=!empty($manager['job_title'])?$manager['job_title']:'Property Manager';

// Search options
$search=trim($_GET['search']??'');
$status=trim($_GET['status']??'');

$allowedStatuses=['active','expired','terminated','renewal_due'];

if($status!=='' && !in_array($status,$allowedStatuses,true)){
$status='';
}

// Lease summary
$totalLeases=0;
$activeLeases=0;
$renewalDue=0;
$expiredLeases=0;

$result=$conn->query("
SELECT
COUNT(*) AS total,
SUM(CASE WHEN lease_status='active' THEN 1 ELSE 0 END) AS active_count,
SUM(CASE WHEN lease_status='renewal_due' THEN 1 ELSE 0 END) AS renewal_count,
SUM(CASE WHEN lease_status='expired' THEN 1 ELSE 0 END) AS expired_count
FROM leases
");

if($result){
$summary=$result->fetch_assoc();
$totalLeases=(int)($summary['total']??0);
$activeLeases=(int)($summary['active_count']??0);
$renewalDue=(int)($summary['renewal_count']??0);
$expiredLeases=(int)($summary['expired_count']??0);
}

// Get leases
$sql="
SELECT
l.lease_id,
l.start_date,
l.end_date,
l.monthly_rent,
l.bond_amount,
l.lease_status,
l.lease_document,
u.user_id AS tenant_id,
u.first_name,
u.last_name,
u.email,
p.property_id,
p.property_code,
p.address_line1,
p.suburb,
p.state,
p.postcode
FROM leases l
INNER JOIN users u ON l.tenant_id=u.user_id
INNER JOIN properties p ON l.property_id=p.property_id
WHERE 1=1
";

$params=[];
$types="";

if($search!==''){
$sql.=" AND (
CAST(l.lease_id AS CHAR) LIKE ?
OR u.first_name LIKE ?
OR u.last_name LIKE ?
OR CONCAT(u.first_name,' ',u.last_name) LIKE ?
OR u.email LIKE ?
OR p.property_code LIKE ?
OR p.address_line1 LIKE ?
OR p.suburb LIKE ?
)";
$searchTerm="%".$search."%";
for($i=0;$i<8;$i++){
$params[]=$searchTerm;
$types.="s";
}
}

if($status!==''){
$sql.=" AND l.lease_status=?";
$params[]=$status;
$types.="s";
}

$sql.=" ORDER BY
CASE
WHEN l.lease_status='renewal_due' THEN 1
WHEN l.lease_status='active' THEN 2
WHEN l.lease_status='expired' THEN 3
WHEN l.lease_status='terminated' THEN 4
ELSE 5
END,
l.end_date ASC,
l.lease_id DESC";

$stmt=$conn->prepare($sql);

if(!$stmt){
die("Unable to load leases: ".$conn->error);
}

if(!empty($params)){
$stmt->bind_param($types,...$params);
}

$stmt->execute();
$leases=$stmt->get_result();
$resultCount=$leases->num_rows;

// Flash message
$successMessage='';

if(isset($_SESSION['lease_flash'])){
$successMessage=$_SESSION['lease_flash'];
unset($_SESSION['lease_flash']);
}

// Also support redirects from older add/edit pages for now
if(isset($_GET['added'])){
$successMessage="Lease created successfully.";
}
if(isset($_GET['updated'])){
$successMessage="Lease updated successfully.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Lease Management | RentEase</title>

<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:"Segoe UI",Arial,sans-serif;background:#f5f7f6;color:#243331;font-size:16px}

/* Sidebar */
.sidebar{position:fixed;top:0;left:0;width:255px;height:100vh;background:#183b3a;padding:25px 18px;overflow-y:auto}
.brand{padding:0 10px 25px}
.brand-name{font-size:25px;font-weight:700;color:#fff}
.brand-name span{color:#8bc7c0}
.brand-tagline{margin-top:5px;color:#b7d1cd;font-size:12px}
.menu-title{margin:20px 10px 8px;color:#8fb0ac;font-size:11px;font-weight:700;letter-spacing:1px;text-transform:uppercase}
.sidebar a{display:flex;align-items:center;gap:11px;padding:11px 12px;margin-bottom:4px;border-radius:8px;color:#d6e4e2;font-size:15px;text-decoration:none;transition:.2s}
.sidebar a:hover{background:#24514e;color:#fff}
.sidebar a.active{background:#2f8178;color:#fff;font-weight:600}
.menu-icon{width:20px;text-align:center}
.logout-area{margin-top:25px;padding-top:15px;border-top:1px solid #315553}
.sidebar .logout{color:#f1c1c1}
.sidebar .logout:hover{background:#68403e;color:#fff}

/* Main */
.main{margin-left:255px;min-height:100vh;padding:30px 36px 0}
.topbar{display:flex;align-items:center;justify-content:space-between;gap:20px;margin-bottom:24px}
.page-title h1{font-size:32px;font-weight:650;margin-bottom:5px}
.page-title p{font-size:16px;color:#687976}

/* Manager profile */
.top-profile{display:flex;align-items:center;gap:11px;min-width:215px;padding:10px 14px;background:#fff;border:1px solid #dce5e2;border-radius:12px;color:#243331;text-decoration:none}
.top-profile:hover{border-color:#9fc8c2;box-shadow:0 4px 14px rgba(47,129,120,.08)}
.profile-icon{display:flex;align-items:center;justify-content:center;width:42px;height:42px;background:#2f8178;border-radius:50%;color:#fff;font-size:16px;font-weight:700}
.profile-info{display:flex;flex-direction:column}
.profile-info strong{font-size:15px;font-weight:600}
.profile-info span{margin-top:2px;color:#71817e;font-size:12px}

/* Summary */
.summary-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:20px}
.summary-card{position:relative;background:#fff;border:1px solid #dce5e2;border-radius:14px;padding:19px 20px;box-shadow:0 3px 12px rgba(35,70,66,.04);overflow:hidden}
..summary-card{
background:#fff;
border:1px solid #dce5e2;
border-radius:14px;
padding:19px 20px;
box-shadow:0 3px 12px rgba(35,70,66,.04);
}
.summary-label{display:block;color:#71817e;font-size:12px;font-weight:600;margin-bottom:7px}
.summary-value{display:block;color:#40514e;font-size:28px;font-weight:600;line-height:1}
.summary-note{display:block;margin-top:7px;color:#82918e;font-size:11px}

/* Search */
.search-card{background:#fff;border:1px solid #dce5e2;border-radius:14px;padding:18px 20px;margin-bottom:20px;box-shadow:0 3px 12px rgba(35,70,66,.04)}
.search-top{display:flex;align-items:center;justify-content:space-between;gap:15px;margin-bottom:14px}
.search-top h2{font-size:19px;font-weight:650}
.search-top p{margin-top:3px;color:#82918e;font-size:12px}
.add-button{display:inline-flex;align-items:center;justify-content:center;height:40px;padding:0 17px;background:#2f8178;border-radius:8px;color:#fff;font-size:14px;font-weight:600;text-decoration:none}
.add-button:hover{background:#286f68}
.search-form{display:grid;grid-template-columns:minmax(250px,1fr) 190px 110px;gap:10px}
.search-form input,.search-form select{width:100%;height:42px;padding:0 12px;border:1px solid #ccd9d6;border-radius:8px;background:#fff;color:#40514e;font-family:inherit;font-size:13px;outline:none}
.search-form input:focus,.search-form select:focus{border-color:#2f8178;box-shadow:0 0 0 3px rgba(47,129,120,.08)}
.search-button{height:42px;border:0;border-radius:8px;background:#2f8178;color:#fff;font-family:inherit;font-size:14px;font-weight:600;cursor:pointer}
.search-button:hover{background:#286f68}

/* Message */
.message{margin-bottom:18px;padding:12px 15px;border:1px solid #b9dcca;border-radius:9px;background:#edf8f1;color:#31684d;font-size:13px}

/* Lease table */
.table-card{background:#fff;border:1px solid #dce5e2;border-radius:14px;overflow:hidden;box-shadow:0 3px 12px rgba(35,70,66,.04)}
.table-heading{display:flex;align-items:center;justify-content:space-between;gap:15px;padding:17px 20px;border-bottom:1px solid #e3ebe9;background:#fbfdfc}
.table-heading h2{font-size:19px;font-weight:650}
.table-heading span{color:#82918e;font-size:12px}
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse}
thead th{padding:12px 14px;background:#f5f8f7;border-bottom:1px solid #dce5e2;color:#687976;font-size:11px;font-weight:700;text-align:left;text-transform:uppercase;letter-spacing:.3px;white-space:nowrap}
tbody td{padding:15px 14px;border-bottom:1px solid #edf1f0;color:#40514e;font-size:13px;vertical-align:middle}
tbody tr:last-child td{border-bottom:0}
tbody tr:hover{background:#fbfdfc}

/* Lease reference */
.lease-reference strong{display:block;color:#40514e;font-size:14px;font-weight:650}
.lease-reference span{display:block;margin-top:3px;color:#82918e;font-size:11px}

/* Tenant */
.tenant-cell{display:flex;align-items:center;gap:10px;min-width:160px}
.tenant-letter{display:flex;align-items:center;justify-content:center;width:34px;height:34px;flex-shrink:0;border-radius:50%;background:#e8f3f1;color:#2f8178;font-size:12px;font-weight:700}
.tenant-cell strong{display:block;color:#40514e;font-size:13px;font-weight:600}
.tenant-cell span{display:block;margin-top:2px;color:#82918e;font-size:11px}

/* Property */
.property-cell{min-width:170px}
.property-cell strong{display:block;color:#40514e;font-size:13px;font-weight:600}
.property-cell span{display:block;margin-top:3px;color:#82918e;font-size:11px;line-height:1.4}

/* Dates */
.date-cell{white-space:nowrap}
.date-cell strong{display:block;font-size:13px;font-weight:600;color:#40514e}
.date-cell span{display:block;margin-top:3px;color:#82918e;font-size:11px}

/* Rent */
.rent-value{font-size:14px;font-weight:600;color:#40514e;white-space:nowrap}
.rent-value small{font-size:10px;color:#82918e;font-weight:400}

/* Status */
.status-badge{display:inline-flex;align-items:center;justify-content:center;padding:5px 9px;border-radius:20px;font-size:10px;font-weight:700;text-transform:capitalize;white-space:nowrap}
.status-active{background:#e7f4ed;color:#2f6f54}
.status-renewal_due{background:#fff3df;color:#936526}
.status-expired{background:#f2eeee;color:#7b5555}
.status-terminated{background:#f7e8e8;color:#934a4a}

/* Actions */
.actions{display:flex;align-items:center;gap:6px;white-space:nowrap}
.action-link{display:inline-flex;align-items:center;justify-content:center;padding:7px 10px;border:1px solid #d4dfdc;border-radius:7px;background:#fff;color:#40514e;font-size:11px;font-weight:600;text-decoration:none}
.action-link:hover{border-color:#9fc8c2;background:#eef6f4;color:#2f8178}
.action-link.primary{border-color:#b9d9d4;background:#eef6f4;color:#2f8178}

/* Empty */
.empty-state{padding:55px 20px;text-align:center}
.empty-icon{display:flex;align-items:center;justify-content:center;width:55px;height:55px;margin:0 auto 12px;border-radius:50%;background:#eef6f4;color:#2f8178;font-size:22px}
.empty-state h3{margin-bottom:5px;font-size:17px;color:#40514e}
.empty-state p{color:#82918e;font-size:13px}

/* Footer */
.footer{margin:30px -36px 0;padding:20px 30px;background:#183b3a;color:#c7d9d6;font-size:13px;text-align:center}
.footer strong{color:#fff}

@media(max-width:1150px){
.summary-grid{grid-template-columns:repeat(2,1fr)}
.search-form{grid-template-columns:1fr 180px}
.search-button{grid-column:1/-1}
}

@media(max-width:760px){
.sidebar{position:relative;width:100%;height:auto}
.main{margin-left:0;padding:22px}
.topbar{align-items:flex-start;flex-direction:column}
.top-profile{width:100%}
.summary-grid{grid-template-columns:1fr 1fr}
.search-top{align-items:flex-start;flex-direction:column}
.add-button{width:100%}
.search-form{grid-template-columns:1fr}
.search-button{grid-column:auto}
.footer{margin-left:-22px;margin-right:-22px}
}

@media(max-width:500px){
.summary-grid{grid-template-columns:1fr}
}
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
<a href="leases.php" class="active"><span class="menu-icon">▤</span>Leases</a>
<a href="../rent/payments.php"><span class="menu-icon">$</span>Rent & Utilities</a>

<div class="menu-title">Operations</div>
<a href="../maintenance/maintenance.php"><span class="menu-icon">⚙</span>Maintenance</a>
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
<h1>Lease Management</h1>
<p>Manage tenant leases and rental agreements.</p>
</div>

<a href="../profile.php" class="top-profile">
<div class="profile-icon"><?php echo htmlspecialchars($profileLetter); ?></div>
<div class="profile-info">
<strong><?php echo htmlspecialchars($fullName); ?></strong>
<span><?php echo htmlspecialchars($jobTitle); ?></span>
</div>
</a>

</div>

<?php if($successMessage!==''): ?>
<div class="message">
<?php echo htmlspecialchars($successMessage); ?>
</div>
<?php endif; ?>

<!-- Lease summary -->
<div class="summary-grid">

<div class="summary-card">
<span class="summary-label">Total Leases</span>
<span class="summary-value"><?php echo $totalLeases; ?></span>
<span class="summary-note">All lease records</span>
</div>

<div class="summary-card">
<span class="summary-label">Active Leases</span>
<span class="summary-value"><?php echo $activeLeases; ?></span>
<span class="summary-note">Currently active</span>
</div>

<div class="summary-card">
<span class="summary-label">Renewal Due</span>
<span class="summary-value"><?php echo $renewalDue; ?></span>
<span class="summary-note">Require attention</span>
</div>

<div class="summary-card">
<span class="summary-label">Expired</span>
<span class="summary-value"><?php echo $expiredLeases; ?></span>
<span class="summary-note">Past agreements</span>
</div>

</div>

<!-- Search -->
<div class="search-card">

<div class="search-top">

<div>
<h2>Search Leases</h2>
<p>Search by tenant, property, email or lease number.</p>
</div>

<a href="add_lease.php" class="add-button">+ Add Lease</a>

</div>

<form method="GET" action="leases.php" class="search-form">

<input
type="text"
name="search"
placeholder="Search tenant, property or lease..."
value="<?php echo htmlspecialchars($search); ?>"
>

<select name="status">
<option value="">All Statuses</option>
<option value="active" <?php echo $status==='active'?'selected':''; ?>>Active</option>
<option value="renewal_due" <?php echo $status==='renewal_due'?'selected':''; ?>>Renewal Due</option>
<option value="expired" <?php echo $status==='expired'?'selected':''; ?>>Expired</option>
<option value="terminated" <?php echo $status==='terminated'?'selected':''; ?>>Terminated</option>
</select>

<button type="submit" class="search-button">Search</button>

</form>

</div>

<!-- Lease records -->
<div class="table-card">

<div class="table-heading">
<h2>Lease Records</h2>
<span><?php echo $resultCount; ?> result<?php echo $resultCount!==1?'s':''; ?></span>
</div>

<?php if($resultCount>0): ?>

<div class="table-wrap">

<table>

<thead>
<tr>
<th>Lease</th>
<th>Tenant</th>
<th>Property</th>
<th>Lease Period</th>
<th>Monthly Rent</th>
<th>Status</th>
<th>Actions</th>
</tr>
</thead>

<tbody>

<?php while($lease=$leases->fetch_assoc()): ?>

<tr>

<td>
<div class="lease-reference">
<strong>Lease #<?php echo (int)$lease['lease_id']; ?></strong>
<span><?php echo !empty($lease['lease_document'])?'Agreement attached':'No agreement'; ?></span>
</div>
</td>

<td>

<div class="tenant-cell">

<div class="tenant-letter">
<?php echo htmlspecialchars(strtoupper(substr($lease['first_name'],0,1))); ?>
</div>

<div>
<strong>
<?php echo htmlspecialchars($lease['first_name'].' '.$lease['last_name']); ?>
</strong>
<span><?php echo htmlspecialchars($lease['email']); ?></span>
</div>

</div>

</td>

<td>

<div class="property-cell">
<strong><?php echo htmlspecialchars($lease['property_code']); ?></strong>
<span>
<?php echo htmlspecialchars($lease['address_line1'].', '.$lease['suburb']); ?>
</span>
</div>

</td>

<td>

<div class="date-cell">
<strong>
<?php echo date("d M Y",strtotime($lease['start_date'])); ?>
</strong>
<span>
to <?php echo date("d M Y",strtotime($lease['end_date'])); ?>
</span>
</div>

</td>

<td>
<div class="rent-value">
$<?php echo number_format((float)$lease['monthly_rent'],2); ?>
<small>/ month</small>
</div>
</td>

<td>
<span class="status-badge status-<?php echo htmlspecialchars($lease['lease_status']); ?>">
<?php echo htmlspecialchars(ucwords(str_replace('_',' ',$lease['lease_status']))); ?>
</span>
</td>

<td>

<div class="actions">

<a
href="view_lease.php?id=<?php echo (int)$lease['lease_id']; ?>"
class="action-link primary"
>
View
</a>

<a
href="edit_lease.php?id=<?php echo (int)$lease['lease_id']; ?>"
class="action-link"
>
Edit
</a>

</div>

</td>

</tr>

<?php endwhile; ?>

</tbody>

</table>

</div>

<?php else: ?>

<div class="empty-state">
<div class="empty-icon">▤</div>
<h3>No leases found</h3>

<?php if($search!=='' || $status!==''): ?>
<p>No lease records match your search.</p>
<?php else: ?>
<p>No lease records have been created yet.</p>
<?php endif; ?>

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