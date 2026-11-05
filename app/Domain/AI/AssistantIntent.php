<?php

namespace App\Domain\AI;

use Illuminate\Support\Str;

/** Clasificación conservadora: las consultas comunes no dependen de llamadas elegidas por el modelo. */
final class AssistantIntent
{
    public function readPlan(string $question, ?string $deviceUuid = null, array $history = []): ?string
    {
        $q = $this->normalize($question);
        // Las recomendaciones elípticas solo heredan un tema técnico verificable
        // de la pregunta anterior del usuario. Nunca heredan destinatarios de acciones.
        if ($this->asksForDiskAdvice($q, $history)) {
            return 'disk_space_advice';
        }
        // Consultas de causa sobre RAM en reposo: respuesta técnica con las
        // mediciones verificadas, no con un ranking de procesos ni texto genérico.
        // Evitar convertir preguntas u órdenes de acciones en un plan de lectura.
        if (! $this->permitsActions($question)
            && preg_match('/\b(?:ram|memoria(?:\s+(?:ram|fisica))?)\b/u', $q)
            && preg_match('/\b(?:por que|porque|a que se debe|cual es la causa|cuales son las causas|como es posible|razon|motivo|explica|a que se debera)\b/u', $q)
            && preg_match('/\b(?:uso|usada|usado|utilizad[ao]|ocupad[ao]|consum[eo]|consume|gast[ao]|porcentaje|\d{1,3}|mitad|siempre|constante|mantien\w*|reposo|inactiv[ao]|sin hacer nada|no hago nada|alta|elevada|llena)\b/u', $q)) {
            return 'memory_usage_advice';
        }
        // La evaluación de rendimiento se basa en CPU/RAM/disco y actualidad
        // de la captura, nunca en una lista de procesos ordenados por RAM.
        if (preg_match('/\b(?:rendimiento|lenta|lento|lentitud|cuello de botella|se traba|problemas? de velocidad)\b/u', $q)
            && preg_match('/\b(?:equipo|equipos|computadora|computadoras|pc|pcs|maquina|maquinas|problema|problemas|cual|cuales|que)\b/u', $q)) {
            return 'performance_diagnosis';
        }
        // En la ficha del equipo, preguntas elípticas sobre actualizar hardware
        // son consultas de lectura, nunca órdenes de cambiar componentes.
        if ($deviceUuid !== null
            && preg_match('/\b(?:componentes?|actualizar|ampliar|upgrade|mejorar|cambiar|reemplazar|renovar|ram|ssd|procesador|cpu)\b/u', $q)
            && preg_match('/\b(?:conviene|deberia|debo|crees|recomiendas|recomendas|momento|vale la pena|necesita|cambiar|mejorar|upgrade)\b/u', $q)) {
            return 'device_upgrade_advice';
        }
        // Report the assistant's actual configured model, not an LLM's guess.
        if (preg_match('/\b(modelo|inteligencia artificial|ia|llm)\b/u', $q) &&
            preg_match('/\b(que|cual|como|nombre|modelo|identidad|eres|sos|usas|utilizas)\b/u', $q) &&
            preg_match('/\b(eres|sos|utilizas|usas|funcionas|asistente|que modelo de ia|cual modelo de ia|modelo de ia es|modelo de inteligencia artificial es)\b/u', $q) &&
            ! preg_match('/\b(equipos?|computadoras?|pcs?|hardware)\b/u', $q)) {
            return 'assistant_identity';
        }
        // Consultas propias del producto: no delegar respuestas fácticas al modelo.
        if (preg_match('/\b(osg|nodo control|nodo ai|este sistema|esta plataforma|este software)\b/u', $q)) {
            if (preg_match('/\b(lenguajes?|tecnologias?|programado|desarrollado|hecho|construido|stack)\b/u', $q)) {
                return 'product_technology';
            }
            if (preg_match('/\b(que es|que hace|para que sirve|como funciona|definicion)\b/u', $q)) {
                return 'product_about';
            }
        }
        if (preg_match('/\b(en que|con que)\s+lenguajes?\b/u', $q) &&
            preg_match('/\b(sistema|software|aplicacion|plataforma|hecho|programado)\b/u', $q)) {
            return 'product_technology';
        }
        // Conexiones de almacenamiento USB registradas: datos históricos, no órdenes remotas.
        // "Detectado" al iniciar el agente NO equivale a una nueva conexión.
        $usbStorage = preg_match('/\b(pendrives?|pen drives?|memorias? usb|unidades? usb|almacenamiento usb|dispositivos? usb|unidades? extraibles?|memorias? extraibles?|medios? extraibles?)\b/u', $q) === 1;
        $usbConnection = preg_match('/\b(conect\w*|conexiones?|enchuf\w*|insert\w*|introduj\w*|recient\w*|ultim\w*|historial|eventos?|registro|cuando|detect\w*)\b/u', $q) === 1;
        if ($usbStorage && $usbConnection) {
            return 'usb_connections';
        }
        // «¿Y la computadora de Félix?» conserva el tema USB del turno anterior.
        // La identidad se resuelve contra el inventario; nunca se infiere por el texto del modelo.
        if (! $this->permitsActions($question) && $this->usbDeviceReference($question) !== null
            && $this->hasUsbContext($history)) {
            return 'usb_device_connections';
        }
        if (preg_match('/\b(consumo|trafico|transferencia|descarga|subida|bajada|bytes|datos)\b/u', $q) &&
            preg_match('/\b(red|redes|internet|ethernet|wifi|conexiones?)\b/u', $q)) {
            return 'network_usage';
        }
        if (preg_match('/\b(apag[oa]|apag[ao]s?|apagados?|apagaron|reinicio|reinicios|reiniciaron|cierre|cierres)\b/u', $q) &&
            preg_match('/\b(inesperad[ao]s?|repentinamente|abruptamente|bruscamente|sin aviso|sin motivo|de golpe|sol[oa]s?|incorrectamente|no limpio|forzado)\b/u', $q)) {
            return 'shutdown_history';
        }
        if (preg_match('/\b(computadoras?|equipos?|pcs?|compus?|maquinas?)\b/u', $q) &&
            preg_match('/\b(mas se utiliza|mas se usa|mayor uso|mas actividad|mas utilizada|mas usada)\b/u', $q)) {
            return 'usage_ambiguous';
        }

        // Selección orientativa para alojar aplicaciones: solo evidencia local,
        // nunca requisitos inventados ni certificación de compatibilidad.
        if (preg_match('/\b(equipos?|computadoras?|compus?|pcs?|netbooks?|notebooks?)\b/u', $q)
            && preg_match('/\b(python|flask|django|fastapi|servidor web|desplegar|ejecutar|alojar)\b/u', $q)
            && preg_match('/\b(cual|cuales|que|pueden|podran|podria|podrian|sirven|mejor)\b/u', $q)) {
            return 'deployment_candidates';
        }

        if (preg_match('/\b(?:gta\s*(?:v|5)|grand theft auto(?:\s*(?:v|5))?)\b/u', $q) &&
            preg_match('/\b(descargar|instalar|instalacion|corre|correr|sirve|sirven|servira|serviria|soporta|jugar|compatible|requisitos|podra|podria|funciona|funcionara|funcionaria|conviene|edicion|version)\b/u', $q)) {
            return 'software_compatibility';
        }

        $mentionsProcess = preg_match('/\b(procesos?|aplicaciones?)\b/u', $q) === 1;
        $mentionsConsumption = preg_match('/\b(ram|memoria|consum[eoins]*|demandantes?|pesados?)\b/u', $q) === 1;

        // Sin un dispositivo explícito, la comparación debe considerar toda la flota,
        // incluidos los últimos registros de los equipos desconectados.
        if ($deviceUuid === null && $mentionsProcess && $mentionsConsumption &&
            ! preg_match('/\b(?:desktop|lab-pc|admin-pc)-[a-z0-9-]+\b/u', $q)) {
            return 'fleet_processes';
        }

        if (! $mentionsProcess && preg_match('/\b(mas|mayor)\s+(?:cantidad\s+de\s+)?(?:ram|memoria)\b/u', $q) &&
            preg_match('/\b(cual|que|equipo|computadora|pc|tiene)\b/u', $q)) {
            return 'ram_leader';
        }

        if (preg_match('/^(?:(?:pero|entonces)\s+)?(?:cuales\s+son|que\s+equipos?\s+(?:hay|estan)\s+registrados?|mostrame\s+(?:los\s+)?equipos?\s+registrados?)(?:\b|$)/u', $q)) {
            return 'device_list';
        }

        if (! $mentionsProcess && preg_match('/\b(cuantos?|cantidad|numero)\b/u', $q) &&
            (preg_match('/\b(equipos?|computadoras?|pcs?|registrados?|son|hay)\b/u', $q))) {
            return 'fleet_count';
        }

        return null;
    }

