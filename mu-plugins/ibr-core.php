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
			'_ibr_groesse'    => array( 'ibr_groesse', 'Halsumfang messen', 15 ),
			'_ibr_laenge'     => array( 'ibr_laenge', 'Die richtige Länge', 16 ),
			'_ibr_pflege'     => array( 'ibr_pflege', 'Pflegehinweise', 25 ),
			'_ibr_sicherheit' => array( 'ibr_sicherheit', 'Sicherheitshinweise', 30 ),
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
