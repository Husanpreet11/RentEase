<?php

session_start();

require_once __DIR__ . '/../config/db.php';

// Get email and password from login form
$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';

// Check if fields are empty
if (empty($email) || empty($password)) {
    die("Please enter email and password.");
}

// Find user in database
$sql = "SELECT user_id, first_name, last_name, email,
               password_hash, role, account_status
        FROM users
        WHERE email = ?";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("Database error: " . $conn->error);
}

$stmt->bind_param("s", $email);
$stmt->execute();

$result = $stmt->get_result();

// Check if email exists
if ($result->num_rows !== 1) {
    die("Email not found.");
}

$user = $result->fetch_assoc();

// Check password
if (!password_verify($password, $user['password_hash'])) {
    die("Wrong password.");
}

// Check account status
if ($user['account_status'] !== 'active') {
    die("Your account is inactive.");
}

// LOGIN SUCCESSFUL
// Save user information in session
$_SESSION['user_id'] = $user['user_id'];
$_SESSION['role'] = $user['role'];
$_SESSION['name'] = $user['first_name'] . " " . $user['last_name'];
$_SESSION['email'] = $user['email'];

// Redirect according to role
if ($user['role'] === 'manager') {

    header("Location: ../manager/dashboard.php");
    exit;

} elseif ($user['role'] === 'tenant') {

    header("Location: ../tenant/dashboard.php");
    exit;

} else {

    die("Invalid user role.");
}

?>