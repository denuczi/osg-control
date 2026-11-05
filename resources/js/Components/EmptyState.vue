<script setup lang="ts">
import { Monitor } from '@lucide/vue';
withDefaults(defineProps<{ title: string; description?: string }>(), { description: '' });
</script>
<template>
  <div class="empty-state"><Monitor :size="28" :stroke-width="1.5" aria-hidden="true"/><h3>{{ title }}</h3><p v-if="description">{{ description }}</p><slot /></div>
</template>
