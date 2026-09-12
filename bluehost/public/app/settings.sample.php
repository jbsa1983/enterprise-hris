<?php
// Copy this file to settings.php and fill in your Bluehost/Zoom MySQL details
// (from cPanel → MySQL Databases). settings.php is gitignored; never commit secrets.
return [
    'db_host'     => getenv('DB_HOST') ?: 'localhost',
    'db_port'     => getenv('DB_PORT') ?: '3306',
    'db_name'     => getenv('DB_NAME') ?: 'hris',
    'db_user'     => getenv('DB_USER') ?: 'hris',
    'db_pass'     => getenv('DB_PASS') ?: '',
    // Generate a long random string, e.g. `php -r "echo bin2hex(random_bytes(48));"`
    'jwt_secret'  => getenv('JWT_SECRET') ?: 'change-me-to-a-long-random-value',
    'access_ttl'  => 1800,      // access token lifetime (seconds)
    'refresh_ttl' => 604800,    // refresh token lifetime (seconds)
    'storage_path'=> getenv('STORAGE_PATH') ?: (__DIR__ . '/../storage'),
    // Optional: folder where DB backups are written (counted in the storage card).
    'backup_path' => getenv('BACKUP_PATH') ?: null,
    // Optional: your hosting plan's storage limit in GB. When set, the dashboard
    // "Real-Time Storage" card shows usage against your plan. Leave 0/unset to
    // fall back to the (shared) server disk. See cPanel → Statistics for your limit.
    'storage_quota_gb' => (float) (getenv('STORAGE_QUOTA_GB') ?: 0),
    'cors_origin' => getenv('CORS_ORIGIN') ?: '',   // set to your domain if API is cross-origin
];
