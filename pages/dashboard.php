<?php
require_once __DIR__ . '/../assets/php/db.php';
require_once __DIR__ . '/../assets/php/jdf.php';

/*
 * ناحیه زمانی و مرزهای «امروز / ماه جاری» بر اساس تقویم شمسی برنامه.
 *
 * تاریخ فاکتورها به‌صورت میلادی در ستون DATE ذخیره می‌شود اما رابط کاربری
 * (هدر داشبورد، انتخاب‌گر تاریخ، لیست فاکتورها و PDFها) شمسی است؛ بنابراین
 * «فروش امروز» و «فروش ماهانه» نیز باید بر مبنای تاریخ شمسی جاری محاسبه شود،
 * نه مرزهای ماه میلادی (مثلاً کسر یک‌روزه ابتدای هر ماه میلادی نباید فروش
 * ماهانه را صفر کند).
 */
date_default_timezone_set('Asia/Tehran');

// امروز در ناحیه زمانی PHP (همان ناحیه‌ای که index.php برای برنامه تنظیم می‌کند)
$todayGreg = date('Y-m-d');

// سال/ماه/روز شمسی جاری
list($currentJy, $currentJm, $currentJd) = gregorian_to_jalali(
    (int) date('Y'),
    (int) date('m'),
    (int) date('d')
);

// ابتدای ماه شمسی جاری به میلادی (مثل ۱ مهر → 2026-09-23)
$monthStartGreg = implode('-', jalali_to_gregorian($currentJy, $currentJm, 1));

// ابتدای ماه شمسی بعد و انتهای ماه شمسی جاری (دیروزِ اولِ ماه بعد)
$nextJy = $currentJm === 12 ? $currentJy + 1 : $currentJy;
$nextJm = $currentJm === 12 ? 1 : $currentJm + 1;
$monthEndGreg = date('Y-m-d',
    strtotime(implode('-', jalali_to_gregorian($nextJy, $nextJm, 1)) . ' -1 day'));

// ۱. شمارش تعداد مشتریان
$customersCount = 0;
$result = $mysqli->query('SELECT COUNT(*) AS total FROM customers');
if ($result) {
    $row = $result->fetch_assoc();
    $customersCount = (int) $row['total'];
    $result->free();
}

// ۲. شمارش تعداد کالاها
$productsCount = 0;
$result = $mysqli->query('SELECT COUNT(*) AS total FROM products');
if ($result) {
    $row = $result->fetch_assoc();
    $productsCount = (int) $row['total'];
    $result->free();
}
/*
 * آخرین ۵ فاکتور فروش قطعی
 *
 * sales_invoice = فاکتور فروش قطعی
 * sales_proforma = پیش فاکتور و عمداً در این لیست قرار نمی‌گیرد
 */
$latestSalesInvoices = [];

$sql = "
    SELECT
        i.id,
        i.invoice_number,
        i.invoice_date,
        i.payment_status,
        i.payable_amount,
        c.id AS customer_id,
        CONCAT(c.first_name, ' ', c.last_name) AS customer_name
    FROM invoices AS i
    INNER JOIN customers AS c ON c.id = i.customer_id
    WHERE i.type = 'sales_invoice'
    ORDER BY i.id DESC
    LIMIT 5
";

$result = $mysqli->query($sql);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $latestSalesInvoices[] = $row;
    }

    $result->free();
}

// ۳. محاسبه فروش امروز (فقط فاکتورهای فروش قطعی در تاریخ شمسی امروز)
$todaySales = 0;
$stmt = $mysqli->prepare(
    "SELECT COALESCE(SUM(payable_amount), 0) AS total
     FROM invoices
     WHERE type = 'sales_invoice'
       AND invoice_date = ?"
);
if ($stmt) {
    $stmt->bind_param('s', $todayGreg);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $todaySales = (float) ($row['total'] ?? 0);
    $stmt->close();
}

// ۴. محاسبه فروش ماهانه (فقط فاکتورهای فروش قطعی در ماه شمسی جاری)
$monthlySales = 0;
$stmt = $mysqli->prepare(
    "SELECT COALESCE(SUM(payable_amount), 0) AS total
     FROM invoices
     WHERE type = 'sales_invoice'
       AND invoice_date BETWEEN ? AND ?"
);
if ($stmt) {
    $stmt->bind_param('ss', $monthStartGreg, $monthEndGreg);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $monthlySales = (float) ($row['total'] ?? 0);
    $stmt->close();
}

