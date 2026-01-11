<?php

namespace PhpPgAdmin\Database\Import;

/**
 * Lightweight streaming XML parser tailored to the export format emitted by XmlFormatter:
 * <data><header>...<col .../></header><records><row><col ...>...</col></row>...</records></data>
 *
 * We avoid persistent XML parser state (cannot be stored in session) and instead
 * extract complete <row>...</row> blocks from the current chunk + remainder.
 */
class XmlRowParser implements RowStreamingParser
{
    public function parse(string $chunk, array &$state): array
    {
        $rows = [];
        $header = null;
        $remainder = $chunk;

        // Extract header only once
        if (empty($state['header_done'])) {
            $headerPos = stripos($remainder, '<header');
            $endPos = stripos($remainder, '</header>');
            if ($headerPos !== false && $endPos !== false && $endPos > $headerPos) {
                $headerBlock = substr($remainder, $headerPos, $endPos - $headerPos + 9);
                $header = $this->parseHeader($headerBlock);
                $state['header_done'] = true;
                // remove header portion from stream so row parsing sees only records
                $remainder = substr($remainder, $endPos + 9);
            }
        }

        // Extract complete <row>..</row> blocks
        $rowsOut = [];
        $cursor = 0;
        $len = strlen($remainder);
        while (true) {
            $rowStart = stripos($remainder, '<row>', $cursor);
            if ($rowStart === false) {
                break;
            }
            $rowEnd = stripos($remainder, '</row>', $rowStart);
            if ($rowEnd === false) {
                // incomplete row, keep tail as remainder
                break;
            }
            $rowBlock = substr($remainder, $rowStart, $rowEnd - $rowStart + 6);
            $rowsOut[] = $this->parseRow($rowBlock);
            $cursor = $rowEnd + 6;
        }

        $rows = $rowsOut;
        $remainder = substr($remainder, $cursor);

        return [
            'rows' => $rows,
            'remainder' => $remainder,
            'header' => $header,
        ];
    }

    private function parseHeader(string $block): array
    {
        $cols = [];
        if (preg_match_all('/<col\s+[^>]*name="([^"]+)"[^>]*type="([^"]*)"[^>]*\/?>/i', $block, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $cols[] = ['name' => html_entity_decode($match[1], ENT_QUOTES | ENT_XML1, 'UTF-8'), 'type' => html_entity_decode($match[2], ENT_QUOTES | ENT_XML1, 'UTF-8')];
            }
        }
        return $cols;
    }

    private function parseRow(string $rowBlock): array
    {
        $row = [];
        if (preg_match_all('/<col\s+[^>]*name="([^"]+)"[^>]*?(null="null")?[^>]*>(.*?)<\/col>/is', $rowBlock, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $name = html_entity_decode($match[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
                $isNull = !empty($match[2]);
                if ($isNull) {
                    $row[$name] = null;
                } else {
                    $row[$name] = html_entity_decode($match[3], ENT_QUOTES | ENT_XML1, 'UTF-8');
                }
            }
        }
        return $row;
    }
}