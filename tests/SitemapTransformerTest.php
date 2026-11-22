<?php

/**
 * @file plugins/generic/ojsbrSitemap/tests/SitemapTransformerTest.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class SitemapTransformerTest
 *
 * @brief The changes made to the XML of the sitemap, on a document built the way
 *        OJS builds it. No database is needed.
 */

namespace APP\plugins\generic\ojsbrSitemap\tests;

use APP\plugins\generic\ojsbrSitemap\classes\SitemapTransformer;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Attributes\CoversClass;
use PKP\tests\PKPTestCase;

#[CoversClass(SitemapTransformer::class)]
class SitemapTransformerTest extends PKPTestCase
{
    public const BASE = 'https://example.org/journal/en';

    public const SITEMAP_NAMESPACE = 'http://www.sitemaps.org/schemas/sitemap/0.9';

    /**
     * A sitemap built as PKPSitemapHandler::_createContextSitemap() builds it.
     *
     * @param string[] $locs
     */
    protected function sitemap(array $locs): DOMDocument
    {
        $doc = new DOMDocument('1.0', 'utf-8');
        $root = $doc->createElement('urlset');
        $root->setAttribute('xmlns', self::SITEMAP_NAMESPACE);
        foreach ($locs as $loc) {
            $url = $doc->createElement('url');
            $url->appendChild($doc->createElement('loc', htmlspecialchars($loc, ENT_COMPAT, 'UTF-8')));
            $root->appendChild($url);
        }
        $doc->appendChild($root);
        return $doc;
    }

    /**
     * The document as a search engine reads it: serialized and parsed again.
     */
    protected function published(DOMDocument $doc): DOMXPath
    {
        $parsed = new DOMDocument();
        $this->assertTrue($parsed->loadXML($doc->saveXML()), 'The sitemap is not well-formed XML.');
        $this->assertTrue($parsed->schemaValidate(__DIR__ . '/fixtures/sitemap-structure.xsd'), 'The sitemap breaks the structure of the sitemap protocol.');
        $xpath = new DOMXPath($parsed);
        $xpath->registerNamespace('s', self::SITEMAP_NAMESPACE);
        $xpath->registerNamespace('xhtml', SitemapTransformer::XHTML_NAMESPACE);
        return $xpath;
    }

    /** @return string[] */
    protected function locs(DOMXPath $xpath): array
    {
        $locs = [];
        foreach ($xpath->query('/s:urlset/s:url/s:loc') as $loc) {
            $locs[] = $loc->textContent;
        }
        return $locs;
    }

    protected function lastmod(DOMXPath $xpath, string $loc): ?string
    {
        $nodes = $xpath->query('/s:urlset/s:url[s:loc="' . $loc . '"]/s:lastmod');
        $this->assertLessThanOrEqual(1, $nodes->length, "More than one lastmod for {$loc}.");
        return $nodes->length ? $nodes->item(0)->textContent : null;
    }

    public function testRemovesTheListedUrlsAndKeepsTheOthersInOrder(): void
    {
        $doc = $this->sitemap([
            self::BASE,
            self::BASE . '/user/register',
            self::BASE . '/login',
            self::BASE . '/search',
            self::BASE . '/issue/current',
            self::BASE . '/issue/archive',
            self::BASE . '/article/view/7',
            self::BASE . '/article/view/7/12',
            self::BASE . '/article/view/8',
        ]);
        (new SitemapTransformer(remove: [
            self::BASE . '/user/register',
            self::BASE . '/login',
            self::BASE . '/search',
            self::BASE . '/issue/current',
            self::BASE . '/article/view/7/12',
        ]))->transform($doc);

        $this->assertSame([
            self::BASE,
            self::BASE . '/issue/archive',
            self::BASE . '/article/view/7',
            self::BASE . '/article/view/8',
        ], $this->locs($this->published($doc)));
    }

    public function testNoUrlIsListedTwice(): void
    {
        $doc = $this->sitemap([self::BASE, self::BASE . '/about', self::BASE . '/about', self::BASE . '/privacy-page']);
        (new SitemapTransformer(add: [self::BASE . '/about', self::BASE . '/about/editorialMasthead', self::BASE . '/about/editorialMasthead']))->transform($doc);

        $this->assertSame([
            self::BASE,
            self::BASE . '/about',
            self::BASE . '/privacy-page',
            self::BASE . '/about/editorialMasthead',
        ], $this->locs($this->published($doc)));
    }

