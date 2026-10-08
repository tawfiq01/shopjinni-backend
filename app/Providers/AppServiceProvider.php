<?php

namespace App\Providers;

use App\Domain\Customers\Models\Customer;
use App\Domain\Expenses\Models\Expense;
use App\Domain\Purchasing\Models\Distributor;
use App\Domain\Purchasing\Models\PurchaseInvoice;
use App\Domain\Purchasing\Models\PurchaseReturn;
use App\Domain\Sales\Models\SalesInvoice;
use App\Domain\Sales\Models\PhoneExchange;
use App\Domain\Sales\Models\SalesReturn;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Domain models live under App\Domain\*\Models, not App\Models, so
        // Laravel's default "App\Models\Foo" -> "Database\Factories\FooFactory"
        // guess never matches. Resolve by class basename instead.
        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Database\\Factories\\'.class_basename($modelName).'Factory'
        );

        // Short, stable aliases for the party_type/reference_type morph
        // columns on journal lines/entries, so renaming or moving a model
        // later doesn't orphan old rows. Not enforced: new domain models are
        // added to this map incrementally as later slices introduce them.
        Relation::morphMap([
            'distributor' => Distributor::class,
            'purchase_invoice' => PurchaseInvoice::class,
            'customer' => Customer::class,
            'sales_invoice' => SalesInvoice::class,
            'expense' => Expense::class,
            'sales_return' => SalesReturn::class,
            'purchase_return' => PurchaseReturn::class,
            'phone_exchange' => PhoneExchange::class,
        ]);

        // Avatar/logo images set their own Access-Control-Allow-Origin: *
        // (they're meant to be embeddable from anywhere, e.g. in an
        // <img> tag regardless of origin) — the global CORS middleware
        // would otherwise overwrite that with the restricted frontend
        // origin from config/cors.php, since these routes live under
        // api/* too.
        HandleCors::skipWhen(fn ($request) => $request->is('api/avatars/*') || $request->is('api/logo/*'));
    }
}
