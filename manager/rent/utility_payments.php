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

// Manager details
$stmt=$conn->prepare("SELECT u.first_name,u.last_name,mp.job_title
FROM users u
LEFT JOIN manager_profiles mp ON mp.manager_id=u.user_id
WHERE u.user_id=? AND u.role='manager'
LIMIT 1");
$stmt->bind_param("i",$managerId);
$stmt->execute();
$manager=$stmt->get_result()->fetch_assoc();

$managerFirst=$manager['first_name']??'Manager';
$managerName=trim($managerFirst.' '.($manager['last_name']??''));
$managerLetter=strtoupper(substr($managerFirst,0,1));
$jobTitle=$manager['job_title']??'Property Manager';

$search=trim($_GET['search']??'');

// Summary values
$totalPaid=0;
$totalAmount=0;
$thisMonth=0;

$result=$conn->query("SELECT COUNT(*) AS total,COALESCE(SUM(amount_paid),0) AS amount
FROM utility_payments
WHERE payment_status='paid'");
if($result){
$row=$result->fetch_assoc();
$totalPaid=(int)$row['total'];
$totalAmount=(float)$row['amount'];
}

$result=$conn->query("SELECT COUNT(*) AS total
FROM utility_payments
WHERE payment_status='paid'
AND YEAR(submitted_at)=YEAR(CURDATE())
AND MONTH(submitted_at)=MONTH(CURDATE())");
if($result){
$thisMonth=(int)$result->fetch_assoc()['total'];
}

// Load actual utility payments
$sql="SELECT
up.utility_payment_id,
up.amount_paid,
up.submitted_at,
up.payment_method,
up.reference_number,
up.payment_status,
ub.utility_bill_id,
ub.utility_type,
ub.provider_name,
ub.amount_due,
ub.due_date,
u.user_id AS tenant_id,
u.first_name,
u.last_name,
p.property_code
FROM utility_payments up
INNER JOIN utility_bills ub ON ub.utility_bill_id=up.utility_bill_id
INNER JOIN leases l ON l.lease_id=ub.lease_id
INNER JOIN users u ON u.user_id=up.tenant_id
INNER JOIN properties p ON p.property_id=l.property_id
WHERE 1=1";

$params=[];
$types='';

if($search!==''){
$sql.=" AND (
CONCAT(u.first_name,' ',u.last_name) LIKE ?
OR p.property_code LIKE ?
OR ub.utility_type LIKE ?
OR ub.provider_name LIKE ?
OR up.reference_number LIKE ?
)";
$value='%'.$search.'%';
$params=[$value,$value,$value,$value,$value];
$types='sssss';
}

$sql.=" ORDER BY up.submitted_at DESC";

$stmt=$conn->prepare($sql);

if(!$stmt){
die("Unable to load utility payments: ".htmlspecialchars($conn->error));
}

if(!empty($params)){
$stmt->bind_param($types,...$params);
}

$stmt->execute();
$result=$stmt->get_result();

$payments=[];

while($row=$result->fetch_assoc()){
$payments[]=$row;
}

function niceText($value){
return ucwords(str_replace('_',' ',$value));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Utility Payments | RentEase</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:"Segoe UI",Arial,sans-serif;background:#f5f7f6;color:#4f5d5b;font-size:16px}
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
.back-row{margin-bottom:17px}
.back-link{color:#2f8178;text-decoration:none;font-size:13px;font-weight:600}
.back-link:hover{text-decoration:underline}
.topbar{display:flex;align-items:center;justify-content:space-between;gap:20px;margin-bottom:22px}
.page-title h1{font-size:32px;font-weight:650;color:#344441;margin-bottom:5px}
.page-title p{font-size:15px;color:#71817e}
.top-profile{display:flex;align-items:center;gap:11px;min-width:215px;padding:10px 14px;background:#fff;border:1px solid #dce5e2;border-radius:12px;color:#4f5d5b;text-decoration:none}
.profile-icon{display:flex;align-items:center;justify-content:center;width:42px;height:42px;background:#2f8178;border-radius:50%;color:#fff;font-weight:700}
.profile-info{display:flex;flex-direction:column}
.profile-info strong{font-size:15px;font-weight:600;color:#40514e}
.profile-info span{margin-top:2px;color:#71817e;font-size:12px}
.summary-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:20px}
.summary-card{background:#fff;border:1px solid #dce5e2;border-radius:14px;padding:19px 20px;box-shadow:0 3px 12px rgba(35,70,66,.04)}
.summary-label{display:block;margin-bottom:8px;color:#5f706d;font-size:12px}
.summary-value{display:block;color:#40514e;font-size:27px;font-weight:600}
.summary-note{display:block;margin-top:5px;color:#71817e;font-size:11px}
.toolbar{display:flex;justify-content:space-between;gap:12px;padding:16px;margin-bottom:20px;background:#fff;border:1px solid #dce5e2;border-radius:14px}
.search-form{display:flex;gap:9px;flex:1;max-width:650px}
.search-form input{flex:1;height:41px;padding:9px 12px;border:1px solid #ccd9d6;border-radius:8px;color:#4f5d5b;font:inherit;font-size:13px;outline:none}
.search-form input:focus{border-color:#2f8178;box-shadow:0 0 0 3px rgba(47,129,120,.08)}
.button{display:inline-flex;align-items:center;justify-content:center;min-height:40px;padding:0 15px;border:0;border-radius:8px;background:#2f8178;color:#fff;font:inherit;font-size:13px;font-weight:600;text-decoration:none;cursor:pointer}
.button:hover{background:#286f68}
.section-card{margin-bottom:22px;background:#fff;border:1px solid #dce5e2;border-radius:15px;overflow:hidden;box-shadow:0 3px 12px rgba(35,70,66,.04)}
.section-header{padding:18px 20px;border-bottom:1px solid #e3ebe9;background:#fbfdfc}
.section-header h2{font-size:20px;color:#40514e;font-weight:650;margin-bottom:4px}
.section-header p{font-size:12px;color:#71817e}
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse}
th{padding:12px 14px;background:#f7faf9;color:#5f706d;font-size:11px;text-align:left;text-transform:uppercase;letter-spacing:.3px;white-space:nowrap}
td{padding:14px;border-top:1px solid #edf1f0;color:#4f5d5b;font-size:13px;vertical-align:middle}
.tenant-name{font-weight:600;color:#40514e}
.sub-text{display:block;margin-top:3px;color:#71817e;font-size:11px}
.money{font-weight:600;color:#40514e;white-space:nowrap}
.status-paid{display:inline-block;padding:5px 10px;border-radius:20px;background:#e8f6ee;color:#3f765d;font-size:10px;font-weight:700}
.status-rejected{display:inline-block;padding:5px 10px;border-radius:20px;background:#fff0ed;color:#9a574b;font-size:10px;font-weight:700}
.view-link{color:#2f8178;text-decoration:none;font-size:12px;font-weight:600}
.view-link:hover{text-decoration:underline}
.empty{text-align:center;padding:32px;color:#71817e}
.footer{margin:30px -36px 0;padding:20px 30px;background:#183b3a;color:#c7d9d6;font-size:13px;text-align:center}
.footer strong{color:#fff}
@media(max-width:900px){
.summary-grid{grid-template-columns:1fr}
}
@media(max-width:760px){
.sidebar{position:relative;width:100%;height:auto}
.main{margin-left:0;padding:22px}
.topbar{align-items:flex-start;flex-direction:column}
.search-form{width:100%;max-width:none;flex-direction:column}
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
<a href="../properties/properties.php"><span class="menu-icon">⌂</span>Properties</a>
<a href="../tenants/tenants.php"><span class="menu-icon">♙</span>Tenants</a>
<a href="../lease/leases.php"><span class="menu-icon">▣</span>Leases</a>
<a href="payments.php" class="active"><span class="menu-icon">$</span>Rent & Utilities</a>

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

<div class="back-row">
<a href="payments.php" class="back-link">← Back to Rent & Utilities</a>
</div>

<div class="topbar">
<div class="page-title">
<h1>Utility Payments</h1>
<p>View utility payments completed by tenants.</p>
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
<span class="summary-label">Paid Utility Payments</span>
<span class="summary-value"><?php echo $totalPaid; ?></span>
<span class="summary-note">Completed payment records</span>
</div>

<div class="summary-card">
<span class="summary-label">Total Received</span>
<span class="summary-value">$<?php echo number_format($totalAmount,2); ?></span>
<span class="summary-note">Successful utility payments</span>
</div>

<div class="summary-card">
<span class="summary-label">Payments This Month</span>
<span class="summary-value"><?php echo $thisMonth; ?></span>
<span class="summary-note">Completed this month</span>
</div>

</div>

<div class="toolbar">
<form method="GET" class="search-form">
<input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search tenant, property, utility, provider or reference">
<button type="submit" class="button">Search</button>
</form>
</div>

<div class="section-card">

<div class="section-header">
<h2>Utility Payment History</h2>
<p>Payments recorded through tenant utility bills.</p>
</div>

<div class="table-wrap">
<table>

<thead>
<tr>
<th>Tenant</th>
<th>Property</th>
<th>Utility</th>
<th>Provider</th>
<th>Amount Paid</th>
<th>Method</th>
<th>Reference</th>
<th>Date</th>
<th>Status</th>
<th>Account</th>
</tr>
</thead>

<tbody>

<?php if(!empty($payments)): ?>

<?php foreach($payments as $payment): ?>

<tr>

<td>
<span class="tenant-name">
<?php echo htmlspecialchars($payment['first_name'].' '.$payment['last_name']); ?>
</span>
</td>

<td>
<?php echo htmlspecialchars($payment['property_code']); ?>
</td>

<td>
<?php echo htmlspecialchars(niceText($payment['utility_type'])); ?>
</td>

<td>
<?php echo htmlspecialchars($payment['provider_name']?:'—'); ?>
</td>

<td>
<span class="money">
$<?php echo number_format((float)$payment['amount_paid'],2); ?>
</span>
</td>

<td>
<?php echo htmlspecialchars(niceText($payment['payment_method'])); ?>
</td>

<td>
<?php echo htmlspecialchars($payment['reference_number']?:'—'); ?>
</td>

<td>
<?php echo date("d M Y",strtotime($payment['submitted_at'])); ?>
<span class="sub-text">
<?php echo date("g:i A",strtotime($payment['submitted_at'])); ?>
</span>
</td>

<td>
<?php if($payment['payment_status']==='paid'): ?>
<span class="status-paid">Paid</span>
<?php else: ?>
<span class="status-rejected">Rejected</span>
<?php endif; ?>
</td>

<td>
<a href="tenant_finances.php?id=<?php echo (int)$payment['tenant_id']; ?>" class="view-link">
View Account
</a>
</td>

</tr>

<?php endforeach; ?>

<?php else: ?>

<tr>
<td colspan="10" class="empty">No utility payment records found.</td>
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