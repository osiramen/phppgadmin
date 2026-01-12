<?php

namespace PhpPgAdmin\Database\Export;

class XmlFormatter extends OutputFormatter
{
    protected $mimeType = 'text/xml; charset=utf-8';
    protected $fileExtension = 'xml';
    protected $supportsGzip = true;

    public function format($recordset, $metadata = [])
    {
        $this->write('<?xml version="1.0" encoding="UTF-8"?>' . "\n");
        $this->write("<data>\n");

        if (!$recordset || $recordset->EOF) {
            $this->write("</data>\n");
            return;
        }

        $columns = [];
        $colCount = $recordset->FieldCount();
        $byteaEncoding = $metadata['bytea_encoding'] ?? 'hex';

        for ($i = 0; $i < $colCount; $i++) {
            $finfo = $recordset->FetchField($i);
            $type = $finfo->type ?? 'unknown';
            $columns[$i] = [
                'name' => $finfo->name,
                'type' => self::DATA_TYPE_MAPPING[$type] ?? $type
            ];
            if ($type === 'bytea') {
                $columns[$i]['encoding'] = $byteaEncoding;
            }
        }

        $this->write("<header>\n");
        foreach ($columns as $col) {
            $name = htmlspecialchars($col['name'], ENT_XML1, 'UTF-8');
            $type = htmlspecialchars($col['type'], ENT_XML1, 'UTF-8');
            $this->write("\t<col name=\"{$name}\" type=\"{$type}\" />\n");
        }
        $this->write("</header>\n");

        $this->write("<records>\n");

        while (!$recordset->EOF) {
            $this->write("\t<row>\n");

            for ($i = 0; $i < $colCount; $i++) {
                $col = $columns[$i];
                $name = htmlspecialchars($col['name'], ENT_XML1, 'UTF-8');
                $type = $col['type'];
                $value = $recordset->fields[$i];

                // NULL
                if ($value === null) {
                    $this->write("\t\t<col name=\"{$name}\" isNull=\"true\" />\n");
                    continue;
                }

                // BYTEA → Base64
                if ($type === 'bytea') {
                    // If $value is escaped, unescape first (adjust based on $data behavior)
                    $encoded = self::encodeBytea($value, $byteaEncoding);
                    $this->write("\t\t<col name=\"{$name}\">{$encoded}</col>\n");
                    continue;
                }

                // Large TEXT/JSON fields → optional CDATA
                // Disabled for now because it would need a check for ]]> inside data
                /*
                if (is_string($value) && strlen($value) >= 1024) {
                    $this->write("\t\t<col name=\"{$name}\"><![CDATA[{$value}]]></col>\n");
                    continue;
                }
                */

                // Normal text
                $encoded = htmlspecialchars($value, ENT_XML1, 'UTF-8');
                $this->write("\t\t<col name=\"{$name}\">{$encoded}</col>\n");
            }

            $this->write("\t</row>\n");
            $recordset->moveNext();
        }

        $this->write("</records>\n");
        $this->write("</data>\n");
    }

}
