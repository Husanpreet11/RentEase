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

// Manager information
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

// Search properties
$search=trim($_GET['search']??'');
$statusFilter=trim($_GET['status']??'');

$allowedStatuses=[
    'vacant',
    'occupied',
    'maintenance',
    'inactive'
];

// Get property information and primary photo
$sql="SELECT
p.property_id,
p.property_code,
p.address_line1,
p.suburb,
p.state,
p.postcode,
p.bedrooms,
p.bathrooms,
p.parking_spaces,
p.weekly_rent,
p.property_status,
p.description,
pp.file_path AS primary_photo
FROM properties p
LEFT JOIN property_photos pp
ON p.property_id=pp.property_id
AND pp.is_primary=1
WHERE 1=1";

$params=[];
$types="";

if($search!==''){
    $sql.=" AND (
    p.property_code LIKE ?
    OR p.address_line1 LIKE ?
    OR p.suburb LIKE ?
    OR p.state LIKE ?
    OR p.postcode LIKE ?
    )";

    $searchValue="%".$search."%";

    for($i=0;$i<5;$i++){
        $params[]=$searchValue;
    }

    $types.="sssss";
}

if($statusFilter!=='' && in_array($statusFilter,$allowedStatuses,true)){
    $sql.=" AND p.property_status=?";
    $params[]=$statusFilter;
    $types.="s";
}

$sql.=" ORDER BY p.property_id DESC";

$stmt=$conn->prepare($sql);

if(!empty($params)){
    $stmt->bind_param($types,...$params);
}

$stmt->execute();
$properties=$stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Properties | RentEase</title>

<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:"Segoe UI",Arial,sans-serif;background:#f5f7f6;color:#243331;font-size:14px}

