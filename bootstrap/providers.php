<?php

use App\Providers\AppServiceProvider;
use Platform\Admin\Providers\PlatformAdminServiceProvider;
use Platform\Billing\Providers\BillingServiceProvider;
use Platform\Enforcement\Providers\EnforcementServiceProvider;
use Platform\Plans\Providers\PlansServiceProvider;
use Platform\Subscriptions\Providers\SubscriptionsServiceProvider;
use Platform\Tenancy\Providers\TenancyServiceProvider;
use Webkul\Admin\Providers\AdminServiceProvider;
use Webkul\Attribute\Providers\AttributeServiceProvider;
use Webkul\BookingProduct\Providers\BookingProductServiceProvider;
use Webkul\CartRule\Providers\CartRuleServiceProvider;
use Webkul\CatalogRule\Providers\CatalogRuleServiceProvider;
use Webkul\Category\Providers\CategoryServiceProvider;
use Webkul\Checkout\Providers\CheckoutServiceProvider;
use Webkul\CMS\Providers\CMSServiceProvider;
use Webkul\Core\Providers\CoreServiceProvider;
use Webkul\Core\Providers\EnvValidatorServiceProvider;
use Webkul\Customer\Providers\CustomerServiceProvider;
use Webkul\DataGrid\Providers\DataGridServiceProvider;
use Webkul\DataTransfer\Providers\DataTransferServiceProvider;
use Webkul\DebugBar\Providers\DebugBarServiceProvider;
use Webkul\EUWithdrawal\Providers\EUWithdrawalServiceProvider;
use Webkul\FPC\Providers\FPCServiceProvider;
use Webkul\GDPR\Providers\GDPRServiceProvider;
use Webkul\ImageCache\Providers\ImageCacheServiceProvider;
use Webkul\Installer\Providers\InstallerServiceProvider;
use Webkul\Inventory\Providers\InventoryServiceProvider;
use Webkul\MagicAI\Providers\MagicAIServiceProvider;
use Webkul\Marketing\Providers\MarketingServiceProvider;
use Webkul\Notification\Providers\NotificationServiceProvider;
use Webkul\PayGlocal\Providers\PayGlocalServiceProvider;
use Webkul\Payment\Providers\PaymentServiceProvider;
use Webkul\Paypal\Providers\PaypalServiceProvider;
use Webkul\PayU\Providers\PayUServiceProvider;
use Webkul\PhonePe\Providers\PhonePeServiceProvider;
use Webkul\Product\Providers\ProductServiceProvider;
use Webkul\Razorpay\Providers\RazorpayServiceProvider;
use Webkul\RMA\Providers\RMAServiceProvider;
use Webkul\Rule\Providers\RuleServiceProvider;
use Webkul\Sales\Providers\SalesServiceProvider;
use Webkul\Shipping\Providers\ShippingServiceProvider;
use Webkul\Shop\Providers\ShopServiceProvider;
use Webkul\Sitemap\Providers\SitemapServiceProvider;
use Webkul\SocialLogin\Providers\SocialLoginServiceProvider;
use Webkul\SocialShare\Providers\SocialShareServiceProvider;
use Webkul\Stripe\Providers\StripeServiceProvider;
use Webkul\Tax\Providers\TaxServiceProvider;
use Webkul\Theme\Providers\ThemeServiceProvider;
use Webkul\User\Providers\UserServiceProvider;

