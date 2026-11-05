<script setup lang="ts">
import { computed } from 'vue';
import { numberPercent } from '@/types/nodo';
const props = defineProps<{ value: number | null | undefined; label: string }>();
const width = computed(() => Math.min(100, Math.max(0, props.value ?? 0)));
const tone = computed(() => {
  if (props.value == null) return 'unknown';
  if (props.value >= 85) return 'critical';
  if (props.value >= 70) return 'warning';
  return 'healthy';
});
</script>
<template>
  <div class="resource-cell" :aria-label="`${label}: ${numberPercent(value)}`">
    <span class="resource-value" :class="`is-${tone}`">{{ numberPercent(value) }}</span>
    <span class="resource-track" aria-hidden="true"><span class="resource-fill" :class="`is-${tone}`" :style="{ width: `${width}%` }"></span></span>
  </div>
</template>
