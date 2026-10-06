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
$error='';

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

// Tenant can already be selected from tenant_finances.php
$selectedTenantId=(int)($_GET['tenant_id']??$_POST['tenant_id']??0);

// Get tenants with a current lease
$tenants=[];

$result=$conn->query("SELECT
u.user_id,
u.first_name,
u.last_name,
u.email,
l.lease_id,
p.property_code,
p.address_line1,
p.suburb
FROM users u
INNER JOIN leases l ON l.tenant_id=u.user_id
INNER JOIN properties p ON p.property_id=l.property_id
WHERE u.role='tenant'
AND u.account_status='active'
AND l.lease_status IN('active','renewal_due')
ORDER BY u.first_name,u.last_name");

if($result){
while($row=$result->fetch_assoc()){
$tenants[]=$row;
}
}

// Add utility bill
if($_SERVER['REQUEST_METHOD']==='POST'){

$tenantId=(int)($_POST['tenant_id']??0);
$utilityType=trim($_POST['utility_type']??'');
$providerName=trim($_POST['provider_name']??'');
$billingStart=trim($_POST['billing_period_start']??'');
$billingEnd=trim($_POST['billing_period_end']??'');
$dueDate=trim($_POST['due_date']??'');
$amountDue=(float)($_POST['amount_due']??0);

$allowedTypes=['electricity','gas','water','internet','other'];

if($tenantId<=0){
$error="Please select a tenant.";
}elseif(!in_array($utilityType,$allowedTypes,true)){
$error="Please select a valid utility type.";
}elseif($billingStart==='' || $billingEnd==='' || $dueDate===''){
$error="Please complete all required dates.";
}elseif($billingEnd<$billingStart){
$error="Billing period end date cannot be before the start date.";
}elseif($dueDate<$billingEnd){
$error="Due date cannot be before the billing period ends.";
}elseif($amountDue<=0){
$error="Amount due must be greater than $0.";
}else{

// Find tenant's current lease
$stmt=$conn->prepare("SELECT
l.lease_id,
u.first_name,
u.last_name
FROM leases l
INNER JOIN users u ON u.user_id=l.tenant_id
WHERE l.tenant_id=?
AND u.role='tenant'
AND l.lease_status IN('active','renewal_due')
ORDER BY l.start_date DESC
LIMIT 1");

$stmt->bind_param("i",$tenantId);
$stmt->execute();
$currentLease=$stmt->get_result()->fetch_assoc();

if(!$currentLease){
$error="This tenant does not have a current lease.";
}else{

$leaseId=(int)$currentLease['lease_id'];
$tenantName=$currentLease['first_name'].' '.$currentLease['last_name'];

try{
$conn->begin_transaction();

// New utility bill starts unpaid
$status='unpaid';

$stmt=$conn->prepare("INSERT INTO utility_bills
(lease_id,utility_type,provider_name,billing_period_start,billing_period_end,due_date,amount_due,status,created_by)
VALUES(?,?,?,?,?,?,?,?,?)");

if(!$stmt){
throw new Exception($conn->error);
}

// i = lease ID
// s = utility type
// s = provider
// s = billing start
// s = billing end
// s = due date
// d = amount
// s = status
// i = manager ID
$stmt->bind_param(
"isssssdsi",
$leaseId,
$utilityType,
$providerName,
$billingStart,
$billingEnd,
$dueDate,
$amountDue,
$status,
$managerId
);

if(!$stmt->execute()){
throw new Exception($stmt->error);
}

$billId=$conn->insert_id;

// Add manager action to activity log
$utilityName=ucwords(str_replace('_',' ',$utilityType));

$description="Added ".$utilityName.
" utility bill of $".number_format($amountDue,2).
" for ".$tenantName.".";

$stmt=$conn->prepare("INSERT INTO activity_log
(user_id,action_type,entity_type,entity_id,description)
VALUES(?,'ADD_UTILITY_BILL','utility_bill',?,?)");

if($stmt){
$stmt->bind_param("iis",$managerId,$billId,$description);
$stmt->execute();
}

$conn->commit();

// Show message once after redirect
$_SESSION['payment_flash']="Utility bill added successfully.";

header("Location: tenant_finances.php?id=".$tenantId);
exit();

}catch(Throwable $e){
$conn->rollback();
$error="Unable to add the utility bill.";
}

}
}
}

// Keep form information after validation error
function oldValue($name,$default=''){
return htmlspecialchars($_POST[$name]??$default);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Add Utility Bill | RentEase</title>
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
.back-row{margin-bottom:18px}
.back-link{color:#2f8178;font-size:13px;font-weight:600;text-decoration:none}
.back-link:hover{text-decoration:underline}
.topbar{display:flex;align-items:center;justify-content:space-between;gap:20px;margin-bottom:22px}
.page-title h1{font-size:32px;font-weight:650;color:#344441;margin-bottom:5px}
.page-title p{font-size:15px;color:#71817e}
.top-profile{display:flex;align-items:center;gap:11px;min-width:215px;padding:10px 14px;background:#fff;border:1px solid #dce5e2;border-radius:12px;color:#4f5d5b;text-decoration:none}
.profile-icon{display:flex;align-items:center;justify-content:center;width:42px;height:42px;background:#2f8178;border-radius:50%;color:#fff;font-weight:700}
.profile-info{display:flex;flex-direction:column}
.profile-info strong{font-size:15px;font-weight:600;color:#40514e}
.profile-info span{margin-top:2px;color:#71817e;font-size:12px}
.error-message{padding:12px 15px;margin-bottom:18px;background:#fff0ed;border:1px solid #efd2cc;border-radius:9px;color:#945247;font-size:13px}
.form-card{max-width:1000px;margin:0 auto 25px;background:#fff;border:1px solid #dce5e2;border-radius:15px;overflow:hidden;box-shadow:0 3px 12px rgba(35,70,66,.04)}
.form-header{padding:20px 23px;border-bottom:1px solid #e3ebe9;background:#fbfdfc}
.form-header h2{font-size:20px;color:#40514e;font-weight:650;margin-bottom:4px}
.form-header p{font-size:12px;color:#71817e}
.form-body{padding:24px}
.form-section{margin-bottom:27px}
.form-section:last-child{margin-bottom:0}
.form-section h3{font-size:15px;color:#40514e;font-weight:650;padding-bottom:9px;margin-bottom:16px;border-bottom:1px solid #edf1f0}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}
.full{grid-column:1/-1}
.form-group label{display:block;margin-bottom:7px;color:#52615f;font-size:12px;font-weight:600}
.required{color:#9a574b}
.form-control{width:100%;height:43px;padding:10px 12px;border:1px solid #ccd9d6;border-radius:8px;background:#fff;color:#4f5d5b;font-family:inherit;font-size:13px;outline:none}
.form-control:focus{border-color:#2f8178;box-shadow:0 0 0 3px rgba(47,129,120,.08)}
.help{display:block;margin-top:5px;color:#71817e;font-size:11px}
.form-actions{display:flex;justify-content:flex-end;gap:10px;padding:18px 24px;border-top:1px solid #e3ebe9;background:#fbfdfc}
.button{display:inline-flex;align-items:center;justify-content:center;min-height:41px;padding:0 17px;border:0;border-radius:8px;background:#2f8178;color:#fff;font-family:inherit;font-size:13px;font-weight:600;text-decoration:none;cursor:pointer}
.button:hover{background:#286f68}
.secondary-button{background:#fff;border:1px solid #ccd9d6;color:#52615f}
.secondary-button:hover{background:#f5f8f7}
.footer{margin:30px -36px 0;padding:20px 30px;background:#183b3a;color:#c7d9d6;font-size:13px;text-align:center}
.footer strong{color:#fff}
@media(max-width:760px){
.sidebar{position:relative;width:100%;height:auto}
.main{margin-left:0;padding:22px}
.topbar{align-items:flex-start;flex-direction:column}
.form-grid{grid-template-columns:1fr}
.full{grid-column:auto}
.form-actions{flex-direction:column}
.button{width:100%}
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
<h1>Add Utility Bill</h1>
<p>Add a utility charge to a tenant's current lease.</p>
</div>

<a href="../profile.php" class="top-profile">
<div class="profile-icon"><?php echo htmlspecialchars($managerLetter); ?></div>
<div class="profile-info">
<strong><?php echo htmlspecialchars($managerName); ?></strong>
<span><?php echo htmlspecialchars($jobTitle); ?></span>
</div>
</a>

</div>

<?php if($error!==''): ?>
<div class="error-message">
<?php echo htmlspecialchars($error); ?>
</div>
<?php endif; ?>

<form method="POST" class="form-card">

<div class="form-header">
<h2>Utility Bill Details</h2>
<p>Enter the utility charge information for the tenant.</p>
</div>

<div class="form-body">

<div class="form-section">

<h3>Tenant & Utility</h3>

<div class="form-grid">

<div class="form-group full">

<label>Tenant <span class="required">*</span></label>

<select name="tenant_id" class="form-control" required>

<option value="">Select tenant</option>

<?php foreach($tenants as $tenant): ?>
<?php
$currentTenant=(int)($_POST['tenant_id']??$selectedTenantId);
?>

<option value="<?php echo (int)$tenant['user_id']; ?>"
<?php echo $currentTenant===(int)$tenant['user_id']?'selected':''; ?>>

<?php
echo htmlspecialchars(
$tenant['first_name'].' '.
$tenant['last_name'].' — '.
$tenant['property_code'].' — '.
$tenant['address_line1'].', '.
$tenant['suburb']
);
?>

</option>

<?php endforeach; ?>

</select>

<span class="help">
Only tenants with a current active lease are available.
</span>

</div>

<div class="form-group">

<label>Utility Type <span class="required">*</span></label>

<select name="utility_type" class="form-control" required>

<option value="">Select utility</option>

<option value="electricity"
<?php echo oldValue('utility_type')==='electricity'?'selected':''; ?>>
Electricity
</option>

<option value="gas"
<?php echo oldValue('utility_type')==='gas'?'selected':''; ?>>
Gas
</option>

<option value="water"
<?php echo oldValue('utility_type')==='water'?'selected':''; ?>>
Water
</option>

<option value="internet"
<?php echo oldValue('utility_type')==='internet'?'selected':''; ?>>
Internet
</option>

<option value="other"
<?php echo oldValue('utility_type')==='other'?'selected':''; ?>>
Other
</option>

</select>

</div>

<div class="form-group">

<label>Provider</label>

<input
type="text"
name="provider_name"
class="form-control"
value="<?php echo oldValue('provider_name'); ?>"
placeholder="Example: Origin Energy">

</div>

</div>
</div>

<div class="form-section">

<h3>Billing Information</h3>

<div class="form-grid">

<div class="form-group">

<label>Billing Period Start <span class="required">*</span></label>

<input
type="date"
name="billing_period_start"
class="form-control"
value="<?php echo oldValue('billing_period_start'); ?>"
required>

</div>

<div class="form-group">

<label>Billing Period End <span class="required">*</span></label>

<input
type="date"
name="billing_period_end"
class="form-control"
value="<?php echo oldValue('billing_period_end'); ?>"
required>

</div>

<div class="form-group">

<label>Due Date <span class="required">*</span></label>

<input
type="date"
name="due_date"
class="form-control"
value="<?php echo oldValue('due_date'); ?>"
required>

</div>

<div class="form-group">

<label>Amount Due ($) <span class="required">*</span></label>

<input
type="number"
name="amount_due"
class="form-control"
value="<?php echo oldValue('amount_due'); ?>"
min="0.01"
step="0.01"
placeholder="0.00"
required>

</div>

</div>
</div>

</div>

<div class="form-actions">

<a href="payments.php" class="button secondary-button">
Cancel
</a>

<button type="submit" class="button">
Add Utility Bill
</button>

</div>

</form>

<footer class="footer">
<strong>© 2026 RentEase Property Management System</strong>
&nbsp; • &nbsp;
Renting Made Easy.
</footer>

</div>

</body>
</html>