<?php
ini_set("display_errors", 1);
ini_set("display_startup_errors", 1);
error_reporting(E_ALL);

session_start();
require_once "config/db.php";

$is_logged_in = isset($_SESSION["user_id"]);
$user_role = $_SESSION["user_role"] ?? "";

$dashboard_link = "";

if ($is_logged_in && $user_role === "Admin") {
    $dashboard_link = "admin/dashboard.php";
} elseif ($is_logged_in && $user_role === "User") {
    $dashboard_link = "user/dashboard.php";
}

$stations = $conn->query("
    SELECT
        st.station_id,
        st.station_name,
        st.location,
        st.contact_number,
        COUNT(sl.slot_id) AS total_slots,
        COALESCE(SUM(CASE WHEN sl.slot_status = 'Available' THEN 1 ELSE 0 END), 0) AS available_slots,
        COALESCE(SUM(CASE WHEN sl.slot_status = 'Reserved' THEN 1 ELSE 0 END), 0) AS reserved_slots,
        COALESCE(SUM(CASE WHEN sl.slot_status = 'Charging' THEN 1 ELSE 0 END), 0) AS charging_slots,
        COALESCE(SUM(CASE WHEN sl.slot_status = 'Out of Service' THEN 1 ELSE 0 END), 0) AS out_slots
    FROM charging_stations st
    LEFT JOIN charging_slots sl ON st.station_id = sl.station_id
    GROUP BY
        st.station_id,
        st.station_name,
        st.location,
        st.contact_number
    ORDER BY st.station_name
");

$slot_details = $conn->query("
    SELECT
        st.station_name,
        st.location,
        sl.slot_number,
        sl.slot_status,
        ct.type_name,
        ct.connector_type,
        ct.power_output_kw,
        ct.rate_per_kwh
    FROM charging_slots sl
    JOIN charging_stations st ON sl.station_id = st.station_id
    JOIN charger_types ct ON sl.type_id = ct.type_id
    ORDER BY st.station_name, sl.slot_number
");

function badgeClass($status) {
    if ($status === "Available") return "badge badge-available";
    if ($status === "Reserved") return "badge badge-reserved";
    if ($status === "Charging") return "badge badge-charging";
    if ($status === "Out of Service") return "badge badge-out";

    return "badge badge-pending";
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Smart EV Charging System</title>
    <link rel="stylesheet" href="public/style.css">
</head>

<body>

<div class="container">

    <div class="dashboard-header">
        <div>
            <h1>Smart EV Charging Station</h1>
            <p>
                View charging station availability, charger types and slot status.
                Registered users can reserve charging slots using smart time calculation.
            </p>
        </div>

        <div class="profile-pill">
            <?php if ($is_logged_in) { ?>
                <?= htmlspecialchars($_SESSION["full_name"] ?? $user_role) ?>
            <?php } else { ?>
                Guest View
            <?php } ?>
        </div>
    </div>

    <div class="nav">
        <a class="active" href="index.php">Home</a>

        <?php if ($is_logged_in && $dashboard_link !== "") { ?>
            <a href="<?= $dashboard_link ?>">Dashboard</a>
        <?php } ?>

        <?php if ($is_logged_in && $user_role === "User") { ?>
            <a href="user/reserve.php">Reserve Slot</a>
            <a href="user/my_reservations.php">My Reservations</a>
            <a href="user/vehicles.php">My Vehicles</a>
        <?php } ?>

        <?php if ($is_logged_in && $user_role === "Admin") { ?>
            <a href="admin/checkin.php">Check-in</a>
            <a href="admin/reservations.php">Reservations</a>
            <a href="admin/reports.php">Reports</a>
        <?php } ?>

        <?php if (!$is_logged_in) { ?>
            <a href="auth/login.php">Login</a>
            <a href="auth/register.php">Create Account</a>
        <?php } else { ?>
            <a href="auth/logout.php">Logout</a>
        <?php } ?>
    </div>

    <div class="page-content">

        <div class="recommend-box">
            <h3>Welcome to the EV Charging Reservation System</h3>

            <?php if (!$is_logged_in) { ?>
                <p>
                    You are viewing as a guest. You can check station availability,
                    but you need to create an account or login to reserve a charging slot.
                </p>

                <div class="page-actions">
                    <a class="action-btn" href="auth/login.php">Login</a>
                    <a class="action-btn secondary" href="auth/register.php">Create Account</a>
                </div>
            <?php } elseif ($user_role === "User") { ?>
                <p>
                    You are logged in as a user. You can view stations, manage your vehicles
                    and reserve a charging slot.
                </p>

                <div class="page-actions">
                    <a class="action-btn" href="user/reserve.php">Reserve Charging Slot</a>
                    <a class="action-btn secondary" href="user/dashboard.php">Go to Dashboard</a>
                </div>
            <?php } elseif ($user_role === "Admin") { ?>
                <p>
                    You are logged in as admin. You can manage stations, reservations,
                    charging sessions, payments and reports.
                </p>

                <div class="page-actions">
                    <a class="action-btn" href="admin/dashboard.php">Admin Dashboard</a>
                    <a class="action-btn secondary" href="admin/reservations.php">Manage Reservations</a>
                </div>
            <?php } ?>
        </div>

        <h2>Charging Station Overview</h2>

        <div class="cards">
            <?php if ($stations->num_rows > 0) { ?>
                <?php while ($row = $stations->fetch_assoc()) { ?>
                    <div class="card">
                        <h3><?= htmlspecialchars($row["station_name"]) ?></h3>

                        <p>
                            <?= intval($row["available_slots"]) ?>
                            /
                            <?= intval($row["total_slots"]) ?>
                        </p>

                        <small>
                            <?= htmlspecialchars($row["location"]) ?><br>
                            Available / Total Slots<br>
                            Contact:
                            <?= htmlspecialchars($row["contact_number"] ?: "N/A") ?>
                        </small>
                    </div>
                <?php } ?>
            <?php } else { ?>
                <div class="card">
                    <h3>No Stations</h3>
                    <p>0</p>
                    <small>No charging stations available yet.</small>
                </div>
            <?php } ?>
        </div>

        <h2>Charging Slot Availability</h2>

        <div class="table-wrapper">
            <table>
                <tr>
                    <th>Station</th>
                    <th>Location</th>
                    <th>Slot</th>
                    <th>Charger Type</th>
                    <th>Connector</th>
                    <th>Power</th>
                    <th>Rate</th>
                    <th>Status</th>
                </tr>

                <?php if ($slot_details->num_rows > 0) { ?>
                    <?php while ($row = $slot_details->fetch_assoc()) { ?>
                        <tr>
                            <td><?= htmlspecialchars($row["station_name"]) ?></td>

                            <td><?= htmlspecialchars($row["location"]) ?></td>

                            <td><?= htmlspecialchars($row["slot_number"]) ?></td>

                            <td><?= htmlspecialchars($row["type_name"]) ?></td>

                            <td><?= htmlspecialchars($row["connector_type"]) ?></td>

                            <td><?= number_format($row["power_output_kw"], 2) ?> kW</td>

                            <td>
                                Rs. <?= number_format($row["rate_per_kwh"], 2) ?>/kWh
                            </td>

                            <td>
                                <span class="<?= badgeClass($row["slot_status"]) ?>">
                                    <?= htmlspecialchars($row["slot_status"]) ?>
                                </span>
                            </td>
                        </tr>
                    <?php } ?>
                <?php } else { ?>
                    <tr>
                        <td colspan="8">No charging slots found.</td>
                    </tr>
                <?php } ?>
            </table>
        </div>

    </div>

</div>

<script src="public/app.js"></script>

</body>
</html>