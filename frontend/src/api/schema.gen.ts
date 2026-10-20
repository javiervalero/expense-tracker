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
    "/auth/register": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Register a new account
         * @description Creates an account and signs the new user in: the `201` carries the session cookie. The address is trimmed and
         *     lower-cased; the password must have 12 to 128 characters (spaces and Unicode are fine, control characters are
         *     not) and is compared byte for byte, with no Unicode normalisation.
         *
         *     An address that already has an account gets `409`. That is deliberate (a fake success would leave the person
         *     unable to sign in); registration is throttled per address to limit probing (`429`). If the request carried a
         *     session cookie, that session is ended and a new one is issued.
         *
         *     Throttling is per client address (5 per hour) and per IPv6 `/48` (20 per hour); every attempt counts, whatever
         *     its outcome. The body of a `429` never says which limit fired.
         */
        post: operations["registerUser"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/auth/login": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Sign in
         * @description Checks the address and password and, if they match, signs the user in: the `200` carries a new session cookie.
         *     If the request carried a session cookie, that session is ended and a new one is issued (a token chosen by the
         *     client is never adopted). The address is trimmed and lower-cased.
         *
         *     A wrong password and an address without an account get the same `401` (same status, headers and body), and
         *     take about the same time. Sign-in only enforces the maximum password length (128 characters; a longer one
         *     gives `422`), so a later change of the password rules never locks anyone out.
         *
         *     Throttling counts failures: per account (10 per 15 minutes), per client address (30 per 15 minutes) and per
         *     IPv6 `/48` (100 per 15 minutes). A successful sign-in resets the account's count and gives the address counts
         *     back. An attempt over a limit gets `429` without the password being checked, even if it is the right one; the
         *     body never says which limit fired.
         */
        post: operations["loginUser"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
}
export type webhooks = Record<string, never>;
export interface components {
    schemas: {
        /** @description The signed-in user. The password is never returned. */
        User: {
            /**
             * Format: uuid
             * @description Opaque identifier.
             */
            id: string;
            /** @description The normalised address (trimmed, lower-case). */
            email: string;
        };
        /** @description An address and a password. Exactly these two members; anything else is a validation error. */
        Credentials: {
            /**
             * @description Any case and surrounding spaces are accepted; it is stored trimmed and lower-case. The trimmed address may
             *     have at most 254 bytes (a longer one gives `422`), so the raw value has no `maxLength`.
             */
            email: string;
            /**
             * @description At most 128 characters. Registration also wants at least 12, no control characters and valid UTF-8; sign-in
             *     only enforces the maximum.
             */
            password: string;
        };
        /** @description A problem with the list of invalid fields in `errors`. */
        ValidationProblemDetails: components["schemas"]["ProblemDetails"] & {
            errors: {
                /**
                 * @description JSON Pointer (RFC 6901) to the invalid member of the request body.
                 * @example /password
                 */
                pointer: string;
                /** @description What is wrong, for the person who sent it. Never repeats the value. */
                detail: string;
            }[];
        };
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
         * @description Returned when the `X-Origin-Verify` header is missing, empty or wrong, and, for every method except `GET`,
         *     `HEAD` and `OPTIONS`, when the cross-site request check fails: `X-Requested-With` is not exactly
         *     `XMLHttpRequest`, or `Origin` is missing, `null` or not one of the app's origins. Origin-verify failures
         *     share one generic problem; CSRF failures share another, with the detail "The request failed the cross-site
         *     request check.". Neither says which header was wrong. Both are checked before the path, so even an unknown
         *     path or another method gets this instead of 404 or 405.
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
        /**
         * @description Returned when the request body is not valid JSON (or not valid UTF-8, or nested too deeply, or holds a number out of range),
         *     or is not a JSON object. The problem never repeats the input.
         */
        BadRequest: {
            headers: {
                "Cache-Control": components["headers"]["CacheControlNoStore"];
                "X-Content-Type-Options": components["headers"]["ContentTypeOptionsNoSniff"];
                [name: string]: unknown;
            };
            content: {
                "application/problem+json": components["schemas"]["ProblemDetails"];
            };
        };
        /** @description Returned when the request body is larger than 65536 bytes. */
        ContentTooLarge: {
            headers: {
                "Cache-Control": components["headers"]["CacheControlNoStore"];
                "X-Content-Type-Options": components["headers"]["ContentTypeOptionsNoSniff"];
                [name: string]: unknown;
            };
            content: {
                "application/problem+json": components["schemas"]["ProblemDetails"];
            };
        };
        /** @description Returned when the request body is not `application/json` (`charset=utf-8` is the only parameter allowed). */
        UnsupportedMediaType: {
            headers: {
                "Cache-Control": components["headers"]["CacheControlNoStore"];
                "X-Content-Type-Options": components["headers"]["ContentTypeOptionsNoSniff"];
                [name: string]: unknown;
            };
            content: {
                "application/problem+json": components["schemas"]["ProblemDetails"];
            };
        };
        /**
         * @description Returned when the body is JSON but a field is wrong. `errors` lists each one by JSON Pointer (`/email`, `/password`, or
         *     `/<name>` for an unexpected field) with a message. The messages never repeat the value.
         */
        ValidationFailed: {
            headers: {
                "Cache-Control": components["headers"]["CacheControlNoStore"];
                "X-Content-Type-Options": components["headers"]["ContentTypeOptionsNoSniff"];
                [name: string]: unknown;
            };
            content: {
                "application/problem+json": components["schemas"]["ValidationProblemDetails"];
            };
        };
        /**
         * @description Returned when the address already has an account. If an earlier attempt of yours ended with a temporary error
         *     (`503`), it may have gone through: try signing in.
         */
        Conflict: {
            headers: {
                "Cache-Control": components["headers"]["CacheControlNoStore"];
                "X-Content-Type-Options": components["headers"]["ContentTypeOptionsNoSniff"];
                [name: string]: unknown;
            };
            content: {
                "application/problem+json": components["schemas"]["ProblemDetails"];
            };
        };
        /**
         * @description Returned when sign-in fails. One answer for a wrong password and for an address without an account: the same
         *     status, headers and body. The problem never says which part was wrong.
         */
        Unauthorized: {
            headers: {
                "WWW-Authenticate": components["headers"]["WWWAuthenticate"];
                "Cache-Control": components["headers"]["CacheControlNoStore"];
                "X-Content-Type-Options": components["headers"]["ContentTypeOptionsNoSniff"];
                [name: string]: unknown;
            };
            content: {
                "application/problem+json": components["schemas"]["ProblemDetails"];
            };
        };
        /**
         * @description Returned when the client address (or its network, or the account) has used up its attempts for the current window. `Retry-After` is
         *     the time to the end of the window. One generic body, whichever limit fired.
         */
        TooManyRequests: {
            headers: {
                "Retry-After": components["headers"]["RetryAfter"];
                "Cache-Control": components["headers"]["CacheControlNoStore"];
                "X-Content-Type-Options": components["headers"]["ContentTypeOptionsNoSniff"];
                [name: string]: unknown;
            };
            content: {
                "application/problem+json": components["schemas"]["ProblemDetails"];
            };
        };
        /**
         * @description Returned when the storage is overloaded. The request had no lasting effect or may have succeeded; try again after
         *     `Retry-After`.
         */
        ServiceUnavailable: {
            headers: {
                "Retry-After": components["headers"]["RetryAfter"];
                "Cache-Control": components["headers"]["CacheControlNoStore"];
                "X-Content-Type-Options": components["headers"]["ContentTypeOptionsNoSniff"];
                [name: string]: unknown;
            };
            content: {
                "application/problem+json": components["schemas"]["ProblemDetails"];
            };
        };
    };
    parameters: {
        /**
         * @description Must be exactly `XMLHttpRequest`. A cross-site form post cannot set it, which is what the cross-site request
         *     check relies on. A request without it gets `403`. The browser's `Origin` header is checked too; it is not
         *     declared here because browsers set it and JavaScript cannot.
         */
        XRequestedWith: "XMLHttpRequest";
    };
    requestBodies: never;
    headers: {
        /** @description Always `no-store`; nothing in the API may be cached. */
        CacheControlNoStore: string;
        /** @description Always `nosniff`; the declared content type is the content type. */
        ContentTypeOptionsNoSniff: string;
        /** @description The methods the path supports, for example `GET, HEAD`. */
        Allow: string;
        /**
         * @description Sets the session cookie: `__Host-session=<token>; Max-Age=<seconds>; Path=/; Secure; HttpOnly; SameSite=Lax`.
         *     `Max-Age` is the absolute session lifetime. The attributes are the same on every environment.
         */
        SetSessionCookie: string;
        /** @description The challenge required on every `401` (RFC 9110): the scheme name `Session`, meaning the session cookie. */
        WWWAuthenticate: "Session";
        /** @description Whole seconds to wait before trying again. */
        RetryAfter: number;
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
    registerUser: {
        parameters: {
            query?: never;
            header: {
                /**
                 * @description Must be exactly `XMLHttpRequest`. A cross-site form post cannot set it, which is what the cross-site request
                 *     check relies on. A request without it gets `403`. The browser's `Origin` header is checked too; it is not
                 *     declared here because browsers set it and JavaScript cannot.
                 */
                "X-Requested-With": components["parameters"]["XRequestedWith"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["Credentials"];
            };
        };
        responses: {
            /** @description The account exists and the response signs the user in. */
            201: {
                headers: {
                    "Set-Cookie": components["headers"]["SetSessionCookie"];
                    "Cache-Control": components["headers"]["CacheControlNoStore"];
                    "X-Content-Type-Options": components["headers"]["ContentTypeOptionsNoSniff"];
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["User"];
                };
            };
            400: components["responses"]["BadRequest"];
            403: components["responses"]["Forbidden"];
            405: components["responses"]["MethodNotAllowed"];
            409: components["responses"]["Conflict"];
            413: components["responses"]["ContentTooLarge"];
            415: components["responses"]["UnsupportedMediaType"];
            422: components["responses"]["ValidationFailed"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalServerError"];
            503: components["responses"]["ServiceUnavailable"];
        };
    };
    loginUser: {
        parameters: {
            query?: never;
            header: {
                /**
                 * @description Must be exactly `XMLHttpRequest`. A cross-site form post cannot set it, which is what the cross-site request
                 *     check relies on. A request without it gets `403`. The browser's `Origin` header is checked too; it is not
                 *     declared here because browsers set it and JavaScript cannot.
                 */
                "X-Requested-With": components["parameters"]["XRequestedWith"];
            };
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["Credentials"];
            };
        };
        responses: {
            /** @description The credentials are right and the response signs the user in. */
            200: {
                headers: {
                    "Set-Cookie": components["headers"]["SetSessionCookie"];
                    "Cache-Control": components["headers"]["CacheControlNoStore"];
                    "X-Content-Type-Options": components["headers"]["ContentTypeOptionsNoSniff"];
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["User"];
                };
            };
            400: components["responses"]["BadRequest"];
            401: components["responses"]["Unauthorized"];
            403: components["responses"]["Forbidden"];
            405: components["responses"]["MethodNotAllowed"];
            413: components["responses"]["ContentTooLarge"];
            415: components["responses"]["UnsupportedMediaType"];
            422: components["responses"]["ValidationFailed"];
            429: components["responses"]["TooManyRequests"];
            500: components["responses"]["InternalServerError"];
            503: components["responses"]["ServiceUnavailable"];
        };
    };
}
