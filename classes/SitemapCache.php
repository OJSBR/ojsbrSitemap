<?php

/**
 * @file plugins/generic/ojsbrSitemap/classes/SitemapCache.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class SitemapCache
 *
 * @brief The sitemaps generated ahead of time, one file per journal and language,
 *        with the state of the content they were generated from.
 */

namespace APP\plugins\generic\ojsbrSitemap\classes;

class SitemapCache
{
    /** A sitemap is regenerated at least this often, even when no content changed. */
    public const REFRESH_SECONDS = 86400;

    /** A file older than this is not served: the scheduled task is not running. */
    public const MAX_AGE_SECONDS = 7 * 86400;

    public function __construct(protected string $dir)
    {
    }

    public function file(int $contextId, string $locale): string
    {
        return "{$this->dir}/{$contextId}/" . preg_replace('/[^A-Za-z0-9_@]/', '', $locale) . '.xml';
    }

    protected function stateFile(int $contextId): string
    {
        return "{$this->dir}/{$contextId}/state.json";
    }

    /**
     * The path of the sitemap to serve, or null when there is none or it is too old.
     */
    public function fresh(int $contextId, string $locale, ?int $now = null): ?string
    {
        $file = $this->file($contextId, $locale);
        clearstatcache(true, $file);
        if (!is_file($file) || filesize($file) === 0) {
            return null;
        }
        return ($now ?? time()) - filemtime($file) <= self::MAX_AGE_SECONDS ? $file : null;
    }

    /**
     * Whether the sitemaps of a journal must be generated again: a language has no
     * file, the content changed since they were generated, or they are a day old.
     *
     * @param string[] $locales
     */
    public function isStale(int $contextId, array $locales, string $signature, ?int $now = null): bool
    {
        $state = $this->state($contextId);
        if (($state['signature'] ?? null) !== $signature || ($now ?? time()) - ($state['generated'] ?? 0) >= self::REFRESH_SECONDS) {
            return true;
        }
        foreach ($locales as $locale) {
            if (!is_file($this->file($contextId, $locale))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Store the sitemap of one language. The file is written next to its final name
     * and renamed, so a request never reads a file being written.
     */
    public function store(int $contextId, string $locale, string $xml): void
    {
        $file = $this->file($contextId, $locale);
        $this->makeDir(dirname($file));
        $temporary = $file . '.' . getmypid() . '.tmp';
        if (file_put_contents($temporary, $xml) !== strlen($xml) || !rename($temporary, $file)) {
            @unlink($temporary);
            throw new \RuntimeException("The sitemap could not be written to {$file}.");
        }
    }

    /**
     * Record the content the sitemaps of a journal were generated from.
     */
    public function storeState(int $contextId, string $signature, ?int $now = null): void
    {
        $this->makeDir("{$this->dir}/{$contextId}");
        file_put_contents($this->stateFile($contextId), json_encode(['signature' => $signature, 'generated' => $now ?? time()]));
    }

    /**
     * Remove the sitemaps of a journal (plugin turned off, language removed).
     */
    public function clear(int $contextId): void
    {
        foreach (glob("{$this->dir}/{$contextId}/*") ?: [] as $file) {
            unlink($file);
        }
    }

    /**
     * Remove the sitemaps of languages the journal no longer has.
     *
     * @param string[] $locales
     */
    public function keepOnly(int $contextId, array $locales): void
    {
        $keep = array_map(fn (string $locale) => $this->file($contextId, $locale), $locales);
        foreach (glob("{$this->dir}/{$contextId}/*.xml") ?: [] as $file) {
            if (!in_array($file, $keep, true)) {
                unlink($file);
            }
        }
    }

    protected function state(int $contextId): array
    {
        $file = $this->stateFile($contextId);
        $state = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        return is_array($state) ? $state : [];
    }

    protected function makeDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("The directory {$dir} could not be created.");
        }
    }
}
