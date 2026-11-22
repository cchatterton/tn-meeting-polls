(function () {
  function bindConfirm(root) {
    root.querySelectorAll('form[data-confirm]').forEach(function (form) {
      form.addEventListener('submit', function (event) {
        if (!window.confirm(form.dataset.confirm)) event.preventDefault();
      });
    });
  }

  function addAttendeeRow(container) {
    if (!container || container.children.length >= 50) return null;
    var row = container.lastElementChild.cloneNode(true);
    row.querySelectorAll('input').forEach(function (input) {
      input.value = '';
      input.checked = false;
      input.required = false;
    });
    container.appendChild(row);
    syncAttendeeRows(container);
    return row;
  }

  function syncAttendeeRows(container) {
    Array.from(container.querySelectorAll('.tnmp-attendee-row')).forEach(function (row, index) {
      row.querySelector('input[type="checkbox"]').name = 'holds[' + index + ']';
      row.querySelector('input[type="email"]').required = index === 0;
    });
  }

  document.querySelectorAll('.tnmp-add').forEach(function (button) {
    button.addEventListener('click', function () {
      var container = document.getElementById(button.dataset.target);
      if (container && container.children.length < Number(button.dataset.limit)) addAttendeeRow(container);
    });
  });

  var contactSearch = document.getElementById('tnmp-contact-search');
  var contactMatches = document.getElementById('tnmp-contact-matches');
  var attendeeRows = document.getElementById('tnmp-attendees');
  if (attendeeRows) {
    attendeeRows.addEventListener('click', function (event) {
      if (!event.target.closest('.tnmp-remove-attendee')) return;
      var row = event.target.closest('.tnmp-attendee-row');
      if (attendeeRows.children.length > 1) row.remove();
      else row.querySelectorAll('input').forEach(function (input) { input.value = ''; input.checked = false; });
      syncAttendeeRows(attendeeRows);
    });
  }
  if (contactSearch && contactMatches && attendeeRows) {
    var matches = Array.from(contactMatches.querySelectorAll('.tnmp-contact-match'));
    var filterContacts = function () {
      var query = contactSearch.value.trim().toLocaleLowerCase();
      var shown = 0;
      matches.forEach(function (match) {
        var email = match.dataset.email.toLocaleLowerCase();
        var name = match.dataset.name.toLocaleLowerCase();
        var alreadyAdded = Array.from(attendeeRows.querySelectorAll('input[type="email"]')).some(function (input) {
          return input.value.trim().toLocaleLowerCase() === email;
        });
        var visible = query.length > 0 && !alreadyAdded && shown < 8 && (name.includes(query) || email.includes(query));
        match.hidden = !visible;
        if (visible) shown++;
      });
    };
    contactSearch.addEventListener('input', filterContacts);
    contactMatches.addEventListener('click', function (event) {
      var button = event.target.closest('.tnmp-pick-contact');
      if (!button) return;
      var contact = button.closest('.tnmp-contact-match');
      var rows = Array.from(attendeeRows.querySelectorAll('.tnmp-attendee-row'));
      var row = rows.find(function (item) { return !item.querySelector('input[type="email"]').value.trim(); });
      if (!row) row = addAttendeeRow(attendeeRows);
      if (!row) return;
      row.querySelector('input[type="email"]').value = contact.dataset.email;
      row.querySelector('input[type="text"]').value = contact.dataset.name;
      contactSearch.value = '';
      filterContacts();
      row.querySelector('input[type="email"]').focus();
    });
  }

  var dates = document.getElementById('tnmp-dates');
  if (dates) {
    var duration = document.querySelector('input[name="duration"]');
    var updateTimeBounds = function () {
      if (dates.dataset.editing === '1') return;
      var earliest = Number(dates.dataset.earliest) * 60;
      var latest = Number(dates.dataset.latest) * 60 - Number(duration.value || 0);
      var minimum = String(Math.floor(earliest / 60)).padStart(2, '0') + ':' + String(earliest % 60).padStart(2, '0');
      var maximum = String(Math.max(0, Math.floor(latest / 60))).padStart(2, '0') + ':' + String(Math.max(0, latest % 60)).padStart(2, '0');
      dates.querySelectorAll('input[type="time"]').forEach(function (input) {
        input.min = minimum;
        input.max = maximum;
      });
    };
    var updateSlotButtons = function () {
      var full = dates.querySelectorAll('.tnmp-time-row').length >= 10;
      dates.querySelectorAll('.tnmp-add-time').forEach(function (button) { button.disabled = full; });
      var addDate = document.getElementById('tnmp-add-date');
      if (addDate) addDate.disabled = full;
    };
    dates.addEventListener('click', function (event) {
      var removeTime = event.target.closest('.tnmp-remove-time');
      if (removeTime) {
        var timeRow = removeTime.closest('.tnmp-time-row');
        var timeGroup = timeRow.closest('.tnmp-date-group');
        if (timeGroup.querySelectorAll('.tnmp-time-row').length > 1) timeRow.remove();
        else if (dates.children.length > 1) timeGroup.remove();
        else timeRow.querySelector('input[type="time"]').value = '';
        updateSlotButtons();
        return;
      }
      var removeDate = event.target.closest('.tnmp-remove-date');
      if (removeDate) {
        var dateGroup = removeDate.closest('.tnmp-date-group');
        if (dates.children.length > 1) dateGroup.remove();
        else {
          dateGroup.querySelector('input[type="date"]').value = '';
          dateGroup.querySelectorAll('.tnmp-time-row').forEach(function (row, index) {
            if (index > 0) row.remove();
            else row.querySelector('input[type="time"]').value = '';
          });
        }
        updateSlotButtons();
        return;
      }
      var button = event.target.closest('.tnmp-add-time');
      if (!button || dates.querySelectorAll('.tnmp-time-row').length >= 10) return;
      var group = button.closest('.tnmp-date-group');
      var row = group.querySelector('.tnmp-time-row').cloneNode(true);
      row.querySelector('input[type="time"]').value = '';
      var closed = row.querySelector('.tnmp-closed');
      if (closed) closed.remove();
      group.querySelector('.tnmp-times').appendChild(row);
      updateTimeBounds();
      updateSlotButtons();
    });
    document.getElementById('tnmp-add-date').addEventListener('click', function () {
      if (dates.querySelectorAll('.tnmp-time-row').length >= 10) return;
      var index = Math.max.apply(null, Array.from(dates.children).map(function (group) { return Number(group.dataset.dateIndex); })) + 1;
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
      var closed = group.querySelector('.tnmp-closed');
      if (closed) closed.remove();
      dates.appendChild(group);
      updateTimeBounds();
      updateSlotButtons();
    });
    if (duration) duration.addEventListener('input', updateTimeBounds);
    updateTimeBounds();
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
