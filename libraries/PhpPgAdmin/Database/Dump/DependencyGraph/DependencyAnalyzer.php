<?php

namespace PhpPgAdmin\Database\Dump\DependencyGraph;

use PhpPgAdmin\Database\Postgres;
use PhpPgAdmin\Database\Dump\ExportDumper;

/**
 * Analyzes PostgreSQL catalog to extract dependencies between database objects.
 * 
 * Queries pg_depend, pg_proc, pg_type, pg_class to build a comprehensive
 * dependency graph for functions, tables, and domains.
 */
class DependencyAnalyzer
{
    /**
     * @var Postgres Database connection
     */
    private $connection;

    /**
     * @var array Schemas in scope for dependency analysis
     */
    private $schemasInScope;

    /**
     * @var array Cache of type OID to typrelid mapping
     */
    private $typeCache = [];

    /**
     * Create analyzer instance.
     *
     * @param Postgres $connection Database connection
     * @param array $schemasInScope Array of schema names to include
     */
    public function __construct(Postgres $connection, array $schemasInScope)
    {
        $this->connection = $connection;
        $this->schemasInScope = $schemasInScope;
    }

    /**
     * Build complete dependency graph for objects in scope.
     *
     * @return DependencyGraph Populated dependency graph
     */
    public function buildGraph()
    {
        $graph = new DependencyGraph();

        // Load all objects into graph as nodes
        $this->loadFunctions($graph);
        $this->loadTables($graph);
        $this->loadDomains($graph);
        $this->loadAggregates($graph);

        // Build type cache for efficient lookups
        $this->buildTypeCache();

        // Add dependency edges
        $this->addFunctionToFunctionDependencies($graph);
        $this->addFunctionToTableDependencies($graph);
        $this->addTableToFunctionDependencies($graph);
        $this->addTableToDomainDependencies($graph);
        $this->addDomainToFunctionDependencies($graph);
        $this->addAggregateToFunctionDependencies($graph);

        return $graph;
    }

    /**
     * Load all functions in scope as graph nodes.
     *
     * @param DependencyGraph $graph Graph to populate
     */
    private function loadFunctions(DependencyGraph $graph)
    {
        $schemaList = $this->escapeSchemaList();

        // Use prokind for PostgreSQL 11+, proisagg for older versions
        if ($this->connection->major_version >= 11) {
            $funcFilter = "p.prokind = 'f'";
        } else {
            $funcFilter = "NOT p.proisagg";
        }

        $sql = "SELECT p.oid, p.proname, n.nspname
                FROM pg_catalog.pg_proc p
                JOIN pg_catalog.pg_namespace n ON n.oid = p.pronamespace
                WHERE n.nspname IN ($schemaList)
                AND $funcFilter
                ORDER BY p.proname";

        $result = $this->connection->selectSet($sql);

        while ($result && !$result->EOF) {
            $node = new ObjectNode(
                $result->fields['oid'],
                'function',
                $result->fields['proname'],
                $result->fields['nspname']
            );
            $graph->addNode($node);
            $result->moveNext();
        }
    }

    /**
     * Load all tables in scope as graph nodes.
     *
     * @param DependencyGraph $graph Graph to populate
     */
    private function loadTables(DependencyGraph $graph)
    {
        $schemaList = $this->escapeSchemaList();

        $sql = "SELECT c.oid, c.relname, n.nspname
                FROM pg_catalog.pg_class c
                JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
                WHERE n.nspname IN ($schemaList)
                AND c.relkind = 'r'
                ORDER BY c.relname";

        $result = $this->connection->selectSet($sql);

        while ($result && !$result->EOF) {
            $node = new ObjectNode(
                $result->fields['oid'],
                'table',
                $result->fields['relname'],
                $result->fields['nspname']
            );
            $graph->addNode($node);
            $result->moveNext();
        }
    }

