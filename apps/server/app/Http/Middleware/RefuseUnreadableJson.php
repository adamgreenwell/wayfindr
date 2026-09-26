<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers a JSON body that cannot be decoded with a 400 that says so.
 *
 * Laravel does not refuse one. Request::json() casts the failed decode to an
 * EMPTY input bag, so the request reaches its controller carrying nothing. On
 * the widget's routes that read as a missing site key, and every endpoint
 * answered 404 "Site not found." -- untrue about the site, and repeated by
 * every retry, because the retry resends the bytes that caused it. A lone
 * UTF-16 surrogate is enough: JavaScript writes one as a valid "\udXXX"
 * escape, and PHP's decoder refuses the whole document for it.
 *
 * The widget's routes only. The public API is a frozen contract (ADR 0018),
 * and the inbound mail and integration webhooks verify a signature over the
 * raw body, so each keeps answering exactly as it did.
 */
class RefuseUnreadableJson
{
    private const EXEMPT = ['api/v1/*', 'api/mail/*', 'api/integrations/*'];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isJson() && ! $request->is(...self::EXEMPT) && $this->unreadable($request)) {
            // The visitor's language is inside the body that could not be
            // read, so the sentence is the install's; the key is what lets the
            // widget say it in the language its panel is already speaking.
            return new JsonResponse([
                'message' => __('errors.unreadable_request'),
                'error_key' => 'error.unreadableRequest',
            ], Response::HTTP_BAD_REQUEST);
        }

        return $next($request);
    }

    private function unreadable(Request $request): bool
    {
        $content = $request->getContent();

        // No body is not an unreadable one: a JSON-typed GET, or a POST with
        // nothing to say, carries on to whatever the endpoint makes of it.
        if (trim($content) === '') {
            return false;
        }

        return ! json_validate($content);
    }
}
