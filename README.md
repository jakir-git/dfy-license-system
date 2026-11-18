# DFY License System

Two WordPress plugins that manage the premium plugin licenses BuddyBoss DFY includes for one year
(Elementor Pro, Gravity Forms, LearnDash, WP Fusion, AutomatorWP Pro, WP All Import Pro).

| Plugin | Installed on | Does |
|---|---|---|
| **DFY License Hub** (`dfy-license-hub/`) | one internal site | List of DFY sites, agency keys (stored encrypted), start/end dates, activate / deactivate / delete, reference list of completed projects, daily checks and email reminders |
| **DFY License Manager** (`dfy-license-manager/`) | every DFY client site | Connects to the hub by itself, activates or takes over the licenses, removes them after the year (the plugins stay active), shows the customer a popup 30/7 days before and a notice afterwards |

See `dfy-license-hub/readme.txt` for how it works day to day.

## Setup

```bash
cp dfy-license-manager/hub-config.example.php dfy-license-manager/hub-config.php
# edit hub-config.php: set your DFY License Hub address
./build.sh            # -> dist/dfy-license-hub-<v>.zip, dist/dfy-license-manager-<v>.zip
```

`hub-config.php`, `dist/` and any data exports are in `.gitignore` and never committed.
License keys, Asana links and customer data live only in the hub's database, never in this code.

## Tests

Plain PHP, no WordPress needed (WordPress and the vendor plugins are stubbed):

```bash
php tests/license/run.php .
php tests/license/admin-actions.php .
php tests/license/admin-smoke.php .
```

`shared/` holds the classes used by both plugins; `build.sh` copies them into each plugin.
