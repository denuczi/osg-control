<?php

namespace App\Domain\AI;

use App\Domain\AI\Tools\DeviceIdentityResolver;
use App\Domain\AI\Tools\FleetTools;
use App\Models\Device;
use App\Services\DeviceAvailability;
use Illuminate\Support\Str;

/**
 * Prepares user-requested messages without delegating recipient selection or
 * the authorization decision to an LLM. Never creates device commands.
 */
final class AssistantMessagePlanner
{
    public function __construct(
        private readonly FleetTools $tools,
        private readonly DeviceIdentityResolver $identityResolver,
        private readonly DeviceAvailability $availability,
        private readonly AssistantNoticeWriter $noticeWriter,
    ) {}

    /** @return array{answer:string, evidence:array, proposal:?array} */
    public function prepare(int $actor, string $question, ?string $deviceUuid = null): array
    {
        // Validate the scope before checking message content. Otherwise a vague
        // notice with an excluded subset receives a missing-text prompt instead
        // of being rejected as unsafe. An exclusion cannot become all_online.
        if ($this->hasExcludedFleetScope($question)) {
            return $this->excludedFleetAnswer();
        }

        $message = $this->message($question);
        if ($message === null && $this->supportsDirectFleetNotice($question)) {
            $message = $this->composeFleetNotice($question);
            if ($message === null && $this->hasDraftableFleetSubject($question)) {
                // A cheap preflight avoids paying for AI when no online
                // recipients exist or the fleet exceeds the proposal limit.
                // FleetTools revalidates atomically at proposal creation.
                if (($eligibilityError = $this->fleetEligibilityError()) !== null) {
                    return $this->answer($eligibilityError);
                }
                // The administrator delegated wording: actually use the
                // configured LLM instead of pretending a template is AI.
                $message = $this->noticeWriter->draft($question);
                if ($message === null) {
                    return $this->answer('La IA no pudo redactar un aviso verificable a partir de esa solicitud. Describe el motivo con más detalle o proporciona el texto. No se preparó ninguna orden.');
                }
            }
        }
        if ($message === null) {
            return $this->answer('¿Qué quieres comunicar? Indica el motivo o pídeme que redacte el aviso. Conservaré los destinatarios de esta conversación durante unos minutos. Ninguna orden fue enviada.');
        }

        // The content may mention another computer or "all connected". Only the
        // recipient clause may authorize fleet-wide scope.
        $instruction = $this->recipientClause($question);
        $normalized = $this->normalize($instruction);
        $allOnline = $this->hasExplicitFleetTarget($normalized);
        $singleOnline = preg_match('/\b(?:al|a la|para el|para la)\s+(?:unico\s+)?(?:equipo|computadora|compu|pc|notebook|netbook)\s+(?:(?:que\s+esta\s+)?(?:conectad[oa]|encendid[oa]|prendid[oa]|en linea|online))\b(?=\s+(?:enviale|envia|mandale|manda|avisale|avisa|un mensaje|diciendo)\b|\s*[\"“«]|$)/u', $normalized) === 1
            || preg_match('/\b(?:al|a la)\s+unic[oa]\s+(?:equipo|computadora|compu|pc)\s+(?:conectad[oa]|encendid[oa]|prendid[oa])\b/u', $normalized) === 1;

        if ($allOnline) {
            $result = $this->tools->execute('prepare_bulk_action', [
                'action' => 'show_message', 'all_online' => true, 'message' => $message,
            ], $actor);
            return $this->result($result, 'No preparé el envío a todos los equipos conectados. Puede que no haya destinatarios aptos o que se haya superado el límite de seguridad. Seleccioná equipos concretos desde «Equipos».');
        }

        if ($singleOnline) {
            $eligible = $this->onlineDevices()->orderBy('uuid')->limit(2)->get(['uuid', 'name']);
            if ($eligible->isEmpty()) {
                return $this->answer('No hay ningún equipo conectado y autorizado para recibir mensajes ahora. No se preparó ni programó ningún envío.');
            }
            if ($eligible->count() !== 1) {
                return $this->answer('Hay más de una computadora conectada. Como pediste enviar el mensaje a un solo equipo, indicá su nombre o hostname, o seleccionalo desde «Equipos». No preparé ninguna orden.');
            }
            return $this->result($this->tools->execute('prepare_bulk_action', [
                'action' => 'show_message', 'device_uuids' => [$eligible->first()->uuid], 'message' => $message,
            ], $actor));
        }

        $reference = $this->reference($instruction);
        if ($reference === null && $deviceUuid !== null) {
            // The user is in a device-specific assistant, and did not name a different target.
            $reference = $deviceUuid;
        }
        if ($reference === null) {
            return $this->answer('¿A qué computadora querés enviar el mensaje? Indicá su nombre o hostname, o pedí explícitamente «a todos los equipos conectados». Ninguna orden fue enviada.');
        }

        $resolution = $this->identityResolver->matches($reference);
        if ($resolution['status'] === 'ambiguous') {
            return $this->answer('Encontré varias computadoras que coinciden con ese nombre. Indicá el hostname o UUID exacto para no enviar el mensaje al equipo equivocado. Ninguna orden fue enviada.');
        }
        if ($resolution['status'] !== 'found') {
            return $this->answer('No pude identificar de manera inequívoca una computadora registrada con ese nombre. Revisá su nombre o hostname en «Equipos». Ninguna orden fue enviada.');
        }
        $uuid = $resolution['matches'][0]['uuid'];
        return $this->result($this->tools->execute('prepare_bulk_action', [
            'action' => 'show_message', 'device_uuids' => [$uuid], 'message' => $message,
        ], $actor), 'El equipo solicitado no está conectado o no está autorizado para recibir órdenes ahora. No se preparó ni programó ningún mensaje.');
    }

