<?php

namespace Tests\Unit;

use App\Support\TestingDatabaseGuard;
use Illuminate\Config\Repository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class TestingDatabaseGuardTest extends TestCase
{
    #[DataProvider('unsafeTestingDatabases')]
    public function test_it_blocks_unsafe_databases_in_testing(mixed $database): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsafe testing database configuration');

        TestingDatabaseGuard::assertConnectionIsSafe('testing', 'mysql', [
            'driver' => 'mysql',
            'database' => $database,
        ]);
    }

    /** @return array<string, array{mixed}> */
    public static function unsafeTestingDatabases(): array
    {
        return [
            'local database' => ['remedic_core_local'],
            'production-like database' => ['remedic_core'],
            'empty database' => [''],
            'null database' => [null],
        ];
    }

    #[DataProvider('safeNamedTestingDatabases')]
    public function test_it_allows_explicitly_named_testing_databases(string $database): void
    {
        TestingDatabaseGuard::assertConnectionIsSafe('testing', 'mysql', [
            'driver' => 'mysql',
            'database' => $database,
        ]);

        $this->addToAssertionCount(1);
    }

    /** @return array<string, array{string}> */
    public static function safeNamedTestingDatabases(): array
    {
        return [
            'testing suffix' => ['remedic_core_testing'],
            'test suffix' => ['something_test'],
        ];
    }

    public function test_it_allows_only_in_memory_sqlite_in_testing(): void
    {
        TestingDatabaseGuard::assertConnectionIsSafe('testing', 'sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        $this->addToAssertionCount(1);
    }

    public function test_it_blocks_file_backed_sqlite_in_testing(): void
    {
        $this->expectException(RuntimeException::class);

        TestingDatabaseGuard::assertConnectionIsSafe('testing', 'sqlite', [
            'driver' => 'sqlite',
            'database' => 'database/testing.sqlite',
        ]);
    }

    public function test_it_does_not_affect_the_local_environment(): void
    {
        TestingDatabaseGuard::assertConnectionIsSafe('local', 'mysql', [
            'driver' => 'mysql',
            'database' => 'remedic_core_local',
        ]);

        $this->addToAssertionCount(1);
    }

    public function test_it_does_not_affect_the_production_environment(): void
    {
        TestingDatabaseGuard::assertConnectionIsSafe('production', 'mysql', [
            'driver' => 'mysql',
            'database' => 'remedic_core',
        ]);

        $this->addToAssertionCount(1);
    }

    public function test_it_checks_the_database_resolved_from_database_url(): void
    {
        $config = new Repository([
            'app' => ['env' => 'testing'],
            'database' => [
                'default' => 'mysql',
                'connections' => [
                    'mysql' => [
                        'driver' => 'mysql',
                        'database' => 'remedic_core_testing',
                        'url' => 'mysql://127.0.0.1/remedic_core',
                    ],
                ],
            ],
        ]);

        $this->expectException(RuntimeException::class);

        TestingDatabaseGuard::assertConfigurationIsSafe($config);
    }

    public function test_it_honors_a_testing_environment_requested_by_artisan(): void
    {
        $config = new Repository([
            'app' => ['env' => 'local'],
            'database' => [
                'default' => 'mysql',
                'connections' => [
                    'mysql' => [
                        'driver' => 'mysql',
                        'database' => 'remedic_core_local',
                    ],
                ],
            ],
        ]);

        $this->expectException(RuntimeException::class);

        TestingDatabaseGuard::assertConfigurationIsSafe($config, 'testing');
    }

    public function test_it_checks_a_connection_explicitly_selected_by_artisan(): void
    {
        $config = new Repository([
            'app' => ['env' => 'local'],
            'database' => [
                'default' => 'sqlite',
                'connections' => [
                    'sqlite' => [
                        'driver' => 'sqlite',
                        'database' => ':memory:',
                    ],
                    'old_core' => [
                        'driver' => 'mysql',
                        'database' => 'remedic_core',
                    ],
                ],
            ],
        ]);

        $this->expectException(RuntimeException::class);

        TestingDatabaseGuard::assertConfigurationIsSafe($config, 'testing', 'old_core');
    }
}
