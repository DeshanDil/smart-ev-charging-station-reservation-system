<?php
if (!isset($page_title)) {
    $page_title = "User Panel";
}

if (!isset($page_subtitle)) {
    $page_subtitle = "Manage your EV charging reservations.";
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
            <?= htmlspecialchars($_SESSION["full_name"] ?? "EV User") ?>
        </div>
    </div>

    <div class="nav">
        <a class="<?= $active_page === 'dashboard' ? 'active' : '' ?>" href="dashboard.php">Dashboard</a>
        <a class="<?= $active_page === 'reserve' ? 'active' : '' ?>" href="reserve.php">Reserve Slot</a>
        <a class="<?= $active_page === 'my_reservations' ? 'active' : '' ?>" href="my_reservations.php">My Reservations</a>
        <a class="<?= $active_page === 'vehicles' ? 'active' : '' ?>" href="vehicles.php">My Vehicles</a>
        <a class="<?= $active_page === 'stations' ? 'active' : '' ?>" href="stations.php">Stations</a>
        <a href="../auth/logout.php">Logout</a>
    </div>

    <div class="page-content">