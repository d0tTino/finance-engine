<?php

return [
    'debt_simulation_cache_ttl' => 3600,
    'debt_simulation_strategies' => [
        \FireflyIII\Modules\AI\Simulations\Strategies\AvalancheStrategy::class,
        \FireflyIII\Modules\AI\Simulations\Strategies\SnowballStrategy::class,
        \FireflyIII\Modules\AI\Simulations\Strategies\BalancedStrategy::class,
    ],
];
