<?php

declare(strict_types=1);

namespace App\Library\Metadata;

/**
 * The readable text of a page (the article, without menus, scripts and footers), with one line
 * per block. What the LLM reads for recipe blogs that publish no schema.org Recipe.
 */
final class HtmlText
{
    public const int MAX_CHARS = 40000;

    private const string NOISE = 'script, style, noscript, template, svg, iframe, form, nav, header, footer, aside, button, select, [aria-hidden="true"], [hidden]';
    private const string BLOCKS = 'p, li, h1, h2, h3, h4, h5, h6, tr, br, div, section, article, blockquote, dd, dt, figcaption';

    public static function fromDocument(\Dom\HTMLDocument $document): string
    {
        $root = $document->querySelector('[itemtype*="schema.org/Recipe"]')
            ?? $document->querySelector('article')
            ?? $document->querySelector('main')
            ?? $document->body;
        if (null === $root) {
            return '';
        }
        $root = $root->cloneNode(true);
        \assert($root instanceof \Dom\Element);

        foreach ($root->querySelectorAll(self::NOISE) as $element) {
            // Not $element->remove(): PHP throws "Not Found Error" for nodes without a parent
            // (seen on x.com's inline SVGs).
            $element->parentNode?->removeChild($element);
        }
        foreach ($root->querySelectorAll(self::BLOCKS) as $element) {
            $element->append("\n");
        }

        $text = (string) $root->textContent;
        $text = (string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $text);
        $text = (string) preg_replace('/\s*\n\s*/u', "\n", $text);

        return mb_substr(trim($text), 0, self::MAX_CHARS);
    }
}
