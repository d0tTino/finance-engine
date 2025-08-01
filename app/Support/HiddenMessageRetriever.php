<?php

/*
 * HiddenMessageRetriever.php
 * Copyright (c) 2025
 *
 * This file is part of Firefly III (https://github.com/firefly-iii).
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace FireflyIII\Support;

/**
 * Class HiddenMessageRetriever
 *
 * Utility to recursively scan arrays or objects for hidden messages.
 */
class HiddenMessageRetriever
{
    /**
     * Retrieve all nested messages from data that contain the keys
     * 'hidden' and 'message'.
     */
    public static function getMessages(array|object $data): array
    {
        $messages = [];
        self::collect($data, $messages);

        return $messages;
    }

    /**
     * @param array|object $data
     * @param array        $messages
     */
    private static function collect(array|object $data, array &$messages): void
    {
        $arrayData = (array) $data;

        if (isset($arrayData['hidden'], $arrayData['message']) && true === $arrayData['hidden']) {
            $messages[] = $arrayData['message'];
        }

        foreach ($arrayData as $value) {
            if (is_array($value) || is_object($value)) {
                self::collect($value, $messages);
            }
        }
    }
}
