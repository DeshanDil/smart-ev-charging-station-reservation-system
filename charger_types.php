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

/* Add charger type */
if (isset($_POST["add_charger_type"])) {
    $type_name = trim($_POST["type_name"]);
    $connector_type = trim($_POST["connector_type"]);
    $power_output_kw = floatval($_POST["power_output_kw"]);
    $rate_per_kwh = floatval($_POST["rate_per_kwh"]);

    try {
        if ($power_output_kw <= 0) {
            throw new Exception("Power output must be greater than zero.");
        }

        if ($rate_per_kwh <= 0) {
            throw new Exception("Rate per kWh must be greater than zero.");
        }

        $stmt = $conn->prepare("
            INSERT INTO charger_types
            (
                type_name,
                connector_type,
                power_output_kw,
                rate_per_kwh
            )
            VALUES
            (?, ?, ?, ?)
        ");

        $stmt->bind_param(
            "ssdd",
            $type_name,
            $connector_type,
            $power_output_kw,
            $rate_per_kwh
        );

        $stmt->execute();

        header("Location: charger_types.php?added=1");
        exit;

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

/* Delete charger type */
if (isset($_GET["delete"])) {
    $type_id = intval($_GET["delete"]);

    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM charging_slots
            WHERE type_id = ?
        ");
        $stmt->bind_param("i", $type_id);
        $stmt->execute();
        $slot_count = $stmt->get_result()->fetch_row()[0];

        if ($slot_count > 0) {
            $error = "Cannot delete this charger type because it is used by charging slots.";
        } else {
            $stmt = $conn->prepare("
                DELETE FROM charger_types
                WHERE type_id = ?
            ");
            $stmt->bind_param("i", $type_id);
            $stmt->execute();

            header("Location: charger_types.php?deleted=1");
            exit;
        }

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

if (isset($_GET["added"])) {
    $message = "Charger type added successfully.";
}

if (isset($_GET["deleted"])) {
    $message = "Charger type deleted successfully.";
}

/* Load charger types */
$charger_types = $conn->query("
    SELECT
        ct.type_id,
        ct.type_name,
        ct.connector_type,
        ct.power_output_kw,
        ct.rate_per_kwh,
        COUNT(sl.slot_id) AS used_slots
    FROM charger_types ct
    LEFT JOIN charging_slots sl ON ct.type_id = sl.type_id
    GROUP BY
        ct.type_id,
        ct.type_name,
        ct.connector_type,
        ct.power_output_kw,
        ct.rate_per_kwh
    ORDER BY ct.power_output_kw ASC
");

$page_title = "Charger Types";
$page_subtitle = "Manage charger power output, connector type and charging rates.";
$active_page = "charger_types";
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
    <a class="action-btn secondary" href="stations.php">Manage Stations</a>
</div>

<div class="section-card">
    <h2>Add Charger Type</h2>

    <form method="POST" class="compact-form">
        <label>Type Name</label>
        <input
            type="text"
            name="type_name"
            placeholder="Example: Fast DC"
            required
        >

        <label>Connector Type</label>
        <input
            type="text"
            name="connector_type"
            placeholder="Example: CCS2 / CHAdeMO / Type 2"
            required
        >

        <label>Power Output kW</label>
        <input
            type="number"
            step="0.01"
            min="0.01"
            name="power_output_kw"
            placeholder="Example: 50"
            required
        >

        <label>Rate Per kWh</label>
        <input
            type="number"
            step="0.01"
            min="0.01"
            name="rate_per_kwh"
            placeholder="Example: 120"
            required
        >

        <button type="submit" name="add_charger_type">Add Charger Type</button>
    </form>
</div>

<h2>Available Charger Types</h2>

<div class="table-wrapper simple-table">
    <table>
        <tr>
            <th>ID</th>
            <th>Type Name</th>
            <th>Connector</th>
            <th>Power Output</th>
            <th>Rate</th>
            <th>Used Slots</th>
            <th>Action</th>
        </tr>

        <?php if ($charger_types->num_rows > 0) { ?>
            <?php while ($row = $charger_types->fetch_assoc()) { ?>
                <tr>
                    <td>#<?= $row["type_id"] ?></td>

                    <td><?= htmlspecialchars($row["type_name"]) ?></td>

                    <td><?= htmlspecialchars($row["connector_type"]) ?></td>

                    <td><?= number_format($row["power_output_kw"], 2) ?> kW</td>

                    <td>Rs. <?= number_format($row["rate_per_kwh"], 2) ?>/kWh</td>

                    <td><?= $row["used_slots"] ?></td>

                    <td>
                        <?php if (intval($row["used_slots"]) === 0) { ?>
                            <a
                                onclick="return confirm('Delete this charger type?')"
                                href="charger_types.php?delete=<?= $row['type_id'] ?>"
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
                <td colspan="7">No charger types found.</td>
            </tr>
        <?php } ?>
    </table>
</div>

<?php require_once "_admin_footer.php"; ?>