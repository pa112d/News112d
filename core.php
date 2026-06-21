<?php
// Sécurité
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Ajouter un intervalle cron personnalisé
 */
add_filter( 'cron_schedules', function ( $s ) {
    if ( ! isset( $s['pne_min'] ) ) {
        $s['pne_min'] = array( 'interval' => 60, 'display' => __( 'Every minute', 'pne' ) );
    }
    return $s;
} );

/**
 * Planifier l'événement si nécessaire (activation gère aussi cela)
 */
add_action( 'init', function () {
    if ( ! wp_next_scheduled( 'pne_send' ) ) {
        wp_schedule_event( time(), 'pne_min', 'pne_send' );
    }
} );

/**
 * Add main PNE menu and submenus
 */
add_action( 'admin_menu', function () {
    // Add main menu entry
    add_menu_page(
        __( 'PNE - Newsletter', 'pne' ),
        __( 'Newsletter', 'pne' ),
        'manage_options',
        'pne',
        function () {
            echo '<div class="wrap"><h1>' . esc_html__( 'Newsletter Manager', 'pne' ) . '</h1></div>';
        },
        'dashicons-email-alt',
        25
    );
    
    // Add submenu: Lists
    add_submenu_page( 'pne', __( 'Lists', 'pne' ), __( 'Lists', 'pne' ), 'manage_options', 'pne-lists', 'pne_lists_ui' );
    
    // Add submenu: Logs
    add_submenu_page( 'pne', __( 'Logs', 'pne' ), __( 'Logs', 'pne' ), 'manage_options', 'pne-logs', 'pne_logs_ui' );
} );

/**
 * Admin post handler: send test campaign
 */
add_action( 'admin_post_pne_send_test', function () {
    if ( ! isset( $_GET['campaign_id'] ) ) wp_die( 'Missing campaign_id' );
    $campaign_id = intval( $_GET['campaign_id'] );
    
    // Verify nonce
    $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
    if ( ! wp_verify_nonce( $nonce, 'pne_send_test_' . $campaign_id ) ) {
        wp_die( 'Invalid nonce' );
    }
    
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'No permission' );

    global $wpdb;
    $campaign = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pne_campaigns WHERE id = %d", $campaign_id ) );
    
    if ( ! $campaign ) wp_die( 'Campaign not found' );

    // prepare test recipients
    $test_emails = get_option( 'pne_test_emails', '' );
    $emails = array();
    
    if ( $test_emails ) {
        $parts = array_map( 'trim', explode( ',', $test_emails ) );
        foreach ( $parts as $p ) if ( is_email( $p ) ) $emails[] = $p;
    }
    
    if ( empty( $emails ) ) {
        $current_user = wp_get_current_user();
        if ( is_email( $current_user->user_email ) ) $emails[] = $current_user->user_email;
    }
    $emails = array_unique( $emails );

    foreach ( $emails as $em ) {
        $wpdb->insert( "{$wpdb->prefix}pne_queue", array(
            'campaign_id' => $campaign_id,
            'email' => $em,
            'from_email' => '',
            'from_name' => '',
            'status' => 'pending',
        ), array( '%d', '%s', '%s', '%s', '%s' ) );
    }

    wp_redirect( admin_url( 'admin.php?page=pne&test_sent=1' ) );
    exit;
} );

/**
 * Admin post handler: promote campaign to full send
 */
