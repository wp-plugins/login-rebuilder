<?php
function is_reserved_login_file( $filename ) {
	return in_array( $filename,
			array( 'index.php', 'wp-activate.php', 'wp-app.php', 'wp-atom.php', 'wp-blog-header.php',
					'wp-comments-post.php', 'wp-commentsrss2.php', 'wp-config.php', 'wp-config-sample.php', 'wp-cron.php',
					'wp-feed.php', 'wp-links-opml.php', 'wp-load.php', 'wp-login.php', 'wp-mail.php',
					'wp-pass.php', 'wp-rdf.php', 'wp-register.php', 'wp-rss.php', 'wp-rss2.php',
					'wp-settings.php', 'wp-signup.php', 'wp-trackback.php', 'xmlrpc.php' ) );
}

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) )
	exit();

define( 'LOGIN_REBUILDER_PROPERTIES', 'login-rebuilder' );
$properties = get_site_option( LOGIN_REBUILDER_PROPERTIES, array( 'deleted'=>true ) );
if ( isset( $properties['page'] ) &&
	!is_reserved_login_file( $properties['page'] ) &&
	@file_exists( ABSPATH.$properties['page'] ) )
	@unlink( ABSPATH.$properties['page'] );
if ( isset( $properties['page_subscriber'] ) &&
	!is_reserved_login_file( $properties['page_subscriber'] ) &&
	@file_exists( ABSPATH.$properties['page_subscriber'] ) )
	@unlink( ABSPATH.$properties['page_subscriber'] );
if ( !isset( $properties['deleted'] ) )
	delete_site_option( LOGIN_REBUILDER_PROPERTIES );
?>