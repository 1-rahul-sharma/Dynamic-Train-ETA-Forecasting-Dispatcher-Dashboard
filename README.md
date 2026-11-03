# Train ETA Dashboard

A PHP and MySQL demonstration dashboard for monitoring train services, estimating arrival times, and simulating dispatcher updates.

## Features

- Train fleet overview with current stations, delay status, and route links.
- Per-train route progress and estimated arrival times.
- Network board of upcoming arrivals and station-level delay summaries.
- Dispatcher controls to adjust delays, update a train's current station, and record disruptions.
- Recent-operations audit trail stored in MySQL.
- Sample trains and schedules for trying the dashboard locally.

> **Note:** This project uses demonstration timetable data and a simple forecast rule. It does not connect to a live railway data feed and is not intended for operational use.

## Requirements

- Windows with [XAMPP](https://www.apachefriends.org/) or another PHP/MySQL environment.
- PHP with the `mysqli` extension enabled.
- MySQL or MariaDB.

## Run locally with XAMPP

1. Copy or clone this project into XAMPP's `htdocs` directory. For example:

   ```text
   C:\xampp\htdocs\CA3Project
   ```

2. Start **Apache** and **MySQL** from the XAMPP Control Panel.
3. Open [phpMyAdmin](http://localhost/phpmyadmin), select **Import**, choose `setup.sql`, and run the import. This creates the `rail_simple` database, its tables, and sample train data.
4. Open [http://localhost/CA3Project/](http://localhost/CA3Project/) in your browser.

The app connects to MySQL at `localhost` using the XAMPP default `root` account with no password. If your local MySQL credentials differ, update the connection in `index.php` before running the project.

## Using the dashboard

Choose a train to inspect its route and ETA forecasts. The scenario controls can add five minutes of delay, apply up to three minutes of recovery, change the train's current station, or record a disruption. Changes are saved in the local database and appear in the recent-operations log.

The forecast estimates remaining delay by reducing it by three minutes at each successive scheduled stop, never below zero. This is a demonstration assumption, not a predictive model based on live operations.

## Project files

| File | Description |
| --- | --- |
| `index.php` | Dashboard interface and PHP application logic. |
| `setup.sql` | Database schema and sample train/schedule data. |

The separate `Train_ETA_Project_Report.pdf` is not included in this repository.

## Troubleshooting

- **Database connection error:** Confirm MySQL is running, `setup.sql` was imported, and the credentials in `index.php` match your local MySQL setup.
- **Page downloads instead of opening:** Confirm Apache is running and open the project through `http://localhost/CA3Project/`, not as a file on disk.
- **Missing `mysqli` errors:** Enable/install PHP's `mysqli` extension in your PHP environment.
