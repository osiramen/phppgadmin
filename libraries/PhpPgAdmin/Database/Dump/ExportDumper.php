<?php

namespace PhpPgAdmin\Database\Dump;

use PhpPgAdmin\Core\AbstractContext;
use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Database\Actions\AclActions;
use PhpPgAdmin\Database\Postgres;

/**
 * Base class for all dumpers providing shared utilities.
 */
abstract class ExportDumper extends AbstractContext
{
    /**
     * @var Postgres
     */
    protected $connection;

    /**
     * @var resource|null
     */
    protected $outputStream = null;

    public function __construct(Postgres $connection = null)
    {
        $this->connection = $connection ?? AppContainer::getPostgres();
    }

    /**
     * Sets the output stream for the dump.
     * 
     * @param resource $stream
     */
    public function setOutputStream($stream)
    {
        $this->outputStream = $stream;
    }

    /**
     * Helper to create a sub-dumper with the same output stream
     * 
     * @param string $subject
     * @param Postgres $connection
     * @return ExportDumper
     */
    protected function createSubDumper($subject, $connection = null)
    {
        $dumper = DumpFactory::create($subject, $connection ?? $this->connection);
        if ($this->outputStream) {
            $dumper->setOutputStream($this->outputStream);
        }
        return $dumper;
    }

    /**
     * Writes a string to the output stream or echoes it.
     * 
     * @param string $string
     */
    protected function write($string)
    {
        if ($this->outputStream) {
            fwrite($this->outputStream, $string);
        } else {
            echo $string;
        }
    }

    private $headerLevel = 0;

    /**
     * Generates a header for the dump.
     */
    protected function writeHeader($title)
    {
        if ($this->headerLevel++ > 0) {
            return;
        }
        $name = AppContainer::getAppName();
        $version = AppContainer::getAppVersion();
        $this->write("--\n");
        $this->write("-- $name $version PostgreSQL dump\n");
        $this->write("-- Subject: {$title}\n");
        $this->write("-- Date: " . date('Y-m-d H:i:s') . "\n");
        $this->write("--\n\n");
    }

    protected function writeFooter()
    {
        if ($this->headerLevel-- > 1) {
            return;
        }
        $this->write("-- Dump completed on " . date('Y-m-d H:i:s') . "\n");
    }

    protected function writeConnectHeader(string $database = null)
    {
        if (!isset($database)) {
            $database = $this->connection->conn->database;
        }
        if ($this->headerLevel++ > 1) {
            return;
        }
        $this->write("\\connect " . $this->connection->quoteIdentifier($database) . "\n");
        $this->write("\\encoding UTF8\n");
        $this->write("SET client_encoding = 'UTF8';\n");
        // pg_dump session settings for reliable restores
        $this->write("SET statement_timeout = 0;\n");
        $this->write("SET lock_timeout = 0;\n");
        $this->write("SET idle_in_transaction_session_timeout = 0;\n");
        $this->write("SET transaction_timeout = 0;\n");
        $this->write("SET standard_conforming_strings = on;\n");
        // Remove search_path to avoid issues with functions that set it internally
        $this->write("SELECT pg_catalog.set_config('search_path', '', false);\n");
        $this->write("SET check_function_bodies = false;\n");
        $this->write("SET xmloption = content;\n");
        $this->write("SET client_min_messages = warning;\n");
        $this->write("SET row_security = off;\n");
        // Set session_replication_role to replica for the whole DB restore
        $this->write("SET session_replication_role = 'replica';\n\n");
    }

    protected function writeConnectFooter()
    {
        if ($this->headerLevel-- > 2) {
            return;
        }
        // After dumping this database, reset session_replication_role to origin
        $this->write("\nSET session_replication_role = 'origin';\n\n");
    }

    /**
     * Generates GRANT/REVOKE SQL for an object.
     * 
     * @param string $objectName
     * @param string $objectType (table, view, sequence, database, function, language, schema, tablespace)
     * @param string|null $schema
     */
    protected function writePrivileges($objectName, $objectType, $schema = null)
    {
        $aclActions = new AclActions($this->connection);
        $privileges = $aclActions->getPrivileges($objectName, $objectType);

        // Handle error codes returned as integers (-1 for unsupported type, -2 for invalid entity)
        if (!is_array($privileges) || empty($privileges)) {
            return;
        }

        $this->write("\n-- Privileges for {$objectType} {$objectName}\n");

        // Reconstruct GRANTS from parsed ACLs
        // This logic is adapted from TableActions::getPrivilegesSql but generalized
        foreach ($privileges as $priv) {
            $grantee = ($priv[1] == '') ? 'PUBLIC' : "\"{$priv[1]}\"";
            $privs = implode(', ', $priv[2]);

            if ($privs == 'ALL PRIVILEGES') {
                $this->write("GRANT ALL ON {$objectType} \"{$objectName}\" TO {$grantee};\n");
            } else {
                $this->write("GRANT {$privs} ON {$objectType} \"{$objectName}\" TO {$grantee}");
                if (!empty($priv[4])) {
                    $this->write(" WITH GRANT OPTION");
                }
                $this->write(";\n");
            }
        }
    }

    /**
     * Helper to generate DROP statement if requested.
     */
    protected function writeDrop($type, $name, $options)
    {
        if (!empty($options['drop_objects'])) {
            $this->write("DROP {$type} IF EXISTS {$name} CASCADE;\n");
        }
    }

    /**
     * Check if comments should be included in the dump.
     */
    protected function shouldIncludeComments($options)
    {
        // Default to true (include comments) unless explicitly disabled
        return !isset($options['include_comments']) || $options['include_comments'];
    }

    /**
     * Helper to generate IF NOT EXISTS clause.
     */
    protected function getIfNotExists($options)
    {
        return (!empty($options['if_not_exists'])) ? "IF NOT EXISTS " : "";
    }

    /**
     * Get table/view data as ADORecordSet.
     * Default implementation returns null.
     * Subclasses should override if they support data export.
     * 
     * @param array $params Parameters with 'table' or 'view' key
     * @return mixed ADORecordSet or null if not supported
     */
    public function getTableData(array $params)
    {
        // Default: not supported by this dumper type
        throw new \Exception("getTableData method not implemented in " . get_class($this));
    }

    /**
     * Performs the traditional dump - outputs complete SQL structure + data.
     * Used for full database/schema/table exports with complete control.
     * Output is written to output stream (if set) or echoed directly.
     * 
     * @param string $subject The subject to dump (e.g., 'table', 'schema', 'database')
     * @param array $params Parameters for the dump (e.g., ['table' => 'my_table', 'schema' => 'public'])
     * @param array $options Options for the dump (e.g., ['clean' => true, 'if_not_exists' => true, 'data_only' => false])
     * @return void
     */
    public function dump($subject, array $params, array $options = [])
    {
        // Default: not supported by this dumper type
        throw new \Exception("Dump method not implemented in " . get_class($this));
    }
}