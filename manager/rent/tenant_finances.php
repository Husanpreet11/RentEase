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
$tenantId=(int)($_GET['id']??0);

if($tenantId<=0){
header("Location: payments.php");
exit();
}

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

// Get tenant and current lease
$stmt=$conn->prepare("SELECT
u.user_id,u.first_name,u.last_name,u.email,u.phone,
l.lease_id,l.start_date,l.end_date,l.monthly_rent,l.lease_status,
p.property_code,p.address_line1,p.suburb,p.state,p.postcode
FROM users u
INNER JOIN leases l ON l.tenant_id=u.user_id
INNER JOIN properties p ON p.property_id=l.property_id
WHERE u.user_id=?
AND u.role='tenant'
AND l.lease_status IN('active','renewal_due')
ORDER BY l.start_date DESC
LIMIT 1");

if(!$stmt){
die("Unable to load tenant account: ".htmlspecialchars($conn->error));
}

$stmt->bind_param("i",$tenantId);
$stmt->execute();
$tenant=$stmt->get_result()->fetch_assoc();

if(!$tenant){
header("Location: payments.php");
exit();
}

$leaseId=(int)$tenant['lease_id'];
$tenantName=$tenant['first_name'].' '.$tenant['last_name'];

// Flash message appears once only
$success='';
if(isset($_SESSION['payment_flash'])){
$success=$_SESSION['payment_flash'];
unset($_SESSION['payment_flash']);
}

// Get rent history with actual paid amounts
$rentHistory=[];

$stmt=$conn->prepare("SELECT
rc.rent_charge_id,
rc.rent_period,
rc.due_date,
rc.amount_due,
COALESCE(SUM(CASE WHEN rp.payment_status='paid' THEN rp.amount_paid ELSE 0 END),0) AS paid_amount
FROM rent_charges rc
LEFT JOIN rent_payments rp ON rp.rent_charge_id=rc.rent_charge_id
WHERE rc.lease_id=?
GROUP BY rc.rent_charge_id,rc.rent_period,rc.due_date,rc.amount_due
ORDER BY rc.due_date DESC");

if(!$stmt){
die("Unable to load rent history: ".htmlspecialchars($conn->error));
}

$stmt->bind_param("i",$leaseId);
$stmt->execute();
$result=$stmt->get_result();

$rentBalance=0;
$overdueItems=0;

while($row=$result->fetch_assoc()){
$amountDue=(float)$row['amount_due'];
$paidAmount=(float)$row['paid_amount'];
$balance=max(0,$amountDue-$paidAmount);

// Status is calculated from the real balance
if($balance<=0){
$status='Paid';
}elseif($paidAmount>0){
$status='Part Paid';
}elseif($row['due_date']<date('Y-m-d')){
$status='Overdue';
}else{
$status='Due';
}

$row['balance']=$balance;
$row['display_status']=$status;

$rentBalance+=$balance;

if($status==='Overdue'){
$overdueItems++;
}

$rentHistory[]=$row;
}

// Get utility bills with actual payments
$utilityBills=[];

$stmt=$conn->prepare("SELECT
ub.utility_bill_id,
ub.utility_type,
ub.provider_name,
ub.billing_period_start,
ub.billing_period_end,
ub.due_date,
ub.amount_due,
COALESCE(SUM(CASE WHEN up.payment_status='paid' THEN up.amount_paid ELSE 0 END),0) AS paid_amount
FROM utility_bills ub
LEFT JOIN utility_payments up ON up.utility_bill_id=ub.utility_bill_id
WHERE ub.lease_id=?
GROUP BY
ub.utility_bill_id,
ub.utility_type,
ub.provider_name,
ub.billing_period_start,
ub.billing_period_end,
ub.due_date,
ub.amount_due
ORDER BY ub.due_date DESC");

if(!$stmt){
die("Unable to load utility bills: ".htmlspecialchars($conn->error));
}

$stmt->bind_param("i",$leaseId);
$stmt->execute();
$result=$stmt->get_result();

$utilityBalance=0;

while($row=$result->fetch_assoc()){
$amountDue=(float)$row['amount_due'];
$paidAmount=(float)$row['paid_amount'];
$balance=max(0,$amountDue-$paidAmount);

if($balance<=0){
$status='Paid';
}elseif($paidAmount>0){
$status='Part Paid';
}elseif($row['due_date']<date('Y-m-d')){
$status='Overdue';
}else{
$status='Unpaid';
}

$row['balance']=$balance;
$row['display_status']=$status;

$utilityBalance+=$balance;

if($status==='Overdue'){
$overdueItems++;
}

$utilityBills[]=$row;
}

function niceText($value){
return ucwords(str_replace('_',' ',$value));
}

function statusClass($status){
if($status==='Paid') return 'status-paid';
if($status==='Overdue') return 'status-overdue';
if($status==='Part Paid') return 'status-part';
return 'status-due';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Tenant Financial Account | RentEase</title>
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
.topbar{display:flex;align-items:center;justify-content:space-between;gap:20px;margin-bottom:20px}
.page-title h1{font-size:32px;font-weight:650;color:#344441;margin-bottom:5px}.page-title p{font-size:15px;color:#71817e}
.top-profile{display:flex;align-items:center;gap:11px;min-width:215px;padding:10px 14px;background:#fff;border:1px solid #dce5e2;border-radius:12px;color:#4f5d5b;text-decoration:none}
.profile-icon{display:flex;align-items:center;justify-content:center;width:42px;height:42px;background:#2f8178;border-radius:50%;color:#fff;font-weight:700}
.profile-info{display:flex;flex-direction:column}.profile-info strong{font-size:15px;font-weight:600;color:#40514e}.profile-info span{margin-top:2px;color:#71817e;font-size:12px}
.back-row{margin-bottom:17px}.back-link{color:#2f8178;text-decoration:none;font-size:13px;font-weight:600}.back-link:hover{text-decoration:underline}
.success-message{padding:12px 15px;margin-bottom:18px;background:#eef7f2;border:1px solid #cfe6d9;border-radius:9px;color:#3e7159;font-size:13px}
.tenant-banner{display:flex;justify-content:space-between;align-items:center;gap:20px;padding:20px 22px;margin-bottom:18px;background:#fff;border:1px solid #dce5e2;border-radius:15px;box-shadow:0 3px 12px rgba(35,70,66,.04)}
.tenant-banner h2{font-size:21px;color:#40514e;font-weight:650;margin-bottom:5px}.tenant-banner p{font-size:13px;color:#71817e;line-height:1.6}
.property-info{text-align:right;font-size:13px;color:#52615f}.property-info strong{display:block;color:#40514e;font-size:15px;margin-bottom:3px}
.summary-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:22px}
.summary-card{background:#fff;border:1px solid #dce5e2;border-radius:14px;padding:19px 20px;box-shadow:0 3px 12px rgba(35,70,66,.04)}
.summary-label{display:block;margin-bottom:8px;color:#5f706d;font-size:12px}.summary-value{display:block;color:#40514e;font-size:26px;font-weight:600}.summary-note{display:block;margin-top:5px;color:#71817e;font-size:11px}
.balance-clear{color:#4d7665}.balance-due{color:#8d5c4d}
.section-card{margin-bottom:22px;background:#fff;border:1px solid #dce5e2;border-radius:15px;overflow:hidden;box-shadow:0 3px 12px rgba(35,70,66,.04)}
.section-header{display:flex;justify-content:space-between;align-items:center;gap:15px;padding:18px 21px;border-bottom:1px solid #e3ebe9;background:#fbfdfc}
.section-header h2{font-size:20px;color:#40514e;font-weight:650;margin-bottom:4px}.section-header p{font-size:12px;color:#71817e}
.button{display:inline-flex;align-items:center;justify-content:center;min-height:39px;padding:0 14px;border:0;border-radius:8px;background:#2f8178;color:#fff;font-size:12px;font-weight:600;text-decoration:none}.button:hover{background:#286f68}
.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse}
th{padding:12px 15px;background:#f7faf9;color:#5f706d;font-size:11px;text-align:left;text-transform:uppercase;letter-spacing:.3px;white-space:nowrap}
td{padding:14px 15px;border-top:1px solid #edf1f0;color:#4f5d5b;font-size:13px;vertical-align:middle}
.money{font-weight:600;color:#40514e;white-space:nowrap}
.status{display:inline-block;padding:5px 10px;border-radius:20px;font-size:10px;font-weight:700;white-space:nowrap}
.status-paid{background:#e8f6ee;color:#3f765d}.status-overdue{background:#fff0ed;color:#9a574b}.status-part{background:#fff6df;color:#876a32}.status-due{background:#eef2f2;color:#5d6d6a}
.empty{text-align:center;padding:32px;color:#71817e}
.footer{margin:30px -36px 0;padding:20px 30px;background:#183b3a;color:#c7d9d6;font-size:13px;text-align:center}.footer strong{color:#fff}
@media(max-width:1100px){.summary-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:760px){.sidebar{position:relative;width:100%;height:auto}.main{margin-left:0;padding:22px}.topbar,.tenant-banner,.section-header{align-items:flex-start;flex-direction:column}.property-info{text-align:left}.summary-grid{grid-template-columns:1fr}.footer{margin-left:-22px;margin-right:-22px}}
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

<div class="back-row"><a href="payments.php" class="back-link">← Back to Rent & Utilities</a></div>

<div class="topbar">
<div class="page-title"><h1>Tenant Financial Account</h1><p>Review rent charges, utility bills and outstanding balances.</p></div>
<a href="../profile.php" class="top-profile">
<div class="profile-icon"><?php echo htmlspecialchars($managerLetter); ?></div>
<div class="profile-info"><strong><?php echo htmlspecialchars($managerName); ?></strong><span><?php echo htmlspecialchars($jobTitle); ?></span></div>
</a>
</div>

<?php if($success!==''): ?>
<div class="success-message"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>

<div class="tenant-banner">
<div>
<h2><?php echo htmlspecialchars($tenantName); ?></h2>
<p><?php echo htmlspecialchars($tenant['email']); ?><br><?php echo htmlspecialchars($tenant['phone']?:'No phone recorded'); ?></p>
</div>
<div class="property-info">
<strong><?php echo htmlspecialchars($tenant['property_code']); ?></strong>
<?php echo htmlspecialchars($tenant['address_line1'].', '.$tenant['suburb'].', '.$tenant['state'].' '.$tenant['postcode']); ?>
</div>
</div>

<div class="summary-grid">
<div class="summary-card">
<span class="summary-label">Monthly Rent</span>
<span class="summary-value">$<?php echo number_format((float)$tenant['monthly_rent'],2); ?></span>
<span class="summary-note">Current lease amount</span>
</div>
<div class="summary-card">
<span class="summary-label">Rent Balance</span>
<span class="summary-value <?php echo $rentBalance>0?'balance-due':'balance-clear'; ?>">$<?php echo number_format($rentBalance,2); ?></span>
<span class="summary-note">Remaining rent charges</span>
</div>
<div class="summary-card">
<span class="summary-label">Utility Balance</span>
<span class="summary-value <?php echo $utilityBalance>0?'balance-due':'balance-clear'; ?>">$<?php echo number_format($utilityBalance,2); ?></span>
<span class="summary-note">Remaining utility bills</span>
</div>
<div class="summary-card">
<span class="summary-label">Overdue Items</span>
<span class="summary-value <?php echo $overdueItems>0?'balance-due':'balance-clear'; ?>"><?php echo $overdueItems; ?></span>
<span class="summary-note">Past due with balance remaining</span>
</div>
</div>

<div class="section-card">
<div class="section-header">
<div><h2>Rent History</h2><p>Rent charges and actual completed payments for the current lease.</p></div>
</div>
<div class="table-wrap">
<table>
<thead>
<tr><th>Rent Period</th><th>Due Date</th><th>Amount Due</th><th>Paid</th><th>Balance</th><th>Status</th></tr>
</thead>
<tbody>
<?php if(!empty($rentHistory)): ?>
<?php foreach($rentHistory as $rent): ?>
<tr>
<td><?php echo htmlspecialchars($rent['rent_period']); ?></td>
<td><?php echo date("d M Y",strtotime($rent['due_date'])); ?></td>
<td><span class="money">$<?php echo number_format((float)$rent['amount_due'],2); ?></span></td>
<td><span class="money">$<?php echo number_format((float)$rent['paid_amount'],2); ?></span></td>
<td><span class="money">$<?php echo number_format((float)$rent['balance'],2); ?></span></td>
<td><span class="status <?php echo statusClass($rent['display_status']); ?>"><?php echo htmlspecialchars($rent['display_status']); ?></span></td>
</tr>
<?php endforeach; ?>
<?php else: ?>
<tr><td colspan="6" class="empty">No rent charges found for this lease.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
</div>

<div class="section-card">
<div class="section-header">
<div><h2>Utility Bills</h2><p>Utility charges and completed payments for this tenant's current lease.</p></div>
<a href="add_utility_bill.php?tenant_id=<?php echo $tenantId; ?>" class="button">+ Add Utility Bill</a>
</div>
<div class="table-wrap">
<table>
<thead>
<tr><th>Utility</th><th>Provider</th><th>Billing Period</th><th>Due Date</th><th>Amount</th><th>Paid</th><th>Balance</th><th>Status</th></tr>
</thead>
<tbody>
<?php if(!empty($utilityBills)): ?>
<?php foreach($utilityBills as $bill): ?>
<tr>
<td><?php echo htmlspecialchars(niceText($bill['utility_type'])); ?></td>
<td><?php echo htmlspecialchars($bill['provider_name']?:'—'); ?></td>
<td><?php echo date("d M Y",strtotime($bill['billing_period_start'])); ?> – <?php echo date("d M Y",strtotime($bill['billing_period_end'])); ?></td>
<td><?php echo date("d M Y",strtotime($bill['due_date'])); ?></td>
<td><span class="money">$<?php echo number_format((float)$bill['amount_due'],2); ?></span></td>
<td><span class="money">$<?php echo number_format((float)$bill['paid_amount'],2); ?></span></td>
<td><span class="money">$<?php echo number_format((float)$bill['balance'],2); ?></span></td>
<td><span class="status <?php echo statusClass($bill['display_status']); ?>"><?php echo htmlspecialchars($bill['display_status']); ?></span></td>
</tr>
<?php endforeach; ?>
<?php else: ?>
<tr><td colspan="8" class="empty">No utility bills found for this lease.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
</div>

<footer class="footer"><strong>© 2026 RentEase Property Management System</strong>&nbsp; • &nbsp;Renting Made Easy.</footer>
</div>
</body>
</html>