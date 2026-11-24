<?php
/**
 * SocialScheduler: Shared Hosting Database-Based Cron Worker
 * Handles scheduled social media posts processing in short-running bounded batches
 * with concurrency lock and automatic retry with backoff.
 */

require_once __DIR__ . '/SocialMediaManager.php';

class SocialScheduler {
    private PDO $db;
    private SocialMediaManager $manager;
    private string $lockFile;
    private int $batchLimit;
    private int $maxRetries;

    public function __construct(?PDO $db = null, int $batchLimit = 10, int $maxRetries = 3) {
        $this->db = $db ?: getDBConnection();
        $this->manager = new SocialMediaManager($this->db);
        $this->batchLimit = $batchLimit;
        $this->maxRetries = $maxRetries;

        $storageDir = APP_PATH . '/database';
        if (!is_dir($storageDir)) {
            @mkdir($storageDir, 0755, true);
        }
        $this->lockFile = $storageDir . '/social_cron.lock';
    }

    /**
     * Main runner called by Cron Job or CLI
     */
    public function run(): array {
        $startTime = microtime(true);
        $now = date('Y-m-d H:i:s');

        // 1. Acquire Concurrency Lock (Shared Hosting safe)
        $lockFp = @fopen($this->lockFile, 'c+');
        if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
            // Check if lock file is stale (older than 5 minutes)
            if (file_exists($this->lockFile) && (time() - filemtime($this->lockFile) > 300)) {
                @unlink($this->lockFile);
            }
            return [
                'status' => 'locked',
                'message' => 'هناك عملية مجدولة أخرى قيد التنفيذ حالياً، تم تخطي هذه الدورة لمنع التكرار.',
                'processed' => 0,
                'duration' => 0
            ];
        }

        // Touch lock file to update timestamp
        @touch($this->lockFile);

        $results = [
            'status' => 'completed',
            'timestamp' => $now,
            'posts_found' => 0,
            'posts_processed' => 0,
            'posts_succeeded' => 0,
            'posts_failed' => 0,
            'details' => [],
            'duration' => 0
        ];

        try {
            // 2. Fetch scheduled posts whose publish time has arrived (Bounded Batch)
            $stmt = $this->db->prepare("
                SELECT id, tenant_id, scheduled_at, caption
                FROM social_posts
                WHERE status = 'scheduled'
                  AND scheduled_at <= :now
                ORDER BY scheduled_at ASC
                LIMIT :limit
            ");
            $stmt->bindValue(':now', $now, PDO::PARAM_STR);
            $stmt->bindValue(':limit', $this->batchLimit, PDO::PARAM_INT);
            $stmt->execute();
            $postsToPublish = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $results['posts_found'] = count($postsToPublish);

            // 3. Process each post sequentially
            foreach ($postsToPublish as $post) {
                $postId = (int)$post['id'];
                $tenantId = (int)$post['tenant_id'];

                $results['posts_processed']++;

                // Execute publishing
                $publishRes = $this->manager->publishPostNow($tenantId, $postId);

                if ($publishRes['success']) {
                    $results['posts_succeeded']++;
                } else {
                    $results['posts_failed']++;
                }

                $results['details'][] = [
                    'post_id' => $postId,
                    'tenant_id' => $tenantId,
                    'status' => $publishRes['status'],
                    'success' => $publishRes['success']
                ];
            }

            // 4. Update last cron run timestamp in settings
            $updSetting = $this->db->prepare("
                UPDATE social_settings
                SET setting_value = :now, updated_at = :now
                WHERE tenant_id IS NULL AND setting_key = 'social_cron_last_run'
            ");
            $updSetting->execute([':now' => $now]);

            if ($updSetting->rowCount() === 0) {
                $insSetting = $this->db->prepare("
                    INSERT INTO social_settings (tenant_id, setting_key, setting_value, is_secret, created_at, updated_at)
                    VALUES (NULL, 'social_cron_last_run', :now, 0, :now, :now)
                ");
                $insSetting->execute([':now' => $now]);
            }

        } finally {
            // Release and close lock file
            if ($lockFp) {
                flock($lockFp, LOCK_UN);
                fclose($lockFp);
            }
        }

        $results['duration'] = round(microtime(true) - $startTime, 3);
        return $results;
    }
}
