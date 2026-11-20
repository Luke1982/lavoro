<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Text from the editor, made safe to show as HTML to anyone: only the markup
 * the editor itself makes is kept (paragraphs, headings, bold, italic,
 * underline, lists, quotes, links), without a single attribute except a link's
 * address, and only an http, https or mailto address at that. Anything else is
 * unwrapped to its text; a script, style or embedded frame goes with its content.
 *
 * Needed because a quote's texts are shown to a customer without an account,
 * and the same page opens as a preview for colleagues who are logged in.
 */
final class RichText
{
    private const KEPT = [
        'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'ul', 'ol', 'li', 'h2', 'h3', 'h4', 'blockquote', 'a',
    ];

    private const DROPPED = [
        'script', 'style', 'iframe', 'object', 'embed', 'template', 'svg', 'math',
        'form', 'input', 'textarea', 'button', 'select',
    ];

    public static function clean(?string $html): ?string
    {
        if ($html === null || trim(strip_tags($html)) === '') {
            return null;
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="UTF-8"><div>' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementsByTagName('div')->item(0);
        self::scrub($root);

        $kept = array_map(fn (DOMNode $node) => $document->saveHTML($node), iterator_to_array($root->childNodes));

        return implode('', $kept);
    }

    /**
     * The same text without its markup, as a timeline or a search reads it: one
     * line per paragraph or list item, entities turned back into characters.
     */
    public static function plain(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        $text = strip_tags(preg_replace('#<br\s*/?>|</(p|li|h[2-4]|blockquote)>#i', "\n", $html));
        $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lines = array_filter(array_map('trim', explode("\n", $decoded)));

        return $lines === [] ? null : implode("\n", $lines);
    }

    private static function isSafeLink(string $address): bool
    {
        return preg_match('/^(https?:|mailto:)/i', trim($address)) === 1;
    }

    private static function scrub(DOMNode $parent): void
    {
        foreach (iterator_to_array($parent->childNodes) as $node) {
            if ($node instanceof DOMText) {
                continue;
            }

            if (!$node instanceof DOMElement || in_array(strtolower($node->tagName), self::DROPPED, true)) {
                $parent->removeChild($node);

                continue;
            }

            self::scrub($node);
            $tag = strtolower($node->tagName);

            if (!in_array($tag, self::KEPT, true)) {
                while ($node->firstChild) {
                    $parent->insertBefore($node->firstChild, $node);
                }
                $parent->removeChild($node);

                continue;
            }

            foreach (iterator_to_array($node->attributes) as $attribute) {
                if (!($tag === 'a' && $attribute->name === 'href' && self::isSafeLink($attribute->value))) {
                    $node->removeAttribute($attribute->name);
                }
            }

            if ($tag === 'a') {
                $node->setAttribute('rel', 'noopener noreferrer');
                $node->setAttribute('target', '_blank');
            }
        }
    }
}
