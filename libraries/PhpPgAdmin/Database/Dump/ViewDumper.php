<?php

namespace PhpPgAdmin\Database\Dump;

use PhpPgAdmin\Database\Actions\ViewActions;

/**
 * Dumper for PostgreSQL views.
 */
class ViewDumper extends ExportDumper
{
    private $schemaQuoted = null;
    private $viewQuoted = null;

    private $schemaEscaped = null;
    private $viewEscaped = null;

    public function dump($subject, array $params, array $options = [])
    {
        $view = $params['view'] ?? null;
        $schema = $params['schema'] ?? $this->connection->_schema;

        if (!$view) {
            return;
        }

        $this->schemaQuoted = $this->connection->quoteIdentifier($schema);
        $this->viewQuoted = $this->connection->quoteIdentifier($view);
        $this->schemaEscaped = $this->connection->escapeString($schema);
        $this->viewEscaped = $this->connection->escapeString($view);

        $viewActions = new ViewActions($this->connection);
        // Schema is already set in connection->_schema
        $rs = $viewActions->getView($view);

        if (!$rs || $rs->EOF) {
            return;
        }

        $this->write("\n-- View: {$this->schemaQuoted}.{$this->viewQuoted}\n");

        $this->writeDrop('VIEW', "{$this->schemaQuoted}.{$this->viewQuoted}", $options);

        if (!empty($options['if_not_exists'])) {
            $this->write("CREATE VIEW IF NOT EXISTS {$this->schemaQuoted}.{$this->viewQuoted} AS\n{$rs->fields['vwdefinition']};\n");
        } else {
            $this->write("CREATE VIEW {$this->schemaQuoted}.{$this->viewQuoted} AS\n{$rs->fields['vwdefinition']};\n");
        }

        // Add comment if present and requested
        if ($this->shouldIncludeComments($options)) {
            if (!empty($rs->fields['relcomment'])) {
                $comment = $this->connection->escapeString($rs->fields['relcomment']);
                $this->write("COMMENT ON VIEW {$this->schemaQuoted}.{$this->viewQuoted} IS '$comment';\\n");
            }
        }

        $this->dumpRules($view, $schema, $options);
        $this->dumpTriggers($view, $schema, $options);

        $this->writePrivileges($view, 'view', $rs->fields['relowner']);
    }

    protected function dumpRules($view, $schema, $options)
    {
        $sql =
            "SELECT definition
            FROM pg_rules
            WHERE schemaname = '{$this->schemaEscaped}'
                AND tablename = '{$this->viewEscaped}'";
        $rs = $this->connection->selectSet($sql);
        if (!$rs || $rs->EOF) {
            return;
        }
        $this->write("\n-- Rules on view {$this->schemaQuoted}.{$this->viewQuoted}\n");
        while (!$rs->EOF) {
            $this->write($rs->fields['definition'] . "\n");
            $rs->moveNext();
        }
    }

    protected function dumpTriggers($view, $schema, $options)
    {
        // pg_get_triggerdef(oid) is available since 9.0
        $sql =
            "SELECT pg_get_triggerdef(oid) as definition
            FROM pg_trigger
            WHERE tgrelid = (
                SELECT oid FROM pg_class WHERE relname = '$this->viewEscaped'
                    AND relnamespace = (
                        SELECT oid FROM pg_namespace
                        WHERE nspname = '$this->schemaEscaped'
                    )
                )";
        $rs = $this->connection->selectSet($sql);
        if (!$rs) {
            return;
        }
        if ($rs->EOF) {
            return;
        }
        $this->write("\n-- Triggers on view \"{$this->schemaQuoted}.{$this->viewQuoted}\"\n");
        while (!$rs->EOF) {
            $this->write($rs->fields['definition'] . ";\n");
            $rs->moveNext();
        }
    }

}
