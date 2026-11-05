<?php

namespace App\Services\Reports;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Throwable;

/** Human-readable PDF projection. Raw telemetry and the AI context remain unchanged. */
final class ReportPresentation
{
    private const MONTHS = [
        1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
        'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre',
    ];

    private readonly DateTimeZone $timezone;

    public function __construct(string $timezone = \App\Services\DisplayTimezone::DEFAULT_ZONE)
    {
        $this->timezone = new DateTimeZone($timezone);
    }

    public function timezoneLabel(mixed $reference = null): string
    {
        $date = ($this->parseDate($reference) ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone($this->timezone);
        $offset = $date->format('P');
        $country = str_starts_with($this->timezone->getName(), 'America/Argentina/')
            ? 'Hora de Argentina' : 'Hora local ('.$this->timezone->getName().')';

        return $country.' (UTC'.$offset.')';
    }

    public function firewallProfile(mixed $value): string
    {
        return match (strtolower((string) ($value ?? ''))) {
            'domain' => 'Dominio', 'private' => 'Privado', 'public' => 'Público',
            default => 'Otro perfil',
        };
    }

    public function dateTime(mixed $value): string
    {
        $date = $this->parseDate($value);
        if ($date === null) {
            return 'No disponible';
        }
        $local = $date->setTimezone($this->timezone);

        return $local->format('j').' de '.self::MONTHS[(int) $local->format('n')]
            .' de '.$local->format('Y').', '.$local->format('H:i').' h';
    }

    public function shortDateTime(mixed $value): string
    {
        return $this->parseDate($value)?->setTimezone($this->timezone)->format('d/m/Y H:i') ?? 'No disponible';
    }

    /** For dates already grouped by calendar day, do not shift them across time zones. */
    public function calendarDay(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
            return 'No disponible';
        }
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));

        return $day !== false && $day->format('Y-m-d') === $value
            ? $day->format('d/m/Y') : 'No disponible';
    }

    public function period(mixed $start, mixed $end): string
    {
        return $this->shortDateTime($start).' a '.$this->shortDateTime($end).' h';
    }

    public function value(mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'No disponible';
        }
        if (is_bool($value)) {
            return $value ? 'Sí' : 'No';
        }
        if (! is_scalar($value)) {
            return 'No disponible';
        }

        return trim((string) $value) !== '' ? (string) $value : 'No disponible';
    }

    public function number(mixed $value, int $decimals = 0): string
    {
        if (! is_numeric($value) || ! is_finite((float) $value)) {
            return 'No disponible';
        }

        return number_format((float) $value, $decimals, ',', '.');
    }

    public function percent(mixed $value, int $decimals = 0): string
    {
        $number = $this->number($value, $decimals);

        return $number === 'No disponible' ? $number : $number.' %';
    }

    public function state(mixed $value, string $on = 'Activado', string $off = 'Desactivado'): string
    {
        return is_bool($value) ? ($value ? $on : $off) : 'No disponible';
    }

    public function bytesGiB(mixed $value): string
    {
        if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value < 0) {
            return 'No disponible';
        }

        return $this->number((float) $value / 1073741824, 2).' GiB';
    }

    public function action(mixed $value): string
    {
        return match ($value) {
            'shutdown' => 'Apagar equipo',
            'restart' => 'Reiniciar equipo',
            'show_message' => 'Mostrar mensaje al usuario',
            'terminate_process' => 'Finalizar proceso',
            default => 'Otra acción',
        };
    }

    public function commandStatus(mixed $value): string
    {
        return match ($value) {
            'pending' => 'Pendiente',
            'dispatched' => 'Enviada al equipo',
            'running' => 'En ejecución',
            'completed' => 'Completada',
            'failed' => 'Fallida',
            'expired' => 'Vencida',
            'unknown' => 'Sin confirmación',
            default => 'Estado no identificado',
        };
    }

    public function health(mixed $value): string
    {
        return match (strtolower(trim((string) ($value ?? '')))) {
            'healthy', 'ok' => 'Saludable',
            'warning', 'caution' => 'Precaución',
            'unhealthy', 'failed', 'critical' => 'Requiere atención',
            default => 'No disponible',
        };
    }

    public function mediaType(mixed $value): string
    {
        return match (strtolower(trim((string) ($value ?? '')))) {
            'ssd' => 'SSD', 'hdd' => 'Disco duro (HDD)', 'scm' => 'Memoria persistente',
            'unspecified', 'unknown', '' => 'Tipo no identificado',
            default => 'Tipo no identificado',
        };
    }

    public function eventLevel(mixed $value): string
    {
        return match (strtolower((string) ($value ?? ''))) {
            '1', 'critical' => 'Crítico', '2', 'error' => 'Error',
            '3', 'warning' => 'Advertencia', '4', 'information' => 'Información',
            '5', 'verbose' => 'Detallado',
            default => 'Sin clasificar',
        };
    }

    public function eventChannel(mixed $channel): string
    {
        return match (strtolower((string) $channel)) {
            'system' => 'Sistema', 'application' => 'Aplicaciones',
            'security' => 'Seguridad', default => 'Otro registro',
        };
    }

    /** Map only stable, known agent fields. Never iterate arbitrary JSON keys into the PDF. */
    public function processor(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $rows = [
            'Modelo' => $this->value($raw['name'] ?? null),
            'Núcleos físicos' => $this->number($raw['physical_cores'] ?? null),
            'Procesadores lógicos' => $this->number($raw['logical_processors'] ?? null),
            'Frecuencia máxima' => $this->mhz($raw['max_mhz'] ?? null),
            'Frecuencia al consultar' => $this->mhz($raw['current_mhz'] ?? null),
        ];

        return $rows;
    }

    /** Normalized printable network model, independent of the agent's JSON transport. */
    public function network(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }
        $interfaces = $this->records($raw['interfaces'] ?? null);
        $profiles = $this->records($raw['profiles'] ?? null);
        $neighbors = $this->records($raw['neighbors'] ?? null);
        $dns = is_array($raw['dns_servers'] ?? null)
            ? array_values(array_filter($raw['dns_servers'], static fn ($v) => is_string($v) && trim($v) !== '')) : [];

        return [
            'gateway' => $this->value($raw['default_gateway'] ?? null),
            'ssid' => $this->value($raw['ssid'] ?? null),
            'dns' => $dns ? implode(' · ', array_slice($dns, 0, 12)) : 'No disponible',
            'interfaces_total' => count($interfaces),
            'interfaces' => array_map(fn (array $item) => [
                'name' => $this->value($item['name'] ?? null),
                'status' => $this->state($item['up'] ?? null, 'Conectada', 'Desconectada'),
                'speed' => $this->mbps($item['link_speed_mbps'] ?? null),
                'mac' => $this->value($item['mac'] ?? null),
                'ipv4' => $this->ipList($item['ipv4'] ?? null),
                'ipv6' => $this->ipList($item['ipv6'] ?? null),
            ], array_slice($interfaces, 0, 16)),
            'profiles_total' => count($profiles),
            'profiles' => array_map(fn (array $item) => [
                'name' => $this->value($item['name'] ?? null),
                'interface' => $this->value($item['interface'] ?? null),
                'category' => $this->networkCategory($item['category'] ?? null),
                'connectivity' => $this->connectivity($item['ipv4_connectivity'] ?? null),
            ], array_slice($profiles, 0, 12)),
            'neighbors_total' => count($neighbors),
            'neighbors' => array_map(fn (array $item) => [
                'ip' => $this->value($item['ip'] ?? null),
                'mac' => $this->value($item['mac'] ?? null),
                'state' => $this->neighborState($item['state'] ?? null),
            ], array_slice($neighbors, 0, 10)),
        ];
    }


    /** Event IDs are meaningful only in conjunction with their provider and channel. */
    public function eventTitle(array $event): string
    {
        $id = (int) ($event['event_id'] ?? 0);
        $provider = strtolower((string) ($event['provider'] ?? ''));
        $channel = strtolower((string) ($event['channel'] ?? ''));
        $catalog = [
            41 => ['system', ['microsoft-windows-kernel-power', 'kernel-power'], 'Reinicio sin apagado correcto'],
            6008 => ['system', ['eventlog'], 'Apagado inesperado'],
            1074 => ['system', ['user32'], 'Reinicio o apagado solicitado'],
            13 => ['system', ['microsoft-windows-kernel-general'], 'Inicio de apagado registrado'],
            6006 => ['system', ['eventlog'], 'Apagado correcto registrado'],
            7 => ['system', ['disk'], 'Error del dispositivo de almacenamiento'],
            51 => ['system', ['disk'], 'Error de E/S durante paginación'],
            55 => ['system', ['ntfs', 'microsoft-windows-ntfs'], 'Error de estructura del sistema de archivos'],
            129 => ['system', ['storahci', 'stornvme', 'iastora', 'iastorac', 'storport'], 'Restablecimiento de controlador de almacenamiento'],
            153 => ['system', ['disk'], 'Reintento de entrada/salida de disco'],
            7045 => ['system', ['service control manager'], 'Instalación de servicio de Windows'],
            1000 => ['application', ['application error'], 'Fallo de una aplicación'],
            1002 => ['application', ['application hang'], 'Aplicación sin respuesta'],
        ];
        $item = $catalog[$id] ?? null;
        return $item && $item[0] === $channel && in_array($provider, $item[1], true)
            ? $item[2] : 'Evento de Windows ID '.$this->number($id);
    }

    public function eventSeverity(mixed $level): string
    {
        return match ((int) $level) {
            1 => 'Crítico', 2 => 'Error', 3 => 'Advertencia', 4 => 'Informativo',
            5 => 'Detallado', default => 'No clasificado',
        };
    }

    public function eventDetail(mixed $message): string
    {
        return is_string($message) && trim($message) !== ''
            ? trim($message)
            : 'Descripción no disponible. Los eventos anteriores a la actualización del agente no incluyen el mensaje de Windows.';
    }

    private function parseDate(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            // Stored timestamps without an explicit offset are UTC in this project.
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }

    private function mhz(mixed $value): string
    {
        return is_numeric($value) && (float) $value > 0 ? $this->number($value).' MHz' : 'No disponible';
    }

    private function mbps(mixed $value): string
    {
        return is_numeric($value) && (float) $value > 0 ? $this->number($value).' Mb/s' : 'No disponible';
    }

    private function records(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    private function ipList(mixed $list): string
    {
        if (! is_array($list)) {
            return 'No disponible';
        }
        $values = [];
        foreach (array_slice($list, 0, 8) as $entry) {
            $ip = is_array($entry) ? ($entry['address'] ?? null) : $entry;
            if (is_string($ip) && trim($ip) !== '') {
                $values[] = $ip;
            }
        }

        return $values ? implode(' · ', $values) : 'No disponible';
    }

    private function networkCategory(mixed $value): string
    {
        return match (strtolower((string) ($value ?? ''))) {
            'private', '1' => 'Privada', 'public', '0' => 'Pública',
            'domainauthenticated', '2' => 'Dominio autenticado',
            default => 'No informada',
        };
    }

    private function connectivity(mixed $value): string
    {
        return match (strtolower((string) ($value ?? ''))) {
            'internet', '4' => 'Acceso a Internet',
            'localnetwork', '3' => 'Solo red local',
            'subnet', '2' => 'Solo subred',
            'disconnected', '0' => 'Desconectada',
            'notraffic', '1' => 'Sin tráfico registrado',
            default => 'No determinada',
        };
    }

    private function neighborState(mixed $value): string
    {
        return match (strtolower((string) ($value ?? ''))) {
            'reachable', '5' => 'Accesible recientemente',
            'stale', '4' => 'Sin comprobación reciente',
            'permanent', '6' => 'Permanente',
            'delay', '3' => 'En espera de comprobación',
            'probe', '2' => 'Comprobando',
            'incomplete', '1' => 'Incompleto',
            'unreachable', '0' => 'No accesible',
            default => 'Estado no identificado',
        };
    }
}
