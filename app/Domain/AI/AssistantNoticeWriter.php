<?php

namespace App\Domain\AI;

use App\Domain\AI\Contracts\ChatModel;
use JsonException;
use Illuminate\Support\Facades\Log;

/**
 * AI-generated wording for notices. No fleet identifiers, actions, or device data
 * are sent to the model. This class cannot create or confirm commands.
 */
final class AssistantNoticeWriter
{
    public const TOOL_NAME = 'draft_windows_notice';

    public function __construct(private readonly ChatModel $model) {}

    /**
     * @return string|null A validated notice, or null when the model cannot draft
     *                     a grounded message. A provider exception propagates.
     */
    public function draft(string $brief): ?string
    {
        $messages = [
            ['role' => 'system', 'content' => <<<'PROMPT'
Eres el redactor de avisos administrativos de OSG Nodo Control. Tu única tarea es redactar EN ESPAÑOL LATINO NEUTRO un mensaje breve, claro y cordial que aparecerá en la pantalla de equipos Windows.

Usa únicamente el motivo y los hechos declarados por el administrador. Si habla de un apagado, mantenimiento o reinicio, advierte que guarden su trabajo sin inventar horas, fechas, urgencias, causas, confirmaciones de ejecución ni promesas adicionales. Puedes mejorar la redacción y el tono; no copies una instrucción meta como «redáctalo tú» al aviso. No incluyas órdenes técnicas para ejecutar, scripts, links ni Markdown. No afirmes que Windows ya fue apagado o que exista una programación verificada.

Si no hay un motivo reconocible o es imposible saber qué avisar, responde known=false, message=null. No inventes un motivo. Si existe, devuelve known=true y el texto del aviso, con saludo y una despedida breve. Máximo 500 caracteres.

Debes responder mediante la función draft_windows_notice. No eres responsable de seleccionar equipos ni de autorizar envíos: eso se comprueba exclusivamente en el servidor y requiere confirmación del administrador.
PROMPT],
            ['role' => 'user', 'content' => 'Redacta un aviso para los destinatarios que el servidor validará. Solicitud del administrador: '.mb_substr($brief, 0, 1100)],
        ];
        $result = $this->model->complete($messages, [$this->definition()]);
        $arguments = null;
        $calls = $result['tool_calls'] ?? [];
        if (is_array($calls) && count($calls) === 1 &&
            ($calls[0]['type'] ?? null) === 'function' &&
            ($calls[0]['function']['name'] ?? null) === self::TOOL_NAME &&
            is_string($calls[0]['function']['arguments'] ?? null) &&
            strlen($calls[0]['function']['arguments']) <= 2400) {
            $arguments = $calls[0]['function']['arguments'];
        } elseif (($calls === [] || $calls === null) && is_string($result['content'] ?? null)) {
            // Some local OpenAI-compatible backends do not implement tool_choice.
            // Accept only a strict JSON object with the same schema, not prose.
            $arguments = trim($result['content']);
        }
        if (! is_string($arguments) || strlen($arguments) > 2400) return null;
        try {
            $data = json_decode($arguments, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            Log::notice('ai_notice_writer_invalid_json');
            return null;
        }
        if (! is_array($data) || array_is_list($data) ||
            array_diff(array_keys($data), ['known', 'message']) ||
            ! array_key_exists('known', $data) || ! is_bool($data['known']) ||
            ! array_key_exists('message', $data)) return null;
        if ($data['known'] === false) return null;
        $message = $data['message'];
        if (! is_string($message)) return null;
        $message = trim($message);
        if ($message === '' || mb_strlen($message) > 500 ||
            preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $message) ||
            preg_match('~(?:https?://|www\.|```|<script|<iframe)~iu', $message)) return null;

        // Do not allow the model to introduce a concrete time/date that the
        // administrator never supplied. Avoid fabricating a shutdown schedule.
        $hasConcreteTime = static fn (string $value): bool => preg_match(
            '/\b(?:\d{1,2}[:.]\d{2}|\d+\s*(?:minutos?|mins?|horas?|hs?|dias?|días?|semanas?)|(?:lunes|martes|miercoles|miércoles|jueves|viernes|sabado|sábado|domingo)\b)\b/iu',
            $value,
        ) === 1;
        if ($hasConcreteTime($message) && ! $hasConcreteTime($brief)) return null;
        return $message;
    }

    private function definition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => self::TOOL_NAME,
                'description' => 'Produce texto cordial para un aviso sin ejecutar acciones.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'known' => ['type' => 'boolean'],
                        'message' => ['type' => ['string', 'null'], 'maxLength' => 500],
                    ],
                    'required' => ['known', 'message'],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }
}
