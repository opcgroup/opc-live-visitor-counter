<?php
/**
 * Plugin Name: OPC Live Visitor Counter
 * Description: Responsive live website counter for online, today, yesterday and total unique visitors, with configurable starting numbers.
 * Version: 1.1.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Update URI: https://github.com/opcgroup/opc-live-visitor-counter
 * Author: OPC Group
 * License: GPL-2.0-or-later
 * Text Domain: opc-live-visitor-counter
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class OPC_Live_Visitor_Counter {
    const VERSION = '1.1.0';
    const OPTION  = 'opcvc_settings';
    const SEEDS   = 'opcvc_daily_seeds';
    const CACHE   = 'opcvc_cached_stats';
    const ONLINE_MINUTES = 5;
    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_shortcode( 'opc_visitor_counter', array( $this, 'shortcode' ) );
        add_action( 'wp_footer', array( $this, 'maybe_auto_footer' ), 30 );
        add_action( 'wp_ajax_opcvc_ping', array( $this, 'ajax_ping' ) );
        add_action( 'wp_ajax_nopriv_opcvc_ping', array( $this, 'ajax_ping' ) );
        add_action( 'wp_ajax_opcvc_stats', array( $this, 'ajax_stats' ) );
        add_action( 'wp_ajax_nopriv_opcvc_stats', array( $this, 'ajax_stats' ) );
        add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
        add_action( 'admin_post_opcvc_save_settings', array( $this, 'save_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
    }

    public static function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $visitors = $wpdb->prefix . 'opcvc_visitors';
        $daily    = $wpdb->prefix . 'opcvc_daily';

        $queries = array(
            "CREATE TABLE {$visitors} (
                visitor_hash char(64) NOT NULL,
                first_seen datetime NOT NULL,
                last_seen datetime NOT NULL,
                PRIMARY KEY  (visitor_hash),
                KEY last_seen (last_seen)
            ) {$charset};",
            "CREATE TABLE {$daily} (
                visit_date date NOT NULL,
                visitor_hash char(64) NOT NULL,
                first_seen datetime NOT NULL,
                PRIMARY KEY  (visit_date,visitor_hash),
                KEY visitor_hash (visitor_hash)
            ) {$charset};",
        );
        dbDelta( $queries );

        if ( false === get_option( self::OPTION, false ) ) {
            add_option(
                self::OPTION,
                array(
                    'starting_total' => 0,
                    'heading' => 'Our Website Visitors',
                    'accent' => '#56e0d0',
                    'auto_footer' => 0,
                    'exclude_admins' => 1,
                ),
                '',
                false
            );
        }
    }

    private function get_options() {
        $defaults = array(
            'starting_total' => 0,
            'heading'        => 'Our Website Visitors',
            'accent'         => '#56e0d0',
            'auto_footer'    => 0,
            'exclude_admins' => 1,
        );
        $stored = get_option( self::OPTION, array() );
        return wp_parse_args( is_array( $stored ) ? $stored : array(), $defaults );
    }

    private function visitor_table() {
        global $wpdb;
        return $wpdb->prefix . 'opcvc_visitors';
    }

    private function daily_table() {
        global $wpdb;
        return $wpdb->prefix . 'opcvc_daily';
    }

    private function local_day_dates() {
        $now = new DateTimeImmutable( 'now', wp_timezone() );
        return array( $now->format( 'Y-m-d' ), $now->modify( '-1 day' )->format( 'Y-m-d' ) );
    }

    private function tracking_is_disabled() {
        $settings = $this->get_options();
        return (bool) $settings['exclude_admins'] && current_user_can( 'manage_options' );
    }

    public function enqueue_assets() {
        if ( is_admin() ) {
            return;
        }
        $settings = $this->get_options();
        wp_enqueue_style( 'opcvc-public', plugin_dir_url( __FILE__ ) . 'assets/counter.css', array(), self::VERSION );
        wp_enqueue_script( 'opcvc-public', plugin_dir_url( __FILE__ ) . 'assets/counter.js', array(), self::VERSION, true );
        wp_localize_script(
            'opcvc-public',
            'OPCVC',
            array(
                'endpoint' => admin_url( 'admin-ajax.php' ),
                'track'    => ! $this->tracking_is_disabled(),
                'refresh'  => 60000,
            )
        );
    }

    private function icon( $type ) {
        $paths = array(
            'online'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
            'today'     => '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M8 2v4M16 2v4M3 10h18M8 15h3"/>',
            'yesterday' => '<path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 3v5h5M12 7v5l4 2"/>',
            'total'     => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
        );
        return '<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $paths[ $type ] . '</svg>';
    }

    public function shortcode( $atts = array() ) {
        $settings = $this->get_options();
        $atts = shortcode_atts( array( 'title' => '' ), $atts, 'opc_visitor_counter' );
        $title = '' !== $atts['title'] ? sanitize_text_field( $atts['title'] ) : $settings['heading'];
        $accent = sanitize_hex_color( $settings['accent'] );
        if ( ! $accent ) {
            $accent = '#56e0d0';
        }
        $metrics = array(
            'online'    => array( 'Online Now', 'Active in the last 5 minutes' ),
            'today'     => array( 'Today', 'Unique visitors today' ),
            'yesterday' => array( 'Yesterday', 'Unique visitors yesterday' ),
            'total'     => array( 'Total Visitors', 'All-time unique visitors' ),
        );

        ob_start();
        ?>
        <section class="opcvc-section" data-opcvc-section style="--opcvc-accent:<?php echo esc_attr( $accent ); ?>" aria-label="Website visitor statistics">
            <div class="opcvc-header">
                <div>
                    <div class="opcvc-overline"><span class="opcvc-live-dot" aria-hidden="true"></span> LIVE WEBSITE STATS</div>
                    <h2 class="opcvc-title"><?php echo esc_html( $title ); ?></h2>
                    <p class="opcvc-subtitle">A look at our website community in real time</p>
                </div>
                <div class="opcvc-status"><span class="opcvc-status-dot" aria-hidden="true"></span> Live updates</div>
            </div>
            <div class="opcvc-grid">
                <?php foreach ( $metrics as $key => $metric ) : ?>
                    <div class="opcvc-card opcvc-card-<?php echo esc_attr( $key ); ?>">
                        <div class="opcvc-card-top">
                            <span class="opcvc-icon"><?php echo $this->icon( $key ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static, trusted SVG. ?></span>
                            <?php if ( 'online' === $key ) : ?><span class="opcvc-online-pill">● LIVE</span><?php endif; ?>
                        </div>
                        <div class="opcvc-value" data-opcvc-value="<?php echo esc_attr( $key ); ?>" aria-label="<?php echo esc_attr( $metric[0] ); ?>">—</div>
                        <div class="opcvc-label"><?php echo esc_html( $metric[0] ); ?></div>
                        <div class="opcvc-description"><?php echo esc_html( $metric[1] ); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="opcvc-footnote"><span class="opcvc-footnote-dot"></span> Visitor statistics refresh automatically. Times follow this website's timezone.</div>
        </section>
        <?php
        return ob_get_clean();
    }

    public function maybe_auto_footer() {
        $settings = $this->get_options();
        if ( ! empty( $settings['auto_footer'] ) && ! is_admin() ) {
            echo do_shortcode( '[opc_visitor_counter]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in shortcode markup.
        }
    }

    private function is_bot() {
        $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
        return '' === trim( $ua ) || (bool) preg_match( '/bot|crawl|spider|slurp|facebookexternalhit|headless|lighthouse|pagespeed|pingdom|uptimerobot|curl|wget/i', $ua );
    }

    public function ajax_ping() {
        if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( $_SERVER['REQUEST_METHOD'] ) : '' ) ) {
            wp_send_json_error( array( 'message' => 'POST required.' ), 405 );
        }

        $raw_id = isset( $_POST['visitor_id'] ) && is_string( $_POST['visitor_id'] ) ? wp_unslash( $_POST['visitor_id'] ) : '';
        if ( ! preg_match( '/^[a-f0-9]{32}$/D', $raw_id ) ) {
            wp_send_json_error( array( 'message' => 'Invalid visitor identifier.' ), 400 );
        }

        if ( ! $this->tracking_is_disabled() && ! $this->is_bot() ) {
            global $wpdb;
            $hash = hash_hmac( 'sha256', $raw_id, wp_salt( 'auth' ) );
            $now = ( new DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y-m-d H:i:s' );
            list( $today ) = $this->local_day_dates();
            $visitor_table = $this->visitor_table();
            $daily_table = $this->daily_table();

            // The unique visitor identifier never enters the database in raw form.
            $wpdb->query(
                $wpdb->prepare(
                    "INSERT INTO {$visitor_table} (visitor_hash, first_seen, last_seen) VALUES (%s, %s, %s)
                     ON DUPLICATE KEY UPDATE last_seen = VALUES(last_seen)",
                    $hash, $now, $now
                )
            );
            $wpdb->query(
                $wpdb->prepare(
                    "INSERT IGNORE INTO {$daily_table} (visit_date, visitor_hash, first_seen) VALUES (%s, %s, %s)",
                    $today, $hash, $now
                )
            );
            delete_transient( self::CACHE );
        }
        nocache_headers();
        wp_send_json_success( $this->get_stats() );
    }

    public function ajax_stats() {
        nocache_headers();
        wp_send_json_success( $this->get_stats() );
    }

    private function get_stats() {
        $cached = get_transient( self::CACHE );
        if ( is_array( $cached ) ) {
            return $cached;
        }
        global $wpdb;
        list( $today, $yesterday ) = $this->local_day_dates();
        $visitors = $this->visitor_table();
        $daily = $this->daily_table();
        $threshold = ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( '-' . self::ONLINE_MINUTES . ' minutes' )->format( 'Y-m-d H:i:s' );

        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$visitors}" );
        $online = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$visitors} WHERE last_seen >= %s", $threshold ) );
        $daily_counts = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT visit_date, COUNT(*) AS number FROM {$daily} WHERE visit_date IN (%s, %s) GROUP BY visit_date",
                $today, $yesterday
            ),
            ARRAY_A
        );
        $counts = array( $today => 0, $yesterday => 0 );
        foreach ( (array) $daily_counts as $row ) {
            $counts[ $row['visit_date'] ] = (int) $row['number'];
        }

        $settings = $this->get_options();
        $seeds = get_option( self::SEEDS, array() );
        $seeds = is_array( $seeds ) ? $seeds : array();
        $result = array(
            'online'    => max( 0, $online ),
            'today'     => max( 0, $counts[ $today ] + (int) ( $seeds[ $today ] ?? 0 ) ),
            'yesterday' => max( 0, $counts[ $yesterday ] + (int) ( $seeds[ $yesterday ] ?? 0 ) ),
            'total'     => max( 0, $total + (int) $settings['starting_total'] ),
        );
        set_transient( self::CACHE, $result, 12 );
        return $result;
    }

    public function add_settings_page() {
        add_options_page(
            'OPC Live Visitor Counter',
            'OPC Visitor Counter',
            'manage_options',
            'opc-visitor-counter',
            array( $this, 'settings_page' )
        );
    }

    public function enqueue_admin_assets( $hook ) {
        if ( 'settings_page_opc-visitor-counter' === $hook ) {
            wp_enqueue_style( 'opcvc-admin', plugin_dir_url( __FILE__ ) . 'assets/admin.css', array(), self::VERSION );
        }
    }

    public function save_settings() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You are not allowed to change these settings.', 'opc-live-visitor-counter' ) );
        }
        check_admin_referer( 'opcvc_save_settings' );
        $old = $this->get_options();
        $raw = isset( $_POST['opcvc'] ) && is_array( $_POST['opcvc'] ) ? wp_unslash( $_POST['opcvc'] ) : array();
        $accent = isset( $raw['accent'] ) ? sanitize_hex_color( $raw['accent'] ) : false;
        $settings = array(
            'starting_total' => min( 999999999999, max( 0, (int) ( $raw['starting_total'] ?? 0 ) ) ),
            'heading'        => sanitize_text_field( $raw['heading'] ?? $old['heading'] ),
            'accent'         => $accent ? $accent : '#56e0d0',
            'auto_footer'    => empty( $raw['auto_footer'] ) ? 0 : 1,
            'exclude_admins' => empty( $raw['exclude_admins'] ) ? 0 : 1,
        );
        update_option( self::OPTION, $settings, false );

        list( $today, $yesterday ) = $this->local_day_dates();
        $seeds = get_option( self::SEEDS, array() );
        $seeds = is_array( $seeds ) ? $seeds : array();
        $seeds[ $today ] = min( 999999999999, max( 0, (int) ( $raw['starting_today'] ?? 0 ) ) );
        $seeds[ $yesterday ] = min( 999999999999, max( 0, (int) ( $raw['starting_yesterday'] ?? 0 ) ) );
        // Preserve recent daily baselines so today's baseline rolls into tomorrow's Yesterday.
        $oldest = ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( '-7 days' )->format( 'Y-m-d' );
        foreach ( $seeds as $date => $value ) {
            if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || $date < $oldest ) {
                unset( $seeds[ $date ] );
            }
        }
        update_option( self::SEEDS, $seeds, false );
        delete_transient( self::CACHE );
        wp_safe_redirect( add_query_arg( array( 'page' => 'opc-visitor-counter', 'updated' => '1' ), admin_url( 'options-general.php' ) ) );
        exit;
    }

    public function settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $settings = $this->get_options();
        list( $today, $yesterday ) = $this->local_day_dates();
        $seeds = get_option( self::SEEDS, array() );
        $seeds = is_array( $seeds ) ? $seeds : array();
        $stats = $this->get_stats();
        ?>
        <div class="wrap opcvc-admin-wrap">
            <div class="opcvc-admin-intro"><span class="opcvc-admin-eyebrow">OPC GROUP · WORDPRESS PLUGIN</span>
                <h1>Live Visitor Counter</h1>
                <p>Manage the starting numbers and appearance of your all-in-one visitor statistics section.</p>
            </div>
            <?php if ( isset( $_GET['updated'] ) && '1' === $_GET['updated'] ) : ?>
                <div class="notice notice-success is-dismissible"><p>Visitor counter settings saved.</p></div>
            <?php endif; ?>
            <div class="opcvc-admin-overview">
                <div><span>Online now</span><strong><?php echo esc_html( number_format_i18n( $stats['online'] ) ); ?></strong></div>
                <div><span>Today</span><strong><?php echo esc_html( number_format_i18n( $stats['today'] ) ); ?></strong></div>
                <div><span>Yesterday</span><strong><?php echo esc_html( number_format_i18n( $stats['yesterday'] ) ); ?></strong></div>
                <div><span>Total visitors</span><strong><?php echo esc_html( number_format_i18n( $stats['total'] ) ); ?></strong></div>
            </div>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="opcvc-admin-panel">
                <?php wp_nonce_field( 'opcvc_save_settings' ); ?>
                <input type="hidden" name="action" value="opcvc_save_settings">
                <h2>Starting Numbers</h2>
                <p class="description">Set your own starting numbers. Each value is a display baseline; actual newly tracked unique visitors are added automatically.</p>
                <div class="opcvc-admin-fields">
                    <label><span>Total Visitors Starting Number</span>
                        <input type="number" min="0" max="999999999999" step="1" name="opcvc[starting_total]" value="<?php echo esc_attr( $settings['starting_total'] ); ?>">
                        <small>Example: set 50000 → display 50,000 + newly recorded lifetime visitors.</small>
                    </label>
                    <label><span>Today's Starting Number <em>(<?php echo esc_html( $today ); ?>)</em></span>
                        <input type="number" min="0" max="999999999999" step="1" name="opcvc[starting_today]" value="<?php echo esc_attr( $seeds[ $today ] ?? 0 ); ?>">
                        <small>Added to today's measured unique visitors. Resets as the date changes.</small>
                    </label>
                    <label><span>Yesterday's Starting Number <em>(<?php echo esc_html( $yesterday ); ?>)</em></span>
                        <input type="number" min="0" max="999999999999" step="1" name="opcvc[starting_yesterday]" value="<?php echo esc_attr( $seeds[ $yesterday ] ?? 0 ); ?>">
                        <small>Added to yesterday's measured unique visitors.</small>
                    </label>
                </div>
                <h2>Display & Appearance</h2>
                <div class="opcvc-admin-fields">
                    <label><span>Section Heading</span>
                        <input type="text" maxlength="100" name="opcvc[heading]" value="<?php echo esc_attr( $settings['heading'] ); ?>">
                    </label>
                    <label><span>Accent Color</span>
                        <input type="color" name="opcvc[accent]" value="<?php echo esc_attr( $settings['accent'] ); ?>">
                    </label>
                </div>
                <div class="opcvc-admin-checkbox">
                    <label><input type="checkbox" name="opcvc[auto_footer]" value="1" <?php checked( $settings['auto_footer'], 1 ); ?>> Automatically display the counter above the website footer</label>
                    <label><input type="checkbox" name="opcvc[exclude_admins]" value="1" <?php checked( $settings['exclude_admins'], 1 ); ?>> Do not count logged-in WordPress administrators (recommended)</label>
                </div>
                <p class="submit"><button type="submit" class="button button-primary button-hero">Save Counter Settings</button></p>
            </form>
            <div class="opcvc-admin-help">
                <h2>Put the full counter section anywhere</h2>
                <p>Paste this shortcode into a WordPress Shortcode block, Elementor Shortcode widget, or compatible page builder:</p>
                <code>[opc_visitor_counter]</code>
                <p>Optional custom title: <code>[opc_visitor_counter title="Our Live Website Traffic"]</code></p>
                <p><strong>Tracking:</strong> Counts anonymous, browser-based unique visitors across all public pages (not page views). An online visitor is active within the last five minutes. Counters refresh approximately every minute.</p>
                <p><strong>Privacy:</strong> Uses a first-party random visitor ID in browser storage and stores only its salted hash in WordPress, not IP addresses. Include this usage in your privacy/cookie policy and apply any consent rules relevant to your visitors.</p>
            </div>
        </div>
        <?php
    }
}

register_activation_hook( __FILE__, array( 'OPC_Live_Visitor_Counter', 'activate' ) );
require_once plugin_dir_path( __FILE__ ) . 'includes/class-opcvc-github-updates.php';
OPCVC_GitHub_Updates::init( __FILE__ );
OPC_Live_Visitor_Counter::instance();
