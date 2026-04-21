<?php
// sem2Assignmentadmin_panel.php
session_start();
require_once 'sem2Assignmentdatabase.php';

// ─── Admin Authentication ────────────────────────────────────────────────────
define('ADMIN_USERNAME', 'Rahul_admin');
define('ADMIN_PASSWORD', '@DM1N');

$admin_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_login'])) {
    $inputUser = $_POST['admin_user'] ?? '';
    $inputPass = $_POST['admin_pass'] ?? '';
    if ($inputUser === ADMIN_USERNAME && $inputPass === ADMIN_PASSWORD) {
        $_SESSION['is_admin'] = true;
    } else {
        $admin_error = 'Invalid admin credentials.';
    }
}

if (isset($_GET['admin_logout'])) {
    unset($_SESSION['is_admin']);
    header('Location: sem2Assignmentadmin_panel.php');
    exit();
}

$isAdmin = isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true;

// ─── Admin Actions ───────────────────────────────────────────────────────────
$actionMsg  = '';
$actionType = '';

if ($isAdmin) {
    $connection = dbConnect();

    // Ensure new columns/tables exist (safe on first run)
    $connection->query("ALTER TABLE attachments ADD COLUMN IF NOT EXISTS is_flagged TINYINT(1) DEFAULT 0");
    $connection->query("ALTER TABLE notifications ADD COLUMN IF NOT EXISTS type    VARCHAR(50) DEFAULT 'message'");
    $connection->query("ALTER TABLE notifications ADD COLUMN IF NOT EXISTS ref_id  INT DEFAULT NULL");
    $connection->query("ALTER TABLE notifications ADD COLUMN IF NOT EXISTS is_read TINYINT(1) DEFAULT 0");

    // Delete user
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user'])) {
        $uid  = intval($_POST['user_id']);
        $stmt = $connection->prepare("DELETE FROM userdetails WHERE id = ?");
        $stmt->bind_param("i", $uid);
        $actionMsg  = $stmt->execute() ? "User account deleted successfully." : "Error: " . $connection->error;
        $actionType = $stmt->execute() ? 'success' : 'error'; // note: execute already ran
        $actionMsg  = "User account deleted successfully.";
        $actionType = 'success';
        $stmt->close();
    }

    // Delete post (admin)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_delete_post'])) {
        $pid   = intval($_POST['post_id']);
        $fstmt = $connection->prepare("SELECT picture, video FROM attachments WHERE id = ?");
        $fstmt->bind_param("i", $pid);
        $fstmt->execute();
        $fres = $fstmt->get_result()->fetch_assoc();
        $fstmt->close();
        if ($fres) {
            if (!empty($fres['picture']) && file_exists("uploads/" . $fres['picture'])) unlink("uploads/" . $fres['picture']);
            if (!empty($fres['video'])   && file_exists("uploads/" . $fres['video']))   unlink("uploads/" . $fres['video']);
        }
        $dstmt = $connection->prepare("DELETE FROM attachments WHERE id = ?");
        $dstmt->bind_param("i", $pid);
        $actionMsg  = $dstmt->execute() ? "Post removed successfully." : "Error: " . $connection->error;
        $actionType = 'success';
        $dstmt->close();
    }

    // Flag / unflag post
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['flag_post'])) {
        $pid  = intval($_POST['post_id']);
        $flag = intval($_POST['flag_value']);
        $fstmt = $connection->prepare("UPDATE attachments SET is_flagged = ? WHERE id = ?");
        $fstmt->bind_param("ii", $flag, $pid);
        $fstmt->execute();
        $fstmt->close();
        $actionMsg  = $flag ? "Post flagged for review." : "Post unflagged.";
        $actionType = 'success';
    }

    // Delete comment (admin)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_delete_comment'])) {
        $cid  = intval($_POST['comment_id']);
        $stmt = $connection->prepare("DELETE FROM post_comments WHERE id = ?");
        $stmt->bind_param("i", $cid);
        $actionMsg  = $stmt->execute() ? "Comment deleted." : "Error: " . $connection->error;
        $actionType = 'success';
        $stmt->close();
    }

    // Delete friend request (admin)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_delete_fr'])) {
        $frid = intval($_POST['fr_id']);
        $stmt = $connection->prepare("DELETE FROM friend_requests WHERE id = ?");
        $stmt->bind_param("i", $frid);
        $actionMsg  = $stmt->execute() ? "Friend request removed." : "Error: " . $connection->error;
        $actionType = 'success';
        $stmt->close();
    }

    // ─── "Last seen" tracking — stamp session when admin visits a section ────
    // Sections that carry badges: comments, friends (pending FR), flagged.
    // We record the timestamp the admin last clicked into each section so we
    // can show only items *newer* than that timestamp in the badge.
    if (!isset($_SESSION['admin_seen'])) {
        $_SESSION['admin_seen'] = ['comments' => null, 'friends' => null, 'flagged' => null];
    }
    // AJAX endpoint: ?mark_seen=<section>
    if (isset($_GET['mark_seen']) && in_array($_GET['mark_seen'], ['comments','friends','flagged'])) {
        $_SESSION['admin_seen'][$_GET['mark_seen']] = date('Y-m-d H:i:s');
        echo json_encode(['ok' => true]);
        $connection->close();
        exit();
    }

    // ─── Fetch all data ──────────────────────────────────────────────────────

    // Users with post, like, comment, friend counts
    $users = $connection->query(
        "SELECT u.id, u.name, u.email, u.profilePic, u.created_at,
                COUNT(DISTINCT a.id)  AS post_count,
                COUNT(DISTINCT pl.id) AS like_count,
                COUNT(DISTINCT pc.id) AS comment_count,
                COUNT(DISTINCT fr.id) AS friend_count
         FROM userdetails u
         LEFT JOIN attachments    a  ON a.userdetails_id = u.id
         LEFT JOIN post_likes     pl ON pl.user_id       = u.id
         LEFT JOIN post_comments  pc ON pc.user_id       = u.id
         LEFT JOIN friend_requests fr ON (fr.sender_id = u.id OR fr.receiver_id = u.id) AND fr.status = 'accepted'
         GROUP BY u.id
         ORDER BY u.created_at DESC"
    )->fetch_all(MYSQLI_ASSOC);

    // Posts with like + comment counts
    $posts = $connection->query(
        "SELECT a.id, a.content, a.picture, a.video, a.created_at, a.is_flagged,
                u.name AS author, u.id AS author_id,
                COUNT(DISTINCT pl.id) AS like_count,
                COUNT(DISTINCT pc.id) AS comment_count
         FROM attachments a
         JOIN userdetails u ON u.id = a.userdetails_id
         LEFT JOIN post_likes    pl ON pl.post_id = a.id
         LEFT JOIN post_comments pc ON pc.post_id = a.id
         GROUP BY a.id
         ORDER BY a.created_at DESC"
    )->fetch_all(MYSQLI_ASSOC);

    // Comments with post info
    $comments = $connection->query(
        "SELECT pc.id, pc.comment, pc.created_at,
                u.name AS commenter_name, u.profilePic AS commenter_pic,
                a.content AS post_content, a.id AS post_id,
                pu.name AS post_author
         FROM post_comments pc
         JOIN userdetails u  ON u.id  = pc.user_id
         JOIN attachments a  ON a.id  = pc.post_id
         JOIN userdetails pu ON pu.id = a.userdetails_id
         ORDER BY pc.created_at DESC"
    )->fetch_all(MYSQLI_ASSOC);

    // Friend requests
    $friendRequests = $connection->query(
        "SELECT fr.id, fr.status, fr.created_at,
                s.name AS sender_name,   s.profilePic AS sender_pic,
                r.name AS receiver_name, r.profilePic AS receiver_pic
         FROM friend_requests fr
         JOIN userdetails s ON s.id = fr.sender_id
         JOIN userdetails r ON r.id = fr.receiver_id
         ORDER BY fr.created_at DESC"
    )->fetch_all(MYSQLI_ASSOC);

    // Aggregate stats
    $totalUsers    = count($users);
    $totalPosts    = count($posts);
    $totalComments = count($comments);
    $flaggedCount  = count(array_filter($posts, fn($p) => $p['is_flagged']));
    $newUsersToday = count(array_filter($users, fn($u) => date('Y-m-d', strtotime($u['created_at'])) === date('Y-m-d')));
    $totalLikes    = (int)array_sum(array_column($posts, 'like_count'));
    $pendingFR     = count(array_filter($friendRequests, fn($f) => $f['status'] === 'pending'));
    $acceptedFR    = count(array_filter($friendRequests, fn($f) => $f['status'] === 'accepted'));

    // ── New-since-last-seen counts (drive the sidebar badges) ────────────────
    $seenComments = $_SESSION['admin_seen']['comments'] ?? null;
    $seenFriends  = $_SESSION['admin_seen']['friends']  ?? null;
    $seenFlagged  = $_SESSION['admin_seen']['flagged']  ?? null;

    // New comments badge: comments added after last visit to Comments section
    if ($seenComments) {
        $newCommentsBadge = count(array_filter($comments,
            fn($c) => strtotime($c['created_at']) > strtotime($seenComments)));
    } else {
        $newCommentsBadge = $totalComments; // never visited → show all
    }

    // Pending FR badge: only pending requests newer than last visit to Friends section
    if ($seenFriends) {
        $newFRBadge = count(array_filter($friendRequests,
            fn($f) => $f['status'] === 'pending' && strtotime($f['created_at']) > strtotime($seenFriends)));
    } else {
        $newFRBadge = $pendingFR;
    }

    // Flagged badge: flagged posts — since admin last viewed Flagged section
    // (flagged is a manual admin action so we just show the total; it resets on visit)
    if ($seenFlagged) {
        $newFlaggedBadge = count(array_filter($posts,
            fn($p) => $p['is_flagged'] && strtotime($p['created_at']) > strtotime($seenFlagged)));
    } else {
        $newFlaggedBadge = $flaggedCount;
    }

    $connection->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMP – Admin Panel</title>
    <link rel="icon" type="image/x-icon" href="SMP_logo-(1).ico">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --blue:     #1877f2;
            --blue-d:   #1060c9;
            --red:      #e74c3c;
            --red-d:    #c0392b;
            --green:    #27ae60;
            --orange:   #e67e22;
            --purple:   #8e44ad;
            --teal:     #16a085;
            --pink:     #e91e8c;
            --bg:       #f0f2f5;
            --card:     #ffffff;
            --border:   #dde1e7;
            --text:     #1c1e21;
            --muted:    #65676b;
            --sidebar-w: 224px;
        }

        body { font-family: Arial, sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; }

        /* ── Login ─────────────────────────────────────────── */
        .login-wrap { display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .login-box  { background: var(--card); border-radius: 10px; box-shadow: 0 2px 12px rgba(0,0,0,.12); padding: 2.5rem 2rem; width: 340px; }
        .login-box h1 { color: var(--blue); text-align: center; margin-bottom: .3rem; font-size: 1.6rem; }
        .login-box p  { color: var(--muted); text-align: center; margin-bottom: 1.5rem; font-size: .85rem; }
        .login-box label { display: block; font-size: .85rem; font-weight: bold; margin-bottom: .3rem; }
        .login-box input[type=text], .login-box input[type=password] {
            width: 100%; padding: .55rem .75rem; border: 1px solid var(--border);
            border-radius: 6px; font-size: .95rem; margin-bottom: 1rem;
        }
        .login-box input:focus { outline: none; border-color: var(--blue); }
        .login-box .btn-login { width: 100%; background: var(--blue); color: #fff; border: none; border-radius: 6px; padding: .65rem; font-size: 1rem; cursor: pointer; font-weight: bold; }
        .login-box .btn-login:hover { background: var(--blue-d); }
        .login-error { color: var(--red); background: #fdecea; padding: .6rem .9rem; border-radius: 6px; font-size: .88rem; margin-bottom: 1rem; }

        /* ── Layout ─────────────────────────────────────────── */
        .layout { display: flex; min-height: 100vh; }

        /* Sidebar */
        .sidebar { width: var(--sidebar-w); background: #1c2a3a; color: #c9d1db; display: flex; flex-direction: column; flex-shrink: 0; position: sticky; top: 0; height: 100vh; overflow-y: auto; }
        .sidebar-brand { padding: 1.2rem 1.2rem 1rem; border-bottom: 1px solid rgba(255,255,255,.08); }
        .sidebar-brand h2 { color: #fff; font-size: 1.1rem; }
        .sidebar-brand span { font-size: .78rem; color: #8899aa; }
        .nav-section { padding: .8rem .8rem .3rem; font-size: .72rem; text-transform: uppercase; letter-spacing: .08em; color: #556677; }
        .nav-item { display: flex; align-items: center; gap: .65rem; padding: .6rem 1.2rem; cursor: pointer; border-radius: 6px; margin: .15rem .6rem; font-size: .92rem; transition: background .15s; text-decoration: none; color: #c9d1db; border: none; background: none; width: calc(100% - 1.2rem); }
        .nav-item:hover, .nav-item.active { background: rgba(255,255,255,.1); color: #fff; }
        .nav-item .icon { font-size: 1.1rem; width: 20px; text-align: center; }
        .nav-badge { margin-left: auto; background: var(--red); color: #fff; border-radius: 999px; padding: .1rem .45rem; font-size: .7rem; font-weight: bold; }
        .sidebar-footer { margin-top: auto; padding: 1rem 1.2rem; border-top: 1px solid rgba(255,255,255,.08); }
        .logout-btn { display: flex; align-items: center; gap: .6rem; color: #ff7b7b; font-size: .88rem; text-decoration: none; }
        .logout-btn:hover { color: #ff4444; }

        /* Main */
        .main { flex: 1; display: flex; flex-direction: column; min-width: 0; }
        .topbar { background: var(--card); border-bottom: 1px solid var(--border); padding: .85rem 1.5rem; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 10; }
        .topbar h1 { font-size: 1.2rem; color: var(--text); }
        .topbar-info { font-size: .82rem; color: var(--muted); }
        .content { padding: 1.5rem; flex: 1; }

        /* Alert */
        .alert { padding: .75rem 1rem; border-radius: 7px; margin-bottom: 1.2rem; font-size: .9rem; display: flex; align-items: center; gap: .5rem; }
        .alert.success { background: #eafaf1; color: #1d6637; border: 1px solid #a9dfbf; }
        .alert.error   { background: #fdecea; color: #8e1c1c; border: 1px solid #f5aca6; }

        /* Stats */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(145px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
        .stat-card { background: var(--card); border-radius: 10px; padding: 1.1rem 1.2rem; box-shadow: 0 1px 4px rgba(0,0,0,.07); display: flex; flex-direction: column; gap: .3rem; }
        .stat-label { font-size: .75rem; color: var(--muted); text-transform: uppercase; letter-spacing: .05em; }
        .stat-value { font-size: 1.9rem; font-weight: bold; line-height: 1; }
        .stat-sub   { font-size: .72rem; color: var(--muted); }
        .stat-card.blue   .stat-value { color: var(--blue); }
        .stat-card.green  .stat-value { color: var(--green); }
        .stat-card.orange .stat-value { color: var(--orange); }
        .stat-card.red    .stat-value { color: var(--red); }
        .stat-card.purple .stat-value { color: var(--purple); }
        .stat-card.teal   .stat-value { color: var(--teal); }
        .stat-card.pink   .stat-value { color: var(--pink); }

        /* Panel */
        .panel { background: var(--card); border-radius: 10px; box-shadow: 0 1px 4px rgba(0,0,0,.07); overflow: hidden; margin-bottom: 1.5rem; }
        .panel-header { padding: 1rem 1.2rem; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: .5rem; }
        .panel-header h2 { font-size: 1rem; }

        /* Badge */
        .badge { background: var(--blue); color: #fff; border-radius: 999px; padding: .15rem .55rem; font-size: .75rem; font-weight: bold; }
        .badge.red    { background: var(--red); }
        .badge.orange { background: var(--orange); }
        .badge.green  { background: var(--green); }
        .badge.purple { background: var(--purple); }

        /* Search */
        .search-bar { padding: .7rem 1.2rem; background: #f7f8fa; border-bottom: 1px solid var(--border); }
        .search-bar input { width: 100%; max-width: 360px; padding: .45rem .8rem; border: 1px solid var(--border); border-radius: 6px; font-size: .88rem; }
        .search-bar input:focus { outline: none; border-color: var(--blue); }

        /* Table */
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: .88rem; }
        th { background: #f7f8fa; padding: .7rem 1rem; text-align: left; font-size: .75rem; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); border-bottom: 1px solid var(--border); white-space: nowrap; }
        td { padding: .65rem 1rem; border-bottom: 1px solid #f0f0f0; vertical-align: middle; }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: #fafbff; }

        /* Avatar */
        .avatar { width: 34px; height: 34px; border-radius: 50%; object-fit: cover; border: 2px solid var(--border); }
        .user-cell  { display: flex; align-items: center; gap: .7rem; }
        .user-name  { font-weight: bold; font-size: .9rem; }
        .user-email { font-size: .78rem; color: var(--muted); }

        /* Buttons */
        .btn { display: inline-flex; align-items: center; gap: .35rem; padding: .35rem .8rem; border-radius: 6px; font-size: .82rem; font-weight: bold; cursor: pointer; border: none; transition: opacity .15s; white-space: nowrap; text-decoration: none; }
        .btn:hover { opacity: .85; }
        .btn-danger  { background: var(--red);    color: #fff; }
        .btn-warning { background: var(--orange);  color: #fff; }
        .btn-success { background: var(--green);   color: #fff; }
        .btn-ghost   { background: #eee; color: var(--text); }
        .btn-purple  { background: var(--purple);  color: #fff; }

        /* Post snippet */
        .post-snippet { max-width: 260px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: var(--text); }
        .post-thumb   { width: 42px; height: 42px; object-fit: cover; border-radius: 5px; border: 1px solid var(--border); }
        .flag-badge   { display: inline-block; background: #fff3cd; color: #856404; border: 1px solid #ffc107; border-radius: 4px; padding: .1rem .45rem; font-size: .73rem; font-weight: bold; }

        /* Engagement pill */
        .eng-pill { display: inline-flex; align-items: center; gap: 4px; background: #f0f2f5; border-radius: 20px; padding: 3px 9px; font-size: .78rem; color: var(--muted); white-space: nowrap; }

        /* Status pill */
        .status-pill { display: inline-block; border-radius: 20px; padding: 3px 10px; font-size: .75rem; font-weight: bold; }
        .status-pending  { background: #fff3cd; color: #856404; }
        .status-accepted { background: #d4edda; color: #155724; }
        .status-declined { background: #f8d7da; color: #721c24; }

        /* Comment bubble */
        .comment-snippet { max-width: 240px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-style: italic; color: #444; }

        /* Sections */
        .section { display: none; }
        .section.active { display: block; }

        /* Charts */
        .charts-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
        .chart-card { background: var(--card); border-radius: 10px; box-shadow: 0 1px 4px rgba(0,0,0,.07); padding: 1.2rem; }
        .chart-card h3 { font-size: .88rem; color: var(--muted); text-transform: uppercase; letter-spacing: .05em; margin-bottom: 1rem; }
        .chart-wrap { position: relative; height: 220px; }

        /* Confirm overlay */
        .overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 100; align-items: center; justify-content: center; }
        .overlay.open { display: flex; }
        .dialog { background: var(--card); border-radius: 12px; padding: 1.8rem 2rem; max-width: 400px; width: 90%; box-shadow: 0 8px 32px rgba(0,0,0,.2); }
        .dialog h3 { margin-bottom: .7rem; }
        .dialog p  { color: var(--muted); font-size: .92rem; margin-bottom: 1.2rem; }
        .dialog-btns { display: flex; gap: .7rem; justify-content: flex-end; }

        @media (max-width: 640px) {
            .sidebar { width: 60px; }
            .sidebar-brand h2, .sidebar-brand span, .nav-item span, .nav-section, .logout-btn span { display: none; }
            .nav-item { justify-content: center; padding: .6rem; margin: .15rem; width: calc(100% - .3rem); }
            .nav-badge { display: none; }
        }
    </style>
</head>
<body>

<?php if (!$isAdmin): ?>
<!-- ══════════════ LOGIN ══════════════ -->
<div class="login-wrap">
    <div class="login-box">
        <h1>⚙️ Admin Panel</h1>
        <p>SMP Social Media Platform</p>
        <?php if ($admin_error): ?>
            <div class="login-error">⚠️ <?= htmlspecialchars($admin_error) ?></div>
        <?php endif; ?>
        <form method="POST">
            <label for="admin_user">Username</label>
            <input type="text" id="admin_user" name="admin_user" placeholder="admin" required autocomplete="username">
            <label for="admin_pass">Password</label>
            <input type="password" id="admin_pass" name="admin_pass" placeholder="••••••••" required autocomplete="current-password">
            <button class="btn-login" type="submit" name="admin_login">Sign In to Admin Panel</button>
        </form>
        <p style="text-align:center;margin-top:1.2rem;font-size:.8rem;color:var(--muted);">
            <em>Change credentials in admin_panel.php before deploying.</em>
        </p>
    </div>
</div>

<?php else: ?>
<!-- ══════════════ DASHBOARD ══════════════ -->

<!-- Confirm Dialog -->
<div class="overlay" id="confirmOverlay">
    <div class="dialog">
        <h3 id="confirmTitle">Confirm Action</h3>
        <p id="confirmMsg">Are you sure?</p>
        <div class="dialog-btns">
            <button class="btn btn-ghost" onclick="closeConfirm()">Cancel</button>
            <form id="confirmForm" method="POST" style="display:inline">
                <input type="hidden" name="user_id"    id="confirmUserId">
                <input type="hidden" name="post_id"    id="confirmPostId">
                <input type="hidden" name="flag_value" id="confirmFlagVal">
                <input type="hidden" name="comment_id" id="confirmCommentId">
                <input type="hidden" name="fr_id"      id="confirmFrId">
                <button class="btn btn-danger" id="confirmBtn" type="submit">Confirm</button>
            </form>
        </div>
    </div>
</div>

<div class="layout">
    <!-- ── Sidebar ─────────────────────────────────────────── -->
    <nav class="sidebar">
        <div class="sidebar-brand">
            <h2>⚙️ Admin</h2>
            <span>SMP Dashboard</span>
        </div>

        <div class="nav-section">Navigation</div>
        <button class="nav-item active" id="tab-overview-btn"  onclick="showSection('overview')">
            <span class="icon">📊</span><span>Overview</span>
        </button>
        <button class="nav-item" id="tab-users-btn"    onclick="showSection('users')">
            <span class="icon">👥</span><span>Users</span>
        </button>
        <button class="nav-item" id="tab-posts-btn"    onclick="showSection('posts')">
            <span class="icon">📝</span><span>Posts</span>
        </button>
        <button class="nav-item" id="tab-comments-btn" onclick="showSection('comments')">
            <span class="icon">💬</span><span>Comments</span>
            <span class="nav-badge" id="badge-comments" style="<?= $newCommentsBadge > 0 ? '' : 'display:none' ?>"><?= $newCommentsBadge ?></span>
        </button>
        <button class="nav-item" id="tab-friends-btn"  onclick="showSection('friends')">
            <span class="icon">🤝</span><span>Friend Requests</span>
            <span class="nav-badge" id="badge-friends"  style="<?= $newFRBadge > 0      ? '' : 'display:none' ?>"><?= $newFRBadge ?></span>
        </button>
        <button class="nav-item" id="tab-flagged-btn"  onclick="showSection('flagged')">
            <span class="icon">🚩</span><span>Flagged</span>
            <span class="nav-badge" id="badge-flagged"  style="<?= $newFlaggedBadge > 0 ? '' : 'display:none' ?>"><?= $newFlaggedBadge ?></span>
        </button>

        <div class="nav-section">App</div>
        <a class="nav-item" href="sem2Profile.php">
            <span class="icon">🏠</span><span>Back to Site</span>
        </a>

        <div class="sidebar-footer">
            <a class="logout-btn" href="sem2Assignmentadmin_panel.php?admin_logout=1">
                <span>🚪</span><span>Sign Out</span>
            </a>
        </div>
    </nav>

    <!-- ── Main ───────────────────────────────────────────── -->
    <div class="main">
        <div class="topbar">
            <h1 id="sectionTitle">Overview</h1>
            <span class="topbar-info">Logged in as <strong>Admin</strong></span>
        </div>

        <div class="content">

            <?php if ($actionMsg): ?>
                <div class="alert <?= $actionType ?>">
                    <?= $actionType === 'success' ? '✅' : '❌' ?> <?= htmlspecialchars($actionMsg) ?>
                </div>
            <?php endif; ?>

            <!-- ════════════ OVERVIEW ════════════ -->
            <div class="section active" id="section-overview">

                <!-- Stats row -->
                <div class="stats-grid">
                    <div class="stat-card blue">
                        <div class="stat-label">Total Users</div>
                        <div class="stat-value"><?= $totalUsers ?></div>
                        <div class="stat-sub"><?= $newUsersToday ?> joined today</div>
                    </div>
                    <div class="stat-card green">
                        <div class="stat-label">Total Posts</div>
                        <div class="stat-value"><?= $totalPosts ?></div>
                    </div>
                    <div class="stat-card pink" style="--pink:#e91e8c">
                        <div class="stat-label">Total Likes</div>
                        <div class="stat-value" style="color:#e91e8c"><?= $totalLikes ?></div>
                    </div>
                    <div class="stat-card purple">
                        <div class="stat-label">Comments</div>
                        <div class="stat-value"><?= $totalComments ?></div>
                    </div>
                    <div class="stat-card teal">
                        <div class="stat-label">Friendships</div>
                        <div class="stat-value"><?= $acceptedFR ?></div>
                        <div class="stat-sub"><?= $pendingFR ?> pending</div>
                    </div>
                    <div class="stat-card orange">
                        <div class="stat-label">New Today</div>
                        <div class="stat-value"><?= $newUsersToday ?></div>
                    </div>
                    <div class="stat-card red">
                        <div class="stat-label">Flagged Posts</div>
                        <div class="stat-value"><?= $flaggedCount ?></div>
                    </div>
                </div>

                <!-- Charts -->
                <div class="charts-grid">
                    <div class="chart-card">
                        <h3>📊 Content Breakdown</h3>
                        <div class="chart-wrap"><canvas id="contentDonut"></canvas></div>
                    </div>
                    <div class="chart-card">
                        <h3>🏆 Top Users by Posts</h3>
                        <div class="chart-wrap"><canvas id="topUsersBar"></canvas></div>
                    </div>
                    <div class="chart-card">
                        <h3>❤️ Engagement Overview</h3>
                        <div class="chart-wrap"><canvas id="engagementBar"></canvas></div>
                    </div>
                    <div class="chart-card">
                        <h3>🤝 Friend Request Status</h3>
                        <div class="chart-wrap"><canvas id="frDonut"></canvas></div>
                    </div>
                </div>

                <!-- Recent users preview -->
                <div class="panel">
                    <div class="panel-header">
                        <h2>Recent Users</h2>
                        <button class="btn btn-ghost" onclick="showSection('users')">View All →</button>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>User</th><th>Posts</th><th>Likes Given</th><th>Friends</th><th>Joined</th></tr></thead>
                            <tbody>
                                <?php foreach (array_slice($users, 0, 5) as $u): ?>
                                <tr>
                                    <td>
                                        <div class="user-cell">
                                            <img class="avatar" src="<?= htmlspecialchars(getProfilePicPath($u['profilePic'])) ?>" alt="">
                                            <div>
                                                <div class="user-name"><?= htmlspecialchars($u['name']) ?></div>
                                                <div class="user-email"><?= htmlspecialchars($u['email']) ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?= $u['post_count'] ?></td>
                                    <td><?= $u['like_count'] ?></td>
                                    <td><?= $u['friend_count'] ?></td>
                                    <td style="color:var(--muted);font-size:.82rem"><?= date('M j, Y', strtotime($u['created_at'])) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Recent posts preview -->
                <div class="panel">
                    <div class="panel-header">
                        <h2>Recent Posts</h2>
                        <button class="btn btn-ghost" onclick="showSection('posts')">View All →</button>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Author</th><th>Content</th><th>❤️ Likes</th><th>💬 Comments</th><th>Posted</th></tr></thead>
                            <tbody>
                                <?php foreach (array_slice($posts, 0, 5) as $p): ?>
                                <tr>
                                    <td style="font-weight:bold;white-space:nowrap"><?= htmlspecialchars($p['author']) ?></td>
                                    <td><div class="post-snippet"><?= htmlspecialchars($p['content']) ?></div></td>
                                    <td><span class="eng-pill">❤️ <?= $p['like_count'] ?></span></td>
                                    <td><span class="eng-pill">💬 <?= $p['comment_count'] ?></span></td>
                                    <td style="color:var(--muted);font-size:.82rem;white-space:nowrap"><?= date('M j, Y', strtotime($p['created_at'])) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Recent comments preview -->
                <div class="panel">
                    <div class="panel-header">
                        <h2>Recent Comments</h2>
                        <button class="btn btn-ghost" onclick="showSection('comments')">View All →</button>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>By</th><th>Comment</th><th>On Post By</th><th>Date</th></tr></thead>
                            <tbody>
                                <?php foreach (array_slice($comments, 0, 5) as $c): ?>
                                <tr>
                                    <td style="white-space:nowrap;font-weight:bold"><?= htmlspecialchars($c['commenter_name']) ?></td>
                                    <td><div class="comment-snippet"><?= htmlspecialchars($c['comment']) ?></div></td>
                                    <td style="color:var(--muted);white-space:nowrap"><?= htmlspecialchars($c['post_author']) ?></td>
                                    <td style="color:var(--muted);font-size:.82rem;white-space:nowrap"><?= date('M j, Y', strtotime($c['created_at'])) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div><!-- /overview -->

            <!-- ════════════ USERS ════════════ -->
            <div class="section" id="section-users">
                <div class="panel">
                    <div class="panel-header">
                        <h2>All Users</h2>
                        <span class="badge"><?= $totalUsers ?></span>
                    </div>
                    <div class="search-bar">
                        <input type="text" id="userSearch" placeholder="🔍  Search by name or email…" oninput="filterTable('userTable', this.value)">
                    </div>
                    <div class="table-wrap">
                        <table id="userTable">
                            <thead><tr>
                                <th>User</th>
                                <th>Posts</th>
                                <th>Likes Given</th>
                                <th>Comments</th>
                                <th>Friends</th>
                                <th>Joined</th>
                                <th>Actions</th>
                            </tr></thead>
                            <tbody>
                                <?php foreach ($users as $u): ?>
                                <tr>
                                    <td>
                                        <div class="user-cell">
                                            <img class="avatar" src="<?= htmlspecialchars(getProfilePicPath($u['profilePic'])) ?>" alt="">
                                            <div>
                                                <div class="user-name"><?= htmlspecialchars($u['name']) ?></div>
                                                <div class="user-email"><?= htmlspecialchars($u['email']) ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?= $u['post_count'] ?></td>
                                    <td><span class="eng-pill">❤️ <?= $u['like_count'] ?></span></td>
                                    <td><span class="eng-pill">💬 <?= $u['comment_count'] ?></span></td>
                                    <td><span class="eng-pill">🤝 <?= $u['friend_count'] ?></span></td>
                                    <td style="color:var(--muted);font-size:.82rem;white-space:nowrap"><?= date('M j, Y', strtotime($u['created_at'])) ?></td>
                                    <td>
                                        <button class="btn btn-danger"
                                            onclick="confirmDeleteUser(<?= $u['id'] ?>, '<?= htmlspecialchars(addslashes($u['name'])) ?>')">
                                            🗑 Delete
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div><!-- /users -->

            <!-- ════════════ POSTS ════════════ -->
            <div class="section" id="section-posts">
                <div class="panel">
                    <div class="panel-header">
                        <h2>All Posts</h2>
                        <span class="badge"><?= $totalPosts ?></span>
                    </div>
                    <div class="search-bar">
                        <input type="text" id="postSearch" placeholder="🔍  Search by author or content…" oninput="filterTable('postTable', this.value)">
                    </div>
                    <div class="table-wrap">
                        <table id="postTable">
                            <thead><tr>
                                <th>Media</th>
                                <th>Author</th>
                                <th>Content</th>
                                <th>❤️ Likes</th>
                                <th>💬 Comments</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th>Actions</th>
                            </tr></thead>
                            <tbody>
                                <?php foreach ($posts as $p): ?>
                                <tr>
                                    <td>
                                        <?php if ($p['picture']): ?>
                                            <img class="post-thumb" src="uploads/<?= htmlspecialchars($p['picture']) ?>" alt="img">
                                        <?php elseif ($p['video']): ?>
                                            <span style="font-size:1.4rem">🎬</span>
                                        <?php else: ?>
                                            <span style="font-size:1.1rem;color:var(--muted)">📄</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="white-space:nowrap;font-weight:bold"><?= htmlspecialchars($p['author']) ?></td>
                                    <td><div class="post-snippet"><?= htmlspecialchars($p['content']) ?></div></td>
                                    <td><span class="eng-pill">❤️ <?= $p['like_count'] ?></span></td>
                                    <td><span class="eng-pill">💬 <?= $p['comment_count'] ?></span></td>
                                    <td>
                                        <?php if ($p['is_flagged']): ?>
                                            <span class="flag-badge">🚩 Flagged</span>
                                        <?php else: ?>
                                            <span style="color:var(--green);font-size:.82rem">✅ OK</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="color:var(--muted);font-size:.82rem;white-space:nowrap"><?= date('M j, Y', strtotime($p['created_at'])) ?></td>
                                    <td style="display:flex;gap:.4rem;flex-wrap:wrap">
                                        <?php if (!$p['is_flagged']): ?>
                                            <button class="btn btn-warning" onclick="confirmFlagPost(<?= $p['id'] ?>, 1)">🚩 Flag</button>
                                        <?php else: ?>
                                            <button class="btn btn-success" onclick="confirmFlagPost(<?= $p['id'] ?>, 0)">✅ Unflag</button>
                                        <?php endif; ?>
                                        <button class="btn btn-danger" onclick="confirmDeletePost(<?= $p['id'] ?>, '<?= htmlspecialchars(addslashes($p['author'])) ?>')">🗑 Remove</button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div><!-- /posts -->

            <!-- ════════════ COMMENTS ════════════ -->
            <div class="section" id="section-comments">
                <div class="panel">
                    <div class="panel-header">
                        <h2>All Comments</h2>
                        <span class="badge purple"><?= $totalComments ?></span>
                    </div>
                    <div class="search-bar">
                        <input type="text" id="commentSearch" placeholder="🔍  Search by commenter, comment, or post author…" oninput="filterTable('commentTable', this.value)">
                    </div>
                    <?php if (empty($comments)): ?>
                        <div style="padding:2.5rem;text-align:center;color:var(--muted);">
                            <div style="font-size:2.5rem;margin-bottom:.5rem">💬</div>
                            No comments yet.
                        </div>
                    <?php else: ?>
                    <div class="table-wrap">
                        <table id="commentTable">
                            <thead><tr>
                                <th>By</th>
                                <th>Comment</th>
                                <th>On Post</th>
                                <th>Post Author</th>
                                <th>Date</th>
                                <th>Actions</th>
                            </tr></thead>
                            <tbody>
                                <?php foreach ($comments as $c): ?>
                                <tr>
                                    <td>
                                        <div class="user-cell">
                                            <img class="avatar" src="<?= htmlspecialchars(getProfilePicPath($c['commenter_pic'])) ?>" alt="">
                                            <span style="font-weight:bold;white-space:nowrap"><?= htmlspecialchars($c['commenter_name']) ?></span>
                                        </div>
                                    </td>
                                    <td><div class="comment-snippet">"<?= htmlspecialchars($c['comment']) ?>"</div></td>
                                    <td><div class="post-snippet" style="max-width:200px"><?= htmlspecialchars($c['post_content']) ?></div></td>
                                    <td style="white-space:nowrap;color:var(--muted)"><?= htmlspecialchars($c['post_author']) ?></td>
                                    <td style="color:var(--muted);font-size:.82rem;white-space:nowrap"><?= date('M j, Y g:i A', strtotime($c['created_at'])) ?></td>
                                    <td>
                                        <button class="btn btn-danger"
                                            onclick="confirmDeleteComment(<?= $c['id'] ?>, '<?= htmlspecialchars(addslashes($c['commenter_name'])) ?>')">
                                            🗑 Delete
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div><!-- /comments -->

            <!-- ════════════ FRIEND REQUESTS ════════════ -->
            <div class="section" id="section-friends">

                <!-- Summary cards -->
                <div class="stats-grid" style="grid-template-columns:repeat(3,1fr);max-width:480px;margin-bottom:1.2rem;">
                    <div class="stat-card teal">
                        <div class="stat-label">Total Requests</div>
                        <div class="stat-value"><?= count($friendRequests) ?></div>
                    </div>
                    <div class="stat-card green">
                        <div class="stat-label">Accepted</div>
                        <div class="stat-value"><?= $acceptedFR ?></div>
                    </div>
                    <div class="stat-card orange">
                        <div class="stat-label">Pending</div>
                        <div class="stat-value"><?= $pendingFR ?></div>
                    </div>
                </div>

                <div class="panel">
                    <div class="panel-header">
                        <h2>All Friend Requests</h2>
                        <span class="badge"><?= count($friendRequests) ?></span>
                    </div>
                    <div class="search-bar">
                        <input type="text" id="frSearch" placeholder="🔍  Search by name or status…" oninput="filterTable('frTable', this.value)">
                    </div>
                    <?php if (empty($friendRequests)): ?>
                        <div style="padding:2.5rem;text-align:center;color:var(--muted);">
                            <div style="font-size:2.5rem;margin-bottom:.5rem">🤝</div>
                            No friend requests yet.
                        </div>
                    <?php else: ?>
                    <div class="table-wrap">
                        <table id="frTable">
                            <thead><tr>
                                <th>Sender</th>
                                <th>Receiver</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th>Actions</th>
                            </tr></thead>
                            <tbody>
                                <?php foreach ($friendRequests as $fr): ?>
                                <tr>
                                    <td>
                                        <div class="user-cell">
                                            <img class="avatar" src="<?= htmlspecialchars(getProfilePicPath($fr['sender_pic'])) ?>" alt="">
                                            <span style="font-weight:bold;white-space:nowrap"><?= htmlspecialchars($fr['sender_name']) ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="user-cell">
                                            <img class="avatar" src="<?= htmlspecialchars(getProfilePicPath($fr['receiver_pic'])) ?>" alt="">
                                            <span style="font-weight:bold;white-space:nowrap"><?= htmlspecialchars($fr['receiver_name']) ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="status-pill status-<?= $fr['status'] ?>">
                                            <?php
                                                $icons = ['pending'=>'⏳','accepted'=>'✅','declined'=>'❌'];
                                                echo ($icons[$fr['status']] ?? '') . ' ' . ucfirst($fr['status']);
                                            ?>
                                        </span>
                                    </td>
                                    <td style="color:var(--muted);font-size:.82rem;white-space:nowrap"><?= date('M j, Y g:i A', strtotime($fr['created_at'])) ?></td>
                                    <td>
                                        <button class="btn btn-danger"
                                            onclick="confirmDeleteFR(<?= $fr['id'] ?>, '<?= htmlspecialchars(addslashes($fr['sender_name'])) ?>', '<?= htmlspecialchars(addslashes($fr['receiver_name'])) ?>')">
                                            🗑 Remove
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div><!-- /friends -->

            <!-- ════════════ FLAGGED ════════════ -->
            <div class="section" id="section-flagged">
                <?php $flaggedPosts = array_filter($posts, fn($p) => $p['is_flagged']); ?>
                <div class="panel">
                    <div class="panel-header">
                        <h2>🚩 Flagged Posts</h2>
                        <span class="badge red"><?= count($flaggedPosts) ?></span>
                    </div>
                    <?php if (empty($flaggedPosts)): ?>
                        <div style="padding:2.5rem;text-align:center;color:var(--muted);">
                            <div style="font-size:2.5rem;margin-bottom:.5rem">🎉</div>
                            No flagged posts right now. All clear!
                        </div>
                    <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead><tr>
                                <th>Media</th>
                                <th>Author</th>
                                <th>Content</th>
                                <th>❤️ Likes</th>
                                <th>💬 Comments</th>
                                <th>Date</th>
                                <th>Actions</th>
                            </tr></thead>
                            <tbody>
                                <?php foreach ($flaggedPosts as $p): ?>
                                <tr style="background:#fff8f8">
                                    <td>
                                        <?php if ($p['picture']): ?>
                                            <img class="post-thumb" src="uploads/<?= htmlspecialchars($p['picture']) ?>" alt="img">
                                        <?php elseif ($p['video']): ?>
                                            <span style="font-size:1.4rem">🎬</span>
                                        <?php else: ?>
                                            <span style="font-size:1.1rem;color:var(--muted)">📄</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-weight:bold"><?= htmlspecialchars($p['author']) ?></td>
                                    <td><div class="post-snippet"><?= htmlspecialchars($p['content']) ?></div></td>
                                    <td><span class="eng-pill">❤️ <?= $p['like_count'] ?></span></td>
                                    <td><span class="eng-pill">💬 <?= $p['comment_count'] ?></span></td>
                                    <td style="color:var(--muted);font-size:.82rem;white-space:nowrap"><?= date('M j, Y', strtotime($p['created_at'])) ?></td>
                                    <td style="display:flex;gap:.4rem;flex-wrap:wrap">
                                        <button class="btn btn-success" onclick="confirmFlagPost(<?= $p['id'] ?>, 0)">✅ Unflag</button>
                                        <button class="btn btn-danger"  onclick="confirmDeletePost(<?= $p['id'] ?>, '<?= htmlspecialchars(addslashes($p['author'])) ?>')">🗑 Remove</button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div><!-- /flagged -->

        </div><!-- /content -->
    </div><!-- /main -->
</div><!-- /layout -->

<script>
// ── Section switching ────────────────────────────────────────────────────────
const sectionTitles = {
    overview: 'Overview',
    users:    'User Management',
    posts:    'Post Moderation',
    comments: 'Comment Moderation',
    friends:  'Friend Requests',
    flagged:  'Flagged Posts'
};

// Sections that have clearable badges
const BADGE_SECTIONS = ['comments', 'friends', 'flagged'];

function showSection(name) {
    document.querySelectorAll('.section').forEach(s => s.classList.remove('active'));
    document.querySelectorAll('.nav-item').forEach(b => b.classList.remove('active'));
    document.getElementById('section-' + name).classList.add('active');
    document.getElementById('tab-' + name + '-btn').classList.add('active');
    document.getElementById('sectionTitle').textContent = sectionTitles[name];

    // If this section has a badge, clear it immediately and record the visit
    if (BADGE_SECTIONS.includes(name)) {
        const badge = document.getElementById('badge-' + name);
        if (badge) badge.style.display = 'none';
        // Tell the server the admin has now seen this section
        fetch('sem2Assignmentadmin_panel.php?mark_seen=' + encodeURIComponent(name))
            .catch(() => {}); // fire-and-forget; silent on network error
    }
}

// ── Table search ─────────────────────────────────────────────────────────────
function filterTable(tableId, query) {
    const q = query.toLowerCase();
    document.querySelectorAll('#' + tableId + ' tbody tr').forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
}

// ── Confirm dialog helpers ───────────────────────────────────────────────────
function openConfirm(title, msg) {
    document.getElementById('confirmTitle').textContent = title;
    document.getElementById('confirmMsg').textContent   = msg;
    document.getElementById('confirmOverlay').classList.add('open');
}
function closeConfirm() {
    document.getElementById('confirmOverlay').classList.remove('open');
    document.getElementById('confirmUserId').value   = '';
    document.getElementById('confirmPostId').value   = '';
    document.getElementById('confirmFlagVal').value  = '';
    document.getElementById('confirmCommentId').value = '';
    document.getElementById('confirmFrId').value     = '';
}

function confirmDeleteUser(uid, name) {
    document.getElementById('confirmUserId').value = uid;
    document.getElementById('confirmBtn').name = 'delete_user';
    openConfirm('Delete User Account',
        `Permanently delete "${name}" and all their posts, messages, likes, comments, and data? This cannot be undone.`);
}
function confirmDeletePost(pid, author) {
    document.getElementById('confirmPostId').value = pid;
    document.getElementById('confirmBtn').name = 'admin_delete_post';
    openConfirm('Remove Post',
        `Remove this post by "${author}"? All likes and comments on it will also be deleted.`);
}
function confirmFlagPost(pid, flagVal) {
    document.getElementById('confirmPostId').value  = pid;
    document.getElementById('confirmFlagVal').value = flagVal;
    document.getElementById('confirmBtn').name = 'flag_post';
    const action = flagVal ? 'Flag' : 'Unflag';
    openConfirm(action + ' Post',
        flagVal ? 'Flag this post for review? It will appear in the Flagged Posts section.'
                : 'Unflag this post? It will be removed from the Flagged Posts section.');
}
function confirmDeleteComment(cid, name) {
    document.getElementById('confirmCommentId').value = cid;
    document.getElementById('confirmBtn').name = 'admin_delete_comment';
    openConfirm('Delete Comment',
        `Permanently delete this comment by "${name}"? This cannot be undone.`);
}
function confirmDeleteFR(frid, sender, receiver) {
    document.getElementById('confirmFrId').value = frid;
    document.getElementById('confirmBtn').name = 'admin_delete_fr';
    openConfirm('Remove Friend Request',
        `Remove the friend request from "${sender}" to "${receiver}"? This will also remove the friendship if it was accepted.`);
}

// Close overlay on background click
document.getElementById('confirmOverlay').addEventListener('click', function(e) {
    if (e.target === this) closeConfirm();
});

// ── Charts ───────────────────────────────────────────────────────────────────
<?php
    $normalPosts  = $totalPosts - $flaggedCount;
    $declinedFR   = count(array_filter($friendRequests, fn($f) => $f['status'] === 'declined'));

    $topUsers = array_filter($users, fn($u) => $u['post_count'] > 0);
    usort($topUsers, fn($a,$b) => $b['post_count'] - $a['post_count']);
    $topUsers  = array_slice($topUsers, 0, 6);
    $topNames  = json_encode(array_column($topUsers, 'name'));
    $topCounts = json_encode(array_column($topUsers, 'post_count'));
?>
const C = {
    blue:   '#1877f2', green:  '#27ae60', orange: '#e67e22',
    red:    '#e74c3c', purple: '#8e44ad', teal:   '#16a085',
    pink:   '#e91e8c', gray:   '#95a5a6',
};

// 1. Content Breakdown doughnut
new Chart(document.getElementById('contentDonut'), {
    type: 'doughnut',
    data: {
        labels: ['Normal Posts', 'Flagged Posts'],
        datasets: [{ data: [<?= $normalPosts ?>, <?= $flaggedCount ?>],
            backgroundColor: [C.blue, C.red], borderWidth: 2, borderColor: '#fff', hoverOffset: 8 }]
    },
    options: { responsive: true, maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom', labels: { font: { size: 12 }, padding: 16 } },
            tooltip: { callbacks: { label: ctx => ` ${ctx.label}: ${ctx.parsed}` } } } }
});

// 2. Top users bar
new Chart(document.getElementById('topUsersBar'), {
    type: 'bar',
    data: {
        labels: <?= $topNames ?>,
        datasets: [{ label: 'Posts', data: <?= $topCounts ?>,
            backgroundColor: [C.blue,C.green,C.orange,C.purple,C.teal,C.red],
            borderRadius: 6, borderSkipped: false }]
    },
    options: { responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true, ticks: { stepSize: 1, font:{size:11} }, grid:{color:'#f0f0f0'} },
                  x: { ticks: { font:{size:11}, maxRotation:30,
                        callback(val,i){ const l=this.getLabelForValue(i); return l.length>10?l.slice(0,10)+'…':l; } },
                        grid:{display:false} } } }
});

// 3. Engagement overview bar
new Chart(document.getElementById('engagementBar'), {
    type: 'bar',
    data: {
        labels: ['Posts', 'Likes', 'Comments', 'Friendships', 'Pending FR'],
        datasets: [{ label: 'Count',
            data: [<?= $totalPosts ?>, <?= $totalLikes ?>, <?= $totalComments ?>, <?= $acceptedFR ?>, <?= $pendingFR ?>],
            backgroundColor: [C.blue, C.pink, C.purple, C.teal, C.orange],
            borderRadius: 6, borderSkipped: false }]
    },
    options: { responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true, ticks:{stepSize:1,font:{size:11}}, grid:{color:'#f0f0f0'} },
                  x: { ticks:{font:{size:11}}, grid:{display:false} } } }
});

// 4. Friend request status doughnut
new Chart(document.getElementById('frDonut'), {
    type: 'doughnut',
    data: {
        labels: ['Accepted', 'Pending', 'Declined'],
        datasets: [{ data: [<?= $acceptedFR ?>, <?= $pendingFR ?>, <?= $declinedFR ?>],
            backgroundColor: [C.green, C.orange, C.red],
            borderWidth: 2, borderColor: '#fff', hoverOffset: 8 }]
    },
    options: { responsive: true, maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom', labels: { font:{size:12}, padding:16 } } } }
});
</script>

<?php endif; ?>
</body>
</html>