// ۵. محاسبه مبلغ پرداخت نشده فروش امروز
// منطق «بدهی» مطابق بقیه برنامه: payment_status در حالت unpaid|partial
// کل مبلغ قابل پرداخت به‌عنوان مانده شمرده می‌شود (مثل گزارش بدهی مشتریان).
$todayUnpaid = 0;
$stmt = $mysqli->prepare(
    "SELECT COALESCE(
         SUM(
             CASE
                 WHEN payment_status IN ('unpaid', 'partial')
                 THEN payable_amount
                 ELSE 0
             END
         ),
         0
     ) AS total
     FROM invoices
     WHERE type = 'sales_invoice'
       AND invoice_date = ?"
);
if ($stmt) {
    $stmt->bind_param('s', $todayGreg);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $todayUnpaid = (float) ($row['total'] ?? 0);
    $stmt->close();
}

// ۶. محاسبه مبلغ پرداخت نشده فروش ماه جاری
$monthlyUnpaid = 0;
$stmt = $mysqli->prepare(
    "SELECT COALESCE(
         SUM(
             CASE
                 WHEN payment_status IN ('unpaid', 'partial')
                 THEN payable_amount
                 ELSE 0
             END
         ),
         0
     ) AS total
     FROM invoices
     WHERE type = 'sales_invoice'
       AND invoice_date BETWEEN ? AND ?"
);
if ($stmt) {
    $stmt->bind_param('ss', $monthStartGreg, $monthEndGreg);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $monthlyUnpaid = (float) ($row['total'] ?? 0);
    $stmt->close();
}
// ═══════════════════════════════════════════════════════════════════
//  بخش جدید: محاسبه داده‌های نمودار فروش ماهانه (۳۰ روز گذشته)
//  فقط فاکتورهای فروش قطعی (sales_invoice)
// ═══════════════════════════════════════════════════════════════════
$salesByDay   = [];
$chartLabels  = [];
$chartData    = [];
$hasSalesData = false;

// ساخت آرایه ۳۰ روز گذشته (شامل امروز) با مقدار اولیه صفر
for ($i = 29; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-{$i} days"));
    $salesByDay[$date] = '0.00';
}

// کوئری: فقط sales_invoice و فقط ۳۰ روز اخیر
$sql = "SELECT invoice_date, SUM(payable_amount) AS total 
        FROM invoices 
        WHERE type = 'sales_invoice' 
          AND invoice_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY invoice_date 
        ORDER BY invoice_date ASC";
$result = $mysqli->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        if (isset($salesByDay[$row['invoice_date']])) {
            $salesByDay[$row['invoice_date']] = $row['total'];
        }
    }
    $result->free();
}

// تبدیل تاریخ‌های میلادی به شمسی برای نمایش روی محور X
foreach ($salesByDay as $date => $amount) {
    $floatAmount = (float) $amount;
    if ($floatAmount > 0) {
        $hasSalesData = true;
    }
    list($y, $m, $d) = explode('-', $date);
    list($jy, $jm, $jd) = gregorian_to_jalali((int)$y, (int)$m, (int)$d);
    $chartLabels[] = convertToPersianNumber($jy) . '/' .
        convertToPersianNumber(str_pad($jm, 2, '0', STR_PAD_LEFT)) . '/' .
        convertToPersianNumber(str_pad($jd, 2, '0', STR_PAD_LEFT));
    $chartData[]   = $floatAmount;
}
// ═══════════════════════════════════════════════════════════════════
//  پایان بخش محاسبه نمودار
// ═══════════════════════════════════════════════════════════════════
?>

<!-- کارت‌های عملیات سریع -->
<div class="quick-actions">
    <div class="quick-action-card blue" onclick="window.location='?page=factors&action=new_sales'">
        <div class="quick-action-icon">
            <i class="fas fa-file-invoice-dollar"></i>
        </div>
        <div class="quick-action-text">
            <h3>ثبت فاکتور فروش</h3>
            <p>ایجاد فاکتور فروش جدید</p>
        </div>
    </div>
    <div class="quick-action-card orange" onclick="window.location='?page=goods-and-services&action=new_product'">
        <div class="quick-action-icon">
            <i class="fas fa-box-open"></i>
        </div>
        <div class="quick-action-text">
            <h3>ثبت کالا و خدمات</h3>
            <p>افزودن کالا یا خدمت جدید</p>
        </div>
    </div>
    <div class="quick-action-card purple" onclick="window.location='?page=product-categories&action=new_category'">
        <div class="quick-action-icon">
            <i class="fas fa-sitemap"></i>
        </div>
        <div class="quick-action-text">
            <h3>ثبت دسته‌بندی جدید</h3>
            <p>افزودن دسته‌بندی جدید</p>
        </div>
    </div>
    
    
    <div class="quick-action-card green" onclick="window.location='?page=purchase-invoice&action=new_purchase'">
        <div class="quick-action-icon">
            <i class="fas fa-cart-plus"></i>
        </div>
        <div class="quick-action-text">
            <h3>ثبت فاکتور خرید</h3>
            <p>ایجاد فاکتور خرید جدید</p>
        </div>
    </div>
    
