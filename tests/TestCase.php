<?php

namespace Tests;

use App\Support\TestingDatabaseGuard;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();

        TestingDatabaseGuard::assertConfigurationIsSafe($app->make('config'));

        return $app;
    }
}
