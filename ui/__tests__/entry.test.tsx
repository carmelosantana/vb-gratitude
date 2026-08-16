// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach } from 'vitest';
import * as React from 'react';
import * as ReactDOMClient from 'react-dom/client';
import { act } from 'react';
import { screen, waitFor, fireEvent, within } from '@testing-library/react';

// The vendored kit builds its client with axios.create() and reads the
// {traceId,data,status} envelope off response.data. We mock axios so
// createApiClient() returns a stub whose .get / .post we drive per-test —
// no real network, session-authed envelope shape preserved. Shared handles are
// created with vi.hoisted so the (hoisted) vi.mock factory can reference them.
const { getMock, postMock } = vi.hoisted(() => ({
  getMock: vi.fn(),
  postMock: vi.fn(),
}));

vi.mock('axios', () => ({
  default: {
    create: () => ({
      get: getMock,
      post: postMock,
      put: vi.fn(),
      delete: vi.fn(),
    }),
  },
}));

import plugin from '../entry';

const envelope = (data: unknown) => ({ data: { traceId: 't-1', status: 'success', data } });

// Route GETs by path so the three sections each get their own payload.
function routeGet(overrides: Record<string, unknown> = {}) {
  const defaults: Record<string, unknown> = {
    '/teammates': { teammates: [] },
    '/shoutouts': { shoutouts: [] },
    '/badges': { badges: [] },
  };
  const table = { ...defaults, ...overrides };
  getMock.mockImplementation((path: string) => {
    const key = Object.keys(table).find((k) => path.startsWith(k));
    return Promise.resolve(envelope(key ? table[key] : {}));
  });
}

function mountApp() {
  const el = document.createElement('div');
  document.body.appendChild(el);
  let unmount: (() => void) | void;
  act(() => {
    unmount = plugin.mount(el, { React, ReactDOM: ReactDOMClient, ui: {} }, {});
  });
  return { el, unmount };
}

beforeEach(() => {
  document.body.innerHTML = '';
  getMock.mockReset();
  postMock.mockReset();
  routeGet();
});

describe('vb-gratitude ESM mount contract', () => {
  it('exports a default object with a mount function', () => {
    expect(typeof plugin.mount).toBe('function');
  });

  it('mounts into a host element and returns an unmount function', () => {
    const { unmount } = mountApp();
    expect(typeof unmount).toBe('function');
    act(() => unmount?.());
  });
});