</div>

<!-- کارت‌های متریک -->
<div class="metrics-grid">
    <div class="metric-card-new daily">
        <div class="metric-header">
            <h3>فروش امروز</h3>
            <div class="metric-icon">
                <i class="fas fa-calendar-day"></i>
            </div>
        </div>
        <div class="metric-value-large"><?php echo number_format($todaySales); ?> ریال</div>
        <?php if ($todayUnpaid > 0): ?>
        <div class="metric-unpaid-amount">
            مبلغ پرداخت نشده: <?php echo number_format($todayUnpaid); ?> ریال
        </div>
        <?php endif; ?>
    </div>
    <div class="metric-card-new monthly">
        <div class="metric-header">
            <h3>فروش ماهانه</h3>
            <div class="metric-icon">
                <i class="fas fa-chart-line"></i>
            </div>
        </div>
        <div class="metric-value-large"><?php echo number_format($monthlySales); ?> ریال</div>
        <?php if ($monthlyUnpaid > 0): ?>
        <div class="metric-unpaid-amount">
            مبلغ پرداخت نشده: <?php echo number_format($monthlyUnpaid); ?> ریال
        </div>
        <?php endif; ?>
    </div>
    <div class="metric-card-new products">
        <div class="metric-header">
            <h3>تعداد کالا</h3>
            <div class="metric-icon">
                <i class="fas fa-box"></i>
            </div>
        </div>
        <div class="metric-value-large"><?php echo number_format($productsCount); ?></div>
    </div>
    <div class="metric-card-new customers">
        <div class="metric-header">
            <h3>تعداد مشتریان</h3>
            <div class="metric-icon">
                <i class="fas fa-users"></i>
            </div>
        </div>
        <div class="metric-value-large"><?php echo number_format($customersCount); ?></div>
    </div>    
</div>


<div class="panel-row">
    <div class="card panel">
        <h2>نمودار فروش ماهانه</h2>
        <?php if ($hasSalesData): ?>
            <div style="position: relative; height: 380px; width: 100%; padding: 10px 4px;">
                <canvas id="monthlySalesChart"></canvas>
            </div>
            <!-- Chart.js از CDN -->
            <script src="assets/js/chart.umd.min.js"></script>
            <script>
                (function() {
                    const labels = <?php echo json_encode($chartLabels, JSON_UNESCAPED_UNICODE); ?>;
                    const data = <?php echo json_encode($chartData,   JSON_UNESCAPED_UNICODE); ?>;

                    // تبدیل اعداد انگلیسی به فارسی
                    function toPersianNum(num) {
                        const persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
                        return String(num).replace(/\d/g, d => persian[d]);
                    }

                    function formatMoney(n) {
                        return toPersianNum(Math.round(n).toLocaleString('en-US')) + ' ریال';
                    }

                    // فشرده‌سازی اعداد بزرگ برای محور Y
                    function formatCompact(n) {
                        if (n >= 1_000_000_000) return toPersianNum((n / 1_000_000_000).toFixed(1)) + ' میلیارد';
                        if (n >= 1_000_000) return toPersianNum((n / 1_000_000).toFixed(1)) + ' میلیون';
                        if (n >= 1_000) return toPersianNum((n / 1_000).toFixed(0)) + ' هزار';
                        return toPersianNum(n);
                    }

                    const ctx = document.getElementById('monthlySalesChart').getContext('2d');
                    new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: labels,
                            datasets: [{
                                label: 'مبلغ فروش',
                                data: data,
                                borderColor: '#1769e0',
                                backgroundColor: 'rgba(23, 105, 224, 0.12)',
                                borderWidth: 2.5,
                                fill: true,
                                tension: 0.35,
                                pointRadius: 4,
                                pointHoverRadius: 7,
                                pointBackgroundColor: '#1769e0',
                                pointBorderColor: '#ffffff',
                                pointBorderWidth: 2
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            interaction: {
                                intersect: false,
                                mode: 'index'
                            },
                            plugins: {
                                legend: {
                                    display: false
                                },
                                tooltip: {
                                    rtl: true,
                                    textDirection: 'rtl',
                                    backgroundColor: '#1f3256',
                                    padding: 12,
                                    cornerRadius: 8,
                                    titleFont: {
                                        family: 'Tahoma, Vazirmatn, sans-serif',
                                        size: 13,
                                        weight: '600'
                                    },
                                    bodyFont: {
                                        family: 'Tahoma, Vazirmatn, sans-serif',
                                        size: 12
                                    },
                                    callbacks: {
                                        title: function(items) {
                                            return 'تاریخ: ' + items[0].label;
                                        },
                                        label: function(context) {
                                            return 'فروش: ' + formatMoney(context.parsed.y);
                                        }
                                    }
                                }
                            },
                            scales: {
                                x: {
                                    ticks: {
                                        font: {
                                            size: 10,
                                            family: 'Tahoma, sans-serif'
                                        },
                                        maxRotation: 45,
                                        autoSkip: true,
                                        maxTicksLimit: 10,
                                        color: '#64748b'
                                    },
                                    grid: {
                                        display: false
                                    }
                                },
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        callback: function(value) {
                                            return formatCompact(value);
                                        },
                                        font: {
                                            size: 11,
                                            family: 'Tahoma, sans-serif'
                                        },
                                        color: '#64748b'
                                    },
                                    grid: {
                                        color: 'rgba(0,0,0,0.05)'
                                    }
                                }
                            }
                        }
                    });
                })();
            </script>
        <?php else: ?>
            <div class="empty-state" style="padding: 40px 20px; text-align: center; color: #94a3b8;">
                در ۳۰ روز گذشته فاکتور فروش قطعی ثبت نشده است.
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="table-panel">
    <div class="card panel">
        <h2>کالاهای کم‌موجودی</h2>

        <div class="table-wrapper">
            <table class="action-table dashboard-low-stock-table">
                <thead>
                    <tr>
                        <th>نام کالا</th>
                        <th>موجودی</th>
                    </tr>
                </thead>

                <tbody id="lowStockTableBody">
                    <tr>
                        <td colspan="2" class="empty-state">
                            در حال دریافت اطلاعات...
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div id="lowStockPagination"></div>
    </div>

