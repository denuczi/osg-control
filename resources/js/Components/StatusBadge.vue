<script setup lang="ts">
import { computed } from 'vue';
const props = defineProps<{ online: boolean; demo?: boolean }>();
const label = computed(() => props.demo ? 'Demostración' : props.online ? 'En línea' : 'Desconectado');
</script>
<template>
  <span class="status-label" :class="demo ? 'is-demo' : online ? 'is-online' : 'is-offline'">
    <span class="status-dot" aria-hidden="true"></span>{{ label }}
  </span>
</template>
