-- MySQL Production Database Setup Script for LAPJU
-- Project: Laporan Progres Jum'at (LAPJU) - Project Progress Tracking System
--
-- This script creates the production database, user, and grants necessary privileges.
-- Modify the database name, username, and password as needed for your environment.
--
-- Requirements:
-- - MySQL 5.7.8+ or MySQL 8.0+ (for JSON column support)
-- - Character set: utf8mb4 (required for proper Unicode support)
-- - Collation: utf8mb4_unicode_ci
--
-- Usage:
-- 1. Connect to MySQL as root or admin user:
--    mysql -u root -p
--
-- 2. Run this script:
--    SOURCE /path/to/mysql-setup.sql;
--    OR
--    mysql -u root -p < /path/to/mysql-setup.sql
--
-- 3. After running this script:
--    - Update .env file with the database credentials
--    - Run: php artisan migrate --force
--    - Run: php artisan db:seed --force (if seeding is needed)

-- ============================================================================
-- 1. Create Production Database
-- ============================================================================

CREATE DATABASE IF NOT EXISTS lapju_production
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

-- ============================================================================
-- 2. Create Production Database User
-- ============================================================================

-- For localhost access (most common)
CREATE USER IF NOT EXISTS 'lapju_user'@'localhost'
  IDENTIFIED BY 'CHANGE_THIS_TO_SECURE_PASSWORD';

-- For remote access (if application server is separate from database server)
-- Uncomment and modify the IP address as needed:
-- CREATE USER IF NOT EXISTS 'lapju_user'@'192.168.1.100'
--   IDENTIFIED BY 'CHANGE_THIS_TO_SECURE_PASSWORD';

-- For any host access (NOT RECOMMENDED for production, only for development)
-- CREATE USER IF NOT EXISTS 'lapju_user'@'%'
--   IDENTIFIED BY 'CHANGE_THIS_TO_SECURE_PASSWORD';

-- ============================================================================
-- 3. Grant Privileges
-- ============================================================================

-- Grant all privileges on production database to the user
GRANT ALL PRIVILEGES ON lapju_production.*
  TO 'lapju_user'@'localhost';

-- If you created a user for remote access, grant privileges here too:
-- GRANT ALL PRIVILEGES ON lapju_production.*
--   TO 'lapju_user'@'192.168.1.100';

-- ============================================================================
-- 4. Apply Changes
-- ============================================================================

FLUSH PRIVILEGES;

-- ============================================================================
-- 5. Verify Setup (Optional)
-- ============================================================================

-- Show created database
SHOW DATABASES LIKE 'lapju_production';

-- Show created user
SELECT User, Host FROM mysql.user WHERE User = 'lapju_user';

-- Show database character set and collation
SELECT
  DEFAULT_CHARACTER_SET_NAME,
  DEFAULT_COLLATION_NAME
FROM information_schema.SCHEMATA
WHERE SCHEMA_NAME = 'lapju_production';

-- ============================================================================
-- Additional Notes
-- ============================================================================

-- To verify the user can access the database:
-- mysql -u lapju_user -p lapju_production

-- To show all privileges for the user:
-- SHOW GRANTS FOR 'lapju_user'@'localhost';

-- To drop the user (if you need to start over):
-- DROP USER IF EXISTS 'lapju_user'@'localhost';

-- To drop the database (WARNING: This will delete all data!):
-- DROP DATABASE IF EXISTS lapju_production;

-- ============================================================================
-- Security Recommendations
-- ============================================================================

-- 1. Change the default password 'CHANGE_THIS_TO_SECURE_PASSWORD' to a strong password
-- 2. Use localhost connection when possible (more secure than remote)
-- 3. Limit privileges to only what's needed (ALL PRIVILEGES is fine for application use)
-- 4. Enable MySQL SSL/TLS for remote connections
-- 5. Regularly backup the database
-- 6. Monitor database access logs
-- 7. Keep MySQL updated to the latest stable version

-- ============================================================================
-- End of Script
-- ============================================================================
