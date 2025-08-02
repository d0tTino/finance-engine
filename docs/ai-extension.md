# AI Extension

Firefly III includes experimental AI-powered features that extend the core application.

## Endpoints

- `POST /api/v1/plaid-hook` – Receives webhooks from Plaid and stores transaction groups after verifying the request signature.
- `GET /api/v1/goals/{goal}/projection` – Generates a Monte Carlo projection for a goal using parameters like `initial`, `mean`, `stdev`, and `years`.
- `POST /api/v1/signals` – Accepts trading signals and forwards them to the configured broker.

## Modules

### Plaid Webhook
Handles incoming Plaid webhook payloads, validates the HMAC header, and stores transaction groups for the authenticated user.

### Goal Projection
Uses a Monte Carlo simulation service to estimate goal growth and returns a JSON time series with median, upper, and lower bounds.

### Strategy Signals
Validates trading signals (`asset`, `action`, `confidence`) and relays them via the broker SDK to external trading platforms.

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
