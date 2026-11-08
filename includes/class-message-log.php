<?php
/**
 * Local message log.
 *
 * Meta's Cloud API has no "list sent/received messages" endpoint, so the plugin
 * keeps a capped ring buffer (last 500) fed by outgoing sends and webhook events.
 */

defined( 'ABSPATH' ) || exit;

class WAMC_Message_Log {

    const OPTION   = 'wamc_message_log';
    const MAX      = 500;
    const PER_PAGE = 20;

    /** @param string $dir 'sent'|'received' */
    public static function add( string $dir, array $entry ): void {
        $log = self::all();
        array_unshift( $log, array_merge( [
            'dir'               => $dir,
            'id'                => '',
            'to'                => '',
            'text'              => '',
            'status'            => '',
            'time'              => current_time( 'mysql' ),
            // 24h window routing fields (populated by WAMC_Dispatcher)
            'notification_type' => '',
            'order_id'          => null,
            'window_status'     => '',   // 'open' | 'closed' | 'unknown'
            'last_inbound'      => null, // ISO datetime of last inbound from recipient
            'msg_type'          => '',   // 'text' | 'template' | 'skipped'
            'template_name'     => null,
            'reason'            => '',
        ], $entry ) );
        update_option( self::OPTION, array_slice( $log, 0, self::MAX ), false );
    }

    /**
     * Enrich the most recent log entry created by WAMC_Meta_Client::post_message()
     * instead of inserting a duplicate row in the log table.
     */
    public static function enrich_latest_or_add( string $dir, array $entry ): void {
        $log = self::all();
        if ( ! empty( $log ) && ( $log[0]['dir'] ?? '' ) === $dir ) {
            $same_id = ! empty( $entry['id'] ) && ( $log[0]['id'] ?? '' ) === $entry['id'];
            $same_to = ! empty( $entry['to'] ) && ( $log[0]['to'] ?? '' ) === $entry['to'];
            if ( $same_id || $same_to ) {
                // Preserve any webhook status update (e.g. 'read' / 'delivered' / 'failed') that already arrived
                $existing_status = $log[0]['status'] ?? '';
                $existing_error  = $log[0]['error'] ?? '';
                $log[0]          = array_merge( $log[0], $entry );
                if ( in_array( $existing_status, [ 'delivered', 'read', 'failed' ], true ) ) {
                    $log[0]['status'] = $existing_status;
                }
                if ( $existing_error !== '' && empty( $log[0]['error'] ) ) {
                    $log[0]['error'] = $existing_error;
                }
                update_option( self::OPTION, $log, false );
                return;
            }
        }
        self::add( $dir, $entry );
    }

    public static function update_status( string $id, string $status, string $error = '' ): void {
        if ( $id === '' ) {
            return;
        }
        $log     = self::all();
        $changed = false;
        foreach ( $log as &$e ) {
            if ( ( $e['id'] ?? '' ) === $id && ( $e['dir'] ?? '' ) === 'sent' ) {
                $e['status'] = $status;
                if ( $error !== '' ) {
                    $e['error'] = $error;
                }
                $changed = true;
                break;
            }
        }
        unset( $e );
        if ( $changed ) {
            update_option( self::OPTION, $log, false );
        }
    }

    public static function find( string $id ): ?array {
        foreach ( self::all() as $e ) {
            if ( ( $e['id'] ?? '' ) === $id ) {
                return $e;
            }
        }
        return null;
    }

    /** Output shape matches what the dashboard JS expects. */
    public static function paginate( string $dir, int $page, ?string $phone = null ): array {
        $rows = array_values( array_filter( self::all(), function ( $e ) use ( $dir, $phone ) {
            return ( $e['dir'] ?? '' ) === $dir
                && ( ! $phone || ( $e['to'] ?? '' ) === preg_replace( '/\D/', '', $phone ) );
        } ) );

        $count      = count( $rows );
        $page_count = max( 1, (int) ceil( $count / self::PER_PAGE ) );
        $page       = min( max( 1, $page ), $page_count );
        $slice      = array_slice( $rows, ( $page - 1 ) * self::PER_PAGE, self::PER_PAGE );

        return [
            'success' => true,
            'data'    => [
                'count'     => $count,
                'pageCount' => $page_count,
                'page'      => $page . ' / ' . $page_count,
                'data'      => array_map( fn( $e ) => [
                    'phonenumber'       => $e['to'],
                    'text'              => $e['text'],
                    'status'            => $e['status'],
                    'delivery'          => $e['error'] ?? '',
                    'createdAt'         => $e['time'],
                    'notification_type' => $e['notification_type'] ?? '',
                    'order_id'          => $e['order_id'] ?? '',
                    'window_status'     => $e['window_status'] ?? '',
                    'last_inbound'      => $e['last_inbound'] ?? '',
                    'msg_type'          => $e['msg_type'] ?? '',
                    'template_name'     => $e['template_name'] ?? '',
                    'reason'            => $e['reason'] ?? '',
                ], $slice ),
            ],
        ];
    }

    private static function all(): array {
        $log = get_option( self::OPTION, [] );
        return is_array( $log ) ? $log : [];
    }
}

