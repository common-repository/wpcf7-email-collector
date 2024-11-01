<?php

if( ! defined('WP_UNINSTALL_PLUGIN') ){
    die;
}

$email = get_posts( array('post_type' => 'email_submission', 'numberposts' => 9999999 ) );

foreach($email as $data){
    wp_delete_post($data->ID, true);
}