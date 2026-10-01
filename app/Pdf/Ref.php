<?php

namespace App\Pdf;

/** An indirect reference: "12 0 R". */
final class Ref
{
    public function __construct(public readonly int $num, public readonly int $gen = 0) {}

    public function key(): string
    {
        return $this->num . ' ' . $this->gen;
    }
}
