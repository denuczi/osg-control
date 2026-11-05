<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ChevronDown, History } from '@lucide/vue';
import { actionName, commandName, dateTime, urls, type Command } from '@/types/nodo';

const props = defineProps<{ commands: Command[] }>();
const previewLimit = 4;
const expanded = ref(false);
const visibleCommands = computed(() => expanded.value ? props.commands : props.commands.slice(0, previewLimit));
</script>

<template>
  <section class="surface-panel recent-orders" aria-labelledby="recent-device-orders-title">
    <div class="section-head recent-orders-heading">
      <div>
        <h2 id="recent-device-orders-title"><History :size="17" aria-hidden="true" /> Órdenes recientes <span class="section-count">{{ commands.length }}</span></h2>
        <p>Estado y resultado de las últimas órdenes del equipo</p>
      </div>
      <div class="recent-orders-actions">
        <button v-if="commands.length > previewLimit" type="button" class="button button-secondary"
          :aria-expanded="expanded" aria-controls="recent-device-orders-list" @click="expanded = !expanded">
          {{ expanded ? 'Mostrar menos' : `Ver ${commands.length - previewLimit} más` }}
          <ChevronDown :size="16" aria-hidden="true" class="expand-chevron" :class="{ 'is-open': expanded }" />
        </button>
        <Link :href="urls.activity" class="quiet-link">Ver actividad completa</Link>
      </div>
    </div>
    <div v-if="!commands.length" class="empty-tiny"><p>Aún no hay órdenes para este equipo.</p></div>
    <ol v-else id="recent-device-orders-list" class="recent-orders-grid" :class="{ 'is-expanded': expanded }"
      :tabindex="expanded ? 0 : undefined" aria-label="Órdenes del equipo">
      <li v-for="command in visibleCommands" :key="command.uuid" class="recent-order-item">
        <div class="recent-order-description">
          <strong>{{ actionName(command.action) }}</strong>
          <small>{{ dateTime(command.created_at) }}</small>
          <details v-if="command.message_text" class="command-details">
            <summary>Ver mensaje enviado</summary><p>{{ command.message_text }}</p>
          </details>
          <details v-if="command.result_message" class="command-details">
            <summary>Ver resultado</summary><p>{{ command.result_message }}</p>
          </details>
        </div>
        <span class="command-status" :class="`status-${command.status}`">{{ commandName(command.status) }}</span>
      </li>
    </ol>
  </section>
</template>
