<script setup lang="ts">
import { ref, watch, onUnmounted, computed } from 'vue';
import { useRemotePolling } from '@/Composables/useRemotePolling';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Search, X, Plus } from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import DeviceTable from '@/Components/DeviceTable.vue';
import EmptyState from '@/Components/EmptyState.vue';
import Pagination from '@/Components/Pagination.vue';
import GenerateFleetReportButton from '@/Components/Reports/GenerateFleetReportButton.vue';
import { urls, type Device, type Paginated } from '@/types/nodo';
const props = defineProps<{ devices: Paginated<Device>, filters: {q: string}, refreshSeconds: number }>();
const liveDevices = ref<Device[]>(props.devices.data);
watch(() => props.devices.data, value => { liveDevices.value = value; });
function isDeviceStatusList(value: unknown): value is {devices: Device[]} {
  if (!value || typeof value !== 'object' || !('devices' in value)) return false;
  const devices = value.devices;
  return Array.isArray(devices) && devices.length <= 15 && devices.every((d: unknown) =>
    d !== null && typeof d === 'object' && 'uuid' in d && typeof d.uuid === 'string' &&
    'online' in d && typeof d.online === 'boolean');
}
const visibleUrl = computed(() => {
  const params = new URLSearchParams();
  props.devices.data.forEach(device => params.append('uuids[]', device.uuid));
  return `${urls.deviceStatuses}?${params}`;
});
const {error: refreshError} = useRemotePolling<{devices: Device[]}>(visibleUrl, props.refreshSeconds, data => {
  const updated = new Map(data.devices.map(d=>[d.uuid,d]));
  liveDevices.value = props.devices.data.map(d=>updated.get(d.uuid) ?? d);
}, isDeviceStatusList);
// crypto.randomUUID() may be unavailable on an HTTP LAN (insecure context).
// getRandomValues() is available there; the UUID is an idempotency key, not a secret.
function newBatchUuid(): string {
  const bytes = new Uint8Array(16);
  if (typeof crypto !== 'undefined' && typeof crypto.getRandomValues === 'function') {
    crypto.getRandomValues(bytes);
  } else {
    for(let i=0;i<16;i++) bytes[i]=Math.floor(Math.random()*256);
  }
  bytes[6]=(bytes[6]&0x0f)|0x40;
  bytes[8]=(bytes[8]&0x3f)|0x80;
  const hex=Array.from(bytes,b=>b.toString(16).padStart(2,'0')).join('');
  return `${hex.slice(0,8)}-${hex.slice(8,12)}-${hex.slice(12,16)}-${hex.slice(16,20)}-${hex.slice(20)}`;
}
const search = ref(props.filters.q ?? '');
const selected = ref<string[]>([]);
const bulkForm = useForm({action:'show_message' as 'show_message'|'shutdown'|'restart', device_uuids:[] as string[], all_online:false, batch_uuid:newBatchUuid(), message:'',confirm:true});
const bulkOpen = ref(false);
const selectable = computed(() => liveDevices.value.filter(d => d.online && !d.demo));
function toggleSelection(uuid:string,checked:boolean){selected.value=checked?[...new Set([...selected.value,uuid])]:selected.value.filter(id=>id!==uuid);}
function selectAllVisible(){selected.value=selectable.value.map(d=>d.uuid);}
function submitBulk(){
 bulkForm.device_uuids=bulkForm.all_online?[]:[...selected.value];
 bulkForm.post(urls.bulkCommand, {preserveScroll:true,onSuccess:()=>{bulkOpen.value=false;selected.value=[];bulkForm.reset();bulkForm.batch_uuid=newBatchUuid();}});
}

