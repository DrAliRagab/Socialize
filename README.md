# Socialize your Laravel project easily

[![Latest Version on Packagist](https://img.shields.io/packagist/v/draliragab/socialize.svg?style=flat-square)](https://packagist.org/packages/draliragab/socialize)
[![License](https://img.shields.io/github/license/draliragab/socialize?style=flat-square)](https://github.com/DrAliRagab/Socialize/blob/master/LICENSE.md)


**Socialize** is a package that helps you to add social media features to your Laravel project easily.

You can share posts to **Facebook**, **Twitter**, **Instagram** and more is coming soon.

```php
$fb = Socialize::facebook()
        ->setMessage('Awesome message')
        ->setLink('https://github.com/')
        ->setAction(ogAction::FEELS, ogObject::EXCITED)
        ->sharePost();

dump($fb->getPostId()); // 123456789101112
```

[![Socialize](/imgs/1.png)](/imgs/1.png)

Or you can share posts using model trait.

```php
$post = Post::first();
$response = $post->shareToFacebook();

echo $response->getPostId(); // 123456789101112
```

## Table of Contents

- [Installation](#installation)
- [Configuration](#configuration)
- [Usage](#usage)
    + [Facebook](#facebook)
        - [Initialization](#initialization)
        - [Available Methods to set Options for sharePost() method](#available-methods-to-set-options-for-sharepost-method)
        - [uploadPhoto(), deletePhoto(), uploadVideo(), deleteVideo()](#uploadphoto-deletephoto-uploadvideo-deletevideo)
        - [getPosts()](#getposts)
        - [getTaggedPosts()](#gettaggedposts)
        - [getPost()](#getpost)
        - [deletePost()](#deletepost)
        - [getComments()](#getcomments)
        - [getUrl()](#geturl)
    + [Twitter](#twitter)
        - [Initialization](#initialization-1)
        - [tweet()](#tweet)
        - [available methods to set options for a tweet](#available-methods-to-set-options-for-a-tweet)
        - [uploadMedia(), getMediaIds()](#uploadmedia-getmediaids)
        - [addMedia()](#addmedia)
        - [deleteTweet()](#deletetweet)
    + [Instagram](#instagram)
        - [Initialization](#initialization-2)
        - [publishImage()](#publishimage)
        - [publishImageCarousel()](#publishimagecarousel)
        - [addComment()](#addcomment)
        - [getUrl()](#geturl-1)
        - [getPost()](#getpost-1)
    + [Traits](#traits)
        - [Socializer](#socializer)
        - [shareToFacebook(), shareToTwitter(), shareToInstagram()](#sharetofacebook-sharetotwitter-sharetoinstagram)
- [Changelog](#changelog)
- [Contributing](#contributing)
- [Security Vulnerabilities](#security-vulnerabilities)
- [Credits](#credits)
- [License](#license)


## Installation

You can install the package via composer:

```bash
composer require draliragab/socialize
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag="socialize-config"
```

This is the contents of the published config file:

```php
return [
    'facebook' => [
        'default' => [
            'app_id' => env('FACEBOOK_APP_ID'),
            'graph_version' => env('FACEBOOK_GRAPH_VERSION', 'v15.0'),
            'page_id' => env('FACEBOOK_PAGE_ID'),
            'page_access_token' => env('FACEBOOK_PAGE_ACCESS_TOKEN'),
        ],
    ],

    'instagram' => [
        'default' => [
            'graph_version' => env('INSTAGRAM_GRAPH_VERSION', 'v15.0'),
            'user_access_token' => env('INSTAGRAM_USER_ACCESS_TOKEN'),
            'instagram_account_id' => env('INSTAGRAM_ACCOUNT_ID'),
        ],
    ],

    'twitter' => [
        'default' => [
            'app_consumer_key' => env('TWITTER_CONSUMER_KEY'),
            'app_consumer_secret' => env('TWITTER_CONSUMER_SECRET'),
            'account_access_token' => env('TWITTER_ACCOUNT_ACCESS_TOKEN'),
            'account_access_token_secret' => env('TWITTER_ACCOUNT_ACCESS_TOKEN_SECRET'),
        ],
    ],

    'model_columns' => [
        'message_column' => 'title',
        'photo_column' => 'image',
    ],
];
```

## Configuration

For each social media you need to add the required credentials to your `.env` file or directly to the published config file.

By default, the package will use the `default` configuration. But you can publish to multiple pages and accounts by adding more configurations.

```php
return [
    'facebook' => [
        'default' => [
            'app_id' => env('FACEBOOK_APP_ID'),
            'graph_version' => env('FACEBOOK_GRAPH_VERSION', 'v15.0'),
            'page_id' => env('FACEBOOK_PAGE_ID'),
            'page_access_token' => env('FACEBOOK_PAGE_ACCESS_TOKEN'),
        ],

        'page_2' => [
            'app_id' => env('FACEBOOK_APP_ID_2'),
            'graph_version' => env('FACEBOOK_GRAPH_VERSION', 'v15.0'),
            'page_id' => env('FACEBOOK_PAGE_ID_2'),
            'page_access_token' => env('FACEBOOK_PAGE_ACCESS_TOKEN_2'),
        ],
    ],
];
```

and pass the configuration name to the initialization method.

```php
$fb = Socialize::facebook('page_2');
```

## Usage

All the social media providers have `share` and `getPostId` methods.

```php
$fb = Socialize::facebook()
        ->share(
            message: 'Awesome message',
            image: 'https:example.com/image.jpg',
            options: [],
        );

$postId = $fb->getPostId();
```

### Facebook

#### Initialization

```php
$fb = Socialize::facebook();
```

Accepts the configuration name as a parameter.

```php
use DrAliRagab\Socialize\Socialize;

$fb = Socialize::facebook('page_2');
```

#### Available Methods to set Options for sharePost() method

```php
setBackdatedTime(float|Carbon $backdated_time)
setBackdatedTimeGranularity(string $backdated_time_granularity) // One of ['year', 'month', 'day', 'hour', 'minute']
addChildAttachment(string $link, string $name = null, string $description = null, string $image_hash = null, string $picture = null)
setFeedTargeting(int $age_max = null, int $age_min = null, array $college_years = null, array $education_statuses = null, array $genders = null, array $geo_locations = null, array $interests = null, int $locales = null, array $relationship_statuses = null)
setLink(string $link)
setMessage(string $text)
setMultiShareEndCard(bool $multi_share_end_card = true)
setMultiShareOptimized(bool $multi_share_optimized = true)
setObjectAttachment(string $object_attachment)
setPlace(string $placeId)
setPublished(bool $published = true)
setScheduledPublishTime(int|Carbon $scheduled_publish_time)
setTags(array $tagsIds)
setTargeting(int $age_min = null, array $geo_locations = null)
setAction(ogAction $action, ogObject $object, ?ogIcon $icon = null)
attachMedia(int $mediaId)
attachMedias(array $mediaIds)

// After setting the options you can share the post
sharePost()

// Also after sharing you can add comments to the post
addComment('Awesome comment')

// And you ca delete the post
deletePost(int $postId) // returns true if the post is deleted successfully
```

Example:

```php
use DrAliRagab\Socialize\Socialize;
use DrAliRagab\Socialize\Enums\ogAction;
use DrAliRagab\Socialize\Enums\ogObject;

$fb = Socialize::facebook();
$response = $fb
    ->setBackdatedTime(now()->subDays(2))
    ->setBackdatedTimeGranularity('day')
    ->addChildAttachment(
        link: 'https://example.com/1',
        name: "Awesome name",
        description: "Awesome description",
        picture: "https://example.com/image.jpg"
    )
    ->addChildAttachment(
        link: 'https://example.com/2',
        name: "Awesome name 2",
        description: "Awesome description 2",
        picture: "https://example.com/image2.jpg"
    )
    ->setFeedTargeting(
        age_max: 65,
        age_min: 18,
    )
    ->setLink('https://example.com')
    ->setMessage('Awesome message')
    ->setMultiShareEndCard(true)
    ->setMultiShareOptimized(true)
    ->setPublished(true)
    ->setTargeting(
        age_min: 18
    )
    ->setAction(ogAction::FEELS, ogObject::EXCITED)
    ->sharePost()   // Must be called after setting the options
    ->addComment('Awesome comment');    // Must be called after sharing the post

$postId = $response->getPostId();   // Get the post id

// Delete the post
$deleted = $fb->deletePost($postId);
```

#### uploadPhoto(), deletePhoto(), uploadVideo(), deleteVideo()

```php
uploadPhoto(string $photoUrl, string $caption = null, bool $published = false, bool $temporary = true)
```

Upload a photo to a Page.

```php
$fb = Socialize::facebook();
$mediaId = $fb->uploadPhoto('https://example.com/image.jpg')->getMediaId();

// You can use the media id to attach it to a post
$postId = Socialize::facebook()
    ->attachMedia($mediaId)
    ->setMessage('Awesome image')
    ->sharePost()
    ->getPostId();
```

Or you can publish photos directly to a Page.

```php
$photoId = Socialize::facebook()
    ->uploadPhoto('https://example.com/image.jpg', 'Awesome image', true, false)
    ->getMediaId(); // returns the photo id

$deleted = Socialize::facebook()->deletePhoto($photoId); // returns true if the photo is deleted successfully
```

#### getPosts()

get the posts of a Facebook Page. Returns `Collection`.

```php
$data = Socialize::facebook()-getPosts();
```

Accepts two parameters:

- `$limit` maximum number of posts to return. Default is 25. Maximum is 100.
- `$fields` fields to return. see https://developers.facebook.com/docs/graph-api/reference/page/feed#readfields

#### getTaggedPosts()

get all public posts in which the page has been tagged.

#### getPost()

get a specific post by its id. Returns `Collection`.

accepts two parameters:

- `$postId` the post id
- `$fields` fields to return. see https://developers.facebook.com/docs/graph-api/reference/pagepost#fields

#### deletePost()

Delete a post by its id. Returns `true` if the post is deleted successfully.

#### getComments()

get comments of a post. Returns `Collection`.

accepts three parameters:

- `$postId` the post id
- `$limit` maximum number of comments to return. Default is 25. Maximum is 100.
- `$fields` fields to return. see https://developers.facebook.com/docs/graph-api/reference/pagepost#fields

#### getUrl()

get the url of a post.

### Twitter

#### Initialization

```php
$twitter = Socialize::twitter();
```

Accepts the configuration name as a parameter.

```php
use DrAliRagab\Socialize\Socialize;

$twitter = Socialize::twitter('account_2');
```

#### tweet()

Publish a tweet to a Twitter account.

```php
$twitter = Socialize::twitter();
$postId = $twitter->tweet('Awesome tweet')->getPostId();
```

#### available methods to set options for a tweet

```php
superFollowersOnly()
addPlace(string $placeId)
addPoll(array $pollOptions, int $pollDuration)
quoteTweet(string $tweetId)
restrictReply(string $restrictReply)    // "mentionedUsers" and "following" are the only options
inReplyTo(string $tweetId)
addMedia(array $mediaIds)
tagUsers(array $usernames)
```

Example:

```php
$postId = $twitter
    ->superFollowersOnly()
    ->addPoll(
        pollOptions: ['Disappointed 😞', 'Predictable 😐', 'Excited 😃'],
        pollDuration: 60,
    )
    ->quoteTweet('12345679101112')
    ->restrictReply('mentionedUsers')
    ->inReplyTo('12345679101112')
    ->tweet(
        text: 'https://example.com/',
    )->getPostId();

```

#### uploadMedia(), getMediaIds()

Upload media to the account

accepts an `array` of media paths.

```php
$imgPath = public_path('default-page-img.png');
$imgPath2 = public_path('default-page-img2.png');

$mediaIds = $twitter->uploadMedia([
    $imgPath,
    $imgPath2,
])->getMediaIds();
```

#### addMedia()

Add media to a tweet.

accepts an `array` of media ids.

```php
$postId = $twitter
    ->addMedia($mediaIds)
    ->tweet('Awesome tweet')->getPostId();
```

You can combine `uploadMedia()` and `addMedia()`.

```php
$postId = $twitter
    ->uploadMedia([$imgPath])
    ->addMedia()
    ->tweet('Awesome tweet')
    ->getPostId();
```

#### deleteTweet()

Delete a tweet by its id. Returns `true` if the tweet is deleted successfully.

### Instagram

#### Initialization

```php
$insta = Socialize::instagram();
```

Accepts the configuration name as a parameter.

```php
use DrAliRagab\Socialize\Socialize;

$insta = Socialize::instagram('account_2');
```

#### publishImage()

Publish an image to an Instagram account.

accepts three parameters:

- `$imageUrl` the image url
- `$caption` the caption of the image
- `$options` an array of options. see https://developers.facebook.com/docs/instagram-api/reference/ig-user/media#query-string-parameters

```php
$insta = Socialize::instagram();
$postId = $insta->publishImage('https://example.com/image.jpg', 'Awesome image')->getPostId();
```

#### publishImageCarousel()

Publish an image carousel to an Instagram account.

accepts three parameters:

- `$imageUrls` an array of image urls
- `$caption` the caption of the image
- `$options` an array of options. see https://developers.facebook.com/docs/instagram-api/reference/ig-user/media#query-string-parameters

```php
$postUrl = $insta
    ->publishImageCarousel([
        'https://example.com/image.jpg',
        'https://example.com/image2.jpg',
        'https://example.com/image3.jpg',
    ], 'Awesome image')
    ->addComment('Awesome image')
    ->getUrl();
```

#### addComment()

Add a comment to a post.

```php
$postId = Socialize::instagram()
    ->publishImage('https://example.com/image.jpg', 'Awesome image')
    ->addComment('Awesome image', $postId)
    ->getPostId();
```

#### getUrl()

get the url of a post.

```php
$postUrl = Socialize::instagram()
    ->publishImage('https://example.com/image.jpg', 'Awesome image')
    ->getUrl();
```

#### getPost()

get a specific post by its id. Returns `Collection`.

accepts two parameters:

- `$postId` the post id
- `$fields` fields to return. see https://developers.facebook.com/docs/instagram-api/reference/ig-media#fields

### Traits

#### Socializer

This trait is used to share to social media directly from the model.

```php
use DrAliRagab\Socialize\Traits\Socializer;

class Post extends Model
{
    use Socializer;
}
```

#### shareToFacebook(), shareToTwitter(), shareToInstagram()

Share to social media directly from the model.

```php
$post = Post::find(1);

$post->shareToFacebook();   // share to facebook
$post->shareToTwitter();    // share to twitter
$post->shareToInstagram();  // share to instagram
```

All of the above methods search for the image in the `image` column of the model and the message in the `title` column.

You can change the columns in the `config` file.

```php
'model_columns' => [
    'message_column' => 'title',
    'photo_column' => 'image',
],
```

Or you can pass the message, image and sharing options as array.

```php
$post->shareToInstagram([
    'photo' => 'https://example.com/image.jpg',
    'message' => 'Awesome post',
    'options' => [
        'scheduled_publish_time' => now()->addDays(2)->timestamp,
    ],
]);
```

Also you can pass the social media account.

```php
$post->shareToTwitter([
    'photo' => public_path('default-page-img.png'),
    'message' => 'Awesome post',
    'config' => 'account_2',
]);
```

<!-- ## Testing

To be done. -->

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Ali Ragab](https://github.com/DrAliRagab)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
