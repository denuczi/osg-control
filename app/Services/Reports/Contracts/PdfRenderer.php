<?php

namespace App\Services\Reports\Contracts;

interface PdfRenderer
{
    public function render(string $uuid, array $raw, ?string $conclusion): string;
}
