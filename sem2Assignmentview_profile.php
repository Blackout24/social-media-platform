<?php 
// sem2Assignmentview_profile.php
session_start();
require_once 'sem2Assignmentdatabase.php';

if (!isset($_SESSION['userdetails_id'])) {
    header("Location: sem2Assignmentlog.php");
    exit();
}

$connection = dbConnect();

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo "Error: Invalid User ID.";
    exit();
}

$view_userdetails_id = intval($_GET['id']);

if ($view_userdetails_id == $_SESSION['userdetails_id']) {
    header("Location: sem2Profile.php");
    exit();
}

$statement = $connection->prepare("SELECT name, profilePic FROM userdetails WHERE id = ?");
$statement->bind_param("i", $view_userdetails_id);
$statement->execute();
$result = $statement->get_result();
$profile_userdetails = $result->fetch_assoc();
$statement->close();

if (!$profile_userdetails) {
    echo "User details not found.";
    exit();
}

// Friend request status
$frq = $connection->prepare("SELECT id, sender_id, receiver_id, status FROM friend_requests WHERE (sender_id=? AND receiver_id=?) OR (sender_id=? AND receiver_id=?)");
$frq->bind_param("iiii", $_SESSION['userdetails_id'], $view_userdetails_id, $view_userdetails_id, $_SESSION['userdetails_id']);
$frq->execute();
$frRow = $frq->get_result()->fetch_assoc();
$frq->close();
$frStatus = $frRow ? $frRow['status'] : 'none';
$frIsSender = $frRow ? ($frRow['sender_id'] == $_SESSION['userdetails_id']) : false;
$frRequestId = $frRow ? $frRow['id'] : null;

