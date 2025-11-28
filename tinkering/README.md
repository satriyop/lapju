# LAPJU Tinkering Directory

This directory contains useful scripts, commands, and troubleshooting guides for the LAPJU application.

## 📁 Files Overview

### 📘 Documentation Files

#### `troubleshooting-cpu-memory-issues.md` (20KB)
**Complete troubleshooting guide for performance issues**
- 11 detailed sections covering CPU and memory problems
- Step-by-step diagnostic procedures
- Both Tinker and SQL query options
- Warnings for commands that impact data
- Emergency quick fixes
- Long-term optimization solutions

**When to use:** When experiencing high CPU usage, memory maxing out, or slow response times

#### `useful-tinker-commands.md` (15KB)
**Comprehensive collection of Laravel tinker commands**
- 12 categories of useful commands
- Data exploration and analysis
- Progress tracking queries
- User and role management
- Database inspection
- Performance monitoring
- Quick reference card

**When to use:** Daily operations, data analysis, debugging, administrative tasks

#### `RELATIONSHIPS.md` (53KB)
**Database relationships and model documentation**
- Complete model relationship mapping
- Database schema overview
- Query examples

**When to use:** Understanding data structure, writing queries, planning features

#### `user-project-location.md` (42KB)
**User, project, and location data documentation**
- User management details
- Project structure
- Location hierarchy

**When to use:** Understanding user permissions, project assignments

#### `task-template.md` (10KB)
**Task template system documentation**
- Template structure
- Task hierarchy
- How templates are used

**When to use:** Working with task templates, creating projects

#### `taskWithProgress.md` (5.1KB)
**Progress tracking documentation**
- How progress is tracked
- Progress calculation logic

**When to use:** Understanding progress tracking, debugging progress issues

#### `dashboard.md` (2.1KB)
**Dashboard functionality documentation**
- Dashboard features
- S-curve calculations

**When to use:** Working on dashboard improvements

#### `DEPLOYMENT.md` (9.8KB)
**Deployment and server setup guide**
- Production deployment steps
- Server configuration

**When to use:** Deploying to production, setting up servers

### 🔧 Executable Scripts

#### `monitor-performance.sh` (8KB) ⭐
**Real-time performance monitoring script**

**Features:**
- CPU and memory usage
- Disk usage
- Active PHP processes
- Database size and record counts
- Cache and session size
- Recent error detection
- Health summary with warnings

**Usage:**
```bash
# Run once
bash tinkering/monitor-performance.sh

# Run continuously (every 5 seconds)
watch -n 5 bash tinkering/monitor-performance.sh

# Save output to file
bash tinkering/monitor-performance.sh > performance-report.txt
```

**When to use:**
- Regular health checks
- Before and after deployments
- When investigating performance issues
- Monitoring during high load

---

## 🚀 Quick Start Guide

### For Performance Issues

1. **Run the monitoring script:**
   ```bash
   bash tinkering/monitor-performance.sh
   ```

2. **Check the output for warnings:**
   - High PHP process count
   - Large cache size
   - High error count
   - Memory usage

3. **Follow the troubleshooting guide:**
   ```bash
   # Open the troubleshooting guide
   cat tinkering/troubleshooting-cpu-memory-issues.md
   # Or view in your editor/browser
   ```

4. **Run diagnostic commands from the guide**

### For Daily Operations

1. **Check useful tinker commands:**
   ```bash
   cat tinkering/useful-tinker-commands.md
   ```

2. **Pick the command you need and run it:**
   ```bash
   php artisan tinker --execute="YOUR_COMMAND_HERE"
   ```

### For Understanding the System

1. **Start with RELATIONSHIPS.md** - Understand the data structure
2. **Review specific documentation** based on what you're working on
3. **Use tinker commands** to explore the actual data

---

## 📊 Performance Monitoring Schedule

### Daily
```bash
# Quick health check
bash tinkering/monitor-performance.sh
```

### Weekly
```bash
# Run full diagnostic (from troubleshooting guide)
# 1. Check logs
tail -100 storage/logs/laravel.log

# 2. Check cache size
du -sh storage/framework/cache

# 3. Check database size
php artisan tinker --execute="
\$tables = ['projects', 'tasks', 'task_progress', 'users'];
foreach(\$tables as \$table) {
    echo str_pad(\$table, 20) . DB::table(\$table)->count() . PHP_EOL;
}
"

# 4. Clear old caches
php artisan cache:clear
php artisan view:clear
```

### Monthly
```bash
# Optimize database
sqlite3 database/database.sqlite "VACUUM;"
sqlite3 database/database.sqlite "ANALYZE;"

# Check for orphaned data (from troubleshooting guide)
# Review and clean up old sessions/logs
```

---

## 🔍 Common Troubleshooting Scenarios

### Scenario 1: Slow Dashboard Loading

