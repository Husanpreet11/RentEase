<?php
session_start();
require_once __DIR__.'/../config/db.php';

// Tenant only
if(!isset($_SESSION['user_id'])){
header("Location: ../index.php");
exit();
}

if(($_SESSION['role']??'')!=='tenant'){
header("Location: ../manager/dashboard.php");
exit();
}

$tenantId=(int)$_SESSION['user_id'];
$type=$_GET['type']??$_POST['type']??'';
$id=(int)($_GET['id']??$_POST['id']??0);
$error='';

// Only rent and utility payments are accepted
if(!in_array($type,['rent','utility'],true)||$id<=0){
header("Location: payments.php");
exit();
}

// Get tenant
$stmt=$conn->prepare("
SELECT first_name,last_name,email
FROM users
WHERE user_id=? AND role='tenant'
LIMIT 1
");
$stmt->bind_param("i",$tenantId);
$stmt->execute();
$tenant=$stmt->get_result()->fetch_assoc();

if(!$tenant){
session_destroy();
header("Location: ../index.php");
exit();
}

$tenantName=trim($tenant['first_name'].' '.$tenant['last_name']);
$tenantLetter=strtoupper(substr($tenant['first_name'],0,1));
$payment=null;

// Get rent charge
if($type==='rent'){
$stmt=$conn->prepare("
SELECT
rc.rent_charge_id AS item_id,
rc.rent_period,
rc.due_date,
rc.amount_due,
rc.status,
p.property_code,
p.address_line1,
p.suburb,
p.state,
p.postcode
FROM rent_charges rc
INNER JOIN leases l ON l.lease_id=rc.lease_id
INNER JOIN properties p ON p.property_id=l.property_id
WHERE rc.rent_charge_id=?
AND l.tenant_id=?
LIMIT 1
");
$stmt->bind_param("ii",$id,$tenantId);
$stmt->execute();
$payment=$stmt->get_result()->fetch_assoc();
}

// Get utility bill
if($type==='utility'){
$stmt=$conn->prepare("
SELECT
ub.utility_bill_id AS item_id,
ub.utility_type,
ub.provider_name,
ub.due_date,
ub.amount_due,
ub.status,
p.property_code,
p.address_line1,
p.suburb,
p.state,
p.postcode
FROM utility_bills ub
INNER JOIN leases l ON l.lease_id=ub.lease_id
INNER JOIN properties p ON p.property_id=l.property_id
WHERE ub.utility_bill_id=?
AND l.tenant_id=?
LIMIT 1
");
$stmt->bind_param("ii",$id,$tenantId);
$stmt->execute();
$payment=$stmt->get_result()->fetch_assoc();
}

// Invalid payment item
if(!$payment){
header("Location: payments.php");
exit();
}

// Calculate how much has already been paid
$alreadyPaid=0;

if($type==='rent'){
$stmt=$conn->prepare("
SELECT COALESCE(SUM(amount_paid),0) AS total_paid
FROM rent_payments
WHERE rent_charge_id=?
AND tenant_id=?
AND payment_status='paid'
");
$stmt->bind_param("ii",$id,$tenantId);
$stmt->execute();
$alreadyPaid=(float)$stmt->get_result()->fetch_assoc()['total_paid'];
}else{
$stmt=$conn->prepare("
SELECT COALESCE(SUM(amount_paid),0) AS total_paid
FROM utility_payments
WHERE utility_bill_id=?
AND tenant_id=?
AND payment_status='paid'
");
$stmt->bind_param("ii",$id,$tenantId);
$stmt->execute();
$alreadyPaid=(float)$stmt->get_result()->fetch_assoc()['total_paid'];
}

$amountDue=(float)$payment['amount_due'];
$amount=max(0,$amountDue-$alreadyPaid);

// Nothing left to pay
if($amount<=0){
header("Location: payments.php");
exit();
}

// Payment title
if($type==='rent'){
$paymentTitle=date("F Y",strtotime($payment['rent_period'].'-01')).' Rent';
}else{
$paymentTitle=ucwords(str_replace('_',' ',$payment['utility_type'])).' Bill';
}

// Process payment
if($_SERVER['REQUEST_METHOD']==='POST'){
$cardName=trim($_POST['card_name']??'');
$cardNumber=preg_replace('/\D/','',$_POST['card_number']??'');
$expiry=trim($_POST['expiry']??'');
$cvv=preg_replace('/\D/','',$_POST['cvv']??'');

if($cardName===''){
$error='Please enter the name on the card.';
}elseif(strlen($cardNumber)<13||strlen($cardNumber)>19){
$error='Please enter a valid card number.';
}elseif(!preg_match('/^(0[1-9]|1[0-2])\/\d{2}$/',$expiry)){
$error='Please enter expiry in MM/YY format.';
}elseif(strlen($cvv)<3||strlen($cvv)>4){
$error='Please enter a valid CVV.';
}else{

$reference=strtoupper($type==='rent'?'RENT':'UTIL').'-'.date('YmdHis').'-'.$tenantId;

$conn->begin_transaction();

try{

if($type==='rent'){

// Recalculate balance before inserting payment
$stmt=$conn->prepare("
SELECT COALESCE(SUM(amount_paid),0) AS total_paid
FROM rent_payments
WHERE rent_charge_id=?
AND tenant_id=?
AND payment_status='paid'
");
$stmt->bind_param("ii",$id,$tenantId);
$stmt->execute();
$currentPaid=(float)$stmt->get_result()->fetch_assoc()['total_paid'];

$currentBalance=max(0,$amountDue-$currentPaid);

if($currentBalance<=0){
throw new Exception('This rent charge has already been paid.');
}

$amount=$currentBalance;

// Save payment
$stmt=$conn->prepare("
INSERT INTO rent_payments
(rent_charge_id,tenant_id,amount_paid,payment_method,reference_number,payment_status)
VALUES(?,?,?,'card',?,'paid')
");
$stmt->bind_param("iids",$id,$tenantId,$amount,$reference);
$stmt->execute();

$paymentId=$conn->insert_id;

// Calculate balance after payment
$stmt=$conn->prepare("
SELECT COALESCE(SUM(amount_paid),0) AS total_paid
FROM rent_payments
WHERE rent_charge_id=?
AND tenant_id=?
AND payment_status='paid'
");
$stmt->bind_param("ii",$id,$tenantId);
$stmt->execute();
$newPaid=(float)$stmt->get_result()->fetch_assoc()['total_paid'];

$newBalance=max(0,$amountDue-$newPaid);

if($newBalance<=0){
$newStatus='paid';
}elseif($newPaid>0){
$newStatus='part_paid';
}elseif($payment['due_date']<date('Y-m-d')){
$newStatus='overdue';
}else{
$newStatus='due';
}

$stmt=$conn->prepare("
UPDATE rent_charges
SET status=?
WHERE rent_charge_id=?
");
$stmt->bind_param("si",$newStatus,$id);
$stmt->execute();

$description="Tenant completed rent payment ".$reference.".";

$stmt=$conn->prepare("
INSERT INTO activity_log
(user_id,action_type,entity_type,entity_id,description)
VALUES(?,'PAY_RENT','rent_payment',?,?)
");
$stmt->bind_param("iis",$tenantId,$paymentId,$description);
$stmt->execute();
}

if($type==='utility'){

// Recalculate utility balance
$stmt=$conn->prepare("
SELECT COALESCE(SUM(amount_paid),0) AS total_paid
FROM utility_payments
WHERE utility_bill_id=?
AND tenant_id=?
AND payment_status='paid'
");
$stmt->bind_param("ii",$id,$tenantId);
$stmt->execute();
$currentPaid=(float)$stmt->get_result()->fetch_assoc()['total_paid'];

$currentBalance=max(0,$amountDue-$currentPaid);

if($currentBalance<=0){
throw new Exception('This utility bill has already been paid.');
}

$amount=$currentBalance;

// Save utility payment
$stmt=$conn->prepare("
INSERT INTO utility_payments
(utility_bill_id,tenant_id,amount_paid,payment_method,reference_number,payment_status)
VALUES(?,?,?,'card',?,'paid')
");
$stmt->bind_param("iids",$id,$tenantId,$amount,$reference);
$stmt->execute();

$paymentId=$conn->insert_id;

// Calculate balance after payment
$stmt=$conn->prepare("
SELECT COALESCE(SUM(amount_paid),0) AS total_paid
FROM utility_payments
WHERE utility_bill_id=?
AND tenant_id=?
AND payment_status='paid'
");
$stmt->bind_param("ii",$id,$tenantId);
$stmt->execute();
$newPaid=(float)$stmt->get_result()->fetch_assoc()['total_paid'];

$newBalance=max(0,$amountDue-$newPaid);

if($newBalance<=0){
$newStatus='paid';
}elseif($newPaid>0){
$newStatus='part_paid';
}elseif($payment['due_date']<date('Y-m-d')){
$newStatus='overdue';
}else{
$newStatus='unpaid';
}

$stmt=$conn->prepare("
UPDATE utility_bills
SET status=?
WHERE utility_bill_id=?
");
$stmt->bind_param("si",$newStatus,$id);
$stmt->execute();

$description="Tenant completed utility payment ".$reference.".";

$stmt=$conn->prepare("
INSERT INTO activity_log
(user_id,action_type,entity_type,entity_id,description)
VALUES(?,'PAY_UTILITY','utility_payment',?,?)
");
$stmt->bind_param("iis",$tenantId,$paymentId,$description);
$stmt->execute();
}

$conn->commit();

header(
"Location: payment_success.php?type=".
urlencode($type).
"&ref=".urlencode($reference).
"&amount=".urlencode(number_format($amount,2,'.',''))
);
exit();

}catch(Throwable $e){
$conn->rollback();
$error=$e->getMessage();
}
}
}

function showDate($date){
if(!$date)return "—";
return date("d M Y",strtotime($date));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Make Payment | RentEase</title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#f3f6f5;color:#344441;font-family:"Segoe UI",Arial,sans-serif;font-size:16px}
.sidebar{position:fixed;top:0;left:0;width:255px;height:100vh;padding:28px 18px;background:#183b3a;overflow-y:auto;z-index:10}
.brand{padding:0 12px 24px;border-bottom:1px solid rgba(255,255,255,.12)}
.brand-name{color:#fff;font-size:28px;font-weight:800;letter-spacing:-.5px}
.brand-name span{color:#80c7ba}
.brand-tagline{margin-top:4px;color:#b8d0cc;font-size:13px}
.menu-title{margin:23px 12px 8px;color:#8fb2ac;font-size:11px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase}
.sidebar a{display:flex;align-items:center;gap:12px;margin-bottom:4px;padding:12px 13px;border-radius:9px;color:#d8e5e2;text-decoration:none;font-size:15px;font-weight:500;transition:.2s}
.sidebar a:hover{background:rgba(255,255,255,.08);color:#fff}
.sidebar a.active{background:#2f8178;color:#fff;font-weight:700}
.menu-icon{width:21px;text-align:center}
.logout-area{margin-top:25px;padding-top:15px;border-top:1px solid rgba(255,255,255,.12)}
.sidebar .logout{color:#f0c4c4}
.main-content{margin-left:255px;min-height:100vh;padding:35px 42px 25px}
.topbar{display:flex;justify-content:space-between;align-items:center;gap:20px;margin-bottom:27px}
.page-title h1{margin:0;color:#2e403d;font-size:32px;font-weight:700;letter-spacing:-.6px}
.page-title p{margin:7px 0 0;color:#71817e;font-size:15px}
.user-box{display:flex;align-items:center;gap:11px;padding:9px 15px;background:#fff;border:1px solid #dce5e2;border-radius:13px;color:#344441;text-decoration:none;box-shadow:0 3px 12px rgba(37,64,60,.04);transition:.2s}
.user-box:hover{border-color:#b9d5cf;box-shadow:0 5px 16px rgba(37,64,60,.07)}
.small-avatar{display:flex;align-items:center;justify-content:center;width:42px;height:42px;border-radius:50%;background:#2f8178;color:#fff;font-size:17px;font-weight:700}
.user-box strong{display:block;color:#344441;font-size:15px;font-weight:600}
.user-box span{display:block;margin-top:2px;color:#82918e;font-size:12px}
.payment-layout{display:grid;grid-template-columns:minmax(330px,.82fr) minmax(430px,1.18fr);gap:24px;align-items:start;max-width:1200px}
.card{background:#fff;border:1px solid #dce5e2;border-radius:17px;overflow:hidden;box-shadow:0 7px 25px rgba(37,64,60,.055)}
.card-header{padding:22px 26px 18px;border-bottom:1px solid #e7edeb}
.card-header h2{margin:0;color:#344441;font-size:20px;font-weight:650}
.card-header p{margin:6px 0 0;color:#82918e;font-size:13px;line-height:1.5}
.card-body{padding:25px 26px}
.summary{position:relative;padding:25px;background:linear-gradient(135deg,#2f8178 0%,#347d75 100%);border-radius:14px;color:#fff;overflow:hidden}
.summary:after{content:"$";position:absolute;right:-4px;bottom:-38px;color:rgba(255,255,255,.07);font-size:145px;font-weight:700;line-height:1}
.summary-label{position:relative;z-index:1;color:#d9efeb;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px}
.summary h3{position:relative;z-index:1;margin:9px 0 7px;font-size:22px;font-weight:600}
.summary p{position:relative;z-index:1;margin:0;max-width:330px;color:#e6f3f0;font-size:13px;line-height:1.55}
.amount-box{margin:18px 0 5px;padding:18px 19px;background:#eef6f4;border:1px solid #d7e9e5;border-radius:12px}
.amount-label{color:#71817e;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.7px}
.amount{margin-top:6px;color:#40514e;font-size:27px;font-weight:600;letter-spacing:-.4px}
.info-row{display:flex;justify-content:space-between;align-items:center;gap:20px;padding:14px 2px;border-bottom:1px solid #edf1ef}
.info-row:last-of-type{border-bottom:none}
.info-label{color:#71817e;font-size:13px}
.info-value{color:#40514e;font-size:13px;font-weight:600;text-align:right}
.notice{position:relative;margin-top:17px;padding:14px 15px 14px 42px;background:#f6f8f7;border:1px solid #e5ebe9;border-radius:10px;color:#71817e;font-size:12px;line-height:1.6}
.notice:before{content:"i";position:absolute;left:15px;top:15px;display:flex;align-items:center;justify-content:center;width:18px;height:18px;background:#dcebe8;border-radius:50%;color:#2f8178;font-size:11px;font-weight:700}
.error{margin-bottom:19px;padding:13px 15px;background:#fae8e8;border:1px solid #f1cccc;border-radius:10px;color:#a84545;font-size:13px}
.card-preview{position:relative;margin-bottom:25px;padding:23px 25px;background:linear-gradient(135deg,#173f3d,#245c57);border-radius:15px;color:#fff;min-height:184px;box-shadow:0 10px 25px rgba(24,59,58,.16);overflow:hidden}
.card-preview:after{content:"";position:absolute;width:190px;height:190px;right:-70px;top:-85px;border:1px solid rgba(255,255,255,.10);border-radius:50%}
.card-preview:before{content:"";position:absolute;width:150px;height:150px;right:-55px;top:-55px;border:1px solid rgba(255,255,255,.08);border-radius:50%}
.preview-brand{position:relative;z-index:1;font-size:18px;font-weight:700;letter-spacing:.2px}
.preview-chip{position:relative;z-index:1;width:40px;height:29px;margin:24px 0 17px;background:linear-gradient(135deg,#b7dcd5,#73b9ad);border-radius:6px}
.preview-chip:after{content:"";position:absolute;left:19px;top:0;width:1px;height:29px;background:rgba(24,59,58,.25)}
.preview-number{position:relative;z-index:1;font-size:18px;font-weight:500;letter-spacing:2.5px}
.preview-bottom{position:relative;z-index:1;display:flex;justify-content:space-between;gap:20px;margin-top:20px;color:#a9cbc5;font-size:9px;font-weight:600;letter-spacing:.8px;text-transform:uppercase}
.preview-bottom strong{display:block;margin-top:4px;color:#fff;font-size:11px;font-weight:500;letter-spacing:.4px}
.form-group{margin-bottom:18px}
.form-group label{display:block;margin-bottom:7px;color:#52615f;font-size:12px;font-weight:650}
.form-control{width:100%;height:46px;padding:0 14px;border:1px solid #cedbd7;border-radius:9px;background:#fbfcfc;color:#344441;font-family:inherit;font-size:14px;outline:none;transition:.2s}
.form-control::placeholder{color:#a1aeab}
.form-control:hover{border-color:#b7cbc6;background:#fff}
.form-control:focus{background:#fff;border-color:#2f8178;box-shadow:0 0 0 3px rgba(47,129,120,.10)}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.actions{display:flex;gap:11px;margin-top:8px;padding-top:4px}
.btn{display:inline-flex;align-items:center;justify-content:center;min-height:45px;padding:11px 19px;border:none;border-radius:9px;font-family:inherit;font-size:14px;font-weight:600;text-decoration:none;cursor:pointer;transition:.2s}
.btn-primary{background:#2f8178;color:#fff;flex:1;box-shadow:0 4px 10px rgba(47,129,120,.16)}
.btn-primary:hover{background:#286f68;box-shadow:0 6px 14px rgba(47,129,120,.20);transform:translateY(-1px)}
.btn-secondary{background:#f0f4f3;border:1px solid #dce5e2;color:#52615f}
.btn-secondary:hover{background:#e7eeec;color:#344441}
.footer{margin-top:36px;margin-left:-42px;margin-right:-42px;margin-bottom:-25px;padding:20px 25px;background:#2f8178;color:#fff;text-align:center;font-size:13px}
.footer strong{color:#fff;font-weight:700}
@media(max-width:1050px){.payment-layout{grid-template-columns:1fr;max-width:800px}}
@media(max-width:760px){.sidebar{position:relative;width:100%;height:auto}.main-content{margin-left:0;padding:25px 18px}.topbar{align-items:flex-start}.user-box{display:none}.payment-layout{display:block}.card{margin-bottom:20px}.form-row{grid-template-columns:1fr}.footer{margin-left:-18px;margin-right:-18px}.actions{flex-direction:column-reverse}.btn{width:100%}}
</style>
</head>
<body>
<div class="sidebar">
<div class="brand">
<div class="brand-name">Rent<span>Ease</span></div>
<div class="brand-tagline">Renting Made Easy.</div>
</div>
<div class="menu-title">Main</div>
<a href="dashboard.php"><span class="menu-icon">⌂</span>Dashboard</a>
<a href="profile.php"><span class="menu-icon">●</span>Profile</a>
<div class="menu-title">My Rental</div>
<a href="lease.php"><span class="menu-icon">▣</span>Lease Information</a>
<a href="payments.php" class="active"><span class="menu-icon">$</span>Rent & Utilities</a>
<a href="maintenance.php"><span class="menu-icon">⚙</span>Maintenance</a>
<a href="inspections.php"><span class="menu-icon">◫</span>Inspections</a>
<a href="communication.php"><span class="menu-icon">✉</span>Communication</a>
<a href="privacy.php"><span class="menu-icon">◆</span>Privacy</a>
<div class="logout-area">
<a href="../auth/logout.php" class="logout"><span class="menu-icon">↪</span>Logout</a>
</div>
</div>

<div class="main-content">
<div class="topbar">
<div class="page-title">
<h1>Make Payment</h1>
<p>Complete your payment securely through RentEase.</p>
</div>
<a href="profile.php" class="user-box">
<div class="small-avatar"><?php echo htmlspecialchars($tenantLetter); ?></div>
<div>
<strong><?php echo htmlspecialchars($tenantName); ?></strong>
<span>Tenant</span>
</div>
</a>
</div>

<div class="payment-layout">

<div class="card">
<div class="card-header">
<h2>Payment Summary</h2>
<p>Review the charge before making payment.</p>
</div>

<div class="card-body">
<div class="summary">
<div class="summary-label"><?php echo $type==='rent'?'Rent Payment':'Utility Payment'; ?></div>
<h3><?php echo htmlspecialchars($paymentTitle); ?></h3>
<p><?php echo htmlspecialchars($payment['address_line1'].', '.$payment['suburb'].', '.$payment['state'].' '.$payment['postcode']); ?></p>
</div>

<div class="amount-box">
<div class="amount-label">Amount to Pay</div>
<div class="amount">$<?php echo number_format($amount,2); ?></div>
</div>

<div class="info-row">
<span class="info-label">Payment Type</span>
<span class="info-value"><?php echo $type==='rent'?'Rent':'Utility'; ?></span>
</div>

<?php if($type==='utility'): ?>
<div class="info-row">
<span class="info-label">Provider</span>
<span class="info-value"><?php echo htmlspecialchars($payment['provider_name']?:'—'); ?></span>
</div>
<?php endif; ?>

<div class="info-row">
<span class="info-label">Due Date</span>
<span class="info-value"><?php echo showDate($payment['due_date']); ?></span>
</div>

<div class="info-row">
<span class="info-label">Current Status</span>
<span class="info-value"><?php echo htmlspecialchars(ucwords(str_replace('_',' ',$payment['status']))); ?></span>
</div>

<div class="notice">
This is a demonstration payment screen. Card details are used only for this payment form and are not stored in the RentEase database.
</div>
</div>
</div>

<div class="card">
<div class="card-header">
<h2>Card Details</h2>
<p>Enter the card information to complete the payment.</p>
</div>

<div class="card-body">

<?php if($error): ?>
<div class="error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="card-preview">
<div class="preview-brand">RentEase</div>
<div class="preview-chip"></div>
<div class="preview-number" id="previewNumber">•••• •••• •••• ••••</div>
<div class="preview-bottom">
<div>CARD HOLDER<strong id="previewName">YOUR NAME</strong></div>
<div>EXPIRES<strong id="previewExpiry">MM/YY</strong></div>
</div>
</div>

<form method="POST" action="make_payment.php">
<input type="hidden" name="type" value="<?php echo htmlspecialchars($type); ?>">
<input type="hidden" name="id" value="<?php echo (int)$id; ?>">

<div class="form-group">
<label>Name on Card</label>
<input type="text" name="card_name" id="cardName" class="form-control" maxlength="100" autocomplete="cc-name" required>
</div>

<div class="form-group">
<label>Card Number</label>
<input type="text" name="card_number" id="cardNumber" class="form-control" maxlength="23" inputmode="numeric" autocomplete="cc-number" placeholder="1234 5678 9012 3456" required>
</div>

<div class="form-row">
<div class="form-group">
<label>Expiry</label>
<input type="text" name="expiry" id="expiry" class="form-control" maxlength="5" inputmode="numeric" autocomplete="cc-exp" placeholder="MM/YY" required>
</div>

<div class="form-group">
<label>CVV</label>
<input type="password" name="cvv" class="form-control" maxlength="4" inputmode="numeric" autocomplete="cc-csc" placeholder="123" required>
</div>
</div>

<div class="actions">
<a href="payments.php" class="btn btn-secondary">Cancel</a>
<button type="submit" class="btn btn-primary">Pay $<?php echo number_format($amount,2); ?></button>
</div>
</form>

</div>
</div>

</div>

<footer class="footer">
<strong>© 2026 RentEase Property Management System</strong>
&nbsp; • &nbsp;
Renting Made Easy.
</footer>
</div>

<script>
const cardName=document.getElementById('cardName');
const cardNumber=document.getElementById('cardNumber');
const expiry=document.getElementById('expiry');
const previewName=document.getElementById('previewName');
const previewNumber=document.getElementById('previewNumber');
const previewExpiry=document.getElementById('previewExpiry');

cardName.addEventListener('input',function(){
previewName.textContent=this.value.trim().toUpperCase()||'YOUR NAME';
});

cardNumber.addEventListener('input',function(){
let value=this.value.replace(/\D/g,'').slice(0,19);
this.value=value.replace(/(.{4})/g,'$1 ').trim();
previewNumber.textContent=this.value||'•••• •••• •••• ••••';
});

expiry.addEventListener('input',function(){
let value=this.value.replace(/\D/g,'').slice(0,4);
if(value.length>2){
value=value.slice(0,2)+'/'+value.slice(2);
}
this.value=value;
previewExpiry.textContent=value||'MM/YY';
});
</script>
</body>
</html>