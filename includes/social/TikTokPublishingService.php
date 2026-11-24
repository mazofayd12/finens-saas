<?php
/**
 * TikTokPublishingService: Official TikTok Content Posting API v2
 */

require_once __DIR__ . '/PublishingServiceInterface.php';
require_once __DIR__ . '/SocialSecurity.php';

class TikTokPublishingService implements PublishingServiceInterface {
    private string $clientKey;
    private string $clientSecret;

    public function __construct(string $clientKey, string $clientSecret) {
        $this->clientKey = trim($clientKey);
        $this->clientSecret = trim($clientSecret);
    }

    public function getPlatformName(): string {
        return 'tiktok';
    }

    /**
     * Generate TikTok OAuth 2.0 authorization URL with PKCE
     */
    public function getAuthorizationUrl(string $redirectUri, string $state, array $extra = []): string {
        $pkce = SocialSecurity::generatePkce();

        $params = [
            'client_key' => $this->clientKey,
            'scope' => 'user.info.basic,video.upload,video.publish',
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'code_challenge' => $pkce['code_challenge'],
            'code_challenge_method' => 'S256'
        ];

        return 'https://www.tiktok.com/v2/auth/authorize/?' . http_build_query($params);
    }

    /**
     * Exchange code for TikTok Access Token and fetch user profile
     */
    public function handleOAuthCallback(string $code, string $redirectUri, array $extra = []): array {
        if (empty($this->clientKey) || empty($this->clientSecret)) {
            return ['success' => false, 'error' => 'بيانات تطبيق تيك توك (Client Key / Secret) غير مهيأة في الإعدادات.'];
        }

        $codeVerifier = SocialSecurity::getPkceVerifier() ?? ($extra['code_verifier'] ?? '');

        // 1. Request access token
        $tokenUrl = 'https://open.tiktokapis.com/v2/oauth/token/';
        $postData = [
            'client_key' => $this->clientKey,
            'client_secret' => $this->clientSecret,
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri
        ];
        if (!empty($codeVerifier)) {
            $postData['code_verifier'] = $codeVerifier;
        }

        $res = $this->sendHttpRequest($tokenUrl, 'POST', $postData, ['Content-Type: application/x-www-form-urlencoded']);

        if (!$res['success'] || empty($res['data']['access_token'])) {
            $errMsg = $res['data']['error_description'] ?? ($res['data']['message'] ?? $res['error']);
            return ['success' => false, 'error' => 'فشل الحصول على توكن تيك توك: ' . $errMsg];
        }

        $tokenData = $res['data'];
        $accessToken = $tokenData['access_token'];
        $refreshToken = $tokenData['refresh_token'] ?? null;
        $expiresIn = (int)($tokenData['expires_in'] ?? 86400);
        $openId = $tokenData['open_id'] ?? '';

        // 2. Fetch User Profile Info
        $userUrl = 'https://open.tiktokapis.com/v2/user/info/?fields=open_id,union_id,avatar_url,display_name,username';
        $userRes = $this->sendHttpRequest($userUrl, 'GET', [], [
            'Authorization: Bearer ' . $accessToken
        ]);

        $userData = $userRes['data']['data']['user'] ?? [];
        $displayName = $userData['display_name'] ?? ('TikTok User ' . substr($openId, 0, 6));
        $username = !empty($userData['username']) ? ('@' . ltrim($userData['username'], '@')) : ('@' . substr($openId, 0, 8));
        $avatarUrl = $userData['avatar_url'] ?? null;

        return [
            'success' => true,
            'account_id' => $openId,
            'account_name' => $displayName,
            'account_username' => $username,
            'avatar_url' => $avatarUrl,
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in' => $expiresIn,
            'metadata' => [
                'union_id' => $userData['union_id'] ?? null,
                'open_id' => $openId
            ],
            'error' => null
        ];
    }

    /**
     * Validate media files for TikTok Content Posting
     */
    public function validateMedia(array $mediaFiles): array {
        $errors = [];
        $warnings = [];

        if (empty($mediaFiles)) {
            $errors[] = "تيك توك يتطلب إرفاق فيديو لنشر المحتوى (المنشورات النصية فقط غير مدعومة).";
            return ['valid' => false, 'errors' => $errors, 'warnings' => $warnings];
        }

        $videoCount = 0;
        foreach ($mediaFiles as $media) {
            $type = $media['file_type'] ?? 'image';
            if ($type === 'video') {
                $videoCount++;
                $sizeMb = ($media['file_size'] ?? 0) / (1024 * 1024);
                if ($sizeMb > 500) {
                    $errors[] = "حجم الفيديو ({$media['file_name']}) يتجاوز 500 ميجابايت الحد الأقصى لتيك توك.";
                }
            } else {
                $warnings[] = "تيك توك مخصص لمقاطع الفيديو؛ سيتم تجاهل الصور المرفقة أو تحويلها لفيديو إن أمكن.";
            }
        }

        if ($videoCount === 0) {
            $errors[] = "لم يتم تحديد أي ملف فيديو. النشر على تيك توك يتطلب ملف فيديو بصيغة MP4 أو MOV.";
        } elseif ($videoCount > 1) {
            $errors[] = "تيك توك يدعم نشر فيديو واحد فقط لكل منشور.";
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings
        ];
    }

