<script setup lang="ts">
import { nextTick, onMounted, onUnmounted, ref } from 'vue';
import { AlertCircle, BookOpenText, LoaderCircle, ShieldCheck, Sparkles, X } from '@lucide/vue';
import AiFormattedText from '@/Components/Assistant/AiFormattedText.vue';
import { csrfHeaders } from '@/Utils/csrf';
import { urls, type ProcessInformation } from '@/types/nodo';

const props = defineProps<{
  processName: string;
  deviceUuid: string;
  initialResult?: ProcessInformation;
}>();
const emit = defineEmits<{ close: []; resolved: [name: string, result: ProcessInformation] }>();
const closeButton = ref<HTMLButtonElement | null>(null);
const panel = ref<HTMLElement | null>(null);
const result = ref<ProcessInformation | null>(props.initialResult ?? null);
const loading = ref(!props.initialResult);
const error = ref('');
let controller: AbortController | null = null;
let previousFocus: HTMLElement | null = null;
let previousBodyOverflow = '';
let mounted = false;

async function load(): Promise<void> {
  if (result.value || controller) return;
  controller = new AbortController();
  const request = controller;
  loading.value = true;
  error.value = '';
  try {
    const response = await fetch(urls.deviceProcessKnowledge(props.deviceUuid), {
      method: 'POST', credentials: 'same-origin', redirect: 'error', signal: request.signal,
      headers: { Accept: 'application/json', 'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest', ...csrfHeaders(document.cookie) },
      body: JSON.stringify({ process: props.processName }),
    });
    if (!response.ok) {
      if (response.status === 419) throw new Error('Tu sesión caducó. Actualiza la página.');
      if (response.status === 409) throw new Error('Este proceso ya no aparece en el inventario reciente.');
      if (response.status === 429) throw new Error('Se alcanzó el límite de consultas. Inténtalo dentro de un minuto.');
      throw new Error('No se pudo recuperar la información. Puedes intentarlo nuevamente.');
    }
    const value: unknown = await response.json();
    if (!value || typeof value !== 'object') throw new Error('El servidor devolvió una respuesta inválida.');
    const record = value as Record<string, unknown>;
    if (typeof record.known !== 'boolean' ||
      !['catalog', 'ai', 'none', 'unavailable'].includes(String(record.source)) ||
      (record.known && (typeof record.description !== 'string' || !record.description.trim()))) {
      throw new Error('El servidor devolvió información incompleta.');
    }
    const resolved: ProcessInformation = {
      known: record.known, source: record.source as ProcessInformation['source'],
      description: record.known ? record.description as string : null,
    };
    if (request.signal.aborted) return;
    result.value = resolved;
    emit('resolved', props.processName, resolved);
  } catch (cause) {
    if (!request.signal.aborted) error.value = cause instanceof Error ? cause.message : 'No se pudo completar la consulta.';
  } finally {
    if (!request.signal.aborted) loading.value = false;
    if (controller === request) controller = null;
  }
}

function close(): void { emit('close'); }
function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') { event.preventDefault(); close(); return; }
  if (event.key !== 'Tab' || !panel.value) return;
  const focusable = Array.from(panel.value.querySelectorAll<HTMLElement>(
    'button:not([disabled]), a[href], input:not([disabled]), [tabindex]:not([tabindex="-1"])',
  )).filter(node => node.getClientRects().length > 0);
  if (!focusable.length) { event.preventDefault(); closeButton.value?.focus(); return; }
  const first = focusable[0];
  const last = focusable[focusable.length - 1];
  if (!panel.value.contains(document.activeElement)) { event.preventDefault(); first?.focus(); return; }
  if (event.shiftKey && document.activeElement === first) {
    event.preventDefault(); last?.focus();
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault(); first?.focus();
  }
}

onMounted(async () => {
  mounted = true;
  previousFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
  previousBodyOverflow = document.body.style.overflow;
  document.body.style.overflow = 'hidden';
  document.addEventListener('keydown', onKeydown);
  await nextTick();
  if (!mounted) return;
  closeButton.value?.focus();
  void load();
});
onUnmounted(() => {
  mounted = false;
  controller?.abort();
  document.removeEventListener('keydown', onKeydown);
  document.body.style.overflow = previousBodyOverflow;
  if (previousFocus?.isConnected) previousFocus.focus({ preventScroll: true });
});
</script>

<template>
  <Teleport to="body">
    <div class="process-info-backdrop" @click.self="close">
      <section ref="panel" class="process-info-dialog" role="dialog" aria-modal="true" aria-labelledby="process-info-title" aria-describedby="process-info-subtitle">
        <header class="process-info-header">
          <div class="process-info-heading">
            <span class="process-info-icon"><BookOpenText :size="23" aria-hidden="true" /></span>
            <div>
              <p class="process-info-kicker">Información de procesos</p>
              <h2 id="process-info-title">{{ processName }}</h2>
              <p id="process-info-subtitle">Descripción técnica y consideraciones de seguridad</p>
            </div>
          </div>
          <button ref="closeButton" type="button" class="process-info-close" aria-label="Cerrar información del proceso" @click="close"><X :size="20" aria-hidden="true" /></button>
        </header>

        <div class="process-info-body" :aria-busy="loading">
          <div v-if="loading" class="process-info-state" role="status">
            <LoaderCircle :size="26" class="process-info-spin" aria-hidden="true" />
            <strong>Consultando información…</strong>
            <p>Buscando en el catálogo y, si es necesario, consultando al asistente.</p>
          </div>
          <div v-else-if="error" class="process-info-state" role="alert">
            <AlertCircle :size="28" aria-hidden="true" />
            <strong>No se pudo consultar el proceso</strong>
            <p>{{ error }}</p>
            <button class="button button-secondary" type="button" @click="load">Reintentar</button>
          </div>
          <div v-else-if="result?.known && result.description" class="process-info-content">
            <div v-if="result.source === 'ai'" class="process-info-source">
              <Sparkles :size="15" aria-hidden="true" />
              Descripción generada por IA
            </div>
            <AiFormattedText :content="result.description" />
          </div>
          <div v-else class="process-info-state" role="status">
            <AlertCircle :size="29" aria-hidden="true" />
            <strong>No hay información disponible para este proceso</strong>
            <p v-if="result?.source === 'unavailable'">No figura en el catálogo y el asistente no está habilitado o configurado.</p>
            <p v-else>El proceso no figura en el catálogo y el asistente no pudo identificarlo con suficiente confianza.</p>
          </div>
        </div>
        <footer class="process-info-footer">
          <span><ShieldCheck :size="15" aria-hidden="true" /> Un nombre de proceso no confirma la legitimidad de un ejecutable.</span>
          <button type="button" class="button button-secondary" @click="close">Cerrar</button>
        </footer>
      </section>
    </div>
  </Teleport>
</template>
