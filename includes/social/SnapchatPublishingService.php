<?php
/**
 * SnapchatPublishingService: Snapchat Public Profiles & Marketing API
 */

require_once __DIR__ . '/PublishingServiceInterface.php';
require_once __DIR__ . '/SocialSecurity.php';

class SnapchatPublishingService implements PublishingServiceInterface {
    private string $clientId;
    private string $clientSecret;

    public function __construct(string $clientId, string $clientSecret) {
        $this->clientId = trim($clientId);
        $this->clientSecret = trim($clientSecret);
    }

    public function getPlatformName(): string {
        return 'snapchat';
    }

    /**
     * Generate Snapchat OAuth authorization URL
     */
    public function getAuthorizationUrl(string $redirectUri, string $state, array $extra = []): string {
        $params = [
            'client_id' => $this->clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'snapchat-marketing-api snapchat-profile-api',
            'state' => $state
        ];

        return 'https://accounts.snapchat.com/login/oauth2/authorize?' . http_build_query($params);
    }

    /**
     * Exchange code for Snapchat tokens
     */
    public function handleOAuthCallback(string $code, string $redirectUri, array $extra = []): array {
        if (empty($this->clientId) || empty($this->clientSecret)) {
            return ['success' => false, 'error' => 'بيانات تطبيق سناب شات (Client ID / Secret) غير مهيأة في الإعدادات.'];
        }

        $tokenUrl = 'https://accounts.snapchat.com/login/oauth2/access_token';
        $postData = [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri
        ];

        $res = $this->sendHttpRequest($tokenUrl, 'POST', $postData);
        if (!$res['success'] || empty($res['data']['access_token'])) {
            return [
                'success' => false,
                'error' => 'فشل الحصول على توكن سناب شات: ' . ($res['data']['error_description'] ?? $res['error'])
            ];
        }

        $tokenData = $res['data'];
        $accessToken = $tokenData['access_token'];
        $refreshToken = $tokenData['refresh_token'] ?? null;
        $expiresIn = (int)($tokenData['expires_in'] ?? 86400);

        // Fetch user / organization info from Snapchat Ads API
        $meRes = $this->sendHttpRequest('https://adsapi.snapchat.com/v1/me', 'GET', [], [
            'Authorization: Bearer ' . $accessToken
        ]);

        $userData = $meRes['data']['me'] ?? [];
        $displayName = $userData['display_name'] ?? ($userData['email'] ?? 'Snapchat Profile');
        $username = '@' . ($userData['organization_id'] ?? 'snapchat');

        return [
            'success' => true,
            'account_id' => (string)($userData['id'] ?? uniqid('snap_')),
            'account_name' => $displayName,
            'account_username' => $username,
            'avatar_url' => null,
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in' => $expiresIn,
            'metadata' => [
                'organization_id' => $userData['organization_id'] ?? null,
                'email' => $userData['email'] ?? null,
                'profile_type' => 'public_profile'
            ],
            'error' => null
        ];
    }

