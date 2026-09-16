# Smart EV Charging Station Reservation System

A PHP and MariaDB web application for managing electric vehicle charging stations, reservations and charging sessions.

## Project overview

The system allows electric vehicle users to register vehicles, select charging stations and reserve charging slots based on their battery requirements. The estimated charging energy, duration and cost are calculated automatically.

Administrators can manage users, vehicles, charging stations, charger types, charging slots, reservations, charging sessions and payments.

## Main features

* User registration and login
* Vehicle registration with battery capacity
* Multiple charging stations and charging slots
* Charger types with connector type, power output and charging rate
* Automatic charging-energy calculation
* Automatic charging-duration calculation
* Estimated charging-cost calculation
* Reservation conflict detection
* Reservation cancellation
* Check-in and charging-session management
* Charging-session completion and energy recording
* Payment recording
* Reservation audit logs
* User charging-history view
* Administrator dashboard
* Monthly revenue reporting

## Technologies used

* PHP 8.2.12
* MySQL / MariaDB 10.4.32
* HTML
* CSS
* JavaScript
* XAMPP
* Apache

## Database design

The database is named `ev_charging_system`.

It contains:

* 9 relational tables
* 4 database views
* 7 stored procedures
* 2 triggers
* Primary keys, foreign keys and unique constraints
* Transaction handling for important reservation and charging operations

The main relationships are:

* Users can register multiple vehicles.
* Charging stations contain multiple charging slots.
* Each charging slot uses one charger type.
* Users reserve charging slots for their vehicles.
* A reservation can create one charging session.
* A completed charging session can have one payment.
* Reservation status changes are recorded in the audit log.

## My contribution

* Designed and developed the PHP application.
* Designed the MariaDB database structure.
* Implemented user and administrator workflows.
* Implemented charging-duration and cost calculations.
* Added reservation conflict validation.
* Added stored procedures, triggers and database views.
* Tested reservation, charging-session and payment workflows.

## Installation

1. Install XAMPP.
2. Copy the project folder into the XAMPP `htdocs` directory.
3. Start Apache and MySQL.
4. Create a database named `ev_charging_system`.
5. Import `database/ev_charging_system.sql` using phpMyAdmin.
6. Update the database connection settings in the project configuration file.
7. Open the application through `http://localhost/`.

## Project screenshots

### Login page
![Login page](screenshots/login.png)

### User dashboard
![User dashboard](screenshots/user-dashboard.png)

### Charging stations
![Charging stations](screenshots/charging-stations.png)

### Reservation page
![Reservation page](screenshots/reservation.png)

### Administrator dashboard
![Administrator dashboard](screenshots/admin-dashboard.png)

### Payment page
![Payment page](screenshots/payment.png)

## Project status

Academic project completed as part of my Computer System Engineering studies at NSBM Green University.
