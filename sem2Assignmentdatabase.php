<?php
// sem2Assignmentdatabase.php
// This file handles database setup and provides connection function

$servName = "localhost";
$username = "root";
$password = "";
$connection = new mysqli($servName, $username, $password);

// Check connection
if ($connection->connect_error) {
    die("Connection failed: " . $connection->connect_error);
}

// Create database if it doesn't exist
$sqlquery = "CREATE DATABASE IF NOT EXISTS socialNet";
if ($connection->query($sqlquery) === FALSE) {
    die("Error creating database: " . $connection->error);
}

// Select the database
$connection->select_db("socialNet");

// Create all tables
$sqlquery = "
CREATE TABLE IF NOT EXISTS userdetails (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    profilePic VARCHAR(255) DEFAULT 'default_profile.png',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS attachments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    userdetails_id INT NOT NULL,
    content TEXT NOT NULL,
    picture VARCHAR(255),
    video VARCHAR(255),
    is_flagged TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (userdetails_id) REFERENCES userdetails(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sender_id INT NOT NULL,
    receiver_id INT NOT NULL,
    notification TEXT NOT NULL,
    type VARCHAR(50) DEFAULT 'message',
    ref_id INT DEFAULT NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sender_id) REFERENCES userdetails(id) ON DELETE CASCADE,
    FOREIGN KEY (receiver_id) REFERENCES userdetails(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sender_id INT NOT NULL,
    receiver_id INT NOT NULL,
    message TEXT NOT NULL,
    is_deleted BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sender_id) REFERENCES userdetails(id) ON DELETE CASCADE,
    FOREIGN KEY (receiver_id) REFERENCES userdetails(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS message_attachments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    message_id INT NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_type VARCHAR(50) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS friend_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sender_id INT NOT NULL,
    receiver_id INT NOT NULL,
    status ENUM('pending','accepted','declined') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sender_id) REFERENCES userdetails(id) ON DELETE CASCADE,
    FOREIGN KEY (receiver_id) REFERENCES userdetails(id) ON DELETE CASCADE,
    UNIQUE KEY unique_pair (sender_id, receiver_id)
);

CREATE TABLE IF NOT EXISTS post_likes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    post_id INT NOT NULL,
    user_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (post_id) REFERENCES attachments(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES userdetails(id) ON DELETE CASCADE,
    UNIQUE KEY unique_like (post_id, user_id)
);

CREATE TABLE IF NOT EXISTS post_comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    post_id INT NOT NULL,
    user_id INT NOT NULL,
    comment TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (post_id) REFERENCES attachments(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES userdetails(id) ON DELETE CASCADE
);";

// Execute multiple queries
if ($connection->multi_query($sqlquery) === TRUE) {
    // Clear all result sets
    do {
        if ($result = $connection->store_result()) {
            $result->free();
        }
    } while ($connection->more_results() && $connection->next_result());
} else {
    die("Error creating tables: " . $connection->error);
}

// Run ALTER TABLE statements to add new columns to existing tables (safe if columns already exist)
$alters = [
    "ALTER TABLE notifications ADD COLUMN IF NOT EXISTS type VARCHAR(50) DEFAULT 'message'",
    "ALTER TABLE notifications ADD COLUMN IF NOT EXISTS ref_id INT DEFAULT NULL",
    "ALTER TABLE notifications ADD COLUMN IF NOT EXISTS is_read TINYINT(1) DEFAULT 0",
    "ALTER TABLE attachments ADD COLUMN IF NOT EXISTS is_flagged TINYINT(1) DEFAULT 0",
];
foreach ($alters as $sql) {
    $connection->query($sql);
}

$connection->close();

// Helper function for profile pictures
function getProfilePicPath($profilePic) {
    if (empty($profilePic) || $profilePic === 'default_profile.png') {
        return 'default_profile.png';
    }
    $uploadPath = 'uploads/' . $profilePic;
    if (file_exists($uploadPath)) {
        return $uploadPath;
    } else {
        return 'default_profile.png';
    }
}

// Connection function to be used in other files
function dbConnect() {
    $servName = "localhost";
    $username = "root";
    $password = "";
    $databaseName = "socialNet";
   
    $connection = new mysqli($servName, $username, $password, $databaseName);
   
    if ($connection->connect_error) {
        die("Connection failed: " . $connection->connect_error);
    }
   
    return $connection;
}
?>