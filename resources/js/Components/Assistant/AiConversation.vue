<script setup lang="ts">
import { nextTick, ref, watch } from 'vue';
import { ArrowUp, Bot, LoaderCircle, ShieldCheck } from '@lucide/vue';
import { useAiAssistant } from '@/Composables/useAiAssistant';
import AiEvidence from '@/Components/Assistant/AiEvidence.vue';
import AiFormattedText from '@/Components/Assistant/AiFormattedText.vue';
import AiActionProposal from '@/Components/Assistant/AiActionProposal.vue';
import AiReportCard from '@/Components/Assistant/AiReportCard.vue';
import { csrfHeaders } from '@/Utils/csrf';
import { urls } from '@/types/nodo';
import type { AssistantProposal } from '@/types/assistant';

const props = defineProps<{ available: boolean; context?: { uuid: string; name: string } | null }>();
const { messages, loading, error, send } = useAiAssistant(props.context?.uuid);
const draft = ref('');
const draftInput = ref<HTMLTextAreaElement | null>(null);
function resizeDraft(): void {
  const input = draftInput.value;
  if (!input) return;
  input.style.height = 'auto';
  input.style.height = `${Math.min(input.scrollHeight, 160)}px`;
}

const scrollRegion = ref<HTMLElement | null>(null);
const submittingProposal = ref<string | null>(null);
const confirmed = ref<string[]>([]);
const discarded = ref<string[]>([]);
const proposalErrors = ref<Record<string, string>>({});
const suggestions = props.context
  ? [`¿Qué procesos consumen más memoria en ${props.context.name}?`, `¿Qué información tenemos de ${props.context.name}?`, '¿Cómo se compara este equipo con los demás?']
  : ['¿Cuántos equipos hay registrados?', '¿Qué computadora tiene más RAM?', '¿Qué procesos consumen más RAM en las computadoras?', '¿Cuáles tienen el disco principal más ocupado?'];

