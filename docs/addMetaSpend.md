# addMetaSpend.php - Meta-ს რეკლამის ხარჯების მიღება

Meta-ს (Facebook / Instagram) რეკლამის ხარჯები ბიტრიქსში შემოდის API-ით და ინახება
სიაში **"Meta - რეკლამის ხარჯები"** (`META_AD_SPEND`). ჯერჯერობით მხოლოდ ინახება -
რეპორტები / ლიდებთან დაკავშირება შემდეგი ეტაპია.

## პროცესი

```
Meta Ads Manager ──(Marketing API, Insights)──► გამომგზავნი (სკრიპტი / ინტეგრაცია)
                                                     │  POST JSON + Bearer ტოკენი
                                                     ▼
                       https://crm.monolith.ge/rest/public/addMetaSpend.php
                                                     │
                                                     ▼
                         სია "Meta - რეკლამის ხარჯები" (META_AD_SPEND)
```

1. გამომგზავნი Meta Marketing API-დან იღებს Insights-ს **რეკლამის დონეზე** (`level=ad`),
   **დღეებად** (`time_increment=1`).
2. მიღებულ რიგებს გვიგზავნის POST-ით - შეიძლება Meta-ს პასუხი პირდაპირ, უცვლელად.
3. ჩვენთან თითო რიგი ხდება სიის ერთი ჩანაწერი: **1 რეკლამა × 1 დღე**.
4. იგივე რეკლამის იგივე დღის ხელახლა გამოგზავნა ჩანაწერს **აახლებს** და ახალს არ ქმნის.

**რეკომენდებული განრიგი:** დღეში ერთხელ (მაგ. დილის 06:00-ზე) გამოიგზავნოს **ბოლო 7 დღე**.
Meta ხარჯს რამდენიმე დღის განმავლობაში აზუსტებს - ხელახლა გამოგზავნა ძველ ციფრს
ჩაანაცვლებს, ორმაგად არაფერი დაითვლება. ისტორიის ჩატვირთვა (backfill) - ნებისმიერი
პერიოდი, იმავე ფორმატით.

## ენდპოინტი

| | |
|---|---|
| **URL** | `https://crm.monolith.ge/rest/public/addMetaSpend.php` |
| **Method** | `POST` |
| **Content-Type** | `application/json` (body - UTF-8) |
| **ავტორიზაცია** | `Authorization: Bearer <TOKEN>` (ან `X-Api-Token: <TOKEN>`) |

ტოკენი git-ში არ ინახება (რეპო საჯაროა) - მხოლოდ სერვერზეა:
`/local/php_interface/meta_spend_secret.php`. ადმინს ჩანს გვერდზეც
`https://crm.monolith.ge/custom/setup/metaSpendList.php`. გამომგზავნს გადაეცემა ცალკე, დახურული არხით.
შეცვლა - ფაილში ახალი მნიშვნელობის ჩაწერა და გამომგზავნისთვის გადაცემა.

თუ გამომგზავნ ხელსაწყოს ჰედერის დამატება არ შეუძლია, მიიღება `?token=<TOKEN>` URL-შიც
(ნაკლებად უსაფრთხოა - URL ლოგებში ჩანს).

## ველები (ერთი რიგი)

| ველი | ტიპი | სავალდებულო | აღწერა |
|---|---|---|---|
| `date_start` | string `YYYY-MM-DD` | **კი** | პერიოდის დასაწყისი |
| `date_stop` | string `YYYY-MM-DD` | არა | პერიოდის დასასრული; არ მოვიდა - `date_start`-ის ტოლია. `date_start`-ზე ადრე ვერ იქნება |
| `campaign_name` | string | არა | კამპანიის სახელი |
| `campaign_id` | string | არა* | კამპანიის ID |
| `adset_name` | string | არა | რეკლამების ჯგუფის სახელი |
| `adset_id` | string | არა* | რეკლამების ჯგუფის ID |
| `ad_name` | string | არა | რეკლამის სახელი |
| `ad_id` | string | **კი*** | რეკლამის ID |
| `spend` | number ან string | **კი** | ხარჯი, `>= 0`. `"12.34"`, `12.34`, `"12,34"` - ყველა მიიღება |
| `account_currency` | string | არა | ანგარიშის ვალუტა (`USD`, `GEL`...) - Meta-ს `spend` ამ ვალუტაშია |

\* `ad_id` აუცილებელია ჩვეულებრივ რიგში. თუ რიგი აგრეგირებულია (რეკლამის გარეშე),
საკმარისია `adset_id`, ან მხოლოდ `campaign_id`.

- ID-ები ჯობია სტრიქონად მოვიდეს (Meta ასე აბრუნებს). რიცხვადაც მიიღება - 18-ნიშნა ID-იც ზუსტად ინახება.
  დასაშვები სიმბოლოები: ციფრები, ლათინური ასოები, `_`, `-` (მაქს. 64).
- თარიღად მიიღება `DD.MM.YYYY` და ISO datetime-იც (`2026-11-16T00:00:00+0400` → `2026-11-16`).
- სხვა ველები (`impressions`, `clicks`...) შეცდომას არ იწვევს - ინახება `RAW_JSON`-ში, ცალკე ველად არა.

