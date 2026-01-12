<?php

namespace PhpPgAdmin\Database\Export;

/**
 * XHTML Format Formatter
 * Converts table data to XHTML 1.0 Transitional table format
 */
class HtmlFormatter extends OutputFormatter
{
    /** @var string */
    protected $mimeType = 'text/plain; charset=utf-8';
    /** @var string */
    protected $fileExtension = 'html';
    /** @var bool */
    protected $supportsGzip = true;

    /**
     * Format ADORecordSet as XHTML
     * @param \ADORecordSet $recordset ADORecordSet
     * @param array $metadata Optional (unused, columns come from recordset)
     */
    public function format($recordset, $metadata = [])
    {
        $this->write('<?xml version="1.0" encoding="UTF-8"?>' . "\n");
        $this->write('<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">' . "\n");
        $this->write('<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en">' . "\n");
        $this->write("<head>\n");
        $this->write("\t<meta http-equiv=\"Content-Type\" content=\"text/html; charset=UTF-8\" />\n");
        $this->write("\t<title>Database Export</title>\n");
        $this->write("\t<style type=\"text/css\">\n");
        $this->write("\t\ttable { border-collapse: collapse; border: 1px solid #999; }\n");
        $this->write("\t\tth { background-color: #f0f0f0; border: 1px solid #999; padding: 5px; text-align: left; font-weight: bold; }\n");
        $this->write("\t\ttd { border: 1px solid #999; padding: 5px; }\n");
        $this->write("\t\ttr:nth-child(even) { background-color: #f9f9f9; }\n");
        $this->write("\t</style>\n");
        $this->write("</head>\n");
        $this->write("<body>\n");
        $this->write("<table>\n");
        if (!$recordset || $recordset->EOF) {
            $this->write("</table>\n</body>\n</html>\n");
            return;
        }

        $byteaEncoding = $metadata['bytea_encoding'] ?? 'hex';
        $exportNulls = $metadata['export_nulls'] ?? '';
        $isBytea = [];

        // Get column names and write header
        $columns = [];
        for ($i = 0; $i < count($recordset->fields); $i++) {
            $finfo = $recordset->fetchField($i);
            $type = $finfo->type ?? 'unknown';
            $columns[] = $finfo->name ?? "Column $i";
            $isBytea[$i] = ($type === 'bytea');
        }

        $this->write("\t<thead>\n\t<tr>\n");
        foreach ($columns as $column) {
            $this->write("\t\t<th>" . htmlspecialchars($column, ENT_QUOTES, 'UTF-8') . "</th>\n");
        }
        $this->write("\t</tr>\n\t</thead>\n");
        // Write data rows
        $this->write("\t<tbody>\n");
        while (!$recordset->EOF) {
            $this->write("\t<tr>\n");
            foreach ($recordset->fields as $i => $value) {
                if ($value === null) {
                    // NULL value
                    $value = $exportNulls;
                } elseif ($isBytea[$i]) {
                    // bytea → encode
                    $value = self::encodeBytea($value, $byteaEncoding);
                }
                $this->write("\t\t<td>" . htmlspecialchars($value ?? 'NULL', ENT_QUOTES, 'UTF-8') . "</td>\n");
            }
            $this->write("\t</tr>\n");
            $recordset->moveNext();
        }
        $this->write("\t</tbody>\n");

        $this->write("</table>\n");
        $this->write("</body>\n");
        $this->write("</html>\n");
    }
}
