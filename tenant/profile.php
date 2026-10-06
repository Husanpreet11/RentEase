<?php
session_start();
require_once __DIR__ . '/../config/db.php';

// Tenant must be logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit();
}

if (($_SESSION['role'] ?? '') !== 'tenant') {
    header("Location: ../manager/dashboard.php");
    exit();
}

$tenantId = (int) $_SESSION['user_id'];
$error = "";

// This message is shown once after an update
$success = $_SESSION['profile_success'] ?? '';
unset($_SESSION['profile_success']);

// Update personal information
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {

    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $dob = trim($_POST['date_of_birth'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {

        // Check if another account already has this email
        $check = $conn->prepare("
            SELECT user_id
            FROM users
            WHERE email = ? AND user_id != ?
        ");

        $check->bind_param("si", $email, $tenantId);
        $check->execute();

        if ($check->get_result()->num_rows > 0) {
            $error = "This email address is already being used.";
        } else {

            $conn->begin_transaction();

            try {

                // Update email and phone
                $stmt = $conn->prepare("
                    UPDATE users
                    SET email = ?, phone = ?, updated_at = NOW()
                    WHERE user_id = ?
                ");

                $stmt->bind_param("ssi", $email, $phone, $tenantId);
                $stmt->execute();

                // Save date of birth
                $stmt = $conn->prepare("
                    INSERT INTO tenant_profiles
                    (tenant_id, date_of_birth)
                    VALUES (?, NULLIF(?, ''))
                    ON DUPLICATE KEY UPDATE
                    date_of_birth = VALUES(date_of_birth)
                ");

                $stmt->bind_param("is", $tenantId, $dob);
                $stmt->execute();

                $conn->commit();

                $_SESSION['email'] = $email;
                $_SESSION['profile_success'] = "Your personal information has been updated.";

                // Redirect stops the form from running again on refresh
                header("Location: profile.php");
                exit();

            } catch (Throwable $e) {

                $conn->rollback();
                $error = "Your profile could not be updated. Please try again.";
            }
        }
    }
}

// Update emergency contact
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_emergency'])) {

    $emergencyName = trim($_POST['emergency_contact_name'] ?? '');
    $emergencyPhone = trim($_POST['emergency_contact_phone'] ?? '');

    $stmt = $conn->prepare("
        INSERT INTO tenant_profiles
        (tenant_id, emergency_contact_name, emergency_contact_phone)
        VALUES (?, NULLIF(?, ''), NULLIF(?, ''))
        ON DUPLICATE KEY UPDATE
        emergency_contact_name = VALUES(emergency_contact_name),
        emergency_contact_phone = VALUES(emergency_contact_phone)
    ");

    $stmt->bind_param(
        "iss",
        $tenantId,
        $emergencyName,
        $emergencyPhone
    );

    if ($stmt->execute()) {

        $_SESSION['profile_success'] = "Emergency contact has been updated.";
        header("Location: profile.php");
        exit();

    } else {

        $error = "Emergency contact could not be updated.";
    }
}

// Change password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {

    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (strlen($newPassword) < 8) {

        $error = "New password must contain at least 8 characters.";

    } elseif ($newPassword !== $confirmPassword) {

        $error = "The new passwords do not match.";

    } else {

        // Get the current password from the database
        $stmt = $conn->prepare("
            SELECT password_hash
            FROM users
            WHERE user_id = ?
        ");

        $stmt->bind_param("i", $tenantId);
        $stmt->execute();

        $passwordData = $stmt->get_result()->fetch_assoc();

        if (
            !$passwordData ||
            !password_verify($currentPassword, $passwordData['password_hash'])
        ) {

            $error = "Your current password is incorrect.";

        } else {

            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

            $stmt = $conn->prepare("
                UPDATE users
                SET password_hash = ?, updated_at = NOW()
                WHERE user_id = ?
            ");

            $stmt->bind_param("si", $newHash, $tenantId);
            $stmt->execute();

            $_SESSION['profile_success'] = "Your password has been changed successfully.";

            header("Location: profile.php");
            exit();
        }
    }
}

// Load the tenant information
$stmt = $conn->prepare("
    SELECT
        u.user_id,
        u.first_name,
        u.last_name,
        u.email,
        u.phone,
        u.address,
        u.account_status,
        tp.date_of_birth,
        tp.emergency_contact_name,
        tp.emergency_contact_phone
    FROM users u
    LEFT JOIN tenant_profiles tp
        ON tp.tenant_id = u.user_id
    WHERE u.user_id = ?
    AND u.role = 'tenant'
");

$stmt->bind_param("i", $tenantId);
$stmt->execute();

$user = $stmt->get_result()->fetch_assoc();

if (!$user) {
    session_destroy();
    header("Location: ../index.php");
    exit();
}

$fullName = trim($user['first_name'] . " " . $user['last_name']);
$initial = strtoupper(substr($user['first_name'], 0, 1));
?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>My Profile | RentEase</title>

<style>

/* Main page */

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    background: #f5f7f6;
    color: #243331;
    font-family: "Segoe UI", Arial, sans-serif;
    font-size: 16px;
}


/* Sidebar */

.sidebar {
    position: fixed;
    top: 0;
    left: 0;
    width: 255px;
    height: 100vh;
    padding: 28px 18px;
    background: #183b3a;
    overflow-y: auto;
}

.brand {
    padding: 0 12px 24px;
    border-bottom: 1px solid rgba(255,255,255,0.12);
}

.brand-name {
    color: white;
    font-size: 28px;
    font-weight: 800;
}

.brand-name span {
    color: #80c7ba;
}

.brand-tagline {
    margin-top: 4px;
    color: #b8d0cc;
    font-size: 13px;
}

.menu-title {
    margin: 23px 12px 8px;
    color: #8fb2ac;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 1.2px;
    text-transform: uppercase;
}

.sidebar a {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 4px;
    padding: 12px 13px;
    border-radius: 9px;
    color: #d8e5e2;
    text-decoration: none;
    font-size: 15px;
    font-weight: 500;
}

.sidebar a:hover {
    background: rgba(255,255,255,0.08);
    color: white;
}

.sidebar a.active {
    background: #2f8178;
    color: white;
    font-weight: 700;
}

.menu-icon {
    width: 21px;
    text-align: center;
}

.logout-area {
    margin-top: 25px;
    padding-top: 15px;
    border-top: 1px solid rgba(255,255,255,0.12);
}

.sidebar .logout {
    color: #f0c4c4;
}


/* Main content */

.main-content {
    margin-left: 255px;
    min-height: 100vh;
    padding: 35px 42px 25px;
}

.topbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
    margin-bottom: 25px;
}

.page-title h1 {
    margin: 0;
    font-size: 32px;
    letter-spacing: -0.5px;
}

.page-title p {
    margin: 7px 0 0;
    color: #687976;
    font-size: 16px;
}


/* Tenant shown in the top corner */

.user-box {
    display: flex;
    align-items: center;
    gap: 11px;
    padding: 9px 14px;
    background: white;
    border: 1px solid #d9e3e0;
    border-radius: 12px;
}

.small-avatar {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 42px;
    height: 42px;
    border-radius: 50%;
    background: #2f8178;
    color: white;
    font-size: 17px;
    font-weight: 800;
}

.user-box strong {
    display: block;
    font-size: 15px;
}

.user-box span {
    display: block;
    color: #71817e;
    font-size: 13px;
}


/* Teal profile banner */

.profile-banner {
    display: flex;
    align-items: center;
    gap: 20px;
    margin-bottom: 22px;
    padding: 26px 28px;
    background: #2f8178;
    color: white;
    border-radius: 16px;
    box-shadow: 0 8px 25px rgba(47,129,120,0.15);
}

.profile-avatar {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 76px;
    height: 76px;
    flex-shrink: 0;
    border-radius: 50%;
    background: white;
    color: #2f8178;
    font-size: 30px;
    font-weight: 800;
}

.profile-banner h2 {
    margin: 0;
    font-size: 25px;
}

.profile-banner p {
    margin: 4px 0 9px;
    color: #e3f1ee;
    font-size: 15px;
}

.active-badge {
    display: inline-block;
    padding: 5px 11px;
    background: rgba(255,255,255,0.18);
    border: 1px solid rgba(255,255,255,0.28);
    border-radius: 20px;
    color: white;
    font-size: 12px;
    font-weight: 700;
}


/* Messages */

.message {
    margin-bottom: 20px;
    padding: 14px 17px;
    border-radius: 10px;
    font-size: 15px;
    font-weight: 600;
}

.success {
    background: #e8f5ee;
    border: 1px solid #c9e5d5;
    color: #287a55;
}

.error {
    background: #faecec;
    border: 1px solid #efd0d0;
    color: #a84545;
}


/* Personal information and password cards */

.top-cards {
    display: grid;
    grid-template-columns: 1.15fr 0.85fr;
    gap: 22px;
    align-items: stretch;
}

.card {
    background: white;
    border: 1px solid #dce5e2;
    border-radius: 15px;
    padding: 27px;
    box-shadow: 0 7px 25px rgba(37,64,60,0.06);
}

.top-cards .card {
    height: 100%;
}

.card-heading {
    margin-bottom: 23px;
    padding-bottom: 17px;
    border-bottom: 1px solid #e4e9e7;
}

.card-heading h2 {
    margin: 0;
    color: #263936;
    font-size: 21px;
}

.card-heading p {
    margin: 5px 0 0;
    color: #71817e;
    font-size: 14px;
}


/* Form fields */

.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 18px;
}

.form-group {
    margin-bottom: 17px;
}

.form-group.full {
    grid-column: 1 / -1;
}

label {
    display: block;
    margin-bottom: 7px;
    color: #344744;
    font-size: 14px;
    font-weight: 700;
}

input {
    width: 100%;
    padding: 12px 13px;
    border: 1px solid #ccd8d5;
    border-radius: 9px;
    background: white;
    color: #243331;
    font-family: inherit;
    font-size: 15px;
    outline: none;
}

input:focus {
    border-color: #2f8178;
    box-shadow: 0 0 0 3px rgba(47,129,120,0.12);
}

input:disabled {
    background: #f1f4f3;
    color: #687673;
}

.helper {
    display: block;
    margin-top: 6px;
    color: #7c8987;
    font-size: 12px;
}


/* Buttons */

.form-actions {
    margin-top: 6px;
}

.btn {
    border: none;
    border-radius: 9px;
    padding: 12px 18px;
    font-family: inherit;
    font-size: 15px;
    font-weight: 700;
    cursor: pointer;
}

.btn-teal {
    background: #2f8178;
    color: white;
}

.btn-teal:hover {
    background: #286f68;
}


/* Password box */

.password-card {
    display: flex;
    flex-direction: column;
}

.password-card form {
    display: flex;
    flex-direction: column;
    flex: 1;
}

.password-card .form-group {
    margin-bottom: 20px;
}

.password-card input {
    padding: 14px 13px;
}

.password-tip {
    margin-top: 5px;
    padding: 18px;
    background: #eef6f4;
    border-radius: 9px;
}

.password-tip strong {
    display: block;
    margin-bottom: 5px;
    color: #286f68;
    font-size: 15px;
}

.password-tip p {
    margin: 0;
    color: #667875;
    font-size: 14px;
    line-height: 1.6;
}

.password-card .form-actions {
    margin-top: auto;
    padding-top: 22px;
}

.password-card .btn {
    width: 100%;
    padding: 14px 18px;
}


/* Emergency contact */

.emergency-card {
    margin-top: 22px;
}

.emergency-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
    margin-bottom: 22px;
    padding-bottom: 17px;
    border-bottom: 1px solid #e4e9e7;
}

.emergency-header h2 {
    margin: 0;
    color: #263936;
    font-size: 21px;
}

.emergency-header p {
    margin: 5px 0 0;
    color: #71817e;
    font-size: 14px;
}

.emergency-label {
    padding: 7px 12px;
    background: #e8f3f1;
    border-radius: 20px;
    color: #286f68;
    font-size: 12px;
    font-weight: 700;
}

.emergency-content {
    display: grid;
    grid-template-columns: 1fr 1fr auto;
    gap: 18px;
    align-items: end;
}

.emergency-content .form-group {
    margin-bottom: 0;
}


/* Teal footer */

.footer {
    margin-top: 32px;
    margin-left: -42px;
    margin-right: -42px;
    margin-bottom: -25px;
    padding: 20px 25px;
    background: #2f8178;
    color: white;
    text-align: center;
    font-size: 14px;
}

.footer strong {
    color: white;
    font-weight: 700;
}


/* Smaller screens */

@media (max-width: 1050px) {

    .top-cards {
        grid-template-columns: 1fr;
    }

    .emergency-content {
        grid-template-columns: 1fr 1fr;
    }

    .emergency-content .emergency-button {
        grid-column: 1 / -1;
    }
}

@media (max-width: 760px) {

    .sidebar {
        position: relative;
        width: 100%;
        height: auto;
    }

    .main-content {
        margin-left: 0;
        padding: 25px 18px;
    }

    .user-box {
        display: none;
    }

    .form-grid,
    .emergency-content {
        grid-template-columns: 1fr;
    }

    .profile-banner {
        align-items: flex-start;
    }

    .emergency-content .emergency-button {
        grid-column: auto;
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

    <a href="profile.php" class="active">
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
    </a>

    <a href="privacy.php">
<span class="menu-icon">◆</span>
Privacy
</a>

    <div class="logout-area">

        <a href="../auth/logout.php" class="logout">
            <span class="menu-icon">↪</span>
            Logout
        </a>

    </div>

</div>


<!-- Main page -->

<div class="main-content">

    <div class="topbar">

        <div class="page-title">

            <h1>My Profile</h1>

            <p>
                Manage your personal details and account security.
            </p>

        </div>

        <div class="user-box">

            <div class="small-avatar">
                <?php echo htmlspecialchars($initial); ?>
            </div>

            <div>

                <strong>
                    <?php echo htmlspecialchars($fullName); ?>
                </strong>

                <span>Tenant</span>

            </div>

        </div>

    </div>


    <!-- This message disappears after the page is refreshed -->

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


    <!-- Tenant summary -->

    <div class="profile-banner">

        <div class="profile-avatar">
            <?php echo htmlspecialchars($initial); ?>
        </div>

        <div>

            <h2>
                <?php echo htmlspecialchars($fullName); ?>
            </h2>

            <p>
                <?php echo htmlspecialchars($user['email']); ?>
            </p>

            <span class="active-badge">
                Active Tenant
            </span>

        </div>

    </div>


    <!-- Main two boxes -->

    <div class="top-cards">


        <!-- Personal information -->

        <div class="card">

            <div class="card-heading">

                <h2>Personal Information</h2>

                <p>
                    View and update your contact details.
                </p>

            </div>


            <form method="POST">

                <div class="form-grid">


                    <div class="form-group">

                        <label>First Name</label>

                        <input
                            type="text"
                            value="<?php echo htmlspecialchars($user['first_name']); ?>"
                            disabled
                        >

                    </div>


                    <div class="form-group">

                        <label>Last Name</label>

                        <input
                            type="text"
                            value="<?php echo htmlspecialchars($user['last_name']); ?>"
                            disabled
                        >

                    </div>


                    <div class="form-group">

                        <label>Email Address</label>

                        <input
                            type="email"
                            name="email"
                            value="<?php echo htmlspecialchars($user['email']); ?>"
                            required
                        >

                    </div>


                    <div class="form-group">

                        <label>Phone Number</label>

                        <input
                            type="text"
                            name="phone"
                            value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>"
                        >

                    </div>


                    <div class="form-group">

                        <label>Date of Birth</label>

                        <input
                            type="date"
                            name="date_of_birth"
                            value="<?php echo htmlspecialchars($user['date_of_birth'] ?? ''); ?>"
                        >

                    </div>


                    <div class="form-group">

                        <label>Account Status</label>

                        <input
                            type="text"
                            value="<?php echo ucfirst(htmlspecialchars($user['account_status'])); ?>"
                            disabled
                        >

                    </div>


                    <div class="form-group full">

                        <label>Property Address</label>

                        <input
                            type="text"
                            value="<?php echo htmlspecialchars($user['address'] ?? 'Not recorded'); ?>"
                            disabled
                        >

                        <span class="helper">
                            Your property address is linked to your tenancy.
                        </span>

                    </div>

                </div>


                <div class="form-actions">

                    <button
                        type="submit"
                        name="update_profile"
                        value="1"
                        class="btn btn-teal"
                    >
                        Save Changes
                    </button>

                </div>

            </form>

        </div>


        <!-- Password box -->

        <div class="card password-card">

            <div class="card-heading">

                <h2>Change Password</h2>

                <p>
                    Keep your RentEase account secure.
                </p>

            </div>


            <form method="POST">


                <div class="form-group">

                    <label>Current Password</label>

                    <input
                        type="password"
                        name="current_password"
                        placeholder="Enter current password"
                        required
                    >

                </div>


                <div class="form-group">

                    <label>New Password</label>

                    <input
                        type="password"
                        name="new_password"
                        placeholder="Enter new password"
                        minlength="8"
                        required
                    >

                </div>


                <div class="form-group">

                    <label>Confirm New Password</label>

                    <input
                        type="password"
                        name="confirm_password"
                        placeholder="Enter new password again"
                        minlength="8"
                        required
                    >

                </div>


                <div class="password-tip">

                    <strong>Password Tip</strong>

                    <p>
                        Use at least 8 characters and avoid using an easy-to-guess password.
                    </p>

                </div>


                <div class="form-actions">

                    <button
                        type="submit"
                        name="change_password"
                        value="1"
                        class="btn btn-teal"
                    >
                        Update Password
                    </button>

                </div>

            </form>

        </div>

    </div>


    <!-- Emergency contact -->

    <div class="card emergency-card">

        <div class="emergency-header">

            <div>

                <h2>Emergency Contact</h2>

                <p>
                    Keep an emergency contact available for your tenancy.
                </p>

            </div>

            <span class="emergency-label">
                Contact Details
            </span>

        </div>


        <form method="POST">

            <div class="emergency-content">


                <div class="form-group">

                    <label>Contact Name</label>

                    <input
                        type="text"
                        name="emergency_contact_name"
                        value="<?php echo htmlspecialchars($user['emergency_contact_name'] ?? ''); ?>"
                        placeholder="Enter contact name"
                    >

                </div>


                <div class="form-group">

                    <label>Contact Phone</label>

                    <input
                        type="text"
                        name="emergency_contact_phone"
                        value="<?php echo htmlspecialchars($user['emergency_contact_phone'] ?? ''); ?>"
                        placeholder="Enter contact phone"
                    >

                </div>


                <div class="emergency-button">

                    <button
                        type="submit"
                        name="update_emergency"
                        value="1"
                        class="btn btn-teal"
                    >
                        Save Contact
                    </button>

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