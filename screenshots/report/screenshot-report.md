# KPI Management System — Screenshot QA Report

**Run date:** 2026-10-20  
**Project:** `/Applications/XAMPP/xamppfiles/htdocs/KPI-System`  
**Temporary test URL:** `http://127.0.0.1:8081/KPI-System/`  
**Viewport:** 1440 × 1000 px

## Summary

| Measure | Result |
| --- | ---: |
| UI state entries in the manifest | 39 |
| Distinct route patterns | 23 |
| Successful page states, including the XLSX and PDF export endpoints | 37 |
| Valid PNG screenshots saved | 38 |
| Successful rendered application states with screenshots | 36 |
| Import validation screenshots (old and retest) | 2 |
| Current states blocked | 1 |
| Historical manifest states superseded | 1 |
| Failed states | 0 |

The original 36 PNG files were retained. Two new 1440 × 1000 PNGs were added: `screenshots/employee/import-preview-mapping-blocked.png` and `screenshots/employee/performance-pdf-page-1.png`. The import screenshot shows a corrected employee-code test that still cannot produce a preview; the PDF screenshot shows page 1 of a valid three-page export. The original import validation image remains available as `screenshots/employee/import-preview-validation.png` for comparison. All 38 files decode successfully and match their manifest paths. The exact duplicate pair `import-form.png` and `import-history.png` shows the same combined Import page: the synthetic dataset has one history row, so the recent-history panel and the history screenshot contain identical pixels. The image is valid evidence of that panel, but it is not a separate history-only page.

## Screenshot coverage review

- **Admin:** Dashboard; employee/account/category/KPI/rating-period/assignment management lists and forms; KPI summary and PDF are represented. Forms were displayed without submitting create, edit, toggle, or delete actions.
- **Manager:** Dashboard, department comparison and month detail, employee ranking/detail, and feedback dialog are represented. The detail page shows the permitted Manager view of a synthetic employee.
- **Employee:** Dashboard, saved performance/evidence, KPI detail, import form/history, feedback, profile, and exports are represented. The current Import result remains blocked. XLSX is a binary download and PDF is represented by its rendered first page.
- **Login:** Shared Login screen is represented.
- **Approval and access-denial screens:** The Admin summary and Manager detail show result/status states. No standalone approval route or dedicated Access Denied page exists in the role routes reviewed. The Employee performance/KPI detail redirects and export HTTP 403 checks are recorded in the access-control result above; those redirects/error responses did not produce separate application pages to capture. No page was fabricated for the index.
- The manifest has no `UNTESTED` outcome. It contains one current `BLOCKED` state, one historical `SUPERSEDED` capture, and one successful XLSX state with `NOT_APPLICABLE` screenshot capture. Mutating actions and successful import confirmation were deliberately not exercised.

## Test database and application connection

- Created a separate, initially absent schema named `kpi_management_system_test` on the local MariaDB instance. It is isolated from the configured `kpi_management_system` schema; no business or personal data was copied.
- Added an optional `KPI_DB_NAME` environment override in `config/database.php`. With the variable unset, the original default schema name remains unchanged. A CLI connection through the application config and the temporary web server both confirmed `DATABASE() = kpi_management_system_test` before test data was written.
- The old schema was checked with SELECT-only row counts after setup. It remains at 8 users, 27 employees, 253 assignments, and 1,866 performances, matching the earlier read-only inspection.
- The application ran on PHP 8.2.4 against local MariaDB 10.4.28. No database credentials are recorded in this report or in Git.

## Schema, migrations, and synthetic data

- Loaded the repository's `database/schema.sql` into the new schema. It already contains the monthly period fields and the annual assignment/monthly performance constraints, so the two 2026-09-11 migrations were not rerun against the current schema.
- Applied `20261019_import_history_and_evidence.sql`. It creates the two additive file-history/evidence tables with `CREATE TABLE IF NOT EXISTS` and does not alter existing tables.
- Did not run the 2026-10-16 approval/audit migration. Its runbook requires a dedicated `kpi_dba@localhost` principal and specific DDL/trigger privileges, and it stages/renames an append-only audit table. That account and permission setup was not part of this isolated screenshot run. Score-recalculation migrations were not needed for synthetic data and were not run.
- Did not run the service-department demo seed because it expects a different company department/employee population. Instead, inserted only these synthetic records in the test schema:

| Record type | Count |
| --- | ---: |
| Test users and linked employees | 3 |
| Departments / positions | 1 / 1 |
| KPI categories / indicators | 2 / 2 |
| Evaluation periods (2026) | 12 |
| KPI assignments | 6 |
| Monthly performances | 72 |
| Manager feedback entries | 1 |
| Evidence files / import-history entries | 1 / 1 |

