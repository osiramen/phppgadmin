<?php

use PhpPgAdmin\Database\Actions\RoleActions;
use PhpPgAdmin\Database\Import\ImportExecutor;
use PhpPgAdmin\Database\Import\LogCollector;
use PhpPgAdmin\Database\Import\SessionSettingsApplier;
use PhpPgAdmin\Database\Import\CopyStreamHandler;
use PhpPgAdmin\Database\Import\Exception\CopyException;
use PhpPgAdmin\Core\AppContainer;

// dbimport.php
// API endpoint for chunked file upload and import processing
// This is an API endpoint that expects JSON requests and returns JSON responses.
// Authentication is checked via session (user must be logged in via main app).

require_once __DIR__ . '/libraries/bootstrap.php';

// After bootstrap, check if we have authentication
// If $_server_info is not set, the user was redirected to login and this won't execute
// But we want to catch the case where server param is missing
if (!isset($_REQUEST['server'])) {
    header('Content-Type: application/json');
    http_response_code(400);
    echo json_encode(['error' => 'server parameter required']);
    exit;
}

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

    try {
        $misc = AppContainer::getMisc();
        $pg = AppContainer::getPostgres();

        // Identify this import stream so multiple uploads can run in parallel without
        // stomping each other's session state. The client sends import_session_id; if
        // missing, fall back to a default bucket for backward compatibility.
        $importSessionId = isset($_REQUEST['import_session_id']) && $_REQUEST['import_session_id'] !== ''
            ? (string) $_REQUEST['import_session_id']
            : 'default';

        // Initialize namespaced session storage for stream imports.
        if (!isset($_SESSION['stream_import']) || !is_array($_SESSION['stream_import'])) {
            $_SESSION['stream_import'] = [];
        }
        // Back-compat: migrate legacy flat structure into default bucket.
        if (!isset($_SESSION['stream_import']['default']) && isset($_SESSION['stream_import']['copy_active'])) {
            $_SESSION['stream_import'] = ['default' => $_SESSION['stream_import']];
        }

        if (!isset($_SESSION['stream_import'][$importSessionId])) {
            $_SESSION['stream_import'][$importSessionId] = [
                'copy_active' => false,
                'copy_header' => '',
                'truncated_tables' => [],
                'home_database' => '',
                'home_schema' => '',
                'current_database' => '',
                'current_schema' => '',
                'active_database' => '',
                'cached_settings' => [],
                'last_applied_db' => null,
                'encoding' => '',
            ];
        }
        $streamState = &$_SESSION['stream_import'][$importSessionId];

        // If the client specified a target database for this import, ensure
        // our connection is to that database. This avoids cases where the
        // global connection points to the server's default DB (e.g. 'postgres').
        $targetDb = $_REQUEST['database'] ?? null;
        if (!empty($targetDb)) {
            try {
                $pgTarget = $misc->getDatabaseAccessor($targetDb);
                if ($pgTarget !== null) {
                    $pg = $pgTarget;
                    AppContainer::setPostgres($pg);
                    // Switched database for this import session
                }
            } catch (Throwable $e) {
                // Reconnection failed — report and abort
                http_response_code(500);
                echo json_encode(['error' => 'Failed to connect to target database', 'detail' => $e->getMessage()]);
                return;
            }
        }

        // Validate server/session context (ensures user is authenticated)
        $serverInfo = $misc->getServerInfo();
        if (!$serverInfo) {
            http_response_code(401);
            echo json_encode(['error' => 'Not authenticated']);
            return;
        }

        // Parameters
        $baseOffset = isset($_REQUEST['offset']) ? (int) $_REQUEST['offset'] : 0;
        $remainderLen = isset($_REQUEST['remainder_len']) ? max(0, (int) $_REQUEST['remainder_len']) : 0;
        $skipCount = isset($_REQUEST['skip']) ? max(0, (int) $_REQUEST['skip']) : 0;
        $eof = !empty($_REQUEST['eof']);

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

        $settingsApplier = new SessionSettingsApplier($logCollector);
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
            $optsToPass = $options;
            $optsToPass['error_mode'] = ($options['stop_on_error'] ? 'abort' : 'log');
            $execState = [
                'scope' => $scope,
                'scope_ident' => $scopeIdent,
                'ownership_queue' => [],
                'rights_queue' => [],
                'deferred' => [],
                'truncated_tables' => &$streamState['truncated_tables'],
            ];
            $GLOBALS['phppgadmin_import_quiet'] = true;
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
            unset($GLOBALS['phppgadmin_import_quiet']);
        };

        $copyHandlerFactory = function () use ($logCollector, &$pg, &$streamState, $options) {
            $scope = $_REQUEST['scope'] ?? 'database';
            $scopeIdent = $_REQUEST['scope_ident'] ?? '';
            $schemaParam = $_REQUEST['schema'] ?? '';
            return new CopyStreamHandler($logCollector, $pg, $streamState, $options, $scope, $scopeIdent, is_string($schemaParam) ? $schemaParam : '');
        };

        $metaCommands = [];

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
                }
                // Clear COPY state and continue with remainder after terminator
                $streamState['copy_active'] = false;
                $streamState['copy_header'] = '';
                $after = substr($decoded, $pos + strlen($m[0][0]));
                $split = \PhpPgAdmin\Database\Import\SqlParser::parseFromString($after, '');
                $statements = $split['statements'];
                $remainder = $split['remainder'];
                $metaCommands = $split['meta'] ?? [];
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
                    $split = \PhpPgAdmin\Database\Import\SqlParser::parseFromString($decoded, '');
                    $statements = $split['statements'];
                    $remainder = $split['remainder'];
                    $metaCommands = $split['meta'] ?? [];
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
                        }
                        $remainder = $tail;
                    }
                }
            } else {
                // Normal path: Parse statements using SqlParser with COPY and comment/meta handling
                $split = \PhpPgAdmin\Database\Import\SqlParser::parseFromString($decoded, '');
                $statements = $split['statements'];
                $remainder = $split['remainder'];
                $metaCommands = $split['meta'] ?? [];
            }
        }

        // Track meta-commands and session-level settings for this import session
        if (!empty($metaCommands)) {
            foreach ($metaCommands as $metaLine) {
                $connDb = $parseConnectMeta($metaLine);
                if ($connDb !== null) {
                    $streamState['current_database'] = $connDb;
                }
                $enc = $parseEncodingMeta($metaLine);
                if ($enc !== null) {
                    $streamState['encoding'] = $enc;
                }
            }
        }

        $settingsApplier->collectFromStatements($statements, $streamState);

        // Determine which database to operate on: prefer meta \\connect, else request/home
        $desiredDb = $streamState['current_database'];
        if ($desiredDb === '' && isset($_REQUEST['database'])) {
            $desiredDb = (string) $_REQUEST['database'];
        }
        if ($desiredDb === '' && $streamState['home_database'] !== '') {
            $desiredDb = $streamState['home_database'];
        }

        if ($desiredDb !== '' && $streamState['active_database'] !== $desiredDb) {
            try {
                $pgTarget = $misc->getDatabaseAccessor($desiredDb);
                if ($pgTarget !== null) {
                    $pg = $pgTarget;
                    AppContainer::setPostgres($pg);
                    $streamState['active_database'] = $desiredDb;
                }
            } catch (Throwable $e) {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to connect to target database', 'detail' => $e->getMessage()]);
                return;
            }
        }

        // Apply cached SET statements once per database connection
        if (!empty($streamState['cached_settings']) && $desiredDb !== '' && $streamState['last_applied_db'] !== $desiredDb) {
            $errors += $settingsApplier->applySettings($pg);
            $streamState['last_applied_db'] = $desiredDb;
        }
        if ($desiredDb !== '' && $streamState['active_database'] === '') {
            $streamState['active_database'] = $desiredDb;
        }

        // Compute offset advance based on source bytes read, not executed statements.
        // New bytes read = payload length minus client-prepended remainder.
        $payloadLen = strlen($decoded);
        $newBytesRead = $payloadLen - $remainderLen;
        if ($newBytesRead < 0) {
            $newBytesRead = 0;
        }
        $absoluteOffset = $baseOffset + $newBytesRead;

        // Execute any non-COPY statements parsed in this chunk
        if (!empty($statements)) {
            if ($skipCount > 0 && count($statements) > 0) {
                $statements = array_slice($statements, $skipCount);
            }
            try {
                $runStatements($statements);
                $logCollector->addInfo('Chunk processed: offset=' . $absoluteOffset . ' parsed=' . count($statements) . ' remainder=' . strlen($remainder));
                if (strlen($remainder) > 10000) {
                    $logCollector->addWarning('Large remainder detected: ' . strlen($remainder) . ' bytes. Preview: ' . substr($remainder, 0, 200));
                }
            } catch (\Throwable $e) {
                $errors++;
                $logCollector->addError('Execution error: ' . $e->getMessage());
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

        // Return the *actual* remainder string.
        // The remainder is not guaranteed to be a literal suffix of the uploaded payload
        // (SqlParser may normalize/trim while streaming INSERT ... VALUES), so returning only
        // remainder_len and reconstructing client-side is not reliable.
        echo json_encode([
            'offset' => $absoluteOffset,
            'remainder_len' => strlen($remainder),
            'remainder' => $remainder,
            'errors' => $errors,
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
        handle_process_chunk_stream();
        break;
    default:
        http_response_code(400);
        echo 'Unknown action';
}
