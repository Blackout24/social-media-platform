<?php
//sem2Assignmentnotifications.php
session_start();
require_once 'sem2Assignmentdatabase.php';

if (!isset($_SESSION['userdetails_id'])) {
    header("Location: sem2Assignmentlog.php");
    exit();
}

$connection = dbConnect();
$userdetails_id = $_SESSION['userdetails_id'];

// Fetch all notifications for the current user
$statement = $connection->prepare(
    "SELECT n.id, n.sender_id, n.notification, n.created_at, u.name as sender_name, u.profilePic
    FROM notifications n
    JOIN userdetails u ON n.sender_id = u.id
    WHERE n.receiver_id = ?
    ORDER BY n.created_at DESC"
);
$statement->bind_param("i", $userdetails_id);
$statement->execute();
$result = $statement->get_result();
$notifications = $result->fetch_all(MYSQLI_ASSOC);
$statement->close();

// Get count of unread notifications (you can add a 'read' column to notifications table later)
$notificationCount = count($notifications);

$connection->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your Notifications</title>
	<link rel="icon" type="image/x-icon" href="SMP_logo-(1).ico">
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            padding: 20px;
        }

        .notifications-container {
            max-width: 800px;
            margin: 0 auto;
            background-color: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }

        h1 {
            color: #333;
            margin-bottom: 20px;
        }

        .notification-count {
            background-color: #007bff;
            color: white;
            padding: 10px 20px;
            border-radius: 5px;
            margin-bottom: 20px;
            text-align: center;
            font-weight: bold;
        }

        .notification {
            padding: 15px;
            margin-bottom: 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            background-color: #f9f9f9;
            display: flex;
            align-items: flex-start;
            transition: background-color 0.3s;
        }

        .notification:hover {
            background-color: #e9ecef;
        }

        .notification.new {
            background-color: #e7f3ff;
            border-left: 4px solid #007bff;
        }

        .notification-pic {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            object-fit: cover;
            margin-right: 15px;
        }

        .notification-content {
            flex: 1;
        }

        .notification-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
        }

        .notification strong {
            color: #007bff;
            font-size: 16px;
        }

        .notification-message {
            color: #333;
            margin: 8px 0;
            line-height: 1.5;
        }

        .notification small {
            color: #666;
            font-size: 12px;
        }

        .notification-actions {
            margin-top: 10px;
        }

        .notification-actions a {
            display: inline-block;
            padding: 6px 12px;
            background-color: #007bff;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            font-size: 14px;
            margin-right: 8px;
        }

        .notification-actions a:hover {
            background-color: #0056b3;
        }

        .notification-actions a.view-profile {
            background-color: #28a745;
        }

        .notification-actions a.view-profile:hover {
            background-color: #218838;
        }

        .back-link {
            display: inline-block;
            margin-top: 20px;
            padding: 10px 20px;
            background-color: #007bff;
            color: white;
            text-decoration: none;
            border-radius: 5px;
        }

        .back-link:hover {
            background-color: #0056b3;
        }

        .no-notifications {
            text-align: center;
            padding: 40px;
            color: #666;
            background-color: #f8f9fa;
            border-radius: 5px;
        }

        .clear-notifications {
            display: inline-block;
            margin-left: 10px;
            padding: 8px 15px;
            background-color: #dc3545;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            font-size: 14px;
        }

        .clear-notifications:hover {
            background-color: #c82333;
        }

        .notification-type {
            font-size: 12px;
            color: #6c757d;
            margin-bottom: 5px;
            text-transform: uppercase;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="notifications-container">
        <h1>📬 Your Notifications</h1>
        
        <?php if ($notificationCount > 0): ?>
            <div class="notification-count">
                You have <?php echo $notificationCount; ?> notification<?php echo $notificationCount > 1 ? 's' : ''; ?>
                <a href="sem2Assignmentclearnotifications.php" class="clear-notifications" onclick="return confirm('Are you sure you want to clear all notifications?')">Clear All</a>
            </div>
        <?php endif; ?>

        <?php if (!empty($notifications)): ?>
            <?php foreach ($notifications as $notif): ?>
                <div class="notification <?php echo $notif['created_at'] > date('Y-m-d H:i:s', strtotime('-1 hour')) ? 'new' : ''; ?>">
                    <img src="<?php echo getProfilePicPath($notif['profilePic']); ?>" alt="Profile" class="notification-pic">
                    <div class="notification-content">
                        <div class="notification-header">
                            <strong><?php echo htmlspecialchars($notif['sender_name']); ?></strong>
                            <small><?php echo date('M j, Y g:i A', strtotime($notif['created_at'])); ?></small>
                        </div>
                        <div class="notification-type">New Message</div>
                        <div class="notification-message">
                            <?php echo htmlspecialchars($notif['notification']); ?>
                        </div>
                        <div class="notification-actions">
                            <a href="sem2Assignmentchat.php?id=<?php echo intval($notif['sender_id']); ?>">Reply</a>
                            <a href="sem2Assignmentview_profile.php?id=<?php echo intval($notif['sender_id']); ?>" class="view-profile">View Profile</a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="no-notifications">
                <h3>No notifications yet</h3>
                <p>When you receive messages from other users, they will appear here.</p>
                <p>Start conversations with other users to receive notifications!</p>
            </div>
        <?php endif; ?>

        <a href="sem2Profile.php" class="back-link">← Back to Profile</a>
    </div>
</body>
</html>