<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { cityName, countryName, countryOf, currentTimeInZone, type TimezoneChoice } from '@/Lib/timezoneChoices';
import { Bot, Clock3, Info, Save, SlidersHorizontal, FileText } from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import type { MonitoringSettings } from '@/types/nodo';
import { urls } from '@/types/nodo';
const props = defineProps<{
  settings: MonitoringSettings,
  timezoneOptions: TimezoneChoice[],
  selectedTimezone: string,
  ranges: Record<keyof MonitoringSettings, [number,number]>,
  storage: {sample_count:number,sample_limit:number,database_bytes:number|null,database_limit_bytes:number},
  reports: {ai_enabled:boolean,ai_configured:boolean,provider:string},
  assistantRuntime: {mode:'local'|'remote',enabled:boolean,limits:{total_timeout_seconds:number,provider_timeout_seconds:number,max_provider_calls:number,max_output_tokens:number},ranges:Record<string,[number,number]>}
}>();
const form = useForm({...props.settings});
const timezoneForm = useForm({ timezone: props.selectedTimezone });
const selectedCountry = ref(countryOf(props.timezoneOptions, props.selectedTimezone));
const countries = computed(() => Array.from(new Set(props.timezoneOptions.map(choice => choice.country)))
  .map(code => ({ code, label: countryName(code) }))
  .sort((a, b) => a.label.localeCompare(b.label, 'es')));
const cityOptions = computed(() => props.timezoneOptions
  .filter(choice => choice.country === selectedCountry.value)
  .map(choice => ({ id: choice.id, label: cityName(choice.id) }))
  .sort((a, b) => a.label.localeCompare(b.label, 'es')));
