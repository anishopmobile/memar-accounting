<?php
/**
 * product_excel_reader.php
 *
 * خواندن فایل‌های اکسل (xlsx / xls) بدون وابستگی خارجی (بدون Composer/PhpSpreadsheet)
 * تا با هر نسخه PHP سازگار باشد.
 *
 * فرمت‌های پشتیبانی‌شده:
 *   - XLSX (ZIP + XML) — با ZipArchive + SimpleXML
 *   - XLS اسکریپت Spreadsheet 2003 (XML) — فرمت خروجی خودِ پروژه
 *   - XLS به‌صورت HTML Table
 *   - XLS باینری (BIFF8 / OLE2)
 *   - CSV — فقط در صورت وجود جداکننده قابل تشخیص
 *
 * این فایل فاقد هرگونه فراخوانی boot/session/DB است و به‌صورت خالص قابل
 * تست در CLI می‌باشد. پیام‌های خطا فارسی و قابل نمایش در رابط کاربری هستند.
 *
 * سازگاری: PHP >= 7.0 (بدون توابع مخصوص PHP 8 مانند str_starts_with)
 */

if (!defined('PRODUCT_EXCEL_READER_LOADED')) {
    define('PRODUCT_EXCEL_READER_LOADED', true);
}

class ProductExcelReaderException extends Exception
{
}

class ProductExcelReader
{
    /** محدودیت سطر برای جلوگیری از پردازش فایل‌های غیرعادی */
    const MAX_OUTPUT_ROWS = 100000;

    /**
     * خواندن فایل و بازگرداندن ردیف‌ها.
     *
     * @param string $path مسیر فایل
     * @return array لیست ردیف‌ها:
     *              ['row'    => شماره سطر واقعی در اکسل (از ۱)
     *               'cells'  => [colIdx => value]]
     * @throws ProductExcelReaderException
     */
    public static function readFile($path)
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new ProductExcelReaderException('فایل انتخاب‌شده در دسترس نیست یا قابل خواندن نیست.');
        }
        if (filesize($path) <= 0) {
            throw new ProductExcelReaderException('فایل انتخاب‌شده خالی است.');
        }

        $fp = @fopen($path, 'rb');
        if ($fp === false) {
            throw new ProductExcelReaderException('امکان باز کردن فایل وجود ندارد.');
        }
        $head = fread($fp, 4096);
        fclose($fp);

        // شناسایی بر اساس محتوا (نه پسوند)
        if (substr($head, 0, 2) === 'PK') {
            return self::readXlsx($path); // ZIP => XLSX
        }

        $trim = $head;
        if (substr($trim, 0, 3) === "\xEF\xBB\xBF") {
            $trim = substr($trim, 3); // حذف BOM
        }

        if (strncasecmp(ltrim($trim), '<?xml', 5) === 0
            || strncasecmp(ltrim($trim), '<Workbook', 9) === 0) {
            return self::readXmlSpreadsheet($path);
        }

        if (strncasecmp(ltrim($trim), '<table', 6) === 0) {
            return self::readHtmlTable($path);
        }
        if (preg_match('#^\s*<!doctype html#i', $trim)
            || preg_match('#^\s*<html#i', $trim)) {
            return self::readHtmlTable($path);
        }

        if (substr($head, 0, 8) === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
            return self::readBiff($path); // OLE2 => XLS باینری
        }

        // CSV / متن با جداکننده
        $probe = explode("\n", str_replace("\r", "\n", $head), 4);
        $firstLine = trim($probe[0] ?? '');
        if ($firstLine !== '' && (strpos($firstLine, ',') !== false || strpos($firstLine, "\t") !== false)) {
            return self::readCsv($path);
        }

        throw new ProductExcelReaderException(
            'فرمت فایل پشتیبانی نمی‌شود. لطفاً فایل .xlsx یا .xls با ردیف اولِ هدر را انتخاب کنید.'
        );
    }
