-- KPI approval workflow columns + append-only audit log (schema only).
--
-- Target: MariaDB 10.4.28 (BEGIN NOT ATOMIC, ADD COLUMN/INDEX/CONSTRAINT IF NOT EXISTS,
--         CREATE TRIGGER IF NOT EXISTS, enforced CHECK constraints, REGEXP_REPLACE).
-- Rollback: 20261016_kpi_approval_audit_rollback.sql
--
-- ===========================================================================
-- REQUIRED ACCOUNT AND PRIVILEGES
-- ===========================================================================
-- Run this file as `kpi_dba`@`localhost`. That account is NOT created here; the
-- database administrator must create it first. Any other account is refused
-- before any DDL (phase A and phase C both check CURRENT_USER()).
--
-- (a) Privileges to EXECUTE this migration, on the application database:
--     SELECT, INSERT, CREATE, ALTER, INDEX, REFERENCES, DROP, TRIGGER.
--       - ALTER TABLE kpi_performances copies the table: ALTER + CREATE + INSERT.
--       - CREATE TABLE audit_logs_migration_stage: CREATE.
--       - RENAME TABLE (stage <-> audit_logs): ALTER + DROP on the old name,
--         CREATE + INSERT on the new name.
--       - ADD INDEX / FOREIGN KEY: INDEX, REFERENCES.
--       - CREATE TRIGGER with DEFINER = itself: TRIGGER (no SUPER needed).
--       - The verification reads information_schema; rows are only visible for
--         objects the account has privileges on, so the privileges above must be
--         on the whole database, not on single columns.
--     Tested (isolated MariaDB 10.4.28, synthetic data): kpi_dba holding these
--     privileges PLUS SHOW VIEW on the database ran this file successfully.
--     NOT VERIFIED: the exact list above without SHOW VIEW.
--
-- (b) Privileges the trigger DEFINER (kpi_dba@localhost) needs at RUNTIME, every
--     time anyone inserts into audit_logs. MariaDB checks them against the
--     DEFINER, not against the account that runs the INSERT:
--       - TRIGGER on audit_logs (required for any trigger to run; the database-level
--         TRIGGER from (a) covers it);
--       - UPDATE on column audit_logs.occurred_at: the BEFORE INSERT trigger
--         trg_audit_logs_set_time executes SET NEW.occurred_at = NOW(6), so that the
--         time of every audit row is set by the database, never by the caller.
--         The privileges in (a) do NOT include UPDATE. Grant it as a separate step
--         AFTER this migration has created audit_logs (a column grant needs the
--         table) and BEFORE application writes are reopened: runbook step 9.
--       - the UPDATE / DELETE guard triggers only SIGNAL and need nothing more.
--     Without UPDATE (occurred_at) EVERY audit INSERT fails (error 1143).
--     WARNING: while the triggers exist, do not drop, rename or change the
--     privileges of kpi_dba@localhost without first assessing the effect on the
--     triggers. Every audit INSERT fails when the DEFINER no longer exists
--     (error 1449), lacks TRIGGER (error 1142) or lacks UPDATE (occurred_at)
--     (error 1143). Keep the account and these privileges for as long as the
--     triggers exist. Test evidence: see TEST STATUS below.
--
-- (c) Privileges for the ROLLBACK file, as the rollback itself checks them (refusal
--     1 in its header): SELECT, TRIGGER and SHOW VIEW globally or on this database,
--     granted directly to the account that runs it (grants through a role, on a
--     wildcard database pattern or on single tables are not accepted; the account
--     name is compared case-sensitively). Without them it refuses before any DROP.
--     In addition: DROP (audit_logs and the stage table) and ALTER + CREATE +
--     INSERT + INDEX on kpi_performances (dropping columns, indexes and
--     constraints copies the table).
--
-- This file does not create accounts and does not GRANT or REVOKE anything.
--
-- ===========================================================================
-- TEST STATUS (isolated MariaDB 10.4.28 instances, synthetic data only)
-- ===========================================================================
-- Nothing below was run on production. The results apply only to MariaDB
-- 10.4.28 with synthetic data loaded from database/schema.sql.
-- PASS:
--   - this file run as kpi_dba: success line, the 3 triggers have DEFINER
--     kpi_dba@localhost, existing data unchanged; a test account other than
--     kpi_dba is refused, also with --force, and nothing changes;
--   - DEFINER runtime: TRIGGER + UPDATE (occurred_at) are sufficient for an audit
--     INSERT, and occurred_at is set by the database; without UPDATE (occurred_at)
--     the INSERT fails (1143), without TRIGGER it fails (1142), after DROP USER
--     kpi_dba it fails (1449) and no audit row is added; UPDATE and DELETE are
--     refused by the triggers (1644);
--   - rollback: refused for accounts without directly granted SELECT, TRIGGER and
--     SHOW VIEW (a privilege missing, TRIGGER on a single table only, privileges
--     through a role, an account name differing only in letter case), also with
--     --force and with the confirmation, and nothing changes; with the privileges
--     and the confirmation it restores the schema exactly and this file applies
--     again.
-- Separate note (outside the scope above): root was refused, also with --force,
-- in an earlier test on a separate schema of the XAMPP server, not on an
-- isolated instance; whether that schema held only synthetic data is not
-- confirmed.
-- NOT VERIFIED:
--   - anything on production: schema equal to schema.sql, data volume, ALTER
--     duration, accounts and privileges;
--   - the exact privilege list (a) without SHOW VIEW;
--   - events metadata (unreadable while the event scheduler is DISABLED) and the
--     completeness of the visible stored routines: a stored routine the rollback
--     account cannot see is not found, and only the operator's manual review
--     protects it;
--   - a DEFINER that is renamed, locked or has an expired password;
--   - backup coverage of stored routines and events (runbook step 3).
-- NOT RUN:
--   - any check against production;
--   - tests of events and of dependencies in other databases;
--   - a rollback interrupted in the middle of its DDL;
--   - the backup and restore of runbook steps 3-4.
--
-- ===========================================================================
-- OPERATIONAL RUNBOOK (production)
-- ===========================================================================
--  1. Use an approved maintenance window.
--  2. Stop application writes (maintenance page / web server stopped) before the
--     migration or the rollback, and keep them stopped until step 13 allows them.
--  3. Create a full backup (mysqldump --single-transaction --triggers) and check
--     that it ends with "Dump completed".
--     WARNING - backup coverage NOT VERIFIED: this command has not been shown to
--     include stored routines or events (it has no --routines or --events). Adding
--     --events failed with error 1577 on MariaDB 10.4.28 while the event
--     scheduler was DISABLED (isolated test). Do not assume that the backup covers
--     stored routines or events. The backup and restore procedure must be
--     reviewed, tested and approved before it is used on production. Do not change
--     this command without tested evidence.
--  4. Restore that backup into a scratch database and compare row counts and
--     checksums with the source before continuing.
--  5. Confirm the target: SELECT DATABASE(), @@hostname, CURRENT_USER(); the
--     database must be the application database and the user kpi_dba@localhost.
--  6. Run this file with the mysql client WITHOUT --force:
--       mysql -u kpi_dba -p <database> < database/migrations/20261016_kpi_approval_audit.sql
--     Success is ONLY the final line "OK: KPI approval audit migration verified".
--     Any ERROR means the migration is NOT complete.
--  7. Verify: SHOW CREATE TABLE kpi_performances, SHOW CREATE TABLE audit_logs,
--     SHOW CREATE TRIGGER for the 3 triggers (DEFINER must be kpi_dba@localhost),
--     information_schema CHECK_CONSTRAINTS / REFERENTIAL_CONSTRAINTS / STATISTICS,
--     and row counts + checksums of every original table and column compared with
--     the values recorded before step 6.
--  8. Confirm audit_logs is empty, audit_logs_migration_stage does not exist, the
--     new kpi_performances columns are 0 / NULL on every row, and the legacy
--     Approved rows (status, score, target, actual, created_at, updated_at) are
--     unchanged.
--  9. Grant the DEFINER's runtime privilege (see (b)), only after step 8 passed:
--       GRANT UPDATE (occurred_at) ON <database>.audit_logs TO 'kpi_dba'@'localhost';
--     SHOW GRANTS FOR 'kpi_dba'@'localhost' must then list UPDATE (`occurred_at`)
--     on audit_logs and TRIGGER on the database (from (a)).
-- 10. Audit smoke test, through the application's real audit path or, until the
--     application writes to audit_logs, with one INSERT into audit_logs by the
--     account the application uses for audit writes, supplying a deliberately
--     wrong occurred_at (for example '2020-01-01 00:00:00'). Record COUNT(*) and
--     MAX(audit_id) before it, then check:
--       - the INSERT succeeds (any error, for example 1143, 1142 or 1449, is a
--         failure);
--       - occurred_at of the new row is the database time at the insert, not the
--         supplied value;
--       - COUNT(*) and MAX(audit_id) increased by exactly one row.
--     The account that writes to audit_logs needs INSERT on audit_logs (in the
--     isolated tests it held SELECT and INSERT on audit_logs); this file does not
--     grant it. NOT VERIFIED: there is no evidence yet that the application
--     writes to audit_logs, or through which account it would do so.
--     The smoke-test row is permanent (UPDATE and DELETE are blocked), and from
--     this step on the rollback refuses because audit data exists: decide on any
--     rollback BEFORE this step.
-- 11. Run role-based smoke tests (Employee, Manager, Admin).
-- 12. If step 9, 10 or 11 fails: do NOT reopen application writes; go to step 14.
-- 13. Reopen application writes only after steps 7 to 11 have all passed.
-- 14. If any check fails: keep writes stopped. Either fix the reported cause and
--     run this file again (it resumes safely), or run the rollback file while no
--     workflow / audit data exists (that is, before step 10), or restore the
--     backup from step 3.
-- 15. Never run a destructive rollback after audit or workflow data has been
--     created; the rollback refuses, and the data must be exported and a decision
--     made explicitly instead.
-- 16. DDL is not atomic: each DDL statement commits on its own. A failed run can
--     leave a partial schema (resumable by rerunning), and the rollback's guard and
--     DROPs are separate steps, so writes must stay stopped during either file.
-- 17. While the triggers exist, do not drop, rename or change the privileges of
--     kpi_dba@localhost without first assessing the effect on the triggers
--     (see (b)): every audit INSERT would fail.
--
-- ===========================================================================
-- STRUCTURE AND SAFETY
-- ===========================================================================
-- Phase A (one compound statement, sections A0-A4):
--   A0 account check, A1 base tables exist, A2 verification of every object that
--   already exists (nothing is trusted by name: definitions are compared), A3 DDL on
--   kpi_performances, A4 the audit table is placed under the staging name
--   audit_logs_migration_stage (created there, or an existing audit_logs is renamed
--   there so that missing triggers can be added). No DDL runs if A0-A2 fail.
-- Phase B (top level): the 3 triggers are created ON audit_logs_migration_stage.
--   MariaDB does not allow CREATE TRIGGER inside a compound statement or a prepared
--   statement, so this phase cannot be guarded directly. It can only attach to the
--   staging table, which exists only after an authorised phase A. The live table
--   name audit_logs never receives triggers in this phase.
-- Phase C (one compound statement): account check again, strict verification of
--   the complete design (all columns, types, defaults, indexes, foreign keys, CHECK
--   expressions, engine, collation, and the 3 triggers' event, timing, orientation,
--   DEFINER and body; no unexpected triggers), then RENAME stage -> audit_logs and
--   print the success line. On any failure it stops with a diagnostic and the
--   table stays under the staging name, so the application cannot use a
--   half-built audit log.
--
-- With --force the client continues after an error, but: if phase A refuses, no
-- staging table is created and every phase B statement fails; phase C refuses
-- again, so there is no success line and no rename.
-- Residual case (documented, not preventable by script structure, tested): when
-- the staging table audit_logs_migration_stage ALREADY exists (left by an earlier
-- failed run) and phase A refuses for any reason (wrong account, conflicting
-- objects), a run with --force still lets phase B add the expected triggers to that
-- STAGING table, because CREATE TRIGGER cannot be made conditional. An account that
-- may set any DEFINER (e.g. root) can do this even with a DEFINER that does not
-- exist. Effects are limited to the staging table: audit_logs is never touched,
-- phase C refuses, nothing is promoted and no success line is printed. The next
-- authorised run verifies those triggers exactly (DEFINER and body) and refuses if
-- they differ. Operator procedure: never use --force; if a staging table exists,
-- inspect it (SHOW CREATE TABLE / SHOW TRIGGERS) before rerunning.
--
-- Verification limits:
--   - CHECK expressions are compared with MariaDB's normalised text in
--     information_schema.CHECK_CONSTRAINTS as produced by 10.4.28. Another version
--     may normalise differently; the result is a false mismatch (the migration
--     stops), never a false pass.
--   - Trigger bodies are compared after collapsing whitespace; any other textual
--     difference counts as a mismatch even if semantically equivalent.
--   - All comparisons are BINARY (information_schema text is case-insensitive by
--     default, which would treat [a-z] and [A-Z] as equal).
--   - Column order in kpi_performances is not compared.
--
-- Data rules:
--   - No existing KPI row is updated. There is no UPDATE / INSERT / DELETE of KPI
--     data in this file.
--   - New kpi_performances columns are NULL for every existing row (no reliable
--     evidence of who submitted / approved existing data, so nothing is backfilled);
--     `version` starts at 0, a technical counter that says nothing about approvals.
--   - Existing Approved rows keep status Approved with reviewed_by / reviewed_at NULL.
--   - audit_logs starts empty; no history is fabricated.

