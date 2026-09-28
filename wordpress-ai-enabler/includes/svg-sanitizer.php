<?php
/**
 * LucidIT WordPress Enabler — parser-based SVG sanitizer.
 *
 * Replaces the regex sanitizer shipped through 2.4.0, which passed three of
 * four measured XSS payloads (task #1013): `<svg/onload=...>` (the regex
 * required whitespace before `on`), an unquoted `href=javascript:...` (the
 * regex required quotes), and an entity-encoded `href="&#106;avascript:..."`.
 * A regex cannot see what a parser sees; this parses the markup with
 * DOMDocument and keeps only an allowlist.
 *
 * Rules:
 *  - A DOCTYPE with an internal subset, or any <!ENTITY>, is refused outright
 *    (XXE and entity expansion). A bare public-identifier DOCTYPE, as design
 *    tools emit, is stripped. The document is loaded with LIBXML_NONET and
 *    without LIBXML_NOENT / LIBXML_DTDLOAD, so no entity is substituted and no
 *    external resource is fetched.
 *  - Elements: SVG-namespace (or un-namespaced) elements on an allowlist.
 *    Everything else, including script, foreignObject, animate/set and any
 *    XHTML-namespace element, is removed with its subtree.
 *  - Attributes: allowlist only. Every on* attribute is dropped in any case.
 *  - href / xlink:href: the value is read after the parser has decoded
 *    entities, then every ASCII control character and space is removed (as
 *    browsers do when parsing a URL). It must be a same-document #fragment;
 *    on <a> an http(s) URL is also allowed; on <image> a base64 raster
 *    data: URI is also allowed. Otherwise the attribute is dropped.
 *  - url(...) in any attribute or <style> must be url(#fragment); @import,
 *    expression(), javascript:, behavior and -moz-binding are refused. A
 *    failing style attribute is dropped; a failing <style> element is removed.
 *  - Comments and processing instructions are removed.
 *
 * Pure PHP (ext-dom), no WordPress calls except WP_Error, so it can be tested
 * outside WordPress.
 *
 * @package LucidIT_WP_Enabler
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const OMATIC_SVG_NS   = 'http://www.w3.org/2000/svg';
const OMATIC_XLINK_NS = 'http://www.w3.org/1999/xlink';
const OMATIC_XML_NS   = 'http://www.w3.org/XML/1998/namespace';

/**
 * Allowed SVG element local names (case-sensitive, as SVG is XML).
 *
 * @return array<string,bool>
 */
function omatic_svg_allowed_elements() {
	static $set = null;
	if ( null === $set ) {
		$set = array_fill_keys(
			array(
				'svg', 'g', 'defs', 'symbol', 'use', 'title', 'desc', 'switch',
				'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon',
				'text', 'tspan', 'textPath',
				'linearGradient', 'radialGradient', 'stop', 'pattern',
				'clipPath', 'mask', 'marker', 'image', 'a', 'style',
				'filter', 'feBlend', 'feColorMatrix', 'feComponentTransfer', 'feComposite',
				'feConvolveMatrix', 'feDiffuseLighting', 'feDisplacementMap', 'feDistantLight',
				'feDropShadow', 'feFlood', 'feFuncA', 'feFuncB', 'feFuncG', 'feFuncR',
				'feGaussianBlur', 'feMerge', 'feMergeNode', 'feMorphology', 'feOffset',
				'fePointLight', 'feSpecularLighting', 'feSpotLight', 'feTile', 'feTurbulence',
			),
			true
		);
	}
	return $set;
}

/**
 * Allowed un-namespaced attribute names (case-sensitive).
 *
 * @return array<string,bool>
 */
