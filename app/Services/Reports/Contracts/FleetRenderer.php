<?php

namespace App\Services\Reports\Contracts;

interface FleetRenderer
{
    public function render(string $uuid, array $inventory, string $executionId): string;
}
