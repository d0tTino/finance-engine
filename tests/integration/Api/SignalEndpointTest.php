<?php

declare(strict_types=1);

namespace Tests\integration\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\integration\TestCase;
use Override;

/**
 * @internal
 *
 * @coversNothing
 */
final class SignalEndpointTest extends TestCase
{
    use RefreshDatabase;

    private $user;

    #[Override]
    protected function setUp(): void
    {
        $dbPath = dirname(__DIR__, 3).'/storage/database/database.sqlite';
        if (!file_exists($dbPath)) {
            @mkdir(dirname($dbPath), 0o777, true);
            touch($dbPath);
        }
        parent::setUp();
        if (!isset($this->user)) {
            $this->user = $this->createAuthenticatedUser();
        }
        $this->actingAs($this->user);
    }

    public function testValidationErrorForMissingPayload(): void
    {
        $response = $this->postJson('/api/v1/signal', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['url', 'payload']);
    }

    public function testForwardingOfValidPayload(): void
    {
        Http::fake([
            'https://example.com/hook' => Http::response(['status' => 'ok'], 200),
        ]);

        $response = $this->postJson('/api/v1/signal', [
            'url' => 'https://example.com/hook',
            'payload' => ['foo' => 'bar'],
        ]);

        $response->assertStatus(200);

        Http::assertSent(static function ($request): bool {
            return 'https://example.com/hook' === $request->url()
                && $request['foo'] === 'bar';
        });
    }
}