    /**
     * Validate media files for Snapchat
     */
    public function validateMedia(array $mediaFiles): array {
        $errors = [];
        $warnings = [];

        if (empty($mediaFiles)) {
            $errors[] = "سناب شات يتطلب صورة أو فيديو بنسبة طولية 9:16 (المنشورات النصية فقط غير مدعومة).";
            return ['valid' => false, 'errors' => $errors, 'warnings' => $warnings];
        }

        foreach ($mediaFiles as $media) {
            $sizeMb = ($media['file_size'] ?? 0) / (1024 * 1024);
            if ($sizeMb > 100) {
                $errors[] = "حجم الملف ({$media['file_name']}) يتجاوز 100 ميجابايت الحد الأقصى لسناب شات.";
            }
        }

        $warnings[] = "تنبيه سناب شات: النشر التلقائي للقصص متاح فقط للحسابات العامة (Public Profiles) المعتمدة من Snap Inc.";

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings
        ];
    }

    /**
     * Publish to Snapchat Public Profile
     */
    public function publish(array $account, array $post, array $mediaFiles = [], array $options = []): array {
        $accessToken = SocialSecurity::decrypt($account['access_token']) ?: $account['access_token'];

        // Determine caption
        $caption = $post['caption'] ?? '';
        if (!empty($post['platform_captions'])) {
            $caps = is_array($post['platform_captions']) ? $post['platform_captions'] : json_decode($post['platform_captions'], true);
            if (!empty($caps['snapchat'])) {
                $caption = $caps['snapchat'];
            }
        }

        // Check if media attached
        if (empty($mediaFiles)) {
            return [
                'success' => false,
                'external_post_id' => null,
                'external_post_url' => null,
                'error' => 'سناب شات يتطلب ملف وسائط (صورة أو فيديو 9:16) لنشر القصة.'
            ];
        }

        $media = $mediaFiles[0];
        $publicMediaUrl = $this->resolvePublicMediaUrl($media['file_path']);

        // Check if tenant has approved public profile ID
        $profileId = $account['account_id'];
        $endpoint = "https://adsapi.snapchat.com/v1/public_profiles/{$profileId}/stories";

        $postData = [
            'caption' => mb_substr($caption, 0, 250),
            'media_url' => $publicMediaUrl,
            'media_type' => ($media['file_type'] === 'video') ? 'VIDEO' : 'IMAGE'
        ];

        $res = $this->sendHttpRequest($endpoint, 'POST_JSON', $postData, [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json'
        ]);

        if ($res['success'] && !empty($res['data']['story']['id'])) {
            $storyId = (string)$res['data']['story']['id'];
            return [
                'success' => true,
                'external_post_id' => $storyId,
                'external_post_url' => "https://story.snapchat.com/",
                'error' => null,
                'raw_response' => $res['data']
            ];
        }

        // If app requires Snap allowlisting or returned standard review error
        $errorMsg = $res['data']['display_message'] ?? ($res['data']['error_description'] ?? $res['error']);
        if (str_contains($errorMsg, '403') || str_contains($errorMsg, 'Unauthorized') || str_contains($errorMsg, 'approval') || str_contains($errorMsg, 'permission')) {
            $errorMsg = 'حساب سناب شات يتطلب موافقة واعتماد الملف التعريفي العام (Public Profile Allowlisting) من Snap Inc. يمكنك نسخ المحتوى ونشره يدوياً من تطبيق سناب شات.';
        }

        return [
            'success' => false,
            'external_post_id' => null,
            'external_post_url' => null,
            'error' => 'سناب شات: ' . $errorMsg,
            'raw_response' => $res['data'],
            'is_manual_fallback_recommended' => true
        ];
    }

    public function refreshTokenIfNeeded(array $account): array {
        $refreshToken = SocialSecurity::decrypt($account['refresh_token'] ?? '');
        if (empty($refreshToken)) {
            return ['success' => false, 'refreshed' => false, 'error' => 'No refresh token available'];
        }

        $url = 'https://accounts.snapchat.com/login/oauth2/access_token';
        $postData = [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken
        ];

        $res = $this->sendHttpRequest($url, 'POST', $postData);
        if ($res['success'] && !empty($res['data']['access_token'])) {
            return [
                'success' => true,
                'refreshed' => true,
                'access_token' => $res['data']['access_token'],
                'refresh_token' => $res['data']['refresh_token'] ?? $refreshToken,
                'expires_in' => $res['data']['expires_in'] ?? 86400
            ];
        }

        return ['success' => false, 'refreshed' => false, 'error' => $res['error']];
    }

    private function resolvePublicMediaUrl(string $relativePath): string {
        if (filter_var($relativePath, FILTER_VALIDATE_URL)) {
            return $relativePath;
        }

        $base = SocialSecurity::getAppBaseUrl(getDBConnection());
        return rtrim($base, '/') . '/' . ltrim($relativePath, '/');
    }

    private function sendHttpRequest(string $url, string $method = 'GET', array $params = [], array $headers = []): array {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        } elseif ($method === 'POST_JSON') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params, JSON_UNESCAPED_UNICODE));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        @curl_close($ch);

        if ($curlError) {
            return ['success' => false, 'http_code' => $httpCode, 'error' => $curlError, 'data' => null];
        }

        $data = json_decode($response, true) ?? [];
        $isSuccess = ($httpCode >= 200 && $httpCode < 300);

        return [
            'success' => $isSuccess,
            'http_code' => $httpCode,
            'error' => $data['error_description'] ?? ($isSuccess ? null : "HTTP {$httpCode}"),
            'data' => $data
        ];
    }
}
