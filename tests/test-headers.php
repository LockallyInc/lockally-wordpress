<?php
/**
 * Tests for the header parsing in Mailer, which is the part of wp_mail that
 * plugins most often get wrong: anything missed here is a header the message
 * silently loses.
 *
 * Runs on plain PHP, with no WordPress: `php tests/test-headers.php`.
 *
 * @package LockallyMail
 */

// The class file guards on ABSPATH, which is how WordPress stops a plugin file
// being loaded directly over the web. Defining it is how the file is loaded
// outside WordPress.
define( 'ABSPATH', __DIR__ );

// Only the pieces the tested methods touch. They deliberately use no
// WordPress functions, so nothing else has to be faked.
require_once __DIR__ . '/../includes/class-mailer.php';

use Lockally\Mail\Mailer;

$failures = 0;
$checks   = 0;

/**
 * @param string $what     What is being asserted.
 * @param mixed  $expected Expected value.
 * @param mixed  $actual   Actual value.
 */
function check( $what, $expected, $actual ) {
	global $failures, $checks;
	++$checks;
	if ( $expected === $actual ) {
		echo "  ok   $what\n";
		return;
	}
	++$failures;
	echo "  FAIL $what\n";
	echo '       expected: ' . var_export( $expected, true ) . "\n";
	echo '       actual:   ' . var_export( $actual, true ) . "\n";
}

echo "headers as one string, the way wp_mail is most often called\n";
$parsed = Mailer::parse_headers(
	"From: Acme Store <shop@acme.test>\r\n" .
	"Cc: manager@acme.test\r\n" .
	"Bcc: records@acme.test\r\n" .
	"Reply-To: Support <help@acme.test>\r\n" .
	"Content-Type: text/html; charset=UTF-8\r\n" .
	"X-Order-Id: 1042\r\n"
);
check( 'the From address is found', 'shop@acme.test', $parsed['from_email'] );
check( 'the From display name is found', 'Acme Store', $parsed['from_name'] );
check( 'Cc is a list', array( 'manager@acme.test' ), $parsed['cc'] );
check( 'Bcc is a list', array( 'records@acme.test' ), $parsed['bcc'] );
check( 'the content type drops the charset', 'text/html', $parsed['content_type'] );
check( 'Reply-To is passed on', 'Support <help@acme.test>', $parsed['headers']['Reply-To'] );
check( "a site's own header is passed on", '1042', $parsed['headers']['X-Order-Id'] );

echo "headers as an array, which WooCommerce and others use\n";
$parsed = Mailer::parse_headers(
	array(
		'Content-Type: text/plain',
		'Cc: one@acme.test, Two <two@acme.test>',
	)
);
check( 'plain text stays plain text', 'text/plain', $parsed['content_type'] );
check(
	'several Cc addresses in one header are separated',
	array( 'one@acme.test', 'Two <two@acme.test>' ),
	$parsed['cc']
);

echo "the awkward cases\n";
$parsed = Mailer::parse_headers( '' );
check( 'no headers still gives a content type', 'text/plain', $parsed['content_type'] );
check( 'no headers gives no From', '', $parsed['from_email'] );

$parsed = Mailer::parse_headers( "nonsense without a colon\nFrom: plain@acme.test" );
check( 'a line that is not a header is skipped', 'plain@acme.test', $parsed['from_email'] );
check( 'an address with no display name has none', '', $parsed['from_name'] );

// These are set from the message itself. Letting a caller override them here
// would either duplicate or contradict what actually goes out.
$parsed = Mailer::parse_headers( "To: someone@acme.test\nSubject: Hello\nMIME-Version: 1.0" );
check( 'To, Subject and MIME-Version are not passed through', array(), $parsed['headers'] );

echo "addresses, however wp_mail was called\n";
check( 'one address', array( 'a@acme.test' ), Mailer::addresses( 'a@acme.test' ) );
check( 'several in one string', array( 'a@acme.test', 'b@acme.test' ), Mailer::addresses( 'a@acme.test, b@acme.test' ) );
check( 'a list with a blank in it', array( 'a@acme.test' ), Mailer::addresses( array( 'a@acme.test', '  ' ) ) );

echo "display names\n";
$split = Mailer::split_address( '"Acme, Inc" <shop@acme.test>' );
check( 'quotes around a name are dropped', 'Acme, Inc', $split['name'] );
check( 'the address is taken from the angle brackets', 'shop@acme.test', $split['email'] );

echo "\n$checks checks, $failures failed\n";
exit( $failures > 0 ? 1 : 0 );
