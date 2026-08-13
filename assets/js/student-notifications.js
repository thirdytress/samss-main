(function () {
  'use strict';

  var bell = document.getElementById('notif-toggle') || 
             document.querySelector('.topbar__icon-btn[aria-label="Notifications"]') || 
             document.querySelector('.topbar__action-btn[aria-label="Notifications"]');
  if (!bell || bell.dataset.notifBound === '1') {
    return;
  }
  bell.dataset.notifBound = '1';

  var dot = bell.querySelector('.topbar__notif-dot') || bell.querySelector('.topbar__badge') || document.querySelector('.topbar__notif-dot');
  var dropdown = null;
  var isOpen = false;

  // Set accessibility attributes
  if (!/^(A|BUTTON)$/i.test(bell.tagName)) {
    bell.setAttribute('role', 'button');
    bell.setAttribute('tabindex', '0');
  }
  bell.setAttribute('aria-expanded', 'false');

  function escapeHtml(s) {
    return String(s).replace(/[&<>\"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function getRelativeTime(dateStr) {
    var date = new Date(dateStr);
    var now = new Date();
    var diffMs = now - date;
    var diffSecs = Math.floor(diffMs / 1000);
    var diffMins = Math.floor(diffSecs / 60);
    var diffHours = Math.floor(diffMins / 60);
    var diffDays = Math.floor(diffHours / 24);

    if (diffMins < 1) return 'Just now';
    if (diffMins < 60) return diffMins + ' min ago';
    if (diffHours < 24) return diffHours + ' hours ago';
    return diffDays + ' days ago';
  }

  function ensureDropdown() {
    if (dropdown) {
      return dropdown;
    }

    dropdown = document.createElement('div');
    dropdown.id = 'student-notif-dropdown';
    dropdown.style.position = 'fixed';
    dropdown.style.width = '480px';
    dropdown.style.maxWidth = 'calc(100vw - 24px)';
    dropdown.style.background = '#ffffff';
    dropdown.style.border = '1px solid #e5e7eb';
    dropdown.style.borderRadius = '12px';
    dropdown.style.boxShadow = '0 18px 40px rgba(2, 8, 23, 0.16)';
    dropdown.style.overflow = 'hidden';
    dropdown.style.zIndex = '10000';
    dropdown.style.display = 'none';
    dropdown.setAttribute('role', 'dialog');
    dropdown.setAttribute('aria-label', 'Announcements');

    document.body.appendChild(dropdown);
    return dropdown;
  }

  function positionDropdown() {
    if (!dropdown || !isOpen) {
      return;
    }

    var rect = bell.getBoundingClientRect();
    var width = Math.min(480, window.innerWidth - 24);
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
      dot = bell.querySelector('.topbar__notif-dot') || bell.querySelector('.topbar__badge') || document.querySelector('.topbar__notif-dot');
    }
    var unread = Number(count) || 0;
    if (unread > 0) {
      if (dot) {
        dot.style.display = 'flex';
        dot.textContent = String(unread);
      }
    } else {
      if (dot) {
        dot.style.display = 'none';
        dot.textContent = '';
      }
    }
  }

  function fetchJson(url, options) {
    return fetch(url, options).then(function (response) {
      if (!response.ok) {
        throw new Error('HTTP ' + response.status);
      }
      return response.json();
    });
  }

  function renderItems(data) {
    ensureDropdown();

    var html = '<div style="padding:12px 14px;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center;">';
    html += '<span style="font-weight:700;font-size:14px;color:#0f172a;">Announcements</span>';
    if (data && Array.isArray(data.announcements) && data.unread_count > 0) {
      html += '<button id="student-notif-mark-all" style="background:none;border:none;color:#2563eb;font-size:12px;font-weight:600;cursor:pointer;padding:2px 6px;">Mark all as read</button>';
    }
    html += '</div>';

    if (!data || !Array.isArray(data.announcements) || data.announcements.length === 0) {
      dropdown.innerHTML = html + '<div style="padding:14px 16px;color:#6b7280;font-size:14px;">No announcements.</div>';
      return;
    }

    html += '<div style="max-height:360px;overflow:auto;">';
    data.announcements.forEach(function (a) {
      var rel = getRelativeTime(a.created_at);
      var isRead = parseInt(a.is_read, 10);
      var opacity = isRead ? 'style="opacity: 0.6;"' : '';
      
      html += '<div class="student-notif-item" data-id="' + a.id + '" ' + opacity + ' style="padding:12px 14px;border-bottom:1px solid #f8fafc;cursor:pointer;">';
      html += '<div style="font-weight:700;font-size:13px;color:#111827;">' + escapeHtml(a.title) + '</div>';
      html += '<div style="font-size:13px;color:#4b5563;margin-top:6px;line-height:1.35;">' + escapeHtml(a.body).replace(/\n/g, '<br>') + '</div>';
      html += '<div style="font-size:12px;color:#999;margin-top:6px;">' + escapeHtml(rel) + '</div>';
      html += '</div>';
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

  function refreshList() {
    return fetchJson('../api/announcements/list.php', { credentials: 'same-origin' })
      .then(function (data) {
        if (data) {
          renderItems(data);
          updateBell(data.unread_count || 0);
        }
      });
  }

  function toggleDropdown() {
    if (isOpen) {
      closeDropdown();
      return;
    }

    refreshList()
      .then(function () {
        openDropdown();
      })
      .catch(function (err) {
        ensureDropdown();
        dropdown.innerHTML = '<div style="padding:14px 16px;color:#b91c1c;font-size:14px;">Unable to load announcements.</div>';
        openDropdown();
        console.error('Failed to load announcements:', err);
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
    if (event.target.closest && (event.target.closest('#notif-toggle') || event.target.closest('.topbar__icon-btn') || event.target.closest('.topbar__action-btn') || event.target.closest('#student-notif-dropdown'))) {
      return;
    }
    closeDropdown();
  });

  // Handle Mark Single Announcement as Read
  document.addEventListener('click', function (event) {
    var item = event.target && event.target.closest ? event.target.closest('.student-notif-item') : null;
    if (!item) {
      return;
    }

    var id = parseInt(item.getAttribute('data-id'), 10);
    if (id > 0 && item.style.opacity !== '0.6') {
      fetch('../api/announcements/mark_read.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.SAMS_CSRF || '' },
        credentials: 'same-origin',
        body: JSON.stringify({ announcement_id: id })
      })
      .then(function () {
        item.style.opacity = '0.6';
        // Refresh silently to get new count
        fetchJson('../api/announcements/list.php', { credentials: 'same-origin' })
          .then(function (data) {
            if (data) {
              updateBell(data.unread_count || 0);
            }
          });
      })
      .catch(function (err) {
        console.error('Failed to mark read:', err);
      });
    }
  });

  // Handle Mark All as Read
  document.addEventListener('click', function (event) {
    if (event.target && event.target.id === 'student-notif-mark-all') {
      event.preventDefault();
      var btn = event.target;
      btn.disabled = true;
      btn.textContent = 'Marking...';

      fetch('../api/announcements/mark_read.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.SAMS_CSRF || '' },
        credentials: 'same-origin',
        body: JSON.stringify({ mark_all: true })
      })
      .then(function () {
        updateBell(0);
        return refreshList();
      })
      .catch(function (err) {
        console.error('Failed to mark all read:', err);
        btn.disabled = false;
        btn.textContent = 'Mark all as read';
      });
    }
  });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
      closeDropdown();
    }
  });

  window.addEventListener('resize', positionDropdown);
  window.addEventListener('scroll', positionDropdown, true);

  // Poll count periodically
  setInterval(function () {
    fetchJson('../api/announcements/list.php', { credentials: 'same-origin' })
      .then(function (data) {
        if (data) {
          updateBell(data.unread_count || 0);
        }
      })
      .catch(function () {
        // Ignore errors
      });
  }, 10000);
})();
