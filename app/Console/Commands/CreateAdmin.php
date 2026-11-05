<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateAdmin extends Command
{
    protected $signature = 'nodo:admin';
    protected $description = 'Crear o actualizar un administrador mediante consola';

    public function handle(): int
    {
        $name = trim((string) $this->ask('Nombre del administrador'));
        $email = strtolower(trim((string) $this->ask('Correo electrónico')));
        $password = (string) $this->secret('Contraseña (mínimo 12 caracteres)');
        if (!$name || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12) {
            $this->error('Nombre, correo o contraseña inválidos.');
            return self::FAILURE;
        }
        User::query()->updateOrCreate(['email' => $email], ['name' => $name, 'password' => Hash::make($password), 'is_admin' => true]);
        $this->info('Administrador creado o actualizado.');
        return self::SUCCESS;
    }
}
