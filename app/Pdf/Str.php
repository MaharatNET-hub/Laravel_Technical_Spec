<?php

namespace App\Pdf;

/** A PDF string; $bytes holds the decoded bytes (escapes / hex already resolved). */
final class Str
{
    public function __construct(public readonly string $bytes) {}
}
