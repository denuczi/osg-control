<script setup lang="ts">
import { computed, reactive } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Filter, Search, X, History, Funnel } from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import EmptyState from '@/Components/EmptyState.vue';
import Pagination from '@/Components/Pagination.vue';
import { actionName, dateTime, urls, type Paginated } from '@/types/nodo';

type Log = {
  id: number;
  event: string;
  event_label: string;
  device_name: string | null;
  device_uuid: string | null;
  actor: string;
  action: string | null;
  summary: string | null;
  message_text: string | null;
  detail: string | null;
  result_message: string | null;
  created_at: string | null;
};

type Option = { value: string; label: string };
type Filters = { q: string; category: string; event: string; device: string; actor: string };

const props = defineProps<{
  logs: Paginated<Log>;
  filters: Filters;
  eventOptions: Option[];
  deviceOptions: Option[];
  actorOptions: Option[];
}>();

const form = reactive<Filters>({
  q: props.filters.q ?? '',
  category: props.filters.category ?? 'important',
  event: props.filters.event ?? '',
  device: props.filters.device ?? '',
  actor: props.filters.actor ?? '',
});

const hasActiveFilters = computed(() =>
  Boolean(form.q.trim() || form.category !== 'important' || form.event || form.device || form.actor),
);

function submit(): void {
  router.get(urls.activity, {
    q: form.q.trim() || undefined,
    category: form.category,
    event: form.event || undefined,
    device: form.device || undefined,
    actor: form.actor || undefined,
  }, {
    preserveState: true,
    preserveScroll: true,
    replace: true,
  });
}

function clearFilters(): void {
  form.q = '';
  form.category = 'important';
  form.event = '';
  form.device = '';
  form.actor = '';
  submit();
}

function onSearchKeydown(event: KeyboardEvent): void {
  if (event.key === 'Enter') {
    event.preventDefault();
    submit();
  }
}
</script>

<template>
  <AppLayout>
    <Head title="Actividad" />

    <div class="page-titlebar activity-page-title">
      <div>
        <div class="page-eyebrow"><History :size="14" /> Auditoría operativa</div>
        <h1>Actividad</h1>
        <p>Historial de operaciones administrativas, informes generados y resultados relevantes del sistema.</p>
      </div>
    </div>

    <section class="surface-panel activity-filters-panel" aria-labelledby="activity-filters-title">
      <div class="section-head toolbar-head activity-toolbar-head">
        <div>
          <h2 id="activity-filters-title"><Funnel :size="17" /> Filtros de actividad</h2>
          <p>Busca por texto libre y acota por evento, equipo o actor sin perder contexto.</p>
        </div>
      </div>

      <form class="activity-filters" @submit.prevent="submit">
        <div class="search-field activity-search-field">
          <Search :size="17" />
          <input
            v-model="form.q"
            type="search"
            aria-label="Buscar en la actividad"
            placeholder="Buscar por evento, equipo, actor o detalle"
            @keydown="onSearchKeydown"
          />
          <button v-if="form.q" type="button" aria-label="Limpiar búsqueda" class="icon-button" @click="form.q = ''">
            <X :size="15" />
          </button>
        </div>

        <label class="filter-field">
          <span>Categoría</span>
          <select v-model="form.category">
            <option value="important">Sin solicitudes de PDF</option>
            <option value="all">Todos los eventos</option>
            <option value="reports">Informes</option>
            <option value="assistant">Asistente IA</option>
            <option value="commands">Órdenes remotas</option>
            <option value="devices">Equipos</option>
            <option value="settings">Configuración</option>
          </select>
        </label>

        <label class="filter-field">
          <span>Evento</span>
          <select v-model="form.event">
            <option value="">Todos</option>
            <option v-for="option in eventOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
          </select>
        </label>

        <label class="filter-field">
          <span>Equipo</span>
          <select v-model="form.device">
            <option value="">Todos</option>
            <option v-for="option in deviceOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
          </select>
        </label>

        <label class="filter-field">
          <span>Actor</span>
          <select v-model="form.actor">
            <option value="">Todos</option>
            <option v-for="option in actorOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
          </select>
        </label>

        <div class="activity-filter-actions">
          <button type="submit" class="button button-primary"><Filter :size="16" />Aplicar filtros</button>
          <button v-if="hasActiveFilters" type="button" class="button button-secondary" @click="clearFilters">Limpiar</button>
        </div>
      </form>
    </section>

    <section class="surface-panel activity-panel">
      <div class="section-head activity-results-head">
        <div>
          <h2>Eventos registrados</h2>
          <p>{{ logs.total }} {{ logs.total === 1 ? 'registro encontrado' : 'registros encontrados' }}<template v-if="form.category === 'important' && !form.event"> · Ocultando solicitudes repetitivas de informes e inventarios</template></p>
        </div>
        <div class="activity-head-pill">{{ hasActiveFilters ? 'Vista filtrada' : 'Vista general' }}</div>
      </div>

      <EmptyState
        v-if="!logs.data.length"
        title="No hay registros para los filtros elegidos"
        description="Prueba con otros filtros o amplía la búsqueda para ver más actividad." />

      <div v-else class="table-scroll activity-table-scroll">
        <table class="data-table activity-table">
          <thead>
            <tr>
              <th>Fecha</th>
              <th>Evento</th>
              <th>Equipo</th>
              <th>Actor</th>
              <th>Detalle</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="log in logs.data" :key="log.id">
              <td class="nowrap cell-secondary activity-date-cell">{{ dateTime(log.created_at) }}</td>
              <td>
                <div class="activity-event-cell">
                  <strong class="item-title">{{ log.event_label }}</strong>
                  <span v-if="!log.event.startsWith('ai.')" class="activity-event-key">{{ log.event }}</span>
                </div>
              </td>
              <td>
                <Link v-if="log.device_uuid" class="table-link" :href="urls.device(log.device_uuid)">
                  {{ log.device_name || 'Equipo' }}
                </Link>
                <span v-else>—</span>
              </td>
              <td>{{ log.actor }}</td>
              <td class="activity-detail">
                <div v-if="log.summary" class="activity-summary">{{ log.summary }}</div>
                <div v-if="log.action" class="activity-meta-line"><strong>Acción:</strong> {{ actionName(log.action) }}</div>
                <div v-if="log.detail" class="activity-meta-line"><strong>Observación:</strong> {{ log.detail }}</div>
                <details v-if="log.message_text" class="activity-message">
                  <summary>Ver mensaje enviado</summary>
                  <p>{{ log.message_text }}</p>
                </details>
                <div v-if="log.result_message" class="activity-result"><strong>Resultado:</strong> {{ log.result_message }}</div>
                <span v-if="!log.summary && !log.action && !log.detail && !log.message_text && !log.result_message">—</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <Pagination :pagination="logs" />
    </section>
  </AppLayout>
</template>
