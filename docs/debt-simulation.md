# Debt Simulation

The debt simulation endpoint evaluates multiple payoff strategies and returns ranked plans with metrics.

## Endpoint

`POST /api/v1/simulations/debt`

## Configuration

Simulation results are cached per user to speed up repeated requests. The cache lifetime in seconds is configured via `ai.debt_simulation_cache_ttl` in `config/ai.php` and defaults to `3600`.

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
      "apr": 0.1599,
      "minimum_payment": 75
    },
    {
      "account_id": "b2a1a148-9b91-455c-8964-fba303b3f7ca",
      "balance": 1200,
      "apr": 0.075,
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
  - `apr` – Annual percentage rate as a decimal fraction (e.g. `0.21` for 21%).
  - `minimum_payment` – Minimum amount due each month.

> **APR handling:** Values must be provided as decimal fractions (e.g. `0.21` for 21%). The service converts APR to monthly interest internally.
>
> For example, the first account in the request above represents a 15.99% APR as `0.1599`. With a $4,500 balance the monthly interest is calculated as `4500 * 0.1599 / 12 ≈ 59.96`, which rounds to the `interest` value shown in the response schedule.

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

        ],
        "recommendations": [
          "Consider refinancing Loan1 to lower the 10.00% APR."
        ]
      },
      "metrics": {
        "interest_saved": 0,
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
        "ranking_heuristic": "interest_then_months",
        "strategy_explanation": "Pays extra toward the debt with the highest interest rate first."
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

        ],
        "recommendations": [
          "Consider refinancing Loan1 to lower the 10.00% APR."
        ]
      },
      "metrics": {
        "interest_saved": -61.88,
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
        "ranking_heuristic": "interest_then_months",
        "strategy_explanation": "Pays extra toward the debt with the smallest balance first to build momentum."
      }
    }
  ]
}
```

### Response fields

- `analysis_id` – Identifier for this simulation run.
- `ranking_heuristic` – Ranking algorithm applied to the plans.
- `proposed_actions` – Array of ranked payoff plans. If no strategies are configured, this array is empty:
  - `rank` – Position of the plan when sorted by total interest (1 is best).
  - `is_optimal` – Indicates whether the plan is the top-ranked option.
  - `plan` – Detailed strategy output:
    - `strategy` – Name of the heuristic applied (`avalanche`, `snowball` or `balanced`).
    - `schedule` – Monthly breakdown of payments, balances, interest and cash flow.
    - `recommendations` – Array of refinance suggestions derived from the input debts.
  - `metrics` – Aggregated plan results:
    - `interest_saved` – Interest saved compared to the optimal plan (negative values indicate extra interest).
    - `time_to_payoff_months` – Number of months to clear all debts.
    - `total_interest_paid` – Total interest paid over the lifetime of the plan.
    - `monthly_cash_flow` – Remaining budget for each month.
  - `cost_of_deviation` – Extra cost versus the optimal plan:
    - `currency` – Additional interest compared to the optimal plan.
    - `time_months` – Additional duration compared to the optimal plan in months.
  - `meta` – Additional information about the plan:
    - `strategy_explanation` – Description of how the payoff strategy works.
    - `ranking_heuristic` – Ranking algorithm applied to the plans.
    - `ranking_reason` – Explanation of why the plan received its rank.
    - `tradeoffs` – Summary of lost interest savings and extra time versus the optimal plan.

## Heuristics

The service supports three payoff strategies:

### Avalanche

Prioritises accounts with the highest interest rate.

### Snowball

Targets the smallest balance first to build momentum.

### Balanced

Distributes extra payments proportionally across outstanding debts using a smooth weighted round-robin algorithm so larger balances receive additional payments more frequently. If the monthly budget is less than the combined minimum payments, the strategy applies the minimums first and no extra funds are allocated, leaving the monthly `cash_flow` at `0`.

## Ranking and Deviation

Plans are sorted by total interest and assigned a `rank`. The `cost_of_deviation` object captures the additional interest and time a plan requires compared to the optimal strategy, helping users quantify trade-offs when selecting a less optimal option.

