<script setup lang="ts">
import { computed, ref, onUnmounted } from 'vue';
import { Link, usePage, router } from '@inertiajs/vue3';
import { Activity, LayoutDashboard, Monitor, Settings2, LogOut, Menu, X, ChartColumnIncreasing, Bot } from '@lucide/vue';
import { urls } from '@/types/nodo';

withDefaults(defineProps<{ immersive?: boolean }>(), { immersive: false });

const mobileOpen = ref(false);
const page = usePage();
const appName = computed(() => String(page.props.appName || 'Nodo Control'));
const user = computed(() => (page.props.auth as { user?: { name: string; email: string } } | undefined)?.user);
const flash = computed(() => page.props.flash as { success?: string | null; error?: string | null } | undefined);
const navigation = [
  { href: urls.dashboard, label: 'Resumen', icon: LayoutDashboard },
  { href: urls.devices, label: 'Equipos', icon: Monitor },
  { href: urls.assistant, label: 'Asistente IA', icon: Bot },
  { href: urls.activity, label: 'Actividad', icon: Activity },
  { href: urls.usage, label: 'Consumo de red', icon: ChartColumnIncreasing },
  { href: urls.settings, label: 'Configuración', icon: Settings2 },
];
function active(href: string) { return href === urls.dashboard ? page.url.startsWith('/panel') : page.url.startsWith(href); }
function logout() { router.post('/logout'); }
const removeNavigationListener = router.on('navigate', () => { mobileOpen.value = false; });
onUnmounted(() => removeNavigationListener());
</script>
<template>
  <div class="app-shell" :class="{ 'app-shell--immersive': immersive }">
    <div v-if="mobileOpen" class="mobile-scrim" @click="mobileOpen=false"></div>
    <aside class="sidebar" :class="{'mobile-open': mobileOpen}" aria-label="Navegación principal">
      <div class="brand">
        <span class="brand-image-frame"><img src="/assets/osg-security.png" alt="OSG Security" class="brand-image" /></span>
        <span class="brand-product">Nodo Control</span>
        <button class="icon-button mobile-close" type="button" aria-label="Cerrar menú" @click="mobileOpen=false"><X :size="19"/></button>
      </div>
      <nav class="nav-list" aria-label="Secciones">
        <Link v-for="link in navigation" :key="link.href" :href="link.href" class="nav-item" :class="{'is-active': active(link.href)}" :aria-current="active(link.href) ? 'page' : undefined">
          <component :is="link.icon" :size="18" :stroke-width="1.8" aria-hidden="true" />
          <span>{{ link.label }}</span>
        </Link>
      </nav>
      <div class="sidebar-bottom">
        <div class="sidebar-user">
          <div class="avatar" aria-hidden="true">{{ user?.name?.charAt(0).toUpperCase() ?? 'A' }}</div>
          <div class="user-info"><strong>{{ user?.name ?? 'Administrador' }}</strong><span>{{ user?.email ?? '' }}</span></div>
        </div>
        <button class="nav-item logout-item" type="button" @click="logout"><LogOut :size="17" aria-hidden="true"/>Cerrar sesión</button>
      </div>
    </aside>
    <div class="app-main">
      <header class="topbar">
        <button class="icon-button show-mobile" type="button" aria-label="Abrir menú" @click="mobileOpen=true"><Menu :size="20"/></button>
        <span class="topbar-context">{{ appName }}</span>
        <span class="topbar-spacer"></span>
        <span class="topbar-user">{{ user?.name ?? 'Administrador' }}</span>
      </header>
      <main id="main" class="page-content" :class="{ 'page-content--immersive': immersive }">
        <div v-if="flash?.success" class="notice notice-success" role="status">{{ flash.success }}</div>
        <div v-if="flash?.error" class="notice notice-danger" role="alert">{{ flash.error }}</div>
        <slot />
      </main>
    </div>
  </div>
</template>
