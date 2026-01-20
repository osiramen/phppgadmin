<?php

namespace PhpPgAdmin\Database\Dump;

use PhpPgAdmin\Database\Actions\DomainActions;

/**
 * Dumper for PostgreSQL domains.
 */
class DomainDumper extends ExportDumper
{
    public function dump($subject, array $params, array $options = [])
    {
        $domainName = $params['domain'] ?? null;
        $schema = $params['schema'] ?? $this->connection->_schema;

        if (!$domainName) {
            return;
        }

        $domainActions = new DomainActions($this->connection);
        $rs = $domainActions->getDomain($domainName);

        if (!$rs || $rs->EOF) {
            return;
        }

        $schemaQuoted = $this->connection->quoteIdentifier($schema);
        $domainQuoted = $this->connection->quoteIdentifier($domainName);

        $this->write("\n-- Domain: $schemaQuoted.$domainQuoted\n");
        $this->writeDrop('DOMAIN', "$schemaQuoted.$domainQuoted", $options);

        $this->write("CREATE DOMAIN $schemaQuoted.$domainQuoted AS {$rs->fields['domtype']}");

        if (isset($rs->fields['domdef'])) {
            $this->write("\n    DEFAULT {$rs->fields['domdef']}");
        }

        $notNull = false;
        if ($this->connection->phpBool($rs->fields['domnotnull'])) {
            $this->write("\n    NOT NULL");
            $notNull = true;
        }

        // Constraints
        $this->dumpConstraints($rs->fields['oid'], $notNull);

        $this->write(";\n");

        if ($this->shouldIncludeComments($options) && !empty($rs->fields['domcomment'])) {
            $comment = $this->connection->escapeString($rs->fields['domcomment']);
            $this->write(
                "\nCOMMENT ON DOMAIN $schemaQuoted.$domainQuoted IS '{$comment}';\n"
            );
        }

        /*
        $this->writePrivileges(
            $domainName,
            'type',
            $rs->fields['domowner']
        );
        */
    }

    protected function dumpConstraints($domainOid, $notNull)
    {
        $sql = "SELECT conname, pg_catalog.pg_get_constraintdef(oid, true) AS consrc
                FROM pg_catalog.pg_constraint
                WHERE contypid = '{$domainOid}'::oid";

        $rs = $this->connection->selectSet($sql);
        if (!$rs) {
            return;
        }
        while (!$rs->EOF) {
            $src = $rs->fields['consrc'];
            // Skip NOT NULL constraint if already handled
            if ($notNull && stripos($src, 'NOT NULL') !== false) {
                $rs->moveNext();
                continue;
            }
            $conname = $this->connection->escapeIdentifier($rs->fields['conname']);
            $this->write("\n    CONSTRAINT $conname {$rs->fields['consrc']}");
            $rs->moveNext();
        }
    }
}
