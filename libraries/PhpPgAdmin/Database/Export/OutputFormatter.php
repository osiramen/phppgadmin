<?php

namespace PhpPgAdmin\Database\Export;

use PhpPgAdmin\Core\AbstractContext;

/**
 * Abstract base class for export output formatters.
 * Each formatter is responsible for transforming dumped data into a specific output format.
 */
abstract class OutputFormatter extends AbstractContext
{
    /**
     * The MIME type for this format (e.g., 'text/plain', 'text/csv')
     * @var string
     */
    protected $mimeType = 'text/plain; charset=utf-8';

    /**
     * The file extension for this format (e.g., 'sql', 'csv', 'json')
     * @var string
     */
    protected $fileExtension = 'sql';

    /**
     * Whether gzip compression is supported for this format
     * @var bool
     */
    protected $supportsGzip = true;

    /**
     * Output stream for writing formatted data
     * @var resource|null
     */
    protected $outputStream = null;

    protected const DATA_TYPE_MAPPING = [

        'int2' => 'smallint',
        'int4' => 'integer',
        'int8' => 'bigint',
        'float4' => 'real',
        'float8' => 'double precision',

        'varchar' => 'character varying',
        'bpchar' => 'character', // blank-padded char

        'bool' => 'boolean',

        'time' => 'time without time zone',
        'timetz' => 'time with time zone',
        'timestamp' => 'timestamp without time zone',
        'timestamptz' => 'timestamp with time zone',

        '_int4' => 'integer[]',
        '_text' => 'text[]',
        '_varchar' => 'character varying[]',
        '_bool' => 'boolean[]',
        '_float8' => 'double precision[]',
        '_numeric' => 'numeric[]',

    ];

    protected static function encodeBytea(string $data, string $encoding): string
    {
        switch ($encoding) {
            case 'base64':
                return base64_encode($data);
            case 'octal':
            case 'escape':
                return bytea_to_octal($data);
            case 'hex':
            default:
                // Default to hex encoding
                return '\x' . bin2hex($data);
        }
    }

    /**
     * Get the MIME type for this format
     * @return string
     */
    public function getMimeType()
    {
        return $this->mimeType;
    }

    /**
     * Get the file extension for this format
     * @return string
     */
    public function getFileExtension()
    {
        return $this->fileExtension;
    }

    /**
     * Check if gzip compression is supported
     * @return bool
     */
    public function supportsGzip()
    {
        return $this->supportsGzip;
    }

    /**
     * Set the output stream for writing formatted data.
     * If not set (null), format() will collect and return output as string.
     *
     * @param resource|null $stream File handle or stream resource, or null to collect as string
     */
    public function setOutputStream($stream)
    {
        $this->outputStream = $stream;
    }

    /**
     * Write data to output stream or echo output.
     *
     * @param string $data Data to write
     */
    protected function write($data)
    {
        if ($this->outputStream) {
            fwrite($this->outputStream, $data);
        } else {
            echo $data;
        }
    }

    /**
     * Format an ADORecordSet to target output format.
     * Input: ADODB RecordSet with data rows from query or table dump
     * Output: Written to stream (if set) or accumulated as string
     *
     * @param \ADORecordSet $recordset ADORecordSet with data rows
     * @param array $metadata Optional metadata (table name, columns, types, etc.)
     */
    abstract public function format($recordset, $metadata = []);
}
