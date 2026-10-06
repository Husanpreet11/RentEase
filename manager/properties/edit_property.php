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

$userId=(int)$_SESSION['user_id'];
$propertyId=isset($_GET['id'])?(int)$_GET['id']:0;
$error="";
$message="";

if($propertyId<=0){
    header("Location: properties.php");
    exit();
}

// Manager details
$sql="SELECT u.first_name,u.last_name,mp.job_title
FROM users u
LEFT JOIN manager_profiles mp ON u.user_id=mp.manager_id
WHERE u.user_id=?
LIMIT 1";

$stmt=$conn->prepare($sql);
$stmt->bind_param("i",$userId);
$stmt->execute();
$manager=$stmt->get_result()->fetch_assoc();

$firstName=$manager['first_name']??'Manager';
$lastName=$manager['last_name']??'';
$fullName=trim($firstName.' '.$lastName);
$profileLetter=strtoupper(substr($firstName,0,1));
$jobTitle=!empty($manager['job_title'])?$manager['job_title']:'Property Manager';

// Show success message once
if(isset($_SESSION['property_flash'])){
    $message=$_SESSION['property_flash'];
    unset($_SESSION['property_flash']);
}

// Update property
if(isset($_POST['update_property'])){
    $address=trim($_POST['address_line1']??'');
    $suburb=trim($_POST['suburb']??'');
    $state=trim($_POST['state']??'');
    $postcode=trim($_POST['postcode']??'');
    $bedrooms=(int)($_POST['bedrooms']??0);
    $bathrooms=(float)($_POST['bathrooms']??0);
    $parking=(int)($_POST['parking_spaces']??0);
    $weeklyRent=(float)($_POST['weekly_rent']??0);
    $propertyStatus=$_POST['property_status']??'';
    $description=trim($_POST['description']??'');

    $allowedStatuses=['vacant','occupied','maintenance','inactive'];
    $allowedStates=['ACT','NSW','VIC','QLD','SA','WA','TAS','NT'];

    if($address===''||$suburb===''||$state===''||$postcode===''){
        $error="Please complete all required information.";
    }elseif(!in_array($state,$allowedStates,true)){
        $error="Please select a valid state.";
    }elseif($bedrooms<1){
        $error="Bedrooms must be at least 1.";
    }elseif($bathrooms<=0){
        $error="Please enter a valid number of bathrooms.";
    }elseif($parking<0){
        $error="Parking spaces cannot be negative.";
    }elseif($weeklyRent<=0){
        $error="Please enter a valid weekly rent.";
    }elseif(!in_array($propertyStatus,$allowedStatuses,true)){
        $error="Please select a valid property status.";
    }else{
        $sql="UPDATE properties
        SET address_line1=?,suburb=?,state=?,postcode=?,bedrooms=?,bathrooms=?,parking_spaces=?,weekly_rent=?,property_status=?,description=?
        WHERE property_id=?";

        $stmt=$conn->prepare($sql);

        if(!$stmt){
            $error="Unable to prepare property update.";
        }else{
            $stmt->bind_param(
                "ssssididssi",
                $address,
                $suburb,
                $state,
                $postcode,
                $bedrooms,
                $bathrooms,
                $parking,
                $weeklyRent,
                $propertyStatus,
                $description,
                $propertyId
            );

            if($stmt->execute()){

                // Keep a simple record in the activity log
                $log=$conn->prepare("
                INSERT INTO activity_log
                (user_id,action_type,entity_type,entity_id,description)
                VALUES (?,'UPDATE_PROPERTY','property',?,'Updated property information')
                ");

                if($log){
                    $log->bind_param("ii",$userId,$propertyId);
                    $log->execute();
                }

                $_SESSION['property_flash']="Property updated successfully.";
                header("Location: edit_property.php?id=".$propertyId);
                exit();

            }else{
                $error="Unable to update property.";
            }
        }
    }
}

// Get property information
$stmt=$conn->prepare("
SELECT *
FROM properties
WHERE property_id=?
LIMIT 1
");
$stmt->bind_param("i",$propertyId);
$stmt->execute();
$property=$stmt->get_result()->fetch_assoc();

if(!$property){
    die("Property not found.");
}

$states=['ACT','NSW','VIC','QLD','SA','WA','TAS','NT'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Edit Property | RentEase</title>

<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:"Segoe UI",Arial,sans-serif;background:#f5f7f6;color:#243331;font-size:16px}

/* Sidebar */
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
.sidebar .logout:hover{background:#68403e;color:#fff}

/* Main */
.main{margin-left:255px;min-height:100vh;padding:30px 36px 0}
.topbar{display:flex;align-items:center;justify-content:space-between;gap:20px;margin-bottom:20px}
.page-title h1{font-size:32px;font-weight:650;margin-bottom:5px;color:#243331}
.page-title p{font-size:16px;color:#687976}

/* Manager profile */
.top-profile{display:flex;align-items:center;gap:11px;min-width:215px;padding:10px 14px;background:#fff;border:1px solid #dce5e2;border-radius:12px;color:#243331;text-decoration:none}
.top-profile:hover{border-color:#9fc8c2;box-shadow:0 4px 14px rgba(47,129,120,.08)}
.profile-icon{display:flex;align-items:center;justify-content:center;width:42px;height:42px;background:#2f8178;border-radius:50%;color:#fff;font-size:16px;font-weight:700}
.profile-info{display:flex;flex-direction:column}
.profile-info strong{font-size:15px;font-weight:600}
.profile-info span{margin-top:2px;color:#71817e;font-size:12px}

/* Back */
.back-row{margin-bottom:16px}
.back-link{display:inline-flex;align-items:center;gap:5px;color:#2f8178;font-size:14px;font-weight:600;text-decoration:none}
.back-link:hover{color:#286f68}

/* Messages */
.message{margin-bottom:16px;padding:12px 15px;border-radius:8px;font-size:14px}
.success{background:#eef7f2;border:1px solid #cfe6d9;color:#2f6f54}
.error{background:#fff1f1;border:1px solid #f0cccc;color:#9b4141}

/* Main card */
.edit-card{background:#fff;border:1px solid #dce5e2;border-radius:14px;box-shadow:0 3px 12px rgba(35,70,66,.04);overflow:hidden}
.card-top{display:flex;align-items:center;justify-content:space-between;gap:20px;padding:20px 24px;border-bottom:1px solid #e3ebe9;background:#fbfdfc}
.card-title h2{font-size:22px;font-weight:650;margin-bottom:4px}
.card-title p{font-size:14px;color:#71817e}
.property-reference{padding:8px 13px;background:#eef6f4;border:1px solid #d7e7e3;border-radius:7px;color:#2f8178;font-size:13px;font-weight:700}

/* Two-column form */
.form-layout{display:grid;grid-template-columns:1.15fr .85fr}
.form-panel{padding:23px 24px}
.form-panel:first-child{border-right:1px solid #e3ebe9}
.panel-heading{display:flex;align-items:center;gap:10px;margin-bottom:20px}
.panel-icon{display:flex;align-items:center;justify-content:center;width:35px;height:35px;background:#eef6f4;border-radius:8px;color:#2f8178;font-size:15px}
.panel-heading h3{font-size:17px;color:#40514e;font-weight:650}
.panel-heading p{margin-top:2px;color:#82918e;font-size:12px}

/* Fields */
.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:17px}
.form-group{min-width:0}
.form-group.full{grid-column:1/-1}
.form-group label{display:block;margin-bottom:7px;color:#40514e;font-size:14px;font-weight:600}
.required{color:#9b4141}
input,select,textarea{width:100%;padding:11px 12px;border:1px solid #ccd9d6;border-radius:8px;background:#fff;color:#40514e;font-family:inherit;font-size:14px;outline:none;transition:.2s}
input,select{height:43px}
input:focus,select:focus,textarea:focus{border-color:#2f8178;box-shadow:0 0 0 3px rgba(47,129,120,.08)}
input[readonly]{background:#f2f5f4;color:#71817e;cursor:not-allowed}
.helper-text{margin-top:5px;color:#8a9996;font-size:11px}

/* Status */
.status-group{grid-column:1/-1}
.status-note{display:flex;align-items:center;gap:7px;margin-top:7px;padding:8px 10px;background:#f6f9f8;border-radius:6px;color:#71817e;font-size:12px}
.status-dot{width:8px;height:8px;border-radius:50%;background:#2f8178}

/* Description */
.description-section{padding:0 24px 23px}
.description-box{padding-top:21px;border-top:1px solid #e3ebe9}
.description-heading{margin-bottom:10px}
.description-heading h3{font-size:16px;color:#40514e;margin-bottom:3px}
.description-heading p{font-size:12px;color:#82918e}
textarea{min-height:105px;resize:vertical;line-height:1.6}

/* Buttons */
.form-actions{display:flex;align-items:center;justify-content:space-between;gap:15px;padding:17px 24px;background:#fbfdfc;border-top:1px solid #e3ebe9}
.action-note{color:#82918e;font-size:12px}
.action-buttons{display:flex;gap:9px}
.cancel-button,.save-button{display:inline-flex;align-items:center;justify-content:center;height:41px;padding:0 19px;border-radius:8px;font-family:inherit;font-size:14px;font-weight:600;text-decoration:none;cursor:pointer}
.cancel-button{background:#fff;border:1px solid #ccd9d6;color:#40514e}
.cancel-button:hover{background:#f3f6f5}
.save-button{background:#2f8178;border:1px solid #2f8178;color:#fff}
.save-button:hover{background:#286f68}

/* Footer */
.footer{margin:30px -36px 0;padding:20px 30px;background:#183b3a;color:#c7d9d6;font-size:13px;text-align:center}
.footer strong{color:#fff}

@media(max-width:1050px){
.form-layout{grid-template-columns:1fr}
.form-panel:first-child{border-right:0;border-bottom:1px solid #e3ebe9}
}
@media(max-width:760px){
.sidebar{position:relative;width:100%;height:auto}
.main{margin-left:0;padding:22px}
.topbar{align-items:flex-start;flex-direction:column}
.form-grid{grid-template-columns:1fr}
.form-group.full,.status-group{grid-column:auto}
.card-top{align-items:flex-start;flex-direction:column}
.form-actions{align-items:stretch;flex-direction:column}
.action-buttons{display:grid;grid-template-columns:1fr 1fr}
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

<a href="../dashboard.php">
<span class="menu-icon">⌂</span>
Dashboard
</a>

<a href="../profile.php">
<span class="menu-icon">●</span>
Profile
</a>

<div class="menu-title">Management</div>

<a href="properties.php" class="active">
<span class="menu-icon">▣</span>
Properties
</a>

<a href="../tenants/tenants.php">
<span class="menu-icon">♙</span>
Tenants
</a>

<a href="../lease/leases.php">
<span class="menu-icon">▤</span>
Leases
</a>

<a href="../rent/payments.php">
<span class="menu-icon">$</span>
Rent & Utilities
</a>

<div class="menu-title">Operations</div>

<a href="../maintenance/maintenance.php">
<span class="menu-icon">⚙</span>
Maintenance
</a>

<a href="../inspections/inspections.php">
<span class="menu-icon">◫</span>
Inspections
</a>

<a href="../messages.php">
<span class="menu-icon">✉</span>
Communication
</a>

<div class="menu-title">System</div>

<a href="../activity_log.php">
<span class="menu-icon">☷</span>
Activity Log
</a>

<div class="logout-area">
<a href="../../auth/logout.php" class="logout">
<span class="menu-icon">↪</span>
Logout
</a>
</div>

</div>

<div class="main">

<div class="topbar">

<div class="page-title">
<h1>Edit Property</h1>
<p>Update property details and rental information.</p>
</div>

<a href="../profile.php" class="top-profile">

<div class="profile-icon">
<?php echo htmlspecialchars($profileLetter); ?>
</div>

<div class="profile-info">
<strong><?php echo htmlspecialchars($fullName); ?></strong>
<span><?php echo htmlspecialchars($jobTitle); ?></span>
</div>

</a>

</div>

<div class="back-row">

<a href="view_property.php?id=<?php echo $propertyId; ?>" class="back-link">
← Back to Property Details
</a>

</div>

<?php if($message!==''): ?>

<div class="message success">
<?php echo htmlspecialchars($message); ?>
</div>

<?php endif; ?>

<?php if($error!==''): ?>

<div class="message error">
<?php echo htmlspecialchars($error); ?>
</div>

<?php endif; ?>

<form method="POST">

<div class="edit-card">

<div class="card-top">

<div class="card-title">
<h2>Property Information</h2>
<p>Edit the details for this property record.</p>
</div>

<div class="property-reference">
<?php echo htmlspecialchars($property['property_code']); ?>
</div>

</div>

<div class="form-layout">

<!-- Address and basic information -->
<div class="form-panel">

<div class="panel-heading">

<div class="panel-icon">⌂</div>

<div>
<h3>Property Details</h3>
<p>Identification and address information</p>
</div>

</div>

<div class="form-grid">

<div class="form-group">

<label>Property Code</label>

<input
type="text"
value="<?php echo htmlspecialchars($property['property_code']); ?>"
readonly
>

<div class="helper-text">
Property code cannot be changed.
</div>

</div>

<div class="form-group">

<label>
Street Address <span class="required">*</span>
</label>

<input
type="text"
name="address_line1"
value="<?php echo htmlspecialchars($property['address_line1']); ?>"
required
>

</div>

<div class="form-group">

<label>
Suburb <span class="required">*</span>
</label>

<input
type="text"
name="suburb"
value="<?php echo htmlspecialchars($property['suburb']); ?>"
required
>

</div>

<div class="form-group">

<label>
State <span class="required">*</span>
</label>

<select name="state" required>

<?php foreach($states as $state): ?>

<option
value="<?php echo $state; ?>"
<?php echo $property['state']===$state?'selected':''; ?>
>
<?php echo $state; ?>
</option>

<?php endforeach; ?>

</select>

</div>

<div class="form-group">

<label>
Postcode <span class="required">*</span>
</label>

<input
type="text"
name="postcode"
value="<?php echo htmlspecialchars($property['postcode']); ?>"
required
>

</div>

</div>

</div>

<!-- Features and rent -->
<div class="form-panel">

<div class="panel-heading">

<div class="panel-icon">▤</div>

<div>
<h3>Features & Rental</h3>
<p>Property size, rent and availability</p>
</div>

</div>

<div class="form-grid">

<div class="form-group">

<label>
Bedrooms <span class="required">*</span>
</label>

<input
type="number"
name="bedrooms"
min="1"
value="<?php echo htmlspecialchars($property['bedrooms']); ?>"
required
>

</div>

<div class="form-group">

<label>
Bathrooms <span class="required">*</span>
</label>

<input
type="number"
name="bathrooms"
min="0.5"
step="0.5"
value="<?php echo htmlspecialchars($property['bathrooms']); ?>"
required
>

</div>

<div class="form-group">

<label>Parking Spaces</label>

<input
type="number"
name="parking_spaces"
min="0"
value="<?php echo htmlspecialchars($property['parking_spaces']); ?>"
>

</div>

<div class="form-group">

<label>
Weekly Rent ($) <span class="required">*</span>
</label>

<input
type="number"
name="weekly_rent"
min="1"
step="0.01"
value="<?php echo htmlspecialchars($property['weekly_rent']); ?>"
required
>

</div>

<div class="form-group status-group">

<label>
Property Status <span class="required">*</span>
</label>

<select name="property_status" required>

<option value="vacant"
<?php echo $property['property_status']==='vacant'?'selected':''; ?>>
Vacant
</option>

<option value="occupied"
<?php echo $property['property_status']==='occupied'?'selected':''; ?>>
Occupied
</option>

<option value="maintenance"
<?php echo $property['property_status']==='maintenance'?'selected':''; ?>>
Maintenance
</option>

<option value="inactive"
<?php echo $property['property_status']==='inactive'?'selected':''; ?>>
Inactive
</option>

</select>

<div class="status-note">
<span class="status-dot"></span>
Current status: <?php echo htmlspecialchars(ucfirst($property['property_status'])); ?>
</div>

</div>

</div>

</div>

</div>

<!-- Description -->
<div class="description-section">

<div class="description-box">

<div class="description-heading">
<h3>Property Description</h3>
<p>Add a short description or useful property information.</p>
</div>

<textarea
name="description"
placeholder="Enter property description..."
><?php echo htmlspecialchars($property['description']??''); ?></textarea>

</div>

</div>

<div class="form-actions">

<div class="action-note">
Required fields are marked with *
</div>

<div class="action-buttons">

<a
href="view_property.php?id=<?php echo $propertyId; ?>"
class="cancel-button"
>
Cancel
</a>

<button
type="submit"
name="update_property"
class="save-button"
>
Save Changes
</button>

</div>

</div>

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