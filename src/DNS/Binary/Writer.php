<?php
/* ============================================================
 * DnsWriter - контролируемая сборка бинарного DNS-сообщения.
 * Единственное место, куда пишутся байты при сериализации:
 * запись значения не по диапазону или некорректной метки - исключение.
 * ============================================================ */

final class DnsWriter
{
  private string $buf = '';

  public function __construct(
    private ?NameTable $table = null, // таблица для сжатия имён; null - писать литералами
  ) {}

  public static function serialize    (object $packet                  ): string { return new DnsWriter(null  )->writePacket($this)->data(); }
  public static function serializeWith(object $packet, NameTable $table): string { return new DnsWriter($table)->writePacket($this)->data(); }

  public function tell(): int    { return strlen($this->buf); }
  public function data(): string { return $this->buf; }
  
  public function writePacket(object $packet) { $packet->writeTo($this); return $this; }

  /** Запись сырых байтов без обработки (числа, RDATA без имён) */
  public function writeRaw(string $bytes): void { $this->buf .= $bytes; }

  /* ---------- Числа с проверкой диапазона ---------- */

  public function writeU8(int $v): self
  {
    if ($v < 0 || $v > 0xFF)
      throw new RuntimeException("U8 вне диапазона: $v");
    
    $this->buf .= chr($v);
    return $this;
  }

  public function writeU16(int $v): self
  {
    if ($v < 0 || $v > 0xFFFF)
      throw new RuntimeException("U16 вне диапазона: $v");
    
    $this->buf .= pack('n', $v);
    return $this;
  }

  public function writeU32(int $v): self
  {
    if ($v < 0 || $v > 0xFFFFFFFF)
      throw new RuntimeException("U32 вне диапазона: $v");
    
    $this->buf .= pack('N', $v);
    return $this;
  }

  /** Патч ранее записанного U16 (RDLENGTH, счётчики, ID) */
  public function writeU16At(int $off, int $v): void
  {
    if ($v < 0 || $v > 0xFFFF)
      throw new RuntimeException("U16 вне диапазона: $v");
    
    if ($off < 0 || $off + 2 > strlen($this->buf))
      throw new RuntimeException("Патч за границами буфера: смещение $off");
    
    $this->buf = substr_replace($this->buf, pack('n', $v), $off, 2);
  }
  
  public function pack($format, ...$args): void
  {
    $this->buf .= pack($format, ...$args);
  }

  /* ---------- Имена ---------- */

  /**
   * Запись доменного имени. Если у писателя есть NameTable -
   * включается сжатие: имя или его суффикс, уже встречавшиеся,
   * заменяются указателем на первое вхождение.
   */
  public function writeName(string $name): void
  {
    if ($this->table === null)
      $this->buf .= NameCodec::encode($name);
    else
      NameCodec::encodeWith($name, $this->table, $this->buf);
  }

  static function test(TestRunner $t)
  {
    /* ---------- DnsWriter: числа ---------- */
    
    $w = new DnsWriter();
    $w->writeU8(0xFF)->writeU16(0x1234)->writeU32(1);
    $t->eq(hexs($w->data()), 'FF 12 34 00 00 00 01', 'DnsWriter: U8/U16/U32 big-endian');
    
    // Патч U16 в уже записанные байты
    $w2 = new DnsWriter();
    $w2->writeU16(0)->writeU16(0xDEAD);
    $w2->writeU16At(0, 0x4242);
    $t->eq(hexs($w2->data()), '42 42 DE AD', 'DnsWriter: writeU16At латает первые два байта');
    
    /* ---------- DnsWriter: диапазоны ---------- */
    
    $t->throws(fn() => (new DnsWriter())->writeU8(256), 'DnsWriter: writeU8(256) -> исключение');
    $t->throws(fn() => (new DnsWriter())->writeU8(-1), 'DnsWriter: writeU8(-1) -> исключение');
    $t->throws(fn() => (new DnsWriter())->writeU16(65536), 'DnsWriter: writeU16(65536) -> исключение');
    $t->throws(fn() => (new DnsWriter())->writeU16(-1), 'DnsWriter: writeU16(-1) -> исключение');
    $t->throws(fn() => (new DnsWriter())->writeU32(1 << 32), 'DnsWriter: writeU32(2^32) -> исключение');
    $t->throws(fn() => (new DnsWriter())->writeU16At(5, 1), 'DnsWriter: патч за границей буфера -> исключение');
    
    /* ---------- DnsWriter: имена ---------- */
    
    // Без таблицы - литералы
    $w3 = new DnsWriter();
    $w3->writeName('a.b');
    $t->eq(hexs($w3->data()), '01 61 01 62 00', 'DnsWriter: имя без таблицы - литерал');
    
    // С таблицей: второй вызов того же имени даёт указатель
    $w4 = new DnsWriter(new NameTable());
    $w4->writeName('example.com');   // смещение 0
    $w4->writeName('example.com');   // -> указатель C0 00
    $t->eq(hexs($w4->data()), '07 65 78 61 6D 70 6C 65 03 63 6F 6D 00 C0 00',
      'DnsWriter: повтор имени -> указатель');
    
    // Ошибка метки через писателя
    $t->throws(fn() => (new DnsWriter())->writeName(str_repeat('x', 64)),
      'DnsWriter: метка 64 байта -> исключение');
    
  }
}
