<?php
/*
Plugin Name: PNE V5.8 Clean
Version: 5.8
*/
if(!defined('ABSPATH')) exit;
define('PNE_PATH', plugin_dir_path(__FILE__));
require_once PNE_PATH.'includes/core.php';

register_activation_hook(__FILE__, 'pne_install');
function pne_install(){
 global $wpdb;
 require_once ABSPATH.'wp-admin/includes/upgrade.php';

 dbDelta("CREATE TABLE {$wpdb->prefix}pne_campaigns (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 subject TEXT,
 message LONGTEXT,
 created_at DATETIME,
 status VARCHAR(20)
 )");

 dbDelta("CREATE TABLE {$wpdb->prefix}pne_queue (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 campaign_id BIGINT,
 email VARCHAR(255),
 status VARCHAR(20) DEFAULT 'pending',
 attempts INT DEFAULT 0,
 last_error TEXT,
 sent_at DATETIME NULL,
 from_email VARCHAR(255),
 from_name VARCHAR(255)
 )");
}
