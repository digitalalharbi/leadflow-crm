<?php

namespace App\Support;

use DOMDocument;
use DOMElement;

/**
 * Whether an uploaded SVG is only a drawing. An SVG is a document that can carry scripts, event handlers, external references and
 * entity bombs, so it is refused rather than cleaned: a logo that needs any of those is not a logo.
 */
final class SafeSvg
{
    private const ALLOWED = ['svg', 'g', 'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'defs', 'clippath', 'mask', 'lineargradient', 'radialgradient', 'stop', 'title', 'desc', 'use', 'text', 'tspan', 'style', 'metadata', 'symbol', 'pattern', 'filter', 'fegaussianblur', 'feoffset', 'feblend', 'fecolormatrix', 'feflood', 'fecomposite', 'femerge', 'femergenode'];

    public static function looksLikeSvg(string $path): bool
    {
        $head = (string) file_get_contents($path, false, null, 0, 1024);

        return (bool) preg_match('/<svg[\s>]/i', $head);
    }

    public static function isSafe(string $xml): bool
    {
        if ($xml === '' || strlen($xml) > 1_572_864 || preg_match('/<!(DOCTYPE|ENTITY)/i', $xml)) {
            return false;
        }

        $previous = libxml_use_internal_errors(true);
        $doc = new DOMDocument;
        $loaded = $doc->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded || $doc->documentElement === null || strtolower($doc->documentElement->localName) !== 'svg') {
            return false;
        }

        foreach ($doc->getElementsByTagName('*') as $node) {
            /** @var DOMElement $node */
            if (! in_array(strtolower($node->localName), self::ALLOWED, true)) {
                return false;
            }

            if (strtolower($node->localName) === 'style' && preg_match('/@import|url\s*\(|expression|javascript:/i', (string) $node->textContent)) {
                return false;
            }

            foreach ($node->attributes as $attribute) {
                $name = strtolower($attribute->nodeName);
                $value = trim((string) $attribute->nodeValue);

                if (str_starts_with($name, 'on')) {
                    return false;
                }

                // A reference may only point inside the drawing.
                // By local name, whatever prefix the namespace was given.
                if (in_array(strtolower($attribute->localName), ['href', 'src'], true) && ! str_starts_with($value, '#')) {
                    return false;
                }

                if (preg_match('/javascript:|data:|url\(\s*[\'"]?(?!#)/i', $value)) {
                    return false;
                }
            }
        }

        return true;
    }
}
