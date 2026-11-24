<?php
/**
 * InstagramPublishingService: Meta Graph API integration for Instagram Professional Accounts (Business / Creator)
 */

require_once __DIR__ . '/PublishingServiceInterface.php';
require_once __DIR__ . '/SocialSecurity.php';

class InstagramPublishingService implements PublishingServiceInterface {
    private string $appId;
    private string $appSecret;
    private string $apiVersion;

    public function __construct(string $appId, string $appSecret, string $apiVersion = 'v20.0') {
        $this->appId = trim($appId);
        $this->appSecret = trim($appSecret);
        $this->apiVersion = trim($apiVersion) ?: 'v20.0';
    }

    public function getPlatformName(): string {
        return 'instagram';
    }

    /**
     * Official Instagram OAuth authorization URL (via Meta Login Dialog)
     */
    public function getAuthorizationUrl(string $redirectUri, string $state, array $extra = []): string {
        $scopes = [
            'instagram_basic',
            'instagram_content_publish',
            'pages_show_list',
            'pages_read_engagement',
            'public_profile'
        ];

        $params = [
            'client_id' => $this->appId,
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'scope' => implode(',', $scopes),
            'response_type' => 'code'
        ];

        return 'https://www.facebook.com/' . $this->apiVersion . '/dialog/oauth?' . http_build_query($params);
    }

    /**
     * Handle OAuth callback: Exchange code, get Page token, then retrieve Instagram Business Account ID
     */
    public function handleOAuthCallback(string $code, string $redirectUri, array $extra = []): array {
        if (empty($this->appId) || empty($this->appSecret)) {
            return ['success' => false, 'error' => 'بيانات تطبيق إنستغرام (Meta App ID / Secret) غير مهيأة في الإعدادات.'];
        }

        // 1. Exchange code for short-lived User Token
        $tokenUrl = "https://graph.facebook.com/{$this->apiVersion}/oauth/access_token?" . http_build_query([
            'client_id' => $this->appId,
            'client_secret' => $this->appSecret,
            'redirect_uri' => $redirectUri,
            'code' => $code
        ]);

        $res = $this->sendHttpRequest($tokenUrl);
        if (!$res['success'] || empty($res['data']['access_token'])) {
            return [
                'success' => false,
                'error' => 'فشل استبدال كود التفويض من إنستغرام: ' . ($res['data']['error']['message'] ?? $res['error'])
            ];
        }

        $userToken = $res['data']['access_token'];

        // 2. Fetch Pages and their connected Instagram Business Accounts
        $pagesUrl = "https://graph.facebook.com/{$this->apiVersion}/me/accounts?fields=id,name,access_token,instagram_business_account{id,username,name,profile_picture_url}&access_token=" . urlencode($userToken);
        $pagesRes = $this->sendHttpRequest($pagesUrl);

        if (!$pagesRes['success'] || empty($pagesRes['data']['data'])) {
            return [
                'success' => false,
                'error' => 'لم يتم العثور على أي صفحات فيسبوك مرتبطة بحسابك.'
            ];
        }

        $pages = $pagesRes['data']['data'];
        $igAccount = null;
        $pageAccessToken = null;

        foreach ($pages as $p) {
            if (!empty($p['instagram_business_account'])) {
                $igAccount = $p['instagram_business_account'];
                $pageAccessToken = $p['access_token'];
                break;
            }
        }

        if (!$igAccount || empty($igAccount['id'])) {
            return [
                'success' => false,
                'error' => 'لم يتم العثور على حساب إنستغرام احترافي (Business / Creator) مرتبط بصفحاتك على فيسبوك. تأكد من تحويل حساب إنستغرام إلى حساب احترافي وربطه بصفحة فيسبوك أولاً.'
            ];
        }

        $igUserId = (string)$igAccount['id'];
        $igUsername = (string)($igAccount['username'] ?? $igUserId);
        $igName = (string)($igAccount['name'] ?? $igUsername);
        $avatarUrl = $igAccount['profile_picture_url'] ?? null;

        return [
            'success' => true,
            'account_id' => $igUserId,
            'account_name' => $igName,
            'account_username' => '@' . ltrim($igUsername, '@'),
            'avatar_url' => $avatarUrl,
            'access_token' => $pageAccessToken ?: $userToken,
            'refresh_token' => null,
            'expires_in' => null,
            'metadata' => [
                'instagram_username' => $igUsername,
                'is_business_account' => true
            ],
            'error' => null
        ];
    }

