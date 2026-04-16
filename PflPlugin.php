<?php

/**
 * @file PflPlugin.php
 *
 * Copyright (c) 2023-2024 Simon Fraser University
 * Copyright (c) 2023-2024 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @brief Publication Facts Label plugin
 */

namespace APP\plugins\generic\pflPlugin;

use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use PKP\facades\Locale;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\plugins\PluginRegistry;
use PKP\linkAction\request\AjaxModal;
use PKP\linkAction\LinkAction;
use PKP\core\PKPString;
use APP\core\Application;
use PKP\core\JSONMessage;
use PKP\db\DAORegistry;
use PKP\submission\PKPSubmission;
use APP\template\TemplateManager;

class PflPlugin extends GenericPlugin {
    /**
     * @copydoc Plugin::register()
     */
    function register($category, $path, $mainContextId = null) {
        if (parent::register($category, $path, $mainContextId)) {
            if ($this->getEnabled($mainContextId)) {
                // Display the PFL in the article landing page.
                Hook::add('Templates::Article::Details', $this->displayArticlePfl(...));

                // Add the author CI statements to the author list
                Hook::add('TemplateManager::display', $this->handleTemplateDisplay(...));
            }
            return true;
        }
        return false;
    }

    /**
     * Get the display name of this plugin.
     * @return String
     */
    function getDisplayName() {
        return __('plugins.generic.pfl.displayName');
    }

    /**
     * Get a description of the plugin.
     */
    function getDescription() {
        return __('plugins.generic.pfl.description');
    }

