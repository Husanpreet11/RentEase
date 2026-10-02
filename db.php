```php
<?php
// RentEase Web System
// MySQL database connection

$host = "localhost";
$username = "root";
$password = "";
$database = "RentEase";

$conn = new mysqli($host, $username, $password, $database);

if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

// Use UTF-8 encoding
$conn->set_charset("utf8mb4");
?>
```
