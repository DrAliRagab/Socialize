<?php

namespace DrAliRagab\Socialize\Providers;

use Carbon\Carbon;
use DrAliRagab\Socialize\Enums\ogAction;
use DrAliRagab\Socialize\Enums\ogIcon;
use DrAliRagab\Socialize\Enums\ogObject;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class Facebook extends Provider implements ProviderInterface
{
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
        $fields ??= [
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
     * Specifies a time in the past to backdate
     */
    public function setBackdatedTime(float|Carbon $backdated_time): self
    {
        if ($backdated_time instanceof Carbon) {
            $backdated_time = $backdated_time->timestamp;
        }

        $this->postData['backdated_time'] = $backdated_time;

        return $this;
    }

    /**
     * Controls the display of how a backdated post appears. For example, if you pick month posts will be displayed as 2 months ago instead of an exact date.
     *
     * @param  string  $backdated_time_granularity. One of ['year', 'month', 'day', 'hour', 'minute']
     */
    public function setBackdatedTimeGranularity(string $backdated_time_granularity): self
    {
        $this->throwExceptionIf(
            ! in_array($backdated_time_granularity, ['year', 'month', 'day', 'hour', 'minute']),
            'Invalid backdated_time_granularity. Must be one of [year, month, day, hour, minute]',
            400
        );

        $this->postData['backdated_time_granularity'] = $backdated_time_granularity;

        return $this;
    }

    /**
     * Add child attachment: Use to specify multiple links in the post. Minimum 2 and maximum of 5 objects. If you set multi_share_optimized to true, you can upload a maximum of 10 objects but Facebook will display the top 5.
     *
     * @param  string  $link The URL of a link to attach to the post. This field is required.
     * @param  string  $name The title of the link preview. If not specified, the title of the linked page will be used. This field will typically be truncated after 35 characters. It is recommended to set a unique name, as Facebook interfaces show actions reported on the name field.
     * @param  string  $description Used to show either a price, discount or website domain. If not specified, the content of the linked page will be extracted and used. This field will typically be truncated after 30 characters.
     * @param  string  $image_hash Hash of a preview image associated with the link from your ad image library (1:1 aspect ratio and a minimum of 458 x 458 px for best display). Either picture or image_hash must be specified.
     * @param  string  $picture A URL that determines the preview image associated with the link (1:1 aspect ratio and a minimum of 458 x 458 px for best display). Either picture or image_hash must be specified.
     * @return $this
     */
    public function addChildAttachment(string $link, string $name = null, string $description = null, string $image_hash = null, string $picture = null): self
    {
        $this->throwExceptionIf(
            ! $image_hash && ! $picture,
            'Either picture or image_hash must be specified',
            500
        );

        $childAttachment = [
            'link' => $link,
            'description' => $description,
            'image_hash' => $image_hash,
            'name' => $name,
            'picture' => $picture,
        ];

        $this->postData['child_attachments'][] = $childAttachment;

        return $this;
    }

    /**
     * Add feed targeting: Object that controls Feed Targeting for this content. Anyone in these groups will be more likely to see this content, those not will be less likely, but may still see it anyway. Any of the targeting fields shown here can be used, none are required.
     *
     * @param  int  $age_max Maximum age. Must be 65 or lower.
     * @param  int  $age_min Minimum age. Must be 13 or higher. Default is 0.
     * @param  int[]  $college_years Array of integers for graduation year from college.
     * @param  int[]  $education_statuses Array of integers for targeting based on education level. Use 1 for high school, 2 for undergraduate, and 3 for alum (or localized equivalents).
     * @param  int[]  $genders Target specific genders. 1 targets all male viewers and 2 females. Default is to target both.
     * @param  array  $geo_locations This object allows you to specify a number of different geographic locations. Please see our targeting guide for information on this object.
     * @param  int[]  $interests One or more IDs to target fans. Use type=audienceinterest to get possible IDs as Targeting Options and use the returned id to specify.
     * @param  int  $locales Targeted locales. Use type of adlocale to find Targeting Options and use the returned key to specify.
     * @param  int[]  $relationship_statuses Array of integers for targeting based on relationship status. Use 1 for single, 2 for 'in a relationship', 3 for married, and 4 for engaged. Default is all types.
     * @return $this
     */
    public function setFeedTargeting(int $age_max = null, int $age_min = null, array $college_years = null, array $education_statuses = null, array $genders = null, array $geo_locations = null, array $interests = null, int $locales = null, array $relationship_statuses = null): self
    {
        $this->postData['feed_targeting'] = [
            'age_max' => $age_max,
            'age_min' => $age_min,
            'college_years' => $college_years,
            'education_statuses' => $education_statuses,
            'genders' => $genders,
            'geo_locations' => $geo_locations,
            'interests' => $interests,
            'locales' => $locales,
            'relationship_statuses' => $relationship_statuses,
        ];

        return $this;
    }

    /**
     * The URL of a link to attach to the post. Either link or message must be supplied.
     */
    public function setLink(string $link): self
    {
        $this->postData['link'] = $link;

        return $this;
    }

    /**
     * The main body of the post. The message can contain mentions of Facebook Pages, @[page-id].
     */
    public function setMessage(string $text): self
    {
        $this->postData['message'] = $text;

        return $this;
    }

    /**
     * If set to false, does not display the end card of a carousel link post when child_attachments is used. Default is true.
     */
    public function setMultiShareEndCard(bool $multi_share_end_card = true): self
    {
        $this->postData['multi_share_end_card'] = $multi_share_end_card;

        return $this;
    }

    /**
     * multi_share_optimized If set to true and only when the post is used in an ad, Facebook will automatically select the order of links in child_attachments. Otherwise, the original ordering of child_attachments is preserved. Default value is true.
     */
    public function setMultiShareOptimized(bool $multi_share_optimized = true): self
    {
        $this->postData['multi_share_optimized'] = $multi_share_optimized;

        return $this;
    }

    /**
     * Facebook ID for an existing picture in the person's photo albums to use as the thumbnail image. They must be the owner of the photo, and the photo cannot be part of a message attachment.
     */
    public function setObjectAttachment(string $object_attachment): self
    {
        $this->postData['object_attachment'] = $object_attachment;

        return $this;
    }

    /**
     * Page ID of a location associated with this post.
     */
    public function setPlace(string $placeId): self
    {
        $this->postData['place'] = $placeId;

        return $this;
    }

    /**
     * Whether a story is shown about this newly published object. Default is true which means the story is displayed in Feed. This field is not supported when actions parameter is specified. Unpublished posts can be used in ads.
     */
    public function setPublished(bool $published = true): self
    {
        $this->postData['published'] = $published;

        return $this;
    }

    /**
     * UNIX timestamp indicating when post should go live. Must be date between 10 minutes and 75 days from the time of the API request.
     */
    public function setScheduledPublishTime(int|Carbon $scheduled_publish_time): self
    {
        if ($scheduled_publish_time instanceof Carbon) {
            $scheduled_publish_time = $scheduled_publish_time->timestamp;
        }

        $this->postData['scheduled_publish_time'] = $scheduled_publish_time;

        return $this;
    }

    /**
     * list of user IDs of people tagged in this post. You cannot specify this field without also specifying a place.
     */
    public function setTags(array $tagsIds): self
    {
        $this->postData['tags'] = implode(',', $tagsIds);

        return $this;
    }

    /**
     * limit the audience for this content. Anyone not in these demographics will not be able to view this content. This will not override any Page-level demographic restrictions that may be in place.
     *
     * @param  int  $age_min Value can be 13, 15, 18, 21, or 25.
     * @param  array  $geo_locations This object allows you to specify a number of different geographic locations.
     */
    public function setTargeting(int $age_min = null, array $geo_locations = null): self
    {
        $this->postData['targeting'] = [
            'age_min' => $age_min,
            'geo_locations' => $geo_locations,
        ];

        return $this;
    }

    /**
     * Add a feeling or activity and an icon to a page post. og_action_type_id and og_object_id are required when posting a feeling or activity. og_icon_id is optional however if not used an icon will be automatically supplied based on the og_object_id.
     */
    public function setAction(ogAction $action, ogObject $object, ?ogIcon $icon = null): self
    {
        $this->postData['og_action_type_id'] = $action->value;
        $this->postData['og_object_id'] = $object->value;

        if ($icon) {
            $this->postData['og_icon_id'] = $icon->value;
        }

        return $this;
    }

    /**
     * Attach media to the post.
     */
    public function attachMedia(int $mediaId): self
    {
        $this->postData['attached_media'][] = '{"media_fbid":"'.$mediaId.'"}';

        return $this;
    }

    /**
     * Attach medias to the post.
     */
    public function attachMedias(array $mediaIds): self
    {
        foreach ($mediaIds as $mediaId) {
            $this->attachMedia($mediaId);
        }

        return $this;
    }

    /**
     * Upload a photo to a Page.
     */
    public function uploadPhoto(string $photoUrl, string $caption = null, bool $published = false, bool $temporary = true): self
    {
        $this->postData = array_merge([
            'url' => $photoUrl,
            'published' => $published,
            'temporary' => $temporary,
        ], $this->postData);

        if ($caption) {
            $this->postData['caption'] = $caption;
        }

        $response = $this->postResponse($this->fbPageId.'/photos', $this->postData);

        $this->photoId = $response['id'];

        return $this;
    }

    /**
     * Delete a photo from a Page.
     */
    public function deletePhoto(int $photoId): bool
    {
        $response = $this->deleteResponse((string) $photoId);

        return $response['success'];
    }

    /**
     * Upload a video to a Page.
     *
     * @return int The ID of the video
     */
    public function uploadVideo(string $videoUrl, string $title = null, bool $publish = false): int
    {
        $this->postData = array_merge([
            'file_url' => $videoUrl,
            'published' => $publish,
        ], $this->postData);

        if ($title) {
            $this->postData['title'] = $title;
        }

        $response = $this->postResponse($this->fbPageId.'/videos', $this->postData);

        $this->videoId = $response['id'];

        return $response['id'];
    }

    /**
     * Delete a video from a Page.
     */
    public function deleteVideo(int $videoId): bool
    {
        $response = $this->deleteResponse((string) $videoId);

        return $response['success'];
    }

    /**
     * Share
     */
    public function share(?string $message = null, ?string $image = null, ?array $options = []): self
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
