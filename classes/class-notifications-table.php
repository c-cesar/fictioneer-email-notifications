<?php

// No direct access!
defined( 'ABSPATH' ) OR exit;

// Make sure base class exists
if ( ! class_exists( 'WP_List_Table' ) ) {
  require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
}

/**
 * Adds post_id to the list of removable query arguments
 *
 * @since 0.1.0
 *
 * @param array $args  The list of removable query arguments.
 *
 * @return array The modified list of removable query arguments.
 */

function fcnen_remove_post_id_query_arg( $args ) {
  $args[] = 'post_id';

  return $args;
}

/**
 * Class FCNEN_Notifications_Table
 *
 * @since 0.1.0
 */

class FCNEN_Notifications_Table extends WP_List_Table {
  private $table_data;
  private $view = '';
  private $uri = '';

  public $total_items;
  public $ready_count = 0;
  public $paused_count = 0;
  public $sent_count = 0;

  /**
   * Constructor for the WP_List_Table subclass.
   *
   * @since Fictioneer Email Subscriptions 1.0.0
   */

  function __construct() {
    global $wpdb;

    parent::__construct([
      'singular' => 'notification',
      'plural' => 'notifications',
      'ajax' => false
    ]);

    // Validate GET actions
    if ( isset( $_GET['action'] ) ) {
      if ( ! isset( $_GET['fcnen-nonce'] ) || ! check_admin_referer( 'fcnen-table-action', 'fcnen-nonce' ) ) {
        wp_die( __( 'Nonce verification failed. Please try again.', 'fcnen' ) );
      }
    }

    // Validate POST actions
    if ( isset( $_POST['action'] ) ) {
      if ( ! isset( $_POST['_wpnonce'] ) || ! check_admin_referer( 'bulk-' . $this->_args['plural'] ) ) {
        wp_die( __( 'Nonce verification failed. Please try again.', 'fcnen' ) );
      }
    }

    // Remove post_id query arg
    add_filter( 'removable_query_args', 'fcnen_remove_post_id_query_arg' );

    // Initialize
    $table_name = $wpdb->prefix . 'fcnen_notifications';
    $this->view = $_GET['view'] ?? 'all';
    $this->total_items = $wpdb->get_var( "SELECT COUNT(post_id) FROM {$table_name}" ) ?? 0;
    $this->ready_count = $wpdb->get_var( "SELECT COUNT(*) FROM $table_name WHERE paused = 0 AND last_sent IS NULL" ) ?? 0;
    $this->paused_count = $wpdb->get_var( "SELECT COUNT(*) FROM $table_name WHERE paused = 1" ) ?? 0;
    $this->sent_count = $wpdb->get_var( "SELECT COUNT(*) FROM $table_name WHERE last_sent IS NOT NULL" ) ?? 0;
    $this->uri = remove_query_arg( ['action', 'id', 'notifications', 'fcnen-nonce'], $_SERVER['REQUEST_URI'] );

    // Redirect from empty views
    switch ( $this->view ) {
      case 'paused':
        if ( $this->paused_count < 1 ) {
          wp_safe_redirect( remove_query_arg( 'view', $this->uri  ) );
          exit();
        }
        break;
      case 'sent':
        if ( $this->sent_count < 1 ) {
          wp_safe_redirect( remove_query_arg( 'view', $this->uri  ) );
          exit();
        }
        break;
      case 'ready':
        if ( $this->ready_count < 1 ) {
          wp_safe_redirect( remove_query_arg( 'view', $this->uri  ) );
          exit();
        }
        break;
    }

    // Finishing cleaning up URI
    $this->uri = remove_query_arg( ['fcnen-notice', 'fcnen-message'], $this->uri );
  }

  /**
   * Retrieve the column headers for the table
   *
   * @since 0.1.0
   *
   * @return array Associative array of column names with their corresponding labels.
   */

