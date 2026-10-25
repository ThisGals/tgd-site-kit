<?php
/**
 * Compare two head snapshots and write a Markdown report.
 *
 *     php tools/head-diff.php before.json after.json > report.md
 *
 * Two questions, answered separately because they fail separately:
 *
 * 1. Did any tag's value change between the two? Every URL, every tag key.
 * 2. Does any page in the second snapshot carry a tag more than once? Two
 *    titles or two canonicals is the duplicate-tag problem this whole switch
 *    exists to avoid, and it can be true even when every value matches -
 *    which is exactly what two SEO plugins printing the same thing looks like.
 *
 * `og:image` and friends repeat legitimately, once per image, so for the og
 * image keys the count check compares against the first snapshot instead of
 * insisting on one.
 *
 * Exit status is 0 when both checks are clean and 1 otherwise, so a shell can
 * act on it - but read the report, not the status.
 *
 * @package TGD_Site_Kit
 */

if ( PHP_SAPI !== 'cli' || empty( $argv[2] ) ) {
	fwrite( STDERR, "usage: php head-diff.php <before.json> <after.json>\n" );
	exit( 1 );
}

$before = json_decode( file_get_contents( $argv[1] ), true );
$after  = json_decode( file_get_contents( $argv[2] ), true );

$repeatable = array( 'meta:og:image', 'meta:og:image:secure_url', 'meta:og:image:width', 'meta:og:image:height' );
$changes    = array();
$dupes      = array();
$missing    = array();

foreach ( $before as $url => $page ) {
	if ( ! isset( $after[ $url ] ) ) {
		$missing[] = $url;
		continue;
	}
	$a = $page['tags'];
	$b = $after[ $url ]['tags'];

	if ( $page['status'] !== $after[ $url ]['status'] ) {
		$changes[ $url ][] = array( 'HTTP status', (string) $page['status'], (string) $after[ $url ]['status'] );
	}

	foreach ( array_unique( array_merge( array_keys( $a ), array_keys( $b ) ) ) as $key ) {
		$va = $a[ $key ] ?? array();
		$vb = $b[ $key ] ?? array();
		if ( $va !== $vb ) {
			$changes[ $url ][] = array( $key, implode( ' ¦ ', $va ), implode( ' ¦ ', $vb ) );
		}
		$limit = in_array( $key, $repeatable, true ) ? max( 1, count( $va ) ) : 1;
		if ( count( $vb ) > $limit ) {
			$dupes[ $url ][] = sprintf( '%s × %d', $key, count( $vb ) );
		}
	}
}

$pages = count( $before );
echo "# Head diff\n\n";
printf( "- Before: `%s`\n- After: `%s`\n- URLs compared: %d\n", basename( $argv[1] ), basename( $argv[2] ), $pages );
printf( "- URLs with a changed tag: %d\n- URLs with a duplicated tag: %d\n", count( $changes ), count( $dupes ) );
if ( $missing ) {
	printf( "- URLs missing from the second snapshot: %d\n", count( $missing ) );
}
echo "\n";

if ( $changes ) {
	echo "## Changed values\n\n";
	foreach ( $changes as $url => $rows ) {
		echo "### {$url}\n\n| Tag | Before | After |\n|---|---|---|\n";
		foreach ( $rows as $row ) {
			$cells = array_map( fn( $v ) => '' === $v ? '*(none)*' : str_replace( '|', '\\|', $v ), $row );
			echo '| ', implode( ' | ', $cells ), " |\n";
		}
		echo "\n";
	}
}

if ( $dupes ) {
	echo "## Duplicated tags\n\n";
	foreach ( $dupes as $url => $rows ) {
		echo "- {$url}: ", implode( ', ', $rows ), "\n";
	}
	echo "\n";
}

if ( $missing ) {
	echo "## Missing\n\n";
	foreach ( $missing as $url ) {
		echo "- {$url}\n";
	}
	echo "\n";
}

if ( ! $changes && ! $dupes && ! $missing ) {
	echo "No differences, and no page carries any tag twice.\n";
}

exit( $changes || $dupes || $missing ? 1 : 0 );