add_action( 'admin_post_pne_promote_campaign', function () {
    if ( ! isset( $_GET['campaign_id'] ) ) wp_die( 'Missing campaign_id' );
    $campaign_id = intval( $_GET['campaign_id'] );
    
    // Verify nonce
    $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
    if ( ! wp_verify_nonce( $nonce, 'pne_promote_' . $campaign_id ) ) {
        wp_die( 'Invalid nonce' );
    }
    
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'No permission' );

    global $wpdb;
    $campaign = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pne_campaigns WHERE id = %d", $campaign_id ) );
    
    if ( ! $campaign ) wp_die( 'Campaign not found' );

    // set campaign status to running
    $wpdb->update( "{$wpdb->prefix}pne_campaigns", array( 'status' => 'running' ), array( 'id' => $campaign_id ), array( '%s' ), array( '%d' ) );

    // Get emails from all users
    $users = get_users();
    $emails = wp_list_pluck( $users, 'user_email' );
    $emails = array_filter( array_unique( $emails ), 'is_email' );

    foreach ( $emails as $em ) {
        $wpdb->insert( "{$wpdb->prefix}pne_queue", array(
            'campaign_id' => $campaign_id,
            'email' => $em,
            'from_email' => '',
            'from_name' => '',
            'status' => 'pending',
        ), array( '%d', '%s', '%s', '%s', '%s' ) );
    }

    wp_redirect( admin_url( 'admin.php?page=pne&promoted=1' ) );
    exit;
} );

/**
 * Get recipients safely (compat function)
 */
function pne_get_recipients() {
    $users = get_users();
    $list  = wp_list_pluck( $users, 'user_email' );
    $list = array_filter( array_unique( $list ), 'is_email' );
    return $list;
}

/**
 * Campaigns Management UI
 */
function pne_campaigns_ui() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    global $wpdb;
    
    $paged = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
    $per_page = 20;
    $offset = ( $paged - 1 ) * $per_page;

    // Fetch campaigns
    $total = intval( $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}pne_campaigns" ) );
    $campaigns = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}pne_campaigns ORDER BY created_at DESC LIMIT %d OFFSET %d",
        $per_page,
        $offset
    ) );

    $total_pages = (int) ceil( $total / $per_page );
    ?>
    <div class="wrap">
        <h1><?php echo esc_html__( 'Campaigns', 'pne' ); ?></h1>

        <table class="widefat fixed striped">
            <thead>
                <tr>
                    <th><?php echo esc_html__( 'ID', 'pne' ); ?></th>
                    <th><?php echo esc_html__( 'Subject', 'pne' ); ?></th>
                    <th><?php echo esc_html__( 'Status', 'pne' ); ?></th>
                    <th><?php echo esc_html__( 'Created', 'pne' ); ?></th>
                    <th><?php echo esc_html__( 'Actions', 'pne' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $campaigns ) ) : ?>
                    <tr><td colspan="5"><?php echo esc_html__( 'No campaigns yet.', 'pne' ); ?></td></tr>
                <?php else : ?>
                    <?php foreach ( $campaigns as $c ) : ?>
                        <tr>
                            <td><?php echo intval( $c->id ); ?></td>
                            <td><?php echo esc_html( substr( $c->subject, 0, 50 ) ); ?></td>
                            <td><?php echo esc_html( $c->status ); ?></td>
                            <td><?php echo esc_html( $c->created_at ); ?></td>
                            <td>
                                <?php
                                if ( $c->status === 'testing' ) {
                                    $send_url = wp_nonce_url( admin_url( 'admin-post.php?action=pne_send_test&campaign_id=' . $c->id ), 'pne_send_test_' . $c->id );
                                    echo '<a href="' . esc_url( $send_url ) . '" class="button button-small">' . esc_html__( 'Send Test', 'pne' ) . '</a> ';
                                    
                                    $promote_url = wp_nonce_url( admin_url( 'admin-post.php?action=pne_promote_campaign&campaign_id=' . $c->id ), 'pne_promote_' . $c->id );
                                    echo '<a href="' . esc_url( $promote_url ) . '" class="button button-small button-primary">' . esc_html__( 'Promote', 'pne' ) . '</a>';
                                }
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if ( $total_pages > 1 ) : ?>
            <div class="tablenav">
                <div class="tablenav-pages">
                    <?php
                    $base = add_query_arg( array( 'page' => 'pne', 'paged' => '%#%' ) );
                    echo paginate_links( array(
                        'base' => $base,
                        'format' => '',
                        'current' => $paged,
                        'total' => $total_pages,
                    ) );
                    ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Mailing Lists Management UI
 */
