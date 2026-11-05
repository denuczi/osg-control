<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import Chart from 'chart.js/auto';
import { urls } from '@/types/nodo';

type Day = { day:string; rx_bytes:number|null; tx_bytes:number|null };
type DeviceRow = { uuid:string;name:string;rx_bytes:number;tx_bytes:number;total_bytes:number };
type Usage = {
  period:string; from:string;to:string;timezone:string;scope:'device'|'fleet';
  rx_bytes:number|null;tx_bytes:number|null;total_bytes:number|null;measured_devices:number;
  registered_devices:number;average_per_measured_device_bytes:number|null;
  days_with_data:number;days_in_period:number;daily:Day[];devices:DeviceRow[];
  measurement:string;disclaimer:string; requested_timezone?:string;
};
const props = withDefaults(defineProps<{deviceId?:string; initialPeriod?:string; showFleet?:boolean}>(),
  { initialPeriod:'month', showFleet:false });
const period = ref(props.initialPeriod);
const pending = ref(false);
const error = ref('');
const report = ref<Usage|null>(null);
const canvas = ref<HTMLCanvasElement|null>(null);
let chart: Chart<'bar'>|null = null;
let controller: AbortController|null = null;
let timer: ReturnType<typeof setTimeout>|undefined;
let disposed = false;
let requestId = 0;
const periods = [
  {key:'today',label:'Hoy'}, {key:'yesterday',label:'Ayer'},
  {key:'3d',label:'3 días'}, {key:'week',label:'Esta semana'},
  {key:'month',label:'Este mes'}, {key:'30d',label:'30 días'},
];
const average = computed(()=>report.value?.average_per_measured_device_bytes ?? null);
function readable(bytes:number|null|undefined):string {
  if (bytes==null) return 'Sin mediciones';
  const value = Math.abs(bytes);
  const unit = value >= 1e9 ? 'GB' : value >= 1e6 ? 'MB' : value >= 1e3 ? 'kB' : 'B';
  const base = unit==='GB'?1e9:unit==='MB'?1e6:unit==='kB'?1e3:1;
  return `${(bytes/base).toLocaleString('es-AR',{maximumFractionDigits:2})} ${unit}`;
}
const periodUrl = computed(()=>props.deviceId ? urls.deviceUsage(props.deviceId):urls.usageData);
function draw(data: Usage):void {
  if (!chart) return;
  chart.data.labels = data.daily.map(d => {
    const [y,m,day] = d.day.split('-');
    return `${day}/${m}`;
  });
  chart.data.datasets[0].data = data.daily.map(d=>d.rx_bytes == null ? null : +(d.rx_bytes / 1e6).toFixed(3));
  chart.data.datasets[1].data = data.daily.map(d=>d.tx_bytes == null ? null : +(d.tx_bytes / 1e6).toFixed(3));
  chart.update('none');
}
async function refresh():Promise<void> {
  if (disposed) return;
  clearTimeout(timer);
  if (document.visibilityState === 'hidden') { timer = setTimeout(()=>void refresh(), 60000);return; }
  controller?.abort();
  controller = new AbortController();
  const current = ++requestId;
  pending.value = true;
  try {
    const result = await fetch(`${periodUrl.value}?period=${encodeURIComponent(period.value)}`,
      {headers:{Accept:'application/json'},credentials:'same-origin',cache:'no-store',signal:controller.signal});
    if (!result.ok) throw new Error(`HTTP ${result.status}`);
    const payload:unknown = await result.json();
    if (typeof payload !== 'object' || !payload || !('daily' in payload) || !Array.isArray(payload.daily) ||
        !('total_bytes' in payload) || (payload.total_bytes !== null && typeof payload.total_bytes !== 'number')) throw new Error('Respuesta inválida');
    if (disposed || current!==requestId) return;
    report.value = payload as Usage;
    error.value = '';
    draw(report.value);
  } catch(e) {
    if (disposed || current!==requestId || (e instanceof DOMException && e.name==='AbortError')) return;
    error.value = 'No se pudo recuperar el consumo. Comprobá la conexión con Laravel.';
  } finally {
    if (!disposed && current===requestId) {
      pending.value = false;
      timer = setTimeout(()=>void refresh(), 60000);
    }
  }
}
watch(period,()=>{ void refresh(); });
watch(()=>props.deviceId,()=>{void refresh();});
onMounted(()=>{
  if (canvas.value) chart = new Chart(canvas.value, {
    type:'bar',data:{labels:[],datasets:[
      {label:'Descarga',data:[],backgroundColor:'#1757a5',borderRadius:3,stack:'usage'},
      {label:'Subida',data:[],backgroundColor:'#8b949e',borderRadius:3,stack:'usage'},
    ]},options:{responsive:true,maintainAspectRatio:false,animation:false,
      scales:{x:{stacked:true,grid:{display:false},ticks:{maxTicksLimit:10}},
              y:{stacked:true,beginAtZero:true,ticks:{callback:v=>`${v} MB`}}},
      plugins:{legend:{position:'bottom',align:'start',labels:{usePointStyle:true,boxWidth:9}}},
    },
  });
  void refresh();
});
onUnmounted(()=>{disposed=true;requestId++;clearTimeout(timer);controller?.abort();chart?.destroy();chart=null;});
</script>

