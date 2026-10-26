<?php
/**
 * Compare hand-written nested JSON-LD with a graph, entity by entity.
 *
 *     php tools/schema-embed-diff.php live.json kit.json map.json [reasons.json] > report.md
 *
 * A hand-written block often describes one entity with everything nested in
 * it (a ContactPage holding the business holding its areas), where a graph
 * splits the same facts into nodes linked by `@id`. schema-diff.php compares
 * graphs node by node; this compares the entity a block describes with the
 * same entity in the graph after every `{"@id": …}` reference in it has been
 * replaced by the node it points at, so the two are the same shape.
 *
 * map.json says which entity to compare on each URL:
 * `{"<url>": {"live": "<@type of the block's node>", "kit": "<@type or @id in the graph>"}}`.
 *
 * Paths are compared as values, so a field order change is not a difference.
 * `@id` and `@context` are ignored. reasons.json is `{"<path pattern>": "why"}`,
 * where a pattern is a regular expression matched against
 * `<url> <path>`. Anything without a reason is listed as unexplained, and the
 * exit status is 1 when there is any.
 *
 * @package TGD_Site_Kit
 */

if ( PHP_SAPI !== 'cli' || empty( $argv[3] ) ) {
	fwrite( STDERR, "usage: php schema-embed-diff.php <live.json> <kit.json> <map.json> [reasons.json]\n" );
	exit( 1 );
}

$live    = json_decode( file_get_contents( $argv[1] ), true );
$kit     = json_decode( file_get_contents( $argv[2] ), true );
$map     = json_decode( file_get_contents( $argv[3] ), true );
$reasons = isset( $argv[4] ) ? (array) json_decode( file_get_contents( $argv[4] ), true ) : array();

$unexplained = 0;
$out         = array();
foreach ( $map as $url => $pick ) {
	$a     = find_node( all_nodes( $live[ $url ]['blocks'] ?? array() ), $pick['live'] );
	$graph = all_nodes( $kit[ $url ]['blocks'] ?? array() );
	$b     = find_node( $graph, $pick['kit'] );
	$out[] = "\n## {$url}\n";
	if ( null === $a || null === $b ) {
		$out[] = '- **Missing:** ' . ( null === $a ? 'live ' . $pick['live'] : 'kit ' . $pick['kit'] ) . "\n";
		++$unexplained;
		continue;
	}
	$by_id = array();
	foreach ( $graph as $node ) {
		if ( isset( $node['@id'] ) ) {
			$by_id[ $node['@id'] ] = $node;
		}
	}
	$fa   = flatten( strip( $a ) );
	$fb   = flatten( strip( embed( $b, $by_id, 0 ) ) );
	$n    = 0;
	$seen = array();
	foreach ( array_unique( array_merge( array_keys( $fa ), array_keys( $fb ) ) ) as $path ) {
		$x = $fa[ $path ] ?? null;
		$y = $fb[ $path ] ?? null;
		if ( $x === $y ) {
			continue;
		}
		// A branch only one side has is one difference, reported at its root.
		if ( null === $x || null === $y ) {
			$root = branch_root( $path, null === $x ? $fa : $fb );
			if ( isset( $seen[ $root ] ) ) {
				continue;
			}
			$seen[ $root ] = true;
			if ( $root !== $path ) {
				$x    = null === $x ? null : '{…}';
				$y    = null === $y ? null : '{…}';
				$path = $root;
			}
		}
		++$n;
		$why = reason( $reasons, "{$url} {$path}" );
		if ( null === $why ) {
			++$unexplained;
		}
		$out[] = sprintf( "- `%s`: %s → %s — %s\n", $path, short( $x ), short( $y ), null === $why ? '**unexplained**' : $why );
	}
	if ( ! $n ) {
		$out[] = "- Same.\n";
	}
}

echo "# Entity comparison\n\n- Live: `" . basename( $argv[1] ) . '`' . "\n- Kit: `" . basename( $argv[2] ) . '`' . "\n- Unexplained differences: {$unexplained}\n";
echo implode( '', $out );
exit( $unexplained ? 1 : 0 );

