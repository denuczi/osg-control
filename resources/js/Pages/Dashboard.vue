<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { Activity, ArrowRight, Monitor, RefreshCw, Search, Server, Wifi } from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import DeviceTable from '@/Components/DeviceTable.vue';
import EmptyState from '@/Components/EmptyState.vue';
import PerformanceChart from '@/Components/PerformanceChart.vue';
import { useRemotePolling } from '@/Composables/useRemotePolling';
import { urls, dateTime, numberPercent, actionName, commandName, type Device, type Command } from '@/types/nodo';

type Stats = { total: number; online: number; demo: number; avgCpu: number | null };
type StatusResponse = { devices: Device[]; total: number; online: number; demo: number; average_cpu: number | null };
const props = defineProps<{ devices: Device[]; recentCommands: Command[]; stats: Stats; refreshSeconds: number }>();
const devices = ref<Device[]>(props.devices);
const stats = ref<Stats>(props.stats);
const search = ref('');
const filter = ref<'all' | 'online' | 'demo'>('all');
const graphDeviceId = ref(props.devices.find(d => d.cpu !== null && d.last_sampled)?.uuid ?? '');
function isStatusResponse(value: unknown): value is StatusResponse {
  if (value === null || typeof value !== 'object') return false;
  if (!('devices' in value) || !Array.isArray(value.devices) || value.devices.length > 100) return false;
  if (!('total' in value) || typeof value.total !== 'number' ||
      !('online' in value) || typeof value.online !== 'number' ||
      !('demo' in value) || typeof value.demo !== 'number') return false;
  if (!('average_cpu' in value) || (value.average_cpu !== null && typeof value.average_cpu !== 'number')) return false;
  return value.devices.every((device: unknown) => device !== null && typeof device === 'object' &&
      'uuid' in device && typeof device.uuid === 'string' &&
      'online' in device && typeof device.online === 'boolean');
}
const { error, lastUpdated, refresh, busy } = useRemotePolling<StatusResponse>(urls.status, props.refreshSeconds, data => {
  devices.value = data.devices;
  stats.value = { total: data.total, online: data.online, demo: data.demo, avgCpu: data.average_cpu };
}, isStatusResponse);
const realCount = computed(() => Math.max(0, stats.value.total - stats.value.demo));
const disconnectedCount = computed(() => Math.max(0, realCount.value - stats.value.online));
const filteredDevices = computed(() => devices.value.filter(d => {
  if (filter.value === 'online' && (d.demo || !d.online)) return false;
  if (filter.value === 'demo' && !d.demo) return false;
  const query = search.value.trim().toLocaleLowerCase();
  return !query || `${d.name} ${d.hostname} ${d.platform}`.toLocaleLowerCase().includes(query);
}));
const graphDevice = computed(() => devices.value.find(d => d.uuid === graphDeviceId.value));
const graphOptions = computed(() => devices.value.filter(d => d.cpu !== null && d.last_sampled));
</script>
<template>
  <AppLayout>
    <Head title="Resumen" />
    <div class="page-titlebar overview-titlebar">
      <div><h1>Resumen</h1><p>Disponibilidad y consumo de los equipos registrados.</p></div>
      <div class="overview-actions">
        <span class="freshness-indicator" :class="{'is-stale': error}" role="status">
          <span class="freshness-dot" aria-hidden="true"></span>
          {{ error ? 'Sin conexión con el servidor' : lastUpdated ? `Actualizado ${dateTime(lastUpdated.toISOString())}` : `Actualización cada ${refreshSeconds} s` }}
        </span>
        <button class="button button-secondary" type="button" :disabled="busy" @click="refresh"><RefreshCw :size="16" :class="{'spin': busy}" aria-hidden="true"/>Actualizar</button>
      </div>
    </div>
    <div v-if="error" class="notice notice-danger" role="alert">No se pudo obtener el estado más reciente. Los valores pueden estar desactualizados.</div>
    <section class="operational-summary operational-widgets" :class="{ 'has-four': stats.avgCpu !== null }" aria-label="Resumen del inventario">
      <div class="operational-item widget-total"><span class="operational-icon"><Monitor :size="18" aria-hidden="true"/></span><div class="operational-text"><span>Equipos registrados</span><strong>{{ stats.total }}</strong><small v-if="stats.demo">{{ stats.demo }} de demostración</small></div></div>
      <div class="operational-item widget-online" :class="{ 'has-online': stats.online > 0 }"><span class="operational-icon"><Wifi :size="18" aria-hidden="true"/></span><div class="operational-text"><span>En línea</span><strong>{{ realCount ? `${stats.online} de ${realCount}` : '—' }}</strong><small>{{ realCount ? 'Equipos reales' : 'Sin equipos reales registrados' }}</small></div></div>
      <div class="operational-item widget-offline" :class="{ 'has-disconnected': disconnectedCount > 0 }"><span class="operational-icon"><Server :size="18" aria-hidden="true"/></span><div class="operational-text"><span>Desconectados</span><strong>{{ realCount ? disconnectedCount : '—' }}</strong><small>{{ realCount ? 'Equipos reales sin señal' : 'Sin equipos reales registrados' }}</small></div></div>
      <div v-if="stats.avgCpu !== null" class="operational-item widget-cpu"><span class="operational-icon"><Activity :size="18" aria-hidden="true"/></span><div class="operational-text"><span>CPU promedio</span><strong>{{ numberPercent(stats.avgCpu) }}</strong><small>Solo equipos reales conectados</small></div></div>
    </section>
    <section class="surface-panel fleet-panel" aria-labelledby="fleet-title">
      <div class="section-head fleet-heading">
        <div><h2 id="fleet-title">Equipos</h2><p>{{ stats.total > devices.length ? `Vista previa de ${devices.length} de ${stats.total} equipos` : `${devices.length} equipos registrados` }}. Selecciona uno para consultar su estado.</p></div>
        <Link class="quiet-link" :href="urls.devices">Ver todos <ArrowRight :size="16" aria-hidden="true"/></Link>
      </div>
      <div class="fleet-controls">
        <div class="fleet-filters" role="group" aria-label="Filtrar equipos">
          <button type="button" :class="{'selected': filter === 'all'}" :aria-pressed="filter === 'all'" @click="filter='all'">Todos</button>
          <button type="button" :class="{'selected': filter === 'online'}" :aria-pressed="filter === 'online'" @click="filter='online'">En línea</button>
          <button v-if="stats.demo" type="button" :class="{'selected': filter === 'demo'}" :aria-pressed="filter === 'demo'" @click="filter='demo'">Demostración</button>
        </div>
        <label class="search-field fleet-search"><Search :size="16" aria-hidden="true"/><input v-model="search" type="search" placeholder="Buscar equipo" aria-label="Buscar equipo" /></label>
      </div>
      <DeviceTable v-if="filteredDevices.length" :devices="filteredDevices.slice(0, 10)" />
      <EmptyState v-else-if="!devices.length" title="No hay equipos registrados" description="Registra un agente Windows para comenzar el monitoreo." />
      <div v-else class="inline-empty">No hay equipos que coincidan con el filtro.</div>
      <div v-if="filteredDevices.length > 10" class="panel-footer"><Link class="quiet-link" :href="urls.devices">Ver listado completo <ArrowRight :size="15"/></Link></div>
    </section>
    <div class="overview-lower">
      <section v-if="graphOptions.length" class="surface-panel history-panel" aria-labelledby="history-title">
        <div class="section-head history-heading">
          <div><h2 id="history-title">Historial de rendimiento</h2><p>CPU y memoria durante la última hora</p></div>
          <label class="history-select-wrap"><span class="sr-only">Equipo del gráfico</span><select v-model="graphDeviceId" class="history-device-select"><option v-for="d in graphOptions" :key="d.uuid" :value="d.uuid">{{ d.name }}</option></select></label>
        </div>
        <p v-if="graphDevice?.demo" class="demo-inline-note">Muestras de demostración; no representan un equipo conectado.</p>
        <PerformanceChart v-if="graphDeviceId" :key="graphDeviceId" :device-id="graphDeviceId" :hours="1" :refresh-seconds="refreshSeconds" :live="graphDevice?.online && graphDevice.last_sampled ? { time: graphDevice.last_sampled, cpu: graphDevice.cpu, memory: graphDevice.memory } : null" />
      </section>
      <section v-if="recentCommands.length" class="surface-panel history-commands" aria-labelledby="commands-title">
        <div class="section-head"><div><h2 id="commands-title">Órdenes recientes</h2><p>Últimas operaciones administrativas</p></div><Link :href="urls.activity" class="quiet-link">Actividad <ArrowRight :size="15"/></Link></div>
        <div class="activity-list"><div v-for="command in recentCommands" :key="command.uuid" class="activity-row"><span class="activity-icon"><Activity :size="16" aria-hidden="true"/></span><div class="activity-info"><strong>{{ actionName(command.action) }}</strong><span>{{ command.device ?? 'Equipo no disponible' }} · {{ dateTime(command.created_at) }}</span></div><span class="command-status" :class="`status-${command.status}`">{{ commandName(command.status) }}</span></div></div>
      </section>
    </div>
  </AppLayout>
</template>
