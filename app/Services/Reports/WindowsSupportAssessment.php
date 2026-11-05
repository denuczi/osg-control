<?php

namespace App\Services\Reports;

use DateTimeImmutable;
use DateTimeInterface;

/** Read-only assessment from Microsoft lifecycle dates. No update or license activation. */
final class WindowsSupportAssessment
{
    public function evaluate(mixed $raw, ?DateTimeInterface $asOf = null): array
    {
        $today = DateTimeImmutable::createFromInterface($asOf ?? new DateTimeImmutable('now'));
        $data = is_array($raw) ? $raw : [];
        $edition = strtolower(trim((string) ($data['EditionId'] ?? '')));
        $build = filter_var($data['Build'] ?? null, FILTER_VALIDATE_INT);
        $build = $build === false ? null : $build;
        $release = trim((string) ($data['DisplayVersion'] ?? ''));
        $caption = trim((string) ($data['Caption'] ?? ''));
        $claimedYears = is_array($data['EsuLicensedYears'] ?? null) ? $data['EsuLicensedYears'] : [];
        $years = array_values(array_unique(array_filter($claimedYears, static fn ($v) => is_int($v) && $v >= 1 && $v <= 3)));
        sort($years);
        $commercialStatus = in_array($data['EsuCommercialStatus'] ?? null, ['licensed', 'not_detected', 'unknown', 'not_applicable'], true)
            ? $data['EsuCommercialStatus'] : 'unknown';

        if ($commercialStatus === 'licensed' && $years === []) {
            $commercialStatus = 'unknown';
        }

        $family = $build !== null && $build >= 22000 ? 'Windows 11'
            : ($build !== null && $build >= 10240 && $build < 22000 ? 'Windows 10' : 'No identificada');
        $supportEnd = null;
        $policy = 'No determinado';
        $status = 'unverified';

        if ($family === 'Windows 10') {
            // LTSC is identified by the edition ID, not by a vague platform label.
            $isLtsc = str_contains($edition, 'enterprises');
            $isIot = str_contains($edition, 'iot');
            $supportEnd = match (true) {
                $isLtsc && $build === 19044 => $isIot ? '2032-01-13' : '2027-01-12',
                $isLtsc && $build === 17763 => '2029-01-09',
                $isLtsc && $build === 14393 => '2026-10-13',
                $isLtsc && $build === 10240 => '2025-10-14',
                $build === 19045 => '2025-10-14',
                $edition !== '' && ! $isLtsc => '2025-10-14',
                default => null,
            };
            $policy = $isLtsc ? 'LTSC/LTSB' : ($supportEnd !== null ? 'Canal general' : 'Sin identificar');
            if ($supportEnd !== null) {
                $status = $today->format('Y-m-d') > $supportEnd ? 'support_ended' : 'supported';
            }
        }

        $summary = match ($status) {
            'supported' => 'La edición identificada se encuentra dentro de su ciclo de soporte publicado.',
            'support_ended' => 'Finalizó el soporte regular de la edición identificada. Verificar si dispone de cobertura ESU vigente.',
            default => 'No se puede determinar el soporte de esta edición con los datos disponibles.',
        };
        if ($family === 'Windows 11' && $status === 'unverified') {
            $summary = 'Windows 11 identificado. La vigencia depende de la edición y versión; no se ha evaluado aquí.';
        }
        // Expiry of the commercial ESU year is distinct from detecting the license.
        // The actual Windows Update patch state is not measured.
        $esuYearEnd = [1 => '2026-10-13', 2 => '2027-10-12', 3 => '2028-10-10'];
        $lastLicensedYear = $years ? max($years) : null;
        $expiredLicenseYear = $lastLicensedYear !== null
            && $today->format('Y-m-d') > $esuYearEnd[$lastLicensedYear];
        $esu = match ($commercialStatus) {
            'licensed' => 'Licencia comercial ESU detectada (año'.(count($years) > 1 ? 's ' : ' ').implode(', ', $years).'). '.($expiredLicenseYear ? 'La cobertura del último año identificado ya finalizó. Comprobar ampliación y parches instalados.' : 'Comprobar vigencia y parches instalados.'),
            'not_detected' => 'No se detectó licencia comercial ESU. Esta consulta no verifica inscripciones ESU de consumidores.',
            'not_applicable' => 'No corresponde a esta versión de Windows.',
            default => 'No se pudo verificar el estado de licencias comerciales ESU.',
        };
        return [
            'family' => $family,
            'caption' => $caption !== '' ? $caption : null,
            'edition_id' => $edition !== '' ? (string) ($data['EditionId'] ?? '') : null,
            'release' => $release !== '' ? $release : null,
            'build' => $build,
            'ubr' => is_int($data['UBR'] ?? null) ? $data['UBR'] : null,
            'policy' => $policy,
            'support_end' => $supportEnd,
            'status' => $status,
            'summary' => $summary,
            'commercial_esu_status' => $commercialStatus,
            'commercial_esu_years' => $years,
            'commercial_esu_summary' => $esu,
            'coverage_verified' => false, // Never infer installed security patches from licensing status.
            'interpretation' => 'Sin telemetría de instalación de parches ni comprobación de ESU de consumidores. No inferir protección efectiva.',
        ];
    }
}
