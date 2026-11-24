<?php
/**
 * PublishingServiceInterface: Standard contract for social platform publishers
 */

interface PublishingServiceInterface {
    /**
     * Get platform identifier (facebook, instagram, tiktok, snapchat)
     */
    public function getPlatformName(): string;

    /**
     * Generate the official platform authorization URL to start OAuth
     */
    public function getAuthorizationUrl(string $redirectUri, string $state, array $extra = []): string;

    /**
     * Handle OAuth code exchange and return token details & account info
     * Returns: [
     *   'success' => bool,
     *   'account_id' => string,
     *   'account_name' => string,
     *   'account_username' => string|null,
     *   'avatar_url' => string|null,
     *   'access_token' => string,
     *   'refresh_token' => string|null,
     *   'expires_in' => int|null,
     *   'metadata' => array,
     *   'error' => string|null
     * ]
     */
    public function handleOAuthCallback(string $code, string $redirectUri, array $extra = []): array;

    /**
     * Validate media files against platform restrictions (formats, aspect ratios, file size, video length)
     * Returns: [
     *   'valid' => bool,
     *   'errors' => array of string error messages,
     *   'warnings' => array of string warning messages
     * ]
     */
    public function validateMedia(array $mediaFiles): array;

    /**
     * Publish a post to the platform
     * Returns: [
     *   'success' => bool,
     *   'external_post_id' => string|null,
     *   'external_post_url' => string|null,
     *   'error' => string|null,
     *   'raw_response' => array|null
     * ]
     */
    public function publish(array $account, array $post, array $mediaFiles = [], array $options = []): array;

    /**
     * Refresh access token if supported or required by the platform
     */
    public function refreshTokenIfNeeded(array $account): array;
}
