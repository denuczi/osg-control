import { onUnmounted, ref } from 'vue';
import { csrfHeaders } from '@/Utils/csrf';
import { urls } from '@/types/nodo';
import type { AssistantAnswer, ConversationMessage } from '@/types/assistant';

const MAX_HISTORY = 6;

// Correlation only: authorization remains bound to the authenticated Laravel session.
// Fallback supports HTTP LAN environments without crypto.randomUUID().
function newConversationId(): string {
  const bytes = new Uint8Array(16);
  if (typeof crypto !== 'undefined' && typeof crypto.getRandomValues === 'function') {
    crypto.getRandomValues(bytes);
  } else {
    for (let i = 0; i < bytes.length; i++) bytes[i] = Math.floor(Math.random() * 256);
  }
  bytes[6] = (bytes[6] & 0x0f) | 0x40;
  bytes[8] = (bytes[8] & 0x3f) | 0x80;
  const h = Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
  return `${h.slice(0,8)}-${h.slice(8,12)}-${h.slice(12,16)}-${h.slice(16,20)}-${h.slice(20)}`;
}

function requestError(status: number): string {
  if (status === 419) return 'La sesión cambió o caducó. Actualiza la página e inicia sesión nuevamente.';
  if (status === 429) return 'Se alcanzó el límite de consultas. Intentá nuevamente en un minuto.';
  if (status === 422) return 'La consulta no tiene un formato válido. Revisá el texto e intentá nuevamente.';
  if (status === 401 || status === 403) return 'Necesitás iniciar sesión como administrador.';
  if (status === 503) return 'El asistente no está disponible o no pudo completar la consulta.';
  return 'No se pudo completar la consulta. Verificá la conexión.';
}

export function useAiAssistant(deviceUuid?: string) {
  const messages = ref<ConversationMessage[]>([]);
  const loading = ref(false);
  const error = ref('');
  const conversationId = newConversationId();
  let nextId = 1;
  let controller: AbortController | null = null;
  let disposed = false;

  async function send(text: string): Promise<boolean> {
    const question = text.trim();
    if (!question || question.length > 1200 || loading.value) return false;
    error.value = '';
    const history = messages.value.slice(-MAX_HISTORY).map(({ role, content }) => ({ role, content: content.slice(0, 1200) }));
    messages.value.push({ id: nextId++, role: 'user', content: question });
    loading.value = true;
    controller = new AbortController();
    try {
      const response = await fetch(urls.assistantAsk, {
        method: 'POST', credentials: 'same-origin', redirect: 'error',
        signal: controller.signal,
        headers: {
          Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest',
          ...csrfHeaders(document.cookie),
        },
        body: JSON.stringify({ question, history, conversation_id: conversationId, ...(deviceUuid ? { device_uuid: deviceUuid } : {}) }),
      });
      if (!response.ok) {
        if (response.status === 503) {
          const payload = await response.json().catch(() => null) as {error?: unknown} | null;
          if (typeof payload?.error === 'string' && payload.error.length <= 300) throw new Error(payload.error);
        }
        throw new Error(requestError(response.status));
      }
      const data = await response.json() as AssistantAnswer;
      if (disposed) return false;
      if (typeof data.answer !== 'string' || !Array.isArray(data.evidence)) throw new Error('La respuesta recibida no es válida.');
      messages.value.push({ id: nextId++, role: 'assistant', content: data.answer, evidence: data.evidence, proposal: data.proposal, report: data.report });
      return true;
    } catch (err) {
      if (!disposed) {
        messages.value = messages.value.filter(item => item.id !== nextId - 1);
        error.value = err instanceof Error && err.name !== 'AbortError' ? err.message : 'Consulta interrumpida.';
      }
      return false;
    } finally {
      if (!disposed) loading.value = false;
      controller = null;
    }
  }

  onUnmounted(() => { disposed = true; controller?.abort(); });
  return { messages, loading, error, send };
}
