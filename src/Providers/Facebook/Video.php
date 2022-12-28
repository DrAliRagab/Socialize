<?php

namespace DrAliRagab\Socialize\Providers\Facebook;

/**
 * Use this endpoint to get and publish Video to a Page.
 */
trait Video
{
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
}
