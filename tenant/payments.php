<?php
session_start();
require_once __DIR__.'/../config/db.php';

// Tenant login check
if(!isset($_SESSION['user_id'])){
header("Location: ../index.php");
exit();
}

if(($_SESSION['role']??'')!=='tenant'){
header("Location: ../manager/dashboard.php");
exit();
}

$tenantId=(int)$_SESSION['user_id'];
$today=date('Y-m-d');

// Get tenant details
$stmt=$conn->prepare("
SELECT first_name,last_name,email
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
l.monthly_rent,
l.start_date,
l.end_date,
l.lease_status,
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
CASE
WHEN l.lease_status='active' THEN 1
WHEN l.lease_status='renewal_due' THEN 2
ELSE 3
END,
l.end_date DESC
LIMIT 1
");

if(!$stmt){
die("Lease query error: ".$conn->error);
}

$stmt->bind_param("i",$tenantId);
$stmt->execute();
$lease=$stmt->get_result()->fetch_assoc();

$rentCharges=[];
$utilityBills=[];
$rentHistory=[];
$utilityHistory=[];

if($lease){

$leaseId=(int)$lease['lease_id'];

// Rent charges
$stmt=$conn->prepare("
SELECT
rc.rent_charge_id,
rc.rent_period,
rc.due_date,
rc.amount_due,
rc.status,
COALESCE((
SELECT SUM(rp.amount_paid)
FROM rent_payments rp
WHERE rp.rent_charge_id=rc.rent_charge_id
AND rp.tenant_id=?
AND rp.payment_status='paid'
),0) AS total_paid
FROM rent_charges rc
WHERE rc.lease_id=?
ORDER BY rc.due_date DESC
");

if(!$stmt){
die("Rent query error: ".$conn->error);
}

$stmt->bind_param("ii",$tenantId,$leaseId);
$stmt->execute();
$result=$stmt->get_result();

while($row=$result->fetch_assoc()){

$amountDue=(float)$row['amount_due'];
$paidAmount=(float)$row['total_paid'];
$balance=max(0,$amountDue-$paidAmount);

// Display status is calculated only.
// Nothing is changed in the database.
if($balance<=0){
$displayStatus='paid';
}elseif($paidAmount>0){
$displayStatus='part_paid';
}elseif($row['due_date']<$today){
$displayStatus='overdue';
}else{
$displayStatus='due';
}

$row['total_paid']=$paidAmount;
$row['balance']=$balance;
$row['display_status']=$displayStatus;

$rentCharges[]=$row;
}

// Utility bills
$stmt=$conn->prepare("
SELECT
ub.utility_bill_id,
ub.utility_type,
ub.provider_name,
ub.billing_period_start,
ub.billing_period_end,
ub.due_date,
ub.amount_due,
ub.status,
COALESCE((
SELECT SUM(up.amount_paid)
FROM utility_payments up
WHERE up.utility_bill_id=ub.utility_bill_id
AND up.tenant_id=?
AND up.payment_status='paid'
),0) AS total_paid
FROM utility_bills ub
WHERE ub.lease_id=?
ORDER BY ub.due_date DESC
");

if(!$stmt){
die("Utility query error: ".$conn->error);
}

$stmt->bind_param("ii",$tenantId,$leaseId);
$stmt->execute();
$result=$stmt->get_result();

while($row=$result->fetch_assoc()){

$amountDue=(float)$row['amount_due'];
$paidAmount=(float)$row['total_paid'];
$balance=max(0,$amountDue-$paidAmount);

// Display status is calculated only.
// Nothing is changed in the database.
if($balance<=0){
$displayStatus='paid';
}elseif($paidAmount>0){
$displayStatus='part_paid';
}elseif($row['due_date']<$today){
$displayStatus='overdue';
}else{
$displayStatus='unpaid';
}

$row['total_paid']=$paidAmount;
$row['balance']=$balance;
$row['display_status']=$displayStatus;

$utilityBills[]=$row;
}

// Rent payment history
$stmt=$conn->prepare("
SELECT
rp.payment_id,
rp.amount_paid,
rp.submitted_at,
rp.payment_method,
rp.reference_number,
rp.payment_status,
rc.rent_period
FROM rent_payments rp
INNER JOIN rent_charges rc
ON rc.rent_charge_id=rp.rent_charge_id
WHERE rp.tenant_id=?
AND rc.lease_id=?
ORDER BY rp.submitted_at DESC,rp.payment_id DESC
");

if(!$stmt){
die("Rent history query error: ".$conn->error);
}

$stmt->bind_param("ii",$tenantId,$leaseId);
$stmt->execute();
$result=$stmt->get_result();

while($row=$result->fetch_assoc()){
$rentHistory[]=$row;
}

// Utility payment history
$stmt=$conn->prepare("
SELECT
up.utility_payment_id,
up.amount_paid,
up.submitted_at,
up.payment_method,
up.reference_number,
up.payment_status,
ub.utility_type
FROM utility_payments up
INNER JOIN utility_bills ub
ON ub.utility_bill_id=up.utility_bill_id
WHERE up.tenant_id=?
AND ub.lease_id=?
ORDER BY up.submitted_at DESC,up.utility_payment_id DESC
");

if(!$stmt){
die("Utility history query error: ".$conn->error);
}

$stmt->bind_param("ii",$tenantId,$leaseId);
$stmt->execute();
$result=$stmt->get_result();

while($row=$result->fetch_assoc()){
$utilityHistory[]=$row;
}
}

// Unread notifications
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

// Summary calculations
$totalOutstanding=0;
$totalPaid=0;

$overdueDate=null;
$overdueAmount=0;

$nextDueDate=null;
$nextDueAmount=0;

// Rent summary
foreach($rentCharges as $charge){

$totalOutstanding+=(float)$charge['balance'];
$totalPaid+=(float)$charge['total_paid'];

if($charge['balance']>0){

if($charge['due_date']<$today){

if($overdueDate===null||$charge['due_date']<$overdueDate){
$overdueDate=$charge['due_date'];
$overdueAmount=(float)$charge['balance'];
}

}else{

if($nextDueDate===null||$charge['due_date']<$nextDueDate){
$nextDueDate=$charge['due_date'];
$nextDueAmount=(float)$charge['balance'];
}

}

}
}

// Utility summary
foreach($utilityBills as $bill){

$totalOutstanding+=(float)$bill['balance'];
$totalPaid+=(float)$bill['total_paid'];

if($bill['balance']>0){

if($bill['due_date']<$today){

if($overdueDate===null||$bill['due_date']<$overdueDate){
$overdueDate=$bill['due_date'];
$overdueAmount=(float)$bill['balance'];
}

}else{

if($nextDueDate===null||$bill['due_date']<$nextDueDate){
$nextDueDate=$bill['due_date'];
$nextDueAmount=(float)$bill['balance'];
}

}

}
}

// Middle summary card
if($overdueDate!==null){

$summaryTitle='Overdue Payment';
$summaryDate=$overdueDate;
$summaryAmount=$overdueAmount;
$summaryMessage='overdue';
$summaryType='overdue';

}elseif($nextDueDate!==null){

$summaryTitle='Next Payment Due';
$summaryDate=$nextDueDate;
$summaryAmount=$nextDueAmount;
$summaryMessage='due';
$summaryType='normal';

}else{

$summaryTitle='Next Payment Due';
$summaryDate=null;
$summaryAmount=0;
$summaryMessage='';
$summaryType='paid';

}

function showDate($date){
if(!$date){
return '—';
}

return date('d M Y',strtotime($date));
}

function niceText($text){
return ucwords(str_replace('_',' ',$text??''));
}

function money($amount){
return '$'.number_format((float)$amount,2);
}

function statusClass($status){

$allowed=[
'paid',
'due',
'unpaid',
'overdue',
'upcoming',
'part_paid'
];

return in_array($status,$allowed,true)
?'status-'.$status
:'status-upcoming';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">

<title>Rent & Utilities | RentEase</title>

<style>
*{box-sizing:border-box}
body{margin:0;background:#f5f7f6;color:#243331;font-family:"Segoe UI",Arial,sans-serif;font-size:16px}
.sidebar{position:fixed;top:0;left:0;width:255px;height:100vh;padding:28px 18px;background:#183b3a;overflow-y:auto}
.brand{padding:0 12px 24px;border-bottom:1px solid rgba(255,255,255,.12)}
.brand-name{color:#fff;font-size:28px;font-weight:800}
.brand-name span{color:#80c7ba}
.brand-tagline{margin-top:4px;color:#b8d0cc;font-size:13px}
.menu-title{margin:23px 12px 8px;color:#8fb2ac;font-size:11px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase}
.sidebar a{display:flex;align-items:center;gap:12px;margin-bottom:4px;padding:12px 13px;border-radius:9px;color:#d8e5e2;text-decoration:none;font-size:15px;font-weight:500}
.sidebar a:hover{background:rgba(255,255,255,.08);color:#fff}
.sidebar a.active{background:#2f8178;color:#fff;font-weight:700}
.menu-icon{width:21px;text-align:center}
.notification-count{display:flex;align-items:center;justify-content:center;min-width:21px;height:21px;margin-left:auto;padding:0 6px;background:#80c7ba;color:#183b3a;border-radius:20px;font-size:11px;font-weight:700}
.logout-area{margin-top:25px;padding-top:15px;border-top:1px solid rgba(255,255,255,.12)}
.sidebar .logout{color:#f0c4c4}
.main-content{margin-left:255px;min-height:100vh;padding:35px 42px 25px}
.topbar{display:flex;justify-content:space-between;align-items:center;gap:20px;margin-bottom:25px}
.page-title h1{margin:0;color:#243331;font-size:32px;font-weight:700;letter-spacing:-.5px}
.page-title p{margin:7px 0 0;color:#687976;font-size:16px}
.user-box{display:flex;align-items:center;gap:11px;padding:9px 14px;background:#fff;border:1px solid #d9e3e0;border-radius:12px;color:#243331;text-decoration:none}
.small-avatar{display:flex;align-items:center;justify-content:center;width:42px;height:42px;border-radius:50%;background:#2f8178;color:#fff;font-size:17px;font-weight:700}
.user-box strong{display:block;font-size:15px;font-weight:600}
.user-box span{display:block;margin-top:2px;color:#71817e;font-size:13px}
.rental-banner{display:flex;justify-content:space-between;align-items:center;gap:20px;margin-bottom:22px;padding:24px 27px;background:#2f8178;border-radius:15px;color:#fff}
.banner-label{margin-bottom:5px;color:#d9efeb;font-size:12px;font-weight:600;letter-spacing:.8px;text-transform:uppercase}
.rental-banner h2{margin:0;font-size:22px;font-weight:600}
.rental-banner p{margin:6px 0 0;color:#e3f1ee;font-size:14px}
.banner-badge{padding:8px 14px;background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.28);border-radius:20px;font-size:13px;font-weight:600}
.summary-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:18px;margin-bottom:22px}
.summary-card{padding:20px;background:#fff;border:1px solid #dce5e2;border-radius:14px;box-shadow:0 5px 18px rgba(37,64,60,.04)}
.summary-label{margin-bottom:9px;color:#71817e;font-size:12px;font-weight:600;letter-spacing:.4px;text-transform:uppercase}
.summary-value{color:#40514e;font-size:19px;font-weight:600}
.summary-note{margin-top:6px;color:#83908e;font-size:12px}
.summary-card.overdue-summary{background:#fffafa;border-color:#efd6d6}
.summary-card.overdue-summary .summary-label{color:#a84545}
.summary-card.overdue-summary .summary-value{color:#8f3c3c}
.summary-card.overdue-summary .summary-note{color:#a84545}
.card{margin-bottom:22px;background:#fff;border:1px solid #dce5e2;border-radius:15px;overflow:hidden;box-shadow:0 6px 22px rgba(37,64,60,.05)}
.card-header{padding:21px 25px 17px;border-bottom:1px solid #e4e9e7}
.card-header h2{margin:0;color:#263936;font-size:21px;font-weight:600}
.card-header p{margin:5px 0 0;color:#71817e;font-size:14px}
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse}
th{padding:13px 18px;background:#f5f8f7;color:#687976;text-align:left;font-size:12px;font-weight:600;text-transform:uppercase;white-space:nowrap}
td{padding:15px 18px;border-top:1px solid #edf1ef;color:#465754;font-size:14px;vertical-align:middle}
.amount{color:#40514e;font-weight:600}
.status{display:inline-block;padding:6px 10px;border-radius:20px;font-size:12px;font-weight:600}
.status-paid{background:#e8f5ee;color:#287a55}
.status-due,.status-unpaid{background:#fff4d8;color:#946319}
.status-overdue{background:#fae8e8;color:#a84545}
.status-upcoming{background:#edf3f2;color:#5f706d}
.status-part_paid{background:#eef2ff;color:#59658c}
.btn{display:inline-block;padding:9px 14px;background:#2f8178;border:none;border-radius:8px;color:#fff;font-family:inherit;font-size:13px;font-weight:600;text-decoration:none;cursor:pointer;white-space:nowrap}
.btn:hover{background:#286f68}
.paid-text{color:#287a55;font-size:13px;font-weight:600}
.history-grid{display:grid;grid-template-columns:1fr 1fr;gap:22px}
.history-item{padding:17px 20px;border-bottom:1px solid #edf1ef}
.history-item:last-child{border-bottom:none}
.history-top{display:flex;justify-content:space-between;gap:15px;margin-bottom:6px}
.history-title{color:#344744;font-size:14px;font-weight:600}
.history-amount{color:#40514e;font-size:14px;font-weight:600}
.history-meta{color:#71817e;font-size:12px;line-height:1.6}
.empty{padding:35px;text-align:center;color:#71817e;font-size:14px}
.footer{margin-top:32px;margin-left:-42px;margin-right:-42px;margin-bottom:-25px;padding:20px 25px;background:#2f8178;color:#fff;text-align:center;font-size:14px}
.footer strong{color:#fff;font-weight:700}

@media(max-width:1050px){
.summary-grid{grid-template-columns:1fr}
.history-grid{grid-template-columns:1fr}
}

@media(max-width:760px){
.sidebar{position:relative;width:100%;height:auto}
.main-content{margin-left:0;padding:25px 18px}
.user-box{display:none}
.rental-banner{flex-direction:column;align-items:flex-start}
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

<a href="dashboard.php">
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

<a href="payments.php" class="active">
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
<span class="notification-count">
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
<h1>Rent & Utilities</h1>
<p>Manage your rent, utility bills and payment history.</p>
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

<div class="rental-banner">

<div>

<div class="banner-label">
Current Rental
</div>

<h2>
<?php echo htmlspecialchars($lease['address_line1']); ?>
</h2>

<p>
<?php echo htmlspecialchars($lease['suburb'].', '.$lease['state'].' '.$lease['postcode']); ?>
</p>

</div>

<div class="banner-badge">
<?php echo htmlspecialchars($lease['property_code']); ?>
</div>

</div>

<div class="summary-grid">

<div class="summary-card">

<div class="summary-label">
Total Outstanding
</div>

<div class="summary-value">
<?php echo money($totalOutstanding); ?>
</div>

<div class="summary-note">
Current unpaid rent and utilities
</div>

</div>

<div class="summary-card <?php echo $summaryType==='overdue'?'overdue-summary':''; ?>">

<div class="summary-label">
<?php echo htmlspecialchars($summaryTitle); ?>
</div>

<div class="summary-value">

<?php if($summaryDate): ?>

<?php echo showDate($summaryDate); ?>

<?php else: ?>

Nothing Due

<?php endif; ?>

</div>

<div class="summary-note">

<?php if($summaryType==='overdue'): ?>

<?php echo money($summaryAmount); ?> overdue

<?php elseif($summaryType==='normal'): ?>

<?php echo money($summaryAmount); ?> due

<?php else: ?>

Your current charges are paid

<?php endif; ?>

</div>

</div>

<div class="summary-card">

<div class="summary-label">
Total Paid
</div>

<div class="summary-value">
<?php echo money($totalPaid); ?>
</div>

<div class="summary-note">
Total value of successful payments
</div>

</div>

</div>

<div class="card">

<div class="card-header">
<h2>Rent</h2>
<p>Your rent charges for the current tenancy.</p>
</div>

<?php if($rentCharges): ?>

<div class="table-wrap">

<table>

<thead>

<tr>
<th>Period</th>
<th>Due Date</th>
<th>Amount</th>
<th>Status</th>
<th>Action</th>
</tr>

</thead>

<tbody>

<?php foreach($rentCharges as $charge): ?>

<tr>

<td>
<?php echo htmlspecialchars(date('F Y',strtotime($charge['rent_period'].'-01'))); ?>
</td>

<td>
<?php echo showDate($charge['due_date']); ?>
</td>

<td class="amount">
<?php echo money($charge['amount_due']); ?>
</td>

<td>

<span class="status <?php echo statusClass($charge['display_status']); ?>">
<?php echo htmlspecialchars(niceText($charge['display_status'])); ?>
</span>

</td>

<td>

<?php if($charge['display_status']!=='paid'): ?>

<a href="make_payment.php?type=rent&id=<?php echo (int)$charge['rent_charge_id']; ?>" class="btn">
Pay Now
</a>

<?php else: ?>

<span class="paid-text">
Paid
</span>

<?php endif; ?>

</td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>

<?php else: ?>

<div class="empty">
No rent charges are currently available.
</div>

<?php endif; ?>

</div>

<div class="card">

<div class="card-header">
<h2>Utility Bills</h2>
<p>View electricity, water, internet and other utility charges.</p>
</div>

<?php if($utilityBills): ?>

<div class="table-wrap">

<table>

<thead>

<tr>
<th>Utility</th>
<th>Provider</th>
<th>Due Date</th>
<th>Amount</th>
<th>Status</th>
<th>Action</th>
</tr>

</thead>

<tbody>

<?php foreach($utilityBills as $bill): ?>

<tr>

<td>
<?php echo htmlspecialchars(niceText($bill['utility_type'])); ?>
</td>

<td>
<?php echo htmlspecialchars($bill['provider_name']?:'—'); ?>
</td>

<td>
<?php echo showDate($bill['due_date']); ?>
</td>

<td class="amount">
<?php echo money($bill['amount_due']); ?>
</td>

<td>

<span class="status <?php echo statusClass($bill['display_status']); ?>">
<?php echo htmlspecialchars(niceText($bill['display_status'])); ?>
</span>

</td>

<td>

<?php if($bill['display_status']!=='paid'): ?>

<a href="make_payment.php?type=utility&id=<?php echo (int)$bill['utility_bill_id']; ?>" class="btn">
Pay Now
</a>

<?php else: ?>

<span class="paid-text">
Paid
</span>

<?php endif; ?>

</td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>

<?php else: ?>

<div class="empty">
No utility bills are currently available.
</div>

<?php endif; ?>

</div>

<div class="history-grid">

<div class="card">

<div class="card-header">
<h2>Rent Payment History</h2>
<p>Payments recorded through RentEase.</p>
</div>

<?php if($rentHistory): ?>

<?php foreach($rentHistory as $payment): ?>

<div class="history-item">

<div class="history-top">

<div class="history-title">
<?php echo htmlspecialchars(date('F Y',strtotime($payment['rent_period'].'-01'))); ?>
</div>

<div class="history-amount">
<?php echo money($payment['amount_paid']); ?>
</div>

</div>

<div class="history-meta">

Paid <?php echo showDate($payment['submitted_at']); ?>

<br>

Reference:
<?php echo htmlspecialchars($payment['reference_number']?:'—'); ?>

</div>

</div>

<?php endforeach; ?>

<?php else: ?>

<div class="empty">
No RentEase rent payment records are available yet.
</div>

<?php endif; ?>

</div>

<div class="card">

<div class="card-header">
<h2>Utility Payment History</h2>
<p>Utility payments recorded through RentEase.</p>
</div>

<?php if($utilityHistory): ?>

<?php foreach($utilityHistory as $payment): ?>

<div class="history-item">

<div class="history-top">

<div class="history-title">
<?php echo htmlspecialchars(niceText($payment['utility_type'])); ?>
</div>

<div class="history-amount">
<?php echo money($payment['amount_paid']); ?>
</div>

</div>

<div class="history-meta">

Paid <?php echo showDate($payment['submitted_at']); ?>

<br>

Reference:
<?php echo htmlspecialchars($payment['reference_number']?:'—'); ?>

</div>

</div>

<?php endforeach; ?>

<?php else: ?>

<div class="empty">
No RentEase utility payment records are available yet.
</div>

<?php endif; ?>

</div>

</div>

<?php else: ?>

<div class="card">

<div class="empty">

<strong>No active lease found.</strong>

<br><br>

Rent and utility information will appear after a lease is assigned to your account.

</div>

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