<?php

declare(strict_types=1);

namespace Tests\integration\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\integration\TestCase;
use Override;

/**
 * @internal
 *
 * @coversNothing
 */
final class GoalProjectionTest extends TestCase
{
    use RefreshDatabase;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        if (!isset($this->user)) {
            $this->user = $this->createAuthenticatedUser();
        }
        $this->actingAs($this->user);
    }

    public function testProjectionEndpointReturnsStructure(): void
    {
        $response = $this->getJson(route('api.v1.goal-projection', ['goal' => 1]));

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'date',
                    'lower',
                    'upper',
                    'median',
                ],
            ],
            'meta' => [
                'iterations',
            ],
        ]);
    }
}
