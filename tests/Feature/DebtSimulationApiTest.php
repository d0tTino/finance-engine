<?php

declare(strict_types=1);

namespace Tests\Feature;

use FireflyIII\Console\Commands\Correction\CreatesGroupMemberships;
use FireflyIII\User;
use Illuminate\Support\Facades\Cache;
use FireflyIII\Http\Middleware\Authenticate;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Illuminate\Support\Facades\Auth;
use Tests\integration\TestCase as IntegrationTestCase;

final class DebtSimulationApiTest extends IntegrationTestCase
{
    public function testUserIsolationAndCaching(): void
    {
        Cache::flush();
        $this->withoutMiddleware([Authenticate::class, 'auth:api', 'auth:api,sanctum', EnsureFrontendRequestsAreStateful::class]);
        config(['auth.defaults.guard' => 'web']);
        Auth::shouldUse('web');

        $accounts   = [
            ['id' => 1, 'balance' => 100.0, 'rate' => 5.0],
            ['id' => 2, 'balance' => 200.0, 'rate' => 3.0],
        ];
        $budget     = 50.0;
        $maxOptions = 2;
        $hash       = hash('sha256', serialize([$accounts, $budget, $maxOptions]));

        $user1       = User::create(['email' => 'user1@example.com', 'password' => 'secret']);
        CreatesGroupMemberships::createGroupMembership($user1);
        $user1->refresh();
        $currentUser = $user1;
        Auth::shouldReceive('check')->andReturn(true);
        Auth::shouldReceive('user')->andReturnUsing(static function () use (&$currentUser) {
            return $currentUser;
        });

        $payload1 = [
            'user_id'        => $user1->id,
            'group_id'       => $user1->user_group_id,
            'accounts'       => $accounts,
            'monthly_budget' => $budget,
            'max_options'    => $maxOptions,
        ];

        $response1 = $this->postJson('/api/v1/simulations/debt', $payload1)->assertOk();
        $key1      = sprintf('u:%d:g:%d:debt-sim-%s', $user1->id, $user1->user_group_id, $hash);
        $this->assertTrue(Cache::has($key1));

        Cache::spy();
        $response1b = $this->postJson('/api/v1/simulations/debt', $payload1)->assertOk();
        Cache::shouldHaveReceived('get')->with($key1)->once();
        $this->assertSame($response1->json(), $response1b->json());

        $user2 = User::create(['email' => 'user2@example.com', 'password' => 'secret']);
        CreatesGroupMemberships::createGroupMembership($user2);
        $user2->refresh();
        $currentUser = $user2;

        $payload2 = [
            'user_id'        => $user2->id,
            'group_id'       => $user2->user_group_id,
            'accounts'       => $accounts,
            'monthly_budget' => $budget,
            'max_options'    => $maxOptions,
        ];

        $key2 = sprintf('u:%d:g:%d:debt-sim-%s', $user2->id, $user2->user_group_id, $hash);
        $this->assertFalse(Cache::has($key2));
        $response2 = $this->postJson('/api/v1/simulations/debt', $payload2)->assertOk();
        $this->assertTrue(Cache::has($key2));
        $this->assertTrue(Cache::has($key1));
        $this->assertNotEquals($key1, $key2);

        Cache::spy();
        $response2b = $this->postJson('/api/v1/simulations/debt', $payload2)->assertOk();
        Cache::shouldHaveReceived('get')->with($key2)->once();
        $this->assertSame($response2->json(), $response2b->json());
    }
}
