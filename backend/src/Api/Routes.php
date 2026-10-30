<?php

declare(strict_types=1);

namespace App\Api;

use App\Auth\Http\LoginHandler;
use App\Auth\Http\LogoutHandler;
use App\Auth\Http\MeHandler;
use App\Auth\Http\RegisterHandler;
use App\Auth\Password\PasswordHasher;
use App\Auth\Password\PasswordPolicy;
use App\Auth\Session\CarriedSession;
use App\Auth\Session\RequireSession;
use App\Auth\Session\SessionAuthenticator;
use App\Auth\Session\SessionRepository;
use App\Auth\Throttle\LoginThrottle;
use App\Auth\Throttle\RegisterThrottle;
use App\Auth\Throttle\ThrottleCounter;
use App\Auth\User\UserRepository;
use App\Categories\CategoryRepository;
use App\Categories\Http\CreateCategoryHandler;
use App\Categories\Http\DeleteCategoryHandler;
use App\Categories\Http\ListCategoriesHandler;
use App\Categories\Http\RenameCategoryHandler;
use App\Expenses\ExpenseRepository;
use App\Expenses\Http\CreateExpenseHandler;
use App\Expenses\Http\DeleteExpenseHandler;
use App\Expenses\Http\GetExpenseHandler;
use App\Expenses\Http\ListExpensesHandler;
use App\Expenses\Http\UpdateExpenseHandler;
use App\Expenses\Listing\ExpenseLister;
use App\Health\HealthHandler;
use App\Http\Handler;
use App\Http\Routing\Route;
use App\Infrastructure\S3\S3ClientFactory;
use App\Infrastructure\S3\S3ObjectStore;
use App\Infrastructure\S3\S3OriginalObjects;
use App\Infrastructure\S3\S3ThumbnailObjects;
use App\Infrastructure\S3\UploadPolicySigner;
use App\Receipts\Http\CreateReceiptUploadHandler;
use App\Receipts\ReceiptObjectCleaner;
use App\Receipts\ReceiptRepository;

/**
 * The route table of the API (templates are relative to `/api/v1`). Every route holds a factory, not a handler: the
 * dispatcher builds the handler (and the stores behind it) of the one route it matched, so a request to `/health`
 * builds nothing else.
 */
final class Routes
{
    /**
     * @param ApiServices $services what the handlers share; the health route needs none of it, the auth routes use it
     *
     * @return list<Route>
     */
    public static function all(ApiServices $services): array
    {
        // Route factories must not branch, and RequireSession must stay the outermost wrapper of a protected handler
        // (ProtectedRoutesMatchContractTest relies on both).
        return [
            new Route('GET', '/health', static fn(): HealthHandler => new HealthHandler()),
            new Route('HEAD', '/health', static fn(): HealthHandler => new HealthHandler()),
            new Route('POST', '/auth/register', static fn(): RegisterHandler => self::register($services)),
            new Route('POST', '/auth/login', static fn(): LoginHandler => self::login($services)),
            new Route('GET', '/auth/me', static fn(): RequireSession => self::me($services)),
            new Route('POST', '/auth/logout', static fn(): LogoutHandler => self::logout($services)),
            new Route('GET', '/categories', static fn(): RequireSession => self::listCategories($services)),
            new Route('POST', '/categories', static fn(): RequireSession => self::createCategory($services)),
            new Route('PATCH', '/categories/{id}', static fn(): RequireSession => self::renameCategory($services)),
            new Route('DELETE', '/categories/{id}', static fn(): RequireSession => self::deleteCategory($services)),
            new Route('GET', '/expenses', static fn(): RequireSession => self::listExpenses($services)),
            new Route('POST', '/expenses', static fn(): RequireSession => self::createExpense($services)),
            new Route('GET', '/expenses/{id}', static fn(): RequireSession => self::getExpense($services)),
            new Route('PATCH', '/expenses/{id}', static fn(): RequireSession => self::updateExpense($services)),
            new Route('DELETE', '/expenses/{id}', static fn(): RequireSession => self::deleteExpense($services)),
            new Route('POST', '/expenses/{id}/receipt/upload', static fn(): RequireSession => self::createReceiptUpload($services)),
        ];
    }

    private static function sessions(ApiServices $services): SessionRepository
    {
        return new SessionRepository($services->items, $services->clock);
    }

