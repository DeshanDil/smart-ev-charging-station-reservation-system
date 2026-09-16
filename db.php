<?php

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = "localhost";
$username = "root";
$password = "";
$database = "ev_charging_system";

try {
    $conn = new mysqli($host, $username, $password, $database);
    $conn->set_charset("utf8mb4");

    // Sri Lanka time for correct reservation check-in validation
    $conn->query("SET time_zone = '+05:30'");
    date_default_timezone_set("Asia/Colombo");

} catch (Exception $e) {
    die("Database connection failed: " . $e->getMessage());
}
?>