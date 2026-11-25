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
 *           IBR_IMPORT_IMAGE_DIR (Ordner mit vorab geladenen Fotos <md5(URL)>.jpg – Browser-Testshop),
 *           IBR_IMPORT_REMOTE_IMAGES (true: nur Verweis aufs Shopify-CDN, Foto lädt der Browser)
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
$ibr_favorites = array( 'hundeleine-maya', 'hundehalsband-friendship', 'hundeleine-lotte', 'handykette-leo' );

/* ---------- Hilfsfunktionen ---------- */

/**
 * Optionsnamen aus Shopify säubern: „Beschläge:“ → „Beschläge“.
 */
function ibr_import_option_name( string $name ): string {
	$name = preg_replace( '/:\s*(?=\()/u', ' ', trim( $name ) ); // „Halsumfang: (in cm)“ → „Halsumfang (in cm)“
	return trim( preg_replace( '/:\s*$/u', '', $name ) );        // „Beschläge:“ → „Beschläge“
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

	// Browser-Testshop: Das Foto hat Playground vorab abgeholt und hier abgelegt (gleiche Herkunft, jeder Browser zeigt es).
	if ( defined( 'IBR_IMPORT_IMAGE_DIR' ) ) {
		$local = IBR_IMPORT_IMAGE_DIR . '/' . $key . '.jpg';
		if ( ! file_exists( $local ) ) {
			ibr_import_log( '  Foto nicht vorab geladen: ' . basename( strtok( $url, '?' ) ) );
			return 0;
		}
		$tmp = wp_tempnam( $key . '.jpg' );
		copy( $local, $tmp );
	} else {
		$tmp = download_url( $sized, 30 );
	}
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

/*
 * Modelle, die in Shopify als getrennte Produkte je Seilstärke angelegt sind, zu einem Produkt
 * mit der Auswahl „Stärke“ zusammenführen. Preise, Bestände und Fotos bleiben je Stärke erhalten.
 */
$ibr_merges = array(
	'hundeleine-lotte' => array(
		'title' => 'Hundeleine LOTTE',
		'attr'  => 'Stärke',
		'parts' => array( 'hundeleine-lotte' => '10 mm', 'hundeleine-lotte-8-mm' => '8 mm' ),
	),
);
foreach ( $ibr_merges as $ibr_target => $ibr_merge ) {
	$ibr_merged   = array();
	$ibr_position = 0;
	foreach ( $ibr_merge['parts'] as $ibr_part => $ibr_value ) {
		foreach ( $ibr_groups[ $ibr_part ] ?? array() as $ibr_row ) {
			$ibr_row['Handle']        = $ibr_target;
			$ibr_row['Option3 Name']  = $ibr_row['Option2 Name'];
			$ibr_row['Option3 Value'] = $ibr_row['Option2 Value'];
			$ibr_row['Option2 Name']  = $ibr_row['Option1 Name'];
			$ibr_row['Option2 Value'] = $ibr_row['Option1 Value'];
			$ibr_row['Option1 Name']  = $ibr_merge['attr'];
			$ibr_row['Option1 Value'] = '' !== $ibr_row['Variant Price'] ? $ibr_value : '';
			if ( '' !== $ibr_row['Image Src'] ) {
				$ibr_row['Image Position'] = (string) ++$ibr_position;
			}
			$ibr_merged[] = $ibr_row;
		}
		unset( $ibr_groups[ $ibr_part ] );
	}
	if ( $ibr_merged ) {
		$ibr_merged[0]['Title'] = $ibr_merge['title'];
		$ibr_groups             = array( $ibr_target => $ibr_merged ) + $ibr_groups;
	}
}

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
	// Kurzbeschreibung unter dem Preis: die SEO-Beschreibung aus Shopify (kurz und passend),
	// sonst schneidet WordPress den Anfang der langen Beschreibung ab.
	if ( '' !== trim( $ibr_main['SEO Description'] ?? '' ) ) {
		$ibr_product->set_short_description( esc_html( trim( $ibr_main['SEO Description'] ) ) );
	}
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
		ibr_import_sku( $ibr_product, $ibr_variants[0]['Variant SKU'] ?? '' );
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
			ibr_import_sku( $ibr_variation, $ibr_row['Variant SKU'] ?? '' );
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

/* ---------- Beispiel-Farbpaletten und -Optionen (Plugin ibr-produktoptionen) ---------- */

/**
 * Variante eines Produkts mit einem bestimmten Eigenschaftswert finden (z. B. Stärke = 8 mm).
 *
 * @return int[]
 */
function ibr_import_variations_with( int $product_id, string $attr, string $value ): array {
	$ids = array();
	foreach ( wc_get_product( $product_id )->get_children() as $child ) {
		$variation = wc_get_product( $child );
		if ( $variation && $variation->get_attribute( $attr ) === $value ) {
			$ids[] = $child;
		}
	}
	return $ids;
}

function ibr_import_product_id( string $handle ): int {
	$ids = wc_get_products( array( 'limit' => 1, 'return' => 'ids', 'meta_key' => '_ibr_handle', 'meta_value' => $handle ) );
	return $ids ? (int) $ids[0] : 0;
}

if ( post_type_exists( 'ibr_farbe' ) && ! term_exists( 'paracord-beispiel', 'ibr_palette' ) ) {
	$ibr_hex = array(
		'Sand' => '#CDB894', 'Beige' => '#D8C7AA', 'Weiß' => '#F2F0EB', 'Schwarz' => '#222222',
		'Oliv' => '#6B6A3E', 'Guacamole' => '#8A9A5B', 'Tannengrün' => '#3F5147', 'Mint' => '#A8DCCB',
		'Hellblau' => '#9DB8DE', 'Flieder' => '#B9A6D3', 'Lila' => '#6A3D8F', 'Rosé' => '#D9A3A0',
		'Bordeaux' => '#7E1F2C', 'Rost' => '#984B2C', 'Cognac' => '#9C5B32', 'Neonpink' => '#FF3D8B',
		'Neonorange' => '#FF6A2B',
	);
	// Beispiel: 8 mm und 10 mm haben teils andere Farben. Eine Farbe kann in mehreren Paletten stecken.
	$ibr_palettes = array(
		'paracord-beispiel' => array( 'Paracord (Beispielfarben)', array_keys( $ibr_hex ) ),
		'seil-10-mm-beispiel' => array( 'Seil 10 mm (Beispielfarben)', array( 'Sand', 'Beige', 'Weiß', 'Schwarz', 'Oliv', 'Guacamole', 'Tannengrün', 'Hellblau', 'Bordeaux', 'Cognac' ) ),
		'seil-8-mm-beispiel'  => array( 'Seil 8 mm (Beispielfarben)', array( 'Sand', 'Beige', 'Schwarz', 'Oliv', 'Mint', 'Flieder', 'Rosé', 'Rost', 'Neonpink', 'Neonorange' ) ),
	);
	$ibr_color_ids = array();
	$ibr_order     = 0;
	foreach ( $ibr_hex as $ibr_name => $ibr_value ) {
		$ibr_color_ids[ $ibr_name ] = wp_insert_post(
			array( 'post_type' => 'ibr_farbe', 'post_status' => 'publish', 'post_title' => $ibr_name, 'menu_order' => $ibr_order++ )
		);
		update_post_meta( $ibr_color_ids[ $ibr_name ], '_ibr_fw_hex', $ibr_value );
	}
	foreach ( $ibr_palettes as $ibr_slug => list( $ibr_title, $ibr_names ) ) {
		$ibr_term = wp_insert_term( $ibr_title, 'ibr_palette', array( 'slug' => $ibr_slug ) );
		foreach ( $ibr_names as $ibr_name ) {
			wp_set_object_terms( $ibr_color_ids[ $ibr_name ], (int) $ibr_term['term_id'], 'ibr_palette', true );
		}
	}
	ibr_import_log( 'Beispiel-Farbpaletten angelegt (Paracord, Seil 8 mm, Seil 10 mm).' );

	$ibr_palette = static fn( string $slug ) => (int) get_term_by( 'slug', $slug, 'ibr_palette' )->term_id;

	// FRIENDSHIP: eine Palette, Anzahl der Farben aus der Variante „Anzahl Farben“.
	$ibr_id = ibr_import_product_id( 'hundehalsband-friendship' );
	if ( $ibr_id ) {
		update_post_meta( $ibr_id, '_ibr_fw_palette', $ibr_palette( 'paracord-beispiel' ) );
		update_post_meta( $ibr_id, '_ibr_fw_slots', 3 );
		update_post_meta( $ibr_id, '_ibr_fw_slot_attr', 'Anzahl Farben' );
	}

	// LOTTE: Farben und Aufpreis hängen von der Stärke ab.
	$ibr_id = ibr_import_product_id( 'hundeleine-lotte' );
	if ( $ibr_id ) {
		update_post_meta( $ibr_id, '_ibr_fw_slots', 2 );
		update_post_meta( $ibr_id, '_ibr_fw_labels', 'Seilfarbe, Wicklung' );
		foreach ( array( '10 mm' => array( 'seil-10-mm-beispiel', '3.00' ), '8 mm' => array( 'seil-8-mm-beispiel', '2.75' ) ) as $ibr_staerke => list( $ibr_slug, $ibr_rate ) ) {
			foreach ( ibr_import_variations_with( $ibr_id, 'Stärke', $ibr_staerke ) as $ibr_var ) {
				update_post_meta( $ibr_var, '_ibr_fw_palette', $ibr_palette( $ibr_slug ) );
				update_post_meta( $ibr_var, '_ibr_len_surcharge', $ibr_rate );
			}
		}
		ibr_fw_refresh_variation_flag( $ibr_id );

		// Längenwahl: Standard 2,00 m, 2,25 m, 2,50 m oder Wunschlänge; Aufpreis je 25 cm (10 mm 3,00 €, 8 mm 2,75 €).
		update_post_meta( $ibr_id, '_ibr_len_standard', 200 );
		update_post_meta( $ibr_id, '_ibr_len_steps', '225, 250' );
		update_post_meta( $ibr_id, '_ibr_len_custom', 'yes' );
		update_post_meta( $ibr_id, '_ibr_len_min', 100 );
		update_post_meta( $ibr_id, '_ibr_len_max', 500 );
		update_post_meta( $ibr_id, '_ibr_len_surcharge', '3.00' );
		update_post_meta( $ibr_id, '_ibr_len_step_cm', 25 );

		// Kurze Texte auf den Stärke-Karten (Platzhalter, im Produkt unter „Auswahl-Texte“ änderbar).
		update_post_meta( $ibr_id, '_ibr_opt_desc', "8 mm: leicht & fein, für kleine Hunde\n10 mm: kräftig & griffig, für große Hunde" );
	}
}

ibr_import_log( "Success: Import fertig, $ibr_count neue Produkte." );
