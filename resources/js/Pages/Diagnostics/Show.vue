<script setup lang="ts">
import { computed, reactive } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, Shield, HardDrive, Laptop, Activity } from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import GenerateReportButton from '@/Components/Reports/GenerateReportButton.vue';
import { dateTime, urls, type Paginated } from '@/types/nodo';

type Section<T> = { status: 'ok' | 'unavailable' | 'unsupported' | 'error'; data: T | null };
type MemoryModule = { CapacityBytes: number | null; SpeedMHz: number | null; Manufacturer: string | null; PartNumber: string | null; Slot: string | null };
type Hardware = { Manufacturer: string | null; Model: string | null; BiosVersion: string | null; Board: string | null;
  Serial: string | null; MemorySlots: number | null; MemoryModules: MemoryModule[] | null;
  BatteryPresent: boolean | null; BatteryPercent: number | null; BatteryStatus: string | null;
  BatteryDesignCapacity: number | null; BatteryFullChargeCapacity: number | null; BatteryCycles: number | null };
type Disk = { Name: string | null; DeviceId: string | null; MediaType: string | null; Health: string | null;
  Temperature: number | null; Wear: number | null; PowerOnHours: number | null;
  ReadErrors: number | null; WriteErrors: number | null };
type Security = { DefenderMode: string | null; DefenderRealtime: boolean | null;
  DefenderSignatureAge: number | null; DefenderSignatureUpdated: string | null;
  FirewallProfiles: { Name: string; Enabled: boolean }[] | null; AntivirusProviders: string[] | null;
  WindowsUpdatesPending: number | null; ProtectionQueryStatus?: 'ok' | 'unavailable' | 'unknown'; Windows?: { Caption: string | null; EditionId: string | null; DisplayVersion: string | null; Build: number | null; UBR: number | null; EsuCommercialStatus: string; EsuLicensedYears: number[] } | null };
