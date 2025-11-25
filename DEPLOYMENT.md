# LAPJU Production Deployment Guide

## MySQL Production Deployment

This guide covers deploying the LAPJU application to production with MySQL database.

---

## Prerequisites

- **MySQL 8.0+** (or MySQL 5.7.8+ minimum for JSON support)
- **PHP 8.2+**
- **Composer**
- **Node.js & NPM** (for asset compilation)
- **Web Server** (Apache/Nginx)

---

## Quick Start

### 1. Database Setup

Run the MySQL setup script to create the production database and user:

```bash
# Connect to MySQL as root
mysql -u root -p

# Run the setup script
SOURCE /path/to/lapju/database/mysql-setup.sql;

# Exit MySQL
exit;
```

**Important:** Edit `database/mysql-setup.sql` and change the default password before running!

### 2. Configure Environment

Copy the production environment template:

```bash
cp .env.production .env
```

Edit `.env` and update these critical values:

```bash
# Generate a new application key
php artisan key:generate

# Update database credentials
DB_HOST=your-mysql-host
DB_DATABASE=lapju_production
DB_USERNAME=lapju_user
DB_PASSWORD=your-secure-password

# Set production URL
APP_URL=https://your-production-domain.com

# Configure email (for notifications)
MAIL_MAILER=smtp
MAIL_HOST=your-smtp-server
MAIL_USERNAME=your-email
MAIL_PASSWORD=your-email-password
```

### 3. Install Dependencies

```bash
# Install PHP dependencies
composer install --optimize-autoloader --no-dev

# Install and build frontend assets
npm install
npm run build
```

### 4. Run Migrations

```bash
# Run database migrations
php artisan migrate --force

# Seed initial data (optional, for demo/testing)
php artisan db:seed --force
```

### 5. Optimize Application

```bash
# Cache configuration
php artisan config:cache

# Cache routes
php artisan route:cache

# Cache views
php artisan view:cache

# Optimize autoloader
php artisan optimize
```

### 6. Set Permissions

```bash
# Set proper permissions for storage and cache
chmod -R 775 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
```

---

## Pre-Deployment Testing

### Local MySQL Test

Before deploying to production, test with MySQL locally:

1. **Install MySQL Locally** (if not already installed):
   ```bash
   # macOS (Homebrew)
   brew install mysql
   brew services start mysql

   # Ubuntu/Debian
   sudo apt install mysql-server
   sudo systemctl start mysql
   ```

2. **Create Test Database**:
   ```bash
   mysql -u root -p < database/mysql-setup.sql
   ```

3. **Update Local .env**:
   ```bash
   DB_CONNECTION=mysql
   DB_DATABASE=lapju_production
   DB_USERNAME=lapju_user
   DB_PASSWORD=your-password
   ```

4. **Run Migrations and Tests**:
   ```bash
   php artisan migrate:fresh --seed
   php artisan test
   ```

5. **Verify Features**:
   - [ ] User authentication works
   - [ ] Projects can be created
   - [ ] Progress tracking works
   - [ ] Photo uploads work
   - [ ] Reports generate correctly
   - [ ] All pages load without errors

---

## Production Deployment Checklist

### Before Deployment

- [ ] MySQL 8.0+ installed on production server
- [ ] Production database created with utf8mb4 charset
- [ ] Database user created with proper privileges
- [ ] `.env` file configured with production settings
- [ ] `APP_KEY` generated with `php artisan key:generate`
- [ ] `APP_DEBUG=false` in production `.env`
- [ ] `APP_ENV=production` in production `.env`
- [ ] Email configuration tested
- [ ] Backup strategy implemented

### During Deployment

- [ ] Upload application files to server
- [ ] Install composer dependencies (with `--no-dev`)
- [ ] Build frontend assets (`npm run build`)
- [ ] Run migrations (`php artisan migrate --force`)
- [ ] Run seeders if needed (`php artisan db:seed --force`)
- [ ] Set proper file permissions
- [ ] Cache configuration, routes, and views
- [ ] Configure web server (Apache/Nginx)
- [ ] Configure SSL certificate
- [ ] Test database connection

### After Deployment

- [ ] Verify all pages load correctly
- [ ] Test user authentication
- [ ] Test critical features (projects, progress, photos)
- [ ] Check error logs for issues
- [ ] Monitor application performance
- [ ] Set up automated backups
- [ ] Set up monitoring (uptime, errors)
- [ ] Document production credentials securely

---

## Database Schema Information

### Total Tables: 25

**Core Tables:**
- `users` - User authentication and profile
- `roles`, `role_user` - Role-based access control
- `offices`, `office_levels` - Military office hierarchy
- `projects` - Project management
- `tasks`, `task_templates` - Task structure
- `task_progress` - Progress tracking
- `progress_photos` - Photo documentation
- `locations` - Geographic locations
- `partners` - Project partners
- `settings` - Application settings

### Database Requirements

- **Charset:** utf8mb4 (for full Unicode support)
- **Collation:** utf8mb4_unicode_ci
- **JSON Support:** Required (MySQL 5.7.8+)
- **Storage Engine:** InnoDB (default)

### Key Features

- **Nested Set Model:** Tasks and offices use `_lft`, `_rgt`, `parent_id` for hierarchical data
- **JSON Columns:** Used in `roles.permissions` and `settings.value`
- **Foreign Keys:** Properly enforced relationships
- **Indexes:** Optimized for reporting and queries

