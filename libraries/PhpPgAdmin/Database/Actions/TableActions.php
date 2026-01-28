<?php

namespace PhpPgAdmin\Database\Actions;

use ADORecordSet;

use PhpPgAdmin\Database\Actions\AclActions;
use PhpPgAdmin\Database\Actions\ConstraintActions;
use PhpPgAdmin\Database\Actions\IndexActions;
use PhpPgAdmin\Database\Actions\RuleActions;

class TableActions extends ActionsBase
{
    //public const 

    /** @var AclActions */
    private $acl;

    /** @var ConstraintActions */
    private $constraint;

    /** @var IndexActions */
    private $index;

    /** @var RuleActions */
    private $rule;

    private function getAclAction()
    {
        if ($this->acl === null) {
            $this->acl = new AclActions($this->connection);
        }
        return $this->acl;
    }

    private function getConstraintAction()
    {
        if ($this->constraint === null) {
            $this->constraint = new ConstraintActions($this->connection);
        }
        return $this->constraint;
    }

    private function getIndexAction()
    {
        if ($this->index === null) {
            $this->index = new IndexActions($this->connection);
        }
        return $this->index;
    }

    private function getRuleAction()
    {
        if ($this->rule === null) {
            $this->rule = new RuleActions($this->connection);
        }
        return $this->rule;
    }

    private function hasGrantOption()
    {
        return $this->connection->hasGrantOption();
    }

    private function supportsTablespaces()
    {
        return $this->connection->hasTablespaces();
    }


    /**
     * Returns table information.
     */
    public function getTable($table)
    {
        $c_schema = $this->connection->_schema;
        $this->connection->clean($c_schema);
        $this->connection->clean($table);

        $sql =
            "SELECT
              c.relname, n.nspname, u.usename AS relowner, c.oid, c.relkind,
              c.relacl,
              pg_catalog.obj_description(c.oid, 'pg_class') AS relcomment,
              (SELECT spcname
                FROM pg_catalog.pg_tablespace pt
                WHERE pt.oid=c.reltablespace) AS tablespace
            FROM pg_catalog.pg_class c
                 LEFT JOIN pg_catalog.pg_user u ON u.usesysid = c.relowner
                 LEFT JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
            WHERE c.relkind = 'r'
                  AND n.nspname = '{$c_schema}'
                  AND n.oid = c.relnamespace
                  AND c.relname = '{$table}'";

        return $this->connection->selectSet($sql);
    }

    /**
     * Finds schema of table/view.
     */
    public function findTableSchema($table)
    {
        $this->connection->clean($table);
        $sql = "
            SELECT n.nspname
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE c.relname = '$table'
            AND n.nspname IN (
                    SELECT sp
                    FROM unnest(
                        string_to_array(
                            replace(current_setting('search_path'), ' ', ''),
                            ','
                        )
                    ) AS sp
                    WHERE sp <> '\$user'
                )
            LIMIT 1
            ";

        $schema = $this->connection->selectField($sql, 'nspname');
        return is_int($schema) ? null : $schema;
    }

    /**
     * Get type of table/view.
     */
    public function getTableType($schema, $table)
    {
        $this->connection->clean($schema);
        $this->connection->clean($table);
        $sql = "SELECT c.relkind
            FROM pg_catalog.pg_class c
            JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = '$schema'
              AND c.relname = '$table'";
        $type = $this->connection->selectField($sql, 'relkind');
        if ($type == 'r' || $type == 'f') {
            return 'table';
        }
        if ($type == 'v' || $type == 'm') {
            return 'view';
        }
        return null;
    }

    /**
     * Return all tables in current database (and schema).
     */
    public function getTables($all = false)
    {
        $c_schema = $this->connection->_schema;
        $this->connection->clean($c_schema);
        if ($all) {
            $sql = "SELECT schemaname AS nspname, tablename AS relname, tableowner AS relowner
                    FROM pg_catalog.pg_tables
                    WHERE schemaname NOT IN ('pg_catalog', 'information_schema', 'pg_toast')
                    ORDER BY schemaname, tablename";
        } else {
            $sql = "SELECT c.relname, pg_catalog.pg_get_userbyid(c.relowner) AS relowner,
                        pg_catalog.obj_description(c.oid, 'pg_class') AS relcomment,
                        reltuples::bigint,
                        (SELECT spcname FROM pg_catalog.pg_tablespace pt WHERE pt.oid=c.reltablespace) AS tablespace
                    FROM pg_catalog.pg_class c
                    LEFT JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
                    WHERE c.relkind = 'r'
                    AND nspname='{$c_schema}'
                    ORDER BY c.relname";
        }

        return $this->connection->selectSet($sql);
    }