/* ============================================================
     * XLSX
     * ========================================================== */

    private static function readXlsx($path)
    {
        if (!class_exists('ZipArchive')) {
            throw new ProductExcelReaderException('پشتیبانی ZIP روی سرور فعال نیست و فایل XLSX قابل خواندن نیست.');
        }
        if (!class_exists('SimpleXMLElement')) {
            throw new ProductExcelReaderException('پشتیبانی XML روی سرور فعال نیست.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new ProductExcelReaderException('فایل XLSX باز نشد (فایل خراب یا محافظت‌شده است).');
        }

        // رشته‌های مشترک (SharedStrings)
        $sharedStrings = array();
        $sst = $zip->getFromName('xl/sharedStrings.xml');
        if ($sst !== false) {
            $sstXml = @simplexml_load_string($sst);
            if ($sstXml !== false) {
                foreach ($sstXml->si as $si) {
                    $text = '';
                    foreach ($si->t as $tt) {
                        $text .= (string) $tt;
                    }
                    if ($text === '' && isset($si->r)) {
                        foreach ($si->r as $rNode) {
                            if (isset($rNode->t)) {
                                $text .= (string) $rNode->t;
                            }
                        }
                    }
                    $sharedStrings[] = self::cleanValue($text);
                }
            }
        }

        // پیدا کردن اولین Worksheet
        $sheetPath = 'xl/worksheets/sheet1.xml';
        $wbXml = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($wbXml !== false && $rels !== false) {
            $wb = @simplexml_load_string($wbXml);
            $relsXml = @simplexml_load_string($rels);
            if ($wb !== false && $relsXml !== false && isset($wb->sheets->sheet[0])) {
                $rid = (string) $wb->sheets->sheet[0]['r:id'];
                foreach ($relsXml->Relationship as $rel) {
                    if ((string) $rel['Id'] === $rid) {
                        $target = (string) $rel['Target'];
                        if (strpos($target, '/') !== 0) {
                            $target = 'xl/' . $target;
                        }
                        $sheetPath = ltrim($target, '/');
                        break;
                    }
                }
            }
        }

        $sheetXml = $zip->getFromName($sheetPath);
        if ($sheetXml === false) {
            $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        }
        $zip->close();

        if ($sheetXml === false) {
            throw new ProductExcelReaderException('ساختار فایل XLSX معتبر نیست (worksheet یافت نشد).');
        }

        return self::parseXlsxSheet($sheetXml, $sharedStrings);
    }
private static function parseXlsxSheet($sheetXml, array $sharedStrings)
    {
        $xml = @simplexml_load_string($sheetXml);
        if ($xml === false || !isset($xml->sheetData)) {
            throw new ProductExcelReaderException('ساختار فایل XLSX معتبر نیست (sheetData یافت نشد).');
        }

        $rows = array();
        $statePos = 0; // شاخص صفردپایه ردیف اکسل
        foreach ($xml->sheetData->row as $rowEl) {
            if (count($rows) >= self::MAX_OUTPUT_ROWS) {
                throw new ProductExcelReaderException('تعداد ردیف‌های فایل بیش از حد مجاز است.');
            }
            $attrR = (string) $rowEl['r'];
            $target = null;
            if ($attrR !== '') {
                $t = (int) $attrR - 1;
                if ($t >= $statePos) {
                    $target = $t;
                }
            }
            if ($target === null) {
                $target = $statePos;
            }
            $statePos = $target;

            $cells = array();
            $lastCol = -1;
            foreach ($rowEl->c as $cEl) {
                $ref = (string) $cEl['r'];
                $colIdx = -1;
                if ($ref !== '') {
                    $colIdx = self::cellRefToCols($ref);
                }
                if ($colIdx < 0) {
                    $colIdx = $lastCol + 1;
                }
                $lastCol = $colIdx;
                $cells[$colIdx] = self::cellValue($cEl, $sharedStrings);
            }

            if (count($cells) === 0) {
                $statePos++;
                continue;
            }
            $rows[] = array('row' => $target + 1, 'cells' => $cells);
            $statePos++;
        }

        if (count($rows) === 0) {
            throw new ProductExcelReaderException('فایل XLSX هیچ ردیف داده‌ای ندارد.');
        }
        return $rows;
    }

    /**
     * استخراج مقدار سلول XLSX
     */
    private static function cellValue($cEl, array $sharedStrings)
    {
        $t = (string) $cEl['t'];
        $v = isset($cEl->v) ? (string) $cEl->v : '';

        switch ($t) {
            case 's':
                $idx = (int) $v;
                return isset($sharedStrings[$idx]) ? $sharedStrings[$idx] : '';
            case 'inlineStr':
                $text = '';
                if (isset($cEl->is)) {
                    foreach ($cEl->is->t as $tt) {
                        $text .= (string) $tt;
                    }
                }
                return self::cleanValue($text);
            case 'b':
                return ($v === '1' || $v === 'true') ? '1' : '0';
            case 'str':
            case 'e':
                return self::cleanValue($v);
            default: // عددی
                return ($v === '') ? '' : self::cleanValue($v);
        }
    }

    /**
     * تبدیل ارجاع سلول (مثل AB12) به ایندکس صفرپایه ستون
     */
    private static function cellRefToCols($ref)
    {
        $col = 0;
        $len = strlen($ref);
        for ($i = 0; $i < $len; $i++) {
            $ch = ord($ref[$i]);
            if (($ch >= 65 && $ch <= 90) || ($ch >= 97 && $ch <= 122)) {
                $n = ($ch >= 97) ? $ch - 97 + 1 : $ch - 65 + 1;
                $col = $col * 26 + $n;
                continue;
            }
            break;
        }
        return $col > 0 ? $col - 1 : 0;
    }
/* ============================================================
     * XLS — XML Spreadsheet 2003 (فرمت خروجی Export پروژه)
     * ========================================================== */

    private static function readXmlSpreadsheet($path)
    {
        $sheets = self::parseXmlSheets($path);
        // سازگاری با قبل: فقط اولین برگه
        $first = null;
        foreach ($sheets as $name => $rows) {
            $first = $rows;
            break;
        }
        if ($first === null || count($first) === 0) {
            throw new ProductExcelReaderException('فایل اکسلِ XML هیچ ردیف داده‌ای ندارد یا ساختار آن معتبر نیست.');
        }
        return $first;
    }

    /**
     * خواندن همه برگه‌های یک فایل XML Spreadsheet 2003.
     *
     * @return array<string, array> map: نام برگه => ردیف‌ها
     */
    private static function parseXmlSheets($path)
    {
        if (!class_exists('SimpleXMLElement')) {
            throw new ProductExcelReaderException('پشتیبانی XML روی سرور فعال نیست.');
        }
        $content = self::readAllText($path);
        $content = self::stripBom($content);
        $xml = @simplexml_load_string($content);
        if ($xml === false) {
            throw new ProductExcelReaderException('فایل XML خوانده نشد؛ ساختار Spreadsheet معتبر نیست.');
        }

        $ssNs = 'urn:schemas-microsoft-com:office:spreadsheet';
        $sheets = array();
        $globalRowNo = 0;

        foreach ($xml->children($ssNs)->Worksheet as $ws) {
            $nameAttr = $ws->attributes($ssNs);
            $sheetName = isset($nameAttr['Name']) ? (string) $nameAttr['Name'] : 'Sheet' . (count($sheets) + 1);
            $rows = array();
            $rowNo = 0;
            $table = $ws->children($ssNs)->Table;
            if ($table === null) {
                continue;
            }
            foreach ($table->children($ssNs)->Row as $rowEl) {
                $rowNo++;
                $globalRowNo++;
                if (count($rows) >= self::MAX_OUTPUT_ROWS) {
                    throw new ProductExcelReaderException('تعداد ردیف‌های فایل بیش از حد مجاز است.');
                }
                $cells = array();
                $col = 0;
                foreach ($rowEl->children($ssNs)->Cell as $cellEl) {
                    $attrs = $cellEl->attributes($ssNs);
                    $idxAttr = isset($attrs['Index']) ? (int) $attrs['Index'] : 0;
                    $idx = $idxAttr > 0 ? $idxAttr - 1 : $col;
                    $data = $cellEl->children($ssNs)->Data;
                    $cellVal = ($data !== null) ? (string) $data : '';
                    $cells[$idx] = self::cleanValue($cellVal);
                    $isMerged = isset($attrs['MergeAcross']) && (int) $attrs['MergeAcross'] > 0;
                    if ($isMerged) {
                        $skip = (int) $attrs['MergeAcross'];
                        for ($j = 1; $j <= $skip; $j++) {
                            $cells[$idx + $j] = '';
                        }
                        $col = $idx + $skip + 1;
                    } else {
                        $col = $idx + 1;
                    }
                }
                $has = false;
                foreach ($cells as $v) {
                    if ((string) $v !== '') {
                        $has = true;
                        break;
                    }
                }
                if (!$has) {
                    continue;
                }
                $rows[] = array('row' => $rowNo, 'cells' => $cells);
            }
            if (count($rows) > 0) {
                $sheets[$sheetName] = $rows;
            }
        }

        if (count($sheets) === 0) {
            throw new ProductExcelReaderException('فایل اکسلِ XML هیچ برگهٔ داده‌ای ندارد یا ساختار آن معتبر نیست.');
        }
        return $sheets;
    }

    /**
     * خواندن فایل با تمام برگه‌ها.
     *
     * برای فرمت XML مجموعه برگه‌ها برمی‌گرداند؛ برای سایر فرمت‌ها (xlsx/biff/html/csv)
     * یک برگه با نام 'Sheet1' بازگشته می‌شود.
     *
     * @return array<string, array> map: نام برگه => ردیف‌ها
     */
    public static function readFileSheets($path)
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new ProductExcelReaderException('فایل انتخاب‌شده در دسترس نیست یا قابل خواندن نیست.');
        }
        if (filesize($path) <= 0) {
            throw new ProductExcelReaderException('فایل انتخاب‌شده خالی است.');
        }
        $fp = @fopen($path, 'rb');
        if ($fp === false) {
            throw new ProductExcelReaderException('امکان باز کردن فایل وجود ندارد.');
        }
        $head = fread($fp, 4096);
        fclose($fp);

        if (substr($head, 0, 2) === 'PK') {
            return array('Sheet1' => self::readXlsx($path));
        }
        $trim = $head;
        if (substr($trim, 0, 3) === "\xEF\xBB\xBF") {
            $trim = substr($trim, 3);
        }
        if (strncasecmp(ltrim($trim), '<?xml', 5) === 0
            || strncasecmp(ltrim($trim), '<Workbook', 9) === 0) {
            return self::parseXmlSheets($path);
        }
        if (strncasecmp(ltrim($trim), '<table', 6) === 0) {
            return array('Sheet1' => self::readHtmlTable($path));
        }
        if (preg_match('#^\s*<!doctype html#i', $trim)
            || preg_match('#^\s*<html#i', $trim)) {
            return array('Sheet1' => self::readHtmlTable($path));
        }
        if (substr($head, 0, 8) === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
            return array('Sheet1' => self::readBiff($path));
        }
        $probe = explode("\n", str_replace("\r", "\n", $head), 4);
        $firstLine = trim($probe[0] ?? '');
        if ($firstLine !== '' && (strpos($firstLine, ',') !== false || strpos($firstLine, "\t") !== false)) {
            return array('Sheet1' => self::readCsv($path));
        }
        throw new ProductExcelReaderException(
            'فرمت فایل پشتیبانی نمی‌شود. لطفاً فایل .xlsx یا .xls با ردیف اولِ هدر را انتخاب کنید.'
        );
    }

    /* ============================================================
     * XLS — HTML Table
     * ========================================================== */

    private static function readHtmlTable($path)
    {
        if (!class_exists('DOMDocument')) {
            throw new ProductExcelReaderException('پشتیبانی HTML روی سرور فعال نیست.');
        }
        $content = self::readAllText($path);
        $content = self::stripBom($content);

        if (function_exists('mb_detect_encoding')) {
            $enc = false;
            try {
                $enc = @mb_detect_encoding($content, array('UTF-8', 'Windows-1252'), true);
            } catch (Throwable $e) {
                $enc = false;
            }
            if ($enc && $enc !== 'UTF-8' && function_exists('mb_convert_encoding')) {
                try {
                    $content = mb_convert_encoding($content, 'UTF-8', $enc);
                } catch (Throwable $e) {
                    // ادامه با محتوای اصلی
                }
            }
        }

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $loaded = @$dom->loadHTML($content);
        libxml_clear_errors();
        if (!$loaded) {
            throw new ProductExcelReaderException('فایل HTML / جدول اکسل خوانده نشد.');
        }

        $tables = $dom->getElementsByTagName('table');
        if ($tables->length === 0) {
            throw new ProductExcelReaderException('فایل HTML جدولی برای خواندن ندارد.');
        }

        $rows = array();
        $rowNo = 0;
        foreach ($tables as $table) {
            $trs = $table->getElementsByTagName('tr');
            foreach ($trs as $tr) {
                $rowNo++;
                if (count($rows) >= self::MAX_OUTPUT_ROWS) {
                    throw new ProductExcelReaderException('تعداد ردیف‌های فایل بیش از حد مجاز است.');
                }
                $cellsEls = $tr->getElementsByTagName('td');
                $cellCount = $cellsEls->length;
                if ($cellCount === 0) {
                    $cellsEls = $tr->getElementsByTagName('th');
                    $cellCount = $cellsEls->length;
                }
                if ($cellCount === 0) {
                    continue;
                }
                $cells = array();
                for ($i = 0; $i < $cellCount; $i++) {
                    $cells[$i] = self::cleanValue(trim($cellsEls->item($i)->textContent));
                }
                $rows[] = array('row' => $rowNo, 'cells' => $cells);
            }
            break; // فقط اولین table
        }

        if (count($rows) === 0) {
            throw new ProductExcelReaderException('فایل HTML هیچ ردیف جدولی ندارد.');
        }
        return $rows;
    }
