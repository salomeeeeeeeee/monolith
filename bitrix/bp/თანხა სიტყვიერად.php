

<?

require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
$APPLICATION->SetTitle(" ");


if(!function_exists('printArr')){
    function printArr($arr) {
        echo "<pre>"; print_r($arr); echo "</pre>";
    }
}


if(!function_exists('convertNumberToWord96')){
    function convertNumberToWord96($num = false){
        $num = str_replace(array(',', ' '), '' , trim($num));
        if(! $num) {
            return false;
        }
        $num = (int) $num;
        $words = array();
        $list1 = array('', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven',
            'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'
        );
        $list2 = array('', 'ten', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety', 'hundred');
        $list3 = array('', 'thousand', 'million', 'billion', 'trillion', 'quadrillion', 'quintillion', 'sextillion', 'septillion',
            'octillion', 'nonillion', 'decillion', 'undecillion', 'duodecillion', 'tredecillion', 'quattuordecillion',
            'quindecillion', 'sexdecillion', 'septendecillion', 'octodecillion', 'novemdecillion', 'vigintillion'
        );
        $num_length = strlen($num);
        $levels = (int) (($num_length + 2) / 3);
        $max_length = $levels * 3;
        $num = substr('00' . $num, -$max_length);
        $num_levels = str_split($num, 3);
        for ($i = 0; $i < count($num_levels); $i++) {    
            $levels--;
            $hundreds = (int) ($num_levels[$i] / 100);
            $hundreds = ($hundreds ? ' ' . $list1[$hundreds] . ' hundred' . ' ' : '');
            $tens = (int) ($num_levels[$i] % 100);
            $singles = '';
            if ( $tens < 20 ) {
                $tens = ($tens ? ' ' . $list1[$tens] . ' ' : '' );
            } else {
                $tens = (int)($tens / 10);
                $tens = ' ' . $list2[$tens] . ' ';
                $singles = (int) ($num_levels[$i] % 10);
                $singles = ' ' . $list1[$singles] . ' ';
            }
            $words[] = $hundreds . $tens . $singles . ( ( $levels && ( int ) ( $num_levels[$i] ) ) ? ' ' . $list3[$levels] . ' ' : '' );
        } //end for loop
        $commas = count($words);
        if ($commas > 1) {
            $commas = $commas - 1;
        }
        return implode(' ', $words);
    }
}

if(!function_exists('convertMoneyToWordsRu')){
    function convertMoneyToWordsRu($larebi, $tetrebi){
        // Lists for grammatical forms
        $dollarForms = ['доллар', 'доллара', 'долларов'];
        $centForms = ['цент', 'цента', 'центов'];

        // Convert numbers to words
        $dollarText = $larebi > 0 ? convertLargeNumberToWordRu($larebi) : 'ноль';
        $dollarWord = getWordForm($larebi, $dollarForms);

        $centText = $tetrebi > 0 ? convertLargeNumberToWordRu($tetrebi) : 'ноль';
        $centWord = getWordForm($tetrebi, $centForms);

        // Combine dollars and cents
        return "$dollarText $dollarWord и $centText $centWord";
    }
}

if(!function_exists('getWordForm')){
    function getWordForm($number, $forms) {
        $number = abs($number) % 100;
        if ($number >= 11 && $number <= 19) {
            return $forms[2]; // Genitive plural
        }
        $lastDigit = $number % 10;
        if ($lastDigit == 1) {
            return $forms[0]; // Singular
        } elseif ($lastDigit >= 2 && $lastDigit <= 4) {
            return $forms[1]; // Genitive singular
        } else {
            return $forms[2]; // Genitive plural
        }
    }
}


if(!function_exists('convertLargeNumberToWordRu')){
    function convertLargeNumberToWordRu($num){
        $list1 = array('', 'один', 'два', 'три', 'четыре', 'пять', 'шесть', 'семь', 'восемь', 'девять', 'десять',
            'одиннадцать', 'двенадцать', 'тринадцать', 'четырнадцать', 'пятнадцать', 'шестнадцать',
            'семнадцать', 'восемнадцать', 'девятнадцать'
        );
        $list2 = array('', 'десять', 'двадцать', 'тридцать', 'сорок', 'пятьдесят', 'шестьдесят', 'семьдесят',
            'восемьдесят', 'девяносто'
        );
        $list3 = array('', 'сто', 'двести', 'триста', 'четыреста', 'пятьсот', 'шестьсот', 'семьсот',
            'восемьсот', 'девятьсот'
        );
        $list4 = array('', 'тысяча', 'тысячи', 'тысяч', 'миллион', 'миллиона', 'миллионов');

        if ($num == 0) {
            return 'ноль';
        }

        $words = [];
        $chunks = str_split(str_pad($num, ceil(strlen($num) / 3) * 3, '0', STR_PAD_LEFT), 3);
        $levels = count($chunks);

        foreach ($chunks as $chunk) {
            $levels--;
            $chunk = (int)$chunk;
            if ($chunk == 0) {
                continue;
            }

            $hundreds = (int)($chunk / 100);
            $remainder = $chunk % 100;

            if ($hundreds) {
                $words[] = $list3[$hundreds];
            }

            if ($remainder < 20) {
                if ($remainder) {
                    $words[] = $list1[$remainder];
                }
            } else {
                $tens = (int)($remainder / 10);
                $units = $remainder % 10;
                $words[] = $list2[$tens];
                if ($units) {
                    $words[] = $list1[$units];
                }
            }

            if ($levels > 0) {
                if ($levels == 1) { // Thousands
                    $wordForm = getWordForm($chunk, ['тысяча', 'тысячи', 'тысяч']);
                    $words[] = $wordForm;
                } elseif ($levels == 2) { // Millions
                    $wordForm = getWordForm($chunk, ['миллион', 'миллиона', 'миллионов']);
                    $words[] = $wordForm;
                }
            }
        }

        return trim(implode(' ', $words));
    }
}


if(!function_exists('convertToText96')){
    function convertToText96($num){
        if ($num == 0)
        {
            return "ნული";
        }
        $aseulebi = array( "", "ას", "ორას", "სამას", "ოთხას", "ხუთას", "ექვსას", "შვიდას", "რვაას", "ცხრაას");
        $oceulebi = array("", "", "ოც", "", "ორმოც", "", "სამოც", "", "ოთხმოც" );
        $erteulebi = array("", "ერთი", "ორი", "სამი", "ოთხი", "ხუთი", "ექვსი", "შვიდი", "რვა", "ცხრა", "ათი"
                , "თერთმეტი", "თორმეტი", "ცამეტი", "თოთხმეტი", "თხუთმეტი", "თექვსმეტი", "ჩვიდმეტი", "თვრამეტი", "ცხრამეტი"
        );
        $result = "";
        
        if (($num - $num % 1000000) / 1000000 > 0)
        {
            $result .= convertToText96((int)($num - $num % 1000000) / 1000000);
            if ($num % 1000000 >= 1)
            {
                $result .= " მილიონ ";
            } else
            {
                $result .= " მილიონი";
            }
            $num %= 1000000;
        }
        
        if (($num - $num % 1000) / 1000 > 0)
        {
            $result .= convertToText96((int)($num - $num % 1000) / 1000);
            if ($num % 1000 >= 1)
            {
                if ($result == "ერთი")
                {
                    $result = "ათას ";
                } else
                {
                    $result .= " ათას ";
                }
            } else
            {
                if ($result == "ერთი")
                {
                    $result = "ათასი";
                } else
                {
                    $result .= " ათასი";
                }
            }
            $num %= 1000;
        }
        
        if ($num >= 100 && ($num - $num % 100) / 100 > 0)
        {
            $result .= $aseulebi[(int)(($num - $num % 100) / 100)];
            if ($num % 100 >= 1)
            {
                $result .= " ";
            } else
            {
                $result .= "ი";
            }
            $num %= 100;
        }
        
        if ($num >= 20 && ($num - $num % 20) / 10 > 0)
        {
            $result .= $oceulebi[(int)(($num - $num % 20) / 10)];
            if ($num % 20 >= 1)
            {
                $result .= "და";
            } else
            {
                $result .= "ი";
            }
            $num %= 20;
        }
        
        if ($num >= 1)
        {
            $result .= $erteulebi[$num];
        }
        
        return $result;
    }
}



if(!function_exists('getDealsByFilter96')){
    function getDealsByFilter96($arFilter, $arSelect = array(), $arSort = array("ID"=>"DESC")) {
        $arDeals = array();
        $res = CCrmDeal::GetList($arSort, $arFilter, array("ID","OPPORTUNITY","UF_CRM_1781860218697"));
        while($arDeal = $res->Fetch()) array_push($arDeals, $arDeal);
        return (count($arDeals) > 0) ? $arDeals : false;
    }
}


    
$rootActivity = $this->GetRootActivity();
$deal_ID = $rootActivity->GetVariable('deal_ID');
//  $deal_ID = 4672;
$arrFilter=array("ID"=>$deal_ID);


$deals=getDealsByFilter96($arrFilter);

  
foreach($deals as $deal){

    $dealID=$deal["ID"];
    if ($deal["OPPORTUNITY"]){

        $totalpriceexploded=explode(".",$deal["OPPORTUNITY"]);
        $larebi=$totalpriceexploded[0];
        $tetrebi=$totalpriceexploded[1];

        $larebitext=convertToText96($larebi);
        $tetrebitext=convertToText96($tetrebi);
        $tanxasityvierad=$larebitext." დოლარი და ".$tetrebitext." ცენტი";
       


        $larebitexteng=convertNumberToWord96($larebi);
        if($tetrebi>9){
            $tetrebitexteng=convertNumberToWord96($tetrebi);
        }elseif($tetrebi>0){

        if(strlen($tetrebi) == 2 ){
            $arr1 = str_split($tetrebi);

            $tetrebitexteng=convertNumberToWord96($arr1[1]);
        }elseif(strlen($tetrebi) == 1){
            $testrebi=$tetrebi."0";
            $tetrebitexteng=convertNumberToWord96($tetrebi);
        }

        }elseif($tetrebi==0){

            $tetrebitexteng='zero';
        
        }

        $tanxasityvieradeng=$larebitexteng." dollars and ".$tetrebitexteng." cent";

        
        $tanxasityvieradru = convertMoneyToWordsRu($larebi, $tetrebi);



    }else {
        $tanxasityvieradeng=" ";
        $tanxasityvierad=" ";
        $tanxasityvieradru = " ";
    }


    

    $CCrmDeal = new CCrmDeal();
    $upd = array(
        "UF_CRM_1781860218697" => $tanxasityvierad,
        // "UF_CRM_1756992081" => $tanxasityvieradeng,
        // "UF_CRM_1756992015" => $tanxasityvieradru,

    );

    $CCrmDeal->Update($dealID, $upd);


}