function pne_lists_ui() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    global $wpdb;
    $action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : '';
    $list_id = isset( $_GET['list_id'] ) ? intval( $_GET['list_id'] ) : 0;

    // Handle list deletion
    if ( $action === 'delete' && $list_id ) {
        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'pne_delete_list_' . $list_id ) ) wp_die( 'Invalid nonce' );
        $wpdb->delete( "{$wpdb->prefix}pne_mailing_lists", array( 'id' => $list_id ), array( '%d' ) );
        $wpdb->delete( "{$wpdb->prefix}pne_list_subscribers", array( 'list_id' => $list_id ), array( '%d' ) );
        wp_redirect( admin_url( 'admin.php?page=pne-lists&deleted=1' ) );
        exit;
    }

    // Edit list
    if ( $action === 'edit' && $list_id ) {
        $list = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pne_mailing_lists WHERE id = %d", $list_id ) );
        if ( ! $list ) wp_die( 'List not found' );
        $subscribers = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pne_list_subscribers WHERE list_id = %d ORDER BY email ASC", $list_id ) );
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'Edit List', 'pne' ); ?></h1>
            
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'pne_update_list' ); ?>
                <input type="hidden" name="action" value="pne_update_list">
                <input type="hidden" name="list_id" value="<?php echo esc_attr( $list->id ); ?>">
                
                <table class="form-table">
                    <tr>
                        <th><label><?php esc_html_e( 'List Name', 'pne' ); ?></label></th>
                        <td><input type="text" name="list_name" value="<?php echo esc_attr( $list->name ); ?>" required style="width:100%;max-width:500px;"></td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e( 'Description', 'pne' ); ?></label></th>
                        <td><textarea name="list_description" style="width:100%;max-width:500px;height:80px;"><?php echo esc_textarea( $list->description ); ?></textarea></td>
                    </tr>
                </table>
                
                <h2><?php esc_html_e( 'Subscribers', 'pne' ); ?> (<?php echo count( $subscribers ); ?>)</h2>
                
                <h3><?php esc_html_e( 'Add/Import Emails', 'pne' ); ?></h3>
                <textarea name="import_emails" style="width:100%;max-width:800px;height:100px;" placeholder="email1@example.com&#10;email2@example.com&#10;email3@example.com"></textarea>
                <p><small><?php esc_html_e( 'One email per line. New emails will be added to the list.', 'pne' ); ?></small></p>
                
                <h3><?php esc_html_e( 'Current Subscribers', 'pne' ); ?></h3>
                <table class="widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Email', 'pne' ); ?></th>
                            <th><?php esc_html_e( 'Added', 'pne' ); ?></th>
                            <th><?php esc_html_e( 'Action', 'pne' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $subscribers ) ) : ?>
                            <tr><td colspan="3"><?php esc_html_e( 'No subscribers yet.', 'pne' ); ?></td></tr>
                        <?php else : ?>
                            <?php foreach ( $subscribers as $sub ) : ?>
                                <tr>
                                    <td><?php echo esc_html( $sub->email ); ?></td>
                                    <td><?php echo esc_html( $sub->created_at ); ?></td>
                                    <td>
                                        <?php
                                        $del_url = wp_nonce_url( admin_url( 'admin-post.php?action=pne_delete_subscriber&subscriber_id=' . $sub->id ), 'pne_delete_subscriber_' . $sub->id );
                                        echo '<a href="' . esc_url( $del_url ) . '" class="button button-small button-link-delete">' . esc_html__( 'Remove', 'pne' ) . '</a>';
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                
                <p style="margin-top:20px;">
                    <button type="submit" class="button button-primary"><?php esc_html_e( 'Save Changes', 'pne' ); ?></button>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=pne-lists' ) ); ?>" class="button"><?php esc_html_e( 'Back', 'pne' ); ?></a>
                </p>
            </form>
        </div>
        <?php
        return;
    }

    // List all mailing lists
    $lists = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}pne_mailing_lists ORDER BY created_at DESC" );
    ?>
    <div class="wrap">
        <h1><?php echo esc_html__( 'Mailing Lists', 'pne' ); ?></h1>
        
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:20px;">
            <?php wp_nonce_field( 'pne_create_list' ); ?>
            <input type="hidden" name="action" value="pne_create_list">
            
            <input type="text" name="list_name" placeholder="<?php esc_attr_e( 'New list name...', 'pne' ); ?>" required style="padding:8px;margin-right:10px;">
            <button type="submit" class="button button-primary"><?php esc_html_e( 'Create List', 'pne' ); ?></button>
        </form>
        
        <table class="widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Name', 'pne' ); ?></th>
                    <th><?php esc_html_e( 'Description', 'pne' ); ?></th>
                    <th><?php esc_html_e( 'Subscribers', 'pne' ); ?></th>
                    <th><?php esc_html_e( 'Created', 'pne' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'pne' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $lists ) ) : ?>
                    <tr><td colspan="5"><?php esc_html_e( 'No mailing lists yet.', 'pne' ); ?></td></tr>
                <?php else : ?>
                    <?php foreach ( $lists as $list ) : 
                        $count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}pne_list_subscribers WHERE list_id = %d", $list->id ) );
                    ?>
                        <tr>
                            <td><strong><?php echo esc_html( $list->name ); ?></strong></td>
                            <td><?php echo esc_html( substr( $list->description, 0, 50 ) ); ?></td>
                            <td><?php echo intval( $count ); ?></td>
                            <td><?php echo esc_html( $list->created_at ); ?></td>
                            <td>
                                <?php
                                $edit_url = admin_url( 'admin.php?page=pne-lists&action=edit&list_id=' . $list->id );
                                $delete_url = wp_nonce_url( admin_url( 'admin.php?page=pne-lists&action=delete&list_id=' . $list->id ), 'pne_delete_list_' . $list->id );
                                echo '<a href="' . esc_url( $edit_url ) . '" class="button">' . esc_html__( 'Edit', 'pne' ) . '</a> ';
                                echo '<a href="' . esc_url( $delete_url ) . '" class="button button-link-delete">' . esc_html__( 'Delete', 'pne' ) . '</a>';
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

