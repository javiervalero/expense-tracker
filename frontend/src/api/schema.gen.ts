/**
 * GENERATED - DO NOT EDIT.
 * Source: contract/openapi.yaml. Regenerate with `make contract-gen`.
 * CI regenerates this file and fails if it differs from the committed one.
 * Do not rely on the generated types of response headers: they are documentation, not a contract the code enforces.
 */

export interface paths {
    "/health": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Health check
         * @description Returns 200 with `{"status":"ok"}` when the API is up. Needs no authentication.
         */
        get: operations["getHealth"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        /**
         * Health check without a body
         * @description Same as `GET /health`, but the response has no body. Error responses to HEAD have no body either (only their
         *     headers are documented).
         */
        head: operations["headHealth"];
        patch?: never;
        trace?: never;
    };
}
export type webhooks = Record<string, never>;
export interface components {
    schemas: {
        /** @description Body of a successful health check. */
        HealthResponse: {
            /** @constant */
            status: "ok";
        };
        /**
         * @description RFC 9457 problem details. This API always sends `title` and `status`; clients must tolerate their absence
         *     in problem documents from third parties (RFC 9457 makes every member optional). Members beyond the five standard ones are allowed (extension members), so this
         *     schema does not use `additionalProperties: false`.
         */
        ProblemDetails: {
            /**
             * Format: uri-reference
             * @description Identifies the problem type. `about:blank` means the title is the HTTP status phrase.
             * @default about:blank
             */
            type?: string;
            /** @description Short, human-readable summary of the problem type. */
            title: string;
            /** @description The HTTP status code. */
            status: number;
            /** @description Human-readable explanation specific to this occurrence. */
            detail?: string;
            /**
             * Format: uri-reference
             * @description Identifies this occurrence of the problem.
             */
            instance?: string;
        } & {
            [key: string]: unknown;
        };
    };
    responses: {
        /**
         * @description Returned when the `X-Origin-Verify` header is missing, empty or wrong. The body is the same generic problem in
         *     every case, so it never says which, and it is checked before the path, so even an unknown path or another
         *     method gets this instead of 404 or 405.
         */
        Forbidden: {
            headers: {
                "Cache-Control": components["headers"]["CacheControlNoStore"];
                "X-Content-Type-Options": components["headers"]["ContentTypeOptionsNoSniff"];
                [name: string]: unknown;
            };
            content: {
                "application/problem+json": components["schemas"]["ProblemDetails"];
            };
        };
        /** @description Returned for any path not listed in this spec. */
        NotFound: {
            headers: {
                "Cache-Control": components["headers"]["CacheControlNoStore"];
                "X-Content-Type-Options": components["headers"]["ContentTypeOptionsNoSniff"];
                [name: string]: unknown;
            };
            content: {
                "application/problem+json": components["schemas"]["ProblemDetails"];
            };
        };
        /** @description Returned for every method other than GET and HEAD on a listed path, OPTIONS included. */
        MethodNotAllowed: {
            headers: {
                Allow: components["headers"]["Allow"];
                "Cache-Control": components["headers"]["CacheControlNoStore"];
                "X-Content-Type-Options": components["headers"]["ContentTypeOptionsNoSniff"];
                [name: string]: unknown;
            };
            content: {
                "application/problem+json": components["schemas"]["ProblemDetails"];
            };
        };
        /** @description Returned when the API fails unexpectedly. The body is a generic problem and never says why; the cause is only in the server log. */
        InternalServerError: {
            headers: {
                "Cache-Control": components["headers"]["CacheControlNoStore"];
                "X-Content-Type-Options": components["headers"]["ContentTypeOptionsNoSniff"];
                [name: string]: unknown;
            };
            content: {
                "application/problem+json": components["schemas"]["ProblemDetails"];
            };
        };
    };
    parameters: never;
    requestBodies: never;
    headers: {
        /** @description Always `no-store`; nothing in the API may be cached. */
        CacheControlNoStore: string;
        /** @description Always `nosniff`; the declared content type is the content type. */
        ContentTypeOptionsNoSniff: string;
        /** @description The methods the path supports, for example `GET, HEAD`. */
        Allow: string;
    };
    pathItems: never;
}
export type $defs = Record<string, never>;
export interface operations {
    getHealth: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The API is up. */
            200: {
                headers: {
                    "Cache-Control": components["headers"]["CacheControlNoStore"];
                    "X-Content-Type-Options": components["headers"]["ContentTypeOptionsNoSniff"];
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["HealthResponse"];
                };
            };
            403: components["responses"]["Forbidden"];
            404: components["responses"]["NotFound"];
            405: components["responses"]["MethodNotAllowed"];
            500: components["responses"]["InternalServerError"];
        };
    };
    headHealth: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The API is up. The response has no body. */
            200: {
                headers: {
                    /** @description The type the body would have (the response has no body). */
                    "Content-Type": string;
                    "Cache-Control": components["headers"]["CacheControlNoStore"];
                    "X-Content-Type-Options": components["headers"]["ContentTypeOptionsNoSniff"];
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Returned when the origin check fails (see `GET /health`). No body (HEAD). */
            403: {
                headers: {
                    "Cache-Control": components["headers"]["CacheControlNoStore"];
                    "X-Content-Type-Options": components["headers"]["ContentTypeOptionsNoSniff"];
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Returned for any path not listed in this spec. No body (HEAD). */
            404: {
                headers: {
                    "Cache-Control": components["headers"]["CacheControlNoStore"];
                    "X-Content-Type-Options": components["headers"]["ContentTypeOptionsNoSniff"];
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Returned for every method other than GET and HEAD, OPTIONS included. No body (HEAD). */
            405: {
                headers: {
                    Allow: components["headers"]["Allow"];
                    "Cache-Control": components["headers"]["CacheControlNoStore"];
                    "X-Content-Type-Options": components["headers"]["ContentTypeOptionsNoSniff"];
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Returned when the API fails unexpectedly. The body never says why. No body (HEAD). */
            500: {
                headers: {
                    "Cache-Control": components["headers"]["CacheControlNoStore"];
                    "X-Content-Type-Options": components["headers"]["ContentTypeOptionsNoSniff"];
                    [name: string]: unknown;
                };
                content?: never;
            };
        };
    };
}
