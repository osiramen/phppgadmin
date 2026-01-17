<?php

namespace PhpPgAdmin\Database\Actions;

use PhpPgAdmin\Database\AbstractActions;

class TypeActions extends AbstractActions
{
    // Base constructor inherited from Actions



    /**
     * Returns all details for a particular type.
     */
    public function getType($typname)
    {
        $this->connection->clean($typname);

        $sql = "SELECT
                t.typtype,
                t.typbyval,
                t.typname,
                t.typinput AS typin,
                t.typoutput AS typout,
                t.typlen,
                t.typalign,
                pu.usename AS typowner,
                pg_catalog.obj_description(t.oid, 'pg_type') AS typcomment
            FROM pg_type t
                LEFT JOIN pg_catalog.pg_user pu ON t.typowner = pu.usesysid
            WHERE typname='{$typname}'";

        return $this->connection->selectSet($sql);
    }

    /**
     * Returns a list of all types in the database.
     */
    public function getTypes($all = false, $tabletypes = false, $domains = false)
    {
        if ($all) {
            $where = '1 = 1';
        } else {
            $c_schema = $this->connection->_schema;
            $this->connection->clean($c_schema);
            $where = "n.nspname = '{$c_schema}'";
        }

        $where2 = "AND c.relnamespace NOT IN (SELECT oid FROM pg_catalog.pg_namespace WHERE nspname LIKE 'pg@_%' ESCAPE '@')";

        $tqry = "'c'";
        if ($tabletypes) {
            $tqry .= ", 'r', 'v'";
        }

        if (!$domains) {
            $where .= " AND t.typtype != 'd'";
        }

        $sql = "SELECT
            t.oid,
            t.typname AS basename,
            pg_catalog.format_type(t.oid, NULL) AS typname,
                pu.usename AS typowner,
                t.typtype,
                pg_catalog.obj_description(t.oid, 'pg_type') AS typcomment
            FROM (pg_catalog.pg_type t
                LEFT JOIN pg_catalog.pg_namespace n ON n.oid = t.typnamespace)
                LEFT JOIN pg_catalog.pg_user pu ON t.typowner = pu.usesysid
            WHERE (t.typrelid = 0 OR (SELECT c.relkind IN ({$tqry}) FROM pg_catalog.pg_class c WHERE c.oid = t.typrelid {$where2}))
            AND t.typelem = 0  -- Exclude array types
            -- AND t.typname !~ '^_'
            AND {$where}
            ORDER BY typname
        ";
        //var_dump($sql);

        return $this->connection->selectSet($sql);
    }

    /**
     * Creates a new base type.
     */
    public function createBaseType($typname, $typin, $typout, $typlen, $typdef, $typelem, $typdelim, $typbyval, $typalign, $typstorage)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($typname);
        $this->connection->fieldClean($typin);
        $this->connection->fieldClean($typout);

        $sql = "
            CREATE TYPE \"{$f_schema}\".\"{$typname}\" (
                INPUT = \"{$typin}\",
                OUTPUT = \"{$typout}\",
                INTERNALLENGTH = {$typlen}";
        if ($typdef != '') {
            $sql .= ", DEFAULT = {$typdef}";
        }
        if ($typelem != '') {
            $sql .= ", ELEMENT = {$typelem}";
        }
        if ($typdelim != '') {
            $sql .= ", DELIMITER = {$typdelim}";
        }
        if ($typbyval) {
            $sql .= ", PASSEDBYVALUE, ";
        }
        if ($typalign != '') {
            $sql .= ", ALIGNMENT = {$typalign}";
        }
        if ($typstorage != '') {
            $sql .= ", STORAGE = {$typstorage}";
        }

        $sql .= ")";

