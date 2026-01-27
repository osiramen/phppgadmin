<?php

namespace PhpPgAdmin\Database\Dump;

use PhpPgAdmin\Database\Actions\ViewActions;
use PhpPgAdmin\Database\Actions\SchemaActions;

/**
 * Orchestrator dumper for a PostgreSQL schema.
 */
class SchemaDumper extends ExportDumper
{
    private $selectedObjects = [];
    private $hasObjectSelection = false;

    private $schemaEscaped = '';
    private $schemaQuoted = '';

    // Deferred statement collections
    private $deferredTriggers = [];
    private $deferredRules = [];
    private $deferredSequenceOwnerships = [];
    private $deferredMaterializedViewRefreshes = [];
    private $dumpedTables = [];

    public function dump($subject, array $params, array $options = [])
    {
        $schema = $params['schema'] ?? $this->connection->_schema;
        if (!$schema) {
            return;
        }

        $this->schemaEscaped = $this->connection->escapeString($schema);
        $this->schemaQuoted = $this->connection->quoteIdentifier($schema);

        // Get list of selected objects (tables/views/sequences)
        $this->selectedObjects = $options['objects'] ?? [];
        $this->hasObjectSelection = isset($options['objects']);
        $this->selectedObjects = array_combine($this->selectedObjects, $this->selectedObjects);
        $includeSchemaObjects = $options['include_schema_objects'] ?? true;

        $schemaActions = new SchemaActions($this->connection);
        $rs = $schemaActions->getSchemaByName($schema);
        if (!$rs || $rs->EOF) {
            return;
        }

        // Save and set schema context for Actions that depend on it
        $oldSchema = $this->connection->_schema;
        $this->connection->_schema = $schema;

        // Write standard dump header for schema exports
        $this->writeHeader("Schema: $this->schemaQuoted");
        $this->writeConnectHeader();

        // Optional schema creation for super users
        if (!empty($options['add_create_schema'])) {
            $this->writeDrop('SCHEMA', $this->schemaQuoted, $options);
            $this->write("CREATE SCHEMA " . $this->getIfNotExists($options) . $this->schemaQuoted . ";\n");
        }

        // 1. Domains and Types
        if ($includeSchemaObjects) {
            $this->dumpDomainsAndTypes($schema, $options);
        }

        // 2. Sequences (without ownership - deferred)
        $this->dumpSequences($schema, $options);

        // 3. Functions (moved before tables to resolve dependencies)
        // With check_function_bodies = false, functions can reference tables that don't exist yet
        if ($includeSchemaObjects) {
            $this->dumpFunctions($schema, $options);
        }

        // 4. Aggregates
        if ($includeSchemaObjects) {
            $this->dumpAggregates($schema, $options);
        }

        // 5. Operators
        if ($includeSchemaObjects) {
            $this->dumpOperators($schema, $options);
        }

        // 6. Tables (structure with defaults and check constraints, but NO triggers/rules)
        $this->dumpTables($schema, $options);

        // 7. Views (regular views, triggers/rules deferred)
        $this->dumpViews($schema, $options);

        // 8. Materialized Views (WITH NO DATA, refresh deferred)
        if ($includeSchemaObjects) {
            $this->dumpMaterializedViews($schema, $options);
        }

        // 9. Apply deferred objects after all structure is created
        $this->applyDeferredMaterializedViewRefreshes($options);
        $this->applyDeferredViews($schema, $options);
        $this->applyDeferredRules($options);
        $this->applyDeferredTriggers($options);
        $this->applyDeferredSequenceOwnerships($options);

        // 10. Privileges
        $this->writePrivileges(
            $schema,
            'schema',
            $rs->fields['ownername'],
            $rs->fields['nspacl']
        );

        // Restore original schema context
        $this->connection->_schema = $oldSchema;

        $this->writeConnectFooter();
        $this->writeFooter();
    }

