<?php
require_once __DIR__.'/config/db.php';

// Passwords are converted to secure hashes before saving
$accounts=[
    "manager@rentease.com"=>"Manager123",
    "tenant1@rentease.com"=>"Husan123",
    "tenant2@rentease.com"=>"Qurat123",
    "tenant3@rentease.com"=>"Vijay123"
];

$stmt=$conn->prepare("UPDATE users SET password_hash=? WHERE email=?");

foreach($accounts as $email=>$plainPassword){
    $hashedPassword=password_hash($plainPassword,PASSWORD_DEFAULT);

    $stmt->bind_param("ss",$hashedPassword,$email);
    $stmt->execute();
}

$stmt->close();

echo "Passwords created successfully!";
?>