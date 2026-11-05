<?php

namespace Tests\Feature;

use App\Services\Reports\ReportConclusionText;
use App\Services\Reports\ReportConclusionWriter;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

final class ReportAiConclusionV606Test extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('reports.ai.provider', 'groq');
        config()->set('reports.ai.model', 'openai/gpt-oss-120b');
        config()->set('reports.ai.groq_key', 'fake-test-token');
    }

    public function test_valid_but_long_groq_response_is_not_discarded(): void
    {
        $conclusion = str_repeat('La memoria se mantiene elevada y merece seguimiento. ', 25);
        $recommendations = str_repeat('Conviene evaluar una ampliación de memoria compatible con el equipo. ', 25);
        Http::fake(['api.groq.com/*' => Http::response([
            'choices' => [['finish_reason' => 'stop', 'message' => [
                'content' => json_encode([
                    'conclusion' => $conclusion,
                    'recomendaciones' => $recommendations,
                ], JSON_UNESCAPED_UNICODE),
            ]]],
        ], 200)]);

        $result = app(ReportConclusionWriter::class)->generate(['metrics' => ['samples' => 100]]);
        $paragraphs = explode("\n\n", $result);

        $this->assertCount(2, $paragraphs);
        $this->assertStringContainsString('memoria', $paragraphs[0]);
        $this->assertStringContainsString('ampliación', $paragraphs[1]);
        $this->assertLessThanOrEqual(850, mb_strlen($paragraphs[0]));
        $this->assertLessThanOrEqual(950, mb_strlen($paragraphs[1]));
        $this->assertStringEndsWith('.', $paragraphs[0]);
        $this->assertStringEndsWith('.', $paragraphs[1]);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) =>
            str_contains($request['messages'][0]['content'], '300–600 caracteres') &&
            str_contains($request['response_format']['json_schema']['schema']['properties']['conclusion']['description'], '300 a 600 caracteres')
        );
    }

    public function test_long_unpunctuated_text_has_bounded_safe_fallback(): void
    {
        $formatter = new ReportConclusionText();
        $text = $formatter->format([
            'conclusion' => str_repeat('rendimiento elevado ', 80),
            'recomendaciones' => str_repeat('verificar memoria y procesador ', 80),
        ]);
        [$conclusion, $recommendations] = explode("\n\n", $text);
        $this->assertLessThanOrEqual(850, mb_strlen($conclusion));
        $this->assertLessThanOrEqual(950, mb_strlen($recommendations));
        $this->assertStringEndsWith('…', $conclusion);
        $this->assertStringEndsWith('…', $recommendations);
    }

    public function test_formatter_removes_markup_and_preserves_ordinary_text(): void
    {
        $formatter = new ReportConclusionText();
        $result = $formatter->format([
            'conclusion' => '<b>La batería</b> presenta desgaste y menor autonomía.',
            'recomendaciones' => 'Comprobar la capacidad efectiva y valorar su sustitución.',
        ]);
        $this->assertStringContainsString('La batería presenta desgaste', $result);
        $this->assertStringNotContainsString('<b>', $result);
        $this->assertStringContainsString('4 GiB', $formatter->format([
            'conclusion' => 'El equipo dispone de menos de 4 GiB de memoria utilizable.',
            'recomendaciones' => 'Comprobar los módulos instalados antes de adquirir nueva memoria.',
        ]));
    }

    public function test_short_or_invalid_fields_remain_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ai_invalid_length');
        (new ReportConclusionText())->format([
            'conclusion' => 'No.',
            'recomendaciones' => 'Revisar el historial de los componentes del equipo.',
        ]);
    }

    public function test_rate_limit_is_different_from_text_length_validation(): void
    {
        Http::fake(['api.groq.com/*' => Http::response(['error' => ['message' => 'Rate limit']], 429)]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ai_http_429');
        app(ReportConclusionWriter::class)->generate(['metrics' => ['samples' => 1]]);
    }
}
