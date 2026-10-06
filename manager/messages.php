<?php
session_start();
require_once __DIR__.'/../config/db.php';

// Only a logged-in manager can open this page
if(!isset($_SESSION['user_id'])){
    header("Location: ../index.php");
    exit();
}
if(($_SESSION['role']??'')!=='manager'){
    header("Location: ../tenant/dashboard.php");
    exit();
}

$managerId=(int)$_SESSION['user_id'];
$error="";
$success="";

// Show flash message once only
if(isset($_SESSION['flash_success'])){
    $success=$_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

// Load manager information
$stmt=$conn->prepare("SELECT user_id,first_name,last_name,email FROM users WHERE user_id=? AND role='manager' LIMIT 1");
if(!$stmt) die("Manager query error: ".$conn->error);
$stmt->bind_param("i",$managerId);
$stmt->execute();
$manager=$stmt->get_result()->fetch_assoc();

if(!$manager){
    session_destroy();
    header("Location: ../index.php");
    exit();
}

$managerName=trim($manager['first_name']." ".$manager['last_name']);
$managerLetter=strtoupper(substr($manager['first_name'],0,1));

// Load active tenants
$tenants=[];
$result=$conn->query("SELECT user_id,first_name,last_name,email FROM users WHERE role='tenant' AND account_status='active' ORDER BY first_name,last_name");
if($result){
    while($row=$result->fetch_assoc()){
        $tenants[]=$row;
    }
}

// Find selected tenant
$selectedTenantId=isset($_GET['tenant'])?(int)$_GET['tenant']:0;

// Open latest conversation if no tenant was selected
if($selectedTenantId<=0){
    $stmt=$conn->prepare("SELECT tenant_id FROM conversations WHERE manager_id=? ORDER BY last_message_at DESC,conversation_id DESC LIMIT 1");
    if($stmt){
        $stmt->bind_param("i",$managerId);
        $stmt->execute();
        $recent=$stmt->get_result()->fetch_assoc();

        if($recent){
            $selectedTenantId=(int)$recent['tenant_id'];
        }elseif(!empty($tenants)){
            $selectedTenantId=(int)$tenants[0]['user_id'];
        }
    }
}

// Load selected tenant
$selectedTenant=null;

if($selectedTenantId>0){
    $stmt=$conn->prepare("SELECT user_id,first_name,last_name,email,phone FROM users WHERE user_id=? AND role='tenant' AND account_status='active' LIMIT 1");
    if($stmt){
        $stmt->bind_param("i",$selectedTenantId);
        $stmt->execute();
        $selectedTenant=$stmt->get_result()->fetch_assoc();
    }
}

// Find current conversation
$conversationId=0;

if($selectedTenant){
    $stmt=$conn->prepare("SELECT conversation_id FROM conversations WHERE manager_id=? AND tenant_id=? LIMIT 1");
    if($stmt){
        $stmt->bind_param("ii",$managerId,$selectedTenantId);
        $stmt->execute();
        $conversation=$stmt->get_result()->fetch_assoc();

        if($conversation){
            $conversationId=(int)$conversation['conversation_id'];
        }
    }
}

// Handle forms
if($_SERVER['REQUEST_METHOD']==='POST'){
    $action=$_POST['action']??'';

    // Send message to tenant
    if($action==='send_message'){
        $postTenantId=(int)($_POST['tenant_id']??0);
        $messageText=trim($_POST['message_text']??'');

        $stmt=$conn->prepare("SELECT user_id,first_name,last_name FROM users WHERE user_id=? AND role='tenant' AND account_status='active' LIMIT 1");
        $postTenant=null;

        if($stmt){
            $stmt->bind_param("i",$postTenantId);
            $stmt->execute();
            $postTenant=$stmt->get_result()->fetch_assoc();
        }

        if(!$postTenant){
            $error="Tenant account could not be found.";
        }elseif($messageText===''){
            $error="Please enter a message.";
        }elseif(strlen($messageText)>3000){
            $error="Your message is too long.";
        }else{
            $conn->begin_transaction();

            try{
                // Find existing conversation
                $stmt=$conn->prepare("SELECT conversation_id FROM conversations WHERE manager_id=? AND tenant_id=? LIMIT 1");
                if(!$stmt){
                    throw new Exception("Conversation query failed.");
                }

                $stmt->bind_param("ii",$managerId,$postTenantId);
                $stmt->execute();
                $existing=$stmt->get_result()->fetch_assoc();

                if($existing){
                    $postConversationId=(int)$existing['conversation_id'];
                }else{
                    // Create conversation
                    $subject="General tenancy communication";

                    $stmt=$conn->prepare("INSERT INTO conversations(manager_id,tenant_id,subject,created_at,last_message_at) VALUES(?,?,?,NOW(),NOW())");
                    if(!$stmt){
                        throw new Exception("Conversation could not be created.");
                    }

                    $stmt->bind_param("iis",$managerId,$postTenantId,$subject);

                    if(!$stmt->execute()){
                        throw new Exception("Conversation could not be created.");
                    }

                    $postConversationId=(int)$conn->insert_id;
                }

                // Save message
                $stmt=$conn->prepare("INSERT INTO messages(conversation_id,sender_id,message_text,sent_at,is_read) VALUES(?,?,?,NOW(),0)");
                if(!$stmt){
                    throw new Exception("Message could not be prepared.");
                }

                $stmt->bind_param("iis",$postConversationId,$managerId,$messageText);

                if(!$stmt->execute()){
                    throw new Exception("Message could not be sent.");
                }

                $messageId=(int)$conn->insert_id;

                // Update conversation time
                $stmt=$conn->prepare("UPDATE conversations SET last_message_at=NOW() WHERE conversation_id=?");
                if($stmt){
                    $stmt->bind_param("i",$postConversationId);
                    $stmt->execute();
                }

                // Save Activity Log
                $description="Manager sent a message to ".$postTenant['first_name']." ".$postTenant['last_name'].".";

                $stmt=$conn->prepare("INSERT INTO activity_log(user_id,action_type,entity_type,entity_id,description) VALUES(?,'SEND_MESSAGE','message',?,?)");
                if($stmt){
                    $stmt->bind_param("iis",$managerId,$messageId,$description);
                    $stmt->execute();
                }

                $conn->commit();

                // Flash message appears once only
                $_SESSION['flash_success']="Message sent successfully.";

                // No sent=1 in URL
                header("Location: messages.php?tenant=".$postTenantId);
                exit();

            }catch(Throwable $e){
                $conn->rollback();
                $error="The message could not be sent. Please try again.";
            }
        }
    }

    // Send announcement or reminder
    if($action==='send_notification'){
        $recipientType=$_POST['recipient_type']??'individual';
        $notificationTenantId=(int)($_POST['notification_tenant_id']??0);
        $title=trim($_POST['title']??'');
        $notificationMessage=trim($_POST['notification_message']??'');

        if($recipientType==='all'){
            $messageType='general_announcement';
        }else{
            $messageType=trim($_POST['message_type']??'general_announcement');
        }

        $allowedTypes=[
            'general_announcement',
            'rent_reminder',
            'inspection_reminder',
            'maintenance_update',
            'lease_renewal',
            'payment_update'
        ];

        if(!in_array($messageType,$allowedTypes,true)){
            $error="Invalid communication type.";
        }elseif($title===''){
            $error="Please enter a title.";
        }elseif($notificationMessage===''){
            $error="Please enter a message.";
        }elseif($recipientType!=='all' && $notificationTenantId<=0){
            $error="Please select a tenant.";
        }else{
            $conn->begin_transaction();

            try{
                // Send to all active tenants
                if($recipientType==='all'){
                    $tenantResult=$conn->query("SELECT user_id FROM users WHERE role='tenant' AND account_status='active'");

                    if(!$tenantResult){
                        throw new Exception("Tenant list could not be loaded.");
                    }

                    $insert=$conn->prepare("INSERT INTO notifications(recipient_id,sender_id,notification_type,title,message,related_entity_type,related_entity_id,is_read,sent_at) VALUES(?,?,?,?,?,'general',NULL,0,NOW())");

                    if(!$insert){
                        throw new Exception("Notification could not be prepared.");
                    }

                    while($tenantRow=$tenantResult->fetch_assoc()){
                        $recipientId=(int)$tenantRow['user_id'];

                        $insert->bind_param(
                            "iisss",
                            $recipientId,
                            $managerId,
                            $messageType,
                            $title,
                            $notificationMessage
                        );

                        if(!$insert->execute()){
                            throw new Exception("Announcement could not be sent.");
                        }
                    }

                    $description="Manager sent a general announcement to all tenants.";
                }else{
                    // Check selected tenant
                    $stmt=$conn->prepare("SELECT user_id,first_name,last_name FROM users WHERE user_id=? AND role='tenant' AND account_status='active' LIMIT 1");

                    if(!$stmt){
                        throw new Exception("Tenant query failed.");
                    }

                    $stmt->bind_param("i",$notificationTenantId);
                    $stmt->execute();
                    $notificationTenant=$stmt->get_result()->fetch_assoc();

                    if(!$notificationTenant){
                        throw new Exception("Tenant not found.");
                    }

                    // Send to one tenant
                    $stmt=$conn->prepare("INSERT INTO notifications(recipient_id,sender_id,notification_type,title,message,related_entity_type,related_entity_id,is_read,sent_at) VALUES(?,?,?,?,?,'general',NULL,0,NOW())");

                    if(!$stmt){
                        throw new Exception("Notification could not be prepared.");
                    }

                    $stmt->bind_param(
                        "iisss",
                        $notificationTenantId,
                        $managerId,
                        $messageType,
                        $title,
                        $notificationMessage
                    );

                    if(!$stmt->execute()){
                        throw new Exception("Notification could not be sent.");
                    }

                    $description="Manager sent a communication to ".$notificationTenant['first_name']." ".$notificationTenant['last_name'].".";
                }

                // Save Activity Log
                $stmt=$conn->prepare("INSERT INTO activity_log(user_id,action_type,entity_type,entity_id,description) VALUES(?,'SEND_NOTIFICATION','notification',NULL,?)");

                if($stmt){
                    $stmt->bind_param("is",$managerId,$description);
                    $stmt->execute();
                }

                $conn->commit();

                // Flash message appears once only
                $_SESSION['flash_success']="Communication sent successfully.";

                // Keep notification tab open but remove notification_sent=1
                header("Location: messages.php?tab=notifications");
                exit();

            }catch(Throwable $e){
                $conn->rollback();
                $error="The communication could not be sent. Please try again.";
            }
        }
    }
}

// Reload selected conversation
$conversationId=0;

if($selectedTenant){
    $stmt=$conn->prepare("SELECT conversation_id FROM conversations WHERE manager_id=? AND tenant_id=? LIMIT 1");

    if($stmt){
        $stmt->bind_param("ii",$managerId,$selectedTenantId);
        $stmt->execute();
        $conversation=$stmt->get_result()->fetch_assoc();

        if($conversation){
            $conversationId=(int)$conversation['conversation_id'];
        }
    }
}

// Mark tenant messages as read
if($conversationId>0){
    $stmt=$conn->prepare("UPDATE messages SET is_read=1,read_at=NOW() WHERE conversation_id=? AND sender_id!=? AND is_read=0");

    if($stmt){
        $stmt->bind_param("ii",$conversationId,$managerId);
        $stmt->execute();
    }
}

// Load conversation messages
$messages=[];

if($conversationId>0){
    $stmt=$conn->prepare("
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

    if($stmt){
        $stmt->bind_param("i",$conversationId);
        $stmt->execute();
        $result=$stmt->get_result();

        while($row=$result->fetch_assoc()){
            $messages[]=$row;
        }
    }
}

// Build conversation list
$conversationList=[];

$stmt=$conn->prepare("
SELECT
u.user_id AS tenant_id,
u.first_name,
u.last_name,
u.email,
c.conversation_id,
c.last_message_at,
(
    SELECT m.message_text
    FROM messages m
    WHERE m.conversation_id=c.conversation_id
    ORDER BY m.sent_at DESC,m.message_id DESC
    LIMIT 1
) AS last_message,
(
    SELECT COUNT(*)
    FROM messages m2
    WHERE m2.conversation_id=c.conversation_id
    AND m2.sender_id=u.user_id
    AND m2.is_read=0
) AS unread_count
FROM users u
LEFT JOIN conversations c
ON c.tenant_id=u.user_id
AND c.manager_id=?
WHERE u.role='tenant'
AND u.account_status='active'
ORDER BY
CASE WHEN c.last_message_at IS NULL THEN 1 ELSE 0 END,
c.last_message_at DESC,
u.first_name ASC
");

if($stmt){
    $stmt->bind_param("i",$managerId);
    $stmt->execute();
    $result=$stmt->get_result();

    while($row=$result->fetch_assoc()){
        $conversationList[]=$row;
    }
}

// Count unread tenant messages
$totalUnread=0;

$stmt=$conn->prepare("
SELECT COUNT(*) AS total
FROM messages m
INNER JOIN conversations c ON c.conversation_id=m.conversation_id
WHERE c.manager_id=?
AND m.sender_id!=?
AND m.is_read=0
");

if($stmt){
    $stmt->bind_param("ii",$managerId,$managerId);
    $stmt->execute();
    $totalUnread=(int)$stmt->get_result()->fetch_assoc()['total'];
}

// Load recent announcements and reminders
$sentNotifications=[];

$stmt=$conn->prepare("
SELECT
n.notification_id,
n.notification_type,
n.title,
n.message,
n.sent_at,
n.is_read,
u.first_name,
u.last_name
FROM notifications n
INNER JOIN users u ON u.user_id=n.recipient_id
WHERE n.sender_id=?
ORDER BY n.sent_at DESC
LIMIT 30
");

if($stmt){
    $stmt->bind_param("i",$managerId);
    $stmt->execute();
    $result=$stmt->get_result();

    while($row=$result->fetch_assoc()){
        $sentNotifications[]=$row;
    }
}

// Format date and time
function showDateTime($date){
    if(!$date) return "No messages yet";
    return date("d M Y, g:i A",strtotime($date));
}

// Make database values easier to read
function niceText($text){
    return ucwords(str_replace("_"," ",$text??""));
}

$openNotificationTab=($_GET['tab']??'')==='notifications';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Communication | RentEase</title>

<style>
/* Main page */
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:"Segoe UI",Arial,sans-serif;background:#f5f7f6;color:#243331;font-size:16px}

/* Manager sidebar */
.sidebar{position:fixed;top:0;left:0;width:255px;height:100vh;padding:28px 18px;background:#183b3a;overflow-y:auto}
.brand{padding:0 12px 24px;border-bottom:1px solid rgba(255,255,255,.12)}
.brand-name{color:#fff;font-size:28px;font-weight:800}
.brand-name span{color:#80c7ba}
.brand-tagline{margin-top:4px;color:#b8d0cc;font-size:13px}
.menu-title{margin:23px 12px 8px;color:#8fb2ac;font-size:11px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase}
.sidebar a{display:flex;align-items:center;gap:12px;margin-bottom:4px;padding:12px 13px;border-radius:9px;color:#d8e5e2;text-decoration:none;font-size:15px;font-weight:500}
.sidebar a:hover{background:rgba(255,255,255,.08);color:#fff}
.sidebar a.active{background:#2f8178;color:#fff;font-weight:700}
.menu-icon{width:21px;text-align:center}
.menu-count{display:flex;align-items:center;justify-content:center;min-width:21px;height:21px;margin-left:auto;padding:0 6px;border-radius:20px;background:#80c7ba;color:#183b3a;font-size:11px;font-weight:700}
.logout-area{margin-top:25px;padding-top:15px;border-top:1px solid rgba(255,255,255,.12)}
.sidebar .logout{color:#f0c4c4}

/* Main content */
.main{margin-left:255px;min-height:100vh;padding:35px 42px 25px}
.topbar{display:flex;justify-content:space-between;align-items:center;gap:20px;margin-bottom:20px}
.page-title h1{margin:0;color:#243331;font-size:32px;font-weight:700;letter-spacing:-.5px}
.page-title p{margin-top:7px;color:#687976;font-size:16px}

/* Manager shown in top corner */
.top-profile{display:flex;align-items:center;gap:11px;min-width:190px;padding:9px 14px;background:#fff;border:1px solid #d9e3e0;border-radius:12px;color:#243331;text-decoration:none}
.profile-icon{display:flex;align-items:center;justify-content:center;width:42px;height:42px;border-radius:50%;background:#2f8178;color:#fff;font-size:17px;font-weight:800}
.profile-info strong{display:block;color:#243331;font-size:15px}
.profile-info span{display:block;margin-top:3px;color:#71817e;font-size:13px}

/* Page messages */
.alert{margin-bottom:18px;padding:13px 16px;border-radius:9px;font-size:14px}
.alert.success{background:#e8f5ee;border:1px solid #cce8d8;color:#287a55}
.alert.error{background:#fae7e7;border:1px solid #efcaca;color:#a84545}

/* Communication tabs */
.tabs{display:flex;gap:9px;margin-bottom:16px}
.tab-btn{padding:11px 18px;background:#fff;border:1px solid #dce5e2;border-radius:9px;color:#40514e;font-family:inherit;font-size:14px;font-weight:600;cursor:pointer}
.tab-btn:hover{border-color:#2f8178;color:#286f68}
.tab-btn.active{background:#2f8178;border-color:#2f8178;color:#fff}
.tab-content{display:none}
.tab-content.active{display:block}

/* Tenant messages layout */
.communication-grid{display:grid;grid-template-columns:330px 1fr;height:610px;background:#fff;border:1px solid #dce5e2;border-radius:15px;overflow:hidden;box-shadow:0 7px 25px rgba(37,64,60,.06)}
.tenant-panel{background:#fbfcfc;border-right:1px solid #dce5e2;overflow-y:auto}
.tenant-panel-title{padding:20px;background:#fff;border-bottom:1px solid #dce5e2;position:sticky;top:0;z-index:2}
.tenant-panel-title h3{color:#304440;font-size:19px;font-weight:600}
.tenant-panel-title p{margin-top:5px;color:#71817e;font-size:13px}
.tenant-item{display:block;padding:16px 18px;border-bottom:1px solid #e8eeec;color:#243331;text-decoration:none}
.tenant-item:hover{background:#f1f7f5}
.tenant-item.active{background:#e8f3f1;border-left:4px solid #2f8178}
.tenant-top{display:flex;justify-content:space-between;align-items:center;gap:10px}
.tenant-name{color:#304440;font-size:15px;font-weight:600}
.unread-badge{display:flex;align-items:center;justify-content:center;min-width:22px;height:22px;padding:0 7px;border-radius:20px;background:#2f8178;color:#fff;font-size:11px;font-weight:700}
.last-message{margin-top:7px;overflow:hidden;color:#687976;font-size:13px;text-overflow:ellipsis;white-space:nowrap}
.last-time{margin-top:5px;color:#8b9996;font-size:11px}

/* Chat */
.chat-panel{display:flex;flex-direction:column;min-width:0;height:610px}
.chat-header{display:flex;align-items:center;gap:13px;padding:17px 20px;border-bottom:1px solid #dce5e2;background:#fff}
.tenant-avatar{display:flex;align-items:center;justify-content:center;width:43px;height:43px;border-radius:50%;background:#2f8178;color:#fff;font-size:17px;font-weight:700}
.chat-header strong{display:block;color:#304440;font-size:16px}
.chat-header span{display:block;margin-top:3px;color:#71817e;font-size:13px}
.chat-area{flex:1;padding:20px;overflow-y:auto;background:#f7f9f8}
.message-row{display:flex;margin-bottom:15px}
.message-row.manager{justify-content:flex-end}
.message-row.tenant{justify-content:flex-start}
.message-bubble{max-width:72%;padding:12px 15px;border-radius:13px;font-size:14px;line-height:1.6}
.message-row.manager .message-bubble{background:#2f8178;color:#fff;border-bottom-right-radius:4px}
.message-row.tenant .message-bubble{background:#fff;color:#40514e;border:1px solid #dce5e2;border-bottom-left-radius:4px}
.message-name{margin-bottom:5px;font-size:12px;font-weight:700}
.message-row.manager .message-name{color:#d8efeb}
.message-row.tenant .message-name{color:#286f68}
.message-time{margin-top:7px;font-size:11px}
.message-row.manager .message-time{color:#c9e5df}
.message-row.tenant .message-time{color:#8b9996}
.chat-empty{display:flex;align-items:center;justify-content:center;height:100%;padding:30px;color:#71817e;font-size:14px;line-height:1.6;text-align:center}

/* Reply box */
.reply-box{padding:14px 18px;background:#fff;border-top:1px solid #dce5e2}
.reply-box form{display:flex;align-items:center;gap:10px}
.reply-box textarea{width:100%;min-height:52px;max-height:90px;padding:11px 12px;border:1px solid #ccd8d5;border-radius:9px;color:#243331;font-family:inherit;font-size:14px;resize:vertical;outline:none}
.reply-box textarea:focus{border-color:#2f8178;box-shadow:0 0 0 3px rgba(47,129,120,.08)}

/* Main buttons */
.btn{display:inline-block;padding:11px 18px;background:#2f8178;border:none;border-radius:8px;color:#fff;font-family:inherit;font-size:14px;font-weight:600;text-decoration:none;white-space:nowrap;cursor:pointer}
.btn:hover{background:#286f68}

/* Announcement cards */
#notificationTab{width:100%}
#notificationTab .section{width:100%;margin-bottom:20px;background:#fff;border:1px solid #dce5e2;border-radius:15px;overflow:hidden;box-shadow:0 7px 25px rgba(37,64,60,.06)}
#notificationTab .section-header{padding:17px 20px;background:#eef6f4;border-bottom:1px solid #dce5e2}
#notificationTab .section-header h2{margin:0;color:#263936;font-size:20px;font-weight:600}
#notificationTab .section-header p{margin-top:4px;color:#687976;font-size:14px}

/* Compact announcement form */
#notificationTab .form-body{padding:18px 20px 20px}
#notificationTab .form-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px 18px}
#notificationTab .form-group{margin:0}
#notificationTab .form-group.full{grid-column:1/-1}
#notificationTab label{display:block;margin-bottom:6px;color:#40514e;font-size:14px;font-weight:600}
#notificationTab input,
#notificationTab select{width:100%;height:42px;padding:9px 11px;background:#fff;border:1px solid #ccd8d5;border-radius:8px;color:#243331;font-family:inherit;font-size:14px;outline:none}
#notificationTab textarea{width:100%;min-height:80px;max-height:130px;padding:10px 11px;background:#fff;border:1px solid #ccd8d5;border-radius:8px;color:#243331;font-family:inherit;font-size:14px;line-height:1.5;resize:vertical;outline:none}
#notificationTab input:focus,
#notificationTab select:focus,
#notificationTab textarea:focus{border-color:#2f8178;box-shadow:0 0 0 3px rgba(47,129,120,.08)}

#notificationTab .btn{margin-top:14px}

/* Communication history */
#notificationTab .table-wrap{width:100%;max-height:340px;overflow:auto;background:#fff}
#notificationTab table{width:100%;min-width:950px;border-collapse:collapse;table-layout:fixed;font-size:13px}
#notificationTab thead{position:sticky;top:0;z-index:3}
#notificationTab th{padding:11px 13px;background:#f1f7f5;border-bottom:1px solid #dce5e2;color:#526562;text-align:left;font-size:11px;font-weight:700;letter-spacing:.3px;text-transform:uppercase}
#notificationTab td{padding:12px 13px;border-bottom:1px solid #e7edeb;color:#40514e;font-size:13px;line-height:1.45;vertical-align:middle}
#notificationTab tbody tr:hover{background:#f8fbfa}
#notificationTab th:nth-child(1),#notificationTab td:nth-child(1){width:12%}
#notificationTab th:nth-child(2),#notificationTab td:nth-child(2){width:14%}
#notificationTab th:nth-child(3),#notificationTab td:nth-child(3){width:17%}
#notificationTab th:nth-child(4),#notificationTab td:nth-child(4){width:32%}
#notificationTab th:nth-child(5),#notificationTab td:nth-child(5){width:17%}
#notificationTab th:nth-child(6),#notificationTab td:nth-child(6){width:8%}
.type-badge{display:inline-block;padding:5px 9px;border-radius:20px;background:#e2f2ef;color:#286f68;font-size:11px;font-weight:700;white-space:nowrap}
.status-read{color:#287a55;font-weight:600}
.status-unread{color:#96671e;font-weight:600}
.empty-state{padding:28px 20px;color:#71817e;font-size:14px;text-align:center}

/* RentEase footer */
.footer{margin-top:30px;margin-left:-42px;margin-right:-42px;margin-bottom:-25px;padding:20px 25px;background:#2f8178;color:#fff;text-align:center;font-size:14px}
.footer strong{color:#fff;font-weight:700}

/* Smaller screens */
@media(max-width:1100px){
    .communication-grid{grid-template-columns:280px 1fr}
    #notificationTab .form-grid{grid-template-columns:1fr 1fr}
    #notificationTab .form-group.full{grid-column:1/-1}
}

@media(max-width:760px){
    .sidebar{position:relative;width:100%;height:auto}
    .main{margin-left:0;padding:25px 18px}
    .top-profile{display:none}
    .communication-grid{grid-template-columns:1fr;height:auto}
    .tenant-panel{max-height:280px;border-right:none;border-bottom:1px solid #dce5e2}
    .chat-panel{height:560px}
    .message-bubble{max-width:88%}
    .reply-box form{flex-direction:column;align-items:stretch}
    #notificationTab .form-grid{grid-template-columns:1fr}
    #notificationTab .form-group.full{grid-column:1}
    .footer{margin-left:-18px;margin-right:-18px}
}
</style>
</head>

<body>

<!-- Manager sidebar -->
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

    <div class="menu-title">Management</div>

    <a href="properties/properties.php">
        <span class="menu-icon">⌂</span>
        Properties
    </a>

    <a href="tenants/tenants.php">
        <span class="menu-icon">♙</span>
        Tenants
    </a>

    <a href="lease/leases.php">
        <span class="menu-icon">▣</span>
        Leases
    </a>

    <a href="rent/payments.php">
        <span class="menu-icon">$</span>
        Rent & Utilities
    </a>

    <div class="menu-title">Operations</div>

    <a href="maintenance/maintenance.php">
        <span class="menu-icon">⚙</span>
        Maintenance
    </a>

    <a href="inspections/inspections.php">
        <span class="menu-icon">◫</span>
        Inspections
    </a>

    <a href="messages.php" class="active">
        <span class="menu-icon">✉</span>
        Communication

        <?php if ($totalUnread>0): ?>
            <span class="menu-count">
                <?php echo $totalUnread; ?>
            </span>
        <?php endif; ?>
    </a>

    <div class="menu-title">System</div>

    <a href="activity_log.php">
        <span class="menu-icon">☷</span>
        Activity Log
    </a>

    <div class="logout-area">
        <a href="../auth/logout.php" class="logout">
            <span class="menu-icon">↪</span>
            Logout
        </a>
    </div>

</div>

<!-- Main page -->
<div class="main">

    <div class="topbar">

        <div class="page-title">
            <h1>Communication Centre</h1>
            <p>Communicate with tenants and send important updates.</p>
        </div>

        <!-- Manager shown in the top corner -->
        <a href="profile.php" class="top-profile">

            <div class="profile-icon">
                <?php echo htmlspecialchars($managerLetter); ?>
            </div>

            <div class="profile-info">
                <strong><?php echo htmlspecialchars($managerName); ?></strong>
                <span>Property Manager</span>
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

    <!-- Communication tabs -->
    <div class="tabs">

        <button
            type="button"
            class="tab-btn <?php echo !$openNotificationTab?'active':''; ?>"
            onclick="showTab('messagesTab',this)"
        >
            Tenant Messages
            <?php if ($totalUnread>0): ?>
                (<?php echo $totalUnread; ?>)
            <?php endif; ?>
        </button>

        <button
            type="button"
            class="tab-btn <?php echo $openNotificationTab?'active':''; ?>"
            onclick="showTab('notificationTab',this)"
        >
            Announcements & Reminders
        </button>

    </div>

    <!-- Tenant messages -->
    <div
        id="messagesTab"
        class="tab-content <?php echo !$openNotificationTab?'active':''; ?>"
    >

        <div class="communication-grid">

            <!-- Tenant list -->
            <div class="tenant-panel">

                <div class="tenant-panel-title">
                    <h3>Tenants</h3>
                    <p>Select a tenant to view the conversation.</p>
                </div>

                <?php if ($conversationList): ?>

                    <?php foreach ($conversationList as $item): ?>

                        <a
                            class="tenant-item <?php echo (int)$item['tenant_id']===$selectedTenantId?'active':''; ?>"
                            href="messages.php?tenant=<?php echo (int)$item['tenant_id']; ?>"
                        >

                            <div class="tenant-top">

                                <span class="tenant-name">
                                    <?php echo htmlspecialchars($item['first_name']." ".$item['last_name']); ?>
                                </span>

                                <?php if ((int)$item['unread_count']>0): ?>
                                    <span class="unread-badge">
                                        <?php echo (int)$item['unread_count']; ?>
                                    </span>
                                <?php endif; ?>

                            </div>

                            <div class="last-message">
                                <?php echo htmlspecialchars($item['last_message'] ?: 'No conversation yet'); ?>
                            </div>

                            <div class="last-time">
                                <?php echo showDateTime($item['last_message_at']); ?>
                            </div>

                        </a>

                    <?php endforeach; ?>

                <?php else: ?>

                    <div class="empty-state">
                        No active tenants found.
                    </div>

                <?php endif; ?>

            </div>

            <!-- Conversation -->
            <div class="chat-panel">

                <?php if ($selectedTenant): ?>

                    <div class="chat-header">

                        <div class="tenant-avatar">
                            <?php echo htmlspecialchars(strtoupper(substr($selectedTenant['first_name'],0,1))); ?>
                        </div>

                        <div>
                            <strong>
                                <?php echo htmlspecialchars($selectedTenant['first_name']." ".$selectedTenant['last_name']); ?>
                            </strong>

                            <span>
                                <?php echo htmlspecialchars($selectedTenant['email']); ?>
                            </span>
                        </div>

                    </div>

                    <!-- Messages -->
                    <div class="chat-area" id="chatArea">

                        <?php if ($messages): ?>

                            <?php foreach ($messages as $message): ?>

                                <?php $isManager=(int)$message['sender_id']===$managerId; ?>

                                <div class="message-row <?php echo $isManager?'manager':'tenant'; ?>">

                                    <div class="message-bubble">

                                        <div class="message-name">
                                            <?php
                                            echo $isManager
                                                ? 'You'
                                                : htmlspecialchars($message['first_name']." ".$message['last_name']);
                                            ?>
                                        </div>

                                        <div>
                                            <?php echo nl2br(htmlspecialchars($message['message_text'])); ?>
                                        </div>

                                        <div class="message-time">

                                            <?php echo showDateTime($message['sent_at']); ?>

                                            <?php if ($isManager): ?>

                                                &nbsp; • &nbsp;

                                                <?php echo (bool)$message['is_read']?'Read':'Sent'; ?>

                                            <?php endif; ?>

                                        </div>

                                    </div>

                                </div>

                            <?php endforeach; ?>

                        <?php else: ?>

                            <div class="chat-empty">
                                No messages with this tenant yet.<br>
                                Send the first message below.
                            </div>

                        <?php endif; ?>

                    </div>

                    <!-- Send a reply -->
                    <div class="reply-box">

                        <form method="POST">

                            <input type="hidden" name="action" value="send_message">

                            <input
                                type="hidden"
                                name="tenant_id"
                                value="<?php echo $selectedTenantId; ?>"
                            >

                            <textarea
                                name="message_text"
                                maxlength="3000"
                                placeholder="Type your message to <?php echo htmlspecialchars($selectedTenant['first_name']); ?>..."
                                required
                            ></textarea>

                            <button type="submit" class="btn">
                                Send
                            </button>

                        </form>

                    </div>

                <?php else: ?>

                    <div class="chat-empty">
                        No tenant selected.
                    </div>

                <?php endif; ?>

            </div>

        </div>

    </div>

    <!-- Announcements and reminders -->
    <div
        id="notificationTab"
        class="tab-content <?php echo $openNotificationTab?'active':''; ?>"
    >

        <!-- Send communication -->
        <div class="section">

            <div class="section-header">
                <h2>Send Announcement or Reminder</h2>
                <p>Send an update to one tenant or all active tenants.</p>
            </div>

            <div class="form-body">

                <form method="POST">

                    <input
                        type="hidden"
                        name="action"
                        value="send_notification"
                    >

                    <div class="form-grid">

                        <!-- Choose recipient -->
                        <div class="form-group">

                            <label for="recipientType">
                                Recipient
                            </label>

                            <select
                                name="recipient_type"
                                id="recipientType"
                                onchange="updateRecipientFields()"
                            >
                                <option value="individual">
                                    Individual Tenant
                                </option>

                                <option value="all">
                                    All Tenants
                                </option>
                            </select>

                        </div>

                        <!-- Choose tenant -->
                        <div
                            class="form-group"
                            id="tenantSelectGroup"
                        >

                            <label for="notificationTenant">
                                Tenant
                            </label>

                            <select
                                name="notification_tenant_id"
                                id="notificationTenant"
                            >

                                <option value="">
                                    Select Tenant
                                </option>

                                <?php foreach ($tenants as $tenantItem): ?>

                                    <option value="<?php echo (int)$tenantItem['user_id']; ?>">
                                        <?php echo htmlspecialchars($tenantItem['first_name']." ".$tenantItem['last_name']); ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                        <!-- Choose communication type -->
                        <div
                            class="form-group"
                            id="messageTypeGroup"
                        >

                            <label for="messageType">
                                Communication Type
                            </label>

                            <select
                                name="message_type"
                                id="messageType"
                            >
                                <option value="general_announcement">
                                    General Announcement
                                </option>

                                <option value="rent_reminder">
                                    Rent Reminder
                                </option>

                                <option value="inspection_reminder">
                                    Inspection Reminder
                                </option>

                                <option value="maintenance_update">
                                    Maintenance Update
                                </option>

                                <option value="lease_renewal">
                                    Lease Renewal
                                </option>

                                <option value="payment_update">
                                    Payment Update
                                </option>
                            </select>

                            

                        </div>

                        <!-- Communication title -->
                        <div class="form-group full">

                            <label for="notificationTitle">
                                Title
                            </label>

                            <input
                                type="text"
                                name="title"
                                id="notificationTitle"
                                maxlength="160"
                                placeholder="Example: Upcoming Inspection"
                                required
                            >

                        </div>

                        <!-- Communication message -->
                        <div class="form-group full">

                            <label for="notificationMessage">
                                Message
                            </label>

                            <textarea
                                name="notification_message"
                                id="notificationMessage"
                                placeholder="Write the communication here..."
                                required
                            ></textarea>

                        </div>

                    </div>

                    <button type="submit" class="btn">
                        Send Communication
                    </button>

                </form>

            </div>

        </div>

        <!-- Communication history -->
        <div class="section">

            <div class="section-header">
                <h2>Recent Communications</h2>
                <p>Recently sent announcements and reminders.</p>
            </div>

            <?php if ($sentNotifications): ?>

                <div class="table-wrap">

                    <table>

                        <thead>
                            <tr>
                                <th>Tenant</th>
                                <th>Type</th>
                                <th>Title</th>
                                <th>Message</th>
                                <th>Sent</th>
                                <th>Status</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php foreach ($sentNotifications as $notice): ?>

                                <tr>

                                    <td>
                                        <?php echo htmlspecialchars($notice['first_name']." ".$notice['last_name']); ?>
                                    </td>

                                    <td>
                                        <span class="type-badge">
                                            <?php echo htmlspecialchars(niceText($notice['notification_type'])); ?>
                                        </span>
                                    </td>

                                    <td>
                                        <strong>
                                            <?php echo htmlspecialchars($notice['title']); ?>
                                        </strong>
                                    </td>

                                    <td>
                                        <?php echo htmlspecialchars($notice['message']); ?>
                                    </td>

                                    <td>
                                        <?php echo showDateTime($notice['sent_at']); ?>
                                    </td>

                                    <td>

                                        <?php if ((bool)$notice['is_read']): ?>

                                            <span class="status-read">
                                                Read
                                            </span>

                                        <?php else: ?>

                                            <span class="status-unread">
                                                Unread
                                            </span>

                                        <?php endif; ?>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php else: ?>

                <div class="empty-state">
                    No communications sent yet.
                </div>

            <?php endif; ?>

        </div>

    </div>

    <!-- RentEase footer -->
    <footer class="footer">
        <strong>© 2026 RentEase Property Management System</strong>
        &nbsp; • &nbsp;
        Renting Made Easy.
    </footer>

</div>

<script>
// Switch between messages and announcements
function showTab(tabId,button) {
    document.querySelectorAll(".tab-content").forEach(function(tab) {
        tab.classList.remove("active");
    });

    document.querySelectorAll(".tab-btn").forEach(function(btn) {
        btn.classList.remove("active");
    });

    document.getElementById(tabId).classList.add("active");
    button.classList.add("active");

    window.scrollTo({
        top:0,
        behavior:"smooth"
    });
}

// Change fields depending on the recipient
function updateRecipientFields() {
    const recipientType=document.getElementById("recipientType").value;
    const tenantGroup=document.getElementById("tenantSelectGroup");
    const messageType=document.getElementById("messageType");
    const messageTypeGroup=document.getElementById("messageTypeGroup");

    if (recipientType==="all") {
        tenantGroup.style.display="none";
        messageType.value="general_announcement";
        messageType.disabled=true;

        // Make the remaining two boxes balanced
        messageTypeGroup.style.gridColumn="span 2";
    } else {
        tenantGroup.style.display="block";
        messageType.disabled=false;
        messageTypeGroup.style.gridColumn="auto";
    }
}

updateRecipientFields();

// Keep the latest chat message visible
const chatArea=document.getElementById("chatArea");

if (chatArea) {
    chatArea.scrollTop=chatArea.scrollHeight;
}
</script>

</body>
</html>