    /**
     * Retrieve the attribute definition of a table.
     * @return ADORecordSet|int
     */
    public function getTableAttributes($table, $field = '')
    {
        $schema = $this->connection->_schema;
        $this->connection->clean($schema);
        $this->connection->clean($table);
        $this->connection->clean($field);

        $whereField = $field !== ''
            ? "AND a.attname = '{$field}'"
            : "AND a.attnum > 0 AND NOT a.attisdropped";

        // Add attgenerated field for PostgreSQL 12+ (generated columns)
        $attgeneratedField = '';
        if ($this->connection->major_version >= 12) {
            $attgeneratedField = "a.attgenerated,";
        }

        $sql =
            "SELECT DISTINCT ON (a.attrelid, a.attnum)
                a.attname,
                a.attnum,
                pg_catalog.format_type(a.atttypid, a.atttypmod) AS type,
                pg_catalog.format_type(a.atttypid, NULL) as base_type,
                a.atttypmod,
                a.attnotnull,
                a.atthasdef,
                {$attgeneratedField}
                pg_get_expr(ad.adbin, ad.adrelid, true) AS adsrc,
                a.attstattarget,
                a.attstorage,
                t.typstorage,
                seqns.nspname AS sequence_schema,
                seq.relname AS sequence_name,
                col_description(a.attrelid, a.attnum) AS comment
            FROM pg_attribute a
            LEFT JOIN pg_attrdef ad ON a.attrelid = ad.adrelid AND a.attnum = ad.adnum
            LEFT JOIN pg_type t ON a.atttypid = t.oid
            LEFT JOIN pg_depend d ON d.refobjid = a.attrelid
                                AND d.refobjsubid = a.attnum
                                AND d.deptype = 'a'
                                AND d.classid = 'pg_class'::regclass
            LEFT JOIN pg_class seq ON seq.oid = d.objid AND seq.relkind = 'S'
            LEFT JOIN pg_namespace seqns ON seq.relnamespace = seqns.oid
            WHERE a.attrelid = (
                SELECT oid FROM pg_class
                WHERE relname = '{$table}'
                AND relnamespace = (SELECT oid FROM pg_namespace WHERE nspname = '{$schema}')
            )
            {$whereField}
            ORDER BY a.attnum";

        return $this->connection->selectSet($sql);
    }

    /**
     * Finds parent tables.
     */
    public function getTableParents($table)
    {
        $c_schema = $this->connection->_schema;
        $this->connection->clean($c_schema);
        $this->connection->clean($table);

        $sql =
            "SELECT
                pn.nspname, relname
            FROM
                pg_catalog.pg_class pc, pg_catalog.pg_inherits pi, pg_catalog.pg_namespace pn
            WHERE
                pc.oid = pi.inhparent
                AND pc.relnamespace = pn.oid
                AND pi.inhrelid = (
                    SELECT oid
                    from pg_catalog.pg_class
                    WHERE relname = '{$table}'
                        AND relnamespace = (
                            SELECT oid
                            FROM pg_catalog.pg_namespace
                            WHERE nspname = '{$c_schema}'
                        )
                    )
            ORDER BY
                pi.inhseqno";

        return $this->connection->selectSet($sql);
    }

    /**
     * Finds child tables.
     */
    public function getTableChildren($table)
    {
        $c_schema = $this->connection->_schema;
        $this->connection->clean($c_schema);
        $this->connection->clean($table);

        $sql = "
            SELECT
                pn.nspname, relname
            FROM
                pg_catalog.pg_class pc, pg_catalog.pg_inherits pi, pg_catalog.pg_namespace pn
            WHERE
                pc.oid=pi.inhrelid
                AND pc.relnamespace=pn.oid
                AND pi.inhparent = (SELECT oid from pg_catalog.pg_class WHERE relname='{$table}'
                    AND relnamespace = (SELECT oid FROM pg_catalog.pg_namespace WHERE nspname = '{$c_schema}'))
        ";

        return $this->connection->selectSet($sql);
    }