1. Check `monitor-performance.sh` - Look for high PHP process count
2. Open `troubleshooting-cpu-memory-issues.md` - Section 6.2
3. Run dashboard S-curve memory test
4. Check for N+1 queries (Section 5)
5. Implement caching (Section 11.1)

### Scenario 2: High Memory Usage

1. Run `monitor-performance.sh` - Check memory section
2. Open `troubleshooting-cpu-memory-issues.md` - Section 9
3. Run memory leak detection test
4. Check for large dataset operations (Section 6)
5. Implement chunk processing (Section 11.2)

### Scenario 3: Database Growing Too Large

1. Check `monitor-performance.sh` - Database information section
2. Open `troubleshooting-cpu-memory-issues.md` - Section 4.4
3. Identify large tables
4. Check for redundant progress entries
5. Optimize database (Section 10.1)

### Scenario 4: Need to Understand Data Flow

1. Open `RELATIONSHIPS.md` - Review model relationships
2. Check `useful-tinker-commands.md` - Section 7
3. Run relationship inspection commands
4. Review specific documentation (user-project-location.md, etc.)

---

## 💡 Tips & Best Practices

### Using Tinker Commands

1. **Always test in development first**
   ```bash
   # Check current environment
   php artisan env
   ```

2. **Use `--execute` for reproducibility**
   ```bash
   # Good - can be saved and reused
   php artisan tinker --execute="App\Models\Project::count()"

   # Less ideal - interactive session
   php artisan tinker
   > App\Models\Project::count()
   ```

3. **Save frequently used commands**
   ```bash
   # Create an alias
   alias check-projects="php artisan tinker --execute='App\Models\Project::count()'"
   ```

### Monitoring Performance

1. **Establish baseline metrics**
   - Run monitoring script on fresh install
   - Save output as baseline
   - Compare future runs to baseline

2. **Monitor trends, not just current state**
   - Save daily/weekly reports
   - Look for gradual increases
   - Set up alerts for thresholds

3. **Use debugbar in development**
   - Already installed in your project
   - Shows queries, memory, time per request
   - Helps identify issues before production

### Database Queries

1. **Always check query count**
   ```php
   DB::enableQueryLog();
   // Your code
   echo count(DB::getQueryLog());
   ```

2. **Use eager loading**
   ```php
   // Bad - N+1 problem
   $projects = Project::all();
   foreach($projects as $p) { echo $p->office->name; }

   // Good - 2 queries total
   $projects = Project::with('office')->get();
   foreach($projects as $p) { echo $p->office->name; }
   ```

3. **Chunk large datasets**
   ```php
   // Bad - loads all into memory
   $progress = TaskProgress::all();

   // Good - processes in chunks
   TaskProgress::chunk(1000, function($entries) {
       // Process
   });
   ```

---

## 🆘 Emergency Procedures

### System is Currently Maxed Out

**Quick fixes (in order):**

```bash
# 1. Clear all caches
php artisan cache:clear && php artisan config:clear && php artisan route:clear && php artisan view:clear

# 2. Check for runaway processes
ps aux | grep php

# 3. Kill stuck processes (if identified)
kill -9 [PID]

# 4. Restart queue workers
php artisan queue:restart

# 5. Optimize database
sqlite3 database/database.sqlite "VACUUM;"

# 6. Run monitoring script to verify
bash tinkering/monitor-performance.sh
```

### Database Corruption Suspected

```bash
# 1. Backup database immediately
cp database/database.sqlite database/database.sqlite.backup

# 2. Check integrity
sqlite3 database/database.sqlite "PRAGMA integrity_check;"

# 3. If issues found, restore from backup
# (Make sure you have backups!)
```

### Need to Reset Everything (CAREFUL!)

```bash
# This will destroy all data!
php artisan migrate:fresh --seed

# Or just refresh specific tables
# (Better to create a migration for this)
```

---

## 📞 Getting Help

If you're still experiencing issues after following these guides:

1. **Check Laravel logs**: `storage/logs/laravel.log`
2. **Enable debug mode**: Set `APP_DEBUG=true` in `.env` (development only!)
3. **Use Laravel Debugbar**: Check the debug bar at bottom of page
4. **Run diagnostics**: Use commands from troubleshooting guide
5. **Document the issue**:
   - What were you doing?
   - What happened?
   - What does the monitoring script show?
   - What errors appear in logs?

---

## 📚 Additional Resources

- **Laravel Documentation**: https://laravel.com/docs
- **Livewire Documentation**: https://livewire.laravel.com/docs
- **Laravel Debugbar**: https://github.com/barryvdh/laravel-debugbar
- **Database Optimization**: Check troubleshooting guide Section 10

---

**Last Updated:** 2025-11-28
**LAPJU Version:** 1.0
**Maintainer:** Development Team
