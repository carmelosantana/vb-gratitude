// ESM single-bundle UI for the extracted vb-gratitude plugin.
//
// Runtime model: React / ReactDOM / the host UI kit are injected via `host` at
// mount — this file NEVER imports react. The only things bundled into
// dist/entry.js are this code, the vendored @vctrs/plugin-ui client kit, and
// axios (pulled in by the kit). All chrome (layout / page container / head) is
// dropped; the host provides it.
//
// Data layer: the vendored kit at ./plugin-ui/client.ts. apiGet unwraps the
// canonical {traceId, data, status} envelope; POSTs go through the same axios
// client and read `.data.data` off the enveloped 2xx (mirrors sibling plugins).
import { apiGet, createApiClient, ApiClientError } from './plugin-ui/client';

type Host = {
  React: typeof import('react');
  ReactDOM: typeof import('react-dom/client');
  ui: Record<string, any>;
};

type PluginModule = {
  mount(el: HTMLElement, host: Host, props: any): (() => void) | void;
};

type Teammate = { id: string; display_name?: string | null; title?: string | null };
type Shoutout = {
  id?: string;
  message: string;
  category?: string | null;
  recipient_name?: string | null;
  created_at?: string | null;
};
type Badge = { badge_key: string; earned_at?: string | null };

// One session-cookie-authed client for the whole plugin surface.
const api = createApiClient('/api/v1/vb-gratitude');

// Friendly labels for the earned-badge keys. Unknown keys fall back to a
// title-cased version of the key so a new badge still reads nicely.
const BADGE_LABELS: Record<string, string> = {
  first_shoutout: 'First Shoutout',
  generous_giver: 'Generous Giver',
  team_favorite: 'Team Favorite',
  gratitude_streak: 'Gratitude Streak',
};

function badgeLabel(key: string): string {
  return (
    BADGE_LABELS[key] ??
    key
      .split(/[_\s-]+/)
      .filter(Boolean)
      .map((w) => w.charAt(0).toUpperCase() + w.slice(1))
      .join(' ')
  );
}

function niceDate(value?: string | null): string {
  if (!value) return '';
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return '';
  return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
}

