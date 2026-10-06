<?php
session_start();
require_once __DIR__ . '/../config/db.php';

// Only logged-in tenants can view this page
if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit();
}

if (($_SESSION['role'] ?? '') !== 'tenant') {
    header("Location: ../manager/dashboard.php");
    exit();
}

$tenantId = (int)$_SESSION['user_id'];

// Get tenant information for the top profile area
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

// Count unread notifications for the sidebar
$stmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM notifications
    WHERE recipient_id=? AND is_read=0
");
$stmt->bind_param("i",$tenantId);
$stmt->execute();
$unreadCommunication = (int)$stmt->get_result()->fetch_assoc()['total'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Privacy | RentEase</title>

<style>
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

/* Sidebar */
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

.menu-count{
    display:flex;
    align-items:center;
    justify-content:center;
    min-width:21px;
    height:21px;
    margin-left:auto;
    padding:0 6px;
    border-radius:20px;
    background:#80c7ba;
    color:#183b3a;
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

/* Main */
.main{
    margin-left:255px;
    min-height:100vh;
    padding:35px 42px 25px;
}

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
}

.page-title p{
    margin:7px 0 0;
    color:#687976;
    font-size:16px;
}

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

/* Privacy introduction */
.privacy-banner{
    display:flex;
    align-items:center;
    gap:20px;
    margin-bottom:22px;
    padding:27px 30px;
    background:#2f8178;
    border-radius:15px;
    color:#fff;
}

.banner-icon{
    display:flex;
    align-items:center;
    justify-content:center;
    width:58px;
    height:58px;
    flex-shrink:0;
    border-radius:14px;
    background:rgba(255,255,255,.15);
    font-size:26px;
}

.privacy-banner h2{
    margin:0 0 7px;
    font-size:22px;
    font-weight:600;
}

.privacy-banner p{
    max-width:800px;
    margin:0;
    color:#e6f3f1;
    font-size:14px;
    line-height:1.6;
}

/* Privacy sections */
.privacy-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:20px;
}

.privacy-card{
    padding:24px;
    background:#fff;
    border:1px solid #dce5e2;
    border-radius:14px;
    box-shadow:0 5px 18px rgba(37,64,60,.04);
}

.card-top{
    display:flex;
    align-items:center;
    gap:12px;
    margin-bottom:14px;
}

.card-icon{
    display:flex;
    align-items:center;
    justify-content:center;
    width:40px;
    height:40px;
    flex-shrink:0;
    border-radius:10px;
    background:#e8f3f1;
    color:#2f8178;
    font-size:17px;
    font-weight:700;
}

.privacy-card h3{
    margin:0;
    color:#304440;
    font-size:18px;
    font-weight:600;
}

.privacy-card p{
    margin:0;
    color:#5f706d;
    font-size:14px;
    line-height:1.7;
}

.privacy-card ul{
    margin:12px 0 0;
    padding-left:20px;
    color:#5f706d;
    font-size:14px;
    line-height:1.8;
}

.privacy-card li{
    padding-left:3px;
}

/* Full-width cards */
.full-card{
    grid-column:1/-1;
}

/* Account security area */
.security-box{
    display:grid;
    grid-template-columns:repeat(3,1fr);
    gap:15px;
    margin-top:17px;
}

.security-item{
    padding:16px;
    background:#f4f8f7;
    border:1px solid #e0e9e6;
    border-radius:10px;
}

.security-item strong{
    display:block;
    margin-bottom:5px;
    color:#40514e;
    font-size:14px;
    font-weight:600;
}

.security-item span{
    color:#71817e;
    font-size:13px;
    line-height:1.5;
}

/* Notice */
.notice{
    margin-top:20px;
    padding:18px 20px;
    background:#eef6f4;
    border-left:4px solid #2f8178;
    border-radius:8px;
}

.notice strong{
    display:block;
    margin-bottom:5px;
    color:#304440;
    font-size:14px;
}

.notice p{
    margin:0;
    color:#60716e;
    font-size:13px;
    line-height:1.6;
}

/* Footer */
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