The synthetic user roles are Admin `1`, Manager `2`, and Employee `3`. Performance rows use the schema's existing `Draft`, `Submitted`, `Approved`, and `Rejected` values. The test evidence is a generated image labelled as synthetic QA evidence; the import-history row has no stored file.

## Account login and role checks

All three accounts were submitted through the real Login form and successfully redirected to their role home page:

| Username | Role ID | Login |
| --- | ---: | --- |
| `test_admin` | 1 | Success |
| `test_manager` | 2 | Success |
| `test_employee` | 3 | Success |

The role-specific side navigation and dashboards rendered. Route checks also confirmed that a Manager is redirected away from Admin pages, an Employee is redirected away from both Admin and Manager home pages, and an Employee's direct request to a Manager route returns to Employee home.

**Access-control finding and fix:** Before the change, `employee/performance.php` rendered for all three roles if their accounts had linked employee rows. Requests with `employee_id=4`, `5`, or `6` still displayed only the signed-in account's session employee, so the test found no cross-employee IDOR. The Employee home, profile, and import routes explicitly require role `3`; Admin and Manager use their separate routes (for example, the Manager employee-detail route returned HTTP 200 for synthetic employee `QA-EMP-001`). I aligned the self-service performance page and KPI detail page with that existing role policy, and added the same role requirement to the direct PDF/XLSX export access helper. After the fix, Admin redirects to Admin home, Manager redirects to Manager home, both export endpoints return HTTP 403 for those roles, and Employee access remains HTTP 200. The approved Manager employee-detail route remains available.

## Screenshot and export limitations

- **Employee Import Preview:** The earlier `screenshots/employee/import-preview-validation.png` records the initial employee-code mismatch. For the retest, a temporary workbook copy changed only the synthetic employee code to `QA-EMP-001`; the repository workbook and database were not changed. The current mapping file has confirmed mappings for KPI IDs 8, 9, and 14, but the test schema contains only synthetic KPI IDs 3 and 4. The corrected workbook therefore produced no preview rows: KPI 14's summary-sheet headers do not match the confirmed rule; KPI 9's count-table headers do not match its confirmed rule; and mapped KPI 8 does not exist in the test schema. The reader reported 3 rejected items, 0 ready items, and 8 empty/skipped items. The latest result is captured at `screenshots/employee/import-preview-mapping-blocked.png` and remains `BLOCKED`. No KPI/assignment rows were added and no import was committed.
- **Employee XLSX export:** Returned HTTP 200 with the expected XLSX content type (11,154 bytes). The downloaded file passed ZIP integrity validation. It is recorded as a successful export in the manifest but has no page screenshot.
- **Employee PDF export:** The initial zero-page report came from the system `file` utility, which misclassified this PDF. The real export returned HTTP 200 and `application/pdf` (131,343 bytes). The PDF page tree has `/Count 3`, contains three page objects, and macOS PDFKit opened it as three pages; page 1 displays the synthetic employee, KPI, actual value 95, grade 4, and score 240. Those values match the test DB row (`weight=60`, `target=100`, `actual=95`, `score=4`, `Draft`); the second synthetic competency row is also included in the document. The new 1440 × 1000 rendered page is `screenshots/employee/performance-pdf-page-1.png`. No PDF code change was needed.
- **Admin summary PDF:** Rendered in Chrome's PDF viewer and has a valid screenshot at `screenshots/admin/summary-pdf.png`.
- No CRUD form was submitted, no feedback was sent, no evidence was uploaded/deleted through the UI, and no import was committed during screenshot capture. The real Login checks wrote only their normal test-account login-log entries to `kpi_management_system_test`; this run did not connect to or write to `kpi_management_system`.

## Test account handling

Instructions are in `screenshots/report/test-account-instructions.md`. Generated passwords are in the local-only file `screenshots/report/test-accounts.local.txt`, protected by owner-only file permissions and explicitly excluded by `.gitignore`. The passwords are not copied into this report or any screenshot. Use the optional `KPI_DB_NAME=kpi_management_system_test` setting before logging in or changing a test password. The Admin account-edit form hashes a replacement password with `password_hash()` and leaves the current password unchanged when the field is blank.

## Files

- Manifest: `screenshots/report/screenshot-manifest.csv`
- Test account instructions: `screenshots/report/test-account-instructions.md`
- Local-only test passwords: `screenshots/report/test-accounts.local.txt` (ignored by Git)
- Shared Login screenshot: `screenshots/shared/login.png`
- Admin screenshots: `screenshots/admin/`
- Manager screenshots: `screenshots/manager/`
- Employee screenshots: `screenshots/employee/`