    private static function register(ApiServices $services): RegisterHandler
    {
        $sessions = self::sessions($services);

        return new RegisterHandler(
            new RegisterThrottle(new ThrottleCounter($services->items, $services->clock)),
            new PasswordPolicy(),
            PasswordHasher::production(),
            new UserRepository($services->items),
            $sessions,
            new CarriedSession($sessions, $services->logger),
            $services->ids,
            $services->clock,
            $services->logger,
        );
    }

    private static function login(ApiServices $services): LoginHandler
    {
        $sessions = self::sessions($services);

        return new LoginHandler(
            new LoginThrottle(new ThrottleCounter($services->items, $services->clock), $services->logger),
            new PasswordPolicy(),
            PasswordHasher::production(),
            new UserRepository($services->items),
            $sessions,
            new CarriedSession($sessions, $services->logger),
            $services->logger,
        );
    }

    private static function logout(ApiServices $services): LogoutHandler
    {
        return new LogoutHandler(new CarriedSession(self::sessions($services), $services->logger));
    }

    private static function me(ApiServices $services): RequireSession
    {
        return self::guarded(new MeHandler(new UserRepository($services->items), self::sessions($services), $services->logger), $services);
    }

    private static function listCategories(ApiServices $services): RequireSession
    {
        return self::guarded(new ListCategoriesHandler(self::categories($services)), $services);
    }

    private static function createCategory(ApiServices $services): RequireSession
    {
        return self::guarded(new CreateCategoryHandler(self::categories($services), $services->ids, $services->clock), $services);
    }

    private static function renameCategory(ApiServices $services): RequireSession
    {
        return self::guarded(new RenameCategoryHandler(self::categories($services), $services->clock), $services);
    }

    private static function deleteCategory(ApiServices $services): RequireSession
    {
        return self::guarded(new DeleteCategoryHandler(self::categories($services)), $services);
    }

    private static function listExpenses(ApiServices $services): RequireSession
    {
        return self::guarded(new ListExpensesHandler(new ExpenseLister(self::expenses($services)), self::categories($services)), $services);
    }

    private static function createExpense(ApiServices $services): RequireSession
    {
        return self::guarded(new CreateExpenseHandler(self::expenses($services), self::categories($services), $services->ids, $services->clock), $services);
    }

    private static function getExpense(ApiServices $services): RequireSession
    {
        return self::guarded(new GetExpenseHandler(self::expenses($services), self::categories($services)), $services);
    }

    private static function updateExpense(ApiServices $services): RequireSession
    {
        return self::guarded(new UpdateExpenseHandler(self::expenses($services), self::categories($services), $services->clock), $services);
    }

    private static function deleteExpense(ApiServices $services): RequireSession
    {
        return self::guarded(new DeleteExpenseHandler(self::expenses($services)), $services);
    }

    /**
     * The only route that builds S3 clients, and only when it runs: the signing client (the PUBLIC endpoint, no network
     * call) for the policy, and the calling client (the internal endpoint) for the cleanup of a replaced receipt.
     */
    private static function createReceiptUpload(ApiServices $services): RequireSession
    {
        $settings = $services->s3;
        $store = new S3ObjectStore(S3ClientFactory::forCalls($settings));

        $cleaner = new ReceiptObjectCleaner(
            new S3OriginalObjects($store, $settings),
            new S3ThumbnailObjects($store, $settings),
            $services->logger,
            $settings,
        );
        $signer = new UploadPolicySigner(S3ClientFactory::forSigning($settings), $settings->receiptsBucket, $services->clock);

        return self::guarded(
            new CreateReceiptUploadHandler(new ReceiptRepository($services->items), $cleaner, $signer, $services->ids, $services->clock),
            $services,
        );
    }

    private static function expenses(ApiServices $services): ExpenseRepository
    {
        return new ExpenseRepository($services->items);
    }

    private static function categories(ApiServices $services): CategoryRepository
    {
        return new CategoryRepository($services->items);
    }

    /** The session guard around a protected handler: always the outermost wrapper (ProtectedRoutesMatchContractTest). */
    private static function guarded(Handler $handler, ApiServices $services): RequireSession
    {
        return new RequireSession($handler, new SessionAuthenticator(self::sessions($services), $services->clock, $services->logger));
    }
}
