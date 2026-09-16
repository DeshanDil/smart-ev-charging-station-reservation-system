-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Sep 16, 2026 at 04:59 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `ev_charging_system`
--

DELIMITER $$
--
-- Procedures
--
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_available_slots_by_station` (IN `p_station_id` INT)   BEGIN
    SELECT
        sl.slot_id,
        sl.slot_number,
        sl.slot_status,
        ct.type_name,
        ct.connector_type,
        ct.power_output_kw,
        ct.rate_per_kwh
    FROM charging_slots sl
    JOIN charger_types ct ON sl.type_id = ct.type_id
    WHERE sl.station_id = p_station_id
      AND sl.slot_status = 'Available'
    ORDER BY sl.slot_number;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_cancel_reservation` (IN `p_reservation_id` INT, IN `p_user_id` INT)   BEGIN
    DECLARE v_status VARCHAR(20);

    START TRANSACTION;

    SELECT status
    INTO v_status
    FROM reservations
    WHERE reservation_id = p_reservation_id
      AND user_id = p_user_id;

    IF v_status IS NULL THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Reservation not found for this user.';
    END IF;

    IF v_status = 'Completed' THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Completed reservation cannot be cancelled.';
    END IF;

    UPDATE reservations
    SET status = 'Cancelled'
    WHERE reservation_id = p_reservation_id
      AND user_id = p_user_id;

    COMMIT;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_complete_charging_session` (IN `p_session_id` INT, IN `p_energy_consumed_kwh` DECIMAL(8,2))   BEGIN
    DECLARE v_count INT DEFAULT 0;
    DECLARE v_reservation_id INT;
    DECLARE v_slot_id INT;
    DECLARE v_rate_per_kwh DECIMAL(10,2);
    DECLARE v_cost DECIMAL(10,2);

    START TRANSACTION;

    IF p_energy_consumed_kwh <= 0 THEN
        ROLLBACK;
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Energy consumed must be greater than zero.';
    END IF;

    SELECT COUNT(*)
    INTO v_count
    FROM charging_sessions
    WHERE session_id = p_session_id;

    IF v_count = 0 THEN
        ROLLBACK;
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Charging session not found.';
    END IF;

    SELECT reservation_id
    INTO v_reservation_id
    FROM charging_sessions
    WHERE session_id = p_session_id;

    SELECT r.slot_id, ct.rate_per_kwh
    INTO v_slot_id, v_rate_per_kwh
    FROM reservations r
    JOIN charging_slots sl ON r.slot_id = sl.slot_id
    JOIN charger_types ct ON sl.type_id = ct.type_id
    WHERE r.reservation_id = v_reservation_id;

    SET v_cost = p_energy_consumed_kwh * v_rate_per_kwh;

    UPDATE charging_sessions
    SET
        actual_end_time = NOW(),
        energy_consumed_kwh = p_energy_consumed_kwh,
        session_duration_min = TIMESTAMPDIFF(MINUTE, actual_start_time, NOW()),
        charging_cost = v_cost,
        status = 'Completed'
    WHERE session_id = p_session_id;

    UPDATE reservations
    SET status = 'Completed'
    WHERE reservation_id = v_reservation_id;

    UPDATE charging_slots
    SET slot_status = 'Available'
    WHERE slot_id = v_slot_id
      AND slot_status <> 'Out of Service';

    COMMIT;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_create_reservation` (IN `p_user_id` INT, IN `p_vehicle_id` INT, IN `p_slot_id` INT, IN `p_reservation_date` DATE, IN `p_start_time` TIME, IN `p_current_battery_percent` DECIMAL(5,2), IN `p_target_battery_percent` DECIMAL(5,2))   BEGIN
    DECLARE v_capacity DECIMAL(8,2) DEFAULT NULL;
    DECLARE v_power_output DECIMAL(8,2) DEFAULT NULL;
    DECLARE v_rate_per_kwh DECIMAL(10,2) DEFAULT NULL;

    DECLARE v_required_energy DECIMAL(8,2);
    DECLARE v_estimated_energy DECIMAL(8,2);
    DECLARE v_duration_min INT;
    DECLARE v_estimated_cost DECIMAL(10,2);

    DECLARE v_start_dt DATETIME;
    DECLARE v_end_dt DATETIME;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    IF p_current_battery_percent < 0 OR p_current_battery_percent >= 100 THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Current battery percentage must be between 0 and 99.';
    END IF;

    IF p_target_battery_percent <= p_current_battery_percent OR p_target_battery_percent > 100 THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Target battery percentage must be greater than current percentage and less than or equal to 100.';
    END IF;

    START TRANSACTION;

    SELECT battery_capacity
    INTO v_capacity
    FROM vehicles
    WHERE vehicle_id = p_vehicle_id
      AND user_id = p_user_id;

    IF v_capacity IS NULL THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Selected vehicle does not belong to this user.';
    END IF;

    SELECT ct.power_output_kw, ct.rate_per_kwh
    INTO v_power_output, v_rate_per_kwh
    FROM charging_slots sl
    JOIN charger_types ct ON sl.type_id = ct.type_id
    WHERE sl.slot_id = p_slot_id;

    IF v_power_output IS NULL THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Invalid charging slot selected.';
    END IF;

    SET v_required_energy =
        v_capacity * ((p_target_battery_percent - p_current_battery_percent) / 100);

    SET v_estimated_energy = v_required_energy * 1.10;

    SET v_duration_min = CEIL((v_estimated_energy / v_power_output) * 60);

    IF v_duration_min <= 0 OR v_duration_min > 1440 THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Calculated charging duration is invalid or too long.';
    END IF;

    SET v_estimated_cost = v_estimated_energy * v_rate_per_kwh;

    SET v_start_dt = TIMESTAMP(p_reservation_date, p_start_time);
    SET v_end_dt = DATE_ADD(v_start_dt, INTERVAL v_duration_min MINUTE);

    INSERT INTO reservations
    (
        user_id,
        vehicle_id,
        slot_id,
        reservation_date,
        start_time,
        end_time,
        status,
        current_battery_percent,
        target_battery_percent,
        required_energy_kwh,
        estimated_energy_kwh,
        estimated_duration_min,
        estimated_cost
    )
    VALUES
    (
        p_user_id,
        p_vehicle_id,
        p_slot_id,
        p_reservation_date,
        p_start_time,
        TIME(v_end_dt),
        'Confirmed',
        p_current_battery_percent,
        p_target_battery_percent,
        v_required_energy,
        v_estimated_energy,
        v_duration_min,
        v_estimated_cost
    );

    COMMIT;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_monthly_revenue_report` (IN `p_year` INT, IN `p_month` INT)   BEGIN
    SELECT
        YEAR(payment_date) AS revenue_year,
        MONTH(payment_date) AS revenue_month,
        COUNT(payment_id) AS total_payments,
        SUM(amount) AS total_revenue,
        AVG(amount) AS average_payment,
        MIN(amount) AS minimum_payment,
        MAX(amount) AS maximum_payment
    FROM payments
    WHERE payment_status = 'Paid'
      AND YEAR(payment_date) = p_year
      AND MONTH(payment_date) = p_month
    GROUP BY YEAR(payment_date), MONTH(payment_date);
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_record_payment` (IN `p_session_id` INT, IN `p_payment_method` VARCHAR(20), IN `p_transaction_ref` VARCHAR(100))   BEGIN
    DECLARE v_count INT DEFAULT 0;
    DECLARE v_cost DECIMAL(10,2);

    START TRANSACTION;

    SELECT COUNT(*)
    INTO v_count
    FROM charging_sessions
    WHERE session_id = p_session_id
      AND status = 'Completed';

    IF v_count = 0 THEN
        ROLLBACK;
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Payment can be recorded only for completed charging sessions.';
    END IF;

    SELECT charging_cost
    INTO v_cost
    FROM charging_sessions
    WHERE session_id = p_session_id;

    IF v_cost IS NULL OR v_cost <= 0 THEN
        ROLLBACK;
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Invalid charging cost.';
    END IF;

    SELECT COUNT(*)
    INTO v_count
    FROM payments
    WHERE session_id = p_session_id;

    IF v_count > 0 THEN
        ROLLBACK;
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Payment has already been recorded for this session.';
    END IF;

    INSERT INTO payments
    (
        session_id,
        amount,
        payment_method,
        payment_date,
        payment_status,
        transaction_ref
    )
    VALUES
    (
        p_session_id,
        v_cost,
        p_payment_method,
        NOW(),
        'Paid',
        p_transaction_ref
    );

    COMMIT;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_start_charging_session` (IN `p_reservation_id` INT)   BEGIN
    DECLARE v_count INT DEFAULT 0;
    DECLARE v_existing_session INT DEFAULT 0;

    DECLARE v_status VARCHAR(30);
    DECLARE v_slot_id INT;

    DECLARE v_reservation_date DATE;
    DECLARE v_start_time TIME;
    DECLARE v_end_time TIME;

    DECLARE v_start_dt DATETIME;
    DECLARE v_end_dt DATETIME;
    DECLARE v_allowed_from DATETIME;
    DECLARE v_now DATETIME;

    START TRANSACTION;

    SELECT COUNT(*)
    INTO v_count
    FROM reservations
    WHERE reservation_id = p_reservation_id;

    IF v_count = 0 THEN
        ROLLBACK;
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Reservation ticket not found.';
    END IF;

    SELECT status, slot_id, reservation_date, start_time, end_time
    INTO v_status, v_slot_id, v_reservation_date, v_start_time, v_end_time
    FROM reservations
    WHERE reservation_id = p_reservation_id;

    IF v_status <> 'Confirmed' THEN
        ROLLBACK;
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Only confirmed reservations can be checked in.';
    END IF;

    SELECT COUNT(*)
    INTO v_existing_session
    FROM charging_sessions
    WHERE reservation_id = p_reservation_id;

    IF v_existing_session > 0 THEN
        ROLLBACK;
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Charging session already started for this reservation.';
    END IF;

    SET v_start_dt = TIMESTAMP(v_reservation_date, v_start_time);

    IF v_end_time <= v_start_time THEN
        SET v_end_dt = TIMESTAMP(DATE_ADD(v_reservation_date, INTERVAL 1 DAY), v_end_time);
    ELSE
        SET v_end_dt = TIMESTAMP(v_reservation_date, v_end_time);
    END IF;

    SET v_allowed_from = DATE_SUB(v_start_dt, INTERVAL 15 MINUTE);
    SET v_now = NOW();

    IF v_now < v_allowed_from THEN
        ROLLBACK;
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Check-in is too early. User can check in only 15 minutes before reservation start time.';
    END IF;

    IF v_now > v_end_dt THEN
        UPDATE reservations
        SET status = 'No Show'
        WHERE reservation_id = p_reservation_id;

        UPDATE charging_slots
        SET slot_status = 'Available'
        WHERE slot_id = v_slot_id
          AND slot_status <> 'Out of Service';

        COMMIT;

        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Reservation expired and marked as No Show.';
    END IF;

    INSERT INTO charging_sessions
    (
        reservation_id,
        actual_start_time,
        status
    )
    VALUES
    (
        p_reservation_id,
        NOW(),
        'In Progress'
    );

    UPDATE charging_slots
    SET slot_status = 'Charging'
    WHERE slot_id = v_slot_id
      AND slot_status <> 'Out of Service';

    COMMIT;
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `charger_types`
--

CREATE TABLE `charger_types` (
  `type_id` int(11) NOT NULL,
  `type_name` varchar(60) NOT NULL,
  `connector_type` varchar(60) NOT NULL,
  `power_output_kw` decimal(6,2) NOT NULL,
  `rate_per_kwh` decimal(10,2) NOT NULL
) ;

--
-- Dumping data for table `charger_types`
--

INSERT INTO `charger_types` (`type_id`, `type_name`, `connector_type`, `power_output_kw`, `rate_per_kwh`) VALUES
(1, 'Slow AC Charger', 'Type 2', 7.40, 80.00),
(2, 'Fast DC Charger', 'CCS Combo 2', 50.00, 120.00),
(3, 'Rapid DC Charger', 'CHAdeMO', 100.00, 160.00),
(4, 'Ultra Fast Charger', 'CCS Combo 2', 150.00, 190.00);

-- --------------------------------------------------------

--
-- Table structure for table `charging_sessions`
--

CREATE TABLE `charging_sessions` (
  `session_id` int(11) NOT NULL,
  `reservation_id` int(11) NOT NULL,
  `actual_start_time` datetime NOT NULL,
  `actual_end_time` datetime DEFAULT NULL,
  `energy_consumed_kwh` decimal(8,2) DEFAULT 0.00,
  `session_duration_min` int(11) DEFAULT 0,
  `charging_cost` decimal(10,2) DEFAULT 0.00,
  `status` enum('In Progress','Completed','Stopped') NOT NULL DEFAULT 'In Progress',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ;

--
-- Dumping data for table `charging_sessions`
--

INSERT INTO `charging_sessions` (`session_id`, `reservation_id`, `actual_start_time`, `actual_end_time`, `energy_consumed_kwh`, `session_duration_min`, `charging_cost`, `status`, `created_at`) VALUES
(1, 1, '2026-07-28 09:02:00', '2026-07-28 09:50:00', 25.80, 48, 3096.00, 'Completed', '2026-08-03 19:12:51'),
(2, 2, '2026-07-29 11:32:00', '2026-07-29 12:20:00', 39.20, 48, 4704.00, 'Completed', '2026-08-03 19:12:51'),
(3, 3, '2026-07-30 14:05:00', '2026-07-30 14:35:00', 29.10, 30, 4656.00, 'Completed', '2026-08-03 19:12:51'),
(4, 4, '2026-07-31 16:20:00', '2026-07-31 16:55:00', 32.80, 35, 5248.00, 'Completed', '2026-08-03 19:12:51'),
(5, 5, '2026-08-01 10:02:00', '2026-08-01 10:20:00', 37.90, 18, 7201.00, 'Completed', '2026-08-03 19:12:51'),
(6, 6, '2026-08-02 20:05:00', '2026-08-02 23:55:00', 31.90, 230, 2552.00, 'Completed', '2026-08-03 19:12:51');

--
-- Triggers `charging_sessions`
--
DELIMITER $$
CREATE TRIGGER `trg_before_session_update` BEFORE UPDATE ON `charging_sessions` FOR EACH ROW BEGIN
    DECLARE v_rate DECIMAL(10,2);

    IF NEW.actual_end_time IS NOT NULL AND NEW.actual_start_time IS NOT NULL THEN
        SET NEW.session_duration_min =
            TIMESTAMPDIFF(MINUTE, NEW.actual_start_time, NEW.actual_end_time);
    END IF;

    IF NEW.energy_consumed_kwh IS NOT NULL THEN
        SELECT ct.rate_per_kwh
        INTO v_rate
        FROM reservations r
        JOIN charging_slots sl ON r.slot_id = sl.slot_id
        JOIN charger_types ct ON sl.type_id = ct.type_id
        WHERE r.reservation_id = NEW.reservation_id;

        SET NEW.charging_cost = NEW.energy_consumed_kwh * v_rate;
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `charging_slots`
--

CREATE TABLE `charging_slots` (
  `slot_id` int(11) NOT NULL,
  `station_id` int(11) NOT NULL,
  `type_id` int(11) NOT NULL,
  `slot_number` varchar(20) NOT NULL,
  `slot_status` enum('Available','Reserved','Charging','Out of Service') NOT NULL DEFAULT 'Available',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `charging_slots`
--

INSERT INTO `charging_slots` (`slot_id`, `station_id`, `type_id`, `slot_number`, `slot_status`, `created_at`) VALUES
(1, 1, 1, 'CMB-S1', 'Available', '2026-08-03 19:12:51'),
(2, 1, 1, 'CMB-S2', 'Available', '2026-08-03 19:12:51'),
(3, 1, 2, 'CMB-F1', 'Available', '2026-08-03 19:12:51'),
(4, 1, 2, 'CMB-F2', 'Available', '2026-08-03 19:12:51'),
(5, 1, 3, 'CMB-R1', 'Available', '2026-08-03 19:12:51'),
(6, 1, 4, 'CMB-U1', 'Available', '2026-08-03 19:12:51'),
(7, 2, 1, 'KDY-S1', 'Available', '2026-08-03 19:12:51'),
(8, 2, 1, 'KDY-S2', 'Available', '2026-08-03 19:12:51'),
(9, 2, 2, 'KDY-F1', 'Available', '2026-08-03 19:12:51'),
(10, 2, 2, 'KDY-F2', 'Available', '2026-08-03 19:12:51'),
(11, 2, 3, 'KDY-R1', 'Available', '2026-08-03 19:12:51'),
(12, 2, 4, 'KDY-U1', 'Available', '2026-08-03 19:12:51'),
(13, 3, 1, 'KRN-S1', 'Available', '2026-08-03 19:12:51'),
(14, 3, 1, 'KRN-S2', 'Available', '2026-08-03 19:12:51'),
(15, 3, 2, 'KRN-F1', 'Available', '2026-08-03 19:12:51'),
(16, 3, 2, 'KRN-F2', 'Available', '2026-08-03 19:12:51'),
(17, 3, 3, 'KRN-R1', 'Available', '2026-08-03 19:12:51'),
(18, 3, 4, 'KRN-U1', 'Available', '2026-08-03 19:12:51'),
(19, 4, 1, 'GAL-S1', 'Available', '2026-08-03 19:12:51'),
(20, 4, 2, 'GAL-F1', 'Available', '2026-08-03 19:12:51'),
(21, 4, 2, 'GAL-F2', 'Available', '2026-08-03 19:12:51'),
(22, 4, 3, 'GAL-R1', 'Available', '2026-08-03 19:12:51'),
(23, 4, 4, 'GAL-U1', 'Available', '2026-08-03 19:12:51'),
(24, 5, 1, 'NEG-S1', 'Available', '2026-08-03 19:12:51'),
(25, 5, 2, 'NEG-F1', 'Out of Service', '2026-08-03 19:12:51'),
(26, 5, 2, 'NEG-F2', 'Available', '2026-08-03 19:12:51'),
(27, 5, 3, 'NEG-R1', 'Available', '2026-08-03 19:12:51'),
(28, 5, 4, 'NEG-U1', 'Available', '2026-08-03 19:12:51');

-- --------------------------------------------------------

--
-- Table structure for table `charging_stations`
--

CREATE TABLE `charging_stations` (
  `station_id` int(11) NOT NULL,
  `station_name` varchar(120) NOT NULL,
  `location` varchar(200) NOT NULL,
  `contact_number` varchar(15) DEFAULT NULL,
  `total_slots` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ;

--
-- Dumping data for table `charging_stations`
--

INSERT INTO `charging_stations` (`station_id`, `station_name`, `location`, `contact_number`, `total_slots`, `created_at`) VALUES
(1, 'Colombo Central EV Station', 'Colombo', '0112345678', 6, '2026-08-03 19:12:51'),
(2, 'Kandy City EV Station', 'Kandy', '0812345678', 6, '2026-08-03 19:12:51'),
(3, 'Kurunegala EV Station', 'Kurunegala', '0372345678', 6, '2026-08-03 19:12:51'),
(4, 'Galle Highway EV Station', 'Galle', '0912345678', 5, '2026-08-03 19:12:51'),
(5, 'Negombo Beach EV Station', 'Negombo', '0312345678', 5, '2026-08-03 19:12:51');

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `payment_id` int(11) NOT NULL,
  `session_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_method` enum('Cash','Card','Online') NOT NULL,
  `payment_date` datetime NOT NULL DEFAULT current_timestamp(),
  `payment_status` enum('Paid','Failed','Refunded') NOT NULL DEFAULT 'Paid',
  `transaction_ref` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ;

--
-- Dumping data for table `payments`
--

INSERT INTO `payments` (`payment_id`, `session_id`, `amount`, `payment_method`, `payment_date`, `payment_status`, `transaction_ref`, `created_at`) VALUES
(1, 1, 3096.00, 'Cash', '2026-07-28 09:55:00', 'Paid', 'PAY-CASH-0001', '2026-08-03 19:12:51'),
(2, 2, 4704.00, 'Card', '2026-07-29 12:25:00', 'Paid', 'PAY-CARD-0002', '2026-08-03 19:12:51'),
(3, 3, 4656.00, 'Online', '2026-07-30 14:40:00', 'Paid', 'PAY-ONLINE-0003', '2026-08-03 19:12:51'),
(4, 4, 5248.00, 'Cash', '2026-07-31 17:00:00', 'Paid', 'PAY-CASH-0004', '2026-08-03 19:12:51'),
(5, 5, 7201.00, 'Card', '2026-08-01 10:25:00', 'Paid', 'PAY-CARD-0005', '2026-08-03 19:12:51'),
(6, 6, 2552.00, 'Online', '2026-08-02 23:58:00', 'Paid', 'PAY-ONLINE-0006', '2026-08-03 19:12:51');

-- --------------------------------------------------------

--
-- Table structure for table `reservations`
--

CREATE TABLE `reservations` (
  `reservation_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `vehicle_id` int(11) NOT NULL,
  `slot_id` int(11) NOT NULL,
  `reservation_date` date NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `status` enum('Pending','Confirmed','Completed','Cancelled','No Show') NOT NULL DEFAULT 'Pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `current_battery_percent` decimal(5,2) DEFAULT 0.00,
  `target_battery_percent` decimal(5,2) DEFAULT 80.00,
  `required_energy_kwh` decimal(8,2) DEFAULT 0.00,
  `estimated_duration_min` int(11) DEFAULT 0,
  `estimated_energy_kwh` decimal(8,2) DEFAULT 0.00,
  `estimated_cost` decimal(10,2) DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `reservations`
