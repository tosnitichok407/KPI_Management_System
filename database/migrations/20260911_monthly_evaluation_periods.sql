-- Safe, additive migration for monthly evaluation periods.
-- Back up the database before running in any environment other than local development.

ALTER TABLE evaluation_periods
    ADD COLUMN period_year SMALLINT NULL AFTER period_name,
    ADD COLUMN period_month TINYINT NULL AFTER period_year,
    ADD COLUMN quarter VARCHAR(2) NULL AFTER period_month;

UPDATE evaluation_periods
SET period_year = YEAR(start_date),
    period_month = MONTH(start_date),
    quarter = CONCAT('Q', CEILING(MONTH(start_date) / 3))
WHERE start_date IS NOT NULL
  AND period_year IS NULL;

ALTER TABLE evaluation_periods
    ADD UNIQUE KEY uq_evaluation_period_year_month (period_year, period_month),
    ADD KEY idx_evaluation_period_year_month (period_year, period_month);

ALTER TABLE kpi_performances
    ADD COLUMN period_id INT NULL AFTER employee_id,
    ADD KEY idx_performance_period (period_id);

UPDATE kpi_performances performance
INNER JOIN evaluation_periods period
    ON performance.performance_date BETWEEN period.start_date AND period.end_date
SET performance.period_id = period.period_id
WHERE performance.period_id IS NULL;

ALTER TABLE kpi_performances
    ADD UNIQUE KEY uq_performance_assignment_period (assignment_id, period_id),
    ADD CONSTRAINT fk_performance_period
        FOREIGN KEY (period_id) REFERENCES evaluation_periods(period_id)
        ON DELETE RESTRICT;
