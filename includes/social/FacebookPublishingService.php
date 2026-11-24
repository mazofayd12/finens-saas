<?php
/**
 * FacebookPublishingService: Meta Graph API v20.0 integration for Facebook Pages
 */

require_once __DIR__ . '/PublishingServiceInterface.php';
require_once __DIR__ . '/SocialSecurity.php';

class FacebookPublishingService implements PublishingServiceInterface {
    private string $appId;
    private string $appSecret;
    private string $apiVersion;

    public function __construct(string $appId, string $appSecret, string $apiVersion = 'v20.0') {
        $this->appId = trim($appId);
        $this->appSecret = trim($appSecret);
        $this->apiVersion = trim($apiVersion) ?: 'v20.0';
    }

    public function getPlatformName(): string {
        return 'facebook';
    }

    /**
     * Official Facebook OAuth dialog URL
     */
    public function getAuthorizationUrl(string $redirectUri, string $state, array $extra = []): string {
        $scopes = [
            'pages_show_list',
            'pages_read_engagement',
            'pages_manage_posts',
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
     * Exchange OAuth code for User token, upgrade to Long-lived token, and fetch Facebook Pages
     */
    public function handleOAuthCallback(string $code, string $redirectUri, array $extra = []): array {
        if (empty($this->appId) || empty($this->appSecret)) {
            return ['success' => false, 'error' => 'بيانات تطبيق فيسبوك (Meta App ID / Secret) غير مهيأة في الإعدادات.'];
        }

        // 1. Exchange code for short-lived User Access Token
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
                'error' => 'فشل استبدال كود التفويض من فيسبوك: ' . ($res['data']['error']['message'] ?? $res['error'])
            ];
        }

        $shortLivedToken = $res['data']['access_token'];

        // 2. Exchange for 60-day Long-lived User Access Token
        $longLivedUrl = "https://graph.facebook.com/{$this->apiVersion}/oauth/access_token?" . http_build_query([
            'grant_type' => 'fb_exchange_token',
            'client_id' => $this->appId,
            'client_secret' => $this->appSecret,
            'fb_exchange_token' => $shortLivedToken
        ]);

        $longRes = $this->sendHttpRequest($longLivedUrl);
        $userToken = ($longRes['success'] && !empty($longRes['data']['access_token']))
            ? $longRes['data']['access_token']
            : $shortLivedToken;

        // 3. Fetch user's managed Facebook Pages (/me/accounts)
        $pagesUrl = "https://graph.facebook.com/{$this->apiVersion}/me/accounts?fields=id,name,category,access_token,picture{url}&access_token=" . urlencode($userToken);
        $pagesRes = $this->sendHttpRequest($pagesUrl);

        if (!$pagesRes['success'] || empty($pagesRes['data']['data'])) {
            return [
                'success' => false,
                'error' => 'لم يتم العثور على أي صفحات فيسبوك (Facebook Pages) يديرها هذا الحساب. تأكد من أن حسابك يمتلك صلاحية إدارة صفحة رسمية.'
            ];
        }

        $pages = $pagesRes['data']['data'];
        
        // Select page: if extra target_page_id provided use it, otherwise select the first page
        $selectedPage = $pages[0];
        if (!empty($extra['target_page_id'])) {
            foreach ($pages as $p) {
                if ($p['id'] == $extra['target_page_id']) {
                    $selectedPage = $p;
                    break;
                }
            }
        }

        $pageAccessToken = $selectedPage['access_token'];
        $pageId = (string)$selectedPage['id'];
        $pageName = (string)$selectedPage['name'];
        $avatarUrl = $selectedPage['picture']['data']['url'] ?? null;

        return [
            'success' => true,
            'account_id' => $pageId,
            'account_name' => $pageName,
            'account_username' => $pageId,
            'avatar_url' => $avatarUrl,
            'access_token' => $pageAccessToken, // Page tokens never expire unless password changed/revoked
            'refresh_token' => null,
            'expires_in' => null,
            'metadata' => [
                'category' => $selectedPage['category'] ?? '',
                'available_pages' => array_map(function($p) {
                    return ['id' => $p['id'], 'name' => $p['name'], 'category' => $p['category'] ?? ''];
                }, $pages),
                'connected_via_user_token' => true
            ],
            'error' => null
        ];
    }

