-- Annual KPI assignments + monthly KPI performances.
-- Run AFTER 20260911_monthly_evaluation_periods.sql.
-- Back up the database before running in any environment other than local development.
--
-- No table, column or row is removed. The statements only backfill NULLs and add
-- constraints; strict mode makes any ALTER fail (instead of coercing data) if a
-- pre-check below is not satisfied.
--
-- Pre-checks (each must return no rows / 0):
--   SELECT kpi_id, employee_id, assignment_year, COUNT(*) FROM kpi_assignments
--     GROUP BY kpi_id, employee_id, assignment_year HAVING COUNT(*) > 1;
--   SELECT COUNT(*) FROM evaluation_periods
--     WHERE period_year IS NULL OR period_month IS NULL OR quarter IS NULL;
--   SELECT COUNT(*) FROM kpi_performances WHERE period_id IS NULL;

SET SESSION sql_mode = CONCAT_WS(',', NULLIF(@@SESSION.sql_mode, ''), 'STRICT_ALL_TABLES');

-- 1. Evaluation period = one calendar month (year + month + quarter required).
UPDATE evaluation_periods
SET period_year = YEAR(start_date),
    period_month = MONTH(start_date),
    quarter = CONCAT('Q', CEILING(MONTH(start_date) / 3))
WHERE start_date IS NOT NULL
  AND (period_year IS NULL OR period_month IS NULL OR quarter IS NULL);

ALTER TABLE evaluation_periods
    MODIFY period_year SMALLINT NOT NULL,
    MODIFY period_month TINYINT NOT NULL,
    MODIFY quarter VARCHAR(2) NOT NULL;

-- 2. KPI assignment = one row per KPI + employee + year, reused every month.
--    kpi_assignments.period_id is kept (legacy link used by dashboards) but is
--    no longer part of the assignment identity.
ALTER TABLE kpi_assignments
    ADD UNIQUE KEY uq_assignment_kpi_employee_year (kpi_id, employee_id, assignment_year);

-- 3. KPI performance = assignment + evaluation period (month).
--    Together with uq_performance_assignment_period (assignment_id, period_id)
--    this allows exactly one performance per assignment per month.
UPDATE kpi_performances performance
INNER JOIN evaluation_periods period
    ON performance.performance_date BETWEEN period.start_date AND period.end_date
SET performance.period_id = period.period_id
WHERE performance.period_id IS NULL;

ALTER TABLE kpi_performances
    MODIFY period_id INT(11) NOT NULL;