    /**
     * Creates a new table.
     */
    public function createTable(
        $name,
        $fields,
        $field,
        $type,
        $array,
        $length,
        $notnull,
        $default,
        $withoutoids,
        $colcomment,
        $tblcomment,
        $tablespace,
        $uniquekey,
        $primarykey
    ) {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($name);

        $status = $this->connection->beginTransaction();
        if ($status != 0)
            return -1;

        $found = false;
        $first = true;
        $comment_sql = '';
        $sql = "CREATE TABLE \"{$f_schema}\".\"{$name}\" (";
        for ($i = 0; $i < $fields; $i++) {
            $this->connection->fieldClean($field[$i]);
            $this->connection->clean($type[$i]);
            $this->connection->clean($length[$i]);
            $this->connection->clean($colcomment[$i]);

            if ($field[$i] == '' || $type[$i] == '')
                continue;
            if (!$first)
                $sql .= ", ";
            else
                $first = false;

            switch ($type[$i]) {
                case 'timestamp with time zone':
                case 'timestamp without time zone':
                    $qual = substr($type[$i], 9);
                    $sql .= "\"{$field[$i]}\" timestamp";
                    if ($length[$i] != '')
                        $sql .= "({$length[$i]})";
                    $sql .= $qual;
                    break;
                case 'time with time zone':
                case 'time without time zone':
                    $qual = substr($type[$i], 4);
                    $sql .= "\"{$field[$i]}\" time";
                    if ($length[$i] != '')
                        $sql .= "({$length[$i]})";
                    $sql .= $qual;
                    break;
                default:
                    $sql .= "\"{$field[$i]}\" {$type[$i]}";
                    if ($length[$i] != '')
                        $sql .= "({$length[$i]})";
            }
            if ($array[$i] == '[]')
                $sql .= '[]';
            if (!isset($primarykey[$i])) {
                if (isset($uniquekey[$i]))
                    $sql .= " UNIQUE";
                if (isset($notnull[$i]))
                    $sql .= " NOT NULL";
            }
            if ($default[$i] != '')
                $sql .= " DEFAULT {$default[$i]}";

            if ($colcomment[$i] != '')
                $comment_sql .= "COMMENT ON COLUMN \"{$name}\".\"{$field[$i]}\" IS '{$colcomment[$i]}';\n";

            $found = true;
        }

        if (!$found)
            return -1;

        $primarykeycolumns = [];
        for ($i = 0; $i < $fields; $i++) {
            if (isset($primarykey[$i])) {
                $primarykeycolumns[] = "\"{$field[$i]}\"";
            }
        }
        if (count($primarykeycolumns) > 0) {
            $sql .= ", PRIMARY KEY (" . implode(", ", $primarykeycolumns) . ")";
        }

        $sql .= ")";

        if ($withoutoids)
            $sql .= ' WITHOUT OIDS';
        else
            $sql .= ' WITH OIDS';

        if ($this->supportsTablespaces() && $tablespace != '') {
            $this->connection->fieldClean($tablespace);
            $sql .= " TABLESPACE \"{$tablespace}\"";
        }

        $status = $this->connection->execute($sql);
        if ($status) {
            $this->connection->rollbackTransaction();
            return -1;
        }

        if ($tblcomment != '') {
            $status = $this->connection->setComment('TABLE', '', $name, $tblcomment, true);
            if ($status) {
                $this->connection->rollbackTransaction();
                return -1;
            }
        }

        if ($comment_sql != '') {
            $status = $this->connection->execute($comment_sql);
            if ($status) {
                $this->connection->rollbackTransaction();
                return -1;
            }
        }
        return $this->connection->endTransaction();
    }

    /**
     * Creates a table LIKE another table.
     */
    public function createTableLike($name, $like, $defaults = false, $constraints = false, $idx = false, $tablespace = '')
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($name);
        $this->connection->fieldClean($like['schema']);
        $this->connection->fieldClean($like['table']);
        $likeStr = "\"{$like['schema']}\".\"{$like['table']}\"";

        $status = $this->connection->beginTransaction();
        if ($status != 0)
            return -1;

