<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'New-admin-password-123';

    private function account(): User
    {
        return User::query()->create([
            'name' => 'Original', 'email' => 'admin@example.test',
            'password' => 'Original-password-123', 'is_admin' => true,
        ])->refresh();
    }

    private function inputs(string $email = 'admin@example.test', string $repeat = self::PASSWORD)
    {
        return $this->artisan('nodo:admin')
            ->expectsQuestion('Nombre del administrador', 'Nuevo nombre')
            ->expectsQuestion('Correo electrónico', $email)
            ->expectsQuestion('Contraseña (mínimo 12 caracteres)', self::PASSWORD)
            ->expectsQuestion('Repite la contraseña', $repeat)
            ->doesntExpectOutputToContain(self::PASSWORD);
    }

    public function test_creates_administrator_only_after_confirmation(): void
    {
        $this->inputs('New@Example.test')
            ->expectsTable(['Acción', 'Nombre', 'Correo'], [['Crear cuenta nueva', 'Nuevo nombre', 'new@example.test']])
            ->expectsConfirmation('¿Crear esta cuenta administradora?', 'yes')
            ->expectsOutput('Administrador creado.')
            ->assertSuccessful();

        $user = User::query()->sole();
        $this->assertSame('new@example.test', $user->email);
        $this->assertTrue($user->is_admin);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
        $this->assertNotSame(self::PASSWORD, $user->password);
    }

    public function test_updates_existing_account_with_explicit_confirmation(): void
    {
        $original = $this->account();
        $this->inputs()
            ->expectsOutput('El correo pertenece a una cuenta existente. Se cambiarán su nombre y contraseña.')
            ->expectsTable(['Acción', 'Nombre', 'Correo'], [['Actualizar cuenta existente', 'Nuevo nombre', $original->email]])
            ->expectsConfirmation('¿Actualizar esta cuenta administradora?', 'yes')
            ->expectsOutput('Administrador actualizado.')
            ->assertSuccessful();

        $this->assertDatabaseCount('users', 1);
        $this->assertSame('Nuevo nombre', $original->fresh()->name);
        $this->assertTrue(Hash::check(self::PASSWORD, $original->fresh()->password));
    }

    public function test_different_email_creates_account_without_replacing_original(): void
    {
        $original = $this->account();
        $before = $original->getAttributes();
        $this->inputs('another@example.test')
            ->expectsConfirmation('¿Crear esta cuenta administradora?', 'yes')
            ->assertSuccessful();

        $this->assertDatabaseCount('users', 2);
        $this->assertSame($before, $original->fresh()->getAttributes());
    }

    public function test_existing_regular_account_requires_explicit_promotion_confirmation(): void
    {
        $original = $this->account();
        $original->update(['is_admin' => false]);
        $this->inputs()
            ->expectsOutput('Esta cuenta también recibirá permisos de administrador.')
            ->expectsConfirmation('¿Actualizar esta cuenta administradora?', 'yes')
            ->assertSuccessful();

        $this->assertDatabaseCount('users', 1);
        $this->assertTrue($original->fresh()->is_admin);
    }

    public function test_cancelling_creation_does_not_create_any_account(): void
    {
        $this->inputs()
            ->expectsConfirmation('¿Crear esta cuenta administradora?', 'no')
            ->expectsOutput('Operación cancelada. No se guardó ningún cambio.')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_cancelling_update_preserves_password_and_profile(): void
    {
        $original = $this->account();
        $before = $original->getAttributes();
        $this->inputs()
            ->expectsConfirmation('¿Actualizar esta cuenta administradora?', 'no')
            ->assertFailed();

        $this->assertSame($before, $original->fresh()->getAttributes());
    }

    public function test_mismatched_passwords_preserve_existing_account(): void
    {
        $original = $this->account();
        $before = $original->getAttributes();
        $this->inputs(repeat: 'A-different-password-123')
            ->expectsOutput('Las contraseñas no coinciden. No se guardó ningún cambio.')
            ->assertFailed();

        $this->assertSame($before, $original->fresh()->getAttributes());
    }

    public function test_invalid_email_is_rejected_before_password_prompt(): void
    {
        $this->artisan('nodo:admin')
            ->expectsQuestion('Nombre del administrador', 'Admin')
            ->expectsQuestion('Correo electrónico', 'not-an-email')
            ->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_short_and_oversized_passwords_are_rejected(): void
    {
        foreach (['short', str_repeat('x', 73), str_repeat('é', 37)] as $password) {
            $this->artisan('nodo:admin')
                ->expectsQuestion('Nombre del administrador', 'Admin')
                ->expectsQuestion('Correo electrónico', 'admin@example.test')
                ->expectsQuestion('Contraseña (mínimo 12 caracteres)', $password)
                ->assertFailed();
        }
        $this->assertDatabaseCount('users', 0);
    }

    public function test_noninteractive_execution_never_creates_or_updates_accounts(): void
    {
        $original = $this->account();
        $before = $original->getAttributes();
        $this->artisan('nodo:admin', ['--no-interaction' => true])->assertFailed();
        $this->assertDatabaseCount('users', 1);
        $this->assertSame($before, $original->fresh()->getAttributes());
    }

    public function test_concurrent_profile_change_is_not_overwritten(): void
    {
        $original = $this->account();
        $hash = $original->password;
        $changed = false;
        DB::listen(function (QueryExecuted $query) use ($original, &$changed): void {
            if (! $changed && str_contains($query->sql, 'from "users"') && str_contains($query->sql, '"email"')) {
                $changed = true;
                User::query()->whereKey($original->id)->update(['name' => 'Concurrent change']);
            }
        });
        $this->inputs()
            ->expectsConfirmation('¿Actualizar esta cuenta administradora?', 'yes')
            ->expectsOutput('La cuenta cambió durante la operación. Vuelve a ejecutar el comando y revisar los datos. No se guardó ningún cambio.')
            ->assertFailed();

        $this->assertSame('Concurrent change', $original->fresh()->name);
        $this->assertSame($hash, $original->fresh()->password);
    }
}
