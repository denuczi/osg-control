<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class CreateAdmin extends Command
{
    protected $signature = 'nodo:admin';

    protected $description = 'Crear o actualizar un administrador mediante consola';

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Este comando requiere una terminal interactiva y confirmación. No se guardó ningún cambio.');

            return self::FAILURE;
        }
        $name = trim((string) $this->ask('Nombre del administrador'));
        $email = strtolower(trim((string) $this->ask('Correo electrónico')));
        if ($name === '' || mb_strlen($name) > 255 || preg_match('/[\x00-\x1f\x7f]/', $name) ||
            strlen($email) > 255 || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Nombre o correo inválidos. No se guardó ningún cambio.');

            return self::FAILURE;
        }
        $existing = User::query()->where('email', $email)->first();
        $fields = ['id', 'name', 'email', 'password', 'is_admin'];
        $original = $existing?->only($fields);
        if ($existing) {
            $this->warn('El correo pertenece a una cuenta existente. Se cambiarán su nombre y contraseña.');
            if (! $existing->is_admin) {
                $this->warn('Esta cuenta también recibirá permisos de administrador.');
            }
        } else {
            $this->info('Se creará una cuenta administradora nueva. Las otras cuentas conservarán su acceso.');
        }
        // Disable visible-input fallback when the terminal cannot hide secrets.
        $password = (string) $this->secret('Contraseña (mínimo 12 caracteres)', false);
        if (mb_strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0")) {
            $this->error('La contraseña debe tener al menos 12 caracteres, hasta 72 bytes y ningún carácter nulo. No se guardó ningún cambio.');

            return self::FAILURE;
        }
        $confirmation = (string) $this->secret('Repite la contraseña', false);
        if (! hash_equals($password, $confirmation)) {
            $this->error('Las contraseñas no coinciden. No se guardó ningún cambio.');

            return self::FAILURE;
        }
        $this->table(['Acción', 'Nombre', 'Correo'], [[$existing ? 'Actualizar cuenta existente' : 'Crear cuenta nueva', $name, $email]]);
        $prompt = $existing ? '¿Actualizar esta cuenta administradora?' : '¿Crear esta cuenta administradora?';
        if (! $this->confirm($prompt, false)) {
            $this->info('Operación cancelada. No se guardó ningún cambio.');

            return self::FAILURE;
        }
        $hash = Hash::make($password);
        $saved = DB::transaction(function () use ($fields, $original, $name, $email, $hash): bool {
            $current = User::query()->where('email', $email)->lockForUpdate()->first();
            if ($current?->only($fields) !== $original) {
                return false;
            }
            $account = $current ?? new User;
            $account->fill(['email' => $email, 'name' => $name, 'password' => $hash, 'is_admin' => true])->save();

            return true;
        }, 3);
        if (! $saved) {
            $this->error('La cuenta cambió durante la operación. Vuelve a ejecutar el comando y revisar los datos. No se guardó ningún cambio.');

            return self::FAILURE;
        }
        $this->info($existing ? 'Administrador actualizado.' : 'Administrador creado.');

        return self::SUCCESS;
    }
}
