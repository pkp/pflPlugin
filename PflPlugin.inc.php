<?php

/**
 * @file PflPlugin.inc.php
 *
 * Copyright (c) 2023-2024 Simon Fraser University
 * Copyright (c) 2023-2024 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @brief Publication Facts Label plugin
 */

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\MySqlConnection;

import('lib.pkp.classes.plugins.GenericPlugin');

class PflPlugin extends GenericPlugin {
    /**
     * @copydoc Plugin::register()
     */
    function register($category, $path, $mainContextId = null) {
        if (parent::register($category, $path, $mainContextId)) {
            if ($this->getEnabled($mainContextId)) {
                // Display the PFL in the article landing page.
                HookRegistry::register('Templates::Article::Details', [$this, 'displayArticlePfl']);

                // Add the author CI statements to the author list
                HookRegistry::register('TemplateManager::display', [$this, 'handleTemplateDisplay']);
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

    function getLabelLocale() {
        $localeMap = [
            'en_US' => 'en',
            'es_ES' => 'es',
            'bg_BG' => 'bg',
            'da_DK' => 'da',
            'id_ID' => 'id',
            'mk_MK' => 'mk',
            'pt_BR' => 'pt',
            'pt_PT' => 'pt',
            'fr_CA' => 'fr',
            'fr_FR' => 'fr',
            'sl_SI' => 'sl',
            'uk_UA' => 'uk',
            'cs_CZ' => 'cs',
            'fi_FI' => 'fi',
            'hu_HU' => 'hu',
            'it_IT' => 'it',
            'sk_SK' => 'sk',
            'tr_TR' => 'tr',
        ];
        $locale = AppLocale::getLocale();
        $locale = $localeMap[$locale] ?? $locale;
        if (!file_exists(dirname(__FILE__) . "/pfl/locale/$locale.json")) $locale = 'en';
        return $locale;
    }

    /**
     * Get the number of published reviewable submissions for a given journal.
     * @param $journalId int
     * @param $dateStart ?string
     */
    function getPublishedReviewableSubmissionCount($journalId, $dateStart = null) {
        $submissionDao = DAORegistry::getDAO('SubmissionDAO');
        $row = $submissionDao->retrieve(
            'SELECT COUNT(*) AS submission_count
            FROM submissions s
            JOIN publications p ON (s.current_publication_id = p.publication_id)
            JOIN sections sec ON (p.section_id = sec.section_id)
            WHERE s.context_id = ? AND sec.meta_reviewed = 1 AND s.status = ?'
            . ($dateStart ? ' AND s.date_submitted >= ' . $submissionDao->dateToDB($dateStart) : ''),
            [$journalId, STATUS_PUBLISHED]
        )->current();
        return $row->submission_count;
    }

    /**
     * Get the peer reviewer count for a given submission.
     * @param $submissionId int
     */
    function getReviewerCount($submissionId) {
        $submissionDao = DAORegistry::getDAO('SubmissionDAO');
        $row = $submissionDao->retrieve(
            'SELECT COUNT(DISTINCT r.reviewer_id) AS reviewer_count FROM review_assignments r WHERE r.date_completed IS NOT NULL AND r.submission_id = ?',
            [$submissionId]
        )->current();
        return $row->reviewer_count;
    }

    /**
     * Get the average peer reviews per published submission in a reviewed section for the journal.
     * @param $journalId int
     * @param $dateStart ?string
     */
    function getReviewerAverage($journalId, $dateStart = null) {
        $submissionDao = DAORegistry::getDAO('SubmissionDAO');
        $row = $submissionDao->retrieve(
            'SELECT AVG(a.ra_count) AS reviewer_count FROM (
                SELECT COUNT(*) AS ra_count FROM review_assignments ra
                JOIN submissions s ON (ra.submission_id = s.submission_id)
                JOIN publications p ON (s.current_publication_id = p.publication_id)
                JOIN sections sec ON (p.section_id = sec.section_id)
                WHERE s.context_id = ? AND sec.meta_reviewed = 1 AND s.status = ?
                ' . ($dateStart ? ' AND s.date_submitted >= ' . $submissionDao->dateToDB($dateStart) : '') . '
                GROUP BY s.submission_id
            ) a',
            [$journalId, STATUS_PUBLISHED]
        )->current();
        return $row->reviewer_count;
    }

    /**
     * Get the average peer reviews per published submission in a reviewed section for the journal.
     * @param $journalId int
     * @param $dateStart ?string
     */
    function getDaysToPublicationAverage($journalId, $dateStart = null) {
        $submissionDao = DAORegistry::getDAO('SubmissionDAO');
        $datediff = Capsule::connection() instanceof MySqlConnection
            ? 'DATEDIFF(p.date_published, s.date_submitted)'
            : "EXTRACT(DAY FROM p.date_published - s.date_submitted)";

        $row = $submissionDao->retrieve(
            'SELECT AVG(a.time_to_publish) AS time_to_publish FROM (
                SELECT ' . $datediff . ' AS time_to_publish FROM review_assignments ra
                JOIN submissions s ON (ra.submission_id = s.submission_id)
                JOIN publications p ON (s.current_publication_id = p.publication_id)
                JOIN sections sec ON (p.section_id = sec.section_id)
                WHERE s.context_id = ? AND sec.meta_reviewed = 1 AND s.status = ?
                ' . ($dateStart ? ' AND s.date_submitted >= ' . $submissionDao->dateToDB($dateStart) : '') . '
                GROUP BY p.publication_id, s.submission_id
            ) a',
            [$journalId, STATUS_PUBLISHED]
        )->current();
        return $row->time_to_publish;
    }

    /**
     * Get the number of reviewable submissions in a peer reviewed section for the journal.
     * @param $journalId int
     * @param $dateStart ?string
     */
    function getReviewableSubmissionCount($journalId, $dateStart = null) {
        $submissionDao = DAORegistry::getDAO('SubmissionDAO');
        $row = $submissionDao->retrieve(
            'SELECT COUNT(*) AS submission_count
            FROM submissions s
            JOIN publications p ON (s.current_publication_id = p.publication_id)
            JOIN sections sec ON (p.section_id = sec.section_id)
            WHERE s.context_id = ? AND sec.meta_reviewed = 1'
            . ($dateStart ? ' AND s.date_submitted >= ' . $submissionDao->dateToDB($dateStart) : ''),
            [$journalId]
        )->current();
        return $row->submission_count;
    }

    /**
     * Get the number of published submissions with at least one author CI statement in a peer reviewed section for the journal.
     * @param $journalId int
     * @param $dateStart ?string
     */
    function getCompetingInterestsSubmissionCount($journalId, $dateStart = null) {
        $submissionDao = DAORegistry::getDAO('SubmissionDAO');
        $row = $submissionDao->retrieve(
            'SELECT COUNT(*) AS submission_count
            FROM submissions s
            JOIN publications p ON (s.current_publication_id = p.publication_id)
            JOIN authors a ON (a.publication_id = p.publication_id)
            JOIN author_settings a_s ON (a_s.author_id = a.author_id AND a_s.setting_name = ? AND a_s.setting_value <> ?)
            JOIN sections sec ON (p.section_id = sec.section_id)
            WHERE s.context_id = ? AND sec.meta_reviewed = 1 AND s.status = ?'
            . ($dateStart ? ' AND s.date_submitted >= ' . $submissionDao->dateToDB($dateStart) : ''),
            ['competingInterests', '', $journalId, STATUS_PUBLISHED]
        )->current();
        return $row->submission_count;
    }

    /**
     * Get the number of published submissions with at least one funding source in a peer reviewed section for the journal.
     * @param $journalId int
     * @param $dateStart ?string
     */
    function getFundedSubmissionCount($journalId, $dateStart = null) {
        if (!PluginRegistry::getPlugin('generic', 'FundingPlugin')) return null;
        $submissionDao = DAORegistry::getDAO('SubmissionDAO');
        $row = $submissionDao->retrieve(
            'SELECT COUNT(*) AS submission_count
            FROM submissions s
            JOIN publications p ON (s.current_publication_id = p.publication_id)
            JOIN funders f ON (f.submission_id = s.submission_id)
            JOIN sections sec ON (p.section_id = sec.section_id)
            WHERE s.context_id = ? AND sec.meta_reviewed = 1 AND s.status = ?'
            . ($dateStart ? ' AND s.date_submitted >= ' . $submissionDao->dateToDB($dateStart) : '') . '
            GROUP BY s.submission_id',
            [$journalId, STATUS_PUBLISHED]
        )->current();
        return $row->submission_count;
    }

    /**
     * Hook handler to display article PFL
     * @param $hookName string
     * @param $args array
     */
    function displayArticlePfl($hookName, $args) {
        $templateMgr =& $args[1];
        $output =& $args[2];

        $request = Application::get()->getRequest();
        $router = $request->getRouter();

        $journal = $request->getContext();
        $dateStart = $this->getSetting($journal->getId(), 'dateStart');

        // https://github.com/asmecher/pflPlugin/issues/20 Only apply PFL to peer reviewed sections
        $section = $templateMgr->get_template_vars('section');
        if ($section && !$section->getMetaReviewed()) return false;

        // Check if the submission came in before a specified start date for inclusion
        $article = $templateMgr->get_template_vars('article');
        $publication = $templateMgr->getTemplateVars('publication');
        if ($dateStart && strtotime($dateStart) > strtotime($article->getDateSubmitted())) return false;

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

        // Journal-specific PFL data
        $acceptanceNumerator = $this->getPublishedReviewableSubmissionCount($journal->getId(), $dateStart);
        $acceptanceDenominator = $this->getReviewableSubmissionCount($journal->getId(), $dateStart);
        $acceptanceRate = $acceptanceDenominator ? intval($acceptanceNumerator / $acceptanceDenominator * 100) : 0;


        // Class data
        $statistics = $this->getStatistics($journal->getId());

        // Article-specific PFL data
        $competingInterests = [];
        foreach ($publication->getData('authors') as $author) {
            if (!method_exists($author, 'getLocalizedCompetingInterests')) continue;
            $ciStatement = trim($author->getLocalizedData('competingInterests') ?? '');
            if (!empty($ciStatement)) $competingInterests[$author->getId()] = $ciStatement;
        }

        $publicationDate = new DateTime($publication->getData('datePublished'));
        $submissionDate = new DateTime($article->getDateSubmitted());

        // Funding
        $pflFundingEnabled = (bool) PluginRegistry::getPlugin('generic', 'FundingPlugin');
        $pflFundersCount = 0;
        $pflFundersValue = $pflFundersValueUrl = null;
        if ($pflFundingEnabled) {
            $funderDao = DAORegistry::getDAO('FunderDAO');
            $funders = $funderDao->getBySubmissionId($article->getId());
            $firstFunder = $funders->next();

            $pflFundersValue = $firstFunder ? 'YES' : 'NO';
            if ($firstFunder) $pflFundersValueUrl = '#funding-data';
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
                'locale' => $this->getLabelLocale(),
                'values' => [
                    'pflReviewerCount' => $this->getReviewerCount($article->getId()),
                    'pflReviewerCountClass' => round($this->getReviewerAverage($journal->getId(), $dateStart), 1),
                    'pflDataAvailabilityValue' => 'NA',
                    'pflDataAvailabilityValueUrl' => null,
                    'pflDataAvailabilityPercentClass' => __('plugins.generic.pfl.percentage', ['num' => $statistics['pflDataAvailabilityPercentClass']]),
                    'pflFundersValue' => $pflFundersValue,
                    'pflFundersCount' => $pflFundersValueUrl,
                    'pflNumHaveFundersClass' => __('plugins.generic.pfl.percentage', ['num' => $statistics['pflNumHaveFundersClass']]),
                    'pflCompetingInterestsValue' => $pflCompetingInterestsValue,
                    'pflCompetingInterestsValueUrl' => $pflCompetingInterestsValueUrl,
                    'pflCompetingInterestsPercentClass' => __('plugins.generic.pfl.percentage', ['num' => $statistics['pflCompetingInterestsPercentClass']]),
                    'pflAcceptedPercent' => __('plugins.generic.pfl.percentage', ['num' => $acceptanceRate]),
                    'pflNumAcceptedClass' => __('plugins.generic.pfl.percentage', ['num' => $statistics['pflNumAcceptedClass']]),
                    'pflDaysToPublication' => $publicationDate->diff($submissionDate)->format('%a'),
                    'pflDaysToPublicationClass' =>  $statistics['pflNumAcceptedClass'],
                    'pflIndexList' => $pflIndexListTransformed,
                    'editorialTeamUrl' => $router->url($request, null, 'about', 'editorialTeam'),
                    'pflAcademicSociety' => $this->getSetting($journal->getId(), 'academicSociety') ?? 'NA',
                    'pflAcademicSocietyUrl' => $this->getSetting($journal->getId(), 'academicSocietyUrl'),
                    'pflPublisherName' =>$journal->getData('publisherInstitution'),
                    'pflPublisherUrl' => $journal->getData('publisherUrl'),
                    'pflInfoUrl' => 'https://pkp.sfu.ca/information-on-pfl/'
                ]

            ]
        ]);

