<?php
require_once __DIR__ . '/../assets/php/boot.php';
require_once __DIR__ . '/../assets/php/db.php';
require_once __DIR__ . '/../assets/php/jdf.php';

// دریافت پارامترهای فیلتر از URL
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$search = $_GET['item_search'] ?? '';

// تبدیل تاریخ میلادی به شمسی برای نمایش در اینپوت‌ها
$date_from_display = '';
if ($date_from) {
    $parts = explode('-', $date_from);
    if (count($parts) === 3) {
        $date_from_display = jdate('Y/m/d', mktime(0, 0, 0, $parts[1], $parts[2], $parts[0]));
    }
}

$date_to_display = '';
if ($date_to) {
    $parts = explode('-', $date_to);
    if (count($parts) === 3) {
        $date_to_display = jdate('Y/m/d', mktime(0, 0, 0, $parts[1], $parts[2], $parts[0]));
    }
}

// ساخت کوئری پویا
$sql = "SELECT i.id, i.invoice_number, i.type, i.invoice_date, i.payment_status, i.payable_amount,
               CONCAT(c.first_name, ' ', c.last_name) AS customer_name,
               ii.id AS item_id, ii.quantity, ii.unit_price, ii.discount, ii.line_total, ii.unit_name,
               p.code AS product_code, p.name AS product_name, pu.barcode
        FROM invoices i
        JOIN customers c ON i.customer_id = c.id
        JOIN invoice_items ii ON i.id = ii.invoice_id
        JOIN products p ON ii.product_id = p.id
        LEFT JOIN product_units pu ON ii.unit_id = pu.id
        WHERE i.type IN ('sales_invoice', 'sales_proforma')";

$params = [];
$types = "";

if (!empty($date_from)) {
    $sql .= " AND i.invoice_date >= ?";
    $params[] = $date_from;
    $types .= "s";
}
if (!empty($date_to)) {
    $sql .= " AND i.invoice_date <= ?";
    $params[] = $date_to;
    $types .= "s";
}
if (!empty($search)) {
    $like = "%" . $search . "%";
    $sql .= " AND (p.code LIKE ? OR p.name LIKE ? OR pu.barcode LIKE ?)";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= "sss";
}

$sql .= " ORDER BY i.invoice_date DESC, i.id DESC, ii.id ASC";

$stmt = $mysqli->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$invoices = [];
$total_matching_items = 0;

while ($row = $result->fetch_assoc()) {
    $inv_id = $row['id'];
    if (!isset($invoices[$inv_id])) {
        $invoices[$inv_id] = [
            'id' => $row['id'],
            'invoice_number' => $row['invoice_number'],
            'type' => $row['type'],
            'invoice_date' => $row['invoice_date'],
            'payment_status' => $row['payment_status'],
            'payable_amount' => $row['payable_amount'],
            'customer_name' => $row['customer_name'],
            'items' => []
        ];
    }
    $invoices[$inv_id]['items'][] = [
        'product_code' => $row['product_code'],
        'product_name' => $row['product_name'],
        'barcode' => $row['barcode'],
        'unit_name' => $row['unit_name'],
        'quantity' => $row['quantity'],
        'unit_price' => $row['unit_price'],
        'discount' => $row['discount'],
        'line_total' => $row['line_total']
    ];
    $total_matching_items++;
}
$stmt->close();
?>

