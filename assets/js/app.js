/* DSAU portal - small UI helpers (no dependencies) */
(function () {
  'use strict';

  // Mobile side-nav toggle
  var btn = document.querySelector('.menu-btn');
  var overlay = document.querySelector('.overlay');
  var themeSelect = document.getElementById('admin-theme-select');
  function closeNav() { document.body.classList.remove('nav-open'); }
  if (btn) btn.addEventListener('click', function () { document.body.classList.toggle('nav-open'); });
  if (overlay) overlay.addEventListener('click', closeNav);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeNav(); });

  if (themeSelect) {
    var themes = ['dsau', 'ocean', 'forest', 'violet', 'dark', 'blue', 'orange', 'golden', 'blue-orange', 'blue-orange-white'];
    var savedTheme = document.documentElement.getAttribute('data-admin-theme') || 'dsau';
    themeSelect.value = savedTheme;
    themeSelect.addEventListener('change', function () {
      var theme = themes.indexOf(themeSelect.value) !== -1 ? themeSelect.value : 'dsau';
      document.documentElement.setAttribute('data-admin-theme', theme);
      localStorage.setItem('dsau-admin-theme', theme);
    });
  }

  // Dismiss alerts
  document.addEventListener('click', function (e) {
    if (e.target.classList.contains('alert-close')) e.target.parentElement.remove();
  });
  setTimeout(function () {
    document.querySelectorAll('.alert-success').forEach(function (a) { a.remove(); });
  }, 6000);

  // Confirm dialogs: <button data-confirm="Are you sure?">
  document.addEventListener('click', function (e) {
    var el = e.target.closest('[data-confirm]');
    if (el && !window.confirm(el.getAttribute('data-confirm'))) e.preventDefault();
  });

  // Show/hide "Other (please specify)" text boxes:
  // <input type=radio|checkbox data-other-toggle="#target" data-other-value="Other">
  function syncOther(group) {
    var inputs = document.querySelectorAll('[data-other-group="' + group + '"]');
    var target = document.querySelector('[data-other-target="' + group + '"]');
    if (!target) return;
    var show = false;
    inputs.forEach(function (i) { if (i.checked && i.value === 'Other') show = true; });
    target.style.display = show ? '' : 'none';
  }
  document.querySelectorAll('[data-other-group]').forEach(function (i) {
    i.addEventListener('change', function () { syncOther(i.getAttribute('data-other-group')); });
  });
  var groups = {};
  document.querySelectorAll('[data-other-group]').forEach(function (i) { groups[i.getAttribute('data-other-group')] = 1; });
  Object.keys(groups).forEach(syncOther);

  // Public form: live fee preview based on course + level
  var courseSel = document.getElementById('course_id');
  var feeBox = document.getElementById('fee-preview');
  var topics = document.getElementById('course-topics');
  function fmt(n) { return 'UGX ' + Number(n).toLocaleString('en-US'); }
  function updateFee() {
    if (!courseSel || !feeBox) return;
    var opt = courseSel.options[courseSel.selectedIndex];
    var level = (document.querySelector('input[name="level"]:checked') || {}).value;
    if (!opt || !opt.value) { feeBox.style.display = 'none'; if (topics) topics.innerHTML = ''; return; }
    var fee = level === 'Advanced' ? opt.getAttribute('data-fee-advanced') : opt.getAttribute('data-fee-beginner');
    var dur = opt.getAttribute('data-duration');
    feeBox.style.display = '';
    feeBox.textContent = 'Tuition for this course (' + (level || 'Beginner') + '): ' + fmt(fee) + ' • ' + dur + ' months • Online • Installments allowed';
    if (topics) {
      var list = (opt.getAttribute('data-topics') || '').split('|').filter(Boolean);
      topics.innerHTML = list.length ? '<div class="small muted mt-1">You will cover:</div><ul class="topic-list"></ul>' : '';
      var ul = topics.querySelector('ul');
      list.forEach(function (t) { var li = document.createElement('li'); li.textContent = t; ul.appendChild(li); });
    }
  }
  if (courseSel) {
    courseSel.addEventListener('change', updateFee);
    document.querySelectorAll('input[name="level"]').forEach(function (r) { r.addEventListener('change', updateFee); });
    updateFee();
  }

  // Prevent double submit
  document.querySelectorAll('form[data-once]').forEach(function (f) {
    f.addEventListener('submit', function () {
      var b = f.querySelector('button[type=submit]');
      if (b) { b.disabled = true; b.textContent = 'Submitting…'; }
    });
  });
})();
