<?php
session_start();
require_once __DIR__.'/../config/db.php';

// Manager access only
if(!isset($_SESSION['user_id'])){
header("Location: ../index.php");
exit();
}

if(($_SESSION['role']??'')!=='manager'){
header("Location: ../tenant/dashboard.php");
exit();
}

$managerId=(int)$_SESSION['user_id'];

// Simple count helper
function getCount($conn,$sql){
$result=$conn->query($sql);
if($result){
$row=$result->fetch_assoc();
return (int)($row['total']??0);
}
return 0;
}

// Manager details
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
header("Location: ../index.php");
exit();
}

$managerName=trim($manager['first_name'].' '.$manager['last_name']);
$firstName=$manager['first_name'];
$jobTitle=!empty($manager['job_title'])?$manager['job_title']:'Property Manager';
$profileLetter=strtoupper(substr($firstName,0,1));

// Total properties
$totalProperties=getCount($conn,"
SELECT COUNT(*) AS total
FROM properties
");

// Active tenants
$activeTenants=getCount($conn,"
SELECT COUNT(*) AS total
FROM users
WHERE role='tenant'
AND account_status='active'
");

// Occupied properties
$occupiedProperties=getCount($conn,"
SELECT COUNT(DISTINCT property_id) AS total
FROM leases
WHERE lease_status='active'
");

// Vacant properties
$vacantProperties=max(0,$totalProperties-$occupiedProperties);

// Open maintenance
$openMaintenance=getCount($conn,"
SELECT COUNT(*) AS total
FROM maintenance_requests
WHERE status NOT IN ('completed','cancelled')
");

// Upcoming inspections
$upcomingInspections=getCount($conn,"
SELECT COUNT(*) AS total
FROM inspections
WHERE status IN ('scheduled','rescheduled')
AND scheduled_at>=NOW()
");

// Expiring leases
$expiringLeases=getCount($conn,"
SELECT COUNT(*) AS total
FROM leases
WHERE lease_status='active'
AND end_date BETWEEN CURDATE()
AND DATE_ADD(CURDATE(),INTERVAL 60 DAY)
");

// Overdue rent using actual paid balance
$overdueRent=getCount($conn,"
SELECT COUNT(*) AS total
FROM rent_charges rc
LEFT JOIN(
SELECT rent_charge_id,SUM(amount_paid) AS paid
FROM rent_payments
WHERE payment_status='paid'
GROUP BY rent_charge_id
) rp ON rp.rent_charge_id=rc.rent_charge_id
WHERE rc.due_date<CURDATE()
AND (rc.amount_due-COALESCE(rp.paid,0))>0
");

// Occupancy percentages
$occupancyRate=$totalProperties>0
?round(($occupiedProperties/$totalProperties)*100)
:0;

$vacancyRate=$totalProperties>0
?round(($vacantProperties/$totalProperties)*100)
:0;

// Needs attention total
$attentionTotal=
$openMaintenance+
$overdueRent+
$expiringLeases+
$upcomingInspections;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Manager Dashboard | RentEase</title>

<style>
*{
margin:0;
padding:0;
box-sizing:border-box;
}

body{
font-family:"Segoe UI",Arial,sans-serif;
background:#f5f7f6;
color:#4f5d5b;
font-size:16px;
}

/* SIDEBAR */

.sidebar{
position:fixed;
top:0;
left:0;
width:255px;
height:100vh;
padding:28px 18px;
background:#183b3a;
overflow-y:auto;
z-index:10;
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

.logout-area{
margin-top:25px;
padding-top:15px;
border-top:1px solid rgba(255,255,255,.12);
}

.sidebar .logout{
color:#f0c4c4;
}

/* MAIN */

.main{
margin-left:255px;
min-height:100vh;
}

.content{
padding:32px 38px 0;
}

/* TOP BAR */

.topbar{
display:flex;
align-items:center;
justify-content:space-between;
gap:20px;
margin-bottom:24px;
}

.page-title h1{
margin:0;
color:#344441;
font-size:32px;
font-weight:700;
letter-spacing:-.5px;
}

.page-title p{
margin-top:6px;
color:#71817e;
font-size:15px;
}

.top-profile{
display:flex;
align-items:center;
gap:11px;
padding:9px 14px;
background:#fff;
border:1px solid #dce5e2;
border-radius:12px;
color:#40514e;
text-decoration:none;
transition:.2s;
}

.top-profile:hover{
border-color:#a9cdc7;
box-shadow:0 5px 16px rgba(47,129,120,.08);
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
color:#40514e;
font-size:14px;
font-weight:600;
}

.profile-info span{
display:block;
margin-top:2px;
color:#71817e;
font-size:12px;
}

/* LARGE TEAL BRAND AREA */

.hero{
position:relative;
overflow:hidden;
margin-bottom:22px;
min-height:185px;
padding:30px 36px;
background:linear-gradient(120deg,#185854,#246f68 48%,#399187);
border-radius:18px;
box-shadow:0 10px 28px rgba(36,111,104,.17);
color:#fff;
}

.hero:before{
content:"";
position:absolute;
width:320px;
height:320px;
right:-90px;
top:-190px;
border-radius:50%;
background:rgba(255,255,255,.07);
}

.hero:after{
content:"";
position:absolute;
width:220px;
height:220px;
right:150px;
bottom:-175px;
border-radius:50%;
background:rgba(255,255,255,.05);
}

.hero-content{
position:relative;
z-index:2;
display:flex;
align-items:center;
gap:38px;
min-height:125px;
}

/* LOGO INSIDE BIG GREEN RECTANGLE */

.hero-logo-box{
display:flex;
align-items:center;
justify-content:center;
width:200px;
min-width:200px;
height:125px;
padding:10px;
background:#fff;
border-radius:15px;
box-shadow:0 7px 20px rgba(0,0,0,.12);
}

.hero-logo{
display:block;
max-width:100%;
max-height:100%;
object-fit:contain;
}

.hero-divider{
width:1px;
height:92px;
background:rgba(255,255,255,.28);
flex-shrink:0;
}

.hero-text{
flex:1;
}

.hero-text h2{
margin:0;
color:#fff;
font-size:29px;
font-weight:700;
letter-spacing:-.3px;
}

.hero-text p{
max-width:700px;
margin:10px 0 0;
color:#e0f0ed;
font-size:14px;
line-height:1.65;
}

/* TOP THREE CARDS */

.stats-grid{
display:grid;
grid-template-columns:repeat(3,1fr);
gap:18px;
margin-bottom:22px;
}

.stat-card{
display:block;
min-height:145px;
padding:21px;
background:#fff;
border:1px solid #dce5e2;
border-radius:15px;
color:inherit;
text-decoration:none;
box-shadow:0 5px 18px rgba(35,70,66,.05);
transition:.2s;
}

.stat-card:hover{
transform:translateY(-3px);
border-color:#b9d7d2;
box-shadow:0 9px 23px rgba(35,70,66,.09);
}

.stat-card.primary{
background:#eef7f5;
border-color:#d2e6e2;
}

.stat-top{
display:flex;
align-items:center;
justify-content:space-between;
gap:12px;
}

.stat-label{
color:#5f706d;
font-size:12px;
font-weight:600;
text-transform:uppercase;
letter-spacing:.4px;
}

.stat-icon{
display:flex;
align-items:center;
justify-content:center;
width:46px;
height:46px;
border-radius:12px;
background:#e8f3f1;
color:#2f8178;
font-size:18px;
}

.stat-card.primary .stat-icon{
background:#2f8178;
color:#fff;
}

.stat-number{
margin-top:17px;
color:#40514e;
font-size:30px;
font-weight:600;
line-height:1;
}

.stat-note{
margin-top:8px;
color:#71817e;
font-size:12px;
line-height:1.45;
}

/* MAIN DASHBOARD GRID */

.dashboard-grid{
display:grid;
grid-template-columns:1.25fr .75fr;
gap:20px;
margin-bottom:22px;
}

.card{
background:#fff;
border:1px solid #dce5e2;
border-radius:15px;
overflow:hidden;
box-shadow:0 5px 18px rgba(35,70,66,.05);
}

.card-header{
display:flex;
align-items:center;
justify-content:space-between;
gap:15px;
padding:20px 22px;
border-bottom:1px solid #e4ebe9;
}

.card-heading h2{
margin:0;
color:#344441;
font-size:20px;
font-weight:600;
}

.card-heading p{
margin-top:4px;
color:#71817e;
font-size:12px;
}

.card-badge{
padding:7px 11px;
background:#e8f3f1;
border-radius:20px;
color:#286f68;
font-size:11px;
font-weight:600;
}

.card-body{
padding:22px;
}

/* OCCUPANCY */

.occupancy-layout{
display:grid;
grid-template-columns:210px 1fr;
gap:35px;
align-items:center;
min-height:230px;
}

.donut-wrap{
position:relative;
display:flex;
align-items:center;
justify-content:center;
width:190px;
height:190px;
margin:auto;
}

.donut{
width:178px;
height:178px;
border-radius:50%;
background:conic-gradient(
#2f8178 0 <?php echo $occupancyRate; ?>%,
#dbe9e6 <?php echo $occupancyRate; ?>% 100%
);
display:flex;
align-items:center;
justify-content:center;
}

.donut:after{
content:"";
position:absolute;
width:125px;
height:125px;
border-radius:50%;
background:#fff;
}

.donut-center{
position:absolute;
z-index:2;
text-align:center;
}

.donut-center strong{
display:block;
color:#2f8178;
font-size:30px;
font-weight:600;
}

.donut-center span{
display:block;
margin-top:2px;
color:#71817e;
font-size:11px;
}

.occupancy-info{
display:grid;
grid-template-columns:1fr 1fr;
gap:13px;
}

.occupancy-box{
padding:16px;
background:#f6f9f8;
border:1px solid #e2ebe8;
border-radius:11px;
}

.occupancy-box.rate{
grid-column:1/-1;
background:#eaf5f2;
border-color:#d4e7e3;
}

.occupancy-label{
display:flex;
align-items:center;
gap:7px;
color:#71817e;
font-size:11px;
}

.dot{
width:8px;
height:8px;
border-radius:50%;
background:#2f8178;
}

.dot.light{
background:#b8d4cf;
}

.occupancy-value{
margin-top:8px;
color:#40514e;
font-size:23px;
font-weight:600;
}

.occupancy-box.rate .occupancy-value{
color:#2f8178;
}

.occupancy-small{
margin-top:3px;
color:#82918e;
font-size:10px;
}

/* NEEDS ATTENTION */

.attention-card .card-header{
background:#f8fbfa;
}

.attention-list{
display:flex;
flex-direction:column;
}

.attention-item{
display:flex;
align-items:center;
gap:13px;
padding:14px 0;
border-bottom:1px solid #edf1f0;
color:#40514e;
text-decoration:none;
transition:.2s;
}

.attention-item:first-child{
padding-top:0;
}

.attention-item:last-child{
padding-bottom:0;
border-bottom:0;
}

.attention-item:hover{
padding-left:4px;
}

.attention-icon{
display:flex;
align-items:center;
justify-content:center;
width:40px;
height:40px;
flex-shrink:0;
border-radius:10px;
background:#e8f3f1;
color:#2f8178;
font-size:16px;
}

.attention-icon.amber{
background:#fff4df;
color:#a66f25;
}

.attention-icon.red{
background:#faeaea;
color:#aa4c4c;
}

.attention-content{
flex:1;
min-width:0;
}

.attention-title{
display:block;
color:#40514e;
font-size:13px;
font-weight:600;
}

.attention-note{
display:block;
margin-top:3px;
color:#82918e;
font-size:10px;
line-height:1.4;
}

.attention-count{
display:flex;
align-items:center;
justify-content:center;
min-width:35px;
height:30px;
padding:0 9px;
border-radius:8px;
background:#f1f5f4;
color:#40514e;
font-size:15px;
font-weight:600;
}

/* QUICK ACTIONS */

.quick-card{
margin-bottom:30px;
}

.quick-actions{
display:grid;
grid-template-columns:repeat(3,1fr);
gap:16px;
}

.quick-action{
position:relative;
display:flex;
align-items:center;
gap:15px;
min-height:100px;
padding:18px;
background:#f4f9f8;
border:1px solid #dce9e6;
border-radius:13px;
color:#40514e;
text-decoration:none;
transition:.2s;
}

.quick-action:hover{
background:#e8f4f1;
border-color:#b5d6d0;
transform:translateY(-2px);
box-shadow:0 5px 15px rgba(47,129,120,.07);
}

.quick-icon{
display:flex;
align-items:center;
justify-content:center;
width:46px;
height:46px;
flex-shrink:0;
border-radius:11px;
background:#2f8178;
color:#fff;
font-size:18px;
}

.quick-text{
padding-right:22px;
}

.quick-text strong{
display:block;
color:#40514e;
font-size:14px;
font-weight:600;
}

.quick-text span{
display:block;
margin-top:4px;
color:#71817e;
font-size:11px;
line-height:1.4;
}

.quick-arrow{
position:absolute;
right:17px;
color:#7fa9a2;
font-size:20px;
}

/* FOOTER */

.footer{
margin:32px -38px 0;
padding:21px 30px;
background:#2f8178;
color:#e0efec;
font-size:14px;
text-align:center;
}

.footer strong{
color:#fff;
font-weight:700;
}

/* RESPONSIVE */

@media(max-width:1150px){

.stats-grid{
grid-template-columns:1fr;
}

.dashboard-grid{
grid-template-columns:1fr;
}

}

@media(max-width:900px){

.quick-actions{
grid-template-columns:1fr;
}

}

@media(max-width:800px){

.sidebar{
position:relative;
width:100%;
height:auto;
}

.main{
margin-left:0;
}

.content{
padding:24px 18px 0;
}

.topbar{
align-items:flex-start;
flex-direction:column;
}

.hero-content{
align-items:flex-start;
flex-direction:column;
}

.hero-divider{
display:none;
}

.hero-logo-box{
width:180px;
min-width:180px;
}

.occupancy-layout{
grid-template-columns:1fr;
}

.footer{
margin-left:-18px;
margin-right:-18px;
}

}

@media(max-width:520px){

.occupancy-info{
grid-template-columns:1fr;
}

.occupancy-box.rate{
grid-column:auto;
}

}
</style>
</head>

<body>

<!-- SIDEBAR -->

<div class="sidebar">

<div class="brand">
<div class="brand-name">
Rent<span>Ease</span>
</div>
<div class="brand-tagline">
Renting Made Easy.
</div>
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

<div class="menu-title">Management</div>

<a href="properties/properties.php">
<span class="menu-icon">⌂</span>
Properties
</a>

<a href="tenants/tenants.php">
<span class="menu-icon">♙</span>
Tenants
</a>

<a href="lease/leases.php">
<span class="menu-icon">▣</span>
Leases
</a>

<a href="rent/payments.php">
<span class="menu-icon">$</span>
Rent & Utilities
</a>

<div class="menu-title">Operations</div>

<a href="maintenance/maintenance.php">
<span class="menu-icon">⚙</span>
Maintenance
</a>

<a href="inspections/inspections.php">
<span class="menu-icon">◫</span>
Inspections
</a>

<a href="messages.php">
<span class="menu-icon">✉</span>
Communication
</a>

<div class="menu-title">System</div>

<a href="activity_log.php">
<span class="menu-icon">☷</span>
Activity Log
</a>

<div class="logout-area">

<a href="../auth/logout.php" class="logout">
<span class="menu-icon">↪</span>
Logout
</a>

</div>

</div>

<!-- MAIN -->

<div class="main">

<div class="content">

<!-- PAGE HEADER -->

<div class="topbar">

<div class="page-title">
<h1>Dashboard</h1>
<p>Property management overview and daily operations.</p>
</div>

<a href="profile.php" class="top-profile">

<div class="profile-icon">
<?php echo htmlspecialchars($profileLetter); ?>
</div>

<div class="profile-info">
<strong>
<?php echo htmlspecialchars($managerName); ?>
</strong>
<span>
<?php echo htmlspecialchars($jobTitle); ?>
</span>
</div>

</a>

</div>

<!-- LARGE TEAL AREA WITH LOGO -->

<div class="hero">

<div class="hero-content">

<div class="hero-logo-box">

<img
src="../images/rentease-logo.png"
alt="RentEase Logo"
class="hero-logo"
>

</div>

<div class="hero-divider"></div>

<div class="hero-text">

<h2>
Welcome back, <?php echo htmlspecialchars($firstName); ?>
</h2>

<p>
Manage your properties, tenants, leases and daily operations from one place.
Review important information and access your management tools quickly with RentEase.
</p>

</div>

</div>

</div>

<!-- THREE TOP CARDS -->

<div class="stats-grid">

<!-- PROPERTIES -->

<a href="properties/properties.php" class="stat-card primary">

<div class="stat-top">

<div class="stat-label">
Total Properties
</div>

<div class="stat-icon">
⌂
</div>

</div>

<div class="stat-number">
<?php echo $totalProperties; ?>
</div>

<div class="stat-note">
<?php echo $occupiedProperties; ?> occupied
&nbsp;•&nbsp;
<?php echo $vacantProperties; ?> vacant
</div>

</a>

<!-- TENANTS -->

<a href="tenants/tenants.php" class="stat-card">

<div class="stat-top">

<div class="stat-label">
Active Tenants
</div>

<div class="stat-icon">
♙
</div>

</div>

<div class="stat-number">
<?php echo $activeTenants; ?>
</div>

<div class="stat-note">
Current active tenant accounts
</div>

</a>

<!-- MAINTENANCE -->

<a href="maintenance/maintenance.php" class="stat-card">

<div class="stat-top">

<div class="stat-label">
Open Maintenance
</div>

<div class="stat-icon">
⚙
</div>

</div>

<div class="stat-number">
<?php echo $openMaintenance; ?>
</div>

<div class="stat-note">
Requests currently requiring attention
</div>

</a>

</div>

<!-- OCCUPANCY + ATTENTION -->

<div class="dashboard-grid">

<!-- OCCUPANCY -->

<div class="card">

<div class="card-header">

<div class="card-heading">

<h2>Property Occupancy</h2>

<p>
Current property portfolio overview.
</p>

</div>

<div class="card-badge">
<?php echo $occupancyRate; ?>% Occupied
</div>

</div>

<div class="card-body">

<div class="occupancy-layout">

<div class="donut-wrap">

<div class="donut"></div>

<div class="donut-center">

<strong>
<?php echo $occupancyRate; ?>%
</strong>

<span>
Occupied
</span>

</div>

</div>

<div class="occupancy-info">

<div class="occupancy-box">

<div class="occupancy-label">

<span class="dot"></span>

Occupied Properties

</div>

<div class="occupancy-value">
<?php echo $occupiedProperties; ?>
</div>

<div class="occupancy-small">
Currently leased properties
</div>

</div>

<div class="occupancy-box">

<div class="occupancy-label">

<span class="dot light"></span>

Vacant Properties

</div>

<div class="occupancy-value">
<?php echo $vacantProperties; ?>
</div>

<div class="occupancy-small">
Currently vacant properties
</div>

</div>

<div class="occupancy-box rate">

<div class="occupancy-label">
Total Properties
</div>

<div class="occupancy-value">
<?php echo $totalProperties; ?>
</div>

<div class="occupancy-small">

<?php echo $occupancyRate; ?>% occupied and
<?php echo $vacancyRate; ?>% vacant

</div>

</div>

</div>

</div>

</div>

</div>

<!-- NEEDS ATTENTION -->

<div class="card attention-card">

<div class="card-header">

<div class="card-heading">

<h2>
Needs Attention
</h2>

<p>
Important items to review.
</p>

</div>

<div class="card-badge">
<?php echo $attentionTotal; ?> Items
</div>

</div>

<div class="card-body">

<div class="attention-list">

<!-- MAINTENANCE -->

<a href="maintenance/maintenance.php" class="attention-item">

<div class="attention-icon amber">
⚙
</div>

<div class="attention-content">

<span class="attention-title">
Maintenance Requests
</span>

<span class="attention-note">
Open requests currently being managed
</span>

</div>

<div class="attention-count">
<?php echo $openMaintenance; ?>
</div>

</a>

<!-- OVERDUE RENT -->

<a href="rent/payments.php" class="attention-item">

<div class="attention-icon red">
$
</div>

<div class="attention-content">

<span class="attention-title">
Overdue Rent
</span>

<span class="attention-note">
Outstanding rent past its due date
</span>

</div>

<div class="attention-count">
<?php echo $overdueRent; ?>
</div>

</a>

<!-- EXPIRING LEASES -->

<a href="lease/leases.php" class="attention-item">

<div class="attention-icon amber">
▣
</div>

<div class="attention-content">

<span class="attention-title">
Expiring Leases
</span>

<span class="attention-note">
Active leases ending within 60 days
</span>

</div>

<div class="attention-count">
<?php echo $expiringLeases; ?>
</div>

</a>

<!-- INSPECTIONS -->

<a href="inspections/inspections.php" class="attention-item">

<div class="attention-icon">
◫
</div>

<div class="attention-content">

<span class="attention-title">
Upcoming Inspections
</span>

<span class="attention-note">
Scheduled property inspections
</span>

</div>

<div class="attention-count">
<?php echo $upcomingInspections; ?>
</div>

</a>

</div>

</div>

</div>

</div>

<!-- QUICK ACTIONS -->

<div class="card quick-card">

<div class="card-header">

<div class="card-heading">

<h2>
Quick Actions
</h2>

<p>
Frequently used manager tools.
</p>

</div>

</div>

<div class="card-body">

<div class="quick-actions">

<!-- LEASES -->

<a href="lease/leases.php" class="quick-action">

<div class="quick-icon">
▣
</div>

<div class="quick-text">

<strong>
Leases
</strong>

<span>
View and manage lease agreements
</span>

</div>

<span class="quick-arrow">
›
</span>

</a>

<!-- INSPECTIONS -->

<a href="inspections/inspections.php" class="quick-action">

<div class="quick-icon">
◫
</div>

<div class="quick-text">

<strong>
Inspections
</strong>

<span>
Schedule and manage inspections
</span>

</div>

<span class="quick-arrow">
›
</span>

</a>

<!-- COMMUNICATION -->

<a href="messages.php" class="quick-action">

<div class="quick-icon">
✉
</div>

<div class="quick-text">

<strong>
Communication
</strong>

<span>
View and send tenant messages
</span>

</div>

<span class="quick-arrow">
›
</span>

</a>

</div>

</div>

</div>

<footer class="footer">

<strong>
© 2026 RentEase Property Management System
</strong>

&nbsp; • &nbsp;

Renting Made Easy.

</footer>

</div>

</div>

</body>
</html>