let debounce: ReturnType<typeof setTimeout> | undefined;
watch(search, value => {
  clearTimeout(debounce);
  debounce = setTimeout(() => {
    if (value.trim() !== props.filters.q) router.get(urls.devices, value.trim() ? {q:value.trim()} : {}, { preserveState:true, replace:true });
  }, 350);
});
onUnmounted(() => clearTimeout(debounce));
function clearSearch() { search.value = ''; router.get(urls.devices, {}, {replace:true}); }
</script>
<template>
  <AppLayout><Head title="Equipos"/>
    <div class="page-titlebar"><div><h1>Equipos</h1><p>Identidad, disponibilidad y recursos de las computadoras.</p></div><div class="fleet-title-actions"><Link :href="urls.assistant" class="button button-secondary">Consultar IA</Link><GenerateFleetReportButton/><Link :href="urls.deviceManagement" class="button button-primary"><Plus :size="16"/> Agregar o administrar equipos</Link></div></div>
    <div v-if="refreshError" class="notice notice-danger" role="alert">La lista no pudo actualizarse; se muestran los últimos datos recibidos.</div>
    <section class="surface-panel devices-surface"><div class="section-head toolbar-head"><div><h2>Dispositivos autorizados</h2><p>{{ devices.total }} {{ devices.total===1?'equipo registrado':'equipos registrados' }}</p></div><div class="search-field"><Search :size="17"/><input v-model="search" type="search" aria-label="Buscar equipos" placeholder="Buscar nombre o hostname"/><button v-if="search" type="button" aria-label="Limpiar búsqueda" class="icon-button" @click="clearSearch"><X :size="15"/></button></div></div>
      <div v-if="!liveDevices.length"><EmptyState :title="search?'No hay coincidencias':'Todavía no hay equipos'" :description="search?'Prueba con otro nombre o hostname.':'Instala OSG Nodo Agent en Windows y autoriza la vinculación desde el panel.'"/></div>
      <DeviceTable v-else :devices="liveDevices" :show-platform="true" :show-contact="true" selectable :selected-ids="selected" @toggle="toggleSelection" />
      <div class="bulk-actions">
        <div class="bulk-actions-main">
          <button class="button button-secondary" type="button" @click="selectAllVisible" :disabled="!selectable.length">Seleccionar conectados visibles</button>
          <span class="muted bulk-selection-count">{{ selected.length }} seleccionados</span>
          <select v-model="bulkForm.action" aria-label="Acción masiva"><option value="show_message">Mostrar mensaje</option><option value="restart">Reiniciar</option><option value="shutdown">Apagar</option></select>
          <label class="bulk-check"><input type="checkbox" v-model="bulkForm.all_online"/> Todos los conectados del servidor</label>
        </div>
        <div class="bulk-actions-submit">
          <button class="button button-primary" type="button" :disabled="!bulkForm.all_online && !selected.length" @click="bulkOpen=true">Continuar</button>
        </div>
      </div>
      <Pagination :pagination="devices" />
    </section>
    <div v-if="bulkOpen" class="modal-overlay" @click.self="bulkOpen=false"><div class="dialog" role="dialog" aria-modal="true" aria-labelledby="bulk-title">
      <div class="dialog-header"><h2 id="bulk-title">Confirmar acción masiva</h2><button type="button" class="icon-button" aria-label="Cerrar" @click="bulkOpen=false"><X :size="19"/></button></div>
      <div class="dialog-body"><p>Se enviará <strong>{{ bulkForm.action==='show_message'?'un mensaje':bulkForm.action==='restart'?'una orden de reinicio':'una orden de apagado' }}</strong> a {{ bulkForm.all_online ? 'todas las computadoras conectadas del servidor (máximo 100)' : `${selected.length} computadora(s) seleccionada(s)` }}.</p>
        <label v-if="bulkForm.action==='show_message'" class="form-field"><span>Mensaje visible para los usuarios</span><textarea v-model="bulkForm.message" maxlength="500" rows="4" required placeholder="Texto del mensaje"/></label>
        <p v-if="bulkForm.errors.message" class="field-error">{{ bulkForm.errors.message }}</p><p v-if="bulkForm.errors.device_uuids" class="field-error">{{ bulkForm.errors.device_uuids }}</p>
        <p class="muted">Cada equipo tendrá su propia orden y resultado de auditoría. Los mensajes requieren una sesión de usuario activa.</p></div>
      <div class="dialog-actions"><button class="button button-secondary" type="button" @click="bulkOpen=false">Cancelar</button><button class="button button-danger" type="button" :disabled="bulkForm.processing" @click="submitBulk">{{ bulkForm.processing?'Registrando…':'Confirmar envío' }}</button></div>
    </div></div>
  </AppLayout>
</template>
