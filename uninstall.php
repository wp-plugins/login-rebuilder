<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) )
	exit();

define( 'LOGIN_REBUILDER_PROPERTIES', 'login-rebuilder' );
$properties = get_site_option( LOGIN_REBUILDER_PROPERTIES, array( 'deleted'=>true ) );
if ( isset( $properties['page'] ) )
	@unlink( ABSPATH.$properties['page'] );
if ( !isset( $properties['deleted'] ) )
	delete_site_option( LOGIN_REBUILDER_PROPERTIES );
?>