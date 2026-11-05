<?php

namespace App\Support;

use App\Models\DeviceCommand;

final class CommandViewData
{
    public static function from(DeviceCommand $command): array
    {
        return [
            'uuid' => $command->uuid,
            'action' => $command->action,
            'status' => $command->status,
            'device' => $command->device?->name,
            'created_at' => $command->created_at?->toIso8601String(),
            'result_message' => $command->result_message,
            'message_text' => $command->action === 'show_message' ? ($command->parameters['message'] ?? null) : null,
        ];
    }
}