    public function testAnAddedUrlThatIsAlsoRemovedIsNotAdded(): void
    {
        $doc = $this->sitemap([self::BASE]);
        (new SitemapTransformer(remove: [self::BASE . '/search'], add: [self::BASE . '/search']))->transform($doc);

        $this->assertSame([self::BASE], $this->locs($this->published($doc)));
    }

    public function testAddedUrlsAreEscaped(): void
    {
        $category = self::BASE . '/catalog/category/a&b';
        $doc = $this->sitemap([self::BASE]);
        (new SitemapTransformer(add: [$category]))->transform($doc);

        $this->assertStringContainsString('<loc>' . self::BASE . '/catalog/category/a&amp;b</loc>', $doc->saveXML());
        $this->assertSame([self::BASE, $category], $this->locs($this->published($doc)));
    }

    public function testLastmodComesFromTheGivenDatesOnly(): void
    {
        $article = self::BASE . '/article/view/7';
        $issue = self::BASE . '/issue/view/3';
        $about = self::BASE . '/about';
        $doc = $this->sitemap([self::BASE, $about, $issue, $article]);
        (new SitemapTransformer(lastModified: [$article => '2024-05-06', $issue => '2023-01-02']))->transform($doc);
        $xpath = $this->published($doc);

        $this->assertSame('2024-05-06', $this->lastmod($xpath, $article));
        $this->assertSame('2023-01-02', $this->lastmod($xpath, $issue));
        // No real date, no lastmod: never the current date.
        $this->assertNull($this->lastmod($xpath, $about));
        $this->assertNull($this->lastmod($xpath, self::BASE));
    }

    public function testTheHomePageAndTheArchiveTakeTheLatestDateOfTheListedPages(): void
    {
        $archive = self::BASE . '/issue/archive';
        $article = self::BASE . '/article/view/7';
        $issue = self::BASE . '/issue/view/3';
        $notListed = self::BASE . '/article/view/99';
        $doc = $this->sitemap([self::BASE, $archive, $issue, $article]);
        (new SitemapTransformer(
            lastModified: [$article => '2024-05-06', $issue => '2023-01-02', $notListed => '2025-12-31'],
            latest: [self::BASE, $archive],
        ))->transform($doc);
        $xpath = $this->published($doc);

        // An article that is not in the sitemap (not in a published issue) does not count.
        $this->assertSame('2024-05-06', $this->lastmod($xpath, self::BASE));
        $this->assertSame('2024-05-06', $this->lastmod($xpath, $archive));
        $this->assertNotContains($notListed, $this->locs($xpath));
    }

    public function testTheHomePageHasNoLastmodWithoutAnyDate(): void
    {
        $doc = $this->sitemap([self::BASE, self::BASE . '/about']);
        (new SitemapTransformer(latest: [self::BASE]))->transform($doc);

        $this->assertNull($this->lastmod($this->published($doc), self::BASE));
    }

    public function testAnExistingLastmodIsKept(): void
    {
        $doc = $this->sitemap([self::BASE . '/about']);
        $url = $doc->documentElement->firstChild;
        $url->appendChild($doc->createElement('lastmod', '2020-01-01'));
        (new SitemapTransformer(lastModified: [self::BASE . '/about' => '2024-05-06']))->transform($doc);

        $this->assertSame('2020-01-01', $this->lastmod($this->published($doc), self::BASE . '/about'));
    }

