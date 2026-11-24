<?php
/**
 * SocialMediaManager: Multi-tenant business logic controller for social media accounts,
 * posts, media files, calendar, and publishing execution.
 */

require_once __DIR__ . '/SocialSecurity.php';
require_once __DIR__ . '/FacebookPublishingService.php';
require_once __DIR__ . '/InstagramPublishingService.php';
require_once __DIR__ . '/TikTokPublishingService.php';
require_once __DIR__ . '/SnapchatPublishingService.php';

class SocialMediaManager {
    private PDO $db;

    public function __construct(?PDO $db = null) {
        $this->db = $db ?: getDBConnection();
    }

    /**
     * Factory for platform-specific publishing services
     */
    public function getService(string $platform): PublishingServiceInterface {
        $platform = strtolower(trim($platform));
        $settings = $this->getPlatformSettings();

        switch ($platform) {
            case 'facebook':
                return new FacebookPublishingService(
                    $settings['meta_app_id'] ?? '',
                    $settings['meta_app_secret'] ?? '',
                    $settings['meta_api_version'] ?? 'v20.0'
                );
            case 'instagram':
                return new InstagramPublishingService(
                    $settings['meta_app_id'] ?? '',
                    $settings['meta_app_secret'] ?? '',
                    $settings['meta_api_version'] ?? 'v20.0'
                );
            case 'tiktok':
                return new TikTokPublishingService(
                    $settings['tiktok_client_key'] ?? '',
                    $settings['tiktok_client_secret'] ?? ''
                );
            case 'snapchat':
                return new SnapchatPublishingService(
                    $settings['snapchat_client_id'] ?? '',
                    $settings['snapchat_client_secret'] ?? ''
                );
            default:
                throw new InvalidArgumentException("منصة غير مدعومة: {$platform}");
        }
    }

    // ==========================================
    // 1. CONNECTED ACCOUNTS MANAGEMENT
    // ==========================================

    /**
     * Get all connected accounts for a specific tenant (Strict Isolation)
     */
    public function getConnectedAccounts(int $tenantId, ?string $platform = null, bool $onlyActive = false): array {
        $sql = "SELECT id, tenant_id, platform, account_id, account_name, account_username, avatar_url,
                       token_expires_at, status, error_details, metadata, created_at, updated_at
                FROM social_accounts
                WHERE tenant_id = :tid";
        $params = [':tid' => $tenantId];

        if ($platform) {
            $sql .= " AND platform = :plat";
            $params[':plat'] = strtolower($platform);
        }

        if ($onlyActive) {
            $sql .= " AND status = 'active'";
        }

        $sql .= " ORDER BY platform ASC, created_at DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($accounts as &$acc) {
            $acc['metadata'] = !empty($acc['metadata']) ? json_decode($acc['metadata'], true) : [];
        }
        unset($acc);