    /**
     * Validate media files for Facebook Pages
     */
    public function validateMedia(array $mediaFiles): array {
        $errors = [];
        $warnings = [];

        $imageCount = 0;
        $videoCount = 0;

        foreach ($mediaFiles as $media) {
            $type = $media['file_type'] ?? 'image';
            $sizeMb = ($media['file_size'] ?? 0) / (1024 * 1024);

            if ($type === 'video') {
                $videoCount++;
                if ($sizeMb > 500) {
                    $errors[] = "حجم الفيديو ({$media['file_name']}) يتجاوز 500 ميجابايت الحد الأقصى لفيسبوك.";
                }
            } else {
                $imageCount++;
                if ($sizeMb > 10) {
                    $errors[] = "حجم الصورة ({$media['file_name']}) يتجاوز 10 ميجابايت الحد الأقصى لفيسبوك.";
                }
            }
        }

        if ($videoCount > 1) {
            $errors[] = "فيسبوك لا يدعم نشر أكثر من فيديو واحد في نفس المنشور.";
        }

        if ($videoCount > 0 && $imageCount > 0) {
            $warnings[] = "الجمع بين الصور والفيديو في منشور واحد قد يعرض الفيديو فقط أو يتم تقسيمه وفق سياسة فيسبوك.";
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings
        ];
    }