function omatic_svg_allowed_attributes() {
	static $set = null;
	if ( null === $set ) {
		$set = array_fill_keys(
			array(
				// Core / structure.
				'id', 'class', 'style', 'lang', 'tabindex', 'role', 'focusable',
				'aria-label', 'aria-labelledby', 'aria-describedby', 'aria-hidden',
				'version', 'baseProfile', 'viewBox', 'preserveAspectRatio', 'width', 'height',
				'x', 'y', 'x1', 'y1', 'x2', 'y2', 'cx', 'cy', 'r', 'rx', 'ry', 'fx', 'fy', 'fr',
				'd', 'points', 'pathLength', 'transform', 'href', 'target',
				'dx', 'dy', 'rotate', 'textLength', 'lengthAdjust', 'startOffset', 'method', 'spacing', 'side',
				// Gradients, patterns, clip, mask, marker.
				'gradientUnits', 'gradientTransform', 'spreadMethod', 'offset',
				'patternUnits', 'patternContentUnits', 'patternTransform',
				'clipPathUnits', 'maskUnits', 'maskContentUnits',
				'markerUnits', 'markerWidth', 'markerHeight', 'refX', 'refY', 'orient',
				// Presentation attributes.
				'fill', 'fill-opacity', 'fill-rule', 'stroke', 'stroke-width', 'stroke-opacity',
				'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit', 'stroke-dasharray',
				'stroke-dashoffset', 'opacity', 'color', 'display', 'visibility', 'overflow',
				'clip-path', 'clip-rule', 'mask', 'filter', 'marker-start', 'marker-mid', 'marker-end',
				'stop-color', 'stop-opacity', 'flood-color', 'flood-opacity', 'lighting-color',
				'color-interpolation', 'color-interpolation-filters', 'color-rendering',
				'shape-rendering', 'text-rendering', 'image-rendering', 'vector-effect',
				'paint-order', 'mix-blend-mode', 'isolation',
				'font-family', 'font-size', 'font-size-adjust', 'font-stretch', 'font-style',
				'font-variant', 'font-weight', 'text-anchor', 'text-decoration', 'letter-spacing',
				'word-spacing', 'writing-mode', 'direction', 'dominant-baseline', 'alignment-baseline',
				'baseline-shift', 'unicode-bidi', 'white-space',
				// Filter primitives.
				'filterUnits', 'primitiveUnits', 'in', 'in2', 'result', 'mode', 'type', 'values',
				'tableValues', 'slope', 'intercept', 'amplitude', 'exponent', 'operator',
				'k1', 'k2', 'k3', 'k4', 'order', 'kernelMatrix', 'divisor', 'bias', 'targetX', 'targetY',
				'edgeMode', 'kernelUnitLength', 'preserveAlpha', 'surfaceScale', 'diffuseConstant',
				'specularConstant', 'specularExponent', 'scale', 'xChannelSelector', 'yChannelSelector',
				'stdDeviation', 'radius', 'baseFrequency', 'numOctaves', 'seed', 'stitchTiles',
				'azimuth', 'elevation', 'z', 'pointsAtX', 'pointsAtY', 'pointsAtZ', 'limitingConeAngle',
				// Conditional processing.
				'requiredFeatures', 'requiredExtensions', 'systemLanguage',
			),
			true
		);
	}
	return $set;
}

/**
 * Normalize a URL-ish attribute value the way a browser's URL parser sees it:
 * the DOM has already decoded entities; remove every ASCII control character
 * and space (browsers strip tab/newline anywhere and C0/space at the ends).
 *
 * @param string $value Decoded attribute value.
 * @return string
 */
function omatic_svg_normalize_url( $value ) {
	return (string) preg_replace( '/[\x00-\x20\x7F]+/', '', (string) $value );
}

/**
 * Whether an href value is acceptable on a given element.
 *
 * @param string $value   Decoded attribute value.
 * @param string $element Element local name.
 * @return bool
 */
function omatic_svg_href_is_safe( $value, $element ) {
	$url = omatic_svg_normalize_url( $value );
	if ( '' === $url ) {
		return false;
	}
	if ( preg_match( '/^#[A-Za-z_][A-Za-z0-9_.:\-]*$/', $url ) ) {
		return true;
	}
	if ( 'a' === $element && preg_match( '#^https?://[^/\\\\]#i', $url ) ) {
		return true;
	}
	if ( 'image' === $element && preg_match( '#^data:image/(png|jpeg|gif|webp);base64,[A-Za-z0-9+/=]+$#i', $url ) ) {
		return true;
	}
	return false;
}

