# AGENTS.md — библиотека `src/DNS`

Правила этого каталога. Общие — в корневом `AGENTS.md`.

## Как всё подключается

`_All.php` — манифест. Порядок важен:

```
Codec/_All.php      NameCodec, StrCodec, NameTable, Json/
Binary/_All.php     Packet (трейт), DnsReader, DnsWriter
Header.php          трейт Packet и JsonPacket должны быть объявлены раньше
Question.php
Record/_All.php     DnsRdata и типы, RdataRegistry — после Binary
Record.php
List/_All.php       DnsList и списки секций
Message.php
Builder.php
Nbns.php
Transport/_All.php
```

`Include_Once` — это обычный `include_once` (ключевое слово PHP, регистр не важен).
Путь разрешается относительно **вызывающего файла**, поэтому `Include_Once 'Json/_All.php'`
из `Codec/_All.php` найдёт `Codec/Json/_All.php`. Абсолютные пути не использовать.

Новый файл подключается в `_All.php` своей папки; новый подкаталог получает свой `_All.php`.

## Тесты

Тест живёт в том же файле, что и класс, в конце: `static function test(TestRunner $t)`.
Варианты для разных срезов: `testJson`, `testCompress`, `testWrite`. Групповой вызов —
в `Record/Base.php` (`DnsRdata::test`) или в `Test.php8` для самостоятельных классов.

Ассерты: `$t->eq`, `$t->ok`, `$t->throws`. Имя проверки — короткая русская фраза,
начинается с имени класса: `'MxRecord: roundtrip'`.

## Типы записей

`DnsRdata` — абстракция, реализации называются `<Type>Record` (`ARecord`, `MxRecord`,
`RrsigRecord`). Свойства типов:

- `public const TYPE` — код типа;
- `serialize()` — RDATA в wire;
- `fromReader(DnsReader $r)` — разбор RDATA (ридер стоит на её начале);
- `toText()` / `fromText()` — презентационный формат (поле `Data` в JSON);
- `__toString()` — для логов.

Порядок добавления — в `docs/recipes.md`. Не забыть `RdataRegistry::MAP`, `_All.php`,
`DnsMessage::TYPE_NAMES` и цикл JSON→wire в `Message::testJson`.

## Сообщение и секции

- Секции — `DnsList`-объекты; `count()`, `foreach`, `[]=` работают, `array_*` — нет.
- Счётчики заголовка вычисляются в `DnsMessage::writeTo()`, не хранятся.
- Пустые секции в JSON не пишутся: контейнер открывается при первом ребёнке.

## Стиль

- 2 пробела, LF, **cp1251**.
- Табличное выравнивание: `=`, `=>`, `,`, `::` в колонку. Не «нормализовать».
- Секции файла помечены `//****************************************************************`.
- `final` у классов, которые не наследуются.
- Сообщения исключений — по-русски, с указанием источника (`$.Answer[2]: …`,
  `udp://1.1.1.1:53: …`).

## Не делать

- Не собирать байты мимо `DnsWriter`, не разбирать мимо `DnsReader`.
- Не добавлять `declare(strict_types=1)` в обычные классы (только в `_All.php`).
- Не менять порядок подключения ради «красоты»: трейты и интерфейсы должны быть
  объявлены раньше классов, которые их используют.
- Не переводить код в UTF-8 и не менять LF на CRLF.