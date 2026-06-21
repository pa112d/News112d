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
 * Récupère la liste des destinataires de façon sûre
 */
function pne_get_recipients() {
    $target = isset( $_POST['target'] ) ? sanitize_text_field( wp_unslash( $_POST['target'] ) ) : 'all';
    $list   = array();

    if ( $target === 'all' ) {
        $users = get_users();
        $list  = wp_list_pluck( $users, 'user_email' );
    } elseif ( $target === 'role' ) {
        $role = isset( $_POST['role'] ) ? sanitize_text_field( wp_unslash( $_POST['role'] ) ) : '';
        if ( $role ) {
            $users = get_users( array( 'role' => $role ) );
            $list  = wp_list_pluck( $users, 'user_email' );
        }
    } elseif ( $target === 'import' ) {
        $raw = isset( $_POST['import_list'] ) ? wp_unslash( $_POST['import_list'] ) : '';
        $lines = preg_split( '/\r\n|\r|\n/', $raw );
        $lines = array_map( 'trim', $lines );
        $list  = array_filter( $lines, 'is_email' );
    }

    $list = array_filter( array_unique( $list ), 'is_email' );
    return $list;
}

/**
 * Tâche cron : envoi d'e-mails
 */
add_action( 'pne_send', function () {
    global $wpdb;

    $limit = 15;
    $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pne_queue WHERE status = %s LIMIT %d", 'pending', $limit ) );

    if ( empty( $rows ) ) {
        return;
    }

    foreach ( $rows as $r ) {
        // Récupération sécurisée du sujet et message
        $campaign_id = intval( $r->campaign_id );
        $subject = $wpdb->get_var( $wpdb->prepare( "SELECT subject FROM {$wpdb->prefix}pne_campaigns WHERE id = %d", $campaign_id ) );
        $msg     = $wpdb->get_var( $wpdb->prepare( "SELECT message FROM {$wpdb->prefix}pne_campaigns WHERE id = %d", $campaign_id ) );

        // Définitions d'en-têtes sûrs pour wp_mail (évite de toucher aux filtres globaux)
        $from_email = sanitize_email( $r->from_email );
        $from_name  = sanitize_text_field( $r->from_name );
        $headers = array( 'Content-Type: text/html; charset=UTF-8' );
        if ( is_email( $from_email ) ) {
            $headers[] = 'From: ' . wp_strip_all_tags( $from_name ) . ' <' . $from_email . '>';
        }

        $ok = wp_mail( $r->email, $subject, $msg, $headers );

        if ( $ok ) {
            $wpdb->update(
                "{$wpdb->prefix}pne_queue",
                array( 'status' => 'sent', 'sent_at' => current_time( 'mysql', 1 ) ),
                array( 'id' => intval( $r->id ) ),
                array( '%s', '%s' ),
                array( '%d' )
            );
        } else {
            $wpdb->update(
                "{$wpdb->prefix}pne_queue",
                array(
                    'status'     => 'error',
                    'attempts'   => intval( $r->attempts ) + 1,
                    'last_error' => 'mail_failed',
                ),
                array( 'id' => intval( $r->id ) ),
                array( '%s', '%d', '%s' ),
                array( '%d' )
            );
        }
    }
} );

/**
 * Menu admin
 */
add_action( 'admin_menu', function () {
    add_menu_page( __( 'PNE', 'pne' ), __( 'PNE', 'pne' ), 'manage_options', 'pne', 'pne_ui' );
    add_submenu_page( 'pne', __( 'Queue', 'pne' ), __( 'Queue', 'pne' ), 'manage_options', 'pne-queue', 'pne_queue_ui' );
} );

/**
 * Enqueue admin assets for the PNE page
 */
add_action( 'admin_enqueue_scripts', function ( $hook ) {
    // only load on our plugin pages
    if ( ! in_array( $hook, array( 'toplevel_page_pne', 'pne_page_pne-queue' ), true ) ) {
        return;
    }

    // Chart.js from CDN
    wp_register_script( 'pne-chartjs', 'https://cdn.jsdelivr.net/npm/chart.js', array(), '4.0.0', true );
    wp_enqueue_script( 'pne-chartjs' );

    // Our admin stats script
    wp_register_script( 'pne-stats', plugins_url( 'assets/pne-stats.js', __FILE__ ), array( 'pne-chartjs' ), '1.0', true );
    $rest_url = esc_url_raw( rest_url( 'pne/v1/stats/yearly' ) );
    $nonce = wp_create_nonce( 'wp_rest' );
    wp_localize_script( 'pne-stats', 'pne_stats', array( 'endpoint' => $rest_url, 'nonce' => $nonce, 'refresh_interval' => 30000 ) );
    wp_enqueue_script( 'pne-stats' );

    // Styles for the grid and queue
    wp_register_style( 'pne-styles', plugins_url( 'assets/pne-stats.css', __FILE__ ), array(), '1.0' );
    wp_enqueue_style( 'pne-styles' );
} );

