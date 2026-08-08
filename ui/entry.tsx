// ESM single-bundle UI for the extracted vb-gratitude plugin.
//
// Runtime model: React / ReactDOM / the host UI kit are injected via `host` at
// mount — this file NEVER imports react. The only things bundled into
// dist/entry.js are this code, the vendored @vctrs/plugin-ui client kit, and
// axios (pulled in by the kit). All chrome (layout / page container / head) is
// dropped; the host provides it.
//
// Data layer: the vendored kit at ./plugin-ui/client.ts. apiGet unwraps the
// canonical {traceId, data, status} envelope.
import { apiGet, createApiClient } from './plugin-ui/client';

type Host = {
  React: typeof import('react');
  ReactDOM: typeof import('react-dom/client');
  ui: Record<string, any>;
};

type PluginModule = {
  mount(el: HTMLElement, host: Host, props: any): (() => void) | void;
};

// One session-cookie-authed client for the whole plugin surface.
const api = createApiClient('/api/v1/vb-gratitude');

const plugin: PluginModule = {
  mount(el, host) {
    const { React, ReactDOM } = host;

    function App() {
      const [error, setError] = React.useState<string | null>(null);

      React.useEffect(() => {
        // TODO: replace with a real endpoint from src/routes.php.
        // apiGet(api, '/overview').then(setData).catch((e) => setError(String(e)));
      }, []);

      return React.createElement(
        'div',
        { className: 'p-6' },
        React.createElement('h1', { className: 'text-xl font-semibold' }, 'Gratitude'),
        error
          ? React.createElement('p', { className: 'text-red-600' }, error)
          : React.createElement('p', null, 'Scaffold ready — implement your UI here.'),
      );
    }

    const root = ReactDOM.createRoot(el);
    root.render(React.createElement(App));

    return () => root.unmount();
  },
};

export default plugin;
