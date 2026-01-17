<?php

namespace PhpPgAdmin\Database\Dump;

use PhpPgAdmin\Database\Actions\AclActions;
use PhpPgAdmin\Database\Actions\RuleActions;
use PhpPgAdmin\Database\Actions\TriggerActions;
use PhpPgAdmin\Database\Export\SqlFormatter;
use PhpPgAdmin\Database\Actions\AdminActions;
use PhpPgAdmin\Database\Actions\IndexActions;
use PhpPgAdmin\Database\Actions\TableActions;
use PhpPgAdmin\Database\Actions\ConstraintActions;

/**
 * Dumper for PostgreSQL tables (structure and data).
 */
class TableDumper extends ExportDumper
{
    public function dump($subject, array $params, array $options = [])
    {
        $table = $params['table'] ?? null;
        $schema = $params['schema'] ?? $this->connection->_schema;

        if (!$table) {
            return;
        }

        $this->write("\n-- Table: \"{$schema}\".\"{$table}\"\n\n");

        if (empty($options['data_only'])) {
            $this->dumpStructure($table, $schema, $options);
        }

        if (empty($options['structure_only'])) {
            $this->dumpData($table, $schema, $options);
        }

        if (empty($options['data_only'])) {
            $this->writeIndexes($table, $options);
            $this->writeTriggers($table, $options);
            $this->writeRules($table, $options);
        }
    }

    protected function dumpStructure($table, $schema, $options)
    {
        // Use existing logic from TableActions/Postgres driver but adapted
        // Use writer-style method instead of getting SQL back
        $this->writeTableDefPrefix($table, $options);

        $this->dumpAutovacuumSettings($table, $schema);
    }

