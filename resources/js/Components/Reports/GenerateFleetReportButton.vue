<script setup lang="ts">
import { onUnmounted, ref } from 'vue';
import { FileDown, Loader2 } from '@lucide/vue';
import { urls } from '@/types/nodo';
import { csrfHeaders } from '@/Utils/csrf';

type State = {
  uuid: string;
  status: 'pending' | 'processing' | 'ready' | 'failed';
  error_code: string | null;
  device_count: number | null;
  download_url: string | null;
};

const report = ref<State | null>(null);
const loading = ref(false);
const error = ref('');
let timer: ReturnType<typeof setTimeout> | undefined;
let attempts = 0;
let disposed = false;

async function request(url: string, method: 'GET' | 'POST' = 'GET'): Promise<State> {

  const response = await fetch(url, {
    method,
    credentials: 'same-origin',
    redirect: 'error',
    headers: {
      Accept: 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      ...(method === 'POST' ? csrfHeaders(document.cookie) : {}),
    },
  });
  if (!response.ok) throw new Error(String(response.status));
  return response.json() as Promise<State>;
}

async function poll(): Promise<void> {
  if (disposed || !report.value) return;
  try {
    const current = await request(urls.fleetReportStatus(report.value.uuid));
    if (disposed) return;
    report.value = current;
    if (current.status === 'ready' || current.status === 'failed') {
      loading.value = false;
      return;
    }
    if (++attempts >= 90) {
      loading.value = false;
      error.value = 'La exportación sigue pendiente. Comprobá que el servicio de informes esté activo.';
      return;
    }
    timer = setTimeout(() => { void poll(); }, 3000);
  } catch {
    loading.value = false;
    error.value = 'No se pudo consultar el estado de la exportación. Vuelve a intentarlo.';
  }
}

async function generate(): Promise<void> {
  if (loading.value) return;
  attempts = 0;
  error.value = '';
  report.value = null;
  loading.value = true;
  if (timer) clearTimeout(timer);
  try {
    report.value = await request(urls.fleetReportCreate, 'POST');
    await poll();
  } catch (e) {
    loading.value = false;
    const status = e instanceof Error ? e.message : '';
    error.value = status === '419'
      ? 'La sesión caducó o cambió. Actualiza la página, inicia sesión si es necesario y vuelve a solicitar el inventario.'
      : status === '429'
        ? 'Se alcanzó el límite de generación de inventarios.'
        : status === '401' || status === '403'
          ? 'No tenés una sesión de administrador activa. Iniciá sesión nuevamente.'
          : 'No se pudo solicitar el inventario. Verificá la conexión y los permisos.';
  }
}

onUnmounted(() => {
  disposed = true;
  if (timer) clearTimeout(timer);
});
</script>

<template>
  <div class="fleet-export" role="group" aria-label="Exportar inventario de equipos">
    <button type="button" class="button button-secondary" :disabled="loading" @click="generate">
      <Loader2 v-if="loading" :size="16" aria-hidden="true" />
      <FileDown v-else :size="16" aria-hidden="true" />
      {{ loading ? 'Preparando inventario…' : 'Exportar inventario PDF' }}
    </button>
    <a v-if="report?.status === 'ready' && report.download_url"
       class="button button-primary" :href="report.download_url">
      Descargar PDF ({{ report.device_count ?? 0 }} equipos)
    </a>
    <span v-if="report?.status === 'failed'" class="field-error" role="alert">
      {{ report.error_code === 'fleet_device_limit_exceeded'
        ? 'El inventario supera el límite permitido para una exportación. Consultá con el administrador.'
        : 'No se pudo generar el inventario. Intentá nuevamente.' }}
    </span>
    <span v-if="error" class="field-error" role="alert">{{ error }}</span>
  </div>
</template>
