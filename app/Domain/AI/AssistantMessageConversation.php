<?php

namespace App\Domain\AI;

use App\Domain\AI\Tools\DeviceIdentityResolver;
use App\Domain\AI\Tools\FleetTools;
use Illuminate\Support\Str;

/**
 * A bounded, server-owned draft for multi-turn show_message requests.
 * Conversation history supplied by the browser is never an authorization source.
 * This service can create an approval proposal, never a device command.
 */
final class AssistantMessageConversation
{
    private const SESSION_KEY = 'osg_assistant_message_drafts';
    private const TTL_SECONDS = 600;

    public function __construct(
        private readonly DeviceIdentityResolver $identities,
        private readonly FleetTools $tools,
        private readonly AssistantIntent $intent,
        private readonly AssistantMessagePlanner $planner,
        private readonly AssistantNoticeWriter $noticeWriter,
    ) {}

    /**
     * @return array{answer:string,evidence:array,proposal:?array}|null
     */
    public function respond(int $actor, string $question, ?string $conversationId, ?string $deviceUuid): ?array
    {
        if ($conversationId === null || ! Str::isUuid($conversationId) || ! request()->hasSession()) {
            return null;
        }

        $text = trim($question);
        $normalized = $this->normalize($text);
        $previous = $this->read($actor, $conversationId);

        if (preg_match('/^(?:cancela|cancelar|olvida|dejalo|(?:por favor\s+)?(?:no|nunca|jamas)\s+(?:le\s+)?(?:envies?|mandes?|avises?|hagas))\b/u', $normalized)) {
            if ($previous !== null) $this->forget($actor, $conversationId);
            return $this->answer('No se preparó ninguna orden. El borrador anterior, si existía, fue descartado.');
        }

        // A user may be replying to a prompt from an older application
        // version. Without a server-owned draft there is no safe recipient
        // authorization, even if the browser sends convincing chat history.
        if ($previous === null && ! $this->intent->requestsMessageAction($text)
            && preg_match('/\b(?:lo\s+debes\s+redactar\s+tu|redacta(?:lo)?\s+tu)\b/u', $normalized)) {
            return $this->answer('Puedo redactar el aviso con IA, pero necesito que indiques otra vez los destinatarios. Por ejemplo: «Envía a todas las computadoras conectadas un aviso sobre el apagado». Ninguna orden fue enviada.');
        }

        // An online-fleet draft is stored server-side, not reconstructed from
        // the browser's untrusted conversation history. A new explicit fleet
        // request always replaces an older named/fleet draft.
        if ($this->intent->requestsMessageAction($text) && $this->planner->supportsDirectFleetNotice($text)) {
            if ($previous !== null) $this->forget($actor, $conversationId);
            if ($this->planner->needsFleetTopic($text)) {
                $this->store($actor, $conversationId, [
                    'actor' => $actor, 'created_at' => now()->timestamp,
                    'scope' => 'all_online',
                ]);
                return $this->answer('¿Qué texto o motivo necesitas comunicar a las computadoras conectadas? Puedes pedirme que redacte un mensaje cordial. No se preparó ninguna orden.');
            }
            return null;
        }
        if (($previous['scope'] ?? null) === 'all_online') {
            // Only a clear follow-up can complete this stored draft; an
            // unrelated question cannot reuse its privileged recipient scope.
            if (! preg_match('/^(?:(?:que|diciendo\s+que|el\s+(?:mensaje|aviso|texto)\s+es|redacta|redactalo|escribe|pon|di\s+que)\b|["“«])/u', $normalized)) {
                $this->forget($actor, $conversationId);
                return null;
            }
            $draftText = preg_replace('/^(?:el\s+(?:mensaje|aviso|texto)\s+es|diciendo)\s*/iu', '', $text) ?? $text;
            if (! preg_match('/[\pL\pN]{3,}/u', $draftText) || preg_match('/^(?:redacta(?:lo)?|escribe(?:lo)?|hazlo)(?:\s+(?:tu|por\s+favor))?$/u', $this->normalize($draftText))) {
                return $this->answer('Indica qué necesitas comunicar. Ninguna orden fue enviada.');
            }
            if (($eligibilityError = $this->planner->fleetEligibilityError()) !== null) {
                $this->forget($actor, $conversationId);
                return $this->answer($eligibilityError);
            }
            // Follow-up "que se apagarán, lo debes redactar tú" delegates
            // wording to the real model; no heuristic negation on "no" in a
            // normal sentence can change the action intent.
            $message = $this->noticeWriter->draft($draftText);
            if ($message === null) {
                return $this->answer('La IA no pudo redactar un aviso fiable con esa información. Precisa qué debe comunicarse; ninguna orden fue enviada.');
            }
            $result = $this->tools->execute('prepare_bulk_action', [
                'action' => 'show_message', 'all_online' => true, 'message' => $message,
            ], $actor);
            $this->forget($actor, $conversationId);
            if (! is_array($result['proposal'] ?? null)) {
                return $this->answer('No pude preparar el aviso para todos los conectados: verifica que haya equipos disponibles y que no se supere el límite de destinatarios. No se envió ninguna orden.');
            }
            return [
                'answer' => 'La IA redactó el aviso para **'.count($result['proposal']['recipients']).' equipos conectados**. Revisa el texto y los destinatarios antes de confirmarlo. **Ninguna orden fue enviada todavía.**',
                'evidence' => [], 'proposal' => $result['proposal'],
            ];
        }

        // An explicit *new* request with a fleet/hostname recipient is not a
        // continuation of an older draft. Give it to the server-side planner
        // instead of replying "no pending message" or reusing stale recipients.
        // The specialized conversation flow below remains responsible for
        // named-device clarifications that require multiple turns.
        if ($this->intent->requestsMessageAction($text) && ! $this->isNewMessageRequest($text)) {
            if ($previous !== null) $this->forget($actor, $conversationId);
            return null;
        }

        // A stale or nonexistent draft must not be reconstructed by a model.
        if ($previous === null && $this->isMessageContinuation($normalized)) {
            return $this->answer('No hay un mensaje pendiente en esta conversación. Indica el destinatario y el texto del aviso para preparar una nueva propuesta. Ninguna orden fue enviada.');
        }

        $reference = $this->reference($text);
        $newRequest = $this->isNewMessageRequest($text);
        $continuation = $previous !== null && ($this->isMessageContinuation($normalized)
            || ($reference !== null && $this->isRecipientClarification($normalized)));

        if (! $newRequest && ! $continuation) {
            // Do not carry a stale action draft into an unrelated question.
            if ($previous !== null) $this->forget($actor, $conversationId);
            return null;
        }

        // Do not mistake “envía el mensaje que pedí” for new message text.
        $message = $newRequest || preg_match('/^(?:el mensaje es|el texto es|dile que)\b/u', $normalized)
            ? $this->extractMessage($text) : null;
        $draft = $newRequest ? [
            'actor' => $actor,
            'created_at' => now()->timestamp,
            'reference' => $reference,
            'message' => $message,
            'context_uuid' => $reference === null ? $deviceUuid : null,
        ] : $previous;

        if ($draft === null) return null;
        if (! $newRequest) {
            if ($reference !== null) {
                $draft['reference'] = $reference;
                $draft['context_uuid'] = null;
            }
            if ($message !== null) $draft['message'] = $message;
        }

        $message = $draft['message'];
        if (! is_string($message) || trim($message) === '') {
            $this->store($actor, $conversationId, $draft);
            return $this->answer('¿Qué mensaje debe aparecer en la computadora? Indica el texto que quieres comunicar. Ninguna orden fue enviada.');
        }
        if (mb_strlen($message) > 500 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $message)) {
            $this->forget($actor, $conversationId);
            return $this->answer('El mensaje no es válido o supera los 500 caracteres. Ninguna orden fue enviada.');
        }

