<?php
/**
 * Public GitHub Releases update discovery for OPC Live Visitor Counter.
 * Releases must carry an asset named opc-live-visitor-counter.zip, containing
 * one top-level directory: opc-live-visitor-counter/.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class OPCVC_GitHub_Updates {
    const REPOSITORY = 'opcgroup/opc-live-visitor-counter';
    const RELEASE_API = 'https://api.github.com/repos/opcgroup/opc-live-visitor-counter/releases/latest';
    const ASSET_NAME = 'opc-live-visitor-counter.zip';
    const UPDATE_URI = 'https://github.com/opcgroup/opc-live-visitor-counter';
    const CACHE_KEY = 'opcvc_github_release_v1';

    private static $plugin_file;

    public static function init( $main_file ) {
        self::$plugin_file = plugin_basename( $main_file );
        add_filter( 'update_plugins_github.com', array( __CLASS__, 'check_update' ), 10, 4 );
    }

    private static function release() {
        $cached = get_transient( self::CACHE_KEY );
        if ( false !== $cached ) {
            return is_array( $cached ) ? $cached : false;
        }

        $response = wp_remote_get(
            self::RELEASE_API,
            array(
                'timeout' => 10,
                'redirection' => 3,
                'headers' => array(
                    'Accept' => 'application/vnd.github+json',
                    'User-Agent' => 'OPC-Live-Visitor-Counter-WordPress-Updater',
                    'X-GitHub-Api-Version' => '2022-11-28',
                ),
            )
        );

        if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
            set_transient( self::CACHE_KEY, 'unavailable', 30 * MINUTE_IN_SECONDS );
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) || ! empty( $body['draft'] ) || ! empty( $body['prerelease'] ) || empty( $body['tag_name'] ) || empty( $body['assets'] ) ) {
            set_transient( self::CACHE_KEY, 'unavailable', 30 * MINUTE_IN_SECONDS );
            return false;
        }

        $version = ltrim( sanitize_text_field( $body['tag_name'] ), 'vV' );
        if ( ! preg_match( '/^\d+\.\d+\.\d+(?:[-.][a-zA-Z0-9.-]+)?$/', $version ) ) {
            set_transient( self::CACHE_KEY, 'unavailable', 30 * MINUTE_IN_SECONDS );
            return false;
        }

        $package = '';
        foreach ( $body['assets'] as $asset ) {
            if ( ! is_array( $asset ) || empty( $asset['name'] ) || self::ASSET_NAME !== $asset['name'] ) {
                continue;
            }
            $possible_url = isset( $asset['browser_download_url'] ) ? $asset['browser_download_url'] : '';
            $parts = wp_parse_url( $possible_url );
            if ( is_array( $parts ) && isset( $parts['scheme'], $parts['host'] ) && 'https' === $parts['scheme'] && 'github.com' === strtolower( $parts['host'] ) ) {
                $package = esc_url_raw( $possible_url );
            }
            break;
        }

        // No installable release: don't show an Update Now link that cannot work.
        if ( ! $package ) {
            set_transient( self::CACHE_KEY, 'unavailable', 30 * MINUTE_IN_SECONDS );
            return false;
        }

        $release = array(
            'version' => $version,
            'package' => $package,
            'url' => esc_url_raw( 'https://github.com/' . self::REPOSITORY . '/releases/latest' ),
        );
        set_transient( self::CACHE_KEY, $release, 4 * HOUR_IN_SECONDS );
        return $release;
    }

    public static function check_update( $update, $plugin_data, $plugin_file, $locales ) {
        // A filter for github.com is shared by all plugins using GitHub Update URI.
        if ( self::$plugin_file !== $plugin_file ||
            ! isset( $plugin_data['UpdateURI'] ) ||
            self::UPDATE_URI !== untrailingslashit( $plugin_data['UpdateURI'] ) ) {
            return $update;
        }

        $release = self::release();
        if ( ! $release ) {
            return false;
        }

        return array(
            'id' => self::UPDATE_URI,
            'slug' => 'opc-live-visitor-counter',
            'version' => $release['version'],
            'url' => $release['url'],
            'package' => $release['package'],
            'requires_php' => '7.4',
            'autoupdate' => false,
        );
    }
}