SET SESSION sql_mode = CONCAT_WS(',', NULLIF(@@SESSION.sql_mode, ''), 'STRICT_ALL_TABLES');
SET SESSION group_concat_max_len = 1000000;

-- ---------------------------------------------------------------------------
-- Expected design (exact text as reported by MariaDB 10.4.28 information_schema)
-- ---------------------------------------------------------------------------
SET @kpi_audit_account = 'kpi_dba@localhost';

-- Objects on kpi_performances and the triggers: one exact fingerprint per object
-- name (looked up by name, compared as a whole string, never as a substring).
SET @kpi_audit_wf_cols = JSON_OBJECT(
    'version',      'version|int(10) unsigned|NO|0||~',
    'submitted_at', 'submitted_at|datetime|YES|NULL||~',
    'submitted_by', 'submitted_by|int(11)|YES|NULL||~',
    'reviewed_at',  'reviewed_at|datetime|YES|NULL||~',
    'reviewed_by',  'reviewed_by|int(11)|YES|NULL||~',
    'review_note',  'review_note|varchar(1000)|YES|NULL||utf8mb4_general_ci'
);
SET @kpi_audit_wf_idx = JSON_OBJECT(
    'idx_performance_period_status', 'idx_performance_period_status|1|period_id,status|BTREE',
    'idx_performance_submitted_by',  'idx_performance_submitted_by|1|submitted_by|BTREE',
    'idx_performance_reviewed_by',   'idx_performance_reviewed_by|1|reviewed_by|BTREE'
);
SET @kpi_audit_wf_fk = JSON_OBJECT(
    'fk_performance_submitted_by', 'fk_performance_submitted_by|kpi_performances|submitted_by|users|user_id|CASCADE|RESTRICT',
    'fk_performance_reviewed_by',  'fk_performance_reviewed_by|kpi_performances|reviewed_by|users|user_id|CASCADE|RESTRICT'
);
SET @kpi_audit_wf_chk = JSON_OBJECT(
    'chk_performance_submitted_pair', 'chk_performance_submitted_pair|`submitted_at` is null = (`submitted_by` is null)',
    'chk_performance_reviewed_pair',  'chk_performance_reviewed_pair|`reviewed_at` is null = (`reviewed_by` is null)'
);

