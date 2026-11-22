<?php

/**
 * @file plugins/generic/ojsbrSitemap/classes/SitemapTransformer.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class SitemapTransformer
 *
 * @brief Changes the sitemap of a journal. It works only on the XML and on the
 *        URLs it is given, so it can be tested without a database.
 */

namespace APP\plugins\generic\ojsbrSitemap\classes;

use DOMDocument;
use DOMElement;

class SitemapTransformer
{
    public const XHTML_NAMESPACE = 'http://www.w3.org/1999/xhtml';

    /**
     * @param string[] $remove URLs taken out of the sitemap.
     * @param array<string, string> $lastModified URL => date (Y-m-d) of the content of that page.
     * @param string[] $add URLs added to the sitemap, unless they are already there.
     * @param string[] $latest URLs whose date is the latest date of the sitemap (home page, archive).
     * @param array<string, string> $alternates hreflang => base URL of the journal in that language.
     * @param ?string $currentBase Base URL of the journal in the language of this sitemap.
     * @param ?string $defaultHreflang hreflang of the primary language, used for x-default.
     */
    public function __construct(
        protected array $remove = [],
        protected array $lastModified = [],
        protected array $add = [],
        protected array $latest = [],
        protected array $alternates = [],
        protected ?string $currentBase = null,
        protected ?string $defaultHreflang = null,
    ) {
    }

    /**
     * Change the sitemap in place.
     */
    public function transform(DOMDocument $doc): void
    {
        $root = $doc->documentElement;
        if (!$root instanceof DOMElement) {
            return;
        }

        $remove = array_fill_keys($this->remove, true);
        $seen = [];
        foreach ($this->urlElements($root) as $url) {
            $loc = $this->locOf($url);
            if ($loc === null || isset($remove[$loc]) || isset($seen[$loc])) {
                $root->removeChild($url);
                continue;
            }
            $seen[$loc] = $url;
        }

        foreach ($this->add as $loc) {
            if (!isset($seen[$loc]) && !isset($remove[$loc])) {
                $url = $doc->createElement('url');
                $url->appendChild($doc->createElement('loc'))->appendChild($doc->createTextNode($loc));
                $root->appendChild($url);
                $seen[$loc] = $url;
            }
        }

        // The home page and the archive change whenever any content changes: they
        // take the latest date of the pages listed, never the current time.
        $dates = array_intersect_key($this->lastModified, $seen);
        $latest = $dates ? max($dates) : null;
        foreach ($seen as $loc => $url) {
            $date = in_array($loc, $this->latest, true) ? $latest : ($this->lastModified[$loc] ?? null);
            if ($date !== null) {
                $this->setLastModified($url, $date);
            }
        }

        if (count($this->alternates) > 1 && $this->currentBase !== null) {
            // Declared as an attribute and the links created by their qualified name, as
            // OJS declares the sitemap namespace: the XML is the same, but a namespaced
            // element makes PHP walk the whole document at each append (13 s for the
            // 44,000 links of a journal with 5,000 articles).
            $root->setAttribute('xmlns:xhtml', self::XHTML_NAMESPACE);
            foreach ($seen as $loc => $url) {
                $this->addAlternates($url, $loc);
            }
        }
    }

    /**
     * The value of hreflang for a PKP locale: pt_BR gives pt-BR, sr@latin gives sr.
     */
    public static function hreflang(string $locale): string
    {
        return str_replace('_', '-', explode('@', $locale)[0]);
    }

    /**
     * The date part (Y-m-d) of a date stored by OJS, or null when there is no real date.
     */
    public static function date(?string $value): ?string
    {
        if ($value === null || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $match)) {
            return null;
        }
        return checkdate((int) $match[2], (int) $match[3], (int) $match[1]) ? "{$match[1]}-{$match[2]}-{$match[3]}" : null;
    }

    /**
     * @return DOMElement[]
     */
    protected function urlElements(DOMElement $root): array
    {
        $urls = [];
        foreach ($root->childNodes as $node) {
            if ($node instanceof DOMElement && $node->localName === 'url') {
                $urls[] = $node;
            }
        }
        return $urls;
    }

    protected function locOf(DOMElement $url): ?string
    {
        foreach ($url->childNodes as $node) {
            if ($node instanceof DOMElement && $node->localName === 'loc') {
                $loc = trim($node->textContent);
                return $loc === '' ? null : $loc;
            }
        }
        return null;
    }

    /**
     * Set lastmod right after loc, as the sitemap schema orders them.
     */
    protected function setLastModified(DOMElement $url, string $date): void
    {
        foreach ($url->childNodes as $node) {
            if ($node instanceof DOMElement && $node->localName === 'lastmod') {
                return;
            }
        }
        $loc = null;
        foreach ($url->childNodes as $node) {
            if ($node instanceof DOMElement && $node->localName === 'loc') {
                $loc = $node;
                break;
            }
        }
        $lastmod = $url->ownerDocument->createElement('lastmod', $date);
        $loc?->nextSibling ? $url->insertBefore($lastmod, $loc->nextSibling) : $url->appendChild($lastmod);
    }

    /**
     * Link the page to the same page in every language of the journal.
     */
    protected function addAlternates(DOMElement $url, string $loc): void
    {
        $rest = $this->pathAfterBase($loc, $this->currentBase);
        if ($rest === null || $url->getElementsByTagName('xhtml:link')->length) {
            return;
        }
        $doc = $url->ownerDocument;
        $hrefs = [];
        foreach ($this->alternates as $hreflang => $base) {
            $hrefs[$hreflang] = $base . $rest;
        }
        if ($this->defaultHreflang !== null && isset($hrefs[$this->defaultHreflang])) {
            $hrefs['x-default'] = $hrefs[$this->defaultHreflang];
        }
        foreach ($hrefs as $hreflang => $href) {
            $link = $doc->createElement('xhtml:link');
            $link->setAttribute('rel', 'alternate');
            $link->setAttribute('hreflang', (string) $hreflang);
            $link->setAttribute('href', $href);
            $url->appendChild($link);
        }
    }

    /**
     * What follows the base URL in a URL ('' for the base itself), or null when the
     * URL is not under the base.
     */
    protected function pathAfterBase(string $loc, string $base): ?string
    {
        if ($loc === $base) {
            return '';
        }
        if (str_starts_with($loc, $base) && in_array($loc[strlen($base)], ['/', '?', '#'], true)) {
            return substr($loc, strlen($base));
        }
        return null;
    }
}
