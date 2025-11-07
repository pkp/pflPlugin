<?php
/**
 * @file PflSettingsForm.inc.php
 *
 * Copyright (c) 2023-2024 Simon Fraser University
 * Copyright (c) 2023-2024 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class PflSettingsForm
 * @ingroup plugins_generic_pflPlugin
 *
 * @brief Form for journal managers to modify Publication Facts Label settings.
 */

namespace APP\plugins\generic\pflPlugin;

use PKP\form\Form;
use APP\core\Application;
use PKP\plugins\PluginRegistry;
use PKP\form\validation\FormValidatorPost;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorCustom;
use PKP\form\validation\FormValidatorUrl;
use PKP\form\validation\FormValidatorRegExp;
use APP\template\TemplateManager;
use APP\notification\NotificationManager;

class PflSettingsForm extends Form {

    /** @var $plugin object */
    public $plugin;

    /**
     * Constructor
     * @param $plugin object
     */
    public function __construct($plugin) {
        parent::__construct($plugin->getTemplateResource('settings.tpl'));
        $this->plugin = $plugin;

        $application = Application::get();
        $context = $application->getRequest()->getContext();
        $httpClient = $application->getHttpClient();
        $onlineIssn = urlencode($context->getSetting('onlineIssn'));

        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));

        // Ensure that the journal is actually indexed in DOAJ by ISSN if applicable.
        $this->addCheck(new FormValidatorCustom($this, 'includeDoaj', 'optional', 'plugins.generic.pflPlugin.settings.indexes.automatic.doaj.validationFailed', function() use ($onlineIssn, $httpClient) {
            try {
                $response = $httpClient->request('GET', "https://doaj.org/api/search/journals/issn%3A{$onlineIssn}", ['headers' => ['Accept' => 'text/json']]);
                if ($response->getStatusCode() != 200) return false;

                $body = json_decode((string) $response->getBody());
                return $body->total == 1; // A single result should be returned.
            } catch (\Exception $e) {
                return false;
            }
        }));

        // Ensure that the journal is actually indexed in Latindex by ISSN if applicable.
        $this->addCheck(new FormValidatorCustom($this, 'includeLatindex', 'optional', 'plugins.generic.pflPlugin.settings.indexes.automatic.latindex.validationFailed', function() use ($onlineIssn, $httpClient) {
            try {
                // NOTE: Verification explicitly disabled; certificate errors reported on Ubuntu 2023-11-23. Not sure if temporary.
                $response = $httpClient->request('GET', "https://latindex.org/latindex/exportar/busquedaAvanzada/json/%7B%22idMod%22:%220%22,%22titulo%22:%22%22,%22otrostitulos%22:%22%22,%22issn%22:%22{$onlineIssn}%22,%22tema%22:%220%22,%22subtema%22:%220%22,%22editorial%22:%22%22,%22idioma%22:%220%22,%22aInicio%22:%22%22,%22aFin%22:%22%22,%22region%22:%220%22,%22pais%22:%220%22,%22ciudad%22:%22%22,%22estado%22:%22%22,%22natPub%22:%220%22,%22natOrg%22:%220%22,%22situacion%22:%220%22,%22frecuencia%22:%220%22,%22soporte%22:%22%22,%22arbitrada%22:%22%22,%22acc-abierto%22:%22%22,%22derechos-uso%22:%22%22,%22cob-pub%22:%22%22,%22cobertura%22:%220%22,%22f_unico%22:%22%22,%22send%22:%22Buscar%22%7D", ['headers' => ['Accept' => 'text/json'], 'verify' => false]);
                if ($response->getStatusCode() != 200) return false;

                $body = json_decode((string) $response->getBody());
                return count($body) == 1; // A single result should be returned.
            } catch (\Exception $e) {
                return false;
            }
        }));

        // Ensure that the journal is actually indexed in Medline by ISSN if applicable.
        $this->addCheck(new FormValidatorCustom($this, 'includeMedline', 'optional', 'plugins.generic.pflPlugin.settings.indexes.automatic.medline.validationFailed', function() use ($onlineIssn, $httpClient) {
            try {
                $response = $httpClient->request('GET', "https://www.ncbi.nlm.nih.gov/nlmcatalog/?term={$onlineIssn}%5BISSN%5D&report=xml&format=text");
                if ($response->getStatusCode() != 200) return false;

                // Body comes in HTML-escaped and wrapped in <pre> tags; strip that out. (There's probably a better way.)
                $body = html_entity_decode(strip_tags($response->getBody()));

                $previousErrorSetting = libxml_use_internal_errors(true);
                $xml = new \SimpleXMLElement($body);
                $errors = libxml_get_errors();
                libxml_use_internal_errors($previousErrorSetting);
                return empty($errors) && !empty($xml->xpath('/NCBICatalogRecord/NLMCatalogRecord/ISSN'));
            } catch (\Exception $e) {
                return false;
            }
        }));

        $this->addCheck(new FormValidatorUrl($this, 'academicSocietyUrl', 'optional', 'plugins.generic.pflPlugin.settings.academicSocietyUrl.invalid'));
        $this->addCheck(new FormValidatorUrl($this, 'scopusUrl', 'optional', 'plugins.generic.pflPlugin.settings.indexes.manual.scopus.urlInvalid'));
        $this->addCheck(new FormValidatorRegExp($this, 'scopusUrl', 'optional', 'plugins.generic.pflPlugin.settings.indexes.manual.scopus.urlInvalid', '/^https:\/\/www\.scopus\.com\/sourceid\/[0-9]+$/'));
        $this->addCheck(new FormValidatorCustom($this, 'dateStart', 'optional', 'plugins.generic.pflPlugin.settings.dateStart.invalid', function($v) {return strtotime($v) !== false;}));

        // The validator below is removed because WOS URLs appear to have a weird colon that the validator (possibly correctly) does not like.
        // $this->addCheck(new FormValidatorUrl($this, 'wosUrl', 'optional', 'plugins.generic.pflPlugin.settings.indexes.manual.wos.urlInvalid'));
        $this->addCheck(new FormValidatorRegExp($this, 'wosUrl', 'optional', 'plugins.generic.pflPlugin.settings.indexes.manual.wos.urlInvalid', '/^https:\/\/mjl\.clarivate\.com/'));
    }

    /**
    * @copydoc Form::init
    */
    public function initData() {
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        $contextId = $context ? $context->getId() : 0;

        foreach (['includeMedline', 'includeDoaj', 'includeLatindex', 'includeScholar', 'scopusUrl', 'wosUrl', 'academicSociety', 'academicSocietyUrl', 'dateStart'] as $settingName) {
            $this->setData($settingName, $this->plugin->getSetting($contextId, $settingName));
        }
    }

    /**
     * Assign form data to user-submitted data.
     */
    public function readInputData() {
        $this->readUserVars([
            'includeMedline', 'includeDoaj', 'includeLatindex', 'includeScholar', 'scopusUrl', 'wosUrl', 'academicSociety', 'academicSocietyUrl', 'dateStart',
        ]);
    }

    /**
     * @copydoc Form::fetch()
     */
    public function fetch($request, $template = null, $display = false) {
        $context = $request->getContext();

        $templateMgr = TemplateManager::getManager($request);
        $fundingPlugin = PluginRegistry::getPlugin('generic', 'FundingPlugin');
        $templateMgr->assign([
            'pluginName' => $this->plugin->getName(),
            'fundingPluginPresent' => $fundingPlugin ? $fundingPlugin->getEnabled() : null,
        ]);

        return parent::fetch($request, $template, $display);
    }

    /**
     * @copydoc Form::execute()
     */
    public function execute(...$functionArgs) {
        $request = Application::get()->getRequest();
        $context = $request->getContext();

        foreach (['includeMedline', 'includeDoaj', 'includeLatindex', 'includeScholar'] as $booleanSettingName) {
            $this->plugin->updateSetting($context->getId(), $booleanSettingName, (bool) $this->getData($booleanSettingName));
        }

        foreach (['scopusUrl', 'wosUrl', 'academicSociety', 'academicSocietyUrl'] as $stringSettingName) {
            $this->plugin->updateSetting($context->getId(), $stringSettingName, (string) $this->getData($stringSettingName));
        }

        $this->plugin->updateSetting($context->getId(), 'dateStart', ((string) $this->getData('dateStart')) ?: null);

        $notificationMgr = new NotificationManager();
        $user = $request->getUser();
        $notificationMgr->createTrivialNotification($user->getId(), NOTIFICATION_TYPE_SUCCESS, array('contents' => __('common.changesSaved')));

        return parent::execute(...$functionArgs);
    }
}

