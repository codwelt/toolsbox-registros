<?php
/**
 * Puntos de entrada de activación y desactivación.
 *
 * @package ToolsBoxRegistros
 */

declare(strict_types=1);

namespace Codwelt\ToolsBoxRegistros\Install;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gestiona la instalación sin eliminar datos al desactivar.
 */
final class Installer {
	public const CAPABILITIES = array( 'tbreg_manage_forms', 'tbreg_view_entries', 'tbreg_edit_entries', 'tbreg_export_entries' );

	/**
	 * Activa el plugin en el sitio actual o en los sitios de la red.
	 *
	 * @param bool $network_wide Activación para toda la red.
	 * @return void
	 */
	public static function activate( bool $network_wide = false ): void {
		if ( ! is_multisite() || ! $network_wide ) {
			self::install_site();
			return;
		}

		$offset = 0;
		do {
			$sites = get_sites(
				array(
					'fields' => 'ids',
					'number' => 100,
					'offset' => $offset,
				)
			);
			foreach ( $sites as $site_id ) {
				switch_to_blog( (int) $site_id );
				try {
					self::install_site();
				} finally {
					restore_current_blog();
				}
			}
			$offset += 100;
			$count   = count( $sites );
		} while ( 100 === $count );
	}

	/**
	 * Crea o actualiza las tablas y concede las capacidades configuradas.
	 *
	 * @return void
	 * @throws \RuntimeException Si alguna tabla no se puede crear.
	 */
	public static function install_site(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( self::schema( $wpdb ) );

		$columns = array(
			'forms'        => array( 'id', 'title', 'slug', 'description', 'status', 'fields', 'settings', 'entries_count', 'created_by', 'created_at', 'updated_at' ),
			'entries'      => array( 'id', 'form_id', 'status', 'user_id', 'email', 'document', 'ip_hash', 'user_agent', 'source_url', 'created_at', 'updated_at' ),
			'entry_values' => array( 'id', 'entry_id', 'field_key', 'value' ),
		);
		foreach ( $columns as $suffix => $required ) {
			$table = $wpdb->prefix . 'tbreg_' . $suffix;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- La instalación verifica el esquema real; una caché podría ocultar un fallo.
			$found = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $table ) ), ARRAY_A );
			if ( null === $found || $found['Name'] !== $table || 'InnoDB' !== $found['Engine'] ) {
				throw new \RuntimeException( 'tbreg_schema_failed' );
			}
			$identifier = '`' . str_replace( '`', '``', $table ) . '`';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- SHOW sin valores; identificador interno escapado, verificación sin caché.
			$actual = $wpdb->get_col( 'SHOW COLUMNS FROM ' . $identifier );
			if ( array() !== array_diff( $required, $actual ) ) {
				throw new \RuntimeException( 'tbreg_schema_failed' );
			}
		}

		$roles = apply_filters( 'tbreg_capability_roles', array( 'administrator' ) );
		foreach ( is_array( $roles ) ? $roles : array() as $role_name ) {
			$role = is_string( $role_name ) ? get_role( $role_name ) : null;
			if ( null === $role ) {
				continue;
			}
			foreach ( self::CAPABILITIES as $capability ) {
				$role->add_cap( $capability );
			}
		}
		update_option( 'tbreg_db_version', Migrations::DB_VERSION, false );
	}

	/**
	 * Devuelve el esquema para dbDelta; los identificadores proceden de WordPress.
	 *
	 * @param \wpdb $db Conexión del sitio.
	 * @return array<string>
	 */
	public static function schema( \wpdb $db ): array {
		$charset = $db->get_charset_collate();
		$prefix  = $db->prefix . 'tbreg_';
		return array(
			"CREATE TABLE {$prefix}forms (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				title varchar(200) NOT NULL,
				slug varchar(200) NOT NULL,
				description text NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'draft',
				fields longtext NOT NULL,
				settings longtext NOT NULL,
				entries_count int unsigned NOT NULL DEFAULT 0,
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY slug (slug),
				KEY status (status)
			) ENGINE=InnoDB {$charset};",
			"CREATE TABLE {$prefix}entries (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				form_id bigint(20) unsigned NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'confirmed',
				user_id bigint(20) unsigned DEFAULT NULL,
				email varchar(190) DEFAULT NULL,
				document varchar(60) DEFAULT NULL,
				ip_hash char(64) NOT NULL DEFAULT '',
				user_agent varchar(255) NOT NULL DEFAULT '',
				source_url varchar(255) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY form_id (form_id),
				KEY status (status),
				KEY form_email (form_id,email),
				KEY form_document (form_id,document),
				KEY form_created (form_id,created_at)
			) ENGINE=InnoDB {$charset};",
			"CREATE TABLE {$prefix}entry_values (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				entry_id bigint(20) unsigned NOT NULL,
				field_key varchar(64) NOT NULL,
				value longtext NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY entry_field (entry_id,field_key)
			) ENGINE=InnoDB {$charset};",
		);
	}

	/**
	 * Desactiva el plugin. La limpieza de tareas se añadirá con sus servicios.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		// La fase 0 no registra tareas ni recursos que deban liberarse.
	}
}