    /**
     * Load all domains in scope as graph nodes.
     *
     * @param DependencyGraph $graph Graph to populate
     */
    private function loadDomains(DependencyGraph $graph)
    {
        $schemaList = $this->escapeSchemaList();

        $sql = "SELECT t.oid, t.typname, n.nspname
                FROM pg_catalog.pg_type t
                JOIN pg_catalog.pg_namespace n ON n.oid = t.typnamespace
                WHERE n.nspname IN ($schemaList)
                AND t.typtype = 'd'
                ORDER BY t.typname";

        $result = $this->connection->selectSet($sql);

        while ($result && !$result->EOF) {
            $node = new ObjectNode(
                $result->fields['oid'],
                'domain',
                $result->fields['typname'],
                $result->fields['nspname']
            );
            $graph->addNode($node);
            $result->moveNext();
        }
    }

    /**
     * Load all aggregates in scope as graph nodes.
     *
     * @param DependencyGraph $graph Graph to populate
     */
    private function loadAggregates(DependencyGraph $graph)
    {
        $schemaList = $this->escapeSchemaList();

        // Use prokind for PostgreSQL 11+, proisagg for older versions
        if ($this->connection->major_version >= 11) {
            $aggFilter = "p.prokind = 'a'";
        } else {
            $aggFilter = "p.proisagg";
        }

        $sql = "SELECT p.oid, p.proname, n.nspname
                FROM pg_catalog.pg_proc p
                JOIN pg_catalog.pg_namespace n ON n.oid = p.pronamespace
                WHERE n.nspname IN ($schemaList)
                AND $aggFilter
                ORDER BY p.proname";

        $result = $this->connection->selectSet($sql);

        while ($result && !$result->EOF) {
            $node = new ObjectNode(
                $result->fields['oid'],
                'aggregate',
                $result->fields['proname'],
                $result->fields['nspname']
            );
            $graph->addNode($node);
            $result->moveNext();
        }
    }

    /**
     * Build cache mapping type OIDs to their backing table OIDs.
     * Handles array types by resolving through typelem.
     */
    private function buildTypeCache()
    {
        $sql = "SELECT t.oid, t.typrelid, t.typelem, t.typtype
                FROM pg_catalog.pg_type t
                WHERE t.typrelid != 0 OR t.typelem != 0";

        $result = $this->connection->selectSet($sql);

        while ($result && !$result->EOF) {
            $this->typeCache[$result->fields['oid']] = [
                'typrelid' => $result->fields['typrelid'],
                'typelem' => $result->fields['typelem'],
                'typtype' => $result->fields['typtype'],
            ];
            $result->moveNext();
        }
    }

    /**
     * Resolve a type OID to its backing table OID (if any).
     * Recursively resolves array types.
     *
     * @param string $typeOid Type OID to resolve
     * @return string|null Table OID or null if not a composite type
     */
    private function resolveTypeToTable($typeOid)
    {
        if (!isset($this->typeCache[$typeOid])) {
            return null;
        }

        $typeInfo = $this->typeCache[$typeOid];

        // If it's an array type, resolve the element type
        if ($typeInfo['typelem'] != 0) {
            return $this->resolveTypeToTable($typeInfo['typelem']);
        }

        // If it has a backing table, return it
        if ($typeInfo['typrelid'] != 0) {
            return $typeInfo['typrelid'];
        }

        return null;
    }

    /**
     * Add function → function dependencies from pg_depend.
     *
     * @param DependencyGraph $graph Graph to populate
     */
    private function addFunctionToFunctionDependencies(DependencyGraph $graph)
    {
        $schemaList = $this->escapeSchemaList();

        // Use version-appropriate filter
        if ($this->connection->major_version >= 11) {
            $funcFilter1 = "p1.prokind = 'f'";
            $funcFilter2 = "p2.prokind = 'f'";
        } else {
            $funcFilter1 = "NOT p1.proisagg";
            $funcFilter2 = "NOT p2.proisagg";
        }

        $sql = "SELECT DISTINCT d.objid AS func_oid, d.refobjid AS depends_on_oid
                FROM pg_catalog.pg_depend d
                JOIN pg_catalog.pg_proc p1 ON p1.oid = d.objid
                JOIN pg_catalog.pg_proc p2 ON p2.oid = d.refobjid
                JOIN pg_catalog.pg_namespace n1 ON n1.oid = p1.pronamespace
                JOIN pg_catalog.pg_namespace n2 ON n2.oid = p2.pronamespace
                WHERE d.classid = 'pg_proc'::regclass
                AND d.refclassid = 'pg_proc'::regclass
                AND d.deptype = 'n'
                AND $funcFilter1
                AND $funcFilter2
                AND n1.nspname IN ($schemaList)
                AND n2.nspname IN ($schemaList)";

        $result = $this->connection->selectSet($sql);

        while ($result && !$result->EOF) {
            // Edge direction: depends_on → func (dependency comes first)
            $graph->addEdge(
                $result->fields['depends_on_oid'],
                $result->fields['func_oid']
            );
            $result->moveNext();
        }
    }

