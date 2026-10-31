-- Rollback for 20261016_kpi_approval_audit.sql (MariaDB 10.4.28).
--
-- WHAT IT REMOVES: the objects that migration creates, and nothing else:
--   audit_logs or audit_logs_migration_stage (with its triggers), the CHECKs
--   chk_performance_submitted_pair / chk_performance_reviewed_pair, the foreign keys
--   fk_performance_submitted_by / fk_performance_reviewed_by, the indexes
--   idx_performance_period_status / idx_performance_submitted_by /
--   idx_performance_reviewed_by and the columns version, submitted_at, submitted_by,
--   reviewed_at, reviewed_by, review_note on kpi_performances.
--
-- WHEN IT REFUSES (SIGNAL before any DROP; nothing is dropped or changed):
--   1. Metadata visibility. The current account must hold SELECT, TRIGGER and
--      SHOW VIEW globally or on this database, granted directly to the account
--      (grants through roles, wildcard database patterns or single tables are not
--      accepted; the account name is compared case-sensitively, so grants of an
--      account whose name differs only in letter case never count). Without them
--      triggers, views and constraints can be invisible in information_schema, and
--      "nothing found" would not mean "nothing exists". Tested with real accounts
--      on an isolated MariaDB 10.4.28 instance with synthetic data (not production):
--      refused without TRIGGER, without SHOW VIEW, with TRIGGER on a single table
--      only, with the privileges only through a role, and for an account whose name
--      differs only in letter case from the one holding the grants; also with
--      mysql --force and with the confirmation set; nothing changed in any case.
--      The database name must contain only letters, digits and '_' (it is used in
--      the cross-database search patterns).
--   2. Ownership / structure. Every object of the design that exists is compared
--      with the exact definition the migration creates (the EXPECTED DESIGN block
--      below is a verbatim copy of the migration's block):
--        - a workflow column, index, foreign key or CHECK differs in any way
--          (type, unsigned, nullability, default, columns, referenced table/column,
--          update/delete rule, normalised CHECK expression);
--        - the audit table differs in any column, index, CHECK, engine or
--          collation, or has / is the target of a foreign key;
--        - any trigger with one of the 3 audit trigger names, or any trigger on
--          either audit table, differs in event, timing, orientation, DEFINER
--          (kpi_dba@localhost) or body, or is unexpected, or is on another table;
--          audit_logs (the promoted table) does not have exactly the 3 triggers;
--        - both audit_logs and audit_logs_migration_stage exist;
--        - another index, foreign key or CHECK on kpi_performances uses a workflow
--          column;
--        - the set of objects is not a state this migration can produce (each of
--          its ALTER statements adds all columns / all indexes / both foreign keys /
--          both CHECKs at once, in that order, before the audit table exists).
--   3. Dependencies (checked only when something would be dropped):
--        - a foreign key in any visible table references a workflow column of
--          kpi_performances or an audit table;
--        - a generated column expression or a DEFAULT expression of another column
--          of kpi_performances mentions a workflow column;
--        - a trigger on any table of this database other than the audit table
--          (including kpi_performances) mentions a workflow column, audit_logs or
--          audit_logs_migration_stage;
--        - a view of this database mentions one of them;
--        - a visible trigger, view, stored routine or event in another database
--          mentions this database's kpi_performances or audit tables
--          (qualified name `db`.`table`);
--        - a readable stored routine or event of this database mentions one of them.
--   4. Unverifiable classes. Whenever something would be dropped, the rollback
--      ALWAYS requires the operator's confirmation of a manual review (see
--      OPERATOR PRE-CHECK) for:
--        - stored routines and events: information_schema.ROUTINES / EVENTS only
--          list what the current account is allowed to see, and it has not been
--          proven for MariaDB 10.4.28 which privileges make that list complete, so
--          an empty or partial list is never taken to mean "none". On the XAMPP
--          server (not on the isolated MariaDB 10.4.28 test instances), both are
--          also unreadable (ROUTINES: mysql.proc needs mysql_upgrade; EVENTS:
--          event scheduler disabled). This limitation was reported in earlier
--          tests, but the raw output was not retained. On the isolated instances
--          ROUTINES was readable; EVENTS was unreadable there too, because they
--          ran with the event scheduler DISABLED. "This server" in the comments
--          of the checks below means that XAMPP server;
--        - objects in other databases: their visibility depends on privileges in
--          those databases, which this file does not prove either.
--      The automatic searches still run where readable, and any reference they
--      find is a hard refusal (refusal 3) that the confirmation cannot override.
--   5. Data (checked only after the structure, including the exact column types,
--      has been verified, so no value is compared through an implicit conversion):
--        - audit_logs or audit_logs_migration_stage contains any row;
--        - any row has version <> 0, or a non-NULL submitted_at, submitted_by,
--          reviewed_at, reviewed_by or review_note.
--      Export the data and decide explicitly before dropping anything.
--   Refusals 1-3 require manual investigation by a DBA.
--
-- The dependency searches are text searches on names (word-boundary, case-
-- insensitive); they can refuse on a harmless match (fail closed) but can miss a
-- reference built dynamically (e.g. a name assembled in a prepared statement).
-- NOT checked at all: application code and SQL outside this server (reports,
-- scripts, other servers or replicas). Nothing here proves that no dependency
-- exists outside the database metadata listed above.
--
-- OPERATOR PRE-CHECK (needed for every run that would drop something):
--   a. as an administrator who can see them all, review every stored routine and
--      event in every database for references to kpi_performances.version /
--      submitted_at / submitted_by / reviewed_at / reviewed_by / review_note,
--      audit_logs and audit_logs_migration_stage;
--   b. review views, triggers and foreign keys in every other database the same
--      way;
--   c. then, in ONE interactive session, set the confirmation and run the file:
--        mysql -u <admin> -p <database>
--        SET @kpi_audit_rollback_manual_check = 'ROUTINES_EVENTS_OTHER_DATABASES_REVIEWED';
--        SOURCE database/migrations/20261016_kpi_approval_audit_rollback.sql
--   Do NOT set it in an option file (my.cnf [client]/[mysql]) or with
--   --init-command: that would apply it to every session without a review.
--   The file copies the value and clears @kpi_audit_rollback_manual_check at the
--   start of every run (also when the run is refused), so a confirmation is used
--   for one run only and must be set again for the next one.
--   The confirmation only covers the classes in refusal 4; every privilege,
--   ownership, dependency and data check still applies.
--
-- RESULT: if none of the objects exists it prints "OK: nothing to roll back ...".
-- After a successful run it verifies that every object is gone and prints
-- "OK: KPI approval audit rollback completed and verified". Any other outcome is
-- NOT a completed rollback.
--
-- Structure: all checks and every DROP are ONE compound statement. Every refusal
-- is a SIGNAL that stops the whole statement before the first DROP, and there is
-- no statement after it, so mysql --force cannot bypass the checks. Objects are
-- dropped in reverse creation order (audit table with its triggers, CHECKs,
-- foreign keys, indexes, columns), so every intermediate state is one the
-- migration can also produce and a rerun after an interrupted rollback is verified
-- the same way. DDL is not atomic: application writes must stay stopped for the
-- whole run.
--
-- Operational rules (see the OPERATIONAL RUNBOOK in 20261016_kpi_approval_audit.sql):
--   - approved maintenance window, application writes stopped for the whole run,
--     a verified backup taken first;
--   - never run it after audit or workflow data has been created (it refuses);
--     note that the migration runbook's audit smoke test (step 10) adds a
--     permanent audit row, so after that step this file always refuses;
--   - confirm DATABASE(), @@hostname and CURRENT_USER() before running.
-- Test status (isolated MariaDB 10.4.28, synthetic data only; see TEST STATUS in
-- 20261016_kpi_approval_audit.sql): PASS for the privilege refusals, the
-- confirmation, and a complete rollback that restores the schema exactly. NOT
-- VERIFIED: the completeness of visible stored routines and events, and anything
-- on production. NOT RUN: events and other-database dependency tests, a rollback
-- interrupted in the middle of its DDL, any run on production.
-- Required privileges: SELECT, TRIGGER and SHOW VIEW (checks, see refusal 1), DROP
-- (audit table), and ALTER + CREATE + INSERT + INDEX on kpi_performances (dropping
-- columns, indexes and constraints copies the table).
--
-- Run with the mysql client by the administrator account, WITHOUT --force
-- (see OPERATOR PRE-CHECK c).