SET @kpi_audit_expect_cols = 'audit_id|bigint(20) unsigned|NO|~|auto_increment|~;occurred_at|datetime(6)|NO|current_timestamp(6)||~;actor_user_id|int(11)|YES|NULL||~;actor_employee_id|int(11)|YES|NULL||~;actor_username|varchar(50)|YES|NULL||utf8mb4_general_ci;actor_role|varchar(50)|YES|NULL||utf8mb4_general_ci;action|varchar(30)|NO|~||utf8mb4_general_ci;entity_type|varchar(50)|NO|~||utf8mb4_general_ci;entity_id|bigint(20) unsigned|YES|NULL||~;employee_id|int(11)|YES|NULL||~;period_id|int(11)|YES|NULL||~;before_data|longtext|YES|NULL||utf8mb4_general_ci;after_data|longtext|YES|NULL||utf8mb4_general_ci;reason|varchar(1000)|YES|NULL||utf8mb4_general_ci;source|varchar(10)|NO|''WEB''||utf8mb4_general_ci;request_id|char(36)|YES|NULL||utf8mb4_general_ci;import_batch_id|bigint(20) unsigned|YES|NULL||~;ip_address|varchar(45)|YES|NULL||utf8mb4_general_ci;user_agent|varchar(255)|YES|NULL||utf8mb4_general_ci';
SET @kpi_audit_expect_idx = 'PRIMARY|0|audit_id|BTREE;idx_audit_action|1|action,occurred_at|BTREE;idx_audit_actor|1|actor_user_id,occurred_at|BTREE;idx_audit_employee_period|1|employee_id,period_id,occurred_at|BTREE;idx_audit_entity|1|entity_type,entity_id,occurred_at|BTREE;idx_audit_import_batch|1|import_batch_id|BTREE;idx_audit_occurred|1|occurred_at|BTREE;idx_audit_request|1|request_id|BTREE';
SET @kpi_audit_expect_chk = 'chk_audit_action|cast(`action` as char charset binary) regexp ''^[A-Z][A-Z_]{1,29}$'';;chk_audit_after_json|`after_data` is null or json_valid(`after_data`) and char_length(`after_data`) <= 65535;;chk_audit_before_json|`before_data` is null or json_valid(`before_data`) and char_length(`before_data`) <= 65535;;chk_audit_entity_type|cast(`entity_type` as char charset binary) regexp ''^[a-z][a-z_]{1,49}$'';;chk_audit_request_id|`request_id` is null or `request_id` regexp ''^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'';;chk_audit_source|cast(`source` as char charset binary) in (''WEB'',''IMPORT'',''CLI'',''SYSTEM'')';
SET @kpi_audit_expect_tbl = 'InnoDB|utf8mb4_general_ci';
SET @kpi_audit_trg = JSON_OBJECT(
    'trg_audit_logs_set_time',  CONCAT('trg_audit_logs_set_time|INSERT|BEFORE|ROW|', @kpi_audit_account,
                                       '|BEGIN SET NEW.occurred_at = NOW(6); END'),
    'trg_audit_logs_no_update', CONCAT('trg_audit_logs_no_update|UPDATE|BEFORE|ROW|', @kpi_audit_account,
                                       '|BEGIN SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''audit_logs is append-only: UPDATE is not allowed''; END'),
    'trg_audit_logs_no_delete', CONCAT('trg_audit_logs_no_delete|DELETE|BEFORE|ROW|', @kpi_audit_account,
                                       '|BEGIN SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''audit_logs is append-only: DELETE is not allowed''; END')
);
-- The audit table itself is compared as a whole (all columns in order, all indexes,
-- all CHECKs, engine and collation), so any extra or missing item is a mismatch.

