<?php
/**
 * CursorReader API Examples
 * 
 * Demonstrates the memory-efficient streaming API
 */

require_once __DIR__ . '/../../bootstrap.php';

use PhpPgAdmin\Database\Cursor\CursorReader;
use PhpPgAdmin\Database\Cursor\RowSizeEstimator;

// Assume $connection is a Postgres instance
// $connection = ...;

// =============================================================================
// EXAMPLE 1: Streaming API (Recommended - Zero Memory Accumulation)
// =============================================================================

echo "=== Example 1: Streaming with eachRow() ===\n";

$reader = new CursorReader(
    $connection,
    'SELECT * FROM large_table',
    null,           // Auto-calculate chunk size
    'large_table',
    'public'
);

$count = $reader->eachRow(function($row, $rowNumber, $fields) {
    // Row is processed immediately and discarded
    // No memory accumulation!
    
    // Access field metadata (only on first row typically)
    if ($rowNumber === 1) {
        echo "Columns: ";
        foreach ($fields as $field) {
            echo $field['name'] . " (" . $field['type'] . "), ";
        }
        echo "\n";
    }
    
    // Process row data (numeric array)
    // echo "Row {$rowNumber}: " . implode(', ', $row) . "\n";
    
    // Example: Write to file, send to API, etc.
    // file_put_contents('output.csv', implode(',', $row) . "\n", FILE_APPEND);
});

echo "Processed {$count} rows\n\n";


// =============================================================================
// EXAMPLE 2: Manual Mode (Direct Control)
// =============================================================================

echo "=== Example 2: Manual mode with fetchChunk() ===\n";

$reader = new CursorReader($connection, 'SELECT id, name, email FROM users');
$reader->open();

$chunkNum = 0;
$totalRows = 0;

while (($result = $reader->fetchChunk()) !== false) {
    $chunkNum++;
    $rowsInChunk = pg_num_rows($result);
    echo "Chunk {$chunkNum}: {$rowsInChunk} rows\n";
    
    // Iterate result directly (zero copy!)
    while ($row = pg_fetch_row($result)) {
        // Process row: $row = [0 => id, 1 => name, 2 => email]
        $totalRows++;
    }
    
    // IMPORTANT: Must free result after processing
    pg_free_result($result);
}

$reader->close();
echo "Total rows: {$totalRows}\n\n";


// =============================================================================
// EXAMPLE 3: Table Analysis Before Export
// =============================================================================

echo "=== Example 3: Analyze table before streaming ===\n";

$analysis = RowSizeEstimator::analyzeTable($connection, 'large_table', 'public');

echo "Table: {$analysis['table_name']}\n";
echo "Size: {$analysis['total_size_mb']} MB\n";
echo "Rows: {$analysis['row_count']}\n";
echo "Avg row size: {$analysis['avg_row_bytes']} bytes\n";
echo "Max row size (estimated): {$analysis['max_row_bytes_estimated']} bytes\n";
echo "Recommended chunk size: {$analysis['recommended_chunk_size']} rows\n";

if ($analysis['has_large_columns']) {
    echo "Large columns detected:\n";
    foreach ($analysis['large_columns'] as $col) {
        echo "  - {$col['name']} ({$col['type']})\n";
    }
}
echo "\n";


// =============================================================================
// EXAMPLE 4: Memory-Efficient CSV Export
// =============================================================================

echo "=== Example 4: Stream to CSV file ===\n";

$outputFile = 'export.csv';
$fp = fopen($outputFile, 'w');

$reader = new CursorReader($connection, 'SELECT * FROM products', null, 'products', 'public');

$reader->eachRow(function($row, $rowNumber, $fields) use ($fp) {
    // Write CSV header on first row
    if ($rowNumber === 1) {
        $headers = array_map(function($f) { return $f['name']; }, $fields);
        fputcsv($fp, $headers);
    }
    
    // Write data row
    fputcsv($fp, $row);
});

fclose($fp);
echo "Exported to {$outputFile}\n\n";


// =============================================================================
// EXAMPLE 5: Fixed Chunk Size for Predictable Workload
// =============================================================================

echo "=== Example 5: Fixed chunk size ===\n";

$reader = new CursorReader(
    $connection,
    'SELECT * FROM orders',
    1000  // Fixed: 1000 rows per chunk (no adaptive sizing)
);

$reader->eachRow(function($row, $rowNumber, $fields) {
    // Process order
});

echo "Done\n\n";


// =============================================================================
// EXAMPLE 6: Monitor Adaptive Chunk Sizing
// =============================================================================

echo "=== Example 6: Monitor chunk size adjustments ===\n";

$reader = new CursorReader(
    $connection,
    'SELECT * FROM variable_size_table'
);

$reader->eachRow(
    function($row, $rowNumber, $fields) {
        // Process row
    },
    function($chunkNum, $rowsInChunk, $memUsed, $newChunkSize) {
        // Called after each chunk is processed
        $memMB = round($memUsed / 1024 / 1024, 2);
        echo "Chunk {$chunkNum}: {$rowsInChunk} rows, {$memMB} MB used, ";
        echo "next chunk size: {$newChunkSize} rows\n";
    }
);

echo "\n";


// =============================================================================
// EXAMPLE 7: Complex Query without Table (adaptive sizing)
// =============================================================================

echo "=== Example 7: Query without table (adaptive sizing) ===\n";

$reader = new CursorReader(
    $connection,
    'SELECT u.*, COUNT(o.id) as order_count 
     FROM users u 
     LEFT JOIN orders o ON u.id = o.user_id 
     GROUP BY u.id'
);

// Starts with 100 rows, adapts based on actual memory usage
$reader->eachRow(function($row, $rowNumber, $fields) {
    // Process aggregated data
});

echo "Done\n\n";


// =============================================================================
// EXAMPLE 7: Error Handling
// =============================================================================

echo "=== Example 7: Error handling ===\n";

try {
    $reader = new CursorReader($connection, 'SELECT * FROM huge_table');
    
    $reader->eachRow(function($row, $rowNumber, $fields) {
        // Process row
        
        // If memory limit is approaching, reader will throw RuntimeException
    });
    
} catch (\RuntimeException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    
    // Reader auto-closes and rolls back transaction
}

echo "\n";


// =============================================================================
// COMPARISON: Old vs New Approach
// =============================================================================

echo "=== MEMORY COMPARISON ===\n\n";

echo "OLD APPROACH (Buffered - BAD):\n";
echo "  \$rs = \$connection->selectSet('SELECT * FROM table');\n";
echo "  // ALL ROWS LOADED INTO MEMORY!\n";
echo "  while (!\$rs->EOF) {\n";
echo "    \$row = \$rs->fields;\n";
echo "    \$rs->moveNext();\n";
echo "  }\n";
echo "  Memory: ~(row_size * row_count) - Can exceed PHP memory_limit!\n\n";

echo "NEW APPROACH (Streaming - GOOD):\n";
echo "  \$reader = new CursorReader(\$connection, 'SELECT * FROM table');\n";
echo "  \$reader->eachRow(function(\$row) {\n";
echo "    // Row processed immediately, then discarded\n";
echo "  });\n";
echo "  Memory: ~(row_size * chunk_size) - Only one chunk in memory!\n\n";

echo "For 1M rows × 5KB/row = 5GB table:\n";
echo "  Old: 5GB in memory (FAIL)\n";
echo "  New: ~50MB in memory (chunk of 10,000 rows) (SUCCESS)\n\n";