    /**
     * Only clear, explicitly connected *plural* recipient groups may be handled
     * without an LLM. Other colloquial requests still go through semantic routing.
     */
    public function supportsDirectFleetNotice(string $question): bool
    {
        return $this->hasExplicitFleetTarget($this->normalize($this->recipientClause($question)));
    }

    /**
     * Check that the request supplies a subject and explicitly delegates the
     * wording. Do not invoke a model for a bare "send a message" request.
     */
    public function hasDraftableFleetSubject(string $question): bool
    {
        $content = preg_replace('/["“«][^"”»]*["”»]/u', ' ', $question) ?? $question;
        return preg_match(
            '/\b(?:para\s+(?:avisar|informar|notificar|comunicar)(?:les|los|las)?(?:\s+de)?|'
            .'con\s+el\s+fin\s+de\s+(?:avisar|informar)|'
            .'para\s+que\s+(?:sepan|tengan\s+presente)|'
            .'sobre|respecto\s+a)\s+(?:que\s+)?\S.{2,}$/iu',
            trim($content),
        ) === 1;
    }

    public function needsFleetTopic(string $question): bool
    {
        return $this->supportsDirectFleetNotice($question)
            && ! $this->hasExcludedFleetScope($question)
            && $this->message($question) === null
            && $this->composeFleetNotice($question) === null
            && ! $this->hasDraftableFleetSubject($question);
    }

    /**
     * Only an explicit imperative can bypass semantic interpretation for a
     * quoted single-recipient notice. An indirect request such as "Necesito
     * comunicar ..." may contain an application name in the quoted body; the
     * model's proposed recipient must still pass the named-reference check.
     */
    public function supportsDirectQuotedMessage(string $question): bool
    {
        $recipient = $this->normalize($this->recipientClause($question));

        return preg_match(
            '/\b(?:envia|enviale|enviales|manda|mandale|mandales|avisa|avisale|avisales|'
            .'notifica|notific[aá]les|informa|informales|comunica|comunicales|dile|diles|di|'
            .'mostra|muestra|muestrales)\b/u',
            $recipient,
        ) === 1;
    }

    /** Reject implicit subset arithmetic rather than broadening its scope. */
    private function hasExcludedFleetScope(string $question): bool
    {
        $recipient = $this->normalize($this->recipientClause($question));

        return $this->hasExplicitFleetTarget($recipient)
            && preg_match(
                '/\b(?:excepto|menos|salvo|excepcion|excepciones|excluyendo|exclui|sin incluir|'
                .'del laboratorio|de la oficina|del aula|del sector|del departamento|'
                .'solo|solamente|unicamente)\b/u',
                $recipient,
            ) === 1;
    }

