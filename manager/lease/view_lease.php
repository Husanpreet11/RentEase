<?php
session_start();
require_once __DIR__.'/../../config/db.php';

if(!isset($_SESSION['user_id'])){
header("Location: ../../index.php");
exit();
}
if(($_SESSION['role']??'')!=='manager'){
header("Location: ../../tenant/dashboard.php");
exit();
}

$managerId=(int)$_SESSION['user_id'];
$leaseId=(int)($_GET['id']??0);

if($leaseId<=0){
header("Location: leases.php");
exit();
}

// Manager details
$stmt=$conn->prepare("
SELECT u.first_name,u.last_name,mp.job_title
FROM users u
LEFT JOIN manager_profiles mp ON u.user_id=mp.manager_id
WHERE u.user_id=?
LIMIT 1
");
$stmt->bind_param("i",$managerId);
$stmt->execute();
$manager=$stmt->get_result()->fetch_assoc();

$firstName=$manager['first_name']??'Manager';
$lastName=$manager['last_name']??'';
$fullName=trim($firstName.' '.$lastName);
$profileLetter=strtoupper(substr($firstName,0,1));
$jobTitle=!empty($manager['job_title'])?$manager['job_title']:'Property Manager';

// Lease details
$stmt=$conn->prepare("
SELECT
l.*,
u.first_name,
u.last_name,
u.email,
u.phone,
u.address AS tenant_address,
p.property_code,
p.address_line1,
p.suburb,
p.state,
p.postcode,
p.bedrooms,
p.bathrooms,
p.parking_spaces,
p.weekly_rent,
p.property_status
FROM leases l
INNER JOIN users u ON l.tenant_id=u.user_id
INNER JOIN properties p ON l.property_id=p.property_id
WHERE l.lease_id=?
LIMIT 1
");
$stmt->bind_param("i",$leaseId);
$stmt->execute();
$lease=$stmt->get_result()->fetch_assoc();

if(!$lease){
header("Location: leases.php");
exit();
}

$tenantName=trim($lease['first_name'].' '.$lease['last_name']);
$status=$lease['lease_status'];

$success='';

if(isset($_SESSION['lease_flash'])){
$success=$_SESSION['lease_flash'];
unset($_SESSION['lease_flash']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Lease Details | RentEase</title>

<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:"Segoe UI",Arial,sans-serif;background:#f5f7f6;color:#243331;font-size:16px}
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
.main{margin-left:255px;min-height:100vh;padding:30px 36px 0}
.topbar{display:flex;align-items:center;justify-content:space-between;gap:20px;margin-bottom:20px}
.page-title h1{font-size:32px;font-weight:650;margin-bottom:5px}
.page-title p{font-size:16px;color:#687976}
.top-profile{display:flex;align-items:center;gap:11px;min-width:215px;padding:10px 14px;background:#fff;border:1px solid #dce5e2;border-radius:12px;color:#243331;text-decoration:none}
.profile-icon{display:flex;align-items:center;justify-content:center;width:42px;height:42px;background:#2f8178;border-radius:50%;color:#fff;font-size:16px;font-weight:700}
.profile-info{display:flex;flex-direction:column}
.profile-info strong{font-size:15px;font-weight:600}
.profile-info span{margin-top:2px;color:#71817e;font-size:12px}
.action-row{display:flex;align-items:center;justify-content:space-between;gap:15px;margin-bottom:18px}
.back-link{color:#2f8178;font-size:14px;font-weight:600;text-decoration:none}
.actions{display:flex;gap:9px}
.button{display:inline-flex;align-items:center;justify-content:center;height:40px;padding:0 16px;border-radius:8px;font-size:14px;font-weight:600;text-decoration:none}
.secondary-button{background:#fff;border:1px solid #ccd9d6;color:#40514e}
.primary-button{background:#2f8178;border:1px solid #2f8178;color:#fff}
.message{padding:12px 15px;margin-bottom:18px;border:1px solid #b9dcca;border-radius:9px;background:#edf8f1;color:#31684d;font-size:13px}
.lease-banner{display:flex;align-items:center;justify-content:space-between;gap:20px;padding:20px 22px;margin-bottom:20px;background:#fff;border:1px solid #dce5e2;border-radius:15px;box-shadow:0 3px 12px rgba(35,70,66,.04)}
.lease-banner h2{font-size:22px;font-weight:650;margin-bottom:4px}
.lease-banner p{color:#71817e;font-size:13px}
.status{padding:7px 12px;border-radius:20px;font-size:11px;font-weight:700;text-transform:capitalize}
.status-active{background:#e7f4ed;color:#2f6f54}
.status-renewal_due{background:#fff3df;color:#936526}
.status-expired{background:#f2eeee;color:#7b5555}
.status-terminated{background:#f7e8e8;color:#934a4a}
.summary-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:15px;margin-bottom:20px}
.summary-card{background:#fff;border:1px solid #dce5e2;border-radius:13px;padding:17px 18px}
.summary-card span{display:block;margin-bottom:6px;color:#71817e;font-size:11px}
.summary-card strong{display:block;color:#40514e;font-size:17px;font-weight:600}
.content-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;align-items:stretch}
.card{display:flex;flex-direction:column;background:#fff;border:1px solid #dce5e2;border-radius:15px;overflow:hidden;box-shadow:0 3px 12px rgba(35,70,66,.04)}
.card-header{padding:17px 20px;border-bottom:1px solid #e3ebe9;background:#fbfdfc}
.card-header h3{font-size:19px;font-weight:650;margin-bottom:3px}
.card-header p{font-size:12px;color:#82918e}
.card-body{flex:1;padding:8px 20px 15px}
.info-row{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;padding:13px 0;border-bottom:1px solid #edf1f0}
.info-row:last-child{border-bottom:0}
.info-label{color:#71817e;font-size:13px}
.info-value{max-width:65%;color:#40514e;font-size:13px;font-weight:600;text-align:right;overflow-wrap:anywhere}
.notes-card{margin-bottom:20px}
.notes{padding:18px 20px;color:#40514e;font-size:14px;line-height:1.6;white-space:pre-line}
.document-row{display:flex;align-items:center;justify-content:space-between;gap:20px;padding:18px 20px}
.document-info strong{display:block;margin-bottom:3px;color:#40514e;font-size:14px}
.document-info span{color:#82918e;font-size:12px}
.document-button{padding:9px 14px;background:#eef6f4;border:1px solid #b9d9d4;border-radius:8px;color:#2f8178;font-size:13px;font-weight:600;text-decoration:none}
.no-document{color:#82918e;font-size:13px}
.footer{margin:30px -36px 0;padding:20px 30px;background:#183b3a;color:#c7d9d6;font-size:13px;text-align:center}
.footer strong{color:#fff}
@media(max-width:900px){
.summary-grid{grid-template-columns:repeat(2,1fr)}
.content-grid{grid-template-columns:1fr}
}
@media(max-width:760px){
.sidebar{position:relative;width:100%;height:auto}
.main{margin-left:0;padding:22px}
.topbar,.action-row{align-items:flex-start;flex-direction:column}
.summary-grid{grid-template-columns:1fr}
.footer{margin-left:-22px;margin-right:-22px}
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
<h1>Lease Details</h1>
<p>Review the tenant, property and rental agreement.</p>
</div>

<a href="../profile.php" class="top-profile">
<div class="profile-icon"><?php echo htmlspecialchars($profileLetter); ?></div>
<div class="profile-info">
<strong><?php echo htmlspecialchars($fullName); ?></strong>
<span><?php echo htmlspecialchars($jobTitle); ?></span>
</div>
</a>
</div>

<div class="action-row">
<a href="leases.php" class="back-link">← Back to Leases</a>

<div class="actions">
<a href="edit_lease.php?id=<?php echo $leaseId; ?>" class="button primary-button">Edit Lease</a>
</div>
</div>

<?php if($success!==''): ?>
<div class="message"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>

<div class="lease-banner">
<div>
<h2>Lease #<?php echo $leaseId; ?></h2>
<p><?php echo htmlspecialchars($tenantName.' • '.$lease['property_code'].' • '.$lease['address_line1']); ?></p>
</div>

<span class="status status-<?php echo htmlspecialchars($status); ?>">
<?php echo htmlspecialchars(ucwords(str_replace('_',' ',$status))); ?>
</span>
</div>

<div class="summary-grid">

<div class="summary-card">
<span>Start Date</span>
<strong><?php echo date("d M Y",strtotime($lease['start_date'])); ?></strong>
</div>

<div class="summary-card">
<span>End Date</span>
<strong><?php echo date("d M Y",strtotime($lease['end_date'])); ?></strong>
</div>

<div class="summary-card">
<span>Monthly Rent</span>
<strong>$<?php echo number_format((float)$lease['monthly_rent'],2); ?></strong>
</div>

<div class="summary-card">
<span>Bond Amount</span>
<strong>$<?php echo number_format((float)$lease['bond_amount'],2); ?></strong>
</div>

</div>

<div class="content-grid">

<div class="card">
<div class="card-header">
<h3>Tenant Information</h3>
<p>Tenant connected to this agreement</p>
</div>

<div class="card-body">

<div class="info-row">
<span class="info-label">Tenant</span>
<span class="info-value"><?php echo htmlspecialchars($tenantName); ?></span>
</div>

<div class="info-row">
<span class="info-label">Email</span>
<span class="info-value"><?php echo htmlspecialchars($lease['email']); ?></span>
</div>

<div class="info-row">
<span class="info-label">Phone</span>
<span class="info-value"><?php echo htmlspecialchars($lease['phone']?:'Not provided'); ?></span>
</div>

<div class="info-row">
<span class="info-label">Address</span>
<span class="info-value"><?php echo htmlspecialchars($lease['tenant_address']?:'Not provided'); ?></span>
</div>

</div>
</div>

<div class="card">
<div class="card-header">
<h3>Property Information</h3>
<p>Property covered by this agreement</p>
</div>

<div class="card-body">

<div class="info-row">
<span class="info-label">Property</span>
<span class="info-value"><?php echo htmlspecialchars($lease['property_code']); ?></span>
</div>

<div class="info-row">
<span class="info-label">Address</span>
<span class="info-value"><?php echo htmlspecialchars($lease['address_line1'].', '.$lease['suburb'].', '.$lease['state'].' '.$lease['postcode']); ?></span>
</div>

<div class="info-row">
<span class="info-label">Bedrooms</span>
<span class="info-value"><?php echo htmlspecialchars($lease['bedrooms']); ?></span>
</div>

<div class="info-row">
<span class="info-label">Bathrooms</span>
<span class="info-value"><?php echo htmlspecialchars($lease['bathrooms']); ?></span>
</div>

<div class="info-row">
<span class="info-label">Parking</span>
<span class="info-value"><?php echo htmlspecialchars($lease['parking_spaces']); ?></span>
</div>

</div>
</div>

</div>

<div class="card notes-card">
<div class="card-header">
<h3>Lease Agreement</h3>
<p>Signed or final agreement document</p>
</div>

<?php if(!empty($lease['lease_document'])): ?>

<div class="document-row">
<div class="document-info">
<strong>Lease Agreement PDF</strong>
<span>Document attached to this lease</span>
</div>

<a href="../../view_lease_document.php?lease_id=<?php echo (int)$leaseId; ?>" target="_blank" class="document-button">
View Agreement
</a>
</div>

<?php else: ?>

<div class="document-row">
<span class="no-document">No lease agreement has been uploaded yet.</span>
</div>

<?php endif; ?>

</div>

<?php if(!empty(trim((string)$lease['notes']))): ?>

<div class="card notes-card">
<div class="card-header">
<h3>Lease Notes</h3>
<p>Additional information recorded for this agreement</p>
</div>

<div class="notes"><?php echo htmlspecialchars($lease['notes']); ?></div>
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