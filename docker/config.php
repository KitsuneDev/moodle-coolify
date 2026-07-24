<?php
declare(strict_types=1);

unset($CFG);
global $CFG;
$CFG = new stdClass();

function coolify_moodle_env(string $name, ?string $default = null): string {
    $value = getenv($name);
    if ($value === false || $value === '') {
        if ($default !== null) {
            return $default;
        }
        throw new RuntimeException("Required environment variable {$name} is missing.");
    }
    return $value;
}

function coolify_moodle_bool(string $name, bool $default): bool {
    $value = getenv($name);
    if ($value === false || $value === '') {
        return $default;
    }

    return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
}

$url = rtrim(coolify_moodle_env('MOODLE_URL'), '/');
$urlParts = parse_url($url);
if (
    $urlParts === false ||
    !isset($urlParts['scheme'], $urlParts['host']) ||
    !in_array(strtolower($urlParts['scheme']), ['http', 'https'], true)
) {
    throw new RuntimeException('MOODLE_URL must be an absolute HTTP or HTTPS URL.');
}

$dbPrefix = coolify_moodle_env('MOODLE_DB_PREFIX', 'mdl_');
if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $dbPrefix)) {
    throw new RuntimeException('MOODLE_DB_PREFIX contains invalid characters.');
}

$dbSsl = coolify_moodle_env('MOODLE_DB_SSL', 'disable');
if (!in_array($dbSsl, ['disable', 'prefer', 'require', 'verify-full'], true)) {
    throw new RuntimeException('MOODLE_DB_SSL must be disable, prefer, require, or verify-full.');
}

$CFG->dbtype = 'pgsql';
$CFG->dblibrary = 'native';
$CFG->dbhost = coolify_moodle_env('MOODLE_DB_HOST', 'postgres');
$CFG->dbname = coolify_moodle_env('MOODLE_DB_NAME', 'moodle');
$CFG->dbuser = coolify_moodle_env('MOODLE_DB_USER', 'moodle');
$CFG->dbpass = coolify_moodle_env('MOODLE_DB_PASSWORD');
$CFG->prefix = $dbPrefix;
$CFG->dboptions = [
    'dbpersist' => false,
    'dbsocket' => false,
    'dbport' => coolify_moodle_env('MOODLE_DB_PORT', '5432'),
    'ssl' => $dbSsl,
    'connecttimeout' => 10,
];

$CFG->wwwroot = $url;
$CFG->dataroot = '/var/www/moodledata';
$CFG->admin = 'admin';
$CFG->directorypermissions = 02770;

$CFG->reverseproxy = coolify_moodle_bool('MOODLE_REVERSE_PROXY', true);
$CFG->sslproxy = strtolower($urlParts['scheme']) === 'https';
$CFG->cookiesecure = $CFG->sslproxy;
$CFG->cookiehttponly = true;

// The persistent code volume supports web-managed plugins and themes. These
// controls can restore image/CLI-only management for more restrictive sites.
$CFG->disableupdateautodeploy = coolify_moodle_bool('MOODLE_DISABLE_WEB_PLUGIN_INSTALL', false);
$CFG->uninstallclionly = coolify_moodle_bool('MOODLE_UNINSTALL_CLI_ONLY', false);
$CFG->expectedcronfrequency = 200;

$upgradeKey = getenv('MOODLE_UPGRADE_KEY');
if ($upgradeKey !== false && $upgradeKey !== '') {
    $CFG->upgradekey = $upgradeKey;
}

// Redis is used for sessions. Configure a separate Redis cache store in
// Site administration if application-level MUC caching is needed.
$CFG->session_handler_class = '\\core\\session\\redis';
$CFG->session_redis_host = coolify_moodle_env('MOODLE_REDIS_HOST', 'redis');
$CFG->session_redis_port = (int) coolify_moodle_env('MOODLE_REDIS_PORT', '6379');
$CFG->session_redis_database = 0;
$CFG->session_redis_auth = coolify_moodle_env('MOODLE_REDIS_PASSWORD');
$CFG->session_redis_prefix = coolify_moodle_env('MOODLE_REDIS_PREFIX', 'moodle_sessions_');
$CFG->session_redis_acquire_lock_timeout = 120;
$CFG->session_redis_acquire_lock_retry = 100;
$CFG->session_redis_serializer_use_igbinary = false;

umask(0007);

require_once(__DIR__ . '/lib/setup.php');
