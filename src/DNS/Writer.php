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

  public function tell(): int   { return strlen($this->buf); }

  public function data(): string { return $this->buf; }
  
  public function writePacket(object $packet) { $packet->writeTo($this); return $this; }

  /** Запись сырых байтов без обработки (числа, RDATA без имён) */
  public function writeRaw(string $bytes): void { $this->buf .= $bytes; }

  /* ---------- Числа с проверкой диапазона ---------- */

  public function writeU8(int $v): void
  {
    if ($v < 0 || $v > 0xFF)
      throw new RuntimeException("U8 вне диапазона: $v");
    
    $this->buf .= chr($v);
  }

  public function writeU16(int $v): void
  {
    if ($v < 0 || $v > 0xFFFF)
      throw new RuntimeException("U16 вне диапазона: $v");
    
    $this->buf .= pack('n', $v);
  }

  public function writeU32(int $v): void
  {
    if ($v < 0 || $v > 0xFFFFFFFF)
      throw new RuntimeException("U32 вне диапазона: $v");
    
    $this->buf .= pack('N', $v);
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
}
