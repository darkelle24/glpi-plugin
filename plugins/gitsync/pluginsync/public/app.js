(function () {
  'use strict';
  var root = document.querySelector('[data-git-sync]');
  if (!root) return;
  root.classList.add('has-js');
  var labels = {idle: 'Jamais lancé', queued: 'En cours', running: 'En cours', success: 'Succès', error: 'Erreur'};
  var formatter = new Intl.DateTimeFormat('fr-FR', {dateStyle: 'short', timeStyle: 'short'});
  function dateText(value, empty) {
    if (!value) return empty;
    var date = new Date(value);
    return Number.isNaN(date.getTime()) ? empty : formatter.format(date);
  }
  root.querySelectorAll('time[datetime]').forEach(function (node) {
    if (node.dateTime) node.textContent = dateText(node.dateTime, node.textContent);
  });
  root.querySelectorAll('[data-auto-submit]').forEach(function (input) {
    input.addEventListener('change', function () { input.form.requestSubmit(); });
  });
  function update(data) {
    data.jobs.forEach(function (job) {
      var card = root.querySelector('[data-job-id="' + Number(job.id) + '"]');
      if (!card) return;
      var badge = card.querySelector('[data-job-status]');
      badge.className = 'gs-status gs-status-' + (labels[job.status] ? job.status : 'idle');
      badge.textContent = labels[job.status] || 'Inconnu';
      var busy = job.status === 'queued' || job.status === 'running';
      card.querySelectorAll('[data-busy-control]').forEach(function (input) { input.disabled = busy; });
      card.querySelector('[data-edit-link]').classList.toggle('gs-disabled', busy);
      var message = card.querySelector('[data-job-message]');
      message.textContent = job.message || '';
      message.classList.toggle('gs-error-text', job.status === 'error');
      var time = card.querySelector('[data-job-time]');
      time.dateTime = job.last_attempt || '';
      time.textContent = dateText(job.last_attempt, 'Aucun pour le moment');
      var next = card.querySelector('[data-job-next]');
      next.dateTime = job.next_run_at || '';
      next.textContent = dateText(job.next_run_at, 'désactivé');
      card.querySelector('[data-auto-submit]').checked = !!job.auto_pull;
    });
    var counts = data.counts || {};
    root.querySelector('[data-count-running]').textContent = (counts.running || 0) + (counts.queued || 0);
    root.querySelector('[data-count-success]').textContent = counts.success || 0;
    root.querySelector('[data-count-error]').textContent = counts.error || 0;
  }
  var stopped = false;
  async function refresh() {
    if (stopped) return;
    if (!document.hidden) {
      var controller = new AbortController();
      var timer = setTimeout(function () { controller.abort(); }, 15000);
      try {
        var response = await fetch(root.dataset.statusUrl, {
          credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
          headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}
        });
        if (!response.ok || !response.headers.get('Content-Type').includes('application/json')) throw new Error('session');
        var data = await response.json();
        if (!Array.isArray(data.jobs)) throw new Error('response');
        update(data);
      } catch (_error) {
        stopped = true;
        var live = root.querySelector('[data-live-indicator]');
        if (live) { live.textContent = 'Suivi interrompu : rechargez la page'; live.classList.add('is-offline'); }
      } finally { clearTimeout(timer); }
    }
    if (!stopped) setTimeout(refresh, 5000);
  }
  if (root.querySelector('[data-live-indicator]')) setTimeout(refresh, 5000);
})();
