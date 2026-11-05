<?php

namespace App\Services\Reports\Contracts;

interface ConclusionWriter
{
    public function generate(array $raw): string;
}
