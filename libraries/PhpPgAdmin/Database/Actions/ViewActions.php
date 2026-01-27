<?php

namespace PhpPgAdmin\Database\Actions;

use ADORecordSet_empty;



class ViewActions extends ActionsBase
{

    /**
     * Returns all details for a particular view.
     */
    public function getView($view)
    {
        $c_schema = $this->connection->_schema;
        $this->connection->clean($c_schema);
        $this->connection->clean($view);

        $sql =
            "SELECT c.relname, n.nspname, c.relkind,
                pg_catalog.pg_get_userbyid(c.relowner) AS relowner,
                pg_catalog.pg_get_viewdef(c.oid, true) AS vwdefinition,
                pg_catalog.obj_description(c.oid, 'pg_class') AS relcomment
            FROM pg_catalog.pg_class c
            LEFT JOIN pg_catalog.pg_namespace n ON (n.oid = c.relnamespace)
            WHERE (c.relname = '{$view}') AND n.nspname='{$c_schema}'";

        return $this->connection->selectSet($sql);
    }

    /**
     * Returns a list of views and/or materialized views in the current schema.
     *
     * @param bool $normal       get normal views
     * @param bool $materialized get materialized views
     */
    public function getViews($normal = true, $materialized = false)
    {
        $c_schema = $this->connection->_schema;
        $this->connection->clean($c_schema);

        if (!$normal && !$materialized) {
            return new ADORecordSet_empty();
        }
        if ($normal && !$materialized) {
            $relkind = "'v'";
        } elseif (!$normal && $materialized) {
            $relkind = "'m'";
        } else {
            $relkind = "'v','m'";
        }

        $sql =
            "SELECT c.relname,
                pg_catalog.pg_get_userbyid(c.relowner) AS relowner,
                pg_catalog.obj_description(c.oid, 'pg_class') AS relcomment,
                c.relkind
            FROM pg_catalog.pg_class c
            LEFT JOIN pg_catalog.pg_namespace n ON (n.oid = c.relnamespace)
            WHERE n.nspname = '{$c_schema}'
            AND c.relkind IN ($relkind)
            ORDER BY c.relname
        ";

        return $this->connection->selectSet($sql);
    }


    /**
     * Updates a view (OR REPLACE).
     */
    public function setView($viewname, $definition, $comment)
    {
        return $this->createView($viewname, $definition, true, $comment);
    }

    /**
     * Creates a new view.
     */
    public function createView($viewname, $definition, $replace, $comment)
    {
        $status = $this->connection->beginTransaction();
        if ($status != 0) {
            return -1;
        }

        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($viewname);

        $sql = "CREATE ";
        if ($replace) {
            $sql .= "OR REPLACE ";
        }
        $sql .= "VIEW \"{$f_schema}\".\"{$viewname}\" AS {$definition}";

        $status = $this->connection->execute($sql);
        if ($status) {
            $this->connection->rollbackTransaction();
            return -1;
        }

        if ($comment != '') {
            $status = $this->connection->setComment('VIEW', $viewname, '', $comment);
            if ($status) {
                $this->connection->rollbackTransaction();
                return -1;
            }
        }

        return $this->connection->endTransaction();
    }

    /**
     * Rename a view.
     */
    public function alterViewName($vwrs, $name)
    {
        if (!empty($name) && ($name != $vwrs->fields['relname'])) {
            $f_schema = $this->connection->_schema;
            $this->connection->fieldClean($f_schema);
            $sql = "ALTER VIEW \"{$f_schema}\".\"{$vwrs->fields['relname']}\" RENAME TO \"{$name}\"";
            $status = $this->connection->execute($sql);
            if ($status == 0) {
                $vwrs->fields['relname'] = $name;
            } else {
                return $status;
            }
        }
        return 0;
    }

    /**
     * Alter a view's owner.
     */
    public function alterViewOwner($vwrs, $owner = null)
    {
        if ((!empty($owner)) && ($vwrs->fields['relowner'] != $owner)) {
            $f_schema = $this->connection->_schema;
            $this->connection->fieldClean($f_schema);
            $sql = "ALTER TABLE \"{$f_schema}\".\"{$vwrs->fields['relname']}\" OWNER TO \"{$owner}\"";
            return $this->connection->execute($sql);
        }
        return 0;
    }

    /**
     * Alter a view's schema.
     */
    public function alterViewSchema($vwrs, $schema)
    {
        if (!empty($schema) && ($vwrs->fields['nspname'] != $schema)) {
            $f_schema = $this->connection->_schema;
            $this->connection->fieldClean($f_schema);
            $sql = "ALTER TABLE \"{$f_schema}\".\"{$vwrs->fields['relname']}\" SET SCHEMA \"{$schema}\"";
            return $this->connection->execute($sql);
        }
        return 0;
    }

    /**
     * Protected helper to alter view properties within a transaction.
     */
    protected function alterViewInternal($vwrs, $name, $owner, $schema, $comment)
    {
        $this->connection->fieldArrayClean($vwrs->fields);

        if ($this->connection->setComment('VIEW', $vwrs->fields['relname'], '', $comment) != 0) {
            return -4;
        }

        $this->connection->fieldClean($owner);
        $status = $this->alterViewOwner($vwrs, $owner);
        if ($status != 0) {
            return -5;
        }

        $this->connection->fieldClean($name);
        $status = $this->alterViewName($vwrs, $name);
        if ($status != 0) {
            return -3;
        }

        $this->connection->fieldClean($schema);
        $status = $this->alterViewSchema($vwrs, $schema);
        if ($status != 0) {
            return -6;
        }

        return 0;
    }

    /**
     * Alter view properties (transactional wrapper).
     */
    public function alterView($view, $name, $owner, $schema, $comment)
    {
        $data = $this->getView($view);
        if ($data->recordCount() != 1) {
            return -2;
        }

        $status = $this->connection->beginTransaction();
        if ($status != 0) {
            $this->connection->rollbackTransaction();
            return -1;
        }

        $status = $this->alterViewInternal($data, $name, $owner, $schema, $comment);

        if ($status != 0) {
            $this->connection->rollbackTransaction();
            return $status;
        }

        return $this->connection->endTransaction();
    }

    /**
     * Drops a view.
     */
    public function dropView($viewname, $cascade)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($viewname);

        $sql = "DROP VIEW \"{$f_schema}\".\"{$viewname}\"";
        if ($cascade) {
            $sql .= ' CASCADE';
        }

        return $this->connection->execute($sql);
    }
}
