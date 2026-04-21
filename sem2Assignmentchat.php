<?php
// sem2Assignmentchat.php - One-on-one chat with another user
session_start();
require_once 'sem2Assignmentdatabase.php';

// Function to convert URLs to clickable links
if (!function_exists('convertUrlsToLinks')) {
    function convertUrlsToLinks($text) {
        $pattern = '/(https?:\/\/[^\s]+)/';
        $replacement = '<a href="$1" style="color: #007bff; text-decoration: underline;">$1</a>';
        return preg_replace($pattern, $replacement, $text);
    }
}

if (!isset($_SESSION['userdetails_id'])) {
    header("Location: sem2Assignmentlog.php");
    exit();
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: sem2Assignmentmessages.php");
    exit();
}

$connection = dbConnect();
$current_user_id = $_SESSION['userdetails_id'];
$other_user_id = intval($_GET['id']);

// Prevent messaging yourself
if ($current_user_id == $other_user_id) {
    header("Location: sem2Profile.php");
    exit();
}

// Fetch other user's details
$statement = $connection->prepare("SELECT name, profilePic FROM userdetails WHERE id = ?");
$statement->bind_param("i", $other_user_id);
$statement->execute();
$result = $statement->get_result();
$other_user = $result->fetch_assoc();
$statement->close();

if (!$other_user) {
    echo "User not found.";
    exit();
}

// Handle message deletion - FIXED VERSION
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_message'])) {
    $message_id = intval($_POST['delete_message']);
    
    // Verify the user owns this message (sender only can delete)
    $verify_stmt = $connection->prepare("SELECT sender_id FROM messages WHERE id = ? AND sender_id = ? AND is_deleted = FALSE");
    $verify_stmt->bind_param("ii", $message_id, $current_user_id);
    $verify_stmt->execute();
    $verify_result = $verify_stmt->get_result();
    
    if ($verify_result->num_rows > 0) {
        $verify_stmt->close();
        
        // Mark message as deleted
        $delete_stmt = $connection->prepare("UPDATE messages SET is_deleted = TRUE WHERE id = ?");
        $delete_stmt->bind_param("i", $message_id);
        
        if ($delete_stmt->execute()) {
            $_SESSION['delete_success'] = "Message deleted successfully.";
        } else {
            $_SESSION['delete_error'] = "Failed to delete message: " . $connection->error;
        }
        $delete_stmt->close();
    } else {
        $_SESSION['delete_error'] = "You can only delete messages you sent.";
        $verify_stmt->close();
    }
    
    // Redirect back to chat to prevent resubmission
    header("Location: sem2Assignmentchat.php?id=$other_user_id");
    exit();
}

// Handle message sending
$messageSent = false;
$error = null;

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['message'])) {
    $message = trim($_POST['message']);
    
    if (!empty($message)) {
        // Insert message
        $statement = $connection->prepare("INSERT INTO messages (sender_id, receiver_id, message) VALUES (?, ?, ?)");
        $statement->bind_param("iis", $current_user_id, $other_user_id, $message);
        
        if ($statement->execute()) {
            $last_message_id = $connection->insert_id;
            $messageSent = true;
            
            // Create notification for the receiver
            $notificationMessage = "Sent you a message: " . (strlen($message) > 50 ? substr($message, 0, 50) . '...' : $message);
            $notificationStmt = $connection->prepare("INSERT INTO notifications (sender_id, receiver_id, notification) VALUES (?, ?, ?)");
            $notificationStmt->bind_param("iis", $current_user_id, $other_user_id, $notificationMessage);
            $notificationStmt->execute();
            $notificationStmt->close();
            
            // Refresh to show the new message
            header("Location: sem2Assignmentchat.php?id=$other_user_id");
            exit();
        } else {
            $error = "Failed to send message: " . $connection->error;
        }
        $statement->close();
    } else {
        $error = "Message cannot be empty.";
    }
}

