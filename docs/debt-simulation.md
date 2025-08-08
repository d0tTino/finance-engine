# Debt Simulation

The debt simulation endpoint evaluates multiple payoff strategies and returns ranked plans with metrics.

## Endpoint

`POST /api/v1/simulations/debt`

## Request

```json
{
  "user_id": "550e8400-e29b-41d4-a716-446655440000",
  "group_id": "3ba92f16-8ef0-4e25-9ad2-8152ef7491a7",
  "monthly_budget": 500,
  "max_options": 2,
  "accounts": [
    {
      "account_id": "af13c6c4-1d05-4d26-8b16-ef3d988c1f02",
      "balance": 4500,
      "apr": 15.99,
      "minimum_payment": 75
    },
    {
      "account_id": "b2a1a148-9b91-455c-8964-fba303b3f7ca",
      "balance": 1200,
      "apr": 7.5,
      "minimum_payment": 25
    }
  ]
}
```

### Request fields

- `user_id` – Owning user identifier (UUID). Must match the authenticated user; mismatches are rejected.
- `group_id` – User group identifier (UUID). Validated with [ValidatesUserGroupTrait](../app/Support/Http/Api/ValidatesUserGroupTrait.php) to ensure the authenticated user belongs to the group, preventing cross-group data exposure.
- `monthly_budget` – Amount available each month for debt repayment.
- `max_options` – Maximum number of strategies to return.
- `accounts` – Array of debts to simulate. Each account contains:
  - `account_id` – Unique account identifier (UUID).
  - `balance` – Current outstanding balance.
  - `apr` – Annual percentage rate in percent (e.g. `7.5` for 7.5%).
  - `minimum_payment` – Minimum amount due each month.

> **APR handling:** Values must be provided as percentages. The service converts APR to monthly interest internally.

> **User ID verification:** The endpoint validates that every account belongs to the provided `user_id` or `group_id` to prevent cross-user data access.

## Response

```json
{
  "analysis_id": "b4383ee0-1e6b-4b4e-8f4e-01fb9c93b5b4",

  "proposed_actions": [
    {
      "rank": 1,
      "is_optimal": true,
      "plan": {
        "strategy": "avalanche",
        "schedule": [
          {
            "month": 1,
            "payments": {"10": 500, "11": 0},
            "balances": {"10": 4000, "11": 1200},

            "interest": 60,
            "payment": 500,
            "cash_flow": 0
          }

        ]
      },
      "metrics": {
        "interest_saved": 61.88,
        "time_to_payoff_months": 34,
        "total_interest_paid": 516.09,
        "monthly_cash_flow": [
          {"month": 1, "cash_flow": 0}
        ]
      },
      "cost_of_deviation": {
        "currency": 0,
        "time_months": 0
      },
      "meta": {
        "ranking_heuristic": "interest_then_months"

      }
    },
    {
      "rank": 2,
      "is_optimal": false,
      "plan": {
        "strategy": "snowball",
        "schedule": [
          {
            "month": 1,
            "payments": {"10": 475, "11": 25},
            "balances": {"10": 4025, "11": 1175},

            "interest": 57.19,
            "payment": 500,
            "cash_flow": 0
          }

        ]
      },
      "metrics": {
        "interest_saved": 0,
        "time_to_payoff_months": 36,
        "total_interest_paid": 577.97,
        "monthly_cash_flow": [
          {"month": 1, "cash_flow": 0}
        ]
      },
      "cost_of_deviation": {
        "currency": 61.88,
        "time_months": 2
      },
      "meta": {
        "ranking_heuristic": "interest_then_months"

      }
    }
  ]
}
```

### Response fields

- `analysis_id` – Identifier for this simulation run.
- `ranking_heuristic` – Ranking algorithm applied to the plans.
- `proposed_actions` – Array of ranked payoff plans:
  - `rank` – Position of the plan when sorted by total interest (1 is best).
  - `is_optimal` – Indicates whether the plan is the top-ranked option.
  - `plan` – Detailed strategy output:
    - `strategy` – Name of the heuristic applied (`avalanche` or `snowball`).
    - `schedule` – Monthly breakdown of payments, balances, interest and cash flow.
  - `metrics` – Aggregated plan results:
    - `interest_saved` – Interest saved compared to the worst plan.
    - `time_to_payoff_months` – Number of months to clear all debts.
    - `total_interest_paid` – Total interest paid over the lifetime of the plan.
    - `monthly_cash_flow` – Remaining budget for each month.
  - `cost_of_deviation` – Extra cost versus the optimal plan:
    - `currency` – Additional interest compared to the optimal plan.
    - `time_months` – Additional duration compared to the optimal plan in months.

## Heuristics

Two heuristics are supported:

- **Avalanche** – Prioritises accounts with the highest interest rate.
- **Snowball** – Targets the smallest balance first to build momentum.

## Ranking and Deviation

Plans are sorted by total interest and assigned a `rank`. The `cost_of_deviation` object captures the additional interest and time a plan requires compared to the optimal strategy, helping users quantify trade-offs when selecting a less optimal option.

