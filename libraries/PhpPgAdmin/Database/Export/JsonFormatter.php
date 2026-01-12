<?php

namespace PhpPgAdmin\Database\Export;

/**
 * JSON Format Formatter
 * Converts table data to structured JSON with metadata
 */
class JsonFormatter extends OutputFormatter
{
    /** @var string */
    protected $mimeType = 'application/json; charset=utf-8';
    /** @var string */
    protected $fileExtension = 'json';
    /** @var bool */
    protected $supportsGzip = true;

    private const TYPE_DEFAULT = 0;
    private const TYPE_INTEGER = 1;
    private const TYPE_DECIMAL = 2;
    private const TYPE_BOOLEAN = 3;
    private const TYPE_BYTEA = 4;
    private const TYPE_JSON = 5;

    /**
     * Format ADORecordSet as JSON
     * @param \ADORecordSet $recordset ADORecordSet
     * @param array $metadata Optional (unused, columns come from recordset)
     */
    public function format($recordset, $metadata = [])
    {
        if (!$recordset || $recordset->EOF) {
            $this->write("{\"header\":[],\"data\":[]}\n");
            return;
        }

        $colCount = count($recordset->fields);
        $byteaEncoding = $metadata['bytea_encoding'] ?? 'hex';

        // --- 1) Column metadata ---
        $columns = [];
        $types = [];
        $fields = [];
        $type_code = [];
        for ($i = 0; $i < $colCount; $i++) {
            $finfo = $recordset->fetchField($i);
            $name = $finfo->name ?? "col_$i";
            $type = strtolower($finfo->type ?? 'unknown');

            $columns[$i] = $name;
            $types[$i] = $type;
            $fields[$i] = json_encode($name, JSON_UNESCAPED_UNICODE);

            // integer types → no escaping
            if (
                isset([
                    'int2' => true,
                    'int4' => true,
                    'int8' => true,
                    'integer' => true,
                    'bigint' => true,
                    'smallint' => true,
                ][$type])
            ) {
                $type_code[$i] = self::TYPE_INTEGER;
                continue;
            }

            // decimal types → probably no escaping
            if (
                isset([
                    'float4' => true,
                    'float8' => true,
                    'real' => true,
                    'double precision' => true,
                    'numeric' => true,
                    'decimal' => true
                ][$type])
            ) {
                $type_code[$i] = self::TYPE_DECIMAL;
                continue;
            }

            // boolean → no escaping
            if ($type === 'bool' || $type === 'boolean') {
                $type_code[$i] = self::TYPE_BOOLEAN;
                continue;
            }

            // bytea → encoding
            if ($type === 'bytea') {
                $type_code[$i] = self::TYPE_BYTEA;
                continue;
            }

            // json/jsonb → no escaping
            if ($type === 'json' || $type === 'jsonb') {
                $type_code[$i] = self::TYPE_JSON;
                continue;
            }

            $type_code[$i] = self::TYPE_DEFAULT;
        }

        // --- 2) Write JSON header ---
        $this->write("{\n");
        $this->write("\t\"header\": [\n");

        $sep = "";
        for ($i = 0; $i < $colCount; $i++) {
            $col = [
                'name' => $columns[$i],
                'type' => self::DATA_TYPE_MAPPING[$types[$i]] ?? $types[$i]
            ];
            if ($types[$i] === 'bytea') {
                $col['encoding'] = $byteaEncoding;
            }
            $this->write($sep . "\t\t" . json_encode($col, JSON_UNESCAPED_UNICODE));
            $sep = ",\n";
        }

        $this->write("\n\t],\n");
        $this->write("\t\"data\": [\n");

        // --- 3) Stream rows ---
        $sep = "";

        while (!$recordset->EOF) {
            $this->write($sep);
            $this->write("\t\t{");

            $innerSep = "";
            foreach ($recordset->fields as $i => $value) {
                $this->write($innerSep . $fields[$i] . ":");
                $innerSep = ",";
                if ($value === null) {
                    $this->write("null");
                    continue;
                }
                switch ($type_code[$i]) {
                    case self::TYPE_INTEGER:
                        $this->write($value);
                        break;
                    case self::TYPE_DECIMAL:
                        // Handle special float values
                        if ($value === "NaN" || $value === "Infinity" || $value === "-Infinity") {
                            $this->write('"' . addcslashes($value, "\\\"\n\r\t\f\b") . '"');
                        } else {
                            $this->write($value);
                        }
                        break;
                    case self::TYPE_BOOLEAN:
                        $this->write($value ? "true" : "false");
                        break;
                    case self::TYPE_BYTEA:
                        $this->write('"' . self::encodeBytea($value, $byteaEncoding) . '"');
                        break;
                    case self::TYPE_JSON:
                        $this->write($value);
                        break;
                    default:
                        $this->write('"' . addcslashes($value, "\\\"\n\r\t\f\b") . '"');
                }
            }

            $this->write("}");
            $sep = ",\n";

            $recordset->moveNext();
        }

        // --- 4) Close JSON ---
        $this->write("\n\t]\n");
        $this->write("}\n");
    }



}