--

INSERT INTO `reservations` (`reservation_id`, `user_id`, `vehicle_id`, `slot_id`, `reservation_date`, `start_time`, `end_time`, `status`, `created_at`, `current_battery_percent`, `target_battery_percent`, `required_energy_kwh`, `estimated_duration_min`, `estimated_energy_kwh`, `estimated_cost`) VALUES
(1, 2, 1, 3, '2026-07-28', '09:00:00', '09:48:00', 'Completed', '2026-08-03 19:12:51', 20.00, 80.00, 24.00, 32, 26.40, 3168.00),
(2, 3, 2, 9, '2026-07-29', '11:30:00', '12:19:00', 'Completed', '2026-08-03 19:12:51', 25.00, 85.00, 36.00, 48, 39.60, 4752.00),
(3, 4, 3, 17, '2026-07-30', '14:00:00', '14:28:00', 'Completed', '2026-08-03 19:12:51', 30.00, 70.00, 25.60, 17, 28.16, 4505.60),
(4, 2, 4, 22, '2026-07-31', '16:15:00', '16:49:00', 'Completed', '2026-08-03 19:12:51', 15.00, 75.00, 30.60, 21, 33.66, 5385.60),
(5, 2, 5, 28, '2026-08-01', '10:00:00', '10:16:00', 'Completed', '2026-08-03 19:12:51', 40.00, 100.00, 36.30, 16, 39.93, 7586.70),
(6, 2, 6, 1, '2026-08-02', '20:00:00', '23:46:00', 'Completed', '2026-08-03 19:12:51', 10.00, 80.00, 29.54, 264, 32.49, 2599.52),
(7, 2, 1, 4, '2026-08-02', '13:00:00', '13:44:00', 'Cancelled', '2026-08-03 19:12:51', 35.00, 90.00, 22.00, 30, 24.20, 2904.00),
(8, 3, 2, 10, '2026-08-03', '08:30:00', '09:18:00', 'No Show', '2026-08-03 19:12:51', 20.00, 80.00, 36.00, 48, 39.60, 4752.00),
(9, 4, 3, 15, '2026-08-04', '00:52:51', '01:39:51', 'Confirmed', '2026-08-03 19:12:51', 25.00, 80.00, 35.20, 47, 38.72, 4646.40),
(10, 2, 4, 20, '2026-08-04', '18:00:00', '18:41:00', 'Confirmed', '2026-08-03 19:12:51', 30.00, 90.00, 30.60, 41, 33.66, 4039.20),
(11, 2, 5, 6, '2026-08-05', '09:00:00', '09:16:00', 'Confirmed', '2026-08-03 19:12:51', 40.00, 100.00, 36.30, 16, 39.93, 7586.70),
(12, 4, 3, 11, '2026-08-05', '14:30:00', '14:58:00', 'Confirmed', '2026-08-03 19:12:51', 25.00, 70.00, 28.80, 20, 31.68, 5068.80),
(13, 2, 1, 24, '2026-08-06', '07:30:00', '11:28:00', 'Confirmed', '2026-08-03 19:12:51', 20.00, 85.00, 26.00, 232, 28.60, 2288.00),
(14, 2, 5, 23, '2026-08-06', '23:00:00', '23:28:00', 'Confirmed', '2026-08-03 19:12:51', 20.00, 85.00, 39.33, 18, 43.26, 8218.93),
(15, 2, 6, 27, '2026-08-07', '10:15:00', '10:42:00', 'Confirmed', '2026-08-03 19:12:51', 45.00, 85.00, 16.88, 12, 18.57, 2970.88),
(16, 2, 6, 6, '2026-09-06', '18:30:00', '18:41:00', 'Confirmed', '2026-09-06 09:27:25', 25.00, 80.00, 23.21, 11, 25.53, 4850.70);

