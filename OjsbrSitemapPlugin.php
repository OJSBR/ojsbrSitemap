<?php

/**
 * @file plugins/generic/ojsbrSitemap/OjsbrSitemapPlugin.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class OjsbrSitemapPlugin
 *
 * @brief Improves the sitemap of an OJS journal for search engines.
 *
 *        Removed: login, registration, search, current issue (the same page as
 *        the latest issue) and the galleys, which are files or viewers of the
 *        article page already listed.
 *        Added: lastmod from the real dates of the content, the editorial
 *        masthead, the privacy statement, the categories and, in multilingual
 *        journals, the same page in each language (xhtml:link hreflang).
 *
 *        The sitemap of a large journal takes longer to build than a PHP request
 *        may run, so a scheduled task generates it ahead of time, one file per
 *        language, and the page serves the file.
 */

namespace APP\plugins\generic\ojsbrSitemap;

use APP\core\Application;
use APP\core\PageRouter;
use APP\core\Request;
use APP\facades\Repo;
use APP\journal\Journal;
use APP\pages\sitemap\SitemapHandler;
use APP\plugins\generic\ojsbrSitemap\classes\SitemapCache;
use APP\plugins\generic\ojsbrSitemap\classes\SitemapPageHandler;
use APP\plugins\generic\ojsbrSitemap\classes\SitemapTask;
use APP\plugins\generic\ojsbrSitemap\classes\SitemapTransformer;
use APP\submission\Submission;
use DOMDocument;
use Illuminate\Support\Facades\DB;
use PKP\announcement\Announcement;
use PKP\core\Core;
use PKP\core\Dispatcher;
use PKP\core\Registry;
use PKP\facades\Locale;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\plugins\interfaces\HasTaskScheduler;
use PKP\scheduledTask\PKPScheduler;

class OjsbrSitemapPlugin extends GenericPlugin implements HasTaskScheduler
{
    public function register($category, $path, $mainContextId = null): bool
    {
        $success = parent::register($category, $path, $mainContextId);
        if (Application::isUnderMaintenance()) {
            return $success;
        }

        // Only the journal sitemap page is changed, so the hook is registered only
        // where the plugin is enabled.
        if ($success && Application::get()->getName() === 'ojs2' && $this->getEnabled($mainContextId)) {
            Hook::add('SitemapHandler::createJournalSitemap', $this->changeSitemap(...));
            Hook::add('LoadHandler', $this->loadSitemapHandler(...));
        }

        return $success;
    }

    public function getDisplayName(): string
    {
        return __('plugins.generic.ojsbrSitemap.displayName');
    }

    public function getDescription(): string
    {
        return __('plugins.generic.ojsbrSitemap.description');
    }

    /**
     * Check the sitemaps four times an hour, away from minute 0, when the daily
     * tasks of OJS run: a run of the scheduler may be skipped, and the check is a
     * light query; a sitemap is generated only when its content changed. The task
     * itself checks in which journals the plugin is enabled: no context is known
     * when the schedules are registered.
     */
    public function registerSchedules(PKPScheduler $scheduler): void
    {
        $scheduler
            ->addSchedule(new SitemapTask())
            ->cron('5,20,35,50 * * * *')
            ->name(SitemapTask::class)
            ->withoutOverlapping();
    }

    /**
     * Serve the journal sitemap through the plugin's handler, which sends the
     * generated file when there is one.
     *
     * @hook LoadHandler [[&$page, &$op, &$sourceFile, &$handler]]
     */
    public function loadSitemapHandler(string $hookName, array $args): bool
    {
        [$page, $op] = $args;
        if ($page !== 'sitemap' || !in_array($op, ['', 'index'], true) || !Application::get()->getRequest()->getContext()) {
            return Hook::CONTINUE;
        }
        $args[3] = new SitemapPageHandler($this);
        return Hook::ABORT;
    }

    public function getCache(): SitemapCache
    {
        return new SitemapCache(Core::getBaseDir() . '/cache/ojsbrSitemap');
    }

    /**
     * The sitemap of a journal in a language, as the sitemap page builds it with the
     * plugin enabled. Meant for the command line: it sets up the request of the
     * sitemap page of the journal in that language.
     */
    public function generateSitemap(Journal $journal, string $locale): string
    {
        $request = $this->sitemapRequest($journal, $locale);
        $doc = (new SitemapHandler())->_createContextSitemap($request);
        // The hook already changed the document when the plugin was registered for
        // this journal; the changes are made only once.
        $this->getTransformer($request, $journal)->transform($doc);
        return $doc->saveXML();
    }

