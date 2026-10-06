<?php
session_start();
require_once __DIR__ . '/../config/db.php';

// Only tenants can use this page
if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit();
}
if (($_SESSION['role'] ?? '') !== 'tenant') {
    header("Location: ../manager/dashboard.php");
    exit();
}

$tenantId = (int)$_SESSION['user_id'];
$error = "";
$success = "";

// Get tenant details
$stmt = $conn->prepare("SELECT first_name,last_name,email FROM users WHERE user_id=? AND role='tenant' LIMIT 1");
$stmt->bind_param("i",$tenantId);
$stmt->execute();
$tenant = $stmt->get_result()->fetch_assoc();

if (!$tenant) {
    session_destroy();
    header("Location: ../index.php");
    exit();
}

$tenantName = trim($tenant['first_name'].' '.$tenant['last_name']);
$tenantLetter = strtoupper(substr($tenant['first_name'],0,1));

// Find the active manager
$stmt = $conn->prepare("
    SELECT u.user_id,u.first_name,u.last_name,u.email,mp.job_title
    FROM users u
    LEFT JOIN manager_profiles mp ON mp.manager_id=u.user_id
    WHERE u.role='manager' AND u.account_status='active'
    ORDER BY u.user_id ASC
    LIMIT 1
");
$stmt->execute();
$manager = $stmt->get_result()->fetch_assoc();
$managerId = $manager ? (int)$manager['user_id'] : 0;

// Find the conversation between this tenant and manager
$conversationId = 0;

if ($managerId > 0) {
    $stmt = $conn->prepare("
        SELECT conversation_id
        FROM conversations
        WHERE manager_id=? AND tenant_id=?
        LIMIT 1
    ");
    $stmt->bind_param("ii",$managerId,$tenantId);
    $stmt->execute();
    $conversation = $stmt->get_result()->fetch_assoc();

    if ($conversation) {
        $conversationId = (int)$conversation['conversation_id'];
    }
}

// Handle message and notification actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Send a message to the manager
    if ($action === 'send_message') {
        $messageText = trim($_POST['message_text'] ?? '');

        if (!$manager) {
            $error = "No property manager is available.";
        } elseif ($messageText === '') {
            $error = "Please enter a message.";
        } elseif (strlen($messageText) > 3000) {
            $error = "Your message is too long.";
        } else {
            $conn->begin_transaction();

            try {
                // Create a conversation the first time the tenant sends a message
                if ($conversationId <= 0) {
                    $subject = "Tenant Communication";

                    $stmt = $conn->prepare("
                        INSERT INTO conversations
                        (manager_id,tenant_id,subject,created_at,last_message_at)
                        VALUES (?,?,?,NOW(),NOW())
                    ");
                    $stmt->bind_param("iis",$managerId,$tenantId,$subject);

                    if (!$stmt->execute()) {
                        throw new Exception("Could not create conversation.");
                    }

                    $conversationId = $conn->insert_id;
                }

                // Save the new message
                $stmt = $conn->prepare("
                    INSERT INTO messages
                    (conversation_id,sender_id,message_text,sent_at,is_read)
                    VALUES (?,?,?,NOW(),0)
                ");
                $stmt->bind_param("iis",$conversationId,$tenantId,$messageText);

                if (!$stmt->execute()) {
                    throw new Exception("Could not send message.");
                }

                $messageId = $conn->insert_id;

                // Keep the latest conversation at the top on manager side
                $stmt = $conn->prepare("
                    UPDATE conversations
                    SET last_message_at=NOW()
                    WHERE conversation_id=?
                ");
                $stmt->bind_param("i",$conversationId);
                $stmt->execute();

                // Add the action to the system activity log
                $description = "Tenant sent a message to property manager.";

                $stmt = $conn->prepare("
                    INSERT INTO activity_log
                    (user_id,action_type,entity_type,entity_id,description)
                    VALUES (?,'SEND_MESSAGE','message',?,?)
                ");
                $stmt->bind_param("iis",$tenantId,$messageId,$description);
                $stmt->execute();

                $conn->commit();

                header("Location: communication.php?sent=1");
                exit();
            } catch (Throwable $e) {
                $conn->rollback();
                $error = "Your message could not be sent. Please try again.";
            }
        }
    }

    // Mark one notification as read
    elseif ($action === 'mark_notification_read') {
        $notificationId = (int)($_POST['notification_id'] ?? 0);

        if ($notificationId > 0) {
            $stmt = $conn->prepare("
                UPDATE notifications
                SET is_read=1,read_at=NOW()
                WHERE notification_id=? AND recipient_id=?
            ");
            $stmt->bind_param("ii",$notificationId,$tenantId);
            $stmt->execute();
        }

        header("Location: communication.php");
        exit();
    }

    // Mark every notification as read
    elseif ($action === 'mark_all_notifications') {
        $stmt = $conn->prepare("
            UPDATE notifications
            SET is_read=1,read_at=NOW()
            WHERE recipient_id=? AND is_read=0
        ");
        $stmt->bind_param("i",$tenantId);
        $stmt->execute();

        header("Location: communication.php");
        exit();
    }
}

if (isset($_GET['sent']) && $_GET['sent'] === '1') {
    $success = "Your message was sent successfully.";
}

// Messages from the manager become read when the tenant opens this page
if ($conversationId > 0) {
    $stmt = $conn->prepare("
        UPDATE messages
        SET is_read=1,read_at=NOW()
        WHERE conversation_id=? AND sender_id!=? AND is_read=0
    ");
    $stmt->bind_param("ii",$conversationId,$tenantId);
    $stmt->execute();
}

// Get the full conversation
$messages = [];

if ($conversationId > 0) {
    $stmt = $conn->prepare("
        SELECT
            m.message_id,
            m.sender_id,
            m.message_text,
            m.sent_at,
            m.is_read,
            m.read_at,
            u.first_name,
            u.last_name,
            u.role
        FROM messages m
        INNER JOIN users u ON u.user_id=m.sender_id
        WHERE m.conversation_id=?
        ORDER BY m.sent_at ASC,m.message_id ASC
    ");
    $stmt->bind_param("i",$conversationId);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $messages[] = $row;
    }
}

// Get tenant notifications
$notifications = [];

$stmt = $conn->prepare("
    SELECT
        n.notification_id,
        n.notification_type,
        n.title,
        n.message,
        n.related_entity_type,
        n.related_entity_id,
        n.is_read,
        n.sent_at,
        n.read_at,
        u.first_name AS sender_first_name,
        u.last_name AS sender_last_name
    FROM notifications n
    LEFT JOIN users u ON u.user_id=n.sender_id
    WHERE n.recipient_id=?
    ORDER BY n.sent_at DESC
");
$stmt->bind_param("i",$tenantId);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $notifications[] = $row;
}

// Count unread notifications for the sidebar and summary card
$unreadCommunication = 0;

foreach ($notifications as $notification) {
    if (!(bool)$notification['is_read']) {
        $unreadCommunication++;
    }
}

function niceText($text) {
    return ucwords(str_replace("_"," ",$text ?? ""));
}

function showDateTime($date) {
    if (!$date) {
        return "—";
    }

    return date("d M Y, g:i A",strtotime($date));
}

function notificationClass($type) {
    switch ($type) {
        case 'rent_reminder':
            return 'orange';
        case 'inspection_reminder':
            return 'purple';
        case 'maintenance_update':
            return 'teal';
        case 'lease_renewal':
            return 'orange';
        case 'payment_update':
            return 'green';
        default:
            return 'neutral';
    }
}

function notificationIcon($type) {
    switch ($type) {
        case 'rent_reminder':
            return '$';
        case 'inspection_reminder':
            return '◫';
        case 'maintenance_update':
            return '⚙';
        case 'lease_renewal':
            return '▣';
        case 'payment_update':
            return '✓';
        default:
            return '✦';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Communication | RentEase</title>

<style>
*{
    box-sizing:border-box;
}

body{
    margin:0;
    background:#f5f7f6;
    color:#243331;
    font-family:"Segoe UI",Arial,sans-serif;
    font-size:16px;
}

/* Sidebar */
.sidebar{
    position:fixed;
    top:0;
    left:0;
    width:255px;
    height:100vh;
    padding:28px 18px;
    background:#183b3a;
    overflow-y:auto;
}

.brand{
    padding:0 12px 24px;
    border-bottom:1px solid rgba(255,255,255,.12);
}

.brand-name{
    color:#fff;
    font-size:28px;
    font-weight:800;
}

.brand-name span{
    color:#80c7ba;
}

.brand-tagline{
    margin-top:4px;
    color:#b8d0cc;
    font-size:13px;
}

.menu-title{
    margin:23px 12px 8px;
    color:#8fb2ac;
    font-size:11px;
    font-weight:700;
    letter-spacing:1.2px;
    text-transform:uppercase;
}

.sidebar a{
    display:flex;
    align-items:center;
    gap:12px;
    margin-bottom:4px;
    padding:12px 13px;
    border-radius:9px;
    color:#d8e5e2;
    text-decoration:none;
    font-size:15px;
    font-weight:500;
    transition:.2s;
}

.sidebar a:hover{
    background:rgba(255,255,255,.08);
    color:#fff;
}

.sidebar a.active{
    background:#2f8178;
    color:#fff;
    font-weight:700;
}

.menu-icon{
    width:21px;
    text-align:center;
}

.menu-count{
    display:flex;
    align-items:center;
    justify-content:center;
    min-width:21px;
    height:21px;
    margin-left:auto;
    padding:0 6px;
    background:#80c7ba;
    color:#183b3a;
    border-radius:20px;
    font-size:11px;
    font-weight:700;
}

.logout-area{
    margin-top:25px;
    padding-top:15px;
    border-top:1px solid rgba(255,255,255,.12);
}

.sidebar .logout{
    color:#f0c4c4;
}

/* Main page */
.main{
    margin-left:255px;
    min-height:100vh;
    padding:35px 42px 25px;
}

.topbar{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:20px;
    margin-bottom:25px;
}

.page-title h1{
    margin:0;
    color:#243331;
    font-size:32px;
    font-weight:700;
    letter-spacing:-.5px;
}

.page-title p{
    margin:7px 0 0;
    color:#687976;
    font-size:16px;
}

.top-profile{
    display:flex;
    align-items:center;
    gap:11px;
    padding:9px 14px;
    background:#fff;
    border:1px solid #d9e3e0;
    border-radius:12px;
    color:#243331;
    text-decoration:none;
}

.profile-icon{
    display:flex;
    align-items:center;
    justify-content:center;
    width:42px;
    height:42px;
    border-radius:50%;
    background:#2f8178;
    color:#fff;
    font-size:17px;
    font-weight:700;
}

.profile-info strong{
    display:block;
    font-size:15px;
    font-weight:600;
}

.profile-info span{
    display:block;
    margin-top:2px;
    color:#71817e;
    font-size:13px;
}

/* Alerts */
.alert{
    margin-bottom:20px;
    padding:14px 17px;
    border-radius:10px;
    font-size:14px;
}

.alert.success{
    background:#e8f5ee;
    border:1px solid #cce8d8;
    color:#287a55;
}

.alert.error{
    background:#fae8e8;
    border:1px solid #efd0d0;
    color:#a84545;
}

/* Summary cards */
.summary-grid{
    display:grid;
    grid-template-columns:repeat(3,1fr);
    gap:18px;
    margin-bottom:22px;
}

.summary-card{
    padding:20px;
    background:#fff;
    border:1px solid #dce5e2;
    border-radius:14px;
    box-shadow:0 5px 18px rgba(37,64,60,.04);
}

.summary-label{
    color:#71817e;
    font-size:12px;
    font-weight:600;
    letter-spacing:.4px;
    text-transform:uppercase;
}

.summary-value{
    margin-top:9px;
    color:#40514e;
    font-size:21px;
    font-weight:600;
}

.summary-note{
    margin-top:6px;
    color:#83908e;
    font-size:12px;
}

/* Main sections */
.section{
    margin-bottom:22px;
    background:#fff;
    border:1px solid #dce5e2;
    border-radius:15px;
    overflow:hidden;
    box-shadow:0 6px 22px rgba(37,64,60,.05);
}

.section-header{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:20px;
    padding:21px 25px 17px;
    border-bottom:1px solid #e4e9e7;
}

.section-header h2{
    margin:0;
    color:#263936;
    font-size:21px;
    font-weight:600;
}

.section-header p{
    margin:5px 0 0;
    color:#71817e;
    font-size:14px;
}

/* Manager information */
.manager-bar{
    display:flex;
    align-items:center;
    gap:13px;
    padding:17px 22px;
    background:#eef6f4;
    border-bottom:1px solid #d6e6e2;
}

.manager-avatar{
    display:flex;
    align-items:center;
    justify-content:center;
    width:45px;
    height:45px;
    flex-shrink:0;
    border-radius:50%;
    background:#2f8178;
    color:#fff;
    font-size:17px;
    font-weight:700;
}

.manager-info strong{
    display:block;
    color:#304440;
    font-size:15px;
    font-weight:600;
}

.manager-info span{
    display:block;
    margin-top:3px;
    color:#687976;
    font-size:13px;
}

/* Chat */
.chat-area{
    height:440px;
    padding:23px;
    overflow-y:auto;
    background:#f5f8f7;
}

.chat-empty{
    display:flex;
    align-items:center;
    justify-content:center;
    height:100%;
    color:#71817e;
    font-size:14px;
    line-height:1.7;
    text-align:center;
}

.message-row{
    display:flex;
    margin-bottom:16px;
}

.message-row.tenant{
    justify-content:flex-end;
}

.message-row.manager{
    justify-content:flex-start;
}

.message-bubble{
    max-width:70%;
    padding:12px 15px;
    border-radius:13px;
    font-size:14px;
    line-height:1.6;
}

.message-row.tenant .message-bubble{
    background:#2f8178;
    color:#fff;
    border-bottom-right-radius:4px;
}

.message-row.manager .message-bubble{
    background:#fff;
    color:#304440;
    border:1px solid #dce5e2;
    border-bottom-left-radius:4px;
}

.message-name{
    margin-bottom:5px;
    font-size:12px;
    font-weight:700;
}

.message-row.tenant .message-name{
    color:#dff1ed;
}

.message-row.manager .message-name{
    color:#2f8178;
}

.message-time{
    margin-top:7px;
    font-size:11px;
}

.message-row.tenant .message-time{
    color:#d8ece8;
}

.message-row.manager .message-time{
    color:#83908e;
}

/* Message form */
.message-form{
    padding:18px 22px;
    background:#fff;
    border-top:1px solid #dce5e2;
}

.message-form form{
    display:flex;
    align-items:flex-end;
    gap:11px;
}

.message-form textarea{
    width:100%;
    min-height:50px;
    max-height:140px;
    padding:13px 14px;
    border:1px solid #ccd8d5;
    border-radius:9px;
    resize:vertical;
    outline:none;
    color:#304440;
    font-family:inherit;
    font-size:14px;
}

.message-form textarea:focus{
    border-color:#2f8178;
    box-shadow:0 0 0 3px rgba(47,129,120,.09);
}

.send-btn{
    min-height:50px;
    padding:0 23px;
    border:0;
    border-radius:9px;
    background:#2f8178;
    color:#fff;
    font-size:14px;
    font-weight:600;
    cursor:pointer;
}

.send-btn:hover{
    background:#286f68;
}

/* Notifications */
.notifications{
    padding:20px;
}

.notification{
    display:flex;
    gap:14px;
    margin-bottom:13px;
    padding:16px;
    background:#fff;
    border:1px solid #dce5e2;
    border-radius:11px;
}

.notification:last-child{
    margin-bottom:0;
}

.notification.unread{
    background:#f3f9f7;
    border-left:4px solid #2f8178;
}

.notification-icon{
    display:flex;
    align-items:center;
    justify-content:center;
    width:39px;
    height:39px;
    flex-shrink:0;
    border-radius:9px;
    font-size:15px;
    font-weight:700;
}

.notification-icon.teal{
    background:#e2f2ef;
    color:#286f68;
}

.notification-icon.green{
    background:#e8f5ee;
    color:#287a55;
}

.notification-icon.orange{
    background:#fff2d9;
    color:#96671e;
}

.notification-icon.purple{
    background:#f0ebf7;
    color:#70558b;
}

.notification-icon.neutral{
    background:#edf2f1;
    color:#60716e;
}

.notification-content{
    flex:1;
    min-width:0;
}

.notification-top{
    display:flex;
    justify-content:space-between;
    gap:15px;
}

.notification-title{
    color:#304440;
    font-size:14px;
    font-weight:600;
}

.notification-type{
    margin-top:4px;
    color:#2f8178;
    font-size:10px;
    font-weight:700;
    letter-spacing:.3px;
    text-transform:uppercase;
}

.notification-date{
    color:#83908e;
    font-size:11px;
    white-space:nowrap;
}

.notification-message{
    margin-top:9px;
    color:#52635f;
    font-size:13px;
    line-height:1.6;
}

.notification-sender{
    margin-top:8px;
    color:#83908e;
    font-size:11px;
}

.read-form{
    margin-top:10px;
}

.read-btn,
.mark-all-btn{
    padding:7px 12px;
    border:1px solid #a9ccc6;
    border-radius:7px;
    background:#fff;
    color:#286f68;
    font-size:11px;
    font-weight:600;
    cursor:pointer;
}

.read-btn:hover,
.mark-all-btn:hover{
    background:#eef6f4;
}

.read-label{
    display:inline-block;
    margin-top:9px;
    color:#4f8568;
    font-size:11px;
    font-weight:600;
}

.empty{
    padding:40px 25px;
    color:#71817e;
    font-size:14px;
    text-align:center;
}

/* Footer */
.footer{
    margin-top:32px;
    margin-left:-42px;
    margin-right:-42px;
    margin-bottom:-25px;
    padding:20px 25px;
    background:#2f8178;
    color:#fff;
    text-align:center;
    font-size:14px;
}

.footer strong{
    color:#fff;
    font-weight:700;
}

/* Smaller screens */
@media(max-width:900px){
    .summary-grid{
        grid-template-columns:1fr;
    }

    .message-bubble{
        max-width:85%;
    }
}

@media(max-width:760px){
    .sidebar{
        position:relative;
        width:100%;
        height:auto;
    }

    .main{
        margin-left:0;
        padding:25px 18px;
    }

    .top-profile{
        display:none;
    }

    .message-form form{
        flex-direction:column;
        align-items:stretch;
    }

    .send-btn{
        width:100%;
    }

    .notification-top{
        flex-direction:column;
        gap:5px;
    }

    .footer{
        margin-left:-18px;
        margin-right:-18px;
    }
}
</style>
</head>

<body>

<div class="sidebar">

<div class="brand">
<div class="brand-name">Rent<span>Ease</span></div>
<div class="brand-tagline">Renting Made Easy.</div>
</div>

<div class="menu-title">Main</div>

<a href="dashboard.php">
<span class="menu-icon">⌂</span>
Dashboard
</a>

<a href="profile.php">
<span class="menu-icon">●</span>
Profile
</a>

<div class="menu-title">My Rental</div>

<a href="lease.php">
<span class="menu-icon">▣</span>
Lease Information
</a>

<a href="payments.php">
<span class="menu-icon">$</span>
Rent & Utilities
</a>

<a href="maintenance.php">
<span class="menu-icon">⚙</span>
Maintenance
</a>

<a href="inspections.php">
<span class="menu-icon">◫</span>
Inspections
</a>

<a href="communication.php" class="active">
<span class="menu-icon">✉</span>
Communication

<a href="privacy.php">
<span class="menu-icon">◆</span>
Privacy
</a>

<?php if ($unreadCommunication > 0): ?>
<span class="menu-count">
<?php echo $unreadCommunication; ?>
</span>
<?php endif; ?>

</a>

<div class="logout-area">

<a href="../auth/logout.php" class="logout">
<span class="menu-icon">↪</span>
Logout
</a>

</div>

</div>

<div class="main">

<div class="topbar">

<div class="page-title">
<h1>Communication</h1>
<p>Message your property manager and view important rental updates.</p>
</div>

<a href="profile.php" class="top-profile">

<div class="profile-icon">
<?php echo htmlspecialchars($tenantLetter); ?>
</div>

<div class="profile-info">
<strong><?php echo htmlspecialchars($tenantName); ?></strong>
<span>Tenant</span>
</div>

</a>

</div>

<?php if ($success): ?>
<div class="alert success">
<?php echo htmlspecialchars($success); ?>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="alert error">
<?php echo htmlspecialchars($error); ?>
</div>
<?php endif; ?>

<!-- Quick communication summary -->
<div class="summary-grid">

<div class="summary-card">

<div class="summary-label">
Property Manager
</div>

<div class="summary-value">
<?php
if ($manager) {
    echo htmlspecialchars($manager['first_name'].' '.$manager['last_name']);
} else {
    echo "—";
}
?>
</div>

<div class="summary-note">
<?php
echo $manager
    ? htmlspecialchars($manager['job_title'] ?: 'Property Manager')
    : 'No manager available';
?>
</div>

</div>

<div class="summary-card">

<div class="summary-label">
Messages
</div>

<div class="summary-value">
<?php echo count($messages); ?>
</div>

<div class="summary-note">
Conversation history
</div>

</div>

<div class="summary-card">

<div class="summary-label">
Unread Updates
</div>

<div class="summary-value">
<?php echo $unreadCommunication; ?>
</div>

<div class="summary-note">
Notifications requiring attention
</div>

</div>

</div>

<!-- Direct conversation with property manager -->
<div class="section">

<div class="section-header">

<div>
<h2>Message Your Property Manager</h2>
<p>Send a message and view your conversation history.</p>
</div>

</div>

<?php if ($manager): ?>

<div class="manager-bar">

<div class="manager-avatar">
<?php echo htmlspecialchars(strtoupper(substr($manager['first_name'],0,1))); ?>
</div>

<div class="manager-info">

<strong>
<?php
echo htmlspecialchars(
    $manager['first_name'].' '.$manager['last_name']
);
?>
</strong>

<span>
<?php
echo htmlspecialchars(
    $manager['job_title'] ?: 'Property Manager'
);
?>
</span>

</div>

</div>

<div class="chat-area" id="chatArea">

<?php if ($messages): ?>

<?php foreach ($messages as $message): ?>

<?php
$isTenant = (int)$message['sender_id'] === $tenantId;
?>

<div class="message-row <?php echo $isTenant ? 'tenant' : 'manager'; ?>">

<div class="message-bubble">

<div class="message-name">

<?php
if ($isTenant) {
    echo "You";
} else {
    echo htmlspecialchars(
        $message['first_name'].' '.$message['last_name']
    );
}
?>

</div>

<div>
<?php
echo nl2br(
    htmlspecialchars($message['message_text'])
);
?>
</div>

<div class="message-time">

<?php echo showDateTime($message['sent_at']); ?>

<?php if ($isTenant): ?>

&nbsp; • &nbsp;

<?php
echo (bool)$message['is_read']
    ? 'Read'
    : 'Sent';
?>

<?php endif; ?>

</div>

</div>

</div>

<?php endforeach; ?>

<?php else: ?>

<div class="chat-empty">
No messages yet.<br>
Send your first message to your property manager.
</div>

<?php endif; ?>

</div>

<div class="message-form">

<form method="POST">

<input
type="hidden"
name="action"
value="send_message"
>

<textarea
name="message_text"
placeholder="Type your message to the property manager..."
maxlength="3000"
required
></textarea>

<button type="submit" class="send-btn">
Send Message
</button>

</form>

</div>

<?php else: ?>

<div class="empty">
No active property manager was found.
</div>

<?php endif; ?>

</div>

<!-- System notifications are kept separate from direct messages -->
<div class="section">

<div class="section-header">

<div>
<h2>Notifications & Updates</h2>
<p>Rent, maintenance, inspection, payment and lease updates.</p>
</div>

<?php if ($unreadCommunication > 0): ?>

<form method="POST">

<input
type="hidden"
name="action"
value="mark_all_notifications"
>

<button type="submit" class="mark-all-btn">
Mark All as Read
</button>

</form>

<?php endif; ?>

</div>

<?php if ($notifications): ?>

<div class="notifications">

<?php foreach ($notifications as $notification): ?>

<?php
$isUnread = !(bool)$notification['is_read'];
$typeClass = notificationClass(
    $notification['notification_type']
);
$typeIcon = notificationIcon(
    $notification['notification_type']
);
?>

<div class="notification <?php echo $isUnread ? 'unread' : ''; ?>">

<div class="notification-icon <?php echo $typeClass; ?>">
<?php echo $typeIcon; ?>
</div>

<div class="notification-content">

<div class="notification-top">

<div>

<div class="notification-title">
<?php
echo htmlspecialchars(
    $notification['title']
);
?>
</div>

<div class="notification-type">
<?php
echo htmlspecialchars(
    niceText(
        $notification['notification_type']
    )
);
?>
</div>

</div>

<div class="notification-date">
<?php
echo showDateTime(
    $notification['sent_at']
);
?>
</div>

</div>

<div class="notification-message">
<?php
echo nl2br(
    htmlspecialchars(
        $notification['message']
    )
);
?>
</div>

<div class="notification-sender">

From:

<?php
if (!empty($notification['sender_first_name'])) {
    echo htmlspecialchars(
        $notification['sender_first_name'].' '.
        $notification['sender_last_name']
    );
} else {
    echo "RentEase System";
}
?>

</div>

<?php if ($isUnread): ?>

<form method="POST" class="read-form">

<input
type="hidden"
name="action"
value="mark_notification_read"
>

<input
type="hidden"
name="notification_id"
value="<?php echo (int)$notification['notification_id']; ?>"
>

<button type="submit" class="read-btn">
Mark as Read
</button>

</form>

<?php else: ?>

<span class="read-label">
✓ Read
</span>

<?php endif; ?>

</div>

</div>

<?php endforeach; ?>

</div>

<?php else: ?>

<div class="empty">
You do not have any notifications yet.
</div>

<?php endif; ?>

</div>

<footer class="footer">
<strong>© 2026 RentEase Property Management System</strong>
&nbsp; • &nbsp;
Renting Made Easy.
</footer>

</div>

<script>
// Open the conversation at the newest message
const chatArea = document.getElementById("chatArea");

if (chatArea) {
    chatArea.scrollTop = chatArea.scrollHeight;
}
</script>

</body>
</html>