<?php

/**
 * AJAX callback to retrieve the modal content
 *
 * Note: The "fictioneer_ajax_" prefix enables the plugin skipping
 * in the theme's must-use-plugin.
 *
 * @since 0.1.0
 */

function fictioneer_ajax_fcnen_get_form_content() {
  // Verify
  if ( ! wp_doing_ajax() ) {
    wp_send_json_error( __( 'Invalid request.', 'fcnen' ) );
  }

  // Get form
  $html = fictioneer_minify_html( fcnen_get_modal_content() );

  // Response
  wp_send_json_success( array( 'html' => $html ) );
}
add_action( 'wp_ajax_fictioneer_ajax_fcnen_get_form_content', 'fictioneer_ajax_fcnen_get_form_content' );
add_action( 'wp_ajax_nopriv_fictioneer_ajax_fcnen_get_form_content', 'fictioneer_ajax_fcnen_get_form_content' );

/**
 * AJAX callback to subscribe
 *
 * Note: The "fictioneer_ajax_" prefix enables the plugin skipping
 * in the theme's must-use-plugin.
 *
 * @since 0.1.0
 */

function fictioneer_ajax_fcnen_subscribe_or_update() {
  // Verify
  if ( ! wp_doing_ajax() ) {
    wp_send_json_error( __( 'Invalid request.', 'fcnen' ) );
  }

  if ( ! check_ajax_referer( 'fcnen-subscribe', 'nonce', false ) ) {
    wp_send_json_error(
      array( 'notice' => __( 'Nonce verification failed. Please reload and try again.', 'fcnen' ) )
    );
  }

  // Setup
  $email = sanitize_email( $_POST['email'] ?? '' );
  $code = sanitize_text_field( $_POST['code'] ?? '' );
  $scope_everything = boolval( absint( $_POST['scope-everything'] ?? 1 ) );
  $scope_posts = boolval( absint( $_POST['scope-posts'] ?? 0 ) );
  $scope_stories = boolval( absint( $_POST['scope-stories'] ?? 0 ) );
  $scope_chapters = boolval( absint( $_POST['scope-chapters'] ?? 0 ) );
  $post_ids = fcnen_get_array_from_post_string( 'post_id' );
  $default_notice = __( 'Submission successful. If everything was in order, you will get an email.', 'fcnen' );
  $result = false;

  // Validate email
  if ( empty( $email ) || ! filter_var( $email, FILTER_VALIDATE_EMAIL ) )  {
    wp_send_json_error( array( 'notice' => __( 'Invalid email address.', 'fcnen' ) ) );
  }

  // Sanitize
  $post_ids = array_map( 'trim', $post_ids );
  $post_ids = array_unique( $post_ids );
  $post_ids = array_map( 'absint', $post_ids );
  $post_ids = array_map( 'strval', $post_ids );

  // Arguments
  $args = array(
    'scope-everything' => $scope_everything,
    'scope-posts' => $scope_posts,
    'scope-stories' => $scope_stories,
    'scope-chapters' => $scope_chapters,
    'post_ids' => $post_ids
  );

  // New or update?
  $is_new_subscriber = ! fcnen_subscriber_exists( $email );

  // New subscriber!
  if ( $is_new_subscriber ) {
    $result = fcnen_add_subscriber( $email, $args );
  }

  // Update subscriber!
  if ( ! $is_new_subscriber ) {
    // Code?
    if ( ! $code ) {
      $notice = WP_DEBUG ? __( 'Code missing.', 'fcnen' ) : $default_notice;
      wp_send_json_error( array( 'notice' => $notice ) );
    }

    // Query subscriber
    $subscriber = fcnen_get_subscriber_by_email_and_code( $email, $code );

    // Code did not match email
    if ( empty( $subscriber ) ) {
      $notice = WP_DEBUG ? __( 'Code did not match email.', 'fcnen' ) : $default_notice;
      wp_send_json_error( array( 'notice' => $notice ) );
    }

    // Update
    $result = fcnen_update_subscriber( $email, $args );
  }

  // Response
  if ( $result ) {
    wp_send_json_success( array( 'notice' => $default_notice ) );
  } else {
    $notice = WP_DEBUG ? __( 'Could not create or update subscription.', 'fcnen' ) : $default_notice;

    // Do not expose informative errors to strangers
    wp_send_json_success( array( 'notice' => $notice ) );
  }
}
add_action( 'wp_ajax_fictioneer_ajax_fcnen_subscribe_or_update', 'fictioneer_ajax_fcnen_subscribe_or_update' );
add_action( 'wp_ajax_nopriv_fictioneer_ajax_fcnen_subscribe_or_update', 'fictioneer_ajax_fcnen_subscribe_or_update' );

/**
 * AJAX callback to unsubscribe
 *
 * Note: The "fictioneer_ajax_" prefix enables the plugin skipping
 * in the theme's must-use-plugin.
 *
 * @since 0.1.0
 */

