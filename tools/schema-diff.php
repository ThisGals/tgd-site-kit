<?php
/**
 * Compare two schema snapshots, node by node, and validate the second.
 *
 *     php tools/schema-diff.php before.json after.json [reasons.json] > report.md
 *
 * For every URL:
 *
 * 1. **Validation of the second snapshot.** Every JSON-LD block parses; there
 *    is exactly one `@graph`; every `{"@id": …}` reference inside that graph
 *    points at a node defined in it (top level or nested).
 * 2. **The graphs, node by node.** Nodes are matched by `@id`, then by `@type`
 *    for nodes without one. A matched node is compared field by field. A node
 *    only in the first snapshot is *gone*; only in the second, *new*.
 * 3. **The other blocks** (a theme's breadcrumbs, a page builder's FAQ block)
 *    must be the same in both, since neither plugin under test prints them.
 *
 * reasons.json is optional: `{"gone": {"<@type>": "why"}, "new": {...},
 * "changed": {"<@type>.<field>": "why"}, "same_as": {"<before @type>": "<after
 * @type>"}}`. `same_as` pairs nodes whose `@id` and type both changed between
 * the plugins (an Organization replaced by a LocalBusiness). Anything without a
 * reason is listed as unexplained, and the exit status is 1 when there is any.
 *
 * @package TGD_Site_Kit
 */

if ( PHP_SAPI !== 'cli' || empty( $argv[2] ) ) {
	fwrite( STDERR, "usage: php schema-diff.php <before.json> <after.json> [reasons.json]\n" );
	exit( 1 );
}

$before  = json_decode( file_get_contents( $argv[1] ), true );
$after   = json_decode( file_get_contents( $argv[2] ), true );
$reasons = isset( $argv[3] ) ? json_decode( file_get_contents( $argv[3] ), true ) : array();
$reasons = array_merge(
	array(
		'gone'    => array(),
		'new'     => array(),
		'changed' => array(),
		'same_as' => array(),
	),
	is_array( $reasons ) ? $reasons : array()
);

$sections    = array();
$unexplained = 0;
$invalid     = 0;
$tally       = array(
	'gone'    => array(),
	'new'     => array(),
	'changed' => array(),
);

foreach ( $before as $url => $page ) {
	$lines   = array();
	$a_page  = $after[ $url ] ?? array( 'status' => 0, 'blocks' => array() );
	$a_graph = graph_nodes( $a_page['blocks'] );
	$b_graph = graph_nodes( $page['blocks'] );

	foreach ( validate( $a_page['blocks'] ) as $problem ) {
		$lines[] = "- **Invalid:** {$problem}";
		++$invalid;
	}

	$matched = array();
	foreach ( $b_graph as $bi => $node ) {
		$ai = match_node( $node, $a_graph, $matched, $reasons['same_as'] );
		if ( null === $ai ) {
			$why     = $reasons['gone'][ type_key( $node ) ] ?? null;
			$lines[] = sprintf( '- Gone: `%s`%s — %s', type_key( $node ), id_note( $node ), $why ?? '**unexplained**' );
			$unexplained += null === $why ? 1 : 0;
			$tally['gone'][ type_key( $node ) ] = ( $tally['gone'][ type_key( $node ) ] ?? 0 ) + 1;
			continue;
		}
		$matched[] = $ai;
		$diffs     = field_diff( $node, $a_graph[ $ai ] );
		if ( type_key( $node ) !== type_key( $a_graph[ $ai ] ) ) {
			array_unshift( $diffs, array( '@type', type_key( $node ), type_key( $a_graph[ $ai ] ) ) );
		}
		foreach ( $diffs as $d ) {
			$key     = type_key( $node ) . '.' . $d[0];
			$why     = $reasons['changed'][ $key ] ?? $reasons['changed'][ type_key( $node ) . '.' . strtok( $d[0], '.[' ) ] ?? null;
			$lines[] = sprintf( '- Changed `%s` `%s`: %s → %s — %s', type_key( $node ), $d[0], short( $d[1] ), short( $d[2] ), $why ?? '**unexplained**' );
			$unexplained += null === $why ? 1 : 0;
			$tally['changed'][ $key ] = ( $tally['changed'][ $key ] ?? 0 ) + 1;
		}
	}
	foreach ( $a_graph as $ai => $node ) {
		if ( in_array( $ai, $matched, true ) ) {
			continue;
		}
		$why     = $reasons['new'][ type_key( $node ) ] ?? null;
		$lines[] = sprintf( '- New: `%s`%s — %s', type_key( $node ), id_note( $node ), $why ?? '**unexplained**' );
		$unexplained += null === $why ? 1 : 0;
		$tally['new'][ type_key( $node ) ] = ( $tally['new'][ type_key( $node ) ] ?? 0 ) + 1;
	}

	$b_other = other_blocks( $page['blocks'] );
	$a_other = other_blocks( $a_page['blocks'] );
	if ( $b_other !== $a_other ) {
		$lines[] = sprintf( '- **Other JSON-LD blocks differ:** %d before, %d after', count( $b_other ), count( $a_other ) );
		++$unexplained;
	}

	if ( $lines ) {
		$sections[ $url ] = $lines;
	}
}

