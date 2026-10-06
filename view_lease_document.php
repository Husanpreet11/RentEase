<?php
session_start();
require_once __DIR__ . '/config/db.php';

// User must be logged in
if(!isset($_SESSION['user_id'])||!isset($_SESSION['role'])){
http_response_code(403);
exit('Access denied.');
}

$userId=(int)$_SESSION['user_id'];
$role=$_SESSION['role'];
$leaseId=(int)($_GET['lease_id']??0);

// Tenant can only view their own lease
if($role==='tenant'){
$stmt=$conn->prepare("
SELECT lease_id,lease_document
FROM leases
WHERE tenant_id=?
AND lease_document IS NOT NULL
AND lease_document<>''
AND lease_status IN ('active','renewal_due')
ORDER BY
CASE
WHEN lease_status='active' THEN 1
WHEN lease_status='renewal_due' THEN 2
ELSE 3
END,
end_date DESC
LIMIT 1
");
$stmt->bind_param("i",$userId);
}

// Manager can view a selected lease
elseif($role==='manager'){
if($leaseId<=0){
http_response_code(400);
exit('Lease ID is required.');
}

$stmt=$conn->prepare("
SELECT lease_id,lease_document
FROM leases
WHERE lease_id=?
AND lease_document IS NOT NULL
AND lease_document<>''
LIMIT 1
");
$stmt->bind_param("i",$leaseId);
}

else{
http_response_code(403);
exit('Access denied.');
}

$stmt->execute();
$lease=$stmt->get_result()->fetch_assoc();

if(!$lease){
http_response_code(404);
exit('Lease agreement is not available.');
}

// Get only the filename from database path
$fileName=basename(str_replace('\\','/',$lease['lease_document']));

// Lease PDF folder
$leaseFolder=__DIR__ . '/uploads/leases/';

$filePath=$leaseFolder.$fileName;

// Check physical file
if(!is_file($filePath)){
http_response_code(404);
exit('Lease agreement file could not be found: '.$fileName);
}

// PDF files only
if(strtolower(pathinfo($filePath,PATHINFO_EXTENSION))!=='pdf'){
http_response_code(403);
exit('Invalid lease document.');
}

// Open PDF in browser
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="'.basename($filePath).'"');
header('Content-Length: '.filesize($filePath));
header('X-Content-Type-Options: nosniff');

readfile($filePath);
exit();
?>