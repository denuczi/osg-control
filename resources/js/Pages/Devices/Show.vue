<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Edit3, RefreshCcw, Power, X, Info, AlertTriangle } from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import MetricBar from '@/Components/MetricBar.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import PerformanceChart from '@/Components/PerformanceChart.vue';
import DeviceProcesses from '@/Components/DeviceProcesses.vue';
import InfrastructureDetails from '@/Components/InfrastructureDetails.vue';
import MediaHistory from '@/Components/MediaHistory.vue';
import RecentDeviceCommands from '@/Components/RecentDeviceCommands.vue';
import NetworkUsagePanel from '@/Components/NetworkUsagePanel.vue';
import GenerateReportButton from '@/Components/Reports/GenerateReportButton.vue';
import { useRemotePolling } from '@/Composables/useRemotePolling';
import { urls, type Device, type Command, type Process, type DeviceAction, numberPercent, dateTime, actionName } from '@/types/nodo';

const props = defineProps<{ device: Device; commands: Command[]; refreshSeconds: number }>();
const device = ref<Device>({ ...props.device });
const { error, lastUpdated } = useRemotePolling<Device>(
  computed(() => urls.deviceLightStatus(props.device.uuid)),
  props.refreshSeconds,
  data => { device.value = { ...device.value, ...data }; },
);
watch(() => props.device, next => {
  if (next.uuid !== device.value.uuid) device.value = { ...next };
  else device.value.name = next.name;
});
const live = computed(() => device.value.online && device.value.last_sampled
  ? { time: device.value.last_sampled, cpu: device.value.cpu, memory: device.value.memory }
  : null);
const hours = ref(1);
const possible = computed(() => device.value.online && !device.value.demo);
function metricTone(value: number | null | undefined): 'healthy' | 'warning' | 'critical' | 'unknown' {
  if (value == null) return 'unknown';
  if (value >= 90) return 'critical';
  if (value >= 75) return 'warning';
  return 'healthy';
}
const renameOpen = ref(false);
const actionOpen = ref(false);
const renameForm = useForm({ name: props.device.name });
const commandForm = useForm<{
  action: DeviceAction | '';
  pid: number | null;
  process_name: string | null;
  process_started_at: number | null;
  message: string;
  confirm: boolean;
}>({ action: '', pid: null, process_name: null, process_started_at: null, message: '', confirm: true });

function openRename() {
  renameForm.reset();
  renameForm.name = device.value.name;
  renameForm.clearErrors();
  renameOpen.value = true;
}
function closeRename() {
  if (renameForm.processing) return;
  renameForm.reset();
  renameForm.clearErrors();
  renameOpen.value = false;
}
function saveName() {
  renameForm.patch(urls.device(device.value.uuid), {
    preserveScroll: true,
    onSuccess: () => {
      device.value.name = renameForm.name;
      renameOpen.value = false;
    },
  });
}
function openAction(action: DeviceAction, process?: Process) {
  commandForm.reset();
  commandForm.clearErrors();
  commandForm.action = action;
  commandForm.pid = process?.pid ?? null;
  commandForm.process_name = process?.name ?? null;
  commandForm.process_started_at = process?.started_at ?? null;
  actionOpen.value = true;
}
function closeAction() {
  if (commandForm.processing) return;
  actionOpen.value = false;
  commandForm.reset();
  commandForm.clearErrors();
}
function sendCommand() {
  if (commandForm.processing) return;
  commandForm.post(`${urls.device(device.value.uuid)}/ordenes`, {
    preserveScroll: true,
    onSuccess: () => {
      actionOpen.value = false;
      commandForm.reset();
      commandForm.clearErrors();
    },
  });
}
</script>

