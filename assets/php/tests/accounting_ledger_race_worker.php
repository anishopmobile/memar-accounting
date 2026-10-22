<?php
/**
 * Isolated CI only: try to allocate 80 out of 100 concurrently.
 * Exit 0 on success, 3 on expected over-allocation validation.
 */
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('CI')!=='true'||count($argv)!==4)exit(2);
$kind=$argv[1];$partyId=(int)$argv[2];$invoiceId=(int)$argv[3];
require_once dirname(__DIR__).'/db.php';
require_once dirname(__DIR__).'/ledger/ledger_service.php';
try {
    ledger_record_payment($mysqli,[
        'party_kind'=>$kind,'party_id'=>$partyId,'amount'=>'80.00','method'=>'cash',
        'paid_on'=>date('Y-m-d'),'reference_no'=>'CONCURRENT',
        'note'=>'Parallel transaction regression',
        'request_token'=>bin2hex(random_bytes(24)),
        'allocations'=>[['invoice_id'=>$invoiceId,'amount'=>'80.00']]
    ]);
    echo "SUCCESS\n";exit(0);
} catch(DomainException $e) {
    echo "REJECTED: ".$e->getMessage()."\n";exit(3);
} catch(Throwable $e){
    fwrite(STDERR,$e->getMessage()."\n");exit(2);
}