--
-- Triggers `reservations`
--
DELIMITER $$
CREATE TRIGGER `trg_before_reservation_insert` BEFORE INSERT ON `reservations` FOR EACH ROW BEGIN
    DECLARE v_count INT DEFAULT 0;
    DECLARE v_slot_status VARCHAR(30);
    DECLARE v_vehicle_owner INT;

    DECLARE v_new_start DATETIME;
    DECLARE v_new_end DATETIME;

    SET v_new_start = TIMESTAMP(NEW.reservation_date, NEW.start_time);

    IF NEW.end_time <= NEW.start_time THEN
        SET v_new_end = TIMESTAMP(DATE_ADD(NEW.reservation_date, INTERVAL 1 DAY), NEW.end_time);
    ELSE
        SET v_new_end = TIMESTAMP(NEW.reservation_date, NEW.end_time);
    END IF;

    SELECT slot_status
    INTO v_slot_status
    FROM charging_slots
    WHERE slot_id = NEW.slot_id;

    IF v_slot_status = 'Out of Service' THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Selected charging slot is out of service.';
    END IF;

    SELECT user_id
    INTO v_vehicle_owner
    FROM vehicles
    WHERE vehicle_id = NEW.vehicle_id;

    IF v_vehicle_owner <> NEW.user_id THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Selected vehicle does not belong to this user.';
    END IF;

    SELECT COUNT(*)
    INTO v_count
    FROM reservations r
    WHERE r.slot_id = NEW.slot_id
      AND r.status IN ('Pending', 'Confirmed')
      AND v_new_start <
          CASE
              WHEN r.end_time <= r.start_time
              THEN TIMESTAMP(DATE_ADD(r.reservation_date, INTERVAL 1 DAY), r.end_time)
              ELSE TIMESTAMP(r.reservation_date, r.end_time)
          END
      AND v_new_end > TIMESTAMP(r.reservation_date, r.start_time);

    IF v_count > 0 THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Selected charging slot is already reserved for this time period.';
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `reservation_audit_logs`
--

