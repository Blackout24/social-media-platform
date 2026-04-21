<?php
// sem2Assignmentlog.php
session_start();
require_once 'sem2Assignmentdatabase.php';

// Allow login even if already logged in (for multiple sessions)
$connect = dbConnect();

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['login'])) {
    $email = $_POST['email'];
    $password = $_POST['password'];

    $statement = $connect->prepare("SELECT id, name, password FROM userdetails WHERE email = ?");
    $statement->bind_param("s", $email);
    $statement->execute();
    $statement->store_result();

    if ($statement->num_rows > 0) {
        $statement->bind_result($userdetails_id, $name, $hashed_password);
        $statement->fetch();

        if (password_verify($password, $hashed_password)) {
            // Override current session with new user
            $_SESSION['userdetails_id'] = $userdetails_id;
            $_SESSION['name'] = $name;
            header("Location: sem2Profile.php");
            exit();
        } else {
            $error = "Invalid email or password.";
        }
    } else {
        $error = "Invalid email or password.";
    }
    $statement->close();
}

$connect->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - SMP</title>
	<link rel="icon" type="image/x-icon" href="SMP_logo-(1).ico">
    <style>
        /*sem2Assignmentlog.php*/
        body {
            font-family: Arial, sans-serif;
            background-color: #f0f2f5;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }
        .login-container {
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
        .info {
            background-color: #d1ecf1;
            color: #0c5460;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 1rem;
            font-size: 14px;
        }
        .info a {
            color: #004085;
            font-weight: bold;
        }
        .links {
            text-align: center;
            margin-top: 1rem;
        }
        .links a {
            color: #1877f2;
            text-decoration: none;
        }
        .links a:hover {
            text-decoration: underline;
        }
        .tip {
            background-color: #fff3cd;
            color: #856404;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 1rem;
            font-size: 12px;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <h1>Welcome to SMP</h1>
        
        <?php if (isset($_SESSION['userdetails_id'])): ?>
            <div class="info">
                Currently logged in as: <strong><?php echo htmlspecialchars($_SESSION['name']); ?></strong><br>
                <small>Logging in below will switch to a different account in this tab.</small>
            </div>
        <?php endif; ?>
        
        <div class="tip">
            💡 Tip: Open a new browser window to login with multiple accounts simultaneously
        </div>
        
        <?php if (isset($error)): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <input type="email" name="email" placeholder="Email" required>
            <input type="password" name="password" placeholder="Password" required>
            <button type="submit" name="login">Login</button>
        </form>
        <div class="links">
            <a href="sem2Assignment.php">Register</a> |
            <a href="sem2Assignment_updatepassword.php">Forgot Password?</a>
        </div>
    </div>
</body>
</html>