/**
 * Whether CSS text (a style attribute, a <style> body, or a presentation
 * attribute value) is free of script vectors and external references.
 *
 * @param string $css Decoded text.
 * @return bool
 */
function omatic_svg_css_is_safe( $css ) {
	// Resolve CSS escapes (\6a, \0000;) the way a CSS tokenizer would, then
	// drop whitespace/comments so split tokens cannot hide a keyword.
	$css = preg_replace_callback(
		'/\\\\([0-9a-fA-F]{1,6})\s?/',
		function ( $m ) {
			$cp = hexdec( $m[1] );
			// Only ASCII can spell a keyword checked below; anything else is inert.
			return ( $cp > 0 && $cp < 128 ) ? chr( $cp ) : '?';
		},
		(string) $css
	);
	$css = preg_replace( '/\\\\(.)/s', '$1', $css );
	$css = preg_replace( '#/\*.*?\*/#s', '', $css );
	$flat = strtolower( (string) preg_replace( '/[\x00-\x20\x7F]+/', '', $css ) );

	foreach ( array( '@import', 'expression(', 'javascript:', 'vbscript:', 'behavior:', '-moz-binding', '<' ) as $bad ) {
		if ( false !== strpos( $flat, $bad ) ) {
			return false;
		}
	}
	// Every url(...) must reference a same-document fragment.
	if ( preg_match_all( '/url\(([^)]*)\)/', $flat, $matches ) ) {
		foreach ( $matches[1] as $target ) {
			$target = trim( $target, "'\"" );
			if ( ! preg_match( '/^#[a-z_][a-z0-9_.:\-]*$/', $target ) ) {
				return false;
			}
		}
	}
	// A url( that did not close is refused too.
	if ( substr_count( $flat, 'url(' ) !== ( isset( $matches[0] ) ? count( $matches[0] ) : 0 ) ) {
		return false;
	}
	return true;
}

/**
 * Recursively sanitize an element's children and attributes in place.
 *
 * @param DOMElement $el Element already accepted by the caller.
 */
function omatic_svg_clean_element( DOMElement $el ) {
	$allowed_attrs = omatic_svg_allowed_attributes();
	$name          = $el->localName;

	// Attributes. Collect first: removing while iterating skips entries.
	$remove = array();
	foreach ( $el->attributes as $attr ) {
		/** @var DOMAttr $attr */
		$local = $attr->localName;
		$ns    = $attr->namespaceURI;
		$value = (string) $attr->value;

		if ( 0 === stripos( $local, 'on' ) ) {
			$remove[] = $attr;
			continue;
		}
		if ( OMATIC_XLINK_NS === $ns ) {
			if ( 'href' !== $local || ! omatic_svg_href_is_safe( $value, $name ) ) {
				$remove[] = $attr;
			}
			continue;
		}
		if ( OMATIC_XML_NS === $ns ) {
			if ( 'space' !== $local && 'lang' !== $local ) {
				$remove[] = $attr;
			}
			continue;
		}
		if ( null !== $ns && '' !== $ns ) {
			$remove[] = $attr; // inkscape:, sodipodi:, xhtml:, anything else.
			continue;
		}
		if ( ! isset( $allowed_attrs[ $local ] ) ) {
			$remove[] = $attr;
			continue;
		}
		if ( 'href' === $local ) {
			if ( ! omatic_svg_href_is_safe( $value, $name ) ) {
				$remove[] = $attr;
			}
			continue;
		}
		if ( 'target' === $local && 'a' !== $name ) {
			$remove[] = $attr;
			continue;
		}
		// Any value that could carry CSS or a url() reference.
		if ( ! omatic_svg_css_is_safe( $value ) ) {
			$remove[] = $attr;
		}
	}
	foreach ( $remove as $attr ) {
		$el->removeAttributeNode( $attr );
	}

	// <style>: accept only when its whole text is safe; it has no children
	// other than text/CDATA after this.
	if ( 'style' === $name ) {
		$text = '';
		foreach ( iterator_to_array( $el->childNodes ) as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType || XML_CDATA_SECTION_NODE === $child->nodeType ) {
				$text .= $child->nodeValue;
			} else {
				$el->removeChild( $child );
			}
		}
		if ( ! omatic_svg_css_is_safe( $text ) ) {
			$el->parentNode->removeChild( $el );
		}
		return;
	}

	// Children.
	$allowed_elements = omatic_svg_allowed_elements();
	foreach ( iterator_to_array( $el->childNodes ) as $child ) {
		switch ( $child->nodeType ) {
			case XML_ELEMENT_NODE:
				$ns = $child->namespaceURI;
				if ( ( OMATIC_SVG_NS === $ns || null === $ns || '' === $ns ) && isset( $allowed_elements[ $child->localName ] ) ) {
					omatic_svg_clean_element( $child );
				} else {
					$el->removeChild( $child );
				}
				break;
			case XML_TEXT_NODE:
			case XML_CDATA_SECTION_NODE:
				break;
			default: // Comments, processing instructions, entity references.
				$el->removeChild( $child );
		}
	}
}