    protected function dumpDomainsAndTypes($schema, $options)
    {
        // 1. Get types
        $types = $this->connection->selectSet(
            "SELECT t.oid, t.typname, t.typtype, t.typnamespace, t.typbasetype
                FROM pg_type t
                JOIN pg_namespace n ON n.oid = t.typnamespace
                WHERE n.nspname = '{$this->schemaEscaped}'
                AND t.typtype IN ('b','c','d','e')
                AND t.typelem = 0  -- Exclude array types
                AND (t.typrelid = 0 OR EXISTS (
                    SELECT 1 FROM pg_class c 
                    WHERE c.oid = t.typrelid 
                    AND c.relkind = 'c'
                ))  -- Include types tied to composite tables
                ORDER BY t.oid"
        );

        // 2. Get dependencies
        $deps = $this->connection->selectSet(
            "SELECT d.objid AS type_oid, d.refobjid AS depends_on_oid
                FROM pg_depend d
                JOIN pg_type t ON t.oid = d.objid
                WHERE d.classid = 'pg_type'::regclass
                AND d.refclassid = 'pg_type'::regclass
                AND d.deptype IN ('n','i')"
        );

        // 3. Build arrays
        $typeList = [];
        while ($types && !$types->EOF) {
            $typeList[$types->fields['oid']] = $types->fields;
            $types->moveNext();
        }

        $depList = [];
        while ($deps && !$deps->EOF) {
            $depList[] = $deps->fields;
            $deps->moveNext();
        }

        // 4. Topologically sort
        $sortedOids = $this->sortTypesTopologically($typeList, $depList);

        // 5. Dumper
        $domainDumper = $this->createSubDumper('domain');
        $typeDumper = $this->createSubDumper('type');

        $this->write("\n-- Domains in schema $this->schemaQuoted\n");

        foreach ($sortedOids as $oid) {
            $t = $typeList[$oid];

            if ($t['typtype'] === 'd') {
                $domainDumper->dump('domain', [
                    'schema' => $schema,
                    'domain' => $t['typname'],
                ], $options);
            }
        }

        $this->write("\n-- Types in schema $this->schemaQuoted\n");

        foreach ($sortedOids as $oid) {
            $t = $typeList[$oid];

            if ($t['typtype'] !== 'd') {
                $typeDumper->dump('type', [
                    'schema' => $schema,
                    'type' => $t['typname'],
                ], $options);
            }
        }
    }

    protected function sortTypesTopologically(array $types, array $deps)
    {
        // Build graph
        $graph = [];
        $incoming = [];

        foreach ($types as $t) {
            $oid = $t['oid'];
            $graph[$oid] = [];
            $incoming[$oid] = 0;
        }

        foreach ($deps as $d) {
            $from = $d['type_oid'];
            $to = $d['depends_on_oid'];

            if (isset($graph[$from]) && isset($graph[$to])) {
                $graph[$from][] = $to;
                $incoming[$to]++;
            }
        }

        // Nodes without incoming edges
        $queue = [];
        foreach ($incoming as $oid => $count) {
            if ($count === 0) {
                $queue[] = $oid;
            }
        }

        $sorted = [];

        while (!empty($queue)) {
            $oid = array_shift($queue);
            $sorted[] = $oid;

            foreach ($graph[$oid] as $dep) {
                $incoming[$dep]--;
                if ($incoming[$dep] === 0) {
                    $queue[] = $dep;
                }
            }
        }

        return $sorted;
    }

