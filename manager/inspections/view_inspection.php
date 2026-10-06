<?php
error_reporting(E_ALL);
ini_set('display_errors',1);
session_start();
require_once __DIR__.'/../../config/db.php';

// Only managers can view inspection details
if(!isset($_SESSION['user_id'])){
    header("Location: ../../index.php");
    exit();
}
if(($_SESSION['role']??'')!=='manager'){
    header("Location: ../../tenant/dashboard.php");
    exit();
}

$managerId=(int)$_SESSION['user_id'];
$inspectionId=(int)($_GET['id']??0);

if($inspectionId<=0){
    header("Location: inspections.php");
    exit();
}

function niceText($text){
    return ucwords(str_replace('_',' ',$text??''));
}

// Load manager information
$stmt=$conn->prepare("SELECT u.first_name,u.last_name,mp.job_title FROM users u LEFT JOIN manager_profiles mp ON mp.manager_id=u.user_id WHERE u.user_id=? AND u.role='manager' LIMIT 1");
$stmt->bind_param("i",$managerId);
$stmt->execute();
$manager=$stmt->get_result()->fetch_assoc();

if(!$manager){
    session_destroy();
    header("Location: ../../index.php");
    exit();
}

$managerName=trim(($manager['first_name']??'Manager').' '.($manager['last_name']??''));
$managerLetter=strtoupper(substr($manager['first_name']??'M',0,1));
$jobTitle=!empty($manager['job_title'])?$manager['job_title']:'Property Manager';

