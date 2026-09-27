-- Separate database for the PHPUnit suite (TEST_DB_NAME=logbook_test).
CREATE DATABASE IF NOT EXISTS logbook_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON logbook_test.* TO 'logbook'@'%';