/**
 * Register REST routes
 */
add_action( 'rest_api_init', function () {
    register_rest_route( 'pne/v1', '/stats/yearly', array(
        'methods'  => 'GET',
        'callback' => 'pne_rest_yearly_stats',
        'permission_callback' => function () { return current_user_can( 'manage_options' ); }
    ) );
} );

/**
 * REST callback: returns 12 months stats for current year (cached via transient)
 */
function pne_rest_yearly_stats( WP_REST_Request $request ) {
    global $wpdb;

    $year = date( 'Y' );
    $transient_key = 'pne_yearly_stats_' . $year;

    $cached = get_transient( $transient_key );
    if ( $cached !== false ) {
        return rest_ensure_response( array( 'success' => true, 'data' => $cached ) );
    }

    $results = array();

    for ( $m = 1; $m <= 12; $m++ ) {
        $label = date_i18n( 'F', mktime( 0, 0, 0, $m, 1 ) );

        $sent = intval( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}pne_queue q JOIN {$wpdb->prefix}pne_campaigns c ON q.campaign_id = c.id WHERE YEAR(c.created_at) = %d AND MONTH(c.created_at) = %d AND q.status = %s", $year, $m, 'sent' ) ) );
        $pending = intval( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}pne_queue q JOIN {$wpdb->prefix}pne_campaigns c ON q.campaign_id = c.id WHERE YEAR(c.created_at) = %d AND MONTH(c.created_at) = %d AND q.status = %s", $year, $m, 'pending' ) ) );
        $error = intval( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}pne_queue q JOIN {$wpdb->prefix}pne_campaigns c ON q.campaign_id = c.id WHERE YEAR(c.created_at) = %d AND MONTH(c.created_at) = %d AND q.status = %s", $year, $m, 'error' ) ) );

        $results[] = array( 'month' => $m, 'label' => $label, 'sent' => $sent, 'pending' => $pending, 'error' => $error );
    }

    // cache for 60 seconds to reduce DB load
    set_transient( $transient_key, $results, 60 );

    return rest_ensure_response( array( 'success' => true, 'data' => $results ) );
}

/**
 * Invalidate yearly stats cache for current year
 */
function pne_invalidate_yearly_cache() {
    $key = 'pne_yearly_stats_' . date( 'Y' );
    delete_transient( $key );
}

/**
 * Queue admin UI: list queue entries with pagination and actions
 */