/* ============================================================
     * CSV
     * ========================================================== */

    private static function readCsv($path)
    {
        $content = self::readAllText($path);
        $content = self::stripBom($content);
        $lines = str_replace(array("\r\n", "\r"), "\n", $content);
        $rows = array();
        $rowNo = 0;
        $delim = ',';
        if (strpos($lines, "\t") !== false && strpos($lines, ',') === false) {
            $delim = "\t";
        }
        foreach (explode("\n", $lines) as $line) {
            $rowNo++;
            if (count($rows) >= self::MAX_OUTPUT_ROWS) {
                throw new ProductExcelReaderException('تعداد ردیف‌های فایل بیش از حد مجاز است.');
            }
            if ($line === '') {
                continue;
            }
            $rows[] = array('row' => $rowNo, 'cells' => str_getcsv($line, $delim));
        }
        if (count($rows) === 0) {
            throw new ProductExcelReaderException('فایل CSV خالی است.');
        }
        return $rows;
    }

    /* ============================================================
     * XLS — BIFF8 / OLE2 (باینری)
     * ========================================================== */

    private static function readBiff($path)
    {
        $data = self::readAllText($path);
        $dataLen = strlen($data);
        if ($dataLen < 512) {
            throw new ProductExcelReaderException('فایل باینری اکسل معتبر نیست.');
        }

        $sectorShift = self::u16le($data, 30);
        $miniShift = self::u16le($data, 32);
        $sectorSize = 1 << $sectorShift;
        $miniSectorSize = 1 << $miniShift;
        if ($sectorShift < 7 || $sectorShift > 14 || $miniShift < 4 || $miniShift > 7) {
            throw new ProductExcelReaderException('ساختار فایل XLS باینری شناسایی نشد.');
        }
        $firstDirSector = self::u32le($data, 48);
        $miniCutoff = self::u32le($data, 56);
        if ($miniCutoff <= 0) {
            $miniCutoff = 4096;
        }
        $firstMiniFatSector = self::u32le($data, 60);
        $numMiniFatSectors = self::u32le($data, 64);
        $firstDifatSector = self::u32le($data, 68);
        $numDifatSectors = self::u32le($data, 72);

        // فهرست سکتورهای FAT از هدر و DIFAT
        $fatSectorIds = array();
        for ($i = 0; $i < 109; $i++) {
            $s = self::u32le($data, 76 + $i * 4);
            if ($s >= 0xFFFFFFFC) {
                break;
            }
            $fatSectorIds[] = $s;
        }
        $difatId = $firstDifatSector;
        for ($k = 0; $k < $numDifatSectors && $difatId >= 0 && $difatId < 0xFFFFFFFC; $k++) {
            $pos = ($difatId + 1) * $sectorSize;
            if ($pos + $sectorSize > $dataLen) {
                break;
            }
            $entries = (int) ($sectorSize / 4) - 1;
            for ($e = 0; $e < $entries; $e++) {
                $s = self::u32le($data, $pos + $e * 4);
                if ($s >= 0xFFFFFFFC) {
                    break;
                }
                $fatSectorIds[] = $s;
            }
            $difatId = self::u32le($data, $pos + $entries * 4);
        }

        // ساخت FAT
        // نکته: بلوک k-ام از سکتورهای FAT، ورودی‌های مربوط به
        // سکتورهای k*count .. k*count+count-1 را نگه می‌دارد (نه بر اساس شماره سکتور FAT)
        $fat = array();
        $fatBlock = 0;
        foreach ($fatSectorIds as $fatSecId) {
            if ($fatSecId >= 0xFFFFFFFC) {
                continue;
            }
            $pos = ($fatSecId + 1) * $sectorSize;
            if ($pos + $sectorSize > $dataLen) {
                continue;
            }
            $count = (int) ($sectorSize / 4);
            for ($i = 0; $i < $count; $i++) {
                $fat[$fatBlock * $count + $i] = self::u32le($data, $pos + $i * 4);
            }
            $fatBlock++;
        }
        return self::parseBiffOle($data, $fat, $sectorSize, $firstDirSector, $miniCutoff,
            $firstMiniFatSector, $miniSectorSize);
    }