        $sql = "CREATE TABLE \"{$f_schema}\".\"{$name}\" (LIKE {$likeStr}";

        if ($defaults)
            $sql .= " INCLUDING DEFAULTS";
        if ($constraints)
            $sql .= " INCLUDING CONSTRAINTS";
        if ($idx)
            $sql .= " INCLUDING INDEXES";

        $sql .= ")";

        if ($this->supportsTablespaces() && $tablespace != '') {
            $this->connection->fieldClean($tablespace);
            $sql .= " TABLESPACE \"{$tablespace}\"";
        }

        $status = $this->connection->execute($sql);
        if ($status) {
            $this->connection->rollbackTransaction();
            return -1;
        }

        return $this->connection->endTransaction();
    }

    /**
     * Alter a table's name.
     */
    public function alterTableName($tblrs, $name = null)
    {
        if (!empty($name) && ($name != $tblrs->fields['relname'])) {
            $f_schema = $this->connection->_schema;
            $this->connection->fieldClean($f_schema);
            $sql = "ALTER TABLE \"{$f_schema}\".\"{$tblrs->fields['relname']}\" RENAME TO \"{$name}\"";
            $status = $this->connection->execute($sql);
            if ($status == 0) {
                $tblrs->fields['relname'] = $name;
            } else {
                return $status;
            }
        }
        return 0;
    }

    /**
     * Alter a table's owner.
     */
    public function alterTableOwner($tblrs, $owner = null)
    {
        if (!empty($owner) && ($tblrs->fields['relowner'] != $owner)) {
            $f_schema = $this->connection->_schema;
            $this->connection->fieldClean($f_schema);
            $sql = "ALTER TABLE \"{$f_schema}\".\"{$tblrs->fields['relname']}\" OWNER TO \"{$owner}\"";
            return $this->connection->execute($sql);
        }
        return 0;
    }

    /**
     * Alter a table's tablespace.
     */
    public function alterTableTablespace($tblrs, $tablespace = null)
    {
        if (!empty($tablespace) && ($tblrs->fields['tablespace'] != $tablespace)) {
            $f_schema = $this->connection->_schema;
            $this->connection->fieldClean($f_schema);
            $sql = "ALTER TABLE \"{$f_schema}\".\"{$tblrs->fields['relname']}\" SET TABLESPACE \"{$tablespace}\"";
            return $this->connection->execute($sql);
        }
        return 0;
    }

    /**
     * Alter a table's schema.
     */
    public function alterTableSchema($tblrs, $schema = null)
    {
        if (!empty($schema) && ($tblrs->fields['nspname'] != $schema)) {
            $f_schema = $this->connection->_schema;
            $this->connection->fieldClean($f_schema);
            $sql = "ALTER TABLE \"{$f_schema}\".\"{$tblrs->fields['relname']}\" SET SCHEMA \"{$schema}\"";
            return $this->connection->execute($sql);
        }
        return 0;
    }

    /**
     * Internal alter table helper (transactional context expected).
     */
    private function alterTableInternal($tblrs, $name, $owner, $schema, $comment, $tablespace)
    {
        $this->connection->fieldArrayClean($tblrs->fields);

        $status = $this->connection->setComment('TABLE', '', $tblrs->fields['relname'], $comment);
        if ($status != 0)
            return -4;

        $this->connection->fieldClean($owner);
        $status = $this->alterTableOwner($tblrs, $owner);
        if ($status != 0)
            return -5;

        $this->connection->fieldClean($tablespace);
        $status = $this->alterTableTablespace($tblrs, $tablespace);
        if ($status != 0)
            return -6;

        $this->connection->fieldClean($name);
        $status = $this->alterTableName($tblrs, $name);
        if ($status != 0)
            return -3;

        $this->connection->fieldClean($schema);
        $status = $this->alterTableSchema($tblrs, $schema);
        if ($status != 0)
            return -7;

        return 0;
    }

    /**
     * Alter table properties.
     */
    public function alterTable($table, $name, $owner, $schema, $comment, $tablespace)
    {
        $data = $this->getTable($table);

        if ($data->recordCount() != 1)
            return -2;

        $status = $this->connection->beginTransaction();
        if ($status != 0) {
            $this->connection->rollbackTransaction();
            return -1;
        }

        $status = $this->alterTableInternal($data, $name, $owner, $schema, $comment, $tablespace);

        if ($status != 0) {
            $this->connection->rollbackTransaction();
            return $status;
        }

        return $this->connection->endTransaction();
    }

    /**
     * Map attnum list to attname list for a relation.
     */
    public function getAttributeNames($table, $atts)
    {
        $c_schema = $this->connection->_schema;
        $this->connection->clean($c_schema);
        $this->connection->clean($table);
        $this->connection->arrayClean($atts);

        if (!is_array($atts))
            return -1;
        if (sizeof($atts) == 0)
            return [];

        $sql = "SELECT attnum, attname FROM pg_catalog.pg_attribute WHERE
            attrelid=(SELECT oid FROM pg_catalog.pg_class WHERE relname='{$table}' AND
            relnamespace=(SELECT oid FROM pg_catalog.pg_namespace WHERE nspname='{$c_schema}'))
            AND attnum IN ('" . join("','", $atts) . "')";

        $rs = $this->connection->selectSet($sql);
        if ($rs->recordCount() != sizeof($atts)) {
            return -2;
        } else {
            $temp = [];
            while (!$rs->EOF) {
                $temp[$rs->fields['attnum']] = $rs->fields['attname'];
                $rs->moveNext();
            }
            return $temp;
        }
    }

    /**
     * Truncate a table (delete all rows).
     */
    public function emptyTable($table)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($table);

        $sql = "DELETE FROM \"{$f_schema}\".\"{$table}\"";

        return $this->connection->execute($sql);
    }

    /**
     * Drop a table.
     */
    public function dropTable($table, $cascade)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($table);

        $sql = "DROP TABLE \"{$f_schema}\".\"{$table}\"";
        if ($cascade)
            $sql .= " CASCADE";

        return $this->connection->execute($sql);
    }

    /**
     * Returns the current default_with_oids setting (legacy compatibility).
     */
    public function getDefaultWithOid()
    {
        // OID support was removed in PG12; retained for callers that check it
        if ($this->connection->major_version >= 12) {
            return false;
        }
        $sql = "SHOW default_with_oids";
        return $this->connection->selectField($sql, 'default_with_oids');
    }

    /**
     * Fetches tuple statistics for a table
     * @param $table The table to fetch stats for
     * @return \ADORecordSet A recordset
     */
    function getStatsTableTuples($table)
    {
        $c_schema = $this->connection->_schema;
        $this->connection->clean($c_schema);
        $this->connection->clean($table);

        $sql = "SELECT * FROM pg_stat_all_tables 
			WHERE schemaname='{$c_schema}' AND relname='{$table}'";

        return $this->connection->selectSet($sql);
    }

    /**
     * Fetches I/0 statistics for a table
     * @param $table The table to fetch stats for
     * @return \ADORecordSet A recordset
     */
    function getStatsTableIO($table)
    {
        $c_schema = $this->connection->_schema;
        $this->connection->clean($c_schema);
        $this->connection->clean($table);

        $sql = "SELECT * FROM pg_statio_all_tables 
			WHERE schemaname='{$c_schema}' AND relname='{$table}'";

        return $this->connection->selectSet($sql);
    }

    /**
     * Fetches tuple statistics for all indexes on a table
     * @param $table The table to fetch index stats for
     * @return \ADORecordSet A recordset
     */
    function getStatsIndexTuples($table)
    {
        $c_schema = $this->connection->_schema;
        $this->connection->clean($c_schema);
        $this->connection->clean($table);

        $sql = "SELECT * FROM pg_stat_all_indexes 
			WHERE schemaname='{$c_schema}' AND relname='{$table}' ORDER BY indexrelname";

        return $this->connection->selectSet($sql);
    }

    /**
     * Fetches I/0 statistics for all indexes on a table
     * @param $table The table to fetch index stats for
     * @return \ADORecordSet A recordset
     */
    function getStatsIndexIO($table)
    {
        $c_schema = $this->connection->_schema;
        $this->connection->clean($c_schema);
        $this->connection->clean($table);

        $sql = "SELECT * FROM pg_statio_all_indexes 
			WHERE schemaname='{$c_schema}' AND relname='{$table}' 
			ORDER BY indexrelname";

        return $this->connection->selectSet($sql);
    }
}
