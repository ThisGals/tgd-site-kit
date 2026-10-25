<?php
/**
 * Build a release: the plugin zip and its SHA-256 file.
 *
 *     php tools/build-release.php v0.2.0
 *
 * Run from the repository root, on a clean working tree, after bumping the
 * version in both places in tgd-site-kit.php (the `Version:` header and the
 * VERSION constant). It refuses to build unless:
 *
 * - the working tree has no uncommitted changes, so the zip is exactly a commit;
 * - the header version, the VERSION constant and the tag all agree;
 * - the tag, if it already exists, points at HEAD.
 *
 * The zip is `git archive` of HEAD with a `tgd-site-kit/` prefix, so it holds
 * only tracked files and honours .gitattributes: vendor/ is untracked, and
 * tools/, tests/, docs/, composer and phpcs files and the notes are
 * export-ignored. The script then opens the zip and refuses it if any of those
 * slipped in, or if the main plugin file is missing.
 *
 * Output goes to build/ (gitignored): tgd-site-kit.zip and
 * tgd-site-kit.zip.sha256, the two assets the updater looks for by name.
 * The last thing it prints is the `gh release create` command to publish them.
 *
 * Plain PHP and git; no Node, no Composer at build time.
 *
 * @package TGD_Site_Kit
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

/**
 * Print an error and stop.
 *
 * @param string $message Why.
 */
function fail( $message ) {
	fwrite( STDERR, "build-release: {$message}\n" );
	exit( 1 );
}

/**
 * Run a git command in the repo and return trimmed output.
 *
 * @param string $args Arguments, already escaped.
 * @param int    $code Exit code, by reference.
 * @return string
 */
function git( $args, &$code = 0 ) {
	$out = array();
	exec( 'git ' . $args . ' 2>&1', $out, $code );
	return trim( implode( "\n", $out ) );
}

$root = dirname( __DIR__ );
chdir( $root );

$tag = $argv[1] ?? '';
if ( ! preg_match( '/^v(\d+\.\d+\.\d+)$/', $tag, $m ) ) {
	fail( 'usage: php tools/build-release.php vX.Y.Z' );
}
$version = $m[1];

if ( '' !== git( 'status --porcelain' ) ) {
	fail( 'the working tree has uncommitted changes; commit or stash them first' );
}

$main = file_get_contents( $root . '/tgd-site-kit.php' );
preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', $main, $header );
preg_match( "/^const VERSION = '([^']+)';/m", $main, $constant );
if ( ( $header[1] ?? '' ) !== $version || ( $constant[1] ?? '' ) !== $version ) {
	fail( sprintf( 'tag says %s, header says %s, VERSION says %s; they must all match', $version, $header[1] ?? '?', $constant[1] ?? '?' ) );
}

$head      = git( 'rev-parse HEAD' );
$tag_ref   = git( 'rev-parse --verify --quiet ' . escapeshellarg( $tag . '^{commit}' ), $tag_code );
$tag_state = 0 === $tag_code ? 'exists' : 'new';
if ( 'exists' === $tag_state && $tag_ref !== $head ) {
	fail( "tag {$tag} exists but points at {$tag_ref}, not HEAD {$head}" );
}

$build = $root . '/build';
if ( ! is_dir( $build ) && ! mkdir( $build ) ) {
	fail( 'could not create build/' );
}
$zip_path = $build . '/tgd-site-kit.zip';
$sha_path = $zip_path . '.sha256';
foreach ( array( $zip_path, $sha_path ) as $old ) {
	if ( file_exists( $old ) ) {
		unlink( $old );
	}
}

git( 'archive --format=zip --prefix=tgd-site-kit/ -o ' . escapeshellarg( $zip_path ) . ' HEAD', $code );
if ( 0 !== $code || ! is_file( $zip_path ) ) {
	fail( 'git archive failed' );
}

$zip = new ZipArchive();
if ( true !== $zip->open( $zip_path ) ) {
	fail( 'could not open the built zip' );
}
$names = array();
for ( $i = 0; $i < $zip->numFiles; $i++ ) {
	$names[] = $zip->getNameIndex( $i );
}
$zip->close();
$forbidden = '#^tgd-site-kit/(vendor|tests|docs|tools|build|\.git)/|^tgd-site-kit/(CLAUDE(\.local)?\.md|composer\.(json|lock)|phpcs\.xml\.dist|\.gitattributes|\.gitignore)$#';
$bad       = preg_grep( $forbidden, $names );
if ( $bad ) {
	fail( "the zip contains files it must not:\n  " . implode( "\n  ", $bad ) );
}
if ( ! in_array( 'tgd-site-kit/tgd-site-kit.php', $names, true ) || preg_grep( '#^(?!tgd-site-kit/)#', $names ) ) {
	fail( 'the zip must hold everything under tgd-site-kit/, including tgd-site-kit/tgd-site-kit.php' );
}

$hash = hash_file( 'sha256', $zip_path );
file_put_contents( $sha_path, $hash . '  tgd-site-kit.zip' . "\n" );

printf( "Built %s (%d files, %d bytes)\nSHA-256 %s\n\n", 'build/tgd-site-kit.zip', count( $names ), filesize( $zip_path ), $hash );
if ( 'new' === $tag_state ) {
	printf( "Tag it and push:\n  git tag %s\n  git push origin main %s\n\n", $tag, $tag );
}
printf( "Publish:\n  gh release create %s build/tgd-site-kit.zip build/tgd-site-kit.zip.sha256 --repo thisgals/tgd-site-kit --title \"%s\" --notes \"...\"\n", $tag, $version );