    protected function sortViewsTopologically(array $views, array $deps)
    {
        // Build graph
        $graph = [];
        $incoming = [];

        foreach ($views as $oid => $name) {
            $graph[$oid] = [];
            $incoming[$oid] = 0;
        }

        foreach ($deps as $d) {
            $from = $d['view_oid'];
            $to = $d['depends_on_oid'];

            if (isset($graph[$from]) && isset($graph[$to])) {
                $graph[$from][] = $to;
                $incoming[$to]++;
            }
        }

        // Nodes without incoming edges
        $queue = [];
        foreach ($incoming as $oid => $count) {
            if ($count === 0) {
                $queue[] = $oid;
            }
        }

        $sorted = [];

        while (!empty($queue)) {
            $oid = array_shift($queue);
            $sorted[] = $oid;

            foreach ($graph[$oid] as $dep) {
                $incoming[$dep]--;
                if ($incoming[$dep] === 0) {
                    $queue[] = $dep;
                }
            }
        }

        // Check for circular dependencies
        if (count($sorted) < count($views)) {
            $this->write("\n-- Warning: Circular view dependencies detected, manual intervention may be required\n");
            $this->write("-- The following views could not be topologically sorted:\n");

            // Add remaining views in alphabetical order
            $remaining = [];
            foreach ($views as $oid => $name) {
                if (!in_array($oid, $sorted)) {
                    $remaining[$oid] = $name;
                    $this->write("--   - {$name}\n");
                }
            }

            // Sort remaining by name and add to sorted list
            asort($remaining);
            foreach ($remaining as $oid => $name) {
                $sorted[] = $oid;
            }

            $this->write("\n");
        }

        return $sorted;
    }

    protected function dumpRelkindObjects($schema, $options, $relkind, $typeName)
    {
        $sql = "SELECT c.relname
                FROM pg_catalog.pg_class c
                JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
                WHERE c.relkind = '{$relkind}'
                AND n.nspname = '{$this->schemaEscaped}'
                ORDER BY c.relname";

        $result = $this->connection->selectSet($sql);
        $dumper = $this->createSubDumper($typeName);

        while ($result && !$result->EOF) {
            $name = $result->fields['relname'];

            if (!$this->hasObjectSelection || isset($this->selectedObjects[$name])) {
                $dumper->dump($typeName, [
                    $typeName => $name,
                    'schema' => $schema,
                ], $options);
            }

            $result->moveNext();
        }
    }

    protected function dumpSequences($schema, $options)
    {
        $this->write("\n-- Sequences in schema $this->schemaQuoted\n");
        $this->dumpRelkindObjects($schema, $options, 'S', 'sequence');
    }

    protected function dumpTables($schema, $options)
    {
        $this->write("\n-- Tables in schema $this->schemaQuoted\n");
        $this->dumpRelkindObjects($schema, $options, 'r', 'table');
    }

    protected function dumpViews($schema, $options)
    {
        $this->write("\n-- Views in schema $this->schemaQuoted\n");

        // Get all views
        $sql = "SELECT c.oid, c.relname
                FROM pg_catalog.pg_class c
                JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
                WHERE c.relkind = 'v'
                AND n.nspname = '{$this->schemaEscaped}'
                ORDER BY c.relname";

        $views = $this->connection->selectSet($sql);

        if (!$views || $views->EOF) {
            return;
        }

        // Build view list
        $viewList = [];
        while (!$views->EOF) {
            $viewList[$views->fields['oid']] = $views->fields['relname'];
            $views->moveNext();
        }

        // Get dependencies between views
        $deps = $this->connection->selectSet(
            "SELECT DISTINCT d.objid AS view_oid, d.refobjid AS depends_on_oid
                FROM pg_depend d
                JOIN pg_class c1 ON c1.oid = d.objid
                JOIN pg_class c2 ON c2.oid = d.refobjid
                WHERE d.classid = 'pg_class'::regclass
                AND d.refclassid = 'pg_class'::regclass
                AND c1.relkind = 'v'
                AND c2.relkind = 'v'
                AND d.deptype IN ('n','i')"
        );

        $depList = [];
        while ($deps && !$deps->EOF) {
            $depList[] = $deps->fields;
            $deps->moveNext();
        }

        // Topologically sort views
        $sortedOids = $this->sortViewsTopologically($viewList, $depList);

        // Dump views in sorted order
        $dumper = $this->createSubDumper('view');

        foreach ($sortedOids as $oid) {
            $viewName = $viewList[$oid];

            if (!$this->hasObjectSelection || isset($this->selectedObjects[$viewName])) {
                $dumper->dump('view', [
                    'view' => $viewName,
                    'schema' => $schema,
                ], $options);
            }
        }
    }

