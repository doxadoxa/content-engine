<?php

declare(strict_types=1);

/** New articles have their own identity and receipts; existing object edits retain the bounded API. */
final class Avyo_Articles
{
    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$wpdb->prefix}avyo_articles (
            content_id char(26) NOT NULL,
            project_slug varchar(191) NOT NULL,
            post_id bigint(20) unsigned NOT NULL,
            content_hash char(64) NOT NULL,
            source_hash char(64) NOT NULL,
            PRIMARY KEY  (content_id),
            UNIQUE KEY post_id (post_id)
        ) ENGINE=InnoDB $charset;");
        dbDelta("CREATE TABLE {$wpdb->prefix}avyo_article_receipts (
            delivery_id char(36) NOT NULL,
            content_id char(26) NOT NULL,
            request_hash char(64) NOT NULL,
            result longtext NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (delivery_id),
            KEY content_id (content_id)
        ) ENGINE=InnoDB $charset;");
    }

    public static function routes(): void
    {
        register_rest_route('avyo/v1', '/articles', [
            'methods' => 'POST', 'permission_callback' => [self::class, 'permission'],
            'callback' => [self::class, 'receive'],
        ]);
    }

    public static function permission()
    {
        if ((! is_ssl() && ! in_array(wp_get_environment_type(), ['local', 'development'], true))
            || ! is_user_logged_in() || ! current_user_can('publish_posts') || ! current_user_can('edit_posts')) {
            return self::error('Use a publishing account over HTTPS.', 403);
        }

        return true;
    }

    public static function receive($request)
    {
        global $wpdb;
        $raw = $request->get_body();
        $data = $request->get_json_params();
        if (strlen($raw) > 1_000_000 || ! is_array($data) || ($data['contract'] ?? null) !== 1
            || ! is_string($data['delivery_id'] ?? null) || ! preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/Di', $data['delivery_id'])) {
            return self::error('Unsupported article request.', 422);
        }
        if (($data['event'] ?? '') === 'ping') {
            foreach (['avyo_articles', 'avyo_article_receipts'] as $suffix) {
                $engine = $wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $wpdb->prefix.$suffix));
                if (strtolower((string) $engine) !== 'innodb') {
                    return self::error('Activate the updated Avyo plugin before testing article publishing.', 503);
                }
            }

            return new WP_REST_Response(['contract' => 1, 'delivery_id' => $data['delivery_id'], 'capabilities' => ['article_publish' => true]], 200);
        }
        if (! in_array($data['event'] ?? '', ['content.published', 'content.updated'], true)) {
            return self::error('Only article creation and updates are supported.', 422);
        }
        $content = $data['content'] ?? null;
        $project = $data['project']['slug'] ?? null;
        if (! is_array($content) || ! is_string($content['id'] ?? null) || ! preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/Di', $content['id'])
            || ! is_string($project) || ! preg_match('/^[a-z0-9][a-z0-9-]{0,190}$/D', $project)
            || ! is_string($content['title'] ?? null) || trim($content['title']) === '' || strlen($content['title']) > 1000
            || ! is_string($content['html'] ?? null) || trim($content['html']) === '' || strlen($content['html']) > 500_000
            || ! is_string($content['slug'] ?? null) || $content['slug'] === '' || strlen($content['slug']) > 191
            || ! is_string($content['locale'] ?? null) || ! preg_match('/^[a-z]{2,3}(?:[-_][A-Za-z]{2,4})?$/D', $content['locale'])
            || ! is_string($content['summary'] ?? null) || strlen($content['summary']) > 4000) {
            return self::error('An article needs its identity, language, title, slug, HTML and summary.', 422);
        }
        if (wp_kses_post($content['html']) !== $content['html'] || wp_strip_all_tags($content['title']) !== $content['title']) {
            return self::error('The article contains unsupported or unsafe markup. Review it before publishing.', 422);
        }
        // A plain WordPress installation has one language. Do not claim a translated URL.
        if (strtolower(substr(str_replace('_', '-', get_locale()), 0, 2)) !== strtolower(substr($content['locale'], 0, 2))) {
            return self::error('This article language does not match the WordPress site language.', 422);
        }
        $lock = 'avyo-article-'.substr(hash('sha256', $content['id']), 0, 40);
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $lock)) !== 1) {
            return self::error('This article is already being delivered. Retry the same delivery.', 503);
        }
        $postId = 0;
        try {
            foreach ([$wpdb->posts, $wpdb->postmeta, $wpdb->term_relationships, $wpdb->term_taxonomy, $wpdb->prefix.'avyo_articles', $wpdb->prefix.'avyo_article_receipts'] as $table) {
                $engine = $wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table));
                if (strtolower((string) $engine) !== 'innodb') {
                    return self::error('Article delivery requires transactional WordPress tables. Activate the updated Avyo plugin.', 503);
                }
            }
            if ($wpdb->query('START TRANSACTION') === false) {
                throw new RuntimeException('Could not start the article transaction.');
            }
            $receipts = $wpdb->prefix.'avyo_article_receipts';
            $articles = $wpdb->prefix.'avyo_articles';
            $receipt = $wpdb->get_row($wpdb->prepare("SELECT * FROM $receipts WHERE delivery_id = %s FOR UPDATE", $data['delivery_id']), ARRAY_A);
            if ($wpdb->last_error !== '') {
                throw new RuntimeException('Could not read the delivery receipt.');
            }
            if ($receipt) {
                if (! hash_equals($receipt['request_hash'], hash('sha256', $raw))) {
                    $wpdb->query('ROLLBACK');

                    return self::error('This delivery identity was used for different content.', 409);
                }
                $saved = json_decode($receipt['result'], true);
                if (! is_array($saved) || ! current_user_can('edit_post', (int) $saved['object_id'])) {
                    $wpdb->query('ROLLBACK');

                    return self::error('The original article is not accessible to this account.', 403);
                }
                if ($wpdb->query('COMMIT') === false) {
                    throw new RuntimeException('Could not confirm the delivery receipt.');
                }

                return new WP_REST_Response($saved, 200);
            }
            $article = $wpdb->get_row($wpdb->prepare("SELECT * FROM $articles WHERE content_id = %s FOR UPDATE", $content['id']), ARRAY_A);
            if ($wpdb->last_error !== '') {
                throw new RuntimeException('Could not read the article identity.');
            }
            $hashContent = $content;
            unset($hashContent['published_at']);
            $contentHash = hash('sha256', json_encode($hashContent, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            if ($article) {
                $postId = (int) $article['post_id'];
                $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE ID = %d FOR UPDATE", $postId));
                if ($wpdb->last_error !== '') {
                    throw new RuntimeException('Could not lock the article.');
                }
                clean_post_cache($postId);
                $post = get_post($postId);
                if ($article['project_slug'] !== $project || ! $post || ! current_user_can('edit_post', $postId)
                    || $post->post_type !== 'post' || $post->post_status !== 'publish' || $post->post_password !== ''
                    || ! hash_equals($article['source_hash'], self::sourceHash($postId))) {
                    $wpdb->query('ROLLBACK');

                    return self::error('The website article changed outside Avyo. Review it before updating.', 409);
                }
            }
            $originalUrl = $article ? get_permalink($postId) : null;
            $originalAuthor = $article ? get_post($postId)->post_author : null;
            if (! $article || ! hash_equals($article['content_hash'], $contentHash)) {
                $fields = ['post_title' => $content['title'], 'post_content' => $content['html'], 'post_excerpt' => wp_strip_all_tags($content['summary'])];
                if ($article) {
                    $result = wp_update_post(wp_slash(['ID' => $postId, ...$fields]), true);
                } else {
                    $result = wp_insert_post(wp_slash([...$fields, 'post_type' => 'post', 'post_status' => 'publish', 'post_name' => sanitize_title($content['slug']), 'post_author' => get_current_user_id()]), true);
                }
                if (is_wp_error($result) || ! $result) {
                    throw new RuntimeException('WordPress refused the article write.');
                }
                $postId = (int) $result;
                clean_post_cache($postId);
                $written = get_post($postId);
                if (! $written || $written->post_title !== $content['title'] || $written->post_content !== $content['html'] || $written->post_status !== 'publish'
                    || $written->post_excerpt !== wp_strip_all_tags($content['summary']) || $written->post_type !== 'post' || $written->post_password !== ''
                    || ($article && ($written->post_author !== $originalAuthor || get_permalink($postId) !== $originalUrl))) {
                    throw new RuntimeException('A WordPress filter changed the requested article.');
                }
                if (Avyo_Receiver::description_owner($postId) === 'avyo') {
                    update_post_meta($postId, '_avyo_meta_description', wp_slash(wp_strip_all_tags($content['summary'])));
                    if (get_post_meta($postId, '_avyo_meta_description', true) !== wp_strip_all_tags($content['summary'])) {
                        throw new RuntimeException('Could not preserve the article description.');
                    }
                }
                if (url_to_postid(get_permalink($postId)) !== $postId) {
                    throw new RuntimeException('The public URL does not resolve to this article.');
                }
                $values = ['project_slug' => $project, 'post_id' => $postId, 'content_hash' => $contentHash, 'source_hash' => self::sourceHash($postId)];
                $saved = $article ? $wpdb->update($articles, $values, ['content_id' => $content['id']]) : $wpdb->insert($articles, ['content_id' => $content['id'], ...$values]);
                if ($saved === false) {
                    throw new RuntimeException('Could not preserve the article identity.');
                }
            }
            $result = ['contract' => 1, 'delivery_id' => $data['delivery_id'], 'content_id' => $content['id'], 'content_hash' => $contentHash,
                'object_id' => (string) $postId, 'public_url' => get_permalink($postId), 'status' => 'published'];
            if ($wpdb->insert($receipts, ['delivery_id' => $data['delivery_id'], 'content_id' => $content['id'], 'request_hash' => hash('sha256', $raw), 'result' => wp_json_encode($result), 'created_at' => gmdate('Y-m-d H:i:s')]) === false) {
                throw new RuntimeException('Could not preserve the publication receipt.');
            }
            if ($wpdb->query('COMMIT') === false) {
                throw new RuntimeException('The article commit could not be confirmed.');
            }

            return new WP_REST_Response($result, 200);
        } catch (Throwable $exception) {
            $wpdb->query('ROLLBACK');

            return self::error('The article outcome could not be confirmed. Retry the same delivery identity.', 503);
        } finally {
            if ($postId > 0) {
                clean_post_cache($postId);
            }
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    private static function sourceHash(int $id): string
    {
        $post = get_post($id);

        return hash('sha256', wp_json_encode([$post->post_title, $post->post_content, $post->post_excerpt, $post->post_status, $post->post_name, $post->post_password, $post->post_author, get_post_meta($id, '_avyo_meta_description', true), get_permalink($id)]));
    }

    private static function error(string $message, int $status): WP_Error
    {
        return new WP_Error('avyo_article_refused', $message, ['status' => $status]);
    }
}
