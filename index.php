<?php
require_once __DIR__ . '/assets/php/boot.php'; // session_start + توابع CSRF قبل از هر خروجی
$pages = [
    'dashboard' => 'داشبورد',
    'factors' => 'فاکتورها',
    'purchase-invoice' => 'فاکتور خرید',
    'sales-return' => 'برگشت فروش',
    'product-categories' => 'دسته‌بندی کالاها',
    'goods-and-services' => 'کالاها و خدمات',
    'defective-goods' => 'کالاهای معیوب',
    'customers' => 'مشتریان',
    'suppliers' => 'تامین‌کنندگان',
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
$page = $_GET['page'] ?? 'dashboard';
if (!array_key_exists($page, $pages)) {
    $page = 'dashboard';
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>کوشا حساب | <?php echo $pages[$page]; ?></title>
    <link rel="stylesheet" href="assets/css/fontawesome.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/kamadatepicker.css">
    <script src="assets/js/jquery.min.js"></script>
    <script src="assets/js/kamadatepicker.js"></script>
    <script src="assets/js/main.js" defer></script>
    <script src="assets/js/invoice.js" defer></script>
    <script src="assets/js/bundle.js" defer></script>
    <script src="assets/js/payment-forms.js" defer></script>
    <script src="assets/js/current-affairs.js" defer></script>
    <script src="assets/js/payment-transactions.js" defer></script>
    <script src="assets/js/payment-sms.js" defer></script>
    <script src="assets/js/payment-zarinpal.js" defer></script>
</head>

<body id="<?php echo $page; ?>">
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
        <aside class="sidebar">
            <div class="brand-panel">
                <div class="brand-title">کوشا حساب</div>
                <div class="brand-subtitle">حسابداری ساده</div>
            </div>
            <nav class="main-nav">
                <?php
                /* فاکتور خرید و برگشت فروش فقط زیرمنوی «فاکتورها» هستند */
                /* زیرمنوهای گزارشات (به‌جز اولین که منوی «گزارشات» را باز می‌کند) در نوار اصلی نمایش داده نمی‌شوند */
                $factorsChildren = ['factors', 'purchase-invoice', 'sales-return'];
                $reportsChildren = ['report-inventory', 'report-customer-debt', 'report-supplier-debt'];
                foreach ($pages as $key => $label):
                    if (in_array($key, ['purchase-invoice', 'sales-return', 'report-customer-debt', 'report-supplier-debt', 'report-sold-items-detail', 'payment-transactions', 'payment-sms-settings', 'payment-zarinpal-settings', 'account'], true)) {
							continue;
						}
                    $iconClass = $navIcons[$key] ?? 'fa-circle';
                ?>
                    <?php if ($key === 'payment-forms'): ?>
                        <?php $paymentActive = in_array($page, ['payment-forms', 'payment-transactions', 'payment-sms-settings', 'payment-zarinpal-settings'], true); ?>
                        <div class="nav-dropdown<?php echo $paymentActive ? ' open' : ''; ?>">
                            <a href="?page=payment-forms" class="nav-link nav-dropdown-toggle<?php echo $paymentActive ? ' active' : ''; ?>">
                                <span class="nav-icon"><i class="fas fa-credit-card"></i></span>
                                <span class="nav-text">پرداخت‌ها</span>
                                <span class="nav-caret"><i class="fas fa-chevron-down"></i></span>
                            </a>
                            <div class="nav-dropdown-menu">
                                <a href="?page=payment-forms" class="nav-sub-link<?php echo $page === 'payment-forms' ? ' active' : ''; ?>">فرم‌های پرداخت</a>
                                <a href="?page=payment-transactions" class="nav-sub-link<?php echo $page === 'payment-transactions' ? ' active' : ''; ?>">تراکنش‌ها</a>
                                <a href="?page=payment-sms-settings" class="nav-sub-link<?php echo $page === 'payment-sms-settings' ? ' active' : ''; ?>">تنظیمات پیامک</a>
                                <a href="?page=payment-zarinpal-settings" class="nav-sub-link<?php echo $page === 'payment-zarinpal-settings' ? ' active' : ''; ?>">تنظیمات زرین‌پال</a>
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
			<a href="?page=account" class="user-chip<?php echo $page === 'account' ? ' active' : ''; ?>" title="حساب کاربری">
			    <span class="user-chip-avatar" aria-hidden="true">
			        <?php if ($authAvatarPath !== ''): ?>
			            <img src="<?php echo htmlspecialchars($authAvatarPath, ENT_QUOTES, 'UTF-8'); ?>" alt="">
			        <?php else: ?>
			            <i class="fas fa-user"></i>
			        <?php endif; ?>
			    </span>
			    <span class="user-chip-name"><?php echo htmlspecialchars($authDisplayName, ENT_QUOTES, 'UTF-8'); ?></span>
			</a>
			<form method="post" action="logout.php" class="logout-form">
			    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
			    <button type="submit" class="logout-button">خروج</button>
			</form>
        </aside>
        <main class="main-content">
            <header class="page-header">
                <div>
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