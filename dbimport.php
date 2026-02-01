<?php

use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Database\Import\SqlParser;
use PhpPgAdmin\Database\Actions\RoleActions;
use PhpPgAdmin\Database\Import\LogCollector;
use PhpPgAdmin\Database\Import\ImportExecutor;
use PhpPgAdmin\Database\Import\CopyStreamHandler;
use PhpPgAdmin\Database\Import\SessionSettingsApplier;
use PhpPgAdmin\Database\Import\Exception\CopyException;

// dbimport.php
// API endpoint for chunked file upload and import processing
// This is an API endpoint that expects JSON requests and returns JSON responses.
// Authentication is checked via session (user must be logged in via main app).

// After bootstrap, check if we have authentication
// If $_server_info is not set, the user was redirected to login and this won't execute
// But we want to catch the case where server param is missing
if (!isset($_REQUEST['server'])) {
    header('Content-Type: application/json');
    http_response_code(400);
    echo json_encode(['error' => 'server parameter required']);
    exit;
}

require_once __DIR__ . '/libraries/bootstrap.php';

/**
 * New streaming import handler: stateless per-request chunk processing.
 * Client sends raw (optionally gzip) chunk and query params:
 * - offset: absolute uncompressed offset in original stream where new data starts
 * - remainder_len: number of bytes of remainder prepended to this chunk (default 0)
 * - skip: optional integer number of statements to skip before executing
 * Returns JSON: { offset, remainder, errors, logEntries }
 */