    /** @param list<array{role:string,content:string}> $history */
    private function asksForDiskAdvice(string $question, array $history): bool
    {
        // Una pregunta que incluye una orden remota no se resuelve como consejo.
        if (preg_match('/\b(apagar|apaga|apaguen|reiniciar|reinicia|envia|enviar|mandar|manda|cerrar proceso)\b/u', $question)) {
            return false;
        }
        $asksAdvice = preg_match('/\b(?:recomiendas|recomendas|recomiendan|recomendacion(?:es)?|aconsejas|sugieres|que (?:puedo|debo|conviene) hacer|que hago|que harias)\b/u', $question) === 1
            || preg_match('/\bcomo\b.*\b(?:reducir|reduzco|liberar|limpiar|solucionar|soluciono)\b/u', $question) === 1;
        if (! $asksAdvice) return false;

        $diskTerms = '/\b(discos?|almacenamiento|espacio (?:libre|ocupado)|unidad principal|volumen(?:es)?|gb libres|liberar espacio)\b/u';
        if (preg_match($diskTerms, $question)) return true;

        // Sin referencia («esto», «eso», «lo»), no asumir un ámbito por historial.
        if (! preg_match('/\b(esto|eso|lo|esa ocupacion|ese porcentaje|al respecto)\b/u', $question)) return false;
        // Solo la última pregunta del usuario (no el texto del modelo) fija el tema.
        foreach (array_reverse(array_slice($history, -6)) as $turn) {
            if (($turn['role'] ?? null) !== 'user') continue;
            return preg_match($diskTerms, $this->normalize((string) ($turn['content'] ?? ''))) === 1;
        }
        return false;
    }

