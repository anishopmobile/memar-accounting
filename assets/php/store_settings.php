<?php
require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/safe_store_image.php';

header('Content-Type: application/json; charset=UTF-8');

function respond($success, $message = '', $data = null)
{
    $payload = ['success' => $success];
    if ($message !== '') {
        $payload['message'] = $message;
    }
    if ($data !== null) {
        $payload['data'] = $data;
    }
    echo json_encode($payload);
    exit;
}

$currentSettings = null;
$settingsResult = $mysqli->query('SELECT * FROM store_settings WHERE id = 1 LIMIT 1');
if ($settingsResult) {
    $currentSettings = $settingsResult->fetch_assoc();
    $settingsResult->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    respond(true, '', ['settings' => $currentSettings ?: []]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') respond(false, 'روش درخواست معتبر نیست.');
require_csrf_or_fail();

$store_name = trim($_POST['store_name'] ?? '');
$economic_code = trim($_POST['economic_code'] ?? '');
$national_code = trim($_POST['national_code'] ?? '');
$registration_number = trim($_POST['registration_number'] ?? '');
$province_id = trim($_POST['province_id'] ?? '');
$city_id = trim($_POST['city_id'] ?? '');
$postal_code = trim($_POST['postal_code'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$address = trim($_POST['address'] ?? '');
$default_size_percentage = trim($_POST['default_size_percentage'] ?? '');

if ($store_name === '') {
    respond(false, 'نام فروشگاه / شخص الزامی است.');
}

if ($province_id !== '' && !ctype_digit($province_id)) {
    respond(false, 'استان انتخاب شده معتبر نیست.');
}
if ($city_id !== '' && !ctype_digit($city_id)) {
    respond(false, 'شهر انتخاب شده معتبر نیست.');
}
if ($default_size_percentage !== '' && !ctype_digit($default_size_percentage)) {
    respond(false, 'اندازه پیش‌فرض باید یک عدد صحیح باشد.');
}

$province_id = $province_id === '' ? null : (int)$province_id;
$city_id = $city_id === '' ? null : (int)$city_id;
$default_size_percentage = $default_size_percentage === '' ? null : (int)$default_size_percentage;

$newStoreImageFiles = [];
function handleUpload(string $fieldName, ?string $existingPath): ?string
{
    global $newStoreImageFiles;
    if (!isset($_FILES[$fieldName])
        || (int)($_FILES[$fieldName]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return $existingPath;
    }
    $file = store_image_save($_FILES[$fieldName], $fieldName, 5 * 1024 * 1024);
    $newStoreImageFiles[] = $file['absolute'];
    return $file['path'];
}

$logo_path = $currentSettings['logo_path'] ?? null;
$signature_path = $currentSettings['signature_path'] ?? null;
$stamp_path = $currentSettings['stamp_path'] ?? null;

try {
    $logo_path = handleUpload('logo_image', $logo_path);
    $signature_path = handleUpload('signature_image', $signature_path);
    $stamp_path = handleUpload('stamp_image', $stamp_path);
} catch (Throwable $e) {
    foreach ($newStoreImageFiles as $created) @unlink($created);
    if (!($e instanceof InvalidArgumentException)) error_log('store image upload: ' . $e->getMessage());
    respond(false, $e instanceof InvalidArgumentException
        ? $e->getMessage() : 'ذخیره تصویر فروشگاه امکان‌پذیر نیست.');
}

if ($currentSettings) {
    $stmt = $mysqli->prepare('UPDATE store_settings SET store_name = ?, economic_code = ?, national_code = ?, registration_number = ?, province_id = ?, city_id = ?, postal_code = ?, phone = ?, address = ?, logo_path = ?, signature_path = ?, stamp_path = ?, default_size_percentage = ? WHERE id = 1');
    $stmt->bind_param(
        'ssssiiisssssi',
        $store_name,
        $economic_code,
        $national_code,
        $registration_number,
        $province_id,
        $city_id,
        $postal_code,
        $phone,
        $address,
        $logo_path,
        $signature_path,
        $stamp_path,
        $default_size_percentage
    );
    if ($stmt->execute()) {
        $stmt->close();
        respond(true, 'اطلاعات فروشگاه با موفقیت بروزرسانی شد.', [
            'store_name' => $store_name,
            'economic_code' => $economic_code,
            'national_code' => $national_code,
            'registration_number' => $registration_number,
            'province_id' => $province_id,
            'city_id' => $city_id,
            'postal_code' => $postal_code,
            'phone' => $phone,
            'address' => $address,
            'logo_path' => $logo_path,
            'signature_path' => $signature_path,
            'stamp_path' => $stamp_path,
            'default_size_percentage' => $default_size_percentage,
        ]);
    }
    respond(false, 'خطا در بروزرسانی اطلاعات فروشگاه.');
}

$stmt = $mysqli->prepare('INSERT INTO store_settings (id, store_name, economic_code, national_code, registration_number, province_id, city_id, postal_code, phone, address, logo_path, signature_path, stamp_path, default_size_percentage) VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
$stmt->bind_param(
    'sssssissssssi',
    $store_name,
    $economic_code,
    $national_code,
    $registration_number,
    $province_id,
    $city_id,
    $postal_code,
    $phone,
    $address,
    $logo_path,
    $signature_path,
    $stamp_path,
    $default_size_percentage
);
if ($stmt->execute()) {
    $stmt->close();
    respond(true, 'اطلاعات فروشگاه با موفقیت ذخیره شد.', [
        'store_name' => $store_name,
        'economic_code' => $economic_code,
        'national_code' => $national_code,
        'registration_number' => $registration_number,
        'province_id' => $province_id,
        'city_id' => $city_id,
        'postal_code' => $postal_code,
        'phone' => $phone,
        'address' => $address,
        'logo_path' => $logo_path,
        'signature_path' => $signature_path,
        'stamp_path' => $stamp_path,
        'default_size_percentage' => $default_size_percentage,
    ]);
}

respond(false, 'خطا در ذخیره‌سازی اطلاعات فروشگاه.');
