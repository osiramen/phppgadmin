<?php

namespace PhpPgAdmin\Database\Import;

/**
 * Tracks and reapplies session-level SET statements across reconnects.
 */
class SessionSettingsApplier
{
    /** @var LogCollector */
    private $logs;
    /** @var array */
    private $cachedSettings = [];
    /** @var array */
    private $seenSettings = [];

    /** @var array */
    private $allowPatterns = [
        '/^SET\s+session_replication_role\s*=\s*/i',
        '/^SET\s+statement_timeout\s*=\s*/i',
        '/^SET\s+lock_timeout\s*=\s*/i',
        '/^SET\s+idle_in_transaction_session_timeout\s*=\s*/i',
        '/^SET\s+transaction_timeout\s*=\s*/i',
        '/^SET\s+client_encoding\s*=\s*/i',
        '/^SET\s+standard_conforming_strings\s*/i',
        '/^SET\s+search_path\s+/i',
        '/^SELECT\s+pg_catalog\.set_config\(\s*\'search_path\'/i',
    ];

    public function __construct(LogCollector $logs)
    {
        $this->logs = $logs;
    }

    public function collectFromStatements(array $statements, array &$state): void
    {
        foreach ($statements as $stmt) {
            $stmtTrim = trim($stmt);
            if ($stmtTrim === '') {
                continue;
            }

            if (!$this->isAllowedSet($stmtTrim)) {
                continue;
            }

            if (substr($stmtTrim, -1) !== ';') {
                $stmtTrim .= ';';
            }

            // Track search_path and encoding side effects
            if (preg_match('/^SET\s+search_path\s+TO\s+(.+);?$/i', $stmtTrim, $m)) {
                $first = trim(explode(',', $m[1])[0]);
                $state['current_schema'] = trim($first, " \"'{}");
            } elseif (preg_match('/set_config\(\s*\'search_path\'\s*,\s*\'([^\']*)\'/i', $stmtTrim, $m)) {
                $parts = explode(',', $m[1]);
                $state['current_schema'] = trim($parts[0], " \"'{}");
            }

            if (preg_match('/^SET\s+client_encoding\s*=\s*\'?([A-Za-z0-9_-]+)\'?/i', $stmtTrim, $m)) {
                $state['encoding'] = $m[1];
            }

            $norm = strtolower(preg_replace('/\s+/', ' ', $stmtTrim));
            if (!isset($this->seenSettings[$norm])) {
                $this->cachedSettings[] = $stmtTrim;
                $this->seenSettings[$norm] = true;
            }
        }

        // Persist cached settings back to shared state so streaming requests keep them.
        $state['cached_settings'] = $this->cachedSettings;
    }

    public function applySettings($pg): int
    {
        $errorCount = 0;
        foreach ($this->cachedSettings as $sql) {
            $sqlTrim = trim($sql);
            if ($sqlTrim === '') {
                continue;
            }

            try {
                $res = $pg->execute($sqlTrim);
                if ($res !== 0) {
                    $this->logs->addError('Session setting failed: ' . $sqlTrim);
                    $errorCount++;
                } else {
                    $this->logs->addInfo('Session setting applied: ' . substr($sqlTrim, 0, 120));
                }
            } catch (\Throwable $e) {
                $this->logs->addError('Session setting exception: ' . $sqlTrim . ' detail=' . $e->getMessage());
                $errorCount++;
            }
        }
        return $errorCount;
    }

    public function getCachedSettings(): array
    {
        return $this->cachedSettings;
    }

    public function setCachedSettings(array $settings): void
    {
        $this->cachedSettings = $settings;
        $this->seenSettings = [];
        foreach ($settings as $s) {
            $norm = strtolower(preg_replace('/\s+/', ' ', trim($s)));
            $this->seenSettings[$norm] = true;
        }
    }

    private function isAllowedSet(string $stmt): bool
    {
        foreach ($this->allowPatterns as $pattern) {
            if (preg_match($pattern, $stmt)) {
                return true;
            }
        }
        return false;
    }
}