    /**
     * Publish post to Facebook Page
     */
    public function publish(array $account, array $post, array $mediaFiles = [], array $options = []): array {
        $pageId = $account['account_id'];
        $accessToken = SocialSecurity::decrypt($account['access_token']) ?: $account['access_token'];

        // Determine caption: platform-specific caption override or main caption
        $caption = $post['caption'] ?? '';
        if (!empty($post['platform_captions'])) {
            $caps = is_array($post['platform_captions']) ? $post['platform_captions'] : json_decode($post['platform_captions'], true);
            if (!empty($caps['facebook'])) {
                $caption = $caps['facebook'];
            }
        }

        // Case 1: Video Post
        $videoFile = null;
        foreach ($mediaFiles as $mf) {
            if (($mf['file_type'] ?? '') === 'video') {
                $videoFile = $mf;
                break;
            }
        }

        if ($videoFile) {
            $videoPublicUrl = $this->resolvePublicMediaUrl($videoFile['file_path']);
            $endpoint = "https://graph.facebook.com/{$this->apiVersion}/{$pageId}/videos";
            $postData = [
                'file_url' => $videoPublicUrl,
                'description' => $caption,
                'access_token' => $accessToken
            ];

            $res = $this->sendHttpRequest($endpoint, 'POST', $postData);
            if ($res['success'] && !empty($res['data']['id'])) {
                $postId = (string)$res['data']['id'];
                return [
                    'success' => true,
                    'external_post_id' => $postId,
                    'external_post_url' => "https://www.facebook.com/{$postId}",
                    'error' => null,
                    'raw_response' => $res['data']
                ];
            }

            return [
                'success' => false,
                'external_post_id' => null,
                'external_post_url' => null,
                'error' => 'خطأ أثناء رفع الفيديو على فيسبوك: ' . ($res['data']['error']['message'] ?? $res['error']),
                'raw_response' => $res['data']
            ];
        }

        // Case 2: Single Photo
        $imageFiles = array_values(array_filter($mediaFiles, fn($m) => ($m['file_type'] ?? 'image') === 'image'));

        if (count($imageFiles) === 1) {
            $photoPublicUrl = $this->resolvePublicMediaUrl($imageFiles[0]['file_path']);
            $endpoint = "https://graph.facebook.com/{$this->apiVersion}/{$pageId}/photos";
            $postData = [
                'url' => $photoPublicUrl,
                'caption' => $caption,
                'access_token' => $accessToken
            ];

            $res = $this->sendHttpRequest($endpoint, 'POST', $postData);
            if ($res['success'] && !empty($res['data']['id'])) {
                $photoId = (string)$res['data']['id'];
                $postId = $res['data']['post_id'] ?? $photoId;
                return [
                    'success' => true,
                    'external_post_id' => (string)$postId,
                    'external_post_url' => "https://www.facebook.com/{$postId}",
                    'error' => null,
                    'raw_response' => $res['data']
                ];
            }

            return [
                'success' => false,
                'external_post_id' => null,
                'external_post_url' => null,
                'error' => 'خطأ أثناء نشر الصورة على فيسبوك: ' . ($res['data']['error']['message'] ?? $res['error']),
                'raw_response' => $res['data']
            ];
        }

        // Case 3: Multiple Photos (Carousel / Album feed post)
        if (count($imageFiles) > 1) {
            $attachedMedia = [];
            foreach ($imageFiles as $img) {
                $photoPublicUrl = $this->resolvePublicMediaUrl($img['file_path']);
                $uploadRes = $this->sendHttpRequest("https://graph.facebook.com/{$this->apiVersion}/{$pageId}/photos", 'POST', [
                    'url' => $photoPublicUrl,
                    'published' => 'false',
                    'access_token' => $accessToken
                ]);

                if ($uploadRes['success'] && !empty($uploadRes['data']['id'])) {
                    $attachedMedia[] = ['media_fbid' => (string)$uploadRes['data']['id']];
                }
            }

            if (!empty($attachedMedia)) {
                $feedRes = $this->sendHttpRequest("https://graph.facebook.com/{$this->apiVersion}/{$pageId}/feed", 'POST', [
                    'message' => $caption,
                    'attached_media' => json_encode($attachedMedia),
                    'access_token' => $accessToken
                ]);

                if ($feedRes['success'] && !empty($feedRes['data']['id'])) {
                    $postId = (string)$feedRes['data']['id'];
                    return [
                        'success' => true,
                        'external_post_id' => $postId,
                        'external_post_url' => "https://www.facebook.com/{$postId}",
                        'error' => null,
                        'raw_response' => $feedRes['data']
                    ];
                }

                return [
                    'success' => false,
                    'external_post_id' => null,
                    'external_post_url' => null,
                    'error' => 'فشل نشر الألبوم متعدد الصور على فيسبوك: ' . ($feedRes['data']['error']['message'] ?? $feedRes['error']),
                    'raw_response' => $feedRes['data']
                ];
            }
        }

        // Case 4: Text-only post
        $feedEndpoint = "https://graph.facebook.com/{$this->apiVersion}/{$pageId}/feed";
        $res = $this->sendHttpRequest($feedEndpoint, 'POST', [
            'message' => $caption,
            'access_token' => $accessToken
        ]);

        if ($res['success'] && !empty($res['data']['id'])) {
            $postId = (string)$res['data']['id'];
            return [
                'success' => true,
                'external_post_id' => $postId,
                'external_post_url' => "https://www.facebook.com/{$postId}",
                'error' => null,
                'raw_response' => $res['data']
            ];
        }

        return [
            'success' => false,
            'external_post_id' => null,
            'external_post_url' => null,
            'error' => 'خطأ أثناء نشر المنشور على فيسبوك: ' . ($res['data']['error']['message'] ?? $res['error']),
            'raw_response' => $res['data']
        ];
    }

    public function refreshTokenIfNeeded(array $account): array {
        // Facebook Page tokens generated from long-lived user tokens do not expire
        return ['success' => true, 'refreshed' => false, 'message' => 'Page token is permanent.'];
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
