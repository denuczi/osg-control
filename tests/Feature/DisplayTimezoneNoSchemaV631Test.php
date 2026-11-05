<?php

namespace Tests\Feature;

use App\Services\DisplayTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Deliberately NO RefreshDatabase: the report AI client is also called from
 * isolated tests that have never run migrations.
 */
final class DisplayTimezoneNoSchemaV631Test extends TestCase
{
    public function test_read_only_formatting_uses_configured_fallback_without_schema(): void
    {
        $this->assertFalse(Schema::hasTable('display_settings'));
        config()->set('reports.display_timezone', 'Europe/Madrid');

        $timezone = app(DisplayTimezone::class);

        $this->assertSame('Europe/Madrid', $timezone->current());
        $this->assertSame(
            '04/11/2026 02:26',
            $timezone->human(CarbonImmutable::parse('2026-11-04T01:26:00Z')),
        );
        $this->assertSame(
            '2026-11-04T02:26:00+01:00',
            $timezone->forModel(['last_seen_at' => '2026-11-04T01:26:00Z'])['last_seen_at'],
        );
    }

    public function test_invalid_fallback_uses_known_valid_default_without_schema(): void
    {
        $this->assertFalse(Schema::hasTable('display_settings'));
        config()->set('reports.display_timezone', 'This/DoesNotExist');

        $this->assertSame(DisplayTimezone::DEFAULT_ZONE, app(DisplayTimezone::class)->current());
    }

    public function test_writes_do_not_silently_succeed_when_settings_table_is_missing(): void
    {
        $this->assertFalse(Schema::hasTable('display_settings'));

        $this->expectException(QueryException::class);
        app(DisplayTimezone::class)->update('Europe/Madrid');
    }

    public function test_database_errors_are_not_hidden_by_fallback(): void
    {
        Schema::shouldReceive('hasTable')->once()
            ->with('display_settings')
            ->andThrow(new RuntimeException('Schema inspection failed'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Schema inspection failed');
        app(DisplayTimezone::class)->current();
    }
}
