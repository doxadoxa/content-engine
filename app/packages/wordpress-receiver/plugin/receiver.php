<?php

declare(strict_types=1);

/** No rendered HTML is ever written back as a WordPress source document. */
final class Avyo_Receiver
{
    private const META = '_avyo_meta_description';

    private const LIMIT = 1048576;

    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $table = $wpdb->prefix.'avyo_operations';
        dbDelta("CREATE TABLE $table (
            operation_id char(36) NOT NULL,
            post_id bigint(20) unsigned NOT NULL,
            actor_id bigint(20) unsigned NOT NULL,
            request_hash char(64) NOT NULL,
            result longtext NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (operation_id),
            KEY post_id (post_id)
        ) ENGINE=InnoDB ".$wpdb->get_charset_collate().';');
    }

    public static function routes(): void
    {
        register_rest_route('avyo/v1', '/objects/(?P<id>[1-9][0-9]*)', [
            'methods' => 'GET', 'permission_callback' => [self::class, 'permission'],
            'callback' => static fn ($request) => self::read((int) $request['id']),
        ]);
        register_rest_route('avyo/v1', '/objects/(?P<id>[1-9][0-9]*)/operations', [
            'methods' => 'POST', 'permission_callback' => [self::class, 'permission'],
            'callback' => [self::class, 'apply'],
        ]);
        register_rest_route('avyo/v1', '/operations/(?P<operation>[a-fA-F0-9-]{36})', [
            'methods' => 'GET', 'permission_callback' => [self::class, 'operation_permission'],
            'callback' => static fn ($request) => self::operation_response((string) $request['operation']),
        ]);
    }

    public static function permission($request)
    {
        if (! is_ssl() && ! in_array(wp_get_environment_type(), ['local', 'development'], true)) {
            return self::error('avyo_https_required', 'Use HTTPS for this connection.', 403);
        }
        $post = get_post((int) $request['id']);
        if (! is_user_logged_in() || ! $post || ! current_user_can('edit_post', $post->ID)) {
            return self::error('avyo_forbidden', 'This account cannot access the object.', 403);
        }
        $type = get_post_type_object($post->post_type);
        if (! $type || ! current_user_can($type->cap->publish_posts)) {
            return self::error('avyo_forbidden', 'A publishing-capable account is required.', 403);
        }

        return true;
    }

    public static function operation_permission($request)
    {
        try {
            $operation = self::operation((string) $request['operation']);
        } catch (Throwable $exception) {
            return self::error('avyo_read_failed', 'The operation could not be read reliably. Retry the read.', 503);
        }
        if (! $operation) {
            return self::error('avyo_operation_unknown', 'No committed operation has this identity.', 404);
        }
        $request['id'] = (int) $operation['object_id'];

        return self::permission($request);
    }

    public static function read(int $id)
    {
        try {
            return self::source($id);
        } catch (Throwable $exception) {
            return self::error('avyo_read_failed', 'The source could not be read reliably. No snapshot is available.', 503);
        }
    }

    public static function apply($request)
    {
        try {
            return self::apply_request($request);
        } catch (Throwable $exception) {
            return self::rollback(self::error('avyo_outcome_unknown', 'The database operation failed. Reconcile this identity before retrying.', 503), (int) $request['id']);
        }
    }

    public static function operation_response(string $operation)
    {
        try {
            $result = self::operation($operation);
        } catch (Throwable $exception) {
            return self::error('avyo_read_failed', 'The operation could not be read reliably. Retry the read.', 503);
        }

        return $result ? new WP_REST_Response($result, 200) : self::error('avyo_operation_unknown', 'No committed operation has this identity.', 404);
    }

    public static function description(): void
    {
        try {
            $owned = is_singular(['page', 'post']) && self::description_owner(get_queried_object_id()) === 'avyo';
        } catch (Throwable $exception) {
            return;
        }
        if ($owned) {
            $description = (string) get_post_meta(get_queried_object_id(), self::META, true);
            if ($description !== '') {
                echo '<meta name="description" content="'.esc_attr($description).'">'."\n";
            }
        }
    }

    public static function settings_menu(): void
    {
        add_options_page('Avyo receiver', 'Avyo receiver', 'manage_options', 'avyo-receiver', [self::class, 'settings']);
    }

    public static function settings(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        if (isset($_POST['avyo_save'])) {
            check_admin_referer('avyo_description_owner');
            update_option('avyo_owns_description', isset($_POST['avyo_owns_description']));
        }
        echo '<div class="wrap"><h1>Avyo receiver</h1><p>Use a dedicated publishing account and an Application Password over HTTPS. Avyo never starts a publication without an explicit API operation.</p><p>Core page/post text is supported. Page builders and unsupported fields require an assisted handoff. Verify every result on the public page.</p><form method="post">';
        wp_nonce_field('avyo_description_owner');
        echo '<label><input type="checkbox" name="avyo_owns_description" value="1" '.checked(get_option('avyo_owns_description', false), true, false).'> Assign meta descriptions to Avyo</label><p>Enable only after checking that the theme and other plugins do not emit descriptions. Known SEO-plugin ownership disables this field automatically. Existing plugin metadata is never overwritten.</p>';
        submit_button('Save ownership', 'primary', 'avyo_save');
        echo '</form></div>';
    }

    public static function description_owner(int $id, ?array $meta = null): string
    {
        global $wpdb;
        foreach (['WPSEO_VERSION', 'RANK_MATH_VERSION', 'AIOSEO_VERSION', 'SEOPRESS_VERSION', 'THE_SEO_FRAMEWORK_VERSION'] as $constant) {
            if (defined($constant)) {
                return 'seo_plugin';
            }
        }
        foreach (['_yoast_wpseo_metadesc', 'rank_math_description', '_aioseo_description', '_seopress_titles_desc', '_genesis_description'] as $key) {
            if ($meta !== null ? in_array($key, array_column($meta, 'meta_key'), true) : metadata_exists('post', $id, $key)) {
                return 'seo_plugin_metadata';
            }
        }

        return self::value($wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name = %s", 'avyo_owns_description')) ? 'avyo' : 'unassigned';
    }

    private static function source(int $id)
    {
        global $wpdb;
        clean_post_cache($id);
        $rows = self::rows($wpdb->prepare("SELECT * FROM $wpdb->posts WHERE ID = %d", $id));
        $post = isset($rows[0]) ? new WP_Post((object) $rows[0]) : null;
        if (! $post || ! in_array($post->post_type, ['page', 'post'], true)) {
            return self::error('avyo_unsupported_object', 'Only existing core pages and posts are supported.', 422);
        }
        $meta = self::rows($wpdb->prepare("SELECT meta_key, meta_value FROM $wpdb->postmeta WHERE post_id = %d AND meta_key NOT IN ('_edit_lock', '_edit_last') ORDER BY meta_key, meta_id", $id));
        $owner = self::description_owner($id, $meta);
        $public_url = get_permalink($post);
        self::database_ok();
        $reason = null;
        if ($post->post_status !== 'publish' || $post->post_password !== '') {
            $reason = 'Only public, already-published content is supported.';
        }
        if ((int) url_to_postid($public_url) !== $id) {
            $reason = 'The public URL does not resolve to this exact core object. Use an assisted handoff.';
        }
        self::database_ok();
        foreach (['_elementor_edit_mode', '_et_pb_use_builder', '_fl_builder_enabled', '_wpb_vc_js_status'] as $key) {
            $value = self::meta_value($meta, $key);
            if ($value && $value !== 'off' && $value !== 'false') {
                $reason = 'This page builder needs an assisted handoff.';
            }
        }
        if (! self::transactional()) {
            $reason = 'The posts, metadata and operation tables must use InnoDB.';
        }
        $body_supported = self::supported_blocks($post->post_content);
        $fields = ['title' => $post->post_title, 'description' => self::meta_value($meta, self::META), 'body_html' => $post->post_content, 'body_text' => wp_strip_all_tags($post->post_content)];
        $editable = $reason ? [] : ['title'];
        if (! $reason && $owner === 'avyo') {
            $editable[] = 'description';
        }
        if (! $reason && $body_supported) {
            $editable[] = 'body_html';
        }
        $identity = [
            'post' => array_intersect_key((array) $post, array_flip(['ID', 'post_type', 'post_status', 'post_password', 'post_title', 'post_content', 'post_excerpt', 'post_name', 'post_parent', 'post_modified_gmt', 'post_author'])),
            'meta' => $meta, 'description_owner' => $owner, 'public_url' => $public_url,
            'terms' => self::rows($wpdb->prepare("SELECT term_taxonomy_id, term_order FROM $wpdb->term_relationships WHERE object_id = %d ORDER BY term_taxonomy_id", $id)),
        ];
        $preserved = $identity;
        unset($preserved['post']['post_title'], $preserved['post']['post_content'], $preserved['post']['post_modified_gmt']);
        $preserved['meta'] = array_values(array_filter($meta, static fn ($row) => $row['meta_key'] !== self::META));
        $revision_id = self::value($wpdb->prepare("SELECT MAX(ID) FROM $wpdb->posts WHERE post_parent = %d AND post_type = 'revision'", $id));
        // Content can be edited away and restored within one timestamp second.
        // The native revision identity still invalidates the reviewed source.
        $identity['core_revision_id'] = $revision_id === null ? null : (string) $revision_id;

        return [
            'schema_v' => 1, 'object_id' => (string) $id, 'object_type' => $post->post_type,
            'public_url' => $public_url, 'revision' => hash('sha256', wp_json_encode($identity)),
            'core_revision_id' => $identity['core_revision_id'],
            'fields' => $fields, 'editable_fields' => $editable,
            'metadata' => [
                'description_owner' => $owner, 'description_present' => in_array(self::META, array_column($meta, 'meta_key'), true),
                'preservation_hash' => hash('sha256', wp_json_encode($preserved)),
                'unsupported_reason' => $reason,
                'body_unsupported_reason' => $body_supported ? null : 'Custom/dynamic blocks require an assisted handoff.',
                'title_semantics' => 'Raw post title; the public document title may use a theme template or suffix.',
            ],
            'capabilities' => ['patch_operations' => ['replace', 'insert_after', 'link'], 'idempotency' => true, 'reconciliation' => true, 'recovery' => true],
            'verification' => [
                'public_fetch_required' => true, 'api_success_is_verification' => false,
                'title' => 'Inspect the visible post heading. Do not equate it to the full HTML document title.',
                'description' => 'Require exactly one public meta[name=description] with the approved value.',
                'body_html' => 'Check the intended rendered text/link and retained form/layout at the public URL.',
            ],
        ];
    }

    private static function apply_request($request)
    {
        global $wpdb;
        $body = $request->get_json_params();
        if (! is_array($body) || strlen($request->get_body()) > self::LIMIT || array_diff(array_keys($body), ['operation_id', 'expected_revision', 'patches', 'restores_operation_id'])) {
            return self::error('avyo_invalid_request', 'Use a bounded JSON operation with only documented fields.', 422);
        }
        $operation = is_string($body['operation_id'] ?? null) ? strtolower($body['operation_id']) : '';
        if (! self::uuid($operation) || ! is_string($body['expected_revision'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $body['expected_revision']) || (isset($body['restores_operation_id']) && (! is_string($body['restores_operation_id']) || ! self::uuid(strtolower($body['restores_operation_id']))))) {
            return self::error('avyo_invalid_request', 'An operation UUID and source revision are required.', 422);
        }
        $id = (int) $request['id'];
        $hash = hash('sha256', wp_json_encode(['object_id' => $id, 'body' => $body]));
        $existing = self::operation($operation);
        if ($existing) {
            return self::replay($existing, $hash);
        }
        if (! self::transactional()) {
            return self::error('avyo_storage_unsupported', 'InnoDB is required before changes can be accepted.', 422);
        }
        self::statement('START TRANSACTION');
        try {
            // Core WordPress edits acquire this same row lock when they write.
            if (self::value($wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE ID = %d FOR UPDATE", $id)) === null) {
                throw new RuntimeException('The source row disappeared before locking.');
            }
            self::rows($wpdb->prepare("SELECT meta_id FROM $wpdb->postmeta WHERE post_id = %d FOR UPDATE", $id));
            self::rows($wpdb->prepare("SELECT term_taxonomy_id FROM $wpdb->term_relationships WHERE object_id = %d FOR UPDATE", $id));
            $existing = self::operation($operation);
            if ($existing) {
                $wpdb->query('ROLLBACK');

                return self::replay($existing, $hash);
            }
            $before = self::read($id);
            if (is_wp_error($before)) {
                return self::rollback($before, $id);
            }
            if (! hash_equals($before['revision'], $body['expected_revision'])) {
                return self::rollback(self::error('avyo_revision_conflict', 'The source changed. Capture it again and request a new review.', 409), $id);
            }
            if (! $before['editable_fields']) {
                return self::rollback(self::error('avyo_unsupported_layout', $before['metadata']['unsupported_reason'], 422), $id);
            }
            $restore = isset($body['restores_operation_id']);
            if ($restore && isset($body['patches'])) {
                return self::rollback(self::error('avyo_invalid_request', 'A recovery cannot contain replacement patches.', 422), $id);
            }
            $description_present = $before['metadata']['description_present'];
            if ($restore) {
                $original = self::operation((string) $body['restores_operation_id']);
                if (! $original || $original['object_id'] !== (string) $id || $original['kind'] !== 'publish' || ! hash_equals($original['after']['revision'], $before['revision'])) {
                    return self::rollback(self::error('avyo_recovery_conflict', 'Recovery requires the unchanged result of the original publication.', 409), $id);
                }
                $changes = array_intersect_key($original['before']['fields'], array_fill_keys($original['changed_fields'], true));
                $description_present = $original['before']['metadata']['description_present'];
                if (array_diff(array_keys($changes), $before['editable_fields'])) {
                    return self::rollback(self::error('avyo_capability_changed', 'Field ownership or capabilities changed; use an assisted recovery.', 409), $id);
                }
            } else {
                $changes = self::patches($before, $body['patches'] ?? null);
                if (is_wp_error($changes)) {
                    return self::rollback($changes, $id);
                }
            }
            $post_update = ['ID' => $id];
            if (isset($changes['title'])) {
                $post_update['post_title'] = $changes['title'];
            }
            if (isset($changes['body_html'])) {
                $post_update['post_content'] = $changes['body_html'];
            }
            if (count($post_update) > 1) {
                $updated = wp_update_post(wp_slash($post_update), true);
                if (is_wp_error($updated)) {
                    return self::rollback(self::error('avyo_write_failed', 'WordPress refused the source update.', 500), $id);
                }
            }
            if (array_key_exists('description', $changes)) {
                if ($restore && ! $description_present) {
                    delete_post_meta($id, self::META);
                } else {
                    update_post_meta($id, self::META, wp_slash($changes['description']));
                }
            }
            $after = self::read($id);
            if (is_wp_error($after) || $before['metadata']['preservation_hash'] !== $after['metadata']['preservation_hash']) {
                return self::rollback(self::error('avyo_unrelated_source_changed', 'A WordPress filter changed an unapproved field. Use an assisted handoff.', 409), $id);
            }
            foreach (['title', 'description', 'body_html'] as $field) {
                if (! array_key_exists($field, $changes) && $before['fields'][$field] !== $after['fields'][$field]) {
                    return self::rollback(self::error('avyo_unrelated_source_changed', 'A WordPress filter changed an unapproved field. Use an assisted handoff.', 409), $id);
                }
            }
            foreach ($changes as $field => $value) {
                if (is_wp_error($after) || $after['fields'][$field] !== $value) {
                    return self::rollback(self::error('avyo_source_transformed', 'A WordPress filter transformed the approved source. Use an assisted handoff.', 409), $id);
                }
            }
            $result = [
                'schema_v' => 1, 'operation_id' => $operation, 'object_id' => (string) $id,
                'request_hash' => $hash, 'status' => 'applied', 'kind' => $restore ? 'recovery' : 'publish',
                'restores_operation_id' => $restore ? $body['restores_operation_id'] : null,
                'changed_fields' => array_keys($changes), 'before' => $before, 'after' => $after,
                'public_verification' => 'required', 'committed_at' => gmdate('c'),
            ];
            $inserted = $wpdb->insert($wpdb->prefix.'avyo_operations', [
                'operation_id' => $operation, 'post_id' => $id, 'actor_id' => get_current_user_id(),
                'request_hash' => $hash, 'result' => wp_json_encode($result), 'created_at' => current_time('mysql', true),
            ]);
            if (! $inserted) {
                return self::rollback(self::error('avyo_operation_conflict', 'The operation identity is already reserved or could not be saved. Reconcile it before retrying.', 409), $id);
            }
            self::statement('COMMIT');
            clean_post_cache($id);

            return new WP_REST_Response($result, 200);
        } catch (Throwable $exception) {
            return self::rollback(self::error('avyo_outcome_unknown', 'Read the operation by its identity before retrying.', 503), $id);
        }
    }

    private static function patches(array $source, $patches)
    {
        if (! is_array($patches) || ! array_is_list($patches) || count($patches) < 1 || count($patches) > 20) {
            return self::error('avyo_invalid_patch', 'Provide between one and twenty exact patches.', 422);
        }
        $changes = [];
        foreach ($patches as $patch) {
            if (! is_array($patch) || array_diff(array_keys($patch), ['field', 'operation', 'before', 'after']) || count($patch) !== 4) {
                return self::error('avyo_invalid_patch', 'Each patch needs field, operation, before and after.', 422);
            }
            foreach ($patch as $value) {
                if (! is_string($value)) {
                    return self::error('avyo_invalid_patch', 'Patch values must be strings.', 422);
                }
            }
            ['field' => $field, 'operation' => $op, 'before' => $old, 'after' => $new] = $patch;
            if (! in_array($field, $source['editable_fields'], true)) {
                return self::error('avyo_unsupported_field', 'This field is not editable with the current owner/layout.', 422);
            }
            $current = $changes[$field] ?? $source['fields'][$field];
            if ($field !== 'body_html') {
                if ($op !== 'replace' || $old !== $current || $new === $old || strlen($new) > ($field === 'title' ? 1000 : 4000) || wp_strip_all_tags($new) !== $new) {
                    return self::error('avyo_invalid_patch', 'Titles and descriptions require an exact full plain-text replacement.', 422);
                }
                $changes[$field] = $new;

                continue;
            }
            if ($old === '' || substr_count($current, $old) !== 1 || strlen($new) > 100000) {
                return self::error('avyo_ambiguous_fragment', 'The exact body fragment must occur once.', 422);
            }
            $replacement = $new;
            if ($op === 'insert_after') {
                if (! self::complete_paragraph($old) || ! self::complete_paragraph($new)) {
                    return self::error('avyo_unsupported_insertion', 'Insert only a complete core paragraph after a complete core paragraph.', 422);
                }
                $replacement = $old."\n\n".$new;
            } elseif ($op === 'link') {
                if (! filter_var($new, FILTER_VALIDATE_URL) || strtolower((string) wp_parse_url($new, PHP_URL_SCHEME)) !== 'https' || wp_parse_url($new, PHP_URL_USER) || wp_parse_url($new, PHP_URL_PASS) || wp_strip_all_tags($old) !== $old || str_contains($old, '[')) {
                    return self::error('avyo_invalid_link', 'Use plain anchor text and an explicit HTTPS destination.', 422);
                }
                $replacement = '<a href="'.esc_attr($new).'">'.$old.'</a>';
            } elseif ($op !== 'replace') {
                return self::error('avyo_invalid_patch', 'Unsupported body operation.', 422);
            }
            $updated = str_replace($old, $replacement, $current);
            if ($op === 'replace' && ! self::safe_text_change($current, $updated)) {
                return self::error('avyo_unsafe_fragment', 'This patch would change markup, forms, shortcodes or unsupported text.', 422);
            }
            if ($op === 'link' && ! self::safe_link_position($current, $old)) {
                return self::error('avyo_unsafe_fragment', 'The link must target text in one supported paragraph or heading, outside any existing link/form.', 422);
            }
            if ($op === 'insert_after' && ! self::safe_insertion_position($current, $old)) {
                return self::error('avyo_unsafe_fragment', 'The insertion anchor must be a top-level supported core block.', 422);
            }
            if ($updated === $current || strlen($updated) > self::LIMIT) {
                return self::error('avyo_invalid_patch', 'The patch is empty or exceeds the source limit.', 422);
            }
            $changes[$field] = $updated;
        }

        return $changes;
    }

    private static function tokens(string $html): array
    {
        // Quoted > characters belong to their tag, not an editable text slot.
        return preg_split('~(<!--[\s\S]*?-->|</?[a-zA-Z](?:[^\'"<>]|"[^"]*"|\'[^\']*\')*>)~', $html, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
    }

    private static function safe_text_change(string $before, string $after): bool
    {
        $a = self::tokens($before);
        $b = self::tokens($after);
        if (count($a) !== count($b)) {
            return false;
        }
        $stack = [];
        foreach ($a as $index => $token) {
            if (str_starts_with($token, '<')) {
                if ($token !== $b[$index]) {
                    return false;
                }
                self::tag_stack($stack, $token);
            } elseif ($token !== $b[$index]) {
                if (! self::editable_text($stack) || strpbrk($token.$b[$index], '[<>') !== false) {
                    return false;
                }
            }
        }

        return true;
    }

    private static function safe_link_position(string $content, string $anchor): bool
    {
        $stack = [];
        foreach (self::tokens($content) as $token) {
            if (str_starts_with($token, '<')) {
                self::tag_stack($stack, $token);
            } elseif (str_contains($token, $anchor)) {
                return self::editable_text($stack) && ! in_array('a', $stack, true);
            }
        }

        return false;
    }

    private static function tag_stack(array &$stack, string $token): void
    {
        if (preg_match('/^<\/([a-z0-9]+)/i', $token, $m)) {
            $position = array_search(strtolower($m[1]), array_reverse($stack, true), true);
            if ($position !== false) {
                $stack = array_slice($stack, 0, $position);
            }
        } elseif (preg_match('/^<([a-z0-9]+)/i', $token, $m) && ! preg_match('/\/\s*>$/', $token) && ! in_array(strtolower($m[1]), ['br', 'hr', 'img', 'input', 'meta', 'link', 'source', 'area', 'embed', 'wbr'], true)) {
            $stack[] = strtolower($m[1]);
        }
    }

    private static function editable_text(array $stack): bool
    {
        return (bool) array_intersect($stack, ['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li'])
            && ! array_intersect($stack, ['form', 'script', 'style', 'textarea', 'button', 'select', 'option', 'iframe', 'svg']);
    }

    private static function complete_paragraph(string $value): bool
    {
        return (bool) preg_match('/^<!-- wp:paragraph -->\s*<p>[^<>\[\]]+<\/p>\s*<!-- \/wp:paragraph -->$/D', $value);
    }

    private static function safe_insertion_position(string $content, string $anchor): bool
    {
        $stack = [];
        $prefix = substr($content, 0, strpos($content, $anchor));
        foreach (self::tokens($prefix) as $token) {
            if (str_starts_with($token, '<')) {
                self::tag_stack($stack, $token);
            }
        }
        if ($stack !== []) {
            return false;
        }
        foreach (parse_blocks($content) as $block) {
            if ($block['blockName'] === 'core/paragraph' && serialize_block($block) === $anchor) {
                return true;
            }
        }

        return false;
    }

    private static function supported_blocks(string $content): bool
    {
        $check = static function (array $blocks) use (&$check): bool {
            foreach ($blocks as $block) {
                if (! in_array($block['blockName'], [null, 'core/paragraph', 'core/heading', 'core/list', 'core/list-item', 'core/group', 'core/columns', 'core/column', 'core/quote', 'core/image', 'core/html', 'core/separator', 'core/spacer'], true) || ! $check($block['innerBlocks'])) {
                    return false;
                }
            }

            return true;
        };

        return $check(parse_blocks($content));
    }

    private static function transactional(): bool
    {
        global $wpdb;
        foreach ([$wpdb->posts, $wpdb->postmeta, $wpdb->term_relationships, $wpdb->prefix.'avyo_operations'] as $table) {
            $engine = self::value($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table));
            if (strtolower((string) $engine) !== 'innodb') {
                return false;
            }
        }

        return true;
    }

    private static function operation(string $operation): ?array
    {
        global $wpdb;
        $json = self::value($wpdb->prepare('SELECT result FROM '.$wpdb->prefix.'avyo_operations WHERE operation_id = %s', strtolower($operation)));

        return $json ? json_decode($json, true, 512, JSON_THROW_ON_ERROR) : null;
    }

    private static function replay(array $operation, string $hash)
    {
        return hash_equals($operation['request_hash'], $hash)
            ? new WP_REST_Response($operation, 200)
            : self::error('avyo_operation_conflict', 'This identity was used for a different request.', 409);
    }

    private static function rollback($error, int $id)
    {
        global $wpdb;
        $wpdb->query('ROLLBACK');
        clean_post_cache($id);

        return $error;
    }

    private static function database_ok(): void
    {
        global $wpdb;
        if ($wpdb->last_error !== '') {
            throw new RuntimeException('A required database operation failed.');
        }
    }

    private static function statement(string $sql): void
    {
        global $wpdb;
        $result = $wpdb->query($sql);
        self::database_ok();
        if ($result === false) {
            throw new RuntimeException('A required transaction statement failed.');
        }
    }

    private static function value(string $sql)
    {
        global $wpdb;
        $result = $wpdb->get_var($sql);
        self::database_ok();

        return $result;
    }

    private static function rows(string $sql): array
    {
        global $wpdb;
        $result = $wpdb->get_results($sql, ARRAY_A);
        self::database_ok();
        if (! is_array($result)) {
            throw new RuntimeException('A required source read failed.');
        }

        return $result;
    }

    private static function meta_value(array $meta, string $key): string
    {
        foreach ($meta as $row) {
            if ($row['meta_key'] === $key) {
                $value = maybe_unserialize($row['meta_value']);
                if (! is_scalar($value)) {
                    throw new RuntimeException('The metadata value is not a supported scalar.');
                }

                return (string) $value;
            }
        }

        return '';
    }

    private static function error(string $code, string $message, int $status): WP_Error
    {
        return new WP_Error($code, $message, ['status' => $status]);
    }

    private static function uuid(string $value): bool
    {
        return (bool) preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $value);
    }
}