    /**
     * Add function → table dependencies (composite type usage).
     *
     * @param DependencyGraph $graph Graph to populate
     */
    private function addFunctionToTableDependencies(DependencyGraph $graph)
    {
        $schemaList = $this->escapeSchemaList();

        // Use version-appropriate filter
        if ($this->connection->major_version >= 11) {
            $funcFilter = "p.prokind = 'f'";
        } else {
            $funcFilter = "NOT p.proisagg";
        }

        // Get functions with their return types and argument types
        $sql = "SELECT 
                    p.oid AS func_oid,
                    p.prorettype,
                    p.proargtypes,
                    p.proallargtypes
                FROM pg_catalog.pg_proc p
                JOIN pg_catalog.pg_namespace n ON n.oid = p.pronamespace
                WHERE n.nspname IN ($schemaList)
                AND $funcFilter";

        $result = $this->connection->selectSet($sql);

        while ($result && !$result->EOF) {
            $funcOid = $result->fields['func_oid'];
            $typeOids = [];

            // Add return type
            $typeOids[] = $result->fields['prorettype'];

            // Add argument types from proargtypes (IN arguments)
            if ($result->fields['proargtypes']) {
                $argtypes = explode(' ', trim($result->fields['proargtypes']));
                $typeOids = array_merge($typeOids, $argtypes);
            }

            // Add all argument types from proallargtypes (includes OUT, INOUT)
            if ($result->fields['proallargtypes']) {
                $allArgtypes = trim($result->fields['proallargtypes'], '{}');
                if ($allArgtypes) {
                    $alltypes = explode(',', $allArgtypes);
                    $typeOids = array_merge($typeOids, $alltypes);
                }
            }

            // Resolve each type to see if it's backed by a table
            foreach ($typeOids as $typeOid) {
                $typeOid = trim($typeOid);
                if (empty($typeOid) || $typeOid === '0') {
                    continue;
                }

                $tableOid = $this->resolveTypeToTable($typeOid);
                if ($tableOid) {
                    // Function depends on this table's composite type
                    // Edge direction: table → function (table must come first)
                    $tableNode = $graph->getNode($tableOid);
                    if ($tableNode && $tableNode->type === 'table') {
                        $graph->addEdge($tableOid, $funcOid);
                    }
                }
            }

            $result->moveNext();
        }
    }

    /**
     * Add table → function dependencies (from defaults and check constraints).
     *
     * @param DependencyGraph $graph Graph to populate
     */
    private function addTableToFunctionDependencies(DependencyGraph $graph)
    {
        $schemaList = $this->escapeSchemaList();

        // Get default expressions and generated column expressions
        $sql = "SELECT 
                    a.attrelid AS table_oid,
                    pg_catalog.pg_get_expr(ad.adbin, ad.adrelid, true) AS expr
                FROM pg_catalog.pg_attribute a
                JOIN pg_catalog.pg_class c ON c.oid = a.attrelid
                JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
                LEFT JOIN pg_catalog.pg_attrdef ad ON ad.adrelid = a.attrelid AND ad.adnum = a.attnum
                WHERE n.nspname IN ($schemaList)
                AND c.relkind = 'r'
                AND a.attnum > 0
                AND NOT a.attisdropped
                AND ad.adbin IS NOT NULL";

        $result = $this->connection->selectSet($sql);

        while ($result && !$result->EOF) {
            $tableOid = $result->fields['table_oid'];
            $expr = $result->fields['expr'];

            $this->extractFunctionDependencies($graph, $tableOid, $expr);
            $result->moveNext();
        }

        // Get check constraints
        $sql = "SELECT 
                    con.conrelid AS table_oid,
                    pg_catalog.pg_get_constraintdef(con.oid, true) AS expr
                FROM pg_catalog.pg_constraint con
                JOIN pg_catalog.pg_class c ON c.oid = con.conrelid
                JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
                WHERE n.nspname IN ($schemaList)
                AND c.relkind = 'r'
                AND con.contype = 'c'";

        $result = $this->connection->selectSet($sql);

        while ($result && !$result->EOF) {
            $tableOid = $result->fields['table_oid'];
            $expr = $result->fields['expr'];

            $this->extractFunctionDependencies($graph, $tableOid, $expr);
            $result->moveNext();
        }
    }

