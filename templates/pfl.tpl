{**
* templates/pfl.tpl
*
* Copyright (c) 2023 Simon Fraser University
* Copyright (c) 2023 John Willinsky
* Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
*
* Journal Integrity Initiative Publication Facts Label template
*}

<section class="item pflPlugin">

<publication-facts-label></publication-facts-label>
<script>

window.addEventListener("DOMContentLoaded", () => {
  var pflData = {$pflData|json_encode};
  {literal}

  function applyPflLabels(labels) {
    var pfl = document.querySelector('publication-facts-label');
    pfl.data = Object.assign({}, pflData, {labels: labels});
  }

  const localeUrl = pflData.baseUrl + '/locale/' + pflData.locale + '.json';
  const fallbackUrl = pflData.baseUrl + '/locale/en.json';

  fetch(localeUrl)
    .then(function(response) {
      if (!response.ok) throw new Error('locale not found');
      return response.json();
    })
    .then(function(labels) {
      applyPflLabels(labels);
    })
    .catch(function() {
      // Fall back to English if the locale file is not available (Issue #41)
      fetch(fallbackUrl)
        .then(function(response) {
          if (!response.ok) throw new Error('fallback locale not found');
          return response.json();
        })
        .then(function(labels) {
          applyPflLabels(labels);
        })
        .catch(function(err) {
          console.warn('PFL: failed to load translations', err);
        });
    });
  {/literal}

  });

</script>

</section>