  function get_columns() {
    return array(
      'cb' => '<input type="checkbox" />',
      'post_title' => __( 'Title', 'fcnen' ),
      'status' => __( 'Status', 'fcnen' ),
      'post_author' => __( 'Author', 'fcnen' ),
      'post_id' => __( 'Post ID', 'fcnen' ),
      'post_type' => __( 'Type', 'fcnen' ),
      'added_at' => __( 'Date', 'fcnen' ),
      'last_sent' => __( 'Last Sent', 'fcnen' )
    );
  }

  /**
   * Prepare the items for display in the table
   *
   * @since 0.1.0
   */

  function prepare_items() {
    // Setup
    $columns = $this->get_columns();
    $sortable = $this->get_sortable_columns();
    $primary = 'post_title';

    // Hidden columns?
    $hidden_columns = get_user_meta( get_current_user_id(), 'managetoplevel_page_fcnen-notificationscolumnshidden', true );
    $hidden = is_array( $hidden_columns ) ? $hidden_columns : [];

    // Data
    $this->table_data = $this->get_table_data();
    $this->_column_headers = [ $columns, $hidden, $sortable, $primary ];

    // Post data
    $post_data = [];
    $post_ids = array_column( $this->table_data, 'post_id' );

    $posts = get_posts(
      array(
        'post_type' => ['post', 'fcn_story', 'fcn_chapter'],
        'post__in' => $post_ids ?: [0],
        'numberposts' => -1,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
        'no_found_rows' => true
      )
    );

    foreach ( $posts as $post ) {
      $post_data[ $post->ID ] = array(
        'post_link' => get_permalink( $post ),
        'post_title' => $post->post_title,
        'post_type' => $post->post_type,
        'post_status' => $post->post_status,
        'post_password' => $post->post_password,
        'post_author' => $post->post_author
      );
    }

    // Prime author cache
    if ( function_exists( 'update_post_author_caches' ) ) {
      update_post_author_caches( $posts );
    }

    // Merge datasets
    foreach ( $this->table_data as $key => $notification ) {
      $post_id = $notification['post_id'];

      if ( isset( $post_data[ $post_id ] ) ) {
        $notification = array_merge( $notification, $post_data[ $post_id ] );
      }

      $notification['status'] = fcnen_post_sendable( $post_id, true );

      $notification['last_sent'] = ! empty( $notification['last_sent'] ) ? $notification['last_sent'] : '';

      $this->table_data[ $key ] = $notification;
    }

    // Prepare rows
    $this->items = $this->table_data;
  }

  /**
   * Retrieve the data for the table
   *
   * @since 0.1.0
   * @global wpdb $wpdb  The WordPress database object.
   *
   * @return array The table data with appended post data.
   */

  function get_table_data() {
    global $wpdb;

    // Guard
    if ( ! current_user_can( 'manage_options' ) ) {
      return [];
    }

    // Setup
    $table_name = $wpdb->prefix . 'fcnen_notifications';
    $view_total_items = $this->total_items;
    $per_page = $this->get_items_per_page( 'fcnen_notifications_per_page', 25 );
    $current_page = $this->get_pagenum();
    $offset = ( $per_page * max( 0, absint( $current_page ) - 1 ) );
    $orderby = sanitize_text_field( $_GET['orderby'] ?? 'id' );
    $order = strtolower( $_GET['order'] ?? 'desc' ) === 'desc' ? 'DESC' : 'ASC';

    // Sanitize orderby
    $orderby = in_array( $orderby, ['post_id', 'post_title', 'post_type', 'post_author', 'added_at', 'last_sent'] ) ?
      $orderby : 'added_at';

    // Search?
    if ( ! empty( $_POST['s'] ?? '' ) ) {
      $search = sanitize_text_field( $_POST['s'] );
      $query = "SELECT * FROM {$table_name} WHERE post_title LIKE '%" . $wpdb->esc_like( $search ) . "%'";
    } else {
      $query = "SELECT * FROM {$table_name}";
    }

    // Prepare for extension
    if ( $this->view !== 'all' ) {
      if ( ! strpos( $query, 'WHERE' ) ) {
        $query .= ' WHERE ';
      } else {
        $query .= ' AND ';
      }
    }

    // View
    switch ( $this->view ) {
      case 'paused':
        $query .= "paused = 1";
        $view_total_items = $this->paused_count;
        break;
      case 'sent':
        $query .= "last_sent IS NOT NULL";
        $view_total_items = $this->sent_count;
        break;
      case 'ready':
        $query .= "paused = 0 AND last_sent IS NULL";
        $view_total_items = $this->ready_count;
        break;
    }

    // Order
    $query .= " ORDER BY {$orderby} {$order}";

    // Query
    $notifications = $wpdb->get_results( $wpdb->prepare( "{$query} LIMIT %d OFFSET %d", $per_page, $offset ), ARRAY_A );

    // Pagination
    $this->set_pagination_args(
      array(
        'total_items' => $view_total_items,
        'per_page' => $per_page,
        'total_pages' => ceil( $view_total_items / $per_page )
      )
    );

    // Return results
    return $notifications;
  }

