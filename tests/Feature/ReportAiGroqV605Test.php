<?php

namespace Tests\Feature;

use App\Services\Reports\ReportConclusionWriter;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

final class ReportAiGroqV605Test extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('reports.ai.provider', 'groq');
        config()->set('reports.ai.model', 'openai/gpt-oss-120b');
        config()->set('reports.ai.groq_key', 'fake-test-token');
    }

    public function test_groq_receives_nonstreaming_strict_json_request_and_returns_conclusion(): void
    {
        Http::fake(['api.groq.com/*' => Http::response([
            'choices' => [['finish_reason' => 'stop', 'message' => [
                'role' => 'assistant',
                'content' => json_encode([
                    'conclusion' => 'El equipo presenta memoria insuficiente para cargas de trabajo elevadas.',
                    'recomendaciones' => 'Evaluar una ampliación de RAM tras comprobar la compatibilidad del equipo.',
                ], JSON_UNESCAPED_UNICODE),
            ]]],
        ], 200)]);

        $text = app(ReportConclusionWriter::class)->generate([
            'device' => ['cpu_percent' => 80, 'memory_percent' => 90],
            'metrics' => ['samples' => 60],
        ]);
        $this->assertStringContainsString('El equipo presenta', $text);
        $this->assertStringContainsString("\n\n", $text);
        Http::assertSent(fn ($request) =>
            $request->url() === 'https://api.groq.com/openai/v1/chat/completions' &&
            $request['model'] === 'openai/gpt-oss-120b' &&
            $request['stream'] === false &&
            $request['max_completion_tokens'] >= 3000 &&
            $request['reasoning_effort'] === 'low' &&
            $request['include_reasoning'] === false &&
            $request['response_format']['type'] === 'json_schema' &&
            $request['response_format']['json_schema']['strict'] === true
        );
    }

    public function test_groq_length_finish_reason_is_reported_not_silently_discarded(): void
    {
        Http::fake(['api.groq.com/*' => Http::response([
            'choices' => [['finish_reason' => 'length', 'message' => ['content' => '']]],
        ], 200)]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ai_output_truncated');
        app(ReportConclusionWriter::class)->generate(['metrics' => ['samples' => 1]]);
    }

    public function test_groq_empty_content_is_not_treated_as_a_valid_completion(): void
    {
        Http::fake(['api.groq.com/*' => Http::response([
            'choices' => [['finish_reason' => 'stop', 'message' => ['content' => '']]],
        ], 200)]);
        $this->expectExceptionMessage('ai_empty_content');
        app(ReportConclusionWriter::class)->generate(['metrics' => ['samples' => 1]]);
    }
}