type Event = { uuid: string; event_id: number; provider: string; channel: string; observed_at: string | null; level: number | null; message: string | null; user_sid: string | null };
const props = defineProps<{
  device: { uuid: string; name: string; hostname: string; platform: string };
  diagnostic: { captured_at: string | null; hardware: Section<Hardware>; disks: Section<{ drives: Disk[] }>;
    security: Section<Security> } | null;
  events: Paginated<Event>;
  eventFilters: { event_q: string; event_id: string; event_channel: string; event_level: string };
  windows_support?: { family: string; release: string | null; build: number | null; ubr: number | null;
    policy: string; support_end: string | null; summary: string; commercial_esu_summary: string } | null;
}>();
const filters = reactive({
  event_q: props.eventFilters.event_q ?? '',
  event_id: props.eventFilters.event_id ?? '',
  event_channel: props.eventFilters.event_channel ?? '',
  event_level: props.eventFilters.event_level ?? '',
});
function applyEventFilters() {
  router.get(urls.deviceDiagnostics(props.device.uuid), {
    event_q: filters.event_q.trim() || undefined,
    event_id: filters.event_id || undefined,
    event_channel: filters.event_channel || undefined,
    event_level: filters.event_level || undefined,
  }, { preserveState: true, preserveScroll: true, replace: true });
}
function clearEventFilters() {
  filters.event_q = ''; filters.event_id = ''; filters.event_channel = ''; filters.event_level = '';
  applyEventFilters();
}
const hardware = computed(() => props.diagnostic?.hardware?.status === 'ok' ? props.diagnostic.hardware.data : null);
const disks = computed(() => props.diagnostic?.disks?.status === 'ok' ? (props.diagnostic.disks.data?.drives ?? []) : []);
const security = computed(() => props.diagnostic?.security?.status === 'ok' ? props.diagnostic.security.data : null);
const statusName = (state: string | undefined) => ({ ok: 'Consultado', unavailable: 'Sin datos', unsupported: 'No compatible', error: 'Error de lectura' } as Record<string, string>)[state ?? ''] ?? 'Pendiente';
const asText = (v: string | number | null | undefined) => v === null || v === undefined || v === '' ? 'No disponible' : String(v);
const gib = (v: number | null | undefined) => v === null || v === undefined ? 'No disponible' : `${(v/1073741824).toLocaleString('es-AR', { maximumFractionDigits: 1 })} GiB`;
// Windows event IDs have meaning only with their log and source provider.
const eventDescriptions: Record<string, string> = {
  'System|microsoft-windows-kernel-power|41': 'Apagado o reinicio no limpio',
  'System|kernel-power|41': 'Apagado o reinicio no limpio',
  'System|eventlog|6008': 'Cierre inesperado',
  'System|user32|1074': 'Reinicio solicitado',
  'System|disk|7': 'Error de disco',
  'System|disk|51': 'Error de E/S',
  'System|ntfs|55': 'Error del sistema de archivos',
  'System|microsoft-windows-ntfs|55': 'Error del sistema de archivos',
  'System|disk|153': 'Reintento de E/S de disco',
  'System|service control manager|7045': 'Servicio instalado',
  'Application|application error|1000': 'Fallo de aplicación',
  'Application|application hang|1002': 'Aplicación sin respuesta',
};
const eventName = (event: Event): string => {
  const provider = event.provider?.toLowerCase() ?? '';
  const key = `${event.channel}|${provider}|${event.event_id}`;
  if (event.channel === 'System' && event.event_id === 129 &&
      ['storahci', 'stornvme', 'iastora', 'iastorac', 'storport'].includes(provider)) {
    return 'Reinicio de controlador de almacenamiento';
  }
  return eventDescriptions[key] ?? `Evento ${event.event_id} (${event.provider || 'origen desconocido'})`;
};
const latestAge = computed(() => {
  const time = props.diagnostic?.captured_at;
  return time ? Date.now() - Date.parse(time) > 24 * 3600 * 1000 : false;
});
</script>
<template>
  <AppLayout>
    <Head :title="`Salud y seguridad · ${device.name}`" />
    <div class="detail-back"><Link :href="urls.device(device.uuid)"><ArrowLeft :size="15" /> Volver al equipo</Link></div>
    <div class="page-titlebar"><div><h1>Salud y seguridad</h1><p>{{ device.name }} · {{ device.hostname }} · {{ device.platform }}</p></div><GenerateReportButton :device-uuid="device.uuid" /></div>
    <p class="page-footnote">Datos consultados: {{ dateTime(diagnostic?.captured_at) }} · Recolección de diagnóstico cada 6 horas.</p>
    <div v-if="!diagnostic" class="notice notice-info">Todavía no hay diagnóstico. Actualiza OSG Agent a la versión 6.0 y comprueba su conexión con el servidor.</div>
    <div v-else-if="latestAge" class="notice notice-info">La última captura tiene más de 24 horas; verificá la conexión del equipo antes de interpretar estos resultados.</div>
    <div class="infra-stack">
      <section class="surface-panel" aria-labelledby="diag-hardware">
        <div class="section-head"><div><h2 id="diag-hardware"><Laptop :size="17"/> Equipo y memoria</h2><p>Información SMBIOS y batería, cuando el fabricante la comunica</p></div><span class="muted">{{ statusName(diagnostic?.hardware?.status) }}</span></div>
        <div v-if="hardware" class="infra-facts">
          <div class="infra-fact"><span>Fabricante</span><strong>{{ asText(hardware.Manufacturer) }}</strong></div>
          <div class="infra-fact"><span>Modelo</span><strong>{{ asText(hardware.Model) }}</strong></div>
          <div class="infra-fact"><span>Placa madre</span><strong>{{ asText(hardware.Board) }}</strong></div>
          <div class="infra-fact"><span>BIOS</span><strong>{{ asText(hardware.BiosVersion) }}</strong></div>
          <div class="infra-fact"><span>Serie</span><strong>{{ asText(hardware.Serial) }}</strong></div>
          <div class="infra-fact"><span>Ranuras RAM declaradas</span><strong>{{ asText(hardware.MemorySlots) }}</strong></div>
          <div class="infra-fact"><span>Batería</span><strong>{{ hardware.BatteryPresent === true ? `${asText(hardware.BatteryPercent)} %` : hardware.BatteryPresent === false ? 'No detectada' : 'No disponible' }}</strong></div>
          <div v-if="hardware.BatteryPresent" class="infra-fact"><span>Salud estimada de batería</span><strong>{{ hardware.BatteryDesignCapacity && hardware.BatteryFullChargeCapacity ? `${Math.min(100, 100 * hardware.BatteryFullChargeCapacity / hardware.BatteryDesignCapacity).toFixed(0)} %` : 'No disponible' }}</strong></div>
          <div v-if="hardware.BatteryPresent" class="infra-fact"><span>Ciclos de batería</span><strong>{{ asText(hardware.BatteryCycles) }}</strong></div>
        </div>
        <p v-else class="infra-no-data">No hay datos verificables de hardware para esta captura.</p>
        <details v-if="hardware?.MemoryModules?.length" class="infra-neighbors">
          <summary>Módulos de RAM <span class="section-count">{{ hardware.MemoryModules.length }}</span></summary>
          <div v-for="(module, index) in hardware.MemoryModules" :key="index" class="infra-neighbor-row">
            <span>{{ asText(module.Slot) }} · {{ gib(module.CapacityBytes) }}</span>
            <span>{{ asText(module.SpeedMHz) }} MHz · {{ asText(module.Manufacturer) }}</span>
          </div>
        </details>
      </section>
      <section class="surface-panel" aria-labelledby="diag-storage">
        <div class="section-head"><div><h2 id="diag-storage"><HardDrive :size="17"/> Salud de discos</h2><p>Indicadores informados por el dispositivo; no todos admiten SMART</p></div><span class="muted">{{ statusName(diagnostic?.disks?.status) }}</span></div>
        <div v-if="disks.length" class="infra-volumes">
          <div v-for="(disk, index) in disks" :key="`${disk.DeviceId ?? disk.Name ?? 'disk'}:${index}`" class="infra-volume">
            <div class="infra-volume-header"><strong>{{ asText(disk.Name) }}</strong><span>{{ asText(disk.MediaType) }} · {{ asText(disk.Health) }}</span></div>
            <div class="infra-facts">
              <div class="infra-fact"><span>Temperatura del disco</span><strong>{{ disk.Temperature == null ? 'No disponible' : `${disk.Temperature} °C` }}</strong></div>
              <div class="infra-fact"><span>Desgaste declarado</span><strong>{{ disk.Wear == null ? 'No disponible' : `${disk.Wear} %` }}</strong></div>
              <div class="infra-fact"><span>Horas de funcionamiento</span><strong>{{ asText(disk.PowerOnHours) }}</strong></div>
              <div class="infra-fact"><span>Errores no corregidos</span><strong>{{ asText(disk.ReadErrors) }} / {{ asText(disk.WriteErrors) }}</strong></div>
            </div>
          </div>
        </div>
        <p v-else class="infra-no-data">No hay indicadores de salud de discos disponibles.</p>
      </section>
      <section class="surface-panel" aria-labelledby="diag-security">
        <div class="section-head"><div><h2 id="diag-security"><Shield :size="17"/> Protección de Windows</h2><p>Estado informado; no sustituye una auditoría de seguridad</p></div><span class="muted">{{ statusName(diagnostic?.security?.status) }}</span></div>
        <p v-if="security?.ProtectionQueryStatus === 'unavailable'" class="page-footnote">La consulta actual de protección no devolvió indicadores verificables. La versión de Windows se obtuvo de manera independiente. No significa que Defender o el firewall estén desactivados.</p>
        <div v-if="security" class="infra-facts">
          <div class="infra-fact"><span>Modo Microsoft Defender</span><strong>{{ asText(security.DefenderMode) }}</strong></div>
          <div class="infra-fact"><span>Protección en tiempo real</span><strong>{{ security.DefenderRealtime == null ? 'No disponible' : security.DefenderRealtime ? 'Activa' : 'Inactiva o modo pasivo' }}</strong></div>
          <div class="infra-fact"><span>Antivirus declarados</span><strong>{{ security.AntivirusProviders?.join(', ') || 'Sin datos verificables' }}</strong></div>
          <div class="infra-fact"><span>Antigüedad firmas Defender</span><strong>{{ security.DefenderSignatureAge == null ? 'No disponible' : `${security.DefenderSignatureAge} días` }}</strong></div>
          <div class="infra-fact"><span>Actualizaciones pendientes</span><strong>{{ security.WindowsUpdatesPending == null ? 'No disponible' : `${security.WindowsUpdatesPending} según caché local` }}</strong></div>
          <div v-for="profile in security.FirewallProfiles ?? []" :key="profile.Name" class="infra-fact"><span>Firewall {{ profile.Name }}</span><strong>{{ profile.Enabled ? 'Activado' : 'Desactivado' }}</strong></div>
        </div>
        <p v-else class="infra-no-data">No se pudo confirmar el estado de la protección.</p>
      </section>
      <section v-if="windows_support?.family === 'Windows 10' || windows_support?.family === 'Windows 11'" class="surface-panel" aria-labelledby="windows-support">
        <div class="section-head"><div><h2 id="windows-support"><Shield :size="17"/> Versión y soporte de Windows</h2><p>Ciclo de soporte según la edición y compilación detectadas</p></div></div>
        <div class="infra-facts">
          <div class="infra-fact"><span>Versión</span><strong>{{ asText(windows_support.release) }}</strong></div>
          <div class="infra-fact"><span>Compilación</span><strong>{{ windows_support.build == null ? 'No disponible' : `${windows_support.build}.${windows_support.ubr ?? '—'}` }}</strong></div>
          <div class="infra-fact"><span>Canal de mantenimiento</span><strong>{{ windows_support.policy }}</strong></div>
          <div class="infra-fact"><span>Fin del soporte publicado</span><strong>{{ windows_support.support_end ? windows_support.support_end.split('-').reverse().join('/') : 'No determinado' }}</strong></div>
        </div>
        <div class="windows-support-notes">
          <p class="windows-support-note">{{ windows_support.summary }}</p>
          <p class="windows-support-note">{{ windows_support.commercial_esu_summary }}</p>
          <p class="windows-support-note">Una licencia ESU detectada no demuestra que las actualizaciones estén instaladas. El agente no puede verificar automáticamente todos los tipos de inscripción ESU.</p>
        </div>
      </section>
      <section class="surface-panel" aria-labelledby="diag-events">
        <div class="section-head"><div><h2 id="diag-events"><Activity :size="17"/> Eventos críticos de Windows</h2><p>Eventos seleccionados para el diagnóstico. El panel resume los más relevantes; para una auditoría completa conviene revisar también el detalle del sistema.</p></div><span class="section-count">{{ events.total }}</span></div>
        <form class="diagnostic-event-filters" @submit.prevent="applyEventFilters">
          <label><span>Buscar en el mensaje, proveedor o usuario</span><input v-model="filters.event_q" type="search" maxlength="100" placeholder="Ej.: RuntimeBroker, disco, SID…" /></label>
          <label><span>ID del evento</span><input v-model="filters.event_id" type="number" min="1" max="65535" placeholder="Ej.: 41" /></label>
          <label><span>Canal</span><select v-model="filters.event_channel"><option value="">Todos</option><option value="System">Sistema</option><option value="Application">Aplicaciones</option></select></label>
          <label><span>Gravedad</span><select v-model="filters.event_level"><option value="">Todas</option><option value="1">Crítico</option><option value="2">Error</option><option value="3">Advertencia</option><option value="4">Información</option><option value="5">Detallado</option></select></label>
          <div class="diagnostic-event-actions"><button class="button button-primary" type="submit">Filtrar</button><button class="button button-secondary" type="button" @click="clearEventFilters">Limpiar</button></div>
        </form>
        <div v-if="events.data.length" class="table-scroll">
          <table class="data-table"><thead><tr><th>Fecha</th><th>Evento</th><th>Canal</th></tr></thead>
            <tbody><tr v-for="event in events.data" :key="event.uuid">
              <td class="nowrap cell-secondary">{{ dateTime(event.observed_at) }}</td><td><strong>{{ eventName(event) }}</strong><div class="event-meta">ID {{ event.event_id }} · {{ event.provider }}</div><details v-if="event.message" class="event-message"><summary>Ver mensaje de Windows</summary><p>{{ event.message }}</p></details></td><td>{{ event.channel === 'System' ? 'Sistema' : event.channel === 'Application' ? 'Aplicaciones' : event.channel }}</td>
            </tr></tbody></table>
        </div>
        <p v-else class="infra-no-data">No hay eventos para los filtros seleccionados o todavía no se han recibido registros.</p>
        <Pagination :pagination="events"/>
      </section>
    </div>
  </AppLayout>
</template>
