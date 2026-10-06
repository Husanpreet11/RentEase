<?php
session_start();

// User must be logged in
if(!isset($_SESSION['user_id'])){
header("Location: ../../index.php");
exit();
}

// Only managers can access the manager section
if(($_SESSION['role']??'')!=='manager'){
header("Location: ../../tenant/dashboard.php");
exit();
}

// Rent payments are made by tenants.
// Managers only monitor payment history and balances.
$_SESSION['payment_flash']="Rent payments are submitted by tenants through their Rent & Utilities page.";

header("Location: payments.php");
exit();
?>