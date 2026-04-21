<?php
// sem2Assignmentfriend_request.php
session_start();
require_once 'sem2Assignmentdatabase.php';

if (!isset($_SESSION['userdetails_id'])) {
    header("Location: sem2Assignmentlog.php");
    exit();
}

$connection = dbConnect();
$current_user_id = $_SESSION['userdetails_id'];

// Handle AJAX requests
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'send') {
    $to_id = intval($_POST['to_id']);
    if ($to_id === $current_user_id) { echo json_encode(['success' => false, 'message' => 'Cannot add yourself']); exit(); }

    // Check if already friends or request pending
    $check = $connection->prepare("SELECT id, status FROM friend_requests WHERE (sender_id=? AND receiver_id=?) OR (sender_id=? AND receiver_id=?)");
    $check->bind_param("iiii", $current_user_id, $to_id, $to_id, $current_user_id);
    $check->execute();
    $existing = $check->get_result()->fetch_assoc();
    $check->close();

    if ($existing) {
        echo json_encode(['success' => false, 'message' => 'Request already exists', 'status' => $existing['status']]);
        exit();
    }

    $stmt = $connection->prepare("INSERT INTO friend_requests (sender_id, receiver_id, status) VALUES (?, ?, 'pending')");
    $stmt->bind_param("ii", $current_user_id, $to_id);
    if ($stmt->execute()) {
        // Notify the receiver
        $sender_name = $_SESSION['name'];
        $notif_msg = "$sender_name sent you a friend request";
        $notif = $connection->prepare("INSERT INTO notifications (sender_id, receiver_id, notification, type) VALUES (?, ?, ?, 'friend_request')");
        $notif->bind_param("iis", $current_user_id, $to_id, $notif_msg);
        $notif->execute();
        $notif->close();
        echo json_encode(['success' => true, 'message' => 'Friend request sent!']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error sending request']);
    }
    $stmt->close();

} elseif ($action === 'accept') {
    $request_id = intval($_POST['request_id']);
    $stmt = $connection->prepare("UPDATE friend_requests SET status='accepted' WHERE id=? AND receiver_id=?");
    $stmt->bind_param("ii", $request_id, $current_user_id);
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        // Notify sender
        $req = $connection->prepare("SELECT sender_id FROM friend_requests WHERE id=?");
        $req->bind_param("i", $request_id);
        $req->execute();
        $sender = $req->get_result()->fetch_assoc();
        $req->close();
        if ($sender) {
            $sender_name = $_SESSION['name'];
            $notif_msg = "$sender_name accepted your friend request";
            $notif = $connection->prepare("INSERT INTO notifications (sender_id, receiver_id, notification, type) VALUES (?, ?, ?, 'friend_accept')");
            $notif->bind_param("iis", $current_user_id, $sender['sender_id'], $notif_msg);
            $notif->execute();
            $notif->close();
        }
        echo json_encode(['success' => true, 'message' => 'Friend request accepted!']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Could not accept request']);
    }
    $stmt->close();

} elseif ($action === 'decline') {
    $request_id = intval($_POST['request_id']);
    $stmt = $connection->prepare("UPDATE friend_requests SET status='declined' WHERE id=? AND receiver_id=?");
    $stmt->bind_param("ii", $request_id, $current_user_id);
    $stmt->execute();
    $stmt->close();
    echo json_encode(['success' => true, 'message' => 'Request declined']);

} elseif ($action === 'unfriend') {
    $other_id = intval($_POST['other_id']);
    $stmt = $connection->prepare("DELETE FROM friend_requests WHERE ((sender_id=? AND receiver_id=?) OR (sender_id=? AND receiver_id=?)) AND status='accepted'");
    $stmt->bind_param("iiii", $current_user_id, $other_id, $other_id, $current_user_id);
    $stmt->execute();
    $stmt->close();
    echo json_encode(['success' => true, 'message' => 'Unfriended']);

} elseif ($action === 'status') {
    $other_id = intval($_GET['other_id']);
    $stmt = $connection->prepare("SELECT id, sender_id, receiver_id, status FROM friend_requests WHERE (sender_id=? AND receiver_id=?) OR (sender_id=? AND receiver_id=?)");
    $stmt->bind_param("iiii", $current_user_id, $other_id, $other_id, $current_user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        echo json_encode(['status' => 'none']);
    } else {
        $is_sender = ($row['sender_id'] == $current_user_id);
        echo json_encode(['status' => $row['status'], 'request_id' => $row['id'], 'is_sender' => $is_sender]);
    }
} elseif ($action === 'list_requests') {
    // Incoming pending requests for current user
    $stmt = $connection->prepare("SELECT fr.id, fr.sender_id, u.name, u.profilePic, fr.created_at FROM friend_requests fr JOIN userdetails u ON u.id=fr.sender_id WHERE fr.receiver_id=? AND fr.status='pending' ORDER BY fr.created_at DESC");
    $stmt->bind_param("i", $current_user_id);
    $stmt->execute();
    $requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    echo json_encode(['requests' => $requests]);
} elseif ($action === 'list_friends') {
    $stmt = $connection->prepare("SELECT u.id, u.name, u.profilePic FROM friend_requests fr JOIN userdetails u ON u.id = CASE WHEN fr.sender_id=? THEN fr.receiver_id ELSE fr.sender_id END WHERE (fr.sender_id=? OR fr.receiver_id=?) AND fr.status='accepted'");
    $stmt->bind_param("iii", $current_user_id, $current_user_id, $current_user_id);
    $stmt->execute();
    $friends = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    echo json_encode(['friends' => $friends]);
}

$connection->close();
?>