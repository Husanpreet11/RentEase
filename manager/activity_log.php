<?php
session_start();
require_once __DIR__ . '/../config/db.php';

// Manager only
if(!isset($_SESSION['user_id'])){
header("Location: ../index.php");
exit();
}
if(($_SESSION['role']??'')!=='manager'){
header("Location: ../tenant/dashboard.php");
exit();
}

$managerId=(int)$_SESSION['user_id'];

// Get manager details
$stmt=$conn->prepare("SELECT first_name,last_name,email FROM users WHERE user_id=? AND role='manager' LIMIT 1");
$stmt->bind_param("i",$managerId);
$stmt->execute();
$manager=$stmt->get_result()->fetch_assoc();

if(!$manager){
session_destroy();
header("Location: ../index.php");
exit();
}

$managerName=trim($manager['first_name'].' '.$manager['last_name']);
$managerLetter=strtoupper(substr($manager['first_name'],0,1));

// Search and filters
$search=trim($_GET['search']??'');
$actionFilter=trim($_GET['action']??'');
$entityFilter=trim($_GET['entity']??'');

// Build activity query
$sql="
SELECT
al.activity_id,
al.user_id,
al.action_type,
al.entity_type,
al.entity_id,
al.description,
al.created_at,
u.first_name,
u.last_name,
u.role
FROM activity_log al
LEFT JOIN users u ON u.user_id=al.user_id
WHERE 1=1
";

$params=[];
$types="";

if($search!==''){
$sql.=" AND (
al.description LIKE ?
OR al.action_type LIKE ?
OR al.entity_type LIKE ?
OR CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')) LIKE ?
)";
$searchValue="%".$search."%";
$params[]=$searchValue;
$params[]=$searchValue;
$params[]=$searchValue;
$params[]=$searchValue;
$types.="ssss";
}

if($actionFilter!==''){
$sql.=" AND al.action_type=?";
$params[]=$actionFilter;
$types.="s";
}

if($entityFilter!==''){
$sql.=" AND al.entity_type=?";
$params[]=$entityFilter;
$types.="s";
}

$sql.=" ORDER BY al.created_at DESC LIMIT 200";

$stmt=$conn->prepare($sql);

if(!empty($params)){
$stmt->bind_param($types,...$params);
}

