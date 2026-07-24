<?php
declare(strict_types=1);

function required_env(string $name): string {
    $value = getenv($name);
    if ($value === false || $value === '') {
        fwrite(STDERR, "Missing environment variable: {$name}\n");
        exit(2);
    }
    return $value;
}

function pg_connection_value(string $value): string {
    return "'" . addcslashes($value, "\\'") . "'";
}

$prefix = getenv('MOODLE_DB_PREFIX') ?: 'mdl_';
if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $prefix)) {
    fwrite(STDERR, "Invalid database prefix.\n");
    exit(2);
}

$sslMode = getenv('MOODLE_DB_SSL') ?: 'disable';
if (!in_array($sslMode, ['disable', 'prefer', 'require', 'verify-full'], true)) {
    fwrite(STDERR, "Invalid database SSL mode.\n");
    exit(2);
}

try {
    $connectionString = sprintf(
        'host=%s port=%s dbname=%s user=%s password=%s sslmode=%s connect_timeout=10',
        pg_connection_value(required_env('MOODLE_DB_HOST')),
        pg_connection_value(getenv('MOODLE_DB_PORT') ?: '5432'),
        pg_connection_value(required_env('MOODLE_DB_NAME')),
        pg_connection_value(required_env('MOODLE_DB_USER')),
        pg_connection_value(required_env('MOODLE_DB_PASSWORD')),
        $sslMode,
    );

    $connection = @pg_connect($connectionString, PGSQL_CONNECT_FORCE_NEW);
    if ($connection === false) {
        throw new RuntimeException('PostgreSQL connection failed.');
    }

    $schemaResult = pg_query($connection, 'SELECT current_schema()');
    if ($schemaResult === false) {
        throw new RuntimeException(pg_last_error($connection));
    }
    $schema = (string) pg_fetch_result($schemaResult, 0, 0);
    $relation = $schema . '.' . $prefix . 'config';

    $relationResult = pg_query_params(
        $connection,
        'SELECT to_regclass($1)',
        [$relation],
    );
    if ($relationResult === false) {
        throw new RuntimeException(pg_last_error($connection));
    }
    $configTable = pg_fetch_result($relationResult, 0, 0);

    if ($configTable === false || $configTable === null) {
        $countResult = pg_query_params(
            $connection,
            'SELECT count(*) FROM pg_catalog.pg_tables WHERE schemaname = $1',
            [$schema],
        );
        if ($countResult === false) {
            throw new RuntimeException(pg_last_error($connection));
        }
        $tableCount = (int) pg_fetch_result($countResult, 0, 0);

        if ($tableCount > 0) {
            fwrite(STDERR, "The database is not empty, but no Moodle config table was found.\n");
            exit(11);
        }

        echo "empty\n";
        exit(10);
    }

    echo "installed\n";
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, "Unable to inspect the Moodle database: {$exception->getMessage()}\n");
    exit(2);
}
