<?php

/**
 * @file plugins/generic/ojsbrSitemap/tests/OjsbrSitemapTest.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class OjsbrSitemapTest
 *
 * @brief The sitemap OJS builds for a journal of the database, with the plugin:
 *        pages removed and added, lastmod against the database and the language
 *        links.
 *
 *        Run with OJSBR_SITEMAP_WITHOUT=1 to run it without the plugin: every
 *        test must then fail.
 */

namespace APP\plugins\generic\ojsbrSitemap\tests;

use APP\core\Application;
use APP\core\Request;
use APP\pages\sitemap\SitemapHandler;
use APP\plugins\generic\ojsbrSitemap\classes\SitemapTransformer;
use APP\plugins\generic\ojsbrSitemap\OjsbrSitemapPlugin;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PKP\core\Registry;
use PKP\tests\PKPTestCase;

#[CoversClass(OjsbrSitemapPlugin::class)]
class OjsbrSitemapTest extends PKPTestCase
{
    protected static bool $registered = false;

    /** @var ?object journal_id and path of a journal with a published article in a published issue */
    protected ?object $journal = null;

    protected mixed $previousEnabled = null;

    protected Request $request;

    protected function setUp(): void
    {
        parent::setUp();
        if (Application::get()->getName() !== 'ojs2') {
            $this->markTestSkipped('The plugin is for OJS.');
        }
        $this->journal = DB::table('submissions AS s')
            ->join('publications AS p', 'p.publication_id', '=', 's.current_publication_id')
            ->join('issues AS i', 'i.issue_id', '=', 'p.issue_id')
            ->join('journals AS j', 'j.journal_id', '=', 's.context_id')
            ->where('s.status', 3)
            ->where('i.published', 1)
            ->orderBy('j.journal_id')
            ->select('j.journal_id', 'j.path')
            ->first();
        if (!$this->journal) {
            $this->markTestSkipped('No journal with a published article in this database.');
        }

        Registry::delete('user');
        $this->request = $this->mockRequest($this->journal->path . '/sitemap');
        $plugin = new OjsbrSitemapPlugin();
        $this->previousEnabled = $plugin->getSetting($this->journal->journal_id, 'enabled');
        $plugin->updateSetting($this->journal->journal_id, 'enabled', true, 'bool');
        if (!getenv('OJSBR_SITEMAP_WITHOUT') && !self::$registered) {
            $plugin->register('generic', 'plugins/generic/ojsbrSitemap', $this->journal->journal_id);
            self::$registered = true;
        }
    }

    protected function tearDown(): void
    {
        if ($this->journal) {
            (new OjsbrSitemapPlugin())->updateSetting($this->journal->journal_id, 'enabled', (bool) $this->previousEnabled, 'bool');
        }
        parent::tearDown();
    }

    /**
     * The sitemap of the journal, serialized and parsed again as a search engine reads it.
     */
    protected function sitemap(): DOMXPath
    {
        $doc = (new SitemapHandler())->_createContextSitemap($this->request);
        $parsed = new DOMDocument();
        $this->assertTrue($parsed->loadXML($doc->saveXML()), 'The sitemap is not well-formed XML.');
        $this->assertTrue($parsed->schemaValidate(__DIR__ . '/fixtures/sitemap-structure.xsd'), 'The sitemap breaks the structure of the sitemap protocol.');
        $xpath = new DOMXPath($parsed);
        $xpath->registerNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $xpath->registerNamespace('xhtml', SitemapTransformer::XHTML_NAMESPACE);
        return $xpath;
    }

    /** @return string[] */
    protected function locs(DOMXPath $xpath): array
    {
        return array_map(fn ($node) => $node->textContent, iterator_to_array($xpath->query('/s:urlset/s:url/s:loc')));
    }

    protected function url(string ...$parts): string
    {
        $page = $parts[0] ?? null;
        $op = $parts[1] ?? null;
        $args = array_slice($parts, 2) ?: null;
        return $this->request->url($this->journal->path, $page, $op, $args);
    }

