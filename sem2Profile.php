<?php
//sem2Profile.php
session_start();
require_once 'sem2Assignmentdatabase.php';

if (!isset($_SESSION['userdetails_id'])) {
    header("Location: sem2Assignmentlog.php");
    exit();
}

$connection = dbConnect();

// Fetch user details FIRST
$statement = $connection->prepare("SELECT name, profilePic FROM userdetails WHERE id = ?");
$statement->bind_param("i", $_SESSION['userdetails_id']);
$statement->execute();
$statement->bind_result($name, $profilePic);
$statement->fetch();
$statement->close();

$profilePicPath = getProfilePicPath($profilePic);

// First login welcome
if (!isset($_SESSION['has_logged_in_before'])) {
    $welcomeMessage = "Welcome to SMP, " . htmlspecialchars($name) . "!";
    $_SESSION['has_logged_in_before'] = true;
    $_SESSION['user_first_login'] = true;
} else {
    $welcomeMessage = htmlspecialchars($name);
}

// Get notification count (unread only)
$notificationStatement = $connection->prepare(
    "SELECT COUNT(*) as notification_count FROM notifications WHERE receiver_id=? AND is_read=0"
);
$notificationStatement->bind_param("i", $_SESSION['userdetails_id']);
$notificationStatement->execute();
$notificationResult = $notificationStatement->get_result();
$notificationData = $notificationResult->fetch_assoc();
$notificationCount = $notificationData['notification_count'];
$notificationStatement->close();

// Handle profile picture upload
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_FILES['profilePic'])) {
    $uploadDir = "uploads/";
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    $uploadFile = $uploadDir . basename($_FILES["profilePic"]["name"]);
    $imageFileType = strtolower(pathinfo($uploadFile, PATHINFO_EXTENSION));
    $allowedExtensions = ["jpg", "png", "jpeg", "gif"];
    if (in_array($imageFileType, $allowedExtensions) && $_FILES["profilePic"]["size"] <= 5000000) {
        if (move_uploaded_file($_FILES["profilePic"]["tmp_name"], $uploadFile)) {
            $statement = $connection->prepare("UPDATE userdetails SET profilePic = ? WHERE id = ?");
            $filename = basename($uploadFile);
            $statement->bind_param("si", $filename, $_SESSION['userdetails_id']);
            $statement->execute();
            $statement->close();
            header("Location: sem2Profile.php");
            exit();
        } else {
            $uploadError = "Sorry, there was an error uploading your file.";
        }
    } else {
        $uploadError = "Invalid file. Please upload a JPG, JPEG, PNG or GIF file under 5MB.";
    }
}

// Handle new post creation
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['new_post'])) {
    $newPost = trim($_POST['new_post']);
    $postImage = null;
    $postVideo = null;
    if (isset($_FILES['post_image']) && $_FILES['post_image']['error'] == 0) {
        $uploadDir = "uploads/";
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        $imageName = uniqid() . '_' . basename($_FILES["post_image"]["name"]);
        $uploadFile = $uploadDir . $imageName;
        $imageFileType = strtolower(pathinfo($uploadFile, PATHINFO_EXTENSION));
        $allowedImageExtensions = ["jpg", "png", "jpeg", "gif"];
        if (in_array($imageFileType, $allowedImageExtensions) && $_FILES["post_image"]["size"] <= 5000000) {
            if (move_uploaded_file($_FILES["post_image"]["tmp_name"], $uploadFile)) {
                $postImage = $imageName;
            } else { $postError = "Error uploading post image."; }
        } else { $postError = "Invalid image. Please upload a JPG, JPEG, PNG or GIF file under 5MB."; }
    }
    if (isset($_FILES['post_video']) && $_FILES['post_video']['error'] == 0) {
        $uploadDir = "uploads/";
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        $videoName = uniqid() . '_' . basename($_FILES["post_video"]["name"]);
        $uploadFile = $uploadDir . $videoName;
        $videoFileType = strtolower(pathinfo($uploadFile, PATHINFO_EXTENSION));
        $allowedVideoExtensions = ["mp4", "mov", "avi", "wmv", "flv", "webm"];
        if (in_array($videoFileType, $allowedVideoExtensions) && $_FILES["post_video"]["size"] <= 50000000) {
            if (move_uploaded_file($_FILES["post_video"]["tmp_name"], $uploadFile)) {
                $postVideo = $videoName;
            } else { $postError = "Error uploading post video."; }
        } else { $postError = "Invalid video. Please upload MP4, MOV, AVI, WMV, FLV, or WebM files under 50MB."; }
    }
    if (!empty($newPost) && !isset($postError)) {
        $statement = $connection->prepare("INSERT INTO attachments (userdetails_id, content, picture, video) VALUES (?, ?, ?, ?)");
        $statement->bind_param("isss", $_SESSION['userdetails_id'], $newPost, $postImage, $postVideo);
        $statement->execute();
        $statement->close();
        header("Location: sem2Profile.php");
        exit();
    }
}