DELIMITER //

-- ===========================================================================
-- PHASE A: guard, verification of existing objects, DDL, staging table
-- ===========================================================================
BEGIN NOT ATOMIC

    DECLARE diag TEXT DEFAULT '';
    DECLARE live_exists INT DEFAULT 0;
    DECLARE stage_exists INT DEFAULT 0;
    DECLARE audit_table VARCHAR(64) DEFAULT NULL;
    DECLARE found_text MEDIUMTEXT;

    -- A0. Account check, before anything else.
    IF CURRENT_USER() <> @kpi_audit_account THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Migration stopped (phase A): run it as kpi_dba@localhost (create that account first). Nothing was changed.';
    END IF;

    -- A1. Base tables must exist in the selected database.
    IF DATABASE() IS NULL
       OR (SELECT COUNT(*) FROM information_schema.TABLES
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('kpi_performances', 'users')) <> 2 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Migration stopped (phase A): no database selected, or kpi_performances / users not found. Nothing was changed.';
    END IF;

    -- A2. Verify every object of this design that ALREADY exists (a matching name
    --     is not trusted; the definition is compared). Nothing is changed here.

    -- A2.1 workflow columns that exist must match exactly
    SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY COLUMN_NAME) INTO found_text
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kpi_performances'
      AND COLUMN_NAME IN ('version', 'submitted_at', 'submitted_by', 'reviewed_at', 'reviewed_by', 'review_note')
      AND NOT (BINARY CONCAT_WS('|', COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, IFNULL(COLUMN_DEFAULT, '~'), EXTRA, IFNULL(COLLATION_NAME, '~')) <=> BINARY JSON_UNQUOTE(JSON_EXTRACT(@kpi_audit_wf_cols, CONCAT('$.', COLUMN_NAME))));
    IF found_text IS NOT NULL THEN
        SET diag = CONCAT(diag, 'column definition differs: kpi_performances.', found_text, '; ');
    END IF;

    -- A2.2 indexes with the expected names that exist must match exactly
    SELECT GROUP_CONCAT(i.idx ORDER BY i.idx) INTO found_text
    FROM (
        SELECT INDEX_NAME AS idx,
               CONCAT_WS('|', INDEX_NAME, MAX(NON_UNIQUE),
                         GROUP_CONCAT(CONCAT(COLUMN_NAME, IFNULL(CONCAT('(', SUB_PART, ')'), '')) ORDER BY SEQ_IN_INDEX),
                         MAX(INDEX_TYPE)) AS fp
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kpi_performances'
          AND INDEX_NAME IN ('idx_performance_period_status', 'idx_performance_submitted_by', 'idx_performance_reviewed_by')
        GROUP BY INDEX_NAME
    ) i
    WHERE NOT (BINARY i.fp <=> BINARY JSON_UNQUOTE(JSON_EXTRACT(@kpi_audit_wf_idx, CONCAT('$.', i.idx))));
    IF found_text IS NOT NULL THEN
        SET diag = CONCAT(diag, 'index definition differs: ', found_text, '; ');
    END IF;

    -- A2.3 foreign keys: expected names must match exactly; no other FK may use the
    --      workflow columns
    SELECT GROUP_CONCAT(f.name ORDER BY f.name) INTO found_text
    FROM (
        SELECT r.CONSTRAINT_NAME AS name,
               CONCAT_WS('|', r.CONSTRAINT_NAME, r.TABLE_NAME,
                         GROUP_CONCAT(k.COLUMN_NAME ORDER BY k.ORDINAL_POSITION),
                         r.REFERENCED_TABLE_NAME,
                         GROUP_CONCAT(k.REFERENCED_COLUMN_NAME ORDER BY k.ORDINAL_POSITION),
                         r.UPDATE_RULE, r.DELETE_RULE) AS fp
        FROM information_schema.REFERENTIAL_CONSTRAINTS r
        INNER JOIN information_schema.KEY_COLUMN_USAGE k
            ON k.CONSTRAINT_SCHEMA = r.CONSTRAINT_SCHEMA
           AND k.CONSTRAINT_NAME = r.CONSTRAINT_NAME
           AND k.TABLE_NAME = r.TABLE_NAME
        WHERE r.CONSTRAINT_SCHEMA = DATABASE()
          AND (r.CONSTRAINT_NAME IN ('fk_performance_submitted_by', 'fk_performance_reviewed_by')
               OR (r.TABLE_NAME = 'kpi_performances'
                   AND k.COLUMN_NAME IN ('version', 'submitted_at', 'submitted_by', 'reviewed_at', 'reviewed_by', 'review_note')))
        GROUP BY r.CONSTRAINT_NAME, r.TABLE_NAME, r.REFERENCED_TABLE_NAME, r.UPDATE_RULE, r.DELETE_RULE
    ) f
    WHERE NOT (BINARY f.fp <=> BINARY JSON_UNQUOTE(JSON_EXTRACT(@kpi_audit_wf_fk, CONCAT('$.', f.name))));
    IF found_text IS NOT NULL THEN
        SET diag = CONCAT(diag, 'foreign key differs or unexpected: ', found_text, '; ');
    END IF;

    -- A2.4 CHECK constraints with the expected names must match exactly
    SELECT GROUP_CONCAT(CONSTRAINT_NAME ORDER BY CONSTRAINT_NAME) INTO found_text
    FROM information_schema.CHECK_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'kpi_performances'
      AND CONSTRAINT_NAME IN ('chk_performance_submitted_pair', 'chk_performance_reviewed_pair')
      AND NOT (BINARY CONCAT_WS('|', CONSTRAINT_NAME, CHECK_CLAUSE) <=> BINARY JSON_UNQUOTE(JSON_EXTRACT(@kpi_audit_wf_chk, CONCAT('$.', CONSTRAINT_NAME))));
    IF found_text IS NOT NULL THEN
        SET diag = CONCAT(diag, 'CHECK constraint differs: ', found_text, '; ');
    END IF;

    -- A2.5 the audit table (live name or staging name), if it exists, must match the
    --      complete design; both names at once is an ambiguous state
    SELECT COUNT(*) INTO live_exists FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_logs';
    SELECT COUNT(*) INTO stage_exists FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_logs_migration_stage';

    IF live_exists = 1 AND stage_exists = 1 THEN
        SET diag = CONCAT(diag, 'both audit_logs and audit_logs_migration_stage exist; ');
    ELSEIF live_exists = 1 THEN
        SET audit_table = 'audit_logs';
    ELSEIF stage_exists = 1 THEN
        SET audit_table = 'audit_logs_migration_stage';
    END IF;

    IF audit_table IS NOT NULL THEN

        IF (SELECT BINARY GROUP_CONCAT(CONCAT_WS('|', COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, IFNULL(COLUMN_DEFAULT, '~'), EXTRA, IFNULL(COLLATION_NAME, '~')) ORDER BY ORDINAL_POSITION SEPARATOR ';')
            FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = audit_table)
           <=> BINARY @kpi_audit_expect_cols = 0 THEN
            SET diag = CONCAT(diag, audit_table, ' columns differ from the design; ');
        END IF;

        IF (SELECT BINARY GROUP_CONCAT(x.fp ORDER BY BINARY x.idx SEPARATOR ';') FROM (
                SELECT INDEX_NAME AS idx,
                       CONCAT_WS('|', INDEX_NAME, MAX(NON_UNIQUE),
                                 GROUP_CONCAT(CONCAT(COLUMN_NAME, IFNULL(CONCAT('(', SUB_PART, ')'), '')) ORDER BY SEQ_IN_INDEX),
                                 MAX(INDEX_TYPE)) AS fp
                FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = audit_table
                GROUP BY INDEX_NAME) x)
           <=> BINARY @kpi_audit_expect_idx = 0 THEN
            SET diag = CONCAT(diag, audit_table, ' indexes differ from the design; ');
        END IF;

        IF (SELECT BINARY GROUP_CONCAT(CONCAT_WS('|', CONSTRAINT_NAME, CHECK_CLAUSE) ORDER BY CONSTRAINT_NAME SEPARATOR ';;')
            FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = audit_table)
           <=> BINARY @kpi_audit_expect_chk = 0 THEN
            SET diag = CONCAT(diag, audit_table, ' CHECK constraints differ from the design; ');
        END IF;

        IF (SELECT BINARY CONCAT_WS('|', ENGINE, TABLE_COLLATION) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = audit_table)
           <=> BINARY @kpi_audit_expect_tbl = 0 THEN
            SET diag = CONCAT(diag, audit_table, ' engine or collation differs; ');
        END IF;

        IF (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND (TABLE_NAME = audit_table OR REFERENCED_TABLE_NAME = audit_table)) > 0 THEN
            SET diag = CONCAT(diag, audit_table, ' has unexpected foreign keys; ');
        END IF;
    END IF;

    -- A2.6 triggers: any trigger with one of the 3 names, or on the audit table
    --      (either name), must be one of the 3 expected definitions, on the audit
    --      table; nothing else is allowed
    SELECT GROUP_CONCAT(TRIGGER_NAME ORDER BY TRIGGER_NAME) INTO found_text
    FROM information_schema.TRIGGERS
    WHERE TRIGGER_SCHEMA = DATABASE()
      AND (TRIGGER_NAME IN ('trg_audit_logs_set_time', 'trg_audit_logs_no_update', 'trg_audit_logs_no_delete')
           OR EVENT_OBJECT_TABLE IN ('audit_logs', 'audit_logs_migration_stage'))
      AND (EVENT_OBJECT_TABLE NOT IN ('audit_logs', 'audit_logs_migration_stage')
           OR NOT (BINARY CONCAT_WS('|', TRIGGER_NAME, EVENT_MANIPULATION, ACTION_TIMING, ACTION_ORIENTATION, DEFINER, TRIM(REGEXP_REPLACE(ACTION_STATEMENT, '[[:space:]]+', ' ')))
                   <=> BINARY JSON_UNQUOTE(JSON_EXTRACT(@kpi_audit_trg, CONCAT('$.', TRIGGER_NAME)))));
    IF found_text IS NOT NULL THEN
        SET diag = CONCAT(diag, 'trigger differs, is unexpected or is on another table: ', found_text, '; ');
    END IF;

    IF diag <> '' THEN
        SET @kpi_audit_message = LEFT(CONCAT('Migration stopped (phase A): existing objects conflict with the design: ', diag, 'Nothing was changed.'), 500);
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = @kpi_audit_message;
    END IF;

    -- A3. Workflow columns, indexes, foreign keys and CHECKs on kpi_performances.
    --     Existing ones were verified in A2, so IF NOT EXISTS only skips correct ones.
    ALTER TABLE kpi_performances
        ADD COLUMN IF NOT EXISTS version INT UNSIGNED NOT NULL DEFAULT 0 AFTER status,
        ADD COLUMN IF NOT EXISTS submitted_at DATETIME NULL DEFAULT NULL AFTER version,
        ADD COLUMN IF NOT EXISTS submitted_by INT(11) NULL DEFAULT NULL AFTER submitted_at,
        ADD COLUMN IF NOT EXISTS reviewed_at DATETIME NULL DEFAULT NULL AFTER submitted_by,
        ADD COLUMN IF NOT EXISTS reviewed_by INT(11) NULL DEFAULT NULL AFTER reviewed_at,
        ADD COLUMN IF NOT EXISTS review_note VARCHAR(1000) NULL DEFAULT NULL AFTER reviewed_by;

    ALTER TABLE kpi_performances
        ADD INDEX IF NOT EXISTS idx_performance_period_status (period_id, status),
        ADD INDEX IF NOT EXISTS idx_performance_submitted_by (submitted_by),
        ADD INDEX IF NOT EXISTS idx_performance_reviewed_by (reviewed_by);

    --   *_by reference users.user_id with ON DELETE RESTRICT so a reviewer's identity
    --   cannot silently disappear (SET NULL would make a real approval look like
    --   initial data). audit_logs keeps username/role snapshots in addition.
    ALTER TABLE kpi_performances
        ADD CONSTRAINT fk_performance_submitted_by FOREIGN KEY IF NOT EXISTS (submitted_by)
            REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE CASCADE,
        ADD CONSTRAINT fk_performance_reviewed_by FOREIGN KEY IF NOT EXISTS (reviewed_by)
            REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE CASCADE;

    -- Who and when are recorded together (both NULL for existing rows).
    ALTER TABLE kpi_performances
        ADD CONSTRAINT IF NOT EXISTS chk_performance_submitted_pair
            CHECK ((submitted_at IS NULL) = (submitted_by IS NULL)),
        ADD CONSTRAINT IF NOT EXISTS chk_performance_reviewed_pair
            CHECK ((reviewed_at IS NULL) = (reviewed_by IS NULL));

    -- A4. The audit table goes under the staging name for phase B:
    --     - existing audit_logs (verified in A2) is renamed to the staging name so
    --       that missing triggers can be added; existing triggers move with it;
    --     - an existing staging table (earlier partial run) is kept;
    --     - otherwise the table is created under the staging name.
    --   Columns: actor_* are snapshots without FK (the log survives user changes);
    --   before_data / after_data hold whitelisted JSON fields only (never passwords,
    --   session ids, CSRF tokens or whole files), max 65,535 chars; action /
    --   entity_type / source checks are BINARY (case-sensitive); occurred_at is set
    --   by trg_audit_logs_set_time; import_batch_id is reserved for Excel import.
    IF live_exists = 1 THEN
        RENAME TABLE audit_logs TO audit_logs_migration_stage;
    ELSEIF stage_exists = 0 THEN
        CREATE TABLE audit_logs_migration_stage (
            audit_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            occurred_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),

            actor_user_id INT(11) NULL DEFAULT NULL,
            actor_employee_id INT(11) NULL DEFAULT NULL,
            actor_username VARCHAR(50) NULL DEFAULT NULL,
            actor_role VARCHAR(50) NULL DEFAULT NULL,

            action VARCHAR(30) NOT NULL,
            entity_type VARCHAR(50) NOT NULL,
            entity_id BIGINT UNSIGNED NULL DEFAULT NULL,
            employee_id INT(11) NULL DEFAULT NULL,
            period_id INT(11) NULL DEFAULT NULL,

            before_data LONGTEXT NULL DEFAULT NULL,
            after_data LONGTEXT NULL DEFAULT NULL,
            reason VARCHAR(1000) NULL DEFAULT NULL,

            source VARCHAR(10) NOT NULL DEFAULT 'WEB',
            request_id CHAR(36) NULL DEFAULT NULL,
            import_batch_id BIGINT UNSIGNED NULL DEFAULT NULL,
            ip_address VARCHAR(45) NULL DEFAULT NULL,
            user_agent VARCHAR(255) NULL DEFAULT NULL,

            PRIMARY KEY (audit_id),
            KEY idx_audit_entity (entity_type, entity_id, occurred_at),
            KEY idx_audit_employee_period (employee_id, period_id, occurred_at),
            KEY idx_audit_actor (actor_user_id, occurred_at),
            KEY idx_audit_action (action, occurred_at),
            KEY idx_audit_occurred (occurred_at),
            KEY idx_audit_request (request_id),
            KEY idx_audit_import_batch (import_batch_id),

            CONSTRAINT chk_audit_action CHECK (BINARY action REGEXP '^[A-Z][A-Z_]{1,29}$'),
            CONSTRAINT chk_audit_entity_type CHECK (BINARY entity_type REGEXP '^[a-z][a-z_]{1,49}$'),
            CONSTRAINT chk_audit_source CHECK (BINARY source IN ('WEB', 'IMPORT', 'CLI', 'SYSTEM')),
            CONSTRAINT chk_audit_before_json CHECK (
                before_data IS NULL OR (JSON_VALID(before_data) AND CHAR_LENGTH(before_data) <= 65535)
            ),
            CONSTRAINT chk_audit_after_json CHECK (
                after_data IS NULL OR (JSON_VALID(after_data) AND CHAR_LENGTH(after_data) <= 65535)
            ),
            CONSTRAINT chk_audit_request_id CHECK (
                request_id IS NULL OR request_id REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'
            )
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    END IF;

