<?
/* ============================================================
 * RRSIG (RFC 4034, тип 46) - подпись набора записей DNSSEC.
 *
 * Wire: u16 тип | u8 алгоритм | u8 меток | u32 исходный TTL |
 *       u32 истекает | u32 началась | u16 ключ | имя подписанта |
 *       подпись (остаток RDATA)
 *
 * Текст: "A 13 2 300 20261127192106 20261125172106 34505 example.com. AQI="
 * Даты в презентационном виде - 14 цифр UTC, внутри - unix-время.
 * Имя подписанта по RFC 4034 не сжимается.
 * ============================================================ */

final class RrsigRecord extends DnsRdata
{
  public const TYPE = 46;

  public function __construct(
    public int    $typeCovered,
    public int    $algorithm,
    public int    $labels,
    public int    $originalTtl,
    public int    $expiration,   // unix-время
    public int    $inception,    // unix-время
    public int    $keyTag,
    public string $signerName,
    public string $signature,    // сырые байты
  ) {}

  public function serialize(): string
  {
    return pack('nCCNNNn',
        $this->typeCovered, $this->algorithm, $this->labels,
        $this->originalTtl, $this->expiration, $this->inception, $this->keyTag)
      . NameCodec::encode($this->signerName)
      . $this->signature;
  }

  public static function fromReader(DnsReader $r): static
  {
    if ($r->remaining() < 19)
      throw new RuntimeException('RRSIG короче минимальной длины: ' . $r->remaining());

    ['t'=>$type, 'a'=>$algorithm, 'l'=>$labels, 'ttl'=>$ttl, 'exp'=>$exp, 'inc'=>$inc, 'k'=>$key]
      = $r->unpack('nt/Ca/Cl/Nttl/Nexp/Ninc/nk');

    $signer = $r->readName();

    return new static($type, $algorithm, $labels, $ttl, $exp, $inc, $key, $signer,
      $r->readBytes($r->remaining()));
  }

  public function __toString(): string { return $this->toText(); }

  /* ---------- Презентационный формат ---------- */

  public function toText(): string
  {
    return implode(' ', [
      DnsMessage::typeName($this->typeCovered),
      $this->algorithm,
      $this->labels,
      $this->originalTtl,
      self::timeToText($this->expiration),
      self::timeToText($this->inception),
      $this->keyTag,
      NameCodec::toPresentation($this->signerName),
      base64_encode($this->signature),
    ]);
  }

  public static function fromText(string $data): static
  {
    $parts = preg_split('/\s+/', trim($data));

    if (count($parts) < 9)
      throw new RuntimeException("Некорректный RRSIG: $data");

    // Подпись может быть разбита пробелами - склеиваем остаток
    $signature = base64_decode(implode('', array_slice($parts, 8)), true);
    if ($signature === false)
      throw new RuntimeException('RRSIG: подпись не декодируется из base64');

    return new static(
      DnsMessage::typeFromName($parts[0]),
      self::number($parts[1], 0xFF      , 'алгоритм'),
      self::number($parts[2], 0xFF      , 'меток'),
      self::number($parts[3], 0xFFFFFFFF, 'исходный TTL'),
      self::timeFromText($parts[4]),
      self::timeFromText($parts[5]),
      self::number($parts[6], 0xFFFF    , 'ключ'),
      NameCodec::fromPresentation($parts[7]),
      $signature,
    );
  }

  private static function number(string $value, int $max, string $field): int
  {
    if (!ctype_digit($value) || (int)$value > $max)
      throw new RuntimeException("RRSIG: некорректное поле $field: $value");

    return (int)$value;
  }

  private static function timeToText(int $time): string { return gmdate('YmdHis', $time); }

  private static function timeFromText(string $value): int
  {
    if (strlen($value) === 14 && ctype_digit($value))
    {
      $date = DateTimeImmutable::createFromFormat('!YmdHis', $value, new DateTimeZone('UTC'));

      if ($date === false || $date->format('YmdHis') !== $value)
        throw new RuntimeException("RRSIG: некорректная дата: $value");

      return $date->getTimestamp();
    }

    if (!ctype_digit($value))
      throw new RuntimeException("RRSIG: некорректная дата: $value");

    return (int)$value;
  }

  /* ------------------------------------------------------------
   * Тесты
   * ------------------------------------------------------------ */

  static function test(TestRunner $t)
  {
    /* ---------- Wire ---------- */

    $sig = new RrsigRecord(1, 13, 2, 300, 2, 1, 0x86C9, 'example.com', "\x01\x02");

    $t->eq(hexs($sig->serialize()),
      '00 01 0D 02 00 00 01 2C 00 00 00 02 00 00 00 01 86 C9'
      . ' 07 65 78 61 6D 70 6C 65 03 63 6F 6D 00 01 02',
      'RrsigRecord: точные байты');

    $back = RrsigRecord::fromReader(DnsReader::of($sig->serialize()));
    $t->eq([$back->typeCovered, $back->algorithm, $back->labels, $back->originalTtl,
            $back->expiration, $back->inception, $back->keyTag, $back->signerName, $back->signature],
      [1, 13, 2, 300, 2, 1, 0x86C9, 'example.com', "\x01\x02"],
      'RrsigRecord: wire roundtrip');

    /* ---------- Текст ---------- */

    $text = 'A 13 2 300 20261127192106 20261125172106 34505 example.com. AQI=';
    $rec  = RrsigRecord::fromText($text);

    $t->eq([$rec->typeCovered, $rec->algorithm, $rec->labels, $rec->originalTtl, $rec->keyTag],
      [1, 13, 2, 300, 34505], 'RrsigRecord: разбор чисел');
    $t->eq($rec->signerName, 'example.com', 'RrsigRecord: имя подписанта');
    $t->eq($rec->signature, "\x01\x02", 'RrsigRecord: подпись из base64');
    $t->eq($rec->toText(), $text, 'RrsigRecord: текст туда и обратно');

    // Дата переводится в unix-время и обратно
    $t->eq(gmdate('YmdHis', $rec->expiration), '20261127192106', 'RrsigRecord: дата истечения');
    $t->eq(gmdate('YmdHis', $rec->inception ), '20261125172106', 'RrsigRecord: дата начала');

    // Неизвестный тип покрытия печатается как TYPE65 и читается обратно
    $unknown = new RrsigRecord(65, 13, 2, 300, 2, 1, 7, 'example.com', "\xAA");
    $t->eq(RrsigRecord::fromText($unknown->toText())->typeCovered, 65, 'RrsigRecord: TYPE65 в тексте');

    // Ошибки
    $t->throws(fn() => RrsigRecord::fromText('A 13 2 300'), 'RrsigRecord: мало полей -> исключение');
    $t->throws(fn() => RrsigRecord::fromText('A 13 2 300 20261127192106 20261125172106 34505 example.com. !!!'), 'RrsigRecord: плохой base64 -> исключение');
    $t->throws(fn() => RrsigRecord::fromText('A 13 2 300 20261332192106 20261125172106 34505 example.com. AQI='), 'RrsigRecord: некорректная дата -> исключение');
    $t->throws(fn() => RrsigRecord::fromText('A 999 2 300 20261127192106 20261125172106 34505 example.com. AQI='), 'RrsigRecord: алгоритм вне диапазона -> исключение');
  }
}