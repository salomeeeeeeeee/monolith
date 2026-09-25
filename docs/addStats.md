# addStats.php - დღიური სტატისტიკის მიღება

ანალოგი `addLead.php`-ისა, ოღონდ სტატისტიკისთვის: დღიური ჯამები (საუბრები,
ლიდები, კომენტარები) + არხების ჭრილი.

## ენდპოინტი

| | |
|---|---|
| **URL** | `https://crm.monolith.ge/rest/public/addStats.php` |
| **Method** | `POST` |
| **Content-Type** | `application/json` |
| **ავტორიზაცია** | `Authorization: Bearer <TOKEN>` (ან `X-Api-Token: <TOKEN>`) |

ტოკენი კოდშია - `STATS_API_TOKEN`, [rest/public/addStats.php](../rest/public/addStats.php).
შესაცვლელად იქვე იცვლება და პარტნიორს ეგზავნება ახალი მნიშვნელობა.

## რას ვიღებთ

```json
{
  "date": "2026-09-20",
  "period_from": "2026-09-20T00:00:00+04:00",
  "period_to": "2026-09-21T00:00:00+04:00",
  "totals": { "conversations": 340, "leads": 47, "comments": 96 },
  "channels": [
    { "channel": "Messenger",  "conversations": 210, "leads": 28 },
    { "channel": "Instagram",  "conversations": 95,  "leads": 14 },
    { "channel": "WhatsApp",   "conversations": 20,  "leads": 4  },
    { "channel": "Widget",     "conversations": 15,  "leads": 1  }
  ],
  "comments": { "total": 96, "answered": 71, "hidden": 5 }
}
```

- სავალდებულოა მხოლოდ `date` (`YYYY-MM-DD`; მიიღება `DD.MM.YYYY` და ISO datetime-იც).
- `totals`-ის გარეშე ჯამები `channels`-იდან დაითვლება.
- არხის უცნობი სახელი არ იკარგება - ისე ჩაიწერება, როგორც მოვიდა.
  ცნობილი სინონიმები ერთმანეთს უტოლდება: `fb` / `facebook` / `fb-messenger` → `Messenger`,
  `ig` → `Instagram`, `wa` → `WhatsApp`, `site` / `chat` → `Widget`.
- backfill-ისთვის მიიღება დღეების მასივიც: `[{...}, {...}]` ან `{"days": [{...}]}`.

## პასუხი

წარმატება - `200`:

```json
{
  "status": 200,
  "message": "OK",
  "date": "2026-09-20",
  "dailyId": 1234,
  "created": true,
  "channels": { "created": 4, "updated": 0, "removed": 0 }
}
```

`created: false` ნიშნავს, რომ ამ თარიღის ჩანაწერი უკვე იყო და განახლდა.

შეცდომა - `400` / `401` / `500`:

```json
{ "status": 400, "message": "date is required (YYYY-MM-DD)" }
```

| კოდი | როდის |
|---|---|
| `400` | ცარიელი/გაუმართავი body, ან `date` აკლია |
| `401` | ტოკენი არ მოვიდა ან არასწორია |
| `500` | სიები ჯერ არ არის შექმნილი, ან ჩაწერა ჩავარდა |

batch-ის შემთხვევაში პასუხი: `{"status":200,"message":"OK","days":3,"results":[...]}`.

## სად ინახება

ორ სიაში (universal lists):

**`DAILO_STATS_DAILY`** - 1 ჩანაწერი = 1 დღე
`STAT_DATE`, `PERIOD_FROM`, `PERIOD_TO`, `CONVERSATIONS`, `LEADS`,
`COMMENTS_TOTAL`, `COMMENTS_ANSWERED`, `COMMENTS_HIDDEN`, `RECEIVED_AT`, `RAW_JSON`

**`DAILO_STATS_CHANNEL`** - 1 ჩანაწერი = დღე + არხი
`STAT_DATE`, `CHANNEL`, `CONVERSATIONS`, `LEADS`, `PARENT_ID`

თარიღი სტრიქონია `YYYY-MM-DD` ფორმატში - ასე პერიოდის ფილტრი და სორტირება
პირდაპირ მუშაობს (`>=PROPERTY_STAT_DATE`), თარიღის ფორმატების გარდაქმნის გარეშე.

### იდემპოტენტურობა

ჩანაწერის გასაღები `XML_ID`-ია:

- დღე - `dailo-stats-2026-09-20`
- არხი - `dailo-stats-2026-09-20-messenger`

ერთი და იმავე თარიღის ხელახლა გამოგზავნა არსებულ ჩანაწერს **აახლებს**, ახალს არ
ქმნის - რეპორტში ორმაგად ვერ დაითვლება. თუ ახალ payload-ში რომელიმე არხი აღარ
არის, იმ დღის ზედმეტი არხის ჩანაწერი იშლება.

## სიების შექმნა (ერთხელ)

ადმინით გაიხსნება: `https://crm.monolith.ge/custom/setup/dailoStatsLists.php`

სკრიპტი იდემპოტენტურია - თუ სია უკვე არსებობს, მხოლოდ დაკლებულ ველებს ამატებს.
iblock-ის პარამეტრებს (ტიპი, საიტი, უფლებები, `VERSION`, `BIZPROC`) არსებული
მომუშავე სიიდან - "Dailo API log" (iblock 26) - კოპირებს.

ველები იქმნება **Lists მოდულის API-ით** (`CList::AddField`), და არა პირდაპირ
`CIBlockProperty::Add`-ით: Lists-ს ველების საკუთარი რეგისტრი აქვს, ამიტომ ნედლად
დამატებული თვისება ბაზაში დევს, `/services/lists/<id>/fields/`-ში კი არ ჩანს.
გვერდი ბოლოში ბეჭდავს, რას აბრუნებს `CList::GetFields()` - ანუ თვითონვე ამოწმებს,
რომ ველები Lists-მა დაინახა.

`?rebuild=1` - **ცარიელ** სიებს წაშლის და თავიდან შექმნის (ჩანაწერიანს არასდროს).

## რეპორტიდან წაკითხვა

```php
$iblockId = CIBlock::GetList([], ['CODE' => 'DAILO_STATS_CHANNEL'])->Fetch()['ID'];

$res = CIBlockElement::GetList(
    ['PROPERTY_STAT_DATE' => 'ASC'],
    [
        'IBLOCK_ID'           => $iblockId,
        '>=PROPERTY_STAT_DATE' => '2026-09-01',
        '<=PROPERTY_STAT_DATE' => '2026-09-30',
        'CHECK_PERMISSIONS'   => 'N',
    ],
    false,
    false,
    ['ID', 'PROPERTY_STAT_DATE', 'PROPERTY_CHANNEL', 'PROPERTY_CONVERSATIONS', 'PROPERTY_LEADS']
);
```

## შემოწმება

```bash
curl -X POST https://crm.monolith.ge/rest/public/addStats.php \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer <TOKEN>" \
  -d '{"date":"2026-09-20","totals":{"conversations":340,"leads":47,"comments":96},
       "channels":[{"channel":"Messenger","conversations":210,"leads":28}],
       "comments":{"total":96,"answered":71,"hidden":5}}'
```
