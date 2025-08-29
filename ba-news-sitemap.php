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
    const PING_THROTTLE_KEY = 'ba_news_sitemap_ping_throttle';
    const BUILD_LOCK_KEY = 'ba_news_sitemap_build_lock';

    // Cron
    const CRON_HOOK     = 'ba_news_sitemap_cron';
    const PREWARM_HOOK  = 'ba_news_sitemap_prewarm_hook';
    const CRON_SCHED    = 'ba_news_sitemap_ttl';

    // Guards
    private static $rendered = false;
    private static $last_count = 0;

    protected static $defaults = [
        'enabled'              => 1,
        'publication_name'     => '',
        'post_types'           => ['post'],
        'default_genres'       => ['Blog'],
        'disable_keywords'     => 0,
        'image_license_url'    => '',
        'enable_pings'         => 1,
        'excluded_taxonomies'  => [],
        // Hardcoded values for simplicity
        'language'             => '', // Always auto-detect
        'window_hours'         => 48,
        'max_urls'             => 1000,
        'cache_ttl'            => 600,
        'respect_noindex'      => 1,
    ];

    private static $allowed_genres = [
        'PressRelease' => 'Press Release',
        'Satire'       => 'Satire',
        'Blog'         => 'Blog',
        'OpEd'         => 'Op-Ed',
        'Opinion'      => 'Opinion',
        'UserGenerated'=> 'User-Generated',
    ];

    /* ========= Bootstrap ========= */

    public static function init() {
        // Routing & request capture (rewrite or no rewrite)
        add_action( 'init',               [ __CLASS__, 'add_rewrite' ] );
        add_filter( 'query_vars',         [ __CLASS__, 'query_vars' ] );
        add_action( 'parse_request',      [ __CLASS__, 'maybe_catch_direct' ], 0 ); // earliest
        add_action( 'template_redirect',  [ __CLASS__, 'maybe_render' ], 0 );

        // Cache invalidation and pre-warming on content changes
        add_action( 'transition_post_status', [ __CLASS__, 'handle_post_status_change' ], 10, 3 );
        add_action( 'deleted_post',           [ __CLASS__, 'purge_cache' ] ); // Purge when post is deleted permanently
        add_action( 'trashed_post',           [ __CLASS__, 'purge_cache' ] ); // Purge when post is moved to trash

        // Admin
        add_action( 'admin_menu',  [ __CLASS__, 'admin_menu' ] );
        add_action( 'admin_init',  [ __CLASS__, 'register_settings' ] );
        add_filter( 'attachment_fields_to_edit', [ __CLASS__, 'add_image_license_field' ], 10, 2 );
        add_filter( 'attachment_fields_to_save', [ __CLASS__, 'save_image_license_field' ], 10, 2 );
        add_action( 'add_meta_boxes', [ __CLASS__, 'add_meta_box' ] );
        add_action( 'save_post',      [ __CLASS__, 'save_meta_box' ], 10, 2 );
        add_action( 'admin_post_ba_news_sitemap_action', [ __CLASS__, 'handle_admin_action' ] );

        // Cron
        add_filter( 'cron_schedules', [ __CLASS__, 'cron_schedules' ] );
        add_action( self::CRON_HOOK,  [ __CLASS__, 'cron_task' ] );
        add_action( self::PREWARM_HOOK, [ __CLASS__, 'prewarm_cache' ] );

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

        // Sanitize the default_genres array
        $clean['default_genres'] = [];
        if ( ! empty( $in['default_genres'] ) && is_array( $in['default_genres'] ) ) {
            foreach ( $in['default_genres'] as $genre ) {
                if ( isset( self::$allowed_genres[ $genre ] ) ) {
                    $clean['default_genres'][] = $genre;
                }
            }
        }

        $clean['disable_keywords'] = isset( $in['disable_keywords'] ) ? 1 : 0;

        $clean['image_license_url'] = isset( $in['image_license_url'] ) ? esc_url_raw( $in['image_license_url'] ) : '';

        $clean['enable_pings'] = isset( $in['enable_pings'] ) ? 1 : 0;

        // Sanitize the excluded_taxonomies array
        $clean['excluded_taxonomies'] = [];
        if ( ! empty( $in['excluded_taxonomies'] ) && is_array( $in['excluded_taxonomies'] ) ) {
            $supported_taxonomies = ['category', 'post_tag'];
            foreach ( $supported_taxonomies as $tax_slug ) {
                if ( ! empty( $in['excluded_taxonomies'][ $tax_slug ] ) && is_array( $in['excluded_taxonomies'][ $tax_slug ] ) ) {
                    $term_ids = array_map( 'intval', $in['excluded_taxonomies'][ $tax_slug ] );
                    $clean['excluded_taxonomies'][ $tax_slug ] = array_filter( $term_ids );
                }
            }
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

    public static function handle_post_status_change( $new_status, $old_status, $post ) {
        // First, always clear the cache on any status change.
        self::purge_cache();

        $opt = self::get_options();
        $sitemap_post_types = (array) ($opt['post_types'] ?? ['post']);

        // If a post is published and it's a type we include in the sitemap, schedule a pre-warm.
        if ( $new_status === 'publish' && in_array( $post->post_type, $sitemap_post_types, true ) ) {
            // Schedule a single event to run in 15 seconds.
            // This is non-blocking and will regenerate the sitemap in the background.
            wp_schedule_single_event( time() + 15, self::PREWARM_HOOK );
        }
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
        
        if ( ! is_string( $cached ) ) {
            delete_transient( self::TRANSIENT );
            $cached = self::empty_xml();
        }

        $meta = get_option( self::LASTBUILD_KEY, [] );
        $last_build_gmt = $meta['generated_at'] ?? null;

        if ( $last_build_gmt ) {
            $last_build_ts = strtotime( $last_build_gmt );
            $etag = md5( $cached );

            // Check headers and send 304 if cache is valid
            $if_modified_since = isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ? strtotime( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) : false;
            $if_none_match = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? trim( $_SERVER['HTTP_IF_NONE_MATCH'], '"' ) : false;

            if ( ( $if_none_match && $if_none_match === $etag ) || ( !$if_none_match && $if_modified_since && $if_modified_since >= $last_build_ts ) ) {
                status_header( 304 );
                exit;
            }

            // Send caching headers for a 200 response
            status_header( 200 );
            header( 'Content-Type: application/xml; charset=UTF-8' );
            header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $last_build_ts ) . ' GMT' );
            header( 'ETag: "' . $etag . '"' );
            header( 'Cache-Control: public, max-age=' . (int) $opt['cache_ttl'] );

        } else {
            // Fallback for the first build or if meta is missing
            status_header( 200 );
            nocache_headers();
            header( 'Content-Type: application/xml; charset=UTF-8' );
        }

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
        $active_tab = isset( $_GET['tab'] ) && in_array( $_GET['tab'], ['exclusions', 'tools'] ) ? $_GET['tab'] : 'general';
        ?>
        <style>
            .nav-tab-wrapper { margin-bottom: 20px; }
            .tab-content { display: none; }
            .tab-content.active { display: block; }
            .ba-status-box { margin: 2em 0; padding: 1em 1.5em; background: #fff; box-shadow: 0 1px 1px rgba(0,0,0,.04); border-left-width: 4px; border-left-style: solid; }
            .ba-status-box-active { border-left-color: #72aee6; }
            .ba-status-box-inactive { border-left-color: #d63638; }
            .ba-status-box h2 { margin-top: 0; }
            .ba-status-box ul { list-style: none; margin: 0; font-size: 14px; line-height: 1.8; }
            .ba-status-box strong { color: #2271b1; }
            .ba-troubleshooting-box { margin-top: 2em; border: 1px solid #c3c4c7; padding: 1em; background: #fff; }
        </style>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                var tabs = document.querySelectorAll('.nav-tab');
                var tabContents = document.querySelectorAll('.tab-content');

                tabs.forEach(function(tab) {
                    tab.addEventListener('click', function(e) {
                        e.preventDefault();

                        tabs.forEach(function(t) { t.classList.remove('nav-tab-active'); });
                        tabContents.forEach(function(c) { c.classList.remove('active'); });

                        tab.classList.add('nav-tab-active');
                        document.getElementById(tab.dataset.tab).classList.add('active');

                        // Update URL for bookmarking
                        var newUrl = new URL(window.location.href);
                        newUrl.searchParams.set('tab', tab.dataset.tab);
                        window.history.replaceState({path: newUrl.href}, '', newUrl.href);
                    });
                });
            });
        </script>

        <div class="wrap">
            <h1>BoardingArea News Sitemap</h1>
            <p style="font-size: 1.1em; color: #50575e;">This plugin automatically creates and updates a special sitemap to help Google find your latest articles faster.</p>

            <?php if ( isset($_GET['settings-updated']) ): ?>
                <div class="notice notice-success is-dismissible"><p><strong>Settings saved.</strong></p></div>
            <?php elseif ( isset($_GET['msg']) && $_GET['msg'] === 'rebuilt' ): ?>
                <div class="notice notice-success is-dismissible"><p><strong>Sitemap cache has been cleared and rebuilt.</strong></p></div>
            <?php elseif ( isset($_GET['msg']) && $_GET['msg'] === 'pinged' ): ?>
                 <div class="notice notice-success is-dismissible"><p><strong>Ping requests have been sent to Google and Bing.</strong></p></div>
            <?php endif; ?>

            <div class="ba-status-box <?php echo (int)$opt['enabled'] === 1 ? 'ba-status-box-active' : 'ba-status-box-inactive'; ?>">
                <h2>Sitemap Status</h2>
                <?php if ( (int) $opt['enabled'] === 1 ): ?>
                    <p style="font-size: 16px; margin: 1em 0;">Your news sitemap is <strong>Active</strong> and running smoothly.</p>
                    <ul>
                        <?php
                        $article_count = $meta['count'] ?? 0;
                        $last_updated_text = 'Never';
                        if ( ! empty( $meta['generated_at'] ) ) {
                            $last_build_time = strtotime( $meta['generated_at'] );
                            if ( $last_build_time ) {
                                $last_updated_text = sprintf( '%s ago', human_time_diff( $last_build_time, current_time( 'timestamp', true ) ) );
                            }
                        }
                        ?>
                        <li><strong>Contents:</strong> Currently includes <strong><?php echo esc_html( $article_count ); ?></strong> recent articles</li>
                        <li><strong>Last Updated:</strong> <?php echo esc_html( $last_updated_text ); ?></li>
                        <li><strong>News Sitemap Link:</strong> <a href="<?php echo esc_url($news_sitemap_url); ?>" target="_blank" rel="noopener"><?php echo esc_html($news_sitemap_url); ?></a></li>
                    </ul>
                <?php else: ?>
                    <p style="font-size: 16px; margin: 1em 0;">Your news sitemap is <strong>Inactive</strong>.</p>
                     <ul>
                        <li><strong>News Sitemap Link:</strong> <code><?php echo esc_html($news_sitemap_url); ?></code> (disabled)</li>
                    </ul>
                <?php endif; ?>
            </div>

            <h2 class="nav-tab-wrapper">
                <a href="?page=ba-news-sitemap&tab=general" class="nav-tab <?php echo $active_tab == 'general' ? 'nav-tab-active' : ''; ?>" data-tab="general-settings">General Settings</a>
                <a href="?page=ba-news-sitemap&tab=exclusions" class="nav-tab <?php echo $active_tab == 'exclusions' ? 'nav-tab-active' : ''; ?>" data-tab="exclusion-rules">Exclusion Rules</a>
                <a href="?page=ba-news-sitemap&tab=advanced" class="nav-tab <?php echo $active_tab == 'advanced' ? 'nav-tab-active' : ''; ?>" data-tab="advanced-settings">Advanced Settings</a>
            </h2>

            <form method="post" action="options.php">
                <?php settings_fields( 'ba_news_sitemap' ); ?>

                <div id="general-settings" class="tab-content <?php echo $active_tab == 'general' ? 'active' : ''; ?>">
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
                            <th scope="row"><label for="ba_news_sitemap_publication_name">Publication Name</label></th>
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
                                    unset($post_types['attachment']);
                                    $saved_post_types = (array) $opt['post_types'];
                                    foreach ( $post_types as $slug => $pt ) {
                                        echo '<label style="display: block; margin-bottom: 5px;"><input type="checkbox" name="' . esc_attr(self::OPT_KEY) . '[post_types][]" value="' . esc_attr($slug) . '" ' . checked( in_array( $slug, $saved_post_types, true ), true, false ) . '> ' . esc_html( $pt->label ) . '</label>';
                                    }
                                    ?>
                                </fieldset>
                                <p class="description">Select which types of content to include. It's usually best to only include "Posts".</p>
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">Default News Genres</th>
                            <td>
                                <fieldset>
                                    <legend class="screen-reader-text"><span>Default News Genres</span></legend>
                                    <?php
                                    $saved_genres = (array) ($opt['default_genres'] ?? []);
                                    foreach ( self::$allowed_genres as $token => $label ) {
                                        echo '<label style="display: block; margin-bottom: 5px;"><input type="checkbox" name="' . esc_attr(self::OPT_KEY) . '[default_genres][]" value="' . esc_attr($token) . '" ' . checked( in_array( $token, $saved_genres, true ), true, false ) . '> ' . esc_html( $label ) . '</label>';
                                    }
                                    ?>
                                </fieldset>
                                <p class="description">Select default genres for your articles. You can override this per-post.</p>
                            </td>
                        </tr>
                    </table>
                </div>

                <div id="advanced-settings" class="tab-content <?php echo $active_tab == 'advanced' ? 'active' : ''; ?>">
                     <table class="form-table" role="presentation">
                        <tr valign="top">
                            <th scope="row">Keywords</th>
                            <td>
                                <label for="ba_news_sitemap_disable_keywords">
                                    <input type="checkbox" id="ba_news_sitemap_disable_keywords" name="<?php echo esc_attr(self::OPT_KEY); ?>[disable_keywords]" value="1" <?php checked( 1, (int) ($opt['disable_keywords'] ?? 0) ); ?>>
                                    Disable News Keywords (Recommended)
                                </label>
                                <p class="description">Don't output the <code>&lt;news:keywords&gt;</code> tag. Google ignores this tag.</p>
                            </td>
                        </tr>
                         <tr valign="top">
                            <th scope="row">Pinging</th>
                            <td>
                                <label for="ba_news_sitemap_enable_pings">
                                    <input type="checkbox" id="ba_news_sitemap_enable_pings" name="<?php echo esc_attr(self::OPT_KEY); ?>[enable_pings]" value="1" <?php checked( 1, (int) ($opt['enable_pings'] ?? 1) ); ?>>
                                    Ping Search Engines
                                </label>
                                <p class="description">Automatically ping Google and Bing on update. Pings are throttled to once every 5 minutes.</p>
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row"><label for="ba_news_sitemap_image_license_url">Default Image License URL</label></th>
                            <td>
                                <input type="url" id="ba_news_sitemap_image_license_url" class="regular-text" name="<?php echo esc_attr(self::OPT_KEY); ?>[image_license_url]" value="<?php echo esc_attr( $opt['image_license_url'] ); ?>" placeholder="https://example.com/image-licenses/">
                                <p class="description">Optional. URL to a page describing licenses for your images. Can help get a "Licensable" badge in Google Images.</p>
                            </td>
                        </tr>
                    </table>
                </div>

                <div id="exclusion-rules" class="tab-content <?php echo $active_tab == 'exclusions' ? 'active' : ''; ?>">
                    <p>Exclude posts from the sitemap if they have any of the selected taxonomy terms.</p>
                    <table class="form-table" role="presentation">
                        <tr valign="top">
                            <th scope="row">Exclude by Taxonomy</th>
                            <td>
                                <strong>Categories</strong>
                                <div style="max-height: 200px; overflow-y: auto; border: 1px solid #ccc; padding: 10px; margin-top: 5px; margin-bottom: 1em;">
                                    <?php
                                    $categories = get_terms(['taxonomy' => 'category', 'hide_empty' => false]);
                                    $excluded_cats = $opt['excluded_taxonomies']['category'] ?? [];
                                    if ( ! empty( $categories ) && ! is_wp_error( $categories ) ) {
                                        foreach ($categories as $term) {
                                            echo '<label style="display: block; margin-bottom: 5px;"><input type="checkbox" name="' . esc_attr(self::OPT_KEY) . '[excluded_taxonomies][category][]" value="' . esc_attr($term->term_id) . '" ' . checked( in_array( $term->term_id, $excluded_cats ), true, false ) . '> ' . esc_html( $term->name ) . '</label>';
                                        }
                                    } else { echo 'No categories found.'; }
                                    ?>
                                </div>

                                <strong>Tags</strong>
                                <div style="max-height: 200px; overflow-y: auto; border: 1px solid #ccc; padding: 10px; margin-top: 5px;">
                                    <?php
                                    $tags = get_terms(['taxonomy' => 'post_tag', 'hide_empty' => false]);
                                    $excluded_tags = $opt['excluded_taxonomies']['post_tag'] ?? [];
                                    if ( ! empty( $tags ) && ! is_wp_error( $tags ) ) {
                                        foreach ($tags as $term) {
                                            echo '<label style="display: block; margin-bottom: 5px;"><input type="checkbox" name="' . esc_attr(self::OPT_KEY) . '[excluded_taxonomies][post_tag][]" value="' . esc_attr($term->term_id) . '" ' . checked( in_array( $term->term_id, $excluded_tags ), true, false ) . '> ' . esc_html( $term->name ) . '</label>';
                                        }
                                    } else { echo 'No tags found.'; }
                                    ?>
                                </div>
                            </td>
                        </tr>
                    </table>
                </div>

                <?php submit_button(); ?>
            </form>

            <details class="ba-troubleshooting-box">
                <summary style="font-size: 1.1em; font-weight: 600; cursor: pointer;">Troubleshooting &amp; Tools</summary>
                <div style="margin-top: 1em;">
                    <h4>Manual Actions</h4>
                    <p>Use these buttons for immediate actions, like refreshing the sitemap or pinging search engines.</p>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display: inline-block; margin-right: 1em;">
                        <?php wp_nonce_field( 'ba_news_sitemap_action' ); ?>
                        <input type="hidden" name="action" value="ba_news_sitemap_action">
                        <input type="hidden" name="op" value="rebuild">
                        <?php submit_button( 'Force Refresh Now', 'secondary', 'submit', false ); ?>
                    </form>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display: inline-block;">
                        <?php wp_nonce_field( 'ba_news_sitemap_action' ); ?>
                        <input type="hidden" name="action" value="ba_news_sitemap_action">
                        <input type="hidden" name="op" value="ping">
                        <?php submit_button( 'Manually Ping Google', 'secondary', 'submit', false ); ?>
                    </form>

                    <h4 style="margin-top: 2em;">System Status</h4>
                    <p style="font-size: 12px; color: #50575e;">
                        <?php
                        $status_items = [];

                        // Cron Status
                        if ( defined('DISABLE_WP_CRON') && DISABLE_WP_CRON ) {
                            $status_items[] = '<strong>WP-Cron:</strong> <span style="color:#d63638;">Disabled</span>';
                        } else {
                            $next_run = wp_next_scheduled( self::CRON_HOOK );
                            if ( $next_run ) {
                                $status_items[] = '<strong>Next Rebuild:</strong> ' . esc_html( sprintf( '%s from now', human_time_diff( $next_run, current_time( 'timestamp', true ) ) ) );
                            } else {
                                $status_items[] = '<strong>Next Rebuild:</strong> Not scheduled';
                            }
                        }

                        // Last Build Status
                        if ( ! empty( $meta['count'] ) ) {
                             $status_items[] = '<strong>Last Build:</strong> ' . esc_html( sprintf( '%d URLs in %dms', $meta['count'], $meta['took_ms'] ?? 0 ) );
                        }

                        // Last Ping Status
                        $last_ping = get_option('ba_news_sitemap_lastping', []);
                        if ( ! empty( $last_ping['pinged_at'] ) ) {
                            $last_ping_time = strtotime( $last_ping['pinged_at'] );
                            $ping_details = [];
                            if (isset($last_ping['results']['google'])) $ping_details[] = 'Google: ' . esc_html($last_ping['results']['google']);
                            if (isset($last_ping['results']['bing'])) $ping_details[] = 'Bing: ' . esc_html($last_ping['results']['bing']);

                            $status_items[] = '<strong>Last Ping:</strong> ' . esc_html( sprintf( '%s ago', human_time_diff( $last_ping_time, current_time( 'timestamp', true ) ) ) ) . ' (' . implode(', ', $ping_details) . ')';
                        }

                        echo implode( ' <span style="color: #ddd;">|</span> ', $status_items );
                        ?>
                    </p>
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
        $msg = 'done';
        switch ( $op ) {
            case 'rebuild':
                self::prewarm_cache();
                $msg = 'rebuilt';
                break;
            case 'ping':
                self::do_pings(true);
                $msg = 'pinged';
                break;
        }
        wp_safe_redirect( add_query_arg( [ 'page' => 'ba-news-sitemap', 'msg' => $msg ], admin_url( 'options-general.php' ) ) );
        exit;
    }

    public static function add_image_license_field( $form_fields, $post ) {
        $license_url = get_post_meta( $post->ID, '_image_license_url', true );
        $form_fields['image_license_url'] = [
            'label' => 'Image License URL',
            'input' => 'text',
            'value' => $license_url,
            'helps' => 'Enter a URL to a page describing the license for this specific image.',
        ];
        return $form_fields;
    }

    public static function save_image_license_field( $post, $attachment ) {
        if ( isset( $attachment['image_license_url'] ) ) {
            $url = esc_url_raw( $attachment['image_license_url'] );
            update_post_meta( $post['ID'], '_image_license_url', $url );
        }
        return $post;
    }

    /* ========= Post Meta Box ========= */

    public static function add_meta_box() {
        $opt = self::get_options();
        $post_types = (array) ($opt['post_types'] ?? ['post']);
        foreach ( $post_types as $post_type ) {
            add_meta_box(
                'ba_news_sitemap_meta',
                'Google News Sitemap',
                [ __CLASS__, 'render_meta_box' ],
                $post_type,
                'side',
                'default'
            );
        }
    }

    public static function render_meta_box( $post ) {
        wp_nonce_field( 'ba_news_sitemap_meta_save', 'ba_news_sitemap_meta_nonce' );

        $saved_genres = get_post_meta( $post->ID, '_news_genres', true );
        if ( ! is_array( $saved_genres ) ) $saved_genres = [];

        ?>
        <p><strong>News Genres</strong></p>
        <p>Override the site's default genres for this post. If none are selected, the default will be used.</p>
        <fieldset>
            <legend class="screen-reader-text">News Genres</legend>
            <?php
            foreach ( self::$allowed_genres as $token => $label ) {
                ?>
                <label for="genre_meta_<?php echo esc_attr($token); ?>" style="display: block; margin-bottom: 5px;">
                    <input type="checkbox" id="genre_meta_<?php echo esc_attr($token); ?>" name="_news_genres[]" value="<?php echo esc_attr($token); ?>" <?php checked( in_array( $token, $saved_genres, true ) ); ?>>
                    <?php echo esc_html( $label ); ?>
                </label>
                <?php
            }
            ?>
        </fieldset>
        <hr style="margin: 1em 0;">
        <p><strong>Stock Tickers</strong></p>
        <?php $stock_tickers = get_post_meta( $post->ID, '_news_stock_tickers', true ); ?>
        <label for="ba_news_sitemap_stock_tickers" class="screen-reader-text">Stock Tickers</label>
        <input type="text" id="ba_news_sitemap_stock_tickers" name="_news_stock_tickers" value="<?php echo esc_attr( $stock_tickers ); ?>" class="widefat" placeholder="e.g. NASDAQ:AAL, NYSE:DAL">
        <p class="description">Optional. Add comma-separated stock tickers relevant to this article.</p>
        <hr style="margin: 1em 0;">
        <?php
        $is_excluded = get_post_meta( $post->ID, '_exclude_from_news_sitemap', true );
        ?>
        <p><strong>Exclusion</strong></p>
        <label for="exclude_from_news_sitemap_meta">
            <input type="checkbox" id="exclude_from_news_sitemap_meta" name="_exclude_from_news_sitemap" value="1" <?php checked( $is_excluded ); ?>>
            Exclude this post from the news sitemap.
        </label>
        <?php
    }

    public static function save_meta_box( $post_id, $post ) {
        if ( ! isset( $_POST['ba_news_sitemap_meta_nonce'] ) || ! wp_verify_nonce( $_POST['ba_news_sitemap_meta_nonce'], 'ba_news_sitemap_meta_save' ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }
        if ( wp_is_post_revision( $post_id ) ) {
            return;
        }

        // Save Genres
        $genres = [];
        if ( ! empty( $_POST['_news_genres'] ) && is_array( $_POST['_news_genres'] ) ) {
            foreach ( $_POST['_news_genres'] as $genre ) {
                if ( isset( self::$allowed_genres[ $genre ] ) ) {
                    $genres[] = $genre;
                }
            }
        }
        if ( ! empty( $genres ) ) {
            update_post_meta( $post_id, '_news_genres', $genres );
        } else {
            delete_post_meta( $post_id, '_news_genres' );
        }

        // Save Stock Tickers
        if ( isset( $_POST['_news_stock_tickers'] ) ) {
            $tickers = sanitize_text_field( $_POST['_news_stock_tickers'] );
            if ( ! empty( $tickers ) ) {
                update_post_meta( $post_id, '_news_stock_tickers', $tickers );
            } else {
                delete_post_meta( $post_id, '_news_stock_tickers' );
            }
        }

        // Save Exclude from News setting
        if ( isset( $_POST['_exclude_from_news_sitemap'] ) ) {
            update_post_meta( $post_id, '_exclude_from_news_sitemap', '1' );
        } else {
            delete_post_meta( $post_id, '_exclude_from_news_sitemap' );
        }
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
        if ( get_transient( self::BUILD_LOCK_KEY ) ) {
            return;
        }

        $opt = self::get_options();
        if ( (int) $opt['enabled'] !== 1 ) return;

        set_transient( self::BUILD_LOCK_KEY, true, 2 * MINUTE_IN_SECONDS );

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
            self::do_pings();
        } catch ( \Throwable $e ) {
            // In case of failure, don't leave a stale cache.
            set_transient( self::TRANSIENT, self::empty_xml(), (int) $opt['cache_ttl'] );
        } finally {
            delete_transient( self::BUILD_LOCK_KEY );
        }
    }

    protected static function do_pings( $force = false ) {
        $opt = self::get_options();
        if ( ! $force && empty( $opt['enable_pings'] ) ) {
            return;
        }

        if ( ! $force && get_transient( self::PING_THROTTLE_KEY ) ) {
            return;
        }

        set_transient( self::PING_THROTTLE_KEY, time(), 5 * MINUTE_IN_SECONDS );

        $sitemap_url = home_url( '/news-sitemap.xml' );
        $ping_urls = [
            'google' => 'https://www.google.com/ping?sitemap=' . urlencode( $sitemap_url ),
            'bing'   => 'https://www.bing.com/ping?sitemap=' . urlencode( $sitemap_url ),
        ];

        $results = [];
        foreach ( $ping_urls as $engine => $url ) {
            $response = wp_remote_get( $url, [ 'timeout' => 10 ] );
            $results[ $engine ] = is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_response_code( $response );
        }

        update_option( 'ba_news_sitemap_lastping', [
            'pinged_at' => gmdate( 'c' ),
            'results'   => $results,
        ], false );
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
        $urlset->setAttribute( 'xmlns:xhtml', 'http://www.w3.org/1999/xhtml' );

        $site_host = wp_parse_url( home_url(), PHP_URL_HOST );

        foreach ( $post_ids as $post_id ) {
            $loc     = get_permalink( $post_id );
            if ( ! $loc ) continue;

            // Single-Host Guard
            if ( wp_parse_url( $loc, PHP_URL_HOST ) !== $site_host ) {
                continue;
            }
            
            if ( (int) $opt['respect_noindex'] === 1 && self::is_noindex( $post_id ) ) continue;

            $external_canonical = self::get_external_canonical_url( $post_id );
            if ( $external_canonical && $external_canonical !== $loc ) {
                continue;
            }

            if ( apply_filters( 'ba_news_sitemap_exclude_post', false, $post_id ) ) {
                continue;
            }

            $lastmod = get_post_modified_time( 'c', true, $post_id );
            $pubdate = get_post_time( 'c', true, $post_id );
            $title   = wp_strip_all_tags( get_the_title( $post_id ), true );

            $url = $dom->createElement( 'url' );
            $url->appendChild( $dom->createElement( 'loc', $loc ) );
            $url->appendChild( $dom->createElement( 'lastmod', $lastmod ) );
            
            // Add image data
            $images = apply_filters( 'ba_news_sitemap_images', self::get_post_images_data( $post_id ), $post_id );
            if ( ! empty( $images ) ) {
                foreach ( $images as $img ) {
                    $image_node = $dom->createElement('image:image');
                    $image_node->appendChild( $dom->createElement('image:loc', $img['loc']) );

                    if ( ! empty( $img['title'] ) ) {
                        $title_node = $dom->createElement('image:title');
                        $title_node->appendChild( $dom->createCDATASection( $img['title'] ) );
                        $image_node->appendChild( $title_node );
                    }
                    if ( ! empty( $img['caption'] ) ) {
                        $caption_node = $dom->createElement('image:caption');
                        $caption_node->appendChild( $dom->createCDATASection( $img['caption'] ) );
                        $image_node->appendChild( $caption_node );
                    }
                    $license_to_use = ! empty( $img['license_url'] ) ? $img['license_url'] : ( $opt['image_license_url'] ?? '' );
                    if ( ! empty( $license_to_use ) ) {
                        $image_node->appendChild( $dom->createElement('image:license', $license_to_use) );
                    }

                    $url->appendChild( $image_node );
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

            $genres = apply_filters( 'ba_news_sitemap_genres', self::get_post_genres( $post_id, $opt ), $post_id );
            if ( ! empty( $genres ) ) {
                $genresNode = $dom->createElement( 'news:genres' );
                $genresNode->appendChild( $dom->createCDATASection( implode( ', ', $genres ) ) );
                $news->appendChild( $genresNode );
            }
            
            if ( empty( $opt['disable_keywords'] ) ) {
                $keywords = apply_filters( 'ba_news_sitemap_keywords', self::post_keywords( $post_id ), $post_id );
                if ( ! empty( $keywords ) ) {
                    $kwNode = $dom->createElement( 'news:keywords' );
                    $kwNode->appendChild( $dom->createCDATASection( implode( ', ', array_slice( $keywords, 0, 10 ) ) ) );
                    $news->appendChild( $kwNode );
                }
            }

            $stock_tickers = self::get_post_stock_tickers( $post_id );
            if ( ! empty( $stock_tickers ) ) {
                $tickersNode = $dom->createElement( 'news:stock_tickers', implode( ',', $stock_tickers ) );
                $news->appendChild( $tickersNode );
            }

            $url->appendChild( $news );

            $xhtml_links = self::get_xhtml_links_data( $post_id );
            if ( ! empty( $xhtml_links ) ) {
                foreach ( $xhtml_links as $link_data ) {
                    $link_node = $dom->createElement('xhtml:link');
                    $link_node->setAttribute('rel', $link_data['rel']);
                    if ( ! empty( $link_data['hreflang'] ) ) {
                        $link_node->setAttribute('hreflang', $link_data['hreflang']);
                    }
                    $link_node->setAttribute('href', $link_data['href']);
                    $url->appendChild( $link_node );
                }
            }

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
        
        $out .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:news="http://www.google.com/schemas/sitemap-news/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1" xmlns:xhtml="http://www.w3.org/1999/xhtml">';

        $site_host = wp_parse_url( home_url(), PHP_URL_HOST );

        foreach ( $post_ids as $post_id ) {
            $loc     = get_permalink( $post_id );
            if ( ! $loc ) continue;

            // Single-Host Guard
            if ( wp_parse_url( $loc, PHP_URL_HOST ) !== $site_host ) {
                continue;
            }

            if ( (int) $opt['respect_noindex'] === 1 && self::is_noindex( $post_id ) ) continue;

            $external_canonical = self::get_external_canonical_url( $post_id );
            if ( $external_canonical && $external_canonical !== $loc ) {
                continue;
            }

            $lastmod = get_post_modified_time( 'c', true, $post_id );
            $pubdate = get_post_time( 'c', true, $post_id );
            $title   = self::cdata( wp_strip_all_tags( get_the_title( $post_id ), true ) );

            $out .= '<url>';
            $out .= '<loc>' . esc_url( $loc ) . '</loc>';
            $out .= '<lastmod>' . esc_html( $lastmod ) . '</lastmod>';

            // Add image data
            $images = apply_filters( 'ba_news_sitemap_images', self::get_post_images_data( $post_id ), $post_id );
            if ( ! empty( $images ) ) {
                foreach ( $images as $img ) {
                    $out .= '<image:image>';
                    $out .= '<image:loc>' . esc_url( $img['loc'] ) . '</image:loc>';
                    if ( ! empty( $img['title'] ) ) {
                        $out .= '<image:title>' . self::cdata( $img['title'] ) . '</image:title>';
                    }
                    if ( ! empty( $img['caption'] ) ) {
                        $out .= '<image:caption>' . self::cdata( $img['caption'] ) . '</image:caption>';
                    }
                    $license_to_use = ! empty( $img['license_url'] ) ? $img['license_url'] : ( $opt['image_license_url'] ?? '' );
                    if ( ! empty( $license_to_use ) ) {
                        $out .= '<image:license>' . esc_url( $license_to_use ) . '</image:license>';
                    }
                    $out .= '</image:image>';
                }
            }

            $out .= '<news:news>';
            $out .= '<news:publication><news:name>' . $pubname . '</news:name><news:language>' . esc_html( $lang ) . '</news:language></news:publication>';
            $out .= '<news:publication_date>' . esc_html( $pubdate ) . '</news:publication_date>';
            $out .= '<news:title>' . $title . '</news:title>';

            $genres = apply_filters( 'ba_news_sitemap_genres', self::get_post_genres( $post_id, $opt ), $post_id );
            if ( ! empty( $genres ) ) {
                $out .= '<news:genres>' . self::cdata( implode( ', ', $genres ) ) . '</news:genres>';
            }

            if ( empty( $opt['disable_keywords'] ) ) {
                $keywords = apply_filters( 'ba_news_sitemap_keywords', self::post_keywords( $post_id ), $post_id );
                if ( ! empty( $keywords ) ) {
                    $out .= '<news:keywords>' . self::cdata( implode( ', ', array_slice( $keywords, 0, 10 ) ) ) . '</news:keywords>';
                }
            }

            $stock_tickers = self::get_post_stock_tickers( $post_id );
            if ( ! empty( $stock_tickers ) ) {
                $out .= '<news:stock_tickers>' . esc_html( implode( ',', $stock_tickers ) ) . '</news:stock_tickers>';
            }

            $out .= '</news:news>';

            $xhtml_links = self::get_xhtml_links_data( $post_id );
            if ( ! empty( $xhtml_links ) ) {
                foreach ( $xhtml_links as $link_data ) {
                    $out .= '<xhtml:link rel="' . esc_attr($link_data['rel']) . '"';
                    if ( ! empty( $link_data['hreflang'] ) ) {
                        $out .= ' hreflang="' . esc_attr($link_data['hreflang']) . '"';
                    }
                    $out .= ' href="' . esc_url($link_data['href']) . '" />';
                }
            }

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

    protected static function get_post_images_data( $post_id ) {
        $images = [];
        $image_ids = [];

        // 1. Get featured image
        if ( has_post_thumbnail( $post_id ) ) {
            $thumb_id = get_post_thumbnail_id( $post_id );
            if ( $thumb_id ) {
                $image_ids[ $thumb_id ] = true; // Use keys to prevent duplicates
            }
        }

        // 2. Get first in-content image
        $post_content = get_post_field( 'post_content', $post_id );
        if ( preg_match( '/<img[^>]+src=[\'"]([^\'"]+)[\'"][^>]*>/', $post_content, $matches ) ) {
            $image_url = $matches[1];
            $image_id = attachment_url_to_postid( $image_url );
            if ( $image_id ) {
                $image_ids[ $image_id ] = true;
            }
        }

        // 3. Prepare data for each unique image ID
        foreach ( array_keys( $image_ids ) as $id ) {
            $img_src = wp_get_attachment_image_src( $id, 'full' );
            if ( ! $img_src ) continue;

            $caption = wp_get_attachment_caption( $id );

            $images[] = [
                'loc'         => $img_src[0],
                'width'       => $img_src[1],
                'title'       => get_the_title( $id ),
                'caption'     => $caption ? $caption : get_post_meta( $id, '_wp_attachment_image_alt', true ),
                'license_url' => get_post_meta( $id, '_image_license_url', true ),
            ];
        }

        // 4. Sort images to prefer larger ones
        usort($images, function( $a, $b ) {
            $a_large = $a['width'] >= 1200;
            $b_large = $b['width'] >= 1200;
            if ( $a_large === $b_large ) {
                return 0;
            }
            return $a_large ? -1 : 1;
        });

        return $images;
    }

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

        if ( ! empty( $opt['excluded_taxonomies'] ) ) {
            $tax_query = ['relation' => 'AND'];
            foreach( $opt['excluded_taxonomies'] as $tax => $terms ) {
                if ( ! empty( $terms ) ) {
                    $tax_query[] = [
                        'taxonomy' => $tax,
                        'field'    => 'term_id',
                        'terms'    => $terms,
                        'operator' => 'NOT IN',
                    ];
                }
            }
            if ( count( $tax_query ) > 1 ) {
                $args['tax_query'] = $tax_query;
            }
        }

        $q = new \WP_Query( $args );
        return apply_filters( 'ba_news_sitemap_post_ids', $q->posts, $args );
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
        $lang = str_replace( '_', '-', get_locale() );
        return apply_filters( 'ba_news_sitemap_language', $lang );
    }

    protected static function post_keywords( $post_id ) {
        $terms = wp_get_post_terms( $post_id, [ 'category', 'post_tag' ], [ 'fields' => 'names' ] );
        if ( is_wp_error( $terms ) || empty( $terms ) ) return [];

        $keywords = array_values( array_unique( array_filter( array_map( 'trim', $terms ) ) ) );

        // Suppress if the only keyword is "Uncategorized"
        if ( count( $keywords ) === 1 && $keywords[0] === 'Uncategorized' ) {
            return [];
        }

        return $keywords;
    }

    protected static function get_post_genres( $post_id, $opt ) {
        $genres = get_post_meta( $post_id, '_news_genres', true );
        if ( ! is_array( $genres ) || empty( $genres ) ) {
            $genres = (array) ($opt['default_genres'] ?? []);
        }
        $validated = [];
        foreach( $genres as $genre ) {
            if ( isset( self::$allowed_genres[ $genre ] ) ) {
                $validated[] = $genre;
            }
        }
        return $validated;
    }

    protected static function get_post_stock_tickers( $post_id ) {
        $tickers_str = get_post_meta( $post_id, '_news_stock_tickers', true );
        if ( empty( $tickers_str ) ) {
            return [];
        }
        $tickers = explode( ',', $tickers_str );
        $tickers = array_map( 'trim', $tickers );
        $tickers = array_filter( $tickers ); // remove empty elements
        $tickers = array_slice( $tickers, 0, 5 ); // Google News allows up to 5 tickers
        return apply_filters( 'ba_news_sitemap_stock_tickers', $tickers, $post_id );
    }

    protected static function get_xhtml_links_data( $post_id ) {
        $links = [];

        // AMP integration
        if ( function_exists( 'amp_get_permalink' ) ) {
            $amp_url = amp_get_permalink( $post_id );
            if ( $amp_url ) {
                $links[] = [
                    'rel' => 'amphtml',
                    'href' => $amp_url,
                ];
            }
        }

        // Hreflang integration via filter
        $hreflang_links = apply_filters( 'ba_news_sitemap_alternates', [], $post_id );
        if ( ! empty( $hreflang_links ) && is_array( $hreflang_links ) ) {
            foreach ( $hreflang_links as $lang => $url ) {
                if ( is_string( $lang ) && is_string( $url ) && strlen( $lang ) > 0 ) {
                     $links[] = [
                        'rel' => 'alternate',
                        'hreflang' => $lang,
                        'href' => $url,
                    ];
                }
            }
        }

        return $links;
    }

    protected static function get_external_canonical_url( $post_id ) {
        $canonical_url = null;

        // Yoast SEO
        if ( defined('WPSEO_VERSION') ) {
            $canonical_url = get_post_meta( $post_id, '_yoast_wpseo_canonical', true );
        }

        // Rank Math (overrides Yoast if both are somehow active)
        if ( defined( 'RANK_MATH_VERSION' ) ) {
            $rank_math_canonical = get_post_meta( $post_id, 'rank_math_canonical_url', true );
            if ( ! empty($rank_math_canonical) ) {
                $canonical_url = $rank_math_canonical;
            }
        }

        return empty($canonical_url) ? null : $canonical_url;
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

            case 'ping':
                self::do_pings( true );
                \WP_CLI::success( 'Ping requests sent to Google and Bing.' );
                break;

            case 'status':
                $last_build = get_option(self::LASTBUILD_KEY, []);
                $last_ping = get_option('ba_news_sitemap_lastping', []);
                if ( empty( $last_build ) ) {
                    \WP_CLI::line( "Sitemap has not been built yet." );
                } else {
                    \WP_CLI::line( "== Sitemap Status ==" );
                    \WP_CLI::line( "Last built: " . ( $last_build['generated_at'] ?? 'Unknown' ) . " UTC" );
                    \WP_CLI::line( "Took: " . ( $last_build['took_ms'] ?? 'N/A' ) . " ms" );
                    \WP_CLI::line( "URL Count: " . ( $last_build['count'] ?? 'N/A' ) );
                }
                if ( ! empty( $last_ping ) ) {
                     \WP_CLI::line( "\n== Last Ping Status ==" );
                     \WP_CLI::line( "Pinged at: " . ( $last_ping['pinged_at'] ?? 'Unknown' ) . " UTC" );
                     foreach ( (array) ($last_ping['results'] ?? []) as $engine => $result ) {
                         \WP_CLI::line( "- $engine: " . $result );
                     }
                }
                break;

            case 'validate':
                \WP_CLI::line( "Validating sitemap XML..." );
                $xml = self::safe_build_xml();
                $dom = new \DOMDocument();
                libxml_use_internal_errors(true);
                if ( $dom->loadXML( $xml ) ) {
                    \WP_CLI::success( "Sitemap XML is well-formed." );
                } else {
                    \WP_CLI::error( "Sitemap XML is NOT well-formed." );
                    foreach (libxml_get_errors() as $error) {
                        \WP_CLI::line( "  - " . $error->message );
                    }
                    libxml_clear_errors();
                }
                break;

            default:
                $help = <<<EOT
Usage: wp ba-news-sitemap <command>

Commands:
  rebuild   Purge cache and rebuild sitemap.
  print     Print sitemap XML to stdout.
  ping      Force pings to search engines.
  status    Show last build time, URL count, and last ping status.
  validate  Check if the generated sitemap XML is well-formed.
EOT;
                \WP_CLI::line( $help );
        }
    }
}

/* ========= Lifecycle ========= */
add_action( 'plugins_loaded', [ 'BA_News_Sitemap', 'init' ] );
register_activation_hook( __FILE__, [ 'BA_News_Sitemap', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'BA_News_Sitemap', 'deactivate' ] );
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), [ 'BA_News_Sitemap', 'add_settings_link' ] );