    /** @return array{answer:string, evidence:array, proposal:null} */
    private function excludedFleetAnswer(): array
    {
        return $this->answer('El alcance no identifica inequívocamente los destinatarios: la solicitud incluye exclusiones. '
            .'Para evitar un envío al conjunto equivocado, seleccioná los destinatarios concretos en «Equipos». '
            .'No se preparó ninguna orden.');
    }

    /** Recipient wording only: quoted text and the content clause grant no scope. */
    private function recipientClause(string $question): string
    {
        $instruction = preg_replace('/["“«][^"”»]*["”»]/u', ' ', $question) ?? $question;
        $parts = preg_split('/\b(?:diciendo|con\s+(?:el\s+)?texto|que\s+(?:el\s+)?mensaje\s+diga|que\s+diga)\b/iu', $instruction, 2);
        $recipient = $parts[0] ?? $instruction;
        // For "dile a las conectadas que ...", the content begins after the
        // online qualifier; this does not split "computadoras que están activas".
        $recipient = preg_replace('/\b(conectad[oa]s|activ[oa]s|encendid[oa]s|prendid[oa]s|online)\s+que\b.*$/isu', '$1', $recipient) ?? $recipient;
        return $recipient;
    }

    private function hasExplicitFleetTarget(string $recipient): bool
    {
        if (preg_match('/\b(?:todos?\s+los\s+(?:equipos|conectados|encendidos|prendidos|activos)|todas?\s+las\s+(?:computadoras|compus|pcs|conectadas|encendidas|prendidas|activas)|(?:a|para)\s+las\s+(?:conectadas|encendidas|prendidas|activas)|(?:a|para)\s+los\s+(?:conectados|encendidos|prendidos|activos)|a\s+todos|a\s+todas|cada\s+(?:equipo|computadora|pc)\s+(?:conectad[oa]|encendid[oa]|prendid[oa]|activ[oa]))\b/u', $recipient)) {
            return true;
        }
        // "a las computadoras activas" designates the complete online set;
        // "a una computadora activa" or "a las computadoras" does not.
        return preg_match('/\b(?:a|para)\s+(?:las|los|todas\s+las|todos\s+los)\s+(?:computadoras|computadores|equipos|pcs|compus|notebooks|netbooks)\s+(?:(?:que\s+(?:estan|esten)\s+)?)?(?:activas|activos|conectadas|conectados|encendidas|encendidos|prendidas|prendidos|online|en\s+linea)\b/u', $recipient) === 1;
    }

