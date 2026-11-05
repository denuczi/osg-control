export type Device = {
  uuid: string;
  name: string;
  hostname: string;
  platform: string;
  demo: boolean;
  online: boolean;
  cpu: number | null;
  memory: number | null;
  disk: number | null;
  memory_total_bytes: number | null;
  memory_used_bytes: number | null;
  memory_available_bytes: number | null;
  disk_total_bytes: number | null;
  disk_used_bytes: number | null;
  disk_free_bytes: number | null;
  network_rx_bps: number | null;
  network_tx_bps: number | null;
  inventory?: DeviceInventory | null;
  media_events?: MediaEvent[];
  last_seen: string | null;
  last_sampled: string | null;
  processes_sampled_at?: string | null;
  processes_total_count?: number | null;
  processes?: Process[];
};
export type ProcessInformation = {
  known: boolean;
  source: 'catalog' | 'ai' | 'none' | 'unavailable';
  description: string | null;
};
export type Process = {
  pid: number;
  name: string;
  memory_mb: number;
  started_at: number;
  can_terminate: boolean;
  display_name?: string | null;
  group_key?: string | null;
};
export type DeviceAction = 'show_message' | 'shutdown' | 'restart' | 'terminate_process';
export type BulkAction = Exclude<DeviceAction, 'terminate_process'>;
export type Command = {
  uuid: string;
  action: string;
  status: 'pending' | 'dispatched' | 'completed' | 'failed' | 'unknown' | 'expired';
  device: string | null;
  created_at: string | null;
  result_message: string | null;
  message_text?: string | null;
};
export type Paginated<T> = {
  data: T[];
  current_page: number;
  last_page: number;
  total: number;
  links: { url: string | null; label: string; active: boolean }[];
};
export type MonitoringSettings = {
  metrics_interval_seconds: number;
  dashboard_refresh_seconds: number;
  heartbeat_interval_seconds: number;
  command_poll_seconds: number;
  retention_days: number;
};
export const urls = {
  dashboard: '/panel',
  assistant: '/asistente',
  assistantAsk: '/asistente/consultar',
  assistantActionConfirm: (uuid: string) => `/asistente/propuestas/${encodeURIComponent(uuid)}/confirmar`,
  assistantActionCancel: (uuid: string) => `/asistente/propuestas/${encodeURIComponent(uuid)}/cancelar`,
  status: '/panel/estado',
  devices: '/equipos',
  deviceManagement: '/equipos/administracion',
  deviceStatuses: '/equipos/estados',
  activity: '/actividad',
  settings: '/configuracion',
  usage: '/consumo-red',
  usageData: '/consumo-red/datos',
  deviceUsage: (uuid: string) => `/equipos/${encodeURIComponent(uuid)}/consumo-red`,
  deviceDiagnostics: (uuid: string) => `/equipos/${encodeURIComponent(uuid)}/diagnostico`,
  device: (uuid: string) => `/equipos/${encodeURIComponent(uuid)}`,
  deviceStatus: (uuid: string) => `/equipos/${encodeURIComponent(uuid)}/estado`,
  deviceLightStatus: (uuid: string) => `/equipos/${encodeURIComponent(uuid)}/estado/ligero`,
  deviceMetrics: (uuid: string) => `/equipos/${encodeURIComponent(uuid)}/metricas`,
  deviceProcessKnowledge: (uuid: string) => `/equipos/${encodeURIComponent(uuid)}/procesos/informacion`,
  deviceMedia: (uuid: string) => `/equipos/${encodeURIComponent(uuid)}/medios`,
  bulkCommand: '/equipos/ordenes/masivas',
  reportCreate: (uuid: string) => `/equipos/${encodeURIComponent(uuid)}/informes`,
  reportStatus: (uuid: string) => `/informes/${encodeURIComponent(uuid)}`,
  reportSettings: '/configuracion/informes',
  fleetReportCreate: '/equipos/inventario-pdf',
  fleetReportStatus: (uuid: string) => `/equipos/inventarios/${encodeURIComponent(uuid)}`, 
};
export function numberPercent(value: number | null | undefined): string {
  return value == null ? '—' : `${value.toLocaleString('es-AR', { maximumFractionDigits: 1 })} %`;
}
export { formatDateTime as dateTime } from '@/Lib/displayTimezone';
export function actionName(action: string): string {
  return ({shutdown:'Apagar equipo', restart:'Reiniciar equipo', show_message:'Mostrar mensaje', terminate_process:'Finalizar proceso'} as Record<string,string>)[action] ?? action;
}
export function commandName(status: string): string {
  return ({pending:'Pendiente', dispatched:'Enviada', running:'En ejecución', completed:'Completada', failed:'Fallida', unknown:'Sin confirmar', expired:'Vencida'} as Record<string,string>)[status] ?? status;
}

export type DeviceVolume = {
  mountpoint: string; device: string; label: string | null; filesystem: string | null;
  total_bytes: number; used_bytes: number; free_bytes: number; used_percent: number;
  removable: boolean; media_class?: 'usb'|'optical'|'removable'|null; serial?:string|null; media_type?: string | null; bus_type?: string | null; model?: string | null;
};
export type NetworkInterface = {
  name: string; up: boolean; link_speed_mbps: number | null;
  ipv4: { address: string; netmask: string | null; subnet?: string | null }[];
  ipv6: string[]; mac: string | null;
};
export type DeviceInventory = {
  captured_at: string;
  processor: { name: string | null; physical_cores: number | null; logical_processors: number | null; max_mhz: number | null; current_mhz: number | null };
  volumes: DeviceVolume[];
  network: { default_gateway: string | null; dns_servers: string[]; ssid: string | null; interfaces: NetworkInterface[]; neighbors?: {ip:string;mac:string;state:string}[]; profiles?: {interface:string;name:string;category:string;ipv4_connectivity:string}[] };
};
export function formatGib(value: number | null | undefined): string {
  return value == null ? '—' : `${(value / (1024 ** 3)).toLocaleString('es-AR', {maximumFractionDigits: 2})} GiB`;
}
export function formatMbps(value: number | null | undefined): string {
  return value == null ? 'Sin medición' : `${(value / 1e6).toLocaleString('es-AR', {maximumFractionDigits:2})} Mbps`;
}

export type MediaEvent = { uuid:string; kind: 'connected'|'disconnected'|'detected'; observed_at:string; volume:DeviceVolume };