function fictioneer_ajax_fcnen_unsubscribe() {
  // Verify
  if ( ! wp_doing_ajax() ) {
    wp_send_json_error( __( 'Invalid request.', 'fcnen' ) );
  }

  if ( ! check_ajax_referer( 'fcnen-subscribe', 'nonce', false ) ) {
    wp_send_json_error(
      array( 'notice' => __( 'Nonce verification failed. Please reload and try again.', 'fcnen' ) )
    );
  }

  // Setup
  $email = sanitize_email( $_POST['email'] ?? '' );
  $code = sanitize_text_field( $_POST['code'] ?? '' );
  $default_notice = __( 'Successfully unsubscribed.', 'fcnen' );
  $result = false;

  // Validate email
  if ( empty( $email ) || ! filter_var( $email, FILTER_VALIDATE_EMAIL ) )  {
    wp_send_json_error( array( 'notice' => __( 'Invalid email address.', 'fcnen' ) ) );
  }

  // Email and code present?
  if ( empty( $email ) || empty( $code ) ) {
    wp_send_json_error( array( 'notice' => __( 'Email or code missing.', 'fcnen' ) ) );
  }

  // Query subscriber
  $subscriber = fcnen_get_subscriber_by_email_and_code( $email, $code );

  // Match found...
  if ( ! $subscriber ) {
    // ... no match
    $notice = WP_DEBUG ? __( 'No matching subscription found.', 'fcnen' ) : $default_notice;

    // Do not expose informative errors to strangers
    wp_send_json_success( array( 'notice' => $notice ) );
  } else {
    // ... found
    $result = fcnen_delete_subscriber( $email );
  }

  // Response
  if ( $result ) {
    wp_send_json_success( array( 'notice' => $default_notice ) );
  } else {
    $notice = WP_DEBUG ? __( 'Could not delete subscription.', 'fcnen' ) : $default_notice;

    // Do not expose informative errors to strangers
    wp_send_json_success( array( 'notice' => $notice ) );
  }
}
add_action( 'wp_ajax_fictioneer_ajax_fcnen_unsubscribe', 'fictioneer_ajax_fcnen_unsubscribe' );
add_action( 'wp_ajax_nopriv_fictioneer_ajax_fcnen_unsubscribe', 'fictioneer_ajax_fcnen_unsubscribe' );

/**
 * AJAX callback to search content
 *
 * Note: The "fictioneer_ajax_" prefix enables the plugin skipping
 * in the theme's must-use-plugin.
 *
 * @since 0.1.0
 */

function fictioneer_ajax_fcnen_search_content() {
  // Verify
  if ( ! wp_doing_ajax() ) {
    wp_send_json_error( __( 'Invalid request.', 'fcnen' ) );
  }

  if ( ! check_ajax_referer( 'fcnen-subscribe', 'nonce', false ) ) {
    wp_send_json_error(
      array( 'notice' => __( 'Nonce verification failed. Please reload and try again.', 'fcnen' ) )
    );
  }

  // Setup
  $filter = sanitize_text_field( $_REQUEST['filter'] ?? '' );
  $search = sanitize_text_field( $_REQUEST['search'] ?? '' );
  $page = absint( $_REQUEST['page'] ?? 1 );
  $query = null;
  $output = [];

  // Query
  if ( $filter === 'story' ) {
    $query = new WP_Query(
      array(
        'post_type' => 'fcn_story',
        'post_status' => 'publish',
        'orderby' => 'date',
        'order' => 'desc',
        'posts_per_page' => 10,
        'paged' => $page,
        's' => $search,
        'update_post_meta_cache' => false, // Improve performance
        'update_post_term_cache' => false // Improve performance
      )
    );

    foreach ( $query->posts as $item ) {
      // Chapter setup
      $title = fictioneer_get_safe_title( $item, 'fcnen-search-stories' );

      // Build and append item
      $item = "<li class='fcnen-dialog-modal__advanced-li' data-click-action='fcnen-add' data-name='post_id[]' data-type='post_id' data-compare='story-{$item->ID}' data-id='{$item->ID}'><span>{$title}</span></li>";

      // Add to output
      $output[] = $item;
    }
  }

  // Add observer?
  if ( $query && $page < $query->max_num_pages ) {
    $page++;

    $observer = '<li class="fcnen-dialog-modal__advanced-li _observer" data-target="fcnen-observer-item" data-page="' . $page . '"><i class="fa-solid fa-spinner fa-spin" style="--fa-animation-duration: .8s;"></i> ' . __( 'Loading…', 'fcnen' ) . '<span></span></li>';

    $output[] = $observer;
  }

  // No results?
  if ( empty( $output ) ) {
    $no_matches = '<li class="fcnen-dialog-modal__advanced-li _disabled _no-match"><span>' . __( 'No matches found.', 'fcnen' ) . '</span></li>';

    $output[] = $no_matches;
  }

  // Response
  wp_send_json_success(
    array(
      'html' => implode( '', $output )
    )
  );
}
add_action( 'wp_ajax_fictioneer_ajax_fcnen_search_content', 'fictioneer_ajax_fcnen_search_content' );
add_action( 'wp_ajax_nopriv_fictioneer_ajax_fcnen_search_content', 'fictioneer_ajax_fcnen_search_content' );