/**
 * Sanitize SVG markup to an allowlisted subset.
 *
 * @param string $svg Raw SVG markup.
 * @return string|WP_Error Sanitized markup (no XML declaration), or an error.
 */
function omatic_svg_sanitize( $svg ) {
	$svg = (string) $svg;

	if ( ! class_exists( 'DOMDocument' ) ) {
		return new WP_Error( 'svg_no_dom', 'The PHP dom extension is required to sanitize SVG; refusing.' );
	}
	if ( false === stripos( $svg, '<svg' ) ) {
		return new WP_Error( 'not_svg', 'Content does not contain an <svg> root element.' );
	}

	// A plain public-identifier DOCTYPE (no internal subset) is stripped; any
	// DOCTYPE with a subset, or any ENTITY declaration, is refused.
	$svg = preg_replace( '/<!DOCTYPE\s+svg[^\[>]*>/i', '', $svg );
	if ( false !== stripos( $svg, '<!DOCTYPE' ) || false !== stripos( $svg, '<!ENTITY' ) ) {
		return new WP_Error( 'svg_unsafe', 'SVG contains a DOCTYPE internal subset or an ENTITY declaration; refusing.' );
	}

	$dom      = new DOMDocument();
	$previous = libxml_use_internal_errors( true );
	// No LIBXML_NOENT (no entity substitution), no LIBXML_DTDLOAD, no network.
	$loaded = $dom->loadXML( $svg, LIBXML_NONET );
	libxml_clear_errors();
	libxml_use_internal_errors( $previous );

	if ( ! $loaded || ! $dom->documentElement ) {
		return new WP_Error( 'svg_invalid', 'SVG is not well-formed XML; refusing.' );
	}

	$root = $dom->documentElement;
	$ns   = $root->namespaceURI;
	if ( 'svg' !== $root->localName || ! ( OMATIC_SVG_NS === $ns || null === $ns || '' === $ns ) ) {
		return new WP_Error( 'not_svg', 'The root element is not <svg>.' );
	}

	// Top-level comments / PIs outside the root are not serialized below.
	omatic_svg_clean_element( $root );

	if ( ! $root->hasAttribute( 'xmlns' ) && ( null === $ns || '' === $ns ) ) {
		$root->setAttribute( 'xmlns', OMATIC_SVG_NS );
	}

	$out = $dom->saveXML( $root );
	if ( false === $out || '' === $out ) {
		return new WP_Error( 'svg_invalid', 'SVG could not be serialized after sanitizing.' );
	}
	return $out;
}
