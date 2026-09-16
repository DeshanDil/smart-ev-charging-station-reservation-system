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

/* Default date range: current month */
$from_date = $_GET["from_date"] ?? date("Y-m-01");
$to_date = $_GET["to_date"] ?? date("Y-m-d");

if (!preg_match("/^\d{4}-\d{2}-\d{2}$/", $from_date)) {
    $from_date = date("Y-m-01");
}

if (!preg_match("/^\d{4}-\d{2}-\d{2}$/", $to_date)) {
    $to_date = date("Y-m-d");
}

$from_datetime = $from_date . " 00:00:00";
$to_datetime = $to_date . " 23:59:59";

function fetchSingleRow($conn, $sql, $types, $params) {
    $stmt = $conn->prepare($sql);

    if ($types !== "") {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

function fetchRows($conn, $sql, $types, $params) {
    $stmt = $conn->prepare($sql);

    if ($types !== "") {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    return $stmt->get_result();
}

/* Summary */
$summary = fetchSingleRow(
    $conn,
    "
    SELECT
        COUNT(DISTINCT p.payment_id) AS total_payments,
        COUNT(DISTINCT s.session_id) AS completed_sessions,
        COALESCE(SUM(p.amount), 0) AS total_revenue,
        COALESCE(SUM(s.energy_consumed_kwh), 0) AS total_energy,
        COALESCE(AVG(s.session_duration_min), 0) AS avg_duration
    FROM payments p
    JOIN charging_sessions s ON p.session_id = s.session_id
    WHERE p.payment_status = 'Paid'
      AND p.payment_date BETWEEN ? AND ?
    ",
    "ss",
    [$from_datetime, $to_datetime]
);

/* Top station */
$top_station = fetchSingleRow(
    $conn,
    "
    SELECT
        st.station_name,
        st.location,
        COUNT(s.session_id) AS session_count,
        COALESCE(SUM(p.amount), 0) AS revenue
    FROM payments p
    JOIN charging_sessions s ON p.session_id = s.session_id
    JOIN reservations r ON s.reservation_id = r.reservation_id
    JOIN charging_slots sl ON r.slot_id = sl.slot_id
    JOIN charging_stations st ON sl.station_id = st.station_id
    WHERE p.payment_status = 'Paid'
      AND p.payment_date BETWEEN ? AND ?
    GROUP BY st.station_id, st.station_name, st.location
    ORDER BY session_count DESC, revenue DESC
    LIMIT 1
    ",
    "ss",
    [$from_datetime, $to_datetime]
);

/* Revenue by station */
$station_report = fetchRows(
    $conn,
    "
    SELECT
        st.station_name,
        st.location,
        COUNT(s.session_id) AS total_sessions,
        COALESCE(SUM(s.energy_consumed_kwh), 0) AS total_energy,
        COALESCE(SUM(p.amount), 0) AS total_revenue
    FROM payments p
    JOIN charging_sessions s ON p.session_id = s.session_id
    JOIN reservations r ON s.reservation_id = r.reservation_id
    JOIN charging_slots sl ON r.slot_id = sl.slot_id
    JOIN charging_stations st ON sl.station_id = st.station_id
    WHERE p.payment_status = 'Paid'
      AND p.payment_date BETWEEN ? AND ?
    GROUP BY st.station_id, st.station_name, st.location
    ORDER BY total_revenue DESC
    ",
    "ss",
    [$from_datetime, $to_datetime]
);

/* Revenue by charger type */
$charger_type_report = fetchRows(
    $conn,
    "
    SELECT
        ct.type_name,
        ct.connector_type,
        ct.power_output_kw,
        ct.rate_per_kwh,
        COUNT(s.session_id) AS total_sessions,
        COALESCE(SUM(s.energy_consumed_kwh), 0) AS total_energy,
        COALESCE(SUM(p.amount), 0) AS total_revenue
    FROM payments p
    JOIN charging_sessions s ON p.session_id = s.session_id
    JOIN reservations r ON s.reservation_id = r.reservation_id
    JOIN charging_slots sl ON r.slot_id = sl.slot_id
    JOIN charger_types ct ON sl.type_id = ct.type_id
    WHERE p.payment_status = 'Paid'
      AND p.payment_date BETWEEN ? AND ?
    GROUP BY ct.type_id, ct.type_name, ct.connector_type, ct.power_output_kw, ct.rate_per_kwh
    ORDER BY total_revenue DESC
    ",
    "ss",
    [$from_datetime, $to_datetime]
);

/* Daily revenue */
$daily_revenue = fetchRows(
    $conn,
    "
    SELECT
        DATE(p.payment_date) AS payment_day,
        COUNT(p.payment_id) AS total_payments,
        COALESCE(SUM(s.energy_consumed_kwh), 0) AS total_energy,
        COALESCE(SUM(p.amount), 0) AS total_revenue
    FROM payments p
    JOIN charging_sessions s ON p.session_id = s.session_id
    WHERE p.payment_status = 'Paid'
      AND p.payment_date BETWEEN ? AND ?
    GROUP BY DATE(p.payment_date)
    ORDER BY payment_day ASC
    ",
    "ss",
    [$from_datetime, $to_datetime]
);

/* Payment details */
$payment_details = fetchRows(
    $conn,
    "
    SELECT
        p.payment_id,
        p.payment_date,
        p.amount,
        p.payment_method,
        p.transaction_ref,
        u.full_name,
        u.contact_number,
        v.vehicle_number,
        st.station_name,
        sl.slot_number,
        ct.type_name,
        s.energy_consumed_kwh,
        s.session_duration_min
    FROM payments p
    JOIN charging_sessions s ON p.session_id = s.session_id
    JOIN reservations r ON s.reservation_id = r.reservation_id
    JOIN users u ON r.user_id = u.user_id
    JOIN vehicles v ON r.vehicle_id = v.vehicle_id
    JOIN charging_slots sl ON r.slot_id = sl.slot_id
    JOIN charging_stations st ON sl.station_id = st.station_id
    JOIN charger_types ct ON sl.type_id = ct.type_id
    WHERE p.payment_status = 'Paid'
      AND p.payment_date BETWEEN ? AND ?
    ORDER BY p.payment_date DESC
    ",
    "ss",
    [$from_datetime, $to_datetime]
);

$page_title = "Reports";
$page_subtitle = "Analyze revenue, energy usage and station performance.";
$active_page = "reports";
require_once "_admin_header.php";
?>

<div class="section-card print-btn">
    <h2>Date Range Filter</h2>

    <form method="GET" class="compact-form">
        <label>From Date</label>
        <input type="date" name="from_date" value="<?= htmlspecialchars($from_date) ?>" required>

        <label>To Date</label>
        <input type="date" name="to_date" value="<?= htmlspecialchars($to_date) ?>" required>

        <button type="submit">Generate Report</button>
    </form>

    <div class="page-actions">
        <button onclick="window.print()">Print / Save Report</button>
        <a class="action-btn secondary" href="dashboard.php">Back to Dashboard</a>
    </div>
</div>

<h2>Report Summary</h2>

<div class="cards">
    <div class="card">
        <h3>Total Revenue</h3>
        <p>Rs. <?= number_format($summary["total_revenue"], 2) ?></p>
        <small>Paid payments within selected date range</small>
    </div>

    <div class="card">
        <h3>Completed Sessions</h3>
        <p><?= $summary["completed_sessions"] ?></p>
        <small>Sessions with recorded payments</small>
    </div>

    <div class="card">
        <h3>Total Energy</h3>
        <p><?= number_format($summary["total_energy"], 2) ?> kWh</p>
        <small>Total consumed charging energy</small>
    </div>

    <div class="card">
        <h3>Average Duration</h3>
        <p><?= number_format($summary["avg_duration"], 0) ?> min</p>
        <small>Average charging session duration</small>
    </div>
</div>

<h2>Most Used Charging Station</h2>

<?php if ($top_station && $top_station["station_name"]) { ?>
    <div class="recommend-box">
        <h3><?= htmlspecialchars($top_station["station_name"]) ?></h3>
        <p>
            Location:
            <strong><?= htmlspecialchars($top_station["location"]) ?></strong>
            <br>
            Sessions:
            <strong><?= $top_station["session_count"] ?></strong>
            <br>
            Revenue:
            <strong>Rs. <?= number_format($top_station["revenue"], 2) ?></strong>
        </p>
    </div>
<?php } else { ?>
    <div class="recommend-box">
        <h3>No report data</h3>
        <p>No paid charging sessions found for this date range.</p>
    </div>
<?php } ?>

<h2>Revenue by Charging Station</h2>

<div class="table-wrapper simple-table">
    <table>
        <tr>
            <th>Station</th>
            <th>Location</th>
            <th>Sessions</th>
            <th>Energy</th>
            <th>Revenue</th>
        </tr>

        <?php if ($station_report->num_rows > 0) { ?>
            <?php while ($row = $station_report->fetch_assoc()) { ?>
                <tr>
                    <td><?= htmlspecialchars($row["station_name"]) ?></td>
                    <td><?= htmlspecialchars($row["location"]) ?></td>
                    <td><?= $row["total_sessions"] ?></td>
                    <td><?= number_format($row["total_energy"], 2) ?> kWh</td>
                    <td>
                        <strong>Rs. <?= number_format($row["total_revenue"], 2) ?></strong>
                    </td>
                </tr>
            <?php } ?>
        <?php } else { ?>
            <tr>
                <td colspan="5">No station revenue found for this date range.</td>
            </tr>
        <?php } ?>
    </table>
</div>

<h2>Revenue by Charger Type</h2>

<div class="table-wrapper simple-table">
    <table>
        <tr>
            <th>Charger Type</th>
            <th>Connector</th>
            <th>Power</th>
            <th>Rate</th>
            <th>Sessions</th>
            <th>Energy</th>
            <th>Revenue</th>
        </tr>

        <?php if ($charger_type_report->num_rows > 0) { ?>
            <?php while ($row = $charger_type_report->fetch_assoc()) { ?>
                <tr>
                    <td><?= htmlspecialchars($row["type_name"]) ?></td>
                    <td><?= htmlspecialchars($row["connector_type"]) ?></td>
                    <td><?= $row["power_output_kw"] ?> kW</td>
                    <td>Rs. <?= number_format($row["rate_per_kwh"], 2) ?>/kWh</td>
                    <td><?= $row["total_sessions"] ?></td>
                    <td><?= number_format($row["total_energy"], 2) ?> kWh</td>
                    <td>
                        <strong>Rs. <?= number_format($row["total_revenue"], 2) ?></strong>
                    </td>
                </tr>
            <?php } ?>
        <?php } else { ?>
            <tr>
                <td colspan="7">No charger type revenue found for this date range.</td>
            </tr>
        <?php } ?>
    </table>
</div>

<h2>Daily Revenue</h2>

<div class="table-wrapper simple-table">
    <table>
        <tr>
            <th>Date</th>
            <th>Payments</th>
            <th>Energy</th>
            <th>Revenue</th>
        </tr>

        <?php if ($daily_revenue->num_rows > 0) { ?>
            <?php while ($row = $daily_revenue->fetch_assoc()) { ?>
                <tr>
                    <td><?= htmlspecialchars($row["payment_day"]) ?></td>
                    <td><?= $row["total_payments"] ?></td>
                    <td><?= number_format($row["total_energy"], 2) ?> kWh</td>
                    <td>
                        <strong>Rs. <?= number_format($row["total_revenue"], 2) ?></strong>
                    </td>
                </tr>
            <?php } ?>
        <?php } else { ?>
            <tr>
                <td colspan="4">No daily revenue found for this date range.</td>
            </tr>
        <?php } ?>
    </table>
</div>

<h2>Payment Details</h2>

<div class="table-wrapper">
    <table>
        <tr>
            <th>Payment</th>
            <th>Date</th>
            <th>Customer</th>
            <th>Vehicle</th>
            <th>Station</th>
            <th>Charger</th>
            <th>Energy</th>
            <th>Duration</th>
            <th>Method</th>
            <th>Amount</th>
        </tr>

        <?php if ($payment_details->num_rows > 0) { ?>
            <?php while ($row = $payment_details->fetch_assoc()) { ?>
                <tr>
                    <td>#<?= $row["payment_id"] ?></td>

                    <td>
                        <?= date("Y-m-d", strtotime($row["payment_date"])) ?><br>
                        <small><?= date("h:i A", strtotime($row["payment_date"])) ?></small>
                    </td>

                    <td>
                        <?= htmlspecialchars($row["full_name"]) ?><br>
                        <small><?= htmlspecialchars($row["contact_number"]) ?></small>
                    </td>

                    <td><?= htmlspecialchars($row["vehicle_number"]) ?></td>

                    <td>
                        <?= htmlspecialchars($row["station_name"]) ?><br>
                        <small>Slot <?= htmlspecialchars($row["slot_number"]) ?></small>
                    </td>

                    <td><?= htmlspecialchars($row["type_name"]) ?></td>

                    <td><?= number_format($row["energy_consumed_kwh"], 2) ?> kWh</td>

                    <td><?= $row["session_duration_min"] ?> min</td>

                    <td>
                        <?= htmlspecialchars($row["payment_method"]) ?><br>
                        <small><?= htmlspecialchars($row["transaction_ref"] ?: "N/A") ?></small>
                    </td>

                    <td>
                        <strong>Rs. <?= number_format($row["amount"], 2) ?></strong>
                    </td>
                </tr>
            <?php } ?>
        <?php } else { ?>
            <tr>
                <td colspan="10">No payment records found for this date range.</td>
            </tr>
        <?php } ?>
    </table>
</div>

<?php require_once "_admin_footer.php"; ?>