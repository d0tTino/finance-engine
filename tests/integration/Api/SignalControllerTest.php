<?php

declare(strict_types=1);

namespace Tests\integration\Api;

use FireflyIII\Modules\AI\Strategy\BrokerSdk;
use FireflyIII\Http\Middleware\Authenticate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\integration\TestCase;
use Mockery;
use Override;

/**
 * @internal
 *
 * @coversNothing
 */
final class SignalControllerTest extends TestCase
{
    use RefreshDatabase;

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
        $this->withoutMiddleware(Authenticate::class);
    }

    public function testValidSignal(): void
    {
        $sdk = Mockery::mock(BrokerSdk::class);
        $sdk->shouldReceive('sendSignal')->once();
        $this->app->instance(BrokerSdk::class, $sdk);

        $response = $this->postJson('/api/v1/signals', [
            'asset'      => 'BTC',
            'action'     => 'buy',
            'confidence' => 0.9,
        ]);

        $response->assertStatus(202);
    }

    public function testInvalidSignal(): void
    {
        $response = $this->postJson('/api/v1/signals', [
            'asset'      => 'BTC',
            'confidence' => 1.5,
        ]);

        $response->assertStatus(422);
    }
}
