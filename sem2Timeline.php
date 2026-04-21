<?php
//sem2Timeline.php
session_start();
require_once 'sem2Assignmentdatabase.php';

if (!isset($_SESSION['userdetails_id'])) {
    header("Location: sem2Assignmentlog.php");
    exit();
}

$connection = dbConnect();

// Fetch timeline posts with images and videos using prepared statement
$statement = $connection->prepare(
    "SELECT content, picture, video, created_at FROM attachments 
    WHERE userdetails_id = ? 
    ORDER BY created_at DESC"
);
$statement->bind_param("i", $_SESSION['userdetails_id']);
$statement->execute();
$result = $statement->get_result();
$posts = $result->fetch_all(MYSQLI_ASSOC);
$statement->close();
$connection->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your Timeline</title>
	<link rel="icon" type="image/x-icon" href="SMP_logo-(1).ico">
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            padding: 20px;
        }

        .timeline-container {
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

        .post {
            padding: 15px;
            margin-bottom: 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            background-color: #f9f9f9;
        }

        .post p {
            margin: 0 0 10px 0;
            color: #333;
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

        .post small {
            color: #666;
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

        .no-posts {
            text-align: center;
            padding: 40px;
            color: #666;
        }
    </style>
</head>
<body>
    <div class="timeline-container">
        <h1>Your Timeline</h1>
        
        
        <?php if (!empty($posts)): ?>
            <?php foreach ($posts as $post): ?>
                <div class="post">
                    <p><?php echo htmlspecialchars($post['content']); ?></p>
                    
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
                    
                    <small><?php echo htmlspecialchars($post['created_at']); ?></small>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="no-posts">
                <p>No posts yet. Go back to your profile to create your first post!</p>
            </div>
        <?php endif; ?>
		<a href="sem2Profile.php" class="back-link">← Back to Profile</a>
    </div>
</body>
</html>