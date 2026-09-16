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
$message = "";

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

/* Cancel reservation */
if (isset($_GET["cancel"])) {
    $reservation_id = intval($_GET["cancel"]);

    try {
        $stmt = $conn->prepare("CALL sp_cancel_reservation(?, ?)");
        $stmt->bind_param("ii", $reservation_id, $user_id);
        $stmt->execute();

        clearStoredProcedureResults($conn);

        header("Location: my_reservations.php");
        exit;

    } catch (Exception $e) {
        $message = $e->getMessage();
    }
}

$stmt = $conn->prepare("
    SELECT
        r.reservation_id,
        r.reservation_date,
        r.start_time,
        r.end_time,
        r.status,
        r.current_battery_percent,
        r.target_battery_percent,
        r.required_energy_kwh,
        r.estimated_energy_kwh,
        r.estimated_duration_min,
        r.estimated_cost,

        v.vehicle_number,
        v.vehicle_model,

        st.station_name,
        st.location,

        sl.slot_number,

        ct.type_name,
        ct.connector_type,
        ct.power_output_kw,

        s.session_id,
        s.status AS session_status,
        s.energy_consumed_kwh,
        s.session_duration_min,
        s.charging_cost,

        p.payment_id,
        p.amount,
        p.payment_method,
        p.payment_status,
        p.transaction_ref,
        p.payment_date

    FROM reservations r
    JOIN vehicles v ON r.vehicle_id = v.vehicle_id
    JOIN charging_slots sl ON r.slot_id = sl.slot_id
    JOIN charging_stations st ON sl.station_id = st.station_id
    JOIN charger_types ct ON sl.type_id = ct.type_id
    LEFT JOIN charging_sessions s ON r.reservation_id = s.reservation_id
    LEFT JOIN payments p ON s.session_id = p.session_id
    WHERE r.user_id = ?
    ORDER BY r.reservation_date DESC, r.start_time DESC
");

$stmt->bind_param("i", $user_id);
$stmt->execute();
$reservations = $stmt->get_result();

$page_title = "My Reservations";
$page_subtitle = "View your tickets, charging sessions and payment receipts.";
$active_page = "my_reservations";
require_once "_user_header.php";
?>

<?php if ($message) { ?>
    <p class="error"><?= htmlspecialchars($message) ?></p>
<?php } ?>

<div class="page-actions">
    <a class="action-btn" href="reserve.php">Reserve New Slot</a>
    <a class="action-btn secondary" href="dashboard.php">Back to Dashboard</a>
</div>

<h2>Reservation History</h2>

<div class="table-wrapper">
    <table>
        <tr>
            <th>Ticket</th>
            <th>Vehicle</th>
            <th>Station</th>
            <th>Battery</th>
            <th>Estimate</th>
            <th>Session</th>
            <th>Payment</th>
            <th>Date / Time</th>
            <th>Status</th>
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
                        <?= htmlspecialchars($row["vehicle_number"]) ?><br>
                        <small><?= htmlspecialchars($row["vehicle_model"]) ?></small>
                    </td>

                    <td>
                        <?= htmlspecialchars($row["station_name"]) ?><br>
                        <small>
                            <?= htmlspecialchars($row["location"]) ?><br>
                            Slot <?= htmlspecialchars($row["slot_number"]) ?> |
                            <?= htmlspecialchars($row["type_name"]) ?>
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
                        <span class="<?= badgeClass($session_status) ?>">
                            <?= htmlspecialchars($session_status) ?>
                        </span>

                        <?php if ($row["session_id"]) { ?>
                            <br>
                            <small>Session ID: <?= $row["session_id"] ?></small>

                            <?php if ($row["energy_consumed_kwh"] !== null) { ?>
                                <br>
                                <small>
                                    Actual Energy:
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
                                Rs. <?= number_format($row["amount"], 2) ?><br>
                                <?= htmlspecialchars($row["payment_method"]) ?>
                            </small>
                        <?php } else { ?>
                            <span class="badge badge-pending">Not Paid</span>
                        <?php } ?>
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
                        <span class="<?= badgeClass($row["status"]) ?>">
                            <?= htmlspecialchars($row["status"]) ?>
                        </span>
                    </td>

                    <td>
                        <?php if ($row["payment_id"]) { ?>

                            <a href="payment_receipt.php?reservation_id=<?= $row['reservation_id'] ?>">
                                View Receipt
                            </a>

                        <?php } elseif ($row["status"] === "Pending" || $row["status"] === "Confirmed") { ?>

                            <a onclick="return confirm('Cancel this reservation?')"
                               href="my_reservations.php?cancel=<?= $row['reservation_id'] ?>">
                                Cancel
                            </a>

                        <?php } else { ?>

                            -

                        <?php } ?>
                    </td>
                </tr>
            <?php } ?>
        <?php } else { ?>
            <tr>
                <td colspan="10">No reservations found.</td>
            </tr>
        <?php } ?>
    </table>
</div>

<?php require_once "_user_footer.php"; ?>