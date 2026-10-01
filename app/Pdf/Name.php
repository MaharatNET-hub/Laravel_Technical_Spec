<?php

namespace App\Pdf;

/** A PDF name object, e.g. /Type (stored without the slash, #xx escapes decoded). */
final class Name
{
    public function __construct(public readonly string $v) {}

    public function __toString(): string
    {
        return $this->v;
    }
}
