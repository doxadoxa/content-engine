<?php

declare(strict_types=1);

/**
 * Plugin Name: Avyo reviewed page receiver
 * Description: Scheduled articles and revision-checked updates to core pages and posts.
 * Version: 0.2.0
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * License: MIT
 */
if (! defined('ABSPATH')) {
    exit;
}

require_once __DIR__.'/receiver.php';
require_once __DIR__.'/articles.php';
register_activation_hook(__FILE__, ['Avyo_Receiver', 'install']);
register_activation_hook(__FILE__, ['Avyo_Articles', 'install']);
add_action('rest_api_init', ['Avyo_Receiver', 'routes']);
add_action('rest_api_init', ['Avyo_Articles', 'routes']);
add_action('admin_menu', ['Avyo_Receiver', 'settings_menu']);
add_action('wp_head', ['Avyo_Receiver', 'description'], 2);
