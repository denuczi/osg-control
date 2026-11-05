import { onMounted, onUnmounted, ref, toValue, type MaybeRefOrGetter, type Ref } from 'vue';

/** Independent JSON polling. Avoids Inertia navigation and overlaps. */
export function useRemotePolling<T>(
  url: MaybeRefOrGetter<string>, defaultSeconds: number, onData: (data: T) => void,
  isValid?: (value: unknown) => value is T,
) {
  const error = ref(false);
  const busy = ref(false);
  const lastUpdated: Ref<Date | null> = ref(null);
  let timer: ReturnType<typeof setTimeout> | undefined;
  let controller: AbortController | undefined;
  let destroyed = false;
  let nextSeconds = Math.max(5, defaultSeconds);

  const schedule = (seconds = nextSeconds) => {
    clearTimeout(timer);
    if (!destroyed) timer = setTimeout(() => void fetchLatest(), Math.min(300, Math.max(5, seconds)) * 1000);
  };
  async function fetchLatest() {
    if (destroyed || busy.value) return;
    if (document.visibilityState === 'hidden') { schedule(); return; }
    busy.value = true;
    controller = new AbortController();
    const timeout = setTimeout(() => controller?.abort(), 15000);
    try {
      const response = await fetch(toValue(url), {
        signal: controller.signal,
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        cache: 'no-store',
      });
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      const raw: unknown = await response.json();
      if (!raw || typeof raw !== 'object' || Array.isArray(raw) ||
          (isValid && !isValid(raw))) throw new Error('Contrato de respuesta inválido');
      const data = raw as T & { next_refresh_seconds?: number };
      if (destroyed) return;
      onData(data);
      error.value = false;
      lastUpdated.value = new Date();
      if (typeof data.next_refresh_seconds === 'number' && Number.isFinite(data.next_refresh_seconds)) {
        nextSeconds = Math.min(300, Math.max(5, data.next_refresh_seconds));
      }
    } catch {
      if (!destroyed) error.value = true;
    } finally {
      clearTimeout(timeout);
      controller = undefined;
      busy.value = false;
      schedule(error.value ? Math.max(nextSeconds, 15) : nextSeconds);
    }
  }
  const onVisible = () => { if (document.visibilityState === 'visible') { clearTimeout(timer); void fetchLatest(); } };
  onMounted(() => { schedule(); document.addEventListener('visibilitychange', onVisible); });
  onUnmounted(() => { destroyed = true; clearTimeout(timer); controller?.abort(); document.removeEventListener('visibilitychange', onVisible); });
  return { error, busy, lastUpdated, refresh: fetchLatest };
}
