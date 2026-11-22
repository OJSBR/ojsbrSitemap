<?php

/**
 * @file plugins/generic/ojsbrSitemap/tests/SitemapCacheTest.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class SitemapCacheTest
 *
 * @brief The files of the generated sitemaps: when one is served, when the
 *        sitemaps of a journal are generated again. No database is needed.
 */

namespace APP\plugins\generic\ojsbrSitemap\tests;

use APP\plugins\generic\ojsbrSitemap\classes\SitemapCache;
use PHPUnit\Framework\Attributes\CoversClass;
use PKP\tests\PKPTestCase;

#[CoversClass(SitemapCache::class)]
class SitemapCacheTest extends PKPTestCase
{
    protected string $dir;

    protected SitemapCache $cache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/ojsbrSitemapTest' . getmypid();
        $this->cache = new SitemapCache($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*/*') ?: [] as $file) {
            unlink($file);
        }
        foreach (glob($this->dir . '/*') ?: [] as $dir) {
            rmdir($dir);
        }
        is_dir($this->dir) && rmdir($this->dir);
        parent::tearDown();
    }

    public function testAStoredSitemapIsServedUntilItIsAWeekOld(): void
    {
        $this->assertNull($this->cache->fresh(1, 'en'));
        $this->cache->store(1, 'en', '<urlset/>');
        $file = $this->cache->fresh(1, 'en');

        $this->assertSame($this->cache->file(1, 'en'), $file);
        $this->assertSame('<urlset/>', file_get_contents($file));
        $this->assertNull($this->cache->fresh(1, 'es'), 'Another language has no file.');
        $this->assertNull($this->cache->fresh(2, 'en'), 'Another journal has no file.');
        $this->assertNotNull($this->cache->fresh(1, 'en', time() + SitemapCache::MAX_AGE_SECONDS));
        $this->assertNull($this->cache->fresh(1, 'en', time() + SitemapCache::MAX_AGE_SECONDS + 60), 'A file the task stopped updating is not served.');
        $this->assertSame([], glob($this->dir . '/1/*.tmp'), 'No temporary file is left.');
    }

    public function testAnEmptyFileIsNotServed(): void
    {
        $this->cache->store(1, 'en', '');

        $this->assertNull($this->cache->fresh(1, 'en'));
    }

    public function testALocaleCannotLeaveTheDirectory(): void
    {
        $this->assertSame($this->dir . '/1/etcpasswd.xml', $this->cache->file(1, '../../etc/passwd'));
        $this->assertSame($this->dir . '/1/pt_BR.xml', $this->cache->file(1, 'pt_BR'));
    }

    public function testTheSitemapsAreGeneratedAgainWhenTheContentChangesOrOnceADay(): void
    {
        $locales = ['en', 'pt_BR'];
        $this->assertTrue($this->cache->isStale(1, $locales, 'a'), 'Never generated.');

        $now = time();
        foreach ($locales as $locale) {
            $this->cache->store(1, $locale, '<urlset/>');
        }
        $this->cache->storeState(1, 'a', $now);

        $this->assertFalse($this->cache->isStale(1, $locales, 'a', $now + 3600));
        $this->assertTrue($this->cache->isStale(1, $locales, 'b', $now + 3600), 'The content changed.');
        $this->assertTrue($this->cache->isStale(1, $locales, 'a', $now + SitemapCache::REFRESH_SECONDS), 'A day old.');
        $this->assertTrue($this->cache->isStale(1, ['en', 'pt_BR', 'es'], 'a', $now + 3600), 'A language was added.');
    }

    public function testOnlyTheLanguagesOfTheJournalAreKept(): void
    {
        foreach (['en', 'es', 'pt_BR'] as $locale) {
            $this->cache->store(1, $locale, '<urlset/>');
        }
        $this->cache->store(2, 'es', '<urlset/>');
        $this->cache->keepOnly(1, ['en', 'pt_BR']);

        $this->assertNotNull($this->cache->fresh(1, 'en'));
        $this->assertNotNull($this->cache->fresh(1, 'pt_BR'));
        $this->assertNull($this->cache->fresh(1, 'es'));
        $this->assertNotNull($this->cache->fresh(2, 'es'), 'Another journal is not touched.');

        $this->cache->clear(1);
        $this->assertNull($this->cache->fresh(1, 'en'));
        $this->assertNotNull($this->cache->fresh(2, 'es'));
    }
}