/* Sidebar */
.sidebar{position:fixed;top:0;left:0;width:255px;height:100vh;background:#183b3a;padding:25px 18px;overflow-y:auto}
.brand{padding:0 10px 25px}
.brand-name{font-size:24px;font-weight:700;color:#fff}
.brand-name span{color:#8bc7c0}
.brand-tagline{margin-top:4px;color:#b7d1cd;font-size:11px}
.menu-title{margin:20px 10px 8px;color:#8fb0ac;font-size:10px;font-weight:700;letter-spacing:1px;text-transform:uppercase}
.sidebar a{display:flex;align-items:center;gap:11px;padding:11px 12px;margin-bottom:4px;border-radius:8px;color:#d6e4e2;font-size:15px;text-decoration:none;transition:.2s}
.sidebar a:hover{background:#24514e;color:#fff}
.sidebar a.active{background:#2f8178;color:#fff;font-weight:600}
.menu-icon{width:20px;text-align:center}
.logout-area{margin-top:25px;padding-top:15px;border-top:1px solid #315553}
.sidebar .logout{color:#f1c1c1}
.sidebar .logout:hover{background:#68403e;color:#fff}

/* Main page */
.main{margin-left:255px;min-height:100vh;padding:30px 36px 0}
.topbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:22px}
.page-title h1{font-size:30px;font-weight:650;margin-bottom:4px;color:#243331}
.page-title p{color:#687976;font-size:14px}

/* Top manager profile */
.top-profile{display:flex;align-items:center;gap:11px;min-width:205px;padding:9px 13px;background:#fff;border:1px solid #dce5e2;border-radius:12px;color:#243331;text-decoration:none}
.top-profile:hover{border-color:#9fc8c2;box-shadow:0 4px 14px rgba(47,129,120,.08)}
.profile-icon{width:42px;height:42px;display:flex;align-items:center;justify-content:center;background:#2f8178;color:#fff;border-radius:50%;font-weight:700}
.profile-info{display:flex;flex-direction:column}
.profile-info strong{font-size:14px;font-weight:600}
.profile-info span{margin-top:2px;color:#71817e;font-size:11px}

/* Heading and add property */
.page-actions{display:flex;align-items:center;justify-content:space-between;margin-bottom:15px}
.section-heading h2{font-size:18px;font-weight:600;margin-bottom:3px}
.section-heading p{font-size:12px;color:#71817e}
.add-button{display:inline-flex;align-items:center;justify-content:center;padding:10px 16px;background:#2f8178;color:#fff;border-radius:8px;text-decoration:none;font-size:13px;font-weight:600}
.add-button:hover{background:#286f68}

/* Compact search */
.search-card{background:#fff;border:1px solid #dce5e2;border-radius:10px;padding:11px;margin-bottom:18px}
.search-form{display:grid;grid-template-columns:minmax(250px,1fr) 180px auto;gap:9px}
.search-form input,.search-form select{width:100%;height:39px;padding:8px 11px;border:1px solid #ccd9d6;border-radius:8px;background:#fff;color:#40514e;font-family:inherit;font-size:13px;outline:none}
.search-form input:focus,.search-form select:focus{border-color:#2f8178;box-shadow:0 0 0 3px rgba(47,129,120,.08)}
.search-button{height:39px;padding:0 19px;border:0;border-radius:8px;background:#2f8178;color:#fff;font-family:inherit;font-size:13px;font-weight:600;cursor:pointer}
.search-button:hover{background:#286f68}

/* Property grid */
.property-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px;align-items:start}
.property-card{overflow:hidden;background:#fff;border:1px solid #dce5e2;border-radius:13px;box-shadow:0 3px 10px rgba(35,70,66,.04);transition:.2s}
.property-card:hover{transform:translateY(-2px);box-shadow:0 7px 20px rgba(35,70,66,.08)}

/* Large property image */
.property-image{position:relative;width:100%;aspect-ratio:16/10;background:#e8efed;overflow:hidden}
.property-image img{display:block;width:100%;height:100%;object-fit:cover}
.no-photo{width:100%;height:100%;display:flex;flex-direction:column;align-items:center;justify-content:center;background:#edf3f1;color:#82918e}
.no-photo-icon{font-size:28px;margin-bottom:5px}
.no-photo span{font-size:11px}

/* Status over photo */
.status{position:absolute;top:11px;right:11px;padding:5px 10px;border-radius:20px;font-size:10px;font-weight:600;text-transform:capitalize;box-shadow:0 2px 7px rgba(0,0,0,.07)}
.status-occupied{background:#e7f4ed;color:#2f6f54}
.status-vacant{background:#e8f3f1;color:#2f8178}
.status-maintenance{background:#fff3df;color:#936526}
.status-inactive{background:#edf0ef;color:#687976}

/* Compact information area */
.property-content{padding:13px 15px 14px}
.property-code{margin-bottom:3px;color:#2f8178;font-size:10px;font-weight:700;letter-spacing:.2px}
.property-address{margin-bottom:2px;color:#243331;font-size:16px;font-weight:600;line-height:1.3}
.property-location{margin-bottom:9px;color:#71817e;font-size:11px}

/* Features use little space */
.property-features{display:flex;align-items:center;gap:17px;padding:8px 0;margin-bottom:9px;border-top:1px solid #edf1f0;border-bottom:1px solid #edf1f0}
.feature{display:flex;align-items:center;gap:4px;color:#71817e;font-size:10px;white-space:nowrap}
.feature strong{color:#40514e;font-size:12px;font-weight:600}

/* Rent */
.rent-row{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}
.rent-label{color:#71817e;font-size:10px}
.rent-amount{color:#40514e;font-size:16px;font-weight:600}
.rent-amount span{color:#71817e;font-size:9px;font-weight:400}

/* Buttons */
.property-actions{display:grid;grid-template-columns:1fr auto;gap:7px}
.view-button,.edit-button{padding:8px 11px;border-radius:7px;text-align:center;text-decoration:none;font-size:11px;font-weight:600}
.view-button{background:#2f8178;color:#fff;border:1px solid #2f8178}
.view-button:hover{background:#286f68}
.edit-button{background:#fff;color:#2f8178;border:1px solid #b8d4cf}
.edit-button:hover{background:#eef6f4}

/* No results */
.empty-state{grid-column:1/-1;padding:42px 20px;background:#fff;border:1px solid #dce5e2;border-radius:12px;text-align:center}
.empty-state h3{margin-bottom:6px;color:#40514e;font-size:17px;font-weight:600}
.empty-state p{color:#71817e;font-size:12px}

/* Footer */
.footer{margin:32px -36px 0;padding:20px 30px;background:#183b3a;color:#c7d9d6;font-size:13px;text-align:center}
.footer strong{color:#fff}

@media(max-width:1150px){
.property-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
}
@media(max-width:850px){
.search-form{grid-template-columns:1fr}
.property-grid{grid-template-columns:1fr}
}
@media(max-width:760px){
.sidebar{position:relative;width:100%;height:auto}
.main{margin-left:0;padding:22px}
.topbar{align-items:flex-start;flex-direction:column;gap:15px}
.page-actions{align-items:flex-start;gap:12px}
.property-image{aspect-ratio:16/9}
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
<h1>Property Management</h1>
<p>View and manage RentEase properties.</p>
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

<!-- Properties heading -->
<div class="page-actions">

<div class="section-heading">
<h2>Properties</h2>
<p>Manage your current property portfolio.</p>
</div>

<a href="add_property.php" class="add-button">
+ Add Property
</a>

</div>

<!-- Search -->
<div class="search-card">

<form method="GET" class="search-form">

<input
type="text"
name="search"
placeholder="Search by property code, address or suburb..."
value="<?php echo htmlspecialchars($search); ?>"
>

<select name="status">

<option value="">All Statuses</option>

<option value="occupied" <?php echo $statusFilter==='occupied'?'selected':''; ?>>
Occupied
</option>

<option value="vacant" <?php echo $statusFilter==='vacant'?'selected':''; ?>>
Vacant
</option>

<option value="maintenance" <?php echo $statusFilter==='maintenance'?'selected':''; ?>>
Maintenance
</option>

<option value="inactive" <?php echo $statusFilter==='inactive'?'selected':''; ?>>
Inactive
</option>

</select>

<button type="submit" class="search-button">
Search
</button>

</form>

</div>

<!-- Property cards -->
<div class="property-grid">

<?php if($properties->num_rows>0): ?>

<?php while($property=$properties->fetch_assoc()): ?>

<div class="property-card">

<div class="property-image">

<?php if(!empty($property['primary_photo'])): ?>

<img
src="../../<?php echo htmlspecialchars($property['primary_photo']); ?>"
alt="<?php echo htmlspecialchars($property['address_line1']); ?>"
onerror="this.style.display='none';this.nextElementSibling.style.display='flex';"
>

<div class="no-photo" style="display:none">
<div class="no-photo-icon">▣</div>
<span>Property photo unavailable</span>
</div>

<?php else: ?>

<div class="no-photo">
<div class="no-photo-icon">▣</div>
<span>No property photo available</span>
</div>

<?php endif; ?>

<span class="status status-<?php echo htmlspecialchars($property['property_status']); ?>">
<?php echo htmlspecialchars(ucfirst($property['property_status'])); ?>
</span>

</div>

<div class="property-content">

<div class="property-code">
<?php echo htmlspecialchars($property['property_code']); ?>
</div>

<div class="property-address">
<?php echo htmlspecialchars($property['address_line1']); ?>
</div>

<div class="property-location">
<?php
echo htmlspecialchars(
    $property['suburb'].', '.
    $property['state'].' '.
    $property['postcode']
);
?>
</div>

<div class="property-features">

<div class="feature">
<strong><?php echo htmlspecialchars($property['bedrooms']); ?></strong>
<span>Bedrooms</span>
</div>

<div class="feature">
<strong><?php echo htmlspecialchars($property['bathrooms']); ?></strong>
<span>Bathrooms</span>
</div>

<div class="feature">
<strong><?php echo htmlspecialchars($property['parking_spaces']); ?></strong>
<span>Parking</span>
</div>

</div>

<div class="rent-row">

<div class="rent-label">
Weekly Rent
</div>

<div class="rent-amount">
$<?php echo number_format((float)$property['weekly_rent'],2); ?>
<span>/ week</span>
</div>

</div>

<div class="property-actions">

<a
href="view_property.php?id=<?php echo (int)$property['property_id']; ?>"
class="view-button"
>
View Details
</a>

<a
href="edit_property.php?id=<?php echo (int)$property['property_id']; ?>"
class="edit-button"
>
Edit
</a>

</div>

</div>

</div>

<?php endwhile; ?>

<?php else: ?>

<div class="empty-state">
<h3>No properties found</h3>
<p>Try another property name, address or status.</p>
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