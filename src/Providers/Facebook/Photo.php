<?php

namespace DrAliRagab\Socialize\Providers\Facebook;

/**
 * Use this endpoint to get and publish Photo to a Page.
 */
trait Photo
{
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
}
