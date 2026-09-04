import { createApp, h, type DefineComponent } from 'vue'
import { createInertiaApp } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import '../css/app.css'

const appName = import.meta.env.VITE_APP_NAME ?? 'AnatoLab'

void createInertiaApp({
  title: (title) => (title ? `${title} · ${appName}` : appName),

  resolve: async (name) => {
    const pages = import.meta.glob<DefineComponent>('./Pages/**/*.vue')
    const page = pages[`./Pages/${name}.vue`]

    if (page === undefined) {
      throw new Error(`Inertia page not found: ./Pages/${name}.vue`)
    }

    const resolved = await page()

    // Every page gets the shell unless it opts out by setting its own layout.
    // Auth and error pages do; that is why this is a default, not a wrapper.
    resolved.default.layout ??= AppLayout

    return resolved
  },

  setup({ el, App, props, plugin }) {
    createApp({ render: () => h(App, props) })
      .use(plugin)
      .mount(el)
  },

  progress: {
    color: '#4f8ef7',
  },
})
