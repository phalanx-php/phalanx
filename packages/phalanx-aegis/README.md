<p align="center">
  <img src="brand/logo.svg" alt="Phalanx" width="520">
</p>

# Phalanx Aegis

**Async PHP without the async.**

Three HTTP calls in parallel. Cancellation that actually fires. Memory that stays flat across millions of rows. Stack traces that name your work instead of `Closure@handler.php:47`.

Phalanx Aegis is built on OpenSwoole coroutines, channels, wait groups, timers, and client pools. It separates *what you want to happen* from *how it runs*. You write named computations. The scope handles coroutine scheduling, cancellation, and cleanup.

PHP 8.4 -- fibers, property hooks, asymmetric visibility, lazy proxies -- is the foundation, not an afterthought.

---

Phalanx is getting a facelift, and not an insignificant one. The fun is just getting started.