    /**     * Add dependencies for aggregates on their supporting functions.
     * Aggregates depend on:
     * - aggtransfn (SFUNC)
     * - aggfinalfn (FINALFUNC)
     * - aggcombinefn (COMBINEFUNC)
     * - aggserialfn (SERIALFUNC)
     * - aggdeserialfn (DESERIALFUNC)
     * - aggmtransfn (MSFUNC)
     * - aggminvtransfn (MINVFUNC)
     * - aggmfinalfn (MFINALFUNC)
     *
     * @param DependencyGraph $graph Graph to populate
     */
    private function addAggregateToFunctionDependencies(DependencyGraph $graph)
    {
        $schemaList = $this->escapeSchemaList();

        // Query pg_aggregate to get all function dependencies
        $sql = "SELECT agg.aggfnoid,
                       agg.aggtransfn, agg.aggfinalfn, agg.aggcombinefn,
                       agg.aggserialfn, agg.aggdeserialfn,
                       agg.aggmtransfn, agg.aggminvtransfn, agg.aggmfinalfn,
                       n.nspname
                FROM pg_catalog.pg_aggregate agg
                JOIN pg_catalog.pg_proc p ON p.oid = agg.aggfnoid
                JOIN pg_catalog.pg_namespace n ON n.oid = p.pronamespace
                WHERE n.nspname IN ($schemaList)";

        $result = $this->connection->selectSet($sql);

        while ($result && !$result->EOF) {
            $aggOid = $result->fields['aggfnoid'];

            // All the function fields that an aggregate can depend on
            $functionFields = [
                'aggtransfn',
                'aggfinalfn',
                'aggcombinefn',
                'aggserialfn',
                'aggdeserialfn',
                'aggmtransfn',
                'aggminvtransfn',
                'aggmfinalfn'
            ];

            foreach ($functionFields as $field) {
                $funcOid = $result->fields[$field];
                if ($funcOid && $funcOid !== '0' && $funcOid !== '-') {
                    // Function must come before aggregate
                    $graph->addEdge($funcOid, $aggOid);
                }
            }

            $result->moveNext();
        }
    }

    /**     * Add table → domain dependencies (tables using domains in columns).
     *
     * @param DependencyGraph $graph Graph to populate
     */
    private function addTableToDomainDependencies(DependencyGraph $graph)
    {
        $schemaList = $this->escapeSchemaList();

        // Find all table columns that use domain types
        $sql = "SELECT DISTINCT 
                    c.oid AS table_oid,
                    t.oid AS domain_oid
                FROM pg_catalog.pg_class c
                JOIN pg_catalog.pg_namespace nc ON nc.oid = c.relnamespace
                JOIN pg_catalog.pg_attribute a ON a.attrelid = c.oid
                JOIN pg_catalog.pg_type t ON t.oid = a.atttypid
                JOIN pg_catalog.pg_namespace nt ON nt.oid = t.typnamespace
                WHERE nc.nspname IN ($schemaList)
                AND c.relkind = 'r'
                AND t.typtype = 'd'
                AND a.attnum > 0
                AND NOT a.attisdropped";

        $result = $this->connection->selectSet($sql);

        while ($result && !$result->EOF) {
            $tableOid = $result->fields['table_oid'];
            $domainOid = $result->fields['domain_oid'];

            // Edge: domain → table (domain must be created before table)
            $graph->addEdge($domainOid, $tableOid);

            $result->moveNext();
        }
    }