END //


-- ===========================================================================
-- PHASE B: triggers on the STAGING table (top level; see STRUCTURE AND SAFETY)
-- ===========================================================================
-- trg_audit_logs_set_time : occurred_at is always the database time (NOW(6)); a
--                           value sent by the application is ignored, so normal
--                           inserts never fail because of it.
-- trg_audit_logs_no_update / _no_delete : append-only for every account.
-- Limits: TRUNCATE does not fire DELETE triggers, and any account with DROP /
-- ALTER / TRIGGER rights (root, kpi_dba) can remove the triggers or the table.
-- Protection against that requires an application account without those rights;
-- these triggers do not protect against an administrator.

CREATE DEFINER = `kpi_dba`@`localhost` TRIGGER IF NOT EXISTS trg_audit_logs_set_time
BEFORE INSERT ON audit_logs_migration_stage
FOR EACH ROW
BEGIN
    SET NEW.occurred_at = NOW(6);
END //

CREATE DEFINER = `kpi_dba`@`localhost` TRIGGER IF NOT EXISTS trg_audit_logs_no_update
BEFORE UPDATE ON audit_logs_migration_stage
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'audit_logs is append-only: UPDATE is not allowed';
END //

CREATE DEFINER = `kpi_dba`@`localhost` TRIGGER IF NOT EXISTS trg_audit_logs_no_delete
BEFORE DELETE ON audit_logs_migration_stage
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'audit_logs is append-only: DELETE is not allowed';
END //