    /**
     * Extrae solo referencias explícitas a equipos. Un nombre no se considera
     * identidad confirmada hasta resolverlo contra el inventario.
     */
    public function usbDeviceReference(string $question): ?string
    {
        if (preg_match('/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i', $question, $uuid)) {
            return $uuid[0];
        }
        if (preg_match('/\b(?:DESKTOP|LAPTOP|LAB-PC|ADMIN-PC|PC)-[A-Z0-9-]{1,}\b/i', $question, $hostname)) {
            return $hostname[0];
        }
        if (! preg_match('/\b(?:computadora|compu|equipo|pc|notebook|netbook)\s+(?:de(?:l)?\s+)?(?<name>[\pL\pN][\pL\pN\s._-]{1,70})/iu', $question, $match)) {
            // Seguimientos breves como «¿Y Félix?» tras una consulta USB.
            if (! preg_match('/^\s*¿?\s*y\s+(?:la\s+de\s+|de\s+)?(?<name>[\pL][\pL\pN\s._-]{2,60})\s*\??\s*$/iu', $question, $match)) {
                return null;
            }
        }
        $name = trim(preg_replace('/\s+(?:es|tiene|esta|conecto|conectaron|que|porque|hoy|ayer|recientemente|durante|esta semana|los ultimos dias)\b.*$/iu', '', $match['name']) ?? '');
        $normalized = $this->normalize($name);
        if (mb_strlen($normalized) < 3 || preg_match('/^(?:conect|usb|pendrive|registrad|hay|alguna|conexiones)/u', $normalized)) {
            return null;
        }
        return $name;
    }