const preferredZone: Record<string, string> = {
  AR: 'America/Argentina/Cordoba', CO: 'America/Bogota', MX: 'America/Mexico_City',
  ES: 'Europe/Madrid', US: 'America/New_York', ZZ: 'UTC',
};
watch(selectedCountry, code => {
  if (cityOptions.value.some(option => option.id === timezoneForm.timezone)) return;
  const preferred = preferredZone[code];
  timezoneForm.timezone = cityOptions.value.find(option => option.id === preferred)?.id
    ?? cityOptions.value[0]?.id ?? '';
});
const sampleTime = ref(new Date());
let clockTimer: ReturnType<typeof setInterval> | undefined;
onMounted(() => { clockTimer = setInterval(() => { sampleTime.value = new Date(); }, 30000); });
onUnmounted(() => { if (clockTimer !== undefined) clearInterval(clockTimer); });
const timezonePreview = computed(() => currentTimeInZone(timezoneForm.timezone, sampleTime.value));
const selectedPlace = computed(() => cityName(timezoneForm.timezone));
function saveTimezone() { timezoneForm.put('/configuracion/zona-horaria', { preserveScroll: true }); }
const reportForm = useForm({ai_enabled:props.reports.ai_enabled});
const assistantForm = useForm({...props.assistantRuntime.limits});
function saveAssistantSettings() { assistantForm.put('/configuracion/asistente', {preserveScroll:true}); }
const assistantFields: {key:keyof typeof props.assistantRuntime.limits,label:string,description:string,unit:string}[] = [
  {key:'total_timeout_seconds',label:'Presupuesto de tiempo para el modelo',description:'Tiempo total disponible para la consulta desde que comienza. Se comprueba antes de cada llamada y limita la espera HTTP, pero no interrumpe una operación local ya iniciada.',unit:'segundos'},
  {key:'provider_timeout_seconds',label:'Tiempo máximo de cada llamada',description:'Tiempo máximo para una respuesta individual del proveedor o del modelo local. Los reintentos no añaden segundos extra.',unit:'segundos'},
  {key:'max_provider_calls',label:'Cantidad máxima de llamadas al modelo',description:'Incluye la interpretación de la solicitud y las respuestas con herramientas. Reducirlo ahorra consumo, pero puede impedir consultas complejas.',unit:'llamadas'},
  {key:'max_output_tokens',label:'Límite de respuesta del modelo',description:'Máximo de tokens de salida solicitados por llamada. No limita los datos de entrada, pero sí puede afectar costo y latencia.',unit:'tokens'},
];
function saveReportSettings() {reportForm.put(urls.reportSettings,{preserveScroll:true});}
const groups: {key: keyof MonitoringSettings, label: string, description: string, unit:string}[] = [
  {key:'metrics_interval_seconds',label:'Captura de métricas',description:'Frecuencia con la que el agente registra CPU, RAM y disco en cada equipo.',unit:'segundos'},
  {key:'dashboard_refresh_seconds',label:'Actualización del panel',description:'Intervalo de las consultas del navegador al servidor para refrescar la vista.',unit:'segundos'},
  {key:'heartbeat_interval_seconds',label:'Señal de actividad',description:'Frecuencia con la que cada agente informa que sigue activo.',unit:'segundos'},
  {key:'command_poll_seconds',label:'Consulta de órdenes',description:'Frecuencia con la que el agente consulta operaciones pendientes.',unit:'segundos'},
  {key:'retention_days',label:'Retención del historial',description:'Tiempo de conservación de las métricas detalladas en el servidor.',unit:'días'},
];
function save() {form.put(urls.settings, { preserveScroll:true });}
</script>
<template>
  <AppLayout>
    <Head title="Configuración"/>
    <div class="page-titlebar">
      <div>
        <div class="page-eyebrow"><SlidersHorizontal :size="14" /> Preferencias del sistema</div>
        <h1>Configuración</h1>
        <p>Ajusta frecuencias de monitoreo, horario visible e integración con informes y asistente IA.</p>
      </div>
    </div>

    <section class="surface-panel settings-overview" aria-label="Resumen de configuración">
      <div class="settings-overview-item">
        <div class="settings-overview-icon is-blue"><Clock3 :size="18" /></div>
        <div>
          <strong>Horario unificado</strong>
          <span>{{ selectedPlace }} · {{ timezonePreview }}</span>
        </div>
      </div>
      <div class="settings-overview-item">
        <div class="settings-overview-icon is-violet"><FileText :size="18" /></div>
        <div>
          <strong>Informes PDF</strong>
          <span>{{ reportForm.ai_enabled ? 'Conclusión asistida por IA activada' : 'Generación estándar sin IA' }}</span>
        </div>
      </div>
      <div class="settings-overview-item">
        <div class="settings-overview-icon is-amber"><Bot :size="18" /></div>
        <div>
          <strong>Asistente IA</strong>
          <span>{{ assistantRuntime.mode === 'local' ? 'Modelo local' : 'Proveedor externo' }} · {{ assistantRuntime.enabled ? 'Disponible' : 'Deshabilitado' }}</span>
        </div>
      </div>
    </section>

    <div class="settings-layout">
      <form class="surface-panel settings-card settings-card--timing" @submit.prevent="save">
        <div class="section-head settings-head"><div><h2><Clock3 :size="18" /> Intervalos de actualización</h2><p>Los agentes reciben estos cambios durante su próxima sincronización.</p></div></div>
        <div class="setting-list">
          <div v-for="group in groups" :key="group.key" class="setting-row">
            <div class="setting-copy"><label :for="group.key">{{ group.label }}</label><p>{{ group.description }}</p><small v-if="form.errors[group.key]" class="field-error" role="alert">{{ form.errors[group.key] }}</small></div>
            <div class="setting-control"><input :id="group.key" v-model.number="form[group.key]" type="number" step="1" :min="ranges[group.key][0]" :max="ranges[group.key][1]" required/><span>{{ group.unit }}</span></div>
          </div>
          <div v-if="form.metrics_interval_seconds <= 10" class="setting-row" role="status">
            <div class="setting-copy">
              <label>Captura intensiva para demostraciones</label>
              <p>Esta frecuencia aumenta las muestras almacenadas y la carga del servidor. Para ver cambios frecuentes, configura también «Actualización del panel» en 5 segundos. La lista de procesos continúa actualizándose cada 60 segundos.</p>
            </div>
          </div>
        </div>
        <div class="settings-actions"><span class="muted">Captura predeterminada recomendada: 60 segundos.</span><button type="submit" class="button button-primary" :disabled="form.processing || !form.isDirty"><Save :size="16"/>{{ form.processing?'Guardando…':'Guardar cambios' }}</button></div>
      </form>

      <aside class="settings-aside">
        <div class="tip-panel">
          <Info :size="19"/>
          <div><strong>Aplicación de cambios</strong><p>Los equipos desconectados mantendrán la última configuración válida y recibirán la nueva cuando vuelvan a comunicarse.</p></div>
        </div>
        <div class="tip-panel storage-panel">
          <div><strong>Almacenamiento de telemetría</strong>
            <p>{{ storage.sample_count.toLocaleString('es-AR') }} de {{ storage.sample_limit.toLocaleString('es-AR') }} muestras como máximo.</p>
            <p v-if="storage.database_bytes !== null">Historial almacenado: {{ (storage.database_bytes/1048576).toFixed(1) }} MiB · umbral {{ (storage.database_limit_bytes/1048576).toFixed(0) }} MiB.</p>
            <p v-else>El espacio ocupado por el historial no está disponible.</p>
            <p>El mantenimiento se ejecuta automáticamente en segundo plano.</p>
          </div>
        </div>
      </aside>
    </div>

    <section class="surface-panel report-config settings-card settings-card--timezone" aria-labelledby="display-timezone-title">
      <div class="section-head settings-head"><div><h2 id="display-timezone-title"><Clock3 :size="18" /> Fecha y hora</h2><p>Selecciona el lugar cuyo horario deseas usar en todo el sistema.</p></div></div>
      <form @submit.prevent="saveTimezone">
        <div class="timezone-body">
          <div class="timezone-fields">
            <label class="timezone-field" for="display-timezone-country">
              <span>País o región</span>
              <select id="display-timezone-country" v-model="selectedCountry" :disabled="timezoneForm.processing">
                <option v-for="country in countries" :key="country.code" :value="country.code">{{ country.label }}</option>
              </select>
            </label>
            <label class="timezone-field" for="display-timezone">
              <span>Ciudad o referencia horaria</span>
              <select id="display-timezone" v-model="timezoneForm.timezone" :disabled="timezoneForm.processing" required aria-describedby="timezone-example">
                <option v-for="zone in cityOptions" :key="zone.id" :value="zone.id">{{ zone.label }}</option>
              </select>
            </label>
          </div>
          <p id="timezone-example" class="timezone-preview" aria-live="off">
            <span>Hora actual en {{ selectedPlace }}</span>
            <strong>{{ timezonePreview }}</strong>
          </p>
          <small v-if="timezoneForm.errors.timezone" class="field-error" role="alert">{{ timezoneForm.errors.timezone }}</small>
          <p class="timezone-explanation">Este horario se aplica al panel, la actividad, los eventos USB, los procesos, las gráficas y los informes. Los registros originales continúan almacenándose en UTC.</p>
          <details class="timezone-technical-note"><summary>Cómo se interpreta el historial existente</summary>
            <p>Los eventos se guardan en UTC para conservar su orden y precisión. En el consumo de red, los días se reconstruyen a partir de mediciones horarias cuando es necesario. Si no existe detalle suficiente, se identifica la zona original usada para la agrupación histórica.</p>
          </details>
        </div>
        <div class="settings-actions"><span class="muted">Afecta a todos los administradores de esta instalación.</span>
          <button class="button button-primary" type="submit" :disabled="timezoneForm.processing || !timezoneForm.isDirty || !timezoneForm.timezone"><Save :size="16" />{{ timezoneForm.processing ? 'Guardando…' : 'Guardar horario' }}</button>
        </div>
      </form>
    </section>

    <section class="surface-panel report-config settings-card settings-card--reports" aria-labelledby="report-settings-title">
      <div class="section-head settings-head"><div><h2 id="report-settings-title"><FileText :size="18" /> Informes PDF</h2><p>La IA redacta la conclusión a partir de la telemetría y los eventos, sin modificar el diseño del documento.</p></div></div>
      <form @submit.prevent="saveReportSettings">
        <div class="report-config-body">
          <div class="report-toggle-row">
            <div class="report-toggle-copy">
              <label for="ai-conclusions">Conclusión y recomendaciones mediante IA</label>
              <p>Cuando está activa, la telemetría seleccionada se procesa con el motor configurado en el servidor. Si falla, el PDF se genera igualmente.</p>
            </div>
            <input id="ai-conclusions" v-model="reportForm.ai_enabled" class="report-checkbox" type="checkbox"
              :disabled="!reports.ai_configured && !reportForm.ai_enabled" aria-describedby="report-ai-provider" />
          </div>
          <div id="report-ai-provider" class="report-provider">
            <span class="report-provider-dot" :class="reports.ai_configured ? 'is-ready' : 'is-missing'" aria-hidden="true"></span>
            <span>Proveedor: <strong>{{ reports.provider === 'groq' ? 'Groq' : reports.provider === 'openai' ? 'OpenAI' : reports.provider === 'local' ? 'Modelo local' : 'OpenAI compatible' }}</strong></span>
            <span class="report-provider-separator" aria-hidden="true">·</span>
            <span>{{ reports.ai_configured ? (reports.provider === 'local' ? 'Modelo y motor local configurados' : 'Credenciales configuradas') : 'Falta configurar el modelo o las credenciales requeridas' }}</span>
          </div>
          <p v-if="reportForm.errors.ai_enabled" class="field-error" role="alert">{{ reportForm.errors.ai_enabled }}</p>
        </div>
        <div class="report-config-actions">
          <span class="muted">Estado: {{ reportForm.ai_enabled ? 'IA habilitada' : 'Solo informe PDF' }}</span>
          <button class="button button-primary" type="submit" :disabled="reportForm.processing || !reportForm.isDirty">
            <Save :size="16" />{{ reportForm.processing ? 'Guardando…' : 'Guardar configuración' }}
          </button>
        </div>
      </form>
    </section>

    <section class="surface-panel report-config settings-card settings-card--assistant" aria-labelledby="assistant-runtime-title">
      <div class="section-head settings-head"><div><h2 id="assistant-runtime-title"><Sparkles :size="18" /> Rendimiento del asistente IA</h2>
        <p>Configura los límites operativos del asistente sin alterar los intervalos del agente ni las conclusiones PDF.</p></div></div>
      <div class="report-config-body settings-runtime-summary">
        <p><strong>Modo actual:</strong> {{ assistantRuntime.mode === 'local' ? 'Modelo local en este servidor' : 'Proveedor externo mediante API' }}.
          {{ assistantRuntime.mode === 'local' ? 'La velocidad depende de los recursos disponibles y del modelo seleccionado.' : 'Puede implicar consumo facturado y latencia de Internet.' }}</p>
        <p class="muted">El modo y el modelo se eligen en la configuración protegida del servidor. Estos límites se guardan por separado para API remota y modelo local. Los valores de tokens pueden no estar disponibles si el proveedor no los informa.</p>
        <p v-if="!assistantRuntime.enabled" class="muted">El asistente está deshabilitado en el servidor; estos límites se aplicarán cuando se habilite.</p>
      </div>
      <form @submit.prevent="saveAssistantSettings">
        <div class="setting-list">
          <div v-for="field in assistantFields" :key="field.key" class="setting-row">
            <div class="setting-copy"><label :for="`assistant-${field.key}`">{{ field.label }}</label>
              <p>{{ field.description }}</p><small v-if="assistantForm.errors[field.key]" class="field-error" role="alert">{{ assistantForm.errors[field.key] }}</small></div>
            <div class="setting-control"><input :id="`assistant-${field.key}`" v-model.number="assistantForm[field.key]" type="number" step="1"
              :min="assistantRuntime.ranges[field.key]?.[0]" :max="assistantRuntime.ranges[field.key]?.[1]" required /><span>{{ field.unit }}</span></div>
          </div>
        </div>
        <div class="settings-actions"><span class="muted">Los límites se aplican a cada nueva consulta. Más tiempo no garantiza una respuesta mejor.</span>
          <button type="submit" class="button button-primary" :disabled="assistantForm.processing || !assistantForm.isDirty"><Save :size="16"/>{{ assistantForm.processing ? 'Guardando…' : 'Guardar límites de IA' }}</button></div>
      </form>
    </section>
  </AppLayout>
</template>