function pne_queue_ui() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    global $wpdb;

    // Handle actions: resend, delete
    if ( isset( $_POST['pne_queue_action'] ) ) {
        check_admin_referer( 'pne_queue_action_nonce' );
        $action = sanitize_text_field( wp_unslash( $_POST['pne_queue_action'] ) );
        $id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;

        if ( $action === 'resend' && $id ) {
            $wpdb->update(
                "{$wpdb->prefix}pne_queue",
                array( 'status' => 'pending', 'attempts' => 0, 'last_error' => null, 'sent_at' => null ),
                array( 'id' => $id ),
                array( '%s', '%d', '%s', '%s' ),
                array( '%d' )
            );
            pne_invalidate_yearly_cache();
            echo '<div class="notice notice-success"><p>' . esc_html__( 'Entry scheduled for resend.', 'pne' ) . '</p></div>';
        }

        if ( $action === 'delete' && $id ) {
            $wpdb->delete( "{$wpdb->prefix}pne_queue", array( 'id' => $id ), array( '%d' ) );
            pne_invalidate_yearly_cache();
            echo '<div class="notice notice-success"><p>' . esc_html__( 'Entry deleted.', 'pne' ) . '</p></div>';
        }
    }

    // Pagination
    $paged = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
    $per_page = 20;
    $offset = ( $paged - 1 ) * $per_page;

    $total = intval( $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}pne_queue" ) );
    $rows = $wpdb->get_results( $wpdb->prepare( "SELECT q.*, c.subject FROM {$wpdb->prefix}pne_queue q LEFT JOIN {$wpdb->prefix}pne_campaigns c ON q.campaign_id = c.id ORDER BY q.id DESC LIMIT %d OFFSET %d", $per_page, $offset ) );

    $total_pages = (int) ceil( $total / $per_page );
    ?>
    <div class="wrap">
        <h1><?php echo esc_html__( 'PNE Queue', 'pne' ); ?></h1>

        <table class="widefat fixed striped">
            <thead>
                <tr>
                    <th><?php echo esc_html__( 'ID', 'pne' ); ?></th>
                    <th><?php echo esc_html__( 'Campaign', 'pne' ); ?></th>
                    <th><?php echo esc_html__( 'Email', 'pne' ); ?></th>
                    <th><?php echo esc_html__( 'Status', 'pne' ); ?></th>
                    <th><?php echo esc_html__( 'Attempts', 'pne' ); ?></th>
                    <th><?php echo esc_html__( 'Last error', 'pne' ); ?></th>
                    <th><?php echo esc_html__( 'Sent at', 'pne' ); ?></th>
                    <th><?php echo esc_html__( 'Actions', 'pne' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $rows ) ) : ?>
                    <tr><td colspan="8"><?php echo esc_html__( 'No queue entries.', 'pne' ); ?></td></tr>
                <?php else : ?>
                    <?php foreach ( $rows as $r ) : ?>
                        <tr>
                            <td><?php echo intval( $r->id ); ?></td>
                            <td><?php echo esc_html( $r->subject ? $r->subject : sprintf( __( 'Campaign #%d', 'pne' ), intval( $r->campaign_id ) ) ); ?></td>
                            <td><?php echo esc_html( $r->email ); ?></td>
                            <td><?php echo esc_html( $r->status ); ?></td>
                            <td><?php echo intval( $r->attempts ); ?></td>
                            <td><?php echo esc_html( $r->last_error ); ?></td>
                            <td><?php echo esc_html( $r->sent_at ); ?></td>
                            <td>
                                <form method="post" style="display:inline">
                                    <?php wp_nonce_field( 'pne_queue_action_nonce' ); ?>
                                    <input type="hidden" name="id" value="<?php echo intval( $r->id ); ?>">
                                    <input type="hidden" name="pne_queue_action" value="resend">
                                    <button class="button" type="submit"><?php echo esc_html__( 'Resend', 'pne' ); ?></button>
                                </form>

                                <form method="post" style="display:inline;margin-left:6px">
                                    <?php wp_nonce_field( 'pne_queue_action_nonce' ); ?>
                                    <input type="hidden" name="id" value="<?php echo intval( $r->id ); ?>">
                                    <input type="hidden" name="pne_queue_action" value="delete">
                                    <button class="button button-secondary" type="submit" onclick="return confirm('<?php echo esc_js( __( 'Are you sure?', 'pne' ) ); ?>')"><?php echo esc_html__( 'Delete', 'pne' ); ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <div style="margin-top:12px">
            <?php if ( $total_pages > 1 ) : ?>
                <div class="tablenav">
                    <div class="tablenav-pages">
                        <?php
                        $base = add_query_arg( 'paged', '%#%' );
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
    </div>
    <?php
}

/**
 * Interface admin : affichage et traitement POST (sécurisé)
 * This function renders the main PNE page (stats + form)
 */