    /**
     * Get the number of published reviewable submissions for a given journal.
     */
    function getPublishedReviewableSubmissionCount(int $journalId, ?string $dateStart = null): int
        {
        try {
            $row = DB::table('submissions AS s')
                ->select([DB::raw('COUNT(*) AS submission_count')])
                ->join('publications AS p', 's.current_publication_id', '=', 'p.publication_id')
                ->join('sections AS sec', 'p.section_id', '=', 'sec.section_id')
                ->where('s.context_id', $journalId)
                ->where('sec.meta_reviewed', 1)
                ->where('s.status', PKPSubmission::STATUS_PUBLISHED)
                ->when($dateStart, fn($q) => $q->where('s.date_submitted', '>=', strtotime($dateStart)))
                ->get()->first();
            return (int) ($row->submission_count ?? 0);
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Get the peer reviewer count for a given submission.
     */
    function getReviewerCount(int $submissionId): int
    {
        try {
            $row = DB::table('review_assignments AS r')
                ->select([DB::raw('COUNT(DISTINCT r.reviewer_id) AS reviewer_count')])
                ->whereNotNull('r.date_completed')
                ->where('r.submission_id', $submissionId)
                ->get()->first();
            return (int) ($row->reviewer_count ?? 0);
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Get the average peer reviews per published submission in a reviewed section for the journal.
     */
    function getReviewerAverage(int $journalId, ?string $dateStart = null): float
    {
        try {
            $row = DB::table(function ($query) use ($journalId, $dateStart) {
                $query->selectRaw('COUNT(*) AS ra_count')
                    ->from('review_assignments AS ra')
                    ->join('submissions AS s', 'ra.submission_id', '=', 's.submission_id')
                    ->join('publications AS p', 's.current_publication_id', '=', 'p.publication_id')
                    ->join('sections AS sec', 'p.section_id', '=', 'sec.section_id')
                    ->where('s.context_id', $journalId)
                    ->where('sec.meta_reviewed', 1)
                    ->where('s.status', PKPSubmission::STATUS_PUBLISHED)
                    ->when($dateStart, fn($q) => $q->where('s.date_submitted', '>=', strtotime($dateStart)))
                    ->groupBy('s.submission_id');
            }, 'a')->selectRaw('AVG(ra_count) AS reviewer_count')->get()->first();
            return (float) ($row->reviewer_count ?? 0);
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Get the average days from submission to publication for a reviewed section for the journal.
     */
    function getDaysToPublicationAverage(int $journalId, ?string $dateStart = null): ?int
    {
        // Date-diff expression is database-specific (MySQL vs PostgreSQL).
        // Both branches are static SQL strings — no user input involved.
        $datediffExpr = DB::connection() instanceof MySqlConnection
            ? DB::raw('DATEDIFF(p.date_published, s.date_submitted)')
            : DB::raw('EXTRACT(DAY FROM p.date_published::date - s.date_submitted::date)');

        try {
            $row = DB::table(function ($query) use ($journalId, $dateStart, $datediffExpr) {
                $query->selectRaw((string) $datediffExpr . ' AS time_to_publish')
                    ->from('review_assignments AS ra')
                    ->join('submissions AS s', 'ra.submission_id', '=', 's.submission_id')
                    ->join('publications AS p', 's.current_publication_id', '=', 'p.publication_id')
                    ->join('sections AS sec', 'p.section_id', '=', 'sec.section_id')
                    ->where('s.context_id', $journalId)
                    ->where('s.status', PKPSubmission::STATUS_PUBLISHED)
                    ->where('sec.meta_reviewed', 1)
                    ->whereRaw('p.date_published > s.date_submitted')
                    ->when($dateStart, fn($q) => $q->where('s.date_submitted', '>=', strtotime($dateStart)))
                    ->groupBy('p.publication_id', 's.submission_id');
            }, 'a')->selectRaw('AVG(time_to_publish) AS time_to_publish')->get()->first();
            $value = $row->time_to_publish ?? null;
            return $value === null ? null : (int) $value;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get the number of reviewable submissions in a peer reviewed section for the journal.
     */
    function getReviewableSubmissionCount(int $journalId, ?string $dateStart = null): int
    {
        try {
            $row = DB::table('submissions AS s')
                ->select([DB::raw('COUNT(*) AS submission_count')])
                ->join('publications AS p', 's.current_publication_id', '=', 'p.publication_id')
                ->join('sections AS sec', 'p.section_id', '=', 'sec.section_id')
                ->where('s.context_id', $journalId)
                ->where('sec.meta_reviewed', 1)
                ->when($dateStart, fn($q) => $q->where('s.date_submitted', '>=', strtotime($dateStart)))
                ->get()->first();
            return (int) ($row->submission_count ?? 0);
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Get the number of published submissions with at least one author CI statement in a peer reviewed section for the journal.
     */
    function getCompetingInterestsSubmissionCount(int $journalId, ?string $dateStart = null): int
    {
        try {
            $row = DB::table('submissions AS s')
                ->select([DB::raw('COUNT(*) AS submission_count')])
                ->join('publications AS p', 's.current_publication_id', '=', 'p.publication_id')
                ->join('authors AS a', 'a.publication_id', '=', 'p.publication_id')
                ->join('author_settings AS a_s', function ($join) {
                    $join->on('a_s.author_id', '=', 'a.author_id')
                        ->where('a_s.setting_name', '=', 'competingInterests')
                        ->where('a_s.setting_value', '<>', '');
                })
                ->join('sections AS sec', 'p.section_id', '=', 'sec.section_id')
                ->where('s.context_id', $journalId)
                ->where('sec.meta_reviewed', 1)
                ->where('s.status', PKPSubmission::STATUS_PUBLISHED)
                ->when($dateStart, fn($q) => $q->where('s.date_submitted', '>=', strtotime($dateStart)))
                ->get()->first();
            return (int) ($row->submission_count ?? 0);
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Get the number of published submissions with at least one funding source in a peer reviewed section for the journal.
     */
    function getFundedSubmissionCount(int $journalId, ?string $dateStart = null): ?int
    {
        if (!PluginRegistry::getPlugin('generic', 'FundingPlugin')) return null;
        try {
            $row = DB::table('submissions AS s')
                ->select([DB::raw('COUNT(*) AS submission_count')])
                ->join('publications AS p', 's.current_publication_id', '=', 'p.publication_id')
                ->join('funders AS f', 'f.submission_id', '=', 's.submission_id')
                ->join('sections AS sec', 'p.section_id', '=', 'sec.section_id')
                ->where('s.context_id', $journalId)
                ->where('sec.meta_reviewed', 1)
                ->where('s.status', PKPSubmission::STATUS_PUBLISHED)
                ->when($dateStart, fn($qb) => $qb->where('s.date_submitted', '>=', strtotime($dateStart)))
                ->get()->first();
            return (int) ($row->submission_count ?? 0);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Normalize an author collection from publication data to a zero-indexed PHP array.
     * Handles both OJS 3.4 (plain array) and OJS 3.5 (Illuminate Collection).
     *
     * @param mixed $authorsRaw Return value of $publication->getData('authors')
     * @return array
     */
    protected function normalizeAuthorsToArray(mixed $authorsRaw): array
    {
        if ($authorsRaw === null) return [];
        if (is_object($authorsRaw) && method_exists($authorsRaw, 'all')) {
            return array_values($authorsRaw->all());
        }
        return array_values((array) $authorsRaw);
    }

    /**
     * Hook handler to display article PFL
     */
    function displayArticlePfl(string $hookName, array $args): bool
    {
        $templateMgr =& $args[1];
        $output =& $args[2];

        $request = Application::get()->getRequest();
        $router = $request->getRouter();

        $journal = $request->getContext();
        if (!$journal) return false;
        $dateStart = $this->getSetting($journal->getId(), 'dateStart');

        // https://github.com/asmecher/pflPlugin/issues/20 Only apply PFL to peer reviewed sections
        $section = $templateMgr->getTemplateVars('section');
        if ($section && !$section->getMetaReviewed()) return false;

        // Check if the submission came in before a specified start date for inclusion
        $article = $templateMgr->getTemplateVars('article');
        $publication = $templateMgr->getTemplateVars('publication');
        $dateSubmitted = $article->getData('dateSubmitted');
        if ($dateStart && $dateSubmitted && strtotime($dateStart) > strtotime($dateSubmitted)) return false;

        $pflIndexList = [];
        $onlineIssn = urlencode($journal->getSetting('onlineIssn'));

        if ($this->getSetting($journal->getId(), 'includeDoaj')) {
            $pflIndexList["https://doaj.org/toc/{$onlineIssn}"] = ['name' => 'DOAJ', 'description' => 'Directory of Open Access Journals'];
        }
        if ($this->getSetting($journal->getId(), 'includeScholar')) {
            $pflIndexList["https://scholar.google.com/scholar?hl=en&as_sdt=0%2C5&q={$onlineIssn}&btnG="] = ['name' => 'GS', 'description' => 'Google Scholar'];
        }

        if ($this->getSetting($journal->getId(), 'includeMedline')) {
            $pflIndexList["https://www.ncbi.nlm.nih.gov/nlmcatalog/?term={$onlineIssn}[ISSN]"] = ['name' => 'M', 'description' => 'Medline'];
        }

        if ($this->getSetting($journal->getId(), 'includeLatindex')) {
            $pflIndexList["https://latindex.org/latindex/Solr/Busqueda?idModBus=0&buscar={$onlineIssn}&submit=Buscar"] = ['name' => 'L', 'description' => 'Latindex'];
        }

        if ($scopusUrl = $this->getSetting($journal->getId(), 'scopusUrl')) {
            $pflIndexList[$scopusUrl] = ['name' => 'S', 'description' => 'Scopus'];
        }

        if ($wosUrl = $this->getSetting($journal->getId(), 'wosUrl')) {
            $pflIndexList[$wosUrl] = ['name' => 'WS', 'description' => 'Web of Science'];
        }

        // Custom user-defined indexes (Issue #32)
        if (($customIndex1Url = $this->getSetting($journal->getId(), 'customIndex1Url'))
            && ($customIndex1Name = $this->getSetting($journal->getId(), 'customIndex1Name'))
        ) {
            $acronym = $this->getSetting($journal->getId(), 'customIndex1Acronym') ?: $customIndex1Name;
            $pflIndexList[$customIndex1Url] = ['name' => $acronym, 'description' => $customIndex1Name];
        }

        if (($customIndex2Url = $this->getSetting($journal->getId(), 'customIndex2Url'))
            && ($customIndex2Name = $this->getSetting($journal->getId(), 'customIndex2Name'))
        ) {
            $acronym = $this->getSetting($journal->getId(), 'customIndex2Acronym') ?: $customIndex2Name;
            $pflIndexList[$customIndex2Url] = ['name' => $acronym, 'description' => $customIndex2Name];
        }

        // Professional organization memberships (Issue #53)
        $pflOrgMemberships = [];
        if ($copeUrl = $this->getSetting($journal->getId(), 'copeUrl')) {
            $pflOrgMemberships[] = ['name' => 'COPE', 'description' => 'Committee on Publication Ethics', 'url' => $copeUrl];
        }
        if ($iildUrl = $this->getSetting($journal->getId(), 'iildUrl')) {
            $pflOrgMemberships[] = ['name' => 'IILD', 'description' => 'International Institute for Licensed Journalists', 'url' => $iildUrl];
        }
        if (($customOrgUrl = $this->getSetting($journal->getId(), 'customOrgUrl'))
            && ($customOrgName = $this->getSetting($journal->getId(), 'customOrgName'))
        ) {
            $customOrgAcronym = $this->getSetting($journal->getId(), 'customOrgAcronym') ?: $customOrgName;
            $pflOrgMemberships[] = ['name' => $customOrgAcronym, 'description' => $customOrgName, 'url' => $customOrgUrl];
        }

        // Journal-specific PFL data
        $acceptanceNumerator = $this->getPublishedReviewableSubmissionCount($journal->getId(), $dateStart);
        $acceptanceDenominator = $this->getReviewableSubmissionCount($journal->getId(), $dateStart);
        $acceptanceRate = $acceptanceDenominator ? intval($acceptanceNumerator / $acceptanceDenominator * 100) : 0;


        // Class data
        $statistics = $this->getStatistics($journal->getId());

        // Article-specific PFL data
        $competingInterests = [];
        foreach ($this->normalizeAuthorsToArray($publication->getData('authors')) as $author) {
            $ciStatement = trim($author->getLocalizedData('competingInterests') ?? '');
            if (!empty($ciStatement)) $competingInterests[$author->getId()] = $ciStatement;
        }

        // Calculate days to publication with null safety
        $datePublished = $publication->getData('datePublished');
        $dateSubmitted = $article->getData('dateSubmitted');
        $daysToPublication = 0;
        if ($datePublished && $dateSubmitted) {
            try {
                $daysToPublication = (new \DateTime($datePublished))->diff(new \DateTime($dateSubmitted))->days;
            } catch (\Exception $e) {
                $daysToPublication = 0;
            }
        }

        // Funding
        $pflFundingEnabled = (bool) PluginRegistry::getPlugin('generic', 'FundingPlugin');
        $pflFundersValue = $pflFundersValueUrl = null;
        if ($pflFundingEnabled) {
            try {
                $funderDao = DAORegistry::getDAO('FunderDAO');
                $funders = $funderDao->getBySubmissionId($article->getId());
                $firstFunder = $funders->next();
                $pflFundersValue = $firstFunder ? 'YES' : 'NO';
                if ($firstFunder) $pflFundersValueUrl = '#funding-data';
            } catch (\Exception $e) {
                $pflFundersValue = 'NA';
            }
        } else {
            $pflFundersValue = 'NA';
        }

        // Competing Interests
        $pflCompetingInterestsEnabled = $journal->getData('requireAuthorCompetingInterests');
        $pflCompetingInterestsValue = ($competingInterests)
            ? 'YES'
            : ($pflCompetingInterestsEnabled
                ? 'NO'
                : 'NA');
        $pflCompetingInterestsValueUrl = $competingInterests ? '#author-list' : null;

        // Data Availability
        $pflDataAvailabilityEnabled = $journal->getData('dataAvailability');
        $dataAvailabilityStatement = $publication->getData('dataAvailability');
        $pflDataAvailabilityValue = !empty($dataAvailabilityStatement[$publication->getData('locale')])
            ? 'YES'
            : ($pflDataAvailabilityEnabled
                ? 'NO'
                : 'NA');
        $pflDataAvailabilityValueUrl = !empty($dataAvailabilityStatement[$publication->getData('locale')]) ? '#data-availability-statement' : null;

        // pflIndex as array:
        $pflIndexListTransformed = array_map(function($item, $url) {
            return [
                'name' => $item['name'],
                'description' => $item['description'],
                'url' => $url
            ];
        }, array_values($pflIndexList), array_keys($pflIndexList));

        $templateMgr->assign([
            'pflData' => [
                'baseUrl' => "{$request->getBaseUrl()}/{$this->getPluginPath()}/pfl",
                'locale' =>  Locale::getLocale(),
                'values' => [
                    'pflReviewerCount' => $this->getReviewerCount($article->getId()),
                    'pflReviewerCountClass' => round($this->getReviewerAverage($journal->getId(), $dateStart), 1),
                    'pflDataAvailabilityValue' => $pflDataAvailabilityValue,
                    'pflDataAvailabilityValueUrl' => $pflDataAvailabilityValueUrl,
                    'pflDataAvailabilityPercentClass' => __('plugins.generic.pfl.percentage', ['num' => $statistics['pflDataAvailabilityPercentClass']]),
                    'pflFundersValue' => $pflFundersValue,
                    'pflFundersCount' => $pflFundersValueUrl,
                    'pflNumHaveFundersClass' => __('plugins.generic.pfl.percentage', ['num' => $statistics['pflNumHaveFundersClass']]),
                    'pflCompetingInterestsValue' => $pflCompetingInterestsValue,
                    'pflCompetingInterestsValueUrl' => $pflCompetingInterestsValueUrl,
                    'pflCompetingInterestsPercentClass' => __('plugins.generic.pfl.percentage', ['num' => $statistics['pflCompetingInterestsPercentClass']]),
                    'pflAcceptedPercent' => __('plugins.generic.pfl.percentage', ['num' => $acceptanceRate]),
                    'pflNumAcceptedClass' => __('plugins.generic.pfl.percentage', ['num' => $statistics['pflNumAcceptedClass']]),
                    'pflDaysToPublication' => $daysToPublication,
                    'pflDaysToPublicationClass' =>  $statistics['pflDaysToPublicationClass'],
                    'pflIndexList' => $pflIndexListTransformed,
                    'pflOrgMemberships' => $pflOrgMemberships,
                    'editorialTeamUrl' => $router->url($request, null, 'about', 'editorialMasthead'),
                    'pflAcademicSociety' => $this->getSetting($journal->getId(), 'academicSociety') ?? 'NA',
                    'pflAcademicSocietyUrl' => $this->getSetting($journal->getId(), 'academicSocietyUrl'),
                    'pflPublisherName' =>$journal->getData('publisherInstitution'),
                    'pflPublisherUrl' => $journal->getData('publisherUrl'),
                    'pflInfoUrl' => 'https://pkp.sfu.ca/information-on-pfl/'
                ]
            ]
        ]);

        $output .= $templateMgr->fetch($this->getTemplateResource('pfl.tpl'));

        return Hook::CONTINUE;
    }

    /**
     * @see Plugin::getActions()
     */
    public function getActions($request, $actionArgs) {

        $actions = parent::getActions($request, $actionArgs);

        if (!$this->getEnabled()) {
            return $actions;
        }

        $router = $request->getRouter();

        $linkAction = new LinkAction(
            'settings',
            new AjaxModal(
                $router->url(
                    $request,
                    null,
                    null,
                    'manage',
                    null,
                    array(
                        'verb' => 'settings',
                        'plugin' => $this->getName(),
                        'category' => 'generic'
                    )
                ),
                $this->getDisplayName()
            ),
            __('manager.plugins.settings'),
            null
        );

        array_unshift($actions, $linkAction);

        // Cache invalidation control (Feature 3)
        $clearCacheAction = new LinkAction(
            'clearCache',
            new AjaxModal(
                $router->url(
                    $request,
                    null,
                    null,
                    'manage',
                    null,
                    [
                        'verb' => 'clearCache',
                        'plugin' => $this->getName(),
                        'category' => 'generic',
                    ]
                ),
                __('plugins.generic.pflPlugin.clearCache')
            ),
            __('plugins.generic.pflPlugin.clearCache'),
            null
        );
        array_unshift($actions, $clearCacheAction);

        // Dashboard link — site admin only (Issue #47)
        $user = $request->getUser();
        if ($user && $user->hasRole([\PKP\security\Role::ROLE_ID_SITE_ADMIN], \PKP\core\PKPApplication::CONTEXT_SITE)) {
            $dashboardAction = new LinkAction(
                'dashboard',
                new AjaxModal(
                    $router->url(
                        $request,
                        null,
                        null,
                        'manage',
                        null,
                        [
                            'verb' => 'dashboard',
                            'plugin' => $this->getName(),
                            'category' => 'generic',
                        ]
                    ),
                    __('plugins.generic.pflPlugin.dashboard')
                ),
                __('plugins.generic.pflPlugin.dashboard'),
                null
            );
            array_unshift($actions, $dashboardAction);
        }

        return $actions;
    }

    /**
     * Get the statistical data from the upstream PFL dataset, caching as necessary.
     */
    function getStatistics(int $journalId): array
    {
        $defaults = [
            'pflDataAvailabilityPercentClass' => 0,
            'pflNumHaveFundersClass' => 0,
            'pflCompetingInterestsPercentClass' => 0,
            'pflNumAcceptedClass' => 0,
            'pflDaysToPublicationClass' => 0,
        ];

        return Cache::remember('pflStats-' . $journalId, 60 * 60 * 24, function() use ($defaults) {
            try {
                $request = Application::get()->getRequest();
                $journal = $request->getContext();
                if (!$journal) return $defaults;

                $dateStart = $this->getSetting($journal->getId(), 'dateStart');
                $reviewableSubmissionsCount = $this->getReviewableSubmissionCount($journal->getId(), $dateStart);
                $fundedSubmissionsCount = $this->getFundedSubmissionCount($journal->getId(), $dateStart);
                $acceptanceCount = $this->getPublishedReviewableSubmissionCount($journal->getId(), $dateStart);

                // Determine plugin version string safely
                $versionString = '1.0.0.0';
                try {
                    $versionDao = DAORegistry::getDAO('VersionDAO');
                    $currentVersion = $versionDao->getCurrentVersion('plugins.generic', 'pflPlugin');
                    if ($currentVersion) $versionString = $currentVersion->getVersionString();
                } catch (\Exception $e) {
                    // Fall back to hardcoded version
                }

                $queryParams = [
                    'version' => $versionString,
                    'platform' => 'ojs',
                    'journalUrl' => $request->url(null, 'index'),
                    'pflNumAcceptedClass' => $acceptanceCount,
                    'pflReviewerCountClass' => $this->getReviewerAverage($journal->getId(), $dateStart),
                    'pflCompetingInterestsClass' => $this->getCompetingInterestsSubmissionCount($journal->getId(), $dateStart),
                    'pflDataAvailabilityClass' => 'N/A',
                    'pflNumHaveFundersClass' => $fundedSubmissionsCount === null ? 'N/A' : $fundedSubmissionsCount,
                    'pflDaysToPublicationClass' => $this->getDaysToPublicationAverage($journal->getId(), $dateStart),
                    'reviewableSubmissionsCount' => $reviewableSubmissionsCount,
                    'issn' => $journal->getSetting('onlineIssn') ?? $journal->getSetting('printIssn'),
                ];

                $client = Application::get()->getHttpClient();
                $response = $client->request('GET', 'https://pkp.sfu.ca/ojs/pflStatistics.json', ['query' => $queryParams]);
                $data = json_decode((string) $response->getBody(), true);
                return is_array($data) ? array_merge($defaults, $data) : $defaults;
            } catch (\Exception $e) {
                return $defaults;
            }
        });
    }

    /**
     * Hook callback: register output filter for article display.
     * This is used to add the CI statements to the author information.
     *
     * @param string $hookName
     * @param array $args
     *
     * @see TemplateManager::display()
     *
     */
    public function handleTemplateDisplay(string $hookName, array $args): bool
    {
        $templateMgr = & $args[0];
        $template = & $args[1];
        $request = Application::get()->getRequest();

        switch ($template) {
            case 'frontend/pages/article.tpl':
                $this->addPflJsAndCss($templateMgr);
                $templateMgr->registerFilter('output', $this->authorCiFilter(...));
                break;
        }
        return Hook::CONTINUE;
    }

    /**
     * Add pfl.js and inline css
     */
    public function addPflJsAndCss(TemplateManager $templateMgr): void
    {
        $request = Application::get()->getRequest();

        $pflPath = "{$request->getBaseUrl()}/{$this->getPluginPath()}/pfl/";

        $templateMgr->addJavaScript(
            'FrontendUiExample',
            "{$pflPath}js/pfl.js",
            [
                'inline' => false,
                'contexts' => ['frontend'],
                'priority' => STYLE_SEQUENCE_LAST
            ]
        );

        $style = <<<EOD
                @font-face {
                    font-family: "PFL Noto Sans";
                    src: url({$pflPath}font/NotoSans-VariableFont_wdthwght.woff2) format("woff2");
                    font-weight: 100 900;
                }

                #funding-data:target,
                #author-list:target {
                    animation: pflflash 1s 1;
                    -webkit-animation: pflflash 1s 1; /* Safari and Chrome */
                }

                #data-availability-statement:target {
                    animation: pflflash 1s 1;
                    -webkit-animation: pflflash 1s 1; /* Safari and Chrome */
                }

                @keyframes pflflash {
                    0% {
                        background: yellow;
                    }
                }

                @-webkit-keyframes pflflash /* Safari and Chrome */ {
                    0% {
                        background: yellow;
                    }
                }

            EOD;

        $templateMgr->addHeader(
            'pfl_locale',
            '<link rel="preload" href="'. $pflPath .'locale/'. Locale::getLocale() .'.json" as="fetch" crossorigin="anonymous">'
        );

        $templateMgr->addStyleSheet(
            'fadeout',
            $style,
            [
                'contexts' => 'frontend',
                'inline' => true,
                'priority' => STYLE_SEQUENCE_LAST,
            ]
        );
    }

    /**
     * Output filter adds author CI statements to article view.
     *
     * @param string $output
     * @param TemplateManager $templateMgr
     *
     * @return string
     */
    public function authorCiFilter($output, $templateMgr)
    {
        $authorIndex = 0;
        $publication = $templateMgr->getTemplateVars('publication');
        $authors = $this->normalizeAuthorsToArray($publication->getData('authors'));

        // Add an ID to the author list
        $startMarkup = '<ul id="author-list" class="authors">';
        $output = str_replace('<ul class="authors">', $startMarkup, $output);

        // Identify the ul.authors list and traverse li/ul/ol elements from there.
        // For any </li> elements in 1st-level depth, append CI statements before </li> element.
        $startOffset = strpos($output, $startMarkup);

        if ($startOffset === false) return $output;

        $startOffset += strlen($startMarkup);
        $depth = 1; // Depth of potentially nested ul/ol list elements

        return substr($output, 0, $startOffset) . preg_replace_callback(
            '/(<\/li>)|(<[uo]l[^>]*>)|(<\/[uo]l>)/i',
            function($matches) use (&$depth, &$authorIndex, $authors) {
                switch (true) {
                    case $depth == 1 && $matches[1] !== '': // </li> in first level depth
                        if (isset($authors[$authorIndex])) {
                            $ciStatement = $authors[$authorIndex]->getLocalizedData('competingInterests');
                            $authorIndex++;
                            if ($ciStatement) {
                                $safeHtml = method_exists('PKP\core\PKPString', 'stripUnsafeHtml')
                                    ? PKPString::stripUnsafeHtml($ciStatement)
                                    : htmlspecialchars($ciStatement);
                                return '
                                <div class="ciStatement">
                                    <div class="ciStatementLabel">' . htmlspecialchars(__('author.competingInterests')) . '</div>
                                    <div class="ciStatementContents">' . $safeHtml . '</div>
                                </div>' . $matches[0];
                            }
                        }
                        break;
                    case !empty($matches[2]) && $depth >= 1: $depth++; break; // <ul>; do not re-enter once we leave
                    case !empty($matches[3]): $depth--; break; // </ul>
                }
                return $matches[0];
            },
            substr($output, $startOffset)
        );
    }

    /**
     * @see Plugin::manage()
     */
    public function manage($args, $request) {
        switch ($request->getUserVar('verb')) {
            case 'settings':
                $form = new PflSettingsForm($this);

                if ($request->getUserVar('save')) {
                    $form->readInputData();
                    if ($form->validate()) {
                        $form->execute();
                        return new JSONMessage(true);
                    }
                }

                $form->initData();
                return new JSONMessage(true, $form->fetch($request));

            case 'clearCache':
                // Cache invalidation control (Feature 3)
                $context = $request->getContext();
                if ($context) {
                    Cache::forget('pflStats-' . $context->getId());
                }
                $notificationMgr = new \APP\notification\NotificationManager();
                $notificationMgr->createTrivialNotification(
                    $request->getUser()->getId(),
                    \PKP\notification\Notification::NOTIFICATION_TYPE_SUCCESS,
                    ['contents' => __('plugins.generic.pflPlugin.clearCache.success')]
                );
                return new JSONMessage(true);

            case 'dashboard':
                // Site Admin Dashboard (Issue #47)
                $user = $request->getUser();
                if (!$user || !$user->hasRole([\PKP\security\Role::ROLE_ID_SITE_ADMIN], \PKP\core\PKPApplication::CONTEXT_SITE)) {
                    return new JSONMessage(false, 'Forbidden');
                }
                return new JSONMessage(true, $this->_fetchDashboard($request));
        }
        return parent::manage($args, $request);
    }

    /**
     * Build the Site Admin dashboard view (Issue #47).
     */
    protected function _fetchDashboard($request): string
    {
        $contextDao = Application::getContextDAO();
        $contexts = $contextDao->getAll(true);

        $journalRows = [];
        while ($context = $contexts->next()) {
            $journalId = $context->getId();
            $enabled = (bool) $this->getEnabled($journalId);
            $dateStart = $this->getSetting($journalId, 'dateStart');

            $indexCount = 0;
            foreach (['includeDoaj', 'includeScholar', 'includeMedline', 'includeLatindex'] as $idx) {
                if ($this->getSetting($journalId, $idx)) $indexCount++;
            }
            if ($this->getSetting($journalId, 'scopusUrl')) $indexCount++;
            if ($this->getSetting($journalId, 'wosUrl')) $indexCount++;
            if ($this->getSetting($journalId, 'customIndex1Url') && $this->getSetting($journalId, 'customIndex1Name')) $indexCount++;
            if ($this->getSetting($journalId, 'customIndex2Url') && $this->getSetting($journalId, 'customIndex2Name')) $indexCount++;

            $orgCount = 0;
            if ($this->getSetting($journalId, 'copeUrl')) $orgCount++;
            if ($this->getSetting($journalId, 'iildUrl')) $orgCount++;
            if ($this->getSetting($journalId, 'customOrgUrl') && $this->getSetting($journalId, 'customOrgName')) $orgCount++;

            $stats = null;
            try {
                $stats = Cache::get('pflStats-' . $journalId);
            } catch (\Exception $e) {
                // Not critical
            }

            $journalRows[] = [
                'id'          => $journalId,
                'title'       => $context->getLocalizedName(),
                'path'        => $context->getData('urlPath'),
                'enabled'     => $enabled,
                'dateStart'   => $dateStart,
                'indexCount'  => $indexCount,
                'orgCount'    => $orgCount,
                'hasCachedStats' => $stats !== null,
                'academicSociety' => $this->getSetting($journalId, 'academicSociety') ?: '—',
            ];
        }

        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign([
            'pluginName'  => $this->getName(),
            'journalRows' => $journalRows,
        ]);
        return $templateMgr->fetch($this->getTemplateResource('dashboard.tpl'));
    }
}
