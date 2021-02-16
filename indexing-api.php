<?php
// @codingStandardsIgnoreLine
/*
 * Plugin Name: Mageinn Indexing Api
 * Description:  Indexing Api
 * Version:     1.0.0
 * Author:      Mageinn
 * Author URI:  https://www.mageinn.com
 * Copyright:   2021 Mageinn LLC
 */

if (!defined('ABSPATH')) {
    exit; // disable direct access
}
/*
 * Compana classes
 */
require_once(ABSPATH . 'wp-content/plugins/compana-elements/compana-api/CompanaOptions.php');
require_once(ABSPATH . 'wp-content/plugins/compana-elements/compana-api/options.php');
require_once(ABSPATH . 'wp-content/plugins/compana-elements/compana-api/CompanaApi.php');
require_once(ABSPATH . 'wp-content/plugins/compana-elements/compana-api/LinkedinConnector.php');
// For DB
require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
// For IndexingApi
require_once (ABSPATH . 'wp-content/plugins/indexing-api/vendor/autoload.php');

/**
 * @throws \Google\Exception
 */
function updateGoogleIndex(){

    $client = new Google\Client();
    $client->setAuthConfig(ABSPATH .'wp-content/plugins/indexing-api/credentials.json');
    $client->addScope('https://www.googleapis.com/auth/indexing');
    // Get a Guzzle HTTP Client
    $httpClient = $client->authorize();
    $endpoint = 'https://www.indexing.googleapis.com/v3/urlNotifications:publish';

    /**
     *  Cheking for table and if it isn't create a new one
     */
    global $wpdb;
    $table_name = $wpdb->get_blog_prefix() . 'compana_jobs';
    $charset_collate = "DEFAULT CHARACTER SET {$wpdb->charset} COLLATE {$wpdb->collate}";
    $query = $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) );
    if ( ! $wpdb->get_var( $query ) == $table_name ) {
        $sql = "CREATE TABLE {$table_name} (
        id int(11) unsigned NOT NULL auto_increment,
        url varchar(255) NOT NULL default '',
        PRIMARY KEY  (id)
    ) {$charset_collate};";
        dbDelta($sql);
    }

    /*
     *   Get urls from compana and put them into database and send them to Indexing
     */
    $jobList = CompanaApi::GetJobs(array(), false, "pm");
    $site_url = "https://yoursite.com";
    $sql= "";
    $db_urls = $wpdb->get_results("SELECT url FROM $table_name");

    foreach ($jobList as $jobItem) {
        $url= $site_url . $jobItem['alias'];
        $exist = false;
        foreach ($db_urls as $db_url){
            if($url == $db_url->url){
                $exist = true;
            }
        }
        if($exist == false) {
            $sql .= "INSERT INTO $table_name (url) VALUE ('$url') ;";
            
            $content = '{
          "url": " ' . $url . ' ",   
          "type": "URL_UPDATED"
        }';
        $httpClient->post($endpoint, [ 'body' => $content ]);
        }

    }
    /**
     *  Get urls from database and,  if it not exist in compana, delete them from dn and IndexingApi
     */
    foreach ($db_urls as $db_url) {
        $url = $db_url->url;
        $exist = false;
        foreach ($jobList as $jobItem){
            if($url == $site_url . $jobItem['alias']){
                $exist = true;
            }
        }
        if($exist == false) {
            $wpdb->delete( $table_name, array( 'url' => $url ) );
            
            $content = '{
          "url": " ' . $url . ' ",
          "type": "URL_DELETED"
        }';
        $httpClient->post($endpoint, [ 'body' => $content ]);
        }
    }
    dbDelta($sql);
}


register_activation_hook(__FILE__, 'google_index_activation');
function google_index_activation() {
    wp_clear_scheduled_hook( 'update_urls_twicedaily' );

    // добавим новую cron задачу
    wp_schedule_event( time(), 'twicedaily', 'update_urls_twicedaily');
}

add_action( 'update_urls_twicedaily', 'update_compana_urls_twicedaily' );
function update_compana_urls_twicedaily() {
    updateGoogleIndex();
}

register_deactivation_hook( __FILE__, 'google_index_deactivation' );
function google_index_deactivation(){
    wp_clear_scheduled_hook( 'update_urls_twicedaily' );
}