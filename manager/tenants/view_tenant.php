<?php
session_start();
require_once __DIR__.'/../../config/db.php';

// Only managers can view tenant management details
if(!isset($_SESSION['user_id'])){
header("Location: ../../index.php");
exit();
}

if(($_SESSION['role']??'')!=='manager'){
header("Location: ../../tenant/dashboard.php");
exit();
}

$managerId=(int)$_SESSION['user_id'];
$tenantId=(int)($_GET['id']??0);

// Tenant ID must be provided
if($tenantId<=0){
header("Location: tenants.php");
exit();
}

// Get manager information for the top profile
$stmt=$conn->prepare("SELECT u.first_name,u.last_name,mp.job_title
FROM users u
LEFT JOIN manager_profiles mp ON u.user_id=mp.manager_id
WHERE u.user_id=? LIMIT 1");
$stmt->bind_param("i",$managerId);
$stmt->execute();
$manager=$stmt->get_result()->fetch_assoc();

$managerFirst=$manager['first_name']??'Manager';
$managerName=trim($managerFirst.' '.($manager['last_name']??''));
$managerLetter=strtoupper(substr($managerFirst,0,1));
$jobTitle=!empty($manager['job_title'])?$manager['job_title']:'Property Manager';

// Load tenant account information
$stmt=$conn->prepare("SELECT user_id,first_name,last_name,email,phone,address,account_status,created_at
FROM users
WHERE user_id=? AND role='tenant'
LIMIT 1");
$stmt->bind_param("i",$tenantId);
$stmt->execute();
$tenant=$stmt->get_result()->fetch_assoc();

// Return to tenant list if tenant does not exist
if(!$tenant){
header("Location: tenants.php");
exit();
}

$tenantName=$tenant['first_name'].' '.$tenant['last_name'];
$tenantLetter=strtoupper(substr($tenant['first_name'],0,1));

// Get the tenant's current lease and property
$stmt=$conn->prepare("SELECT
l.lease_id,l.start_date,l.end_date,l.monthly_rent,l.bond_amount,
l.lease_status,l.lease_document,l.notes,
p.property_id,p.property_code,p.address_line1,p.suburb,p.state,p.postcode,
p.bedrooms,p.bathrooms,p.parking_spaces,p.property_status
FROM leases l
INNER JOIN properties p ON l.property_id=p.property_id
WHERE l.tenant_id=?
AND l.lease_status IN('active','renewal_due')
ORDER BY l.start_date DESC
LIMIT 1");

$stmt->bind_param("i",$tenantId);
$stmt->execute();
$currentLease=$stmt->get_result()->fetch_assoc();

// Display success message only once
$success='';

if(isset($_SESSION['tenant_flash'])){
$success=$_SESSION['tenant_flash'];
unset($_SESSION['tenant_flash']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Tenant Details | RentEase</title>
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
.topbar{display:flex;align-items:center;justify-content:space-between;gap:20px;margin-bottom:20px}
.page-title h1{font-size:32px;font-weight:650;color:#40514e;margin-bottom:5px}
.page-title p{font-size:16px;color:#71817e}
.top-profile{display:flex;align-items:center;gap:11px;min-width:215px;padding:10px 14px;background:#fff;border:1px solid #dce5e2;border-radius:12px;color:#52615f;text-decoration:none}
.profile-icon{display:flex;align-items:center;justify-content:center;width:42px;height:42px;background:#2f8178;border-radius:50%;color:#fff;font-weight:700}
.profile-info{display:flex;flex-direction:column}
.profile-info strong{font-size:15px;color:#40514e}
.profile-info span{margin-top:2px;color:#82918e;font-size:12px}
.action-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px}
.back-link{color:#2f8178;font-size:14px;font-weight:600;text-decoration:none}
.action-buttons{display:flex;gap:9px}
.button{display:inline-flex;align-items:center;justify-content:center;height:40px;padding:0 15px;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none}
.secondary-button{background:#fff;border:1px solid #ccd9d6;color:#5f6d6b}
.primary-button{background:#2f8178;color:#fff}
.primary-button:hover{background:#286f68}
.success-message{padding:12px 15px;margin-bottom:18px;background:#eef7f2;border:1px solid #cfe6d9;border-radius:9px;color:#3e7159;font-size:13px}
.tenant-header{display:flex;align-items:center;gap:17px;padding:21px;margin-bottom:20px;background:#fff;border:1px solid #dce5e2;border-radius:15px;box-shadow:0 3px 12px rgba(35,70,66,.04)}
.tenant-avatar{display:flex;align-items:center;justify-content:center;width:60px;height:60px;flex-shrink:0;background:#eef6f4;border-radius:50%;color:#2f8178;font-size:22px;font-weight:700}
.tenant-heading{flex:1}
.tenant-heading h2{font-size:22px;color:#40514e;font-weight:650;margin-bottom:4px}
.tenant-heading p{font-size:13px;color:#82918e}
.status{display:inline-block;padding:6px 11px;border-radius:20px;font-size:10px;font-weight:700;text-transform:capitalize}
.status-active{background:#e7f4ed;color:#3e765d}
.status-inactive{background:#f0f2f2;color:#6f7b79}
.content-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;align-items:stretch}
.card{display:flex;flex-direction:column;background:#fff;border:1px solid #dce5e2;border-radius:15px;overflow:hidden;box-shadow:0 3px 12px rgba(35,70,66,.04)}
.card-header{padding:17px 20px;border-bottom:1px solid #e3ebe9;background:#fbfdfc}
.card-header h3{font-size:19px;color:#40514e;font-weight:650;margin-bottom:3px}
.card-header p{font-size:12px;color:#82918e}
.card-body{flex:1;padding:8px 20px 15px}
.info-row{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;padding:13px 0;border-bottom:1px solid #edf1f0}
.info-row:last-child{border-bottom:0}
.info-label{color:#82918e;font-size:13px}
.info-value{max-width:65%;color:#52615f;font-size:13px;font-weight:600;text-align:right;overflow-wrap:anywhere}
.property-name{font-size:17px;color:#40514e;font-weight:650;margin-bottom:5px}
.property-address{font-size:13px;color:#71817e;line-height:1.5;margin-bottom:16px}
.property-features{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}
.feature{padding:11px;background:#f7faf9;border:1px solid #e3ebe9;border-radius:8px;text-align:center}
.feature strong{display:block;color:#52615f;font-size:15px;font-weight:600}
.feature span{display:block;margin-top:3px;color:#8a9896;font-size:10px}
.no-rental{display:flex;align-items:center;justify-content:center;min-height:160px;color:#82918e;font-size:13px;text-align:center}
.footer{margin:30px -36px 0;padding:20px 30px;background:#183b3a;color:#c7d9d6;font-size:13px;text-align:center}
.footer strong{color:#fff}
@media(max-width:900px){.content-grid{grid-template-columns:1fr}}
@media(max-width:760px){.sidebar{position:relative;width:100%;height:auto}.main{margin-left:0;padding:22px}.topbar,.action-row{align-items:flex-start;flex-direction:column}.tenant-header{align-items:flex-start}.footer{margin-left:-22px;margin-right:-22px}}
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

<!-- Page heading and actions -->
<div class="topbar">
<div class="page-title">
<h1>Tenant Details</h1>
<p>View the tenant's account and current rental information.</p>
</div>

<a href="../profile.php" class="top-profile">
<div class="profile-icon"><?php echo htmlspecialchars($managerLetter); ?></div>
<div class="profile-info">
<strong><?php echo htmlspecialchars($managerName); ?></strong>
<span><?php echo htmlspecialchars($jobTitle); ?></span>
</div>
</a>
</div>

<div class="action-row">
<a href="tenants.php" class="back-link">← Back to Tenants</a>

<div class="action-buttons">
<a href="edit_tenant.php?id=<?php echo $tenantId; ?>" class="button primary-button">Edit Tenant</a>

<?php if($currentLease): ?>
<a href="../lease/view_lease.php?id=<?php echo (int)$currentLease['lease_id']; ?>" class="button secondary-button">View Lease</a>
<?php else: ?>
<a href="../lease/add_lease.php?tenant_id=<?php echo $tenantId; ?>" class="button secondary-button">Create Lease</a>
<?php endif; ?>
</div>
</div>

<?php if($success!==''): ?>
<div class="success-message"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>

<!-- Tenant account summary -->
<div class="tenant-header">
<div class="tenant-avatar"><?php echo htmlspecialchars($tenantLetter); ?></div>

<div class="tenant-heading">
<h2><?php echo htmlspecialchars($tenantName); ?></h2>
<p>Tenant #<?php echo $tenantId; ?> • Member since <?php echo date("d M Y",strtotime($tenant['created_at'])); ?></p>
</div>

<span class="status status-<?php echo htmlspecialchars($tenant['account_status']); ?>">
<?php echo htmlspecialchars(ucfirst($tenant['account_status'])); ?>
</span>
</div>

<div class="content-grid">

<!-- Contact information -->
<div class="card">
<div class="card-header">
<h3>Contact Information</h3>
<p>Main tenant account details</p>
</div>

<div class="card-body">
<div class="info-row">
<span class="info-label">Email</span>
<span class="info-value"><?php echo htmlspecialchars($tenant['email']); ?></span>
</div>

<div class="info-row">
<span class="info-label">Phone</span>
<span class="info-value"><?php echo htmlspecialchars($tenant['phone']?:'Not provided'); ?></span>
</div>

<div class="info-row">
<span class="info-label">Address</span>
<span class="info-value"><?php echo htmlspecialchars($tenant['address']?:'Not provided'); ?></span>
</div>
</div>
</div>

<!-- Current rental property -->
<div class="card">
<div class="card-header">
<h3>Current Rental</h3>
<p>Property currently assigned to this tenant</p>
</div>

<?php if($currentLease): ?>
<div class="card-body" style="padding-top:18px">

<div class="property-name">
<?php echo htmlspecialchars($currentLease['property_code']); ?>
</div>

<div class="property-address">
<?php echo htmlspecialchars(
$currentLease['address_line1'].', '.
$currentLease['suburb'].', '.
$currentLease['state'].' '.
$currentLease['postcode']
); ?>
</div>

<div class="property-features">
<div class="feature">
<strong><?php echo (int)$currentLease['bedrooms']; ?></strong>
<span>Bedrooms</span>
</div>

<div class="feature">
<strong><?php echo htmlspecialchars($currentLease['bathrooms']); ?></strong>
<span>Bathrooms</span>
</div>

<div class="feature">
<strong><?php echo (int)$currentLease['parking_spaces']; ?></strong>
<span>Parking</span>
</div>
</div>

</div>
<?php else: ?>
<div class="no-rental">
No current property is assigned to this tenant.
</div>
<?php endif; ?>
</div>

</div>

<?php if($currentLease): ?>

<div class="content-grid">

<!-- Current lease information -->
<div class="card">
<div class="card-header">
<h3>Current Lease</h3>
<p>Active rental agreement</p>
</div>

<div class="card-body">
<div class="info-row">
<span class="info-label">Status</span>
<span class="info-value"><?php echo htmlspecialchars(ucwords(str_replace('_',' ',$currentLease['lease_status']))); ?></span>
</div>

<div class="info-row">
<span class="info-label">Start Date</span>
<span class="info-value"><?php echo date("d M Y",strtotime($currentLease['start_date'])); ?></span>
</div>

<div class="info-row">
<span class="info-label">End Date</span>
<span class="info-value"><?php echo date("d M Y",strtotime($currentLease['end_date'])); ?></span>
</div>
</div>
</div>

<!-- Rental finance information -->
<div class="card">
<div class="card-header">
<h3>Rental Finance</h3>
<p>Financial terms of the current agreement</p>
</div>

<div class="card-body">
<div class="info-row">
<span class="info-label">Monthly Rent</span>
<span class="info-value">$<?php echo number_format((float)$currentLease['monthly_rent'],2); ?></span>
</div>

<div class="info-row">
<span class="info-label">Bond Amount</span>
<span class="info-value">$<?php echo number_format((float)$currentLease['bond_amount'],2); ?></span>
</div>

<div class="info-row">
<span class="info-label">Agreement</span>
<span class="info-value"><?php echo !empty($currentLease['lease_document'])?'PDF attached':'Not uploaded'; ?></span>
</div>
</div>
</div>

</div>

<?php endif; ?>

<!-- Footer -->
<footer class="footer">
<strong>© 2026 RentEase Property Management System</strong>
&nbsp; • &nbsp;
Renting Made Easy.
</footer>

</div>
</body>
</html>