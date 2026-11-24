<?php
/**
 * Legt Demo-Inhalte für Tests an: Kategorien und vier Produkte mit echten Fotos.
 * Nur für lokale Umgebungen, Codespaces, Playground und automatische Tests.
 * Preise und Varianten sind Platzhalter.
 *
 * Aufruf: npm run wp -- eval-file wp-content/ibr-scripts/demo-content.php
 *
 * @package ibr-scripts
 */

defined( 'ABSPATH' ) || exit;

// Läuft mit WP-CLI (lokal, Codespaces, GitHub Actions) und ohne (WordPress Playground).
function ibr_demo_log( string $msg ): void {
	class_exists( 'WP_CLI' ) ? WP_CLI::log( $msg ) : print( $msg . "\n" );
}
function ibr_demo_error( string $msg ): void {
	if ( class_exists( 'WP_CLI' ) ) {
		WP_CLI::error( $msg );
	}
	throw new RuntimeException( $msg );
}

if ( 'production' === wp_get_environment_type() ) {
	ibr_demo_error( 'Demo-Inhalte werden auf der Live-Seite nicht angelegt.' );
}

if ( ! class_exists( 'WooCommerce' ) ) {
	ibr_demo_error( 'WooCommerce ist nicht aktiv.' );
}

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

// Shop-Grundeinstellungen.
update_option( 'blogname', 'Inspired by Rocket' );
update_option( 'blogdescription', 'Besondere Lieblingsstücke für Hund & Mensch' );
update_option( 'woocommerce_currency', 'EUR' );
update_option( 'woocommerce_default_country', 'DE' );
update_option( 'woocommerce_price_decimal_sep', ',' );
update_option( 'woocommerce_price_thousand_sep', '.' );
update_option( 'woocommerce_currency_pos', 'right_space' );
update_option( 'woocommerce_manage_stock', 'yes' );
update_option( 'woocommerce_coming_soon', 'no' );

/**
 * Kategorie mit festem Slug anlegen oder vorhandene zurückgeben.
 */
function ibr_demo_category( string $name, string $slug ): int {
	$term = get_term_by( 'slug', $slug, 'product_cat' );
	if ( $term ) {
		return (int) $term->term_id;
	}
	$term = wp_insert_term( $name, 'product_cat', array( 'slug' => $slug ) );
	return (int) $term['term_id'];
}

/**
 * Produkt per Namen finden, damit das Skript mehrfach laufen kann.
 */
function ibr_demo_find( string $name ): int {
	$ids = wc_get_products(
		array(
			'name'   => $name,
			'limit'  => 1,
			'return' => 'ids',
			'status' => 'any',
		)
	);
	return $ids ? (int) $ids[0] : 0;
}

/**
 * Foto aus scripts/demo-images in die Mediathek übernehmen (einmalig).
 */
function ibr_demo_image( string $file, string $alt ): int {
	$existing = get_posts(
		array(
			'post_type'   => 'attachment',
			'post_status' => 'inherit',
			'meta_key'    => '_ibr_demo_image',
			'meta_value'  => $file,
			'numberposts' => 1,
			'fields'      => 'ids',
		)
	);
	if ( $existing ) {
		return (int) $existing[0];
	}

	$source = __DIR__ . '/demo-images/' . $file;
	if ( ! file_exists( $source ) ) {
		ibr_demo_log( "Foto fehlt: $file" );
		return 0;
	}

	$tmp = wp_tempnam( $file );
	copy( $source, $tmp );
	$id = media_handle_sideload( array( 'name' => $file, 'tmp_name' => $tmp ), 0 );
	if ( is_wp_error( $id ) ) {
		ibr_demo_log( "Foto nicht übernommen ($file): " . $id->get_error_message() );
		return 0;
	}
	update_post_meta( $id, '_ibr_demo_image', $file );
	update_post_meta( $id, '_wp_attachment_image_alt', $alt );
	return (int) $id;
}

// Alte Demo-Produkte aus früheren Ständen entfernen.
foreach ( array( 'Handykette LEO (Demo)', 'Hundeleine LOTTE (Demo)' ) as $old ) {
	$old_id = ibr_demo_find( $old );
	if ( $old_id ) {
		wc_get_product( $old_id )->delete( true );
		ibr_demo_log( "Entfernt: $old" );
	}
}

