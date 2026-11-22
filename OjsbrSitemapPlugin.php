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
 */

namespace APP\plugins\generic\ojsbrSitemap;

use APP\core\Application;
use APP\core\Request;
use APP\facades\Repo;
use APP\journal\Journal;
use APP\plugins\generic\ojsbrSitemap\classes\SitemapTransformer;
use APP\submission\Submission;
use DOMDocument;
use Illuminate\Support\Facades\DB;
use PKP\announcement\Announcement;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;

class OjsbrSitemapPlugin extends GenericPlugin
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
     * Read what the transformer needs. The URLs are built with the same calls
     * OJS uses to build the sitemap, so they match exactly.
     */
    public function getTransformer(Request $request, Journal $journal): SitemapTransformer
    {
        $path = $journal->getPath();
        $url = fn (?string $page = null, ?string $op = null, ?array $args = null) => $request->url($path, $page, $op, $args);

        $remove = [$url('login'), $url('user', 'register'), $url('search'), $url('issue', 'current')];
        $lastModified = [];

        foreach ($this->getArticles($journal) as $article) {
            $date = SitemapTransformer::date($article->last_modified) ?? SitemapTransformer::date($article->date_published);
            if ($date !== null) {
                $lastModified[$url('article', 'view', [$this->bestId($article->url_path, $article->submission_id)])] = $date;
            }
        }

        foreach ($this->getGalleys($journal) as $galley) {
            $remove[] = $url('article', 'view', [
                $this->bestId($galley->publication_url_path, $galley->submission_id),
                $this->bestId($galley->url_path, $galley->galley_id),
            ]);
        }

        foreach ($this->getIssues($journal) as $issue) {
            $date = SitemapTransformer::date($issue->last_modified) ?? SitemapTransformer::date($issue->date_published);
            if ($date !== null) {
                $lastModified[$url('issue', 'view', [$this->bestId($issue->url_path, $issue->issue_id)])] = $date;
            }
        }

        $announcementKey = (new Announcement())->getKeyName();
        foreach (Announcement::withContextIds([$journal->getId()])->get([$announcementKey, Announcement::CREATED_AT]) as $announcement) {
            $date = SitemapTransformer::date((string) $announcement->getRawOriginal(Announcement::CREATED_AT));
            if ($date !== null) {
                $lastModified[$url('announcement', 'view', [$announcement->getKey()])] = $date;
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
