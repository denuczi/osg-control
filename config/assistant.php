<?php

return [
    // Habilitación independiente de las conclusiones de informes.
    'enabled' => (bool) env('NODO_ASSISTANT_ENABLED', false),
    'max_question_chars' => 1200,
    'max_history_items' => 6,
    'max_tools_per_request' => 6,
    'max_devices_per_action' => 20,
    'proposal_ttl_seconds' => 120,
    // El presupuesto de llamadas y tiempo se configura en Configuración > Asistente IA.
    // Valores predeterminados según motor local o API remota: AssistantRuntime.
];