$stmt->execute();
$activities=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Action filter options
$actionTypes=[];
$result=$conn->query("
SELECT DISTINCT action_type
FROM activity_log
WHERE action_type IS NOT NULL
AND action_type!=''
ORDER BY action_type
");

if($result){
while($row=$result->fetch_assoc()){
$actionTypes[]=$row['action_type'];
}
}

// Entity filter options
$entityTypes=[];
$result=$conn->query("
SELECT DISTINCT entity_type
FROM activity_log
WHERE entity_type IS NOT NULL
AND entity_type!=''
ORDER BY entity_type
");

if($result){
while($row=$result->fetch_assoc()){
$entityTypes[]=$row['entity_type'];
}
}

// Summary counts
$totalActivities=0;
$todayActivities=0;
$managerActivities=0;
$tenantActivities=0;

$result=$conn->query("SELECT COUNT(*) AS total FROM activity_log");
if($result){
$totalActivities=(int)$result->fetch_assoc()['total'];
}

$result=$conn->query("SELECT COUNT(*) AS total FROM activity_log WHERE DATE(created_at)=CURDATE()");
if($result){
$todayActivities=(int)$result->fetch_assoc()['total'];
}

$result=$conn->query("
SELECT COUNT(*) AS total
FROM activity_log al
INNER JOIN users u ON u.user_id=al.user_id
WHERE u.role='manager'
");
if($result){
$managerActivities=(int)$result->fetch_assoc()['total'];
}

$result=$conn->query("
SELECT COUNT(*) AS total
FROM activity_log al
INNER JOIN users u ON u.user_id=al.user_id
WHERE u.role='tenant'
");
if($result){
$tenantActivities=(int)$result->fetch_assoc()['total'];
}

// Helper functions
function displayAction($action){
return ucwords(strtolower(str_replace('_',' ',$action??'')));
}

function displayEntity($entity){
return ucwords(strtolower(str_replace('_',' ',$entity??'')));
}

function actionClass($action){
$action=strtolower($action??'');

if(
strpos($action,'create')!==false||
strpos($action,'add')!==false||
strpos($action,'submit')!==false
){
return 'green';
}

if(
strpos($action,'delete')!==false||
strpos($action,'remove')!==false||
strpos($action,'reject')!==false
){
return 'red';
}

if(
strpos($action,'payment')!==false||
strpos($action,'pay')!==false
){
return 'amber';
}

if(
strpos($action,'message')!==false||
strpos($action,'send')!==false
){
return 'teal';
}

if(
strpos($action,'update')!==false||
strpos($action,'edit')!==false
){
return 'teal';
}

return 'grey';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Activity Log | RentEase</title>
<style>
*{
box-sizing:border-box;
}
body{
margin:0;
background:#f5f7f6;
color:#4f5d5b;
font-family:"Segoe UI",Arial,sans-serif;
font-size:16px;
}
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
.logout-area{
margin-top:25px;
padding-top:15px;
border-top:1px solid rgba(255,255,255,.12);
}
.sidebar .logout{
color:#f0c4c4;
}
.main{
margin-left:255px;
min-height:100vh;
padding:35px 42px 25px;
}
.topbar{
display:flex;
align-items:center;
justify-content:space-between;
gap:20px;
margin-bottom:25px;
}
.page-title h1{
margin:0;
color:#344441;
font-size:32px;
font-weight:700;
letter-spacing:-.5px;
}
.page-title p{
margin:7px 0 0;
color:#71817e;
font-size:16px;
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
font-size:15px;
font-weight:600;
}
.profile-info span{
display:block;
margin-top:2px;
color:#71817e;
font-size:13px;
}
.stats-grid{
display:grid;
grid-template-columns:repeat(4,1fr);
gap:18px;
margin-bottom:22px;
}
.stat-card{
padding:20px;
background:#fff;
border:1px solid #dce5e2;
border-radius:14px;
box-shadow:0 5px 18px rgba(37,64,60,.04);
}
.stat-title{
color:#71817e;
font-size:12px;
font-weight:600;
letter-spacing:.4px;
text-transform:uppercase;
}
.stat-value{
margin-top:9px;
color:#40514e;
font-size:24px;
font-weight:600;
}
.stat-note{
margin-top:6px;
color:#82918e;
font-size:12px;
}
.card{
background:#fff;
border:1px solid #dce5e2;
border-radius:15px;
overflow:hidden;
box-shadow:0 6px 22px rgba(37,64,60,.05);
}
.card-header{
padding:21px 25px;
border-bottom:1px solid #e4e9e7;
}
.card-header h2{
margin:0;
color:#344441;
font-size:21px;
font-weight:600;
}
.card-header p{
margin:5px 0 0;
color:#71817e;
font-size:14px;
}
.filters{
padding:20px 25px;
background:#f8faf9;
border-bottom:1px solid #e4e9e7;
}
.filter-form{
display:grid;
grid-template-columns:2fr 1fr 1fr auto;
gap:12px;
align-items:end;
}
.filter-group label{
display:block;
margin-bottom:7px;
color:#5f706d;
font-size:13px;
font-weight:600;
}
.form-control{
width:100%;
height:42px;
padding:10px 12px;
background:#fff;
border:1px solid #cfdad7;
border-radius:8px;
color:#40514e;
font-family:inherit;
font-size:14px;
outline:none;
}
.form-control:focus{
border-color:#2f8178;
box-shadow:0 0 0 3px rgba(47,129,120,.08);
}
.filter-buttons{
display:flex;
gap:8px;
}
.btn{
display:inline-flex;
align-items:center;
justify-content:center;
height:42px;
padding:0 17px;
border:0;
border-radius:8px;
background:#2f8178;
color:#fff;
font-family:inherit;
font-size:13px;
font-weight:600;
text-decoration:none;
cursor:pointer;
white-space:nowrap;
transition:.2s;
}
.btn:hover{
background:#286f68;
}
.btn-secondary{
background:#e9efed;
color:#52615f;
}
.btn-secondary:hover{
background:#dce5e2;
}
.results-info{
display:flex;
align-items:center;
justify-content:space-between;
gap:15px;
padding:14px 25px;
border-bottom:1px solid #e4e9e7;
color:#71817e;
font-size:13px;
}
.results-info strong{
color:#40514e;
font-weight:600;
}
.table-wrap{
overflow-x:auto;
}
table{
width:100%;
border-collapse:collapse;
}
th{
padding:13px 16px;
background:#f5f8f7;
border-bottom:1px solid #dce5e2;
color:#5f706d;
font-size:11px;
font-weight:700;
text-align:left;
text-transform:uppercase;
letter-spacing:.4px;
}
td{
padding:15px 16px;
border-bottom:1px solid #edf1f0;
color:#52615f;
font-size:13px;
vertical-align:middle;
}
tbody tr:hover{
background:#fafcfb;
}
tbody tr:last-child td{
border-bottom:0;
}
.date-main{
color:#40514e;
font-weight:600;
white-space:nowrap;
}
.date-time{
margin-top:3px;
color:#82918e;
font-size:11px;
}
.user-name{
color:#40514e;
font-weight:600;
white-space:nowrap;
}
.user-role{
margin-top:3px;
color:#82918e;
font-size:11px;
text-transform:capitalize;
}
.description{
min-width:240px;
max-width:420px;
color:#52615f;
line-height:1.55;
}
.entity-name{
color:#40514e;
font-weight:600;
}
.entity-id{
margin-top:3px;
color:#82918e;
font-size:11px;
}
.badge{
display:inline-block;
padding:6px 10px;
border-radius:20px;
font-size:11px;
font-weight:600;
white-space:nowrap;
}
.badge.green{
background:#e8f5ee;
color:#287a55;
}
.badge.red{
background:#fae8e8;
color:#a84545;
}
.badge.amber{
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
.empty-state{
padding:45px 25px;
color:#71817e;
font-size:14px;
text-align:center;
}
.empty-state strong{
display:block;
margin-bottom:6px;
color:#40514e;
font-size:16px;
font-weight:600;
}
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
@media(max-width:1100px){
.stats-grid{
grid-template-columns:repeat(2,1fr);
}
.filter-form{
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
.stats-grid{
grid-template-columns:1fr;
}
.filter-form{
grid-template-columns:1fr;
}
.filter-buttons{
width:100%;
}
.filter-buttons .btn{
flex:1;
}
.footer{
margin-left:-18px;
margin-right:-18px;
}
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
<a href="dashboard.php">
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
<a href="activity_log.php" class="active">
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

<div class="main">
<div class="topbar">
<div class="page-title">
<h1>Activity Log</h1>
<p>Review important activity across the RentEase system.</p>
</div>

<a href="profile.php" class="top-profile">
<div class="profile-icon"><?php echo htmlspecialchars($managerLetter); ?></div>
<div class="profile-info">
<strong><?php echo htmlspecialchars($managerName); ?></strong>
<span>Property Manager</span>
</div>
</a>
</div>

<div class="stats-grid">
<div class="stat-card">
<div class="stat-title">Total Activities</div>
<div class="stat-value"><?php echo $totalActivities; ?></div>
<div class="stat-note">All recorded system activity</div>
</div>

<div class="stat-card">
<div class="stat-title">Activities Today</div>
<div class="stat-value"><?php echo $todayActivities; ?></div>
<div class="stat-note">Activity recorded today</div>
</div>

<div class="stat-card">
<div class="stat-title">Manager Activities</div>
<div class="stat-value"><?php echo $managerActivities; ?></div>
<div class="stat-note">Actions performed by managers</div>
</div>

<div class="stat-card">
<div class="stat-title">Tenant Activities</div>
<div class="stat-value"><?php echo $tenantActivities; ?></div>
<div class="stat-note">Actions performed by tenants</div>
</div>
</div>

<div class="card">
<div class="card-header">
<h2>System Activity</h2>
<p>Search and review the most recent actions recorded by RentEase.</p>
</div>

<div class="filters">
<form method="GET" class="filter-form">
<div class="filter-group">
<label for="search">Search</label>
<input type="text" id="search" name="search" class="form-control" placeholder="Search user, action or description..." value="<?php echo htmlspecialchars($search); ?>">
</div>

<div class="filter-group">
<label for="action">Action</label>
<select id="action" name="action" class="form-control">
<option value="">All Actions</option>
<?php foreach($actionTypes as $type): ?>
<option value="<?php echo htmlspecialchars($type); ?>" <?php echo $actionFilter===$type?'selected':''; ?>>
<?php echo htmlspecialchars(displayAction($type)); ?>
</option>
<?php endforeach; ?>
</select>
</div>

<div class="filter-group">
<label for="entity">Area</label>
<select id="entity" name="entity" class="form-control">
<option value="">All Areas</option>
<?php foreach($entityTypes as $type): ?>
<option value="<?php echo htmlspecialchars($type); ?>" <?php echo $entityFilter===$type?'selected':''; ?>>
<?php echo htmlspecialchars(displayEntity($type)); ?>
</option>
<?php endforeach; ?>
</select>
</div>

<div class="filter-buttons">
<button type="submit" class="btn">Search</button>
<?php if($search!==''||$actionFilter!==''||$entityFilter!==''): ?>
<a href="activity_log.php" class="btn btn-secondary">Show All</a>
<?php endif; ?>
</div>
</form>
</div>

<div class="results-info">
<div>
<strong><?php echo count($activities); ?></strong>
<?php echo count($activities)===1?'activity':'activities'; ?> shown
</div>
<div>Maximum 200 recent records</div>
</div>

<?php if(count($activities)>0): ?>
<div class="table-wrap">
<table>
<thead>
<tr>
<th>Date & Time</th>
<th>User</th>
<th>Action</th>
<th>Area</th>
<th>Description</th>
</tr>
</thead>
<tbody>

<?php foreach($activities as $activity): ?>
<tr>
<td>
<div class="date-main">
<?php echo date('d M Y',strtotime($activity['created_at'])); ?>
</div>
<div class="date-time">
<?php echo date('g:i A',strtotime($activity['created_at'])); ?>
</div>
</td>

<td>
<?php if(!empty($activity['first_name'])): ?>
<div class="user-name">
<?php echo htmlspecialchars(trim($activity['first_name'].' '.$activity['last_name'])); ?>
</div>
<div class="user-role">
<?php echo htmlspecialchars($activity['role']); ?>
</div>
<?php else: ?>
<div class="user-name">System</div>
<div class="user-role">Automated</div>
<?php endif; ?>
</td>

<td>
<span class="badge <?php echo actionClass($activity['action_type']); ?>">
<?php echo htmlspecialchars(displayAction($activity['action_type'])); ?>
</span>
</td>

<td>
<div class="entity-name">
<?php echo htmlspecialchars(displayEntity($activity['entity_type'])); ?>
</div>

<?php if(!empty($activity['entity_id'])): ?>
<div class="entity-id">
ID: <?php echo (int)$activity['entity_id']; ?>
</div>
<?php endif; ?>
</td>

<td>
<div class="description">
<?php echo htmlspecialchars($activity['description']); ?>
</div>
</td>
</tr>
<?php endforeach; ?>

</tbody>
</table>
</div>

<?php else: ?>
<div class="empty-state">
<strong>No activities found</strong>
<span>No activity matches your current search.</span>
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