## Body-ს ფორმატი

მიიღება ოთხივე ვარიანტი:

```jsonc
// 1. Meta Insights API-ის პასუხი პირდაპირ (paging იგნორირდება)
{ "data": [ {...}, {...} ], "paging": { ... } }

// 2. რიგების მასივი
[ {...}, {...} ]

// 3. {"rows": [...]}
{ "rows": [ {...}, {...} ] }

// 4. ერთი რიგი
{ "date_start": "2026-11-16", "ad_id": "...", "spend": "12.34" }
```

სრული მაგალითი:

```json
{
  "data": [
    {
      "date_start": "2026-11-16",
      "date_stop": "2026-11-16",
      "campaign_name": "Dighomi - Leads - Nov",
      "campaign_id": "120210000000000001",
      "adset_name": "Tbilisi 25-45",
      "adset_id": "120210000000000002",
      "ad_name": "Video 1",
      "ad_id": "120210000000000003",
      "spend": "21.75",
      "account_currency": "USD"
    }
  ]
}
```

## Meta-დან წამოღება (გამომგზავნის მხარე)

```
GET https://graph.facebook.com/v<ვერსია>/act_<AD_ACCOUNT_ID>/insights
    ?level=ad
    &fields=campaign_name,campaign_id,adset_name,adset_id,ad_name,ad_id,spend,account_currency
    &time_increment=1
    &time_range={"since":"2026-11-10","until":"2026-11-16"}
    &limit=500
    &access_token=<META_ACCESS_TOKEN>
```

- `time_increment=1` - თითო რიგი = 1 დღე (`date_start` = `date_stop`). **ეს არის სასურველი რეჟიმი.**
- პასუხი 500-ზე მეტი რიგი თუ გამოვა, Meta აბრუნებს `paging.next`-ს - თითო გვერდი გამოიგზავნოს
  ცალკე მოთხოვნით (ან გაერთიანდეს, ოღონდ ერთ მოთხოვნაში მაქს. 2000 რიგი).
- რამდენიმე სარეკლამო ანგარიშის შემთხვევაში - იგივე, თითო ანგარიშზე. `ad_id` Meta-ში უნიკალურია,
  ანგარიშები ერთმანეთს არ გადაეფარება.

## პასუხი

წარმატება - `200`:

```json
{ "status": 200, "message": "OK", "received": 120, "created": 100, "updated": 20, "failed": 0 }
```

- `created` - ახალი ჩანაწერები, `updated` - არსებული (იგივე რეკლამა + პერიოდი) განახლდა.
- ერთი რიგის გამოგზავნისას პასუხს ემატება `"id"` - სიის ჩანაწერის ID.

ნაწილობრივ შენახული - ისევ `200`, ცუდი რიგები `errors`-შია (`index` - რიგის ნომერი 0-დან):

```json
{
  "status": 200,
  "message": "Partially saved - see errors",
  "received": 5, "created": 1, "updated": 0, "failed": 4,
  "errors": [
    { "index": 0, "message": "spend is required" },
    { "index": 1, "message": "date_stop is earlier than date_start" },
    { "index": 2, "message": "ad_id is required (or adset_id / campaign_id for aggregated rows)" },
    { "index": 3, "message": "date_start is missing or invalid (YYYY-MM-DD expected)" }
  ]
}
```

შეცდომა:

```json
{ "status": 400, "message": "Nothing saved", "received": 1, "errors": [ ... ] }
```

| კოდი | როდის |
|---|---|
| `200` | ერთი რიგი მაინც შეინახა (დანარჩენის შეცდომები - `errors`-ში) |
| `400` | ცარიელი body, გაუმართავი JSON, 2000-ზე მეტი რიგი, ან ვერც ერთი რიგი ვერ შეინახა |
| `401` | ტოკენი არ მოვიდა ან არასწორია |
| `405` | მეთოდი POST არ არის |
| `500` | სია ან ტოკენი სერვერზე არ არის მოწყობილი |

`400`/`401`/`405`/`500`-ზე არაფერი ინახება - შეიძლება იგივე მოთხოვნის თავიდან გაგზავნა.

## ლიმიტები და სიჩქარე

- ერთ მოთხოვნაში მაქსიმუმ **2000 რიგი**.
- ~15 მწმ რიგზე: 500 რიგი ≈ 7-8 წამი (გაზომილია 2026-11-17). რეკომენდაცია - 500 რიგი მოთხოვნაზე
  (Meta-ს ერთი გვერდი), პასუხის ლოდინის დრო (timeout) - 60 წამი მაინც.

## იდემპოტენტურობა (ორმაგი დათვლის დაცვა)

ჩანაწერის გასაღები - `XML_ID`:

```
meta-spend-ad-<ad_id>-<date_start>-<date_stop>
meta-spend-adset-<adset_id>-<date_start>-<date_stop>        (რიგი ad_id-ის გარეშე)
meta-spend-campaign-<campaign_id>-<date_start>-<date_stop>  (მხოლოდ campaign_id)
```