-- ===========================================================================
-- PHASE C: account check, strict verification, promote stage -> audit_logs
-- ===========================================================================
BEGIN NOT ATOMIC

    DECLARE diag TEXT DEFAULT '';
    DECLARE found_text MEDIUMTEXT;

    IF CURRENT_USER() <> @kpi_audit_account THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Migration NOT complete (phase C): run it as kpi_dba@localhost. No table was promoted.';
    END IF;

    IF (SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_logs') <> 0 THEN
        SET diag = CONCAT(diag, 'audit_logs already exists under the live name; ');
    END IF;

    IF (SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_logs_migration_stage') <> 1 THEN
        SET diag = CONCAT(diag, 'audit_logs_migration_stage is missing (phase A did not complete); ');
    END IF;

    -- kpi_performances: complete and exact (every expected object present and
    -- identical; no other foreign key on the workflow columns)
    IF (SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kpi_performances'
          AND COLUMN_NAME IN ('version', 'submitted_at', 'submitted_by', 'reviewed_at', 'reviewed_by', 'review_note')
          AND BINARY CONCAT_WS('|', COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, IFNULL(COLUMN_DEFAULT, '~'), EXTRA, IFNULL(COLLATION_NAME, '~')) <=> BINARY JSON_UNQUOTE(JSON_EXTRACT(@kpi_audit_wf_cols, CONCAT('$.', COLUMN_NAME)))) <> 6 THEN
        SET diag = CONCAT(diag, 'kpi_performances workflow columns missing or different; ');
    END IF;

    IF (SELECT COUNT(*) FROM (
            SELECT INDEX_NAME AS idx,
                   CONCAT_WS('|', INDEX_NAME, MAX(NON_UNIQUE),
                             GROUP_CONCAT(CONCAT(COLUMN_NAME, IFNULL(CONCAT('(', SUB_PART, ')'), '')) ORDER BY SEQ_IN_INDEX),
                             MAX(INDEX_TYPE)) AS fp
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kpi_performances'
              AND INDEX_NAME IN ('idx_performance_period_status', 'idx_performance_submitted_by', 'idx_performance_reviewed_by')
            GROUP BY INDEX_NAME) x
        WHERE BINARY x.fp <=> BINARY JSON_UNQUOTE(JSON_EXTRACT(@kpi_audit_wf_idx, CONCAT('$.', x.idx)))) <> 3 THEN
        SET diag = CONCAT(diag, 'kpi_performances indexes missing or different; ');
    END IF;

    SELECT COUNT(*), SUM(BINARY f.fp <=> BINARY JSON_UNQUOTE(JSON_EXTRACT(@kpi_audit_wf_fk, CONCAT('$.', f.name)))) INTO @kpi_audit_fk_total, @kpi_audit_fk_ok
    FROM (
        SELECT r.CONSTRAINT_NAME AS name,
               CONCAT_WS('|', r.CONSTRAINT_NAME, r.TABLE_NAME,
                         GROUP_CONCAT(k.COLUMN_NAME ORDER BY k.ORDINAL_POSITION),
                         r.REFERENCED_TABLE_NAME,
                         GROUP_CONCAT(k.REFERENCED_COLUMN_NAME ORDER BY k.ORDINAL_POSITION),
                         r.UPDATE_RULE, r.DELETE_RULE) AS fp
        FROM information_schema.REFERENTIAL_CONSTRAINTS r
        INNER JOIN information_schema.KEY_COLUMN_USAGE k
            ON k.CONSTRAINT_SCHEMA = r.CONSTRAINT_SCHEMA
           AND k.CONSTRAINT_NAME = r.CONSTRAINT_NAME
           AND k.TABLE_NAME = r.TABLE_NAME
        WHERE r.CONSTRAINT_SCHEMA = DATABASE()
          AND (r.CONSTRAINT_NAME IN ('fk_performance_submitted_by', 'fk_performance_reviewed_by')
               OR (r.TABLE_NAME = 'kpi_performances'
                   AND k.COLUMN_NAME IN ('version', 'submitted_at', 'submitted_by', 'reviewed_at', 'reviewed_by', 'review_note')))
        GROUP BY r.CONSTRAINT_NAME, r.TABLE_NAME, r.REFERENCED_TABLE_NAME, r.UPDATE_RULE, r.DELETE_RULE) f;
    IF @kpi_audit_fk_total <> 2 OR IFNULL(@kpi_audit_fk_ok, 0) <> 2 THEN
        SET diag = CONCAT(diag, 'kpi_performances foreign keys missing, different or unexpected; ');
    END IF;

    IF (SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'kpi_performances'
          AND CONSTRAINT_NAME IN ('chk_performance_submitted_pair', 'chk_performance_reviewed_pair')
          AND BINARY CONCAT_WS('|', CONSTRAINT_NAME, CHECK_CLAUSE) <=> BINARY JSON_UNQUOTE(JSON_EXTRACT(@kpi_audit_wf_chk, CONCAT('$.', CONSTRAINT_NAME)))) <> 2 THEN
        SET diag = CONCAT(diag, 'kpi_performances CHECK constraints missing or different; ');
    END IF;

    -- staging table: complete and exact
    IF (SELECT BINARY GROUP_CONCAT(CONCAT_WS('|', COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, IFNULL(COLUMN_DEFAULT, '~'), EXTRA, IFNULL(COLLATION_NAME, '~')) ORDER BY ORDINAL_POSITION SEPARATOR ';')
        FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_logs_migration_stage')
       <=> BINARY @kpi_audit_expect_cols = 0 THEN
        SET diag = CONCAT(diag, 'audit table columns missing or different; ');
    END IF;

    IF (SELECT BINARY GROUP_CONCAT(x.fp ORDER BY BINARY x.idx SEPARATOR ';') FROM (
            SELECT INDEX_NAME AS idx,
                   CONCAT_WS('|', INDEX_NAME, MAX(NON_UNIQUE),
                             GROUP_CONCAT(CONCAT(COLUMN_NAME, IFNULL(CONCAT('(', SUB_PART, ')'), '')) ORDER BY SEQ_IN_INDEX),
                             MAX(INDEX_TYPE)) AS fp
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_logs_migration_stage'
            GROUP BY INDEX_NAME) x)
       <=> BINARY @kpi_audit_expect_idx = 0 THEN
        SET diag = CONCAT(diag, 'audit table indexes missing or different; ');
    END IF;

    IF (SELECT BINARY GROUP_CONCAT(CONCAT_WS('|', CONSTRAINT_NAME, CHECK_CLAUSE) ORDER BY CONSTRAINT_NAME SEPARATOR ';;')
        FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_logs_migration_stage')
       <=> BINARY @kpi_audit_expect_chk = 0 THEN
        SET diag = CONCAT(diag, 'audit table CHECK constraints missing or different; ');
    END IF;

    IF (SELECT BINARY CONCAT_WS('|', ENGINE, TABLE_COLLATION) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_logs_migration_stage')
       <=> BINARY @kpi_audit_expect_tbl = 0 THEN
        SET diag = CONCAT(diag, 'audit table engine or collation differs; ');
    END IF;

    IF (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND (TABLE_NAME = 'audit_logs_migration_stage' OR REFERENCED_TABLE_NAME = 'audit_logs_migration_stage')) > 0 THEN
        SET diag = CONCAT(diag, 'audit table has unexpected foreign keys; ');
    END IF;

    -- triggers: exactly the 3 expected definitions, all on the staging table, no
    -- other trigger on it, and none of the 3 names on any other table
    SELECT COUNT(*), SUM(EVENT_OBJECT_TABLE = 'audit_logs_migration_stage'
                         AND BINARY CONCAT_WS('|', TRIGGER_NAME, EVENT_MANIPULATION, ACTION_TIMING, ACTION_ORIENTATION, DEFINER, TRIM(REGEXP_REPLACE(ACTION_STATEMENT, '[[:space:]]+', ' ')))
                             <=> BINARY JSON_UNQUOTE(JSON_EXTRACT(@kpi_audit_trg, CONCAT('$.', TRIGGER_NAME))))
    INTO @kpi_audit_trg_total, @kpi_audit_trg_ok
    FROM information_schema.TRIGGERS
    WHERE TRIGGER_SCHEMA = DATABASE()
      AND (EVENT_OBJECT_TABLE = 'audit_logs_migration_stage'
           OR TRIGGER_NAME IN ('trg_audit_logs_set_time', 'trg_audit_logs_no_update', 'trg_audit_logs_no_delete'));
    IF @kpi_audit_trg_total <> 3 OR IFNULL(@kpi_audit_trg_ok, 0) <> 3 THEN
        SELECT GROUP_CONCAT(CONCAT(TRIGGER_NAME, '@', EVENT_OBJECT_TABLE, '(', DEFINER, ')') ORDER BY TRIGGER_NAME) INTO found_text
        FROM information_schema.TRIGGERS
        WHERE TRIGGER_SCHEMA = DATABASE()
          AND (EVENT_OBJECT_TABLE = 'audit_logs_migration_stage'
               OR TRIGGER_NAME IN ('trg_audit_logs_set_time', 'trg_audit_logs_no_update', 'trg_audit_logs_no_delete'));
        SET diag = CONCAT(diag, 'triggers missing, different or unexpected (found: ', IFNULL(found_text, 'none'), '); ');
    END IF;

    IF diag <> '' THEN
        SET @kpi_audit_message = LEFT(CONCAT('Migration NOT complete (phase C): ', diag, 'Table not promoted; fix the cause and rerun.'), 500);
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = @kpi_audit_message;
    END IF;

    RENAME TABLE audit_logs_migration_stage TO audit_logs;

    SELECT 'OK: KPI approval audit migration verified' AS migration_result;

END //

DELIMITER ;
