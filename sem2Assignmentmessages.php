<?php
// sem2Assignmentmessages.php - View all message conversations
session_start();
require_once 'sem2Assignmentdatabase.php';

if (!isset($_SESSION['userdetails_id'])) {
    header("Location: sem2Assignmentlog.php");
    exit();
}

$connection = dbConnect();
$current_user_id = $_SESSION['userdetails_id'];

// If user clicks to start conversation with specific user
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $other_user_id = intval($_GET['id']);
    header("Location: sem2Assignmentchat.php?id=$other_user_id");
    exit();
}

// Get all users the current user has messaged or received messages from
// FIXED: Use a working approach for MySQL to exclude deleted messages
$query = "
    SELECT 
        conv.other_user_id,
        conv.other_user_name,
        conv.profilePic,
        (
            SELECT m2.message 
            FROM messages m2
            WHERE ((m2.sender_id = ? AND m2.receiver_id = conv.other_user_id) 
                OR (m2.sender_id = conv.other_user_id AND m2.receiver_id = ?))
            AND m2.is_deleted = FALSE
            ORDER BY m2.created_at DESC
            LIMIT 1
        ) as last_message,
        (
            SELECT m2.created_at 
            FROM messages m2
            WHERE ((m2.sender_id = ? AND m2.receiver_id = conv.other_user_id) 
                OR (m2.sender_id = conv.other_user_id AND m2.receiver_id = ?))
            AND m2.is_deleted = FALSE
            ORDER BY m2.created_at DESC
            LIMIT 1
        ) as last_message_time
    FROM (
        SELECT DISTINCT 
            CASE 
                WHEN m.sender_id = ? THEN m.receiver_id
                ELSE m.sender_id
            END as other_user_id,
            u.name as other_user_name,
            u.profilePic
        FROM messages m
        JOIN userdetails u ON u.id = CASE 
            WHEN m.sender_id = ? THEN m.receiver_id
            ELSE m.sender_id
        END
        WHERE (m.sender_id = ? OR m.receiver_id = ?)
    ) as conv
    HAVING last_message IS NOT NULL
    ORDER BY last_message_time DESC
";

$statement = $connection->prepare($query);
$statement->bind_param("iiiiiiii", 
    $current_user_id, $current_user_id,
    $current_user_id, $current_user_id,
    $current_user_id, $current_user_id,
    $current_user_id, $current_user_id
);
$statement->execute();
$result = $statement->get_result();
$conversations = $result->fetch_all(MYSQLI_ASSOC);
$statement->close();

$connection->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your Messages - SMP</title>
	<link rel="icon" type="image/x-icon" href="SMP_logo-(1).ico">
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            padding: 20px;
            margin: 0;
        }

        .messages-container {
            max-width: 800px;
            margin: 0 auto;
            background-color: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }

        h1, h2 {
            color: #333;
            margin-bottom: 20px;
        }

        .conversation {
            display: flex;
            align-items: center;
            padding: 15px;
            margin-bottom: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            background-color: #f9f9f9;
            text-decoration: none;
            color: inherit;
            transition: background-color 0.3s, transform 0.2s;
        }

        .conversation:hover {
            background-color: #e9ecef;
            transform: translateX(5px);
        }

        .conversation-pic {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            object-fit: cover;
            margin-right: 15px;
            border: 2px solid #007bff;
        }

        .conversation-info {
            flex: 1;
        }

        .conversation-name {
            font-weight: bold;
            color: #007bff;
            margin-bottom: 5px;
            font-size: 16px;
        }

        .conversation-preview {
            color: #666;
            font-size: 14px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 500px;
        }

        .conversation-preview.no-message {
            color: #999;
            font-style: italic;
        }

        .conversation-preview.file-message {
            color: #28a745;
            font-weight: 500;
        }

        .conversation-time {
            color: #999;
            font-size: 12px;
            margin-left: 10px;
            white-space: nowrap;
        }

        .no-messages {
            text-align: center;
            padding: 40px;
            color: #666;
        }

        .no-messages h3 {
            color: #007bff;
            margin-bottom: 10px;
        }

        .back-link {
            display: inline-block;
            margin-top: 20px;
            padding: 10px 20px;
            background-color: #007bff;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            transition: background-color 0.3s;
        }

        .back-link:hover {
            background-color: #0056b3;
        }

        .info-box {
            background-color: #e7f3ff;
            border-left: 4px solid #007bff;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
        }

        .info-box p {
            margin: 0;
            color: #004085;
        }

        .conversations-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .conversation-count {
            background-color: #007bff;
            color: white;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: bold;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }

        .empty-state-icon {
            font-size: 64px;
            margin-bottom: 20px;
        }

        .empty-state h3 {
            color: #333;
            margin-bottom: 10px;
        }

        .empty-state p {
            color: #666;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="messages-container">
        <h1>📧 Your Messages</h1>

        <?php if (!empty($conversations)): ?>
            <div class="conversations-header">
                <h2>Recent Conversations</h2>
                <span class="conversation-count"><?php echo count($conversations); ?> conversation<?php echo count($conversations) != 1 ? 's' : ''; ?></span>
            </div>
            <?php foreach ($conversations as $conv): ?>
                <a href="sem2Assignmentchat.php?id=<?php echo intval($conv['other_user_id']); ?>" class="conversation">
                    <img src="<?php echo getProfilePicPath($conv['profilePic']); ?>" alt="Profile" class="conversation-pic">
                    <div class="conversation-info">
                        <div class="conversation-name"><?php echo htmlspecialchars($conv['other_user_name']); ?></div>
                        <div class="conversation-preview <?php echo empty($conv['last_message']) ? 'no-message' : ''; ?> <?php echo (strpos($conv['last_message'] ?? '', '[FILE:') === 0) ? 'file-message' : ''; ?>">
                            <?php 
                                if (!empty($conv['last_message'])) {
                                    // Check if it's a file message
                                    if (strpos($conv['last_message'], '[FILE:') === 0) {
                                        echo '📎 Sent an image';
                                    } else {
                                        // Truncate long messages
                                        $preview = htmlspecialchars($conv['last_message']);
                                        echo strlen($preview) > 60 ? substr($preview, 0, 60) . '...' : $preview;
                                    }
                                } else {
                                    echo 'No messages available';
                                }
                            ?>
                        </div>
                    </div>
                    <div class="conversation-time">
                        <?php 
                            if (!empty($conv['last_message_time'])) {
                                $time = strtotime($conv['last_message_time']);
                                $now = time();
                                $diff = $now - $time;
                                
                                // Show relative time for recent messages
                                if ($diff < 60) {
                                    echo 'Just now';
                                } elseif ($diff < 3600) {
                                    echo floor($diff / 60) . 'm ago';
                                } elseif ($diff < 86400) {
                                    echo floor($diff / 3600) . 'h ago';
                                } elseif ($diff < 604800) {
                                    echo floor($diff / 86400) . 'd ago';
                                } else {
                                    echo date('M j, g:i A', $time);
                                }
                            }
                        ?>
                    </div>
                </a>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty-state">
                <div class="empty-state-icon">💬</div>
                <h3>No conversations yet</h3>
                <p>Start connecting with others by sending your first message!</p>
                <div class="info-box">
                    <p><strong>💡 Tip:</strong> Go to your profile, search for users, and click "Message" to start a conversation.</p>
                </div>
            </div>
        <?php endif; ?>

        <a href="sem2Profile.php" class="back-link">← Back to Profile</a>
    </div>
</body>
</html>