# System Check Report — 2026-10-20

## Scope and result

This report records a read-only review of the existing screenshots and an attempt to reach the local application. No code, database, or existing screenshot was changed. No login or other request that could write application data was submitted.

Loopback requests to `http://127.0.0.1/KPI-System/`, `http://localhost/KPI-System/`, and the previously documented `http://127.0.0.1:8081/KPI-System/` were denied by this environment's network permissions; this does not establish whether Apache itself is running. No browser automation or browser executable was available. The current runtime database selection therefore could not be confirmed, and live application checks are **BLOCKED**.

Static configuration falls back to `kpi_management_system` when `KPI_DB_NAME` is unset. That variable was unset in this inspection process, but its value may differ in Apache. Since the application runtime and database connection could not be inspected, no mutating request was made.

## Existing screenshot review

- 38 existing PNG files were checked for PNG structure, chunk CRCs, and decompression. All 38 are valid and 1440 × 1000 pixels.
- All screenshot paths referenced by `screenshot-manifest.csv` exist.
- Coverage present: shared Login (1), Admin (20), Manager (6), and Employee (11).
- The screenshots visually show the KPI Management System interface and synthetic QA accounts. Their capture history is documented in `screenshot-report.md`; this review did not recapture or independently establish their original browser session.
- Existing images were preserved. No new screenshot was captured in this run.

Manifest summary: 39 page-state entries; 37 `SUCCESS`, 1 `BLOCKED`, and 1 `SUPERSEDED`. Capture states are 38 `SUCCESS` and 1 `NOT_APPLICABLE` for the binary XLSX download. The current Import Preview remains blocked: KPI 14 and KPI 9 fail the confirmed header rules, KPI 8 is absent from the test schema, and no import confirmation was made.

### Screenshot coverage gaps

- There is no separate screenshot for an Approval page or an Access Denied page; the existing QA report says no standalone route for either was found.
- No screenshot shows the Manager evidence-list dialog itself.
- The existing screenshots do not establish a successful Logout flow.

## Functional test status for this run

| Function | Status | Evidence / limitation |
| --- | --- | --- |
| Live Login and Logout | BLOCKED | The local application did not respond; no login form was submitted. |
| Admin, Manager, and Employee dashboards | BLOCKED | Historical role screenshots exist, but no current live page was opened. |
| Read-only details and Manager filters | BLOCKED | Historical screenshots and test results exist; not rerun in this environment. |
| Create, edit, delete, KPI save, evidence upload/delete | BLOCKED | Not attempted because the active application database could not be confirmed as `kpi_management_system_test`. |
| Evidence listing/download | BLOCKED | No live application or file request was tested. Existing Employee evidence screenshot is historical. |
| Import Preview | BLOCKED | The latest recorded preview has no READY rows because of the header/mapping/test-data issues above. No confirmation was submitted. |
| XLSX and PDF export | NOT TESTED | The previous report records successful XLSX validation and a valid three-page PDF; neither export was regenerated in this run. |
| Access-control checks | NOT TESTED | Previous reports record limited synthetic-role checks; they were not repeated here and do not establish complete authorization coverage. |

## Final status

- **PASS:** Existing screenshot files are structurally valid, 1440 × 1000, and their Manifest paths exist.
- **FAIL:** No new functional failure was observed because no live application interaction was possible.
- **BLOCKED:** Current live application checks, Import Preview, and all mutating checks that require confirming the active test database.
- **NOT TESTED:** Current Login/Logout, dashboards, filters, exports, evidence operations, KPI saves, CRUD, and access-control retests. Historical results are not treated as current-run passes.

## Needed to complete live QA

Start the local application and provide a reachable local URL plus an available browser. Before any mutating test, confirm through the application's own connection that the selected database is exactly `kpi_management_system_test`. Resolve the recorded Import Preview blockers before expecting an import to pass. Capture only missing or materially changed screens, keeping all existing images intact.
