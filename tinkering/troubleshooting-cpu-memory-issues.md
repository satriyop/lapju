# Troubleshooting CPU & Memory Issues - LAPJU

This guide provides step-by-step troubleshooting for CPU and memory maxing out issues in the LAPJU application.

---

## Table of Contents

1. [Initial Diagnostics](#1-initial-diagnostics)
2. [Check Application Logs](#2-check-application-logs)
3. [Monitor Real-Time Activity](#3-monitor-real-time-activity)
4. [Database Query Analysis](#4-database-query-analysis)
5. [Identify N+1 Query Problems](#5-identify-n1-query-problems)
6. [Check Large Dataset Operations](#6-check-large-dataset-operations)
7. [Cache & Session Analysis](#7-cache--session-analysis)
8. [Queue & Background Jobs](#8-queue--background-jobs)
9. [Memory Leak Detection](#9-memory-leak-detection)
10. [Optimization Steps](#10-optimization-steps)
11. [Long-term Solutions](#11-long-term-solutions)

---

## 1. Initial Diagnostics

### Step 1.1: Check Current Server Resources

```bash
# Check CPU usage
top -n 1 | head -20

# Check memory usage
free -h

# Check disk usage
df -h

# Find processes using most CPU
ps aux --sort=-%cpu | head -10

# Find processes using most memory
ps aux --sort=-%mem | head -10
```

### Step 1.2: Check PHP-FPM Status

```bash
# Check if PHP-FPM is running
ps aux | grep php-fpm

# Check PHP-FPM pool status (if enabled)
# Add this to your php-fpm pool config: pm.status_path = /status
curl http://localhost/status

# Check PHP-FPM error log
tail -f /var/log/php8.2-fpm.log  # Adjust path as needed
```

### Step 1.3: Check Apache/Nginx Status

```bash
# For Apache
systemctl status apache2
apachectl status

# For Nginx
systemctl status nginx
nginx -t
```

---

## 2. Check Application Logs

### Step 2.1: Laravel Application Logs

```bash
# Check recent errors
tail -100 /Users/satriyo/dev/laravel-project/lapju/storage/logs/laravel.log

# Follow live logs
tail -f /Users/satriyo/dev/laravel-project/lapju/storage/logs/laravel.log

# Search for memory errors
grep -i "memory" /Users/satriyo/dev/laravel-project/lapju/storage/logs/laravel.log

# Search for timeout errors
grep -i "timeout" /Users/satriyo/dev/laravel-project/lapju/storage/logs/laravel.log

# Search for database errors
grep -i "database\|query" /Users/satriyo/dev/laravel-project/lapju/storage/logs/laravel.log
```

### Step 2.2: Check PHP Error Logs

```bash
# Location varies by system
tail -100 /var/log/php_errors.log

# Or check php.ini for error_log location
php -i | grep error_log
```

---

## 3. Monitor Real-Time Activity

### Step 3.1: Enable Laravel Debugbar (Development Only)

```bash
# Already installed in your project
# Make sure APP_DEBUG=true in .env
# Access any page and check the debugbar at bottom
```

**What to look for:**
- Number of queries per page (should be < 50)
- Query execution time (should be < 100ms total)
- Memory usage per request (should be < 50MB)
- Duplicate queries (indicates N+1 problem)

### Step 3.2: Use Laravel Telescope (Optional - Install if needed)

```bash
composer require laravel/telescope --dev
php artisan telescope:install
php artisan migrate
```

Access at: `http://your-app.test/telescope`

---

## 4. Database Query Analysis

### Step 4.1: Check Database Connection Pool

**Option 1: Using Tinker**
```php
php artisan tinker --execute="
echo 'Database connections:' . PHP_EOL;
echo 'Driver: ' . config('database.default') . PHP_EOL;
echo 'Database: ' . config('database.connections.sqlite.database') . PHP_EOL;
echo 'Max connections: ' . ini_get('pdo.max_persistent') . PHP_EOL;
"
```

**Option 2: Using SQL (SQLite)**
```bash
sqlite3 /Users/satriyo/dev/laravel-project/lapju/database/database.sqlite "PRAGMA compile_options;"
```

### Step 4.2: Find Slow Queries

**⚠️ WARNING: This command will impact performance - Use in development only**

**Option 1: Using Tinker**
```php
php artisan tinker --execute="
DB::enableQueryLog();

// Simulate the operation causing issues
// Example: Load dashboard
\$projects = App\Models\Project::with(['office', 'location', 'tasks', 'users'])->get();

\$queries = DB::getQueryLog();
echo 'Total queries: ' . count(\$queries) . PHP_EOL . PHP_EOL;

// Find queries taking > 100ms
foreach(\$queries as \$query) {
    if(\$query['time'] > 100) {
        echo 'SLOW QUERY (' . \$query['time'] . 'ms):' . PHP_EOL;
        echo \$query['query'] . PHP_EOL;
        echo 'Bindings: ' . json_encode(\$query['bindings']) . PHP_EOL . PHP_EOL;
    }
}
"
```

**Option 2: Using SQL (SQLite doesn't support query logs)**
For SQLite, use the application-level query logging above.

### Step 4.3: Check Database Size

**Option 1: Using Tinker**
```php
php artisan tinker --execute="
\$tables = ['projects', 'tasks', 'task_progress', 'users', 'offices', 'locations', 'task_templates'];

echo str_pad('Table', 20) . str_pad('Rows', 15) . PHP_EOL;
echo str_repeat('-', 35) . PHP_EOL;

foreach(\$tables as \$table) {
    \$count = DB::table(\$table)->count();
    echo str_pad(\$table, 20) . str_pad(number_format(\$count), 15) . PHP_EOL;
}
"
```

**Option 2: Using SQL**
```bash
sqlite3 /Users/satriyo/dev/laravel-project/lapju/database/database.sqlite << 'EOF'
SELECT name,
       (SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name!='sqlite_sequence') as total_tables;

SELECT
    name as table_name,
    (SELECT COUNT(*) FROM pragma_table_info(name)) as columns
FROM sqlite_master
WHERE type='table'
ORDER BY name;
EOF
```

### Step 4.4: Identify Large Tables

**Option 1: Using Tinker**
```php
php artisan tinker --execute="
// Check TaskProgress table size (likely culprit)
\$progressCount = DB::table('task_progress')->count();
\$avgSize = DB::table('task_progress')
    ->selectRaw('AVG(LENGTH(notes)) as avg_notes_size')
    ->first();

echo 'TaskProgress Records: ' . number_format(\$progressCount) . PHP_EOL;
echo 'Average Notes Size: ' . round(\$avgSize->avg_notes_size ?? 0) . ' bytes' . PHP_EOL . PHP_EOL;

// Check for records with large notes
\$largeNotes = DB::table('task_progress')
    ->selectRaw('id, task_id, LENGTH(notes) as note_size')
    ->whereRaw('LENGTH(notes) > 1000')
    ->orderByRaw('LENGTH(notes) DESC')
    ->limit(10)
    ->get();

echo 'Progress entries with large notes (>1KB):' . PHP_EOL;
foreach(\$largeNotes as \$record) {
    echo 'ID: ' . \$record->id . ' | Size: ' . round(\$record->note_size / 1024, 2) . ' KB' . PHP_EOL;
}
"
```

**Option 2: Using SQL**
```bash
sqlite3 /Users/satriyo/dev/laravel-project/lapju/database/database.sqlite << 'EOF'
-- Count records in task_progress
SELECT COUNT(*) as total_records FROM task_progress;

-- Find largest notes
SELECT
    id,
    task_id,
    LENGTH(notes) as note_size
FROM task_progress
WHERE LENGTH(notes) > 1000
ORDER BY note_size DESC
LIMIT 10;
EOF
```

---

## 5. Identify N+1 Query Problems

### Step 5.1: Check Dashboard Queries

**⚠️ WARNING: This command may cause high CPU/memory usage during execution**

**Option 1: Using Tinker**
```php
php artisan tinker --execute="
DB::enableQueryLog();

// Simulate dashboard load WITHOUT eager loading (bad practice)
\$projects = App\Models\Project::all();
foreach(\$projects as \$project) {
    // This causes N+1
    \$office = \$project->office;
    \$location = \$project->location;
}

\$badQueries = count(DB::getQueryLog());
echo 'Queries WITHOUT eager loading: ' . \$badQueries . PHP_EOL;

DB::flushQueryLog();

// Simulate dashboard load WITH eager loading (good practice)
\$projects = App\Models\Project::with(['office', 'location'])->get();
foreach(\$projects as \$project) {
    \$office = \$project->office;
    \$location = \$project->location;
}

\$goodQueries = count(DB::getQueryLog());
echo 'Queries WITH eager loading: ' . \$goodQueries . PHP_EOL;
echo 'Queries saved: ' . (\$badQueries - \$goodQueries) . PHP_EOL;
"
```

### Step 5.2: Check Progress Page Queries

**⚠️ WARNING: This may trigger many queries - Use with caution**

**Option 1: Using Tinker**
```php
php artisan tinker --execute="
DB::enableQueryLog();

\$projectId = 1; // Change this

// Get all tasks for project
\$tasks = App\Models\Task::where('project_id', \$projectId)->get();

// Check for N+1 when getting progress (bad)
foreach(\$tasks as \$task) {
    \$progress = \$task->progress()->latest()->first();
}

\$queries = DB::getQueryLog();
echo 'Queries for ' . \$tasks->count() . ' tasks: ' . count(\$queries) . PHP_EOL;

if(count(\$queries) > \$tasks->count() + 5) {
    echo 'WARNING: Potential N+1 query problem detected!' . PHP_EOL;
}
"
```

---

## 6. Check Large Dataset Operations

### Step 6.1: Identify Memory-Intensive Operations

**Option 1: Using Tinker**
```php
php artisan tinker --execute="
echo 'Memory at start: ' . round(memory_get_usage() / 1024 / 1024, 2) . ' MB' . PHP_EOL;

// Load all tasks (potentially memory-intensive)
\$tasks = App\Models\Task::all();
echo 'After loading ' . \$tasks->count() . ' tasks: ' . round(memory_get_usage() / 1024 / 1024, 2) . ' MB' . PHP_EOL;

// Load all progress (potentially memory-intensive)
\$progress = App\Models\TaskProgress::all();
echo 'After loading ' . \$progress->count() . ' progress entries: ' . round(memory_get_usage() / 1024 / 1024, 2) . ' MB' . PHP_EOL;

echo 'Peak memory: ' . round(memory_get_peak_usage() / 1024 / 1024, 2) . ' MB' . PHP_EOL;
"
```

### Step 6.2: Check Dashboard S-Curve Calculation

**Option 1: Using Tinker**
```php
php artisan tinker --execute="
echo 'Testing S-Curve calculation memory usage...' . PHP_EOL;
echo 'Memory before: ' . round(memory_get_usage() / 1024 / 1024, 2) . ' MB' . PHP_EOL;

\$startTime = microtime(true);

// Simulate aggregated S-curve calculation
\$projects = App\Models\Project::with(['tasks' => function(\$q) {
    \$q->whereDoesntHave('children');
}])->get();

\$dates = [];
\$currentDate = now()->subDays(30);
for(\$i = 0; \$i < 90; \$i++) {
    \$dates[] = \$currentDate->copy()->addDays(\$i)->format('Y-m-d');
}

foreach(\$dates as \$date) {
    foreach(\$projects as \$project) {
        // Simulate progress calculation
        \$progress = App\Models\TaskProgress::where('project_id', \$project->id)
            ->where('progress_date', '<=', \$date)
            ->get();
    }
}

\$endTime = microtime(true);

echo 'Memory after: ' . round(memory_get_usage() / 1024 / 1024, 2) . ' MB' . PHP_EOL;
echo 'Peak memory: ' . round(memory_get_peak_usage() / 1024 / 1024, 2) . ' MB' . PHP_EOL;
echo 'Time taken: ' . round(\$endTime - \$startTime, 2) . ' seconds' . PHP_EOL;
"
```

---

## 7. Cache & Session Analysis

### Step 7.1: Check Cache Size

**Option 1: Using Tinker**
```php
php artisan tinker --execute="
echo 'Cache driver: ' . config('cache.default') . PHP_EOL;

// Check if cache directory exists
\$cachePath = storage_path('framework/cache');
if(file_exists(\$cachePath)) {
    \$size = 0;
    \$iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(\$cachePath)
    );
    foreach(\$iterator as \$file) {
        if(\$file->isFile()) {
            \$size += \$file->getSize();
        }
    }
    echo 'Cache size: ' . round(\$size / 1024 / 1024, 2) . ' MB' . PHP_EOL;
}
"
```

**Option 2: Using Bash**
```bash
# Check cache directory size
du -sh /Users/satriyo/dev/laravel-project/lapju/storage/framework/cache

# Check number of cache files
find /Users/satriyo/dev/laravel-project/lapju/storage/framework/cache -type f | wc -l

# Clear cache if needed
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear
```

### Step 7.2: Check Session Storage

```bash
# Check session directory size
du -sh /Users/satriyo/dev/laravel-project/lapju/storage/framework/sessions

# Count session files
find /Users/satriyo/dev/laravel-project/lapju/storage/framework/sessions -type f | wc -l

# Remove old sessions (Laravel handles this automatically, but you can manually clean)
find /Users/satriyo/dev/laravel-project/lapju/storage/framework/sessions -type f -mtime +7 -delete
```

---

## 8. Queue & Background Jobs

### Step 8.1: Check Queue Status

**Option 1: Using Tinker**
```php
php artisan tinker --execute="
// Check if using database queue
if(config('queue.default') === 'database') {
    \$pending = DB::table('jobs')->count();
    \$failed = DB::table('failed_jobs')->count();

    echo 'Pending jobs: ' . \$pending . PHP_EOL;
    echo 'Failed jobs: ' . \$failed . PHP_EOL;

    if(\$pending > 100) {
        echo 'WARNING: High number of pending jobs may cause performance issues!' . PHP_EOL;
    }
}
"
```

**Option 2: Using Bash**
```bash
# Check queue workers
ps aux | grep "queue:work"

# Check failed jobs
php artisan queue:failed

# Retry all failed jobs (if needed)
php artisan queue:retry all

# Clear all failed jobs
php artisan queue:flush
```

---

## 9. Memory Leak Detection

### Step 9.1: Check for Circular References

**Option 1: Using Tinker**
```php
php artisan tinker --execute="
// Test for memory leaks in model relationships
echo 'Initial memory: ' . round(memory_get_usage() / 1024 / 1024, 2) . ' MB' . PHP_EOL;

for(\$i = 0; \$i < 100; \$i++) {
    \$project = App\Models\Project::with(['tasks.progress', 'office', 'location'])->first();
    unset(\$project); // Try to free memory

    if(\$i % 10 === 0) {
        echo 'Iteration ' . \$i . ': ' . round(memory_get_usage() / 1024 / 1024, 2) . ' MB' . PHP_EOL;
    }
}

echo 'Final memory: ' . round(memory_get_usage() / 1024 / 1024, 2) . ' MB' . PHP_EOL;
echo 'Peak memory: ' . round(memory_get_peak_usage() / 1024 / 1024, 2) . ' MB' . PHP_EOL;
"
```

### Step 9.2: Test Livewire Component Memory

**⚠️ WARNING: This requires running actual HTTP requests**

```bash
# Use Apache Bench to test concurrent requests
ab -n 100 -c 10 http://your-app.test/dashboard

# Monitor memory during test
watch -n 1 'ps aux | grep php'
```

---

## 10. Optimization Steps

### Step 10.1: Optimize Database Queries

**Safe to run - No data impact**

```bash
# Optimize SQLite database
sqlite3 /Users/satriyo/dev/laravel-project/lapju/database/database.sqlite "VACUUM;"
sqlite3 /Users/satriyo/dev/laravel-project/lapju/database/database.sqlite "ANALYZE;"
```

### Step 10.2: Add Database Indexes

**⚠️ WARNING: This modifies database structure**

**Option 1: Using Tinker**
```php
php artisan tinker --execute="
// Check existing indexes
\$indexes = DB::select('SELECT * FROM sqlite_master WHERE type = \"index\"');
echo 'Existing indexes:' . PHP_EOL;
foreach(\$indexes as \$index) {
    echo '  ' . \$index->name . ' on table ' . \$index->tbl_name . PHP_EOL;
}
"
```

**Option 2: Create Migration for Indexes**
```bash
# Create migration
php artisan make:migration add_performance_indexes

# Edit migration file and add:
```

```php
// In the migration up() method:
Schema::table('task_progress', function (Blueprint $table) {
    $table->index(['project_id', 'progress_date']);
    $table->index(['task_id', 'progress_date']);
});

Schema::table('tasks', function (Blueprint $table) {
    $table->index(['project_id', 'template_task_id']);
    $table->index(['_lft', '_rgt']); // For nested set queries
});

// Run migration
php artisan migrate
```

### Step 10.3: Optimize Autoloader

```bash
# Generate optimized class map
composer dump-autoload -o

# Clear and cache Laravel configs
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### Step 10.4: Enable OPcache

Check `php.ini` and ensure:
```ini
opcache.enable=1
opcache.memory_consumption=128
opcache.interned_strings_buffer=8
opcache.max_accelerated_files=10000
opcache.revalidate_freq=2
```

Restart PHP-FPM after changes:
```bash
sudo systemctl restart php8.2-fpm
```

---

## 11. Long-term Solutions

### Solution 11.1: Implement Query Result Caching

**Add to your Livewire components:**

```php
use Illuminate\Support\Facades\Cache;

public function getAggregatedSCurveData()
{
    return Cache::remember('s-curve-aggregated-' . $this->selectedOfficeId, 3600, function() {
        // Your expensive calculation here
        return $this->calculateAggregatedSCurveData();
    });
}
```

### Solution 11.2: Implement Chunk Processing

**For large dataset operations:**

```php
// Instead of:
$progress = TaskProgress::all(); // Bad - loads everything into memory

// Use:
TaskProgress::chunk(1000, function($progressEntries) {
    foreach($progressEntries as $progress) {
        // Process each chunk
    }
});
```

### Solution 11.3: Use Lazy Loading for Large Collections

```php
// Instead of:
$tasks = Task::all(); // Bad - eager loads all

// Use:
$tasks = Task::cursor(); // Good - lazy loads
foreach($tasks as $task) {
    // Process one at a time
}
```

### Solution 11.4: Implement Redis for Caching (Optional)

```bash
# Install Redis
sudo apt-get install redis-server

# Install PHP Redis extension
composer require predis/predis

# Update .env
CACHE_DRIVER=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
```

### Solution 11.5: Database Query Optimization Checklist

**Review these files for optimization:**

- `/Users/satriyo/dev/laravel-project/lapju/resources/views/livewire/dashboard.blade.php`
  - Lines 450-584: `calculateAggregatedSCurveData()` and `calculateProjectProgressAtDate()`
  - Consider caching results
  - Use chunk processing for large date ranges

- `/Users/satriyo/dev/laravel-project/lapju/resources/views/livewire/progress/index.blade.php`
  - Lines 112-141: `getLatestProgressMap()`
  - Already optimized with correlated subquery
  - Consider adding index on (task_id, progress_date)

### Solution 11.6: Monitor Production Performance

**Install Laravel Horizon (for queue monitoring):**
```bash
composer require laravel/horizon
php artisan horizon:install
php artisan migrate
```

**Or use Laravel Pulse (for application monitoring):**
```bash
composer require laravel/pulse
php artisan vendor:publish --provider="Laravel\Pulse\PulseServiceProvider"
php artisan migrate
```

---

## Quick Diagnostic Checklist

When CPU/Memory maxes out, run these in order:

- [ ] **Check logs**: `tail -f storage/logs/laravel.log`
- [ ] **Check top processes**: `ps aux --sort=-%mem | head -10`
- [ ] **Enable query log**: Check debugbar for query count
- [ ] **Test without eager loading**: Count queries difference
- [ ] **Check cache size**: `du -sh storage/framework/cache`
- [ ] **Clear cache**: `php artisan cache:clear`
- [ ] **Check database size**: Count records in major tables
- [ ] **Optimize database**: `VACUUM` and `ANALYZE`
- [ ] **Check queue**: `php artisan queue:failed`
- [ ] **Test memory leak**: Run iteration test
- [ ] **Add indexes**: Create migration if needed
- [ ] **Implement caching**: Cache expensive calculations

---

## Emergency Quick Fixes

If system is currently maxed out:

```bash
# 1. Clear all caches
php artisan cache:clear && php artisan config:clear && php artisan route:clear && php artisan view:clear

# 2. Restart queue workers
php artisan queue:restart

# 3. Kill stuck processes (CAREFUL!)
pkill -f "php artisan queue:work"

# 4. Restart PHP-FPM
sudo systemctl restart php8.2-fpm

# 5. Optimize database
sqlite3 database/database.sqlite "VACUUM;"

# 6. Clear old logs
echo "" > storage/logs/laravel.log

# 7. Check and kill any runaway PHP processes
ps aux | grep php | grep -v grep
# Then kill specific PIDs if needed: kill -9 [PID]
```

---

## Performance Monitoring Script

Create a script to monitor performance:

```bash
#!/bin/bash
# Save as: /Users/satriyo/dev/laravel-project/lapju/tinkering/monitor-performance.sh

echo "=== LAPJU Performance Monitor ==="
echo "Time: $(date)"
echo ""

echo "--- CPU Usage ---"
top -bn1 | grep "Cpu(s)" | sed "s/.*, *\([0-9.]*\)%* id.*/\1/" | awk '{print "CPU Usage: " 100 - $1"%"}'

echo ""
echo "--- Memory Usage ---"
free -h | awk '/^Mem:/ {print "Used: " $3 " / " $2 " (" $3/$2*100 "%)"}'

echo ""
echo "--- Disk Usage ---"
df -h /Users/satriyo/dev/laravel-project/lapju | awk 'NR==2 {print "Used: " $3 " / " $2 " (" $5 ")"}'

echo ""
echo "--- PHP Processes ---"
ps aux | grep php | grep -v grep | wc -l | awk '{print "Active PHP processes: " $1}'

echo ""
echo "--- Database Size ---"
du -h /Users/satriyo/dev/laravel-project/lapju/database/database.sqlite

echo ""
echo "--- Cache Size ---"
du -sh /Users/satriyo/dev/laravel-project/lapju/storage/framework/cache

echo ""
echo "--- Recent Errors (Last 10) ---"
tail -10 /Users/satriyo/dev/laravel-project/lapju/storage/logs/laravel.log | grep -i error || echo "No recent errors"
```

Run with: `bash tinkering/monitor-performance.sh`

---

**Last Updated:** 2025-11-28
**LAPJU Version:** 1.0