        $reference = $draft['reference'] ?? $draft['context_uuid'] ?? null;
        if (! is_string($reference) || trim($reference) === '') {
            $this->store($actor, $conversationId, $draft);
            return $this->answer('¿Qué computadora debe recibir el mensaje? Indica su nombre, hostname o UUID. El texto del aviso se conserva durante unos minutos. Ninguna orden fue enviada.');
        }

        $resolution = $this->identities->matches($reference);
        if ($resolution['status'] === 'ambiguous') {
            $this->store($actor, $conversationId, $draft);
            return $this->answer('Encontré varias computadoras con ese nombre. Indica el hostname o UUID de la correcta. Ninguna orden fue enviada.');
        }
        if ($resolution['status'] !== 'found') {
            $this->store($actor, $conversationId, $draft);
            return $this->answer('No encontré un equipo único con ese nombre. Comprueba el nombre, hostname o UUID en «Equipos». Ninguna orden fue enviada.');
        }

        $uuid = $resolution['matches'][0]['uuid'];
        $result = $this->tools->execute('prepare_bulk_action', [
            'action' => 'show_message', 'device_uuids' => [$uuid], 'message' => $message,
        ], $actor);
        $proposal = $result['proposal'] ?? null;
        if (! is_array($proposal)) {
            $this->forget($actor, $conversationId);
            return $this->answer('La computadora está desconectada o no está autorizada para recibir órdenes. No se preparó ni programó ningún envío.');
        }