    protected function dumpMaterializedViews($schema, $options)
    {
        $this->write("\n-- Materialized Views in schema $this->schemaQuoted\n");

        $viewActions = new ViewActions($this->connection);
        $result = $viewActions->getViews(false, true);

        if (!$result || $result->EOF) {
            return;
        }

        $dumper = $this->createSubDumper('materialized_view');

        while (!$result->EOF) {
            $viewName = $result->fields['relname'];

            if (!$this->hasObjectSelection || isset($this->selectedObjects[$viewName])) {
                $dumper->dump('materialized_view', [
                    'view' => $viewName,
                    'schema' => $schema,
                ], $options);
            }

            $result->moveNext();
        }
    }

    protected function applyDeferredMaterializedViewRefreshes($options)
    {
        if (empty($this->deferredMaterializedViewRefreshes)) {
            return;
        }

        $this->write("\n");
        $this->write("--\n");
        $this->write("-- Refresh Materialized Views\n");
        $this->write("--\n\n");

        foreach ($this->deferredMaterializedViewRefreshes as $mv) {
            $schemaQuoted = $this->connection->quoteIdentifier($mv['schema']);
            $viewQuoted = $this->connection->quoteIdentifier($mv['view']);

            $this->write("-- Refreshing materialized view {$mv['schema']}.{$mv['view']}\n");
            $this->write("REFRESH MATERIALIZED VIEW $schemaQuoted.$viewQuoted;\n\n");
        }
    }

    protected function applyDeferredViews($schema, $options)
    {
        // Views are created during dumpViews() with topological sorting
        // This is a placeholder for future view-specific deferred operations
    }

    protected function applyDeferredRules($options)
    {
        if (empty($this->deferredRules)) {
            return;
        }

        $this->write("\n");
        $this->write("--\n");
        $this->write("-- Deferred Rules\n");
        $this->write("--\n\n");

        foreach ($this->deferredRules as $rule) {
            $this->write("-- Rule on {$rule['schema']}.{$rule['relation']}\n");
            $this->write($rule['definition']);
            $this->write(";\n\n");
        }
    }

    protected function applyDeferredTriggers($options)
    {
        if (empty($this->deferredTriggers)) {
            return;
        }

        $this->write("\n");
        $this->write("--\n");
        $this->write("-- Deferred Triggers\n");
        $this->write("--\n\n");

        foreach ($this->deferredTriggers as $trigger) {
            $this->write("-- Trigger on {$trigger['schema']}.{$trigger['relation']}\n");
            $this->write($trigger['definition']);
            $this->write(";\n\n");
        }
    }

    protected function applyDeferredSequenceOwnerships($options)
    {
        if (empty($this->deferredSequenceOwnerships)) {
            return;
        }

        $this->write("\n");
        $this->write("--\n");
        $this->write("-- Sequence Ownerships\n");
        $this->write("--\n\n");

        foreach ($this->deferredSequenceOwnerships as $ownership) {
            $tableKey = "{$ownership['schema']}.{$ownership['table']}";

            if (!$this->isTableDumped($ownership['schema'], $ownership['table'])) {
                $this->write("-- Skipping ownership: table $tableKey not found\n");
                continue;
            }

            $schemaQuoted = $this->connection->quoteIdentifier($ownership['schema']);
            $sequenceQuoted = $this->connection->quoteIdentifier($ownership['sequence']);
            $tableQuoted = $this->connection->quoteIdentifier($ownership['table']);
            $columnQuoted = $this->connection->quoteIdentifier($ownership['column']);

            $this->write("ALTER SEQUENCE $schemaQuoted.$sequenceQuoted OWNED BY $schemaQuoted.$tableQuoted.$columnQuoted;\n");
        }
    }

