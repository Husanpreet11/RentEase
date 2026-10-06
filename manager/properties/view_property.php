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
$propertyId=isset($_GET['id'])?(int)$_GET['id']:0;

if($propertyId<=0){
header("Location: properties.php");
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
$stmt->bind_param("i",$userId);
$stmt->execute();
$manager=$stmt->get_result()->fetch_assoc();

$firstName=$manager['first_name']??'Manager';
$lastName=$manager['last_name']??'';
$fullName=trim($firstName.' '.$lastName);
$profileLetter=strtoupper(substr($firstName,0,1));
$jobTitle=!empty($manager['job_title'])?$manager['job_title']:'Property Manager';

// Property details
$stmt=$conn->prepare("
SELECT *
FROM properties
WHERE property_id=?
LIMIT 1
");
$stmt->bind_param("i",$propertyId);
$stmt->execute();
$property=$stmt->get_result()->fetch_assoc();

if(!$property){
die("Property not found.");
}

// Property photos
$stmt=$conn->prepare("
SELECT *
FROM property_photos
WHERE property_id=?
ORDER BY is_primary DESC,photo_id ASC
");
$stmt->bind_param("i",$propertyId);
$stmt->execute();
$photoResult=$stmt->get_result();

$photoList=[];
$primaryPhoto=null;
$otherPhotos=[];

while($photo=$photoResult->fetch_assoc()){
$photoList[]=$photo;
if((int)$photo['is_primary']===1 && $primaryPhoto===null){
$primaryPhoto=$photo;
}else{
$otherPhotos[]=$photo;
}
}

// Use first photo if no primary photo is selected
if($primaryPhoto===null && !empty($photoList)){
$primaryPhoto=$photoList[0];
$otherPhotos=array_slice($photoList,1);
}

// Current tenant and active lease
$stmt=$conn->prepare("
SELECT
l.lease_id,
l.start_date,
l.end_date,
l.monthly_rent,
l.bond_amount,
l.lease_status,
u.user_id AS tenant_id,
u.first_name,
u.last_name,
u.email,
u.phone
FROM leases l
INNER JOIN users u ON l.tenant_id=u.user_id
WHERE l.property_id=?
AND l.lease_status IN ('active','renewal_due')
ORDER BY l.start_date DESC
LIMIT 1
");
$stmt->bind_param("i",$propertyId);
$stmt->execute();
$currentLease=$stmt->get_result()->fetch_assoc();

$status=$property['property_status']??'vacant';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Property Details | RentEase</title>

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

/* Main page */
.main{margin-left:255px;min-height:100vh;padding:30px 36px 0}
.topbar{display:flex;align-items:center;justify-content:space-between;gap:20px;margin-bottom:20px}
.page-title h1{font-size:32px;font-weight:650;margin-bottom:5px}
.page-title p{font-size:16px;color:#687976}

/* Manager profile */
.top-profile{display:flex;align-items:center;gap:11px;min-width:215px;padding:10px 14px;background:#fff;border:1px solid #dce5e2;border-radius:12px;color:#243331;text-decoration:none}
.top-profile:hover{border-color:#9fc8c2;box-shadow:0 4px 14px rgba(47,129,120,.08)}
.profile-icon{display:flex;align-items:center;justify-content:center;width:42px;height:42px;background:#2f8178;border-radius:50%;color:#fff;font-size:16px;font-weight:700}
.profile-info{display:flex;flex-direction:column}
.profile-info strong{font-size:15px;font-weight:600}
.profile-info span{margin-top:2px;color:#71817e;font-size:12px}

/* Top actions */
.action-row{display:flex;align-items:center;justify-content:space-between;gap:15px;margin-bottom:18px}
.back-link{color:#2f8178;font-size:14px;font-weight:600;text-decoration:none}
.back-link:hover{color:#286f68}
.top-actions{display:flex;gap:9px}
.button{display:inline-flex;align-items:center;justify-content:center;height:40px;padding:0 16px;border-radius:8px;font-size:14px;font-weight:600;text-decoration:none}
.secondary-button{background:#fff;border:1px solid #ccd9d6;color:#40514e}
.secondary-button:hover{background:#f1f6f4;border-color:#aac9c4}
.primary-button{background:#2f8178;border:1px solid #2f8178;color:#fff}
.primary-button:hover{background:#286f68}

/* Equal top row */
.top-property-row{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;align-items:stretch}
.top-property-row>.section{height:100%}

/* Cards */
.section{display:flex;flex-direction:column;background:#fff;border:1px solid #dce5e2;border-radius:14px;overflow:hidden;box-shadow:0 3px 12px rgba(35,70,66,.04)}
.section-header{padding:17px 20px;border-bottom:1px solid #e3ebe9;background:#fbfdfc}
.section-header h3{font-size:20px;font-weight:650;color:#243331;margin-bottom:3px}
.section-header p{font-size:13px;color:#82918e}
.section-body{flex:1;padding:8px 20px 15px}

/* Main property image */
.property-image-card{position:relative;min-height:450px;background:#edf3f1}
.property-image-card>img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;object-position:center}
.no-main-photo{display:flex;align-items:center;justify-content:center;flex-direction:column;width:100%;height:100%;min-height:450px;color:#82918e;background:#edf3f1}
.no-main-photo span{font-size:42px;margin-bottom:8px;color:#9db4b0}

/* Status */
.status{position:absolute;top:18px;left:18px;padding:7px 13px;border-radius:20px;font-size:12px;font-weight:700;text-transform:capitalize;box-shadow:0 2px 8px rgba(0,0,0,.08);z-index:2}
.status-occupied{background:#e7f4ed;color:#2f6f54}
.status-vacant{background:#e8f3f1;color:#2f8178}
.status-maintenance{background:#fff3df;color:#936526}
.status-inactive{background:#edf0ef;color:#687976}

/* Address on image */
.photo-address{position:absolute;left:0;right:0;bottom:0;padding:60px 22px 20px;background:linear-gradient(to bottom,transparent,rgba(18,45,43,.88));color:#fff;z-index:2}
.photo-address strong{display:block;font-size:22px;font-weight:600;margin-bottom:3px}
.photo-address span{font-size:14px;color:#e1ecea}

/* Property information */
.property-info-card{min-height:450px}
.info-row{display:flex;align-items:center;justify-content:space-between;gap:20px;padding:13px 0;border-bottom:1px solid #edf1f0}
.info-row:last-child{border-bottom:0}
.info-label{font-size:14px;color:#71817e}
.info-value{max-width:65%;font-size:14px;font-weight:600;color:#40514e;text-align:right;overflow-wrap:anywhere}
.property-description{margin-top:12px;padding:13px;background:#f5f8f7;border-radius:8px}
.property-description strong{display:block;margin-bottom:4px;font-size:14px;color:#40514e}
.property-description p{font-size:13px;line-height:1.5;color:#687976}

/* Bottom balanced layout */
.details-bottom-row{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;align-items:stretch}

/* Photos fill full left height */
.photos-section{height:100%}
.photos-section .section-body{display:flex;flex-direction:column}
.photo-gallery{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;padding-top:10px}

/* Main gallery photo */
.gallery-primary{position:relative;grid-column:1/-1;width:100%;height:260px;border-radius:10px;overflow:hidden;background:#edf3f1}
.gallery-primary img{display:block;width:100%;height:100%;object-fit:cover;object-position:center}
.gallery-primary span{position:absolute;left:10px;bottom:10px;padding:5px 9px;border-radius:5px;background:rgba(24,59,58,.9);color:#fff;font-size:11px;font-weight:600}

/* Smaller gallery photos */
.small-property-photo{position:relative;width:100%;height:130px;border-radius:9px;overflow:hidden;background:#edf3f1}
.small-property-photo img{display:block;width:100%;height:100%;object-fit:cover}
.photo-action{margin-top:auto;padding-top:13px;text-align:right}
.photo-action a{color:#2f8178;font-size:13px;font-weight:600;text-decoration:none}
.photo-action a:hover{color:#286f68}

/* Tenant and lease stacked */
.right-details-column{display:grid;grid-template-rows:1fr 1fr;gap:20px;height:100%}
.right-details-column>.section{height:100%;min-height:0}

/* Tenant */
.tenant-person{display:flex;align-items:center;gap:13px;padding:14px 0;border-bottom:1px solid #edf1f0}
.tenant-icon{display:flex;align-items:center;justify-content:center;width:45px;height:45px;flex-shrink:0;background:#e8f3f1;border-radius:50%;color:#2f8178;font-size:16px;font-weight:700}
.tenant-person strong{display:block;margin-bottom:2px;color:#40514e;font-size:16px}
.tenant-person span{color:#82918e;font-size:12px}

/* Empty state */
.no-record{display:flex;flex:1;flex-direction:column;align-items:center;justify-content:center;min-height:160px;text-align:center}
.no-record strong{margin-bottom:5px;color:#40514e;font-size:16px}
.no-record p{max-width:300px;color:#82918e;font-size:13px;line-height:1.5}

/* Footer */
.footer{margin:30px -36px 0;padding:20px 30px;background:#183b3a;color:#c7d9d6;font-size:13px;text-align:center}
.footer strong{color:#fff}

/* Tablet */
@media(max-width:1000px){
.property-image-card,.property-info-card{min-height:420px}
}

/* Smaller screens */
@media(max-width:900px){
.top-property-row{grid-template-columns:1fr}
.details-bottom-row{grid-template-columns:1fr}
.property-image-card{height:390px;min-height:390px}
.property-info-card{min-height:auto}
.right-details-column{grid-template-rows:auto;height:auto}
}

/* Mobile */
@media(max-width:760px){
.sidebar{position:relative;width:100%;height:auto}
.main{margin-left:0;padding:22px}
.topbar{align-items:flex-start;flex-direction:column}
.action-row{align-items:flex-start;flex-direction:column}
.top-actions{width:100%}
.button{flex:1}
.property-image-card{height:300px;min-height:300px}
.no-main-photo{min-height:300px}
.photo-address{padding:45px 16px 15px}
.photo-address strong{font-size:18px}
.photo-gallery{grid-template-columns:1fr}
.gallery-primary{height:220px}
.small-property-photo{height:180px}
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
<a href="properties.php" class="active"><span class="menu-icon">▣</span>Properties</a>
<a href="../tenants/tenants.php"><span class="menu-icon">♙</span>Tenants</a>
<a href="../lease/leases.php"><span class="menu-icon">▤</span>Leases</a>
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
<h1>Property Details</h1>
<p>View property, photos, tenant and lease information.</p>
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

<a href="properties.php" class="back-link">← Back to Properties</a>

<div class="top-actions">
<a href="property_photos.php?id=<?php echo $propertyId; ?>" class="button secondary-button">Manage Photos</a>
<a href="edit_property.php?id=<?php echo $propertyId; ?>" class="button primary-button">Edit Property</a>
</div>

</div>

<!-- Main image and property information -->
<div class="top-property-row">

<div class="section property-image-card">

<?php if($primaryPhoto): ?>
<img src="../../<?php echo htmlspecialchars($primaryPhoto['file_path']); ?>" alt="Property">
<?php else: ?>
<div class="no-main-photo">
<span>▣</span>
No property photo available
</div>
<?php endif; ?>

<div class="status status-<?php echo htmlspecialchars($status); ?>">
<?php echo htmlspecialchars(ucfirst($status)); ?>
</div>

<div class="photo-address">
<strong><?php echo htmlspecialchars($property['address_line1']); ?></strong>
<span><?php echo htmlspecialchars($property['suburb'].', '.$property['state'].' '.$property['postcode']); ?></span>
</div>

</div>

<div class="section property-info-card">

<div class="section-header">
<h3>Property Information</h3>
<p>Key information about this property</p>
</div>

<div class="section-body">

<div class="info-row">
<span class="info-label">Property Code</span>
<span class="info-value"><?php echo htmlspecialchars($property['property_code']); ?></span>
</div>

<div class="info-row">
<span class="info-label">Address</span>
<span class="info-value"><?php echo htmlspecialchars($property['address_line1'].', '.$property['suburb'].', '.$property['state'].' '.$property['postcode']); ?></span>
</div>

<div class="info-row">
<span class="info-label">Status</span>
<span class="info-value"><?php echo htmlspecialchars(ucfirst($status)); ?></span>
</div>

<div class="info-row">
<span class="info-label">Bedrooms</span>
<span class="info-value"><?php echo htmlspecialchars($property['bedrooms']); ?></span>
</div>

<div class="info-row">
<span class="info-label">Bathrooms</span>
<span class="info-value"><?php echo htmlspecialchars($property['bathrooms']); ?></span>
</div>

<div class="info-row">
<span class="info-label">Parking</span>
<span class="info-value"><?php echo htmlspecialchars($property['parking_spaces']); ?></span>
</div>

<div class="info-row">
<span class="info-label">Weekly Rent</span>
<span class="info-value">$<?php echo number_format((float)$property['weekly_rent'],2); ?></span>
</div>

<?php if(!empty($property['description'])): ?>
<div class="property-description">
<strong>Description</strong>
<p><?php echo nl2br(htmlspecialchars($property['description'])); ?></p>
</div>
<?php endif; ?>

</div>
</div>

</div>

<!-- Photos on left, tenant and lease stacked on right -->
<div class="details-bottom-row">

<div class="section photos-section">

<div class="section-header">
<h3>Property Photos</h3>
<p><?php echo count($photoList); ?> photo<?php echo count($photoList)!==1?'s':''; ?> attached</p>
</div>

<div class="section-body">

<?php if($primaryPhoto): ?>

<div class="photo-gallery">

<div class="gallery-primary">
<img src="../../<?php echo htmlspecialchars($primaryPhoto['file_path']); ?>" alt="Primary property photo">
<span>Primary</span>
</div>

<?php foreach(array_slice($otherPhotos,0,4) as $photo): ?>
<div class="small-property-photo">
<img src="../../<?php echo htmlspecialchars($photo['file_path']); ?>" alt="Property photo">
</div>
<?php endforeach; ?>

</div>

<div class="photo-action">
<a href="property_photos.php?id=<?php echo $propertyId; ?>">Manage Property Photos →</a>
</div>

<?php else: ?>

<div class="no-record">
<strong>No Property Photos</strong>
<p>No photos have been uploaded for this property.</p>
</div>

<?php endif; ?>

</div>
</div>

<!-- Right side -->
<div class="right-details-column">

<!-- Current Tenant -->
<div class="section">

<div class="section-header">
<h3>Current Tenant</h3>
<p>Tenant currently assigned to this property</p>
</div>

<div class="section-body">

<?php if($currentLease): ?>

<div class="tenant-person">

<div class="tenant-icon">
<?php echo htmlspecialchars(strtoupper(substr($currentLease['first_name'],0,1))); ?>
</div>

<div>
<strong><?php echo htmlspecialchars($currentLease['first_name'].' '.$currentLease['last_name']); ?></strong>
<span>Current Tenant</span>
</div>

</div>

<div class="info-row">
<span class="info-label">Email</span>
<span class="info-value"><?php echo htmlspecialchars($currentLease['email']); ?></span>
</div>

<div class="info-row">
<span class="info-label">Phone</span>
<span class="info-value"><?php echo htmlspecialchars($currentLease['phone']??'Not provided'); ?></span>
</div>

<div class="info-row">
<span class="info-label">Lease Status</span>
<span class="info-value"><?php echo htmlspecialchars(ucwords(str_replace('_',' ',$currentLease['lease_status']))); ?></span>
</div>

<?php else: ?>

<div class="no-record">
<strong>No Current Tenant</strong>
<p>This property does not currently have an active tenant.</p>
</div>

<?php endif; ?>

</div>
</div>

<!-- Current Lease -->
<div class="section">

<div class="section-header">
<h3>Current Lease</h3>
<p>Current rental agreement</p>
</div>

<div class="section-body">

<?php if($currentLease): ?>

<div class="info-row">
<span class="info-label">Start Date</span>
<span class="info-value"><?php echo date("d M Y",strtotime($currentLease['start_date'])); ?></span>
</div>

<div class="info-row">
<span class="info-label">End Date</span>
<span class="info-value"><?php echo date("d M Y",strtotime($currentLease['end_date'])); ?></span>
</div>

<div class="info-row">
<span class="info-label">Monthly Rent</span>
<span class="info-value">$<?php echo number_format((float)$currentLease['monthly_rent'],2); ?></span>
</div>

<div class="info-row">
<span class="info-label">Bond Amount</span>
<span class="info-value">$<?php echo number_format((float)$currentLease['bond_amount'],2); ?></span>
</div>

<?php else: ?>

<div class="no-record">
<strong>No Active Lease</strong>
<p>Lease information will appear when a tenant is assigned.</p>
</div>

<?php endif; ?>

</div>
</div>

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