<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue';
import { Download, FileText, LoaderCircle, RefreshCw, AlertCircle, CheckCircle2, Clock3 } from '@lucide/vue';
import type { AssistantReport } from '@/types/assistant';

const props = defineProps<{ report: AssistantReport }>();
type Status = 'pending' | 'processing' | 'ready' | 'failed';
const status = ref<Status>(props.report.status);
const downloadUrl = ref<string | null>(null);
const message = ref('');
let checks = 0;
let timer: ReturnType<typeof setTimeout> | undefined;
let disposed = false;

async function refresh(): Promise<void> {
  if (disposed || status.value === 'failed' || (status.value === 'ready' && downloadUrl.value)) return;
  try {
    const response = await fetch(props.report.status_url, {
      credentials: 'same-origin', redirect: 'error',
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    });
    if (!response.ok) {
      message.value = response.status === 419 || response.status === 401
        ? 'La sesión caducó. Iniciá sesión para consultar el informe.'
        : 'No pude consultar el estado del informe. Podés reintentar.';
      return;
    }
    const data = await response.json() as { uuid?: string; status?: Status; download_url?: string | null; error_code?: string | null };
    if (disposed || data.uuid !== props.report.uuid ||
      !['pending', 'processing', 'ready', 'failed'].includes(data.status ?? '')) {
      message.value = 'La respuesta del informe no es válida.';
      return;
    }
    status.value = data.status as Status;
    if (status.value === 'ready') {
      downloadUrl.value = data.download_url ?? null;
      message.value = downloadUrl.value ? '' : 'El informe finalizó, pero todavía no tiene un enlace de descarga.';
    } else if (status.value === 'failed') {
      message.value = 'No se pudo generar el PDF. Revisá que el servicio de informes esté funcionando.';
    } else if (++checks < 90) {
      timer = setTimeout(() => { void refresh(); }, 3000);
    } else {
      message.value = 'El informe sigue pendiente. Podés volver a consultar su estado.';
    }
  } catch {
    if (!disposed) message.value = 'No pude consultar el estado. Comprobá la conexión y reintentá.';
  }
}
function retry(): void {
  if (timer) clearTimeout(timer);
  message.value = '';
  checks = 0;
  void refresh();
}
onMounted(() => { void refresh(); });
onUnmounted(() => { disposed = true; if (timer) clearTimeout(timer); });
</script>

<template>
  <section class="ai-report-card" aria-label="Estado del informe PDF">
    <div class="ai-report-main">
      <span class="ai-report-icon" aria-hidden="true"><FileText :size="21" :stroke-width="1.8" /></span>
      <div class="ai-report-copy">
        <span class="ai-report-type">Documento PDF</span>
        <h3>{{ report.kind === 'fleet' ? 'Inventario general de equipos' : 'Informe de computadora' }}</h3>
        <p>{{ report.kind === 'fleet'
          ? 'Resumen de los equipos registrados y su información disponible.'
          : 'Detalle del equipo y sus mediciones registradas.' }}</p>
      </div>
      <span :class="['ai-report-state', `is-${status}`, { 'is-unavailable': status === 'ready' && !downloadUrl }]" role="status">
        <CheckCircle2 v-if="status === 'ready' && downloadUrl" :size="14" aria-hidden="true" />
        <AlertCircle v-else-if="status === 'failed' || (status === 'ready' && !downloadUrl)" :size="14" aria-hidden="true" />
        <LoaderCircle v-else :size="14" class="ai-spin" aria-hidden="true" />
        {{ status === 'ready' ? (downloadUrl ? 'Disponible' : 'Sin enlace') : status === 'failed' ? 'Fallido' : status === 'processing' ? 'Generando' : 'En espera' }}
      </span>
    </div>
    <div class="ai-report-footer">
      <div class="ai-report-feedback" aria-live="polite">
        <p v-if="message" :class="['ai-report-message', { 'is-error': status === 'failed' || status === 'ready' }]">{{ message }}</p>
        <p v-else-if="status === 'pending' || status === 'processing'" class="ai-report-message">
          <Clock3 :size="14" aria-hidden="true" /> {{ status === 'pending' ? 'Esperando al servicio de informes.' : 'Preparando el archivo para descarga.' }}
        </p>
        <p v-else-if="status === 'failed'" class="ai-report-message is-error">No se pudo generar el PDF. Revisá el servicio de informes.</p>
        <p v-else-if="status === 'ready'" class="ai-report-message">Archivo listo para descargar.</p>
      </div>
      <a v-if="status === 'ready' && downloadUrl" class="button button-primary ai-report-download" :href="downloadUrl">
        <Download :size="16" aria-hidden="true" /> Descargar PDF
      </a>
      <button v-else-if="message && status !== 'failed'" class="button button-secondary ai-report-retry" type="button" @click="retry">
        <RefreshCw :size="15" aria-hidden="true" /> Comprobar estado
      </button>
    </div>
  </section>
</template>
