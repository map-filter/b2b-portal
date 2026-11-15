<?php

namespace Tests;

use App\Services\Messaging\InMemoryBus;
use App\Services\Messaging\MessageBus;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected InMemoryBus $bus;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bus = new InMemoryBus;
        $this->app->instance(MessageBus::class, $this->bus);
    }
}
