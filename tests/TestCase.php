<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Ai\Embeddings;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * No test may reach a real embedding provider or need built assets.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Embeddings::fake();
        $this->withoutVite();
        $this->withHeader('Accept-Language', 'de');
    }

    /**
     * Runs on every application boot, before any refresh. The RefreshDatabase
     * trait's own beforeRefreshingDatabase() hook would shadow a method of
     * this name, so the guard is hooked into the application refresh instead.
     */
    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        $this->guardTestingDatabase();
    }

    /**
     * Refuse to run against any database other than the dedicated testing one.
     */
    protected function guardTestingDatabase(): void
    {
        $database = config('database.connections.'.config('database.default').'.database');

        if ($database !== 'tory_testing') {
            throw new RuntimeException("Refusing to run tests on database [{$database}]; tests must run on [tory_testing].");
        }
    }
}
