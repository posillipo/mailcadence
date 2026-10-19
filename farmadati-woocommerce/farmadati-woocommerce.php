<?php
/**
 * Plugin Name: Farmadati WooCommerce Integration
 * Plugin URI:  https://www.farmaciapetrone.com/
 * Description: Integra il web service Farmadati BDF2.0 con WooCommerce per importare e sincronizzare prodotti farmaceutici.
 * Version:     1.0.0
 * Author:      Farmacia Petrone
 * Text Domain: farmadati-wc
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'FARMADATI_WC_VERSION', '1.0.0' );
define( 'FARMADATI_WC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FARMADATI_WC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

add_action( 'plugins_loaded', 'farmadati_wc_init' );

function farmadati_wc_init() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>Farmadati WooCommerce Integration</strong> richiede WooCommerce attivo.</p></div>';
        } );
        return;
    }

    require_once FARMADATI_WC_PLUGIN_DIR . 'includes/class-farmadati-client.php';
    require_once FARMADATI_WC_PLUGIN_DIR . 'includes/class-farmadati-sync.php';
    require_once FARMADATI_WC_PLUGIN_DIR . 'includes/class-farmadati-csv-sync.php';
    require_once FARMADATI_WC_PLUGIN_DIR . 'includes/class-farmadati-admin.php';

    Farmadati_Admin::init();
    Farmadati_Sync::init();
    Farmadati_CSV_Sync::init();
}

register_activation_hook( __FILE__, 'farmadati_wc_activate' );
function farmadati_wc_activate() {
    if ( ! wp_next_scheduled( 'farmadati_daily_sync' ) ) {
        wp_schedule_event( strtotime( 'tomorrow 03:00:00' ), 'daily', 'farmadati_daily_sync' );
    }
}

register_deactivation_hook( __FILE__, 'farmadati_wc_deactivate' );
function farmadati_wc_deactivate() {
    wp_clear_scheduled_hook( 'farmadati_daily_sync' );
}
