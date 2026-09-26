<?php
/**
 * Removes the plugin's data — only when the owner asked for it in Settings → Advanced.
 *
 * @package NetArz_AI
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$netarz_ai_settings = get_option( 'netarz_ai_settings', array() );
if ( empty( $netarz_ai_settings['delete_on_uninstall'] ) ) {
	return;
}

global $wpdb;

foreach ( array( 'netarz_ai_chats', 'netarz_ai_chat_messages', 'netarz_ai_tickets', 'netarz_ai_ticket_replies', 'netarz_ai_log' ) as $netarz_ai_table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$netarz_ai_table}" ); // phpcs:ignore
}

foreach ( array( 'netarz_ai_settings', 'netarz_ai_db_version', 'netarz_ai_page_created', 'netarz_ai_flush_rewrite' ) as $netarz_ai_option ) {
	delete_option( $netarz_ai_option );
}
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_netarz\_ai\_%' OR option_name LIKE '\_transient\_timeout\_netarz\_ai\_%' OR option_name LIKE 'netarz\_ai\_lock\_%'" ); // phpcs:ignore

// Private ticket attachments.
$netarz_ai_uploads = wp_upload_dir( null, false );
$netarz_ai_dir     = trailingslashit( $netarz_ai_uploads['basedir'] ) . 'netarz-ai-tickets';
if ( is_dir( $netarz_ai_dir ) ) {
	$netarz_ai_files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $netarz_ai_dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $netarz_ai_files as $netarz_ai_file ) {
		$netarz_ai_file->isDir() ? @rmdir( $netarz_ai_file->getPathname() ) : @unlink( $netarz_ai_file->getPathname() ); // phpcs:ignore
	}
	@rmdir( $netarz_ai_dir ); // phpcs:ignore
}

foreach ( wp_roles()->role_objects as $netarz_ai_role ) {
	$netarz_ai_role->remove_cap( 'netarz_ai_support' );
	$netarz_ai_role->remove_cap( 'netarz_ai_writer' );
}

wp_clear_scheduled_hook( 'netarz_ai_daily' );
wp_unschedule_hook( 'netarz_ai_ticket_ai' );
