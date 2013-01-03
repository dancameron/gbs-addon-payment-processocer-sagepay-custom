<?php 

class Group_Buying_Disable_Carts extends Group_Buying_Controller {

	public static function init() {
		parent::init();
		
		// Remove all the purchase limit filters
		self::gbs_remove_purchase_limit_filters();
		
		// pop the products array
		add_action('gb_cart_load_products_get', array( get_class(), 'pop_cart_products'), 100, 2 );
		
	}
	
	public static function pop_cart_products( $products, Group_Buying_Cart $cart ) {
		if ( count($products) > 1 ) {
			return array(array_pop($products));
		}
		return $products;
	}
	
	public function flush_cart( Group_Buying_Cart $cart, $product_id, $quantity, $data = null ) {
		foreach ( $cart->products as $index => $product ) {
			if ( $product['deal_id'] !== $product_id || $data !== $product['data'] ) {
				unset($cart->products[$index]);
			}
		}
		return;
	}
	
	protected static function gbs_remove_purchase_limit_filters() {
		remove_all_filters( 'group_buying_template_meta_boxes/deal-expiration.php');
		remove_all_filters( 'group_buying_template_meta_boxes/deal-price.php');
		remove_all_filters( 'group_buying_template_meta_boxes/deal-limits.php');
	}

}

// Initiate the add-on
class Group_Buying_Disable_Carts_Addon extends Group_Buying_Controller {
	
	public static function init() {
		// Hook this plugin into the GBS add-ons controller
		add_filter('gb_addons', array(get_class(),'gb_disable_carts'), 10, 1);
	}

	public static function gb_disable_carts( $addons ) {
		$addons['disable_carts'] = array(
			'label' => self::__('Cart Disabler and Purchase Limit Enabler'),
			'description' => self::__('Disables the ability to purchase multiple deals by limiting the GBS cart. Enables purchase limits on gateways that intentionally remove the functionality and modifies the payment gateways to run authorization and automatic capturing (limited).'),
			'files' => array(
				__FILE__,
			),
			'callbacks' => array(
				array('Group_Buying_Disable_Carts', 'init'),
			),
		);
		return $addons;
	}

}