    public function testEveryPageLinksToItselfInEveryLanguage(): void
    {
        $article = self::BASE . '/article/view/7';
        $doc = $this->sitemap([self::BASE, $article]);
        (new SitemapTransformer(
            lastModified: [$article => '2024-05-06'],
            alternates: [
                'en' => 'https://example.org/journal/en',
                'pt-BR' => 'https://example.org/journal/pt_BR',
                'es' => 'https://example.org/journal/es',
            ],
            currentBase: self::BASE,
            defaultHreflang: 'pt-BR',
        ))->transform($doc);

        $xml = $doc->saveXML();
        $this->assertStringContainsString('<urlset xmlns="' . self::SITEMAP_NAMESPACE . '" xmlns:xhtml="' . SitemapTransformer::XHTML_NAMESPACE . '">', $xml);
        $this->assertSame(1, substr_count($xml, 'xmlns:xhtml='), 'The xhtml namespace is declared once, on urlset.');

        $xpath = $this->published($doc);
        $links = [];
        foreach ($xpath->query('/s:urlset/s:url[s:loc="' . $article . '"]/xhtml:link') as $link) {
            $this->assertSame('alternate', $link->getAttribute('rel'));
            $links[$link->getAttribute('hreflang')] = $link->getAttribute('href');
        }
        $this->assertSame([
            'en' => 'https://example.org/journal/en/article/view/7',
            'pt-BR' => 'https://example.org/journal/pt_BR/article/view/7',
            'es' => 'https://example.org/journal/es/article/view/7',
            'x-default' => 'https://example.org/journal/pt_BR/article/view/7',
        ], $links);
        $this->assertSame(4, $xpath->query('/s:urlset/s:url[s:loc="' . self::BASE . '"]/xhtml:link')->length);
        $this->assertSame('https://example.org/journal/es', $xpath->query('/s:urlset/s:url[s:loc="' . self::BASE . '"]/xhtml:link[@hreflang="es"]')->item(0)->getAttribute('href'));
    }

    public function testALargeJournalIsChangedQuickly(): void
    {
        // 11,000 addresses and three languages (44,000 links): namespaced elements took
        // 13 s here, longer than a PHP request of a hosting account may run.
        $locs = array_map(fn (int $i) => self::BASE . '/article/view/' . $i, range(1, 11000));
        $doc = $this->sitemap($locs);
        $start = microtime(true);
        (new SitemapTransformer(
            lastModified: array_fill_keys($locs, '2024-05-06'),
            alternates: ['en' => self::BASE, 'es' => 'https://example.org/journal/es', 'pt-BR' => 'https://example.org/journal/pt_BR'],
            currentBase: self::BASE,
            defaultHreflang: 'pt-BR',
        ))->transform($doc);

        $this->assertLessThan(3, microtime(true) - $start);
        $this->assertSame(44000, $this->published($doc)->query('//xhtml:link')->length);
    }

    public function testAJournalWithOneLanguageGetsNoLanguageLinks(): void
    {
        $doc = $this->sitemap([self::BASE]);
        (new SitemapTransformer(alternates: ['en' => self::BASE], currentBase: self::BASE, defaultHreflang: 'en'))->transform($doc);

        $this->assertStringNotContainsString('xhtml', $doc->saveXML());
    }

    public function testAUrlOutsideTheJournalGetsNoLanguageLinks(): void
    {
        $outside = 'https://example.org/journal/english-page';
        $doc = $this->sitemap([$outside]);
        (new SitemapTransformer(
            alternates: ['en' => self::BASE, 'es' => 'https://example.org/journal/es'],
            currentBase: self::BASE,
        ))->transform($doc);

        $this->assertSame(0, $this->published($doc)->query('//xhtml:link')->length);
    }

    public function testHreflangOfPkpLocales(): void
    {
        $this->assertSame('pt-BR', SitemapTransformer::hreflang('pt_BR'));
        $this->assertSame('en', SitemapTransformer::hreflang('en'));
        $this->assertSame('sr-Latn', SitemapTransformer::hreflang('sr_Latn'));
        $this->assertSame('sr', SitemapTransformer::hreflang('sr@latin'));
        $this->assertSame('zh-Hans', SitemapTransformer::hreflang('zh_Hans'));
    }

    public function testDatesAreReducedToTheDay(): void
    {
        $this->assertSame('2024-05-06', SitemapTransformer::date('2024-05-06 10:11:12'));
        $this->assertSame('2024-05-06', SitemapTransformer::date('2024-05-06'));
        $this->assertNull(SitemapTransformer::date(null));
        $this->assertNull(SitemapTransformer::date(''));
        $this->assertNull(SitemapTransformer::date('0000-00-00 00:00:00'));
        $this->assertNull(SitemapTransformer::date('2024-02-30'));
        $this->assertNull(SitemapTransformer::date('yesterday'));
    }
}