  /**
   * Renders the content of the "cb" column
   *
   * @since 0.1.0
   *
   * @param array $item  The data for the current row item.
   *
   * @return string The "cb" column content.
   */

  function column_cb( $item ) {
    return sprintf( '<input type="checkbox" name="notifications[]" value="%s" />', $item['post_id'] );
  }

  /**
   * Render the content of the "post_title" column
   *
   * @since 0.1.0
   *
   * @param array $item  The data for the current row item.
   *
   * @return string The "post_title" column content.
   */

  function column_post_title( $item ) {
    // Setup
    $actions = [];
    $notes = [];
    $title = '';
    $suffix = '';

    // Chapter?
    if ( $item['post_type'] === 'fcn_chapter' ) {
      $story_id = get_post_meta( $item['post_id'], 'fictioneer_chapter_story', true );
      $story_title = get_the_title( $story_id ) ?: '';

      // Story title as suffix
      if ( ! empty( $story_title ) ) {
        $story_title = mb_strimwidth( $story_title, 0, 25, '…' ); // Truncate to max 24 characters
        $suffix = " &mdash; {$story_title}";
      }

      // Hidden?
      if ( ! empty( get_post_meta( $item['post_id'], 'fictioneer_chapter_hidden', true ) ) ) {
        $notes[] = __( 'Hidden', 'fcnen' );
      }
    }

    // Story?
    if ( $item['post_type'] === 'fcn_story' ) {
      // Hidden?
      if ( ! empty( get_post_meta( $item['post_id'], 'fictioneer_story_hidden', true ) ) ) {
        $notes[] = __( 'Hidden', 'fcnen' );
      }
    }

    // Build title
    if ( $item['post_link'] ?? 0 ) {
      $title = sprintf(
        _x( '<a href="%1$s">%2$s</a> %3$s %4$s', 'Notification list table title column.', 'fcnen' ),
        $item['post_link'],
        mb_strimwidth( trim( $item['post_title'] ), 0, 41, '…' ), // Truncate to max 40 characters
        $suffix,
        empty( $notes ) ? '' : '(' . implode( ', ', $notes ) . ')'
      );
    } else {
      $title = sprintf(
        _x( '<span>%1$s</span> %2$s %3$s', 'Notification list table title column.', 'fcnen' ),
        mb_strimwidth( trim( $item['post_title'] ), 0, 41, '…' ), // Truncate to max 40 characters
        $suffix,
        empty( $notes ) ? '' : '(' . implode( ', ', $notes ) . ')'
      );
    }

    // Unsent action
    if ( ! empty( $item['last_sent'] ) ) {
      $actions['unsent'] = sprintf(
        '<a href="%s">%s</a>',
        wp_nonce_url(
          add_query_arg(
            array( 'action' => 'unsent_notification', 'id' => $item['id'], 'post_id' => $item['post_id'] ),
            $this->uri
          ),
          'fcnen-table-action',
          'fcnen-nonce'
        ),
        __( 'Unsent', 'fcnen' )
      );
    }

    // Pause action
    if ( empty( $item['last_sent'] ) && ! $item['paused'] ) {
      $actions['pause'] = sprintf(
        '<a href="%s">%s</a>',
        wp_nonce_url(
          add_query_arg(
            array( 'action' => 'pause_notification', 'id' => $item['id'], 'post_id' => $item['post_id'] ),
            $this->uri
          ),
          'fcnen-table-action',
          'fcnen-nonce'
        ),
        __( 'Pause', 'fcnen' )
      );
    }

    // Unpause action
    if ( $item['paused'] ) {
      $actions['unpause'] = sprintf(
        '<a href="%s">%s</a>',
        wp_nonce_url(
          add_query_arg(
            array( 'action' => 'unpause_notification', 'id' => $item['id'], 'post_id' => $item['post_id'] ),
            $this->uri
          ),
          'fcnen-table-action',
          'fcnen-nonce'
        ),
        __( 'Unpause', 'fcnen' )
      );
    }

    // Delete action
    $actions['delete'] = sprintf(
      '<a href="%s">%s</a>',
      wp_nonce_url(
        add_query_arg(
          array( 'action' => 'delete_notification', 'id' => $item['id'], 'post_id' => $item['post_id'] ),
          $this->uri
        ),
        'fcnen-table-action',
        'fcnen-nonce'
      ),
      __( 'Remove', 'fcnen' )
    );

    // Return the final output
    return sprintf(
      '<span>%s</span> %s',
      trim( $title ),
      $this->row_actions( $actions )
    );
  }

