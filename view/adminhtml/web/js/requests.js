define([], function () {
    'use strict';

    return function (config, element) {
        const button = element.querySelector('[data-role="toggle-auto-refresh"]');
        const status = element.querySelector('[data-role="refresh-status"]');
        const list = element.querySelector('[data-role="requests-list"]');
        let refreshTimer = null;

        function appendCell(row, value) {
            const cell = document.createElement('td');
            cell.textContent = value;
            row.appendChild(cell);
        }

        function render(requests) {
            list.replaceChildren();

            if (!requests.length) {
                const notice = document.createElement('div');
                notice.className = 'message message-notice notice';
                const message = document.createElement('div');
                message.textContent = 'There are no currently rate-limited requests.';
                notice.appendChild(message);
                list.appendChild(notice);
                return;
            }

            const table = document.createElement('table');
            table.className = 'data-grid';
            const headings = [
                'Request URL',
                'Concurrent Requests at Rejection',
                'Marker Expires In',
            ];
            const head = document.createElement('thead');
            const headingRow = document.createElement('tr');
            headings.forEach(function (heading) {
                const cell = document.createElement('th');
                cell.className = 'data-grid-th';
                const label = document.createElement('span');
                label.textContent = heading;
                cell.appendChild(label);
                headingRow.appendChild(cell);
            });
            head.appendChild(headingRow);
            table.appendChild(head);

            const body = document.createElement('tbody');
            requests.forEach(function (request) {
                const row = document.createElement('tr');
                appendCell(row, request.path);
                appendCell(row, String(request.concurrent_requests));
                appendCell(row, request.expires_in + ' seconds');
                body.appendChild(row);
            });
            table.appendChild(body);
            list.appendChild(table);
        }

        function refresh() {
            fetch(config.url, {
                credentials: 'same-origin',
                headers: {'X-Requested-With': 'XMLHttpRequest'},
            })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('Request failed with status ' + response.status);
                    }
                    return response.json();
                })
                .then(function (data) {
                    render(data.requests || []);
                    status.textContent = 'Updated at ' + new Date().toLocaleTimeString();
                })
                .catch(function () {
                    status.textContent = 'Refresh failed. Check your connection and permissions.';
                });
        }

        button.addEventListener('click', function () {
            if (refreshTimer) {
                window.clearInterval(refreshTimer);
                refreshTimer = null;
                button.setAttribute('aria-pressed', 'false');
                button.querySelector('span').textContent = 'Start Auto Refresh';
                status.textContent = 'Auto refresh is off.';
                return;
            }

            refresh();
            refreshTimer = window.setInterval(refresh, config.interval || 5000);
            button.setAttribute('aria-pressed', 'true');
            button.querySelector('span').textContent = 'Stop Auto Refresh';
            status.textContent = 'Refreshing every 5 seconds.';
        });
    };
});