<?php

declare(strict_types=1);

namespace Tests\Feature;

use FireflyIII\Console\Commands\Correction\CreatesGroupMemberships;
use FireflyIII\User;
use Illuminate\Support\Facades\Cache;
use FireflyIII\Http\Middleware\Authenticate;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use FireflyIII\Http\Middleware\OpaMiddleware;
use Mockery;
use Tests\integration\TestCase as IntegrationTestCase;

final class DebtSimulationApiTest extends IntegrationTestCase
{
    public function testUserIsolationAndCaching(): void
    {
        Cache::setDefaultDriver('file');
        putenv('CACHE_DRIVER=file');
        Cache::flush();
        $this->withoutMiddleware([Authenticate::class, 'auth:api', 'auth:api,sanctum', EnsureFrontendRequestsAreStateful::class, OpaMiddleware::class]);
        config(['auth.defaults.guard' => 'web']);

        $accounts   = [
            ['id' => 1, 'balance' => 100.0, 'rate' => 5.0],
            ['id' => 2, 'balance' => 200.0, 'rate' => 3.0],
        ];
        $budget     = 50.0;
        $maxOptions = 2;
        $hash       = hash('sha256', serialize([$accounts, $budget, $maxOptions]));

        $user1 = User::create(['email' => 'user1@example.com', 'password' => 'secret']);
        CreatesGroupMemberships::createGroupMembership($user1);
        $user1->refresh();
        $this->be($user1);

        $payload1 = [
            'user_id'        => (string) $user1->id,
            'group_id'       => null === $user1->user_group_id ? null : (string) $user1->user_group_id,
            'accounts'       => $accounts,
            'monthly_budget' => $budget,
            'max_options'    => $maxOptions,
        ];

        $response1  = $this->postJson('/api/v1/simulations/debt', $payload1)->assertOk();
        $response1b = $this->postJson('/api/v1/simulations/debt', $payload1)->assertOk();
        $this->assertSame($response1->json(), $response1b->json());

        $user2 = User::create(['email' => 'user2@example.com', 'password' => 'secret']);
        CreatesGroupMemberships::createGroupMembership($user2);
        $user2->refresh();
        $this->be($user2);

        $payload2 = [
            'user_id'        => (string) $user2->id,
            'group_id'       => null === $user2->user_group_id ? null : (string) $user2->user_group_id,
            'accounts'       => $accounts,
            'monthly_budget' => $budget,
            'max_options'    => $maxOptions,
        ];

        $response2  = $this->postJson('/api/v1/simulations/debt', $payload2)->assertOk();
        $response2b = $this->postJson('/api/v1/simulations/debt', $payload2)->assertOk();
        $this->assertSame($response2->json(), $response2b->json());
    }
}