/**
     * خواندن جریان Workbook از دایرکتوری OLE و ارسال به پیمایش رکوردها
     */
    private static function parseBiffOle($data, &$fat, $sectorSize, $firstDirSector,
        $miniCutoff, $firstMiniFatSector, $miniSectorSize)
    {
        $dataLen = strlen($data);

        $readChain = function ($start, $maxBytes) use ($data, $dataLen, $sectorSize, &$fat) {
            $out = '';
            $sec = $start;
            $guard = 0;
            while ($sec !== 0xFFFFFFFE && isset($fat[$sec]) && $guard < 100000) {
                $pos = ($sec + 1) * $sectorSize;
                if ($pos + $sectorSize > $dataLen) {
                    break;
                }
                if ($maxBytes > 0 && strlen($out) + $sectorSize >= $maxBytes) {
                    $out .= substr($data, $pos, $maxBytes - strlen($out));
                    break;
                }
                $out .= substr($data, $pos, $sectorSize);
                $sec = $fat[$sec];
                $guard++;
            }
            return $out;
        };

        $dirStream = $readChain($firstDirSector, 0);
        if ($dirStream === '') {
            throw new ProductExcelReaderException('دایرکتوری فایل XLS باینری معتبر نیست.');
        }

        $rootStart = null;
        $rootSize = 0;
        $workbookStart = null;
        $workbookSize = 0;

        for ($o = 0; $o + 128 <= strlen($dirStream); $o += 128) {
            $entry = substr($dirStream, $o, 128);
            $nameLen = self::u16le($entry, 64);
            $type = ord($entry[66]);
            $startSec = self::u32le($entry, 116);
            $size = self::u64le($entry, 120);
            $name = '';
            if ($nameLen > 0 && $nameLen <= 64) {
                // نام DirectoryEntry از ابتدای رکورد (offset 0) و UTF-16LE است
                $name = function_exists('mb_convert_encoding')
                    ? mb_convert_encoding(substr($entry, 0, $nameLen), 'UTF-8', 'UTF-16LE')
                    : substr($entry, 0, $nameLen);
            }
            if ($type === 5) {
                $rootStart = $startSec;
                $rootSize = (int) $size;
            }
            if ($type === 2 && (trim($name) === 'Workbook' || trim($name) === 'Book')) {
                $workbookStart = $startSec;
                $workbookSize = (int) $size;
            }
        }

        if ($workbookStart === null) {
            throw new ProductExcelReaderException('Workbook درون فایل XLS پیدا نشد.');
        }

        if ($workbookSize >= $miniCutoff) {
            $stream = $readChain($workbookStart, $workbookSize);
        } elseif ($rootStart !== null) {
            $stream = self::readMiniWorkbook($data, $fat, $firstMiniFatSector, $sectorSize,
                $miniSectorSize, $rootStart, $workbookStart, $workbookSize);
        } else {
            $stream = '';
        }

        if ($stream === '') {
            throw new ProductExcelReaderException('اطلاعات Workbook در فایل XLS پیدا نشد.');
        }
        if ($workbookSize > 0 && strlen($stream) > $workbookSize) {
            $stream = substr($stream, 0, $workbookSize);
        }
        return self::parseBiffRecords($stream);
    }
