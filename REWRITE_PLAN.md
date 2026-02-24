# Socialize Rewrite Plan (Laravel 12+)

## 1) Product Direction

### Goal
Rebuild the package from scratch as a modern Laravel package that offers a **single, intuitive API** for publishing content to:
- Facebook
- Instagram
- X (Twitter)
- LinkedIn

### Core principles
- Unified cross-provider API first.
- Provider-specific capabilities via explicit fluent chaining.
- Strong typing and predictable responses.
- Fail fast on invalid combinations.
- Production-grade tests and static analysis.

### Out of scope (first rewrite release)
- OAuth login/token exchange flows.
- Long-running queues/workers bundled by default.
- Non-publishing endpoints unrelated to core sharing (analytics, inbox, ad APIs).

## 2) External API Baseline (Current Docs)

### Facebook Graph API
- Use Graph API version configuration with default to latest known stable in docs timeline.
- Page post publishing via `POST /{page-id}/feed`.
- Image publishing via `POST /{page-id}/photos`.
- Post deletion via `DELETE /{post-id}`.

### Instagram Platform (Content Publishing)
- Content publishing flow:
  1) `POST /{ig-id}/media` (create container)
  2) `POST /{ig-id}/media_publish` (publish container)
  3) Optional status check `GET /{container-id}?fields=status_code`
- Enforce publishing constraints from docs (public media URL, rate limits, supported formats).

### X API (Posts)
- Create post: `POST /2/tweets`.
- Delete post: `DELETE /2/tweets/{id}`.
- Bearer token auth with user context for write operations.
- Optional media attachment through media IDs.

### LinkedIn API
- Create post via `POST /rest/posts`.
- Delete post via `DELETE /rest/posts/{encoded-urn}`.
- Required headers:
  - `Authorization: Bearer ...`
  - `Linkedin-Version: YYYYMM`
  - `X-Restli-Protocol-Version: 2.0.0`
- Optional image flow through `rest/images?action=initializeUpload`.

## 3) Package Architecture

### Namespaces
- `DrAliRagab\Socialize\`
  - `SocializeManager` (entry point)
  - `Facades\Socialize`
  - `SocializeServiceProvider`
  - `Contracts\`
  - `Enums\`
  - `Exceptions\`
  - `Http\`
  - `Providers\`
  - `Support\`
  - `ValueObjects\`

### Core abstractions
- `ProviderDriver` contract
  - `share(SharePayload $payload): ShareResult`
  - `delete(string $postId): bool`
  - Optional feature interfaces for provider-specific actions.
- `SharePayload`
  - `text`, `link`, `media`, `metadata`, `options`.
- `ShareResult`
  - normalized result with `provider`, `id`, `url`, `raw`.

### Fluent builder
- `Socialize::provider('facebook')->...`
- Shortcuts:
  - `Socialize::facebook()`
  - `Socialize::instagram()`
  - `Socialize::twitter()`
  - `Socialize::linkedin()`
- Shared fluent methods:
  - `message(string)`
  - `link(string)`
  - `imageUrl(string)`
  - `videoUrl(string)`
  - `mediaId(string)`
  - `metadata(array)`
  - `share()`
- Provider-specific fluent methods (explicitly validated)
  - Facebook: `scheduledAt()`, `published()`, `targeting()`
  - Instagram: `carousel()`, `reel()`, `altText()`
  - X: `replyTo()`, `quote()`, `poll()`
  - LinkedIn: `visibility()`, `distribution()`

### Validation strategy
- Central validator checks common payload rules.
- Provider validator checks provider-only rules.
- Throw `InvalidSharePayloadException` with actionable message on invalid state.

### HTTP strategy
- Laravel HTTP client (`Illuminate\Support\Facades\Http`) with:
  - configurable timeouts
  - retry policy for transient errors
  - provider-specific base URLs
- Error normalization to `SocializeApiException` carrying:
  - provider
  - status code
  - error code/message
  - raw body

## 4) Configuration Design

`config/socialize.php` with per-provider named profiles:
- `default_profile`
- `providers.facebook.profiles.default`
- `providers.instagram.profiles.default`
- `providers.twitter.profiles.default`
- `providers.linkedin.profiles.default`
- `http.timeout`, `http.connect_timeout`, `http.retries`
- `logging.enabled` + channel options

## 5) Developer API UX

### Unified usage
```php
Socialize::provider('facebook')
    ->message('Hello')
    ->link('https://example.com')
    ->share();
```

### Provider switching with same shared methods
```php
foreach (['facebook', 'twitter', 'linkedin'] as $provider) {
    Socialize::provider($provider)
        ->message('Release is live')
        ->link('https://example.com/release')
        ->share();
}
```

### Provider-specific chaining
```php
Socialize::instagram()
    ->imageUrl('https://cdn.example.com/post.jpg')
    ->altText('Product photo')
    ->share();
```

## 6) Testing Strategy (Target >=95% Coverage)

### Tools
- Pest v4
- Orchestra Testbench v10
- HTTP fakes for all provider flows

### Coverage matrix
- Manager and provider resolution
- Shared fluent API behavior
- Provider-specific method availability and rejection
- Payload validation edge cases
- API success mapping to normalized results
- API error mapping (4xx/5xx/timeouts/malformed response)
- Config profile fallback and missing credentials
- Delete flows per provider
- Instagram multi-step publishing and polling behavior

### Negative/edge scenarios
- Missing required fields per provider
- Unsupported combination (e.g. Instagram without media)
- Invalid URLs and empty payloads
- API returning partial/missing ids
- Retry exhaustion and transport exceptions

## 7) Static Analysis and Quality Gates

- PHPStan at maximum strictness (`level: max`) with Larastan and deprecation rules.
- Strict types enabled across source and tests.
- Pint formatting.
- CI script targets:
  - `composer test`
  - `composer test-coverage`
  - `composer analyse`

## 8) Documentation Deliverables

Rewrite README with:
- installation
- configuration for each provider
- shared API quickstart
- provider-specific examples
- error handling
- testing and quality policy
- supported platform constraints and caveats

## 9) Execution Sequence

1. Remove all legacy package implementation.
2. Scaffold new package structure and contracts.
3. Implement manager, fluent builder, and provider drivers.
4. Implement provider validators + normalized responses.
5. Write full Pest suite and fixtures.
6. Configure PHPStan max strictness.
7. Run and fix until tests + analysis pass and coverage >=95%.
8. Finalize docs.

## 10) Post-Rewrite Audit (2026-02-24)

### Checked against plan
- Unified API with provider switching: done.
- Provider-specific fluent methods: done.
- Facebook/Instagram/X/LinkedIn drivers: done.
- Configurable HTTP retries/timeouts: done.
- Error normalization and typed exceptions: done.
- LinkedIn support added (in addition to original providers): done.

### Tightening done after audit
- Updated baseline to PHP `^8.4` and re-enabled global `mb_*` string function normalization.
- Added stricter input validation for empty provider-specific IDs/options.
- Added stricter delete validation (empty post IDs fail fast).
- Added LinkedIn version format validation (`YYYYMM`).
- Added Instagram publish fallback status query (`status_code`, `status`) for diagnostics.
- Expanded tests to target edge/error branches and provider-specific constraints.

### Remaining optional enhancements (future)
- Optional built-in pre-check for Instagram `content_publishing_limit`.
- Optional first-class LinkedIn image upload helper (`/rest/images?action=initializeUpload` flow).
