<?php

/*
 * HiddenMessageRetrieverTest.php
 *
 * This file is part of Firefly III (https://github.com/firefly-iii).
 */

declare(strict_types=1);

namespace Tests\unit\Support;

use FireflyIII\Support\HiddenMessageRetriever;
use Tests\integration\TestCase;

/**
 * @group unit-test
 * @group support
 *
 * @internal
 */
final class HiddenMessageRetrieverTest extends TestCase
{
    public function testRetrieveNestedMessages(): void
    {
        $data = [
            'level1' => [
                'hidden' => true,
                'message' => 'top-level',
                'next' => [
                    'hidden' => true,
                    'message' => 'nested',
                    'deep' => [
                        'not_hidden' => true,
                        'another' => [
                            'hidden' => true,
                            'message' => 'deeper',
                        ],
                    ],
                ],
            ],
        ];

        $messages = HiddenMessageRetriever::getMessages($data);

        $this->assertSame(['top-level', 'nested', 'deeper'], $messages);
    }
}
