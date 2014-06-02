<?php
/*
Plugin Name: Login rebuilder
Plugin URI: http://elearn.jp/wpman/column/login-rebuilder.html
Description: This plug-in will make a new login page for your site.
Author: tmatsuur
Version: 1.2.2
Author URI: http://12net.jp/
*/

/*
 Copyright (C) 2013-2014 tmatsuur (Email: takenori dot matsuura at 12net dot jp)
This program is licensed under the GNU GPL Version 2.
*/

define( 'LOGIN_REBUILDER_DOMAIN', 'login-rebuilder' );
define( 'LOGIN_REBUILDER_DB_VERSION_NAME', 'login-rebuilder-db-version' );
define( 'LOGIN_REBUILDER_DB_VERSION', '1.2.1' );
define( 'LOGIN_REBUILDER_PROPERTIES', 'login-rebuilder' );
define( 'LOGIN_REBUILDER_LOGGING_NAME', 'login-rebuilder-logging' );

$plugin_login_rebuilder = new login_rebuilder();

class login_rebuilder {
	var $properties;
	var $content = "<?php
define( 'LOGIN_REBUILDER_SIGNATURE', '%sig%' );
require_once './wp-login.php';
?>";
	const LOGIN_REBUILDER_PROPERTIES_NAME = 'login-rebuilder-properties';
	const LOGIN_REBUILDER_NONCE_NAME = 'login-rebuilder-nonce';
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

