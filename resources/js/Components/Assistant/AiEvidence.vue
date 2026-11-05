<script setup lang="ts">
import { computed, ref } from 'vue';
import { ChevronDown, ChevronUp, Monitor, Activity, Database, Wifi, ShieldAlert } from '@lucide/vue';
import { dateTime } from '@/types/nodo';
import type { AssistantEvidence } from '@/types/assistant';
const props = defineProps<{ evidence: AssistantEvidence }>();
const expanded = ref(true);
const deviceItems = computed(() => props.evidence.data.devices ?? []);
const processes = computed(() => props.evidence.data.top_processes ?? []);
const fleetProcesses = computed(() => props.evidence.data.fleet_top_processes ?? []);
const compatibilityDevices = computed(() => props.evidence.data.compatibility_devices ?? []);
const requirements = computed(() => props.evidence.data.requirements ?? null);
const networkDevices = computed(() => props.evidence.data.network_devices ?? []);
const shutdownDevices = computed(() => props.evidence.data.shutdown_devices ?? []);
const usbEvents = computed(() => props.evidence.data.usb_events ?? []);
const usbTarget = computed(() => props.evidence.data.target_device ?? null);
function bytes(value: number | null | undefined): string {
  if (value == null) return 'Sin mediciones';
  const unit = value >= 1024 ** 3 ? 'GiB' : value >= 1024 ** 2 ? 'MiB' : 'KiB';
  const divisor = unit === 'GiB' ? 1024 ** 3 : unit === 'MiB' ? 1024 ** 2 : 1024;
  return `${(value / divisor).toLocaleString('es-AR', { maximumFractionDigits: 2 })} ${unit}`;
}
function checkText(status: string): string {
  return ({ pass: 'Cumple dato conocido', fail: 'No cumple', unknown: 'Sin verificar' } as Record<string, string>)[status] ?? 'Sin verificar';
}
function value(value: number | null | undefined, suffix = ''): string {
  return value == null ? 'Sin información' : `${value.toLocaleString('es-AR', {maximumFractionDigits: 1})}${suffix}`;
}
</script>
<template>
  <section class="ai-evidence" :aria-label="evidence.title">
    <button type="button" class="ai-evidence-toggle" :aria-expanded="expanded" @click="expanded = !expanded">
      <Monitor v-if="evidence.kind === 'devices' || evidence.kind === 'processors'" :size="16" aria-hidden="true" />
      <Activity v-else-if="evidence.kind === 'processes' || evidence.kind === 'fleet_processes'" :size="16" aria-hidden="true" />
      <Wifi v-else-if="evidence.kind === 'network'" :size="16" aria-hidden="true" />
      <ShieldAlert v-else-if="evidence.kind === 'shutdowns' || evidence.kind === 'usb_connections'" :size="16" aria-hidden="true" />
      <Database v-else :size="16" aria-hidden="true" />
      <span>{{ evidence.title }}</span>
      <ChevronUp v-if="expanded" :size="16" aria-hidden="true" />
      <ChevronDown v-else :size="16" aria-hidden="true" />
    </button>
    <div v-if="expanded" class="ai-evidence-body">
      <div v-if="evidence.kind === 'summary'" class="ai-mini-stats">
        <div><span>Registrados</span><strong>{{ value(evidence.data.registered) }}</strong></div>
        <div><span>Conectados</span><strong>{{ value(typeof evidence.data.online === 'number' ? evidence.data.online : null) }}</strong></div>
        <div><span>RAM promedio</span><strong>{{ value(evidence.data.average_ram_gib, ' GiB') }}</strong></div>
      </div>
      <template v-else-if="evidence.kind === 'network'">
        <p class="ai-note">Período: {{ evidence.data.from }} a {{ evidence.data.to }}<span v-if="evidence.data.aggregation_timezone"> (días agrupados según {{ evidence.data.aggregation_timezone }})</span>. Equipos con datos: {{ evidence.data.measured_devices ?? 0 }} de {{ evidence.data.registered_devices ?? 0 }}.</p>
        <div class="ai-mini-stats">
          <div><span>Recibidos</span><strong>{{ bytes(evidence.data.rx_bytes) }}</strong></div>
          <div><span>Enviados</span><strong>{{ bytes(evidence.data.tx_bytes) }}</strong></div>
          <div><span>Tráfico total registrado</span><strong>{{ bytes(evidence.data.total_bytes) }}</strong></div>
        </div>
        <p v-if="networkDevices.length === 0" class="ai-note">No hay capturas de tráfico para este período. No equivale a consumo cero.</p>
        <div v-else class="ai-table-scroll" role="region" aria-label="Tabla de resultados" tabindex="0"><table class="ai-table">
          <thead><tr><th>Equipo</th><th>Recibidos</th><th>Enviados</th><th>Total</th></tr></thead>
          <tbody><tr v-for="device in networkDevices" :key="device.uuid">
            <td><a :href="`/equipos/${encodeURIComponent(device.uuid)}`">{{ device.name }}</a></td>
            <td>{{ bytes(device.rx_bytes) }}</td><td>{{ bytes(device.tx_bytes) }}</td><td>{{ bytes(device.total_bytes) }}</td>
          </tr></tbody>
        </table></div>
        <p v-if="evidence.data.truncated" class="ai-note">Se muestran los equipos con mayor tráfico. Los totales incluyen todas las mediciones del período.</p>
      </template>
      <template v-else-if="evidence.kind === 'shutdowns'">
        <p class="ai-note">Se revisaron los eventos conservados del período indicado. Equipos con indicios: {{ evidence.data.devices_with_signals ?? 0 }}.</p>
        <p v-if="shutdownDevices.length === 0" class="ai-note">No se encontraron indicios en los eventos disponibles. Esto no confirma que no hayan ocurrido incidentes.</p>
        <div v-else class="ai-table-scroll" role="region" aria-label="Tabla de resultados" tabindex="0"><table class="ai-table">
          <thead><tr><th>Equipo</th><th>Posibles incidentes</th><th>Registros 41/6008</th><th>Último indicio</th></tr></thead>
          <tbody><tr v-for="device in shutdownDevices" :key="device.uuid">
            <td><a :href="`/equipos/${encodeURIComponent(device.uuid)}`">{{ device.name }}</a></td>
            <td>{{ device.incidents }}</td><td>{{ device.event_records }}</td><td>{{ dateTime(device.latest_signal_at) }}</td>
          </tr></tbody>
        </table></div>
        <p v-if="evidence.data.truncated" class="ai-note">El análisis contiene solo los eventos más recientes; hay más registros fuera del límite de consulta.</p>
      </template>
      <template v-else-if="evidence.kind === 'usb_connections'">
        <p v-if="usbTarget" class="ai-note">
          Equipo consultado:
          <a :href="`/equipos/${encodeURIComponent(usbTarget?.uuid ?? '')}`">{{ usbTarget?.name }}</a>
          <span v-if="usbTarget?.hostname && usbTarget.hostname !== usbTarget.name"> ({{ usbTarget.hostname }})</span>.
          El equipo está registrado aunque esté desconectado.
        </p>
        <p class="ai-note">Período: {{ evidence.data.period_label ?? 'últimos 7 días' }}. Conexiones registradas: {{ evidence.data.connection_events ?? 0 }}{{ usbTarget ? '' : ` · Equipos con conexiones: ${evidence.data.devices_with_connections ?? 0}` }}.</p>
        <p v-if="usbEvents.length === 0" class="ai-note">No se encontraron conexiones USB registradas {{ usbTarget ? 'para este equipo' : 'en los equipos consultados' }} durante el período. No demuestra que nunca se haya conectado un dispositivo.</p>
        <div v-else class="ai-table-scroll" role="region" aria-label="Tabla de resultados" tabindex="0"><table class="ai-table">
          <thead><tr><th>Equipo</th><th>Unidad USB</th><th>Letra o punto de montaje</th><th>Conectada el</th></tr></thead>
          <tbody><tr v-for="event in usbEvents" :key="event.event_uuid">
            <td><a :href="`/equipos/${encodeURIComponent(event.device_uuid)}`">{{ event.device_name }}</a></td>
            <td>{{ event.label || 'Sin etiqueta' }}</td>
            <td>{{ event.mountpoint || 'No informado' }}</td>
            <td>{{ dateTime(event.observed_at) }}</td>
          </tr></tbody>
        </table></div>
        <p v-if="evidence.data.truncated" class="ai-note">Se muestran solo las conexiones más recientes del período.</p>
      </template>
      <template v-else-if="evidence.kind === 'devices' || evidence.kind === 'processors'">
        <p v-if="evidence.data.truncated" class="ai-note">Mostrando {{ evidence.data.returned }} de {{ evidence.data.total_matching }} equipos. No es un listado completo.</p>
        <p v-if="deviceItems.length === 0" class="ai-note">No se encontraron equipos para este filtro. Esto no significa que el inventario general esté vacío.</p>
        <div v-else class="ai-table-scroll" role="region" aria-label="Tabla de resultados" tabindex="0">
          <table class="ai-table"><thead><tr><th>Equipo</th><th>Estado</th><th>RAM</th><th>{{ evidence.kind === 'processors' ? 'Núcleos' : 'Unidad principal' }}</th></tr></thead>
            <tbody><tr v-for="device in deviceItems" :key="device.uuid">
              <td><a :href="`/equipos/${encodeURIComponent(device.uuid)}`">{{ device.name }}</a><small v-if="device.hostname && device.hostname.trim().toLocaleLowerCase() !== device.name.trim().toLocaleLowerCase()">{{ device.hostname }}</small></td>
              <td>{{ device.demo ? 'Demostración' : device.online ? 'Conectado' : 'Sin conexión' }}</td>
              <td>{{ value(device.ram_gib, ' GiB') }}</td>
              <td v-if="evidence.kind === 'processors'">{{ value(device.physical_cores) }}<small>{{ device.cpu_name ?? 'Procesador no informado' }}</small></td>
              <td v-else>{{ value(device.primary_disk_used_percent, ' %') }}<small>{{ device.metrics_at ? `Medición: ${dateTime(device.metrics_at)}` : 'Sin medición' }}</small></td>
            </tr></tbody>
          </table>
        </div>
      </template>
      <template v-else-if="evidence.kind === 'compatibility'">
        <p class="ai-note">Comparación orientativa de requisitos mínimos por edición. La desconexión no impide consultar el último inventario.</p>
        <p v-if="compatibilityDevices.length === 0" class="ai-note">No hay equipos registrados para evaluar.</p>
        <div v-else class="ai-table-scroll" role="region" aria-label="Tabla de resultados" tabindex="0">
          <table class="ai-table ai-compat-table">
            <thead><tr><th>Equipo</th><th>RAM instalada</th><th>Enhanced</th><th>Legacy</th></tr></thead>
            <tbody><tr v-for="device in compatibilityDevices" :key="device.uuid">
              <td><a :href="`/equipos/${encodeURIComponent(device.uuid)}`">{{ device.name }}</a>
                <small>{{ device.online ? 'Conectado' : 'Sin conexión' }}</small>
                <small>Inventario: {{ dateTime(device.captured_at ?? device.metrics_at) }}</small>
              </td>
              <td>{{ value(device.ram_gib, ' GiB') }}</td>
              <td v-for="edition in [device.editions.enhanced, device.editions.legacy]" :key="edition === device.editions.enhanced ? 'enhanced' : 'legacy'">
                <strong :class="edition.status === 'insufficient' ? 'ai-compat-fail' : 'ai-compat-unknown'">
                  {{ edition.status === 'insufficient' ? 'Requisito incumplido' : 'No verificable' }}
                </strong>
                <small>RAM: {{ checkText(edition.ram) }}</small>
                <small>Espacio y tipo de unidad: {{ checkText(edition.storage) }}</small>
                <small>CPU y gráficos: sin verificar</small>
              </td>
            </tr></tbody>
          </table>
        </div>
        <div v-if="requirements" class="ai-compat-requirements">
          <p><strong>Enhanced:</strong> {{ requirements.enhanced.ram_gb }} GB RAM, {{ requirements.enhanced.free_gb }} GB libres en SSD; verificar CPU y GPU.</p>
          <p><strong>Legacy:</strong> {{ requirements.legacy.ram_gb }} GB RAM, {{ requirements.legacy.free_gb }} GB libres; verificar CPU y GPU.</p>
        </div>
        <a v-if="evidence.data.source_url?.startsWith('https://support.rockstargames.com/')"
          :href="evidence.data.source_url" rel="noopener noreferrer" target="_blank" class="ai-official-source">Ver requisitos oficiales de Rockstar Games</a>
      </template>
      <template v-else-if="evidence.kind === 'processes'">
        <p class="ai-note">Captura: {{ dateTime(evidence.data.captured_at) }} · {{ evidence.data.snapshot_is_recent ? 'Reciente' : 'Puede estar desactualizada' }}. Muestra parcial, no recuento total.</p>
        <p v-if="processes.length === 0" class="ai-note">Este equipo no tiene una captura de procesos disponible.</p>
        <div v-else class="ai-table-scroll" role="region" aria-label="Tabla de resultados" tabindex="0"><table class="ai-table"><thead><tr><th>Proceso</th><th>PID</th><th>Memoria residente</th></tr></thead>
          <tbody><tr v-for="(process, i) in processes" :key="`${process.pid ?? 'unknown'}-${i}`"><td>{{ process.name }}</td><td>{{ process.pid ?? '—' }}</td><td>{{ value(process.memory_mb, ' MB') }}</td></tr></tbody>
        </table></div>
      </template>
      <template v-else-if="evidence.kind === 'fleet_processes'">
        <p class="ai-note">Últimas capturas de {{ evidence.data.devices_with_samples ?? 0 }} equipos. {{ evidence.data.online_only ? 'Solo equipos conectados.' : 'También se incluyen equipos desconectados.' }} Los procesos podrían no seguir activos.</p>
        <p v-if="fleetProcesses.length === 0" class="ai-note">No hay procesos registrados para comparar.</p>
        <div v-else class="ai-table-scroll" role="region" aria-label="Tabla de resultados" tabindex="0"><table class="ai-table">
          <thead><tr><th>Equipo</th><th>Proceso</th><th>PID</th><th>Memoria residente</th><th>Captura</th></tr></thead>
          <tbody><tr v-for="(process, i) in fleetProcesses" :key="`${process.device_uuid}-${process.pid ?? 'unknown'}-${i}`">
            <td><a :href="`/equipos/${encodeURIComponent(process.device_uuid)}`">{{ process.device_name }}</a><small>{{ process.online ? 'Conectado' : 'Sin conexión' }}</small></td>
            <td>{{ process.name }}</td><td>{{ process.pid ?? '—' }}</td>
            <td>{{ value(process.memory_mb, ' MB') }}</td><td>{{ process.captured_at ? dateTime(process.captured_at) : 'Sin fecha' }}</td>
          </tr></tbody>
        </table></div>
      </template>
      <p v-if="evidence.data.notes || evidence.data.criteria" class="ai-evidence-footnote">{{ evidence.data.notes ?? evidence.data.criteria }}</p>
    </div>
  </section>
</template>
