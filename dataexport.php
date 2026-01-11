<?php

use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Database\Export\Compression\CompressionFactory;
use PhpPgAdmin\Database\Export\FormatterFactory;
use PhpPgAdmin\Gui\ExportOutputRenderer;
use PhpPgAdmin\Gui\QueryExportRenderer;

/**
 * Export query results to various formats (SQL, CSV, XML, HTML, JSON, etc.)
 * Uses unified OutputFormatter infrastructure for consistent format handling.
 *
 * $Id: dataexport.php,v 1.26 2007/07/12 19:26:22 xzilla Exp $
 */

// Include application functions
include_once('./libraries/bootstrap.php');

// Prevent timeouts on large exports (non-safe mode only)
if (!ini_get('safe_mode'))
	set_time_limit(0);

$pg = AppContainer::getPostgres();
$misc = AppContainer::getMisc();
$lang = AppContainer::getLang();

/**
 * Generate a descriptive filename for the query export.
 */
function generateQueryExportFilename()
{
	$timestamp = date('Ymd_His');
	return 'query_export_' . $timestamp;
}

// Handle export action with new unified parameter system
if (isset($_REQUEST['action']) && $_REQUEST['action'] === 'export') {
	AppContainer::setSkipHtmlFrame(true);

	// Get unified parameters
	$output_format = $_REQUEST['output_format'] ?? 'csv';
	$insert_format = $_REQUEST['insert_format'] ?? 'copy';

	// Parse composite `output` parameter: 'show' | 'download' | 'download-gzip' | 'download-bzip2' | 'download-zip'
	$rawOutput = $_REQUEST['output'] ?? 'download';
	if ($rawOutput === 'show') {
		$output_method = 'show';
		$output_compression = '';
	} else {
		$output_method = 'download';
		$compression_suffix = str_replace('download-', '', $rawOutput);
		$output_compression = $compression_suffix ?: 'plain';
	}

	// Get the query to export
	$query = $_REQUEST['query'] ?? ($_SESSION['sqlquery'] ?? '');
	if (empty($query)) {
		header('HTTP/1.0 400 Bad Request');
		echo "Error: No query provided for export.";
		exit;
	}

	// Validate format is supported for query exports
	try {
		$formatter = FormatterFactory::create($output_format);
	} catch (\Exception $e) {
		header('HTTP/1.0 400 Bad Request');
		echo "Error: Invalid export format: " . htmlspecialchars($output_format);
		exit;
	}

	// Execute the query
	$pg->conn->setFetchMode(ADODB_FETCH_NUM);
	$rs = $pg->conn->Execute($query);
	if (!$rs) {
		header('HTTP/1.0 500 Internal Server Error');
		echo "Error executing query: " . htmlspecialchars($pg->conn->ErrorMsg());
		exit;
	}

	// Set up download headers and output handling
	$filename = generateQueryExportFilename();
	$mime_type = $formatter->getMimeType();
	$file_extension = $formatter->getFileExtension();

	if ($output_method === 'show') {
		// For browser display, use unified HTML wrapper
		ExportOutputRenderer::beginHtmlOutput(['mode' => $output_format]);
		$output_stream = null; // HTML mode uses echo directly
	} else {
		// For all other output methods (download with optional compression), use CompressionFactory
		try {
			$strategy = CompressionFactory::create($output_compression);
			if (!$strategy) {
				die("Error: Unsupported output method: " . htmlspecialchars($output_compression));
			}
			$handle = $strategy->begin("$filename.$file_extension");
			$output_stream = $handle['stream'];
		} catch (\Exception $e) {
			die("Error: " . htmlspecialchars($e->getMessage()));
		}
	}

	// Reset recordset to beginning for formatter
	$rs->moveFirst();

	// Build metadata for formatter
	$metadata = [
		'table' => $_REQUEST['table'] ?? $_REQUEST['view'] ?? 'query_result',
		'insert_format' => $insert_format,
		'export_nulls' => $_REQUEST['export_nulls'] ?? '',
	];

	// Stream output directly using the formatter
	// Pass stream to formatter for memory-efficient processing
	if ($output_stream !== null) {
		$formatter->setOutputStream($output_stream);
	}
	$formatter->format($rs, $metadata);

	// Close streams for download and gzipped output
	if ($output_method !== 'show' && isset($strategy) && isset($handle)) {
		$strategy->finish($handle);
	} elseif ($output_method === 'show') {
		ExportOutputRenderer::endHtmlOutput();
	}
	exit;
}

// If not an export action, display the export form
// Get query from request or session
$query = $_REQUEST['query'] ?? ($_SESSION['sqlquery'] ?? '');

if (empty($query)) {
	header('HTTP/1.0 400 Bad Request');
	echo "Error: No query provided for export.";
	exit;
}
// Display the query export form
$misc->printHeader($lang['strexport']);
$misc->printBody();
$misc->printTrail($_REQUEST['subject'] ?? 'database');
$misc->printTitle($lang['strexport']);

if (isset($msg))
	$misc->printMsg($msg);

// Render the export form using QueryDataRenderer
$renderer = new QueryExportRenderer();
$renderer->renderExportForm($query, $_REQUEST);

$misc->printFooter();
