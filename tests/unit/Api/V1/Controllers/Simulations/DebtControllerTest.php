<?php

declare(strict_types=1);

namespace Tests\unit\Api\V1\Controllers\Simulations;

use FireflyIII\Api\V1\Controllers\Simulations\DebtController;
use FireflyIII\Api\V1\Requests\Simulations\DebtRequest;
use FireflyIII\Modules\AI\Simulations\DebtSimulationService;
use Illuminate\Http\JsonResponse;
use Tests\integration\TestCase;

final class DebtControllerTest extends TestCase
{
    public function testFallbackFormatsTradeoffsFromCostOfDeviation(): void
    {
        $service = $this->createMock(DebtSimulationService::class);

        $plan = [
            'rank'              => 2,
            'is_optimal'        => false,
            'strategy'          => 'snowball',
            'accounts'          => [
                ['account_id' => 'acc-1', 'name' => 'Debt One'],
            ],
            'schedule'          => [[
                'month'           => 1,
                'payments'        => ['acc-1' => ['amount' => 50.0]],
                'balances'        => ['acc-1' => ['amount' => 950.0]],
                'payments_legacy' => ['acc-1' => ['amount' => 45.0]],
                'balances_legacy' => ['acc-1' => ['amount' => 960.0]],
                'interest'        => 5.0,
                'payment'         => 50.0,
                'cash_flow'       => 20.0,
                'unused_budget'   => 5.0,
            ]],
            'recommendations'          => [],
            'legacy_recommendations'   => [],
            'status'                   => 'active',
            'interest_saved'           => 5.0,
            'time_to_payoff_months'    => 12,
            'total_interest'           => 100.0,
            'monthly_cash_flow'        => [['month' => 1, 'cash_flow' => 20.0]],
            'cost_of_deviation'        => ['currency' => 40.0, 'time_months' => 6],
            'meta'                     => [
                'ranking_heuristic'       => 'heuristic',
                'ranking_reason'          => null,
                'tradeoff_drivers'        => [],
                'legacy_tradeoff_drivers' => [],
                'heuristic_scores'        => [],
                'strategy_explanation'    => 'explanation',
                'accounts'                => [],
            ],
        ];

        $service->expects(self::once())
            ->method('simulate')
            ->willReturn([$plan]);

        $controller = new DebtController($service);

        $request = $this->createMock(DebtRequest::class);
        $request->method('getData')->willReturn([
            'user_id'        => 'user-uuid',
            'group_id'       => null,
            'accounts'       => [
                ['account_id' => 'acc-1', 'balance' => 100.0, 'apr' => 0.05, 'minimum_payment' => 20.0],
            ],
            'monthly_budget' => 100.0,
            'max_options'    => 1,
        ]);

        $response = $controller($request);
        self::assertInstanceOf(JsonResponse::class, $response);

        $payload    = $response->getData(true);
        $tradeoffs  = $payload['proposed_actions'][0]['meta']['tradeoffs'] ?? null;

        self::assertIsString($tradeoffs);
        self::assertSame('loses 40.00 in interest savings and 6 extra months', $tradeoffs);
    }
}
