<?php
/*
Plugin Name: Login rebuilder
Plugin URI: http://elearn.jp/wpman/column/login-rebuilder.html
Description: This plug-in will make a new login page for your site.
Author: tmatsuur
Version: 1.4.3
Author URI: http://12net.jp/
*/

/*
 Copyright (C) 2013-2015 tmatsuur (Email: takenori dot matsuura at 12net dot jp)
This program is licensed under the GNU GPL Version 2.
*/

define( 'LOGIN_REBUILDER_DOMAIN', 'login-rebuilder' );
define( 'LOGIN_REBUILDER_DB_VERSION_NAME', 'login-rebuilder-db-version' );
define( 'LOGIN_REBUILDER_DB_VERSION', '1.4.3' );
define( 'LOGIN_REBUILDER_PROPERTIES', 'login-rebuilder' );
define( 'LOGIN_REBUILDER_LOGGING_NAME', 'login-rebuilder-logging' );

$plugin_login_rebuilder = new login_rebuilder();

class login_rebuilder {
	const LOGIN_REBUILDER_PROPERTIES_NAME = 'login-rebuilder-properties';
	const LOGIN_REBUILDER_RESPONSE_403 = 1;
	const LOGIN_REBUILDER_RESPONSE_404 = 2;
	const LOGIN_REBUILDER_RESPONSE_GO_HOME = 3;
	const LOGIN_REBUILDER_STATUS_IN_PREPARATION = 0;
	const LOGIN_REBUILDER_STATUS_WORKING = 1;
	const LOGIN_REBUILDER_LOGGING_OFF = 0;
	const LOGIN_REBUILDER_LOGGING_INVALID_REQUEST = 1;
	const LOGIN_REBUILDER_LOGGING_LOGIN = 2;
	const LOGIN_REBUILDER_LOGGING_ALL = 3;
	const LOGIN_REBUILDER_LOGGING_LIMIT = 100;
	const LOGIN_REBUILDER_NONCE_NAME = 'login-rebuilder-nonce';
	const LOGIN_REBUILDER_NONCE_LIFETIME = 1800;
	const LOGIN_REBUILDER_AJAX_NONCE_NAME = 'login-rebuilder-ajax-nonce';

	var $candidate = array( 'new-login.php', 'your-login.php', 'admin-login.php', 'wordpress-login.php', 'hidden-login.php' );
	var $properties;
	var $content = "<?php
define( 'LOGIN_REBUILDER_SIGNATURE', '%sig%' );
require_once './wp-login.php';
?>";
	var $root_url;		// trailing slash is removed
	var $root_path;	// trailing slash is removed
	var $use_site_option = true;

	function __construct() {
		register_activation_hook( __FILE__ , array( &$this , 'activation' ) );
		register_deactivation_hook( __FILE__ , array( &$this , 'deactivation' ) );

		$this->root_url = ( ( is_ssl() || force_ssl_login() )? "https://": "http://" ).$_SERVER["HTTP_HOST"];
		$this->root_path = $_SERVER['DOCUMENT_ROOT'];
		if ( empty( $this->root_path ) ) {
			list( $scheme, $content_uri ) = explode( "://".$_SERVER["HTTP_HOST"], get_option( 'siteurl' ) );
			$this->root_path = preg_replace( '/'.str_replace( array( '-', '.', '/' ), array( '\\-', '\\.', '[\\/\\\\]' ), $content_uri ).'/u', '', untrailingslashit( ABSPATH ) );
		}

		if ( is_multisite() ) {
			$details = get_blog_details();
			if ( $details->path != '/' )
				$this->use_site_option = false;
		}
		$this->_load_option();
		if ( $this->properties['status'] == self::LOGIN_REBUILDER_STATUS_WORKING &&
			( !@file_exists( $this->_login_file_path( $this->properties['page'] ) ) || !$this->_is_valid_new_login_file() ) ) {
			$this->properties['status'] = self::LOGIN_REBUILDER_STATUS_IN_PREPARATION;
		}

		add_action( 'admin_menu', array( &$this, 'admin_menu' ) );
		add_action( 'wp_ajax_login_rebuilder_try_save', array( &$this, 'try_save' ) );
		add_filter( 'plugin_row_meta', array( &$this, 'plugin_row_meta' ), 9, 2 );

		add_filter( 'site_url', array( &$this, 'site_url' ), 10, 4 );
		add_filter( 'network_site_url', array( &$this, 'network_site_url' ), 10, 3 );
		add_filter( 'wp_redirect', array( &$this, 'wp_redirect' ), 10, 2 );

		if ( $this->properties['status'] == self::LOGIN_REBUILDER_STATUS_WORKING ) {
			add_action( 'login_init', array( &$this, 'login_init' ) );
			if ( $this->properties['logging'] == self::LOGIN_REBUILDER_LOGGING_ALL ||
				$this->properties['logging'] == self::LOGIN_REBUILDER_LOGGING_LOGIN ) {
				add_filter( 'login_redirect', array( &$this, 'login_redirect' ), 10, 3 );
				add_action( 'wp_login_failed', array( &$this, 'wp_login_failed' ), 10, 1 );
			}
			add_filter( 'authenticate', array( &$this, 'role_authenticate' ), 99, 3 );
		}
	}

