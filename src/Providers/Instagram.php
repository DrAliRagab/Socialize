<?php

namespace DrAliRagab\Socialize\Providers;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class Instagram extends Provider implements ProviderInterface
{
    protected ?string $instaGraphVersion;

    protected ?string $instaUserAccessToken;

    protected ?string $instaAccountId;

    protected ?string $IGContainerId;

    protected ?string $instaPostId;

    public function __construct(string $config = 'default')
    {
        $this->config = config('socialize.instagram.'.$config);

        $this->instaGraphVersion = $this->config['graph_version'];
        $this->instaUserAccessToken = $this->config['user_access_token'];
        $this->instaAccountId = $this->config['instagram_account_id'];

        $this->Client = Http::withToken($this->instaUserAccessToken)->withOptions([
            'base_uri' => 'https://graph.facebook.com/'.$this->instaGraphVersion,
        ]);
    }

    /**
     * Create Instagram Container
     */
    private function createContainer(?array $options = null): self
    {
        $options ??= $this->postData;

        $response = $this->postResponse($this->instaAccountId.'/media', $options);

        $this->IGContainerId = $response['id'];

        return $this;
    }

    /**
     * Publish Instagram Container
     */
    private function publishContainer(?string $containerId = null): self
    {
        $containerId ??= $this->IGContainerId;

        $response = $this->postResponse($this->instaAccountId.'/media_publish', [
            'creation_id' => $containerId,
        ]);

        $this->instaPostId = $response['id'];

        return $this;
    }

    /**
     * Get Instagram Container ID
     */
    private function getContainerId(): string
    {
        return $this->IGContainerId;
    }

    /**
     * Publish Instagram Photo
     */
    public function publishImage(string $imageUrl, ?string $caption = null, ?array $options = null): self
    {
        $this->postData = array_merge([
            'image_url' => $imageUrl,
            'caption' => $caption,
        ], $options);

        $this->createContainer();
        $this->publishContainer();

        return $this;
    }

    /**
     * Publish Instagram Carousel
     */
    private function publishCarousel(array $mediaIds, ?string $caption = null, ?array $options = null): self
    {
        $this->postData = array_merge([
            'media_type' => 'CAROUSEL',
            'children' => implode(',', $mediaIds),
            'caption' => $caption,
        ], $options);

        $this->createContainer();
        $this->publishContainer();

        return $this;
    }

    /**
     * Publish Instagram Image Carousel
     */
    public function publishImageCarousel(array $imageUrls, ?string $caption = null, ?array $options = null): self
    {
        $mediaIds = [];

        foreach ($imageUrls as $imageUrl) {
            $mediaIds[] = $this->publishImage($imageUrl, $caption, $options)->getContainerId();
        }

        $this->publishCarousel($mediaIds, $caption, $options);

        return $this;
    }

    /**
     * Add Comment to Instagram Post
     */
    public function addComment(string $message, ?string $postId = null): self
    {
        $postId ??= $this->instaPostId;

        $this->postResponse($postId.'/comments', [
            'message' => $message,
        ]);

        return $this;
    }

    /**
     * Get Instagram Post ID
     */
    public function getPostId(): string
    {
        return $this->instaPostId;
    }

    /**
     * Get Instagram Post URL
     */
    public function getUrl(?string $postId = null): string|null
    {
        $postId ??= $this->instaPostId;

        $this->throwExceptionIf(
            ! $postId,
            'Can not find the post id'
        );

        $this->postData = [
            'fields' => 'permalink',
        ];

        $response = $this->getResponse($postId, $this->postData);

        return $response['permalink'] ?? null;
    }

    /**
     * Get Instagram Post
     *
     * @param  string  $postId
     * @param  array  $fields fields to retuen. see https://developers.facebook.com/docs/instagram-api/reference/ig-media#fields
     */
    public function getPost(string $postId, ?array $fields = null): Collection
    {
        $fields ??= ['caption', 'id', 'media_type', 'media_url', 'permalink', 'thumbnail_url', 'timestamp', 'username'];

        $fields = implode(',', $fields);

        $this->throwExceptionIf(
            ! $postId,
            'Can not find the post id'
        );

        $this->postData = [
            'fields' => $fields,
        ];

        $response = $this->getResponse($postId, $this->postData);

        return collect($response);
    }

    /**
     * Share
     */
    public function share(?string $message = null, string $image = null, ?array $options = null): self
    {
        return $this->publishImage($image, $message, $options);
    }
}
