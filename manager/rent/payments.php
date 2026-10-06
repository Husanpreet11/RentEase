<?php
session_start();
require_once __DIR__.'/../../config/db.php';

// Only managers can access this page
if(!isset($_SESSION['user_id'])){
header("Location: ../../index.php");
exit();
}
if(($_SESSION['role']??'')!=='manager'){
header("Location: ../../tenant/dashboard.php");
exit();
}

$managerId=(int)$_SESSION['user_id'];

// Get manager information
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

// Show flash message once only
$success='';
if(isset($_SESSION['payment_flash'])){
$success=$_SESSION['payment_flash'];
unset($_SESSION['payment_flash']);
}

$search=trim($_GET['search']??'');

// Summary information
$totalTenants=0;
$overdueRent=0;
$outstandingUtilities=0;
$paymentsThisMonth=0;

$result=$conn->query("SELECT COUNT(DISTINCT tenant_id) AS total
FROM leases
WHERE lease_status IN('active','renewal_due')");
if($result){
$totalTenants=(int)$result->fetch_assoc()['total'];
}

$result=$conn->query("SELECT COUNT(*) AS total
FROM rent_charges rc
WHERE rc.due_date<CURDATE()
AND (
rc.amount_due-
COALESCE((
SELECT SUM(rp.amount_paid)
FROM rent_payments rp
WHERE rp.rent_charge_id=rc.rent_charge_id
AND rp.payment_status='paid'
),0)
)>0");
if($result){
$overdueRent=(int)$result->fetch_assoc()['total'];
}

$result=$conn->query("SELECT COUNT(*) AS total
FROM utility_bills ub
WHERE (
ub.amount_due-
COALESCE((
SELECT SUM(up.amount_paid)
FROM utility_payments up
WHERE up.utility_bill_id=ub.utility_bill_id
AND up.payment_status='paid'
),0)
)>0");
if($result){
$outstandingUtilities=(int)$result->fetch_assoc()['total'];
}

// Rent payments made this month
$result=$conn->query("SELECT COUNT(*) AS total
FROM rent_payments
WHERE payment_status='paid'
AND YEAR(submitted_at)=YEAR(CURDATE())
AND MONTH(submitted_at)=MONTH(CURDATE())");
if($result){
$paymentsThisMonth+=(int)$result->fetch_assoc()['total'];
}

// Utility payments made this month
$result=$conn->query("SELECT COUNT(*) AS total
FROM utility_payments
WHERE payment_status='paid'
AND YEAR(submitted_at)=YEAR(CURDATE())
AND MONTH(submitted_at)=MONTH(CURDATE())");
if($result){
$paymentsThisMonth+=(int)$result->fetch_assoc()['total'];
}

// Get current tenant accounts
$sql="SELECT
u.user_id AS tenant_id,
u.first_name,
u.last_name,
u.email,
u.phone,
l.lease_id,
l.monthly_rent,
p.property_code,
p.address_line1,
p.suburb
FROM users u
INNER JOIN leases l ON l.tenant_id=u.user_id
INNER JOIN properties p ON p.property_id=l.property_id
WHERE u.role='tenant'
AND l.lease_status IN('active','renewal_due')";

$params=[];
$types='';

if($search!==''){
$sql.=" AND (
CONCAT(u.first_name,' ',u.last_name) LIKE ?
OR u.email LIKE ?
OR u.phone LIKE ?
OR p.property_code LIKE ?
OR p.address_line1 LIKE ?
OR p.suburb LIKE ?
)";
$value='%'.$search.'%';
$params=[$value,$value,$value,$value,$value,$value];
$types='ssssss';
}

$sql.=" ORDER BY u.first_name,u.last_name";

$stmt=$conn->prepare($sql);
if(!$stmt){
die("Unable to load tenant accounts: ".htmlspecialchars($conn->error));
}
if(!empty($params)){
$stmt->bind_param($types,...$params);
}
$stmt->execute();
$result=$stmt->get_result();

$tenantAccounts=[];

while($tenant=$result->fetch_assoc()){
$tenantId=(int)$tenant['tenant_id'];
$leaseId=(int)$tenant['lease_id'];

$rentBalance=0;
$utilityBalance=0;
$overdueCount=0;

$stmtRent=$conn->prepare("SELECT
rc.rent_charge_id,
rc.amount_due,
rc.due_date,
COALESCE(SUM(CASE WHEN rp.payment_status='paid' THEN rp.amount_paid ELSE 0 END),0) AS paid_amount
FROM rent_charges rc
LEFT JOIN rent_payments rp ON rp.rent_charge_id=rc.rent_charge_id
WHERE rc.lease_id=?
GROUP BY rc.rent_charge_id,rc.amount_due,rc.due_date");

if($stmtRent){
$stmtRent->bind_param("i",$leaseId);
$stmtRent->execute();
$rentResult=$stmtRent->get_result();

while($charge=$rentResult->fetch_assoc()){
$balance=max(0,(float)$charge['amount_due']-(float)$charge['paid_amount']);
$rentBalance+=$balance;

if($balance>0 && $charge['due_date']<date('Y-m-d')){
$overdueCount++;
}
}
}

$stmtUtility=$conn->prepare("SELECT
ub.utility_bill_id,
ub.amount_due,
ub.due_date,
COALESCE(SUM(CASE WHEN up.payment_status='paid' THEN up.amount_paid ELSE 0 END),0) AS paid_amount
FROM utility_bills ub
LEFT JOIN utility_payments up ON up.utility_bill_id=ub.utility_bill_id
WHERE ub.lease_id=?
GROUP BY ub.utility_bill_id,ub.amount_due,ub.due_date");

if($stmtUtility){
$stmtUtility->bind_param("i",$leaseId);
$stmtUtility->execute();
$utilityResult=$stmtUtility->get_result();

while($bill=$utilityResult->fetch_assoc()){
$balance=max(0,(float)$bill['amount_due']-(float)$bill['paid_amount']);
$utilityBalance+=$balance;

if($balance>0 && $bill['due_date']<date('Y-m-d')){
$overdueCount++;
}
}
}

$tenant['rent_balance']=$rentBalance;
$tenant['utility_balance']=$utilityBalance;
$tenant['overdue_count']=$overdueCount;
$tenantAccounts[]=$tenant;
}

// Recent rent payments
$recentPayments=[];

$result=$conn->query("SELECT
u.first_name,
u.last_name,
'Rent' AS payment_type,
rp.amount_paid,
rp.payment_method,
rp.reference_number,
rp.submitted_at
FROM rent_payments rp
INNER JOIN rent_charges rc ON rc.rent_charge_id=rp.rent_charge_id
INNER JOIN leases l ON l.lease_id=rc.lease_id
INNER JOIN users u ON u.user_id=l.tenant_id
WHERE rp.payment_status='paid'
ORDER BY rp.submitted_at DESC
LIMIT 8");

if($result){
while($row=$result->fetch_assoc()){
$recentPayments[]=$row;
}
}

// Recent utility payments
$result=$conn->query("SELECT
u.first_name,
u.last_name,
'Utility' AS payment_type,
up.amount_paid,
up.payment_method,
up.reference_number,
up.submitted_at
FROM utility_payments up
INNER JOIN utility_bills ub ON ub.utility_bill_id=up.utility_bill_id
INNER JOIN leases l ON l.lease_id=ub.lease_id
INNER JOIN users u ON u.user_id=l.tenant_id
WHERE up.payment_status='paid'
ORDER BY up.submitted_at DESC
LIMIT 8");

if($result){
while($row=$result->fetch_assoc()){
$recentPayments[]=$row;
}
}

// Put all recent payments in date order
usort($recentPayments,function($a,$b){
return strtotime($b['submitted_at'])<=>strtotime($a['submitted_at']);
});
$recentPayments=array_slice($recentPayments,0,8);

function niceText($value){
return ucwords(str_replace('_',' ',$value));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Rent & Utilities | RentEase</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:"Segoe UI",Arial,sans-serif;background:#f5f7f6;color:#4f5d5b;font-size:16px}
.sidebar{position:fixed;top:0;left:0;width:255px;height:100vh;background:#183b3a;padding:25px 18px;overflow-y:auto}
.brand{padding:0 10px 25px}.brand-name{font-size:25px;font-weight:700;color:#fff}.brand-name span{color:#8bc7c0}.brand-tagline{margin-top:5px;color:#b7d1cd;font-size:12px}
.menu-title{margin:20px 10px 8px;color:#8fb0ac;font-size:11px;font-weight:700;letter-spacing:1px;text-transform:uppercase}
.sidebar a{display:flex;align-items:center;gap:11px;padding:11px 12px;margin-bottom:4px;border-radius:8px;color:#d6e4e2;font-size:15px;text-decoration:none}
.sidebar a:hover{background:#24514e;color:#fff}.sidebar a.active{background:#2f8178;color:#fff;font-weight:600}.menu-icon{width:20px;text-align:center}
.logout-area{margin-top:25px;padding-top:15px;border-top:1px solid #315553}.sidebar .logout{color:#f1c1c1}
.main{margin-left:255px;min-height:100vh;padding:30px 36px 0}
.topbar{display:flex;align-items:center;justify-content:space-between;gap:20px;margin-bottom:22px}
.page-title h1{font-size:32px;font-weight:650;color:#344441;margin-bottom:5px}.page-title p{font-size:16px;color:#71817e}
.top-profile{display:flex;align-items:center;gap:11px;min-width:215px;padding:10px 14px;background:#fff;border:1px solid #dce5e2;border-radius:12px;color:#4f5d5b;text-decoration:none}
.profile-icon{display:flex;align-items:center;justify-content:center;width:42px;height:42px;background:#2f8178;border-radius:50%;color:#fff;font-weight:700}
.profile-info{display:flex;flex-direction:column}.profile-info strong{font-size:15px;font-weight:600;color:#40514e}.profile-info span{margin-top:2px;color:#71817e;font-size:12px}
.success-message{padding:12px 15px;margin-bottom:18px;background:#eef7f2;border:1px solid #cfe6d9;border-radius:9px;color:#3e7159;font-size:13px}
.summary-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:20px}
.summary-card{background:#fff;border:1px solid #dce5e2;border-radius:14px;padding:19px 20px;box-shadow:0 3px 12px rgba(35,70,66,.04)}
.summary-label{display:block;margin-bottom:8px;color:#5f706d;font-size:12px}.summary-value{display:block;color:#40514e;font-size:27px;font-weight:600}.summary-note{display:block;margin-top:5px;color:#71817e;font-size:11px}
.toolbar{display:flex;align-items:center;justify-content:space-between;gap:15px;padding:16px;margin-bottom:20px;background:#fff;border:1px solid #dce5e2;border-radius:14px;box-shadow:0 3px 12px rgba(35,70,66,.04)}
.search-form{display:flex;gap:9px;flex:1;max-width:650px}.search-form input{flex:1;height:41px;padding:9px 12px;border:1px solid #ccd9d6;border-radius:8px;color:#4f5d5b;font:inherit;font-size:13px;outline:none}
.search-form input:focus{border-color:#2f8178;box-shadow:0 0 0 3px rgba(47,129,120,.08)}
.button{display:inline-flex;align-items:center;justify-content:center;min-height:40px;padding:0 15px;border:0;border-radius:8px;background:#2f8178;color:#fff;font:inherit;font-size:13px;font-weight:600;text-decoration:none;cursor:pointer}
.button:hover{background:#286f68}.secondary-button{background:#eef6f4;border:1px solid #cfe2df;color:#2f8178}.secondary-button:hover{background:#e3f0ed}
.section-card{margin-bottom:20px;background:#fff;border:1px solid #dce5e2;border-radius:15px;overflow:hidden;box-shadow:0 3px 12px rgba(35,70,66,.04)}
.section-header{display:flex;align-items:center;justify-content:space-between;gap:15px;padding:17px 20px;border-bottom:1px solid #e3ebe9;background:#fbfdfc}
.section-header h2{font-size:20px;color:#40514e;font-weight:650;margin-bottom:3px}.section-header p{font-size:12px;color:#71817e}
.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse}
th{padding:12px 15px;background:#f7faf9;color:#5f706d;font-size:11px;text-align:left;text-transform:uppercase;letter-spacing:.3px;white-space:nowrap}
td{padding:14px 15px;border-top:1px solid #edf1f0;color:#4f5d5b;font-size:13px;vertical-align:middle}
.tenant-name{font-weight:600;color:#40514e}.sub-text{display:block;margin-top:3px;color:#71817e;font-size:11px}
.money{font-weight:600;color:#40514e;white-space:nowrap}.balance-due{color:#8d5c4d}.balance-clear{color:#4d7665}
.overdue{display:inline-block;padding:5px 9px;border-radius:20px;background:#fff0ed;color:#9a574b;font-size:10px;font-weight:700}
.clear-status,.payment-status{display:inline-block;padding:5px 9px;border-radius:20px;background:#eef6f4;color:#4f746b;font-size:10px;font-weight:700}
.empty{text-align:center;padding:32px;color:#71817e}
.footer{margin:30px -36px 0;padding:20px 30px;background:#183b3a;color:#c7d9d6;font-size:13px;text-align:center}.footer strong{color:#fff}
@media(max-width:1100px){.summary-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:760px){.sidebar{position:relative;width:100%;height:auto}.main{margin-left:0;padding:22px}.topbar,.toolbar,.section-header{align-items:flex-start;flex-direction:column}.summary-grid{grid-template-columns:1fr}.search-form{width:100%;max-width:none;flex-direction:column}.footer{margin-left:-22px;margin-right:-22px}}
</style>
</head>
<body>
<div class="sidebar">
<div class="brand"><div class="brand-name">Rent<span>Ease</span></div><div class="brand-tagline">Renting Made Easy.</div></div>
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
<div class="logout-area"><a href="../../auth/logout.php" class="logout"><span class="menu-icon">↪</span>Logout</a></div>
</div>

<div class="main">
<div class="topbar">
<div class="page-title"><h1>Rent & Utilities</h1><p>Monitor tenant balances, utility bills and completed payments.</p></div>
<a href="../profile.php" class="top-profile">
<div class="profile-icon"><?php echo htmlspecialchars($managerLetter); ?></div>
<div class="profile-info"><strong><?php echo htmlspecialchars($managerName); ?></strong><span><?php echo htmlspecialchars($jobTitle); ?></span></div>
</a>
</div>

<?php if($success!==''): ?>
<div class="success-message"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>

<div class="summary-grid">
<div class="summary-card"><span class="summary-label">Current Tenancies</span><span class="summary-value"><?php echo $totalTenants; ?></span><span class="summary-note">Active rental accounts</span></div>
<div class="summary-card"><span class="summary-label">Overdue Rent</span><span class="summary-value"><?php echo $overdueRent; ?></span><span class="summary-note">Outstanding past-due charges</span></div>
<div class="summary-card"><span class="summary-label">Outstanding Utilities</span><span class="summary-value"><?php echo $outstandingUtilities; ?></span><span class="summary-note">Bills with a remaining balance</span></div>
<div class="summary-card"><span class="summary-label">Payments This Month</span><span class="summary-value"><?php echo $paymentsThisMonth; ?></span><span class="summary-note">Completed tenant payments</span></div>
</div>

<div class="toolbar">
<form method="GET" class="search-form">
<input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search tenant, email or property">
<button type="submit" class="button">Search</button>
</form>
<a href="add_utility_bill.php" class="button">+ Add Utility Bill</a>
</div>

<div class="section-card">
<div class="section-header">
<div><h2>Tenant Financial Accounts</h2><p>Current rent and utility balances for active tenancies.</p></div>
<span class="sub-text"><?php echo count($tenantAccounts); ?> tenant<?php echo count($tenantAccounts)===1?'':'s'; ?></span>
</div>
<div class="table-wrap">
<table>
<thead><tr><th>Tenant</th><th>Property</th><th>Monthly Rent</th><th>Rent Balance</th><th>Utility Balance</th><th>Attention</th><th>Action</th></tr></thead>
<tbody>
<?php if(!empty($tenantAccounts)): ?>
<?php foreach($tenantAccounts as $tenant): ?>
<?php
$rentBalance=(float)$tenant['rent_balance'];
$utilityBalance=(float)$tenant['utility_balance'];
$overdueCount=(int)$tenant['overdue_count'];
?>
<tr>
<td><span class="tenant-name"><?php echo htmlspecialchars($tenant['first_name'].' '.$tenant['last_name']); ?></span><span class="sub-text"><?php echo htmlspecialchars($tenant['email']); ?></span></td>
<td><span class="tenant-name"><?php echo htmlspecialchars($tenant['property_code']); ?></span><span class="sub-text"><?php echo htmlspecialchars($tenant['address_line1'].', '.$tenant['suburb']); ?></span></td>
<td><span class="money">$<?php echo number_format((float)$tenant['monthly_rent'],2); ?></span></td>
<td><span class="money <?php echo $rentBalance>0?'balance-due':'balance-clear'; ?>">$<?php echo number_format($rentBalance,2); ?></span></td>
<td><span class="money <?php echo $utilityBalance>0?'balance-due':'balance-clear'; ?>">$<?php echo number_format($utilityBalance,2); ?></span></td>
<td><?php if($overdueCount>0): ?><span class="overdue"><?php echo $overdueCount; ?> overdue</span><?php else: ?><span class="clear-status">Up to date</span><?php endif; ?></td>
<td><a href="tenant_finances.php?id=<?php echo (int)$tenant['tenant_id']; ?>" class="button secondary-button">View Account</a></td>
</tr>
<?php endforeach; ?>
<?php else: ?>
<tr><td colspan="7" class="empty">No tenant financial accounts found.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
</div>

<div class="section-card">
<div class="section-header"><div><h2>Recent Payments</h2><p>Latest rent and utility payments completed by tenants.</p></div></div>
<div class="table-wrap">
<table>
<thead><tr><th>Tenant</th><th>Type</th><th>Amount</th><th>Method</th><th>Reference</th><th>Date</th><th>Status</th></tr></thead>
<tbody>
<?php if(!empty($recentPayments)): ?>
<?php foreach($recentPayments as $payment): ?>
<tr>
<td><span class="tenant-name"><?php echo htmlspecialchars($payment['first_name'].' '.$payment['last_name']); ?></span></td>
<td><?php echo htmlspecialchars($payment['payment_type']); ?></td>
<td><span class="money">$<?php echo number_format((float)$payment['amount_paid'],2); ?></span></td>
<td><?php echo htmlspecialchars(niceText($payment['payment_method'])); ?></td>
<td><?php echo htmlspecialchars($payment['reference_number']?:'—'); ?></td>
<td><?php echo date("d M Y",strtotime($payment['submitted_at'])); ?></td>
<td><span class="payment-status">Paid</span></td>
</tr>
<?php endforeach; ?>
<?php else: ?>
<tr><td colspan="7" class="empty">No completed payments found.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
</div>

<footer class="footer"><strong>© 2026 RentEase Property Management System</strong>&nbsp; • &nbsp;Renting Made Easy.</footer>
</div>
</body>
</html>