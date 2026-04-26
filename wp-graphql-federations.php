<?php
/**
 * Plugin Name: WPGraphQL Federations
 * Description: Adds Apollo Federation support to WPGraphQL and provides a runtime registry for SDL fragments and entity resolvers.
 * Version: 0.1.0
 * Author: Manuel Antunes
 * Text Domain: wp-graphql-federations
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

error_log('WPGraphQL Federation Main File Loaded');

// Prefer composer autoload if available
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

// Fallback autoloader for the plugin itself
spl_autoload_register(function ($class) {
    $prefix = 'Manuelantunes\\WpGraphqlFederations\\';
    $base_dir = __DIR__ . '/src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

\Manuelantunes\WpGraphqlFederations\Federation::init();

if ( is_admin() ) {
	\Manuelantunes\WpGraphqlFederations\Admin::init();
}
