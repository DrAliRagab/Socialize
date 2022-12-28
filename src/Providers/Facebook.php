<?php

namespace DrAliRagab\Socialize\Providers;

use DrAliRagab\Socialize\Providers\Facebook\Feed;
use DrAliRagab\Socialize\Providers\Facebook\Photo;
use DrAliRagab\Socialize\Providers\Facebook\Video;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class Facebook extends Provider implements ProviderInterface
{
    use Feed, Photo, Video;

    protected ?string $fbAppId;

    protected ?string $fbGraphVersion;

    protected ?string $fbPageId;

    protected ?string $fbPageAccessToken;

    public ?string $pagePostId = null;

    public ?int $photoId;

    public ?int $videoId;

    public function __construct(string $config = 'default')
    {
        $this->config = config('socialize.facebook.'.$config);

        $this->fbAppId = $this->config['app_id'];
        $this->fbGraphVersion = $this->config['graph_version'];
        $this->fbPageId = $this->config['page_id'];
        $this->fbPageAccessToken = $this->config['page_access_token'];

        $this->throwExceptionIf(
            ! $this->fbAppId
                || ! $this->fbGraphVersion
                || ! $this->fbPageId
                || ! $this->fbPageAccessToken,
            'Can not find the required configuration'
        );

        $this->Client = Http::withToken($this->fbPageAccessToken)->withOptions([
            'base_uri' => 'https://graph.facebook.com/'.$this->fbGraphVersion,
        ]);
    }

    /**
     * Share a post on the page
     */
    public function sharePost(): self
    {
        $response = $this->postResponse($this->fbPageId.'/feed', $this->postData);

        $this->pagePostId = $response['id'];

        return $this;
    }

    /**
     * get the posts of a Facebook Page.
     *
     * @param  int  $limit maximum number of posts to return. Default is 25. Maximum is 100.
     * @param  array  $fields fields to return. Default is id,created_time,message,story,permalink_url,full_picture,shares,likes,comments. You can specify additional fields. see https://developers.facebook.com/docs/graph-api/reference/page/feed#readfields
     */
    public function getPosts(int $limit = 25, array $fields = null): Collection
    {
        $fields ??= [
            'id',
            'created_time',
            'message',
            'story',
            'permalink_url',
            'full_picture',
            'shares',
            'likes',
            'comments',
        ];

        $this->postData = [
            'limit' => $limit,
            'fields' => implode(',', $fields),
        ];

        $response = $this->getResponse($this->fbPageId.'/feed', $this->postData);

        return collect([
            'data' => $response['data'],
            'next' => $response['paging']['next'] ?? null,
            'previous' => $response['paging']['previous'] ?? null,
        ]);
    }

    /**
     * get all public posts in which the page has been tagged.
     */
    public function getTaggedPosts(): Collection
    {
        $response = $this->getResponse($this->fbPageId.'/tagged');

        return collect([
            'data' => $response['data'],
            'next' => $response['paging']['next'] ?? null,
            'previous' => $response['paging']['previous'] ?? null,
        ]);
    }

    /**
     * get a post by id.
     *
     * @param  string  $postId the post id
     * @param  array  $fields fields to return. Default is id,created_time,message,story,permalink_url,full_picture,shares,likes,comments. You can specify additional fields. see https://developers.facebook.com/docs/graph-api/reference/pagepost#fields
     * @return Collection
     */
    public function getPost(string $postId, array $fields = null): Collection
    {
        $fields = [
            'id',
            'created_time',
            'message',
            'story',
            'permalink_url',
            'full_picture',
            'shares',
            'likes',
            'comments',
        ];

        $response = $this->getResponse($this->fbPageId.'_'.$postId, [
            'fields' => implode(',', $fields),
        ]);

        return collect($response);
    }

    /**
     * Delete a post by id.
     *
     * @param  int  $postId the post id
     */
    public function deletePost(int $postId): bool
    {
        $response = $this->deleteResponse($this->fbPageId.'_'.$postId);

        return $response['success'];
    }

    /**
     * Add comment to a post.
     */
    public function addComment(string $message, ?int $postId = null): self
    {
        $postId ??= $this->getPostId();

        $this->postData = [
            'message' => $message,
        ];

        $this->throwExceptionIf(! $postId, 'Post ID is required');

        $this->postResponse($this->fbPageId.'_'.$postId.'/comments', $this->postData);

        return $this;
    }

    /**
     * get comments of a post.
     *
     * @param  int  $postId the post id
     * @param  int  $limit maximum number of comments to return. Default is 25. Maximum is 100.
     * @param  array  $fields fields to return. Default is id,created_time,message,permalink_url. You can specify additional fields. see https://developers.facebook.com/docs/graph-api/reference/pagepost#fields
     */
    public function getComments(int $postId, int $limit = 25, array $fields = null): Collection
    {
        $fields = [
            'id',
            'created_time',
            'message',
            'permalink_url',
        ];

        $this->postData = [
            'limit' => $limit,
            'fields' => implode(',', $fields),
        ];

        $response = $this->getResponse($this->fbPageId.'_'.$postId.'/comments', $this->postData);

        return collect([
            'data' => $response['data'],
            'next' => $response['paging']['next'] ?? null,
            'previous' => $response['paging']['previous'] ?? null,
        ]);
    }

    /**
     * get url.
     */
    public function getUrl(): string
    {
        return 'https://www.facebook.com/'.$this->getPostId();
    }

    public function getPostId(): string
    {
        $postId = explode('_', $this->pagePostId)[1] ?? null;

        return (string) ($postId ?? $this->photoId ?? $this->videoId);
    }

    public function getMediaId(): int|null
    {
        return $this->photoId ?? $this->videoId;
    }

    /**
     * get page response.
     *
     * @param  string  $url the url to get the response from
     */
    public function getPage(string $url): Collection
    {
        $response = $this->getResponse($url);

        return collect([
            'data' => $response['data'],
            'next' => $response['paging']['next'] ?? null,
            'previous' => $response['paging']['previous'] ?? null,
        ]);
    }

    /**
     * Share
     */
    public function share(?string $message = null, ?string $image = null, ?array $options = null): self
    {
        $this->postData = array_merge($this->postData, $options);

        if ($image) {
            return $this->uploadPhoto($image, $message, true, false);
        }

        $this->throwExceptionIf(! $message, 'Message is required if no photo is provided');

        $this->setMessage($message);

        return $this->sharePost();
    }
}
