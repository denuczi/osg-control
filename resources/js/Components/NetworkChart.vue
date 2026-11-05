<script setup lang="ts">
import { onMounted, onUnmounted, ref, watch } from 'vue';
import Chart from 'chart.js/auto';
import { urls } from '@/types/nodo';
import { chartDateFormatter, parseUtcTimestamp } from '@/Lib/displayTimezone';
const props = defineProps<{deviceId:string,hours?:number}>();
const canvas = ref<HTMLCanvasElement|null>(null);
const empty = ref(true);
const failed = ref(false);
let chart: Chart<'line'> | null = null;
let timer: ReturnType<typeof setTimeout> | undefined;
let controller: AbortController | null = null;
let disposed = false;
let requestVersion = 0;
async function refresh(): Promise<void> {
  if (disposed) return;
  clearTimeout(timer);
  if (document.visibilityState === 'hidden') { timer = setTimeout(() => void refresh(), 15000); return; }
  const version = ++requestVersion;
  controller?.abort();
  controller = new AbortController();
  try {
    const response = await fetch(`${urls.deviceMetrics(props.deviceId)}?hours=${props.hours ?? 1}`, {cache:'no-store',credentials:'same-origin',signal:controller.signal,headers:{Accept:'application/json'}});
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
    const raw: unknown = await response.json();
    if (!raw || typeof raw !== 'object' || !('samples' in raw) || !Array.isArray(raw.samples))
      throw new Error('Respuesta inválida de tráfico');
    const data = raw as { samples: {time:string,rx_mbps:number|null,tx_mbps:number|null}[],next_refresh_seconds?:number };
    if (disposed || version !== requestVersion) return;
    empty.value = !data.samples.some(s=>s.rx_mbps !== null || s.tx_mbps !== null);
    failed.value = false;
    if (chart) {
      chart.data.labels = data.samples.map(s=>chartDateFormatter((props.hours ?? 1)>=48 ? {day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'} : {hour:'2-digit',minute:'2-digit'}).format(parseUtcTimestamp(s.time) ?? new Date(NaN)));
      chart.data.datasets[0].data = data.samples.map(s=>s.rx_mbps);
      chart.data.datasets[1].data = data.samples.map(s=>s.tx_mbps);
      chart.update('none');
    }
    timer = setTimeout(()=>void refresh(),Math.max(5,Math.min(300,data.next_refresh_seconds||60))*1000);
  } catch(e) {
    if (disposed || version !== requestVersion || (e instanceof DOMException && e.name === 'AbortError')) return;
    failed.value = true;
    timer = setTimeout(()=>void refresh(),30000);
  }
}
watch(()=>[props.hours, props.deviceId],()=>{
  clearTimeout(timer);
  controller?.abort();
  void refresh();
});
onMounted(()=>{
  if (!canvas.value) return;
  chart = new Chart(canvas.value,{
    type:'line',data:{labels:[],datasets:[
      {label:'Recibido',data:[],borderColor:'#1757a5',borderWidth:2,pointRadius:0,tension:0.25},
      {label:'Enviado',data:[],borderColor:'#6f7680',borderWidth:2,pointRadius:0,tension:0.25},
    ]},options:{responsive:true,maintainAspectRatio:false,animation:false,interaction:{mode:'index',intersect:false},
      scales:{y:{min:0,ticks:{callback:value=>`${value} Mbps`}},x:{ticks:{maxTicksLimit:7},grid:{display:false}}},
      plugins:{legend:{position:'bottom',align:'start',labels:{usePointStyle:true,boxWidth:8,boxHeight:8}}}}
  });
  void refresh();
});
onUnmounted(()=>{disposed=true;requestVersion++;clearTimeout(timer);controller?.abort();chart?.destroy();chart=null;});
</script>
<template>
  <div class="infra-bandwidth-heading">Tráfico de red · {{ (hours ?? 1) === 168 ? '7 días' : (hours ?? 1) === 72 ? '3 días' : `${hours ?? 1} h` }}</div>
  <div class="infra-bandwidth-plot"><canvas ref="canvas" aria-label="Historial de tráfico por interfaces activas en Mbps" role="img"/><p v-if="empty && !failed" class="infra-chart-message">Todavía no hay mediciones de tráfico.</p><p v-if="failed" class="infra-chart-message">No se pudo consultar el historial de red.</p></div>
</template>