echo "# Schema diff\n\n";
printf( "- Before: `%s`\n- After: `%s`\n- URLs: %d\n- Validation problems in the second snapshot: %d\n- Unexplained differences: %d\n\n", basename( $argv[1] ), basename( $argv[2] ), count( $before ), $invalid, $unexplained );
echo "## Summary\n\n| Kind | Node or field | Pages | Reason |\n|---|---|---|---|\n";
foreach ( array( 'gone', 'new', 'changed' ) as $kind ) {
	ksort( $tally[ $kind ] );
	foreach ( $tally[ $kind ] as $key => $count ) {
		$why = $reasons[ $kind ][ $key ] ?? ( 'changed' === $kind ? ( $reasons['changed'][ strtok( $key, '.' ) . '.' . strtok( substr( $key, strpos( $key, '.' ) + 1 ), '.[' ) ] ?? null ) : null );
		printf( "| %s | `%s` | %d | %s |\n", $kind, $key, $count, $why ?? '**unexplained**' );
	}
}
echo "\n## By URL\n\n";
foreach ( $sections as $url => $lines ) {
	echo "### {$url}\n\n", implode( "\n", $lines ), "\n\n";
}
if ( ! $sections ) {
	echo "No differences, and every page's JSON-LD is valid.\n";
}
exit( $unexplained || $invalid ? 1 : 0 );

/**
 * The nodes of the page's `@graph` blocks, pooled. A hand-written site often
 * prints one sitewide graph and one per page; pooling them lets that be
 * compared with a single graph. Validation still flags more than one.
 *
 * @param array $blocks Snapshot blocks.
 * @return array[]
 */
function graph_nodes( $blocks ) {
	$nodes = array();
	foreach ( $blocks as $block ) {
		if ( $block['graph'] ) {
			$nodes = array_merge( $nodes, array_column( $block['nodes'], 'node' ) );
		}
	}
	return $nodes;
}

/**
 * Every non-graph block, canonicalised, for an unordered comparison.
 *
 * @param array $blocks Snapshot blocks.
 * @return string[]
 */
function other_blocks( $blocks ) {
	$out = array();
	foreach ( $blocks as $block ) {
		if ( ! $block['graph'] ) {
			$out[] = json_encode( canonical( array_column( $block['nodes'], 'node' ) ) );
		}
	}
	sort( $out );
	return $out;
}

/**
 * Validation problems in one page's blocks.
 *
 * @param array $blocks Snapshot blocks.
 * @return string[]
 */
function validate( $blocks ) {
	$problems = array();
	$graphs   = 0;
	foreach ( $blocks as $block ) {
		if ( ! $block['valid'] ) {
			$problems[] = 'a JSON-LD block does not parse';
		}
		$graphs += $block['graph'] ? 1 : 0;
	}
	if ( $blocks && 1 !== $graphs ) {
		$problems[] = "{$graphs} `@graph` blocks, not one";
	}
	$nodes   = graph_nodes( $blocks );
	$defined = array();
	$refs    = array();
	walk_ids( $nodes, $defined, $refs );
	foreach ( array_unique( array_diff( $refs, $defined ) ) as $missing ) {
		$problems[] = "`@id` {$missing} is referenced but not defined";
	}
	return $problems;
}

