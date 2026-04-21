<?php
// sem2Assignmentclearnotifications.php
session_start();
require_once 'sem2Assignmentdatabase.php';

if (!isset($_SESSION['userdetails_id'])) {
    header("Location: sem2Assignmentlog.php");
    exit();
}

$connection = dbConnect();
$userdetails_id = $_SESSION['userdetails_id'];

// Delete all notifications for the current user
$statement = $connection->prepare("DELETE FROM notifications WHERE receiver_id = ?");
$statement->bind_param("i", $userdetails_id);
$statement->execute();
$statement->close();

$connection->close();

// Redirect back to notifications page
header("Location: sem2Assignmentnotifications.php");
exit();
?>