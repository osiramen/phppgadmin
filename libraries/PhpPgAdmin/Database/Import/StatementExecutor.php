<?php

namespace PhpPgAdmin\Database\Import;

use Exception;
use PhpPgAdmin\Database\Import\Exception\StatementExecutionException;

/**
 * Executes individual SQL statements with classification, policy checks,
 * logging, and error handling.
 */
class StatementExecutor
{
    /** @var LogCollector */
    private $logs;
    /** @var array */
    private $state;
    private $pg;
    /** @var bool */
    private $isSuper;
    /** @var string */
    private $currentUser;
    /** @var array */
    private $options;
    private $allowCategory;

    public function __construct(
        LogCollector $logs,
        array &$state,
        $pg,
        bool $isSuper,
        string $currentUser = '',
        array $options = [],
        ?callable $allowCategory = null
    ) {
        $this->logs = $logs;
        $this->state = &$state;
        $this->pg = $pg;
        $this->isSuper = $isSuper;
        $this->currentUser = $currentUser;
        $this->options = $options;
        $this->allowCategory = $allowCategory ?? function () {
            return true;
        };
    }

    /**
     * Execute a single statement with classification, policy enforcement, and logging.
     *
     * @throws StatementExecutionException when execution fails and error_mode is abort.
     * @return bool true if executed; false if skipped/queued/deferred.
     */
    public function execute(string $statement): bool
    {
        $stmtTrim = trim($statement);
        if ($stmtTrim === '') {
            return false;
        }

        $category = StatementClassifier::classify($stmtTrim, $this->currentUser);

        switch ($category) {
            case 'self_affecting':
                return $this->handleSelfAffecting($stmtTrim);
            case 'data':
                return $this->handleData($stmtTrim);
            case 'drop':
                return $this->handleDrop($stmtTrim);
            case 'ownership_change':
                return $this->handleOwnershipChange($stmtTrim);
            case 'rights':
                return $this->handleRights($stmtTrim);
            default:
                return $this->handlePoliciesAndExecute($stmtTrim, $category);
        }
    }

    private function handleSelfAffecting(string $stmt): bool
    {
        $defer = $this->options['defer_self'] ?? true;
        if (!$defer) {
            if ($this->isSuper || ($this->state['scope'] ?? '') === 'server') {
                return $this->executeStatement($stmt);
            }
            $this->state['deferred'][] = $stmt;
            $this->logs->addDeferred('Self-affecting statement deferred (not superuser)', $stmt);
            return false;
        }

        $this->state['deferred'][] = $stmt;
        $this->logs->addDeferred('Self-affecting statement deferred', $stmt);
        return false;
    }

    private function handleData(string $stmt): bool
    {
        if (empty($this->options['data'])) {
            $this->logs->addSkipped('Data import disabled', $stmt, 'data_disabled', 'data');
            return false;
        }

        if (!empty($this->options['truncate'])) {
            $this->truncateTargetTable($stmt);
        }

        return $this->executeStatement($stmt);
    }

    private function handleDrop(string $stmt): bool
    {
        if (empty($this->options['allow_drops'])) {
            $this->logs->addBlocked('DROP statements not allowed', $stmt, 'drops_not_allowed');
            return false;
        }

        return $this->executeStatement($stmt);
    }

    private function handleOwnershipChange(string $stmt): bool
    {
        if (empty($this->options['ownership'])) {
            $this->logs->addSkipped('Ownership changes disabled', $stmt, 'ownership_disabled');
            return false;
        }

        $this->state['ownership_queue'][] = $stmt;
        $this->logs->addQueuedOwnership('Ownership statement queued', $stmt);
        return false;
    }

    private function handleRights(string $stmt): bool
    {
        if (empty($this->options['rights'])) {
            $this->logs->addSkipped('Rights statements disabled', $stmt, 'rights_disabled');
            return false;
        }

        $this->state['rights_queue'][] = $stmt;
        $this->logs->addQueuedRights('Rights statement queued', $stmt);
        return false;
    }

    private function handlePoliciesAndExecute(string $stmt, string $category): bool
    {
        if (preg_match('/^\s*CREATE\s+SCHEMA\b/i', $stmt) && empty($this->options['schema_create'])) {
            $this->logs->addSkipped('Schema creation disabled', $stmt, 'schema_create_disabled');
            return false;
        }

        if (preg_match('/^\s*(CREATE|ALTER|DROP)\s+(ROLE|USER)\b/i', $stmt) && empty($this->options['roles'])) {
            $this->logs->addSkipped('Role operations disabled', $stmt, 'roles_disabled');
            return false;
        }

        if (preg_match('/^\s*(CREATE|ALTER|DROP)\s+TABLESPACE\b/i', $stmt) && empty($this->options['tablespaces'])) {
            $this->logs->addSkipped('Tablespace operations disabled', $stmt, 'tablespaces_disabled');
            return false;
        }

        if (!call_user_func($this->allowCategory, $category)) {
            $this->logs->addSkipped('Statement category not allowed', $stmt, null, $category);
            return false;
        }

        return $this->executeStatement($stmt);
    }

    private function executeStatement(string $stmt): bool
    {
        $err = $this->pg->execute($stmt);
        if ($err !== 0) {
            $errorMsg = '';
            if (isset($this->pg->conn->_connectionID)) {
                $errorMsg = pg_last_error($this->pg->conn->_connectionID) ?: '';
            }
            $this->logs->addError('Statement execution failed' . ($errorMsg ? ': ' . $errorMsg : ''), $stmt, $err);
            $errorMode = $this->options['error_mode'] ?? 'abort';
            if ($errorMode === 'abort') {
                throw new StatementExecutionException($stmt, $err, $errorMsg);
            }
            return false;
        }

        $this->logs->addSuccess('Statement executed', $stmt);
        return true;
    }

    private function truncateTargetTable(string $stmt): void
    {
        $rawTable = null;
        if (preg_match('/^\s*INSERT\s+INTO\s+([^\s(]+)/i', $stmt, $m) || preg_match('/^\s*COPY\s+([^\s(]+)/i', $stmt, $m)) {
            $rawTable = $m[1];
        }

        if ($rawTable === null) {
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
            $schema = ($this->state['scope'] ?? '') === 'schema' ? ($this->state['scope_ident'] ?? '') : null;
            $table = $parts[0];
        } else {
            $table = array_pop($parts);
            $schema = array_pop($parts);
        }

        $fullName = $schema ? ($schema . '.' . $table) : $table;
        if (in_array($fullName, $this->state['truncated_tables'] ?? [], true)) {
            return;
        }

        $quoteIdent = function ($name) {
            return '"' . str_replace('"', '""', $name) . '"';
        };

        if ($schema) {
            $ident = $quoteIdent($schema) . '.' . $quoteIdent($table);
        } else {
            $ident = $quoteIdent($table);
        }

        $terr = $this->pg->execute('TRUNCATE TABLE ' . $ident);
        if ($terr !== 0) {
            $errorMsg = '';
            if (isset($this->pg->conn->_connectionID)) {
                $errorMsg = pg_last_error($this->pg->conn->_connectionID) ?: '';
            }
            $this->logs->addError('TRUNCATE failed' . ($errorMsg ? ': ' . $errorMsg : ''), null, $terr);
            return;
        }

        $this->logs->addTruncated('Table truncated before data import', $fullName);
        $this->state['truncated_tables'][] = $fullName;
    }
}
