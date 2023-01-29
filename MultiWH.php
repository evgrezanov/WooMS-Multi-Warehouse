<?php

/**
 * Plugin Name: WooMS Multi Warehouse
 * Plugin URI: https://github.com/wpcraft-ru/wooms/issues/327
 * Description: Добавляет механизм сохранения остатков по множеству складов в метаполя продукта
 * Version: 1.4
 */

 defined('ABSPATH') || exit; // Exit if accessed directly

 /**
  * Synchronization the stock of goods from MoySklad 
  * for simple and variable products
  * based https://github.com/evgrezanov/WooMS-Multi-Warehouse
  */
 class MultiWH
 {
     static public $config_wh_list = [
         'г. Пермь ул. Героев Хасана, 50/1 Самовывоз'   => 'woomsxt_perm',
         'с. Лобаново ул. Центральная 11Б Самовывоз'    => 'woomsxt_lobanovo',
         'Посылка 1-й Класс' => 'woomsxt_package_first_class',
         'Курьер' => 'woomsxt_courier',
     ];
 
 
     /**
      * The init
      */
     public static function init()
     {
 
         add_action('plugins_loaded', function () {
             add_filter('wooms_product_save', array(__CLASS__, 'update_product'), 30, 2);
             add_filter('wooms_variation_save', array(__CLASS__, 'update_variation'), 30, 2);
         });
 
         add_filter('woocommerce_get_availability_text', array(__CLASS__,  'woomsxt_before_add_to_cart_btn'), 99, 2);
         add_filter('wooms_order_send_data', array(__CLASS__, 'add_data_to_order'), 10, 2);
 
         // поля для вариации
         add_action('woocommerce_product_after_variable_attributes', array(__CLASS__, 'variation_settings_fields'), 10, 3);
         add_action('woocommerce_save_product_variation', array(__CLASS__, 'save_variation_settings_fields'), 10, 2);
         add_filter('woocommerce_available_variation', array(__CLASS__, 'load_variation_settings_fields'));
 
         // simple product metabox fields
         add_action('woocommerce_product_options_advanced', array( __CLASS__, 'simple_product_settings_fields'));
         // TODO - нужно?
         //add_action('woocommerce_process_product_meta', array( __CLASS__, 'save_simple_product_settings_fields'));
 
         // TODO Добавляем поля в Rest API
         //add_action('rest_api_init', array(__CLASS__, 'handle_remote_stock'));
     }
 
     // TODO - доработать
     public static function get_config_wh_list() {
         $option = get_option('config_wh_list');
         if (!empty($option)) {
             self::$config_wh_list = $option;
         }
         return self::$config_wh_list;
     }
 
     /**
      * Update product then import from MS
      */
     public static function update_product($product, $data_api)
     {
 
         $url = '';
         if ($product->get_type() == 'simple') {
             $url = sprintf('https://online.moysklad.ru/api/remap/1.2/report/stock/bystore?filter=product=https://online.moysklad.ru/api/remap/1.2/entity/product/%s', $product->get_meta('wooms_id'));
         }
 
         if (empty($url)) {
             return $product;
         }
 
         $data = wooms_request($url);
 
         if (!isset($data['rows'])) {
             return $product;
         }
 
         $result = [];
 
         foreach (self::$config_wh_list as $name => $key) {
             $value = 0;
             if (isset($data['rows'][0]['stockByStore'])) {
                 foreach ($data['rows'][0]['stockByStore'] as $row) {
                     if ($row['name'] == $name) {
                         $value = $row['stock'];
                     }
                 }
             }
 
             $result[] = [
                 'key' => $key,
                 'name' => $name,
                 'value' => $value,
             ];
         }
 
 
         foreach ($result as $item) {
             $product->update_meta_data($item['key'], $item['value']);
         }
 
 
         return $product;
     }
 
     /**
      * Update variation then import from MS
      */
     public static function update_variation($product, $data_api)
     {
 
         $url = '';
 
         if ($product->get_type() == 'variation') {
             $url = sprintf('https://online.moysklad.ru/api/remap/1.2/report/stock/bystore?filter=variant=https://online.moysklad.ru/api/remap/1.2/entity/variant/%s', $product->get_meta('wooms_id'));
         }
 
         if (empty($url)) {
             return $product;
         }
 
         $data = wooms_request($url);
 
         if (!isset($data['rows'])) {
             return $product;
         }
 
         $result = [];
 
         foreach (self::$config_wh_list as $name => $key) {
             $value = 0;
             if (isset($data['rows'][0]['stockByStore'])) {
                 foreach ($data['rows'][0]['stockByStore'] as $row) {
                     if ($row['name'] == $name) {
                         $value = $row['stock'];
                     }
                 }
             }
 
             $result[] = [
                 'key' => $key,
                 'name' => $name,
                 'value' => $value,
             ];
         }
 
 
         foreach ($result as $item) {
             $product->update_meta_data($item['key'], $item['value']);
             /*$wh = $whNames($item['key']);
             $product_name = $product->get_name();
             do_action(
                 'wooms_logger',
                 __CLASS__,
                 sprintf('Остатки продукта %s (%s) сохранены на %s', $product_name, $item['value'], $wh)
             );*/
         }
 
 
         return $product;
     }
 
     /**
      * Display product at warehauses
      * TODO - переделать в шаблон, добавить фильтр или вообще вынести отсюда
      */
     public static function woomsxt_before_add_to_cart_btn($availability, $product)
     {
         $stock = $product->get_stock_quantity();
         $product_id = $product->get_id();
         $content = '';
         //var_dump($product->managing_stock());
         //if ($product->is_in_stock() && $product->managing_stock()) {
         if ($product->is_in_stock()) {
             $content = '<table>
                 <tr>
                     <td>
                         <p><i class="fas fa-truck icon-large"></i> Доставка :</p>
                     </td>
                     <td>
                         <ul>
                             <li><span class="rb59-available-product-count">' . $stock . '</span>(доступно по предзаказу)</li>
                         </ul>
                     </td>
                 </tr>
                 <tr>
                     <td>
                         <p><i class="fas fa-shopping-cart icon-large"></i> Самовывоз :</p>
                     </td>
                     <td>
                         <br>
                         <ul>';
 
             foreach (self::$config_wh_list as $name => $key) {
                 $count = get_post_meta($product_id, $key, true);
                 if ($count > 0) {
                     $content .= '<li>' . $name . ' : <span class="rb59-available-product-count">' . $count . '</span> (доступно по предзаказу)</li>';
                 } else {
                     $content .= '<li>' . $name . ' : <span class="rb59-available-product-count">0</span> (доступно по предзаказу)</li>';
                 }
             }
 
             $content .= '</ul>
                     </td>
                 </tr>
             </table>';
         }
 
         return $content;
     }
 
     /**
      * add_data_to_order then send order to MS
      */
     public static function add_data_to_order($data, $order_id)
     {
         $order = wc_get_order($order_id);
         $shipping_data_method_title = '';
 
         foreach ($order->get_items('shipping') as $item_id => $item) {
             $item_data = $item->get_data();
             $shipping_data_method_title = $item_data['method_title'];
         }
 
         if (empty($shipping_data_method_title)) {
             return;
         }
 
         $url_wh  = 'https://online.moysklad.ru/api/remap/1.2/entity/store';
         $data_wh = wooms_request($url_wh);
         if (empty($data_wh['rows'])) {
             return;
         }
 
         foreach ($data_wh['rows'] as $row) :
             $store_name =  $row['name'];
             if ($shipping_data_method_title == $store_name) :
                 $store_id = $row['id'];
             endif;
         endforeach;
         $url = sprintf('https://online.moysklad.ru/api/remap/1.2/entity/store/%s', $store_id);
         $data['store']['meta'] = array(
             "href" => $url,
             "type" => "store",
         );
 
         return $data;
     }
 
     /**
      * Add field for variations
      */
     public static function variation_settings_fields($loop, $variation_data, $variation)
     {
         foreach (self::$config_wh_list as $label => $id) {
             woocommerce_wp_text_input(
                 array(
                     'id' => "{$id}{$loop}",
                     'name' => "{$id}[{$loop}]",
                     'value' => get_post_meta($variation->ID, $id, true),
                     'label' => __($label, 'woocommerce'),
                     'desc_tip' => true,
                     'description' => __($label, 'woocommerce'),
                     'wrapper_class' => 'form-row form-row-full',
                 )
             );
         }
     }
 
     /**
      * Save data for variations
      */
     public static function save_variation_settings_fields($variation_id, $loop)
     {
         foreach (self::$config_wh_list as $key => $value) {
             $field_value = $_POST[$value][$loop];
     
             if (!empty($field_value)) {
                 update_post_meta($variation_id, $value, esc_attr($field_value));
             }
         }
     }
 
     /**
      * Load data for variation
      */
     public static function load_variation_settings_fields($variation)
     {
         foreach (self::$config_wh_list as $name => $meta_key) {
             $variation[$meta_key] = get_post_meta($variation['variation_id'], $meta_key, true);
         }
     
         return $variation;
     }
 
     
     /**
      *  Add remote stock field to REST API
      */
     public static function handle_remote_stock(){
         register_rest_field(
             'product', 
             'remote_stock', 
             array(
                 'get_callback' => function ($object) {
                     $remote_stock = [];
                     foreach (self::$config_wh_list as $name => $key) {
                         $remote_stock[$key] = get_post_meta($object['id'], $key, true);
                     }
                     return $remote_stock;
                 },
                 'update_callback' => null,
                 'schema'          => null,
             )
         );
     }   
 
     /**
      * Add field for variations
      */
     public static function simple_product_settings_fields() {
         global $thepostid;
         if ( get_product($thepostid)->is_type('simple')) {
             echo '<div class="options_group">';
             echo '<h3>' . __('Остатки на складах', 'woocommerce' ) . '</h3>';
             foreach ( self::$config_wh_list as $label => $key ) {
                 woocommerce_wp_text_input(
                     array(
                         'id' => $key,
                         'label' => __( $label, 'woocommerce' ),
                         'desc_tip' => 'true',
                         'description' => 'Здесь отображаются остатки из Мой склад на складе ' . $label,
                     )
                 );
             }
             echo '</div>';
         }
     }
 
     public static function save_product_settings_fields($post_id) {
         $custom_product_meta_field = $_POST['_custom_product_meta_field'];
         if ( ! empty( $custom_product_meta_field ) ) {
             update_post_meta( $post_id, '_custom_product_meta_field', esc_attr( $custom_product_meta_field ) );
         }
     }
     
 }

MultiWH::init();
