<?php

namespace PhpPgAdmin\Database\Import;

use PhpPgAdmin\Database\Import\Exception\CopyException;

/**
 * Handles streaming COPY data using pg_put_line/pg_end_copy.
 */
class CopyStreamHandler
{
    /** @var LogCollector */
    private $logs;
    private $pg;
    /** @var string */
    private $scope;
    /** @var string */
    private $scopeIdent;
    /** @var string */
    private $schema;

    /** @var array */
    private $state;

    /** @var array */
    private $options;

    public function __construct(
        LogCollector $logs,
        $pg,
        array &$state = [],
        array $options = [],
        string $scope = 'database',
        string $scopeIdent = '',
        string $schema = ''
    ) {
        $this->logs = $logs;
        $this->pg = $pg;
        $this->state = &$state;
        $this->options = $options;
        $this->scope = $scope;
        $this->scopeIdent = $scopeIdent;
        $this->schema = $schema;
    }

    /**
     * Stream COPY data.
     *
     * @throws CopyException on failures.
     */
    public function stream(string $copyHeader, string $dataSend): void
    {
        $conn = $this->pg->conn->_connectionID ?? null;
        if ($conn === null) {
            throw new CopyException('No DB connection for COPY stream');
        }

        if (!function_exists('pg_put_line') || !function_exists('pg_end_copy')) {
            throw new CopyException('pg_put_line/pg_end_copy not available');
        }

        $this->setSearchPath($conn);

        // Respect truncate option for COPY blocks too.
        if (!empty($this->options['truncate'])) {
            $this->maybeTruncateForCopyHeader($copyHeader);
        }

        $res = @pg_query($conn, $copyHeader);
        if ($res === false) {
            $err = pg_last_error($conn) ?: 'unknown';
            throw new CopyException('COPY pg_query error: ' . $err);
        }

        $linesSent = $this->sendDataLines($conn, $dataSend);

        $termOk = @pg_put_line($conn, "\\.\n");
        if ($termOk === false) {
            $err = pg_last_error($conn) ?: 'unknown';
            throw new CopyException('COPY terminator pg_put_line failed: ' . $err);
        }

        $endOk = @pg_end_copy($conn);
        if ($endOk === false) {
            $err = pg_last_error($conn) ?: 'unknown';
            throw new CopyException('COPY pg_end_copy failed: ' . $err . ' lines_sent=' . $linesSent);
        }

        $this->logs->addInfo('COPY completed: bytes=' . strlen($dataSend) . ' lines_sent=' . $linesSent);
    }

    private function maybeTruncateForCopyHeader(string $copyHeader): void
    {
        if (!isset($this->state['truncated_tables']) || !is_array($this->state['truncated_tables'])) {
            $this->state['truncated_tables'] = [];
        }

        $rawTable = null;
        if (preg_match('/^\s*COPY\s+([^\s(]+)/i', $copyHeader, $m)) {
            $rawTable = $m[1];
        }
        if ($rawTable === null || $rawTable === '') {
            return;
        }

        $rawTable = trim($rawTable);
        $rawTable = preg_replace('/^"(.*)"$/', '$1', $rawTable);
        $parts = preg_split('/\./', $rawTable);
        $parts = array_map(function ($p) {
            $p = trim($p);
            return preg_replace('/^"(.*)"$/', '$1', $p);
        }, $parts);

        if (count($parts) === 1) {
            $schema = null;
            if ($this->scope === 'schema' && $this->scopeIdent !== '') {
                $schema = $this->scopeIdent;
            } elseif ($this->schema !== '') {
                $schema = $this->schema;
            }
            $table = $parts[0];
        } else {
            $table = array_pop($parts);
            $schema = array_pop($parts);
        }

        $fullName = $schema ? ($schema . '.' . $table) : $table;
        if (in_array($fullName, $this->state['truncated_tables'], true)) {
            return;
        }

        $quoteIdent = function ($name) {
            return '"' . str_replace('"', '""', $name) . '"';
        };

        $ident = $schema ? ($quoteIdent($schema) . '.' . $quoteIdent($table)) : $quoteIdent($table);
        $terr = $this->pg->execute('TRUNCATE TABLE ' . $ident);
        if ($terr !== 0) {
            $errMsg = '';
            if (isset($this->pg->conn->_connectionID)) {
                $errMsg = pg_last_error($this->pg->conn->_connectionID) ?: '';
            }
            $this->logs->addError('TRUNCATE failed' . ($errMsg ? ': ' . $errMsg : ''), null, $terr);
            return;
        }

        $this->logs->addTruncated('Table truncated before COPY', $fullName);
        $this->state['truncated_tables'][] = $fullName;
    }

    private function setSearchPath($conn): void
    {
        $schemaParts = [];
        if ($this->scope === 'schema' && $this->scopeIdent !== '') {
            $schemaParts[] = '"' . str_replace('"', '""', $this->scopeIdent) . '"';
        } elseif ($this->schema !== '') {
            $schemaParts[] = '"' . str_replace('"', '""', $this->schema) . '"';
        }
        $schemaParts[] = 'public';
        $schemaParts[] = 'pg_catalog';
        @pg_query($conn, 'SET search_path TO ' . implode(', ', $schemaParts));
    }

    private function sendDataLines($conn, string $dataSend): int
    {
        $pos = 0;
        $len = strlen($dataSend);
        $linesSent = 0;

        while ($pos < $len) {
            $nl = strpos($dataSend, "\n", $pos);
            if ($nl === false) {
                $line = substr($dataSend, $pos);
                $pos = $len;
            } else {
                $line = substr($dataSend, $pos, $nl - $pos + 1);
                $pos = $nl + 1;
            }

            $ok = @pg_put_line($conn, $line);
            if ($ok === false) {
                $err = pg_last_error($conn) ?: 'unknown';
                throw new CopyException('COPY pg_put_line failed: ' . $err . ' line_preview=' . substr($line, 0, 200));
            }

            $linesSent++;
        }

        return $linesSent;
    }
}
