<?php
/**
 * Importiert den bereinigten Shopify-Export (scripts/import/shopify-produkte.csv) in WooCommerce.
 *
 * - einfache und variable Produkte mit allen Varianten, Preisen, Streichpreisen, Beständen
 * - Kategorien nach Produkttyp, Schlagwörter, SEO-Titel und -Beschreibung (für Rank Math)
 * - Pflege-, Sicherheits- und Größenhinweise als eigene Felder (Reiter auf der Produktseite)
 * - Fotos direkt vom Shopify-CDN
 *
 * Läuft mehrfach ohne Doppelungen (Abgleich über den Shopify-Handle).
 * Nur für Test- und Entwicklungsumgebungen.
 *
 * Aufruf: npm run wp -- eval-file wp-content/ibr-scripts/shopify-import.php
 * Optional: IBR_IMPORT_MAX_IMAGES (Fotos pro Produkt, Standard alle),
 *           IBR_IMPORT_CSV (Pfad zur CSV),
 *           IBR_IMPORT_REMOTE_IMAGES (true: Fotos nicht herunterladen, sondern direkt vom
 *           Shopify-CDN anzeigen – für den Browser-Testshop, der nicht herunterladen kann)
 *
 * @package ibr-scripts
 */

defined( 'ABSPATH' ) || exit;

function ibr_import_log( string $msg ): void {
	class_exists( 'WP_CLI' ) ? WP_CLI::log( $msg ) : print( $msg . "\n" );
}

if ( 'production' === wp_get_environment_type() ) {
	throw new RuntimeException( 'Der Test-Import läuft nicht auf der Live-Seite.' );
}
if ( ! class_exists( 'WooCommerce' ) ) {
	throw new RuntimeException( 'WooCommerce ist nicht aktiv.' );
}

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

$ibr_csv        = defined( 'IBR_IMPORT_CSV' ) ? IBR_IMPORT_CSV : __DIR__ . '/import/shopify-produkte.csv';
$ibr_max_images = defined( 'IBR_IMPORT_MAX_IMAGES' ) ? (int) IBR_IMPORT_MAX_IMAGES : 99;

// Keine Zwischengrößen erzeugen: spart beim Testimport viel Zeit, WooCommerce nimmt dann das Original.
add_filter( 'intermediate_image_sizes_advanced', '__return_empty_array' );
add_filter( 'big_image_size_threshold', '__return_false' );

// Shop-Grundeinstellungen.
update_option( 'blogname', 'Inspired by Rocket' );
update_option( 'blogdescription', 'Besondere Lieblingsstücke für Hund & Mensch' );
update_option( 'woocommerce_currency', 'EUR' );
update_option( 'woocommerce_default_country', 'DE' );
update_option( 'woocommerce_price_decimal_sep', ',' );
update_option( 'woocommerce_price_thousand_sep', '.' );
update_option( 'woocommerce_currency_pos', 'right_space' );
update_option( 'woocommerce_manage_stock', 'yes' );
update_option( 'woocommerce_weight_unit', 'kg' );
update_option( 'woocommerce_coming_soon', 'no' );

/* ---------- Kategorien ---------- */

/**
 * Kategorie (optional unter einer Elternkategorie) mit festem Slug anlegen.
 */
function ibr_import_category( string $name, string $slug, int $parent = 0 ): int {
	$term = get_term_by( 'slug', $slug, 'product_cat' );
	if ( $term ) {
		return (int) $term->term_id;
	}
	$term = wp_insert_term( $name, 'product_cat', array( 'slug' => $slug, 'parent' => $parent ) );
	return is_wp_error( $term ) ? 0 : (int) $term['term_id'];
}

$ibr_cat_hund   = ibr_import_category( 'Für deinen Hund', 'hund' );
$ibr_cat_mensch = ibr_import_category( 'Für dich', 'mensch' );
$ibr_cats       = array(
	'leinen'      => ibr_import_category( 'Leinen', 'leinen', $ibr_cat_hund ),
	'halsbaender' => ibr_import_category( 'Halsbänder', 'halsbaender', $ibr_cat_hund ),
	'marken'      => ibr_import_category( 'Hundemarken', 'hundemarken', $ibr_cat_hund ),
	'decken'      => ibr_import_category( 'Decken', 'decken', $ibr_cat_hund ),
	'leckerli'    => ibr_import_category( 'Leckerli-Gläser', 'leckerli-glaeser', $ibr_cat_hund ),
	'handyketten' => ibr_import_category( 'Handyketten', 'handyketten', $ibr_cat_mensch ),
	'schmuck'     => ibr_import_category( 'Schmuck', 'schmuck', $ibr_cat_mensch ),
	'tassen'      => ibr_import_category( 'Tassen', 'tassen', $ibr_cat_mensch ),
	'taschen'     => ibr_import_category( 'Taschen', 'taschen', $ibr_cat_mensch ),
	'karten'      => ibr_import_category( 'Postkarten & Poster', 'postkarten-poster', $ibr_cat_mensch ),
	'gutscheine'  => ibr_import_category( 'Gutscheine', 'gutscheine' ),
	'workshops'   => ibr_import_category( 'Workshops', 'workshops' ),
);

