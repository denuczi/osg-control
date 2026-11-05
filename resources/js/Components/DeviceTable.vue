<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronRight, Monitor } from '@lucide/vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import ResourceCell from '@/Components/ResourceCell.vue';
import { dateTime, urls, type Device } from '@/types/nodo';
withDefaults(defineProps<{ devices: Device[]; showPlatform?: boolean; showContact?: boolean; selectedIds?: string[]; selectable?: boolean }>(), {
  showPlatform: false,
  showContact: false,
  selectable: false,
  selectedIds: () => [],
});
const emit = defineEmits<{toggle: [uuid:string, checked:boolean]}>();
</script>
<template>
  <div class="table-scroll devices-table-wrap">
    <table class="data-table devices-table">
      <thead><tr>
        <th v-if="selectable" scope="col">Seleccionar</th><th scope="col">Equipo</th>
        <th scope="col">Estado</th>
        <th v-if="showPlatform" scope="col">Sistema</th>
        <th scope="col">CPU</th>
        <th scope="col">Memoria</th>
        <th v-if="showContact" scope="col">Último contacto</th>
        <th scope="col" class="align-right">Detalle</th>
      </tr></thead>
      <tbody>
        <tr v-for="device in devices" :key="device.uuid">
          <td v-if="selectable"><input type="checkbox" :disabled="!device.online || device.demo" :checked="selectedIds.includes(device.uuid)" :aria-label="`Seleccionar ${device.name}`" @change="emit('toggle',device.uuid,($event.target as HTMLInputElement).checked)"/></td>
          <td>
            <div class="device-identity">
              <span class="device-identity-icon"><Monitor :size="18" :stroke-width="1.8" aria-hidden="true"/></span>
              <span class="device-identity-text">
                <Link :href="urls.device(device.uuid)" class="item-title">{{ device.name }}</Link>
                <span class="cell-secondary">{{ device.hostname }}</span>
              </span>
            </div>
          </td>
          <td><StatusBadge :online="device.online" :demo="device.demo" /></td>
          <td v-if="showPlatform" class="cell-secondary system-name">{{ device.platform }}</td>
          <td><ResourceCell :value="device.cpu" label="CPU" /></td>
          <td><ResourceCell :value="device.memory" label="Memoria" /></td>
          <td v-if="showContact" class="cell-secondary nowrap">{{ device.demo ? 'Datos de prueba' : dateTime(device.last_seen) }}</td>
          <td class="align-right"><Link :href="urls.device(device.uuid)" class="details-link" :aria-label="`Ver detalles de ${device.name}`"><span>Ver</span><ChevronRight :size="16" aria-hidden="true"/></Link></td>
        </tr>
      </tbody>
    </table>
  </div>
  <p class="mobile-table-hint">Deslizá la tabla horizontalmente para ver más columnas.</p>
</template>