<?php
// تعریف رنگ‌های پاستلی ملایم برای پس‌زمینه ردیف‌ها
$rowColors = [
    '#e3f2fd', // آبی خیلی روشن
    '#f3e5f5', // بنفش خیلی روشن
    '#e8f5e9', // سبز خیلی روشن
    '#fff3e0', // نارنجی خیلی روشن
    '#fce4ec', // صورتی خیلی روشن
    '#e0f7fa', // فیروزه‌ای خیلی روشن
    '#fff8e1', // زرد خیلی روشن
    '#f1f8e9', // سبز لیمویی خیلی روشن
];

// آرایه‌ای برای نگاشت customer_id به رنگ
$customerColorMap = [];
$colorIndex = 0;
?>

<div class="card panel">
    <h2>آخرین فاکتورها</h2>
    <div class="table-wrapper">
        <table class="action-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>مشتری</th>
                    <th>تاریخ</th>
                    <th>وضعیت پرداخت</th>
                    <th>مبلغ کل</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($latestSalesInvoices)): ?>
                    <tr>
                        <td colspan="5" class="empty-state">فاکتور فروش قطعی موجود نیست</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($latestSalesInvoices as $index => $invoice): ?>
                        <?php
                        $paymentStatusLabels = [
                            'paid'   => 'پرداخت شده',
                            'unpaid' => 'پرداخت نشده',
                            'partial' => 'پرداخت جزئی',
                        ];

                        $paymentStatus = $paymentStatusLabels[$invoice['payment_status']]
                            ?? $invoice['payment_status'];

                        $invoiceDate = '';
                        if (!empty($invoice['invoice_date'])) {
                            $invoiceDate = jdate('Y/m/d', strtotime($invoice['invoice_date']));
                        }

                        $customerName = trim($invoice['customer_name']);
                        
                        // تخصیص رنگ به مشتری (به‌جز مشتری متفرقه)
                        $rowBgColor = '';
                        if ($customerName !== 'مشتری متفرقه') {
                            $customerId = $invoice['customer_id'] ?? null;
                            if ($customerId && !isset($customerColorMap[$customerId])) {
                                $customerColorMap[$customerId] = $rowColors[$colorIndex % count($rowColors)];
                                $colorIndex++;
                            }
                            if ($customerId && isset($customerColorMap[$customerId])) {
                                $rowBgColor = $customerColorMap[$customerId];
                            }
                        }
                        
                        // تعیین کلاس وضعیت پرداخت
                        $statusClass = '';
                        if ($invoice['payment_status'] === 'unpaid') {
                            $statusClass = 'status-unpaid';
                        } elseif ($invoice['payment_status'] === 'paid') {
                            $statusClass = 'status-paid';
                        } elseif ($invoice['payment_status'] === 'partial') {
                            $statusClass = 'status-partial';
                        }
                        ?>
                        <tr style="<?php echo $rowBgColor ? 'background-color: ' . $rowBgColor . ';' : ''; ?>">
                            <td><?php echo $index + 1; ?></td>
                            <td><?php echo htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($invoiceDate, ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>
                                <span class="payment-status-badge <?php echo $statusClass; ?>">
                                    <?php echo htmlspecialchars($paymentStatus, ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </td>
                            <td><?php echo number_format((float) $invoice['payable_amount']); ?> ریال</td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</div>