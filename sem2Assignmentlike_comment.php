<?php
// sem2Assignmentlike_comment.php
session_start();
require_once 'sem2Assignmentdatabase.php';

if (!isset($_SESSION['userdetails_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

header('Content-Type: application/json');

$connection = dbConnect();
$current_user_id = $_SESSION['userdetails_id'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'toggle_like') {
    $post_id = intval($_POST['post_id']);

    // Check if already liked
    $check = $connection->prepare("SELECT id FROM post_likes WHERE post_id=? AND user_id=?");
    $check->bind_param("ii", $post_id, $current_user_id);
    $check->execute();
    $existing = $check->get_result()->fetch_assoc();
    $check->close();

    if ($existing) {
        // Unlike
        $del = $connection->prepare("DELETE FROM post_likes WHERE post_id=? AND user_id=?");
        $del->bind_param("ii", $post_id, $current_user_id);
        $del->execute();
        $del->close();
        $liked = false;
    } else {
        // Like
        $ins = $connection->prepare("INSERT INTO post_likes (post_id, user_id) VALUES (?, ?)");
        $ins->bind_param("ii", $post_id, $current_user_id);
        $ins->execute();
        $ins->close();
        $liked = true;

        // Notify post owner (not self-likes)
        $owner = $connection->prepare("SELECT userdetails_id FROM attachments WHERE id=?");
        $owner->bind_param("i", $post_id);
        $owner->execute();
        $owner_row = $owner->get_result()->fetch_assoc();
        $owner->close();

        if ($owner_row && $owner_row['userdetails_id'] != $current_user_id) {
            $sender_name = $_SESSION['name'];
            $notif_msg = "$sender_name liked your post";
            $notif = $connection->prepare("INSERT INTO notifications (sender_id, receiver_id, notification, type, ref_id) VALUES (?, ?, ?, 'like', ?)");
            $notif->bind_param("iisi", $current_user_id, $owner_row['userdetails_id'], $notif_msg, $post_id);
            $notif->execute();
            $notif->close();
        }
    }

    // Get updated count
    $cnt = $connection->prepare("SELECT COUNT(*) as cnt FROM post_likes WHERE post_id=?");
    $cnt->bind_param("i", $post_id);
    $cnt->execute();
    $count = $cnt->get_result()->fetch_assoc()['cnt'];
    $cnt->close();

    echo json_encode(['success' => true, 'liked' => $liked, 'count' => $count]);

} elseif ($action === 'get_likes') {
    $post_id = intval($_GET['post_id']);
    $cnt = $connection->prepare("SELECT COUNT(*) as cnt FROM post_likes WHERE post_id=?");
    $cnt->bind_param("i", $post_id);
    $cnt->execute();
    $count = $cnt->get_result()->fetch_assoc()['cnt'];
    $cnt->close();

    $check = $connection->prepare("SELECT id FROM post_likes WHERE post_id=? AND user_id=?");
    $check->bind_param("ii", $post_id, $current_user_id);
    $check->execute();
    $liked = $check->get_result()->num_rows > 0;
    $check->close();

    echo json_encode(['count' => $count, 'liked' => $liked]);

} elseif ($action === 'add_comment') {
    $post_id = intval($_POST['post_id']);
    $comment_text = trim($_POST['comment']);

    if (empty($comment_text)) {
        echo json_encode(['success' => false, 'message' => 'Comment cannot be empty']);
        exit();
    }

    $ins = $connection->prepare("INSERT INTO post_comments (post_id, user_id, comment) VALUES (?, ?, ?)");
    $ins->bind_param("iis", $post_id, $current_user_id, $comment_text);
    if ($ins->execute()) {
        $comment_id = $connection->insert_id;
        $ins->close();

        // Get comment data
        $fetch = $connection->prepare("SELECT pc.id, pc.comment, pc.created_at, u.name, u.profilePic FROM post_comments pc JOIN userdetails u ON u.id=pc.user_id WHERE pc.id=?");
        $fetch->bind_param("i", $comment_id);
        $fetch->execute();
        $comment_data = $fetch->get_result()->fetch_assoc();
        $fetch->close();

        // Notify post owner
        $owner = $connection->prepare("SELECT userdetails_id FROM attachments WHERE id=?");
        $owner->bind_param("i", $post_id);
        $owner->execute();
        $owner_row = $owner->get_result()->fetch_assoc();
        $owner->close();

        if ($owner_row && $owner_row['userdetails_id'] != $current_user_id) {
            $sender_name = $_SESSION['name'];
            $notif_msg = "$sender_name commented on your post: " . (strlen($comment_text) > 40 ? substr($comment_text, 0, 40) . '...' : $comment_text);
            $notif = $connection->prepare("INSERT INTO notifications (sender_id, receiver_id, notification, type, ref_id) VALUES (?, ?, ?, 'comment', ?)");
            $notif->bind_param("iisi", $current_user_id, $owner_row['userdetails_id'], $notif_msg, $post_id);
            $notif->execute();
            $notif->close();
        }

        echo json_encode(['success' => true, 'comment' => $comment_data]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error saving comment']);
    }

} elseif ($action === 'get_comments') {
    $post_id = intval($_GET['post_id']);
    $stmt = $connection->prepare("SELECT pc.id, pc.comment, pc.created_at, pc.user_id, u.name, u.profilePic FROM post_comments pc JOIN userdetails u ON u.id=pc.user_id WHERE pc.post_id=? ORDER BY pc.created_at ASC");
    $stmt->bind_param("i", $post_id);
    $stmt->execute();
    $comments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    echo json_encode(['comments' => $comments]);

} elseif ($action === 'delete_comment') {
    $comment_id = intval($_POST['comment_id']);
    // Only delete own comments
    $stmt = $connection->prepare("DELETE FROM post_comments WHERE id=? AND user_id=?");
    $stmt->bind_param("ii", $comment_id, $current_user_id);
    $stmt->execute();
    $deleted = $stmt->affected_rows > 0;
    $stmt->close();
    echo json_encode(['success' => $deleted]);
}

$connection->close();
?>