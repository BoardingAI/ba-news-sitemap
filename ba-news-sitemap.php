<?php
/*
Plugin Name: BoardingArea News Sitemap
Plugin URI: https://boardingarea.com
Description: Get your posts on Google News faster with a 'set & forget' sitemap. This plugin automatically creates a special news sitemap with your articles from the last 48 hours & works with Yoast SEO to help Google discover them right away.
Version: 1.0.0
Author: BoardingArea
Author URI: https://boardingarea.com
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/

if ( ! defined( 'ABSPATH' ) ) exit;

class BA_News_Sitemap {
    const VERSION       = '1.0.0';

    // Routing / cache / options
    const QV            = 'news_sitemap';
    const TRANSIENT     = 'ba_news_sitemap_xml_cache';
    const LASTBUILD_KEY = 'ba_news_sitemap_lastbuild';
    const OPT_KEY       = 'ba_news_sitemap_options';

    // Cron
    const CRON_HOOK     = 'ba_news_sitemap_cron';
    const CRON_SCHED    = 'ba_news_sitemap_ttl';

    // Guards
    private static $rendered = false;
    private static $last_count = 0;

    protected static $defaults = [
        'enabled'              => 1,
        'publication_name'     => '',
        'post_types'           => ['post'],
        // Hardcoded values for simplicity
        'language'             => '', // Always auto-detect
        'window_hours'         => 48,
        'max_urls'             => 1000,
        'cache_ttl'            => 600,
        'respect_noindex'      => 1,
    ];

    /* ========= Bootstrap ========= */

    public static function init() {
        // Routing & request capture (rewrite or no rewrite)
        add_action( 'init',               [ __CLASS__, 'add_rewrite' ] );
        add_filter( 'query_vars',         [ __CLASS__, 'query_vars' ] );
        add_action( 'parse_request',      [ __CLASS__, 'maybe_catch_direct' ], 0 ); // earliest
        add_action( 'template_redirect',  [ __CLASS__, 'maybe_render' ], 0 );

        // Cache invalidation on content changes
        add_action( 'save_post',                [ __CLASS__, 'purge_cache' ] );
        add_action( 'deleted_post',             [ __CLASS__, 'purge_cache' ] );
        add_action( 'trashed_post',             [ __CLASS__, 'purge_cache' ] );
        add_action( 'transition_post_status',   [ __CLASS__, 'purge_cache' ], 10, 3 );

        // Admin
        add_action( 'admin_menu',  [ __CLASS__, 'admin_menu' ] );
        add_action( 'admin_init',  [ __CLASS__, 'register_settings' ] );
        add_action( 'admin_post_ba_news_sitemap_action', [ __CLASS__, 'handle_admin_action' ] );

        // Cron
        add_filter( 'cron_schedules', [ __CLASS__, 'cron_schedules' ] );
        add_action( self::CRON_HOOK,  [ __CLASS__, 'cron_task' ] );

        // Options lifecycle
        add_action( 'update_option_' . self::OPT_KEY, [ __CLASS__, 'options_updated' ], 10, 2 );

        // Yoast SEO Integration Hooks
        add_filter( 'wpseo_sitemap_index_links', [ __CLASS__, 'yoast_index_links' ], 10, 1 );
        add_filter( 'wpseo_sitemap_index', [ __CLASS__, 'add_sitemap_to_yoast_index' ], 10, 1 );

        // WP-CLI
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            \WP_CLI::add_command( 'ba-news-sitemap', [ __CLASS__, 'cli' ] );
        }
    }

    public static function activate() {
        self::ensure_options();
        self::add_rewrite();
        flush_rewrite_rules();
        self::maybe_schedule();
    }

    public static function deactivate() {
        self::unschedule();
        flush_rewrite_rules();
        delete_transient( self::TRANSIENT );
        delete_option( self::LASTBUILD_KEY );
    }

    /* ========= Options ========= */

    protected static function ensure_options() {
        $opt = get_option( self::OPT_KEY, null );
        if ( ! is_array( $opt ) ) {
            $defaults = self::$defaults;
            $defaults['publication_name'] = get_bloginfo( 'name' );
            add_option( self::OPT_KEY, $defaults, '', false );
            return;
        }
        $merged = array_merge( self::$defaults, $opt );
        if ( empty( $merged['publication_name'] ) ) {
            $merged['publication_name'] = get_bloginfo( 'name' );
        }
        if ( $merged !== $opt ) {
            update_option( self::OPT_KEY, $merged, false );
        }
    }

    protected static function get_options() {
        self::ensure_options();
        $opt = get_option( self::OPT_KEY, [] );
        $opt = array_merge( self::$defaults, is_array( $opt ) ? $opt : [] );
        if ( empty( $opt['publication_name'] ) ) {
            $opt['publication_name'] = get_bloginfo( 'name' );
        }

        // Force simplified, user-friendly defaults, ignoring any saved values for these keys
        $opt['window_hours']        = 48;
        $opt['max_urls']            = 1000;
        $opt['cache_ttl']           = 600; // 10 minutes
        $opt['respect_noindex']     = 1; // Always on
        $opt['language']            = '';  // Always auto-detect

        return $opt;
    }

    public static function register_settings() {
        register_setting( 'ba_news_sitemap', self::OPT_KEY, [
            'type'              => 'array',
            'sanitize_callback' => [ __CLASS__, 'sanitize_options' ],
            'default'           => self::$defaults,
        ] );
    }

    public static function sanitize_options( $in ) {
        // Start with the full defaults, which includes our hardcoded values
        $clean = self::$defaults;

        // Get options from DB to preserve any non-UI settings if they exist
        $existing_options = get_option(self::OPT_KEY, []);
        $clean = array_merge($clean, $existing_options);

        // Sanitize the few user-configurable options
        $clean['enabled'] = isset( $in['enabled'] ) ? 1 : 0;
        $clean['publication_name'] = isset( $in['publication_name'] ) ? sanitize_text_field( $in['publication_name'] ) : '';

        // Sanitize the post_types array from checkboxes
        $clean['post_types'] = [];
        if ( ! empty( $in['post_types'] ) && is_array( $in['post_types'] ) ) {
            $available_post_types = array_keys( get_post_types( [ 'public' => true ] ) );
            foreach ( $in['post_types'] as $pt ) {
                if ( in_array( $pt, $available_post_types, true ) ) {
                    $clean['post_types'][] = sanitize_key( $pt );
                }
            }
        }

        // Failsafe: if user unchecks everything, default to 'post' to prevent empty sitemaps
        if ( empty( $clean['post_types'] ) ) {
            $clean['post_types'] = ['post'];
        }

        return $clean;
    }

    public static function options_updated( $old, $new ) {
        if ( (int)($old['enabled'] ?? 0) !== (int)($new['enabled'] ?? 0) ) {
            self::add_rewrite();
            flush_rewrite_rules();
        }
        self::reschedule();
        self::purge_cache();
        self::prewarm_cache();
    }

    /* ========= Routing ========= */

    public static function add_rewrite() {
        $opt = self::get_options();
        if ( (int) $opt['enabled'] === 1 ) {
            add_rewrite_rule( '^news-sitemap\.xml$', 'index.php?' . self::QV . '=1', 'top' );
        }
    }

    public static function query_vars( $vars ) {
        $vars[] = self::QV;
        return $vars;
    }

    public static function maybe_catch_direct( $wp ) {
        if ( self::$rendered ) return;
        $req = parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );
        if ( ! $req ) return;
        $req = rtrim( $req, '/' );
        if ( $req === rtrim( wp_parse_url( home_url( '/news-sitemap.xml' ), PHP_URL_PATH ), '/' ) ) {
            $wp->query_vars[self::QV] = 1;
        }
    }

    public static function maybe_render() {
        if ( self::$rendered ) return;

        $opt = self::get_options();
        if ( (int) $opt['enabled'] !== 1 ) return;
        if ( intval( get_query_var( self::QV ) ) !== 1 ) return;

        self::$rendered = true;
        
        $cached = get_transient( self::TRANSIENT );

        if ( false === $cached ) {
            try {
                $xml = self::safe_build_xml();
                set_transient( self::TRANSIENT, $xml, (int) $opt['cache_ttl'] );
                $cached = $xml;
            } catch ( \Throwable $e ) {
                $cached = self::empty_xml();
            }
        }
        
        // Self-heal corrupt cache
        if ( ! is_string( $cached ) ) {
            delete_transient( self::TRANSIENT );
            $cached = self::empty_xml();
        }

        nocache_headers();
        header( 'Content-Type: application/xml; charset=UTF-8' );
        echo $cached;
        exit;
    }

    /* ========= Yoast Integration ========= */

    public static function yoast_index_links( $links ) {
        $opt = self::get_options();
        if ( (int) $opt['enabled'] !== 1 ) {
            return $links;
        }

        $latest_post_args = [
            'post_type' => (array) $opt['post_types'], 'post_status' => 'publish', 'posts_per_page' => 1,
            'orderby' => 'modified', 'order' => 'DESC', 'fields' => 'ids',
            'suppress_filters' => true, 'no_found_rows' => true,
        ];
        $q = new \WP_Query( $latest_post_args );
        $lastmod = ( $q->have_posts() ) ? get_post_modified_time( 'c', true, $q->posts[0] ) : gmdate( 'c' );

        $entry = [ 'loc' => esc_url( home_url( '/news-sitemap.xml' ) ), 'lastmod' => $lastmod ];
        foreach ( $links as $l ) {
            if ( isset( $l['loc'] ) && rtrim( $l['loc'], '/' ) === rtrim( $entry['loc'], '/' ) ) return $links;
        }
        array_unshift( $links, $entry );
        return $links;
    }

    public static function add_sitemap_to_yoast_index( $sitemap_xml ) {
        $our_loc = esc_url( home_url( '/news-sitemap.xml' ) );
        if ( stripos( $sitemap_xml, '<loc>' . $our_loc . '</loc>' ) !== false ) return $sitemap_xml;

        $opt = self::get_options();
        if ( (int) $opt['enabled'] !== 1 ) return $sitemap_xml;
        
        $latest_post_args = [
            'post_type' => (array) $opt['post_types'], 'post_status' => 'publish', 'posts_per_page' => 1,
            'orderby' => 'modified', 'order' => 'DESC', 'fields' => 'ids',
            'suppress_filters' => true, 'no_found_rows' => true,
        ];
        $q = new \WP_Query( $latest_post_args );
        $lastmod = ( $q->have_posts() ) ? get_post_modified_time( 'c', true, $q->posts[0] ) : gmdate( 'c' );

        $entry = '<sitemap><loc>' . $our_loc . '</loc><lastmod>' . esc_html( $lastmod ) . '</lastmod></sitemap>';
        $patched = preg_replace( '/(<sitemapindex\b[^>]*>)/i', '$1' . $entry, $sitemap_xml, 1 );
        return $patched ?: $sitemap_xml;
    }

    /* ========= Admin UI ========= */

    public static function admin_menu() {
        add_options_page('BoardingArea News Sitemap', 'News Sitemap', 'manage_options', 'ba-news-sitemap', [ __CLASS__, 'settings_page' ]);
    }

    public static function settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        
        $opt = self::get_options();
        $meta = get_option( self::LASTBUILD_KEY, [] );
        $news_sitemap_url = home_url( '/news-sitemap.xml' );
        $base_sitemap_url = home_url( '/sitemap_index.xml' );
        ?>
        <div class="wrap">
            <h1>BoardingArea News Sitemap</h1>
            <p style="font-size: 1.1em; color: #50575e;">This plugin automatically creates and updates a special sitemap to help Google find your latest articles faster.</p>
            
            <?php if ( isset($_GET['settings-updated']) ): ?>
                <div id="setting-error-settings_updated" class="notice notice-success settings-error is-dismissible"> 
                    <p><strong>Settings saved.</strong></p>
                </div>
            <?php endif; ?>

            <div style="margin: 2em 0; padding: 1em 1.5em; background: #fff; border-left: 4px solid <?php echo (int)$opt['enabled'] === 1 ? '#72aee6' : '#d63638'; ?>; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                <h2 style="margin-top: 0;">Sitemap Status</h2>
                <?php if ( (int) $opt['enabled'] === 1 ): ?>
                    <?php
                    $status_text = '<span style="color: #2271b1; font-weight: 600;">Active</span>';
                    $article_count = $meta['count'] ?? 0;
                    $last_updated_text = 'Never';
                    if ( ! empty( $meta['generated_at'] ) ) {
                        $last_build_time = strtotime( $meta['generated_at'] );
                        if ( $last_build_time ) {
                            $last_updated_text = sprintf( '%s ago', human_time_diff( $last_build_time, current_time( 'timestamp', true ) ) );
                        }
                    }
                    ?>
                    <p style="font-size: 16px; margin: 1em 0;">Your news sitemap is <?php echo $status_text; ?> and running smoothly.</p>
                    <ul style="list-style: none; margin: 0; font-size: 14px; line-height: 1.8;">
                        <li><strong>Status:</strong> On &amp; Working</li>
                        <li><strong>Contents:</strong> Currently includes <strong><?php echo esc_html( $article_count ); ?></strong> recent articles</li>
                        <li><strong>Last Updated:</strong> <?php echo esc_html( $last_updated_text ); ?></li>
                        <li><strong>News Sitemap Link:</strong> <a href="<?php echo esc_url($news_sitemap_url); ?>" target="_blank" rel="noopener"><?php echo esc_html($news_sitemap_url); ?></a></li>
                        <li><strong>Base Sitemap Index:</strong> <a href="<?php echo esc_url($base_sitemap_url); ?>" target="_blank" rel="noopener"><?php echo esc_html($base_sitemap_url); ?></a></li>
                    </ul>
                <?php else: ?>
                    <?php $status_text = '<span style="color: #d63638; font-weight: 600;">Inactive</span>'; ?>
                    <p style="font-size: 16px; margin: 1em 0;">Your news sitemap is currently <?php echo $status_text; ?>.</p>
                     <ul style="list-style: none; margin: 0; font-size: 14px; line-height: 1.8;">
                        <li><strong>Status:</strong> Off &amp; Disabled</li>
                        <li><strong>Contents:</strong> 0 articles</li>
                        <li><strong>Last Updated:</strong> N/A</li>
                        <li><strong>News Sitemap Link:</strong> <code><?php echo esc_html($news_sitemap_url); ?></code> (disabled)</li>
                        <li><strong>Base Sitemap Index:</strong> <a href="<?php echo esc_url($base_sitemap_url); ?>" target="_blank" rel="noopener"><?php echo esc_html($base_sitemap_url); ?></a></li>
                    </ul>
                <?php endif; ?>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields( 'ba_news_sitemap' ); ?>
                
                <h2>Settings</h2>
                <table class="form-table" role="presentation">
                    <tr valign="top">
                        <th scope="row">Enable Sitemap</th>
                        <td>
                            <label for="ba_news_sitemap_enabled">
                                <input type="checkbox" id="ba_news_sitemap_enabled" name="<?php echo esc_attr(self::OPT_KEY); ?>[enabled]" value="1" <?php checked( 1, (int) $opt['enabled'] ); ?>>
                                Automatically create and update the News Sitemap.
                            </label>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">
                            <label for="ba_news_sitemap_publication_name">Publication Name</label>
                        </th>
                        <td>
                            <input type="text" id="ba_news_sitemap_publication_name" class="regular-text" name="<?php echo esc_attr(self::OPT_KEY); ?>[publication_name]" value="<?php echo esc_attr( $opt['publication_name'] ); ?>" placeholder="<?php echo esc_attr( get_bloginfo('name') ); ?>">
                            <p class="description">The name of your blog as it should appear in Google News. Defaults to your site title.</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Include Content Types</th>
                        <td>
                            <fieldset>
                                <legend class="screen-reader-text"><span>Include Content Types</span></legend>
                                <?php
                                $post_types = get_post_types( [ 'public' => true ], 'objects' );
                                unset($post_types['attachment']); // Attachments should not be in sitemaps
                                $saved_post_types = (array) $opt['post_types'];

                                foreach ( $post_types as $slug => $pt ) {
                                    ?>
                                    <label for="pt_<?php echo esc_attr($slug); ?>" style="display: block; margin-bottom: 5px;">
                                        <input type="checkbox" id="pt_<?php echo esc_attr($slug); ?>" name="<?php echo esc_attr(self::OPT_KEY); ?>[post_types][]" value="<?php echo esc_attr($slug); ?>" <?php checked( in_array( $slug, $saved_post_types, true ) ); ?>>
                                        <?php echo esc_html( $pt->label ); ?>
                                    </label>
                                    <?php
                                }
                                ?>
                            </fieldset>
                             <p class="description">Select which types of content to include. It's usually best to only include your main article types, like "Posts".</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
            
            <details style="margin-top: 2em; border: 1px solid #c3c4c7; padding: 1em; background: #fff;">
                <summary style="font-size: 1.1em; font-weight: 600; cursor: pointer;">Troubleshooting &amp; Tools</summary>
                <div style="margin-top: 1em;">
                    <p>If you think your sitemap is out of date or not showing your latest post, you can manually refresh it.</p>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <?php wp_nonce_field( 'ba_news_sitemap_action' ); ?>
                        <input type="hidden" name="action" value="ba_news_sitemap_action">
                        <input type="hidden" name="op" value="rebuild">
                        <?php submit_button( 'Force Refresh Now', 'secondary', 'submit', false ); ?>
                    </form>
                </div>
            </details>
        </div>
        <?php
    }

    public static function add_settings_link( $links ) {
        $settings_url = admin_url( 'options-general.php?page=ba-news-sitemap' );
        $settings_link = '<a href="' . esc_url( $settings_url ) . '">' . __( 'Settings', 'ba-news-sitemap' ) . '</a>';
        array_unshift( $links, $settings_link );
        return $links;
    }

    public static function handle_admin_action() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );
        check_admin_referer( 'ba_news_sitemap_action' );

        $op = isset( $_POST['op'] ) ? sanitize_key( $_POST['op'] ) : '';
        switch ( $op ) {
            case 'rebuild':
                self::prewarm_cache();
                break;
        }
        wp_safe_redirect( add_query_arg( [ 'page' => 'ba-news-sitemap', 'msg' => 'done' ], admin_url( 'options-general.php' ) ) );
        exit;
    }

    /* ========= Cron / Cache ========= */

    public static function cron_schedules( $schedules ) {
        $ttl = (int) self::get_options()['cache_ttl'];
        $schedules[ self::CRON_SCHED ] = [ 'interval' => max( 60, $ttl ), 'display'  => 'BA News Sitemap (Cache TTL)'];
        return $schedules;
    }

    protected static function maybe_schedule() {
        $opt = self::get_options();
        if ( (int) $opt['enabled'] !== 1 ) return;
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + 60, self::CRON_SCHED, self::CRON_HOOK );
        }
    }

    protected static function reschedule() {
        self::unschedule();
        self::maybe_schedule();
    }

    protected static function unschedule() {
        $ts = wp_next_scheduled( self::CRON_HOOK );
        while ( $ts ) {
            wp_unschedule_event( $ts, self::CRON_HOOK );
            $ts = wp_next_scheduled( self::CRON_HOOK );
        }
    }

    public static function cron_task() {
        $opt = self::get_options();
        if ( (int) $opt['enabled'] !== 1 ) return;
        self::prewarm_cache();
    }

    public static function purge_cache() {
        delete_transient( self::TRANSIENT );
    }

    protected static function prewarm_cache() {
        $opt = self::get_options();
        if ( (int) $opt['enabled'] !== 1 ) return;

        try {
            $start = microtime( true );
            $xml = self::safe_build_xml();
            set_transient( self::TRANSIENT, $xml, (int) $opt['cache_ttl'] );

            $meta = [
                'generated_at' => gmdate( 'c' ),
                'count'        => (int) self::$last_count,
                'took_ms'      => (int) round( ( microtime( true ) - $start ) * 1000 ),
            ];
            update_option( self::LASTBUILD_KEY, $meta, false );
        } catch ( \Throwable $e ) {
            // In case of failure, don't leave a stale cache.
            set_transient( self::TRANSIENT, self::empty_xml(), (int) $opt['cache_ttl'] );
        }
    }

    /* ========= XML Builders ========= */

    protected static function safe_build_xml() {
        @set_time_limit( 10 );
        $posts = self::get_recent_posts( self::get_options() );
        self::$last_count = is_array( $posts ) ? count( $posts ) : 0;

        if ( empty( $posts ) ) {
            return self::empty_xml();
        }
        if ( class_exists( 'DOMDocument' ) ) {
            return self::build_xml_dom( $posts );
        }
        return self::build_xml_string( $posts );
    }

    protected static function build_xml_dom( $post_ids ) {
        $opt     = self::get_options();
        $pubname = $opt['publication_name'];
        $lang    = self::news_language( $opt );

        $dom = new \DOMDocument( '1.0', 'UTF-8' );
        $dom->formatOutput = true;

        $xsl_url = plugins_url( 'boardingarea-sitemap.xsl', __FILE__ );
        $xsl = $dom->createProcessingInstruction( 'xml-stylesheet', 'type="text/xsl" href="' . esc_url( $xsl_url ) . '"' );
        $dom->appendChild( $xsl );

        $urlset = $dom->createElement( 'urlset' );
        $urlset->setAttribute( 'xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9' );
        $urlset->setAttribute( 'xmlns:news', 'http://www.google.com/schemas/sitemap-news/0.9' );
        $urlset->setAttribute( 'xmlns:image', 'http://www.google.com/schemas/sitemap-image/1.1' );

        foreach ( $post_ids as $post_id ) {
            $loc     = get_permalink( $post_id );
            if ( ! $loc ) continue;
            
            if ( (int) $opt['respect_noindex'] === 1 && self::is_noindex( $post_id ) ) continue;

            $lastmod = get_post_modified_time( 'c', true, $post_id );
            $pubdate = get_post_time( 'c', true, $post_id );
            $title   = wp_strip_all_tags( get_the_title( $post_id ), true );

            $url = $dom->createElement( 'url' );
            $url->appendChild( $dom->createElement( 'loc', $loc ) );
            $url->appendChild( $dom->createElement( 'lastmod', $lastmod ) );
            
            // Add image data if a featured image exists
            if ( has_post_thumbnail( $post_id ) ) {
                $thumb_id = get_post_thumbnail_id( $post_id );
                $img_data = wp_get_attachment_image_src( $thumb_id, 'full' );
                if ( $img_data && ! empty( $img_data[0] ) ) {
                    $image = $dom->createElement('image:image');
                    $image->appendChild( $dom->createElement('image:loc', $img_data[0]) );
                    $url->appendChild($image);
                }
            }

            $news = $dom->createElement( 'news:news' );
            $publication = $dom->createElement( 'news:publication' );
            $publication->appendChild( $dom->createElement( 'news:name' ) )->appendChild( $dom->createCDATASection( $pubname ) );
            $publication->appendChild( $dom->createElement( 'news:language', $lang ) );
            $news->appendChild( $publication );
            $news->appendChild( $dom->createElement( 'news:publication_date', $pubdate ) );

            $titleNode = $dom->createElement( 'news:title' );
            $titleNode->appendChild( $dom->createCDATASection( $title ) );
            $news->appendChild( $titleNode );
            
            $keywords = self::post_keywords( $post_id );
            if ( ! empty( $keywords ) ) {
                $kwNode = $dom->createElement( 'news:keywords' );
                $kwNode->appendChild( $dom->createCDATASection( implode( ', ', array_slice( $keywords, 0, 10 ) ) ) );
                $news->appendChild( $kwNode );
            }

            $url->appendChild( $news );
            $urlset->appendChild( $url );
        }

        $dom->appendChild( $urlset );
        return $dom->saveXML();
    }

    protected static function build_xml_string( $post_ids ) {
        $opt     = self::get_options();
        $pubname = self::cdata( $opt['publication_name'] );
        $lang    = self::news_language( $opt );

        $out  = '<?xml version="1.0" encoding="UTF-8"?>';
        $xsl_url = plugins_url( 'boardingarea-sitemap.xsl', __FILE__ );
        $out .= '<?xml-stylesheet type="text/xsl" href="' . esc_url( $xsl_url ) . '"?>';
        
        $out .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:news="http://www.google.com/schemas/sitemap-news/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">';

        foreach ( $post_ids as $post_id ) {
            $loc     = get_permalink( $post_id );
            if ( ! $loc ) continue;

            if ( (int) $opt['respect_noindex'] === 1 && self::is_noindex( $post_id ) ) continue;

            $lastmod = get_post_modified_time( 'c', true, $post_id );
            $pubdate = get_post_time( 'c', true, $post_id );
            $title   = self::cdata( wp_strip_all_tags( get_the_title( $post_id ), true ) );

            $out .= '<url>';
            $out .= '<loc>' . esc_url( $loc ) . '</loc>';
            $out .= '<lastmod>' . esc_html( $lastmod ) . '</lastmod>';

            // Add image data if a featured image exists
            if ( has_post_thumbnail( $post_id ) ) {
                $thumb_id = get_post_thumbnail_id( $post_id );
                $img_data = wp_get_attachment_image_src( $thumb_id, 'full' );
                if ( $img_data && ! empty( $img_data[0] ) ) {
                    $out .= '<image:image><image:loc>' . esc_url($img_data[0]) . '</image:loc></image:image>';
                }
            }

            $out .= '<news:news>';
            $out .= '<news:publication><news:name>' . $pubname . '</news:name><news:language>' . esc_html( $lang ) . '</news:language></news:publication>';
            $out .= '<news:publication_date>' . esc_html( $pubdate ) . '</news:publication_date>';
            $out .= '<news:title>' . $title . '</news:title>';

            $keywords = self::post_keywords( $post_id );
            if ( ! empty( $keywords ) ) {
                $out .= '<news:keywords>' . self::cdata( implode( ', ', array_slice( $keywords, 0, 10 ) ) ) . '</news:keywords>';
            }

            $out .= '</news:news>';
            $out .= '</url>';
        }

        $out .= '</urlset>';
        return $out;
    }

    protected static function cdata( $str ) {
        $str = (string) $str;
        return '<![CDATA[' . str_replace(']]>', ']]]]><![CDATA[>', $str) . ']]>';
    }

    protected static function empty_xml() {
        $xsl_url = plugins_url( 'boardingarea-sitemap.xsl', __FILE__ );
        $xsl_line = '<?xml-stylesheet type="text/xsl" href="' . esc_url( $xsl_url ) . '"?>';
        return '<?xml version="1.0" encoding="UTF-8"?>' . $xsl_line
             . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:news="http://www.google.com/schemas/sitemap-news/0.9"></urlset>';
    }

    /* ========= Data helpers ========= */

    protected static function get_recent_posts( $opt ) {
        $post_types = (array) $opt['post_types'];
        $hours      = max( 1, (int) $opt['window_hours'] );
        $after_gmt  = gmdate( 'Y-m-d H:i:s', time() - ( $hours * HOUR_IN_SECONDS ) );

        $args = [
            'post_type'           => $post_types, 'post_status'         => 'publish',
            'date_query'          => [ [ 'column' => 'post_date_gmt', 'after' => $after_gmt, 'inclusive' => true ] ],
            'orderby'             => 'date', 'order' => 'DESC',
            'posts_per_page'      => max( 1, (int) $opt['max_urls'] ),
            'ignore_sticky_posts' => true, 'no_found_rows' => true,
            'suppress_filters'    => true, 'fields' => 'ids',
        ];

        $q = new \WP_Query( $args );
        return $q->posts;
    }

    protected static function is_noindex( $post_id ) {
        if ( '1' === (string) get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true ) ) return true;
        $rm = get_post_meta( $post_id, 'rank_math_robots', true );
        if ( is_array( $rm ) && in_array( 'noindex', $rm, true ) ) return true;
        $aio = get_post_meta( $post_id, '_aioseo_robots_default', true );
        if ( is_array( $aio ) && ( $aio['noindex'] ?? false ) ) return true;
        if ( get_post_meta( $post_id, '_exclude_from_news_sitemap', true ) ) return true;
        return false;
    }

    protected static function news_language( $opt ) {
        if ( ! empty( $opt['language'] ) ) {
            return strtolower( substr( $opt['language'], 0, 5 ) );
        }
        return strtolower( substr( get_locale(), 0, 2 ) );
    }

    protected static function post_keywords( $post_id ) {
        $terms = wp_get_post_terms( $post_id, [ 'category', 'post_tag' ], [ 'fields' => 'names' ] );
        if ( is_wp_error( $terms ) || empty( $terms ) ) return [];
        return array_values( array_unique( array_filter( array_map( 'trim', $terms ) ) ) );
    }

    /* ========= WP-CLI ========= */

    public static function cli( $args, $assoc ) {
        if ( ! class_exists( 'WP_CLI' ) ) return;
        $sub = $args[0] ?? 'help';
        switch ( $sub ) {
            case 'rebuild':
            case 'purge':
                self::prewarm_cache();
                \WP_CLI::success( 'Cache purged and rebuilt.' );
                break;
            case 'print':
                \WP_CLI::line( self::safe_build_xml() );
                break;
            default:
                \WP_CLI::line( "Usage:\n  wp ba-news-sitemap rebuild   # Purge cache and rebuild sitemap\n  wp ba-news-sitemap print     # Print XML to stdout" );
        }
    }
}

/* ========= Lifecycle ========= */
add_action( 'plugins_loaded', [ 'BA_News_Sitemap', 'init' ] );
register_activation_hook( __FILE__, [ 'BA_News_Sitemap', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'BA_News_Sitemap', 'deactivate' ] );
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), [ 'BA_News_Sitemap', 'add_settings_link' ] );