return [
    /**
     * Application service providers.
     */
    AppServiceProvider::class,

    /**
     * Platform tenancy provider — must boot before every Webkul provider below
     * (specifically CoreServiceProvider) so tenant DB/cache/filesystem/queue
     * context is established before any Bagisto code resolves a connection,
     * channel, or config value. See docs/architecture/tenancy.md (R9).
     */
    TenancyServiceProvider::class,

    /**
     * Plan/feature entitlement domain (TASK-ARCH-008) - depends on
     * Platform\Tenancy (tenant model, tenancy() helpers), so registered
     * after it; no ordering requirement relative to Webkul providers below
     * (registers only a console command, no listeners/middleware/routes).
     */
    PlansServiceProvider::class,

    /**
     * TASK-ARCH-016: the provider-agnostic subscription lifecycle domain
     * (Platform\Subscriptions\Services\SubscriptionLifecycle) - depends on
     * Platform\Plans (registered above) and Platform\Tenancy (registered
     * above that), so registered after both. Registers no listeners/
     * middleware/routes of its own - `Platform\Tenancy\Services\
     * TenantProvisioner` and `Platform\Admin`'s controllers call into it
     * directly. See docs/architecture/subscriptions.md.
     */
    SubscriptionsServiceProvider::class,

    /**
     * TASK-ARCH-018: the provider-agnostic billing domain
     * (Platform\Billing\Services\BillingService/PaymentLifecycle, the
     * PaymentProvider contract, the Stripe reference adapter) - depends on
     * Platform\Subscriptions/Platform\Plans/Platform\Tenancy (all
     * registered above), so registered after all three. Binds
     * PaymentProvider::class in register() (config-driven resolution via
     * BillingProviderResolver); registers no listeners/middleware/routes
     * of its own in this task - no checkout/webhook HTTP endpoint exists
     * yet (TASK-ARCH-019). See docs/architecture/billing.md.
     */
    BillingServiceProvider::class,

    /**
     * TASK-ARCH-011: the CENTRAL Platform Admin area (tenants/plans/
     * provisioning management for the SaaS business itself, NOT a tenant's
     * Bagisto store admin) - depends on Platform\Tenancy and Platform\Plans
     * (reads Tenant/Plan directly), so registered after both; registers its
     * own 'platform' guard routes/views/command only, no listeners/
     * middleware-group definitions of its own (those live in
     * bootstrap/app.php, see the 'platform' group comment there). See
     * docs/architecture/platform-admin.md.
     */
    PlatformAdminServiceProvider::class,

    /**
     * TASK-ARCH-012: wires Bagisto's real product-creation extension
     * point (Webkul\Product\Models\Product's Eloquent `creating` event)
     * to Platform\Plans' feature-agnostic TenantLimits enforcement
     * engine. Depends on Platform\Plans (registered above) AND
     * Webkul\Product (registered below, though load order does not
     * matter here - Eloquent model-event registration has no dependency
     * on the owning package's provider having booted first). See
     * docs/architecture/feature-limits.md "Enforcement boundary".
     */
    EnforcementServiceProvider::class,

    /**
     * Webkul's service providers.
     */
    AdminServiceProvider::class,
    AttributeServiceProvider::class,
    BookingProductServiceProvider::class,
    CMSServiceProvider::class,
    CartRuleServiceProvider::class,
    CatalogRuleServiceProvider::class,
    CategoryServiceProvider::class,
    CheckoutServiceProvider::class,
    CoreServiceProvider::class,
    EnvValidatorServiceProvider::class,
    CustomerServiceProvider::class,
    DataGridServiceProvider::class,
    DataTransferServiceProvider::class,
    DebugBarServiceProvider::class,
    EUWithdrawalServiceProvider::class,
    FPCServiceProvider::class,
    GDPRServiceProvider::class,
    ImageCacheServiceProvider::class,
    InstallerServiceProvider::class,
    InventoryServiceProvider::class,
    MagicAIServiceProvider::class,
    MarketingServiceProvider::class,
    NotificationServiceProvider::class,
    PayGlocalServiceProvider::class,
    PayUServiceProvider::class,
    PaymentServiceProvider::class,
    PaypalServiceProvider::class,
    PhonePeServiceProvider::class,
    ProductServiceProvider::class,
    RMAServiceProvider::class,
    RazorpayServiceProvider::class,
    RuleServiceProvider::class,
    SalesServiceProvider::class,
    ShippingServiceProvider::class,
    ShopServiceProvider::class,
    SitemapServiceProvider::class,
    SocialLoginServiceProvider::class,
    SocialShareServiceProvider::class,
    StripeServiceProvider::class,
    TaxServiceProvider::class,
    ThemeServiceProvider::class,
    UserServiceProvider::class,
];