CREATE TABLE `reservation_audit_logs` (
  `audit_id` int(11) NOT NULL,
  `reservation_id` int(11) NOT NULL,
  `old_status` varchar(20) DEFAULT NULL,
  `new_status` varchar(20) DEFAULT NULL,
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `note` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `reservation_audit_logs`
--

INSERT INTO `reservation_audit_logs` (`audit_id`, `reservation_id`, `old_status`, `new_status`, `changed_at`, `note`) VALUES
(1, 1, 'Confirmed', 'Completed', '2026-08-03 19:12:51', 'Charging session completed successfully.'),
(2, 2, 'Confirmed', 'Completed', '2026-08-03 19:12:51', 'Charging session completed successfully.'),
(3, 3, 'Confirmed', 'Completed', '2026-08-03 19:12:51', 'Charging session completed successfully.'),
(4, 4, 'Confirmed', 'Completed', '2026-08-03 19:12:51', 'Charging session completed successfully.'),
(5, 5, 'Confirmed', 'Completed', '2026-08-03 19:12:51', 'Charging session completed successfully.'),
(6, 6, 'Confirmed', 'Completed', '2026-08-03 19:12:51', 'Charging session completed successfully.'),
(7, 7, 'Confirmed', 'Cancelled', '2026-08-03 19:12:51', 'User cancelled the reservation.'),
(8, 8, 'Confirmed', 'No Show', '2026-08-03 19:12:51', 'User did not arrive within the check-in time window.');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `contact_number` varchar(15) DEFAULT NULL,
  `user_role` enum('Admin','User') NOT NULL DEFAULT 'User',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `full_name`, `email`, `password`, `contact_number`, `user_role`, `created_at`) VALUES