function handle_process_chunk_stream(): void
{
    header('Content-Type: application/json');

    ini_set('html_errors', '0');

    AppContainer::set('throw_on_sql_error', true);

    try {
        $misc = AppContainer::getMisc();
        $pg = AppContainer::getPostgres();

        // Identify this import stream so multiple uploads can run in parallel
        // without stomping each other's session state.
        $importSessionId = isset($_REQUEST['import_session_id']) ?? null;
        if (empty($importSessionId)) {
            http_response_code(400);
            echo json_encode(['error' => 'import_session_id parameter required']);
            return;
        }

        // Parameters
        $baseOffset = isset($_REQUEST['offset']) ? (int) $_REQUEST['offset'] : 0;
        $remainderLen = isset($_REQUEST['remainder_len']) ? max(0, (int) $_REQUEST['remainder_len']) : 0;
        $skipCount = isset($_REQUEST['skip']) ? max(0, (int) $_REQUEST['skip']) : 0;
        $eof = !empty($_REQUEST['eof']);

        // Initialize namespaced session storage for stream imports.
        if (!isset($_SESSION['stream_import'])) {
            $_SESSION['stream_import'] = [];
        }

        if (!isset($_SESSION['stream_import'][$importSessionId]) || $baseOffset === 0) {
            $_SESSION['stream_import'][$importSessionId] = [
                'copy_active' => false,
                'copy_header' => '',
                'truncated_tables' => [],
                'deferred' => [],
                'home_database' => '',
                'home_schema' => '',
                'current_database' => '',
                'current_schema' => '',
                'active_database' => '',
                'pending_database' => '',        // Database to switch to before next statement
                'cached_settings' => [],
                'last_applied_db' => null,
                'encoding' => '',
                'standard_conforming_strings' => true,
            ];
        }
        $streamState = &$_SESSION['stream_import'][$importSessionId];

        // Validate server/session context (ensures user is authenticated)
        $serverInfo = $misc->getServerInfo();
        if (!$serverInfo) {
            http_response_code(401);
            echo json_encode(['error' => 'Not authenticated']);
            return;
        }

        $logCollector = new LogCollector(true);

        // Optional first-request options (can be sent every time; we ignore if unused)
        $options = [
            'stop_on_error' => !empty($_REQUEST['opt_stop_on_error']),
            'roles' => !empty($_REQUEST['opt_roles']),
            'tablespaces' => !empty($_REQUEST['opt_tablespaces']),
            'databases' => !empty($_REQUEST['opt_databases']),
            'schema_create' => !empty($_REQUEST['opt_schema_create']),
            'data' => !empty($_REQUEST['opt_data']),
            'truncate' => !empty($_REQUEST['opt_truncate']),
            'ownership' => !empty($_REQUEST['opt_ownership']),
            'rights' => !empty($_REQUEST['opt_rights']),
            'defer_self' => !empty($_REQUEST['opt_defer_self']),
            'allow_drops' => !empty($_REQUEST['opt_allow_drops']),
            'ignore_connect' => !empty($_REQUEST['opt_ignore_connect']),
            'verbose' => !empty($_REQUEST['opt_verbose']),
            // Running inside the streaming chunk handler
            'streaming' => true,
        ];

        // Read request body (binary)
        $raw = file_get_contents('php://input');
        if ($raw === false) {
            http_response_code(400);
            echo json_encode(['error' => 'No input']);
            return;
        }

        // Calculate checksum of received data if client provided one
        $clientHash = $_REQUEST['chunk_hash'] ?? null;
        if ($clientHash !== null) {
            $serverHash = hash('fnv1a64', $raw);
            if ($serverHash !== $clientHash) {
                http_response_code(400);
                echo json_encode([
                    'error' => 'Checksum mismatch: chunk corrupted during transmission',
                    'expected' => $clientHash,
                    'received' => $serverHash,
                ]);
                return;
            }
        }

        // Detect gzip magic bytes 0x1F 0x8B
        $decoded = $raw;
        if (strlen($raw) >= 2) {
            $b0 = ord($raw[0]);
            $b1 = ord($raw[1]);
            if ($b0 === 0x1F && $b1 === 0x8B) {
                if (function_exists('gzdecode')) {
                    $tmp = @gzdecode($raw);
                    if ($tmp !== false) {
                        $decoded = $tmp;
                    }
                }
            }
        }

        // Reset per-import state on first chunk
        if ($baseOffset === 0 && $remainderLen === 0) {
            $streamState['truncated_tables'] = [];
            $streamState['deferred'] = [];
            $streamState['ownership_queue'] = [];
            $streamState['rights_queue'] = [];
            $streamState['cached_settings'] = [];
            $streamState['last_applied_db'] = null;
            if ($streamState['home_database'] === '') {
                $streamState['home_database'] = $_REQUEST['database'] ?? ($serverInfo['database'] ?? '');
            }
            if ($streamState['home_schema'] === '') {
                $streamState['home_schema'] = $_REQUEST['schema'] ?? '';
            }
            if ($streamState['current_database'] === '') {
                $streamState['current_database'] = $streamState['home_database'];
            }
            if ($streamState['current_schema'] === '') {
                $streamState['current_schema'] = $streamState['home_schema'];
            }
            $streamState['active_database'] = $streamState['current_database'];
        }

        $inCopy = !empty($streamState['copy_active']);
        $copyHeader = $streamState['copy_header'] ?? '';
        $truncatedTables = &$streamState['truncated_tables']; // Reference for persistence

        $statements = [];
        $remainder = '';
        $errors = 0;

        // Log import options on first chunk
        if ($baseOffset === 0 && $remainderLen === 0) {
            $logCollector->addInfo('Import started. Options: ' . json_encode([
                'truncate' => $options['truncate'],
                'data' => $options['data'],
                'stop_on_error' => $options['stop_on_error'],
            ]));
        }

        $isIgnorableTail = function (string $tail): bool {
            $len = strlen($tail);
            $i = 0;
            while ($i < $len) {
                // whitespace
                $ch = $tail[$i];
                if ($ch === ' ' || $ch === "\t" || $ch === "\r" || $ch === "\n" || $ch === "\f") {
                    $i++;
                    continue;
                }

                // line comment
                if ($ch === '-' && ($i + 1) < $len && $tail[$i + 1] === '-') {
                    $nl = strpos($tail, "\n", $i + 2);
                    if ($nl === false) {
                        return true;
                    }
                    $i = $nl + 1;
                    continue;
                }

                // block comment
                if ($ch === '/' && ($i + 1) < $len && $tail[$i + 1] === '*') {
                    $end = strpos($tail, '*/', $i + 2);
                    if ($end === false) {
                        return false;
                    }
                    $i = $end + 2;
                    continue;
                }

                // Anything else is meaningful SQL
                return false;
            }
            return true;
        };

        $settingsApplier = new SessionSettingsApplier(
            $logCollector,
            $pg->getMajorVersion()
        );
        if (!empty($streamState['cached_settings'])) {
            $settingsApplier->setCachedSettings($streamState['cached_settings']);
        }

        $parseConnectMeta = function (string $line): ?string {
            $trim = trim($line);
            if (!preg_match('/^\\\\c(?:onnect)?\s+(.+)$/i', $trim, $m)) {
                return null;
            }
            $arg = trim($m[1]);
            if ($arg === '') {
                return null;
            }
            if (($arg[0] === '"' && substr($arg, -1) === '"') || ($arg[0] === "'" && substr($arg, -1) === "'")) {
                $arg = substr($arg, 1, -1);
                $arg = str_replace(['\\"', "\\'", '\\\\'], ['"', "'", '\\'], $arg);
            } else {
                $arg = strtok($arg, " \t");
            }
            return $arg;
        };

        $parseEncodingMeta = function (string $line): ?string {
            $trim = trim($line);
            if (preg_match('/^\\encoding\s+([A-Za-z0-9_-]+)/i', $trim, $m)) {
                return $m[1];
            }
            return null;
        };

        $runStatements = function (array $stmts) use (&$pg, &$streamState, $options, &$errors, $logCollector) {
            if (empty($stmts)) {
                return;
            }
            $scope = $_REQUEST['scope'] ?? 'database';
            $scopeIdent = $_REQUEST['scope_ident'] ?? '';
            $execState = [
                'scope' => $scope,
                'scope_ident' => $scopeIdent,
                'ownership_queue' => &$streamState['ownership_queue'],
                'rights_queue' => &$streamState['rights_queue'],
                'deferred' => &$streamState['deferred'],
                'truncated_tables' => &$streamState['truncated_tables'],
            ];
            AppContainer::set('quiet_sql_error_handling', true);
            $roleActions = new RoleActions($pg);
            $isSuper = $roleActions->isSuperUser();
            ImportExecutor::executeStatementsBatch(
                $stmts,
                $options,
                $execState,
                $pg,
                $scope,
                $isSuper,
                function () {
                    return true;
                },
                $logCollector,
                $errors
            );
            AppContainer::set('quiet_sql_error_handling', false);
        };

        $executeDeferred = function () use (&$pg, &$streamState, $options, &$errors, $logCollector) {
            if (empty($streamState['deferred'])) {
                return;
            }

            $stmts = $streamState['deferred'];
            $streamState['deferred'] = [];

            $scope = $_REQUEST['scope'] ?? 'database';
            $scopeIdent = $_REQUEST['scope_ident'] ?? '';
            $optsToPass = $options;
            // Force execution of self-affecting statements now.
            $optsToPass['defer_self'] = false;

            $execState = [
                'scope' => $scope,
                'scope_ident' => $scopeIdent,
                'ownership_queue' => &$streamState['ownership_queue'],
                'rights_queue' => &$streamState['rights_queue'],
                'deferred' => [],
                'truncated_tables' => &$streamState['truncated_tables'],
            ];

            AppContainer::set('quiet_sql_error_handling', true);
            $roleActions = new RoleActions($pg);
            $isSuper = $roleActions->isSuperUser();
            ImportExecutor::executeStatementsBatch(
                $stmts,
                $optsToPass,
                $execState,
                $pg,
                $scope,
                $isSuper,
                function () {
                    return true;
                },
                $logCollector,
                $errors
            );
            $logCollector->addInfo('Deferred statements executed: ' . count($stmts));
            AppContainer::set('quiet_sql_error_handling', false);
        };

        // Execute queued ownership and rights statements (called on EOF)
        $executeQueuedStatements = function () use (&$pg, &$streamState, $options, &$errors, $logCollector) {
            $ownershipQueue = $streamState['ownership_queue'] ?? [];
            $rightsQueue = $streamState['rights_queue'] ?? [];

            if (empty($ownershipQueue) && empty($rightsQueue)) {
                return;
            }

            $scope = $_REQUEST['scope'] ?? 'database';
            $scopeIdent = $_REQUEST['scope_ident'] ?? '';

            AppContainer::set('quiet_sql_error_handling', true);

            // Execute ownership statements first
            if (!empty($ownershipQueue)) {
                $logCollector->addInfo('Executing ' . count($ownershipQueue) . ' queued ownership statements');
                foreach ($ownershipQueue as $stmt) {
                    try {
                        $status = $pg->execute($stmt);
                        if ($status < 0) {
                            $errors++;
                            $logCollector->addError('Ownership statement failed: ' . $stmt);
                        }
                    } catch (\Throwable $e) {
                        $errors++;
                        $logCollector->addError('Ownership statement failed: ' . $e->getMessage());
                        if (!empty($options['stop_on_error'])) {
                            break;
                        }
                    }
                }
                $streamState['ownership_queue'] = [];
            }

            // Execute rights statements
            if (!empty($rightsQueue)) {
                $logCollector->addInfo('Executing ' . count($rightsQueue) . ' queued rights statements');
                foreach ($rightsQueue as $stmt) {
                    try {
                        $status = $pg->execute($stmt);
                        if ($status < 0) {
                            $errors++;
                            $logCollector->addError('Rights statement failed: ' . $stmt);
                        }
                    } catch (\Throwable $e) {
                        $errors++;
                        $logCollector->addError('Rights statement failed: ' . $e->getMessage());
                        if (!empty($options['stop_on_error'])) {
                            break;
                        }
                    }
                }
                $streamState['rights_queue'] = [];
            }

            AppContainer::set('quiet_sql_error_handling', false);
        };

        $copyHandlerFactory = function () use ($logCollector, &$pg, &$streamState, $options) {
            $scope = $_REQUEST['scope'] ?? 'database';
            $scopeIdent = $_REQUEST['scope_ident'] ?? '';
            $schemaParam = $_REQUEST['schema'] ?? '';
            return new CopyStreamHandler($logCollector, $pg, $streamState, $options, $scope, $scopeIdent, is_string($schemaParam) ? $schemaParam : '');
        };

        $items = [];
        $shouldStop = false;
        // COPY streaming mode
        $copyTermPattern = "/\r?\n\\\.\r?\n/";
        if ($inCopy) {
            // If terminator present in this chunk, finish COPY and parse rest normally
            if (preg_match($copyTermPattern, $decoded, $m, PREG_OFFSET_CAPTURE)) {
                $pos = $m[0][1];
                $before = substr($decoded, 0, $pos); // data lines up to newline before terminator
                // Build a complete COPY for this chunk
                $dataSend = $before;
                if ($dataSend !== '' && substr($dataSend, -1) !== "\n") {
                    $dataSend .= "\n";
                }
                try {
                    $copyHandlerFactory()->stream($copyHeader, $dataSend);
                } catch (CopyException $e) {
                    $errors++;
                    $logCollector->addError($e->getMessage());
                    if ($options['stop_on_error']) {
                        $shouldStop = true;
                        $logCollector->addError('Import will stop due to COPY error (stop_on_error enabled)');
                    }
                }
                // Clear COPY state and continue with remainder after terminator
                $streamState['copy_active'] = false;
                $streamState['copy_header'] = '';
                $after = substr($decoded, $pos + strlen($m[0][0]));
                $split = SqlParser::parseFromString($after, '', false, !empty($streamState['standard_conforming_strings']));
                $items = $split['items'];
                $remainder = $split['remainder'];
                $streamState['standard_conforming_strings'] = !empty($split['standard_conforming_strings']);
            } else {
                // No terminator yet: send only complete lines in this chunk
                $lastNl = strrpos($decoded, "\n");
                if ($lastNl === false) {
                    // Keep all as remainder for next chunk
                    $remainder = $decoded;
                } else {
                    $dataSend = substr($decoded, 0, $lastNl + 1);
                    $tail = substr($decoded, $lastNl + 1);
                    try {
                        $copyHandlerFactory()->stream($copyHeader, $dataSend);
                    } catch (CopyException $e) {
                        $errors++;
                        $logCollector->addError($e->getMessage());
                        if ($options['stop_on_error']) {
                            $shouldStop = true;
                            $logCollector->addError('Import will stop due to COPY error (stop_on_error enabled)');
                        }
                    }
                    $remainder = $tail;
                }
            }
        } else {
            // Check if this chunk starts a COPY ... FROM stdin block without terminator present
            if (preg_match('/^\s*(COPY\b.*?FROM\s+stdin;\s*\r?\n)/si', $decoded, $hm, PREG_OFFSET_CAPTURE)) {
                $header = $hm[1][0];
                $headerEnd = $hm[1][1] + strlen($hm[1][0]);
                $rest = substr($decoded, $headerEnd);
                if (preg_match($copyTermPattern, $rest, $m, PREG_OFFSET_CAPTURE)) {
                    // Header and terminator both present in same chunk; let parser handle it fully
                    $split = SqlParser::parseFromString($decoded, '', false, !empty($streamState['standard_conforming_strings']));
                    $items = $split['items'];
                    $remainder = $split['remainder'];
                    $streamState['standard_conforming_strings'] = !empty($split['standard_conforming_strings']);
                } else {
                    // Activate COPY streaming and send complete lines from rest
                    $streamState['copy_active'] = true;
                    $streamState['copy_header'] = $header;
                    $lastNl = strrpos($rest, "\n");
                    if ($lastNl === false) {
                        $remainder = $rest; // no full line yet
                    } else {
                        $dataSend = substr($rest, 0, $lastNl + 1);
                        $tail = substr($rest, $lastNl + 1);
                        try {
                            $copyHandlerFactory()->stream($header, $dataSend);
                        } catch (CopyException $e) {
                            $errors++;
                            $logCollector->addError($e->getMessage());
                            if ($options['stop_on_error']) {
                                $shouldStop = true;
                                $logCollector->addError('Import will stop due to COPY error (stop_on_error enabled)');
                            }
                        }
                        $remainder = $tail;
                    }
                }
            } else {
                // Normal path: Parse statements using SqlParser with COPY and comment/meta handling
                $split = SqlParser::parseFromString($decoded, '', false, !empty($streamState['standard_conforming_strings']));
                $items = $split['items'];
                $remainder = $split['remainder'];
                $streamState['standard_conforming_strings'] = !empty($split['standard_conforming_strings']);
                // Note: If remainder starts with COPY, streaming will be activated AFTER
                // statements in $items are executed (see code after the foreach loop)
            }
        }

        // Helper function to switch database with deferred statement execution and settings re-application
        $switchDatabase = function (string $dbName) use (&$pg, &$streamState, $executeDeferred, $settingsApplier, $misc, &$errors, $logCollector) {
            if ($streamState['active_database'] === $dbName) {
                return true; // Already on this database
            }

            // Execute deferred self-affecting statements before leaving current database
            $executeDeferred();

            try {
                $pgTarget = $misc->getDatabaseAccessor($dbName);
                if ($pgTarget !== null) {
                    $pg = $pgTarget;
                    AppContainer::setPostgres($pg);
                    $streamState['active_database'] = $dbName;

                    // Re-apply cached settings to new connection
                    if (!empty($streamState['cached_settings'])) {
                        $errors += $settingsApplier->applySettings($pg);
                        $logCollector->addInfo('Re-applied ' . count($streamState['cached_settings']) . ' cached settings to database: ' . $dbName);
                    }
                    $streamState['last_applied_db'] = $dbName;
                    return true;
                }
            } catch (Throwable $e) {
                $errors++;
                $logCollector->addError('Failed to switch to database "' . $dbName . '": ' . $e->getMessage());
                return false;
            }
        };

        // Compute offset advance based on source bytes read, not executed statements.
        // New bytes read = payload length minus client-prepended remainder.
        $payloadLen = strlen($decoded);
        $newBytesRead = $payloadLen - $remainderLen;
        if ($newBytesRead < 0) {
            $newBytesRead = 0;
        }
        $absoluteOffset = $baseOffset + $newBytesRead;

        // Initialize home database on first chunk
        if ($baseOffset === 0 && $remainderLen === 0) {
            if ($streamState['home_database'] === '') {
                $streamState['home_database'] = $_REQUEST['database'] ?? ($serverInfo['database'] ?? '');
            }
            if ($streamState['home_schema'] === '') {
                $streamState['home_schema'] = $_REQUEST['schema'] ?? '';
            }
            if ($streamState['current_database'] === '') {
                $streamState['current_database'] = $streamState['home_database'];
            }
            if ($streamState['active_database'] === '') {
                $streamState['active_database'] = $streamState['home_database'];
            }
        }

        // Process items sequentially: meta-commands affect subsequent statements
        $itemsProcessed = 0;
        if (!empty($items)) {
            if ($skipCount > 0) {
                // Skip items as requested (used for retry after error)
                $items = array_slice($items, $skipCount);
            }

            // Track errors before processing to detect new errors in this chunk
            $errorsBefore = $errors;

            foreach ($items as $item) {
                if ($item['type'] === 'meta') {
                    // Process meta-command immediately
                    $metaLine = $item['content'];

                    // Handle \connect (unless ignored by option)
                    if (empty($options['ignore_connect'])) {
                        $connDb = $parseConnectMeta($metaLine);
                        if ($connDb !== null) {
                            $streamState['current_database'] = $connDb;
                            $streamState['pending_database'] = $connDb;
                            $logCollector->addInfo('Meta-command: \\connect ' . $connDb);
                        }
                    } else {
                        // Check if this is a \connect command (for logging purposes)
                        $connDb = $parseConnectMeta($metaLine);
                        if ($connDb !== null) {
                            $logCollector->addInfo('Meta-command: \\connect ' . $connDb . ' (ignored)');
                        }
                    }

                    // Handle \encoding
                    $enc = $parseEncodingMeta($metaLine);
                    if ($enc !== null) {
                        $streamState['encoding'] = $enc;
                        // Cache encoding as SET statement for re-application
                        $setEncoding = "SET client_encoding = '" . str_replace("'", "''", $enc) . "';";
                        $settingsApplier->collectFromStatement($setEncoding, $streamState);
                        $logCollector->addInfo('Meta-command: \\encoding ' . $enc);
                    }

                    $itemsProcessed++;
                } elseif ($item['type'] === 'statement') {
                    // Check if there's a pending database switch from meta-command
                    if (
                        !empty($streamState['pending_database']) &&
                        $streamState['active_database'] !== $streamState['pending_database']
                    ) {
                        if (!$switchDatabase($streamState['pending_database'])) {
                            // Failed to switch database: stop processing further statements
                            $shouldStop = true;
                            $logCollector->addFatal('Import stopped: failed to switch database to "' . $streamState['pending_database'] . '"');
                            break;
                        }
                        $streamState['pending_database'] = ''; // Clear after switch
                    }

                    // Collect settings from this statement before executing
                    // Returns false if statement should be skipped (unknown/unsupported SET command)
                    $shouldExecute = $settingsApplier->collectFromStatement($item['content'], $streamState);

                    // Execute the statement only if it wasn't skipped
                    if ($shouldExecute) {
                        try {
                            $runStatements([$item['content']]);
                            $itemsProcessed++;
                        } catch (\Throwable $e) {
                            // Error already counted in executeStatementsBatch
                            $logCollector->addError('Statement execution failed: ' . $e->getMessage());
                            // Exception thrown means stop_on_error was triggered
                            if ($options['stop_on_error']) {
                                break; // Stop processing remaining items in this chunk
                            }
                        }
                    } else {
                        // Statement was skipped (logged by collectFromStatement)
                        $itemsProcessed++;
                    }
                }
            }

            // Check if errors occurred and stop_on_error is enabled
            if ($options['stop_on_error'] && $errors > $errorsBefore) {
                $shouldStop = true;
                $errorCount = $errors - $errorsBefore;
                $logCollector->addError('Import stopped: ' . $errorCount . ' SQL error(s) occurred in this chunk (stop_on_error enabled)');
            }

            // After all statements are executed, check if remainder starts with COPY
            // Now it's safe to activate streaming since CREATE TABLE etc. have run
            if (!$shouldStop && $remainder !== '' && !$inCopy && preg_match('/^\s*(COPY\b.*?FROM\s+stdin;\s*\r?\n)/si', $remainder, $copyMatch, PREG_OFFSET_CAPTURE)) {
                $header = $copyMatch[1][0];
                $headerEnd = $copyMatch[1][1] + strlen($copyMatch[1][0]);
                $rest = substr($remainder, $headerEnd);

                // Check if terminator is present in remainder
                if (!preg_match($copyTermPattern, $rest)) {
                    // Activate COPY streaming and send complete lines
                    $streamState['copy_active'] = true;
                    $streamState['copy_header'] = $header;
                    $lastNl = strrpos($rest, "\n");
                    if ($lastNl === false) {
                        $remainder = $rest; // no full line yet
                    } else {
                        $dataSend = substr($rest, 0, $lastNl + 1);
                        $tail = substr($rest, $lastNl + 1);
                        try {
                            $copyHandlerFactory()->stream($header, $dataSend);
                        } catch (CopyException $e) {
                            $errors++;
                            $logCollector->addError($e->getMessage());
                            if ($options['stop_on_error']) {
                                $shouldStop = true;
                                $logCollector->addError('Import will stop due to COPY error (stop_on_error enabled)');
                            }
                        }
                        $remainder = $tail;
                    }
                }
                // else: Full COPY with terminator - leave as-is, next chunk will handle it via SqlParser
            }

            $logCollector->addInfo('Chunk processed: offset=' . $absoluteOffset . ' items=' . $itemsProcessed . ' remainder=' . strlen($remainder));
            if (strlen($remainder) > 10000) {
                $copyStatus = !empty($streamState['copy_active']) ? ' (COPY streaming active)' : '';
                $logCollector->addWarning('Large remainder detected: ' . strlen($remainder) . ' bytes' . $copyStatus . '. Preview: ' . substr($remainder, 0, 200));
            }
        }

        // If client indicates EOF: either drop ignorable tail or surface a clear error.
        // NOTE: We do not attempt to "reconstruct" SQL here. The client will receive the exact
        // remainder string and can retry by prepending it (also required for future compressed streaming).
        if ($eof && !empty($remainder) && !$inCopy) {
            if ($isIgnorableTail($remainder)) {
                $remainder = '';
            } else {
                $errors++;
                $logCollector->addError('Unexpected end of file: trailing SQL could not be parsed/executed. remainder_len=' . strlen($remainder) . ' preview=' . substr($remainder, 0, 200));
            }
        }

        // On EOF, flush any remaining deferred statements for the last database.
        if ($eof && !$inCopy) {
            $executeDeferred();
            $executeQueuedStatements();
        }

        // Return the *actual* remainder string.
        // The remainder is not guaranteed to be a literal suffix of the uploaded payload
        // (SqlParser may normalize/trim while streaming INSERT ... VALUES), so returning only
        // remainder_len and reconstructing client-side is not reliable.
        echo json_encode([
            'offset' => $absoluteOffset,
            'remainder_len' => strlen($remainder),
            'remainder' => $remainder,
            'errors' => $errors,
            'stop' => $shouldStop,
            'logEntries' => $logCollector->getLogsWithSummary(),
        ]);
    } catch (Throwable $t) {
        http_response_code(500);
        echo json_encode(['error' => 'process_chunk failed', 'detail' => $t->getMessage()]);
    }
}

// Helper handlers for remaining actions
// Main action dispatcher (streaming-only)

$action = $_REQUEST['action'] ?? 'process_chunk';

switch ($action) {
    case 'process_chunk':
        //usleep(1000000); // 0.1s
        handle_process_chunk_stream();
        break;
    default:
        http_response_code(400);
        echo 'Unknown action';
}