/**
     * خواندن Workbook از Mini Stream (وقتی حجم کمتر از حد نصاب مینی باشد)
     */
    private static function readMiniWorkbook($data, &$fat, $firstMiniFatSector,
        $sectorSize, $miniSectorSize, $rootStart, $workbookStart, $workbookSize)
    {
        $dataLen = strlen($data);
        $miniContainer = '';
        $sec = $rootStart;
        $guard = 0;
        while ($sec !== 0xFFFFFFFE && isset($fat[$sec]) && $guard < 100000) {
            $pos = ($sec + 1) * $sectorSize;
            if ($pos + $sectorSize > $dataLen) {
                break;
            }
            $miniContainer .= substr($data, $pos, $sectorSize);
            $sec = $fat[$sec];
            $guard++;
        }

        $miniFat = array();
        $mId = $firstMiniFatSector;
        $mBlock = 0;
        $guardM = 0;
        while ($mId !== 0xFFFFFFFE && isset($fat[$mId]) && $guardM < 100000) {
            $pos = ($mId + 1) * $sectorSize;
            if ($pos + $sectorSize > $dataLen) {
                break;
            }
            $count = (int) ($sectorSize / 4);
            for ($i = 0; $i < $count; $i++) {
                $miniFat[$mBlock * $count + $i] = self::u32le($data, $pos + $i * 4);
            }
            $mBlock++;
            $mId = $fat[$mId];
            $guardM++;
        }

        $stream = '';
        $mSec = $workbookStart;
        $guardS = 0;
        while ($mSec !== 0xFFFFFFFE && isset($miniFat[$mSec]) && $guardS < 200000) {
            $pos = $mSec * $miniSectorSize;
            if ($pos + $miniSectorSize > strlen($miniContainer)) {
                break;
            }
            $stream .= substr($miniContainer, $pos, $miniSectorSize);
            $mSec = $miniFat[$mSec];
            $guardS++;
            if ($workbookSize > 0 && strlen($stream) >= $workbookSize) {
                break;
            }
        }
        return $stream;
    }