// Load inspection information
function loadInspection($conn,$inspectionId){
    $stmt=$conn->prepare("
    SELECT
    i.*,
    p.property_code,p.address_line1,p.suburb,p.state,p.postcode,
    u.first_name AS tenant_first_name,
    u.last_name AS tenant_last_name,
    u.email AS tenant_email,
    u.phone AS tenant_phone
    FROM inspections i
    INNER JOIN properties p ON p.property_id=i.property_id
    LEFT JOIN users u ON u.user_id=i.tenant_id
    WHERE i.inspection_id=?
    LIMIT 1
    ");

    if(!$stmt) return null;

    $stmt->bind_param("i",$inspectionId);
    $stmt->execute();

    return $stmt->get_result()->fetch_assoc();
}

$inspection=loadInspection($conn,$inspectionId);

if(!$inspection){
    die("Inspection not found.");
}

$errorMessage="";
$successMessage="";

// Read the success message once and remove it
if(isset($_SESSION['flash_success'])){
    $successMessage=$_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

// Update inspection
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['update_inspection'])){
    $inspectionType=trim($_POST['inspection_type']??'');
    $scheduledAt=trim($_POST['scheduled_at']??'');
    $status=trim($_POST['status']??'');
    $notes=trim($_POST['notes']??'');
    $resultSummary=trim($_POST['result_summary']??'');

    $allowedTypes=['routine','entry','exit','follow_up','other'];
    $allowedStatuses=['scheduled','rescheduled','completed','cancelled'];

    if(!in_array($inspectionType,$allowedTypes,true)){
        $errorMessage="Please select a valid inspection type.";
    }elseif(!in_array($status,$allowedStatuses,true)){
        $errorMessage="Please select a valid inspection status.";
    }elseif($scheduledAt===''){
        $errorMessage="Please select an inspection date and time.";
    }else{
        $timestamp=strtotime($scheduledAt);

        if($timestamp===false){
            $errorMessage="Please enter a valid date and time.";
        }else{
            $scheduledAtDatabase=date('Y-m-d H:i:s',$timestamp);
            $oldStatus=$inspection['status'];
            $oldScheduledAt=$inspection['scheduled_at'];
            $completedAt=null;

            if($status==='completed'){
                $completedAt=!empty($inspection['completed_at'])
                    ?$inspection['completed_at']
                    :date('Y-m-d H:i:s');
            }

            $conn->begin_transaction();

            try{
                // Update inspection
                $stmt=$conn->prepare("
                UPDATE inspections
                SET inspection_type=?,scheduled_at=?,status=?,notes=?,result_summary=?,completed_at=?
                WHERE inspection_id=?
                ");

                if(!$stmt) throw new Exception($conn->error);

                $stmt->bind_param(
                    "ssssssi",
                    $inspectionType,
                    $scheduledAtDatabase,
                    $status,
                    $notes,
                    $resultSummary,
                    $completedAt,
                    $inspectionId
                );

                if(!$stmt->execute()){
                    throw new Exception($stmt->error);
                }

                $statusChanged=$oldStatus!==$status;
                $dateChanged=$oldScheduledAt!==$scheduledAtDatabase;

                // Notify tenant if important details changed
                if(!empty($inspection['tenant_id']) && ($statusChanged||$dateChanged)){
                    $tenantId=(int)$inspection['tenant_id'];
                    $notificationType="inspection_reminder";
                    $notificationTitle="Inspection updated";
                    $relatedEntityType="inspection";
                    $displayDate=date("d M Y",$timestamp);
                    $displayTime=date("g:i A",$timestamp);
                    $displayType=niceText($inspectionType);

                    if($status==='cancelled'){
                        $notificationTitle="Inspection cancelled";
                        $notificationMessage="The ".$displayType." inspection for ".$inspection['property_code']." has been cancelled.";
                    }elseif($status==='completed'){
                        $notificationTitle="Inspection completed";
                        $notificationMessage="The ".$displayType." inspection for ".$inspection['property_code']." has been marked as completed.";
                    }elseif($dateChanged){
                        $notificationTitle="Inspection rescheduled";
                        $notificationMessage="Your ".$displayType." inspection for ".$inspection['property_code']." is scheduled for ".$displayDate." at ".$displayTime.".";
                    }else{
                        $notificationMessage="Your ".$displayType." inspection for ".$inspection['property_code']." has been updated.";
                    }

                    $stmt=$conn->prepare("
                    INSERT INTO notifications
                    (recipient_id,sender_id,notification_type,title,message,related_entity_type,related_entity_id)
                    VALUES(?,?,?,?,?,?,?)
                    ");

                    if(!$stmt) throw new Exception($conn->error);

                    $stmt->bind_param(
                        "iissssi",
                        $tenantId,
                        $managerId,
                        $notificationType,
                        $notificationTitle,
                        $notificationMessage,
                        $relatedEntityType,
                        $inspectionId
                    );

                    if(!$stmt->execute()){
                        throw new Exception($stmt->error);
                    }
                }

                // Record manager update
                $actionType="UPDATE_INSPECTION";
                $entityType="inspection";
                $description="Manager updated inspection #".$inspectionId." for property ".$inspection['property_code'].".";

                $stmt=$conn->prepare("
                INSERT INTO activity_log
                (user_id,action_type,entity_type,entity_id,description)
                VALUES(?,?,?,?,?)
                ");

                if(!$stmt) throw new Exception($conn->error);

                $stmt->bind_param(
                    "issis",
                    $managerId,
                    $actionType,
                    $entityType,
                    $inspectionId,
                    $description
                );

                if(!$stmt->execute()){
                    throw new Exception($stmt->error);
                }

                $conn->commit();

                // This message is shown once only
                $_SESSION['flash_success']="Inspection updated successfully.";

                header("Location: view_inspection.php?id=".$inspectionId);
                exit();

            }catch(Throwable $e){
                $conn->rollback();
                $errorMessage="Update failed: ".$e->getMessage();
            }
        }
    }
}

// Reload latest inspection information
$inspection=loadInspection($conn,$inspectionId);

$statusClass='status-'.$inspection['status'];
$tenantName=trim(($inspection['tenant_first_name']??'').' '.($inspection['tenant_last_name']??''));
$inputDate=date('Y-m-d\TH:i',strtotime($inspection['scheduled_at']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Inspection Details | RentEase</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:"Segoe UI",Arial,sans-serif;background:#f5f7f6;color:#243331;font-size:16px}
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
.logout-area{margin-top:25px;padding-top:15px;border-top:1px solid rgba(255,255,255,.12)}
.sidebar .logout{color:#f0c4c4}
.main{margin-left:255px;min-height:100vh;padding:35px 42px 25px}
.topbar{display:flex;justify-content:space-between;align-items:center;gap:20px;margin-bottom:20px}
.page-title h1{color:#243331;font-size:32px;font-weight:700}
.page-title p{margin-top:7px;color:#687976;font-size:16px}
.top-profile{display:flex;align-items:center;gap:11px;min-width:190px;padding:9px 14px;background:#fff;border:1px solid #d9e3e0;border-radius:12px;color:#243331;text-decoration:none}
.profile-icon{display:flex;align-items:center;justify-content:center;width:42px;height:42px;border-radius:50%;background:#2f8178;color:#fff;font-size:17px;font-weight:800}
.profile-info strong{display:block;font-size:15px}
.profile-info span{display:block;margin-top:3px;color:#71817e;font-size:13px}
.back-row{margin-bottom:18px}
.back-link{color:#2f8178;font-size:14px;font-weight:600;text-decoration:none}
.alert{margin-bottom:18px;padding:13px 16px;border-radius:9px;font-size:14px}
.alert.success{background:#e8f5ee;border:1px solid #cce8d8;color:#287a55}
.alert.error{background:#fae7e7;border:1px solid #efcaca;color:#a84545}
.inspection-banner{display:flex;justify-content:space-between;align-items:center;gap:20px;margin-bottom:18px;padding:20px 22px;background:#eef6f4;border:1px solid #d7e6e2;border-radius:14px}
.inspection-banner h2{color:#304440;font-size:22px;font-weight:650}
.inspection-banner p{margin-top:5px;color:#687976;font-size:14px}
.badge{display:inline-block;padding:6px 10px;border-radius:20px;font-size:12px;font-weight:700}
.status-scheduled{background:#dcefeb;color:#286f68}
.status-rescheduled{background:#fff0d2;color:#94661c}
.status-completed{background:#dff0e5;color:#39785d}
.status-cancelled{background:#f7dfdf;color:#a14e4e}
.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:18px}
.card,.form-card{background:#fff;border:1px solid #dce5e2;border-radius:14px;overflow:hidden;box-shadow:0 5px 18px rgba(37,64,60,.05)}
.card-header{padding:17px 20px;background:#eef6f4;border-bottom:1px solid #dce5e2}
.card-header h3{color:#304440;font-size:19px;font-weight:650}
.card-body{padding:19px 20px}
.detail-row{display:flex;justify-content:space-between;gap:20px;padding:10px 0;border-bottom:1px solid #edf1f0}
.detail-row:last-child{border-bottom:none}
.detail-label{color:#71817e;font-size:13px}
.detail-value{color:#40514e;font-size:14px;font-weight:600;text-align:right}
.form-body{padding:21px}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:17px}
.form-group.full{grid-column:1/-1}
label{display:block;margin-bottom:7px;color:#40514e;font-size:14px;font-weight:600}
input,select,textarea{width:100%;padding:11px 12px;background:#fff;border:1px solid #ccd8d5;border-radius:8px;color:#243331;font-family:inherit;font-size:14px;outline:none}
input,select{height:44px}
textarea{min-height:100px;resize:vertical;line-height:1.5}
input:focus,select:focus,textarea:focus{border-color:#2f8178;box-shadow:0 0 0 3px rgba(47,129,120,.08)}
.btn{margin-top:18px;padding:11px 18px;background:#2f8178;border:none;border-radius:8px;color:#fff;font-family:inherit;font-size:14px;font-weight:600;cursor:pointer}
.btn:hover{background:#286f68}
.footer{margin-top:32px;margin-left:-42px;margin-right:-42px;margin-bottom:-25px;padding:20px 25px;background:#2f8178;color:#fff;text-align:center;font-size:15px}
.footer strong{color:#fff;font-weight:700}
@media(max-width:800px){
.sidebar{position:relative;width:100%;height:auto}
.main{margin-left:0;padding:25px 18px}
.top-profile{display:none}
.info-grid,.form-grid{grid-template-columns:1fr}
.form-group.full{grid-column:1}
.inspection-banner{align-items:flex-start;flex-direction:column}
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
<a href="../dashboard.php"><span class="menu-icon">⌂</span>Dashboard</a>
<a href="../profile.php"><span class="menu-icon">●</span>Profile</a>

<div class="menu-title">Management</div>
<a href="../properties/properties.php"><span class="menu-icon">⌂</span>Properties</a>
<a href="../tenants/tenants.php"><span class="menu-icon">♙</span>Tenants</a>
<a href="../lease/leases.php"><span class="menu-icon">▣</span>Leases</a>
<a href="../rent/payments.php"><span class="menu-icon">$</span>Rent & Utilities</a>

<div class="menu-title">Operations</div>
<a href="../maintenance/maintenance.php"><span class="menu-icon">⚙</span>Maintenance</a>
<a href="inspections.php" class="active"><span class="menu-icon">◫</span>Inspections</a>
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
<h1>Inspection Details</h1>
<p>View and update this property inspection.</p>
</div>

<a href="../profile.php" class="top-profile">
<div class="profile-icon"><?php echo htmlspecialchars($managerLetter); ?></div>
<div class="profile-info">
<strong><?php echo htmlspecialchars($managerName); ?></strong>
<span><?php echo htmlspecialchars($jobTitle); ?></span>
</div>
</a>
</div>

<div class="back-row">
<a href="inspections.php" class="back-link">← Back to Inspections</a>
</div>

<?php if($successMessage): ?>
<div class="alert success">
<?php echo htmlspecialchars($successMessage); ?>
</div>
<?php endif; ?>

<?php if($errorMessage): ?>
<div class="alert error">
<?php echo htmlspecialchars($errorMessage); ?>
</div>
<?php endif; ?>

<div class="inspection-banner">
<div>
<h2>Inspection #<?php echo $inspectionId; ?></h2>
<p>
<?php echo htmlspecialchars($inspection['property_code']); ?>
&nbsp; • &nbsp;
<?php echo htmlspecialchars(niceText($inspection['inspection_type'])); ?>
</p>
</div>

<span class="badge <?php echo htmlspecialchars($statusClass); ?>">
<?php echo htmlspecialchars(niceText($inspection['status'])); ?>
</span>
</div>

<div class="info-grid">

<div class="card">
<div class="card-header">
<h3>Property Information</h3>
</div>

<div class="card-body">

<div class="detail-row">
<span class="detail-label">Property Code</span>
<span class="detail-value">
<?php echo htmlspecialchars($inspection['property_code']); ?>
</span>
</div>

<div class="detail-row">
<span class="detail-label">Address</span>
<span class="detail-value">
<?php
echo htmlspecialchars(
$inspection['address_line1'].', '.
$inspection['suburb'].', '.
$inspection['state'].' '.
$inspection['postcode']
);
?>
</span>
</div>

<div class="detail-row">
<span class="detail-label">Scheduled</span>
<span class="detail-value">
<?php echo date('d M Y, g:i A',strtotime($inspection['scheduled_at'])); ?>
</span>
</div>

<?php if(!empty($inspection['completed_at'])): ?>
<div class="detail-row">
<span class="detail-label">Completed</span>
<span class="detail-value">
<?php echo date('d M Y, g:i A',strtotime($inspection['completed_at'])); ?>
</span>
</div>
<?php endif; ?>

</div>
</div>

<div class="card">
<div class="card-header">
<h3>Tenant Information</h3>
</div>

<div class="card-body">

<div class="detail-row">
<span class="detail-label">Tenant</span>
<span class="detail-value">
<?php echo htmlspecialchars($tenantName!==''?$tenantName:'No tenant'); ?>
</span>
</div>

<div class="detail-row">
<span class="detail-label">Email</span>
<span class="detail-value">
<?php echo htmlspecialchars($inspection['tenant_email']??'Not available'); ?>
</span>
</div>

<div class="detail-row">
<span class="detail-label">Phone</span>
<span class="detail-value">
<?php echo htmlspecialchars($inspection['tenant_phone']??'Not available'); ?>
</span>
</div>

<div class="detail-row">
<span class="detail-label">Current Status</span>
<span class="detail-value">
<?php echo htmlspecialchars(niceText($inspection['status'])); ?>
</span>
</div>

</div>
</div>

</div>

<div class="form-card">

<div class="card-header">
<h3>Update Inspection</h3>
</div>

<div class="form-body">

<form method="POST">

<div class="form-grid">

<div class="form-group">
<label for="inspection_type">Inspection Type</label>
<select name="inspection_type" id="inspection_type" required>
<option value="routine" <?php echo $inspection['inspection_type']==='routine'?'selected':''; ?>>Routine</option>
<option value="entry" <?php echo $inspection['inspection_type']==='entry'?'selected':''; ?>>Entry</option>
<option value="exit" <?php echo $inspection['inspection_type']==='exit'?'selected':''; ?>>Exit</option>
<option value="follow_up" <?php echo $inspection['inspection_type']==='follow_up'?'selected':''; ?>>Follow Up</option>
<option value="other" <?php echo $inspection['inspection_type']==='other'?'selected':''; ?>>Other</option>
</select>
</div>

<div class="form-group">
<label for="scheduled_at">Date & Time</label>
<input
type="datetime-local"
name="scheduled_at"
id="scheduled_at"
value="<?php echo htmlspecialchars($inputDate); ?>"
required
>
</div>

<div class="form-group full">
<label for="status">Inspection Status</label>
<select name="status" id="status" required>
<option value="scheduled" <?php echo $inspection['status']==='scheduled'?'selected':''; ?>>Scheduled</option>
<option value="rescheduled" <?php echo $inspection['status']==='rescheduled'?'selected':''; ?>>Rescheduled</option>
<option value="completed" <?php echo $inspection['status']==='completed'?'selected':''; ?>>Completed</option>
<option value="cancelled" <?php echo $inspection['status']==='cancelled'?'selected':''; ?>>Cancelled</option>
</select>
</div>

<div class="form-group full">
<label for="notes">Inspection Notes</label>
<textarea
name="notes"
id="notes"
placeholder="Add inspection notes..."
><?php echo htmlspecialchars($inspection['notes']??''); ?></textarea>
</div>

<div class="form-group full">
<label for="result_summary">Inspection Result</label>
<textarea
name="result_summary"
id="result_summary"
placeholder="Enter the inspection result or summary..."
><?php echo htmlspecialchars($inspection['result_summary']??''); ?></textarea>
</div>

</div>

<button
type="submit"
name="update_inspection"
value="1"
class="btn"
>
Save Changes
</button>

</form>

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