    /**
     * Validate media files for Instagram requirements
     */
    public function validateMedia(array $mediaFiles): array {
        $errors = [];
        $warnings = [];

        if (empty($mediaFiles)) {
            $errors[] = "إنستغرام لا يدعم المنشورات النصية فقط؛ يجب إرفاق صورة أو فيديو واحد على الأقل.";
            return ['valid' => false, 'errors' => $errors, 'warnings' => $warnings];
        }

        if (count($mediaFiles) > 10) {
            $errors[] = "الحد الأقصى لعدد وسائط الألبوم في إنستغرام هو 10 صور أو فيديوهات.";
        }

        foreach ($mediaFiles as $media) {
            $type = $media['file_type'] ?? 'image';
            $sizeMb = ($media['file_size'] ?? 0) / (1024 * 1024);

            if ($type === 'video') {
                if ($sizeMb > 100) {
                    $errors[] = "حجم الفيديو ({$media['file_name']}) يتجاوز 100 ميجابايت الحد الأقصى لإنستغرام.";
                }
            } else {
                if ($sizeMb > 8) {
                    $errors[] = "حجم الصورة ({$media['file_name']}) يتجاوز 8 ميجابايت الحد الأقصى لإنستغرام.";
                }
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings
        ];
    }

    /**
     * Publish post using official 2-step Instagram Container API
     */
    public function publish(array $account, array $post, array $mediaFiles = [], array $options = []): array {
        if (empty($mediaFiles)) {
            return [
                'success' => false,
                'external_post_id' => null,
                'external_post_url' => null,
                'error' => 'إنستغرام يتطلب إرفاق صورة أو فيديو واحد على الأقل للنشر.'
            ];
        }

        $igUserId = $account['account_id'];
        $accessToken = SocialSecurity::decrypt($account['access_token']) ?: $account['access_token'];

        // Determine caption: platform override or default
        $caption = $post['caption'] ?? '';
        if (!empty($post['platform_captions'])) {
            $caps = is_array($post['platform_captions']) ? $post['platform_captions'] : json_decode($post['platform_captions'], true);
            if (!empty($caps['instagram'])) {
                $caption = $caps['instagram'];
            }
        }

        // Case 1: Video / Reel (Single video)
        $videoFile = null;
        foreach ($mediaFiles as $mf) {
            if (($mf['file_type'] ?? '') === 'video') {
                $videoFile = $mf;
                break;
            }
        }

        if ($videoFile && count($mediaFiles) === 1) {
            $videoPublicUrl = $this->resolvePublicMediaUrl($videoFile['file_path']);
            // Create Video Container
            $containerEndpoint = "https://graph.facebook.com/{$this->apiVersion}/{$igUserId}/media";
            $res = $this->sendHttpRequest($containerEndpoint, 'POST', [
                'media_type' => 'REELS',
                'video_url' => $videoPublicUrl,
                'caption' => $caption,
                'access_token' => $accessToken
            ]);

            if (!$res['success'] || empty($res['data']['id'])) {
                return [
                    'success' => false,
                    'external_post_id' => null,
                    'external_post_url' => null,
                    'error' => 'فشل إنشاء حاوية الفيديو على إنستغرام: ' . ($res['data']['error']['message'] ?? $res['error']),
                    'raw_response' => $res['data']
                ];
            }

            $containerId = $res['data']['id'];

            // Poll container status until ready (max 60 seconds)
            $isReady = $this->pollContainerStatus($containerId, $accessToken);
            if (!$isReady['success']) {
                return [
                    'success' => false,
                    'external_post_id' => null,
                    'external_post_url' => null,
                    'error' => 'تجاوزت معالجة الفيديو في إنستغرام الوقت المسموح: ' . $isReady['error'],
                    'raw_response' => $isReady['data']
                ];
            }

            // Publish Container
            return $this->publishContainer($igUserId, $containerId, $accessToken);
        }

        // Case 2: Single Photo
        $imageFiles = array_values(array_filter($mediaFiles, fn($m) => ($m['file_type'] ?? 'image') === 'image'));
        if (count($imageFiles) === 1 && count($mediaFiles) === 1) {
            $photoPublicUrl = $this->resolvePublicMediaUrl($imageFiles[0]['file_path']);
            $containerEndpoint = "https://graph.facebook.com/{$this->apiVersion}/{$igUserId}/media";
            $res = $this->sendHttpRequest($containerEndpoint, 'POST', [
                'image_url' => $photoPublicUrl,
                'caption' => $caption,
                'access_token' => $accessToken
            ]);

            if (!$res['success'] || empty($res['data']['id'])) {
                return [
                    'success' => false,
                    'external_post_id' => null,
                    'external_post_url' => null,
                    'error' => 'فشل رفع الصورة إلى إنستغرام: ' . ($res['data']['error']['message'] ?? $res['error']),
                    'raw_response' => $res['data']
                ];
            }

            $containerId = $res['data']['id'];
            return $this->publishContainer($igUserId, $containerId, $accessToken);
        }

        // Case 3: Carousel (Multi-photo / Multi-video up to 10)
        if (count($mediaFiles) > 1) {
            $childContainerIds = [];
            foreach ($mediaFiles as $media) {
                $isVid = ($media['file_type'] ?? '') === 'video';
                $mediaPublicUrl = $this->resolvePublicMediaUrl($media['file_path']);

                $childData = [
                    'is_carousel_item' => 'true',
                    'access_token' => $accessToken
                ];

                if ($isVid) {
                    $childData['media_type'] = 'VIDEO';
                    $childData['video_url'] = $mediaPublicUrl;
                } else {
                    $childData['image_url'] = $mediaPublicUrl;
                }

                $childRes = $this->sendHttpRequest("https://graph.facebook.com/{$this->apiVersion}/{$igUserId}/media", 'POST', $childData);
                if ($childRes['success'] && !empty($childRes['data']['id'])) {
                    $childContainerIds[] = (string)$childRes['data']['id'];
                }
            }

            if (empty($childContainerIds)) {
                return [
                    'success' => false,
                    'external_post_id' => null,
                    'external_post_url' => null,
                    'error' => 'فشل تجهيز عناصر ألبوم إنستغرام.'
                ];
            }

            // Create parent carousel container
            $carouselRes = $this->sendHttpRequest("https://graph.facebook.com/{$this->apiVersion}/{$igUserId}/media", 'POST', [
                'media_type' => 'CAROUSEL',
                'children' => implode(',', $childContainerIds),
                'caption' => $caption,
                'access_token' => $accessToken
            ]);

            if (!$carouselRes['success'] || empty($carouselRes['data']['id'])) {
                return [
                    'success' => false,
                    'external_post_id' => null,
                    'external_post_url' => null,
                    'error' => 'فشل إنشاء حاوية الألبوم على إنستغرام: ' . ($carouselRes['data']['error']['message'] ?? $carouselRes['error']),
                    'raw_response' => $carouselRes['data']
                ];
            }

            $parentContainerId = $carouselRes['data']['id'];
            return $this->publishContainer($igUserId, $parentContainerId, $accessToken);
        }

        return [
            'success' => false,
            'external_post_id' => null,
            'external_post_url' => null,
            'error' => 'صيغة الوسائط غير مدعومة في إنستغرام.'
        ];
    }

    public function refreshTokenIfNeeded(array $account): array {
        return ['success' => true, 'refreshed' => false, 'message' => 'Token managed via Facebook Page permanent access.'];
    }

    private function publishContainer(string $igUserId, string $containerId, string $accessToken): array {
        $publishEndpoint = "https://graph.facebook.com/{$this->apiVersion}/{$igUserId}/media_publish";
        $res = $this->sendHttpRequest($publishEndpoint, 'POST', [
            'creation_id' => $containerId,
            'access_token' => $accessToken
        ]);

        if ($res['success'] && !empty($res['data']['id'])) {
            $mediaId = (string)$res['data']['id'];
            
            // Try to get permalink
            $infoRes = $this->sendHttpRequest("https://graph.facebook.com/{$this->apiVersion}/{$mediaId}?fields=permalink&access_token=" . urlencode($accessToken));
            $permalink = $infoRes['data']['permalink'] ?? "https://www.instagram.com/p/{$mediaId}/";

            return [
                'success' => true,
                'external_post_id' => $mediaId,
                'external_post_url' => $permalink,
                'error' => null,
                'raw_response' => $res['data']
            ];
        }

        return [
            'success' => false,
            'external_post_id' => null,
            'external_post_url' => null,
            'error' => 'فشل إطلاق النشر على إنستغرام: ' . ($res['data']['error']['message'] ?? $res['error']),
            'raw_response' => $res['data']
        ];
    }

    private function pollContainerStatus(string $containerId, string $accessToken): array {
        $statusEndpoint = "https://graph.facebook.com/{$this->apiVersion}/{$containerId}?fields=status_code&access_token=" . urlencode($accessToken);
        
        for ($i = 0; $i < 6; $i++) {
            sleep(3);
            $res = $this->sendHttpRequest($statusEndpoint);
            if ($res['success']) {
                $status = $res['data']['status_code'] ?? '';
                if ($status === 'FINISHED') {
                    return ['success' => true, 'data' => $res['data']];
                }
                if ($status === 'ERROR' || $status === 'EXPIRED') {
                    return ['success' => false, 'error' => "حالة الحاوية: {$status}", 'data' => $res['data']];
                }
            }
        }

        // Return assuming ready or let publishContainer handle final response
        return ['success' => true, 'data' => []];
    }

    private function resolvePublicMediaUrl(string $relativePath): string {
        if (filter_var($relativePath, FILTER_VALIDATE_URL)) {
            return $relativePath;
        }

        $base = SocialSecurity::getAppBaseUrl(getDBConnection());
        return rtrim($base, '/') . '/' . ltrim($relativePath, '/');
    }

    private function sendHttpRequest(string $url, string $method = 'GET', array $params = []): array {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        @curl_close($ch);

        if ($curlError) {
            return ['success' => false, 'http_code' => $httpCode, 'error' => $curlError, 'data' => null];
        }

        $data = json_decode($response, true) ?? [];
        $isSuccess = ($httpCode >= 200 && $httpCode < 300) && !isset($data['error']);

        return [
            'success' => $isSuccess,
            'http_code' => $httpCode,
            'error' => $data['error']['message'] ?? ($isSuccess ? null : "HTTP {$httpCode}"),
            'data' => $data
        ];
    }
}
