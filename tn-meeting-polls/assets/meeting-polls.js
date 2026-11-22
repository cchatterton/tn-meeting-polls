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

  var dates = document.getElementById('tnmp-dates');
  if (dates) {
    var updateSlotButtons = function () {
      var full = dates.querySelectorAll('.tnmp-time-row').length >= 10;
      dates.querySelectorAll('.tnmp-add-time').forEach(function (button) { button.disabled = full; });
      var addDate = document.getElementById('tnmp-add-date');
      if (addDate) addDate.disabled = full;
    };
    dates.addEventListener('click', function (event) {
      var button = event.target.closest('.tnmp-add-time');
      if (!button || dates.querySelectorAll('.tnmp-time-row').length >= 10) return;
      var group = button.closest('.tnmp-date-group');
      var row = group.querySelector('.tnmp-time-row').cloneNode(true);
      row.querySelector('input[type="time"]').value = '';
      row.querySelector('input[type="number"]').value = '60';
      group.querySelector('.tnmp-times').appendChild(row);
      updateSlotButtons();
    });
    document.getElementById('tnmp-add-date').addEventListener('click', function () {
      if (dates.querySelectorAll('.tnmp-time-row').length >= 10) return;
      var index = dates.children.length;
      var group = dates.firstElementChild.cloneNode(true);
      group.dataset.dateIndex = String(index);
      group.querySelector('input[type="date"]').value = '';
      group.querySelectorAll('.tnmp-time-row').forEach(function (row, position) {
        if (position > 0) row.remove();
      });
      group.querySelectorAll('input').forEach(function (input) {
        input.name = input.name.replace(/\[\d+\]/, '[' + index + ']');
      });
      group.querySelector('input[type="time"]').value = '';
      group.querySelector('input[type="number"]').value = '60';
      dates.appendChild(group);
      updateSlotButtons();
    });
    updateSlotButtons();
  }

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
