<?php
ini_set("display_errors", 1);
ini_set("display_startup_errors", 1);
error_reporting(E_ALL);

session_start();
require_once "../config/db.php";

if (!isset($_SESSION["user_id"]) || $_SESSION["user_role"] !== "Admin") {
    header("Location: ../auth/login.php");
    exit;
}

function singleValue($conn, $sql) {
    $result = $conn->query($sql);
    $row = $result->fetch_row();
    return $row[0] ?? 0;
}

function badgeClass($status) {
    if ($status === "Confirmed") return "badge badge-available";
    if ($status === "Pending") return "badge badge-pending";
    if ($status === "Completed" || $status === "Paid") return "badge badge-paid";
    if ($status === "In Progress" || $status === "Charging") return "badge badge-charging";
    if ($status === "Cancelled" || $status === "No Show") return "badge badge-out";
    return "badge badge-reserved";
}

$total_users = singleValue($conn, "SELECT COUNT(*) FROM users WHERE user_role='User'");
$total_stations = singleValue($conn, "SELECT COUNT(*) FROM charging_stations");
$total_slots = singleValue($conn, "SELECT COUNT(*) FROM charging_slots");
$available_slots = singleValue($conn, "SELECT COUNT(*) FROM charging_slots WHERE slot_status='Available'");
$active_reservations = singleValue($conn, "SELECT COUNT(*) FROM reservations WHERE status IN ('Pending','Confirmed')");
$ongoing_sessions = singleValue($conn, "SELECT COUNT(*) FROM charging_sessions WHERE status='In Progress'");
$today_revenue = singleValue($conn, "SELECT COALESCE(SUM(amount),0) FROM payments WHERE payment_status='Paid' AND DATE(payment_date)=CURDATE()");
$total_revenue = singleValue($conn, "SELECT COALESCE(SUM(amount),0) FROM payments WHERE payment_status='Paid'");

$recent_reservations = $conn->query("
    SELECT
        r.reservation_id,
        r.reservation_date,
        r.start_time,
        r.end_time,
        r.status,
        u.full_name,
        v.vehicle_number,
        st.station_name,
        sl.slot_number
    FROM reservations r
    JOIN users u ON r.user_id = u.user_id
    JOIN vehicles v ON r.vehicle_id = v.vehicle_id
    JOIN charging_slots sl ON r.slot_id = sl.slot_id
    JOIN charging_stations st ON sl.station_id = st.station_id
    ORDER BY r.created_at DESC
    LIMIT 6
");

$page_title = "Admin Dashboard";
$page_subtitle = "Simple control center for reservations, sessions, payments and reports.";
$active_page = "dashboard";
require_once "_admin_header.php";
?>

<h2>Overview</h2>

<div class="cards">
    <div class="card">
        <h3>Users</h3>
        <p><?= $total_users ?></p>
        <small>Registered EV users</small>
    </div>

    <div class="card">
        <h3>Stations</h3>
        <p><?= $total_stations ?></p>
        <small>Charging locations</small>
    </div>

    <div class="card">
        <h3>Slots</h3>
        <p><?= $available_slots ?>/<?= $total_slots ?></p>
        <small>Available / total slots</small>
    </div>

    <div class="card">
        <h3>Today Revenue</h3>
        <p>Rs. <?= number_format($today_revenue, 2) ?></p>
        <small>Total revenue: Rs. <?= number_format($total_revenue, 2) ?></small>
    </div>
</div>

<h2>Operations</h2>

<div class="cards">
    <div class="card">
        <h3>Active Reservations</h3>
        <p><?= $active_reservations ?></p>
        <small>Pending or confirmed tickets</small>
    </div>

    <div class="card">
        <h3>Charging Now</h3>
        <p><?= $ongoing_sessions ?></p>
        <small>Sessions in progress</small>
    </div>
</div>

<h2>Quick Actions</h2>
<p class="dashboard-note">Use these shortcuts for the main admin tasks.</p>

<div class="action-grid">
    <div class="action-card">
        <h3>Ticket Check-in</h3>
        <p>Verify a user ticket and start the charging session.</p>
        <a class="action-btn" href="checkin.php">Open Check-in</a>
    </div>

    <div class="action-card">
        <h3>Reservations</h3>
        <p>Complete charging sessions and record payments.</p>
        <a class="action-btn" href="reservations.php">Manage Reservations</a>
    </div>

    <div class="action-card">
        <h3>Station Setup</h3>
        <p>Manage charging stations, slots and charger types.</p>
        <a class="action-btn secondary" href="stations.php">Manage Stations</a>
    </div>

    <div class="action-card">
        <h3>Reports</h3>
        <p>View revenue, energy usage and station performance.</p>
        <a class="action-btn secondary" href="reports.php">View Reports</a>
    </div>
</div>

<h2>Recent Reservations</h2>

<div class="table-wrapper simple-table">
    <table>
        <tr>
            <th>Ticket</th>
            <th>User</th>
            <th>Vehicle</th>
            <th>Station</th>
            <th>Date</th>
            <th>Time</th>
            <th>Status</th>
            <th>Action</th>
        </tr>

        <?php if ($recent_reservations->num_rows > 0) { ?>
            <?php while ($row = $recent_reservations->fetch_assoc()) { ?>
                <tr>
                    <td>#<?= $row["reservation_id"] ?></td>
                    <td><?= htmlspecialchars($row["full_name"]) ?></td>
                    <td><?= htmlspecialchars($row["vehicle_number"]) ?></td>
                    <td>
                        <?= htmlspecialchars($row["station_name"]) ?><br>
                        <small>Slot <?= htmlspecialchars($row["slot_number"]) ?></small>
                    </td>
                    <td><?= htmlspecialchars($row["reservation_date"]) ?></td>
                    <td>
                        <?= date("h:i A", strtotime($row["start_time"])) ?>
                        -
                        <?= date("h:i A", strtotime($row["end_time"])) ?>
                    </td>
                    <td>
                        <span class="<?= badgeClass($row["status"]) ?>">
                            <?= htmlspecialchars($row["status"]) ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($row["status"] === "Confirmed") { ?>
                            <a href="checkin.php?reservation_id=<?= $row["reservation_id"] ?>">Check-in</a>
                        <?php } else { ?>
                            <a href="reservations.php">View</a>
                        <?php } ?>
                    </td>
                </tr>
            <?php } ?>
        <?php } else { ?>
            <tr>
                <td colspan="8">No recent reservations found.</td>
            </tr>
        <?php } ?>
    </table>
</div>

<?php require_once "_admin_footer.php"; ?>