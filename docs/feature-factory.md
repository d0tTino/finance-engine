# Feature Factory

The feature factory produces per-market feature parquet files. Coverage checks
ensure that fewer than 5% of markets have missing values for any feature.

## Remediation

If the coverage report shows more than 5% of markets missing a feature:

1. Re-run the feature generation pipeline for the affected markets.
2. Inspect upstream data sources for gaps or outages.
3. Remove or manually backfill markets with persistent data issues.
