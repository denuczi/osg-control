<script setup lang="ts">
import type { InlinePart } from '@/Utils/assistantMarkup';

defineProps<{ parts: InlinePart[] }>();
</script>

<template>
  <template v-for="(part, index) in parts" :key="index">
    <strong v-if="part.kind === 'strong'">{{ part.value }}</strong>
    <em v-else-if="part.kind === 'em'">{{ part.value }}</em>
    <code v-else-if="part.kind === 'code'">{{ part.value }}</code>
    <a v-else-if="part.kind === 'link'" :href="part.href" target="_blank" rel="noopener noreferrer nofollow">{{ part.value }}</a>
    <template v-else>{{ part.value }}</template>
  </template>
</template>
