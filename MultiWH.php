<?php

/**
 * Plugin Name: WooMS Multi Warehouse
 * Plugin URI: https://github.com/wpcraft-ru/wooms/issues/327
 * Description: Добавляет механизм сохранения остатков по множеству складов в метаполя продукта
 * Version: 1.3
 */

defined('ABSPATH') || exit; // Exit if accessed directly

/**
 * Synchronization the stock of goods from MoySklad
 */
class MultiWH
{
    static public $config_wh_list = [
        'г. Пермь ул. Героев Хасана, 50/1 Самовывоз'   => 'woomsxt_perm',
        'с. Лобаново ул. Центральная 11Б Самовывоз'    => 'woomsxt_lobanovo',
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

        // Добавляем поля в Rest API
        add_action('rest_api_init', array(__CLASS__, 'handle_remote_stock'));
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
        woocommerce_wp_text_input(
            array(
                'id'            => "woomsxt_perm{$loop}",
                'name'          => "woomsxt_perm[{$loop}]",
                'value'         => get_post_meta($variation->ID, 'woomsxt_perm', true),
                'label'         => __('Остатки на складе Пермь', 'woocommerce'),
                'desc_tip'      => true,
                'description'   => __('Остатки на складе Пермь.', 'woocommerce'),
                'wrapper_class' => 'form-row form-row-full',
            )
        );
        woocommerce_wp_text_input(
            array(
                'id'            => "woomsxt_lobanovo{$loop}",
                'name'          => "woomsxt_lobanovo[{$loop}]",
                'value'         => get_post_meta($variation->ID, 'woomsxt_lobanovo', true),
                'label'         => __('Остатки на складе Лобаново', 'woocommerce'),
                'desc_tip'      => true,
                'description'   => __('Остатки на складе Лобаново.', 'woocommerce'),
                'wrapper_class' => 'form-row form-row-full',
            )
        );
    }

    /**
     * Save data for variations
     */
    public static function save_variation_settings_fields($variation_id, $loop)
    {
        $woomsxt_perm = $_POST['woomsxt_perm'][$loop];

        if (!empty($woomsxt_perm)) {
            update_post_meta($variation_id, 'woomsxt_perm', esc_attr($woomsxt_perm));
        }

        $woomsxt_lobanovo = $_POST['woomsxt_lobanovo'][$loop];

        if (!empty($woomsxt_lobanovo)) {
            update_post_meta($variation_id, 'woomsxt_lobanovo', esc_attr($woomsxt_lobanovo));
        }
    }

    /**
     * Load data for variation
     */
    public static function load_variation_settings_fields($variation)
    {
        $variation['woomsxt_perm'] = get_post_meta($variation['variation_id'], 'woomsxt_perm', true);
        $variation['woomsxt_lobanovo'] = get_post_meta($variation['variation_id'], 'woomsxt_lobanovo', true);

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

}

MultiWH::init();