/**
 * Admin post handler: create new list
 */
add_action( 'admin_post_pne_create_list', function () {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );
    
    $nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
    if ( ! wp_verify_nonce( $nonce, 'pne_create_list' ) ) wp_die( 'Invalid nonce' );
    
    $list_name = isset( $_POST['list_name'] ) ? sanitize_text_field( wp_unslash( $_POST['list_name'] ) ) : '';
    if ( empty( $list_name ) ) wp_die( 'List name required' );
    
    global $wpdb;
    $wpdb->insert( "{$wpdb->prefix}pne_mailing_lists", array(
        'name' => $list_name,
        'description' => '',
        'created_at' => current_time( 'mysql', 1 ),
    ), array( '%s', '%s', '%s' ) );
    
    $list_id = $wpdb->insert_id;
    wp_redirect( admin_url( 'admin.php?page=pne-lists&action=edit&list_id=' . $list_id ) );
    exit;
} );

/**
 * Admin post handler: update list
 */
add_action( 'admin_post_pne_update_list', function () {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );
    
    $nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
    if ( ! wp_verify_nonce( $nonce, 'pne_update_list' ) ) wp_die( 'Invalid nonce' );
    
    $list_id = isset( $_POST['list_id'] ) ? intval( $_POST['list_id'] ) : 0;
    $list_name = isset( $_POST['list_name'] ) ? sanitize_text_field( wp_unslash( $_POST['list_name'] ) ) : '';
    $list_description = isset( $_POST['list_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['list_description'] ) ) : '';
    $import_emails = isset( $_POST['import_emails'] ) ? sanitize_textarea_field( wp_unslash( $_POST['import_emails'] ) ) : '';
    
    if ( empty( $list_id ) || empty( $list_name ) ) wp_die( 'Invalid data' );
    
    global $wpdb;
    $wpdb->update( "{$wpdb->prefix}pne_mailing_lists", array(
        'name' => $list_name,
        'description' => $list_description,
    ), array( 'id' => $list_id ), array( '%s', '%s' ), array( '%d' ) );
    
    // Import emails
    if ( ! empty( $import_emails ) ) {
        $emails = array_map( 'trim', explode( "\n", $import_emails ) );
        $emails = array_filter( $emails, 'is_email' );
        $emails = array_unique( $emails );
        
        foreach ( $emails as $email ) {
            // Check if already exists
            $exists = $wpdb->get_var( $wpdb->prepare( 
                "SELECT id FROM {$wpdb->prefix}pne_list_subscribers WHERE list_id = %d AND email = %s", 
                $list_id, $email 
            ) );
            
            if ( ! $exists ) {
                $wpdb->insert( "{$wpdb->prefix}pne_list_subscribers", array(
                    'list_id' => $list_id,
                    'email' => $email,
                    'created_at' => current_time( 'mysql', 1 ),
                ), array( '%d', '%s', '%s' ) );
            }
        }
    }
    
    wp_redirect( admin_url( 'admin.php?page=pne-lists&action=edit&list_id=' . $list_id . '&updated=1' ) );
    exit;
} );

/**
 * Admin post handler: delete subscriber
 */
add_action( 'admin_post_pne_delete_subscriber', function () {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );
    
    $subscriber_id = isset( $_GET['subscriber_id'] ) ? intval( $_GET['subscriber_id'] ) : 0;
    $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
    if ( ! wp_verify_nonce( $nonce, 'pne_delete_subscriber_' . $subscriber_id ) ) wp_die( 'Invalid nonce' );
    
    global $wpdb;
    $subscriber = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pne_list_subscribers WHERE id = %d", $subscriber_id ) );
    
    if ( ! $subscriber ) wp_die( 'Subscriber not found' );
    
    $wpdb->delete( "{$wpdb->prefix}pne_list_subscribers", array( 'id' => $subscriber_id ), array( '%d' ) );
    
    wp_redirect( admin_url( 'admin.php?page=pne-lists&action=edit&list_id=' . $subscriber->list_id . '&removed=1' ) );
    exit;
} );

