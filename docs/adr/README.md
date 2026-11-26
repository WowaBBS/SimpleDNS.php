# Architecture Decision Records

Короткие записи о принятых решениях. Их цель — чтобы агент (и человек) не отменял
решение «по улучшению», не прочитав, почему оно такое.

| № | Решение |
|---|---|
| [0001](0001-record-composition.md) | `DnsRecord` содержит `DnsRdata`, а не наследует его |
| [0002](0002-json-layer.md) | JSON-слой: `JsonWriter` со стейт-машиной + подменяемые `JsonSink` |
| [0003](0003-section-lists.md) | Секции сообщения — объекты-списки, а не массивы |
| [0004](0004-builder-and-derived-type.md) | `DnsBuilder` и вывод типа из класса RDATA |
| [0005](0005-nbns-separate.md) | NetBIOS — отдельный клиент, вне DNS-модели |
| [0006](0006-rrsig-and-doh-json.md) | RRSIG как тип 46 и DNSSEC в JSON через `do=1` |