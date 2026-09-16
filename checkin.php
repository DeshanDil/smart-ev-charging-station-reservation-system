<?php
session_start();
require_once "../config/db.php";

if (!isset($_SESSION["user_id"]) || $_SESSION["user_role"] !== "Admin") {
    header("Location: ../auth/login.php");
    exit;
}

$message = "";
$error = "";
$ticket = null;

function clearStoredProcedureResults($conn) {
    while ($conn->more_results()) {
        $conn->next_result();

        if ($res = $conn->store_result()) {
            $res->free();
        }
    }
}

if (isset($_POST["start_session"])) {
    $reservation_id = intval($_POST["reservation_id"]);

    try {
        $stmt = $conn->prepare("CALL sp_start_charging_session(?)");
        $stmt->bind_param("i", $reservation_id);
        $stmt->execute();

        clearStoredProcedureResults($conn);

        header("Location: checkin.php?reservation_id=" . $reservation_id . "&started=1");
        exit;
    } catch (Exception $e) {
        if (strpos($e->getMessage(), "No Show") !== false) {
            $message = $e->getMessage();
        } else {
            $error = $e->getMessage();
        }
    }
}

if (isset($_GET["reservation_id"]) && $_GET["reservation_id"] !== "") {
    $reservation_id = intval($_GET["reservation_id"]);

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
            u.full_name,
            u.email,
            u.contact_number,
            v.vehicle_number,
            v.vehicle_model,
            st.station_name,
            st.location,
            sl.slot_number,
            ct.type_name,
            ct.connector_type,
            ct.power_output_kw,
            s.session_id,
            s.status AS session_status
        FROM reservations r
        JOIN users u ON r.user_id = u.user_id
        JOIN vehicles v ON r.vehicle_id = v.vehicle_id
        JOIN charging_slots sl ON r.slot_id = sl.slot_id
        JOIN charging_stations st ON sl.station_id = st.station_id
        JOIN charger_types ct ON sl.type_id = ct.type_id
        LEFT JOIN charging_sessions s ON r.reservation_id = s.reservation_id
        WHERE r.reservation_id = ?
    ");

    $stmt->bind_param("i", $reservation_id);
    $stmt->execute();

    $ticket = $stmt->get_result()->fetch_assoc();

    if (!$ticket) {
        $error = "No reservation ticket found.";
    }
}

if (isset($_GET["started"])) {
    $message = "Charging session started successfully.";
}

$page_title = "Ticket Check-in";
$page_subtitle = "Verify reservation tickets and start charging sessions.";
$active_page = "checkin";
require_once "_admin_header.php";
?>

<?php if ($message) { ?>
    <p class="success"><?= htmlspecialchars($message) ?></p>
<?php } ?>

<?php if ($error) { ?>
    <p class="error"><?= htmlspecialchars($error) ?></p>
<?php } ?>

<div class="section-card">
    <h2>Enter Ticket ID</h2>

    <form method="GET" class="compact-form">
        <label>Reservation / Ticket ID</label>
        <input
            type="number"
            name="reservation_id"
            placeholder="Example: 12"
            value="<?= htmlspecialchars($_GET["reservation_id"] ?? "") ?>"
            required
        >

        <button type="submit">Verify Ticket</button>
    </form>
</div>

