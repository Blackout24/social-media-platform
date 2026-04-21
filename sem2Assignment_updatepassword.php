<?php
// sem2Assignment_updatepassword.php
session_start();
require_once 'sem2AssignmentDatabase.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = $_POST['email'];
    $new_password = password_hash($_POST['new_password'], PASSWORD_DEFAULT);

    $connection = dbConnect();
    $statement = $connection->prepare("SELECT id FROM userdetails WHERE email = ?");
    $statement->bind_param("s", $email);
    $statement->execute();
    $statement->store_result();

    if ($statement->num_rows > 0) {
        $statement->close();
        $statement = $connection->prepare("UPDATE userdetails SET password = ? WHERE email = ?");
        $statement->bind_param("ss", $new_password, $email);
        $statement->execute();
        $statement->close();

        $message = "Your password has been reset successfully!";
    } else {
        $error = "No user found with this email.";
    }
    $connection->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - SMP</title>
	<link rel="icon" type="image/x-icon" href="SMP_logo-(1).ico">
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f0f2f5;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }
        .reset-container {
            background-color: white;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            width: 300px;
        }
        h1 {
            color: #1877f2;
            text-align: center;
        }
        form {
            display: flex;
            flex-direction: column;
        }
        input {
            margin-bottom: 1rem;
            padding: 0.5rem;
            border: 1px solid #ddd;
            border-radius: 5px;
        }
        button {
            background-color: #1877f2;
            color: white;
            padding: 0.5rem;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }
        button:hover {
            background-color: #166fe5;
        }
        .error {
            color: red;
            margin-bottom: 1rem;
        }
        .success {
            color: green;
            margin-bottom: 1rem;
        }
    </style>
</head>
<body>
    <div class="reset-container">
        <h1>Reset Your Password</h1>
        <form method="POST">
            <input type="email" name="email" placeholder="Email" required>
            <input type="password" name="new_password" placeholder="New Password" required>
            <button type="submit">Reset Password</button>
        </form>
        <?php if (isset($error)) echo "<p class='error'>$error</p>"; ?>
        <?php if (isset($message)) echo "<p class='success'>$message</p>"; ?>
        <p>Remember your password? <a href="sem2Assignmentlog.php">Login</a></p>
    </div>
    </body>
    </html>
