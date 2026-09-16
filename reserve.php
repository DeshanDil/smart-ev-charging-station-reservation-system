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
$error = "";

function clearStoredProcedureResults($conn) {
    while ($conn->more_results()) {
        $conn->next_result();

        if ($res = $conn->store_result()) {
            $res->free();
        }
    }
}

/* Create reservation */
if (isset($_POST["reserve"])) {
    $vehicle_id = intval($_POST["vehicle_id"]);
    $slot_id = intval($_POST["slot_id"]);
    $reservation_date = $_POST["reservation_date"];
    $start_time = $_POST["start_time"];
    $current_battery = floatval($_POST["current_battery_percent"]);
    $target_battery = floatval($_POST["target_battery_percent"]);

    try {
        $stmt = $conn->prepare("CALL sp_create_reservation(?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param(
            "iiissdd",
            $user_id,
            $vehicle_id,
            $slot_id,
            $reservation_date,
            $start_time,
            $current_battery,
            $target_battery
        );

        $stmt->execute();
        clearStoredProcedureResults($conn);

        header("Location: dashboard.php?reservation=success");
        exit;

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

/* Load user's vehicles */
$vehicle_rows = [];

$stmt = $conn->prepare("
    SELECT vehicle_id, vehicle_number, vehicle_model, battery_capacity
    FROM vehicles
    WHERE user_id = ?
    ORDER BY vehicle_number
");
$stmt->bind_param("i", $user_id);
$stmt->execute();

$vehicle_result = $stmt->get_result();

while ($vehicle = $vehicle_result->fetch_assoc()) {
    $vehicle_rows[] = $vehicle;
}

/* Load stations */
$station_rows = [];

$station_result = $conn->query("
    SELECT station_id, station_name, location
    FROM charging_stations
    ORDER BY station_name
");

while ($station = $station_result->fetch_assoc()) {
    $station_rows[] = $station;
}

/* Search values */
$available_slots = [];
$recommended_slot = null;

$search_vehicle_id = intval($_GET["vehicle_id"] ?? 0);
$search_station = intval($_GET["station_id"] ?? 0);
$search_date = $_GET["reservation_date"] ?? "";
$search_start = $_GET["start_time"] ?? "";
$current_battery = $_GET["current_battery_percent"] ?? "";
$target_battery = $_GET["target_battery_percent"] ?? "";

$search_done = false;

/* Smart availability search */
if (
    $search_vehicle_id > 0 &&
    !empty($search_date) &&
    !empty($search_start) &&
    $current_battery !== "" &&
    $target_battery !== ""
) {
    $search_done = true;

    $current_battery = floatval($current_battery);
    $target_battery = floatval($target_battery);

    $selected_vehicle = null;

    foreach ($vehicle_rows as $vehicle) {
        if ($vehicle["vehicle_id"] == $search_vehicle_id) {
            $selected_vehicle = $vehicle;
            break;
        }
    }

    if (!$selected_vehicle) {
        $error = "Please select a valid vehicle.";
    } elseif ($current_battery < 0 || $current_battery >= 100) {
        $error = "Current battery percentage must be between 0 and 99.";
    } elseif ($target_battery <= $current_battery || $target_battery > 100) {
        $error = "Target battery percentage must be greater than current percentage and less than or equal to 100.";
    } else {
        $battery_capacity = floatval($selected_vehicle["battery_capacity"]);

        $required_energy = $battery_capacity * (($target_battery - $current_battery) / 100);

        /* 10% charging loss */
        $estimated_energy = $required_energy * 1.10;

        $start_dt = new DateTime($search_date . " " . $search_start . ":00");
        $start_dt_sql = $start_dt->format("Y-m-d H:i:s");

        $sql = "
            SELECT
                sl.slot_id,
                sl.slot_number,
                sl.slot_status,
                st.station_id,
                st.station_name,
                st.location,
                ct.type_name,
                ct.connector_type,
                ct.power_output_kw,
                ct.rate_per_kwh
            FROM charging_slots sl
            JOIN charging_stations st ON sl.station_id = st.station_id
            JOIN charger_types ct ON sl.type_id = ct.type_id
            WHERE sl.slot_status NOT IN ('Out of Service', 'Charging')
        ";

        if ($search_station > 0) {
            $sql .= " AND st.station_id = ? ";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $search_station);
        } else {
            $stmt = $conn->prepare($sql);
        }

        $stmt->execute();
        $slot_result = $stmt->get_result();

        while ($slot = $slot_result->fetch_assoc()) {
            $power_output = floatval($slot["power_output_kw"]);
            $rate_per_kwh = floatval($slot["rate_per_kwh"]);

            if ($power_output <= 0) {
                continue;
            }

            $duration_min = ceil(($estimated_energy / $power_output) * 60);

            if ($duration_min <= 0 || $duration_min > 1440) {
                continue;
            }

            $end_dt = clone $start_dt;
            $end_dt->modify("+{$duration_min} minutes");

            $end_dt_sql = $end_dt->format("Y-m-d H:i:s");

            $conflict_stmt = $conn->prepare("
                SELECT COUNT(*) AS conflict_count
                FROM reservations r
                WHERE r.slot_id = ?
                  AND r.status IN ('Pending', 'Confirmed')
                  AND ? <
                      CASE
                          WHEN r.end_time <= r.start_time
                          THEN TIMESTAMP(DATE_ADD(r.reservation_date, INTERVAL 1 DAY), r.end_time)
                          ELSE TIMESTAMP(r.reservation_date, r.end_time)
                      END
                  AND ? > TIMESTAMP(r.reservation_date, r.start_time)
            ");

            $conflict_stmt->bind_param(
                "iss",
                $slot["slot_id"],
                $start_dt_sql,
                $end_dt_sql
            );

            $conflict_stmt->execute();
            $conflict_result = $conflict_stmt->get_result()->fetch_assoc();

            if ($conflict_result["conflict_count"] == 0) {
                $estimated_cost = $estimated_energy * $rate_per_kwh;

                $slot["required_energy_kwh"] = $required_energy;
                $slot["estimated_energy_kwh"] = $estimated_energy;
                $slot["estimated_duration_min"] = $duration_min;
                $slot["estimated_cost"] = $estimated_cost;
                $slot["estimated_end_date"] = $end_dt->format("Y-m-d");
                $slot["estimated_end_time"] = $end_dt->format("H:i:s");
                $slot["is_overnight"] = $end_dt->format("Y-m-d") !== $search_date;

                $available_slots[] = $slot;
            }
        }

        usort($available_slots, function ($a, $b) {
            if ($a["estimated_duration_min"] == $b["estimated_duration_min"]) {
                return $a["estimated_cost"] <=> $b["estimated_cost"];
            }

            return $a["estimated_duration_min"] <=> $b["estimated_duration_min"];
        });

        if (count($available_slots) > 0) {
            $recommended_slot = $available_slots[0];
        }
    }
}

$page_title = "Reserve Charging Slot";
$page_subtitle = "Find the best available charger using smart time calculation.";
$active_page = "reserve";
require_once "_user_header.php";
?>

<?php if ($message) { ?>
    <p class="success"><?= htmlspecialchars($message) ?></p>
<?php } ?>

<?php if ($error) { ?>
    <p class="error"><?= htmlspecialchars($error) ?></p>
<?php } ?>

<?php if (count($vehicle_rows) === 0) { ?>
    <p class="error">
        You need to add a vehicle before making a reservation.
        <a href="vehicles.php">Add Vehicle</a>
    </p>
<?php } ?>

<div class="section-card">
    <h2>Find Smart Charging Options</h2>

    <form method="GET" class="compact-form">
        <label>Select Vehicle</label>
        <select name="vehicle_id" required>
            <option value="">Select Vehicle</option>

            <?php foreach ($vehicle_rows as $vehicle) { ?>
                <option value="<?= $vehicle['vehicle_id'] ?>"
                    <?= $search_vehicle_id == $vehicle['vehicle_id'] ? "selected" : "" ?>>
                    <?= htmlspecialchars($vehicle['vehicle_number']) ?> -
                    <?= htmlspecialchars($vehicle['vehicle_model']) ?>
                    (<?= $vehicle['battery_capacity'] ?> kWh)
                </option>
            <?php } ?>
        </select>

        <label>Charging Station</label>
        <select name="station_id">
            <option value="0">Any Station</option>

            <?php foreach ($station_rows as $station) { ?>
                <option value="<?= $station['station_id'] ?>"
                    <?= $search_station == $station['station_id'] ? "selected" : "" ?>>
                    <?= htmlspecialchars($station['station_name']) ?> -
                    <?= htmlspecialchars($station['location']) ?>
                </option>
            <?php } ?>
        </select>

        <label>Current Battery Percentage</label>
        <input
            type="number"
            step="0.01"
            min="0"
            max="99"
            name="current_battery_percent"
            placeholder="Example: 25"
            value="<?= htmlspecialchars($current_battery) ?>"
            required
        >

        <label>Target Battery Percentage</label>
        <input
            type="number"
            step="0.01"
            min="1"
            max="100"
            name="target_battery_percent"
            placeholder="Example: 80"
            value="<?= htmlspecialchars($target_battery) ?>"
            required
        >

        <label>Reservation Date</label>
        <input
            type="date"
            name="reservation_date"
            value="<?= htmlspecialchars($search_date) ?>"
            required
        >

        <label>Start Time</label>
        <input
            type="time"
            name="start_time"
            value="<?= htmlspecialchars($search_start) ?>"
            required
        >

        <button type="submit">Find Charging Options</button>
    </form>
</div>

<?php if ($recommended_slot) { ?>
    <div class="recommend-box">
        <h3>Smart Recommendation</h3>
        <p>
            Best option:
            <strong><?= htmlspecialchars($recommended_slot["station_name"]) ?></strong>,
            Slot <strong><?= htmlspecialchars($recommended_slot["slot_number"]) ?></strong>.
            Estimated duration:
            <strong><?= $recommended_slot["estimated_duration_min"] ?> minutes</strong>.
            Estimated cost:
            <strong>Rs. <?= number_format($recommended_slot["estimated_cost"], 2) ?></strong>.
        </p>
    </div>
<?php } ?>

<?php if ($search_done && !$error) { ?>
    <h2>Available Charging Options</h2>

    <?php if (count($available_slots) === 0) { ?>
        <p class="error">No available charging slots found for this charging requirement.</p>
    <?php } else { ?>

        <div class="table-wrapper">
            <table>
                <tr>
                    <th>Station</th>
                    <th>Slot</th>
                    <th>Charger</th>
                    <th>Energy</th>
                    <th>Duration</th>
                    <th>End Time</th>
                    <th>Cost</th>
                    <th>Action</th>
                </tr>

                <?php foreach ($available_slots as $slot) { ?>
                    <tr>
                        <td>
                            <?= htmlspecialchars($slot["station_name"]) ?><br>
                            <small><?= htmlspecialchars($slot["location"]) ?></small>
                        </td>

                        <td><?= htmlspecialchars($slot["slot_number"]) ?></td>

                        <td>
                            <?= htmlspecialchars($slot["type_name"]) ?><br>
                            <small>
                                <?= htmlspecialchars($slot["connector_type"]) ?> |
                                <?= $slot["power_output_kw"] ?> kW |
                                Rs. <?= number_format($slot["rate_per_kwh"], 2) ?>/kWh
                            </small>
                        </td>

                        <td>
                            <?= number_format($slot["required_energy_kwh"], 2) ?> kWh<br>
                            <small>
                                With loss:
                                <?= number_format($slot["estimated_energy_kwh"], 2) ?> kWh
                            </small>
                        </td>

                        <td>
                            <?= $slot["estimated_duration_min"] ?> min
                        </td>

                        <td>
                            <?= date("h:i A", strtotime($slot["estimated_end_time"])) ?>

                            <?php if ($slot["is_overnight"]) { ?>
                                <br>
                                <span class="badge badge-charging">Ends Next Day</span>
                            <?php } ?>
                        </td>

                        <td>
                            <strong>Rs. <?= number_format($slot["estimated_cost"], 2) ?></strong>
                        </td>

                        <td>
                            <form method="POST">
                                <input type="hidden" name="vehicle_id" value="<?= $search_vehicle_id ?>">
                                <input type="hidden" name="slot_id" value="<?= $slot['slot_id'] ?>">
                                <input type="hidden" name="reservation_date" value="<?= htmlspecialchars($search_date) ?>">
                                <input type="hidden" name="start_time" value="<?= htmlspecialchars($search_start) ?>">
                                <input type="hidden" name="current_battery_percent" value="<?= htmlspecialchars($current_battery) ?>">
                                <input type="hidden" name="target_battery_percent" value="<?= htmlspecialchars($target_battery) ?>">

                                <button type="submit" name="reserve">Reserve</button>
                            </form>
                        </td>
                    </tr>
                <?php } ?>
            </table>
        </div>

    <?php } ?>
<?php } ?>

<?php require_once "_user_footer.php"; ?>