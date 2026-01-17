# Adaptive Chunk Sizing - How It Works

## The Problem

When streaming large result sets, we need to balance:
- **Small chunks** = many network roundtrips, slow
- **Large chunks** = high memory usage, risk of OOM

Solution: **Adaptive chunk sizing** - adjust chunk size based on actual memory usage.

## Why Manual Mode Cannot Adapt

### Manual Mode (No Adaptation Possible)

```
┌─────────────────────────────────────────────────────────┐
│  Your Code                                              │
├─────────────────────────────────────────────────────────┤
│  $reader->open()                                        │
│                                                         │
│  while (($result = $reader->fetchChunk()) !== false) { │
│      ┌─────────────────────────────────────────────┐   │
│      │  while ($row = pg_fetch_row($result)) {     │   │
│      │      process($row);   ← YOU control this    │   │
│      │  }                                           │   │
│      └─────────────────────────────────────────────┘   │
│      pg_free_result($result);                          │
│  }                                                      │
│                                                         │
│  $reader->close()                                       │
└─────────────────────────────────────────────────────────┘
         ↑
         └─ CursorReader cannot see inside YOUR loop!
            Cannot measure memory during pg_fetch_row()
```

**Why?** CursorReader hands you the pg result resource and returns. It doesn't see:
- When you call `pg_fetch_row()`
- How much memory your `process($row)` uses
- When you finish processing the chunk

**Result:** Chunk size stays **FIXED**.

---

### Callback Mode (Adaptation Works!)

```
┌─────────────────────────────────────────────────────────┐
│  CursorReader::eachRow()                                │
├─────────────────────────────────────────────────────────┤
│  $memBefore = memory_get_usage()                        │
│                                                         │
│  while (($result = $this->fetchChunk()) !== false) {   │
│      ┌─────────────────────────────────────────────┐   │
│      │  while ($row = pg_fetch_row($result)) {     │   │
│      │      $callback($row);  ← CursorReader calls │   │
│      │  }                                           │   │
│      └─────────────────────────────────────────────┘   │
│      pg_free_result($result);                          │
│                                                         │
│      $memAfter = memory_get_usage()                     │
│      $memUsed = $memAfter - $memBefore                  │
│                                                         │
│      if ($memUsed > 10MB) {                             │
│          $chunkSize *= 0.7  // Decrease 30%            │
│      } else if ($memUsed < 5MB) {                       │
│          $chunkSize *= 1.3  // Increase 30%            │
│      }                                                  │
│                                                         │
│      $memBefore = $memAfter                             │
│  }                                                      │
└─────────────────────────────────────────────────────────┘
         ↑
         └─ CursorReader CONTROLS the loop!
            Measures memory between chunks
```

**Why?** CursorReader controls the entire iteration:
- Measures memory before processing chunk
- Calls your callback for each row
- Measures memory after processing chunk
- Calculates delta and adjusts chunk size

**Result:** Chunk size **ADAPTS** dynamically!

---

## Memory Measurement Timeline

```
Time ──────────────────────────────────────────────────►

Chunk 1 (1000 rows):
┌────────┐  ┌──────────────────┐  ┌────────┐
│ FETCH  │  │  Process rows    │  │ Measure│
│        │  │  via callback    │  │ Memory │
└────────┘  └──────────────────┘  └────────┘
     ↑               ↑                  ↑
     │               │                  └─ memAfter = 8MB
     │               └─ Your callback processes each row
     └─ memBefore = 2MB
     
Memory used: 8MB - 2MB = 6MB → OK, keep size at 1000


Chunk 2 (1000 rows):
┌────────┐  ┌──────────────────┐  ┌────────┐
│ FETCH  │  │  Process rows    │  │ Measure│
│        │  │  via callback    │  │ Memory │
└────────┘  └──────────────────┘  └────────┘
     ↑               ↑                  ↑
     │               │                  └─ memAfter = 20MB
     │               └─ Callback accumulating data?
     └─ memBefore = 8MB
     
Memory used: 20MB - 8MB = 12MB → TOO HIGH!
Next chunk: 1000 * 0.7 = 700 rows


Chunk 3 (700 rows):
┌────────┐  ┌──────────────────┐  ┌────────┐
│ FETCH  │  │  Process rows    │  │ Measure│
│        │  │  via callback    │  │ Memory │
└────────┘  └──────────────────┘  └────────┘
     ↑               ↑                  ↑
     │               │                  └─ memAfter = 26MB
     │               └─ Fewer rows processed
     └─ memBefore = 20MB
     
Memory used: 26MB - 20MB = 6MB → GOOD!
Next chunk: Keep at 700 rows
```

---

## Best Practices

### ✅ DO: Use eachRow() when you need adaptive sizing

```php
$reader = new CursorReader($connection, 'SELECT * FROM huge_table');

$reader->eachRow(function($row, $rowNum, $fields) {
    // Process each row without accumulation
    file_put_contents('output.csv', implode(',', $row) . "\n", FILE_APPEND);
});

// Chunk size adjusts automatically!
```

### ✅ DO: Monitor adjustments with callback

```php
$reader->eachRow(
    function($row, $rowNum, $fields) {
        process($row);
    },
    function($chunkNum, $rowsInChunk, $memUsed, $newChunkSize) {
        echo "Chunk $chunkNum: {$rowsInChunk} rows, ";
        echo round($memUsed/1024/1024, 2) . " MB, ";
        echo "next: {$newChunkSize} rows\n";
    }
);
```

### ⚠️ CAUTION: Don't accumulate memory in callback

```php
// BAD: Accumulating in callback affects measurement
$results = [];
$reader->eachRow(function($row) use (&$results) {
    $results[] = $row;  // Memory keeps growing!
});

// GOOD: Process and discard
$reader->eachRow(function($row) {
    fputcsv($outputFile, $row);  // Write and forget
});
```

### ✅ DO: Use manual mode for fixed workloads

```php
// If you know your data and want control
$reader = new CursorReader($connection, 'SELECT * FROM data', 500); // Fixed
$reader->open();

while (($result = $reader->fetchChunk()) !== false) {
    while ($row = pg_fetch_row($result)) {
        process($row);
    }
    pg_free_result($result);
}

$reader->close();
```

### ❌ DON'T: Expect adaptation in manual mode

```php
// This will NOT adapt (chunk size stays at initial value)
$reader = new CursorReader($connection, 'SELECT * FROM data');
$reader->open();

while (($result = $reader->fetchChunk()) !== false) {
    // CursorReader cannot measure memory here
    while ($row = pg_fetch_row($result)) {
        process($row);
    }
    pg_free_result($result);
}
```

---

## Summary

| Mode | API | Adaptive Sizing | Use Case |
|------|-----|----------------|----------|
| **Callback** | `eachRow()` | ✅ YES | Variable row sizes, unknown data |
| **Manual** | `fetchChunk()` | ❌ NO | Fixed workloads, maximum control |

**Key Insight:** Adaptive sizing requires CursorReader to control the iteration loop so it can measure memory between chunks. If you control the loop (manual mode), adaptation cannot work.
