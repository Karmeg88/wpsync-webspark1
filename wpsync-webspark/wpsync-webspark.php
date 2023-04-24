<?php
/*
Plugin Name: wpsync-webspark
*/
register_activation_hook( __FILE__, 'wpsync_webspark_activation' );
register_deactivation_hook( __FILE__, 'wpsync_webspark_deactivation' );

function wpsync_webspark_activation() {

    // Регистрация задачи планировщика
    if ( ! wp_next_scheduled( 'wpsync_webspark_sync_event' ) ) {
        wp_schedule_event( time(), 'hourly', 'wpsync_webspark_sync_event' );
    }
}

function wpsync_webspark_deactivation() {
    // Удаление задачи планировщика при деактивации плагина
    wp_clear_scheduled_hook( 'wpsync_webspark_sync_event' );
}
add_action( 'rest_api_init', 'wpsync_webspark_register_routes' );

function wpsync_webspark_register_routes() {
    // Регистрация маршрута /wpsync-webspark/v1/sync
    register_rest_route( 'wpsync-webspark/v1', '/sync', array(
        'methods'  => WP_REST_Server::READABLE,
        'callback' => 'wpsync_webspark_endpoint_handler',
    ) );
}
function wpsync_webspark_endpoint_handler( $request ) {
    // Выполнение синхронизации товаров
    wpsync_webspark_sync();
    $response = array(
        'message' => 'OK',
    );

    return $response;
}
function wpsync_webspark_sync() {
   $url = 'https://wp.webspark.dev/wp-api/products?limit=2000';
    $response = wp_remote_get( $url, array(
        'headers' => array(
            'Accept' => 'application/json',
        ),
    ) );

    if ( is_wp_error( $response ) ) {
        // Обработка ошибки
        error_log( 'API error: ' . $response->get_error_message() );
        return;
    }

    $body = wp_remote_retrieve_body( $response );
    $data = json_decode( $body, true );

    if ( empty( $data ) ) {
        // Обработка пустого ответа
        error_log( 'Empty response from API' );
        return;
    }

    // Обновление товарной базы
    foreach ( $data as $product ) {
        $sku = $product['sku'];
        $name = $product['name'];
        $description = $product['description'];
        $price = $product['price'];
        $picture = $product['picture'];
        $in_stock = $product['in_stock'];

        // Проверка существования товара в базе
        $product_id = wc_get_product_id_by_sku( $sku );

        if ( $product_id ) {
            // Обновление существующего товара
            $product = new WC_Product( $product_id );

            if ( $name != $product->get_name() ) {
                $product ->set_name( $name );
            }
            if ( $description != $product->get_description() ) {
            $product->set_description( $description );
            }

            if ( $price != $product->get_price() ) {
                $product->set_price( $price );
            }

            if ( $picture != $product->get_image_id() ) {
                $product->set_image_id( $picture );
            }

            if ( $in_stock != $product->get_stock_quantity() ) {
                $product->set_stock_quantity( $in_stock );
            }

            $product->save();
        } else {
            // Создание нового товара
            $product = new WC_Product();
            $product->set_name( $name );
            $product->set_description( $description );
            $product->set_regular_price( $price );
            $product->set_image_id( $picture );
            $product->set_stock_quantity( $in_stock );
            $product->set_sku( $sku );
            $product->save();
        }
    }

    // Удаление товаров, которые не пришли в ответе API
    $existing_products = get_posts(
        array(
            'post_type'      => 'product',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'fields'         => 'ids',
        )
    );

    $existing_skus = array_map(
        function ( $id ) {
            return get_post_meta( $id, '_sku', true );
        },
        $existing_products
    );

    $missing_skus = array_diff( $existing_skus, array_column( $data, 'sku' ) );

    foreach ( $missing_skus as $sku ) {
        $product_id = wc_get_product_id_by_sku( $sku );
        if ( $product_id ) {
            wp_trash_post( $product_id );
        }
    }
}
    // Добавление кнопки в админку
    function wpsync_webspark_add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            'Оновити весь товар зі складу',
            'Оновити весь товар зі складу',
            'manage_options',
            'wpsync-webspark-update-products',
            'wpsync_webspark_update_products'
        );
    }
    add_action( 'admin_menu', 'wpsync_webspark_add_admin_menu' );

    // Обработчик для кнопки
    function wpsync_webspark_update_products() {
        wpsync_webspark_sync();
        echo '<div class="notice notice-success"><p>Товари успішно оновлені</p></div>';
    }

?>