<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { Paginated } from '@/types/nodo';

const props = defineProps<{
  pagination: Pick<Paginated<unknown>, 'links' | 'total' | 'current_page' | 'last_page'>;
}>();
const previousUrl = computed(() => props.pagination.links[0]?.url ?? null);
const nextUrl = computed(() => props.pagination.links[props.pagination.links.length - 1]?.url ?? null);
// Page numbers supplied by Laravel, excluding the first/last localization labels.
const pages = computed(() => props.pagination.links.slice(1, -1).filter(link => /^\d+$/.test(link.label)));
</script>
<template>
  <nav v-if="pagination.last_page > 1" aria-label="Paginación" class="pagination-row">
    <span class="muted">Página {{ pagination.current_page }} de {{ pagination.last_page }} · {{ pagination.total }} registros</span>
    <div class="pagination-actions">
      <span v-if="!previousUrl" class="pagination-button disabled" aria-disabled="true">Anterior</span>
      <Link v-else class="pagination-button" :href="previousUrl ?? '#'" preserve-scroll>Anterior</Link>
      <template v-for="page in pages" :key="page.label">
        <span v-if="page.active" class="pagination-button current" aria-current="page">{{ page.label }}</span>
        <Link v-else-if="page.url" class="pagination-button" :href="page.url"
          :aria-label="`Ir a la página ${page.label}`" preserve-scroll>{{ page.label }}</Link>
      </template>
      <span v-if="!nextUrl" class="pagination-button disabled" aria-disabled="true">Siguiente</span>
      <Link v-else class="pagination-button" :href="nextUrl ?? '#'" preserve-scroll>Siguiente</Link>
    </div>
  </nav>
</template>
