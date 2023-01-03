<?php

namespace DrAliRagab\Socialize\Providers;

use Abraham\TwitterOAuth\TwitterOAuth;

class Twitter extends Provider implements ProviderInterface
{
    protected ?string $twitterConsumerKey;

    protected ?string $twitterConsumerSecret;

    protected ?string $twitterAccountAccessToken;

    protected ?string $twitterAccountAccessTokenSecret;

    protected ?string $ApiVersion = '2';

    protected ?array $mediaIds = [];

    public ?string $tweetId;

    public function __construct(string $config = 'default')
    {
        $this->config = config('socialize.twitter.'.$config);

        $this->twitterConsumerKey = $this->config['app_consumer_key'];
        $this->twitterConsumerSecret = $this->config['app_consumer_secret'];
        $this->twitterAccountAccessToken = $this->config['account_access_token'];
        $this->twitterAccountAccessTokenSecret = $this->config['account_access_token_secret'];

        $this->Client = new TwitterOAuth(
            $this->twitterConsumerKey,
            $this->twitterConsumerSecret,
            $this->twitterAccountAccessToken,
            $this->twitterAccountAccessTokenSecret
        );
    }

    private function makeRequest(string $method, string $Apiversion, string $endpoint, ?array $data = []): mixed
    {
        $this->Client->setApiVersion($Apiversion);

        $response = $this->Client->$method($endpoint, $data, true);

        $this->throwExceptionIf(isset($response->errors), "Error in $method $endpoint", 500, (array) $response);

        return $response;
    }

    /**
     * Tweet to the account
     */
    public function tweet(?string $text): self
    {
        if ($text) {
            $this->postData['text'] = $text;
        }

        // Tweet using v2 API: Supports polls, super followers, and more
        $response = $this->makeRequest('post', '2', 'tweets', $this->postData);

        $this->tweetId = $response->data->id;

        return $this;
    }

    /**
     * Set for super followers only
     */
    public function superFollowersOnly(): self
    {
        $this->postData['for_super_followers_only'] = 'true';

        return $this;
    }

    /**
     * Add place to the tweet
     */
    public function addPlace(string $placeId): self
    {
        $this->postData['geo'] = [
            'place_id' => $placeId,
        ];

        return $this;
    }

    /**
     * Add poll to the tweet
     */
    public function addPoll(array $pollOptions, int $pollDuration): self
    {
        $this->postData['poll'] = [
            'options' => $pollOptions,
            'duration_minutes' => $pollDuration,
        ];

        return $this;
    }

    /**
     * Quote a tweet
     */
    public function quoteTweet(string $tweetId): self
    {
        $this->postData['quote_tweet_id'] = $tweetId;

        return $this;
    }

    /**
     * Restrict who can reply to the tweet; "mentionedUsers" and "following" are the only options
     */
    public function restrictReply(string $restrictReply): self
    {
        $this->postData['reply_settings'] = $restrictReply;

        return $this;
    }

    /**
     * Tweet as a reply to another tweet
     */
    public function inReplyTo(string $tweetId): self
    {
        $this->postData['reply'] = [
            'in_reply_to_tweet_id' => $tweetId,
        ];

        return $this;
    }

    /**
     * Search for a place
     */
    public function queryPlace(string $query, string $granularity = 'neighborhood'): self
    {
        // Query places done using v1.1
        $response = $this->makeRequest('get', '1.1', 'geo/search', [
            'query' => $query,
            'granularity' => $granularity,
        ]);

        $placeId = $response?->result?->places[0]?->id ?? null;

        if ($placeId) {
            $this->postData['geo'] = [
                'place_id' => $placeId,
            ];
        }

        return $this;
    }

    /**
     * Add media to the tweet
     */
    public function addMedia(?array $mediaIds = null): self
    {
        $mediaIds ??= $this->mediaIds;

        $this->postData['media']['media_ids'] = $mediaIds;

        return $this;
    }

    /**
     * Tag users in the tweet
     */
    public function tagUsers(array $userIds): self
    {
        $this->postData['media']['tagged_user_ids'] = $userIds;

        return $this;
    }

    /**
     * Delete a tweet
     */
    public function deleteTweet(string $tweetId): bool
    {
        $this->makeRequest('delete', '2', "tweets/$tweetId");

        return true;
    }

    /**
     * Upload media to the account
     */
    public function uploadMedia(array $mediaPath): self
    {
        $mediaIds = [];

        foreach ($mediaPath as $media) {
            $mediaIds[] = $this->makeRequest('upload', '1.1', 'media/upload', [
                'media' => $media,
            ])->media_id_string;
        }

        $this->mediaIds = $mediaIds;

        return $this;
    }

    /**
     * get media ids
     */
    public function getMediaIds(): array|null
    {
        return $this->mediaIds;
    }

    /**
     * Add Comment to a tweet
     */
    public function addComment(string $message, ?string $tweetId = null): self
    {
        $tweetId ??= $this->tweetId;

        $postData = [
            'text' => $message,
            'reply' => [
                'in_reply_to_tweet_id' => $tweetId,
            ],
        ];

        $this->makeRequest('post', '2', 'tweets', $postData);

        return $this;
    }

    /**
     * Share
     */
    public function share(?string $message = null, ?string $image = null, ?array $options = []): self
    {
        $this->postData = array_merge($this->postData, $options ?? []);

        if (! $image) {
            $this->throwExceptionIf(! $message, 'Message is required');

            return $this->tweet($message);
        }

        $mediaIds = $this->uploadMedia([$image])->getMediaIds();

        $this->throwExceptionIf(! $mediaIds, 'Error uploading media');

        return $this->addMedia($mediaIds)->tweet($message);
    }

    public function getPostId(): string
    {
        return $this->tweetId;
    }
}
