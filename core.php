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
    if ( ! wp_next_scheduled( 'pne_process_news' ) ) {
        // daily processing of scheduled news
        wp_schedule_event( time(), 'daily', 'pne_process_news' );
    }
} );

/**
 * Register Custom Post Type: PNE News
 */
add_action( 'init', function () {
    $labels = array(
        'name' => __( 'PNE News', 'pne' ),
        'singular_name' => __( 'PNE News', 'pne' ),
        'add_new_item' => __( 'Add New News', 'pne' ),
        'edit_item' => __( 'Edit News', 'pne' ),
        'new_item' => __( 'New News', 'pne' ),
        'view_item' => __( 'View News', 'pne' ),
        'search_items' => __( 'Search News', 'pne' ),
    );

    register_post_type( 'pne_news', array(
        'labels' => $labels,
        'public' => false,
        'show_ui' => true,
        'show_in_menu' => false, // we add a submenu under PNE
        'supports' => array( 'title', 'editor' ),
        'capability_type' => 'post',
    ) );
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
    
    // Add submenu: Campaigns
    add_submenu_page( 'pne', __( 'Campaigns', 'pne' ), __( 'Campaigns', 'pne' ), 'manage_options', 'edit.php?post_type=pne_news' );
    
    // Add submenu: News
    add_submenu_page( 'pne', __( 'News', 'pne' ), __( 'News', 'pne' ), 'manage_options', 'edit.php?post_type=pne_news' );
    
    // Add submenu: Logs
    add_submenu_page( 'pne', __( 'Logs', 'pne' ), __( 'Logs', 'pne' ), 'manage_options', 'pne-logs', 'pne_logs_ui' );
} );

/**
 * Enqueue admin scripts for media uploader on pne_news edit screens
 */
add_action( 'admin_enqueue_scripts', function ( $hook ) {
    $screen = get_current_screen();
    if ( $screen && isset( $screen->post_type ) && $screen->post_type === 'pne_news' ) {
        wp_enqueue_media();
        wp_register_script( 'pne-admin', plugins_url( 'assets/pne-admin.js', __FILE__ ), array( 'jquery' ), '1.0', true );
        wp_enqueue_script( 'pne-admin' );
    }
} );

/**
 * Meta boxes for pne_news
 */