	function activation() {
		if ( get_option( LOGIN_REBUILDER_DB_VERSION_NAME ) != LOGIN_REBUILDER_DB_VERSION ) {
			update_option( LOGIN_REBUILDER_DB_VERSION_NAME, LOGIN_REBUILDER_DB_VERSION );
		}
	}
	function deactivation() {
		$this->_delete_private_nonce();
		delete_option( LOGIN_REBUILDER_DB_VERSION_NAME );
		delete_option( LOGIN_REBUILDER_LOGGING_NAME );

		$this->properties['status'] = self::LOGIN_REBUILDER_STATUS_IN_PREPARATION;
		$this->_save_option();
	}
	function login_init() {
		if ( isset( $_GET['action'] ) && $_GET['action'] == 'postpass' ) return;

		load_plugin_textdomain( LOGIN_REBUILDER_DOMAIN, false, plugin_basename( dirname( __FILE__ ) ).'/languages' );
		if ( preg_match( '/\/wp\-login\.php/u', $_SERVER['REQUEST_URI'] ) ||
			!( ( $this->_in_url( $_SERVER['REQUEST_URI'], $this->properties['page'] ) || $this->_in_url( $_SERVER['REQUEST_URI'], $this->properties['page_subscriber'] ) ) && defined( 'LOGIN_REBUILDER_SIGNATURE' ) && $this->properties['keyword'] == LOGIN_REBUILDER_SIGNATURE ) ) {
			if ( $this->properties['logging'] == self::LOGIN_REBUILDER_LOGGING_ALL ||
				 $this->properties['logging'] == self::LOGIN_REBUILDER_LOGGING_INVALID_REQUEST ) {
				$this->_logging(
					'invalid',
					array(
						'time'=>time(),
						'ip'=>$_SERVER['REMOTE_ADDR'],
						'uri'=>$_SERVER['REQUEST_URI'],
						'id'=>( empty( $_POST['log'] )? '': $_POST['log'] ),
						'pw'=>( empty( $_POST['pwd'] )? '': $_POST['pwd'] ) )
					);
			}
			switch ( $this->properties['response'] ) {
				case self::LOGIN_REBUILDER_RESPONSE_GO_HOME:
					wp_redirect( home_url() );
					break;
				case self::LOGIN_REBUILDER_RESPONSE_404:
					status_header( 404 );
					break;
				case self::LOGIN_REBUILDER_RESPONSE_403:
				default:
					status_header( 403 );
					break;
			}
			exit;
		}
	}
	function login_redirect( $redirect_to, $requested_redirect_to, $user ) {
		if ( !is_wp_error( $user ) && $this->properties['logging'] == self::LOGIN_REBUILDER_LOGGING_ALL ||
			$this->properties['logging'] == self::LOGIN_REBUILDER_LOGGING_LOGIN ) {
			$this->_logging(
				'login',
				array(
					'time'=>time(),
					'ip'=>$_SERVER['REMOTE_ADDR'],
					'uri'=>$_SERVER['REQUEST_URI'],
					'id'=>$user->ID )
				);
		}
		return $redirect_to;
	}
	function wp_login_failed( $username ) {
		$this->_logging(
			'invalid',
			array(
				'time'=>time(),
				'ip'=>$_SERVER['REMOTE_ADDR'],
				'uri'=>$_SERVER['REQUEST_URI'],
				'id'=>$username,
				'pw'=>( empty( $_POST['pwd'] )? '': $_POST['pwd'] ) )
			);
	}
	function site_url( $url, $path, $orig_scheme, $blog_id ) {
		if ( $this->properties['status'] == self::LOGIN_REBUILDER_STATUS_WORKING ) {
			$my_login_page = $this->properties['page'];
			if ( function_exists( 'wp_get_current_user' ) )
				$user = wp_get_current_user();
			else
				$user = (object)array( 'data'=>null );
			if ( $this->properties['page_subscriber'] != '' && ( $this->_in_url( $_SERVER['REQUEST_URI'], $this->properties['page_subscriber'] ) || ( isset( $user->data ) && $this->_is_secondary_login_user( $user ) ) ) )
				$my_login_page = $this->properties['page_subscriber'];

			if ( ( $path == 'wp-login.php' || preg_match( '/wp-login\.php\?action=\w+/', $path ) ) &&
				( is_user_logged_in() || $this->_in_url( $_SERVER['REQUEST_URI'], $my_login_page ) ) )
				$url = $this->_rewrite_login_url( 'wp-login.php', $my_login_page, $url );
		}
		return $url;
	}
	function network_site_url( $url, $path, $orig_scheme ) {
		return $this->site_url( $url, $path, $orig_scheme, 0 );
	}
	function wp_redirect( $location, $status ) {
		if ( $this->properties['status'] == self::LOGIN_REBUILDER_STATUS_WORKING ) {
			$my_login_page = $this->properties['page'];
			if ( function_exists( 'wp_get_current_user' ) )
				$user = wp_get_current_user();
			else
				$user = (object)array( 'data'=>null );
			if ( $this->properties['page_subscriber'] != '' && ( $this->_in_url( $_SERVER['REQUEST_URI'], $this->properties['page_subscriber'] ) || ( isset( $user->data ) && $this->_is_secondary_login_user( $user ) ) ) )
				$my_login_page = $this->properties['page_subscriber'];

			if ( $this->_in_url( $_SERVER['REQUEST_URI'], $my_login_page ) )
				$location = $this->_rewrite_login_url( 'wp-login.php', $my_login_page, $location );
			else if ( preg_match( '/reauth\=1$/u', $location ) ) {
				if ( is_user_admin() )
					$scheme = 'logged_in';
				else
					$scheme = apply_filters( 'auth_redirect_scheme', '' );
				if ( $cookie_elements = wp_parse_auth_cookie( '',  $scheme ) ) {
					extract( $cookie_elements, EXTR_OVERWRITE );
					$user = get_user_by( 'login', $username );
					if ( $user ) // timeout
						$location = $this->_rewrite_login_url( 'wp-login.php?', $my_login_page.'?', $location );
				}
			}
		}
		return $location;
	}
	/**
	 * Returns the URL that allows the users other than the administrator to log in to the site.
	 *
	 * @since 1.4.3
	 *
	 * @param string $redirect Path to redirect to on login. Optional. Default: ''.
	 * @param int $blog_id The id of the blog. Optional. Default: 0 (current blog).
	 * @return string Secondary log in URL.
	 */
	function wp_secondary_login_url( $redirect = '', $blog_id = 0 ) {
		$login_url = '';
		if ( is_multisite() && !empty( $blog_id ) ) {
			switch_to_blog( $blog_id );
			$site_url = get_option( 'siteurl' );
			$blog_properties = get_option( LOGIN_REBUILDER_PROPERTIES );
			restore_current_blog();
			if ( isset( $blog_properties['status'] ) && $blog_properties['status'] == self::LOGIN_REBUILDER_STATUS_WORKING && !empty( $blog_properties['page_subscriber'] ) ) {
				$login_url = set_url_scheme( $site_url, 'login' );
				$login_url .= '/' . ltrim( $blog_properties['page_subscriber'], '/' );
				if ( !empty( $redirect ) )
					$login_url = add_query_arg( 'redirect_to', urlencode( $redirect ), $login_url );
			}
		} else {
			if ( $this->properties['status'] == self::LOGIN_REBUILDER_STATUS_WORKING && !empty( $this->properties['page_subscriber'] ) ) {
				$login_url = $this->_login_file_url( $this->properties['page_subscriber'] );
				if ( !empty( $redirect ) )
					$login_url = add_query_arg( 'redirect_to', urlencode( $redirect ), $login_url );
			}
		}
		return $login_url;
	}
	function role_authenticate( $user, $username, $password ) {
		if ( !is_a( $user, 'WP_User' ) )
			return $user;

		if ( $this->properties['page_subscriber'] != '' &&
			$this->_is_valid_new_login_file( $this->properties['page_subscriber'] ) ) {
			// exists secondary login file
			if ( $this->_in_url( $_SERVER['REQUEST_URI'], $this->properties['page_subscriber'] ) ) {
				// secondary login file
				if ( !$this->_is_secondary_login_user( $user ) )
					return new WP_Error( 'authentication_failed', __( '<strong>ERROR</strong>: Invalid  username.', LOGIN_REBUILDER_DOMAIN ) );
			} else {
				// new login file
				if ( !$this->_is_not_secondary_login_user( $user ) )
					return new WP_Error( 'authentication_failed', __( '<strong>ERROR</strong>: Invalid  username.', LOGIN_REBUILDER_DOMAIN ) );
			}
		}
		return $user;
	}
	function plugin_row_meta( $links, $file ) {
		if ( $file == plugin_basename( dirname( __FILE__ ) ).'/'.basename( __FILE__ ) ) {
			$links[] = '<a href="options-general.php?page='.self::LOGIN_REBUILDER_PROPERTIES_NAME.'">'.__( 'Settings' ).'</a>';
		}
		return $links;
	}
	function admin_menu() {
		load_plugin_textdomain( LOGIN_REBUILDER_DOMAIN, false, plugin_basename( dirname( __FILE__ ) ).'/languages' );
		add_options_page( __( 'Login rebuilder', LOGIN_REBUILDER_DOMAIN ), __( 'Login rebuilder', LOGIN_REBUILDER_DOMAIN ), 'manage_options', self::LOGIN_REBUILDER_PROPERTIES_NAME, array( &$this, 'properties' ) );
	}
	function properties() {
		global $wp_version;
		if ( !current_user_can( 'manage_options' ) )
			return;	// Except an administrator

		$show_reload = false;
		$message = '';
		if ( isset( $_POST['properties'] ) ) {
			check_admin_referer( self::LOGIN_REBUILDER_PROPERTIES_NAME.$this->_nonce_suffix() );

			if ( $this->_verify_private_nonce() ) {
				$_POST['properties']['page'] = trim( $_POST['properties']['page'] );
				$_POST['properties']['page_subscriber'] = trim( $_POST['properties']['page_subscriber'] );
				if ( $this->_is_reserved_login_file( $_POST['properties']['page'] ) ) {
					$message = __( 'New login file is system file. Please change a path name.', LOGIN_REBUILDER_DOMAIN );
					$this->properties = $_POST['properties'];
				} else if ( $_POST['properties']['page'] != '' && $this->_case_subsite_invalid_login_file( $_POST['properties']['page'] ) ) {
					$message = __( 'The case of the sub-site, new login file is invalid. Please change a path name.', LOGIN_REBUILDER_DOMAIN );
					$this->properties = $_POST['properties'];
				} else if ( $_POST['properties']['page_subscriber'] != '' && ( $_POST['properties']['page_subscriber'] == $_POST['properties']['page'] || $this->_is_reserved_login_file( $_POST['properties']['page_subscriber'] ) ) ) {
					$message = __( 'Login file for subscriber is invalid. Please change a path name.', LOGIN_REBUILDER_DOMAIN );
					$this->properties = $_POST['properties'];
				} else if ( $_POST['properties']['page_subscriber'] != '' && $this->_case_subsite_invalid_login_file( $_POST['properties']['page_subscriber'] ) ) {
					$message = __( 'The case of the sub-site, login file for subscriber is invalid. Please change a path name.', LOGIN_REBUILDER_DOMAIN );
					$this->properties = $_POST['properties'];
				} else if ( $_POST['properties']['page_subscriber'] != '' && !( is_array( $_POST['properties']['secondary_roles'] ) && count( $_POST['properties']['secondary_roles'] ) > 0 ) ) {
					$message = __( 'User role to use the secondary login file is not selected. Please select at least one role.', LOGIN_REBUILDER_DOMAIN );
					$this->properties = $_POST['properties'];
				} else {
					$prev_status = $this->properties['status'];
					$prev_page = $this->properties['page'];
					if ( $this->properties['keyword'] != $_POST['properties']['keyword'] ||
						$this->properties['logging'] != $_POST['properties']['logging'] ||
						$this->properties['page'] != $_POST['properties']['page'] ||
						$this->properties['page_subscriber'] != $_POST['properties']['page_subscriber'] ||
						$this->properties['secondary_roles'] != $_POST['properties']['secondary_roles'] ) {
						$this->properties['logging'] = $_POST['properties']['logging'];
						$this->properties['secondary_roles'] = $_POST['properties']['secondary_roles'];

						if ( $this->properties['keyword'] != $_POST['properties']['keyword'] &&
							$this->use_site_option && is_multisite() ) {
							// subsite keyword update
							$sites = $this->_get_sites( get_current_blog_id() );
							if ( is_array( $sites ) ) 	foreach ( $sites as $site ) {
								switch_to_blog( $site->blog_id );
								$properties = get_option( LOGIN_REBUILDER_PROPERTIES, '' );
								$properties['keyword'] = $_POST['properties']['keyword'];
								if ( isset( $properties['page'] ) && !empty( $properties['page'] ) ) {
									$login_path = $this->_login_file_path( $properties['page'] );
									if ( @file_exists( $login_path ) )
										$updated = $this->_update_login_file_keyword( $login_path, $properties['keyword'] );
								}
								if ( isset( $properties['page_subscriber'] ) && !empty( $properties['page_subscriber'] ) ) {
									$login_path = $this->_login_file_path( $properties['page_subscriber'] );
									if ( @file_exists( $login_path ) )
										$updated = $this->_update_login_file_keyword( $login_path, $properties['keyword'] );
								}
								update_option( LOGIN_REBUILDER_PROPERTIES, $properties );
								restore_current_blog();
							}
						}
						$this->properties['keyword'] = $_POST['properties']['keyword'];

						if ( $this->properties['page'] != $_POST['properties']['page'] ) {
							$login_path = $this->_login_file_path( $this->properties['page'] );
							if (@file_exists( $login_path ) && $this->_is_deletable( $login_path ) ) @unlink( $login_path );
							$this->properties['page'] = $_POST['properties']['page'];
						}
						if ( $this->properties['page_subscriber'] != $_POST['properties']['page_subscriber'] ) {
							$login_path = $this->_login_file_path( $this->properties['page_subscriber'] );
							if (@file_exists( $login_path ) && $this->_is_deletable( $login_path ) ) @unlink( $login_path );
							$this->properties['page_subscriber'] = $_POST['properties']['page_subscriber'];
						}

						$result = $this->try_save( array_merge( $_POST['properties'], array( 'mode'=>1 ) ) );
						if ( $result['update'] ) {
							$this->properties['status'] = intval( $_POST['properties']['status'] );
						} else if ( !empty( $this->properties['page'] ) && ( !@file_exists( $this->_login_file_path( $this->properties['page'] ) ) || !$this->_is_valid_new_login_file() ) ) {
							$message .= __( "However, failed to write a new login file to disk.\nPlease change into the enabled writing of a disk or upload manually.", LOGIN_REBUILDER_DOMAIN );
							$this->properties['status'] = self::LOGIN_REBUILDER_STATUS_IN_PREPARATION;
						}
						$subscriber = $_POST['properties'];
						$subscriber['page'] = $subscriber['page_subscriber'];
						$subscriber['content'] = $subscriber['content_subscriber'];
						$result = $this->try_save( array_merge( $subscriber, array( 'mode'=>1 ) ) );
						$message = __( 'Options saved.', LOGIN_REBUILDER_DOMAIN ).' ';
						if ( !$result['update'] && !empty( $this->properties['page_subscriber'] ) && ( !@file_exists( $this->_login_file_path( $this->properties['page_subscriber'] ) ) || !$this->_is_valid_new_login_file( $this->properties['page_subscriber'] ) ) ) {
							$message .= __( "However, failed to write a login file for subscriber to disk.\nPlease change into the enabled writing of a disk or upload manually.", LOGIN_REBUILDER_DOMAIN );
						}
					} else if ( $this->properties['status'] != intval( $_POST['properties']['status'] ) ) {
						$message = __( 'Options saved.', LOGIN_REBUILDER_DOMAIN ).' ';
						$this->properties['status'] = intval( $_POST['properties']['status'] );
						if ( $this->properties['status'] == self::LOGIN_REBUILDER_STATUS_WORKING ) {
							if ( !@file_exists( $this->_login_file_path( $this->properties['page'] ) ) ) {
								$result = $this->try_save( array_merge( $_POST['properties'], array( 'mode'=>1 ) ) );
								if ( !$result['update'] ) {
									$message .= __( "However, a new login file was not found.", LOGIN_REBUILDER_DOMAIN );
									$this->properties['status'] = self::LOGIN_REBUILDER_STATUS_IN_PREPARATION;
								}
							} else if ( !$this->_is_valid_new_login_file() ) {
								$message .= __( "However, the contents of a new login file are not in agreement.", LOGIN_REBUILDER_DOMAIN );
								$this->properties['status'] = self::LOGIN_REBUILDER_STATUS_IN_PREPARATION;
							}
						}
					}
					$this->properties['response'] = intval( $_POST['properties']['response'] );
					$this->_save_option();

					// rewrite logout url
					if ( $this->properties['status'] == self::LOGIN_REBUILDER_STATUS_IN_PREPARATION ) {
						$logout_from = $this->_login_file_url( ( $prev_status == self::LOGIN_REBUILDER_STATUS_WORKING )? $prev_page: $this->properties['page'] );
						$logout_to = site_url().'/wp-login.php';
					} else {
						if ( $prev_status == self::LOGIN_REBUILDER_STATUS_WORKING )
							$logout_from = $this->_login_file_url( $prev_page );
						else
							$logout_from = site_url().'/wp-login.php';
						$logout_to = $this->_login_file_url( $this->properties['page'] );
					}
				}
				$this->_clear_private_nonce();
			} else {
				$message .= __( "Expiration date of this page has expired.", LOGIN_REBUILDER_DOMAIN );
				$show_reload = true;
			}
		}
		$logging = get_option( LOGIN_REBUILDER_LOGGING_NAME, array( 'invalid'=>array(), 'login'=>array() ) );
?>
<div id="login-rebuilder-properties" class="wrap">
<div id="icon-options-general" class="icon32"><br /></div>
<h2><?php _e( 'Login rebuilder', LOGIN_REBUILDER_DOMAIN ); ?> <?php _e( 'Settings' ) ;?></h2>
<?php if ( $message != '' ) { global $wp_version; ?>
<?php if ( version_compare( $wp_version, '3.5', '>=' ) ) { ?>
<div id="setting-error-settings_updated" class="updated settings-error"><p><strong><?php echo $message; ?></strong></p></div>
<?php } else { ?>
<div id="message" class="update fade"><p><?php echo $message; ?></p></div>
<?php } } ?>

<div id="login-rebuilder-widget" class="metabox-holder">
<p><?php _e( 'Notice: This page is valid for 30 minutes.', LOGIN_REBUILDER_DOMAIN ); ?></p>
<?php if ( $show_reload ) { ?>
<p><a href="<?php echo str_replace( '%07E', '~', $_SERVER['REQUEST_URI'] ); ?>" class="button"><?php _e( 'Reload now.', LOGIN_REBUILDER_DOMAIN ); ?></a></p>
<?php } else { ?>
<form method="post" action="<?php echo str_replace( '%07E', '~', $_SERVER['REQUEST_URI'] ); ?>">
<table summary="login rebuilder properties" class="form-table">
<tr valign="top">
<th><?php _e( 'Response to an invalid request :', LOGIN_REBUILDER_DOMAIN ); ?></th>
<td>
<input type="radio" name="properties[response]" id="properties_response_1" value="<?php _e( self::LOGIN_REBUILDER_RESPONSE_403 ); ?>" <?php checked( $this->properties['response'] == self::LOGIN_REBUILDER_RESPONSE_403 ); ?> /><label for="properties_response_1">&nbsp;<span><?php _e( '403 status', LOGIN_REBUILDER_DOMAIN ); ?></span></label><br />
<input type="radio" name="properties[response]" id="properties_response_2" value="<?php _e( self::LOGIN_REBUILDER_RESPONSE_404 ); ?>" <?php checked( $this->properties['response'] == self::LOGIN_REBUILDER_RESPONSE_404 ); ?> /><label for="properties_response_2">&nbsp;<span><?php _e( '404 status', LOGIN_REBUILDER_DOMAIN ); ?></span></label><br />
<input type="radio" name="properties[response]" id="properties_response_3" value="<?php _e( self::LOGIN_REBUILDER_RESPONSE_GO_HOME ); ?>" <?php checked( $this->properties['response'] == self::LOGIN_REBUILDER_RESPONSE_GO_HOME ); ?> /><label for="properties_response_3">&nbsp;<span><?php _e( 'redirect to a site url', LOGIN_REBUILDER_DOMAIN ); echo ' ( '.home_url().' )'; ?></span></label><br />
</td>
</tr>

<tr valign="top">
<th><label for="properties_keyword"><?php _e( 'Login file keyword :', LOGIN_REBUILDER_DOMAIN ); ?></label></th>
<td><input type="text" name="properties[keyword]" id="properties_keyword" value="<?php _e( $this->properties['keyword'] ); ?>" class="regular-text code" <?php if ( !$this->use_site_option ) echo 'readonly="readonly"'; ?> /></td>
</tr>

<tr valign="top">
<th><label for="properties_page"><?php _e( 'New login file :', LOGIN_REBUILDER_DOMAIN ); ?></label></th>
<td><input type="text" name="properties[page]" id="properties_page" value="<?php _e( $this->properties['page'] ); ?>" class="regular-text code" />
<p style="padding: 0 0 0 1em; font-size: 92%; color: #666666;">Path: <span id="path_login" class="path">&nbsp;</span> <span id="writable" class="writable">&nbsp;</span><br />
URL: <span id="url_login" class="url">&nbsp;</span><br />
<textarea name="properties[content]" id="login_page_content" rows="4" style="font-family:monospace; width: 96%;" readonly="readonly" class="content"></textarea><input type="hidden" id="content_template" value="<?php echo $this->content; ?>" /></p>
</td>
</tr>
<tr valign="top">
<th><label for="properties_page"><?php _e( 'Secondary login file:', LOGIN_REBUILDER_DOMAIN ); ?></label></th>
<td><input type="text" name="properties[page_subscriber]" id="properties_page_subscriber" value="<?php _e( $this->properties['page_subscriber'] ); ?>" class="regular-text code" />
<p style="padding: 0 0 0 1em; font-size: 92%; color: #666666;">Path: <span id="path_subscriber" class="path">&nbsp;</span> <span id="writable_subscriber" class="writable">&nbsp;</span><br />
URL: <span id="url_subscriber" class="url">&nbsp;</span><br />
<textarea name="properties[content_subscriber]" id="subscriber_page_content" rows="4" style="font-family:monospace; width: 96%;" readonly="readonly" class="content"></textarea><br />
<?php _e( 'Role' ); ?>: <?php
$roles = get_editable_roles();
unset( $roles['administrator'] );
foreach ( array_reverse( $roles ) as $role => $details ) {
	$name = translate_user_role($details['name'] );
	$checked = in_array( $role, (array)$this->properties['secondary_roles'] )? ' checked="checked"': '';
	$role = esc_attr($role);
	echo '<input type="checkbox" name="properties[secondary_roles][]" id="secondary_'.$role.'" value="'.$role.'" '.$checked.'/><label for="secondary_'.$role.'">'.$name.'</label>&nbsp;&nbsp;&nbsp;';
}
?>
</p>
</td>
</tr>

<tr valign="top">
<th><?php _e( 'Status :', LOGIN_REBUILDER_DOMAIN ); ?></th>
<td>
<input type="radio" name="properties[status]" id="properties_status_0" value="<?php _e( self::LOGIN_REBUILDER_STATUS_IN_PREPARATION ); ?>" <?php checked( $this->properties['status'] == self::LOGIN_REBUILDER_STATUS_IN_PREPARATION ); ?> /><label for="properties_status_0">&nbsp;<span><?php _e( 'in preparation', LOGIN_REBUILDER_DOMAIN ); ?></span></label><br />
<input type="radio" name="properties[status]" id="properties_status_1" value="<?php _e( self::LOGIN_REBUILDER_STATUS_WORKING ); ?>" <?php checked( $this->properties['status'] == self::LOGIN_REBUILDER_STATUS_WORKING ); ?> /><label for="properties_status_1">&nbsp;<span><?php _e( 'working', LOGIN_REBUILDER_DOMAIN ); ?></span></label><br />
</td>
</tr>

<tr valign="top">
<th><?php _e( 'Logging :', LOGIN_REBUILDER_DOMAIN ); ?></th>
<td>
<input type="radio" name="properties[logging]" id="properties_logging_0" value="<?php _e( self::LOGIN_REBUILDER_LOGGING_OFF ); ?>" <?php checked( $this->properties['logging'] == self::LOGIN_REBUILDER_LOGGING_OFF ); ?> /><label for="properties_logging_0">&nbsp;<span><?php _e( 'off', LOGIN_REBUILDER_DOMAIN ); ?></span></label><br />
<input type="radio" name="properties[logging]" id="properties_logging_1" value="<?php _e( self::LOGIN_REBUILDER_LOGGING_INVALID_REQUEST ); ?>" <?php checked( $this->properties['logging'] == self::LOGIN_REBUILDER_LOGGING_INVALID_REQUEST ); ?> /><label for="properties_logging_1">&nbsp;<span><?php _e( 'invalid request only', LOGIN_REBUILDER_DOMAIN ); ?></span></label><br />
<input type="radio" name="properties[logging]" id="properties_logging_2" value="<?php _e( self::LOGIN_REBUILDER_LOGGING_LOGIN ); ?>" <?php checked( $this->properties['logging'] == self::LOGIN_REBUILDER_LOGGING_LOGIN ); ?> /><label for="properties_logging_2">&nbsp;<span><?php _e( 'login only', LOGIN_REBUILDER_DOMAIN ); ?></span></label><br />
<input type="radio" name="properties[logging]" id="properties_logging_3" value="<?php _e( self::LOGIN_REBUILDER_LOGGING_ALL ); ?>" <?php checked( $this->properties['logging'] == self::LOGIN_REBUILDER_LOGGING_ALL ); ?> /><label for="properties_logging_3">&nbsp;<span><?php _e( 'all', LOGIN_REBUILDER_DOMAIN ); ?></span></label><br />
</td>
</tr>

<tr valign="top">
<td colspan="2">
<input type="submit" name="submit" value="<?php esc_attr_e( 'Save Changes' ); ?>" class="button-primary" />
<?php if ( count( $logging['invalid'] ) > 0 || count( $logging['login'] ) > 0 ) { ?>
<input type="button" name="view-log" id="view-log" value="<?php esc_attr_e( 'View log', LOGIN_REBUILDER_DOMAIN ); ?>" class="button" />
<?php } wp_nonce_field( self::LOGIN_REBUILDER_PROPERTIES_NAME.$this->_nonce_suffix() ); $this->_private_nonce_field(); ?>
</td>
</tr>
</table>
</form>

<?php
if ( count( $logging['invalid'] ) > 0 || count( $logging['login'] ) > 0 ) {
	$gmt_offset = get_option( 'gmt_offset' );
	$date_time_format = get_option( 'date_format' ).' '.get_option( 'time_format' );
	$log_box_style = 'max-height: 12.75em; overflow: auto; font-family: monospace; background-color: #FFFFFF; border: 1px solid #666666; padding: .25em;';
?>
<div id="log-content" style="display: none;">
<table summary="login rebuilder logs" class="form-table">
<tbody>
<td style="vertical-align: top;"><h3><?php _e( 'Log of invalid request', LOGIN_REBUILDER_DOMAIN ); ?></h3>
<div id="invalid-log" style="<?php esc_attr_e( $log_box_style ); ?>">
<?php
if ( isset( $logging['invalid'] ) && is_array( $logging['invalid'] ) ) {
	krsort( $logging['invalid'] );
	foreach ( $logging['invalid'] as $log ) { ?>
<?php esc_html_e( date_i18n( $date_time_format, $log['time']+$gmt_offset*3600 ) ); ?> - <?php esc_html_e( $log['id'].'('.$log['ip'].')' ); ?><br />
<?php } ?>
</div></td>
<td style="vertical-align: top;"><h3><?php _e( 'Log of login', LOGIN_REBUILDER_DOMAIN ); ?></h3>
<div id="login-log"  style="<?php esc_attr_e( $log_box_style ); ?>">
<?php
krsort( $logging['login'] );
foreach ( $logging['login'] as $log ) {
	$_user = get_user_by( 'id', $log['id'] ); ?>
<?php esc_html_e( date_i18n( $date_time_format, $log['time']+$gmt_offset*3600 ) ); ?> - <?php esc_html_e( $_user->user_nicename.'('.$log['ip'].')' ); ?><br />
<?php } ?>
</div></td>
</tbody>
</table>
</div>
<?php } } } ?>
</div>
</div>
<script type="text/javascript">
( function($) {
<?php if ( isset( $logout_to ) && $logout_from != $logout_to ) { ?>
	$( 'a' ).each( function () {
		$( this ).attr( 'href', $( this ).attr( 'href' ).replace( '<?php echo $logout_from; ?>', '<?php echo $logout_to; ?>' ) );
	} );
<?php } ?>
	$( '#properties_keyword' ).blur( function () {
		if ( $( '#properties_page' ).val() != '' && $.trim( $( '#login_page_content' ).val() ) != '' ) {
			$( '#login_page_content' ).val( $( '#login_page_content' ).val().replace( /'LOGIN_REBUILDER_SIGNATURE', '[0-9a-zA-Z]+'/, "'LOGIN_REBUILDER_SIGNATURE', '"+$( this ).val()+"'" ) );
		}
		if ( $( '#properties_page_subscriber' ).val() != '' && $.trim( $( '#subscriber_page_content' ).val() ) != '' ) {
			$( '#subscriber_page_content' ).val( $( '#subscriber_page_content' ).val().replace( /'LOGIN_REBUILDER_SIGNATURE', '[0-9a-zA-Z]+'/, "'LOGIN_REBUILDER_SIGNATURE', '"+$( this ).val()+"'" ) );
		}
	} );
	$( '#properties_page, #properties_page_subscriber' ).blur( function () {
		var page_elm = $( this );
		var uri = $.trim( $( this ).val() );
		var valid_uri = ( uri != '' );
<?php if ( !$this->use_site_option ) { ?>
		if ( uri != '' && uri.indexOf( '/' ) != -1 ) {
			alert( "<?php _e( "The case of the sub-site, you can not contain '/' in the path name. Please change a path name.", LOGIN_REBUILDER_DOMAIN ); ?>" );
			$( this ).next().find( 'span.path,span.writable,span.url' ).text( '' );
			$( this ).focus();
			valid_uri = false;
		}
<?php } ?>
		if ( valid_uri ) {
			$( 'input[name=submit]' ).attr( 'disabled', 'disabled' );
			$.post( '<?php echo admin_url( 'admin-ajax.php' ); ?>',
				{ action: 'login_rebuilder_try_save', mode: 0, page: uri, _ajax_nonce: '<?php echo wp_create_nonce( self::LOGIN_REBUILDER_AJAX_NONCE_NAME.$this->_nonce_suffix() ); ?>' },
				function( response ) {
					page_elm.next().each( function () {
						$( this ).find( 'span.path' ).text( response.data.path );
						if ( response.data.exists )
							$( this ).find( 'span.url' ).html( '<a href="'+response.data.url+'" target="_blank">'+response.data.url+'</a>' );
						else
							$( this ).find( 'span.url' ).text( response.data.url );
						if ( response.data.exists )
							out_exists = '<?php _e( 'File exists, ', LOGIN_REBUILDER_DOMAIN ); ?>';
						else
							out_exists = '<?php _e( 'File not found, ', LOGIN_REBUILDER_DOMAIN ); ?>';
						if ( response.data.writable ) {
							out_writing = '<?php _e( 'Writing is possible', LOGIN_REBUILDER_DOMAIN ); ?>';
							out_color = 'blue';
						} else {
							out_writing = '<?php _e( 'Writing is impossible', LOGIN_REBUILDER_DOMAIN ); ?>';
							out_color = 'orange';
						}
						$( this ).find( 'span.writable' ).text( '['+out_exists+out_writing+']' ).css( 'color', out_color );
						$( this ).find( 'textarea.content' ).text( response.data.content.replace( '%sig%', $( '#properties_keyword' ).val() ) );
					} );
				}, 'json' ).always( function() {
					$( 'input[name=submit]' ).removeAttr( 'disabled' );
				} );
		}
	} );
	$( '#properties_page' ).blur();
	$( '#properties_page_subscriber' ).blur();
	$( '#view-log' ).click( function () { $( '#log-content' ).fadeToggle(); } );
} )( jQuery );
</script>
<?php
	}
	function try_save( $param = null ) {
		if ( is_array( $param ) && count( $param ) > 0 )
			extract( $param );
		else {
			check_ajax_referer( self::LOGIN_REBUILDER_AJAX_NONCE_NAME.$this->_nonce_suffix() );
			extract( $_POST );
		}
		if ( !isset( $mode ) || !isset( $page ) ) {
			if ( is_null( $param ) || $param == '' )
				exit;
			else
				return null;
		}
		$data = array(
				'request'=>$page,
				'path'=>$this->_login_file_path( $page ),
				'url'=>$this->_login_file_url( $page ),
				'exists'=>false,
				'writable'=>false,
				'update'=>false,
				'content'=>$this->_rewrite_login_content( $page, $this->content ) );
		if ( defined( 'DOING_AJAX' ) && $mode == 1 ) {
			// invalid access
			$mode = 0;
			$data['path'] = '';
			$data['content'] = '';
		}
		// exists
		if ( @file_exists( $data['path'] ) )
			$data['exists'] = true;
		// writable
		if ( ( $fp = @fopen( $data['path'] , 'a' ) ) !== false ) {
			@fclose( $fp );
			if ( !$data['exists'] )
				@unlink( $data['path'] );
			$data['writable'] = true;
		}
		if ( $mode == 1 ) {
			// update
			if ( ( $fp = @fopen( $data['path'], 'w' ) ) !== false ) {
				@fwrite( $fp, stripslashes( $content ) );
				@fclose( $fp );
				@chmod( $data['path'], 0644 );
				$data['update'] = true;
			}
		}
		if ( is_null( $param ) || $param == '' ) {
			if ( function_exists( 'wp_send_json_success' ) )
				wp_send_json_success( $data );
			else {
				@header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
				echo json_encode( array( 'success'=>true, 'data'=>$data ) );
				exit;
			}
		} else
			return $data;
	}

