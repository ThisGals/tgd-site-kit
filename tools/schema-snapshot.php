<?php
/**
 * Fetch each URL anonymously and record every JSON-LD block on the page.
 *
 *     php tools/schema-snapshot.php urls.txt > schema-before.json
 *
 * Plain PHP, no WordPress, for the same reason as head-snapshot.php: what a
 * crawler reads is the evidence, not what the database says.
 *
 * The whole page is read, not only the head: a theme's code box, a page
 * builder block or a footer can all print structured data, and Google reads it
 * wherever it is. For each URL it records the HTTP status and every
 * `application/ld+json` block in order, each as:
 *
 * - `raw`: the block's text, so a parse failure can be seen;
 * - `valid`: whether it parsed as JSON;
 * - `graph`: true when it is an object with an `@graph` array;
 * - `nodes`: the top-level nodes (the `@graph` members, or the object itself,
 *   or each member of a top-level array), each with its `@type` and `@id`.
 *
 * @package TGD_Site_Kit
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

if ( empty( $argv[1] ) || ! is_readable( $argv[1] ) ) {
	fwrite( STDERR, "usage: php schema-snapshot.php <url-list-file>\n" );
	exit( 1 );
}

$out = array();
foreach ( array_values( array_filter( array_map( 'trim', file( $argv[1] ) ) ) ) as $url ) {
	$ch = curl_init( $url );
	curl_setopt_array(
		$ch,
		array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_TIMEOUT        => 60,
			CURLOPT_USERAGENT      => 'tgdsk-schema-snapshot',
			CURLOPT_HTTPHEADER     => array( 'Cache-Control: no-cache' ),
			CURLOPT_SSL_OPTIONS    => CURLSSLOPT_NATIVE_CA,
		)
	);
	$html   = curl_exec( $ch );
	$status = curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
	curl_close( $ch );

	$blocks = array();
	if ( is_string( $html ) && preg_match_all( '#<script\b[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $m ) ) {
		foreach ( $m[1] as $raw ) {
			$blocks[] = describe_block( $raw );
		}
	}
	$out[ $url ] = array(
		'status' => $status,
		'blocks' => $blocks,
	);
}

echo json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), "\n";

/**
 * Parse one JSON-LD block and list its top-level nodes.
 *
 * @param string $raw Block text.
 * @return array
 */
function describe_block( $raw ) {
	$data  = json_decode( trim( $raw ), true );
	$valid = JSON_ERROR_NONE === json_last_error() && is_array( $data );
	$graph = $valid && isset( $data['@graph'] ) && is_array( $data['@graph'] );
	$nodes = array();
	if ( $graph ) {
		$nodes = $data['@graph'];
	} elseif ( $valid && array_is_list( $data ) ) {
		$nodes = $data;
	} elseif ( $valid ) {
		$nodes = array( $data );
	}
	$listed = array();
	foreach ( $nodes as $node ) {
		if ( ! is_array( $node ) ) {
			continue;
		}
		$listed[] = array(
			'type' => $node['@type'] ?? null,
			'id'   => $node['@id'] ?? null,
			'node' => $node,
		);
	}
	return array(
		'raw'   => $valid ? null : $raw,
		'valid' => $valid,
		'graph' => $graph,
		'nodes' => $listed,
	);
}
