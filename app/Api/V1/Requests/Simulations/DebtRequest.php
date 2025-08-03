<?php

/*
 * DebtRequest.php
 *
 * This file is part of Firefly III (https://github.com/firefly-iii).
 *
 * Copyright (c) 2025
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

namespace FireflyIII\Api\V1\Requests\Simulations;

use FireflyIII\Support\Request\ChecksLogin;
use FireflyIII\Support\Request\ConvertsDataTypes;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Class DebtRequest
 *
 * Validates the payload for the debt simulation endpoint.
 */
class DebtRequest extends FormRequest
{
    use ChecksLogin;
    use ConvertsDataTypes;

    /**
     * Extract validated data with proper types.
     *
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return [
            'user_id'        => $this->convertInteger('user_id'),
            'group_id'       => $this->convertInteger('group_id'),
            'accounts'       => $this->get('accounts', []),
            'monthly_budget' => $this->convertFloat('monthly_budget'),
            'max_options'    => $this->convertInteger('max_options'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'user_id'        => 'required|integer',
            'group_id'       => 'required|integer',
            'accounts'       => 'required|array|min:1',
            'monthly_budget' => 'required|numeric|min:0',
            'max_options'    => 'required|integer|min:1',
        ];
    }
}