/**
 * Every node of every block: graph members, list members, or the block itself.
 *
 * @param array $blocks Snapshot blocks.
 * @return array[]
 */
function all_nodes( $blocks ) {
	$nodes = array();
	foreach ( $blocks as $block ) {
		$nodes = array_merge( $nodes, array_column( $block['nodes'], 'node' ) );
	}
	return $nodes;
}

/**
 * The first node whose `@id` or `@type` is the given value.
 *
 * @param array[] $nodes Nodes.
 * @param string  $want  `@id` or `@type`.
 * @return array|null
 */
function find_node( $nodes, $want ) {
	foreach ( $nodes as $node ) {
		if ( ( $node['@id'] ?? null ) === $want || in_array( $want, (array) ( $node['@type'] ?? array() ), true ) ) {
			return $node;
		}
	}
	return null;
}

/**
 * Replace each bare `{"@id": …}` reference with the node it points at.
 *
 * @param mixed $value Value.
 * @param array $by_id Graph nodes by `@id`.
 * @param int   $depth Depth, to stop a cycle.
 * @return mixed
 */
function embed( $value, $by_id, $depth ) {
	if ( ! is_array( $value ) ) {
		return $value;
	}
	if ( 1 === count( $value ) && isset( $value['@id'] ) && isset( $by_id[ $value['@id'] ] ) && $depth < 4 ) {
		return embed( $by_id[ $value['@id'] ], $by_id, $depth + 1 );
	}
	foreach ( $value as $k => $v ) {
		$value[ $k ] = embed( $v, $by_id, $depth );
	}
	return $value;
}

/**
 * Drop `@id` and `@context` everywhere.
 *
 * @param mixed $value Value.
 * @return mixed
 */
function strip( $value ) {
	if ( ! is_array( $value ) ) {
		return $value;
	}
	unset( $value['@id'], $value['@context'] );
	return array_map( 'strip', $value );
}

/**
 * Path => scalar.
 *
 * @param mixed  $value  Value.
 * @param string $prefix Path so far.
 * @return array
 */
function flatten( $value, $prefix = '' ) {
	if ( ! is_array( $value ) ) {
		return array( ( '' === $prefix ? '.' : $prefix ) => $value );
	}
	$out = array();
	foreach ( $value as $k => $v ) {
		$out += flatten( $v, '' === $prefix ? (string) $k : $prefix . '.' . $k );
	}
	return $out;
}

/**
 * The shortest prefix of a path that the other side has nothing under.
 *
 * @param string $path  Path.
 * @param array  $other The other side, flattened.
 * @return string
 */
function branch_root( $path, $other ) {
	$parts  = explode( '.', $path );
	$prefix = '';
	foreach ( $parts as $part ) {
		$prefix = '' === $prefix ? $part : $prefix . '.' . $part;
		$found  = false;
		foreach ( array_keys( $other ) as $key ) {
			if ( $key === $prefix || str_starts_with( $key, $prefix . '.' ) ) {
				$found = true;
				break;
			}
		}
		if ( ! $found ) {
			return $prefix;
		}
	}
	return $path;
}

/**
 * The reason for a difference, or null.
 *
 * @param array  $reasons Pattern => why.
 * @param string $subject URL and path.
 * @return string|null
 */
function reason( $reasons, $subject ) {
	foreach ( $reasons as $pattern => $why ) {
		if ( preg_match( '#' . $pattern . '#', $subject ) ) {
			return $why;
		}
	}
	return null;
}

/**
 * A short printable value.
 *
 * @param mixed $value Value.
 * @return string
 */
function short( $value ) {
	if ( null === $value ) {
		return '*(absent)*';
	}
	$text = json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	return '`' . ( strlen( $text ) > 90 ? substr( $text, 0, 87 ) . '...' : $text ) . '`';
}
