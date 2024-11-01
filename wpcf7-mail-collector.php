<?php

/**
 * Plugin Name:       Wpcf7 Email Collector
 * Description:       WPCF7 Email Collector is an extention plugin for Contact form 7, It stores form submissions into admin panel for user to be able to view. No STMP required !
 * Version:           1.0.0
 * Requires at least: 5.2
 * Requires PHP:      7.2
 * Author:            Anmo
 */


if( ! defined('ABSPATH') ){ die; }

class Wpcf7EmailCollector extends WP_REST_Controller {

    var $mc_post_type = 'mail_collector';

    function __construct(){
        add_action('init', array($this, 'init_MailCollector') );

        if (defined('WPCF7_PLUGIN_DIR')) {
            require_once WPCF7_PLUGIN_DIR . '/includes/contact-form.php';
            // override hook action to collect mail
            add_action('rest_api_init', array($this,'override_wpcf7_endpint'), 10, 0);
        }
    }

    public function init_MailCollector(){
        $this->reg_mail_collector();

        add_action( 'restrict_manage_posts', array($this, 'add_section_filter' ) );
        add_filter( 'pre_get_posts', array($this, 'filter_mail' ) );

        add_filter( 'manage_mail_collector_posts_columns', array($this, 'set_custom_edit_book_columns' ) );
        add_action( 'manage_mail_collector_posts_custom_column' , array($this, 'custom_mail_column' ), 10, 2 );

        add_action( 'do_meta_boxes', array($this, 'removeDefaultCustomFields' ), 10, 3 );
        add_action( 'admin_menu', array($this, 'createCustomFields' ) );
    }

    function removeDefaultCustomFields( $type, $context, $post ) {
        foreach ( array( 'normal', 'advanced', 'side' ) as $context ) {
            remove_meta_box( 'postcustom', $this->mc_post_type, $context );
        }
    }

    function createCustomFields(){
        if ( function_exists( 'add_meta_box' ) ) {
            add_meta_box(
                'my-custom-inbox', 
                'Details',
                array($this, 'display_inbox' ), 
                $this->mc_post_type, 
                'normal', 
                'high'
            );
        }
    }

    function display_inbox() {
        global $post;  
        if ( $post->post_type == $this->mc_post_type ) {
            $wpcf7_id = get_post_meta($post->ID, 'mc_wpcf7_form_id', true);
            $wpcf7 = get_post( $wpcf7_id );
            $wpcf7_title = $wpcf7->post_title;

            if(get_post_meta($post->ID, 'mc_wpcf7_form_opened', true) === false){
                update_post_meta($post->ID,'mc_wpcf7_form_opened', true);
            }

            $wpcf7_mail = json_decode( get_post_meta($post->ID, 'mc_wpcf7_form_mail', true), true);

            ?>
                <h2> <b>Form Sent:</b> <?php echo esc_html( $wpcf7_title ); ?> </h2>
                <h2> <b>Sender Email:</b> <?php echo esc_html( $post->post_title ); ?> </h2>
                <h2> <b>Time Sent:</b> <?php echo esc_html($post->post_date); ?> </h2>
                <hr style="border-top: 3px solid #bbb;">
                <h2> <b> Form Entries </b> </h2>
                <hr style="border-top: 3px solid #bbb;">
                <?php 
                    foreach ($wpcf7_mail as $key => $value) {
                        $mkey = key($value);
                        $content = $value[key($value)]; 
                        ?> 
                            <h2>
                                <b> <?php echo esc_html($mkey);?> : </b> 
                                <?php echo esc_html($content); ?> 
                            </h2> 
                        <?php
                    }
                ?>
            
            <?php
        } 
    }

    function validate_email($email){
        foreach($email as $key => $value){
            if(strpos(key($value), 'email') !== false){
                $val_email = $value[key($value)];
                return filter_var($val_email, FILTER_VALIDATE_EMAIL);
            }
        }
        return true;
    }

