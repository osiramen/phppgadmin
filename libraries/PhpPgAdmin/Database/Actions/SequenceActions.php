<?php

namespace PhpPgAdmin\Database\Actions;

use PhpPgAdmin\Database\AbstractActions;

class SequenceActions extends AbstractActions
{
    // Base constructor inherited from Actions

    /**
     * Determines whether the current user can directly access sequence information.
     * Returns 't' or 'f'.
     */
    public function hasSequencePrivilege($sequence)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->clean($f_schema);
        $this->connection->fieldClean($sequence);
        $this->connection->clean($sequence);

        $sql = "SELECT pg_catalog.has_sequence_privilege('{$f_schema}.{$sequence}','SELECT,USAGE')";

        return $this->connection->selectField($sql, 'has_sequence_privilege');
    }

    /**
     * Returns properties of a single sequence.
     */
    public function getSequence($sequence)
    {
        $c_schema = $this->connection->_schema;
        $this->connection->clean($c_schema);
        $c_sequence = $sequence;
        $this->connection->fieldClean($sequence);
        $this->connection->clean($c_sequence);

        $join = '';
        if ($this->hasSequencePrivilege($sequence) === 't') {
            $join = "CROSS JOIN \"{$c_schema}\".\"{$c_sequence}\" AS s";
        } else {
            $join = 'CROSS JOIN ( values (null, null, null) ) AS s (last_value, log_cnt, is_called) ';
        }

        $sql = "
            SELECT
                c.relname AS seqname,
                s.last_value, s.log_cnt, s.is_called,
                m.seqstart AS start_value, m.seqincrement AS increment_by, m.seqmax AS max_value, m.seqmin AS min_value,
                m.seqcache AS cache_value, m.seqcycle AS is_cycled,
                pg_catalog.obj_description(c.oid, 'pg_class') as seqcomment,
                pg_catalog.pg_get_userbyid(c.relowner) as seqowner,
                n.nspname
            FROM
                pg_catalog.pg_class c
                JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
                JOIN pg_catalog.pg_sequence m ON m.seqrelid = c.oid
                {$join}
            WHERE
                c.relkind IN ('S')
                AND c.relname = '{$c_sequence}'
                AND n.nspname = '{$c_schema}'
        ";

        return $this->connection->selectSet($sql);
    }

    /**
     * Returns all sequences in the current database.
     */
    public function getSequences($all = false)
    {
        if ($all) {
            $sql = "
                SELECT
                    n.nspname,
                    c.relname AS seqname,
                    pg_catalog.pg_get_userbyid(c.relowner) as seqowner
                FROM
                    pg_catalog.pg_class c
                    JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
                WHERE
                    c.relkind IN ('S')
                    AND n.nspname NOT IN ('pg_catalog','information_schema')
                    AND n.nspname !~ '^pg_toast'
                    AND pg_catalog.pg_table_is_visible(c.oid)
                ORDER BY
                    nspname, seqname;
            ";
        } else {
            $c_schema = $this->connection->_schema;
            $this->connection->clean($c_schema);
            $sql = "
                SELECT
                    n.nspname,
                    c.relname AS seqname,
                    pg_catalog.obj_description(c.oid, 'pg_class') AS seqcomment,
                    (SELECT spcname FROM pg_catalog.pg_tablespace pt WHERE pt.oid=c.reltablespace) AS tablespace,
                    pg_catalog.pg_get_userbyid(c.relowner) as seqowner
                FROM
                    pg_catalog.pg_class c
                    JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
                WHERE
                    c.relkind IN ('S')
                    AND n.nspname = '{$c_schema}'
                    AND pg_catalog.pg_table_is_visible(c.oid)
                ORDER BY
                    nspname, seqname;
            ";
        }

        return $this->connection->selectSet($sql);
    }

    /**
     * Execute nextval on a given sequence.
     */
    public function nextvalSequence($sequence)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->clean($f_schema);
        $this->connection->fieldClean($sequence);
        $this->connection->clean($sequence);

        $sql = "SELECT pg_catalog.SETVAL('\"{$f_schema}\".\"{$sequence}\"', pg_catalog.NEXTVAL('\"{$f_schema}\".\"{$sequence}\"'), true);";

        return $this->connection->execute($sql);
    }

    /**
     * Execute setval on a given sequence.
     */
    public function setvalSequence($sequence, $nextvalue)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->clean($f_schema);
        $this->connection->fieldClean($sequence);
        $this->connection->clean($sequence);
        $this->connection->clean($nextvalue);

        $sql = "SELECT pg_catalog.SETVAL('\"{$f_schema}\".\"{$sequence}\"', '{$nextvalue}', true)";

        return $this->connection->execute($sql);
    }

    /**
     * Restart a given sequence to its start value.
     */
    public function restartSequence($sequence)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($sequence);

        $sql = "ALTER SEQUENCE \"{$f_schema}\".\"{$sequence}\" RESTART;";

        return $this->connection->execute($sql);
    }

    /**
     * Resets a given sequence to its minimum value.
     * @return int 0 success, -1 sequence not found
     */
    public function resetSequence($sequence)
    {
        $seq = $this->getSequence($sequence);
        if (!is_object($seq) || $seq->recordCount() != 1) {
            return -1;
        }
        $minvalue = $seq->fields['min_value'];

        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($sequence);
        $this->connection->clean($sequence);

        $sql = "SELECT pg_catalog.SETVAL('\"{$f_schema}\".\"{$sequence}\"', {$minvalue})";

        return $this->connection->execute($sql);
    }

    /**
     * Creates a new sequence.
     */
    public function createSequence(
        $sequence,
        $increment,
        $minvalue,
        $maxvalue,
        $startvalue,
        $cachevalue,
        $cycledvalue
    ) {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($sequence);
        $this->connection->clean($increment);
        $this->connection->clean($minvalue);
        $this->connection->clean($maxvalue);
        $this->connection->clean($startvalue);
        $this->connection->clean($cachevalue);

        $sql = "CREATE SEQUENCE \"{$f_schema}\".\"{$sequence}\"";
        if ($increment !== '') {
            $sql .= " INCREMENT {$increment}";
        }
        if ($minvalue !== '') {
            $sql .= " MINVALUE {$minvalue}";
        }
        if ($maxvalue !== '') {
            $sql .= " MAXVALUE {$maxvalue}";
        }
        if ($startvalue !== '') {
            $sql .= " START {$startvalue}";
        }
        if ($cachevalue !== '') {
            $sql .= " CACHE {$cachevalue}";
        }
        if ($cycledvalue) {
            $sql .= " CYCLE";
        }

        return $this->connection->execute($sql);
    }

    /**
     * Rename a sequence.
     */
    public function alterSequenceName($seqrs, $name)
    {
        if (!empty($name) && ($seqrs->fields['seqname'] != $name)) {
            $f_schema = $this->connection->_schema;
            $this->connection->fieldClean($f_schema);
            $sql = "ALTER SEQUENCE \"{$f_schema}\".\"{$seqrs->fields['seqname']}\" RENAME TO \"{$name}\"";
            $status = $this->connection->execute($sql);
            if ($status == 0) {
                $seqrs->fields['seqname'] = $name;
            } else {
                return $status;
            }
        }
        return 0;
    }

    /**
     * Alter a sequence's owner.
     */
    public function alterSequenceOwner($seqrs, $owner)
    {
        if (!empty($owner) && ($seqrs->fields['seqowner'] != $owner)) {
            $f_schema = $this->connection->_schema;
            $this->connection->fieldClean($f_schema);
            $sql = "ALTER SEQUENCE \"{$f_schema}\".\"{$seqrs->fields['seqname']}\" OWNER TO \"{$owner}\"";
            return $this->connection->execute($sql);
        }
        return 0;
    }

    /**
     * Alter a sequence's schema.
     */
    public function alterSequenceSchema($seqrs, $schema)
    {
        if (!empty($schema) && ($seqrs->fields['nspname'] != $schema)) {
            $f_schema = $this->connection->_schema;
            $this->connection->fieldClean($f_schema);
            $sql = "ALTER SEQUENCE \"{$f_schema}\".\"{$seqrs->fields['seqname']}\" SET SCHEMA {$schema}";
            return $this->connection->execute($sql);
        }
        return 0;
    }

    /**
     * Alter a sequence's properties.
     */
    public function alterSequenceProps(
        $seqrs,
        $increment,
        $minvalue,
        $maxvalue,
        $restartvalue,
        $cachevalue,
        $cycledvalue,
        $startvalue
    ) {
        $sql = '';
        if (!empty($increment) && ($increment != $seqrs->fields['increment_by'])) {
            $sql .= " INCREMENT {$increment}";
        }
        if (!empty($minvalue) && ($minvalue != $seqrs->fields['min_value'])) {
            $sql .= " MINVALUE {$minvalue}";
        }
        if (!empty($maxvalue) && ($maxvalue != $seqrs->fields['max_value'])) {
            $sql .= " MAXVALUE {$maxvalue}";
        }
        if (!empty($restartvalue) && ($restartvalue != $seqrs->fields['last_value'])) {
            $sql .= " RESTART {$restartvalue}";
        }
        if (!empty($cachevalue) && ($cachevalue != $seqrs->fields['cache_value'])) {
            $sql .= " CACHE {$cachevalue}";
        }
        if (!empty($startvalue) && ($startvalue != $seqrs->fields['start_value'])) {
            $sql .= " START {$startvalue}";
        }
        if (!is_null($cycledvalue)) {
            $sql .= (!$cycledvalue ? ' NO ' : '') . ' CYCLE';
        }

        if ($sql !== '') {
            $f_schema = $this->connection->_schema;
            $this->connection->fieldClean($f_schema);
            $sql = "ALTER SEQUENCE \"{$f_schema}\".\"{$seqrs->fields['seqname']}\" {$sql}";
            return $this->connection->execute($sql);
        }

        return 0;
    }

    /**
     * Internal helper to alter a sequence; must be run inside a transaction.
     * Returns 0 on success or negative error codes from legacy flow.
     */
    private function alterSequenceInternal(
        $seqrs,
        $name,
        $comment,
        $owner,
        $schema,
        $increment,
        $minvalue,
        $maxvalue,
        $restartvalue,
        $cachevalue,
        $cycledvalue,
        $startvalue
    ) {
        $this->connection->fieldArrayClean($seqrs->fields);

        $status = $this->connection->setComment('SEQUENCE', $seqrs->fields['seqname'], '', $comment);
        if ($status != 0) {
            return -4;
        }

        $this->connection->fieldClean($owner);
        $status = $this->alterSequenceOwner($seqrs, $owner);
        if ($status != 0) {
            return -5;
        }

        $this->connection->clean($increment);
        $this->connection->clean($minvalue);
        $this->connection->clean($maxvalue);
        $this->connection->clean($restartvalue);
        $this->connection->clean($cachevalue);
        $this->connection->clean($cycledvalue);
        $this->connection->clean($startvalue);
        $status = $this->alterSequenceProps($seqrs, $increment, $minvalue, $maxvalue, $restartvalue, $cachevalue, $cycledvalue, $startvalue);
        if ($status != 0) {
            return -6;
        }

        $this->connection->fieldClean($name);
        $status = $this->alterSequenceName($seqrs, $name);
        if ($status != 0) {
            return -3;
        }

        $this->connection->clean($schema);
        $status = $this->alterSequenceSchema($seqrs, $schema);
        if ($status != 0) {
            return -7;
        }

        return 0;
    }

    /**
     * Alters a sequence.
     * @return int 0 success, -1 transaction error, -2 get existing sequence error, or internal error codes
     */
    public function alterSequence(
        $sequence,
        $name,
        $comment,
        $owner = null,
        $schema = null,
        $increment = null,
        $minvalue = null,
        $maxvalue = null,
        $restartvalue = null,
        $cachevalue = null,
        $cycledvalue = null,
        $startvalue = null
    ) {
        $this->connection->fieldClean($sequence);

        $data = $this->getSequence($sequence);

        if (!is_object($data) || $data->recordCount() != 1) {
            return -2;
        }

        $status = $this->connection->beginTransaction();
        if ($status != 0) {
            $this->connection->rollbackTransaction();
            return -1;
        }

        $status = $this->alterSequenceInternal(
            $data,
            $name,
            $comment,
            $owner,
            $schema,
            $increment,
            $minvalue,
            $maxvalue,
            $restartvalue,
            $cachevalue,
            $cycledvalue,
            $startvalue
        );

        if ($status != 0) {
            $this->connection->rollbackTransaction();
            return $status;
        }

        return $this->connection->endTransaction();
    }

    /**
     * Drops a given sequence.
     */
    public function dropSequence($sequence, $cascade)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($sequence);

        $sql = "DROP SEQUENCE \"{$f_schema}\".\"{$sequence}\"";
        if ($cascade) {
            $sql .= ' CASCADE';
        }

        return $this->connection->execute($sql);
    }
}
