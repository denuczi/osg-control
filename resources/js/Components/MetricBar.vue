<script setup lang="ts">
import { computed } from 'vue';
import { numberPercent } from '@/types/nodo';
const props = defineProps<{ label: string; value: number | null; compact?: boolean }>();
const val = computed(() => Math.max(0, Math.min(100, Number(props.value ?? 0))));
const tone = computed(() => {
  if (props.value == null) return 'unknown';
  if (val.value >= 90) return 'critical';
  if (val.value >= 75) return 'warning';
  return 'healthy';
});
</script>
<template>
  <div class="metric-bar" :class="['metric-bar', {'compact':compact}, `is-${tone}`]">
    <div class="metric-bar-heading"><span>{{ label }}</span><strong>{{ numberPercent(value) }}</strong></div>
    <div class="metric-track" role="progressbar" :aria-label="label" :aria-valuenow="value ?? 0" aria-valuemin="0" aria-valuemax="100">
      <div class="metric-fill" :class="`is-${tone}`" :style="{width:`${val}%`}"></div>
    </div>
  </div>
</template>
