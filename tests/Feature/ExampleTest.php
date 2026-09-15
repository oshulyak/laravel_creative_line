<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase {
    use RefreshDatabase;

    /**
     * Базе нужны таблицы даже для главной страницы: LoggerMiddleware
     * стоит в глобальном стеке и пишет строку access_logs на каждый запрос.
     */
    public function test_the_application_returns_a_successful_response(): void {
        $response = $this->get('/');

        $response->assertStatus(200);
    }
}
