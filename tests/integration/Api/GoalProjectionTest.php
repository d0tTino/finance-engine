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

    public function testYearsParameterControlsNumberOfResults(): void
    {
        $response = $this->getJson(route('api.v1.goal-projection', [
            'goal' => 1,
            'years' => 2,
        ]));

        $response->assertOk();
        $response->assertJsonCount(24, 'data');
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