<?php if ($ticket) { ?>
    <?php
        $is_overnight = $ticket["end_time"] <= $ticket["start_time"];

        $start_dt = new DateTime($ticket["reservation_date"] . " " . $ticket["start_time"]);
        $end_dt = new DateTime($ticket["reservation_date"] . " " . $ticket["end_time"]);

        if ($is_overnight) {
            $end_dt->modify("+1 day");
        }

        $allowed_from = clone $start_dt;
        $allowed_from->modify("-15 minutes");

        $now = new DateTime("now", new DateTimeZone("Asia/Colombo"));

        $can_checkin = false;
        $checkin_status = "";
        $checkin_badge = "badge ";

        if ($ticket["status"] !== "Confirmed") {
            $checkin_status = "This ticket is not active for check-in.";
            $checkin_badge .= "badge-out";
        } elseif ($ticket["session_id"]) {
            $checkin_status = "Charging session already started.";
            $checkin_badge .= "badge-charging";
        } elseif ($now < $allowed_from) {
            $checkin_status = "Too early for check-in.";
            $checkin_badge .= "badge-pending";
        } elseif ($now > $end_dt) {
            $checkin_status = "Reservation expired. Mark as No Show.";
            $checkin_badge .= "badge-out";
        } else {
            $checkin_status = "Check-in allowed now.";
            $checkin_badge .= "badge-available";
            $can_checkin = true;
        }
    ?>

    <h2>Ticket Details</h2>

    <div class="recommend-box">
        <h3>EV Charging Reservation Ticket #<?= $ticket["reservation_id"] ?></h3>

        <div class="cards">
            <div class="card">
                <h3>Customer</h3>
                <p><?= htmlspecialchars($ticket["full_name"]) ?></p>
                <small><?= htmlspecialchars($ticket["contact_number"]) ?></small>
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
                <h3>Status</h3>
                <p><span class="badge badge-available"><?= htmlspecialchars($ticket["status"]) ?></span></p>
                <small><?= $ticket["session_id"] ? "Session ID: " . $ticket["session_id"] : "No session started" ?></small>
            </div>
        </div>

        <h2>Check-in Validation</h2>

        <div class="table-wrapper simple-table">
            <table>
                <tr>
                    <th>Allowed From</th>
                    <th>Allowed Until</th>
                    <th>Current Time</th>
                    <th>Status</th>
                </tr>

                <tr>
                    <td><?= $allowed_from->format("Y-m-d h:i A") ?></td>
                    <td><?= $end_dt->format("Y-m-d h:i A") ?></td>
                    <td><?= $now->format("Y-m-d h:i A") ?></td>
                    <td><span class="<?= $checkin_badge ?>"><?= htmlspecialchars($checkin_status) ?></span></td>
                </tr>
            </table>
        </div>

        <div class="table-wrapper simple-table">
            <table>
                <tr>
                    <th>Slot</th>
                    <th>Charger</th>
                    <th>Date</th>
                    <th>Time</th>
                    <th>Battery</th>
                    <th>Duration</th>
                    <th>Estimated Cost</th>
                </tr>

                <tr>
                    <td><?= htmlspecialchars($ticket["slot_number"]) ?></td>
                    <td>
                        <?= htmlspecialchars($ticket["type_name"]) ?><br>
                        <small><?= htmlspecialchars($ticket["connector_type"]) ?> | <?= $ticket["power_output_kw"] ?> kW</small>
                    </td>
                    <td>
                        <?= htmlspecialchars($ticket["reservation_date"]) ?>
                        <?php if ($is_overnight) { ?>
                            <br><span class="badge badge-charging">Ends Next Day</span>
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
                    <td><?= $ticket["estimated_duration_min"] ?> minutes</td>
                    <td><strong>Rs. <?= number_format($ticket["estimated_cost"], 2) ?></strong></td>
                </tr>
            </table>
        </div>

        <div class="page-actions">
            <?php if ($can_checkin) { ?>
                <form method="POST" style="padding:0;background:transparent;border:none;margin:0;">
                    <input type="hidden" name="reservation_id" value="<?= $ticket["reservation_id"] ?>">
                    <button type="submit" name="start_session">Start Charging Session</button>
                </form>
            <?php } elseif ($ticket["status"] === "Confirmed" && !$ticket["session_id"] && $now > $end_dt) { ?>
                <form method="POST" style="padding:0;background:transparent;border:none;margin:0;">
                    <input type="hidden" name="reservation_id" value="<?= $ticket["reservation_id"] ?>">
                    <button type="submit" name="start_session">Mark No Show</button>
                </form>
            <?php } elseif ($ticket["session_id"]) { ?>
                <a class="action-btn secondary" href="reservations.php">Manage Session / Payment</a>
            <?php } ?>
        </div>
    </div>
<?php } ?>

<?php require_once "_admin_footer.php"; ?>