        $this->forget($actor, $conversationId);
        return [
            'answer' => 'Preparé el mensaje para **'.$resolution['matches'][0]['name'].'**. Revisa el destinatario y el texto en la tarjeta de confirmación. **Ninguna orden fue enviada todavía.**',
            'evidence' => [],
            'proposal' => $proposal,
        ];
    }

    private function isNewMessageRequest(string $text): bool
    {
        $q = $this->normalize($text);
        if (preg_match('/^(?:(?:por favor)\s+)?(?:no|nunca|jamas|que|cual|como|donde|cuando|por que|puedo|se puede|si)\b/u', $q)
            || preg_match('/\b(?:no|nunca|jamas)\s+(?:le\s+)?(?:envies?|mandes?|avises?|digas?|notifiques?|informe?s?)\b/u', $q)) return false;
        // The imperative can precede or follow the target. A generic technical
        // explanation containing 'enviar un mensaje' must not create a proposal.
        return (preg_match('/\b(?:di|dile|diles|avisale|avisales|avisa|notifica|informa|comunica|envia|enviale|manda|mandale)\b/u', $q) === 1)
            && (preg_match('/\b(?:computadora|computador|equipo|pc|notebook|netbook|laptop)\b/u', $q) === 1)
            && (preg_match('/\b(?:a la|al|para la|para el)\s+(?:computadora|computador|equipo|pc|notebook|netbook|laptop)\b/u', $q) === 1);
    }

    private function isRecipientClarification(string $q): bool
    {
        return preg_match('/^(?:la|el|es|se llama|su (?:nombre|hostname)|de nombre|es la que|es el que|la computadora|el equipo|computadora|equipo)\b/u', $q) === 1;
    }

    private function isMessageContinuation(string $q): bool
    {
        return preg_match('/^(?:envia|enviale|manda|mandale|avisa|avisale|prepara|hazlo|si|si hazlo|el mensaje es|dile que)\b/u', $q) === 1
            && preg_match('/\b(?:mensaje|aviso|pedi|anterior|hablamos|dije|texto|prepara|hazlo)\b/u', $q) === 1;
    }

    private function reference(string $text): ?string
    {
        // Parse the recipient clause only. Names, hostnames and UUIDs inside
        // the notice are content, never authorization to choose a recipient.
        $recipientPart = preg_split(
            '/\b(?:diciendo|que\s+(?:el\s+)?mensaje\s+diga|que\s+diga|con\s+(?:el\s+)?texto)\b/iu',
            $text, 2,
        )[0] ?? '';
        $outsideQuotes = preg_replace('/["“«][^"”»]*["”»]/u', ' ', $recipientPart) ?? '';

        if (preg_match('/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i', $outsideQuotes, $m)) return $m[0];
        if (preg_match('/\b(?:DESKTOP|LAPTOP|LAB-PC|ADMIN-PC|PC)-[A-Z0-9-]+\b/i', $outsideQuotes, $m)) return $m[0];

        // Explicit quoted name in the recipient clause is legitimate.
        if (preg_match('/\b(?:computadora|computador|equipo|pc|netbook|notebook|laptop)\s+(?:(?:de\s+)?nombre\s+|llamad[oa]\s+|que\s+se\s+llama\s+)?["“«]([^"”»]+)["”»]/iu', $recipientPart, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/\b(?:de\s+nombre|llamad[oa]|que\s+se\s+llama)\s*["“«]([^"”»]+)["”»]/iu', $recipientPart, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/\b(?:de\s+nombre|llamad[oa]|que\s+se\s+llama)\s+([\pL\pN][\pL\pN ._-]{1,80}?)(?=\s+(?:envia|manda|avisa|dile|di\s+que|que\s+entrar|un\s+mensaje)\b|[.,;]|$)/iu', $outsideQuotes, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/\b(?:computadora|computador|equipo|pc|netbook|notebook|laptop)\s+(?:de\s+la|del|de)\s+([\pL\pN][\pL\pN ._-]{1,70}?)(?=\s+(?:que|envia|manda|avisa|dile|un\s+mensaje)\b|[.,;]|$)/iu', $outsideQuotes, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    private function extractMessage(string $question): ?string
    {
        $content = null;
        if (preg_match('/\b(?:diciendo|que\s+diga|con\s+(?:el\s+)?texto)\s*[:=-]?\s*["“«]([^"”»]{1,500})["”»]/iu', $question, $m)) {
            $content = $m[1];
        } elseif (preg_match('/^\s*(?:el\s+mensaje\s+es|el\s+texto\s+es|dile\s+que)\s+(.+)$/isu', $question, $m)) {
            $content = $m[1];
        } elseif (preg_match('/\b(?:diciendo\s+que|que\s+diga|di\s+que)\s+(.+)$/isu', $question, $m)) {
            $content = $m[1];
        } elseif (preg_match('/^\s*(?:di|dile|diles|avisa|avisale|notifica|informa)\b/iu', $question)
            && preg_match('/\bque\s+(.+)$/isu', $question, $m)) {
            $content = $m[1];
        }
        if ($content === null) return null;

        // Separate instructions about tone from the text of the notice.
        $content = preg_split('/\.\s*(?:se\s+cordial|sé\s+cordial|di\s+que\s+guarde|dile\s+que\s+guarde)\b/iu', $content, 2)[0];
        $content = trim($content, " \t\n\r\0\x0B\"“”«».;!");
        if ($content === '') return null;
        if (preg_match('/^entrar[aá]\s+en\s+mantenimiento\s+el\s+servidor\s+en\s+(\d+)\s*(?:hs?|horas?)$/iu', $content, $m)) {
            $n = (int) $m[1];
            $content = 'El servidor entrará en mantenimiento en '.$n.' '.($n === 1 ? 'hora' : 'horas');
        }
        $content = preg_replace('/\b(\d+)\s*hs?\b/iu', '$1 horas', $content) ?? $content;
        $content = preg_replace('/\b1\s+horas\b/iu', '1 hora', $content) ?? $content;
        $content = rtrim($content, '.').'.';
        $content = mb_strtoupper(mb_substr($content, 0, 1)).mb_substr($content, 1);
        $normalized = $this->normalize($question);
        if (preg_match('/\b(?:se cordial|cordialmente|amablemente)\b/u', $normalized)) {
            $content = 'Hola. '.$content;
        }
        if (preg_match('/\b(?:guarde|guardar|guarden)\s+(?:sus|tus|los)\s+trabajos\b/u', $normalized)) {
            $content .= ' Por favor, guarde sus trabajos antes del mantenimiento.';
        }
        return mb_strlen($content) <= 500 ? $content : null;
    }

    private function read(int $actor, string $conversationId): ?array
    {
        $all = request()->session()->get(self::SESSION_KEY, []);
        $draft = is_array($all) ? ($all[$actor.':'.$conversationId] ?? null) : null;
        if (! is_array($draft)) return null;
        if (($draft['actor'] ?? null) !== $actor || ! is_int($draft['created_at'] ?? null)
            || $draft['created_at'] + self::TTL_SECONDS < now()->timestamp) {
            $this->forget($actor, $conversationId);
            return null;
        }
        return $draft;
    }

    private function store(int $actor, string $conversationId, array $draft): void
    {
        $all = request()->session()->get(self::SESSION_KEY, []);
        if (! is_array($all)) $all = [];
        $all[$actor.':'.$conversationId] = $draft;
        // Per-session bound; do not retain unbounded drafts from discarded tabs.
        if (count($all) > 8) $all = array_slice($all, -8, null, true);
        request()->session()->put(self::SESSION_KEY, $all);
    }

    private function forget(int $actor, string $conversationId): void
    {
        $all = request()->session()->get(self::SESSION_KEY, []);
        if (! is_array($all)) return;
        unset($all[$actor.':'.$conversationId]);
        request()->session()->put(self::SESSION_KEY, $all);
    }

    private function normalize(string $text): string
    {
        $text = strtolower(Str::ascii($text));
        return trim(preg_replace('/\s+/u', ' ', preg_replace('/[^a-z0-9\s-]+/u', ' ', $text) ?? '') ?? '');
    }

    private function answer(string $text): array
    {
        return ['answer' => $text, 'evidence' => [], 'proposal' => null];
    }
}
