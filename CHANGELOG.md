# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

### Changed

- Improved README for GitHub with clearer onboarding, provider matrix, fluent API examples, and environment setup guidance.
- Clarified local tester usage and quality command workflow (`composer check`, coverage, analysis, formatting, Rector).

## 2.0.0 - 2026-02-24

### Added

- Full package rewrite with a provider-agnostic fluent API for Facebook, Instagram, X, and LinkedIn.
- Shared methods for cross-provider publishing (`message`, `link`, `media`, `metadata`, `option`, `share`, `delete`).
- Provider-specific fluent methods for Facebook scheduling/targeting, Instagram reels/carousel/alt text, X reply/quote/poll, and LinkedIn distribution/visibility/media URN.
- Automatic media normalization between URL-based and upload-based providers.
- Typed `SharePayload` / `ShareResult` value objects and dedicated exception model.
- Local end-to-end CLI tester (`bin/local-tester.php`) and X OAuth helper script (`bin/x-user-token.php`).
- Strict static analysis and style tooling (PHPStan max level, Pest coverage enforcement, Pint, Rector).

### Changed

- Upgraded package runtime requirement to PHP 8.4+ and Laravel 11/12 support.
- Re-enabled global `mb_*` string functions usage where applicable.
- Increased default HTTP timeout and improved retry/poll behavior for long-running provider operations.

### Fixed

- Multiple provider integration and payload edge cases discovered during real API validation.
- X media upload compatibility issues by migrating to supported v2 media upload flow.
- Instagram publish readiness and media-type handling to align with current API constraints.
