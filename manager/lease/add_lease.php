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

// Active tenants
$tenants=$conn->query("
SELECT user_id,first_name,last_name,email
FROM users
WHERE role='tenant'
AND account_status='active'
ORDER BY first_name,last_name
");

// Properties available for a new lease
$properties=$conn->query("
SELECT property_id,property_code,address_line1,suburb,state,postcode,weekly_rent
FROM properties
WHERE property_status='vacant'
ORDER BY property_code
");

// Form values
$tenantId=(int)($_POST['tenant_id']??0);
$propertyId=(int)($_POST['property_id']??0);
$startDate=trim($_POST['start_date']??'');
$endDate=trim($_POST['end_date']??'');
$monthlyRent=trim($_POST['monthly_rent']??'');
$bondAmount=trim($_POST['bond_amount']??'');
$leaseStatus=trim($_POST['lease_status']??'active');
$notes=trim($_POST['notes']??'');

$allowedStatuses=['active','renewal_due'];

if($_SERVER['REQUEST_METHOD']==='POST'){

if($tenantId<=0){
$error="Please select a tenant.";
}elseif($propertyId<=0){
$error="Please select a property.";
}elseif($startDate==='' || $endDate===''){
$error="Please enter the lease start and end dates.";
}elseif(strtotime($endDate)<=strtotime($startDate)){
$error="Lease end date must be after the start date.";
}elseif($monthlyRent==='' || !is_numeric($monthlyRent) || (float)$monthlyRent<=0){
$error="Please enter a valid monthly rent.";
}elseif($bondAmount!=='' && (!is_numeric($bondAmount) || (float)$bondAmount<0)){
$error="Please enter a valid bond amount.";
}elseif(!in_array($leaseStatus,$allowedStatuses,true)){
$error="Please select a valid lease status.";
}else{

$monthlyRent=(float)$monthlyRent;
$bondAmount=$bondAmount===''?0:(float)$bondAmount;

// Confirm tenant exists
$stmt=$conn->prepare("
SELECT user_id
FROM users
WHERE user_id=?
AND role='tenant'
AND account_status='active'
LIMIT 1
");
$stmt->bind_param("i",$tenantId);
$stmt->execute();

if(!$stmt->get_result()->fetch_assoc()){
$error="Selected tenant is not available.";
}else{

// Confirm property is still vacant
$stmt=$conn->prepare("
SELECT property_id
FROM properties
WHERE property_id=?
AND property_status='vacant'
LIMIT 1
");
$stmt->bind_param("i",$propertyId);
$stmt->execute();

if(!$stmt->get_result()->fetch_assoc()){
$error="Selected property is no longer available.";
}else{

try{
$conn->begin_transaction();

// Create lease
$stmt=$conn->prepare("
INSERT INTO leases
(property_id,tenant_id,start_date,end_date,monthly_rent,bond_amount,lease_status,notes)
VALUES(?,?,?,?,?,?,?,?)
");

if(!$stmt){
throw new Exception("Could not prepare lease record.");
}

$stmt->bind_param(
"iissddss",
$propertyId,
$tenantId,
$startDate,
$endDate,
$monthlyRent,
$bondAmount,
$leaseStatus,
$notes
);

if(!$stmt->execute()){
throw new Exception("Could not create lease.");
}

$newLeaseId=$conn->insert_id;

// Property becomes occupied
$stmt=$conn->prepare("
UPDATE properties
SET property_status='occupied'
WHERE property_id=?
AND property_status='vacant'
");
$stmt->bind_param("i",$propertyId);

if(!$stmt->execute() || $stmt->affected_rows!==1){
throw new Exception("Property is no longer available.");
}

// Activity log
$description="Created Lease #".$newLeaseId.".";
$stmt=$conn->prepare("
INSERT INTO activity_log
(user_id,action_type,entity_type,entity_id,description)
VALUES(?,'CREATE_LEASE','lease',?,?)
");

if($stmt){
$stmt->bind_param("iis",$managerId,$newLeaseId,$description);
$stmt->execute();
}

$conn->commit();

$_SESSION['lease_flash']="Lease created successfully.";
header("Location: view_lease.php?id=".$newLeaseId);
exit();

}catch(Throwable $e){
$conn->rollback();
$error="Lease could not be created. ".$e->getMessage();
}

}

}

}

}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Create Lease | RentEase</title>

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
.back-link{display:inline-block;margin-bottom:18px;color:#2f8178;font-size:14px;font-weight:600;text-decoration:none}
.back-link:hover{color:#286f68}
.message{max-width:1050px;padding:12px 15px;margin-bottom:18px;border-radius:9px;font-size:13px}
.error-message{background:#fff0f0;border:1px solid #e8c5c5;color:#934a4a}
.form-card{max-width:1050px;background:#fff;border:1px solid #dce5e2;border-radius:15px;overflow:hidden;box-shadow:0 3px 12px rgba(35,70,66,.04)}
.card-header{padding:18px 22px;border-bottom:1px solid #e3ebe9;background:#fbfdfc}
.card-header h2{font-size:21px;font-weight:650;margin-bottom:4px}
.card-header p{font-size:13px;color:#71817e}
.card-body{padding:22px}
.form-section{padding-bottom:22px;margin-bottom:22px;border-bottom:1px solid #e3ebe9}
.form-section:last-of-type{border-bottom:0;margin-bottom:0}
.form-section h3{font-size:17px;font-weight:650;margin-bottom:4px;color:#40514e}
.form-section>p{margin-bottom:16px;color:#82918e;font-size:12px}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px 20px}
.form-group.full{grid-column:1/-1}
.form-group label{display:block;margin-bottom:6px;color:#40514e;font-size:13px;font-weight:600}
input,select,textarea{width:100%;padding:11px 12px;border:1px solid #ccd9d6;border-radius:8px;background:#fff;color:#40514e;font-family:inherit;font-size:14px;outline:none}
input,select{height:44px}
textarea{min-height:100px;resize:vertical}
input:focus,select:focus,textarea:focus{border-color:#2f8178;box-shadow:0 0 0 3px rgba(47,129,120,.08)}
.help{display:block;margin-top:5px;color:#82918e;font-size:11px}
.form-actions{display:flex;justify-content:flex-end;gap:10px;padding-top:20px;border-top:1px solid #e3ebe9}
.button{display:inline-flex;align-items:center;justify-content:center;height:42px;padding:0 18px;border-radius:8px;font-family:inherit;font-size:14px;font-weight:600;text-decoration:none;cursor:pointer}
.cancel-button{border:1px solid #ccd9d6;background:#fff;color:#40514e}
.save-button{border:0;background:#2f8178;color:#fff}
.save-button:hover{background:#286f68}
.footer{margin:30px -36px 0;padding:20px 30px;background:#183b3a;color:#c7d9d6;font-size:13px;text-align:center}
.footer strong{color:#fff}
@media(max-width:800px){
.sidebar{position:relative;width:100%;height:auto}
.main{margin-left:0;padding:22px}
.topbar{align-items:flex-start;flex-direction:column}
.form-grid{grid-template-columns:1fr}
.form-group.full{grid-column:auto}
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
<h1>Create Lease</h1>
<p>Create a new rental agreement for a tenant and property.</p>
</div>

<a href="../profile.php" class="top-profile">
<div class="profile-icon"><?php echo htmlspecialchars($profileLetter); ?></div>
<div class="profile-info">
<strong><?php echo htmlspecialchars($fullName); ?></strong>
<span><?php echo htmlspecialchars($jobTitle); ?></span>
</div>
</a>
</div>

<a href="leases.php" class="back-link">← Back to Leases</a>

<?php if($error!==''): ?>
<div class="message error-message"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="form-card">

<div class="card-header">
<h2>New Lease Agreement</h2>
<p>Select the tenant and property, then enter the agreement details.</p>
</div>

<form method="POST">
<div class="card-body">

<div class="form-section">
<h3>Tenant & Property</h3>
<p>Choose who the agreement is for and which property they will occupy.</p>

<div class="form-grid">

<div class="form-group">
<label>Tenant *</label>
<select name="tenant_id" required>
<option value="">Select tenant</option>
<?php while($tenant=$tenants->fetch_assoc()): ?>
<option value="<?php echo (int)$tenant['user_id']; ?>" <?php echo $tenantId===(int)$tenant['user_id']?'selected':''; ?>>
<?php echo htmlspecialchars($tenant['first_name'].' '.$tenant['last_name'].' — '.$tenant['email']); ?>
</option>
<?php endwhile; ?>
</select>
</div>

<div class="form-group">
<label>Property *</label>
<select name="property_id" id="property_id" required>
<option value="">Select vacant property</option>
<?php while($property=$properties->fetch_assoc()): ?>
<option
value="<?php echo (int)$property['property_id']; ?>"
data-weekly="<?php echo htmlspecialchars($property['weekly_rent']); ?>"
<?php echo $propertyId===(int)$property['property_id']?'selected':''; ?>
>
<?php echo htmlspecialchars($property['property_code'].' — '.$property['address_line1'].', '.$property['suburb']); ?>
</option>
<?php endwhile; ?>
</select>
<span class="help">Only vacant properties are available for a new lease.</span>
</div>

</div>
</div>

<div class="form-section">
<h3>Lease Period & Finance</h3>
<p>Enter the agreement dates and rental amounts.</p>

<div class="form-grid">

<div class="form-group">
<label>Start Date *</label>
<input type="date" name="start_date" value="<?php echo htmlspecialchars($startDate); ?>" required>
</div>

<div class="form-group">
<label>End Date *</label>
<input type="date" name="end_date" value="<?php echo htmlspecialchars($endDate); ?>" required>
</div>

<div class="form-group">
<label>Monthly Rent ($) *</label>
<input type="number" name="monthly_rent" step="0.01" min="0" value="<?php echo htmlspecialchars((string)$monthlyRent); ?>" required>
</div>

<div class="form-group">
<label>Bond Amount ($)</label>
<input type="number" name="bond_amount" step="0.01" min="0" value="<?php echo htmlspecialchars((string)$bondAmount); ?>">
</div>

<div class="form-group">
<label>Lease Status *</label>
<select name="lease_status" required>
<option value="active" <?php echo $leaseStatus==='active'?'selected':''; ?>>Active</option>
<option value="renewal_due" <?php echo $leaseStatus==='renewal_due'?'selected':''; ?>>Renewal Due</option>
</select>
</div>

</div>
</div>

<div class="form-section">
<h3>Additional Notes</h3>
<p>Optional internal information about this agreement.</p>

<div class="form-group full">
<label>Notes</label>
<textarea name="notes" placeholder="Add any relevant lease notes..."><?php echo htmlspecialchars($notes); ?></textarea>
</div>
</div>

<div class="form-actions">
<a href="leases.php" class="button cancel-button">Cancel</a>
<button type="submit" class="button save-button">Create Lease</button>
</div>

</div>
</form>

</div>

<footer class="footer">
<strong>© 2026 RentEase Property Management System</strong>
&nbsp; • &nbsp;
Renting Made Easy.
</footer>

</div>
</body>
</html>