$cat_hund   = ibr_demo_category( 'Für deinen Hund', 'hund' );
$cat_mensch = ibr_demo_category( 'Für dich', 'mensch' );

$simple_products = array(
	array(
		'name'  => 'Halsband Makramee',
		'price' => '29.90',
		'cat'   => $cat_hund,
		'image' => array( 'halsband-makramee.webp', 'Geknüpftes Halsband in Lila und Rosé mit Lederverschluss' ),
		'text'  => 'Von Hand geknüpftes Halsband mit Lederverschluss und Messingschnalle.',
		'order' => 2,
		'stock' => 6,
	),
	array(
		'name'  => 'Hundeleine Sand & Neon',
		'price' => '44.90',
		'cat'   => $cat_hund,
		'image' => array( 'hundeleine-sand-neon.webp', 'Sandfarbene Seilleine mit neonfarbenen Wicklungen und Messingringen' ),
		'text'  => 'Verstellbare Seilleine in Sand mit leuchtenden Wicklungen.',
		'order' => 3,
		'stock' => 4,
	),
	array(
		'name'  => 'Namenskette, personalisiert',
		'price' => '24.90',
		'cat'   => $cat_mensch,
		'image' => array( 'namenskette.webp', 'Goldene Kette mit Namensplättchen ELLA und Herz' ),
		'text'  => 'Von Hand gestempelt, mit dem Namen deines Lieblings.',
		'order' => 4,
		'stock' => 10,
	),
);

foreach ( $simple_products as $data ) {
	if ( ibr_demo_find( $data['name'] ) ) {
		continue;
	}
	$product = new WC_Product_Simple();
	$product->set_name( $data['name'] );
	$product->set_regular_price( $data['price'] );
	$product->set_category_ids( array( $data['cat'] ) );
	$product->set_manage_stock( true );
	$product->set_stock_quantity( $data['stock'] );
	$product->set_short_description( $data['text'] . ' Demo-Produkt, der Preis ist ein Platzhalter.' );
	$product->set_menu_order( $data['order'] );
	$product->set_image_id( ibr_demo_image( $data['image'][0], $data['image'][1] ) );
	$product->save();
	ibr_demo_log( 'Angelegt: ' . $data['name'] );
}

// Variables Produkt mit Länge und Stärke.
if ( ! ibr_demo_find( 'Hundeleine Oliv' ) ) {
	$attributes = array();
	foreach ( array( 'Länge' => array( '1,5 m', '2,0 m' ), 'Stärke' => array( '8 mm', '10 mm' ) ) as $label => $options ) {
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( $label );
		$attribute->set_options( $options );
		$attribute->set_visible( true );
		$attribute->set_variation( true );
		$attributes[] = $attribute;
	}

	$product = new WC_Product_Variable();
	$product->set_name( 'Hundeleine Oliv' );
	$product->set_category_ids( array( $cat_hund ) );
	$product->set_attributes( $attributes );
	$product->set_short_description( 'Seilleine in Oliv mit goldenen Karabinern. Demo-Produkt mit Varianten, die Preise sind Platzhalter.' );
	$product->set_menu_order( 1 );
	$product->set_image_id( ibr_demo_image( 'hundeleine-oliv.webp', 'Olivgrüne Seilleine mit goldenen Karabinern' ) );
	$parent_id = $product->save();

	$stock = 5;
	foreach ( array( '1,5 m' => array( '34.90', '36.90' ), '2,0 m' => array( '38.90', '40.90' ) ) as $laenge => $prices ) {
		foreach ( array( '8 mm', '10 mm' ) as $i => $staerke ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $parent_id );
			$variation->set_attributes(
				array(
					sanitize_title( 'Länge' )  => $laenge,
					sanitize_title( 'Stärke' ) => $staerke,
				)
			);
			$variation->set_regular_price( $prices[ $i ] );
			$variation->set_manage_stock( true );
			$variation->set_stock_quantity( $stock-- );
			$variation->save();
		}
	}

	WC_Product_Variable::sync( $parent_id );
	ibr_demo_log( 'Angelegt: Hundeleine Oliv mit 4 Varianten' );
}

ibr_demo_log( 'Success: Demo-Inhalte sind bereit.' );
