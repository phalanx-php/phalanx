# Phalanx Benchmarks

Benchmarks start with Aegis kernel costs, then roll outward as more of the framework surface becomes real.

## Current Harness

Run the Aegis kernel sweep:

```bash
composer bench:aegis
```

Run one case:

```bash
composer bench:aegis -- --case=scope_create_dispose
```

Emit JSON for local baselines:

```bash
composer bench:aegis -- --format=json > benchmarks/results/aegis-kernel.json
```

`benchmarks/results/*.json` and `benchmarks/results/*.csv` are ignored because benchmark output is machine-specific.

## Current Aegis Signal

The first Aegis benchmarks are regression guards for coroutine orchestration overhead:

- scope creation/disposal
- task execution
- supervisor lifecycle
- concurrent task fanout
- singleflight waiter fanout
- cancellation fanout
- in-process and Swoole table ledgers
- transaction scope entry/exit

These are kernel microbenchmarks. They should not be compared directly to HTTP request/second benchmarks.

## Hyperf And Swoole Reference

Hyperf is a useful ecosystem reference, but there is no clean current public Hyperf result that maps directly to the Aegis kernel harness.

Known reference points:

- Hyperf README reports `103,921.49` requests/sec on Aliyun 8 cores / 16GB using `wrk -c 1024 -t 8 http://127.0.0.1:9501/`.
- TechEmpower has a Hyperf benchmark entry, but its current FrameworkBenchmarks config marks the Hyperf variants with `tags: ["broken"]`.
- TechEmpower Round 23 remains useful for Swoole/OpenSwoole ecosystem ceilings, but its physical benchmark hardware is much larger than local development hardware: Xeon Gold 6330, 56 cores, 64GB RAM, 40GbE.

Approximate TechEmpower Round 23 PHP physical peak reference points:

| Framework | JSON peak RPS | Plaintext peak RPS |
| --- | ---: | ---: |
| Swoole | 2.46M | 3.52M |
| OpenSwoole | 2.05M | 2.34M |
| webman | 2.62M | 3.15M |
| symfony-swoole | 313k | 333k |
| laravel-swoole | 82k | 90k |

Use these as target shape only. They include HTTP parsing, routing, serialization, network effects, and benchmark-specific tuning. The current Aegis suite measures only internal coordination costs.

## Rollout Order

1. Keep Aegis kernel benchmarks as local regression guards.
2. Add baseline comparison once the kernel cases settle: save a JSON baseline, compare later runs, warn on large percentage regressions.
3. Add Phalanx HTTP benchmarks when the request path exists: `/plaintext` and `/json` first.
4. Add middleware, DI, and request-scope benchmarks once those APIs stabilize.
5. Add database benchmarks later: single query, multiple query, update, and transaction-wrapped variants.
6. Add realistic application paths last.

## References

- Hyperf repository and README benchmark: https://github.com/hyperf/hyperf
- TechEmpower FrameworkBenchmarks Hyperf config: https://raw.githubusercontent.com/TechEmpower/FrameworkBenchmarks/master/frameworks/PHP/hyperf/benchmark_config.json
- TechEmpower Round 23 hardware note: https://www.techempower.com/blog/tag/benchmarks/
- TechEmpower Round 23 physical PHP results data: https://www.techempower.com/benchmarks/results/round23/ph.json
- wrk: https://github.com/wg/wrk
