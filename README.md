# Dynamic Train ETA Forecasting & Dispatcher Dashboard

A PHP and MySQL web application that demonstrates a train operations dashboard. Explore a sample rail fleet, view route-level arrival estimates, analyze forecast delays by station, and simulate dispatcher updates.

> **Project scope:** This is an educational demonstration that uses seeded timetable data and a simple forecast rule. It does not retrieve live railway data and is not suitable for real-world dispatching.

## Dashboard capabilities

- **Fleet overview:** View train numbers, names, current stations, delay minutes, and service status.
- **Train route detail:** Select a service to see its route, current position, progress, scheduled stops, and estimated arrival times.
- **Upcoming arrivals:** See each train's next scheduled station alongside its scheduled and forecast arrival times.
- **Station analysis:** Compare scheduled service counts and forecast average and peak delays across stations.
- **Dispatcher simulation:** Add five delay minutes, apply up to three minutes of recovery, update a train's current station, or record a disruption.
- **Operations history:** Review the 12 most recent dispatcher events, including the train, station, time, notes, and delay change.
- **Sample data:** Start with six example services and their five-stop schedules.

## Technology

- PHP with the `mysqli` extension
- MySQL or MariaDB
- HTML and CSS (the dashboard styling is included in `index.php`)

## Requirements

- Windows with [XAMPP](https://www.apachefriends.org/) or another compatible PHP and MySQL environment.
- PHP with `mysqli` enabled.
- MySQL or MariaDB.

## Run locally with XAMPP

1. Clone the repository into XAMPP's `htdocs` directory:

   ```powershell
   cd C:\xampp\htdocs
   git clone https://github.com/1-rahul-sharma/Dynamic-Train-ETA-Forecasting-Dispatcher-Dashboard.git CA3Project
   ```

   Alternatively, download the repository as a ZIP and extract it to `C:\xampp\htdocs\CA3Project`.

2. Open the XAMPP Control Panel and start **Apache** and **MySQL**.
3. Open [phpMyAdmin](http://localhost/phpmyadmin), select **Import**, choose `setup.sql` from the project folder, and run it. The script creates the `rail_simple` database, its tables, and the sample services and stops.
4. Visit [http://localhost/CA3Project/](http://localhost/CA3Project/) in your browser.

### Database connection

By default, `index.php` connects to MySQL at `localhost` as `root` with an empty password, which matches a typical local XAMPP installation. If your local configuration differs, update the connection near the top of `index.php`.

## Forecast and status rules

For a stop at route sequence number `n`, the demonstration forecast is:

```text
forecast delay = max(0, current train delay - 3 × n) minutes
estimated arrival = scheduled arrival + forecast delay
```

The same schedule-based rule is used for the displayed stop forecasts and station summaries. The current service status is **On time** at zero delay, **Delayed** at 1–15 minutes, and **Major delay** above 15 minutes. The three-minute recovery per stop is a fixed assumption, not a learned or live prediction.

## Database overview

`setup.sql` creates the following tables in the `rail_simple` database:

| Table | Purpose |
| --- | --- |
| `trains` | Train number, service name, current station, and current delay. |
| `stops` | Ordered scheduled stops and timetable times for each train. |
| `train_events` | Dispatcher activity history, including event details, station, and delay change. |

The sample inserts use `INSERT IGNORE`, so importing the setup script again does not reset existing train delays or overwrite existing sample rows.

## Project files

| File | Description |
| --- | --- |
| `index.php` | Dashboard rendering, forecast calculations, database access, and dispatcher actions. |
| `setup.sql` | Database and table definitions plus sample train and timetable data. |
| `.gitignore` | Excludes local environment files, editor clutter, logs, and the separate report PDF. |

The separate `Train_ETA_Project_Report.pdf` is intentionally not included in this repository.

## Troubleshooting

- **Database connection error:** Make sure MySQL is running, `setup.sql` imported successfully, and the credentials in `index.php` match your local MySQL configuration.
- **The page downloads instead of opening:** Start Apache and use `http://localhost/CA3Project/` rather than opening `index.php` from the file system.
- **`mysqli` is unavailable:** Enable the `mysqli` PHP extension in your environment.
- **The dashboard has no train data:** Import `setup.sql` into the MySQL server used by the app.

## Security note

The default credentials are intended only for a local development environment. The demonstration does not include user authentication or the security controls required for an internet-facing production deployment. Do not expose it publicly with default database credentials or real operational data.
