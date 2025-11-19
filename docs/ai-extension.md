# AI Extension

Firefly III includes experimental AI-powered features that extend the core application.

## Endpoints

- `POST /api/v1/plaid-hook` – Receives webhooks from Plaid and stores transaction groups after verifying the request signature.
- `GET /api/v1/goals/{goal}/projection` – Generates a Monte Carlo projection for a goal using parameters like `initial`, `mean`, `stdev`, and `years`.
- `POST /api/v1/signals` – Accepts trading signals and forwards them to the configured broker.
- `POST /api/v1/simulations/debt` – Evaluates debt payoff strategies using avalanche, snowball, balanced, and machine-learning heuristics.

## Modules

### Plaid Webhook
Handles incoming Plaid webhook payloads, validates the HMAC header, and stores transaction groups for the authenticated user.

### Goal Projection
Uses a Monte Carlo simulation service to estimate goal growth and returns a JSON time series with median, upper, and lower bounds.

### Strategy Signals
Validates trading signals (`asset`, `action`, `confidence`) and relays them via the broker SDK to external trading platforms.

#### Runtime adapters

Research code for trading ideas lives under `fe/strategies/` and typically
exposes convenience functions for feature engineering, signal generation and
backtesting.  When a strategy is ready for production the `fe.export.build`
utility packages a lightweight wheel named `polymarket_alpha`.  Each exported
module now ships a `Strategy` subclass that implements the runtime ABC exposed
by the wheel (`propose_orders`, `on_fill`, and `risk_profile`).  These adapter
classes delegate all analytics to the original research functions so notebooks
continue to operate unchanged while production systems receive a stable,
object-oriented interface.

The wheel also includes `polymarket_alpha.strategies.runtime`, a helper module
that mirrors the runtime base class when the wheel is not installed.  This
keeps the research environment self-contained while making the mapping between
research modules and deployable strategies explicit.

### Debt Simulation
Runs heuristic strategies to generate ranked payoff plans for outstanding debts. The simulator currently includes:

- **Avalanche** – Puts every extra dollar toward the debt with the highest APR. When no balances remain, the selector returns `null`, signalling the scheduler to stop allocating overpayments.
- **Snowball** – Targets the smallest balance first to create early wins. It also yields `null` once every tracked debt is paid off so the cycle exits cleanly.
- **Balanced** – Uses a smooth weighted round-robin algorithm to spread surplus payments proportionally across outstanding balances. If minimum payments exhaust the monthly budget or all debts reach zero, no additional allocations are made.
- **ML** – Invokes an ONNX Runtime model to choose the next debt based on learned patterns. Whenever the model file is missing, fails to load, or scores stay below the configured threshold, it falls back to the avalanche heuristic.

See the [Debt Simulation](debt-simulation.md) documentation—especially the [heuristics overview](debt-simulation.md#heuristics)—for request/response schemas and ranking details. The response schema always includes `cost_of_deviation`; optimal plans simply surface zero currency and time penalties so SDK adapters do not need to branch on `is_optimal`.

### Webhooks & Event Bus
`FinanceEventService` publishes finance events over Redis channels prefixed with `ume.events.finance.` for downstream consumers.

## Setup: Open Policy Agent (OPA)

1. Create a policy file, for example `policy.rego`:

   ```rego
   package finance.authz
   default allow = false
   allow {
     input.method == "POST"
   }
   ```

2. Run OPA in server mode:

   ```bash
   opa run --server --set=decision_logs.console=true policy.rego
   ```

3. Ensure the service is reachable at `http://localhost:8181/v1/data/finance/authz`, which is the default URL used by the middleware.

## Setup: Event Bus

1. Start a Redis instance (e.g. `docker run -p 6379:6379 redis:latest`).
2. Configure the application with the correct Redis connection settings (`REDIS_HOST`, `REDIS_PORT`, etc.).
3. Subscribers can listen on channels matching `ume.events.finance.*` to receive published events.
