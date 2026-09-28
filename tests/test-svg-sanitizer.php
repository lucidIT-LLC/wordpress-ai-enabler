<?php
/**
 * Standalone test for includes/svg-sanitizer.php (task #1013).
 * Run: php tests/test-svg-sanitizer.php   (exit 0 = all pass)
 * Not shipped: lives outside the plugin folder.
 */
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
	private $code; private $msg;
	public function __construct( $code, $msg ) { $this->code = $code; $this->msg = $msg; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->msg; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }
require __DIR__ . '/../wordpress-ai-enabler/includes/svg-sanitizer.php';

$fail = 0;
function check( $label, $ok, $detail = '' ) {
	global $fail;
	if ( ! $ok ) { $fail++; }
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $ok ? '' : "\n      " . $detail );
}

/**
 * A result is safe when it was refused, or when the output (entity-decoded,
 * whitespace-stripped, lowercased) carries no script vector.
 */
function is_safe( $out ) {
	if ( is_wp_error( $out ) ) { return true; }
	$flat = strtolower( preg_replace( '/[\x00-\x20\x7F]+/', '', html_entity_decode( html_entity_decode( $out, ENT_QUOTES | ENT_HTML5 ), ENT_QUOTES | ENT_HTML5 ) ) );
	foreach ( array( '<script', 'javascript:', 'onload=', 'onclick=', 'onerror=', 'onbegin=', '<foreignobject', 'evil.example', '<!entity', 'vbscript:' ) as $bad ) {
		if ( false !== strpos( $flat, $bad ) ) { return false; }
	}
	return (bool) preg_match( '/\son[a-z]+\s*=/i', $out ) === false;
}

$ns = 'xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"';

// Smith's four measured payloads (task #1013, W1).
$payloads = array(
	'P1 script element'              => "<svg $ns><script>alert(1)</script><rect width=\"1\" height=\"1\"/></svg>",
	'P2 svg/onload (no whitespace)'  => "<svg $ns/onload=\"alert(1)\"><rect width=\"1\" height=\"1\"/></svg>",
	'P2b onload well-formed'         => "<svg $ns onload=\"alert(1)\"><rect width=\"1\" height=\"1\"/></svg>",
	'P3 unquoted href=javascript:'   => "<svg $ns><a href=javascript:alert(1)><rect width=\"1\" height=\"1\"/></a></svg>",
	'P3b quoted a href javascript'   => "<svg $ns><a href=\"javascript:alert(1)\"><rect width=\"1\" height=\"1\"/></a></svg>",
	'P4 entity-encoded javascript'   => "<svg $ns><a href=\"&#106;avascript:alert(1)\"><rect width=\"1\" height=\"1\"/></a></svg>",
	'P4b xlink tab-split javascript' => "<svg $ns><a xlink:href=\"java&#9;script:alert(1)\"><rect width=\"1\" height=\"1\"/></a></svg>",
	// Further vectors named in the finding.
	'foreignObject HTML'             => "<svg $ns><foreignObject><div xmlns=\"http://www.w3.org/1999/xhtml\"><img src=\"x\" onerror=\"alert(1)\"/></div></foreignObject></svg>",
	'style url() external'           => "<svg $ns><style>rect{fill:url(http://evil.example/x.svg#a)}</style><rect width=\"1\" height=\"1\"/></svg>",
	'style @import'                  => "<svg $ns><style>@import 'http://evil.example/x.css';</style></svg>",
	'style attr url external'        => "<svg $ns><rect style=\"fill:url(http://evil.example/a)\" width=\"1\" height=\"1\"/></svg>",
	'use external reference'         => "<svg $ns><use href=\"http://evil.example/sprite.svg#a\"/></svg>",
	'XXE entity'                     => "<?xml version=\"1.0\"?><!DOCTYPE svg [<!ENTITY x SYSTEM \"file:///etc/passwd\">]><svg $ns><text>&x;</text></svg>",
	'animate sets href'              => "<svg $ns><a><animate attributeName=\"href\" values=\"javascript:alert(1)\"/><rect width=\"1\" height=\"1\"/></a></svg>",
	'set onbegin'                    => "<svg $ns><set attributeName=\"x\" to=\"1\" onbegin=\"alert(1)\"/></svg>",
	'uppercase ONCLICK'              => "<svg $ns><rect ONCLICK=\"alert(1)\" width=\"1\" height=\"1\"/></svg>",
	'xhtml script namespace'         => "<svg $ns xmlns:h=\"http://www.w3.org/1999/xhtml\"><h:script>alert(1)</h:script></svg>",
	'data:text/html image'           => "<svg $ns><image href=\"data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==\"/></svg>",
	'css escaped javascript'         => "<svg $ns><style>a{background:url(\\6a avascript:alert(1))}</style></svg>",
);
foreach ( $payloads as $label => $svg ) {
	$out = omatic_svg_sanitize( $svg );
	check( $label, is_safe( $out ), is_wp_error( $out ) ? $out->get_error_message() : $out );
}

// Benign SVG: structure, gradients, internal references and links survive.
$benign = '<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd">
<!-- Generator: test -->
<svg ' . $ns . ' viewBox="0 0 120 40" width="120" height="40">
  <title>Logo</title>
  <defs>
    <linearGradient id="g" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#0af"/><stop offset="1" stop-color="#05a"/></linearGradient>
    <symbol id="dot"><circle cx="5" cy="5" r="5"/></symbol>
    <filter id="f"><feGaussianBlur stdDeviation="1"/></filter>
  </defs>
  <style>.t{font-family:sans-serif;fill:url(#g)}</style>
  <rect width="120" height="40" rx="6" fill="url(#g)" filter="url(#f)"/>
  <use xlink:href="#dot" x="4" y="4"/>
  <use href="#dot" x="20" y="4"/>
  <a href="https://o-matic.ai" target="_blank"><text class="t" x="30" y="26" font-size="14">o-MATIC &amp; co</text></a>
</svg>';
$out = omatic_svg_sanitize( $benign );
check( 'benign: not refused', ! is_wp_error( $out ), is_wp_error( $out ) ? $out->get_error_message() : '' );
if ( ! is_wp_error( $out ) ) {
	foreach ( array( '<title>Logo</title>', 'id="g"', 'fill="url(#g)"', 'filter="url(#f)"', 'xlink:href="#dot"', 'href="#dot"', 'href="https://o-matic.ai"', 'fill:url(#g)', 'viewBox="0 0 120 40"', '<feGaussianBlur', 'o-MATIC &amp; co' ) as $needle ) {
		check( "benign keeps $needle", false !== strpos( $out, $needle ), $out );
	}
	check( 'benign: re-parses as XML', false !== @simplexml_load_string( $out ), $out );
	check( 'benign: comment dropped', false === strpos( $out, 'Generator' ), $out );
}

// Idempotent: sanitizing sanitized output changes nothing.
if ( ! is_wp_error( $out ) ) {
	check( 'idempotent', omatic_svg_sanitize( $out ) === $out );
}

echo $fail ? "\n$fail FAILED\n" : "\nALL PASS\n";
exit( $fail ? 1 : 0 );