add_action( 'add_meta_boxes', function () {
    add_meta_box( 'pne_news_meta', __( 'PNE News Settings', 'pne' ), function ( $post ) {
        wp_nonce_field( 'pne_news_meta_nonce', 'pne_news_meta_nonce' );
        $subject = get_post_meta( $post->ID, 'pne_subject', true );
        $png_id = get_post_meta( $post->ID, 'pne_png_id', true );
        $pdf_id = get_post_meta( $post->ID, 'pne_pdf_id', true );
        $png_url = $png_id ? wp_get_attachment_url( $png_id ) : get_post_meta( $post->ID, 'pne_png', true );
        $pdf_url = $pdf_id ? wp_get_attachment_url( $pdf_id ) : get_post_meta( $post->ID, 'pne_pdf', true );
        $date = get_post_meta( $post->ID, 'pne_sending_date', true );
        $role = get_post_meta( $post->ID, 'pne_recipient_role', true );
        $test_emails = get_post_meta( $post->ID, 'pne_test_emails', true );
        $view_url = get_post_meta( $post->ID, 'pne_view_url', true );
        ?>
        <p>
            <label><?php esc_html_e( 'Subject', 'pne' ); ?></label><br>
            <input type="text" name="pne_subject" value="<?php echo esc_attr( $subject ); ?>" style="width:100%">
        </p>
        <p>
            <label><?php esc_html_e( 'PNG (use media uploader)', 'pne' ); ?></label><br>
            <input type="hidden" id="pne_png_id" name="pne_png_id" value="<?php echo esc_attr( $png_id ); ?>">
            <button type="button" class="button" id="pne_select_png"><?php esc_html_e( 'Select PNG', 'pne' ); ?></button>
            <span id="pne_png_preview" style="margin-left:10px"><?php echo $png_url ? esc_html( basename( $png_url ) ) : ''; ?></span>
        </p>
        <p>
            <label><?php esc_html_e( 'PDF (use media uploader)', 'pne' ); ?></label><br>
            <input type="hidden" id="pne_pdf_id" name="pne_pdf_id" value="<?php echo esc_attr( $pdf_id ); ?>">
            <button type="button" class="button" id="pne_select_pdf"><?php esc_html_e( 'Select PDF', 'pne' ); ?></button>
            <span id="pne_pdf_preview" style="margin-left:10px"><?php echo $pdf_url ? esc_html( basename( $pdf_url ) ) : ''; ?></span>
        </p>
        <p>
            <label><?php esc_html_e( 'View online URL (optional)', 'pne' ); ?></label><br>
            <input type="url" name="pne_view_url" value="<?php echo esc_attr( $view_url ); ?>" style="width:100%" placeholder="https://example.com/news/your-article">
        </p>
        <p>
            <label><?php esc_html_e( 'Sending date', 'pne' ); ?></label><br>
            <input type="date" name="pne_sending_date" value="<?php echo esc_attr( $date ); ?>">
        </p>
        <p>
            <label><?php esc_html_e( 'Recipient role (optional, leave empty = all)', 'pne' ); ?></label><br>
            <select name="pne_recipient_role">
                <option value="" <?php selected( $role, '' ); ?>><?php esc_html_e( 'All users', 'pne' ); ?></option>
                <?php
                global $wp_roles;
                foreach ( $wp_roles->roles as $r_key => $r ) {
                    echo '<option value="' . esc_attr( $r_key ) . '" ' . selected( $role, $r_key, false ) . '>' . esc_html( $r['name'] ) . '</option>';
                }
                ?>
            </select>
        </p>
        <p>
            <label><?php esc_html_e( 'Test emails (comma separated). Defaults to current user email if empty.', 'pne' ); ?></label><br>
            <input type="text" name="pne_test_emails" value="<?php echo esc_attr( $test_emails ); ?>" style="width:100%">
        </p>
        <p>
            <?php
            $processed = get_post_meta( $post->ID, 'pne_news_processed', true );
            $test_campaign_id = get_post_meta( $post->ID, 'pne_news_test_campaign_id', true );
            if ( ! $test_campaign_id ) {
                $send_url = wp_nonce_url( admin_url( 'admin-post.php?action=pne_send_test&post_id=' . $post->ID ), 'pne_send_test_' . $post->ID );
                echo '<a href="' . esc_url( $send_url ) . '" class="button button-primary">' . esc_html__( 'Send test', 'pne' ) . '</a>';
            } else {
                echo '<span class="dashicons dashicons-yes"></span> ' . esc_html__( 'Test sent', 'pne' );
                $promote_url = wp_nonce_url( admin_url( 'admin-post.php?action=pne_promote_campaign&post_id=' . $post->ID ), 'pne_promote_' . $post->ID );
                echo ' <a href="' . esc_url( $promote_url ) . '" class="button">' . esc_html__( 'Promote to full send', 'pne' ) . '</a>';
            }
            ?>
        </p>
        <?php
    }, 'pne_news', 'normal', 'default' );
} );

/**
 * Save meta for pne_news
 */
