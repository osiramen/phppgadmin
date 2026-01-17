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
use PhpPgAdmin\Database\Cursor\CursorReader;

/**
 * Dumper for PostgreSQL tables (structure and data).
 */
class TableDumper extends ExportDumper
{
    private $tableQuoted;
    private $schemaQuoted;

    public function dump($subject, array $params, array $options = [])
    {
        $table = $params['table'] ?? null;
        $schema = $params['schema'] ?? $this->connection->_schema;

        if (!$table) {
            return;
        }

        $this->tableQuoted = $this->connection->quoteIdentifier($table);        
        $this->schemaQuoted = $this->connection->quoteIdentifier($schema);

        $this->write("\n-- Table: \"{$schema}\".\"{$table}\"\n\n");

        if (empty($options['data_only'])) {
            // Use existing logic from TableActions/Postgres driver but adapted
            // Use writer-style method instead of getting SQL back
            $this->dumpTableStructure($table, $options);

            $this->dumpAutovacuumSettings($table, $schema);
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

    private const INSERT_COPY = 1;
    private const INSERT_MULTI = 2;
    private const INSERT_SINGLE = 3;

    protected function dumpData($table, $schema, $options)
    {
        $this->write("\n-- Data for table \"{$schema}\".\"{$table}\"\n");

        switch ($options['insert_format'] ?? 'copy') {
            default:
            case 'copy':
                $insertFormat = self::INSERT_COPY;
                break;
            case 'single':
                $insertFormat = self::INSERT_SINGLE;
                break;
            case 'multi':
                $insertFormat = self::INSERT_MULTI;
                break;
        }
        //$oids = !empty($options['oids']);

        try {
            // Build SQL query for table export
            $sql = "SELECT * FROM {$this->schemaQuoted}.{$this->tableQuoted}";

            // Create cursor reader with automatic chunk size calculation
            $reader = new CursorReader(
                $this->connection,
                $sql,
                null, // Auto-calculate chunk size
                $table,
                $schema
            );

            // Open cursor (begins transaction)
            $reader->open();

            // Prepare for SQL formatting
            $tableName = "{$this->schemaQuoted}.{$this->tableQuoted}";
            $fields = null;
            $totalRows = 0;
            $isFirstRow = true;
            $columnNames = null;
            $escapeModes = null;
            $insertBegin = null;
            $rowsInBatch = 0;
            $batchSize = 1000;

            // Stream rows without accumulation using eachRow()
            $reader->eachRow(function($row, $rowNumber, $fieldMetadata) use (
                $insertFormat,
                $tableName,
                &$fields,
                &$columnNames,
                &$escapeModes,
                &$isFirstRow,
                &$insertBegin,
                &$rowsInBatch,
                $batchSize
            ) {
                // Initialize on first row
                if ($fields === null) {
                    $fields = $fieldMetadata;
                    $columnNames = array_map(function($field) {
                        return $this->connection->escapeString(
                            $field['name']
                        );
                    }, $fields);
                    $escapeModes = $this->determineEscapeModes($fields);
                }

                // Write headers on first row
                if ($isFirstRow) {
                    if ($insertFormat === self::INSERT_COPY) {
                        $line = "COPY {$tableName} (" . implode(', ', $columnNames) . ") FROM stdin;\n";
                        $this->write($line);
                    } else {
                        $insertBegin = "INSERT INTO {$tableName} (" . implode(', ', $columnNames) . ") VALUES";
                        if ($insertFormat === self::INSERT_MULTI) {
                            $this->write("$insertBegin\n");
                        }
                    }
                    $isFirstRow = false;
                }

                // Write row data
                if ($insertFormat === self::INSERT_COPY) {
                    $this->writeCopyRow($row, $escapeModes);
                } elseif ($insertFormat === self::INSERT_MULTI) {
                    // Break into batches
                    if ($rowsInBatch >= $batchSize) {
                        $this->write(";\n\n$insertBegin\n");
                        $rowsInBatch = 0;
                    } elseif ($rowsInBatch > 0) {
                        $this->write(",\n");
                    }
                    $this->writeInsertValues($row, $escapeModes);
                    $rowsInBatch++;
                } else {
                    // Single-row INSERT
                    $this->write($insertBegin . " ");
                    $this->writeInsertValues($row, $escapeModes);
                    $this->write(";\n");
                }
            });

            // Close cursor (commits transaction)
            $reader->close();

            // Write terminators
            if ($fields !== null) {
                if ($insertFormat === self::INSERT_COPY) {
                    $this->write("\\.\n\n");
                } elseif ($insertFormat === self::INSERT_MULTI) {
                    $this->write(";\n\n");
                }
            }

        } catch (\Exception $e) {
            error_log('Error dumping table data: ' . $e->getMessage());
            $this->write("-- Error exporting data: " . $e->getMessage() . "\n");
        }
    }

    /**
     * Determine escape modes for each field based on type
     * 
     * @param array $fields Field metadata
     * @return array Escape modes (0=none, 1=string, 2=bytea)
     */
    protected function determineEscapeModes($fields)
    {
        $escapeModes = [];
        
        foreach ($fields as $i => $field) {
            $type = strtolower($field['type'] ?? '');

            // Numeric types - no escaping
            if (in_array($type, [
                'int2', 'int4', 'int8', 'integer', 'bigint', 'smallint',
                'float4', 'float8', 'real', 'double precision',
                'numeric', 'decimal'
            ])) {
                $escapeModes[$i] = 0;
            }
            // Boolean - no escaping
            elseif (in_array($type, ['bool', 'boolean'])) {
                $escapeModes[$i] = 0;
            }
            // Bytea - special escaping
            elseif ($type === 'bytea') {
                $escapeModes[$i] = 2;
            }
            // Everything else - string escaping
            else {
                $escapeModes[$i] = 1;
            }
        }

        return $escapeModes;
    }

    /**
     * Write a row in COPY format
     * 
     * @param array $row Numeric array of values
     * @param array $escapeModes Escape modes for each column
     */
    protected function writeCopyRow($row, $escapeModes)
    {
        $line = '';
        $first = true;

        foreach ($row as $i => $v) {
            if (!$first) {
                $line .= "\t";
            }
            $first = false;

            if ($v === null) {
                $line .= '\\N';
            } else {
                if ($escapeModes[$i] === 2) {
                    // Bytea - octal escaping for COPY
                    $line .= bytea_to_octal($v);
                } else {
                    // COPY escaping: backslash and special chars
                    $v = addcslashes($v, "\0\\\n\r\t");
                    $line .= $v;
                }
            }
        }

        $this->write($line . "\n");
    }

    /**
     * Write INSERT VALUES clause
     * 
     * @param array $row Numeric array of values
     * @param array $escapeModes Escape modes for each column
     */
    protected function writeInsertValues($row, $escapeModes)
    {
        $values = "(";
        $first = true;

        foreach ($row as $i => $v) {
            if (!$first) {
                $values .= ",";
            }
            $first = false;

            if ($v === null) {
                $values .= "NULL";
            } elseif ($escapeModes[$i] === 1) {
                // String escaping
                $values .= $this->connection->conn->qstr($v);
            } elseif ($escapeModes[$i] === 2) {
                // Bytea escaping
                $values .= "'\\x" . bin2hex($v) . "'";
            } else {
                // No escaping (numeric/boolean)
                $values .= $v;
            }
        }

        $values .= ")";
        $this->write($values);
    }

    /**
     * Write table definition prefix (columns, constraints, comments, privileges).
     * Returns true on success, false on failure or missing table.
     */
    protected function dumpTableStructure($table, $options)
    {
        $tableActions = new TableActions($this->connection);
        $t = $tableActions->getTable($table);
        if (!is_object($t) || $t->recordCount() != 1) {
            $this->connection->rollbackTransaction();
            return false;
        }

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
        $this->writeDrop('TABLE', "{$this->schemaQuoted}.{$this->tableQuoted}", $options);
        $this->write("CREATE TABLE {$this->schemaQuoted}.{$this->tableQuoted} (\n");

        // columns
        $col_comments_sql = '';
        $first_attr = true;
        while (!$atts->EOF) {
            if ($first_attr) {
                $first_attr = false;
            } else {
                $this->write(",\n");
            }
            $name = $this->connection->quoteIdentifier($atts->fields['attname']);
            $this->write("    {$name}");
            if (
                $this->connection->phpBool($atts->fields['attisserial']) &&
                ($atts->fields['type'] == 'integer' || $atts->fields['type'] == 'bigint')
            ) {
                $this->write(($atts->fields['type'] == 'integer') ? " SERIAL" : " BIGSERIAL");
            } else {
                $type = $this->connection->formatType(
                    $atts->fields['type'],
                    $atts->fields['atttypmod']
                );
                $this->write(" " . $type);
                if ($this->connection->phpBool($atts->fields['attnotnull'])) {
                    $this->write(" NOT NULL");
                }
                if ($atts->fields['adsrc'] !== null) {
                    $this->write(" DEFAULT {$atts->fields['adsrc']}");
                }
            }

            if ($atts->fields['comment'] !== null) {
                $this->connection->clean($atts->fields['comment']);
                $col_comments_sql .= "COMMENT ON COLUMN {$this->schemaQuoted}.{$this->tableQuoted}.{$this->connection->quoteIdentifier($atts->fields['attname'])} IS '{$atts->fields['comment']}';\n";
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
                $fieldQuoted = $this->connection->quoteIdentifier($atts->fields['attname']);
                $this->write("ALTER TABLE ONLY {$this->schemaQuoted}.{$this->tableQuoted} ALTER COLUMN {$fieldQuoted} SET STATISTICS {$atts->fields['attstattarget']};\n");
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
                $fieldQuoted = $this->connection->quoteIdentifier($atts->fields['attname']);
                $this->write("ALTER TABLE ONLY {$this->schemaQuoted}.{$this->tableQuoted} ALTER COLUMN {$fieldQuoted} SET STORAGE {$storage};\n");
            }

            $atts->moveNext();
        }

        // table comment
        if ($t->fields['relcomment'] !== null) {
            $this->connection->clean($t->fields['relcomment']);
            $this->write("\n-- Comment\n\n");
            $this->write("COMMENT ON TABLE {$this->schemaQuoted}.{$this->tableQuoted} IS '{$t->fields['relcomment']}';\n");
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
            $this->write("REVOKE ALL ON TABLE {$this->schemaQuoted}.{$this->tableQuoted} FROM PUBLIC;\n");
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

}
