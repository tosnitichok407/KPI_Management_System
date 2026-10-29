-- KPI 10 target and scoring criteria update (2026-10-21)
-- Target is stored per annual assignment; apply to every KPI 10 assignment.
UPDATE kpi_assignments
SET target_value = 10
WHERE kpi_id = 10;

-- Authoritative criteria source used by loadKpiScoreCriteria().
INSERT INTO kpi_score_criteria (kpi_id, score_level, criteria)
VALUES
    (10, 5, '>10%'),
    (10, 4, '5-9%'),
    (10, 3, '1-4%'),
    (10, 2, '0%'),
    (10, 1, 'ไม่มี')
ON DUPLICATE KEY UPDATE criteria = VALUES(criteria);

-- Keep legacy criteria sources in sync for older pages and exports.
UPDATE kpi_indicators
SET score_5 = '>10%',
    score_4 = '5-9%',
    score_3 = '1-4%',
    score_2 = '0%',
    score_1 = 'ไม่มี'
WHERE kpi_id = 10;

INSERT INTO kpi_score_levels (kpi_id, score, criteria)
VALUES
    (10, 5, '>10%'),
    (10, 4, '5-9%'),
    (10, 3, '1-4%'),
    (10, 2, '0%'),
    (10, 1, 'ไม่มี')
ON DUPLICATE KEY UPDATE criteria = VALUES(criteria);
