<?php
/*
Plugin Name: PNE V5.8 Clean
Plugin URI: https://github.com/pa112d/News112d
Description: Simple newsletter/campaign plugin (hardened: nonce/cap checks, prepared queries, escaping).
Version: 5.8
Author: pa112d
Text Domain: pne
Domain Path: /languages
*/

if (! defined('ABSPATH')) {
    exit;
}

define('PNE_PATH', plugin_dir_path(__FILE__));

// Load translations (if any)
function pne_load_textdomain() {
    load_plugin_textdomain('pne', false, dirname(plugin_basename(__FILE__)) . '/languages');
}
add_action('plugins_loaded', 'pne_load_textdomain');

// Include core functionality
require_once PNE_PATH . 'core.php';

// Activation: create tables with correct charset/collation
register_activation_hook(__FILE__, 'pne_install');
function pne_install() {
    global $wpdb;

    if (! current_user_can('activate_plugins')) {
        return;
    }

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset_collate = $wpdb->get_charset_collate();

    $sql1 = "CREATE TABLE {$wpdb->prefix}pne_campaigns (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      subject TEXT NOT NULL,
      message LONGTEXT NOT NULL,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      status VARCHAR(20) DEFAULT 'draft',
      PRIMARY KEY (id)
    ) $charset_collate;";

    $sql2 = "CREATE TABLE {$wpdb->prefix}pne_queue (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      campaign_id BIGINT UNSIGNED NOT NULL,
      email VARCHAR(255) NOT NULL,
      status VARCHAR(20) DEFAULT 'pending',
      attempts INT DEFAULT 0,
      last_error TEXT,
      sent_at DATETIME NULL,
      from_email VARCHAR(255),
      from_name VARCHAR(255),
      PRIMARY KEY (id)
    ) $charset_collate;";

    // Logs table for detailed SMTP/error logging
    $sql3 = "CREATE TABLE {$wpdb->prefix}pne_logs (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      campaign_id BIGINT UNSIGNED NULL,
      queue_id BIGINT UNSIGNED NULL,
      email VARCHAR(255) DEFAULT NULL,
      level VARCHAR(20) DEFAULT 'error',
      message TEXT,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id)
    ) $charset_collate;";

    // Mailing lists table
    $sql4 = "CREATE TABLE {$wpdb->prefix}pne_mailing_lists (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      name VARCHAR(255) NOT NULL,
      description TEXT,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id)
    ) $charset_collate;";

    // List subscribers table
    $sql5 = "CREATE TABLE {$wpdb->prefix}pne_list_subscribers (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      list_id BIGINT UNSIGNED NOT NULL,
      email VARCHAR(255) NOT NULL,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY unique_email_per_list (list_id, email)
    ) $charset_collate;";

    dbDelta($sql1);
    dbDelta($sql2);
    dbDelta($sql3);
    dbDelta($sql4);
    dbDelta($sql5);

    // Ensure scheduled event exists
    if (! wp_next_scheduled('pne_send')) {
        wp_schedule_event(time(), 'pne_min', 'pne_send');
    }
}

// Deactivation: clear scheduled hooks
register_deactivation_hook(__FILE__, 'pne_deactivate');
function pne_deactivate() {
    // unschedule our cron hook
    wp_clear_scheduled_hook('pne_send');
    wp_clear_scheduled_hook('pne_process_news');
}
