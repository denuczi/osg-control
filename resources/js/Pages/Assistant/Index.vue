<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Bot, ShieldCheck, Database, MessagesSquare } from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import AiConversation from '@/Components/Assistant/AiConversation.vue';

defineProps<{ available: boolean; deviceContext: { uuid: string; name: string } | null }>();
</script>
<template>
  <AppLayout immersive>
    <Head title="Asistente de equipos" />
    <div class="assistant-screen">
      <header class="page-titlebar ai-page-title">
        <div>
          <h1>Asistente de equipos</h1>
          <p>Consulta información del inventario y prepara acciones con autorización.</p>
        </div>
        <details class="assistant-help-disclosure">
          <summary><ShieldCheck :size="16" aria-hidden="true" /> Información y seguridad</summary>
          <div class="assistant-help-content">
            <p><Database :size="16" aria-hidden="true" /> Los datos proceden de capturas registradas; pueden estar desactualizados.</p>
            <p><MessagesSquare :size="16" aria-hidden="true" /> Puedes consultar equipos, procesos, recursos e informes.</p>
            <p><Bot :size="16" aria-hidden="true" /> Las acciones remotas requieren confirmación del administrador.</p>
          </div>
        </details>
      </header>
      <AiConversation :key="deviceContext?.uuid ?? 'fleet'" :available="available" :context="deviceContext" />
    </div>
  </AppLayout>
</template>
