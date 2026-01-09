<?php

namespace PhpPgAdmin\Database\Export;

use PhpPgAdmin\Core\AppContainer;

/**
 * SQL Format Formatter
 * Outputs PostgreSQL SQL statements as-is or slightly processed
 */
class SqlFormatter extends OutputFormatter
{
    /** @var string */
    protected $mimeType = 'text/plain; charset=utf-8';
    /** @var string */
    protected $fileExtension = 'sql';
    /** @var bool */
    protected $supportsGzip = true;

    private const ESCAPE_MODE_NONE = 0;
    private const ESCAPE_MODE_STRING = 1;
    private const ESCAPE_MODE_BYTEA = 2;

    /**
     * Format ADORecordSet as SQL INSERT statements
     * @param \ADORecordSet $recordset ADORecordSet
     * @param array $metadata with keys: table, columns, insert_format
     */
    public function format($recordset, $metadata = [])
    {
        $pg = AppContainer::getPostgres();
        $table_name = $metadata['table'] ?? 'data';
        $insert_format = $metadata['insert_format'] ?? 'multi'; // multi, single, or copy

        if (!$recordset || $recordset->EOF) {
            return;
        }

        // Get column information
        $columns = [];
        $escape_mode = []; // 0 = none, 1 = literal, 2 = bytea
        for ($i = 0; $i < count($recordset->fields); $i++) {
            $finfo = $recordset->fetchField($i);
            $name = $finfo->name ?? "column_$i";
            $columns[$i] = $name;
            $type = strtolower($finfo->type ?? '');

            // numeric types → no escaping
            if (
                isset([
                    'int2' => true,
                    'int4' => true,
                    'int8' => true,
                    'integer' => true,
                    'bigint' => true,
                    'smallint' => true,
                    'float4' => true,
                    'float8' => true,
                    'real' => true,
                    'double precision' => true,
                    'numeric' => true,
                    'decimal' => true
                ][$type])
            ) {
                $escape_mode[$i] = self::ESCAPE_MODE_NONE;
                continue;
            }

            // boolean → no escaping
            if ($type === 'bool' || $type === 'boolean') {
                $escape_mode[$i] = self::ESCAPE_MODE_NONE;
                continue;
            }

            // bytea → escapeBytea
            if ($type === 'bytea') {
                $escape_mode[$i] = self::ESCAPE_MODE_BYTEA;
                continue;
            }

            // anything else → escapeLiteral
            $escape_mode[$i] = self::ESCAPE_MODE_STRING;
        }


        if ($insert_format === 'copy') {
            // COPY format
            $line = "COPY " . $pg->escapeIdentifier($table_name) . " (" . implode(', ', array_map([$pg, 'escapeIdentifier'], $columns)) . ") FROM stdin;\n";
            $this->write($line);

            while (!$recordset->EOF) {
                $first = true;
                $line = '';
                foreach ($recordset->fields as $i => $v) {
                    if ($v !== null) {
                        if ($escape_mode[$i] === self::ESCAPE_MODE_BYTEA) {
                            // COPY bytea escaping
                            $v = bytea_to_octal($v);
                        } else {
                            // COPY escaping: backslash and non-printable chars
                            $v = addcslashes($v, "\0\\\n\r\t");
                            $v = preg_replace('/\\\\([0-7]{3})/', '\\\\\1', $v);
                        }
                    }
                    if ($first) {
                        $line .= ($v === null) ? '\\N' : $v;
                        $first = false;
                    } else {
                        $line .= "\t" . (($v === null) ? '\\N' : $v);
                    }
                }
                $line .= "\n";
                $this->write($line);
                $recordset->moveNext();
            }
            $this->write("\\.\n");
        } else {
            // Standard INSERT statements (multi or single)
            $batch_size = $metadata['batch_size'] ?? 100; // for multi-row inserts
            $is_multi = ($insert_format === 'multi');
            $rows_in_batch = 0;
            $insert_begin = $line = "INSERT INTO " . $pg->escapeIdentifier($table_name) . " (" . implode(', ', array_map([$pg, 'escapeIdentifier'], $columns)) . ") VALUES";

            while (!$recordset->EOF) {

                $values = "(";
                $sep = "";
                foreach ($recordset->fields as $i => $v) {
                    $values .= $sep;
                    $sep = ",";
                    if ($v === null) {
                        $values .= "NULL";
                    } elseif ($escape_mode[$i] === self::ESCAPE_MODE_STRING) {
                        $values .= $pg->escapeLiteral($v);
                    } elseif ($escape_mode[$i] === self::ESCAPE_MODE_BYTEA) {
                        $values .= "'" . $pg->escapeBytea($v) . "'";
                    } else {
                        $values .= $v;
                    }
                }
                $values .= ")";

                if ($is_multi) {
                    if ($rows_in_batch === 0) {
                        $this->write("$insert_begin\n");
                    } elseif ($rows_in_batch >= $batch_size) {
                        $this->write(";\n\n$insert_begin\n");
                        $rows_in_batch = 0;
                    } else {
                        $this->write(",\n");
                    }
                    $this->write($values);
                    $rows_in_batch++;
                } else {
                    $this->write("$insert_begin $values;\n");
                }

                $recordset->moveNext();
            }

            // Output multi-row INSERT statements
            if ($is_multi && $rows_in_batch > 0) {
                $this->write(";\n");
            }
        }
    }

}
