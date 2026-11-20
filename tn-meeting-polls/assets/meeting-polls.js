(function () {
  function bindConfirm(root) {
    root.querySelectorAll('form[data-confirm]').forEach(function (form) {
      form.addEventListener('submit', function (event) {
        if (!window.confirm(form.dataset.confirm)) event.preventDefault();
      });
    });
  }

  document.querySelectorAll('.tnmp-add').forEach(function (button) {
    button.addEventListener('click', function () {
      var container = document.getElementById(button.dataset.target);
      if (!container || container.children.length >= Number(button.dataset.limit)) return;
      var row = container.lastElementChild.cloneNode(true);
      row.querySelectorAll('input').forEach(function (input) {
        input.value = '';
        input.checked = false;
        input.required = false;
        if (input.type === 'checkbox') input.name = 'holds[' + container.children.length + ']';
      });
      container.appendChild(row);
    });
  });

  bindConfirm(document);
  var results = document.getElementById('tnmp-results');
  var refreshStatus = document.getElementById('tnmp-refresh-status');
  if (results && typeof tnmpData !== 'undefined') {
    window.setInterval(function () {
      if (document.hidden || results.contains(document.activeElement)) return;
      var body = new URLSearchParams({ action: 'tnmp_results', nonce: tnmpData.nonce, return_url: window.location.href });
      fetch(tnmpData.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
        .then(function (response) { return response.json(); })
        .then(function (data) {
          if (!data.success || !data.data || !data.data.html) throw new Error('Live update failed');
          var openDetails = Array.from(results.querySelectorAll('details[open]')).map(function (item) {
            return item.closest('[data-poll-id]').dataset.pollId;
          });
          results.innerHTML = data.data.html;
          openDetails.forEach(function (id) {
            var card = results.querySelector('[data-poll-id="' + id + '"]');
            if (card) card.querySelector('details').open = true;
          });
          bindConfirm(results);
          if (refreshStatus && refreshStatus.textContent) refreshStatus.textContent = 'Live updates resumed.';
        }).catch(function () {
          if (refreshStatus && refreshStatus.textContent !== 'Live updates paused. Refresh this page to retry.') {
            refreshStatus.textContent = 'Live updates paused. Refresh this page to retry.';
          }
        });
    }, 15000);
  }
})();
