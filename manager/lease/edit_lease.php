<?php
session_start();
require_once __DIR__.'/../../config/db.php';

if(!isset($_SESSION['user_id'])){
header("Location: ../../index.php");
exit();
}
if(($_SESSION['role']??'')!=='manager'){
header("Location: ../../tenant/dashboard.php");
exit();
}

$managerId=(int)$_SESSION['user_id'];
$leaseId=(int)($_GET['id']??0);
$error='';

if($leaseId<=0){
header("Location: leases.php");
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
$stmt->bind_param("i",$managerId);
$stmt->execute();
$manager=$stmt->get_result()->fetch_assoc();

$firstName=$manager['first_name']??'Manager';
$lastName=$manager['last_name']??'';
$fullName=trim($firstName.' '.$lastName);
$profileLetter=strtoupper(substr($firstName,0,1));
$jobTitle=!empty($manager['job_title'])?$manager['job_title']:'Property Manager';

// Load lease
function getLease($conn,$leaseId){
$stmt=$conn->prepare("
SELECT
l.*,
u.first_name,
u.last_name,
u.email,
p.property_code,
p.address_line1,
p.suburb,
p.state,
p.postcode
FROM leases l
INNER JOIN users u ON l.tenant_id=u.user_id
INNER JOIN properties p ON l.property_id=p.property_id
WHERE l.lease_id=?
LIMIT 1
");
$stmt->bind_param("i",$leaseId);
$stmt->execute();
return $stmt->get_result()->fetch_assoc();
}

$lease=getLease($conn,$leaseId);

if(!$lease){
header("Location: leases.php");
exit();
}

$allowedStatuses=['active','renewal_due','expired','terminated'];

if($_SERVER['REQUEST_METHOD']==='POST'){

$startDate=trim($_POST['start_date']??'');
$endDate=trim($_POST['end_date']??'');
$monthlyRent=trim($_POST['monthly_rent']??'');
$bondAmount=trim($_POST['bond_amount']??'');
$leaseStatus=trim($_POST['lease_status']??'');
$notes=trim($_POST['notes']??'');

if($startDate==='' || $endDate===''){
$error="Please enter the start and end dates.";
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
$documentPath=$lease['lease_document'];
$newUploadedFile=null;

try{

// Upload replacement PDF if supplied
if(isset($_FILES['lease_document']) && $_FILES['lease_document']['error']!==UPLOAD_ERR_NO_FILE){

if($_FILES['lease_document']['error']!==UPLOAD_ERR_OK){
throw new Exception("Lease document could not be uploaded.");
}

if($_FILES['lease_document']['size']>10*1024*1024){
throw new Exception("Lease document must be smaller than 10MB.");
}

$extension=strtolower(pathinfo($_FILES['lease_document']['name'],PATHINFO_EXTENSION));

if($extension!=='pdf'){
throw new Exception("Lease agreement must be a PDF file.");
}

$uploadDirectory=__DIR__.'/../../uploads/leases/';

if(!is_dir($uploadDirectory)){
mkdir($uploadDirectory,0777,true);
}

$fileName='lease_'.$leaseId.'_'.time().'.pdf';
$newUploadedFile=$uploadDirectory.$fileName;

if(!move_uploaded_file($_FILES['lease_document']['tmp_name'],$newUploadedFile)){
throw new Exception("Lease document could not be saved.");
}

$documentPath='uploads/leases/'.$fileName;
}

$conn->begin_transaction();

$oldStatus=$lease['lease_status'];

$stmt=$conn->prepare("
UPDATE leases
SET start_date=?,
end_date=?,
monthly_rent=?,
bond_amount=?,
lease_status=?,
lease_document=?,
notes=?
WHERE lease_id=?
");

$stmt->bind_param(
"ssddsssi",
$startDate,
$endDate,
$monthlyRent,
$bondAmount,
$leaseStatus,
$documentPath,
$notes,
$leaseId
);

if(!$stmt->execute()){
throw new Exception("Could not update lease.");
}

// Keep property status consistent with lease
$propertyId=(int)$lease['property_id'];

if(in_array($leaseStatus,['active','renewal_due'],true)){
$propertyStatus='occupied';
}else{
$propertyStatus='vacant';
}

$stmt=$conn->prepare("
UPDATE properties
SET property_status=?
WHERE property_id=?
");
$stmt->bind_param("si",$propertyStatus,$propertyId);

if(!$stmt->execute()){
throw new Exception("Could not update property status.");
}

// Activity log
$description="Updated Lease #".$leaseId.".";
$stmt=$conn->prepare("
INSERT INTO activity_log
(user_id,action_type,entity_type,entity_id,description)
VALUES(?,'UPDATE_LEASE','lease',?,?)
");

if($stmt){
$stmt->bind_param("iis",$managerId,$leaseId,$description);
$stmt->execute();
}

$conn->commit();

// Remove previous PDF only after successful update
if(
$newUploadedFile!==null &&
!empty($lease['lease_document']) &&
$lease['lease_document']!==$documentPath
){
$oldFile=__DIR__.'/../../'.$lease['lease_document'];

if(is_file($oldFile)){
@unlink($oldFile);
}
}

$_SESSION['lease_flash']="Lease updated successfully.";
header("Location: view_lease.php?id=".$leaseId);
exit();

}catch(Throwable $e){

if($conn->errno===0 || true){
try{$conn->rollback();}catch(Throwable $ignored){}
}

if($newUploadedFile!==null && is_file($newUploadedFile)){
@unlink($newUploadedFile);
}

$error="Lease could not be updated. ".$e->getMessage();
}

}

}

$lease=getLease($conn,$leaseId);
$tenantName=$lease['first_name'].' '.$lease['last_name'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Edit Lease | RentEase</title>

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
.message{max-width:1050px;padding:12px 15px;margin-bottom:18px;border-radius:9px;font-size:13px}
.error-message{background:#fff0f0;border:1px solid #e8c5c5;color:#934a4a}
.form-card{max-width:1050px;background:#fff;border:1px solid #dce5e2;border-radius:15px;overflow:hidden;box-shadow:0 3px 12px rgba(35,70,66,.04)}
.card-header{display:flex;align-items:center;justify-content:space-between;gap:20px;padding:18px 22px;border-bottom:1px solid #e3ebe9;background:#fbfdfc}
.card-header h2{font-size:21px;font-weight:650;margin-bottom:4px}
.card-header p{font-size:13px;color:#71817e}
.reference{padding:6px 10px;border-radius:7px;background:#eef6f4;color:#2f8178;font-size:12px;font-weight:600}
.card-body{padding:22px}
.readonly-grid{display:grid;grid-template-columns:1fr 1fr;gap:15px;margin-bottom:22px}
.readonly-box{padding:14px 15px;background:#f5f8f7;border:1px solid #e3ebe9;border-radius:9px}
.readonly-box span{display:block;margin-bottom:4px;color:#71817e;font-size:11px}
.readonly-box strong{display:block;color:#40514e;font-size:14px;font-weight:600}
.form-section{padding-bottom:22px;margin-bottom:22px;border-bottom:1px solid #e3ebe9}
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
.current-document{display:flex;align-items:center;justify-content:space-between;gap:15px;padding:12px 14px;margin-bottom:12px;background:#f5f8f7;border:1px solid #e3ebe9;border-radius:8px}
.current-document span{font-size:13px;color:#40514e}
.current-document a{color:#2f8178;font-size:12px;font-weight:600;text-decoration:none}
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
.form-grid,.readonly-grid{grid-template-columns:1fr}
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
<h1>Edit Lease</h1>
<p>Update the rental agreement and lease document.</p>
</div>

<a href="../profile.php" class="top-profile">
<div class="profile-icon"><?php echo htmlspecialchars($profileLetter); ?></div>
<div class="profile-info">
<strong><?php echo htmlspecialchars($fullName); ?></strong>
<span><?php echo htmlspecialchars($jobTitle); ?></span>
</div>
</a>
</div>

<a href="view_lease.php?id=<?php echo $leaseId; ?>" class="back-link">← Back to Lease Details</a>

<?php if($error!==''): ?>
<div class="message error-message"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="form-card">

<div class="card-header">
<div>
<h2>Lease Agreement</h2>
<p>Update the information for this rental agreement.</p>
</div>
<span class="reference">Lease #<?php echo $leaseId; ?></span>
</div>

<form method="POST" enctype="multipart/form-data">
<div class="card-body">

<div class="readonly-grid">

<div class="readonly-box">
<span>Tenant</span>
<strong><?php echo htmlspecialchars($tenantName); ?></strong>
</div>

<div class="readonly-box">
<span>Property</span>
<strong><?php echo htmlspecialchars($lease['property_code'].' — '.$lease['address_line1'].', '.$lease['suburb']); ?></strong>
</div>

</div>

<div class="form-section">
<h3>Lease Period & Finance</h3>
<p>Update the agreement dates, rent and bond amount.</p>

<div class="form-grid">

<div class="form-group">
<label>Start Date *</label>
<input type="date" name="start_date" value="<?php echo htmlspecialchars($lease['start_date']); ?>" required>
</div>

<div class="form-group">
<label>End Date *</label>
<input type="date" name="end_date" value="<?php echo htmlspecialchars($lease['end_date']); ?>" required>
</div>

<div class="form-group">
<label>Monthly Rent ($) *</label>
<input type="number" name="monthly_rent" min="0" step="0.01" value="<?php echo htmlspecialchars($lease['monthly_rent']); ?>" required>
</div>

<div class="form-group">
<label>Bond Amount ($)</label>
<input type="number" name="bond_amount" min="0" step="0.01" value="<?php echo htmlspecialchars($lease['bond_amount']); ?>">
</div>

<div class="form-group">
<label>Lease Status *</label>
<select name="lease_status" required>
<option value="active" <?php echo $lease['lease_status']==='active'?'selected':''; ?>>Active</option>
<option value="renewal_due" <?php echo $lease['lease_status']==='renewal_due'?'selected':''; ?>>Renewal Due</option>
<option value="expired" <?php echo $lease['lease_status']==='expired'?'selected':''; ?>>Expired</option>
<option value="terminated" <?php echo $lease['lease_status']==='terminated'?'selected':''; ?>>Terminated</option>
</select>
</div>

</div>
</div>

<div class="form-section">
<h3>Lease Agreement Document</h3>
<p>Upload the signed or final PDF agreement when required.</p>

<?php if(!empty($lease['lease_document'])): ?>
<div class="current-document">
<span>Current agreement is attached.</span>
<a href="../../view_lease_document.php?lease_id=<?php echo $leaseId; ?>" target="_blank">View Current PDF</a>
</div>
<?php endif; ?>

<div class="form-group full">
<label><?php echo !empty($lease['lease_document'])?'Replace Agreement PDF':'Upload Agreement PDF'; ?></label>
<input type="file" name="lease_document" accept=".pdf,application/pdf">
<span class="help">PDF only. Maximum file size: 10MB.</span>
</div>
</div>

<div class="form-section">
<h3>Additional Notes</h3>
<p>Optional internal information about this agreement.</p>

<div class="form-group full">
<label>Notes</label>
<textarea name="notes" placeholder="Add lease notes..."><?php echo htmlspecialchars($lease['notes']??''); ?></textarea>
</div>
</div>

<div class="form-actions">
<a href="view_lease.php?id=<?php echo $leaseId; ?>" class="button cancel-button">Cancel</a>
<button type="submit" class="button save-button">Save Changes</button>
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