// Preserve the administrator's reading position when reviewing long responses.
// Only follow new content if already near the bottom or if the user sent a message.
watch(() => [messages.value.length, loading.value] as const, async ([count], [previousCount]) => {
  const region = scrollRegion.value;
  if (!region) return;

  const nearBottom = region.scrollHeight - region.scrollTop - region.clientHeight <= 96;
  const sentMessage = count > previousCount && messages.value[count - 1]?.role === 'user';

  await nextTick();
  if (scrollRegion.value !== region || (!nearBottom && !sentMessage)) return;
  region.scrollTop = region.scrollHeight;
});
function onComposerKeydown(event: KeyboardEvent): void {
  if (event.key !== 'Enter' || event.shiftKey || event.isComposing || event.ctrlKey || event.altKey || event.metaKey) return;
  event.preventDefault();
  void submit();
}
async function submit(value?: string): Promise<void> {
  const question = (value ?? draft.value).trim();
  if (!question || loading.value || !props.available) return;
  draft.value = '';
  await nextTick();
  resizeDraft();
  const ok = await send(question);
  if (!ok) { draft.value = question; await nextTick(); resizeDraft(); }
}
async function cancel(proposal: AssistantProposal): Promise<void> {
  if (submittingProposal.value || confirmed.value.includes(proposal.uuid)) return;
  submittingProposal.value = proposal.uuid;
  proposalErrors.value[proposal.uuid] = '';
  try {
    const response = await fetch(urls.assistantActionCancel(proposal.uuid), {
      method: 'POST', credentials: 'same-origin', redirect: 'error',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest', ...csrfHeaders(document.cookie) },
      body: JSON.stringify({}),
    });
    if (!response.ok) {
      if (response.status === 419) throw new Error('La sesión caducó. Actualiza la página.');
      if (response.status === 409) throw new Error('La propuesta ya fue utilizada y no se puede cancelar.');
      throw new Error('No se pudo descartar la propuesta en el servidor.');
    }
    const result = await response.json() as { status: string };
    if (result.status !== 'cancelled') throw new Error('Respuesta de cancelación no válida.');
    discarded.value = [...discarded.value, proposal.uuid];
  } catch (err) {
    proposalErrors.value[proposal.uuid] = err instanceof Error ? err.message : 'No se pudo cancelar.';
  } finally { submittingProposal.value = null; }
}
async function confirm(proposal: AssistantProposal): Promise<void> {
  if (submittingProposal.value || confirmed.value.includes(proposal.uuid)) return;
  submittingProposal.value = proposal.uuid;
  proposalErrors.value[proposal.uuid] = '';
  try {
    const response = await fetch(urls.assistantActionConfirm(proposal.uuid), {
      method: 'POST', credentials: 'same-origin', redirect: 'error',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest', ...csrfHeaders(document.cookie) },
      body: JSON.stringify({ confirm: true }),
    });
    if (!response.ok) {
      if (response.status === 419) throw new Error('La sesión caducó. Actualiza la página.');
      if (response.status === 410) throw new Error('La propuesta venció. Solicita una nueva.');
      if (response.status === 409) throw new Error('La propuesta ya no está disponible o el asistente fue deshabilitado. Solicita una nueva si corresponde.');
      if (response.status === 422) throw new Error('La operación ya no es válida. Algún equipo puede haberse desconectado.');
      if (response.status === 429) throw new Error('Hay demasiadas operaciones. Vuelve a intentarlo en un minuto.');
      throw new Error('No se pudo registrar la orden. Ninguna ejecución se confirmó.');
    }
    const result = await response.json() as { status: string; orders_registered: number };
    if (result.status !== 'registered') throw new Error('La respuesta de la operación no es válida.');
    confirmed.value = [...confirmed.value, proposal.uuid];
  } catch (err) {
    proposalErrors.value[proposal.uuid] = err instanceof Error ? err.message : 'No se pudo confirmar la propuesta.';
  } finally { submittingProposal.value = null; }
}
</script>
<template>
  <section class="ai-chat" aria-label="Conversación con OSG Nodo AI">
    <div ref="scrollRegion" class="ai-chat-history" role="log" aria-live="polite" aria-relevant="additions text">
      <div v-if="!messages.length" class="ai-welcome">
        <div class="ai-welcome-mark"><Bot :size="24" /></div>
        <h2>¿Qué necesitas saber de tus equipos?</h2>
        <p>Consulta información registrada, compara computadoras y prepara acciones con confirmación.</p>
        <div v-if="context" class="ai-context-label">Contexto actual: <strong>{{ context.name }}</strong></div>
        <div class="ai-suggestions">
          <button v-for="suggestion in suggestions" :key="suggestion" type="button" :disabled="!available" @click="submit(suggestion)">{{ suggestion }} <ArrowUp :size="15"/></button>
        </div>
      </div>
      <template v-for="message in messages" :key="message.id">
        <div :class="['ai-chat-message', message.role === 'user' ? 'ai-chat-user' : 'ai-chat-assistant']">
          <div v-if="message.role === 'assistant'" class="ai-chat-avatar"><Bot :size="16"/></div>
          <div class="ai-chat-bubble"><AiFormattedText v-if="message.role === 'assistant'" :content="message.content" /><template v-else>{{ message.content }}</template></div>
        </div>
        <div v-if="message.evidence?.length" class="ai-chat-addons">
          <AiEvidence v-for="(item, index) in message.evidence" :key="`${message.id}-${index}`" :evidence="item" />
        </div>
        <div v-if="message.report" class="ai-chat-addons">
          <AiReportCard :report="message.report" />
        </div>
        <div v-if="message.proposal && !discarded.includes(message.proposal.uuid)" class="ai-chat-addons">
          <AiActionProposal :proposal="message.proposal" :submitting="submittingProposal === message.proposal.uuid"
            :confirmed="confirmed.includes(message.proposal.uuid)" :error="proposalErrors[message.proposal.uuid] ?? ''"
            @confirm="confirm(message.proposal)" @cancel="cancel(message.proposal)" />
        </div>
      </template>
      <div v-if="loading" class="ai-chat-message ai-chat-assistant"><div class="ai-chat-avatar"><Bot :size="16"/></div>
        <div class="ai-chat-bubble ai-typing"><LoaderCircle :size="17" class="ai-spin"/> Consultando información…</div>
      </div>
    </div>
    <div class="ai-chat-composer">
      <p v-if="!available" class="notice notice-info" role="status">El asistente todavía no está habilitado. Un administrador debe configurar su proveedor de IA.</p>
      <p v-if="error" class="field-error" role="alert">{{ error }}</p>
      <form class="ai-compose-form" @submit.prevent="submit()">
        <label class="sr-only" for="assistant-question">Consulta al asistente</label>
        <textarea id="assistant-question" ref="draftInput" v-model="draft" rows="1" maxlength="1200" :disabled="loading || !available" @input="resizeDraft"
          placeholder="Escribe tu consulta…" @keydown="onComposerKeydown" />
        <button class="ai-send-button" type="submit" :disabled="loading || !available || !draft.trim()" aria-label="Enviar pregunta"><ArrowUp :size="19"/></button>
      </form>
      <div class="ai-chat-disclaimer"><ShieldCheck :size="13"/><span>Las respuestas se basan en capturas disponibles. Las acciones requieren aprobación y pueden interrumpir el trabajo.</span></div>
    </div>
  </section>
</template>