/**
 * Kategorie aus Shopify-Produkttyp und Titel ableiten.
 */
function ibr_import_category_for( string $type, string $title, array $cats ): int {
	if ( false !== stripos( $title, 'Workshop' ) ) {
		return $cats['workshops'];
	}
	if ( false !== stripos( $title, 'Tote Bag' ) ) {
		return $cats['taschen'];
	}
	$map = array(
		'Hundeleinen'       => 'leinen',
		'Hundehalsband'     => 'halsbaender',
		'Hundemarke'        => 'marken',
		'Hundedecken'       => 'decken',
		'Aufbewahrung'      => 'leckerli',
		'Handykette'        => 'handyketten',
		'Halskette'         => 'schmuck',
		'Tasse'             => 'tassen',
		'Postkarte'         => 'karten',
		'Poster'            => 'karten',
		'Geschenkgutschein' => 'gutscheine',
	);
	return $cats[ $map[ $type ] ?? 'gutscheine' ] ?? 0;
}

// Diese Stücke erscheinen zuerst (Startseite „Eure Favoriten“).
$ibr_favorites = array( 'hundeleine-maya', 'hundehalsband-friendship', 'hundeleine-lotte-10-mm', 'handykette-leo' );

/* ---------- Hilfsfunktionen ---------- */

/**
 * Optionsnamen aus Shopify säubern: „Beschläge:“ → „Beschläge“.
 */
function ibr_import_option_name( string $name ): string {
	return trim( preg_replace( '/:\s*$|:\s*(?=\()/u', '', trim( $name ) ) );
}

/**
 * Foto vom Shopify-CDN in die Mediathek holen (einmal pro URL).
 */
function ibr_import_image( string $url, string $alt, int $parent ): int {
	if ( '' === $url ) {
		return 0;
	}
	$key      = md5( strtok( $url, '?' ) );
	$existing = get_posts(
		array(
			'post_type'   => 'attachment',
			'post_status' => 'inherit',
			'meta_key'    => '_ibr_source',
			'meta_value'  => $key,
			'numberposts' => 1,
			'fields'      => 'ids',
		)
	);
	if ( $existing ) {
		return (int) $existing[0];
	}

	// Shopify liefert auf Wunsch kleinere Fassungen; 900 px reichen für den Shop.
	$sized = add_query_arg( 'width', 900, $url );

	// Browser-Testshop: nur einen Verweis anlegen, das Foto lädt der Browser direkt von Shopify.
	if ( defined( 'IBR_IMPORT_REMOTE_IMAGES' ) && IBR_IMPORT_REMOTE_IMAGES ) {
		$id = wp_insert_attachment(
			array(
				'post_title'     => $alt,
				'post_mime_type' => 'image/jpeg',
				'post_status'    => 'inherit',
				'guid'           => $sized,
			),
			false,
			$parent
		);
		update_post_meta( $id, '_ibr_remote_url', $sized );
		update_post_meta( $id, '_ibr_source', $key );
		update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		// Die meisten Shop-Fotos sind Hochformat 9:16; genaue Maße sind ohne Download nicht bekannt.
		wp_update_attachment_metadata( $id, array( 'width' => 900, 'height' => 1600, 'file' => '', 'sizes' => array() ) );
		return (int) $id;
	}

	$tmp = download_url( $sized, 30 );
	if ( is_wp_error( $tmp ) ) {
		ibr_import_log( '  Foto nicht geladen: ' . $tmp->get_error_message() );
		return 0;
	}
	$name = sanitize_file_name( basename( strtok( $url, '?' ) ) );
	if ( ! preg_match( '/\.(jpe?g|png|webp|gif)$/i', $name ) ) {
		$name .= '.jpg';
	}
	$id = media_handle_sideload( array( 'name' => $name, 'tmp_name' => $tmp ), $parent );
	if ( is_wp_error( $id ) ) {
		@unlink( $tmp );
		ibr_import_log( '  Foto nicht übernommen: ' . $id->get_error_message() );
		return 0;
	}
	update_post_meta( $id, '_ibr_source', $key );
	if ( '' !== $alt ) {
		update_post_meta( $id, '_wp_attachment_image_alt', $alt );
	}
	return (int) $id;
}

/**
 * SKU nur setzen, wenn sie noch frei ist.
 */
