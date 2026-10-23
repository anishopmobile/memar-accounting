<?php
/** Authenticated configuration for one nominated walk-in/public customer. */
declare(strict_types=1);
require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/db.php';

try {
    if (($_SERVER['REQUEST_METHOD']??'')==='GET') {
        $res=$mysqli->query("SELECT s.enabled,s.customer_id,
            TRIM(CONCAT(COALESCE(c.first_name,''),' ',COALESCE(c.last_name,''))) AS customer_name
            FROM accounting_auto_settlement_settings s
            LEFT JOIN customers c ON c.id=s.customer_id WHERE s.id=1");
        $cfg=$res?$res->fetch_assoc():null;
        if($res)$res->free();
        respond_json(true,'',[
            'enabled'=>(bool)($cfg['enabled']??false),
            'customer_id'=>isset($cfg['customer_id'])?(int)$cfg['customer_id']:null,
            'customer_name'=>(string)($cfg['customer_name']??'')
        ]);
    }
    if (($_SERVER['REQUEST_METHOD']??'')!=='POST') respond_error('روش نامعتبر است.',405);
    require_csrf_or_fail();
    $enabledRaw=(string)($_POST['enabled']??'');
    if(!in_array($enabledRaw,['0','1'],true))throw new InvalidArgumentException('وضعیت تسویه خودکار نامعتبر است.');
    $enabled=(int)$enabledRaw;
    $idRaw=(string)($_POST['customer_id']??'');
    $customerId=$idRaw===''?null:filter_var($idRaw,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if($customerId===false)throw new InvalidArgumentException('مشتری انتخاب‌شده نامعتبر است.');
    if($enabled===1 && !$customerId)throw new InvalidArgumentException('برای فعال‌سازی، مشتری عمومی را انتخاب کنید.');
    $mysqli->begin_transaction();
    try {
        $stmt=$mysqli->prepare('SELECT id FROM accounting_auto_settlement_settings WHERE id=1 FOR UPDATE');
        $stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();
        if(!$row)throw new RuntimeException('Auto settlement settings unavailable');
        if($customerId!==null) {
            $stmt=$mysqli->prepare('SELECT id FROM customers WHERE id=?');
            $stmt->bind_param('i',$customerId);$stmt->execute();
            $ok=$stmt->get_result()->fetch_assoc();$stmt->close();
            if(!$ok)throw new InvalidArgumentException('مشتری انتخاب‌شده یافت نشد.');
        }
        $actor=function_exists('auth_user_id')?auth_user_id():null;
        $stmt=$mysqli->prepare('UPDATE accounting_auto_settlement_settings SET enabled=?,customer_id=?,updated_by=? WHERE id=1');
        $stmt->bind_param('iii',$enabled,$customerId,$actor);$stmt->execute();$stmt->close();
        $mysqli->commit();
    }catch(Throwable $e){$mysqli->rollback();throw $e;}
    respond_json(true,'تنظیمات تسویه خودکار ذخیره شد.',['enabled'=>$enabled===1,'customer_id'=>$customerId]);
}catch(InvalidArgumentException $e){respond_error($e->getMessage(),422);}
catch(Throwable $e){error_log('accounting_auto_settlement_settings: '.$e->getMessage());respond_error('خطا در تنظیمات تسویه خودکار.',500);}
