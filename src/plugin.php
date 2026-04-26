<?php
/*
 Plugin Name: WPGraphQL Federations
 Description: Adds Apollo Federation support to WPGraphQL and provides a runtime registry for SDL fragments and entity resolvers.
 Version: 0.1.0
 Author: Manuel Antunes
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

error_log('WPGraphQL Federation Plugin: File loaded');

// Prefer composer autoload if available
$autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoload)) {
	require_once $autoload;
}

// Fallback autoloader for the plugin itself if composer isn't run
spl_autoload_register(function ($class) {
    $prefix = 'Manuelantunes\\WpGraphqlFederations\\';
    $base_dir = __DIR__ . '/';

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

use Manuelantunes\WpGraphqlFederations\Federation;
use Manuelantunes\WpGraphqlFederations\Admin;

Federation::init();
Admin::init();