function ibr_import_sku( WC_Product $product, string $sku ): void {
	if ( '' === $sku ) {
		return;
	}
	$owner = wc_get_product_id_by_sku( $sku );
	if ( $owner && $owner !== $product->get_id() ) {
		ibr_import_log( "  Artikelnummer doppelt, übersprungen: $sku" );
		return;
	}
	try {
		$product->set_sku( $sku );
	} catch ( WC_Data_Exception $e ) {
		ibr_import_log( "  Artikelnummer nicht gesetzt ($sku): " . $e->getMessage() );
	}
}

/**
 * Preise, Bestand, Gewicht aus einer Shopify-Variantenzeile übernehmen.
 */
function ibr_import_variant_data( WC_Product $product, array $row ): void {
	$price   = (float) $row['Variant Price'];
	$compare = (float) $row['Variant Compare At Price'];
	if ( $compare > $price ) {
		$product->set_regular_price( wc_format_decimal( $compare ) );
		$product->set_sale_price( wc_format_decimal( $price ) );
	} else {
		$product->set_regular_price( wc_format_decimal( $price ) );
		$product->set_sale_price( '' );
	}
	$product->set_manage_stock( true );
	$product->set_stock_quantity( (int) $row['Variant Inventory Qty'] );
	$product->set_backorders( 'continue' === $row['Variant Inventory Policy'] ? 'notify' : 'no' );
	if ( (float) $row['Variant Grams'] > 0 ) {
		$product->set_weight( wc_format_decimal( (float) $row['Variant Grams'] / 1000, 3 ) );
	}
	if ( 'false' === $row['Variant Requires Shipping'] ) {
		$product->set_virtual( true );
	}
}

/* ---------- CSV lesen ---------- */

if ( ! file_exists( $ibr_csv ) ) {
	throw new RuntimeException( "CSV nicht gefunden: $ibr_csv" );
}
$ibr_fh     = fopen( $ibr_csv, 'r' );
$ibr_header = fgetcsv( $ibr_fh, 0, ',', '"', '' );
$ibr_groups = array();
while ( ( $ibr_line = fgetcsv( $ibr_fh, 0, ',', '"', '' ) ) !== false ) {
	$ibr_row                          = array_combine( $ibr_header, array_pad( $ibr_line, count( $ibr_header ), '' ) );
	$ibr_groups[ $ibr_row['Handle'] ][] = $ibr_row;
}
fclose( $ibr_fh );

// Demo-Produkte aus früheren Ständen entfernen; jetzt gibt es echte Produkte.
foreach ( wc_get_products( array( 'limit' => -1, 'status' => 'any', 'return' => 'ids', 'meta_key' => '_ibr_handle', 'meta_compare' => 'NOT EXISTS' ) ) as $ibr_old ) {
	wc_get_product( $ibr_old )->delete( true );
}

/* ---------- Produkte anlegen ---------- */