    /**
     * The state of the content the sitemap is built from: when it changes, the
     * sitemap is generated again.
     */
    public function getSignature(Journal $journal): string
    {
        $articles = DB::table('submissions AS s')
            ->join('publications AS p', 'p.publication_id', '=', 's.current_publication_id')
            ->where('s.context_id', $journal->getId())
            ->where('s.status', Submission::STATUS_PUBLISHED)
            ->selectRaw('COUNT(*) AS c, MAX(p.last_modified) AS m, MAX(p.date_published) AS d')
            ->first();
        $issues = DB::table('issues')
            ->where('journal_id', $journal->getId())
            ->where('published', 1)
            ->selectRaw('COUNT(*) AS c, MAX(last_modified) AS m, MAX(date_published) AS d')
            ->first();
        $announcements = Announcement::withContextIds([$journal->getId()])->count();
        return sha1(json_encode([
            // A new version of the plugin may change the sitemap.
            sha1_file(__FILE__) . sha1_file(__DIR__ . '/classes/SitemapTransformer.php'),
            $journal->getSupportedLocales(),
            $articles,
            $issues,
            $announcements,
        ]));
    }

    /**
     * The request of the sitemap page of a journal in a language, from the command line.
     */
    protected function sitemapRequest(Journal $journal, string $locale): Request
    {
        Registry::delete('request');
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['PATH_INFO'] = '/' . $journal->getPath() . '/' . $locale . '/sitemap';
        $application = Application::get();
        $request = $application->getRequest();
        $router = new PageRouter();
        $router->setApplication($application);
        $dispatcher = new Dispatcher();
        $dispatcher->addRouterName('\\APP\\core\\PageRouter', Application::ROUTE_PAGE);
        $dispatcher->setApplication($application);
        $router->setDispatcher($dispatcher);
        $request->setRouter($router);
        Locale::setLocale($locale);
        return $request;
    }

    /**
     * Change the sitemap of the journal.
     *
     * @hook SitemapHandler::createJournalSitemap [[&$doc]]
     */
    public function changeSitemap(string $hookName, array $args): bool
    {
        $doc = $args[0];
        $request = Application::get()->getRequest();
        $journal = $request->getContext();
        if (!$doc instanceof DOMDocument || !$journal instanceof Journal) {
            return Hook::CONTINUE;
        }

        // Everything is read before the document is touched: when reading fails the
        // sitemap of OJS is served as it is.
        try {
            $transformer = $this->getTransformer($request, $journal);
        } catch (\Throwable $e) {
            error_log('ojsbrSitemap: the sitemap of journal ' . $journal->getId() . ' was not changed: ' . $e->getMessage());
            return Hook::CONTINUE;
        }
        $transformer->transform($doc);

        return Hook::CONTINUE;
    }

    /**
     * Read what the transformer needs. The URLs are built by the router of OJS,
     * as OJS builds the sitemap, so they match exactly.
     */
    public function getTransformer(Request $request, Journal $journal): SitemapTransformer
    {
        $path = $journal->getPath();
        $url = fn (?string $page = null, ?string $op = null, ?array $args = null) => $request->url($path, $page, $op, $args);
        $articleUrl = $this->urlTemplate($request, $path, 'article', 'view', 1);
        $galleyUrl = $this->urlTemplate($request, $path, 'article', 'view', 2);
        $pdfUrl = $this->urlTemplate($request, $path, 'article', 'download', 2, '');
        $issueUrl = $this->urlTemplate($request, $path, 'issue', 'view', 1);
        $announcementUrl = $this->urlTemplate($request, $path, 'announcement', 'view', 1);

        $remove = [$url('login'), $url('user', 'register'), $url('search'), $url('issue', 'current')];
        $lastModified = [];

        foreach ($this->getArticles($journal) as $article) {
            $date = SitemapTransformer::date($article->last_modified) ?? SitemapTransformer::date($article->date_published);
            if ($date !== null) {
                $lastModified[$articleUrl($this->bestId($article->url_path, $article->submission_id))] = $date;
            }
        }

        // OJS 3.5.0-6 lists every galley as article/view/<article>/<galley>; later 3.5
        // releases list a PDF as article/download/<article>/<galley>, without the
        // language. Both forms are removed.
        foreach ($this->getGalleys($journal) as $galley) {
            $ids = [
                $this->bestId($galley->publication_url_path, $galley->submission_id),
                $this->bestId($galley->url_path, $galley->galley_id),
            ];
            $remove[] = $galleyUrl(...$ids);
            $remove[] = $pdfUrl(...$ids);
        }

        foreach ($this->getIssues($journal) as $issue) {
            $date = SitemapTransformer::date($issue->last_modified) ?? SitemapTransformer::date($issue->date_published);
            if ($date !== null) {
                $lastModified[$issueUrl($this->bestId($issue->url_path, $issue->issue_id))] = $date;
            }
        }

        $announcementKey = (new Announcement())->getKeyName();
        foreach (Announcement::withContextIds([$journal->getId()])->get([$announcementKey, Announcement::CREATED_AT]) as $announcement) {
            $date = SitemapTransformer::date((string) $announcement->getRawOriginal(Announcement::CREATED_AT));
            if ($date !== null) {
                $lastModified[$announcementUrl((string) $announcement->getKey())] = $date;
            }
        }

        // The editorial masthead page always has content: the list of the editors,
        // or the editorial history when the list is turned off.
        $add = [$url('about', 'editorialMasthead')];
        if ($this->hasText($journal->getData('privacyStatement'))) {
            $add[] = $url('about', 'privacy');
        }
        $categories = Repo::category()->getCollector()->filterByContextIds([$journal->getId()])->getMany();
        foreach ($categories as $category) {
            $add[] = $url('catalog', 'category', [$category->getPath()]);
        }

        $alternates = [];
        $locales = $journal->getSupportedLocales();
        if (count($locales) > 1) {
            foreach ($locales as $locale) {
                $alternates[SitemapTransformer::hreflang($locale)] = $request->getDispatcher()->url($request, Application::ROUTE_PAGE, $path, urlLocaleForPage: $locale);
            }
        }

        return new SitemapTransformer(
            remove: $remove,
            lastModified: $lastModified,
            add: $add,
            latest: [$url(), $url('issue', 'archive')],
            alternates: $alternates,
            currentBase: $url(),
            defaultHreflang: SitemapTransformer::hreflang($journal->getPrimaryLocale()),
        );
    }

