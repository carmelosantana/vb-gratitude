// @vitest-environment jsdom
import { describe, it, expect, vi } from 'vitest';
import * as React from 'react';
import * as ReactDOMClient from 'react-dom/client';

// The vendored kit builds its client with axios.create() and reads the
// {traceId,data,status} envelope off response.data. Mock axios so
// createApiClient() returns a stub whose .get resolves enveloped payloads —
// no real network, session-authed shape preserved.
vi.mock('axios', () => ({
  default: {
    create: () => ({
      get: vi.fn().mockResolvedValue({ data: { traceId: 't-1', status: 'success', data: {} } }),
      post: vi.fn(),
      put: vi.fn(),
      delete: vi.fn(),
    }),
  },
}));

import plugin from '../entry';

describe('vb-gratitude ESM mount contract', () => {
  it('exports a default object with a mount function', () => {
    expect(typeof plugin.mount).toBe('function');
  });

  it('mounts into a host element and returns an unmount function', () => {
    const el = document.createElement('div');
    document.body.appendChild(el);

    const unmount = plugin.mount(el, {
      React,
      ReactDOM: ReactDOMClient,
      ui: {},
    }, {});

    expect(typeof unmount).toBe('function');
    unmount?.();
  });
});
