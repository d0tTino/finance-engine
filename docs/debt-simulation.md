# Debt Simulation

The debt simulation endpoint evaluates multiple payoff strategies and returns ranked plans with metrics.

## Endpoint

`POST /api/v1/simulations/debt`

## Request

```json
{
  "user_id": 1,
  "group_id": 1,
  "monthly_budget": 500,
  "max_options": 2,
  "accounts": [
    {"id": 10, "balance": 4500, "rate": 15.99},
    {"id": 11, "balance": 1200, "rate": 7.5}
  ]
}
```

### Request fields

- `user_id` – Owning user identifier.
- `group_id` – User group identifier.
- `monthly_budget` – Amount available each month for debt repayment.
- `max_options` – Maximum number of strategies to return.
- `accounts` – Array of debts to simulate. Each account contains:
  - `id` – Unique account identifier.
  - `balance` – Current outstanding balance.
  - `rate` – Annual percentage rate (APR).

## Response

```json
{
  "data": [
    {
      "strategy": "avalanche",
      "order": [10, 11],
      "metrics": {
        "months": 34,
        "interest": 650.23
      },
      "rank": 1,
      "deviation_cost": 0
    },
    {
      "strategy": "snowball",
      "order": [11, 10],
      "metrics": {
        "months": 36,
        "interest": 712.11
      },
      "rank": 2,
      "deviation_cost": 61.88
    }
  ]
}
```

### Response fields

- `strategy` – Name of the heuristic applied (`avalanche` or `snowball`).
- `order` – Account IDs in the payoff order.
- `metrics` – Aggregated plan results.
  - `months` – Number of months to clear all debts.
  - `interest` – Total interest paid in the plan.
- `rank` – Position of the plan when sorted by total interest (1 is best).
- `deviation_cost` – Extra interest paid compared to the top-ranked plan.

## Heuristics

Two heuristics are supported:

- **Avalanche** – Prioritises accounts with the highest interest rate.
- **Snowball** – Targets the smallest balance first to build momentum.

## Ranking and Deviation

Plans are sorted by total interest and assigned a `rank`. The `deviation_cost` represents the difference in interest between a plan and the cheapest option, helping users quantify the trade-off when selecting a less optimal strategy.

