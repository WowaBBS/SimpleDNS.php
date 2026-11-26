# Архитектура

## Слои

```
        ┌──────────────────────────────────────────────┐
        │ Transport     DoH, UDP, TCP, DoT, mDNS, …     │  как доставить байты
        ├──────────────────────────────────────────────┤
        │ Message       DnsMessage / DnsHeader / …      │  что за сообщение
        ├──────────────────────────────────────────────┤
        │ List          DnsQuestionList / DnsRecordList │  секции
        ├──────────────────────────────────────────────┤
        │ Record        DnsRecord + DnsRdata + типы     │  запись и её данные
        ├──────────────────────────────────────────────┤
        │ Binary        DnsReader / DnsWriter           │  байты
        │ Codec         NameCodec / StrCodec / Json     │  имена, строки, JSON
        └──────────────────────────────────────────────┘
```

Нижние слои не знают о верхних: `NameCodec` не знает про `DnsRecord`, `DnsReader` —
про транспорт, `JsonWriter` — про DNS. Верхние пользуются только нижними.

## Поток запроса

```
DnsBuilder ──► DnsMessage ──► serialize()/serializeWith() ──► Transport ──► сеть
                                   │
                             DnsWriter + NameCodec
```

Обратно:

```
сеть ──► Transport ──► DnsMessage::deserialize() ──► DnsReader ──► DnsMessage
                                                     │
                                               RdataRegistry (тип → класс RDATA)
```

JSON идёт тем же маршрутом, но через `JsonWriter`/`JsonReader`:

```
DnsMessage::toJson()      → JsonWriter (ArraySink)   → массив
DnsMessage::toJsonString()→ JsonWriter (StreamSink)  → строка
DnsMessage::deserialize() → DnsReader                → из байтов
DnsMessage::fromJson()    → JsonReader               → из JSON
```

## Единственные точки истины

| Что | Где | Правило |
|---|---|---|
| байты | `DnsReader`, `DnsWriter` | любое чтение/запись идёт через них, с проверкой границ |
| имена | `NameCodec` (+`NameTable` для сжатия) | класcы не формируют имена вручную |
| строки TXT/CAA | `StrCodec` | RFC 8427: печатный ASCII, остальное `\DDD` |
| JSON | `JsonWriter`, `JsonReader` | состояние (запятые, пустые секции) только в `JsonWriter` |
| типы | `RdataRegistry` | однозначное соответствие «код типа ↔ класс RDATA» |
| секции | `DnsList` и наследники | списки, а не массивы; счётчики из содержимого |

## Инварианты

1. **Счётчики заголовка не хранятся вручную.** `DnsMessage::writeTo()` вычисляет
   `qdCount/anCount/nsCount/arCount` из фактических секций перед записью.
2. **RDLENGTH вычисляется, а не задаётся.** `DnsRecord::writeTo()` пишет ноль,
   затем патчит длину через `DnsWriter::writeU16At()`.
3. **Сжатие имён включается владельцем писателя.** `new DnsWriter(new NameTable())`
   — сжатие, `new DnsWriter(null)` — литералы. Классы RDATA про это не знают.
4. **Тип записи выводится из класса RDATA.** `new DnsRecord('example.com',
   new MxRecord(10, 'mx.example.com'), 60)` — тип 15 сам; для `UnknownRdata`
   код передаётся явно и проверяется.
5. **Пустые секции в JSON не пишутся.** `JsonWriter` открывает контейнер при первом
   ребёнке, поэтому пустые `Answer`/`Authority` в вывод не попадают.
6. **Порядок секций фиксирован:** Question, Answer, Authority, Additional.
7. **EDNS только в DNS.** `MulticastTransport::stripOpt()` убирает OPT для mDNS
   и LLMNR, а `NbnsClient` вообще не использует OPT.

## Транспорты

Контракт — `DnsTransport`:

```php
interface DnsTransport
{
  public function exchange(DnsMessage $request): DnsMessage;
  public function name(): string;
}
```

- `TransportChecks` сверяет ID ответа с запросом там, где это применимо.
- Таймаут — параметр конструктора, не метода.
- `UdpTransport` при `TC=1` уходит на TCP (передаёт тот же запрос).
- `MulticastTransport` (mDNS/LLMNR): запрос с эфемерного порта, ответы собираются
  за окно ожидания и объединяются с дедупликацией (`mergeMessages()`).
- `DohTransport` умеет POST-wire и GET-JSON; JSON-способ задаётся `jsonMode`
  (`JSON_PARAMS` или `JSON_GET`).
- `DoqTransport` — заглушка: проверяет наличие расширения `quic` и бросает
  `RuntimeException` с инструкцией.

## NetBIOS отдельно

`NbnsClient` не реализует `DnsTransport` и не участвует в модели `DnsMessage`:
у NBNS другие имена (16 байт + first-level encoding), другие флаги (`NM_FLAGS`),
другие типы (`0x20`/`0x21`, причём `0x21` совпадает с DNS `SRV`). Общее — только
12-байтовый конверт, поэтому переиспользуется `DnsReader`, но не `DnsRecord`.

## Тесты

Тесты — часть классов: `static function test(TestRunner $t)` в конце файла.
Раннер `Test.php8` только вызывает группы. Живые проверки — отдельно,
в `Test.Network.php8`, и в офлайн-набор не входят.