        $output .= $templateMgr->fetch($this->getTemplateResource('pfl.tpl'));

        return false;
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
        import('lib.pkp.classes.linkAction.request.AjaxModal');
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

        return $actions;
    }

    function getStatistics($journalId) {
        $cache = CacheManager::getManager()->getCache('pflStats', $journalId, [$this, '_statsCacheMiss']);
        if (time() - $cache->getCacheTime() > 60 * 60 * 24) {
            // Cache is older than one day, erase it.
            $cache->flush();
        }
        return $cache->getContents();
    }

    /**
     * Callback to fill cache with data, if empty.
     * @return array
     */
    function _statsCacheMiss($cache, $cacheId) {
        $versionDao = DAORegistry::getDAO('VersionDAO');
        $currentVersion = $versionDao->getCurrentVersion('plugins.generic', 'pflPlugin');
        $request = Application::get()->getRequest();
        $journal = $request->getJournal();
        $dateStart = $this->getSetting($journal->getId(), 'dateStart');
        $reviewableSubmissionsCount = $this->getReviewableSubmissionCount($journal->getId(), $dateStart);
        $fundedSubmissionsCount = $this->getFundedSubmissionCount($journal->getId(), $dateStart);
        $acceptanceCount = $this->getPublishedReviewableSubmissionCount($journal->getId(), $dateStart);
        $queryParams = [
            'version' => $currentVersion->getVersionString(),
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
        $data = json_decode($response->getBody(), true);
        $cache->setEntireCache($data);
        return $data;
    }

    /**
     * Hook callback: register output filter for article display.
     * This is used to add the CI statements to the author information.
     *
     * @param string $hookName
     * @param array $args
     *
     * @return bool
     *
     * @see TemplateManager::display()
     *
     */
    public function handleTemplateDisplay($hookName, $args)
    {
        $templateMgr = & $args[0];
        $template = & $args[1];
        $request = Application::get()->getRequest();

        switch ($template) {
            case 'frontend/pages/article.tpl':
                $this->addPflJsAndCss($templateMgr);
                $templateMgr->registerFilter('output', [$this, 'authorCiFilter']);
                break;
        }
        return false;
    }

    /*
     * Add pfl.js and inline css
     *
     * @param string $output
     * @param TemplateManager $templateMgr
     */
    public function addPflJsAndCss($templateMgr) {
        $request = Application::get()->getRequest();

        $pflPath = "{$request->getBaseUrl()}/{$this->getPluginPath()}/pfl/";

        $templateMgr->addJavaScript(
            'PublicationFactsLabel',
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
            '<link rel="preload" href="'. $pflPath .'locale/' . $this->getLabelLocale() .'.json" as="fetch" crossorigin="anonymous">'
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
        $authors = array_values($publication->getData('authors'));

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
                        if ($ciStatement = $authors[$authorIndex++]->getLocalizedData('competingInterests')) return '
                            <div class="ciStatement">
                                <div class="ciStatementLabel">' . htmlspecialchars(__('author.competingInterests')) . '</div>
                                <div class="ciStatementContents">' . PKPString::stripUnsafeHtml($ciStatement) . '</div>
                            </div>' . $matches[0];
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
                $this->import('PflSettingsForm');
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
        }
        return parent::manage($args, $request);
    }
}