/**
 * Admin UI: Logs list with filters, export and purge
 */
function pne_logs_ui() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    global $wpdb;

    // Filters
    $s_campaign = isset( $_GET['campaign_id'] ) ? intval( $_GET['campaign_id'] ) : 0;
    $s_email = isset( $_GET['email'] ) ? sanitize_text_field( wp_unslash( $_GET['email'] ) ) : '';
    $s_level = isset( $_GET['level'] ) ? sanitize_text_field( wp_unslash( $_GET['level'] ) ) : '';
    $s_from = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '';
    $s_to = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '';

    $paged = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
    $per_page = 30;
    $offset = ( $paged - 1 ) * $per_page;

    $where = array();
    $params = array();
    if ( $s_campaign ) {
        $where[] = 'campaign_id = %d'; $params[] = $s_campaign;
    }
    if ( $s_email ) {
        $where[] = 'email LIKE %s'; $params[] = '%' . $wpdb->esc_like( $s_email ) . '%';
    }
    if ( $s_level ) {
        $where[] = 'level = %s'; $params[] = $s_level;
    }
    if ( $s_from ) {
        $where[] = 'created_at >= %s'; $params[] = $s_from . ' 00:00:00';
    }
    if ( $s_to ) {
        $where[] = 'created_at <= %s'; $params[] = $s_to . ' 23:59:59';
    }

    $where_sql = '';
    if ( ! empty( $where ) ) {
        $where_sql = 'WHERE ' . implode( ' AND ', $where );
    }

    // Count
    $count_sql = "SELECT COUNT(*) FROM {$wpdb->prefix}pne_logs " . $where_sql;
    $count_query = $wpdb->prepare( $count_sql, $params );
    $total = intval( $wpdb->get_var( $count_query ) );

    // Fetch
    $sql = "SELECT * FROM {$wpdb->prefix}pne_logs " . $where_sql . " ORDER BY created_at DESC LIMIT %d OFFSET %d";
    $params_with_limit = $params;
    $params_with_limit[] = $per_page; $params_with_limit[] = $offset;
    $prepared = $wpdb->prepare( $sql, $params_with_limit );
    $rows = $wpdb->get_results( $prepared );

    $total_pages = (int) ceil( $total / $per_page );
    ?>
    <div class="wrap">
        <h1><?php echo esc_html__( 'PNE Logs', 'pne' ); ?></h1>

        <form method="get" style="margin-bottom:12px">
            <input type="hidden" name="page" value="pne-logs">
            <input type="text" name="campaign_id" placeholder="<?php echo esc_attr__( 'Campaign ID', 'pne' ); ?>" value="<?php echo esc_attr( $s_campaign ); ?>">
            <input type="text" name="email" placeholder="<?php echo esc_attr__( 'Email', 'pne' ); ?>" value="<?php echo esc_attr( $s_email ); ?>">
            <select name="level">
                <option value=""><?php echo esc_html__( 'Any level', 'pne' ); ?></option>
                <option value="error" <?php selected( $s_level, 'error' ); ?>><?php echo esc_html__( 'Error', 'pne' ); ?></option>
                <option value="warning" <?php selected( $s_level, 'warning' ); ?>><?php echo esc_html__( 'Warning', 'pne' ); ?></option>
                <option value="info" <?php selected( $s_level, 'info' ); ?>><?php echo esc_html__( 'Info', 'pne' ); ?></option>
            </select>
            <label><?php echo esc_html__( 'From', 'pne' ); ?></label>
            <input type="date" name="from" value="<?php echo esc_attr( $s_from ); ?>">
            <label><?php echo esc_html__( 'To', 'pne' ); ?></label>
            <input type="date" name="to" value="<?php echo esc_attr( $s_to ); ?>">
            <button class="button" type="submit"><?php echo esc_html__( 'Filter', 'pne' ); ?></button>
            <?php
            $export_url = wp_nonce_url( add_query_arg( $_GET, admin_url( 'admin-post.php?action=pne_export_logs' ) ), 'pne_export_logs' );
            echo ' <a class="button" href="' . esc_url( $export_url ) . '">' . esc_html__( 'Export CSV', 'pne' ) . '</a>';
            ?>
        </form>

        <table class="widefat fixed striped">
            <thead>
                <tr>
                    <th><?php echo esc_html__( 'ID', 'pne' ); ?></th>
                    <th><?php echo esc_html__( 'Date', 'pne' ); ?></th>
                    <th><?php echo esc_html__( 'Campaign ID', 'pne' ); ?></th>
                    <th><?php echo esc_html__( 'Queue ID', 'pne' ); ?></th>
                    <th><?php echo esc_html__( 'Email', 'pne' ); ?></th>
                    <th><?php echo esc_html__( 'Level', 'pne' ); ?></th>
                    <th><?php echo esc_html__( 'Message', 'pne' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $rows ) ) : ?>
                    <tr><td colspan="7"><?php echo esc_html__( 'No logs.', 'pne' ); ?></td></tr>
                <?php else : ?>
                    <?php foreach ( $rows as $r ) : ?>
                        <tr>
                            <td><?php echo intval( $r->id ); ?></td>
                            <td><?php echo esc_html( $r->created_at ); ?></td>
                            <td><?php echo esc_html( $r->campaign_id ); ?></td>
                            <td><?php echo esc_html( $r->queue_id ); ?></td>
                            <td><?php echo esc_html( $r->email ); ?></td>
                            <td><?php echo esc_html( $r->level ); ?></td>
                            <td><?php echo esc_html( $r->message ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if ( $total_pages > 1 ) : ?>
            <div class="tablenav">
                <div class="tablenav-pages">
                    <?php
                    $base = add_query_arg( array( 'page' => 'pne-logs', 'paged' => '%#%' ) );
                    echo paginate_links( array(
                        'base' => $base,
                        'format' => '',
                        'current' => $paged,
                        'total' => $total_pages,
                    ) );
                    ?>
                </div>
            </div>
        <?php endif; ?>

        <h2><?php echo esc_html__( 'Purge logs', 'pne' ); ?></h2>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'pne_purge_logs' ); ?>
            <input type="hidden" name="action" value="pne_purge_logs">
            <label><?php echo esc_html__( 'Purge logs older than (days)', 'pne' ); ?></label>
            <input type="number" name="days" value="30" min="1">
            <button class="button button-secondary" type="submit"><?php echo esc_html__( 'Purge', 'pne' ); ?></button>
        </form>

    </div>
    <?php
}