    protected function dumpData($table, $schema, $options)
    {
        $this->write("\n-- Data for table \"{$schema}\".\"{$table}\"\n");

        $insertFormat = $options['insert_format'] ?? 'copy'; // 'copy', 'single', or 'multi'
        $oids = !empty($options['oids']);

        // Optionally set session_replication_role to replica to avoid firing triggers during restore
        $replication_role_set = false;
        if (empty($options['suppress_replication_role'])) {
            $this->write("SET session_replication_role = 'replica';\n\n");
            $replication_role_set = true;
        }

        // Set fetch mode to NUM for data dumping
        $this->connection->conn->setFetchMode(ADODB_FETCH_NUM);

        // Determine average row size for CURSOR streaming
        /*
        SELECT pg_total_relation_size('mytable') / reltuples
FROM pg_class
WHERE oid = 'mytable'::regclass;

SELECT max(pg_column_size(t.*))
FROM mytable t
LIMIT 1000;

        */

        $this->connection->conn->Query(ADODB_FETCH_ASSOC);

        pg_query($conn, 'BEGIN');

        pg_query($conn, "
    DECLARE mycur NO SCROLL CURSOR FOR
    SELECT * FROM mytable ORDER BY id
");

        while (true) {
            $res = pg_query($conn, "FETCH FORWARD 1000 FROM mycur");
            if (!$res || pg_num_rows($res) === 0) {
                break;
            }

            while ($row = pg_fetch_row($res)) {
                $formatter->writeRow($row);
                $stream->write($formatter->buffer);
            }

            pg_free_result($res);
        }

        pg_query($conn, "CLOSE mycur");
        pg_query($conn, 'COMMIT');


        $rs = $this->connection->selectSet("SELECT * FROM \"{$schema}\".\"{$table}\"");

        if (!$rs) {
            // No recordset at all
            if ($replication_role_set) {
                $this->write("SET session_replication_role = 'origin';\n\n");
            }
            return;
        }

        // Move to first record (recordset may be positioned at EOF after initial select)
        if (is_callable([$rs, 'moveFirst'])) {
            $rs->moveFirst();
        }

        // Check if there's actually data after moving to first record
        if ($rs->EOF) {
            // No data to export
            if ($replication_role_set) {
                $this->write("SET session_replication_role = 'origin';\n\n");
            }
            return;
        }

        // Use SqlFormatter to generate SQL output
        $formatter = new SqlFormatter();

        // Set formatter to use dumper's output stream
        $formatter->setOutputStream($this->outputStream);

        // Format the recordset and write to output
        $metadata = [
            'table' => "\"{$schema}\".\"{$table}\"",
            'insert_format' => $insertFormat
        ];

        $formatter->format($rs, $metadata);


        // Restore fetch mode
        $this->connection->conn->setFetchMode(ADODB_FETCH_ASSOC);

        // Reset session replication role if we set it earlier
        if ($replication_role_set) {
            $this->write("SET session_replication_role = 'origin';\n\n");
        }
    }

    /**
     * Write table definition prefix (columns, constraints, comments, privileges).
     * Returns true on success, false on failure or missing table.
     */
    protected function writeTableDefPrefix($table, $options)
    {
        $tableActions = new TableActions($this->connection);
        $t = $tableActions->getTable($table);
        if (!is_object($t) || $t->recordCount() != 1) {
            $this->connection->rollbackTransaction();
            return false;
        }
        $this->connection->fieldClean($t->fields['relname']);
        $this->connection->fieldClean($t->fields['nspname']);

        $atts = $tableActions->getTableAttributes($table);
        if (!is_object($atts)) {
            $this->connection->rollbackTransaction();
            return false;
        }

        $cons = (new ConstraintActions($this->connection))->getConstraints($table);
        if (!is_object($cons)) {
            $this->connection->rollbackTransaction();
            return false;
        }

        // header / drop / create begin
        $this->write("-- Definition\n\n");
        $this->writeDrop('TABLE', "\"{$t->fields['nspname']}\".\"{$t->fields['relname']}\"", $options);
        $this->write("CREATE TABLE \"{$t->fields['nspname']}\".\"{$t->fields['relname']}\" (\n");

        // columns
        $col_comments_sql = '';
        $first_attr = true;
        while (!$atts->EOF) {
            if ($first_attr) {
                $first_attr = false;
            } else {
                $this->write(",\n");
            }
            $this->connection->fieldClean($atts->fields['attname']);
            $this->write("    \"{$atts->fields['attname']}\"");
            if (
                $this->connection->phpBool($atts->fields['attisserial']) &&
                ($atts->fields['type'] == 'integer' || $atts->fields['type'] == 'bigint')
            ) {
                $this->write(($atts->fields['type'] == 'integer') ? " SERIAL" : " BIGSERIAL");
            } else {
                $this->write(" " . $this->connection->formatType($atts->fields['type'], $atts->fields['atttypmod']));
                if ($this->connection->phpBool($atts->fields['attnotnull'])) {
                    $this->write(" NOT NULL");
                }
                if ($atts->fields['adsrc'] !== null) {
                    $this->write(" DEFAULT {$atts->fields['adsrc']}");
                }
            }

            if ($atts->fields['comment'] !== null) {
                $this->connection->clean($atts->fields['comment']);
                $col_comments_sql .= "COMMENT ON COLUMN \"{$t->fields['relname']}\".\"{$atts->fields['attname']}\"  IS '{$atts->fields['comment']}';\n";
            }

            $atts->moveNext();
        }

        // constraints
        while (!$cons->EOF) {
            if ($cons->fields['contype'] == 'n') {
                // Skip NOT NULL constraints as they are dumped with the column definition
                $cons->moveNext();
                continue;
            }
            $this->write(",\n");
            $this->connection->fieldClean($cons->fields['conname']);
            $this->write("    CONSTRAINT \"{$cons->fields['conname']}\" ");
            if ($cons->fields['consrc'] !== null) {
                $this->write($cons->fields['consrc']);
            } else {
                switch ($cons->fields['contype']) {
                    case 'p':
                        $keys = $tableActions->getAttributeNames($table, explode(' ', $cons->fields['indkey']));
                        $this->write("PRIMARY KEY (" . join(',', $keys) . ")");
                        break;
                    case 'u':
                        $keys = $tableActions->getAttributeNames($table, explode(' ', $cons->fields['indkey']));
                        $this->write("UNIQUE (" . join(',', $keys) . ")");
                        break;
                    default:
                        $this->connection->rollbackTransaction();
                        return false;
                }
            }

            $cons->moveNext();
        }

        $this->write("\n)");

        if ($this->connection->hasObjectID($table)) {
            $this->write(" WITH OIDS");
        } else {
            $this->write(" WITHOUT OIDS");
        }

        $this->write(";\n");

        // per-column ALTERs (statistics, storage)
        $atts->moveFirst();
        $first = true;
        while (!$atts->EOF) {
            $this->connection->fieldClean($atts->fields['attname']);
            // Only output SET STATISTICS if the value is non-negative and not empty
            if (isset($atts->fields['attstattarget']) && $atts->fields['attstattarget'] !== '' && $atts->fields['attstattarget'] >= 0) {
                if ($first) {
                    $this->write("\n");
                    $first = false;
                }
                $this->write("ALTER TABLE ONLY \"{$t->fields['nspname']}\".\"{$t->fields['relname']}\" ALTER COLUMN \"{$atts->fields['attname']}\" SET STATISTICS {$atts->fields['attstattarget']};\n");
            }
            if ($atts->fields['attstorage'] != $atts->fields['typstorage']) {
                $storage = null;
                switch ($atts->fields['attstorage']) {
                    case 'p':
                        $storage = 'PLAIN';
                        break;
                    case 'e':
                        $storage = 'EXTERNAL';
                        break;
                    case 'm':
                        $storage = 'MAIN';
                        break;
                    case 'x':
                        $storage = 'EXTENDED';
                        break;
                    default:
                        $this->connection->rollbackTransaction();
                        return false;
                }
                $this->write("ALTER TABLE ONLY \"{$t->fields['nspname']}\".\"{$t->fields['relname']}\" ALTER COLUMN \"{$atts->fields['attname']}\" SET STORAGE {$storage};\n");
            }

            $atts->moveNext();
        }

        // table comment
        if ($t->fields['relcomment'] !== null) {
            $this->connection->clean($t->fields['relcomment']);
            $this->write("\n-- Comment\n\n");
            $this->write("COMMENT ON TABLE \"{$t->fields['nspname']}\".\"{$t->fields['relname']}\" IS '{$t->fields['relcomment']}';\n");
        }

        // column comments
        if ($col_comments_sql != '') {
            $this->write($col_comments_sql);
        }

        // privileges
        $privs = (new AclActions($this->connection))->getPrivileges($table, 'table');
        if (!is_array($privs)) {
            $this->connection->rollbackTransaction();
            return false;
        }

        if (sizeof($privs) > 0) {
            $this->write("\n-- Privileges\n\n");
            $this->write("REVOKE ALL ON TABLE \"{$t->fields['nspname']}\".\"{$t->fields['relname']}\" FROM PUBLIC;\n");
            $this->writePrivilegesFromArray($privs, $t);
        }

        $this->write("\n");

        return true;
    }

    /**
     * Write indexes for the table.
     */
    private function writeIndexes($table, $options)
    {
        $indexActions = new IndexActions($this->connection);

        $indexes = $indexActions->getIndexes($table);

        if (!is_object($indexes) || $indexes->EOF) {
            return;
        }

        $this->write("\n-- Indexes\n\n");

        while (!$indexes->EOF) {
            $def = $indexes->fields['inddef'];
            if (!empty($options['if_not_exists'])) {
                if ($this->connection->major_version >= 9.5) {
                    $def = str_replace(
                        'CREATE UNIQUE INDEX',
                        'CREATE UNIQUE INDEX IF NOT EXISTS',

                        $def
                    );
                    $def = str_replace(
                        'CREATE INDEX',
                        'CREATE INDEX IF NOT EXISTS',
                        $def
                    );
                }
            }
            $this->write("$def;\n");
            $indexes->moveNext();
        }
    }

    /**
     * Write triggers for the table.
     */
    private function writeTriggers($table, $options)
    {
        $triggerActions = new TriggerActions($this->connection);
        $triggers = $triggerActions->getTriggers($table);

        if (!is_object($triggers) || $triggers->EOF) {
            return;
        }

        $this->write("\n-- Triggers\n\n");

        while (!$triggers->EOF) {
            $def = $triggers->fields['tgdef'];
            if (!empty($options['if_not_exists'])) {
                if ($this->connection->major_version >= 14) {
                    $def = str_replace(
                        'CREATE CONSTRAINT TRIGGER',
                        'CREATE OR REPLACE CONSTRAINT TRIGGER',
                        $def
                    );
                    $def = str_replace(
                        'CREATE TRIGGER',
                        'CREATE OR REPLACE TRIGGER',
                        $def
                    );
                }
            }
            $this->write("$def;\n");
            $triggers->moveNext();
        }
    }

    /**
     * Write rules for the table.
     */
    private function writeRules($table, $options)
    {
        $ruleActions = new RuleActions($this->connection);
        $rules = $ruleActions->getRules($table);

        if (!is_object($rules) || $rules->EOF) {
            return;
        }

        $this->write("\n-- Rules\n\n");

        while (!$rules->EOF) {
            $def = $rules->fields['definition'];
            $def = str_replace('CREATE RULE', 'CREATE OR REPLACE RULE', $def);
            $this->write("$def;\n");
            $rules->moveNext();
        }
    }

    /**
     * Take the privileges array format used previously and write corresponding GRANT/SET/RESET statements.
     */
    private function writePrivilegesFromArray($privs, $t)
    {
        foreach ($privs as $v) {
            $nongrant = array_diff($v[2], $v[4]);
            if (sizeof($v[2]) == 0 || ($v[0] == 'user' && $v[1] == $t->fields['relowner']))
                continue;
            if ($v[3] != $t->fields['relowner']) {
                $grantor = $v[3];
                $this->connection->clean($grantor);
                $this->write("SET SESSION AUTHORIZATION '{$grantor}';\n");
            }
            $this->write("GRANT " . join(', ', $nongrant) . " ON TABLE \"{$t->fields['relname']}\" TO ");
            switch ($v[0]) {
                case 'public':
                    $this->write("PUBLIC;\n");
                    break;
                case 'user':
                    $this->connection->fieldClean($v[1]);
                    $this->write("\"{$v[1]}\";\n");
                    break;
                case 'group':
                    $this->connection->fieldClean($v[1]);
                    $this->write("GROUP \"{$v[1]}\";\n");
                    break;
                default:
                    $this->connection->rollbackTransaction();
                    return;
            }

            if ($v[3] != $t->fields['relowner']) {
                $this->write("RESET SESSION AUTHORIZATION;\n");
            }

            if (sizeof($v[4]) == 0)
                continue;

            if ($v[3] != $t->fields['relowner']) {
                $grantor = $v[3];
                $this->connection->clean($grantor);
                $this->write("SET SESSION AUTHORIZATION '{$grantor}';\n");
            }

            $this->write("GRANT " . join(', ', $v[4]) . " ON \"{$t->fields['relname']}\" TO ");
            switch ($v[0]) {
                case 'public':
                    $this->write("PUBLIC");
                    break;
                case 'user':
                    $this->connection->fieldClean($v[1]);
                    $this->write("\"{$v[1]}\"");
                    break;
                case 'group':
                    $this->connection->fieldClean($v[1]);
                    $this->write("GROUP \"{$v[1]}\"");
                    break;
                default:
                    $this->connection->rollbackTransaction();
                    return;
            }
            $this->write(" WITH GRANT OPTION;\n");

            if ($v[3] != $t->fields['relowner']) {
                $this->write("RESET SESSION AUTHORIZATION;\n");
            }
        }
    }


    protected function dumpAutovacuumSettings($table, $schema)
    {
        $adminActions = new AdminActions($this->connection);

        $oldSchema = $this->connection->_schema;
        $this->connection->_schema = $schema;

        $autovacs = $adminActions->getTableAutovacuum($table);

        $this->connection->_schema = $oldSchema;

        if (!$autovacs || $autovacs->EOF) {
            return;
        }

        while ($autovacs && !$autovacs->EOF) {
            $options = [];
            foreach ($autovacs->fields as $key => $value) {
                if (is_int($key)) {
                    continue;
                }
                if ($key === 'nspname' || $key === 'relname') {
                    continue;
                }
                if ($value === null || $value === '') {
                    continue;
                }
                $options[] = $key . '=' . $value;
            }

            if (!empty($options)) {
                $this->write("ALTER TABLE \"{$schema}\".\"{$table}\" SET (" . implode(', ', $options) . ");\n");
                $this->write("\n");
            }

            $autovacs->moveNext();
        }
    }


    /**
     * Get table data as an ADORecordSet for export formatting.
     *
     * @param array $params Table parameters (schema, table)
     * @return mixed ADORecordSet or null if table cannot be read
     */
    public function getTableData($params)
    {
        $table = $params['table'] ?? null;
        $schema = $params['schema'] ?? $this->connection->_schema;

        if (!$table) {
            return null;
        }

        // Use existing dumpRelation method from connection to get table data
        $this->connection->conn->setFetchMode(ADODB_FETCH_NUM);
        $recordset = $this->connection->dumpRelation($table, false);

        return $recordset;
    }
}