describe('vb-gratitude UI', () => {
  it('renders the three sections: give form, feed, and badges', async () => {
    mountApp();
    expect(await screen.findByRole('heading', { name: /give a shoutout/i })).toBeTruthy();
    expect(screen.getByRole('heading', { name: /recent team gratitude/i })).toBeTruthy();
    expect(screen.getByRole('heading', { name: /your badges/i })).toBeTruthy();
    // A real, labeled send button — not a bare div.
    expect(screen.getByRole('button', { name: /send gratitude/i })).toBeTruthy();
  });

  it('populates the teammate picker from GET /teammates', async () => {
    routeGet({
      '/teammates': {
        teammates: [
          { id: 'staff-1', display_name: 'Ada Lovelace', title: 'Advisor' },
          { id: 'staff-2', display_name: 'Grace Hopper' },
        ],
      },
    });
    mountApp();

    const select = (await screen.findByLabelText(/teammate/i)) as HTMLSelectElement;
    await waitFor(() => {
      expect(within(select).getByRole('option', { name: /Ada Lovelace — Advisor/i })).toBeTruthy();
    });
    expect(within(select).getByRole('option', { name: /Grace Hopper/i })).toBeTruthy();
    // Placeholder option is present + selected by default.
    expect((within(select).getByRole('option', { name: /choose a teammate/i }) as HTMLOptionElement).selected).toBe(
      true,
    );
  });

  it('submits POST /shoutouts with the selected recipient + message, then clears and re-fetches the feed on success', async () => {
    routeGet({
      '/teammates': { teammates: [{ id: 'staff-1', display_name: 'Ada Lovelace' }] },
    });
    postMock.mockResolvedValue(envelope({ shoutout: { id: 'sh-1' } }));
    mountApp();

    const select = (await screen.findByLabelText(/teammate/i)) as HTMLSelectElement;
    await waitFor(() => expect(within(select).queryByRole('option', { name: /Ada Lovelace/i })).toBeTruthy());

    fireEvent.change(select, { target: { value: 'staff-1' } });
    const message = screen.getByLabelText(/your message/i) as HTMLTextAreaElement;
    fireEvent.change(message, { target: { value: 'Thanks for the great work!' } });
    fireEvent.change(screen.getByLabelText(/category/i), { target: { value: 'Teamwork' } });

    const getCallsBefore = getMock.mock.calls.length;
    fireEvent.click(screen.getByRole('button', { name: /send gratitude/i }));

    // POST called with the right payload.
    await waitFor(() => expect(postMock).toHaveBeenCalledTimes(1));
    expect(postMock).toHaveBeenCalledWith('/shoutouts', {
      recipient_staff_id: 'staff-1',
      message: 'Thanks for the great work!',
      category: 'Teamwork',
    });

    // On success: message cleared + a success status shown.
    await waitFor(() => expect((screen.getByLabelText(/your message/i) as HTMLTextAreaElement).value).toBe(''));
    expect(screen.getByRole('status').textContent).toMatch(/gratitude sent/i);

    // Feed was re-fetched (a fresh GET /shoutouts after the send).
    await waitFor(() =>
      expect(getMock.mock.calls.filter((c) => String(c[0]).startsWith('/shoutouts')).length).toBeGreaterThan(1),
    );
    expect(getMock.mock.calls.length).toBeGreaterThan(getCallsBefore);
  });

  it('shows a friendly error and does NOT clear the message on a 422', async () => {
    routeGet({
      '/teammates': { teammates: [{ id: 'staff-1', display_name: 'Ada Lovelace' }] },
    });
    // Mirror an axios error carrying the envelope's error string.
    postMock.mockRejectedValue({
      response: { status: 422, data: { traceId: 't-2', status: 'error', error: 'That teammate is unknown.' } },
    });
    mountApp();

    const select = (await screen.findByLabelText(/teammate/i)) as HTMLSelectElement;
    await waitFor(() => expect(within(select).queryByRole('option', { name: /Ada Lovelace/i })).toBeTruthy());

    fireEvent.change(select, { target: { value: 'staff-1' } });
    const message = screen.getByLabelText(/your message/i) as HTMLTextAreaElement;
    fireEvent.change(message, { target: { value: 'Nice job!' } });
    fireEvent.click(screen.getByRole('button', { name: /send gratitude/i }));

    // Friendly inline error surfaced from the envelope.
    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toMatch(/that teammate is unknown/i);

    // Message is preserved so they can fix + resend.
    expect((screen.getByLabelText(/your message/i) as HTMLTextAreaElement).value).toBe('Nice job!');
    // No success banner on failure.
    expect(screen.queryByRole('status')).toBeNull();
  });

  it('does not POST (and shows guidance) when no teammate is selected', async () => {
    mountApp();
    await screen.findByRole('button', { name: /send gratitude/i });
    fireEvent.change(screen.getByLabelText(/your message/i), { target: { value: 'Hello' } });
    fireEvent.click(screen.getByRole('button', { name: /send gratitude/i }));

    expect(await screen.findByRole('alert')).toBeTruthy();
    expect(postMock).not.toHaveBeenCalled();
  });

  it('renders feed rows from GET /shoutouts', async () => {
    routeGet({
      '/shoutouts': {
        shoutouts: [
          {
            id: 'sh-1',
            message: 'You saved the launch!',
            recipient_name: 'Grace Hopper',
            category: 'Heroics',
            created_at: '2026-08-10T12:00:00Z',
          },
          { id: 'sh-2', message: 'Great mentoring.', recipient_name: 'Ada Lovelace', created_at: null },
        ],
      },
    });
    mountApp();

    expect(await screen.findByText(/you saved the launch!/i)).toBeTruthy();
    expect(screen.getByText('Grace Hopper')).toBeTruthy();
    expect(screen.getByText(/great mentoring\./i)).toBeTruthy();
    expect(screen.getByText('Ada Lovelace')).toBeTruthy();
    expect(screen.getByText('Heroics')).toBeTruthy();
  });

  it('shows the badges empty-state when GET /badges is empty', async () => {
    mountApp();
    const badgesHeading = await screen.findByRole('heading', { name: /your badges/i });
    const section = badgesHeading.closest('section') as HTMLElement;
    expect(within(section).getByText(/no badges yet — start sharing!/i)).toBeTruthy();
  });

  it('renders earned badges with friendly labels when GET /badges has rows', async () => {
    routeGet({
      '/badges': {
        badges: [
          { badge_key: 'first_shoutout', earned_at: '2026-08-01T00:00:00Z' },
          { badge_key: 'mystery_medal', earned_at: null },
        ],
      },
    });
    mountApp();

    expect(await screen.findByText('First Shoutout')).toBeTruthy();
    // Unknown key falls back to a title-cased label.
    expect(screen.getByText('Mystery Medal')).toBeTruthy();
  });
});
