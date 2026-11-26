# SimpleDNS.php

DNS-клиент на чистом PHP, без зависимостей и автозагрузки: свой разбор и сборка
wire-формата, свой JSON-кодек (RFC 8427), несколько транспортов и отдельный клиент
NetBIOS.

## Что умеет

**Сообщения.** `DnsMessage` собирается и разбирается целиком: заголовок, вопросы,
секции ответа, сжатие имён при записи. Есть `DnsBuilder` для короткой сборки,
`DnsReader`/`DnsWriter` как единственные точки доступа к байтам.

**Типы записей.** A, AAAA, NS, CNAME, PTR, MX, TXT, SOA, SRV, CAA, OPT (EDNS),
RRSIG (DNSSEC) и неизвестные типы в формате `\# <длина> <hex>` (RFC 3597).

**JSON.** Полный круг `application/dns+json`: `toJson()` / `toJsonString()` /
`toJsonStream()` и обратный разбор с терпимостью к реальным серверам
(`Name`/`name`, `Type`: `"A"` или `1`).

**Транспорты.**

| Транспорт | Класс | Особенности |
|---|---|---|
| DoH (RFC 8484) | `DohTransport` | POST wire, GET JSON, `do=1` для DNSSEC |
| UDP (RFC 1035) | `UdpTransport` | при `TC=1` сам повторяет по TCP |
| TCP (RFC 1035) | `TcpTransport` | кадр с двухбайтовой длиной |
| DoT (RFC 7858) | `DotTransport` | TLS поверх TCP-кадра |
| DoQ (RFC 9250) | `DoqTransport` | заглушка: ждёт расширение php-quic |
| mDNS (RFC 6762) | `MdnsTransport` | `.local`, QU-бит, сбор всех ответов |
| LLMNR (RFC 4795) | `LlmnrTransport` | multicast без EDNS |

`TransportFactory::fromUrl('tls://dns.google')` выбирает транспорт по схеме.

**NetBIOS.** `NbnsClient` — таблица имён хоста (NBSTAT) и разрешение имени в IPv4.
Живёт отдельно: NBNS не DNS, хотя конверт похож.

## Требования

PHP 8.1+ с `short_open_tag=On`. Ничего больше: ни composer, ни расширений.

## Быстрый старт

```php
include_once '_All.php';

// DoH
$reply = (new DohTransport())->exchange(DnsMessage::query('example.com', 1));

// UDP, TCP, DoT — тот же контракт
$reply = (new UdpTransport('1.1.1.1'))->exchange(DnsMessage::query('example.com', 1));
$reply = (new DotTransport('dns.google'))->exchange(DnsMessage::query('example.com', 1));

// по адресу
$transport = TransportFactory::fromUrl('tls://dns.google');

// JSON
echo $reply->toJsonString();
```

Сборка сообщения:

```php
$message = DnsBuilder::response(id: 0x1234)
  ->q('example.com', 15)
  ->mx('example.com', 10, 'mail.example.com', 3600)
  ->txt('example.com', 'v=spf1 -all', 60)
  ->build();
```

Локальная сеть:

```php
$mdns = new MdnsTransport(timeout: 1);
$mdns->ask('_http._tcp.local', 12);       // сервисы и адреса
$nbns = new NbnsClient();
$nbns->stat('192.168.1.10');              // NetBIOS-имена хоста
```

## Примеры и утилиты

Запускаются как отдельные скрипты:

| Файл | Что делает |
|---|---|
| `src/DNS/Example.php8` | один запрос через DoH, UDP, TCP и DoT |
| `src/DNS/Example.Mdns.php8` | обзор mDNS-сервисов в локальной сети |
| `src/DNS/Example.Names.php8` | инвентарь имён: mDNS + NBNS + LLMNR + PTR |

## Тесты

```bash
cd src/DNS
php Test.php8           # офлайн: не ходит в сеть
php Test.Network.php8   # живой: провайдеры, mDNS, NBNS
```

Тесты живут рядом с кодом: в каждом классе есть `static function test(TestRunner $t)`,
а раннер лишь перечисляет группы.

## Структура

```
src/DNS/
  Binary/     DnsReader, DnsWriter, Packet
  Codec/      NameCodec, StrCodec, NameTable, Json/
  Record/     DnsRdata и типы записей, RdataRegistry
  List/       DnsList, DnsQuestionList, DnsRecordList
  Transport/  DnsTransport и реализации, TransportFactory
  Header.php Question.php Record.php Message.php Builder.php Nbns.php
  Test.php8 Test.Network.php8 Example*.php8
```

## Кодировка

Код — **cp1251 + LF**, документация (`*.md`) — UTF-8. Подробности и правила для
агентов — в `AGENTS.md`.

## Ограничения

Осознанно не сделано или упирается в провайдеров — см.
[docs/known-limitations.md](docs/known-limitations.md).