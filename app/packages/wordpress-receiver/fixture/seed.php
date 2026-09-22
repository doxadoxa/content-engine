<?php

declare(strict_types=1);

if (wp_get_environment_type() !== 'local' || get_option('siteurl') !== 'http://localhost:8093') {
    throw new RuntimeException('This seeder is only for the isolated localhost WordPress fixture.');
}

function avyo_fixture_user(string $login, string $role): array
{
    $user = get_user_by('login', $login);
    $id = $user ? $user->ID : wp_insert_user(['user_login' => $login, 'user_pass' => 'fixture-login-only-not-for-production', 'user_email' => $login.'@wordpress-fixture.test', 'role' => $role]);
    if (is_wp_error($id)) {
        throw new RuntimeException($id->get_error_message());
    }
    $result = WP_Application_Passwords::create_new_application_password($id, ['name' => 'Avyo isolated fixture '.gmdate('c')]);
    if (is_wp_error($result)) {
        throw new RuntimeException($result->get_error_message());
    }

    return ['id' => $id, 'username' => $login, 'password' => $result[0]];
}

$editor = avyo_fixture_user('avyo-editor', 'editor');
$subscriber = avyo_fixture_user('avyo-reader', 'subscriber');
$paragraph = '<!-- wp:paragraph -->'."\n".'<p>Home cleaning visits include the kitchen and bathroom.</p>'."\n".'<!-- /wp:paragraph -->';
$form = '<!-- wp:html -->'."\n".'<form action="#booking" method="post"><label>Name <input name="name" required></label><button>Request a visit</button></form>'."\n".'<!-- /wp:html -->';
$fixtures = [
    'service' => ['page', '<!-- wp:heading -->'."\n".'<h2 class="wp-block-heading">Home cleaning</h2>'."\n".'<!-- /wp:heading -->'."\n\n".$paragraph."\n\n".$form],
    'article' => ['post', '<!-- wp:paragraph -->'."\n".'<p>Prepare your home before the cleaning visit.</p>'."\n".'<!-- /wp:paragraph -->'],
    'builder' => ['page', $paragraph],
    'dynamic' => ['page', '<!-- wp:latest-posts /-->'],
    'seo_owned' => ['post', $paragraph],
    'classic' => ['post', '<p>Read about our home cleaning service.</p><p title="a > b and <p> hidden">Keep attributes intact.</p><form><p>Protected form paragraph</p></form>'],
    'duplicate' => ['post', '<p>Same anchor</p><p>Same anchor</p>'],
];
$ids = [];
foreach ($fixtures as $key => [$type, $content]) {
    // New fixture identities per run preserve all previous run evidence.
    $id = wp_insert_post(wp_slash(['post_type' => $type, 'post_status' => 'publish', 'post_title' => 'Avyo fixture '.str_replace('_', ' ', $key), 'post_content' => $content, 'post_author' => $editor['id'], 'post_excerpt' => 'Unrelated excerpt retained.']), true);
    if (is_wp_error($id)) {
        throw new RuntimeException($id->get_error_message());
    }
    update_post_meta($id, 'unrelated_business_setting', 'must-remain-byte-identical');
    $ids[$key] = $id;
}
update_post_meta($ids['builder'], '_elementor_edit_mode', 'builder');
update_post_meta($ids['seo_owned'], '_yoast_wpseo_metadesc', 'SEO plugin owns this description.');
update_option('avyo_owns_description', true);
update_option('blogname', 'Avyo WordPress fixture');
update_option('permalink_structure', '/%postname%/');
flush_rewrite_rules(true);
update_option('avyo_fixture', ['editor' => $editor, 'subscriber' => $subscriber, 'objects' => $ids, 'paragraph' => $paragraph, 'form' => $form]);
WP_CLI::success('Created local core service-page, article, builder, dynamic-block, SEO-owner and preservation fixtures.');
