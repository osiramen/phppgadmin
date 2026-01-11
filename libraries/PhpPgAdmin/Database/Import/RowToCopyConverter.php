<?php

namespace PhpPgAdmin\Database\Import;

class RowToCopyConverter
{
    private $nullPatterns;

    /**
     * @param array $nullPatterns strings that should be treated as NULL
     */
    public function __construct(array $nullPatterns)
    {
        // Prepare for fast lookup
        // "" => true, "NULL" => true, "\N" => true, etc.
        $this->nullPatterns = array_combine(
            $nullPatterns,
            array_fill(0, count($nullPatterns), true)
        );
    }

    /**
     * @param array $rowValues Numeric or assoc row values
     * @param array $mapping   Target column names in order
     * @param array $meta      Table meta (array of ['name','type','is_bytea','is_json'])
     * @param bool  $assoc     Whether $rowValues is associative by column name
     */
    public function toCopyLine(array $rowValues, array $mapping, array $meta, bool $assoc): string
    {
        $lineParts = [];
        foreach ($mapping as $idx => $colName) {
            $value = null;
            if ($assoc) {
                $value = $rowValues[$colName] ?? null;
            } else {
                $value = $rowValues[$idx] ?? null;
            }

            if ($value === null || isset($this->nullPatterns[(string) $value])) {
                $lineParts[] = '\\N';
                continue;
            }

            $isBytea = !empty($meta[$idx]['is_bytea']);

            if ($isBytea) {
                // bytea -> octal format
                $escaped = bytea_to_octal((string) $value);
            } else {
                // normal text -> COPY escaping
                $escaped = addcslashes((string) $value, "\0\\\n\r\t");
                // Fix octal escapes
                $escaped = preg_replace('/\\\\([0-7]{3})/', '\\\\\\1', $escaped);
            }

            $lineParts[] = $escaped;
        }

        return implode("\t", $lineParts) . "\n";
    }

}
