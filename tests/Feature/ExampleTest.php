<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_root_returns_service_info_as_json(): void
    {
        $this->getJson('/')->assertOk()->assertJsonStructure(['service', 'api', 'health']);
    }
}
