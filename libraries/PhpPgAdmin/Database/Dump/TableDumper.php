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
    private $deferredConstraints = [];
    private $deferredIndexes = [];

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
            // Reset deferred constraints/indexes for this table
            $this->deferredConstraints = [];
            $this->deferredIndexes = [];

            // Use existing logic from TableActions/Postgres driver but adapted
            // Use writer-style method instead of getting SQL back
            $this->dumpTableStructure($table, $options);

            $this->dumpAutovacuumSettings($table, $schema);
        }

        if (empty($options['structure_only'])) {
            $this->dumpData($table, $schema, $options);
        }

        if (empty($options['data_only'])) {
            // Apply constraints and indexes AFTER data import for better performance
            $this->applyDeferredConstraints($options);
            $this->writeIndexes($table, $options);
            $this->deferTriggers($table, $schema, $options);
            $this->deferRules($table, $schema, $options);
        }

        // Register this table as dumped (for sequence ownership validation)
        if ($this->parentDumper && method_exists($this->parentDumper, 'registerDumpedTable')) {
            $this->parentDumper->registerDumpedTable($schema, $table);
        }
    }

    protected function dumpData($table, $schema, $options)
    {
        $this->write("\n-- Data for table \"{$schema}\".\"{$table}\"\n");

        try {
            // Build SQL query for table export
            $sql = "SELECT * FROM {$this->schemaQuoted}.{$this->tableQuoted}";

            // Create cursor reader with automatic chunk size calculation
            $reader = new CursorReader(
                $this->connection,
                $sql,
                null, // Auto-calculate chunk size
                $table,
                $schema,
                'r' // relation kind
            );

            // Open cursor (begins transaction)
            $reader->open();

            // Send data to SQL formatter for output
            $sqlFormatter = new SqlFormatter();
            $sqlFormatter->setOutputStream($this->outputStream);
            $metadata = [
                'table' => "{$this->schemaQuoted}.{$this->tableQuoted}",
                'batch_size' => $options['batch_size'] ?? 1000,
                'insert_format' => $options['insert_format'] ?? 'copy',
            ];
            $reader->processRows($sqlFormatter, $metadata);

            // Close cursor (commits transaction)
            $reader->close();

        } catch (\Exception $e) {
            error_log('Error dumping table data: ' . $e->getMessage());
            $this->write("-- Error dumping data: " . $e->getMessage() . "\n");
        }
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
            return false;
        }

        $atts = $tableActions->getTableAttributes($table);
        if (!is_object($atts)) {
            return false;
        }

        $constraintActions = new ConstraintActions($this->connection);
        $cons = $constraintActions->getConstraints($table);
        if (!is_object($cons)) {
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
            $this->write("    {$name} {$atts->fields['type']}");

            // Check for generated column first (PostgreSQL 12+)
            if (isset($atts->fields['attgenerated']) && $atts->fields['attgenerated'] === 's') {
                // Generated stored column - write GENERATED ALWAYS AS
                if ($atts->fields['adsrc'] !== null) {
                    $this->write(" GENERATED ALWAYS AS ({$atts->fields['adsrc']}) STORED");
                }
            } else {
                // Regular column - handle NOT NULL and DEFAULT
                if ($this->connection->phpBool($atts->fields['attnotnull'])) {
                    $this->write(" NOT NULL");
                }

                if ($atts->fields['adsrc'] !== null) {
                    // Case 3: Other defaults (not sequence-related)
                    $this->write(" DEFAULT {$atts->fields['adsrc']}");
                }
            }

            if ($atts->fields['comment'] !== null) {
                $comment = $this->connection->escapeString($atts->fields['comment']);
                $col_comments_sql .= "COMMENT ON COLUMN {$this->schemaQuoted}.{$this->tableQuoted}.{$this->connection->quoteIdentifier($atts->fields['attname'])} IS '{$comment}';\n";
            }

            $atts->moveNext();
        }

        // Store constraints for deferred application (except NOT NULL)
        while (!$cons->EOF) {
            if ($cons->fields['contype'] == 'n') {
                // Skip NOT NULL constraints as they are dumped with the column definition
                $cons->moveNext();
                continue;
            }

            $name = $this->connection->quoteIdentifier($cons->fields['conname']);
            $src = $cons->fields['consrc'];
            if (empty($src)) {
                // Build constraint source from type and columns
                $columns = trim($cons->fields['columns'], '{}');
                switch ($cons->fields['contype']) {
                    case 'p':
                        $src = "PRIMARY KEY ($columns)";
                        break;
                    case 'u':
                        $src = "UNIQUE ($columns)";
                        break;
                    case 'f':
                        // Foreign key - should not happen as consrc is always populated
                        $src = $cons->fields['consrc'];
                        break;
                    case 'c':
                        // Check constraint - should not happen as consrc is always populated
                        $src = $cons->fields['consrc'];
                        break;
                    default:
                        $cons->moveNext();
                        continue 2;
                }
            }

            // Store constraint for later application
            $this->deferredConstraints[] = [
                'name' => $name,
                'definition' => $src,
                'type' => $cons->fields['contype']
            ];

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
            $fieldQuoted = $this->connection->quoteIdentifier($atts->fields['attname']);

            // Set sequence ownership if applicable
            if (!empty($atts->fields['sequence_name'])) {
                if ($first) {
                    $this->write("\n");
                    $first = false;
                }
                $sequenceQuoted = $this->connection->quoteIdentifier($atts->fields['sequence_name']);
                $this->write("\nALTER SEQUENCE {$this->schemaQuoted}.{$sequenceQuoted} OWNED BY {$this->schemaQuoted}.{$this->tableQuoted}.{$fieldQuoted};\n");
            }

            // Set statistics target
            $stat = $atts->fields['attstattarget'];
            if ($stat !== null && $stat !== '' && is_numeric($stat) && $stat >= 0) {
                if ($first) {
                    $this->write("\n");
                    $first = false;
                }
                $this->write("ALTER TABLE ONLY {$this->schemaQuoted}.{$this->tableQuoted} ALTER COLUMN {$fieldQuoted} SET STATISTICS {$stat};\n");
            }

            // Set storage parameter
            if ($atts->fields['attstorage'] != $atts->fields['typstorage']) {
                if ($first) {
                    $this->write("\n");
                    $first = false;
                }
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
                        return false;
                }
                $this->write("ALTER TABLE ONLY {$this->schemaQuoted}.{$this->tableQuoted} ALTER COLUMN {$fieldQuoted} SET STORAGE {$storage};\n");
            }

            $atts->moveNext();
        }

        // table comment
        if ($t->fields['relcomment'] !== null) {
            $comment = $this->connection->escapeString($t->fields['relcomment']);
            $this->write("\n-- Comment\n\n");
            $this->write("COMMENT ON TABLE {$this->schemaQuoted}.{$this->tableQuoted} IS '{$comment}';\n");
        }

        // column comments
        if ($col_comments_sql != '') {
            $this->write($col_comments_sql);
        }

        // privileges
        $this->writePrivileges(
            $table,
            'table',
            $t->fields['relowner'],
            $t->fields['relacl']
        );

        $this->write("\n");

        return true;
    }

    /**
     * Apply deferred constraints after data import.
     */
    private function applyDeferredConstraints($options)
    {
        if (empty($this->deferredConstraints)) {
            return;
        }

        $this->write("\n-- Constraints (applied after data import)\n\n");

        foreach ($this->deferredConstraints as $constraint) {
            $this->write("ALTER TABLE {$this->schemaQuoted}.{$this->tableQuoted} ");
            $this->write("ADD CONSTRAINT {$constraint['name']} {$constraint['definition']}");
            $this->write(";\n");
        }
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
            if ($indexes->fields['indisprimary']) {
                // Skip primary key index (created with constraint)
                $indexes->moveNext();
                continue;
            }

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
     * Defer triggers for the table (to be applied after functions are created).
     */
    private function deferTriggers($table, $schema, $options)
    {
        $triggerActions = new TriggerActions($this->connection);
        $triggers = $triggerActions->getTriggers($table);

        if (!is_object($triggers) || $triggers->EOF) {
            return;
        }

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

            // Add to parent SchemaDumper's deferred collection
            if ($this->parentDumper && method_exists($this->parentDumper, 'addDeferredTrigger')) {
                $this->parentDumper->addDeferredTrigger($schema, $table, $def);
            }

            $triggers->moveNext();
        }
    }

    /**
     * Defer rules for the table (to be applied after functions are created).
     */
    private function deferRules($table, $schema, $options)
    {
        $ruleActions = new RuleActions($this->connection);
        $rules = $ruleActions->getRules($table);

        if (!is_object($rules) || $rules->EOF) {
            return;
        }

        while (!$rules->EOF) {
            $def = $rules->fields['definition'];
            $def = str_replace('CREATE RULE', 'CREATE OR REPLACE RULE', $def);

            // Add to parent SchemaDumper's deferred collection
            if ($this->parentDumper && method_exists($this->parentDumper, 'addDeferredRule')) {
                $this->parentDumper->addDeferredRule($schema, $table, $def);
            }

            $rules->moveNext();
        }
    }


    /**
     * Dump autovacuum settings for the table.
     */
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