/**
 * Collect defined `@id`s (on objects with more than an `@id`) and references
 * (objects that are nothing but an `@id`).
 *
 * @param mixed $value   Value.
 * @param array $defined Defined ids, by reference.
 * @param array $refs    Referenced ids, by reference.
 */
function walk_ids( $value, array &$defined, array &$refs ) {
	if ( ! is_array( $value ) ) {
		return;
	}
	if ( isset( $value['@id'] ) && is_string( $value['@id'] ) ) {
		if ( 1 === count( $value ) ) {
			$refs[] = $value['@id'];
		} else {
			$defined[] = $value['@id'];
		}
	}
	foreach ( $value as $child ) {
		walk_ids( $child, $defined, $refs );
	}
}

/**
 * Find the matching node in the other graph: same `@id`, else a paired type.
 *
 * @param array $node    Node from the first graph.
 * @param array $others  Nodes of the second graph.
 * @param array $taken   Indexes already matched.
 * @param array $same_as Before type => after type pairings.
 * @return int|null
 */
function match_node( $node, $others, $taken, $same_as ) {
	foreach ( $others as $i => $other ) {
		if ( ! in_array( $i, $taken, true ) && isset( $node['@id'], $other['@id'] ) && $node['@id'] === $other['@id'] ) {
			return $i;
		}
	}
	$want = array( type_key( $node ) );
	if ( isset( $same_as[ type_key( $node ) ] ) ) {
		$want = array_merge( $want, (array) $same_as[ type_key( $node ) ] );
	}
	foreach ( $others as $i => $other ) {
		if ( ! in_array( $i, $taken, true ) && in_array( type_key( $other ), $want, true ) ) {
			return $i;
		}
	}
	return null;
}

/**
 * Field-level differences between two nodes, as path, before, after.
 *
 * @param mixed  $a    First.
 * @param mixed  $b    Second.
 * @param string $path Path so far.
 * @return array[]
 */
function field_diff( $a, $b, $path = '' ) {
	if ( is_array( $a ) && is_array( $b ) && ! array_is_list( $a ) && ! array_is_list( $b ) ) {
		$out = array();
		foreach ( array_unique( array_merge( array_keys( $a ), array_keys( $b ) ) ) as $key ) {
			if ( '' === $path && '@type' === $key ) {
				continue;
			}
			$sub = '' === $path ? (string) $key : "{$path}.{$key}";
			if ( ! array_key_exists( $key, $a ) || ! array_key_exists( $key, $b ) ) {
				$out[] = array( $sub, $a[ $key ] ?? null, $b[ $key ] ?? null );
			} else {
				$out = array_merge( $out, field_diff( $a[ $key ], $b[ $key ], $sub ) );
			}
		}
		return $out;
	}
	return canonical( $a ) === canonical( $b ) ? array() : array( array( '' === $path ? '(node)' : $path, $a, $b ) );
}

/**
 * A value with every object's keys sorted, for order-free comparison.
 *
 * @param mixed $value Value.
 * @return mixed
 */
function canonical( $value ) {
	if ( ! is_array( $value ) ) {
		return $value;
	}
	$out = array_map( 'canonical', $value );
	if ( ! array_is_list( $out ) ) {
		ksort( $out );
	}
	return $out;
}

/**
 * A node's type as one string.
 *
 * @param array $node Node.
 * @return string
 */
function type_key( $node ) {
	return implode( '+', (array) ( $node['@type'] ?? '?' ) );
}

/**
 * The node's id, for display.
 *
 * @param array $node Node.
 * @return string
 */
function id_note( $node ) {
	return isset( $node['@id'] ) ? ' (`' . $node['@id'] . '`)' : '';
}

/**
 * A value shortened for a report line.
 *
 * @param mixed $value Value.
 * @return string
 */
function short( $value ) {
	if ( null === $value ) {
		return '*(absent)*';
	}
	$text = is_scalar( $value ) ? (string) $value : json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	return '`' . ( strlen( $text ) > 90 ? substr( $text, 0, 87 ) . '...' : $text ) . '`';
}
