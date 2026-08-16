# Changelog

All notable changes to `vb-gratitude` are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/), and the project adheres to
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.1.0] - 2026-08-16

### Added

- Give-a-shoutout flow: recognize a teammate with a message and optional
  category, awarding points through the core gamification ledger (no separate
  points ledger), capped per giver per day by a configurable daily allowance.
- Milestone badges earned automatically from giving activity — First Thanks,
  Grateful Regular, Team Connector (distinct teammates), Gratitude Champion —
  plus Appreciated for shoutouts received.
- Tenant-isolated `GratitudeShoutout` and `GratitudeBadgeAward` models backed by
  fail-closed row-level security.
- Session-authed HTTP API (`/api/v1/vb-gratitude`): create and list shoutouts,
  list earned badges, and a PII-free teammate picker, each gated by permission.
- Dashboard widgets: shoutouts given and received this month, plus a recent
  team-gratitude feed.
- Optional daily morning reminder that nudges opted-in users to share gratitude.
- Module UI mounted under the dashboard for giving and browsing gratitude.
- Default permission grants so the plugin is usable the moment an admin enables
  it (everyone can give and view; owners and admins manage settings).
