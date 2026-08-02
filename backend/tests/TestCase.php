<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        if (! $this->app) {
            $this->refreshApplication();
        }

        // docker-compose sets DB_DATABASE as a real container env var, which
        // beats both phpunit.xml <env> overrides and .env.testing (Dotenv's
        // immutable loader never overwrites an already-set variable). Force
        // the test connection here, before RefreshDatabase migrates, so the
        // test suite never touches the dev database.
        config(['database.connections.pgsql.database' => 'meal_planner_test']);
        DB::purge('pgsql');

        parent::setUp();
    }
}
