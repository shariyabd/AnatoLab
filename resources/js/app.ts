import { createApp, h, type DefineComponent } from 'vue'
import { createInertiaApp, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import '../css/app.css'

const appName = import.meta.env.VITE_APP_NAME ?? 'AnatoLab'

/**
 * Keep `<meta name="csrf-token">` in step with the session.
 *
 * app.blade.php renders that tag once, when the document loads. Logging in and
 * registering both regenerate the session — and both are Inertia visits, so the
 * document is never reloaded and the tag goes on carrying the token from before
 * the rotation. Every `fetch` in resources/js/composables reads it, so the
 * tutor, quizzes, missions, simulations, lesson progress and analytics all
 * start failing with 419 the moment a student signs in, and keep failing until
 * they happen to reload the page.
 *
 * `HandleInertiaRequests` shares the current token with every response, so the
 * fix is to write it back after each navigation. Done here, once, rather than
 * in the six composables that read the tag: they are right to read it, and the
 * tag was the thing that was wrong.
 */
function syncCsrfToken(token: unknown): void {
  if (typeof token !== 'string' || token === '') return

  const meta = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')

  if (meta !== null && meta.content !== token) {
    meta.content = token
  }
}

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
    syncCsrfToken(props.initialPage.props.csrfToken)

    // `success` fires after every Inertia visit, including the redirect that
    // follows a login or a registration — which is the visit that matters.
    router.on('success', (event) => {
      syncCsrfToken(event.detail.page.props.csrfToken)
    })

    createApp({ render: () => h(App, props) })
      .use(plugin)
      .mount(el)
  },

  progress: {
    color: '#4f8ef7',
  },
})
