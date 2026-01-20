<?php

namespace PhpPgAdmin\Database\Actions;

use PhpPgAdmin\Database\AbstractActions;

class SchemaActions extends AbstractActions
{
    /**
     * Return all schemas in the current database.
     * @param bool|null $showSystem whether to include system schemas; if null, use global setting
     */
    public function getSchemas(bool $showSystem = null)
    {
        $conf = $this->conf();

        if ($showSystem === null) {
            $showSystem = $conf['show_system'];
        }
        if (!$showSystem) {
            // Only user-defined schemas
            $where = "WHERE pn.nspname NOT LIKE 'pg_%'
                  AND pn.nspname <> 'information_schema'";
        } else {
            // All except temporary and toast schemas
            $where = "WHERE pn.nspname NOT LIKE 'pg_temp%'
                  AND pn.nspname NOT LIKE 'pg_toast%'";
        }

        $sql =
            "SELECT pn.nspname, pu.rolname AS nspowner, pn.oid,
                pg_catalog.obj_description(pn.oid, 'pg_namespace') AS nspcomment
            FROM pg_catalog.pg_namespace pn
            LEFT JOIN pg_catalog.pg_roles pu ON (pn.nspowner = pu.oid)
            {$where}
            ORDER BY nspname
        ";

        return $this->connection->selectSet($sql);
    }


    /**
     * Return all information relating to a schema.
     */
    public function getSchemaByName($schema)
    {
        $this->connection->clean($schema);
        $sql =
            "SELECT
                pn.oid,
                pn.nspname,
                pn.nspowner,
                pg_catalog.pg_get_userbyid(pn.nspowner) AS ownername,
                pn.nspacl,
                pg_catalog.obj_description(pn.oid, 'pg_namespace') AS nspcomment
            FROM pg_catalog.pg_namespace pn
            WHERE pn.nspname = '{$schema}'";
        return $this->connection->selectSet($sql);
    }

    /**
     * Sets the current working schema.
     */
    public function setSchema($schema)
    {
        $search_path = $this->getSearchPath();
        $schema_by_key = array_flip($search_path);
        if (!isset($schema_by_key[$schema])) {
            if ($this->connection->_schema != '') {
                // remove current schema from search path
                unset($schema_by_key[$this->connection->_schema]);
                $search_path = array_keys($schema_by_key);
            }
            $status = $this->setSearchPath($search_path);
            if ($status != 0) {
                return $status;
            }
        }
        $this->connection->_schema = $schema;
        return 0;
    }

    /**
     * Sets the current schema search path.
     */
    public function setSearchPath($paths)
    {
        if (!is_array($paths)) {
            return -1;
        } elseif (sizeof($paths) == 0) {
            return -2;
        } elseif (sizeof($paths) == 1 && $paths[0] == '') {
            $paths[0] = 'pg_catalog';
        }

        $temp = [];
        foreach ($paths as $schema) {
            if ($schema != '') {
                $temp[] = $schema;
            }
        }
        $this->connection->fieldArrayClean($temp);

        $sql = 'SET SEARCH_PATH TO "' . implode('","', $temp) . '"';

        return $this->connection->execute($sql);
    }

    /**
     * Creates a new schema.
     */
    public function createSchema($schemaname, $authorization = '', $comment = '')
    {
        $this->connection->fieldClean($schemaname);
        $this->connection->fieldClean($authorization);

        $sql = "CREATE SCHEMA \"{$schemaname}\"";
        if ($authorization != '') {
            $sql .= " AUTHORIZATION \"{$authorization}\"";
        }

        if ($comment != '') {
            $status = $this->connection->beginTransaction();
            if ($status != 0) {
                return -1;
            }
        }

        $status = $this->connection->execute($sql);
        if ($status != 0) {
            $this->connection->rollbackTransaction();
            return -1;
        }

        if ($comment != '') {
            $status = $this->connection->setComment('SCHEMA', $schemaname, '', $comment);
            if ($status != 0) {
                $this->connection->rollbackTransaction();
                return -1;
            }

            return $this->connection->endTransaction();
        }

        return 0;
    }