---

## Migration from SQLite to MySQL

If you have existing data in SQLite that needs to be migrated:

### Option 1: Export/Import via Seeders

1. Export SQLite data to CSV/JSON
2. Create custom seeder to import data
3. Run seeder on MySQL database

### Option 2: Database-Agnostic Migration Script

Create a migration command:

```bash
php artisan make:command MigrateFromSqlite
```

Implement logic to read from SQLite and write to MySQL using Eloquent.

---

## Common Issues & Solutions

### Issue: "Syntax error or access violation: 1071 Specified key was too long"

**Solution:** This is already handled. The application uses `utf8mb4` charset, and Laravel's `AppServiceProvider` sets default string length to 191 characters.

### Issue: "SQLSTATE[42000]: Syntax error or access violation"

**Solution:** Check that you're using MySQL 5.7.8+ for JSON column support.

### Issue: "Access denied for user 'lapju_user'@'localhost'"

**Solution:**
1. Verify the password in `.env` matches the one set in `mysql-setup.sql`
2. Run `FLUSH PRIVILEGES;` in MySQL
3. Check user exists: `SELECT User, Host FROM mysql.user WHERE User = 'lapju_user';`

### Issue: "Can't connect to MySQL server"

**Solution:**
1. Verify MySQL is running: `sudo systemctl status mysql`
2. Check firewall allows MySQL port 3306
3. Verify `DB_HOST` is correct in `.env`

---

## Performance Optimization

### MySQL Configuration

Edit `/etc/mysql/my.cnf` or `/etc/my.cnf`:

```ini
[mysqld]
# InnoDB Settings
innodb_buffer_pool_size = 1G  # 70-80% of available RAM
innodb_log_file_size = 256M
innodb_flush_log_at_trx_commit = 2

# Query Cache (for read-heavy applications)
query_cache_type = 1
query_cache_size = 64M

# Connections
max_connections = 200

# Character Set
character-set-server = utf8mb4
collation-server = utf8mb4_unicode_ci
```

### Laravel Optimizations

```bash
# Enable OPcache (edit php.ini)
opcache.enable=1
opcache.memory_consumption=128
opcache.max_accelerated_files=10000
opcache.validate_timestamps=0  # Production only!

# Enable Laravel caching
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

---

## Backup Strategy

### Database Backup

Create automated daily backups:

```bash
#!/bin/bash
# backup-db.sh

BACKUP_DIR="/path/to/backups"
DATE=$(date +%Y%m%d_%H%M%S)
DB_NAME="lapju_production"
DB_USER="lapju_user"
DB_PASS="your-password"

mysqldump -u $DB_USER -p$DB_PASS $DB_NAME | gzip > $BACKUP_DIR/lapju_$DATE.sql.gz

# Keep only last 30 days of backups
find $BACKUP_DIR -name "lapju_*.sql.gz" -mtime +30 -delete
```

Add to crontab:

```bash
0 2 * * * /path/to/backup-db.sh
```

### Application Files Backup

Backup uploaded files (photos):

```bash
# Backup storage directory
tar -czf lapju_storage_$(date +%Y%m%d).tar.gz storage/app/public/progress/
```

---

## Rollback Procedure

If deployment fails:

1. **Restore Database**:
   ```bash
   gunzip < backup.sql.gz | mysql -u lapju_user -p lapju_production
   ```

2. **Restore Application Files**:
   ```bash
   # Restore from backup or git
   git checkout previous-stable-version
   ```

3. **Rollback Migrations** (if needed):
   ```bash
   php artisan migrate:rollback --step=1
   ```

---

## Monitoring & Maintenance

### Log Monitoring

Monitor Laravel logs:

```bash
tail -f storage/logs/laravel.log
```

### Health Checks

Create a health check endpoint to monitor:
- Database connectivity
- Disk space
- Queue workers (if using)
- Cache availability

### Regular Maintenance

- **Weekly:** Review error logs
- **Monthly:** Update dependencies (test in staging first)
- **Quarterly:** Review and optimize database indexes
- **Yearly:** Security audit

---

## Security Best Practices

1. **Environment File**: Never commit `.env` to version control
2. **APP_KEY**: Generate unique key for production
3. **Database**: Use strong passwords, limit access
4. **SSL/TLS**: Always use HTTPS in production
5. **Firewall**: Restrict MySQL port 3306 to application server only
6. **Updates**: Keep PHP, Laravel, and MySQL updated
7. **Backups**: Test restore procedures regularly
8. **Monitoring**: Set up error tracking (Sentry, Bugsnag, etc.)

---

## Support & Resources

### Laravel Documentation
- [Deployment](https://laravel.com/docs/12.x/deployment)
- [Database](https://laravel.com/docs/12.x/database)
- [Migrations](https://laravel.com/docs/12.x/migrations)

### MySQL Documentation
- [Installation](https://dev.mysql.com/doc/refman/8.0/en/installing.html)
- [Character Sets](https://dev.mysql.com/doc/refman/8.0/en/charset-unicode-utf8mb4.html)

---

## Version History

- **v1.0** - Initial MySQL production deployment guide
- Created: 2025-11-25

---

## Questions?

For deployment issues or questions, refer to:
1. Laravel logs: `storage/logs/laravel.log`
2. MySQL error log: `/var/log/mysql/error.log`
3. Web server logs: `/var/log/nginx/` or `/var/log/apache2/`
