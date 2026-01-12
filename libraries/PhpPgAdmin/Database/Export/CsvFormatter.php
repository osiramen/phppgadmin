<?php

namespace PhpPgAdmin\Database\Export;

/**
 * CSV Format Formatter
 * Converts table data to RFC 4180 compliant CSV
 */
class CsvFormatter extends OutputFormatter
{
    /** @var string */
    protected $mimeType = 'text/csv; charset=utf-8';
    /** @var string */
    protected $fileExtension;
    /** @var bool */
    protected $supportsGzip = true;

    /** @var \PhpPgAdmin\Database\Postgres */
    private $pg;

    private $exportNulls;

    /** @var string */
    private $delimiter;
    /** @var string */
    private $lineEnding;

    private $byteaEncoding;

    public function __construct($delimiter = ',', $lineEnding = "\r\n", $fileExtension = 'csv')
    {
        $this->delimiter = $delimiter;
        $this->lineEnding = $lineEnding;
        $this->fileExtension = $fileExtension;
    }

    /**
     * Format ADORecordSet as CSV
     * @param \ADORecordSet $recordset ADORecordSet
     * @param array $metadata Optional (unused, columns come from recordset)
     */
    public function format($recordset, $metadata = [])
    {
        if (!$recordset || $recordset->EOF) {
            return;
        }

        $this->pg = $this->postgres();
        $this->exportNulls = $metadata['export_nulls'] ?? '';
        $this->byteaEncoding = $metadata['bytea_encoding'] ?? 'hex';

        // Detect bytea columns once
        $is_bytea = [];
        $is_json = [];
        $col_count = count($recordset->fields);

        for ($i = 0; $i < $col_count; $i++) {
            $finfo = $recordset->fetchField($i);
            $type = strtolower($finfo->type ?? '');
            $is_bytea[$i] = ($type === 'bytea');
            $is_json[$i] = ($type === 'json' || $type === 'jsonb');
        }

        // Header
        $columns = [];
        for ($i = 0; $i < $col_count; $i++) {
            $finfo = $recordset->fetchField($i);
            $columns[$i] = $finfo->name ?? "Column $i";
        }
        $this->write($this->csvLineRaw($columns));

        // Rows
        while (!$recordset->EOF) {
            $this->write($this->csvLineRecord($recordset->fields, $is_bytea, $is_json));
            $recordset->moveNext();
        }
    }

    /**
     * CSV line for header (no bytea)
     */
    private function csvLineRaw(array $fields): string
    {
        $out = '';
        $sep = '';

        foreach ($fields as $field) {
            $out .= $sep;
            $out .= $this->csvField($field);
            $sep = $this->delimiter;
        }

        return $out . $this->lineEnding;
    }

    /**
     * CSV line for data rows (with bytea support)
     */
    private function csvLineRecord(array $fields, array $is_bytea, array $is_json): string
    {
        $out = '';
        $sep = '';

        foreach ($fields as $i => $value) {
            $out .= $sep;

            if ($value === null) {
                // append NULL representation
                $value = $this->exportNulls;
            } elseif ($is_json[$i]) {
                // JSON → special escaping for CSV
                $value = $this->escapeJsonForCsv($value);
            } else {
                if ($is_bytea[$i]) {
                    // bytea → encode → then CSV-escape
                    $value = self::encodeBytea($value, $this->byteaEncoding);
                }
                $value = $this->csvField($value);
            }

            $out .= $value;
            $sep = $this->delimiter;
        }

        return $out . $this->lineEnding;
    }

    /**
     * Fast CSV field escaping
     */
    private function csvField($value): string
    {
        $value = (string) $value;

        if (strpbrk($value, $this->delimiter . "\"\n") !== false) {
            return '"' . str_replace('"', '""', $value) . '"';
        }

        return $value;
    }

    private function escapeJsonForCsv(string $json): string
    {
        // Escape literal newlines in JSON to prevent reimport corruption
        $json = str_replace("\n", "\\n", $json);

        // Preserve escaped quotes in JSON while CSV-escaping outer quotes
        $temp = str_replace('\\"', "\x1A", $json);
        $temp = str_replace('"', '""', $temp);
        $escaped = str_replace("\x1A", '\\"', $temp);

        return '"' . $escaped . '"';
    }


}