@media(max-width:900px){
    .privacy-grid,
    .security-box{
        grid-template-columns:1fr;
    }

    .full-card{
        grid-column:auto;
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

    .privacy-banner{
        align-items:flex-start;
        padding:22px;
    }

    .footer{
        margin-left:-18px;
        margin-right:-18px;
    }
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

<a href="maintenance.php">
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
<span class="menu-count">
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

<div class="main">

<div class="topbar">

<div class="page-title">
<h1>Privacy</h1>
<p>Understand how your information is used and protected in RentEase.</p>
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

<div class="privacy-banner">

<div class="banner-icon">◆</div>

<div>
<h2>Your Privacy Matters</h2>
<p>
RentEase uses tenant information to support lease management, rent and utility records,
maintenance requests, property inspections and communication with the property manager.
</p>
</div>

</div>

<div class="privacy-grid">

<div class="privacy-card">

<div class="card-top">
<div class="card-icon">01</div>
<h3>Information We Store</h3>
</div>

<p>
RentEase stores information required to manage your tenancy and provide the features available in your account.
</p>

<ul>
<li>Name and contact information</li>
<li>Residential and lease information</li>
<li>Emergency contact details</li>
<li>Rent and utility payment records</li>
<li>Maintenance requests and uploaded photos</li>
<li>Inspection information</li>
<li>Messages and account notifications</li>
</ul>

</div>

<div class="privacy-card">

<div class="card-top">
<div class="card-icon">02</div>
<h3>How Your Information Is Used</h3>
</div>

<p>
Your information is used only for functions connected with managing your rental property and RentEase account.
</p>

<ul>
<li>Managing your current lease</li>
<li>Recording rent and utility payments</li>
<li>Processing maintenance requests</li>
<li>Scheduling and recording inspections</li>
<li>Sending rental reminders and updates</li>
<li>Supporting communication with your property manager</li>
</ul>

</div>

<div class="privacy-card">

<div class="card-top">
<div class="card-icon">03</div>
<h3>Access to Your Information</h3>
</div>

<p>
Your tenant account is designed so that you can access information connected with your own tenancy.
Your property manager may access tenant information when it is required to manage the property,
lease, payments, maintenance or inspections.
</p>

</div>

<div class="privacy-card">

<div class="card-top">
<div class="card-icon">04</div>
<h3>Payment Information</h3>
</div>

<p>
RentEase records payment information such as the payment amount, payment status, date and
transaction reference. Card details entered during payment are not intended to be stored
in the RentEase database.
</p>

</div>

<div class="privacy-card full-card">

<div class="card-top">
<div class="card-icon">05</div>
<h3>Account & Data Security</h3>
</div>

<p>
RentEase uses account access controls to help prevent tenants from viewing another tenant's
private rental information. You should also take reasonable steps to protect your own account.
</p>

<div class="security-box">

<div class="security-item">
<strong>Keep Your Password Private</strong>
<span>Do not share your RentEase password with other people.</span>
</div>

<div class="security-item">
<strong>Use Your Own Account</strong>
<span>Always sign in using your personal tenant account.</span>
</div>

<div class="security-item">
<strong>Log Out When Finished</strong>
<span>Log out after using RentEase on a shared or public computer.</span>
</div>

</div>

</div>

<div class="privacy-card">

<div class="card-top">
<div class="card-icon">06</div>
<h3>Your Responsibilities</h3>
</div>

<p>
Please provide accurate information when updating your contact details, submitting
maintenance requests, making payments or communicating through RentEase.
</p>

</div>

<div class="privacy-card">

<div class="card-top">
<div class="card-icon">07</div>
<h3>Updating Your Information</h3>
</div>

<p>
You can update permitted personal information through your Profile page. Some information,
such as your property address and lease details, is controlled by property management records
and cannot be directly changed from your tenant account.
</p>

</div>

</div>

<div class="notice">

<strong>Privacy Questions</strong>

<p>
If you believe information in your RentEase account is incorrect or you have a question
about how your tenancy information is being handled, contact your property manager through
the Communication page.
</p>

</div>

<footer class="footer">
<strong>© 2026 RentEase Property Management System</strong>
&nbsp; • &nbsp;
Renting Made Easy.
</footer>

</div>

</body>
</html>