	// private properties functions
	private function _default_properties() {
		$default_properties = array( 'status'=>self::LOGIN_REBUILDER_STATUS_IN_PREPARATION,
				'logging'=>self::LOGIN_REBUILDER_LOGGING_OFF,
				'page'=>$this->candidate[ array_rand( $this->candidate ) ],
				'page_subscriber'=>'',
				'secondary_roles'=>array( 'subscriber' ),
				'keyword'=>$this->_generate_keyword(),
				'response'=>self::LOGIN_REBUILDER_RESPONSE_403 );
		return $default_properties;
	}
	private function _load_option() {
		$default_properties = $this->_default_properties();
		$this->properties = get_site_option( LOGIN_REBUILDER_PROPERTIES, $default_properties );
		if ( !isset( $this->properties['secondary_roles'] ) )
			$this->properties['secondary_roles'] = array( 'subscriber' );
		if ( !$this->use_site_option ) {
			$_properties = get_option( LOGIN_REBUILDER_PROPERTIES, $default_properties );
			if ( is_numeric( $_properties ) )
				$_properties = array_merge( $this->properties, array( 'status'=>$_properties ) );
			$this->properties = $_properties;
		}
		if ( !isset( $this->properties['logging'] ) )
			$this->properties['logging'] = self::LOGIN_REBUILDER_LOGGING_OFF;
	}
	private function _save_option() {
		if ( $this->use_site_option )
			update_site_option( LOGIN_REBUILDER_PROPERTIES, $this->properties );
		else
			update_option( LOGIN_REBUILDER_PROPERTIES, $this->properties );
	}
	private function _raw_generate_keyword() {
		return substr( str_shuffle( 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789abcdefghijklmnopqrstuvwxyz0123456789' ), rand( 0, 60 ), 8 );
	}
	private function _generate_keyword() {
		if ( $this->use_site_option )
			return $this->_raw_generate_keyword();
		else {
			// sub site
			$properties = get_site_option( LOGIN_REBUILDER_PROPERTIES, '' );
			if ( empty( $properties ) ) {
				$properties = $this->_default_properties();
				$properties['keyword'] = $this->_raw_generate_keyword();
				update_site_option( LOGIN_REBUILDER_PROPERTIES, $properties );
			}
			return $properties['keyword'];
		}
	}

	// private auth functions
	private function _logging( $type, $log ) {
		if ( is_array( $log ) ) {
			$logging = get_option( LOGIN_REBUILDER_LOGGING_NAME, '' );
			if ( empty( $logging ) ) {
				$logging = array( 'invalid'=>array(), 'login'=>array() );
				add_option( LOGIN_REBUILDER_LOGGING_NAME, $logging, '', 'no' );
			}
			if ( count( $logging[$type] ) >= self::LOGIN_REBUILDER_LOGGING_LIMIT )
				array_shift( $logging[$type] );
			if ( !is_array( $logging ) )
				$logging = array();
			$logging[$type][] = $log;
			update_option( LOGIN_REBUILDER_LOGGING_NAME, $logging );
		}
	}
	private function _is_secondary_login_user( $user ) {
		if ( is_array( $user->roles ) ) foreach ( $user->roles as $role ) {
			if ( in_array( $role, (array)$this->properties['secondary_roles'] ) ) return true;
		}
		return false;
	}
	private function _is_not_secondary_login_user( $user ) {
		if ( is_array( $user->roles ) ) {
			global $wp_roles;
			$not_secondary_roles = array_diff( array_keys( $wp_roles->roles ), (array)$this->properties['secondary_roles'] );
			foreach ( $user->roles as $role ) {
				if ( in_array( $role, $not_secondary_roles ) ) return true;
			}
		}
		return false;
	}

	// private login file functions
	private function _rewrite_login_url( $wp_login, $page, $url ) {
		if ( strpos( $url, $wp_login ) !== false ) {
			$new_url = $this->_login_file_url( $page );
			if ( ( $pos = strpos( $url, '?' ) ) !== false )
				$new_url .= substr( $url, $pos );
			return $new_url;
		} else
			return $url;
	}
	private function _in_url( $url, $page ) {
		return ( strpos( $url, '/'.ltrim( $page , '/' ) ) !== false );
	}
	private function _login_file_url( $page ) {
		if ( strpos( $page, '/' ) !== false )
			return $this->root_url.'/'.ltrim( $page , '/' );
		else
			return site_url( $page );
	}
	private function _login_file_path( $page ) {
		if ( strpos( $page, '/' ) !== false )
			$path = $this->root_path.'/'.ltrim( $page , '/' );
		else
			$path = ABSPATH.$page;
		if ( function_exists( 'wp_normalize_path' ) )
			$path = wp_normalize_path( $path );
		return $path;
	}
	private function _rewrite_login_content( $page, $content ) {
		if ( strpos( $page, '/' ) !== false ) {
			$wp_login = '';
			if ( defined( 'ABSPATH' ) && file_exists( ABSPATH.'wp-login.php' ) )
				$wp_login = ABSPATH.'wp-login.php';
			else if ( defined( 'WPINC' ) && file_exists( ABSPATH.WPINC.'/wp-login.php' ) )
				$wp_login = ABSPATH.WPINC.'/wp-login.php';
			if ( !empty( $wp_login ) ) {
				if ( function_exists( 'wp_normalize_path' ) )
					$wp_login = wp_normalize_path( $wp_login );
				$content = str_replace( './wp-login.php', $wp_login, $content );
			}
		}
		return $content;
	}
	private function _get_sites( $ignore_ids = null ) {
		if ( is_multisite() ) {
			global $wpdb;
			$query = "SELECT * FROM $wpdb->blogs WHERE 1=1";
			if ( !empty( $ignore_ids ) )
				$query .= " AND blog_id NOT IN (".implode( ',', (array)$ignore_ids ).")";
			return $wpdb->get_results( $query );
		} else
			return array();
	}
	private function _is_deletable( $path ) {
		if ( $this->_is_reserved_login_file( basename( $path ) ) ) return false;
		if ( !$this->use_site_option ) {
			$sites = $this->_get_sites( get_current_blog_id() );
			if ( is_array( $sites ) ) 	foreach ( $sites as $site ) {
				switch_to_blog( $site->blog_id );
				$properties = get_option( LOGIN_REBUILDER_PROPERTIES, '' );
				if ( isset( $properties['page'] ) && !empty( $properties['page'] ) ) {
					$login_path = $this->_login_file_path( $properties['page'] );
					if ( $login_path == $path ) return false;
				}
				if ( isset( $properties['page_subscriber'] ) && !empty( $properties['page_subscriber'] ) ) {
					$login_path = $this->_login_file_path( $properties['page_subscriber'] );
					if ( $login_path == $path ) return false;
				}
				restore_current_blog();
			}
		}
		return true;
	}
	private function _update_login_file_keyword( $login_path, $new_keyword ) {
		$updated = false;
		$content = @file_get_contents( $login_path );
		if ( $content !== false && ( $fp = @fopen( $login_path, 'w' ) ) !== false ) {
			$content = preg_replace( "/'LOGIN_REBUILDER_SIGNATURE', '[0-9a-zA-Z]+'/u", "'LOGIN_REBUILDER_SIGNATURE', '{$new_keyword}'", $content );
			@fwrite( $fp, $content );
			@fclose( $fp );
			$updated = true;
		}
		return $updated;
	}
	private function _is_reserved_login_file( $filename ) {
		return in_array( $filename,
				array( 'index.php', 'wp-activate.php', 'wp-app.php', 'wp-atom.php', 'wp-blog-header.php',
					'wp-comments-post.php', 'wp-commentsrss2.php', 'wp-config.php', 'wp-config-sample.php', 'wp-cron.php',
					'wp-feed.php', 'wp-links-opml.php', 'wp-load.php', 'wp-login.php', 'wp-mail.php',
					'wp-pass.php', 'wp-rdf.php', 'wp-register.php', 'wp-rss.php', 'wp-rss2.php',
					'wp-settings.php', 'wp-signup.php', 'wp-trackback.php', 'xmlrpc.php' ) );
	}
	private function _is_valid_new_login_file( $filename = null ) {
		if ( is_null( $filename ) )
			$filename = $this->properties['page'];
		return
			preg_replace( "/\r\n|\r|\n/", "\r", $this->_rewrite_login_content( $filename, str_replace( '%sig%', $this->properties['keyword'], $this->content ) ) ) ==
			preg_replace( "/\r\n|\r|\n/", "\r", trim( @file_get_contents( $this->_login_file_path( $filename ) ) ) );
	}
	private function _case_subsite_invalid_login_file( $filename ) {
		if ( empty( $finename ) ) return false;
		if ( $this->use_site_option ) return false;
		return ( strpos( $filename, '/' ) !== false );
	}

	// private nonce functions
	private function _nonce_suffix() {
		return date_i18n( 'His TO', filemtime( __FILE__ ) );
	}
	private function _init_private_nonce() {
		if ( get_option( self::LOGIN_REBUILDER_NONCE_NAME, '' ) == '' ) {
			add_option( self::LOGIN_REBUILDER_NONCE_NAME,
					array( get_current_user_id()=>array( 'nonce'=>'', 'access'=>time() ) ),
					'', 'no' );
		}
	}
	private function _clear_private_nonce() {
		$private_nonce = get_option( self::LOGIN_REBUILDER_NONCE_NAME, '' );
		if ( isset( $private_nonce['nonce'] ) ) unset( $private_nonce['nonce'] );
		if ( isset( $private_nonce['access'] ) ) unset( $private_nonce['access'] );
		$user_id = get_current_user_id();
		if ( isset( $private_nonce[$user_id] ) ) {
			unset( $private_nonce[$user_id] );
			update_option( self::LOGIN_REBUILDER_NONCE_NAME, $private_nonce );
		}
	}
	private function _delete_private_nonce() {
		delete_option( self::LOGIN_REBUILDER_NONCE_NAME );
	}
	private function _private_nonce_field( $field_name = self::LOGIN_REBUILDER_NONCE_NAME, $action = self::LOGIN_REBUILDER_NONCE_NAME ) {
		$field_name = esc_attr( $field_name );
		$now = time();
		$user_id = get_current_user_id();

		$this->_init_private_nonce();
		$private_nonce = get_option( self::LOGIN_REBUILDER_NONCE_NAME, '' );
		if ( isset( $private_nonce[$user_id]['nonce'] ) && $private_nonce[$user_id]['nonce'] != '' &&
			( $now-$private_nonce[$user_id]['access'] ) < self::LOGIN_REBUILDER_NONCE_LIFETIME/10*9 ) {
			// Do not update the nonce value.
			$nonce = $private_nonce[$user_id]['nonce'];
		} else {
			$nonce = wp_create_nonce( $action.($now%10000).$this->_nonce_suffix() );
			$private_nonce[$user_id] = array( 'nonce'=>$nonce, 'access'=>$now );
			update_option( self::LOGIN_REBUILDER_NONCE_NAME, $private_nonce );
		}
		$nonce_field = '<input type="hidden" id="'.$field_name.'" name="'.$field_name.'" value="'.$nonce.'" />';
		echo $nonce_field;
	}
	private function _verify_private_nonce( $field_name = self::LOGIN_REBUILDER_NONCE_NAME, $lifetime = self::LOGIN_REBUILDER_NONCE_LIFETIME ) {
		$user_id = get_current_user_id();
		$valid = false;
		$field_name = esc_attr( $field_name );
		$now = time();
		$private_nonce = get_option( self::LOGIN_REBUILDER_NONCE_NAME, '' );
		if ( isset( $private_nonce[$user_id]['nonce'] ) && isset( $private_nonce[$user_id]['access'] ) &&
			isset( $_REQUEST[$field_name] ) && $_REQUEST[$field_name] == $private_nonce[$user_id]['nonce'] &&
			( $now-$private_nonce[$user_id]['access'] ) > 0 && ( $now-$private_nonce[$user_id]['access'] ) <= $lifetime ) {
			$valid = true;
		}
		return $valid;
	}
}
?>