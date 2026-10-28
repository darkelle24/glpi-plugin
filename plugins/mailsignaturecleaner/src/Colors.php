<?php
namespace MailSignatureCleaner;

use DOMDocument;
use DOMElement;

/** Remove sender colors so each reader's GLPI theme supplies the foreground. */
final class Colors
{
    public static function normalize(string $html): array
    {
        $unchanged = ['html' => $html, 'changed' => false];
        if (strlen($html) > Cleaner::MAX_BYTES
            || !preg_match('/<[a-z][a-z0-9]*(?:\s[^<>]*|\s*)>/i', $html)
            || preg_match('/<!ENTITY\b|<!DOCTYPE[^>]*\[/i', $html)) {
            return $unchanged;
        }
        $doc = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $doc->loadHTML('<?xml encoding="UTF-8">' . $html,
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded || !($body = $doc->getElementsByTagName('body')->item(0))) {
            return $unchanged;
        }
        $changed = false;
        foreach (iterator_to_array($doc->getElementsByTagName('*')) as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($node->tagName);
            // Embedded/linked sender styles can recolor classes after inline cleanup.
            if ($tag === 'style' || ($tag === 'link'
                && preg_match('/(?:^|\s)stylesheet(?:\s|$)/i', $node->getAttribute('rel')))) {
                $node->parentNode?->removeChild($node);
                $changed = true;
                continue;
            }
            foreach (['color', 'bgcolor', 'text', 'link', 'vlink', 'alink'] as $attribute) {
                if ($node->hasAttribute($attribute)) {
                    $node->removeAttribute($attribute);
                    $changed = true;
                }
            }
            if ($node->hasAttribute('background')) {
                $node->removeAttribute('background');
                $changed = true;
            }
            if (!$node->hasAttribute('style')) {
                continue;
            }
            $original = $node->getAttribute('style');
            $declarations = self::declarations($original);
            $kept = [];
            $removed = $declarations === null;
            foreach ($declarations ?? [] as $declaration) {
                $colon = strpos($declaration, ':');
                if ($colon === false) {
                    $removed = true;
                    continue;
                }
                $property = trim(substr($declaration, 0, $colon));
                $property = preg_replace_callback('/\\\\([0-9a-f]{1,6})\s?|\\\\(.)/i',
                    static fn(array $m): string => ($m[1] ?? '') !== ''
                        ? (hexdec($m[1]) < 128 ? chr((int) hexdec($m[1])) : '?') : ($m[2] ?? ''),
                    $property);
                $property = strtolower($property);
                if (str_starts_with($property, '--') || in_array($property,
                    ['color', 'background', 'background-color', 'background-image', 'all',
                        '-webkit-text-fill-color', '-webkit-text-stroke', '-webkit-text-stroke-color',
                        'text-shadow', 'text-decoration-color', 'filter', 'mix-blend-mode'], true)) {
                    $removed = true;
                } else {
                    $kept[] = $declaration;
                }
            }
            if ($removed) {
                if ($kept === []) {
                    $node->removeAttribute('style');
                } else {
                    $node->setAttribute('style', implode(';', $kept));
                }
                $changed = true;
            }
        }
        if (!$changed) {
            return $unchanged;
        }
        if (preg_match('/<html\b|<!doctype\b/i', $html)) {
            foreach (iterator_to_array($doc->childNodes) as $child) {
                if ($child->nodeType === XML_PI_NODE) {
                    $doc->removeChild($child);
                }
            }
            return ['html' => $doc->saveHTML(), 'changed' => true];
        }
        $output = '';
        foreach ($body->childNodes as $child) {
            $output .= $doc->saveHTML($child);
        }
        return ['html' => $output, 'changed' => true];
    }

    /** Split CSS declarations without breaking quoted/parenthesized semicolons. */
    private static function declarations(string $css): ?array
    {
        $declarations = [];
        $part = '';
        $quote = null;
        $depth = 0;
        $length = strlen($css);
        for ($i = 0; $i < $length; $i++) {
            $char = $css[$i];
            if ($char === '\\' && $i + 1 < $length) {
                $part .= $char . $css[++$i];
                continue;
            }
            if ($quote !== null) {
                $part .= $char;
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === '/' && ($css[$i + 1] ?? '') === '*') {
                $end = strpos($css, '*/', $i + 2);
                if ($end === false) {
                    return null;
                }
                $i = $end + 1;
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                if (--$depth < 0) {
                    return null;
                }
            } elseif ($char === ';' && $depth === 0) {
                if (trim($part) !== '') {
                    $declarations[] = trim($part);
                }
                $part = '';
                continue;
            }
            $part .= $char;
        }
        if ($quote !== null || $depth !== 0) {
            return null;
        }
        if (trim($part) !== '') {
            $declarations[] = trim($part);
        }
        return $declarations;
    }
}