// Handle user search
$searchResults = [];
if ($_SERVER["REQUEST_METHOD"] == "GET" && isset($_GET['query'])) {
    $query = "%{$_GET['query']}%";
    $statement = $connection->prepare("SELECT id, name, email, profilePic FROM userdetails WHERE name LIKE ? AND id != ?");
    $statement->bind_param("si", $query, $_SESSION['userdetails_id']);
    $statement->execute();
    $result = $statement->get_result();
    while ($row = $result->fetch_assoc()) {
        $searchResults[] = $row;
    }
    $statement->close();
}

// Fetch user posts with like & comment counts
$statement = $connection->prepare("
    SELECT a.id, a.content, a.picture, a.video, a.created_at,
           COUNT(DISTINCT pl.id) AS like_count,
           COUNT(DISTINCT pc.id) AS comment_count,
           MAX(CASE WHEN pl.user_id = ? THEN 1 ELSE 0 END) AS user_liked
    FROM attachments a
    LEFT JOIN post_likes pl ON pl.post_id = a.id
    LEFT JOIN post_comments pc ON pc.post_id = a.id
    WHERE a.userdetails_id = ?
    GROUP BY a.id
    ORDER BY a.created_at DESC
");
$statement->bind_param("ii", $_SESSION['userdetails_id'], $_SESSION['userdetails_id']);
$statement->execute();
$posts = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
$statement->close();

// Build friend request status map for search results
$frStatusMap = [];
if (!empty($searchResults)) {
    foreach ($searchResults as $user) {
        $uid = $user['id'];
        $frq = $connection->prepare("SELECT id, sender_id, receiver_id, status FROM friend_requests WHERE (sender_id=? AND receiver_id=?) OR (sender_id=? AND receiver_id=?)");
        $frq->bind_param("iiii", $_SESSION['userdetails_id'], $uid, $uid, $_SESSION['userdetails_id']);
        $frq->execute();
        $frRow = $frq->get_result()->fetch_assoc();
        $frq->close();
        $frStatusMap[$uid] = $frRow;
    }
}

$connection->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMP - <?php echo htmlspecialchars($name); ?>'s Profile</title>
    <link rel="icon" type="image/x-icon" href="SMP_logo-(1).ico">
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            padding: 20px;
        }
        .profile-container {
            background-color: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 4px 10px rgba(0,0,0,.1);
            width: 100%;
            max-width: 800px;
            text-align: center;
        }
        h1, h2 { margin-bottom: 20px; color: #333; }
        .profile-pic { width:150px; height:150px; border-radius:50%; margin-bottom:20px; object-fit:cover; }
        .upload-form { margin-bottom: 20px; }
        .new-post-form textarea { width:100%; padding:10px; margin:10px 0; border:1px solid #ccc; border-radius:5px; box-sizing:border-box; min-height:100px; }
        .file-input-wrapper { margin:10px 0; text-align:left; }
        .file-input-wrapper label { display:block; margin-bottom:5px; color:#666; font-size:14px; }
        .file-input-wrapper input[type="file"] { width:100%; padding:8px; border:1px solid #ccc; border-radius:5px; box-sizing:border-box; }
        .file-input-wrapper small { display:block; color:#666; font-size:12px; margin-top:5px; font-style:italic; }
        button { width:100%; padding:10px; background-color:#007bff; border:none; color:#fff; border-radius:5px; cursor:pointer; }
        button:hover { background-color:#0056b3; }
        .search-form input { width:80%; padding:10px; margin:10px 0; border:1px solid #ccc; border-radius:5px; }
        .search-results-container { display:grid; grid-template-columns:repeat(auto-fill,minmax(250px,1fr)); gap:20px; margin:20px 0; }
        .user-card { background-color:#f9f9f9; border:1px solid #ddd; border-radius:8px; padding:20px; text-align:center; box-shadow:0 2px 4px rgba(0,0,0,.1); }
        .user-card-pic { width:80px; height:80px; border-radius:50%; object-fit:cover; margin:0 auto 15px; }
        .user-card-name { font-weight:bold; color:#333; margin-bottom:10px; font-size:18px; }
        .user-card-email { color:#666; font-size:14px; margin-bottom:15px; }
        .user-card-actions { display:flex; gap:8px; justify-content:center; flex-wrap:wrap; }
        .btn-message { background-color:#28a745; color:white; padding:8px 15px; text-decoration:none; border-radius:5px; font-size:14px; border:none; cursor:pointer; display:inline-block; }
        .btn-message:hover { background-color:#218838; }
        .btn-profile { background-color:#007bff; color:white; padding:8px 15px; text-decoration:none; border-radius:5px; font-size:14px; display:inline-block; }
        .btn-profile:hover { background-color:#0056b3; }
        .btn-addfriend { background-color:#27ae60; color:white; padding:8px 15px; border-radius:5px; font-size:14px; border:none; cursor:pointer; display:inline-block; }
        .btn-addfriend:hover { background-color:#219a52; }
        .btn-pending  { background-color:#f39c12; color:white; padding:8px 15px; border-radius:5px; font-size:14px; border:none; cursor:default; display:inline-block; }
        .btn-friends  { background-color:#6c757d; color:white; padding:8px 15px; border-radius:5px; font-size:14px; border:none; cursor:pointer; display:inline-block; }
        .view-messages-btn  { display:inline-block; margin:20px 5px; padding:12px 25px; background-color:#6f42c1; color:white; text-decoration:none; border-radius:5px; font-weight:bold; }
        .view-messages-btn:hover { background-color:#5a2d91; }
        .view-notifications-btn { display:inline-block; margin:20px 5px; padding:12px 25px; background-color:#ffc107; color:#212529; text-decoration:none; border-radius:5px; font-weight:bold; }
        .view-notifications-btn:hover { background-color:#e0a800; }
        .notification-badge { background-color:#dc3545; color:white; border-radius:50%; padding:2px 6px; font-size:12px; margin-left:5px; }
        .view-timeline-btn { display:inline-block; margin:20px 5px; padding:12px 25px; background-color:#17a2b8; color:white; text-decoration:none; border-radius:5px; font-weight:bold; }
        .view-timeline-btn:hover { background-color:#138496; }
        .view-friends-btn { display:inline-block; margin:20px 5px; padding:12px 25px; background-color:#27ae60; color:white; text-decoration:none; border-radius:5px; font-weight:bold; }
        .view-friends-btn:hover { background-color:#219a52; }
        .button-container { display:flex; justify-content:center; gap:10px; margin:20px 0; flex-wrap:wrap; }

        /* Posts */
        .post { background-color:#f9f9f9; padding:15px; margin-bottom:20px; border-radius:8px; text-align:left; border:1px solid #e0e0e0; }
        .post p { margin:0 0 10px 0; }
        .post-image { max-width:100%; height:auto; border-radius:5px; margin:10px 0; }
        .post-video { max-width:100%; max-height:400px; border-radius:5px; margin:10px 0; background:#000; }
        .video-container { position:relative; width:100%; max-width:600px; margin:10px 0; }
        .post small { color:#666; font-size:12px; }
        .post-actions { display:flex; justify-content:space-between; align-items:center; margin-top:10px; padding-top:10px; border-top:1px solid #e0e0e0; }
        .post-buttons { display:flex; gap:8px; }
        .post-meta { display:flex; align-items:center; gap:8px; }
        .copy-success { color:#28a745; font-size:10px; margin-left:8px; display:none; }
        .share-post-btn  { background-color:#6f42c1; color:white; border:none; border-radius:4px; padding:5px 10px; cursor:pointer; font-size:12px; width:auto; }
        .share-post-btn:hover { background-color:#5a2d91; }
        .delete-post-btn { background-color:#dc3545; color:white; border:none; border-radius:4px; padding:5px 10px; cursor:pointer; font-size:12px; width:auto; }
        .delete-post-btn:hover { background-color:#c82333; }

        /* Like / Comment bar */
        .post-engagement { display:flex; gap:8px; margin-top:10px; padding-top:8px; border-top:1px solid #e9e9e9; align-items:center; }
        .like-btn {
            background:none; border:1.5px solid #ccc; border-radius:20px; padding:5px 14px;
            font-size:13px; cursor:pointer; display:inline-flex; align-items:center; gap:5px;
            width:auto; color:#555; transition: all .15s;
        }
        .like-btn.liked { border-color:#e74c3c; background:#fff0f0; color:#e74c3c; }
        .like-btn:hover  { border-color:#e74c3c; color:#e74c3c; background:#fff5f5; }
        .comment-toggle-btn {
            background:none; border:1.5px solid #ccc; border-radius:20px; padding:5px 14px;
            font-size:13px; cursor:pointer; display:inline-flex; align-items:center; gap:5px;
            width:auto; color:#555; transition: all .15s;
        }
        .comment-toggle-btn:hover { border-color:#007bff; color:#007bff; background:#f0f7ff; }

        /* Comments section */
        .comments-section { margin-top:12px; display:none; }
        .comments-section.open { display:block; }
        .comment-item { display:flex; gap:10px; align-items:flex-start; margin-bottom:10px; }
        .comment-avatar { width:32px; height:32px; border-radius:50%; object-fit:cover; flex-shrink:0; }
        .comment-bubble { background:#f0f2f5; border-radius:12px; padding:8px 12px; flex:1; }
        .comment-author { font-weight:700; font-size:13px; color:#333; margin-bottom:2px; }
        .comment-text   { font-size:13px; color:#444; }
        .comment-time   { font-size:11px; color:#999; margin-top:3px; }
        .comment-delete { background:none; border:none; color:#ccc; cursor:pointer; font-size:14px; padding:0 4px; width:auto; }
        .comment-delete:hover { color:#e74c3c; background:none; }
        .comment-form { display:flex; gap:8px; margin-top:8px; }
        .comment-input { flex:1; padding:8px 12px; border:1.5px solid #ddd; border-radius:20px; font-size:13px; outline:none; }
        .comment-input:focus { border-color:#007bff; }
        .comment-submit { background:#007bff; color:#fff; border:none; border-radius:20px; padding:8px 16px; cursor:pointer; font-size:13px; width:auto; }
        .comment-submit:hover { background:#0056b3; }

        .error   { color:red; margin:10px 0; padding:10px; background-color:#f8d7da; border:1px solid #f5c6cb; border-radius:5px; }
        .success { color:green; margin:10px 0; padding:10px; background-color:#d4edda; border:1px solid #c3e6cb; border-radius:5px; }
        .no-results { text-align:center; padding:40px; color:#666; font-style:italic; }
    </style>
</head>
<body>
<div class="profile-container">
    <div style="background-color:#f8f9fa;padding:10px;border-radius:5px;margin-bottom:15px;text-align:right;">
        <span style="color:#666;font-size:14px;">Logged in as: <strong><?php echo htmlspecialchars($name); ?></strong></span>
        <a href="sem2Assignmentlog.php" style="margin-left:10px;color:#007bff;text-decoration:none;font-size:14px;">Switch Account</a>
    </div>

    <h1><?php echo $welcomeMessage; ?></h1>
    <img src="<?php echo $profilePicPath; ?>" alt="Profile Picture" class="profile-pic">

    <h2>Upload Profile Picture</h2>
    <form method="POST" enctype="multipart/form-data" class="upload-form">
        <input type="file" name="profilePic" accept="image/*" required>
        <button type="submit">Upload</button>
    </form>
    <?php if (isset($uploadError)): ?><p class="error"><?php echo htmlspecialchars($uploadError); ?></p><?php endif; ?>

    <h2>Create a New Post</h2>
    <form method="POST" enctype="multipart/form-data" class="new-post-form">
        <textarea name="new_post" placeholder="What's on your mind?" required></textarea>
        <div class="file-input-wrapper">
            <label for="post_image">Add an image (optional):</label>
            <input type="file" name="post_image" id="post_image" accept="image/*">
        </div>
        <div class="file-input-wrapper">
            <label for="post_video">Add a video (optional):</label>
            <input type="file" name="post_video" id="post_video" accept="video/*">
            <small>Supported formats: MP4, MOV, AVI, WMV, FLV, WebM (max 50MB)</small>
        </div>
        <button type="submit">Post</button>
    </form>
    <?php if (isset($postError)): ?><p class="error"><?php echo htmlspecialchars($postError); ?></p><?php endif; ?>

    <?php if (isset($_SESSION['delete_success'])): ?>
        <div class="success"><?php echo htmlspecialchars($_SESSION['delete_success']); unset($_SESSION['delete_success']); ?></div>
    <?php endif; ?>
    <?php if (isset($_SESSION['delete_error'])): ?>
        <div class="error"><?php echo htmlspecialchars($_SESSION['delete_error']); unset($_SESSION['delete_error']); ?></div>
    <?php endif; ?>

    <!-- Action Buttons -->
    <div class="button-container">
        <a href="sem2Assignmentmessages.php" class="view-messages-btn">📧 View Chats</a>
        <a href="sem2Assignmentnotifications.php" class="view-notifications-btn">
            🔔 Notifications
            <?php if ($notificationCount > 0): ?><span class="notification-badge"><?php echo $notificationCount; ?></span><?php endif; ?>
        </a>
        <a href="sem2Timeline.php" class="view-timeline-btn">📱 Timeline</a>
        <a href="sem2Assignmentfriends.php" class="view-friends-btn">👥 Friends</a>
    </div>

    <h2>Search for Other Users</h2>
    <form method="GET" class="search-form">
        <input type="text" name="query" placeholder="Enter username" required>
        <button type="submit">Search</button>
    </form>

    <?php if (!empty($searchResults)): ?>
        <h2>Search Results:</h2>
        <div class="search-results-container">
            <?php foreach ($searchResults as $user):
                $uid = $user['id'];
                $frRow = $frStatusMap[$uid] ?? null;
                $frStatus = $frRow ? $frRow['status'] : 'none';
                $isSender = $frRow ? ($frRow['sender_id'] == $_SESSION['userdetails_id']) : false;
            ?>
            <div class="user-card">
                <img src="<?php echo getProfilePicPath($user['profilePic']); ?>" alt="Profile Picture" class="user-card-pic">
                <div class="user-card-name"><?php echo htmlspecialchars($user['name']); ?></div>
                <div class="user-card-email"><?php echo htmlspecialchars($user['email']); ?></div>
                <div class="user-card-actions">
                    <a href="sem2Assignmentchat.php?id=<?php echo $uid; ?>" class="btn-message">💬 Message</a>
                    <a href="sem2Assignmentview_profile.php?id=<?php echo $uid; ?>" class="btn-profile">👤 Profile</a>
                    <?php if ($frStatus === 'accepted'): ?>
                        <button class="btn-friends fr-unfriend-btn" data-uid="<?php echo $uid; ?>" title="Click to unfriend">✓ Friends</button>
                    <?php elseif ($frStatus === 'pending' && $isSender): ?>
                        <span class="btn-pending">⏳ Pending</span>
                    <?php elseif ($frStatus === 'pending' && !$isSender): ?>
                        <!-- They sent us a request – show accept -->
                        <button class="btn-addfriend fr-accept-inline-btn" data-uid="<?php echo $uid; ?>">✓ Accept</button>
                    <?php else: ?>
                        <button class="btn-addfriend fr-add-btn" data-uid="<?php echo $uid; ?>">➕ Add Friend</button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php elseif ($_SERVER["REQUEST_METHOD"] == "GET" && isset($_GET['query'])): ?>
        <div class="no-results"><p>No users found matching "<?php echo htmlspecialchars($_GET['query']); ?>"</p></div>
    <?php endif; ?>

    <h2>Your Posts</h2>
    <?php if (!empty($posts)): ?>
        <?php foreach ($posts as $post): ?>
        <div class="post" id="post-<?php echo $post['id']; ?>">
            <p><?php echo htmlspecialchars($post['content']); ?></p>

            <?php if (!empty($post['picture'])): ?>
                <img src="uploads/<?php echo htmlspecialchars($post['picture']); ?>" alt="Post image" class="post-image">
            <?php endif; ?>

            <?php if (!empty($post['video'])): ?>
                <div class="video-container">
                    <video controls class="post-video">
                        <source src="uploads/<?php echo htmlspecialchars($post['video']); ?>" type="video/mp4">
                        Your browser does not support the video tag.
                    </video>
                </div>
            <?php endif; ?>

            <!-- Like & Comment bar -->
            <div class="post-engagement">
                <button class="like-btn <?php echo $post['user_liked'] ? 'liked' : ''; ?>"
                        data-post-id="<?php echo $post['id']; ?>"
                        onclick="toggleLike(this, <?php echo $post['id']; ?>)">
                    <?php echo $post['user_liked'] ? '❤️' : '🤍'; ?>
                    <span class="like-count"><?php echo $post['like_count']; ?></span>
                </button>
                <button class="comment-toggle-btn"
                        onclick="toggleComments(<?php echo $post['id']; ?>)">
                    💬 <span class="comment-count"><?php echo $post['comment_count']; ?></span>
                </button>
            </div>

            <!-- Comments section -->
            <div class="comments-section" id="comments-<?php echo $post['id']; ?>">
                <div class="comments-list" id="comments-list-<?php echo $post['id']; ?>">
                    <!-- loaded via JS -->
                </div>
                <div class="comment-form">
                    <input type="text" class="comment-input" placeholder="Write a comment…"
                           id="comment-input-<?php echo $post['id']; ?>"
                           onkeypress="if(event.key==='Enter'){submitComment(<?php echo $post['id']; ?>);}">
                    <button class="comment-submit" onclick="submitComment(<?php echo $post['id']; ?>)">Post</button>
                </div>
            </div>

            <div class="post-actions">
                <div class="post-meta">
                    <small><?php echo htmlspecialchars($post['created_at']); ?></small>
                    <span class="copy-success" id="copy-success-<?php echo $post['id']; ?>">✓ Copied!</span>
                </div>
                <div class="post-buttons">
                    <button onclick="sharePost(<?php echo $post['id']; ?>)" class="share-post-btn" title="Share this post">📤 Share</button>
                    <form method="POST" action="sem2Assignmentdelete_post.php" style="display:inline;" onsubmit="return confirm('Delete this post?');">
                        <input type="hidden" name="post_id" value="<?php echo $post['id']; ?>">
                        <button type="submit" class="delete-post-btn">🗑️ Delete</button>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    <?php else: ?>
        <p>No posts yet. Create your first post above!</p>
    <?php endif; ?>

    <form method="POST" action="sem2Assignmentlogout.php" style="margin-top:20px;">
        <button type="submit" style="background-color:#dc3545;">Logout</button>
    </form>
    <div style="margin-top:10px;">
        <a href="indexA.php" style="color:#007bff;text-decoration:none;font-size:14px;">← Back to Home</a>
    </div>
</div>

<script>
// ── Friend Request Buttons ────────────────────────────────────────────────────
document.querySelectorAll('.fr-add-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const uid = this.dataset.uid;
        const fd = new FormData();
        fd.append('action', 'send');
        fd.append('to_id', uid);
        fetch('sem2Assignmentfriend_request.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    this.textContent = '⏳ Pending';
                    this.classList.remove('btn-addfriend');
                    this.classList.add('btn-pending');
                    this.disabled = true;
                } else {
                    alert(res.message);
                }
            });
    });
});

document.querySelectorAll('.fr-unfriend-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        if (!confirm('Remove this friend?')) return;
        const uid = this.dataset.uid;
        const fd = new FormData();
        fd.append('action', 'unfriend');
        fd.append('other_id', uid);
        fetch('sem2Assignmentfriend_request.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    this.textContent = '➕ Add Friend';
                    this.classList.remove('btn-friends');
                    this.classList.add('btn-addfriend');
                }
            });
    });
});

document.querySelectorAll('.fr-accept-inline-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const uid = this.dataset.uid;
        fetch('sem2Assignmentfriend_request.php?action=status&other_id=' + uid)
            .then(r => r.json())
            .then(data => {
                if (data.request_id) {
                    const fd = new FormData();
                    fd.append('action', 'accept');
                    fd.append('request_id', data.request_id);
                    return fetch('sem2Assignmentfriend_request.php', { method: 'POST', body: fd });
                }
            })
            .then(r => r && r.json())
            .then(res => {
                if (res && res.success) {
                    this.textContent = '✓ Friends';
                    this.classList.remove('btn-addfriend');
                    this.classList.add('btn-friends');
                }
            });
    });
});

// ── Likes ─────────────────────────────────────────────────────────────────────
function toggleLike(btn, postId) {
    const fd = new FormData();
    fd.append('action', 'toggle_like');
    fd.append('post_id', postId);
    fetch('sem2Assignmentlike_comment.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                btn.classList.toggle('liked', data.liked);
                btn.querySelector('.like-count').textContent = data.count;
                btn.childNodes[0].textContent = data.liked ? '❤️' : '🤍';
            }
        });
}

// ── Comments ──────────────────────────────────────────────────────────────────
function toggleComments(postId) {
    const section = document.getElementById('comments-' + postId);
    const isOpen = section.classList.toggle('open');
    if (isOpen) loadComments(postId);
}

function loadComments(postId) {
    fetch('sem2Assignmentlike_comment.php?action=get_comments&post_id=' + postId)
        .then(r => r.json())
        .then(data => {
            renderComments(postId, data.comments);
        });
}

function renderComments(postId, comments) {
    const list = document.getElementById('comments-list-' + postId);
    if (!comments || comments.length === 0) {
        list.innerHTML = '<p style="color:#999;font-size:13px;padding:4px 0;">No comments yet. Be the first!</p>';
        return;
    }
    const currentUserId = <?php echo $_SESSION['userdetails_id']; ?>;
    list.innerHTML = comments.map(c => {
        const isOwn = parseInt(c.user_id) === currentUserId;
        const ts = new Date(c.created_at);
        const timeStr = ts.toLocaleString('en-US', { month:'short', day:'numeric', hour:'numeric', minute:'2-digit', hour12:true });
        return `<div class="comment-item" id="comment-item-${c.id}">
            <img src="${c.profilePic && c.profilePic !== 'default_profile.png' ? 'uploads/' + c.profilePic : 'default_profile.png'}" class="comment-avatar" alt="">
            <div class="comment-bubble">
                <div class="comment-author">${escapeHtml(c.name)}</div>
                <div class="comment-text">${escapeHtml(c.comment)}</div>
                <div class="comment-time">${timeStr}</div>
            </div>
            ${isOwn ? `<button class="comment-delete" onclick="deleteComment(${c.id}, ${postId})" title="Delete">×</button>` : ''}
        </div>`;
    }).join('');
}

function submitComment(postId) {
    const input = document.getElementById('comment-input-' + postId);
    const text = input.value.trim();
    if (!text) return;
    const fd = new FormData();
    fd.append('action', 'add_comment');
    fd.append('post_id', postId);
    fd.append('comment', text);
    fetch('sem2Assignmentlike_comment.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                input.value = '';
                // Update comment count
                const btn = document.querySelector(`button.comment-toggle-btn[onclick="toggleComments(${postId})"]`);
                const countSpan = btn.querySelector('.comment-count');
                countSpan.textContent = parseInt(countSpan.textContent) + 1;
                loadComments(postId);
            }
        });
}

function deleteComment(commentId, postId) {
    if (!confirm('Delete this comment?')) return;
    const fd = new FormData();
    fd.append('action', 'delete_comment');
    fd.append('comment_id', commentId);
    fetch('sem2Assignmentlike_comment.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                document.getElementById('comment-item-' + commentId)?.remove();
                const btn = document.querySelector(`button.comment-toggle-btn[onclick="toggleComments(${postId})"]`);
                const countSpan = btn.querySelector('.comment-count');
                countSpan.textContent = Math.max(0, parseInt(countSpan.textContent) - 1);
            }
        });
}

function escapeHtml(text) {
    const d = document.createElement('div');
    d.appendChild(document.createTextNode(text));
    return d.innerHTML;
}

// ── Share ─────────────────────────────────────────────────────────────────────
function sharePost(postId) {
    const shareUrl = window.location.origin + window.location.pathname.replace('sem2Profile.php', '') + 'sem2Assignmentview_post.php?id=' + postId;
    navigator.clipboard.writeText(shareUrl).then(function() {
        const el = document.getElementById('copy-success-' + postId);
        el.style.display = 'inline';
        setTimeout(() => el.style.display = 'none', 3000);
        alert('Post link copied to clipboard!');
    }, function() {
        prompt('Copy this link to share:', shareUrl);
    });
}
</script>
</body>
</html>