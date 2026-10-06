<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit();
}

if (($_SESSION['role'] ?? '') !== 'tenant') {
    header("Location: ../manager/dashboard.php");
    exit();
}

$type = $_GET['type'] ?? 'payment';
$reference = trim($_GET['ref'] ?? '');
$amount = isset($_GET['amount']) && is_numeric($_GET['amount'])
    ? (float)$_GET['amount']
    : null;

if ($type === 'rent') {
    $title = 'Rent Payment Successful';
    $paymentType = 'Rent Payment';
} elseif ($type === 'utility') {
    $title = 'Utility Payment Successful';
    $paymentType = 'Utility Payment';
} else {
    $title = 'Payment Successful';
    $paymentType = 'Payment';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Payment Successful | RentEase</title>
<style>
*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:30px;background:#f5f7f6;color:#243331;font-family:"Segoe UI",Arial,sans-serif}
.page{width:100%;max-width:570px}
.logo{text-align:center;margin-bottom:22px;color:#243331;font-size:29px;font-weight:800}
.logo span{color:#2f8178}
.tagline{text-align:center;margin-top:-18px;margin-bottom:25px;color:#71817e;font-size:13px}
.success-card{background:#fff;border:1px solid #dce5e2;border-radius:17px;overflow:hidden;box-shadow:0 12px 35px rgba(37,64,60,.08)}
.success-top{padding:34px 35px 28px;background:#eef6f4;text-align:center;border-bottom:1px solid #dce5e2}
.check{display:flex;align-items:center;justify-content:center;width:72px;height:72px;margin:0 auto 18px;background:#2f8178;border-radius:50%;color:#fff;font-size:34px;font-weight:600}
h1{margin:0;color:#2d413e;font-size:24px;font-weight:600}
.success-top p{max-width:410px;margin:10px auto 0;color:#687976;font-size:14px;line-height:1.6}
.details{padding:23px 32px}
.status{display:flex;align-items:center;justify-content:center;margin-bottom:19px;padding:11px;background:#e8f5ee;border-radius:9px;color:#287a55;font-size:13px;font-weight:600}
.detail-row{display:flex;justify-content:space-between;gap:20px;padding:13px 0;border-bottom:1px solid #edf1ef}
.detail-row:last-child{border-bottom:none}
.detail-label{color:#71817e;font-size:13px}
.detail-value{color:#40514e;font-size:13px;font-weight:600;text-align:right}
.actions{padding:0 32px 30px;text-align:center}
.btn{display:inline-block;width:100%;padding:12px 20px;background:#2f8178;border-radius:9px;color:#fff;font-size:14px;font-weight:600;text-decoration:none}
.btn:hover{background:#286f68}
.message{margin-top:15px;color:#83908e;font-size:12px;line-height:1.6}
.footer{margin-top:22px;color:#71817e;text-align:center;font-size:12px}
.footer strong{color:#40514e;font-weight:600}
@media(max-width:600px){body{padding:18px}.success-top{padding:30px 20px}.details{padding:20px}.actions{padding:0 20px 25px}}
</style>
</head>
<body>

<div class="page">

<div class="logo">Rent<span>Ease</span></div>
<div class="tagline">Renting Made Easy.</div>

<div class="success-card">

<div class="success-top">

<div class="check">✓</div>

<h1><?php echo htmlspecialchars($title); ?></h1>

<p>
Your payment has been completed successfully and has been recorded in RentEase.
</p>

</div>

<div class="details">

<div class="status">
Payment Status: Paid
</div>

<div class="detail-row">
<span class="detail-label">Payment Type</span>
<span class="detail-value"><?php echo htmlspecialchars($paymentType); ?></span>
</div>

<?php if ($amount !== null): ?>
<div class="detail-row">
<span class="detail-label">Amount Paid</span>
<span class="detail-value">$<?php echo number_format($amount,2); ?></span>
</div>
<?php endif; ?>

<?php if ($reference !== ''): ?>
<div class="detail-row">
<span class="detail-label">Reference Number</span>
<span class="detail-value"><?php echo htmlspecialchars($reference); ?></span>
</div>
<?php endif; ?>

<div class="detail-row">
<span class="detail-label">Payment Date</span>
<span class="detail-value"><?php echo date("d M Y"); ?></span>
</div>

</div>

<div class="actions">

<a href="payments.php" class="btn">
Back to Rent & Utilities
</a>

<div class="message">
Your Rent & Utilities page will now show this charge as paid.
</div>

</div>

</div>

<div class="footer">
<strong>© 2026 RentEase Property Management System</strong>
&nbsp; • &nbsp;
Renting Made Easy.
</div>

</div>

</body>
</html>