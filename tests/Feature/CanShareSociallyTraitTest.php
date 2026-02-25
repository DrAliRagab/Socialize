<?php

declare(strict_types=1);

use DrAliRagab\Socialize\Tests\Fixtures\PostModel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('shares a model to facebook using configured columns', function (): void {
    Http::fake([
        'https://graph.facebook.com/*' => Http::response(['id' => 'fb-model-1'], 200),
    ]);

    $post = new PostModel([
        'title' => 'Model post title',
        'url'   => 'https://example.com/model',
        'image' => 'https://cdn.example.com/model.jpg',
    ]);

    $shareResult = $post->shareToFacebook();

    expect($shareResult->id())->toBe('fb-model-1');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/photos'));
});

it('shares a model to linkedin through generic shareTo', function (): void {
    Http::fake([
        'https://api.linkedin.com/rest/posts' => Http::response(['id' => 'urn:li:share:model-2'], 201),
    ]);

    $post = new PostModel([
        'title' => 'LinkedIn title',
        'url'   => 'https://example.com/professional',
    ]);

    $shareResult = $post->shareTo('linkedin');

    expect($shareResult->id())->toBe('urn:li:share:model-2');
});

it('supports all trait convenience methods', function (): void {
    Http::fake([
        'https://graph.facebook.com/v25.0/98765/media'         => Http::response(['id' => 'ig-container'], 200),
        'https://graph.facebook.com/v25.0/98765/media_publish' => Http::response(['id' => 'ig-post'], 200),
        'https://api.x.com/2/tweets'                           => Http::response(['data' => ['id' => 'x-post']], 200),
        'https://api.linkedin.com/rest/posts'                  => Http::response(['id' => 'urn:li:share:trait'], 201),
    ]);

    $instagramPost = new PostModel([
        'title' => 'Trait post',
        'url'   => 'https://example.com/trait',
        'image' => 'https://cdn.example.com/trait.jpg',
    ]);

    $textOnlyPost = new PostModel([
        'title' => 'Trait post',
        'url'   => 'https://example.com/trait',
    ]);

    expect($instagramPost->shareToInstagram()->id())->toBe('ig-post')
        ->and($textOnlyPost->shareToTwitter()->id())->toBe('x-post')
        ->and($textOnlyPost->shareToLinkedIn()->id())->toBe('urn:li:share:trait')
    ;
});

it('ignores non string mapped values in trait column resolution', function (): void {
    Http::fake([
        'https://api.x.com/2/tweets' => Http::response(['data' => ['id' => 'x-post-2']], 200),
    ]);

    config()->set('socialize.model_columns.message', 'name');
    config()->set('socialize.model_columns.link', 'meta.link');
    config()->set('socialize.model_columns.image', 'meta.image');

    $post = new PostModel([
        'name' => 'Typed value',
        'meta' => [
            'link'  => 123,
            'image' => ['unexpected'],
        ],
    ]);

    $shareResult = $post->shareToTwitter();

    expect($shareResult->id())->toBe('x-post-2');
});

it('maps configured video column when sharing models', function (): void {
    Http::fake([
        'https://graph.facebook.com/*' => Http::response(['id' => 'fb-video-model'], 200),
    ]);

    config()->set('socialize.model_columns.video', 'clip');
    config()->set('socialize.model_columns.image', 'missing-image');

    $post = new PostModel([
        'title' => 'Video model post',
        'clip'  => 'https://cdn.example.com/model-video.mp4',
    ]);

    $shareResult = $post->shareToFacebook();

    expect($shareResult->id())->toBe('fb-video-model');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/videos')
        && ($request->data()['file_url'] ?? null) === 'https://cdn.example.com/model-video.mp4');
});
