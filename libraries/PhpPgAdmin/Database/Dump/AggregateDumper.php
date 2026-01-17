<?php

namespace PhpPgAdmin\Database\Dump;

use PhpPgAdmin\Database\Actions\AggregateActions;

/**
 * Dumper for PostgreSQL aggregates.
 */
class AggregateDumper extends ExportDumper
{
    public function dump($subject, array $params, array $options = [])
    {
        $name = $params['aggregate'] ?? null;
        $basetype = $params['basetype'] ?? null;
        $schema = $params['schema'] ?? $this->connection->_schema;

        if (!$name) {
            return;
        }

        $aggregateActions = new AggregateActions($this->connection);
        $rs = $aggregateActions->getAggregate($name, $basetype);

        if (!$rs || $rs->EOF) {
            return;
        }

        $schemaQuoted = $this->connection->quoteIdentifier($schema);
        $nameQuoted = $this->connection->quoteIdentifier($name);

        $this->write("\n-- Aggregate: {$schemaQuoted}.{$nameQuoted}\n");

        // DROP AGGREGATE needs the type
        $typeStr = ($rs->fields['proargtypes'] === null) ? '*' : $rs->fields['proargtypes'];
        $this->writeDrop('AGGREGATE', "{$schemaQuoted}.{$nameQuoted} ({$typeStr})", $options);

        $this->write("CREATE AGGREGATE {$schemaQuoted}.{$nameQuoted} (\n");
        $this->write("    BASETYPE = " . (($rs->fields['proargtypes'] === null) ? 'ANY' : $rs->fields['proargtypes']) . ",\n");

        // SFUNC (quote + qualify when needed)
        $sfuncQuoted = $this->connection->quoteIdentifier($rs->fields['aggtransfn']);
        if (!empty($rs->fields['sfuncnspname'])) {
            $sfuncQuoted = $this->connection->quoteIdentifier($rs->fields['sfuncnspname']) . '.' . $sfuncQuoted;
        }
        $this->write("    SFUNC = {$sfuncQuoted},\n");

        // STYPE comes from format_type(), do not quote
        $this->write("    STYPE = " . $rs->fields['aggstype']);

        if ($rs->fields['aggfinalfn'] !== null && $rs->fields['aggfinalfn'] !== '-') {
            $finalQuoted = $this->connection->quoteIdentifier($rs->fields['aggfinalfn']);
            if (!empty($rs->fields['finalfnnspname'])) {
                $finalQuoted = $this->connection->quoteIdentifier($rs->fields['finalfnnspname']) . '.' . $finalQuoted;
            }
            $this->write(",\n    FINALFUNC = " . $finalQuoted);
        }
        if ($rs->fields['agginitval'] !== null) {
            $this->write(",\n    INITCOND = '{$rs->fields['agginitval']}'");
        }

        // SORTOP: only write if operator name is present; qualify only when needed
        if (!empty($rs->fields['oprname'])) {
            $oprQuoted = $this->connection->quoteIdentifier($rs->fields['oprname']);
            if (!empty($rs->fields['oprnspname'])) {
                $oprQuoted = $this->connection->quoteIdentifier($rs->fields['oprnspname']) . '.' . $oprQuoted;
            }
            $this->write(",\n    SORTOP = {$oprQuoted}");
        }

        $this->write("\n);\n");

        if ($rs->fields['aggrcomment'] !== null && $this->shouldIncludeComments($options)) {
            $comment = $this->connection->escapeString($rs->fields['aggrcomment']);
            $this->write("\nCOMMENT ON AGGREGATE {$schemaQuoted}.{$nameQuoted} ($typeStr) IS '{$comment}';\n");
        }
    }
}
