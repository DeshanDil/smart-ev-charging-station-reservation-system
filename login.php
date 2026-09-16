<?php
session_start();
require_once "../config/db.php";

$error = "";

if (isset($_SESSION["user_id"])) {
    if ($_SESSION["user_role"] === "Admin") {
        header("Location: ../admin/dashboard.php");
        exit;
    } else {
        header("Location: ../user/dashboard.php");
        exit;
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email = trim($_POST["email"]);
    $password = $_POST["password"];

    try {
        $stmt = $conn->prepare("
            SELECT user_id, full_name, email, password, user_role
            FROM users
            WHERE email = ?
            LIMIT 1
        ");

        $stmt->bind_param("s", $email);
        $stmt->execute();

        $user = $stmt->get_result()->fetch_assoc();

        if ($user) {
            $password_ok =
                password_verify($password, $user["password"]) ||
                $password === $user["password"];

            if ($password_ok) {
                $_SESSION["user_id"] = $user["user_id"];
                $_SESSION["full_name"] = $user["full_name"];
                $_SESSION["user_role"] = $user["user_role"];

                if ($user["user_role"] === "Admin") {
                    header("Location: ../admin/dashboard.php");
                    exit;
                } else {
                    header("Location: ../user/dashboard.php");
                    exit;
                }
            } else {
                $error = "Invalid email or password.";
            }
        } else {
            $error = "Invalid email or password.";
        }

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Login - Smart EV Charging</title>
    <link rel="stylesheet" href="../public/style.css">
</head>

<body class="auth-body">

<div class="auth-container">

    <div class="auth-card">

        <div class="auth-brand">
            <div class="auth-logo">⚡</div>
            <h1>Smart EV Charging</h1>
            <p>Login to manage reservations and charging sessions.</p>
        </div>

        <?php if ($error) { ?>
            <p class="error"><?= htmlspecialchars($error) ?></p>
        <?php } ?>

        <form method="POST">
            <label>Email Address</label>
            <input
                type="email"
                name="email"
                placeholder="Enter your email address"
                required
            >

            <label>Password</label>
            <input
                type="password"
                name="password"
                placeholder="Enter your password"
                required
            >

            <button type="submit">Login</button>
        </form>

        <div class="auth-footer">
            No account?
            <a href="register.php">Create new account</a>
        </div>

        

    </div>

</div>

<script src="../public/app.js"></script>

</body>
</html>