add_action( 'save_post', function ( $post_id ) {
    if ( get_post_type( $post_id ) !== 'pne_news' ) {
        return;
    }
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( ! isset( $_POST['pne_news_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pne_news_meta_nonce'] ) ), 'pne_news_meta_nonce' ) ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;

    $subject = isset( $_POST['pne_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['pne_subject'] ) ) : '';
    $png_id = isset( $_POST['pne_png_id'] ) ? intval( wp_unslash( $_POST['pne_png_id'] ) ) : 0;
    $pdf_id = isset( $_POST['pne_pdf_id'] ) ? intval( wp_unslash( $_POST['pne_pdf_id'] ) ) : 0;
    $date = isset( $_POST['pne_sending_date'] ) ? sanitize_text_field( wp_unslash( $_POST['pne_sending_date'] ) ) : '';
    $role = isset( $_POST['pne_recipient_role'] ) ? sanitize_text_field( wp_unslash( $_POST['pne_recipient_role'] ) ) : '';
    $test_emails = isset( $_POST['pne_test_emails'] ) ? sanitize_text_field( wp_unslash( $_POST['pne_test_emails'] ) ) : '';
    $view_url = isset( $_POST['pne_view_url'] ) ? esc_url_raw( wp_unslash( $_POST['pne_view_url'] ) ) : '';

    update_post_meta( $post_id, 'pne_subject', $subject );
    if ( $png_id ) update_post_meta( $post_id, 'pne_png_id', $png_id );
    if ( $pdf_id ) update_post_meta( $post_id, 'pne_pdf_id', $pdf_id );
    update_post_meta( $post_id, 'pne_sending_date', $date );
    update_post_meta( $post_id, 'pne_recipient_role', $role );
    update_post_meta( $post_id, 'pne_test_emails', $test_emails );
    update_post_meta( $post_id, 'pne_view_url', $view_url );

    // If date changed, reset processed flag and test campaign
    delete_post_meta( $post_id, 'pne_news_processed' );
    delete_post_meta( $post_id, 'pne_news_test_campaign_id' );
}, 10, 1 );

/**
 * Admin post handler: send test campaign
 */
add_action( 'admin_post_pne_send_test', function () {
    if ( ! isset( $_GET['post_id'] ) ) wp_die( 'Missing post_id' );
    $post_id = intval( $_GET['post_id'] );
    if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'pne_send_test_' . $post_id ) ) wp_die( 'Invalid nonce' );
    if ( ! current_user_can( 'edit_post', $post_id ) ) wp_die( 'No permission' );

    $subject = get_post_meta( $post_id, 'pne_subject', true );
    $png_id = get_post_meta( $post_id, 'pne_png_id', true );
    $pdf_id = get_post_meta( $post_id, 'pne_pdf_id', true );
    $test_emails = get_post_meta( $post_id, 'pne_test_emails', true );
    $meta_view_url = get_post_meta( $post_id, 'pne_view_url', true );

    // resolve URLs
    $png_url = $png_id ? wp_get_attachment_url( $png_id ) : get_post_meta( $post_id, 'pne_png', true );
    $pdf_url = $pdf_id ? wp_get_attachment_url( $pdf_id ) : get_post_meta( $post_id, 'pne_pdf', true );

    // use provided view_url or fallback to permalink
    $view_url = $meta_view_url ? esc_url_raw( $meta_view_url ) : get_permalink( $post_id );

    // validate attachments existence if IDs
    if ( $png_id && ! get_attached_file( $png_id ) ) wp_die( 'PNG file missing' );
    if ( $pdf_id && ! get_attached_file( $pdf_id ) ) wp_die( 'PDF file missing' );

    // Build a polished HTML email body with subject, large image and action buttons
    $s = $subject ?: get_the_title( $post_id );

    $message = '<div style="font-family:Arial,Helvetica,sans-serif;color:#333;line-height:1.4;padding:16px;">';
    $message .= '<h1 style="font-size:20px;color:#111;margin:0 0 12px;">' . esc_html( $s ) . '</h1>';
    if ( $png_url ) {
        $message .= '<div style="text-align:center;margin:18px 0;"><img src="' . esc_url( $png_url ) . '" alt="' . esc_attr( $s ) . '" style="width:100%;max-width:600px;height:auto;border-radius:4px;"></div>';
    }
    $message .= '<p style="text-align:center;margin:20px 0;">';
    if ( $pdf_url ) {
        $message .= '<a href="' . esc_url( $pdf_url ) . '" style="display:inline-block;padding:12px 20px;background:#1e73be;color:#fff;text-decoration:none;border-radius:4px;margin-right:8px;">' . esc_html__( 'Download PDF', 'pne' ) . '</a>';
    }
    $message .= '<a href="' . esc_url( $view_url ) . '" style="display:inline-block;padding:12px 20px;background:#6ab04c;color:#fff;text-decoration:none;border-radius:4px;">' . esc_html__( 'View Online', 'pne' ) . '</a>';
    $message .= '</p>';
    $message .= '<p style="color:#666;font-size:13px;text-align:center;margin-top:8px;">' . esc_html__( 'If you cannot click the buttons, copy and paste the links in your browser.', 'pne' ) . '</p>';
    $message .= '</div>';

    global $wpdb;
    // create campaign in testing status
    $wpdb->insert( "{$wpdb->prefix}pne_campaigns", array(
        'subject' => $s,
        'message' => $message,
        'created_at' => current_time( 'mysql', 1 ),
        'status' => 'testing',
    ), array( '%s', '%s', '%s', '%s' ) );
    $cid = $wpdb->insert_id;

    if ( $cid ) {
        // prepare test recipients
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
                'campaign_id' => $cid,
                'email' => $em,
                'from_email' => '',
                'from_name' => '',
                'status' => 'pending',
            ), array( '%d', '%s', '%s', '%s', '%s' ) );
        }

        update_post_meta( $post_id, 'pne_news_test_campaign_id', $cid );
        // redirect back
        wp_redirect( admin_url( 'post.php?post=' . $post_id . '&action=edit&test_sent=1' ) );
        exit;
    }

    wp_die( 'Could not create test campaign' );
} );

