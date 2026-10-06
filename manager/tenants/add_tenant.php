<?php
session_start();
require_once __DIR__.'/../../config/db.php';

// Only managers can create tenant accounts
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

// Get manager information for the top profile
$stmt=$conn->prepare("SELECT u.first_name,u.last_name,mp.job_title
FROM users u
LEFT JOIN manager_profiles mp ON u.user_id=mp.manager_id
WHERE u.user_id=? LIMIT 1");
$stmt->bind_param("i",$managerId);
$stmt->execute();
$manager=$stmt->get_result()->fetch_assoc();

$managerFirst=$manager['first_name']??'Manager';
$fullName=trim($managerFirst.' '.($manager['last_name']??''));
$profileLetter=strtoupper(substr($managerFirst,0,1));
$jobTitle=!empty($manager['job_title'])?$manager['job_title']:'Property Manager';

// Keep entered values if validation fails
$firstName=trim($_POST['first_name']??'');
$lastName=trim($_POST['last_name']??'');
$email=trim($_POST['email']??'');
$phone=trim($_POST['phone']??'');
$address=trim($_POST['address']??'');
$accountStatus=$_POST['account_status']??'active';

// Create tenant account
if(isset($_POST['add_tenant'])){

$password=$_POST['password']??'';
$confirmPassword=$_POST['confirm_password']??'';

// Validate form information
if($firstName===''||$lastName===''||$email===''||$password===''){
$error="Please complete all required fields.";
}elseif(!filter_var($email,FILTER_VALIDATE_EMAIL)){
$error="Please enter a valid email address.";
}elseif(strlen($password)<8){
$error="Password must contain at least 8 characters.";
}elseif($password!==$confirmPassword){
$error="Passwords do not match.";
}elseif(!in_array($accountStatus,['active','inactive'],true)){
$error="Please select a valid account status.";
}else{

// Make sure the email is not already registered
$stmt=$conn->prepare("SELECT user_id FROM users WHERE email=? LIMIT 1");
$stmt->bind_param("s",$email);
$stmt->execute();

if($stmt->get_result()->fetch_assoc()){
$error="An account with this email already exists.";
}else{

// Password is hashed before saving to the database
$passwordHash=password_hash($password,PASSWORD_DEFAULT);
$role='tenant';

try{
$conn->begin_transaction();

// Save new tenant account
$stmt=$conn->prepare("INSERT INTO users
(role,first_name,last_name,email,phone,address,password_hash,account_status)
VALUES(?,?,?,?,?,?,?,?)");

$stmt->bind_param(
"ssssssss",
$role,
$firstName,
$lastName,
$email,
$phone,
$address,
$passwordHash,
$accountStatus
);

if(!$stmt->execute()){
throw new Exception("Unable to create tenant account.");
}

$newTenantId=$conn->insert_id;

// Record the action in the activity log
$description="Created tenant account for ".$firstName." ".$lastName.".";

$stmt=$conn->prepare("INSERT INTO activity_log
(user_id,action_type,entity_type,entity_id,description)
VALUES(?,'ADD_TENANT','tenant',?,?)");

if($stmt){
$stmt->bind_param("iis",$managerId,$newTenantId,$description);
$stmt->execute();
}

$conn->commit();

// Show success once on the tenant details page
$_SESSION['tenant_flash']="Tenant account created successfully.";
header("Location: view_tenant.php?id=".$newTenantId);
exit();

}catch(Throwable $e){
$conn->rollback();
$error="Unable to create tenant account.";
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
<title>Add Tenant | RentEase</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:"Segoe UI",Arial,sans-serif;background:#f5f7f6;color:#52615f;font-size:16px}
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
.topbar{display:flex;align-items:center;justify-content:space-between;gap:20px;margin-bottom:20px}
.page-title h1{font-size:32px;font-weight:650;color:#40514e;margin-bottom:5px}
.page-title p{font-size:16px;color:#71817e}
.top-profile{display:flex;align-items:center;gap:11px;min-width:215px;padding:10px 14px;background:#fff;border:1px solid #dce5e2;border-radius:12px;color:#52615f;text-decoration:none}
.profile-icon{display:flex;align-items:center;justify-content:center;width:42px;height:42px;background:#2f8178;border-radius:50%;color:#fff;font-weight:700}
.profile-info{display:flex;flex-direction:column}
.profile-info strong{font-size:15px;color:#40514e}
.profile-info span{margin-top:2px;color:#82918e;font-size:12px}
.back-link{display:inline-block;margin-bottom:18px;color:#2f8178;font-size:14px;font-weight:600;text-decoration:none}
.error-message{max-width:1050px;padding:12px 15px;margin-bottom:18px;background:#fff1f1;border:1px solid #f0cccc;border-radius:9px;color:#9b4b4b;font-size:13px}
.form-card{max-width:1050px;background:#fff;border:1px solid #dce5e2;border-radius:15px;overflow:hidden;box-shadow:0 3px 12px rgba(35,70,66,.04)}
.card-header{padding:18px 22px;border-bottom:1px solid #e3ebe9;background:#fbfdfc}
.card-header h2{font-size:21px;color:#40514e;font-weight:650;margin-bottom:4px}
.card-header p{font-size:13px;color:#82918e}
.card-body{padding:22px}
.form-section{padding-bottom:22px;margin-bottom:22px;border-bottom:1px solid #e3ebe9}
.form-section:last-of-type{border-bottom:0;margin-bottom:0}
.form-section h3{font-size:17px;color:#52615f;font-weight:650;margin-bottom:4px}
.form-section>p{margin-bottom:16px;color:#82918e;font-size:12px}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px 20px}
.form-group.full{grid-column:1/-1}
.form-group label{display:block;margin-bottom:6px;color:#5f706d;font-size:13px;font-weight:600}
.required{color:#a55c5c}
input,select{width:100%;height:44px;padding:10px 12px;border:1px solid #ccd9d6;border-radius:8px;background:#fff;color:#52615f;font-family:inherit;font-size:14px;outline:none}
input:focus,select:focus{border-color:#2f8178;box-shadow:0 0 0 3px rgba(47,129,120,.08)}
.helper-text{display:block;margin-top:5px;color:#8b9997;font-size:11px}
.password-panel{padding:17px;background:#f7faf9;border:1px solid #e3ebe9;border-radius:10px}
.form-actions{display:flex;justify-content:flex-end;gap:10px;padding-top:20px;border-top:1px solid #e3ebe9}
.button{display:inline-flex;align-items:center;justify-content:center;height:42px;padding:0 18px;border-radius:8px;font-family:inherit;font-size:14px;font-weight:600;text-decoration:none;cursor:pointer}
.cancel-button{border:1px solid #ccd9d6;background:#fff;color:#5f6d6b}
.save-button{border:0;background:#2f8178;color:#fff}
.save-button:hover{background:#286f68}
.footer{margin:30px -36px 0;padding:20px 30px;background:#183b3a;color:#c7d9d6;font-size:13px;text-align:center}
.footer strong{color:#fff}
@media(max-width:760px){.sidebar{position:relative;width:100%;height:auto}.main{margin-left:0;padding:22px}.topbar{align-items:flex-start;flex-direction:column}.form-grid{grid-template-columns:1fr}.form-group.full{grid-column:auto}.footer{margin-left:-22px;margin-right:-22px}}
</style>
</head>
<body>

<!-- Manager sidebar -->
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
<a href="tenants.php" class="active"><span class="menu-icon">♙</span>Tenants</a>
<a href="../lease/leases.php"><span class="menu-icon">▣</span>Leases</a>
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

<!-- Page heading -->
<div class="topbar">
<div class="page-title">
<h1>Add Tenant</h1>
<p>Create a new tenant account.</p>
</div>

<a href="../profile.php" class="top-profile">
<div class="profile-icon"><?php echo htmlspecialchars($profileLetter); ?></div>
<div class="profile-info">
<strong><?php echo htmlspecialchars($fullName); ?></strong>
<span><?php echo htmlspecialchars($jobTitle); ?></span>
</div>
</a>
</div>

<a href="tenants.php" class="back-link">← Back to Tenants</a>

<?php if($error!==''): ?>
<div class="error-message"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="form-card">
<div class="card-header">
<h2>Tenant Account</h2>
<p>Enter the tenant's personal and account information.</p>
</div>

<form method="POST">
<div class="card-body">

<!-- Personal information -->
<div class="form-section">
<h3>Personal Information</h3>
<p>Basic information used to identify the tenant.</p>

<div class="form-grid">
<div class="form-group">
<label>First Name <span class="required">*</span></label>
<input type="text" name="first_name" value="<?php echo htmlspecialchars($firstName); ?>" required>
</div>

<div class="form-group">
<label>Last Name <span class="required">*</span></label>
<input type="text" name="last_name" value="<?php echo htmlspecialchars($lastName); ?>" required>
</div>
</div>
</div>

<!-- Contact information -->
<div class="form-section">
<h3>Contact Information</h3>
<p>Contact details used for tenancy communication.</p>

<div class="form-grid">
<div class="form-group">
<label>Email <span class="required">*</span></label>
<input type="email" name="email" value="<?php echo htmlspecialchars($email); ?>" required>
</div>

<div class="form-group">
<label>Phone</label>
<input type="text" name="phone" value="<?php echo htmlspecialchars($phone); ?>">
</div>

<div class="form-group full">
<label>Address</label>
<input type="text" name="address" value="<?php echo htmlspecialchars($address); ?>">
</div>
</div>
</div>

<!-- Tenant login setup -->
<div class="form-section">
<h3>Account Setup</h3>
<p>Create the tenant's login details and account status.</p>

<div class="password-panel">
<div class="form-grid">

<div class="form-group">
<label>Password <span class="required">*</span></label>
<input type="password" name="password" required>
<span class="helper-text">Minimum 8 characters.</span>
</div>

<div class="form-group">
<label>Confirm Password <span class="required">*</span></label>
<input type="password" name="confirm_password" required>
</div>

<div class="form-group">
<label>Account Status</label>
<select name="account_status">
<option value="active" <?php echo $accountStatus==='active'?'selected':''; ?>>Active</option>
<option value="inactive" <?php echo $accountStatus==='inactive'?'selected':''; ?>>Inactive</option>
</select>
</div>

</div>
</div>
</div>

<!-- Form buttons -->
<div class="form-actions">
<a href="tenants.php" class="button cancel-button">Cancel</a>
<button type="submit" name="add_tenant" class="button save-button">Create Tenant</button>
</div>

</div>
</form>
</div>

<!-- Footer -->
<footer class="footer">
<strong>© 2026 RentEase Property Management System</strong>
&nbsp; • &nbsp;
Renting Made Easy.
</footer>

</div>
</body>
</html>