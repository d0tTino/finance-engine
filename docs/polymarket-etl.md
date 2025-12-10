# Polymarket ETL operations

This repository includes a scheduled job that runs the Polymarket ETL daily so the partition coverage window remains healthy.

## Schedule and workflow

- Workflow: `.github/workflows/polymarket-etl.yml`
- Schedule: daily at 06:00 UTC via cron plus manual `workflow_dispatch` for reruns.
- Failure criteria: the ETL raises `PartitionCoverageError` when `assert_partitions` detects coverage below the configured window and the workflow fails.

## Configuration

Environment variables drive where data and logs are stored and how long to keep them:

- `POLYMARKET_OUTPUT_DIR`: destination for partitioned Parquet files. Defaults to `data/polymarket` in the repository.
- `POLYMARKET_LOG_DIR`: folder for run logs. Defaults to `<output_dir>/logs`.
- `POLYMARKET_LOG_RETENTION_DAYS`: how many days of log files to keep (set to `0` to disable pruning).
- `POLYMARKET_COVERAGE_DAYS`: trailing days window passed to `assert_partitions`.
- `POLYMARKET_MIN_COVERAGE`: minimum acceptable fraction of days present in the window.
- `POLYMARKET_ARTIFACT_RETENTION_DAYS`: how many days to keep uploaded artifacts on GitHub.

These values can be adjusted per-run through workflow inputs or environment overrides.

## Rerun procedures

1. Navigate to **Actions → Polymarket ETL** in GitHub and select **Run workflow** to trigger a manual run with custom configuration values if needed.
2. Inspect the uploaded artifacts (`polymarket-etl-output`) for the Parquet partitions and ETL logs. Logs are also stored under `POLYMARKET_LOG_DIR` in the runner and pruned according to `POLYMARKET_LOG_RETENTION_DAYS`.
3. If a run fails because of insufficient partition coverage, rerun with a longer `POLYMARKET_COVERAGE_DAYS` window and verify missing dates are backfilled, or reprocess locally using:
   ```bash
   python -m fe.data.polymarket.etl --output-dir <path> --days <window> --min-coverage <threshold> \
     --log-dir <log_path> --log-retention-days <days>
   ```
   After a successful local run, upload the refreshed partitions and re-trigger the workflow to confirm coverage.

## Outputs and artifacts

Each workflow run uploads the Parquet output directory and ETL log directory as artifacts with configurable retention. Logs are timestamped per run to help correlate coverage errors with the underlying API responses.
