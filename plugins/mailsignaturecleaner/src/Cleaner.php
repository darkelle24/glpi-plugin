<?php
namespace MailSignatureCleaner;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Symfony\Component\CssSelector\CssSelectorConverter;

final class Cleaner
{
    public const MAX_BYTES = 1048576;

    /** Pure operation shared by the mail hook and the preview page. */
    public function clean(string $html, array $settings): array
    {
        $result = ['html' => $html, 'changed' => false, 'status' => 'unchanged',
            'removed' => 0, 'protected' => 0, 'matches' => [], 'warnings' => []];
        if (strlen($html) > self::MAX_BYTES) {
            $result['status'] = 'too_large';
            return $result;
        }
        // Never entity-decode the whole input: escaped code examples are legitimate text.
        if (!preg_match('/<[a-z][a-z0-9]*(?:\s[^<>]*|\s*)>/i', $html)) {
            $result['status'] = 'plain_text';
            return $result;
        }
        if (preg_match('/<!ENTITY\b|<!DOCTYPE[^>]*\[/i', $html)) {
            $result['status'] = 'unsafe_declaration';
            return $result;
        }
        try {
            $settings = Settings::validate($settings);
            $converter = new CssSelectorConverter();
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
                $result['status'] = 'parse_failed';
                return $result;
            }
            $xpath = new DOMXPath($doc);
            $protected = [];
            // These protections cannot be disabled in configuration.
            $protectSelectors = array_merge(['blockquote', 'a[href*="document.send.php"]',
                'img[src*="document.send.php"]', 'img[id]:not([src])', '[data-glpi-document]'],
                Settings::lines($settings['protect_selectors']));
            foreach ($protectSelectors as $selector) {
                foreach ($xpath->query($converter->toXPath($selector)) as $node) {
                    $protected[spl_object_id($node)] = $node;
                }
            }
            $targets = [];
            $cutTargets = [];
            $quoteNodes = [];
            foreach (['blockquote', '.gmail_quote', '.moz-cite-prefix', '#divRplyFwdMsg'] as $selector) {
                foreach ($xpath->query($converter->toXPath($selector)) as $node) {
                    $quoteNodes[spl_object_id($node)] = true;
                }
            }
            foreach (Settings::lines($settings['cut_after_selectors']) as $selector) {
                $anchors = $xpath->query($converter->toXPath($selector));
                $result['matches']['Après : ' . $selector] = $anchors->length;
                if ($anchors->length > 1) {
                    $result['status'] = 'ambiguous_cut';
                    return $result;
                }
                if ($anchors->length === 0) {
                    continue;
                }
                $anchor = $anchors->item(0);
                if (!$this->contains($body, $anchor) || $anchor->isSameNode($body)) {
                    $result['status'] = 'invalid_cut';
                    return $result;
                }
                foreach ($protected as $protectedNode) {
                    if ($this->contains($protectedNode, $anchor)) {
                        $result['status'] = 'protected';
                        $result['protected']++;
                        return $result;
                    }
                }
                // Follow document order across parent boundaries; retain the anchor
                // and its ancestors so the retained HTML stays structurally valid.
                for ($branch = $anchor; !$branch->isSameNode($body); $branch = $branch->parentNode) {
                    for ($next = $branch->nextSibling; $next !== null; $next = $next->nextSibling) {
                        $targets[spl_object_id($next)] = $next;
                        $cutTargets[spl_object_id($next)] = true;
                    }
                }
            }
            foreach (Settings::lines($settings['selectors']) as $selector) {
                $nodes = $xpath->query($converter->toXPath($selector));
                $result['matches'][$selector] = $nodes->length;
                foreach ($nodes as $node) {
                    if ($node instanceof DOMElement) {
                        $targets[spl_object_id($node)] = $node;
                    }
                }
            }
            if (count($targets) > 100) {
                $result['status'] = 'too_many_matches';
                return $result;
            }
            $allowed = [];
            foreach ($targets as $node) {
                $blocked = ($node instanceof DOMElement
                    && in_array(strtolower($node->tagName), ['html', 'head', 'body'], true))
                    || !$this->contains($body, $node);
                foreach ($protected as $protectedNode) {
                    // A deliberate cut may also discard historical reply headers.
                    // Normal selector removals and anchor checks keep quote protection.
                    if ($settings['cut_remove_quotes'] === '1'
                        && isset($cutTargets[spl_object_id($node)])
                        && isset($quoteNodes[spl_object_id($protectedNode)])) {
                        continue;
                    }
                    if ($this->contains($node, $protectedNode) || $this->contains($protectedNode, $node)) {
                        $blocked = true;
                        break;
                    }
                }
                $serialized = $doc->saveHTML($node);
                foreach (Settings::lines($settings['markers']) as $marker) {
                    if (str_contains($serialized, $marker) || str_contains($node->textContent, $marker)) {
                        $blocked = true;
                        break;
                    }
                }
                if ($blocked) {
                    $result['protected']++;
                } else {
                    $allowed[] = $node;
                }
            }
            // Deduplicate normal targets and explicit cut-after tails.
            $top = array_filter($allowed, function (DOMNode $node) use ($allowed): bool {
                foreach ($allowed as $other) {
                    if ($other !== $node && $this->contains($other, $node)) {
                        return false;
                    }
                }
                return true;
            });
            if ($top === []) {
                $result['status'] = $result['protected'] ? 'protected' : 'no_match';
                return $result;
            }
            $beforeText = $this->text($body);
            $beforeSize = strlen($doc->saveHTML($body));
            foreach ($top as $node) {
                $node->parentNode->removeChild($node);
            }
            $afterText = $this->text($body);
            $remainingMedia = $xpath->query('.//img|.//object|.//video|.//audio|.//a[@href]', $body)->length;
            if ($afterText === '' && $remainingMedia === 0) {
                $result['status'] = 'empty_guard';
                return $result;
            }
            $textRatio = $beforeText === '' ? 0 : 100 * max(0, strlen($beforeText) - strlen($afterText)) / strlen($beforeText);
            $htmlRatio = 100 * max(0, $beforeSize - strlen($doc->saveHTML($body))) / max(1, $beforeSize);
            if (max($textRatio, $htmlRatio) > (int) $settings['max_removed_percent']) {
                $result['status'] = 'ratio_guard';
                return $result;
            }
            // Preserve full-document vs fragment representation.
            if (preg_match('/<html\b|<!doctype\b/i', $html)) {
                foreach (iterator_to_array($doc->childNodes) as $child) {
                    if ($child->nodeType === XML_PI_NODE) {
                        $doc->removeChild($child);
                    }
                }
                $output = $doc->saveHTML();
            } else {
                $output = '';
                foreach ($body->childNodes as $child) {
                    $output .= $doc->saveHTML($child);
                }
            }
            // Check all delimiter occurrences, including outside removed nodes and HTML comments.
            foreach (Settings::lines($settings['markers']) as $marker) {
                if (substr_count($html, $marker) !== substr_count($output, $marker)) {
                    $result['status'] = 'marker_guard';
                    return $result;
                }
            }
            $result['html'] = $output;
            $result['changed'] = $output !== $html;
            $result['status'] = 'cleaned';
            $result['removed'] = count($top);
            return $result;
        } catch (\Throwable $e) {
            $result['status'] = 'error';
            $result['warnings'][] = 'Nettoyage abandonné : configuration invalide ou erreur de traitement.';
            return $result;
        }
    }

    private function contains(DOMNode $parent, DOMNode $child): bool
    {
        for ($node = $child; $node !== null; $node = $node->parentNode) {
            if ($node->isSameNode($parent)) {
                return true;
            }
        }
        return false;
    }

    private function text(DOMNode $node): string
    {
        return preg_replace('/[\s\x{00a0}]+/u', '', $node->textContent) ?? $node->textContent;
    }
}