(1, 'System Administrator', 'admin@evsystem.lk', 'admin123', '0771000001', 'Admin', '2026-07-27 15:36:46'),
(2, 'Nimal Perera', 'nimal@gmail.com', 'user123', '0771234567', 'User', '2026-07-27 15:36:46'),
(3, 'Kasun Silva', 'kasun@gmail.com', 'user123', '0712345678', 'User', '2026-07-27 15:36:46'),
(4, 'Amaya Fernando', 'amaya@gmail.com', 'user123', '0763456789', 'User', '2026-07-27 15:36:46');

-- --------------------------------------------------------

--
-- Table structure for table `vehicles`
--

CREATE TABLE `vehicles` (
  `vehicle_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `vehicle_number` varchar(20) NOT NULL,
  `vehicle_model` varchar(80) NOT NULL,
  `battery_capacity` decimal(6,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ;

--
-- Dumping data for table `vehicles`
--

INSERT INTO `vehicles` (`vehicle_id`, `user_id`, `vehicle_number`, `vehicle_model`, `battery_capacity`, `created_at`) VALUES
(1, 2, 'EV-CAR-1001', 'Nissan Leaf', 40.00, '2026-08-03 19:12:51'),
(2, 3, 'EV-CAR-1002', 'Tesla Model 3', 60.00, '2026-08-03 19:12:51'),
(3, 4, 'EV-CAR-1003', 'Hyundai Kona Electric', 64.00, '2026-08-03 19:12:51'),
(4, 2, 'EV-CAR-1004', 'MG ZS EV', 51.00, '2026-08-03 19:12:51'),
(5, 2, 'EV-CAR-1005', 'BYD Atto 3', 60.50, '2026-08-03 19:12:51'),
(6, 2, 'EV-CAR-1006', 'BMW i3', 42.20, '2026-08-03 19:12:51');

-- --------------------------------------------------------

--
-- Stand-in structure for view `vw_admin_dashboard_summary`
-- (See below for the actual view)
--
CREATE TABLE `vw_admin_dashboard_summary` (
`total_registered_users` bigint(21)
,`total_stations` bigint(21)
,`total_slots` bigint(21)
,`total_reservations` bigint(21)
,`completed_sessions` bigint(21)
,`total_revenue` decimal(32,2)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `vw_monthly_revenue`
-- (See below for the actual view)
--
CREATE TABLE `vw_monthly_revenue` (
`revenue_year` int(4)
,`revenue_month` int(2)
,`total_payments` bigint(21)
,`total_revenue` decimal(32,2)
,`average_payment` decimal(14,6)
,`minimum_payment` decimal(10,2)
,`maximum_payment` decimal(10,2)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `vw_reservation_details`
-- (See below for the actual view)
--
CREATE TABLE `vw_reservation_details` (
`reservation_id` int(11)
,`full_name` varchar(100)
,`email` varchar(100)
,`vehicle_number` varchar(20)
,`vehicle_model` varchar(80)
,`station_name` varchar(120)
,`location` varchar(200)
,`slot_number` varchar(20)
,`type_name` varchar(60)
,`reservation_date` date
,`start_time` time
,`end_time` time
,`status` enum('Pending','Confirmed','Completed','Cancelled','No Show')
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `vw_station_slot_availability`
-- (See below for the actual view)
--
CREATE TABLE `vw_station_slot_availability` (
`station_id` int(11)
,`station_name` varchar(120)
,`location` varchar(200)
,`type_name` varchar(60)
,`connector_type` varchar(60)
,`power_output_kw` decimal(6,2)
,`total_slots` bigint(21)
,`available_slots` decimal(22,0)
,`reserved_slots` decimal(22,0)
,`charging_slots` decimal(22,0)
,`out_of_service_slots` decimal(22,0)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `vw_user_charging_history`
-- (See below for the actual view)
--
CREATE TABLE `vw_user_charging_history` (
`user_id` int(11)
,`full_name` varchar(100)
,`vehicle_number` varchar(20)
,`station_name` varchar(120)
,`slot_number` varchar(20)
,`reservation_date` date
,`actual_start_time` datetime
,`actual_end_time` datetime
,`energy_consumed_kwh` decimal(8,2)
,`session_duration_min` int(11)
,`charging_cost` decimal(10,2)
,`payment_method` enum('Cash','Card','Online')
,`payment_status` enum('Paid','Failed','Refunded')
);

-- --------------------------------------------------------

--
-- Structure for view `vw_admin_dashboard_summary`
--
DROP TABLE IF EXISTS `vw_admin_dashboard_summary`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_admin_dashboard_summary`  AS SELECT (select count(0) from `users` where `users`.`user_role` = 'User') AS `total_registered_users`, (select count(0) from `charging_stations`) AS `total_stations`, (select count(0) from `charging_slots`) AS `total_slots`, (select count(0) from `reservations`) AS `total_reservations`, (select count(0) from `charging_sessions` where `charging_sessions`.`status` = 'Completed') AS `completed_sessions`, (select ifnull(sum(`payments`.`amount`),0) from `payments` where `payments`.`payment_status` = 'Paid') AS `total_revenue` ;

-- --------------------------------------------------------

--
-- Structure for view `vw_monthly_revenue`
--
DROP TABLE IF EXISTS `vw_monthly_revenue`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_monthly_revenue`  AS SELECT year(`payments`.`payment_date`) AS `revenue_year`, month(`payments`.`payment_date`) AS `revenue_month`, count(`payments`.`payment_id`) AS `total_payments`, sum(`payments`.`amount`) AS `total_revenue`, avg(`payments`.`amount`) AS `average_payment`, min(`payments`.`amount`) AS `minimum_payment`, max(`payments`.`amount`) AS `maximum_payment` FROM `payments` WHERE `payments`.`payment_status` = 'Paid' GROUP BY year(`payments`.`payment_date`), month(`payments`.`payment_date`) ;

-- --------------------------------------------------------

--
-- Structure for view `vw_reservation_details`
--
DROP TABLE IF EXISTS `vw_reservation_details`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_reservation_details`  AS SELECT `r`.`reservation_id` AS `reservation_id`, `u`.`full_name` AS `full_name`, `u`.`email` AS `email`, `v`.`vehicle_number` AS `vehicle_number`, `v`.`vehicle_model` AS `vehicle_model`, `st`.`station_name` AS `station_name`, `st`.`location` AS `location`, `sl`.`slot_number` AS `slot_number`, `ct`.`type_name` AS `type_name`, `r`.`reservation_date` AS `reservation_date`, `r`.`start_time` AS `start_time`, `r`.`end_time` AS `end_time`, `r`.`status` AS `status` FROM (((((`reservations` `r` join `users` `u` on(`r`.`user_id` = `u`.`user_id`)) join `vehicles` `v` on(`r`.`vehicle_id` = `v`.`vehicle_id`)) join `charging_slots` `sl` on(`r`.`slot_id` = `sl`.`slot_id`)) join `charging_stations` `st` on(`sl`.`station_id` = `st`.`station_id`)) join `charger_types` `ct` on(`sl`.`type_id` = `ct`.`type_id`)) ;

-- --------------------------------------------------------

--
-- Structure for view `vw_station_slot_availability`
--
DROP TABLE IF EXISTS `vw_station_slot_availability`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_station_slot_availability`  AS SELECT `cs`.`station_id` AS `station_id`, `cs`.`station_name` AS `station_name`, `cs`.`location` AS `location`, `ct`.`type_name` AS `type_name`, `ct`.`connector_type` AS `connector_type`, `ct`.`power_output_kw` AS `power_output_kw`, count(`sl`.`slot_id`) AS `total_slots`, sum(case when `sl`.`slot_status` = 'Available' then 1 else 0 end) AS `available_slots`, sum(case when `sl`.`slot_status` = 'Reserved' then 1 else 0 end) AS `reserved_slots`, sum(case when `sl`.`slot_status` = 'Charging' then 1 else 0 end) AS `charging_slots`, sum(case when `sl`.`slot_status` = 'Out of Service' then 1 else 0 end) AS `out_of_service_slots` FROM ((`charging_stations` `cs` join `charging_slots` `sl` on(`cs`.`station_id` = `sl`.`station_id`)) join `charger_types` `ct` on(`sl`.`type_id` = `ct`.`type_id`)) GROUP BY `cs`.`station_id`, `cs`.`station_name`, `cs`.`location`, `ct`.`type_name`, `ct`.`connector_type`, `ct`.`power_output_kw` ;

-- --------------------------------------------------------

--
-- Structure for view `vw_user_charging_history`
--
DROP TABLE IF EXISTS `vw_user_charging_history`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_user_charging_history`  AS SELECT `u`.`user_id` AS `user_id`, `u`.`full_name` AS `full_name`, `v`.`vehicle_number` AS `vehicle_number`, `st`.`station_name` AS `station_name`, `sl`.`slot_number` AS `slot_number`, `r`.`reservation_date` AS `reservation_date`, `s`.`actual_start_time` AS `actual_start_time`, `s`.`actual_end_time` AS `actual_end_time`, `s`.`energy_consumed_kwh` AS `energy_consumed_kwh`, `s`.`session_duration_min` AS `session_duration_min`, `s`.`charging_cost` AS `charging_cost`, `p`.`payment_method` AS `payment_method`, `p`.`payment_status` AS `payment_status` FROM ((((((`users` `u` join `reservations` `r` on(`u`.`user_id` = `r`.`user_id`)) join `vehicles` `v` on(`r`.`vehicle_id` = `v`.`vehicle_id`)) join `charging_slots` `sl` on(`r`.`slot_id` = `sl`.`slot_id`)) join `charging_stations` `st` on(`sl`.`station_id` = `st`.`station_id`)) join `charging_sessions` `s` on(`r`.`reservation_id` = `s`.`reservation_id`)) left join `payments` `p` on(`s`.`session_id` = `p`.`session_id`)) ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `charger_types`
--
ALTER TABLE `charger_types`
  ADD PRIMARY KEY (`type_id`);

--
-- Indexes for table `charging_sessions`
--
ALTER TABLE `charging_sessions`
  ADD PRIMARY KEY (`session_id`),
  ADD UNIQUE KEY `reservation_id` (`reservation_id`);

--
-- Indexes for table `charging_slots`
--
ALTER TABLE `charging_slots`
  ADD PRIMARY KEY (`slot_id`),
  ADD UNIQUE KEY `uq_station_slot` (`station_id`,`slot_number`),
  ADD KEY `fk_slots_charger_types` (`type_id`);

--
-- Indexes for table `charging_stations`
--
ALTER TABLE `charging_stations`
  ADD PRIMARY KEY (`station_id`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`payment_id`),
  ADD UNIQUE KEY `session_id` (`session_id`);

--
-- Indexes for table `reservations`
--
ALTER TABLE `reservations`
  ADD PRIMARY KEY (`reservation_id`),
  ADD KEY `fk_reservations_users` (`user_id`),
  ADD KEY `fk_reservations_vehicles` (`vehicle_id`),
  ADD KEY `fk_reservations_slots` (`slot_id`);

--
-- Indexes for table `reservation_audit_logs`
--
ALTER TABLE `reservation_audit_logs`
  ADD PRIMARY KEY (`audit_id`),
  ADD KEY `fk_audit_reservations` (`reservation_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `vehicles`
--
ALTER TABLE `vehicles`
  ADD PRIMARY KEY (`vehicle_id`),
  ADD UNIQUE KEY `vehicle_number` (`vehicle_number`),
  ADD KEY `fk_vehicles_users` (`user_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `charger_types`
--
ALTER TABLE `charger_types`
  MODIFY `type_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `charging_sessions`
--
ALTER TABLE `charging_sessions`
  MODIFY `session_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `charging_slots`
--
ALTER TABLE `charging_slots`
  MODIFY `slot_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=29;

--
-- AUTO_INCREMENT for table `charging_stations`
--
ALTER TABLE `charging_stations`
  MODIFY `station_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `payment_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `reservations`
--
ALTER TABLE `reservations`
  MODIFY `reservation_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `reservation_audit_logs`
--
ALTER TABLE `reservation_audit_logs`
  MODIFY `audit_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `vehicles`
--
ALTER TABLE `vehicles`
  MODIFY `vehicle_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `charging_sessions`
--
ALTER TABLE `charging_sessions`
  ADD CONSTRAINT `fk_sessions_reservations` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`reservation_id`) ON UPDATE CASCADE;

--
-- Constraints for table `charging_slots`
--
ALTER TABLE `charging_slots`
  ADD CONSTRAINT `fk_slots_charger_types` FOREIGN KEY (`type_id`) REFERENCES `charger_types` (`type_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_slots_stations` FOREIGN KEY (`station_id`) REFERENCES `charging_stations` (`station_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `fk_payments_sessions` FOREIGN KEY (`session_id`) REFERENCES `charging_sessions` (`session_id`) ON UPDATE CASCADE;

--
-- Constraints for table `reservations`
--
ALTER TABLE `reservations`
  ADD CONSTRAINT `fk_reservations_slots` FOREIGN KEY (`slot_id`) REFERENCES `charging_slots` (`slot_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_reservations_users` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_reservations_vehicles` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`vehicle_id`) ON UPDATE CASCADE;

--
-- Constraints for table `reservation_audit_logs`
--
ALTER TABLE `reservation_audit_logs`
  ADD CONSTRAINT `fk_audit_reservations` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`reservation_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `vehicles`
--
ALTER TABLE `vehicles`
  ADD CONSTRAINT `fk_vehicles_users` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
