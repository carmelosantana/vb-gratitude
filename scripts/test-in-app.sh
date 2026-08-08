#!/usr/bin/env bash
#
# test-in-app.sh — run the Gratitude Pest suite against a throwaway
# vctrbase-php worktree, inside the app container, using a PRIVATE test database.
#
# vb-gratitude is an EXTERNAL, uploaded, first-party plugin — it does not live in
# the app tree. Its tests therefore run against a mounted, throwaway app worktree:
#
#   1. Restore the private DB ($DB) from the committed schema dump (shared postgres).
#   2. Run the app's in-tree migrations (`artisan migrate`) — this creates the
#      app_user role + pgsql_app connection (enforce_real_rls) but NOT the plugin's
#      own tables; feature tests install+migrate the plugin in-process, unit tests
#      run its migrations directly.
#   3. Sync this plugin's tests/ into the worktree at tests/Feature/Plugins/VbGratitude/
#      — a path Pest already scans, so the tests inherit the app's TestCase +
#      DatabaseTransactions + the worktree Pest.php beforeEach hooks.
#   4. Mount the plugin read-only at /plugin-src and pass the VCTRS signing key via env
#      (PLUGIN_PRIV / PLUGIN_PUB) so the signed-install boot test can sign a real ZIP.
#   5. Run Pest against the synced test path (or an optional override arg).
#
# Usage:
#   scripts/test-in-app.sh                                       # whole suite
#   scripts/test-in-app.sh tests/Feature/Plugins/VbGratitude/MigrationsTest.php
#   scripts/test-in-app.sh tests/Feature/Plugins/VbGratitude "--filter=isolation"
#
# Env overrides:
#   MAIN   app repo (has the running docker compose stack)   default: ../../vctrbase-php
#   WT     throwaway app worktree to mount                    default: ../../vctrbase-php-vb-gratitude-test
#   KEYDIR dir holding vctrs.privkey.b64 / vctrs.pubkey.b64
#   DB     private test database name                         default: vctrs_test_vb_gratitude
set -euo pipefail

PLUGIN="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MAIN="${MAIN:-$(cd "$PLUGIN/../../vctrbase-php" && pwd)}"
WT="${WT:-$PLUGIN/../../vctrbase-php-vb-gratitude-test}"
KEYDIR="${KEYDIR:-$(cd "$PLUGIN/../../.plugin-signing-keys" && pwd)}"
DB="${DB:-vctrs_test_vb_gratitude}"

# Optional args: $1 = test path/dir (relative to worktree root), $2… = extra pest flags.
TARGET="${1:-tests/Feature/Plugins/VbGratitude}"
shift || true
EXTRA_ARGS="$*"

PRIV="$(cat "$KEYDIR/vctrs.privkey.b64")"
PUB="$(cat "$KEYDIR/vctrs.pubkey.b64")"

# ── Create the throwaway worktree if missing (checked out at the app's HEAD) ─────
if [ ! -d "$WT" ]; then
  echo ">> creating throwaway worktree $WT"
  git -C "$MAIN" worktree add -f --detach "$WT" HEAD >/dev/null 2>&1
fi
WT="$(cd "$WT" && pwd)"

DEST="$WT/tests/Feature/Plugins/VbGratitude"

echo ">> syncing plugin tests → $DEST"
rm -rf "$DEST"
mkdir -p "$DEST"
cp -R "$PLUGIN/tests/." "$DEST/"

# Remove the in-tree vb-gratitude plugin from the throwaway worktree so the in-tree
# copy does not shadow the uploaded install (its manifest declares nav key
# `vb-gratitude` + the /dashboard/certifications routes, which would collide with the
# uploaded plugin installed in-process). Removing it keeps the harness reproducible
# (the worktree is a throwaway test mount).
if [ -d "$WT/plugins/vb-gratitude" ]; then
  echo ">> removing in-tree plugins/vb-gratitude from worktree ($WT)"
  rm -rf "$WT/plugins/vb-gratitude"
fi

cd "$MAIN"

echo ">> restoring $DB from the schema dump (shared postgres, private DB)…"
docker compose exec -T postgres sh -c \
  "dropdb -U postgres --if-exists $DB >/dev/null 2>&1; \
   createdb -U postgres $DB && \
   psql -q -U postgres -d $DB -f /docker-entrypoint-initdb.d/01-schema.sql >/dev/null 2>&1 && \
   echo '   '$DB' ready'"

echo ">> running pest ($TARGET) in worktree ($WT)…"
docker compose run --rm -T \
  -v "$WT:/var/www/html" \
  -v "$MAIN/vendor:/var/www/html/vendor" \
  -v "$PLUGIN:/plugin-src:ro" \
  -e DB_DATABASE="$DB" -e APP_ENV=testing \
  -e PLUGIN_SRC=/plugin-src -e PLUGIN_PRIV="$PRIV" -e PLUGIN_PUB="$PUB" \
  app sh -c "rm -rf database/schema 2>/dev/null || true; \
             touch .env; \
             php artisan migrate --force --no-interaction >/dev/null 2>&1 || true; \
             php -d memory_limit=1024M ./vendor/bin/pest $TARGET $EXTRA_ARGS"
