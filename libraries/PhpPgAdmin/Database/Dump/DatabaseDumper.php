<?php

namespace PhpPgAdmin\Database\Dump;

use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Database\Actions\SchemaActions;
use PhpPgAdmin\Database\Connector;

/**
 * Orchestrator dumper for a PostgreSQL database.
 */
class DatabaseDumper extends ExportDumper
{
    public function dump($subject, array $params, array $options = [])
    {
        $database = $params['database'] ?? $this->connection->conn->database;
        if (!$database) {
            return;
        }

        $c_database = $database;
        $this->connection->clean($c_database);

        $this->writeHeader("Database: \"{$c_database}\"");

        // Emit global preliminaries (ON_ERROR_STOP) unless suppressed
        if (empty($options['suppress_preliminaries'])) {
            $this->write("\\set ON_ERROR_STOP on\n\n");
        }

        // Database settings
        // Optionally add creation and/or connect markers when orchestrated by ServerDumper
        if (!empty($options['add_create_database'])) {
            $this->write("-- Database creation\n");
            if (!empty($options['clean'])) {
                $this->write("DROP DATABASE IF EXISTS \"" . addslashes($c_database) . "\" CASCADE;\n");
            }
            $this->write("CREATE DATABASE \"" . addslashes($c_database) . "\";\n");
        }

        $this->writeConnectHeader($database);

        // Save current database and reconnect to target database
        $originalDatabase = $this->connection->conn->database;
        $serverInfo = AppContainer::getMisc()->getServerInfo();

        if ($database != $originalDatabase) {
            $this->connection->conn->close();
            // Reconnect to the target database
            $this->connection->conn->connect(
                Connector::getHostPortString(
                    $serverInfo['host'] ?? null,
                    $serverInfo['port'] ?? null,
                    $serverInfo['sslmode'] ?? null
                ),
                $serverInfo['username'] ?? '',
                $serverInfo['password'] ?? '',
                $database,
            );
        }

        // Begin transaction for data consistency (only if not structure-only)
        if (empty($options['structure_only'])) {
            $this->connection->beginDump();
        }

        // Get list of selected schemas (if any)
        $hasSelection = isset($options['objects']);
        $selectedSchemas = $options['objects'] ?? [];
        $selectedSchemas = array_combine($selectedSchemas, $selectedSchemas);
        unset($options['objects']);

        // Iterate through schemas
        $schemaActions = new SchemaActions($this->connection);
        $schemas = $schemaActions->getSchemas();

        $dumper = $this->createSubDumper('schema');
        while ($schemas && !$schemas->EOF) {
            $schemaName = $schemas->fields['nspname'];
            if (!$hasSelection || isset($selectedSchemas[$schemaName])) {
                $dumper->dump('schema', ['schema' => $schemaName], $options);
            }
            $schemas->moveNext();
        }

        $this->writePrivileges($database, 'database');

        // End transaction for this database
        if (empty($options['structure_only'])) {
            $this->connection->endDump();
        }

        if ($database != $originalDatabase) {
            // Reconnect to original database
            $this->connection->conn->close();
            $this->connection->conn->connect(
                Connector::getHostPortString(
                    $serverInfo['host'] ?? null,
                    $serverInfo['port'] ?? null,
                    $serverInfo['sslmode'] ?? null
                ),
                $serverInfo['username'] ?? '',
                $serverInfo['password'] ?? '',
                $originalDatabase
            );
        }

        $this->writeConnectFooter();
        $this->writeFooter();
    }
}