  /**
   * Render the content of the "post_author" column
   *
   * @since 0.1.0
   *
   * @param array $item  The data for the current row item.
   *
   * @return string The "post_author" column content.
   */

  function column_post_author( $item ) {
    return empty( $item['post_author'] ) ? '&mdash;' : get_the_author_meta( 'display_name', $item['post_author'] ) ;
  }

  /**
   * Render the content of the "status" column
   *
   * @since 0.1.0
   *
   * @param array $item  The data for the current row item.
   *
   * @return string The "status" column content.
   */

  function column_status( $item ) {
    // Setup
    $excluded_posts = get_option( 'fcnen_excluded_posts', [] ) ?: [];
    $excluded_authors = get_option( 'fcnen_excluded_authors', [] ) ?: [];

    // Return correct status label
    if ( $item['last_sent'] ) {
      return _x( 'Sent', 'Notification list table status column.', 'fcnen' );
    }

    if ( $item['paused'] ) {
      return _x( 'Paused', 'Notification list table status column.', 'fcnen' );
    }

    if ( ! get_post( $item['post_id'] ) ) {
      return _x( 'Blocked:<br>Deleted', 'Notification list table status column.', 'fcnen' );
    }

    if ( get_post_status( $item['post_id'] ) === 'trash' ) {
      return _x( 'Blocked:<br>Trashed', 'Notification list table status column.', 'fcnen' );
    }

    if ( in_array( $item['post_author'], $excluded_authors ) ) {
      return _x( 'Blocked:<br>Excluded Author', 'Notification list table status column.', 'fcnen' );
    }

    if ( in_array( $item['post_id'], $excluded_posts ) ) {
      return _x( 'Blocked:<br>Excluded Post', 'Notification list table status column.', 'fcnen' );
    }

    if ( $item['status']['sendable'] ?? 0 ) {
      return _x( 'Ready', 'Notification list table status column.', 'fcnen' );
    }

    $status = '';

    switch ( $item['status']['message'] ) {
      case 'post-unpublished':
        $status = _x( 'Blocked:<br>Unpublished', 'Notification list table status column.', 'fcnen' );
        break;
      case 'post-protected':
        $status = _x( 'Blocked:<br>Protected', 'Notification list table status column.', 'fcnen' );
        break;
      case 'post-invalid-type':
        $status = _x( 'Blocked:<br>Invalid', 'Notification list table status column.', 'fcnen' );
        break;
      case 'post-excluded':
        $status = _x( 'Blocked:<br>Excluded', 'Notification list table status column.', 'fcnen' );
        break;
      case 'post-hidden':
        $status = _x( 'Blocked:<br>Hidden', 'Notification list table status column.', 'fcnen' );
        break;
      default:
        $status = _x( 'Blocked', 'Notification list table status column.', 'fcnen' );
    }

    return $status;
  }