function pne_ui() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    global $wpdb;

    // Traitement du POST sécurisé
    if ( isset( $_POST['send'] ) ) {

        // Vérifier le nonce et les droits
        if ( ! isset( $_POST['pne_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pne_nonce'] ) ), 'pne_send_action' ) ) {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'Nonce verification failed.', 'pne' ) . '</p></div>';
        } else {
            $subject = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '';
            $msg_raw = isset( $_POST['message'] ) ? wp_kses_post( wp_unslash( $_POST['message'] ) ) : '';
            $from    = isset( $_POST['from_email'] ) ? sanitize_email( wp_unslash( $_POST['from_email'] ) ) : '';
            $name    = isset( $_POST['from_name'] ) ? sanitize_text_field( wp_unslash( $_POST['from_name'] ) ) : '';

            // Validate email
            if ( ! is_email( $from ) ) {
                echo '<div class="notice notice-error"><p>' . esc_html__( 'Expéditeur invalide.', 'pne' ) . '</p></div>';
            } else {
                // Insertion campagne (wpdb->insert prépare les valeurs)
                $wpdb->insert(
                    "{$wpdb->prefix}pne_campaigns",
                    array(
                        'subject'    => $subject,
                        'message'    => $msg_raw,
                        'created_at' => current_time( 'mysql', 1 ),
                        'status'     => 'running',
                    ),
                    array( '%s', '%s', '%s', '%s' )
                );
                $cid = $wpdb->insert_id;

                // Liste des destinataires
                $list = pne_get_recipients();
                foreach ( $list as $e ) {
                    if ( ! is_email( $e ) ) {
                        continue;
                    }
                    $wpdb->insert(
                        "{$wpdb->prefix}pne_queue",
                        array(
                            'campaign_id' => $cid,
                            'email'       => $e,
                            'from_email'  => $from,
                            'from_name'   => $name,
                        ),
                        array( '%d', '%s', '%s', '%s' )
                    );
                }

                // Invalidate cache so stats update immediately
                pne_invalidate_yearly_cache();

                echo '<div class="notice notice-success"><p>' . esc_html__( 'Campagne enregistrée et destinataires ajoutés.', 'pne' ) . '</p></div>';
            }
        }
    }

    // Main UI (stats rendered by JS)
    ?>
    <style>.box{background:#fff;padding:15px;margin-top:15px;border-radius:6px}.pne-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}.pne-card{background:#fff;padding:10px;border-radius:6px;box-shadow:0 1px 2px rgba(0,0,0,0.05)}.pne-card canvas{width:100%!important;height:150px!important}@media (max-width:900px){.pne-grid{grid-template-columns:repeat(3,1fr)}}@media (max-width:600px){.pne-grid{grid-template-columns:repeat(2,1fr)}}@media (max-width:420px){.pne-grid{grid-template-columns:repeat(1,1fr)}}</style>
    <div class="wrap">
        <h1><?php echo esc_html__( 'PNE V5.8', 'pne' ); ?></h1>

        <div class="box">
            <h3><?php echo esc_html__( 'Yearly newsletters overview', 'pne' ); ?></h3>
            <div id="pne-yearly-stats" class="pne-grid"></div>
            <p><button id="pne-refresh" class="button"><?php echo esc_html__( 'Actualiser', 'pne' ); ?></button></p>
        </div>

        <form method="post">
            <?php wp_nonce_field( 'pne_send_action', 'pne_nonce' ); ?>

            <div class="box">
                <h3><?php echo esc_html__( 'Campaign', 'pne' ); ?></h3>
                <input name="subject" placeholder="<?php echo esc_attr__( 'Subject', 'pne' ); ?>" style="width:100%" value="<?php echo isset( $_POST['subject'] ) ? esc_attr( wp_unslash( $_POST['subject'] ) ) : ''; ?>"><br><br>

                <?php
                // Utiliser wp_editor pour l'éditeur HTML (améliore l'expérience et la sécurité)
                $content = isset( $_POST['message'] ) ? wp_kses_post( wp_unslash( $_POST['message'] ) ) : '';
                wp_editor( $content, 'pne_message_editor', array( 'textarea_name' => 'message', 'textarea_rows' => 10 ) );
                ?>

                <button type="button" onclick="document.getElementById('p').innerHTML = document.getElementById('pne_message_editor').value;"><?php echo esc_html__( 'Preview', 'pne' ); ?></button>
                <div id="p"></div>
            </div>

            <div class="box">
                <h3><?php echo esc_html__( 'Sender', 'pne' ); ?></h3>
                <input name="from_email" placeholder="<?php echo esc_attr__( 'email', 'pne' ); ?>" value="<?php echo isset( $_POST['from_email'] ) ? esc_attr( wp_unslash( $_POST['from_email'] ) ) : ''; ?>"><br>
                <input name="from_name" placeholder="<?php echo esc_attr__( 'name', 'pne' ); ?>" value="<?php echo isset( $_POST['from_name'] ) ? esc_attr( wp_unslash( $_POST['from_name'] ) ) : ''; ?>">
            </div>

            <div class="box">
                <h3><?php echo esc_html__( 'Recipients', 'pne' ); ?></h3>
                <label><input type="radio" name="target" value="all" <?php checked( isset( $_POST['target'] ) ? $_POST['target'] : 'all', 'all' ); ?>><?php echo esc_html__( 'All', 'pne' ); ?></label><br>
                <label><input type="radio" name="target" value="role" <?php checked( isset( $_POST['target'] ) ? $_POST['target'] : '', 'role' ); ?>><?php echo esc_html__( 'Role', 'pne' ); ?></label>
                <select name="role"><option value="subscriber"><?php echo esc_html__( 'subscriber', 'pne' ); ?></option></select><br>
                <label><input type="radio" name="target" value="import" <?php checked( isset( $_POST['target'] ) ? $_POST['target'] : '', 'import' ); ?>><?php echo esc_html__( 'Raw list', 'pne' ); ?></label>
                <textarea name="import_list"><?php echo isset( $_POST['import_list'] ) ? esc_textarea( wp_unslash( $_POST['import_list'] ) ) : ''; ?></textarea>
            </div>

            <button name="send" class="button button-primary"><?php echo esc_html__( 'Send', 'pne' ); ?></button>
        </form>
    </div>
    <?php
}