/**
     * پیمایش رکوردهای BIFF و استخراج سلول‌ها
     */
    private static function parseBiffRecords($stream)
    {
        $len = strlen($stream);
        $codepage = 1252;
        $sst = array();
        $cells = array();
        $pos = 0;
        $inSheet = false;
        $guard = 0;

        while ($pos + 4 <= $len && $guard++ < 200000) {
            $recType = self::u16le($stream, $pos);
            $recLen = self::u16le($stream, $pos + 2);
            if ($recLen > ($len - $pos - 4)) {
                break; // داده ناقص
            }
            $rec = substr($stream, $pos + 4, $recLen);
            $pos += 4 + $recLen;
            switch ($recType) {
                case 0x0809: // BOF — شروع برگه
                    $inSheet = true;
                    break;
                case 0x000A: // EOF
                    $inSheet = false;
                    break;
                case 0x0042: // CODEPAGE
                    if ($recLen >= 2) {
                        $codepage = self::u16le($rec, 0);
                    }
                    break;
                case 0x00FC: // SST (رشته‌های مشترک)
                    $sstData = $rec;
                    while ($pos + 4 <= $len) {
                        $t2 = self::u16le($stream, $pos);
                        $l2 = self::u16le($stream, $pos + 2);
                        if ($t2 !== 0x003C) {
                            break;
                        }
                        if ($l2 > ($len - $pos - 4)) {
                            break;
                        }
                        $sstData .= substr($stream, $pos + 4, $l2);
                        $pos += 4 + $l2;
                    }
                    $sst = self::parseSst($sstData, $codepage);
                    break;
                case 0x00FD: // LABELSST
                    if ($inSheet && $recLen >= 10) {
                        $row = self::u16le($rec, 0);
                        $col = self::u16le($rec, 2);
                        $idx = self::u32le($rec, 6);
                        $cells[$row][$col] = isset($sst[$idx]) ? $sst[$idx] : '';
                    }
                    break;
                case 0x0204: // LABEL (رشته مستقیم)
                    if ($inSheet && $recLen >= 9) {
                        $row = self::u16le($rec, 0);
                        $col = self::u16le($rec, 2);
                        $cch = self::u16le($rec, 6);
                        $flags = ord($rec[8]);
                        $highByte = ($flags & 0x01) === 1;
                        $bytes = $highByte ? $cch * 2 : $cch;
                        if (9 + $bytes <= $recLen) {
                            $raw = substr($rec, 9, $bytes);
                            $val = $highByte
                                ? self::utf16ToUtf8($raw)
                                : self::cpToUtf8($raw, $codepage);
                        } else {
                            $val = '';
                        }
                        $cells[$row][$col] = $val;
                    }
                    break;
                case 0x0203: // NUMBER
                    if ($inSheet && $recLen >= 14) {
                        $dbl = unpack('e', substr($rec, 6, 8));
                        if (is_array($dbl) && isset($dbl[1])) {
                            $cells[self::u16le($rec, 0)][self::u16le($rec, 2)] =
                                self::numberToString($dbl[1]);
                        }
                    }
                    break;
                case 0x027E: // RK
                    if ($inSheet && $recLen >= 10) {
                        $rk = self::u32le($rec, 6);
                        $cells[self::u16le($rec, 0)][self::u16le($rec, 2)] =
                            self::numberToString(self::rkToFloat($rk));
                    }
                    break;
                case 0x00BD: // MULRK
                    if ($inSheet && $recLen >= 6) {
                        $row = self::u16le($rec, 0);
                        $col = self::u16le($rec, 2);
                        $off = 4;
                        while ($off + 6 <= $recLen) {
                            $rk = self::u32le($rec, $off + 2);
                            $cells[$row][$col] = self::numberToString(self::rkToFloat($rk));
                            $col++;
                            $off += 6;
                        }
                    }
                    break;
                case 0x0205: // BOOLERR
                    if ($inSheet && $recLen >= 7) {
                        $cells[self::u16le($rec, 0)][self::u16le($rec, 2)] = (string) ord($rec[6]);
                    }
                    break;
                default:
                    break;
            }
        }
if (count($cells) === 0) {
            throw new ProductExcelReaderException('فایل XLS باینری داده‌ای برای خواندن ندارد.');
        }

        ksort($cells);
        $rows = array();
        foreach ($cells as $r => $cols) {
            ksort($cols);
            $rows[] = array('row' => $r + 1, 'cells' => $cols);
        }
        return $rows;
    }

    private static function parseSst($data, $codepage)
    {
        $len = strlen($data);
        if ($len < 8) {
            return array();
        }
        $count = self::u32le($data, 4);
        $list = array();
        $off = 8;
        for ($i = 0; $i < $count && $off < $len; $i++) {
            if ($off + 3 > $len) {
                break;
            }
            $cch = self::u16le($data, $off);
            $flags = ord($data[$off + 2]);
            $off += 3;
            $highByte = ($flags & 0x01) === 1;
            $bytes = $highByte ? $cch * 2 : $cch;
            if ($off + $bytes > $len) {
                break;
            }
            $raw = substr($data, $off, $bytes);
            $str = $highByte ? self::utf16ToUtf8($raw) : self::cpToUtf8($raw, $codepage);
            $off += $bytes;
            if (($flags & 0x08) !== 0 && $off + 2 <= $len) { $off += 2; }  // rich formatting
            if (($flags & 0x04) !== 0 && $off + 4 <= $len) { $off += 4; }  // خصوصیات اضافه
            $list[] = $str;
        }
        return $list;
    }

    private static function utf16ToUtf8($bytes)
    {
        if (function_exists('mb_convert_encoding')) {
            return mb_convert_encoding($bytes, 'UTF-8', 'UTF-16LE');
        }
        return $bytes;
    }

    private static function cpToUtf8($bytes, $codepage)
    {
        $enc = self::biffCodePage($codepage);
        if (function_exists('iconv')) {
            $c = @iconv($enc, 'UTF-8//IGNORE', $bytes);
            if ($c !== false) {
                return $c;
            }
        }
        if (function_exists('mb_convert_encoding')) {
            try {
                return mb_convert_encoding($bytes, 'UTF-8', $enc);
            } catch (Throwable $e) {
                // تلاش مجدد با پیشفرض
            }
        }
        return $bytes;
    }

    private static function biffCodePage($cp)
    {
        switch ($cp) {
            case 65001: return 'UTF-8';
            case 1200:  return 'UTF-16LE';
            case 1251:  return 'Windows-1251';
            case 1252:  return 'Windows-1252';
            case 1256:  return 'Windows-1256';
            case 10000: return 'MacRoman';
            default:    return 'Windows-1252';
        }
    }

    private static function rkToFloat($rk)
    {
        if (($rk & 0x02) === 0x02) {
            $v = $rk >> 2;
            if ($v & 0x20000000) {
                $v -= 0x40000000; // ممیز ۳۰ بیتی عدد صحیح
            }
        } else {
            $bin = pack('V', 0) . pack('V', $rk & 0xFFFFFFFC);
            $d = unpack('e', $bin);
            $v = isset($d[1]) ? $d[1] : 0;
        }
        if (($rk & 0x01) === 0x01) {
            $v /= 100;
        }
        return $v;
    }

    private static function numberToString($num)
    {
        if (is_int($num) || floor($num) == $num) {
            return (string) ((int) $num);
        }
        $s = rtrim(rtrim(sprintf('%.10F', $num), '0'), '.');
        if ($s === '' || $s === '-') {
            $s = '0';
        }
        return $s;
    }