const plugin: PluginModule = {
  mount(el, host) {
    const R = host.React;
    const { ReactDOM } = host;

    function App() {
      const [teammates, setTeammates] = R.useState<Teammate[]>([]);
      const [feed, setFeed] = R.useState<Shoutout[]>([]);
      const [badges, setBadges] = R.useState<Badge[]>([]);

      const [recipient, setRecipient] = R.useState('');
      const [message, setMessage] = R.useState('');
      const [category, setCategory] = R.useState('');

      const [sending, setSending] = R.useState(false);
      const [formError, setFormError] = R.useState<string | null>(null);
      const [sentOk, setSentOk] = R.useState(false);

      function loadFeed() {
        apiGet<{ shoutouts?: Shoutout[] }>('/shoutouts', api)
          .then((d) => setFeed(d?.shoutouts ?? []))
          .catch(() => {
            /* a feed hiccup shouldn't break the give form */
          });
      }

      function loadBadges() {
        apiGet<{ badges?: Badge[] }>('/badges', api)
          .then((d) => setBadges(d?.badges ?? []))
          .catch(() => {
            /* badges are non-critical chrome */
          });
      }

      R.useEffect(() => {
        apiGet<{ teammates?: Teammate[] }>('/teammates', api)
          .then((d) => setTeammates(d?.teammates ?? []))
          .catch(() => setTeammates([]));
        loadFeed();
        loadBadges();
      }, []);

      function submit(e?: any) {
        if (e && typeof e.preventDefault === 'function') e.preventDefault();
        if (sending) return;

        setSentOk(false);
        if (!recipient) {
          setFormError('Pick a teammate to thank first.');
          return;
        }
        if (!message.trim()) {
          setFormError('Add a few warm words before you send.');
          return;
        }

        setSending(true);
        setFormError(null);

        const payload: Record<string, unknown> = {
          recipient_staff_id: recipient,
          message: message.trim(),
        };
        if (category.trim()) payload.category = category.trim();

        api
          .post('/shoutouts', payload)
          .then((r: any) => r.data.data)
          .then(() => {
            // Success: clear the form + refresh what the send may have changed.
            setRecipient('');
            setMessage('');
            setCategory('');
            setFormError(null);
            setSentOk(true);
            loadFeed();
            loadBadges();
          })
          .catch((err: any) => {
            // On a 422 / validation error, surface the envelope's message and
            // KEEP what they typed so they can fix and resend.
            const msg =
              err?.response?.data?.error ??
              (err instanceof ApiClientError ? err.message : null) ??
              'We couldn’t send that shoutout — please try again.';
            setFormError(msg);
            setSentOk(false);
          })
          .finally(() => setSending(false));
      }

      const card = {
        background: 'var(--card, #fff)',
        border: '1px solid rgba(0,0,0,0.08)',
        borderRadius: 12,
        padding: 20,
        boxShadow: '0 1px 2px rgba(0,0,0,0.04)',
      } as const;
      const labelStyle = { display: 'block', fontWeight: 600, marginBottom: 6, fontSize: 14 } as const;
      const inputStyle = {
        width: '100%',
        padding: '10px 12px',
        borderRadius: 8,
        border: '1px solid rgba(0,0,0,0.15)',
        fontSize: 14,
        boxSizing: 'border-box',
      } as const;

      return R.createElement(
        'div',
        { style: { display: 'grid', gap: 20, maxWidth: 720, margin: '0 auto', padding: 24 } },

        // ── Header ──────────────────────────────────────────────────────────
        R.createElement(
          'header',
          null,
          R.createElement('h1', { style: { fontSize: 24, fontWeight: 700, margin: 0 } }, 'Gratitude'),
          R.createElement(
            'p',
            { style: { margin: '6px 0 0', color: 'var(--muted, #6b7280)' } },
            'Say thanks to a teammate — a little appreciation goes a long way. 💛',
          ),
        ),

        // ── 1. Give a shoutout ──────────────────────────────────────────────
        R.createElement(
          'section',
          { style: card, 'aria-labelledby': 'give-heading' },
          R.createElement('h2', { id: 'give-heading', style: { marginTop: 0, fontSize: 18 } }, 'Give a shoutout'),
          R.createElement(
            'form',
            { onSubmit: submit, style: { display: 'grid', gap: 14 } },

            R.createElement(
              'div',
              null,
              R.createElement('label', { htmlFor: 'gr-recipient', style: labelStyle }, 'Teammate'),
              R.createElement(
                'select',
                {
                  id: 'gr-recipient',
                  value: recipient,
                  onChange: (ev: any) => setRecipient(ev.target.value),
                  style: inputStyle,
                },
                R.createElement('option', { value: '' }, 'Choose a teammate…'),
                teammates.map((t) =>
                  R.createElement(
                    'option',
                    { key: t.id, value: t.id },
                    (t.display_name || 'Teammate') + (t.title ? ' — ' + t.title : ''),
                  ),
                ),
              ),
            ),

            R.createElement(
              'div',
              null,
              R.createElement('label', { htmlFor: 'gr-message', style: labelStyle }, 'Your message'),
              R.createElement('textarea', {
                id: 'gr-message',
                value: message,
                onChange: (ev: any) => setMessage(ev.target.value),
                rows: 3,
                placeholder: 'Thanks for going above and beyond…',
                style: { ...inputStyle, resize: 'vertical' },
              }),
            ),

            R.createElement(
              'div',
              null,
              R.createElement(
                'label',
                { htmlFor: 'gr-category', style: labelStyle },
                'Category ',
                R.createElement('span', { style: { fontWeight: 400, color: 'var(--muted, #6b7280)' } }, '(optional)'),
              ),
              R.createElement('input', {
                id: 'gr-category',
                type: 'text',
                value: category,
                onChange: (ev: any) => setCategory(ev.target.value),
                placeholder: 'e.g. Teamwork, Customer care',
                style: inputStyle,
              }),
            ),

            formError
              ? R.createElement(
                  'p',
                  {
                    role: 'alert',
                    style: {
                      margin: 0,
                      color: '#b91c1c',
                      background: '#fef2f2',
                      border: '1px solid #fecaca',
                      borderRadius: 8,
                      padding: '8px 12px',
                      fontSize: 14,
                    },
                  },
                  formError,
                )
              : null,

            sentOk
              ? R.createElement(
                  'p',
                  {
                    role: 'status',
                    style: {
                      margin: 0,
                      color: '#166534',
                      background: '#f0fdf4',
                      border: '1px solid #bbf7d0',
                      borderRadius: 8,
                      padding: '8px 12px',
                      fontSize: 14,
                    },
                  },
                  'Gratitude sent — thank you for sharing! 🎉',
                )
              : null,

            R.createElement(
              'div',
              null,
              R.createElement(
                'button',
                {
                  type: 'submit',
                  disabled: sending,
                  style: {
                    background: sending ? '#fcd34d' : '#f59e0b',
                    color: '#1f2937',
                    fontWeight: 600,
                    border: 'none',
                    borderRadius: 8,
                    padding: '10px 18px',
                    fontSize: 14,
                    cursor: sending ? 'default' : 'pointer',
                  },
                },
                sending ? 'Sending…' : 'Send gratitude',
              ),
            ),
          ),
        ),

        // ── 2. Recent team gratitude (feed) ─────────────────────────────────
        R.createElement(
          'section',
          { style: card, 'aria-labelledby': 'feed-heading' },
          R.createElement('h2', { id: 'feed-heading', style: { marginTop: 0, fontSize: 18 } }, 'Recent team gratitude'),
          feed.length === 0
            ? R.createElement(
                'p',
                { style: { color: 'var(--muted, #6b7280)', margin: 0 } },
                'No shoutouts yet — be the first to brighten someone’s day!',
              )
            : R.createElement(
                'ul',
                { style: { listStyle: 'none', margin: 0, padding: 0, display: 'grid', gap: 12 } },
                feed.map((s, i) =>
                  R.createElement(
                    'li',
                    {
                      key: s.id ?? i,
                      style: {
                        borderLeft: '3px solid #f59e0b',
                        padding: '8px 0 8px 14px',
                      },
                    },
                    R.createElement(
                      'div',
                      { style: { display: 'flex', justifyContent: 'space-between', gap: 12, alignItems: 'baseline' } },
                      R.createElement('strong', null, s.recipient_name || 'A teammate'),
                      s.created_at
                        ? R.createElement(
                            'span',
                            { style: { color: 'var(--muted, #6b7280)', fontSize: 12 } },
                            niceDate(s.created_at),
                          )
                        : null,
                    ),
                    R.createElement('p', { style: { margin: '4px 0 0' } }, s.message),
                    s.category
                      ? R.createElement(
                          'span',
                          {
                            style: {
                              display: 'inline-block',
                              marginTop: 6,
                              fontSize: 12,
                              color: '#92400e',
                              background: '#fef3c7',
                              borderRadius: 999,
                              padding: '2px 10px',
                            },
                          },
                          s.category,
                        )
                      : null,
                  ),
                ),
              ),
        ),

        // ── 3. Your badges ──────────────────────────────────────────────────
        R.createElement(
          'section',
          { style: card, 'aria-labelledby': 'badges-heading' },
          R.createElement('h2', { id: 'badges-heading', style: { marginTop: 0, fontSize: 18 } }, 'Your badges'),
          badges.length === 0
            ? R.createElement(
                'p',
                { style: { color: 'var(--muted, #6b7280)', margin: 0 } },
                'No badges yet — start sharing!',
              )
            : R.createElement(
                'ul',
                { style: { listStyle: 'none', margin: 0, padding: 0, display: 'flex', flexWrap: 'wrap', gap: 10 } },
                badges.map((b, i) =>
                  R.createElement(
                    'li',
                    {
                      key: b.badge_key ?? i,
                      style: {
                        display: 'flex',
                        alignItems: 'center',
                        gap: 8,
                        background: '#fffbeb',
                        border: '1px solid #fde68a',
                        borderRadius: 999,
                        padding: '6px 14px',
                        fontSize: 14,
                        fontWeight: 600,
                        color: '#92400e',
                      },
                    },
                    R.createElement('span', { 'aria-hidden': 'true' }, '🏅'),
                    R.createElement('span', null, badgeLabel(b.badge_key)),
                    b.earned_at
                      ? R.createElement(
                          'span',
                          { style: { fontWeight: 400, color: 'var(--muted, #6b7280)', fontSize: 12 } },
                          niceDate(b.earned_at),
                        )
                      : null,
                  ),
                ),
              ),
        ),
      );
    }

    const root = ReactDOM.createRoot(el);
    root.render(R.createElement(App));

    return () => root.unmount();
  },
};

export default plugin;
