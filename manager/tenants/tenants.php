<?php
session_start();
require_once __DIR__.'/../../config/db.php';

// Only managers can access tenant management
if(!isset($_SESSION['user_id'])){
header("Location: ../../index.php");
exit();
}

if(($_SESSION['role']??'')!=='manager'){
header("Location: ../../tenant/dashboard.php");
exit();
}

$managerId=(int)$_SESSION['user_id'];

// Get manager details for the profile shown at the top
$stmt=$conn->prepare("SELECT u.first_name,u.last_name,mp.job_title
FROM users u
LEFT JOIN manager_profiles mp ON u.user_id=mp.manager_id
WHERE u.user_id=? LIMIT 1");
$stmt->bind_param("i",$managerId);
$stmt->execute();
$manager=$stmt->get_result()->fetch_assoc();

$firstName=$manager['first_name']??'Manager';
$fullName=trim($firstName.' '.($manager['last_name']??''));
$profileLetter=strtoupper(substr($firstName,0,1));
$jobTitle=!empty($manager['job_title'])?$manager['job_title']:'Property Manager';

// Get search values entered by the manager
$search=trim($_GET['search']??'');
$status=trim($_GET['status']??'');

// Count tenants for the summary cards
$totalTenants=0;
$activeTenants=0;
$tenantsWithLease=0;

$result=$conn->query("SELECT COUNT(*) AS total FROM users WHERE role='tenant'");
if($result){
$totalTenants=(int)$result->fetch_assoc()['total'];
}

$result=$conn->query("SELECT COUNT(*) AS total FROM users WHERE role='tenant' AND account_status='active'");
if($result){
$activeTenants=(int)$result->fetch_assoc()['total'];
}

$result=$conn->query("SELECT COUNT(DISTINCT tenant_id) AS total FROM leases WHERE lease_status IN('active','renewal_due')");
if($result){
$tenantsWithLease=(int)$result->fetch_assoc()['total'];
}

// Get tenants together with their current lease and property
$sql="SELECT u.user_id,u.first_name,u.last_name,u.email,u.phone,u.account_status,
l.lease_id,l.end_date,l.monthly_rent,l.lease_status,
p.property_id,p.property_code,p.address_line1,p.suburb
FROM users u
LEFT JOIN leases l ON l.lease_id=(
SELECT l2.lease_id
FROM leases l2
WHERE l2.tenant_id=u.user_id
AND l2.lease_status IN('active','renewal_due')
ORDER BY l2.start_date DESC
LIMIT 1
)
LEFT JOIN properties p ON l.property_id=p.property_id
WHERE u.role='tenant'";

$params=[];
$types='';

// Add search condition when a search is entered
if($search!==''){
$sql.=" AND (
CONCAT(u.first_name,' ',u.last_name) LIKE ?
OR u.email LIKE ?
OR u.phone LIKE ?
OR p.property_code LIKE ?
)";
$value='%'.$search.'%';
$params=[$value,$value,$value,$value];
$types='ssss';
}

// Add account status condition
if(in_array($status,['active','inactive'],true)){
$sql.=" AND u.account_status=?";
$params[]=$status;
$types.='s';
}

$sql.=" ORDER BY u.first_name,u.last_name";

$stmt=$conn->prepare($sql);

if(!empty($params)){
$stmt->bind_param($types,...$params);
}