    /**
     * A function that gives the URL of a page with the given ids in its path.
     *
     * The router builds the URL once, with markers in place of the ids, and the
     * markers are replaced by the ids encoded as the router encodes each part of
     * the path (rawurlencode). A large journal has thousands of articles and
     * galleys, and building each URL with the router took longer than the time
     * a PHP request is allowed to run.
     */
    public function urlTemplate(Request $request, string $path, string $page, string $op, int $ids, ?string $urlLocaleForPage = null): \Closure
    {
        $markers = array_map(fn (int $i) => "ojsbrSitemapId{$i}x", range(1, $ids));
        $template = $request->getDispatcher()->url($request, Application::ROUTE_PAGE, $path, $page, $op, $markers, urlLocaleForPage: $urlLocaleForPage);
        return fn (string ...$values) => strtr($template, array_combine($markers, array_map(rawurlencode(...), $values)));
    }

    /**
     * The current publication of each published article, in one query instead of
     * one per article.
     */
    protected function getArticles(Journal $journal): iterable
    {
        return DB::table('submissions AS s')
            ->join('publications AS p', 'p.publication_id', '=', 's.current_publication_id')
            ->where('s.context_id', $journal->getId())
            ->where('s.status', Submission::STATUS_PUBLISHED)
            ->get(['s.submission_id', 'p.url_path', 'p.last_modified', 'p.date_published']);
    }

    /**
     * The galleys of the current publication of each published article.
     */
    protected function getGalleys(Journal $journal): iterable
    {
        return DB::table('publication_galleys AS g')
            ->join('submissions AS s', 's.current_publication_id', '=', 'g.publication_id')
            ->join('publications AS p', 'p.publication_id', '=', 'g.publication_id')
            ->where('s.context_id', $journal->getId())
            ->where('s.status', Submission::STATUS_PUBLISHED)
            ->get(['s.submission_id', 'p.url_path AS publication_url_path', 'g.galley_id', 'g.url_path']);
    }

    protected function getIssues(Journal $journal): iterable
    {
        return DB::table('issues')
            ->where('journal_id', $journal->getId())
            ->where('published', 1)
            ->get(['issue_id', 'url_path', 'last_modified', 'date_published']);
    }

    /**
     * The id OJS puts in the URL: the URL path when there is one, the id otherwise
     * (as Submission::getBestId(), Issue::getBestIssueId() and Galley::getBestGalleyId()).
     */
    protected function bestId(?string $urlPath, int|string $id): string
    {
        return strlen((string) $urlPath) ? (string) $urlPath : (string) $id;
    }

    /**
     * Whether a localized setting has text in any language.
     */
    protected function hasText(mixed $value): bool
    {
        foreach ((array) $value as $text) {
            if (trim(strip_tags((string) $text)) !== '') {
                return true;
            }
        }
        return false;
    }
}

if (!PKP_STRICT_MODE) {
    class_alias('\APP\plugins\generic\ojsbrSitemap\OjsbrSitemapPlugin', '\OjsbrSitemapPlugin');
}
