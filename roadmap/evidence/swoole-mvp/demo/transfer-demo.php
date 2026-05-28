<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use OpenSwoole\Coroutine as Co;
use OpenSwoole\Runtime;
use Phalanx\Swoole\Mvp\Application;
use Phalanx\Swoole\Mvp\Profile\Composes;
use Phalanx\Swoole\Mvp\Profile\Writes;
use Phalanx\Swoole\Mvp\Scope\CompositionScope;
use Phalanx\Swoole\Mvp\Scope\WriteScope;

Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

final class AccountStore
{
    /** @var array<int, int> */
    private array $balances = [];

    public function get(int $id): int
    {
        return $this->balances[$id] ?? 0;
    }

    public function set(int $id, int $balance): void
    {
        $this->balances[$id] = $balance;
    }

    public function snapshot(): array
    {
        ksort($this->balances);
        return $this->balances;
    }
}

final class ProductStore
{
    /** @var array<int, int> */
    private array $stock = [];

    public function adjust(int $id, int $delta): int
    {
        $this->stock[$id] = ($this->stock[$id] ?? 0) + $delta;
        return $this->stock[$id];
    }

    public function snapshot(): array
    {
        ksort($this->stock);
        return $this->stock;
    }
}

final class TransferFunds implements Writes
{
    public function __construct(
        public int $fromId,
        public int $toId,
        public int $amount,
        public string $label,
    ) {}

    public static function writes(): array
    {
        return [AccountStore::class => ['fromId', 'toId']];
    }

    public function __invoke(WriteScope $scope): array
    {
        $store = $scope->use(AccountStore::class);
        $started = microtime(true);

        $from = $store->get($this->fromId);
        $to = $store->get($this->toId);
        Co::usleep(80_000);
        $store->set($this->fromId, $from - $this->amount);
        $store->set($this->toId, $to + $this->amount);

        $ended = microtime(true);
        return [
            'label' => $this->label,
            'from' => $this->fromId,
            'to' => $this->toId,
            'started' => $started,
            'ended' => $ended,
        ];
    }
}

final class AdjustStock implements Writes
{
    public function __construct(
        public int $productId,
        public int $delta,
        public string $label,
    ) {}

    public static function writes(): array
    {
        return [ProductStore::class => ['productId']];
    }

    public function __invoke(WriteScope $scope): array
    {
        $store = $scope->use(ProductStore::class);
        $started = microtime(true);
        Co::usleep(80_000);
        $store->adjust($this->productId, $this->delta);
        $ended = microtime(true);
        return [
            'label' => $this->label,
            'product' => $this->productId,
            'started' => $started,
            'ended' => $ended,
        ];
    }
}

final class RunDemo implements Composes
{
    public function __invoke(CompositionScope $scope): array
    {
        return $scope->parallel([
            'transferA' => new TransferFunds(1, 2, 100, 'transferA(1->2)'),
            'transferB' => new TransferFunds(2, 3, 50, 'transferB(2->3)'),
            'transferC' => new TransferFunds(4, 5, 25, 'transferC(4->5)'),
            'stockX' => new AdjustStock(99, -1, 'stockX(99)'),
        ]);
    }
}

Co::run(static function (): void {
    $app = new Application();

    $app->services()
        ->singleton(AccountStore::class)
        ->factory(static fn() => new AccountStore())
        ->capacity(10)
        ->suspending()
        ->transactionSafe();

    $app->services()
        ->singleton(ProductStore::class)
        ->factory(static fn() => new ProductStore())
        ->capacity(10)
        ->suspending();

    $app->registerTasks(TransferFunds::class, AdjustStock::class, RunDemo::class)
        ->compile()
        ->boot();

    $t0 = microtime(true);
    $results = $app->dispatcher()->dispatch(new RunDemo());
    $elapsed = microtime(true) - $t0;

    fwrite(STDOUT, sprintf("== demo elapsed %.3fs ==\n", $elapsed));
    foreach ($results as $key => $r) {
        fwrite(STDOUT, sprintf(
            "  %-10s start=%.3f end=%.3f dur=%.3f label=%s\n",
            $key,
            $r['started'] - $t0,
            $r['ended'] - $t0,
            $r['ended'] - $r['started'],
            $r['label'],
        ));
    }
});
