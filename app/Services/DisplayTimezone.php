<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Single installation-wide presentation zone. Database timestamps and agent
 * telemetry always remain instants in UTC. No PHP/global timezone mutation.
 * Scoped per HTTP request / queue job so changes are visible on next request.
 */
final class DisplayTimezone
{
    private const KEY = 'timezone';
    public const DEFAULT_ZONE = 'America/Argentina/Cordoba';

    private ?string $selected = null;

    public static function identifiers(): array
    {
        return DateTimeZone::listIdentifiers(DateTimeZone::ALL);
    }

    /** Options grouped by the geographic country of each IANA timezone. */
    public static function choices(): array
    {
        $choices = [];
        foreach (self::identifiers() as $identifier) {
            $location = (new DateTimeZone($identifier))->getLocation();
            $country = is_array($location) ? ($location['country_code'] ?? 'ZZ') : 'ZZ';
            $choices[] = ['id' => $identifier, 'country' => preg_match('/^[A-Z]{2}$/D', $country) && $country !== '??' ? $country : 'ZZ'];
        }

        return $choices;
    }

    public static function isAllowed(string $zone): bool
    {
        return in_array($zone, self::identifiers(), true);
    }

    public function current(): string
    {
        if ($this->selected !== null) {
            return $this->selected;
        }

        $fallback = (string) config('reports.display_timezone', self::DEFAULT_ZONE);
        if (! self::isAllowed($fallback)) {
            $fallback = self::DEFAULT_ZONE;
        }

        // Standalone AI/report tests may run without migrations. The schema
        // check also permits read-only use during an upgrade before this new
        // table is created. Do not catch database exceptions here: connection
        // failures and malformed queries must remain visible to operators.
        if (! Schema::hasTable('display_settings')) {
            return $this->selected = $fallback;
        }

        $stored = DB::table('display_settings')->where('key', self::KEY)->value('value');

        return $this->selected = is_string($stored) && self::isAllowed($stored)
            ? $stored
            : $fallback;
    }

    public function update(string $zone): void
    {
        if (! self::isAllowed($zone)) {
            throw ValidationException::withMessages(['timezone' => 'Selecciona una zona horaria válida.']);
        }
        $now = now();
        DB::table('display_settings')->upsert([
            ['key' => self::KEY, 'value' => $zone, 'created_at' => $now, 'updated_at' => $now],
        ], ['key'], ['value', 'updated_at']);
        $this->selected = $zone;
    }

    /** Explicit offset + IANA zone for humans; never claim a UTC event was local. */
    public function label(?DateTimeInterface $reference = null): string
    {
        $date = $reference ? CarbonImmutable::instance($reference) : CarbonImmutable::now('UTC');
        $zone = $this->current();
        return $zone.' (UTC'.$date->setTimezone($zone)->format('P').')';
    }

    /** Formats a known UTC instant; does not infer dates from user text. */
    public function human(DateTimeInterface $date): string
    {
        return CarbonImmutable::instance($date)->setTimezone($this->current())->format('d/m/Y H:i');
    }

    /**
     * Only for the LLM's tool result, not the API payload or stored evidence:
     * localize ISO timestamps with explicit offsets; leave calendar dates,
     * UUIDs, labels and counts untouched. Recurses through bounded tool data.
     */
    public function forModel(array $result, ?string $snapshotZone = null): array
    {
        // Queued reports must use the zone captured with their dataset, even if
        // an administrator changes the setting while the job is running.
        $zone = $snapshotZone !== null && self::isAllowed($snapshotZone) ? $snapshotZone : $this->current();
        $localized = $this->localize($result, $zone);
        $localized['display_timezone'] = $zone;
        $localized['timestamp_note'] = 'Las fechas ISO completas con hora ya están convertidas e incluyen offset. Los días de calendario y las claves horarias de series agregadas no se convirtieron. No aplicar una segunda conversión.';
        return $localized;
    }

    private function localize(array $data, string $zone): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->localize($value, $zone);
            } elseif (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})?$/D', $value)) {
                // DateTime parsing can normalize impossible input dates. Reject
                // these rather than silently presenting an invented instant.
                if (! checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))
                    || (int) substr($value, 11, 2) > 23 || (int) substr($value, 14, 2) > 59
                    || (int) substr($value, 17, 2) > 59) continue;
                try {
                    $data[$key] = CarbonImmutable::parse($value, 'UTC')->setTimezone($zone)->format('Y-m-d\TH:i:sP');
                } catch (\Throwable) {
                    // Invalid dates remain unchanged; never fabricate an instant.
                }
            }
        }
        return $data;
    }
}
