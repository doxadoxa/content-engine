<?php

declare(strict_types=1);

/** Local fixture only; never distribute this file in the receiver plugin. */
if (wp_get_environment_type() !== 'local') {
    return;
}
add_filter('pre_wp_mail', '__return_true');
add_filter('automatic_updater_disabled', '__return_true');
add_filter('wp_insert_post_data', static function ($data) {
    if (get_option('avyo_fixture_transform_source', false) === 'unrelated') {
        $data['post_excerpt'] = 'Unapproved change by fixture filter';
    } elseif (get_option('avyo_fixture_transform_source', false)) {
        $data['post_title'] .= ' transformed by fixture filter';
    }

    return $data;
});

// Real SQL failures exercise wpdb's false/last_error behaviour, not PHP mocks.
add_action('init', static function () {
    $fault = get_option('avyo_fixture_sql_fault', '');
    if (! is_string($fault) || $fault === '') {
        return;
    }
    add_filter('query', static function (string $sql) use ($fault): string {
        $matches = match ($fault) {
            'begin' => $sql === 'START TRANSACTION',
            'post_lock' => str_starts_with($sql, 'SELECT ID FROM ') && str_ends_with($sql, ' FOR UPDATE'),
            'meta_lock' => str_starts_with($sql, 'SELECT meta_id FROM ') && str_ends_with($sql, ' FOR UPDATE'),
            'term_lock' => str_starts_with($sql, 'SELECT term_taxonomy_id FROM ') && str_ends_with($sql, ' FOR UPDATE'),
            'meta_read' => str_starts_with($sql, 'SELECT meta_key, meta_value FROM '),
            'revision_read' => str_starts_with($sql, 'SELECT MAX(ID) FROM '),
            'operation_read' => str_starts_with($sql, 'SELECT result FROM '),
            default => false,
        };

        return $matches ? 'AVYO_INTENTIONAL_FIXTURE_SQL_FAILURE' : $sql;
    });
});