        return $accounts;
    }

    /**
     * Get single account by ID (Strict Tenant Isolation)
     */
    public function getAccountById(int $tenantId, int $accountId, bool $includeSecret = false): ?array {
        $sql = "SELECT * FROM social_accounts WHERE tenant_id = :tid AND id = :id LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':tid' => $tenantId, ':id' => $accountId]);
        $acc = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$acc) {
            return null;
        }

        $acc['metadata'] = !empty($acc['metadata']) ? json_decode($acc['metadata'], true) : [];

        if (!$includeSecret) {
            unset($acc['access_token'], $acc['refresh_token']);
        }

        return $acc;
    }

    /**
     * Save or update connected account (Encrypts tokens at rest)
     */
    public function saveAccount(int $tenantId, array $data): int {
        $platform = strtolower(trim($data['platform']));
        $accountId = trim($data['account_id']);
        $accountName = trim($data['account_name']);
        $username = trim($data['account_username'] ?? '');
        $avatarUrl = $data['avatar_url'] ?? null;
        $accessToken = trim($data['access_token']);
        $refreshToken = !empty($data['refresh_token']) ? trim($data['refresh_token']) : null;
        $expiresIn = !empty($data['expires_in']) ? (int)$data['expires_in'] : null;
        $tokenExpiresAt = $expiresIn ? date('Y-m-d H:i:s', time() + $expiresIn) : null;
        $metadata = !empty($data['metadata']) ? json_encode($data['metadata'], JSON_UNESCAPED_UNICODE) : null;

        // Encrypt tokens at rest
        $encAccessToken = SocialSecurity::encrypt($accessToken);
        $encRefreshToken = $refreshToken ? SocialSecurity::encrypt($refreshToken) : null;

        // Check if account already exists for this tenant & platform
        $chk = $this->db->prepare("SELECT id FROM social_accounts WHERE tenant_id = :tid AND platform = :plat AND account_id = :aid LIMIT 1");
        $chk->execute([':tid' => $tenantId, ':plat' => $platform, ':aid' => $accountId]);
        $existing = $chk->fetch();

        $now = date('Y-m-d H:i:s');

        if ($existing) {
            $upd = $this->db->prepare("
                UPDATE social_accounts
                SET account_name = :aname, account_username = :uname, avatar_url = :avatar,
                    access_token = :atoken, refresh_token = :rtoken, token_expires_at = :exp,
                    status = 'active', error_details = NULL, metadata = :meta, updated_at = :now
                WHERE id = :id AND tenant_id = :tid
            ");
            $upd->execute([
                ':aname' => $accountName,
                ':uname' => $username,
                ':avatar' => $avatarUrl,
                ':atoken' => $encAccessToken,
                ':rtoken' => $encRefreshToken,
                ':exp' => $tokenExpiresAt,
                ':meta' => $metadata,
                ':now' => $now,
                ':id' => $existing['id'],
                ':tid' => $tenantId
            ]);
            $this->logActivity($tenantId, null, (int)$existing['id'], $platform, 'account_reconnected', 200, ['account_id' => $accountId]);
            return (int)$existing['id'];
        }

        $ins = $this->db->prepare("
            INSERT INTO social_accounts (tenant_id, platform, account_id, account_name, account_username, avatar_url,
                                         access_token, refresh_token, token_expires_at, status, metadata, created_at, updated_at)
            VALUES (:tid, :plat, :aid, :aname, :uname, :avatar, :atoken, :rtoken, :exp, 'active', :meta, :now, :now)
        ");
        $ins->execute([
            ':tid' => $tenantId,
            ':plat' => $platform,
            ':aid' => $accountId,
            ':aname' => $accountName,
            ':uname' => $username,
            ':avatar' => $avatarUrl,
            ':atoken' => $encAccessToken,
            ':rtoken' => $encRefreshToken,
            ':exp' => $tokenExpiresAt,
            ':meta' => $metadata,
            ':now' => $now
        ]);
        $newId = (int)$this->db->lastInsertId();

        $this->logActivity($tenantId, null, $newId, $platform, 'account_connected', 200, ['account_id' => $accountId, 'account_name' => $accountName]);
        return $newId;
    }

    /**
     * Disconnect account
     */
    public function disconnectAccount(int $tenantId, int $accountId): bool {
        $acc = $this->getAccountById($tenantId, $accountId);
        if (!$acc) {
            return false;
        }

        $del = $this->db->prepare("DELETE FROM social_accounts WHERE id = :id AND tenant_id = :tid");
        $del->execute([':id' => $accountId, ':tid' => $tenantId]);

        $this->logActivity($tenantId, null, $accountId, $acc['platform'], 'account_disconnected', 200, ['account_name' => $acc['account_name']]);
        return true;
    }

    // ==========================================
    // 2. POST MANAGEMENT (Drafts, Schedules, Previews)
    // ==========================================

    /**
     * Create a new social post
     */
    public function createPost(int $tenantId, int $userId, array $data, array $accountIds, array $mediaFiles = []): int {
        $caption = trim($data['caption'] ?? '');
        $status = in_array($data['status'] ?? '', ['draft', 'scheduled', 'published']) ? $data['status'] : 'draft';
        $scheduledAt = !empty($data['scheduled_at']) ? trim($data['scheduled_at']) : null;
        $platformCaptions = !empty($data['platform_captions']) ? json_encode($data['platform_captions'], JSON_UNESCAPED_UNICODE) : null;
        $timezone = $data['timezone'] ?? 'UTC';

        if ($status === 'scheduled' && empty($scheduledAt)) {
            $status = 'draft';
        }

        $now = date('Y-m-d H:i:s');

        $this->db->beginTransaction();
        try {
            // 1. Insert main post
            $ins = $this->db->prepare("
                INSERT INTO social_posts (tenant_id, user_id, caption, platform_captions, status, scheduled_at, timezone, created_at, updated_at)
                VALUES (:tid, :uid, :caption, :pcaps, :status, :sched, :tz, :now, :now)
            ");
            $ins->execute([
                ':tid' => $tenantId,
                ':uid' => $userId,
                ':caption' => $caption,
                ':pcaps' => $platformCaptions,
                ':status' => $status,
                ':sched' => $scheduledAt,
                ':tz' => $timezone,
                ':now' => $now
            ]);
            $postId = (int)$this->db->lastInsertId();

            // 2. Link target accounts/platforms
            if (!empty($accountIds)) {
                $targetStmt = $this->db->prepare("
                    INSERT INTO social_post_platforms (post_id, tenant_id, social_account_id, platform, status, created_at, updated_at)
                    VALUES (:pid, :tid, :said, :plat, 'pending', :now, :now)
                ");

                foreach ($accountIds as $said) {
                    $acc = $this->getAccountById($tenantId, (int)$said);
                    if ($acc) {
                        $targetStmt->execute([
                            ':pid' => $postId,
                            ':tid' => $tenantId,
                            ':said' => $acc['id'],
                            ':plat' => $acc['platform'],
                            ':now' => $now
                        ]);
                    }
                }
            }

            // 3. Link uploaded media
            if (!empty($mediaFiles)) {
                $linkMedia = $this->db->prepare("UPDATE social_media_files SET post_id = :pid WHERE id = :mid AND tenant_id = :tid");
                foreach ($mediaFiles as $mid) {
                    $linkMedia->execute([':pid' => $postId, ':mid' => (int)$mid, ':tid' => $tenantId]);
                }
            }

            $this->db->commit();
            return $postId;
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Update existing post
     */
    public function updatePost(int $tenantId, int $postId, array $data, array $accountIds = [], array $mediaFiles = []): bool {
        $post = $this->getPostById($tenantId, $postId);
        if (!$post) {
            return false;
        }

        $caption = trim($data['caption'] ?? $post['caption']);
        $status = in_array($data['status'] ?? '', ['draft', 'scheduled', 'published', 'cancelled']) ? $data['status'] : $post['status'];
        $scheduledAt = isset($data['scheduled_at']) ? trim($data['scheduled_at']) : $post['scheduled_at'];
        $platformCaptions = isset($data['platform_captions']) ? json_encode($data['platform_captions'], JSON_UNESCAPED_UNICODE) : $post['platform_captions_raw'];
        $now = date('Y-m-d H:i:s');

        $this->db->beginTransaction();
        try {
            $upd = $this->db->prepare("
                UPDATE social_posts
                SET caption = :caption, platform_captions = :pcaps, status = :status,
                    scheduled_at = :sched, updated_at = :now
                WHERE id = :id AND tenant_id = :tid
            ");
            $upd->execute([
                ':caption' => $caption,
                ':pcaps' => $platformCaptions,
                ':status' => $status,
                ':sched' => $scheduledAt,
                ':now' => $now,
                ':id' => $postId,
                ':tid' => $tenantId
            ]);

            // Sync platforms if provided
            if (!empty($accountIds)) {
                // Delete previous targets
                $del = $this->db->prepare("DELETE FROM social_post_platforms WHERE post_id = :pid AND tenant_id = :tid");
                $del->execute([':pid' => $postId, ':tid' => $tenantId]);

                $targetStmt = $this->db->prepare("
                    INSERT INTO social_post_platforms (post_id, tenant_id, social_account_id, platform, status, created_at, updated_at)
                    VALUES (:pid, :tid, :said, :plat, 'pending', :now, :now)
                ");

                foreach ($accountIds as $said) {
                    $acc = $this->getAccountById($tenantId, (int)$said);
                    if ($acc) {
                        $targetStmt->execute([
                            ':pid' => $postId,
                            ':tid' => $tenantId,
                            ':said' => $acc['id'],
                            ':plat' => $acc['platform'],
                            ':now' => $now
                        ]);
                    }
                }
            }

            // Sync media files
            if (!empty($mediaFiles)) {
                $linkMedia = $this->db->prepare("UPDATE social_media_files SET post_id = :pid WHERE id = :mid AND tenant_id = :tid");
                foreach ($mediaFiles as $mid) {
                    $linkMedia->execute([':pid' => $postId, ':mid' => (int)$mid, ':tid' => $tenantId]);
                }
            }

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Delete post and associated media/platforms
     */
    public function deletePost(int $tenantId, int $postId): bool {
        $post = $this->getPostById($tenantId, $postId);
        if (!$post) {
            return false;
        }

        // Delete post media files from disk
        $mediaList = $this->getPostMedia($tenantId, $postId);
        foreach ($mediaList as $m) {
            if (!empty($m['file_path']) && file_exists(APP_PATH . '/' . $m['file_path'])) {
                @unlink(APP_PATH . '/' . $m['file_path']);
            }
        }

        $del = $this->db->prepare("DELETE FROM social_posts WHERE id = :id AND tenant_id = :tid");
        $del->execute([':id' => $postId, ':tid' => $tenantId]);

        return true;
    }

    /**
     * Reschedule post
     */
    public function reschedulePost(int $tenantId, int $postId, string $newScheduledAt): bool {
        $upd = $this->db->prepare("
            UPDATE social_posts
            SET scheduled_at = :sched, status = 'scheduled', updated_at = :now
            WHERE id = :id AND tenant_id = :tid
        ");
        return $upd->execute([
            ':sched' => $newScheduledAt,
            ':now' => date('Y-m-d H:i:s'),
            ':id' => $postId,
            ':tid' => $tenantId
        ]);
    }

    /**
     * Get single post by ID with accounts and media
     */
    public function getPostById(int $tenantId, int $postId): ?array {
        $stmt = $this->db->prepare("
            SELECT p.*, u.name as author_name
            FROM social_posts p
            LEFT JOIN users u ON p.user_id = u.id
            WHERE p.id = :id AND p.tenant_id = :tid
            LIMIT 1
        ");
        $stmt->execute([':id' => $postId, ':tid' => $tenantId]);
        $post = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$post) {
            return null;
        }

        $post['platform_captions_raw'] = $post['platform_captions'];
        $post['platform_captions'] = !empty($post['platform_captions']) ? json_decode($post['platform_captions'], true) : [];

        // Targets
        $tgtStmt = $this->db->prepare("
            SELECT spp.*, sa.account_name, sa.account_username, sa.avatar_url
            FROM social_post_platforms spp
            JOIN social_accounts sa ON spp.social_account_id = sa.id
            WHERE spp.post_id = :pid AND spp.tenant_id = :tid
        ");
        $tgtStmt->execute([':pid' => $postId, ':tid' => $tenantId]);
        $post['targets'] = $tgtStmt->fetchAll(PDO::FETCH_ASSOC);

        // Media
        $post['media'] = $this->getPostMedia($tenantId, $postId);

        return $post;
    }

    /**
     * Query posts with filters and pagination
     */
    public function getPosts(int $tenantId, array $filters = [], int $limit = 50, int $offset = 0): array {
        $sql = "SELECT p.*, u.name as author_name,
                       (SELECT COUNT(*) FROM social_media_files smf WHERE smf.post_id = p.id) as media_count,
                       (SELECT file_path FROM social_media_files smf WHERE smf.post_id = p.id ORDER BY smf.id ASC LIMIT 1) as thumbnail_path,
                       (SELECT file_type FROM social_media_files smf WHERE smf.post_id = p.id ORDER BY smf.id ASC LIMIT 1) as thumbnail_type
                FROM social_posts p
                LEFT JOIN users u ON p.user_id = u.id
                WHERE p.tenant_id = :tid";
        $params = [':tid' => $tenantId];

        if (!empty($filters['status'])) {
            $sql .= " AND p.status = :status";
            $params[':status'] = $filters['status'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND p.caption LIKE :search";
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        $sql .= " ORDER BY CASE WHEN p.status = 'scheduled' THEN p.scheduled_at ELSE p.created_at END DESC";
        $sql .= " LIMIT {$limit} OFFSET {$offset}";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch targets for all retrieved posts
        foreach ($posts as &$p) {
            $tStmt = $this->db->prepare("
                SELECT spp.platform, spp.status, spp.external_post_url, sa.account_name
                FROM social_post_platforms spp
                JOIN social_accounts sa ON spp.social_account_id = sa.id
                WHERE spp.post_id = :pid AND spp.tenant_id = :tid
            ");
            $tStmt->execute([':pid' => $p['id'], ':tid' => $tenantId]);
            $p['platforms'] = $tStmt->fetchAll(PDO::FETCH_ASSOC);
        }
        unset($p);

        return $posts;
    }

    /**
     * Get posts for content calendar date range
     */
    public function getCalendarPosts(int $tenantId, string $startDate, string $endDate): array {
        $sql = "SELECT p.id, p.caption, p.status, p.scheduled_at, p.published_at, p.created_at,
                       (SELECT file_path FROM social_media_files smf WHERE smf.post_id = p.id ORDER BY smf.id ASC LIMIT 1) as thumbnail_path
                FROM social_posts p
                WHERE p.tenant_id = :tid
                  AND (
                      (p.status = 'scheduled' AND p.scheduled_at BETWEEN :s1 AND :e1)
                      OR (p.status = 'published' AND p.published_at BETWEEN :s2 AND :e2)
                      OR (p.status = 'draft' AND p.created_at BETWEEN :s3 AND :e3)
                  )
                ORDER BY p.scheduled_at ASC, p.created_at ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':tid' => $tenantId,
            ':s1' => $startDate . ' 00:00:00',
            ':e1' => $endDate . ' 23:59:59',
            ':s2' => $startDate . ' 00:00:00',
            ':e2' => $endDate . ' 23:59:59',
            ':s3' => $startDate . ' 00:00:00',
            ':e3' => $endDate . ' 23:59:59'
        ]);
        $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($posts as &$p) {
            $tStmt = $this->db->prepare("
                SELECT spp.platform, spp.status, sa.account_name
                FROM social_post_platforms spp
                JOIN social_accounts sa ON spp.social_account_id = sa.id
                WHERE spp.post_id = :pid AND spp.tenant_id = :tid
            ");
            $tStmt->execute([':pid' => $p['id'], ':tid' => $tenantId]);
            $p['platforms'] = $tStmt->fetchAll(PDO::FETCH_ASSOC);
        }
        unset($p);

        return $posts;
    }

    // ==========================================
    // 3. MEDIA MANAGEMENT
    // ==========================================

    /**
     * Upload and register a media file (Organized by Tenant)
     */
    public function uploadMediaFile(int $tenantId, array $file, ?int $postId = null): array {
        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return ['success' => false, 'error' => 'ملف الرفع غير صالح.'];
        }

        $origName = $file['name'];
        $fileSize = (int)$file['size'];
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

        $allowedImageExts = ['jpg', 'jpeg', 'png', 'webp'];
        $allowedVideoExts = ['mp4', 'mov', 'webm'];

        $isImage = in_array($ext, $allowedImageExts, true);
        $isVideo = in_array($ext, $allowedVideoExts, true);

        if (!$isImage && !$isVideo) {
            return ['success' => false, 'error' => "امتداد الملف غير مدعوم ({$ext}). يرجى رفع صور JPG/PNG/WebP أو فيديو MP4/MOV."];
        }

        // Validate max sizes (Images 15MB, Videos 500MB)
        if ($isImage && $fileSize > (15 * 1024 * 1024)) {
            return ['success' => false, 'error' => 'حجم الصورة يتجاوز الحد الأقصى المسموح به (15 ميجابايت).'];
        }
        if ($isVideo && $fileSize > (500 * 1024 * 1024)) {
            return ['success' => false, 'error' => 'حجم الفيديو يتجاوز الحد الأقصى المسموح به (500 ميجابايت).'];
        }

        // Organize by tenant: uploads/social/{tenant_id}/
        $relDir = "uploads/social/{$tenantId}";
        $targetDir = APP_PATH . '/' . $relDir;
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $safeName = 'media_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $targetPath = $targetDir . '/' . $safeName;
        $relPath = $relDir . '/' . $safeName;

        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            return ['success' => false, 'error' => 'فشل نقل الملف إلى مجلد التخزين.'];
        }

        // Extract metadata
        $mimeType = mime_content_type($targetPath) ?: ($isImage ? 'image/jpeg' : 'video/mp4');
        $dimensions = null;
        if ($isImage) {
            $imgInfo = @getimagesize($targetPath);
            if ($imgInfo) {
                $dimensions = "{$imgInfo[0]}x{$imgInfo[1]}";
            }
        }

        $fileType = $isVideo ? 'video' : 'image';

        // Insert into database
        $ins = $this->db->prepare("
            INSERT INTO social_media_files (tenant_id, post_id, file_path, file_type, file_name, file_size, mime_type, dimensions, created_at)
            VALUES (:tid, :pid, :fpath, :ftype, :fname, :fsize, :mime, :dim, :now)
        ");
        $ins->execute([
            ':tid' => $tenantId,
            ':pid' => $postId,
            ':fpath' => $relPath,
            ':ftype' => $fileType,
            ':fname' => $origName,
            ':fsize' => $fileSize,
            ':mime' => $mimeType,
            ':dim' => $dimensions,
            ':now' => date('Y-m-d H:i:s')
        ]);
        $mediaId = (int)$this->db->lastInsertId();

        return [
            'success' => true,
            'media_id' => $mediaId,
            'file_path' => $relPath,
            'public_url' => SocialSecurity::getAppBaseUrl($this->db) . '/' . $relPath,
            'file_type' => $fileType,
            'file_name' => $origName,
            'dimensions' => $dimensions
        ];
    }

    /**
     * Get media files attached to a post
     */
    public function getPostMedia(int $tenantId, int $postId): array {
        $stmt = $this->db->prepare("
            SELECT * FROM social_media_files
            WHERE tenant_id = :tid AND post_id = :pid
            ORDER BY id ASC
        ");
        $stmt->execute([':tid' => $tenantId, ':pid' => $postId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Delete media file
     */
    public function deleteMediaFile(int $tenantId, int $mediaId): bool {
        $stmt = $this->db->prepare("SELECT * FROM social_media_files WHERE id = :mid AND tenant_id = :tid LIMIT 1");
        $stmt->execute([':mid' => $mediaId, ':tid' => $tenantId]);
        $media = $stmt->fetch();

        if (!$media) {
            return false;
        }

        if (!empty($media['file_path']) && file_exists(APP_PATH . '/' . $media['file_path'])) {
            @unlink(APP_PATH . '/' . $media['file_path']);
        }

        $del = $this->db->prepare("DELETE FROM social_media_files WHERE id = :mid AND tenant_id = :tid");
        $del->execute([':mid' => $mediaId, ':tid' => $tenantId]);
        return true;
    }

    // ==========================================
    // 4. PUBLISHING ENGINE & ORCHESTRATION
    // ==========================================

    /**
     * Immediately publish a post to all its configured target platforms
     */
    public function publishPostNow(int $tenantId, int $postId): array {
        $post = $this->getPostById($tenantId, $postId);
        if (!$post) {
            return ['success' => false, 'error' => 'المنشور غير موجود.'];
        }

        if (empty($post['targets'])) {
            return ['success' => false, 'error' => 'لم يتم تحديد أي حسابات سوشيال ميديا للنشر.'];
        }

        // Set status to processing
        $this->db->prepare("UPDATE social_posts SET status = 'processing', updated_at = :now WHERE id = :id AND tenant_id = :tid")
                 ->execute([':now' => date('Y-m-d H:i:s'), ':id' => $postId, ':tid' => $tenantId]);

        $mediaFiles = $post['media'];
        $results = [];
        $successCount = 0;
        $failCount = 0;

        foreach ($post['targets'] as $target) {
            $account = $this->getAccountById($tenantId, (int)$target['social_account_id'], true);
            if (!$account || $account['status'] !== 'active') {
                $results[$target['platform']] = ['success' => false, 'error' => 'الحساب غير متصل أو منتهي الصلاحية.'];
                $failCount++;
                continue;
            }

            try {
                $service = $this->getService($target['platform']);
                $pubResult = $service->publish($account, $post, $mediaFiles);

                $now = date('Y-m-d H:i:s');
                if ($pubResult['success']) {
                    $successCount++;
                    $upd = $this->db->prepare("
                        UPDATE social_post_platforms
                        SET status = 'published', external_post_id = :ext_id, external_post_url = :ext_url,
                            published_at = :now, error_message = NULL, updated_at = :now
                        WHERE id = :id AND tenant_id = :tid
                    ");
                    $upd->execute([
                        ':ext_id' => $pubResult['external_post_id'],
                        ':ext_url' => $pubResult['external_post_url'],
                        ':now' => $now,
                        ':id' => $target['id'],
                        ':tid' => $tenantId
                    ]);

                    $this->logActivity($tenantId, $postId, $account['id'], $target['platform'], 'publish_success', 200, $pubResult['raw_response'] ?? []);
                } else {
                    $failCount++;
                    $upd = $this->db->prepare("
                        UPDATE social_post_platforms
                        SET status = 'failed', error_message = :err, last_attempt_at = :now,
                            retry_count = retry_count + 1, updated_at = :now
                        WHERE id = :id AND tenant_id = :tid
                    ");
                    $upd->execute([
                        ':err' => $pubResult['error'],
                        ':now' => $now,
                        ':id' => $target['id'],
                        ':tid' => $tenantId
                    ]);

                    $this->logActivity($tenantId, $postId, $account['id'], $target['platform'], 'publish_failure', 400, $pubResult['raw_response'] ?? [], $pubResult['error']);
                }

                $results[$target['platform']] = $pubResult;
            } catch (Exception $e) {
                $failCount++;
                $results[$target['platform']] = ['success' => false, 'error' => $e->getMessage()];
                $this->logActivity($tenantId, $postId, $account['id'], $target['platform'], 'publish_failure', 500, [], $e->getMessage());
            }
        }

        // Determine final post status
        $finalStatus = 'published';
        if ($successCount === 0 && $failCount > 0) {
            $finalStatus = 'failed';
        } elseif ($successCount > 0 && $failCount > 0) {
            $finalStatus = 'partially_published';
        }

        $now = date('Y-m-d H:i:s');
        $updPost = $this->db->prepare("
            UPDATE social_posts
            SET status = :st, published_at = CASE WHEN :st IN ('published', 'partially_published') THEN :now ELSE published_at END,
                updated_at = :now
            WHERE id = :id AND tenant_id = :tid
        ");
        $updPost->execute([
            ':st' => $finalStatus,
            ':now' => $now,
            ':id' => $postId,
            ':tid' => $tenantId
        ]);

        return [
            'success' => ($successCount > 0),
            'status' => $finalStatus,
            'success_count' => $successCount,
            'fail_count' => $failCount,
            'results' => $results
        ];
    }

    // ==========================================
    // 5. DASHBOARD STATS & RECENT ACTIVITY
    // ==========================================

    /**
     * Get overview dashboard numbers for a tenant
     */
    public function getDashboardStats(int $tenantId): array {
        $stats = [
            'total_accounts' => 0,
            'published_posts' => 0,
            'scheduled_posts' => 0,
            'failed_posts' => 0,
            'draft_posts' => 0,
            'upcoming_posts' => [],
            'recent_logs' => []
        ];

        // 1. Total connected accounts
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM social_accounts WHERE tenant_id = :tid AND status = 'active'");
        $stmt->execute([':tid' => $tenantId]);
        $stats['total_accounts'] = (int)$stmt->fetchColumn();

        // 2. Posts count by status
        $stmt = $this->db->prepare("
            SELECT status, COUNT(*) as cnt
            FROM social_posts
            WHERE tenant_id = :tid
            GROUP BY status
        ");
        $stmt->execute([':tid' => $tenantId]);
        $counts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $stats['published_posts'] = (int)($counts['published'] ?? 0) + (int)($counts['partially_published'] ?? 0);
        $stats['scheduled_posts'] = (int)($counts['scheduled'] ?? 0);
        $stats['failed_posts'] = (int)($counts['failed'] ?? 0);
        $stats['draft_posts'] = (int)($counts['draft'] ?? 0);

        // 3. Upcoming scheduled posts (next 5)
        $upStmt = $this->db->prepare("
            SELECT p.id, p.caption, p.scheduled_at,
                   (SELECT file_path FROM social_media_files WHERE post_id = p.id LIMIT 1) as thumbnail
            FROM social_posts p
            WHERE p.tenant_id = :tid AND p.status = 'scheduled'
            ORDER BY p.scheduled_at ASC
            LIMIT 5
        ");
        $upStmt->execute([':tid' => $tenantId]);
        $stats['upcoming_posts'] = $upStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($stats['upcoming_posts'] as &$up) {
            $tStmt = $this->db->prepare("
                SELECT spp.platform, sa.account_name
                FROM social_post_platforms spp
                JOIN social_accounts sa ON spp.social_account_id = sa.id
                WHERE spp.post_id = :pid AND spp.tenant_id = :tid
            ");
            $tStmt->execute([':pid' => $up['id'], ':tid' => $tenantId]);
            $up['platforms'] = $tStmt->fetchAll(PDO::FETCH_ASSOC);
        }
        unset($up);

        // 4. Recent publishing logs
        $stats['recent_logs'] = $this->getPublishingLogs($tenantId, 10);

        return $stats;
    }

    /**
     * Get audit/publishing logs
     */
    public function getPublishingLogs(int $tenantId, int $limit = 50): array {
        $stmt = $this->db->prepare("
            SELECT l.*, sa.account_name
            FROM social_publish_logs l
            LEFT JOIN social_accounts sa ON l.social_account_id = sa.id
            WHERE l.tenant_id = :tid
            ORDER BY l.created_at DESC
            LIMIT {$limit}
        ");
        $stmt->execute([':tid' => $tenantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ==========================================
    // 6. GLOBAL & TENANT SETTINGS
    // ==========================================

    /**
     * Get platform API credentials
     */
    public function getPlatformSettings(?int $tenantId = null): array {
        $keys = [
            'meta_app_id' => getenv('META_APP_ID') ?: '',
            'meta_app_secret' => getenv('META_APP_SECRET') ?: '',
            'meta_api_version' => getenv('META_API_VERSION') ?: 'v20.0',
            'tiktok_client_key' => getenv('TIKTOK_CLIENT_KEY') ?: '',
            'tiktok_client_secret' => getenv('TIKTOK_CLIENT_SECRET') ?: '',
            'snapchat_client_id' => getenv('SNAPCHAT_CLIENT_ID') ?: '',
            'snapchat_client_secret' => getenv('SNAPCHAT_CLIENT_SECRET') ?: '',
            'social_app_public_url' => getenv('SOCIAL_APP_PUBLIC_URL') ?: '',
            'social_cron_last_run' => null
        ];

        // Retrieve settings from database
        try {
            $stmt = $this->db->query("SELECT setting_key, setting_value, is_secret FROM social_settings WHERE tenant_id IS NULL");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $val = $row['setting_value'];
                if ((int)$row['is_secret'] === 1 && !empty($val)) {
                    $val = SocialSecurity::decrypt($val) ?: $val;
                }
                $keys[$row['setting_key']] = $val;
            }
        } catch (Exception $e) {}

        return $keys;
    }

    /**
     * Save platform credentials
     */
    public function savePlatformSettings(array $settings, ?int $tenantId = null): bool {
        $secretKeys = ['meta_app_secret', 'tiktok_client_secret', 'snapchat_client_secret'];

        foreach ($settings as $key => $val) {
            $isSecret = in_array($key, $secretKeys, true) ? 1 : 0;
            $storedVal = trim((string)$val);
            if ($isSecret && !empty($storedVal)) {
                $storedVal = SocialSecurity::encrypt($storedVal);
            }

            // Check if exists
            $chk = $this->db->prepare("SELECT id FROM social_settings WHERE tenant_id IS NULL AND setting_key = :k LIMIT 1");
            $chk->execute([':k' => $key]);
            $exists = $chk->fetch();

            $now = date('Y-m-d H:i:s');
            if ($exists) {
                $upd = $this->db->prepare("UPDATE social_settings SET setting_value = :v, is_secret = :sec, updated_at = :now WHERE id = :id");
                $upd->execute([':v' => $storedVal, ':sec' => $isSecret, ':now' => $now, ':id' => $exists['id']]);
            } else {
                $ins = $this->db->prepare("INSERT INTO social_settings (tenant_id, setting_key, setting_value, is_secret, created_at, updated_at) VALUES (NULL, :k, :v, :sec, :now, :now)");
                $ins->execute([':k' => $key, ':v' => $storedVal, ':sec' => $isSecret, ':now' => $now]);
            }
        }

        return true;
    }

    private function logActivity(int $tenantId, ?int $postId, ?int $accountId, string $platform, string $action, ?int $code = 200, mixed $payload = [], ?string $error = null): void {
        try {
            $sanitized = SocialSecurity::sanitizePayload($payload);
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

            $stmt = $this->db->prepare("
                INSERT INTO social_publish_logs (tenant_id, post_id, social_account_id, platform, action, status_code, response_payload, error_message, ip_address, created_at)
                VALUES (:tid, :pid, :aid, :plat, :act, :code, :payload, :err, :ip, :now)
            ");
            $stmt->execute([
                ':tid' => $tenantId,
                ':pid' => $postId,
                ':aid' => $accountId,
                ':plat' => $platform,
                ':act' => $action,
                ':code' => $code,
                ':payload' => $sanitized,
                ':err' => $error,
                ':ip' => $ip,
                ':now' => date('Y-m-d H:i:s')
            ]);
        } catch (Exception $e) {
            error_log("Failed to log social activity: " . $e->getMessage());
        }
    }
}
