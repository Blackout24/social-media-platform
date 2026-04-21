<?php
// sem2AssignmentLogout.php
session_start();
session_unset(); // Clear all session variables
session_destroy(); 

// Redirect to login page
header("Location: sem2Assignmentlog.php");
exit();
?>
