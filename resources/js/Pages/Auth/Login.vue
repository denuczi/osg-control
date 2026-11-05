<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ArrowRight } from '@lucide/vue';
const form = useForm({ email:'', password:'' });
function login() { form.post('/login', { onFinish:()=>form.reset('password') }); }
</script>
<template>
  <div class="login-screen"><Head title="Iniciar sesión"/><main class="login-shell"><section class="login-card" aria-labelledby="login-title"><div class="login-brand"><img src="/assets/osg-security.png" alt="OSG Security" class="login-brand-image"/><span class="login-product">Nodo Control</span></div><div class="login-header"><h1 id="login-title">Iniciar sesión</h1><p>Accede a la administración de tus equipos.</p></div><form @submit.prevent="login" class="login-form"><div class="form-field"><label for="email">Correo electrónico</label><input id="email" v-model="form.email" type="email" autocomplete="username" required autofocus/><span v-if="form.errors.email" class="field-error">{{ form.errors.email }}</span></div><div class="form-field"><label for="password">Contraseña</label><input id="password" v-model="form.password" type="password" autocomplete="current-password" required/><span v-if="form.errors.password" class="field-error">{{ form.errors.password }}</span></div><button class="button button-primary login-submit" type="submit" :disabled="form.processing"><span>{{ form.processing?'Ingresando…':'Ingresar' }}</span><ArrowRight :size="17"/></button></form></section></main></div>
</template>