SET SESSION sql_mode = CONCAT_WS(',', NULLIF(@@SESSION.sql_mode, ''), 'STRICT_ALL_TABLES');
SET SESSION group_concat_max_len = 1000000;

-- ---------------------------------------------------------------------------
-- BEGIN EXPECTED DESIGN (verbatim copy of the block in 20261016_kpi_approval_audit.sql)
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
-- ---------------------------------------------------------------------------
-- END EXPECTED DESIGN
-- ---------------------------------------------------------------------------

-- Dependency search patterns (word boundary; case-insensitive under the default
-- collation, so VERSION also matches: fail closed).
SET @kpi_audit_rb_cols_re = '(^|[^A-Za-z0-9_$])(version|submitted_at|submitted_by|reviewed_at|reviewed_by|review_note)([^A-Za-z0-9_$]|$)';
SET @kpi_audit_rb_names_re = '(^|[^A-Za-z0-9_$])(version|submitted_at|submitted_by|reviewed_at|reviewed_by|review_note|audit_logs|audit_logs_migration_stage)([^A-Za-z0-9_$]|$)';
SET @kpi_audit_rb_ack_token = 'ROUTINES_EVENTS_OTHER_DATABASES_REVIEWED';

DELIMITER //

BEGIN NOT ATOMIC

    DECLARE diag TEXT DEFAULT '';
    DECLARE data_diag TEXT DEFAULT '';
    DECLARE found_text MEDIUMTEXT;
    DECLARE live_exists INT DEFAULT 0;
    DECLARE stage_exists INT DEFAULT 0;
    DECLARE audit_table VARCHAR(64) DEFAULT NULL;
    DECLARE cols_present INT DEFAULT 0;
    DECLARE cols_ok INT DEFAULT 0;
    DECLARE idx_present INT DEFAULT 0;
    DECLARE idx_ok INT DEFAULT 0;
    DECLARE fk_total INT DEFAULT 0;
    DECLARE fk_ok INT DEFAULT 0;
    DECLARE chk_present INT DEFAULT 0;
    DECLARE chk_ok INT DEFAULT 0;
    DECLARE trg_total INT DEFAULT 0;
    DECLARE trg_ok INT DEFAULT 0;
    DECLARE global_privs INT DEFAULT 0;
    DECLARE db_privs INT DEFAULT 0;
    DECLARE grantee_text VARCHAR(400) DEFAULT '';
    DECLARE qualified_re VARCHAR(400) DEFAULT '';
    DECLARE routines_unreadable INT DEFAULT 0;
    DECLARE events_unreadable INT DEFAULT 0;
    DECLARE hidden_count INT DEFAULT 0;
    DECLARE unverifiable TEXT DEFAULT '';
    DECLARE manual_check TEXT DEFAULT NULL;

    -- Confirmation is consumed by this run: copied, then cleared before anything
    -- else (also when the run is refused later), so it cannot be reused.
    SET manual_check = @kpi_audit_rollback_manual_check;
    SET @kpi_audit_rollback_manual_check = NULL;

    IF DATABASE() IS NULL
       OR (SELECT COUNT(*) FROM information_schema.TABLES
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('kpi_performances', 'users')) <> 2 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Rollback refused: no database selected, or kpi_performances / users not found. Nothing was dropped.';
    END IF;

    IF NOT (BINARY DATABASE() REGEXP '^[A-Za-z0-9_]+$') THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Rollback refused: database name contains characters other than letters, digits and _ (used in dependency search patterns). Nothing was dropped.';
    END IF;

    -- -----------------------------------------------------------------------
    -- 0. Metadata visibility (refusal 1): SELECT, TRIGGER and SHOW VIEW granted
    --    directly to the current account, globally or on this database
    -- -----------------------------------------------------------------------
    SET grantee_text = CONCAT('''', SUBSTRING_INDEX(CURRENT_USER(), '@', 1), '''@''', SUBSTRING_INDEX(CURRENT_USER(), '@', -1), '''');

    SELECT COUNT(DISTINCT PRIVILEGE_TYPE) INTO global_privs
    FROM information_schema.USER_PRIVILEGES
    WHERE BINARY GRANTEE = BINARY grantee_text AND PRIVILEGE_TYPE IN ('SELECT', 'TRIGGER', 'SHOW VIEW');

    SELECT COUNT(DISTINCT p.PRIVILEGE_TYPE) INTO db_privs
    FROM (
        SELECT PRIVILEGE_TYPE FROM information_schema.USER_PRIVILEGES
        WHERE BINARY GRANTEE = BINARY grantee_text AND PRIVILEGE_TYPE IN ('SELECT', 'TRIGGER', 'SHOW VIEW')
        UNION ALL
        SELECT PRIVILEGE_TYPE FROM information_schema.SCHEMA_PRIVILEGES
        WHERE BINARY GRANTEE = BINARY grantee_text
          AND TABLE_SCHEMA IN (DATABASE(), REPLACE(DATABASE(), '_', '\\_'))
          AND PRIVILEGE_TYPE IN ('SELECT', 'TRIGGER', 'SHOW VIEW')
    ) p;

    IF db_privs <> 3 THEN
        SET @kpi_audit_rollback_message = CONCAT(
            'Rollback refused: ', CURRENT_USER(), ' does not hold SELECT, TRIGGER and SHOW VIEW globally or on this database ',
            '(granted directly), so triggers, views and constraints may be invisible and cannot be verified. Nothing was dropped.');
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = @kpi_audit_rollback_message;
    END IF;

    SET qualified_re = CONCAT('`?', DATABASE(), '`?[[:space:]]*[.][[:space:]]*`?(kpi_performances|audit_logs|audit_logs_migration_stage)([^A-Za-z0-9_$]|$)');

    -- =======================================================================
    -- 1. Ownership / structure (no data is read in this section)
    -- =======================================================================

    -- 1.1 workflow columns: every existing one exact; all 6 or none
    SELECT COUNT(*), IFNULL(SUM(BINARY CONCAT_WS('|', COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, IFNULL(COLUMN_DEFAULT, '~'), EXTRA, IFNULL(COLLATION_NAME, '~')) <=> BINARY JSON_UNQUOTE(JSON_EXTRACT(@kpi_audit_wf_cols, CONCAT('$.', COLUMN_NAME)))), 0),
           GROUP_CONCAT(IF(BINARY CONCAT_WS('|', COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, IFNULL(COLUMN_DEFAULT, '~'), EXTRA, IFNULL(COLLATION_NAME, '~')) <=> BINARY JSON_UNQUOTE(JSON_EXTRACT(@kpi_audit_wf_cols, CONCAT('$.', COLUMN_NAME))), NULL, COLUMN_NAME) ORDER BY COLUMN_NAME)
    INTO cols_present, cols_ok, found_text
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kpi_performances' AND COLUMN_NAME IN ('version', 'submitted_at', 'submitted_by', 'reviewed_at', 'reviewed_by', 'review_note');
    IF cols_present <> cols_ok THEN
        SET diag = CONCAT(diag, 'workflow column definition differs: ', found_text, '; ');
    END IF;
    IF cols_present NOT IN (0, 6) THEN
        SET diag = CONCAT(diag, 'incomplete workflow column set (', cols_present, ' of 6); ');
    END IF;

    -- 1.2 indexes: every existing one exact; all 3 or none; no other index on a
    --     workflow column (dropping the column would change it)
    SELECT COUNT(*), IFNULL(SUM(BINARY x.fp <=> BINARY JSON_UNQUOTE(JSON_EXTRACT(@kpi_audit_wf_idx, CONCAT('$.', x.idx)))), 0),
           GROUP_CONCAT(IF(BINARY x.fp <=> BINARY JSON_UNQUOTE(JSON_EXTRACT(@kpi_audit_wf_idx, CONCAT('$.', x.idx))), NULL, x.idx) ORDER BY x.idx)
    INTO idx_present, idx_ok, found_text
    FROM (
            SELECT INDEX_NAME AS idx,
                   CONCAT_WS('|', INDEX_NAME, MAX(NON_UNIQUE),
                             GROUP_CONCAT(CONCAT(COLUMN_NAME, IFNULL(CONCAT('(', SUB_PART, ')'), '')) ORDER BY SEQ_IN_INDEX),
                             MAX(INDEX_TYPE)) AS fp
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kpi_performances' AND INDEX_NAME IN ('idx_performance_period_status', 'idx_performance_submitted_by', 'idx_performance_reviewed_by')
            GROUP BY INDEX_NAME) x;
    IF idx_present <> idx_ok THEN
        SET diag = CONCAT(diag, 'index definition differs: ', found_text, '; ');
    END IF;
    IF idx_present NOT IN (0, 3) THEN
        SET diag = CONCAT(diag, 'incomplete index set (', idx_present, ' of 3); ');
    END IF;
    SELECT GROUP_CONCAT(DISTINCT INDEX_NAME ORDER BY INDEX_NAME) INTO found_text
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kpi_performances'
      AND COLUMN_NAME IN ('version', 'submitted_at', 'submitted_by', 'reviewed_at', 'reviewed_by', 'review_note') AND INDEX_NAME NOT IN ('idx_performance_period_status', 'idx_performance_submitted_by', 'idx_performance_reviewed_by');
    IF found_text IS NOT NULL THEN
        SET diag = CONCAT(diag, 'other index uses a workflow column: ', found_text, '; ');
    END IF;

    -- 1.3 foreign keys: the expected names and any FK on a workflow column; every
    --     one must be exact (an unexpected FK has no expected definition)
    SELECT COUNT(*), IFNULL(SUM(BINARY f.fp <=> BINARY JSON_UNQUOTE(JSON_EXTRACT(@kpi_audit_wf_fk, CONCAT('$.', f.name)))), 0),
           GROUP_CONCAT(IF(BINARY f.fp <=> BINARY JSON_UNQUOTE(JSON_EXTRACT(@kpi_audit_wf_fk, CONCAT('$.', f.name))), NULL, f.name) ORDER BY f.name)
    INTO fk_total, fk_ok, found_text
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
               OR (r.TABLE_NAME = 'kpi_performances' AND k.COLUMN_NAME IN ('version', 'submitted_at', 'submitted_by', 'reviewed_at', 'reviewed_by', 'review_note')))
        GROUP BY r.CONSTRAINT_NAME, r.TABLE_NAME, r.REFERENCED_TABLE_NAME, r.UPDATE_RULE, r.DELETE_RULE
    ) f;
    IF fk_total <> fk_ok THEN
        SET diag = CONCAT(diag, 'foreign key differs or unexpected: ', found_text, '; ');
    END IF;
    IF fk_total NOT IN (0, 2) THEN
        SET diag = CONCAT(diag, 'incomplete foreign key set (', fk_total, ' of 2); ');
    END IF;

    -- 1.4 CHECKs: the expected names exact; all 2 or none; no other CHECK on
    --     kpi_performances mentions a workflow column
    SELECT COUNT(*), IFNULL(SUM(BINARY CONCAT_WS('|', CONSTRAINT_NAME, CHECK_CLAUSE) <=> BINARY JSON_UNQUOTE(JSON_EXTRACT(@kpi_audit_wf_chk, CONCAT('$.', CONSTRAINT_NAME)))), 0),
           GROUP_CONCAT(IF(BINARY CONCAT_WS('|', CONSTRAINT_NAME, CHECK_CLAUSE) <=> BINARY JSON_UNQUOTE(JSON_EXTRACT(@kpi_audit_wf_chk, CONCAT('$.', CONSTRAINT_NAME))), NULL, CONSTRAINT_NAME) ORDER BY CONSTRAINT_NAME)
    INTO chk_present, chk_ok, found_text
    FROM information_schema.CHECK_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'kpi_performances' AND CONSTRAINT_NAME IN ('chk_performance_submitted_pair', 'chk_performance_reviewed_pair');
    IF chk_present <> chk_ok THEN
        SET diag = CONCAT(diag, 'CHECK constraint differs: ', found_text, '; ');
    END IF;
    IF chk_present NOT IN (0, 2) THEN
        SET diag = CONCAT(diag, 'incomplete CHECK set (', chk_present, ' of 2); ');
    END IF;
    SELECT GROUP_CONCAT(CONSTRAINT_NAME ORDER BY CONSTRAINT_NAME) INTO found_text
    FROM information_schema.CHECK_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'kpi_performances'
      AND CONSTRAINT_NAME NOT IN ('chk_performance_submitted_pair', 'chk_performance_reviewed_pair')
      AND CHECK_CLAUSE REGEXP '`(version|submitted_at|submitted_by|reviewed_at|reviewed_by|review_note)`';
    IF found_text IS NOT NULL THEN
        SET diag = CONCAT(diag, 'other CHECK uses a workflow column: ', found_text, '; ');
    END IF;

    -- 1.6 audit table: exactly one name, complete exact definition, no foreign keys
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
            SET diag = CONCAT(diag, audit_table, ' has or is the target of a foreign key; ');
        END IF;
    END IF;

    -- 1.7 triggers: every trigger with one of the 3 names, or on either audit table,
    --     must be one of the 3 exact definitions on the audit table; the promoted
    --     table must have all 3
    SELECT COUNT(*),
           IFNULL(SUM(EVENT_OBJECT_TABLE = audit_table
                      AND BINARY CONCAT_WS('|', TRIGGER_NAME, EVENT_MANIPULATION, ACTION_TIMING, ACTION_ORIENTATION, DEFINER, TRIM(REGEXP_REPLACE(ACTION_STATEMENT, '[[:space:]]+', ' '))) <=> BINARY JSON_UNQUOTE(JSON_EXTRACT(@kpi_audit_trg, CONCAT('$.', TRIGGER_NAME)))), 0),
           GROUP_CONCAT(CONCAT(TRIGGER_NAME, '@', EVENT_OBJECT_TABLE, '(', DEFINER, ')') ORDER BY TRIGGER_NAME)
    INTO trg_total, trg_ok, found_text
    FROM information_schema.TRIGGERS
    WHERE TRIGGER_SCHEMA = DATABASE()
      AND (TRIGGER_NAME IN ('trg_audit_logs_set_time', 'trg_audit_logs_no_update', 'trg_audit_logs_no_delete')
           OR EVENT_OBJECT_TABLE IN ('audit_logs', 'audit_logs_migration_stage'));
    IF trg_total <> trg_ok THEN
        SET diag = CONCAT(diag, 'trigger differs, is unexpected or is on another table (found: ', found_text, '); ');
    END IF;
    IF live_exists = 1 AND stage_exists = 0 AND trg_ok <> 3 THEN
        SET diag = CONCAT(diag, 'audit_logs does not have exactly the 3 audit triggers; ');
    END IF;

    -- 1.8 the set must be a state this migration can produce (creation order:
    --     columns, indexes, foreign keys, CHECKs, audit table, triggers)
    IF (idx_present > 0 AND cols_present <> 6)
       OR (fk_total > 0 AND idx_present <> 3)
       OR (chk_present > 0 AND fk_total <> 2)
       OR (audit_table IS NOT NULL AND chk_present <> 2) THEN
        SET diag = CONCAT(diag, 'object set is not a state this migration produces; ');
    END IF;

    -- -----------------------------------------------------------------------
    -- 1.9 Dependencies (refusal 3), only when something would be dropped
    -- -----------------------------------------------------------------------
    IF cols_present > 0 OR audit_table IS NOT NULL THEN

        -- foreign keys in any visible table that reference a workflow column or
        -- an audit table of this database
        SELECT GROUP_CONCAT(DISTINCT CONCAT(TABLE_SCHEMA, '.', TABLE_NAME, '.', CONSTRAINT_NAME) ORDER BY TABLE_SCHEMA, TABLE_NAME, CONSTRAINT_NAME)
        INTO found_text
        FROM information_schema.KEY_COLUMN_USAGE
        WHERE REFERENCED_TABLE_SCHEMA = DATABASE()
          AND ((REFERENCED_TABLE_NAME = 'kpi_performances'
                AND REFERENCED_COLUMN_NAME IN ('version', 'submitted_at', 'submitted_by', 'reviewed_at', 'reviewed_by', 'review_note'))
               OR REFERENCED_TABLE_NAME IN ('audit_logs', 'audit_logs_migration_stage'));
        IF found_text IS NOT NULL THEN
            SET diag = CONCAT(diag, 'foreign key references a workflow column or audit table: ', found_text, '; ');
        END IF;

        -- generated columns and DEFAULT expressions of other kpi_performances columns
        SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY COLUMN_NAME) INTO found_text
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kpi_performances'
          AND COLUMN_NAME NOT IN ('version', 'submitted_at', 'submitted_by', 'reviewed_at', 'reviewed_by', 'review_note')
          AND (IFNULL(GENERATION_EXPRESSION, '') REGEXP @kpi_audit_rb_cols_re
               OR IFNULL(COLUMN_DEFAULT, '') REGEXP @kpi_audit_rb_cols_re);
        IF found_text IS NOT NULL THEN
            SET diag = CONCAT(diag, 'generated column or DEFAULT expression uses a workflow column: kpi_performances.', found_text, '; ');
        END IF;

        -- triggers on every table of this database except the audit table itself
        -- (its triggers were verified exactly in 1.7), and triggers in other
        -- databases that name this database's tables
        SELECT GROUP_CONCAT(CONCAT(TRIGGER_SCHEMA, '.', TRIGGER_NAME, '@', EVENT_OBJECT_TABLE) ORDER BY TRIGGER_SCHEMA, TRIGGER_NAME)
        INTO found_text
        FROM information_schema.TRIGGERS
        WHERE (TRIGGER_SCHEMA = DATABASE()
               AND EVENT_OBJECT_TABLE NOT IN ('audit_logs', 'audit_logs_migration_stage')
               AND ACTION_STATEMENT REGEXP @kpi_audit_rb_names_re)
           OR (TRIGGER_SCHEMA <> DATABASE() AND ACTION_STATEMENT REGEXP qualified_re);
        IF found_text IS NOT NULL THEN
            SET diag = CONCAT(diag, 'trigger mentions a workflow column or audit table: ', found_text, '; ');
        END IF;

        -- views of this database, and views in other databases that name this
        -- database's tables
        SELECT GROUP_CONCAT(CONCAT(TABLE_SCHEMA, '.', TABLE_NAME) ORDER BY TABLE_SCHEMA, TABLE_NAME) INTO found_text
        FROM information_schema.VIEWS
        WHERE (TABLE_SCHEMA = DATABASE() AND VIEW_DEFINITION REGEXP @kpi_audit_rb_names_re)
           OR (TABLE_SCHEMA <> DATABASE() AND VIEW_DEFINITION REGEXP qualified_re);
        IF found_text IS NOT NULL THEN
            SET diag = CONCAT(diag, 'view mentions a workflow column or audit table: ', found_text, '; ');
        END IF;

        -- views whose definition is hidden from this account
        SELECT COUNT(*) INTO hidden_count FROM information_schema.VIEWS
        WHERE TABLE_SCHEMA = DATABASE() AND (VIEW_DEFINITION IS NULL OR VIEW_DEFINITION = '');
        IF hidden_count > 0 THEN
            SET diag = CONCAT(diag, hidden_count, ' view definition(s) of this database are hidden; ');
        END IF;

        -- stored routines (information_schema.ROUTINES fails on this server until
        -- mysql_upgrade is run)
        BEGIN
            DECLARE CONTINUE HANDLER FOR SQLEXCEPTION SET routines_unreadable = 1;
            SELECT IFNULL(SUM(ROUTINE_DEFINITION IS NULL), 0),
                   GROUP_CONCAT(IF((ROUTINE_SCHEMA = DATABASE() AND ROUTINE_DEFINITION REGEXP @kpi_audit_rb_names_re)
                                   OR ROUTINE_DEFINITION REGEXP qualified_re,
                                   CONCAT(ROUTINE_SCHEMA, '.', ROUTINE_NAME), NULL))
            INTO hidden_count, found_text
            FROM information_schema.ROUTINES;
        END;
        IF routines_unreadable = 0 AND found_text IS NOT NULL THEN
            SET diag = CONCAT(diag, 'stored routine mentions a workflow column or audit table: ', found_text, '; ');
        END IF;
        -- always unverifiable: a readable but possibly incomplete list proves nothing
        SET unverifiable = CONCAT(unverifiable, 'stored routines (',
            IF(routines_unreadable = 1, 'information_schema.ROUTINES unreadable',
               IF(hidden_count > 0, 'some definitions hidden', 'completeness of the visible list not provable')), '); ');

        -- events (information_schema.EVENTS fails while the event scheduler is
        -- disabled on this server)
        SET hidden_count = 0;
        SET found_text = NULL;
        BEGIN
            DECLARE CONTINUE HANDLER FOR SQLEXCEPTION SET events_unreadable = 1;
            SELECT IFNULL(SUM(EVENT_DEFINITION IS NULL), 0),
                   GROUP_CONCAT(IF((EVENT_SCHEMA = DATABASE() AND EVENT_DEFINITION REGEXP @kpi_audit_rb_names_re)
                                   OR EVENT_DEFINITION REGEXP qualified_re,
                                   CONCAT(EVENT_SCHEMA, '.', EVENT_NAME), NULL))
            INTO hidden_count, found_text
            FROM information_schema.EVENTS;
        END;
        IF events_unreadable = 0 AND found_text IS NOT NULL THEN
            SET diag = CONCAT(diag, 'event mentions a workflow column or audit table: ', found_text, '; ');
        END IF;
        SET unverifiable = CONCAT(unverifiable, 'events (',
            IF(events_unreadable = 1, 'information_schema.EVENTS unreadable',
               IF(hidden_count > 0, 'some definitions hidden', 'completeness of the visible list not provable')), '); ');

        SET unverifiable = CONCAT(unverifiable, 'objects in other databases (',
            IF(global_privs <> 3, 'no global SELECT, TRIGGER and SHOW VIEW', 'visibility in other databases not provable'), '); ');
    END IF;

    IF diag <> '' THEN
        SET @kpi_audit_rollback_message = LEFT(CONCAT(
            'Rollback refused: objects cannot be verified as created by this migration, or other objects depend on them: ', diag,
            'Nothing was dropped. Manual DBA investigation required.'), 500);
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = @kpi_audit_rollback_message;
    END IF;

    -- -----------------------------------------------------------------------
    -- 1.10 Unverifiable classes (refusal 4): only with an explicit operator
    --      confirmation of the manual review (see OPERATOR PRE-CHECK)
    -- -----------------------------------------------------------------------
    IF unverifiable <> '' AND NOT (BINARY IFNULL(manual_check, '') <=> BINARY @kpi_audit_rb_ack_token) THEN
        SET @kpi_audit_rollback_message = LEFT(CONCAT(
            'Rollback refused: cannot be checked automatically: ', unverifiable,
            'Review them manually, then set @kpi_audit_rollback_manual_check = ''', @kpi_audit_rb_ack_token,
            ''' and run the file in the same session (see header; it is cleared after each run). Nothing was dropped.'), 500);
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = @kpi_audit_rollback_message;
    END IF;

    -- =======================================================================
    -- 2. Data (column types were verified exactly in 1.1, so these comparisons
    --    involve no implicit type conversion)
    -- =======================================================================
    IF audit_table IS NOT NULL THEN
        EXECUTE IMMEDIATE CONCAT('SELECT COUNT(*) INTO @kpi_audit_rollback_rows FROM `', audit_table, '`');
        IF @kpi_audit_rollback_rows > 0 THEN
            SET data_diag = CONCAT(data_diag, audit_table, ' contains ', @kpi_audit_rollback_rows, ' row(s); ');
        END IF;
    END IF;

    IF cols_present = 6 THEN
        EXECUTE IMMEDIATE 'SELECT CONCAT_WS('', '',
                    IF(SUM(version <> 0) > 0, ''version'', NULL),
                    IF(SUM(submitted_at IS NOT NULL) > 0, ''submitted_at'', NULL),
                    IF(SUM(submitted_by IS NOT NULL) > 0, ''submitted_by'', NULL),
                    IF(SUM(reviewed_at IS NOT NULL) > 0, ''reviewed_at'', NULL),
                    IF(SUM(reviewed_by IS NOT NULL) > 0, ''reviewed_by'', NULL),
                    IF(SUM(review_note IS NOT NULL) > 0, ''review_note'', NULL))
                INTO @kpi_audit_rollback_columns
                FROM kpi_performances';
        IF IFNULL(@kpi_audit_rollback_columns, '') <> '' THEN
            SET data_diag = CONCAT(data_diag, 'workflow data in kpi_performances.', @kpi_audit_rollback_columns, '; ');
        END IF;
    END IF;

    IF data_diag <> '' THEN
        SET @kpi_audit_rollback_message = LEFT(CONCAT(
            'Rollback refused: workflow or audit data exists: ', data_diag,
            'Nothing was dropped. Export the data and decide explicitly before dropping.'), 500);
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = @kpi_audit_rollback_message;
    END IF;

    -- =======================================================================
    -- 3. Drop (only reached when every check above passed), reverse creation order
    -- =======================================================================
    IF cols_present = 0 AND audit_table IS NULL THEN

        SELECT 'OK: nothing to roll back (no objects of this migration exist); nothing was changed' AS rollback_result;

    ELSE

        IF audit_table = 'audit_logs' THEN
            DROP TABLE audit_logs;
        ELSEIF audit_table = 'audit_logs_migration_stage' THEN
            DROP TABLE audit_logs_migration_stage;
        END IF;

        ALTER TABLE kpi_performances
            DROP CONSTRAINT IF EXISTS chk_performance_submitted_pair,
            DROP CONSTRAINT IF EXISTS chk_performance_reviewed_pair;

        ALTER TABLE kpi_performances
            DROP FOREIGN KEY IF EXISTS fk_performance_submitted_by,
            DROP FOREIGN KEY IF EXISTS fk_performance_reviewed_by;

        ALTER TABLE kpi_performances
            DROP INDEX IF EXISTS idx_performance_period_status,
            DROP INDEX IF EXISTS idx_performance_submitted_by,
            DROP INDEX IF EXISTS idx_performance_reviewed_by;

        ALTER TABLE kpi_performances
            DROP COLUMN IF EXISTS review_note,
            DROP COLUMN IF EXISTS reviewed_by,
            DROP COLUMN IF EXISTS reviewed_at,
            DROP COLUMN IF EXISTS submitted_by,
            DROP COLUMN IF EXISTS submitted_at,
            DROP COLUMN IF EXISTS version;

        -- 4. Verify that every object is gone
        IF (SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('audit_logs', 'audit_logs_migration_stage')) <> 0
           OR (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kpi_performances' AND COLUMN_NAME IN ('version', 'submitted_at', 'submitted_by', 'reviewed_at', 'reviewed_by', 'review_note')) <> 0
           OR (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kpi_performances' AND INDEX_NAME IN ('idx_performance_period_status', 'idx_performance_submitted_by', 'idx_performance_reviewed_by')) <> 0
           OR (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
               WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME IN ('fk_performance_submitted_by', 'fk_performance_reviewed_by')) <> 0
           OR (SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS
               WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'kpi_performances' AND CONSTRAINT_NAME IN ('chk_performance_submitted_pair', 'chk_performance_reviewed_pair')) <> 0
           OR (SELECT COUNT(*) FROM information_schema.TRIGGERS
               WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME IN ('trg_audit_logs_set_time', 'trg_audit_logs_no_update', 'trg_audit_logs_no_delete')) <> 0 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Rollback NOT complete: some objects still exist after the drops. Keep writes stopped and investigate.';
        END IF;

        SELECT 'OK: KPI approval audit rollback completed and verified' AS rollback_result,
               IF(unverifiable = '', 'none', CONCAT('operator-confirmed manual review: ', unverifiable)) AS manual_review;

    END IF;

END //

DELIMITER ;
