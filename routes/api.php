<?php

use App\Domain\Accounting\Http\Controllers\ChartOfAccountController;
use App\Domain\Accounting\Http\Controllers\JournalEntryController;
use App\Domain\Accounting\Http\Controllers\LedgerController;
use App\Domain\Accounting\Http\Controllers\PaymentMethodController;
use App\Domain\Auth\Http\Controllers\AuthController;
use App\Domain\Auth\Http\Controllers\RoleController;
use App\Domain\Auth\Http\Controllers\UserController;
use App\Domain\Backup\Http\Controllers\BackupSettingController;
use App\Domain\Branches\Http\Controllers\BranchController;
use App\Domain\Catalog\Http\Controllers\BrandController;
use App\Domain\Catalog\Http\Controllers\ColorController;
use App\Domain\Catalog\Http\Controllers\ProductModelController;
use App\Domain\Catalog\Http\Controllers\ProductTypeController;
use App\Domain\Catalog\Http\Controllers\ProductVariantColorController;
use App\Domain\Catalog\Http\Controllers\ProductVariantController;
use App\Domain\Customers\Http\Controllers\CustomerController;
use App\Domain\Expenses\Http\Controllers\ExpenseCategoryController;
use App\Domain\Expenses\Http\Controllers\ExpenseController;
use App\Domain\Inventory\Http\Controllers\StockTransferController;
use App\Domain\Purchasing\Http\Controllers\DistributorController;
use App\Domain\Purchasing\Http\Controllers\PurchaseInvoiceController;
use App\Domain\Purchasing\Http\Controllers\PurchaseReturnController;
use App\Domain\Reports\Http\Controllers\CashPositionController;
use App\Domain\Reports\Http\Controllers\DashboardController;
use App\Domain\Reports\Http\Controllers\DueReportController;
use App\Domain\Reports\Http\Controllers\ImeiHistoryController;
use App\Domain\Reports\Http\Controllers\PurchaseReportController;
use App\Domain\Reports\Http\Controllers\SalesReportController;
use App\Domain\Reports\Http\Controllers\StockReportController;
use App\Domain\Sales\Http\Controllers\PhoneExchangeController;
use App\Domain\Sales\Http\Controllers\PosSearchController;
use App\Domain\Sales\Http\Controllers\SalesInvoiceController;
use App\Domain\Sales\Http\Controllers\SalesReturnController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/auth/change-password', [AuthController::class, 'changePassword']);

    // Readable by any authenticated user (POS/reporting screens need this too).
    Route::get('/catalog/brands', [BrandController::class, 'index']);
    Route::get('/catalog/product-types', [ProductTypeController::class, 'index']);
    Route::get('/catalog/colors', [ColorController::class, 'index']);
    Route::get('/catalog/models', [ProductModelController::class, 'index']);
    Route::get('/catalog/models/{productModel}', [ProductModelController::class, 'show']);
    Route::get('/catalog/products', [ProductVariantColorController::class, 'index']);

    // Mutations require explicit catalog management permission.
    Route::middleware('permission:catalog.manage')->group(function () {
        Route::post('/catalog/brands', [BrandController::class, 'store']);
        Route::put('/catalog/brands/{brand}', [BrandController::class, 'update']);
        Route::delete('/catalog/brands/{brand}', [BrandController::class, 'destroy']);

        Route::post('/catalog/product-types', [ProductTypeController::class, 'store']);
        Route::put('/catalog/product-types/{productType}', [ProductTypeController::class, 'update']);

        Route::post('/catalog/colors', [ColorController::class, 'store']);
        Route::put('/catalog/colors/{color}', [ColorController::class, 'update']);
        Route::delete('/catalog/colors/{color}', [ColorController::class, 'destroy']);

        Route::post('/catalog/models', [ProductModelController::class, 'store']);
        Route::put('/catalog/models/{productModel}', [ProductModelController::class, 'update']);
        Route::delete('/catalog/models/{productModel}', [ProductModelController::class, 'destroy']);

        Route::post('/catalog/models/{productModel}/variants', [ProductVariantController::class, 'store']);
        Route::put('/catalog/variants/{variant}', [ProductVariantController::class, 'update']);
        Route::delete('/catalog/variants/{variant}', [ProductVariantController::class, 'destroy']);

        Route::post('/catalog/variants/{variant}/colors', [ProductVariantColorController::class, 'store']);
        Route::put('/catalog/products/{sku}', [ProductVariantColorController::class, 'update']);
        Route::delete('/catalog/products/{sku}', [ProductVariantColorController::class, 'destroy']);
    });

    // Accounting — readable by anyone with accounting.view (Admin/Accountant).
    Route::middleware('permission:accounting.view')->group(function () {
        Route::get('/accounting/accounts', [ChartOfAccountController::class, 'index']);
        Route::get('/accounting/accounts/{account}/ledger', [LedgerController::class, 'forAccount']);
        Route::get('/accounting/journal-entries', [JournalEntryController::class, 'index']);
    });

    // Manual postings and custom accounts require accounting.manage.
    Route::middleware('permission:accounting.manage')->group(function () {
        Route::post('/accounting/accounts', [ChartOfAccountController::class, 'store']);
        Route::post('/accounting/journal-entries', [JournalEntryController::class, 'store']);
    });

    // Distributors — readable by anyone who can manage purchases; only
    // catalog/purchase managers can create or edit them.
    Route::get('/distributors', [DistributorController::class, 'index']);
    Route::get('/distributors/{distributor}', [DistributorController::class, 'show']);
    Route::get('/distributors/{distributor}/ledger', [DistributorController::class, 'ledger']);

    Route::middleware('permission:distributors.manage')->group(function () {
        Route::post('/distributors', [DistributorController::class, 'store']);
        Route::put('/distributors/{distributor}', [DistributorController::class, 'update']);
        Route::delete('/distributors/{distributor}', [DistributorController::class, 'destroy']);
    });

    Route::get('/payment-methods', [PaymentMethodController::class, 'index']);

    // Purchases — readable by anyone (POS/reporting), only purchase managers can record them.
    Route::get('/purchases', [PurchaseInvoiceController::class, 'index']);
    Route::get('/purchases/{purchaseInvoice}', [PurchaseInvoiceController::class, 'show']);

    Route::middleware('permission:purchases.manage')->group(function () {
        Route::post('/purchases', [PurchaseInvoiceController::class, 'store']);
        Route::post('/purchase-returns', [PurchaseReturnController::class, 'store']);
    });

    // Customers — readable by anyone (POS needs this); only customers.manage can create/edit.
    Route::get('/customers', [CustomerController::class, 'index']);
    Route::get('/customers/{customer}', [CustomerController::class, 'show']);
    Route::get('/customers/{customer}/ledger', [CustomerController::class, 'ledger']);

    Route::middleware('permission:customers.manage')->group(function () {
        Route::post('/customers', [CustomerController::class, 'store']);
        Route::put('/customers/{customer}', [CustomerController::class, 'update']);
        Route::delete('/customers/{customer}', [CustomerController::class, 'destroy']);
    });

    // POS / Sales — recording a sale requires pos.sell; anyone can browse history.
    Route::get('/pos/search', [PosSearchController::class, 'search']);
    Route::get('/sales', [SalesInvoiceController::class, 'index']);
    Route::get('/sales/{salesInvoice}', [SalesInvoiceController::class, 'show']);

    Route::middleware('permission:pos.sell')->group(function () {
        Route::post('/sales', [SalesInvoiceController::class, 'store']);
        Route::post('/sales-returns', [SalesReturnController::class, 'store']);
        Route::post('/phone-exchanges', [PhoneExchangeController::class, 'store']);
    });

    // Expenses — Admin/Accountant only, both viewing and recording.
    Route::middleware('permission:expenses.manage')->group(function () {
        Route::get('/expense-categories', [ExpenseCategoryController::class, 'index']);
        Route::post('/expense-categories', [ExpenseCategoryController::class, 'store']);
        Route::get('/expenses', [ExpenseController::class, 'index']);
        Route::post('/expenses', [ExpenseController::class, 'store']);
    });

    // Reports — read-only, available to anyone with reports.view.
    Route::middleware('permission:reports.view')->group(function () {
        Route::get('/dashboard/summary', [DashboardController::class, 'summary']);
        Route::get('/reports/stock', [StockReportController::class, 'index']);
        Route::get('/reports/imei-stock', [StockReportController::class, 'imeiUnits']);
        Route::get('/reports/stock-movements', [StockReportController::class, 'movements']);
        Route::get('/reports/sales/summary', [SalesReportController::class, 'summary']);
        Route::get('/reports/dues', [DueReportController::class, 'index']);
        Route::get('/reports/cash-position', [CashPositionController::class, 'index']);
        Route::get('/reports/imei-history', [ImeiHistoryController::class, 'show']);
    });

    // Purchase totals reveal wholesale cost — same restriction as cost/profit.
    Route::middleware('permission:reports.view-cost|purchases.manage')->group(function () {
        Route::get('/reports/purchases/summary', [PurchaseReportController::class, 'summary']);
        Route::get('/reports/purchases/price-history', [PurchaseReportController::class, 'priceHistory']);
    });

    // Branches — readable by anyone (needed to pick a branch in forms);
    // only branches.manage can create/edit them.
    Route::get('/branches', [BranchController::class, 'index']);

    Route::middleware('permission:branches.manage')->group(function () {
        Route::post('/branches', [BranchController::class, 'store']);
        Route::put('/branches/{branch}', [BranchController::class, 'update']);
    });

    // Stock transfers between branches — readable by anyone, recording
    // one requires stock.transfer.
    Route::get('/stock-transfers', [StockTransferController::class, 'index']);

    Route::middleware('permission:stock.transfer')->group(function () {
        Route::post('/stock-transfers', [StockTransferController::class, 'store']);
    });

    // Staff accounts — Admin only.
    Route::middleware('permission:users.manage')->group(function () {
        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::put('/users/{user}', [UserController::class, 'update']);
        Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword']);
        Route::get('/roles', [RoleController::class, 'index']);
    });

    // Database backup — Admin only.
    Route::middleware('permission:backup.manage')->group(function () {
        Route::get('/backup/status', [BackupSettingController::class, 'status']);
        Route::get('/backup/google-auth-url', [BackupSettingController::class, 'googleAuthUrl']);
        Route::post('/backup/google-disconnect', [BackupSettingController::class, 'disconnectGoogle']);
        Route::get('/backup/drive-folders', [BackupSettingController::class, 'driveFolders']);
        Route::post('/backup/settings', [BackupSettingController::class, 'updateSettings']);
        Route::post('/backup/run-now', [BackupSettingController::class, 'runNow']);
        Route::get('/backup/logs', [BackupSettingController::class, 'logs']);
    });
});