// Handle file upload (images/GIFs)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_FILES['chat_file']) && $_FILES['chat_file']['error'] == 0) {
    $uploadDir = "uploads/chat_files/";
    
    // Create directory if it doesn't exist
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    $fileName = uniqid() . '_' . basename($_FILES["chat_file"]["name"]);
    $uploadFile = $uploadDir . $fileName;
    $fileType = strtolower(pathinfo($uploadFile, PATHINFO_EXTENSION));
    $allowedExtensions = ["jpg", "jpeg", "png", "gif", "webp"];
    
    // Increased size limit to 10MB for GIFs
    if (in_array($fileType, $allowedExtensions) && $_FILES["chat_file"]["size"] <= 10000000) {
        if (move_uploaded_file($_FILES["chat_file"]["tmp_name"], $uploadFile)) {
            // Insert a message indicating file was sent
            $fileMessage = "[FILE:{$fileName}]";
            $statement = $connection->prepare("INSERT INTO messages (sender_id, receiver_id, message) VALUES (?, ?, ?)");
            $statement->bind_param("iis", $current_user_id, $other_user_id, $fileMessage);
            
            if ($statement->execute()) {
                $last_message_id = $connection->insert_id;
                
                // Save file info in message_attachments table
                $attachmentStmt = $connection->prepare("INSERT INTO message_attachments (message_id, file_name, file_type, file_path) VALUES (?, ?, ?, ?)");
                $attachmentStmt->bind_param("isss", $last_message_id, $fileName, $fileType, $uploadFile);
                $attachmentStmt->execute();
                $attachmentStmt->close();
                
                $messageSent = true;
                
                // Create notification for the receiver
                $fileTypeName = ($fileType == 'gif') ? 'GIF' : 'image';
                $notificationMessage = "Sent you a {$fileTypeName}";
                $notificationStmt = $connection->prepare("INSERT INTO notifications (sender_id, receiver_id, notification) VALUES (?, ?, ?)");
                $notificationStmt->bind_param("iis", $current_user_id, $other_user_id, $notificationMessage);
                $notificationStmt->execute();
                $notificationStmt->close();
                
            } else {
                $error = "Failed to send file: " . $connection->error;
            }
            $statement->close();
        } else {
            $error = "Sorry, there was an error uploading your file.";
        }
    } else {
        $error = "Invalid file. Please upload JPG, JPEG, PNG, GIF, or WebP files under 10MB.";
    }
    
    if ($messageSent) {
        header("Location: sem2Assignmentchat.php?id=$other_user_id");
        exit();
    }
}

// Check for delete success/error messages
$delete_success = '';
$delete_error = '';
if (isset($_SESSION['delete_success'])) {
    $delete_success = $_SESSION['delete_success'];
    unset($_SESSION['delete_success']);
}
if (isset($_SESSION['delete_error'])) {
    $delete_error = $_SESSION['delete_error'];
    unset($_SESSION['delete_error']);
}

// Auto-fill message from URL parameter
$prefilledMessage = '';
if (isset($_GET['message'])) {
    $prefilledMessage = urldecode($_GET['message']);
}

