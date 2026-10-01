<?php

namespace App\Pdf;

/** A PDF stream: dictionary + the raw (still encoded) bytes. */
final class Stream
{
    public function __construct(public Dict $dict, public string $raw) {}
}
