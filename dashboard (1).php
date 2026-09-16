<?php
ini_set("display_errors", 1);
ini_set("display_startup_errors", 1);
error_reporting(E_ALL);

session_start();
require_once "../config/db.php";

if (!isset($_SESSION["user_id"]) || $_SESSION["user_role"] !== "User") {
    header("Location: ../auth/login.php");
    exit;
}

$user_id = $_SESSION["user_id"];

function singleValue($conn, $sql, $user_id) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_row();
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

$total_vehicles = singleValue(
    $conn,
    "SELECT COUNT(*) FROM vehicles WHERE user_id=?",
    $user_id
);

$active_reservations = singleValue(
    $conn,
    "SELECT COUNT(*) FROM reservations WHERE user_id=? AND status IN ('Pending','Confirmed')",
    $user_id
);

$completed_reservations = singleValue(
    $conn,
    "SELECT COUNT(*) FROM reservations WHERE user_id=? AND status='Completed'",
    $user_id
);

$total_paid = singleValue(
    $conn,
    "
    SELECT COALESCE(SUM(p.amount),0)
    FROM payments p
    JOIN charging_sessions s ON p.session_id = s.session_id
    JOIN reservations r ON s.reservation_id = r.reservation_id
    WHERE r.user_id=? AND p.payment_status='Paid'
    ",
    $user_id
);

