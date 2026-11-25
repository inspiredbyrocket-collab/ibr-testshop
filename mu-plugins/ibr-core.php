<?php
/**
 * Plugin Name: Inspired by Rocket – Kern
 * Description: Grundeinstellungen des Shops, unabhängig vom Theme. Wird automatisch geladen (Must-Use-Plugin).
 * Version: 0.1.0
 * Requires PHP: 8.1
 * Author: Inspired by Rocket
 *
 * @package ibr-core
 */

defined( 'ABSPATH' ) || exit;

// Entwicklungsumgebung hinter einem HTTPS-Proxy (z. B. GitHub Codespaces):
// WordPress erkennt HTTPS sonst nicht und leitet endlos um.
// Nur lokal aktiv; auf Test- und Live-Server regelt das der Hoster.
if (
	'local' === wp_get_environment_type()
	&& isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] )
	&& 'https' === strtolower( (string) $_SERVER['HTTP_X_FORWARDED_PROTO'] )
) {
	$_SERVER['HTTPS'] = 'on';
}

// Öffentliche Adresse der Entwicklungsumgebung (z. B. Codespaces), gesetzt von scripts/dev-setup.sh.
// Überschreibt die Adresse, die wp-env vorgibt (http://localhost:8888).
if ( 'local' === wp_get_environment_type() && defined( 'IBR_PUBLIC_URL' ) && IBR_PUBLIC_URL ) {
	$ibr_public_url = static fn(): string => untrailingslashit( (string) IBR_PUBLIC_URL );
	add_filter( 'option_home', $ibr_public_url, 99 );
	add_filter( 'option_siteurl', $ibr_public_url, 99 );
}

// Dateien nicht über den WordPress-Editor ändern lassen; Änderungen laufen über Git.
if ( ! defined( 'DISALLOW_FILE_EDIT' ) ) {
	define( 'DISALLOW_FILE_EDIT', true );
}

// XML-RPC wird nicht gebraucht und ist ein häufiges Angriffsziel.
add_filter( 'xmlrpc_enabled', '__return_false' );

// WordPress-Version nicht im Quelltext verraten.
remove_action( 'wp_head', 'wp_generator' );

// Auf der Testumgebung niemals von Suchmaschinen indexieren lassen.
add_filter(
	'wp_robots',
	static function ( array $robots ): array {
		if ( 'production' !== wp_get_environment_type() ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
		}
		return $robots;
	}
);

/**
 * Zusätzliche Reiter auf der Produktseite: Pflege, Sicherheit, Größe/Länge.
 * Die Texte stehen als Produktfelder (_ibr_pflege, _ibr_sicherheit, _ibr_groesse, _ibr_laenge).
 */
add_filter(
	'woocommerce_product_tabs',
	static function ( array $tabs ): array {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return $tabs;
		}

		$extra = array(
			'_ibr_groesse'    => array( 'ibr_groesse', 'Halsumfang', 15 ),
			'_ibr_laenge'     => array( 'ibr_laenge', 'Länge', 16 ),
			'_ibr_pflege'     => array( 'ibr_pflege', 'Pflege', 25 ),
			'_ibr_sicherheit' => array( 'ibr_sicherheit', 'Sicherheit', 30 ),
		);

		foreach ( $extra as $meta => list( $key, $title, $priority ) ) {
			$text = get_post_meta( $product->get_id(), $meta, true );
			if ( '' === trim( (string) $text ) ) {
				continue;
			}
			$tabs[ $key ] = array(
				'title'    => $title,
				'priority' => $priority,
				'callback' => static function () use ( $text ): void {
					echo wp_kses_post( wpautop( $text ) );
				},
			);
		}
		return $tabs;
	}
);

/**
 * Reiter kurz benennen (passen so auf dem Handy nebeneinander) und die doppelten
 * Überschriften in den Reitern weglassen – der Reiter sagt schon, worum es geht.
 */
add_filter(
	'woocommerce_product_tabs',
	static function ( array $tabs ): array {
		global $product;
		if ( isset( $tabs['description'] ) ) {
			$tabs['description']['title'] = 'Beschreibung';
		}
		if ( isset( $tabs['additional_information'] ) ) {
			$tabs['additional_information']['title'] = 'Details';
		}
		if ( isset( $tabs['reviews'] ) && $product instanceof WC_Product ) {
			$tabs['reviews']['title'] = sprintf( 'Bewertungen (%d)', $product->get_review_count() );
		}
		return $tabs;
	},
	99
);
add_filter( 'woocommerce_product_description_heading', '__return_empty_string' );
add_filter( 'woocommerce_product_additional_information_heading', '__return_empty_string' );

/**
 * Fotos, die nur als Verweis existieren (Browser-Testshop), direkt von ihrer Quelle laden.
 */
add_filter(
	'wp_get_attachment_url',
	static function ( $url, $id ) {
		$remote = get_post_meta( $id, '_ibr_remote_url', true );
		return $remote ? $remote : $url;
	},
	10,
	2
);
add_filter(
	'wp_calculate_image_srcset',
	static function ( $sources, $size, $src, $meta, $id ) {
		return get_post_meta( $id, '_ibr_remote_url', true ) ? false : $sources;
	},
	10,
	5
);
