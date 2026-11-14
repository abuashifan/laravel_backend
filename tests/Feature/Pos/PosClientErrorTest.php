<?php

namespace Tests\Feature\Pos;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class PosClientErrorTest extends TestCase
{
    use RefreshDatabase;

    public function test_error_report_is_logged_without_login(): void
    {
        Log::shouldReceive('channel')->with('stderr')->once()->andReturnSelf();
        Log::shouldReceive('warning')->once()->withArgs(fn (string $message, array $context) => $message === 'pos.client_error'
            && $context['message'] === 'Boom'
            && $context['source'] === 'react'
            && $context['user_id'] === null);

        $this->postJson('/api/pos/client-errors', ['message' => 'Boom', 'source' => 'react', 'url' => '/sales'])
            ->assertOk();
    }

    public function test_message_is_required_and_source_limited(): void
    {
        $this->postJson('/api/pos/client-errors', [])->assertStatus(422);
        $this->postJson('/api/pos/client-errors', ['message' => 'x', 'source' => 'lain'])->assertStatus(422);
    }

    public function test_reports_are_rate_limited(): void
    {
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('warning');

        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/api/pos/client-errors', ['message' => 'e'])->assertOk();
        }
        $this->postJson('/api/pos/client-errors', ['message' => 'e'])->assertStatus(429);
    }
}
