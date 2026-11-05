<script setup lang="ts">
import { computed, ref, onMounted, onUnmounted } from 'vue';
import { AlertTriangle, CheckCircle2, ShieldCheck } from '@lucide/vue';
import type { AssistantProposal } from '@/types/assistant';
import { formatClock, parseUtcTimestamp } from '@/Lib/displayTimezone';
const props = defineProps<{ proposal: AssistantProposal; submitting: boolean; confirmed: boolean; error: string }>();
const emit = defineEmits<{ confirm: []; cancel: [] }>();
const typedConfirmation = ref('');
const names: Record<AssistantProposal['action'], string> = {show_message:'Enviar mensaje',restart:'Reiniciar equipos',shutdown:'Apagar equipos'};
const dangerous = computed(() => props.proposal.action !== 'show_message');
const requiredWord = computed(() => props.proposal.action === 'shutdown' ? 'APAGAR' : 'REINICIAR');
const canConfirm = computed(() => !props.submitting && !props.confirmed && (!dangerous.value || typedConfirmation.value.trim() === requiredWord.value));
const nowMs = ref(Date.now());
let interval: ReturnType<typeof setInterval> | undefined;
onMounted(() => { interval = setInterval(() => { nowMs.value = Date.now(); }, 1000); });
onUnmounted(() => { if (interval) clearInterval(interval); });
const expired = computed(() => (parseUtcTimestamp(props.proposal.expires_at)?.getTime() ?? 0) <= nowMs.value);
</script>
<template>
  <section class="ai-proposal" aria-label="Propuesta de acción remota">
    <div class="ai-proposal-heading"><ShieldCheck :size="19"/><strong>{{ names[proposal.action] }}</strong><span>{{ proposal.recipients.length }} {{ proposal.recipients.length === 1 ? 'equipo' : 'equipos' }}</span></div>
    <div v-if="confirmed" class="ai-approved"><CheckCircle2 :size="17"/> Órdenes registradas. La ejecución se verificará por equipo.</div>
    <template v-else>
      <p class="ai-note">La IA preparó esta operación, pero todavía no se envió ninguna orden. Verificá los destinatarios antes de autorizar.</p>
      <ul class="ai-recipient-list"><li v-for="device in proposal.recipients" :key="device.uuid">{{ device.name }} <small>{{ device.uuid }}</small></li></ul>
      <div v-if="proposal.message" class="ai-message-preview"><strong>Mensaje:</strong><p>{{ proposal.message }}</p></div>
      <div v-if="dangerous" class="ai-danger"><AlertTriangle :size="17"/><span>Esta operación puede interrumpir el trabajo y provocar pérdida de cambios no guardados.</span></div>
      <label v-if="dangerous" class="ai-confirm-label">Escribe <strong>{{ requiredWord }}</strong> para confirmar
        <input v-model="typedConfirmation" type="text" autocomplete="off" :placeholder="requiredWord" />
      </label>
      <p class="ai-note">La propuesta vence en {{ formatClock(proposal.expires_at) }}. El sistema verificará nuevamente el estado de los equipos.</p>
      <p v-if="error" role="alert" class="field-error">{{ error }}</p>
      <div class="ai-proposal-actions"><button type="button" class="button button-secondary" :disabled="submitting" @click="emit('cancel')">Descartar</button>
        <button type="button" :class="['button', dangerous ? 'button-danger' : 'button-primary']" :disabled="!canConfirm || expired" @click="emit('confirm')">{{ submitting ? 'Registrando…' : 'Autorizar operación' }}</button></div>
    </template>
  </section>
</template>
