<?php

declare(strict_types=1);

namespace Tests\integration\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\integration\TestCase;
use FireflyIII\Http\Middleware\OpaMiddleware;
use function Safe\mkdir;
use function Safe\touch;
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
            mkdir(dirname($dbPath), 0o777, true);
            touch($dbPath);
        }
        parent::setUp();
        if (!isset($this->user)) {
            $this->user = $this->createAuthenticatedUser();
        }
        $this->actingAs($this->user, 'api');
        $this->withoutMiddleware(OpaMiddleware::class);
    }

    public function testValidationErrorForMissingPayload(): void
    {
        $response = $this->postJson('/api/v1/signals', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['asset', 'action', 'confidence']);
    }

    public function testForwardingOfValidPayload(): void
    {
        config([
            'services.broker.driver' => 'freqtrade',
            'services.broker.freqtrade_url' => 'https://example.com/hook',
        ]);
        Http::fake([
            'https://example.com/hook' => Http::response(['status' => 'ok'], 200),
        ]);

        $response = $this->postJson('/api/v1/signals', [
            'asset' => 'BTC',
            'action' => 'buy',
            'confidence' => 0.9,
        ]);

        $response->assertStatus(202);

        Http::assertSent(static function ($request): bool {
            return 'https://example.com/hook' === $request->url()
                && 'BTC' === $request['asset']
                && 'buy' === $request['action']
                && 0.9 === (float) $request['confidence'];
        });
    }
}
