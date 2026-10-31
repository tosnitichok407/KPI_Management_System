# Local screenshot QA test accounts

These accounts exist only in the isolated MariaDB schema `kpi_management_system_test` and use synthetic employee records.

| Username | Role |
| --- | --- |
| `test_admin` | Admin |
| `test_manager` | Manager |
| `test_employee` | Employee |

The generated passwords are kept in the local-only file `screenshots/report/test-accounts.local.txt`. That file is excluded by `.gitignore` and has owner-only read/write permissions. Do not copy it into a commit, screenshot, issue, or shared log.

## Start the application against the test schema

From the project environment, start the PHP server with `KPI_DB_NAME=kpi_management_system_test`. For the current XAMPP installation:

```sh
KPI_DB_NAME=kpi_management_system_test /Applications/XAMPP/xamppfiles/bin/php -S 127.0.0.1:8081 -t /Applications/XAMPP/xamppfiles/htdocs
```

Open `http://127.0.0.1:8081/KPI-System/login.php`. When `KPI_DB_NAME` is unset, `config/database.php` keeps its original default schema name.

## Change a test password

Log in as `test_admin` while the app is connected to the test schema. Open **จัดการบัญชีผู้ใช้**, edit the desired test account, and enter a new password in the password field. The application hashes the replacement with `password_hash()` before saving it. A blank password leaves that test account's current password unchanged.

If a password is changed, update the local-only credentials file. Never reset or edit an account while the application is connected to `kpi_management_system`.
