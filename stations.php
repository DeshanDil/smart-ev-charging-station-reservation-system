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

/* Add station */
if (isset($_POST["add_station"])) {
    $station_name = trim($_POST["station_name"]);
    $location = trim($_POST["location"]);
    $contact_number = trim($_POST["contact_number"]);
    $total_slots = intval($_POST["total_slots"]);

    try {
        $stmt = $conn->prepare("
            INSERT INTO charging_stations
            (
                station_name,
                location,
                contact_number,
                total_slots
            )
            VALUES
            (?, ?, ?, ?)
        ");

        $stmt->bind_param(
            "sssi",
            $station_name,
            $location,
            $contact_number,
            $total_slots
        );

        $stmt->execute();

        header("Location: stations.php?added=1");
        exit;

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

/* Delete station */
if (isset($_GET["delete"])) {
    $station_id = intval($_GET["delete"]);

    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM charging_slots
            WHERE station_id = ?
        ");
        $stmt->bind_param("i", $station_id);
        $stmt->execute();
        $slot_count = $stmt->get_result()->fetch_row()[0];

        if ($slot_count > 0) {
            $error = "Cannot delete this station because it has charging slots.";
        } else {
            $stmt = $conn->prepare("
                DELETE FROM charging_stations
                WHERE station_id = ?
            ");
            $stmt->bind_param("i", $station_id);
            $stmt->execute();

            header("Location: stations.php?deleted=1");
            exit;
        }

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

if (isset($_GET["added"])) {
    $message = "Charging station added successfully.";
}

if (isset($_GET["deleted"])) {
    $message = "Charging station deleted successfully.";
}

/* Load stations */
$stations = $conn->query("
    SELECT
        st.station_id,
        st.station_name,
        st.location,
        st.contact_number,
        st.total_slots,
        COUNT(sl.slot_id) AS actual_slots,
        SUM(CASE WHEN sl.slot_status = 'Available' THEN 1 ELSE 0 END) AS available_slots,
        SUM(CASE WHEN sl.slot_status = 'Charging' THEN 1 ELSE 0 END) AS charging_slots,
        SUM(CASE WHEN sl.slot_status = 'Out of Service' THEN 1 ELSE 0 END) AS out_slots
    FROM charging_stations st
    LEFT JOIN charging_slots sl ON st.station_id = sl.station_id
    GROUP BY
        st.station_id,
        st.station_name,
        st.location,
        st.contact_number,
        st.total_slots
    ORDER BY st.station_name
");

$page_title = "Manage Stations";
$page_subtitle = "Add and manage EV charging station locations.";
$active_page = "stations";
require_once "_admin_header.php";
?>

<?php if ($message) { ?>
    <p class="success"><?= htmlspecialchars($message) ?></p>
<?php } ?>

<?php if ($error) { ?>
    <p class="error"><?= htmlspecialchars($error) ?></p>
<?php } ?>

<div class="page-actions">
    <a class="action-btn secondary" href="slots.php">Manage Slots</a>
    <a class="action-btn secondary" href="charger_types.php">Charger Types</a>
</div>

<div class="section-card">
    <h2>Add Charging Station</h2>

    <form method="POST" class="compact-form">
        <label>Station Name</label>
        <input
            type="text"
            name="station_name"
            placeholder="Example: Colombo Central EV Station"
            required
        >

        <label>Location</label>
        <input
            type="text"
            name="location"
            placeholder="Example: Colombo"
            required
        >

        <label>Contact Number</label>
        <input
            type="text"
            name="contact_number"
            placeholder="Example: 0712345678"
        >

        <label>Planned Total Slots</label>
        <input
            type="number"
            name="total_slots"
            min="0"
            value="0"
            required
        >

        <button type="submit" name="add_station">Add Station</button>
    </form>
</div>

<h2>Charging Stations</h2>

<div class="table-wrapper simple-table">
    <table>
        <tr>
            <th>ID</th>
            <th>Station</th>
            <th>Location</th>
            <th>Contact</th>
            <th>Slots</th>
            <th>Status Summary</th>
            <th>Action</th>
        </tr>

        <?php if ($stations->num_rows > 0) { ?>
            <?php while ($row = $stations->fetch_assoc()) { ?>
                <tr>
                    <td>#<?= $row["station_id"] ?></td>

                    <td><?= htmlspecialchars($row["station_name"]) ?></td>

                    <td><?= htmlspecialchars($row["location"]) ?></td>

                    <td><?= htmlspecialchars($row["contact_number"] ?: "N/A") ?></td>

                    <td>
                        Planned:
                        <?= $row["total_slots"] ?><br>
                        <small>
                            Actual:
                            <?= $row["actual_slots"] ?>
                        </small>
                    </td>

                    <td>
                        <span class="badge badge-available">
                            Available <?= intval($row["available_slots"]) ?>
                        </span>
                        <br><br>
                        <span class="badge badge-charging">
                            Charging <?= intval($row["charging_slots"]) ?>
                        </span>
                        <br><br>
                        <span class="badge badge-out">
                            Out <?= intval($row["out_slots"]) ?>
                        </span>
                    </td>

                    <td>
                        <?php if (intval($row["actual_slots"]) === 0) { ?>
                            <a
                                onclick="return confirm('Delete this station?')"
                                href="stations.php?delete=<?= $row['station_id'] ?>"
                            >
                                Delete
                            </a>
                        <?php } else { ?>
                            <a href="slots.php">View Slots</a>
                        <?php } ?>
                    </td>
                </tr>
            <?php } ?>
        <?php } else { ?>
            <tr>
                <td colspan="7">No stations found.</td>
            </tr>
        <?php } ?>
    </table>
</div>

<?php require_once "_admin_footer.php"; ?>