<template>
  <section class="surface-panel usage-panel" aria-label="Consumo de red por período">
    <div class="section-head usage-header">
      <div><h2>Consumo de red</h2><p>Bytes transferidos por interfaces monitorizadas · no equivale a datos de Internet facturados</p></div>
      <Link v-if="deviceId" :href="urls.usage" class="quiet-link">Comparar equipos</Link>
    </div>
    <div class="usage-content">
      <div class="usage-periods" role="group" aria-label="Seleccionar período de consumo">
        <button v-for="option in periods" :key="option.key" class="button button-secondary"
          type="button" :class="{'usage-period-active':period===option.key}"
          :aria-pressed="period===option.key" @click="period=option.key">{{ option.label }}</button>
      </div>
      <p v-if="error" class="notice notice-danger" role="alert">{{ error }} <button type="button" class="button button-secondary" @click="refresh">Reintentar</button></p>
      <div v-if="report" class="usage-summary" :aria-busy="pending">
        <div><span>Transferencia total</span><strong>{{ readable(report.total_bytes) }}</strong></div>
        <div><span>Descarga</span><strong>{{ readable(report.rx_bytes) }}</strong></div>
        <div><span>Subida</span><strong>{{ readable(report.tx_bytes) }}</strong></div>
        <div v-if="!deviceId"><span>Promedio por equipo medido</span><strong>{{ readable(average) }}</strong></div>
      </div>
      <p v-else-if="!error" class="muted">Consultando consumo…</p>
      <p v-if="report" class="usage-coverage">{{ report.days_with_data }} de {{ report.days_in_period }} días con registros · Días agrupados en {{ report.timezone }}<span v-if="!deviceId"> · {{ report.measured_devices }} de {{ report.registered_devices }} equipos con mediciones</span></p>
      <p v-if="report && report.requested_timezone" class="usage-warning">El historial disponible conserva la agrupación de {{ report.timezone }}. No hay detalle horario suficiente para convertirlo a {{ report.requested_timezone }}.</p>
      <p v-if="report && report.days_with_data < report.days_in_period" class="usage-warning">Existen días sin registros. Los valores mostrados no representan necesariamente todo el consumo del período.</p>
      <div class="usage-chart"><canvas ref="canvas" aria-label="Tráfico recibido y enviado por día, en megabytes" role="img" /></div>
      <p class="usage-disclaimer">Los bytes proceden de contadores del sistema; el reparto próximo al cambio de día se estima proporcionalmente. El tráfico LAN también cuenta. Sin conexión a Laravel, el agente conserva los acumulados localmente para sincronizarlos después.</p>
      <div v-if="showFleet && report?.devices.length" class="usage-devices">
        <h3>Consumo por computadora</h3>
        <div class="table-scroll"><table class="data-table"><thead><tr><th>Equipo</th><th>Descarga</th><th>Subida</th><th>Total</th></tr></thead><tbody>
          <tr v-for="d in report.devices" :key="d.uuid"><td><Link class="quiet-link" :href="urls.device(d.uuid)">{{ d.name }}</Link></td><td>{{ readable(d.rx_bytes) }}</td><td>{{ readable(d.tx_bytes) }}</td><td><strong>{{ readable(d.total_bytes) }}</strong></td></tr>
        </tbody></table></div>
      </div>
    </div>
  </section>
</template>