    /** @param list<array{role:string,content:string}> $history */
    private function hasUsbContext(array $history): bool
    {
        $inspected = 0;
        foreach (array_reverse($history) as $entry) {
            if (($entry['role'] ?? null) !== 'user') continue;
            if (++$inspected > 3) return false;
            $q = $this->normalize((string) ($entry['content'] ?? ''));
            if (preg_match('/\b(pendrives?|pen drives?|memorias? usb|unidades? usb|dispositivos? usb)\b/u', $q)) {
                return true;
            }
            // Se admiten referencias intermedias al dispositivo, no cambios de tema.
            if ($this->usbDeviceReference((string) ($entry['content'] ?? '')) !== null
                && ! $this->permitsActions((string) ($entry['content'] ?? ''))) {
                continue;
            }
            return false;
        }
        return false;
    }

    /** @param list<array{role:string,content:string}> $history */
    public function usbPeriod(string $question, array $history): string
    {
        if (preg_match('/\b(hoy|ayer|mes|mensual|30 dias|ultima semana|ultimos? 7 dias)\b/u', $this->normalize($question))) {
            return $this->dataPeriod($question);
        }
        foreach (array_reverse($history) as $entry) {
            if (($entry['role'] ?? null) !== 'user') continue;
            $q = $this->normalize((string) ($entry['content'] ?? ''));
            if (preg_match('/\b(pendrives?|pen drives?|memorias? usb|unidades? usb|dispositivos? usb)\b/u', $q)) {
                return $this->dataPeriod((string) $entry['content']);
            }
        }
        return '7d';
    }

    /** Períodos que el backend puede calcular sin inventar rangos. */
    public function dataPeriod(string $question): string
    {
        $q = $this->normalize($question);
        if (preg_match('/\b(ayer)\b/u', $q)) return 'yesterday';
        if (preg_match('/\b(hoy)\b/u', $q)) return 'today';
        if (preg_match('/\b(30 dias|ultimo mes)\b/u', $q)) return '30d';
        if (preg_match('/\b(mes|mensual|este mes)\b/u', $q)) return 'month';
        return '7d';
    }

    /** Una petición de envío no tiene por qué comenzar por el verbo: "al equipo prendido enviale...". */
    public function requestsMessageAction(string $question): bool
    {
        $instruction = preg_replace('/["“«][^"”»]*["”»]/u', ' ', $question) ?? $question;
        $q = $this->normalize($instruction);
        if (preg_match('/^(?:no|nunca|que|cual|como|donde|en que parte|por que|cuando|puedo|se puede|si)\b/u', $q)
            || preg_match('/\b(?:no|nunca|jamas)\s+(?:le\s+)?(?:digas?|avises?|envies?|mandes?|notifiques?)\b/u', $q)) {
            return false;
        }
        // 'Dile a las conectadas que ...' es un mensaje aunque no contenga
        // literalmente las palabras mensaje, aviso o notificación.
        $imperative = preg_match('/\b(?:di|dile|diles|envia|enviar|enviale|enviales|manda|mandar|mandale|mandales|avisa|avisar|avisale|avisales|notifica|notificar|informa|informar|comunica|comunicar|transmite|hazles saber|dales aviso|mostra|mostrar)\b/u', $q) === 1;
        $recipients = preg_match('/\b(?:equipos?|computadoras?|computadores?|pcs?|compus?|notebooks?|netbooks?|conectad[oa]s?|encendid[oa]s?|prendid[oa]s?)\b/u', $q) === 1;
        $content = preg_match('/\b(?:mensaje|aviso|notificacion|texto|que|diciendo|comunicar|informar)\b/u', $q) === 1;
        // Un hostname puede ser el destinatario directo de «envía un mensaje a
        // HOST-01», sin incluir literalmente «equipo» o «computadora».
        // Inspeccionar únicamente la instrucción (el cuerpo citado ya se eliminó)
        // evita que un nombre dentro del aviso autorice otro destinatario.
        $explicitNamedRecipient = preg_match(
            '/\b(?:mensaje|aviso|notificacion|texto)\s+(?:a|para)\s+(?!todos?\b|todas?\b)[a-z0-9][a-z0-9._-]{1,119}\b/u',
            $q,
        ) === 1;
        return $imperative && ($recipients || $explicitNamedRecipient) && $content;
    }