    /**
     * Updates a schema.
     */
    public function updateSchema($schemaname, $comment, $name, $owner)
    {
        $this->connection->fieldClean($schemaname);
        $this->connection->fieldClean($name);
        $this->connection->fieldClean($owner);

        $status = $this->connection->beginTransaction();
        if ($status != 0) {
            $this->connection->rollbackTransaction();
            return -1;
        }

        $status = $this->connection->setComment('SCHEMA', $schemaname, '', $comment);
        if ($status != 0) {
            $this->connection->rollbackTransaction();
            return -1;
        }

        $schema_rs = $this->getSchemaByName($schemaname);
        if ($schema_rs->fields['ownername'] != $owner) {
            $sql = "ALTER SCHEMA \"{$schemaname}\" OWNER TO \"{$owner}\"";
            $status = $this->connection->execute($sql);
            if ($status != 0) {
                $this->connection->rollbackTransaction();
                return -1;
            }
        }

        if ($name != $schemaname) {
            $sql = "ALTER SCHEMA \"{$schemaname}\" RENAME TO \"{$name}\"";
            $status = $this->connection->execute($sql);
            if ($status != 0) {
                $this->connection->rollbackTransaction();
                return -1;
            }
        }

        return $this->connection->endTransaction();
    }

    /**
     * Drops a schema.
     */
    public function dropSchema($schemaname, $cascade)
    {
        $this->connection->fieldClean($schemaname);

        $sql = "DROP SCHEMA \"{$schemaname}\"";
        if ($cascade) {
            $sql .= " CASCADE";
        }

        return $this->connection->execute($sql);
    }

    /**
     * Return the current schema search path.
     */
    public function getSearchPath()
    {
        $sql = 'SELECT current_schemas(false) AS search_path';

        return $this->connection->phpArray($this->connection->selectField($sql, 'search_path'));
    }

    public function getSchemaTablesAndColumns($schema)
    {
        $this->connection->clean($schema);
        $sql = <<<SQL
            SELECT 
                c.relname AS table_name,
                a.attname AS column_name,
                pg_catalog.format_type(a.atttypid, a.atttypmod) AS data_type,
                a.attnum AS ordinal_position
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            JOIN pg_attribute a ON a.attrelid = c.oid
            WHERE c.relkind IN ('r', 'p', 'v', 'm')
            AND n.nspname = '{$schema}'
            AND a.attnum > 0
            AND NOT a.attisdropped
            ORDER BY c.relname, a.attnum
        SQL;
        return $this->connection->selectSet($sql);
    }

    public function getSchemaForeignKeyRelations($schema)
    {
        $this->connection->clean($schema);
        $sql = <<<SQL
            SELECT
                src.relname AS source_table,
                src_col.attname AS source_column,
                tgt.relname AS target_table,
                tgt_col.attname AS target_column
            FROM pg_constraint con
            JOIN pg_class src ON src.oid = con.conrelid
            JOIN pg_namespace src_ns ON src_ns.oid = src.relnamespace
            JOIN pg_class tgt ON tgt.oid = con.confrelid
            JOIN pg_namespace tgt_ns ON tgt_ns.oid = tgt.relnamespace
            JOIN pg_attribute src_col 
                ON src_col.attrelid = src.oid 
                AND src_col.attnum = ANY(con.conkey)
            JOIN pg_attribute tgt_col 
                ON tgt_col.attrelid = tgt.oid 
                AND tgt_col.attnum = ANY(con.confkey)
            WHERE con.contype = 'f'
            AND src_ns.nspname = '{$schema}'
            AND tgt_ns.nspname = '{$schema}'
        SQL;
        return $this->connection->selectSet($sql);
    }
}