$ibr_count = 0;
foreach ( $ibr_groups as $ibr_handle => $ibr_rows ) {
	$ibr_main     = $ibr_rows[0];
	$ibr_variants = array_values( array_filter( $ibr_rows, fn( $r ) => '' !== $r['Variant Price'] ) );
	$ibr_images   = array_values( array_filter( $ibr_rows, fn( $r ) => '' !== $r['Image Src'] ) );
	usort( $ibr_images, fn( $a, $b ) => (int) $a['Image Position'] <=> (int) $b['Image Position'] );

	$ibr_existing = wc_get_products( array( 'limit' => 1, 'return' => 'ids', 'status' => 'any', 'meta_key' => '_ibr_handle', 'meta_value' => $ibr_handle ) );
	if ( $ibr_existing ) {
		continue;
	}

	$ibr_options = array();
	foreach ( array( 1, 2, 3 ) as $ibr_i ) {
		$ibr_name = $ibr_main[ "Option{$ibr_i} Name" ];
		if ( '' !== $ibr_name && 'Title' !== $ibr_name ) {
			$ibr_options[ $ibr_i ] = ibr_import_option_name( $ibr_name );
		}
	}
	$ibr_is_variable = $ibr_options && count( $ibr_variants ) > 1;

	$ibr_product = $ibr_is_variable ? new WC_Product_Variable() : new WC_Product_Simple();
	$ibr_product->set_name( $ibr_main['Title'] );
	$ibr_product->set_slug( $ibr_handle );
	$ibr_product->set_status( 'publish' );
	$ibr_product->set_description( wp_kses_post( $ibr_main['Body (HTML)'] ) );
	$ibr_product->set_category_ids( array( ibr_import_category_for( $ibr_main['Type'], $ibr_main['Title'], $ibr_cats ) ) );
	$ibr_fav = array_search( $ibr_handle, $ibr_favorites, true );
	$ibr_product->set_menu_order( false === $ibr_fav ? 100 + $ibr_count : $ibr_fav + 1 );

	if ( $ibr_is_variable ) {
		$ibr_attributes = array();
		foreach ( $ibr_options as $ibr_i => $ibr_label ) {
			$ibr_values = array_values( array_unique( array_filter( array_map( fn( $v ) => $v[ "Option{$ibr_i} Value" ], $ibr_variants ) ) ) );
			$ibr_attr   = new WC_Product_Attribute();
			$ibr_attr->set_name( $ibr_label );
			$ibr_attr->set_options( $ibr_values );
			$ibr_attr->set_visible( true );
			$ibr_attr->set_variation( true );
			$ibr_attributes[] = $ibr_attr;
		}
		$ibr_product->set_attributes( $ibr_attributes );
	} else {
		ibr_import_variant_data( $ibr_product, $ibr_variants[0] );
		ibr_import_sku( $ibr_product, $ibr_variants[0]['Variant SKU'] );
	}

	$ibr_id = $ibr_product->save();
	update_post_meta( $ibr_id, '_ibr_handle', $ibr_handle );

	// Schlagwörter.
	$ibr_tags = array_filter( array_map( 'trim', explode( ',', $ibr_main['Tags'] ) ) );
	if ( $ibr_tags ) {
		wp_set_object_terms( $ibr_id, $ibr_tags, 'product_tag' );
	}

	// SEO (Rank Math liest diese Felder) und Zusatzhinweise.
	$ibr_meta = array(
		'rank_math_title'       => 'SEO Title',
		'rank_math_description' => 'SEO Description',
		'_ibr_pflege'           => 'Pflegehinweise (product.metafields.custom.pflegehinweise)',
		'_ibr_sicherheit'       => 'Warn- und Sicherheitshinweise (product.metafields.custom.warn_und_sicherheitshinweise)',
		'_ibr_groesse'          => 'Halsumfang messen (product.metafields.custom.halsumfang_messen)',
		'_ibr_laenge'           => 'Länge Handykette messen (product.metafields.custom.hilfe_bei_der_auswahl_der_laenge)',
	);
	foreach ( $ibr_meta as $ibr_key => $ibr_col ) {
		if ( '' !== trim( $ibr_main[ $ibr_col ] ?? '' ) ) {
			update_post_meta( $ibr_id, $ibr_key, sanitize_textarea_field( $ibr_main[ $ibr_col ] ) );
		}
	}

	// Fotos.
	$ibr_image_ids = array();
	$ibr_by_url    = array();
	foreach ( array_slice( $ibr_images, 0, $ibr_max_images ) as $ibr_img ) {
		$ibr_img_id = ibr_import_image( $ibr_img['Image Src'], $ibr_img['Image Alt Text'] ?: $ibr_main['Title'], $ibr_id );
		if ( $ibr_img_id ) {
			$ibr_image_ids[]                         = $ibr_img_id;
			$ibr_by_url[ $ibr_img['Image Src'] ] = $ibr_img_id;
		}
	}
	if ( $ibr_image_ids ) {
		$ibr_product = wc_get_product( $ibr_id );
		$ibr_product->set_image_id( array_shift( $ibr_image_ids ) );
		$ibr_product->set_gallery_image_ids( $ibr_image_ids );
		$ibr_product->save();
	}

	// Varianten.
	if ( $ibr_is_variable ) {
		foreach ( $ibr_variants as $ibr_row ) {
			$ibr_variation = new WC_Product_Variation();
			$ibr_variation->set_parent_id( $ibr_id );
			$ibr_attrs = array();
			foreach ( $ibr_options as $ibr_i => $ibr_label ) {
				$ibr_attrs[ sanitize_title( $ibr_label ) ] = $ibr_row[ "Option{$ibr_i} Value" ];
			}
			$ibr_variation->set_attributes( $ibr_attrs );
			ibr_import_variant_data( $ibr_variation, $ibr_row );
			ibr_import_sku( $ibr_variation, $ibr_row['Variant SKU'] );
			if ( isset( $ibr_by_url[ $ibr_row['Variant Image'] ] ) ) {
				$ibr_variation->set_image_id( $ibr_by_url[ $ibr_row['Variant Image'] ] );
			}
			$ibr_variation->save();
		}
		WC_Product_Variable::sync( $ibr_id );
	}

	++$ibr_count;
	ibr_import_log( sprintf( 'Importiert: %s (%d Varianten, %d Fotos)', $ibr_main['Title'], count( $ibr_variants ), count( $ibr_by_url ) ) );
}

ibr_import_log( "Success: Import fertig, $ibr_count neue Produkte." );
