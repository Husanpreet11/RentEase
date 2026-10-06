<?php
session_start();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>RentEase Login</title>

    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f4f4f4;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }

        .login-box {
            background: white;
            width: 350px;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }

        h1 {
            text-align: center;
            margin-bottom: 5px;
        }

        .subtitle {
            text-align: center;
            color: #666;
            margin-bottom: 25px;
        }

        label {
            display: block;
            margin-top: 15px;
            margin-bottom: 5px;
        }

        input {
            width: 100%;
            padding: 10px;
            box-sizing: border-box;
        }

        button {
            width: 100%;
            padding: 11px;
            margin-top: 20px;
            background: #222;
            color: white;
            border: none;
            cursor: pointer;
        }

        button:hover {
            background: #444;
        }
    </style>
<link rel="stylesheet" href="assets/css/rentease.css">
</head>

<body>

<div class="login-box">

    <h1>RentEase</h1>

    <p class="subtitle">
        Property Management System
    </p>

    <form action="auth/login.php" method="POST">

        <label>Email</label>

        <input
            type="email"
            name="email"
            required
        >

        <label>Password</label>

        <input
            type="password"
            name="password"
            required
        >
<a 
    href="http://localhost:5173/"
    style="
        display: block;
        text-align: right;
        margin-top: 8px;
        font-size: 14px;
        color: #555;
        text-decoration: none;
    "
>
    Forgot Password?
</a>
        <button type="submit">
            Login
        </button>

    </form>

</div>

</body>
</html>