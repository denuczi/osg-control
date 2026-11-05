<?php

namespace App\Domain\AI\Contracts;

interface ChatModel
{
    /** @param array<array<string,mixed>> $messages @param array<array<string,mixed>> $tools */
    public function complete(array $messages, array $tools): array;
}