// Fetch chat history with attachments (exclude deleted messages) - FIXED QUERY
$statement = $connection->prepare("
    SELECT m.id, m.sender_id, m.message, m.created_at,
           ma.file_name, ma.file_type, ma.file_path
    FROM messages m 
    LEFT JOIN message_attachments ma ON m.id = ma.message_id
    WHERE ((m.sender_id = ? AND m.receiver_id = ?) 
       OR (m.sender_id = ? AND m.receiver_id = ?))
    AND m.is_deleted = FALSE
    ORDER BY m.created_at ASC
");
$statement->bind_param("iiii", $current_user_id, $other_user_id, $other_user_id, $current_user_id);
$statement->execute();
$result = $statement->get_result();
$messages = $result->fetch_all(MYSQLI_ASSOC);
$statement->close();
$connection->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat with <?php echo htmlspecialchars($other_user['name']); ?></title>
	<link rel="icon" type="image/x-icon" href="SMP_logo-(1).ico">
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            margin: 0;
            padding: 0;
            display: flex;
            flex-direction: column;
            height: 100vh;
        }

        .chat-header {
            background-color: #007bff;
            color: white;
            padding: 15px 20px;
            display: flex;
            align-items: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .chat-header img {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            margin-right: 15px;
        }

        .chat-header h2 {
            margin: 0;
            font-size: 20px;
        }

        .back-btn {
            margin-left: auto;
            background-color: rgba(255,255,255,0.2);
            color: white;
            padding: 8px 15px;
            text-decoration: none;
            border-radius: 5px;
        }

        .back-btn:hover {
            background-color: rgba(255,255,255,0.3);
        }

        .chat-container {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
            background-color: #e9ecef;
        }

        .message {
            margin-bottom: 15px;
            display: flex;
            position: relative;
            animation: slideIn 0.3s ease-out;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .message.sent {
            justify-content: flex-end;
        }

        .message.received {
            justify-content: flex-start;
        }

        .message-bubble {
            max-width: 60%;
            padding: 10px 15px;
            border-radius: 18px;
            word-wrap: break-word;
            position: relative;
        }

        .message.sent .message-bubble {
            background-color: #007bff;
            color: white;
        }

        .message.received .message-bubble {
            background-color: white;
            color: #333;
        }

        .message-time {
            font-size: 11px;
            margin-top: 5px;
            opacity: 0.7;
        }

        .message-actions {
            position: absolute;
            top: -8px;
            opacity: 0;
            transition: opacity 0.2s;
            background: white;
            border-radius: 15px;
            padding: 2px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
            z-index: 10;
        }

        .message.sent .message-actions {
            right: -5px;
        }

        .message:hover .message-actions {
            opacity: 1;
        }

        .delete-btn {
            background: #dc3545;
            color: white;
            border: none;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            cursor: pointer;
            font-size: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.2s;
        }

        .delete-btn:hover {
            background: #c82333;
            transform: scale(1.1);
        }

        .message-input-container {
            background-color: white;
            padding: 15px 20px;
            border-top: 1px solid #ddd;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .message-input-container textarea {
            flex: 1;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 20px;
            resize: none;
            font-family: Arial, sans-serif;
            font-size: 14px;
        }

        .message-input-container textarea:focus {
            outline: none;
            border-color: #007bff;
        }

        .message-input-container button {
            padding: 10px 20px;
            background-color: #007bff;
            color: white;
            border: none;
            border-radius: 20px;
            cursor: pointer;
            font-weight: bold;
        }

        .message-input-container button:hover {
            background-color: #0056b3;
        }

        .no-messages {
            text-align: center;
            color: #666;
            padding: 40px;
        }

        .error {
            color: red;
            padding: 10px;
            background-color: #f8d7da;
            margin: 10px;
            border-radius: 5px;
            border: 1px solid #f5c6cb;
        }

        .success {
            color: green;
            padding: 10px;
            background-color: #d4edda;
            margin: 10px;
            border-radius: 5px;
            border: 1px solid #c3e6cb;
        }

        .chat-image {
            max-width: 300px;
            max-height: 300px;
            border-radius: 10px;
            margin: 5px 0;
            cursor: pointer;
        }

        .file-upload-btn {
            background-color: #28a745;
            color: white;
            padding: 10px 15px;
            border: none;
            border-radius: 20px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .file-upload-btn:hover {
            background-color: #218838;
        }

        .file-input-wrapper {
            position: relative;
            display: inline-block;
        }

        .file-input-wrapper input[type="file"] {
            position: absolute;
            left: 0;
            top: 0;
            opacity: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
        }

        .message-bubble a {
            color: inherit;
            text-decoration: underline;
        }

        .message.sent .message-bubble a {
            color: #e6f3ff;
        }

        .message.received .message-bubble a {
            color: #007bff;
        }

        .delete-form {
            display: inline;
            margin: 0;
        }
    </style>
</head>
<body>
    <div class="chat-header">
        <img src="<?php echo getProfilePicPath($other_user['profilePic']); ?>" alt="Profile">
        <h2><?php echo htmlspecialchars($other_user['name']); ?></h2>
        <a href="sem2Assignmentmessages.php" class="back-btn">← Back to Messages</a>
    </div>

    <div class="chat-container" id="chatContainer">
        <?php if (isset($error)): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if (isset($messageSent) && $messageSent): ?>
            <div class="success">Message sent successfully!</div>
        <?php endif; ?>

        <?php if ($delete_success): ?>
            <div class="success"><?php echo htmlspecialchars($delete_success); ?></div>
        <?php endif; ?>

        <?php if ($delete_error): ?>
            <div class="error"><?php echo htmlspecialchars($delete_error); ?></div>
        <?php endif; ?>

        <?php if (!empty($messages)): ?>
            <?php foreach ($messages as $msg): ?>
                <?php $isSent = ($msg['sender_id'] == $current_user_id); ?>
                <div class="message <?php echo $isSent ? 'sent' : 'received'; ?>">
                    <div class="message-bubble">
                        <?php if (!empty($msg['file_name'])): ?>
                            <!-- Display image/GIF -->
                            <?php if (in_array($msg['file_type'], ['gif', 'jpg', 'jpeg', 'png', 'webp'])): ?>
                                <img src="<?php echo htmlspecialchars($msg['file_path']); ?>" 
                                     alt="Shared image" 
                                     class="chat-image"
                                     onclick="window.open(this.src, '_blank')">
                            <?php else: ?>
                                <a href="<?php echo htmlspecialchars($msg['file_path']); ?>" download>
                                    Download File: <?php echo htmlspecialchars($msg['file_name']); ?>
                                </a>
                            <?php endif; ?>
                        <?php else: ?>
                            <!-- Display text message with clickable links -->
                            <?php echo convertUrlsToLinks(nl2br(htmlspecialchars($msg['message']))); ?>
                        <?php endif; ?>
                        
                        <div class="message-time">
                            <?php echo date('g:i A', strtotime($msg['created_at'])); ?>
                        </div>
                    </div>
                    
                    <!-- Delete button (only show for user's own messages) -->
                    <?php if ($isSent): ?>
                        <div class="message-actions">
                            <form method="POST" class="delete-form" onsubmit="return confirm('Are you sure you want to delete this message?');">
                                <input type="hidden" name="delete_message" value="<?php echo $msg['id']; ?>">
                                <button type="submit" class="delete-btn" title="Delete message">×</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="no-messages">
                No messages yet. Say hello! 👋
            </div>
        <?php endif; ?>
    </div>

    <form method="POST" enctype="multipart/form-data" class="message-input-container">
        <div class="file-input-wrapper">
            <button type="button" class="file-upload-btn">📎</button>
            <input type="file" name="chat_file" accept="image/*,image/gif" id="chatFileInput">
        </div>
        
        <textarea name="message" 
                  placeholder="Type a message..." 
                  rows="1" 
                  id="messageTextarea"
                  onkeypress="if(event.keyCode==13 && !event.shiftKey){event.preventDefault(); this.form.requestSubmit(); return false;}"><?php echo htmlspecialchars($prefilledMessage); ?></textarea>
        
        <button type="submit">Send</button>
    </form>

    <script>
        // Auto-scroll to bottom of chat
        const chatContainer = document.getElementById('chatContainer');
        chatContainer.scrollTop = chatContainer.scrollHeight;

        // Auto-resize textarea
        const textarea = document.getElementById('messageTextarea');
        textarea.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = Math.min(this.scrollHeight, 120) + 'px';
        });

        // Focus on textarea when page loads
        textarea.focus();

        // File input change handler
        document.getElementById('chatFileInput').addEventListener('change', function() {
            if (this.files.length > 0) {
                // Auto-submit form when file is selected
                this.form.submit();
            }
        });

        // Auto-focus and select if there's a prefilled message
        <?php if (!empty($prefilledMessage)): ?>
            textarea.focus();
            textarea.select();
        <?php endif; ?>

        // Hide success/error messages after 5 seconds
        setTimeout(function() {
            const successMessages = document.querySelectorAll('.success');
            const errorMessages = document.querySelectorAll('.error');
            
            successMessages.forEach(msg => {
                msg.style.transition = 'opacity 0.5s';
                msg.style.opacity = '0';
                setTimeout(() => msg.style.display = 'none', 500);
            });
            errorMessages.forEach(msg => {
                msg.style.transition = 'opacity 0.5s';
                msg.style.opacity = '0';
                setTimeout(() => msg.style.display = 'none', 500);
            });
        }, 5000);
    </script>
</body>
</html>