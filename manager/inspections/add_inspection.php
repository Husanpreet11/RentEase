<?php
error_reporting(E_ALL);
ini_set('display_errors',1);
session_start();
require_once __DIR__.'/../../config/db.php';

// Only managers can schedule inspections
if(!isset($_SESSION['user_id'])){
    header("Location: ../../index.php");
    exit();
}
if(($_SESSION['role']??'')!=='manager'){
    header("Location: ../../tenant/dashboard.php");
    exit();
}

$managerId=(int)$_SESSION['user_id'];
$errorMessage="";

// Load manager details
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

// Load active leases
$leaseResult=$conn->query("
SELECT
l.lease_id,l.property_id,l.tenant_id,
p.property_code,p.address_line1,p.suburb,p.state,p.postcode,
u.first_name,u.last_name
FROM leases l
INNER JOIN properties p ON p.property_id=l.property_id
INNER JOIN users u ON u.user_id=l.tenant_id
WHERE l.lease_status='active'
AND u.role='tenant'
ORDER BY p.property_code ASC,u.first_name ASC
");

// Schedule inspection
if($_SERVER['REQUEST_METHOD']==='POST'){
    $leaseId=(int)($_POST['lease_id']??0);
    $inspectionType=trim($_POST['inspection_type']??'');
    $scheduledAt=trim($_POST['scheduled_at']??'');
    $notes=trim($_POST['notes']??'');

    $allowedTypes=['routine','entry','exit','follow_up','other'];

    if($leaseId<=0){
        $errorMessage="Please select a property and tenant.";
    }elseif(!in_array($inspectionType,$allowedTypes,true)){
        $errorMessage="Please select a valid inspection type.";
    }elseif($scheduledAt===''){
        $errorMessage="Please select the inspection date and time.";
    }else{
        $timestamp=strtotime($scheduledAt);

        if($timestamp===false){
            $errorMessage="Please enter a valid inspection date and time.";
        }elseif($timestamp<=time()){
            $errorMessage="Please select a future date and time for the inspection.";
        }else{
            $scheduledAtDatabase=date('Y-m-d H:i:s',$timestamp);

            // Confirm selected lease
            $stmt=$conn->prepare("
            SELECT
            l.property_id,l.tenant_id,
            p.property_code,p.address_line1,p.suburb,
            u.first_name,u.last_name
            FROM leases l
            INNER JOIN properties p ON p.property_id=l.property_id
            INNER JOIN users u ON u.user_id=l.tenant_id
            WHERE l.lease_id=?
            AND l.lease_status='active'
            AND u.role='tenant'
            LIMIT 1
            ");
            $stmt->bind_param("i",$leaseId);
            $stmt->execute();
            $selectedLease=$stmt->get_result()->fetch_assoc();

            if(!$selectedLease){
                $errorMessage="The selected active lease could not be found.";
            }else{
                $propertyId=(int)$selectedLease['property_id'];
                $tenantId=(int)$selectedLease['tenant_id'];

                $conn->begin_transaction();

                try{
                    $status="scheduled";

                    // Save inspection
                    $stmt=$conn->prepare("
                    INSERT INTO inspections
                    (property_id,tenant_id,inspection_type,scheduled_at,status,notes,scheduled_by)
                    VALUES(?,?,?,?,?,?,?)
                    ");
                    if(!$stmt) throw new Exception($conn->error);

                    $stmt->bind_param(
                        "iissssi",
                        $propertyId,
                        $tenantId,
                        $inspectionType,
                        $scheduledAtDatabase,
                        $status,
                        $notes,
                        $managerId
                    );

                    if(!$stmt->execute()){
                        throw new Exception($stmt->error);
                    }

                    $inspectionId=(int)$conn->insert_id;

                    // Send notification to tenant
                    $notificationType="inspection_reminder";
                    $notificationTitle="Property inspection scheduled";
                    $relatedEntityType="inspection";
                    $displayDate=date("d M Y",$timestamp);
                    $displayTime=date("g:i A",$timestamp);
                    $displayType=ucwords(str_replace('_',' ',$inspectionType));

                    $notificationMessage="A ".$displayType." inspection has been scheduled for ".$displayDate." at ".$displayTime." for ".$selectedLease['property_code'].".";

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

                    // Save Activity Log
                    $actionType="CREATE_INSPECTION";
                    $entityType="inspection";
                    $description="Manager scheduled inspection #".$inspectionId." for property ".$selectedLease['property_code'].".";

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

                    // One-time success message
                    $_SESSION['flash_success']="Inspection scheduled successfully.";

                    header("Location: view_inspection.php?id=".$inspectionId);
                    exit();

                }catch(Throwable $e){
                    $conn->rollback();
                    $errorMessage="Could not schedule inspection: ".$e->getMessage();
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
<title>Schedule Inspection | RentEase</title>
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
.page-title h1{color:#243331;font-size:32px;font-weight:700;letter-spacing:-.5px}
.page-title p{margin-top:7px;color:#687976;font-size:16px}
.top-profile{display:flex;align-items:center;gap:11px;min-width:190px;padding:9px 14px;background:#fff;border:1px solid #d9e3e0;border-radius:12px;color:#243331;text-decoration:none}
.profile-icon{display:flex;align-items:center;justify-content:center;width:42px;height:42px;border-radius:50%;background:#2f8178;color:#fff;font-size:17px;font-weight:800}
.profile-info strong{display:block;color:#243331;font-size:15px}
.profile-info span{display:block;margin-top:3px;color:#71817e;font-size:13px}
.back-row{margin-bottom:17px}
.back-link{color:#2f8178;font-size:14px;font-weight:600;text-decoration:none}
.back-link:hover{text-decoration:underline}
.alert{max-width:1050px;margin-bottom:17px;padding:13px 16px;border-radius:9px;font-size:14px}
.alert.error{background:#fae7e7;border:1px solid #efcaca;color:#a84545}
.form-card{width:100%;max-width:1050px;background:#fff;border:1px solid #dce5e2;border-radius:15px;overflow:hidden;box-shadow:0 7px 25px rgba(37,64,60,.06)}
.card-header{padding:18px 25px;background:#eef6f4;border-bottom:1px solid #dce5e2}
.card-header h2{color:#304440;font-size:21px;font-weight:650}
.card-header p{margin-top:5px;color:#687976;font-size:14px}
.form-body{padding:22px 25px 24px}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:17px 20px}
.form-group.full{grid-column:1/-1}
label{display:block;margin-bottom:7px;color:#40514e;font-size:14px;font-weight:600}
input,select,textarea{width:100%;padding:11px 12px;background:#fff;border:1px solid #ccd8d5;border-radius:8px;color:#243331;font-family:inherit;font-size:14px;outline:none}
input,select{height:44px}
textarea{min-height:95px;max-height:150px;resize:vertical;line-height:1.5}
input:focus,select:focus,textarea:focus{border-color:#2f8178;box-shadow:0 0 0 3px rgba(47,129,120,.08)}
.help-text{margin-top:6px;color:#83908e;font-size:12px;line-height:1.4}
.form-actions{display:flex;gap:10px;margin-top:17px}
.btn{display:inline-flex;align-items:center;justify-content:center;padding:11px 18px;border-radius:8px;font-family:inherit;font-size:14px;font-weight:600;text-decoration:none;cursor:pointer}
.btn-primary{background:#2f8178;border:1px solid #2f8178;color:#fff}
.btn-primary:hover{background:#286f68;border-color:#286f68}
.btn-secondary{background:#fff;border:1px solid #ccd8d5;color:#40514e}
.btn-secondary:hover{background:#f2f6f5}
.footer{margin-top:25px;margin-left:-42px;margin-right:-42px;margin-bottom:-25px;padding:20px 25px;background:#2f8178;color:#fff;text-align:center;font-size:15px}
.footer strong{color:#fff;font-weight:700}
@media(max-width:760px){
.sidebar{position:relative;width:100%;height:auto}
.main{margin-left:0;padding:25px 18px}
.top-profile{display:none}
.form-grid{grid-template-columns:1fr}
.form-group.full{grid-column:1}
.form-actions{flex-direction:column}
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
<h1>Schedule Inspection</h1>
<p>Create a new property inspection for an active tenancy.</p>
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

<?php if($errorMessage): ?>
<div class="alert error"><?php echo htmlspecialchars($errorMessage); ?></div>
<?php endif; ?>

<div class="form-card">

<div class="card-header">
<h2>Inspection Details</h2>
<p>Select the tenancy and enter the inspection date and details.</p>
</div>

<div class="form-body">

<form method="POST">

<div class="form-grid">

<div class="form-group full">
<label for="lease_id">Property & Tenant</label>

<select name="lease_id" id="lease_id" required>
<option value="">Select property and tenant</option>

<?php if($leaseResult): ?>
<?php while($lease=$leaseResult->fetch_assoc()): ?>
<option
value="<?php echo (int)$lease['lease_id']; ?>"
<?php echo ((int)($_POST['lease_id']??0)===(int)$lease['lease_id'])?'selected':''; ?>
>
<?php
echo htmlspecialchars(
$lease['property_code'].' — '.
$lease['address_line1'].', '.
$lease['suburb'].' — '.
$lease['first_name'].' '.$lease['last_name']
);
?>
</option>
<?php endwhile; ?>
<?php endif; ?>

</select>

<div class="help-text">
The property and tenant are connected through the active lease.
</div>
</div>

<div class="form-group">
<label for="inspection_type">Inspection Type</label>

<select name="inspection_type" id="inspection_type" required>
<option value="">Select inspection type</option>
<option value="routine" <?php echo ($_POST['inspection_type']??'')==='routine'?'selected':''; ?>>Routine</option>
<option value="entry" <?php echo ($_POST['inspection_type']??'')==='entry'?'selected':''; ?>>Entry</option>
<option value="exit" <?php echo ($_POST['inspection_type']??'')==='exit'?'selected':''; ?>>Exit</option>
<option value="follow_up" <?php echo ($_POST['inspection_type']??'')==='follow_up'?'selected':''; ?>>Follow Up</option>
<option value="other" <?php echo ($_POST['inspection_type']??'')==='other'?'selected':''; ?>>Other</option>
</select>
</div>

<div class="form-group">
<label for="scheduled_at">Date & Time</label>

<input
type="datetime-local"
name="scheduled_at"
id="scheduled_at"
min="<?php echo date('Y-m-d\TH:i'); ?>"
value="<?php echo htmlspecialchars($_POST['scheduled_at']??''); ?>"
required
>
</div>

<div class="form-group full">
<label for="notes">Inspection Notes</label>

<textarea
name="notes"
id="notes"
placeholder="Add any information the manager should know before the inspection..."
><?php echo htmlspecialchars($_POST['notes']??''); ?></textarea>

<div class="help-text">
The tenant will automatically receive an inspection notification after scheduling.
</div>
</div>

</div>

<div class="form-actions">
<button type="submit" class="btn btn-primary">Schedule Inspection</button>
<a href="inspections.php" class="btn btn-secondary">Cancel</a>
</div>

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