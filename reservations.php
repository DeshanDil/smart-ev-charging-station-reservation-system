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

$message = "";
$error = "";

function clearStoredProcedureResults($conn) {
    while ($conn->more_results()) {
        $conn->next_result();

        if ($res = $conn->store_result()) {
            $res->free();
        }
    }
}

function badgeClass($status) {
    if ($status === "Confirmed" || $status === "Available") return "badge badge-available";
    if ($status === "Pending") return "badge badge-pending";
    if ($status === "Completed" || $status === "Paid") return "badge badge-paid";
    if ($status === "In Progress" || $status === "Charging") return "badge badge-charging";
    if ($status === "Cancelled" || $status === "No Show" || $status === "Out of Service") return "badge badge-out";

    return "badge badge-reserved";
}

/* Complete charging session */
if (isset($_POST["complete_session"])) {
    $session_id = intval($_POST["session_id"]);
    $energy_consumed_kwh = floatval($_POST["energy_consumed_kwh"]);

    try {
        $stmt = $conn->prepare("CALL sp_complete_charging_session(?, ?)");
        $stmt->bind_param("id", $session_id, $energy_consumed_kwh);
        $stmt->execute();

        clearStoredProcedureResults($conn);

        header("Location: reservations.php?completed=success");
        exit;

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

/* Success messages */
if (isset($_GET["payment"]) && $_GET["payment"] === "success") {
    $message = "Payment recorded successfully.";
}

if (isset($_GET["completed"]) && $_GET["completed"] === "success") {
    $message = "Charging session completed successfully.";
}

/* Load reservations */
$reservations = $conn->query("
    SELECT
        r.reservation_id,
        r.reservation_date,
        r.start_time,
        r.end_time,
        r.status AS reservation_status,
        r.current_battery_percent,
        r.target_battery_percent,
        r.required_energy_kwh,
        r.estimated_energy_kwh,
        r.estimated_duration_min,
        r.estimated_cost,

        u.full_name,
        u.email,
        u.contact_number,

        v.vehicle_number,
        v.vehicle_model,

        st.station_name,
        st.location,

        sl.slot_number,
        sl.slot_status,

        ct.type_name,
        ct.connector_type,
        ct.power_output_kw,

        s.session_id,
        s.actual_start_time,
        s.actual_end_time,
        s.energy_consumed_kwh,
        s.session_duration_min,
        s.charging_cost,
        s.status AS session_status,

        p.payment_id,
        p.amount,
        p.payment_method,
        p.payment_status
    FROM reservations r
    JOIN users u ON r.user_id = u.user_id
    JOIN vehicles v ON r.vehicle_id = v.vehicle_id
    JOIN charging_slots sl ON r.slot_id = sl.slot_id
    JOIN charging_stations st ON sl.station_id = st.station_id
    JOIN charger_types ct ON sl.type_id = ct.type_id
    LEFT JOIN charging_sessions s ON r.reservation_id = s.reservation_id
    LEFT JOIN payments p ON s.session_id = p.session_id
    ORDER BY r.created_at DESC
");

$page_title = "Manage Reservations";
$page_subtitle = "View tickets, complete charging sessions and record payments.";
$active_page = "reservations";
require_once "_admin_header.php";
?>

<?php if ($message) { ?>
    <p class="success"><?= htmlspecialchars($message) ?></p>
<?php } ?>

<?php if ($error) { ?>
    <p class="error"><?= htmlspecialchars($error) ?></p>
<?php } ?>

<div class="page-actions">
    <a class="action-btn secondary" href="checkin.php">Ticket Check-in</a>
    <a class="action-btn secondary" href="reports.php">View Reports</a>
</div>

<h2>All Reservations</h2>

<div class="table-wrapper">
    <table>
        <tr>
            <th>Ticket</th>
            <th>User</th>
            <th>Vehicle</th>
            <th>Station / Slot</th>
            <th>Battery</th>
            <th>Estimate</th>
            <th>Date / Time</th>
            <th>Reservation</th>
            <th>Session</th>
            <th>Payment</th>
            <th>Action</th>
        </tr>

        <?php if ($reservations->num_rows > 0) { ?>
            <?php while ($row = $reservations->fetch_assoc()) { ?>
                <?php
                    $is_overnight = $row["end_time"] <= $row["start_time"];
                    $session_status = $row["session_status"] ?? "Not Started";
                ?>

                <tr>
                    <td>#<?= $row["reservation_id"] ?></td>

                    <td>
                        <?= htmlspecialchars($row["full_name"]) ?><br>
                        <small>
                            <?= htmlspecialchars($row["contact_number"]) ?><br>
                            <?= htmlspecialchars($row["email"]) ?>
                        </small>
                    </td>

                    <td>
                        <?= htmlspecialchars($row["vehicle_number"]) ?><br>
                        <small><?= htmlspecialchars($row["vehicle_model"]) ?></small>
                    </td>

                    <td>
                        <?= htmlspecialchars($row["station_name"]) ?><br>
                        <small>
                            <?= htmlspecialchars($row["location"]) ?><br>
                            Slot <?= htmlspecialchars($row["slot_number"]) ?> |
                            <?= htmlspecialchars($row["type_name"]) ?> |
                            <?= htmlspecialchars($row["connector_type"]) ?> |
                            <?= $row["power_output_kw"] ?> kW
                        </small>
                    </td>

                    <td>
                        <?= number_format($row["current_battery_percent"], 2) ?>%
                        →
                        <?= number_format($row["target_battery_percent"], 2) ?>%
                    </td>

                    <td>
                        Energy:
                        <?= number_format($row["estimated_energy_kwh"], 2) ?> kWh<br>
                        Duration:
                        <?= $row["estimated_duration_min"] ?> min<br>
                        Cost:
                        <strong>Rs. <?= number_format($row["estimated_cost"], 2) ?></strong>
                    </td>

                    <td>
                        <?= htmlspecialchars($row["reservation_date"]) ?><br>
                        <?= date("h:i A", strtotime($row["start_time"])) ?>
                        -
                        <?= date("h:i A", strtotime($row["end_time"])) ?>

                        <?php if ($is_overnight) { ?>
                            <br>
                            <span class="badge badge-charging">Ends Next Day</span>
                        <?php } ?>
                    </td>

                    <td>
                        <span class="<?= badgeClass($row["reservation_status"]) ?>">
                            <?= htmlspecialchars($row["reservation_status"]) ?>
                        </span>
                    </td>

                    <td>
                        <span class="<?= badgeClass($session_status) ?>">
                            <?= htmlspecialchars($session_status) ?>
                        </span>

                        <?php if ($row["session_id"]) { ?>
                            <br>
                            <small>Session ID: <?= $row["session_id"] ?></small>

                            <?php if ($row["energy_consumed_kwh"] !== null) { ?>
                                <br>
                                <small>
                                    Energy:
                                    <?= number_format($row["energy_consumed_kwh"], 2) ?> kWh
                                </small>
                            <?php } ?>

                            <?php if ($row["charging_cost"] !== null) { ?>
                                <br>
                                <small>
                                    Actual Cost:
                                    Rs. <?= number_format($row["charging_cost"], 2) ?>
                                </small>
                            <?php } ?>
                        <?php } ?>
                    </td>

                    <td>
                        <?php if ($row["payment_id"]) { ?>
                            <span class="badge badge-paid">
                                <?= htmlspecialchars($row["payment_status"]) ?>
                            </span>
                            <br>
                            <small>
                                <?= htmlspecialchars($row["payment_method"]) ?> |
                                Rs. <?= number_format($row["amount"], 2) ?>
                            </small>
                        <?php } else { ?>
                            <span class="badge badge-pending">Not Paid</span>
                        <?php } ?>
                    </td>

                    <td>
                        <?php if ($row["reservation_status"] === "Confirmed" && !$row["session_id"]) { ?>

                            <a href="checkin.php?reservation_id=<?= $row['reservation_id'] ?>">
                                Check-in
                            </a>

                        <?php } elseif ($row["session_status"] === "In Progress") { ?>

                            <form method="POST">
                                <input type="hidden" name="session_id" value="<?= $row['session_id'] ?>">

                                <input
                                    type="number"
                                    step="0.01"
                                    min="0.01"
                                    name="energy_consumed_kwh"
                                    placeholder="Energy kWh"
                                    required
                                >

                                <button type="submit" name="complete_session">
                                    Complete
                                </button>
                            </form>

                        <?php } elseif ($row["session_status"] === "Completed" && !$row["payment_id"]) { ?>

                            <a href="record_payment.php?session_id=<?= $row['session_id'] ?>">
                                Record Payment
                            </a>

                        <?php } elseif ($row["payment_id"]) { ?>

                            <span class="badge badge-paid">Payment Done</span>

                        <?php } else { ?>

                            -

                        <?php } ?>
                    </td>
                </tr>
            <?php } ?>
        <?php } else { ?>
            <tr>
                <td colspan="11">No reservations found.</td>
            </tr>
        <?php } ?>
    </table>
</div>

<?php require_once "_admin_footer.php"; ?>