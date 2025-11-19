# Base Rate Engine

The base rate engine estimates outside-view probabilities using historical resolution data. It fits monotonic isotonic regression models for each category and reports calibrated probabilities with Wilson score intervals. Historical rows are first mapped into the horizon buckets declared in `fe/base_rates/taxonomy.yaml`, ensuring that both prior estimation and Brier-score evaluations respect the taxonomy's time scopes.

## CLI

The estimator exposes utilities that can be invoked from the command line.

### Evaluate overall Brier score

```bash
python -m fe.base_rates.estimator evaluate-brier-score
```

Prints the global Brier score and the percentage improvement over a naive `0.5` prior. Optional arguments:

- `--test-size` – fraction of data used for validation (default `0.2`).
- `--random-state` – seed controlling the train/test split.

### Evaluate Brier score by taxonomy

```bash
python -m fe.base_rates.estimator evaluate-brier-by-taxonomy
```

Reports Brier scores and improvements for each taxonomy bucket defined in `taxonomy.yaml`. The same `--test-size` and `--random-state` options are available.
