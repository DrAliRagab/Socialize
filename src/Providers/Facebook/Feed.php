<?php

namespace DrAliRagab\Socialize\Providers\Facebook;

use Carbon\Carbon;
use DrAliRagab\Socialize\Enums\ogAction;
use DrAliRagab\Socialize\Enums\ogIcon;
use DrAliRagab\Socialize\Enums\ogObject;

/**
 * Use this endpoint to get and publish Posts to a Page.
 */
trait Feed
{
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
}
