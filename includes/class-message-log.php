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
                'data'      => array_map( function ( $e ) {
                    $oid = $e['order_id'] ?? '';
                    // Backfill order_id for older received messages if missing
                    if ( empty( $oid ) && ( $e['dir'] ?? '' ) === 'received' && ! empty( $e['to'] ) && class_exists( 'WAMC_Order_Lookup' ) ) {
                        $found = WAMC_Order_Lookup::find_latest( (string) $e['to'] );
                        if ( $found ) {
                            $oid = $found->get_id();
                        }
                    }
                    return [
                        'phonenumber'       => $e['to'],
                        'text'              => $e['text'],
                        'status'            => $e['status'],
                        'delivery'          => $e['error'] ?? '',
                        'error'             => $e['error'] ?? '',
                        'createdAt'         => $e['time'],
                        'notification_type' => $e['notification_type'] ?? '',
                        'order_id'          => $oid,
                        'window_status'     => $e['window_status'] ?? '',
                        'last_inbound'      => $e['last_inbound'] ?? '',
                        'msg_type'          => $e['msg_type'] ?? '',
                        'template_name'     => $e['template_name'] ?? '',
                        'sender_user'       => $e['sender_user'] ?? '',
                        'reason'            => $e['reason'] ?? '',
                    ];
                }, $slice ),
            ],
        ];
    }

    /**
     * Delete log entries by date range (YYYY-MM-DD to YYYY-MM-DD) or clear all.
     *
     * @return int Number of deleted entries.
     */
    public static function delete_by_date( string $from_date = '', string $to_date = '', string $dir = 'all' ): int {
        $all = self::all();
        if ( empty( $all ) ) {
            return 0;
        }

        $from_ts = $from_date !== '' ? strtotime( $from_date . ' 00:00:00' ) : null;
        $to_ts   = $to_date !== ''   ? strtotime( $to_date . ' 23:59:59' )   : null;

        $kept    = [];
        $deleted = 0;

        foreach ( $all as $e ) {
            $entry_dir = $e['dir'] ?? 'sent';
            if ( $dir !== 'all' && $entry_dir !== $dir ) {
                $kept[] = $e;
                continue;
            }

            if ( $from_ts === null && $to_ts === null ) {
                // Clear all matching $dir
                $deleted++;
                continue;
            }

            $entry_ts = ! empty( $e['time'] ) ? strtotime( (string) $e['time'] ) : false;
            if ( ! $entry_ts ) {
                $kept[] = $e;
                continue;
            }

            $match_from = ( $from_ts === null ) || ( $entry_ts >= $from_ts );
            $match_to   = ( $to_ts === null )   || ( $entry_ts <= $to_ts );

            if ( $match_from && $match_to ) {
                $deleted++;
            } else {
                $kept[] = $e;
            }
        }

        update_option( self::OPTION, array_values( $kept ), false );
        return $deleted;
    }

    private static function all(): array {
        $log = get_option( self::OPTION, [] );
        return is_array( $log ) ? $log : [];
    }
}

