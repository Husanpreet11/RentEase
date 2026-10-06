import { useState } from "react";
import "./ForgotPassword.css";

function ForgotPassword() {
  const [email, setEmail] = useState("");
  const [sent, setSent] = useState(false);

  const handleSubmit = (e) => {
    e.preventDefault();

    if (!email.trim()) {
      return;
    }

    // Demo password reset for the university project
    setSent(true);
  };

  return (
    <div className="forgot-page">
      <div className="forgot-card">

        <div className="brand-area">
          <div className="logo">R</div>

          <div className="brand-name">
            Rent<span>Ease</span>
          </div>

          <div className="brand-tagline">
            Property Management System
          </div>
        </div>

        {!sent ? (
          <>
            <div className="page-heading">
              <h1>Forgot Password?</h1>
              <p>
                Enter the email associated with your RentEase account
                and we'll send you password reset instructions.
              </p>
            </div>

            <form onSubmit={handleSubmit}>
              <label htmlFor="email">Email Address</label>

              <input
                id="email"
                type="email"
                placeholder="Enter your email address"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                required
              />

              <button type="submit" className="primary-button">
                Send Reset Instructions
              </button>
            </form>

            <a
              className="back-link"
              href="http://localhost/RentEase/"
            >
              ← Back to Login
            </a>
          </>
        ) : (
          <>
            <div className="success-icon">✓</div>

            <div className="page-heading success-heading">
              <h1>Check Your Email</h1>

              <p>
                Password reset instructions have been sent to:
              </p>
            </div>

            <div className="email-box">
              {email}
            </div>

            <p className="small-text">
              Please check your inbox and follow the instructions
              to reset your password.
            </p>

            <div className="demo-note">
              This is a demonstration password reset feature for RentEase.
            </div>

            <button
              type="button"
              className="sent-button"
              disabled
            >
              ✓ Instructions Sent
            </button>

            <button
              type="button"
              className="try-again"
              onClick={() => {
                setSent(false);
                setEmail("");
              }}
            >
              Use Another Email
            </button>

            <a
              className="back-link"
              href="http://localhost/RentEase/"
            >
              ← Back to Login
            </a>
          </>
        )}

        <div className="card-footer">
          © 2026 RentEase • Renting Made Easy.
        </div>

      </div>
    </div>
  );
}

export default ForgotPassword;