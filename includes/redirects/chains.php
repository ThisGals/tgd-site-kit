<?php
/**
 * Redirect chains and loops among the site's own redirects.
 *
 * A chain is a redirect whose target is itself redirected: A → B, B → C sends
 * a visitor through two hops where one would do. A loop is a chain that comes
 * back to where it started; the save check refuses the loops it can see (see
 * leads_back()), but one can still form through later edits or a regex.
 *
 * Each hop is found the way a request is matched (exact before regex, ID
 * order, the same per-redirect rules), by asking exact_target() and
 * regex_target() what they would answer for the target as a request with no
 * query. Only internal targets are followed. **Regex rules whose target uses
 * a backreference (`$1`, `\1`) are left out**, as a start and as a hop: where
 * they send a visitor depends on the request, and the Redirects tab says how
 * many there are.
 *
 * Fixing a chain points every redirect in it except the last straight at the
 * end; nothing else is rewritten. A chain that ends at a 410 or in a loop has
 * no fix: what it should do is a person's decision.
 *
 * @package TGD_Site_Kit
 */

namespace TGD_Site_Kit\Redirects;

defined( 'ABSPATH' ) || exit;

/**
 * Whether a regex rule's target uses a backreference.
 *
 * @param array $row Shaped row.
 * @return bool
 */
function has_backreference( array $row ) {
	return ! empty( $row['is_regex'] ) && (bool) preg_match( '/\$\d|\$\{\d+\}|\\\\\d/', (string) $row['target'] );
}

/**
 * The redirects chain detection uses, in matching order: exact ones by ID,
 * then regex ones without a backreference by ID. Enabled only.
 *
 * @return array{exact: array<string, array[]>, regex: array[], left_out: int}
 */
function chain_rows() {
	$out = array(
		'exact'    => array(),
		'regex'    => array(),
		'left_out' => 0,
	);
	foreach ( all( 'ASC' ) as $row ) {
		if ( ! $row['enabled'] ) {
			continue;
		}
		if ( $row['is_regex'] ) {
			if ( has_backreference( $row ) ) {
				++$out['left_out'];
			} else {
				$out['regex'][] = $row;
			}
			continue;
		}
		$out['exact'][ match_key( split_url( $row['source'] )['path'] ) ][] = $row;
	}
	return $out;
}

/**
 * The redirect a target would hit as a request, and where it sends it.
 *
 * @param string $target Target URL or path.
 * @param array  $rows   From chain_rows().
 * @return array{row: array, location: string}|null Null when the target is
 *         external or nothing redirects it.
 */
function next_hop( $target, array $rows ) {
	$to = split_url( $target );
	if ( '' === (string) $target || ! is_own_host( $to['host'] ) ) {
		return null;
	}
	$request = array(
		'path'  => $to['path'],
		'query' => $to['query'],
	);
	foreach ( $rows['exact'][ match_key( $to['path'] ) ] ?? array() as $row ) {
		$location = exact_target( $row, $request );
		if ( null !== $location ) {
			return array(
				'row'      => $row,
				'location' => $location,
			);
		}
	}
	foreach ( $rows['regex'] as $row ) {
		$location = regex_target( $row, $request );
		if ( null !== $location ) {
			return array(
				'row'      => $row,
				'location' => $location,
			);
		}
	}
	return null;
}

/**
 * Every chain and loop.
 *
 * A chain is listed once, from its first redirect: A → B → C → D appears as
 * one chain of three redirects, not also as B → C → D.
 *
 * @return array{chains: array[], loops: array[], left_out: int}
 *         Each chain: {start: int, ids: int[], hops: string[], end: string,
 *         ends: `target`, `gone` or `loop`}. hops are the sources in order.
 */
