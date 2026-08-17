# `vb-gratitude` — Gratitude

Gamified team gratitude for small teams: give a shoutout to a teammate, earn points and badges, with a gentle daily morning prompt. Points award through the core gamification ledger.

## Architecture

- **PHP** — `src/VbGratitudeServiceProvider.php` implements `App\Plugins\Contracts\PluginModule`.
  Routes in `src/routes.php`, models in `src/Models/`, namespace `Vctrs\Plugins\VbGratitude`.
- **UI** — `ui/entry.tsx` builds to a single ESM bundle at `dist/entry.js`. React comes
  from the host at mount; it is never bundled.
- **Migrations** — `database/migrations/`, tables prefixed `vb_gratitude_`.
- **Trust** — ships a `provider`, so the artifact must be signed by a trusted
  marketplace key to install.

## Development

```bash
npm install
npm run test                  # Vitest — the ESM mount contract
npm run build                 # -> dist/entry.js
bash scripts/test-in-app.sh   # full Pest suite in a throwaway app worktree
bash scripts/build-zip.sh     # deterministic shippable ZIP
```

## Publishing

Tag `v<version>` to build, sign, and release. Copy the `artifact` block that CI prints
to the job summary into `marketplace-repository/plugins/vb-gratitude/manifest.json`.

**Use CI's digest and signature, never a local build's.** Local ZIP bytes differ from
CI ZIP bytes, so locally computed values fail verification on every install.

## License

AGPL-3.0-or-later with the VCTRbase plugin exception. See [LICENSE](LICENSE).