    protected function dumpFunctions($schema, $options)
    {
        $this->write("\n-- Functions in schema $this->schemaQuoted\n");

        $sql = "SELECT p.oid AS prooid
                FROM pg_catalog.pg_proc p
                LEFT JOIN pg_catalog.pg_namespace n ON n.oid = p.pronamespace
                WHERE n.nspname = '{$this->schemaEscaped}'
                AND p.prokind = 'f'
                ORDER BY p.proname";

        $functions = $this->connection->selectSet($sql);
        $dumper = $this->createSubDumper('function');

        while ($functions && !$functions->EOF) {
            $dumper->dump('function', [
                'function_oid' => $functions->fields['prooid'],
                'schema' => $schema,
            ], $options);
            $functions->moveNext();
        }
    }

    protected function dumpAggregates($schema, $options)
    {
        $this->write("\n-- Aggregates in schema $this->schemaQuoted\n");

        $sql = "SELECT p.proname,
                    pg_catalog.pg_get_function_arguments(p.oid) AS proargtypes
                FROM pg_catalog.pg_proc p
                LEFT JOIN pg_catalog.pg_namespace n ON n.oid = p.pronamespace
                WHERE n.nspname = '{$this->schemaEscaped}'
                AND p.prokind = 'a'
                ORDER BY p.proname";

        $aggregates = $this->connection->selectSet($sql);
        $aggDumper = $this->createSubDumper('aggregate');

        while ($aggregates && !$aggregates->EOF) {
            $aggDumper->dump('aggregate', [
                'aggregate' => $aggregates->fields['proname'],
                'basetype' => $aggregates->fields['proargtypes'],
                'schema' => $schema
            ], $options);
            $aggregates->moveNext();
        }

    }

    protected function dumpOperators($schema, $options)
    {
        $this->write("\n-- Operators in schema $this->schemaQuoted\n");

        $sql = "SELECT o.oid
                FROM pg_catalog.pg_operator o
                LEFT JOIN pg_catalog.pg_namespace n ON n.oid = o.oprnamespace
                WHERE n.nspname = '{$this->schemaEscaped}'
                ORDER BY o.oid";

        $operators = $this->connection->selectSet($sql);
        $opDumper = $this->createSubDumper('operator');

        while ($operators && !$operators->EOF) {
            $opDumper->dump('operator', [
                'operator_oid' => $operators->fields['oid'],
                'schema' => $schema
            ], $options);
            $operators->moveNext();
        }
    }

    /**
     * Add a trigger to be applied after functions are created
     */
    public function addDeferredTrigger($schema, $relation, $triggerDefinition)
    {
        $this->deferredTriggers[] = [
            'schema' => $schema,
            'relation' => $relation,
            'definition' => $triggerDefinition,
        ];
    }

    /**
     * Add a rule to be applied after functions are created
     */
    public function addDeferredRule($schema, $relation, $ruleDefinition)
    {
        $this->deferredRules[] = [
            'schema' => $schema,
            'relation' => $relation,
            'definition' => $ruleDefinition,
        ];
    }

    /**
     * Add a sequence ownership statement to be applied at the end
     */
    public function addDeferredSequenceOwnership($schema, $sequence, $table, $column)
    {
        $this->deferredSequenceOwnerships[] = [
            'schema' => $schema,
            'sequence' => $sequence,
            'table' => $table,
            'column' => $column,
        ];
    }

    /**
     * Add a materialized view refresh statement
     */
    public function addDeferredMaterializedViewRefresh($schema, $viewName)
    {
        $this->deferredMaterializedViewRefreshes[] = [
            'schema' => $schema,
            'view' => $viewName,
        ];
    }

    /**
     * Track that a table was dumped (for ownership validation)
     */
    public function registerDumpedTable($schema, $table)
    {
        $this->dumpedTables["$schema.$table"] = true;
    }

    /**
     * Check if a table was dumped
     */
    public function isTableDumped($schema, $table)
    {
        return isset($this->dumpedTables["$schema.$table"]);
    }
}
