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

$prefix = getenv('MOODLE_DB_PREFIX') ?: 'mdl_';
if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $prefix)) {
    fwrite(STDERR, "Invalid database prefix.\n");
    exit(2);
}

$sslMode = getenv('MOODLE_DB_SSL') ?: 'disable';
$dsn = sprintf(
    'pgsql:host=%s;port=%s;dbname=%s;sslmode=%s',
    required_env('MOODLE_DB_HOST'),
    getenv('MOODLE_DB_PORT') ?: '5432',
    required_env('MOODLE_DB_NAME'),
    $sslMode,
);

try {
    $pdo = new PDO(
        $dsn,
        required_env('MOODLE_DB_USER'),
        required_env('MOODLE_DB_PASSWORD'),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ],
    );

    $schema = (string) $pdo->query('SELECT current_schema()')->fetchColumn();
    $relation = $schema . '.' . $prefix . 'config';

    $statement = $pdo->prepare('SELECT to_regclass(:relation)');
    $statement->execute(['relation' => $relation]);
    $row = $statement->fetch(PDO::FETCH_NUM);

    if (!$row || $row[0] === null) {
        $tableCount = (int) $pdo
            ->query("SELECT count(*) FROM pg_catalog.pg_tables WHERE schemaname = current_schema()")
            ->fetchColumn();

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