  /**
   * Render the content of the "post_id" column
   *
   * @since 0.1.0
   *
   * @param array $item  The data for the current row item.
   *
   * @return string The "post_id" column content.
   */

  function column_post_id( $item ) {
    return $item['post_id'];
  }

  /**
   * Render the content of the "post_type" column
   *
   * @since 0.1.0
   *
   * @param array $item  The data for the current row item.
   *
   * @return string The "post_type" column content.
   */

  function column_post_type( $item ) {
    $type_object = get_post_type_object( $item['post_type'] );

    return $type_object->labels->singular_name;
  }

  /**
   * Render the content of the "added_at" column
   *
   * @since 0.1.0
   *
   * @param array $item  The data for the current row item.
   *
   * @return string The "added_at" column content.
   */

  function column_added_at( $item ) {
    return __( 'Enqueued', 'fcnen' ) . '<br>' . get_date_from_gmt( $item['added_at'], 'Y-m-d H:i:s' );
  }

  /**
   * Render the content of the "last_sent" column
   *
   * @since 0.1.0
   *
   * @param array $item  The data for the current row item.
   *
   * @return string The "last_sent" column content.
   */

  function column_last_sent( $item ) {
    if ( empty( $item['last_sent'] ) ) {
      return '&mdash;';
    }

    return __( 'Mailed', 'fcnen' ) . '<br>' . get_date_from_gmt( $item['last_sent'], 'Y-m-d H:i:s' );
  }

  /**
   * Retrieve the bulk actions available for the table
   *
   * @since 0.1.0
   *
   * @return array An associative array of bulk actions. The keys represent the action identifiers,
   *               and the values represent the action labels.
   */

  function get_bulk_actions() {
    return array(
      'bulk_pause' => __( 'Pause', 'fcnen' ),
      'bulk_unpause' => __( 'Unpause', 'fcnen' ),
      'bulk_unsent' => __( 'Unsent', 'fcnen' ),
      'bulk_delete' => __( 'Remove', 'fcnen' )
    );
  }

  /**
   * Render extra content in the table navigation section
   *
   * @since 0.1.0
   *
   * @param string $which  The position of the navigation, either "top" or "bottom".
   */

  function extra_tablenav( $which ) {
    if ( $this->total_items > 0 ) {
      // Start HTML ---> ?>
      <div class="alignleft actions">
        <?php
          printf(
            '<a href="%s" class="button action">%s</a>',
            wp_nonce_url(
              add_query_arg(
                array( 'action' => 'remove_sent' ),
                remove_query_arg( ['paged'], $this->uri )
              ),
              'fcnen-table-action',
              'fcnen-nonce'
            ),
            __( 'Remove Sent', 'fcnen' )
          );
        ?>
      </div>
      <?php // <--- End HTML
    }
  }

  /**
   * Display the views for filtering the table
   *
   * @since 0.1.0
   */

  function display_views() {
    // Guard
    if ( ! current_user_can( 'manage_options' ) ) {
      echo '';
      return;
    }

    // Setup
    $views = [];
    $current = 'all';
    $uri = remove_query_arg( ['paged'], $this->uri );

    // Current
    if ( ! empty( $this->view ) ) {
      switch ( $this->view ) {
        case 'paused':
          $current = 'paused';
          break;
        case 'sent':
          $current = 'sent';
          break;
        case 'ready':
          $current = 'ready';
          break;
        default:
          $current = 'all';
      }
    }

    // Build views HTML
    $views['all'] = sprintf(
      '<li class="all"><a href="%s" class="%s">%s</a></li>',
      add_query_arg( array( 'view' => 'all' ), $uri ),
      $current === 'all' ? 'current' : '',
      sprintf( __( 'All <span class="count">(%s)</span>', 'fcnen' ), $this->total_items )
    );

    if ( $this->ready_count > 0 ) {
      $views['ready'] = sprintf(
        '<li class="ready"><a href="%s" class="%s">%s</a></li>',
        add_query_arg( array( 'view' => 'ready' ), $uri ),
        $current === 'ready' ? 'current' : '',
        sprintf( __( 'Ready <span class="count">(%s)</span>', 'fcnen' ), $this->ready_count )
      );
    }

    if ( $this->paused_count > 0 ) {
      $views['paused'] = sprintf(
        '<li class="paused"><a href="%s" class="%s">%s</a></li>',
        add_query_arg( array( 'view' => 'paused' ), $uri ),
        $current === 'paused' ? 'current' : '',
        sprintf( __( 'Paused <span class="count">(%s)</span>', 'fcnen' ), $this->paused_count )
      );
    }

    if ( $this->sent_count > 0 ) {
      $views['sent'] = sprintf(
        '<li class="sent"><a href="%s" class="%s">%s</a></li>',
        add_query_arg( array( 'view' => 'sent' ), $uri ),
        $current === 'sent' ? 'current' : '',
        sprintf( __( 'Sent <span class="count">(%s)</span>', 'fcnen' ), $this->sent_count )
      );
    }

    // Output final HTML
    echo '<ul class="subsubsub">' . implode( ' | ', $views ) . '</ul>';
  }

