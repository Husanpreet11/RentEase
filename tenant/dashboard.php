<?php
session_start();
require_once __DIR__.'/../config/db.php';

// Tenant only
if(!isset($_SESSION['user_id'])){
header("Location: ../index.php");
exit();
}

if(($_SESSION['role']??'')!=='tenant'){
header("Location: ../manager/dashboard.php");
exit();
}

$tenantId=(int)$_SESSION['user_id'];

// Get tenant details
$stmt=$conn->prepare("
SELECT first_name,last_name,email,phone,address
FROM users
WHERE user_id=? AND role='tenant'
LIMIT 1
");

if(!$stmt){
die("Tenant query error: ".$conn->error);
}

$stmt->bind_param("i",$tenantId);
$stmt->execute();
$tenant=$stmt->get_result()->fetch_assoc();

if(!$tenant){
session_destroy();
header("Location: ../index.php");
exit();
}

$tenantName=trim($tenant['first_name'].' '.$tenant['last_name']);
$tenantLetter=strtoupper(substr($tenant['first_name'],0,1));

// Get current lease and property
$stmt=$conn->prepare("
SELECT
l.lease_id,
l.start_date,
l.end_date,
l.monthly_rent,
l.lease_status,
p.property_id,
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
CASE WHEN l.lease_status='active' THEN 1 ELSE 2 END,
l.end_date DESC
LIMIT 1
");

if(!$stmt){
die("Lease query error: ".$conn->error);
}

$stmt->bind_param("i",$tenantId);
$stmt->execute();
$lease=$stmt->get_result()->fetch_assoc();

// Get property image
$propertyImage='';

if($lease){
$stmt=$conn->prepare("
SELECT file_path
FROM property_photos
WHERE property_id=?
ORDER BY is_primary DESC
LIMIT 1
");

if(!$stmt){
die("Property image query error: ".$conn->error);
}

$propertyId=(int)$lease['property_id'];
$stmt->bind_param("i",$propertyId);
$stmt->execute();
$photo=$stmt->get_result()->fetch_assoc();

if($photo&&!empty($photo['file_path'])){
$photoPath=str_replace('\\','/',$photo['file_path']);
$physicalPath=__DIR__.'/../'.$photoPath;

if(file_exists($physicalPath)){
$propertyImage='../'.$photoPath;
}
}

// Husan property fallback
if(!$propertyImage&&$lease['property_code']==='RE-P101'){
$fallback='uploads/properties/tenant1.jpg';

if(file_exists(__DIR__.'/../'.$fallback)){
$propertyImage='../'.$fallback;
}
}
}

// Count active maintenance
$stmt=$conn->prepare("
SELECT COUNT(*) AS total
FROM maintenance_requests
WHERE tenant_id=?
AND status NOT IN ('completed','cancelled')
");

if(!$stmt){
die("Maintenance query error: ".$conn->error);
}

$stmt->bind_param("i",$tenantId);
$stmt->execute();
$activeMaintenance=(int)$stmt->get_result()->fetch_assoc()['total'];

// Count unread notifications
$stmt=$conn->prepare("
SELECT COUNT(*) AS total
FROM notifications
WHERE recipient_id=?
AND is_read=0
");

if(!$stmt){
die("Notification query error: ".$conn->error);
}

$stmt->bind_param("i",$tenantId);
$stmt->execute();
$unreadCommunication=(int)$stmt->get_result()->fetch_assoc()['total'];

// Calculate current month rent balance
$rentDue=0;
$rentDueLabel='No outstanding rent';

if($lease){
$stmt=$conn->prepare("
SELECT
rc.rent_charge_id,
rc.amount_due,
rc.due_date,
COALESCE((
SELECT SUM(rp.amount_paid)
FROM rent_payments rp
WHERE rp.rent_charge_id=rc.rent_charge_id
AND rp.tenant_id=?
AND rp.payment_status='paid'
),0) AS paid_amount
FROM rent_charges rc
WHERE rc.lease_id=?
AND YEAR(rc.due_date)=YEAR(CURDATE())
AND MONTH(rc.due_date)=MONTH(CURDATE())
");

if(!$stmt){
die("Rent query error: ".$conn->error);
}

$leaseId=(int)$lease['lease_id'];
$stmt->bind_param("ii",$tenantId,$leaseId);
$stmt->execute();
$rentResult=$stmt->get_result();

while($charge=$rentResult->fetch_assoc()){
$balance=max(0,(float)$charge['amount_due']-(float)$charge['paid_amount']);
$rentDue+=$balance;
}

if($rentDue>0){
$rentDueLabel='Outstanding this month';
}
}

// Recent maintenance
$stmt=$conn->prepare("
SELECT maintenance_id,title,priority,status,submitted_at
FROM maintenance_requests
WHERE tenant_id=?
ORDER BY submitted_at DESC
LIMIT 4
");

if(!$stmt){
die("Recent maintenance query error: ".$conn->error);
}

$stmt->bind_param("i",$tenantId);
$stmt->execute();
$recentMaintenance=$stmt->get_result();

// Recent notifications
$stmt=$conn->prepare("
SELECT
notification_id,
notification_type,
title,
message,
is_read,
sent_at
FROM notifications
WHERE recipient_id=?
ORDER BY sent_at DESC
LIMIT 4
");

if(!$stmt){
die("Recent notification query error: ".$conn->error);
}

$stmt->bind_param("i",$tenantId);
$stmt->execute();
$recentMessages=$stmt->get_result();

function niceText($value){
return ucwords(str_replace('_',' ',$value??''));
}

function shortDate($date){
if(!$date){
return '—';
}

return date('d M Y',strtotime($date));
}

function maintenanceStatusClass($status){
if($status==='completed'){
return 'green';
}

if($status==='cancelled'){
return 'red';
}

if($status==='in_progress'){
return 'teal';
}

if($status==='scheduled'){
return 'orange';
}

return 'neutral';
}

function priorityClass($priority){
if($priority==='urgent'||$priority==='high'){
return 'red';
}

if($priority==='medium'){
return 'orange';
}

return 'neutral';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Dashboard | RentEase</title>

<style>
*{box-sizing:border-box}
body{margin:0;background:#f4f7f6;color:#344441;font-family:"Segoe UI",Arial,sans-serif;font-size:16px}
.sidebar{position:fixed;top:0;left:0;width:255px;height:100vh;padding:28px 18px;background:#183b3a;overflow-y:auto;z-index:10}
.brand{padding:0 12px 24px;border-bottom:1px solid rgba(255,255,255,.12)}
.brand-name{color:#fff;font-size:28px;font-weight:800;letter-spacing:-.5px}
.brand-name span{color:#80c7ba}
.brand-tagline{margin-top:4px;color:#b8d0cc;font-size:13px}
.menu-title{margin:23px 12px 8px;color:#8fb2ac;font-size:11px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase}
.sidebar a{display:flex;align-items:center;gap:12px;margin-bottom:4px;padding:12px 13px;border-radius:9px;color:#d8e5e2;text-decoration:none;font-size:15px;font-weight:500;transition:.2s}
.sidebar a:hover{background:rgba(255,255,255,.08);color:#fff}
.sidebar a.active{background:#2f8178;color:#fff;font-weight:700}
.menu-icon{width:21px;text-align:center}
.menu-count{display:flex;align-items:center;justify-content:center;min-width:21px;height:21px;margin-left:auto;padding:0 6px;border-radius:20px;background:#80c7ba;color:#183b3a;font-size:11px;font-weight:700}
.logout-area{margin-top:25px;padding-top:15px;border-top:1px solid rgba(255,255,255,.12)}
.sidebar .logout{color:#f0c4c4}
.main-content{margin-left:255px;min-height:100vh;padding:35px 42px 25px}
.topbar{display:flex;justify-content:space-between;align-items:center;gap:20px;margin-bottom:26px}
.page-title h1{margin:0;color:#2d403d;font-size:32px;font-weight:700;letter-spacing:-.7px}
.page-title p{margin:7px 0 0;color:#71817e;font-size:15px}
.user-box{display:flex;align-items:center;gap:11px;padding:9px 15px;background:#fff;border:1px solid #dce5e2;border-radius:13px;color:#344441;text-decoration:none;box-shadow:0 3px 12px rgba(37,64,60,.04)}
.small-avatar{display:flex;align-items:center;justify-content:center;width:42px;height:42px;border-radius:50%;background:#2f8178;color:#fff;font-size:17px;font-weight:700}
.user-box strong{display:block;font-size:15px;font-weight:600}
.user-box span{display:block;margin-top:2px;color:#82918e;font-size:12px}

/* Main property */
.rental-card{display:grid;grid-template-columns:44% 56%;min-height:315px;margin-bottom:23px;background:#fff;border:1px solid #dce5e2;border-radius:18px;overflow:hidden;box-shadow:0 8px 28px rgba(37,64,60,.07)}
.property-photo{position:relative;min-height:315px;background:#e3ecea;overflow:hidden}
.property-photo img{display:block;width:100%;height:100%;min-height:315px;object-fit:cover;transition:transform .4s ease}
.rental-card:hover .property-photo img{transform:scale(1.025)}
.no-property-photo{display:flex;align-items:center;justify-content:center;width:100%;height:100%;min-height:315px;color:#71817e;background:#e7eeec;font-size:14px}
.rental-info{position:relative;display:flex;flex-direction:column;justify-content:center;padding:38px;background:#2f8178;color:#fff;overflow:hidden}
.rental-info:after{content:"⌂";position:absolute;right:-17px;bottom:-65px;color:rgba(255,255,255,.055);font-size:205px;font-weight:700}
.rental-heading{position:relative;z-index:1;display:flex;justify-content:space-between;align-items:flex-start;gap:20px}
.rental-label{margin-bottom:9px;color:#d5ebe7;font-size:11px;font-weight:700;letter-spacing:1px}
.rental-heading h2{margin:0;color:#fff;font-size:27px;font-weight:600;line-height:1.25}
.rental-heading p{margin:8px 0 0;color:#e3f1ee;font-size:15px}
.property-code{padding:7px 13px;background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.25);border-radius:20px;color:#fff;font-size:12px;font-weight:700;white-space:nowrap}
.rental-details{position:relative;z-index:1;display:grid;grid-template-columns:repeat(3,1fr);gap:18px;margin-top:38px}
.rental-detail{padding-top:15px;border-top:1px solid rgba(255,255,255,.22)}
.rental-detail span{display:block;margin-bottom:7px;color:#cce5e0;font-size:12px}
.rental-detail strong{color:#fff;font-size:16px;font-weight:600}

/* Summary */
.summary-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:18px;margin-bottom:23px}
.summary-card{min-height:148px;padding:22px;background:#fff;border:1px solid #dce5e2;border-radius:15px;box-shadow:0 5px 20px rgba(37,64,60,.045);transition:.2s}
.summary-card:hover{transform:translateY(-2px);box-shadow:0 9px 25px rgba(37,64,60,.075)}
.summary-icon{display:flex;align-items:center;justify-content:center;width:39px;height:39px;margin-bottom:15px;background:#e8f3f1;border-radius:10px;color:#2f8178;font-size:17px;font-weight:700}
.summary-label{color:#71817e;font-size:11px;font-weight:700;letter-spacing:.55px;text-transform:uppercase}
.summary-value{margin-top:8px;color:#40514e;font-size:22px;font-weight:600;line-height:1.25}
.summary-value.small{font-size:16px}
.summary-note{margin-top:6px;color:#82918e;font-size:12px;line-height:1.45}

/* Bottom cards */
.activity-grid{display:grid;grid-template-columns:1fr 1fr;gap:22px}
.card{background:#fff;border:1px solid #dce5e2;border-radius:15px;overflow:hidden;box-shadow:0 5px 20px rgba(37,64,60,.045)}
.card-heading{display:flex;justify-content:space-between;align-items:center;padding:20px 23px;border-bottom:1px solid #e7ecea}
.card-heading h2{margin:0;color:#344441;font-size:19px;font-weight:600}
.card-heading a{padding:7px 11px;background:#eef6f4;border-radius:7px;color:#2f8178;font-size:12px;font-weight:700;text-decoration:none}
.card-heading a:hover{background:#e0efec}
.activity-item{padding:17px 23px;border-bottom:1px solid #edf1f0}
.activity-item:last-child{border-bottom:none}
.activity-top{display:flex;justify-content:space-between;gap:15px;margin-bottom:7px}
.activity-title{color:#40514e;font-size:14px;font-weight:600}
.activity-date{color:#82918e;font-size:11px;white-space:nowrap}
.activity-text{color:#687976;font-size:13px;line-height:1.55}
.badges{display:flex;flex-wrap:wrap;gap:6px;margin-top:9px}
.badge{display:inline-block;padding:5px 9px;border-radius:20px;font-size:10px;font-weight:700}
.badge.teal,.badge.new{background:#e2f2ef;color:#286f68}
.badge.green{background:#e8f5ee;color:#287a55}
.badge.orange{background:#fff2d9;color:#96671e}
.badge.red{background:#fae7e7;color:#a84545}
.badge.neutral{background:#edf2f1;color:#60716e}
.empty{padding:42px 24px;color:#82918e;font-size:13px;text-align:center}
.footer{margin-top:32px;margin-left:-42px;margin-right:-42px;margin-bottom:-25px;padding:20px 25px;background:#2f8178;color:#fff;text-align:center;font-size:13px}
.footer strong{color:#fff;font-weight:700}

@media(max-width:1200px){
.summary-grid{grid-template-columns:repeat(2,1fr)}
}
@media(max-width:1000px){
.rental-card{grid-template-columns:1fr}
.property-photo,.property-photo img{height:300px}
.activity-grid{grid-template-columns:1fr}
}
@media(max-width:760px){
.sidebar{position:relative;width:100%;height:auto}
.main-content{margin-left:0;padding:25px 18px}
.user-box{display:none}
.rental-info{padding:27px 23px}
.rental-details{grid-template-columns:1fr}
.summary-grid{grid-template-columns:1fr}
.footer{margin-left:-18px;margin-right:-18px}
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

<a href="dashboard.php" class="active">
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

<a href="inspections.php">
<span class="menu-icon">◫</span>
Inspections
</a>

<a href="communication.php">
<span class="menu-icon">✉</span>
Communication

<?php if($unreadCommunication>0): ?>
<span class="menu-count">
<?php echo $unreadCommunication; ?>
</span>
<?php endif; ?>

</a>

<a href="privacy.php">
<span class="menu-icon">◆</span>
Privacy
</a>

<div class="logout-area">

<a href="../auth/logout.php" class="logout">
<span class="menu-icon">↪</span>
Logout
</a>

</div>
</div>

<div class="main-content">

<div class="topbar">

<div class="page-title">
<h1>Welcome, <?php echo htmlspecialchars($tenant['first_name']); ?></h1>
<p>Here is an overview of your rental account.</p>
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

<?php if($lease): ?>

<div class="rental-card">

<div class="property-photo">

<?php if($propertyImage): ?>

<img
src="<?php echo htmlspecialchars($propertyImage); ?>"
alt="<?php echo htmlspecialchars($lease['address_line1']); ?>"
>

<?php else: ?>

<div class="no-property-photo">
No property image available
</div>

<?php endif; ?>

</div>

<div class="rental-info">

<div class="rental-heading">

<div>

<div class="rental-label">
YOUR CURRENT RENTAL
</div>

<h2>
<?php echo htmlspecialchars($lease['address_line1']); ?>
</h2>

<p>
<?php echo htmlspecialchars($lease['suburb'].', '.$lease['state'].' '.$lease['postcode']); ?>
</p>

</div>

<div class="property-code">
<?php echo htmlspecialchars($lease['property_code']); ?>
</div>

</div>

<div class="rental-details">

<div class="rental-detail">
<span>Monthly Rent</span>
<strong>
$<?php echo number_format((float)$lease['monthly_rent'],2); ?>
</strong>
</div>

<div class="rental-detail">
<span>Lease Ends</span>
<strong>
<?php echo shortDate($lease['end_date']); ?>
</strong>
</div>

<div class="rental-detail">
<span>Lease Status</span>
<strong>
<?php echo htmlspecialchars(niceText($lease['lease_status'])); ?>
</strong>
</div>

</div>
</div>
</div>

<?php else: ?>

<div class="card" style="margin-bottom:23px">

<div class="empty">
No active rental property was found for your account.
</div>

</div>

<?php endif; ?>

<div class="summary-grid">

<div class="summary-card">

<div class="summary-icon">$</div>

<div class="summary-label">
Rent Due This Month
</div>

<div class="summary-value">
$<?php echo number_format($rentDue,2); ?>
</div>

<div class="summary-note">
<?php echo htmlspecialchars($rentDueLabel); ?>
</div>

</div>

<div class="summary-card">

<div class="summary-icon">⚙</div>

<div class="summary-label">
Active Maintenance
</div>

<div class="summary-value">
<?php echo $activeMaintenance; ?>
</div>

<div class="summary-note">
Open maintenance request<?php echo $activeMaintenance===1?'':'s'; ?>
</div>

</div>

<div class="summary-card">

<div class="summary-icon">✉</div>

<div class="summary-label">
Unread Communication
</div>

<div class="summary-value">
<?php echo $unreadCommunication; ?>
</div>

<div class="summary-note">
Unread notification<?php echo $unreadCommunication===1?'':'s'; ?>
</div>

</div>

<div class="summary-card">

<div class="summary-icon">▤</div>

<div class="summary-label">
Lease Expiry
</div>

<div class="summary-value small">
<?php echo $lease?shortDate($lease['end_date']):'No active lease'; ?>
</div>

<?php if($lease): ?>

<div class="summary-note">
<?php echo htmlspecialchars(niceText($lease['lease_status'])); ?> lease
</div>

<?php endif; ?>

</div>

</div>

<div class="activity-grid">

<div class="card">

<div class="card-heading">

<h2>Recent Maintenance</h2>

<a href="maintenance.php">
View All
</a>

</div>

<?php if($recentMaintenance->num_rows>0): ?>

<?php while($item=$recentMaintenance->fetch_assoc()): ?>

<div class="activity-item">

<div class="activity-top">

<div class="activity-title">
<?php echo htmlspecialchars($item['title']); ?>
</div>

<div class="activity-date">
<?php echo shortDate($item['submitted_at']); ?>
</div>

</div>

<div class="badges">

<span class="badge <?php echo maintenanceStatusClass($item['status']); ?>">
<?php echo htmlspecialchars(niceText($item['status'])); ?>
</span>

<span class="badge <?php echo priorityClass($item['priority']); ?>">
<?php echo htmlspecialchars(niceText($item['priority'])); ?> Priority
</span>

</div>

</div>

<?php endwhile; ?>

<?php else: ?>

<div class="empty">
You have no maintenance requests yet.
</div>

<?php endif; ?>

</div>

<div class="card">

<div class="card-heading">

<h2>Recent Communication</h2>

<a href="communication.php">
View All
</a>

</div>

<?php if($recentMessages->num_rows>0): ?>

<?php while($item=$recentMessages->fetch_assoc()): ?>

<div class="activity-item">

<div class="activity-top">

<div class="activity-title">
<?php echo htmlspecialchars($item['title']); ?>
</div>

<div class="activity-date">
<?php echo shortDate($item['sent_at']); ?>
</div>

</div>

<div class="activity-text">

<?php
$preview=$item['message'];

if(strlen($preview)>120){
$preview=substr($preview,0,120).'...';
}

echo htmlspecialchars($preview);
?>

</div>

<div class="badges">

<span class="badge neutral">
<?php echo htmlspecialchars(niceText($item['notification_type'])); ?>
</span>

<?php if(!(int)$item['is_read']): ?>

<span class="badge new">
New
</span>

<?php endif; ?>

</div>

</div>

<?php endwhile; ?>

<?php else: ?>

<div class="empty">
No communication received yet.
</div>

<?php endif; ?>

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