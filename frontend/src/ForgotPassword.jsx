import { useState } from "react";
import "./ForgotPassword.css";

function ForgotPassword() {
    const [email, setEmail] = useState("");
    const [message, setMessage] = useState("");

    const handleSubmit = (event) => {
        event.preventDefault();
        setMessage("Check your email for password reset instructions.");
    };

    return (
        <div className="forgot-page">
            <div className="forgot-container">

                <div className="welcome-section">
                    <h1>RentEase</h1>
                    <h2>Forgot your password?</h2>
                    <div className="line"></div>

                    <p>
                        Don't worry. Enter your registered email
                        and we will help you get back into your
                        RentEase account.
                    </p>
                </div>

                <div className="reset-section">
                    <h2>Reset Password</h2>

                    <p className="subtitle">
                        Enter your email address and we will send
                        instructions to reset your password.
                    </p>

                    <form onSubmit={handleSubmit}>
                        <label htmlFor="email">Email Address</label>

                        <input
                            type="email"
                            id="email"
                            placeholder="Enter your email"
                            value={email}
                            onChange={(event) =>
                                setEmail(event.target.value)
                            }
                            required
                        />

                        <button type="submit">
                            Send Instructions
                        </button>
                    </form>

                    {message && (
                        <div className="success-message">
                            {message}
                        </div>
                    )}

                    <div className="back-login">
                        <a href="http://localhost/RentEase-Web-System/auth/login.php">
                            ← Back to Login
                        </a>
                    </div>
                </div>

            </div>
        </div>
    );
}

export default ForgotPassword;
