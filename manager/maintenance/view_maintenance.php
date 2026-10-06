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
$maintenanceId=isset($_GET['id'])?(int)$_GET['id']:0;

if($maintenanceId<=0){
    header("Location: maintenance.php");
    exit();
}

// Simple display helpers
function niceText($text){
    return ucwords(str_replace('_',' ',$text??''));
}

function showDate($date){
    if(empty($date)) return 'Not set';
    return date('d M Y, g:i A',strtotime($date));
}

// Manager information
$stmt=$conn->prepare("
SELECT u.first_name,u.last_name,mp.job_title
FROM users u
LEFT JOIN manager_profiles mp ON mp.manager_id=u.user_id
WHERE u.user_id=? AND u.role='manager'
LIMIT 1
");
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

// Loads the latest maintenance information
function loadMaintenanceRequest($conn,$maintenanceId){
    $stmt=$conn->prepare("
    SELECT
    m.*,
    u.first_name AS tenant_first_name,
    u.last_name AS tenant_last_name,
    u.email AS tenant_email,
    u.phone AS tenant_phone,
    p.property_code,
    p.address_line1,
    p.suburb,
    p.state,
    p.postcode
    FROM maintenance_requests m
    INNER JOIN users u ON u.user_id=m.tenant_id
    INNER JOIN properties p ON p.property_id=m.property_id
    WHERE m.maintenance_id=?
    LIMIT 1
    ");
    $stmt->bind_param("i",$maintenanceId);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

$request=loadMaintenanceRequest($conn,$maintenanceId);

if(!$request){
    die("Maintenance request not found.");
}

// One-time success message
$successMessage='';
$errorMessage='';

if(isset($_SESSION['maintenance_flash'])){
    $successMessage=$_SESSION['maintenance_flash'];
    unset($_SESSION['maintenance_flash']);
}

// Update request
if($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['update_request'])){
    $oldStatus=$request['status'];
    $oldPriority=$request['priority'];
    $oldScheduledDate=$request['scheduled_date']??null;
    $oldAssignedTo=trim($request['assigned_to']??'');

    $newStatus=$_POST['status']??'';
    $priority=$_POST['priority']??'';
    $assignedTo=trim($_POST['assigned_to']??'');
    $scheduledInput=trim($_POST['scheduled_date']??'');
    $managerSummary=trim($_POST['manager_summary']??'');
    $changeNote=trim($_POST['change_note']??'');

    $allowedStatuses=[
        'submitted',
        'in_review',
        'scheduled',
        'in_progress',
        'completed',
        'cancelled'
    ];

    $allowedPriorities=[
        'low',
        'medium',
        'high',
        'urgent'
    ];

    if(!in_array($newStatus,$allowedStatuses,true)){
        $errorMessage="Please select a valid status.";
    }elseif(!in_array($priority,$allowedPriorities,true)){
        $errorMessage="Please select a valid priority.";
    }else{
        $scheduledDate=null;

        if($scheduledInput!==''){
            $timestamp=strtotime($scheduledInput);

            if($timestamp===false){
                $errorMessage="Please enter a valid scheduled date and time.";
            }else{
                $scheduledDate=date('Y-m-d H:i:s',$timestamp);
            }
        }

        if($errorMessage===''){
            // Keep the original completion date if already completed
            if($newStatus==='completed'){
                $completedAt=!empty($request['completed_at'])
                    ?$request['completed_at']
                    :date('Y-m-d H:i:s');
            }else{
                $completedAt=null;
            }

            $conn->begin_transaction();

            try{
                $stmt=$conn->prepare("
                UPDATE maintenance_requests
                SET priority=?,
                    status=?,
                    scheduled_date=?,
                    completed_at=?,
                    assigned_to=?,
                    manager_summary=?
                WHERE maintenance_id=?
                ");

                if(!$stmt){
                    throw new Exception("Update could not be prepared.");
                }

                $stmt->bind_param(
                    "ssssssi",
                    $priority,
                    $newStatus,
                    $scheduledDate,
                    $completedAt,
                    $assignedTo,
                    $managerSummary,
                    $maintenanceId
                );

                if(!$stmt->execute()){
                    throw new Exception("Request could not be updated.");
                }

                // Add progress history when status changes
                if($oldStatus!==$newStatus){
                    $historyNote=$changeNote!==''?$changeNote:'Status updated by property manager.';

                    $stmt=$conn->prepare("
                    INSERT INTO maintenance_status_history
                    (maintenance_id,old_status,new_status,changed_by,change_note)
                    VALUES(?,?,?,?,?)
                    ");

                    if(!$stmt){
                        throw new Exception("Progress history could not be prepared.");
                    }

                    $stmt->bind_param(
                        "issis",
                        $maintenanceId,
                        $oldStatus,
                        $newStatus,
                        $managerId,
                        $historyNote
                    );

                    if(!$stmt->execute()){
                        throw new Exception("Progress history could not be saved.");
                    }
                }

                // Notify tenant when an important request detail changes
                $importantChange=
                    $oldStatus!==$newStatus||
                    $oldPriority!==$priority||
                    $oldScheduledDate!==$scheduledDate||
                    $oldAssignedTo!==$assignedTo;

                if($importantChange){
                    $tenantId=(int)$request['tenant_id'];
                    $notificationType='maintenance_update';
                    $notificationTitle='Maintenance request updated';

                    $notificationMessage=
                        'Your maintenance request "'.$request['title'].
                        '" has been updated. Current status: '.
                        niceText($newStatus).'.';

                    if($scheduledDate){
                        $notificationMessage.=
                            ' Scheduled for '.
                            date('d M Y, g:i A',strtotime($scheduledDate)).
                            '.';
                    }

                    $relatedType='maintenance';

                    $stmt=$conn->prepare("
                    INSERT INTO notifications
                    (recipient_id,sender_id,notification_type,title,message,related_entity_type,related_entity_id)
                    VALUES(?,?,?,?,?,?,?)
                    ");

                    if(!$stmt){
                        throw new Exception("Notification could not be prepared.");
                    }

                    $stmt->bind_param(
                        "iissssi",
                        $tenantId,
                        $managerId,
                        $notificationType,
                        $notificationTitle,
                        $notificationMessage,
                        $relatedType,
                        $maintenanceId
                    );

                    if(!$stmt->execute()){
                        throw new Exception("Notification could not be saved.");
                    }
                }

                // Save manager action in Activity Log
                $actionType='UPDATE_MAINTENANCE';
                $entityType='maintenance';

                $activityDescription=
                    'Manager updated maintenance request #'.
                    $maintenanceId.
                    '. Status: '.
                    niceText($newStatus).
                    ', priority: '.
                    niceText($priority).
                    '.';

                $stmt=$conn->prepare("
                INSERT INTO activity_log
                (user_id,action_type,entity_type,entity_id,description)
                VALUES(?,?,?,?,?)
                ");

                if($stmt){
                    $stmt->bind_param(
                        "issis",
                        $managerId,
                        $actionType,
                        $entityType,
                        $maintenanceId,
                        $activityDescription
                    );
                    $stmt->execute();
                }

                $conn->commit();

                // Flash message appears once only
                $_SESSION['maintenance_flash']="Maintenance request updated successfully.";

                // Clean URL prevents refresh problem
                header("Location: view_maintenance.php?id=".$maintenanceId);
                exit();

            }catch(Throwable $e){
                $conn->rollback();
                $errorMessage="The maintenance request could not be updated. Please try again.";
            }
        }
    }
}

// Reload request
$request=loadMaintenanceRequest($conn,$maintenanceId);

// Tenant uploaded evidence
$stmt=$conn->prepare("
SELECT mp.*,u.first_name,u.last_name
FROM maintenance_photos mp
INNER JOIN users u ON u.user_id=mp.uploaded_by
WHERE mp.maintenance_id=?
ORDER BY mp.uploaded_at DESC
");
$stmt->bind_param("i",$maintenanceId);
$stmt->execute();
$photos=$stmt->get_result();

// Progress history
$stmt=$conn->prepare("
SELECT msh.*,u.first_name,u.last_name
FROM maintenance_status_history msh
INNER JOIN users u ON u.user_id=msh.changed_by
WHERE msh.maintenance_id=?
ORDER BY msh.changed_at DESC
");
$stmt->bind_param("i",$maintenanceId);
$stmt->execute();
$statusHistory=$stmt->get_result();

$scheduledValue='';
if(!empty($request['scheduled_date'])){
    $scheduledValue=date('Y-m-d\TH:i',strtotime($request['scheduled_date']));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Manage Maintenance Request | RentEase</title>
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
.topbar{display:flex;justify-content:space-between;align-items:center;gap:20px;margin-bottom:20px}
.page-title h1{font-size:32px;margin-bottom:5px}
.page-title p{font-size:15px;color:#687976}
.top-profile{display:flex;align-items:center;gap:11px;min-width:205px;padding:9px 13px;background:#fff;border:1px solid #dce5e2;border-radius:12px;color:#243331;text-decoration:none}
.profile-icon{display:flex;align-items:center;justify-content:center;width:42px;height:42px;background:#2f8178;border-radius:50%;color:#fff;font-weight:700}
.profile-info{display:flex;flex-direction:column}
.profile-info strong{font-size:14px}
.profile-info span{margin-top:2px;color:#71817e;font-size:11px}
.back-link{display:inline-block;margin-bottom:18px;color:#2f8178;font-size:14px;font-weight:600;text-decoration:none}
.back-link:hover{text-decoration:underline}
.alert{padding:13px 15px;margin-bottom:18px;border-radius:9px;font-size:14px}
.alert-success{background:#e5f3ea;color:#376b55;border:1px solid #cce4d5}
.alert-error{background:#fdeaea;color:#963f3f;border:1px solid #f2cccc}
.request-banner{display:flex;justify-content:space-between;align-items:center;gap:20px;padding:22px 24px;margin-bottom:20px;background:#fff;border:1px solid #dce5e2;border-radius:15px;box-shadow:0 3px 12px rgba(35,70,66,.04)}
.request-banner h2{font-size:23px;margin-bottom:5px}
.request-number{font-size:13px;color:#71817e}
.banner-badges{display:flex;gap:8px;flex-wrap:wrap}
.badge{display:inline-block;padding:6px 10px;border-radius:20px;font-size:11px;font-weight:700}
.priority-low{background:#eef1f0;color:#60706d}
.priority-medium{background:#e8f3f1;color:#2f7069}
.priority-high{background:#fff1d9;color:#91601f}
.priority-urgent{background:#fde8e8;color:#a13e3e}
.status-submitted{background:#e8f3f1;color:#2f7069}
.status-in_review{background:#eeeafa;color:#66559a}
.status-scheduled{background:#fff1d9;color:#91601f}
.status-in_progress{background:#e5eef6;color:#426a88}
.status-completed{background:#e5f3ea;color:#477b65}
.status-cancelled{background:#edf0ef;color:#687976}

/* Main two-column structure */
.content-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:20px;align-items:stretch}
.column{display:flex;flex-direction:column;gap:20px;height:100%}
.column>.card{margin:0}
.column:first-child>.card:last-child{flex:1}
.column:last-child>.card:first-child{flex:1}
.card{background:#fff;border:1px solid #dce5e2;border-radius:15px;overflow:hidden;box-shadow:0 3px 12px rgba(35,70,66,.04)}
.card-header{padding:17px 20px;border-bottom:1px solid #e3ebe9;background:#fbfdfc}
.card-header h2{font-size:20px;margin-bottom:3px}
.card-header p{font-size:12px;color:#71817e}
.card-body{padding:20px}
.column:first-child>.card:last-child .card-body{height:calc(100% - 70px)}
.column:last-child>.card:first-child .card-body{height:calc(100% - 70px)}
.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}
.info-item.full{grid-column:1/-1}
.label{display:block;margin-bottom:5px;color:#71817e;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.3px}
.value{color:#40514e;font-size:14px;font-weight:600;line-height:1.45;overflow-wrap:anywhere}
.description{padding:15px;background:#f5f8f7;border:1px solid #e3ebe9;border-radius:9px;color:#40514e;font-size:14px;line-height:1.65;white-space:pre-line}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:15px}
.form-group{margin-bottom:2px}
.form-group.full{grid-column:1/-1}
.form-group label{display:block;margin-bottom:6px;color:#40514e;font-size:13px;font-weight:600}
.form-help{display:block;margin-top:5px;color:#82918e;font-size:11px;line-height:1.4}
input,select,textarea{width:100%;padding:10px 11px;border:1px solid #ccd9d6;border-radius:8px;background:#fff;color:#40514e;font-family:inherit;font-size:13px;outline:none}
input:focus,select:focus,textarea:focus{border-color:#2f8178;box-shadow:0 0 0 3px rgba(47,129,120,.08)}
textarea{min-height:88px;resize:vertical}
.manager-summary{min-height:105px}
.button-row{padding-top:16px;margin-top:16px;border-top:1px solid #e3ebe9}
.primary-button{width:100%;padding:11px 18px;border:0;border-radius:8px;background:#2f8178;color:#fff;font-family:inherit;font-size:14px;font-weight:600;cursor:pointer}
.primary-button:hover{background:#286f68}
..photo-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;width:100%}
.photo-item{width:100%;min-width:0;padding:9px;background:#f5f8f7;border:1px solid #e3ebe9;border-radius:9px;overflow:hidden}
.photo-item img{display:block;width:100%;height:auto;aspect-ratio:4/3;object-fit:cover;border-radius:7px}
.photo-caption{margin-top:7px;color:#71817e;font-size:11px;line-height:1.4;overflow-wrap:anywhere}
@media(max-width:760px){
.photo-grid{grid-template-columns:1fr}
.photo-item img{width:100%;height:auto;aspect-ratio:4/3}
}
.empty{padding:8px 0;color:#82918e;font-size:13px;line-height:1.5}
@media(max-width:1100px){
.content-grid{grid-template-columns:1fr}
.column{height:auto}
.column:first-child>.card:last-child,.column:last-child>.card:first-child{flex:none}
.column:first-child>.card:last-child .card-body,.column:last-child>.card:first-child .card-body{height:auto}
}
/* Progress timeline */
.progress-list{padding-left:5px}
.history-item{position:relative;padding:0 0 21px 22px;margin-left:5px;border-left:2px solid #dce5e2}
.history-item:last-child{border-left-color:transparent;padding-bottom:0}
.history-dot{position:absolute;left:-6px;top:3px;width:10px;height:10px;border-radius:50%;background:#2f8178}
.history-status{font-size:13px;font-weight:700;color:#40514e}
.history-date{margin-top:4px;color:#82918e;font-size:10px}
.history-note{margin-top:6px;padding:8px 10px;background:#f5f8f7;border-radius:6px;color:#687976;font-size:12px;line-height:1.45}
.footer{margin:32px -36px 0;padding:21px 30px;background:#183b3a;color:#c7d9d6;font-size:13px;text-align:center}
.footer strong{color:#fff}
@media(max-width:1100px){.content-grid{grid-template-columns:1fr}}
@media(max-width:760px){.sidebar{position:relative;width:100%;height:auto}.main{margin-left:0;padding:22px}.topbar,.request-banner{align-items:flex-start;flex-direction:column}.info-grid,.form-grid,.photo-grid{grid-template-columns:1fr}.info-item.full,.form-group.full{grid-column:auto}.footer{margin-left:-22px;margin-right:-22px}}
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
<a href="../lease/leases.php"><span class="menu-icon">▤</span>Leases</a>
<a href="../rent/payments.php"><span class="menu-icon">$</span>Rent & Utilities</a>

<div class="menu-title">Operations</div>
<a href="maintenance.php" class="active"><span class="menu-icon">⚙</span>Maintenance</a>
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
<h1>Manage Maintenance Request</h1>
<p>Review the reported issue and manage the repair from assessment to completion.</p>
</div>

<a href="../profile.php" class="top-profile">
<div class="profile-icon"><?php echo htmlspecialchars($managerLetter); ?></div>
<div class="profile-info">
<strong><?php echo htmlspecialchars($managerName); ?></strong>
<span><?php echo htmlspecialchars($jobTitle); ?></span>
</div>
</a>
</div>

<a href="maintenance.php" class="back-link">← Back to Maintenance</a>

<?php if($successMessage!==''): ?>
<div class="alert alert-success">
<?php echo htmlspecialchars($successMessage); ?>
</div>
<?php endif; ?>

<?php if($errorMessage!==''): ?>
<div class="alert alert-error">
<?php echo htmlspecialchars($errorMessage); ?>
</div>
<?php endif; ?>

<!-- Request overview -->
<div class="request-banner">
<div>
<h2><?php echo htmlspecialchars($request['title']); ?></h2>
<div class="request-number">
Request #<?php echo (int)$request['maintenance_id']; ?>
&nbsp; • &nbsp;
Reported <?php echo showDate($request['submitted_at']); ?>
</div>
</div>

<div class="banner-badges">
<span class="badge priority-<?php echo htmlspecialchars($request['priority']); ?>">
<?php echo htmlspecialchars(niceText($request['priority'])); ?> Priority
</span>

<span class="badge status-<?php echo htmlspecialchars($request['status']); ?>">
<?php echo htmlspecialchars(niceText($request['status'])); ?>
</span>
</div>
</div>

<div class="content-grid">

<!-- LEFT SIDE -->
<div class="column">

<!-- Request details -->
<div class="card">
<div class="card-header">
<h2>Request Details</h2>
<p>Original maintenance issue reported by the tenant.</p>
</div>

<div class="card-body">
<div class="info-grid">

<div class="info-item">
<span class="label">Category</span>
<div class="value">
<?php echo htmlspecialchars(niceText($request['category'])); ?>
</div>
</div>

<div class="info-item">
<span class="label">Date Reported</span>
<div class="value">
<?php echo showDate($request['submitted_at']); ?>
</div>
</div>

<div class="info-item full">
<span class="label">Problem Description</span>
<div class="description">
<?php echo htmlspecialchars($request['description']); ?>
</div>
</div>

</div>
</div>
</div>

<!-- Tenant and property -->
<div class="card">
<div class="card-header">
<h2>Tenant & Property</h2>
<p>Tenant contact details and location of the maintenance issue.</p>
</div>

<div class="card-body">
<div class="info-grid">

<div class="info-item">
<span class="label">Tenant</span>
<div class="value">
<?php echo htmlspecialchars($request['tenant_first_name'].' '.$request['tenant_last_name']); ?>
</div>
</div>

<div class="info-item">
<span class="label">Property</span>
<div class="value">
<?php echo htmlspecialchars($request['property_code']); ?>
</div>
</div>

<div class="info-item">
<span class="label">Email</span>
<div class="value">
<?php echo htmlspecialchars($request['tenant_email']?:'Not provided'); ?>
</div>
</div>

<div class="info-item">
<span class="label">Phone</span>
<div class="value">
<?php echo htmlspecialchars($request['tenant_phone']?:'Not provided'); ?>
</div>
</div>

<div class="info-item full">
<span class="label">Property Address</span>
<div class="value">
<?php
echo htmlspecialchars(
    $request['address_line1'].', '.
    $request['suburb'].', '.
    $request['state'].' '.
    $request['postcode']
);
?>
</div>
</div>

</div>
</div>
</div>

<!-- Tenant evidence -->
<div class="card">
<div class="card-header">
<h2>Tenant Evidence</h2>
<p>Photos uploaded with the maintenance request.</p>
</div>

<div class="card-body">

<?php if($photos->num_rows>0): ?>

<div class="photo-grid">

<?php while($photo=$photos->fetch_assoc()): ?>

<div class="photo-item">

<img
src="../../<?php echo htmlspecialchars($photo['file_path']); ?>"
alt="Maintenance evidence"
>

<div class="photo-caption">
Uploaded by
<?php echo htmlspecialchars($photo['first_name'].' '.$photo['last_name']); ?>

<?php if(!empty($photo['caption'])): ?>
<br><?php echo htmlspecialchars($photo['caption']); ?>
<?php endif; ?>
</div>

</div>

<?php endwhile; ?>

</div>

<?php else: ?>

<div class="empty">
No photo evidence was attached to this request.
</div>

<?php endif; ?>

</div>
</div>

</div>

<!-- RIGHT SIDE -->
<div class="column">

<!-- Management actions -->
<div class="card">
<div class="card-header">
<h2>Management Actions</h2>
<p>Assess the issue, organise the repair and update its progress.</p>
</div>

<div class="card-body">

<form method="POST">

<div class="form-grid">

<div class="form-group">
<label for="status">Status</label>

<select id="status" name="status" required>
<option value="submitted" <?php echo $request['status']==='submitted'?'selected':''; ?>>Submitted</option>
<option value="in_review" <?php echo $request['status']==='in_review'?'selected':''; ?>>In Review</option>
<option value="scheduled" <?php echo $request['status']==='scheduled'?'selected':''; ?>>Scheduled</option>
<option value="in_progress" <?php echo $request['status']==='in_progress'?'selected':''; ?>>In Progress</option>
<option value="completed" <?php echo $request['status']==='completed'?'selected':''; ?>>Completed</option>
<option value="cancelled" <?php echo $request['status']==='cancelled'?'selected':''; ?>>Cancelled</option>
</select>
</div>

<div class="form-group">
<label for="priority">Priority</label>

<select id="priority" name="priority" required>
<option value="low" <?php echo $request['priority']==='low'?'selected':''; ?>>Low</option>
<option value="medium" <?php echo $request['priority']==='medium'?'selected':''; ?>>Medium</option>
<option value="high" <?php echo $request['priority']==='high'?'selected':''; ?>>High</option>
<option value="urgent" <?php echo $request['priority']==='urgent'?'selected':''; ?>>Urgent</option>
</select>
</div>

<div class="form-group full">
<label for="assigned_to">Assigned To</label>

<input
type="text"
id="assigned_to"
name="assigned_to"
maxlength="150"
placeholder="Example: ABC Plumbing"
value="<?php echo htmlspecialchars($request['assigned_to']??''); ?>"
>

<span class="form-help">
Contractor, technician or staff member responsible for the repair.
</span>
</div>

<div class="form-group full">
<label for="scheduled_date">Scheduled Visit</label>

<input
type="datetime-local"
id="scheduled_date"
name="scheduled_date"
value="<?php echo htmlspecialchars($scheduledValue); ?>"
>

<span class="form-help">
Date and time arranged for the maintenance visit.
</span>
</div>

<div class="form-group full">
<label for="change_note">Progress Note</label>

<textarea
id="change_note"
name="change_note"
placeholder="Example: Plumber booked for Tuesday morning."
></textarea>

<span class="form-help">
Used in Request Progress when the status changes.
</span>
</div>

<div class="form-group full">
<label for="manager_summary">Resolution Details</label>

<textarea
id="manager_summary"
name="manager_summary"
class="manager-summary"
placeholder="Example: Kitchen tap replaced and tested successfully."
><?php echo htmlspecialchars($request['manager_summary']??''); ?></textarea>

<span class="form-help">
Record the final repair outcome or other important resolution details.
</span>
</div>

</div>

<div class="button-row">
<button
type="submit"
name="update_request"
class="primary-button"
>
Save Request Update
</button>
</div>

</form>

</div>
</div>

<!-- Request progress -->
<div class="card">
<div class="card-header">
<h2>Request Progress</h2>
<p>History of status changes for this maintenance request.</p>
</div>

<div class="card-body">

<?php if($statusHistory->num_rows>0): ?>

<div class="progress-list">

<?php while($history=$statusHistory->fetch_assoc()): ?>

<div class="history-item">

<div class="history-dot"></div>

<div class="history-status">
<?php echo htmlspecialchars(niceText($history['old_status'])); ?>
→
<?php echo htmlspecialchars(niceText($history['new_status'])); ?>
</div>

<div class="history-date">
<?php echo showDate($history['changed_at']); ?>
&nbsp; • &nbsp;
<?php echo htmlspecialchars($history['first_name'].' '.$history['last_name']); ?>
</div>

<?php if(!empty($history['change_note'])): ?>

<div class="history-note">
<?php echo htmlspecialchars($history['change_note']); ?>
</div>

<?php endif; ?>

</div>

<?php endwhile; ?>

</div>

<?php else: ?>

<div class="empty">
No status changes have been recorded yet. The progress timeline will appear when the manager changes the request status.
</div>

<?php endif; ?>

</div>
</div>

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