/**
 * Export logs as CSV (admin_post)
 */
add_action( 'admin_post_pne_export_logs', function () {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );
    
    $nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
    if ( ! wp_verify_nonce( $nonce, 'pne_export_logs' ) ) wp_die( 'Invalid nonce' );

    global $wpdb;
    $where = array(); $params = array();
    if ( isset( $_GET['campaign_id'] ) && $_GET['campaign_id'] ) { $where[] = 'campaign_id = %d'; $params[] = intval( $_GET['campaign_id'] ); }
    if ( isset( $_GET['email'] ) && $_GET['email'] ) { $where[] = 'email LIKE %s'; $params[] = '%' . $wpdb->esc_like( sanitize_text_field( wp_unslash( $_GET['email'] ) ) ) . '%'; }
    if ( isset( $_GET['level'] ) && $_GET['level'] ) { $where[] = 'level = %s'; $params[] = sanitize_text_field( wp_unslash( $_GET['level'] ) ); }
    if ( isset( $_GET['from'] ) && $_GET['from'] ) { $where[] = 'created_at >= %s'; $params[] = sanitize_text_field( wp_unslash( $_GET['from'] ) ) . ' 00:00:00'; }
    if ( isset( $_GET['to'] ) && $_GET['to'] ) { $where[] = 'created_at <= %s'; $params[] = sanitize_text_field( wp_unslash( $_GET['to'] ) ) . ' 23:59:59'; }
    $where_sql = '';
    if ( ! empty( $where ) ) $where_sql = 'WHERE ' . implode( ' AND ', $where );

    $sql = "SELECT * FROM {$wpdb->prefix}pne_logs " . $where_sql . " ORDER BY created_at DESC";
    $prepared = $wpdb->prepare( $sql, $params );
    $rows = $wpdb->get_results( $prepared );

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=pne-logs-' . date('Y-m-d') . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv( $out, array( 'id', 'created_at', 'campaign_id', 'queue_id', 'email', 'level', 'message' ) );
    foreach ( $rows as $r ) {
        fputcsv( $out, array( $r->id, $r->created_at, $r->campaign_id, $r->queue_id, $r->email, $r->level, $r->message ) );
    }
    fclose( $out );
    exit;
} );

/**
 * Purge logs older than N days (admin_post)
 */
add_action( 'admin_post_pne_purge_logs', function () {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );
    
    $nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
    if ( ! wp_verify_nonce( $nonce, 'pne_purge_logs' ) ) wp_die( 'Invalid nonce' );
    
    $days = isset( $_POST['days'] ) ? max( 1, intval( $_POST['days'] ) ) : 30;
    $cutoff = date( 'Y-m-d H:i:s', strtotime( '-' . $days . ' days' ) );

    global $wpdb;
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}pne_logs WHERE created_at < %s", $cutoff ) );

    wp_redirect( admin_url( 'admin.php?page=pne-logs&purged=1' ) );
    exit;
} );
