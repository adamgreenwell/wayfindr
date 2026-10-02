<script>
    (function () {
        var report = document.getElementById('support-report');
        var initialized = new WeakSet();

        if (! report) {
            return;
        }

        function showLatestDays() {
            report.querySelectorAll('.chart-scroll').forEach(function (chart) {
                // Hidden tab panels have no measurable width. Wait until the
                // panel is shown, then let the reader own its scroll position.
                if (initialized.has(chart) || chart.clientWidth === 0) {
                    return;
                }

                var overflow = chart.scrollWidth - chart.clientWidth;

                if (overflow <= 0) {
                    return;
                }

                // Keep the days oldest-first; begin at the recent end so
                // quiet early days cannot make a populated range look empty.
                chart.scrollLeft = overflow;
                initialized.add(chart);
            });
        }

        window.addEventListener('wayfindr:tab-shown', function (event) {
            if (event.detail && event.detail.tabs === report.id) {
                showLatestDays();
            }
        });

        window.addEventListener('resize', showLatestDays);
        showLatestDays();
    })();
</script>
