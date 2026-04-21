<?php
// sem2Assignmentfriends.php
session_start();
require_once 'sem2Assignmentdatabase.php';

if (!isset($_SESSION['userdetails_id'])) {
    header("Location: sem2Assignmentlog.php");
    exit();
}

$connection = dbConnect();
$current_user_id = $_SESSION['userdetails_id'];

// Incoming friend requests
$reqStmt = $connection->prepare("
    SELECT fr.id, fr.sender_id, u.name, u.profilePic, fr.created_at
    FROM friend_requests fr
    JOIN userdetails u ON u.id = fr.sender_id
    WHERE fr.receiver_id = ? AND fr.status = 'pending'
    ORDER BY fr.created_at DESC
");
$reqStmt->bind_param("i", $current_user_id);
$reqStmt->execute();
$pendingRequests = $reqStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$reqStmt->close();

// Friends list
$friendStmt = $connection->prepare("
    SELECT u.id, u.name, u.profilePic
    FROM friend_requests fr
    JOIN userdetails u ON u.id = CASE WHEN fr.sender_id=? THEN fr.receiver_id ELSE fr.sender_id END
    WHERE (fr.sender_id=? OR fr.receiver_id=?) AND fr.status='accepted'
    ORDER BY u.name ASC
");
$friendStmt->bind_param("iii", $current_user_id, $current_user_id, $current_user_id);
$friendStmt->execute();
$friends = $friendStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$friendStmt->close();

$connection->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Friends – SMP</title>
    <link rel="icon" type="image/x-icon" href="SMP_logo-(1).ico">
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --bg: #f0f2f5; --card: #ffffff; --blue: #1877f2;
            --green: #27ae60; --red: #e74c3c; --text: #1c1e21;
            --muted: #65676b; --border: #e0e3e7; --radius: 12px;
            --shadow: 0 2px 12px rgba(0,0,0,.08);
        }
        body { font-family: 'Sora', sans-serif; background: var(--bg); min-height: 100vh; padding: 24px 16px 48px; color: var(--text); }
        .page-wrap { max-width: 720px; margin: 0 auto; }
        h1 { font-size: 1.6rem; font-weight: 700; margin-bottom: 24px; }
        h2 { font-size: 1.1rem; font-weight: 700; margin-bottom: 14px; color: var(--muted); text-transform: uppercase; letter-spacing: .05em; font-size: .8rem; }
        .section { background: var(--card); border-radius: var(--radius); box-shadow: var(--shadow); padding: 20px; margin-bottom: 24px; }
        .card-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 14px; }
        .friend-card { background: #f7f8fa; border-radius: 10px; padding: 16px; text-align: center; border: 1px solid var(--border); }
        .friend-card img { width: 64px; height: 64px; border-radius: 50%; object-fit: cover; margin-bottom: 10px; border: 2px solid var(--border); }
        .friend-card .fname { font-weight: 700; font-size: .95rem; margin-bottom: 10px; }
        .card-btns { display: flex; gap: 6px; justify-content: center; flex-wrap: wrap; }
        .btn { display: inline-flex; align-items: center; gap: 5px; padding: 7px 14px; border-radius: 8px; font-family: inherit; font-size: .8rem; font-weight: 600; cursor: pointer; border: none; text-decoration: none; transition: opacity .15s; }
        .btn:hover { opacity: .82; }
        .btn-blue  { background: var(--blue); color: #fff; }
        .btn-gray  { background: #e4e6eb; color: var(--text); }
        .btn-green { background: var(--green); color: #fff; }
        .btn-red   { background: var(--red); color: #fff; }
        /* Request row */
        .req-item { display: flex; align-items: center; gap: 14px; padding: 14px 0; border-bottom: 1px solid var(--border); }
        .req-item:last-child { border-bottom: none; }
        .req-item img { width: 48px; height: 48px; border-radius: 50%; object-fit: cover; flex-shrink: 0; }
        .req-info { flex: 1; }
        .req-name { font-weight: 700; font-size: .95rem; }
        .req-time { font-size: .75rem; color: var(--muted); margin-top: 2px; }
        .req-actions { display: flex; gap: 8px; }
        .badge { background: var(--red); color: #fff; border-radius: 20px; padding: 2px 8px; font-size: .72rem; font-weight: 700; margin-left: 8px; }
        .empty { color: var(--muted); font-size: .9rem; padding: 16px 0; text-align: center; }
        .back-row { margin-top: 8px; }
    </style>
</head>
<body>
<div class="page-wrap">
    <h1>👥 Friends</h1>

    <!-- Pending requests -->
    <div class="section">
        <h2>Friend Requests <span class="badge"><?php echo count($pendingRequests); ?></span></h2>
        <?php if (empty($pendingRequests)): ?>
            <div class="empty">No pending friend requests</div>
        <?php else: ?>
            <?php foreach ($pendingRequests as $req):
                $ts = strtotime($req['created_at']);
                $diff = time() - $ts;
                if ($diff < 3600)       $tStr = floor($diff/60).'m ago';
                elseif ($diff < 86400)  $tStr = floor($diff/3600).'h ago';
                else                    $tStr = date('M j, Y', $ts);
            ?>
            <div class="req-item" id="req-<?php echo $req['id']; ?>">
                <img src="<?php echo getProfilePicPath($req['profilePic']); ?>" alt="">
                <div class="req-info">
                    <div class="req-name"><?php echo htmlspecialchars($req['name']); ?></div>
                    <div class="req-time"><?php echo $tStr; ?></div>
                </div>
                <div class="req-actions">
                    <button class="btn btn-green accept-btn" data-id="<?php echo $req['id']; ?>">✓ Accept</button>
                    <button class="btn btn-gray decline-btn" data-id="<?php echo $req['id']; ?>">✕ Decline</button>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Friends -->
    <div class="section">
        <h2>Your Friends (<?php echo count($friends); ?>)</h2>
        <?php if (empty($friends)): ?>
            <div class="empty">You have no friends yet. Search for users on your profile to add them!</div>
        <?php else: ?>
        <div class="card-grid">
            <?php foreach ($friends as $f): ?>
            <div class="friend-card">
                <img src="<?php echo getProfilePicPath($f['profilePic']); ?>" alt="">
                <div class="fname"><?php echo htmlspecialchars($f['name']); ?></div>
                <div class="card-btns">
                    <a href="sem2Assignmentview_profile.php?id=<?php echo $f['id']; ?>" class="btn btn-blue">Profile</a>
                    <a href="sem2Assignmentchat.php?id=<?php echo $f['id']; ?>" class="btn btn-gray">Chat</a>
                    <button class="btn btn-red unfriend-btn" data-uid="<?php echo $f['id']; ?>">Unfriend</button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="back-row">
        <a href="sem2Profile.php" class="btn btn-gray">← Back to Profile</a>
    </div>
</div>

<script>
document.querySelectorAll('.accept-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const reqId = this.dataset.id;
        const row = document.getElementById('req-' + reqId);
        const fd = new FormData();
        fd.append('action', 'accept');
        fd.append('request_id', reqId);
        fetch('sem2Assignmentfriend_request.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    row.innerHTML = `<div style="color:#27ae60;font-weight:600;padding:8px 0;">✓ Friend request accepted! Refresh to see them in your list.</div>`;
                }
            });
    });
});

document.querySelectorAll('.decline-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const reqId = this.dataset.id;
        const row = document.getElementById('req-' + reqId);
        const fd = new FormData();
        fd.append('action', 'decline');
        fd.append('request_id', reqId);
        fetch('sem2Assignmentfriend_request.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                if (res.success) row.remove();
            });
    });
});

document.querySelectorAll('.unfriend-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        if (!confirm('Remove this friend?')) return;
        const uid = this.dataset.uid;
        const fd = new FormData();
        fd.append('action', 'unfriend');
        fd.append('other_id', uid);
        fetch('sem2Assignmentfriend_request.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                if (res.success) this.closest('.friend-card').remove();
            });
    });
});
</script>
</body>
</html>