/* ============================================================
     * ابزارهای عمومی
     * ========================================================== */

    private static function cleanValue($v)
    {
        $s = trim((string) $v);
        if ($s === '') {
            return '';
        }
        if (function_exists('iconv')) {
            $c = @iconv('UTF-8', 'UTF-8//IGNORE', $s);
            if ($c !== false) {
                return $c;
            }
        }
        return $s;
    }

    private static function readAllText($path)
    {
        $fp = @fopen($path, 'rb');
        if ($fp === false) {
            throw new ProductExcelReaderException('خطا در خواندن فایل.');
        }
        $data = '';
        while (!feof($fp)) {
            $data .= fread($fp, 65536);
        }
        fclose($fp);
        return $data;
    }

    private static function stripBom($s)
    {
        if (substr($s, 0, 3) === "\xEF\xBB\xBF") {
            return substr($s, 3);
        }
        return $s;
    }

    private static function u16le($s, $offset)
    {
        if ($offset + 2 > strlen($s)) {
            return 0;
        }
        return ord($s[$offset]) | (ord($s[$offset + 1]) << 8);
    }

    private static function u32le($s, $offset)
    {
        if ($offset + 4 > strlen($s)) {
            return 0;
        }
        $lo = ord($s[$offset]) | (ord($s[$offset + 1]) << 8);
        $hi = ord($s[$offset + 2]) | (ord($s[$offset + 3]) << 8);
        return $lo + ($hi << 16);
    }

    private static function u64le($s, $offset)
    {
        $lo = self::u32le($s, $offset);
        $hi = self::u32le($s, $offset + 4);
        if (PHP_INT_SIZE >= 8) {
            return $lo + ($hi * 4294967296);
        }
        return $lo;
    }
}