    function store_collected_mail($WPCF7_ID,$mail){

        $form = get_post($WPCF7_ID);
        $mail_sender = 'Unknown';
        foreach($mail as $key => $value){
            if(strpos(key($value), 'email') !== false){
                $mail_sender = $value[key($value)];
            }

            $mail[$key] = array(
                key($value) => str_replace(array("\r\n","\r","\n","\\r","\\n","\\r\\n"),"</br>", $value[key($value)]) 
            );

        }

        $user_input_in = json_encode( $mail );
        wp_insert_post(array(
            'post_type'       => "mail_collector",
            'post_title'      => $mail_sender,
            'post_name'       => $mail_sender, 
            'post_status'     => "publish",
            'comment_status'  => "closed",
            'ping_status'     => "closed",
            'meta_input'      => array(
                'mc_wpcf7_form_id' => $form->ID,
                'mc_wpcf7_form_title' => $form->post_title,
                'mc_wpcf7_form_mail' => $user_input_in,
                'mc_wpcf7_form_opened' => false,
            )
        ));

    }

    public function override_wpcf7_endpint(){
        //function overrides contact form 7 submission endpoint to collect mail

        $route_namespace = 'contact-form-7/v1';

        register_rest_route( $route_namespace,
			'/contact-forms/(?P<id>\d+)/feedback',
			array(
				array(
					'methods' => \WP_REST_Server::CREATABLE,
					'callback' => array( $this, 'wpcf7_feedback' ),
					'permission_callback' => '__return_true',
				),
			),
            true
		);

    }

    public function wpcf7_feedback(\WP_REST_Request $request){
         
        $content_type = $request->get_header( 'Content-Type' );

		if ( ! str_starts_with( $content_type, 'multipart/form-data' ) ) {
			return new \WP_Error( 'wpcf7_unsupported_media_type',
				__( "The request payload format is not supported.", 'contact-form-7' ),
				array( 'status' => 415 )
			);
		}

		$url_params = $request->get_url_params();
		$item = null;

		if ( ! empty( $url_params['id'] ) ) {
            if (class_exists('WPCF7_ContactForm')) {
                $item = \WPCF7_ContactForm::get_instance( $url_params['id'] );
            }
		}

		if ( ! $item ) {
			return new \WP_Error( 'wpcf7_not_found',
				__( "The requested contact form was not found.", 'contact-form-7' ),
				array( 'status' => 404 )
			);
		}

        // get from data from $item
        $form_id = $url_params['id'];           // get form id
        $form = $item->prop('form');            // get form html 
        $body = $request->get_body_params();    // get form data

        $user_input_in = $this->get_form_key($form,$body);

        if($this->validate_email($user_input_in) == false){
            $response = array(
                'status' => 'failed',
                'message' => 'Invalid Email'
            );
    
            return rest_ensure_response( $response );
        }

        // save mail to database
        $this->store_collected_mail($form_id,$user_input_in);

        // return success message
        $response = array(
            'status' => 'success',
            'message' => 'Email Sent Successfully'
        );

        return rest_ensure_response( $response );
    }

    private function get_form_key($from,$body){
        // get form key from form html
        $keys = array();
        $re = '#\[(.*?)\]#';
        preg_match_all($re, $from, $matches, PREG_SET_ORDER, 0);

        foreach ($matches as $value) {
            $temp = explode(' ', $value[1]);
            if($temp[0] == 'submit') continue;

            // $keys[] = $temp[1];
            $keys[$temp[1]] = $temp[0];
        }

        $user_input = array();
        foreach ($keys as $key => $type) {
            if(isset($body[$key])){
                // sanitize input
                $cleaned = $this->sanitize_form_data($type, $body[$key]);

                // $user_input[] = array($key => $body[$key]);
                $user_input[] = array($key => $cleaned);
            } 
        }

        return $user_input;
    }

