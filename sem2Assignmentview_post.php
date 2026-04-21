<?php
// sem2Assignmentview_post.php
session_start();
require_once 'sem2Assignmentdatabase.php';

if (!isset($_SESSION['userdetails_id'])) {
    header("Location: sem2Assignmentlog.php");
    exit();
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo "Invalid post ID.";
    exit();
}

$post_id = intval($_GET['id']);
$connection = dbConnect();

// Fetch post details
$statement = $connection->prepare("
    SELECT a.content, a.picture, a.video, a.created_at, u.name, u.profilePic, u.id as user_id 
    FROM attachments a 
    JOIN userdetails u ON a.userdetails_id = u.id 
    WHERE a.id = ?
");
$statement->bind_param("i", $post_id);
$statement->execute();
$result = $statement->get_result();
$post = $result->fetch_assoc();
$statement->close();

// Get the chat ID from the URL if it was passed when sharing
$chat_user_id = null;
if (isset($_GET['chat_id']) && is_numeric($_GET['chat_id'])) {
    $chat_user_id = intval($_GET['chat_id']);
    // Store in session for future reference
    $_SESSION['last_chat_user_id'] = $chat_user_id;
} elseif (isset($_SESSION['last_chat_user_id'])) {
    $chat_user_id = $_SESSION['last_chat_user_id'];
}

// Check HTTP referrer to see if we came from a chat
if (!$chat_user_id && isset($_SERVER['HTTP_REFERER'])) {
    $referrer = $_SERVER['HTTP_REFERER'];
    if (preg_match('/sem2Assignmentchat\.php\?id=(\d+)/', $referrer, $matches)) {
        $chat_user_id = intval($matches[1]);
        $_SESSION['last_chat_user_id'] = $chat_user_id;
    }
}

$connection->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shared Post - SMP</title>
	<link rel="icon" type="image/x-icon" href="SMP_logo-(1).ico">
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            padding: 20px;
        }

        .post-container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }

        .post-header {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
        }

        .post-header img {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            object-fit: cover;
            margin-right: 15px;
        }

        .post-content {
            margin-bottom: 15px;
            line-height: 1.6;
        }

        .post-image {
            max-width: 100%;
            height: auto;
            border-radius: 5px;
            margin: 10px 0;
        }

        .post-video {
            max-width: 100%;
            max-height: 400px;
            border-radius: 5px;
            margin: 10px 0;
            background: #000;
        }

        .video-container {
            position: relative;
            width: 100%;
            max-width: 600px;
            margin: 10px 0;
        }

        .post-meta {
            color: #666;
            font-size: 14px;
            border-top: 1px solid #eee;
            padding-top: 10px;
            margin-top: 15px;
        }

        .back-link {
            display: inline-block;
            margin-top: 20px;
            padding: 10px 20px;
            background-color: #007bff;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            font-size: 14px;
        }

        .back-link:hover {
            background-color: #0056b3;
        }
    </style>
</head>
<body>
    <div class="post-container">
        <div class="post-header">
            <img src="<?php echo getProfilePicPath($post['profilePic']); ?>" alt="Profile Picture">
            <div>
                <h3 style="margin: 0;"><?php echo htmlspecialchars($post['name']); ?></h3>
                <small style="color: #666;">Shared Post</small>
            </div>
        </div>

        <div class="post-content">
            <p><?php echo nl2br(htmlspecialchars($post['content'])); ?></p>
            
            <?php if (!empty($post['picture'])): ?>
                <img src="uploads/<?php echo htmlspecialchars($post['picture']); ?>" 
                     alt="Post image" class="post-image">
            <?php endif; ?>
            
            <?php if (!empty($post['video'])): ?>
                <div class="video-container">
                    <video controls class="post-video">
                        <source src="uploads/<?php echo htmlspecialchars($post['video']); ?>" type="video/mp4">
                        Your browser does not support the video tag.
                    </video>
                </div>
            <?php endif; ?>
        </div>

        <div class="post-meta">
            Posted on: <?php echo htmlspecialchars($post['created_at']); ?>
        </div>

        <?php if ($chat_user_id): ?>
            <!-- Go back to the specific chat we came from -->
            <a href="sem2Assignmentchat.php?id=<?php echo $chat_user_id; ?>" class="back-link">
                ← Back to Chat
            </a>
        <?php else: ?>
            <!-- If no chat context, just show a simple back button -->
            <a href="sem2Profile.php" class="back-link">
                ← Back to Profile
            </a>
        <?php endif; ?>
    </div>

    <script>
        // Keyboard shortcut: Escape key to go back
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                <?php if ($chat_user_id): ?>
                    window.location.href = 'sem2Assignmentchat.php?id=<?php echo $chat_user_id; ?>';
                <?php else: ?>
                    window.location.href = 'sem2Profile.php';
                <?php endif; ?>
            }
        });
    </script>
</body>
</html>