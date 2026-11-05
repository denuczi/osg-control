<script setup lang="ts">
import { ref, onMounted, onUnmounted, watch } from 'vue';
import { urls, type MediaEvent, dateTime, formatGib } from '@/types/nodo';
const props = defineProps<{deviceId:string}>();
const items = ref<MediaEvent[]>([]);
const page = ref(1);
const lastPage = ref(1);
const loading = ref(false);
const error = ref('');
let controller:AbortController|null = null;
// Zero in legacy notifications means "not measured", not a zero-capacity USB stick.
function mediaCapacity(bytes:number|null|undefined):string {
  return typeof bytes === 'number' && bytes > 0 ? formatGib(bytes) : 'No disponible';
}
async function load(next:number){
  controller?.abort(); const current=new AbortController(); controller=current; loading.value=true; error.value='';
  try {
    const res=await fetch(`${urls.deviceMedia(props.deviceId)}?page=${next}`, {signal:current.signal,credentials:'same-origin',headers:{Accept:'application/json'},cache:'no-store'});
    if(!res.ok) throw new Error('No se pudo consultar el historial');
    const data:{data:MediaEvent[],current_page:number,last_page:number}=await res.json();
    if (controller !== current) return;
    items.value=data.data;page.value=data.current_page;lastPage.value=data.last_page;
  } catch(e){if(controller===current && !(e instanceof DOMException && e.name==='AbortError')) error.value='No se pudo cargar el historial de unidades.';}
  finally{if(controller===current) loading.value=false;}
}
onMounted(()=>void load(1));
watch(() => props.deviceId, () => { items.value=[]; void load(1); });
onUnmounted(() => controller?.abort());
</script>
<template>
  <section class="surface-panel media-history">
    <div class="section-head"><div><h2>Historial de USB y CD/DVD</h2><p>Inserciones, extracciones y medios detectados · zona horaria configurada</p></div><button type="button" class="button button-secondary" @click="load(page)">Actualizar</button></div>
    <p v-if="error" class="notice notice-danger" role="alert">{{ error }}</p>
    <p v-if="!items.length && !loading" class="muted">Sin registros. Solo se detectan cambios cuando el agente está funcionando.</p>
    <div v-else class="table-scroll"><table class="data-table"><thead><tr><th>Fecha y hora</th><th>Evento</th><th>Medio</th><th>Unidad</th><th>Capacidad</th></tr></thead><tbody>
      <tr v-for="e in items" :key="e.uuid"><td>{{ dateTime(e.observed_at) }}</td><td>{{ e.kind==='connected'?'Conectado':e.kind==='disconnected'?'Desconectado':'Detectado al iniciar' }}</td><td>{{ e.volume.media_class==='optical'?'CD/DVD':e.volume.media_class==='usb'?'USB':'Extraíble' }}</td><td>{{ e.volume.label || e.volume.mountpoint }}</td><td>{{ mediaCapacity(e.volume.total_bytes) }}</td></tr>
    </tbody></table></div>
    <div class="bulk-actions"><button class="button button-secondary" type="button" :disabled="loading || page<=1" @click="load(page-1)">Anterior</button><span class="muted">Página {{ page }} de {{ lastPage }}</span><button class="button button-secondary" type="button" :disabled="loading || page>=lastPage" @click="load(page+1)">Siguiente</button></div>
  </section>
</template>
