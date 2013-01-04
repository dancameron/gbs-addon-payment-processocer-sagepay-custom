<?php
/*
Plugin Name: Group Buying Addon - Cart Disabler and Purchase Limit Enabler for Sagepay
Version: .1
Description: Disables the ability to purchase multiple deals by limiting the GBS cart. Enables purchase limits on gateways that intentionally remove the functionality and modifies the payment gateways to run authorization and automatic capturing (limited support). Supported Gateways: Sagepay
Plugin URI: http://groupbuyingsite.com/marketplace
Author: GroupBuyingSite.com
Author URI: http://groupbuyingsite.com/features
Plugin Author: Dan Cameron
Plugin Author URI: http://sproutventure.com/
Contributors: Dan Cameron
Text Domain: group-buying

*/

define ('GB_DC_PATH', WP_PLUGIN_DIR . '/' . basename( dirname( __FILE__ ) ) );

add_action('plugins_loaded', 'gb_load_disable_carts');
function gb_load_disable_carts() {
	if (class_exists('Group_Buying_Controller')) {
		require_once('groupBuyingDisableCarts.class.php');
		Group_Buying_Disable_Carts_Addon::init();
	}
}
add_action('gb_register_processors', 'gb_load_authorize_capture_gateways');
function gb_load_authorize_capture_gateways() {
	foreach (glob(GB_DC_PATH.'/payment_processors/*.class.php') as $file_path)
	{
		require_once($file_path);
	}
}

add_action('admin_head', 'gb_dc_version_check');
function gb_dc_version_check() {
	if ( class_exists('Group_Buying') ) {
		if ( !version_compare( Group_Buying::GB_VERSION, '3.0.999', '>=' ) ) {
			echo '<div class="error"><p><strong>Group Buying Addon - Cart Disabler and Purchase Limit Enabler</strong> requires a higher version of GBS (version 3.1+).</p></div>';
		}
	}
}