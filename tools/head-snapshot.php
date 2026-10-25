<?php
/**
 * Fetch each URL anonymously and record the SEO tags in its rendered <head>.
 *
 *     php tools/head-snapshot.php urls.txt > before.json
 *
 * Plain PHP, no WordPress: it reads the page the way a crawler does, which is
 * the point. What the database says a page should carry is not evidence of what
 * it carries.
 *
 * For each URL it records the HTTP status and, for every tag that matters to
 * search and sharing, every value found - as a list, so a duplicated tag shows
 * up as two entries rather than being silently collapsed into one:
 *
 * - title
 * - meta name=description, name=robots, name=twitter:*
 * - meta property=og:*, article:*, fb:*
 * - link rel=canonical, rel=prev, rel=next
 *
 * Only the <head> is read. A tag in the body is not a head tag, and Avada
 * prints plenty of things in the body that look like one.
 *
 * @package TGD_Site_Kit
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

if ( empty( $argv[1] ) || ! is_readable( $argv[1] ) ) {
	fwrite( STDERR, "usage: php head-snapshot.php <url-list-file>\n" );
	exit( 1 );
}

$urls = array_values( array_filter( array_map( 'trim', file( $argv[1] ) ) ) );
$out  = array();

foreach ( $urls as $url ) {
	$ch = curl_init( $url );
	curl_setopt_array(
		$ch,
		array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_TIMEOUT        => 60,
			CURLOPT_USERAGENT      => 'tgdsk-head-snapshot',
			CURLOPT_HTTPHEADER     => array( 'Cache-Control: no-cache' ),
			// Local's certificate is trusted in the Windows store, not in PHP's bundle.
			CURLOPT_SSL_OPTIONS    => CURLSSLOPT_NATIVE_CA,
		)
	);
	$html   = curl_exec( $ch );
	$status = curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
	curl_close( $ch );

	$out[ $url ] = array(
		'status' => $status,
		'tags'   => false === $html ? array() : head_tags( $html ),
	);
}

echo json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), "\n";

/**
 * Pull the tags we compare out of a document's head.
 *
 * DOMDocument rather than regular expressions, so attribute order and quoting
 * style cannot make the same tag read as two different ones. Values are the
 * decoded attribute text, which is what a crawler reads: `&amp;` and `&#038;`
 * are both `&` once parsed, and comparing raw markup would report that as a
 * difference when nothing a search engine sees has changed.
 *
 * @param string $html Whole document.
 * @return array<string, string[]> Tag key => every value found, in order.
 */
function head_tags( $html ) {
	$end  = stripos( $html, '</head>' );
	$head = false === $end ? $html : substr( $html, 0, $end + 7 );

	$doc = new DOMDocument();
	libxml_use_internal_errors( true );
	$doc->loadHTML( '<?xml encoding="utf-8"?>' . $head . '</html>' );
	libxml_clear_errors();

	$tags = array();
	foreach ( $doc->getElementsByTagName( 'title' ) as $node ) {
		$tags['title'][] = $node->textContent;
	}
	foreach ( $doc->getElementsByTagName( 'meta' ) as $node ) {
		$name     = strtolower( $node->getAttribute( 'name' ) );
		$property = strtolower( $node->getAttribute( 'property' ) );
		$content  = $node->getAttribute( 'content' );
		if ( in_array( $name, array( 'description', 'robots' ), true ) || str_starts_with( $name, 'twitter:' ) ) {
			$tags[ 'meta:' . $name ][] = $content;
		} elseif ( preg_match( '/^(og|article|fb):/', $property ) ) {
			$tags[ 'meta:' . $property ][] = $content;
		}
	}
	foreach ( $doc->getElementsByTagName( 'link' ) as $node ) {
		$rel = strtolower( $node->getAttribute( 'rel' ) );
		if ( in_array( $rel, array( 'canonical', 'prev', 'next' ), true ) ) {
			$tags[ 'link:' . $rel ][] = $node->getAttribute( 'href' );
		}
	}
	ksort( $tags );
	return $tags;
}
