<?php

declare(strict_types=1);

namespace Phalanx\Stream;

use RuntimeException;

final class ResourceHandle
{
    /** @param resource $resource */
    private function __construct(
        private mixed $resource,
        private readonly string $label,
    ) {
    }

    public static function captureBuffer(): self
    {
        return self::open('php://temp', 'w+', 'capture buffer');
    }

    public static function memoryBuffer(string $contents = ''): self
    {
        $handle = self::open('php://memory', 'w+', 'memory buffer');

        if ($contents !== '') {
            $handle->write($contents);
            $handle->rewind();
        }

        return $handle;
    }

    public static function memoryInput(string $contents = ''): self
    {
        return self::memoryBuffer($contents);
    }

    public static function nullInput(): self
    {
        return self::open('/dev/null', 'r', 'null input');
    }

    public function __destruct()
    {
        $this->close();
    }

    /** @return resource */
    public function resource(): mixed
    {
        if (!is_resource($this->resource)) {
            throw new RuntimeException("The {$this->label} resource is closed.");
        }

        return $this->resource;
    }

    public function write(string $contents): void
    {
        $result = fwrite($this->resource(), $contents);

        if ($result === false || $result < strlen($contents)) {
            throw new RuntimeException("Unable to write to {$this->label}.");
        }
    }

    public function rewind(): void
    {
        rewind($this->resource());
    }

    public function drain(): string
    {
        $this->rewind();

        return (string) stream_get_contents($this->resource());
    }

    public function close(): void
    {
        if (is_resource($this->resource)) {
            fclose($this->resource);
        }
    }

    private static function open(string $target, string $mode, string $label): self
    {
        $resource = fopen($target, $mode);

        if ($resource === false) {
            throw new RuntimeException("Unable to open {$label}.");
        }

        return new self($resource, $label);
    }
}
