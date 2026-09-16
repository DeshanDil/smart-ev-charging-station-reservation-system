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

$stations = $conn->query("
    SELECT
        st.station_id,
        st.station_name,
        st.location,
        st.contact_number,
        COUNT(sl.slot_id) AS total_slots,
        SUM(CASE WHEN sl.slot_status = 'Available' THEN 1 ELSE 0 END) AS available_slots,
        SUM(CASE WHEN sl.slot_status = 'Reserved' THEN 1 ELSE 0 END) AS reserved_slots,
        SUM(CASE WHEN sl.slot_status = 'Charging' THEN 1 ELSE 0 END) AS charging_slots,
        SUM(CASE WHEN sl.slot_status = 'Out of Service' THEN 1 ELSE 0 END) AS out_slots
    FROM charging_stations st
    LEFT JOIN charging_slots sl ON st.station_id = sl.station_id
    GROUP BY
        st.station_id,
        st.station_name,
        st.location,
        st.contact_number
    ORDER BY st.station_name
");

$slot_details = $conn->query("
    SELECT
        st.station_name,
        st.location,
        sl.slot_number,
        sl.slot_status,
        ct.type_name,
        ct.connector_type,
        ct.power_output_kw,
        ct.rate_per_kwh
    FROM charging_slots sl
    JOIN charging_stations st ON sl.station_id = st.station_id
    JOIN charger_types ct ON sl.type_id = ct.type_id
    ORDER BY st.station_name, sl.slot_number
");

function badgeClass($status) {
    if ($status === "Available") return "badge badge-available";
    if ($status === "Reserved") return "badge badge-reserved";
    if ($status === "Charging") return "badge badge-charging";
    if ($status === "Out of Service") return "badge badge-out";
    return "badge badge-pending";
}

$page_title = "Charging Stations";
$page_subtitle = "View station locations, charger types and slot availability.";
$active_page = "stations";
require_once "_user_header.php";
?>

<div class="page-actions">
    <a class="action-btn" href="reserve.php">Reserve Charging Slot</a>
    <a class="action-btn secondary" href="dashboard.php">Back to Dashboard</a>
</div>

<h2>Station Overview</h2>

<div class="cards">
    <?php if ($stations->num_rows > 0) { ?>
        <?php while ($row = $stations->fetch_assoc()) { ?>
            <div class="card">
                <h3><?= htmlspecialchars($row["station_name"]) ?></h3>

                <p>
                    <?= intval($row["available_slots"]) ?>
                    /
                    <?= intval($row["total_slots"]) ?>
                </p>

                <small>
                    <?= htmlspecialchars($row["location"]) ?><br>
                    Available / Total Slots<br>
                    Contact:
                    <?= htmlspecialchars($row["contact_number"] ?: "N/A") ?>
                </small>
            </div>
        <?php } ?>
    <?php } else { ?>
        <div class="card">
            <h3>No Stations</h3>
            <p>0</p>
            <small>No charging stations available.</small>
        </div>
    <?php } ?>
</div>

<h2>Charging Slot Details</h2>

<div class="table-wrapper">
    <table>
        <tr>
            <th>Station</th>
            <th>Location</th>
            <th>Slot</th>
            <th>Charger</th>
            <th>Power</th>
            <th>Rate</th>
            <th>Status</th>
        </tr>

        <?php if ($slot_details->num_rows > 0) { ?>
            <?php while ($row = $slot_details->fetch_assoc()) { ?>
                <tr>
                    <td><?= htmlspecialchars($row["station_name"]) ?></td>

                    <td><?= htmlspecialchars($row["location"]) ?></td>

                    <td><?= htmlspecialchars($row["slot_number"]) ?></td>

                    <td>
                        <?= htmlspecialchars($row["type_name"]) ?><br>
                        <small>
                            <?= htmlspecialchars($row["connector_type"]) ?>
                        </small>
                    </td>

                    <td>
                        <?= number_format($row["power_output_kw"], 2) ?> kW
                    </td>

                    <td>
                        Rs. <?= number_format($row["rate_per_kwh"], 2) ?>/kWh
                    </td>

                    <td>
                        <span class="<?= badgeClass($row["slot_status"]) ?>">
                            <?= htmlspecialchars($row["slot_status"]) ?>
                        </span>
                    </td>
                </tr>
            <?php } ?>
        <?php } else { ?>
            <tr>
                <td colspan="7">No charging slots found.</td>
            </tr>
        <?php } ?>
    </table>
</div>

<?php require_once "_user_footer.php"; ?>