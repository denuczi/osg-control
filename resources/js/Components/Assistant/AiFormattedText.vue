<script setup lang="ts">
import { computed } from 'vue';
import AiInlineText from '@/Components/Assistant/AiInlineText.vue';
import { parseAssistantMessage } from '@/Utils/assistantMarkup';

const props = defineProps<{ content: string }>();
const blocks = computed(() => parseAssistantMessage(props.content));
</script>

<template>
  <div class="ai-md">
    <template v-for="(block, index) in blocks" :key="index">
      <p v-if="block.kind === 'paragraph'" class="ai-md-paragraph"><AiInlineText :parts="block.parts" /></p>
      <h2 v-else-if="block.kind === 'heading' && block.level === 2" class="ai-md-heading"><AiInlineText :parts="block.parts" /></h2>
      <h3 v-else-if="block.kind === 'heading' && block.level === 3" class="ai-md-heading"><AiInlineText :parts="block.parts" /></h3>
      <h4 v-else-if="block.kind === 'heading'" class="ai-md-heading"><AiInlineText :parts="block.parts" /></h4>
      <ul v-else-if="block.kind === 'unordered'" class="ai-md-list">
        <li v-for="(item, itemIndex) in block.items" :key="itemIndex"><AiInlineText :parts="item" /></li>
      </ul>
      <ol v-else-if="block.kind === 'ordered'" class="ai-md-list" :start="block.start">
        <li v-for="(item, itemIndex) in block.items" :key="itemIndex"><AiInlineText :parts="item" /></li>
      </ol>
      <div v-else-if="block.kind === 'table'" class="ai-md-table-scroll">
        <table class="ai-md-table">
          <thead><tr><th v-for="(cell, column) in block.headers" :key="column"><AiInlineText :parts="cell" /></th></tr></thead>
          <tbody><tr v-for="(row, rowIndex) in block.rows" :key="rowIndex"><td v-for="(cell, column) in row" :key="column"><AiInlineText :parts="cell" /></td></tr></tbody>
        </table>
      </div>
      <pre v-else-if="block.kind === 'code'" class="ai-md-code"><code>{{ block.value }}</code></pre>
    </template>
  </div>
</template>
