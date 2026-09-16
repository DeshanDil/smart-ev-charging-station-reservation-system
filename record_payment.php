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

$error = "";
$message = "";
$payment_data = null;

function clearStoredProcedureResults($conn) {
    while ($conn->more_results()) {
        $conn->next_result();

        if ($res = $conn->store_result()) {
            $res->free();
        }
    }
}

$session_id = intval($_GET["session_id"] ?? $_POST["session_id"] ?? 0);

if ($session_id <= 0) {
    $error = "Invalid charging session selected.";
}

/* Record payment */
if (isset($_POST["record_payment"])) {
    $session_id = intval($_POST["session_id"]);
    $payment_method = $_POST["payment_method"];
    $transaction_ref = trim($_POST["transaction_ref"]);

    try {
        $stmt = $conn->prepare("CALL sp_record_payment(?, ?, ?)");
        $stmt->bind_param("iss", $session_id, $payment_method, $transaction_ref);
        $stmt->execute();

        clearStoredProcedureResults($conn);

        header("Location: reservations.php?payment=success");
        exit;

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

/* Load selected payment information only */
if ($session_id > 0) {
    $stmt = $conn->prepare("
        SELECT
            s.session_id,
            s.reservation_id,
            s.actual_start_time,
            s.actual_end_time,
            s.energy_consumed_kwh,
            s.session_duration_min,
            s.charging_cost,
            s.status AS session_status,

            r.reservation_date,
            r.start_time,
            r.end_time,
            r.current_battery_percent,
            r.target_battery_percent,
            r.required_energy_kwh,
            r.estimated_energy_kwh,
            r.estimated_cost,

            u.full_name,
            u.email,
            u.contact_number,

            v.vehicle_number,
            v.vehicle_model,
            v.battery_capacity,

            st.station_name,
            st.location,

            sl.slot_number,

            ct.type_name,
            ct.connector_type,
            ct.power_output_kw,
            ct.rate_per_kwh,

            p.payment_id,
            p.payment_status
        FROM charging_sessions s
        JOIN reservations r ON s.reservation_id = r.reservation_id
        JOIN users u ON r.user_id = u.user_id
        JOIN vehicles v ON r.vehicle_id = v.vehicle_id
        JOIN charging_slots sl ON r.slot_id = sl.slot_id
        JOIN charging_stations st ON sl.station_id = st.station_id
        JOIN charger_types ct ON sl.type_id = ct.type_id
        LEFT JOIN payments p ON s.session_id = p.session_id
        WHERE s.session_id = ?
    ");

    $stmt->bind_param("i", $session_id);
    $stmt->execute();

    $payment_data = $stmt->get_result()->fetch_assoc();

    if (!$payment_data) {
        $error = "Charging session not found.";
    }
}

$page_title = "Record Payment";
$page_subtitle = "Confirm payment for the selected completed charging session.";
$active_page = "reservations";
require_once "_admin_header.php";
?>

<?php if ($error) { ?>
    <p class="error"><?= htmlspecialchars($error) ?></p>
<?php } ?>

<?php if ($message) { ?>
    <p class="success"><?= htmlspecialchars($message) ?></p>
<?php } ?>

<div class="page-actions">
    <a class="action-btn secondary" href="reservations.php">Back to Reservations</a>
    <a class="action-btn secondary" href="reports.php">View Reports</a>
</div>

<?php if ($payment_data) { ?>

    <?php if ($payment_data["payment_id"]) { ?>
        <p class="error">
            Payment has already been recorded for this charging session.
        </p>
    <?php } ?>

    <?php if ($payment_data["session_status"] !== "Completed") { ?>
        <p class="error">
            Payment can be recorded only after the charging session is completed.
        </p>
    <?php } ?>

    <h2>Customer & Charging Summary</h2>

    <div class="cards">
        <div class="card">
            <h3>Customer</h3>
            <p><?= htmlspecialchars($payment_data["full_name"]) ?></p>
            <small>
                <?= htmlspecialchars($payment_data["email"]) ?><br>
                <?= htmlspecialchars($payment_data["contact_number"]) ?>
            </small>
        </div>

        <div class="card">
            <h3>Vehicle</h3>
            <p><?= htmlspecialchars($payment_data["vehicle_number"]) ?></p>
            <small>
                <?= htmlspecialchars($payment_data["vehicle_model"]) ?><br>
                <?= $payment_data["battery_capacity"] ?> kWh Battery
            </small>
        </div>

        <div class="card">
            <h3>Station</h3>
            <p><?= htmlspecialchars($payment_data["station_name"]) ?></p>
            <small>
                <?= htmlspecialchars($payment_data["location"]) ?><br>
                Slot <?= htmlspecialchars($payment_data["slot_number"]) ?>
            </small>
        </div>

        <div class="card">
            <h3>Amount Due</h3>
            <p>Rs. <?= number_format($payment_data["charging_cost"], 2) ?></p>
            <small>
                Actual consumed energy:
                <?= number_format($payment_data["energy_consumed_kwh"], 2) ?> kWh
            </small>
        </div>
    </div>

    <h2>Charging Session Details</h2>

    <div class="table-wrapper simple-table">
        <table>
            <tr>
                <th>Session ID</th>
                <th>Reservation ID</th>
                <th>Charger</th>
                <th>Start Time</th>
                <th>End Time</th>
                <th>Duration</th>
                <th>Status</th>
            </tr>

            <tr>
                <td><?= $payment_data["session_id"] ?></td>
                <td><?= $payment_data["reservation_id"] ?></td>
                <td>
                    <?= htmlspecialchars($payment_data["type_name"]) ?><br>
                    <small>
                        <?= htmlspecialchars($payment_data["connector_type"]) ?> |
                        <?= $payment_data["power_output_kw"] ?> kW |
                        Rs. <?= number_format($payment_data["rate_per_kwh"], 2) ?>/kWh
                    </small>
                </td>
                <td><?= date("Y-m-d h:i A", strtotime($payment_data["actual_start_time"])) ?></td>
                <td><?= date("Y-m-d h:i A", strtotime($payment_data["actual_end_time"])) ?></td>
                <td><?= $payment_data["session_duration_min"] ?> minutes</td>
                <td>
                    <span class="badge badge-paid">
                        <?= htmlspecialchars($payment_data["session_status"]) ?>
                    </span>
                </td>
            </tr>
        </table>
    </div>

    <h2>Payment Form</h2>

    <?php if (!$payment_data["payment_id"] && $payment_data["session_status"] === "Completed") { ?>
        <div class="section-card">
            <form method="POST" class="compact-form">
                <input type="hidden" name="session_id" value="<?= $payment_data["session_id"] ?>">

                <label>Payment Amount</label>
                <input
                    type="text"
                    value="Rs. <?= number_format($payment_data["charging_cost"], 2) ?>"
                    readonly
                >

                <label>Payment Method</label>
                <select name="payment_method" required>
                    <option value="">Select Payment Method</option>
                    <option value="Cash">Cash</option>
                    <option value="Card">Card</option>
                    <option value="Online">Online</option>
                </select>

                <label>Transaction Reference</label>
                <input
                    type="text"
                    name="transaction_ref"
                    placeholder="Example: CASH-001 / CARD-REF-123"
                >

                <button type="submit" name="record_payment">
                    Confirm Payment
                </button>
            </form>
        </div>
    <?php } ?>

<?php } ?>

<?php require_once "_admin_footer.php"; ?>