    /** Una indicación contextual de ubicación, no una autorización para ejecutar. */
    public function asksWhereToSendMessage(string $question, array $history): bool
    {
        $q = $this->normalize($question);
        if (! preg_match('/^(?:en que parte|donde|donde esta|como lo hago|como envio el mensaje|donde puedo hacerlo)\??$/u', $q)) {
            return false;
        }
        foreach (array_reverse($history) as $turn) {
            if (($turn['role'] ?? null) !== 'user') continue;
            return $this->requestsMessageAction((string) ($turn['content'] ?? ''));
        }
        return false;
    }

    /**
     * Preselección de alta cobertura para evitar una segunda llamada al modelo
     * en preguntas técnicas generales. La interpretación semántica sigue a cargo
     * del modelo y la autorización permanece en el servidor.
     */
    public function mayNeedSemanticRouting(string $question): bool
    {
        $q = $this->normalize($question);
        return preg_match('/\b(comuni(?:c|q)\w*|avis\w*|mensaj\w*|notific\w*|redact\w*|inform\w*|'
            .'(?:dile|diles|di|transmite|hazles\s+saber)|navegadores?|browser|chrome|firefox|brave|edge|opera|abiert[oa]s?|ejecutandose)\b/u', $q) === 1;
    }

    /** Nunca expone funciones de acción a una consulta informativa o hipotética. */
    public function permitsActions(string $question): bool
    {
        $q = $this->normalize($question);
        if (preg_match('/^(?:no|nunca)\s+/u', $q)) {
            return false;
        }
        if (preg_match('/^(?:que|cual|como|por que|cuando|puedo|se puede)\b/u', $q)) {
            return false;
        }
        if ($this->requestsMessageAction($question)) return true;

        return preg_match('/^(?:(?:por favor|quiero que|necesito que|podes|podrias|puedes)\s+){0,2}'
            .'(?:apaga|apaguen|apagar|apagal[oa]s?|reinicia|reinicien|reiniciar|reinicial[oa]s?|'
            .'envia|enviar|enviale|enviales|manda|mandale|mandales|avisa|avisale|avisales|'
            .'mostra\s+un\s+mensaje|mostrar\s+un\s+mensaje)\b/u', $q) === 1;
    }

    /** Un ordinal depende de una lista visual que el servidor no guarda como selección autorizada. */
    public function usesOrdinalReference(string $question): bool
    {
        return preg_match('/\b(primero|primera|primer|segundo|segunda|tercero|tercera|ultimo|ultima)\b/u',
            $this->normalize($question)) === 1;
    }

    /** Permite que el modelo reduzca la búsqueda a equipos conectados solo si se solicitó. */
    public function asksOnlyOnline(string $question): bool
    {
        return preg_match('/\b(conectad[oa]s?|online|en linea|encendid[oa]s?|activ[oa]s? ahora)\b/u', $this->normalize($question)) === 1;
    }

    private function normalize(string $question): string
    {
        $q = strtolower(Str::ascii($question));
        $q = preg_replace('/[^a-z0-9\s-]+/u', ' ', $q) ?? '';
        return trim(preg_replace('/\s+/u', ' ', $q) ?? '');
    }
}
