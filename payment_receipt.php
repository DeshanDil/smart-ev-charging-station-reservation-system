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
$reservation_id = intval($_GET["reservation_id"] ?? 0);

if ($reservation_id <= 0) {
    die("Invalid reservation selected.");
}

$stmt = $conn->prepare("
    SELECT
        r.reservation_id,
        r.reservation_date,
        r.start_time,
        r.end_time,
        r.status AS reservation_status,
        r.current_battery_percent,
        r.target_battery_percent,

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
        p.payment_date,
        p.payment_status,
        p.transaction_ref

    FROM reservations r
    JOIN users u ON r.user_id = u.user_id
    JOIN vehicles v ON r.vehicle_id = v.vehicle_id
    JOIN charging_slots sl ON r.slot_id = sl.slot_id
    JOIN charging_stations st ON sl.station_id = st.station_id
    JOIN charger_types ct ON sl.type_id = ct.type_id
    JOIN charging_sessions s ON r.reservation_id = s.reservation_id
    JOIN payments p ON s.session_id = p.session_id
    WHERE r.reservation_id = ?
      AND r.user_id = ?
");

$stmt->bind_param("ii", $reservation_id, $user_id);
$stmt->execute();

$receipt = $stmt->get_result()->fetch_assoc();

if (!$receipt) {
    die("Payment receipt not found.");
}

$page_title = "Payment Receipt";
$page_subtitle = "View your completed EV charging payment receipt.";
$active_page = "my_reservations";
require_once "_user_header.php";
?>

<div class="page-actions print-btn">
    <a class="action-btn secondary" href="my_reservations.php">Back to Reservations</a>
    <button onclick="window.print()">Print / Save Receipt</button>
</div>

<div class="recommend-box">

    <h3>EV Charging Payment Receipt</h3>

    <div class="cards">
        <div class="card">
            <h3>Payment ID</h3>
            <p>#<?= $receipt["payment_id"] ?></p>
            <small>
                <span class="badge badge-paid">
                    <?= htmlspecialchars($receipt["payment_status"]) ?>
                </span>
            </small>
        </div>

        <div class="card">
            <h3>Reservation ID</h3>
            <p>#<?= $receipt["reservation_id"] ?></p>
            <small>Session ID: <?= $receipt["session_id"] ?></small>
        </div>

        <div class="card">
            <h3>Amount Paid</h3>
            <p>Rs. <?= number_format($receipt["amount"], 2) ?></p>
            <small><?= htmlspecialchars($receipt["payment_method"]) ?></small>
        </div>

        <div class="card">
            <h3>Payment Date</h3>
            <p><?= date("Y-m-d", strtotime($receipt["payment_date"])) ?></p>
            <small><?= date("h:i A", strtotime($receipt["payment_date"])) ?></small>
        </div>
    </div>

    <h2>Customer Details</h2>

    <div class="table-wrapper simple-table">
        <table>
            <tr>
                <th>Customer</th>
                <th>Email</th>
                <th>Contact</th>
                <th>Vehicle</th>
            </tr>

            <tr>
                <td><?= htmlspecialchars($receipt["full_name"]) ?></td>

                <td><?= htmlspecialchars($receipt["email"]) ?></td>

                <td><?= htmlspecialchars($receipt["contact_number"]) ?></td>

                <td>
                    <?= htmlspecialchars($receipt["vehicle_number"]) ?><br>
                    <small>
                        <?= htmlspecialchars($receipt["vehicle_model"]) ?> |
                        <?= $receipt["battery_capacity"] ?> kWh
                    </small>
                </td>
            </tr>
        </table>
    </div>

    <h2>Charging Details</h2>

    <div class="table-wrapper simple-table">
        <table>
            <tr>
                <th>Station</th>
                <th>Slot</th>
                <th>Charger</th>
                <th>Battery</th>
                <th>Actual Energy</th>
                <th>Duration</th>
            </tr>

            <tr>
                <td>
                    <?= htmlspecialchars($receipt["station_name"]) ?><br>
                    <small><?= htmlspecialchars($receipt["location"]) ?></small>
                </td>

                <td><?= htmlspecialchars($receipt["slot_number"]) ?></td>

                <td>
                    <?= htmlspecialchars($receipt["type_name"]) ?><br>
                    <small>
                        <?= htmlspecialchars($receipt["connector_type"]) ?> |
                        <?= $receipt["power_output_kw"] ?> kW |
                        Rs. <?= number_format($receipt["rate_per_kwh"], 2) ?>/kWh
                    </small>
                </td>

                <td>
                    <?= number_format($receipt["current_battery_percent"], 2) ?>%
                    →
                    <?= number_format($receipt["target_battery_percent"], 2) ?>%
                </td>

                <td>
                    <?= number_format($receipt["energy_consumed_kwh"], 2) ?> kWh
                </td>

                <td>
                    <?= $receipt["session_duration_min"] ?> minutes
                </td>
            </tr>
        </table>
    </div>

    <h2>Session Time</h2>

    <div class="table-wrapper simple-table">
        <table>
            <tr>
                <th>Reservation Date</th>
                <th>Reserved Time</th>
                <th>Actual Start</th>
                <th>Actual End</th>
                <th>Session Status</th>
            </tr>

            <tr>
                <td><?= htmlspecialchars($receipt["reservation_date"]) ?></td>

                <td>
                    <?= date("h:i A", strtotime($receipt["start_time"])) ?>
                    -
                    <?= date("h:i A", strtotime($receipt["end_time"])) ?>
                </td>

                <td>
                    <?= date("Y-m-d h:i A", strtotime($receipt["actual_start_time"])) ?>
                </td>

                <td>
                    <?= date("Y-m-d h:i A", strtotime($receipt["actual_end_time"])) ?>
                </td>

                <td>
                    <span class="badge badge-paid">
                        <?= htmlspecialchars($receipt["session_status"]) ?>
                    </span>
                </td>
            </tr>
        </table>
    </div>

    <h2>Payment Summary</h2>

    <div class="table-wrapper simple-table">
        <table>
            <tr>
                <th>Payment Method</th>
                <th>Transaction Reference</th>
                <th>Rate</th>
                <th>Calculation</th>
                <th>Total Amount</th>
            </tr>

            <tr>
                <td><?= htmlspecialchars($receipt["payment_method"]) ?></td>

                <td>
                    <?= htmlspecialchars($receipt["transaction_ref"] ?: "N/A") ?>
                </td>

                <td>
                    Rs. <?= number_format($receipt["rate_per_kwh"], 2) ?>/kWh
                </td>

                <td>
                    <?= number_format($receipt["energy_consumed_kwh"], 2) ?> kWh
                    ×
                    Rs. <?= number_format($receipt["rate_per_kwh"], 2) ?>
                </td>

                <td>
                    <strong>
                        Rs. <?= number_format($receipt["amount"], 2) ?>
                    </strong>
                </td>
            </tr>
        </table>
    </div>

</div>

<?php require_once "_user_footer.php"; ?>