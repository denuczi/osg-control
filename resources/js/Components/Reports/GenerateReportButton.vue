<script setup lang="ts">
import { ref, onUnmounted } from 'vue';
import { FileDown, Loader2 } from '@lucide/vue';
import { urls } from '@/types/nodo';
import { csrfHeaders } from '@/Utils/csrf';

type ReportStatus = {
  uuid: string;
  status: 'pending' | 'processing' | 'ready' | 'failed';
  ai_status: 'disabled' | 'pending' | 'completed' | 'unavailable';
  error_code: string | null;
  ai_error_code: string | null;
  download_url: string | null;
};
const props = defineProps<{ deviceUuid: string }>();
const report = ref<ReportStatus | null>(null);
const loading = ref(false);
const error = ref('');
let timer: ReturnType<typeof setTimeout> | undefined;
let disposed = false;
let checks = 0;

async function json(url: string, method = 'GET'): Promise<ReportStatus> {

  const response = await fetch(url, {
    method, credentials: 'same-origin', redirect: 'error',
    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...(method !== 'GET' ? csrfHeaders(document.cookie) : {}) },
  });
  if (!response.ok) throw new Error(`HTTP ${response.status}`);
  return response.json() as Promise<ReportStatus>;
}

async function poll() {
  if (disposed || !report.value) return;
  try {
    report.value = await json(urls.reportStatus(report.value.uuid));
    if (report.value.status === 'ready' || report.value.status === 'failed') { loading.value = false; return; }
    if (++checks >= 90) {
      error.value = 'El informe sigue pendiente. Verificá que el servicio de informes esté activo y volvé a consultarlo.';
      loading.value = false;
      return;
    }
    timer = setTimeout(poll, 3000);
  } catch {
    loading.value = false;
    error.value = 'No se pudo comprobar el estado del informe. Vuelve a intentarlo.';
  }
}
function aiErrorLabel(code: string): string {
  const labels: Record<string, string> = {
    ai_output_truncated: 'la respuesta excedió el límite del modelo',
    ai_empty_content: 'el modelo no devolvió texto',
    ai_invalid_json: 'respuesta incompatible con JSON',
    ai_invalid_schema: 'estructura de respuesta incorrecta',
    ai_invalid_length: 'conclusión fuera de los límites',
    ai_connection_failed: 'no se pudo conectar con el proveedor',
    ai_context_over_budget: 'demasiados datos para la solicitud',
    ai_not_configured: 'proveedor sin configurar',
  };
  if (labels[code]) return labels[code];
  if (/^ai_http_\d{3}$/.test(code)) return `el proveedor devolvió HTTP ${code.slice(-3)}`;
  return 'error de generación de la conclusión';
}
async function generate() {
  if (loading.value) return;
  error.value = '';
  loading.value = true;
  checks = 0;
  if (timer) clearTimeout(timer);
  try {
    report.value = await json(urls.reportCreate(props.deviceUuid), 'POST');
    await poll();
  } catch (e) {
    loading.value = false;
    const status = e instanceof Error ? e.message : '';
    error.value = status === 'HTTP 419'
      ? 'La sesión caducó o cambió. Actualiza la página, inicia sesión si es necesario y vuelve a generar el informe.'
      : status === 'HTTP 429'
        ? 'Límite temporal o diario de informes alcanzado.'
        : status === 'HTTP 401' || status === 'HTTP 403'
          ? 'No tenés una sesión de administrador activa. Iniciá sesión nuevamente.'
          : 'No se pudo solicitar el informe. Comprobá la conexión y los permisos.';
  }
}
onUnmounted(() => { disposed = true; if (timer) clearTimeout(timer); });
</script>

<template>
  <div class="report-export">
    <button type="button" class="button button-secondary" :disabled="loading" @click="generate">
      <Loader2 v-if="loading" :size="16" aria-hidden="true" />
      <FileDown v-else :size="16" aria-hidden="true" />
      {{ loading ? 'Generando informe…' : 'Generar informe PDF' }}
    </button>
    <a v-if="report?.status === 'ready' && report.download_url" class="button button-primary" :href="report.download_url">Descargar PDF</a>
    <span v-if="report?.status === 'ready' && report.ai_status === 'unavailable'" class="muted" role="status">PDF generado sin conclusión de IA ({{ report.ai_error_code ? aiErrorLabel(report.ai_error_code) : 'error no identificado' }}).</span>
    <span v-if="report?.status === 'failed'" role="alert" class="field-error">No se pudo generar el PDF. Contactá al administrador para revisar el servicio de informes.</span>
    <span v-if="error" role="alert" class="field-error">{{ error }}</span>
  </div>
</template>