    /**
     * Add domain → function dependencies (from check constraints).
     *
     * @param DependencyGraph $graph Graph to populate
     */
    private function addDomainToFunctionDependencies(DependencyGraph $graph)
    {
        $schemaList = $this->escapeSchemaList();

        $sql = "SELECT 
                    t.oid AS domain_oid,
                    pg_catalog.pg_get_constraintdef(con.oid, true) AS expr
                FROM pg_catalog.pg_type t
                JOIN pg_catalog.pg_namespace n ON n.oid = t.typnamespace
                JOIN pg_catalog.pg_constraint con ON con.contypid = t.oid
                WHERE n.nspname IN ($schemaList)
                AND t.typtype = 'd'
                AND con.contype = 'c'";

        $result = $this->connection->selectSet($sql);

        while ($result && !$result->EOF) {
            $domainOid = $result->fields['domain_oid'];
            $expr = $result->fields['expr'];

            $this->extractFunctionDependencies($graph, $domainOid, $expr);
            $result->moveNext();
        }
    }

    /**
     * Extract function dependencies from an expression.
     * Looks for function call patterns and resolves them to OIDs.
     *
     * @param DependencyGraph $graph Graph to populate
     * @param string $sourceOid OID of source object (table/domain)
     * @param string $expr Expression to analyze
     */
    private function extractFunctionDependencies(DependencyGraph $graph, $sourceOid, $expr)
    {
        // Pattern matches: function_name( or schema.function_name(
        // Captures schema-qualified and unqualified function calls
        if (preg_match_all('/(?:(\w+)\.)?(\w+)\s*\(/i', $expr, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $schema = $match[1] ?: null;
                $funcName = $match[2];

                // Skip common built-in functions
                if (ExportDumper::isBuiltinFunction($funcName)) {
                    continue;
                }

                $funcOid = $this->resolveFunctionName($funcName, $schema);
                if ($funcOid) {
                    // Edge direction: function → source (function must come first)
                    // e.g., for table default: function → table
                    $graph->addEdge($funcOid, $sourceOid);
                }
            }
        }
    }

    /**
     * Resolve function name (optionally schema-qualified) to OID.
     *
     * @param string $funcName Function name
     * @param string|null $schema Optional schema name
     * @return string|null Function OID or null if not found
     */
    private function resolveFunctionName($funcName, $schema = null)
    {
        $schemaList = $this->escapeSchemaList();
        $funcName = $this->connection->escapeString($funcName);

        if ($schema) {
            $schema = $this->connection->escapeString($schema);
            $sql = "SELECT p.oid
                    FROM pg_catalog.pg_proc p
                    JOIN pg_catalog.pg_namespace n ON n.oid = p.pronamespace
                    WHERE p.proname = '$funcName'
                    AND n.nspname = '$schema'
                    AND p.prokind = 'f'
                    LIMIT 1";
        } else {
            $sql = "SELECT p.oid
                    FROM pg_catalog.pg_proc p
                    JOIN pg_catalog.pg_namespace n ON n.oid = p.pronamespace
                    WHERE p.proname = '$funcName'
                    AND n.nspname IN ($schemaList)
                    AND p.prokind = 'f'
                    LIMIT 1";
        }

        $result = $this->connection->selectSet($sql);

        if ($result && !$result->EOF) {
            return $result->fields['oid'];
        }

        return null;
    }

    /**
     * Escape and format schema list for SQL IN clause.
     *
     * @return string Comma-separated escaped schema names
     */
    private function escapeSchemaList()
    {
        static $escaped = null;
        if ($escaped !== null) {
            return $escaped;
        }
        $sep = "";
        foreach ($this->schemasInScope as $schema) {
            $escaped .= $sep . "'";
            $escaped .= $this->connection->escapeString($schema);
            $escaped .= "'";
            $sep = ", ";
        }
        return $escaped;
    }
}
