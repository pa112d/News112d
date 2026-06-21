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
 * Add submenu entry for PNE News
 */
add_action( 'admin_menu', function () {
    add_submenu_page( 'pne', __( 'News', 'pne' ), __( 'News', 'pne' ), 'manage_options', 'edit.php?post_type=pne_news' );
} );

/**
 * Meta boxes for pne_news
 */
add_action( 'add_meta_boxes', function () {
    add_meta_box( 'pne_news_meta', __( 'PNE News Settings', 'pne' ), function ( $post ) {
        wp_nonce_field( 'pne_news_meta_nonce', 'pne_news_meta_nonce' );
        $subject = get_post_meta( $post->ID, 'pne_subject', true );
        $png = get_post_meta( $post->ID, 'pne_png', true );
        $pdf = get_post_meta( $post->ID, 'pne_pdf', true );
        $date = get_post_meta( $post->ID, 'pne_sending_date', true );
        ?>
        <p>
            <label><?php esc_html_e( 'Subject', 'pne' ); ?></label><br>
            <input type="text" name="pne_subject" value="<?php echo esc_attr( $subject ); ?>" style="width:100%">
        </p>
        <p>
            <label><?php esc_html_e( 'PNG URL', 'pne' ); ?></label><br>
            <input type="url" name="pne_png" value="<?php echo esc_attr( $png ); ?>" style="width:100%">
        </p>
        <p>
            <label><?php esc_html_e( 'PDF URL', 'pne' ); ?></label><br>
            <input type="url" name="pne_pdf" value="<?php echo esc_attr( $pdf ); ?>" style="width:100%">
        </p>
        <p>
            <label><?php esc_html_e( 'Sending date', 'pne' ); ?></label><br>
            <input type="date" name="pne_sending_date" value="<?php echo esc_attr( $date ); ?>">
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
    $png = isset( $_POST['pne_png'] ) ? esc_url_raw( wp_unslash( $_POST['pne_png'] ) ) : '';
    $pdf = isset( $_POST['pne_pdf'] ) ? esc_url_raw( wp_unslash( $_POST['pne_pdf'] ) ) : '';
    $date = isset( $_POST['pne_sending_date'] ) ? sanitize_text_field( wp_unslash( $_POST['pne_sending_date'] ) ) : '';

    update_post_meta( $post_id, 'pne_subject', $subject );
    update_post_meta( $post_id, 'pne_png', $png );
    update_post_meta( $post_id, 'pne_pdf', $pdf );
    update_post_meta( $post_id, 'pne_sending_date', $date );

    // If date changed, reset processed flag
    delete_post_meta( $post_id, 'pne_news_processed' );
}, 10, 1 );

/**
 * Shortcode to display a pne_news
 * Usage: [pne_news id=123]
 */
add_shortcode( 'pne_news', function ( $atts ) {
    $atts = shortcode_atts( array( 'id' => 0 ), $atts, 'pne_news' );
    $id = intval( $atts['id'] );
    if ( ! $id ) return '';

    $post = get_post( $id );
    if ( ! $post || $post->post_type !== 'pne_news' ) return '';

    $subject = get_post_meta( $id, 'pne_subject', true );
    $png = get_post_meta( $id, 'pne_png', true );
    $pdf = get_post_meta( $id, 'pne_pdf', true );
    $date = get_post_meta( $id, 'pne_sending_date', true );

    $html = '<div class="pne-news">';
    $html .= '<h2>' . esc_html( $subject ? $subject : $post->post_title ) . '</h2>';
    if ( $png ) {
        $html .= '<p><img src="' . esc_url( $png ) . '" alt="' . esc_attr( $subject ) . '" style="max-width:100%;height:auto"></p>';
    }
    if ( $pdf ) {
        $html .= '<p><a href="' . esc_url( $pdf ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Download PDF', 'pne' ) . '</a></p>';
    }
    if ( $date ) {
        $html .= '<p><small>' . esc_html( date_i18n( get_option( 'date_format' ), strtotime( $date ) ) ) . '</small></p>';
    }
    $html .= '</div>';

    return $html;
} );

/**
 * Process scheduled pne_news (daily)
 */
add_action( 'pne_process_news', function () {
    global $wpdb;

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
        $subject = get_post_meta( $p->ID, 'pne_subject', true );
        $png = get_post_meta( $p->ID, 'pne_png', true );
        $pdf = get_post_meta( $p->ID, 'pne_pdf', true );

        // Build message: include image and pdf link
        $message = '';
        if ( $png ) {
            $message .= '<p><img src="' . esc_url( $png ) . '" alt="' . esc_attr( $subject ) . '" style="max-width:100%;height:auto"></p>';
        }
        if ( $pdf ) {
            $message .= '<p><a href="' . esc_url( $pdf ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Download PDF', 'pne' ) . '</a></p>';
        }
        // fallback to post content if no png/pdf
        if ( empty( $message ) ) {
            $message = apply_filters( 'the_content', $p->post_content );
        }

        $s = $subject ? $subject : $p->post_title;

        // Insert campaign
        $wpdb->insert(
            "{$wpdb->prefix}pne_campaigns",
            array(
                'subject' => $s,
                'message' => $message,
                'created_at' => current_time( 'mysql', 1 ),
                'status' => 'running',
            ),
            array( '%s', '%s', '%s', '%s' )
        );
        $cid = $wpdb->insert_id;

        if ( $cid ) {
            // Default recipients = all users
            $users = get_users();
            $emails = wp_list_pluck( $users, 'user_email' );
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

            // Mark post as processed and store campaign id
            update_post_meta( $p->ID, 'pne_news_processed', 1 );
            update_post_meta( $p->ID, 'pne_news_campaign_id', $cid );

            // Invalidate stats cache
            if ( function_exists( 'pne_invalidate_yearly_cache' ) ) {
                pne_invalidate_yearly_cache();
            }
        }
    }
} );

/**
 * Récupère la liste des destinataires de façon sûre (reste en place)
 */
function pne_get_recipients() {
    // kept for backward compatibility - prefer calling get_users() when scheduling
    $users = get_users();
    $list  = wp_list_pluck( $users, 'user_email' );
    $list = array_filter( array_unique( $list ), 'is_email' );
    return $list;
}
