# PostgreSQL Cursor-Based Chunked Reader

Memory-efficient streaming reader for large PostgreSQL tables and queries using server-side cursors.

## Features

- ✅ **Server-side cursors** with `DECLARE CURSOR` / `FETCH` / `CLOSE`
- ✅ **Zero-copy streaming** - no row accumulation in memory
- ✅ **Automatic chunk size calculation** based on table statistics and available memory
- ✅ **Adaptive chunk sizing** for queries (starts at 100 rows, adjusts based on actual memory usage)
- ✅ **Memory safety** with 80% limit checks and automatic abort
- ✅ **Field metadata caching** (lazy loaded on first fetch)
- ✅ **Two APIs**: Manual mode (direct pg result) or streaming mode (callback)

## Classes

### `CursorReader`
Main class for streaming table/query results.

**Key Methods:**
- `fetchChunk()` - Returns pg result resource (manual iteration, zero memory overhead)
- `eachRow($callback)` - Streams rows through callback (no accumulation)
- `open()` / `close()` - Explicit cursor lifecycle control

### `ChunkCalculator`
Calculates optimal chunk sizes based on:
- Available PHP memory (`memory_limit`)
- Estimated row size from table statistics
- Target memory per chunk (5-10 MB)
- Hard limits (50-50,000 rows)

### `RowSizeEstimator`
Estimates row sizes using:
- `pg_column_size()` sampling (1000 rows)
- `pg_total_relation_size()` for total table size
- TOAST-capable column detection (text, bytea, json/jsonb, xml, etc.)

## Usage Examples

### Recommended: Streaming with Callback (Zero Memory Accumulation)

```php
use PhpPgAdmin\Database\Cursor\CursorReader;

// Create reader with auto chunk sizing
$reader = new CursorReader(
    $connection,           // Postgres connection
    'SELECT * FROM users', // Query
    null,                  // Auto chunk size
    'users',              // Table name for size estimation
    'public'              // Schema name
);

// Process each row as it's fetched (no accumulation!)
$reader->eachRow(function($row, $rowNumber, $fields) {
    // $row is numeric array: [0 => 'value1', 1 => 'value2', ...]
    // $fields is metadata: [['name' => 'id', 'type' => 'int4', ...], ...]
    
    // Row is processed immediately and discarded
    echo "Row {$rowNumber}: " . implode(', ', $row) . "\n";
});

// Reader auto-opens and auto-closes
```

### Manual Mode: Direct Result Resource Access

```php
$reader = new CursorReader($connection, 'SELECT * FROM large_table');
$reader->open();

while (($result = $reader->fetchChunk()) !== false) {
    // $result is pg_result resource - iterate directly (zero copy!)
    while ($row = pg_fetch_row($result)) {
        // Process row immediately
        processRow($row);
    }
    
    // Must free result after processing
    pg_free_result($result);
}

$reader->close();
```

### Simple Alias for Manual Mode

```php
$reader->open();

while (($result = $reader->nextChunk()) !== false) {
    while ($row = pg_fetch_row($result)) {
        // Process row
    }
    pg_free_result($result);
}

$reader->close();
```

### Fixed Chunk Size

```php
// Use fixed chunk size (no adaptive sizing)
$reader = new CursorReader(
    $connection,
    'SELECT * FROM my_table',
    500  // 500 rows per fetch
);
```

### Query without Table Name

```php
// For queries without table name, starts at 100 rows and adapts
$reader = new CursorReader(
    $connection,
    'SELECT * FROM users JOIN orders USING (user_id)'
);

$reader->eachRow(function($row, $rowNum, $fields) {
    // Process joined data
});
```

## Integration with TableDumper

The `TableDumper` class now uses `CursorReader` with streaming:

```php
// In TableDumper::dumpData()
$reader = new CursorReader(
    $this->connection,
    "SELECT * FROM \"{$schema}\".\"{$table}\"",
    null,    // Auto-calculate chunk size
    $table,
    $schema
);

// Stream rows one-by-one (no accumulation!)
$reader->eachRow(function($row, $rowNumber, $fields) use ($insertFormat) {
    if ($isFirstRow) {
        // Write headers
    }
    
    // Write row immediately to output
    if ($insertFormat === 'copy') {
        $this->writeCopyRow($row, $escapeModes);
    } else {
        $this->writeInsertValues($row, $escapeModes);
    }
});
```

