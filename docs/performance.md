# Reporting-query performance

The reporting benchmark uses the fixture generator and query harness in `scripts/`:

```bash
npm run env:start
npm run bench:seed
npm run bench:run
```

## Author statistics decision

The per-author aggregate is rendered only on the Pangram results screen in wp-admin. Its latency budget is **p95 <= 150 ms** with 50,000 result rows. This leaves the full admin request room for WordPress bootstrap, the result list, and rendering while keeping this individual reporting query responsive.

Measurement on 2026-09-17:

| Dataset | Environment | Runs | Median | p95 | Maximum | Budget |
| --- | --- | ---: | ---: | ---: | ---: | ---: |
| 50,000 result rows; 47,500 successful; 25 authors | WordPress 7.1, PHP 8.3.33, MariaDB 12.3.3 in Docker | 20 after one warm-up | 79.6 ms | 90.0 ms | 103.3 ms | p95 <= 150 ms |

The query passes the budget with 60.0 ms of p95 headroom. Author-statistics caching was therefore removed: its version option used a read/increment/write invalidation that could lose concurrent increments, and it coupled result writes to reporting-cache state. Running the measured aggregate directly is simpler and always reflects current results.

The benchmark prints the dataset size and runtime versions alongside the percentiles so later runs can be compared under the same conditions.
