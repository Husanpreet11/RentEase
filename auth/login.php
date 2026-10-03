
<!DOCTYPE html>
<html lang="en">

<head>

    <!-- Page title -->
    <title>RentEase | Login</title>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <style>

        /* Main page styling */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: Arial, sans-serif;
        }

        body {
            min-height: 100vh;
            background: linear-gradient(135deg, #f5efff, #e8ddff);
            display: flex;
            justify-content: center;
            align-items: center;
        }

        /* Login card */
        .login-container {
            width: 850px;
            max-width: 90%;
            min-height: 500px;
            background: white;
            border-radius: 18px;
            overflow: hidden;
            display: flex;
            box-shadow: 0 12px 35px rgba(60, 40, 90, 0.15);
        }

        /* Welcome section */
        .welcome-section {
            width: 45%;
            background: linear-gradient(135deg, #6c4ab6, #9272d3);
            color: white;
            padding: 55px 40px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .welcome-section h1 {
            font-size: 42px;
            margin-bottom: 12px;
        }

        .welcome-section h2 {
            font-size: 23px;
            font-weight: normal;
        }

        .welcome-section p {
            font-size: 15px;
            line-height: 1.7;
            margin-top: 18px;
        }

        .line {
            width: 55px;
            height: 4px;
            background: white;
            margin-top: 18px;
            border-radius: 5px;
        }

        /* Login form section */
        .login-section {
            width: 55%;
            padding: 55px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .login-section h2 {
            color: #2d2340;
            font-size: 30px;
            margin-bottom: 8px;
        }

        .subtitle {
            color: #777;
            font-size: 14px;
            margin-bottom: 28px;
        }

        label {
            display: block;
            color: #40384d;
            font-size: 14px;
            font-weight: bold;
            margin-bottom: 7px;
        }

        input {
            width: 100%;
            padding: 13px;
            margin-bottom: 20px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            outline: none;
        }

        input:focus {
            border-color: #7b5cc7;
            box-shadow: 0 0 0 3px rgba(123, 92, 199, 0.1);
        }

        button {
            width: 100%;
            padding: 14px;
            background: #6c4ab6;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
        }

        button:hover {
            background: #57399b;
        }

        .footer-text {
            text-align: center;
            color: #888;
            font-size: 13px;
            margin-top: 22px;
        }

        .forgot-password {
    text-align: right;
    margin-top: -10px;
    margin-bottom: 20px;
}

.forgot-password a {
    color: #6c4ab6;
    text-decoration: none;
    font-size: 13px;
}

.forgot-password a:hover {
    text-decoration: underline;
}

        /* Mobile layout */
        @media (max-width: 700px) {

            .login-container {
                flex-direction: column;
            }

            .welcome-section,
            .login-section {
                width: 100%;
            }

            .welcome-section {
                padding: 35px 30px;
            }

            .login-section {
                padding: 35px 30px;
            }
        }

    </style>

</head>

<body>

    <div class="login-container">

        <!-- Welcome message -->
        <div class="welcome-section">

            <h1>RentEase</h1>

            <h2>Welcome Back!</h2>

            <div class="line"></div>

            <p>
                Manage your rental property with ease.
                RentEase keeps property, lease and
                maintenance information organised in one place.
            </p>

        </div>

        <!-- Login form -->
        <div class="login-section">

            <h2>Sign In</h2>

            <p class="subtitle">
                Enter your details to access your account.
            </p>

            <form action="login_process.php" method="POST">

                <label for="email">Email Address</label>

                <input
                    type="email"
                    id="email"
                    name="email"
                    placeholder="Enter your email"
                    required
                >

                <label for="password">Password</label>

                <input
                    type="password"
                    id="password"
                    name="password"
                    placeholder="Enter your password"
                    required
                >

                <button type="submit">Login</button>

                <div class="forgot-password">
    <a href="forgot_password.php">Forgot your password?</a>
</div>

            </form>

            <p class="footer-text">
                RentEase Property Management System
            </p>

        </div>

    </div>

</body>

</html>
