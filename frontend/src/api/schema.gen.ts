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
    "/auth/me": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Current user
         * @description Returns the signed-in user. It never sets a cookie on success (the sliding expiry is renewed on the server and
         *     the cookie's `Max-Age` is the absolute lifetime). Without a valid session (no cookie, a malformed or repeated one, an unknown or
         *     expired session, or a session whose user no longer exists) the answer is `401` with `WWW-Authenticate: Session`;
         *     when the request carried a session cookie, the `401` also clears it. A session of a user that no longer exists
         *     is ended as well.
         */
        get: operations["getCurrentUser"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/auth/logout": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Sign out
         * @description Ends the session the request carries and clears the cookie. The answer is always `204` with the clearing cookie,
         *     also when there is no cookie, or it is malformed, repeated, unknown or already ended, so it is safe to repeat and
         *     says nothing about whether a session existed. It needs no session, but it is a state-changing request, so the
         *     cross-site request check applies and a forged sign-out is refused with `403`. Only the session that the request
         *     carries is ended; this is not "sign out everywhere". If the storage fails (`503`) the session may still be
         *     active: try again.
         */
        post: operations["logoutUser"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/categories": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * List the categories
         * @description Returns every category of the signed-in user, sorted by name without regard to case (then by id). There is no
         *     pagination: a user has at most 100 categories. A new user has none, and the list is empty.
         */
        get: operations["listCategories"];
        put?: never;
        /**
         * Create a category
         * @description Creates a category with the given name. A name is unique for the user without regard to case (`Food` and `food`
         *     are the same name) and to invisible characters; a name that is already in use gets `409`. A user has at most 100
         *     categories: a request that would exceed that gets `409` as well, and the `detail` says which of the two it is.
         *     The `201` carries the address of the new category in `Location`.
         */
        post: operations["createCategory"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/categories/{id}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post?: never;
        /**
         * Delete a category
         * @description Deletes a category. It is unconditional (no version is needed). The expenses that used it are not changed: they
         *     show as uncategorized. A second delete of the same id gets `404`.
         */
        delete: operations["deleteCategory"];
        options?: never;
        head?: never;
        /**
         * Rename a category
         * @description Changes the name of a category. The body carries the `version` the client last read; if the category changed
         *     since, the answer is `409` (reload and try again). A name that another category already uses also gets `409`.
         *     An id that is not a category of the signed-in user (malformed, unknown, deleted or someone else's) gets one and
         *     the same `404`.
         */
        patch: operations["renameCategory"];
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
        /** @description One category of the signed-in user. */
        Category: {
            /**
             * Format: uuid
             * @description Opaque identifier.
             */
            id: string;
            /** @description The name as entered (trimmed), 1 to 50 characters. */
            name: string;
            /** @description 1 when created, one more after every change. A rename must send the version it last read. */
            version: number;
            /**
             * Format: date-time
             * @description ISO 8601 UTC, whole seconds.
             */
            createdAt: string;
            /**
             * Format: date-time
             * @description ISO 8601 UTC, whole seconds.
             */
            updatedAt: string;
        };
        /** @description The categories of the signed-in user (at most 100), sorted by name without regard to case. */
        CategoryList: {
            items: components["schemas"]["Category"][];
        };
        /** @description The body of a create. Exactly one member; anything else is a validation error. */
        NewCategory: {
            /**
             * @description 1 to 50 characters after trimming, counted by the server as characters (not bytes), with no control
             *     characters, text-direction controls or invisible-only text. Unique per user without regard to case. The
             *     schema's `maxLength` is only the outer bound the server reads before trimming (the server refuses more than
             *     50 x 4 + 1024 bytes, so no accepted value has more characters than that); it is not the 50-character rule,
             *     because surrounding whitespace is trimmed first and a padded name of 50 characters is accepted.
             */
            name: string;
        };
        /** @description The body of a rename. Exactly these two members; anything else is a validation error. */
        CategoryRename: {
            /** @description The new name, under the same rules as `NewCategory.name` (50 characters after trimming; see there for `maxLength`). */
            name: string;
            /** @description The `version` of the category as the client last read it. A different stored version gives `409`. */
            version: number;
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
        /** @description Returned for every method the path does not support (see `Allow`), OPTIONS included. */
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
         *     or is not a JSON object, or when the query string cannot be parsed. The problem never repeats the input.
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
        /**
         * @description Returned when the query string cannot be parsed or, on an operation that takes query parameters, when one of them
         *     is invalid or unknown. The `detail` names the parameter and never repeats its value.
         */
        InvalidQuery: {
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
         * @description Returned when the id is malformed, unknown, deleted, or belongs to another user. One body for every cause
         *     ("The resource does not exist."), so it never says which. It is never `403`.
         */
        ResourceNotFound: {
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
         * @description Returned when the change conflicts with the current state. The `detail` says which: the name is already in use,
         *     the category changed since the client read it (stale `version`: reload and try again), or the user already has
         *     the maximum number of categories (100). It never repeats a name, an id or a version.
         */
        EditConflict: {
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
         * @description Returned when the request has no valid session. One answer for every cause (no cookie, malformed, repeated,
         *     unknown, expired or ended), so it never says why. When the request carried a session cookie, the response also
         *     clears it with `Set-Cookie`.
         */
        SessionRequired: {
            headers: {
                "WWW-Authenticate": components["headers"]["WWWAuthenticate"];
                /**
                 * @description Present only when the request carried a session cookie: `__Host-session=; Max-Age=0; Path=/; Secure;
                 *     HttpOnly; SameSite=Lax`.
                 */
                "Set-Cookie"?: string;
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
        /** @description The identifier of the resource (a lower-case UUID). Any other value gets the same `404` as an unknown id. */
        ResourceId: string;
    };
    requestBodies: never;
    headers: {
        /** @description The address of the created resource, for example `/api/v1/categories/<id>`. */
        Location: string;
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
        /** @description Clears the session cookie: `__Host-session=; Max-Age=0; Path=/; Secure; HttpOnly; SameSite=Lax`. */
        ClearSessionCookie: string;
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
    getCurrentUser: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The session is valid. */
            200: {
                headers: {
                    "Cache-Control": components["headers"]["CacheControlNoStore"];
                    "X-Content-Type-Options": components["headers"]["ContentTypeOptionsNoSniff"];
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["User"];
                };
            };
            400: components["responses"]["InvalidQuery"];
            401: components["responses"]["SessionRequired"];
            403: components["responses"]["Forbidden"];
            405: components["responses"]["MethodNotAllowed"];
            500: components["responses"]["InternalServerError"];
            503: components["responses"]["ServiceUnavailable"];
        };
    };
    logoutUser: {
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
        requestBody?: never;
        responses: {
            /** @description The session is ended, or there was none. */
            204: {
                headers: {
                    "Set-Cookie": components["headers"]["ClearSessionCookie"];
                    "Cache-Control": components["headers"]["CacheControlNoStore"];
                    "X-Content-Type-Options": components["headers"]["ContentTypeOptionsNoSniff"];
                    [name: string]: unknown;
                };
                content?: never;
            };
            400: components["responses"]["InvalidQuery"];
            403: components["responses"]["Forbidden"];
            405: components["responses"]["MethodNotAllowed"];
            500: components["responses"]["InternalServerError"];
            503: components["responses"]["ServiceUnavailable"];
        };
    };
    listCategories: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The categories of the signed-in user. */
            200: {
                headers: {
                    "Cache-Control": components["headers"]["CacheControlNoStore"];
                    "X-Content-Type-Options": components["headers"]["ContentTypeOptionsNoSniff"];
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["CategoryList"];
                };
            };
            400: components["responses"]["InvalidQuery"];
            401: components["responses"]["SessionRequired"];
            403: components["responses"]["Forbidden"];
            405: components["responses"]["MethodNotAllowed"];
            500: components["responses"]["InternalServerError"];
            503: components["responses"]["ServiceUnavailable"];
        };
    };
    createCategory: {
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
                "application/json": components["schemas"]["NewCategory"];
            };
        };
        responses: {
            /** @description The category was created. */
            201: {
                headers: {
                    Location: components["headers"]["Location"];
                    "Cache-Control": components["headers"]["CacheControlNoStore"];
                    "X-Content-Type-Options": components["headers"]["ContentTypeOptionsNoSniff"];
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Category"];
                };
            };
            400: components["responses"]["BadRequest"];
            401: components["responses"]["SessionRequired"];
            403: components["responses"]["Forbidden"];
            405: components["responses"]["MethodNotAllowed"];
            409: components["responses"]["EditConflict"];
            413: components["responses"]["ContentTooLarge"];
            415: components["responses"]["UnsupportedMediaType"];
            422: components["responses"]["ValidationFailed"];
            500: components["responses"]["InternalServerError"];
            503: components["responses"]["ServiceUnavailable"];
        };
    };
    deleteCategory: {
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
            path: {
                /** @description The identifier of the resource (a lower-case UUID). Any other value gets the same `404` as an unknown id. */
                id: components["parameters"]["ResourceId"];
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The category is deleted. */
            204: {
                headers: {
                    "Cache-Control": components["headers"]["CacheControlNoStore"];
                    "X-Content-Type-Options": components["headers"]["ContentTypeOptionsNoSniff"];
                    [name: string]: unknown;
                };
                content?: never;
            };
            400: components["responses"]["InvalidQuery"];
            401: components["responses"]["SessionRequired"];
            403: components["responses"]["Forbidden"];
            404: components["responses"]["ResourceNotFound"];
            405: components["responses"]["MethodNotAllowed"];
            500: components["responses"]["InternalServerError"];
            503: components["responses"]["ServiceUnavailable"];
        };
    };
    renameCategory: {
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
            path: {
                /** @description The identifier of the resource (a lower-case UUID). Any other value gets the same `404` as an unknown id. */
                id: components["parameters"]["ResourceId"];
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["CategoryRename"];
            };
        };
        responses: {
            /** @description The category was renamed. `version` is one more than before. */
            200: {
                headers: {
                    "Cache-Control": components["headers"]["CacheControlNoStore"];
                    "X-Content-Type-Options": components["headers"]["ContentTypeOptionsNoSniff"];
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Category"];
                };
            };
            400: components["responses"]["BadRequest"];
            401: components["responses"]["SessionRequired"];
            403: components["responses"]["Forbidden"];
            404: components["responses"]["ResourceNotFound"];
            405: components["responses"]["MethodNotAllowed"];
            409: components["responses"]["EditConflict"];
            413: components["responses"]["ContentTooLarge"];
            415: components["responses"]["UnsupportedMediaType"];
            422: components["responses"]["ValidationFailed"];
            500: components["responses"]["InternalServerError"];
            503: components["responses"]["ServiceUnavailable"];
        };
    };
}