  /**
   * Perform actions based on the GET and POST requests
   *
   * @since 0.1.0
   * @global wpdb $wpdb  The WordPress database object.
   */

  function perform_actions() {
    global $wpdb;

    // Guard
    if ( ! current_user_can( 'manage_options' ) ) {
      return;
    }

    // Setup
    $table_name = $wpdb->prefix . 'fcnen_notifications';
    $query_args = [];

    // GET actions
    if ( isset( $_GET['action'] ) ) {
      $id = absint( $_GET['id'] ?? 0 );
      $post_id = absint( $_GET['post_id'] ?? 0 );
      $post = get_post( $post_id );
      $title = empty( $post ) ? __( 'UNAVAILABLE', 'fcnen' ) : $post->post_title;

      // Remove sent notifications
      if ( $_GET['action'] === 'remove_sent' ) {
        if ( $wpdb->query( $wpdb->prepare( "DELETE FROM $table_name WHERE last_sent IS NOT NULL" ) ) ) {
          $query_args['fcnen-notice'] = 'remove-sent-notification-success';
          fcnen_log( 'Removed sent notification.' );
        } else {
          $query_args['fcnen-notice'] = 'remove-sent-notification-failure';
        }
      }

      // Abort if...
      if ( empty( $post_id ) ) {
        wp_safe_redirect( add_query_arg( $query_args, $this->uri ) );
        exit();
      }

      // Delete notifications
      if ( $_GET['action'] === 'delete_notification' ) {
        if ( $wpdb->delete( $table_name, array( 'id' => $id ), ['%d'] ) ) {
          $query_args['fcnen-notice'] = 'delete-notification-success';
          fcnen_log( "Deleted notification for \"{$title}\" (#{$post_id})." );
        } else {
          $query_args['fcnen-notice'] = 'delete-notification-failure';
        }

        $query_args['fcnen-message'] = $post_id;
      }

      // Pause notifications
      if ( $_GET['action'] === 'pause_notification' ) {
        if ( $wpdb->update( $table_name, array( 'paused' => 1 ), array( 'id' => $id ), ['%d'], ['%d'] ) ) {
          $query_args['fcnen-notice'] = 'paused-notification-success';
          fcnen_log( "Paused notification for \"{$title}\" (#{$post_id})." );
        } else {
          $query_args['fcnen-notice'] = 'paused-notification-failure';
        }

        $query_args['fcnen-message'] = $post_id;
      }

      // Unpause notifications
      if ( $_GET['action'] === 'unpause_notification' ) {
        if ( $wpdb->update( $table_name, array( 'paused' => 0 ), array( 'id' => $id ), ['%d'], ['%d'] ) ) {
          $query_args['fcnen-notice'] = 'unpaused-notification-success';
          fcnen_log( "Unpaused notification for \"{$title}\" (#{$post_id})." );
        } else {
          $query_args['fcnen-notice'] = 'unpaused-notification-failure';
        }

        $query_args['fcnen-message'] = $post_id;
      }

      // Unsent notifications
      if ( $_GET['action'] === 'unsent_notification' ) {
        if ( $wpdb->update( $table_name, array( 'last_sent' => null ), array( 'id' => $id ) ) ) {
          $query_args['fcnen-notice'] = 'unsent-notification-success';
          fcnen_log( "Marked notification for \"{$title}\" (#{$post_id}) as unsent." );
        } else {
          $query_args['fcnen-notice'] = 'unsent-notification-failure';
        }

        $query_args['fcnen-message'] = $post_id;
      }

      // Redirect with notice (prevents multi-submit)
      wp_safe_redirect( add_query_arg( $query_args, $this->uri ) );
      exit();
    }

    // POST actions
    if ( isset( $_POST['action'] ) && empty( $_POST['s'] ?? 0 ) ) {
      $ids = array_map( 'absint', $_POST['notifications'] ?? [] );
      $collection = implode( ',', $ids );
      $log_ids = implode( ', ', array_map( function( $id ) { return "#{$id}"; }, $ids ) );

      // Abort if...
      if ( empty( $collection ) ) {
        wp_safe_redirect( add_query_arg( $query_args, $this->uri ) );
        exit();
      }

      // Bulk delete notifications
      if ( $_POST['action'] === 'bulk_delete' ) {
        $query = "DELETE FROM $table_name WHERE post_id IN ($collection)";
        $result = $wpdb->query( $query );

        if ( $result !== false ) {
          $query_args['fcnen-notice'] = 'bulk-delete-notifications-success';
          $query_args['fcnen-message'] = $result;
          fcnen_log( "Deleted set of notifications: $log_ids." );
        } else {
          $query_args['fcnen-notice'] = 'bulk-delete-notifications-failure';
        }
      }

      // Bulk unsent notifications
      if ( $_POST['action'] === 'bulk_unsent' ) {
        $query = "UPDATE $table_name SET last_sent = NULL WHERE post_id IN ($collection)";
        $result = $wpdb->query( $query );

        if ( $result !== false ) {
          $query_args['fcnen-notice'] = 'bulk-unsent-notifications-success';
          $query_args['fcnen-message'] = $result;
          fcnen_log( "Marked set of notifications as unsent: $log_ids." );
        } else {
          $query_args['fcnen-notice'] = 'bulk-unsent-notifications-failure';
        }
      }

      // Bulk pause notifications
      if ( $_POST['action'] === 'bulk_pause' ) {
        $query = "UPDATE $table_name SET paused = 1 WHERE post_id IN ($collection)";
        $result = $wpdb->query( $query );

        if ( $result !== false ) {
          $query_args['fcnen-notice'] = 'bulk-pause-notifications-success';
          $query_args['fcnen-message'] = $result;
          fcnen_log( "Paused set of notifications: $log_ids." );
        } else {
          $query_args['fcnen-notice'] = 'bulk-pause-notifications-failure';
        }
      }

      // Bulk unpause notifications
      if ( $_POST['action'] === 'bulk_unpause' ) {
        $query = "UPDATE $table_name SET paused = 0 WHERE post_id IN ($collection)";
        $result = $wpdb->query( $query );

        if ( $result !== false ) {
          $query_args['fcnen-notice'] = 'bulk-unpause-notifications-success';
          $query_args['fcnen-message'] = $result;
          fcnen_log( "Unpaused set of notifications: $log_ids." );
        } else {
          $query_args['fcnen-notice'] = 'bulk-unpause-notifications-failure';
        }
      }

      // Redirect with notice (prevents multi-submit)
      wp_safe_redirect( add_query_arg( $query_args, $this->uri ) );
      exit();
    }
  }

  /**
   * Retrieve the sortable columns for the table
   *
   * @since 0.1.0
   *
   * @return array An associative array of sortable columns and their sort parameters.
   *               The keys represent the column names, and the values are arrays
   *               with the column key and sort order (true for ascending, false for descending).
   */

  protected function get_sortable_columns() {
    return array(
      'post_title' => ['post_title', false],
      'added_at' => ['added_at', false],
      'last_sent' => ['last_sent', false]
    );
  }
}