// Fetch user's posts with like/comment counts for the viewing user
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
$statement->bind_param("ii", $_SESSION['userdetails_id'], $view_userdetails_id);
$statement->execute();
$userPosts = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
$statement->close();
$connection->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($profile_userdetails['name']); ?>'s Profile – SMP</title>
    <link rel="icon" type="image/x-icon" href="SMP_logo-(1).ico">
    <style>
        body { font-family: Arial, sans-serif; background-color: #f4f4f4; padding: 20px; }
        .profile-container { max-width: 800px; margin: 0 auto; background-color: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 4px 10px rgba(0,0,0,.1); }
        h1, h2 { color: #333; }
        .profile-header { display: flex; align-items: center; gap: 20px; margin-bottom: 20px; flex-wrap: wrap; }
        .profile-pic { width: 100px; height: 100px; border-radius: 50%; object-fit: cover; border: 3px solid #e0e0e0; }
        .profile-info h1 { margin: 0 0 10px 0; }
        .profile-actions { display: flex; gap: 10px; flex-wrap: wrap; }
        .btn { display: inline-flex; align-items: center; gap: 6px; padding: 9px 18px; border-radius: 6px; font-size: .88rem; font-weight: 600; cursor: pointer; border: none; text-decoration: none; transition: opacity .15s; }
        .btn:hover { opacity: .83; }
        .btn-blue  { background: #007bff; color: #fff; }
        .btn-green { background: #27ae60; color: #fff; }
        .btn-orange{ background: #f39c12; color: #fff; cursor: default; }
        .btn-gray  { background: #6c757d; color: #fff; }
        .btn-red   { background: #e74c3c; color: #fff; }

        .posts-section { margin-top: 24px; }
        .post { background-color: #f9f9f9; padding: 15px; margin-bottom: 20px; border-radius: 8px; border: 1px solid #ddd; }
        .post p { margin: 0 0 10px 0; color: #333; }
        .post-image { max-width: 100%; height: auto; border-radius: 5px; margin: 10px 0; }
        .post-video { max-width: 100%; max-height: 400px; border-radius: 5px; margin: 10px 0; background: #000; }
        .video-container { position: relative; width: 100%; max-width: 600px; margin: 10px 0; }
        .post small { color: #666; }
        .no-posts { text-align: center; padding: 40px; color: #666; font-style: italic; }
        .back-link { display: inline-block; margin-top: 20px; color: #007bff; text-decoration: none; }
        .back-link:hover { text-decoration: underline; }

        /* Like / Comment */
        .post-engagement { display: flex; gap: 8px; margin-top: 10px; padding-top: 8px; border-top: 1px solid #e9e9e9; align-items: center; }
        .like-btn { background: none; border: 1.5px solid #ccc; border-radius: 20px; padding: 5px 14px; font-size: 13px; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; color: #555; transition: all .15s; }
        .like-btn.liked { border-color: #e74c3c; background: #fff0f0; color: #e74c3c; }
        .like-btn:hover { border-color: #e74c3c; color: #e74c3c; background: #fff5f5; }
        .comment-toggle-btn { background: none; border: 1.5px solid #ccc; border-radius: 20px; padding: 5px 14px; font-size: 13px; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; color: #555; transition: all .15s; }
        .comment-toggle-btn:hover { border-color: #007bff; color: #007bff; background: #f0f7ff; }
        .comments-section { margin-top: 12px; display: none; }
        .comments-section.open { display: block; }
        .comment-item { display: flex; gap: 10px; align-items: flex-start; margin-bottom: 10px; }
        .comment-avatar { width: 32px; height: 32px; border-radius: 50%; object-fit: cover; flex-shrink: 0; }
        .comment-bubble { background: #f0f2f5; border-radius: 12px; padding: 8px 12px; flex: 1; }
        .comment-author { font-weight: 700; font-size: 13px; color: #333; margin-bottom: 2px; }
        .comment-text { font-size: 13px; color: #444; }
        .comment-time { font-size: 11px; color: #999; margin-top: 3px; }
        .comment-form { display: flex; gap: 8px; margin-top: 8px; }
        .comment-input { flex: 1; padding: 8px 12px; border: 1.5px solid #ddd; border-radius: 20px; font-size: 13px; outline: none; }
        .comment-input:focus { border-color: #007bff; }
        .comment-submit { background: #007bff; color: #fff; border: none; border-radius: 20px; padding: 8px 16px; cursor: pointer; font-size: 13px; }
        .comment-submit:hover { background: #0056b3; }
        .comment-delete { background: none; border: none; color: #ccc; cursor: pointer; font-size: 14px; padding: 0 4px; }
        .comment-delete:hover { color: #e74c3c; background: none; }
    </style>
</head>
<body>
<div class="profile-container">
    <div class="profile-header">
        <img src="<?php echo getProfilePicPath($profile_userdetails['profilePic']); ?>" alt="Profile Picture" class="profile-pic">
        <div class="profile-info">
            <h1><?php echo htmlspecialchars($profile_userdetails['name']); ?></h1>
            <div class="profile-actions">
                <a href="sem2Assignmentchat.php?id=<?php echo $view_userdetails_id; ?>" class="btn btn-blue">💬 Message</a>

                <?php if ($frStatus === 'accepted'): ?>
                    <button class="btn btn-gray" id="friendBtn" onclick="unfriend()">✓ Friends</button>
                <?php elseif ($frStatus === 'pending' && $frIsSender): ?>
                    <span class="btn btn-orange">⏳ Request Sent</span>
                <?php elseif ($frStatus === 'pending' && !$frIsSender): ?>
                    <button class="btn btn-green" id="friendBtn" onclick="acceptRequest()">✓ Accept Request</button>
                <?php else: ?>
                    <button class="btn btn-green" id="friendBtn" onclick="sendRequest()">➕ Add Friend</button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="posts-section">
        <h2><?php echo htmlspecialchars($profile_userdetails['name']); ?>'s Posts</h2>

        <?php if (!empty($userPosts)): ?>
            <?php foreach ($userPosts as $post): ?>
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

                <div class="post-engagement">
                    <button class="like-btn <?php echo $post['user_liked'] ? 'liked' : ''; ?>"
                            data-post-id="<?php echo $post['id']; ?>"
                            onclick="toggleLike(this, <?php echo $post['id']; ?>)">
                        <?php echo $post['user_liked'] ? '❤️' : '🤍'; ?>
                        <span class="like-count"><?php echo $post['like_count']; ?></span>
                    </button>
                    <button class="comment-toggle-btn" onclick="toggleComments(<?php echo $post['id']; ?>)">
                        💬 <span class="comment-count"><?php echo $post['comment_count']; ?></span>
                    </button>
                </div>

                <div class="comments-section" id="comments-<?php echo $post['id']; ?>">
                    <div class="comments-list" id="comments-list-<?php echo $post['id']; ?>"></div>
                    <div class="comment-form">
                        <input type="text" class="comment-input" placeholder="Write a comment…"
                               id="comment-input-<?php echo $post['id']; ?>"
                               onkeypress="if(event.key==='Enter'){submitComment(<?php echo $post['id']; ?>);}">
                        <button class="comment-submit" onclick="submitComment(<?php echo $post['id']; ?>)">Post</button>
                    </div>
                </div>

                <small><?php echo htmlspecialchars($post['created_at']); ?></small>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="no-posts"><p><?php echo htmlspecialchars($profile_userdetails['name']); ?> hasn't posted anything yet.</p></div>
        <?php endif; ?>
    </div>

    <a href="sem2Profile.php" class="back-link">← Back to Your Profile</a>
</div>

<script>
const OTHER_USER_ID = <?php echo $view_userdetails_id; ?>;
const CURRENT_USER_ID = <?php echo $_SESSION['userdetails_id']; ?>;
let currentFrRequestId = <?php echo $frRequestId ?? 'null'; ?>;

function sendRequest() {
    const fd = new FormData();
    fd.append('action', 'send');
    fd.append('to_id', OTHER_USER_ID);
    fetch('sem2Assignmentfriend_request.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                document.getElementById('friendBtn').outerHTML = '<span class="btn btn-orange">⏳ Request Sent</span>';
            } else { alert(res.message); }
        });
}

function acceptRequest() {
    fetch('sem2Assignmentfriend_request.php?action=status&other_id=' + OTHER_USER_ID)
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
                const btn = document.getElementById('friendBtn');
                if (btn) { btn.textContent = '✓ Friends'; btn.onclick = unfriend; btn.className = 'btn btn-gray'; }
            }
        });
}

function unfriend() {
    if (!confirm('Remove this friend?')) return;
    const fd = new FormData();
    fd.append('action', 'unfriend');
    fd.append('other_id', OTHER_USER_ID);
    fetch('sem2Assignmentfriend_request.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                const btn = document.getElementById('friendBtn');
                if (btn) { btn.textContent = '➕ Add Friend'; btn.onclick = sendRequest; btn.className = 'btn btn-green'; }
            }
        });
}

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
        .then(data => renderComments(postId, data.comments));
}
function renderComments(postId, comments) {
    const list = document.getElementById('comments-list-' + postId);
    if (!comments || !comments.length) {
        list.innerHTML = '<p style="color:#999;font-size:13px;padding:4px 0;">No comments yet. Be the first!</p>';
        return;
    }
    list.innerHTML = comments.map(c => {
        const isOwn = parseInt(c.user_id) === CURRENT_USER_ID;
        const ts = new Date(c.created_at);
        const timeStr = ts.toLocaleString('en-US', { month:'short', day:'numeric', hour:'numeric', minute:'2-digit', hour12:true });
        const picSrc = (c.profilePic && c.profilePic !== 'default_profile.png') ? 'uploads/' + c.profilePic : 'default_profile.png';
        return `<div class="comment-item" id="comment-item-${c.id}">
            <img src="${picSrc}" class="comment-avatar" alt="">
            <div class="comment-bubble">
                <div class="comment-author">${escapeHtml(c.name)}</div>
                <div class="comment-text">${escapeHtml(c.comment)}</div>
                <div class="comment-time">${timeStr}</div>
            </div>
            ${isOwn ? `<button class="comment-delete" onclick="deleteComment(${c.id}, ${postId})">×</button>` : ''}
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
                const btn = document.querySelector(`button.comment-toggle-btn[onclick="toggleComments(${postId})"]`);
                const cs = btn.querySelector('.comment-count');
                cs.textContent = parseInt(cs.textContent) + 1;
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
                const cs = btn.querySelector('.comment-count');
                cs.textContent = Math.max(0, parseInt(cs.textContent) - 1);
            }
        });
}
function escapeHtml(text) {
    const d = document.createElement('div');
    d.appendChild(document.createTextNode(text));
    return d.innerHTML;
}
</script>
</body>
</html>