        return $this->connection->execute($sql);
    }

    /**
     * Drops a type.
     */
    public function dropType($typname, $cascade)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($typname);

        $sql = "DROP TYPE \"{$f_schema}\".\"{$typname}\"";
        if ($cascade) {
            $sql .= ' CASCADE';
        }

        return $this->connection->execute($sql);
    }

    /**
     * Creates a new enum type in the database.
     */
    public function createEnumType($name, $values, $typcomment)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($name);

        if (empty($values)) {
            return -2;
        }

        $status = $this->connection->beginTransaction();
        if ($status != 0) {
            return -1;
        }

        $values = array_unique($values);
        $nbval = count($values);

        for ($i = 0; $i < $nbval; $i++) {
            $this->connection->clean($values[$i]);
        }

        $sql = "CREATE TYPE \"{$f_schema}\".\"{$name}\" AS ENUM ('";
        $sql .= implode("','", $values);
        $sql .= "')";

        $status = $this->connection->execute($sql);
        if ($status) {
            $this->connection->rollbackTransaction();
            return -1;
        }

        if ($typcomment != '') {
            $status = $this->connection->setComment('TYPE', $name, '', $typcomment, true);
            if ($status) {
                $this->connection->rollbackTransaction();
                return -1;
            }
        }

        return $this->connection->endTransaction();
    }

    /**
     * Get defined values for a given enum.
     */
    public function getEnumValues($name)
    {
        $this->connection->clean($name);

        $sql = "SELECT enumlabel AS enumval
        FROM pg_catalog.pg_type t JOIN pg_catalog.pg_enum e ON (t.oid=e.enumtypid)
        WHERE t.typname = '{$name}' ORDER BY e.oid";
        return $this->connection->selectSet($sql);
    }

    /**
     * Creates a new composite type in the database.
     */
    public function createCompositeType($name, $fields, $field, $type, $array, $length, $colcomment, $typcomment)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($name);

        $status = $this->connection->beginTransaction();
        if ($status != 0) {
            return -1;
        }

        $found = false;
        $first = true;
        $comment_sql = '';
        $sql = "CREATE TYPE \"{$f_schema}\".\"{$name}\" AS (";
        for ($i = 0; $i < $fields; $i++) {
            $this->connection->fieldClean($field[$i]);
            $this->connection->clean($type[$i]);
            $this->connection->clean($length[$i]);
            $this->connection->clean($colcomment[$i]);

            if ($field[$i] == '' || $type[$i] == '') {
                continue;
            }
            if (!$first) {
                $sql .= ", ";
            } else {
                $first = false;
            }

            switch ($type[$i]) {
                case 'timestamp with time zone':
                case 'timestamp without time zone':
                    $qual = substr($type[$i], 9);
                    $sql .= "\"{$field[$i]}\" timestamp";
                    if ($length[$i] != '') {
                        $sql .= "({$length[$i]})";
                    }
                    $sql .= $qual;
                    break;
                case 'time with time zone':
                case 'time without time zone':
                    $qual = substr($type[$i], 4);
                    $sql .= "\"{$field[$i]}\" time";
                    if ($length[$i] != '') {
                        $sql .= "({$length[$i]})";
                    }
                    $sql .= $qual;
                    break;
                default:
                    $sql .= "\"{$field[$i]}\" {$type[$i]}";
                    if ($length[$i] != '') {
                        $sql .= "({$length[$i]})";
                    }
            }

            if ($array[$i] == '[]') {
                $sql .= '[]';
            }

            if ($colcomment[$i] != '') {
                $comment_sql .= "COMMENT ON COLUMN \"{$f_schema}\".\"{$name}\".\"{$field[$i]}\" IS '{$colcomment[$i]}';\n";
            }

            $found = true;
        }

        if (!$found) {
            return -1;
        }

        $sql .= ")";

        $status = $this->connection->execute($sql);
        if ($status) {
            $this->connection->rollbackTransaction();
            return -1;
        }

        if ($typcomment != '') {
            $status = $this->connection->setComment('TYPE', $name, '', $typcomment, true);
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
     * Returns a list of all conversions in the database.
     */
    public function getConversions()
    {
        $c_schema = $this->connection->_schema;
        $this->connection->clean($c_schema);
        $sql = "
            SELECT
                   c.conname,
                   pg_catalog.pg_encoding_to_char(c.conforencoding) AS conforencoding,
                   pg_catalog.pg_encoding_to_char(c.contoencoding) AS contoencoding,
                   c.condefault,
                   pg_catalog.obj_description(c.oid, 'pg_conversion') AS concomment
            FROM pg_catalog.pg_conversion c, pg_catalog.pg_namespace n
            WHERE n.oid = c.connamespace
                  AND n.nspname='{$c_schema}'
            ORDER BY 1;
        ";

        return $this->connection->selectSet($sql);
    }

    /**
     * Renames a type.
     */
    public function renameType($typename, $newname)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($typename);
        $this->connection->fieldClean($newname);

        $sql = "ALTER TYPE \"{$f_schema}\".\"{$typename}\" RENAME TO \"{$newname}\"";

        return $this->connection->execute($sql);
    }

    /**
     * Changes the owner of a type.
     */
    public function changeTypeOwner($typename, $newowner)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($typename);
        $this->connection->fieldClean($newowner);

        $sql = "ALTER TYPE \"{$f_schema}\".\"{$typename}\" OWNER TO \"{$newowner}\"";

        return $this->connection->execute($sql);
    }

    /**
     * Changes the schema of a type.
     */
    public function changeTypeSchema($typename, $newschema)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($typename);
        $this->connection->fieldClean($newschema);

        $sql = "ALTER TYPE \"{$f_schema}\".\"{$typename}\" SET SCHEMA \"{$newschema}\"";

        return $this->connection->execute($sql);
    }

    /**
     * Renames an enum type value.
     */
    public function renameEnumTypeValue($type, $oldValue, $newValue)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($type);
        $this->connection->clean($oldValue);
        $this->connection->clean($newValue);

        $sql = "ALTER TYPE \"{$f_schema}\".\"{$type}\" RENAME VALUE '{$oldValue}' TO '{$newValue}'";

        return $this->connection->execute($sql);
    }

    /**
     * Adds a new value to an enum type.
     */
    public function addEnumTypeValue($type, $newValue, $before = null, $after = null)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($type);
        $this->connection->clean($newValue);

        $sql = "ALTER TYPE \"{$f_schema}\".\"{$type}\" ADD VALUE '{$newValue}'";

        if ($before !== null) {
            $this->connection->clean($before);
            $sql .= " BEFORE '{$before}'";
        } elseif ($after !== null) {
            $this->connection->clean($after);
            $sql .= " AFTER '{$after}'";
        }

        return $this->connection->execute($sql);
    }

    public function hasTypeAddValue(): bool
    {
        return $this->connection->major_version >= 9.1;
    }

    public function hasTypeRenameValue(): bool
    {
        return $this->connection->major_version >= 10;
    }

    public function hasEnumTypes(): bool
    {
        // Todo remove validation?
        return true;
    }

}