<div class="card">
    <!-- پنل فیلتر -->
    <form method="GET" action="?page=report-sold-items-detail" class="filter-panel" style="margin-bottom: 20px; display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-end;">
        <input type="hidden" name="page" value="report-sold-items-detail">
        
        <div style="display: flex; flex-direction: column;">
            <label style="font-size: 12px; margin-bottom: 4px; color: #555;">از تاریخ</label>
            <input type="text" id="dateFromDisplay" class="inv-date-display" readonly autocomplete="off" placeholder="۱۴۰۵/۰۱/۰۱" value="<?php echo htmlspecialchars($date_from_display); ?>" style="direction:ltr; text-align:center; width: 130px; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
            <input type="hidden" id="dateFrom" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
        </div>

        <div style="display: flex; flex-direction: column;">
            <label style="font-size: 12px; margin-bottom: 4px; color: #555;">تا تاریخ</label>
            <input type="text" id="dateToDisplay" class="inv-date-display" readonly autocomplete="off" placeholder="۱۴۰۵/۱۲/۲۹" value="<?php echo htmlspecialchars($date_to_display); ?>" style="direction:ltr; text-align:center; width: 130px; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
            <input type="hidden" id="dateTo" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
        </div>

        <div style="display: flex; flex-direction: column; flex-grow: 1; max-width: 350px;">
            <label style="font-size: 12px; margin-bottom: 4px; color: #555;">جستجو (کد، نام یا بارکد کالا)</label>
            <input type="text" name="item_search" value="<?php echo htmlspecialchars($search); ?>" placeholder="مثال: 178 یا خودکار بیک" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
        </div>

        <button type="submit" class="button" style="height: 38px; padding: 0 20px;">اعمال فیلتر</button>
        <a href="?page=report-sold-items-detail" class="button-secondary" style="height: 38px; display: inline-flex; align-items: center; padding: 0 15px; text-decoration: none;">پاک کردن</a>
    </form>

    <!-- نمایش تعداد نتایج -->
    <?php if ($date_from || $date_to || $search): ?>
        <div style="margin-bottom: 15px; font-weight: bold; color: #2068ff; font-size: 14px;">
            <?php echo $total_matching_items; ?> مورد یافت شد
        </div>
    <?php endif; ?>

    <!-- لیست نتایج -->
    <?php if (count($invoices) === 0): ?>
        <div class="table-wrapper">
            <table class="action-table">
                <tbody>
                    <tr><td colspan="6" class="empty-state">موردی یافت نشد. لطفاً بازه زمانی یا عبارت جستجو را تغییر دهید.</td></tr>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <?php foreach ($invoices as $inv): ?>
            <div class="invoice-report-block" style="border: 1px solid #e0e0e0; border-radius: 8px; margin-bottom: 15px; overflow: hidden; background: #fff;">
                <!-- هدر فاکتور (قابل کلیک) -->
                <div class="invoice-header" style="padding: 12px 15px; background: #f8f9fa; cursor: pointer; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e0e0e0;" onclick="toggleInvoiceItems(this)">
                    <div style="display: flex; gap: 20px; flex-wrap: wrap; font-size: 14px; color: #333;">
                        <span><strong>شماره:</strong> <a href="?page=factors&action=edit&id=<?php echo $inv['id']; ?>" style="color: #2068ff; text-decoration: none;"><?php echo htmlspecialchars($inv['invoice_number']); ?></a></span>
                        <span><strong>مشتری:</strong> <?php echo htmlspecialchars($inv['customer_name']); ?></span>
                        <span><strong>تاریخ:</strong> <?php echo jdate('j F Y', strtotime($inv['invoice_date'])); ?></span>
                        <span><strong>نوع:</strong> <?php echo $inv['type'] === 'sales_proforma' ? 'پیش فاکتور' : 'فاکتور'; ?></span>
                        <span><strong>وضعیت:</strong> <?php echo $inv['payment_status'] === 'paid' ? 'پرداخت شده' : ($inv['payment_status'] === 'partial' ? 'جزئی' : 'پرداخت نشده'); ?></span>
                        <span><strong>مبلغ کل:</strong> <?php echo number_format($inv['payable_amount']); ?> ریال</span>
                    </div>
                    <span class="toggle-icon" style="transition: transform 0.2s; color: #666;">▼</span>
                </div>
                
                <!-- جزئیات اقلام فاکتور -->
                <div class="invoice-items hidden" style="padding: 0;">
                    <table class="action-table" style="margin: 0; border: none; box-shadow: none;">
                        <thead>
                            <tr style="background: #fff;">
                                <th style="text-align: right;">کالا</th>
                                <th>واحد</th>
                                <th>قیمت واحد</th>
                                <th>تعداد</th>
                                <th>تخفیف</th>
                                <th>مبلغ ردیف</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($inv['items'] as $item): ?>
                                <tr>
                                    <td style="text-align: right;">
                                        <?php echo htmlspecialchars($item['product_code']); ?> — <?php echo htmlspecialchars($item['product_name']); ?>
                                        <?php if ($item['barcode']): ?>
                                            <br><small style="color: #888;">بارکد: <?php echo htmlspecialchars($item['barcode']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($item['unit_name']); ?></td>
                                    <td><?php echo number_format($item['unit_price']); ?></td>
                                    <td><?php echo number_format($item['quantity']); ?></td>
                                    <td><?php echo number_format($item['discount']); ?></td>
                                    <td><?php echo number_format($item['line_total']); ?> ریال</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<style>
    .hidden { display: none; }
    .invoice-report-block:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
    .invoice-header:hover { background: #f1f3f5; }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // راه‌اندازی DatePicker شمسی برای فیلدهای تاریخ
    if (window.kamaDatepicker) {
        const dpOptions = {
            placeholder: '',
            twodigit: true,
            closeAfterSelect: true,
            nextButtonIcon: 'بعدی',
            previousButtonIcon: 'قبلی',
            forceFarsiDigits: true,
            markToday: true,
            highlightSelectedDay: true,
            sync: true,
            gotoToday: true
        };
        window.kamaDatepicker('dateFromDisplay', dpOptions);
        window.kamaDatepicker('dateToDisplay', dpOptions);
    }

    // همگام‌سازی تاریخ شمسی نمایشی با تاریخ میلادی مخفی (برای ارسال به سرور)
    function syncHiddenFromDisplay(displayId, hiddenId) {
        var jalaliVal = String(document.getElementById(displayId).value || '').trim();
        if (!jalaliVal) return;
        if (typeof jalaliStrToGregInput === 'function') {
            var greg = jalaliStrToGregInput(jalaliVal);
            if (greg) document.getElementById(hiddenId).value = greg;
        }
    }

    document.getElementById('dateFromDisplay').addEventListener('change', function() {
        syncHiddenFromDisplay('dateFromDisplay', 'dateFrom');
    });
    document.getElementById('dateToDisplay').addEventListener('change', function() {
        syncHiddenFromDisplay('dateToDisplay', 'dateTo');
    });
});

