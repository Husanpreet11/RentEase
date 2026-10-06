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

$userId=(int)$_SESSION['user_id'];
$error='';
$success='';

// Show success message only once
if(isset($_SESSION['profile_flash'])){
    $success=$_SESSION['profile_flash'];
    unset($_SESSION['profile_flash']);
}

// Update manager profile
if($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['update_profile'])){
    $firstName=trim($_POST['first_name']??'');
    $lastName=trim($_POST['last_name']??'');
    $email=trim($_POST['email']??'');
    $phone=trim($_POST['phone']??'');
    $address=trim($_POST['address']??'');
    $jobTitle=trim($_POST['job_title']??'');
    $officePhone=trim($_POST['office_phone']??'');
    $officeEmail=trim($_POST['office_email']??'');

    if($firstName===''||$lastName===''){
        $error="First name and last name cannot be empty.";
    }elseif($email===''){
        $error="Personal email cannot be empty.";
    }elseif(!filter_var($email,FILTER_VALIDATE_EMAIL)){
        $error="Please enter a valid personal email.";
    }elseif($officeEmail!==''&&!filter_var($officeEmail,FILTER_VALIDATE_EMAIL)){
        $error="Please enter a valid office email.";
    }else{
        // Check whether another account already uses this email
        $stmt=$conn->prepare("
        SELECT user_id
        FROM users
        WHERE email=? AND user_id<>?
        LIMIT 1
        ");
        $stmt->bind_param("si",$email,$userId);
        $stmt->execute();

        if($stmt->get_result()->num_rows>0){
            $error="This personal email is already used by another account.";
        }else{
            $conn->begin_transaction();

            try{
                // Update general account information
                $stmt=$conn->prepare("
                UPDATE users
                SET first_name=?,
                    last_name=?,
                    email=?,
                    phone=?,
                    address=?
                WHERE user_id=?
                ");

                if(!$stmt){
                    throw new Exception("Unable to prepare profile update.");
                }

                $stmt->bind_param(
                    "sssssi",
                    $firstName,
                    $lastName,
                    $email,
                    $phone,
                    $address,
                    $userId
                );

                if(!$stmt->execute()){
                    throw new Exception("Unable to update account information.");
                }

                // Update manager-specific information
                $stmt=$conn->prepare("
                UPDATE manager_profiles
                SET job_title=?,
                    office_phone=?,
                    office_email=?
                WHERE manager_id=?
                ");

                if(!$stmt){
                    throw new Exception("Unable to prepare manager information.");
                }

                $stmt->bind_param(
                    "sssi",
                    $jobTitle,
                    $officePhone,
                    $officeEmail,
                    $userId
                );

                if(!$stmt->execute()){
                    throw new Exception("Unable to update manager information.");
                }

                // Save action in Activity Log
                $actionType='UPDATE_PROFILE';
                $entityType='user';
                $description='Manager updated their profile information.';

                $stmt=$conn->prepare("
                INSERT INTO activity_log
                (user_id,action_type,entity_type,entity_id,description)
                VALUES(?,?,?,?,?)
                ");

                if($stmt){
                    $stmt->bind_param(
                        "issis",
                        $userId,
                        $actionType,
                        $entityType,
                        $userId,
                        $description
                    );
                    $stmt->execute();
                }

                $conn->commit();

                // Update session information too
                $_SESSION['name']=$firstName.' '.$lastName;
                $_SESSION['email']=$email;

                // Flash message prevents refresh problem
                $_SESSION['profile_flash']="Profile information updated successfully.";

                header("Location: profile.php");
                exit();

            }catch(Throwable $e){
                $conn->rollback();
                $error="Unable to update profile information.";
            }
        }
    }
}

// Change manager password
if($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['change_password'])){
    $currentPassword=$_POST['current_password']??'';
    $newPassword=$_POST['new_password']??'';
    $confirmPassword=$_POST['confirm_password']??'';

    $stmt=$conn->prepare("
    SELECT password_hash
    FROM users
    WHERE user_id=?
    LIMIT 1
    ");
    $stmt->bind_param("i",$userId);
    $stmt->execute();
    $passwordData=$stmt->get_result()->fetch_assoc();

    if(!$passwordData||!password_verify($currentPassword,$passwordData['password_hash'])){
        $error="Current password is incorrect.";
    }elseif(strlen($newPassword)<8){
        $error="New password must be at least 8 characters.";
    }elseif($newPassword!==$confirmPassword){
        $error="New passwords do not match.";
    }else{
        $newHash=password_hash($newPassword,PASSWORD_DEFAULT);

        $conn->begin_transaction();

        try{
            $stmt=$conn->prepare("
            UPDATE users
            SET password_hash=?
            WHERE user_id=?
            ");

            if(!$stmt){
                throw new Exception("Unable to prepare password update.");
            }

            $stmt->bind_param("si",$newHash,$userId);

            if(!$stmt->execute()){
                throw new Exception("Unable to update password.");
            }

            // Record password change
            $actionType='CHANGE_PASSWORD';
            $entityType='user';
            $description='Manager changed their account password.';

            $stmt=$conn->prepare("
            INSERT INTO activity_log
            (user_id,action_type,entity_type,entity_id,description)
            VALUES(?,?,?,?,?)
            ");

            if($stmt){
                $stmt->bind_param(
                    "issis",
                    $userId,
                    $actionType,
                    $entityType,
                    $userId,
                    $description
                );
                $stmt->execute();
            }

            $conn->commit();

            $_SESSION['profile_flash']="Password changed successfully.";

            header("Location: profile.php");
            exit();

        }catch(Throwable $e){
            $conn->rollback();
            $error="Unable to change password.";
        }
    }
}

// Load manager information
$stmt=$conn->prepare("
SELECT
u.first_name,
u.last_name,
u.email,
u.phone,
u.address,
u.account_status,
u.created_at,
mp.job_title,
mp.office_phone,
mp.office_email
FROM users u
LEFT JOIN manager_profiles mp
ON mp.manager_id=u.user_id
WHERE u.user_id=? AND u.role='manager'
LIMIT 1
");
$stmt->bind_param("i",$userId);
$stmt->execute();
$user=$stmt->get_result()->fetch_assoc();

if(!$user){
    die("Manager account could not be found.");
}

$fullName=trim($user['first_name'].' '.$user['last_name']);
$profileLetter=strtoupper(substr($user['first_name'],0,1));
$displayJobTitle=!empty($user['job_title'])?$user['job_title']:'Property Manager';
$memberSince=!empty($user['created_at'])?date('M Y',strtotime($user['created_at'])):'Not available';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>My Profile | RentEase</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:"Segoe UI",Arial,sans-serif;background:#f5f7f6;color:#243331}
.sidebar{position:fixed;top:0;left:0;width:255px;height:100vh;background:#183b3a;padding:25px 18px;overflow-y:auto}
.brand{padding:0 10px 24px}
.brand-name{font-size:27px;font-weight:750;color:#fff}
.brand-name span{color:#8bc7c0}
.brand-tagline{margin-top:4px;font-size:12px;color:#b7d1cd}
.menu-title{margin:20px 10px 8px;color:#8fb0ac;font-size:10px;font-weight:700;letter-spacing:1px;text-transform:uppercase}
.sidebar a{display:flex;align-items:center;gap:11px;padding:11px 12px;margin-bottom:4px;border-radius:8px;color:#d6e4e2;font-size:15px;text-decoration:none;transition:.2s}
.sidebar a:hover{background:#24514e;color:#fff}
.sidebar a.active{background:#2f8178;color:#fff;font-weight:600}
.menu-icon{width:20px;text-align:center}
.logout-area{margin-top:25px;padding-top:15px;border-top:1px solid #315553}
.sidebar .logout{color:#f1c1c1}
.sidebar .logout:hover{background:#68403e;color:#fff}
.main{margin-left:255px;min-height:100vh;padding:30px 36px 0}
.topbar{display:flex;justify-content:space-between;align-items:center;gap:20px;margin-bottom:22px}
.page-title h1{font-size:32px;margin-bottom:5px}
.page-title p{font-size:15px;color:#687976}
.top-profile{display:flex;align-items:center;gap:11px;min-width:205px;padding:9px 13px;background:#fff;border:1px solid #dce5e2;border-radius:12px;color:#243331;text-decoration:none}
.top-profile:hover{border-color:#9fc8c2;box-shadow:0 4px 14px rgba(47,129,120,.08)}
.profile-icon{display:flex;align-items:center;justify-content:center;width:42px;height:42px;background:#2f8178;border-radius:50%;color:#fff;font-weight:700}
.profile-info{display:flex;flex-direction:column}
.profile-info strong{font-size:14px}
.profile-info span{margin-top:2px;color:#71817e;font-size:11px}
.alert{padding:13px 15px;margin-bottom:18px;border-radius:9px;font-size:14px}
.alert-success{background:#e5f3ea;color:#376b55;border:1px solid #cce4d5}
.alert-error{background:#fdeaea;color:#963f3f;border:1px solid #f2cccc}
.profile-banner{display:flex;justify-content:space-between;align-items:center;gap:20px;padding:22px 24px;margin-bottom:20px;background:#fff;border:1px solid #dce5e2;border-radius:15px;box-shadow:0 3px 12px rgba(35,70,66,.04)}
.profile-identity{display:flex;align-items:center;gap:16px}
.large-profile-icon{display:flex;align-items:center;justify-content:center;width:64px;height:64px;flex-shrink:0;background:#2f8178;border-radius:50%;color:#fff;font-size:23px;font-weight:700}
.profile-identity h2{font-size:21px;margin-bottom:4px}
.profile-identity p{font-size:13px;color:#71817e}
.profile-meta{display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end}
.meta-item{min-width:115px;padding:10px 13px;background:#f3f8f7;border:1px solid #e0e9e7;border-radius:9px}
.meta-label{display:block;margin-bottom:3px;color:#71817e;font-size:10px;text-transform:uppercase;font-weight:700}
.meta-value{color:#40514e;font-size:13px;font-weight:600}
.profile-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:stretch;margin-bottom:20px}
.card{display:flex;flex-direction:column;background:#fff;border:1px solid #dce5e2;border-radius:15px;overflow:hidden;box-shadow:0 3px 12px rgba(35,70,66,.04)}
.card-header{padding:17px 20px;border-bottom:1px solid #e3ebe9;background:#fbfdfc}
.card-header h2{font-size:20px;margin-bottom:3px}
.card-header p{font-size:12px;color:#71817e}
.card-body{display:flex;flex-direction:column;flex:1;padding:20px}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.form-group{display:flex;flex-direction:column}
.form-group.full{grid-column:1/-1}
.form-group label{margin-bottom:6px;color:#40514e;font-size:12px;font-weight:600}
.form-group input,.form-group textarea{width:100%;padding:10px 11px;border:1px solid #ccd9d6;border-radius:8px;background:#fff;color:#40514e;font-family:inherit;font-size:13px;outline:none}
.form-group textarea{min-height:82px;resize:vertical}
.form-group input:focus,.form-group textarea:focus{border-color:#2f8178;box-shadow:0 0 0 3px rgba(47,129,120,.08)}
.form-group input[readonly],.form-group textarea[readonly]{background:#f3f6f5;color:#687976;cursor:default}
.form-help{margin-top:5px;color:#82918e;font-size:10px;line-height:1.4}
.form-actions{display:flex;justify-content:flex-end;margin-top:auto;padding-top:20px}
.btn{padding:10px 18px;border:0;border-radius:8px;font-family:inherit;font-size:13px;font-weight:600;cursor:pointer;transition:.2s}
.btn-primary{background:#2f8178;color:#fff}
.btn-primary:hover{background:#286f68}
.btn-secondary{background:#eef6f4;color:#2f7069;border:1px solid #cfe1de}
.btn-secondary:hover{background:#e1efec}
.security-card{margin-bottom:20px}
.security-top{display:flex;justify-content:space-between;align-items:center;gap:20px}
.security-text h3{margin-bottom:4px;color:#40514e;font-size:15px}
.security-text p{color:#71817e;font-size:12px;line-height:1.5}
.password-form{display:none;margin-top:20px;padding-top:20px;border-top:1px solid #e3ebe9}
.password-form.show{display:block}
.password-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:15px}
.password-actions{display:flex;justify-content:flex-end;margin-top:17px}
.footer{margin:32px -36px 0;padding:21px 30px;background:#183b3a;color:#c7d9d6;font-size:13px;text-align:center}
.footer strong{color:#fff}
@media(max-width:1050px){
.profile-grid{grid-template-columns:1fr}
.password-grid{grid-template-columns:1fr}
}
@media(max-width:760px){
.sidebar{position:relative;width:100%;height:auto}
.main{margin-left:0;padding:22px}
.topbar,.profile-banner,.security-top{align-items:flex-start;flex-direction:column}
.profile-meta{justify-content:flex-start}
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
<a href="dashboard.php"><span class="menu-icon">⌂</span>Dashboard</a>
<a href="profile.php" class="active"><span class="menu-icon">●</span>Profile</a>

<div class="menu-title">Management</div>
<a href="properties/properties.php"><span class="menu-icon">▣</span>Properties</a>
<a href="tenants/tenants.php"><span class="menu-icon">♙</span>Tenants</a>
<a href="lease/leases.php"><span class="menu-icon">▤</span>Leases</a>
<a href="rent/payments.php"><span class="menu-icon">$</span>Rent & Utilities</a>

<div class="menu-title">Operations</div>
<a href="maintenance/maintenance.php"><span class="menu-icon">⚙</span>Maintenance</a>
<a href="inspections/inspections.php"><span class="menu-icon">◫</span>Inspections</a>
<a href="messages.php"><span class="menu-icon">✉</span>Communication</a>

<div class="menu-title">System</div>
<a href="activity_log.php"><span class="menu-icon">☷</span>Activity Log</a>

<div class="logout-area">
<a href="../auth/logout.php" class="logout"><span class="menu-icon">↪</span>Logout</a>
</div>
</div>

<div class="main">

<div class="topbar">
<div class="page-title">
<h1>My Profile</h1>
<p>View and manage your RentEase account information.</p>
</div>

<a href="profile.php" class="top-profile">
<div class="profile-icon"><?php echo htmlspecialchars($profileLetter); ?></div>
<div class="profile-info">
<strong><?php echo htmlspecialchars($fullName); ?></strong>
<span><?php echo htmlspecialchars($displayJobTitle); ?></span>
</div>
</a>
</div>

<?php if($success!==''): ?>
<div class="alert alert-success">
<?php echo htmlspecialchars($success); ?>
</div>
<?php endif; ?>

<?php if($error!==''): ?>
<div class="alert alert-error">
<?php echo htmlspecialchars($error); ?>
</div>
<?php endif; ?>

<!-- Manager overview -->
<div class="profile-banner">
<div class="profile-identity">
<div class="large-profile-icon">
<?php echo htmlspecialchars($profileLetter); ?>
</div>

<div>
<h2><?php echo htmlspecialchars($fullName); ?></h2>
<p><?php echo htmlspecialchars($displayJobTitle); ?></p>
</div>
</div>

<div class="profile-meta">
<div class="meta-item">
<span class="meta-label">Account</span>
<span class="meta-value">
<?php echo ucfirst(htmlspecialchars($user['account_status'])); ?>
</span>
</div>

<div class="meta-item">
<span class="meta-label">Member Since</span>
<span class="meta-value">
<?php echo htmlspecialchars($memberSince); ?>
</span>
</div>
</div>
</div>

<form method="POST" id="profileForm">
<input type="hidden" name="update_profile" value="1">

<div class="profile-grid">

<!-- Personal information -->
<div class="card">
<div class="card-header">
<h2>Personal Information</h2>
<p>Your personal RentEase account information.</p>
</div>

<div class="card-body">
<div class="form-grid">

<div class="form-group">
<label>First Name</label>
<input type="text" name="first_name" class="editable-field" value="<?php echo htmlspecialchars($user['first_name']); ?>" readonly required>
</div>

<div class="form-group">
<label>Last Name</label>
<input type="text" name="last_name" class="editable-field" value="<?php echo htmlspecialchars($user['last_name']); ?>" readonly required>
</div>

<div class="form-group">
<label>Personal Email</label>
<input type="email" name="email" class="editable-field" value="<?php echo htmlspecialchars($user['email']); ?>" readonly required>
</div>

<div class="form-group">
<label>Personal Phone</label>
<input type="text" name="phone" class="editable-field" value="<?php echo htmlspecialchars($user['phone']??''); ?>" readonly>
</div>

<div class="form-group full">
<label>Address</label>
<textarea name="address" class="editable-field" readonly><?php echo htmlspecialchars($user['address']??''); ?></textarea>
</div>

</div>
</div>
</div>

<!-- Professional information -->
<div class="card">
<div class="card-header">
<h2>Professional Information</h2>
<p>Your manager-specific RentEase information.</p>
</div>

<div class="card-body">
<div class="form-grid">

<div class="form-group">
<label>Job Title</label>
<input type="text" name="job_title" class="editable-field" value="<?php echo htmlspecialchars($user['job_title']??''); ?>" readonly>
</div>

<div class="form-group">
<label>Office Phone</label>
<input type="text" name="office_phone" class="editable-field" value="<?php echo htmlspecialchars($user['office_phone']??''); ?>" readonly>
</div>

<div class="form-group full">
<label>Office Email</label>
<input type="email" name="office_email" class="editable-field" value="<?php echo htmlspecialchars($user['office_email']??''); ?>" readonly>
</div>

<div class="form-group full">
<label>Account Role</label>
<input type="text" value="Manager" readonly>
<span class="form-help">The account role is controlled by RentEase for security.</span>
</div>

</div>

<div class="form-actions">
<button type="button" id="profileActionButton" class="btn btn-primary" onclick="editProfile()">
Edit Information
</button>
</div>

</div>
</div>

</div>
</form>

<!-- Account security -->
<div class="card security-card">
<div class="card-header">
<h2>Account Security</h2>
<p>Manage your RentEase login password.</p>
</div>

<div class="card-body">

<div class="security-top">
<div class="security-text">
<h3>Password</h3>
<p>Change your password regularly to keep your manager account secure.</p>
</div>

<button type="button" class="btn btn-secondary" onclick="togglePasswordForm()">
Change Password
</button>
</div>

<div class="password-form <?php echo isset($_POST['change_password'])&&$error!==''?'show':''; ?>" id="passwordForm">

<form method="POST">

<div class="password-grid">

<div class="form-group">
<label>Current Password</label>
<input type="password" name="current_password" autocomplete="current-password" required>
</div>

<div class="form-group">
<label>New Password</label>
<input type="password" name="new_password" minlength="8" autocomplete="new-password" required>
</div>

<div class="form-group">
<label>Confirm New Password</label>
<input type="password" name="confirm_password" minlength="8" autocomplete="new-password" required>
</div>

</div>

<div class="password-actions">
<button type="submit" name="change_password" class="btn btn-primary">
Update Password
</button>
</div>

</form>
</div>
</div>
</div>

<footer class="footer">
<strong>© 2026 RentEase Property Management System</strong>
&nbsp; • &nbsp;
Renting Made Easy.
</footer>

</div>

<script>
let editingProfile=false;

function editProfile(){
    const fields=document.querySelectorAll(".editable-field");
    const button=document.getElementById("profileActionButton");

    if(!editingProfile){
        fields.forEach(function(field){
            field.removeAttribute("readonly");
        });

        button.textContent="Save Changes";
        editingProfile=true;

        const firstField=document.querySelector(".editable-field");
        if(firstField){
            firstField.focus();
        }

        return;
    }

    document.getElementById("profileForm").submit();
}

function togglePasswordForm(){
    const passwordForm=document.getElementById("passwordForm");
    passwordForm.classList.toggle("show");
}
</script>

</body>
</html>