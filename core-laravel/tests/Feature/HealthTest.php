<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_health_reports_all_dependencies(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJson([
                'status' => 'ok',
                'checks' => ['database' => true, 'redis' => true, 'rabbitmq' => true],
            ]);
    }
}