## Memory Management

### Automatic Chunk Sizing (Tables)

For tables, chunk size is calculated as:

```
chunk_size = (available_memory * 0.55) / max_row_bytes
```

Where:
- `available_memory` = `(memory_limit - current_usage) * 0.55`
- `max_row_bytes` = sampled from 1000 rows using `pg_column_size()`

### Adaptive Sizing (Queries)

For queries, starts with 100 rows and adjusts after each chunk:

- If chunk uses > 10 MB: decrease by 30%
- If chunk uses < 5 MB: increase by 30%
- Hard limits: 50 min, 50,000 max

**Important:** Adaptive sizing only works with `eachRow()` because CursorReader controls the iteration loop and can measure memory between chunks. In manual mode (`fetchChunk()`), you control the `pg_fetch_row()` loop, so CursorReader cannot measure memory usage - chunk size remains fixed.

### Monitor Adaptive Adjustments

```php
$reader->eachRow(
    function($row, $rowNum, $fields) {
        // Process row
    },
    function($chunkNum, $rowsInChunk, $memUsed, $newChunkSize) {
        // Called after each chunk
        echo "Chunk {$chunkNum}: {$rowsInChunk} rows, ";
        echo round($memUsed / 1024 / 1024, 2) . " MB, ";
        echo "next size: {$newChunkSize}\n";
    }
);
```

### Safety Checks

Automatically aborts if memory usage exceeds 80% of `memory_limit`:

```php
// Throws RuntimeException if approaching limit
$reader->eachRow($callback);
```

## Field Metadata

Fields array structure:

```php
[
    [
        'name' => 'id',
        'type' => 'int4',
        'type_oid' => 23,
        'size' => 4,
        'num' => 0
    ],
    [
        'name' => 'email',
        'type' => 'varchar',
        'type_oid' => 1043,
        'size' => -1,
        'num' => 1
    ],
    // ...
]
```

## Error Handling

```php
try {
    $reader = new CursorReader($connection, 'SELECT * FROM my_table');
    
    $reader->eachRow(function($row, $rowNum, $fields) {
        // Process row
    });
    
} catch (\RuntimeException $e) {
    // Handle errors (cursor failure, memory limit, etc.)
    echo "Error: " . $e->getMessage();
}

// Reader auto-closes in destructor if forgotten
```

## Performance Tips

1. **Use table name when possible** - enables better chunk size calculation
2. **Monitor memory** - check `ChunkCalculator::getMemoryStats()` for diagnostics
3. **Adjust for specific workloads** - set fixed chunk size if you know your data
4. **TOAST columns** - tables with large text/bytea/json columns will use smaller chunks automatically

## Technical Details

### Transaction Handling

- Cursor requires transaction: `BEGIN` → `DECLARE` → `FETCH` → `CLOSE` → `COMMIT`
- Reader manages transaction automatically
- On error: auto-rollback and cleanup in `finally` block

### Cursor Type

Uses `NO SCROLL CURSOR` for optimal forward-only streaming:

```sql
DECLARE cursor_xyz NO SCROLL CURSOR FOR SELECT ...
```

### Row Format

Returns `pg_fetch_row()` results (numeric arrays) for minimal memory overhead:

```php
$row = [0 => 'value1', 1 => 123, 2 => null, ...];
```

## Requirements

- PHP >= 7.2
- `ext-pgsql`
- PostgreSQL 9.2+ (for `TABLESAMPLE` in row size estimation)

## Testing

```php
// Analyze table before dumping
use PhpPgAdmin\Database\Cursor\RowSizeEstimator;

$analysis = RowSizeEstimator::analyzeTable($connection, 'users', 'public');
print_r($analysis);

// Output:
// [
//     'table_name' => 'users',
//     'schema_name' => 'public',
//     'total_size_mb' => 1234.56,
//     'row_count' => 1000000,
//     'avg_row_bytes' => 1234,
//     'max_row_bytes_estimated' => 5678,
//     'has_large_columns' => true,
//     'large_columns' => [['name' => 'bio', 'type' => 'text', ...]],
//     'recommended_chunk_size' => 900
// ]
```
