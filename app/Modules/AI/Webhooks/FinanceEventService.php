<?php

declare(strict_types=1);

namespace FireflyIII\Modules\AI\Webhooks;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use JsonException;

use function Safe\json_encode;

class FinanceEventService
{
    private string $prefix = 'ume.events.finance.';

    /**
     * Publish the given payload as JSON on the given topic.
     */
    public function publish(string $topic, array $payload): void
    {
        if (app()->environment('testing')) {
            return;
        }

        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            Log::error('Could not encode finance event payload: '.$e->getMessage());

            return;
        }

        $channel = $this->prefix.$topic;
        Redis::publish($channel, $json);
        Log::debug(sprintf('Published finance event on %s', $channel));
    }
}
