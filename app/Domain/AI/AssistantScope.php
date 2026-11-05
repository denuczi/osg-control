<?php

namespace App\Domain\AI;

use Illuminate\Support\Str;

/** The server enforces the assistant's product boundary before calling an LLM. */
final class AssistantScope
{
    public function __construct(private readonly AssistantIntent $intent) {}

    public function allows(string $question, array $history = [], ?string $deviceUuid = null): bool
    {
        $q = trim(preg_replace('/[^a-z0-9\s-]+/u', ' ', strtolower(Str::ascii($question))) ?? '');
        $q = trim(preg_replace('/\s+/u', ' ', $q) ?? '');

        // Familiar prompts cannot acquire infrastructure scope from older chat messages.
        if (preg_match('/\b(enamora(?:do|da|r)|novi[oa]s?|pareja|relacion sentimental|prima|primo|sexo|cita romantica|recetas?|cocin(?:ar|a|ando)|lasana|comidas?|hornear|ingredientes|horoscopo|terapia|depresion)\b/u', $q)
            && ! preg_match('/\b(pc|computadora|equipo|hardware|software|servidor|redes?|wifi|sistema operativo)\b/u', $q)) {
            return false;
        }

        if ($this->intent->requestsMessageAction($question)) return true;

        // Admite una recomendación de seguimiento solo cuando el contexto anterior
        // identifica de forma inequívoca un tema de infraestructura de solo lectura.
        if (in_array($this->intent->readPlan($question, $deviceUuid, $history), ['disk_space_advice', 'assistant_identity'], true)) {
            return true;
        }

        if (preg_match('/\b(equipos?|computadoras?|ordenadores?|pcs?|portatiles?|notebooks?|netbooks?|'
            .'hardware|software|osg|nodo|lenguajes?|tecnologias?|este sistema|esta plataforma|procesos?|cpu|procesadores?|nucleos?|ram|memoria|gpu|grafic[ao]s?|'
            .'discos?|ssd|hdd|almacenamiento|windows|linux|servidores?|infraestructura|inventario|'
            .'red|redes|trafico|consumo de red|consumo de internet|wifi|ethernet|conectad[oa]s?|desconectad[oa]s?|monitoreo|metricas?|'
            .'compus?|apagones?|apagados? inesperados?|antivirus|defender|firewall|apag[ao]r?|reinici[ao]r?|comandos?|'
            .'utiliza|utilizad[ao]s?|actividad|instalar|instalacion|descargar|actualizar|actualizaciones|juegos?|gta|espacio|capacidad|dispositivos?|programas?|servicios|puertos?|internet|conexion|'
            .'requisitos|compatibilidad|rendimiento|diagnostico|eventos?|informes?|reportes?|pdf|'
            .'nodo control|nodo agent|flask|django|fastapi|python|nodejs|node js|node|javascript|typescript|php|laravel|vue|react|express|nestjs|backend|frontend|frameworks?|despliegue|deploy|docker|contenedores?|apis?|rest|http|https|servidor web|base de datos|postgresql|mysql|redis|git|nginx|apache|pip|virtualenv|comuni(?:c|q)\w*|notific\w*|inform\w*|mensajes?|avis\w*|encendid[oa]s?|prendid[oa]s?|usb|pendrives?|pen drives?|extraibles?|removibles?|bateria|temperatura|navegadores?|chrome|excel|word)\b/u', $q)) {
            return true;
        }
        if (preg_match('/^(hola|buenas|gracias|que podes hacer|que puedes hacer|ayuda|que sabes hacer)[?.! ]*$/u', $q)) {
            return true;
        }
        // El asistente puede conversar sobre tecnología aunque no coincida con
        // un vocabulario cerrado. Las acciones continúan gobernadas por su
        // política independiente y nunca se habilitan por esta decisión.
        // El contexto de un equipo habilita preguntas elípticas de asesoramiento,
        // no autoriza destinatarios para órdenes remotas.
        if ($deviceUuid !== null) return true;

        // Un seguimiento puede heredar el ámbito de la última pregunta técnica
        // del usuario, pero no de una respuesta generada por el modelo.
        $isFollowUp = mb_strlen($q) <= 110 && preg_match(
            '/^(?:y |de esos|de esas|cuales|cual|cuantos|por que|pero |los |las |que hay|mostrame|dame |que (?:me )?recomiend|que recomendas|que (?:puedo|debo|conviene) hacer|como (?:lo |puedo )?(?:reducir|reduzco|mejorar|solucionar|liberar))/u',
            $q,
        );
        if ($isFollowUp && $deviceUuid !== null) return true;
        if ($isFollowUp) {
            foreach (array_reverse(array_slice($history, -6)) as $turn) {
                if (($turn['role'] ?? null) !== 'user') continue;
                return $this->allows((string) ($turn['content'] ?? ''), [], null);
            }
            // «Reducir esto» sin un referente no es una pregunta tecnológica
            // verificable. Preguntar por el contexto antes de invocar al modelo.
            return false;
        }
        // Solo las solicitudes inequívocamente ajenas al ámbito tecnológico
        // se rechazan arriba. Consultas informativas nuevas se delegan al modelo.
        // Esto evita bloquear hardware/software/redes por falta de una palabra clave.
        return true;
    }
}