    public function testPagesWithoutContentForSearchEnginesAreRemoved(): void
    {
        $locs = $this->locs($this->sitemap());

        $this->assertContains($this->url(), $locs);
        foreach ([$this->url('login'), $this->url('user', 'register'), $this->url('search'), $this->url('issue', 'current')] as $loc) {
            $this->assertNotContains($loc, $locs);
        }
        $galleys = DB::table('publication_galleys AS g')
            ->join('submissions AS s', 's.current_publication_id', '=', 'g.publication_id')
            ->where('s.context_id', $this->journal->journal_id)
            ->where('s.status', 3)
            ->count();
        $this->assertGreaterThan(0, $galleys, 'The journal has no galley to remove.');
        foreach ($locs as $loc) {
            $this->assertDoesNotMatchRegularExpression('#/article/view/[^/]+/[^/]+$#', $loc, 'A galley is still listed.');
        }
    }

    public function testTheEditorialMastheadIsAddedAndNoUrlIsListedTwice(): void
    {
        $locs = $this->locs($this->sitemap());

        $this->assertContains($this->url('about', 'editorialMasthead'), $locs);
        $this->assertSame(count($locs), count(array_unique($locs)));
    }

    public function testLastmodOfAnArticleAndAnIssueIsTheirModificationDate(): void
    {
        $xpath = $this->sitemap();
        $article = DB::table('submissions AS s')
            ->join('publications AS p', 'p.publication_id', '=', 's.current_publication_id')
            ->join('issues AS i', 'i.issue_id', '=', 'p.issue_id')
            ->where('s.context_id', $this->journal->journal_id)
            ->where('s.status', 3)
            ->where('i.published', 1)
            ->orderBy('s.submission_id')
            ->select('s.submission_id', 'p.url_path', 'p.last_modified', 'p.date_published', 'i.issue_id', 'i.url_path AS issue_url_path', 'i.last_modified AS issue_last_modified', 'i.date_published AS issue_date_published')
            ->first();

        $loc = $this->url('article', 'view', strlen((string) $article->url_path) ? $article->url_path : (string) $article->submission_id);
        $expected = SitemapTransformer::date($article->last_modified) ?? SitemapTransformer::date($article->date_published);
        $this->assertSame($expected, $xpath->evaluate('string(/s:urlset/s:url[s:loc="' . $loc . '"]/s:lastmod)'), $loc);

        $loc = $this->url('issue', 'view', strlen((string) $article->issue_url_path) ? $article->issue_url_path : (string) $article->issue_id);
        $expected = SitemapTransformer::date($article->issue_last_modified) ?? SitemapTransformer::date($article->issue_date_published);
        $this->assertSame($expected, $xpath->evaluate('string(/s:urlset/s:url[s:loc="' . $loc . '"]/s:lastmod)'), $loc);

        $this->assertNotSame('', $xpath->evaluate('string(/s:urlset/s:url[s:loc="' . $this->url() . '"]/s:lastmod)'), 'The home page has no lastmod.');
    }

    public function testEveryPageLinksToItselfInTheOtherLanguages(): void
    {
        $locales = Application::getContextDAO()->getById($this->journal->journal_id)->getSupportedLocales();
        if (count($locales) < 2) {
            $this->markTestSkipped('The journal has one language.');
        }
        $xpath = $this->sitemap();
        $urls = $xpath->query('/s:urlset/s:url')->length;

        $this->assertSame($urls * (count($locales) + 1), $xpath->query('/s:urlset/s:url/xhtml:link[@rel="alternate"]')->length);
        $this->assertSame($urls, $xpath->query('/s:urlset/s:url/xhtml:link[@hreflang="x-default"]')->length);
        foreach ($locales as $locale) {
            $this->assertSame($urls, $xpath->query('/s:urlset/s:url/xhtml:link[@hreflang="' . SitemapTransformer::hreflang($locale) . '"]')->length, $locale);
        }
    }
}
