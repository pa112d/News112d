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
} );

/**
 * Interface admin : affichage et traitement POST (sécurisé)
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

                echo '<div class="notice notice-success"><p>' . esc_html__( 'Campagne enregistrée et destinataires ajoutés.', 'pne' ) . '</p></div>';
            }
        }
    }

    // Stats (sous forme sûre)
    $sent    = intval( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}pne_queue WHERE status = %s", 'sent' ) ) );
    $pending = intval( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}pne_queue WHERE status = %s", 'pending' ) ) );
    $error   = intval( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}pne_queue WHERE status = %s", 'error' ) ) );
    ?>
    <style>.box{background:#fff;padding:15px;margin-top:15px;border-radius:6px}</style>
    <div class="wrap">
        <h1><?php echo esc_html__( 'PNE V5.8', 'pne' ); ?></h1>

        <div class="box">
            <h3><?php echo esc_html__( 'Stats', 'pne' ); ?></h3>
            <canvas id="chart"></canvas>
            <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
            <script>
            new Chart(document.getElementById('chart'),{
                type:'doughnut',
                data:{
                    labels:['<?php echo esc_js( 'Sent' ); ?>','<?php echo esc_js( 'Pending' ); ?>','<?php echo esc_js( 'Error' ); ?>'],
                    datasets:[{data:[<?php echo $sent; ?>,<?php echo $pending; ?>,<?php echo $error; ?>]}]
                }
            });
            </script>
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