// تابع باز و بسته کردن جزئیات فاکتور
function toggleInvoiceItems(header) {
    const items = header.nextElementSibling;
    const icon = header.querySelector('.toggle-icon');
    if (items.classList.contains('hidden')) {
        items.classList.remove('hidden');
        icon.style.transform = 'rotate(180deg)';
    } else {
        items.classList.add('hidden');
        icon.style.transform = 'rotate(0deg)';
    }
}
</script>
<script>
// ============================================================
// تابع تبدیل اعداد فارسی/عربی به انگلیسی
// ============================================================
function persianToEnglish(str) {
    if (!str) return '';
    var persian = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
    var arabic  = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
    for (var i = 0; i < 10; i++) {
        str = str.replace(new RegExp(persian[i], 'g'), i)
                 .replace(new RegExp(arabic[i], 'g'), i);
    }
    return str;
}

// ============================================================
// تابع تبدیل تاریخ شمسی (YYYY/MM/DD) به میلادی (YYYY-MM-DD)
// الگوریتم استاندارد و دقیق تبدیل جلالی به میلادی
// ============================================================
function jalaliToGregorian(jy, jm, jd) {
    jy = parseInt(jy, 10);
    jm = parseInt(jm, 10);
    jd = parseInt(jd, 10);

    var gy, gm, gd;
    var days;

    jy += 1595;
    days = -355668 + (365 * jy) + (~~(jy / 33) * 8) + ~~(((jy % 33) + 3) / 4) + jd;
    if (jm < 7) {
        days += (jm - 1) * 31;
    } else {
        days += ((jm - 7) * 30) + 186;
    }

    gy = 400 * ~~(days / 146097);
    days %= 146097;
    if (days > 36524) {
        gy += 100 * ~~(--days / 36524);
        days %= 36524;
        if (days >= 365) days++;
    }
    gy += 4 * ~~(days / 1461);
    days %= 1461;
    if (days > 365) {
        gy += ~~((days - 1) / 365);
        days = (days - 1) % 365;
    }
    gd = days + 1;

    var sal_a = [0, 31, ((gy % 4 === 0 && gy % 100 !== 0) || (gy % 400 === 0)) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    for (gm = 1; gm <= 12 && gd > sal_a[gm]; gm++) {
        gd -= sal_a[gm];
    }

    return gy + '-' + (gm < 10 ? '0' + gm : gm) + '-' + (gd < 10 ? '0' + gd : gd);
}

// ============================================================
// تابع اصلی: تبدیل رشته تاریخ شمسی ورودی به فرمت میلادی دیتابیس
// ============================================================
function jalaliStrToGregInput(jalaliStr) {
    if (!jalaliStr) return '';
    // تبدیل اعداد فارسی/عربی به انگلیسی
    var clean = persianToEnglish(String(jalaliStr).trim());
    // جدا کردن اجزای تاریخ با / یا - یا فاصله
    var parts = clean.split(/[\/\-\s]/);
    if (parts.length < 3) return '';
    var jy = parseInt(parts[0], 10);
    var jm = parseInt(parts[1], 10);
    var jd = parseInt(parts[2], 10);
    if (isNaN(jy) || isNaN(jm) || isNaN(jd)) return '';
    // سال باید 4 رقمی باشد
    if (jy < 100) jy += 1300;
    return jalaliToGregorian(jy, jm, jd);
}

// ============================================================
// همگام‌سازی فیلد مخفی میلادی با فیلد نمایشی شمسی
// ============================================================
function syncHiddenFromDisplay(displayId, hiddenId) {
    var jalaliVal = document.getElementById(displayId).value || '';
    jalaliVal = String(jalaliVal).trim();
    if (!jalaliVal) {
        document.getElementById(hiddenId).value = '';
        return;
    }
    var greg = jalaliStrToGregInput(jalaliVal);
    if (greg) {
        document.getElementById(hiddenId).value = greg;
    }
}

// ============================================================
// اجرای اولیه هنگام بارگذاری صفحه
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    // راه‌اندازی DatePicker شمسی
    if (window.kamaDatepicker) {
        var dpOptions = {
            placeholder: '',
            twodigit: true,
            closeAfterSelect: true,
            nextButtonIcon: 'بعدی',
            previousButtonIcon: 'قبلی',
            forceFarsiDigits: true,
            markToday: true,
            highlightSelectedDay: true,
            sync: true,
            gotoToday: true
        };
        window.kamaDatepicker('dateFromDisplay', dpOptions);
        window.kamaDatepicker('dateToDisplay', dpOptions);
    }

    // همگام‌سازی اولیه هنگام بارگذاری صفحه (اگر مقدار از قبل وجود دارد)
    syncHiddenFromDisplay('dateFromDisplay', 'dateFrom');
    syncHiddenFromDisplay('dateToDisplay', 'dateTo');

    // همگام‌سازی هنگام تغییر تاریخ
    document.getElementById('dateFromDisplay').addEventListener('change', function() {
        syncHiddenFromDisplay('dateFromDisplay', 'dateFrom');
    });
    document.getElementById('dateToDisplay').addEventListener('change', function() {
        syncHiddenFromDisplay('dateToDisplay', 'dateTo');
    });

    // جلوگیری از ارسال فرم با Enter در فیلدهای تاریخ (برای اطمینان از sync شدن)
    document.getElementById('dateFromDisplay').addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            syncHiddenFromDisplay('dateFromDisplay', 'dateFrom');
        }
    });
    document.getElementById('dateToDisplay').addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            syncHiddenFromDisplay('dateToDisplay', 'dateTo');
        }
    });

    // قبل از submit فرم، یک بار دیگر sync انجام شود (برای اطمینان نهایی)
    var filterForm = document.querySelector('form.filter-panel');
    if (filterForm) {
        filterForm.addEventListener('submit', function(e) {
            syncHiddenFromDisplay('dateFromDisplay', 'dateFrom');
            syncHiddenFromDisplay('dateToDisplay', 'dateTo');
        });
    }
});

// تابع باز و بسته کردن جزئیات فاکتور
function toggleInvoiceItems(header) {
    var items = header.nextElementSibling;
    var icon = header.querySelector('.toggle-icon');
    if (items.classList.contains('hidden')) {
        items.classList.remove('hidden');
        icon.style.transform = 'rotate(180deg)';
    } else {
        items.classList.add('hidden');
        icon.style.transform = 'rotate(0deg)';
    }
}
</script>