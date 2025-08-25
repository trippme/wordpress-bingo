<?php
/*
Plugin Name: Configurable Digital Bingo
Description: Digital 5×5 bingo card with shared status across all users/devices. Center tile always "BINGO".
Version: 1.2.10
Author: Tripp Meister
Text Domain: configurable-digital-bingo
*/

if ( ! defined( 'ABSPATH' ) ) exit;

class Configurable_Digital_Bingo {
    private $boards_table;
    private $status_table;

    public function __construct() {
        global $wpdb;
        $this->boards_table = $wpdb->prefix . 'cdb_boards';
        $this->status_table = $wpdb->prefix . 'cdb_status';

        register_activation_hook( __FILE__, array( $this, 'activate' ) );
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_public_assets' ) );
        add_action( 'admin_post_cdb_add', array( $this, 'add_board' ) );
        add_action( 'admin_post_cdb_save', array( $this, 'save_board' ) );
        add_action( 'wp_ajax_cdb_toggle', array( $this, 'ajax_toggle' ) );
        add_action( 'wp_ajax_nopriv_cdb_toggle', array( $this, 'ajax_toggle' ) );
        add_shortcode( 'digital_bingo', array( $this, 'render_bingo' ) );
    }

    public function activate() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE " . $this->boards_table . " (
            id mediumint unsigned NOT NULL AUTO_INCREMENT,
            name varchar(200) NOT NULL,
            items longtext NOT NULL,
            rewards longtext NOT NULL,
            punishment longtext NOT NULL,
            PRIMARY KEY (id)
        ) $charset;";
        $sql .= "CREATE TABLE " . $this->status_table . " (
            board_id mediumint unsigned NOT NULL,
            tile_idx tinyint unsigned NOT NULL,
            PRIMARY KEY (board_id, tile_idx)
        ) $charset;";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    public function add_admin_menu() {
        add_menu_page( 'Bingo Boards', 'Bingo Boards', 'manage_options', 'cdb_settings', array( $this, 'settings_page' ), 'dashicons-list-view', 80 );
    }

    public function enqueue_admin_assets( $hook ) {
        if ( $hook !== 'toplevel_page_cdb_settings' ) return;
        wp_enqueue_script( 'jquery-ui-tabs' );
        wp_enqueue_style( 'jquery-ui-css', 'https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css' );
    }

    public function enqueue_public_assets() {
        wp_enqueue_script( 'cdb-script', plugin_dir_url( __FILE__ ) . 'assets/cdb-script.js', array( 'jquery' ), null, false );
        $ajax_url = esc_url_raw( admin_url( 'admin-ajax.php' ) );
        wp_localize_script( 'cdb-script', 'cdb_params', array(
            'ajax_url' => $ajax_url,
            'nonce'    => wp_create_nonce( 'cdb_toggle' ),
        ) );
        wp_enqueue_style( 'cdb-style', plugin_dir_url( __FILE__ ) . 'assets/cdb-style.css' );
    }

    public function settings_page() {
        global $wpdb;
        $boards_table = $this->boards_table;
        $boards = $wpdb->get_results( "SELECT * FROM $boards_table" );
        $current_tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'new';

        echo '<div class="wrap"><h1>Bingo Boards</h1>';
        if ( isset( $_GET['saved'] ) ) {
            echo '<div id="setting-error-settings_updated" class="updated settings-error notice is-dismissible"><p>Changes saved.</p><button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button></div>';
        }

        echo '<h2 class="nav-tab-wrapper">';
        foreach ( $boards as $b ) {
            $tab_id = (int) $b->id;
            $class = ( $current_tab === $tab_id ) ? ' nav-tab-active' : '';
            echo '<a href="' . esc_url( admin_url("admin.php?page=cdb_settings&tab={$tab_id}") ) . '" class="nav-tab' . $class . '">' . esc_html( $b->name ) . '</a>';
        }
        echo '<a href="' . wp_nonce_url( admin_url('admin-post.php?action=cdb_add'), 'cdb_add') . '" class="nav-tab">+ Add New</a>';
        echo '</h2>';

        if ( 'new' === $current_tab ) {
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
            wp_nonce_field( 'cdb_add' );
            echo '<input type="hidden" name="action" value="cdb_add">';
            echo '<p><button class="button button-primary" type="submit">Create New Board</button></p>';
            echo '</form>';
        } else {
            $id = intval( $current_tab );
            $board = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $boards_table WHERE id=%d", $id ) );
            if ( $board ) {
                echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
                wp_nonce_field( 'cdb_save_' . $id );
                echo '<input type="hidden" name="action" value="cdb_save">';
                echo '<input type="hidden" name="board_id" value="' . esc_attr( $id ) . '">';
                echo '<table class="form-table">';
                echo '<tr><th>Name</th><td><input class="regular-text" name="board_name" value="' . esc_attr( $board->name ) . '"></td></tr>';
                echo '<tr><th>Items (24 lines)</th><td><textarea class="large-text code" rows="24" name="items_text" style="width:100%;">' . esc_textarea( $board->items ) . '</textarea></td></tr>';
                echo '<tr><th>Rewards (one per line)</th><td><textarea class="large-text code" rows="4" name="rewards_text" style="width:100%;">' . esc_textarea( $board->rewards ) . '</textarea></td></tr>';
                echo '<tr><th>Punishment (one per line)</th><td><textarea class="large-text code" rows="4" name="punishment_text" style="width:100%;">' . esc_textarea( $board->punishment ) . '</textarea></td></tr>';
                echo '</table>';
        // Shortcode above buttons
        echo '<p>Shortcode: <code>[digital_bingo id="' . esc_attr($current_tab) . '"]</code></p>';
                submit_button( 'Save Board' );
                submit_button( 'Delete Board', 'secondary', 'delete', false, array( 'onclick' => "return confirm('Delete this board?');" ) );
                echo '<p>Shortcode: <code>[digital_bingo id="' . esc_attr( $id ) . '"]</code></p>';
                echo '</form>';
            } else {
                echo '<p>Board not found.</p>';
            }
        }

        echo '</div>';
    }

    public function add_board() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
        check_admin_referer( 'cdb_add' );
        global $wpdb;
        $wpdb->insert( $this->boards_table, array( 'name' => 'New Board', 'items' => '', 'rewards' => '', 'punishment' => '' ), array( '%s', '%s', '%s', '%s' ) );
        $new_id = $wpdb->insert_id;
        wp_redirect( admin_url( 'admin.php?page=cdb_settings&tab=' . $new_id ) );
        exit;
    }

    public function save_board() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
        $id = intval( $_POST['board_id'] ?? 0 );
        check_admin_referer( 'cdb_save_' . $id );
        global $wpdb;
        if ( isset( $_POST['delete'] ) ) {
            $wpdb->delete( $this->boards_table, array( 'id' => $id ), array( '%d' ) );
            $wpdb->delete( $this->status_table, array( 'board_id' => $id ), array( '%d' ) );
            wp_redirect( admin_url( 'admin.php?page=cdb_settings&tab=new' ) );
        } else {
            $name = sanitize_text_field( wp_unslash( $_POST['board_name'] ?? '' ) );
            $items = sanitize_textarea_field( wp_unslash( $_POST['items_text'] ?? '' ) );
            $rewards = sanitize_textarea_field( wp_unslash( $_POST['rewards_text'] ?? '' ) );
            $punishment = sanitize_textarea_field( wp_unslash( $_POST['punishment_text'] ?? '' ) );
            $wpdb->update( $this->boards_table, array(
                'name'       => $name,
                'items'      => $items,
                'rewards'    => $rewards,
                'punishment' => $punishment,
            ), array( 'id' => $id ), array( '%s', '%s', '%s', '%s' ), array( '%d' ) );
            wp_redirect( admin_url( 'admin.php?page=cdb_settings&tab=' . $id . '&saved=1' ) );
        }
        exit;
    }

    public function ajax_toggle() {
        check_ajax_referer( 'cdb_toggle', 'nonce' );
        $b = intval( $_POST['board'] );
        $t = intval( $_POST['tile'] );
        global $wpdb;
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM " . $this->status_table . " WHERE board_id=%d AND tile_idx=%d",
            $b, $t
        ));
        if ( $exists ) {
            $wpdb->delete( $this->status_table, array( 'board_id' => $b, 'tile_idx' => $t ), array( '%d', '%d' ) );
            wp_send_json_success( array( 'status' => 0 ) );
        } else {
            $wpdb->insert( $this->status_table, array( 'board_id' => $b, 'tile_idx' => $t ), array( '%d', '%d' ) );
            wp_send_json_success( array( 'status' => 1 ) );
        }
    }

    public function render_bingo( $atts ) {
        global $wpdb;
        $boards_table = $this->boards_table;
        $status_table = $this->status_table;
        $atts = shortcode_atts( array( 'id' => 0 ), $atts, 'digital_bingo' );
        $b = intval( $atts['id'] );
        $board = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . $boards_table . " WHERE id=%d", $b ) );
        if ( ! $board ) return '';
        // Title
        $html = '<h2 class="cdb-board-title">' . esc_html( $board->name ) . '</h2>';
        // Grid
        $lines = preg_split( '/\r\n|\r|\n/', $board->items );
        $lines = array_slice( $lines, 0, 24 );
        array_splice( $lines, 12, 0, 'BINGO' );
        $done = $wpdb->get_col( $wpdb->prepare( "SELECT tile_idx FROM " . $status_table . " WHERE board_id=%d", $b ) );
        $html .= '<div class="cdb-board" data-board="' . esc_attr( $b ) . '">';
        foreach ( $lines as $i => $text ) {
            $cls = 'cdb-tile' . ( in_array( $i, $done ) ? ' active' : '' );
            $html .= '<div class="' . esc_attr( $cls ) . '" data-board="' . esc_attr( $b ) . '" data-tile="' . esc_attr( $i ) . '">' . esc_html( $text ) . '</div>';
        }
        $html .= '</div>';
        // Rewards
        if ( ! empty( $board->rewards ) ) {
            $html .= '<h3>Rewards:</h3><ul>';
            foreach ( preg_split( '/\r\n|\r|\n/', $board->rewards ) as $line ) {
                $html .= '<li>' . esc_html( trim( $line ) ) . '</li>';
            }
            $html .= '</ul>';
        }
        // Punishment
        if ( ! empty( $board->punishment ) ) {
            $html .= '<h3>Punishment:</h3><ul>';
            foreach ( preg_split( '/\r\n|\r|\n/', $board->punishment ) as $line ) {
                $html .= '<li>' . esc_html( trim( $line ) ) . '</li>';
            }
            $html .= '</ul>';
        }
        return $html;
    }
}

new Configurable_Digital_Bingo();