/* Active ticket */
$stmt = $conn->prepare("
    SELECT
        r.reservation_id,
        r.reservation_date,
        r.start_time,
        r.end_time,
        r.status,
        r.current_battery_percent,
        r.target_battery_percent,
        r.estimated_duration_min,
        r.estimated_cost,

        v.vehicle_number,
        v.vehicle_model,

        st.station_name,
        st.location,

        sl.slot_number,

        ct.type_name,
        ct.connector_type
    FROM reservations r
    JOIN vehicles v ON r.vehicle_id = v.vehicle_id
    JOIN charging_slots sl ON r.slot_id = sl.slot_id
    JOIN charging_stations st ON sl.station_id = st.station_id
    JOIN charger_types ct ON sl.type_id = ct.type_id
    WHERE r.user_id = ?
      AND r.status IN ('Pending','Confirmed')
    ORDER BY r.created_at DESC
    LIMIT 1
");

$stmt->bind_param("i", $user_id);
$stmt->execute();
$ticket = $stmt->get_result()->fetch_assoc();

/* Recent reservations */
$stmt = $conn->prepare("
    SELECT
        r.reservation_id,
        r.reservation_date,
        r.start_time,
        r.end_time,
        r.status,

        v.vehicle_number,

        st.station_name,

        sl.slot_number,

        p.payment_id
    FROM reservations r
    JOIN vehicles v ON r.vehicle_id = v.vehicle_id
    JOIN charging_slots sl ON r.slot_id = sl.slot_id
    JOIN charging_stations st ON sl.station_id = st.station_id
    LEFT JOIN charging_sessions s ON r.reservation_id = s.reservation_id
    LEFT JOIN payments p ON s.session_id = p.session_id
    WHERE r.user_id = ?
    ORDER BY r.created_at DESC
    LIMIT 6
");

$stmt->bind_param("i", $user_id);
$stmt->execute();
$recent_reservations = $stmt->get_result();

$page_title = "User Dashboard";
$page_subtitle = "Manage your EV charging reservations and digital tickets.";
$active_page = "dashboard";
require_once "_user_header.php";
?>

<?php if (isset($_GET["reservation"]) && $_GET["reservation"] === "success") { ?>
    <p class="success">
        Reservation created successfully. Your active ticket is shown below.
    </p>
<?php } ?>

<h2>My Summary</h2>

<div class="cards">
    <div class="card">
        <h3>Vehicles</h3>
        <p><?= $total_vehicles ?></p>
        <small>Registered EV vehicles</small>
    </div>

    <div class="card">
        <h3>Active Tickets</h3>
        <p><?= $active_reservations ?></p>
        <small>Pending or confirmed reservations</small>
    </div>

    <div class="card">
        <h3>Completed</h3>
        <p><?= $completed_reservations ?></p>
        <small>Finished charging sessions</small>
    </div>

    <div class="card">
        <h3>Total Paid</h3>
        <p>Rs. <?= number_format($total_paid, 2) ?></p>
        <small>Completed payments</small>
    </div>
</div>

<h2>Quick Actions</h2>

<div class="action-grid">
    <div class="action-card">
        <h3>Reserve Charging Slot</h3>
        <p>Find available chargers and reserve with smart time calculation.</p>
        <a class="action-btn" href="reserve.php">Reserve Now</a>
    </div>

    <div class="action-card">
        <h3>My Reservations</h3>
        <p>View all tickets, payment status and receipts.</p>
        <a class="action-btn secondary" href="my_reservations.php">View Reservations</a>
    </div>

    <div class="action-card">
        <h3>My Vehicles</h3>
        <p>Add or manage your registered EV vehicles.</p>
        <a class="action-btn secondary" href="vehicles.php">Manage Vehicles</a>
    </div>

    <div class="action-card">
        <h3>Charging Stations</h3>
        <p>View station locations, charger types and current slot availability.</p>
        <a class="action-btn secondary" href="stations.php">View Stations</a>
    </div>
</div>

<h2>Active Digital Ticket</h2>

<?php if ($ticket) { ?>
    <?php $is_overnight = $ticket["end_time"] <= $ticket["start_time"]; ?>

    <div class="recommend-box">
        <h3>Reservation Ticket #<?= $ticket["reservation_id"] ?></h3>

        <div class="cards">
            <div class="card">
                <h3>Status</h3>
                <p>
                    <span class="<?= badgeClass($ticket["status"]) ?>">
                        <?= htmlspecialchars($ticket["status"]) ?>
                    </span>
                </p>
                <small>Your current active reservation</small>
            </div>

            <div class="card">
                <h3>Vehicle</h3>
                <p><?= htmlspecialchars($ticket["vehicle_number"]) ?></p>
                <small><?= htmlspecialchars($ticket["vehicle_model"]) ?></small>
            </div>

            <div class="card">
                <h3>Station</h3>
                <p><?= htmlspecialchars($ticket["station_name"]) ?></p>
                <small><?= htmlspecialchars($ticket["location"]) ?></small>
            </div>

            <div class="card">
                <h3>Slot</h3>
                <p><?= htmlspecialchars($ticket["slot_number"]) ?></p>
                <small>
                    <?= htmlspecialchars($ticket["type_name"]) ?> |
                    <?= htmlspecialchars($ticket["connector_type"]) ?>
                </small>
            </div>
        </div>

        <div class="table-wrapper simple-table">
            <table>
                <tr>
                    <th>Date</th>
                    <th>Time</th>
                    <th>Battery</th>
                    <th>Duration</th>
                    <th>Estimated Cost</th>
                </tr>

                <tr>
                    <td>
                        <?= htmlspecialchars($ticket["reservation_date"]) ?>

                        <?php if ($is_overnight) { ?>
                            <br>
                            <span class="badge badge-charging">Ends Next Day</span>
                        <?php } ?>
                    </td>

                    <td>
                        <?= date("h:i A", strtotime($ticket["start_time"])) ?>
                        -
                        <?= date("h:i A", strtotime($ticket["end_time"])) ?>
                    </td>

                    <td>
                        <?= number_format($ticket["current_battery_percent"], 2) ?>%
                        →
                        <?= number_format($ticket["target_battery_percent"], 2) ?>%
                    </td>

                    <td>
                        <?= $ticket["estimated_duration_min"] ?> minutes
                    </td>

                    <td>
                        <strong>
                            Rs. <?= number_format($ticket["estimated_cost"], 2) ?>
                        </strong>
                    </td>
                </tr>
            </table>
        </div>
    </div>

<?php } else { ?>

    <div class="recommend-box">
        <h3>No Active Ticket</h3>
        <p>
            You do not have an active reservation.
            Reserve a charging slot to generate a digital ticket.
        </p>

        <div class="page-actions">
            <a class="action-btn" href="reserve.php">Reserve Charging Slot</a>
            <a class="action-btn secondary" href="stations.php">View Stations</a>
        </div>
    </div>

<?php } ?>

<h2>Recent Reservations</h2>

<div class="table-wrapper simple-table">
    <table>
        <tr>
            <th>Ticket</th>
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

                    <td><?= htmlspecialchars($row["vehicle_number"]) ?></td>

                    <td>
                        <?= htmlspecialchars($row["station_name"]) ?><br>
                        <small>
                            Slot <?= htmlspecialchars($row["slot_number"]) ?>
                        </small>
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
                        <?php if ($row["payment_id"]) { ?>
                            <a href="payment_receipt.php?reservation_id=<?= $row["reservation_id"] ?>">
                                Receipt
                            </a>
                        <?php } else { ?>
                            <a href="my_reservations.php">Details</a>
                        <?php } ?>
                    </td>
                </tr>
            <?php } ?>
        <?php } else { ?>
            <tr>
                <td colspan="7">No reservations found.</td>
            </tr>
        <?php } ?>
    </table>
</div>

<?php require_once "_user_footer.php"; ?>