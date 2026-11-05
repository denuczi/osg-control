<script setup lang="ts">
import { computed } from 'vue';
import { Cpu, HardDrive, Network, Usb, Wifi } from '@lucide/vue';
import NetworkChart from '@/Components/NetworkChart.vue';
import type { Device } from '@/types/nodo';
import { dateTime, formatGib, formatMbps } from '@/types/nodo';
const props = defineProps<{ device: Device; hours?: number }>();
const hardware = computed(() => props.device.inventory?.processor);
const volumes = computed(() => props.device.inventory?.volumes ?? []);
const network = computed(() => props.device.inventory?.network);
const activeInterfaces = computed(() => (network.value?.interfaces ?? []).filter(i => i.up));
const usagePercent = (value: number | null | undefined): number | null =>
  typeof value === 'number' && Number.isFinite(value) ? Math.min(100, Math.max(0, value)) : null;
const nearlyFull = (value: number | null | undefined): boolean => (usagePercent(value) ?? 0) >= 90;
const cautionFull = (value: number | null | undefined): boolean => {
  const usage = usagePercent(value) ?? 0;
  return usage >= 75 && usage < 90;
};
const cpuGhz = (mhz: number | null | undefined) => mhz == null ? 'No disponible' : `${(mhz/1000).toLocaleString('es-AR',{maximumFractionDigits:2})} GHz`;
</script>
<template>
  <div class="infra-stack">
    <section class="surface-panel" aria-labelledby="hardware-title">
      <div class="section-head infra-head"><div><h2 id="hardware-title"><Cpu :size="17"/> Hardware</h2><p>Características reportadas por el agente</p></div><span v-if="device.inventory" class="infra-observed">Inventario: {{ dateTime(device.inventory.captured_at) }}</span></div>
      <div v-if="!hardware" class="infra-no-data">El agente todavía no envió el inventario del hardware.</div>
      <div v-else class="infra-facts">
        <div class="infra-fact infra-fact-wide"><span>Procesador</span><strong>{{ hardware.name ?? 'No disponible' }}</strong></div>
        <div class="infra-fact"><span>Núcleos físicos</span><strong>{{ hardware.physical_cores ?? '—' }}</strong></div>
        <div class="infra-fact"><span>Procesadores lógicos</span><strong>{{ hardware.logical_processors ?? '—' }}</strong></div>
        <div class="infra-fact"><span>Frecuencia máxima declarada</span><strong>{{ cpuGhz(hardware.max_mhz) }}</strong></div>
        <div class="infra-fact"><span>Frecuencia informada</span><strong>{{ cpuGhz(hardware.current_mhz) }}</strong></div>
        <div class="infra-fact"><span>RAM total</span><strong>{{ formatGib(device.memory_total_bytes) }}</strong></div>
        <div class="infra-fact"><span>RAM en uso</span><strong>{{ formatGib(device.memory_used_bytes) }}</strong></div>
        <div class="infra-fact"><span>RAM disponible</span><strong>{{ formatGib(device.memory_available_bytes) }}</strong></div>
      </div>
    </section>
    <section class="surface-panel" aria-labelledby="storage-title">
      <div class="section-head infra-head"><div><h2 id="storage-title"><HardDrive :size="17"/> Discos y unidades</h2><p>Capacidad por volumen y medios extraíbles</p></div></div>
      <div v-if="!volumes.length" class="infra-no-data">Todavía no hay volúmenes inventariados.</div>
      <div v-else class="infra-volumes">
        <div v-for="volume in volumes" :key="volume.mountpoint+volume.device" class="infra-volume">
          <div class="infra-volume-header"><span class="infra-volume-name"><Usb v-if="volume.removable" :size="16"/><HardDrive v-else :size="16"/>{{ volume.label || volume.mountpoint }}</span><span class="infra-volume-type">{{ volume.removable ? 'Extraíble' : (volume.media_type || 'Tipo desconocido') }}<template v-if="volume.bus_type"> · {{ volume.bus_type }}</template></span></div>
          <div class="infra-volume-meta">{{ volume.mountpoint }} <span v-if="volume.filesystem">· {{ volume.filesystem }}</span> <span v-if="volume.model">· {{ volume.model }}</span></div>
          <div class="infra-storage-heading"><span>Espacio utilizado</span><strong :class="{ 'is-critical': nearlyFull(volume.used_percent), 'is-caution': cautionFull(volume.used_percent) }">{{ usagePercent(volume.used_percent) === null ? 'Sin datos' : `${usagePercent(volume.used_percent)?.toLocaleString('es', { maximumFractionDigits: 1 })} %` }}</strong></div>
          <div v-if="usagePercent(volume.used_percent) !== null" class="infra-storage-bar" :class="{ 'is-critical': nearlyFull(volume.used_percent), 'is-caution': cautionFull(volume.used_percent) }" role="progressbar" :aria-valuenow="usagePercent(volume.used_percent) ?? 0" aria-valuemin="0" aria-valuemax="100" :aria-label="`Espacio utilizado en ${volume.mountpoint}`"><div :style="{ width: `${usagePercent(volume.used_percent) ?? 0}%` }"/></div>
          <p v-else class="infra-storage-unknown">El porcentaje de ocupación no está disponible.</p>
          <div class="infra-volume-foot"><span>{{ formatGib(volume.used_bytes) }} utilizados</span><span>{{ formatGib(volume.free_bytes) }} libres de {{ formatGib(volume.total_bytes) }}</span></div>
          <p v-if="nearlyFull(volume.used_percent)" class="infra-storage-alert" role="status">Poco espacio disponible en esta unidad.</p>
        </div>
      </div>
    </section>
    <section class="surface-panel" aria-labelledby="network-title">
      <div class="section-head infra-head"><div><h2 id="network-title"><Network :size="17"/> Red</h2><p>Interfaces y tráfico de la computadora; no representa un test de velocidad de Internet</p></div></div>
      <div v-if="!network" class="infra-no-data">El agente todavía no informó la configuración de red.</div>
      <template v-else>
        <div class="infra-facts infra-network-summary">
          <div class="infra-fact"><span>Gateway / router predeterminado</span><strong>{{ network.default_gateway || 'No detectado' }}</strong></div>
          <div class="infra-fact"><span>DNS configurados</span><strong>{{ network.dns_servers?.join(', ') || 'No disponibles' }}</strong></div>
          <div class="infra-fact"><span>Recepción actual</span><strong>{{ formatMbps(device.online ? device.network_rx_bps : null) }}</strong></div>
          <div class="infra-fact"><span>Envío actual</span><strong>{{ formatMbps(device.online ? device.network_tx_bps : null) }}</strong></div>
        </div>
        <div class="infra-profiles" v-if="network.profiles?.length"><div v-for="profile in network.profiles" :key="profile.interface" class="infra-profile"><strong>{{ profile.name || profile.interface }}</strong><span>{{ profile.interface }} · {{ profile.ipv4_connectivity || 'Conectividad desconocida' }}</span></div></div>
        <div class="infra-interface-list" v-if="activeInterfaces.length">
          <div v-for="adapter in activeInterfaces" :key="adapter.name" class="infra-interface"><div class="infra-adapter-name"><Wifi :size="15"/><strong>{{ adapter.name }}</strong><span v-if="adapter.link_speed_mbps">Enlace {{ adapter.link_speed_mbps }} Mbps</span></div><div class="infra-adapter-addresses">IPv4: {{ adapter.ipv4.map(ip=>ip.address+(ip.subnet?' ('+ip.subnet+')':'')).join(', ') || 'No disponible' }}<span v-if="adapter.mac"> · MAC: {{ adapter.mac }}</span></div></div>
        </div>
        <div v-else class="infra-no-data">No hay interfaces de red activas registradas.</div>
        <details class="infra-neighbors" v-if="network.neighbors?.length">
          <summary>Otros dispositivos conocidos por Windows <span class="section-count">{{ network.neighbors.length }}</span></summary>
          <p>Dispositivos observados por Windows. No es un inventario completo de la red.</p>
          <div class="infra-neighbor-row" v-for="neighbor in network.neighbors.slice(0,16)" :key="neighbor.ip+neighbor.mac"><span>{{ neighbor.ip }}</span><span>{{ neighbor.mac }}</span></div>
        </details>
        <div class="infra-network-chart"><NetworkChart :device-id="device.uuid" :hours="hours" /></div>
      </template>
    </section>
  </div>
</template>
