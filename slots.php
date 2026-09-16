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

/* Add charging slot */
if (isset($_POST["add_slot"])) {
    $station_id = intval($_POST["station_id"]);
    $type_id = intval($_POST["type_id"]);
    $slot_number = trim($_POST["slot_number"]);
    $slot_status = $_POST["slot_status"];

    try {
        $stmt = $conn->prepare("
            INSERT INTO charging_slots
            (
                station_id,
                type_id,
                slot_number,
                slot_status
            )
            VALUES
            (?, ?, ?, ?)
        ");

        $stmt->bind_param(
            "iiss",
            $station_id,
            $type_id,
            $slot_number,
            $slot_status
        );

        $stmt->execute();

        header("Location: slots.php?added=1");
        exit;

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

/* Update slot status */
if (isset($_POST["update_status"])) {
    $slot_id = intval($_POST["slot_id"]);
    $slot_status = $_POST["slot_status"];

    try {
        $stmt = $conn->prepare("
            UPDATE charging_slots
            SET slot_status = ?
            WHERE slot_id = ?
        ");

        $stmt->bind_param("si", $slot_status, $slot_id);
        $stmt->execute();

        header("Location: slots.php?updated=1");
        exit;

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

/* Delete slot */
if (isset($_GET["delete"])) {
    $slot_id = intval($_GET["delete"]);

    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM reservations
            WHERE slot_id = ?
        ");
        $stmt->bind_param("i", $slot_id);
        $stmt->execute();
        $reservation_count = $stmt->get_result()->fetch_row()[0];

        if ($reservation_count > 0) {
            $error = "Cannot delete this slot because it has reservation records.";
        } else {
            $stmt = $conn->prepare("
                DELETE FROM charging_slots
                WHERE slot_id = ?
            ");
            $stmt->bind_param("i", $slot_id);
            $stmt->execute();

            header("Location: slots.php?deleted=1");
            exit;
        }

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

if (isset($_GET["added"])) {
    $message = "Charging slot added successfully.";
}

if (isset($_GET["updated"])) {
    $message = "Charging slot status updated successfully.";
}

if (isset($_GET["deleted"])) {
    $message = "Charging slot deleted successfully.";
}

/* Load dropdowns */
$stations = $conn->query("
    SELECT station_id, station_name, location
    FROM charging_stations
    ORDER BY station_name
");

$charger_types = $conn->query("
    SELECT type_id, type_name, connector_type, power_output_kw
    FROM charger_types
    ORDER BY power_output_kw
");

/* Load slots */
$slots = $conn->query("
    SELECT
        sl.slot_id,
        sl.slot_number,
        sl.slot_status,

        st.station_name,
        st.location,

        ct.type_name,
        ct.connector_type,
        ct.power_output_kw,
        ct.rate_per_kwh,

        COUNT(r.reservation_id) AS reservation_count
    FROM charging_slots sl
    JOIN charging_stations st ON sl.station_id = st.station_id
    JOIN charger_types ct ON sl.type_id = ct.type_id
    LEFT JOIN reservations r ON sl.slot_id = r.slot_id
    GROUP BY
        sl.slot_id,
        sl.slot_number,
        sl.slot_status,
        st.station_name,
        st.location,
        ct.type_name,
        ct.connector_type,
        ct.power_output_kw,
        ct.rate_per_kwh
    ORDER BY st.station_name, sl.slot_number
");

function badgeClass($status) {
    if ($status === "Available") return "badge badge-available";
    if ($status === "Reserved") return "badge badge-reserved";
    if ($status === "Charging") return "badge badge-charging";
    if ($status === "Out of Service") return "badge badge-out";

    return "badge badge-pending";
}

$page_title = "Charging Slots";
$page_subtitle = "Manage charging slot availability and service status.";
$active_page = "slots";
require_once "_admin_header.php";
?>

<?php if ($message) { ?>
    <p class="success"><?= htmlspecialchars($message) ?></p>
<?php } ?>

<?php if ($error) { ?>
    <p class="error"><?= htmlspecialchars($error) ?></p>
<?php } ?>

<div class="page-actions">
    <a class="action-btn secondary" href="stations.php">Manage Stations</a>
    <a class="action-btn secondary" href="charger_types.php">Charger Types</a>
</div>

<div class="section-card">
    <h2>Add Charging Slot</h2>

    <form method="POST" class="compact-form">
        <label>Charging Station</label>
        <select name="station_id" required>
            <option value="">Select Station</option>

            <?php while ($station = $stations->fetch_assoc()) { ?>
                <option value="<?= $station["station_id"] ?>">
                    <?= htmlspecialchars($station["station_name"]) ?>
                    -
                    <?= htmlspecialchars($station["location"]) ?>
                </option>
            <?php } ?>
        </select>

        <label>Charger Type</label>
        <select name="type_id" required>
            <option value="">Select Charger Type</option>

            <?php while ($type = $charger_types->fetch_assoc()) { ?>
                <option value="<?= $type["type_id"] ?>">
                    <?= htmlspecialchars($type["type_name"]) ?>
                    -
                    <?= htmlspecialchars($type["connector_type"]) ?>
                    -
                    <?= $type["power_output_kw"] ?> kW
                </option>
            <?php } ?>
        </select>

        <label>Slot Number</label>
        <input
            type="text"
            name="slot_number"
            placeholder="Example: CMB-S1"
            required
        >

        <label>Slot Status</label>
        <select name="slot_status" required>
            <option value="Available">Available</option>
            <option value="Out of Service">Out of Service</option>
        </select>

        <button type="submit" name="add_slot">Add Slot</button>
    </form>
</div>

<h2>Charging Slots</h2>

<div class="table-wrapper">
    <table>
        <tr>
            <th>ID</th>
            <th>Station</th>
            <th>Slot</th>
            <th>Charger</th>
            <th>Rate</th>
            <th>Status</th>
            <th>Reservations</th>
            <th>Update Status</th>
            <th>Action</th>
        </tr>

        <?php if ($slots->num_rows > 0) { ?>
            <?php while ($row = $slots->fetch_assoc()) { ?>
                <tr>
                    <td>#<?= $row["slot_id"] ?></td>

                    <td>
                        <?= htmlspecialchars($row["station_name"]) ?><br>
                        <small><?= htmlspecialchars($row["location"]) ?></small>
                    </td>

                    <td><?= htmlspecialchars($row["slot_number"]) ?></td>

                    <td>
                        <?= htmlspecialchars($row["type_name"]) ?><br>
                        <small>
                            <?= htmlspecialchars($row["connector_type"]) ?> |
                            <?= $row["power_output_kw"] ?> kW
                        </small>
                    </td>

                    <td>Rs. <?= number_format($row["rate_per_kwh"], 2) ?>/kWh</td>

                    <td>
                        <span class="<?= badgeClass($row["slot_status"]) ?>">
                            <?= htmlspecialchars($row["slot_status"]) ?>
                        </span>
                    </td>

                    <td><?= $row["reservation_count"] ?></td>

                    <td>
                        <form method="POST">
                            <input type="hidden" name="slot_id" value="<?= $row["slot_id"] ?>">

                            <select name="slot_status" required>
                                <option value="Available" <?= $row["slot_status"] === "Available" ? "selected" : "" ?>>
                                    Available
                                </option>

                                <option value="Reserved" <?= $row["slot_status"] === "Reserved" ? "selected" : "" ?>>
                                    Reserved
                                </option>

                                <option value="Charging" <?= $row["slot_status"] === "Charging" ? "selected" : "" ?>>
                                    Charging
                                </option>

                                <option value="Out of Service" <?= $row["slot_status"] === "Out of Service" ? "selected" : "" ?>>
                                    Out of Service
                                </option>
                            </select>

                            <button type="submit" name="update_status">Update</button>
                        </form>
                    </td>

                    <td>
                        <?php if (intval($row["reservation_count"]) === 0) { ?>
                            <a
                                onclick="return confirm('Delete this charging slot?')"
                                href="slots.php?delete=<?= $row['slot_id'] ?>"
                            >
                                Delete
                            </a>
                        <?php } else { ?>
                            <span class="badge badge-pending">
                                In Use
                            </span>
                        <?php } ?>
                    </td>
                </tr>
            <?php } ?>
        <?php } else { ?>
            <tr>
                <td colspan="9">No charging slots found.</td>
            </tr>
        <?php } ?>
    </table>
</div>

<?php require_once "_admin_footer.php"; ?>