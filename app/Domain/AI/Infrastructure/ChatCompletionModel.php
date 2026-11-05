<?php

namespace App\Domain\AI\Infrastructure;

use App\Domain\AI\Contracts\ChatModel;
use App\Domain\AI\AssistantRequestInterpreter;
use App\Domain\AI\AssistantNoticeWriter;
use App\Domain\AI\AssistantRuntime;
use App\Services\Reports\ReportSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Adaptador para el endpoint Chat Completions de proveedores configurados. */
final class ChatCompletionModel implements ChatModel
{
    public function __construct(private readonly ReportSettings $settings, private readonly AssistantRuntime $runtime) {}

    public function complete(array $messages, array $tools): array
    {
        if (! $this->settings->configured()) throw new RuntimeException('assistant_not_configured');
        $timeout = $this->runtime->reserveCall();
        $ai = config('reports.ai');
        $provider = $this->settings->provider();
        [$url, $key] = match ($provider) {
            'openai' => ['https://api.openai.com/v1/chat/completions', $ai['openai_key']],
            'groq' => ['https://api.groq.com/openai/v1/chat/completions', $ai['groq_key']],
            'compatible' => [rtrim((string) $ai['compatible_url'], '/').'/chat/completions', $ai['openai_compatible_key']],
            'local' => [rtrim((string) $ai['local_url'], '/').'/chat/completions', null],
            default => throw new RuntimeException('assistant_provider_invalid'),
        };
        $body = [
            'model' => $ai['model'], 'messages' => $messages, 'stream' => false,
            // Local OpenAI-compatible servers often accept max_tokens, while
            // hosted providers accept max_completion_tokens.
            $provider === 'local' ? 'max_tokens' : 'max_completion_tokens' => $this->runtime->settings()['max_output_tokens'],
        ];
        if ($this->settings->provider() === 'groq' && in_array((string) $ai['model'], ['openai/gpt-oss-20b', 'openai/gpt-oss-120b'], true)) {
            $body['reasoning_effort'] = 'low';
            $body['include_reasoning'] = false;
        }
        if ($tools !== []) {
            $body['tools'] = $tools;
            $body['tool_choice'] = count($tools) === 1 &&
                in_array(($tools[0]['function']['name'] ?? null), [AssistantRequestInterpreter::TOOL_NAME, AssistantNoticeWriter::TOOL_NAME], true)
                    ? ['type' => 'function', 'function' => ['name' => $tools[0]['function']['name']]]
                    : 'auto';
            $body['parallel_tool_calls'] = false;
            if ($provider === 'local') {
                // Ollama exposes tools but does not advertise tool_choice.
                unset($body['tool_choice'], $body['parallel_tool_calls']);
            }
        }
        try {
            $http = Http::connectTimeout(min(5.0, $timeout))->timeout($timeout)
                ->acceptJson()->withoutRedirecting();
            if ($provider !== 'local') $http = $http->withToken((string) $key);
            $response = $http->post($url, $body);
        } catch (ConnectionException $e) {
            throw new RuntimeException('assistant_connection_failed', previous: $e);
        }
        if (! $response->successful()) {
            throw new RuntimeException('assistant_provider_http_'.$response->status());
        }
        $message = $response->json('choices.0.message');
        $finish = $response->json('choices.0.finish_reason');
        if (! is_array($message) || $finish === 'length' || ! in_array($finish, ['stop', 'tool_calls', null], true)) {
            throw new RuntimeException('assistant_invalid_completion');
        }
        // Evitar procesar respuestas inusualmente grandes o tool calls malformadas.
        if (strlen(json_encode($message, JSON_INVALID_UTF8_SUBSTITUTE)) > 18000) {
            throw new RuntimeException('assistant_response_too_large');
        }
        $this->runtime->recordUsage($response->json('usage'));
        return $message;
    }
}
