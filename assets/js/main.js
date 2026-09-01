// Polls the unread notification count and keeps the on-page badge current.
// AJAX polling is the project's stand-in for push — no WebSockets anywhere.
(function () {
    var POLL_INTERVAL_MS = 15000;

    var badge = document.getElementById('unread-badge');
    var link = document.getElementById('notifications-link');

    if (!badge && !link) {
        return;
    }

    function render(count) {
        var text = count > 0 ? '(' + count + ')' : '';
        if (badge) {
            badge.textContent = text;
        }
        if (link) {
            link.textContent = count > 0 ? 'Notifications (' + count + ')' : 'Notifications';
        }
    }

    function poll() {
        fetch('../ajax/poll_notifications.php', { credentials: 'same-origin' })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('Poll failed: ' + response.status);
                }
                return response.json();
            })
            .then(function (data) {
                render(data.unread);
            })
            .catch(function () {
                // A failed poll is not worth interrupting the page over —
                // the next tick will try again.
            });
    }

    poll();
    setInterval(poll, POLL_INTERVAL_MS);
})();
