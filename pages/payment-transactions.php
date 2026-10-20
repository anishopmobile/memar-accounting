<?php
require_once __DIR__ . '/../assets/php/db.php';
require_once __DIR__ . '/../assets/php/payment/payment_loader.php';

$repo = new PaymentFormRepository($mysqli);
$forms = $repo->all();

$statuses = [
    'created', 'pending', 'redirected', 'callback_received',
    'verifying', 'paid', 'failed', 'cancelled', 'expired',
];
?>
<input type="hidden" id="clientTimezoneOffset" value="">
<div class="card" id="paymentDashboardCards">
    <div class="stats-grid">
        <div class="stat-card"><div class="stat-label">کل تراکنش‌ها</div><div class="stat-value" id="statTotal">-</div></div>
        <div class="stat-card"><div class="stat-label">پرداخت موفق</div><div class="stat-value" id="statPaid">-</div></div>
        <div class="stat-card"><div class="stat-label">ناموفق</div><div class="stat-value" id="statFailed">-</div></div>
        <div class="stat-card"><div class="stat-label">در انتظار</div><div class="stat-value" id="statPending">-</div></div>
        <div class="stat-card"><div class="stat-label">پرداخت امروز</div><div class="stat-value" id="statToday">-</div></div>
        <div class="stat-card"><div class="stat-label">مجموع درآمد</div><div class="stat-value" id="statRevenue">-</div></div>
    </div>
</div>

<div class="card" id="paymentTransactionList">
    <div class="page-actions">
        <h2 style="margin:0;">تراکنش‌های پرداخت</h2>
        <button type="button" class="button-secondary" id="txRefresh">تازه‌سازی</button>
    </div>

    <div class="filter-panel tx-filter-panel">
        <input type="search" id="txSearch" placeholder="جستجو (شماره/کد پیگیری/نام/موبایل)...">
        <select id="txStatus">
            <option value="">همه وضعیت‌ها</option>
            <?php foreach ($statuses as $s): ?>
                <option value="<?php echo htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(PaymentStateMachine::label($s), ENT_QUOTES, 'UTF-8'); ?></option>
            <?php endforeach; ?>
        </select>
        <select id="txForm">
            <option value="">همه فرم‌ها</option>
            <?php foreach ($forms as $f): ?>
                <option value="<?php echo (int) $f->id; ?>"><?php echo htmlspecialchars($f->title, ENT_QUOTES, 'UTF-8'); ?></option>
            <?php endforeach; ?>
        </select>
        <input type="text" id="txAmountMin" placeholder="از مبلغ" inputmode="numeric">
        <input type="text" id="txAmountMax" placeholder="تا مبلغ" inputmode="numeric">
        <input type="date" id="txDateFrom" title="از تاریخ">
        <input type="date" id="txDateTo" title="تا تاریخ">
        <button type="button" class="button" id="txApply">اعمال فیلتر</button>
    </div>

    <div class="table-wrapper">
        <table class="action-table" id="paymentTxTable">
            <thead>
                <tr>
                    <th data-sort="number">شماره</th>
                    <th data-sort="form_id" class="no-sort">فرم</th>
                    <th data-sort="amount">مبلغ</th>
                    <th data-sort="status">وضعیت</th>
                    <th data-sort="mobile">مشتری</th>
                    <th data-sort="date">تاریخ</th>
                    <th class="no-sort">عملیات</th>
                </tr>
            </thead>
            <tbody>
                <tr><td colspan="7" class="empty-state">در حال بارگذاری...</td></tr>
            </tbody>
        </table>
    </div>
    <div id="txPagination" class="dashboard-pagination"></div>
</div>

<!-- Details modal -->
<div class="modal" id="txDetailModal">
    <div class="modal-backdrop" data-close="txDetailModal"></div>
    <div class="modal-content">
        <div class="modal-header">
            <h2>جزئیات تراکنش</h2>
            <button class="modal-close" data-close="txDetailModal" type="button">×</button>
        </div>
        <div id="txDetailBody"><p class="empty-state">در حال بارگذاری...</p></div>
    </div>
</div>

<script>
    window.PAYMENT_STATUSES = <?php echo json_encode(array_values(array_map(fn($s) => ['value' => $s, 'label' => PaymentStateMachine::label($s)], $statuses))); ?>;
    window.PAYMENT_FORMS = <?= json_encode(array_map(fn($f) => ['value' => $f->id, 'label' => $f->title], $forms)); ?>;
</script>