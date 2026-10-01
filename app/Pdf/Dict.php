<?php

namespace App\Pdf;

/** A PDF dictionary. Keys are names without the slash. */
final class Dict
{
    /** @param array<string,mixed> $e */
    public function __construct(public array $e = []) {}

    public function get(string $k, mixed $default = null): mixed
    {
        return $this->e[$k] ?? $default;
    }

    public function has(string $k): bool
    {
        return array_key_exists($k, $this->e);
    }

    public function set(string $k, mixed $v): static
    {
        $this->e[$k] = $v;

        return $this;
    }

    public function remove(string ...$keys): static
    {
        foreach ($keys as $k) {
            unset($this->e[$k]);
        }

        return $this;
    }

    public function name(string $k): ?string
    {
        $v = $this->e[$k] ?? null;

        return $v instanceof Name ? $v->v : null;
    }

    public function copy(): self
    {
        return new self($this->e);
    }
}
