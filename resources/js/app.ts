import { createApp, h, type DefineComponent } from 'vue';
import { createInertiaApp, router } from '@inertiajs/vue3';
import { setDisplayTimezone } from './Lib/displayTimezone';
import '../css/app.css';
import '../css/refinement.css';
import '../css/workspace.css';

// Keep page components lazy-loaded while returning the concrete Vue component
// type expected by Inertia 3 (not the generic helper's wider union type).
const pages = import.meta.glob<{ default: DefineComponent }>('./Pages/**/*.vue');

createInertiaApp({
  title: title => { const appName = document.querySelector('meta[name="application-name"]')?.getAttribute('content') || 'Nodo Control'; return title ? `${title} · ${appName}` : appName; },
  resolve: name => {
    const page = pages[`./Pages/${name}.vue`];
    if (!page) {
      throw new Error(`Página Inertia no encontrada: ${name}`);
    }
    return page().then(module => module.default);
  },
  setup({ el, App, props, plugin }) {
    setDisplayTimezone(props.initialPage.props.displayTimezone);
    router.on('navigate', event => setDisplayTimezone(event.detail.page.props.displayTimezone));
    createApp({ render: () => h(App, props) }).use(plugin).mount(el);
  },
  progress: { color: '#1757a5', showSpinner: false },
});
