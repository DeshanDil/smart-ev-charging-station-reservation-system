<?php
session_start();
require_once "../config/db.php";

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $full_name = trim($_POST["full_name"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $contact_number = trim($_POST["contact_number"] ?? "");
    $password = $_POST["password"] ?? "";
    $confirm_password = $_POST["confirm_password"] ?? "";

    if ($full_name === "" || $email === "" || $contact_number === "" || $password === "") {
        $error = "Please fill all required fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match.";
    } elseif (strlen($password) < 4) {
        $error = "Password must contain at least 4 characters.";
    } else {
        try {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $conn->prepare("
                INSERT INTO users
                (
                    full_name,
                    email,
                    password,
                    contact_number,
                    user_role
                )
                VALUES
                (?, ?, ?, ?, 'User')
            ");

            $stmt->bind_param(
                "ssss",
                $full_name,
                $email,
                $hashed_password,
                $contact_number
            );

            $stmt->execute();

            header("Location: login.php?registered=1");
            exit;

        } catch (Exception $e) {
            if (strpos($e->getMessage(), "Duplicate") !== false) {
                $error = "This email address is already registered.";
            } else {
                $error = $e->getMessage();
            }
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Create Account - Smart EV Charging</title>
    <link rel="stylesheet" href="../public/style.css">
</head>

<body class="auth-body">

<div class="auth-container">

    <div class="auth-card">

        <div class="auth-brand">
            <div class="auth-logo">⚡</div>
            <h1>Create Account</h1>
            <p>Register as an EV user and reserve smart charging slots easily.</p>
        </div>

        <?php if ($error) { ?>
            <p class="error"><?= htmlspecialchars($error) ?></p>
        <?php } ?>

        <form method="POST">

            <label>Full Name</label>
            <input
                type="text"
                name="full_name"
                placeholder="Enter your full name"
                required
            >

            <label>Email Address</label>
            <input
                type="email"
                name="email"
                placeholder="Enter your email address"
                required
            >

            <label>Contact Number</label>
            <input
                type="text"
                name="contact_number"
                placeholder="Enter your contact number"
                required
            >

            <label>Password</label>
            <input
                type="password"
                name="password"
                placeholder="Create password"
                required
            >

            <label>Confirm Password</label>
            <input
                type="password"
                name="confirm_password"
                placeholder="Confirm password"
                required
            >

            <button type="submit">Create Account</button>

        </form>

        <div class="auth-footer">
            Already have an account?
            <a href="login.php">Login</a>
        </div>

    </div>

</div>

<script src="../public/app.js"></script>

</body>
</html>