{**
 * plugins/generic/pflPlugin/templates/dashboard.tpl
 *
 * Copyright (c) 2023-2025 Simon Fraser University
 * Copyright (c) 2023-2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Site Admin dashboard: per-journal PFL adoption status (Issue #47)
 *}

<div class="pkp_modal_panel" style="min-width:650px;">
    <h2 style="margin-bottom:0.5em;">{translate key="plugins.generic.pflPlugin.dashboard"}</h2>

    <p style="margin:0 0 1em 0;">
        <a href="{$exportCsvUrl|escape}" target="_blank" class="pkp_button"
           style="display:inline-block; padding:5px 12px; background:#007ab3; color:#fff; text-decoration:none; border-radius:3px; font-size:0.9em;">
            {translate key="plugins.generic.pflPlugin.dashboard.exportCsv"}
        </a>
    </p>

    <table class="pkp_table" style="width:100%; border-collapse:collapse; margin-top:0.5em;">
        <thead>
            <tr>
                <th style="text-align:left; padding:6px 8px; border-bottom:2px solid #ccc;">{translate key="plugins.generic.pflPlugin.dashboard.journal"}</th>
                <th style="text-align:center; padding:6px 8px; border-bottom:2px solid #ccc;">{translate key="plugins.generic.pflPlugin.dashboard.enabled"}</th>
                <th style="text-align:center; padding:6px 8px; border-bottom:2px solid #ccc;">{translate key="plugins.generic.pflPlugin.dashboard.indexes"}</th>
                <th style="text-align:center; padding:6px 8px; border-bottom:2px solid #ccc;">{translate key="plugins.generic.pflPlugin.dashboard.orgs"}</th>
                <th style="text-align:left; padding:6px 8px; border-bottom:2px solid #ccc;">{translate key="plugins.generic.pfl.academicSociety"}</th>
                <th style="text-align:left; padding:6px 8px; border-bottom:2px solid #ccc;">{translate key="plugins.generic.pflPlugin.settings.dateStart"}</th>
                <th style="text-align:center; padding:6px 8px; border-bottom:2px solid #ccc;">{translate key="plugins.generic.pflPlugin.dashboard.cachedStats"}</th>
            </tr>
        </thead>
        <tbody>
            {foreach from=$journalRows item="row"}
            <tr style="border-bottom:1px solid #eee;">
                <td style="padding:6px 8px;">
                    <strong>{$row.title|escape}</strong>
                    {if $row.path}<br /><small style="color:#666;">/{$row.path|escape}</small>{/if}
                </td>
                <td style="text-align:center; padding:6px 8px;">
                    {if $row.enabled}
                        <span style="color:green; font-weight:bold;">&#10003;</span>
                    {else}
                        <span style="color:#aaa;">&#8212;</span>
                    {/if}
                </td>
                <td style="text-align:center; padding:6px 8px;">
                    {if $row.indexCount > 0}
                        <span style="color:green;">{$row.indexCount}</span>
                    {else}
                        <span style="color:#aaa;">0</span>
                    {/if}
                </td>
                <td style="text-align:center; padding:6px 8px;">
                    {if $row.orgCount > 0}
                        <span style="color:green;">{$row.orgCount}</span>
                    {else}
                        <span style="color:#aaa;">0</span>
                    {/if}
                </td>
                <td style="padding:6px 8px;">{$row.academicSociety|escape}</td>
                <td style="padding:6px 8px;">
                    {if $row.dateStart}
                        {$row.dateStart|escape}
                    {else}
                        <span style="color:#aaa;">{translate key="common.none"}</span>
                    {/if}
                </td>
                <td style="text-align:center; padding:6px 8px;" id="pfl-cache-cell-{$row.id}">
                    {if $row.hasCachedStats}
                        <span style="color:green;">&#10003;</span>
                    {else}
                        <span style="color:#aaa;">&#8212;</span>
                    {/if}
                    <button
                        type="button"
                        title="{translate key="plugins.generic.pflPlugin.clearCache"}"
                        style="margin-left:4px; font-size:0.75em; padding:1px 6px; cursor:pointer; vertical-align:middle;"
                        onclick="pflClearJournalCache(this, {$row.id|escape:'javascript'})">
                        {translate key="plugins.generic.pflPlugin.dashboard.clearCache"}
                    </button>
                </td>
            </tr>
            {foreachelse}
            <tr>
                <td colspan="7" style="padding:12px 8px; text-align:center; color:#666;">
                    {translate key="plugins.generic.pflPlugin.dashboard.noJournals"}
                </td>
            </tr>
            {/foreach}
        </tbody>
    </table>

    <p style="margin-top:1em; color:#666; font-size:0.9em;">
        {translate key="plugins.generic.pflPlugin.dashboard.legend"}
    </p>
</div>

<script>
(function() {
    var clearUrl = {$clearJournalCacheUrl|json_encode};

    window.pflClearJournalCache = function(btn, journalId) {
        btn.disabled = true;
        btn.textContent = '…';
        jQuery.getJSON(clearUrl, {ldelim}journalId: journalId{rdelim}, function(data) {
            var cell = document.getElementById('pfl-cache-cell-' + journalId);
            if (data && data.status) {
                cell.innerHTML = '<span style="color:#aaa;">&#8212;</span>'
                    + ' <button type="button" title="{translate key="plugins.generic.pflPlugin.clearCache"|escape:'javascript'}"'
                    + ' style="margin-left:4px;font-size:0.75em;padding:1px 6px;cursor:pointer;vertical-align:middle;"'
                    + ' onclick="pflClearJournalCache(this,' + journalId + ')">'
                    + btn.textContent + '</button>';
            } else {
                btn.disabled = false;
                btn.textContent = '{translate key="plugins.generic.pflPlugin.dashboard.clearCache"|escape:'javascript'}';
            }
        }).fail(function() {
            btn.disabled = false;
            btn.textContent = '{translate key="plugins.generic.pflPlugin.dashboard.clearCache"|escape:'javascript'}';
        });
    };
}());
</script>