იგივე გასაღების ხელახლა მოსვლა ჩანაწერს გადააწერს (ხარჯი, სახელები, ვალუტა, `RAW_JSON`,
მიღების დრო). ჩანაწერი არასდროს იშლება ავტომატურად.

**ყურადღება:** გასაღებში პერიოდიც შედის. თუ ერთი და იგივე რეკლამა მოვა ჯერ დღეებად
(`2026-11-01`, `2026-11-02`...), მერე კვირის ჯამად (`2026-11-01 .. 2026-11-07`), ორივე შეინახება
და ჯამში ორმაგად დაითვლება. ამიტომ ყოველთვის `time_increment=1` (დღეებად), ან რეპორტში
ფილტრი `DATE_START = DATE_STOP`.

## სად ინახება

სია **"Meta - რეკლამის ხარჯები"** - `META_AD_SPEND`, iblock **36**
([ჩანაწერები](https://crm.monolith.ge/services/lists/36/view/0/)):

| სიის ველი (CODE) | ტიპი | საიდან |
|---|---|---|
| დასახელება (NAME) | | `2026-11-16 · <ad_name>` (ან adset / campaign სახელი) |
| `DATE_START` | თარიღი | `date_start` |
| `DATE_STOP` | თარიღი | `date_stop` |
| `CAMPAIGN_NAME` | სტრიქონი | `campaign_name` |
| `CAMPAIGN_ID` | სტრიქონი | `campaign_id` |
| `ADSET_NAME` | სტრიქონი | `adset_name` |
| `ADSET_ID` | სტრიქონი | `adset_id` |
| `AD_NAME` | სტრიქონი | `ad_name` |
| `AD_ID` | სტრიქონი | `ad_id` |
| `SPEND` | რიცხვი | `spend` |
| `CURRENCY` | სტრიქონი | `account_currency` |
| `RECEIVED_AT` | თარიღი/დრო | ბოლო მიღების დრო (სერვერის) |
| `RAW_JSON` | ტექსტი | რიგი ზუსტად ისე, როგორც მოვიდა |

ID-ები სტრიქონია, რომ 17-18 ნიშნა Meta ID-ის სიზუსტე არ დაიკარგოს. ჩანაწერის ავტორი - ადმინი (ID 1).
სიას ბიზნეს-პროცესები არ აქვს; უფლებები "Dailo API log"-ის (iblock 26) მსგავსია.

## სიის შექმნა (ერთხელ - უკვე გაშვებულია 2026-11-17)

ადმინით: `https://crm.monolith.ge/custom/setup/metaSpendList.php`

იდემპოტენტურია - სია თუ არსებობს, მხოლოდ დაკლებულ ველებს ამატებს. ველები იქმნება
Lists-ის API-ით (`CList::AddField`), რომ `/services/lists/36/fields/`-ში გამოჩნდეს. გვერდზე ჩანს
ტოკენის სტატუსი და მზა `curl` ტესტისთვის.

## რეპორტიდან წაკითხვა

```php
CModule::IncludeModule('iblock');
$iblockId = CIBlock::GetList([], ['CODE' => 'META_AD_SPEND', 'CHECK_PERMISSIONS' => 'N'])->Fetch()['ID'];

// თარიღის ველი ბაზაში YYYY-MM-DD-ად ინახება - ფილტრი ამ ფორმატით
$res = CIBlockElement::GetList(
    ['PROPERTY_DATE_START' => 'ASC'],
    [
        'IBLOCK_ID'             => $iblockId,
        '>=PROPERTY_DATE_START' => '2026-11-01',
        '<=PROPERTY_DATE_START' => '2026-11-30',
        'CHECK_PERMISSIONS'     => 'N',
    ],
    false,
    false,
    ['ID', 'PROPERTY_DATE_START', 'PROPERTY_CAMPAIGN_NAME', 'PROPERTY_AD_ID', 'PROPERTY_SPEND', 'PROPERTY_CURRENCY']
);
// PROPERTY_DATE_START_VALUE ბრუნდება საიტის ფორმატით: DD/MM/YYYY
```

## შემოწმება

```bash
curl -X POST https://crm.monolith.ge/rest/public/addMetaSpend.php \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer <TOKEN>" \
  --data-binary @payload.json
```

`payload.json` - ზემოთ მოცემული "სრული მაგალითი", UTF-8-ში შენახული.
Windows-ზე ქართული ტექსტი `-d '...'`-ით პირდაპირ ბრძანებაში არ გაგზავნოთ - კონსოლი `?`-ებად
აქცევს; გამოიყენეთ ფაილი (`--data-binary @file`).

## ფაილები

| ფაილი | რა არის |
|---|---|
| [rest/public/addMetaSpend.php](../rest/public/addMetaSpend.php) | API |
| [custom/setup/metaSpendList.php](../custom/setup/metaSpendList.php) | სიის შექმნა |
| `local/php_interface/meta_spend_secret.php` | ტოკენი (მხოლოდ სერვერზე, git-ში არა) |
| [local/php_interface/meta_spend_secret.example.php](../local/php_interface/meta_spend_secret.example.php) | ტოკენის ფაილის ნიმუში |
