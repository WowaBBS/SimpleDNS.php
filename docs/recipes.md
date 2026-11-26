# Рецепты

Пошаговые инструкции для типовых изменений. После каждого пункта — проверка.

## Новый тип записи

1. `src/DNS/Record/Foo.php` — класс `FooRecord extends DnsRdata`:
   - `public const TYPE = <код>;`
   - `serialize(): string` — RDATA в wire-формате;
   - `fromReader(DnsReader $r): static` — разбор из ридера, стоящего на начале RDATA;
   - `toText(): string` / `fromText(string $data): static` — презентационный формат
     (это поле `Data` в JSON, RFC 8427);
   - `__toString(): string` — для логов;
   - `static function test(TestRunner $t)` — golden-вектор байтов, roundtrip wire,
     разбор текста, негативные случаи.
2. `src/DNS/Record/Registry.php` — добавить `код => FooRecord ::class` в `MAP`
   (обратный поиск `typeFor()` работает от него автоматически).
3. `src/DNS/Record/_All.php` — `Include_Once 'Foo.php';`.
4. `src/DNS/Message.php` — мнемоника в `TYPE_NAMES` (`код => 'FOO'`), иначе в JSON
   тип будет `TYPEnnn`.
5. `src/DNS/Record/Base.php` — вызвать `FooRecord::test($t)` в `DnsRdata::test()`.
6. `src/DNS/Message.php`, `testJson()` — добавить пару `[код, new FooRecord(...)]`
   в цикл «JSON → wire», чтобы тип проверялся и в JSON-круге.

Проверка: `php -l` по изменённым файлам, `php Test.php8` — `0 упало`.

## Новый транспорт

1. `src/DNS/Transport/Foo.php` — `final class FooTransport implements DnsTransport`
   (или `extends MulticastTransport` для multicast-протокола):
   - `use TransportChecks;` — сверка ID ответа;
   - конструктор: адрес и `timeout` (таймаут — свойство, не параметр метода);
   - `name(): string` — «схема://адрес:порт» для логов и ошибок;
   - `exchange(DnsMessage $request): DnsMessage` — отправка и разбор;
   - `static function test(TestRunner $t)` — без сети: адрес, параметры, кадрирование,
     нормализация запроса.
2. `src/DNS/Transport/_All.php` — подключить (базовый класс раньше наследников).
3. `src/DNS/Transport/Factory.php` — схема в `fromUrl()`.
4. `src/DNS/Test.php8` — вызов `FooTransport::test($t)`.
5. `src/DNS/Test.Network.php8` — живая проверка; если сеть не обязательна для
   утверждения, печатайте `NOTE`, а не PASS/FAIL.

Проверка: `php Test.php8` и (если есть сеть) `php Test.Network.php8`.

## Новое поле в JSON

1. Запись: `writeToJson(JsonWriter $w)` — `$w->put('Key', $value)`.
   Для заголовка — `DnsHeader::writeJsonFields()`.
2. Чтение: `fromJsonReader(JsonReader $j)` — `$j->int('Key')`, `$j->boolOr(...)`,
   `$j->strOrInt(...)`. Отсутствующее поле — `*Or` с осознанным значением по умолчанию.
3. Тест: точный массив канонического вида **и** проверка, что `JSON → wire`
   даёт те же байты (`DnsMessage::fromJson($m->toJsonString())->serialize()`).
4. Помните: ключи ищутся без учёта регистра, тип может прийти числом.

## Новая секция

1. `src/DNS/List/FooList.php`: `final class FooList extends DnsList` + `itemClass()`.
2. `src/DNS/List/_All.php` — подключить.
3. `src/DNS/Message.php` — свойство в конструкторе, запись в `writeTo()`,
   `writeJsonTo()`, чтение в `fromReader()`/`fromJsonReader()`.

## Новый тест

- Офлайн: `static function test(TestRunner $t)` в классе, вызов в `Test.php8`.
- Сеть: `Test.Network.php8`; зависящее от окружения — через `NOTE`.
- Имя проверки — короткая русская фраза: `'TcpTransport: кадр с префиксом длины'`.
- Ассерты: `$t->eq($got, $want, $name)`, `$t->ok($cond, $name)`,
  `$t->throws(fn() => ..., $name)`.

## Правка ошибок и сообщений

- Текст исключения начинается с источника: `$this->name() . ': …'` у транспортов,
  `$.Answer[2]: нет поля Type` у `JsonReader`.
- Сообщение должно говорить, что делать (например, «Нужно расширение php-quic»).
- Не оставляйте `@` перед вызовами, скрывающими реальную причину: у транспортов
  ошибки оборачиваются и перевыбрасываются с контекстом.