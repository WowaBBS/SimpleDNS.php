# AGENTS.md — транспортный слой

Правила каталога. Общие — в корневом `AGENTS.md`, библиотечные — в `../AGENTS.md`.

## Контракт

```php
interface DnsTransport
{
  public function exchange(DnsMessage $request): DnsMessage;
  public function name(): string;   // «схема://адрес:порт» для логов и ошибок
}
```

- Таймаут — свойство конструктора, не параметр `exchange()`.
- Проверка ответа (ID) — через трейт `TransportChecks`, не копированием кода.
- Ошибки оборачиваются с именем транспорта: `throw new RuntimeException($this->name() . ': …')`.

## Реализации и их особенности

| Класс | Детали |
|---|---|
| `DohTransport` | POST wire; GET JSON (`JSON_PARAMS` по умолчанию, `JSON_GET` для RFC 8484); `Accept: application/dns-json`; `do=1`, если в запросе OPT с DO |
| `UdpTransport` | при `TC=1` повторяет тот же запрос через `TcpTransport` |
| `TcpTransport` | кадр: 2 байта длины + сообщение; `frame()`/`unframe()` — публичные и тестируемые; **не `final`**, потому что `DotTransport` его расширяет |
| `DotTransport` | тот же кадр через `tls://`, порт 853, SNI и проверка сертификата |
| `DoqTransport` | заглушка: `supported()` = `extension_loaded('quic')`, иначе исключение с инструкцией. Ветка без расширения не тестируется |
| `MulticastTransport` | база для mDNS/LLMNR: отправка, сбор ответов, `mergeMessages()` |
| `MdnsTransport` | `.local`, ID=0, QU-бит в классе вопроса, EDNS вырезается |
| `LlmnrTransport` | без EDNS, ID ненулевой |

## Multicast: три правила, на которых всё держится

1. **Сокет не соединяется с группой.** `stream_socket_client('udp://224.0.0.251:5353')`
   создаёт соединённый сокет, и ядро отбрасывает юникаст-ответы с адреса хоста.
   Используется `stream_socket_server('udp://0.0.0.0:0')` + `sendto`/`recvfrom`.
2. **Чтение — через `stream_select`.** `stream_set_timeout` не действует на
   `stream_socket_recvfrom`, вызов блокируется навсегда.
3. **Никакого EDNS.** `MulticastTransport::stripOpt()` убирает OPT из запроса.

Ответов может быть несколько (от разных хостов) — они объединяются
`mergeMessages()` с дедупликацией по «имя + тип + RDATA».

## Выбор транспорта

`TransportFactory::fromUrl()`: `udp://`, `tcp://`, `tls://`/`dot://`, `doq://`,
`https://`/`doh://`. Для mDNS/LLMNR фабрики нет: это не адресуемые серверы,
они создаются напрямую.

## Тесты

- Офлайн: `static function test(TestRunner $t)` в классе (адрес, параметры, кадрирование,
  нормализация запроса). Вызов — в `Test.php8`.
- Сеть: `Test.Network.php8`. Всё, что зависит от провайдера или локальной сети,
  печатается как `NOTE`, а не как PASS/FAIL.
- Новый транспорт: добавить `test()` в `Test.php8` и живую проверку в `Test.Network.php8`.