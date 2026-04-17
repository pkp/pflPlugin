{**
 * plugins/generic/pflPlugin/templates/clearCache.tpl
 *
 * Copyright (c) 2023-2025 Simon Fraser University
 * Copyright (c) 2023-2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Confirmation form for clearing the PFL statistics cache.
 *}
<script>
$(function() {ldelim}
    $('#pflClearCacheForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
{rdelim});
</script>

<div class="pkp_modal_panel">
    <h2>{translate key="plugins.generic.pflPlugin.clearCache"}</h2>
    <form class="pkp_form" id="pflClearCacheForm" method="post" action="{$clearCacheUrl|escape}">
        {csrf}
        {include file="controllers/notification/inPlaceNotification.tpl" notificationId="pflClearCacheFormNotification"}
        <p>{translate key="plugins.generic.pflPlugin.clearCache.confirm"}</p>
        <button type="submit" class="pkp_button pkp_button_primary">
            {translate key="plugins.generic.pflPlugin.clearCache"}
        </button>
    </form>
</div>