    /**
     * Conservative, offline-friendly composition of a *notification*, never
     * a command. Facts, timing and certainty come only from the user.
     */
    private function composeFleetNotice(string $question): ?string
    {
        $body = null;
        // A quoted message is handled by message(), preserving its exact text.
        if (preg_match('/\b(?:diciendo(?:\s+que)?|que\s+(?:el\s+)?mensaje\s+diga|que\s+diga|con\s+(?:el\s+)?texto)\s*[:=-]?\s*(.+)$/isu', $question, $match)) {
            $body = $match[1];
        } elseif (preg_match('/\b(?:conectad[oa]s|activ[oa]s|encendid[oa]s|prendid[oa]s|online)\s+que\s+(.+)$/isu', $question, $match)) {
            $body = $match[1];
        }
        if ($body === null) return null;
        $body = trim($body, " \t\n\r\0\x0B\"“”«»;.!¿? ");
        if ($body === '' || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $body)) return null;
        // Avoid promoting unknown statements into promises. No invented time.
        $body = preg_replace('/^que\s+/iu', '', $body) ?? $body;
        if (preg_match('/^se\s+(?:apagaran|apagarán|reiniciaran|reiniciarán|actualizaran|actualizarán)\b/iu', $body)) {
            $body = 'los equipos '.$body;
        }
        $body = mb_strtolower(mb_substr($body, 0, 1)).mb_substr($body, 1);
        $body = rtrim($body, '.');
        if ($body === '' || mb_strlen($body) > 325) return null;
        $message = 'Hola. Les informamos que '.$body.'.';
        if (preg_match('/\b(?:apag|reinici|mantenimiento|actualiz|interrup|inactiv)/iu', $body)) {
            $message .= ' Por favor, guarden sus trabajos para evitar la pérdida de información.';
        }
        $message .= ' Gracias por su comprensión.';
        return mb_strlen($message) <= 500 ? $message : null;
    }

    /**
     * The model supplies *meaning*, never device identifiers to execute blindly.
     * Recipient resolution, online checks and proposal creation stay server-side.
     *
     * @param array{intent:string,target:string,reference:?string,message:?string,tone:string} $interpretation
     */
    public function prepareInterpreted(int $actor, string $question, array $interpretation, ?string $deviceUuid = null): array
    {
        if ($this->hasExcludedFleetScope($question)) {
            return $this->excludedFleetAnswer();
        }

        $withoutQuotedContent = preg_replace('/["“«][^"”»]*["”»]/u', ' ', $question) ?? $question;
        $instruction = $this->normalize($withoutQuotedContent);
        // A negation or hypothetical question must not become a message
        // proposal merely because an LLM returns intent=message.
        if (preg_match('/^(?:no|nunca|jamas|que|cual|como|donde|cuando|por que|que pasaria|se puede|puedo)\b/u', $instruction)
            || preg_match('/\b(?:quiero|necesito|prefiero|pido)\s+(?:que\s+)?no\b/u', $instruction)) {
            return $this->answer('No preparé ningún mensaje: la solicitud contiene una negación o es una consulta, no una orden inequívoca. Si querés enviarlo, pedímelo explícitamente.');
        }
        $message = $interpretation['message'];
        if (! is_string($message) || trim($message) === '') {
            return $this->answer('¿Qué mensaje querés enviar? Podés escribirlo de forma natural. No preparé ninguna orden.');
        }
        $message = trim($message);
        if (mb_strlen($message) > 500 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $message)) {
            return $this->answer('El texto del mensaje no es válido o supera los 500 caracteres. No preparé ninguna orden.');
        }
        if ($interpretation['tone'] === 'cordial') {
            $message = $this->makeCourteous($message);
        }
        $target = $interpretation['target'];
        if ($target === 'all_online') {
            // Even a misclassified model output cannot silently expand a single
            // recipient to the entire fleet: the administrator must say 'all'.
            $withoutQuote = preg_replace('/["“«][^"”»]*["”»]/u', ' ', $question) ?? $question;
            $instruction = $this->normalize($withoutQuote);
            // A model may misunderstand phrases quoted inside the message;
            // broad or restricted fleet targeting requires explicit user scope.
            $explicitAll = preg_match('/\b(?:todos?\s+los\s+(?:equipos|conectados|encendidos|prendidos)|todas?\s+las\s+(?:computadoras|compus|pcs|conectadas|encendidas|prendidas)|(?:a|para)\s+las\s+(?:conectadas|encendidas|prendidas)|(?:a|para)\s+los\s+(?:conectados|encendidos|prendidos)|cada\s+(?:equipo|computadora|pc)\s+(?:conectad[oa]|encendid[oa]|prendid[oa]))\b/u', $instruction) === 1;
            $hasExclusions = preg_match('/\b(?:excepto|menos|salvo|excluyendo|sin incluir|solamente|solo|unicamente|del laboratorio|del aula|de la oficina|del sector|del departamento)\b/u', $instruction) === 1;
            if (! $explicitAll || $hasExclusions) {
                return $this->answer('No preparé el mensaje masivo porque el alcance no identifica inequívocamente a todos los conectados. Indicá los destinatarios concretos en «Equipos» o pedí expresamente «todos los equipos conectados».');
            }
            return $this->result($this->tools->execute('prepare_bulk_action', [
                'action' => 'show_message', 'all_online' => true, 'message' => $message,
            ], $actor));
        }
        if ($target === 'single_online') {
            $devices = $this->onlineDevices()->orderBy('uuid')->limit(2)->get(['uuid', 'name']);
            if ($devices->isEmpty()) {
                return $this->answer('No hay computadoras conectadas y autorizadas para recibir mensajes. No se programó ningún envío.');
            }
            if ($devices->count() !== 1) {
                return $this->answer('Hay varias computadoras conectadas. Indicá cuál debe recibir el mensaje; no voy a elegir una arbitrariamente.');
            }
            return $this->result($this->tools->execute('prepare_bulk_action', [
                'action' => 'show_message', 'device_uuids' => [$devices->first()->uuid], 'message' => $message,
            ], $actor));
        }
        if ($target === 'context' && $deviceUuid !== null) {
            return $this->result($this->tools->execute('prepare_bulk_action', [
                'action' => 'show_message', 'device_uuids' => [$deviceUuid], 'message' => $message,
            ], $actor));
        }
        if ($target === 'named' && is_string($interpretation['reference'])) {
            $reference = trim($interpretation['reference']);
            // The model cannot authorize a recipient merely because its name
            // occurs inside the message body. Keep only the recipient part of
            // the request and recognize quoted aliases *only* when explicitly
            // attached to a device/name expression (e.g. de nombre "doctor").
            $recipientClause = preg_split(
                '/\b(?:diciendo|que\s+(?:el\s+)?mensaje\s+diga|que\s+diga|con\s+(?:el\s+)?texto)\b/iu',
                $question, 2,
            )[0] ?? '';
            $unquoted = preg_replace('/["“«][^"”»]*["”»]/u', ' ', $recipientClause) ?? '';
            $source = $this->normalize($unquoted);
            $needle = $this->normalize($reference);
            $mentionedOutsideQuotes = $needle !== '' && preg_match(
                '/(?<![a-z0-9])'.preg_quote($needle, '/').'(?![a-z0-9])/u',
                $source,
            ) === 1;
            $explicitQuotedAlias = false;
            if ($needle !== '' && preg_match_all(
                '/\b(?:computadora|computador|equipo|pc|netbook|notebook|laptop)\s+(?:(?:de\s+)?nombre\s+|llamad[oa]\s+|que\s+se\s+llama\s+)?["“«]([^"”»]+)["”»]/iu',
                $recipientClause, $quotedRecipients,
            )) {
                foreach ($quotedRecipients[1] as $quotedAlias) {
                    if ($this->normalize($quotedAlias) === $needle) {
                        $explicitQuotedAlias = true;
                        break;
                    }
                }
            }
            if (! $mentionedOutsideQuotes && ! $explicitQuotedAlias) {
                return $this->answer('No pude verificar qué equipo nombraste. Indicá su nombre o hostname exacto; no preparé ninguna orden.');
            }
            $resolution = $this->identityResolver->matches($reference);
            if ($resolution['status'] === 'ambiguous') {
                return $this->answer('Hay varios equipos que coinciden con ese nombre. Indicá el hostname o UUID antes de preparar el mensaje.');
            }
            if ($resolution['status'] !== 'found') {
                return $this->answer('No encontré un equipo registrado con ese nombre o hostname. No se preparó ninguna orden.');
            }
            return $this->result($this->tools->execute('prepare_bulk_action', [
                'action' => 'show_message',
                'device_uuids' => [$resolution['matches'][0]['uuid']],
                'message' => $message,
            ], $actor));
        }
        return $this->answer('¿A qué computadora querés enviar el mensaje? Indicá su nombre o pedí expresamente que se envíe a todos los equipos conectados.');
    }

    private function makeCourteous(string $message): string
    {
        // Don't duplicate a greeting or thanks when the model has already
        // supplied a courteous draft. Keep the administrator's wording intact.
        if (preg_match('/^(hola|buen dia|buenos dias|estimad[oa]s?)[,!. ]/iu', $message)
            || preg_match('/\b(gracias|agradecemos|agradezco)\b/iu', $message)) return $message;
        $sentence = preg_match('/[.!?]$/u', $message) ? $message : $message.'.';
        $result = 'Hola. '.$sentence.' Gracias por tu comprensión.';
        return mb_strlen($result) <= 500 ? $result : $message;
    }

    /** Avoid expensive inference when the validated online fleet is unusable. */
    public function fleetEligibilityError(): ?string
    {
        $max = max(1, min(20, (int) config('assistant.max_devices_per_action', 20)));
        $count = $this->onlineDevices()->limit($max + 1)->get(['uuid'])->count();
        if ($count === 0) {
            return 'No hay computadoras conectadas y autorizadas para recibir el aviso. No se preparó ninguna orden.';
        }
        if ($count > $max) {
            return 'Hay más equipos conectados que el límite de '.$max.' por propuesta. Selecciona destinatarios concretos desde «Equipos»; no se eligió un subconjunto arbitrario.';
        }
        return null;
    }

    private function onlineDevices(): \Illuminate\Database\Eloquent\Builder
    {
        return $this->availability->online(
            Device::query()->whereNull('archived_at'), $this->availability->cutoff(),
        );
    }

    private function message(string $question): ?string
    {
        // Prefer an explicitly introduced message over other quoted identifiers.
        if (! preg_match('/\b(?:diciendo|que\s+diga|con\s+(?:el\s+)?texto)\s*[:=-]?\s*["“«]([^"”»]{1,500})["”»]/iu', $question, $match)) {
            preg_match_all('/["“«]([^"”»]{1,500})["”»]/u', $question, $quoted);
            if (count($quoted[1] ?? []) !== 1) return null;
            $match = [1 => $quoted[1][0]];
        }
        $message = trim($match[1]);
        if ($message === '' || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $message)) {
            return null;
        }
        $instruction = preg_replace('/["“«][^"”»]*["”»]/u', ' ', $question) ?? $question;
        if (preg_match('/\bcordial(?:mente)?\b/ui', $instruction)) {
            $message = $this->makeCourteous($message);
        }
        return mb_strlen($message) <= 500 ? $message : null;
    }

    private function reference(string $question): ?string
    {
        if (preg_match('/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/iu', $question, $match)) {
            return $match[0];
        }
        // Reconocer hostnames explícitos sin prefijos fijos. Los nombres del
        // mensaje entre comillas nunca llegan a este método (prepare los quita).
        if (preg_match('/\b(?:un\s+)?(?:mensaje|aviso|notificacion|texto)\s+(?:a|para)\s+(?!todos?\b|todas?\b)(?<host>[a-z0-9][a-z0-9._-]{1,119})(?=\s+(?:diciendo|con\s+(?:el\s+)?texto|que\s+(?:el\s+)?mensaje\s+diga|que\s+diga)\b|\s*$)/iu', $question, $match)) {
            return $match['host'];
        }
        if (preg_match('/\b(?:DESKTOP|LAPTOP|LAB-PC|ADMIN-PC|PC)-[A-Z0-9-]{1,}\b/i', $question, $match)) {
            return $match[0];
        }
        if (preg_match('/\b(?:al|a la|para el|para la)\s+(?:equipo|computadora|compu|pc|notebook|netbook)\s+(?:de\s+)?(?<name>[\pL\pN][\pL\pN ._-]{2,70}?)(?=\s+(?:enviale|envia|mandale|manda|avisale|avisa|un mensaje|diciendo|con el texto|que diga)\b|\s*["“«]|$)/iu', $question, $match)) {
            return trim($match['name']);
        }
        return null;
    }

    private function result(array $result, string $fallback = 'No pude preparar el mensaje. Verificá que el equipo esté conectado y autorizado. No se envió ninguna orden.'): array
    {
        $proposal = $result['proposal'] ?? null;
        if ($proposal === null) return $this->answer($fallback);
        $count = count($proposal['recipients'] ?? []);
        $target = $count === 1 ? '1 equipo' : "{$count} equipos";
        return [
            'answer' => "Preparé un mensaje para **{$target}**. Revisá el texto y los destinatarios en la tarjeta de confirmación. **Todavía no se envió ninguna orden**.",
            'evidence' => [],
            'proposal' => $proposal,
        ];
    }

    private function answer(string $text): array
    {
        return ['answer' => $text, 'evidence' => [], 'proposal' => null];
    }

    private function normalize(string $text): string
    {
        $text = strtolower(Str::ascii($text));
        return trim(preg_replace('/\s+/u', ' ', preg_replace('/[^a-z0-9\s-]+/u', ' ', $text) ?? '') ?? '');
    }
}
