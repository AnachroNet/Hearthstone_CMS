(function () {
    function initHomeEventCalendarTooltips() {
        var section = document.querySelector('.home-event-calendar');
        if (!section) {
            return;
        }

        var triggers = section.querySelectorAll('.has-event-tooltip[data-tooltip]');
        if (!triggers.length) {
            return;
        }

        var tooltip = document.createElement('div');
        tooltip.className = 'item-tooltip home-event-calendar-tooltip';
        tooltip.setAttribute('role', 'tooltip');
        tooltip.hidden = true;
        document.body.appendChild(tooltip);

        function placeTooltip(clientX, clientY) {
            tooltip.style.left = clientX + 14 + 'px';
            tooltip.style.top = clientY + 14 + 'px';
            tooltip.hidden = false;
            tooltip.style.display = 'block';

            var rect = tooltip.getBoundingClientRect();
            if (rect.right > window.innerWidth) {
                tooltip.style.left = Math.max(8, window.innerWidth - rect.width - 10) + 'px';
            }
            if (rect.bottom > window.innerHeight) {
                tooltip.style.top = Math.max(8, window.innerHeight - rect.height - 10) + 'px';
            }
        }

        function hideTooltip() {
            tooltip.style.display = 'none';
            tooltip.hidden = true;
        }

        triggers.forEach(function (el) {
            el.addEventListener('mouseenter', function (e) {
                var html = el.getAttribute('data-tooltip');
                if (!html) {
                    return;
                }
                tooltip.innerHTML = html;
                placeTooltip(e.clientX, e.clientY);
            });
            el.addEventListener('mousemove', function (e) {
                if (tooltip.style.display === 'block') {
                    placeTooltip(e.clientX, e.clientY);
                }
            });
            el.addEventListener('mouseleave', hideTooltip);
            el.addEventListener('focus', function () {
                var html = el.getAttribute('data-tooltip');
                if (!html) {
                    return;
                }
                tooltip.innerHTML = html;
                var r = el.getBoundingClientRect();
                placeTooltip(r.left + r.width / 2, r.top);
            });
            el.addEventListener('blur', hideTooltip);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initHomeEventCalendarTooltips);
    } else {
        initHomeEventCalendarTooltips();
    }
})();
