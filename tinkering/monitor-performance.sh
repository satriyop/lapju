#!/bin/bash
# LAPJU Performance Monitoring Script
# Usage: bash tinkering/monitor-performance.sh

echo "╔════════════════════════════════════════════════════════════╗"
echo "║         LAPJU Performance Monitor                          ║"
echo "╚════════════════════════════════════════════════════════════╝"
echo ""
echo "📅 Time: $(date '+%Y-%m-%d %H:%M:%S')"
echo ""

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "🖥️  CPU & Memory Usage"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

# CPU Usage (macOS compatible)
if [[ "$OSTYPE" == "darwin"* ]]; then
    # macOS
    CPU_USAGE=$(ps -A -o %cpu | awk '{s+=$1} END {print s "%"}')
    echo "CPU Usage: $CPU_USAGE"

    # Memory
    echo "Memory Usage:"
    vm_stat | perl -ne '/page size of (\d+)/ and $size=$1; /Pages\s+([^:]+)[^\d]+(\d+)/ and printf("%-16s % 16.2f MB\n", "$1:", $2 * $size / 1048576);'
else
    # Linux
    top -bn1 | grep "Cpu(s)" | sed "s/.*, *\([0-9.]*\)%* id.*/\1/" | awk '{print "CPU Usage: " 100 - $1"%"}'

    echo ""
    echo "Memory Usage:"
    free -h | awk '/^Mem:/ {print "  Total:     " $2; print "  Used:      " $3 " (" $3/$2*100 "%)"; print "  Available: " $7}'
fi

echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "💾 Disk Usage"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
df -h /Users/satriyo/dev/laravel-project/lapju | awk 'NR==2 {print "  Used: " $3 " / " $2 " (" $5 ")"}'

echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "⚙️  PHP Processes"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
PHP_PROCESSES=$(ps aux | grep -E "php|artisan" | grep -v grep | wc -l | xargs)
echo "  Active PHP processes: $PHP_PROCESSES"

if [ "$PHP_PROCESSES" -gt 20 ]; then
    echo "  ⚠️  WARNING: High number of PHP processes!"
fi

# Show top PHP processes by memory
echo ""
echo "  Top 5 PHP processes by memory:"
ps aux | grep -E "php|artisan" | grep -v grep | sort -k4 -r | head -5 | awk '{printf "    PID: %-8s CPU: %-6s MEM: %-6s CMD: %s\n", $2, $3"%", $4"%", $11" "$12" "$13}'

echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "🗄️  Database Information"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

DB_PATH="/Users/satriyo/dev/laravel-project/lapju/database/database.sqlite"
if [ -f "$DB_PATH" ]; then
    DB_SIZE=$(du -h "$DB_PATH" | awk '{print $1}')
    echo "  Database Size: $DB_SIZE"

    # Count records in main tables
    echo ""
    echo "  Record Counts:"
    sqlite3 "$DB_PATH" << 'EOF'
.mode column
SELECT
    'Projects' as Table,
    (SELECT COUNT(*) FROM projects) as Count
UNION ALL
SELECT 'Tasks', (SELECT COUNT(*) FROM tasks)
UNION ALL
SELECT 'TaskProgress', (SELECT COUNT(*) FROM task_progress)
UNION ALL
SELECT 'Users', (SELECT COUNT(*) FROM users)
UNION ALL
SELECT 'Offices', (SELECT COUNT(*) FROM offices);
EOF
else
    echo "  ❌ Database file not found!"
fi

echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "📦 Cache & Storage"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

CACHE_PATH="/Users/satriyo/dev/laravel-project/lapju/storage/framework/cache"
if [ -d "$CACHE_PATH" ]; then
    CACHE_SIZE=$(du -sh "$CACHE_PATH" 2>/dev/null | awk '{print $1}')
    CACHE_FILES=$(find "$CACHE_PATH" -type f 2>/dev/null | wc -l | xargs)
    echo "  Cache Size: $CACHE_SIZE ($CACHE_FILES files)"
else
    echo "  Cache Size: 0 (directory not found)"
fi

SESSION_PATH="/Users/satriyo/dev/laravel-project/lapju/storage/framework/sessions"
if [ -d "$SESSION_PATH" ]; then
    SESSION_SIZE=$(du -sh "$SESSION_PATH" 2>/dev/null | awk '{print $1}')
    SESSION_FILES=$(find "$SESSION_PATH" -type f 2>/dev/null | wc -l | xargs)
    echo "  Session Size: $SESSION_SIZE ($SESSION_FILES files)"
else
    echo "  Session Size: 0 (directory not found)"
fi

LOG_PATH="/Users/satriyo/dev/laravel-project/lapju/storage/logs"
if [ -d "$LOG_PATH" ]; then
    LOG_SIZE=$(du -sh "$LOG_PATH" 2>/dev/null | awk '{print $1}')
    echo "  Logs Size: $LOG_SIZE"
else
    echo "  Logs Size: 0 (directory not found)"
fi

echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "⚠️  Recent Errors"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

LARAVEL_LOG="/Users/satriyo/dev/laravel-project/lapju/storage/logs/laravel.log"
if [ -f "$LARAVEL_LOG" ]; then
    ERROR_COUNT=$(grep -ci "error\|exception\|fatal" "$LARAVEL_LOG" 2>/dev/null || echo "0")
    echo "  Total errors in log: $ERROR_COUNT"

    if [ "$ERROR_COUNT" -gt 0 ]; then
        echo ""
        echo "  Last 5 errors:"
        grep -i "error\|exception\|fatal" "$LARAVEL_LOG" 2>/dev/null | tail -5 | while IFS= read -r line; do
            echo "    $(echo "$line" | cut -c1-100)..."
        done
    else
        echo "  ✅ No recent errors found"
    fi
else
    echo "  ℹ️  Log file not found (no errors yet)"
fi

echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "📊 Health Summary"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

# Calculate health score
HEALTH_ISSUES=0

if [ "$PHP_PROCESSES" -gt 20 ]; then
    HEALTH_ISSUES=$((HEALTH_ISSUES + 1))
    echo "  ⚠️  High PHP process count"
fi

if [ -f "$LARAVEL_LOG" ]; then
    if [ "$ERROR_COUNT" -gt 100 ]; then
        HEALTH_ISSUES=$((HEALTH_ISSUES + 1))
        echo "  ⚠️  High error count in logs"
    fi
fi

if [ -d "$CACHE_PATH" ]; then
    if [ "$CACHE_FILES" -gt 10000 ]; then
        HEALTH_ISSUES=$((HEALTH_ISSUES + 1))
        echo "  ⚠️  Large cache file count"
    fi
fi

if [ "$HEALTH_ISSUES" -eq 0 ]; then
    echo "  ✅ System health: GOOD"
else
    echo "  ⚠️  System health: $HEALTH_ISSUES issue(s) detected"
fi

echo ""
echo "╚════════════════════════════════════════════════════════════╝"
echo ""
echo "💡 Tips:"
echo "   - Run 'php artisan cache:clear' to clear cache"
echo "   - Run 'php artisan queue:restart' to restart queue workers"
echo "   - Check 'tinkering/troubleshooting-cpu-memory-issues.md' for detailed guide"
echo ""
