<?php
require_once __DIR__ . '/assets/php/boot.php'; // session_start + توابع CSRF قبل از هر خروجی
require_once __DIR__ . '/assets/php/currency.php';
$pages = [
    'dashboard' => 'داشبورد',
    'factors' => 'فاکتورها',
    'purchase-invoice' => 'فاکتور خرید',
    'sales-return' => 'برگشت فروش',
    'product-categories' => 'دسته‌بندی کالاها',
    'goods-and-services' => 'کالاها و خدمات',
    'media-library' => 'کتابخانه تصاویر',
    'defective-goods' => 'کالاهای معیوب',
    'customers' => 'مشتریان',
    'suppliers' => 'تامین‌کنندگان',
    'party-statement' => 'صورت‌حساب طرف حساب',
    'report-inventory' => 'گزارش موجودی کالاها',
    'report-customer-debt' => 'گزارش بدهی مشتریان',
    'report-supplier-debt' => 'گزارش بدهی به تامین‌کنندگان',
	'report-sold-items-detail' => 'ریز کالاهای فروخته شده', 
    'settings' => 'تنظیمات',
    'notes' => 'یادداشت‌ها',
    'current-affairs' => 'امور جاری',
    'about-us' => 'درباره ما',
    'payment-forms' => 'فرم‌های پرداخت',
    'payment-transactions' => 'تراکنش‌ها',
    'payment-sms-settings' => 'تنظیمات پیامک',
    'payment-zarinpal-settings' => 'تنظیمات زرین‌پال',
    'account' => 'حساب کاربری',
];
/* Application branding is UI-only and separate from store/invoice identity. */
require_once __DIR__ . '/assets/php/db.php';
$brandMode = 'name';
$brandLogo = '';
$brandResult = $mysqli->query('SELECT display_mode, logo_path FROM app_brand_settings WHERE id = 1 LIMIT 1');
if ($brandResult && ($brandRow = $brandResult->fetch_assoc())) {
    $candidate = (string) ($brandRow['logo_path'] ?? '');
    if (preg_match('#^assets/uploads/brand_[0-9a-f]{32}\\.(png|jpg|webp)$#D', $candidate) === 1
        && is_file(__DIR__ . '/' . $candidate)) {
        $brandLogo = $candidate;
    }
    $candidateMode = (string) ($brandRow['display_mode'] ?? 'name');
    if ($brandLogo !== '' && in_array($candidateMode, ['logo', 'logo_name'], true)) {
        $brandMode = $candidateMode;
    }
}
$page = $_GET['page'] ?? 'dashboard';
if (!array_key_exists($page, $pages)) {
    $page = 'dashboard';
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl" data-bs-theme="light">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>حسابداری معمار | <?php echo $pages[$page]; ?></title>
    <link rel="stylesheet" href="assets/vendor/tabler/tabler.rtl.min.css">
    <link rel="stylesheet" href="assets/css/fontawesome.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/kamadatepicker.css">
    <link rel="stylesheet" href="assets/css/tabler-integration.css">
    <link rel="stylesheet" href="assets/css/navbar-toolbar.css">
    <link rel="stylesheet" href="assets/css/dashboard-about.css">
    <link rel="stylesheet" href="assets/css/product-brand-defective.css">
    <link rel="stylesheet" href="assets/vendor/hcg-searchable-select/hcg-select.css">
    <link rel="stylesheet" href="assets/css/hcg-select-integration.css">
    <?php if (in_array($page, ['goods-and-services','product-categories','media-library','settings','factors','purchase-invoice','sales-return','defective-goods','report-inventory','report-sold-items-detail','dashboard'], true)): ?>
    <link rel="stylesheet" href="assets/vendor/dropzone/dropzone.min.css">
    <link rel="stylesheet" href="assets/css/media-library.css">
    <?php endif; ?>
    <script src="assets/js/jquery.min.js"></script>
    <script src="assets/js/kamadatepicker.js"></script>
    <script>window.APP_CURRENCY = <?php echo json_encode(getCurrentCurrency(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS); ?>;
    window.APP_CSRF_TOKEN = <?php echo json_encode(csrf_token(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS); ?>;</script>
    <script src="assets/vendor/hcg-searchable-select/hcg-select.js" defer></script>
    <script src="assets/js/hcg-select-integration.js" defer></script>
    <script src="assets/js/currency.js" defer></script>
    <script src="assets/js/currency-settings.js" defer></script>
    <script src="assets/js/main.js" defer></script>
    <?php if (in_array($page, ['goods-and-services','product-categories','media-library','settings','factors','purchase-invoice','sales-return','defective-goods','report-inventory','report-sold-items-detail','dashboard'], true)): ?>
    <script src="assets/vendor/dropzone/dropzone.min.js" defer></script>
    <script src="assets/js/media-ui.js" defer></script>
    <?php endif; ?>
    <script src="assets/js/brand-settings.js" defer></script>
    <script src="assets/js/layout.js" defer></script>
    <script src="assets/js/invoice.js" defer></script>
    <script src="assets/js/accounting.js" defer></script>
    <script src="assets/js/bundle.js" defer></script>
    <script src="assets/js/payment-forms.js" defer></script>
    <script src="assets/js/current-affairs.js" defer></script>
    <script src="assets/js/payment-transactions.js" defer></script>
    <script src="assets/js/payment-sms.js" defer></script>
    <script src="assets/js/payment-zarinpal.js" defer></script>
</head>

<body id="<?php echo $page; ?>" class="tabler-admin">
    <?php
    date_default_timezone_set('Asia/Tehran');
    require_once __DIR__ . '/assets/php/jdf.php';
    $shamsiDate = jdate('l، j F Y');
    $navIcons = [
        'dashboard' => 'fa-chart-pie',
        'factors' => 'fa-file-invoice-dollar',
        'purchase-invoice' => 'fa-cart-plus',
        'sales-return' => 'fa-undo',
        'product-categories' => 'fa-sitemap',
        'goods-and-services' => 'fa-box-open',
        'defective-goods' => 'fa-times-circle',
        'customers' => 'fa-users',
        'suppliers' => 'fa-truck',
        'party-statement' => 'fa-money-bill-transfer',
        'report-inventory' => 'fa-boxes-stacked',
        'report-customer-debt' => 'fa-user-clock',
        'report-supplier-debt' => 'fa-truck-field',
		'report-sold-items-detail' => 'fa-list-alt',
        'settings' => 'fa-cog',
        'notes' => 'fa-sticky-note',
        'current-affairs' => 'fa-calendar-days',
        'about-us' => 'fa-info-circle',
        'payment-forms' => 'fa-credit-card',
        'payment-transactions' => 'fa-receipt',
        'payment-sms-settings' => 'fa-sms',
        'payment-zarinpal-settings' => 'fa-coins',
        'account' => 'fa-user-gear',
    ];
    ?>
    <?php
    /* اطلاعات کاربر واردشده برای نمایش در نوار بالا (قبل از خروجی HTML) */
    $authUser = current_user();
    $authDisplayName = 'کاربر';
    $authAvatarPath = '';
    if ($authUser !== null) {
        $authDisplayName = trim((string) $authUser['first_name'] . ' ' . (string) $authUser['last_name']);
        if ($authDisplayName === '') {
            $authDisplayName = (string) ($authUser['username'] ?? 'کاربر');
        }
        $authAvatarPath = (string) ($authUser['avatar_path'] ?? '');
    }
    ?>
    <div class="app-shell">
        <div class="sidebar-backdrop" id="sidebarBackdrop" aria-hidden="true"></div>
        <aside class="sidebar" id="appSidebar" aria-label="منوی مدیریت">
            <div class="app-sidebar-heading">
            <div class="brand-panel brand-mode-<?php echo $brandMode; ?>" aria-label="حسابداری معمار">
                <?php if ($brandLogo !== '' && $brandMode !== 'name'): ?>
                    <img class="brand-logo" src="<?php echo htmlspecialchars($brandLogo, ENT_QUOTES, 'UTF-8'); ?>" alt="لوگوی حسابداری معمار" width="52" height="42">
                <?php endif; ?>
                <?php if ($brandMode !== 'logo'): ?>
                    <div class="brand-title">حسابداری معمار</div>
                <?php endif; ?>
            </div>
            <button type="button" class="sidebar-close" id="sidebarClose" aria-label="بستن منو"><i class="fas fa-times" aria-hidden="true"></i></button>
            </div>
            <nav class="main-nav">
                <?php
                /* فاکتور خرید و برگشت فروش فقط زیرمنوی «فاکتورها» هستند */
                /* زیرمنوهای گزارشات (به‌جز اولین که منوی «گزارشات» را باز می‌کند) در نوار اصلی نمایش داده نمی‌شوند */
                $factorsChildren = ['factors', 'purchase-invoice', 'sales-return', 'defective-goods'];
                $reportsChildren = ['report-inventory', 'report-customer-debt', 'report-supplier-debt', 'report-sold-items-detail'];
                foreach ($pages as $key => $label):
                    if (in_array($key, ['purchase-invoice', 'sales-return', 'report-customer-debt', 'report-supplier-debt', 'report-sold-items-detail', 'defective-goods', 'notes', 'payment-forms', 'payment-transactions', 'payment-sms-settings', 'payment-zarinpal-settings', 'account', 'about-us', 'party-statement'], true)) {
							continue;
						}
                    $iconClass = $navIcons[$key] ?? 'fa-circle';
                ?>
                    <?php if ($key === 'settings'): ?>
                        <?php
                        $paymentActive = in_array($page, ['payment-forms', 'payment-transactions', 'payment-sms-settings', 'payment-zarinpal-settings'], true);
                        $settingsActive = in_array($page, ['settings', 'notes', 'payment-forms', 'payment-transactions', 'payment-sms-settings', 'payment-zarinpal-settings'], true);
                        ?>
                        <div class="nav-dropdown<?php echo $settingsActive ? ' open' : ''; ?>">
                            <a href="?page=settings" class="nav-link nav-dropdown-toggle<?php echo $settingsActive ? ' active' : ''; ?>" aria-expanded="<?php echo $settingsActive ? 'true' : 'false'; ?>" aria-haspopup="true">
                                <span class="nav-icon"><i class="fas fa-cog"></i></span>
                                <span class="nav-text">تنظیمات</span>
                                <span class="nav-caret"><i class="fas fa-chevron-down"></i></span>
                            </a>
                            <div class="nav-dropdown-menu">
                                <a href="?page=settings" class="nav-sub-link<?php echo $page === 'settings' ? ' active' : ''; ?>">تنظیمات سیستم</a>
                                <a href="?page=notes" class="nav-sub-link<?php echo $page === 'notes' ? ' active' : ''; ?>">یادداشت‌ها</a>
                                <details class="nav-subgroup"<?php echo $paymentActive ? ' open' : ''; ?>>
                                    <summary class="<?php echo $paymentActive ? 'active' : ''; ?>">پرداخت‌ها <i class="fas fa-chevron-down" aria-hidden="true"></i></summary>
                                    <div class="nav-subgroup-links">
                                        <a href="?page=payment-forms" class="nav-sub-link<?php echo $page === 'payment-forms' ? ' active' : ''; ?>">فرم‌های پرداخت</a>
                                        <a href="?page=payment-transactions" class="nav-sub-link<?php echo $page === 'payment-transactions' ? ' active' : ''; ?>">تراکنش‌ها</a>
                                        <a href="?page=payment-sms-settings" class="nav-sub-link<?php echo $page === 'payment-sms-settings' ? ' active' : ''; ?>">تنظیمات پیامک</a>
                                        <a href="?page=payment-zarinpal-settings" class="nav-sub-link<?php echo $page === 'payment-zarinpal-settings' ? ' active' : ''; ?>">تنظیمات زرین‌پال</a>
                                    </div>
                                </details>
                            </div>
                        </div>
                    <?php elseif ($key === 'factors'): ?>
                        <?php $factorsActive = in_array($page, $factorsChildren, true); ?>
                        <div class="nav-dropdown<?php echo $factorsActive ? ' open' : ''; ?>">
                            <a href="?page=factors" class="nav-link nav-dropdown-toggle<?php echo $factorsActive ? ' active' : ''; ?>">
                                <span class="nav-icon"><i class="fas fa-file-invoice-dollar"></i></span>
                                <span class="nav-text">فاکتورها</span>
                                <span class="nav-caret"><i class="fas fa-chevron-down"></i></span>
                            </a>
                            <div class="nav-dropdown-menu">
                                <a href="?page=factors" class="nav-sub-link<?php echo $page === 'factors' ? ' active' : ''; ?>">فاکتور فروش</a>
                                <a href="?page=purchase-invoice" class="nav-sub-link<?php echo $page === 'purchase-invoice' ? ' active' : ''; ?>">فاکتور خرید</a>
                                <a href="?page=sales-return" class="nav-sub-link<?php echo $page === 'sales-return' ? ' active' : ''; ?>">برگشت فروش</a>
                                <a href="?page=defective-goods" class="nav-sub-link<?php echo $page === 'defective-goods' ? ' active' : ''; ?>">کالاهای معیوب</a>
                            </div>
                        </div>
                    <?php elseif ($key === 'report-inventory'): ?>
                        <?php $reportsActive = in_array($page, $reportsChildren, true); ?>
                        <div class="nav-dropdown<?php echo $reportsActive ? ' open' : ''; ?>">
                            <a href="?page=report-inventory" class="nav-link nav-dropdown-toggle<?php echo $reportsActive ? ' active' : ''; ?>">
                                <span class="nav-icon"><i class="fas fa-chart-bar"></i></span>
                                <span class="nav-text">گزارشات</span>
                                <span class="nav-caret"><i class="fas fa-chevron-down"></i></span>
                            </a>
                            <div class="nav-dropdown-menu">
                                <a href="?page=report-inventory" class="nav-sub-link<?php echo $page === 'report-inventory' ? ' active' : ''; ?>">گزارش موجودی کالاها</a>
								<a href="?page=report-sold-items-detail" class="nav-sub-link<?php echo $page === 'report-sold-items-detail' ? ' active' : ''; ?>">ریز کالاهای فروخته شده</a>
                                <a href="?page=report-customer-debt" class="nav-sub-link<?php echo $page === 'report-customer-debt' ? ' active' : ''; ?>">گزارش بدهی مشتریان</a>
                                <a href="?page=report-supplier-debt" class="nav-sub-link<?php echo $page === 'report-supplier-debt' ? ' active' : ''; ?>">گزارش بدهی به تامین‌کنندگان</a>
                            </div>
                        </div>
                    <?php else: ?>
                        <a href="?page=<?php echo $key; ?>" class="nav-link<?php echo $page === $key ? ' active' : ''; ?>">
                            <span class="nav-icon"><i class="fas <?php echo $iconClass; ?>"></i></span>
                            <span class="nav-text"><?php echo $label; ?></span>
                        </a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </nav>
            <?php $accountMenuActive = in_array($page, ['account', 'about-us'], true); ?>
            <div class="nav-dropdown account-nav-dropdown">
                <button type="button" class="user-chip nav-dropdown-toggle<?php echo $accountMenuActive ? ' active' : ''; ?>"
                    title="حساب کاربری" aria-label="منوی حساب کاربری"
                    aria-expanded="false"
                    aria-haspopup="true" aria-controls="accountNavMenu">
                    <span class="user-chip-avatar" aria-hidden="true">
                        <?php if ($authAvatarPath !== ''): ?>
                            <img src="<?php echo htmlspecialchars($authAvatarPath, ENT_QUOTES, 'UTF-8'); ?>" alt="">
                        <?php else: ?>
                            <i class="fas fa-user"></i>
                        <?php endif; ?>
                    </span>
                    <span class="user-chip-name"><?php echo htmlspecialchars($authDisplayName, ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="nav-caret" aria-hidden="true"><i class="fas fa-chevron-down"></i></span>
                </button>
                <div class="nav-dropdown-menu account-nav-menu" id="accountNavMenu">
                    <a href="?page=account" class="nav-sub-link<?php echo $page === 'account' ? ' active' : ''; ?>"><i class="fas fa-user-gear" aria-hidden="true"></i> حساب کاربری</a>
                    <a href="?page=about-us" class="nav-sub-link<?php echo $page === 'about-us' ? ' active' : ''; ?>"><i class="fas fa-info-circle" aria-hidden="true"></i> درباره ما</a>
                </div>
            </div>
			<form method="post" action="logout.php" class="logout-form">
			    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
			    <button type="submit" class="logout-button">خروج</button>
			</form>
        </aside>
        <main class="main-content" id="mainContent">
            <header class="page-header">
                <div class="page-heading">
                    <button type="button" id="sidebarOpen" class="sidebar-toggle" aria-label="باز کردن منو" aria-controls="appSidebar" aria-expanded="false"><i class="fas fa-bars" aria-hidden="true"></i></button>
                    <h1><?php echo $pages[$page]; ?></h1>
                </div>
                <div class="page-meta"><?php echo $shamsiDate; ?></div>
            </header>
            <section class="page-body">
                <?php include __DIR__ . '/pages/' . $page . '.php'; ?>
            </section>
        </main>
    </div>
</body>

</html>