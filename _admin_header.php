<?php
if (!isset($page_title)) {
    $page_title = "Admin Panel";
}

if (!isset($page_subtitle)) {
    $page_subtitle = "Smart EV Charging Station Reservation System.";
}

if (!isset($active_page)) {
    $active_page = "";
}
?>

<!DOCTYPE html>
<html>
<head>
    <title><?= htmlspecialchars($page_title) ?></title>
    <link rel="stylesheet" href="../public/style.css">
</head>

<body>

<div class="container">

    <div class="dashboard-header">
        <div>
            <h1><?= htmlspecialchars($page_title) ?></h1>
            <p><?= htmlspecialchars($page_subtitle) ?></p>
        </div>

        <div class="profile-pill">
            <?= htmlspecialchars($_SESSION["full_name"] ?? "Admin") ?>
        </div>
    </div>

    <div class="nav">
        <a class="<?= $active_page === 'dashboard' ? 'active' : '' ?>" href="dashboard.php">Dashboard</a>
        <a class="<?= $active_page === 'checkin' ? 'active' : '' ?>" href="checkin.php">Check-in</a>
        <a class="<?= $active_page === 'reservations' ? 'active' : '' ?>" href="reservations.php">Reservations</a>
        <a class="<?= $active_page === 'stations' ? 'active' : '' ?>" href="stations.php">Stations</a>
        <a class="<?= $active_page === 'charger_types' ? 'active' : '' ?>" href="charger_types.php">Charger Types</a>
        <a class="<?= $active_page === 'slots' ? 'active' : '' ?>" href="slots.php">Slots</a>
        <a class="<?= $active_page === 'reports' ? 'active' : '' ?>" href="reports.php">Reports</a>
        <a href="../auth/logout.php">Logout</a>
    </div>

    <div class="page-content">