    /**
     * Publish video to TikTok via Direct Post Content Posting API v2
     */
    public function publish(array $account, array $post, array $mediaFiles = [], array $options = []): array {
        $videoFile = null;
        foreach ($mediaFiles as $m) {
            if (($m['file_type'] ?? '') === 'video') {
                $videoFile = $m;
                break;
            }
        }

        if (!$videoFile) {
            return [
                'success' => false,
                'external_post_id' => null,
                'external_post_url' => null,
                'error' => 'تيك توك يتطلب ملف فيديو بصيغة MP4 أو MOV.'
            ];
        }

        $accessToken = SocialSecurity::decrypt($account['access_token']) ?: $account['access_token'];

        // Determine caption
        $caption = $post['caption'] ?? '';
        if (!empty($post['platform_captions'])) {
            $caps = is_array($post['platform_captions']) ? $post['platform_captions'] : json_decode($post['platform_captions'], true);
            if (!empty($caps['tiktok'])) {
                $caption = $caps['tiktok'];
            }
        }

        $videoPublicUrl = $this->resolvePublicMediaUrl($videoFile['file_path']);

        // Check creator posting permissions first
        $creatorCheckUrl = 'https://open.tiktokapis.com/v2/post/publish/creator_info/query/';
        $creatorRes = $this->sendHttpRequest($creatorCheckUrl, 'POST_JSON', [], [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json; charset=UTF-8'
        ]);

        $privacyLevel = 'PUBLIC_TO_EVERYONE';
        if ($creatorRes['success'] && !empty($creatorRes['data']['data']['privacy_level_options'])) {
            $options = $creatorRes['data']['data']['privacy_level_options'];
            if (!in_array('PUBLIC_TO_EVERYONE', $options) && !empty($options[0])) {
                $privacyLevel = $options[0];
            }
        }

        // Initialize Video Post via PULL_FROM_URL
        $initUrl = 'https://open.tiktokapis.com/v2/post/publish/video/init/';
        $initPayload = [
            'post_info' => [
                'title' => mb_substr($caption, 0, 2200),
                'privacy_level' => $privacyLevel,
                'disable_duet' => false,
                'disable_comment' => false,
                'disable_stitch' => false,
                'video_cover_timestamp_ms' => 1000
            ],
            'source_info' => [
                'source' => 'PULL_FROM_URL',
                'video_url' => $videoPublicUrl
            ]
        ];

        $initRes = $this->sendHttpRequest($initUrl, 'POST_JSON', $initPayload, [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json; charset=UTF-8'
        ]);

        if ($initRes['success'] && !empty($initRes['data']['data']['publish_id'])) {
            $publishId = $initRes['data']['data']['publish_id'];

            return [
                'success' => true,
                'external_post_id' => $publishId,
                'external_post_url' => "https://www.tiktok.com/",
                'error' => null,
                'raw_response' => $initRes['data']
            ];
        }

        $errMsg = $initRes['data']['error']['message'] ?? ($initRes['data']['message'] ?? $initRes['error']);
        return [
            'success' => false,
            'external_post_id' => null,
            'external_post_url' => null,
            'error' => 'فشل إرسال الفيديو لتيك توك: ' . $errMsg,
            'raw_response' => $initRes['data']
        ];
    }

    /**
     * Refresh TikTok Access Token
     */
    public function refreshTokenIfNeeded(array $account): array {
        $refreshToken = SocialSecurity::decrypt($account['refresh_token'] ?? '');
        if (empty($refreshToken)) {
            return ['success' => false, 'refreshed' => false, 'error' => 'No refresh token available'];
        }

        $url = 'https://open.tiktokapis.com/v2/oauth/token/';
        $postData = [
            'client_key' => $this->clientKey,
            'client_secret' => $this->clientSecret,
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken
        ];

        $res = $this->sendHttpRequest($url, 'POST', $postData, ['Content-Type: application/x-www-form-urlencoded']);
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
        $isSuccess = ($httpCode >= 200 && $httpCode < 300) && empty($data['error']['code']);

        return [
            'success' => $isSuccess,
            'http_code' => $httpCode,
            'error' => $data['error']['message'] ?? ($isSuccess ? null : "HTTP {$httpCode}"),
            'data' => $data
        ];
    }
}
