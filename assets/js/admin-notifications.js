(function () {
  'use strict';

  var bell = document.querySelector('.topbar__notif') || document.querySelector('.topbar__notif-btn');
  if (!bell || bell.dataset.notifBound === '1') {
    return;
  }
  bell.dataset.notifBound = '1';

  var dot = document.querySelector('.topbar__notif-dot');
  var dropdown = null;
  var isOpen = false;

  if (!/^(A|BUTTON)$/i.test(bell.tagName)) {
    bell.setAttribute('role', 'button');
    bell.setAttribute('tabindex', '0');
  }
  bell.setAttribute('aria-expanded', 'false');

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/\"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function ensureDropdown() {
    if (dropdown) {
      return dropdown;
    }

    dropdown = document.createElement('div');
    dropdown.id = 'admin-notif-dropdown';
    dropdown.style.position = 'fixed';
    dropdown.style.width = '360px';
    dropdown.style.maxWidth = 'calc(100vw - 24px)';
    dropdown.style.background = '#ffffff';
    dropdown.style.border = '1px solid #e5e7eb';
    dropdown.style.borderRadius = '12px';
    dropdown.style.boxShadow = '0 18px 40px rgba(2, 8, 23, 0.16)';
    dropdown.style.overflow = 'hidden';
    dropdown.style.zIndex = '10000';
    dropdown.style.display = 'none';
    dropdown.setAttribute('role', 'dialog');
    dropdown.setAttribute('aria-label', 'Notifications');

    document.body.appendChild(dropdown);
    return dropdown;
  }

  function positionDropdown() {
    if (!dropdown || !isOpen) {
      return;
    }

    var rect = bell.getBoundingClientRect();
    var width = Math.min(360, window.innerWidth - 24);
    dropdown.style.width = width + 'px';

    var left = rect.right - width;
    if (left < 12) {
      left = 12;
    }
    if (left + width > window.innerWidth - 12) {
      left = window.innerWidth - width - 12;
    }

    dropdown.style.left = left + 'px';
    dropdown.style.top = (rect.bottom + 10) + 'px';
  }

  function updateBell(count) {
    if (!dot) {
      return;
    }

    var unread = Number(count) || 0;
    if (unread > 0) {
      dot.style.display = '';
      dot.textContent = unread > 99 ? '99+' : String(unread);
      dot.setAttribute('aria-label', unread + ' new notifications');
    } else {
      dot.style.display = 'none';
      dot.textContent = '';
      dot.setAttribute('aria-label', 'No new notifications');
    }
  }

  function fetchJson(url) {
    return fetch(url, { credentials: 'same-origin', cache: 'no-store' }).then(function (response) {
      if (!response.ok) {
        throw new Error('HTTP ' + response.status);
      }
      return response.json();
    });
  }

  function renderItems(items) {
    ensureDropdown();

    if (!Array.isArray(items) || items.length === 0) {
      dropdown.innerHTML = '<div style="padding:14px 16px;color:#6b7280;font-size:14px;">No recent notifications.</div>';
      return;
    }

    var html = '<div style="padding:10px 14px;border-bottom:1px solid #f1f5f9;font-weight:700;font-size:14px;color:#0f172a;">Recent Notifications</div>';
    html += '<div style="max-height:340px;overflow:auto;">';

    items.forEach(function (item) {
      var linkUrl = escapeHtml(item.link_url || '#');
      var title = escapeHtml(item.title || item.student_code || 'Notification');
      var office = escapeHtml(item.preferred_office || item.type || 'General');
      var snippet = escapeHtml(item.snippet || 'No details provided.');
      var createdAt = escapeHtml(item.created_at || '');

      html += '<a href="' + linkUrl + '" style="display:block;padding:12px 14px;border-bottom:1px solid #f8fafc;text-decoration:none;color:#111827;">';
      html += '<div style="display:flex;justify-content:space-between;gap:8px;">';
      html += '<strong style="font-size:13px;">' + title + '</strong>';
      html += '<span style="font-size:12px;color:#6b7280;white-space:nowrap;">' + createdAt + '</span>';
      html += '</div>';
      html += '<div style="font-size:12px;color:#374151;margin-top:2px;">' + office + '</div>';
      html += '<div style="font-size:13px;color:#4b5563;margin-top:6px;line-height:1.35;">' + snippet + '</div>';
      html += '</a>';
    });

    html += '</div>';
    dropdown.innerHTML = html;
  }

  function openDropdown() {
    ensureDropdown();
    dropdown.style.display = '';
    bell.setAttribute('aria-expanded', 'true');
    isOpen = true;
    positionDropdown();
  }

  function closeDropdown() {
    if (!dropdown) {
      return;
    }
    dropdown.style.display = 'none';
    bell.setAttribute('aria-expanded', 'false');
    isOpen = false;
  }

  function toggleDropdown() {
    if (isOpen) {
      closeDropdown();
      return;
    }

    fetchJson('notifications_list.php')
      .then(function (data) {
        if (data && data.success) {
          renderItems(data.items || []);
          openDropdown();
        }
      })
      .catch(function () {
        ensureDropdown();
        dropdown.innerHTML = '<div style="padding:14px 16px;color:#b91c1c;font-size:14px;">Unable to load notifications.</div>';
        openDropdown();
      });
  }

  function pollCount() {
    fetchJson('notifications_count.php')
      .then(function (data) {
        if (data && data.success) {
          updateBell(data.count || 0);
        }
      })
      .catch(function () {
        // Ignore temporary failures so page behavior remains stable.
      });
  }

  bell.addEventListener('click', function (event) {
    event.preventDefault();
    toggleDropdown();
  });

  bell.addEventListener('keydown', function (event) {
    if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      toggleDropdown();
    }
  });

  document.addEventListener('click', function (event) {
    if (!isOpen) {
      return;
    }
    if (event.target.closest && (event.target.closest('.topbar__notif') || event.target.closest('.topbar__notif-btn') || event.target.closest('#admin-notif-dropdown'))) {
      return;
    }
    closeDropdown();
  });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
      closeDropdown();
    }
  });

  window.addEventListener('resize', positionDropdown);
  window.addEventListener('scroll', positionDropdown, true);

  pollCount();
  setInterval(pollCount, 10000);
})();
