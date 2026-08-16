# Changelog

All notable changes to `vb-gratitude` are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/), and the project adheres to
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- Give-a-shoutout flow: recognize a teammate with a message and optional
  category, awarding points through the core gamification ledger.
- Per-giver daily allowance capping how many shoutouts earn points each day.
- Tenant-isolated `GratitudeShoutout` and `GratitudeBadgeAward` models backed by
  fail-closed row-level security.
- Session-authed HTTP API (`/api/v1/vb-gratitude`): create and list shoutouts,
  list earned badges, and a PII-free teammate picker, each gated by permission.
- Dashboard widgets: shoutouts given and received this month, plus a recent
  team-gratitude feed.
- Optional daily morning reminder job that nudges opted-in users each morning.
- Module UI mounted under the dashboard for giving and browsing gratitude.