$stmt->execute();
$tenants=$stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Tenant Management | RentEase</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:"Segoe UI",Arial,sans-serif;background:#f5f7f6;color:#52615f;font-size:16px}
.sidebar{position:fixed;top:0;left:0;width:255px;height:100vh;background:#183b3a;padding:25px 18px;overflow-y:auto}
.brand{padding:0 10px 25px}
.brand-name{font-size:25px;font-weight:700;color:#fff}
.brand-name span{color:#8bc7c0}
.brand-tagline{margin-top:5px;color:#b7d1cd;font-size:12px}
.menu-title{margin:20px 10px 8px;color:#8fb0ac;font-size:11px;font-weight:700;letter-spacing:1px;text-transform:uppercase}
.sidebar a{display:flex;align-items:center;gap:11px;padding:11px 12px;margin-bottom:4px;border-radius:8px;color:#d6e4e2;font-size:15px;text-decoration:none}
.sidebar a:hover{background:#24514e;color:#fff}
.sidebar a.active{background:#2f8178;color:#fff;font-weight:600}
.menu-icon{width:20px;text-align:center}
.logout-area{margin-top:25px;padding-top:15px;border-top:1px solid #315553}
.sidebar .logout{color:#f1c1c1}
.main{margin-left:255px;min-height:100vh;padding:30px 36px 0}
.topbar{display:flex;align-items:center;justify-content:space-between;gap:20px;margin-bottom:22px}
.page-title h1{font-size:32px;font-weight:650;color:#40514e;margin-bottom:5px}
.page-title p{font-size:16px;color:#71817e}
.top-profile{display:flex;align-items:center;gap:11px;min-width:215px;padding:10px 14px;background:#fff;border:1px solid #dce5e2;border-radius:12px;color:#52615f;text-decoration:none}
.profile-icon{display:flex;align-items:center;justify-content:center;width:42px;height:42px;background:#2f8178;border-radius:50%;color:#fff;font-weight:700}
.profile-info{display:flex;flex-direction:column}
.profile-info strong{font-size:15px;font-weight:600;color:#40514e}
.profile-info span{margin-top:2px;color:#82918e;font-size:12px}
.page-actions{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px}
.section-heading h2{font-size:20px;font-weight:650;color:#40514e;margin-bottom:3px}
.section-heading p{font-size:13px;color:#82918e}
.add-button,.search-button{border:0;background:#2f8178;color:#fff;border-radius:8px;font-family:inherit;font-size:14px;font-weight:600;cursor:pointer;text-decoration:none}
.add-button{padding:11px 17px}
.search-button{padding:10px 19px}
.add-button:hover,.search-button:hover{background:#286f68}
.summary-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:20px}
.summary-card{background:#fff;border:1px solid #dce5e2;border-radius:14px;padding:18px 20px;box-shadow:0 3px 12px rgba(35,70,66,.04)}
.summary-card span{display:block;margin-bottom:7px;color:#82918e;font-size:12px}
.summary-card strong{display:block;color:#52615f;font-size:26px;font-weight:600}
.search-card{background:#fff;border:1px solid #dce5e2;border-radius:14px;padding:16px;margin-bottom:20px;box-shadow:0 3px 12px rgba(35,70,66,.04)}
.search-form{display:grid;grid-template-columns:1fr 190px auto;gap:10px}
.search-form input,.search-form select{width:100%;padding:10px 12px;border:1px solid #ccd9d6;border-radius:8px;background:#fff;color:#52615f;font-family:inherit;font-size:13px;outline:none}
.search-form input:focus,.search-form select:focus{border-color:#2f8178;box-shadow:0 0 0 3px rgba(47,129,120,.08)}
.table-card{background:#fff;border:1px solid #dce5e2;border-radius:15px;overflow:hidden;box-shadow:0 3px 12px rgba(35,70,66,.04)}
.table-heading{display:flex;justify-content:space-between;align-items:center;padding:17px 20px;border-bottom:1px solid #e3ebe9;background:#fbfdfc}
.table-heading h2{font-size:19px;color:#40514e;font-weight:650}
.table-heading span{font-size:12px;color:#82918e}
table{width:100%;border-collapse:collapse}
th{padding:12px 15px;background:#f7faf9;color:#71817e;font-size:11px;text-align:left;text-transform:uppercase;letter-spacing:.3px}
td{padding:14px 15px;border-top:1px solid #edf1f0;color:#52615f;font-size:13px;vertical-align:middle}
.tenant-name{font-weight:600;color:#40514e}
.sub-text{display:block;margin-top:3px;color:#8a9896;font-size:11px}
.status{display:inline-block;padding:5px 9px;border-radius:20px;font-size:10px;font-weight:700;text-transform:capitalize}
.status-active{background:#e7f4ed;color:#3e765d}
.status-inactive{background:#f0f2f2;color:#6f7b79}
.lease-status{color:#52615f;font-weight:600}
.no-lease{color:#929e9c}
.view-button{display:inline-block;padding:7px 11px;background:#eef6f4;border:1px solid #cfe2df;border-radius:7px;color:#2f8178;font-size:12px;font-weight:600;text-decoration:none}
.view-button:hover{background:#e3f0ed}
.empty{text-align:center;padding:35px;color:#82918e}
.footer{margin:30px -36px 0;padding:20px 30px;background:#183b3a;color:#c7d9d6;font-size:13px;text-align:center}
.footer strong{color:#fff}
@media(max-width:1000px){.summary-grid{grid-template-columns:1fr}.table-card{overflow-x:auto}}
@media(max-width:760px){.sidebar{position:relative;width:100%;height:auto}.main{margin-left:0;padding:22px}.topbar,.page-actions{align-items:flex-start;flex-direction:column}.search-form{grid-template-columns:1fr}.footer{margin-left:-22px;margin-right:-22px}}
</style>
</head>
<body>

<!-- Manager sidebar -->
<div class="sidebar">
<div class="brand">
<div class="brand-name">Rent<span>Ease</span></div>
<div class="brand-tagline">Renting Made Easy.</div>
</div>

<div class="menu-title">Main</div>
<a href="../dashboard.php"><span class="menu-icon">⌂</span>Dashboard</a>
<a href="../profile.php"><span class="menu-icon">●</span>Profile</a>

<div class="menu-title">Management</div>
<a href="../properties/properties.php"><span class="menu-icon">⌂</span>Properties</a>
<a href="tenants.php" class="active"><span class="menu-icon">♙</span>Tenants</a>
<a href="../lease/leases.php"><span class="menu-icon">▣</span>Leases</a>
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

<!-- Page heading and manager profile -->
<div class="topbar">
<div class="page-title">
<h1>Tenant Management</h1>
<p>Manage tenant accounts and their current rental details.</p>
</div>

<a href="../profile.php" class="top-profile">
<div class="profile-icon"><?php echo htmlspecialchars($profileLetter); ?></div>
<div class="profile-info">
<strong><?php echo htmlspecialchars($fullName); ?></strong>
<span><?php echo htmlspecialchars($jobTitle); ?></span>
</div>
</a>
</div>

<div class="page-actions">
<div class="section-heading">
<h2>Tenants</h2>
<p>View and manage registered tenant accounts.</p>
</div>
<a href="add_tenant.php" class="add-button">+ Add Tenant</a>
</div>

<!-- Tenant summary cards -->
<div class="summary-grid">
<div class="summary-card">
<span>Total Tenants</span>
<strong><?php echo $totalTenants; ?></strong>
</div>

<div class="summary-card">
<span>Active Accounts</span>
<strong><?php echo $activeTenants; ?></strong>
</div>

<div class="summary-card">
<span>Current Tenancies</span>
<strong><?php echo $tenantsWithLease; ?></strong>
</div>
</div>

<!-- Search tenants -->
<div class="search-card">
<form method="GET" class="search-form">
<input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search name, email, phone or property code">

<select name="status">
<option value="">All account statuses</option>
<option value="active" <?php echo $status==='active'?'selected':''; ?>>Active</option>
<option value="inactive" <?php echo $status==='inactive'?'selected':''; ?>>Inactive</option>
</select>

<button type="submit" class="search-button">Search</button>
</form>
</div>

<!-- Tenant records -->
<div class="table-card">
<div class="table-heading">
<h2>Tenant Records</h2>
<span><?php echo $tenants->num_rows; ?> result<?php echo $tenants->num_rows===1?'':'s'; ?></span>
</div>

<table>
<thead>
<tr>
<th>Tenant</th>
<th>Contact</th>
<th>Current Property</th>
<th>Monthly Rent</th>
<th>Account</th>
<th>Action</th>
</tr>
</thead>

<tbody>
<?php if($tenants->num_rows>0): ?>
<?php while($tenant=$tenants->fetch_assoc()): ?>
<tr>
<td>
<span class="tenant-name"><?php echo htmlspecialchars($tenant['first_name'].' '.$tenant['last_name']); ?></span>
<span class="sub-text">Tenant #<?php echo (int)$tenant['user_id']; ?></span>
</td>

<td>
<?php echo htmlspecialchars($tenant['email']); ?>
<span class="sub-text"><?php echo htmlspecialchars($tenant['phone']?:'No phone provided'); ?></span>
</td>

<td>
<?php if(!empty($tenant['property_id'])): ?>
<span class="lease-status"><?php echo htmlspecialchars($tenant['property_code']); ?></span>
<span class="sub-text"><?php echo htmlspecialchars($tenant['address_line1'].', '.$tenant['suburb']); ?></span>
<?php else: ?>
<span class="no-lease">No current property</span>
<?php endif; ?>
</td>

<td>
<?php echo $tenant['monthly_rent']!==null?'$'.number_format((float)$tenant['monthly_rent'],2):'—'; ?>
</td>

<td>
<span class="status status-<?php echo htmlspecialchars($tenant['account_status']); ?>">
<?php echo htmlspecialchars(ucfirst($tenant['account_status'])); ?>
</span>
</td>

<td>
<a href="view_tenant.php?id=<?php echo (int)$tenant['user_id']; ?>" class="view-button">View</a>
</td>
</tr>
<?php endwhile; ?>
<?php else: ?>
<tr>
<td colspan="6" class="empty">No tenants found.</td>
</tr>
<?php endif; ?>
</tbody>
</table>
</div>

<!-- Footer -->
<footer class="footer">
<strong>© 2026 RentEase Property Management System</strong>
&nbsp; • &nbsp;
Renting Made Easy.
</footer>

</div>
</body>
</html>