<template>
  <AppLayout>
    <Head :title="device.name" />
    <div class="detail-back"><Link :href="urls.devices"><ArrowLeft :size="15" /> Equipos</Link></div>
    <div class="page-titlebar device-title device-hero">
      <div class="device-title-copy">
        <div class="device-title-line"><h1>{{ device.name }}</h1><StatusBadge :online="device.online" :demo="device.demo" /></div>
        <p>{{ device.hostname }} · {{ device.platform }} · Último contacto: {{ dateTime(device.last_seen) }}</p>
      </div>
      <div class="device-title-actions device-toolbar">
        <div class="device-toolbar-group device-toolbar-group--primary">
          <GenerateReportButton :device-uuid="device.uuid" />
        </div>
        <div class="device-toolbar-group device-toolbar-group--secondary">
          <Link class="button button-secondary" :href="urls.deviceManagement">Administrar acceso</Link>
          <Link class="button button-secondary" :href="`${urls.assistant}?device=${encodeURIComponent(device.uuid)}`">Consultar IA</Link>
          <Link class="button button-secondary" :href="urls.deviceDiagnostics(device.uuid)">Salud y seguridad</Link>
          <button type="button" class="button button-secondary" @click="openRename"><Edit3 :size="16" /> Editar nombre</button>
        </div>
      </div>
    </div>
    <div v-if="device.demo" class="notice notice-info"><Info :size="17" /> Equipo de demostración: las acciones remotas están deshabilitadas.</div>
    <div v-if="error" class="notice notice-danger" role="alert"><AlertTriangle :size="17" /> No se pudo actualizar el estado. Los valores pueden estar desactualizados.</div>

    <div class="device-kpis" aria-label="Últimas métricas del equipo">
      <div class="device-kpi" :class="`device-kpi--${metricTone(device.cpu)}`"><MetricBar label="CPU" :value="device.cpu" /><div class="device-kpi-value">{{ numberPercent(device.cpu) }}</div></div>
      <div class="device-kpi" :class="`device-kpi--${metricTone(device.memory)}`"><MetricBar label="Memoria RAM" :value="device.memory" /><div class="device-kpi-value">{{ numberPercent(device.memory) }}</div></div>
      <div class="device-kpi" :class="`device-kpi--${metricTone(device.disk)}`"><MetricBar label="Disco" :value="device.disk" /><div class="device-kpi-value">{{ numberPercent(device.disk) }}</div></div>
    </div>

    <div class="detail-grid detail-monitoring-grid">
      <div class="detail-primary">
        <section class="surface-panel" aria-labelledby="performance-title">
          <div class="section-head performance-heading">
            <div><h2 id="performance-title">Rendimiento</h2><p>CPU y memoria RAM · mediciones reportadas por el agente</p></div>
            <div class="segmented" role="group" aria-label="Período de rendimiento">
              <button v-for="period in [1, 6, 24, 48, 72, 168]" :key="period" type="button"
                :aria-pressed="hours === period" :class="{ selected: hours === period }"
                @click="hours = period">{{ period === 72 ? '3 días' : period === 168 ? '7 días' : `${period} h` }}</button>
            </div>
          </div>
          <PerformanceChart :device-id="device.uuid" :refresh-seconds="refreshSeconds" :hours="hours" :live="live" />
        </section>
        <DeviceProcesses :key="device.uuid" :device-uuid="device.uuid" :processes="device.processes ?? []" :total-count="device.processes_total_count ?? null" :captured-at="device.processes_sampled_at ?? null"
          :online="device.online" :can-command="possible" @terminate="process => openAction('terminate_process', process)" />
      </div>

      <aside class="detail-aside" aria-label="Administración del equipo">
        <section class="surface-panel">
          <div class="section-head"><div><h2>Administración remota</h2><p>Acciones sobre el equipo</p></div></div>
          <div class="command-buttons">
            <button type="button" class="command-button" :disabled="!possible" @click="openAction('show_message')"><Info :size="18" /><span>Enviar mensaje en pantalla</span></button>
            <button type="button" class="command-button" :disabled="!possible" @click="openAction('restart')"><RefreshCcw :size="18" /><span>Reiniciar equipo</span></button>
            <button type="button" class="command-button destructive" :disabled="!possible" @click="openAction('shutdown')"><Power :size="18" /><span>Apagar equipo</span></button>
          </div>
        </section>
        <section class="surface-panel">
          <div class="section-head"><div><h2>Información</h2><p>Identidad y último estado</p></div></div>
          <dl class="facts-list">
            <div><dt>Identificador</dt><dd class="mono fact-uuid">{{ device.uuid }}</dd></div>
            <div><dt>Nombre del equipo</dt><dd>{{ device.hostname }}</dd></div>
            <div><dt>Sistema</dt><dd>{{ device.platform }}</dd></div>
            <div><dt>RAM instalada</dt><dd>{{ device.memory_total_bytes ? `${(device.memory_total_bytes / 1073741824).toFixed(1)} GiB` : 'Sin datos' }}</dd></div>
            <div><dt>Último contacto</dt><dd>{{ dateTime(device.last_seen) }}</dd></div>
            <div><dt>Última medición</dt><dd>{{ dateTime(device.last_sampled) }}</dd></div>
          </dl>
        </section>

      </aside>
    </div>

    <NetworkUsagePanel :key="device.uuid" :device-id="device.uuid" initial-period="today" />
    <div class="device-detail-section-heading"><h2>Infraestructura del equipo</h2><p>Hardware, almacenamiento y conectividad</p></div>
    <InfrastructureDetails :device="device" :hours="hours" />
    <RecentDeviceCommands :key="device.uuid" :commands="commands" />
    <MediaHistory :device-id="device.uuid" />
    <div class="page-footnote">{{ lastUpdated ? `Estado consultado: ${dateTime(lastUpdated.toISOString())}` : `Consulta automática cada ${refreshSeconds} segundos` }}</div>

    <div v-if="renameOpen" class="modal-overlay" @click.self="closeRename">
      <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="rename-title">
        <div class="dialog-header"><h2 id="rename-title">Editar nombre</h2><button class="icon-button" type="button" :disabled="renameForm.processing" aria-label="Cerrar" @click="closeRename"><X :size="20" /></button></div>
        <form @submit.prevent="saveName">
          <div class="dialog-body">
            <label class="form-field"><span>Nombre descriptivo</span><input v-model="renameForm.name" maxlength="100" required autofocus /></label>
            <p v-if="renameForm.errors.name" class="field-error">{{ renameForm.errors.name }}</p>
            <p class="muted">El identificador y el nombre de Windows no cambiarán.</p>
          </div>
          <div class="dialog-actions"><button type="button" class="button button-secondary" :disabled="renameForm.processing" @click="closeRename">Cancelar</button><button type="submit" class="button button-primary" :disabled="renameForm.processing">Guardar</button></div>
        </form>
      </div>
    </div>
    <div v-if="actionOpen" class="modal-overlay" @click.self="closeAction">
      <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="command-title">
        <div class="dialog-header"><h2 id="command-title">{{ actionName(commandForm.action) }}</h2><button class="icon-button" type="button" :disabled="commandForm.processing" aria-label="Cerrar" @click="closeAction"><X :size="20" /></button></div>
        <div class="dialog-body">
          <p>Se enviará una orden a <strong>{{ device.name }}</strong>.</p>
          <p v-if="commandForm.action === 'terminate_process'">Proceso: <strong>{{ commandForm.process_name }}</strong> (PID {{ commandForm.pid }}).</p>
          <label v-if="commandForm.action === 'show_message'" class="form-field"><span>Texto del mensaje</span><textarea v-model="commandForm.message" rows="4" maxlength="500" required placeholder="Escribe el mensaje que verá el usuario" /></label>
          <p v-if="commandForm.errors.message" class="field-error">{{ commandForm.errors.message }}</p>
          <p v-if="commandForm.action === 'terminate_process' || commandForm.action === 'shutdown' || commandForm.action === 'restart'" class="muted">Esta acción puede interrumpir trabajos sin guardar. Windows puede rechazar procesos protegidos.</p>
          <p v-else class="muted">El mensaje se mostrará en Windows y quedará registrado en el historial de administración.</p>
          <p v-if="commandForm.errors.action" class="field-error">{{ commandForm.errors.action }}</p>
          <p v-if="commandForm.errors.confirm" class="field-error">{{ commandForm.errors.confirm }}</p>
        </div>
        <div class="dialog-actions"><button type="button" class="button button-secondary" :disabled="commandForm.processing" @click="closeAction">Cancelar</button><button type="button" class="button button-primary" :disabled="commandForm.processing || (commandForm.action === 'show_message' && !commandForm.message.trim())" @click="sendCommand">{{ commandForm.processing ? 'Enviando…' : 'Enviar orden' }}</button></div>
      </div>
    </div>
  </AppLayout>
</template>