	function __construct() {
		load_plugin_textdomain( LOGIN_REBUILDER_DOMAIN, false, plugin_basename( dirname( __FILE__ ) ).'/languages' );
		register_activation_hook( __FILE__ , array( &$this , 'init' ) );
		register_deactivation_hook( __FILE__ , array( &$this , 'deactivation' ) );
		$candidate = array( 'new-login.php', 'your-login.php', 'admin-login.php', 'wordpress-login.php', 'hidden-login.php' );
		$this->properties = get_site_option( LOGIN_REBUILDER_PROPERTIES,
			array( 'status'=>self::LOGIN_REBUILDER_STATUS_IN_PREPARATION,
					'logging'=>self::LOGIN_REBUILDER_LOGGING_OFF,
					'page'=>$candidate[ array_rand( $candidate ) ],
					'page_subscriber'=>'',
					'keyword'=>$this->generate_keyword(),
					'response'=>self::LOGIN_REBUILDER_RESPONSE_403 ) );
		if ( !isset( $this->properties['logging'] ) )
			$this->properties['logging'] = self::LOGIN_REBUILDER_LOGGING_OFF;
		if ( is_multisite() )
			$this->properties['status'] = get_option( LOGIN_REBUILDER_PROPERTIES, self::LOGIN_REBUILDER_STATUS_IN_PREPARATION );
		if ( $this->properties['status'] == self::LOGIN_REBUILDER_STATUS_WORKING &&
			( !@file_exists( ABSPATH.$this->properties['page'] ) || !$this->is_valid_new_login_file() ) ) {
			$this->properties['status'] = self::LOGIN_REBUILDER_STATUS_IN_PREPARATION;
		}
		add_action( 'admin_menu', array( &$this, 'admin_menu' ) );
		add_action( 'wp_ajax_login_rebuilder_try_save', array( &$this, 'try_save' ) );
		add_filter( 'plugin_row_meta', array( &$this, 'plugin_row_meta' ), 9, 2 );
		if ( $this->properties['status'] == self::LOGIN_REBUILDER_STATUS_WORKING ) {
			add_action( 'login_init', array( &$this, 'login_init' ) );
			if ( $this->properties['logging'] == self::LOGIN_REBUILDER_LOGGING_ALL || $this->properties['logging'] == self::LOGIN_REBUILDER_LOGGING_LOGIN )
				add_filter( 'login_redirect', array( &$this, 'login_redirect' ), 10, 3 );
			add_filter( 'site_url', array( &$this, 'site_url' ), 10, 4 );
			add_filter( 'wp_redirect', array( &$this, 'wp_redirect' ), 10, 2 );
			if ( $this->properties['page_subscriber'] != '' &&
				strpos( $_SERVER['REQUEST_URI'], '/'.$this->properties['page_subscriber'] ) !== false &&
				$this->is_valid_new_login_file( $this->properties['page_subscriber'] ) )
				add_filter( 'authenticate', array( &$this, 'subscriber_authenticate' ), 99, 3 );
		}
	}
	private function generate_keyword() {
		return substr( str_shuffle( 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789abcdefghijklmnopqrstuvwxyz0123456789' ), rand( 0, 60 ), 8 );
	}
	function init() {
		if ( get_option( LOGIN_REBUILDER_DB_VERSION_NAME ) != LOGIN_REBUILDER_DB_VERSION ) {
			update_option( LOGIN_REBUILDER_DB_VERSION_NAME, LOGIN_REBUILDER_DB_VERSION );
		}
	}
	function deactivation() {
		$this->delete_private_nonce();
		delete_option( LOGIN_REBUILDER_DB_VERSION_NAME );
		delete_option( LOGIN_REBUILDER_LOGGING_NAME );

		if ( is_multisite() )
			delete_option( LOGIN_REBUILDER_PROPERTIES );
		else {
			$this->properties['status'] = self::LOGIN_REBUILDER_STATUS_IN_PREPARATION;
			update_site_option( LOGIN_REBUILDER_PROPERTIES, $this->properties );
		}
	}

	function login_init() {
		if ( $_GET['action'] == 'postpass' ) return;
		if ( preg_match( '/\/wp\-login\.php/u', $_SERVER['REQUEST_URI'] ) ||
			!( ( strpos( $_SERVER['REQUEST_URI'], '/'.$this->properties['page'] ) !== false || strpos( $_SERVER['REQUEST_URI'], '/'.$this->properties['page_subscriber'] ) !== false ) && defined( 'LOGIN_REBUILDER_SIGNATURE' ) && $this->properties['keyword'] == LOGIN_REBUILDER_SIGNATURE ) ) {
			if ( $this->properties['logging'] == self::LOGIN_REBUILDER_LOGGING_ALL || $this->properties['logging'] == self::LOGIN_REBUILDER_LOGGING_INVALID_REQUEST ) {
				// logging
				$logging = get_option( LOGIN_REBUILDER_LOGGING_NAME, '' );
				if ( $logging == '' ) {
					$logging = array( 'invalid'=>array(), 'login'=>array() );
					add_option( LOGIN_REBUILDER_LOGGING_NAME, $logging, '', 'no' );
				}
				if ( count( $logging['invalid'] ) >= self::LOGIN_REBUILDER_LOGGING_LIMIT )
					array_shift( $logging['invalid'] );
				$logging['invalid'][] = array(
						'time'=>time(), 
						'ip'=>$_SERVER['REMOTE_ADDR'], 
						'uri'=>$_SERVER['REQUEST_URI'], 
						'id'=>( empty( $_POST['log'] )? '': $_POST['log'] ), 
						'pw'=>( empty( $_POST['pwd'] )? '': $_POST['pwd'] ) );
				update_option( LOGIN_REBUILDER_LOGGING_NAME, $logging );
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
		if ( !is_wp_error( $user ) && $this->properties['logging'] == self::LOGIN_REBUILDER_LOGGING_ALL || $this->properties['logging'] == self::LOGIN_REBUILDER_LOGGING_LOGIN ) {
			// logging
			$logging = get_option( LOGIN_REBUILDER_LOGGING_NAME, '' );
			if ( $logging == '' ) {
				$logging = array( 'invalid'=>array(), 'login'=>array() );
				add_option( LOGIN_REBUILDER_LOGGING_NAME, $logging, '', 'no' );
			}
			if ( count( $logging['login'] ) >= self::LOGIN_REBUILDER_LOGGING_LIMIT )
				array_shift( $logging['login'] );
			$logging['login'][] = array(
					'time'=>time(),
					'ip'=>$_SERVER['REMOTE_ADDR'], 
					'uri'=>$_SERVER['REQUEST_URI'],
					'id'=>$user->ID );
			update_option( LOGIN_REBUILDER_LOGGING_NAME, $logging );
		}
		return $redirect_to;
	}
	function site_url( $url, $path, $orig_scheme, $blog_id ) {
		$my_login_page = $this->properties['page'];
		if ( function_exists( 'wp_get_current_user' ) )
			$user = wp_get_current_user();
		else
			$user = (object)array( 'data'=>null );
		if ( $this->properties['page_subscriber'] != '' && ( strpos( $_SERVER['REQUEST_URI'], '/'.$this->properties['page_subscriber'] ) !== false || ( isset( $user->data ) && !$user->has_cap( 'edit_posts' ) ) ) )
			$my_login_page = $this->properties['page_subscriber'];

		if ( $path == 'wp-login.php' &&
			( is_user_logged_in()  || strpos( $_SERVER['REQUEST_URI'], '/'.$my_login_page ) !== false ) )
			$url = str_replace( 'wp-login.php', $my_login_page, $url );
		return $url;
	}
	function wp_redirect( $location, $status ) {
		$my_login_page = $this->properties['page'];
		if ( function_exists( 'wp_get_current_user' ) )
			$user = wp_get_current_user();
		else
			$user = (object)array( 'data'=>null );
		if ( $this->properties['page_subscriber'] != '' && ( strpos( $_SERVER['REQUEST_URI'], '/'.$this->properties['page_subscriber'] ) !== false || ( isset( $user->data ) && !$user->has_cap( 'edit_posts' ) ) ) )
			$my_login_page = $this->properties['page_subscriber'];

		if ( preg_match( '/\/'.str_replace( array( '-', '.' ), array( '\\-', '\\.' ), $my_login_page ).'/u', $_SERVER['REQUEST_URI']) )
			$location = str_replace( 'wp-login.php', $my_login_page, $location );
		else if ( preg_match( '/reauth\=1$/u', $location ) ) {
			if ( is_user_admin() )
				$scheme = 'logged_in';
			else
				$scheme = apply_filters( 'auth_redirect_scheme', '' );
			if ( $cookie_elements = wp_parse_auth_cookie( '',  $scheme ) ) {
				extract( $cookie_elements, EXTR_OVERWRITE );
				$user = get_user_by( 'login', $username );
				if ( $user ) // timeout
					$location = str_replace( 'wp-login.php?', $my_login_page.'?', $location );
			}
		}
		return $location;
	}
	function subscriber_authenticate( $user, $username, $password ) {
		if ( !is_a( $user, 'WP_User' ) )
			return $user;

		if ( !( isset( $user->caps['subscriber'] ) && $user->caps['subscriber'] ) )
			return new WP_Error( 'authentication_failed', __( '<strong>ERROR</strong>: Invalid  username.', LOGIN_REBUILDER_DOMAIN ) );
		return $user;
	}
	function plugin_row_meta( $links, $file ) {
		if ( $file == plugin_basename( dirname( __FILE__ ) ).'/'.basename( __FILE__ ) ) {
			$links[] = '<a href="options-general.php?page='.self::LOGIN_REBUILDER_PROPERTIES_NAME.'">'.__( 'Settings' ).'</a>';
		}
		return $links;
	}
	function admin_menu() {
		add_options_page( __( 'Login rebuilder', LOGIN_REBUILDER_DOMAIN ), __( 'Login rebuilder', LOGIN_REBUILDER_DOMAIN ), 9, self::LOGIN_REBUILDER_PROPERTIES_NAME, array( &$this, 'properties' ) );
	}

	private function is_reserved_login_file( $filename ) {
		return in_array( $filename,
				array( 'index.php', 'wp-activate.php', 'wp-app.php', 'wp-atom.php', 'wp-blog-header.php',
					'wp-comments-post.php', 'wp-commentsrss2.php', 'wp-config.php', 'wp-config-sample.php', 'wp-cron.php',
					'wp-feed.php', 'wp-links-opml.php', 'wp-load.php', 'wp-login.php', 'wp-mail.php',
					'wp-pass.php', 'wp-rdf.php', 'wp-register.php', 'wp-rss.php', 'wp-rss2.php',
					'wp-settings.php', 'wp-signup.php', 'wp-trackback.php', 'xmlrpc.php' ) );
	}
	private function is_valid_new_login_file( $filename = null ) {
		if ( is_null( $filename ) )
			$filename = $this->properties['page'];
		return 
			preg_replace( "/\r\n|\r|\n/", "\r", str_replace( '%sig%', $this->properties['keyword'], $this->content ) ) == 
			preg_replace( "/\r\n|\r|\n/", "\r", trim( @file_get_contents( ABSPATH.$filename ) ) );
	}

	function properties() {
		global $wp_version;
		if ( !current_user_can( 'manage_options' ) )
			return;	// Except an administrator

		$show_reload = false;
		$message = '';
		if ( isset( $_POST['properties'] ) ) {
			check_admin_referer( self::LOGIN_REBUILDER_PROPERTIES_NAME );

			if ( $this->verify_private_nonce() ) {
				$_POST['properties']['page'] = trim( $_POST['properties']['page'] );
				$_POST['properties']['page_subscriber'] = trim( $_POST['properties']['page_subscriber'] );
				if ( $this->is_reserved_login_file( $_POST['properties']['page'] ) ) {
					$message = __( 'New login file is system file. Please change a file name.', LOGIN_REBUILDER_DOMAIN );
					$this->properties = $_POST['properties'];
				} else if ( $_POST['properties']['page_subscriber'] != '' && ( $_POST['properties']['page_subscriber'] == $_POST['properties']['page'] || $this->is_reserved_login_file( $_POST['properties']['page_subscriber'] ) ) ) {
					$message = __( 'Login file for subscriber is invalid. Please change a file name.', LOGIN_REBUILDER_DOMAIN );
					$this->properties = $_POST['properties'];
				} else {
					if ( $this->properties['keyword'] != $_POST['properties']['keyword'] ||
						$this->properties['logging'] != $_POST['properties']['logging'] ||
						$this->properties['page'] != $_POST['properties']['page'] ||
						$this->properties['page_subscriber'] != $_POST['properties']['page_subscriber'] ) {
						$this->properties['keyword'] = $_POST['properties']['keyword'];
						$this->properties['logging'] = $_POST['properties']['logging'];
						$this->properties['page'] = $_POST['properties']['page'];
						$this->properties['page_subscriber'] = $_POST['properties']['page_subscriber'];
						$result = $this->try_save( array_merge( $_POST['properties'], array( 'mode'=>1 ) ) );
						if ( $result['update'] ) {
							$this->properties['status'] = intval( $_POST['properties']['status'] );
						} else if ( !empty( $this->properties['page'] ) && ( !@file_exists( ABSPATH.$this->properties['page'] ) || !$this->is_valid_new_login_file() ) ) {
							$message .= __( "However, failed to write a new login file to disk.\nPlease change into the enabled writing of a disk or upload manually.", LOGIN_REBUILDER_DOMAIN );
							$this->properties['status'] = self::LOGIN_REBUILDER_STATUS_IN_PREPARATION;
						}
						$subscriber = $_POST['properties'];
						$subscriber['page'] = $subscriber['page_subscriber'];
						$result = $this->try_save( array_merge( $subscriber, array( 'mode'=>1 ) ) );
						$message = __( 'Options saved.', LOGIN_REBUILDER_DOMAIN ).' ';
						if ( !$result['update'] && !empty( $this->properties['page_subscriber'] ) && ( !@file_exists( ABSPATH.$this->properties['page_subscriber'] ) || !$this->is_valid_new_login_file( $this->properties['page_subscriber'] ) ) ) {
							$message .= __( "However, failed to write a login file for subscriber to disk.\nPlease change into the enabled writing of a disk or upload manually.", LOGIN_REBUILDER_DOMAIN );
						}
					} else if ( $this->properties['status'] != intval( $_POST['properties']['status'] ) ) {
						$message = __( 'Options saved.', LOGIN_REBUILDER_DOMAIN ).' ';
						$this->properties['status'] = intval( $_POST['properties']['status'] );
						if ( $this->properties['status'] == self::LOGIN_REBUILDER_STATUS_WORKING ) {
							if ( !@file_exists( ABSPATH.$this->properties['page'] ) ) {
								$message .= __( "However, a new login file was not found.", LOGIN_REBUILDER_DOMAIN );
								$this->properties['status'] = self::LOGIN_REBUILDER_STATUS_IN_PREPARATION;
							} else if ( !$this->is_valid_new_login_file() ) {
								$message .= __( "However, the contents of a new login file are not in agreement.", LOGIN_REBUILDER_DOMAIN );
								$this->properties['status'] = self::LOGIN_REBUILDER_STATUS_IN_PREPARATION;
							}
						}
					}
					$this->properties['response'] = intval( $_POST['properties']['response'] );
					update_site_option( LOGIN_REBUILDER_PROPERTIES, $this->properties );
					if ( is_multisite() )
						update_option( LOGIN_REBUILDER_PROPERTIES, $this->properties['status'] );
					if ( $this->properties['status'] == self::LOGIN_REBUILDER_STATUS_IN_PREPARATION ) {
						$logout_from = site_url( $this->properties['page'] );
						$logout_to = site_url( 'wp-login.php' );
					} else {
						$logout_from = site_url( 'wp-login.php' );
						$logout_to = site_url( $this->properties['page'] );
					}
				}
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
<td><input type="text" name="properties[keyword]" id="properties_keyword" value="<?php _e( $this->properties['keyword'] ); ?>" class="regular-text code" /></td>
</tr>

<tr valign="top">
<th rowspan="2"><label for="properties_page"><?php _e( 'New login file :', LOGIN_REBUILDER_DOMAIN ); ?></label></th>
<td><input type="text" name="properties[page]" id="properties_page" value="<?php _e( $this->properties['page'] ); ?>" class="regular-text code" />&nbsp;<span id="writable">&nbsp;</span></td>
</tr>

<tr valign="top">
<td><textarea  name="properties[content]" id="login_page_content" rows="4" cols="60" style="font-family:monospace;" readonly="readonly"></textarea><input type="hidden" id="content_template" value="<?php echo $this->content; ?>" /></td>
</tr>

<tr valign="top">
<th><label for="properties_page"><?php _e( 'Login file for subscriber:', LOGIN_REBUILDER_DOMAIN ); ?></label></th>
<td><input type="text" name="properties[page_subscriber]" id="properties_page_subscriber" value="<?php _e( $this->properties['page_subscriber'] ); ?>" class="regular-text code" />&nbsp;<span id="writable_subscriber">&nbsp;</span></td>
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
<?php } wp_nonce_field( self::LOGIN_REBUILDER_PROPERTIES_NAME ); $this->private_nonce_field(); ?>
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
<?php } } ?>
</div>
</div>
<script type="text/javascript">
( function($) {
	$( 'a' ).each( function () {
		$( this ).attr( 'href', jQuery( this ).attr( 'href' ).replace( '<?php echo $logout_from; ?>', '<?php echo $logout_to; ?>' ) );
	} );
	$( '#properties_keyword' ).blur( function () {
		$( '#login_page_content' ).val( $( '#content_template' ).val().replace( '%sig%', $( this ).val() ) );
	} );
	$( '#properties_page, #properties_page_subscriber' ).blur( function () {
		var page_elm = $( this );
		var url = $.trim( $( this ).val() );
		if ( url != '' ) {
			$.post( '<?php echo admin_url( 'admin-ajax.php' ); ?>',
				{ action: 'login_rebuilder_try_save', mode: 0, page: url },
				function( response ) {
					if ( response.data.writable ) {
						page_elm.next( 'span' ).text( '[<?php _e( 'Writing is possible', LOGIN_REBUILDER_DOMAIN ); ?>]' ).css( 'color', 'blue' );
					} else {
						page_elm.next( 'span' ).text( '[<?php _e( 'Writing is impossible', LOGIN_REBUILDER_DOMAIN ); ?>]' ).css( 'color', 'orange' );
					}
				}, 'json' );
		}
	} );
	$( '#properties_keyword' ).blur();
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
		else
			extract( $_POST );
		if ( !isset( $mode ) || !isset( $page ) ) {
			if ( is_null( $param ) || $param == '' )
				exit;
			else
				return null;
		}
		$data = array(
			'request'=>$page,
			'path'=>ABSPATH.$page,
			'exists'=>false,
			'writable'=>false,
			'update'=>false );
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
	private function init_private_nonce() {
		if ( get_option( self::LOGIN_REBUILDER_NONCE_NAME, '' ) == '' )
			add_option( self::LOGIN_REBUILDER_NONCE_NAME, array( 'nonce'=>'', 'access'=>time() ), '', 'no' );
	}
	private function delete_private_nonce() {
		delete_option( self::LOGIN_REBUILDER_NONCE_NAME );
	}
	private function private_nonce_field( $field_name = self::LOGIN_REBUILDER_NONCE_NAME, $action = self::LOGIN_REBUILDER_NONCE_NAME ) {
		$now = time();
		$nonce = wp_create_nonce( $action.$now%10000 );
		$this->init_private_nonce();
		update_option( self::LOGIN_REBUILDER_NONCE_NAME, array( 'nonce'=>$nonce, 'access'=>$now ) );
		$field_name = esc_attr( $field_name );
		$nonce_field = '<input type="hidden" id="'.$field_name.'" name="'.$field_name.'" value="'.$nonce.'" />';
		echo $nonce_field;
	}
	private function verify_private_nonce( $field_name = self::LOGIN_REBUILDER_NONCE_NAME, $lifetime = 1800 ) {
		$valid = false;
		$field_name = esc_attr( $field_name );
		$now = time();
		$private_nonce = get_option( self::LOGIN_REBUILDER_NONCE_NAME, '' );
		if ( isset( $private_nonce['nonce'] ) && isset( $private_nonce['access'] ) &&
			isset( $_REQUEST[$field_name] ) && $_REQUEST[$field_name] == $private_nonce['nonce'] &&
			( $now-$private_nonce['access'] ) > 0 && ( $now-$private_nonce['access'] ) <= $lifetime ) {
			$valid = true;
		}
		return $valid;
	}
}
?>