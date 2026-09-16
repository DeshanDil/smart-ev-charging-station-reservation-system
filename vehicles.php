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

/* Add vehicle */
if (isset($_POST["add_vehicle"])) {
    $vehicle_number = strtoupper(trim($_POST["vehicle_number"]));
    $vehicle_model = trim($_POST["vehicle_model"]);
    $battery_capacity = floatval($_POST["battery_capacity"]);

    try {
        if ($battery_capacity <= 0) {
            throw new Exception("Battery capacity must be greater than zero.");
        }

        $stmt = $conn->prepare("
            INSERT INTO vehicles
            (
                user_id,
                vehicle_number,
                vehicle_model,
                battery_capacity
            )
            VALUES
            (?, ?, ?, ?)
        ");

        $stmt->bind_param(
            "issd",
            $user_id,
            $vehicle_number,
            $vehicle_model,
            $battery_capacity
        );

        $stmt->execute();

        header("Location: vehicles.php?added=1");
        exit;

    } catch (Exception $e) {
        if (strpos($e->getMessage(), "Duplicate") !== false) {
            $error = "This vehicle number is already registered.";
        } else {
            $error = $e->getMessage();
        }
    }
}

/* Delete vehicle */
if (isset($_GET["delete"])) {
    $vehicle_id = intval($_GET["delete"]);

    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM reservations
            WHERE vehicle_id = ?
        ");
        $stmt->bind_param("i", $vehicle_id);
        $stmt->execute();
        $reservation_count = $stmt->get_result()->fetch_row()[0];

        if ($reservation_count > 0) {
            $error = "Cannot delete this vehicle because it has reservation records.";
        } else {
            $stmt = $conn->prepare("
                DELETE FROM vehicles
                WHERE vehicle_id = ?
                  AND user_id = ?
            ");
            $stmt->bind_param("ii", $vehicle_id, $user_id);
            $stmt->execute();

            header("Location: vehicles.php?deleted=1");
            exit;
        }

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

if (isset($_GET["added"])) {
    $message = "Vehicle added successfully.";
}

if (isset($_GET["deleted"])) {
    $message = "Vehicle deleted successfully.";
}

/* Load vehicles */
$stmt = $conn->prepare("
    SELECT
        v.vehicle_id,
        v.vehicle_number,
        v.vehicle_model,
        v.battery_capacity,
        v.created_at,
        COUNT(r.reservation_id) AS reservation_count
    FROM vehicles v
    LEFT JOIN reservations r ON v.vehicle_id = r.vehicle_id
    WHERE v.user_id = ?
    GROUP BY
        v.vehicle_id,
        v.vehicle_number,
        v.vehicle_model,
        v.battery_capacity,
        v.created_at
    ORDER BY v.created_at DESC
");

$stmt->bind_param("i", $user_id);
$stmt->execute();
$vehicles = $stmt->get_result();

$page_title = "My Vehicles";
$page_subtitle = "Add and manage your registered electric vehicles.";
$active_page = "vehicles";
require_once "_user_header.php";
?>

<?php if ($message) { ?>
    <p class="success"><?= htmlspecialchars($message) ?></p>
<?php } ?>

<?php if ($error) { ?>
    <p class="error"><?= htmlspecialchars($error) ?></p>
<?php } ?>

<div class="page-actions">
    <a class="action-btn" href="reserve.php">Reserve Charging Slot</a>
    <a class="action-btn secondary" href="dashboard.php">Back to Dashboard</a>
</div>

<div class="section-card">
    <h2>Add New Vehicle</h2>

    <form method="POST" class="compact-form">
        <label>Vehicle Number</label>
        <input
            type="text"
            name="vehicle_number"
            placeholder="Example: CAR-1234"
            required
        >

        <label>Vehicle Model</label>
        <input
            type="text"
            name="vehicle_model"
            placeholder="Example: Nissan Leaf / Tesla Model 3"
            required
        >

        <label>Battery Capacity kWh</label>
        <input
            type="number"
            step="0.01"
            min="0.01"
            name="battery_capacity"
            placeholder="Example: 60"
            required
        >

        <button type="submit" name="add_vehicle">Add Vehicle</button>
    </form>
</div>

<h2>Registered Vehicles</h2>

<div class="table-wrapper simple-table">
    <table>
        <tr>
            <th>ID</th>
            <th>Vehicle Number</th>
            <th>Model</th>
            <th>Battery Capacity</th>
            <th>Reservations</th>
            <th>Added Date</th>
            <th>Action</th>
        </tr>

        <?php if ($vehicles->num_rows > 0) { ?>
            <?php while ($row = $vehicles->fetch_assoc()) { ?>
                <tr>
                    <td>#<?= $row["vehicle_id"] ?></td>

                    <td>
                        <strong><?= htmlspecialchars($row["vehicle_number"]) ?></strong>
                    </td>

                    <td><?= htmlspecialchars($row["vehicle_model"]) ?></td>

                    <td>
                        <?= number_format($row["battery_capacity"], 2) ?> kWh
                    </td>

                    <td>
                        <?= $row["reservation_count"] ?>
                    </td>

                    <td>
                        <?= date("Y-m-d", strtotime($row["created_at"])) ?><br>
                        <small><?= date("h:i A", strtotime($row["created_at"])) ?></small>
                    </td>

                    <td>
                        <?php if (intval($row["reservation_count"]) === 0) { ?>
                            <a
                                onclick="return confirm('Delete this vehicle?')"
                                href="vehicles.php?delete=<?= $row['vehicle_id'] ?>"
                            >
                                Delete
                            </a>
                        <?php } else { ?>
                            <span class="badge badge-pending">In Use</span>
                        <?php } ?>
                    </td>
                </tr>
            <?php } ?>
        <?php } else { ?>
            <tr>
                <td colspan="7">No vehicles added yet.</td>
            </tr>
        <?php } ?>
    </table>
</div>

<?php require_once "_user_footer.php"; ?>