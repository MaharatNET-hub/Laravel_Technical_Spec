<?php

namespace App\Pdf;

/** Reference to an object created by the Writer (already numbered in the output file). */
final class NewRef
{
    public function __construct(public readonly int $num) {}
}