/**
 * Admin post handler: promote test campaign to full send
 */
add_action( 'admin_post_pne_promote_campaign', function () {
    if ( ! isset( $_GET['post_id'] ) ) wp_die( 'Missing post_id' );
    $post_id = intval( $_GET['post_id'] );
    if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'pne_promote_' . $post_id ) ) wp_die( 'Invalid nonce' );
    if ( ! current_user_can( 'edit_post', $post_id ) ) wp_die( 'No permission' );

    $test_cid = get_post_meta( $post_id, 'pne_news_test_campaign_id', true );
    if ( ! $test_cid ) wp_die( 'No test campaign found' );

    $role = get_post_meta( $post_id, 'pne_recipient_role', true );

    global $wpdb;

    // set campaign status to running
    $wpdb->update( "{$wpdb->prefix}pne_campaigns", array( 'status' => 'running' ), array( 'id' => $test_cid ), array( '%s' ), array( '%d' ) );

    // insert full recipients
    if ( $role ) {
        $users = get_users( array( 'role' => $role ) );
    } else {
        $users = get_users();
    }
    $emails = wp_list_pluck( $users, 'user_email' );
    $emails = array_filter( array_unique( $emails ), 'is_email' );

    foreach ( $emails as $em ) {
        $wpdb->insert( "{$wpdb->prefix}pne_queue", array(
            'campaign_id' => $test_cid,
            'email' => $em,
            'from_email' => '',
            'from_name' => '',
            'status' => 'pending',
        ), array( '%d', '%s', '%s', '%s', '%s' ) );
    }

    update_post_meta( $post_id, 'pne_news_processed', 1 );
    update_post_meta( $post_id, 'pne_news_campaign_id', $test_cid );

    // invalidate cache
    if ( function_exists( 'pne_invalidate_yearly_cache' ) ) pne_invalidate_yearly_cache();

    wp_redirect( admin_url( 'post.php?post=' . $post_id . '&action=edit&promoted=1' ) );
    exit;
} );

/**
 * Process scheduled pne_news (daily)
 * This will create a test campaign if no test exists yet. Admin must promote to full send.
 */
