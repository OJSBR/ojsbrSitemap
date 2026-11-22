<?php

/**
 * @file plugins/generic/ojsbrSitemap/classes/SitemapTask.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class SitemapTask
 *
 * @brief Generates the sitemaps of the journals where the plugin is enabled, one
 *        file per language, when their content changed or once a day.
 *
 *        Generating the sitemap of a large journal takes longer than a PHP request
 *        may run (34 to 44 s for 5,400 articles). Run by the scheduler from the
 *        command line, it has no such limit, and the page serves the file at once.
 */

namespace APP\plugins\generic\ojsbrSitemap\classes;

use APP\core\Application;
use APP\journal\Journal;
use APP\plugins\generic\ojsbrSitemap\OjsbrSitemapPlugin;
use PKP\plugins\PluginRegistry;
use PKP\scheduledTask\ScheduledTask;
use PKP\scheduledTask\ScheduledTaskHelper;

class SitemapTask extends ScheduledTask
{
    public function getName(): string
    {
        return __('plugins.generic.ojsbrSitemap.task');
    }

    protected function executeActions(): bool
    {
        $plugin = $this->getPlugin();
        $cache = $plugin->getCache();
        $success = true;
        foreach (Application::getContextDAO()->getAll(true)->toIterator() as $journal) { /** @var Journal $journal */
            // The scheduler registers the plugin without a context: it is enabled or
            // not per journal (pkp/pkp-lib#11793).
            if (!$plugin->getEnabled($journal->getId())) {
                $cache->clear($journal->getId());
                continue;
            }
            try {
                $this->generate($plugin, $cache, $journal);
            } catch (\Throwable $e) {
                $success = false;
                $this->addExecutionLogEntry($journal->getPath() . ': ' . $e->getMessage(), ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_ERROR);
            }
        }
        return $success;
    }

    /**
     * Generate the sitemaps of a journal when they are stale.
     */
    protected function generate(OjsbrSitemapPlugin $plugin, SitemapCache $cache, Journal $journal): void
    {
        $locales = $journal->getSupportedLocales();
        $signature = $plugin->getSignature($journal);
        if (!$cache->isStale($journal->getId(), $locales, $signature)) {
            return;
        }
        // Each file is replaced when its new version is ready: the old one is served
        // meanwhile.
        foreach ($locales as $locale) {
            $cache->store($journal->getId(), $locale, $plugin->generateSitemap($journal, $locale));
        }
        $cache->keepOnly($journal->getId(), $locales);
        $cache->storeState($journal->getId(), $signature);
        $this->addExecutionLogEntry($journal->getPath() . ': ' . implode(', ', $locales), ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_COMPLETED);
    }

    protected function getPlugin(): OjsbrSitemapPlugin
    {
        $plugin = PluginRegistry::getPlugin('generic', 'ojsbrsitemapplugin');
        return $plugin instanceof OjsbrSitemapPlugin ? $plugin : new OjsbrSitemapPlugin();
    }
}
