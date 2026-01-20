<?php

namespace PhpPgAdmin\Database\Dump;

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

        // 2. Functions
        if ($includeSchemaObjects) {
            $this->dumpFunctions($schema, $options);
        }

        // 3. Aggregates
        if ($includeSchemaObjects) {
            $this->dumpAggregates($schema, $options);
        }

        // 4. Operators
        if ($includeSchemaObjects) {
            $this->dumpOperators($schema, $options);
        }

        // 5. Sequences
        $this->dumpSequences($schema, $options);

        // 6. Tables
        $this->dumpTables($schema, $options);

        // 7. Views
        $this->dumpViews($schema, $options);

        // 8. Privileges
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
        $this->dumpRelkindObjects($schema, $options, 'v', 'view');
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
}
