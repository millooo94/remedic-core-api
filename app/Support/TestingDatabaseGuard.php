<?php

namespace App\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\ConfigurationUrlParser;
use RuntimeException;
use Throwable;

final class TestingDatabaseGuard
{
    /** @var list<string> */
    private const NAMED_DATABASE_DRIVERS = ['mysql', 'mariadb', 'pgsql', 'sqlsrv'];

    private const TEST_DATABASE_PATTERN = '/\A[A-Z0-9][A-Z0-9_-]*_(?:TEST|TESTING)\z/iD';

    public static function assertConfigurationIsSafe(
        Repository $config,
        mixed $requestedEnvironment = null,
        mixed $requestedConnection = null,
    ): void {
        $environment = $requestedEnvironment === 'testing'
            ? 'testing'
            : $config->get('app.env');
        $connectionName = is_string($requestedConnection) && $requestedConnection !== ''
            ? $requestedConnection
            : $config->get('database.default');
        $connections = $config->get('database.connections');
        $connection = is_string($connectionName) && is_array($connections)
            ? ($connections[$connectionName] ?? null)
            : null;

        self::assertConnectionIsSafe($environment, $connectionName, $connection);
    }

    public static function assertConnectionIsSafe(
        mixed $environment,
        mixed $connectionName,
        mixed $connection,
    ): void {
        if ($environment !== 'testing') {
            return;
        }

        if (! is_string($connectionName) || $connectionName === '' || ! is_array($connection)) {
            throw self::unsafeConfiguration($connectionName);
        }

        try {
            $resolvedConnection = (new ConfigurationUrlParser)->parseConfiguration($connection);
        } catch (Throwable) {
            throw self::unsafeConfiguration($connectionName);
        }

        $driver = $resolvedConnection['driver'] ?? null;
        $database = $resolvedConnection['database'] ?? null;

        if ($driver === 'sqlite' && $database === ':memory:') {
            return;
        }

        if (is_string($driver)
            && in_array($driver, self::NAMED_DATABASE_DRIVERS, true)
            && is_string($database)
            && preg_match(self::TEST_DATABASE_PATTERN, $database) === 1) {
            return;
        }

        throw self::unsafeConfiguration($connectionName, $driver, $database);
    }

    private static function unsafeConfiguration(
        mixed $connectionName,
        mixed $driver = null,
        mixed $database = null,
    ): RuntimeException {
        return new RuntimeException(sprintf(
            'Unsafe testing database configuration: refusing to run tests or migrations against a non-test database (connection: %s, driver: %s, database: %s).',
            self::describe($connectionName),
            self::describe($driver),
            self::describe($database),
        ));
    }

    private static function describe(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '<empty>';
        }

        return is_scalar($value) ? (string) $value : '<invalid>';
    }
}
