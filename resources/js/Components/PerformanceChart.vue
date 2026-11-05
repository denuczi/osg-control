<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import Chart from 'chart.js/auto';
import { dateTime, urls } from '@/types/nodo';
import { chartDateFormatter, parseUtcTimestamp } from '@/Lib/displayTimezone';

type LiveMetric = { time: string; cpu: number | null; memory: number | null };
type Metric = LiveMetric;
type MetricsResponse = { samples: Metric[]; next_refresh_seconds?: number };
const props = defineProps<{
  deviceId: string;
  refreshSeconds: number;
  hours: number;
  live?: LiveMetric | null;
}>();
const canvas = ref<HTMLCanvasElement | null>(null);
const loading = ref(true);
const failed = ref(false);
const lastHistoricalAt = ref<string | null>(null);
const samples = ref<Metric[]>([]);
const displayedSample = computed(() => props.live ?? null);
const empty = computed(() => samples.value.length === 0 && !props.live);
let chart: Chart<'line'> | null = null;
let timer: ReturnType<typeof setTimeout> | undefined;
let request: AbortController | null = null;
let revision = 0;
let disposed = false;

function visibleSamples(): Metric[] {
  const points = samples.value.filter(p => Number.isFinite((parseUtcTimestamp(p.time)?.getTime() ?? NaN)));
  const live = props.live;
  if (!live || !Number.isFinite((parseUtcTimestamp(live.time)?.getTime() ?? NaN))) return points;
  const since = Date.now() - props.hours * 3600 * 1000;
  if ((parseUtcTimestamp(live.time)?.getTime() ?? NaN) < since) return points;
  const result = points.slice();
  // Last point is the precise sample displayed in the KPI, never an AVG bucket.
  // Don't overwrite newer historical data with an older live-status response.
  const lastAt = result.length ? (parseUtcTimestamp(result[result.length - 1].time)?.getTime() ?? NaN) : -Infinity;
  const liveAt = (parseUtcTimestamp(live.time)?.getTime() ?? NaN);
  if (liveAt >= lastAt) {
    if (liveAt === lastAt && result.length) result[result.length - 1] = live;
    else result.push(live);
  }
  return result;
}
function render() {
  if (!chart) return;
  const points = visibleSamples();
  const opts: Intl.DateTimeFormatOptions = props.hours >= 48
    ? { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }
    : { hour: '2-digit', minute: '2-digit', second: props.hours === 1 ? '2-digit' : undefined };
  const formatter = chartDateFormatter(opts);
  chart.data.labels = points.map(point => formatter.format(parseUtcTimestamp(point.time) ?? new Date(NaN)));
  chart.data.datasets[0].data = points.map(point => point.cpu);
  chart.data.datasets[1].data = points.map(point => point.memory);
  chart.update('none');
}
function schedule(seconds: number) {
  clearTimeout(timer);
  if (!disposed) timer = setTimeout(() => void load(), Math.min(300, Math.max(5, seconds)) * 1000);
}
async function load() {
  if (disposed || document.visibilityState === 'hidden') return;
  clearTimeout(timer);
  const version = ++revision;
  request?.abort();
  const current = new AbortController();
  request = current;
  try {
    const response = await fetch(`${urls.deviceMetrics(props.deviceId)}?hours=${props.hours}`, {
      signal: current.signal, cache: 'no-store', credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    });
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
    const raw: unknown = await response.json();
    if (!raw || typeof raw !== 'object' || !('samples' in raw) || !Array.isArray(raw.samples))
      throw new Error('Respuesta de métricas inválida');
    const data = raw as MetricsResponse;
    if (disposed || version !== revision) return;
    samples.value = data.samples;
    lastHistoricalAt.value = data.samples.at(-1)?.time ?? null;
    failed.value = false;
    render();
    schedule(data.next_refresh_seconds ?? props.refreshSeconds);
  } catch (error) {
    if (!disposed && version === revision && !(error instanceof DOMException && error.name === 'AbortError')) {
      failed.value = true;
      schedule(Math.max(15, props.refreshSeconds));
    }
  } finally {
    if (!disposed && version === revision) loading.value = false;
    if (request === current) request = null;
  }
}
function onVisible() {
  if (document.visibilityState === 'visible') void load();
  else { clearTimeout(timer); request?.abort(); }
}
watch(() => [props.hours, props.deviceId], () => {
  samples.value = [];
  loading.value = true;
  failed.value = false;
  void load();
});
watch(() => props.live, render);
onMounted(() => {
  if (!canvas.value) return;
  chart = new Chart(canvas.value, {
    type: 'line',
    data: { labels: [], datasets: [
      { label: 'CPU', data: [], borderColor: '#d64e22', backgroundColor: 'rgba(214,78,34,.07)', borderWidth: 2, pointRadius: 1, pointHoverRadius: 4, tension: 0.25, fill: true },
      { label: 'Memoria RAM', data: [], borderColor: '#3275ae', backgroundColor: 'transparent', borderWidth: 2, pointRadius: 1, pointHoverRadius: 4, tension: 0.25 },
    ] },
    options: {
      responsive: true, maintainAspectRatio: false, animation: false,
      interaction: { intersect: false, mode: 'index' },
      scales: {
        y: { min: 0, max: 100, ticks: { callback: value => `${value}%`, color: '#737373', stepSize: 25 }, grid: { color: 'rgba(30,30,30,.07)' } },
        x: { ticks: { maxTicksLimit: 7, color: '#737373' }, grid: { display: false } },
      },
      plugins: {
        legend: { position: 'bottom', align: 'start', labels: { usePointStyle: true, boxWidth: 7, boxHeight: 7, color: '#4a4a4a', padding: 18 } },
        tooltip: { backgroundColor: '#303030', padding: 10 },
      },
    },
  });
  render();
  document.addEventListener('visibilitychange', onVisible);
  void load();
});
onUnmounted(() => {
  disposed = true;
  revision++;
  clearTimeout(timer);
  request?.abort();
  document.removeEventListener('visibilitychange', onVisible);
  chart?.destroy();
  chart = null;
});
</script>

<template>
  <div class="metric-chart-wrap">
    <div class="chart-area">
      <canvas ref="canvas" aria-label="Historial de consumo de CPU y memoria RAM" role="img" />
      <div v-if="loading && !live" class="chart-message">Cargando métricas…</div>
      <div v-else-if="empty && !failed" class="chart-message">No hay mediciones para este período.</div>
    </div>
    <p class="metric-chart-caption" role="status">
      <span v-if="failed" class="chart-caption-error">No se pudo actualizar el historial. </span>
      <span v-if="displayedSample">
        Última medición: {{ dateTime(displayedSample.time) }} · CPU {{ displayedSample.cpu?.toLocaleString('es-AR', { maximumFractionDigits: 1 }) ?? '—' }} % · RAM {{ displayedSample.memory?.toLocaleString('es-AR', { maximumFractionDigits: 1 }) ?? '—' }} %.
      </span>
      <span v-else-if="lastHistoricalAt">Última muestra histórica: {{ dateTime(lastHistoricalAt) }}.</span>
      <span v-else>Esperando mediciones del agente.</span>
      <span> El historial agrupa mediciones por intervalos; el último punto disponible representa la muestra actual.</span>
    </p>
  </div>
</template>
