<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Support\WidgetRealtimeConfig;
use Illuminate\Http\Response;

class WidgetScriptController extends Controller
{
    /**
     * The realtime library is served from here rather than from a public CDN.
     *
     * A self-hosted install should serve every byte it runs. Fetching
     * pusher-js from js.pusher.com meant an air-gapped or firewalled
     * deployment silently lost realtime, a host page with a strict
     * `script-src 'self' https://<wayfindr-host>` policy could not load it,
     * and every visitor's browser contacted a third party to use support chat
     * (issue #714). The bytes are unchanged -- those pages already fetched
     * this exact file -- but they now come from one origin, in one request.
     */
    public function __invoke(): Response
    {
        $scriptPath = base_path('../../packages/widget-js/src/wayfindr-widget.js');

        abort_unless(is_file($scriptPath), 404);

        return response($this->script($scriptPath), 200, [
            'Content-Type' => 'application/javascript; charset=UTF-8',
            'Cache-Control' => 'public, max-age=60',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function script(string $scriptPath): string
    {
        $widget = file_get_contents($scriptPath);
        $vendorPath = base_path('../../packages/widget-js/vendor/pusher.min.js');

        // Only when realtime is actually configured, which is the same
        // condition that decides whether a page is given Reverb settings at
        // all. An install without realtime should not carry the library.
        if (! WidgetRealtimeConfig::isConfigured()) {
            return $widget;
        }

        // Configured for realtime but the library is missing: a packaging
        // fault, not a runtime state. Serving the widget anyway leaves an
        // install whose settings promise realtime quietly without it, which is
        // the failure this whole change exists to end -- so it is said out
        // loud rather than absorbed.
        if (! is_file($vendorPath)) {
            report(new \RuntimeException(
                "Realtime is configured but the bundled client is missing at {$vendorPath}. "
                .'The widget will load without realtime. This is a packaging fault: '
                .'packages/widget-js/vendor must ship alongside packages/widget-js/src.'
            ));

            return $widget;
        }

        return $this->bundledRealtime(file_get_contents($vendorPath))."\n".$widget;
    }

    /**
     * Wrap the vendored library so it cannot disturb the host page.
     *
     * Two hazards, both belonging to the page rather than to us. The library
     * is a UMD bundle: on a page using RequireJS it would call an anonymous
     * `define()` and never set a global, so the module loaders are hidden from
     * it and the browser branch is taken deterministically. And it publishes
     * itself as `window.Pusher`, which would clobber a host page's own Pusher
     * -- so the reference is moved somewhere namespaced and whatever was there
     * before is put back exactly as found, including having been absent.
     */
    private function bundledRealtime(string $vendor): string
    {
        return <<<JS
        (function () {
          var hadPusher = Object.prototype.hasOwnProperty.call(window, 'Pusher');
          var previousPusher = window.Pusher;
          var previousDefine = window.define;
          var previousExports = window.exports;
          var previousModule = window.module;

          try { window.define = undefined; window.exports = undefined; window.module = undefined; } catch (e) {}

          try {
            {$vendor}
          } catch (e) {
            if (window.console && window.console.error) {
              window.console.error('[wayfindr] bundled realtime library failed to load:', e);
            }
          }

          window.__wayfindrPusher = window.Pusher;

          try { window.define = previousDefine; window.exports = previousExports; window.module = previousModule; } catch (e) {}

          if (hadPusher) {
            window.Pusher = previousPusher;
          } else {
            try { delete window.Pusher; } catch (e) { window.Pusher = undefined; }
          }
        })();
        JS;
    }
}
