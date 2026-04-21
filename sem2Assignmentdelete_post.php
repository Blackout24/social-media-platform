<?php
// sem2Assignmentdelete_post.php
session_start();
require_once 'sem2Assignmentdatabase.php';

if (!isset($_SESSION['userdetails_id'])) {
    header("Location: sem2Assignmentlog.php");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['post_id'])) {
    $post_id = intval($_POST['post_id']);
    $user_id = $_SESSION['userdetails_id'];
    
    $connection = dbConnect();
    
    // Verify the post belongs to the current user before deleting
    $verify_stmt = $connection->prepare("SELECT id, picture, video FROM attachments WHERE id = ? AND userdetails_id = ?");
    $verify_stmt->bind_param("ii", $post_id, $user_id);
    $verify_stmt->execute();
    $result = $verify_stmt->get_result();
    
    if ($result->num_rows > 0) {
        $post = $result->fetch_assoc();
        
        // Delete associated files if they exist
        if (!empty($post['picture']) && file_exists("uploads/" . $post['picture'])) {
            unlink("uploads/" . $post['picture']);
        }
        
        if (!empty($post['video']) && file_exists("uploads/" . $post['video'])) {
            unlink("uploads/" . $post['video']);
        }
        
        // Delete the post from database
        $delete_stmt = $connection->prepare("DELETE FROM attachments WHERE id = ? AND userdetails_id = ?");
        $delete_stmt->bind_param("ii", $post_id, $user_id);
        
        if ($delete_stmt->execute()) {
            $_SESSION['delete_success'] = "Post deleted successfully.";
        } else {
            $_SESSION['delete_error'] = "Error deleting post: " . $connection->error;
        }
        $delete_stmt->close();
    } else {
        $_SESSION['delete_error'] = "Post not found or you don't have permission to delete it.";
    }
    
    $verify_stmt->close();
    $connection->close();
}

// Redirect back to profile page
header("Location: sem2Profile.php");
exit();
?>