add_action( 'pne_process_news', function () {
    $today = date( 'Y-m-d' );

    // find pne_news posts for today that are not processed
    $args = array(
        'post_type' => 'pne_news',
        'post_status' => 'publish',
        'meta_query' => array(
            array(
                'key' => 'pne_sending_date',
                'value' => $today,
                'compare' => '='
            ),
            array(
                'key' => 'pne_news_processed',
                'compare' => 'NOT EXISTS'
            )
        ),
        'posts_per_page' => -1,
    );

    $posts = get_posts( $args );
    if ( empty( $posts ) ) return;

    foreach ( $posts as $p ) {
        // if a test campaign already exists, skip — admin must promote
        $existing_test = get_post_meta( $p->ID, 'pne_news_test_campaign_id', true );
        if ( $existing_test ) continue;

        $subject = get_post_meta( $p->ID, 'pne_subject', true );
        $png_id = get_post_meta( $p->ID, 'pne_png_id', true );
        $pdf_id = get_post_meta( $p->ID, 'pne_pdf_id', true );

        // resolve URLs and validate attachments if provided as IDs
        $png_url = $png_id ? wp_get_attachment_url( $png_id ) : get_post_meta( $p->ID, 'pne_png', true );
        $pdf_url = $pdf_id ? wp_get_attachment_url( $pdf_id ) : get_post_meta( $p->ID, 'pne_pdf', true );
        $meta_view_url = get_post_meta( $p->ID, 'pne_view_url', true );
        $view_url = $meta_view_url ? esc_url_raw( $meta_view_url ) : get_permalink( $p->ID );

        if ( $png_id && ! get_attached_file( $png_id ) ) {
            update_post_meta( $p->ID, 'pne_news_error', 'PNG missing' );
            continue;
        }
        if ( $pdf_id && ! get_attached_file( $pdf_id ) ) {
            update_post_meta( $p->ID, 'pne_news_error', 'PDF missing' );
            continue;
        }

        // Build a polished HTML email body with subject, large image and action buttons
        $s = $subject ? $subject : $p->post_title;

        $message = '<div style="font-family:Arial,Helvetica,sans-serif;color:#333;line-height:1.4;padding:16px;">';
        $message .= '<h1 style="font-size:20px;color:#111;margin:0 0 12px;">' . esc_html( $s ) . '</h1>';
        if ( $png_url ) {
            $message .= '<div style="text-align:center;margin:18px 0;"><img src="' . esc_url( $png_url ) . '" alt="' . esc_attr( $s ) . '" style="width:100%;max-width:600px;height:auto;border-radius:4px;"></div>';
        }
        $message .= '<p style="text-align:center;margin:20px 0;">';
        if ( $pdf_url ) {
            $message .= '<a href="' . esc_url( $pdf_url ) . '" style="display:inline-block;padding:12px 20px;background:#1e73be;color:#fff;text-decoration:none;border-radius:4px;margin-right:8px;">' . esc_html__( 'Download PDF', 'pne' ) . '</a>';
        }
        $message .= '<a href="' . esc_url( $view_url ) . '" style="display:inline-block;padding:12px 20px;background:#6ab04c;color:#fff;text-decoration:none;border-radius:4px;">' . esc_html__( 'View Online', 'pne' ) . '</a>';
        $message .= '</p>';
        $message .= '<p style="color:#666;font-size:13px;text-align:center;margin-top:8px;">' . esc_html__( 'If you cannot click the buttons, copy and paste the links in your browser.', 'pne' ) . '</p>';
        $message .= '</div>';

        $s = $subject ? $subject : $p->post_title;

        // Insert campaign with testing status
        global $wpdb;
        $wpdb->insert(
            "{$wpdb->prefix}pne_campaigns",
            array(
                'subject' => $s,
                'message' => $message,
                'created_at' => current_time( 'mysql', 1 ),
                'status' => 'testing',
            ),
            array( '%s', '%s', '%s', '%s' )
        );
        $cid = $wpdb->insert_id;

        if ( $cid ) {
            // Default test recipients = current user who scheduled or admin (use site admin email)
            $admins = get_users( array( 'role' => 'administrator' ) );
            $emails = wp_list_pluck( $admins, 'user_email' );
            $emails = array_filter( array_unique( $emails ), 'is_email' );

            foreach ( $emails as $em ) {
                $wpdb->insert(
                    "{$wpdb->prefix}pne_queue",
                    array(
                        'campaign_id' => $cid,
                        'email' => $em,
                        'from_email' => '',
                        'from_name' => '',
                        'status' => 'pending',
                    ),
                    array( '%d', '%s', '%s', '%s', '%s' )
                );
            }

            update_post_meta( $p->ID, 'pne_news_test_campaign_id', $cid );

            if ( function_exists( 'pne_invalidate_yearly_cache' ) ) {
                pne_invalidate_yearly_cache();
            }
        }
    }
} );

/**
 * Récupère la liste des destinataires de façon sûre (compat function)
 */
function pne_get_recipients() {
    $users = get_users();
    $list  = wp_list_pluck( $users, 'user_email' );
    $list = array_filter( array_unique( $list ), 'is_email' );
    return $list;
}

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
    if ( ! wp_verify_nonce( $_REQUEST['_wpnonce'] ?? '', 'pne_export_logs' ) ) wp_die( 'Invalid nonce' );

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
    if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'pne_purge_logs' ) ) wp_die( 'Invalid nonce' );
    $days = isset( $_POST['days'] ) ? max( 1, intval( $_POST['days'] ) ) : 30;
    $cutoff = date( 'Y-m-d H:i:s', strtotime( '-' . $days . ' days' ) );

    global $wpdb;
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}pne_logs WHERE created_at < %s", $cutoff ) );

    wp_redirect( admin_url( 'admin.php?page=pne-logs&purged=1' ) );
    exit;
} );
