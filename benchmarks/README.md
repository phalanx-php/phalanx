# Phalanx Benchmarks

Benchmarks start with the Aegis kernel, then rolling outward as more of the framework surface becomes real.

## Kernel benchmarks

```bash
composer bench:aegis
composer bench:aegis -- --case=scope_create_dispose
composer bench:aegis -- --format=json
composer bench:aegis -- --baseline=baseline.json
```

## HTTP dispatch benchmarks

```bash
composer bench:stoa
```

## HTTP server (wrk)

Standalone Stoa server for external load testing with wrk, siege, or ab.

```bash
composer bench:wrk
```

In another terminal:

```bash
wrk -t4 -c100 -d10s http://127.0.0.1:8080/json
wrk -t4 -c100 -d10s http://127.0.0.1:8080/plaintext
```
