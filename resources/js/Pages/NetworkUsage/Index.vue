<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import NetworkUsagePanel from '@/Components/NetworkUsagePanel.vue';
</script>
<template>
  <AppLayout>
    <Head title="Consumo de red" />
    <div class="page-titlebar"><div><h1>Consumo de red</h1><p>Transferencia de datos registrada por las computadoras de la red.</p></div></div>
    <NetworkUsagePanel initial-period="month" :show-fleet="true" />
  </AppLayout>
</template>