    private function sanitize_form_data($type,$data){
        // sanitize form data
        switch ($type) {
            case 'tel':
            case 'tel*':    
            case 'number':
            case 'number*':    
            case 'date':
            case 'date*':
            case 'textarea':
            case 'textarea*':
            case 'text*':    
            case 'text': return sanitize_text_field( $data );
            case 'email*': 
            case 'email': return sanitize_email( $data );
            case 'url*': 
            case 'url': return esc_url_raw( $data );
            default: return 'UNKNOWN';
        }
    }

    function activiate(){
        flush_rewrite_rules();
    }

    function deactivate(){
        flush_rewrite_rules();
    }

    function reg_mail_collector(){
        register_post_type('mail_collector',array(
          'labels' => array(
            'name' => _x('Mail Collector', 'post type general name'),
            'singular_name' => _x('Mail Collector', 'post type singular name'),
            'add_new' => _x('Add New', 'Mail Collector'),
            'add_new_item' => __('Add New Mail Collector'),
            'edit_item' => __('Email Inbox'),
            'new_item' => __('New Mail Collector'),
            'view_item' => __('View Mail Collector'),
            'search_items' => __('Search Mail Collector'),
            'not_found' =>  __('No Mail Collector found'),
            'not_found_in_trash' => __('No Mail Collector found in Trash'),
            'parent_item_colon' => '',
            'menu_name' => 'Mail Collector'
          ),
          'public' => true,
          'publicly_queryable' => false,
          'post_status' => 'publish',
          'show_ui' => true,
          'show_in_menu' => true,
          'query_var' => true,  
          'rewrite' => array('slug'=>'mail-collector'),
          'capability_type' => 'post',
          'hierarchical' => false,
          'supports' => array('title','custom-fields')
        ));
    }

    function set_custom_edit_book_columns($columns) {
        $columns['source_form'] = __( 'Form Name', 'your_text_domain' );

        return $columns;
    }

    function custom_mail_column( $column, $post_id ) {
        switch ( $column ) {
            case 'source_form' :
                $wpcf7_id = get_post_meta($post_id, 'mc_wpcf7_form_title', true);
                echo esc_html( $wpcf7_id ); 
                break;
        }
    }

    function add_section_filter($post_type ) {
        if( 'mail_collector' !== $post_type ) return;

        // sanitize form name
        $section = sanitize_text_field( $_GET[ 'source_form' ] ) ;

        if ( !isset( $_GET[ 'source_form' ] ) || empty( $_GET[ 'source_form' ] ) ) return;

        // get wpcf7 form list
        $args = array( 'post_type' => 'wpcf7_contact_form', 'posts_per_page' => 999999, 'post_status' => 'publish');
        $wpcf7_forms = get_posts($args);

        ?>
            <script>
                document.querySelector('#posts-filter > div.tablenav.top > div:nth-child(2)').innerHTML = '';
            </script>
            <select name="source_form">
                <option value=""> Form Name </option>
                <?php foreach ( $wpcf7_forms as $forms ): 
                    $selected = $forms->post_title == $section ? 'selected="selected"' : ''; ?>
                    <option <?php echo $selected; ?> value="<?php echo esc_attr( $forms->post_title ); ?>"><?php echo esc_html( $forms->post_title ); ?></option>
                <?php endforeach; ?>
            </select>
        <?php
    }
    
    function filter_mail( $query ) {
        if ( !$query->is_main_query() ) return;

        // sanitize form name
        $section = sanitize_text_field( $_GET[ 'source_form' ] ) ;

        if ( !isset( $section ) || empty( $section ) ) return;

        $meta_query = array(
            array(
                'key' => 'mc_wpcf7_form_title',
                'value' => $section,
                'compare' => '='
            )
        );
        $query->set( 'meta_query', $meta_query );
    }
}


if(class_exists('Wpcf7EmailCollector')){
    $wpcf7EmailCollector = new Wpcf7EmailCollector();
    register_activation_hook( __FILE__, array($wpcf7EmailCollector, 'activiate') );
    register_deactivation_hook( __FILE__, array($wpcf7EmailCollector, 'deactivate') );
}