function find_chains() {
	$rows   = chain_rows();
	$starts = array_merge( array_merge( array(), ...array_values( $rows['exact'] ) ), $rows['regex'] );
	$chains = array();
	$loops  = array();
	foreach ( $starts as $start ) {
		if ( 410 === (int) $start['status'] ) {
			continue;
		}
		$members = array( $start );
		$seen    = array( (int) $start['id'] => 0 );
		$end     = (string) $start['target'];
		$ends    = 'target';
		$cycle   = array();
		for ( $i = 0; $i < 20; $i++ ) {
			$hop = next_hop( $end, $rows );
			if ( ! $hop ) {
				break;
			}
			$id = (int) $hop['row']['id'];
			if ( isset( $seen[ $id ] ) ) {
				$ends  = 'loop';
				$cycle = array_map( fn( $r ) => (int) $r['id'], array_slice( $members, $seen[ $id ] ) );
				break;
			}
			$seen[ $id ] = count( $members );
			$members[]   = $hop['row'];
			if ( 410 === (int) $hop['row']['status'] ) {
				$ends = 'gone';
				break;
			}
			$end = $hop['location'];
		}
		if ( count( $members ) < 2 && 'loop' !== $ends ) {
			continue;
		}
		$chain = array(
			'start' => (int) $start['id'],
			'ids'   => array_map( fn( $r ) => (int) $r['id'], $members ),
			'hops'  => array_map( fn( $r ) => (string) $r['source'], $members ),
			'end'   => 'target' === $ends ? $end : '',
			'ends'  => $ends,
		);
		if ( 'loop' === $ends ) {
			// One entry per loop, however many redirects lead into it.
			sort( $cycle );
			$key = implode( ',', $cycle );
			if ( ! isset( $loops[ $key ] ) || count( $loops[ $key ]['ids'] ) > count( $chain['ids'] ) ) {
				$loops[ $key ] = $chain;
			}
			continue;
		}
		$chains[ $chain['start'] ] = $chain;
	}
	// Leave out a chain whose first redirect is part of a longer one.
	$inside = array();
	foreach ( $chains as $chain ) {
		foreach ( array_slice( $chain['ids'], 1 ) as $id ) {
			$inside[ $id ] = true;
		}
	}
	$chains = array_filter( $chains, fn( $chain ) => ! isset( $inside[ $chain['start'] ] ) );
	return array(
		'chains'   => array_values( $chains ),
		'loops'    => array_values( $loops ),
		'left_out' => $rows['left_out'],
	);
}

/**
 * Point every redirect of one chain except the last straight at its end.
 *
 * The chain is worked out again from what is stored now, so a stale screen
 * can't rewrite the wrong redirects. Each rewrite goes through validate().
 *
 * @param int $start ID of the chain's first redirect.
 * @return array{fixed: int[], refused: string[]}
 */
function fix_chain( $start ) {
	$out = array(
		'fixed'   => array(),
		'refused' => array(),
	);
	foreach ( find_chains()['chains'] as $chain ) {
		if ( (int) $chain['start'] !== (int) $start || 'target' !== $chain['ends'] ) {
			continue;
		}
		foreach ( array_slice( $chain['ids'], 0, -1 ) as $id ) {
			$row = get( $id );
			if ( ! $row || $row['target'] === $chain['end'] ) {
				continue;
			}
			$clean = validate( array( 'target' => $chain['end'] ) + $row, $id );
			if ( is_wp_error( $clean ) ) {
				/* translators: 1: redirect ID, 2: reason */
				$out['refused'][] = sprintf( __( 'Redirect %1$d: %2$s', 'tgd-site-kit' ), $id, $clean->get_error_message() );
				continue;
			}
			update( $id, $clean );
			$out['fixed'][] = (int) $id;
		}
	}
	return $out;
}

/**
 * Fix every chain that has a fix.
 *
 * @return array{fixed: int[], refused: string[]}
 */
function fix_all_chains() {
	$out = array(
		'fixed'   => array(),
		'refused' => array(),
	);
	foreach ( find_chains()['chains'] as $chain ) {
		if ( 'target' !== $chain['ends'] ) {
			continue;
		}
		$one            = fix_chain( $chain['start'] );
		$out['fixed']   = array_merge( $out['fixed'], $one['fixed'] );
		$out['refused'] = array_merge( $out['refused'], $one['refused'] );
	}
	return $out;
}
