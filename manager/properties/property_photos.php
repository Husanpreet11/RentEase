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

if($propertyId<=0){
header("Location: properties.php");
exit();
}

// Flash messages
$message=$_SESSION['photo_success']??'';
$error=$_SESSION['photo_error']??'';
unset($_SESSION['photo_success'],$_SESSION['photo_error']);

// Manager details
$stmt=$conn->prepare("
SELECT u.first_name,u.last_name,mp.job_title
FROM users u
LEFT JOIN manager_profiles mp ON u.user_id=mp.manager_id
WHERE u.user_id=?
LIMIT 1
");
$stmt->bind_param("i",$userId);
$stmt->execute();
$manager=$stmt->get_result()->fetch_assoc();

if(!$manager){
session_destroy();
header("Location: ../../index.php");
exit();
}

$fullName=trim($manager['first_name'].' '.$manager['last_name']);
$profileLetter=strtoupper(substr($manager['first_name'],0,1));
$jobTitle=!empty($manager['job_title'])?$manager['job_title']:'Property Manager';

// Get property
$stmt=$conn->prepare("
SELECT property_id,property_code,address_line1,suburb,state,postcode
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

// Correct physical upload folder:
// RR/uploads/properties/
$uploadFolder=__DIR__.'/../../uploads/properties/';

// Create folder if it does not exist
if(!is_dir($uploadFolder)){
mkdir($uploadFolder,0775,true);
}

// Upload new property photo
if(isset($_POST['upload_photo'])){
$caption=trim($_POST['caption']??'');

if(!isset($_FILES['property_photo'])){
$_SESSION['photo_error']="Please select a property photo.";
header("Location: property_photos.php?id=".$propertyId);
exit();
}

$file=$_FILES['property_photo'];

if($file['error']!==UPLOAD_ERR_OK){
switch($file['error']){
case UPLOAD_ERR_INI_SIZE:
case UPLOAD_ERR_FORM_SIZE:
$_SESSION['photo_error']="The selected photo is too large.";
break;
case UPLOAD_ERR_NO_FILE:
$_SESSION['photo_error']="Please select a property photo.";
break;
default:
$_SESSION['photo_error']="The photo could not be uploaded.";
}
header("Location: property_photos.php?id=".$propertyId);
exit();
}

// Maximum 5 MB
if($file['size']>5*1024*1024){
$_SESSION['photo_error']="The photo must be smaller than 5 MB.";
header("Location: property_photos.php?id=".$propertyId);
exit();
}

// Make sure it is a real image
$imageInfo=@getimagesize($file['tmp_name']);

if($imageInfo===false){
$_SESSION['photo_error']="The selected file is not a valid image.";
header("Location: property_photos.php?id=".$propertyId);
exit();
}

// Allowed image types
$allowedTypes=[
IMAGETYPE_JPEG=>'jpg',
IMAGETYPE_PNG=>'png',
IMAGETYPE_WEBP=>'webp'
];

$imageType=$imageInfo[2];

if(!isset($allowedTypes[$imageType])){
$_SESSION['photo_error']="Only JPG, JPEG, PNG and WEBP images are allowed.";
header("Location: property_photos.php?id=".$propertyId);
exit();
}

$extension=$allowedTypes[$imageType];

// Create unique safe filename
$fileName='property_'.$propertyId.'_'.date('YmdHis').'_'.bin2hex(random_bytes(3)).'.'.$extension;

$destination=$uploadFolder.$fileName;

// Path stored in database
$databasePath='uploads/properties/'.$fileName;

// Move image from temporary computer upload to RentEase folder
if(!move_uploaded_file($file['tmp_name'],$destination)){
$_SESSION['photo_error']="Unable to save the uploaded photo.";
header("Location: property_photos.php?id=".$propertyId);
exit();
}

// Check whether property already has photos
$stmt=$conn->prepare("
SELECT COUNT(*) AS total
FROM property_photos
WHERE property_id=?
");
$stmt->bind_param("i",$propertyId);
$stmt->execute();
$photoCount=$stmt->get_result()->fetch_assoc();

$isPrimary=((int)$photoCount['total']===0)?1:0;

// Save image path in database
$stmt=$conn->prepare("
INSERT INTO property_photos
(property_id,file_path,caption,is_primary)
VALUES(?,?,?,?)
");
$stmt->bind_param(
"issi",
$propertyId,
$databasePath,
$caption,
$isPrimary
);

if($stmt->execute()){
$_SESSION['photo_success']="Property photo uploaded successfully.";
}else{
if(is_file($destination)){
unlink($destination);
}
$_SESSION['photo_error']="Unable to save the photo information.";
}

header("Location: property_photos.php?id=".$propertyId);
exit();
}

// Make selected photo primary
if(isset($_POST['make_primary'])){
$photoId=(int)($_POST['photo_id']??0);

if($photoId<=0){
$_SESSION['photo_error']="Invalid photo selected.";
header("Location: property_photos.php?id=".$propertyId);
exit();
}

// Make sure photo belongs to current property
$stmt=$conn->prepare("
SELECT photo_id
FROM property_photos
WHERE photo_id=?
AND property_id=?
LIMIT 1
");
$stmt->bind_param("ii",$photoId,$propertyId);
$stmt->execute();
$photoExists=$stmt->get_result()->fetch_assoc();

if(!$photoExists){
$_SESSION['photo_error']="Property photo could not be found.";
header("Location: property_photos.php?id=".$propertyId);
exit();
}

$conn->begin_transaction();

try{
$stmt=$conn->prepare("
UPDATE property_photos
SET is_primary=0
WHERE property_id=?
");
$stmt->bind_param("i",$propertyId);
$stmt->execute();

$stmt=$conn->prepare("
UPDATE property_photos
SET is_primary=1
WHERE photo_id=?
AND property_id=?
");
$stmt->bind_param("ii",$photoId,$propertyId);
$stmt->execute();

$conn->commit();

$_SESSION['photo_success']="Primary photo updated.";
}catch(Throwable $e){
$conn->rollback();
$_SESSION['photo_error']="Unable to change the primary photo.";
}

header("Location: property_photos.php?id=".$propertyId);
exit();
}

// Delete photo
if(isset($_POST['delete_photo'])){
$photoId=(int)($_POST['photo_id']??0);

$stmt=$conn->prepare("
SELECT file_path,is_primary
FROM property_photos
WHERE photo_id=?
AND property_id=?
LIMIT 1
");
$stmt->bind_param("ii",$photoId,$propertyId);
$stmt->execute();
$photo=$stmt->get_result()->fetch_assoc();

if(!$photo){
$_SESSION['photo_error']="Property photo could not be found.";
header("Location: property_photos.php?id=".$propertyId);
exit();
}

$wasPrimary=((int)$photo['is_primary']===1);

$conn->begin_transaction();

try{
// Delete database record
$stmt=$conn->prepare("
DELETE FROM property_photos
WHERE photo_id=?
AND property_id=?
");
$stmt->bind_param("ii",$photoId,$propertyId);
$stmt->execute();

// If primary was deleted, select another photo
if($wasPrimary){
$stmt=$conn->prepare("
SELECT photo_id
FROM property_photos
WHERE property_id=?
ORDER BY photo_id ASC
LIMIT 1
");
$stmt->bind_param("i",$propertyId);
$stmt->execute();
$nextPhoto=$stmt->get_result()->fetch_assoc();

if($nextPhoto){
$nextPhotoId=(int)$nextPhoto['photo_id'];

$stmt=$conn->prepare("
UPDATE property_photos
SET is_primary=1
WHERE photo_id=?
AND property_id=?
");
$stmt->bind_param("ii",$nextPhotoId,$propertyId);
$stmt->execute();
}
}

$conn->commit();

// Correct physical delete path:
// RR/uploads/properties/filename
$fileName=basename(str_replace('\\','/',$photo['file_path']));
$physicalFile=$uploadFolder.$fileName;

if(is_file($physicalFile)){
unlink($physicalFile);
}

$_SESSION['photo_success']="Photo deleted successfully.";

}catch(Throwable $e){
$conn->rollback();
$_SESSION['photo_error']="Unable to delete the photo.";
}

header("Location: property_photos.php?id=".$propertyId);
exit();
}

// Get property photos
$stmt=$conn->prepare("
SELECT *
FROM property_photos
WHERE property_id=?
ORDER BY is_primary DESC,photo_id DESC
");
$stmt->bind_param("i",$propertyId);
$stmt->execute();
$photos=$stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Property Photos | RentEase</title>

<style>
*{
margin:0;
padding:0;
box-sizing:border-box;
}

body{
font-family:"Segoe UI",Arial,sans-serif;
background:#f5f7f6;
color:#4f5d5b;
font-size:14px;
}

/* SIDEBAR */

.sidebar{
position:fixed;
top:0;
left:0;
width:255px;
height:100vh;
padding:25px 18px;
background:#183b3a;
overflow-y:auto;
}

.brand{
padding:0 10px 25px;
border-bottom:1px solid rgba(255,255,255,.10);
}

.brand-name{
font-size:24px;
font-weight:750;
color:#fff;
}

.brand-name span{
color:#8bc7c0;
}

.brand-tagline{
margin-top:4px;
font-size:11px;
color:#b7d1cd;
}

.menu-title{
margin:20px 10px 8px;
color:#8fb0ac;
font-size:10px;
font-weight:700;
letter-spacing:1px;
text-transform:uppercase;
}

.sidebar a{
display:flex;
align-items:center;
gap:11px;
padding:11px 12px;
margin-bottom:4px;
border-radius:8px;
color:#d6e4e2;
font-size:15px;
text-decoration:none;
transition:.2s;
}

.sidebar a:hover{
background:#24514e;
color:#fff;
}

.sidebar a.active{
background:#2f8178;
color:#fff;
font-weight:600;
}

.menu-icon{
width:20px;
text-align:center;
}

.logout-area{
margin-top:25px;
padding-top:15px;
border-top:1px solid #315553;
}

.sidebar .logout{
color:#f1c1c1;
}

.sidebar .logout:hover{
background:#68403e;
color:#fff;
}

/* MAIN */

.main{
margin-left:255px;
min-height:100vh;
padding:30px 36px 0;
}

.topbar{
display:flex;
justify-content:space-between;
align-items:center;
margin-bottom:23px;
}

.page-title h1{
margin-bottom:5px;
font-size:32px;
color:#344441;
}

.page-title p{
font-size:15px;
color:#71817e;
}

.top-profile{
display:flex;
align-items:center;
gap:11px;
min-width:205px;
padding:9px 13px;
background:#fff;
border:1px solid #dce5e2;
border-radius:12px;
color:#40514e;
text-decoration:none;
}

.top-profile:hover{
border-color:#9fc8c2;
box-shadow:0 4px 14px rgba(47,129,120,.08);
}

.profile-icon{
display:flex;
align-items:center;
justify-content:center;
width:42px;
height:42px;
background:#2f8178;
color:#fff;
border-radius:50%;
font-weight:700;
}

.profile-info{
display:flex;
flex-direction:column;
}

.profile-info strong{
font-size:14px;
color:#40514e;
}

.profile-info span{
margin-top:2px;
font-size:11px;
color:#71817e;
}

/* NAVIGATION */

.back-link{
display:inline-block;
margin-bottom:18px;
color:#2f8178;
font-size:13px;
font-weight:600;
text-decoration:none;
}

.back-link:hover{
color:#286f68;
}

/* PROPERTY HEADING */

.property-heading{
padding:20px;
margin-bottom:20px;
background:#fff;
border:1px solid #dce5e2;
border-radius:13px;
box-shadow:0 3px 12px rgba(35,70,66,.04);
}

.property-code{
margin-bottom:5px;
color:#2f8178;
font-size:11px;
font-weight:700;
}

.property-heading h2{
margin-bottom:4px;
color:#344441;
font-size:20px;
font-weight:600;
}

.property-heading p{
color:#71817e;
font-size:12px;
}

/* MESSAGES */

.message{
padding:13px 15px;
margin-bottom:18px;
border-radius:9px;
font-size:13px;
}

.success{
background:#eef7f2;
border:1px solid #cfe6d9;
color:#2f6f54;
}

.error{
background:#fff1f1;
border:1px solid #f0cccc;
color:#9b4141;
}

/* CARDS */

.card{
padding:22px;
margin-bottom:20px;
background:#fff;
border:1px solid #dce5e2;
border-radius:14px;
box-shadow:0 3px 12px rgba(35,70,66,.04);
}

.card h3{
margin-bottom:5px;
color:#344441;
font-size:19px;
font-weight:600;
}

.card-description{
margin-bottom:20px;
color:#71817e;
font-size:13px;
}

/* UPLOAD */

.upload-grid{
display:grid;
grid-template-columns:1fr 1fr auto;
gap:12px;
align-items:end;
}

.form-group{
display:flex;
flex-direction:column;
}

.form-group label{
margin-bottom:7px;
color:#5f706d;
font-size:12px;
font-weight:600;
}

.form-group input{
min-height:45px;
padding:10px 12px;
border:1px solid #ccd9d6;
border-radius:8px;
background:#fff;
color:#40514e;
font-family:inherit;
font-size:12px;
outline:none;
}

.form-group input:focus{
border-color:#2f8178;
box-shadow:0 0 0 3px rgba(47,129,120,.08);
}

input[type=file]{
padding:8px;
}

input[type=file]::file-selector-button{
margin-right:10px;
padding:7px 12px;
border:1px solid #b9cbc7;
border-radius:6px;
background:#eef6f4;
color:#40514e;
font-family:inherit;
cursor:pointer;
}

input[type=file]::file-selector-button:hover{
background:#e1efec;
}

.upload-button{
min-height:45px;
padding:11px 19px;
border:0;
border-radius:8px;
background:#2f8178;
color:#fff;
font-family:inherit;
font-size:13px;
font-weight:600;
cursor:pointer;
}

.upload-button:hover{
background:#286f68;
}

/* PHOTO GALLERY */

.photo-grid{
display:grid;
grid-template-columns:repeat(3,1fr);
gap:16px;
}

.photo-card{
overflow:hidden;
background:#fff;
border:1px solid #dce5e2;
border-radius:12px;
}

.photo-image{
position:relative;
height:220px;
background:#eef3f2;
}

.photo-image img{
display:block;
width:100%;
height:100%;
object-fit:cover;
}

.primary-badge{
position:absolute;
top:10px;
left:10px;
padding:6px 10px;
background:#2f8178;
color:#fff;
border-radius:20px;
font-size:10px;
font-weight:700;
}

.photo-info{
padding:14px;
}

.caption{
min-height:20px;
margin-bottom:12px;
color:#52615f;
font-size:12px;
}

.photo-actions{
display:flex;
gap:8px;
}

.photo-actions form{
flex:1;
}

.action-button{
width:100%;
padding:9px;
border:0;
border-radius:7px;
font-family:inherit;
font-size:11px;
font-weight:600;
cursor:pointer;
}

.primary-button{
background:#2f8178;
color:#fff;
}

.primary-button:hover{
background:#286f68;
}

.delete-button{
background:#fff1f1;
color:#b44a4a;
border:1px solid #efd0d0;
}

.delete-button:hover{
background:#fbe3e3;
}

.primary-text{
flex:1;
padding:9px;
background:#eaf5f2;
border-radius:7px;
color:#2f8178;
text-align:center;
font-size:11px;
font-weight:600;
}

/* EMPTY */

.empty-state{
padding:45px;
background:#f7faf9;
border:1px dashed #ccd9d6;
border-radius:10px;
text-align:center;
}

.empty-state h4{
margin-bottom:6px;
color:#40514e;
font-size:16px;
}

.empty-state p{
color:#71817e;
font-size:12px;
}

/* FOOTER */

.footer{
margin:32px -36px 0;
padding:20px;
background:#2f8178;
color:#cfe3df;
text-align:center;
font-size:12px;
}

.footer strong{
color:#fff;
}

/* RESPONSIVE */

@media(max-width:1050px){
.photo-grid{
grid-template-columns:repeat(2,1fr);
}

.upload-grid{
grid-template-columns:1fr;
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
padding:22px 22px 0;
}

.topbar{
align-items:flex-start;
flex-direction:column;
gap:15px;
}

.photo-grid{
grid-template-columns:1fr;
}

.footer{
margin-left:-22px;
margin-right:-22px;
}
}
</style>
</head>

<body>

<div class="sidebar">

<div class="brand">
<div class="brand-name">
Rent<span>Ease</span>
</div>
<div class="brand-tagline">
Renting Made Easy.
</div>
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
<span class="menu-icon">♟</span>
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
<h1>Property Photos</h1>
<p>Upload and manage property images.</p>
</div>

<a href="../profile.php" class="top-profile">

<div class="profile-icon">
<?php echo htmlspecialchars($profileLetter); ?>
</div>

<div class="profile-info">

<strong>
<?php echo htmlspecialchars($fullName); ?>
</strong>

<span>
<?php echo htmlspecialchars($jobTitle); ?>
</span>

</div>

</a>

</div>

<a href="view_property.php?id=<?php echo $propertyId; ?>" class="back-link">
← Back to Property Details
</a>

<div class="property-heading">

<div class="property-code">
<?php echo htmlspecialchars($property['property_code']); ?>
</div>

<h2>
<?php echo htmlspecialchars($property['address_line1']); ?>
</h2>

<p>
<?php
echo htmlspecialchars(
$property['suburb'].', '.
$property['state'].' '.
$property['postcode']
);
?>
</p>

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

<div class="card">

<h3>Upload New Photo</h3>

<div class="card-description">
Choose a JPG, JPEG, PNG or WEBP image from your computer. Maximum file size is 5 MB.
</div>

<form method="POST" enctype="multipart/form-data" class="upload-grid">

<div class="form-group">

<label for="property_photo">
Property Photo
</label>

<input
type="file"
id="property_photo"
name="property_photo"
accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
required
>

</div>

<div class="form-group">

<label for="caption">
Caption
</label>

<input
type="text"
id="caption"
name="caption"
maxlength="255"
placeholder="Example: Front view"
>

</div>

<button
type="submit"
name="upload_photo"
class="upload-button"
>
Upload Photo
</button>

</form>

</div>

<div class="card">

<h3>Property Gallery</h3>

<div class="card-description">
The primary image is displayed on the main property page.
</div>

<?php if($photos->num_rows>0): ?>

<div class="photo-grid">

<?php while($photo=$photos->fetch_assoc()): ?>

<div class="photo-card">

<div class="photo-image">

<img
src="../../<?php echo htmlspecialchars($photo['file_path']); ?>?v=<?php echo (int)$photo['photo_id']; ?>"
alt="Property photo"
>

<?php if((int)$photo['is_primary']===1): ?>

<div class="primary-badge">
Primary
</div>

<?php endif; ?>

</div>

<div class="photo-info">

<div class="caption">

<?php
echo !empty($photo['caption'])
?htmlspecialchars($photo['caption'])
:'No caption';
?>

</div>

<div class="photo-actions">

<?php if((int)$photo['is_primary']!==1): ?>

<form method="POST">

<input
type="hidden"
name="photo_id"
value="<?php echo (int)$photo['photo_id']; ?>"
>

<button
type="submit"
name="make_primary"
class="action-button primary-button"
>
Make Primary
</button>

</form>

<?php else: ?>

<div class="primary-text">
Main Photo
</div>

<?php endif; ?>

<form
method="POST"
onsubmit="return confirm('Delete this property photo?');"
>

<input
type="hidden"
name="photo_id"
value="<?php echo (int)$photo['photo_id']; ?>"
>

<button
type="submit"
name="delete_photo"
class="action-button delete-button"
>
Delete
</button>

</form>

</div>

</div>

</div>

<?php endwhile; ?>

</div>

<?php else: ?>

<div class="empty-state">

<h4>No Photos Yet</h4>

<p>
Upload the first photo for this property. It will automatically become the primary image.
</p>

</div>

<?php endif; ?>

</div>

<footer class="footer">
<strong>© 2026 RentEase Property Management System</strong>
&nbsp; • &nbsp;
Renting Made Easy.
</footer>

</div>

</body>
</html>