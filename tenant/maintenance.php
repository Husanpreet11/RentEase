<?php
session_start();
require_once __DIR__ . '/../config/db.php';

/*
|--------------------------------------------------------------------------
| Security
|--------------------------------------------------------------------------
| Only a logged-in tenant can open this page.
*/
if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit();
}

if (($_SESSION['role'] ?? '') !== 'tenant') {
    header("Location: ../manager/dashboard.php");
    exit();
}

$tenantId = (int)$_SESSION['user_id'];
$error = "";

/*
|--------------------------------------------------------------------------
| Success Message
|--------------------------------------------------------------------------
| The success message is stored in the session so we can redirect after
| submitting the form. This prevents duplicate requests on page refresh.
*/
$success = $_SESSION['maintenance_success'] ?? '';
unset($_SESSION['maintenance_success']);

/*
|--------------------------------------------------------------------------
| Get Tenant Information
|--------------------------------------------------------------------------
*/
$stmt = $conn->prepare("
    SELECT first_name,last_name,email
    FROM users
    WHERE user_id=? AND role='tenant'
    LIMIT 1
");
$stmt->bind_param("i",$tenantId);
$stmt->execute();
$tenant = $stmt->get_result()->fetch_assoc();

if (!$tenant) {
    session_destroy();
    header("Location: ../index.php");
    exit();
}

$tenantName = trim($tenant['first_name'].' '.$tenant['last_name']);
$tenantLetter = strtoupper(substr($tenant['first_name'],0,1));

/*
|--------------------------------------------------------------------------
| Get Current Rental Property
|--------------------------------------------------------------------------
| A maintenance request must belong to the tenant's current property.
*/
$stmt = $conn->prepare("
    SELECT
        l.lease_id,
        l.property_id,
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
$stmt->bind_param("i",$tenantId);
$stmt->execute();
$lease = $stmt->get_result()->fetch_assoc();

$propertyId = $lease ? (int)$lease['property_id'] : 0;

/*
|--------------------------------------------------------------------------
| Create Maintenance Request
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_request') {

    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $category = $_POST['category'] ?? 'other';
    $priority = $_POST['priority'] ?? 'medium';

    // Only these values are accepted from the form
    $allowedCategories = [
        'plumbing',
        'electrical',
        'appliance',
        'heating_cooling',
        'structural',
        'security',
        'other'
    ];

    $allowedPriorities = [
        'low',
        'medium',
        'high',
        'urgent'
    ];

    // Basic validation before saving anything
    if ($propertyId <= 0) {
        $error = "No active property was found for your account.";
    } elseif ($title === '') {
        $error = "Please enter a request title.";
    } elseif (strlen($title) > 150) {
        $error = "The request title is too long.";
    } elseif ($description === '') {
        $error = "Please describe the maintenance problem.";
    } elseif (!in_array($category,$allowedCategories,true)) {
        $error = "Invalid maintenance category.";
    } elseif (!in_array($priority,$allowedPriorities,true)) {
        $error = "Invalid maintenance priority.";
    } else {

        /*
        |--------------------------------------------------------------------------
        | Validate Optional Photo Before Creating Request
        |--------------------------------------------------------------------------
        */
        $photoProvided = isset($_FILES['maintenance_photo'])
            && $_FILES['maintenance_photo']['error'] !== UPLOAD_ERR_NO_FILE;

        $photoTemp = "";
        $photoExtension = "";

        if ($photoProvided) {

            if ($_FILES['maintenance_photo']['error'] !== UPLOAD_ERR_OK) {
                $error = "The selected photo could not be uploaded.";
            } elseif ($_FILES['maintenance_photo']['size'] > 5 * 1024 * 1024) {
                $error = "Please choose a photo smaller than 5 MB.";
            } else {
                $photoTemp = $_FILES['maintenance_photo']['tmp_name'];
                $photoExtension = strtolower(
                    pathinfo($_FILES['maintenance_photo']['name'],PATHINFO_EXTENSION)
                );

                $allowedExtensions = ['jpg','jpeg','png','webp'];

                if (
                    !in_array($photoExtension,$allowedExtensions,true) ||
                    @getimagesize($photoTemp) === false
                ) {
                    $error = "Please choose a valid JPG, JPEG, PNG or WEBP image.";
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Save Request
        |--------------------------------------------------------------------------
        */
        if ($error === '') {

            $conn->begin_transaction();

            try {

                $stmt = $conn->prepare("
                    INSERT INTO maintenance_requests
                    (
                        property_id,
                        tenant_id,
                        title,
                        description,
                        category,
                        priority,
                        status
                    )
                    VALUES (?,?,?,?,?,?,'submitted')
                ");

                $stmt->bind_param(
                    "iissss",
                    $propertyId,
                    $tenantId,
                    $title,
                    $description,
                    $category,
                    $priority
                );

                if (!$stmt->execute()) {
                    throw new Exception("Request could not be created.");
                }

                $maintenanceId = $conn->insert_id;

                /*
                |--------------------------------------------------------------------------
                | Save Photo
                |--------------------------------------------------------------------------
                | Photos are stored in uploads/maintenance and their path is saved
                | in the maintenance_photos table.
                */
                if ($photoProvided) {

                    $uploadDirectory = __DIR__ . '/../uploads/maintenance/';

                    if (!is_dir($uploadDirectory)) {
                        if (!mkdir($uploadDirectory,0775,true)) {
                            throw new Exception("Photo folder could not be created.");
                        }
                    }

                    $newFileName =
                        'maintenance_'.
                        $maintenanceId.'_'.time().'_'.
                        bin2hex(random_bytes(4)).'.'.
                        $photoExtension;

                    $destination = $uploadDirectory.$newFileName;

                    if (!move_uploaded_file($photoTemp,$destination)) {
                        throw new Exception("Maintenance photo could not be saved.");
                    }

                    $databasePath = 'uploads/maintenance/'.$newFileName;
                    $caption = 'Photo submitted with maintenance request';

                    $stmtPhoto = $conn->prepare("
                        INSERT INTO maintenance_photos
                        (
                            maintenance_id,
                            uploaded_by,
                            file_path,
                            caption
                        )
                        VALUES (?,?,?,?)
                    ");

                    $stmtPhoto->bind_param(
                        "iiss",
                        $maintenanceId,
                        $tenantId,
                        $databasePath,
                        $caption
                    );

                    if (!$stmtPhoto->execute()) {
                        @unlink($destination);
                        throw new Exception("Maintenance photo could not be recorded.");
                    }
                }

                /*
                |--------------------------------------------------------------------------
                | First Status History Entry
                |--------------------------------------------------------------------------
                */
                $historyNote = "Maintenance request submitted by tenant.";

                $stmtHistory = $conn->prepare("
                    INSERT INTO maintenance_status_history
                    (
                        maintenance_id,
                        old_status,
                        new_status,
                        changed_by,
                        change_note
                    )
                    VALUES (?,NULL,'submitted',?,?)
                ");

                $stmtHistory->bind_param(
                    "iis",
                    $maintenanceId,
                    $tenantId,
                    $historyNote
                );

                if (!$stmtHistory->execute()) {
                    throw new Exception("Maintenance status history could not be saved.");
                }

                /*
                |--------------------------------------------------------------------------
                | Activity Log
                |--------------------------------------------------------------------------
                | This allows the manager to see that the tenant created a request.
                */
                $activityDescription =
                    $tenantName." submitted maintenance request: ".$title.".";

                $stmtLog = $conn->prepare("
                    INSERT INTO activity_log
                    (
                        user_id,
                        action_type,
                        entity_type,
                        entity_id,
                        description
                    )
                    VALUES (
                        ?,
                        'SUBMIT_MAINTENANCE',
                        'maintenance',
                        ?,
                        ?
                    )
                ");

                $stmtLog->bind_param(
                    "iis",
                    $tenantId,
                    $maintenanceId,
                    $activityDescription
                );

                if (!$stmtLog->execute()) {
                    throw new Exception("Activity could not be recorded.");
                }

                $conn->commit();

                /*
                |--------------------------------------------------------------------------
                | Redirect After Successful POST
                |--------------------------------------------------------------------------
                | This prevents duplicate requests if the browser is refreshed.
                */
                $_SESSION['maintenance_success'] =
                    "Maintenance request submitted successfully.";

                header("Location: maintenance.php");
                exit();

            } catch (Throwable $e) {

                $conn->rollback();

                // Remove uploaded file if the transaction later failed
                if (isset($destination) && is_file($destination)) {
                    @unlink($destination);
                }

                $error = "Maintenance request could not be submitted. Please try again.";
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| Get Tenant Maintenance Requests
|--------------------------------------------------------------------------
*/
$requests = [];

$stmt = $conn->prepare("
    SELECT
        mr.maintenance_id,
        mr.title,
        mr.description,
        mr.category,
        mr.priority,
        mr.status,
        mr.submitted_at,
        mr.scheduled_date,
        mr.completed_at,
        mr.assigned_to,
        mr.manager_summary,
        (
            SELECT mp.file_path
            FROM maintenance_photos mp
            WHERE mp.maintenance_id=mr.maintenance_id
            ORDER BY mp.uploaded_at ASC
            LIMIT 1
        ) AS photo_path,
        p.property_code,
        p.address_line1,
        p.suburb
    FROM maintenance_requests mr
    INNER JOIN properties p ON p.property_id=mr.property_id
    WHERE mr.tenant_id=?
    ORDER BY mr.submitted_at DESC
");

$stmt->bind_param("i",$tenantId);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $requests[] = $row;
}

/*
|--------------------------------------------------------------------------
| Summary Counts
|--------------------------------------------------------------------------
*/
$totalRequests = count($requests);
$activeRequests = 0;
$completedRequests = 0;

foreach ($requests as $request) {

    if (!in_array($request['status'],['completed','cancelled'],true)) {
        $activeRequests++;
    }

    if ($request['status'] === 'completed') {
        $completedRequests++;
    }
}

/*
|--------------------------------------------------------------------------
| Unread Communication Count
|--------------------------------------------------------------------------
*/
$stmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM notifications
    WHERE recipient_id=? AND is_read=0
");

$stmt->bind_param("i",$tenantId);
$stmt->execute();

$unreadCommunication =
    (int)$stmt->get_result()->fetch_assoc()['total'];

/*
|--------------------------------------------------------------------------
| Helper Functions
|--------------------------------------------------------------------------
*/
function niceText($text) {
    return ucwords(str_replace("_"," ",$text ?? ""));
}

function showDate($date) {
    if (!$date) {
        return "—";
    }

    return date("d M Y, h:i A",strtotime($date));
}

function statusClass($status) {
    switch ($status) {
        case 'completed':
            return 'green';

        case 'cancelled':
            return 'red';

        case 'in_progress':
        case 'scheduled':
            return 'teal';

        case 'in_review':
            return 'orange';

        default:
            return 'grey';
    }
}

function priorityClass($priority) {
    switch ($priority) {
        case 'urgent':
            return 'red';

        case 'high':
            return 'orange';

        case 'medium':
            return 'teal';

        default:
            return 'grey';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Maintenance | RentEase</title>

<style>
/* Basic page styling */
*{
    box-sizing:border-box;
}

body{
    margin:0;
    background:#f5f7f6;
    color:#243331;
    font-family:"Segoe UI",Arial,sans-serif;
    font-size:16px;
}

/* Left tenant navigation */
.sidebar{
    position:fixed;
    top:0;
    left:0;
    width:255px;
    height:100vh;
    padding:28px 18px;
    background:#183b3a;
    overflow-y:auto;
}

.brand{
    padding:0 12px 24px;
    border-bottom:1px solid rgba(255,255,255,.12);
}

.brand-name{
    color:#fff;
    font-size:28px;
    font-weight:800;
}

.brand-name span{
    color:#80c7ba;
}

.brand-tagline{
    margin-top:4px;
    color:#b8d0cc;
    font-size:13px;
}

.menu-title{
    margin:23px 12px 8px;
    color:#8fb2ac;
    font-size:11px;
    font-weight:700;
    letter-spacing:1.2px;
    text-transform:uppercase;
}

.sidebar a{
    display:flex;
    align-items:center;
    gap:12px;
    margin-bottom:4px;
    padding:12px 13px;
    border-radius:9px;
    color:#d8e5e2;
    text-decoration:none;
    font-size:15px;
    font-weight:500;
    transition:.2s;
}

.sidebar a:hover{
    background:rgba(255,255,255,.08);
    color:#fff;
}

.sidebar a.active{
    background:#2f8178;
    color:#fff;
    font-weight:700;
}

.menu-icon{
    width:21px;
    text-align:center;
}

.notification-count{
    display:flex;
    align-items:center;
    justify-content:center;
    min-width:21px;
    height:21px;
    margin-left:auto;
    padding:0 6px;
    background:#80c7ba;
    color:#183b3a;
    border-radius:20px;
    font-size:11px;
    font-weight:700;
}

.logout-area{
    margin-top:25px;
    padding-top:15px;
    border-top:1px solid rgba(255,255,255,.12);
}

.sidebar .logout{
    color:#f0c4c4;
}

/* Main page area */
.main{
    margin-left:255px;
    min-height:100vh;
    padding:35px 42px 25px;
}

/* Top page heading */
.topbar{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:20px;
    margin-bottom:25px;
}

.page-title h1{
    margin:0;
    color:#243331;
    font-size:32px;
    font-weight:700;
    letter-spacing:-.5px;
}

.page-title p{
    margin:7px 0 0;
    color:#687976;
    font-size:16px;
}

/* Tenant profile box */
.top-profile{
    display:flex;
    align-items:center;
    gap:11px;
    padding:9px 14px;
    background:#fff;
    border:1px solid #d9e3e0;
    border-radius:12px;
    color:#243331;
    text-decoration:none;
}

.profile-icon{
    display:flex;
    align-items:center;
    justify-content:center;
    width:42px;
    height:42px;
    border-radius:50%;
    background:#2f8178;
    color:#fff;
    font-size:17px;
    font-weight:700;
}

.profile-info strong{
    display:block;
    font-size:15px;
    font-weight:600;
}

.profile-info span{
    display:block;
    margin-top:2px;
    color:#71817e;
    font-size:13px;
}

/* Success and error messages */
.message{
    margin-bottom:20px;
    padding:13px 16px;
    border-radius:9px;
    font-size:14px;
}

.success{
    background:#e8f5ee;
    border:1px solid #b8dfcc;
    color:#287a55;
}

.error{
    background:#fae8e8;
    border:1px solid #edc5c5;
    color:#a84545;
}

/* Current rental banner */
.property-box{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:20px;
    margin-bottom:22px;
    padding:24px 27px;
    background:#2f8178;
    border-radius:15px;
    color:#fff;
}

.property-label{
    margin-bottom:5px;
    color:#d9efeb;
    font-size:12px;
    font-weight:600;
    letter-spacing:.8px;
    text-transform:uppercase;
}

.property-box h2{
    margin:0;
    font-size:22px;
    font-weight:600;
}

.property-box p{
    margin:6px 0 0;
    color:#e3f1ee;
    font-size:14px;
}

.property-code{
    padding:8px 14px;
    background:rgba(255,255,255,.16);
    border:1px solid rgba(255,255,255,.28);
    border-radius:20px;
    font-size:13px;
    font-weight:600;
}

/* Request summary */
.summary-grid{
    display:grid;
    grid-template-columns:repeat(3,1fr);
    gap:18px;
    margin-bottom:22px;
}

.summary-card{
    padding:20px;
    background:#fff;
    border:1px solid #dce5e2;
    border-radius:14px;
    box-shadow:0 5px 18px rgba(37,64,60,.04);
}

.summary-label{
    color:#71817e;
    font-size:12px;
    font-weight:600;
    letter-spacing:.4px;
    text-transform:uppercase;
}

.summary-value{
    margin-top:9px;
    color:#40514e;
    font-size:21px;
    font-weight:600;
}

.summary-note{
    margin-top:6px;
    color:#83908e;
    font-size:12px;
}

/* Main maintenance section */
.section{
    margin-bottom:22px;
    background:#fff;
    border:1px solid #dce5e2;
    border-radius:15px;
    overflow:hidden;
    box-shadow:0 6px 22px rgba(37,64,60,.05);
}

.section-header{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:20px;
    padding:21px 25px;
    border-bottom:1px solid #e4e9e7;
}

.section-header h2{
    margin:0;
    color:#263936;
    font-size:21px;
    font-weight:600;
}

.section-header p{
    margin:5px 0 0;
    color:#71817e;
    font-size:14px;
}

/* Buttons */
.btn{
    display:inline-block;
    padding:10px 16px;
    border:0;
    border-radius:8px;
    background:#2f8178;
    color:#fff;
    font-family:inherit;
    font-size:13px;
    font-weight:600;
    text-decoration:none;
    cursor:pointer;
    transition:.2s;
}

.btn:hover{
    background:#286f68;
}

.btn-secondary{
    background:#e9efed;
    color:#52635f;
}

.btn-secondary:hover{
    background:#dce5e2;
}

/* New request form */
.request-form{
    display:none;
    padding:25px;
    background:#f8faf9;
    border-bottom:1px solid #e4e9e7;
}

.form-intro{
    margin-bottom:20px;
    padding:15px 17px;
    background:#eef6f4;
    border-left:4px solid #2f8178;
    border-radius:0 8px 8px 0;
    color:#536663;
    font-size:13px;
    line-height:1.6;
}

.form-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:18px;
}

.form-group{
    margin-bottom:3px;
}

.form-group.full{
    grid-column:1/-1;
}

label{
    display:block;
    margin-bottom:7px;
    color:#40514e;
    font-size:13px;
    font-weight:600;
}

input,
select,
textarea{
    width:100%;
    padding:11px 12px;
    background:#fff;
    border:1px solid #cfdad7;
    border-radius:8px;
    color:#334744;
    font-family:inherit;
    font-size:14px;
    outline:none;
}

input:focus,
select:focus,
textarea:focus{
    border-color:#2f8178;
    box-shadow:0 0 0 3px rgba(47,129,120,.08);
}

textarea{
    min-height:120px;
    resize:vertical;
}

.form-actions{
    display:flex;
    gap:9px;
    margin-top:20px;
}

/* Photo upload */
.photo-upload-box{
    padding:18px;
    background:#fff;
    border:1px dashed #aebfbb;
    border-radius:10px;
}

.choose-photo-btn{
    padding:10px 15px;
    background:#eef6f4;
    border:1px solid #8dbbb4;
    border-radius:8px;
    color:#286f68;
    font-family:inherit;
    font-size:13px;
    font-weight:600;
    cursor:pointer;
}

.choose-photo-btn:hover{
    background:#e1efec;
}

.selected-file{
    margin-top:10px;
    color:#687976;
    font-size:12px;
}

.photo-help{
    margin-top:8px;
    color:#899693;
    font-size:11px;
}

.photo-preview-box{
    margin-top:15px;
}

.photo-preview-box img{
    display:block;
    width:280px;
    max-width:100%;
    height:180px;
    object-fit:cover;
    border:1px solid #dce5e2;
    border-radius:10px;
}

.remove-photo-btn{
    margin-top:9px;
    padding:7px 11px;
    background:#fff;
    border:1px solid #e6c4c4;
    border-radius:7px;
    color:#a84545;
    font-family:inherit;
    font-size:11px;
    cursor:pointer;
}

/* Existing maintenance requests */
.requests{
    padding:22px;
}

.request-card{
    margin-bottom:17px;
    padding:20px;
    border:1px solid #dce5e2;
    border-radius:12px;
    background:#fff;
}

.request-card:last-child{
    margin-bottom:0;
}

.request-top{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:20px;
    margin-bottom:14px;
}

.request-title{
    margin-bottom:5px;
    color:#304440;
    font-size:17px;
    font-weight:600;
}

.request-id{
    color:#83908e;
    font-size:12px;
}

.badges{
    display:flex;
    flex-wrap:wrap;
    justify-content:flex-end;
    gap:6px;
}

.badge{
    display:inline-block;
    padding:6px 10px;
    border-radius:20px;
    font-size:11px;
    font-weight:600;
}

.badge.green{
    background:#e8f5ee;
    color:#287a55;
}

.badge.red{
    background:#fae8e8;
    color:#a84545;
}

.badge.orange{
    background:#fff2d9;
    color:#96671e;
}

.badge.teal{
    background:#e6f3f1;
    color:#286f68;
}

.badge.grey{
    background:#edf2f1;
    color:#60716e;
}

.request-description{
    margin-bottom:17px;
    color:#52635f;
    font-size:14px;
    line-height:1.7;
}

/* Attached request image */
.attached-photo{
    margin-bottom:18px;
}

.attached-photo-label{
    margin-bottom:7px;
    color:#71817e;
    font-size:11px;
    font-weight:600;
    text-transform:uppercase;
}

.attached-photo img{
    display:block;
    width:280px;
    max-width:100%;
    height:180px;
    object-fit:cover;
    border:1px solid #dce5e2;
    border-radius:10px;
}

/* Request details */
.details-grid{
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:11px;
}

.detail{
    padding:12px;
    background:#f5f8f7;
    border-radius:8px;
}

.detail-label{
    margin-bottom:5px;
    color:#83908e;
    font-size:10px;
    font-weight:600;
    text-transform:uppercase;
}

.detail-value{
    color:#40514e;
    font-size:12px;
    font-weight:600;
}

/* Update entered by manager */
.manager-update{
    margin-top:15px;
    padding:14px 16px;
    background:#eef6f4;
    border-left:4px solid #2f8178;
    border-radius:0 8px 8px 0;
}

.manager-update strong{
    display:block;
    margin-bottom:5px;
    color:#286f68;
    font-size:12px;
}

.manager-update p{
    margin:0;
    color:#52635f;
    font-size:13px;
    line-height:1.6;
}

/* Empty state */
.empty{
    padding:40px 25px;
    color:#71817e;
    font-size:14px;
    text-align:center;
}

/* Footer matches the other tenant pages */
.footer{
    margin-top:32px;
    margin-left:-42px;
    margin-right:-42px;
    margin-bottom:-25px;
    padding:20px 25px;
    background:#2f8178;
    color:#fff;
    text-align:center;
    font-size:14px;
}

.footer strong{
    color:#fff;
    font-weight:700;
}

/* Smaller screens */
@media(max-width:1050px){
    .summary-grid{
        grid-template-columns:1fr;
    }

    .details-grid{
        grid-template-columns:1fr 1fr;
    }
}

@media(max-width:760px){
    .sidebar{
        position:relative;
        width:100%;
        height:auto;
    }

    .main{
        margin-left:0;
        padding:25px 18px;
    }

    .top-profile{
        display:none;
    }

    .property-box{
        flex-direction:column;
        align-items:flex-start;
    }

    .form-grid{
        grid-template-columns:1fr;
    }

    .details-grid{
        grid-template-columns:1fr;
    }

    .request-top{
        flex-direction:column;
    }

    .badges{
        justify-content:flex-start;
    }

    .footer{
        margin-left:-18px;
        margin-right:-18px;
    }
}
</style>
</head>

<body>

<!-- Tenant sidebar -->
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

<a href="payments.php">
<span class="menu-icon">$</span>
Rent & Utilities
</a>

<a href="maintenance.php" class="active">
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

<a href="privacy.php">
<span class="menu-icon">◆</span>
Privacy
</a>

<?php if ($unreadCommunication > 0): ?>
<span class="notification-count">
<?php echo $unreadCommunication; ?>
</span>
<?php endif; ?>

</a>

<div class="logout-area">

<a href="../auth/logout.php" class="logout">
<span class="menu-icon">↪</span>
Logout
</a>

</div>

</div>

<!-- Main content -->
<div class="main">

<div class="topbar">

<div class="page-title">
<h1>Maintenance</h1>
<p>Report property problems and track your maintenance requests.</p>
</div>

<a href="profile.php" class="top-profile">

<div class="profile-icon">
<?php echo htmlspecialchars($tenantLetter); ?>
</div>

<div class="profile-info">
<strong><?php echo htmlspecialchars($tenantName); ?></strong>
<span>Tenant</span>
</div>

</a>

</div>

<!-- Messages -->
<?php if ($success): ?>
<div class="message success">
<?php echo htmlspecialchars($success); ?>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="message error">
<?php echo htmlspecialchars($error); ?>
</div>
<?php endif; ?>

<?php if ($lease): ?>

<!-- Current property -->
<div class="property-box">

<div>

<div class="property-label">
Maintenance For
</div>

<h2>
<?php echo htmlspecialchars($lease['address_line1']); ?>
</h2>

<p>
<?php
echo htmlspecialchars(
    $lease['suburb'].', '.
    $lease['state'].' '.
    $lease['postcode']
);
?>
</p>

</div>

<div class="property-code">
<?php echo htmlspecialchars($lease['property_code']); ?>
</div>

</div>

<!-- Maintenance summary -->
<div class="summary-grid">

<div class="summary-card">
<div class="summary-label">Total Requests</div>
<div class="summary-value"><?php echo $totalRequests; ?></div>
<div class="summary-note">All maintenance requests</div>
</div>

<div class="summary-card">
<div class="summary-label">Active Requests</div>
<div class="summary-value"><?php echo $activeRequests; ?></div>
<div class="summary-note">Currently being handled</div>
</div>

<div class="summary-card">
<div class="summary-label">Completed</div>
<div class="summary-value"><?php echo $completedRequests; ?></div>
<div class="summary-note">Finished maintenance requests</div>
</div>

</div>

<!-- Maintenance request section -->
<div class="section">

<div class="section-header">

<div>
<h2>My Maintenance Requests</h2>
<p>Submit a property problem or check the latest progress.</p>
</div>

<button
type="button"
class="btn"
onclick="openRequestForm()"
>
+ New Request
</button>

</div>

<!-- New maintenance request form -->
<div class="request-form" id="requestForm">

<div class="form-intro">
Please provide clear details about the problem. You can also attach one photo to help the property manager understand the issue.
</div>

<form method="POST" enctype="multipart/form-data">

<input type="hidden" name="action" value="create_request">

<div class="form-grid">

<div class="form-group full">
<label for="requestTitle">Request Title</label>

<input
type="text"
id="requestTitle"
name="title"
placeholder="Example: Kitchen tap is leaking"
maxlength="150"
value="<?php echo htmlspecialchars($_POST['title'] ?? ''); ?>"
required
>
</div>

<div class="form-group">

<label for="category">
Category
</label>

<select id="category" name="category" required>

<option value="plumbing">Plumbing</option>
<option value="electrical">Electrical</option>
<option value="appliance">Appliance</option>
<option value="heating_cooling">Heating / Cooling</option>
<option value="structural">Structural</option>
<option value="security">Security</option>
<option value="other">Other</option>

</select>

</div>

<div class="form-group">

<label for="priority">
Priority
</label>

<select id="priority" name="priority" required>

<option value="low">Low</option>
<option value="medium" selected>Medium</option>
<option value="high">High</option>
<option value="urgent">Urgent</option>

</select>

</div>

<div class="form-group full">

<label for="description">
Describe the Problem
</label>

<textarea
id="description"
name="description"
placeholder="Explain what is wrong and where the problem is located..."
required
><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>

</div>

<!-- Optional maintenance photo -->
<div class="form-group full">

<label>
Attach Photo (Optional)
</label>

<div class="photo-upload-box">

<input
type="file"
name="maintenance_photo"
id="maintenancePhoto"
accept="image/jpeg,image/png,image/webp"
onchange="previewMaintenancePhoto(event)"
hidden
>

<button
type="button"
class="choose-photo-btn"
onclick="document.getElementById('maintenancePhoto').click()"
>
📷 Choose Photo from Computer
</button>

<div id="selectedFileName" class="selected-file">
No photo selected
</div>

<div
id="photoPreviewBox"
class="photo-preview-box"
style="display:none;"
>

<img
id="photoPreview"
src=""
alt="Selected maintenance photo"
>

<button
type="button"
class="remove-photo-btn"
onclick="removeMaintenancePhoto()"
>
Remove Photo
</button>

</div>

<div class="photo-help">
JPG, JPEG, PNG or WEBP • Maximum 5 MB
</div>

</div>

</div>

</div>

<div class="form-actions">

<button type="submit" class="btn">
Submit Request
</button>

<button
type="button"
class="btn btn-secondary"
onclick="closeRequestForm()"
>
Cancel
</button>

</div>

</form>

</div>

<!-- Existing requests -->
<?php if ($requests): ?>

<div class="requests">

<?php foreach ($requests as $request): ?>

<div class="request-card">

<div class="request-top">

<div>

<div class="request-title">
<?php echo htmlspecialchars($request['title']); ?>
</div>

<div class="request-id">
Request #<?php echo (int)$request['maintenance_id']; ?>
&nbsp; • &nbsp;
Submitted <?php echo showDate($request['submitted_at']); ?>
</div>

</div>

<div class="badges">

<span class="badge <?php echo priorityClass($request['priority']); ?>">
<?php echo htmlspecialchars(niceText($request['priority'])); ?> Priority
</span>

<span class="badge <?php echo statusClass($request['status']); ?>">
<?php echo htmlspecialchars(niceText($request['status'])); ?>
</span>

</div>

</div>

<div class="request-description">
<?php echo nl2br(htmlspecialchars($request['description'])); ?>
</div>

<!-- Photo originally submitted with request -->
<?php if (!empty($request['photo_path'])): ?>

<div class="attached-photo">

<div class="attached-photo-label">
Attached Photo
</div>

<img
src="../<?php echo htmlspecialchars($request['photo_path']); ?>"
alt="Maintenance request photo"
>

</div>

<?php endif; ?>

<!-- Maintenance details -->
<div class="details-grid">

<div class="detail">
<div class="detail-label">Category</div>

<div class="detail-value">
<?php echo htmlspecialchars(niceText($request['category'])); ?>
</div>
</div>

<div class="detail">
<div class="detail-label">Assigned To</div>

<div class="detail-value">
<?php
echo htmlspecialchars(
    $request['assigned_to'] ?: 'Not assigned yet'
);
?>
</div>
</div>

<div class="detail">
<div class="detail-label">Scheduled</div>

<div class="detail-value">
<?php echo showDate($request['scheduled_date']); ?>
</div>
</div>

<div class="detail">
<div class="detail-label">Completed</div>

<div class="detail-value">
<?php echo showDate($request['completed_at']); ?>
</div>
</div>

</div>

<!-- Manager progress message -->
<?php if (!empty($request['manager_summary'])): ?>

<div class="manager-update">

<strong>
Manager Update
</strong>

<p>
<?php
echo nl2br(
    htmlspecialchars($request['manager_summary'])
);
?>
</p>

</div>

<?php endif; ?>

</div>

<?php endforeach; ?>

</div>

<?php else: ?>

<div class="empty">
You have not submitted any maintenance requests yet.
</div>

<?php endif; ?>

</div>

<?php else: ?>

<div class="section">

<div class="empty">
You need an active lease before you can submit a maintenance request.
</div>

</div>

<?php endif; ?>

<footer class="footer">
<strong>© 2026 RentEase Property Management System</strong>
&nbsp; • &nbsp;
Renting Made Easy.
</footer>

</div>

<script>
/*
|--------------------------------------------------------------------------
| Open and Close New Request Form
|--------------------------------------------------------------------------
*/
function openRequestForm(){
    document.getElementById("requestForm").style.display="block";

    document.getElementById("requestForm").scrollIntoView({
        behavior:"smooth",
        block:"start"
    });
}

function closeRequestForm(){
    document.getElementById("requestForm").style.display="none";
}

/*
|--------------------------------------------------------------------------
| Maintenance Photo Preview
|--------------------------------------------------------------------------
| This only previews the selected image in the browser.
| The PHP section above performs the real server-side validation.
*/
function previewMaintenancePhoto(event){

    const input=event.target;
    const file=input.files[0];

    const previewBox=document.getElementById("photoPreviewBox");
    const preview=document.getElementById("photoPreview");
    const fileName=document.getElementById("selectedFileName");

    if(!file){
        return;
    }

    // Maximum photo size is 5 MB
    if(file.size>5*1024*1024){

        alert("Please choose a photo smaller than 5 MB.");

        input.value="";
        preview.src="";
        previewBox.style.display="none";
        fileName.textContent="No photo selected";

        return;
    }

    // Only normal image formats used by RentEase are accepted
    const allowedTypes=[
        "image/jpeg",
        "image/png",
        "image/webp"
    ];

    if(!allowedTypes.includes(file.type)){

        alert("Please choose a JPG, JPEG, PNG or WEBP image.");

        input.value="";
        preview.src="";
        previewBox.style.display="none";
        fileName.textContent="No photo selected";

        return;
    }

    fileName.textContent="Selected: "+file.name;
    preview.src=URL.createObjectURL(file);
    previewBox.style.display="block";
}

/*
|--------------------------------------------------------------------------
| Remove Selected Photo
|--------------------------------------------------------------------------
*/
function removeMaintenancePhoto(){

    const input=document.getElementById("maintenancePhoto");
    const preview=document.getElementById("photoPreview");
    const previewBox=document.getElementById("photoPreviewBox");
    const fileName=document.getElementById("selectedFileName");

    input.value="";
    preview.src="";
    previewBox.style.display="none";
    fileName.textContent="No photo selected";
}
</script>

</body>
</html>