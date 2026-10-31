<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Refuses to run against a database whose name does not end in "_test".
     *
     * Runs before traits such as RefreshDatabase, so a misconfigured environment
     * (e.g. a cached config or a missing phpunit.xml override) can never wipe
     * the development database.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        $database = (string) $app['config']->get('database.connections.'.$app['config']->get('database.default').'.database');

        if (! str_ends_with($database, '_test')) {
            throw new RuntimeException("Tests must run against a dedicated *_test database, got [{$database}].");
        }

        return $app;
    }

    /**
     * No test may reach a real external service (e.g. OpenAI, which costs money):
     * every outgoing HTTP request must be faked with Http::fake(), or the test fails.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    /**
     * Send requests the way the SPA does. Sanctum only starts a session (cookie
     * authentication) for requests whose Origin is a stateful domain.
     */
    protected function fromSpa(): static
    {
        return $this->withHeaders([
            'Origin' => config('app.frontend_url'),
            'Accept' => 'application/json',
        ]);
    }
}
